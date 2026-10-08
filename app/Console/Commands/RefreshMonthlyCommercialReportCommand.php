<?php

namespace App\Console\Commands;

use App\Models\MonthlyCommercialReportSnapshot;
use App\Models\SalesforceInterest;
use App\Services\Reports\MonthlyCommercial\MonthlyCommercialPeriodService;
use App\Services\Reports\MonthlyCommercial\MonthlyCommercialReportBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RefreshMonthlyCommercialReportCommand extends Command
{
    protected $signature = 'reports:refresh-monthly-commercial
        {--days=30 : Numero de dias del periodo actual}
        {--store : Guarda el snapshot en base de datos}
        {--show-summary : Muestra un resumen por consola}';

    protected $description = 'Calcula el informe mensual comercial y opcionalmente guarda un snapshot.';

    public function handle(
        MonthlyCommercialReportBuilder $builder,
        MonthlyCommercialPeriodService $periodService,
    ): int {
        $days = max((int) $this->option('days'), 1);
        $now = CarbonImmutable::now();
        $periods = $periodService->periods($days, $now);

        try {
            $currentLeads = SalesforceInterest::query()
                ->where('is_deleted', false)
                ->where('functional_created_at', '>=', $periods['current_start'])
                ->where('functional_created_at', '<', $periods['current_end'])
                ->count();

            if ($currentLeads === 0) {
                $this->warn('No hay Interests activos sincronizados en salesforce_interests para el periodo actual. Ejecuta primero el pipeline read-only de Interest.');

                return self::FAILURE;
            }

            $payload = $builder->build($days, $now);
            $snapshot = null;

            if ($this->option('store')) {
                $snapshot = MonthlyCommercialReportSnapshot::create([
                    'period_start' => $periods['current_start'],
                    'period_end' => $periods['current_end'],
                    'previous_period_start' => $periods['previous_start'],
                    'previous_period_end' => $periods['previous_end'],
                    'payload_json' => $payload,
                    'generated_at' => CarbonImmutable::now(),
                ]);

                $this->info('Snapshot del informe mensual guardado.');
            }

            if ($this->option('show-summary')) {
                $summary = $payload['resumen_global'];

                $this->line('Resumen informe mensual comercial');
                $this->line('Leads en analisis: '.$summary['leads_totales']);
                $this->line('Convertidos: '.$summary['leads_convertidos']);
                $this->line('Descartados: '.$summary['leads_descartados']);
                $this->line('Potenciales: '.$summary['leads_potenciales']);
                $this->line('Potenciales sin seguimiento >3 dias: '.$summary['potenciales_sin_seguimiento_mayor_3_dias']);
            }

            if ($snapshot !== null) {
                $this->line('Snapshot id: '.$snapshot->id);
            }

            $this->invalidateDashboardCache();

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Error calculando el informe mensual comercial.');
            $this->line($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function invalidateDashboardCache(): void
    {
        Cache::forever('lead_dashboard_cache_version', ((int) Cache::get('lead_dashboard_cache_version', 1)) + 1);
        $this->line('Cache del dashboard Salesforce invalidada.');
    }
}
