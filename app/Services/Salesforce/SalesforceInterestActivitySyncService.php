<?php

namespace App\Services\Salesforce;

use App\Models\ReportSyncRun;
use App\Models\SalesforceInterestActivity;
use App\Models\SalesforceInterestActivityRun;
use App\Support\IntegrationErrorSanitizer;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SalesforceInterestActivitySyncService
{
    public const LOCK_KEY = 'salesforce_interest_activity_sync';

    public const TASK_SOQL_FIELDS = 'Id, WhatId, WhoId, ActivityDate, CreatedDate, IsDeleted, LastModifiedDate, SystemModstamp';

    public const EVENT_SOQL_FIELDS = 'Id, WhatId, WhoId, StartDateTime, CreatedDate, IsDeleted, LastModifiedDate, SystemModstamp';

    private const LOCK_TTL_SECONDS = 21600;

    private const LOCAL_LOOKUP_CHUNK_SIZE = 500;

    private const PERSIST_CHUNK_SIZE = 200;

    private const CLEANUP_CHUNK_SIZE = 1000;

    private const SALESFORCE_ID_PATTERN = '/^[A-Za-z0-9]{18}$/';

    public function __construct(
        private readonly SalesforceClient $client,
    ) {}

    /** @return array{run: SalesforceInterestActivityRun, stats: array<string, int|float|string|null>} */
    public function sync(string $reason, ?int $generationId = null): array
    {
        return $this->syncSnapshot($reason, $generationId, false);
    }

    /**
     * Rebuild an isolated F5 run from the current remote state. This is only
     * used by initial recovery; it never changes an existing activity run.
     *
     * @return array{run: SalesforceInterestActivityRun, stats: array<string, int|float|string|null>}
     */
    public function syncCurrentRecovery(string $reason, int $generationId): array
    {
        return $this->syncSnapshot($reason, $generationId, true);
    }

    /** Deterministic, non-identifying digest of a completed F5 materialization. */
    public function manifest(SalesforceInterestActivityRun $run): array
    {
        $hash = hash_init('sha256');
        $rows = 0;
        SalesforceInterestActivity::query()->where('activity_run_id', $run->id)
            ->chunkById(1000, function ($items) use ($hash, &$rows): void {
                foreach ($items as $item) {
                    hash_update($hash, $this->activityHash((array) $item->getAttributes())."\n");
                    $rows++;
                }
            });

        return ['run_id' => $run->id, 'rows' => $rows, 'sha256' => hash_final($hash)];
    }

    /** @return array{rows: int, sha256: string, matches: bool} */
    public function verifyCurrentRecovery(SalesforceInterestActivityRun $run, int $generationId): array
    {
        $hash = hash_init('sha256');
        $rows = 0;
        $cutoff = CarbonImmutable::parse($run->source_cutoff_at, 'UTC');
        foreach (['Task', 'Event'] as $kind) {
            foreach ($this->client->queryPages($this->soql($kind, $cutoff, true), true) as $records) {
                $interests = $this->resolveInterests($records, $generationId);
                foreach ($records as $record) {
                    $row = $this->mapRecord($run, $kind, $record, $interests);
                    hash_update($hash, $this->activityHash($row)."\n");
                    $rows++;
                }
            }
        }
        $actual = $this->manifest($run);
        $expected = hash_final($hash);

        return ['rows' => $rows, 'sha256' => $expected,
            'matches' => $rows === $actual['rows'] && hash_equals($expected, $actual['sha256'])];
    }

    /** @return array{run: SalesforceInterestActivityRun, stats: array<string, int|float|string|null>} */
    private function syncSnapshot(string $reason, ?int $generationId, bool $currentState): array
    {
        if ($generationId === null) {
            throw new RuntimeException('F5 requires an explicit isolated F2 candidate.');
        }
        $generations = app(InterestGenerationStore::class);
        $candidate = $generations->generation($generationId);
        if ($candidate->status !== 'validating' || ! $candidate->f2_complete) {
            throw new RuntimeException('F5 requires a complete conflict-free F2 candidate.');
        }
        if ($candidate->f5_complete && $candidate->activity_run_id !== null) {
            $completed = SalesforceInterestActivityRun::findOrFail($candidate->activity_run_id);

            return ['run' => $completed, 'stats' => $completed->stats ?? []];
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw new RuntimeException('Interest activity sync requires a reason between 10 and 500 characters.');
        }

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL_SECONDS);
        if (! $lock->get()) {
            throw new RuntimeException('Another Interest activity sync is already running.');
        }

        $run = null;
        $cutoff = CarbonImmutable::parse($candidate->cutoff_at, 'UTC');
        $stats = $this->initialStats($cutoff);
        $startedAt = microtime(true);

        try {
            $sourceRun = ReportSyncRun::findOrFail($candidate->interest_run_id);
            $stats['source_interest_sync_run_id'] = $sourceRun->id;
            $stats['source_interest_cutoff_at'] = $sourceRun->source_cutoff_at->utc()->toIso8601String();
            $stats['source_interests'] = $generations->rows($generationId)->count();

            try {
                $run = SalesforceInterestActivityRun::query()->create([
                    'run_identifier' => (string) Str::uuid(),
                    'reason' => $reason,
                    'status' => 'running',
                    'source_interest_sync_run_id' => $sourceRun->id,
                    'source_interest_cutoff_at' => $sourceRun->source_cutoff_at,
                    'source_cutoff_at' => $cutoff,
                    'started_at' => now('UTC'),
                    'stats' => $stats,
                ]);

                $this->syncActivities($run, $cutoff, $stats, $generationId, $currentState);
                $this->afterSnapshotBuilt($run, $stats);
                $this->assertSnapshotComplete($run, $stats);
                if (! $generations->generation($generationId)->f2_complete) {
                    throw new RuntimeException('F2 candidate is no longer complete.');
                }
                $stats['duration_seconds'] = round(microtime(true) - $startedAt, 3);

                $run->update([
                    'status' => 'completed',
                    'completed_at' => now('UTC'),
                    'stats' => $stats,
                    'error_message' => null,
                ]);
                $generations->finishF5($generationId, $run);
            } catch (Throwable) {
                $generations->fail($generationId);
                $stats['errors']++;
                $stats['duration_seconds'] = round(microtime(true) - $startedAt, 3);
                try {
                    $run?->update([
                        'status' => 'failed',
                        'completed_at' => now('UTC'),
                        'stats' => $stats,
                        'error_message' => IntegrationErrorSanitizer::sanitizeMessage(
                            'Interest activity sync failed safely.',
                        ),
                    ]);
                } catch (Throwable) {
                }

                throw new RuntimeException('Interest activity sync failed safely.');
            }

            return ['run' => $run, 'stats' => $stats];
        } finally {
            $lock->release();
        }
    }

    /** @param array<string, int|float|string|null> $stats */
    private function syncActivities(
        SalesforceInterestActivityRun $run,
        CarbonImmutable $cutoff,
        array &$stats,
        int $generationId,
        bool $currentState,
    ): void {
        $this->syncActivityKind($run, 'Task', $cutoff, $stats, $generationId, $currentState);
        $this->syncActivityKind($run, 'Event', $cutoff, $stats, $generationId, $currentState);
    }

    /** @param array<string, int|float|string|null> $stats */
    private function syncActivityKind(
        SalesforceInterestActivityRun $run,
        string $kind,
        CarbonImmutable $cutoff,
        array &$stats,
        int $generationId,
        bool $currentState,
    ): void {
        $stats['query_all_calls']++;
        foreach ($this->client->queryPages($this->soql($kind, $cutoff, $currentState), true) as $records) {
            $stats[$kind === 'Task' ? 'task_pages' : 'event_pages']++;
            $stats[$kind === 'Task' ? 'tasks_examined' : 'events_examined'] += count($records);
            $interestById = $this->resolveInterests($records, $generationId);
            $rows = array_map(
                fn (array $record): array => $this->mapRecord($run, $kind, $record, $interestById),
                $records,
            );

            foreach (array_chunk($rows, self::PERSIST_CHUNK_SIZE) as $chunk) {
                DB::table('salesforce_interest_activities')->insert($chunk);
                $stats['persist_chunks']++;
                $stats['rows_materialized'] += count($chunk);
                foreach ($chunk as $row) {
                    $stats[$row['activity_is_deleted'] ? 'activities_deleted' : 'activities_active']++;
                    if ($row['relationship_status'] === 'resolved') {
                        $stats['direct_relations']++;
                    } else {
                        $stats['unresolved_references']++;
                        $metric = match ($row['relationship_status']) {
                            'interest_not_in_source' => 'interest_not_in_source',
                            'invalid_activity_id' => 'invalid_activity_ids',
                            'invalid_interest_reference' => 'invalid_interest_references',
                        };
                        $stats[$metric]++;
                    }
                }
                $this->afterPersistChunk($run, $stats);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, object>
     */
    private function resolveInterests(array $records, int $generationId): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            fn (array $record): string => trim((string) data_get($record, 'WhatId')),
            $records,
        ), fn (string $id): bool => $this->isValidSalesforceId($id))));
        $interests = [];

        foreach (array_chunk($ids, self::LOCAL_LOOKUP_CHUNK_SIZE) as $chunk) {
            foreach (app(InterestGenerationStore::class)->rows($generationId)
                ->select(['version.salesforce_id', 'version.is_deleted'])
                ->whereIn('version.salesforce_id', $chunk)
                ->get() as $interest) {
                $interests[trim((string) $interest->salesforce_id)] = $interest;
            }
        }

        return $interests;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, object>  $interestById
     * @return array<string, mixed>
     */
    public function mapRecord(
        SalesforceInterestActivityRun $run,
        string $kind,
        array $record,
        array $interestById,
    ): array {
        $activityId = trim((string) data_get($record, 'Id'));
        $interestId = trim((string) data_get($record, 'WhatId'));
        $interest = $interestById[$interestId] ?? null;
        $relationshipStatus = match (true) {
            ! $this->isValidSalesforceId($activityId) => 'invalid_activity_id',
            ! $this->isValidSalesforceId($interestId) => 'invalid_interest_reference',
            $interest === null => 'interest_not_in_source',
            default => 'resolved',
        };
        $now = now();

        return [
            'activity_run_id' => $run->id,
            'activity_kind' => $kind,
            'activity_salesforce_id' => $activityId,
            'interest_salesforce_id' => $interestId === '' ? null : $interestId,
            'who_salesforce_id' => $this->nullableId(data_get($record, 'WhoId')),
            'relationship_status' => $relationshipStatus,
            'activity_is_deleted' => (bool) data_get($record, 'IsDeleted', false),
            'interest_is_deleted' => $interest === null ? null : (bool) $interest->is_deleted,
            'activity_date' => $kind === 'Task' ? $this->parseDate(data_get($record, 'ActivityDate')) : null,
            'start_datetime' => $kind === 'Event' ? $this->parseDateTime(data_get($record, 'StartDateTime')) : null,
            'salesforce_created_at' => $this->parseDateTime(data_get($record, 'CreatedDate')),
            'salesforce_last_modified_at' => $this->parseDateTime(data_get($record, 'LastModifiedDate')),
            'system_modstamp_at' => $this->parseDateTime(data_get($record, 'SystemModstamp')),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function soql(string $kind, CarbonImmutable $cutoff, bool $currentState = false): string
    {
        $fields = $kind === 'Task' ? self::TASK_SOQL_FIELDS : self::EVENT_SOQL_FIELDS;

        return 'SELECT '.$fields."\n"
            .'FROM '.$kind."\n"
            ."WHERE What.Type = 'Interes__c'\n"
            .($currentState ? 'ORDER BY Id ASC' : '    AND SystemModstamp <= '.$cutoff->format('Y-m-d\TH:i:s\Z')."\nORDER BY SystemModstamp ASC, Id ASC");
    }

    /** @param array<string, int|float|string|null> $stats */
    private function assertSnapshotComplete(SalesforceInterestActivityRun $run, array $stats): void
    {
        $persisted = SalesforceInterestActivity::query()
            ->where('activity_run_id', $run->id)
            ->count();
        if ($persisted !== $stats['rows_materialized']
            || $persisted !== $stats['tasks_examined'] + $stats['events_examined']) {
            throw new RuntimeException('Interest activity snapshot coverage is incomplete.');
        }
    }

    private function isValidSalesforceId(string $id): bool
    {
        return preg_match(self::SALESFORCE_ID_PATTERN, $id) === 1;
    }

    private function nullableId(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $row */
    private function activityHash(array $row): string
    {
        $value = [];
        foreach ([
            'activity_kind', 'activity_salesforce_id', 'interest_salesforce_id', 'who_salesforce_id', 'relationship_status',
            'activity_is_deleted', 'interest_is_deleted', 'activity_date', 'start_datetime', 'salesforce_created_at',
            'salesforce_last_modified_at', 'system_modstamp_at',
        ] as $field) {
            $item = $row[$field] ?? null;
            $value[$field] = match (true) {
                $item instanceof CarbonInterface => $item->utc()->toIso8601String(),
                in_array($field, ['activity_is_deleted', 'interest_is_deleted'], true) => $item === null ? null : (int) (bool) $item,
                in_array($field, ['start_datetime', 'salesforce_created_at', 'salesforce_last_modified_at', 'system_modstamp_at'], true) => $item === null ? null : CarbonImmutable::parse($item, 'UTC')->utc()->toIso8601String(),
                default => $item === null ? null : (string) $item,
            };
        }
        ksort($value);

        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function parseDate(mixed $value): ?string
    {
        return blank($value) ? null : CarbonImmutable::parse($value)->toDateString();
    }

    private function parseDateTime(mixed $value): ?CarbonImmutable
    {
        return blank($value) ? null : CarbonImmutable::parse($value)->utc();
    }

    /** @return array<string, int|float|string|null> */
    private function initialStats(CarbonImmutable $cutoff): array
    {
        return [
            'source_interest_sync_run_id' => null,
            'source_interest_cutoff_at' => null,
            'cutoff' => $cutoff->toIso8601String(),
            'source_interests' => 0,
            'query_all_calls' => 0,
            'task_pages' => 0,
            'event_pages' => 0,
            'tasks_examined' => 0,
            'events_examined' => 0,
            'direct_relations' => 0,
            'activities_active' => 0,
            'activities_deleted' => 0,
            'unresolved_references' => 0,
            'interest_not_in_source' => 0,
            'invalid_activity_ids' => 0,
            'invalid_interest_references' => 0,
            'persist_chunks' => 0,
            'rows_materialized' => 0,
            'errors' => 0,
            'cleanup_errors' => 0,
            'duration_seconds' => 0.0,
        ];
    }

    /** @param array<string, int|float|string|null> $stats */
    protected function afterPersistChunk(SalesforceInterestActivityRun $run, array $stats): void {}

    /** @param array<string, int|float|string|null> $stats */
    protected function afterSnapshotBuilt(SalesforceInterestActivityRun $run, array $stats): void {}

    protected function afterCleanupChunk(int $rowsDeleted): void {}
}
