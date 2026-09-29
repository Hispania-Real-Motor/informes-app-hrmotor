<?php

namespace App\Console\Commands;

use App\Services\Salesforce\SalesforceInterestReconciliationService;
use Illuminate\Console\Command;
use Throwable;

class SalesforceReconcileInterestsLocalCommand extends Command
{
    protected $signature = 'salesforce:reconcile-interests-local
        {--reason= : Motivo auditable obligatorio, entre 10 y 500 caracteres}';

    protected $description = 'Materializa localmente la reconciliación histórica Lead–Interest sin acceder a Salesforce.';

    public function handle(SalesforceInterestReconciliationService $service): int
    {
        $reason = trim((string) $this->option('reason'));

        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            $this->error('--reason debe contener entre 10 y 500 caracteres no whitespace.');

            return self::FAILURE;
        }

        try {
            $stats = $service->run($reason);
        } catch (Throwable $exception) {
            $this->error(str_starts_with($exception->getMessage(), 'Ya existe otra reconciliación')
                ? $exception->getMessage()
                : 'No se pudo completar la reconciliación local Lead–Interest de forma segura.');

            return self::FAILURE;
        }

        $this->line('INTEREST_RECONCILIATION_METRICS='.json_encode(
            $stats,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
        $this->info('Reconciliación local Lead–Interest completada.');

        return self::SUCCESS;
    }
}
