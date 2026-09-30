<?php

namespace Tests\Feature;

use App\Models\SalesforceOpportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ReservasVentasPortalPercentagesColumnTotalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['openai.enabled' => false]);
    }

    public function test_portales_separan_conversion_y_participacion(): void
    {
        $this->opportunities('006-web-reserva', 111, ['portal_resolved' => 'Web', 'stage_name' => 'Reserva', 'reservation' => true]);
        $this->opportunities('006-web-caida', 30, ['portal_resolved' => 'Web', 'stage_name' => 'Cerrada Perdida']);
        $this->opportunities('006-web-cv', 20, ['portal_resolved' => 'Web', 'stage_name' => 'Contrato', 'reservation' => true, 'cv_signed' => true]);

        $this->opportunities('006-coches-reserva', 233, ['portal_resolved' => 'Coches.net', 'stage_name' => 'Reserva', 'reservation' => true]);
        $this->opportunities('006-coches-caida', 90, ['portal_resolved' => 'Coches.net', 'stage_name' => 'Cerrada Perdida']);
        $this->opportunities('006-coches-cv', 60, ['portal_resolved' => 'Coches.net', 'stage_name' => 'Contrato', 'reservation' => true, 'cv_signed' => true]);

        $rows = collect($this->getJson('/informes/reservas-ventas/data/portals?'.$this->reservasVentasQuery())->json('items'));
        $web = $rows->firstWhere('portal', 'Web');
        $coches = $rows->firstWhere('portal', 'Coches.net');

        $this->assertSame(111, $web['reservas_vivas']);
        $this->assertSame(30, $web['oportunidades_caidas']);
        $this->assertSame(20, $web['cv_firmados']);
        $this->assertSame(68.94, (float) $web['reservas_vivas_pct']);
        $this->assertSame(18.63, (float) $web['oportunidades_caidas_pct']);
        $this->assertSame(12.42, (float) $web['cv_firmados_pct']);
        $this->assertSame(32.27, (float) $web['reservas_vivas_participation_pct']);
        $this->assertSame(25.0, (float) $web['oportunidades_caidas_participation_pct']);
        $this->assertSame(25.0, (float) $web['cv_firmados_participation_pct']);

        $this->assertSame(233, $coches['reservas_vivas']);
        $this->assertSame(90, $coches['oportunidades_caidas']);
        $this->assertSame(60, $coches['cv_firmados']);
        $this->assertSame(60.84, (float) $coches['reservas_vivas_pct']);
        $this->assertSame(23.5, (float) $coches['oportunidades_caidas_pct']);
        $this->assertSame(15.67, (float) $coches['cv_firmados_pct']);
        $this->assertSame(67.73, (float) $coches['reservas_vivas_participation_pct']);
        $this->assertSame(75.0, (float) $coches['oportunidades_caidas_participation_pct']);
        $this->assertSame(75.0, (float) $coches['cv_firmados_participation_pct']);
    }

    private function opportunities(string $prefix, int $count, array $attributes = []): void
    {
        $now = now();
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $id = "$prefix-$i";
            $rows[] = array_merge([
                'salesforce_id' => $id,
                'name' => $id,
                'created_date' => '2026-05-10 10:00:00',
                'stage_name' => 'Abierta',
                'record_type_name' => 'Venta',
                'owner_id' => '005-owner',
                'owner_name' => 'Comercial',
                'owner_delegation' => 'Alcobendas',
                'portal_resolved' => 'Web',
                'portal_resolution_source' => 'opportunity',
                'reservation' => false,
                'cv_signed' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ], $attributes);
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            SalesforceOpportunity::query()->insert($chunk);
        }
    }

    private function reservasVentasQuery(): string
    {
        return http_build_query([
            'period' => 'custom',
            'date_criterion' => 'created_date',
            'current_start' => '2026-05-01',
            'current_end' => '2026-05-31',
            'comparison_start' => '2026-04-01',
            'comparison_end' => '2026-04-30',
        ]);
    }
}
