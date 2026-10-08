<?php

namespace Tests\Feature;

use App\Models\SalesforceLead;
use App\Models\SalesforceUser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\MirrorsLeadFixturesToInterestReporting;
use Tests\TestCase;

class DashboardZoneFilterTest extends TestCase
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

    public function test_zona_filtra_por_delegacion_comercial_en_summary_portales_y_delegaciones(): void
    {
        $this->commercial('005-sur', 'Comercial Sur', 'HR MOTOR TORREJON');
        $this->commercial('005-cat', 'Comercial Cat', 'HR MOTOR SANT BOI');
        $this->lead('00Q-sur', 'Potencial', ['owner_id' => '005-sur', 'owner_name' => 'Comercial Sur', 'delegacion_encargada_text' => 'Sant Boi', 'portal_text' => 'Web']);
        $this->lead('00Q-cat', 'Potencial', ['owner_id' => '005-cat', 'owner_name' => 'Comercial Cat', 'delegacion_encargada_text' => 'Torrejón de Ardoz', 'portal_text' => 'Web']);

        $summary = $this->getJson('/informes/leads/data/summary?zone=Zona%20Sur%20y%20Centro');
        $delegations = collect($this->getJson('/informes/leads/data/delegations?zone=Zona%20Sur%20y%20Centro')->json('items'));
        $portals = collect($this->getJson('/informes/leads/data/portals?zone=Zona%20Sur%20y%20Centro')->json('items'));

        $this->assertSame(1, $summary->json('kpis.leads_totales'));
        $this->assertSame('Sant Boi', $delegations->first()['delegacion']);
        $this->assertSame(1, $portals->firstWhere('portal', 'Web')['leads_totales']);
    }

    public function test_zona_filtra_por_delegacion_comercial_en_comerciales(): void
    {
        $this->commercial('005-sur', 'Comercial Sur', 'HR MOTOR TORREJON');
        $this->commercial('005-cat', 'Comercial Cat', 'HR MOTOR SANT BOI');
        $this->lead('00Q-sur', 'Potencial', ['owner_id' => '005-sur', 'owner_name' => 'Comercial Sur', 'delegacion_encargada_text' => 'Sant Boi']);
        $this->lead('00Q-cat', 'Potencial', ['owner_id' => '005-cat', 'owner_name' => 'Comercial Cat', 'delegacion_encargada_text' => 'Torrejón']);

        $rows = collect($this->getJson('/informes/leads/data/commercials?zone=Zona%20Sur%20y%20Centro')->json('items'));

        $this->assertTrue($rows->contains('comercial', 'Comercial Sur'));
        $this->assertFalse($rows->contains('comercial', 'Comercial Cat'));
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
            'owner_id' => '005-owner',
            'owner_name' => 'Owner',
            'medio_nuevo' => 'Formulario',
            'portal_text' => 'Web',
        ], $overrides));
    }
}
