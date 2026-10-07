<?php

namespace App\Console\Commands;

use App\Services\Salesforce\SalesforceInterestActivitySyncService;
use Illuminate\Console\Command;
use Throwable;

class SalesforceSyncInterestActivitiesCommand extends Command
{
    protected $signature = 'salesforce:sync-interest-activities
        {--reason= : Motivo auditable obligatorio, entre 10 y 500 caracteres}';

    protected $description = 'Captura read-only Task y Event relacionados directamente con Interest.';

    public function handle(SalesforceInterestActivitySyncService $service): int
    {
        $reason = trim((string) $this->option('reason'));
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            $this->error('--reason debe contener entre 10 y 500 caracteres no whitespace.');

            return self::FAILURE;
        }

        try {
            $result = $service->sync($reason);
        } catch (Throwable $exception) {
            $this->error(str_starts_with($exception->getMessage(), 'Another Interest activity sync')
                ? $exception->getMessage()
                : 'No se pudo completar el snapshot Interest–Task/Event de forma segura.');

            return self::FAILURE;
        }

        $this->line('INTEREST_ACTIVITY_METRICS='.json_encode(
            $result['stats'],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
        $this->info('Snapshot Interest–Task/Event completado.');

        return self::SUCCESS;
    }
}
