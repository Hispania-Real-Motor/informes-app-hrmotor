<?php

namespace App\Services\Reports\Leads;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Pinned, bounded local evidence for administrative report preparation. */
final class OperationalInterestSourceReader
{
    private const BATCH = 500;

    private const PROTECTED_FIELDS = ['original_source', 'utm_campaign', 'utm_content', 'utm_id', 'utm_medium', 'utm_source', 'utm_term'];

    public function __construct(private readonly OperationalInterestMonthlyReader $months) {}

    public function open(int $year, int $month, bool $requireUtm = false): array
    {
        try {
            $snapshot = $this->months->latestForMonth($year, $month);
            $run = DB::table('salesforce_interest_operational_runs')->where('id', $snapshot['integrity_revision'])
                ->where('month_key', $snapshot['operational_month'])->where('status', 'completed')->first();
            if ($run === null || ($requireUtm && (int) $run->utm_contract_version < 2)) {
                throw new ProvisionalInterestSourceUnavailable;
            }
            $this->months->assertCurrent($snapshot['operational_month'], $snapshot['integrity_revision'], $snapshot['projection_key']);
            $this->months->assertActivityPointer($snapshot['operational_month'], $snapshot['activity_pointer_id']);
        } catch (Throwable) {
            throw new ProvisionalInterestSourceUnavailable;
        }

        return [
            'source_type' => 'operational_provisional',
            'month' => $snapshot['operational_month'],
            'run_id' => $snapshot['integrity_revision'],
            'projection_key' => $snapshot['projection_key'],
            'activity_run_id' => $snapshot['activity_run_id'],
            'activity_pointer_id' => $snapshot['activity_pointer_id'],
            'activity_coverage_complete' => $snapshot['activity_coverage_complete'],
            'utm_contract_version' => (int) $run->utm_contract_version,
            'functional_start_at' => $run->functional_start_at,
            'functional_end_at' => $run->functional_end_at,
            'effective_cutoff_at' => $run->effective_cutoff_at,
            'observed_at' => $run->completed_at,
            'coverage' => $snapshot['overview']['coverage'],
            'incidents' => $snapshot['overview']['coverage']['change_categories'] ?? [],
        ];
    }

    /** Callback receives at most 500 active rows. Consumers must stage results until the final assertion passes. */
    public function eachBatch(array $version, callable $consume): void
    {
        $cursor = 0;
        try {
            do {
                $this->assertCurrent($version);
                $rows = DB::table('salesforce_interest_operational_rows')
                    ->where('run_id', $version['run_id'])->where('is_deleted', false)
                    ->where('id', '>', $cursor)->orderBy('id')->limit(self::BATCH)->get();
                if ($rows->isEmpty()) {
                    break;
                }
                $batch = [];
                foreach ($rows as $row) {
                    $entry = [
                        'salesforce_id' => $row->salesforce_id,
                        'functional_created_at' => $row->functional_created_at,
                        'status' => $row->status,
                        'type' => $row->type,
                        'source' => $row->source,
                        'medium' => $row->medium,
                        'channel' => $row->channel,
                        'origin_delegation' => $row->origin_delegation,
                        'owner_salesforce_id' => $row->owner_salesforce_id,
                        'lead_salesforce_id' => $row->lead_salesforce_id,
                        'account_salesforce_id' => $row->account_salesforce_id,
                        'sale_vehicle_salesforce_id' => $row->sale_vehicle_salesforce_id,
                        'appraisal_vehicle_salesforce_id' => $row->appraisal_vehicle_salesforce_id,
                        'inverse_opportunity_salesforce_id' => $row->inverse_opportunity_salesforce_id,
                        'observed_at' => $row->observed_at,
                        'content_hash' => $row->content_hash,
                    ];
                    foreach (self::PROTECTED_FIELDS as $field) {
                        $ciphertext = $row->{$field.'_ciphertext'};
                        $entry[$field] = $ciphertext === null ? null : Crypt::decryptString($ciphertext);
                    }
                    $batch[] = $entry;
                }
                $consume($batch, $version);
                $cursor = (int) $rows->last()->id;
                $this->assertCurrent($version);
            } while ($rows->count() === self::BATCH);
        } catch (Throwable) {
            throw new ProvisionalInterestSourceUnavailable;
        }
        $this->assertCurrent($version);
    }

    public function assertCurrent(array $version): void
    {
        $this->months->assertCurrent($version['month'], $version['run_id'], $version['projection_key']);
        $this->months->assertActivityPointer($version['month'], $version['activity_pointer_id']);
    }
}
