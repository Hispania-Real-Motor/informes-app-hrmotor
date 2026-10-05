<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class ReportAnalyticalPatternsTest extends TestCase
{
    public function test_design_system_exposes_analytical_contracts_without_legacy_overrides(): void
    {
        $css = file_get_contents(resource_path('css/reports/design-system.css'));

        foreach ([
            '.report-ui-kpi-strip',
            '.report-ui-data-panel',
            '.report-ui-section-header',
            '.report-ui-tabs',
            '.report-ui-tab',
            '.report-ui-filter-bar',
            '.report-ui-source-status',
            '.report-ui-table-row--highlight',
            '.report-ui-table__numeric',
            '.report-ui-table--sticky-header',
        ] as $contract) {
            $this->assertStringContainsString($contract, $css);
        }

        $this->assertStringNotContainsString('!important', $css);
        $this->assertStringNotContainsString('.main-tab', $css);
        $this->assertStringNotContainsString('.filter-reset', $css);
        $this->assertStringNotContainsString('.filter-group', $css);
    }

    public function test_section_header_renders_optional_content_actions_and_escaped_props(): void
    {
        $title = '<script>alert("section")</script>';
        $description = '<strong>Contexto</strong>';
        $html = Blade::render(<<<'BLADE'
            <x-reports.ui.section-header eyebrow="Auditoría" :title="$title" :description="$description">
                <x-slot:actions><a class="report-ui-button" href="/informes">Revisar</a></x-slot:actions>
            </x-reports.ui.section-header>
        BLADE, compact('title', 'description'));

        $this->assertStringContainsString('report-ui-section-header__eyebrow', $html);
        $this->assertStringContainsString('report-ui-section-header__actions', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&lt;strong&gt;Contexto&lt;/strong&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<strong>Contexto</strong>', $html);
    }

    public function test_source_status_supports_optional_metadata_indicator_and_escaped_props(): void
    {
        $titleOnly = Blade::render('<x-reports.ui.source-status title="Salesforce" />');

        $this->assertStringContainsString('report-ui-source-status__title', $titleOnly);
        $this->assertStringNotContainsString('report-ui-source-status__detail', $titleOnly);
        $this->assertStringNotContainsString('report-ui-source-status__meta', $titleOnly);
        $this->assertStringNotContainsString('report-ui-source-status__indicator', $titleOnly);

        $title = '<script>alert("source")</script>';
        $detail = '<img src=x onerror=alert(1)>';
        $meta = '<strong>Actualizado</strong>';
        $html = Blade::render(<<<'BLADE'
            <x-reports.ui.source-status :title="$title" :detail="$detail" :meta="$meta">
                <x-slot:indicator><span class="report-ui-badge">Metadata</span></x-slot:indicator>
            </x-reports.ui.source-status>
        BLADE, compact('title', 'detail', 'meta'));

        $this->assertStringContainsString('report-ui-source-status__detail', $html);
        $this->assertStringContainsString('report-ui-source-status__meta', $html);
        $this->assertStringContainsString('report-ui-source-status__indicator', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringContainsString('&lt;strong&gt;Actualizado&lt;/strong&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
    }

    public function test_source_status_does_not_define_a_functional_status_taxonomy(): void
    {
        $component = file_get_contents(resource_path('views/components/reports/ui/source-status.blade.php'));

        $this->assertStringNotContainsString('report-ui-status--', $component);
        $this->assertStringNotContainsString('Correcto', $component);
        $this->assertStringNotContainsString('Crítico', $component);
        $this->assertStringNotContainsString('not-evaluable', $component);
    }

    public function test_summary_renders_real_executive_metrics_and_seo_remains_structural(): void
    {
        $this->get('/informes')
            ->assertOk()
            ->assertSee('Resumen Ejecutivo')
            ->assertSee('report-ui-kpi-strip', false)
            ->assertSee('report-ui-table', false)
            ->assertSee('report-ui-status', false)
            ->assertDontSee('report-ui-source-status', false)
            ->assertDontSee('Sin datos analíticos en este lote');

        $this->get('/informes/seo-analytics')
            ->assertOk()
            ->assertSee('report-ui-source-status', false)
            ->assertDontSee('report-ui-kpi-strip', false)
            ->assertDontSee('report-ui-table', false)
            ->assertDontSee('report-ui-status', false);
    }
}
