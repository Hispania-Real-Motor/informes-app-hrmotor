<?php

namespace App\Console\Commands;

use App\Services\Salesforce\MonthlyInterestReconciliationService;
use Illuminate\Console\Command;
use RuntimeException;

final class ReconcileMonthlyInterestEvidenceCommand extends Command
{
    protected $signature = 'interests:monthly-reconcile
        {--recovery= : Monthly F2 recovery ID}
        {--materialization= : Monthly materialization ID}
        {--f5-run= : Monthly F5 run ID}
        {--include-identifiers : Emit at most 500 restricted Salesforce identifiers}
        {--confirm-sensitive-evidence : Required together with --include-identifiers}';

    protected $description = 'Read-only reconciliation of isolated monthly Interest evidence.';

    public function handle(MonthlyInterestReconciliationService $service): int
    {
        try {
            $summary = $service->summary($this->id('recovery'), $this->id('materialization'), $this->id('f5-run'));
            if ($this->option('include-identifiers')) {
                if (! $this->option('confirm-sensitive-evidence')) {
                    throw new RuntimeException('Restricted identifiers require explicit operator confirmation.');
                }
                $summary['restricted_evidence'] = ['missing_f2_identifiers' => $service->missingIdentifiers($this->id('recovery'), $this->id('materialization'))];
            }
            $this->line(json_encode($summary, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error('Monthly reconciliation was rejected safely.');

            return self::FAILURE;
        }
    }

    private function id(string $option): int
    {
        $id = filter_var($this->option($option), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new RuntimeException('A positive journal ID is required.');
        }

        return $id;
    }
}
