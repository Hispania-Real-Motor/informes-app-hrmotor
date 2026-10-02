<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class ReportDesignSystemTest extends TestCase
{
    public function test_structural_pages_render_shared_primitives_without_fictitious_metrics(): void
    {
        $this->get('/informes')
            ->assertOk()
            ->assertSee('report-ui-page-header', false)
            ->assertSee('report-ui-kpi-strip', false)
            ->assertSee('report-ui-badge', false)
            ->assertSee('report-ui-status', false)
            ->assertSee('Resumen Ejecutivo')
            ->assertDontSee('Sin datos analíticos en este lote');

        $this->get('/informes/seo-analytics')
            ->assertOk()
            ->assertSee('report-ui-page-header', false)
            ->assertSee('report-ui-empty-state', false)
            ->assertSee('report-ui-badge', false)
            ->assertSee('report-ui-source-status', false)
            ->assertSee('Todavía no hay métricas SEO persistidas')
            ->assertSee('nunca ejecuta llamadas externas')
            ->assertDontSee('report-ui-status', false);
    }

    public function test_status_component_renders_every_official_state_with_icon_and_text(): void
    {
        foreach ([
            'ok' => 'Correcto',
            'observation' => 'Observación',
            'deviation' => 'Desviación relevante',
            'critical' => 'Crítico',
            'not-evaluable' => 'No evaluable',
        ] as $state => $label) {
            $html = Blade::render('<x-reports.ui.status :state="$state" />', compact('state'));

            $this->assertStringContainsString('report-ui-status--'.$state, $html);
            $this->assertStringContainsString('data-report-status="'.$state.'"', $html);
            $this->assertStringContainsString('aria-hidden="true"', $html);
            $this->assertStringContainsString($label, $html);
        }
    }

    public function test_unknown_status_falls_back_to_not_evaluable(): void
    {
        $html = Blade::render('<x-reports.ui.status state="unexpected" />');

        $this->assertStringContainsString('report-ui-status--not-evaluable', $html);
        $this->assertStringContainsString('data-report-status="not-evaluable"', $html);
        $this->assertStringContainsString('No evaluable', $html);
        $this->assertStringNotContainsString('Correcto', $html);
        $this->assertStringNotContainsString('Crítico', $html);
    }

    public function test_empty_state_supports_optional_action_without_unescaped_markup_contracts(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-reports.ui.empty-state title="Sin resultados">
                <x-slot:action><a class="report-ui-button" href="/informes">Volver</a></x-slot:action>
            </x-reports.ui.empty-state>
        BLADE);

        $this->assertStringContainsString('report-ui-empty-state__action', $html);
        $this->assertStringContainsString('class="report-ui-button"', $html);
        $this->assertStringContainsString('Sin resultados', $html);
    }

    public function test_shell_loads_design_system_before_shell_css_and_vite_registers_both(): void
    {
        $shell = file_get_contents(resource_path('views/components/reports/app-shell.blade.php'));
        $vite = file_get_contents(base_path('vite.config.js'));
        $css = file_get_contents(resource_path('css/reports/design-system.css'));

        $designPosition = strpos($shell, 'resources/css/reports/design-system.css');
        $shellPosition = strpos($shell, 'resources/css/reports/app-shell.css');

        $this->assertNotFalse($designPosition);
        $this->assertNotFalse($shellPosition);
        $this->assertLessThan($shellPosition, $designPosition);
        $this->assertStringContainsString("'resources/css/reports/design-system.css'", $vite);
        $this->assertStringContainsString('.report-ui-page-header', $css);
        $this->assertStringContainsString('.report-ui-card', $css);
        $this->assertStringContainsString('.report-ui-button', $css);
        $this->assertStringContainsString('.report-ui-field', $css);
        $this->assertStringContainsString('.report-ui-skeleton', $css);
        $this->assertStringContainsString('.report-ui-table-shell', $css);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);
        $this->assertStringNotContainsString('!important', $css);
    }
}
