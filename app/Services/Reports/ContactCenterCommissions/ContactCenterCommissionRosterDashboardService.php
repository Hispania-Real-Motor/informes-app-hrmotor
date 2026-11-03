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
        $members = $this->roster->membersForMonth($start);
        $memberIds = $members->pluck('salesforce_user_id')->unique()->values()->all();
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
        $sourceCertifiable = $readiness['ready'];
        $rows = $members->map(fn (ContactCenterCommissionRosterMember $member): array => $this->agentRow(
            $member,
            $users,
            $appointments,
            $reservations,
            $deliveries,
            $reconciliations,
            $start,
            $target,
            $companyCount,
            $companyCompliance,
            $sourceCertifiable,
            $includeDetails,
        ))->sortBy('agent_name')->values()->all();

        $unrostered = $this->unrosteredSetters($start, $end, $memberIds);
        $globalIncidents = $this->globalIncidents($unrostered);
        $responsible = $this->responsibleRow($monthKey, $target, $companyCount, $companyCompliance, $companyDeliveries, $sourceCertifiable, $includeDetails);

        return [
            'ready' => $issues === [],
            'plan' => 'roster_evidence',
            'month' => $monthKey,
            'month_label' => $start->translatedFormat('F Y'),
            'closure_cutoff_date' => $end->subDay()->toDateString(),
            'issues' => array_values(array_unique($issues)),
            'warnings' => array_values(array_unique($warnings)),
            'diagnostics' => [
                'roster_members' => count($rows),
                'appointments_count' => $appointments->count(),
                'reservations_count' => $reservations->count(),
                'deliveries_count' => $deliveries->count(),
                'company_deliveries' => $companyCount,
                'company_delivery_target' => $target,
                'company_compliance_percent' => $companyCompliance,
                'unrostered_appointment_setters' => $unrostered['appointments'],
                'unrostered_reservation_setters' => $unrostered['reservations'],
                'unrostered_delivery_setters' => $unrostered['deliveries'],
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
            ->where('period_start', $start->toDateString())
            ->where('period_end', $end->toDateString())
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
    private function agentRow(ContactCenterCommissionRosterMember $member, Collection $users, Collection $appointments, Collection $reservations, Collection $deliveries, Collection $reconciliations, CarbonImmutable $month, ?int $target, int $companyDeliveries, ?float $companyCompliance, bool $sourceCertifiable, bool $includeDetails): array
    {
        $userId = $member->salesforce_user_id;
        $user = $users->get($userId);
        $rowAppointments = $appointments->where('appointment_setter_id', $userId)->filter(fn (CommercialCommissionAppointmentEvidence $event): bool => $this->memberIsEffective($member, $event->appointment_capture_date))->values();
        $rowReservations = $reservations->where('appointment_setter_id', $userId)->filter(fn (SalesforceOpportunity $event): bool => $this->memberIsEffective($member, $event->reservation_date))->values();
        $rowDeliveries = $deliveries->where('appointment_setter_id', $userId)->filter(fn (SalesforceOpportunity $event): bool => $this->memberIsEffective($member, $event->cv_signed_date))->values();
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
            'agent_name' => $member->display_name,
            'salesforce_user_id' => $userId,
            'salesforce_created_date' => $user?->created_date?->toIso8601String(),
            'roster_effective_from' => $member->effective_from?->toDateString(),
            'roster_effective_until' => $member->effective_until?->toDateString(),
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
        $certifiable = $sourceCertifiable && $target !== null;
        $formula = $target === null ? [
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

    /** @return array{appointments: array<int, string>, reservations: array<int, string>, deliveries: array<int, string>} */
    private function unrosteredSetters(CarbonImmutable $start, CarbonImmutable $end, array $memberIds): array
    {
        return [
            'appointments' => CommercialCommissionAppointmentEvidence::query()->whereNotNull('appointment_setter_id')->whereNotIn('appointment_setter_id', $memberIds)->whereDate('appointment_capture_date', '>=', $start->toDateString())->whereDate('appointment_capture_date', '<', $end->toDateString())->distinct()->orderBy('appointment_setter_id')->pluck('appointment_setter_id')->all(),
            'reservations' => SalesforceOpportunity::query()->whereNotNull('appointment_setter_id')->whereNotIn('appointment_setter_id', $memberIds)->where('reservation', true)->whereDate('reservation_date', '>=', $start->toDateString())->whereDate('reservation_date', '<', $end->toDateString())->distinct()->orderBy('appointment_setter_id')->pluck('appointment_setter_id')->all(),
            'deliveries' => SalesforceOpportunity::query()->whereNotNull('appointment_setter_id')->whereNotIn('appointment_setter_id', $memberIds)->where('cv_signed', true)->whereDate('cv_signed_date', '>=', $start->toDateString())->whereDate('cv_signed_date', '<', $end->toDateString())->distinct()->orderBy('appointment_setter_id')->pluck('appointment_setter_id')->all(),
        ];
    }

    /** @return array<int, array<string, string>> */
    private function globalIncidents(array $unrostered): array
    {
        return collect($unrostered)->flatMap(function (array $ids, string $type): array {
            return array_map(fn (string $id): array => [
                'type' => 'Captador fuera de roster: '.$type,
                'reference_id' => $id,
                'reference_name' => '',
                'event_date' => null,
                'reason' => 'El UserId no pertenece al roster aplicable; no se ha generado comisión.',
            ], $ids);
        })->values()->all();
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
