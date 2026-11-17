<?php

namespace App\Services\Salesforce;

use App\Services\Reports\Leads\OperationalInterestKpiProjector;
use App\Services\Reports\Leads\OperationalInterestMonthlyReader;
use App\Services\Reports\Leads\OperationalInterestQualitySnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Orchestrates only the bounded operational Interest pipeline for one Madrid month. */
class OperationalInterestCurrentAutomation
{
    public function __construct(
        private readonly OperationalInterestMonthlySync $interests,
        private readonly OperationalInterestQualitySnapshot $quality,
        private readonly OperationalInterestActivitySync $activities,
        private readonly OperationalInterestKpiProjector $kpis,
        private readonly OperationalInterestMonthlyReader $reader,
    ) {}

    /** @return array{month:string,interest_run_id:int,activity_run_id:int,projection_id:int} */
    public function run(): array
    {
        $month = CarbonImmutable::now('Europe/Madrid')->format('Y-m');
        if (! in_array($month, ['2026-10', '2026-11'], true)) {
            throw new RuntimeException('Operational KPI contract is not enabled for the current month.');
        }

        $interestRunId = $this->completeInterest($month);
        $this->quality->rebuildActive($month);
        $activityRunId = $this->completeActivities($month);
        $projection = $this->kpis->build((int) substr($month, 0, 4), (int) substr($month, 5, 2));

        if (($projection['status'] ?? null) !== 'completed') {
            throw new RuntimeException('Operational KPI projection did not complete.');
        }

        return [
            'month' => $month,
            'interest_run_id' => $interestRunId,
            'activity_run_id' => $activityRunId,
            'projection_id' => (int) ($projection['projection_id'] ?? 0),
        ];
    }

    private function completeInterest(string $month): int
    {
        $run = DB::table('salesforce_interest_operational_runs')->where('month_key', $month)
            ->whereIn('status', ['prepared', 'running', 'failed'])->orderByDesc('id')->first();
        if ($run !== null && $run->status === 'failed' && $this->isSupersededByActive($run)) {
            $run = null;
        }
        if ($run !== null) {
            $status = $this->interests->status((int) $run->id);
            if ($status['status'] !== 'completed' || $status['phase'] !== 'completed') {
                $this->interests->run((int) $run->id);
            }
        } else {
            $runId = $this->interests->prepare($month);
            $this->interests->run($runId);
        }

        return (int) $this->reader->latestForMonth((int) substr($month, 0, 4), (int) substr($month, 5, 2))['integrity_revision'];
    }

    private function isSupersededByActive(object $candidate): bool
    {
        $month = DB::table('salesforce_interest_operational_months')->where('month_key', $candidate->month_key)->first();
        if ($month === null || $month->active_run_id === null) {
            return false;
        }

        $active = DB::table('salesforce_interest_operational_runs')->where('id', $month->active_run_id)->first();
        if ($active === null || $active->status !== 'completed' || $active->phase !== 'completed'
            || $active->projection_key !== $month->active_projection_key) {
            throw new RuntimeException('Operational active pointer is inconsistent.');
        }

        return CarbonImmutable::parse($candidate->effective_cutoff_at, 'UTC')
            ->lessThanOrEqualTo(CarbonImmutable::parse($active->effective_cutoff_at, 'UTC'));
    }

    private function completeActivities(string $month): int
    {
        $source = $this->reader->latestForMonth((int) substr($month, 0, 4), (int) substr($month, 5, 2));
        if ($source['activity_run_id'] !== null) {
            return (int) $source['activity_run_id'];
        }
        $run = DB::table('salesforce_interest_operational_activity_runs')->where('month_key', $month)
            ->where('source_run_id', $source['integrity_revision'])
            ->whereIn('status', ['prepared', 'running', 'failed'])->orderByDesc('id')->first();
        if ($run !== null) {
            $status = $this->activities->status((int) $run->id);
            if ($status['status'] !== 'completed' || $status['phase'] !== 'completed') {
                $this->activities->run((int) $run->id);
            }
        } else {
            $runId = $this->activities->prepare($month);
            $this->activities->run($runId);
        }

        $active = $this->reader->latestForMonth((int) substr($month, 0, 4), (int) substr($month, 5, 2));
        if ($active['activity_run_id'] === null) {
            throw new RuntimeException('Operational activity run did not become active.');
        }

        return (int) $active['activity_run_id'];
    }
}
