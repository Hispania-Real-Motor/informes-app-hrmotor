<?php

namespace App\Services\Salesforce;

use App\Models\SalesforceInterest;
use App\Models\SalesforceInterestOpportunityReconciliation;
use App\Models\SalesforceInterestOpportunityReconciliationRun;
use App\Models\SalesforceOpportunityInterestDirect;
use App\Models\SalesforceOpportunityInterestDirectRun;
use App\Models\SalesforceOpportunityInterestReconciliation;
use App\Models\SalesforceOpportunityInterestReconciliationRun;
use App\Support\IntegrationErrorSanitizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SalesforceOpportunityInterestReconciliationService
{
    public const LOCK_KEY = 'salesforce_opportunity_interest_bidirectional_reconciliation';

    private const LOCK_TTL_SECONDS = 21600;

    private const CHUNK_SIZE = 200;

    private const CLEANUP_CHUNK_SIZE = 1000;

    /** @return array<string, int|float|string|null> */
    public function run(string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw new RuntimeException('Bidirectional Opportunity Interest reconciliation requires a reason between 10 and 500 characters.');
        }

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL_SECONDS);
        if (! $lock->get()) {
            throw new RuntimeException('Another bidirectional Opportunity Interest reconciliation is already running.');
        }

        $run = null;
        $stats = $this->initialStats();
        $startedAt = microtime(true);

        try {
            $directRun = $this->latestStableDirectRun();
            $inverseRun = $this->latestStableInverseRun();

            try {
                $run = SalesforceOpportunityInterestReconciliationRun::query()->create([
                    'run_identifier' => (string) Str::uuid(),
                    'reason' => $reason,
                    'status' => 'running',
                    'direct_run_id' => $directRun->id,
                    'direct_cutoff_at' => $directRun->source_cutoff_at,
                    'inverse_run_id' => $inverseRun->id,
                    'inverse_interest_sync_run_id' => $inverseRun->source_interest_sync_run_id,
                    'inverse_interest_cutoff_at' => $inverseRun->source_interest_cutoff_at,
                    'started_at' => now('UTC'),
                    'stats' => $stats,
                ]);

                $this->materializeDirectUniverse($run, $directRun, $inverseRun, $stats);
                $this->materializeInverseOnlyUniverse($run, $directRun, $inverseRun, $stats);
                $this->afterSnapshotBuilt($run, $stats);
                $this->assertSourcesUnchanged($directRun, $inverseRun);
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
                            'Bidirectional Opportunity Interest reconciliation failed safely.',
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

                throw new RuntimeException('Bidirectional Opportunity Interest reconciliation failed safely.');
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
    private function materializeDirectUniverse(
        SalesforceOpportunityInterestReconciliationRun $run,
        SalesforceOpportunityInterestDirectRun $directRun,
        SalesforceInterestOpportunityReconciliationRun $inverseRun,
        array &$stats,
    ): void {
        $cursor = 0;
        while (true) {
            $directs = SalesforceOpportunityInterestDirect::query()
                ->where('direct_run_id', $directRun->id)
                ->where('id', '>', $cursor)
                ->orderBy('id')
                ->limit(self::CHUNK_SIZE)
                ->get();
            if ($directs->isEmpty()) {
                break;
            }

            $opportunityIds = $directs->pluck('opportunity_salesforce_id')->all();
            $inverseByOpportunity = $this->inverseEvidence($inverseRun, $opportunityIds);
            $interestPresence = $this->interestPresence($directs->pluck('interest_salesforce_id')->filter()->all());
            $rows = [];
            foreach ($directs as $direct) {
                $inverse = $inverseByOpportunity->get($direct->opportunity_salesforce_id, collect());
                $rows[] = $this->resolutionRow($run, $direct, $inverse, $interestPresence, $stats);
            }
            DB::table('salesforce_opportunity_interest_reconciliations')->insert($rows);
            $cursor = (int) $directs->last()->id;
            $stats['chunks']++;
            $this->afterMaterializeChunk($run, $stats);
        }
    }

    /** @param array<string, int|float|string|null> $stats */
    private function materializeInverseOnlyUniverse(
        SalesforceOpportunityInterestReconciliationRun $run,
        SalesforceOpportunityInterestDirectRun $directRun,
        SalesforceInterestOpportunityReconciliationRun $inverseRun,
        array &$stats,
    ): void {
        $cursor = '';
        while (true) {
            $groups = SalesforceInterestOpportunityReconciliation::query()
                ->select('opportunity_salesforce_id')
                ->where('reconciliation_run_id', $inverseRun->id)
                ->whereNotNull('opportunity_salesforce_id')
                ->where('opportunity_salesforce_id', '>', $cursor)
                ->whereNotExists(function ($query) use ($directRun): void {
                    $query->selectRaw('1')
                        ->from('salesforce_opportunity_interest_directs as direct')
                        ->whereColumn(
                            'direct.opportunity_salesforce_id',
                            'salesforce_interest_opportunity_reconciliations.opportunity_salesforce_id',
                        )
                        ->where('direct.direct_run_id', $directRun->id);
                })
                ->groupBy('opportunity_salesforce_id')
                ->orderBy('opportunity_salesforce_id')
                ->limit(self::CHUNK_SIZE)
                ->get();
            if ($groups->isEmpty()) {
                break;
            }

            $opportunityIds = $groups->pluck('opportunity_salesforce_id')->all();
            $inverseByOpportunity = $this->inverseEvidence($inverseRun, $opportunityIds);
            $rows = [];
            foreach ($opportunityIds as $opportunityId) {
                $rows[] = $this->resolutionRow(
                    $run,
                    null,
                    $inverseByOpportunity->get($opportunityId, collect()),
                    collect(),
                    $stats,
                );
            }
            DB::table('salesforce_opportunity_interest_reconciliations')->insert($rows);
            $cursor = (string) $groups->last()->opportunity_salesforce_id;
            $stats['chunks']++;
            $this->afterMaterializeChunk($run, $stats);
        }
    }

    /** @param list<string> $opportunityIds @return Collection<string, Collection<int, SalesforceInterestOpportunityReconciliation>> */
    private function inverseEvidence(
        SalesforceInterestOpportunityReconciliationRun $inverseRun,
        array $opportunityIds,
    ): Collection {
        return SalesforceInterestOpportunityReconciliation::query()
            ->where('reconciliation_run_id', $inverseRun->id)
            ->whereIn('opportunity_salesforce_id', $opportunityIds)
            ->orderBy('id')
            ->get()
            ->groupBy('opportunity_salesforce_id');
    }

    /** @param list<string> $interestIds @return Collection<string, SalesforceInterest> */
    private function interestPresence(array $interestIds): Collection
    {
        return SalesforceInterest::query()
            ->select(['salesforce_id', 'is_deleted'])
            ->whereIn('salesforce_id', array_values(array_unique($interestIds)))
            ->get()
            ->keyBy('salesforce_id');
    }

    /**
     * @param  Collection<int, SalesforceInterestOpportunityReconciliation>  $inverse
     * @param  Collection<string, SalesforceInterest>  $interestPresence
     * @param  array<string, int|float|string|null>  $stats
     * @return array<string, mixed>
     */
    private function resolutionRow(
        SalesforceOpportunityInterestReconciliationRun $run,
        ?SalesforceOpportunityInterestDirect $direct,
        Collection $inverse,
        Collection $interestPresence,
        array &$stats,
    ): array {
        $opportunityId = $direct?->opportunity_salesforce_id
            ?? (string) $inverse->firstOrFail()->opportunity_salesforce_id;
        $inverseIds = $inverse->pluck('interest_salesforce_id')->unique()->values()->all();
        $inverseCount = count($inverseIds);
        $directId = $direct?->interest_salesforce_id;
        $relationship = $this->relationshipStatus($direct, $directId, $inverseIds);
        $interest = $directId === null ? null : $interestPresence->get($directId);
        $interestPresenceStatus = match (true) {
            $direct === null => 'not_applicable',
            $direct->reference_status !== 'valid' => 'invalid_reference',
            $interest === null => 'not_local',
            (bool) $interest->is_deleted => 'present_deleted',
            default => 'present_active',
        };
        $opportunityDeleted = $direct?->opportunity_is_deleted
            ?? $inverse->first()?->opportunity_is_deleted;
        $requiresReview = in_array($relationship, [
            'direct_only', 'inverse_only', 'contradiction', 'inverse_shared', 'unresolved',
        ], true) || $interestPresenceStatus === 'not_local' || $opportunityDeleted === true;

        $stats['opportunities_examined']++;
        $stats[$relationship]++;
        $stats['direct_references'] += (int) ($direct !== null);
        $stats['inverse_references'] += $inverseCount;
        $stats['opportunities_deleted'] += (int) ($opportunityDeleted === true);
        $stats['direct_interests_not_local'] += (int) ($interestPresenceStatus === 'not_local');
        $stats['invalid_references'] += (int) ($interestPresenceStatus === 'invalid_reference');
        $stats['requires_review'] += (int) $requiresReview;

        $now = now();

        return [
            'reconciliation_run_id' => $run->id,
            'opportunity_salesforce_id' => $opportunityId,
            'direct_interest_salesforce_id' => $directId,
            'inverse_interest_salesforce_ids' => $inverseIds === [] ? null : json_encode($inverseIds, JSON_THROW_ON_ERROR),
            'inverse_reference_count' => $inverseCount,
            'relationship_status' => $relationship,
            'direct_reference_status' => $direct?->reference_status,
            'direct_interest_presence_status' => $interestPresenceStatus,
            'direct_interest_is_deleted' => $interest?->is_deleted,
            'opportunity_is_deleted' => $opportunityDeleted,
            'requires_review' => $requiresReview,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @param list<string> $inverseIds */
    private function relationshipStatus(
        ?SalesforceOpportunityInterestDirect $direct,
        ?string $directId,
        array $inverseIds,
    ): string {
        if ($direct !== null && $direct->reference_status !== 'valid') {
            return 'unresolved';
        }
        if ($direct === null) {
            return count($inverseIds) > 1 ? 'inverse_shared' : 'inverse_only';
        }
        if ($inverseIds === []) {
            return 'direct_only';
        }
        if (count($inverseIds) > 1) {
            return 'inverse_shared';
        }

        return $inverseIds[0] === $directId ? 'both_match' : 'contradiction';
    }

    private function latestStableDirectRun(): SalesforceOpportunityInterestDirectRun
    {
        $run = SalesforceOpportunityInterestDirectRun::query()->latest('id')->first();
        if ($run === null || $run->status !== 'completed' || $run->source_cutoff_at === null) {
            throw new RuntimeException('The latest direct Opportunity Interest run is not stable.');
        }

        return $run;
    }

    private function latestStableInverseRun(): SalesforceInterestOpportunityReconciliationRun
    {
        $run = SalesforceInterestOpportunityReconciliationRun::query()->latest('id')->first();
        if ($run === null
            || $run->status !== 'completed'
            || $run->source_interest_cutoff_at === null) {
            throw new RuntimeException('The latest inverse Interest Opportunity run is not stable.');
        }

        return $run;
    }

    private function assertSourcesUnchanged(
        SalesforceOpportunityInterestDirectRun $directRun,
        SalesforceInterestOpportunityReconciliationRun $inverseRun,
    ): void {
        $currentDirect = $this->latestStableDirectRun();
        $currentInverse = $this->latestStableInverseRun();
        if ($currentDirect->id !== $directRun->id
            || ! $currentDirect->source_cutoff_at?->equalTo($directRun->source_cutoff_at)
            || $currentInverse->id !== $inverseRun->id
            || ! $currentInverse->source_interest_cutoff_at?->equalTo($inverseRun->source_interest_cutoff_at)) {
            throw new RuntimeException('Opportunity Interest evidence changed during reconciliation.');
        }
    }

    private function cleanupDetailedSnapshots(
        SalesforceOpportunityInterestReconciliationRun $currentRun,
        bool $completed,
    ): void {
        $protectedRunIds = [$currentRun->id];
        if (! $completed) {
            $latestCompleted = SalesforceOpportunityInterestReconciliationRun::query()
                ->where('status', 'completed')
                ->orderByDesc('completed_at')
                ->orderByDesc('id')
                ->value('id');
            if ($latestCompleted !== null) {
                $protectedRunIds[] = (int) $latestCompleted;
            }
        }

        while (true) {
            $ids = SalesforceOpportunityInterestReconciliation::query()
                ->whereNotIn('reconciliation_run_id', $protectedRunIds)
                ->whereIn('reconciliation_run_id', SalesforceOpportunityInterestReconciliationRun::query()
                    ->select('id')
                    ->whereIn('status', ['completed', 'failed']))
                ->orderBy('id')
                ->limit(self::CLEANUP_CHUNK_SIZE)
                ->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }

            DB::transaction(fn (): int => SalesforceOpportunityInterestReconciliation::query()
                ->whereIn('id', $ids->all())
                ->whereNotIn('reconciliation_run_id', $protectedRunIds)
                ->delete());
            $this->afterCleanupChunk($ids->count());
        }
    }

    /** @return array<string, int|float|string|null> */
    private function initialStats(): array
    {
        return [
            'opportunities_examined' => 0,
            'direct_references' => 0,
            'inverse_references' => 0,
            'both_match' => 0,
            'direct_only' => 0,
            'inverse_only' => 0,
            'contradiction' => 0,
            'inverse_shared' => 0,
            'unresolved' => 0,
            'opportunities_deleted' => 0,
            'direct_interests_not_local' => 0,
            'invalid_references' => 0,
            'requires_review' => 0,
            'chunks' => 0,
            'errors' => 0,
            'cleanup_errors' => 0,
            'duration_seconds' => 0.0,
        ];
    }

    /** @param array<string, int|float|string|null> $stats */
    protected function afterMaterializeChunk(
        SalesforceOpportunityInterestReconciliationRun $run,
        array $stats,
    ): void {}

    /** @param array<string, int|float|string|null> $stats */
    protected function afterSnapshotBuilt(
        SalesforceOpportunityInterestReconciliationRun $run,
        array $stats,
    ): void {}

    protected function afterCleanupChunk(int $rowsDeleted): void {}
}
