<?php

namespace App\Console\Commands;

use App\Services\Salesforce\OperationalInterestCurrentAutomation;
use Illuminate\Console\Command;
use Throwable;

final class SyncOperationalInterestCurrentCommand extends Command
{
    protected $signature = 'salesforce:sync-interest-operational-current
        {--allow-salesforce-read : Explicit authorization for bounded operational Interest reads}';

    protected $description = 'Updates the current operational Interest month without touching official generations.';

    public function handle(OperationalInterestCurrentAutomation $automation): int
    {
        if ($this->option('allow-salesforce-read') !== true) {
            $this->error('Operational Interest automation requires explicit read authorization.');

            return self::FAILURE;
        }

        try {
            $this->line(json_encode($automation->run(), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Operational Interest automation stopped. Inspect safe run status before resuming.');

            return self::FAILURE;
        }
    }
}
