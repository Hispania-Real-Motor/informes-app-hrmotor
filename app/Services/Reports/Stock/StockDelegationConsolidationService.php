<?php

namespace App\Services\Reports\Stock;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class StockDelegationConsolidationService
{
    private const REFERENCE_TABLES = [
        'salesforce_vehicles',
        'stock_daily_snapshots',
        'salesforce_sale_snapshots',
        'stock_availability_alerts',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<int, array<string, mixed>>
     */
    public function inspect(array $rules): array
    {
        return collect($rules)
            ->map(fn (array $rule): array => $this->inspectRule($rule))
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<int, array<string, mixed>>
     */
    public function consolidate(array $rules): array
    {
        return DB::transaction(function () use ($rules): array {
            return collect($rules)
                ->map(fn (array $rule): array => $this->consolidateRule($rule))
                ->all();
        }, 3);
    }

    /** @return array<string, mixed> */
    private function inspectRule(array $rule): array
    {
        $delegations = $this->candidates($rule)->get();
        $capacities = $delegations->pluck('capacity_total')->filter(fn ($value): bool => $value !== null)->unique()->values();

        return [
            'canonical_name' => $rule['canonical_name'],
            'salesforce_id' => $rule['salesforce_id'],
            'candidate_ids' => $delegations->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            'candidate_count' => $delegations->count(),
            'capacity_values' => $capacities->map(fn ($value): int => (int) $value)->all(),
            'capacity_conflict' => $capacities->count() > 1,
            'already_consolidated' => $delegations->whereNull('merged_into_id')->count() === 1
                && $delegations->firstWhere('normalized_key', $rule['normalized_key'])?->salesforce_id === $rule['salesforce_id'],
        ];
    }

    /** @return array<string, mixed> */
    private function consolidateRule(array $rule): array
    {
        $delegations = $this->candidates($rule)->lockForUpdate()->get();
        if ($delegations->isEmpty()) {
            return [
                'canonical_name' => $rule['canonical_name'],
                'status' => 'not_found',
                'target_id' => null,
                'merged_ids' => [],
            ];
        }

        $capacities = $delegations->pluck('capacity_total')->filter(fn ($value): bool => $value !== null)->unique()->values();
        if ($capacities->count() > 1) {
            throw new RuntimeException("Capacidades incompatibles para {$rule['canonical_name']}; no se ha modificado ningún dato.");
        }

        $salesforceOwner = $delegations->firstWhere('salesforce_id', $rule['salesforce_id']);
        $target = $delegations->first(
            fn (object $delegation): bool => $delegation->normalized_key === $rule['normalized_key']
                && $delegation->merged_into_id === null,
        ) ?? $salesforceOwner ?? $delegations->first();

        $unexpectedSalesforceIds = $delegations->pluck('salesforce_id')
            ->filter()
            ->reject(fn (string $id): bool => $id === $rule['salesforce_id'])
            ->unique();
        if ($unexpectedSalesforceIds->isNotEmpty()) {
            throw new RuntimeException("Identidades Salesforce incompatibles para {$rule['canonical_name']}; no se ha modificado ningún dato.");
        }

        $sourceIds = $delegations->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->reject(fn (int $id): bool => $id === (int) $target->id)
            ->values();
        $allIds = $sourceIds->push((int) $target->id)->unique()->values();
        $referenceCounts = [];

        foreach (self::REFERENCE_TABLES as $table) {
            $referenceCounts[$table] = DB::table($table)->whereIn('stock_delegation_id', $sourceIds)->count();
            if ($sourceIds->isNotEmpty()) {
                DB::table($table)
                    ->whereIn('stock_delegation_id', $sourceIds)
                    ->update(['stock_delegation_id' => $target->id]);
            }
        }

        $capacitySource = $delegations->first(fn (object $delegation): bool => $delegation->capacity_total !== null);

        if ($sourceIds->isNotEmpty()) {
            DB::table('stock_delegations')->whereIn('id', $sourceIds)->update([
                'salesforce_id' => null,
                'capacity_total' => null,
                'capacity_source_name' => null,
                'capacity_updated_at' => null,
                'is_commercial' => false,
                'merged_into_id' => $target->id,
                'updated_at' => now(),
            ]);
        }

        DB::table('stock_delegations')->where('id', $target->id)->update([
            'salesforce_id' => $rule['salesforce_id'],
            'salesforce_name' => $salesforceOwner?->salesforce_name ?? $target->salesforce_name,
            'canonical_name' => $rule['canonical_name'],
            'normalized_key' => $rule['normalized_key'],
            'commercial_group' => $rule['commercial_group'],
            'zone' => $rule['zone'],
            'capacity_total' => $capacitySource?->capacity_total,
            'capacity_source_name' => $capacitySource?->capacity_source_name,
            'capacity_updated_at' => $capacitySource?->capacity_updated_at,
            'is_commercial' => true,
            'merged_into_id' => null,
            'updated_at' => now(),
        ]);

        $hasAvailable = DB::table('salesforce_vehicles')
            ->where('stock_delegation_id', $target->id)
            ->where('is_in_stock', true)
            ->where('state', 'Disponible')
            ->exists();
        $this->reconcileAvailabilityAlerts((int) $target->id, $sourceIds->all(), $hasAvailable);

        return [
            'canonical_name' => $rule['canonical_name'],
            'status' => 'consolidated',
            'target_id' => (int) $target->id,
            'merged_ids' => $sourceIds->all(),
            'candidate_ids' => $allIds->all(),
            'reference_counts' => $referenceCounts,
            'has_available_stock' => $hasAvailable,
        ];
    }

    private function candidates(array $rule)
    {
        $keys = collect($rule['source_keys'])->push($rule['normalized_key'])->unique()->values()->all();

        return DB::table('stock_delegations')
            ->where(function ($query) use ($keys, $rule): void {
                $query->whereIn('normalized_key', $keys)
                    ->orWhere('salesforce_id', $rule['salesforce_id']);
            })
            ->orderBy('id');
    }

    /** @param array<int, int> $sourceIds */
    private function reconcileAvailabilityAlerts(int $targetId, array $sourceIds, bool $hasAvailable): void
    {
        $openAlerts = DB::table('stock_availability_alerts')
            ->where('stock_delegation_id', $targetId)
            ->where('state', 'open')
            ->orderByDesc('id')
            ->get();
        $resolveIds = $hasAvailable ? $openAlerts->pluck('id') : $openAlerts->slice(1)->pluck('id');

        if ($resolveIds->isNotEmpty()) {
            DB::table('stock_availability_alerts')->whereIn('id', $resolveIds)->update([
                'state' => 'resolved',
                'resolved_at' => now(),
                'last_error' => null,
                'updated_at' => now(),
            ]);
        }

        $technicalIdentifiers = collect($sourceIds)
            ->map(fn (int $id): string => 'stock-delegation-'.$id);
        if ($hasAvailable) {
            $technicalIdentifiers->push('stock-delegation-'.$targetId);
        }

        if ($technicalIdentifiers->isNotEmpty()) {
            DB::table('operational_alerts')
                ->where('type', 'stock_availability')
                ->where('source', 'stock:sync-daily')
                ->where('state', 'open')
                ->whereIn('technical_identifier', $technicalIdentifiers->unique()->all())
                ->update([
                    'state' => 'resolved',
                    'resolution' => 'Identidad de delegación consolidada de forma controlada.',
                    'resolved_at' => now(),
                    'updated_at' => now(),
                ]);
        }
    }
}
