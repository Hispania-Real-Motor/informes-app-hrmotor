<?php

namespace Tests\Feature;

use App\Models\SalesforceOpportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ReservasVentasPercentagesDoNotUseRowTotalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['openai.enabled' => false]);
    }

    public function test_conversion_usa_total_de_fila_y_participacion_usa_total_de_columna(): void
    {
        $this->opportunities('006-web-reserva', 111, ['portal_resolved' => 'Web', 'stage_name' => 'Reserva', 'reservation' => true]);
        $this->opportunities('006-web-neutra', 3689, ['portal_resolved' => 'Web']);
        $this->opportunities('006-coches-reserva', 233, ['portal_resolved' => 'Coches.net', 'stage_name' => 'Reserva', 'reservation' => true]);

        $row = collect($this->getJson('/informes/reservas-ventas/data/portals?'.$this->reservasVentasQuery())->json('items'))
            ->firstWhere('portal', 'Web');

        $this->assertSame(3800, $row['oportunidades_totales']);
        $this->assertSame(111, $row['reservas_vivas']);
        $this->assertSame(2.92, (float) $row['reservas_vivas_pct']);
        $this->assertSame(32.27, (float) $row['reservas_vivas_participation_pct']);
    }

    private function opportunities(string $prefix, int $count, array $attributes = []): void
    {
        $now = now();
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $id = "$prefix-$i";
            $row = array_merge([
                'salesforce_id' => $id,
                'name' => $id,
                'created_date' => '2026-05-10 10:00:00',
                'stage_name' => 'Abierta',
                'record_type_name' => 'Venta',
                'owner_id' => '005-owner',
                'owner_name' => 'Comercial',
                'owner_delegation' => 'Alcobendas',
                'portal_resolved' => 'Web',
                'portal_original' => 'Web',
                'portal_resolution_source' => 'opportunity',
                'reservation' => false,
                'cv_signed' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ], $attributes);
            $row['portal_original'] = $attributes['portal_resolved'] ?? $row['portal_original'];
            $rows[] = $row;
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
