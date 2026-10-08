<?php

namespace App\Services\Salesforce;

use App\Models\ReportSyncRun;
use App\Models\SalesforceInterestActivityRun;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class SalesforceInterestReportingPipelineService
{
    public const LOCK_KEY = 'salesforce-interest-reporting-pipeline';

    public function __construct(
        private readonly SalesforceInterestSyncService $interests,
        private readonly SalesforceInterestActivitySyncService $activities,
    ) {}

    /** @return array{interest_run: ReportSyncRun, activity_run: SalesforceInterestActivityRun} */
    public function run(): array
    {
        $lock = Cache::lock(self::LOCK_KEY, 1800);
        if (! $lock->get()) {
            throw new RuntimeException('Another Interest reporting pipeline is already running.');
        }

        try {
            $f2Run = $this->syncInterests();
            if ($f2Run->status !== 'completed' || $f2Run->source_cutoff_at === null) {
                throw new RuntimeException('F2 did not publish a completed run with cutoff.');
            }

            $f5Run = $this->syncActivities();
            if ($f5Run->status !== 'completed'
                || (int) $f5Run->source_interest_sync_run_id !== (int) $f2Run->id
                || ! $f5Run->source_interest_cutoff_at?->equalTo($f2Run->source_cutoff_at)) {
                throw new RuntimeException('F5 is not aligned with the F2 run published by the pipeline.');
            }

            return ['interest_run' => $f2Run, 'activity_run' => $f5Run];
        } finally {
            $lock->release();
        }
    }

    protected function syncInterests(): ReportSyncRun
    {
        return $this->interests->sync(SalesforceInterestSyncService::MODE_INCREMENTAL)['run'];
    }

    protected function syncActivities(): SalesforceInterestActivityRun
    {
        return $this->activities
            ->sync('Pipeline periódico ROT-1 posterior a F2 incremental')['run'];
    }
}
