<?php

namespace App\Services\Reports\CommercialCommissions;

use App\Models\StoreManagerCommissionTarget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class StoreManagerCommissionTargetService
{
    private const OCTOBER_REFERENCE_FILE = 'database/seeders/StoreManagerCommissionTargetsOctober2026.csv';

    private const OCTOBER_REFERENCE_LABEL = 'Detalle por tienda — OBJETIVOS OCTUBRE ULTIMOS(1).xlsx';

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

    /**
     * Return the repository-controlled target reference for periods that have
     * an approved source file. The reference is intentionally independent from
     * the mutable target table used by the dashboard.
     *
     * @return array{month: string, source_sha256: string, source_label: string, targets: array<int, array<string, mixed>>, delivery_total: int, purchase_total: int}|null
     */
    public function approvedReferenceForMonth(CarbonImmutable|string $month): ?array
    {
        $key = $month instanceof CarbonImmutable ? $month->format('Y-m') : (string) $month;
        if ($key !== '2026-10') {
            return null;
        }

        $path = base_path(self::OCTOBER_REFERENCE_FILE);
        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false || fgetcsv($handle) !== ['zone', 'store_name', 'delivery_target', 'purchase_target']) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            return null;
        }

        $targets = [];
        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null] || $row === []) {
                continue;
            }
            if (count($row) !== 4 || $row[0] === '' || $row[1] === '' || ! ctype_digit($row[2]) || ! ctype_digit($row[3])) {
                fclose($handle);

                return null;
            }

            [$zone, $storeName, $deliveryTarget, $purchaseTarget] = $row;
            $targets[] = [
                'zone' => $zone,
                'store_key' => $this->normalizeKey($storeName),
                'store_name' => $storeName,
                'target_deliveries' => (int) $deliveryTarget,
                'target_purchases' => (int) $purchaseTarget,
                'target_source' => [
                    'sha256' => hash('sha256', $contents),
                    'label' => self::OCTOBER_REFERENCE_LABEL,
                ],
            ];
        }
        fclose($handle);

        return [
            'month' => $key,
            'source_sha256' => hash('sha256', $contents),
            'source_label' => self::OCTOBER_REFERENCE_LABEL,
            'targets' => $targets,
            'delivery_total' => array_sum(array_column($targets, 'target_deliveries')),
            'purchase_total' => array_sum(array_column($targets, 'target_purchases')),
        ];
    }
}
