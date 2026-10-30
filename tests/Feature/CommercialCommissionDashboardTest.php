<?php

namespace Tests\Feature;

use App\Models\CommercialCommissionClosure;
use App\Models\CommercialCommissionSnapshot;
use App\Models\ReportSyncRun;
use App\Models\ReportUser;
use App\Models\SalesforceOpportunity;
use App\Models\SalesforceReview;
use App\Models\SalesforceUser;
use App\Services\Reports\AreaManagerCommissions\AreaManagerCommissionDashboardService;
use App\Services\Reports\CommercialCommissions\CommercialCommissionClosureService;
use App\Services\Reports\CommercialCommissions\CommercialCommissionDashboardService;
use App\Services\Reports\CommercialCommissions\CommercialCommissionDelegationReviewsService;
use App\Services\Reports\CommercialCommissions\CommercialCommissionFormulaConfigService;
use App\Services\Reports\FinancialCommissions\FinancialCommissionDashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

class CommercialCommissionDashboardTest extends TestCase
{
    use RefreshDatabase;

    private int $reportUserSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'https://app.hrmotor.com/api/internal/google-reviews/count*' => Http::response([
                'month' => '06-26',
                'location' => 'HR Motor || Test',
                'reviews_count' => 0,
                'average_rating' => null,
            ], 200),
        ]);
    }

    public function test_admin_ve_tab_y_puede_entrar_a_comisiones_comerciales(): void
    {
        config()->set('services.informes_auth.enabled', true);

        $adminSession = $this->authenticatedSession(ReportUser::ROLE_ADMIN);

        $this->withSession($adminSession)
            ->get('/informes/leads')
            ->assertOk()
            ->assertSee('/informes/comisiones-comerciales', false);

        $this->withSession($adminSession)
            ->get('/informes/comisiones-comerciales')
            ->assertOk()
            ->assertSee('Comisiones Comerciales')
            ->assertSee('Mes cerrado')
            ->assertSee('Exportar comisiones')
            ->assertSee('Diagnostico de datos base');
    }

    public function test_director_ve_tab_y_puede_entrar_a_comisiones_comerciales_sin_ser_admin(): void
    {
        config()->set('services.informes_auth.enabled', true);

        $session = $this->authenticatedSession(ReportUser::ROLE_DIRECTOR);

        $this->withSession($session)
            ->get('/informes/leads')
            ->assertOk()
            ->assertSee('/informes/comisiones-comerciales', false);

        $this->withSession($session)
            ->get('/informes/comisiones-comerciales')
            ->assertOk()
            ->assertSee('Comisiones Comerciales')
            ->assertDontSee('Diagnostico de datos base');
    }

    public function test_dashboard_renderiza_el_detalle_mensual_de_junio_sin_tablas_historicas(): void
    {
        config()->set('services.informes_auth.enabled', true);

        SalesforceUser::create([
            'salesforce_id' => '005-MONTHLY-DETAIL',
            'name' => 'Comercial Mensual',
            'profile_name' => 'Compra/Venta',
            'is_active' => true,
        ]);
        SalesforceOpportunity::create([
            'salesforce_id' => 'OPP-MONTHLY-DETAIL',
            'name' => 'Venta mensual',
            'owner_id' => '005-MONTHLY-DETAIL',
            'owner_name' => 'Comercial Mensual',
            'owner_is_active' => true,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'cv_signed' => true,
            'cv_signed_date' => '2026-06-10',
        ]);

        $response = $this->withSession($this->authenticatedSession(ReportUser::ROLE_DIRECTOR))
            ->get('/informes/comisiones-comerciales?month=2026-06')
            ->assertOk()
            ->assertSee('Datos actualizados:')
            ->assertSee('Operaciones del mes')
            ->assertSee('Venta mensual')
            ->assertSee('data-commercial-detail-tab-trigger="operations"', false)
            ->assertSee('data-commercial-detail-tab-panel="operations"', false)
            ->assertDontSee('class="table-shell is-hidden" data-commercial-detail-tab-panel="operations"', false);

        $this->assertMatchesRegularExpression(
            '/class="main-tab active" data-commercial-detail-tab-trigger="operations".*data-commercial-detail-tab-panel="operations"/s',
            $response->getContent(),
        );
        $javascript = file_get_contents(resource_path('js/reports/commercial-commissions-dashboard.js'));
        $this->assertStringContainsString('activate(triggers[0].dataset.commercialDetailTabTrigger);', $javascript);
        $this->assertStringContainsString('detailPanel.dataset.commercialDetailTabPanel !== targetTab', $javascript);
    }

    public function test_area_manager_puede_entrar_y_viewer_no_ve_comisiones(): void
    {
        config()->set('services.informes_auth.enabled', true);

        $areaManagerSession = $this->authenticatedSession(ReportUser::ROLE_AREA_MANAGER);
        $viewerSession = $this->authenticatedSession(ReportUser::ROLE_VIEWER);

        $this->withSession($areaManagerSession)
            ->get('/informes/leads')
            ->assertOk()
            ->assertSee('/informes/comisiones-comerciales', false);

        $this->withSession($areaManagerSession)
            ->get('/informes/comisiones-comerciales')
            ->assertOk();

        $this->withSession($viewerSession)
            ->get('/informes/leads')
            ->assertOk()
            ->assertDontSee('/informes/comisiones-comerciales', false);

        $this->withSession($viewerSession)
            ->get('/informes/comisiones-comerciales')
            ->assertRedirect('/informes/leads');
    }

    public function test_dashboard_renderiza_pestana_area_manager(): void
    {
        config()->set('services.informes_auth.enabled', true);

        SalesforceOpportunity::create([
            'salesforce_id' => 'AM-ROUTE-1',
            'name' => 'Bilbao route',
            'owner_id' => '005-AM-ROUTE',
            'owner_name' => 'Area Route',
            'owner_is_active' => true,
            'owner_delegation' => 'Bilbao',
            'delivery_store' => 'Bilbao',
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-10',
            'beneficio_financiacion_comercial' => 100,
            'garantia_total' => 50,
        ]);

        $adminSession = $this->authenticatedSession(ReportUser::ROLE_ADMIN);

        $this->withSession($adminSession)
            ->get('/informes/comisiones-comerciales?tab=area-manager&month=2026-05')
            ->assertOk()
            ->assertSee('Comisiones Area Manager')
            ->assertSee('KPIs por delegacion')
            ->assertSee('Oscar Ortega');
    }

    public function test_dashboard_renderiza_pestana_financieros(): void
    {
        config()->set('services.informes_auth.enabled', true);

        SalesforceOpportunity::create([
            'salesforce_id' => 'FIN-ROUTE-1',
            'name' => 'Financiera route',
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'opportunity_record_type_formula' => 'Venta',
            'owner_delegation' => 'Bilbao',
            'financial_zone' => 'Zona Cristina',
            'opo_for_importe_total' => 10000,
            'importe_financiado' => 5000,
            'financial_commission' => 900,
            'financial_discount' => 90,
            'garantia_total' => 400,
            'interest_rate' => '6,99%',
            'cv_signed_date' => '2026-05-10',
        ]);

        $adminSession = $this->authenticatedSession(ReportUser::ROLE_ADMIN);

        $this->withSession($adminSession)
            ->get('/informes/comisiones-comerciales?tab=financials&month=2026-05')
            ->assertOk()
            ->assertSee('Comisiones Financieros')
            ->assertSee('Total comision')
            ->assertSee('Bloque 1');
    }

    public function test_dashboard_financieros_calcula_comision_por_zona(): void
    {
        app(CommercialCommissionFormulaConfigService::class)->saveForMonth('2026-05', [
            'financials' => [
                'financed_percentage_brackets' => [
                    ['min_percent' => 47.0001, 'incentive' => 0.0125],
                    ['min_percent' => 44.1, 'incentive' => 0.0100],
                    ['min_percent' => 42.1, 'incentive' => 0.0075],
                    ['min_percent' => 40.1, 'incentive' => 0.0050],
                    ['min_percent' => 39.1, 'incentive' => 0.0040],
                    ['min_percent' => 38.0, 'incentive' => 0.0020],
                    ['min_percent' => 0.0, 'incentive' => 0.0010],
                ],
                'profitability_brackets' => [
                    ['min_percent' => 16.6, 'incentive' => 0.0075],
                    ['min_percent' => 15.6, 'incentive' => 0.0050],
                    ['min_percent' => 14.5, 'incentive' => 0.0040],
                    ['min_percent' => 14.0, 'incentive' => 0.0020],
                    ['min_percent' => 0.0, 'incentive' => 0.0],
                ],
                'guarantee_percentage_brackets' => [
                    ['min_percent' => 7.0, 'incentive' => 0.0050],
                    ['min_percent' => 6.0, 'incentive' => 0.0030],
                    ['min_percent' => 5.0, 'incentive' => 0.0020],
                    ['min_percent' => 0.0, 'incentive' => 0.0],
                ],
                'excluded_interest_rates' => ['3,99%', '4,99%', '5,99%'],
            ],
        ]);

        SalesforceOpportunity::create([
            'salesforce_id' => 'FIN-1',
            'name' => 'Financiera 1',
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'opportunity_record_type_formula' => 'Venta',
            'owner_delegation' => 'Bilbao',
            'financial_zone' => 'Zona Cristina',
            'opo_for_importe_total' => 100000,
            'importe_financiado' => 50000,
            'financial_commission' => 10000,
            'financial_discount' => 1000,
            'garantia_total' => 3000,
            'interest_rate' => '6,99%',
            'cv_signed_date' => '2026-05-12',
        ]);

        SalesforceOpportunity::create([
            'salesforce_id' => 'FIN-2',
            'name' => 'Financiera 2',
            'stage_name' => 'Contrato',
            'record_type_name' => 'Cambio',
            'opportunity_record_type_formula' => 'Cambio',
            'owner_delegation' => 'Bilbao',
            'financial_zone' => 'Zona Cristina',
            'opo_for_importe_total' => 50000,
            'importe_financiado' => 25000,
            'financial_commission' => 5000,
            'financial_discount' => 500,
            'garantia_total' => 2000,
            'interest_rate' => '4,99%',
            'cv_signed_date' => '2026-05-14',
        ]);

        SalesforceOpportunity::create([
            'salesforce_id' => 'FIN-3',
            'name' => 'Sin zona',
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'opportunity_record_type_formula' => 'Venta',
            'owner_delegation' => 'General',
            'financial_zone' => 'Sin Zona',
            'opo_for_importe_total' => 10000,
            'importe_financiado' => 1000,
            'financial_commission' => 0,
            'financial_discount' => 0,
            'garantia_total' => 0,
            'interest_rate' => '6,99%',
            'cv_signed_date' => '2026-05-14',
        ]);

        $payload = app(FinancialCommissionDashboardService::class)
            ->build('2026-05');

        $this->assertTrue($payload['ready']);
        $this->assertSame(2, $payload['diagnostics']['eligible_operations_count']);
        $this->assertSame(1, $payload['diagnostics']['profitability_excluded_operations_count']);
        $this->assertSame(1, $payload['diagnostics']['general_or_without_zone_operations_count']);
        $this->assertSame(0, $payload['diagnostics']['unknown_financial_zone_operations_count']);

        $zone = collect($payload['summary_rows'])->firstWhere('zone_name', 'Zona Cristina');

        $this->assertNotNull($zone);
        $this->assertSame(2, $zone['operations_count']);
        $this->assertEqualsWithDelta(150000.0, $zone['amount_total'], 0.01);
        $this->assertEqualsWithDelta(75000.0, $zone['amount_financed'], 0.01);
        $this->assertEqualsWithDelta(50.0, $zone['financed_percentage'], 0.01);
        $this->assertEqualsWithDelta(13500.0, $zone['net_commission'], 0.01);
        $this->assertEqualsWithDelta(0.0125, $zone['financed_incentive'], 0.0001);
        $this->assertEqualsWithDelta(168.75, $zone['block_1_commission'], 0.01);
        $this->assertEqualsWithDelta(18.0, $zone['profitability_percentage'], 0.01);
        $this->assertEqualsWithDelta(0.0075, $zone['profitability_incentive'], 0.0001);
        $this->assertEqualsWithDelta(67.5, $zone['block_2_commission'], 0.01);
        $this->assertEqualsWithDelta(6.67, $zone['guarantee_percentage'], 0.01);
        $this->assertEqualsWithDelta(0.0030, $zone['guarantee_incentive'], 0.0001);
        $this->assertEqualsWithDelta(15.0, $zone['block_3_commission'], 0.01);
        $this->assertEqualsWithDelta(251.25, $zone['final_commission'], 0.01);
    }

    public function test_dashboard_financieros_bloquea_zona_desconocida_con_importes_economicos(): void
    {
        SalesforceOpportunity::create([
            'salesforce_id' => 'FIN-UNKNOWN-ZONE',
            'name' => 'Zona no configurada',
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'opportunity_record_type_formula' => 'Venta',
            'owner_delegation' => 'HR MOTOR BILBAO',
            'financial_zone' => 'Zona Atlantico',
            'opo_for_importe_total' => 10000,
            'importe_financiado' => 5000,
            'financial_commission' => 100,
            'financial_discount' => 10,
            'garantia_total' => 0,
            'interest_rate' => '8,99%',
            'cv_signed_date' => '2026-07-15',
        ]);

        $payload = app(FinancialCommissionDashboardService::class)->build('2026-07');

        $this->assertFalse($payload['ready']);
        $this->assertStringContainsString('Dataset financiero no conciliado', $payload['issues'][0]);
        $this->assertSame(1, $payload['diagnostics']['unknown_financial_zone_operations_count']);
        $this->assertSame('Zona Atlantico', $payload['diagnostics']['unknown_financial_zones'][0]['zone_name']);
        $this->assertSame(['FIN-UNKNOWN-ZONE'], $payload['diagnostics']['unknown_financial_zones'][0]['opportunity_ids']);
        $this->assertEqualsWithDelta(100.0, $payload['diagnostics']['financial_commission_excluded'], 0.01);
        $this->assertEmpty($payload['summary_rows']);

        $this->withSession($this->authenticatedSession(ReportUser::ROLE_FINANCIAL))
            ->get('/informes/comisiones-comerciales/export/comisiones.xlsx?month=2026-07')
            ->assertStatus(409);
    }

    public function test_dashboard_financieros_aplica_reglas_especiales_por_zona_y_suma_delegaciones_con_distintos_owners(): void
    {
        foreach ([
            ['id' => 'FIN-IRENE-ALICANTE', 'owner_id' => '005-COMMERCIAL-1', 'zone' => 'Zona Irene', 'delegation' => 'HR MOTOR ALICANTE', 'commission' => 12000, 'discount' => 2000, 'guarantee' => 300, 'interest' => null],
            ['id' => 'FIN-IRENE-PATERNA', 'owner_id' => '005-COMMERCIAL-2', 'zone' => 'Zona Irene', 'delegation' => 'HR MOTOR PATERNA', 'commission' => 8000, 'discount' => 0, 'guarantee' => 700, 'interest' => '8,99%'],
            ['id' => 'FIN-NURIA-SEDAVI', 'owner_id' => '005-COMMERCIAL-3', 'zone' => 'Zona Nuria', 'delegation' => 'HR MOTOR SEDAVI', 'commission' => 10000, 'discount' => 1000, 'guarantee' => 0, 'interest' => null],
            ['id' => 'FIN-NURIA-CASTELLON', 'owner_id' => '005-COMMERCIAL-4', 'zone' => 'Zona Nuria', 'delegation' => 'HR MOTOR CASTELLON', 'commission' => 5000, 'discount' => 0, 'guarantee' => 0, 'interest' => null],
        ] as $operation) {
            SalesforceOpportunity::create([
                'salesforce_id' => $operation['id'],
                'name' => $operation['zone'],
                'owner_id' => $operation['owner_id'],
                'stage_name' => 'Contrato',
                'record_type_name' => 'Venta',
                'opportunity_record_type_formula' => 'Venta',
                'financial_zone' => $operation['zone'],
                'owner_delegation' => $operation['delegation'],
                'opo_for_importe_total' => 100000,
                'importe_financiado' => 50000,
                'financial_commission' => $operation['commission'],
                'financial_discount' => $operation['discount'],
                'garantia_total' => $operation['guarantee'],
                'interest_rate' => $operation['interest'],
                'cv_signed_date' => '2026-06-15',
            ]);
        }

        $payload = app(FinancialCommissionDashboardService::class)
            ->build('2026-06');

        $nuria = collect($payload['summary_rows'])->firstWhere('responsible_key', 'zona_nuria');
        $irene = collect($payload['summary_rows'])->firstWhere('responsible_key', 'zona_irene');
        $ireneDelegations = collect($payload['delegation_rows'])->where('responsible_key', 'zona_irene');
        $nuriaDelegations = collect($payload['delegation_rows'])->where('responsible_key', 'zona_nuria');

        $this->assertEqualsWithDelta(0.005, $nuria['special_responsible_percent'], 0.0001);
        $this->assertEqualsWithDelta(70.0, $nuria['final_commission'], 0.01);
        $this->assertEqualsWithDelta(90.0, $irene['final_commission'], 0.01);
        $this->assertEqualsWithDelta(200000.0, $irene['amount_total'], 0.01);
        $this->assertEqualsWithDelta(100000.0, $irene['amount_financed'], 0.01);
        $this->assertEqualsWithDelta(50.0, $irene['financed_percentage'], 0.01);
        $this->assertEqualsWithDelta(16.0, $irene['profitability_percentage'], 0.01);
        $this->assertEqualsWithDelta(1000.0, $irene['premium_guarantee_total'], 0.01);
        $this->assertEqualsWithDelta(1.0, $irene['guarantee_percentage'], 0.01);
        $this->assertEqualsWithDelta(0.005, $irene['special_responsible_percent'], 0.0001);
        $this->assertSame(0.0, $irene['block_1_commission']);
        $this->assertSame(0.0, $irene['block_2_commission']);
        $this->assertSame(0.0, $irene['block_3_commission']);
        $this->assertEqualsCanonicalizing(['Alicante', 'Paterna'], $ireneDelegations->pluck('delegation_name')->all());
        $this->assertEqualsCanonicalizing(['Castellón', 'Sedavi'], $nuriaDelegations->pluck('delegation_name')->all());
        $this->assertEqualsWithDelta($irene['final_commission'], $ireneDelegations->sum('final_commission'), 0.001);
        $this->assertEqualsWithDelta($nuria['final_commission'], $nuriaDelegations->sum('final_commission'), 0.001);
        $this->assertEqualsWithDelta(
            collect($payload['summary_rows'])->sum('financial_commission_total'),
            collect($payload['delegation_rows'])->sum('financial_commission_total'),
            0.001
        );
        $this->assertEqualsWithDelta(
            collect($payload['summary_rows'])->sum('final_commission'),
            collect($payload['delegation_rows'])->sum('final_commission'),
            0.001
        );
        $this->assertSame('Tipo de interes vacio', $payload['detail_rows'][0]['profitability_reason']);

        $response = $this->withSession($this->authenticatedSession(ReportUser::ROLE_ADMIN))
            ->get('/informes/comisiones-comerciales?tab=financials&month=2026-06')
            ->assertOk();
        $html = $response->getContent();
        $sectionStart = strpos($html, '<h2>Irene y Nuria</h2>');
        $sectionEnd = strpos($html, '<h2>Detalle por responsable y delegacion</h2>', $sectionStart);

        $this->assertNotFalse($sectionStart);
        $this->assertNotFalse($sectionEnd);

        $section = substr($html, $sectionStart, $sectionEnd - $sectionStart);
        $headerStart = strpos($section, '<thead>');
        $headerEnd = strpos($section, '</thead>', $headerStart);

        $this->assertNotFalse($headerStart);
        $this->assertNotFalse($headerEnd);

        $header = substr($section, $headerStart, $headerEnd - $headerStart);
        $expectedHeaders = [
            'Responsable',
            'Comision final',
            'Ops.',
            'Imp. total',
            'Imp. financiado',
            '% financiado',
            'Com. financiera',
            'Desc. financiera',
            'Com. neta',
            'Rentabilidad',
            'Garantia',
            '% garantias',
            'Multiplicador',
        ];
        $previousPosition = -1;

        foreach ($expectedHeaders as $expectedHeader) {
            $position = strpos($header, '>'.$expectedHeader.'</th>');

            $this->assertNotFalse($position, "No se encontro la cabecera {$expectedHeader}.");
            $this->assertGreaterThan($previousPosition, $position, "La cabecera {$expectedHeader} esta fuera de orden.");
            $previousPosition = $position;
        }

        foreach (['200.000,00', '100.000,00', '50,00%', '20.000,00', '2.000,00', '18.000,00', '16,00%', '1.000,00', '1,00%', '0,50%', '90,00'] as $formattedValue) {
            $this->assertStringContainsString($formattedValue, $section);
        }

        $this->assertStringNotContainsString('Com. bloque 1', $section);
        $this->assertStringNotContainsString('Com. bloque 2', $section);
        $this->assertStringNotContainsString('Com. bloque 3', $section);
    }

    public function test_dashboard_financieros_respeta_limites_tipo_y_etapa_del_universo_mensual(): void
    {
        foreach ([
            ['id' => 'FIN-JUL-01', 'date' => '2026-07-01', 'type' => 'Venta', 'stage' => 'Entrega'],
            ['id' => 'FIN-JUL-31', 'date' => '2026-07-31', 'type' => 'Cambio', 'stage' => 'Entrega'],
            ['id' => 'FIN-AUG-01', 'date' => '2026-08-01', 'type' => 'Venta', 'stage' => 'Entrega'],
            ['id' => 'FIN-LOST', 'date' => '2026-07-15', 'type' => 'Venta', 'stage' => 'Cerrada perdida'],
            ['id' => 'FIN-APPRAISAL', 'date' => '2026-07-15', 'type' => 'Tasacion', 'stage' => 'Entrega'],
        ] as $operation) {
            SalesforceOpportunity::create([
                'salesforce_id' => $operation['id'],
                'name' => $operation['id'],
                'stage_name' => $operation['stage'],
                'record_type_name' => $operation['type'],
                'opportunity_record_type_formula' => $operation['type'],
                'financial_zone' => 'Zona Carlos',
                'owner_delegation' => 'HR MOTOR RIVAS',
                'opo_for_importe_total' => 10000,
                'importe_financiado' => 5000,
                'financial_commission' => 1000,
                'financial_discount' => 100,
                'garantia_total' => 500,
                'interest_rate' => '8,99%',
                'cv_signed_date' => $operation['date'],
            ]);
        }

        $payload = app(FinancialCommissionDashboardService::class)->build('2026-07');

        $this->assertSame(2, $payload['diagnostics']['universe_operations_count']);
        $this->assertEqualsCanonicalizing(
            ['FIN-JUL-01', 'FIN-JUL-31'],
            collect($payload['detail_rows'])->pluck('opportunity_id')->all()
        );
    }

    public function test_dashboard_financieros_expone_el_ajuste_de_redondeo_por_delegacion(): void
    {
        foreach ([
            ['id' => 'FIN-ROUND-ALICANTE', 'delegation' => 'HR MOTOR ALICANTE'],
            ['id' => 'FIN-ROUND-PATERNA', 'delegation' => 'HR MOTOR PATERNA'],
        ] as $operation) {
            SalesforceOpportunity::create([
                'salesforce_id' => $operation['id'],
                'name' => $operation['id'],
                'stage_name' => 'Contrato',
                'record_type_name' => 'Venta',
                'opportunity_record_type_formula' => 'Venta',
                'owner_delegation' => $operation['delegation'],
                'financial_zone' => 'Zona Irene',
                'opo_for_importe_total' => 100,
                'importe_financiado' => 50,
                'financial_commission' => 1,
                'financial_discount' => 0,
                'garantia_total' => 0,
                'interest_rate' => null,
                'cv_signed_date' => '2026-07-15',
            ]);
        }

        $payload = app(FinancialCommissionDashboardService::class)->build('2026-07');
        $summary = collect($payload['summary_rows'])->firstWhere('responsible_key', 'zona_irene');
        $delegations = collect($payload['delegation_rows'])->where('responsible_key', 'zona_irene');
        $adjusted = $delegations->first(fn (array $row): bool => (float) $row['rounding_adjustment'] !== 0.0);

        $this->assertTrue($payload['ready']);
        $this->assertEqualsWithDelta(0.01, $summary['final_commission'], 0.001);
        $this->assertEqualsWithDelta($summary['final_commission'], $delegations->sum('final_commission'), 0.001);
        $this->assertEqualsWithDelta(-0.01, $adjusted['rounding_adjustment'], 0.001);
        $this->assertSame(1, $payload['diagnostics']['rounding_adjustments_count']);
        $this->assertEqualsWithDelta(-0.01, $payload['diagnostics']['rounding_adjustments_total'], 0.001);
    }

    public function test_dashboard_financieros_no_aplica_la_regla_especial_antes_de_junio(): void
    {
        SalesforceOpportunity::create([
            'salesforce_id' => 'FIN-NURIA-MAY',
            'name' => 'Nombre visible modificado',
            'owner_id' => '005-COMMERCIAL',
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'opportunity_record_type_formula' => 'Venta',
            'financial_zone' => 'Zona Irene',
            'owner_delegation' => 'HR MOTOR ALICANTE',
            'opo_for_importe_total' => 100000,
            'importe_financiado' => 50000,
            'financial_commission' => 19219.76,
            'financial_discount' => 0,
            'garantia_total' => 0,
            'interest_rate' => '8,99%',
            'cv_signed_date' => '2026-05-15',
        ]);

        $payload = app(FinancialCommissionDashboardService::class)
            ->build('2026-05');
        $row = collect($payload['summary_rows'])->firstWhere('responsible_key', 'zona_irene');

        $this->assertSame(0.0, $row['special_responsible_percent']);
        $this->assertGreaterThan(0, $row['block_1_commission']);
    }

    public function test_export_financieros_uses_the_same_special_responsible_total_as_dashboard(): void
    {
        SalesforceOpportunity::create([
            'salesforce_id' => 'FIN-IRENE-EXPORT',
            'name' => 'Operacion financiera',
            'owner_id' => '005-COMMERCIAL',
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'opportunity_record_type_formula' => 'Venta',
            'financial_zone' => 'Zona Irene',
            'owner_delegation' => 'HR MOTOR ALICANTE',
            'opo_for_importe_total' => 100000,
            'importe_financiado' => 50000,
            'financial_commission' => 53029.32,
            'financial_discount' => 0,
            'garantia_total' => 0,
            'interest_rate' => null,
            'cv_signed_date' => '2026-06-15',
        ]);

        $dashboard = app(FinancialCommissionDashboardService::class)->build('2026-06');
        $row = collect($dashboard['summary_rows'])->firstWhere('responsible_key', 'zona_irene');

        $response = $this->withSession($this->authenticatedSession(ReportUser::ROLE_FINANCIAL))
            ->get('/informes/comisiones-comerciales/export/comisiones.xlsx?month=2026-06');

        $response->assertOk();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()) === true);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        $this->assertStringContainsString(
            '<v>'.number_format((float) $row['final_commission'], 2, '.', '').'</v>',
            $sheet
        );
    }

    public function test_export_contact_center_definitive_uses_snapshot_and_separates_responsible_sheet(): void
    {
        $closure = CommercialCommissionClosure::query()->create([
            'month' => '2026-09', 'closure_scope' => CommercialCommissionClosure::SCOPE_CONTACT_CENTER,
            'status' => CommercialCommissionClosure::STATUS_DEFINITIVE, 'snapshot_version' => 1,
            'formula_version' => 'test', 'data_cutoff_at' => now(),
        ]);
        CommercialCommissionSnapshot::query()->create([
            'closure_id' => $closure->id, 'month' => '2026-09', 'version' => 1,
            'formula_version' => 'test', 'data_cutoff_at' => now(), 'created_at' => now(),
            'payload' => ['contact_center' => [
                'month' => '2026-09', 'summary_rows' => [['agent_name' => 'Agente congelado', 'final_total' => 100.0]],
                'responsible_contact_center' => [
                    'responsible_name' => 'Vanesa Germán', 'month' => '2026-09', 'reference_month' => '2025-09',
                    'actual_deliveries' => 101, 'reference_deliveries' => 100, 'compliance_percent' => 101.0,
                    'bracket' => '>= 100 %', 'additional_deliveries' => 1, 'base_amount' => 500.0,
                    'additional_amount' => 5.0, 'final_total' => 505.0,
                ],
            ]],
        ]);
        SalesforceOpportunity::query()->create(['salesforce_id' => 'LIVE-CHANGED', 'name' => 'Dato vivo', 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->withSession($this->authenticatedSession(ReportUser::ROLE_DIRECTOR))
            ->get('/informes/comisiones-comerciales/export/comisiones.xlsx?month=2026-09');

        $response->assertOk();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()) === true);
        $workbook = (string) $zip->getFromName('xl/workbook.xml');
        $contactSheet = (string) $zip->getFromName('xl/worksheets/sheet4.xml');
        $responsibleSheet = (string) $zip->getFromName('xl/worksheets/sheet5.xml');
        $zip->close();

        $this->assertStringContainsString('Responsable Contact Center', $workbook);
        $this->assertStringContainsString('Agente congelado', $contactSheet);
        $this->assertStringContainsString('Vanesa Germán', $responsibleSheet);
        $this->assertStringContainsString('<v>505.00</v>', $responsibleSheet);
        $this->assertStringNotContainsString('Dato vivo', $responsibleSheet);
    }

    public function test_dashboard_calcula_resumen_real_de_comisiones(): void
    {
        config()->set('commercial_commissions.purchase_rentability_field', 'informe_rentabilidad');
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');

        SalesforceUser::create([
            'salesforce_id' => '005-A',
            'name' => 'Comercial A',
            'profile_name' => 'Compra/Venta',
            'is_active' => true,
        ]);

        SalesforceUser::create([
            'salesforce_id' => '005-B',
            'name' => 'Comercial B',
            'profile_name' => 'Compra/Venta',
            'is_active' => true,
        ]);

        SalesforceUser::create([
            'salesforce_id' => '005-Z',
            'name' => 'Tasador Sin Actividad',
            'profile_name' => 'Compra/Venta',
            'is_active' => true,
        ]);

        foreach (range(1, 7) as $index) {
            SalesforceOpportunity::create([
                'salesforce_id' => 'SALE-'.$index,
                'name' => 'Venta '.$index,
                'owner_id' => '005-A',
                'owner_name' => 'Comercial A',
                'owner_is_active' => true,
                'stage_name' => 'Contrato',
                'record_type_name' => 'Venta',
                'cv_signed' => true,
                'cv_signed_date' => '2026-05-1'.$index,
                'opo_for_importe_total' => 10000,
                'importe_financiado' => 5000,
                'beneficio_financiacion_comercial' => 1000,
                'garantia_total' => 1000,
                'opo_div_descuento' => 100,
                'gestion_de_venta' => false,
                'vehicle_plate' => $index === 1 ? '1111AAA' : 'PLATE-'.$index,
                'vehicle_days_in_stock' => $index === 1 ? 10 : 160,
                'vehicle_entry_date' => $index === 1 ? '2025-10-01' : '2026-03-01',
                'shared_delivery_id' => $index === 1 ? '005-B' : null,
                'shared_delivery_name' => $index === 1 ? 'Comercial B' : null,
            ]);
        }

        SalesforceOpportunity::create([
            'salesforce_id' => 'SALE-MANAGED-1',
            'name' => 'Venta gestionada',
            'owner_id' => '005-A',
            'owner_name' => 'Comercial A',
            'owner_is_active' => true,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-18',
            'opo_for_importe_total' => 10000,
            'importe_financiado' => 5000,
            'beneficio_financiacion_comercial' => 1000,
            'garantia_total' => 1000,
            'opo_div_descuento' => 100,
            'gestion_de_venta' => true,
            'vehicle_plate' => 'MANAGED-1',
            'vehicle_days_in_stock' => 20,
            'vehicle_entry_date' => '2025-12-01',
        ]);

        SalesforceOpportunity::create([
            'salesforce_id' => 'PURCHASE-1',
            'name' => 'Compra previa',
            'owner_id' => '005-A',
            'owner_name' => 'Comercial A',
            'owner_is_active' => true,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Tasacion',
            'cv_signed' => true,
            'cv_signed_date' => '2026-04-20',
            'gestion_de_venta' => false,
            'vehicle_plate' => '1111AAA',
            'vehicle_sale_price' => 11000,
            'vehicle_purchase_price' => 10000,
            'vehicle_purchase_source' => 'Compra directa',
            'vehicle_purchase_date' => '2026-05-02',
            'vehicle_buyer_id' => '005-A',
            'vehicle_buyer_name' => 'Comercial A',
        ]);

        foreach (range(1, 4) as $index) {
            SalesforceReview::create([
                'salesforce_id' => 'REV-'.$index,
                'created_date' => '2026-05-2'.$index.' 10:00:00',
                'owner_id' => '005-review-'.$index,
                'owner_name' => 'Review '.$index,
                'opportunity_salesforce_id' => 'SALE-'.$index,
                'opportunity_name' => 'Venta '.$index,
                'opportunity_owner_id' => '005-A',
                'opportunity_owner_name' => 'Comercial A',
                'opportunity_record_type_name' => 'Venta',
                'opportunity_cv_signed_date' => '2026-05-2'.$index,
            ]);
        }

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-05');

        $this->assertTrue($payload['ready']);
        $this->assertTrue($payload['diagnostics']['sale_management_filter_applied']);
        $this->assertCount(1, $payload['summary_rows']);

        $commercialA = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-A');
        $commercialB = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-B');
        $commercialZ = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-Z');

        $this->assertSame(7, $commercialA['deliveries_count']);
        $this->assertSame(7, $commercialA['operations_count']);
        $this->assertEquals(390.0, $commercialA['sales_amount']);
        $this->assertEquals(52.2, $commercialA['purchases_amount']);
        $this->assertEquals(35.0, $commercialA['discount_penalty_amount']);
        $this->assertEquals(10.0, $commercialA['stock_150_amount']);
        $this->assertEquals(417.2, $commercialA['prima_total']);
        $this->assertEquals(100.0, $commercialA['delivery_bracket_percent']);
        $this->assertEquals(417.2, $commercialA['prima_adjusted']);
        $this->assertEquals(57.14, $commercialA['reviews_percentage']);
        $this->assertEquals(50.0, $commercialA['financing_percentage']);
        $this->assertEquals(210.0, $commercialA['financing_product_amount']);
        $this->assertEquals(420.0, $commercialA['guarantee_product_amount']);
        $this->assertEquals(1047.2, $commercialA['final_commission']);
        $this->assertCount(1, $commercialA['details']['purchases']);

        $this->assertNull($commercialB);
        $this->assertNull($commercialZ);
    }

    public function test_dashboard_aplica_penalizacion_de_resenas_excluyente_y_financiacion_incluye_tasaciones(): void
    {
        config()->set('commercial_commissions.purchase_rentability_field', 'informe_rentabilidad');
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');
        $this->createCommercialUser('005-C', 'Comercial C');

        foreach (range(1, 7) as $index) {
            SalesforceOpportunity::create([
                'salesforce_id' => 'DELIVERY-'.$index,
                'name' => 'Entrega '.$index,
                'owner_id' => '005-C',
                'owner_name' => 'Comercial C',
                'owner_is_active' => true,
                'stage_name' => 'Contrato',
                'record_type_name' => $index === 7 ? 'Cambio' : 'Venta',
                'cv_signed' => true,
                'cv_signed_date' => '2026-05-0'.$index,
                'opo_for_importe_total' => 10000,
                'importe_financiado' => 5000,
                'garantia_total' => 350,
                'gestion_de_venta' => false,
            ]);
        }

        foreach (range(1, 3) as $index) {
            SalesforceOpportunity::create([
                'salesforce_id' => 'APPRAISAL-'.$index,
                'name' => 'Tasacion '.$index,
                'owner_id' => '005-C',
                'owner_name' => 'Comercial C',
                'owner_is_active' => true,
                'stage_name' => 'Contrato',
                'record_type_name' => 'Tasacion',
                'cv_signed' => true,
                'cv_signed_date' => '2026-05-1'.$index,
                'opo_for_importe_total' => 10000,
                'importe_financiado' => 0,
                'garantia_total' => 350,
                'gestion_de_venta' => false,
            ]);
        }

        foreach ([
            ['id' => 'REV-C-1', 'opportunity_id' => 'DELIVERY-1', 'opportunity_name' => 'Entrega 1', 'date' => '2026-05-20 10:00:00'],
            ['id' => 'REV-C-2', 'opportunity_id' => 'DELIVERY-1', 'opportunity_name' => 'Entrega 1', 'date' => '2026-05-20 11:00:00'],
            ['id' => 'REV-C-3', 'opportunity_id' => 'DELIVERY-2', 'opportunity_name' => 'Entrega 2', 'date' => '2026-05-21 10:00:00'],
            ['id' => 'REV-C-4', 'opportunity_id' => 'APPRAISAL-1', 'opportunity_name' => 'Tasacion 1', 'date' => '2026-05-22 10:00:00'],
        ] as $index => $review) {
            SalesforceReview::create([
                'salesforce_id' => $review['id'],
                'created_date' => $review['date'],
                'owner_id' => '005-review-c-'.$index,
                'owner_name' => 'Review C '.$index,
                'opportunity_salesforce_id' => $review['opportunity_id'],
                'opportunity_name' => $review['opportunity_name'],
                'opportunity_owner_id' => '005-C',
                'opportunity_owner_name' => 'Comercial C',
                'opportunity_record_type_name' => str_contains($review['opportunity_id'], 'APPRAISAL') ? 'Tasacion' : 'Venta',
                'opportunity_cv_signed_date' => '2026-05-20',
            ]);
        }

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-05');
        $commercial = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-C');

        $this->assertSame(7, $commercial['deliveries_count']);
        $this->assertSame(10, $commercial['operations_count']);
        $this->assertEquals(420.0, $commercial['prima_total']);
        $this->assertEquals(420.0, $commercial['prima_adjusted']);
        $this->assertEquals(4, $commercial['reviews_count']);
        $this->assertEquals(40.0, $commercial['reviews_percentage']);
        $this->assertEquals(42.0, $commercial['reviews_penalty']);
        $this->assertEquals(35000.0, $commercial['financed_amount']);
        $this->assertEquals(100000.0, $commercial['total_vehicle_amount']);
        $this->assertEquals(35.0, $commercial['financing_percentage']);
        $this->assertEquals(42.0, $commercial['financing_penalty']);
        $this->assertEquals(84.0, $commercial['total_penalties']);
        $this->assertEquals(336.0, $commercial['prima_after_penalties']);
        $this->assertCount(4, $commercial['details']['reviews']);
    }

    public function test_dashboard_aplica_tramo_por_perfil_y_partner_community_mantiene_tramo_normal(): void
    {
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');
        $this->createCommercialUser('005-JT', 'Jefe Tienda', true, 'Compra/Venta');
        $this->createCommercialUser('005-PC', 'Partner Community', true, 'Comerciales Partner Community');

        foreach (range(1, 7) as $index) {
            SalesforceOpportunity::create([
                'salesforce_id' => 'JT-SALE-'.$index,
                'name' => 'Venta JT '.$index,
                'owner_id' => '005-JT',
                'owner_name' => 'Jefe Tienda',
                'owner_is_active' => true,
                'stage_name' => 'Contrato',
                'record_type_name' => 'Venta',
                'cv_signed' => true,
                'cv_signed_date' => '2026-08-0'.$index,
                'opo_for_importe_total' => 10000,
                'gestion_de_venta' => false,
            ]);

            SalesforceOpportunity::create([
                'salesforce_id' => 'PC-SALE-'.$index,
                'name' => 'Venta PC '.$index,
                'owner_id' => '005-PC',
                'owner_name' => 'Partner Community',
                'owner_is_active' => true,
                'stage_name' => 'Contrato',
                'record_type_name' => 'Venta',
                'cv_signed' => true,
                'cv_signed_date' => '2026-08-0'.$index,
                'opo_for_importe_total' => 10000,
                'gestion_de_venta' => false,
            ]);
        }

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-08');

        $jefeTienda = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-JT');
        $partnerCommunity = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-PC');

        $this->assertNotNull($jefeTienda);
        $this->assertNotNull($partnerCommunity);
        $this->assertEquals(420.0, $jefeTienda['prima_total']);
        $this->assertEquals(100.0, $jefeTienda['delivery_bracket_percent']);
        $this->assertEquals(420.0, $jefeTienda['prima_adjusted']);
        $this->assertEquals(420.0, $partnerCommunity['prima_total']);
        $this->assertEquals(80.0, $partnerCommunity['delivery_bracket_percent']);
        $this->assertEquals(336.0, $partnerCommunity['prima_adjusted']);
    }

    public function test_dashboard_no_deja_prima_neta_negativa_ni_penalizaciones_negativas(): void
    {
        config()->set('commercial_commissions.purchase_rentability_field', 'informe_rentabilidad');
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');
        $this->createCommercialUser('005-N', 'Comercial N');

        foreach (range(1, 7) as $index) {
            SalesforceOpportunity::create([
                'salesforce_id' => 'NEG-'.$index,
                'name' => 'Operacion negativa '.$index,
                'owner_id' => '005-N',
                'owner_name' => 'Comercial N',
                'owner_is_active' => true,
                'stage_name' => 'Contrato',
                'record_type_name' => 'Venta',
                'cv_signed' => true,
                'cv_signed_date' => '2026-07-0'.$index,
                'opo_for_importe_total' => 10000,
                'importe_financiado' => 2000,
                'opo_div_descuento' => 2000,
                'gestion_de_venta' => false,
            ]);
        }

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-07');
        $commercial = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-N');

        $this->assertLessThan(0, $commercial['prima_adjusted']);
        $this->assertEquals(0.0, $commercial['reviews_penalty']);
        $this->assertEquals(0.0, $commercial['financing_penalty']);
        $this->assertEquals(0.0, $commercial['guarantee_penalty']);
        $this->assertEquals(0.0, $commercial['prima_after_penalties']);
        $this->assertEquals(0.0, $commercial['final_commission']);
    }

    public function test_dashboard_asigna_la_compra_al_propietario_de_la_compra_y_no_al_vendedor_posterior(): void
    {
        config()->set('commercial_commissions.purchase_rentability_field', 'informe_rentabilidad');
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');
        $this->createCommercialUser('005-T', 'Tasador Uno');
        $this->createCommercialUser('005-S', 'Comercial Venta');

        SalesforceOpportunity::create([
            'salesforce_id' => 'PURCHASE-T1',
            'name' => 'Tasacion de entrada',
            'owner_id' => '005-T',
            'owner_name' => 'Tasador Uno',
            'owner_is_active' => true,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Tasacion',
            'cv_signed' => true,
            'cv_signed_date' => '2026-04-20',
            'gestion_de_venta' => false,
            'vehicle_plate' => '4444AAA',
            'vehicle_sale_price' => 11000,
            'vehicle_purchase_price' => 10000,
            'vehicle_purchase_source' => 'Compra directa',
            'vehicle_purchase_date' => '2026-05-15',
            'vehicle_buyer_id' => '005-T',
            'vehicle_buyer_name' => 'Tasador Uno',
        ]);

        SalesforceOpportunity::create([
            'salesforce_id' => 'SALE-S1',
            'name' => 'Venta posterior',
            'owner_id' => '005-S',
            'owner_name' => 'Comercial Venta',
            'owner_is_active' => true,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-25',
            'opo_for_importe_total' => 12000,
            'importe_financiado' => 6000,
            'gestion_de_venta' => false,
            'vehicle_plate' => '4444AAA',
        ]);

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-05');

        $tasador = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-T');
        $vendedor = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-S');

        $this->assertNotNull($tasador);
        $this->assertNotNull($vendedor);
        $this->assertEquals(18.0, $tasador['purchases_amount']);
        $this->assertCount(1, $tasador['details']['purchases']);
        $this->assertEquals(0.0, $vendedor['purchases_amount']);
        $this->assertSame('Tasador Uno', $tasador['details']['purchases'][0]['purchase_owner_name']);
        $this->assertSame('Tasacion de entrada', $tasador['details']['purchases'][0]['purchase_opportunity_name']);
        $this->assertSame('Venta posterior', $tasador['details']['purchases'][0]['sale_opportunity_name']);
    }

    public function test_dashboard_detecta_compras_con_tasacion_acentuada_y_match_por_vehicle_interest(): void
    {
        config()->set('commercial_commissions.purchase_rentability_field', 'informe_rentabilidad');
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');
        $this->createCommercialUser('005-T2', 'Tasador Dos');
        $this->createCommercialUser('005-S2', 'Comercial Dos');

        SalesforceOpportunity::create([
            'salesforce_id' => 'PURCHASE-ACCENT-1',
            'name' => 'Tasacion acentuada',
            'owner_id' => '005-T2',
            'owner_name' => 'Tasador Dos',
            'owner_is_active' => true,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Tasación',
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-15',
            'gestion_de_venta' => false,
            'vehicle_interest_id' => 'VEH-100',
            'vehicle_plate' => null,
            'vehicle_sale_price' => 11000,
            'vehicle_purchase_price' => 10000,
            'vehicle_purchase_source' => 'Compra directa',
            'vehicle_purchase_date' => '2026-05-15',
            'vehicle_buyer_id' => '005-T2',
            'vehicle_buyer_name' => 'Tasador Dos',
        ]);

        SalesforceOpportunity::create([
            'salesforce_id' => 'SALE-ACCENT-1',
            'name' => 'Venta con vehiculo enlazado',
            'owner_id' => '005-S2',
            'owner_name' => 'Comercial Dos',
            'owner_is_active' => true,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-25',
            'opo_for_importe_total' => 15000,
            'importe_financiado' => 7000,
            'gestion_de_venta' => false,
            'vehicle_interest_id' => 'VEH-100',
            'vehicle_plate' => '9999BBB',
        ]);

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-05');

        $tasador = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-T2');
        $vendedor = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-S2');

        $this->assertNotNull($tasador);
        $this->assertNotNull($vendedor);
        $this->assertEquals(18.0, $tasador['purchases_amount']);
        $this->assertCount(1, $tasador['details']['purchases']);
        $this->assertSame('Tasacion acentuada', $tasador['details']['purchases'][0]['purchase_opportunity_name']);
        $this->assertSame('Venta con vehiculo enlazado', $tasador['details']['purchases'][0]['sale_opportunity_name']);
        $this->assertEquals(0.0, $vendedor['purchases_amount']);
    }

    public function test_dashboard_no_pierde_compra_historica_si_el_owner_original_ya_no_esta_activo(): void
    {
        config()->set('commercial_commissions.purchase_rentability_field', 'informe_rentabilidad');
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');
        $this->createCommercialUser('005-I1', 'Comercial Inactivo', false);
        $this->createCommercialUser('005-V1', 'Comercial Activo');

        SalesforceOpportunity::create([
            'salesforce_id' => 'PURCHASE-INACTIVE-1',
            'name' => 'Compra historica inactiva',
            'owner_id' => '005-I1',
            'owner_name' => 'Comercial Inactivo',
            'owner_is_active' => false,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Cambio',
            'cv_signed' => true,
            'cv_signed_date' => '2026-04-10',
            'gestion_de_venta' => false,
            'vehicle_plate' => '7777CCC',
            'vehicle_sale_price' => 11000,
            'vehicle_purchase_price' => 10000,
            'vehicle_purchase_source' => 'Cambio',
            'vehicle_purchase_date' => '2026-05-10',
            'vehicle_buyer_id' => '005-I1',
            'vehicle_buyer_name' => 'Comercial Inactivo',
        ]);

        SalesforceOpportunity::create([
            'salesforce_id' => 'SALE-INACTIVE-1',
            'name' => 'Venta de compra historica',
            'owner_id' => '005-V1',
            'owner_name' => 'Comercial Activo',
            'owner_is_active' => true,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-20',
            'opo_for_importe_total' => 16000,
            'importe_financiado' => 8000,
            'gestion_de_venta' => false,
            'vehicle_plate' => '7777CCC',
        ]);

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-05');

        $comprador = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-I1');
        $vendedor = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-V1');

        $this->assertNotNull($comprador);
        $this->assertNotNull($vendedor);
        $this->assertEquals(18.0, $comprador['purchases_amount']);
        $this->assertCount(1, $comprador['details']['purchases']);
        $this->assertEquals(0.0, $vendedor['purchases_amount']);
    }

    public function test_dashboard_normaliza_matriculas_con_espacios_para_liquidar_compras(): void
    {
        config()->set('commercial_commissions.purchase_rentability_field', 'informe_rentabilidad');
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');
        $this->createCommercialUser('005-P1', 'Josue Fernandez');
        $this->createCommercialUser('005-P2', 'Leonardo Gonzalez');

        SalesforceOpportunity::create([
            'salesforce_id' => 'PURCHASE-PLATE-1',
            'name' => 'Compra con espacio en matricula',
            'owner_id' => '005-P1',
            'owner_name' => 'Josue Fernandez',
            'owner_is_active' => true,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Tasacion',
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-10',
            'gestion_de_venta' => false,
            'vehicle_plate' => '9978 MBZ',
            'vehicle_sale_price' => 11000,
            'vehicle_purchase_price' => 10000,
            'vehicle_purchase_source' => 'Compra directa',
            'vehicle_purchase_date' => '2026-05-10',
            'vehicle_buyer_id' => '005-P1',
            'vehicle_buyer_name' => 'Josue Fernandez',
        ]);

        SalesforceOpportunity::create([
            'salesforce_id' => 'SALE-PLATE-1',
            'name' => 'Venta posterior sin espacio',
            'owner_id' => '005-P2',
            'owner_name' => 'Leonardo Gonzalez',
            'owner_is_active' => true,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-20',
            'opo_for_importe_total' => 15000,
            'importe_financiado' => 7000,
            'gestion_de_venta' => false,
            'vehicle_plate' => '9978MBZ',
        ]);

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-05');

        $comprador = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-P1');
        $vendedor = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-P2');

        $this->assertNotNull($comprador);
        $this->assertNotNull($vendedor);
        $this->assertEquals(18.0, $comprador['purchases_amount']);
        $this->assertCount(1, $comprador['details']['purchases']);
        $this->assertSame('Josue Fernandez', $comprador['commercial_name']);
        $this->assertEquals(0.0, $vendedor['purchases_amount']);
    }

    public function test_dashboard_liquida_compra_desde_product2_aunque_no_exista_oportunidad_historica_local(): void
    {
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');
        $this->createCommercialUser('005-SD1', 'Vendedor Directo');
        $this->createCommercialUser('005-BD1', 'Comprador Product2');

        SalesforceOpportunity::create([
            'salesforce_id' => 'SALE-DIRECT-1',
            'name' => 'Venta con comprador Product2',
            'owner_id' => '005-SD1',
            'owner_name' => 'Vendedor Directo',
            'owner_is_active' => true,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-25',
            'opo_for_importe_total' => 18000,
            'importe_financiado' => 5000,
            'gestion_de_venta' => false,
            'vehicle_interest_id' => '01t-DIRECT-1',
            'vehicle_plate' => '1234ABC',
            'vehicle_buyer_id' => '005-BD1',
            'vehicle_buyer_name' => 'Comprador Product2',
            'vehicle_sale_price' => 15000,
            'vehicle_purchase_price' => 10000,
            'vehicle_purchase_source' => 'Compra directa',
            'vehicle_purchase_date' => '2026-05-20',
            'opo_div_descuento' => 300,
            'beneficio_financiacion_comercial' => 700,
            'garantia_total' => 500,
        ]);

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-05');

        $comprador = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-BD1');
        $vendedor = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-SD1');

        $this->assertNotNull($comprador);
        $this->assertNotNull($vendedor);
        $this->assertEquals(106.2, $comprador['purchases_amount']);
        $this->assertCount(1, $comprador['details']['purchases']);
        $this->assertSame('Sin oportunidad historica local', $comprador['details']['purchases'][0]['purchase_opportunity_name']);
        $this->assertSame('Product2', $comprador['details']['purchases'][0]['purchase_record_type_name']);
        $this->assertSame('product2_sale_vehicle', $comprador['details']['purchases'][0]['source']);
        $this->assertEquals(0.0, $vendedor['purchases_amount']);
    }

    public function test_dashboard_excluye_compras_con_fecha_compra_product2_igual_o_anterior_al_primero_de_mayo_de_2026(): void
    {
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');
        $this->createCommercialUser('005-OLD-BUY', 'Comprador Antiguo');
        $this->createCommercialUser('005-OLD-SELL', 'Vendedor Antiguo');
        $this->createCommercialUser('005-OLD-DIRECT', 'Comprador Directo Antiguo');
        $this->createCommercialUser('005-OLD-DIRECT-SELL', 'Vendedor Directo Antiguo');

        SalesforceOpportunity::create([
            'salesforce_id' => 'PURCHASE-OLD-1',
            'name' => 'Compra historica antigua',
            'owner_id' => '005-OLD-BUY',
            'owner_name' => 'Comprador Antiguo',
            'owner_is_active' => true,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Tasacion',
            'cv_signed' => true,
            'cv_signed_date' => '2026-04-20',
            'gestion_de_venta' => false,
            'vehicle_plate' => '5555OLD',
            'vehicle_sale_price' => 11000,
            'vehicle_purchase_price' => 10000,
            'vehicle_purchase_source' => 'Compra directa',
            'vehicle_purchase_date' => '2026-05-01',
            'vehicle_buyer_id' => '005-OLD-BUY',
            'vehicle_buyer_name' => 'Comprador Antiguo',
        ]);

        SalesforceOpportunity::create([
            'salesforce_id' => 'SALE-OLD-1',
            'name' => 'Venta posterior antigua',
            'owner_id' => '005-OLD-SELL',
            'owner_name' => 'Vendedor Antiguo',
            'owner_is_active' => true,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'cv_signed' => true,
            'cv_signed_date' => '2026-06-10',
            'opo_for_importe_total' => 14000,
            'importe_financiado' => 5000,
            'gestion_de_venta' => false,
            'vehicle_plate' => '5555OLD',
        ]);

        SalesforceOpportunity::create([
            'salesforce_id' => 'SALE-DIRECT-OLD-1',
            'name' => 'Venta Product2 antigua',
            'owner_id' => '005-OLD-DIRECT-SELL',
            'owner_name' => 'Vendedor Directo Antiguo',
            'owner_is_active' => true,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'cv_signed' => true,
            'cv_signed_date' => '2026-06-12',
            'opo_for_importe_total' => 18000,
            'importe_financiado' => 5000,
            'gestion_de_venta' => false,
            'vehicle_interest_id' => '01t-DIRECT-OLD-1',
            'vehicle_plate' => '8888OLD',
            'vehicle_buyer_id' => '005-OLD-DIRECT',
            'vehicle_buyer_name' => 'Comprador Directo Antiguo',
            'vehicle_sale_price' => 15000,
            'vehicle_purchase_price' => 10000,
            'vehicle_purchase_source' => 'Compra directa',
            'vehicle_purchase_date' => '2026-04-30',
            'opo_div_descuento' => 300,
            'beneficio_financiacion_comercial' => 700,
            'garantia_total' => 500,
        ]);

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-06');

        $historicalBuyer = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-OLD-BUY');
        $directBuyer = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-OLD-DIRECT');
        $historicalSeller = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-OLD-SELL');
        $directSeller = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-OLD-DIRECT-SELL');

        $this->assertNull($historicalBuyer);
        $this->assertNull($directBuyer);
        $this->assertNotNull($historicalSeller);
        $this->assertNotNull($directSeller);
        $this->assertEquals(0.0, $historicalSeller['purchases_amount']);
        $this->assertEquals(0.0, $directSeller['purchases_amount']);
    }

    public function test_dashboard_excluye_compras_con_procedencia_fuera_de_cambio_y_compra_directa(): void
    {
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');
        $this->createCommercialUser('005-SD2', 'Vendedor Dos');
        $this->createCommercialUser('005-BD2', 'Comprador Excluido');

        SalesforceOpportunity::create([
            'salesforce_id' => 'SALE-DIRECT-2',
            'name' => 'Venta con procedencia excluida',
            'owner_id' => '005-SD2',
            'owner_name' => 'Vendedor Dos',
            'owner_is_active' => true,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'cv_signed' => true,
            'cv_signed_date' => '2026-06-11',
            'opo_for_importe_total' => 18000,
            'importe_financiado' => 5000,
            'gestion_de_venta' => false,
            'vehicle_interest_id' => '01t-DIRECT-2',
            'vehicle_plate' => '4321CBA',
            'vehicle_buyer_id' => '005-BD2',
            'vehicle_buyer_name' => 'Comprador Excluido',
            'vehicle_sale_price' => 15000,
            'vehicle_purchase_price' => 10000,
            'vehicle_purchase_source' => 'Subasta',
            'vehicle_purchase_date' => '2026-05-20',
            'opo_div_descuento' => 300,
            'beneficio_financiacion_comercial' => 700,
            'garantia_total' => 500,
        ]);

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-06');
        $comprador = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-BD2');

        $this->assertNull($comprador);
    }

    public function test_dashboard_excluye_usuarios_sin_perfiles_comerciales_permitidos(): void
    {
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');

        SalesforceUser::create([
            'salesforce_id' => '005-OK',
            'name' => 'Comercial Permitido',
            'profile_name' => 'Comerciales Partner Community',
            'is_active' => true,
        ]);

        SalesforceUser::create([
            'salesforce_id' => '005-NO',
            'name' => 'Usuario No Comercial',
            'profile_name' => 'System Administrator',
            'is_active' => true,
        ]);

        foreach ([
            ['id' => 'SALE-OK-1', 'owner_id' => '005-OK', 'owner_name' => 'Comercial Permitido', 'plate' => 'AAA111'],
            ['id' => 'SALE-NO-1', 'owner_id' => '005-NO', 'owner_name' => 'Usuario No Comercial', 'plate' => 'BBB222'],
        ] as $row) {
            SalesforceOpportunity::create([
                'salesforce_id' => $row['id'],
                'name' => $row['id'],
                'owner_id' => $row['owner_id'],
                'owner_name' => $row['owner_name'],
                'owner_is_active' => true,
                'stage_name' => 'Contrato',
                'record_type_name' => 'Venta',
                'cv_signed' => true,
                'cv_signed_date' => '2026-06-10',
                'opo_for_importe_total' => 10000,
                'importe_financiado' => 3000,
                'gestion_de_venta' => false,
                'vehicle_plate' => $row['plate'],
            ]);
        }

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-06');

        $this->assertNotNull(collect($payload['summary_rows'])->firstWhere('commercial_id', '005-OK'));
        $this->assertNull(collect($payload['summary_rows'])->firstWhere('commercial_id', '005-NO'));
    }

    public function test_dashboard_calcula_cuadro_delegaciones_con_meta_y_bonus_de_calidad(): void
    {
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');
        app(CommercialCommissionFormulaConfigService::class)->saveForMonth('2026-06', [
            'delegations' => [
                'goals' => [
                    'hr-motor-alicante' => [
                        'label' => 'HR MOTOR ALICANTE',
                        'target_deliveries' => 35,
                    ],
                ],
            ],
        ]);

        $this->createCommercialUser('005-D1', 'Delegacion Uno');

        foreach (range(1, 30) as $index) {
            SalesforceOpportunity::create([
                'salesforce_id' => 'DEL-ALICANTE-'.$index,
                'name' => 'Venta Alicante '.$index,
                'owner_id' => '005-D1',
                'owner_name' => 'Delegacion Uno',
                'owner_is_active' => true,
                'owner_delegation' => 'HR MOTOR ALICANTE',
                'delivery_store' => 'HR MOTOR ALICANTE',
                'stage_name' => 'Contrato',
                'record_type_name' => $index === 30 ? 'Cambio' : 'Venta',
                'cv_signed' => true,
                'cv_signed_date' => '2026-06-'.str_pad((string) min($index, 28), 2, '0', STR_PAD_LEFT),
                'opo_for_importe_total' => 10000,
                'vehicle_sale_price' => 12000,
                'vehicle_purchase_price' => 10000,
                'opo_div_descuento' => 0,
                'importe_financiado' => 5000,
                'beneficio_financiacion_comercial' => 800,
                'garantia_total' => 200,
                'gestion_de_venta' => false,
            ]);
        }

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-06');
        $delegation = collect($payload['delegation_rows'])->firstWhere('delegation_name', 'Alicante');

        $this->assertNotNull($delegation);
        $this->assertSame(35, $delegation['target_deliveries']);
        $this->assertSame(30, $delegation['deliveries_count']);
        $this->assertEquals(85.71, $delegation['objective_percentage']);
        $this->assertEquals(0.35, $delegation['objective_commission_percent']);
        $this->assertEquals(90000.0, $delegation['rentability_total']);
        $this->assertEquals(3000.0, $delegation['average_rentability']);
        $this->assertEquals(315.0, $delegation['prima_final']);
        $this->assertSame(0, $delegation['reviews_count']);
        $this->assertNull($delegation['reviews_average_rating']);
        $this->assertEquals(0.0, $delegation['reviews_commission_amount']);
        $this->assertEquals(16.0, $delegation['financing_profitability_percentage']);
        $this->assertEquals(50.0, $delegation['financed_amount_percentage']);
        $this->assertEquals(31.5, $delegation['financed_amount_bonus_amount']);
        $this->assertEquals(34.65, $delegation['profitability_bonus_amount']);
        $this->assertEquals(381.15, $delegation['total_commission']);
    }

    public function test_dashboard_delegaciones_calcula_porcentaje_rentabilidad_con_la_misma_suma_que_salesforce(): void
    {
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');
        app(CommercialCommissionFormulaConfigService::class)->saveForMonth('2026-06', [
            'delegations' => [
                'goals' => [
                    'valladolid' => [
                        'label' => 'Valladolid',
                        'target_deliveries' => 2,
                    ],
                ],
            ],
        ]);

        $this->createCommercialUser('005-V1', 'Delegacion Valladolid');

        SalesforceOpportunity::create([
            'salesforce_id' => 'DEL-VALL-1',
            'name' => 'Venta Valladolid 1',
            'owner_id' => '005-V1',
            'owner_name' => 'Delegacion Valladolid',
            'owner_is_active' => true,
            'owner_delegation' => 'Valladolid',
            'delivery_store' => 'Valladolid',
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'cv_signed' => true,
            'cv_signed_date' => '2026-06-10',
            'opo_for_importe_total' => 10000,
            'vehicle_sale_price' => 12000,
            'vehicle_purchase_price' => 10000,
            'importe_financiado' => 5000,
            'beneficio_financiacion_comercial' => 800,
            'garantia_total' => 100,
            'gestion_de_venta' => false,
        ]);

        SalesforceOpportunity::create([
            'salesforce_id' => 'DEL-VALL-2',
            'name' => 'Venta Valladolid 2',
            'owner_id' => '005-V1',
            'owner_name' => 'Delegacion Valladolid',
            'owner_is_active' => true,
            'owner_delegation' => 'Valladolid',
            'delivery_store' => 'Valladolid',
            'stage_name' => 'Contrato',
            'record_type_name' => 'Cambio',
            'cv_signed' => true,
            'cv_signed_date' => '2026-06-12',
            'opo_for_importe_total' => 10000,
            'vehicle_sale_price' => 12000,
            'vehicle_purchase_price' => 10000,
            'importe_financiado' => 5000,
            'beneficio_financiacion_comercial' => -100,
            'garantia_total' => 100,
            'gestion_de_venta' => false,
        ]);

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-06');
        $delegation = collect($payload['delegation_rows'])->firstWhere('delegation_name', 'Valladolid');

        $this->assertNotNull($delegation);
        $this->assertEquals(7.0, $delegation['financing_profitability_percentage']);
    }

    public function test_dashboard_delegaciones_excluye_operaciones_sin_tienda_de_entrega(): void
    {
        app(CommercialCommissionFormulaConfigService::class)->saveForMonth('2026-06', [
            'delegations' => [
                'goals' => [
                    'zaragoza' => ['label' => 'Zaragoza', 'target_deliveries' => 2],
                ],
            ],
        ]);

        foreach ([
            ['id' => 'ZAR-CON-TIENDA', 'delivery_store' => 'Zaragoza', 'sale_price' => 12000],
            ['id' => 'ZAR-SIN-TIENDA', 'delivery_store' => null, 'sale_price' => 22000],
        ] as $operation) {
            SalesforceOpportunity::create([
                'salesforce_id' => $operation['id'],
                'name' => 'Venta '.$operation['id'],
                'owner_id' => '005-ZAR',
                'owner_name' => 'Comercial Zaragoza',
                'owner_is_active' => true,
                'owner_delegation' => 'Zaragoza',
                'delivery_store' => $operation['delivery_store'],
                'stage_name' => 'Contrato',
                'record_type_name' => 'Venta',
                'cv_signed' => true,
                'cv_signed_date' => '2026-06-10',
                'vehicle_sale_price' => $operation['sale_price'],
                'vehicle_purchase_price' => 10000,
                'gestion_de_venta' => false,
            ]);
        }

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-06');
        $delegation = collect($payload['delegation_rows'])->firstWhere('delegation_name', 'Zaragoza');
        $audit = app(CommercialCommissionDashboardService::class)->delegationDeliveriesAudit('2026-06');

        $this->assertNotNull($delegation);
        $this->assertSame(1, $delegation['deliveries_count']);
        $this->assertEquals(2000.0, $delegation['rentability_total']);
        $this->assertCount(1, $audit['rows']);
        $this->assertSame('ZAR-CON-TIENDA', $audit['rows'][0]['opportunity_id']);
    }

    public function test_dashboard_delegaciones_agrupa_rentabilidad_por_tienda_de_entrega(): void
    {
        app(CommercialCommissionFormulaConfigService::class)->saveForMonth('2026-06', [
            'delegations' => [
                'goals' => [
                    'paterna' => ['label' => 'Paterna', 'target_deliveries' => 1],
                    'murcia' => ['label' => 'Murcia', 'target_deliveries' => 1],
                ],
            ],
        ]);

        SalesforceOpportunity::create([
            'salesforce_id' => 'MUR-ENTREGA-PAT-OWNER',
            'name' => 'Venta entregada Murcia',
            'owner_id' => '005-OWNER-PAT',
            'owner_name' => 'Comercial Paterna',
            'owner_is_active' => true,
            'owner_delegation' => 'Paterna',
            'report_owner_delegation' => 'Paterna',
            'delivery_store' => 'Murcia',
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'cv_signed' => true,
            'cv_signed_date' => '2026-06-10',
            'vehicle_sale_price' => 15000,
            'vehicle_purchase_price' => 10000,
            'gestion_de_venta' => false,
        ]);

        $rows = collect(app(CommercialCommissionDashboardService::class)->build('2026-06')['delegation_rows']);
        $murcia = $rows->firstWhere('delegation_name', 'Murcia');
        $paterna = $rows->firstWhere('delegation_name', 'Paterna');

        $this->assertNotNull($murcia);
        $this->assertEquals(5000.0, $murcia['rentability_total']);
        $this->assertNotNull($paterna);
        $this->assertEquals(0.0, $paterna['rentability_total']);
    }

    public function test_dashboard_delegaciones_rentabilidad_reproduce_filtros_del_informe_productos_salesforce(): void
    {
        app(CommercialCommissionFormulaConfigService::class)->saveForMonth('2026-06', [
            'delegations' => [
                'goals' => [
                    'murcia' => ['label' => 'Murcia', 'target_deliveries' => 1],
                ],
            ],
        ]);

        foreach ([
            ['id' => 'RENT-VENTA', 'record_type' => 'Venta', 'stage' => 'Contrato', 'cv_signed' => true, 'rentability' => 3000],
            ['id' => 'RENT-TASACION', 'record_type' => 'Tasacion', 'stage' => 'Contrato', 'cv_signed' => false, 'rentability' => 2000],
            ['id' => 'RENT-GANADA', 'record_type' => 'Venta', 'stage' => 'Cerrada ganada', 'cv_signed' => true, 'rentability' => 7000],
            ['id' => 'RENT-PERDIDA', 'record_type' => 'Venta', 'stage' => 'Cerrada perdida', 'cv_signed' => true, 'rentability' => 9000],
        ] as $operation) {
            SalesforceOpportunity::create([
                'salesforce_id' => $operation['id'],
                'name' => 'Operacion '.$operation['id'],
                'owner_id' => '005-MUR',
                'owner_name' => 'Comercial Murcia',
                'owner_is_active' => true,
                'owner_delegation' => 'Murcia',
                'delivery_store' => 'Murcia',
                'stage_name' => $operation['stage'],
                'record_type_name' => $operation['record_type'],
                'cv_signed' => $operation['cv_signed'],
                'cv_signed_date' => '2026-06-10',
                'vehicle_sale_price' => 10000 + $operation['rentability'],
                'vehicle_purchase_price' => 10000,
                'gestion_de_venta' => false,
            ]);
        }

        $delegation = collect(app(CommercialCommissionDashboardService::class)->build('2026-06')['delegation_rows'])
            ->firstWhere('delegation_name', 'Murcia');

        $this->assertNotNull($delegation);
        $this->assertEquals(5000.0, $delegation['rentability_total']);
        $this->assertEquals(2500.0, $delegation['average_rentability']);
    }

    public function test_dashboard_delegaciones_calcula_porcentajes_financieros_por_delegacion_historica_del_propietario(): void
    {
        app(CommercialCommissionFormulaConfigService::class)->saveForMonth('2026-06', [
            'delegations' => [
                'goals' => [
                    'rivas' => ['label' => 'Rivas', 'target_deliveries' => 1],
                ],
            ],
        ]);

        foreach ([
            ['id' => 'RIV-HISTORICA', 'delivery_store' => 'Zaragoza', 'report_owner_delegation' => 'Rivas', 'benefit' => 1200, 'financed' => 10000, 'total' => 20000],
            ['id' => 'RIV-ENTREGA', 'delivery_store' => 'Rivas', 'report_owner_delegation' => 'Bilbao', 'benefit' => 800, 'financed' => 4000, 'total' => 5000],
        ] as $operation) {
            SalesforceOpportunity::create([
                'salesforce_id' => $operation['id'],
                'name' => 'Venta '.$operation['id'],
                'owner_id' => '005-RIV',
                'owner_name' => 'Comercial Rivas',
                'owner_is_active' => true,
                'owner_delegation' => 'Rivas',
                'report_owner_delegation' => $operation['report_owner_delegation'],
                'delivery_store' => $operation['delivery_store'],
                'stage_name' => 'Entrega',
                'record_type_name' => 'Venta',
                'cv_signed' => true,
                'cv_signed_date' => '2026-06-10',
                'vehicle_sale_price' => 12000,
                'vehicle_purchase_price' => 10000,
                'beneficio_financiacion_comercial' => $operation['benefit'],
                'importe_financiado' => $operation['financed'],
                'opo_for_importe_total' => $operation['total'],
                'gestion_de_venta' => false,
            ]);
        }

        $delegation = collect(app(CommercialCommissionDashboardService::class)->build('2026-06')['delegation_rows'])
            ->firstWhere('delegation_name', 'Rivas');

        $this->assertNotNull($delegation);
        $this->assertEquals(12.0, $delegation['financing_profitability_percentage']);
        $this->assertEquals(50.0, $delegation['financed_amount_percentage']);
    }

    public function test_auditoria_de_entregas_delegaciones_exporta_la_misma_logica_del_cuadro(): void
    {
        SalesforceOpportunity::create([
            'salesforce_id' => 'AUDIT-SALE',
            'name' => 'Venta Zaragoza',
            'owner_id' => '005-AUDIT',
            'owner_name' => 'Owner Zaragoza',
            'owner_is_active' => false,
            'owner_delegation' => 'HR MOTOR BILBAO',
            'report_owner_delegation' => 'HR MOTOR BILBAO',
            'delivery_store' => 'HR MOTOR ZARAGOZA',
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'cv_signed' => true,
            'cv_signed_date' => '2026-06-10',
            'gestion_de_venta' => true,
            'vehicle_plate' => '0001AUD',
        ]);
        SalesforceOpportunity::create([
            'salesforce_id' => 'AUDIT-FACILITEA',
            'name' => 'Operacion Facilitea Zaragoza',
            'owner_id' => '005-AUDIT',
            'owner_name' => 'Owner Zaragoza',
            'owner_is_active' => true,
            'owner_delegation' => 'General',
            'delivery_store' => 'HR MOTOR ZARAGOZA',
            'stage_name' => 'Contrato',
            'record_type_name' => 'Tasacion',
            'cv_signed' => true,
            'cv_signed_date' => '2026-06-12',
        ]);
        SalesforceOpportunity::create([
            'salesforce_id' => 'AUDIT-EXCLUDED',
            'name' => 'Tasacion normal Zaragoza',
            'owner_id' => '005-AUDIT',
            'owner_name' => 'Owner Zaragoza',
            'owner_delegation' => 'HR MOTOR ZARAGOZA',
            'delivery_store' => 'HR MOTOR ZARAGOZA',
            'stage_name' => 'Contrato',
            'record_type_name' => 'Tasacion',
            'cv_signed' => true,
            'cv_signed_date' => '2026-06-13',
        ]);

        $audit = app(CommercialCommissionDashboardService::class)->delegationDeliveriesAudit('2026-06');

        $this->assertTrue($audit['ready']);
        $this->assertCount(2, $audit['rows']);
        $this->assertSame('Zaragoza', $audit['rows'][0]['delegation_calculated']);
        $this->assertSame('Venta', $audit['rows'][0]['inclusion_reason']);
        $this->assertSame('Facilitea por nombre', $audit['rows'][1]['inclusion_reason']);
        $this->assertTrue($audit['rows'][1]['is_facilitea']);
    }

    public function test_dashboard_delegaciones_calcula_kpis_financieros_desde_owner_delegation_normalizada(): void
    {
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');
        app(CommercialCommissionFormulaConfigService::class)->saveForMonth('2026-05', [
            'delegations' => [
                'goals' => [
                    'palma' => [
                        'label' => 'Palma',
                        'target_deliveries' => 1,
                    ],
                ],
            ],
        ]);

        $this->createCommercialUser('005-P1', 'Delegacion Palma');

        foreach (range(1, 2) as $index) {
            SalesforceOpportunity::create([
                'salesforce_id' => 'DEL-PALMA-'.$index,
                'name' => 'Venta Palma '.$index,
                'owner_id' => '005-P1',
                'owner_name' => 'Delegacion Palma',
                'owner_is_active' => true,
                'owner_delegation' => 'HR MOTOR MALLORCA',
                'delivery_store' => 'Palma',
                'stage_name' => 'Contrato',
                'record_type_name' => 'Venta',
                'cv_signed' => true,
                'cv_signed_date' => '2026-05-10',
                'opo_for_importe_total' => 10000,
                'vehicle_sale_price' => 12000,
                'vehicle_purchase_price' => 10000,
                'importe_financiado' => 10000,
                'beneficio_financiacion_comercial' => 1090,
                'garantia_total' => 100,
                'gestion_de_venta' => false,
            ]);
        }

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-05');
        $delegation = collect($payload['delegation_rows'])->firstWhere('delegation_name', 'Palma');

        $this->assertNotNull($delegation);
        $this->assertSame(2, $delegation['deliveries_count']);
        $this->assertEquals(10.9, $delegation['financing_profitability_percentage']);
    }

    public function test_dashboard_no_rompe_la_pagina_si_falla_el_endpoint_de_resenas_con_filtro_call_center(): void
    {
        config()->set('services.informes_auth.enabled', true);
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');

        Http::fake([
            'https://app.hrmotor.com/api/internal/google-reviews/count*' => Http::failedConnection(),
        ]);

        $this->createCommercialUser('005-RV', 'Delegacion Resenas');

        SalesforceOpportunity::create([
            'salesforce_id' => 'DEL-REV-1',
            'name' => 'Venta Zaragoza 1',
            'owner_id' => '005-RV',
            'owner_name' => 'Delegacion Resenas',
            'owner_is_active' => true,
            'owner_delegation' => 'Zaragoza',
            'delivery_store' => 'Zaragoza',
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-15',
            'opo_for_importe_total' => 10000,
            'vehicle_sale_price' => 12000,
            'vehicle_purchase_price' => 10000,
            'importe_financiado' => 5000,
            'beneficio_financiacion_comercial' => 500,
            'garantia_total' => 100,
            'gestion_de_venta' => false,
            'raw_payload' => [
                'Captador__c' => 'Vanesa',
                'Comisi_n_Captador__c' => 5,
            ],
        ]);

        $adminSession = $this->authenticatedSession(ReportUser::ROLE_ADMIN);

        $this->withSession($adminSession)
            ->get('/informes/comisiones-comerciales?call_center_contract_from=2026-06-01&call_center_contract_to=2026-06-30&month=2026-05')
            ->assertOk()
            ->assertSee('Comisiones Comerciales');
    }

    public function test_dashboard_excluye_delegaciones_general_y_call_fontellas(): void
    {
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');
        $this->createCommercialUser('005-D2', 'Delegacion Dos');

        foreach ([
            ['id' => 'DEL-GENERAL-1', 'delegation' => 'General'],
            ['id' => 'DEL-CALL-1', 'delegation' => 'Call Fontellas'],
            ['id' => 'DEL-VALID-1', 'delegation' => 'HR MOTOR ALICANTE'],
        ] as $row) {
            SalesforceOpportunity::create([
                'salesforce_id' => $row['id'],
                'name' => $row['id'],
                'owner_id' => '005-D2',
                'owner_name' => 'Delegacion Dos',
                'owner_is_active' => true,
                'owner_delegation' => $row['delegation'],
                'delivery_store' => $row['delegation'],
                'stage_name' => 'Contrato',
                'record_type_name' => 'Venta',
                'cv_signed' => true,
                'cv_signed_date' => '2026-06-10',
                'opo_for_importe_total' => 10000,
                'vehicle_sale_price' => 12000,
                'vehicle_purchase_price' => 10000,
                'importe_financiado' => 5000,
                'beneficio_financiacion_comercial' => 500,
                'garantia_total' => 100,
                'gestion_de_venta' => false,
            ]);
        }

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-06');
        $delegationNames = collect($payload['delegation_rows'])->pluck('delegation_name');

        $this->assertTrue($delegationNames->contains('Alicante'));
        $this->assertFalse($delegationNames->contains('General'));
        $this->assertFalse($delegationNames->contains('Call Fontellas'));
        $this->assertFalse($delegationNames->contains('HR MOTOR ALICANTE'));
    }

    public function test_dashboard_cuadro_delegaciones_audita_entregas_sin_filtrar_por_owner_activo_ni_gestion_de_venta(): void
    {
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');
        $this->createCommercialUser('005-D3', 'Delegacion Tres');

        foreach ([
            ['id' => 'DEL-BILBAO-1', 'delivery_store' => 'Bilbao', 'owner_is_active' => true, 'gestion_de_venta' => false],
            ['id' => 'DEL-BILBAO-2', 'delivery_store' => 'HR MOTOR BILBAO', 'owner_is_active' => false, 'gestion_de_venta' => false],
            ['id' => 'DEL-BILBAO-3', 'delivery_store' => 'Bilbao', 'owner_is_active' => true, 'gestion_de_venta' => true],
        ] as $row) {
            SalesforceOpportunity::create([
                'salesforce_id' => $row['id'],
                'name' => $row['id'],
                'owner_id' => '005-D3',
                'owner_name' => 'Delegacion Tres',
                'owner_is_active' => $row['owner_is_active'],
                'owner_delegation' => 'HR MOTOR BILBAO',
                'delivery_store' => $row['delivery_store'],
                'stage_name' => 'Contrato',
                'record_type_name' => 'Venta',
                'cv_signed' => true,
                'cv_signed_date' => '2026-06-10',
                'opo_for_importe_total' => 10000,
                'vehicle_sale_price' => 12000,
                'vehicle_purchase_price' => 10000,
                'importe_financiado' => 5000,
                'beneficio_financiacion_comercial' => 500,
                'garantia_total' => 100,
                'gestion_de_venta' => $row['gestion_de_venta'],
            ]);
        }

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-06');
        $delegation = collect($payload['delegation_rows'])->firstWhere('delegation_name', 'Bilbao');

        $this->assertNotNull($delegation);
        $this->assertSame(3, $delegation['deliveries_count']);
        $this->assertNull(collect($payload['delegation_rows'])->firstWhere('delegation_name', 'HR MOTOR BILBAO'));
    }

    public function test_dashboard_normaliza_alias_delegaciones_y_hace_match_con_meta_para_evitar_prima_final_en_cero(): void
    {
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');

        app(CommercialCommissionFormulaConfigService::class)->saveForMonth('2026-06', [
            'delegations' => [
                'goals' => [
                    'mallorca' => [
                        'label' => 'Mallorca',
                        'target_deliveries' => 1,
                    ],
                    'san-boi' => [
                        'label' => 'San Boi',
                        'target_deliveries' => 1,
                    ],
                    'villarreal-almassora' => [
                        'label' => 'Villarreal/Almassora',
                        'target_deliveries' => 1,
                    ],
                    'malga' => [
                        'label' => 'Malga',
                        'target_deliveries' => 1,
                    ],
                    'llica-de-vall' => [
                        'label' => 'Lliçà De Vall',
                        'target_deliveries' => 99,
                    ],
                ],
            ],
        ]);

        $this->createCommercialUser('005-D4', 'Delegacion Cuatro');

        foreach ([
            ['id' => 'DEL-PALMA-1', 'delivery_store' => 'Palma'],
            ['id' => 'DEL-SANT-BOI-1', 'delivery_store' => 'Sant_Boi'],
            ['id' => 'DEL-VILLAREAL-1', 'delivery_store' => 'Villareal/Almasora'],
            ['id' => 'DEL-MALAGA-1', 'delivery_store' => 'Malaga'],
            ['id' => 'DEL-LLICA-1', 'delivery_store' => 'Lliçà De Vall'],
            ['id' => 'DEL-ALCALA-1', 'delivery_store' => 'Alcalá de Guadaira'],
            ['id' => 'DEL-ALCALA-2', 'delivery_store' => 'Alcala De Guadaira'],
            ['id' => 'DEL-CASTELLON-1', 'delivery_store' => 'Castellón'],
            ['id' => 'DEL-CASTELLON-2', 'delivery_store' => 'Castellon'],
            ['id' => 'DEL-DH-1', 'delivery_store' => 'Dos Hermanas'],
            ['id' => 'DEL-DH-2', 'delivery_store' => 'Dos hermanas'],
            ['id' => 'DEL-TORREJON-1', 'delivery_store' => 'Torrejón'],
            ['id' => 'DEL-TORREJON-2', 'delivery_store' => 'Torrejon'],
        ] as $row) {
            SalesforceOpportunity::create([
                'salesforce_id' => $row['id'],
                'name' => $row['id'],
                'owner_id' => '005-D4',
                'owner_name' => 'Delegacion Cuatro',
                'owner_is_active' => true,
                'owner_delegation' => $row['delivery_store'],
                'delivery_store' => $row['delivery_store'],
                'stage_name' => 'Contrato',
                'record_type_name' => 'Venta',
                'cv_signed' => true,
                'cv_signed_date' => '2026-06-10',
                'opo_for_importe_total' => 10000,
                'vehicle_sale_price' => 12000,
                'vehicle_purchase_price' => 10000,
                'importe_financiado' => 5000,
                'beneficio_financiacion_comercial' => 500,
                'garantia_total' => 100,
                'gestion_de_venta' => false,
            ]);
        }

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-06');
        $rows = collect($payload['delegation_rows']);

        $this->assertSame(1, $rows->where('delegation_name', 'Palma')->count());
        $this->assertSame(1, $rows->where('delegation_name', 'Sant Boi')->count());
        $this->assertSame(1, $rows->where('delegation_name', 'Villareal')->count());
        $this->assertSame(1, $rows->where('delegation_name', 'Malaga')->count());
        $this->assertSame(1, $rows->where('delegation_name', 'Alcalá de Guadaira')->count());
        $this->assertSame(1, $rows->where('delegation_name', 'Castellón')->count());
        $this->assertSame(1, $rows->where('delegation_name', 'Dos Hermanas')->count());
        $this->assertSame(1, $rows->where('delegation_name', 'Torrejón de Ardoz')->count());
        $this->assertFalse($rows->pluck('delegation_name')->contains('Mallorca'));
        $this->assertFalse($rows->pluck('delegation_name')->contains('San Boi'));
        $this->assertFalse($rows->pluck('delegation_name')->contains('Sant_Boi'));
        $this->assertFalse($rows->pluck('delegation_name')->contains('Villareal/Almasora'));
        $this->assertFalse($rows->pluck('delegation_name')->contains('Malga'));
        $this->assertFalse($rows->pluck('delegation_name')->contains('Lliçà De Vall'));
        $this->assertFalse($rows->pluck('delegation_name')->contains('Alcala De Guadaira'));
        $this->assertFalse($rows->pluck('delegation_name')->contains('Castellon'));
        $this->assertFalse($rows->pluck('delegation_name')->contains('Dos hermanas'));
        $this->assertFalse($rows->pluck('delegation_name')->contains('Torrejon'));
        $this->assertFalse($rows->pluck('delegation_name')->contains('Torrejón'));

        $this->assertSame(1, $rows->firstWhere('delegation_name', 'Palma')['target_deliveries']);
        $this->assertSame(1, $rows->firstWhere('delegation_name', 'Sant Boi')['target_deliveries']);
        $this->assertSame(1, $rows->firstWhere('delegation_name', 'Villareal')['target_deliveries']);
        $this->assertSame(1, $rows->firstWhere('delegation_name', 'Malaga')['target_deliveries']);
        $this->assertGreaterThan(0, $rows->firstWhere('delegation_name', 'Palma')['prima_final']);
    }

    public function test_dashboard_aplica_comision_de_resenas_solo_si_cumple_objetivo_y_supera_el_50_por_ciento(): void
    {
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');
        app()->instance(
            CommercialCommissionDelegationReviewsService::class,
            new class(app(CommercialCommissionFormulaConfigService::class)) extends CommercialCommissionDelegationReviewsService
            {
                public function forMonthAndDelegations(CarbonImmutable $month, Collection $delegationLabels): array
                {
                    return [
                        'Zaragoza' => ['reviews_count' => 23, 'average_rating' => 4.6],
                        'Bilbao' => ['reviews_count' => 10, 'average_rating' => 3.6],
                    ];
                }
            }
        );

        app(CommercialCommissionFormulaConfigService::class)->saveForMonth('2026-06', [
            'delegations' => [
                'goals' => [
                    'zaragoza' => [
                        'label' => 'Zaragoza',
                        'target_deliveries' => 20,
                    ],
                    'bilbao' => [
                        'label' => 'Bilbao',
                        'target_deliveries' => 20,
                    ],
                ],
            ],
        ]);

        $this->createCommercialUser('005-R1', 'Delegacion Reviews');

        foreach (range(1, 27) as $index) {
            SalesforceOpportunity::create([
                'salesforce_id' => 'DEL-ZARAGOZA-'.$index,
                'name' => 'Venta Zaragoza '.$index,
                'owner_id' => '005-R1',
                'owner_name' => 'Delegacion Reviews',
                'owner_is_active' => true,
                'owner_delegation' => 'Zaragoza',
                'delivery_store' => 'Zaragoza',
                'stage_name' => 'Contrato',
                'record_type_name' => 'Venta',
                'cv_signed' => true,
                'cv_signed_date' => '2026-06-10',
                'opo_for_importe_total' => 10000,
                'vehicle_sale_price' => 12000,
                'vehicle_purchase_price' => 10000,
                'importe_financiado' => 5000,
                'beneficio_financiacion_comercial' => 500,
                'garantia_total' => 100,
                'gestion_de_venta' => false,
            ]);
        }

        foreach (range(1, 20) as $index) {
            SalesforceOpportunity::create([
                'salesforce_id' => 'DEL-BILBAO-R-'.$index,
                'name' => 'Venta Bilbao '.$index,
                'owner_id' => '005-R1',
                'owner_name' => 'Delegacion Reviews',
                'owner_is_active' => true,
                'owner_delegation' => 'Bilbao',
                'delivery_store' => 'Bilbao',
                'stage_name' => 'Contrato',
                'record_type_name' => 'Venta',
                'cv_signed' => true,
                'cv_signed_date' => '2026-06-10',
                'opo_for_importe_total' => 10000,
                'vehicle_sale_price' => 12000,
                'vehicle_purchase_price' => 10000,
                'importe_financiado' => 5000,
                'beneficio_financiacion_comercial' => 500,
                'garantia_total' => 100,
                'gestion_de_venta' => false,
            ]);
        }

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-06');
        $zaragoza = collect($payload['delegation_rows'])->firstWhere('delegation_name', 'Zaragoza');
        $bilbao = collect($payload['delegation_rows'])->firstWhere('delegation_name', 'Bilbao');

        $this->assertNotNull($zaragoza);
        $this->assertSame(23, $zaragoza['reviews_count']);
        $this->assertEquals(4.6, $zaragoza['reviews_average_rating']);
        $this->assertEquals(200.0, $zaragoza['reviews_commission_amount']);
        $this->assertEquals(621.2, $zaragoza['prima_final']);

        $this->assertNotNull($bilbao);
        $this->assertSame(10, $bilbao['reviews_count']);
        $this->assertEquals(3.6, $bilbao['reviews_average_rating']);
        $this->assertEquals(0.0, $bilbao['reviews_commission_amount']);
    }

    public function test_dashboard_area_manager_calcula_kpis_llaves_y_totales_por_manager(): void
    {
        app(CommercialCommissionFormulaConfigService::class)->saveForMonth('2026-02', [
            'area_manager' => [
                'kpi_bases' => [
                    'deliveries' => 150,
                    'benefit' => 150,
                    'guarantee' => 100,
                    'purchases' => 100,
                ],
                'zone_keys' => [
                    ['min_percent' => 100, 'multiplier' => 1.10],
                    ['min_percent' => 91, 'multiplier' => 1.00],
                    ['min_percent' => 85, 'multiplier' => 0.90],
                    ['min_percent' => 0, 'multiplier' => 0.80],
                ],
                'assignments' => [
                    'bilbao' => [
                        'label' => 'Bilbao',
                        'manager_key' => 'kosta-plamenov',
                        'active' => true,
                        'objectives' => [
                            'deliveries' => 10,
                            'benefit' => 1000,
                            'guarantee' => 500,
                            'purchases' => 4,
                        ],
                    ],
                    'pamplona' => [
                        'label' => 'Pamplona',
                        'manager_key' => 'kosta-plamenov',
                        'active' => true,
                        'objectives' => [
                            'deliveries' => 10,
                            'benefit' => 1000,
                            'guarantee' => 500,
                            'purchases' => 2,
                        ],
                    ],
                ],
            ],
        ]);

        foreach (range(1, 10) as $index) {
            SalesforceOpportunity::create([
                'salesforce_id' => 'AM-BIL-'.$index,
                'name' => 'Bilbao '.$index,
                'owner_id' => '005-AM',
                'owner_name' => 'Area Comercial',
                'owner_is_active' => true,
                'owner_delegation' => 'Bilbao',
                'delivery_store' => 'Bilbao',
                'stage_name' => 'Contrato',
                'record_type_name' => 'Venta',
                'cv_signed' => true,
                'cv_signed_date' => '2026-02-10',
                'beneficio_financiacion_comercial' => 100,
                'garantia_total' => 50,
            ]);
        }

        foreach (range(1, 8) as $index) {
            SalesforceOpportunity::create([
                'salesforce_id' => 'AM-PAM-'.$index,
                'name' => 'Pamplona '.$index,
                'owner_id' => '005-AM',
                'owner_name' => 'Area Comercial',
                'owner_is_active' => true,
                'owner_delegation' => 'Pamplona',
                'delivery_store' => 'Pamplona',
                'stage_name' => 'Contrato',
                'record_type_name' => 'Venta',
                'cv_signed' => true,
                'cv_signed_date' => '2026-02-11',
                'beneficio_financiacion_comercial' => 100,
                'garantia_total' => 50,
            ]);
        }

        foreach (range(1, 4) as $index) {
            SalesforceOpportunity::create([
                'salesforce_id' => 'AM-PUR-BIL-'.$index,
                'name' => 'Compra Bilbao '.$index,
                'owner_id' => '005-AM',
                'owner_name' => 'Area Comercial',
                'owner_is_active' => true,
                'owner_delegation' => 'Bilbao',
                'delivery_store' => 'Bilbao',
                'stage_name' => 'Contrato',
                'record_type_name' => 'Tasacion',
                'cv_signed' => true,
                'cv_signed_date' => '2026-02-15',
            ]);
        }

        foreach (range(1, 2) as $index) {
            SalesforceOpportunity::create([
                'salesforce_id' => 'AM-PUR-PAM-'.$index,
                'name' => 'Compra Pamplona '.$index,
                'owner_id' => '005-AM',
                'owner_name' => 'Area Comercial',
                'owner_is_active' => true,
                'owner_delegation' => 'Pamplona',
                'delivery_store' => 'Pamplona',
                'stage_name' => 'Contrato',
                'record_type_name' => 'Tasacion',
                'cv_signed' => true,
                'cv_signed_date' => '2026-02-16',
            ]);
        }

        $payload = app(AreaManagerCommissionDashboardService::class)->build('2026-02');
        $row = collect($payload['summary_rows'])->firstWhere('manager_key', 'kosta-plamenov');

        $this->assertNotNull($row);
        $this->assertSame(2, $row['delegations_count']);
        $this->assertSame(20.0, $row['deliveries_objective']);
        $this->assertSame(18.0, $row['deliveries_actual']);
        $this->assertSame(90.0, $row['deliveries_zone_percent_used']);
        $this->assertSame(0.9, $row['deliveries_zone_key']);
        $this->assertEquals(135.0, $row['deliveries_commission']);
        $this->assertEquals(135.0, $row['benefit_commission']);
        $this->assertEquals(90.0, $row['guarantee_commission']);
        $this->assertEquals(220.0, $row['purchases_commission']);
        $this->assertEquals(580.0, $row['automatic_total']);
    }

    public function test_dashboard_area_manager_recalcula_solo_la_zona_solicitada(): void
    {
        app(CommercialCommissionFormulaConfigService::class)->saveForMonth('2026-01', [
            'area_manager' => [
                'assignments' => [
                    'bilbao' => [
                        'label' => 'Bilbao',
                        'manager_key' => 'kosta-plamenov',
                        'active' => true,
                        'objectives' => ['deliveries' => 1, 'benefit' => 100, 'guarantee' => 10, 'purchases' => 1],
                    ],
                    'malaga' => [
                        'label' => 'Malaga',
                        'manager_key' => 'david-baeza',
                        'active' => true,
                        'objectives' => ['deliveries' => 1, 'benefit' => 100, 'guarantee' => 10, 'purchases' => 1],
                    ],
                ],
            ],
        ]);

        foreach (['Bilbao' => 'NORTE', 'Malaga' => 'SUR'] as $delegation => $suffix) {
            SalesforceOpportunity::query()->create([
                'salesforce_id' => 'AM-SCOPE-'.$suffix,
                'name' => 'Venta '.$delegation,
                'owner_id' => '005-'.$suffix,
                'owner_name' => 'Comercial '.$delegation,
                'owner_is_active' => true,
                'owner_delegation' => $delegation,
                'delivery_store' => $delegation,
                'stage_name' => 'Contrato',
                'record_type_name' => 'Venta',
                'cv_signed' => true,
                'cv_signed_date' => '2026-01-15',
                'beneficio_financiacion_comercial' => 100,
                'garantia_total' => 10,
            ]);
        }

        $payload = app(AreaManagerCommissionDashboardService::class)->build('2026-01', 'Zona Norte');

        $this->assertSame(['kosta-plamenov'], collect($payload['summary_rows'])->pluck('manager_key')->all());
        $this->assertSame(1, $payload['summary_rows'][0]['delegations_count']);
        $this->assertSame(1.0, $payload['summary_rows'][0]['deliveries_actual']);
        $this->assertNull($payload['commercial_director']);
    }

    public function test_dashboard_area_manager_calcula_direccion_comercial_global_y_no_la_expone_por_zona(): void
    {
        $this->seedAreaManagerDirectorScenario('2026-03');

        $payload = app(AreaManagerCommissionDashboardService::class)->build('2026-03');
        $managerTotals = collect($payload['summary_rows'])->mapWithKeys(
            fn (array $row): array => [$row['manager_key'] => $row['final_total']]
        );
        $commercialDirector = $payload['commercial_director'];

        $this->assertSame(1000.0, $managerTotals->get('david-baeza'));
        $this->assertSame(2000.0, $managerTotals->get('kosta-plamenov'));
        $this->assertSame(3000.0, $managerTotals->get('nicolas-fernandez'));
        $this->assertSame(4000.0, $managerTotals->get('luis-lopez'));
        $this->assertSame('0057R00000B2SGg', $commercialDirector['salesforce_user_id']);
        $this->assertSame('Oscar Ortega', $commercialDirector['name']);
        $this->assertSame(10000.0, $commercialDirector['basis_amount']);
        $this->assertSame(40.0, $commercialDirector['commission_percent']);
        $this->assertSame(4000.0, $commercialDirector['final_total']);

        $globalSession = $this->authenticatedSession(ReportUser::ROLE_DIRECTOR);
        $this->withSession($globalSession)
            ->get('/informes/comisiones-comerciales?tab=area-manager&month=2026-03')
            ->assertOk()
            ->assertSee('Direccion Comercial')
            ->assertSee('Oscar Ortega')
            ->assertSee('10.000,00 EUR')
            ->assertSee('40,00%')
            ->assertSee('4.000,00 EUR');

        $restrictedPayload = app(AreaManagerCommissionDashboardService::class)->build('2026-03', 'Zona Norte');

        $this->assertNull($restrictedPayload['commercial_director']);
        $this->withSession($this->authenticatedSession(ReportUser::ROLE_AREA_MANAGER))
            ->get('/informes/comisiones-comerciales?tab=area-manager&month=2026-03')
            ->assertOk()
            ->assertDontSee('Oscar Ortega');
    }

    public function test_area_manager_definitivo_congela_direccion_comercial_en_front_y_xlsx_y_reapertura_vuelve_al_vivo(): void
    {
        $this->seedAreaManagerDirectorScenario('2026-03');
        ReportSyncRun::query()->create([
            'dataset' => 'salesforce_opportunities', 'source' => 'salesforce', 'status' => 'completed',
            'period_start_at' => '2026-03-01', 'period_end_at' => '2026-04-01',
            'started_at' => now()->subMinute(), 'completed_at' => now(),
            'timezone' => config('app.timezone'), 'stats' => ['queried' => 4, 'stored' => 4],
        ]);
        $session = $this->authenticatedSession(ReportUser::ROLE_DIRECTOR);
        $director = ReportUser::query()->findOrFail($session['report_user_id']);
        $closureService = app(CommercialCommissionClosureService::class);
        $components = array_fill_keys(array_keys($closureService->requiredComponents(CommercialCommissionClosure::SCOPE_AREA_MANAGER)), true);

        $closureService->prepare('2026-03', CommercialCommissionClosure::SCOPE_AREA_MANAGER, $components, $director);
        $closureService->approve('2026-03', CommercialCommissionClosure::SCOPE_AREA_MANAGER, $director);

        $snapshot = $closureService->definitiveSnapshot('2026-03', CommercialCommissionClosure::SCOPE_AREA_MANAGER);
        $this->assertEquals(4000.0, data_get($snapshot, 'area_manager.commercial_director.final_total'));

        SalesforceOpportunity::query()->delete();
        $this->assertSame(0.0, data_get(
            app(AreaManagerCommissionDashboardService::class)->build('2026-03'),
            'commercial_director.final_total'
        ));

        $this->withSession($session)
            ->get('/informes/comisiones-comerciales?tab=area-manager&month=2026-03')
            ->assertOk()
            ->assertViewHas('areaManagerDashboard', fn (array $dashboard): bool => (float) data_get($dashboard, 'commercial_director.final_total') === 4000.0)
            ->assertSee('4.000,00 EUR');

        $export = $this->withSession($session)
            ->get('/informes/comisiones-comerciales/export/comisiones.xlsx?month=2026-03')
            ->assertOk();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($export->baseResponse->getFile()->getPathname()) === true);
        $areaManagerSheet = (string) $zip->getFromName('xl/worksheets/sheet5.xml');
        $zip->close();

        $this->assertMatchesRegularExpression(
            '/<row r="\d+">.*Oscar Ortega.*<v>4000\.00<\/v>.*<\/row>/s',
            $areaManagerSheet
        );

        $closureService->reopen(
            '2026-03',
            CommercialCommissionClosure::SCOPE_AREA_MANAGER,
            'Recalculo auditado',
            $director,
        );

        $this->withSession($session)
            ->get('/informes/comisiones-comerciales?tab=area-manager&month=2026-03')
            ->assertOk()
            ->assertViewHas('areaManagerDashboard', fn (array $dashboard): bool => data_get($dashboard, 'commercial_director.final_total') === 0.0);
    }

    public function test_dashboard_delegaciones_incluye_oportunidades_facilitea_por_nombre_en_entregas(): void
    {
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');

        app(CommercialCommissionFormulaConfigService::class)->saveForMonth('2026-03', [
            'delegations' => [
                'goals' => [
                    'bilbao' => [
                        'label' => 'Bilbao',
                        'target_deliveries' => 1,
                    ],
                ],
            ],
        ]);

        SalesforceOpportunity::create([
            'salesforce_id' => 'FAC-DEL-1',
            'name' => 'Operacion Facilitea Bilbao',
            'owner_id' => '005-FAC',
            'owner_name' => 'Facilitea Team',
            'owner_is_active' => true,
            'owner_delegation' => 'HR MOTOR BILBAO',
            'delivery_store' => 'Bilbao',
            'stage_name' => 'Contrato',
            'record_type_name' => 'Otro',
            'cv_signed' => true,
            'cv_signed_date' => '2026-03-10',
            'beneficio_financiacion_comercial' => 100,
            'garantia_total' => 50,
            'vehicle_sale_price' => 12000,
            'vehicle_purchase_price' => 10000,
            'gestion_de_venta' => false,
        ]);

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-03');
        $delegation = collect($payload['delegation_rows'])->firstWhere('delegation_name', 'Bilbao');

        $this->assertNotNull($delegation);
        $this->assertSame(1, $delegation['deliveries_count']);
    }

    public function test_dashboard_delegaciones_asigna_facilitea_general_a_tienda_entrega_y_calcula_porcentaje_financiado(): void
    {
        app(CommercialCommissionFormulaConfigService::class)->saveForMonth('2026-06', [
            'delegations' => [
                'goals' => [
                    'zaragoza' => [
                        'label' => 'Zaragoza',
                        'target_deliveries' => 3,
                    ],
                ],
            ],
        ]);

        foreach ([
            ['id' => 'ZAR-REG-1', 'name' => 'Venta Zaragoza', 'record_type' => 'Venta', 'owner_delegation' => 'Zaragoza', 'report_owner_delegation' => 'Zaragoza', 'delivery_store' => 'Zaragoza', 'financed' => 450, 'total' => 1000],
            ['id' => 'ZAR-FAC-1', 'name' => 'FACILITEA Zaragoza 1', 'record_type' => 'Otro', 'owner_delegation' => 'General', 'report_owner_delegation' => 'CAPTADOR U ONLINE', 'delivery_store' => 'HR MOTOR ZARAGOZA', 'financed' => 120, 'total' => 200],
            ['id' => 'ZAR-FAC-2', 'name' => 'FACILITEA Zaragoza 2', 'record_type' => 'Otro', 'owner_delegation' => 'Sin Zona', 'report_owner_delegation' => 'CAPTADOR U ONLINE', 'delivery_store' => 'HR MOTOR ZARAGOZA', 'financed' => 120, 'total' => 200],
        ] as $operation) {
            SalesforceOpportunity::create([
                'salesforce_id' => $operation['id'],
                'name' => $operation['name'],
                'owner_id' => '005-ZAR',
                'owner_name' => 'Equipo Facilitea',
                'owner_is_active' => true,
                'owner_delegation' => $operation['owner_delegation'],
                'report_owner_delegation' => $operation['report_owner_delegation'],
                'delivery_store' => $operation['delivery_store'],
                'stage_name' => 'Contrato',
                'record_type_name' => $operation['record_type'],
                'cv_signed' => true,
                'cv_signed_date' => '2026-06-10',
                'importe_financiado' => $operation['financed'],
                'opo_for_importe_total' => $operation['total'],
            ]);
        }

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-06');
        $zaragoza = collect($payload['delegation_rows'])->firstWhere('delegation_name', 'Zaragoza');

        $this->assertNotNull($zaragoza);
        $this->assertSame(3, $zaragoza['deliveries_count']);
        $this->assertEquals(45.0, $zaragoza['financed_amount_percentage']);
        $this->assertEquals(10.0, $zaragoza['financed_amount_bonus_percent']);
    }

    public function test_dashboard_area_manager_asigna_facilitea_general_a_tienda_entrega(): void
    {
        app(CommercialCommissionFormulaConfigService::class)->saveForMonth('2026-06', [
            'area_manager' => [
                'assignments' => [
                    'badajoz' => [
                        'label' => 'Badajoz',
                        'manager_key' => 'david-baeza',
                        'active' => true,
                        'objectives' => [
                            'deliveries' => 2,
                            'benefit' => 100,
                            'guarantee' => 50,
                            'purchases' => 0,
                        ],
                    ],
                ],
            ],
        ]);

        foreach ([
            ['id' => 'DAV-DEL-1', 'name' => 'Venta Badajoz', 'record_type' => 'Venta'],
            ['id' => 'DAV-FAC-1', 'name' => 'FACILITEA Badajoz', 'record_type' => 'Otro'],
        ] as $operation) {
            SalesforceOpportunity::create([
                'salesforce_id' => $operation['id'],
                'name' => $operation['name'],
                'owner_id' => '005-GENERAL',
                'owner_name' => 'General',
                'owner_is_active' => true,
                'owner_delegation' => 'General',
                'delivery_store' => 'HR MOTOR BADAJOZ',
                'stage_name' => 'Contrato',
                'record_type_name' => $operation['record_type'],
                'cv_signed' => true,
                'cv_signed_date' => '2026-06-12',
                'beneficio_financiacion_comercial' => 100,
                'garantia_total' => 50,
            ]);
        }

        $payload = app(AreaManagerCommissionDashboardService::class)->build('2026-06');
        $david = collect($payload['summary_rows'])->firstWhere('manager_key', 'david-baeza');

        $this->assertNotNull($david);
        $this->assertSame(1.0, $david['deliveries_actual']);
        $this->assertSame(0.0, $david['purchases_actual']);
    }

    public function test_dashboard_area_manager_imputa_entregas_y_compras_por_owner_delegation(): void
    {
        app(CommercialCommissionFormulaConfigService::class)->saveForMonth('2026-04', [
            'area_manager' => [
                'assignments' => [
                    'sant-boi' => [
                        'label' => 'Sant Boi',
                        'manager_key' => 'david-baeza',
                        'active' => true,
                        'objectives' => [
                            'deliveries' => 1,
                            'benefit' => 100,
                            'guarantee' => 50,
                            'purchases' => 1,
                        ],
                    ],
                    'manresa' => [
                        'label' => 'Manresa',
                        'manager_key' => 'david-baeza',
                        'active' => true,
                        'objectives' => [
                            'deliveries' => 1,
                            'benefit' => 100,
                            'guarantee' => 50,
                            'purchases' => 1,
                        ],
                    ],
                ],
            ],
        ]);

        SalesforceOpportunity::create([
            'salesforce_id' => 'AM-OWNER-DEL-1',
            'name' => 'Entrega owner San Boi',
            'owner_id' => '005-AM-SB',
            'owner_name' => 'Area Comercial',
            'owner_is_active' => true,
            'owner_delegation' => 'San Boi',
            'delivery_store' => 'Manresa',
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'cv_signed' => true,
            'cv_signed_date' => '2026-04-11',
            'beneficio_financiacion_comercial' => 100,
            'garantia_total' => 50,
        ]);

        SalesforceOpportunity::create([
            'salesforce_id' => 'AM-OWNER-PUR-1',
            'name' => 'Compra owner San Boi',
            'owner_id' => '005-AM-SB',
            'owner_name' => 'Area Comercial',
            'owner_is_active' => true,
            'owner_delegation' => 'San Boi',
            'delivery_store' => 'Manresa',
            'stage_name' => 'Contrato',
            'record_type_name' => 'Tasacion',
            'cv_signed' => true,
            'cv_signed_date' => '2026-04-12',
        ]);

        $payload = app(AreaManagerCommissionDashboardService::class)->build('2026-04');
        $row = collect($payload['summary_rows'])->firstWhere('manager_key', 'david-baeza');

        $this->assertNotNull($row);

        $sanBoiDeliveries = collect($row['detail_rows'] ?? [])
            ->first(fn (array $detail) => $detail['delegation_name'] === 'Sant Boi' && $detail['kpi_key'] === 'deliveries');
        $sanBoiPurchases = collect($row['detail_rows'] ?? [])
            ->first(fn (array $detail) => $detail['delegation_name'] === 'Sant Boi' && $detail['kpi_key'] === 'purchases');
        $manresaDeliveries = collect($row['detail_rows'] ?? [])
            ->first(fn (array $detail) => $detail['delegation_name'] === 'Manresa' && $detail['kpi_key'] === 'deliveries');

        $this->assertNotNull($sanBoiDeliveries);
        $this->assertNotNull($sanBoiPurchases);
        $this->assertNotNull($manresaDeliveries);
        $this->assertSame(1.0, $sanBoiDeliveries['actual']);
        $this->assertSame(1.0, $sanBoiPurchases['actual']);
        $this->assertSame(0.0, $manresaDeliveries['actual']);
    }

    public function test_dashboard_area_manager_unifica_llica_de_valls_en_una_sola_delegacion(): void
    {
        app(CommercialCommissionFormulaConfigService::class)->saveForMonth('2026-04', [
            'area_manager' => [
                'assignments' => [
                    'llica-de-valls' => [
                        'label' => 'Llica de Valls',
                        'manager_key' => 'luis-lopez',
                        'active' => true,
                        'objectives' => [
                            'deliveries' => 1,
                            'benefit' => 100,
                            'guarantee' => 50,
                            'purchases' => 1,
                        ],
                    ],
                ],
            ],
        ]);

        SalesforceOpportunity::create([
            'salesforce_id' => 'AM-LLICA-1',
            'name' => 'Llica entrega',
            'owner_id' => '005-AM-LLICA',
            'owner_name' => 'Area Comercial',
            'owner_is_active' => true,
            'owner_delegation' => 'HR MOTOR LLIÇA DE VALLS',
            'delivery_store' => 'Lliça de Valls',
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'cv_signed' => true,
            'cv_signed_date' => '2026-04-09',
            'beneficio_financiacion_comercial' => 100,
            'garantia_total' => 50,
        ]);

        $payload = app(AreaManagerCommissionDashboardService::class)->build('2026-04');
        $row = collect($payload['summary_rows'])->firstWhere('manager_key', 'luis-lopez');

        $this->assertNotNull($row);
        $llicaRows = collect($row['detail_rows'] ?? [])
            ->where('delegation_name', 'Llica de Valls')
            ->where('kpi_key', 'deliveries')
            ->values();

        $this->assertSame(1, $llicaRows->count());
        $this->assertSame(1.0, $llicaRows->first()['actual']);
    }

    public function test_dashboard_area_manager_recupera_owner_delegation_llica_de_vall_legacy(): void
    {
        app(CommercialCommissionFormulaConfigService::class)->saveForMonth('2026-04', [
            'area_manager' => [
                'assignments' => [
                    'llica-de-valls' => [
                        'label' => 'Llica de Valls',
                        'manager_key' => 'luis-lopez',
                        'active' => true,
                        'objectives' => [
                            'deliveries' => 1,
                            'benefit' => 100,
                            'guarantee' => 50,
                            'purchases' => 1,
                        ],
                    ],
                ],
            ],
        ]);

        SalesforceOpportunity::create([
            'salesforce_id' => 'AM-LLICA-LEGACY-1',
            'name' => 'Llica legacy entrega',
            'owner_id' => '005-AM-LLICA-LEGACY',
            'owner_name' => 'Area Comercial',
            'owner_is_active' => true,
            'owner_delegation' => 'Lliça de Vall',
            'delivery_store' => 'Otra Delegacion',
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'cv_signed' => true,
            'cv_signed_date' => '2026-04-10',
            'beneficio_financiacion_comercial' => 100,
            'garantia_total' => 50,
        ]);

        $payload = app(AreaManagerCommissionDashboardService::class)->build('2026-04');
        $row = collect($payload['summary_rows'])->firstWhere('manager_key', 'luis-lopez');

        $this->assertNotNull($row);

        $deliveriesRow = collect($row['detail_rows'] ?? [])
            ->first(fn (array $detail) => $detail['delegation_name'] === 'Llica de Valls' && $detail['kpi_key'] === 'deliveries');

        $this->assertNotNull($deliveriesRow);
        $this->assertSame(1.0, $deliveriesRow['actual']);
    }

    public function helper_dashboard_area_manager_usa_user_delegation_del_owner_como_fallback_fixture(): void
    {
        app(CommercialCommissionFormulaConfigService::class)->saveForMonth('2026-04', [
            'area_manager' => [
                'assignments' => [
                    'llica-de-valls' => [
                        'label' => 'Llica de Valls',
                        'manager_key' => 'luis-lopez',
                        'active' => true,
                        'objectives' => [
                            'deliveries' => 1,
                            'benefit' => 100,
                            'guarantee' => 50,
                            'purchases' => 1,
                        ],
                    ],
                ],
            ],
        ]);

        SalesforceUser::create([
            'salesforce_id' => '005-AM-OWNER-USER',
            'name' => 'Owner Llica',
            'profile_name' => 'Compra/Venta',
            'user_delegation' => 'HR MOTOR LLIÇA DE VALLS',
            'is_active' => true,
        ]);

        SalesforceOpportunity::create([
            'salesforce_id' => 'AM-LLICA-OWNER-USER-1',
            'name' => 'Entrega por delegacion de usuario',
            'owner_id' => '005-AM-OWNER-USER',
            'owner_name' => 'Owner Llica',
            'owner_is_active' => true,
            'owner_delegation' => '',
            'delivery_store' => 'Otra Delegacion',
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'cv_signed' => true,
            'cv_signed_date' => '2026-04-10',
            'beneficio_financiacion_comercial' => 100,
            'garantia_total' => 50,
        ]);

        $payload = app(AreaManagerCommissionDashboardService::class)->build('2026-04');
        $row = collect($payload['summary_rows'])->firstWhere('manager_key', 'luis-lopez');

        $this->assertNotNull($row);

        $deliveriesRow = collect($row['detail_rows'] ?? [])
            ->first(fn (array $detail) => $detail['delegation_name'] === 'Llica de Valls' && $detail['kpi_key'] === 'deliveries');

        $this->assertNotNull($deliveriesRow);
        $this->assertSame(1.0, $deliveriesRow['actual']);
    }

    public function helper_dashboard_area_manager_usa_user_delegation_del_owner_como_fallback_ascii(): void
    {
        app(CommercialCommissionFormulaConfigService::class)->saveForMonth('2026-04', [
            'area_manager' => [
                'assignments' => [
                    'llica-de-valls' => [
                        'label' => 'Llica de Valls',
                        'manager_key' => 'luis-lopez',
                        'active' => true,
                        'objectives' => [
                            'deliveries' => 1,
                            'benefit' => 100,
                            'guarantee' => 50,
                            'purchases' => 1,
                        ],
                    ],
                ],
            ],
        ]);

        SalesforceUser::create([
            'salesforce_id' => '005-AM-OWNER-USER-ASCII',
            'name' => 'Owner Llica ASCII',
            'profile_name' => 'Compra/Venta',
            'user_delegation' => 'HR MOTOR LLICA DE VALLS',
            'is_active' => true,
        ]);

        SalesforceOpportunity::create([
            'salesforce_id' => 'AM-LLICA-OWNER-USER-ASCII-1',
            'name' => 'Entrega por delegacion de usuario ASCII',
            'owner_id' => '005-AM-OWNER-USER-ASCII',
            'owner_name' => 'Owner Llica ASCII',
            'owner_is_active' => true,
            'owner_delegation' => '',
            'delivery_store' => 'Otra Delegacion',
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'cv_signed' => true,
            'cv_signed_date' => '2026-04-10',
            'beneficio_financiacion_comercial' => 100,
            'garantia_total' => 50,
        ]);

        $payload = app(AreaManagerCommissionDashboardService::class)->build('2026-04');
        $row = collect($payload['summary_rows'])->firstWhere('manager_key', 'luis-lopez');

        $this->assertNotNull($row);

        $deliveriesRow = collect($row['detail_rows'] ?? [])
            ->first(fn (array $detail) => $detail['delegation_name'] === 'Llica de Valls' && $detail['kpi_key'] === 'deliveries');

        $this->assertNotNull($deliveriesRow);
        $this->assertSame(1.0, $deliveriesRow['actual']);
    }

    public function test_desde_junio_comercial_cobra_operaciones_del_mes_sin_rentabilidad_historica(): void
    {
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');
        $this->createCommercialUser('005-MONTHLY', 'Comercial Mensual');

        foreach ([
            ['id' => 'MONTHLY-SALE', 'type' => 'Venta'],
            ['id' => 'MONTHLY-APPRAISAL', 'type' => 'Tasacion'],
            ['id' => 'MONTHLY-CHANGE', 'type' => 'Cambio'],
        ] as $index => $operation) {
            SalesforceOpportunity::create([
                'salesforce_id' => $operation['id'],
                'name' => $operation['type'].' junio',
                'owner_id' => '005-MONTHLY',
                'owner_name' => 'Comercial Mensual',
                'owner_is_active' => true,
                'stage_name' => 'Contrato',
                'record_type_name' => $operation['type'],
                'cv_signed' => true,
                'cv_signed_date' => '2026-06-0'.($index + 1),
                'gestion_de_venta' => false,
            ]);
        }

        $row = collect(app(CommercialCommissionDashboardService::class)->build('2026-06')['summary_rows'])
            ->firstWhere('commercial_id', '005-MONTHLY');

        $this->assertNotNull($row);
        $this->assertFalse($row['is_appraiser']);
        $this->assertSame(1, $row['sales_count']);
        $this->assertSame(1, $row['appraisals_count']);
        $this->assertSame(1, $row['changes_count']);
        $this->assertEquals(60.0, $row['sales_amount']);
        $this->assertEquals(60.0, $row['appraisals_amount']);
        $this->assertEquals(85.0, $row['changes_amount']);
        $this->assertEquals(145.0, $row['purchases_amount']);
        $this->assertEquals(205.0, $row['operations_commission_amount']);
        $this->assertSame('Cambio 85 EUR', collect($row['details']['operations'])->firstWhere('opportunity_id', 'MONTHLY-CHANGE')['reason']);
    }

    public function test_desde_junio_venta_y_cambio_compartidos_pagan_una_participacion_al_secundario_sin_reatribuir_operaciones(): void
    {
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');
        $this->createCommercialUser('005-SHARED-OWNER', 'Owner sintético');
        $this->createCommercialUser('005-SHARED-SECONDARY', 'Secundario sintético');

        foreach ([
            ['id' => 'SHARED-SALE', 'type' => 'Venta'],
            ['id' => 'SHARED-CHANGE', 'type' => 'Cambio'],
            ['id' => 'SHARED-APPRAISAL-IGNORED', 'type' => 'Tasacion'],
        ] as $index => $operation) {
            SalesforceOpportunity::create([
                'salesforce_id' => $operation['id'],
                'name' => $operation['type'].' compartida sintética',
                'owner_id' => '005-SHARED-OWNER',
                'owner_name' => 'Owner sintético',
                'owner_is_active' => true,
                'stage_name' => 'Contrato',
                'record_type_name' => $operation['type'],
                'cv_signed' => true,
                'cv_signed_date' => '2026-09-0'.($index + 1),
                'gestion_de_venta' => false,
                'shared_delivery_id' => '005-SHARED-SECONDARY',
                'shared_delivery_name' => 'Secundario sintético',
            ]);
        }

        $dashboard = app(CommercialCommissionDashboardService::class)->build('2026-09');
        $owner = collect($dashboard['summary_rows'])->firstWhere('commercial_id', '005-SHARED-OWNER');
        $secondary = collect($dashboard['summary_rows'])->firstWhere('commercial_id', '005-SHARED-SECONDARY');

        $this->assertNotNull($owner);
        $this->assertSame(3, $owner['operations_count']);
        $this->assertSame(2, $owner['deliveries_count']);
        $this->assertSame(1, $owner['changes_count']);
        $this->assertEquals(85.0, $owner['changes_amount']);
        $this->assertEquals(145.0, $owner['purchases_amount']);
        $this->assertEquals(175.0, $owner['operations_commission_amount']);
        $this->assertSame(0, $owner['shared_count']);
        $this->assertEquals(0.0, $owner['shared_amount']);
        $this->assertEquals(175.0, $owner['prima_total']);
        $this->assertEquals(175.0, $owner['prima_adjusted']);
        $this->assertSame('Cambio 85 EUR', collect($owner['details']['operations'])->firstWhere('opportunity_id', 'SHARED-CHANGE')['reason']);
        $this->assertSame('SHARED-CHANGE', collect($owner['details']['purchases'])->firstWhere('opportunity_id', 'SHARED-CHANGE')['opportunity_id']);

        $this->assertNotNull($secondary);
        $this->assertSame('Secundario sintético', $secondary['commercial_name']);
        $this->assertSame(0, $secondary['operations_count']);
        $this->assertSame(0, $secondary['deliveries_count']);
        $this->assertSame(0, $secondary['changes_count']);
        $this->assertEquals(0.0, $secondary['purchases_amount']);
        $this->assertSame(2, $secondary['shared_count']);
        $this->assertEquals(60.0, $secondary['shared_amount']);
        $this->assertEquals(60.0, $secondary['prima_total']);
        $this->assertEquals(60.0, $secondary['prima_adjusted']);

        $sharedOpportunityIds = collect($secondary['details']['shared'])->pluck('opportunity_id');
        $this->assertEqualsCanonicalizing(['SHARED-SALE', 'SHARED-CHANGE'], $sharedOpportunityIds->all());
        $this->assertSame(2, $sharedOpportunityIds->unique()->count());
        $this->assertEmpty($secondary['details']['operations']);
        $this->assertSame(2, $dashboard['diagnostics']['shared_sales_count']);
    }

    public function test_entrega_compartida_no_duplica_owner_igual_a_secundario_ni_inventa_comision_para_tasador_secundario(): void
    {
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');
        $this->createCommercialUser('005-SHARED-SELF', 'Comercial autorreferenciado');
        $this->createCommercialUser('005-SHARED-OWNER-2', 'Owner sintético 2');
        SalesforceUser::create([
            'salesforce_id' => '005-SHARED-APPRAISER',
            'name' => 'Tasador secundario sintético',
            'profile_name' => 'Standard User',
            'is_active' => true,
            'commission_appraiser' => true,
        ]);

        SalesforceOpportunity::create([
            'salesforce_id' => 'SHARED-SELF-CHANGE',
            'name' => 'Cambio autorreferenciado sintético',
            'owner_id' => '005-SHARED-SELF',
            'owner_name' => 'Comercial autorreferenciado',
            'owner_is_active' => true,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Cambio',
            'cv_signed' => true,
            'cv_signed_date' => '2026-09-10',
            'gestion_de_venta' => false,
            'shared_delivery_id' => '005-SHARED-SELF',
            'shared_delivery_name' => 'Comercial autorreferenciado',
        ]);
        SalesforceOpportunity::create([
            'salesforce_id' => 'SHARED-APPRAISER-CHANGE',
            'name' => 'Cambio con tasador secundario sintético',
            'owner_id' => '005-SHARED-OWNER-2',
            'owner_name' => 'Owner sintético 2',
            'owner_is_active' => true,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Cambio',
            'cv_signed' => true,
            'cv_signed_date' => '2026-09-11',
            'gestion_de_venta' => false,
            'shared_delivery_id' => '005-SHARED-APPRAISER',
            'shared_delivery_name' => 'Tasador secundario sintético',
        ]);

        $rows = collect(app(CommercialCommissionDashboardService::class)->build('2026-09')['summary_rows']);
        $self = $rows->firstWhere('commercial_id', '005-SHARED-SELF');
        $owner = $rows->firstWhere('commercial_id', '005-SHARED-OWNER-2');
        $appraiser = $rows->firstWhere('commercial_id', '005-SHARED-APPRAISER');

        $this->assertNotNull($self);
        $this->assertSame(1, $self['changes_count']);
        $this->assertEquals(85.0, $self['changes_amount']);
        $this->assertSame(0, $self['shared_count']);
        $this->assertEquals(0.0, $self['shared_amount']);
        $this->assertEmpty($self['details']['shared']);

        $this->assertNotNull($owner);
        $this->assertSame(1, $owner['changes_count']);
        $this->assertEquals(85.0, $owner['changes_amount']);
        $this->assertSame(0, $owner['shared_count']);

        $this->assertNotNull($appraiser);
        $this->assertTrue($appraiser['is_appraiser']);
        $this->assertSame(0, $appraiser['operations_count']);
        $this->assertSame(0, $appraiser['shared_count']);
        $this->assertEquals(0.0, $appraiser['shared_amount']);
        $this->assertEquals(0.0, $appraiser['final_commission']);
        $this->assertEmpty($appraiser['details']['shared']);
    }

    public function test_desde_junio_tasador_aplica_escalado_financiacion_y_rapidez(): void
    {
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');
        SalesforceUser::create([
            'salesforce_id' => '005-APPRAISER',
            'name' => 'Tasador Junio',
            'profile_name' => 'Standard User',
            'is_active' => true,
            'commission_appraiser' => true,
        ]);

        foreach (range(1, 12) as $index) {
            SalesforceOpportunity::create([
                'salesforce_id' => 'APPRAISER-PURCHASE-'.$index,
                'name' => 'Compra tasador '.$index,
                'owner_id' => '005-APPRAISER',
                'owner_name' => 'Tasador Junio',
                'owner_is_active' => true,
                'stage_name' => 'Contrato',
                'record_type_name' => $index === 12 ? 'Cambio' : 'Tasacion',
                'cv_signed' => true,
                'cv_signed_date' => '2026-06-01',
                'gestion_de_venta' => false,
                'vehicle_plate' => 'TAS'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
            ]);
        }

        SalesforceOpportunity::create([
            'salesforce_id' => 'APPRAISER-SALE-JUNE',
            'name' => 'Venta tasador junio',
            'owner_id' => '005-APPRAISER',
            'owner_name' => 'Tasador Junio',
            'owner_is_active' => true,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'cv_signed' => true,
            'cv_signed_date' => '2026-06-10',
            'gestion_de_venta' => false,
            'beneficio_financiacion_comercial' => 1000,
            'vehicle_plate' => 'VENTA001',
        ]);

        SalesforceOpportunity::create([
            'salesforce_id' => 'APPRAISER-SALE-SPEED',
            'name' => 'Venta posterior tasador',
            'owner_id' => '005-OTHER',
            'owner_name' => 'Otro comercial',
            'owner_is_active' => true,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'cv_signed' => true,
            'cv_signed_date' => '2026-06-20',
            'gestion_de_venta' => false,
            'vehicle_plate' => 'TAS001',
        ]);

        $row = collect(app(CommercialCommissionDashboardService::class)->build('2026-06')['summary_rows'])
            ->firstWhere('commercial_id', '005-APPRAISER');

        $this->assertNotNull($row);
        $this->assertTrue($row['is_appraiser']);
        $this->assertSame(12, $row['appraiser_purchase_count']);
        $this->assertSame('11-15 compras', $row['appraiser_purchase_tier']);
        $this->assertEquals(60.0, $row['appraiser_purchase_rate']);
        $this->assertSame(11, $row['appraisals_count']);
        $this->assertEquals(660.0, $row['appraisals_amount']);
        $this->assertSame(1, $row['changes_count']);
        $this->assertEquals(60.0, $row['changes_amount']);
        $this->assertEquals(720.0, $row['purchases_amount']);
        $this->assertEquals(780.0, $row['operations_commission_amount']);
        $this->assertEquals(60.0, $row['sales_amount']);
        $this->assertEquals(30.0, $row['appraiser_financing_commission']);
        $this->assertEquals(20.0, $row['appraiser_speed_under_30_amount']);
        $this->assertEquals(830.0, $row['final_commission']);
    }

    public function test_tasador_desglosa_ocho_tasaciones_y_dos_cambios_sin_duplicar_la_comision(): void
    {
        $row = $this->buildAppraiserPurchaseBreakdown(8, 2, 'APPRAISER-SPLIT');

        $this->assertSame(8, $row['appraisals_count']);
        $this->assertEquals(240.0, $row['appraisals_amount']);
        $this->assertSame(2, $row['changes_count']);
        $this->assertEquals(60.0, $row['changes_amount']);
        $this->assertSame(10, $row['appraiser_purchase_count']);
        $this->assertEquals(30.0, $row['appraiser_purchase_rate']);
        $this->assertEquals(300.0, $row['purchases_amount']);
        $this->assertEquals($row['appraisals_amount'] + $row['changes_amount'], $row['purchases_amount']);
        $this->assertEquals(300.0, $row['operations_commission_amount']);
        $this->assertEquals(300.0, $row['prima_total']);
        $this->assertEquals(300.0, $row['prima_adjusted']);
        $this->assertEquals(300.0, $row['final_commission']);
    }

    #[DataProvider('appraiserPurchaseBreakdownCases')]
    public function test_tasador_mantiene_el_desglose_para_un_solo_tipo_y_el_tramo_sin_comision(
        int $appraisals,
        int $changes,
        float $expectedRate,
        float $expectedAppraisalsAmount,
        float $expectedChangesAmount,
    ): void {
        $row = $this->buildAppraiserPurchaseBreakdown($appraisals, $changes, 'APPRAISER-EDGE-'.$appraisals.'-'.$changes);

        $this->assertSame($appraisals, $row['appraisals_count']);
        $this->assertSame($changes, $row['changes_count']);
        $this->assertEquals($expectedRate, $row['appraiser_purchase_rate']);
        $this->assertEquals($expectedAppraisalsAmount, $row['appraisals_amount']);
        $this->assertEquals($expectedChangesAmount, $row['changes_amount']);
        $this->assertEquals($expectedAppraisalsAmount + $expectedChangesAmount, $row['purchases_amount']);
        $this->assertEquals($row['purchases_amount'], $row['final_commission']);
    }

    public static function appraiserPurchaseBreakdownCases(): array
    {
        return [
            'solo tasaciones' => [8, 0, 30.0, 240.0, 0.0],
            'solo cambios' => [0, 8, 30.0, 0.0, 240.0],
            'tramo cero conjunto' => [4, 3, 0.0, 0.0, 0.0],
        ];
    }

    private function buildAppraiserPurchaseBreakdown(int $appraisals, int $changes, string $userId): array
    {
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');

        SalesforceUser::create([
            'salesforce_id' => $userId,
            'name' => 'Tasador de prueba',
            'profile_name' => 'Standard User',
            'is_active' => true,
            'commission_appraiser' => true,
        ]);

        foreach ([
            'Tasacion' => $appraisals,
            'Cambio' => $changes,
        ] as $recordType => $count) {
            if ($count === 0) {
                continue;
            }

            foreach (range(1, $count) as $index) {
                SalesforceOpportunity::create([
                    'salesforce_id' => $userId.'-'.$recordType.'-'.$index,
                    'name' => $recordType.' de prueba '.$index,
                    'owner_id' => $userId,
                    'owner_name' => 'Tasador de prueba',
                    'owner_is_active' => true,
                    'stage_name' => 'Contrato',
                    'record_type_name' => $recordType,
                    'cv_signed' => true,
                    'cv_signed_date' => '2026-07-10',
                    'gestion_de_venta' => false,
                ]);
            }
        }

        $row = collect(app(CommercialCommissionDashboardService::class)->build('2026-07')['summary_rows'])
            ->firstWhere('commercial_id', $userId);

        $this->assertNotNull($row);

        return $row;
    }

    private function authenticatedSession(string $role): array
    {
        $this->reportUserSequence++;
        $user = ReportUser::query()->create([
            'name' => 'Test '.$role,
            'email' => $role.'-'.$this->reportUserSequence.'@example.test',
            'password' => Hash::make('dashboard-test-password'),
            'role' => $role,
            'area_zone' => $role === ReportUser::ROLE_AREA_MANAGER ? 'north' : null,
            'is_active' => true,
        ]);

        return [
            'informes_authenticated' => true,
            'report_user_id' => $user->id,
            'report_user_role' => $user->role,
            'report_user_email' => $user->email,
        ];
    }

    private function createCommercialUser(string $id, string $name, bool $isActive = true, string $profile = 'Compra/Venta'): void
    {
        SalesforceUser::create([
            'salesforce_id' => $id,
            'name' => $name,
            'profile_name' => $profile,
            'is_active' => $isActive,
        ]);
    }

    private function seedAreaManagerDirectorScenario(string $month): void
    {
        $assignments = collect([
            ['label' => 'Malaga', 'manager_key' => 'david-baeza'],
            ['label' => 'Bilbao', 'manager_key' => 'kosta-plamenov'],
            ['label' => 'Pamplona', 'manager_key' => 'kosta-plamenov'],
            ['label' => 'Alicante', 'manager_key' => 'nicolas-fernandez'],
            ['label' => 'Paterna', 'manager_key' => 'nicolas-fernandez'],
            ['label' => 'Elche', 'manager_key' => 'nicolas-fernandez'],
            ['label' => 'Badalona', 'manager_key' => 'luis-lopez'],
            ['label' => 'Girona', 'manager_key' => 'luis-lopez'],
            ['label' => 'Lleida', 'manager_key' => 'luis-lopez'],
            ['label' => 'Manresa', 'manager_key' => 'luis-lopez'],
        ])->mapWithKeys(fn (array $assignment): array => [
            str($assignment['label'])->slug()->toString() => [
                ...$assignment,
                'active' => true,
                'objectives' => ['deliveries' => 1, 'benefit' => 0, 'guarantee' => 0, 'purchases' => 0],
            ],
        ])->all();

        app(CommercialCommissionFormulaConfigService::class)->saveForMonth($month, [
            'area_manager' => [
                'kpi_bases' => ['deliveries' => 1000, 'benefit' => 0, 'guarantee' => 0, 'purchases' => 0],
                'zone_keys' => [['min_percent' => 0, 'multiplier' => 1.0]],
                'assignments' => $assignments,
            ],
        ]);

        foreach (array_values($assignments) as $index => $assignment) {
            SalesforceOpportunity::query()->create([
                'salesforce_id' => 'AM-DIRECTOR-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                'name' => 'Entrega '.$assignment['label'],
                'owner_id' => '005-AM-DIRECTOR-'.$index,
                'owner_name' => 'Comercial '.$assignment['label'],
                'owner_is_active' => true,
                'owner_delegation' => $assignment['label'],
                'delivery_store' => $assignment['label'],
                'stage_name' => 'Contrato',
                'record_type_name' => 'Venta',
                'cv_signed' => true,
                'cv_signed_date' => $month.'-15',
                'beneficio_financiacion_comercial' => 0,
                'garantia_total' => 0,
            ]);
        }
    }
}
