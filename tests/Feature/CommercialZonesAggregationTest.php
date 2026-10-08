<?php

namespace Tests\Feature;

use App\Models\SalesforceLead;
use App\Models\SalesforceUser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\MirrorsLeadFixturesToInterestReporting;
use Tests\TestCase;

class CommercialZonesAggregationTest extends TestCase
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

    public function test_agrupa_por_zona_comercial_y_respeta_filtro_zona(): void
    {
        $this->commercial('005-sur', 'Sur', 'HR MOTOR TORREJON');
        $this->commercial('005-cat', 'Cat', 'HR MOTOR SANT BOI');
        $this->lead('00Q1', 'Convertido', ['persona_que_trabajo_id' => '005-sur']);
        $this->lead('00Q2', 'Descartado', ['propietario_descarte_id' => '005-cat']);
        $this->lead('00Q3', 'Potencial', ['owner_id' => '005-sur']);

        $allRows = collect($this->getJson('/informes/leads/data/commercials')->json('zones'));
        $filteredRows = collect($this->getJson('/informes/leads/data/commercials?zone=Zona%20Sur%20y%20Centro')->json('zones'));

        $this->assertSame(2, $allRows->firstWhere('zone', 'Zona Sur y Centro')['leads_totales']);
        $this->assertSame(1, $allRows->firstWhere('zone', 'Zona Cataluña')['leads_totales']);
        $this->assertCount(1, $filteredRows);
        $this->assertSame('Zona Sur y Centro', $filteredRows->first()['zone']);
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
            'owner_id' => '005-sur',
            'owner_name' => 'Sur',
            'portal_text' => 'Web',
        ], $overrides));
    }
}
