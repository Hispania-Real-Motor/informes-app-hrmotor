<?php

namespace Tests\Unit;

use App\Services\Reports\CommercialCommissions\StoreManagerCommissionPlanCalculator;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class StoreManagerCommissionPlanCalculatorTest extends TestCase
{
    public function test_plan_is_effective_only_from_october_2026(): void
    {
        $calculator = app(StoreManagerCommissionPlanCalculator::class);

        $this->assertFalse($calculator->appliesTo('2026-09'));
        $this->assertTrue($calculator->appliesTo('2026-10'));
        $this->assertTrue($calculator->appliesTo(CarbonImmutable::parse('2026-11-01')));
    }

    /** @dataProvider storeKpiBrackets */
    public function test_store_kpi_brackets_are_exact(int $actual, ?int $target, ?float $incentive, ?string $bracket): void
    {
        $result = app(StoreManagerCommissionPlanCalculator::class)->storeKpiIncentive($actual, $target);

        $this->assertSame($incentive, $result['incentive']);
        $this->assertSame($bracket, $result['bracket']);
    }

    public static function storeKpiBrackets(): array
    {
        return [
            'below 90 percent' => [26, 30, 0.0, 'below_90_percent'],
            '90 percent' => [27, 30, 162.0, '90_to_below_100_percent'],
            'exact target' => [30, 30, 300.0, 'exact_target'],
            'above target' => [32, 30, 330.0, 'above_target'],
            'zero target' => [20, 0, 0.0, 'target_zero'],
            'missing target' => [20, null, null, null],
        ];
    }

    public function test_individual_delivery_boundaries(): void
    {
        $calculator = app(StoreManagerCommissionPlanCalculator::class);

        $this->assertSame(0.0, $calculator->individualDeliveryIncentive(9));
        $this->assertSame(120.0, $calculator->individualDeliveryIncentive(10));
        $this->assertSame(120.0, $calculator->individualDeliveryIncentive(11));
        $this->assertSame(200.0, $calculator->individualDeliveryIncentive(12));
        $this->assertSame(220.0, $calculator->individualDeliveryIncentive(13));
    }

    public function test_financing_and_guarantee_thresholds_preserve_negative_accumulations(): void
    {
        $calculator = app(StoreManagerCommissionPlanCalculator::class);

        $this->assertSame(0.0, $calculator->thresholdIncentive(-1, 100, 100));
        $this->assertSame(100.0, $calculator->thresholdIncentive(100, 100, 100));
        $this->assertSame(101.0, $calculator->thresholdIncentive(200, 100, 100));
        $this->assertNull($calculator->thresholdIncentive(null, 100, 100));
    }

    public function test_final_total_is_clamped_only_after_all_contributions(): void
    {
        $calculator = app(StoreManagerCommissionPlanCalculator::class);

        $this->assertSame(0.0, $calculator->finalTotal(10, 0, -150, [-100]));
        $this->assertSame(60.0, $calculator->finalTotal(100, 50, -150, [60]));
    }
}
