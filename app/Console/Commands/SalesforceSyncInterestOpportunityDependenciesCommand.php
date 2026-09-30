<?php

namespace App\Console\Commands;

use App\Services\Salesforce\SalesforceInterestOpportunityDependencySyncService;
use Illuminate\Console\Command;
use Throwable;

class SalesforceSyncInterestOpportunityDependenciesCommand extends Command
{
    protected $signature = 'salesforce:sync-interest-opportunity-dependencies
        {--reason= : Motivo auditable obligatorio, entre 10 y 500 caracteres}';

    protected $description = 'Sincroniza dependencias Opportunity read-only requeridas por Interests.';

    public function handle(SalesforceInterestOpportunityDependencySyncService $service): int
    {
        $reason = trim((string) $this->option('reason'));
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            $this->error('--reason debe contener entre 10 y 500 caracteres no whitespace.');

            return self::FAILURE;
        }

        try {
            $result = $service->sync($reason);
        } catch (Throwable $exception) {
            $this->error(str_starts_with($exception->getMessage(), 'Another Interest Opportunity dependency sync')
                ? $exception->getMessage()
                : 'No se pudo completar el snapshot de dependencias Opportunity de forma segura.');

            return self::FAILURE;
        }

        $this->line('INTEREST_OPPORTUNITY_DEPENDENCY_METRICS='.json_encode(
            $result['stats'],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
        $this->info('Snapshot local de dependencias Opportunity completado.');

        return self::SUCCESS;
    }
}
