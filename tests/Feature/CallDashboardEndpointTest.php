<?php

namespace Tests\Feature;

use App\Models\SalesforceCall;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CallDashboardEndpointTest extends TestCase
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

    public function test_dashboard_y_endpoints_responden_con_datos(): void
    {
        $this->callRow(['salesforce_id' => '00T-1']);

        $this->get('/informes/llamadas')->assertOk()->assertSee('Llamadas');
        $this->getJson('/informes/llamadas/data/summary')->assertOk()->assertJsonPath('ok', true)->assertJsonPath('kpis.total_calls', 1);
        $this->getJson('/informes/llamadas/data/agents')->assertOk()->assertJsonPath('agents.0.total_calls', 1);
        $this->getJson('/informes/llamadas/data/delegations')->assertOk()->assertJsonPath('zones.0.total_calls', 1);
        $this->getJson('/informes/llamadas/data/portals')->assertOk()->assertJsonPath('items.0.portal', 'Web');
    }

    public function test_equipos_incluyen_fila_sin_equipo_y_concilian_con_atendidas(): void
    {
        $this->callRow(['salesforce_id' => '00T-commercial']);
        $this->callRow([
            'salesforce_id' => '00T-contact-center',
            'operational_team' => 'contact_center',
            'owner_team' => 'contact_center',
        ]);
        $this->callRow([
            'salesforce_id' => '00T-unassigned',
            'operational_team' => 'unassigned',
            'owner_team' => 'unassigned',
        ]);

        $summaryResponse = $this->getJson('/informes/llamadas/data/summary')->assertOk();
        $summary = $summaryResponse->json('kpis');
        $visibleTeams = collect($summaryResponse->json('charts.answered_by_team'));
        $teams = collect($this->getJson('/informes/llamadas/data/agents')->assertOk()->json('teams'));

        $this->assertSame(3, $summary['answered']);
        $this->assertSame(3, $teams->sum('answered'));
        $this->assertSame(1, $teams->firstWhere('team_label', 'Sin equipo')['answered']);
        $this->assertSame(3, $visibleTeams->sum('value'));
        $this->assertSame(1, $visibleTeams->firstWhere('label', 'Sin equipo')['value']);
    }

    public function test_perfil_de_pruebas_excluido_no_participa_en_kpi_y_permanece_en_auditoria(): void
    {
        $this->callRow([
            'salesforce_id' => '00T-operational',
            'call_object' => 'operational-call-object',
            'included_in_dashboard' => true,
        ]);
        $this->callRow([
            'salesforce_id' => '00T-test-profile',
            'call_object' => 'test-call-object',
            'included_in_dashboard' => false,
            'dashboard_exclusion_reason' => 'excluded_test_profile',
            'call_status' => 'not_answered',
            'is_answered' => false,
            'is_lost' => true,
        ]);

        $this->getJson('/informes/llamadas/data/summary')
            ->assertOk()
            ->assertJsonPath('kpis.total_calls', 1)
            ->assertJsonPath('kpis.answered', 1)
            ->assertJsonPath('kpis.not_answered', 0)
            ->assertJsonPath('reconciliation.excluded_test_profile', 1);

        $items = collect($this->getJson('/informes/llamadas/data/audit')->assertOk()->json('items'));
        $this->assertSame('excluded_test_profile', $items->firstWhere('task_id', '00T-test-profile')['exclusion_reason']);
    }

    public function test_csv_auditoria_exporta_una_fila_por_task_y_serializa_valores_brutos_como_json(): void
    {
        foreach (range(1, 3) as $index) {
            $this->callRow([
                'salesforce_id' => '00T-included-'.$index,
                'call_object' => 'call-object-'.$index,
                'included_in_dashboard' => true,
                'parse_debug' => [
                    'parsed' => [
                        'result' => 'Atendida',
                        'nested' => ['index' => $index],
                    ],
                ],
            ]);
        }
        foreach (range(1, 2) as $index) {
            $this->callRow([
                'salesforce_id' => '00T-excluded-'.$index,
                'call_object' => null,
                'included_in_dashboard' => false,
                'dashboard_exclusion_reason' => 'missing_call_object',
                'parse_debug' => ['parsed' => []],
            ]);
        }

        $content = $this->get('/informes/llamadas/export/audit.csv')
            ->assertOk()
            ->streamedContent();
        $records = $this->csvRecords($content);
        $header = array_shift($records);
        $taskIdIndex = array_search('task_id', $header, true);
        $reasonIndex = array_search('exclusion_reason', $header, true);
        $rawValuesIndex = array_search('classification_raw_values', $header, true);

        $this->assertCount(5, $records);
        $this->assertCount(5, array_unique(array_column($records, $taskIdIndex)));
        $this->assertSame(2, collect($records)->where($reasonIndex, 'missing_call_object')->count());
        foreach ($records as $record) {
            $this->assertIsArray(json_decode($record[$rawValuesIndex], true, flags: JSON_THROW_ON_ERROR));
        }
        $this->assertStringNotContainsString('Array', $content);
    }

    public function test_auditoria_identifica_task_fuera_del_universo_por_call_object(): void
    {
        $this->callRow([
            'salesforce_id' => '00T-included',
            'call_object' => 'a-call-object',
            'included_in_dashboard' => true,
            'classification_rule_version' => '2026-08-04.1',
        ]);
        $this->callRow([
            'salesforce_id' => '00T-excluded',
            'call_object' => null,
            'included_in_dashboard' => false,
            'dashboard_exclusion_reason' => 'missing_call_object',
        ]);

        $this->getJson('/informes/llamadas/data/summary')
            ->assertOk()
            ->assertJsonPath('kpis.total_calls', 1);
        $items = collect($this->getJson('/informes/llamadas/data/audit')->assertOk()->json('items'));

        $this->assertCount(2, $items);
        $this->assertSame('included', $items->firstWhere('task_id', '00T-included')['inclusion_exclusion_reason']);
        $this->assertSame('missing_call_object', $items->firstWhere('task_id', '00T-excluded')['inclusion_exclusion_reason']);
        $this->get('/informes/llamadas/export/audit.csv')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_auditoria_expone_relacion_interest_y_procedencia_sin_pii_nueva(): void
    {
        $this->callRow([
            'salesforce_id' => '00T-interest-audit',
            'what_id' => 'a01AAA000000000AAA',
            'portal_resolution_source' => 'interest',
            'parse_debug' => [
                'parsed' => [],
                'portal_debug' => [
                    'relationship_status' => 'exact_interest',
                    'interest_matched' => true,
                    'interest_id' => 'a01AAA000000000AAA',
                    'interest_is_deleted' => true,
                    'interest_source_raw' => 'Coches.net',
                ],
            ],
        ]);

        $row = $this->getJson('/informes/llamadas/data/audit')->assertOk()->json('items.0');

        $this->assertSame('a01AAA000000000AAA', $row['what_id']);
        $this->assertTrue($row['interest_exact_match']);
        $this->assertTrue($row['interest_is_deleted']);
        $this->assertSame('Coches.net', $row['interest_source_fallback']);
        $this->assertSame('interest', $row['portal_provenance']);
        $this->assertArrayNotHasKey('interest_raw_payload', $row);
        $this->assertArrayNotHasKey('client_phone', $row);
    }

    private function callRow(array $overrides = []): void
    {
        SalesforceCall::create(array_merge([
            'salesforce_id' => '00T-base',
            'created_date' => '2026-05-20 10:00:00',
            'owner_name' => 'Comercial Owner',
            'operational_user_name' => 'Comercial Owner',
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

    private function csvRecords(string $content): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($content));

        return array_map(function (string $line): array {
            $record = str_getcsv($line);
            $record[0] = ltrim($record[0], "\xEF\xBB\xBF");

            return $record;
        }, array_filter($lines, fn (string $line): bool => $line !== ''));
    }
}
