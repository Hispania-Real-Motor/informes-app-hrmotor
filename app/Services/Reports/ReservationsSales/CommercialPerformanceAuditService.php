<?php

namespace App\Services\Reports\ReservationsSales;

use App\Models\CommercialPerformanceMonthlyTarget;
use App\Models\SalesforceInterest;
use App\Models\SalesforceOpportunity;
use App\Models\SalesforceOpportunityStageTransition;
use App\Services\Reports\Leads\LeadRecordTypeNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class CommercialPerformanceAuditService
{
    private const DATASET_TIMEZONE = 'Europe/Madrid';

    public function __construct(
        private readonly CommercialPerformanceMonthlyRosterService $monthlyRoster,
        private readonly CommercialPerformanceDatasetService $dataset,
        private readonly LeadRecordTypeNormalizer $recordTypeNormalizer,
    ) {}

    public function payload(array $filters): array
    {
        $interestContext = $this->dataset->interestSourceContext();
        $month = CarbonImmutable::createFromFormat('!Y-m', $filters['month'], self::DATASET_TIMEZONE)->startOfMonth();
        $end = $month->addMonth();
        $months = collect([$month]);
        $context = $this->monthlyRoster->context($months);
        $coverage = $this->dataset->historyCoverage($months)[$filters['month']];
        $rows = collect();
        $saleClassificationStates = [];

        $this->appendInterests($rows, $month, $end, $context);
        $this->appendOpportunities($rows, $month, $end, $context, $saleClassificationStates);
        $this->appendTransitions($rows, $month, $end, $context, $coverage['status']);
        $this->applyDeduplication($rows, $saleClassificationStates);
        $this->appendMonthlyEvaluation($rows, $this->targetForMonth($month));

        $rows = $rows
            ->when(filled($filters['zone'] ?? null), fn (Collection $items) => $items->where('zone', $filters['zone']))
            ->when(filled($filters['delegation'] ?? null), fn (Collection $items) => $items->where('delegation', $filters['delegation']))
            ->when(filled($filters['commercial'] ?? null), fn (Collection $items) => $items->where('commercial_id', $filters['commercial']));

        $rows = $rows->sortBy([['event_at', 'desc'], ['event_type', 'asc'], ['source_id', 'asc']])->values();
        $this->dataset->validateInterestSourceContext($interestContext);
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['per_page'] ?? 100), 1), 200);

        return [
            'ok' => true,
            'month' => $filters['month'],
            'coverage_status' => $coverage['status'],
            'coverage_source_cutoff_at' => $coverage['source_cutoff_at'],
            'coverage_certified_until' => $coverage['certified_until'],
            'coverage_unresolved_dependencies' => $coverage['unresolved_dependencies'],
            'items' => $rows->forPage($page, $perPage)->values()->all(),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $rows->count(),
                'last_page' => max((int) ceil($rows->count() / $perPage), 1),
            ],
            'pii_excluded' => true,
            'interest_sync_run_id' => $interestContext['id'],
            'interest_sync_cutoff_at' => $interestContext['cutoff'],
            'interest_owner_semantics' => 'current_interest_owner_at_last_sync',
        ];
    }

    private function targetForMonth(CarbonImmutable $month): int
    {
        $now = now();
        $monthDate = $month->toDateString();
        CommercialPerformanceMonthlyTarget::query()->insertOrIgnore([[
            'month' => $monthDate,
            'reservations_target' => CommercialPerformanceMonthlyTarget::DEFAULT_RESERVATIONS_TARGET,
            'is_explicit' => false,
            'updated_by_report_user_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]]);

        return (int) CommercialPerformanceMonthlyTarget::query()
            ->whereDate('month', $monthDate)
            ->value('reservations_target');
    }

    private function appendMonthlyEvaluation(Collection $rows, int $target): void
    {
        $activityByCommercial = $rows
            ->filter(fn (array $row): bool => $row['counted_in_metric']
                && $row['metric_attribution'] !== 'data_quality_incident'
                && filled($row['commercial_id'])
                && in_array($row['event_type'], ['interest', 'opportunity', 'reservation', 'sale', 'sale_dropped'], true))
            ->groupBy('commercial_id');

        foreach ($rows as $key => $row) {
            $isIncident = blank($row['commercial_id']);
            $hasRealActivity = $activityByCommercial->has($row['commercial_id']);
            $evaluable = ! $isIncident
                && $hasRealActivity
                && $row['delegation_certified'];
            $status = match (true) {
                $isIncident => 'data_incident',
                $evaluable => 'evaluable',
                default => 'not_evaluable',
            };
            $reason = match ($status) {
                'data_incident' => $row['delegation_issue'] ?? 'missing_commercial_identity',
                'not_evaluable' => $row['delegation_issue'] ?? 'no_real_activity',
                default => null,
            };

            $row['monthly_has_real_activity'] = $hasRealActivity;
            $row['monthly_evaluable'] = $evaluable;
            $row['monthly_evaluation_status'] = $status;
            $row['monthly_evaluation_reason'] = $reason;
            $row['objective_applies'] = $evaluable;
            $row['monthly_objective'] = $evaluable ? $target : null;
            $rows->put($key, $row);
        }
    }

    private function appendInterests(Collection $rows, CarbonImmutable $start, CarbonImmutable $end, array $context): void
    {
        SalesforceInterest::query()
            ->where('functional_created_at', '>=', $start->utc())
            ->where('functional_created_at', '<', $end->utc())
            ->select([
                'id', 'salesforce_id', 'type', 'is_deleted', 'owner_salesforce_id', 'owner_name', 'functional_created_at',
            ])
            ->orderBy('id')
            ->chunkById(1000, function ($interests) use ($rows, $context): void {
                foreach ($interests as $interest) {
                    $attribution = $this->monthlyRoster->attribution(
                        $context,
                        $interest->owner_salesforce_id,
                        $interest->owner_name,
                        $interest->functional_created_at,
                    );
                    $type = $this->recordTypeNormalizer->normalize($interest->type);
                    $eligibleType = in_array($type, $this->recordTypeNormalizer->ventaFilterTypes(), true);
                    $counted = ! $interest->is_deleted && $eligibleType && $context['users']->has($attribution['commercial_id']);
                    $rows->push($this->row(
                        eventType: 'interest',
                        sourceId: (string) $interest->salesforce_id,
                        eventAt: $interest->functional_created_at,
                        attribution: $attribution,
                        interestId: (string) $interest->salesforce_id,
                        counted: $counted,
                        exclusion: $counted ? null : ($interest->is_deleted ? 'deleted' : ($eligibleType ? 'non_commercial_responsible' : 'record_type_excluded')),
                    ));
                }
            });
    }

    private function appendOpportunities(
        Collection $rows,
        CarbonImmutable $start,
        CarbonImmutable $end,
        array $context,
        array &$saleClassificationStates,
    ): void {
        SalesforceOpportunity::query()
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
                'id', 'salesforce_id', 'record_type_name', 'stage_name', 'owner_id', 'owner_name',
                'created_date', 'reservation', 'reservation_date', 'cv_signed', 'cv_signed_date',
                'vehicle_interest_id', 'vehicle_plate',
            ])
            ->orderBy('id')
            ->chunkById(1000, function ($opportunities) use ($rows, $start, $end, $context, &$saleClassificationStates): void {
                foreach ($opportunities as $opportunity) {
                    $eligibleType = in_array($opportunity->record_type_name, ['Venta', 'Cambio'], true);
                    $funnel = $this->funnelClassification($opportunity);
                    if ($eligibleType && $opportunity->cv_signed && filled($opportunity->cv_signed_date)) {
                        $saleClassificationStates[$this->saleClassificationKey($opportunity)][$funnel['sales_dropped'] ? 'dropped' : 'valid'] = true;
                    }
                    if ($this->inRange($opportunity->created_date, $start, $end)) {
                        $this->pushOpportunityEvent($rows, $opportunity, 'opportunity', $opportunity->created_date, $context, $eligibleType, $eligibleType, $funnel);
                    }
                    if ($this->inRange($opportunity->reservation_date, $start, $end)) {
                        $this->pushOpportunityEvent($rows, $opportunity, 'reservation', $opportunity->reservation_date, $context, $eligibleType && $funnel['reservations_total'], $eligibleType, $funnel);
                    }
                    if ($funnel['sales_valid'] && $this->inRange($opportunity->cv_signed_date, $start, $end)) {
                        $this->pushOpportunityEvent($rows, $opportunity, 'sale', $opportunity->cv_signed_date, $context, $eligibleType, $eligibleType, $funnel);
                    }
                    if ($funnel['sales_dropped'] && $this->inRange($funnel['sales_reference_date'], $start, $end)) {
                        $this->pushOpportunityEvent($rows, $opportunity, 'sale_dropped', $funnel['sales_reference_date'], $context, $eligibleType, $eligibleType, $funnel);
                    }
                }
            });
    }

    private function appendTransitions(
        Collection $rows,
        CarbonImmutable $start,
        CarbonImmutable $end,
        array $context,
        string $coverageStatus,
    ): void {
        $transitions = SalesforceOpportunityStageTransition::query()
            ->where('transitioned_at', '>=', $start->utc())
            ->where('transitioned_at', '<', $end->utc())
            ->orderBy('id')
            ->get();
        $deletedOpportunityIds = SalesforceOpportunity::withoutGlobalScope(SalesforceOpportunity::ACTIVE_SCOPE)
            ->where('is_deleted', true)
            ->where('deletion_detection_source', SalesforceOpportunity::DELETION_SOURCE_QUERY_ALL)
            ->whereIn('salesforce_id', $transitions->pluck('opportunity_salesforce_id')->unique())
            ->pluck('salesforce_id')
            ->flip();
        $opportunities = SalesforceOpportunity::query()
            ->whereIn('salesforce_id', $transitions->pluck('opportunity_salesforce_id')->unique())
            ->get(['salesforce_id', 'vehicle_interest_id', 'vehicle_plate'])
            ->keyBy('salesforce_id');

        foreach ($transitions as $transition) {
            if ($deletedOpportunityIds->has($transition->opportunity_salesforce_id)) {
                continue;
            }

            $attribution = $this->monthlyRoster->attribution(
                $context,
                $transition->owner_id,
                $transition->owner_name,
                $transition->transitioned_at,
            );
            $commerciallyEligible = filled($transition->owner_id) && $context['users']->has($transition->owner_id);
            $counted = $coverageStatus === 'covered'
                && $transition->is_reservation_cancellation
                && $commerciallyEligible;
            $opportunity = $opportunities->get($transition->opportunity_salesforce_id);
            $rows->push($this->row(
                eventType: 'cancellation_transition',
                sourceId: (string) $transition->salesforce_history_id,
                eventAt: $transition->transitioned_at,
                attribution: $attribution,
                opportunityId: (string) $transition->opportunity_salesforce_id,
                counted: $counted,
                exclusion: match (true) {
                    $counted => null,
                    $coverageStatus !== 'covered' => 'history_coverage_'.$coverageStatus,
                    ! $transition->is_reservation_cancellation => $transition->quality_status,
                    ! $commerciallyEligible => 'non_commercial_responsible',
                    default => 'business_rule_excluded',
                },
                coverageStatus: $coverageStatus,
                deduplicationKey: $this->opportunityIdentity($opportunity, (string) $transition->opportunity_salesforce_id).'|'.$transition->transitioned_at->toDateString(),
            ));
        }
    }

    private function pushOpportunityEvent(
        Collection $rows,
        SalesforceOpportunity $opportunity,
        string $eventType,
        mixed $eventAt,
        array $context,
        bool $counted,
        bool $eligibleType,
        array $funnel = [],
    ): void {
        $attribution = $this->monthlyRoster->attribution($context, $opportunity->owner_id, $opportunity->owner_name, $eventAt);
        $commerciallyEligible = filled($opportunity->owner_id) && $context['users']->has($opportunity->owner_id);
        $countedInMetric = $counted && $commerciallyEligible;
        $rows->push($this->row(
            eventType: $eventType,
            sourceId: (string) $opportunity->salesforce_id,
            eventAt: $eventAt,
            attribution: $attribution,
            opportunityId: (string) $opportunity->salesforce_id,
            counted: $countedInMetric,
            exclusion: match (true) {
                $countedInMetric => null,
                ! $eligibleType => 'record_type_excluded',
                $counted && ! $commerciallyEligible => 'non_commercial_responsible',
                default => 'business_rule_excluded',
            },
            deduplicationKey: $this->opportunityIdentity($opportunity).'|'.CarbonImmutable::parse($eventAt)->toDateString(),
            funnel: $funnel,
            classificationKey: $eligibleType ? $this->classificationKey($eventType, $opportunity) : null,
            saleClassificationKey: $eligibleType ? $this->saleClassificationKey($opportunity) : null,
        ));
    }

    private function row(
        string $eventType,
        string $sourceId,
        mixed $eventAt,
        array $attribution,
        ?string $interestId = null,
        ?string $opportunityId = null,
        bool $counted = true,
        ?string $exclusion = null,
        ?string $coverageStatus = null,
        ?string $deduplicationKey = null,
        array $funnel = [],
        ?string $classificationKey = null,
        ?string $saleClassificationKey = null,
    ): array {
        return [
            'event_type' => $eventType,
            'source_id' => $sourceId,
            'interest_id' => $interestId,
            'opportunity_id' => $opportunityId,
            'event_at' => CarbonImmutable::parse($eventAt)->setTimezone(self::DATASET_TIMEZONE)->toIso8601String(),
            'commercial_id' => $attribution['commercial_id'],
            'commercial' => $attribution['commercial'],
            'delegation' => $attribution['delegation'],
            'zone' => $attribution['zone'],
            'delegation_certified' => $attribution['delegation_certified'],
            'delegation_status' => $attribution['delegation_status'],
            'delegation_issue' => $attribution['delegation_issue'] ?? null,
            'coverage_status' => $coverageStatus,
            'counted_in_metric' => $counted,
            'exclusion_reason' => $exclusion,
            'deduplication_key' => $deduplicationKey,
            'deduplication_status' => $deduplicationKey === null ? null : 'unique',
            'metric_attribution' => $attribution['commercial_id'],
            'funnel' => $funnel,
            'classification_key' => $classificationKey,
            'sale_classification_key' => $saleClassificationKey,
        ];
    }

    private function classificationKey(string $eventType, SalesforceOpportunity $opportunity): ?string
    {
        if ($eventType === 'reservation' && filled($opportunity->reservation_date)) {
            return 'reservation|'.$this->opportunityIdentity($opportunity).'|'.CarbonImmutable::parse($opportunity->reservation_date)->toDateString();
        }

        if (in_array($eventType, ['sale', 'sale_dropped'], true) && filled($opportunity->cv_signed_date)) {
            return 'sale|'.$this->opportunityIdentity($opportunity).'|'.CarbonImmutable::parse($opportunity->cv_signed_date)->toDateString();
        }

        return null;
    }

    private function saleClassificationKey(SalesforceOpportunity $opportunity): ?string
    {
        if (! $opportunity->cv_signed || blank($opportunity->cv_signed_date)) {
            return null;
        }

        return 'sale|'.$this->opportunityIdentity($opportunity).'|'.CarbonImmutable::parse($opportunity->cv_signed_date)->toDateString();
    }

    private function funnelClassification(SalesforceOpportunity $opportunity): array
    {
        $lost = strcasecmp(trim((string) $opportunity->stage_name), 'Cerrada Perdida') === 0;
        $hasReservationDate = filled($opportunity->reservation_date);
        $hasSignedDate = filled($opportunity->cv_signed_date);
        $reservationsTotal = (bool) $opportunity->reservation && $hasReservationDate;
        $salesReferenceDate = $hasReservationDate ? $opportunity->reservation_date : ($hasSignedDate ? $opportunity->cv_signed_date : null);
        $salesDropped = (bool) $opportunity->cv_signed && $lost && $salesReferenceDate !== null;

        return [
            'reservations_total' => $reservationsTotal,
            'reservations_active' => $reservationsTotal && ! $opportunity->cv_signed && ! $lost,
            'reservations_valid_for_objective' => $reservationsTotal && ! $lost,
            'reservations_dropped' => $reservationsTotal && ! $opportunity->cv_signed && $lost,
            'sales_valid' => (bool) $opportunity->cv_signed && ! $lost && $hasSignedDate,
            'sales_dropped' => $salesDropped,
            'sales_signed_reference' => (bool) $opportunity->cv_signed && $salesReferenceDate !== null,
            'sales_reference_date' => $salesReferenceDate,
            'reservation_not_demonstrated' => $salesDropped && ! $hasReservationDate,
            'fulfillment_contribution' => $reservationsTotal && ! $lost,
            'fulfillment_exclusion_reason' => match (true) {
                ! $reservationsTotal => 'reservation_not_demonstrated',
                $lost && $opportunity->cv_signed => 'sale_dropped',
                $lost => 'reservation_dropped',
                default => null,
            },
            'data_insufficient' => (bool) $opportunity->cv_signed && $lost && $salesReferenceDate === null,
        ];
    }

    private function applyDeduplication(Collection $rows, array $saleClassificationStates): void
    {
        $groups = $rows
            ->filter(fn (array $row): bool => $row['counted_in_metric']
                && in_array($row['event_type'], ['reservation', 'sale', 'sale_dropped', 'cancellation_transition'], true)
                && filled($row['deduplication_key']))
            ->groupBy(fn (array $row): string => $row['event_type'].'|'.$row['deduplication_key'], true);

        foreach ($groups as $group) {
            if ($group->count() < 2) {
                continue;
            }

            $representativeKey = $group->keys()->first();
            $conflict = $group->pluck('commercial_id')->uniqueStrict()->count() > 1;
            foreach ($group as $key => $row) {
                if ($key === $representativeKey) {
                    $row['deduplication_status'] = $conflict ? 'attribution_conflict_representative' : 'counted_representative';
                    $row['metric_attribution'] = $conflict ? 'data_quality_incident' : $row['commercial_id'];
                } else {
                    $row['counted_in_metric'] = false;
                    $row['exclusion_reason'] = 'deduplicated_event';
                    $row['deduplication_status'] = 'excluded_duplicate';
                }
                $rows->put($key, $row);
            }
        }

        $classificationGroups = $rows
            ->filter(fn (array $row): bool => filled($row['classification_key']))
            ->groupBy('classification_key', true);

        foreach ($classificationGroups as $group) {
            if ($group->count() < 2
                || $group->map(fn (array $row): string => $this->funnelClassificationSignature($row['funnel']))->unique()->count() === 1) {
                continue;
            }

            $this->markClassificationConflict($rows, $group);
        }

        $conflictingSaleKeys = collect($saleClassificationStates)
            ->filter(fn (array $states): bool => isset($states['valid'], $states['dropped']))
            ->keys();
        foreach ($conflictingSaleKeys as $key) {
            $group = $rows->filter(fn (array $row): bool => ($row['sale_classification_key'] ?? null) === $key);
            if ($group->isNotEmpty()) {
                $this->markClassificationConflict($rows, $group);
            }
        }
    }

    private function markClassificationConflict(Collection $rows, Collection $group): void
    {
        $attributionConflict = $group->pluck('commercial_id')->uniqueStrict()->count() > 1;
        foreach ($group as $key => $row) {
            if ($row['event_type'] === 'opportunity') {
                continue;
            }

            $row['metric_attribution'] = 'data_quality_incident';
            if (in_array($row['event_type'], ['sale', 'sale_dropped'], true)) {
                $row['counted_in_metric'] = false;
                $row['exclusion_reason'] = 'classification_conflict';
                $row['deduplication_status'] = 'classification_conflict_excluded';
            }
            $row['classification_conflict'] = true;
            $row['attribution_conflict'] = $attributionConflict;
            $row['funnel']['classification_conflict'] = true;
            $row['funnel']['reservations_active'] = false;
            $row['funnel']['reservations_dropped'] = false;
            $row['funnel']['reservations_valid_for_objective'] = false;
            $row['funnel']['sales_valid'] = false;
            $row['funnel']['sales_dropped'] = false;
            $row['funnel']['fulfillment_contribution'] = false;
            $row['funnel']['fulfillment_exclusion_reason'] = 'classification_conflict';
            $rows->put($key, $row);
        }
    }

    private function funnelClassificationSignature(array $funnel): string
    {
        return implode('|', array_map(
            fn (string $key): string => (string) ((int) ($funnel[$key] ?? false)),
            ['reservations_active', 'reservations_dropped', 'sales_valid', 'sales_dropped'],
        ));
    }

    private function opportunityIdentity(?SalesforceOpportunity $opportunity, ?string $fallbackId = null): string
    {
        if (filled($opportunity?->vehicle_interest_id)) {
            return 'vehicle:'.mb_strtolower((string) $opportunity->vehicle_interest_id);
        }

        $plate = preg_replace('/[^\pL\pN]+/u', '', mb_strtoupper(trim((string) $opportunity?->vehicle_plate)));

        return $plate !== '' ? 'plate:'.$plate : 'opportunity:'.($opportunity?->salesforce_id ?: $fallbackId);
    }

    private function inRange(mixed $date, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        if (blank($date)) {
            return false;
        }

        $value = CarbonImmutable::parse($date)->setTimezone(self::DATASET_TIMEZONE);

        return $value->greaterThanOrEqualTo($start) && $value->lessThan($end);
    }
}
