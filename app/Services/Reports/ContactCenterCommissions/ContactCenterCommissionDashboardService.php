<?php

namespace App\Services\Reports\ContactCenterCommissions;

use App\Models\SalesforceLead;
use App\Models\SalesforceOpportunity;
use App\Services\Reports\CommercialCommissions\CommercialCommissionSourceReadinessService;
use App\Services\Reports\CommercialCommissions\CommissionMonthResolver;
use App\Services\Reports\CommercialCommissions\EligibleDeliveryOpportunityQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ContactCenterCommissionDashboardService
{
    public function __construct(
        private readonly CommissionMonthResolver $monthResolver,
        private readonly EligibleDeliveryOpportunityQuery $deliveryOpportunities,
        private readonly CommercialCommissionSourceReadinessService $sourceReadiness,
    ) {}

    private const CLOSURE_DAY = 10;

    private const OPPORTUNITY_COMMISSION = 5.0;

    private const SALE_COMMISSION = 12.0;

    private const RATIO_BONUS_PER_SALE = 2.0;

    private const RESPONSIBLE_CONTACT_CENTER_EFFECTIVE_FROM = '2026-09-01';

    private const RESPONSIBLE_CONTACT_CENTER_NAME = 'Vanesa Germán';

    private const RESPONSIBLE_DELIVERY_COLUMNS = [
        'salesforce_id', 'name', 'cv_signed_date', 'record_type_name', 'stage_name',
        'owner_name', 'owner_delegation', 'delivery_store',
    ];

    private const CONTACT_CENTER_AGENT_ALIASES = [
        'jose ignacio palomo casas' => 'Jose Ignacio Palomo Casas',
        'maria paz vidal perez' => 'Maria Paz Vidal Perez',
        'estefany taborda' => 'Estefany Taborda',
        'johanna panos' => 'Johanna Paños',
        'nuria larrosa' => 'Nuria Larrosa',
        'rafael polanco' => 'Rafael Polanco',
        'maria german' => 'Maria German',
        'yuleidis garcia' => 'Yuleidis Garcia',
        'maria vidal' => 'Maria Paz Vidal Perez',
        'vidal perez' => 'Maria Paz Vidal Perez',
        'jose palomo' => 'Jose Ignacio Palomo Casas',
        'palomo casas' => 'Jose Ignacio Palomo Casas',
        'larrosa' => 'Nuria Larrosa',
        'taborda' => 'Estefany Taborda',
        'panos' => 'Johanna Paños',
        'yuleidis' => 'Yuleidis Garcia',
        'rafael' => 'Rafael Polanco',
    ];

    public function build(?string $month, bool $includeDetails = true): array
    {
        [$selectedMonth, $monthWarning] = $this->resolveMonth($month);
        $periodStart = $selectedMonth->startOfMonth();
        $periodEnd = $periodStart->addMonth();
        $closureEnd = $this->closureEndExclusive($selectedMonth);
        $issues = $this->blockingIssues();
        $warnings = array_values(array_filter([$monthWarning]));

        $summaryRows = [];
        $globalIncidents = [];
        $responsibleCommission = null;
        $diagnostics = [
            'appointments_count' => 0,
            'sales_count' => 0,
            'opportunity_links_count' => 0,
            'reservation_links_count' => 0,
            'show_count' => 0,
            'appointments_without_phone_count' => 0,
            'sales_without_appointment_count' => 0,
            'sales_ambiguous_count' => 0,
        ];

        if ($issues === []) {
            $historyStart = $periodStart->subMonths(2);

            $monthAppointments = $this->appointmentsInRange($periodStart, $periodEnd)
                ->get()
                ->filter(fn (SalesforceLead $lead): bool => $this->contactCenterAgentName(
                    $lead->appointment_setter_name ?: $lead->appointment_setter_id
                ) !== null)
                ->values();
            $candidateAppointments = $this->appointmentsInRange($historyStart, $closureEnd)
                ->get()
                ->values();
            $relevantOpportunities = $this->monthAttributedOpportunities($historyStart, $closureEnd)
                ->get()
                ->values();
            $monthSales = $this->monthSales($periodStart, $periodEnd)
                ->get()
                ->values();

            $opportunitiesById = $relevantOpportunities->keyBy('salesforce_id');
            $opportunitiesByPhone = $this->indexOpportunitiesByPhone($relevantOpportunities);
            $appointmentsByOpportunity = $this->indexAppointmentsByConvertedOpportunity($candidateAppointments);
            $appointmentsByPhone = $this->indexAppointmentsByPhone($candidateAppointments);

            $diagnostics['appointments_count'] = $monthAppointments->count();
            $diagnostics['sales_count'] = $monthSales->count();

            foreach ($monthAppointments as $appointment) {
                $agentName = $this->contactCenterAgentName($appointment->appointment_setter_name ?: $appointment->appointment_setter_id);

                if ($agentName === null) {
                    continue;
                }

                $rowKey = $this->agentKeyFromName($agentName);
                $this->ensureRow($summaryRows, $rowKey, $agentName);
                $summaryRows[$rowKey]['appointment_count']++;

                $appointmentPhones = $this->phonesForLead($appointment);
                $showedUp = $this->normalizedText($appointment->appointment_attended_status) === 'acudio';

                if ($showedUp) {
                    $summaryRows[$rowKey]['show_count']++;
                    $diagnostics['show_count']++;
                }

                if ($includeDetails) {
                    $summaryRows[$rowKey]['details']['appointments'][] = [
                        'lead_id' => $appointment->salesforce_id,
                        'lead_name' => (string) ($appointment->name ?? ''),
                        'phone_normalized' => implode(' / ', $appointmentPhones),
                        'agent_name' => $agentName,
                        'capture_date' => optional($appointment->appointment_capture_date)?->toDateString(),
                        'appointment_call' => (bool) $appointment->appointment_call,
                        'appointment_store' => (bool) $appointment->appointment_store,
                        'attended_status' => (string) ($appointment->appointment_attended_status ?? ''),
                        'candidate_status' => (string) ($appointment->candidate_status_formula ?? ''),
                        'owner_name' => (string) ($appointment->owner_name ?? ''),
                        'store_commercial_name' => (string) ($appointment->store_commercial_name ?? ''),
                        'portal' => (string) ($appointment->portal_text ?? ''),
                        'delegation' => (string) ($appointment->delegacion_encargada_text ?? ''),
                        'inclusion_reason' => $this->appointmentReason($appointment),
                    ];
                }
                $linkedOpportunities = $this->linkedOpportunitiesForAppointment(
                    $appointment,
                    $opportunitiesById,
                    $opportunitiesByPhone,
                    $closureEnd
                );
                $bestOutcome = $this->bestOutcomeForAppointment($appointment, $linkedOpportunities);

                if ($bestOutcome !== null) {
                    /** @var SalesforceOpportunity $opportunity */
                    $opportunity = $bestOutcome['opportunity'];
                    $captureDate = optional($appointment->appointment_capture_date)?->toDateString();

                    $summaryRows[$rowKey]['opportunity_count']++;
                    $diagnostics['opportunity_links_count']++;
                    if ($includeDetails) {
                        $summaryRows[$rowKey]['details']['opportunities'][] = [
                            'lead_id' => $appointment->salesforce_id,
                            'opportunity_id' => $opportunity->salesforce_id,
                            'opportunity_name' => (string) ($opportunity->name ?? ''),
                            'phone_normalized' => implode(' / ', $this->phonesForOpportunity($opportunity)),
                            'agent_name' => $agentName,
                            'capture_date' => $captureDate,
                            'opportunity_created_date' => optional($opportunity->created_date)?->toDateString(),
                            'stage_name' => (string) ($opportunity->stage_name ?? ''),
                            'owner_name' => (string) ($opportunity->owner_name ?? ''),
                            'delegation' => (string) ($opportunity->owner_delegation ?? ''),
                            'record_type_name' => (string) ($opportunity->record_type_name ?? ''),
                            'link_origin' => $bestOutcome['link_origin'],
                            'commission_amount' => self::OPPORTUNITY_COMMISSION,
                        ];
                    }

                    if ($bestOutcome['has_reservation']) {
                        $summaryRows[$rowKey]['reservation_count']++;
                        $diagnostics['reservation_links_count']++;
                        if ($includeDetails) {
                            $summaryRows[$rowKey]['details']['reservations'][] = [
                                'opportunity_id' => $opportunity->salesforce_id,
                                'opportunity_name' => (string) ($opportunity->name ?? ''),
                                'reservation_date' => optional($opportunity->reservation_date)?->toDateString(),
                                'agent_name' => $agentName,
                                'phone_normalized' => implode(' / ', $this->phonesForOpportunity($opportunity)),
                                'vehicle_plate' => (string) ($opportunity->vehicle_plate ?? ''),
                                'account_name' => (string) ($opportunity->account_name ?? ''),
                                'stage_name' => (string) ($opportunity->stage_name ?? ''),
                                'portal' => (string) ($opportunity->portal_resolved ?? $opportunity->portal_original ?? ''),
                                'pending_contract' => (bool) $opportunity->reservation && ! (bool) $opportunity->cv_signed,
                                'observations' => $bestOutcome['link_origin'],
                            ];
                        }
                    }
                }
            }

            foreach ($monthSales as $sale) {
                $bestAppointment = $this->bestAppointmentForSale(
                    $sale,
                    $appointmentsByOpportunity,
                    $appointmentsByPhone,
                    $selectedMonth
                );

                /** @var SalesforceLead|null $appointment */
                $appointment = $bestAppointment['lead'];
                $agentName = $appointment instanceof SalesforceLead
                    ? $this->contactCenterAgentName($appointment->appointment_setter_name ?: $appointment->appointment_setter_id)
                    : null;

                if ($agentName === null || ! $appointment instanceof SalesforceLead) {
                    continue;
                }

                $rowKey = $this->agentKeyFromName($agentName);
                $this->ensureRow($summaryRows, $rowKey, $agentName);
                $summaryRows[$rowKey]['sales_count']++;
                if ($includeDetails) {
                    $summaryRows[$rowKey]['details']['sales'][] = [
                        'opportunity_id' => $sale->salesforce_id,
                        'opportunity_name' => (string) ($sale->name ?? ''),
                        'phone_normalized' => implode(' / ', $this->phonesForOpportunity($sale)),
                        'agent_name' => $agentName,
                        'contract_signed_date' => optional($sale->cv_signed_date)?->toDateString(),
                        'cv_signed' => (bool) $sale->cv_signed,
                        'stage_name' => (string) ($sale->stage_name ?? ''),
                        'owner_name' => (string) ($sale->owner_name ?? ''),
                        'delegation' => (string) ($sale->owner_delegation ?? ''),
                        'vehicle_plate' => (string) ($sale->vehicle_plate ?? ''),
                        'record_type_name' => (string) ($sale->record_type_name ?? ''),
                        'month_imputed' => $selectedMonth->format('Y-m'),
                        'sale_commission_amount' => self::SALE_COMMISSION,
                        'ratio_bonus_applied' => false,
                        'observations' => $bestAppointment['reason'],
                    ];
                }
            }
        }

        $summaryRows = collect($summaryRows)
            ->map(function (array $row) use ($includeDetails): array {
                $ratio = $this->divide($row['sales_count'], $row['appointment_count']);
                $opportunityCommission = round($row['opportunity_count'] * self::OPPORTUNITY_COMMISSION, 2);
                $salesCommission = round($row['sales_count'] * self::SALE_COMMISSION, 2);
                $ratioBonus = $ratio > 0.03
                    ? round($row['sales_count'] * self::RATIO_BONUS_PER_SALE, 2)
                    : 0.0;
                $volumeBonus = $this->volumeBonus($row['sales_count']);
                $automaticTotal = round($opportunityCommission + $salesCommission + $ratioBonus + $volumeBonus, 2);
                $showRate = $this->divide($row['show_count'], $row['appointment_count']);

                $row['sales_ratio'] = $ratio;
                $row['opportunity_commission'] = $opportunityCommission;
                $row['sales_commission'] = $salesCommission;
                $row['ratio_bonus'] = $ratioBonus;
                $row['volume_bonus'] = $volumeBonus;
                $row['show_rate'] = $showRate;
                $row['show_commission'] = 0.0;
                $row['automatic_total'] = $automaticTotal;
                $row['manual_adjustment'] = 0.0;
                $row['final_total'] = $automaticTotal;
                $row['review_status'] = count($row['details']['incidents']) > 0 ? 'Revisar' : 'OK';
                $row['observations'] = count($row['details']['incidents']) > 0
                    ? count($row['details']['incidents']).' incidencias'
                    : 'Sin incidencias';

                if ($includeDetails && $ratioBonus > 0) {
                    foreach ($row['details']['sales'] as &$detail) {
                        $detail['ratio_bonus_applied'] = true;
                    }
                    unset($detail);
                }

                if ($includeDetails) {
                    foreach (['appointments', 'opportunities', 'reservations', 'sales', 'incidents'] as $detailKey) {
                        $row['details'][$detailKey] = collect($row['details'][$detailKey])
                            ->sortBy([
                                ['event_date', 'desc'],
                                ['capture_date', 'desc'],
                                ['contract_signed_date', 'desc'],
                                ['reservation_date', 'desc'],
                                ['opportunity_created_date', 'desc'],
                                ['reference_name', 'asc'],
                                ['opportunity_name', 'asc'],
                                ['lead_name', 'asc'],
                            ])
                            ->values()
                            ->all();
                    }
                } else {
                    unset($row['details']);
                }

                return $row;
            })
            ->sortByDesc('final_total')
            ->values()
            ->all();

        if (
            $issues === []
            && $diagnostics['appointments_count'] === 0
            && $diagnostics['opportunity_links_count'] === 0
            && $diagnostics['sales_count'] === 0
        ) {
            $warnings[] = 'No hay citas efectivas del Contact Center para el mes seleccionado. Re-sincroniza leads con salesforce:sync-monthly-commercial si esperabas actividad.';
        }

        if ($issues === []) {
            $responsibleCommission = $this->responsibleContactCenterCommission($selectedMonth, $includeDetails);
            if (is_array($responsibleCommission)) {
                $issues = array_values(array_unique(array_merge($issues, $responsibleCommission['issues'] ?? [])));
            }
        }

        return [
            'ready' => $issues === [],
            'month' => $selectedMonth->format('Y-m'),
            'month_label' => $selectedMonth->translatedFormat('F Y'),
            'closure_cutoff_date' => $closureEnd->subDay()->toDateString(),
            'issues' => $issues,
            'warnings' => array_values(array_unique(array_filter($warnings))),
            'diagnostics' => $diagnostics,
            'summary_rows' => $summaryRows,
            // summary_rows remains exclusively the agent/captador universe.
            'responsible_contact_center' => $responsibleCommission,
            'global_incidents' => collect($globalIncidents)
                ->sortByDesc(fn (array $row) => $row['event_date'] ?? '')
                ->values()
                ->all(),
        ];
    }

    private function appointmentsInRange(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): Builder
    {
        return SalesforceLead::query()
            ->whereNotNull('appointment_capture_date')
            ->whereDate('appointment_capture_date', '>=', $periodStart->toDateString())
            ->whereDate('appointment_capture_date', '<', $periodEnd->toDateString())
            ->where(function (Builder $query): void {
                $query->where('appointment_call', true)
                    ->orWhere('appointment_store', true);
            })
            ->where(function (Builder $query): void {
                $query->whereNotNull('appointment_setter_id')
                    ->orWhereNotNull('appointment_setter_name');
            });
    }

    private function monthAttributedOpportunities(CarbonImmutable $periodStart, CarbonImmutable $closureEnd): Builder
    {
        return SalesforceOpportunity::query()
            ->whereNotNull('raw_payload')
            ->where(function (Builder $query) use ($periodStart, $closureEnd): void {
                $periodStartDate = $periodStart->toDateString();
                $closureDate = $closureEnd->toDateString();

                $query
                    ->where(function (Builder $dateQuery) use ($periodStartDate, $closureDate): void {
                        $dateQuery->whereNotNull('created_date')
                            ->whereDate('created_date', '>=', $periodStartDate)
                            ->whereDate('created_date', '<', $closureDate);
                    })
                    ->orWhere(function (Builder $dateQuery) use ($periodStartDate, $closureDate): void {
                        $dateQuery->where('reservation', true)
                            ->whereNotNull('reservation_date')
                            ->whereDate('reservation_date', '>=', $periodStartDate)
                            ->whereDate('reservation_date', '<', $closureDate);
                    })
                    ->orWhere(function (Builder $dateQuery) use ($periodStartDate, $closureDate): void {
                        $dateQuery->where('cv_signed', true)
                            ->whereNotNull('cv_signed_date')
                            ->whereDate('cv_signed_date', '>=', $periodStartDate)
                            ->whereDate('cv_signed_date', '<', $closureDate);
                    });
            });
    }

    private function monthSales(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): Builder
    {
        return SalesforceOpportunity::query()
            ->where('cv_signed', true)
            ->whereNotNull('cv_signed_date')
            ->whereDate('cv_signed_date', '>=', $periodStart->toDateString())
            ->whereDate('cv_signed_date', '<', $periodEnd->toDateString())
            ->whereRaw('LOWER(COALESCE(stage_name, \'\')) <> ?', ['cerrada perdida']);
    }

    /** @return array<string, mixed>|null */
    private function responsibleContactCenterCommission(CarbonImmutable $selectedMonth, bool $includeDetails): ?array
    {
        if ($selectedMonth->lt(CarbonImmutable::parse(self::RESPONSIBLE_CONTACT_CENTER_EFFECTIVE_FROM))) {
            return null;
        }

        $periodStart = $selectedMonth->startOfMonth();
        $periodEnd = $periodStart->addMonth();
        $referenceStart = $periodStart->subYearNoOverflow();
        $referenceEnd = $referenceStart->addMonth();
        $coverage = $this->sourceReadiness->historicalFullMonthCoverageState(
            'salesforce_opportunities',
            'Oportunidades del mes de referencia Contact Center',
            $referenceStart,
        );

        $currentDeliveries = $this->deliveryOpportunities
            ->forPeriod($periodStart, $periodEnd, self::RESPONSIBLE_DELIVERY_COLUMNS)
            ->get()->unique('salesforce_id')->values();
        $referenceDeliveries = $this->deliveryOpportunities
            ->forPeriod($referenceStart, $referenceEnd, self::RESPONSIBLE_DELIVERY_COLUMNS)
            ->get()->unique('salesforce_id')->values();
        $actual = $currentDeliveries->count();
        $reference = $referenceDeliveries->count();
        $issues = [];

        if (($coverage['blocking'] ?? true) === true) {
            $issues[] = (string) ($coverage['message'] ?? 'No existe cobertura verificable del mes de referencia.');
        }
        if ($reference === 0) {
            $issues[] = 'El mes de referencia no tiene entregas elegibles; la comisión de Responsable Contact Center no es certificable.';
        }

        $formula = $issues === [] ? $this->responsibleCommissionFormula($actual, $reference) : [
            'compliance_percent' => null,
            'bracket' => 'No certificable',
            'additional_deliveries' => 0,
            'base_amount' => 0.0,
            'additional_amount' => 0.0,
            'final_total' => 0.0,
        ];

        return [
            'responsible_name' => self::RESPONSIBLE_CONTACT_CENTER_NAME,
            'month' => $periodStart->format('Y-m'),
            'reference_month' => $referenceStart->format('Y-m'),
            'actual_deliveries' => $actual,
            'reference_deliveries' => $reference,
            'reference_coverage' => $coverage,
            'certifiable' => $issues === [],
            'issues' => $issues,
            ...$formula,
            'actual_delivery_details' => $includeDetails ? $this->responsibleDeliveryDetails($currentDeliveries) : [],
            'reference_delivery_details' => $includeDetails ? $this->responsibleDeliveryDetails($referenceDeliveries) : [],
        ];
    }

    /** @return array{compliance_percent: float, bracket: string, additional_deliveries: int, base_amount: float, additional_amount: float, final_total: float} */
    private function responsibleCommissionFormula(int $actual, int $reference): array
    {
        // Cross multiplication keeps the 90% threshold deterministic.
        if ($actual * 100 < $reference * 90) {
            return ['compliance_percent' => round(($actual / $reference) * 100, 2), 'bracket' => '< 90 %', 'additional_deliveries' => 0, 'base_amount' => 0.0, 'additional_amount' => 0.0, 'final_total' => 0.0];
        }
        if ($actual < $reference) {
            return ['compliance_percent' => round(($actual / $reference) * 100, 2), 'bracket' => '90 % - 99,99 %', 'additional_deliveries' => 0, 'base_amount' => 350.0, 'additional_amount' => 0.0, 'final_total' => 350.0];
        }

        $additionalDeliveries = $actual - $reference;
        $additionalAmount = $additionalDeliveries * 5.0;

        return ['compliance_percent' => round(($actual / $reference) * 100, 2), 'bracket' => '>= 100 %', 'additional_deliveries' => $additionalDeliveries, 'base_amount' => 500.0, 'additional_amount' => $additionalAmount, 'final_total' => 500.0 + $additionalAmount];
    }

    /** @param Collection<int, SalesforceOpportunity> $opportunities
     *  @return array<int, array<string, string|bool|null>> */
    private function responsibleDeliveryDetails(Collection $opportunities): array
    {
        return $opportunities->map(function (SalesforceOpportunity $opportunity): array {
            $isFacilitea = str_contains($this->normalizedText($opportunity->name), 'facilitea');

            return [
                'opportunity_id' => $opportunity->salesforce_id,
                'opportunity_name' => $opportunity->name,
                'contract_signed_date' => $opportunity->cv_signed_date?->toDateString(),
                'record_type_name' => $opportunity->record_type_name,
                'stage_name' => $opportunity->stage_name,
                'owner_name' => $opportunity->owner_name,
                'delegation' => $opportunity->delivery_store ?: $opportunity->owner_delegation,
                'is_facilitea' => $isFacilitea,
                'inclusion_reason' => $isFacilitea ? 'Facilitea con contrato CV firmado' : 'Tipo '.($opportunity->record_type_name ?: 'Venta/Cambio').' con contrato CV firmado',
            ];
        })->sortBy('opportunity_id')->values()->all();
    }

    private function contactCenterAgentName(?string $rawValue): ?string
    {
        $normalized = $this->normalizedText($rawValue);

        if ($normalized === '') {
            return null;
        }

        foreach (self::CONTACT_CENTER_AGENT_ALIASES as $alias => $canonical) {
            if ($normalized === $alias || str_contains($normalized, $alias)) {
                return $canonical;
            }
        }

        return null;
    }

    private function agentKeyFromName(string $agentName): string
    {
        return $this->normalizedText($agentName) ?: 'sin-agente';
    }

    private function opportunityAgentName(SalesforceOpportunity $opportunity): ?string
    {
        $rawAgent = $this->payloadValue($opportunity, 'Captador_de_cita__r.Name')
            ?: $this->payloadValue($opportunity, 'Captador_de_cita__c')
            ?: $this->payloadValue($opportunity, 'Captador__c');

        return $this->contactCenterAgentName((string) $rawAgent);
    }

    private function opportunityCaptureDate(SalesforceOpportunity $opportunity): ?string
    {
        $rawDate = $this->payloadValue($opportunity, 'Fecha_captador__c');

        if (! filled($rawDate)) {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $rawDate)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function dateWithinRange(?string $date, CarbonImmutable $rangeStart, CarbonImmutable $rangeEnd): bool
    {
        return $date !== null
            && $date >= $rangeStart->toDateString()
            && $date < $rangeEnd->toDateString();
    }

    private function opportunityHasOutcomeBeforeClosure(
        SalesforceOpportunity $opportunity,
        ?string $captureDate,
        CarbonImmutable $closureEnd
    ): bool {
        if ($captureDate === null) {
            return false;
        }

        return collect([
            optional($opportunity->created_date)?->toDateString(),
            (bool) $opportunity->reservation ? optional($opportunity->reservation_date)?->toDateString() : null,
            (bool) $opportunity->cv_signed ? optional($opportunity->cv_signed_date)?->toDateString() : null,
        ])->filter()
            ->contains(function (string $date) use ($captureDate, $closureEnd): bool {
                return $date >= $captureDate && $date < $closureEnd->toDateString();
            });
    }

    private function reservationCountsForOpportunity(
        SalesforceOpportunity $opportunity,
        ?string $captureDate,
        CarbonImmutable $closureEnd
    ): bool {
        $reservationDate = optional($opportunity->reservation_date)?->toDateString();

        return (bool) $opportunity->reservation
            && $captureDate !== null
            && $reservationDate !== null
            && $reservationDate >= $captureDate
            && $reservationDate < $closureEnd->toDateString();
    }

    private function opportunityOutcomeLabel(SalesforceOpportunity $opportunity, ?string $captureDate): string
    {
        $reservationDate = optional($opportunity->reservation_date)?->toDateString();
        $saleDate = optional($opportunity->cv_signed_date)?->toDateString();
        $createdDate = optional($opportunity->created_date)?->toDateString();

        if ($reservationDate !== null && $reservationDate >= (string) $captureDate) {
            return 'Reserva posterior a la cita';
        }

        if ($saleDate !== null && $saleDate >= (string) $captureDate) {
            return 'Contrato firmado posterior a la cita';
        }

        if ($createdDate !== null && $createdDate >= (string) $captureDate) {
            return 'Oportunidad creada posterior a la cita';
        }

        return 'Captador directo en la oportunidad';
    }

    private function linkedOpportunitiesForAppointment(
        SalesforceLead $appointment,
        Collection $opportunitiesById,
        array $opportunitiesByPhone,
        CarbonImmutable $closureEnd
    ): Collection {
        $records = collect();

        if (filled($appointment->converted_opportunity_id)) {
            $record = $opportunitiesById->get($appointment->converted_opportunity_id);

            if ($record !== null) {
                $records->push($record);
            }
        }

        foreach ($this->phonesForLead($appointment) as $phone) {
            foreach ($opportunitiesByPhone[$phone] ?? [] as $record) {
                $records->push($record);
            }
        }

        $captureDate = optional($appointment->appointment_capture_date)?->toDateString();

        return $records
            ->filter(function (SalesforceOpportunity $opportunity) use ($captureDate, $closureEnd): bool {
                if (! $this->opportunityHasOutcomeAfterDate($opportunity, $captureDate)) {
                    return false;
                }

                return $this->opportunityRelevantBeforeClosure($opportunity, $closureEnd);
            })
            ->unique('salesforce_id')
            ->values();
    }

    private function bestOutcomeForAppointment(SalesforceLead $appointment, Collection $linkedOpportunities): ?array
    {
        if ($linkedOpportunities->isEmpty()) {
            return null;
        }

        $convertedOpportunityId = (string) ($appointment->converted_opportunity_id ?? '');
        $captureDate = optional($appointment->appointment_capture_date)?->toDateString();

        return $linkedOpportunities
            ->map(function (SalesforceOpportunity $opportunity) use ($convertedOpportunityId, $captureDate): array {
                $createdDate = optional($opportunity->created_date)?->toDateString();
                $reservationDate = optional($opportunity->reservation_date)?->toDateString();
                $saleDate = optional($opportunity->cv_signed_date)?->toDateString();
                $firstEventDate = collect([$createdDate, $reservationDate, $saleDate])
                    ->filter()
                    ->sort()
                    ->first();

                return [
                    'opportunity' => $opportunity,
                    'has_reservation' => (bool) $opportunity->reservation,
                    'link_origin' => $this->bestOutcomeLabel($opportunity, $captureDate),
                    'score' => [
                        'direct_match' => $convertedOpportunityId !== '' && $opportunity->salesforce_id === $convertedOpportunityId ? 1 : 0,
                        'reservation' => (bool) $opportunity->reservation ? 1 : 0,
                        'sale' => (bool) $opportunity->cv_signed ? 1 : 0,
                        'event_date' => $firstEventDate ?? '9999-12-31',
                    ],
                ];
            })
            ->sort(function (array $a, array $b): int {
                return [$b['score']['direct_match'], $b['score']['reservation'], $b['score']['sale'], $a['score']['event_date']]
                    <=> [$a['score']['direct_match'], $a['score']['reservation'], $a['score']['sale'], $b['score']['event_date']];
            })
            ->first();
    }

    private function bestAppointmentForSale(
        SalesforceOpportunity $sale,
        array $appointmentsByOpportunity,
        array $appointmentsByPhone,
        CarbonImmutable $selectedMonth
    ): array {
        $candidates = collect();

        foreach ($appointmentsByOpportunity[$sale->salesforce_id] ?? [] as $lead) {
            $candidates->push($lead);
        }

        foreach ($this->phonesForOpportunity($sale) as $phone) {
            foreach ($appointmentsByPhone[$phone] ?? [] as $lead) {
                $candidates->push($lead);
            }
        }

        $saleDate = optional($sale->cv_signed_date)?->toDateString();

        $ranked = $candidates
            ->unique('salesforce_id')
            ->filter(fn (SalesforceLead $lead): bool => optional($lead->appointment_capture_date)?->toDateString() !== null)
            ->filter(fn (SalesforceLead $lead): bool => optional($lead->appointment_capture_date)?->toDateString() <= $saleDate)
            ->map(function (SalesforceLead $lead) use ($sale, $selectedMonth): array {
                $captureDate = optional($lead->appointment_capture_date)?->toDateString();
                $directMatch = filled($lead->converted_opportunity_id) && $lead->converted_opportunity_id === $sale->salesforce_id;
                $sameMonth = optional($lead->appointment_capture_date)?->startOfMonth()?->equalTo($selectedMonth) ?? false;

                return [
                    'lead' => $lead,
                    'reason' => $directMatch
                        ? 'Atribucion por ConvertedOpportunityId'
                        : ($sameMonth ? 'Atribucion por telefono y cita del mismo mes' : 'Atribucion por telefono y cita previa'),
                    'score' => [
                        'same_month' => $sameMonth ? 1 : 0,
                        'direct_match' => $directMatch ? 1 : 0,
                        'capture_date' => $captureDate ?? '0000-00-00',
                    ],
                ];
            })
            ->sort(function (array $a, array $b): int {
                return [$b['score']['same_month'], $b['score']['direct_match'], $b['score']['capture_date']]
                    <=> [$a['score']['same_month'], $a['score']['direct_match'], $a['score']['capture_date']];
            })
            ->values();

        if ($ranked->isEmpty()) {
            return [
                'lead' => null,
                'reason' => 'Sin cita previa imputable',
                'ambiguous' => false,
            ];
        }

        $top = $ranked->first();
        $second = $ranked->get(1);
        $ambiguous = $second !== null
            && $top['score'] === $second['score']
            && $this->agentKey($top['lead']) !== $this->agentKey($second['lead']);

        return [
            'lead' => $top['lead'],
            'reason' => $top['reason'],
            'ambiguous' => $ambiguous,
        ];
    }

    private function ensureRow(array &$rows, string $agentKey, string $agentName): void
    {
        if (isset($rows[$agentKey])) {
            return;
        }

        $rows[$agentKey] = [
            'agent_key' => $agentKey,
            'agent_name' => $agentName,
            'appointment_count' => 0,
            'opportunity_count' => 0,
            'reservation_count' => 0,
            'sales_count' => 0,
            'show_count' => 0,
            'details' => [
                'appointments' => [],
                'opportunities' => [],
                'reservations' => [],
                'sales' => [],
                'incidents' => [],
            ],
        ];
    }

    private function indexOpportunitiesByPhone(Collection $opportunities): array
    {
        $index = [];

        foreach ($opportunities as $opportunity) {
            foreach ($this->phonesForOpportunity($opportunity) as $phone) {
                $index[$phone] ??= [];
                $index[$phone][] = $opportunity;
            }
        }

        return $index;
    }

    private function indexAppointmentsByConvertedOpportunity(Collection $appointments): array
    {
        $index = [];

        foreach ($appointments as $appointment) {
            if (blank($appointment->converted_opportunity_id)) {
                continue;
            }

            $index[$appointment->converted_opportunity_id] ??= [];
            $index[$appointment->converted_opportunity_id][] = $appointment;
        }

        return $index;
    }

    private function indexAppointmentsByPhone(Collection $appointments): array
    {
        $index = [];

        foreach ($appointments as $appointment) {
            foreach ($this->phonesForLead($appointment) as $phone) {
                $index[$phone] ??= [];
                $index[$phone][] = $appointment;
            }
        }

        return $index;
    }

    private function phonesForLead(SalesforceLead $lead): array
    {
        return collect([
            $lead->phone,
            $lead->mobile_phone,
            data_get($lead->raw_payload, 'Phone'),
            data_get($lead->raw_payload, 'MobilePhone'),
        ])->map(fn (mixed $value) => $this->normalizePhone($value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function phonesForOpportunity(SalesforceOpportunity $opportunity): array
    {
        return collect([
            $opportunity->account_phone,
            data_get($opportunity->raw_payload, 'Account.Phone'),
            data_get($opportunity->raw_payload, 'Phone'),
        ])->map(fn (mixed $value) => $this->normalizePhone($value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function payloadValue(SalesforceOpportunity $opportunity, string $path): mixed
    {
        $payload = is_array($opportunity->raw_payload) ? $opportunity->raw_payload : [];

        if ($payload === []) {
            return null;
        }

        $segments = explode('.', $path);
        $current = $payload;

        foreach ($segments as $segment) {
            if (! is_array($current)) {
                return null;
            }

            $matchedKey = null;

            foreach ($current as $key => $value) {
                if (Str::lower((string) $key) === Str::lower($segment)) {
                    $matchedKey = $key;
                    break;
                }
            }

            if ($matchedKey === null) {
                return null;
            }

            $current = $current[$matchedKey];
        }

        return $current;
    }

    private function opportunityHasOutcomeAfterDate(SalesforceOpportunity $opportunity, ?string $captureDate): bool
    {
        if ($captureDate === null) {
            return false;
        }

        return collect([
            optional($opportunity->created_date)?->toDateString(),
            (bool) $opportunity->reservation ? optional($opportunity->reservation_date)?->toDateString() : null,
            (bool) $opportunity->cv_signed ? optional($opportunity->cv_signed_date)?->toDateString() : null,
        ])->filter()
            ->contains(fn (string $date): bool => $date >= $captureDate);
    }

    private function opportunityRelevantBeforeClosure(SalesforceOpportunity $opportunity, CarbonImmutable $closureEnd): bool
    {
        $cutoffDate = $closureEnd->toDateString();

        return collect([
            optional($opportunity->created_date)?->toDateString(),
            optional($opportunity->reservation_date)?->toDateString(),
            optional($opportunity->cv_signed_date)?->toDateString(),
        ])->filter()
            ->contains(fn (string $date): bool => $date < $cutoffDate);
    }

    private function bestOutcomeLabel(SalesforceOpportunity $opportunity, ?string $captureDate): string
    {
        $reservationDate = optional($opportunity->reservation_date)?->toDateString();
        $saleDate = optional($opportunity->cv_signed_date)?->toDateString();
        $createdDate = optional($opportunity->created_date)?->toDateString();

        if ($reservationDate !== null && $reservationDate >= (string) $captureDate) {
            return 'Reserva posterior a la cita';
        }

        if ($saleDate !== null && $saleDate >= (string) $captureDate) {
            return 'Contrato firmado posterior a la cita';
        }

        return 'Oportunidad creada posterior a la cita';
    }

    private function appointmentReason(SalesforceLead $lead): string
    {
        return match (true) {
            (bool) $lead->appointment_call && (bool) $lead->appointment_store => 'Cita llamada y cita tienda',
            (bool) $lead->appointment_store => 'Cita tienda',
            default => 'Cita llamada',
        };
    }

    private function agentKey(SalesforceLead $lead): string
    {
        $raw = $lead->appointment_setter_id ?: $lead->appointment_setter_name;

        return $this->normalizedText($raw) ?: 'sin-agente';
    }

    private function agentName(SalesforceLead $lead): string
    {
        return $this->displayText($lead->appointment_setter_name ?: $lead->appointment_setter_id ?: 'Sin agente');
    }

    private function normalizedText(?string $value): string
    {
        return trim(Str::lower(Str::ascii(preg_replace('/\s+/', ' ', (string) $value))));
    }

    private function displayText(?string $value): string
    {
        $text = trim(preg_replace('/\s+/', ' ', (string) $value));

        return $text !== '' ? $text : 'Sin dato';
    }

    private function normalizePhone(mixed $value): ?string
    {
        $value = preg_replace('/\D+/', '', (string) $value);
        $value = preg_replace('/^34(?=\d{9}$)/', '', $value ?? '');

        return $value !== '' ? $value : null;
    }

    private function divide(int|float $numerator, int|float $denominator): float
    {
        if ((float) $denominator === 0.0) {
            return 0.0;
        }

        return round((float) $numerator / (float) $denominator, 6);
    }

    private function volumeBonus(int $salesCount): float
    {
        return match (true) {
            $salesCount >= 20 => 500.0,
            $salesCount >= 15 => 250.0,
            $salesCount >= 10 => 100.0,
            default => 0.0,
        };
    }

    private function resolveMonth(?string $month): array
    {
        $context = $this->monthResolver->resolveWithContext($month);

        return [$context['month'], $context['warning']];
    }

    private function closureEndExclusive(CarbonImmutable $selectedMonth): CarbonImmutable
    {
        return $selectedMonth->addMonth()->setDay(self::CLOSURE_DAY)->addDay()->startOfDay();
    }

    private function blockingIssues(): array
    {
        $issues = [];

        if (! Schema::hasTable('salesforce_leads')) {
            $issues[] = 'La tabla local salesforce_leads no existe todavia.';
        }

        if (! Schema::hasTable('salesforce_opportunities')) {
            $issues[] = 'La tabla local salesforce_opportunities no existe todavia.';
        }

        foreach ([
            'salesforce_id',
            'name',
            'appointment_setter_name',
            'appointment_capture_date',
            'appointment_call',
            'appointment_store',
            'appointment_attended_status',
            'phone',
            'mobile_phone',
            'converted_opportunity_id',
            'portal_text',
        ] as $column) {
            if (Schema::hasTable('salesforce_leads') && ! Schema::hasColumn('salesforce_leads', $column)) {
                $issues[] = "Falta la columna local salesforce_leads.{$column}. Ejecuta migrate y re-sincroniza leads.";
            }
        }

        foreach ([
            'salesforce_id',
            'name',
            'created_date',
            'stage_name',
            'record_type_name',
            'owner_name',
            'owner_delegation',
            'account_phone',
            'account_name',
            'vehicle_plate',
            'portal_resolved',
            'portal_original',
            'reservation',
            'reservation_date',
            'cv_signed',
            'cv_signed_date',
            'raw_payload',
        ] as $column) {
            if (Schema::hasTable('salesforce_opportunities') && ! Schema::hasColumn('salesforce_opportunities', $column)) {
                $issues[] = "Falta la columna local salesforce_opportunities.{$column}.";
            }
        }

        return $issues;
    }
}
