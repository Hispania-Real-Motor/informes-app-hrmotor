<?php

namespace App\Console\Commands;

use App\Services\Reports\ContactCenterCommissions\Sync\CommercialCommissionAppointmentEvidenceSyncService;
use App\Services\Reports\ReportSyncRunService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class SalesforceSyncCommercialCommissionAppointmentEvidenceCommand extends Command
{
    protected $signature = 'salesforce:sync-commercial-commission-appointments
        {--from= : Fecha inicial inclusiva Y-m-d}
        {--to= : Fecha final exclusiva Y-m-d}
        {--debug-soql : Imprime la query SOQL sin ejecutarla}';

    protected $description = 'Materializa citas de Interes__c para evidencia acotada de Contact Center.';

    public function handle(CommercialCommissionAppointmentEvidenceSyncService $sync, ReportSyncRunService $runs): int
    {
        [$start, $end] = $this->period();
        if ($start === null || $end === null) {
            return self::FAILURE;
        }
        if ($this->option('debug-soql')) {
            $this->line($sync->soql($start, $end));

            return self::SUCCESS;
        }

        $run = $runs->start(CommercialCommissionAppointmentEvidenceSyncService::DATASET, 'salesforce', $start, $end);
        try {
            $result = $sync->sync($start, $end);
            $runs->complete($run, now(), $result);
            $this->line('Citas consultadas: '.$result['queried']);
            $this->line('Citas persistidas: '.$result['saved']);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $runs->fail($run, $exception);
            $this->error('Error sincronizando evidencia de citas de Contact Center.');

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
