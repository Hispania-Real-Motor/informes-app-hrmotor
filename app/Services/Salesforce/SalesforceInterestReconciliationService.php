<?php

namespace App\Services\Salesforce;

use App\Models\SalesforceInterest;
use App\Models\SalesforceInterestReconciliation;
use App\Models\SalesforceInterestReconciliationRun;
use App\Models\SalesforceLead;
use App\Support\IntegrationErrorSanitizer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SalesforceInterestReconciliationService
{
    public const LOCK_KEY = 'salesforce_interest_local_reconciliation';

    private const LOCK_TTL_SECONDS = 21600;

    private const CHUNK_SIZE = 200;

    private const CLEANUP_CHUNK_SIZE = 1000;

    private const MAX_MASTER_HOPS = 100;

    public function run(string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw new RuntimeException('La reconciliación requiere un motivo de entre 10 y 500 caracteres.');
        }

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL_SECONDS);
        if (! $lock->get()) {
            throw new RuntimeException('Ya existe otra reconciliación local Lead–Interest en ejecución.');
        }

        $run = null;
        $stats = $this->initialStats();
        $startedAt = microtime(true);

        try {
            try {
                $run = SalesforceInterestReconciliationRun::query()->create([
                    'run_identifier' => (string) Str::uuid(),
                    'reason' => $reason,
                    'status' => 'running',
                    'started_at' => now(),
                    'stats' => $stats,
                ]);

                $this->reconcileLeads($run, $stats);
                $this->reconcileUnmatchedInterests($run, $stats);
                $stats['duration_seconds'] = round(microtime(true) - $startedAt, 3);

                $run->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'stats' => $stats,
                    'error_message' => null,
                ]);
            } catch (Throwable) {
                $stats['errors']++;
                $stats['duration_seconds'] = round(microtime(true) - $startedAt, 3);
                $run?->update([
                    'status' => 'failed',
                    'completed_at' => now(),
                    'stats' => $stats,
                    'error_message' => IntegrationErrorSanitizer::sanitizeMessage(
                        'Interest reconciliation failed safely.',
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

                throw new RuntimeException('Interest reconciliation failed safely.');
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

    /** @param array<string, int|float|null> $stats */
    private function reconcileLeads(SalesforceInterestReconciliationRun $run, array &$stats): void
    {
        $cursor = 0;

        while (true) {
            $leads = SalesforceLead::query()
                ->select([
                    'id',
                    'salesforce_id',
                    'is_deleted',
                    'salesforce_master_record_id',
                ])
                ->where('id', '>', $cursor)
                ->orderBy('id')
                ->limit(self::CHUNK_SIZE)
                ->get();

            if ($leads->isEmpty()) {
                break;
            }

            $leadIds = $leads->pluck('salesforce_id')->map(fn (mixed $id): string => (string) $id)->all();
            $interests = SalesforceInterest::query()
                ->select($this->interestFields())
                ->whereIn('migration_origin_lead_id', $leadIds)
                ->get()
                ->keyBy('migration_origin_lead_id');
            $masters = $this->resolveMasters($leads);
            $rows = [];

            foreach ($leads as $lead) {
                /** @var SalesforceInterest|null $interest */
                $interest = $interests->get((string) $lead->salesforce_id);
                $master = $masters[(string) $lead->salesforce_id];
                $canonicalStatus = $interest === null
                    ? 'not_applicable'
                    : $this->canonicalPersonStatus($interest);
                $relationshipStatus = $interest === null ? 'lead_without_interest' : 'exact';
                $alignment = $this->currentLeadAlignment(
                    $interest,
                    (string) $lead->salesforce_id,
                    $master['resolved_id'],
                );
                $hasConflict = $this->hasStructuralConflict(
                    $relationshipStatus,
                    $master['status'],
                    $canonicalStatus,
                    $alignment,
                );

                $rows[] = $this->resolutionRow(
                    $run,
                    'lead',
                    (string) $lead->salesforce_id,
                    (string) $lead->salesforce_id,
                    $interest,
                    $relationshipStatus,
                    $master,
                    $canonicalStatus,
                    $alignment,
                    (bool) $lead->is_deleted,
                    $hasConflict,
                );

                $stats['leads_examined']++;
                $stats[$relationshipStatus]++;
                $stats['lead_deleted'] += (int) $lead->is_deleted;
                $stats['lead_merged'] += (int) ($this->nullableId($lead->salesforce_master_record_id) !== null);
                $stats['master_'.$master['status']]++;
                $stats['canonical_'.$canonicalStatus]++;
                $stats['conflicts'] += (int) $hasConflict;
            }

            $this->insertRows($rows);
            $cursor = (int) $leads->last()->id;
            $stats['last_lead_id_processed'] = $cursor;
            $stats['rows_materialized'] += count($rows);
            $this->afterLeadChunk($cursor, $stats);
        }
    }

    /** @param array<string, int|float|null> $stats */
    private function reconcileUnmatchedInterests(SalesforceInterestReconciliationRun $run, array &$stats): void
    {
        $cursor = 0;

        while (true) {
            $interests = SalesforceInterest::query()
                ->select(['id', ...$this->interestFields()])
                ->where('id', '>', $cursor)
                ->orderBy('id')
                ->limit(self::CHUNK_SIZE)
                ->get();

            if ($interests->isEmpty()) {
                break;
            }

            $originIds = $interests->pluck('migration_origin_lead_id')
                ->map($this->nullableId(...))
                ->filter()
                ->unique()
                ->values()
                ->all();
            $existingOrigins = $originIds === []
                ? []
                : SalesforceLead::query()
                    ->whereIn('salesforce_id', $originIds)
                    ->pluck('salesforce_id')
                    ->mapWithKeys(fn (mixed $id): array => [(string) $id => true])
                    ->all();
            $rows = [];

            foreach ($interests as $interest) {
                $stats['interests_examined']++;
                $stats['interest_deleted'] += (int) $interest->is_deleted;
                $originId = $this->nullableId($interest->migration_origin_lead_id);

                if ($originId !== null && isset($existingOrigins[$originId])) {
                    continue;
                }

                $relationshipStatus = $originId === null
                    ? 'interest_without_migration_origin'
                    : 'interest_origin_missing_lead';
                $canonicalStatus = $this->canonicalPersonStatus($interest);
                $hasConflict = $this->hasStructuralConflict(
                    $relationshipStatus,
                    'not_applicable',
                    $canonicalStatus,
                    'not_applicable',
                );
                $rows[] = $this->resolutionRow(
                    $run,
                    'interest',
                    (string) $interest->salesforce_id,
                    null,
                    $interest,
                    $relationshipStatus,
                    ['status' => 'not_applicable', 'immediate_id' => null, 'resolved_id' => null],
                    $canonicalStatus,
                    'not_applicable',
                    null,
                    $hasConflict,
                );

                $stats[$relationshipStatus]++;
                $stats['canonical_'.$canonicalStatus]++;
                $stats['master_not_applicable']++;
                $stats['conflicts'] += (int) $hasConflict;
            }

            $this->insertRows($rows);
            $cursor = (int) $interests->last()->id;
            $stats['last_interest_id_processed'] = $cursor;
            $stats['rows_materialized'] += count($rows);
            $this->afterInterestChunk($cursor, $stats);
        }
    }

    /** @param Collection<int, SalesforceLead> $leads @return array<string, array{status:string,immediate_id:?string,resolved_id:?string}> */
    private function resolveMasters(Collection $leads): array
    {
        $loaded = $leads->keyBy('salesforce_id')->all();
        $missing = [];
        $expanded = [];
        $frontier = $leads->pluck('salesforce_master_record_id')
            ->map($this->nullableId(...))->filter()->unique()->values()->all();

        for ($depth = 0; $depth < self::MAX_MASTER_HOPS && $frontier !== []; $depth++) {
            $toExpand = array_values(array_filter(
                $frontier,
                fn (string $id): bool => ! isset($expanded[$id]) && ! isset($missing[$id]),
            ));
            if ($toExpand === []) {
                break;
            }

            $unknown = array_values(array_filter(
                $toExpand,
                fn (string $id): bool => ! isset($loaded[$id]),
            ));

            if ($unknown !== []) {
                $found = SalesforceLead::query()
                    ->select(['id', 'salesforce_id', 'is_deleted', 'salesforce_master_record_id'])
                    ->whereIn('salesforce_id', $unknown)
                    ->get()
                    ->keyBy('salesforce_id');

                foreach ($unknown as $id) {
                    $lead = $found->get($id);
                    if ($lead === null) {
                        $missing[$id] = true;
                    } else {
                        $loaded[$id] = $lead;
                    }
                }
            }

            $nextFrontier = [];
            foreach ($toExpand as $id) {
                $expanded[$id] = true;
                if (! isset($loaded[$id])) {
                    continue;
                }

                $nextId = $this->nullableId($loaded[$id]->salesforce_master_record_id);
                if ($nextId !== null) {
                    $nextFrontier[] = $nextId;
                }
            }
            $frontier = array_values(array_unique($nextFrontier));
        }

        $resolved = [];
        foreach ($leads as $lead) {
            $originId = (string) $lead->salesforce_id;
            $immediateId = $this->nullableId($lead->salesforce_master_record_id);
            if ($immediateId === null) {
                $resolved[$originId] = ['status' => 'none', 'immediate_id' => null, 'resolved_id' => null];

                continue;
            }

            $currentId = $originId;
            $nextId = $immediateId;
            $visited = [$originId => true];
            $hops = 0;
            $status = 'depth_exceeded';
            $resolvedId = null;

            while ($hops < self::MAX_MASTER_HOPS) {
                if ($nextId === $currentId) {
                    $status = $hops === 0 ? 'self_reference' : 'cycle';
                    break;
                }
                if (isset($visited[$nextId])) {
                    $status = 'cycle';
                    break;
                }
                if (isset($missing[$nextId])) {
                    $status = 'missing';
                    break;
                }
                if (! isset($loaded[$nextId])) {
                    $status = 'depth_exceeded';
                    break;
                }

                $visited[$nextId] = true;
                $currentId = $nextId;
                $hops++;
                $nextId = $this->nullableId($loaded[$currentId]->salesforce_master_record_id);

                if ($nextId === null) {
                    $status = $hops === 1 ? 'direct' : 'chain';
                    $resolvedId = $currentId;
                    break;
                }
            }

            $resolved[$originId] = [
                'status' => $status,
                'immediate_id' => $immediateId,
                'resolved_id' => $resolvedId,
            ];
        }

        return $resolved;
    }

    /** @return list<string> */
    private function interestFields(): array
    {
        return [
            'salesforce_id',
            'migration_origin_lead_id',
            'lead_salesforce_id',
            'account_salesforce_id',
            'canonical_person_type',
            'canonical_person_salesforce_id',
            'is_deleted',
        ];
    }

    private function canonicalPersonStatus(SalesforceInterest $interest): string
    {
        $accountId = $this->nullableId($interest->account_salesforce_id);
        $leadId = $this->nullableId($interest->lead_salesforce_id);
        $actualType = $this->nullableId($interest->canonical_person_type);
        $actualId = $this->nullableId($interest->canonical_person_salesforce_id);

        if ($accountId !== null) {
            return $actualType === 'Account' && $actualId === $accountId
                ? 'coherent_account'
                : 'inconsistent';
        }
        if ($leadId !== null) {
            return $actualType === 'Lead' && $actualId === $leadId
                ? 'coherent_lead'
                : 'inconsistent';
        }

        return $actualType === null && $actualId === null ? 'coherent_none' : 'inconsistent';
    }

    private function currentLeadAlignment(?SalesforceInterest $interest, string $originId, ?string $masterId): string
    {
        if ($interest === null) {
            return 'not_applicable';
        }

        $currentLeadId = $this->nullableId($interest->lead_salesforce_id);
        if ($currentLeadId === null) {
            return 'no_current_lead';
        }
        if ($currentLeadId === $originId) {
            return 'matches_origin';
        }
        if ($masterId !== null && $currentLeadId === $masterId) {
            return 'matches_master';
        }

        return 'other';
    }

    private function hasStructuralConflict(
        string $relationshipStatus,
        string $masterStatus,
        string $canonicalStatus,
        string $currentLeadAlignment,
    ): bool {
        if ($canonicalStatus === 'inconsistent'
            || $relationshipStatus === 'interest_origin_missing_lead'
            || in_array($masterStatus, ['missing', 'self_reference', 'cycle', 'depth_exceeded'], true)
            || $currentLeadAlignment === 'other') {
            return true;
        }

        return in_array($masterStatus, ['direct', 'chain'], true)
            && $currentLeadAlignment === 'matches_origin';
    }

    /** @param array{status:string,immediate_id:?string,resolved_id:?string} $master @return array<string, mixed> */
    private function resolutionRow(
        SalesforceInterestReconciliationRun $run,
        string $subjectType,
        string $subjectId,
        ?string $leadId,
        ?SalesforceInterest $interest,
        string $relationshipStatus,
        array $master,
        string $canonicalStatus,
        string $alignment,
        ?bool $leadDeleted,
        bool $hasConflict,
    ): array {
        $now = now();

        return [
            'reconciliation_run_id' => $run->id,
            'subject_type' => $subjectType,
            'subject_salesforce_id' => $subjectId,
            'lead_salesforce_id' => $leadId,
            'interest_salesforce_id' => $interest?->salesforce_id,
            'migration_origin_lead_id' => $interest?->migration_origin_lead_id,
            'immediate_master_lead_id' => $master['immediate_id'],
            'resolved_master_lead_id' => $master['resolved_id'],
            'relationship_status' => $relationshipStatus,
            'master_status' => $master['status'],
            'canonical_person_status' => $canonicalStatus,
            'current_lead_alignment' => $alignment,
            'lead_is_deleted' => $leadDeleted,
            'interest_is_deleted' => $interest === null ? null : (bool) $interest->is_deleted,
            'has_conflict' => $hasConflict,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function insertRows(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        DB::transaction(fn (): bool => SalesforceInterestReconciliation::query()->insert($rows));
    }

    private function cleanupDetailedSnapshots(
        SalesforceInterestReconciliationRun $currentRun,
        bool $completed,
    ): void {
        $protectedRunIds = [$currentRun->id];

        if (! $completed) {
            $lastCompletedRunId = SalesforceInterestReconciliationRun::query()
                ->where('status', 'completed')
                ->orderByDesc('completed_at')
                ->orderByDesc('id')
                ->value('id');

            if ($lastCompletedRunId !== null) {
                $protectedRunIds[] = (int) $lastCompletedRunId;
            }
        }

        while (true) {
            $ids = SalesforceInterestReconciliation::query()
                ->whereNotIn('reconciliation_run_id', $protectedRunIds)
                ->whereIn('reconciliation_run_id', SalesforceInterestReconciliationRun::query()
                    ->select('id')
                    ->whereIn('status', ['completed', 'failed']))
                ->orderBy('id')
                ->limit(self::CLEANUP_CHUNK_SIZE)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            DB::transaction(fn (): int => SalesforceInterestReconciliation::query()
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

    /** @return array<string, int|float|null> */
    private function initialStats(): array
    {
        return [
            'leads_examined' => 0,
            'interests_examined' => 0,
            'rows_materialized' => 0,
            'exact' => 0,
            'lead_without_interest' => 0,
            'interest_origin_missing_lead' => 0,
            'interest_without_migration_origin' => 0,
            'lead_deleted' => 0,
            'lead_merged' => 0,
            'interest_deleted' => 0,
            'master_none' => 0,
            'master_direct' => 0,
            'master_chain' => 0,
            'master_missing' => 0,
            'master_self_reference' => 0,
            'master_cycle' => 0,
            'master_depth_exceeded' => 0,
            'master_not_applicable' => 0,
            'canonical_coherent_account' => 0,
            'canonical_coherent_lead' => 0,
            'canonical_coherent_none' => 0,
            'canonical_inconsistent' => 0,
            'canonical_not_applicable' => 0,
            'conflicts' => 0,
            'errors' => 0,
            'cleanup_errors' => 0,
            'last_lead_id_processed' => null,
            'last_interest_id_processed' => null,
            'duration_seconds' => 0.0,
        ];
    }

    /** @param array<string, int|float|null> $stats */
    protected function afterLeadChunk(int $cursor, array $stats): void {}

    /** @param array<string, int|float|null> $stats */
    protected function afterInterestChunk(int $cursor, array $stats): void {}

    protected function afterCleanupChunk(int $rowsDeleted): void {}
}
