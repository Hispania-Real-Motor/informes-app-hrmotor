<?php

namespace App\Services\Reports\Leads;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** HTTP reads only the activated month pointer and precalculated projection. */
final class OperationalInterestMonthlyReader
{
    public function hasActiveForMonth(int $year, int $month): bool
    {
        try {
            return DB::table('salesforce_interest_operational_months')
                ->where('month_key', sprintf('%04d-%02d', $year, $month))
                ->whereNotNull('active_run_id')->exists();
        } catch (Throwable) {
            throw new ProvisionalInterestSourceUnavailable;
        }
    }

    public function latestForMonth(int $year, int $month): array
    {
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            throw new ProvisionalInterestSourceUnavailable;
        }
        $key = sprintf('%04d-%02d', $year, $month);
        try {
            $monthRow = DB::table('salesforce_interest_operational_months')->where('month_key', $key)->first();
            if ($monthRow === null || $monthRow->active_run_id === null
                || ! Str::isUuid((string) $monthRow->active_projection_key)) {
                throw new ProvisionalInterestSourceUnavailable;
            }
            $run = DB::table('salesforce_interest_operational_runs')->where('id', $monthRow->active_run_id)
                ->where('month_key', $key)->where('status', 'completed')->where('phase', 'completed')
                ->where('projection_key', $monthRow->active_projection_key)->first();
            if ($run === null || $run->overview === null) {
                throw new ProvisionalInterestSourceUnavailable;
            }
            $overview = json_decode($run->overview, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($overview) || ! isset($overview['coverage'], $overview['classification'], $overview['functional_kpis'])) {
                throw new ProvisionalInterestSourceUnavailable;
            }
            $this->assertCurrent($key, (int) $run->id, (string) $run->projection_key);

            return ['overview' => $overview, 'projection_key' => (string) $run->projection_key,
                'integrity_revision' => (int) $run->id, 'scope' => 'operational_'.str_replace('-', '_', $key),
                'operational_month' => $key];
        } catch (Throwable) {
            throw new ProvisionalInterestSourceUnavailable;
        }
    }

    public function assertCurrent(string $month, int $runId, string $projectionKey): void
    {
        try {
            $valid = DB::table('salesforce_interest_operational_months')->where('month_key', $month)
                ->where('active_run_id', $runId)->where('active_projection_key', $projectionKey)->exists();
            if (! $valid) {
                throw new ProvisionalInterestSourceUnavailable;
            }
        } catch (Throwable) {
            throw new ProvisionalInterestSourceUnavailable;
        }
    }
}
