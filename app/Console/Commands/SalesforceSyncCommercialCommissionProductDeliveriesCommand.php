<?php

namespace App\Console\Commands;

use App\Services\Reports\ContactCenterCommissions\CommercialCommissionDeliveryReconciliationService;
use App\Services\Reports\ContactCenterCommissions\Sync\CommercialCommissionProductDeliveryEvidenceSyncService;
use App\Services\Reports\ReportSyncRunService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class SalesforceSyncCommercialCommissionProductDeliveriesCommand extends Command
{
    protected $signature = 'salesforce:sync-commercial-commission-product-deliveries
        {--from= : Fecha inicial inclusiva Y-m-d}
        {--to= : Fecha final exclusiva Y-m-d}
        {--debug-soql : Imprime la query Product2 principal sin ejecutarla}';

    protected $description = 'Materializa evidencia Product2 de entregas y reconcilia contra Opportunities firmadas.';

    public function handle(
        CommercialCommissionProductDeliveryEvidenceSyncService $sync,
        CommercialCommissionDeliveryReconciliationService $reconciliation,
        ReportSyncRunService $runs,
    ): int {
        [$start, $end] = $this->period();
        if ($start === null || $end === null) {
            return self::FAILURE;
        }
        if ($this->option('debug-soql')) {
            $this->line($sync->soql($start, $end));

            return self::SUCCESS;
        }

        $run = $runs->start(CommercialCommissionProductDeliveryEvidenceSyncService::DATASET, 'salesforce', $start, $end);
        try {
            $result = $sync->sync($start, $end);
            $reconciled = $reconciliation->reconcile($start, $end);
            $runs->complete($run, now(), $result + ['reconciliation' => $reconciled]);
            $this->line('Product2 consultados: '.$result['queried']);
            $this->line('Product2 persistidos: '.$result['saved']);
            $this->line('Filas de reconciliacion: '.$reconciled['rows']);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $runs->fail($run, $exception);
            $this->error('Error sincronizando evidencia Product2 de Contact Center.');

            return self::FAILURE;
        }
    }

    /** @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable} */
    private function period(): array
    {
        if (! filled($this->option('from')) || ! filled($this->option('to'))) {
            $this->error('--from y --to son obligatorios para una sincronizacion acotada.');

            return [null, null];
        }
        $start = CarbonImmutable::parse((string) $this->option('from'))->startOfDay();
        $end = CarbonImmutable::parse((string) $this->option('to'))->startOfDay();
        if ($end->lessThanOrEqualTo($start)) {
            $this->error('--to debe ser posterior a --from.');

            return [null, null];
        }

        return [$start, $end];
    }
}
