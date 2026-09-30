<?php

namespace Tests\Unit\Executive;

use App\Services\Analytics\Executive\ExecutiveMetricRulesEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExecutiveMetricRulesEngineTest extends TestCase
{
    private ExecutiveMetricRulesEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = new ExecutiveMetricRulesEngine;
    }

    #[DataProvider('directionCases')]
    public function test_direction_is_based_only_on_actual_vs_baseline(int $actual, string $direction): void
    {
        $result = $this->evaluate('leads', $actual, [100, 100, 100, 100]);

        $this->assertTrue($result['evaluable']);
        $this->assertSame($direction, $result['direction']);
    }

    public static function directionCases(): array
    {
        return [
            'actual greater than baseline is favorable' => [120, ExecutiveMetricRulesEngine::DIRECTION_FAVORABLE],
            'actual equal to baseline is stable' => [100, ExecutiveMetricRulesEngine::DIRECTION_STABLE],
            'actual lower than baseline is unfavorable' => [90, ExecutiveMetricRulesEngine::DIRECTION_UNFAVORABLE],
        ];
    }

    public function test_result_contains_rule_version_and_computed_values(): void
    {
        $result = $this->evaluate('leads', 120, [100, 100, 100, 100], d364: 999);

        $this->assertSame(ExecutiveMetricRulesEngine::RULE_VERSION, $result['rule_version']);
        $this->assertSame(100.0, $result['baseline']);
        $this->assertSame(20.0, $result['variation_percent']);
        $this->assertSame(20.0, $result['magnitude_variation_percent']);
        $this->assertSame(20.0, $result['absolute_difference']);
        $this->assertSame(999.0, $result['d364_reference']);
        $this->assertSame([ExecutiveMetricRulesEngine::REASON_NORMAL_EVALUATION], $result['reason_codes']);
        $this->assertNull($result['confirmed_cause']);
        $this->assertNull($result['possible_cause_to_review']);
        $this->assertNull($result['recommended_action_key']);
    }

    public function test_d364_is_optional_and_never_changes_status(): void
    {
        $withoutYear = $this->evaluate('leads', 130, [100, 100, 100, 100]);
        $withDifferentYear = $this->evaluate('leads', 130, [100, 100, 100, 100], d364: 1);

        $this->assertSame(ExecutiveMetricRulesEngine::STATUS_ATTENTION, $withoutYear['status']);
        $this->assertSame($withoutYear['status'], $withDifferentYear['status']);
        $this->assertNull($withoutYear['d364_reference']);
        $this->assertSame(1.0, $withDifferentYear['d364_reference']);
    }

    #[DataProvider('bandBoundaryCases')]
    public function test_percentage_band_boundaries_are_inclusive_or_exclusive_as_approved(
        int $actual,
        string $expectedStatus,
    ): void {
        $result = $this->evaluate('leads', $actual, [1000, 1000, 1000, 1000]);

        $this->assertSame($expectedStatus, $result['status']);
    }

    public static function bandBoundaryCases(): array
    {
        return [
            '14.9 percent remains correct' => [1149, ExecutiveMetricRulesEngine::STATUS_CORRECT],
            'exactly 15 percent is attention' => [1150, ExecutiveMetricRulesEngine::STATUS_ATTENTION],
            'exactly 25 percent is deviation' => [1250, ExecutiveMetricRulesEngine::STATUS_DEVIATION],
            'exactly 40 percent is deviation' => [1400, ExecutiveMetricRulesEngine::STATUS_DEVIATION],
            'above 40 percent is critical' => [1401, ExecutiveMetricRulesEngine::STATUS_CRITICAL],
        ];
    }

    public function test_zero_current_with_evaluable_baseline_is_critical_unfavorable(): void
    {
        $result = $this->evaluate('leads', 0, [100, 100, 100, 100]);

        $this->assertTrue($result['evaluable']);
        $this->assertTrue($result['business_alert']);
        $this->assertSame(ExecutiveMetricRulesEngine::STATUS_CRITICAL, $result['status']);
        $this->assertSame(ExecutiveMetricRulesEngine::DIRECTION_UNFAVORABLE, $result['direction']);
        $this->assertSame([ExecutiveMetricRulesEngine::REASON_ZERO_CURRENT], $result['reason_codes']);
    }

    public function test_baseline_exactly_at_minimum_is_evaluable_and_just_below_is_not(): void
    {
        $atMinimum = $this->evaluate('reservas', 7, [5, 5, 5, 5]);
        $belowMinimum = $this->evaluate('reservas', 6, [4, 5, 5, 5]);

        $this->assertTrue($atMinimum['evaluable']);
        $this->assertFalse($belowMinimum['evaluable']);
        $this->assertSame(ExecutiveMetricRulesEngine::STATUS_NOT_EVALUABLE, $belowMinimum['status']);
        $this->assertContains(ExecutiveMetricRulesEngine::REASON_INSUFFICIENT_BASELINE, $belowMinimum['reason_codes']);
        $this->assertFalse($belowMinimum['business_alert']);
    }

    #[DataProvider('metricGateBehaviorCases')]
    public function test_each_default_metric_enforces_minimum_baseline_and_exact_absolute_gates(
        string $metric,
        array $belowMinimumReferences,
        int $attentionActual,
        array $attentionReferences,
        int $deviationActual,
        array $deviationReferences,
        int $criticalActual,
        array $criticalReferences,
    ): void {
        $this->assertFalse($this->evaluate($metric, $attentionActual, $belowMinimumReferences)['evaluable']);
        $this->assertSame(
            ExecutiveMetricRulesEngine::STATUS_ATTENTION,
            $this->evaluate($metric, $attentionActual, $attentionReferences)['status'],
        );
        $this->assertSame(
            ExecutiveMetricRulesEngine::STATUS_DEVIATION,
            $this->evaluate($metric, $deviationActual, $deviationReferences)['status'],
        );
        $this->assertSame(
            ExecutiveMetricRulesEngine::STATUS_CRITICAL,
            $this->evaluate($metric, $criticalActual, $criticalReferences)['status'],
        );
    }

    public static function metricGateBehaviorCases(): array
    {
        return [
            'leads' => [
                'leads',
                [99, 99, 99, 99],
                230,
                [200, 200, 200, 200],
                250,
                [200, 200, 200, 200],
                300,
                [200, 200, 200, 200],
            ],
            'reservas' => [
                'reservas',
                [4, 4, 4, 4],
                23,
                [20, 20, 20, 20],
                25,
                [20, 20, 20, 20],
                24,
                [16, 16, 16, 16],
            ],
            'ventas' => [
                'ventas',
                [4, 4, 4, 4],
                23,
                [20, 20, 20, 20],
                25,
                [20, 20, 20, 20],
                24,
                [16, 16, 16, 16],
            ],
        ];
    }

    #[DataProvider('gateDegradationCases')]
    public function test_absolute_gates_never_raise_and_can_degrade_percentage_band(
        string $metric,
        int $actual,
        array $references,
        string $expectedStatus,
    ): void {
        $result = $this->evaluate($metric, $actual, $references);

        $this->assertSame($expectedStatus, $result['status']);
    }

    public static function gateDegradationCases(): array
    {
        return [
            'attention band without absolute gate degrades to correct' => ['leads', 120, [100, 100, 100, 100], ExecutiveMetricRulesEngine::STATUS_CORRECT],
            'attention band with exact gate is attention' => ['leads', 130, [100, 100, 100, 100], ExecutiveMetricRulesEngine::STATUS_ATTENTION],
            'deviation band without deviation gate but meeting attention gate degrades to attention' => ['leads', 225, [180, 180, 180, 180], ExecutiveMetricRulesEngine::STATUS_ATTENTION],
            'critical band without critical gate but meeting deviation gate degrades to deviation' => ['leads', 180, [125, 125, 125, 125], ExecutiveMetricRulesEngine::STATUS_DEVIATION],
            'no absolute gate met becomes correct' => ['leads', 115, [100, 100, 100, 100], ExecutiveMetricRulesEngine::STATUS_CORRECT],
            '35 percent and difference 70 reaches deviation for leads' => ['leads', 270, [200, 200, 200, 200], ExecutiveMetricRulesEngine::STATUS_DEVIATION],
            'exact 25 percent and exact deviation gate is deviation' => ['leads', 250, [200, 200, 200, 200], ExecutiveMetricRulesEngine::STATUS_DEVIATION],
        ];
    }

    public function test_required_deviation_examples_for_leads(): void
    {
        $deviationByThirtyFive = $this->evaluate('leads', 270, [200, 200, 200, 200]);
        $deviationByExactTwentyFive = $this->evaluate('leads', 250, [200, 200, 200, 200]);
        $notEnoughGate = $this->evaluate('leads', 235, [200, 200, 200, 200]);

        $this->assertSame(ExecutiveMetricRulesEngine::STATUS_DEVIATION, $deviationByThirtyFive['status']);
        $this->assertSame(ExecutiveMetricRulesEngine::STATUS_DEVIATION, $deviationByExactTwentyFive['status']);
        $this->assertSame(ExecutiveMetricRulesEngine::STATUS_ATTENTION, $notEnoughGate['status']);
    }

    #[DataProvider('missingReferenceCases')]
    public function test_each_required_weekly_reference_is_mandatory(string $missingKey): void
    {
        $references = ['d7' => 100, 'd14' => 100, 'd21' => 100, 'd28' => 100];
        unset($references[$missingKey]);

        $result = $this->engine->evaluate([
            'metric_key' => 'leads',
            'current' => 100,
            'references' => $references,
            'config' => ExecutiveMetricRulesEngine::defaultMetricConfigs()['leads'],
        ]);

        $this->assertFalse($result['evaluable']);
        $this->assertContains(ExecutiveMetricRulesEngine::REASON_MISSING_WEEKLY_REFERENCE.':'.$missingKey, $result['reason_codes']);
        $this->assertFalse($result['business_alert']);
    }

    public static function missingReferenceCases(): array
    {
        return [
            ['d7'],
            ['d14'],
            ['d21'],
            ['d28'],
        ];
    }

    public function test_multiple_missing_references_are_reported(): void
    {
        $result = $this->engine->evaluate([
            'metric_key' => 'leads',
            'current' => 100,
            'references' => ['d7' => 100, 'd28' => 100],
            'config' => ExecutiveMetricRulesEngine::defaultMetricConfigs()['leads'],
        ]);

        $this->assertFalse($result['evaluable']);
        $this->assertContains(ExecutiveMetricRulesEngine::REASON_MISSING_WEEKLY_REFERENCE.':d14', $result['reason_codes']);
        $this->assertContains(ExecutiveMetricRulesEngine::REASON_MISSING_WEEKLY_REFERENCE.':d21', $result['reason_codes']);
    }

    #[DataProvider('healthCases')]
    public function test_data_health_is_preserved_and_incident_blocks_business_evaluation(string $health, bool $evaluable): void
    {
        $result = $this->evaluate('ventas', 7, [5, 5, 5, 5], health: $health);

        $this->assertSame($health, $result['data_health']);
        $this->assertSame($evaluable, $result['evaluable']);
        if (! $evaluable) {
            $this->assertSame(ExecutiveMetricRulesEngine::STATUS_NOT_EVALUABLE, $result['status']);
            $this->assertContains(ExecutiveMetricRulesEngine::REASON_DATA_INCIDENT, $result['reason_codes']);
            $this->assertFalse($result['business_alert']);
        }
    }

    public static function healthCases(): array
    {
        return [
            'updated' => [ExecutiveMetricRulesEngine::HEALTH_UPDATED, true],
            'partial' => [ExecutiveMetricRulesEngine::HEALTH_PARTIAL, true],
            'stale' => [ExecutiveMetricRulesEngine::HEALTH_STALE, true],
            'incident' => [ExecutiveMetricRulesEngine::HEALTH_INCIDENT, false],
        ];
    }

    public function test_incomplete_day_is_not_evaluable(): void
    {
        $result = $this->evaluate('ventas', 7, [5, 5, 5, 5], dayComplete: false);

        $this->assertFalse($result['evaluable']);
        $this->assertContains(ExecutiveMetricRulesEngine::REASON_INCOMPLETE_DAY, $result['reason_codes']);
        $this->assertFalse($result['business_alert']);
    }

    public function test_zero_values_are_valid_and_not_confused_with_absence(): void
    {
        $result = $this->evaluate('leads', 0, [0, 0, 0, 0]);

        $this->assertFalse($result['evaluable']);
        $this->assertSame(0.0, $result['current']);
        $this->assertSame(0.0, $result['baseline']);
        $this->assertContains(ExecutiveMetricRulesEngine::REASON_INSUFFICIENT_BASELINE, $result['reason_codes']);
    }

    public function test_negative_values_are_not_evaluable_for_count_metrics(): void
    {
        $negativeCurrent = $this->evaluate('leads', -1, [100, 100, 100, 100]);
        $negativeReference = $this->evaluate('leads', 120, [100, -100, 100, 100]);

        $this->assertFalse($negativeCurrent['evaluable']);
        $this->assertContains(ExecutiveMetricRulesEngine::REASON_NEGATIVE_VALUE, $negativeCurrent['reason_codes']);
        $this->assertFalse($negativeReference['evaluable']);
        $this->assertContains(ExecutiveMetricRulesEngine::REASON_NEGATIVE_VALUE, $negativeReference['reason_codes']);
    }

    #[DataProvider('defaultMetricConfigCases')]
    public function test_default_metric_configs_cover_approved_minimums_and_gates(
        string $metric,
        int $minimumBaseline,
        int $attentionGate,
        int $deviationGate,
        int $criticalGate,
    ): void {
        $config = ExecutiveMetricRulesEngine::defaultMetricConfigs()[$metric];

        $this->assertSame((float) $minimumBaseline, (float) $config['minimum_baseline']);
        $this->assertSame((float) $attentionGate, (float) $config['absolute_gates'][ExecutiveMetricRulesEngine::STATUS_ATTENTION]);
        $this->assertSame((float) $deviationGate, (float) $config['absolute_gates'][ExecutiveMetricRulesEngine::STATUS_DEVIATION]);
        $this->assertSame((float) $criticalGate, (float) $config['absolute_gates'][ExecutiveMetricRulesEngine::STATUS_CRITICAL]);
    }

    public static function defaultMetricConfigCases(): array
    {
        return [
            'leads' => ['leads', 100, 30, 50, 100],
            'reservas' => ['reservas', 5, 3, 5, 8],
            'ventas' => ['ventas', 5, 3, 5, 8],
        ];
    }

    /**
     * @param  array<int, int|float>  $weekly
     * @return array<string, mixed>
     */
    private function evaluate(
        string $metric,
        int|float $actual,
        array $weekly,
        int|float|null $d364 = null,
        string $health = ExecutiveMetricRulesEngine::HEALTH_UPDATED,
        bool $dayComplete = true,
    ): array {
        $references = [
            'd7' => $weekly[0] ?? null,
            'd14' => $weekly[1] ?? null,
            'd21' => $weekly[2] ?? null,
            'd28' => $weekly[3] ?? null,
        ];

        if ($d364 !== null) {
            $references['d364'] = $d364;
        }

        return $this->engine->evaluate([
            'metric_key' => $metric,
            'current' => $actual,
            'references' => $references,
            'config' => ExecutiveMetricRulesEngine::defaultMetricConfigs()[$metric],
            'data_health' => $health,
            'day_complete' => $dayComplete,
        ]);
    }
}
