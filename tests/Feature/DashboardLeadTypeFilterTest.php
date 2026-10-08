<?php

namespace Tests\Feature;

use App\Models\SalesforceLead;
use App\Models\SalesforceUser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\MirrorsLeadFixturesToInterestReporting;
use Tests\TestCase;

class DashboardLeadTypeFilterTest extends TestCase
{
    use MirrorsLeadFixturesToInterestReporting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-13 12:00:00'));

        SalesforceUser::create([
            'salesforce_id' => '005-commercial',
            'name' => 'Comercial',
            'profile_name' => 'Compra/Venta',
            'user_delegation' => 'HR MOTOR TORREJON',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_filtro_tipo_de_lead_filtra_por_record_type_name(): void
    {
        $this->lead('00Q1', 'Tasación');
        $this->lead('00Q2', 'Venta');
        $this->lead('00Q3', 'Venta con cambio');
        $this->lead('00Q4', ' Lead ');
        $this->lead('00Q5', 'AYVENS');
        $this->lead('00Q6', 'Otro');
        $this->lead('00Q7', null);

        $this->assertSame(7, $this->getJson('/informes/leads/data/summary')->json('kpis.leads_totales'));
        $this->assertSame(7, $this->getJson('/informes/leads/data/summary?lead_type=all')->json('kpis.leads_totales'));
        $this->assertSame(1, $this->getJson('/informes/leads/data/summary?lead_type=Tasaci%C3%B3n')->json('kpis.leads_totales'));
        $this->assertSame(1, $this->getJson('/informes/leads/data/summary?lead_type=Tasacion')->json('kpis.leads_totales'));
        $this->assertSame(1, $this->getJson('/informes/leads/data/summary?lead_type=%20TASACI%C3%93N%20')->json('kpis.leads_totales'));
        $this->assertSame(4, $this->getJson('/informes/leads/data/summary?lead_type=Venta')->json('kpis.leads_totales'));
    }

    public function test_filtro_tipo_de_lead_aplica_a_todos_los_endpoints_principales(): void
    {
        $this->lead('00Q1', 'Tasación', ['portal_text' => 'Web']);
        $this->lead('00Q2', 'Venta', ['portal_text' => 'Meta']);

        $query = '?lead_type=Tasacion';

        $this->assertSame(1, $this->getJson('/informes/leads/data/summary'.$query)->json('kpis.leads_totales'));
        $this->assertSame(1, $this->getJson('/informes/leads/data/commercials'.$query)->json('commercials.0.leads_totales'));
        $this->assertSame(1, $this->getJson('/informes/leads/data/delegations'.$query)->json('items.0.leads_totales'));
        $this->assertSame(1, $this->getJson('/informes/leads/data/portals'.$query)->json('items.0.leads_totales'));
        $this->assertSame('Web', $this->getJson('/informes/leads/data/portals'.$query)->json('items.0.portal'));
    }

    public function test_opciones_de_filtro_solo_muestran_tipos_permitidos(): void
    {
        $this->lead('00Q1', 'Otro');

        $filters = $this->getJson('/informes/leads/data/summary')->json('filters');

        $this->assertSame(['Tasación', 'Venta'], $filters['lead_types']);
    }

    public function test_respuesta_vacia_es_correcta_y_no_se_confunde_con_un_error(): void
    {
        $response = $this->getJson('/informes/leads/data/summary?lead_type=Tasacion');

        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('empty', true)
            ->assertJsonPath('kpis.leads_totales', 0)
            ->assertJsonPath('executive_insights_source', 'none')
            ->assertJsonCount(0, 'executive_insights');
    }

    public function test_cache_key_incluye_tipo_de_lead_al_formar_parte_de_los_filtros(): void
    {
        $service = file_get_contents(app_path('Services/Reports/Leads/SalesforceLeadDashboardDatasetService.php'));

        $this->assertStringContainsString("'lead_type'", $service);
        $this->assertStringContainsString("'filters' => \$filters", $service);
    }

    private function lead(string $id, ?string $type, array $overrides = []): SalesforceLead
    {
        return SalesforceLead::create(array_merge([
            'salesforce_id' => $id,
            'name' => $id,
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Potencial',
            'record_type_name' => $type,
            'owner_id' => '005-commercial',
            'owner_name' => 'Comercial',
            'delegacion_encargada_text' => 'HR MOTOR TORREJON',
            'portal_text' => 'Web',
        ], $overrides));
    }
}
