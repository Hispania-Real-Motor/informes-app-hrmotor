<?php

namespace App\Console\Commands;

use App\Services\Salesforce\OperationalInterestActivitySync;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/** Explicit operator action; it is not registered in the scheduler. */
final class SyncOperationalInterestActivitiesCommand extends Command
{
    protected $signature = 'salesforce:sync-interest-activities-operational
        {action : prepare, run, or status}
        {--month= : Complete operational month YYYY-MM}
        {--run-id= : Prepared or failed activity run ID}
        {--allow-salesforce-read : Explicit permission for bounded Task/Event reads}';

    protected $description = 'Build an isolated Task/Event observation for an active operational Interest version.';

    public function handle(OperationalInterestActivitySync $sync): int
    {
        try {
            $action = (string) $this->argument('action');
            $runId = filter_var($this->option('run-id'), FILTER_VALIDATE_INT);
            if ($action === 'prepare' && $this->option('month') !== null) {
                $this->line(json_encode(['run_id' => $sync->prepare((string) $this->option('month'))], JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
            if ($action === 'status' && $runId !== false && $runId > 0) {
                $this->line(json_encode($sync->status($runId), JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
            if ($action !== 'run' || $runId === false || $runId < 1 || $this->option('allow-salesforce-read') !== true) {
                throw new RuntimeException('Operational activity action requires explicit authorization.');
            }
            $this->line(json_encode($sync->run($runId), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Operational activity command stopped. Inspect safe status before resuming.');

            return self::FAILURE;
        }
    }
}
