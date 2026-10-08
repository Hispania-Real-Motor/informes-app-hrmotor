<?php

namespace Tests\Feature;

use App\Models\SalesforceLead;
use App\Models\SalesforceUser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\MirrorsLeadFixturesToInterestReporting;
use Tests\TestCase;

class CommercialsEndpointTest extends TestCase
{
    use MirrorsLeadFixturesToInterestReporting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-13 12:00:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_commercials_endpoint_devuelve_zonas_delegaciones_y_comerciales(): void
    {
        $this->commercial('005-worker', 'Comercial Torrejon', 'HR MOTOR TORREJON');
        $this->lead('00Q1', 'Convertido', ['persona_que_trabajo_id' => '005-worker', 'persona_que_trabajo_name' => 'Comercial Torrejon']);

        $response = $this->getJson('/informes/leads/data/commercials');

        $response->assertOk();
        $response->assertJsonStructure([
            'ok',
            'zones' => [['zone', 'leads_totales', 'convertidos', 'conversion_pct', 'descartados', 'descarte_pct', 'potenciales_pct', 'potenciales_sin_trabajar_pct', 'gestionados_pct']],
            'delegations' => [['commercial_delegation', 'zone', 'leads_totales', 'convertidos', 'potenciales_pct']],
            'commercials' => [['comercial', 'commercial_delegation', 'zone', 'leads_totales', 'convertidos', 'potenciales_pct', 'potenciales_sin_trabajar_pct', 'gestionados_pct']],
            'items',
        ]);
        $this->assertSame('Zona Sur y Centro', $response->json('zones.0.zone'));
        $this->assertSame('Torrejón', $response->json('delegations.0.commercial_delegation'));
        $this->assertSame('Comercial Torrejon', $response->json('commercials.0.comercial'));
    }

    private function commercial(string $id, string $name, string $delegation): void
    {
        SalesforceUser::create([
            'salesforce_id' => $id,
            'name' => $name,
            'profile_name' => 'Compra/Venta',
            'user_delegation' => $delegation,
            'is_active' => true,
        ]);
    }

    private function lead(string $id, string $status, array $overrides = []): void
    {
        SalesforceLead::create(array_merge([
            'salesforce_id' => $id,
            'name' => $id,
            'created_date' => '2026-05-10 10:00:00',
            'status' => $status,
            'owner_id' => '005-worker',
            'owner_name' => 'Comercial Torrejon',
            'portal_text' => 'Web',
        ], $overrides));
    }
}
