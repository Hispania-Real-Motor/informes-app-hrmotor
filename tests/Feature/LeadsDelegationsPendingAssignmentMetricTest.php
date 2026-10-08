<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Concerns\CreatesLeadDashboardRows;
use Tests\Feature\Concerns\MirrorsLeadFixturesToInterestReporting;
use Tests\TestCase;

class LeadsDelegationsPendingAssignmentMetricTest extends TestCase
{
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

    public function test_delegaciones_muestran_totales_pendientes_con_owner_generico_y_porcentaje(): void
    {
        $this->leadRow('00Q-alco-1', [
            'delegacion_encargada_text' => 'Alcobendas',
            'owner_name' => 'API User',
        ]);
        $this->leadRow('00Q-alco-2', [
            'delegacion_encargada_text' => 'Alcobendas',
            'owner_name' => 'Carlos Torres',
        ]);
        $this->leadRow('00Q-alco-3', [
            'delegacion_encargada_text' => 'Alcobendas',
            'owner_name' => 'Comercial Real',
        ]);
        $this->leadRow('00Q-alco-4', [
            'delegacion_encargada_text' => 'Alcobendas',
            'status' => 'Convertido',
            'owner_name' => 'Admin Adesso',
        ]);

        $row = collect($this->getJson('/informes/leads/data/delegations')->json('items'))
            ->firstWhere('delegacion', 'Alcobendas');

        $this->assertSame(4, $row['leads_totales']);
        $this->assertSame(2, $row['leads_unassigned']);
        $this->assertSame(50.0, (float) $row['leads_unassigned_pct']);
    }
}
