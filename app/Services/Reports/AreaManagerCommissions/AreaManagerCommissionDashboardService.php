<?php

namespace App\Services\Reports\AreaManagerCommissions;

use App\Models\SalesforceOpportunity;
use App\Models\SalesforceUser;
use App\Services\Reports\CommercialCommissions\CommercialCommissionFormulaConfigService;
use App\Services\Reports\CommercialCommissions\CommissionMonthResolver;
use App\Services\Reports\CommercialCommissions\EligibleDeliveryOpportunityQuery;
use App\Services\Reports\Leads\LeadDelegationNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AreaManagerCommissionDashboardService
{
    private const COMMERCIAL_DIRECTOR_COMMISSION_RATE = 0.40;

    private const COMMERCIAL_DIRECTOR_NAME = 'Oscar Ortega';

    private const COMMERCIAL_DIRECTOR_SALESFORCE_USER_ID = '0057R00000B2SGg';

    private const OPPORTUNITY_COLUMNS = [
        'salesforce_id',
        'name',
        'owner_id',
        'stage_name',
        'record_type_name',
        'owner_delegation',
        'delivery_store',
        'beneficio_financiacion_comercial',
        'garantia_total',
        'cv_signed',
        'cv_signed_date',
    ];

    public function __construct(
        private readonly CommercialCommissionFormulaConfigService $formulaConfig,
        private readonly CommissionMonthResolver $monthResolver,
        private readonly LeadDelegationNormalizer $delegationNormalizer,
        private readonly EligibleDeliveryOpportunityQuery $deliveryOpportunities,
    ) {}

    public function build(?string $month, ?string $zoneLabel = null): array
    {
        $selectedMonth = $this->monthResolver->resolve($month);
        $periodStart = $selectedMonth->startOfMonth();
        $periodEnd = $periodStart->addMonth();
        $settings = $this->formulaConfig->forMonth($selectedMonth);
        $issues = $this->blockingIssues();

        if ($issues !== []) {
            return [
                'ready' => false,
                'month' => $selectedMonth->format('Y-m'),
                'month_label' => $selectedMonth->translatedFormat('F Y'),
                'issues' => $issues,
                'warnings' => [],
                'diagnostics' => $this->emptyDiagnostics(),
                'summary_rows' => [],
                'commercial_director' => null,
                'global_incidents' => [],
            ];
        }

        $deliveryOperations = $this->monthlyDeliveryOperations($periodStart, $periodEnd)->get();
        $purchaseOperations = $this->monthlyPurchaseOperations($periodStart, $periodEnd)->get();
        $ownerDelegations = $this->ownerDelegationsByOwnerId($deliveryOperations->merge($purchaseOperations));
        $assignmentMap = collect($settings['area_manager']['assignments'] ?? []);
        $managerDefinitions = collect($this->formulaConfig->areaManagerDefinitions())->keyBy('key');

        $deliveryStats = $this->buildDeliveryStatsByDelegation($deliveryOperations, $ownerDelegations);
        $purchaseStats = $this->buildPurchaseStatsByDelegation($purchaseOperations, $ownerDelegations);
        $allDelegationLabels = $this->delegationUniverse($assignmentMap, $deliveryStats, $purchaseStats);
        $delegationPayloads = $this->buildDelegationPayloads(
            $allDelegationLabels,
            $assignmentMap,
            $deliveryStats,
            $purchaseStats,
            $settings
        );

        if (filled($zoneLabel)) {
            $delegationPayloads = $delegationPayloads
                ->filter(fn (array $payload): bool => $this->belongsToZone(
                    $payload['delegation_name'] ?? null,
                    (string) $zoneLabel,
                ))
                ->values();
        }

        $managerRows = $managerDefinitions
            ->map(function (array $manager) use ($delegationPayloads, $settings): array {
                $managerDelegations = $delegationPayloads
                    ->filter(fn (array $payload) => ($payload['manager_key'] ?? '') === $manager['key'] && ($payload['active'] ?? true));

                return $this->buildManagerRow($manager['key'], $manager['label'], $managerDelegations, $settings);
            })
            ->when(filled($zoneLabel), fn (Collection $rows): Collection => $rows->filter(
                fn (array $row): bool => (int) ($row['delegations_count'] ?? 0) > 0
            ))
            ->sortByDesc('automatic_total')
            ->values()
            ->all();
        $commercialDirector = filled($zoneLabel)
            ? null
            : $this->buildCommercialDirector($managerRows);

        $globalIncidents = $delegationPayloads
            ->flatMap(function (array $payload) use ($managerDefinitions): array {
                return collect($payload['incidents'] ?? [])
                    ->map(function (array $incident) use ($payload, $managerDefinitions): array {
                        $managerKey = (string) ($payload['manager_key'] ?? '');

                        return [
                            ...$incident,
                            'manager_name' => (string) ($managerDefinitions->get($managerKey)['label'] ?? '-'),
                        ];
                    })
                    ->all();
            })
            ->values()
            ->all();

        return [
            'ready' => true,
            'month' => $selectedMonth->format('Y-m'),
            'month_label' => $selectedMonth->translatedFormat('F Y'),
            'issues' => [],
            'warnings' => [
                ...($globalIncidents === [] ? [] : ['Revisa las incidencias globales para delegaciones sin manager o sin objetivos configurados.']),
            ],
            'diagnostics' => [
                'managers_count' => count($managerRows),
                'delegations_count' => $delegationPayloads->filter(fn (array $payload) => ($payload['active'] ?? true) && ($payload['manager_key'] ?? '') !== '')->count(),
                'configured_delegations_count' => filled($zoneLabel) ? $delegationPayloads->count() : $assignmentMap->count(),
                'delivery_operations_count' => filled($zoneLabel)
                    ? (int) $delegationPayloads->sum(fn (array $payload): int => count($payload['details']['deliveries'] ?? []))
                    : $deliveryOperations->count(),
                'purchase_operations_count' => filled($zoneLabel)
                    ? (int) $delegationPayloads->sum(fn (array $payload): int => count($payload['details']['purchases'] ?? []))
                    : $purchaseOperations->count(),
                'incidents_count' => count($globalIncidents),
            ],
            'summary_rows' => $managerRows,
            'commercial_director' => $commercialDirector,
            'global_incidents' => $globalIncidents,
        ];
    }

    /** @param array<int, array<string, mixed>> $managerRows */
    private function buildCommercialDirector(array $managerRows): array
    {
        $basisAmount = round((float) collect($managerRows)->sum('final_total'), 2);

        return [
            'salesforce_user_id' => self::COMMERCIAL_DIRECTOR_SALESFORCE_USER_ID,
            'name' => self::COMMERCIAL_DIRECTOR_NAME,
            'basis_amount' => $basisAmount,
            'commission_percent' => self::COMMERCIAL_DIRECTOR_COMMISSION_RATE * 100,
            'final_total' => round($basisAmount * self::COMMERCIAL_DIRECTOR_COMMISSION_RATE, 2),
        ];
    }

    private function belongsToZone(mixed $delegation, string $zoneLabel): bool
    {
        $normalized = $this->delegationNormalizer->normalize(is_string($delegation) ? $delegation : null);

        return ($normalized['zone'] ?? null) === $zoneLabel;
    }

    private function buildDeliveryStatsByDelegation(Collection $operations, Collection $ownerDelegations): Collection
    {
        return $operations
            ->map(function (SalesforceOpportunity $opportunity) use ($ownerDelegations): array {
                $delegation = $this->resolveAreaManagerDelegation($opportunity, $ownerDelegations);

                return [
                    'delegation' => $delegation,
                    'opportunity_id' => (string) $opportunity->salesforce_id,
                    'opportunity_name' => (string) $opportunity->name,
                    'record_type_name' => (string) $opportunity->record_type_name,
                    'contract_signed_date' => optional($opportunity->cv_signed_date)?->toDateString(),
                    'benefit' => max(0, (float) ($opportunity->beneficio_financiacion_comercial ?? 0)),
                    'guarantee' => max(0, (float) ($opportunity->garantia_total ?? 0)),
                ];
            })
            ->filter(fn (array $row) => $row['delegation'] !== '' && $this->formulaConfig->shouldIncludeDelegationLabel($row['delegation']))
            ->groupBy('delegation');
    }

    private function buildPurchaseStatsByDelegation(Collection $operations, Collection $ownerDelegations): Collection
    {
        return $operations
            ->map(function (SalesforceOpportunity $opportunity) use ($ownerDelegations): array {
                $delegation = $this->resolveAreaManagerDelegation($opportunity, $ownerDelegations);

                return [
                    'delegation' => $delegation,
                    'opportunity_id' => (string) $opportunity->salesforce_id,
                    'opportunity_name' => (string) $opportunity->name,
                    'record_type_name' => (string) $opportunity->record_type_name,
                    'contract_signed_date' => optional($opportunity->cv_signed_date)?->toDateString(),
                ];
            })
            ->filter(fn (array $row) => $row['delegation'] !== '' && $this->formulaConfig->shouldIncludeDelegationLabel($row['delegation']))
            ->groupBy('delegation');
    }

    private function delegationUniverse(Collection $assignmentMap, Collection $deliveryStats, Collection $purchaseStats): Collection
    {
        return collect($assignmentMap->map(fn (array $assignment) => (string) ($assignment['label'] ?? '')))
            ->merge($deliveryStats->keys())
            ->merge($purchaseStats->keys())
            ->map(fn (string $label): string => $this->formulaConfig->normalizeDelegationLabel($label))
            ->filter(fn (string $label) => $label !== '' && $this->formulaConfig->shouldIncludeDelegationLabel($label))
            ->unique(fn (string $label): string => $this->formulaConfig->delegationKey($label))
            ->sortBy(fn (string $label) => Str::of($label)->ascii()->lower()->toString())
            ->values();
    }

    private function buildDelegationPayloads(
        Collection $delegationLabels,
        Collection $assignmentMap,
        Collection $deliveryStats,
        Collection $purchaseStats,
        array $settings
    ): Collection {
        $bases = $settings['area_manager']['kpi_bases'] ?? [];

        return $delegationLabels->map(function (string $delegationLabel) use ($assignmentMap, $deliveryStats, $purchaseStats, $bases): array {
            $key = $this->formulaConfig->delegationKey($delegationLabel);
            $assignment = $assignmentMap->get($key, []);
            $deliveryRows = collect($deliveryStats->get($delegationLabel, collect()))->values();
            $purchaseRows = collect($purchaseStats->get($delegationLabel, collect()))->values();
            $actuals = [
                'deliveries' => (float) $deliveryRows->count(),
                'benefit' => round((float) $deliveryRows->sum('benefit'), 2),
                'guarantee' => round((float) $deliveryRows->sum('guarantee'), 2),
                'purchases' => (float) $purchaseRows->count(),
            ];
            $objectives = [
                'deliveries' => (float) data_get($assignment, 'objectives.deliveries', 0),
                'benefit' => (float) data_get($assignment, 'objectives.benefit', 0),
                'guarantee' => (float) data_get($assignment, 'objectives.guarantee', 0),
                'purchases' => (float) data_get($assignment, 'objectives.purchases', 0),
            ];
            $incidents = [];

            if (($assignment['manager_key'] ?? '') === '' && array_sum($actuals) > 0) {
                $incidents[] = [
                    'delegation_name' => $delegationLabel,
                    'manager_name' => '-',
                    'kpi' => '-',
                    'message' => 'Incidencia: delegacion con datos reales pero sin manager asignado.',
                ];
            }

            $kpiRows = collect(['deliveries', 'benefit', 'guarantee', 'purchases'])
                ->map(function (string $kpi) use ($delegationLabel, $assignment, $actuals, $objectives, $bases, &$incidents): array {
                    $objective = max(0, (float) ($objectives[$kpi] ?? 0));
                    $actual = max(0, (float) ($actuals[$kpi] ?? 0));
                    $rawPercent = $objective > 0 ? round(($actual / $objective) * 100, 2) : null;
                    $usedPercent = $rawPercent !== null ? (float) round($rawPercent) : null;
                    $qualified = $usedPercent !== null && $usedPercent >= 85.0;
                    $base = max(0, (float) ($bases[$kpi] ?? 0));
                    $preKeyCommission = $qualified ? round($base * ($usedPercent / 100), 2) : 0.0;
                    $reason = $qualified
                        ? 'Incluido: cumplimiento >= 85%'
                        : 'Excluido: cumplimiento < 85%';

                    if ($objective <= 0) {
                        $reason = 'Incidencia: objetivo a cero';
                        $incidents[] = [
                            'delegation_name' => $delegationLabel,
                            'manager_name' => $assignment['manager_key'] ?? '-',
                            'kpi' => $this->kpiLabel($kpi),
                            'message' => 'Incidencia: objetivo a cero.',
                        ];
                    }

                    return [
                        'delegation_name' => $delegationLabel,
                        'kpi_key' => $kpi,
                        'kpi_label' => $this->kpiLabel($kpi),
                        'objective' => $objective,
                        'actual' => $actual,
                        'compliance_percent_raw' => $rawPercent,
                        'compliance_percent_used' => $usedPercent,
                        'base_amount' => $base,
                        'qualified' => $qualified,
                        'pre_key_commission' => $preKeyCommission,
                        'reason' => $reason,
                    ];
                })
                ->keyBy('kpi_key')
                ->all();

            return [
                'delegation_key' => $key,
                'delegation_name' => $delegationLabel,
                'manager_key' => (string) ($assignment['manager_key'] ?? ''),
                'active' => (bool) ($assignment['active'] ?? true),
                'objectives' => $objectives,
                'actuals' => $actuals,
                'kpis' => $kpiRows,
                'details' => [
                    'deliveries' => $deliveryRows->all(),
                    'purchases' => $purchaseRows->all(),
                ],
                'incidents' => $incidents,
            ];
        });
    }

    private function buildManagerRow(string $managerKey, string $managerLabel, Collection $delegations, array $settings): array
    {
        $zoneKeys = $settings['area_manager']['zone_keys'] ?? [];
        $kpiSummaries = [];
        $detailRows = [];
        $managerIncidents = $delegations
            ->flatMap(fn (array $payload) => $payload['incidents'] ?? [])
            ->map(fn (array $incident) => [
                ...$incident,
                'manager_name' => $managerLabel,
            ])
            ->values()
            ->all();

        foreach (['deliveries', 'benefit', 'guarantee', 'purchases'] as $kpi) {
            $objectiveTotal = round((float) $delegations->sum(fn (array $payload) => (float) ($payload['objectives'][$kpi] ?? 0)), 2);
            $actualTotal = round((float) $delegations->sum(fn (array $payload) => (float) ($payload['actuals'][$kpi] ?? 0)), 2);
            $zonePercentRaw = $this->averageCompliancePercent($delegations, $kpi);
            $zonePercentUsed = $zonePercentRaw !== null ? (float) round($zonePercentRaw) : null;
            $zoneMultiplier = $this->zoneMultiplier($zonePercentUsed, $zoneKeys);
            $preKeyTotal = round((float) $delegations->sum(
                fn (array $payload) => (float) data_get($payload, "kpis.{$kpi}.pre_key_commission", 0)
            ), 2);
            $finalCommission = round($preKeyTotal * $zoneMultiplier, 2);

            $kpiSummaries[$kpi] = [
                'objective_total' => $objectiveTotal,
                'actual_total' => $actualTotal,
                'zone_percent_raw' => $zonePercentRaw,
                'zone_percent_used' => $zonePercentUsed,
                'zone_multiplier' => $zoneMultiplier,
                'pre_key_total' => $preKeyTotal,
                'final_commission' => $finalCommission,
            ];
        }

        foreach ($delegations as $delegation) {
            foreach (['deliveries', 'benefit', 'guarantee', 'purchases'] as $kpi) {
                $detailRows[] = [
                    'manager_name' => $managerLabel,
                    'delegation_name' => $delegation['delegation_name'],
                    'kpi_key' => $kpi,
                    'kpi_label' => $this->kpiLabel($kpi),
                    'objective' => (float) ($delegation['objectives'][$kpi] ?? 0),
                    'actual' => (float) ($delegation['actuals'][$kpi] ?? 0),
                    'compliance_percent_raw' => data_get($delegation, "kpis.{$kpi}.compliance_percent_raw"),
                    'compliance_percent_used' => data_get($delegation, "kpis.{$kpi}.compliance_percent_used"),
                    'base_amount' => (float) data_get($delegation, "kpis.{$kpi}.base_amount", 0),
                    'qualified' => (bool) data_get($delegation, "kpis.{$kpi}.qualified", false),
                    'pre_key_commission' => (float) data_get($delegation, "kpis.{$kpi}.pre_key_commission", 0),
                    'zone_percent_raw' => $kpiSummaries[$kpi]['zone_percent_raw'],
                    'zone_percent_used' => $kpiSummaries[$kpi]['zone_percent_used'],
                    'zone_multiplier' => $kpiSummaries[$kpi]['zone_multiplier'],
                    'final_commission' => null,
                    'reason' => (string) data_get($delegation, "kpis.{$kpi}.reason", ''),
                ];
            }
        }

        $automaticTotal = round(collect($kpiSummaries)->sum('final_commission'), 2);

        return [
            'manager_key' => $managerKey,
            'manager_name' => $managerLabel,
            'delegations_count' => $delegations->count(),
            'deliveries_objective' => $kpiSummaries['deliveries']['objective_total'],
            'deliveries_actual' => $kpiSummaries['deliveries']['actual_total'],
            'deliveries_zone_percent' => $kpiSummaries['deliveries']['zone_percent_raw'],
            'deliveries_zone_percent_used' => $kpiSummaries['deliveries']['zone_percent_used'],
            'deliveries_zone_key' => $kpiSummaries['deliveries']['zone_multiplier'],
            'deliveries_commission' => $kpiSummaries['deliveries']['final_commission'],
            'benefit_objective' => $kpiSummaries['benefit']['objective_total'],
            'benefit_actual' => $kpiSummaries['benefit']['actual_total'],
            'benefit_zone_percent' => $kpiSummaries['benefit']['zone_percent_raw'],
            'benefit_zone_percent_used' => $kpiSummaries['benefit']['zone_percent_used'],
            'benefit_zone_key' => $kpiSummaries['benefit']['zone_multiplier'],
            'benefit_commission' => $kpiSummaries['benefit']['final_commission'],
            'guarantee_objective' => $kpiSummaries['guarantee']['objective_total'],
            'guarantee_actual' => $kpiSummaries['guarantee']['actual_total'],
            'guarantee_zone_percent' => $kpiSummaries['guarantee']['zone_percent_raw'],
            'guarantee_zone_percent_used' => $kpiSummaries['guarantee']['zone_percent_used'],
            'guarantee_zone_key' => $kpiSummaries['guarantee']['zone_multiplier'],
            'guarantee_commission' => $kpiSummaries['guarantee']['final_commission'],
            'purchases_objective' => $kpiSummaries['purchases']['objective_total'],
            'purchases_actual' => $kpiSummaries['purchases']['actual_total'],
            'purchases_zone_percent' => $kpiSummaries['purchases']['zone_percent_raw'],
            'purchases_zone_percent_used' => $kpiSummaries['purchases']['zone_percent_used'],
            'purchases_zone_key' => $kpiSummaries['purchases']['zone_multiplier'],
            'purchases_commission' => $kpiSummaries['purchases']['final_commission'],
            'key_summary' => sprintf(
                'E %.2f / B %.2f / G %.2f / C %.2f',
                $kpiSummaries['deliveries']['zone_multiplier'],
                $kpiSummaries['benefit']['zone_multiplier'],
                $kpiSummaries['guarantee']['zone_multiplier'],
                $kpiSummaries['purchases']['zone_multiplier'],
            ),
            'automatic_total' => $automaticTotal,
            'manual_adjustment' => 0.0,
            'final_total' => $automaticTotal,
            'observations' => $managerIncidents === [] ? 'Sin incidencias' : 'Revisar incidencias',
            'review_state' => $managerIncidents === [] ? 'OK' : 'Revision',
            'kpi_summaries' => $kpiSummaries,
            'detail_rows' => $detailRows,
            'operation_details' => $delegations->map(fn (array $payload) => [
                'delegation_name' => $payload['delegation_name'],
                'deliveries' => $payload['details']['deliveries'] ?? [],
                'purchases' => $payload['details']['purchases'] ?? [],
            ])->values()->all(),
            'incidents' => $managerIncidents,
        ];
    }

    private function monthlyDeliveryOperations(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): Builder
    {
        return $this->deliveryOpportunities->forPeriod($periodStart, $periodEnd, self::OPPORTUNITY_COLUMNS);
    }

    private function monthlyPurchaseOperations(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): Builder
    {
        $query = SalesforceOpportunity::query()
            ->select(self::OPPORTUNITY_COLUMNS)
            ->where('cv_signed', true)
            ->whereDate('cv_signed_date', '>=', $periodStart->toDateString())
            ->whereDate('cv_signed_date', '<', $periodEnd->toDateString())
            ->whereRaw("LOWER(COALESCE(stage_name, '')) <> ?", ['cerrada perdida']);

        $this->applyRecordTypeFilter($query, ['tasacion', 'cambio']);

        return $query;
    }

    private function applyRecordTypeFilter(Builder $query, array $values): void
    {
        $query->where(function (Builder $builder) use ($values): void {
            foreach (array_values($values) as $index => $value) {
                $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';

                if ($value === 'tasacion') {
                    $builder->{$method}("LOWER(COALESCE(record_type_name, '')) LIKE ?", ['tas%']);

                    continue;
                }

                $builder->{$method}("LOWER(COALESCE(record_type_name, '')) = ?", [$value]);
            }
        });
    }

    private function zoneMultiplier(?float $zonePercentUsed, array $zoneKeys): float
    {
        if ($zonePercentUsed === null) {
            return 0.0;
        }

        foreach ($zoneKeys as $row) {
            if ($zonePercentUsed >= (float) ($row['min_percent'] ?? 0)) {
                return (float) ($row['multiplier'] ?? 0);
            }
        }

        return 0.0;
    }

    private function kpiLabel(string $kpi): string
    {
        return match ($kpi) {
            'deliveries' => 'Entregas',
            'benefit' => 'Beneficio',
            'guarantee' => 'Garantia Premium',
            'purchases' => 'Compras',
            default => $kpi,
        };
    }

    private function normalizeAreaManagerOwnerDelegation(mixed $value): string
    {
        $normalized = $this->formulaConfig->normalizeDelegationLabel($value);

        if ($normalized !== '') {
            return $normalized;
        }

        $rawUpper = Str::upper((string) $value);

        if (str_contains($rawUpper, 'LLI') && str_contains($rawUpper, 'VALL')) {
            return 'Llica de Valls';
        }

        $comparable = Str::of((string) $value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();

        if (str_contains($comparable, 'vall') && preg_match('/\blli[a-z]*\b/', $comparable) === 1) {
            return 'Llica de Valls';
        }

        return match ($comparable) {
            'llica de vall', 'llica', 'llica de vall barcelona' => 'Llica de Valls',
            default => '',
        };
    }

    private function resolveAreaManagerDelegation(SalesforceOpportunity $opportunity, Collection $ownerDelegations): string
    {
        $ownerId = (string) ($opportunity->owner_id ?? '');
        $ownerDelegation = $ownerId !== '' ? (string) ($ownerDelegations->get($ownerId) ?? '') : '';

        if ($ownerDelegation === '') {
            $ownerDelegation = $this->normalizeAreaManagerOwnerDelegation($opportunity->owner_delegation);
        }

        // Facilitea owned by General/Sin zona belongs to the actual delivery store.
        if (
            $this->isFaciliteaOperation($opportunity)
            && ! $this->formulaConfig->shouldIncludeDelegationLabel($ownerDelegation)
        ) {
            return $this->formulaConfig->deliveryDelegationLabel($opportunity->delivery_store);
        }

        if ($ownerDelegation !== '') {
            return $ownerDelegation;
        }

        return '';
    }

    private function ownerDelegationsByOwnerId(Collection $operations): Collection
    {
        $ownerIds = $operations
            ->pluck('owner_id')
            ->filter(fn (mixed $ownerId): bool => is_string($ownerId) && trim($ownerId) !== '')
            ->map(fn (string $ownerId): string => trim($ownerId))
            ->unique()
            ->values();

        if ($ownerIds->isEmpty()) {
            return collect();
        }

        return SalesforceUser::query()
            ->whereIn('salesforce_id', $ownerIds->all())
            ->get(['salesforce_id', 'user_delegation'])
            ->mapWithKeys(fn (SalesforceUser $user): array => [
                (string) $user->salesforce_id => $this->normalizeAreaManagerOwnerDelegation($user->user_delegation),
            ]);
    }

    private function averageCompliancePercent(Collection $delegations, string $kpi): ?float
    {
        $values = $delegations
            ->map(fn (array $payload): ?float => data_get($payload, "kpis.{$kpi}.compliance_percent_raw"))
            ->filter(fn (mixed $value): bool => $value !== null)
            ->map(fn (mixed $value): float => round((float) $value, 2))
            ->values();

        if ($values->isEmpty()) {
            return null;
        }

        return round((float) $values->avg(), 2);
    }

    private function isFaciliteaOperation(SalesforceOpportunity $opportunity): bool
    {
        return str_contains(
            Str::of((string) $opportunity->name)->lower()->toString(),
            'facilitea'
        );
    }

    private function blockingIssues(): array
    {
        if (! Schema::hasTable('salesforce_opportunities')) {
            return ['La tabla local salesforce_opportunities no existe todavia.'];
        }

        $missing = collect(self::OPPORTUNITY_COLUMNS)
            ->reject(fn (string $column) => Schema::hasColumn('salesforce_opportunities', $column))
            ->values()
            ->all();

        if ($missing !== []) {
            return ['Faltan columnas necesarias en salesforce_opportunities: '.implode(', ', $missing).'.'];
        }

        return [];
    }

    private function emptyDiagnostics(): array
    {
        return [
            'managers_count' => 0,
            'delegations_count' => 0,
            'configured_delegations_count' => 0,
            'delivery_operations_count' => 0,
            'purchase_operations_count' => 0,
            'incidents_count' => 0,
        ];
    }
}
