<?php

namespace App\Services\Reports\Leads;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** HTTP reads only the activated month pointer and precalculated projection. */
final class OperationalInterestMonthlyReader
{
    public function __construct(private readonly OperationalInterestQualitySnapshot $quality) {}

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
            $quality = $this->quality->forRun((int) $run->id, (string) $run->projection_key);
            if ($quality !== null) {
                if ((int) ($quality['total'] ?? -1) !== (int) ($overview['coverage']['eligible_active_matching'] ?? -2)) {
                    throw new ProvisionalInterestSourceUnavailable;
                }
                $overview['coverage']['delegation_quality'] = $quality;
            }
            $activityPointer = $monthRow->active_activity_run_id;
            $activityRun = $activityPointer === null ? null
                : DB::table('salesforce_interest_operational_activity_runs')->where('id', $activityPointer)
                    ->where('source_run_id', $run->id)->where('month_key', $key)
                    ->where('status', 'completed')->where('phase', 'completed')->first();
            $kpiProjection = $activityRun === null ? null
                : DB::table('salesforce_interest_operational_kpi_projections')
                    ->where('source_run_id', $run->id)->where('activity_run_id', $activityRun->id)
                    ->where('source_projection_key', $run->projection_key)
                    ->where('status', 'completed')->first();
            if ($kpiProjection !== null
                && ((int) $kpiProjection->rows_count !== (int) $overview['coverage']['eligible_active_matching']
                    || (bool) $kpiProjection->activity_coverage_complete !== (bool) $activityRun->coverage_complete)) {
                throw new ProvisionalInterestSourceUnavailable;
            }
            $this->assertCurrent($key, (int) $run->id, (string) $run->projection_key);
            $this->assertActivityPointer($key, $activityPointer === null ? null : (int) $activityPointer);

            return ['overview' => $overview, 'projection_key' => (string) $run->projection_key,
                'integrity_revision' => (int) $run->id, 'scope' => 'operational_'.str_replace('-', '_', $key),
                'operational_month' => $key, 'activity_pointer_id' => $activityPointer === null ? null : (int) $activityPointer,
                'activity_run_id' => $activityRun === null ? null : (int) $activityRun->id,
                'activity_observed_at' => $activityRun?->completed_at,
                'activity_coverage_complete' => $activityRun === null ? null : (bool) $activityRun->coverage_complete,
                'activity_verified_count' => $activityRun === null ? null : (int) $activityRun->verified_count,
                'activity_quarantined_count' => $activityRun === null ? null : (int) $activityRun->quarantined_count,
                'kpi_projection_id' => $kpiProjection === null ? null : (int) $kpiProjection->id,
                'kpi_reference_at' => $kpiProjection?->reference_at,
                'commercial_users_observed_at' => $kpiProjection?->commercial_users_observed_at];
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

    public function assertActivityPointer(string $month, ?int $runId): void
    {
        try {
            $query = DB::table('salesforce_interest_operational_months')->where('month_key', $month);
            $runId === null ? $query->whereNull('active_activity_run_id') : $query->where('active_activity_run_id', $runId);
            if (! $query->exists()) {
                throw new ProvisionalInterestSourceUnavailable;
            }
        } catch (Throwable) {
            throw new ProvisionalInterestSourceUnavailable;
        }
    }
}
