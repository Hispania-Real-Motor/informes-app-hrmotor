<?php

namespace App\Services\Reports\CommercialCommissions;

use App\Models\SalesforceDelegationManagerHistory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class DelegationManagerHistoryResolver
{
    private const TABLE = 'salesforce_delegation_manager_history';

    private const REQUIRED_COLUMNS = [
        'delegation_key',
        'delegation_name',
        'manager_salesforce_user_id',
        'manager_name',
        'effective_at',
        'coverage_from',
        'coverage_to',
        'history_verified',
    ];

    public function __construct(private readonly CommercialCommissionFormulaConfigService $formulaConfig) {}

    public function resolve(CarbonImmutable $month, array $delegationLabels): array
    {
        if ($month->lt(CarbonImmutable::parse('2026-07-01'))) {
            return [];
        }

        $keys = collect($delegationLabels)->map(fn (string $label): string => $this->formulaConfig->delegationKey($label))->unique()->values();
        $schemaIssue = $this->schemaIssue();
        if ($schemaIssue !== null) {
            Log::warning('Historico de jefes de tienda no disponible para resolver comisiones.', [
                'integration' => 'delegation_manager_history',
                'status' => $schemaIssue,
            ]);

            return $this->unverifiedResults($month, $delegationLabels, $keys);
        }

        $monthEnd = $month->addMonth();
        $rows = SalesforceDelegationManagerHistory::query()
            ->whereIn('delegation_key', $keys)
            ->where('coverage_from', '<', $monthEnd)
            ->where(fn ($query) => $query->whereNull('coverage_to')->orWhere('coverage_to', '>', $month))
            ->orderBy('effective_at')
            ->get()
            ->groupBy('delegation_key');

        return $keys->mapWithKeys(function (string $key) use ($rows, $month, $monthEnd): array {
            /** @var Collection<int, SalesforceDelegationManagerHistory> $timeline */
            $timeline = $rows->get($key, collect());
            $effective = $timeline->filter(fn ($row): bool => $row->coverage_from->lt($monthEnd)
                && ($row->coverage_to === null || $row->coverage_to->gt($month)))->values();
            $closingInstant = $monthEnd->subSecond();
            $closing = $effective
                ->filter(fn ($row): bool => filled($row->manager_salesforce_user_id)
                    && $row->coverage_from->lte($closingInstant)
                    && ($row->coverage_to === null || $row->coverage_to->gt($closingInstant)))
                ->sortByDesc('effective_at')
                ->first();
            $closingVerified = $closing !== null;
            $rotationVerified = $this->coversWholePeriod($effective, $month, $monthEnd);
            $distinct = $effective->pluck('manager_salesforce_user_id')->filter()->unique()->count();
            $label = $closing?->delegation_name ?? $effective->last()?->delegation_name ?? $key;
            $newPlan = $month->greaterThanOrEqualTo(CarbonImmutable::parse(StoreManagerCommissionPlanCalculator::EFFECTIVE_FROM));
            $alert = ($newPlan ? $distinct >= 2 : $distinct > 2)
                ? sprintf(
                    '%s ha tenido %d jefes de tienda demostrados durante %s. Revisar la asignación de la comisión.',
                    $label,
                    $distinct,
                    $month->locale('es')->translatedFormat('F \d\e Y'),
                )
                : null;

            return [$key => [
                'store_manager_salesforce_user_id' => $closingVerified ? $closing->manager_salesforce_user_id : null,
                'store_manager_name' => $closingVerified ? $closing->manager_name : null,
                'store_manager_history_status' => $closingVerified ? 'verified' : 'unverified',
                'store_manager_closing_status' => $closingVerified ? 'verified' : 'unverified',
                'store_manager_rotation_history_status' => $rotationVerified ? 'verified' : 'unverified',
                'store_manager_distinct_count' => $distinct,
                'store_manager_alert' => $alert,
                'store_manager_evidence_count' => $effective->count(),
            ]];
        })->all();
    }

    /**
     * Returns every verified manager covering any part of the month. This is
     * deliberately independent from opportunity owners: a manager with no
     * operation in the period must still receive the correct exemption.
     */
    public function verifiedManagerIds(CarbonImmutable $month): array
    {
        if ($month->lt(CarbonImmutable::parse('2026-10-01')) || $this->schemaIssue() !== null) {
            return [];
        }

        return SalesforceDelegationManagerHistory::query()
            ->where('history_verified', true)
            ->whereNotNull('manager_salesforce_user_id')
            ->where('coverage_from', '<', $month->addMonth())
            ->where(fn ($query) => $query->whereNull('coverage_to')->orWhere('coverage_to', '>', $month))
            ->distinct()
            ->pluck('manager_salesforce_user_id')
            ->filter()
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    private function schemaIssue(): ?string
    {
        if (! Schema::hasTable(self::TABLE)) {
            return 'table_missing';
        }

        $columns = array_map('strtolower', Schema::getColumnListing(self::TABLE));

        return array_diff(self::REQUIRED_COLUMNS, $columns) === [] ? null : 'schema_upgrade_pending';
    }

    private function unverifiedResults(CarbonImmutable $month, array $delegationLabels, Collection $keys): array
    {
        $labelsByKey = collect($delegationLabels)->mapWithKeys(
            fn (string $label): array => [$this->formulaConfig->delegationKey($label) => $label]
        );

        return $keys->mapWithKeys(function (string $key) use ($labelsByKey): array {
            $label = $labelsByKey->get($key, $key);

            return [$key => [
                'store_manager_salesforce_user_id' => null,
                'store_manager_name' => null,
                'store_manager_history_status' => 'unverified',
                'store_manager_closing_status' => 'unverified',
                'store_manager_rotation_history_status' => 'unverified',
                'store_manager_distinct_count' => null,
                'store_manager_alert' => null,
                'store_manager_evidence_count' => 0,
            ]];
        })->all();
    }

    private function coversWholePeriod(Collection $rows, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        $cursor = $start;

        while ($cursor->lt($end)) {
            $covering = $rows->filter(fn ($row): bool => $row->history_verified
                && filled($row->manager_salesforce_user_id)
                && $row->coverage_from->lte($cursor)
                && ($row->coverage_to === null || $row->coverage_to->gt($cursor)))
                ->sortByDesc(fn ($row): int => $row->coverage_to?->getTimestamp() ?? PHP_INT_MAX)
                ->first();
            if ($covering === null) {
                return false;
            }

            $next = $covering->coverage_to ?? $end;
            if ($next->lte($cursor)) {
                return false;
            }
            $cursor = $next->min($end);
        }

        return true;
    }
}
