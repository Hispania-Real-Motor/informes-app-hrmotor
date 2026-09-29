<?php

namespace App\Services\Salesforce;

use App\Models\ReportSyncRun;
use App\Models\SalesforceInterest;
use App\Models\SalesforceInterestOpportunityReconciliation;
use App\Models\SalesforceInterestOpportunityReconciliationRun;
use App\Models\SalesforceOpportunity;
use App\Models\SalesforceOpportunityPresenceReconciliationRun;
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
            $opportunitySource = $this->latestStableOpportunityRun();
            $presenceRunId = SalesforceOpportunityPresenceReconciliationRun::query()
                ->latest('id')
                ->value('id');

            try {
                $run = SalesforceInterestOpportunityReconciliationRun::query()->create([
                    'run_identifier' => (string) Str::uuid(),
                    'reason' => $reason,
                    'status' => 'running',
                    'source_interest_sync_run_id' => $interestSource->id,
                    'source_interest_cutoff_at' => $interestSource->source_cutoff_at,
                    'source_opportunity_sync_run_id' => $opportunitySource->id,
                    'source_opportunity_sync_status' => $opportunitySource->status,
                    'source_opportunity_period_end_at' => $opportunitySource->period_end_at,
                    'source_opportunity_completed_at' => $opportunitySource->completed_at,
                    'source_opportunity_presence_run_id' => $presenceRunId,
                    'started_at' => now('UTC'),
                    'stats' => $stats,
                ]);

                $stats['distinct_opportunities_referenced'] = SalesforceInterest::query()
                    ->whereNotNull('inverse_opportunity_salesforce_id')
                    ->where('inverse_opportunity_salesforce_id', '<>', '')
                    ->distinct()
                    ->count('inverse_opportunity_salesforce_id');
                $this->materialize($run, $stats);
                $this->beforeSourceValidation($run, $stats);
                $this->assertInterestSourceUnchanged($interestSource);
                $this->assertOpportunitySourceUnchanged($opportunitySource);
                $this->assertReferencedOpportunityEvidenceUnchanged($run);
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
            $opportunities = $this->opportunitiesBySalesforceId($opportunityIds);
            $referenceCounts = $this->referenceCounts($opportunityIds);
            $now = now();
            $rows = [];

            foreach ($interests as $interest) {
                $opportunityId = $this->nullableId($interest->inverse_opportunity_salesforce_id);
                /** @var SalesforceOpportunity|null $opportunity */
                $opportunity = $opportunityId === null ? null : $opportunities->get($opportunityId);
                $referenceCount = $opportunityId === null ? 0 : ($referenceCounts[$opportunityId] ?? 0);
                $relationshipStatus = $opportunityId === null
                    ? 'no_inverse'
                    : ($referenceCount > 1 ? 'inverse_shared' : 'inverse_unique');
                $presenceStatus = $this->presenceStatus($opportunityId, $opportunity);
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
                    'interest_is_deleted' => (bool) $interest->is_deleted,
                    'opportunity_is_deleted' => $opportunity?->is_deleted,
                    'opportunity_salesforce_deleted_at' => $opportunity?->salesforce_deleted_at,
                    'opportunity_deletion_detection_source' => $opportunity?->deletion_detection_source,
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

    /** @param list<string> $ids @return Collection<string, SalesforceOpportunity> */
    private function opportunitiesBySalesforceId(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return SalesforceOpportunity::withoutGlobalScope(SalesforceOpportunity::ACTIVE_SCOPE)
            ->select([
                'salesforce_id',
                'is_deleted',
                'salesforce_deleted_at',
                'deletion_detection_source',
            ])
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
            ->select('inverse_opportunity_salesforce_id')
            ->selectRaw('COUNT(*) as reference_count')
            ->whereIn('inverse_opportunity_salesforce_id', $ids)
            ->groupBy('inverse_opportunity_salesforce_id')
            ->pluck('reference_count', 'inverse_opportunity_salesforce_id')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    private function presenceStatus(?string $opportunityId, ?SalesforceOpportunity $opportunity): string
    {
        if ($opportunityId === null) {
            return 'not_applicable';
        }
        if ($opportunity === null) {
            return 'not_local';
        }
        if ($opportunity->is_deleted
            && $opportunity->deletion_detection_source === SalesforceOpportunity::DELETION_SOURCE_QUERY_ALL) {
            return 'present_deleted';
        }
        if (! $opportunity->is_deleted
            && $opportunity->deletion_detection_source === SalesforceOpportunity::PRESENCE_SOURCE_MISSING) {
            return 'present_missing';
        }
        if (! $opportunity->is_deleted && $opportunity->deletion_detection_source === null) {
            return 'present_active';
        }

        return 'present_unresolved';
    }

    private function requiresReview(string $relationshipStatus, string $presenceStatus, bool $interestDeleted): bool
    {
        return $relationshipStatus === 'inverse_shared'
            || in_array($presenceStatus, ['not_local', 'present_missing', 'present_unresolved'], true)
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

    private function latestStableOpportunityRun(): ReportSyncRun
    {
        $run = ReportSyncRun::query()
            ->where('dataset', 'salesforce_opportunities')
            ->where('source', 'salesforce')
            ->latest('id')
            ->first();

        if ($run === null
            || $run->status !== 'completed'
            || $run->period_end_at === null
            || $run->completed_at === null) {
            throw new RuntimeException('The latest Opportunity sync run is not a stable completed source.');
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

    private function assertOpportunitySourceUnchanged(ReportSyncRun $source): void
    {
        $latest = $this->latestStableOpportunityRun();
        if ($latest->id !== $source->id
            || ! $latest->period_end_at?->equalTo($source->period_end_at)
            || ! $latest->completed_at?->equalTo($source->completed_at)) {
            throw new RuntimeException('The Opportunity pipeline changed while reconciliation was being built.');
        }
    }

    private function assertReferencedOpportunityEvidenceUnchanged(
        SalesforceInterestOpportunityReconciliationRun $run,
    ): void {
        $cursor = 0;

        while (true) {
            $rows = SalesforceInterestOpportunityReconciliation::query()
                ->where('reconciliation_run_id', $run->id)
                ->whereNotNull('opportunity_salesforce_id')
                ->where('id', '>', $cursor)
                ->orderBy('id')
                ->limit(self::CHUNK_SIZE)
                ->get();
            if ($rows->isEmpty()) {
                break;
            }

            $opportunityIds = $rows->pluck('opportunity_salesforce_id')->unique()->values()->all();
            $opportunities = $this->opportunitiesBySalesforceId($opportunityIds);

            foreach ($rows as $row) {
                /** @var SalesforceOpportunity|null $opportunity */
                $opportunity = $opportunities->get($row->opportunity_salesforce_id);
                if (! $this->sameOpportunityEvidence($row, $opportunity)) {
                    throw new RuntimeException('Referenced Opportunity evidence changed during reconciliation.');
                }
            }

            $cursor = (int) $rows->last()->id;
        }
    }

    private function sameOpportunityEvidence(
        SalesforceInterestOpportunityReconciliation $snapshot,
        ?SalesforceOpportunity $current,
    ): bool {
        if ($current === null) {
            return $snapshot->opportunity_presence_status === 'not_local';
        }
        if ($snapshot->opportunity_presence_status === 'not_local') {
            return false;
        }

        return (bool) $snapshot->opportunity_is_deleted === (bool) $current->is_deleted
            && $snapshot->opportunity_deletion_detection_source === $current->deletion_detection_source
            && $snapshot->opportunity_salesforce_deleted_at?->toIso8601String()
                === $current->salesforce_deleted_at?->toIso8601String();
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
