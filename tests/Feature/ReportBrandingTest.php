<?php

namespace Tests\Feature;

use Tests\TestCase;

class ReportBrandingTest extends TestCase
{
    public function test_leads_usa_el_shell_con_branding_y_navegacion_lateral(): void
    {
        $response = $this->get('/informes/leads');

        $response->assertOk();
        $response->assertSee('Intereses | HR Motor - Informes comerciales');
        $response->assertSee('HR Motor - Informes comerciales');
        $response->assertSee('/brand/favicon.ico', false);
        $response->assertSee('/informes/reservas-ventas', false);
        $response->assertSee('class="app-sidebar"', false);
        $response->assertSee('data-sidebar-toggle', false);
        $response->assertSee('aria-current="page"', false);
        $response->assertSee('/brand/logo-horizontal.svg', false);
        $response->assertSee('alt="HR Motor"', false);
    }

    public function test_reservas_ventas_usa_el_mismo_shell_y_marca_su_enlace_activo(): void
    {
        $response = $this->get('/informes/reservas-ventas');

        $response->assertOk();
        $response->assertSee('Reservas / Ventas | HR Motor - Informes comerciales');
        $response->assertSee('HR Motor - Informes comerciales');
        $response->assertSee('/brand/favicon.ico', false);
        $response->assertSee('/informes/leads', false);
        $response->assertSee('class="app-sidebar"', false);
        $response->assertSee('data-sidebar-toggle', false);
        $response->assertSee('aria-current="page"', false);
        $response->assertSee('/brand/logo-horizontal.svg', false);
        $response->assertSee('alt="HR Motor"', false);
    }
}
