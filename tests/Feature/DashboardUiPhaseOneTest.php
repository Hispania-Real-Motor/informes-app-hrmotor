<?php

namespace Tests\Feature;

use Tests\TestCase;

class DashboardUiPhaseOneTest extends TestCase
{
    public function test_html_inicial_muestra_carga_y_solo_filtros_fase_uno(): void
    {
        $response = $this->get('/informes/leads');

        $response->assertOk();
        $response->assertSee('Cargando fotografía local...');
        $response->assertSee('leadDelegation');
        $response->assertSee('Tipo de Interés');
        $response->assertSee('Tasación');
        $response->assertSee('Venta');
        $response->assertSee('commercialDelegation');
        $response->assertSee('Zona', false);
        $response->assertSee('Fuente');
        $response->assertSee('Comercial');
        $response->assertDontSee('Exposición');
        $response->assertSee('Comerciales/Delegaciones/Zonas');
        $response->assertSee('Delegaciones de procedencia');
        $response->assertSee('Buscar comercial');
        $response->assertSee('Añadir/Quitar columna');
        $response->assertSee('data-lead-commercial-tab-trigger="commercials"', false);
        $response->assertSee('data-lead-commercial-tab-trigger="delegations"', false);
        $response->assertSee('data-lead-commercial-tab-trigger="zones"', false);
        $response->assertSee('% convertidos');
        $response->assertSee('% descartados');
        $response->assertSee('% gestionados');
        $response->assertDontSee('id="channel"', false);
        $response->assertDontSee('id="status"', false);
        $response->assertDontSee('leadGroup');
        $response->assertDontSee('Grupo del lead');
        $response->assertDontSee('Venta con cambio');
        $response->assertDontSee('Datos actualizados: -');
        $response->assertDontSee('Grupo portal');
        $response->assertDontSee('Solo Exposicion', false);
        $response->assertDontSee('Informe mensual');
        $response->assertDontSee('KPIs clave');
        $response->assertDontSee('Detalle portal');
        $response->assertDontSee('Calidad de dato');
        $response->assertDontSee('data-panel="panel-comerciales">Comerciales</button>', false);
        $response->assertDontSee('Delegaciones / Zonas');
    }

    public function test_js_formatea_fecha_europea_y_no_expone_grupo_portal(): void
    {
        $js = file_get_contents(resource_path('js/reports/leads-dashboard.js'));

        $this->assertStringContainsString("Intl.DateTimeFormat('es-ES'", $js);
        $this->assertStringContainsString("day: '2-digit'", $js);
        $this->assertStringContainsString("month: '2-digit'", $js);
        $this->assertStringContainsString('makeTableSortable', $js);
        $this->assertStringContainsString('parseSortableValue', $js);
        $this->assertStringContainsString("'leadType'", $js);
        $this->assertStringContainsString('lead_type', $js);
        $this->assertStringContainsString('bindLeadCommercialTabs', $js);
        $this->assertStringContainsString('bindLeadCommercialSearch', $js);
        $this->assertStringContainsString('applyCommercialSearchFilter', $js);
        $this->assertStringContainsString('leadCommercialZoneColumns', $js);
        $this->assertStringContainsString('leadCommercialDelegationColumns', $js);
        $this->assertStringContainsString('data-sort-value', $js);
        $this->assertStringNotContainsString("'leadGroup'", $js);
        $this->assertStringNotContainsString("'channel'", $js);
        $this->assertStringNotContainsString("'status'", $js);
        $this->assertStringNotContainsString('portalGroup', $js);
        $this->assertStringNotContainsString('portal_group', $js);
    }
}
