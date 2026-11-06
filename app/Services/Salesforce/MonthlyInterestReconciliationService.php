<?php

namespace App\Services\Salesforce;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Builds read-only, bounded evidence from the isolated monthly journals. */
final class MonthlyInterestReconciliationService
{
    /** @return array<string,mixed> */
    public function summary(int $recoveryId, int $materializationId, int $activityRunId): array
    {
        $recovery = DB::table('salesforce_interest_monthly_recoveries')->find($recoveryId)
            ?? throw new RuntimeException('Monthly F2 recovery does not exist.');
        $materialization = DB::table('salesforce_interest_monthly_materializations')->find($materializationId)
            ?? throw new RuntimeException('Monthly F2 materialization does not exist.');
        $activityRun = DB::table('salesforce_interest_monthly_activity_recoveries')->find($activityRunId)
            ?? throw new RuntimeException('Monthly F5 run does not exist.');
        if ((int) $materialization->monthly_recovery_id !== $recoveryId || (int) $activityRun->monthly_recovery_id !== $recoveryId) {
            throw new RuntimeException('The supplied journals do not share the same monthly F2 recovery.');
        }

        $f2Counters = $this->json($recovery->counters);
        $materializationCounters = $this->json($materialization->counters);
        $f5Counters = $this->json($activityRun->counters);
        $f2Evidence = $this->json($recovery->evidence);
        $f5Evidence = $this->json($activityRun->evidence);

        return [
            'read_only' => true,
            'published' => false,
            'historical_certification' => 'unavailable',
            'f2' => [
                'recovery_id' => $recoveryId,
                'rows' => DB::table('salesforce_interest_monthly_recovery_rows')->where('monthly_recovery_id', $recoveryId)->count(),
                'soft_deleted_original' => DB::table('salesforce_interest_monthly_recovery_rows')->where('monthly_recovery_id', $recoveryId)->where('is_deleted', true)->count(),
                'verification' => data_get($f2Evidence, 'verification', []),
                'verification_content_changes_identifiable' => false,
                'verification_content_changes_reason' => 'Only counters and hashes were retained; prior field values and per-row verification evidence are unavailable.',
                'reported_content_changes' => (int) data_get($f2Counters, 'content_changed', 0),
            ],
            'materialization' => [
                'materialization_id' => $materializationId,
                'rows' => DB::table('salesforce_interest_monthly_materialized_rows')->where('monthly_materialization_id', $materializationId)->count(),
                'missing_from_materialization' => $this->missingCount($recoveryId, $materializationId),
                'content_changed' => DB::table('salesforce_interest_monthly_materialized_rows')->where('monthly_materialization_id', $materializationId)->where('content_matches_inventory', false)->count(),
                'without_canonical_person' => DB::table('salesforce_interest_monthly_materialized_rows')->where('monthly_materialization_id', $materializationId)->whereNull('canonical_person_salesforce_id')->count(),
                'soft_deleted_observed' => DB::table('salesforce_interest_monthly_materialized_rows')->where('monthly_materialization_id', $materializationId)->where('is_deleted', true)->count(),
                'classification_limit' => 'The F2 journal retains only the original hash. Changed rows are intentionally classified as historical_value_unavailable unless an independently retained prior value exists.',
                'reported_counters' => $materializationCounters,
            ],
            'f5' => [
                'run_id' => $activityRunId,
                'rows' => DB::table('salesforce_interest_monthly_activity_rows')->where('monthly_activity_recovery_id', $activityRunId)->count(),
                'verification' => data_get($f5Evidence, 'verification', []),
                'additional_remote_ids_identifiable' => false,
                'additional_remote_ids_reason' => 'The completed historical run retained the verification counter, not a per-row discrepancy journal.',
                'reported_counters' => $f5Counters,
            ],
            'observability' => [
                'soft_delete' => 'observable only when returned by queryAll.',
                'hard_delete' => 'not proven by absence from queryAll.',
                'get_deleted' => 'No historical hard-delete coverage is inferred from these journals.',
            ],
        ];
    }

    /** @return list<array{salesforce_id:string,evidence_sha256:string}> */
    public function missingIdentifiers(int $recoveryId, int $materializationId, int $limit = 500): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new RuntimeException('A safe evidence limit between 1 and 500 is required.');
        }

        return DB::table('salesforce_interest_monthly_recovery_rows as source')
            ->leftJoin('salesforce_interest_monthly_materialized_rows as materialized', function ($join) use ($materializationId): void {
                $join->on('materialized.salesforce_id', '=', 'source.salesforce_id')->where('materialized.monthly_materialization_id', '=', $materializationId);
            })->where('source.monthly_recovery_id', $recoveryId)->whereNull('materialized.id')->orderBy('source.salesforce_id')->limit($limit)
            ->get(['source.salesforce_id', 'source.content_hash'])->map(fn (object $row): array => ['salesforce_id' => $row->salesforce_id, 'evidence_sha256' => $row->content_hash])->all();
    }

    private function missingCount(int $recoveryId, int $materializationId): int
    {
        return DB::table('salesforce_interest_monthly_recovery_rows as source')
            ->leftJoin('salesforce_interest_monthly_materialized_rows as materialized', function ($join) use ($materializationId): void {
                $join->on('materialized.salesforce_id', '=', 'source.salesforce_id')->where('materialized.monthly_materialization_id', '=', $materializationId);
            })->where('source.monthly_recovery_id', $recoveryId)->whereNull('materialized.id')->count();
    }

    /** @return array<string,mixed> */
    private function json(?string $value): array
    {
        return $value === null ? [] : json_decode($value, true, 64, JSON_THROW_ON_ERROR);
    }
}
