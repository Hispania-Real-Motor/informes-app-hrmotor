<?php

namespace App\Services\Reports\ReservationsSales;

use App\Models\ReportSyncRun;
use App\Models\SalesforceOpportunity;
use App\Services\Reports\Leads\LeadDelegationNormalizer;
use App\Services\Reports\ReservasVentas\OpportunityPortalNormalizer;
use App\Support\ReportUserAccess;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ReservationsSalesDashboardDatasetService
{
    private const CACHE_TTL_MINUTES = 10;

    private const OPPORTUNITY_TYPES = ['Tasación', 'Venta'];

    private const DATA_QUALITY_INCIDENT = 'Incidencia de datos';

    private const EXECUTIVE_BASE_COVERAGE_MODES = ['period', 'all_history'];

    /** @var array<string, ReportSyncRun|null> */
    private array $opportunityBaseCoverageRuns = [];

    /** @var array<string, ReportSyncRun|null> */
    private array $opportunityFreshnessRuns = [];

    /** @var array<string, mixed>|null */
    private ?array $interestAttributionContext = null;

    /** @var array<string, array<string, mixed>> */
    private array $interestAttributions = [];

    public function __construct(
        private readonly LeadDelegationNormalizer $delegationNormalizer,
        private readonly OpportunityPortalNormalizer $portalNormalizer,
        private readonly OpportunityInterestAttributionService $interestAttribution,
    ) {}

    public function summary(Request $request): array
    {
        return $this->payload($request)['summary'];
    }

    public function executiveProduction(CarbonImmutable $start, CarbonImmutable $endExclusive): array
    {
        $this->beginInterestAttribution($this->opportunityFreshnessRun($start, $endExclusive)?->source_cutoff_at);
        $filters = $this->filters(Request::create('/internal/executive/reservas-ventas', 'GET', [
            'period' => 'custom',
            'date_criterion' => 'created_date',
        ]));
        $period = ['start' => $start, 'end' => $endExclusive];
        $aggregate = $this->aggregate($filters, $period);
        $this->validateInterestAttribution();

        return [
            'reservas' => $aggregate['bucket']['reservas_totales'],
            'ventas' => $aggregate['production_sales'],
            'data_quality' => $aggregate['data_quality'],
            'source_cutoff' => $this->opportunitySyncMetadata($start, $endExclusive),
        ];
    }

    public function kpiAudit(Request $request): array
    {
        $filters = $this->filters($request);
        $periods = $this->periods($filters);
        $metric = $this->resolveAuditMetric($request->string('metric')->toString());

        $this->beginInterestAttribution($this->opportunityFreshnessRun(
            $periods['current']['start'],
            $periods['current']['end'],
        )?->source_cutoff_at);

        $payload = Cache::remember(
            'reservas-ventas-dashboard-audit-v1:'.md5(json_encode([
                'filters' => $filters,
                'period' => $this->periodPayload($periods['current']),
                'metric' => $metric,
                'version' => $this->dataVersion(),
            ])),
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn () => $this->buildAuditPayload($filters, $periods['current'], $metric)
        );

        $this->validateInterestAttribution();

        return $payload;
    }

    public function cohortOpportunityIds(Request $request): array
    {
        $filters = $this->filters($request);
        $period = $this->periods($filters)['current'];

        return $this->resolvedCohort($filters, $period)
            ->map(fn (array $item): string => (string) $item['row']['opportunity_id'])
            ->filter()
            ->sort()
            ->values()
            ->all();
    }

    public function commercialRows(Request $request): array
    {
        $payload = $this->payload($request);

        return [
            'ok' => true,
            'zones' => $payload['commercial_zones'],
            'delegations' => $payload['commercial_delegations'],
            'commercials' => $payload['commercials'],
            'items' => $payload['commercials'],
        ];
    }

    public function portalRows(Request $request): array
    {
        return ['items' => $this->payload($request)['portals']];
    }

    public function payload(Request $request): array
    {
        $filters = $this->filters($request);
        $periods = $this->periods($filters);

        $this->beginInterestAttribution($this->opportunityFreshnessRun(
            $periods['current']['start'],
            $periods['current']['end'],
        )?->source_cutoff_at);

        $payload = Cache::remember(
            'reservas-ventas-dashboard-v7:'.md5(json_encode([
                'filters' => $filters,
                'periods' => $this->cachePeriodIdentity($periods),
                'version' => $this->dataVersion(),
            ])),
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn () => $this->buildPayload($filters, $periods)
        );

        $this->validateInterestAttribution();

        return $payload;
    }

    private function buildPayload(array $filters, array $periods): array
    {
        $current = $this->aggregate($filters, $periods['current']);
        $previous = $this->aggregate($filters, $periods['previous']);
        $current['bucket']['reservas_vivas_actuales_salesforce'] = $this->globalLiveReservations($filters);
        $comparison = $this->comparison($current['bucket'], $previous['bucket']);
        $currentCreatedRows = $filters['date_criterion'] === 'created_date'
            ? $current['cohort_rows']
            : $this->resolvedCreatedCohort($filters, $periods['current'])->pluck('row')->values()->all();
        $previousCreatedRows = $filters['date_criterion'] === 'created_date'
            ? $previous['cohort_rows']
            : $this->resolvedCreatedCohort($filters, $periods['previous'])->pluck('row')->values()->all();
        $currentCreatedCohort = $this->executiveCohort($currentCreatedRows);
        $previousCreatedCohort = $this->executiveCohort($previousCreatedRows);
        $productionCurrent = [
            'reservas' => $current['bucket']['reservas_totales'],
            'ventas' => $current['production_sales'],
        ];
        $productionPrevious = [
            'reservas' => $previous['bucket']['reservas_totales'],
            'ventas' => $previous['production_sales'],
        ];
        $dataQuality = $this->mergeDataQuality($current['data_quality'], $currentCreatedCohort['data_quality']);
        $filterOptions = $this->filterOptionsFromRows(array_merge($current['filter_rows'], $currentCreatedRows));

        return [
            'summary' => [
                'ok' => $current['bucket']['oportunidades_totales'] > 0
                    || $previous['bucket']['oportunidades_totales'] > 0
                    || $current['bucket']['reservas_totales'] > 0
                    || $previous['bucket']['reservas_totales'] > 0
                    || $current['production_sales'] > 0
                    || $previous['production_sales'] > 0
                    || $currentCreatedCohort['bucket']['oportunidades_totales'] > 0
                    || $previousCreatedCohort['bucket']['oportunidades_totales'] > 0
                    || $current['bucket']['reservas_vivas_actuales_salesforce'] > 0,
                'message' => (
                    $current['bucket']['oportunidades_totales'] > 0
                    || $current['bucket']['reservas_totales'] > 0
                    || $current['production_sales'] > 0
                    || $currentCreatedCohort['bucket']['oportunidades_totales'] > 0
                    || $current['bucket']['reservas_vivas_actuales_salesforce'] > 0
                ) ? null : 'No hay oportunidades sincronizadas para el periodo seleccionado.',
                'periodo_actual' => $this->periodPayload($periods['current']),
                'periodo_comparado' => $this->periodPayload($periods['previous']),
                'datos_actualizados' => $this->lastUpdated()?->toDateTimeString(),
                'dataset_cutoff_at' => $this->lastUpdated()?->toDateTimeString(),
                'dataset_generated_at' => CarbonImmutable::now()->toDateTimeString(),
                'dataset_source' => 'local_snapshot',
                'dataset_timezone' => config('app.timezone'),
                'kpis' => $current['bucket'],
                'comparativa' => $comparison,
                'produccion_periodo' => [
                    'periodo_actual' => $productionCurrent,
                    'periodo_comparado' => $productionPrevious,
                    'comparativa' => $this->simpleComparison($productionCurrent, $productionPrevious, [
                        ['key' => 'reservas', 'label' => 'Reservas'],
                        ['key' => 'ventas', 'label' => 'Ventas'],
                    ]),
                    'date_fields' => [
                        'reservas' => 'reservation_date',
                        'ventas' => 'cv_signed_date',
                    ],
                    'interval_semantics' => '[start,end)',
                ],
                'cohorte_creacion' => [
                    'date_criterion' => 'created_date',
                    'date_label' => 'Fecha de creación',
                    'periodo_actual' => $currentCreatedCohort['bucket'],
                    'periodo_comparado' => $previousCreatedCohort['bucket'],
                    'comparativa' => $this->simpleComparison(
                        $currentCreatedCohort['bucket'],
                        $previousCreatedCohort['bucket'],
                        [
                            ['key' => 'oportunidades_totales', 'label' => 'Oportunidades totales'],
                            ['key' => 'reservas_vivas', 'label' => 'Reservas vivas'],
                            ['key' => 'oportunidades_caidas', 'label' => 'Oportunidades caídas'],
                            ['key' => 'cv_firmados', 'label' => 'Contratos CV firmados'],
                        ],
                    ),
                ],
                'executive_insights' => [],
                'executive_insights_source' => 'none',
                'insights' => [],
                'filters' => $filterOptions,
                'universe_date_criterion' => $filters['date_criterion'],
                'universe_date_label' => $this->dateCriterionLabel($filters['date_criterion']),
                'data_quality' => $dataQuality,
                'interest_attribution' => $this->interestAttributionMetadata(),
            ],
            'commercial_zones' => $current['zones'],
            'commercial_delegations' => $current['delegations'],
            'commercials' => $current['commercials'],
            'portals' => $current['portals'],
        ];
    }

    private function buildAuditPayload(array $filters, array $period, string $metric): array
    {
        $resolved = match ($metric) {
            'reservas_totales' => $this->resolvedReservationEventsCohort($filters, $period),
            'cv_firmados_periodo' => $this->resolvedProductionSalesCohort($filters, $period),
            'reservas_vivas_actuales_salesforce' => $this->resolvedGlobalLiveReservationsCohort($filters),
            default => $this->resolvedCohort($filters, $period),
        };
        $productionSaleResolution = null;
        if ($metric === 'cv_firmados_periodo') {
            $productionSaleResolution = $this->resolveProductionSaleGroups($resolved->pluck('row')->values()->all());
            $auditableOpportunityIds = collect($productionSaleResolution['quality'])
                ->flatten(1)
                ->pluck('opportunity_id')
                ->filter()
                ->flip();
            $opportunities = $resolved
                ->filter(fn (array $item): bool => $auditableOpportunityIds->has($item['row']['opportunity_id']))
                ->pluck('opportunity')
                ->values()
                ->all();
        } else {
            $opportunities = $resolved
                ->filter(fn (array $item): bool => $this->matchesAuditMetric($item['row'], $metric))
                ->pluck('opportunity')
                ->values()
                ->all();
        }

        $qualityByOpportunity = [];
        $deduplicatedTotal = count($opportunities);
        if (in_array($metric, ['reservas_totales', 'reservas_vivas', 'reservas_vivas_actuales_salesforce', 'cv_firmados', 'cv_firmados_periodo'], true)) {
            $eventMetric = match ($metric) {
                'reservas_totales' => 'reservation_events',
                'cv_firmados', 'cv_firmados_periodo' => 'cv_firmados',
                default => 'reservas_vivas',
            };
            $groups = $metric === 'cv_firmados_periodo'
                ? $productionSaleResolution['quality']
                : $this->metricEventGroups(
                    array_map(fn (SalesforceOpportunity $opportunity) => $this->decorate($opportunity), $opportunities),
                    $eventMetric,
                );
            $countedGroups = $metric === 'cv_firmados_periodo'
                ? $productionSaleResolution['counted']
                : $groups;
            $deduplicatedTotal = count($countedGroups);
            foreach ($groups as $groupKey => $groupRows) {
                $opportunityIds = collect($groupRows)->pluck('opportunity_id')->filter()->sort()->values()->all();
                $countedId = isset($countedGroups[$groupKey]) ? ($opportunityIds[0] ?? null) : null;
                $conflicts = collect(['owner_id', 'owner_name', 'commercial_delegation', 'delivery_store', 'zone', 'portal'])
                    ->filter(fn (string $field) => $this->hasConflictingValues($groupRows, $field))
                    ->values()
                    ->all();
                $classificationConflict = $metric === 'cv_firmados_periodo'
                    && $this->hasSaleClassificationConflict($groupRows);
                if ($classificationConflict) {
                    $conflicts[] = 'classification';
                }
                foreach ($groupRows as $groupRow) {
                    $qualityByOpportunity[$groupRow['opportunity_id']] = [
                        'duplicate_group_key' => $groupKey,
                        'duplicate_group_size' => count($groupRows),
                        'counted_in_kpi' => $groupRow['opportunity_id'] === $countedId,
                        'quality_status' => $classificationConflict
                            ? 'classification_conflict'
                            : (count($groupRows) > 1 ? 'duplicate_event' : 'valid'),
                        'affected_opportunity_ids' => $opportunityIds,
                        'conflicting_fields' => $conflicts,
                        'breakdown_status' => $conflicts === [] && ! $classificationConflict
                            ? 'common_attribution'
                            : 'data_quality_incident',
                    ];
                }
            }
        }

        $items = array_map(function (SalesforceOpportunity $opportunity) use ($metric, $filters, $qualityByOpportunity): array {
            return array_merge(
                $this->decorateAuditOpportunity($opportunity, $metric, $filters['date_criterion']),
                $qualityByOpportunity[$opportunity->salesforce_id] ?? [
                    'duplicate_group_key' => null,
                    'duplicate_group_size' => 1,
                    'counted_in_kpi' => true,
                    'quality_status' => 'valid',
                    'affected_opportunity_ids' => [$opportunity->salesforce_id],
                    'conflicting_fields' => [],
                    'breakdown_status' => 'common_attribution',
                ],
            );
        }, $opportunities);

        usort($items, fn (array $a, array $b) => [$a['metric_date'] ?? '', $a['opportunity_id'] ?? ''] <=> [$b['metric_date'] ?? '', $b['opportunity_id'] ?? '']);

        return [
            'ok' => true,
            'metric' => $metric,
            'metric_label' => $this->auditMetricLabel($metric),
            'periodo_actual' => $this->periodPayload($period),
            'total' => $deduplicatedTotal,
            'audit_rows' => count($items),
            'items' => $items,
        ];
    }

    private function aggregate(array $filters, array $period): array
    {
        $bucket = $this->emptyBucket();
        $zones = [];
        $delegations = [];
        $commercials = [];
        $portals = [];
        $filterOptions = $this->emptyFilterOptionsAccumulator();
        $cohortRows = $this->resolvedCohort($filters, $period)
            ->map(function (array $item) use (&$filterOptions): array {
                $this->collectFilterOptions($filterOptions, $item['row']);

                return $item['row'];
            })
            ->values()
            ->all();
        $reservationEvents = $this->resolvedReservationEventsCohort($filters, $period)
            ->pluck('row')
            ->map(function (array $row) use (&$filterOptions): array {
                $this->collectFilterOptions($filterOptions, $row);

                return $row;
            })
            ->all();
        $productionSaleRows = $this->resolvedProductionSalesCohort($filters, $period)
            ->pluck('row')
            ->map(function (array $row) use (&$filterOptions): array {
                $this->collectFilterOptions($filterOptions, $row);

                return $row;
            })
            ->all();

        foreach ($cohortRows as $row) {
            $baseRow = array_merge($row, ['is_reserva_viva' => false, 'is_cv_firmado' => false]);
            $this->addToBucket($bucket, $baseRow);
            $this->addGroup($zones, $row['zone'], $row['zone'], [], $baseRow);
            $this->addGroup($delegations, $row['commercial_delegation'].'|'.$row['zone'], $row['commercial_delegation'], ['zone' => $row['zone']], $baseRow);
            $this->addGroup($commercials, (string) $row['owner_id'], (string) ($row['owner_name'] ?: $row['owner_id']), [
                'commercial_delegation' => $row['commercial_delegation'],
                'zone' => $row['zone'],
            ], $baseRow);
            $this->addGroup($portals, $row['portal'], $row['portal'], [], $baseRow);
        }

        $reservationGroups = $this->metricEventGroups($cohortRows, 'reservas_vivas');
        $reservationPeriodGroups = $this->metricEventGroups($reservationEvents, 'reservation_events');
        $reservationQualityGroups = $this->mergeMetricEventGroups(
            $this->metricEventGroups($cohortRows, 'reservation_events'),
            $reservationPeriodGroups,
        );
        $signedGroups = $this->metricEventGroups($cohortRows, 'cv_firmados');
        $productionSaleResolution = $this->resolveProductionSaleGroups($productionSaleRows);
        $bucket['reservas_totales'] = count($reservationPeriodGroups);
        $this->addDeduplicatedMetric($bucket, $zones, $delegations, $commercials, $portals, $reservationGroups, 'reservas_vivas');
        $this->addDeduplicatedMetric($bucket, $zones, $delegations, $commercials, $portals, $signedGroups, 'cv_firmados');
        $incidents = array_values(array_merge(
            $this->duplicateIncidents($reservationQualityGroups, 'reservation'),
            $this->duplicateIncidents(
                $this->mergeMetricEventGroups($signedGroups, $productionSaleResolution['quality']),
                'sale',
            ),
        ));

        return [
            'bucket' => $this->finalizeBucket($bucket),
            'production_sales' => count($productionSaleResolution['counted']),
            'zones' => $this->finalizeGroups($zones, 'zone'),
            'delegations' => $this->finalizeGroups($delegations, 'commercial_delegation'),
            'commercials' => $this->finalizeGroups($commercials, 'comercial'),
            'portals' => $this->finalizeGroups($portals, 'portal'),
            'filters' => $this->filterOptionsFromAccumulator($filterOptions),
            'cohort_rows' => $cohortRows,
            'filter_rows' => array_merge($cohortRows, $reservationEvents, $productionSaleRows),
            'data_quality' => [
                'duplicate_event_groups' => count($incidents),
                'incidents' => $incidents,
            ],
        ];
    }

    private function baseQuery(array $filters, array $period)
    {
        $query = SalesforceOpportunity::query()->select($this->cohortColumns());
        $field = $this->dateField($filters['date_criterion']);

        $query->where($field, '>=', $period['start'])
            ->where($field, '<', $period['end']);

        $this->applyOpportunityTypeFilter($query, $filters['opportunity_type']);

        if (filled($filters['access_commercial'])) {
            $query->where('owner_id', $filters['access_commercial']);
        }

        return $query;
    }

    private function resolvedCohort(array $filters, array $period): Collection
    {
        return $this->resolveQueryCohort($this->baseQuery($filters, $period), $filters);
    }

    private function resolvedReservationEventsCohort(array $filters, array $period): Collection
    {
        return $this->resolveQueryCohort($this->reservationEventsQuery($filters, $period), $filters);
    }

    private function resolvedProductionSalesCohort(array $filters, array $period): Collection
    {
        return $this->resolveQueryCohort($this->productionSalesQuery($filters, $period), $filters);
    }

    private function resolvedCreatedCohort(array $filters, array $period): Collection
    {
        return $this->resolvedCohort(array_merge($filters, ['date_criterion' => 'created_date']), $period);
    }

    private function resolvedGlobalLiveReservationsCohort(array $filters): Collection
    {
        return $this->resolveQueryCohort($this->globalLiveReservationsQuery($filters), $filters);
    }

    private function resolveQueryCohort($query, array $filters): Collection
    {
        $resolved = collect();

        $query->orderBy('id')->chunkById(1000, function (Collection $opportunities) use ($filters, $resolved): void {
            $attributions = $this->interestAttribution->resolve($opportunities, $this->interestAttributionContext ?? []);
            foreach ($opportunities as $opportunity) {
                $attribution = $attributions->get((string) $opportunity->salesforce_id, [
                    'relationship_status' => 'no_reference',
                    'inverse_interest_ids' => [],
                    'inverse_reference_count' => 0,
                ]);
                $this->interestAttributions[(string) $opportunity->salesforce_id] = $attribution;
                $opportunity->setAttribute('_rot3_interest_attribution', $attribution);
                $row = $this->decorate($opportunity, $attribution);

                if ($this->passesFilters($row, $filters)) {
                    $resolved->push([
                        'opportunity' => $opportunity,
                        'row' => $row,
                    ]);
                }
            }
        });

        return $resolved;
    }

    private function matchesAuditMetric(array $row, string $metric): bool
    {
        return match ($metric) {
            'reservas_totales' => $row['has_reservation_event'],
            'reservas_vivas', 'reservas_vivas_actuales_salesforce' => $row['is_reserva_viva'],
            'oportunidades_caidas' => $row['is_caida'],
            'cv_firmados' => $row['is_cv_firmado'],
            'cv_firmados_periodo' => $row['is_cv_signed'],
            default => true,
        };
    }

    private function cohortColumns(): array
    {
        return [
            'id',
            'salesforce_id',
            'vehicle_interest_id',
            'vehicle_plate',
            'reservation_date',
            'cv_signed_date',
            'owner_id',
            'owner_name',
            'owner_delegation',
            'delivery_store',
            'stage_name',
            'reservation',
            'cv_signed',
            'created_date',
            'close_date',
            'record_type_name',
            'account_id',
            'portal_original',
            'opportunity_source_raw',
            'opportunity_source_normalized',
        ];
    }

    private function globalLiveReservations(array $filters): int
    {
        $rows = $this->resolvedGlobalLiveReservationsCohort($filters)
            ->pluck('row')
            ->all();

        return count($this->metricEventGroups($rows, 'reservas_vivas'));
    }

    private function globalLiveReservationsQuery(array $filters)
    {
        $query = SalesforceOpportunity::query()
            ->select($this->cohortColumns())
            ->where('reservation', true)
            ->where('cv_signed', false)
            ->whereRaw("LOWER(COALESCE(stage_name, '')) <> 'cerrada perdida'");

        $this->applyOpportunityTypeFilter($query, $filters['opportunity_type']);

        if (filled($filters['access_commercial'])) {
            $query->where('owner_id', $filters['access_commercial']);
        }

        return $query;
    }

    private function reservationEventsQuery(array $filters, array $period)
    {
        $query = SalesforceOpportunity::query()
            ->select($this->cohortColumns())
            ->where('reservation', true)
            ->whereNotNull('reservation_date')
            ->where('reservation_date', '>=', $period['start'])
            ->where('reservation_date', '<', $period['end']);

        $this->applyOpportunityTypeFilter($query, $filters['opportunity_type']);

        if (filled($filters['access_commercial'])) {
            $query->where('owner_id', $filters['access_commercial']);
        }

        return $query;
    }

    private function productionSalesQuery(array $filters, array $period)
    {
        $query = SalesforceOpportunity::query()
            ->select($this->cohortColumns())
            ->whereIn('record_type_name', ['Venta', 'Cambio'])
            ->where('cv_signed', true)
            ->whereNotNull('cv_signed_date')
            ->where('cv_signed_date', '>=', $period['start'])
            ->where('cv_signed_date', '<', $period['end']);

        $this->applyOpportunityTypeFilter($query, $filters['opportunity_type']);

        if (filled($filters['access_commercial'])) {
            $query->where('owner_id', $filters['access_commercial']);
        }

        return $query;
    }

    /** @param array<string,mixed>|null $interestAttribution */
    private function decorate(SalesforceOpportunity $opportunity, ?array $interestAttribution = null): array
    {
        $delegation = $this->normalizeCommercialDelegation($opportunity->owner_delegation);
        $stage = (string) $opportunity->stage_name;
        $isClosedLost = strcasecmp($stage, 'Cerrada Perdida') === 0;
        $reservation = (bool) $opportunity->reservation;
        $cvSigned = (bool) $opportunity->cv_signed;
        $interestAttribution ??= $opportunity->getAttribute('_rot3_interest_attribution')
            ?: $this->interestAttributions[(string) $opportunity->salesforce_id]
            ?? ['relationship_status' => 'no_reference'];
        $effectivePortal = $this->interestAttribution->effectivePortal($opportunity, $interestAttribution);

        return [
            'opportunity_id' => $opportunity->salesforce_id,
            'vehicle_identity' => $this->vehicleIdentity($opportunity),
            'vehicle_id' => $opportunity->vehicle_interest_id,
            'vehicle_plate' => $opportunity->vehicle_plate,
            'reservation_date' => $this->auditDate($opportunity->reservation_date),
            'cv_signed_date' => $this->auditDate($opportunity->cv_signed_date),
            'owner_id' => $opportunity->owner_id,
            'owner_name' => $opportunity->owner_name,
            'delivery_store' => $opportunity->delivery_store,
            'commercial_delegation' => $delegation['delegation'],
            'zone' => $delegation['zone'],
            'portal' => $effectivePortal['portal'],
            'portal_resolution_source' => $effectivePortal['source'],
            'interest_attribution' => $interestAttribution,
            'stage_name' => $stage,
            'is_cv_signed' => $cvSigned,
            'is_reserva_viva' => $reservation && ! $cvSigned && ! $isClosedLost,
            'has_reservation_event' => $reservation && filled($opportunity->reservation_date),
            'is_caida' => $isClosedLost,
            'is_cv_firmado' => $cvSigned && ! $isClosedLost,
        ];
    }

    private function decorateAuditOpportunity(SalesforceOpportunity $opportunity, string $metric, string $dateCriterion): array
    {
        $row = $this->decorate($opportunity);

        return [
            'metric' => $metric,
            'metric_label' => $this->auditMetricLabel($metric),
            'metric_date' => $this->auditMetricDate($opportunity, $metric, $dateCriterion),
            'opportunity_id' => $opportunity->salesforce_id,
            'vehicle_id' => $opportunity->vehicle_interest_id,
            'vehicle_plate' => $opportunity->vehicle_plate,
            'created_date' => $this->auditDate($opportunity->created_date),
            'close_date' => $this->auditDate($opportunity->close_date),
            'reservation_date' => $this->auditDate($opportunity->reservation_date),
            'cv_signed_date' => $this->auditDate($opportunity->cv_signed_date),
            'record_type_name' => $opportunity->record_type_name,
            'stage_name' => $opportunity->stage_name,
            'owner_id' => $opportunity->owner_id,
            'owner_name' => $opportunity->owner_name,
            'delivery_store' => $opportunity->delivery_store,
            'commercial_delegation' => $row['commercial_delegation'],
            'zone' => $row['zone'],
            'account_id' => $opportunity->account_id,
            'portal_original' => $opportunity->portal_original,
            'portal_resolved' => $row['portal'],
            'portal_resolution_source' => $row['portal_resolution_source'],
            'interest_id' => data_get($row, 'interest_attribution.interest_id'),
            'relationship_status' => data_get($row, 'interest_attribution.relationship_status'),
            'direct_interest_id' => data_get($row, 'interest_attribution.direct_interest_id'),
            'inverse_interest_ids' => data_get($row, 'interest_attribution.inverse_interest_ids', []),
            'inverse_reference_count' => data_get($row, 'interest_attribution.inverse_reference_count', 0),
            'interest_source' => data_get($row, 'interest_attribution.interest_source'),
            'interest_is_deleted' => data_get($row, 'interest_attribution.interest_is_deleted'),
            'direct_run_id' => data_get($this->interestAttributionContext, 'direct_run_id'),
            'direct_cutoff_at' => data_get($this->interestAttributionContext, 'direct_cutoff_at'),
            'f2_run_id' => data_get($this->interestAttributionContext, 'interest_run_id'),
            'f2_cutoff_at' => data_get($this->interestAttributionContext, 'interest_cutoff_at'),
            'opportunity_source_raw' => $opportunity->opportunity_source_raw,
            'opportunity_source_normalized' => $opportunity->opportunity_source_normalized,
            'is_reserva_viva' => $row['is_reserva_viva'],
            'is_caida' => $row['is_caida'],
            'is_cv_firmado' => $row['is_cv_firmado'],
        ];
    }

    private function filters(Request $request): array
    {
        return [
            'period' => $request->string('period')->toString() ?: 'last_30_days',
            'date_criterion' => $request->string('date_criterion')->toString() ?: 'created_date',
            'current_start' => $request->string('current_start')->toString(),
            'current_end' => $request->string('current_end')->toString(),
            'comparison_start' => $request->string('comparison_start')->toString(),
            'comparison_end' => $request->string('comparison_end')->toString(),
            'opportunity_type' => $request->string('opportunity_type')->toString() ?: 'all',
            'commercial_delegation' => $request->string('commercial_delegation')->toString(),
            'zone' => $request->string('zone')->toString(),
            'commercial' => $request->string('commercial')->toString(),
            'access_commercial' => ReportUserAccess::salesforceUserId($request),
            'access_delegation' => ReportUserAccess::delegationName($request),
            'access_zone' => ReportUserAccess::isAreaManager($request)
                ? ReportUserAccess::areaZoneLabel($request)
                : null,
        ];
    }

    private function passesFilters(array $row, array $filters): bool
    {
        if ($filters['commercial_delegation'] && $row['commercial_delegation'] !== $filters['commercial_delegation']) {
            return false;
        }

        if ($filters['zone'] && $row['zone'] !== $filters['zone']) {
            return false;
        }

        if ($filters['commercial'] && $row['owner_id'] !== $filters['commercial']) {
            return false;
        }

        if ($filters['access_commercial'] && $row['owner_id'] !== $filters['access_commercial']) {
            return false;
        }

        if ($filters['access_delegation'] && $row['commercial_delegation'] !== $filters['access_delegation']) {
            return false;
        }

        if ($filters['access_zone'] && $row['zone'] !== $filters['access_zone']) {
            return false;
        }

        return true;
    }

    private function periods(array $filters): array
    {
        $now = CarbonImmutable::now();

        if ($filters['period'] === 'custom') {
            $currentStart = $this->parseDate($filters['current_start'], $now->subDays(30))->startOfDay();
            $currentEnd = $this->parseDate($filters['current_end'], $now)->addDay()->startOfDay();
            $comparisonStart = $this->parseDate($filters['comparison_start'], $currentStart->subDays((int) floor($currentStart->diffInDays($currentEnd->subDay())) + 1))->startOfDay();
            $comparisonEnd = $this->parseDate($filters['comparison_end'], $currentStart->subDay())->addDay()->startOfDay();

            return [
                'current' => ['start' => $currentStart, 'end' => $currentEnd],
                'previous' => ['start' => $comparisonStart, 'end' => $comparisonEnd],
            ];
        }

        if ($filters['period'] === 'current_month') {
            $currentStart = $now->startOfMonth();
            $currentEnd = $now->addDay()->startOfDay();
            $previousStart = $currentStart->subMonthNoOverflow();
            $previousEndCandidate = $previousStart->addDays((int) floor($currentStart->diffInDays($currentEnd)));
            $previousEnd = $previousEndCandidate->greaterThan($currentStart)
                ? $currentStart
                : $previousEndCandidate;

            return [
                'current' => ['start' => $currentStart, 'end' => $currentEnd],
                'previous' => ['start' => $previousStart, 'end' => $previousEnd],
            ];
        }

        if ($filters['period'] === 'previous_month') {
            $currentStart = $now->subMonthNoOverflow()->startOfMonth();
            $currentEnd = $now->startOfMonth();

            return [
                'current' => ['start' => $currentStart, 'end' => $currentEnd],
                'previous' => ['start' => $currentStart->subMonthNoOverflow()->startOfMonth(), 'end' => $currentStart],
            ];
        }

        return [
            'current' => ['start' => $now->subDays(30), 'end' => $now],
            'previous' => ['start' => $now->subDays(60), 'end' => $now->subDays(30)],
        ];
    }

    private function addToBucket(array &$bucket, array $row): void
    {
        $bucket['oportunidades_totales']++;
        $bucket['reservas_vivas'] += $row['is_reserva_viva'] ? 1 : 0;
        $bucket['oportunidades_caidas'] += $row['is_caida'] ? 1 : 0;
        $bucket['cv_firmados'] += $row['is_cv_firmado'] ? 1 : 0;
    }

    private function addGroup(array &$groups, string $key, string $label, array $extra, array $row): void
    {
        $groups[$key] ??= ['key' => $key, 'label' => $label, 'extra' => $extra, 'bucket' => $this->emptyBucket()];
        $this->addToBucket($groups[$key]['bucket'], $row);
    }

    private function metricEventGroups(array $rows, string $metric): array
    {
        $dateKey = in_array($metric, ['reservas_vivas', 'reservation_events'], true) ? 'reservation_date' : 'cv_signed_date';
        $flag = match ($metric) {
            'reservas_vivas' => 'is_reserva_viva',
            'reservation_events' => 'has_reservation_event',
            'sale_candidates' => 'is_cv_signed',
            default => 'is_cv_firmado',
        };
        $groups = [];

        foreach ($rows as $row) {
            if (! ($row[$flag] ?? false)) {
                continue;
            }

            $identity = $row['vehicle_identity'] ?: 'opportunity:'.$row['opportunity_id'];
            $date = $row[$dateKey] ?: 'undated:'.$row['opportunity_id'];
            $groups[$identity.'|'.$date][] = $row;
        }

        return $groups;
    }

    private function resolveProductionSaleGroups(array $rows): array
    {
        $qualityGroups = [];
        $countedGroups = [];

        foreach ($this->metricEventGroups($rows, 'sale_candidates') as $groupKey => $groupRows) {
            $hasValidSale = collect($groupRows)->contains(fn (array $row): bool => (bool) ($row['is_cv_firmado'] ?? false));
            if (! $hasValidSale) {
                continue;
            }

            $qualityGroups[$groupKey] = $groupRows;
            if (! $this->hasSaleClassificationConflict($groupRows)) {
                $countedGroups[$groupKey] = array_values(array_filter(
                    $groupRows,
                    fn (array $row): bool => (bool) ($row['is_cv_firmado'] ?? false),
                ));
            }
        }

        return [
            'counted' => $countedGroups,
            'quality' => $qualityGroups,
        ];
    }

    private function hasSaleClassificationConflict(array $rows): bool
    {
        $hasValidSale = collect($rows)->contains(fn (array $row): bool => (bool) ($row['is_cv_firmado'] ?? false));
        $hasDroppedSale = collect($rows)->contains(
            fn (array $row): bool => (bool) ($row['is_cv_signed'] ?? false) && (bool) ($row['is_caida'] ?? false),
        );

        return $hasValidSale && $hasDroppedSale;
    }

    private function mergeMetricEventGroups(array ...$groupSets): array
    {
        $merged = [];

        foreach ($groupSets as $groups) {
            foreach ($groups as $groupKey => $rows) {
                foreach ($rows as $row) {
                    $opportunityKey = filled($row['opportunity_id'] ?? null)
                        ? 'opportunity:'.$row['opportunity_id']
                        : 'row:'.md5(json_encode($row));
                    $merged[$groupKey][$opportunityKey] = $row;
                }
            }
        }

        return array_map('array_values', $merged);
    }

    private function addDeduplicatedMetric(
        array &$bucket,
        array &$zones,
        array &$delegations,
        array &$commercials,
        array &$portals,
        array $eventGroups,
        string $metric,
    ): void {
        foreach ($eventGroups as $eventRows) {
            $bucket[$metric]++;

            $zone = $this->commonValue($eventRows, 'zone');
            $delegation = $this->commonValue($eventRows, 'commercial_delegation');
            $ownerId = $this->commonValue($eventRows, 'owner_id');
            $ownerName = $this->commonValue($eventRows, 'owner_name');
            $portal = $this->commonValue($eventRows, 'portal');

            $zoneLabel = $zone ?? self::DATA_QUALITY_INCIDENT;
            $this->addMetricGroup($zones, $zoneLabel, $zoneLabel, [], $metric);

            $delegationLabel = $delegation ?? self::DATA_QUALITY_INCIDENT;
            $delegationZone = $zone ?? self::DATA_QUALITY_INCIDENT;
            $this->addMetricGroup($delegations, $delegationLabel.'|'.$delegationZone, $delegationLabel, ['zone' => $delegationZone], $metric);

            $commercialAmbiguous = $ownerId === null || $ownerName === null;
            $commercialKey = $commercialAmbiguous ? self::DATA_QUALITY_INCIDENT : (string) $ownerId;
            $commercialLabel = $commercialAmbiguous ? self::DATA_QUALITY_INCIDENT : ($ownerName ?: $ownerId);
            $this->addMetricGroup($commercials, $commercialKey, $commercialLabel, [
                'commercial_delegation' => $commercialAmbiguous ? self::DATA_QUALITY_INCIDENT : $delegationLabel,
                'zone' => $commercialAmbiguous ? self::DATA_QUALITY_INCIDENT : $delegationZone,
            ], $metric);

            $portalLabel = $portal ?? self::DATA_QUALITY_INCIDENT;
            $this->addMetricGroup($portals, $portalLabel, $portalLabel, [], $metric);
        }
    }

    private function addMetricGroup(array &$groups, string $key, string $label, array $extra, string $metric): void
    {
        $groups[$key] ??= ['key' => $key, 'label' => $label, 'extra' => $extra, 'bucket' => $this->emptyBucket()];
        $groups[$key]['bucket'][$metric]++;
    }

    private function commonValue(array $rows, string $key): mixed
    {
        $values = collect($rows)
            ->map(fn (array $row) => $row[$key] ?? null)
            ->map(fn ($value) => is_string($value) ? trim($value) : $value)
            ->uniqueStrict()
            ->values();

        return $values->count() === 1 ? $values->first() : null;
    }

    private function hasConflictingValues(array $rows, string $key): bool
    {
        return collect($rows)
            ->map(fn (array $row) => $row[$key] ?? null)
            ->map(fn ($value) => is_string($value) ? trim($value) : $value)
            ->uniqueStrict()
            ->count() > 1;
    }

    private function duplicateIncidents(array $eventGroups, string $eventType): array
    {
        $incidents = [];

        foreach ($eventGroups as $groupKey => $rows) {
            if (count($rows) < 2) {
                continue;
            }

            $conflictingFields = collect(['owner_id', 'owner_name', 'commercial_delegation', 'delivery_store', 'zone', 'portal'])
                ->filter(fn (string $field) => $this->hasConflictingValues($rows, $field))
                ->values()
                ->all();
            if ($eventType === 'sale' && $this->hasSaleClassificationConflict($rows)) {
                $conflictingFields[] = 'classification';
            }
            $first = $rows[0];
            $incidents[] = [
                'type' => $eventType,
                'vehicle_id' => $first['vehicle_id'],
                'vehicle_plate' => $first['vehicle_plate'],
                'event_date' => $eventType === 'reservation' ? $first['reservation_date'] : $first['cv_signed_date'],
                'opportunity_ids' => collect($rows)->pluck('opportunity_id')->filter()->values()->all(),
                'conflicting_fields' => $conflictingFields,
                'breakdown_status' => $conflictingFields === [] ? 'common_attribution' : 'data_quality_incident',
                'group_key' => $groupKey,
            ];
        }

        return $incidents;
    }

    private function finalizeGroups(array $groups, string $labelKey): array
    {
        $rows = [];

        foreach ($groups as $group) {
            $rows[] = array_merge($group['extra'], $this->finalizeBucket($group['bucket']), [
                'group_key' => $group['key'],
                $labelKey => $group['label'],
                'nombre' => $group['label'],
                'comercial' => $group['label'],
                'delegacion' => $group['label'],
                'commercial_id' => $labelKey === 'comercial' ? $group['key'] : null,
            ]);
        }

        $rows = $this->applyColumnPercentages($rows);

        usort($rows, fn (array $a, array $b) => ($b['oportunidades_totales'] ?? 0) <=> ($a['oportunidades_totales'] ?? 0));

        return array_values($rows);
    }

    private function applyColumnPercentages(array $rows): array
    {
        $totals = [
            'reservas_vivas' => array_sum(array_column($rows, 'reservas_vivas')),
            'oportunidades_caidas' => array_sum(array_column($rows, 'oportunidades_caidas')),
            'cv_firmados' => array_sum(array_column($rows, 'cv_firmados')),
        ];

        return array_map(fn (array $row) => array_merge($row, [
            'reservas_vivas_participation_pct' => $this->columnPercentage($row['reservas_vivas'], $totals['reservas_vivas']),
            'oportunidades_caidas_participation_pct' => $this->columnPercentage($row['oportunidades_caidas'], $totals['oportunidades_caidas']),
            'cv_firmados_participation_pct' => $this->columnPercentage($row['cv_firmados'], $totals['cv_firmados']),
        ]), $rows);
    }

    private function finalizeBucket(array $bucket): array
    {
        $total = $bucket['oportunidades_totales'];

        return array_merge($bucket, [
            'reservas_vivas_pct' => $this->percentage($bucket['reservas_vivas'], $total),
            'oportunidades_caidas_pct' => $this->percentage($bucket['oportunidades_caidas'], $total),
            'cv_firmados_pct' => $this->percentage($bucket['cv_firmados'], $total),
        ]);
    }

    private function emptyBucket(): array
    {
        return [
            'oportunidades_totales' => 0,
            'reservas_vivas' => 0,
            'oportunidades_caidas' => 0,
            'cv_firmados' => 0,
        ];
    }

    private function comparison(array $current, array $previous): array
    {
        return collect([
            ['key' => 'oportunidades_totales', 'label' => 'Oportunidades totales'],
            ['key' => 'reservas_vivas', 'label' => 'Reservas vivas del universo seleccionado', 'percent_key' => 'reservas_vivas_pct'],
            ['key' => 'reservas_totales', 'label' => 'Reservas totales del período'],
            ['key' => 'oportunidades_caidas', 'label' => 'Oportunidades caídas', 'percent_key' => 'oportunidades_caidas_pct'],
            ['key' => 'cv_firmados', 'label' => 'Contratos CV firmados', 'percent_key' => 'cv_firmados_pct'],
        ])->map(function (array $metric) use ($current, $previous) {
            $percentKey = $metric['percent_key'] ?? null;

            return [
                'key' => $metric['key'],
                'metrica' => $metric['label'],
                'periodo_actual' => $current[$metric['key']] ?? null,
                'periodo_actual_pct' => $percentKey ? ($current[$percentKey] ?? null) : null,
                'periodo_comparado' => $previous[$metric['key']] ?? null,
                'periodo_comparado_pct' => $percentKey ? ($previous[$percentKey] ?? null) : null,
                'diferencia' => ($current[$metric['key']] ?? 0) - ($previous[$metric['key']] ?? 0),
                'diferencia_pct_puntos' => $percentKey ? round(($current[$percentKey] ?? 0) - ($previous[$percentKey] ?? 0), 2) : null,
                'is_compact' => $percentKey !== null,
            ];
        })->all();
    }

    private function executiveCohort(array $rows): array
    {
        $bucket = $this->emptyBucket();

        foreach ($rows as $row) {
            $this->addToBucket($bucket, array_merge($row, [
                'is_reserva_viva' => false,
                'is_cv_firmado' => false,
            ]));
        }

        $reservationGroups = $this->metricEventGroups($rows, 'reservas_vivas');
        $signedGroups = $this->metricEventGroups($rows, 'cv_firmados');
        $bucket['reservas_vivas'] = count($reservationGroups);
        $bucket['cv_firmados'] = count($signedGroups);
        $incidents = array_values(array_merge(
            $this->duplicateIncidents($reservationGroups, 'reservation'),
            $this->duplicateIncidents($signedGroups, 'sale'),
        ));

        return [
            'bucket' => $this->finalizeBucket($bucket),
            'data_quality' => [
                'duplicate_event_groups' => count($incidents),
                'incidents' => $incidents,
            ],
        ];
    }

    private function simpleComparison(array $current, array $previous, array $metrics): array
    {
        return array_map(fn (array $metric): array => [
            'key' => $metric['key'],
            'metrica' => $metric['label'],
            'periodo_actual' => $current[$metric['key']] ?? null,
            'periodo_comparado' => $previous[$metric['key']] ?? null,
            'diferencia' => ($current[$metric['key']] ?? 0) - ($previous[$metric['key']] ?? 0),
        ], $metrics);
    }

    private function mergeDataQuality(array ...$qualitySets): array
    {
        $incidents = collect($qualitySets)
            ->flatMap(fn (array $quality): array => $quality['incidents'] ?? [])
            ->keyBy(fn (array $incident): string => ($incident['type'] ?? 'unknown').'|'.($incident['group_key'] ?? md5(json_encode($incident))))
            ->values()
            ->all();

        return [
            'duplicate_event_groups' => count($incidents),
            'incidents' => $incidents,
        ];
    }

    private function filterOptions(): array
    {
        return [
            'opportunity_types' => self::OPPORTUNITY_TYPES,
        ];
    }

    private function emptyFilterOptionsAccumulator(): array
    {
        return [
            'commercials' => [],
            'commercial_delegations' => [],
            'zones' => [],
        ];
    }

    private function collectFilterOptions(array &$options, array $row): void
    {
        if (filled($row['owner_id'] ?? null) || filled($row['owner_name'] ?? null)) {
            $options['commercials'][(string) ($row['owner_id'] ?? $row['owner_name'])] = [
                'id' => (string) ($row['owner_id'] ?? ''),
                'name' => (string) ($row['owner_name'] ?: $row['owner_id']),
            ];
        }

        if (filled($row['commercial_delegation'] ?? null)) {
            $options['commercial_delegations'][$row['commercial_delegation']] = true;
        }

        if (filled($row['zone'] ?? null) && $row['zone'] !== LeadDelegationNormalizer::UNCLASSIFIED) {
            $options['zones'][$row['zone']] = true;
        }
    }

    private function filterOptionsFromAccumulator(array $options): array
    {
        return [
            'opportunity_types' => self::OPPORTUNITY_TYPES,
            'commercials' => collect($options['commercials'])
                ->values()
                ->filter(fn (array $item) => $item['id'] !== '' || $item['name'] !== '')
                ->sortBy('name')
                ->values()
                ->all(),
            'commercial_delegations' => $this->delegationNormalizer->sortLabels(array_keys($options['commercial_delegations'])),
            'zones' => $this->delegationNormalizer->sortLabels(
                collect($this->delegationNormalizer->knownZones())
                    ->merge(array_keys($options['zones']))
                    ->unique()
                    ->values()
                    ->all()
            ),
        ];
    }

    private function filterOptionsFromRows(array $rows): array
    {
        $options = $this->emptyFilterOptionsAccumulator();
        foreach ($rows as $row) {
            $this->collectFilterOptions($options, $row);
        }

        return $this->filterOptionsFromAccumulator($options);
    }

    private function dateField(string $criterion): string
    {
        return match ($criterion) {
            'reservation_date' => 'reservation_date',
            'cv_signed_date' => 'cv_signed_date',
            default => 'created_date',
        };
    }

    private function dateCriterionLabel(string $criterion): string
    {
        return match ($criterion) {
            'reservation_date' => 'Fecha de reserva',
            'cv_signed_date' => 'Fecha de firma del contrato',
            default => 'Fecha de creación',
        };
    }

    private function vehicleIdentity(SalesforceOpportunity $opportunity): ?string
    {
        $vehicleId = trim((string) $opportunity->vehicle_interest_id);
        if ($vehicleId !== '') {
            return 'vehicle:'.mb_strtolower($vehicleId);
        }

        $plate = preg_replace('/[^\pL\pN]+/u', '', mb_strtoupper(trim((string) $opportunity->vehicle_plate)));

        return $plate !== '' ? 'plate:'.$plate : null;
    }

    private function resolveAuditMetric(?string $metric): string
    {
        $metric = trim((string) $metric);

        return in_array($metric, [
            'oportunidades_totales',
            'reservas_totales',
            'reservas_vivas',
            'reservas_vivas_actuales_salesforce',
            'oportunidades_caidas',
            'cv_firmados',
            'cv_firmados_periodo',
        ], true) ? $metric : 'oportunidades_totales';
    }

    private function auditMetricLabel(string $metric): string
    {
        return match ($metric) {
            'reservas_totales' => 'Reservas totales del período',
            'reservas_vivas' => 'Reservas vivas del universo seleccionado',
            'reservas_vivas_actuales_salesforce' => 'Reservas vivas actuales (todas las fechas)',
            'oportunidades_caidas' => 'Oportunidades caidas',
            'cv_firmados' => 'Contratos CV firmados',
            'cv_firmados_periodo' => 'Ventas producidas en el período',
            default => 'Oportunidades totales',
        };
    }

    private function applyOpportunityTypeFilter($query, string $opportunityType): void
    {
        if (in_array($opportunityType, ['Tasacion', 'Tasación'], true)) {
            $query->where('record_type_name', 'Tasacion');

            return;
        }

        if ($opportunityType === 'Venta') {
            $query->whereIn('record_type_name', ['Venta', 'Cambio']);
        }
    }

    private function auditMetricDate(SalesforceOpportunity $opportunity, string $metric, string $dateCriterion): ?string
    {
        $criterionField = $this->dateField($dateCriterion);

        return match ($metric) {
            'reservas_totales' => $this->auditDate($opportunity->reservation_date),
            'reservas_vivas', 'reservas_vivas_actuales_salesforce' => $this->auditDate($opportunity->reservation_date)
                ?: $this->auditDate($opportunity->{$criterionField}),
            'oportunidades_caidas' => $this->auditDate($opportunity->close_date)
                ?: $this->auditDate($opportunity->{$criterionField}),
            'cv_firmados_periodo' => $this->auditDate($opportunity->cv_signed_date),
            'cv_firmados' => $this->auditDate($opportunity->cv_signed_date)
                ?: $this->auditDate($opportunity->{$criterionField}),
            default => $this->auditDate($opportunity->{$criterionField}),
        };
    }

    private function normalizeCommercialDelegation(?string $raw): array
    {
        $normalized = $this->delegationNormalizer->normalize($raw);

        if (str_ends_with($normalized['delegation'], ' General')) {
            return [
                'delegation' => LeadDelegationNormalizer::UNCLASSIFIED,
                'zone' => LeadDelegationNormalizer::UNCLASSIFIED,
            ];
        }

        return $normalized;
    }

    private function percentage(int|float $value, int|float $total): ?float
    {
        return $total > 0 ? round(($value / $total) * 100, 2) : null;
    }

    private function columnPercentage(int|float $value, int|float $total): float
    {
        return $total > 0 ? round(($value / $total) * 100, 2) : 0.0;
    }

    private function parseDate(?string $value, CarbonImmutable $fallback): CarbonImmutable
    {
        if (blank($value)) {
            return $fallback;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private function auditDate(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function periodPayload(array $period): array
    {
        $end = CarbonImmutable::parse($period['end']);
        $displayEnd = $end->isStartOfDay() && $end->greaterThan(CarbonImmutable::parse($period['start']))
            ? $end->subDay()
            : $end;

        return [
            'inicio' => CarbonImmutable::parse($period['start'])->toDateString(),
            'fin' => $displayEnd->toDateString(),
            'technical' => [
                'start_inclusive' => CarbonImmutable::parse($period['start'])->toIso8601String(),
                'end_exclusive' => $end->toIso8601String(),
                'semantics' => '[start,end)',
                'timezone' => (string) config('app.timezone'),
            ],
        ];
    }

    private function periodPayloads(array $periods): array
    {
        return [
            'current' => $this->periodPayload($periods['current']),
            'previous' => $this->periodPayload($periods['previous']),
        ];
    }

    private function cachePeriodIdentity(array $periods): array
    {
        return collect($this->periodPayloads($periods))
            ->map(fn (array $period): array => [
                'inicio' => $period['inicio'],
                'fin' => $period['fin'],
            ])
            ->all();
    }

    private function lastUpdated(): ?CarbonImmutable
    {
        $updated = SalesforceOpportunity::query()->max('updated_at');

        return $updated ? CarbonImmutable::parse($updated) : null;
    }

    /** @return array<string, mixed> */
    private function opportunitySyncMetadata(CarbonImmutable $start, CarbonImmutable $endExclusive): array
    {
        $baseRun = $this->opportunityBaseCoverageRun($start, $endExclusive);
        $freshnessRun = $this->opportunityFreshnessRun($start, $endExclusive);

        return [
            'dataset_cutoff_at' => $freshnessRun?->source_cutoff_at?->toDateTimeString(),
            'dataset_source' => 'report_sync_runs',
            'coverage_base_status' => $baseRun !== null ? 'covered' : 'missing',
            'coverage_base_run_id' => $baseRun?->id,
            'coverage_base_mode' => $this->syncRunMode($baseRun),
            'coverage_base_period_start_at' => $baseRun?->period_start_at?->toDateTimeString(),
            'coverage_base_period_end_at' => $baseRun?->period_end_at?->toDateTimeString(),
            'coverage_base_source_cutoff_at' => $baseRun?->source_cutoff_at?->toDateTimeString(),
            'freshness_run_id' => $freshnessRun?->id,
            'freshness_mode' => $this->syncRunMode($freshnessRun),
            'freshness_status' => $freshnessRun?->status,
            'freshness_completed_at' => $freshnessRun?->completed_at?->toDateTimeString(),
            'freshness_period_start_at' => $freshnessRun?->period_start_at?->toDateTimeString(),
            'freshness_period_end_at' => $freshnessRun?->period_end_at?->toDateTimeString(),
            'local_updated_at' => $this->lastUpdated()?->toDateTimeString(),
            'timezone' => (string) ($freshnessRun?->timezone ?? $baseRun?->timezone ?? config('app.timezone')),
        ];
    }

    private function opportunityBaseCoverageRun(CarbonImmutable $start, CarbonImmutable $endExclusive): ?ReportSyncRun
    {
        $cacheKey = $this->opportunitySyncRangeKey($start, $endExclusive);

        if (array_key_exists($cacheKey, $this->opportunityBaseCoverageRuns)) {
            return $this->opportunityBaseCoverageRuns[$cacheKey];
        }

        return $this->opportunityBaseCoverageRuns[$cacheKey] = ReportSyncRun::query()
            ->where('dataset', 'salesforce_opportunities')
            ->where('source', 'salesforce')
            ->where('status', 'completed')
            ->whereIn('stats->mode', self::EXECUTIVE_BASE_COVERAGE_MODES)
            ->where('period_start_at', '<=', $start)
            ->where('period_end_at', '>=', $endExclusive)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();
    }

    private function opportunityFreshnessRun(CarbonImmutable $start, CarbonImmutable $endExclusive): ?ReportSyncRun
    {
        $cacheKey = $this->opportunitySyncRangeKey($start, $endExclusive);

        if (array_key_exists($cacheKey, $this->opportunityFreshnessRuns)) {
            return $this->opportunityFreshnessRuns[$cacheKey];
        }

        return $this->opportunityFreshnessRuns[$cacheKey] = ReportSyncRun::query()
            ->where('dataset', 'salesforce_opportunities')
            ->where('source', 'salesforce')
            ->where(function ($query) use ($start, $endExclusive): void {
                $query->where('stats->mode', 'modified')
                    ->orWhere(function ($query) use ($start, $endExclusive): void {
                        $query->where('period_start_at', '<=', $start)
                            ->where('period_end_at', '>=', $endExclusive);
                    });
            })
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();
    }

    private function opportunitySyncRangeKey(CarbonImmutable $start, CarbonImmutable $endExclusive): string
    {
        return $start->toIso8601String().'|'.$endExclusive->toIso8601String();
    }

    private function syncRunMode(?ReportSyncRun $run): ?string
    {
        $mode = $run?->stats['mode'] ?? null;

        return filled($mode) ? (string) $mode : null;
    }

    private function dataVersion(): array
    {
        return [
            'count' => SalesforceOpportunity::query()->count(),
            'max_id' => SalesforceOpportunity::query()->max('id'),
            'updated_at' => SalesforceOpportunity::query()->max('updated_at'),
            'dashboard_cache_version' => Cache::get('reservas_ventas_dashboard_cache_version', 1),
            'interest_attribution_direct_run_id' => data_get($this->interestAttributionContext, 'direct_latest_id'),
            'interest_attribution_f2_run_id' => data_get($this->interestAttributionContext, 'interest_run_id'),
        ];
    }

    private function beginInterestAttribution(?CarbonInterface $requiredOpportunityCutoff): void
    {
        $this->interestAttributions = [];
        $this->interestAttributionContext = $this->interestAttribution->capture($requiredOpportunityCutoff);
    }

    private function validateInterestAttribution(): void
    {
        if ($this->interestAttributionContext !== null) {
            $this->interestAttribution->validate($this->interestAttributionContext);
        }
    }

    /** @return array<string,mixed> */
    private function interestAttributionMetadata(): array
    {
        $counts = collect($this->interestAttributions)->countBy('relationship_status');

        return array_merge($this->interestAttributionContext ?? [], [
            'both_match' => (int) $counts->get('both_match', 0),
            'direct_only' => (int) $counts->get('direct_only', 0),
            'inverse_only' => (int) $counts->get('inverse_only', 0),
            'contradiction' => (int) $counts->get('contradiction', 0),
            'inverse_shared' => (int) $counts->get('inverse_shared', 0),
            'unresolved' => (int) $counts->get('unresolved', 0),
            'no_reference' => (int) $counts->get('no_reference', 0),
            'deleted_interests_used' => collect($this->interestAttributions)
                ->where('relationship_status', 'both_match')->where('interest_is_deleted', true)->count(),
            'interest_attributions_used' => collect($this->interestAttributions)
                ->where('relationship_status', 'both_match')->filter(fn (array $row): bool => filled($row['interest_source'] ?? null))->count(),
        ]);
    }
}
