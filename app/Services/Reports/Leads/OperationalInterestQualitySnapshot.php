<?php

namespace App\Services\Reports\Leads;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Value-free classification of the active operational month, built outside HTTP. */
final class OperationalInterestQualitySnapshot
{
    public function __construct(private readonly LeadDelegationNormalizer $delegations) {}

    /** @return array<string, int|null> */
    public function rebuildActive(string $month): array
    {
        if (preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw new RuntimeException('Operational month is invalid.');
        }
        $active = DB::table('salesforce_interest_operational_months')->where('month_key', $month)->first();
        if ($active === null || $active->active_run_id === null) {
            throw new RuntimeException('Operational month has no active version.');
        }
        $run = DB::table('salesforce_interest_operational_runs')->where('id', $active->active_run_id)
            ->where('month_key', $month)->where('status', 'completed')->where('phase', 'completed')
            ->where('projection_key', $active->active_projection_key)->first();
        if ($run === null || $run->overview === null) {
            throw new RuntimeException('Operational active version is inconsistent.');
        }
        $overview = json_decode($run->overview, true, 64, JSON_THROW_ON_ERROR);
        $expected = (int) data_get($overview, 'coverage.eligible_active_matching', -1);
        $quality = ['total' => 0, 'blank' => 0, 'recognized' => 0, 'general' => 0,
            'unknown_other' => 0, 'recognizable_without_alias' => null, 'relationships_insufficient' => null];
        $groups = DB::table('salesforce_interest_operational_rows')->where('run_id', $run->id)
            ->where('is_deleted', false)->select('origin_delegation')->selectRaw('COUNT(*) as total')
            ->groupBy('origin_delegation')->cursor();
        foreach ($groups as $group) {
            $count = (int) $group->total;
            $raw = trim((string) $group->origin_delegation);
            $quality['total'] += $count;
            if ($raw === '') {
                $quality['blank'] += $count;
            } elseif (mb_strtolower($raw) === 'general') {
                $quality['general'] += $count;
            } elseif ($this->delegations->normalize($raw)['is_classified']) {
                $quality['recognized'] += $count;
            } else {
                $quality['unknown_other'] += $count;
            }
        }
        if ($quality['total'] !== $expected) {
            throw new RuntimeException('Operational quality coverage does not match the active version.');
        }

        DB::transaction(function () use ($month, $active, $run, $quality): void {
            $current = DB::table('salesforce_interest_operational_months')->where('month_key', $month)->lockForUpdate()->first();
            if ($current === null || (int) $current->active_run_id !== (int) $run->id
                || $current->active_projection_key !== $run->projection_key
                || (int) $active->active_run_id !== (int) $current->active_run_id) {
                throw new RuntimeException('Operational month changed during quality validation.');
            }
            DB::table('salesforce_interest_operational_quality')->upsert([[
                'run_id' => $run->id, 'projection_key' => $run->projection_key,
                'delegation_quality' => json_encode($quality, JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]], ['run_id'], ['projection_key', 'delegation_quality', 'updated_at']);
        });

        return $quality;
    }

    /** @return array<string, int|null>|null */
    public function forRun(int $runId, string $projectionKey): ?array
    {
        $row = DB::table('salesforce_interest_operational_quality')->where('run_id', $runId)
            ->where('projection_key', $projectionKey)->first();

        return $row === null ? null : json_decode($row->delegation_quality, true, 16, JSON_THROW_ON_ERROR);
    }
}
