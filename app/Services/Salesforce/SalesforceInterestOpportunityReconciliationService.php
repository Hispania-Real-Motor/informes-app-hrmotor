<?php

namespace App\Services\Salesforce;

use App\Models\ReportSyncRun;
use App\Models\SalesforceInterest;
use App\Models\SalesforceInterestOpportunityDependency;
use App\Models\SalesforceInterestOpportunityDependencyRun;
use App\Models\SalesforceInterestOpportunityReconciliation;
use App\Models\SalesforceInterestOpportunityReconciliationRun;
use App\Support\IntegrationErrorSanitizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SalesforceInterestOpportunityReconciliationService
{
    public const LOCK_KEY = 'salesforce_interest_opportunity_reconciliation';

    private const LOCK_TTL_SECONDS = 21600;

    private const CHUNK_SIZE = 200;

    private const CLEANUP_CHUNK_SIZE = 1000;

    private const NORMALIZED_OPPORTUNITY_ID_SQL = 'TRIM(inverse_opportunity_salesforce_id)';

    /** @return array<string, int|float|string|null> */
    public function run(string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw new RuntimeException('Interest–Opportunity reconciliation requires a reason between 10 and 500 characters.');
        }

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL_SECONDS);
        if (! $lock->get()) {
            throw new RuntimeException('Another Interest–Opportunity reconciliation is already running.');
        }

        $run = null;
        $stats = $this->initialStats();
        $startedAt = microtime(true);

        try {
            $interestSource = $this->latestStableInterestRun();
            $dependencyRun = $this->latestStableDependencyRun($interestSource);
            $this->assertDependencyCoverage($dependencyRun);

            try {
                $run = SalesforceInterestOpportunityReconciliationRun::query()->create([
                    'run_identifier' => (string) Str::uuid(),
                    'reason' => $reason,
                    'status' => 'running',
                    'opportunity_dependency_run_id' => $dependencyRun->id,
                    'source_interest_sync_run_id' => $interestSource->id,
                    'source_interest_cutoff_at' => $interestSource->source_cutoff_at,
                    'started_at' => now('UTC'),
                    'stats' => $stats,
                ]);

                $stats['distinct_opportunities_referenced'] = (int) SalesforceInterest::query()
                    ->whereNotNull('inverse_opportunity_salesforce_id')
                    ->whereRaw(self::NORMALIZED_OPPORTUNITY_ID_SQL." <> ''")
                    ->selectRaw('COUNT(DISTINCT '.self::NORMALIZED_OPPORTUNITY_ID_SQL.') as aggregate')
                    ->value('aggregate');
                $this->materialize($run, $stats);
                $this->beforeSourceValidation($run, $stats);
                $this->assertInterestSourceUnchanged($interestSource);
                $this->assertDependencyRunUnchanged($dependencyRun, $interestSource);
                $this->assertDependencyCoverage($dependencyRun);
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
                            'Interest–Opportunity reconciliation failed safely.',
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

                throw new RuntimeException('Interest–Opportunity reconciliation failed safely.');
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

            return $stats;
        } finally {
            $lock->release();
        }
    }

    /** @param array<string, int|float|string|null> $stats */
    private function materialize(
        SalesforceInterestOpportunityReconciliationRun $run,
        array &$stats,
    ): void {
        $cursor = 0;

        while (true) {
            $interests = SalesforceInterest::query()
                ->select(['id', 'salesforce_id', 'inverse_opportunity_salesforce_id', 'is_deleted'])
                ->where('id', '>', $cursor)
                ->orderBy('id')
                ->limit(self::CHUNK_SIZE)
                ->get();

            if ($interests->isEmpty()) {
                break;
            }

            $opportunityIds = $interests->pluck('inverse_opportunity_salesforce_id')
                ->map($this->nullableId(...))
                ->filter()
                ->unique()
                ->values()
                ->all();
            $dependencies = $this->dependenciesBySalesforceId($run, $opportunityIds);
            $referenceCounts = $this->referenceCounts($opportunityIds);
            $now = now();
            $rows = [];

            foreach ($interests as $interest) {
                $opportunityId = $this->nullableId($interest->inverse_opportunity_salesforce_id);
                /** @var SalesforceInterestOpportunityDependency|null $dependency */
                $dependency = $opportunityId === null ? null : $dependencies->get($opportunityId);
                $referenceCount = $opportunityId === null ? 0 : ($referenceCounts[$opportunityId] ?? 0);
                $relationshipStatus = $opportunityId === null
                    ? 'no_inverse'
                    : ($referenceCount > 1 ? 'inverse_shared' : 'inverse_unique');
                $presenceStatus = $this->presenceStatus($opportunityId, $dependency);
                $requiresReview = $this->requiresReview(
                    $relationshipStatus,
                    $presenceStatus,
                    (bool) $interest->is_deleted,
                );

                $rows[] = [
                    'reconciliation_run_id' => $run->id,
                    'interest_salesforce_id' => (string) $interest->salesforce_id,
                    'opportunity_salesforce_id' => $opportunityId,
                    'relationship_status' => $relationshipStatus,
                    'opportunity_presence_status' => $presenceStatus,
                    'opportunity_evidence_source' => $opportunityId === null
                        ? null
                        : 'interest_opportunity_dependency_snapshot',
                    'interest_is_deleted' => (bool) $interest->is_deleted,
                    'opportunity_is_deleted' => $dependency?->is_deleted,
                    'opportunity_salesforce_deleted_at' => $dependency?->presence_status === 'deleted'
                        ? $dependency->system_modstamp_at
                        : null,
                    'opportunity_deletion_detection_source' => $dependency?->presence_status === 'deleted'
                        ? 'query_all_deleted'
                        : null,
                    'inverse_reference_count' => $referenceCount,
                    'requires_review' => $requiresReview,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $stats['interests_examined']++;
                $stats['interests_deleted'] += (int) $interest->is_deleted;
                if ($relationshipStatus === 'no_inverse') {
                    $stats['interests_without_inverse']++;
                } else {
                    $stats[$relationshipStatus]++;
                }

                if ($opportunityId !== null) {
                    $stats['inverse_references']++;
                    $stats['opportunities_'.$presenceStatus]++;
                }
                $stats['requires_review'] += (int) $requiresReview;
            }

            DB::table('salesforce_interest_opportunity_reconciliations')->insert($rows);
            $cursor = (int) $interests->last()->id;
            $stats['last_interest_id_processed'] = $cursor;
            $this->afterInterestChunk($cursor, $stats);
        }
    }

    /** @param list<string> $ids @return \Illuminate\Support\Collection<string, SalesforceInterestOpportunityDependency> */
    private function dependenciesBySalesforceId(
        SalesforceInterestOpportunityReconciliationRun $run,
        array $ids,
    ): Collection {
        if ($ids === []) {
            return collect();
        }

        return SalesforceInterestOpportunityDependency::query()
            ->select([
                'salesforce_id',
                'presence_status',
                'is_deleted',
                'salesforce_last_modified_at',
                'system_modstamp_at',
            ])
            ->where('dependency_run_id', $run->opportunity_dependency_run_id)
            ->whereIn('salesforce_id', $ids)
            ->get()
            ->keyBy('salesforce_id');
    }

    /** @param list<string> $ids @return array<string, int> */
    private function referenceCounts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return SalesforceInterest::query()
            ->selectRaw(self::NORMALIZED_OPPORTUNITY_ID_SQL.' as normalized_opportunity_salesforce_id')
            ->selectRaw('COUNT(*) as reference_count')
            ->whereNotNull('inverse_opportunity_salesforce_id')
            ->whereRaw(self::NORMALIZED_OPPORTUNITY_ID_SQL." <> ''")
            ->whereIn(DB::raw(self::NORMALIZED_OPPORTUNITY_ID_SQL), $ids)
            ->groupByRaw(self::NORMALIZED_OPPORTUNITY_ID_SQL)
            ->pluck('reference_count', 'normalized_opportunity_salesforce_id')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    private function presenceStatus(
        ?string $opportunityId,
        ?SalesforceInterestOpportunityDependency $dependency,
    ): string {
        if ($opportunityId === null) {
            return 'not_applicable';
        }
        if ($dependency === null) {
            return 'present_unresolved';
        }

        return match ($dependency->presence_status) {
            'active' => $dependency->is_deleted === false ? 'present_active' : 'present_unresolved',
            'deleted' => $dependency->is_deleted === true ? 'present_deleted' : 'present_unresolved',
            'missing' => $dependency->is_deleted === null ? 'salesforce_missing' : 'present_unresolved',
            'invalid' => $dependency->is_deleted === null ? 'invalid_reference' : 'present_unresolved',
            default => 'present_unresolved',
        };
    }

    private function requiresReview(string $relationshipStatus, string $presenceStatus, bool $interestDeleted): bool
    {
        return $relationshipStatus === 'inverse_shared'
            || in_array($presenceStatus, [
                'salesforce_missing',
                'invalid_reference',
                'present_unresolved',
            ], true)
            || (! $interestDeleted && $presenceStatus === 'present_deleted');
    }

    private function latestStableInterestRun(): ReportSyncRun
    {
        $run = ReportSyncRun::query()
            ->where('dataset', SalesforceInterestSyncService::DATASET)
            ->where('source', SalesforceInterestSyncService::SOURCE)
            ->latest('id')
            ->first();

        if ($run === null || $run->status !== 'completed' || $run->source_cutoff_at === null) {
            throw new RuntimeException('The latest Interest sync run is not a stable completed source.');
        }

        return $run;
    }

    private function latestStableDependencyRun(
        ReportSyncRun $interestSource,
    ): SalesforceInterestOpportunityDependencyRun {
        $run = SalesforceInterestOpportunityDependencyRun::query()
            ->latest('id')
            ->first();

        if ($run === null
            || $run->status !== 'completed'
            || $run->source_interest_sync_run_id !== $interestSource->id
            || ! $run->source_interest_cutoff_at?->equalTo($interestSource->source_cutoff_at)) {
            throw new RuntimeException('The latest Opportunity dependency run is not current for the Interest source.');
        }

        return $run;
    }

    private function assertInterestSourceUnchanged(ReportSyncRun $source): void
    {
        $latest = $this->latestStableInterestRun();
        if ($latest->id !== $source->id
            || ! $latest->source_cutoff_at?->equalTo($source->source_cutoff_at)) {
            throw new RuntimeException('The Interest source changed while reconciliation was being built.');
        }
    }

    private function assertDependencyRunUnchanged(
        SalesforceInterestOpportunityDependencyRun $dependencyRun,
        ReportSyncRun $interestSource,
    ): void {
        $latest = $this->latestStableDependencyRun($interestSource);
        if ($latest->id !== $dependencyRun->id) {
            throw new RuntimeException('The Opportunity dependency snapshot changed during reconciliation.');
        }
    }

    private function assertDependencyCoverage(
        SalesforceInterestOpportunityDependencyRun $dependencyRun,
    ): void {
        if (SalesforceInterestOpportunityDependency::query()
            ->where('dependency_run_id', $dependencyRun->id)
            ->where('presence_status', 'pending')
            ->exists()
            || DB::table('salesforce_interests as interests')
                ->leftJoin('salesforce_interest_opportunity_dependencies as dependencies', function ($join) use ($dependencyRun): void {
                    $join->on(
                        DB::raw('TRIM(interests.inverse_opportunity_salesforce_id)'),
                        '=',
                        'dependencies.salesforce_id',
                    )->where('dependencies.dependency_run_id', $dependencyRun->id);
                })
                ->whereNotNull('interests.inverse_opportunity_salesforce_id')
                ->whereRaw("TRIM(interests.inverse_opportunity_salesforce_id) <> ''")
                ->whereNull('dependencies.id')
                ->exists()) {
            throw new RuntimeException('Opportunity dependency snapshot coverage is incomplete.');
        }
    }

    private function cleanupDetailedSnapshots(
        SalesforceInterestOpportunityReconciliationRun $currentRun,
        bool $completed,
    ): void {
        $protectedRunIds = [$currentRun->id];
        if (! $completed) {
            $latestCompleted = SalesforceInterestOpportunityReconciliationRun::query()
                ->where('status', 'completed')
                ->latest('completed_at')
                ->latest('id')
                ->value('id');
            if ($latestCompleted !== null) {
                $protectedRunIds[] = (int) $latestCompleted;
            }
        }

        while (true) {
            $ids = SalesforceInterestOpportunityReconciliation::query()
                ->whereNotIn('reconciliation_run_id', $protectedRunIds)
                ->whereIn('reconciliation_run_id', SalesforceInterestOpportunityReconciliationRun::query()
                    ->select('id')
                    ->whereIn('status', ['completed', 'failed']))
                ->orderBy('id')
                ->limit(self::CLEANUP_CHUNK_SIZE)
                ->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }

            DB::transaction(fn (): int => SalesforceInterestOpportunityReconciliation::query()
                ->whereIn('id', $ids->all())
                ->whereNotIn('reconciliation_run_id', $protectedRunIds)
                ->delete());
            $this->afterCleanupChunk($ids->count());
        }
    }

    private function nullableId(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @return array<string, int|float|string|null> */
    private function initialStats(): array
    {
        return [
            'interests_examined' => 0,
            'interests_without_inverse' => 0,
            'inverse_references' => 0,
            'distinct_opportunities_referenced' => 0,
            'inverse_unique' => 0,
            'inverse_shared' => 0,
            'opportunities_present_active' => 0,
            'opportunities_present_deleted' => 0,
            'opportunities_present_missing' => 0,
            'opportunities_not_local' => 0,
            'opportunities_present_unresolved' => 0,
            'opportunities_salesforce_missing' => 0,
            'opportunities_invalid_reference' => 0,
            'interests_deleted' => 0,
            'requires_review' => 0,
            'errors' => 0,
            'cleanup_errors' => 0,
            'last_interest_id_processed' => null,
            'duration_seconds' => 0.0,
        ];
    }

    /** @param array<string, int|float|string|null> $stats */
    protected function afterInterestChunk(int $cursor, array $stats): void {}

    /** @param array<string, int|float|string|null> $stats */
    protected function beforeSourceValidation(
        SalesforceInterestOpportunityReconciliationRun $run,
        array $stats,
    ): void {}

    protected function afterCleanupChunk(int $rowsDeleted): void {}
}
