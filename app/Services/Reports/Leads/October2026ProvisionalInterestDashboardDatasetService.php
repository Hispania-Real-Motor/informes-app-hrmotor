<?php

namespace App\Services\Reports\Leads;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Read model for the administrative October/November 2026 dashboard.
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
        private readonly OperationalInterestMonthlyReader $operationalReader,
    ) {}

    /** @return array<string, mixed> */
    public function summary(Request $request): array
    {
        $snapshot = $this->snapshot($request);
        $rows = $this->filteredRows($snapshot, $request);
        $bucket = $this->bucket($rows, $this->hasOwners($snapshot), $this->hasActivity($snapshot), $this->hasKpis($snapshot));
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
            'activity_coverage' => $this->hasActivity($snapshot) ? [
                'complete' => $snapshot['activity_coverage_complete'],
                'verified' => $snapshot['activity_verified_count'],
                'quarantined' => $snapshot['activity_quarantined_count'],
            ] : null,
            'kpi_evidence' => $this->hasKpis($snapshot) ? [
                'reference_at' => $snapshot['kpi_reference_at'],
                'commercial_users_observed_at' => $snapshot['commercial_users_observed_at'],
                'activity_complete' => $snapshot['activity_coverage_complete'],
                'management_complete_for_filter' => $bucket['gestionados'] !== null,
            ] : null,
            'comparativa' => [],
            'insights' => [
                $label.': '.$overview['coverage']['eligible_active_matching'].' Intereses activos disponibles.',
                ...(isset($overview['coverage']['observed']) ? ['Cobertura operativa: '.$overview['coverage']['observed'].' observados, '.$overview['coverage']['admitted'].' admitidos y '.$overview['coverage']['quarantined'].' en cuarentena. '.$overview['coverage']['changed_between_reads'].' cambios entre lecturas.'] : []),
                ...(isset($period['effective_cutoff']) ? ['Altas incluidas hasta '.CarbonImmutable::parse($period['effective_cutoff'], 'UTC')->setTimezone('Europe/Madrid')->format('d/m/Y H:i').'; contenido observado en la validación.'] : []),
                ...(isset($overview['coverage']['delegation_quality']) ? [sprintf(
                    'Procedencia: %d vacías, %d con valor general, %d con valor desconocido y %d reconocidas; los valores no reconocidos no se asignan por inferencia.',
                    $overview['coverage']['delegation_quality']['blank'],
                    $overview['coverage']['delegation_quality']['general'],
                    $overview['coverage']['delegation_quality']['unknown_other'],
                    $overview['coverage']['delegation_quality']['recognized'],
                )] : []),
                ...($this->hasActivity($snapshot) ? ['Actividad Task/Event observada sobre la cohorte de Intereses de '.$label.'; las actividades pueden haberse ejecutado fuera del mes. '.($snapshot['activity_coverage_complete'] ? 'Cobertura observable completa.' : 'Cobertura parcial: '.($snapshot['activity_quarantined_count'] ?? 0).' actividades en cuarentena; los recuentos son mínimos observados.').' '.$bucket['activities'].' actividades activas sobre '.$bucket['interests_with_activity'].' Intereses filtrados. No equivale a llamadas atendidas ni acredita quién gestionó cada Interés.'] : []),
                $this->hasKpis($snapshot) ? 'Clasificación de gestión preparada localmente con la regla oficial a la fecha de referencia indicada; los potenciales sin actividad acreditada permanecen indeterminados si la cobertura es parcial.' : 'Los indicadores de gestión y elegibilidad comercial requieren una proyección administrativa compatible.',
                'Las comparativas requieren fuentes mensuales compatibles.',
            ],
            'filters' => $filters,
            'availability' => [
                'commercials' => $this->hasOwners($snapshot) ? (isset($snapshot['operational_month']) ? 'Propietarios observados en la sincronización operativa; asignación histórica no certificada.' : 'Propietarios observados en F2M; la asignación histórica no está certificada.') : 'Sin datos suficientes de propietarios para este mes.',
                'activity' => $this->hasActivity($snapshot)
                    ? ($snapshot['activity_coverage_complete'] ? 'Cobertura observable completa. ' : 'Cobertura parcial: recuentos mínimos observados; actividades en cuarentena. ').'Task/Event vinculadas por WhatId a la cohorte de Intereses del mes seleccionado, con fechas de actividad no restringidas al mes; el comercial ejecutor no está acreditado.'
                    : 'Sin inventario Task/Event operativo completo para esta versión de Intereses.',
                'comparison' => 'Sin datos suficientes para comparar con otro período.',
            ],
            'coverage' => $overview['coverage'],
        ];
    }

    /** @return array{items:list<array<string,int|float|string>>} */
    public function delegationRows(Request $request): array
    {
        $snapshot = $this->snapshot($request);
        $items = $this->groupedBuckets($this->filteredRows($snapshot, $request), 'origin_delegation', 'delegacion', $this->hasOwners($snapshot), $this->hasActivity($snapshot), $this->hasKpis($snapshot));
        $this->assertCurrent($snapshot);

        return ['items' => $items];
    }

    /** @return array{items:list<array<string,int|float|string>>} */
    public function sourceRows(Request $request): array
    {
        $snapshot = $this->snapshot($request);
        $items = $this->groupedBuckets($this->filteredRows($snapshot, $request), 'source', 'portal', $this->hasOwners($snapshot), $this->hasActivity($snapshot), $this->hasKpis($snapshot));
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
            ->selectRaw("SUM(CASE WHEN interest_status = 'Potencial' THEN 1 ELSE 0 END) as potenciales");
        $commercials = $this->addKpiSelect($commercials, $this->hasKpis($snapshot))
            ->groupBy('owner_key', 'commercial_delegation', 'commercial_zone')
            ->orderByDesc('leads_totales')->get();
        $labels = $this->ownerLabels($snapshot);
        $items = $commercials->map(function (object $row) use ($labels, $snapshot): array {
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
                ...$this->kpiValues($row, $total, $this->hasKpis($snapshot)),
            ];
        })->all();
        $delegations = $this->groupedCommercialBuckets($rows, 'commercial_delegation', 'commercial_delegation', $this->hasKpis($snapshot));
        $zones = $this->groupedCommercialBuckets($rows, 'commercial_zone', 'zone', $this->hasKpis($snapshot));
        $this->assertCurrent($snapshot);

        return [
            'ok' => true,
            'zones' => $zones,
            'delegations' => $delegations,
            'commercials' => $items,
            'items' => $items,
            'message' => isset($snapshot['operational_month'])
                ? 'Propietario observado durante la sincronización; delegación según el intervalo disponible en la observación.'
                : 'Propietario F2M observado; delegación según el intervalo disponible al corte funcional del mes.',
        ];
    }

    /** @return array{overview:array<string,mixed>,projection_key:string,integrity_revision:int} */
    private function snapshot(Request $request): array
    {
        $month = MonthlyInterestProvisionalPeriod::forKey($request->query('period'));
        $snapshot = $month !== null ? $this->monthlySnapshot($month) : $this->reader->dashboardProjection();
        $requested = $request->header('X-Interest-Generation');
        if ($requested !== null && ! hash_equals($snapshot['projection_key'], (string) $requested)) {
            throw new ProvisionalInterestSourceUnavailable;
        }

        return $snapshot;
    }

    /** @param array{year:int,month:int,flag:string,label:string} $month */
    private function monthlySnapshot(array $month): array
    {
        if ($this->operationalReader->hasActiveForMonth($month['year'], $month['month'])) {
            return $this->operationalReader->latestForMonth($month['year'], $month['month']);
        }

        if ($month['year'] === 2026 && $month['month'] === 10) {
            return $this->reader->dashboardProjection();
        }

        return $this->monthlyReader->latestForMonth($month['year'], $month['month']);
    }

    /** @param array{overview:array<string,mixed>,projection_key:string,integrity_revision:int} $snapshot */
    private function filteredRows(array $snapshot, Request $request): Builder
    {
        $rows = DB::table('salesforce_interest_october_provisional_projection_rows')
            ->where('scope', $snapshot['scope'] ?? self::SCOPE)
            ->where('integrity_revision', $snapshot['integrity_revision'])
            ->where('projection_key', $snapshot['projection_key']);
        if ($this->hasActivity($snapshot)) {
            $rows->leftJoin('salesforce_interest_operational_activity_summaries as activity_summary', function ($join) use ($snapshot): void {
                $join->on('activity_summary.interest_key', '=', 'salesforce_interest_october_provisional_projection_rows.interest_key')
                    ->where('activity_summary.run_id', '=', $snapshot['activity_run_id']);
            });
        }
        if ($this->hasKpis($snapshot)) {
            $rows->leftJoin('salesforce_interest_operational_kpi_rows as operational_kpi', function ($join) use ($snapshot): void {
                $join->on('operational_kpi.interest_key', '=', 'salesforce_interest_october_provisional_projection_rows.interest_key')
                    ->where('operational_kpi.projection_id', '=', $snapshot['kpi_projection_id']);
            });
        }

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

    private function bucket(Builder $rows, bool $hasOwners, bool $hasActivity, bool $hasKpis): array
    {
        $query = (clone $rows)->selectRaw('COUNT(*) as leads_totales')
            ->selectRaw("SUM(CASE WHEN interest_status = 'Convertido' THEN 1 ELSE 0 END) as convertidos")
            ->selectRaw("SUM(CASE WHEN interest_status = 'Descartado' THEN 1 ELSE 0 END) as descartados")
            ->selectRaw("SUM(CASE WHEN interest_status = 'Potencial' THEN 1 ELSE 0 END) as potenciales")
            ->selectRaw("SUM(CASE WHEN channel = 'Llamada' THEN 1 ELSE 0 END) as llamadas")
            ->selectRaw("SUM(CASE WHEN channel = 'Formulario' THEN 1 ELSE 0 END) as formularios")
            ->selectRaw("SUM(CASE WHEN interest_status = 'Potencial' AND owner_is_technical = 1 THEN 1 ELSE 0 END) as leads_unassigned")
            ->selectRaw("SUM(CASE WHEN origin_delegation = 'Sin clasificar' THEN 1 ELSE 0 END) as unclassified");
        if ($hasActivity) {
            $query->selectRaw('SUM(COALESCE(activity_summary.activities, 0)) as activities')
                ->selectRaw('SUM(CASE WHEN activity_summary.activities > 0 THEN 1 ELSE 0 END) as interests_with_activity');
        }
        $query = $this->addKpiSelect($query, $hasKpis);
        $result = $query->first();
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
            ...$this->kpiValues($result, $total, $hasKpis),
            'leads_unassigned' => $hasOwners ? (int) ($result->leads_unassigned ?? 0) : null,
            'activities' => $hasActivity ? (int) ($result->activities ?? 0) : null,
            'interests_with_activity' => $hasActivity ? (int) ($result->interests_with_activity ?? 0) : null,
            'llamadas' => $calls,
            'llamadas_pct' => $this->percentage($calls, $total),
            'formularios' => $forms,
            'formularios_pct' => $this->percentage($forms, $total),
            'unclassified' => (int) ($result->unclassified ?? 0),
        ];
    }

    /** @return list<array<string,int|float|string>> */
    private function groupedBuckets(Builder $rows, string $column, string $output, bool $hasOwners, bool $hasActivity, bool $hasKpis): array
    {
        $query = (clone $rows)->select($column)
            ->selectRaw('COUNT(*) as leads_totales')
            ->selectRaw("SUM(CASE WHEN interest_status = 'Convertido' THEN 1 ELSE 0 END) as convertidos")
            ->selectRaw("SUM(CASE WHEN interest_status = 'Descartado' THEN 1 ELSE 0 END) as descartados")
            ->selectRaw("SUM(CASE WHEN interest_status = 'Potencial' THEN 1 ELSE 0 END) as potenciales")
            ->selectRaw("SUM(CASE WHEN channel = 'Llamada' THEN 1 ELSE 0 END) as llamadas")
            ->selectRaw("SUM(CASE WHEN channel = 'Formulario' THEN 1 ELSE 0 END) as formularios")
            ->selectRaw("SUM(CASE WHEN interest_status = 'Potencial' AND owner_is_technical = 1 THEN 1 ELSE 0 END) as leads_unassigned");
        if ($hasActivity) {
            $query->selectRaw('SUM(COALESCE(activity_summary.activities, 0)) as activities')
                ->selectRaw('SUM(CASE WHEN activity_summary.activities > 0 THEN 1 ELSE 0 END) as interests_with_activity');
        }
        $query = $this->addKpiSelect($query, $hasKpis);

        return $query->groupBy($column)
            ->orderByDesc('leads_totales')
            ->orderBy($column)
            ->get()
            ->map(function (object $row) use ($column, $output, $hasOwners, $hasActivity, $hasKpis): array {
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
                    ...$this->kpiValues($row, $total, $hasKpis),
                    'activities' => $hasActivity ? (int) ($row->activities ?? 0) : null,
                    'interests_with_activity' => $hasActivity ? (int) ($row->interests_with_activity ?? 0) : null,
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
            if (isset($snapshot['operational_month'])) {
                $this->operationalReader->assertCurrent($snapshot['operational_month'],
                    $snapshot['integrity_revision'], $snapshot['projection_key']);
                $this->operationalReader->assertActivityPointer($snapshot['operational_month'],
                    $snapshot['activity_pointer_id'] ?? null);

                return;
            }
            $this->monthlyReader->assertCurrent($snapshot['recovery_id'],
                $snapshot['integrity_revision'], $snapshot['projection_key']);

            return;
        }
        $this->reader->assertDashboardProjectionCurrent($snapshot['integrity_revision'], $snapshot['projection_key']);
    }

    private function hasActivity(array $snapshot): bool
    {
        return isset($snapshot['operational_month'], $snapshot['activity_run_id']);
    }

    private function hasKpis(array $snapshot): bool
    {
        return isset($snapshot['operational_month'], $snapshot['activity_run_id'], $snapshot['kpi_projection_id']);
    }

    private function addKpiSelect(Builder $query, bool $hasKpis): Builder
    {
        if (! $hasKpis) {
            return $query;
        }

        return $query->selectRaw('COUNT(operational_kpi.interest_key) as kpi_rows')
            ->selectRaw('SUM(CASE WHEN operational_kpi.managed = 1 THEN 1 ELSE 0 END) as managed_count')
            ->selectRaw('SUM(CASE WHEN operational_kpi.managed IS NULL THEN 1 ELSE 0 END) as managed_unknown')
            ->selectRaw('SUM(CASE WHEN operational_kpi.potential_unworked = 1 THEN 1 ELSE 0 END) as unworked_count')
            ->selectRaw('SUM(CASE WHEN operational_kpi.potential_unworked IS NULL THEN 1 ELSE 0 END) as unworked_unknown')
            ->selectRaw('SUM(CASE WHEN operational_kpi.without_eligible_commercial = 1 THEN 1 ELSE 0 END) as without_eligible_count')
            ->selectRaw('SUM(CASE WHEN operational_kpi.without_commercial_delegation = 1 THEN 1 ELSE 0 END) as without_delegation_count');
    }

    private function kpiValues(?object $row, int $total, bool $hasKpis): array
    {
        $complete = $hasKpis && (int) ($row->kpi_rows ?? -1) === $total;
        $managed = $complete && (int) ($row->managed_unknown ?? -1) === 0
            ? (int) ($row->managed_count ?? 0) : null;
        $unworked = $complete && (int) ($row->unworked_unknown ?? -1) === 0
            ? (int) ($row->unworked_count ?? 0) : null;

        return [
            'potenciales_sin_trabajar' => $unworked,
            'gestionados' => $managed,
            'gestionados_pct' => $managed === null ? null : $this->percentage($managed, $total),
            'without_eligible_commercial' => $complete ? (int) ($row->without_eligible_count ?? 0) : null,
            'without_commercial_delegation' => $complete ? (int) ($row->without_delegation_count ?? 0) : null,
        ];
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
    private function groupedCommercialBuckets(Builder $rows, string $column, string $output, bool $hasKpis): array
    {
        $groupColumns = $column === 'commercial_delegation' ? ['commercial_delegation', 'commercial_zone'] : ['commercial_zone'];

        $query = (clone $rows)->select($groupColumns)
            ->selectRaw('COUNT(*) as leads_totales')
            ->selectRaw("SUM(CASE WHEN interest_status = 'Convertido' THEN 1 ELSE 0 END) as convertidos")
            ->selectRaw("SUM(CASE WHEN interest_status = 'Descartado' THEN 1 ELSE 0 END) as descartados")
            ->selectRaw("SUM(CASE WHEN interest_status = 'Potencial' THEN 1 ELSE 0 END) as potenciales");

        return $this->addKpiSelect($query, $hasKpis)
            ->groupBy(...$groupColumns)
            ->orderByDesc('leads_totales')->get()
            ->map(function (object $row) use ($column, $output, $hasKpis): array {
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
                    ...$this->kpiValues($row, $total, $hasKpis),
                ];
            })->all();
    }

    /** @param array<int, array<string, mixed>> $rows @return list<string> */
    private function labels(array $rows, string $key): array
    {
        return collect($rows)->pluck($key)->filter(fn (mixed $value): bool => is_string($value))->values()->all();
    }
}
