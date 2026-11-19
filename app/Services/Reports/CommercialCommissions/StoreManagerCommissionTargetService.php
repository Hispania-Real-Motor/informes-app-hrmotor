<?php

namespace App\Services\Reports\CommercialCommissions;

use App\Models\StoreManagerCommissionTarget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class StoreManagerCommissionTargetService
{
    public function forMonth(CarbonImmutable|string $month): array
    {
        $key = $month instanceof CarbonImmutable ? $month->format('Y-m') : (string) $month;
        if (! Schema::hasTable('store_manager_commission_targets')) {
            return [];
        }

        return StoreManagerCommissionTarget::query()->where('month', $key)->orderBy('store_name')->get()
            ->map(fn (StoreManagerCommissionTarget $target): array => [
                'zone' => $target->zone,
                'store_key' => $target->store_key,
                'store_name' => $target->store_name,
                'target_deliveries' => $target->delivery_target,
                'target_purchases' => $target->purchase_target,
                'target_source' => ['sha256' => $target->source_sha256, 'label' => $target->source_label],
            ])->all();
    }

    public function normalizeKey(string $name): string
    {
        $key = Str::of($name)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '-')->trim('-')->value();
        $key = (string) preg_replace('/^hr-motor-/', '', $key);

        return match ($key) {
            'a-coruna', 'acoruna' => 'a-coruna',
            'malaga-ortega' => 'malaga-ortega',
            'malaga-almacha' => 'malaga-almacha',
            'llica-de-vall' => 'llica-de-vall',
            default => $key,
        };
    }
}
