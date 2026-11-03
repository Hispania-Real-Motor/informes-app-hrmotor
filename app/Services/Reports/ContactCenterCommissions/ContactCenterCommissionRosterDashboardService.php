<?php

namespace App\Services\Reports\ContactCenterCommissions;

use App\Models\CommercialCommissionAppointmentEvidence;
use App\Models\CommercialCommissionDeliveryReconciliation;
use App\Models\ContactCenterCommissionRosterMember;
use App\Models\ReportSyncRun;
use App\Models\SalesforceOpportunity;
use App\Models\SalesforceUser;
use App\Services\Reports\CommercialCommissions\CommercialCommissionFormulaConfigService;
use App\Services\Reports\CommercialCommissions\CommercialCommissionSourceReadinessService;
use App\Services\Reports\CommercialCommissions\EligibleDeliveryOpportunityQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;

class ContactCenterCommissionRosterDashboardService
{
    private const RESPONSIBLE_NAME = 'Vanesa German Bielsa';

    public function __construct(
        private readonly ContactCenterCommissionRosterService $roster,
        private readonly ContactCenterCommissionPlanCalculator $calculator,
        private readonly CommercialCommissionFormulaConfigService $formulaConfig,
        private readonly CommercialCommissionSourceReadinessService $sourceReadiness,
        private readonly EligibleDeliveryOpportunityQuery $deliveryOpportunities,
    ) {}

    public function build(CarbonImmutable $month, bool $includeDetails): array
    {
        $start = $month->startOfMonth();
        $end = $start->addMonth();
        $monthKey = $start->format('Y-m');
        $schemaIssues = $this->schemaIssues();
        $readiness = $this->sourceReadiness->inspect('contact_center', $monthKey, ['issues' => $schemaIssues]);
        $issues = $readiness['blocking'];
        $warnings = $readiness['warnings'];
        $membersByUserId = $this->roster->membersForMonth($start)
            ->groupBy('salesforce_user_id');
        $memberIds = $membersByUserId->keys()->values()->all();
        $settings = $this->formulaConfig->forMonth($monthKey);
        $target = $settings['company_delivery_target'] ?? null;
        $target = is_int($target) && $target > 0 ? $target : null;

        if ($target === null) {
            $issues[] = 'Falta objetivo general de entregas de compañía para el mes.';
        }

        $users = SalesforceUser::query()->whereIn('salesforce_id', $memberIds)->get()->keyBy('salesforce_id');
        $appointments = $this->appointments($start, $end, $memberIds);
        $reservations = $this->reservations($start, $end, $memberIds);
        $deliveries = $this->agentDeliveries($start, $end, $memberIds);
        $companyDeliveries = $this->deliveryOpportunities
            ->forPeriod($start, $end, ['salesforce_id', 'name', 'cv_signed_date', 'appointment_setter_id', 'vehicle_interest_id', 'vehicle_plate'])
            ->get()
            ->unique('salesforce_id')
            ->values();
        $reconciliations = $this->reconciliations($start, $end);
        if ($reconciliations === null) {
            $warnings[] = 'El contraste Product2 no está disponible; las entregas económicas continúan basándose en Opportunities.';
            $reconciliations = collect();
        } elseif (! $this->hasProduct2Evidence($start, $end)) {
            $warnings[] = 'El contraste Product2 no está disponible; las entregas económicas continúan basándose en Opportunities.';
        }

        $companyCount = $companyDeliveries->count();
        $companyCompliance = $target === null ? null : round(($companyCount / $target) * 100, 2);
        $agentSourcesCertifiable = $readiness['ready'];
        $responsibleSourcesCertifiable = ! ($readiness['components']['sales']['blocking'] ?? true);
        $rows = $membersByUserId->map(fn (Collection $memberPeriods): array => $this->agentRow(
            $memberPeriods,
            $users,
            $appointments,
            $reservations,
            $deliveries,
            $reconciliations,
            $start,
            $target,
            $companyCount,
            $companyCompliance,
            $agentSourcesCertifiable,
            $includeDetails,
        ))->sortBy('agent_name')->values()->all();

        $globalIncidents = $this->globalIncidents($start, $end, $membersByUserId, $appointments, $reservations, $deliveries);
        $responsible = $this->responsibleRow($monthKey, $target, $companyCount, $companyCompliance, $companyDeliveries, $responsibleSourcesCertifiable, $includeDetails);
        foreach (collect($rows)->where('certifiable', false) as $row) {
            $issues[] = 'Miembro Contact Center no certificable: '.$row['salesforce_user_id'].' ('.$row['agent_name'].'). '.$row['observations'];
        }

        return [
            'ready' => $issues === [],
            'plan' => 'roster_evidence',
            'month' => $monthKey,
            // The closure must freeze the exact settings that were used to
            // calculate this payload, rather than resolving them a second time.
            'formula_settings' => $settings,
            'month_label' => $start->translatedFormat('F Y'),
            'closure_cutoff_date' => $end->subDay()->toDateString(),
            'issues' => array_values(array_unique($issues)),
            'warnings' => array_values(array_unique($warnings)),
            'diagnostics' => [
                'roster_members' => $membersByUserId->count(),
                'appointments_count' => $appointments->count(),
                'reservations_count' => $reservations->count(),
                'deliveries_count' => $deliveries->count(),
                'company_deliveries' => $companyCount,
                'company_delivery_target' => $target,
                'company_compliance_percent' => $companyCompliance,
                'unrostered_appointment_setters' => $this->incidentUserIds($globalIncidents, 'appointments'),
                'unrostered_reservation_setters' => $this->incidentUserIds($globalIncidents, 'reservations'),
                'unrostered_delivery_setters' => $this->incidentUserIds($globalIncidents, 'deliveries'),
                'product2_match_count' => $reconciliations->where('discrepancy_type', 'MATCH')->count(),
                'product2_discrepancy_count' => $reconciliations->where('discrepancy_type', '!=', 'MATCH')->count(),
                'source_readiness' => $readiness['components'],
            ],
            'summary_rows' => $rows,
            'responsible_contact_center' => $responsible,
            'global_incidents' => $globalIncidents,
        ];
    }

    /** @return Collection<int, CommercialCommissionAppointmentEvidence> */
    private function appointments(CarbonImmutable $start, CarbonImmutable $end, array $memberIds): Collection
    {
        return CommercialCommissionAppointmentEvidence::query()
            ->whereIn('appointment_setter_id', $memberIds)
            ->whereDate('appointment_capture_date', '>=', $start->toDateString())
            ->whereDate('appointment_capture_date', '<', $end->toDateString())
            ->get(['salesforce_interest_id', 'appointment_setter_id', 'appointment_setter_name', 'appointment_capture_date'])
            ->unique('salesforce_interest_id')
            ->values();
    }

    /** @return Collection<int, SalesforceOpportunity> */
    private function reservations(CarbonImmutable $start, CarbonImmutable $end, array $memberIds): Collection
    {
        return SalesforceOpportunity::query()
            ->whereIn('appointment_setter_id', $memberIds)
            ->where('reservation', true)
            ->whereDate('reservation_date', '>=', $start->toDateString())
            ->whereDate('reservation_date', '<', $end->toDateString())
            ->get(['salesforce_id', 'name', 'appointment_setter_id', 'appointment_setter_name', 'reservation_date', 'vehicle_interest_id'])
            ->unique('salesforce_id')
            ->values();
    }

    /** @return Collection<int, SalesforceOpportunity> */
    private function agentDeliveries(CarbonImmutable $start, CarbonImmutable $end, array $memberIds): Collection
    {
        return SalesforceOpportunity::query()
            ->whereIn('appointment_setter_id', $memberIds)
            ->where('cv_signed', true)
            ->whereDate('cv_signed_date', '>=', $start->toDateString())
            ->whereDate('cv_signed_date', '<', $end->toDateString())
            ->get(['salesforce_id', 'name', 'appointment_setter_id', 'appointment_setter_name', 'cv_signed_date', 'vehicle_interest_id', 'vehicle_plate'])
            ->unique('salesforce_id')
            ->values();
    }

    private function reconciliations(CarbonImmutable $start, CarbonImmutable $end): ?Collection
    {
        if (! Schema::hasTable('commercial_commission_delivery_reconciliations')) {
            return null;
        }

        return CommercialCommissionDeliveryReconciliation::query()
            ->whereDate('period_start', $start->toDateString())
            ->whereDate('period_end', $end->toDateString())
            ->get(['opportunity_salesforce_id', 'product_salesforce_id', 'discrepancy_type', 'plate', 'product_delivery_date', 'product_state']);
    }

    private function hasProduct2Evidence(CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return Schema::hasTable('report_sync_runs')
            && ReportSyncRun::query()
                ->where('dataset', 'commercial_commission_product_delivery_evidence')
                ->where('status', 'completed')
                ->whereNotNull('completed_at')
                ->where('period_start_at', '<=', $start)
                ->where('period_end_at', '>=', $end)
                ->exists();
    }

    /** @return array<string, mixed> */
    private function agentRow(Collection $memberPeriods, Collection $users, Collection $appointments, Collection $reservations, Collection $deliveries, Collection $reconciliations, CarbonImmutable $month, ?int $target, int $companyDeliveries, ?float $companyCompliance, bool $sourceCertifiable, bool $includeDetails): array
    {
        /** @var ContactCenterCommissionRosterMember $presentationMember */
        $presentationMember = $memberPeriods->sortByDesc('effective_from')->firstOrFail();
        $userId = $presentationMember->salesforce_user_id;
        $user = $users->get($userId);
        $rowAppointments = $appointments->where('appointment_setter_id', $userId)->filter(fn (CommercialCommissionAppointmentEvidence $event): bool => $this->isEffectiveInAnyPeriod($memberPeriods, $event->appointment_capture_date))->values();
        $rowReservations = $reservations->where('appointment_setter_id', $userId)->filter(fn (SalesforceOpportunity $event): bool => $this->isEffectiveInAnyPeriod($memberPeriods, $event->reservation_date))->values();
        $rowDeliveries = $deliveries->where('appointment_setter_id', $userId)->filter(fn (SalesforceOpportunity $event): bool => $this->isEffectiveInAnyPeriod($memberPeriods, $event->cv_signed_date))->values();
        $rowIssues = [];
        $threshold = $user instanceof SalesforceUser ? $this->calculator->conversionThreshold($user->created_date, $month) : null;
        if (! $user instanceof SalesforceUser) {
            $rowIssues[] = 'No existe SalesforceUser local para el miembro del roster.';
        } elseif ($user->created_date === null) {
            $rowIssues[] = 'Falta Salesforce User.CreatedDate; no se puede determinar el umbral de conversión.';
        }
        if ($rowAppointments->isEmpty()) {
            $rowIssues[] = 'Sin citas en el mes; no supera la barrera de conversión.';
        }

        $conversionPassed = $this->calculator->conversionPassed($rowAppointments->count(), $rowReservations->count(), $threshold);
        $incentive = $conversionPassed ? $this->calculator->deliveryIncentive($rowDeliveries->count()) : $this->calculator->deliveryIncentive(0);
        $bonus = $target === null ? null : $this->calculator->companyBonus($conversionPassed, $companyDeliveries, $target);
        $hardFailures = array_filter($rowIssues, fn (string $issue): bool => ! str_starts_with($issue, 'Sin citas'));
        $certifiable = $sourceCertifiable && $target !== null && $hardFailures === [];
        $finalTotal = $certifiable ? $incentive['delivery_incentive'] + $bonus : null;
        $deliveryReconciliations = $reconciliations->whereIn('opportunity_salesforce_id', $rowDeliveries->pluck('salesforce_id'))->values();

        return [
            'agent_key' => $userId,
            'agent_name' => $presentationMember->display_name,
            'salesforce_user_id' => $userId,
            'salesforce_created_date' => $user?->created_date?->toIso8601String(),
            'roster_effective_from' => $presentationMember->effective_from?->toDateString(),
            'roster_effective_until' => $presentationMember->effective_until?->toDateString(),
            'roster_periods' => $memberPeriods->sortBy('effective_from')->map(fn (ContactCenterCommissionRosterMember $member): array => [
                'effective_from' => $member->effective_from?->toDateString(),
                'effective_until' => $member->effective_until?->toDateString(),
            ])->values()->all(),
            'conversion_threshold' => $threshold,
            'appointment_count' => $rowAppointments->count(),
            'reservation_count' => $rowReservations->count(),
            'sales_count' => $rowDeliveries->count(),
            'delivery_count' => $rowDeliveries->count(),
            'conversion_percent' => $rowAppointments->isEmpty() ? null : round(($rowReservations->count() / $rowAppointments->count()) * 100, 2),
            'conversion_passed' => $conversionPassed,
            ...$incentive,
            'company_delivery_target' => $target,
            'company_deliveries' => $companyDeliveries,
            'company_compliance_percent' => $companyCompliance,
            'company_bonus' => $bonus,
            'automatic_total' => $finalTotal,
            'final_total' => $finalTotal,
            'certifiable' => $certifiable,
            'review_status' => $certifiable ? 'OK' : 'No certificable',
            'observations' => $rowIssues === [] ? 'Sin incidencias' : implode(' ', $rowIssues),
            'issues' => $rowIssues,
            'details' => $includeDetails ? [
                'appointments' => $rowAppointments->map(fn (CommercialCommissionAppointmentEvidence $event): array => [
                    'interest_id' => $event->salesforce_interest_id,
                    'capture_date' => $event->appointment_capture_date?->toDateString(),
                    'appointment_setter_id' => $event->appointment_setter_id,
                    'appointment_setter_name' => $event->appointment_setter_name,
                ])->all(),
                'reservations' => $rowReservations->map(fn (SalesforceOpportunity $event): array => [
                    'opportunity_id' => $event->salesforce_id,
                    'opportunity_name' => $event->name,
                    'reservation_date' => $event->reservation_date?->toDateString(),
                    'appointment_setter_id' => $event->appointment_setter_id,
                    'vehicle_interest_id' => $event->vehicle_interest_id,
                ])->all(),
                'deliveries' => $rowDeliveries->map(fn (SalesforceOpportunity $event): array => [
                    'opportunity_id' => $event->salesforce_id,
                    'opportunity_name' => $event->name,
                    'cv_signed_date' => $event->cv_signed_date?->toDateString(),
                    'appointment_setter_id' => $event->appointment_setter_id,
                    'vehicle_interest_id' => $event->vehicle_interest_id,
                    'plate' => $event->vehicle_plate,
                    'product2_statuses' => $reconciliations->where('opportunity_salesforce_id', $event->salesforce_id)->pluck('discrepancy_type')->values()->all(),
                ])->all(),
                'product2_reconciliations' => $deliveryReconciliations->map(fn (CommercialCommissionDeliveryReconciliation $item): array => [
                    'opportunity_id' => $item->opportunity_salesforce_id,
                    'product_id' => $item->product_salesforce_id,
                    'status' => $item->discrepancy_type,
                    'plate' => $item->plate,
                ])->all(),
            ] : [],
        ];
    }

    /** @return array<string, mixed> */
    private function responsibleRow(string $month, ?int $target, int $deliveries, ?float $compliance, Collection $companyDeliveries, bool $sourceCertifiable, bool $includeDetails): array
    {
        $issues = [];
        if ($target === null) {
            $issues[] = 'Falta objetivo general de entregas de compañía para el mes.';
        }
        if (! $sourceCertifiable) {
            $issues[] = 'La cobertura de Opportunities no permite certificar las entregas de compañía.';
        }
        $certifiable = $sourceCertifiable && $target !== null;
        $formula = ! $certifiable ? [
            'compliance_percent' => null,
            'bracket' => 'No certificable',
            'base_amount' => null,
            'additional_deliveries' => null,
            'additional_amount' => null,
            'final_total' => null,
        ] : $this->calculator->responsibleCommission($deliveries, $target);

        return [
            'responsible_name' => self::RESPONSIBLE_NAME,
            'month' => $month,
            'company_delivery_target' => $target,
            'actual_deliveries' => $deliveries,
            'company_compliance_percent' => $compliance,
            'certifiable' => $certifiable,
            'issues' => $issues,
            ...$formula,
            'actual_delivery_details' => $includeDetails ? $companyDeliveries->map(fn (SalesforceOpportunity $event): array => [
                'opportunity_id' => $event->salesforce_id,
                'opportunity_name' => $event->name,
                'contract_signed_date' => $event->cv_signed_date?->toDateString(),
                'appointment_setter_id' => $event->appointment_setter_id,
                'plate' => $event->vehicle_plate,
            ])->all() : [],
        ];
    }

    /** @return array<int, array<string, string|null>> */
    private function globalIncidents(CarbonImmutable $start, CarbonImmutable $end, Collection $membersByUserId, Collection $appointments, Collection $reservations, Collection $deliveries): array
    {
        $memberIds = $membersByUserId->keys()->all();
        $events = collect([
            ...$appointments->map(fn (CommercialCommissionAppointmentEvidence $event): array => $this->incidentEvent('appointments', $event->appointment_setter_id, $event->salesforce_interest_id, $event->appointment_capture_date))->all(),
            ...$reservations->map(fn (SalesforceOpportunity $event): array => $this->incidentEvent('reservations', $event->appointment_setter_id, $event->salesforce_id, $event->reservation_date))->all(),
            ...$deliveries->map(fn (SalesforceOpportunity $event): array => $this->incidentEvent('deliveries', $event->appointment_setter_id, $event->salesforce_id, $event->cv_signed_date))->all(),
            ...CommercialCommissionAppointmentEvidence::query()->whereNotNull('appointment_setter_id')->whereNotIn('appointment_setter_id', $memberIds)->whereDate('appointment_capture_date', '>=', $start->toDateString())->whereDate('appointment_capture_date', '<', $end->toDateString())->get(['salesforce_interest_id', 'appointment_setter_id', 'appointment_capture_date'])->map(fn (CommercialCommissionAppointmentEvidence $event): array => $this->incidentEvent('appointments', $event->appointment_setter_id, $event->salesforce_interest_id, $event->appointment_capture_date))->all(),
            ...SalesforceOpportunity::query()->whereNotNull('appointment_setter_id')->whereNotIn('appointment_setter_id', $memberIds)->where('reservation', true)->whereDate('reservation_date', '>=', $start->toDateString())->whereDate('reservation_date', '<', $end->toDateString())->get(['salesforce_id', 'appointment_setter_id', 'reservation_date'])->map(fn (SalesforceOpportunity $event): array => $this->incidentEvent('reservations', $event->appointment_setter_id, $event->salesforce_id, $event->reservation_date))->all(),
            ...SalesforceOpportunity::query()->whereNotNull('appointment_setter_id')->whereNotIn('appointment_setter_id', $memberIds)->where('cv_signed', true)->whereDate('cv_signed_date', '>=', $start->toDateString())->whereDate('cv_signed_date', '<', $end->toDateString())->get(['salesforce_id', 'appointment_setter_id', 'cv_signed_date'])->map(fn (SalesforceOpportunity $event): array => $this->incidentEvent('deliveries', $event->appointment_setter_id, $event->salesforce_id, $event->cv_signed_date))->all(),
        ]);

        return $events->filter(function (array $event) use ($membersByUserId): bool {
            $periods = $membersByUserId->get($event['salesforce_user_id']);

            return ! $periods instanceof Collection || ! $this->isEffectiveInAnyPeriod($periods, $event['event_date']);
        })->map(function (array $event) use ($membersByUserId): array {
            $inRoster = $membersByUserId->has($event['salesforce_user_id']);

            return [
                'type' => $inRoster ? 'EVENT_OUTSIDE_ROSTER_EFFECTIVE_RANGE' : 'USER_NOT_IN_ROSTER',
                'event_type' => $event['event_type'],
                'reference_id' => $event['reference_id'],
                'reference_name' => '',
                'salesforce_user_id' => $event['salesforce_user_id'],
                'event_date' => $event['event_date'],
                'reason' => $inRoster
                    ? 'La fecha del evento no pertenece a ningún tramo vigente del roster; no se ha generado comisión.'
                    : 'El UserId no pertenece al roster aplicable; no se ha generado comisión.',
            ];
        })->values()->all();
    }

    /** @return array{event_type:string,salesforce_user_id:string,reference_id:string,event_date:?string} */
    private function incidentEvent(string $type, ?string $userId, ?string $referenceId, mixed $eventDate): array
    {
        return [
            'event_type' => $type,
            'salesforce_user_id' => (string) $userId,
            'reference_id' => (string) $referenceId,
            'event_date' => $eventDate ? CarbonImmutable::parse($eventDate)->toDateString() : null,
        ];
    }

    /** @return array<int, string> */
    private function incidentUserIds(array $incidents, string $eventType): array
    {
        return collect($incidents)->where('event_type', $eventType)->pluck('salesforce_user_id')->unique()->sort()->values()->all();
    }

    private function isEffectiveInAnyPeriod(Collection $memberPeriods, mixed $eventDate): bool
    {
        return $memberPeriods->contains(fn (ContactCenterCommissionRosterMember $member): bool => $this->memberIsEffective($member, $eventDate));
    }

    private function memberIsEffective(ContactCenterCommissionRosterMember $member, mixed $eventDate): bool
    {
        if ($eventDate === null) {
            return false;
        }

        $date = CarbonImmutable::parse($eventDate)->toDateString();

        return $date >= $member->effective_from->toDateString()
            && ($member->effective_until === null || $date <= $member->effective_until->toDateString());
    }

    /** @return array<int, string> */
    private function schemaIssues(): array
    {
        $requirements = [
            'contact_center_commission_roster_members' => ['salesforce_user_id', 'effective_from', 'effective_until'],
            'salesforce_users' => ['salesforce_id', 'created_date'],
            'commercial_commission_appointment_evidences' => ['salesforce_interest_id', 'appointment_setter_id', 'appointment_capture_date'],
            'salesforce_opportunities' => ['salesforce_id', 'appointment_setter_id', 'reservation', 'reservation_date', 'cv_signed', 'cv_signed_date'],
        ];
        $issues = [];
        foreach ($requirements as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $issues[] = "Falta la tabla local {$table}.";

                continue;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $issues[] = "Falta la columna local {$table}.{$column}.";
                }
            }
        }

        return $issues;
    }
}
