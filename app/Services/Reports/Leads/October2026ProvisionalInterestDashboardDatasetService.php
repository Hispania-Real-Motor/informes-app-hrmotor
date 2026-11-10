<?php

namespace App\Services\Reports\Leads;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Read model for the administrative October 2026 dashboard.
 *
 * It deliberately queries the validated categorical projection only. The
 * monthly F2/F2M/F5 journals remain an administrative-validation concern and
 * are never read while serving an HTTP report.
 */
final class October2026ProvisionalInterestDashboardDatasetService
{
    private const SCOPE = 'october_2026_provisional';

    /** @var array<string, string> */
    private const FILTER_COLUMNS = [
        'lead_type' => 'interest_type',
        'lead_delegation' => 'origin_delegation',
        'group' => 'origin_group',
        'zone' => 'origin_zone',
        // Keep the original dashboard query contract: its Fuente control is
        // named "portal" even though the projection stores the source field.
        'portal' => 'source',
        'medium' => 'medium',
        'channel' => 'channel',
        'status' => 'interest_status',
    ];

    public function __construct(private readonly October2026ProvisionalInterestReader $reader) {}

    /** @return array<string, mixed> */
    public function summary(Request $request): array
    {
        $snapshot = $this->snapshot($request);
        $rows = $this->filteredRows($snapshot, $request);
        $bucket = $this->bucket($rows);
        $overview = $snapshot['overview'];

        $this->assertCurrent($snapshot);

        return [
            'ok' => true,
            'provisional' => true,
            'generation_id' => $snapshot['projection_key'],
            'dataset_cutoff_at' => null,
            'dataset_generated_at' => null,
            'dataset_timezone' => 'Europe/Madrid',
            'periodo_actual' => ['inicio' => '2026-10-01', 'fin' => '2026-10-31', 'etiqueta' => 'Octubre de 2026'],
            'periodo_comparado' => null,
            'empty' => $bucket['leads_totales'] === 0,
            'message' => $bucket['leads_totales'] === 0 ? 'No hay Intereses que coincidan con los filtros de octubre.' : null,
            'kpis' => $bucket,
            'comparativa' => [],
            'insights' => [
                'Datos de octubre: '.$overview['coverage']['eligible_active_matching'].' Intereses activos disponibles.',
                'Las comparativas y los indicadores de actividad requieren más datos.',
            ],
            'filters' => $this->filterOptions($snapshot),
            'availability' => [
                'commercials' => 'Sin datos suficientes de comerciales para octubre.',
                'activity' => 'Sin datos suficientes de actividad y gestión para octubre.',
                'comparison' => 'Sin datos suficientes para comparar con otro período.',
            ],
            'coverage' => $overview['coverage'],
        ];
    }

    /** @return array{items:list<array<string,int|float|string>>} */
    public function delegationRows(Request $request): array
    {
        $snapshot = $this->snapshot($request);
        $items = $this->groupedBuckets($this->filteredRows($snapshot, $request), 'origin_delegation', 'delegacion');
        $this->assertCurrent($snapshot);

        return ['items' => $items];
    }

    /** @return array{items:list<array<string,int|float|string>>} */
    public function sourceRows(Request $request): array
    {
        $snapshot = $this->snapshot($request);
        $items = $this->groupedBuckets($this->filteredRows($snapshot, $request), 'source', 'portal');
        $this->assertCurrent($snapshot);

        return ['items' => $items];
    }

    /** @return array{ok:bool,zones:list<array{}>,delegations:list<array{}>,commercials:list<array{}>,items:list<array{}>,message:string} */
    public function commercialRows(Request $request): array
    {
        $snapshot = $this->snapshot($request);
        $this->assertCurrent($snapshot);

        return [
            'ok' => true,
            'zones' => [],
            'delegations' => [],
            'commercials' => [],
            'items' => [],
            'message' => 'Sin datos suficientes de comerciales y delegaciones para octubre.',
        ];
    }

    /** @return array{overview:array<string,mixed>,projection_key:string,integrity_revision:int} */
    private function snapshot(Request $request): array
    {
        $snapshot = $this->reader->dashboardProjection();
        $requested = $request->header('X-Interest-Generation');
        if ($requested !== null && ! hash_equals($snapshot['projection_key'], (string) $requested)) {
            throw new ProvisionalInterestSourceUnavailable;
        }

        return $snapshot;
    }

    /** @param array{overview:array<string,mixed>,projection_key:string,integrity_revision:int} $snapshot */
    private function filteredRows(array $snapshot, Request $request): Builder
    {
        $rows = DB::table('salesforce_interest_october_provisional_projection_rows')
            ->where('scope', self::SCOPE)
            ->where('integrity_revision', $snapshot['integrity_revision'])
            ->where('projection_key', $snapshot['projection_key']);

        foreach (self::FILTER_COLUMNS as $parameter => $column) {
            $value = trim($request->string($parameter)->toString());
            if ($value !== '' && $value !== 'all') {
                $rows->where($column, $this->filterValue($parameter, $value));
            }
        }

        return $rows;
    }

    /** @param array{overview:array<string,mixed>,projection_key:string,integrity_revision:int} $snapshot */
    private function filterOptions(array $snapshot): array
    {
        $overview = $snapshot['overview'];
        $classification = $overview['classification'] ?? [];
        $functional = $overview['functional_kpis'] ?? [];

        return [
            'lead_delegations' => $this->labels($classification['by_delegation'] ?? [], 'delegation'),
            'groups' => $this->labels($classification['by_group'] ?? [], 'group'),
            'zones' => $this->labels($classification['by_zone'] ?? [], 'zone'),
            'portals' => $this->labels($functional['sources'] ?? [], 'source'),
            'sources' => $this->labels($functional['sources'] ?? [], 'source'),
            'media' => $this->labels($functional['media'] ?? [], 'medium'),
            'channels' => $this->labels($functional['channels'] ?? [], 'channel'),
            'statuses' => $this->labels($functional['statuses'] ?? [], 'status'),
            'lead_types' => $this->labels($functional['types'] ?? [], 'type'),
            'commercials' => [],
            'commercial_delegations' => [],
        ];
    }

    private function bucket(Builder $rows): array
    {
        $result = (clone $rows)->selectRaw('COUNT(*) as leads_totales')
            ->selectRaw("SUM(CASE WHEN interest_status = 'Convertido' THEN 1 ELSE 0 END) as convertidos")
            ->selectRaw("SUM(CASE WHEN interest_status = 'Descartado' THEN 1 ELSE 0 END) as descartados")
            ->selectRaw("SUM(CASE WHEN interest_status = 'Potencial' THEN 1 ELSE 0 END) as potenciales")
            ->selectRaw("SUM(CASE WHEN origin_delegation = 'Sin clasificar' THEN 1 ELSE 0 END) as unclassified")
            ->first();
        $total = (int) ($result->leads_totales ?? 0);
        $converted = (int) ($result->convertidos ?? 0);
        $discarded = (int) ($result->descartados ?? 0);

        return [
            'leads_totales' => $total,
            'convertidos' => $converted,
            'conversion_pct' => $this->percentage($converted, $total),
            'descartados' => $discarded,
            'descarte_pct' => $this->percentage($discarded, $total),
            'potenciales' => (int) ($result->potenciales ?? 0),
            'potenciales_sin_trabajar' => null,
            'leads_unassigned' => null,
            'gestionados' => null,
            'gestionados_pct' => null,
            'llamadas' => null,
            'llamadas_pct' => null,
            'formularios' => null,
            'formularios_pct' => null,
            'without_eligible_commercial' => null,
            'without_commercial_delegation' => null,
            'unclassified' => (int) ($result->unclassified ?? 0),
        ];
    }

    /** @return list<array<string,int|float|string>> */
    private function groupedBuckets(Builder $rows, string $column, string $output): array
    {
        return (clone $rows)->select($column)
            ->selectRaw('COUNT(*) as leads_totales')
            ->selectRaw("SUM(CASE WHEN interest_status = 'Convertido' THEN 1 ELSE 0 END) as convertidos")
            ->selectRaw("SUM(CASE WHEN interest_status = 'Descartado' THEN 1 ELSE 0 END) as descartados")
            ->selectRaw("SUM(CASE WHEN interest_status = 'Potencial' THEN 1 ELSE 0 END) as potenciales")
            ->groupBy($column)
            ->orderByDesc('leads_totales')
            ->orderBy($column)
            ->get()
            ->map(function (object $row) use ($column, $output): array {
                $total = (int) $row->leads_totales;
                $converted = (int) $row->convertidos;
                $discarded = (int) $row->descartados;

                return [
                    $output => (string) $row->{$column},
                    'leads_totales' => $total,
                    'convertidos' => $converted,
                    'conversion_pct' => $this->percentage($converted, $total),
                    'descartados' => $discarded,
                    'descarte_pct' => $this->percentage($discarded, $total),
                    'potenciales' => (int) $row->potenciales,
                    'potenciales_sin_trabajar' => null,
                    'gestionados' => null,
                    'gestionados_pct' => null,
                    'llamadas' => null,
                    'llamadas_pct' => null,
                    'formularios' => null,
                    'formularios_pct' => null,
                    'leads_unassigned' => null,
                    'leads_unassigned_pct' => null,
                ];
            })
            ->all();
    }

    /** @param array{overview:array<string,mixed>,projection_key:string,integrity_revision:int} $snapshot */
    private function assertCurrent(array $snapshot): void
    {
        $this->reader->assertDashboardProjectionCurrent($snapshot['integrity_revision'], $snapshot['projection_key']);
    }

    private function percentage(int $value, int $total): float
    {
        return $total === 0 ? 0.0 : round(($value / $total) * 100, 2);
    }

    private function filterValue(string $parameter, string $value): string
    {
        return $parameter === 'lead_type' && $value === 'Tasacion' ? 'Tasación' : $value;
    }

    /** @param array<int, array<string, mixed>> $rows @return list<string> */
    private function labels(array $rows, string $key): array
    {
        return collect($rows)->pluck($key)->filter(fn (mixed $value): bool => is_string($value))->values()->all();
    }
}
