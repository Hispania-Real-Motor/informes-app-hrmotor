<?php

namespace App\Services\Reports\Leads;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
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
        'commercial' => 'owner_key',
        'commercial_delegation' => 'commercial_delegation',
    ];

    public function __construct(
        private readonly October2026ProvisionalInterestReader $reader,
        private readonly MonthlyInterestProvisionalReader $monthlyReader,
    ) {}

    /** @return array<string, mixed> */
    public function summary(Request $request): array
    {
        $snapshot = $this->snapshot($request);
        $rows = $this->filteredRows($snapshot, $request);
        $bucket = $this->bucket($rows, $this->hasOwners($snapshot));
        $overview = $snapshot['overview'];
        $filters = $this->filterOptions($snapshot);
        $period = $overview['period'];
        $start = CarbonImmutable::parse($period['start_utc'], 'UTC')->setTimezone('Europe/Madrid');
        $end = CarbonImmutable::parse($period['end_utc'], 'UTC')->setTimezone('Europe/Madrid')->subDay();
        $label = $period['label'];

        $this->assertCurrent($snapshot);

        return [
            'ok' => true,
            'provisional' => true,
            'generation_id' => $snapshot['projection_key'],
            'dataset_cutoff_at' => null,
            'dataset_generated_at' => $overview['validated_at'] ?? null,
            'dataset_timezone' => 'Europe/Madrid',
            'periodo_actual' => ['inicio' => $start->toDateString(), 'fin' => $end->toDateString(), 'etiqueta' => $label],
            'periodo_comparado' => null,
            'empty' => $bucket['leads_totales'] === 0,
            'message' => $bucket['leads_totales'] === 0 ? 'No hay Intereses que coincidan con los filtros del mes.' : null,
            'kpis' => $bucket,
            'comparativa' => [],
            'insights' => [
                $label.': '.$overview['coverage']['eligible_active_matching'].' Intereses activos disponibles.',
                ...(isset($period['effective_cutoff']) ? ['Altas incluidas hasta '.CarbonImmutable::parse($period['effective_cutoff'], 'UTC')->setTimezone('Europe/Madrid')->format('d/m/Y H:i').'; contenido observado en la validación.'] : []),
                'Las comparativas y los indicadores de actividad requieren más datos.',
            ],
            'filters' => $filters,
            'availability' => [
                'commercials' => $this->hasOwners($snapshot) ? 'Propietarios observados en F2M; la asignación histórica no está certificada.' : 'Sin datos suficientes de propietarios para este mes.',
                'activity' => 'Sin datos suficientes de actividad y gestión para este mes.',
                'comparison' => 'Sin datos suficientes para comparar con otro período.',
            ],
            'coverage' => $overview['coverage'],
        ];
    }

    /** @return array{items:list<array<string,int|float|string>>} */
    public function delegationRows(Request $request): array
    {
        $snapshot = $this->snapshot($request);
        $items = $this->groupedBuckets($this->filteredRows($snapshot, $request), 'origin_delegation', 'delegacion', $this->hasOwners($snapshot));
        $this->assertCurrent($snapshot);

        return ['items' => $items];
    }

    /** @return array{items:list<array<string,int|float|string>>} */
    public function sourceRows(Request $request): array
    {
        $snapshot = $this->snapshot($request);
        $items = $this->groupedBuckets($this->filteredRows($snapshot, $request), 'source', 'portal', $this->hasOwners($snapshot));
        $this->assertCurrent($snapshot);

        return ['items' => $items];
    }

    /** @return array{ok:bool,zones:list<array{}>,delegations:list<array{}>,commercials:list<array{}>,items:list<array{}>,message:string} */
    public function commercialRows(Request $request): array
    {
        $snapshot = $this->snapshot($request);
        if (! $this->hasOwners($snapshot)) {
            $this->assertCurrent($snapshot);

            return ['ok' => true, 'zones' => [], 'delegations' => [], 'commercials' => [], 'items' => [], 'message' => 'Sin datos suficientes de propietarios para este mes.'];
        }
        $rows = $this->filteredRows($snapshot, $request);
        $commercials = (clone $rows)->select(['owner_key', 'commercial_delegation', 'commercial_zone'])
            ->selectRaw('COUNT(*) as leads_totales')
            ->selectRaw("SUM(CASE WHEN interest_status = 'Convertido' THEN 1 ELSE 0 END) as convertidos")
            ->selectRaw("SUM(CASE WHEN interest_status = 'Descartado' THEN 1 ELSE 0 END) as descartados")
            ->selectRaw("SUM(CASE WHEN interest_status = 'Potencial' THEN 1 ELSE 0 END) as potenciales")
            ->groupBy('owner_key', 'commercial_delegation', 'commercial_zone')
            ->orderByDesc('leads_totales')->get();
        $labels = $this->ownerLabels($snapshot);
        $items = $commercials->map(function (object $row) use ($labels): array {
            $total = (int) $row->leads_totales;
            $converted = (int) $row->convertidos;
            $discarded = (int) $row->descartados;
            $key = (string) $row->owner_key;

            return [
                'group_key' => $key,
                'comercial' => ($labels[$key] ?? 'Propietario sin nombre').' · '.substr($key, -6),
                'commercial_delegation' => (string) $row->commercial_delegation,
                'zone' => (string) $row->commercial_zone,
                'leads_totales' => $total,
                'convertidos' => $converted,
                'conversion_pct' => $this->percentage($converted, $total),
                'descartados' => $discarded,
                'descarte_pct' => $this->percentage($discarded, $total),
                'potenciales' => (int) $row->potenciales,
                'potenciales_sin_trabajar' => null,
                'gestionados' => null,
                'gestionados_pct' => null,
            ];
        })->all();
        $delegations = $this->groupedCommercialBuckets($rows, 'commercial_delegation', 'commercial_delegation');
        $zones = $this->groupedCommercialBuckets($rows, 'commercial_zone', 'zone');
        $this->assertCurrent($snapshot);

        return [
            'ok' => true,
            'zones' => $zones,
            'delegations' => $delegations,
            'commercials' => $items,
            'items' => $items,
            'message' => 'Propietario F2M observado; delegación según el intervalo disponible al corte funcional del mes.',
        ];
    }

    /** @return array{overview:array<string,mixed>,projection_key:string,integrity_revision:int} */
    private function snapshot(Request $request): array
    {
        $month = MonthlyInterestProvisionalPeriod::forKey($request->query('period'));
        $snapshot = $month !== null
            ? $this->monthlyReader->latestForMonth($month['year'], $month['month'])
            : $this->reader->dashboardProjection();
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
            ->where('scope', $snapshot['scope'] ?? self::SCOPE)
            ->where('integrity_revision', $snapshot['integrity_revision'])
            ->where('projection_key', $snapshot['projection_key']);

        foreach (self::FILTER_COLUMNS as $parameter => $column) {
            $value = trim($request->string($parameter)->toString());
            if ($value !== '' && $value !== 'all') {
                if (in_array($parameter, ['commercial', 'commercial_delegation'], true) && ! $this->hasOwners($snapshot)) {
                    throw new ProvisionalInterestSourceUnavailable;
                }
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
            'commercials' => $this->hasOwners($snapshot) ? $this->ownerFilterOptions($snapshot) : [],
            'commercial_delegations' => $this->hasOwners($snapshot) ? $this->projectionLabels($snapshot, 'commercial_delegation') : [],
        ];
    }

    private function bucket(Builder $rows, bool $hasOwners): array
    {
        $result = (clone $rows)->selectRaw('COUNT(*) as leads_totales')
            ->selectRaw("SUM(CASE WHEN interest_status = 'Convertido' THEN 1 ELSE 0 END) as convertidos")
            ->selectRaw("SUM(CASE WHEN interest_status = 'Descartado' THEN 1 ELSE 0 END) as descartados")
            ->selectRaw("SUM(CASE WHEN interest_status = 'Potencial' THEN 1 ELSE 0 END) as potenciales")
            ->selectRaw("SUM(CASE WHEN channel = 'Llamada' THEN 1 ELSE 0 END) as llamadas")
            ->selectRaw("SUM(CASE WHEN channel = 'Formulario' THEN 1 ELSE 0 END) as formularios")
            ->selectRaw("SUM(CASE WHEN interest_status = 'Potencial' AND owner_is_technical = 1 THEN 1 ELSE 0 END) as leads_unassigned")
            ->selectRaw("SUM(CASE WHEN origin_delegation = 'Sin clasificar' THEN 1 ELSE 0 END) as unclassified")
            ->first();
        $total = (int) ($result->leads_totales ?? 0);
        $converted = (int) ($result->convertidos ?? 0);
        $discarded = (int) ($result->descartados ?? 0);
        $calls = (int) ($result->llamadas ?? 0);
        $forms = (int) ($result->formularios ?? 0);

        return [
            'leads_totales' => $total,
            'convertidos' => $converted,
            'conversion_pct' => $this->percentage($converted, $total),
            'descartados' => $discarded,
            'descarte_pct' => $this->percentage($discarded, $total),
            'potenciales' => (int) ($result->potenciales ?? 0),
            'potenciales_sin_trabajar' => null,
            'leads_unassigned' => $hasOwners ? (int) ($result->leads_unassigned ?? 0) : null,
            'gestionados' => null,
            'gestionados_pct' => null,
            'llamadas' => $calls,
            'llamadas_pct' => $this->percentage($calls, $total),
            'formularios' => $forms,
            'formularios_pct' => $this->percentage($forms, $total),
            'without_eligible_commercial' => null,
            'without_commercial_delegation' => null,
            'unclassified' => (int) ($result->unclassified ?? 0),
        ];
    }

    /** @return list<array<string,int|float|string>> */
    private function groupedBuckets(Builder $rows, string $column, string $output, bool $hasOwners): array
    {
        return (clone $rows)->select($column)
            ->selectRaw('COUNT(*) as leads_totales')
            ->selectRaw("SUM(CASE WHEN interest_status = 'Convertido' THEN 1 ELSE 0 END) as convertidos")
            ->selectRaw("SUM(CASE WHEN interest_status = 'Descartado' THEN 1 ELSE 0 END) as descartados")
            ->selectRaw("SUM(CASE WHEN interest_status = 'Potencial' THEN 1 ELSE 0 END) as potenciales")
            ->selectRaw("SUM(CASE WHEN channel = 'Llamada' THEN 1 ELSE 0 END) as llamadas")
            ->selectRaw("SUM(CASE WHEN channel = 'Formulario' THEN 1 ELSE 0 END) as formularios")
            ->selectRaw("SUM(CASE WHEN interest_status = 'Potencial' AND owner_is_technical = 1 THEN 1 ELSE 0 END) as leads_unassigned")
            ->groupBy($column)
            ->orderByDesc('leads_totales')
            ->orderBy($column)
            ->get()
            ->map(function (object $row) use ($column, $output, $hasOwners): array {
                $total = (int) $row->leads_totales;
                $converted = (int) $row->convertidos;
                $discarded = (int) $row->descartados;
                $calls = (int) $row->llamadas;
                $forms = (int) $row->formularios;

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
                    'llamadas' => $calls,
                    'llamadas_pct' => $this->percentage($calls, $total),
                    'formularios' => $forms,
                    'formularios_pct' => $this->percentage($forms, $total),
                    'leads_unassigned' => $hasOwners ? (int) $row->leads_unassigned : null,
                    'leads_unassigned_pct' => $hasOwners ? $this->percentage((int) $row->leads_unassigned, $total) : null,
                ];
            })
            ->all();
    }

    /** @param array{overview:array<string,mixed>,projection_key:string,integrity_revision:int} $snapshot */
    private function assertCurrent(array $snapshot): void
    {
        if (isset($snapshot['scope']) && $snapshot['scope'] !== self::SCOPE) {
            $this->monthlyReader->assertCurrent($snapshot['recovery_id'],
                $snapshot['integrity_revision'], $snapshot['projection_key']);

            return;
        }
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

    private function hasOwners(array $snapshot): bool
    {
        return ($snapshot['overview']['projection_contract'] ?? null) === 'observed_owners_v1';
    }

    /** @return array<string, string> */
    private function ownerLabels(array $snapshot): array
    {
        try {
            return DB::table('salesforce_interest_october_provisional_owners')
                ->where('projection_key', $snapshot['projection_key'])
                ->get(['owner_key', 'name_ciphertext'])
                ->mapWithKeys(fn (object $row): array => [
                    $row->owner_key => $row->name_ciphertext === null ? 'Sin propietario observado' : Crypt::decryptString($row->name_ciphertext),
                ])->all();
        } catch (\Throwable) {
            throw new ProvisionalInterestSourceUnavailable;
        }
    }

    /** @return list<array{id:string,name:string}> */
    private function ownerFilterOptions(array $snapshot): array
    {
        $options = [];
        foreach ($this->ownerLabels($snapshot) as $key => $name) {
            $options[] = ['id' => $key, 'name' => $name.' · '.substr($key, -6)];
        }
        usort($options, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $options;
    }

    /** @return list<string> */
    private function projectionLabels(array $snapshot, string $column): array
    {
        return DB::table('salesforce_interest_october_provisional_projection_rows')
            ->where('scope', $snapshot['scope'] ?? self::SCOPE)
            ->where('integrity_revision', $snapshot['integrity_revision'])
            ->where('projection_key', $snapshot['projection_key'])
            ->distinct()->orderBy($column)->pluck($column)->all();
    }

    /** @return list<array<string, int|float|string|null>> */
    private function groupedCommercialBuckets(Builder $rows, string $column, string $output): array
    {
        $groupColumns = $column === 'commercial_delegation' ? ['commercial_delegation', 'commercial_zone'] : ['commercial_zone'];

        return (clone $rows)->select($groupColumns)
            ->selectRaw('COUNT(*) as leads_totales')
            ->selectRaw("SUM(CASE WHEN interest_status = 'Convertido' THEN 1 ELSE 0 END) as convertidos")
            ->selectRaw("SUM(CASE WHEN interest_status = 'Descartado' THEN 1 ELSE 0 END) as descartados")
            ->selectRaw("SUM(CASE WHEN interest_status = 'Potencial' THEN 1 ELSE 0 END) as potenciales")
            ->groupBy(...$groupColumns)
            ->orderByDesc('leads_totales')->get()
            ->map(function (object $row) use ($column, $output): array {
                $total = (int) $row->leads_totales;
                $converted = (int) $row->convertidos;
                $discarded = (int) $row->descartados;

                return [
                    $output => (string) $row->{$column},
                    'zone' => (string) $row->commercial_zone,
                    'leads_totales' => $total,
                    'convertidos' => $converted,
                    'conversion_pct' => $this->percentage($converted, $total),
                    'descartados' => $discarded,
                    'descarte_pct' => $this->percentage($discarded, $total),
                    'potenciales' => (int) $row->potenciales,
                    'potenciales_sin_trabajar' => null,
                    'gestionados' => null,
                    'gestionados_pct' => null,
                ];
            })->all();
    }

    /** @param array<int, array<string, mixed>> $rows @return list<string> */
    private function labels(array $rows, string $key): array
    {
        return collect($rows)->pluck($key)->filter(fn (mixed $value): bool => is_string($value))->values()->all();
    }
}
