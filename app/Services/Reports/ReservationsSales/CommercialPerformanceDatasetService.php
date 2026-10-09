<?php

namespace App\Services\Reports\ReservationsSales;

use App\Models\CommercialPerformanceMonthlyTarget;
use App\Models\ReportSyncRun;
use App\Models\SalesforceInterest;
use App\Models\SalesforceOpportunity;
use App\Models\SalesforceOpportunityHistorySyncInterval;
use App\Models\SalesforceOpportunityStageTransition;
use App\Services\Reports\Leads\LeadRecordTypeNormalizer;
use App\Services\Salesforce\SalesforceInterestSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CommercialPerformanceDatasetService
{
    private const CACHE_TTL_MINUTES = 10;

    private const DATASET_TIMEZONE = 'Europe/Madrid';

    public function __construct(
        private readonly LeadRecordTypeNormalizer $recordTypeNormalizer,
        private readonly CommercialPerformanceMonthlyRosterService $monthlyRoster,
    ) {}

    public function payload(array $filters): array
    {
        $filters = $this->normalizeFilters($filters);

        return $this->present($this->basePayload($filters['month']), $filters);
    }

    public function updateTarget(string $month, int $target, ?int $reportUserId): CommercialPerformanceMonthlyTarget
    {
        $monthDate = CarbonImmutable::createFromFormat('!Y-m', $month, self::DATASET_TIMEZONE)->startOfMonth();

        $result = CommercialPerformanceMonthlyTarget::query()->updateOrCreate(
            ['month' => $monthDate->toDateString()],
            [
                'reservations_target' => $target,
                'is_explicit' => true,
                'updated_by_report_user_id' => $reportUserId,
            ],
        );

        $this->incrementCacheVersion();

        return $result;
    }

    private function basePayload(string $month): array
    {
        $interestContext = $this->interestSourceContext();
        $version = $this->cacheVersion();
        $key = 'reservas-ventas-commercial-performance-base-v5:'.hash('sha256', json_encode([
            'month' => $month,
            'version' => $version,
            'interest_source' => $interestContext,
        ]));
        $cached = Cache::get($key);
        if (is_array($cached)) {
            $this->validateInterestSourceContext($interestContext);

            return $cached;
        }

        $base = Cache::lock($key.':lock', 30)->block(10, function () use ($key, $month, $interestContext): array {
            $cached = Cache::get($key);

            if (is_array($cached)) {
                return $cached;
            }

            $base = $this->buildBase($month, $interestContext);
            $this->validateInterestSourceContext($interestContext);
            Cache::put($key, $base, now()->addMinutes(self::CACHE_TTL_MINUTES));

            return $base;
        });

        $this->validateInterestSourceContext($interestContext);

        return $base;
    }

    /** @param array<string,mixed> $interestContext */
    private function buildBase(string $month, array $interestContext): array
    {
        $selected = CarbonImmutable::createFromFormat('!Y-m', $month, self::DATASET_TIMEZONE)->startOfMonth();
        $months = collect(range(3, 0))->map(fn (int $offset): CarbonImmutable => $selected->subMonthsNoOverflow($offset));
        $start = $months->first()->startOfMonth();
        $end = $selected->addMonth()->startOfMonth();
        $monthKeys = $months->map->format('Y-m')->all();
        $targets = $this->targets($months);
        $rosterContext = $this->monthlyRoster->context($months);
        $historyCoverage = $this->historyCoverage($months);
        $buckets = array_fill_keys($monthKeys, []);
        $qualityByMonth = array_fill_keys($monthKeys, [
            'unresolved_attribution_events' => 0,
            'duplicate_conflict_groups' => 0,
            'uncertified_historical_events' => 0,
            'margin_conflict_groups' => 0,
            'invalid_cancellation_chronology' => 0,
            'organisation_changes_within_month' => 0,
            'bootstrap_approved_assignments' => 0,
            'observed_assignments' => 0,
        ]);
        foreach ($monthKeys as $monthKey) {
            $qualityByMonth[$monthKey]['organisation_changes_within_month'] = collect($rosterContext['assessments'][$monthKey] ?? [])
                ->where('reason', 'organisation_change_within_month')
                ->count();
            $qualityByMonth[$monthKey]['bootstrap_approved_assignments'] = collect($rosterContext['assignments'][$monthKey] ?? [])
                ->where('delegation_status', 'bootstrap_approved')
                ->count();
            $qualityByMonth[$monthKey]['observed_assignments'] = collect($rosterContext['assignments'][$monthKey] ?? [])
                ->where('delegation_status', 'observed')
                ->count();
        }

        $this->seedCertifiedRoster($buckets, $monthKeys, $rosterContext);
        $this->aggregateInterests($buckets, $qualityByMonth, $start, $end, $rosterContext);
        $this->aggregateOpportunities($buckets, $qualityByMonth, $start, $end, $rosterContext);
        $this->aggregateCancellations($buckets, $qualityByMonth, $start, $end, $rosterContext);

        $rowsByMonth = [];
        foreach ($monthKeys as $monthKey) {
            $rowsByMonth[$monthKey] = collect($buckets[$monthKey])
                ->map(fn (array $bucket): array => $this->finalizeRow(
                    $bucket,
                    $targets[$monthKey]['value'],
                    $historyCoverage[$monthKey]['status'] === 'covered',
                ))
                ->values()
                ->all();
        }

        $delegationHistory = [
            'delegation_history_certified_from' => DB::table('commercial_delegation_snapshots')->min('observed_from'),
            'delegation_history_evaluable_from' => DB::table('commercial_delegation_snapshots')
                ->whereIn('source', [
                    CommercialDelegationSnapshotService::SOURCE_OBSERVED,
                    CommercialDelegationSnapshotService::SOURCE_BUSINESS_BOOTSTRAP,
                ])->min('observed_from'),
            'delegation_history_observed_from' => DB::table('commercial_delegation_snapshots')
                ->where('source', CommercialDelegationSnapshotService::SOURCE_OBSERVED)
                ->min('observed_from'),
            'delegation_history_bootstrap_from' => DB::table('commercial_delegation_snapshots')
                ->where('source', CommercialDelegationSnapshotService::SOURCE_BUSINESS_BOOTSTRAP)
                ->min('observed_from'),
        ];
        $generatedAt = now()->toIso8601String();

        return compact('monthKeys', 'targets', 'historyCoverage', 'qualityByMonth', 'rowsByMonth', 'delegationHistory', 'generatedAt', 'interestContext');
    }

    private function present(array $base, array $filters): array
    {
        $monthKeys = $base['monthKeys'];
        $targets = $base['targets'];
        $historyCoverage = $base['historyCoverage'];
        $rowsByMonth = $base['rowsByMonth'];
        $delegationHistory = $base['delegationHistory'];
        $interestContext = $base['interestContext'];
        $generatedAt = $base['generatedAt'];
        $currentRows = $this->applyAccessScope(collect($rowsByMonth[$filters['month']] ?? []), $filters);
        $quality = $this->scopeDataQuality(
            $base['qualityByMonth'][$filters['month']] ?? [],
            $currentRows,
            $filters,
        );
        $coverageByMonth = filled($filters['access_zone'])
            ? collect($historyCoverage)
                ->map(fn (array $coverage): array => array_replace($coverage, ['unresolved_dependencies' => null]))
                ->all()
            : $historyCoverage;
        $filterOptions = $this->filterOptions($currentRows, $filters);
        $rankUniverse = $this->applyOrganisationFilters($currentRows, $filters);
        $ranked = $this->applyTeamComparisonsAndRanking($rankUniverse);
        $commercialRows = $ranked
            ->filter(fn (array $row): bool => filled($row['commercial_id']) && $row['has_real_activity'])
            ->values();
        $displayRows = filled($filters['commercial'])
            ? $commercialRows->where('commercial_id', $filters['commercial'])->values()
            : $commercialRows;
        $summaryRows = filled($filters['commercial']) ? $displayRows : $rankUniverse;
        $universe = $this->universeMetadata($rankUniverse, $targets[$filters['month']]['value']);
        $dataIncident = $this->dataIncidentSummary($currentRows, $historyCoverage[$filters['month']]['status'] === 'covered');

        $evolution = collect($monthKeys)->map(function (string $monthKey) use ($rowsByMonth, $filters, $historyCoverage): array {
            $rows = $this->applyOrganisationFilters(
                $this->applyAccessScope(collect($rowsByMonth[$monthKey] ?? []), $filters),
                $filters,
            );
            if (filled($filters['commercial'])) {
                $rows = $rows->where('commercial_id', $filters['commercial'])->values();
            }

            return $this->evolutionRow(
                $monthKey,
                $rows,
                $historyCoverage[$monthKey]['status'] === 'covered',
            );
        })->all();

        return [
            'ok' => true,
            'month' => $filters['month'],
            'objective' => [
                'reservations_target' => $targets[$filters['month']]['value'],
                'is_explicit' => (bool) $targets[$filters['month']]['is_explicit'],
                'default' => CommercialPerformanceMonthlyTarget::DEFAULT_RESERVATIONS_TARGET,
            ],
            'items' => $displayRows->all(),
            'universe' => $universe,
            'data_incident' => $dataIncident,
            'evolution' => $evolution,
            'filters' => $filterOptions,
            'summary' => $this->evolutionRow(
                $filters['month'],
                $summaryRows,
                $historyCoverage[$filters['month']]['status'] === 'covered',
            ),
            'data_quality' => $quality + [
                'cancellations_available' => $historyCoverage[$filters['month']]['status'] === 'covered',
                'cancellation_coverage_status' => $historyCoverage[$filters['month']]['status'],
                'cancellation_source_cutoff_at' => $historyCoverage[$filters['month']]['source_cutoff_at'],
                'cancellation_certified_until' => $historyCoverage[$filters['month']]['certified_until'],
                'cancellation_unresolved_dependencies' => filled($filters['access_zone'])
                    ? null
                    : $historyCoverage[$filters['month']]['unresolved_dependencies'],
                'cancellation_coverage_by_month' => $coverageByMonth,
                'cancellation_source' => 'OpportunityHistory',
                ...$delegationHistory,
                'delegation_history_limitation' => 'Desde 2026-04-01 se admite el bootstrap aprobado por negocio cuando la primera asignación fiable no tiene evidencias contradictorias; se distingue de la observación Salesforce.',
                'interest_sync_run_id' => $interestContext['id'],
                'interest_sync_cutoff_at' => $interestContext['cutoff'],
                'interest_owner_semantics' => 'current_interest_owner_at_last_sync',
            ],
            'semantics' => [
                'activity_monthly' => true,
                'cohort' => false,
                'ratios_may_exceed_100' => true,
                'leads_alias' => 'interests',
                'lead_to_reservation_pct_alias' => 'interest_to_reservation_pct',
                'cancellation_date_field' => 'salesforce_opportunity_stage_transitions.transitioned_at',
            ],
            'dataset_source' => 'local_snapshot',
            'dataset_generated_at' => $generatedAt,
            'dataset_timezone' => self::DATASET_TIMEZONE,
        ];
    }

    private function aggregateInterests(
        array &$buckets,
        array &$quality,
        CarbonImmutable $start,
        CarbonImmutable $end,
        array $rosterContext,
    ): void {
        SalesforceInterest::query()
            ->where('is_deleted', false)
            ->where('functional_created_at', '>=', $start->utc())
            ->where('functional_created_at', '<', $end->utc())
            ->select([
                'id', 'salesforce_id', 'type', 'owner_salesforce_id', 'owner_name', 'functional_created_at',
            ])
            ->orderBy('id')
            ->chunkById(1000, function ($interests) use (&$buckets, &$quality, $rosterContext): void {
                foreach ($interests as $interest) {
                    $type = $this->recordTypeNormalizer->normalize($interest->type);
                    if (! in_array($type, $this->recordTypeNormalizer->ventaFilterTypes(), true)) {
                        continue;
                    }

                    $userId = filled($interest->owner_salesforce_id) ? trim((string) $interest->owner_salesforce_id) : null;
                    $attribution = $this->attribution(
                        $userId,
                        $interest->owner_name ?? data_get($rosterContext['users']->get($userId), 'name'),
                        $interest->functional_created_at,
                        $rosterContext,
                        $quality,
                    );
                    $this->increment($buckets, $this->monthKey($interest->functional_created_at), $attribution, 'leads');
                }
            });
    }

    private function aggregateOpportunities(
        array &$buckets,
        array &$quality,
        CarbonImmutable $start,
        CarbonImmutable $end,
        array $rosterContext,
    ): void {
        $reservationGroups = [];
        $activeReservationGroups = [];
        $validReservationGroups = [];
        $droppedReservationGroups = [];
        $saleGroups = [];
        $droppedSaleGroups = [];
        $signedSaleReferenceGroups = [];
        $saleClassificationGroups = [];

        SalesforceOpportunity::query()
            ->whereIn('record_type_name', ['Venta', 'Cambio'])
            ->where(function ($query) use ($start, $end): void {
                $query->where(function ($created) use ($start, $end): void {
                    $created->where('created_date', '>=', $start->utc())
                        ->where('created_date', '<', $end->utc());
                })->orWhere(function ($reservation) use ($start, $end): void {
                    $reservation->where('reservation_date', '>=', $start->toDateString())
                        ->where('reservation_date', '<', $end->toDateString());
                })->orWhere(function ($sale) use ($start, $end): void {
                    $sale->where('cv_signed_date', '>=', $start->toDateString())
                        ->where('cv_signed_date', '<', $end->toDateString());
                });
            })
            ->select([
                'id', 'salesforce_id', 'created_date', 'reservation', 'reservation_date',
                'cv_signed', 'cv_signed_date', 'stage_name', 'owner_id', 'owner_name',
                'vehicle_interest_id', 'vehicle_plate', 'informe_rentabilidad',
            ])
            ->orderBy('id')
            ->chunkById(1000, function ($opportunities) use (
                &$buckets, &$quality, &$reservationGroups, &$activeReservationGroups, &$validReservationGroups,
                &$droppedReservationGroups, &$saleGroups, &$droppedSaleGroups, &$signedSaleReferenceGroups, &$saleClassificationGroups,
                $start, $end, $rosterContext
            ): void {
                foreach ($opportunities as $opportunity) {
                    if ($this->inRange($opportunity->created_date, $start, $end)) {
                        $attribution = $this->attribution(
                            $opportunity->owner_id,
                            $opportunity->owner_name,
                            $opportunity->created_date,
                            $rosterContext,
                            $quality,
                        );
                        $this->increment($buckets, $this->monthKey($opportunity->created_date), $attribution, 'opportunities');
                    }

                    if ($opportunity->reservation && $this->inRange($opportunity->reservation_date, $start, $end)) {
                        $event = $this->opportunityEvent($opportunity, $opportunity->reservation_date, $rosterContext, $quality);
                        $key = $this->eventKey($opportunity, $opportunity->reservation_date);
                        $reservationGroups[$key][] = $event;
                        if (! $this->isLost($opportunity)) {
                            $validReservationGroups[$key][] = $event;
                        }
                        if (! $opportunity->cv_signed && ! $this->isLost($opportunity)) {
                            $activeReservationGroups[$key][] = $event;
                        }
                        if (! $opportunity->cv_signed && $this->isLost($opportunity)) {
                            $droppedReservationGroups[$key][] = $event;
                        }
                        if ($opportunity->cv_signed && filled($opportunity->cv_signed_date)) {
                            $saleClassificationGroups[$this->saleClassificationKey($opportunity)]['reservation_keys'][$key] = true;
                        }
                    }

                    if ($opportunity->cv_signed && filled($opportunity->cv_signed_date)) {
                        $classificationKey = $this->saleClassificationKey($opportunity);
                        $saleClassificationGroups[$classificationKey][$this->isLost($opportunity) ? 'has_dropped_sale' : 'has_valid_sale'] = true;
                    }

                    if ($opportunity->cv_signed
                        && ! $this->isLost($opportunity)
                        && $this->inRange($opportunity->cv_signed_date, $start, $end)) {
                        $event = $this->opportunityEvent($opportunity, $opportunity->cv_signed_date, $rosterContext, $quality);
                        $event['margin'] = $opportunity->informe_rentabilidad === null
                            ? null
                            : (float) $opportunity->informe_rentabilidad;
                        $saleKey = $this->eventKey($opportunity, $opportunity->cv_signed_date);
                        $saleGroups[$saleKey][] = $event;
                        $saleClassificationGroups[$this->saleClassificationKey($opportunity)]['sale_keys'][$saleKey] = true;
                    }

                    if (! $opportunity->cv_signed) {
                        continue;
                    }

                    $saleReferenceDate = $this->saleReferenceDate($opportunity);
                    if ($saleReferenceDate === null || ! $this->inRange($saleReferenceDate, $start, $end)) {
                        continue;
                    }

                    $event = $this->opportunityEvent($opportunity, $saleReferenceDate, $rosterContext, $quality, false);
                    $key = $this->eventKey($opportunity, $saleReferenceDate);
                    $signedSaleReferenceGroups[$key][] = $event;
                    if (filled($opportunity->cv_signed_date)) {
                        $saleClassificationGroups[$this->saleClassificationKey($opportunity)]['references'][$key] = true;
                    }
                    if ($this->isLost($opportunity)) {
                        $droppedSaleGroups[$key][] = $this->opportunityEvent($opportunity, $saleReferenceDate, $rosterContext, $quality);
                        if (filled($opportunity->cv_signed_date)) {
                            $saleClassificationGroups[$this->saleClassificationKey($opportunity)]['dropped_sale_keys'][$key] = true;
                        }
                    }
                }
            });

        foreach (array_keys($reservationGroups) as $key) {
            $hasActive = isset($activeReservationGroups[$key]);
            $hasDropped = isset($droppedReservationGroups[$key]);
            $hasValid = isset($validReservationGroups[$key]);

            if (($hasActive && $hasDropped) || ($hasValid && $hasDropped)) {
                unset($activeReservationGroups[$key], $droppedReservationGroups[$key], $validReservationGroups[$key]);
                $reservationGroups[$key] = $this->markClassificationConflict($reservationGroups[$key]);
            }
        }

        foreach ($saleClassificationGroups as $classificationGroup) {
            if (! isset($classificationGroup['has_valid_sale'], $classificationGroup['has_dropped_sale'])) {
                continue;
            }

            foreach (array_keys($classificationGroup['sale_keys'] ?? []) as $key) {
                unset($saleGroups[$key]);
            }
            foreach (array_keys($classificationGroup['dropped_sale_keys'] ?? []) as $key) {
                unset($droppedSaleGroups[$key]);
            }
            $hasDemonstratedReservation = isset($classificationGroup['reservation_keys']);
            foreach (array_keys($classificationGroup['reservation_keys'] ?? []) as $key) {
                unset($activeReservationGroups[$key], $droppedReservationGroups[$key], $validReservationGroups[$key]);
                $reservationGroups[$key] = $this->markClassificationConflict($reservationGroups[$key]);
            }
            foreach (array_keys($classificationGroup['references'] ?? []) as $key) {
                $signedSaleReferenceGroups[$key] = $this->markClassificationConflict(
                    $signedSaleReferenceGroups[$key],
                    ! $hasDemonstratedReservation,
                );
            }
        }

        $this->applyDeduplicatedGroups($buckets, $quality, $reservationGroups, 'reservations_total');
        $this->applyDeduplicatedGroups($buckets, $quality, $activeReservationGroups, 'reservations_active');
        $this->applyDeduplicatedGroups($buckets, $quality, $validReservationGroups, 'reservations_valid_for_objective', false);
        $this->applyDeduplicatedGroups($buckets, $quality, $droppedReservationGroups, 'reservations_dropped', false);
        $this->applyDeduplicatedGroups($buckets, $quality, $saleGroups, 'sales');
        $this->applyDeduplicatedGroups($buckets, $quality, $droppedSaleGroups, 'sales_dropped');
        $this->applyDeduplicatedGroups($buckets, $quality, $signedSaleReferenceGroups, 'sales_signed_reference', false);
    }

    private function aggregateCancellations(
        array &$buckets,
        array &$quality,
        CarbonImmutable $start,
        CarbonImmutable $end,
        array $rosterContext,
    ): void {
        $transitions = SalesforceOpportunityStageTransition::query()
            ->where('transitioned_at', '>=', $start->utc())
            ->where('transitioned_at', '<', $end->utc())
            ->where('is_reservation_cancellation', true)
            ->whereRaw("LOWER(new_stage) = 'cerrada perdida'")
            ->whereNotIn('opportunity_salesforce_id', SalesforceOpportunity::withoutGlobalScope(SalesforceOpportunity::ACTIVE_SCOPE)
                ->where('is_deleted', true)
                ->where('deletion_detection_source', SalesforceOpportunity::DELETION_SOURCE_QUERY_ALL)
                ->select('salesforce_id'))
            ->get();
        $opportunities = SalesforceOpportunity::query()
            ->whereIn('salesforce_id', $transitions->pluck('opportunity_salesforce_id')->unique())
            ->get(['salesforce_id', 'vehicle_interest_id', 'vehicle_plate'])
            ->keyBy('salesforce_id');
        $groups = [];

        foreach ($transitions as $transition) {
            $opportunity = $opportunities->get($transition->opportunity_salesforce_id);
            $identity = $this->vehicleIdentity($opportunity)
                ?: 'opportunity:'.$transition->opportunity_salesforce_id;
            $key = $identity.'|'.$transition->transitioned_at->format('Y-m-d');
            $groups[$key][] = [
                'month' => $this->monthKey($transition->transitioned_at),
                'attribution' => $this->attribution(
                    $transition->owner_id,
                    $transition->owner_name,
                    $transition->transitioned_at,
                    $rosterContext,
                    $quality,
                ),
            ];
        }

        SalesforceOpportunityStageTransition::query()
            ->where('transitioned_at', '>=', $start->utc())
            ->where('transitioned_at', '<', $end->utc())
            ->where('quality_status', 'reservation_after_transition')
            ->whereNotIn('opportunity_salesforce_id', SalesforceOpportunity::withoutGlobalScope(SalesforceOpportunity::ACTIVE_SCOPE)
                ->where('is_deleted', true)
                ->where('deletion_detection_source', SalesforceOpportunity::DELETION_SOURCE_QUERY_ALL)
                ->select('salesforce_id'))
            ->get(['transitioned_at'])
            ->each(fn (SalesforceOpportunityStageTransition $transition) => $this->incrementQuality(
                $quality,
                $this->monthKey($transition->transitioned_at),
                'invalid_cancellation_chronology',
            ));

        $this->applyDeduplicatedGroups($buckets, $quality, $groups, 'cancellations');
    }

    private function opportunityEvent(
        SalesforceOpportunity $opportunity,
        mixed $date,
        array $rosterContext,
        array &$quality,
        bool $countQuality = true,
    ): array {
        $ignoredQuality = [];
        if ($countQuality) {
            $eventQuality = &$quality;
        } else {
            $eventQuality = &$ignoredQuality;
        }

        return [
            'month' => $this->monthKey($date),
            'attribution' => $this->attribution(
                $opportunity->owner_id,
                $opportunity->owner_name,
                $date,
                $rosterContext,
                $eventQuality,
            ),
        ];
    }

    private function applyDeduplicatedGroups(
        array &$buckets,
        array &$quality,
        array $groups,
        string $metric,
        bool $reportAttributionConflict = true,
    ): void {
        foreach ($groups as $events) {
            $month = (string) data_get($events, '0.month');
            $attributions = collect($events)->pluck('attribution');
            $signatures = $attributions->map(fn (array $item): string => $this->attributionSignature($item))->unique();
            $classificationConflict = collect($events)->contains(fn (array $event): bool => (bool) ($event['classification_conflict'] ?? false));
            $reportClassificationConflict = collect($events)->contains(fn (array $event): bool => (bool) ($event['report_classification_conflict'] ?? false));
            $attribution = ! $classificationConflict && $signatures->count() === 1
                ? $attributions->first()
                : $this->monthlyRoster->incidentAttribution();

            if ((($reportAttributionConflict && $signatures->count() !== 1) || $reportClassificationConflict) && $metric !== 'reservations_active') {
                $this->incrementQuality($quality, $month, 'duplicate_conflict_groups');
            }

            $this->increment($buckets, $month, $attribution, $metric);

            if ($metric !== 'sales') {
                continue;
            }

            $margins = collect($events)->pluck('margin')->filter(fn ($value): bool => $value !== null)->uniqueStrict()->values();
            if ($margins->count() === 1) {
                $this->increment($buckets, $month, $attribution, 'margin_total', (float) $margins->first());
                $this->increment($buckets, $month, $attribution, 'sales_with_margin');
            } else {
                $this->increment($buckets, $month, $attribution, 'sales_without_margin');
                if ($margins->count() > 1) {
                    $this->incrementQuality($quality, $month, 'margin_conflict_groups');
                }
            }
        }
    }

    private function markClassificationConflict(array $events, bool $reportConflict = true): array
    {
        return array_map(function (array $event) use ($reportConflict): array {
            $event['classification_conflict'] = true;
            $event['report_classification_conflict'] = $reportConflict;

            return $event;
        }, $events);
    }

    private function attribution(
        mixed $userId,
        mixed $userName,
        mixed $eventAt,
        array $rosterContext,
        array &$quality,
        int $eventCount = 1,
    ): array {
        $userId = trim((string) $userId);
        $month = $this->monthKey($eventAt);
        if ($userId === '') {
            $this->incrementQuality($quality, $month, 'unresolved_attribution_events', $eventCount);

            return $this->monthlyRoster->incidentAttribution();
        }

        if (! $rosterContext['users']->has($userId)) {
            $this->incrementQuality($quality, $month, 'unresolved_attribution_events', $eventCount);

            return $this->monthlyRoster->incidentAttribution();
        }

        $attribution = $this->monthlyRoster->attribution($rosterContext, $userId, $userName, $eventAt);
        if (! $attribution['delegation_certified']) {
            $this->incrementQuality($quality, $month, 'uncertified_historical_events', $eventCount);
        }

        return $attribution;
    }

    private function incrementQuality(array &$qualityByMonth, string $month, string $metric, int $amount = 1): void
    {
        if (! isset($qualityByMonth[$month][$metric])) {
            return;
        }

        $qualityByMonth[$month][$metric] += $amount;
    }

    private function increment(array &$buckets, string $month, array $attribution, string $metric, int|float $amount = 1): void
    {
        if (! array_key_exists($month, $buckets)) {
            return;
        }

        $key = $this->attributionSignature($attribution);
        $buckets[$month][$key] ??= $this->emptyBucket($attribution);
        $buckets[$month][$key][$metric] += $amount;
    }

    private function seedCertifiedRoster(array &$buckets, array $monthKeys, array $rosterContext): void
    {
        foreach ($monthKeys as $month) {
            foreach ($this->monthlyRoster->rosterForMonth($rosterContext, $month) as $attribution) {
                $buckets[$month][$this->attributionSignature($attribution)] ??= $this->emptyBucket($attribution);
            }
        }
    }

    private function emptyBucket(array $attribution): array
    {
        return $attribution + [
            'leads' => 0,
            'opportunities' => 0,
            'reservations_total' => 0,
            'reservations_active' => 0,
            'reservations_valid_for_objective' => 0,
            'reservations_dropped' => 0,
            'sales' => 0,
            'sales_dropped' => 0,
            'sales_signed_reference' => 0,
            'cancellations' => 0,
            'margin_total' => 0.0,
            'sales_with_margin' => 0,
            'sales_without_margin' => 0,
        ];
    }

    private function finalizeRow(array $bucket, int $target, bool $cancellationsAvailable): array
    {
        $hasRealActivity = $this->hasRealActivity($bucket);
        $isDataIncident = blank($bucket['commercial_id']);
        $isEvaluable = ! $isDataIncident && $hasRealActivity && $bucket['delegation_certified'];
        $objective = $isEvaluable ? $target : null;
        $fulfillment = $isEvaluable ? $this->percentage($bucket['reservations_valid_for_objective'], $target) : null;
        $evaluationStatus = match (true) {
            $isDataIncident => 'data_incident',
            $isEvaluable => 'evaluable',
            ! $hasRealActivity => 'excluded_no_activity',
            default => 'not_evaluable',
        };
        $evaluationReason = match ($evaluationStatus) {
            'excluded_no_activity' => 'no_real_activity',
            'not_evaluable' => $bucket['delegation_issue'] ?? 'not_certifiable',
            'data_incident' => $bucket['delegation_issue'] ?? 'missing_commercial_identity',
            default => null,
        };

        return array_merge($bucket, [
            'cancellations' => $cancellationsAvailable ? $bucket['cancellations'] : null,
            'has_real_activity' => $hasRealActivity,
            'evaluable' => $isEvaluable,
            'evaluation_status' => $evaluationStatus,
            'evaluation_reason' => $evaluationReason,
            'ranking_eligible' => $isEvaluable,
            'objective' => $objective,
            'fulfillment_pct' => $fulfillment,
            'traffic_light' => $fulfillment === null ? null : $this->trafficLight((float) $fulfillment),
            'lead_to_reservation_pct' => $this->percentage($bucket['reservations_total'], $bucket['leads']),
            'opportunity_to_reservation_pct' => $this->percentage($bucket['reservations_total'], $bucket['opportunities']),
            'reservation_to_sale_pct' => $this->percentage($bucket['sales'], $bucket['reservations_total']),
            'reservation_drop_pct' => $this->percentage($bucket['reservations_dropped'], $bucket['reservations_total']),
            'sale_drop_pct' => $this->percentage($bucket['sales_dropped'], $bucket['sales_signed_reference']),
            'cancellation_pct' => $cancellationsAvailable
                ? $this->percentage($bucket['cancellations'], $bucket['reservations_total'])
                : null,
            'average_margin_per_sale' => $bucket['sales_with_margin'] > 0
                ? round($bucket['margin_total'] / $bucket['sales_with_margin'], 2)
                : null,
            'margin_coverage_pct' => $this->percentage($bucket['sales_with_margin'], $bucket['sales']),
            'ranking' => null,
        ]);
    }

    private function applyTeamComparisonsAndRanking(Collection $rows): Collection
    {
        $teamPopulation = $rows
            ->filter(fn (array $row): bool => $row['evaluable'])
            ->values();
        $delegationStats = $teamPopulation
            ->groupBy('delegation')
            ->map(function (Collection $team): array {
                $salesWithMargin = (int) $team->sum('sales_with_margin');
                $marginTotal = (float) $team->sum('margin_total');

                return [
                    'average_reservations' => round((float) $team->average('reservations_total'), 2),
                    'lead_to_reservation_pct' => $this->percentage($team->sum('reservations_total'), $team->sum('leads')),
                    'opportunity_to_reservation_pct' => $this->percentage($team->sum('reservations_total'), $team->sum('opportunities')),
                    'reservation_to_sale_pct' => $this->percentage($team->sum('sales'), $team->sum('reservations_total')),
                    'cancellation_pct' => $team->contains(fn (array $row): bool => $row['cancellations'] === null)
                        ? null
                        : $this->percentage($team->sum('cancellations'), $team->sum('reservations_total')),
                    'margin_total' => round($marginTotal, 2),
                    'average_margin_per_sale' => $salesWithMargin > 0 ? round($marginTotal / $salesWithMargin, 2) : null,
                    'sales_with_margin' => $salesWithMargin,
                    'sales_without_margin' => (int) $team->sum('sales_without_margin'),
                ];
            });

        $eligible = $teamPopulation
            ->sortBy([['fulfillment_pct', 'desc'], ['commercial', 'asc']]);
        $ranks = [];
        $position = 0;
        $previous = null;
        foreach ($eligible as $row) {
            $value = $row['fulfillment_pct'];
            if ($previous === null || $value !== $previous) {
                $position++;
                $previous = $value;
            }
            $ranks[$this->attributionSignature($row)] = $position;
        }

        return $rows->map(function (array $row) use (
            $delegationStats,
            $ranks,
        ): array {
            $delegation = $row['evaluable'] ? $delegationStats->get($row['delegation']) : null;
            $isEligible = (bool) $row['evaluable'];
            $teamAverageReservations = data_get($delegation, 'average_reservations');
            $teamLeadToReservation = data_get($delegation, 'lead_to_reservation_pct');
            $teamOpportunityToReservation = data_get($delegation, 'opportunity_to_reservation_pct');
            $teamReservationToSale = data_get($delegation, 'reservation_to_sale_pct');
            $individualLeadToReservation = $this->rawPercentage($row['reservations_total'], $row['leads']);
            $individualOpportunityToReservation = $this->rawPercentage($row['reservations_total'], $row['opportunities']);
            $individualReservationToSale = $this->rawPercentage($row['sales'], $row['reservations_total']);
            $teamDeviation = $teamAverageReservations === null
                ? null
                : $row['reservations_total'] - $teamAverageReservations;

            return array_merge($row, [
                'team_average_reservations' => $isEligible && $teamAverageReservations !== null
                    ? round($teamAverageReservations, 2)
                    : null,
                'team_reservations_deviation' => $isEligible && $teamDeviation !== null
                    ? round($teamDeviation, 2)
                    : null,
                'team_reservations_deviation_pct' => $isEligible && $teamDeviation !== null && $teamAverageReservations > 0
                    ? round(($teamDeviation / $teamAverageReservations) * 100, 2)
                    : null,
                'team_lead_to_reservation_pct' => $isEligible && $teamLeadToReservation !== null
                    ? round($teamLeadToReservation, 2)
                    : null,
                'lead_to_reservation_vs_team_pp' => $isEligible && $individualLeadToReservation !== null && $teamLeadToReservation !== null
                    ? round($individualLeadToReservation - $teamLeadToReservation, 2)
                    : null,
                'team_opportunity_to_reservation_pct' => $isEligible && $teamOpportunityToReservation !== null
                    ? round($teamOpportunityToReservation, 2)
                    : null,
                'opportunity_to_reservation_vs_team_pp' => $isEligible && $individualOpportunityToReservation !== null && $teamOpportunityToReservation !== null
                    ? round($individualOpportunityToReservation - $teamOpportunityToReservation, 2)
                    : null,
                'team_reservation_to_sale_pct' => $isEligible && $teamReservationToSale !== null
                    ? round($teamReservationToSale, 2)
                    : null,
                'reservation_to_sale_vs_team_pp' => $isEligible && $individualReservationToSale !== null && $teamReservationToSale !== null
                    ? round($individualReservationToSale - $teamReservationToSale, 2)
                    : null,
                'delegation_cancellation_pct' => data_get($delegation, 'cancellation_pct'),
                'delegation_margin_total' => data_get($delegation, 'margin_total'),
                'delegation_average_margin_per_sale' => data_get($delegation, 'average_margin_per_sale'),
                'ranking' => $ranks[$this->attributionSignature($row)] ?? null,
            ]);
        })->sort(function (array $left, array $right): int {
            $rankComparison = ($left['ranking'] ?? PHP_INT_MAX) <=> ($right['ranking'] ?? PHP_INT_MAX);

            return $rankComparison !== 0
                ? $rankComparison
                : strcmp((string) $left['commercial'], (string) $right['commercial']);
        })->values();
    }

    private function hasRealActivity(array $row): bool
    {
        return collect([
            'leads',
            'opportunities',
            'reservations_total',
            'sales',
            'sales_dropped',
        ])->sum(fn (string $metric): int => (int) ($row[$metric] ?? 0)) > 0;
    }

    private function universeMetadata(Collection $rows, int $individualTarget): array
    {
        $evaluable = $rows->where('evaluable', true)->values();
        $activeNotEvaluable = $rows
            ->filter(fn (array $row): bool => filled($row['commercial_id'])
                && $row['has_real_activity']
                && ! $row['evaluable'])
            ->values();
        $excludedNoActivity = $rows
            ->filter(fn (array $row): bool => filled($row['commercial_id']) && ! $row['has_real_activity'])
            ->values();
        $globalTarget = (int) $evaluable->sum('objective');
        $globalReservations = (int) $evaluable->sum('reservations_valid_for_objective');

        return [
            'evaluable_commercials' => $evaluable->count(),
            'active_not_evaluable_commercials' => $activeNotEvaluable->count(),
            'excluded_no_activity_commercials' => $excludedNoActivity->count(),
            'individual_target' => $individualTarget,
            'global_target' => $globalTarget,
            'global_reservations_valid_for_objective' => $globalReservations,
            'global_fulfillment_pct' => $this->percentage($globalReservations, $globalTarget),
            'commercial_filter_affects_global_universe' => false,
        ];
    }

    private function dataIncidentSummary(Collection $rows, bool $cancellationsAvailable): ?array
    {
        $incidents = $rows->whereNull('commercial_id')->values();

        if ($incidents->isEmpty()) {
            return null;
        }

        return array_merge(
            $this->evolutionRow('incident', $incidents, $cancellationsAvailable),
            ['objective' => null, 'fulfillment_pct' => null],
        );
    }

    private function evolutionRow(string $month, Collection $rows, bool $cancellationsAvailable): array
    {
        $reservations = (int) $rows->sum('reservations_total');
        $validReservations = (int) $rows->sum('reservations_valid_for_objective');
        $evaluable = $rows->where('evaluable', true);
        $objectiveReservations = (int) $evaluable->sum('reservations_valid_for_objective');
        $objective = (int) $evaluable->sum(fn (array $row): int => is_numeric($row['objective']) ? (int) $row['objective'] : 0);
        $leads = (int) $rows->sum('leads');
        $opportunities = (int) $rows->sum('opportunities');
        $sales = (int) $rows->sum('sales');
        $cancellations = $cancellationsAvailable ? (int) $rows->sum('cancellations') : null;
        $marginTotal = round((float) $rows->sum('margin_total'), 2);
        $salesWithMargin = (int) $rows->sum('sales_with_margin');

        return [
            'month' => $month,
            'leads' => $leads,
            'opportunities' => $opportunities,
            'reservations_total' => $reservations,
            'reservations_active' => (int) $rows->sum('reservations_active'),
            'reservations_valid_for_objective' => $validReservations,
            'evaluable_reservations_valid_for_objective' => $objectiveReservations,
            'reservations_dropped' => (int) $rows->sum('reservations_dropped'),
            'sales' => $sales,
            'sales_dropped' => (int) $rows->sum('sales_dropped'),
            'sales_signed_reference' => (int) $rows->sum('sales_signed_reference'),
            'cancellations' => $cancellations,
            'objective' => $objective,
            'fulfillment_pct' => $this->percentage($objectiveReservations, $objective),
            'lead_to_reservation_pct' => $this->percentage($reservations, $leads),
            'opportunity_to_reservation_pct' => $this->percentage($reservations, $opportunities),
            'reservation_to_sale_pct' => $this->percentage($sales, $reservations),
            'reservation_drop_pct' => $this->percentage((int) $rows->sum('reservations_dropped'), $reservations),
            'sale_drop_pct' => $this->percentage((int) $rows->sum('sales_dropped'), (int) $rows->sum('sales_signed_reference')),
            'cancellation_pct' => $cancellationsAvailable
                ? $this->percentage($cancellations, $reservations)
                : null,
            'margin_total' => $marginTotal,
            'average_margin_per_sale' => $salesWithMargin > 0 ? round($marginTotal / $salesWithMargin, 2) : null,
            'sales_with_margin' => $salesWithMargin,
            'sales_without_margin' => (int) $rows->sum('sales_without_margin'),
        ];
    }

    private function applyOrganisationFilters(Collection $rows, array $filters): Collection
    {
        return $rows
            ->when(filled($filters['zone']), fn (Collection $items) => $items->where('zone', $filters['zone']))
            ->when(filled($filters['delegation']), fn (Collection $items) => $items->where('delegation', $filters['delegation']))
            ->values();
    }

    private function applyAccessScope(Collection $rows, array $filters): Collection
    {
        return $rows
            ->when(filled($filters['access_zone']), fn (Collection $items) => $items->where('zone', $filters['access_zone']))
            ->values();
    }

    private function scopeDataQuality(array $quality, Collection $rows, array $filters): array
    {
        if (blank($filters['access_zone'])) {
            return $quality;
        }

        return array_replace(
            array_fill_keys(array_keys($quality), null),
            [
                'bootstrap_approved_assignments' => $rows->where('delegation_status', 'bootstrap_approved')->count(),
                'observed_assignments' => $rows->where('delegation_status', 'observed')->count(),
            ],
        );
    }

    private function filterOptions(Collection $rows, array $filters): array
    {
        $evaluable = $rows->where('delegation_certified', true);
        $delegationRows = filled($filters['zone']) ? $evaluable->where('zone', $filters['zone']) : $evaluable;
        $commercialRows = $rows->filter(fn (array $row): bool => filled($row['commercial_id']) && $row['has_real_activity']);
        if (filled($filters['zone'])) {
            $commercialRows = $commercialRows
                ->where('delegation_certified', true)
                ->where('zone', $filters['zone']);
        }
        if (filled($filters['delegation'])) {
            $commercialRows = $commercialRows
                ->where('delegation_certified', true)
                ->where('delegation', $filters['delegation']);
        }

        return [
            'zones' => $evaluable->pluck('zone')->filter()->unique()->sort()->values()->all(),
            'delegations' => $delegationRows->pluck('delegation')->filter()->unique()->sort()->values()->all(),
            'commercials' => $commercialRows
                ->filter(fn (array $row): bool => filled($row['commercial_id']))
                ->map(fn (array $row): array => ['id' => $row['commercial_id'], 'name' => $row['commercial']])
                ->unique('id')->sortBy('name')->values()->all(),
        ];
    }

    public function historyCoverage(Collection $months): array
    {
        $globalStart = $months->first()->startOfMonth()->utc();
        $globalEnd = $months->last()->addMonth()->startOfMonth()->utc();
        $now = CarbonImmutable::now('UTC');
        $currentMonthStart = $now->setTimezone(self::DATASET_TIMEZONE)->startOfMonth()->utc();
        $intervals = SalesforceOpportunityHistorySyncInterval::query()
            ->where('range_start', '<', $globalEnd)
            ->where('range_end', '>', $globalStart)
            ->orderBy('range_start')
            ->get(['range_start', 'range_end', 'unresolved_dependencies', 'is_kpi_certified']);
        $blockingTransitions = SalesforceOpportunityStageTransition::query()
            ->whereIn('quality_status', ['opportunity_not_local', 'previous_stage_not_demonstrated'])
            ->where('transitioned_at', '>=', $globalStart->toDateTimeString())
            ->where('transitioned_at', '<', $globalEnd->toDateTimeString())
            ->whereNotIn('opportunity_salesforce_id', SalesforceOpportunity::withoutGlobalScope(SalesforceOpportunity::ACTIVE_SCOPE)
                ->where('is_deleted', true)
                ->where('deletion_detection_source', SalesforceOpportunity::DELETION_SOURCE_QUERY_ALL)
                ->select('salesforce_id'))
            ->get(['transitioned_at', 'quality_status']);

        return $months->mapWithKeys(function (CarbonImmutable $month) use ($intervals, $blockingTransitions, $currentMonthStart): array {
            $start = $month->startOfMonth()->utc();
            $calendarEnd = $month->addMonth()->startOfMonth()->utc();
            $monthIntervals = $intervals->filter(function (SalesforceOpportunityHistorySyncInterval $interval) use ($start, $calendarEnd): bool {
                return $this->intervalBoundary($interval, 'range_start')->lessThan($calendarEnd)
                    && $this->intervalBoundary($interval, 'range_end')->greaterThan($start);
            });
            $sourceCutoff = $monthIntervals
                ->map(fn (SalesforceOpportunityHistorySyncInterval $interval): CarbonImmutable => $this->intervalBoundary($interval, 'range_end')->min($calendarEnd))
                ->sortDesc()
                ->first();
            $isCurrentMonth = $start->equalTo($currentMonthStart);
            $end = $isCurrentMonth ? ($sourceCutoff ?? $start) : $calendarEnd;
            $cursor = $start;
            $hasOverlap = $monthIntervals->isNotEmpty();
            $pendingTransitions = $blockingTransitions->filter(function (SalesforceOpportunityStageTransition $transition) use ($start, $end): bool {
                $transitionedAt = $this->transitionBoundary($transition);

                return $transitionedAt->greaterThanOrEqualTo($start) && $transitionedAt->lessThan($end);
            });
            $unresolvedDependencies = $pendingTransitions->count();

            if ($end->lessThanOrEqualTo($start)) {
                return [$month->format('Y-m') => [
                    'status' => 'uncovered',
                    'range_start' => $start->toIso8601String(),
                    'range_end' => $end->toIso8601String(),
                    'source_cutoff_at' => $sourceCutoff?->toIso8601String(),
                    'certified_until' => null,
                    'unresolved_dependencies' => $unresolvedDependencies,
                ]];
            }

            foreach ($monthIntervals->where('is_kpi_certified', true) as $interval) {
                $intervalStart = $this->intervalBoundary($interval, 'range_start')->max($start);
                $intervalEnd = $this->intervalBoundary($interval, 'range_end')->min($end);
                if ($intervalEnd->lessThanOrEqualTo($start) || $intervalStart->greaterThanOrEqualTo($end)) {
                    continue;
                }

                if ($intervalStart->greaterThan($cursor)) {
                    break;
                }

                if ($intervalEnd->greaterThan($cursor)) {
                    $cursor = $intervalEnd;
                }

                if ($cursor->greaterThanOrEqualTo($end)) {
                    break;
                }
            }

            $status = $cursor->greaterThanOrEqualTo($end) && $unresolvedDependencies === 0
                ? 'covered'
                : ($hasOverlap ? 'partial' : 'uncovered');
            $certifiedUntil = $cursor->greaterThan($start) ? $cursor : null;
            if ($pendingTransitions->isNotEmpty()) {
                $firstPendingAt = $pendingTransitions
                    ->map(fn (SalesforceOpportunityStageTransition $transition): CarbonImmutable => $this->transitionBoundary($transition))
                    ->sort()
                    ->first();
                $certifiedUntil = $firstPendingAt->greaterThan($start)
                    ? ($certifiedUntil?->min($firstPendingAt) ?? $firstPendingAt)
                    : null;
            }

            return [$month->format('Y-m') => [
                'status' => $status,
                'range_start' => $start->toIso8601String(),
                'range_end' => $end->toIso8601String(),
                'source_cutoff_at' => $sourceCutoff?->toIso8601String(),
                'certified_until' => $certifiedUntil?->toIso8601String(),
                'unresolved_dependencies' => $unresolvedDependencies,
            ]];
        })->all();
    }

    private function intervalBoundary(SalesforceOpportunityHistorySyncInterval $interval, string $attribute): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $interval->getRawOriginal($attribute), 'UTC');
    }

    private function transitionBoundary(SalesforceOpportunityStageTransition $transition): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $transition->getRawOriginal('transitioned_at'), 'UTC');
    }

    private function targets(Collection $months): array
    {
        $now = now();
        CommercialPerformanceMonthlyTarget::query()->insertOrIgnore(
            $months->map(fn (CarbonImmutable $month): array => [
                'month' => $month->toDateString(),
                'reservations_target' => CommercialPerformanceMonthlyTarget::DEFAULT_RESERVATIONS_TARGET,
                'is_explicit' => false,
                'updated_by_report_user_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all(),
        );

        $stored = CommercialPerformanceMonthlyTarget::query()
            ->whereIn('month', $months->map->toDateString())
            ->get()
            ->keyBy(fn (CommercialPerformanceMonthlyTarget $target): string => $target->month->format('Y-m'));

        return $months->mapWithKeys(function (CarbonImmutable $month) use ($stored): array {
            $target = $stored->get($month->format('Y-m'));

            return [$month->format('Y-m') => [
                'value' => (int) $target->reservations_target,
                'is_explicit' => (bool) $target->is_explicit,
            ]];
        })->all();
    }

    private function normalizeFilters(array $filters): array
    {
        return [
            'month' => (string) $filters['month'],
            'zone' => trim((string) ($filters['zone'] ?? '')),
            'delegation' => trim((string) ($filters['delegation'] ?? '')),
            'commercial' => trim((string) ($filters['commercial'] ?? '')),
            'access_zone' => trim((string) ($filters['access_zone'] ?? '')),
        ];
    }

    private function monthKey(mixed $date): string
    {
        return CarbonImmutable::parse($date)->setTimezone(self::DATASET_TIMEZONE)->format('Y-m');
    }

    private function inRange(mixed $date, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        if (blank($date)) {
            return false;
        }

        $value = CarbonImmutable::parse($date)->setTimezone(self::DATASET_TIMEZONE);

        return $value->greaterThanOrEqualTo($start) && $value->lessThan($end);
    }

    private function eventKey(?SalesforceOpportunity $opportunity, mixed $date): string
    {
        return ($this->vehicleIdentity($opportunity) ?: 'opportunity:'.$opportunity?->salesforce_id)
            .'|'.CarbonImmutable::parse($date)->toDateString();
    }

    private function isLost(SalesforceOpportunity $opportunity): bool
    {
        return strcasecmp(trim((string) $opportunity->stage_name), 'Cerrada Perdida') === 0;
    }

    private function saleReferenceDate(SalesforceOpportunity $opportunity): mixed
    {
        return filled($opportunity->reservation_date)
            ? $opportunity->reservation_date
            : (filled($opportunity->cv_signed_date) ? $opportunity->cv_signed_date : null);
    }

    private function saleClassificationKey(SalesforceOpportunity $opportunity): string
    {
        return ($this->vehicleIdentity($opportunity) ?: 'opportunity:'.$opportunity->salesforce_id)
            .'|'.CarbonImmutable::parse($opportunity->cv_signed_date)->toDateString();
    }

    private function vehicleIdentity(?SalesforceOpportunity $opportunity): ?string
    {
        if ($opportunity === null) {
            return null;
        }

        $vehicleId = trim((string) $opportunity->vehicle_interest_id);
        if ($vehicleId !== '') {
            return 'vehicle:'.mb_strtolower($vehicleId);
        }

        $plate = preg_replace('/[^\pL\pN]+/u', '', mb_strtoupper(trim((string) $opportunity->vehicle_plate)));

        return $plate !== '' ? 'plate:'.$plate : null;
    }

    private function attributionSignature(array $attribution): string
    {
        return (string) ($attribution['commercial_id'] ?? 'incident');
    }

    private function percentage(int|float $numerator, int|float $denominator): ?float
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 2) : null;
    }

    private function rawPercentage(int|float $numerator, int|float $denominator): ?float
    {
        return $denominator > 0 ? ($numerator / $denominator) * 100 : null;
    }

    public function trafficLight(float $fulfillment): string
    {
        return match (true) {
            $fulfillment >= 100 => 'green',
            $fulfillment >= 80 => 'yellow',
            $fulfillment >= 60 => 'orange',
            default => 'red',
        };
    }

    private function cacheVersion(): array
    {
        return [
            'reservas_ventas_dashboard' => (int) Cache::get('reservas_ventas_dashboard_cache_version', 1),
            'commercial_performance' => (int) Cache::get('commercial_performance_cache_version', 1),
        ];
    }

    /** @return array{id:int,cutoff:string} */
    public function interestSourceContext(): array
    {
        $run = ReportSyncRun::query()
            ->where('dataset', SalesforceInterestSyncService::DATASET)
            ->where('source', SalesforceInterestSyncService::SOURCE)
            ->orderByDesc('id')
            ->first();

        if ($run === null || $run->status !== 'completed' || $run->source_cutoff_at === null) {
            throw new \RuntimeException('El snapshot F2 de Interests no está disponible para Rendimiento comercial.');
        }

        return ['id' => (int) $run->id, 'cutoff' => $run->source_cutoff_at->toIso8601String()];
    }

    /** @param array{id:int,cutoff:string} $context */
    public function validateInterestSourceContext(array $context): void
    {
        if ($this->interestSourceContext() !== $context) {
            throw new \RuntimeException('El snapshot F2 cambió durante la construcción de Rendimiento comercial.');
        }
    }

    private function incrementCacheVersion(): void
    {
        Cache::forever('commercial_performance_cache_version', ((int) Cache::get('commercial_performance_cache_version', 1)) + 1);
    }
}
