<?php

namespace App\Console\Commands;

use App\Services\Salesforce\SalesforceOpportunityInterestDirectSyncService;
use Illuminate\Console\Command;
use Throwable;

class SalesforceSyncOpportunityInterestDirectCommand extends Command
{
    protected $signature = 'salesforce:sync-opportunity-interest-direct
        {--reason= : Motivo auditable obligatorio, entre 10 y 500 caracteres}';

    protected $description = 'Captura read-only la relación directa Opportunity → Interest.';

    public function handle(SalesforceOpportunityInterestDirectSyncService $service): int
    {
        $reason = trim((string) $this->option('reason'));
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            $this->error('--reason debe contener entre 10 y 500 caracteres no whitespace.');

            return self::FAILURE;
        }

        try {
            $result = $service->sync($reason);
        } catch (Throwable $exception) {
            $this->error(str_starts_with($exception->getMessage(), 'Another direct Opportunity Interest sync')
                ? $exception->getMessage()
                : 'No se pudo completar el snapshot directo Opportunity–Interest de forma segura.');

            return self::FAILURE;
        }

        $this->line('OPPORTUNITY_INTEREST_DIRECT_METRICS='.json_encode(
            $result['stats'],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
        $this->info('Snapshot directo Opportunity–Interest completado.');

        return self::SUCCESS;
    }
}
