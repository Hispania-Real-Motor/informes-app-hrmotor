<?php

namespace App\Services\Reports\CommercialCommissions;

use Carbon\CarbonImmutable;

/**
 * Pure calculator for the store-manager plan effective from October 2026.
 *
 * It deliberately contains no data attribution or fallback logic. Those
 * concerns must be resolved by the dashboard and represented as incidents.
 */
class StoreManagerCommissionPlanCalculator
{
    public const EFFECTIVE_FROM = '2026-10-01';

    public function appliesTo(CarbonImmutable|string $month): bool
    {
        $selectedMonth = $month instanceof CarbonImmutable
            ? $month->startOfMonth()
            : CarbonImmutable::createFromFormat('!Y-m', $month)->startOfMonth();

        return $selectedMonth->greaterThanOrEqualTo(CarbonImmutable::parse(self::EFFECTIVE_FROM));
    }

    public function storeKpiIncentive(int $actual, ?int $target): array
    {
        if ($actual < 0) {
            return ['incentive' => null, 'status' => 'data_invalid', 'bracket' => null, 'ratio' => null];
        }

        if ($target === null) {
            return ['incentive' => null, 'status' => 'target_pending', 'bracket' => null, 'ratio' => null];
        }

        if ($target < 0) {
            return ['incentive' => null, 'status' => 'data_invalid', 'bracket' => null, 'ratio' => null];
        }

        if ($target === 0) {
            return ['incentive' => 0.0, 'status' => 'not_applicable', 'bracket' => 'target_zero', 'ratio' => null];
        }

        $ratio = $actual / $target;

        if ($ratio < 0.90) {
            return ['incentive' => 0.0, 'status' => 'calculated', 'bracket' => 'below_90_percent', 'ratio' => $ratio];
        }

        if ($actual < $target) {
            return ['incentive' => $actual * 6.0, 'status' => 'calculated', 'bracket' => '90_to_below_100_percent', 'ratio' => $ratio];
        }

        if ($actual === $target) {
            return ['incentive' => $actual * 10.0, 'status' => 'calculated', 'bracket' => 'exact_target', 'ratio' => $ratio];
        }

        return [
            'incentive' => ($target * 10.0) + (($actual - $target) * 15.0),
            'status' => 'calculated',
            'bracket' => 'above_target',
            'ratio' => $ratio,
        ];
    }

    public function individualDeliveryIncentive(int $actual): float
    {
        $actual = max(0, $actual);

        if ($actual < 10) {
            return 0.0;
        }

        if ($actual <= 11) {
            return 120.0;
        }

        return 200.0 + (max(0, $actual - 12) * 20.0);
    }

    public function storeReviewAdjustment(mixed $reviewsCount, mixed $averageRating): ?float
    {
        $count = is_int($reviewsCount)
            ? $reviewsCount
            : (is_string($reviewsCount) && preg_match('/^\d+$/D', $reviewsCount) === 1 ? (int) $reviewsCount : null);
        if ($count === null || $count < 0) {
            return null;
        }

        if ($count === 0) {
            return $averageRating === null ? -150.0 : null;
        }

        if (! is_int($averageRating) && ! is_float($averageRating) && ! is_string($averageRating)) {
            return null;
        }
        if (! is_numeric($averageRating)) {
            return null;
        }

        $average = (float) $averageRating;
        if (! is_finite($average) || $average < 0.0 || $average > 5.0) {
            return null;
        }

        return $average >= 4.0 ? 150.0 : -150.0;
    }

    public function thresholdIncentive(float|int|null $accumulated, ?float $minimum, float $atMinimum): ?float
    {
        if ($accumulated === null || $minimum === null) {
            return null;
        }

        $accumulated = (float) $accumulated;
        $minimum = (float) $minimum;

        if ($accumulated < $minimum) {
            return 0.0;
        }

        return round($atMinimum + (($accumulated - $minimum) * 0.01), 2);
    }

    public function individualSubtotal(float $delivery, ?float $financing, ?float $guarantee, ?float $penalty): ?float
    {
        if ($financing === null || $guarantee === null || $penalty === null) {
            return null;
        }

        return round($delivery + $financing + $guarantee - $penalty, 2);
    }

    public function finalTotal(float $storeDeliveries, float $storePurchases, float $reviewsAdjustment, array $individualSubtotals): ?float
    {
        if (collect($individualSubtotals)->contains(fn (mixed $subtotal): bool => $subtotal === null || ! is_numeric($subtotal))) {
            return null;
        }

        return max(0.0, round($storeDeliveries + $storePurchases + $reviewsAdjustment + array_sum($individualSubtotals), 2));
    }
}
