<?php

namespace App\Console\Commands;

use App\Services\Salesforce\SalesforceInterestOpportunityReconciliationService;
use Illuminate\Console\Command;
use Throwable;

class SalesforceReconcileInterestOpportunitiesCommand extends Command
{
    protected $signature = 'salesforce:reconcile-interest-opportunities
        {--reason= : Motivo auditable obligatorio, entre 10 y 500 caracteres}';

    protected $description = 'Materializa la reconciliación local read-only Interest → Opportunity.';

    public function handle(SalesforceInterestOpportunityReconciliationService $service): int
    {
        $reason = trim((string) $this->option('reason'));
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            $this->error('--reason debe contener entre 10 y 500 caracteres no whitespace.');

            return self::FAILURE;
        }

        try {
            $stats = $service->run($reason);
        } catch (Throwable $exception) {
            $this->error(str_starts_with($exception->getMessage(), 'Another Interest–Opportunity reconciliation')
                ? $exception->getMessage()
                : 'No se pudo completar la reconciliación Interest–Opportunity de forma segura.');

            return self::FAILURE;
        }

        $this->line('INTEREST_OPPORTUNITY_RECONCILIATION_METRICS='.json_encode(
            $stats,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
        $this->info('Snapshot local Interest–Opportunity completado.');

        return self::SUCCESS;
    }
}
