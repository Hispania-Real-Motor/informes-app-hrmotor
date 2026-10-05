<?php

namespace Tests\Unit;

use App\Services\Analytics\Executive\ExecutiveMetricRulesEngine;
use App\Services\Analytics\Executive\ExecutiveSummaryService;
use Tests\TestCase;

class ExecutiveSummaryServiceTest extends TestCase
{
    public function test_composes_metrics_and_orders_business_alerts_deterministically(): void
    {
        $summary = app(ExecutiveSummaryService::class)->compose($this->dataset([
            'leads' => $this->metric('leads', 0, [200, 200, 200, 200], mtd: 0),
            'reservas' => $this->metric('reservas', 19, [10, 10, 10, 10], mtd: 19),
            'ventas' => $this->metric('ventas', 5, [10, 10, 10, 10], mtd: 5),
        ]));

        $this->assertSame(['leads', 'reservas', 'ventas'], array_column($summary['alerts'], 'metric_key'));
        $this->assertSame('Crítico', $summary['alerts'][0]['status']['label']);
        $this->assertSame('Desfavorable', $summary['alerts'][0]['direction']['label']);
        $this->assertSame('Crítico', $summary['alerts'][1]['status']['label']);
        $this->assertSame('Favorable', $summary['alerts'][1]['direction']['label']);
        $this->assertSame('Desviación', $summary['alerts'][2]['status']['label']);
        $this->assertLessThanOrEqual(5, count($summary['alerts']));
    }

    public function test_alert_ties_use_absolute_difference_and_metric_key(): void
    {
        $summary = app(ExecutiveSummaryService::class)->compose($this->dataset([
            'leads' => $this->metric('leads', 130, [100, 100, 100, 100]),
            'reservas' => $this->metric('reservas', 8, [5, 5, 5, 5]),
            'ventas' => $this->metric('ventas', 8, [5, 5, 5, 5]),
        ]));

        $this->assertSame(['leads', 'reservas', 'ventas'], array_column($summary['alerts'], 'metric_key'));
    }

    public function test_data_incident_and_not_evaluable_metrics_do_not_create_business_alerts(): void
    {
        $summary = app(ExecutiveSummaryService::class)->compose($this->dataset([
            'leads' => $this->metric('leads', null, [200, 200, 200, 200], health: ExecutiveMetricRulesEngine::HEALTH_PARTIAL, complete: false),
            'reservas' => $this->metric('reservas', 0, [10, 10, 10, 10], health: ExecutiveMetricRulesEngine::HEALTH_INCIDENT, incident: true),
            'ventas' => $this->metric('ventas', 10, [10, 10, 10, 10], mtd: null),
        ]));

        $this->assertSame([], $summary['alerts']);
        $this->assertSame('No evaluable', $summary['metrics']['leads']['status']['label']);
        $this->assertSame('Incidencia', $summary['metrics']['reservas']['data_health']['label']);
        $this->assertSame('Estable', $summary['metrics']['ventas']['direction']['label']);
        $this->assertSame('-', $summary['metrics']['ventas']['display']['mtd']);
    }

    public function test_d364_is_secondary_context_and_mtd_zero_is_preserved(): void
    {
        $summary = app(ExecutiveSummaryService::class)->compose($this->dataset([
            'leads' => $this->metric('leads', 100, [100, 100, 100, 100], d364: 999, mtd: 0),
            'reservas' => $this->metric('reservas', 5, [5, 5, 5, 5], d364: null, mtd: 0),
            'ventas' => $this->metric('ventas', 5, [5, 5, 5, 5], d364: 1, mtd: 0),
        ]));

        $this->assertSame('999', $summary['metrics']['leads']['display']['d364_reference']);
        $this->assertSame('-', $summary['metrics']['reservas']['display']['d364_reference']);
        $this->assertSame('0', $summary['metrics']['leads']['display']['mtd']);
        $this->assertSame('Correcto', $summary['metrics']['leads']['status']['label']);
    }

    public function test_updated_metric_with_insufficient_baseline_keeps_health_but_prevents_complete_evaluability_headline(): void
    {
        $summary = app(ExecutiveSummaryService::class)->compose($this->dataset([
            'leads' => $this->metric('leads', 8, [10, 10, 10, 10]),
            'reservas' => $this->metric('reservas', 5, [5, 5, 5, 5]),
            'ventas' => $this->metric('ventas', 5, [5, 5, 5, 5]),
        ]));

        $this->assertFalse($summary['metrics']['leads']['evaluable']);
        $this->assertSame('No evaluable', $summary['metrics']['leads']['status']['label']);
        $this->assertSame('Actualizado', $summary['metrics']['leads']['data_health']['label']);
        $this->assertFalse($summary['data_health_summary']['all_evaluable']);
        $this->assertSame(2, $summary['data_health_summary']['evaluable_count']);
        $this->assertSame(1, $summary['data_health_summary']['not_evaluable_count']);
        $this->assertSame('Parcialmente evaluable', $summary['data_health_summary']['headline']);
        $this->assertNotSame('Completamente evaluable', $summary['data_health_summary']['headline']);
    }

    public function test_all_updated_but_no_metrics_evaluable_uses_not_evaluable_headline(): void
    {
        $summary = app(ExecutiveSummaryService::class)->compose($this->dataset([
            'leads' => $this->metric('leads', 8, [10, 10, 10, 10]),
            'reservas' => $this->metric('reservas', 2, [4, 4, 4, 4]),
            'ventas' => $this->metric('ventas', 2, [4, 4, 4, 4]),
        ]));

        $this->assertFalse($summary['data_health_summary']['all_evaluable']);
        $this->assertSame(0, $summary['data_health_summary']['evaluable_count']);
        $this->assertSame(3, $summary['data_health_summary']['not_evaluable_count']);
        $this->assertSame('No evaluable', $summary['data_health_summary']['headline']);
    }

    public function test_all_updated_and_all_metrics_evaluable_uses_complete_evaluability_headline(): void
    {
        $summary = app(ExecutiveSummaryService::class)->compose($this->dataset([
            'leads' => $this->metric('leads', 100, [100, 100, 100, 100]),
            'reservas' => $this->metric('reservas', 5, [5, 5, 5, 5]),
            'ventas' => $this->metric('ventas', 5, [5, 5, 5, 5]),
        ]));

        $this->assertTrue($summary['data_health_summary']['all_evaluable']);
        $this->assertSame(3, $summary['data_health_summary']['evaluable_count']);
        $this->assertSame(0, $summary['data_health_summary']['not_evaluable_count']);
        $this->assertSame('Completamente evaluable', $summary['data_health_summary']['headline']);
    }

    /** @param array<string, array<string, mixed>> $metrics */
    private function dataset(array $metrics): array
    {
        return [
            'cutoff' => [
                'date' => '2026-09-30',
                'timezone' => 'Europe/Madrid',
                'day_complete' => true,
                'start_inclusive' => '2026-09-30T00:00:00+02:00',
                'end_exclusive' => '2026-10-01T00:00:00+02:00',
            ],
            'mtd_period' => [
                'start_inclusive' => '2026-09-01T00:00:00+02:00',
                'end_exclusive' => '2026-10-01T00:00:00+02:00',
                'timezone' => 'Europe/Madrid',
            ],
            'metrics' => $metrics,
        ];
    }

    /** @param array<int, int|float> $references
     * @return array<string, mixed>
     */
    private function metric(
        string $key,
        int|float|null $current,
        array $references,
        int|float|null $d364 = null,
        int|float|null $mtd = 0,
        string $health = ExecutiveMetricRulesEngine::HEALTH_UPDATED,
        bool $complete = true,
        bool $incident = false,
    ): array {
        $referencePayload = [
            'd7' => $references[0] ?? null,
            'd14' => $references[1] ?? null,
            'd21' => $references[2] ?? null,
            'd28' => $references[3] ?? null,
            'd364' => $d364,
        ];

        return [
            'metric_key' => $key,
            'current' => $current,
            'references' => $referencePayload,
            'mtd' => $mtd,
            'data_health' => $health,
            'day_complete' => $complete,
            'data_incident' => $incident,
            'coverage' => [
                'current' => ['complete' => $complete, 'data_incident' => $incident, 'raw_value' => $current],
                'references' => [],
                'mtd' => ['complete' => true, 'data_incident' => false, 'raw_value' => $mtd],
            ],
            'engine_input' => [
                'metric_key' => $key,
                'current' => $current,
                'references' => $referencePayload,
                'config' => ExecutiveMetricRulesEngine::defaultMetricConfigs()[$key],
                'data_health' => $health,
                'day_complete' => $complete,
                'data_incident' => $incident,
            ],
        ];
    }
}
