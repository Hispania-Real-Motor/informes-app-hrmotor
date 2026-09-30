<?php

namespace App\Services\Salesforce;

use App\Models\ReportSyncRun;
use App\Models\SalesforceInterestOpportunityDependency;
use App\Models\SalesforceInterestOpportunityDependencyRun;
use App\Support\IntegrationErrorSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SalesforceInterestOpportunityDependencySyncService
{
    public const LOCK_KEY = 'salesforce_interest_opportunity_dependency_sync';

    public const SOQL_FIELDS = 'Id, IsDeleted, LastModifiedDate, SystemModstamp';

    private const LOCK_TTL_SECONDS = 21600;

    private const SALESFORCE_BATCH_SIZE = 100;

    private const CLEANUP_CHUNK_SIZE = 1000;

    private const SALESFORCE_ID_PATTERN = '/^[A-Za-z0-9]{18}$/';

    public function __construct(
        private readonly SalesforceClient $client,
    ) {}

    /** @return array{run: SalesforceInterestOpportunityDependencyRun, stats: array<string, int|float|string|null>} */
    public function sync(string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw new RuntimeException('Dependency sync requires a reason between 10 and 500 characters.');
        }

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL_SECONDS);
        if (! $lock->get()) {
            throw new RuntimeException('Another Interest Opportunity dependency sync is already running.');
        }

        $run = null;
        $stats = $this->initialStats();
        $startedAt = microtime(true);

        try {
            $sourceRun = $this->latestStableInterestRun();
            $stats['source_interest_sync_run_id'] = $sourceRun->id;
            $stats['source_interest_cutoff_at'] = $sourceRun->source_cutoff_at->utc()->toIso8601String();

            try {
                $run = SalesforceInterestOpportunityDependencyRun::query()->create([
                    'run_identifier' => (string) Str::uuid(),
                    'reason' => $reason,
                    'status' => 'running',
                    'source_interest_sync_run_id' => $sourceRun->id,
                    'source_interest_cutoff_at' => $sourceRun->source_cutoff_at,
                    'started_at' => now('UTC'),
                    'stats' => $stats,
                ]);

                $stats['references_seeded'] = $this->seedReferences($run);
                $this->processPending($run, $stats);
                $this->afterDependenciesBuilt($run, $stats);
                $this->assertCompleteCoverage($run, $stats['references_seeded']);
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
                            'Interest Opportunity dependency sync failed safely.',
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

                throw new RuntimeException('Interest Opportunity dependency sync failed safely.');
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

    private function seedReferences(SalesforceInterestOpportunityDependencyRun $run): int
    {
        $now = now();
        $source = DB::table('salesforce_interests')
            ->selectRaw('? as dependency_run_id', [$run->id])
            ->selectRaw('TRIM(inverse_opportunity_salesforce_id) as salesforce_id')
            ->selectRaw("'pending' as presence_status")
            ->selectRaw('NULL as is_deleted')
            ->selectRaw('NULL as salesforce_last_modified_at')
            ->selectRaw('NULL as system_modstamp_at')
            ->selectRaw('? as created_at', [$now])
            ->selectRaw('? as updated_at', [$now])
            ->whereNotNull('inverse_opportunity_salesforce_id')
            ->whereRaw("TRIM(inverse_opportunity_salesforce_id) <> ''")
            ->distinct();

        DB::table('salesforce_interest_opportunity_dependencies')->insertUsing([
            'dependency_run_id',
            'salesforce_id',
            'presence_status',
            'is_deleted',
            'salesforce_last_modified_at',
            'system_modstamp_at',
            'created_at',
            'updated_at',
        ], $source);

        return SalesforceInterestOpportunityDependency::query()
            ->where('dependency_run_id', $run->id)
            ->count();
    }

    /** @param array<string, int|float|string|null> $stats */
    private function processPending(SalesforceInterestOpportunityDependencyRun $run, array &$stats): void
    {
        while (true) {
            $dependencies = SalesforceInterestOpportunityDependency::query()
                ->where('dependency_run_id', $run->id)
                ->where('presence_status', 'pending')
                ->orderBy('id')
                ->limit(self::SALESFORCE_BATCH_SIZE)
                ->get();

            if ($dependencies->isEmpty()) {
                break;
            }

            $invalid = $dependencies->filter(
                fn (SalesforceInterestOpportunityDependency $dependency): bool => ! $this->isValidSalesforceId($dependency->salesforce_id),
            );
            if ($invalid->isNotEmpty()) {
                SalesforceInterestOpportunityDependency::query()
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
                fn (SalesforceInterestOpportunityDependency $dependency): bool => $invalid->contains('id', $dependency->id),
            );
            if ($valid->isNotEmpty()) {
                $this->queryAndPersistBatch($run, $valid->all(), $stats);
            }

            $stats['batches']++;
            $this->afterBatch($run, $stats);
        }
    }

    /**
     * @param  list<SalesforceInterestOpportunityDependency>  $dependencies
     * @param  array<string, int|float|string|null>  $stats
     */
    private function queryAndPersistBatch(
        SalesforceInterestOpportunityDependencyRun $run,
        array $dependencies,
        array &$stats,
    ): void {
        $ids = array_map(
            fn (SalesforceInterestOpportunityDependency $dependency): string => $dependency->salesforce_id,
            $dependencies,
        );
        $records = $this->client->queryAll($this->soql($ids));
        $stats['query_all_calls']++;
        $stats['queried_ids'] += count($ids);
        $recordsById = collect($records)->keyBy(
            fn (array $record): string => trim((string) data_get($record, 'Id')),
        );
        $now = now();
        $updates = [];

        foreach ($dependencies as $dependency) {
            $record = $recordsById->get($dependency->salesforce_id);
            if ($record === null) {
                $updates[] = $this->dependencyUpdateRow($dependency, 'missing', null, null, null, $now);
                $stats['missing']++;

                continue;
            }

            $deleted = (bool) data_get($record, 'IsDeleted', false);
            $status = $deleted ? 'deleted' : 'active';
            $updates[] = $this->dependencyUpdateRow(
                $dependency,
                $status,
                $deleted,
                $this->parseDateTime(data_get($record, 'LastModifiedDate')),
                $this->parseDateTime(data_get($record, 'SystemModstamp')),
                $now,
            );
            $stats[$status]++;
        }

        SalesforceInterestOpportunityDependency::query()->upsert(
            $updates,
            ['dependency_run_id', 'salesforce_id'],
            [
                'presence_status',
                'is_deleted',
                'salesforce_last_modified_at',
                'system_modstamp_at',
                'updated_at',
            ],
        );
    }

    /** @return array<string, mixed> */
    private function dependencyUpdateRow(
        SalesforceInterestOpportunityDependency $dependency,
        string $presenceStatus,
        ?bool $isDeleted,
        ?CarbonImmutable $lastModifiedAt,
        ?CarbonImmutable $systemModstampAt,
        mixed $now,
    ): array {
        return [
            'dependency_run_id' => $dependency->dependency_run_id,
            'salesforce_id' => $dependency->salesforce_id,
            'presence_status' => $presenceStatus,
            'is_deleted' => $isDeleted,
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

        return 'SELECT '.self::SOQL_FIELDS." FROM Opportunity WHERE Id IN ({$quotedIds})";
    }

    private function assertCompleteCoverage(
        SalesforceInterestOpportunityDependencyRun $run,
        int $referencesSeeded,
    ): void {
        $dependencies = SalesforceInterestOpportunityDependency::query()
            ->where('dependency_run_id', $run->id);

        if ((clone $dependencies)->where('presence_status', 'pending')->exists()
            || (clone $dependencies)->count() !== $referencesSeeded
            || DB::table('salesforce_interests as interests')
                ->leftJoin('salesforce_interest_opportunity_dependencies as dependencies', function ($join) use ($run): void {
                    $join->on(
                        DB::raw('TRIM(interests.inverse_opportunity_salesforce_id)'),
                        '=',
                        'dependencies.salesforce_id',
                    )->where('dependencies.dependency_run_id', $run->id);
                })
                ->whereNotNull('interests.inverse_opportunity_salesforce_id')
                ->whereRaw("TRIM(interests.inverse_opportunity_salesforce_id) <> ''")
                ->whereNull('dependencies.id')
                ->exists()) {
            throw new RuntimeException('Opportunity dependency snapshot coverage is incomplete.');
        }
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
        SalesforceInterestOpportunityDependencyRun $currentRun,
        bool $completed,
    ): void {
        $protectedRunIds = [$currentRun->id];
        if (! $completed) {
            $latestCompleted = SalesforceInterestOpportunityDependencyRun::query()
                ->where('status', 'completed')
                ->orderByDesc('completed_at')
                ->orderByDesc('id')
                ->value('id');
            if ($latestCompleted !== null) {
                $protectedRunIds[] = (int) $latestCompleted;
            }
        }

        while (true) {
            $ids = SalesforceInterestOpportunityDependency::query()
                ->whereNotIn('dependency_run_id', $protectedRunIds)
                ->whereIn('dependency_run_id', SalesforceInterestOpportunityDependencyRun::query()
                    ->select('id')
                    ->whereIn('status', ['completed', 'failed']))
                ->orderBy('id')
                ->limit(self::CLEANUP_CHUNK_SIZE)
                ->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }

            DB::transaction(fn (): int => SalesforceInterestOpportunityDependency::query()
                ->whereIn('id', $ids->all())
                ->whereNotIn('dependency_run_id', $protectedRunIds)
                ->delete());
            $this->afterCleanupChunk($ids->count());
        }
    }

    private function isValidSalesforceId(string $id): bool
    {
        return preg_match(self::SALESFORCE_ID_PATTERN, $id) === 1;
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
            'references_seeded' => 0,
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
    protected function afterBatch(SalesforceInterestOpportunityDependencyRun $run, array $stats): void {}

    /** @param array<string, int|float|string|null> $stats */
    protected function afterDependenciesBuilt(
        SalesforceInterestOpportunityDependencyRun $run,
        array $stats,
    ): void {}

    protected function afterCleanupChunk(int $rowsDeleted): void {}
}
