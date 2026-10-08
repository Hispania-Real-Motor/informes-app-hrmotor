<?php

namespace Tests\Feature;

use App\Models\SalesforceLead;
use App\Models\SalesforceUser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\MirrorsLeadFixturesToInterestReporting;
use Tests\TestCase;

class CommercialDelegationsAggregationTest extends TestCase
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

    public function test_agrupa_por_delegacion_comercial_y_cruza_filtros_de_reparto(): void
    {
        $this->commercial('005-torrejon', 'Torrejon', 'HR MOTOR TORREJON');
        $this->commercial('005-rivas', 'Rivas', 'HR MOTOR RIVAS');
        $this->lead('00Q1', 'Convertido', [
            'persona_que_trabajo_id' => '005-torrejon',
            'delegacion_encargada_text' => 'Sant Boi',
        ]);
        $this->lead('00Q2', 'Potencial', [
            'owner_id' => '005-rivas',
            'delegacion_encargada_text' => 'Madrid',
        ]);

        $rows = collect($this->getJson('/informes/leads/data/commercials?lead_delegation=Sant%20Boi')->json('delegations'));

        $this->assertCount(1, $rows);
        $this->assertSame('Torrejón', $rows->first()['commercial_delegation']);
        $this->assertSame('Zona Sur y Centro', $rows->first()['zone']);
        $this->assertSame(1, $rows->first()['leads_totales']);
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
            'owner_id' => '005-torrejon',
            'owner_name' => 'Torrejon',
            'portal_text' => 'Web',
        ], $overrides));
    }
}
