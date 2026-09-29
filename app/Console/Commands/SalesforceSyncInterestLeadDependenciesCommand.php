<?php

namespace App\Console\Commands;

use App\Services\Salesforce\SalesforceInterestLeadDependencySyncService;
use Illuminate\Console\Command;
use Throwable;

class SalesforceSyncInterestLeadDependenciesCommand extends Command
{
    protected $signature = 'salesforce:sync-interest-lead-dependencies
        {--reason= : Motivo auditable obligatorio, entre 10 y 500 caracteres}';

    protected $description = 'Sincroniza en local dependencias Lead read-only requeridas por Interests.';

    public function handle(SalesforceInterestLeadDependencySyncService $service): int
    {
        $reason = trim((string) $this->option('reason'));
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            $this->error('--reason debe contener entre 10 y 500 caracteres no whitespace.');

            return self::FAILURE;
        }

        try {
            $result = $service->sync($reason);
        } catch (Throwable $exception) {
            $this->error(str_starts_with($exception->getMessage(), 'Another Interest Lead dependency sync')
                ? $exception->getMessage()
                : 'No se pudo completar el snapshot de dependencias Lead de forma segura.');

            return self::FAILURE;
        }

        $this->line('INTEREST_LEAD_DEPENDENCY_METRICS='.json_encode(
            $result['stats'],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
        $this->info('Snapshot local de dependencias Lead completado.');

        return self::SUCCESS;
    }
}
