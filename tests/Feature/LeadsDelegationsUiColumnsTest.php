<?php

namespace Tests\Feature;

use Tests\TestCase;

class LeadsDelegationsUiColumnsTest extends TestCase
{
    public function test_tabla_de_delegaciones_de_reparto_no_muestra_zona_comercial_ni_grupo_lead(): void
    {
        $response = $this->get('/informes/leads');

        $response->assertOk();

        $section = $this->sectionBetween(
            $response->getContent(),
            '<section id="panel-delegaciones"',
            '<section id="panel-portales"'
        );

        $this->assertMatchesRegularExpression('/Delegaci.{1,4}n de procedencia/', $section);
        $this->assertStringContainsString('delegationRows', $section);
        $this->assertStringContainsString('Intereses totales', $section);
        $this->assertStringContainsString('Potencial con owner gen', $section);
        $this->assertStringContainsString('% pendiente', $section);
        $this->assertStringNotContainsString('Zona comercial', $section);
        $this->assertStringNotContainsString('Grupo Lead', $section);
        $this->assertStringNotContainsString('Convertidos</th>', $section);
        $this->assertStringNotContainsString('Descartados</th>', $section);
        $this->assertStringNotContainsString('Potenciales sin trabajar</th>', $section);
        $this->assertStringNotContainsString('Gestionados</th>', $section);

        $js = file_get_contents(resource_path('js/reports/leads-dashboard.js'));
        $renderDelegations = $this->sectionBetween($js, 'function renderDelegations', 'function renderPortals');

        $this->assertStringContainsString('(row) => row.delegacion', $renderDelegations);
        $this->assertStringContainsString('row.leads_unassigned', $renderDelegations);
        $this->assertStringContainsString('row.leads_unassigned_pct', $renderDelegations);
        $this->assertStringNotContainsString('row.zone', $renderDelegations);
        $this->assertStringNotContainsString('row.convertidos', $renderDelegations);
    }

    private function sectionBetween(string $content, string $startNeedle, string $endNeedle): string
    {
        $start = strpos($content, $startNeedle);
        $this->assertNotFalse($start);

        $end = strpos($content, $endNeedle, $start);
        $this->assertNotFalse($end);

        return substr($content, $start, $end - $start);
    }
}
