<?php

namespace App\Services\Reports\Stock;

use App\Models\StockDelegation;

class StockDelegationService
{
    /** @var array<string, StockDelegation|null> */
    private array $salesforceCache = [];

    public function __construct(
        private readonly StockDelegationNormalizer $normalizer,
    ) {}

    public function resolveSalesforce(?string $salesforceId, ?string $salesforceName): ?StockDelegation
    {
        if (blank($salesforceId) && blank($salesforceName)) {
            return null;
        }

        $normalized = $this->normalizer->normalize($salesforceName);
        $cacheKey = filled($salesforceId)
            ? 'id:'.$salesforceId
            : 'name:'.$normalized['normalized_key'];
        if (array_key_exists($cacheKey, $this->salesforceCache)) {
            return $this->salesforceCache[$cacheKey];
        }

        $salesforceDelegation = filled($salesforceId)
            ? StockDelegation::query()->where('salesforce_id', $salesforceId)->first()
            : null;

        $canonicalDelegation = StockDelegation::query()
            ->operational()
            ->where('normalized_key', $normalized['normalized_key'])
            ->first();

        $delegation = $salesforceDelegation?->merged_into_id !== null
            ? $salesforceDelegation->mergedInto
            : $salesforceDelegation;

        // A controlled repair may still be pending after deploying a new alias.
        // Prefer the existing canonical record without stealing the unique
        // Salesforce ID from the historical duplicate during a normal sync.
        if ($delegation !== null && $canonicalDelegation !== null && ! $canonicalDelegation->is($delegation)) {
            return $this->salesforceCache[$cacheKey] = $canonicalDelegation;
        }

        $delegation ??= $canonicalDelegation;
        $delegation ??= new StockDelegation;
        $attributes = [
            'canonical_name' => $normalized['canonical_name'],
            'normalized_key' => $normalized['normalized_key'],
            'commercial_group' => $normalized['commercial_group'],
            'zone' => $normalized['zone'],
            'is_commercial' => $normalized['is_commercial'],
        ];
        if (filled($salesforceId)) {
            $attributes['salesforce_id'] = $salesforceId;
        }
        if (filled($salesforceName) && (filled($salesforceId) || blank($delegation->salesforce_name))) {
            $attributes['salesforce_name'] = $salesforceName;
        }
        $delegation->fill($attributes);
        $delegation->save();

        return $this->salesforceCache[$cacheKey] = $delegation;
    }

    public function applyCapacity(string $sourceName, int $capacity): StockDelegation
    {
        $normalized = $this->normalizer->normalize($sourceName);
        $delegation = StockDelegation::query()
            ->operational()
            ->where('normalized_key', $normalized['normalized_key'])
            ->firstOrNew();

        $delegation->fill([
            'canonical_name' => $normalized['canonical_name'],
            'normalized_key' => $normalized['normalized_key'],
            'commercial_group' => $normalized['commercial_group'],
            'zone' => $normalized['zone'],
            'capacity_total' => max($capacity, 0),
            'capacity_source_name' => $sourceName,
            'capacity_updated_at' => now(),
            'is_commercial' => $this->normalizer->isCommercial($sourceName),
        ]);
        $delegation->save();
        $this->salesforceCache = [];

        return $delegation;
    }
}
