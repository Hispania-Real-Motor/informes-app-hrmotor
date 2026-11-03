<?php

namespace App\Services\Reports\ContactCenterCommissions;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Pure economic rules for the roster-based Contact Center plan effective from
 * September 2026.  Values used for brackets are deliberately integer based.
 */
class ContactCenterCommissionPlanCalculator
{
    public function conversionThreshold(?CarbonInterface $salesforceCreatedAt, CarbonImmutable $monthStart): ?int
    {
        if ($salesforceCreatedAt === null) {
            return null;
        }

        $threeMonthAnniversary = CarbonImmutable::parse($salesforceCreatedAt)
            ->startOfDay()
            ->addMonthsNoOverflow(3);

        return $monthStart->lt($threeMonthAnniversary) ? 5 : 7;
    }

    public function conversionPassed(int $appointments, int $reservations, ?int $threshold): bool
    {
        return $appointments > 0
            && $threshold !== null
            && $reservations * 100 >= $appointments * $threshold;
    }

    /** @return array{deliveries_1_9: int, deliveries_10_20: int, deliveries_21_plus: int, amount_1_9: float, amount_10_20: float, amount_21_plus: float, delivery_incentive: float} */
    public function deliveryIncentive(int $deliveries): array
    {
        $deliveries = max(0, $deliveries);
        $firstBand = min($deliveries, 9);
        $secondBand = min(max($deliveries - 9, 0), 11);
        $thirdBand = max($deliveries - 20, 0);

        return [
            'deliveries_1_9' => $firstBand,
            'deliveries_10_20' => $secondBand,
            'deliveries_21_plus' => $thirdBand,
            'amount_1_9' => $firstBand * 10.0,
            'amount_10_20' => $secondBand * 15.0,
            'amount_21_plus' => $thirdBand * 20.0,
            'delivery_incentive' => $firstBand * 10.0 + $secondBand * 15.0 + $thirdBand * 20.0,
        ];
    }

    public function companyBonus(bool $conversionPassed, int $companyDeliveries, int $companyTarget): float
    {
        if (! $conversionPassed || $companyDeliveries < $companyTarget) {
            return 0.0;
        }

        if ($companyDeliveries * 100 < $companyTarget * 105) {
            return 50.0;
        }

        if ($companyDeliveries * 100 <= $companyTarget * 110) {
            return 80.0;
        }

        return 150.0;
    }

    /** @return array{compliance_percent: float, bracket: string, base_amount: float, additional_deliveries: int, additional_amount: float, final_total: float} */
    public function responsibleCommission(int $companyDeliveries, int $companyTarget): array
    {
        $compliance = round(($companyDeliveries / $companyTarget) * 100, 2);

        if ($companyDeliveries * 100 < $companyTarget * 90) {
            return $this->responsibleResult($compliance, '< 90 %', 0.0, 0, 0.0);
        }

        if ($companyDeliveries < $companyTarget) {
            return $this->responsibleResult($compliance, '90 % - 99,99 %', 350.0, 0, 0.0);
        }

        $additionalDeliveries = $companyDeliveries - $companyTarget;

        return $this->responsibleResult($compliance, '>= 100 %', 500.0, $additionalDeliveries, $additionalDeliveries * 5.0);
    }

    /** @return array{compliance_percent: float, bracket: string, base_amount: float, additional_deliveries: int, additional_amount: float, final_total: float} */
    private function responsibleResult(float $compliance, string $bracket, float $base, int $additionalDeliveries, float $additionalAmount): array
    {
        return [
            'compliance_percent' => $compliance,
            'bracket' => $bracket,
            'base_amount' => $base,
            'additional_deliveries' => $additionalDeliveries,
            'additional_amount' => $additionalAmount,
            'final_total' => $base + $additionalAmount,
        ];
    }
}
