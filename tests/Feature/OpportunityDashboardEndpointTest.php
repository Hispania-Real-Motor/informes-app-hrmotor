<?php

namespace Tests\Feature;

use App\Models\SalesforceOpportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpportunityDashboardEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_commercials_y_portals_devuelven_datos(): void
    {
        $this->createOpportunity('006-1', [
            'created_date' => '2026-05-10 10:00:00',
            'owner_name' => 'Comercial Uno',
            'owner_delegation' => 'Alcobendas',
            'portal_resolved' => 'Web',
            'record_type_name' => 'Venta',
            'reservation' => true,
            'cv_signed' => false,
            'stage_name' => 'Reserva',
        ]);
        $this->createOpportunity('006-2', [
            'created_date' => '2026-05-11 10:00:00',
            'owner_name' => 'Comercial Uno',
            'owner_delegation' => 'Alcobendas',
            'portal_resolved' => 'Meta',
            'record_type_name' => 'Cambio',
            'reservation' => true,
            'cv_signed' => true,
            'stage_name' => 'Contrato',
        ]);
        $this->createOpportunity('006-3', [
            'created_date' => '2026-05-12 10:00:00',
            'owner_name' => 'Comercial Dos',
            'owner_delegation' => 'Sant Boi',
            'portal_resolved' => 'Web',
            'record_type_name' => 'Tasacion',
            'reservation' => true,
            'cv_signed' => true,
            'stage_name' => 'Cerrada Perdida',
        ]);

        $query = [
            'period' => 'custom',
            'date_criterion' => 'created_date',
            'current_start' => '2026-05-01',
            'current_end' => '2026-05-31',
            'comparison_start' => '2026-04-01',
            'comparison_end' => '2026-04-30',
        ];

        $this->getJson('/informes/reservas-ventas/data/summary?'.http_build_query($query))
            ->assertOk()
            ->assertJsonPath('kpis.oportunidades_totales', 3)
            ->assertJsonPath('kpis.reservas_vivas', 1)
            ->assertJsonPath('kpis.cv_firmados', 1)
            ->assertJsonPath('kpis.oportunidades_caidas', 1)
            ->assertJsonStructure([
                'filters' => [
                    'commercials',
                    'commercial_delegations',
                    'zones',
                    'opportunity_types',
                ],
            ])
            ->assertJsonStructure([
                'periodo_actual' => ['inicio', 'fin', 'technical' => ['start_inclusive', 'end_exclusive', 'semantics', 'timezone']],
                'periodo_comparado' => ['inicio', 'fin', 'technical' => ['start_inclusive', 'end_exclusive', 'semantics', 'timezone']],
                'produccion_periodo' => [
                    'periodo_actual' => ['reservas', 'ventas'],
                    'periodo_comparado' => ['reservas', 'ventas'],
                    'comparativa',
                    'date_fields' => ['reservas', 'ventas'],
                    'interval_semantics',
                ],
                'cohorte_creacion' => [
                    'date_criterion',
                    'date_label',
                    'periodo_actual' => ['oportunidades_totales', 'reservas_vivas', 'oportunidades_caidas', 'cv_firmados'],
                    'periodo_comparado',
                    'comparativa',
                ],
            ])
            ->assertJsonPath('produccion_periodo.date_fields.reservas', 'reservation_date')
            ->assertJsonPath('produccion_periodo.date_fields.ventas', 'cv_signed_date')
            ->assertJsonPath('produccion_periodo.interval_semantics', '[start,end)')
            ->assertJsonPath('cohorte_creacion.date_criterion', 'created_date')
            ->assertJsonStructure(['executive_insights', 'executive_insights_source']);

        $this->getJson('/informes/reservas-ventas/data/commercials?'.http_build_query($query))
            ->assertOk()
            ->assertJsonStructure(['zones', 'delegations', 'commercials']);

        $this->getJson('/informes/reservas-ventas/data/portals?'.http_build_query($query))
            ->assertOk()
            ->assertJsonPath('items.0.portal', 'Web');
    }

    public function test_kpi_audit_devuelve_oportunidades_del_kpi_seleccionado(): void
    {
        $this->createOpportunity('006-1', [
            'created_date' => '2026-05-10 10:00:00',
            'reservation' => true,
            'reservation_date' => '2026-05-11',
            'cv_signed' => false,
            'stage_name' => 'Reserva',
            'record_type_name' => 'Venta',
            'account_id' => '001-1',
            'account_name' => 'Cuenta 1',
            'portal_original' => 'Web',
            'portal_resolved' => 'Web',
            'opportunity_source_raw' => 'COCHES.NET',
            'opportunity_source_normalized' => 'Coches.net',
        ]);
        $this->createOpportunity('006-2', [
            'created_date' => '2026-05-12 10:00:00',
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-14',
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
        ]);

        $query = http_build_query([
            'period' => 'custom',
            'date_criterion' => 'created_date',
            'current_start' => '2026-05-01',
            'current_end' => '2026-05-31',
            'comparison_start' => '2026-04-01',
            'comparison_end' => '2026-04-30',
            'metric' => 'reservas_vivas',
        ]);

        $payload = $this->getJson('/informes/reservas-ventas/data/kpi-audit?'.$query)
            ->assertOk()
            ->json();

        $this->assertSame('reservas_vivas', $payload['metric']);
        $this->assertSame(1, $payload['total']);
        $this->assertSame('006-1', $payload['items'][0]['opportunity_id']);
        $this->assertSame('001-1', $payload['items'][0]['account_id']);

        $this->get('/informes/reservas-ventas/export/kpi-audit.csv?'.$query)
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_filtro_de_comercial_y_catalogo_de_filtros_aplican_en_summary_y_commercials(): void
    {
        $this->createOpportunity('006-filter-1', [
            'created_date' => '2026-05-10 10:00:00',
            'owner_id' => '005-a',
            'owner_name' => 'Comercial Alcobendas',
            'owner_delegation' => 'Alcobendas',
            'portal_resolved' => 'Web',
            'record_type_name' => 'Venta',
            'reservation' => true,
            'stage_name' => 'Reserva',
        ]);
        $this->createOpportunity('006-filter-2', [
            'created_date' => '2026-05-11 10:00:00',
            'owner_id' => '005-b',
            'owner_name' => 'Comercial Sant Boi',
            'owner_delegation' => 'Sant Boi',
            'portal_resolved' => 'Meta',
            'record_type_name' => 'Cambio',
            'cv_signed' => true,
            'stage_name' => 'Contrato',
        ]);

        $query = [
            'period' => 'custom',
            'date_criterion' => 'created_date',
            'current_start' => '2026-05-01',
            'current_end' => '2026-05-31',
            'comparison_start' => '2026-04-01',
            'comparison_end' => '2026-04-30',
            'commercial' => '005-a',
        ];

        $this->getJson('/informes/reservas-ventas/data/summary?'.http_build_query($query))
            ->assertOk()
            ->assertJsonPath('kpis.oportunidades_totales', 1)
            ->assertJsonPath('kpis.reservas_vivas', 1)
            ->assertJsonPath('kpis.cv_firmados', 0)
            ->assertJsonPath('filters.commercials.0.id', '005-a');

        $this->getJson('/informes/reservas-ventas/data/commercials?'.http_build_query($query))
            ->assertOk()
            ->assertJsonPath('commercials.0.comercial', 'Comercial Alcobendas')
            ->assertJsonPath('commercials.0.commercial_delegation', 'Alcobendas')
            ->assertJsonPath('commercials.0.zone', 'Zona Sur y Centro');
    }

    private function createOpportunity(string $id, array $attributes): void
    {
        $attributes['portal_original'] ??= $attributes['portal_resolved'] ?? 'Web';
        SalesforceOpportunity::query()->create(array_merge([
            'salesforce_id' => $id,
            'name' => $id,
            'owner_id' => '005-'.$id,
            'owner_name' => 'Comercial',
            'owner_delegation' => 'Alcobendas',
            'portal_resolved' => 'Web',
            'portal_original' => 'Web',
            'portal_resolution_source' => 'opportunity',
            'reservation' => false,
            'cv_signed' => false,
        ], $attributes));
    }
}
