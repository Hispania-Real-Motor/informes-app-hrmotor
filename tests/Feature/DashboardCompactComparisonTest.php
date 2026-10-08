<?php

namespace Tests\Feature;

use App\Models\SalesforceLead;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\MirrorsLeadFixturesToInterestReporting;
use Tests\TestCase;

class DashboardCompactComparisonTest extends TestCase
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

    public function test_comparativa_compacta_metricas_con_porcentaje(): void
    {
        $this->lead('00Q-current-1', 'Convertido', '2026-05-10 10:00:00');
        $this->lead('00Q-current-2', 'Potencial', '2026-05-10 11:00:00');
        $this->lead('00Q-prev-1', 'Convertido', '2026-04-01 10:00:00');
        $this->lead('00Q-prev-2', 'Descartado', '2026-04-01 11:00:00');

        $rows = collect($this->getJson('/informes/leads/data/summary')->json('comparativa'));

        $this->assertNull($rows->firstWhere('key', 'conversion_pct'));
        $this->assertNull($rows->firstWhere('key', 'descarte_pct'));
        $this->assertNull($rows->firstWhere('key', 'gestionados_pct'));

        $converted = $rows->firstWhere('key', 'convertidos');
        $discarded = $rows->firstWhere('key', 'descartados');
        $managed = $rows->firstWhere('key', 'gestionados');

        $this->assertTrue($converted['is_compact']);
        $this->assertEquals(50.0, $converted['periodo_actual_pct']);
        $this->assertArrayHasKey('diferencia_pct_puntos', $converted);
        $this->assertTrue($discarded['is_compact']);
        $this->assertTrue($managed['is_compact']);
    }

    private function lead(string $id, string $status, string $createdDate): void
    {
        SalesforceLead::create([
            'salesforce_id' => $id,
            'name' => $id,
            'created_date' => $createdDate,
            'status' => $status,
            'owner_id' => '005-owner',
            'owner_name' => 'Owner',
            'portal_text' => 'Web',
            'delegacion_encargada_text' => 'HR MOTOR TORREJON',
        ]);
    }
}
