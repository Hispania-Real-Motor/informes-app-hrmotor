<?php

namespace App\Services\Reports\CommercialCommissions;

class StoreManagerIndividualExemptionService
{
    public function apply(array $row, bool $verifiedStoreManager): array
    {
        if (! $verifiedStoreManager) {
            return $row;
        }

        $discountPenalty = (float) ($row['discount_penalty_amount'] ?? 0);
        $row['discount_penalty_amount'] = 0.0;
        $row['guarantee_penalty'] = 0.0;
        $row['reviews_penalty'] = 0.0;
        $row['financing_penalty'] = 0.0;
        $row['total_penalties'] = 0.0;
        $bracketPercent = (float) ($row['delivery_bracket_percent'] ?? 100) / 100;
        $row['prima_after_penalties'] = round((float) ($row['prima_adjusted'] ?? 0) + ($discountPenalty * $bracketPercent), 2);
        $row['penalty_exemption'] = [
            'applied' => true,
            'reason' => 'Jefe de tienda verificado durante el mes; se conservan incentivos positivos y cancelaciones financieras.',
        ];
        $row['final_commission'] = round(
            $row['prima_after_penalties']
            + (float) ($row['financing_product_amount'] ?? 0)
            + (float) ($row['guarantee_product_amount'] ?? 0)
            + (float) ($row['appraiser_financing_commission'] ?? 0)
            + (float) ($row['appraiser_speed_amount'] ?? 0)
            + (float) ($row['financing_cancellation_penalty_amount'] ?? 0),
            2
        );

        return $row;
    }
}
