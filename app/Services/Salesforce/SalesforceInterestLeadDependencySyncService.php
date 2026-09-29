<?php

namespace App\Services\Salesforce;

use App\Models\ReportSyncRun;
use App\Models\SalesforceInterestLeadDependency;
use App\Models\SalesforceInterestLeadDependencyRun;
use App\Support\IntegrationErrorSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SalesforceInterestLeadDependencySyncService
{
    public const LOCK_KEY = 'salesforce_interest_lead_dependency_sync';

    public const SOQL_FIELDS = 'Id, IsDeleted, MasterRecordId, LastModifiedDate, SystemModstamp';

    private const LOCK_TTL_SECONDS = 21600;

    private const SALESFORCE_BATCH_SIZE = 100;

    private const CLEANUP_CHUNK_SIZE = 1000;

    private const SALESFORCE_ID_PATTERN = '/^[A-Za-z0-9]{18}$/';

    public function __construct(
        private readonly SalesforceClient $client,
    ) {}

    /** @return array{run: SalesforceInterestLeadDependencyRun, stats: array<string, int|float|string|null>} */
    public function sync(string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw new RuntimeException('Dependency sync requires a reason between 10 and 500 characters.');
        }

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL_SECONDS);
        if (! $lock->get()) {
            throw new RuntimeException('Another Interest Lead dependency sync is already running.');
        }

        $run = null;
        $stats = $this->initialStats();
        $startedAt = microtime(true);

        try {
            $sourceRun = $this->latestStableInterestRun();
            $stats['source_interest_sync_run_id'] = $sourceRun->id;
            $stats['source_interest_cutoff_at'] = $sourceRun->source_cutoff_at->utc()->toIso8601String();

            try {
                $run = SalesforceInterestLeadDependencyRun::query()->create([
                    'run_identifier' => (string) Str::uuid(),
                    'reason' => $reason,
                    'status' => 'running',
                    'source_interest_sync_run_id' => $sourceRun->id,
                    'source_interest_cutoff_at' => $sourceRun->source_cutoff_at,
                    'started_at' => now('UTC'),
                    'stats' => $stats,
                ]);

                $stats['origins_seeded'] = $this->seedOrigins($run);
                $stats['dependencies_discovered'] = $stats['origins_seeded'];
                $this->processPending($run, $stats);

                if (SalesforceInterestLeadDependency::query()
                    ->where('dependency_run_id', $run->id)
                    ->where('presence_status', 'pending')
                    ->exists()) {
                    throw new RuntimeException('Dependency snapshot still contains pending rows.');
                }

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
                $run?->update([
                    'status' => 'failed',
                    'completed_at' => now('UTC'),
                    'stats' => $stats,
                    'error_message' => IntegrationErrorSanitizer::sanitizeMessage(
                        'Interest Lead dependency sync failed safely.',
                    ),
                ]);

                if ($run !== null) {
                    try {
                        $this->cleanupDetailedSnapshots($run, false);
                    } catch (Throwable) {
                        $stats['errors']++;
                        $run->update(['stats' => $stats]);
                    }
                }

                throw new RuntimeException('Interest Lead dependency sync failed safely.');
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

    private function seedOrigins(SalesforceInterestLeadDependencyRun $run): int
    {
        $now = now();
        $source = DB::table('salesforce_interests')
            ->selectRaw('? as dependency_run_id', [$run->id])
            ->selectRaw('TRIM(migration_origin_lead_id) as salesforce_id')
            ->selectRaw("'pending' as presence_status")
            ->selectRaw('1 as is_origin_reference')
            ->selectRaw('0 as is_master_dependency')
            ->selectRaw('NULL as is_deleted')
            ->selectRaw('NULL as salesforce_master_record_id')
            ->selectRaw('NULL as salesforce_last_modified_at')
            ->selectRaw('NULL as system_modstamp_at')
            ->selectRaw('? as created_at', [$now])
            ->selectRaw('? as updated_at', [$now])
            ->whereNotNull('migration_origin_lead_id')
            ->whereRaw("TRIM(migration_origin_lead_id) <> ''")
            ->distinct();

        DB::table('salesforce_interest_lead_dependencies')->insertUsing([
            'dependency_run_id',
            'salesforce_id',
            'presence_status',
            'is_origin_reference',
            'is_master_dependency',
            'is_deleted',
            'salesforce_master_record_id',
            'salesforce_last_modified_at',
            'system_modstamp_at',
            'created_at',
            'updated_at',
        ], $source);

        return SalesforceInterestLeadDependency::query()
            ->where('dependency_run_id', $run->id)
            ->count();
    }

    /** @param array<string, int|float|string|null> $stats */
    private function processPending(SalesforceInterestLeadDependencyRun $run, array &$stats): void
    {
        while (true) {
            $dependencies = SalesforceInterestLeadDependency::query()
                ->where('dependency_run_id', $run->id)
                ->where('presence_status', 'pending')
                ->orderBy('id')
                ->limit(self::SALESFORCE_BATCH_SIZE)
                ->get();

            if ($dependencies->isEmpty()) {
                break;
            }

            $invalid = $dependencies->filter(
                fn (SalesforceInterestLeadDependency $dependency): bool => ! $this->isValidSalesforceId($dependency->salesforce_id),
            );
            if ($invalid->isNotEmpty()) {
                SalesforceInterestLeadDependency::query()
                    ->where('dependency_run_id', $run->id)
                    ->whereIn('id', $invalid->modelKeys())
                    ->update([
                        'presence_status' => 'invalid',
                        'is_deleted' => null,
                        'updated_at' => now(),
                    ]);
                $stats['invalid'] += $invalid->count();
            }

            $valid = $dependencies->reject(
                fn (SalesforceInterestLeadDependency $dependency): bool => $invalid->contains('id', $dependency->id),
            );
            if ($valid->isNotEmpty()) {
                $this->queryAndPersistBatch($run, $valid->all(), $stats);
            }

            $stats['batches']++;
            $this->afterBatch($run, $stats);
        }
    }

    /**
     * @param  list<SalesforceInterestLeadDependency>  $dependencies
     * @param  array<string, int|float|string|null>  $stats
     */
    private function queryAndPersistBatch(
        SalesforceInterestLeadDependencyRun $run,
        array $dependencies,
        array &$stats,
    ): void {
        $ids = array_map(fn (SalesforceInterestLeadDependency $dependency): string => $dependency->salesforce_id, $dependencies);
        $records = $this->client->queryAll($this->soql($ids));
        $stats['query_all_calls']++;
        $stats['queried_ids'] += count($ids);
        $recordsById = collect($records)->keyBy(fn (array $record): string => trim((string) data_get($record, 'Id')));
        $now = now();
        $updates = [];
        $masterIds = [];

        foreach ($dependencies as $dependency) {
            $record = $recordsById->get($dependency->salesforce_id);
            if ($record === null) {
                $updates[] = $this->dependencyUpdateRow($dependency, 'missing', null, null, null, null, $now);
                $stats['missing']++;

                continue;
            }

            $deleted = (bool) data_get($record, 'IsDeleted', false);
            $status = $deleted ? 'deleted' : 'active';
            $masterId = $this->nullableId(data_get($record, 'MasterRecordId'));
            $updates[] = $this->dependencyUpdateRow(
                $dependency,
                $status,
                $deleted,
                $masterId,
                $this->parseDateTime(data_get($record, 'LastModifiedDate')),
                $this->parseDateTime(data_get($record, 'SystemModstamp')),
                $now,
            );
            $stats[$status]++;
            if ($masterId !== null) {
                $masterIds[] = $masterId;
            }
        }

        SalesforceInterestLeadDependency::query()->upsert(
            $updates,
            ['dependency_run_id', 'salesforce_id'],
            [
                'presence_status',
                'is_origin_reference',
                'is_master_dependency',
                'is_deleted',
                'salesforce_master_record_id',
                'salesforce_last_modified_at',
                'system_modstamp_at',
                'updated_at',
            ],
        );

        $this->enqueueMasters($run, array_values(array_unique($masterIds)), $stats);
    }

    /** @param list<string> $masterIds @param array<string, int|float|string|null> $stats */
    private function enqueueMasters(
        SalesforceInterestLeadDependencyRun $run,
        array $masterIds,
        array &$stats,
    ): void {
        if ($masterIds === []) {
            return;
        }

        SalesforceInterestLeadDependency::query()
            ->where('dependency_run_id', $run->id)
            ->whereIn('salesforce_id', $masterIds)
            ->update(['is_master_dependency' => true, 'updated_at' => now()]);
        $existing = SalesforceInterestLeadDependency::query()
            ->where('dependency_run_id', $run->id)
            ->whereIn('salesforce_id', $masterIds)
            ->pluck('salesforce_id')
            ->all();
        $newIds = array_values(array_diff($masterIds, $existing));
        if ($newIds === []) {
            return;
        }

        $now = now();
        SalesforceInterestLeadDependency::query()->insert(array_map(
            fn (string $id): array => [
                'dependency_run_id' => $run->id,
                'salesforce_id' => $id,
                'presence_status' => 'pending',
                'is_origin_reference' => false,
                'is_master_dependency' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $newIds,
        ));
        $stats['masters_discovered'] += count($newIds);
        $stats['dependencies_discovered'] += count($newIds);
    }

    /** @return array<string, mixed> */
    private function dependencyUpdateRow(
        SalesforceInterestLeadDependency $dependency,
        string $presenceStatus,
        ?bool $isDeleted,
        ?string $masterId,
        ?CarbonImmutable $lastModifiedAt,
        ?CarbonImmutable $systemModstampAt,
        mixed $now,
    ): array {
        return [
            'dependency_run_id' => $dependency->dependency_run_id,
            'salesforce_id' => $dependency->salesforce_id,
            'presence_status' => $presenceStatus,
            'is_origin_reference' => $dependency->is_origin_reference,
            'is_master_dependency' => $dependency->is_master_dependency,
            'is_deleted' => $isDeleted,
            'salesforce_master_record_id' => $masterId,
            'salesforce_last_modified_at' => $lastModifiedAt,
            'system_modstamp_at' => $systemModstampAt,
            'created_at' => $dependency->created_at,
            'updated_at' => $now,
        ];
    }

    /** @param list<string> $ids */
    private function soql(array $ids): string
    {
        $quotedIds = implode(', ', array_map(fn (string $id): string => "'{$id}'", $ids));

        return 'SELECT '.self::SOQL_FIELDS." FROM Lead WHERE Id IN ({$quotedIds})";
    }

    private function isValidSalesforceId(string $id): bool
    {
        return preg_match(self::SALESFORCE_ID_PATTERN, $id) === 1;
    }

    private function assertSourceRunUnchanged(ReportSyncRun $sourceRun): void
    {
        $latest = $this->latestStableInterestRun();
        if ($latest->id !== $sourceRun->id
            || ! $latest->source_cutoff_at?->equalTo($sourceRun->source_cutoff_at)) {
            throw new RuntimeException('The Interest source changed while dependencies were being built.');
        }
    }

    private function cleanupDetailedSnapshots(
        SalesforceInterestLeadDependencyRun $currentRun,
        bool $completed,
    ): void {
        $protectedRunIds = [$currentRun->id];
        if (! $completed) {
            $latestCompleted = SalesforceInterestLeadDependencyRun::query()
                ->where('status', 'completed')
                ->orderByDesc('completed_at')
                ->orderByDesc('id')
                ->value('id');
            if ($latestCompleted !== null) {
                $protectedRunIds[] = (int) $latestCompleted;
            }
        }

        while (true) {
            $ids = SalesforceInterestLeadDependency::query()
                ->whereNotIn('dependency_run_id', $protectedRunIds)
                ->whereIn('dependency_run_id', SalesforceInterestLeadDependencyRun::query()
                    ->select('id')
                    ->whereIn('status', ['completed', 'failed']))
                ->orderBy('id')
                ->limit(self::CLEANUP_CHUNK_SIZE)
                ->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }

            DB::transaction(fn (): int => SalesforceInterestLeadDependency::query()
                ->whereIn('id', $ids->all())
                ->whereNotIn('dependency_run_id', $protectedRunIds)
                ->delete());
            $this->afterCleanupChunk($ids->count());
        }
    }

    private function nullableId(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function parseDateTime(mixed $value): ?CarbonImmutable
    {
        return blank($value) ? null : CarbonImmutable::parse($value)->utc();
    }

    /** @return array<string, int|float|string|null> */
    private function initialStats(): array
    {
        return [
            'source_interest_sync_run_id' => null,
            'source_interest_cutoff_at' => null,
            'origins_seeded' => 0,
            'dependencies_discovered' => 0,
            'masters_discovered' => 0,
            'batches' => 0,
            'query_all_calls' => 0,
            'queried_ids' => 0,
            'active' => 0,
            'deleted' => 0,
            'missing' => 0,
            'invalid' => 0,
            'errors' => 0,
            'cleanup_errors' => 0,
            'duration_seconds' => 0.0,
        ];
    }

    /** @param array<string, int|float|string|null> $stats */
    protected function afterBatch(SalesforceInterestLeadDependencyRun $run, array $stats): void {}

    protected function afterCleanupChunk(int $rowsDeleted): void {}
}
