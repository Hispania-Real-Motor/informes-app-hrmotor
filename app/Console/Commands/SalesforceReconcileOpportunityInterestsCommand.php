<?php

namespace App\Console\Commands;

use App\Services\Salesforce\SalesforceOpportunityInterestReconciliationService;
use Illuminate\Console\Command;
use Throwable;

class SalesforceReconcileOpportunityInterestsCommand extends Command
{
    protected $signature = 'salesforce:reconcile-opportunity-interests
        {--reason= : Motivo auditable obligatorio, entre 10 y 500 caracteres}';

    protected $description = 'Reconcilia localmente las evidencias directas e inversas Opportunity–Interest.';

    public function handle(SalesforceOpportunityInterestReconciliationService $service): int
    {
        $reason = trim((string) $this->option('reason'));
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            $this->error('--reason debe contener entre 10 y 500 caracteres no whitespace.');

            return self::FAILURE;
        }

        try {
            $stats = $service->run($reason);
        } catch (Throwable $exception) {
            $this->error(str_starts_with($exception->getMessage(), 'Another bidirectional Opportunity Interest reconciliation')
                ? $exception->getMessage()
                : 'No se pudo completar la reconciliación bidireccional de forma segura.');

            return self::FAILURE;
        }

        $this->line('OPPORTUNITY_INTEREST_RECONCILIATION_METRICS='.json_encode(
            $stats,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
        $this->info('Reconciliación bidireccional Opportunity–Interest completada.');

        return self::SUCCESS;
    }
}
