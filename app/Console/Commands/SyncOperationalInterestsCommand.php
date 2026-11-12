<?php

namespace App\Console\Commands;

use App\Services\Salesforce\OperationalInterestMonthlySync;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/** Explicit, isolated operator entry point; no scheduler registration. */
final class SyncOperationalInterestsCommand extends Command
{
    protected $signature = 'salesforce:sync-interests-operational
        {action : prepare, run, status, or sync-current}
        {--month= : Complete YYYY-MM month in Europe/Madrid}
        {--run-id= : Prepared or failed operational run identifier}
        {--allow-salesforce-read : Explicit authorization for bounded Salesforce reads}';

    protected $description = 'Build a provisional operational Interest month without publishing an official generation.';

    public function handle(OperationalInterestMonthlySync $sync): int
    {
        $action = (string) $this->argument('action');
        $runId = filter_var($this->option('run-id'), FILTER_VALIDATE_INT);
        try {
            if ($action === 'status' && $runId !== false) {
                $this->line(json_encode($sync->status($runId), JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
            if ($action === 'prepare' && $this->option('month') !== null) {
                $this->line(json_encode(['run_id' => $sync->prepare((string) $this->option('month'))], JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
            if (! in_array($action, ['run', 'sync-current'], true) || $this->option('allow-salesforce-read') !== true) {
                throw new \RuntimeException('Operational command requires a valid action and explicit read authorization.');
            }
            if ($action === 'sync-current') {
                $month = CarbonImmutable::now('Europe/Madrid')->format('Y-m');
                $runId = $sync->prepare($month);
            }
            if ($runId === false || $runId === null || $runId < 1) {
                throw new \RuntimeException('Operational run identifier is required.');
            }
            $this->line(json_encode($sync->run($runId), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Operational Interest command stopped. Inspect the safe run status before retrying.');

            return self::FAILURE;
        }
    }
}
