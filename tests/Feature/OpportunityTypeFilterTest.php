<?php

namespace Tests\Feature;

use App\Models\SalesforceOpportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpportunityTypeFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_tipo_venta_incluye_venta_y_cambio_y_tasacion_filtra_tasacion(): void
    {
        foreach (['Tasacion', 'Venta', 'Cambio', 'Otro', null] as $index => $type) {
            SalesforceOpportunity::query()->create([
                'salesforce_id' => '006-'.$index,
                'created_date' => '2026-05-10 10:00:00',
                'record_type_name' => $type,
                'stage_name' => 'Reserva',
                'owner_id' => '005-'.$index,
                'owner_name' => 'Comercial',
                'owner_delegation' => 'Alcobendas',
                'portal_resolved' => 'Web',
                'portal_original' => 'Web',
                'reservation' => true,
                'cv_signed' => false,
            ]);
        }

        $base = [
            'period' => 'custom',
            'date_criterion' => 'created_date',
            'current_start' => '2026-05-01',
            'current_end' => '2026-05-31',
            'comparison_start' => '2026-04-01',
            'comparison_end' => '2026-04-30',
        ];

        $this->assertTotal(5, array_merge($base, ['opportunity_type' => 'all']));
        $this->assertTotal(1, array_merge($base, ['opportunity_type' => 'Tasacion']));
        $this->assertTotal(2, array_merge($base, ['opportunity_type' => 'Venta']));

        $response = $this->get('/informes/reservas-ventas');
        $response->assertOk();
        $response->assertSee('Tasación');
        $response->assertSee('Venta');
        $response->assertDontSee('Cambio</option>', false);
    }

    public function test_ventas_de_produccion_solo_cuentan_venta_y_cambio_sin_redefinir_el_kpi_legacy(): void
    {
        foreach (['Venta', 'Cambio', 'Tasacion', 'Otro', null] as $index => $type) {
            SalesforceOpportunity::query()->create([
                'salesforce_id' => '006-production-'.$index,
                'created_date' => '2026-05-10 10:00:00',
                'record_type_name' => $type,
                'stage_name' => 'Contrato',
                'owner_id' => '005-production-'.$index,
                'owner_name' => 'Comercial',
                'owner_delegation' => 'Alcobendas',
                'portal_resolved' => 'Web',
                'portal_original' => 'Web',
                'reservation' => false,
                'cv_signed' => true,
                'cv_signed_date' => '2026-05-15',
                'vehicle_interest_id' => '01t-production-'.$index,
            ]);
        }

        $base = [
            'period' => 'custom',
            'date_criterion' => 'created_date',
            'current_start' => '2026-05-01',
            'current_end' => '2026-05-31',
            'comparison_start' => '2026-04-01',
            'comparison_end' => '2026-04-30',
        ];

        $this->getJson('/informes/reservas-ventas/data/summary?'.http_build_query(array_merge($base, ['opportunity_type' => 'all'])))
            ->assertOk()
            ->assertJsonPath('kpis.cv_firmados', 5)
            ->assertJsonPath('produccion_periodo.periodo_actual.ventas', 2);

        $this->getJson('/informes/reservas-ventas/data/summary?'.http_build_query(array_merge($base, ['opportunity_type' => 'Venta'])))
            ->assertOk()
            ->assertJsonPath('kpis.cv_firmados', 2)
            ->assertJsonPath('produccion_periodo.periodo_actual.ventas', 2);

        $this->getJson('/informes/reservas-ventas/data/summary?'.http_build_query(array_merge($base, ['opportunity_type' => 'Tasacion'])))
            ->assertOk()
            ->assertJsonPath('kpis.cv_firmados', 1)
            ->assertJsonPath('produccion_periodo.periodo_actual.ventas', 0);
    }

    private function assertTotal(int $expected, array $query): void
    {
        $this->getJson('/informes/reservas-ventas/data/summary?'.http_build_query($query))
            ->assertOk()
            ->assertJsonPath('kpis.oportunidades_totales', $expected);
    }
}
