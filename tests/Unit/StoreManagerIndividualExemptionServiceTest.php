<?php

namespace Tests\Unit;

use App\Services\Reports\CommercialCommissions\StoreManagerIndividualExemptionService;
use Tests\TestCase;

class StoreManagerIndividualExemptionServiceTest extends TestCase
{
    public function test_verified_manager_keeps_positive_components_and_one_financing_cancellation(): void
    {
        $row = app(StoreManagerIndividualExemptionService::class)->apply([
            'prima_total' => 280.0,
            'prima_adjusted' => 300.0,
            'financing_product_amount' => 0.0,
            'guarantee_product_amount' => 0.0,
            'financing_cancellation_penalty_amount' => -70.0,
            'discount_penalty_amount' => 20.0,
            'guarantee_penalty' => 30.0,
            'reviews_penalty' => 25.0,
            'financing_penalty' => 25.0,
        ], true);

        $this->assertSame(250.0, $row['final_commission']);
        $this->assertSame(320.0, $row['prima_after_penalties']);
        $this->assertSame(0.0, $row['total_penalties']);
        $this->assertTrue($row['penalty_exemption']['applied']);
    }

    public function test_unverified_user_is_not_exempted(): void
    {
        $row = ['total_penalties' => 100.0, 'final_commission' => 200.0];

        $this->assertSame($row, app(StoreManagerIndividualExemptionService::class)->apply($row, false));
    }
}
