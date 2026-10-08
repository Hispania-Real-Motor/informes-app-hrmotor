<?php

namespace App\Services\Reports\MonthlyCommercial;

use App\Services\Reports\Leads\SalesforceInterestDashboardDatasetService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;

class MonthlyCommercialReportBuilder
{
    public function __construct(
        private readonly SalesforceInterestDashboardDatasetService $dashboardDataset,
    ) {}

    public function build(int $days = 30, ?CarbonInterface $now = null): array
    {
        $now = $now ? CarbonImmutable::parse($now) : CarbonImmutable::now();
        $currentStart = $now->subDays($days);
        $previousStart = $now->subDays($days * 2);
        $previousEnd = $currentStart->subDay();

        $request = Request::create('/informes/leads/data/summary', 'GET', [
            'period' => 'custom',
            'current_start' => $currentStart->toDateString(),
            'current_end' => $now->toDateString(),
            'comparison_start' => $previousStart->toDateString(),
            'comparison_end' => $previousEnd->toDateString(),
        ]);

        $summary = $this->dashboardDataset->summary($request);
        $commercials = $this->dashboardDataset->commercialRows($request)['items'];
        $delegations = $this->dashboardDataset->delegationRows($request)['items'];
        $portals = $this->dashboardDataset->portalRows($request)['items'];
        $kpis = $summary['kpis'];

        $resumenGlobal = [
            'leads_totales' => $kpis['leads_totales'],
            'leads_convertidos' => $kpis['convertidos'],
            'leads_descartados' => $kpis['descartados'],
            'leads_potenciales' => $kpis['potenciales'],
            'potenciales_sin_seguimiento_mayor_3_dias' => $kpis['potenciales_sin_trabajar'],
            'leads_gestionados' => $kpis['gestionados'],
            'conversion_sobre_total' => $this->ratio($kpis['convertidos'], $kpis['leads_totales']),
            'descarte_sobre_total' => $this->ratio($kpis['descartados'], $kpis['leads_totales']),
            'ratio_gestionados_sobre_total' => $this->ratio($kpis['gestionados'], $kpis['leads_totales']),
            'llamadas' => $kpis['llamadas'],
            'formularios' => $kpis['formularios'],
        ];

        return [
            'fecha_analisis' => now()->toDateString(),
            'periodos_estandar' => [
                'periodo_actual' => $summary['periodo_actual'],
                'periodo_anterior' => $summary['periodo_comparado'],
            ],
            'resumen_global' => $resumenGlobal,
            'kpis_ejecutivos_exactos' => [
                'leads_en_analisis' => $kpis['leads_totales'],
                'conversion_sobre_total' => $resumenGlobal['conversion_sobre_total'],
                'descarte_sobre_total' => $resumenGlobal['descarte_sobre_total'],
                'potenciales_sin_trabajar' => $kpis['potenciales_sin_trabajar'],
                'gestionados' => $kpis['gestionados'],
                'ratio_gestionados_sobre_total' => $resumenGlobal['ratio_gestionados_sobre_total'],
            ],
            'evolucion_periodo_anterior' => [
                'periodo_actual' => $summary['periodo_actual'],
                'periodo_anterior' => $summary['periodo_comparado'],
                'items' => $summary['comparativa'],
            ],
            'bolsa_viva' => [
                'resumen' => $resumenGlobal,
                'comerciales' => $commercials,
                'delegaciones' => $delegations,
                'fuentes' => $portals,
            ],
            'conversion_descarte' => [
                'resumen' => $resumenGlobal,
                'comerciales' => $commercials,
                'fuentes' => $portals,
                'delegaciones' => $delegations,
            ],
            'tiempos_gestion' => [
                'resumen' => [],
                'comerciales' => [],
                'delegaciones' => [],
                'portales' => [],
            ],
            'calidad_dato' => [],
            'ranking_ejecutivo' => [
                'potenciales_pendientes_comerciales' => array_slice($commercials, 0, 10),
                'potenciales_pendientes_delegaciones' => array_slice($delegations, 0, 10),
                'portales_baja_conversion' => [],
            ],
            'resumen_ejecutivo' => [
                'prioridades' => array_map(fn (array $item) => [
                    'titulo' => $item['titulo'] ?? 'Resumen operativo',
                    'sugerencia' => $item['recomendacion'] ?? ($item['problema_detectado'] ?? ''),
                    'prioridad' => $item['prioridad'] ?? 'media',
                ], $summary['executive_insights'] ?? []),
            ],
        ];
    }

    private function ratio(int|float $value, int|float $total): ?float
    {
        return $total > 0 ? round($value / $total, 4) : null;
    }
}
