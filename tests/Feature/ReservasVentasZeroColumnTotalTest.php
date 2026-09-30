<?php

namespace Tests\Feature;

use App\Models\SalesforceOpportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ReservasVentasZeroColumnTotalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['openai.enabled' => false]);
    }

    public function test_total_de_columna_cero_devuelve_porcentaje_cero_en_tablas(): void
    {
        $this->opportunities('006-web-neutra', 3, ['portal_resolved' => 'Web']);
        $this->opportunities('006-coches-neutra', 2, ['portal_resolved' => 'Coches.net']);

        $row = collect($this->getJson('/informes/reservas-ventas/data/portals?'.$this->reservasVentasQuery())->json('items'))
            ->firstWhere('portal', 'Web');

        $this->assertSame(0, $row['reservas_vivas']);
        $this->assertSame(0, $row['oportunidades_caidas']);
        $this->assertSame(0, $row['cv_firmados']);
        $this->assertSame(0.0, (float) $row['reservas_vivas_pct']);
        $this->assertSame(0.0, (float) $row['oportunidades_caidas_pct']);
        $this->assertSame(0.0, (float) $row['cv_firmados_pct']);
        $this->assertSame(0.0, (float) $row['reservas_vivas_participation_pct']);
        $this->assertSame(0.0, (float) $row['oportunidades_caidas_participation_pct']);
        $this->assertSame(0.0, (float) $row['cv_firmados_participation_pct']);
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
