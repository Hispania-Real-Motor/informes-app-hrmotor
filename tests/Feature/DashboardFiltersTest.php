<?php

namespace Tests\Feature;

use App\Models\SalesforceLead;
use App\Models\SalesforceUser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\MirrorsLeadFixturesToInterestReporting;
use Tests\TestCase;

class DashboardFiltersTest extends TestCase
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

    public function test_filter_options_separan_delegaciones_grupos_y_zonas(): void
    {
        SalesforceUser::create([
            'salesforce_id' => '005-commercial',
            'name' => 'Comercial',
            'profile_name' => 'Compra/Venta',
            'user_delegation' => 'HR MOTOR TORREJON',
            'is_active' => true,
        ]);

        foreach ([
            ['00Q1', 'Torrejón de Ardoz'],
            ['00Q2', 'Sant Boi'],
            ['00Q3', 'Valencia Sedavi'],
            ['00Q4', 'Web Alicante'],
            ['00Q5', 'leadsmadrid@hrmotor.com'],
        ] as [$id, $delegation]) {
            SalesforceLead::create([
                'salesforce_id' => $id,
                'name' => $id,
                'created_date' => '2026-05-10 10:00:00',
                'status' => 'Potencial',
                'owner_id' => '005-commercial',
                'owner_name' => 'Comercial',
                'delegacion_encargada_text' => $delegation,
                'portal_text' => 'Web',
            ]);
        }

        $filters = $this->getJson('/informes/leads/data/summary')->json('filters');

        $this->assertNotContains('lead_groups', array_keys($filters));
        $this->assertNotContains('Grupo Madrid', $filters['lead_delegations']);
        $this->assertNotContains('leadsmadrid@hrmotor.com', $filters['lead_delegations']);
        $this->assertNotContains('Grupo Madrid', $filters['commercial_delegations']);
        $this->assertSame([
            'Zona Cataluña',
            'Zona Mediterraneo',
            'Zona Norte',
            'Zona Sur y Centro',
        ], $filters['zones']);
    }
}
