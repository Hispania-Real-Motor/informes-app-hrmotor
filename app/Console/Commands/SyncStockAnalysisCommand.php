<?php

namespace App\Console\Commands;

use App\Services\Reports\Stock\SalesforceLogisticsSyncService;
use App\Services\Reports\Stock\SalesforceSaleSnapshotService;
use App\Services\Reports\Stock\SalesforceSignedSaleSyncService;
use App\Services\Reports\Stock\SalesforceVehicleSyncService;
use App\Services\Reports\Stock\StockAvailabilityAlertService;
use App\Services\Reports\Stock\StockDailySnapshotService;
use App\Services\Reports\Stock\StockSaleValidityService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SyncStockAnalysisCommand extends Command
{
    protected $signature = 'stock:sync-daily
        {--date= : Fecha de la fotografía en formato Y-m-d}
        {--sales-days=180 : Días de oportunidades que se revisan}
        {--logistics-days=365 : Días de logística que se revisan}
        {--skip-vehicles : No sincroniza Product2}
        {--skip-stock-snapshot : No genera la fotografía diaria de stock}
        {--skip-opportunities : No resincroniza oportunidades}
        {--skip-logistics : No sincroniza logística}
        {--skip-alerts : No evalúa alertas de disponibilidad}';

    protected $description = 'Sincroniza Product2 y genera las fotografías diarias de stock y ventas.';

    public function handle(
        SalesforceVehicleSyncService $vehicles,
        StockDailySnapshotService $stockSnapshots,
        SalesforceSignedSaleSyncService $signedSales,
        SalesforceSaleSnapshotService $saleSnapshots,
        SalesforceLogisticsSyncService $logistics,
        StockAvailabilityAlertService $availabilityAlerts,
        StockSaleValidityService $saleValidity,
    ): int {
        $lock = Cache::lock('stock-analysis-daily-sync', 7200);
        if (! $lock->get()) {
            $this->warn('Ya hay una sincronización diaria de stock en curso.');

            // An omitted sync must not publish a false successful heartbeat.
            return self::INVALID;
        }

        try {
            $snapshotDate = filled($this->option('date'))
                ? CarbonImmutable::parse((string) $this->option('date'))->startOfDay()
                : CarbonImmutable::today(config('app.timezone'));
            $now = CarbonImmutable::now();

            if (! $this->option('skip-vehicles')) {
                $this->info('Sincronizando stock actual de Product2.');
                $vehicleResult = $vehicles->sync();
                $this->line("Vehículos en stock sincronizados: {$vehicleResult['saved']}");
            }

            if (! $this->option('skip-opportunities')) {
                $days = max((int) $this->option('sales-days'), 1);
                $this->info("Sincronizando oportunidades de los últimos {$days} días.");
                $opportunityResult = $signedSales->sync($now->subDays($days)->startOfDay(), $now->addDay()->startOfDay());
                $this->line("Oportunidades sincronizadas: {$opportunityResult['saved']}");
            }

            $newSales = $saleSnapshots->captureNew();
            $this->line("Nuevas ventas congeladas: {$newSales}");
            $validity = $saleValidity->reconcile();
            $this->line(
                "Validez de ventas: {$validity['valid']} válidas, {$validity['invalid']} invalidadas, "
                ."{$validity['duplicates']} snapshots con vehículo duplicado.",
            );

            if (! $this->option('skip-logistics')) {
                $days = max((int) $this->option('logistics-days'), 1);
                $logisticsResult = $logistics->sync($now->subDays($days)->startOfDay());
                $this->line("Registros logísticos sincronizados: {$logisticsResult['saved']}");
            }

            if (! $this->option('skip-stock-snapshot')) {
                $stockRows = $stockSnapshots->capture($snapshotDate);
                $this->line("Vehículos fotografiados para {$snapshotDate->toDateString()}: {$stockRows}");
            }
            if (! $this->option('skip-alerts')) {
                $alertResult = $availabilityAlerts->evaluate();
                $this->line("Alertas de disponibilidad: {$alertResult['opened']} nuevas, {$alertResult['resolved']} resueltas, {$alertResult['errors']} errores.");
            }
            $this->info('Sincronización diaria de stock completada.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('No se pudo completar la sincronización diaria.');
            $this->line($exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
