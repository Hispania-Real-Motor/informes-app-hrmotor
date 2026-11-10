<?php

namespace Tests\Feature;

use App\Models\SalesforceCall;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CallDashboardFiltersTest extends TestCase
{
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

    public function test_filtra_por_campos_principales(): void
    {
        $this->callRow('00T-match', [
            'created_date' => '2026-05-20 10:00:00',
            'operational_user_id' => '005-match',
            'operational_user_name' => 'Agente Match',
            'operational_team' => 'contact_center',
            'direction' => 'inbound',
            'call_status' => 'answered',
            'call_origin' => 'portal',
            'delegation' => 'Sin clasificar',
            'zone' => 'Sin clasificar',
            'portal_resolved' => 'Web',
        ]);
        $this->callRow('00T-other', [
            'created_date' => '2026-05-20 10:00:00',
            'operational_user_id' => '005-other',
            'operational_user_name' => 'Agente Other',
            'operational_team' => 'customer_service',
            'direction' => 'outbound',
            'call_status' => 'not_answered',
            'call_origin' => 'switchboard',
            'delegation' => 'Bilbao',
            'zone' => 'Zona Norte',
            'portal_resolved' => 'Llamada directa',
            'is_answered' => false,
            'is_lost' => true,
        ]);

        $query = http_build_query([
            'period' => 'custom',
            'current_start' => '2026-05-01',
            'current_end' => '2026-05-31',
            'comparison_start' => '2026-04-01',
            'comparison_end' => '2026-04-30',
            'team' => 'contact_center',
            'direction' => 'inbound',
            'status' => 'answered',
            'origin' => 'portal',
            'delegation' => 'Sin clasificar',
            'zone' => 'Sin clasificar',
            'portal' => 'Web',
            'user' => '005-match',
        ]);

        $this->getJson('/informes/llamadas/data/summary?'.$query)
            ->assertOk()
            ->assertJsonPath('kpis.total_calls', 1)
            ->assertJsonPath('kpis.answered', 1);
    }

    public function test_october_2026_selects_native_calls_without_interest_generation(): void
    {
        $this->callRow('00T-october-start', ['created_date' => '2026-09-30 22:30:00']);
        $this->callRow('00T-october', ['created_date' => '2026-10-31 10:00:00']);
        $this->callRow('00T-november-start', ['created_date' => '2026-10-31 23:00:00']);

        $this->get('/informes/llamadas?period=october_2026')
            ->assertOk()
            ->assertSee('value="october_2026" selected', false);

        $this->getJson('/informes/llamadas/data/summary?period=october_2026')
            ->assertOk()
            ->assertJsonPath('periodo_actual.inicio', '2026-10-01')
            ->assertJsonPath('periodo_actual.fin', '2026-11-01')
            ->assertJsonPath('kpis.total_calls', 2)
            ->assertJsonPath('kpis.answered', 2);
    }

    private function callRow(string $id, array $overrides = []): void
    {
        SalesforceCall::create(array_merge([
            'salesforce_id' => $id,
            'created_date' => '2026-05-20 10:00:00',
            'operational_user_name' => 'Agente',
            'operational_team' => 'commercial',
            'owner_team' => 'commercial',
            'delegation' => 'Alcobendas',
            'zone' => 'Zona Sur y Centro',
            'portales_raw' => 'Web',
            'call_origin' => 'portal',
            'portal_resolved' => 'Web',
            'call_status' => 'answered',
            'is_answered' => true,
            'is_lost' => false,
            'direction' => 'inbound',
            'adjusted_duration_seconds' => 40,
        ], $overrides));
    }
}
