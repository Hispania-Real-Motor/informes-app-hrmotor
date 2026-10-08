<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Concerns\CreatesLeadDashboardRows;
use Tests\Feature\Concerns\MirrorsLeadFixturesToInterestReporting;
use Tests\TestCase;

class LeadsSummaryIncludesUnassignedTest extends TestCase
{
    use CreatesLeadDashboardRows;
    use MirrorsLeadFixturesToInterestReporting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-25 12:00:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_summary_and_comparison_include_unassigned_leads(): void
    {
        $this->leadRow('00Q-current-1', [
            'owner_id' => '0052X00000AP4U5QAL',
            'created_date' => '2026-05-10 10:00:00',
        ]);
        $this->leadRow('00Q-current-2', [
            'owner_id' => '005-other',
            'owner_name' => 'API User',
            'created_date' => '2026-05-11 10:00:00',
        ]);
        $this->leadRow('00Q-previous-1', [
            'owner_id' => '0057R00000CQGZaQAP',
            'created_date' => '2026-04-10 10:00:00',
        ]);

        $payload = $this->getJson('/informes/leads/data/summary?'.http_build_query([
            'period' => 'custom',
            'current_start' => '2026-05-01',
            'current_end' => '2026-05-31',
            'comparison_start' => '2026-04-01',
            'comparison_end' => '2026-04-30',
        ]))->assertOk()->json();

        $comparison = collect($payload['comparativa'])->firstWhere('key', 'leads_unassigned');

        $this->assertSame(2, $payload['kpis']['leads_unassigned']);
        $this->assertSame('Leads sin asignar', $comparison['metrica']);
        $this->assertSame(2, $comparison['periodo_actual']);
        $this->assertSame(1, $comparison['periodo_comparado']);
        $this->assertEquals(1, $comparison['diferencia']);
    }
}
