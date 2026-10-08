<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Concerns\CreatesCallDashboardRows;
use Tests\Feature\Concerns\CreatesLeadDashboardRows;
use Tests\Feature\Concerns\MirrorsLeadFixturesToInterestReporting;
use Tests\TestCase;

class DashboardCacheMissConsistencyTest extends TestCase
{
    use CreatesCallDashboardRows;
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

    public function test_leads_summary_cache_hit_preserves_the_cache_miss_payload_for_comparison_periods(): void
    {
        $this->leadRow('lead-current-boundary', ['created_date' => '2026-05-01 00:00:00']);
        $this->leadRow('lead-previous-boundary', ['created_date' => '2026-04-30 23:59:59']);

        $url = '/informes/leads/data/summary?'.http_build_query([
            'period' => 'custom',
            'current_start' => '2026-05-01',
            'current_end' => '2026-05-31',
            'comparison_start' => '2026-04-01',
            'comparison_end' => '2026-04-30',
        ]);

        $cold = $this->getJson($url)->assertOk()->json();
        $warm = $this->getJson($url)->assertOk()->json();

        $this->assertSame($cold, $warm);
        $this->assertSame(1, $cold['kpis']['leads_totales']);
        $this->assertSame(1, collect($cold['comparativa'])->firstWhere('key', 'leads_totales')['periodo_comparado']);
    }

    public function test_calls_cache_hit_preserves_summary_agents_and_delegations_payloads(): void
    {
        $this->callRow('call-commercial');
        $this->callRow('call-contact-center', [
            'operational_team' => 'contact_center',
            'owner_team' => 'contact_center',
        ]);

        foreach ([
            '/informes/llamadas/data/summary',
            '/informes/llamadas/data/agents',
            '/informes/llamadas/data/delegations',
        ] as $url) {
            $cold = $this->getJson($url)->assertOk()->json();
            $warm = $this->getJson($url)->assertOk()->json();

            $this->assertSame($cold, $warm, $url);
        }
    }
}
