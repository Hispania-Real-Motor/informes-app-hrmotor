<?php

namespace App\Services\Salesforce;

use App\Models\ReportSyncRun;
use App\Models\SalesforceInterestActivity;
use App\Models\SalesforceInterestActivityRun;
use App\Support\IntegrationErrorSanitizer;
use Carbon\CarbonImmutable;
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
    public function sync(string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw new RuntimeException('Interest activity sync requires a reason between 10 and 500 characters.');
        }

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL_SECONDS);
        if (! $lock->get()) {
            throw new RuntimeException('Another Interest activity sync is already running.');
        }

        $run = null;
        $cutoff = CarbonImmutable::now('UTC');
        $stats = $this->initialStats($cutoff);
        $startedAt = microtime(true);

        try {
            $sourceRun = $this->latestStableInterestRun();
            $stats['source_interest_sync_run_id'] = $sourceRun->id;
            $stats['source_interest_cutoff_at'] = $sourceRun->source_cutoff_at->utc()->toIso8601String();
            $stats['source_interests'] = DB::table('salesforce_interests')->count();

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

                $this->syncActivities($run, $cutoff, $stats);
                $this->afterSnapshotBuilt($run, $stats);
                $this->assertSnapshotComplete($run, $stats);
                $this->assertSourceRunUnchanged($sourceRun);
                $stats['duration_seconds'] = round(microtime(true) - $startedAt, 3);

                $run->update([
                    'status' => 'completed',
                    'completed_at' => now('UTC'),
                    'stats' => $stats,
                    'error_message' => null,
                ]);
            } catch (Throwable) {
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

                if ($run !== null) {
                    try {
                        $this->cleanupDetailedSnapshots($run, false);
                    } catch (Throwable) {
                        $stats['cleanup_errors']++;
                        try {
                            $run->update(['stats' => $stats]);
                        } catch (Throwable) {
                        }
                    }
                }

                throw new RuntimeException('Interest activity sync failed safely.');
            }

            try {
                $this->cleanupDetailedSnapshots($run, true);
            } catch (Throwable) {
                $stats['cleanup_errors']++;
                try {
                    $run->update(['stats' => $stats]);
                } catch (Throwable) {
                }
            }

            return ['run' => $run, 'stats' => $stats];
        } finally {
            $lock->release();
        }
    }

    private function latestStableInterestRun(): ReportSyncRun
    {
        $run = ReportSyncRun::query()
            ->where('dataset', SalesforceInterestSyncService::DATASET)
            ->where('source', SalesforceInterestSyncService::SOURCE)
            ->orderByDesc('id')
            ->first();

        if ($run === null || $run->status !== 'completed' || $run->source_cutoff_at === null) {
            throw new RuntimeException('The latest Interest sync run is not a stable completed source.');
        }

        return $run;
    }

    /** @param array<string, int|float|string|null> $stats */
    private function syncActivities(
        SalesforceInterestActivityRun $run,
        CarbonImmutable $cutoff,
        array &$stats,
    ): void {
        $this->syncActivityKind($run, 'Task', $cutoff, $stats);
        $this->syncActivityKind($run, 'Event', $cutoff, $stats);
    }

    /** @param array<string, int|float|string|null> $stats */
    private function syncActivityKind(
        SalesforceInterestActivityRun $run,
        string $kind,
        CarbonImmutable $cutoff,
        array &$stats,
    ): void {
        $stats['query_all_calls']++;
        foreach ($this->client->queryPages($this->soql($kind, $cutoff), true) as $records) {
            $stats[$kind === 'Task' ? 'task_pages' : 'event_pages']++;
            $stats[$kind === 'Task' ? 'tasks_examined' : 'events_examined'] += count($records);
            $interestById = $this->resolveInterests($records);
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
    private function resolveInterests(array $records): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            fn (array $record): string => trim((string) data_get($record, 'WhatId')),
            $records,
        ), fn (string $id): bool => $this->isValidSalesforceId($id))));
        $interests = [];

        foreach (array_chunk($ids, self::LOCAL_LOOKUP_CHUNK_SIZE) as $chunk) {
            foreach (DB::table('salesforce_interests')
                ->select(['salesforce_id', 'is_deleted'])
                ->whereIn('salesforce_id', $chunk)
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
    private function mapRecord(
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

    private function soql(string $kind, CarbonImmutable $cutoff): string
    {
        $fields = $kind === 'Task' ? self::TASK_SOQL_FIELDS : self::EVENT_SOQL_FIELDS;

        return 'SELECT '.$fields."\n"
            .'FROM '.$kind."\n"
            ."WHERE What.Type = 'Interes__c'\n"
            .'    AND SystemModstamp <= '.$cutoff->format('Y-m-d\TH:i:s\Z')."\n"
            .'ORDER BY SystemModstamp ASC, Id ASC';
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

    private function assertSourceRunUnchanged(ReportSyncRun $sourceRun): void
    {
        $latest = $this->latestStableInterestRun();
        if ($latest->id !== $sourceRun->id
            || ! $latest->source_cutoff_at?->equalTo($sourceRun->source_cutoff_at)) {
            throw new RuntimeException('The Interest source changed while activities were being built.');
        }
    }

    private function cleanupDetailedSnapshots(SalesforceInterestActivityRun $currentRun, bool $completed): void
    {
        $protectedRunIds = [$currentRun->id];
        if (! $completed) {
            $latestCompleted = SalesforceInterestActivityRun::query()
                ->where('status', 'completed')
                ->orderByDesc('completed_at')
                ->orderByDesc('id')
                ->value('id');
            if ($latestCompleted !== null) {
                $protectedRunIds[] = (int) $latestCompleted;
            }
        }

        while (true) {
            $ids = SalesforceInterestActivity::query()
                ->whereNotIn('activity_run_id', $protectedRunIds)
                ->whereIn('activity_run_id', SalesforceInterestActivityRun::query()
                    ->select('id')
                    ->whereIn('status', ['completed', 'failed']))
                ->orderBy('id')
                ->limit(self::CLEANUP_CHUNK_SIZE)
                ->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }

            DB::transaction(fn (): int => SalesforceInterestActivity::query()
                ->whereIn('id', $ids->all())
                ->whereNotIn('activity_run_id', $protectedRunIds)
                ->delete());
            $this->afterCleanupChunk($ids->count());
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
