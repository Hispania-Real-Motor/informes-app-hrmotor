<?php

namespace App\Console\Commands;

use App\Models\SalesforceOpportunity;
use App\Services\Reports\MonthlyCommercial\Sync\SalesforceMonthlyUsersSyncService;
use App\Services\Reports\ReportSyncRunService;
use App\Services\Reports\ReservationsSales\Sync\SalesforceOpportunityHistorySyncService;
use App\Services\Reports\ReservationsSales\Sync\SalesforceOpportunitySyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SalesforceSyncOpportunitiesCommand extends Command
{
    protected $signature = 'salesforce:sync-opportunities
        {--days=60 : Numero de dias hacia atras que se sincronizan}
        {--months= : Meses hacia atras que se sincronizan; tiene prioridad sobre --days}
        {--from= : Fecha inicial explicita en formato Y-m-d}
        {--to= : Fecha final exclusiva explicita en formato Y-m-d}
        {--all-history : Sincroniza el historico completo de opportunities desde 2020-01-01}
        {--modified : Descubre por LastModifiedDate cambios recientes en Opportunities antiguas}
        {--fresh : Borra solo la tabla de oportunidades Salesforce antes de sincronizar}
        {--debug-soql : Imprime la query SOQL ejecutada}';

    protected $description = 'Sincroniza Opportunities de Salesforce para el dashboard Reservas / Ventas.';

    public function handle(
        SalesforceMonthlyUsersSyncService $usersSync,
        SalesforceOpportunitySyncService $opportunitiesSync,
        SalesforceOpportunityHistorySyncService $historySync,
        ReportSyncRunService $syncRuns,
    ): int {
        $periodEnd = $this->periodEnd();
        $periodStart = $this->periodStart($periodEnd);

        if ($periodEnd->lessThanOrEqualTo($periodStart)) {
            $this->error('El rango indicado no es valido: --to debe ser posterior a --from.');

            return self::FAILURE;
        }

        $syncRun = $syncRuns->start('salesforce_opportunities', 'salesforce', $periodStart, $periodEnd);

        try {
            if ($this->option('fresh')) {
                SalesforceOpportunity::withoutGlobalScope(SalesforceOpportunity::ACTIVE_SCOPE)->delete();
                $this->warn('Tabla salesforce_opportunities vaciada.');
            }
            $this->info('Sincronizando Salesforce Reservas / Ventas.');
            $this->line('Periodo inicio: '.$periodStart->utc()->format('Y-m-d\TH:i:s\Z'));
            $this->line('Periodo fin exclusivo: '.$periodEnd->utc()->format('Y-m-d\TH:i:s\Z'));

            $users = $usersSync->sync();
            $this->line('Usuarios consultados: '.$users['queried']);
            $this->line('Usuarios sincronizados: '.$users['saved']);
            if ($this->option('debug-soql')) {
                $this->newLine();
                $this->line('SOQL Opportunities:');
                $this->line($this->option('modified')
                    ? $opportunitiesSync->modifiedSoql($periodStart, $periodEnd)
                    : $opportunitiesSync->soql($periodStart, $periodEnd));
                $this->newLine();
            }

            $result = $opportunitiesSync->sync($periodStart, $periodEnd, (bool) $this->option('modified'));
            $stats = $result['stats'];

            $this->line('Opportunities consultadas: '.$result['queried']);
            $this->line('Opportunities guardadas: '.$result['saved']);
            $this->line('Borradas confirmadas consultadas: '.$result['deletions']['queried']);
            $this->line('Borradas locales actualizadas: '.$result['deletions']['changed']);
            $this->line('Con Portal__c util: '.$stats['opportunity']);
            $this->line('Portal reconstruido desde Lead: '.$stats['lead']);
            $this->line('Portal desde Fuente_de_Origen__c: '.$stats['opportunity_source']);
            $this->line('Fallback Exposicion: '.$stats['fallback_exposicion']);
            $this->line('Fallback Web: '.$stats['fallback_web']);
            $this->line('Sin clasificar: '.$stats['unclassified']);
            $this->line('Reservas vivas: '.$stats['reservas_vivas']);
            $this->line('Caidas: '.$stats['caidas']);
            $this->line('CV firmados: '.$stats['cv_firmados']);

            $history = $historySync->sync($periodStart, $periodEnd);
            $this->line('OpportunityHistory consultados: '.$history['queried']);
            $this->line('Transiciones demostrables guardadas: '.$history['saved']);
            $this->line('Cancelaciones de reserva verificadas: '.$history['verified_cancellations']);
            $this->line('Intervalos OpportunityHistory consultados persistidos: '.$history['covered_intervals']);
            $this->line('Candidatas sin transición previa demostrable: '.$history['unverifiable']);
            $this->line('Dependencias que impiden certificar KPI: '.$history['unresolved_dependencies']);

            if ($result['queried'] === 0 && $result['deletions']['queried'] === 0) {
                $this->warn('Salesforce devolvio 0 opportunities para el periodo indicado.');
            }

            $this->invalidateDashboardCache();
            $syncRuns->complete($syncRun, now(), [
                'queried' => $result['queried'],
                'saved' => $result['saved'],
                'material_changed' => $stats['material_changed'] ?? $result['saved'],
                'deleted_queried' => $result['deletions']['queried'],
                'deleted_changed' => $result['deletions']['changed'],
                'history_saved' => $history['saved'],
                'mode' => $this->option('modified') ? 'modified' : ($this->option('all-history') ? 'all_history' : 'period'),
            ]);
            $this->info('Sincronizacion Reservas / Ventas completada.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $syncRuns->fail($syncRun, $exception);
            $this->error('Error sincronizando Opportunities.');
            $this->line($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function invalidateDashboardCache(): void
    {
        Cache::forever('reservas_ventas_dashboard_cache_version', ((int) Cache::get('reservas_ventas_dashboard_cache_version', 1)) + 1);
        Cache::forever('campaign_dashboard_cache_version', ((int) Cache::get('campaign_dashboard_cache_version', 1)) + 1);
        $this->line('Cache del dashboard Reservas / Ventas invalidada.');
        $this->line('Cache del dashboard Campanas invalidada.');
    }

    private function periodStart(CarbonImmutable $end): CarbonImmutable
    {
        if ((bool) $this->option('all-history')) {
            return CarbonImmutable::create(2020, 1, 1, 0, 0, 0, 'UTC');
        }

        $from = $this->option('from');

        if (filled($from)) {
            return CarbonImmutable::parse($from)->startOfDay();
        }

        $months = $this->option('months');

        if ($months !== null && $months !== '') {
            return $end->subMonthsNoOverflow(max((int) $months, 1))->startOfDay();
        }

        return $end->subDays(max((int) $this->option('days'), 1))->startOfDay();
    }

    private function periodEnd(): CarbonImmutable
    {
        $to = $this->option('to');

        if (filled($to)) {
            return CarbonImmutable::parse($to)->startOfDay();
        }

        return CarbonImmutable::now();
    }
}
