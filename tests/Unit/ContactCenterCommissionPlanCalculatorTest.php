<?php

namespace Tests\Unit;

use App\Services\Reports\ContactCenterCommissions\ContactCenterCommissionPlanCalculator;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ContactCenterCommissionPlanCalculatorTest extends TestCase
{
    public function test_progressive_delivery_incentive_is_incremental(): void
    {
        $calculator = app(ContactCenterCommissionPlanCalculator::class);

        foreach ([0 => 0.0, 1 => 10.0, 9 => 90.0, 10 => 105.0, 12 => 135.0, 20 => 255.0, 21 => 275.0] as $deliveries => $expected) {
            $this->assertSame($expected, $calculator->deliveryIncentive($deliveries)['delivery_incentive']);
        }
    }

    public function test_conversion_gate_uses_exact_integer_comparisons(): void
    {
        $calculator = app(ContactCenterCommissionPlanCalculator::class);

        $this->assertFalse($calculator->conversionPassed(10000, 499, 5));
        $this->assertTrue($calculator->conversionPassed(10000, 500, 5));
        $this->assertFalse($calculator->conversionPassed(10000, 699, 7));
        $this->assertTrue($calculator->conversionPassed(10000, 700, 7));
        $this->assertFalse($calculator->conversionPassed(0, 10, 5));
    }

    public function test_threshold_changes_only_on_the_next_natural_month(): void
    {
        $calculator = app(ContactCenterCommissionPlanCalculator::class);
        $createdAt = CarbonImmutable::parse('2026-06-02 08:26:54', 'UTC');

        $this->assertSame(5, $calculator->conversionThreshold($createdAt, CarbonImmutable::parse('2026-09-01')));
        $this->assertSame(7, $calculator->conversionThreshold($createdAt, CarbonImmutable::parse('2026-10-01')));
        $this->assertSame(5, $calculator->conversionThreshold(CarbonImmutable::parse('2026-06-22 07:24:25', 'UTC'), CarbonImmutable::parse('2026-09-01')));
        $this->assertSame(7, $calculator->conversionThreshold(CarbonImmutable::parse('2026-06-22 07:24:25', 'UTC'), CarbonImmutable::parse('2026-10-01')));
        $this->assertNull($calculator->conversionThreshold(null, CarbonImmutable::parse('2026-09-01')));
    }

    public function test_company_bonus_respects_exact_boundaries_and_conversion_gate(): void
    {
        $calculator = app(ContactCenterCommissionPlanCalculator::class);

        $this->assertSame(0.0, $calculator->companyBonus(true, 9999, 10000));
        $this->assertSame(50.0, $calculator->companyBonus(true, 10000, 10000));
        $this->assertSame(50.0, $calculator->companyBonus(true, 10499, 10000));
        $this->assertSame(80.0, $calculator->companyBonus(true, 10500, 10000));
        $this->assertSame(80.0, $calculator->companyBonus(true, 11000, 10000));
        $this->assertSame(150.0, $calculator->companyBonus(true, 11001, 10000));
        $this->assertSame(0.0, $calculator->companyBonus(false, 12000, 10000));
    }

    public function test_responsible_formula_uses_monthly_company_target(): void
    {
        $calculator = app(ContactCenterCommissionPlanCalculator::class);

        foreach ([899 => 0.0, 900 => 350.0, 999 => 350.0, 1000 => 500.0, 1012 => 560.0] as $deliveries => $expected) {
            $this->assertSame($expected, $calculator->responsibleCommission($deliveries, 1000)['final_total']);
        }
    }
}
