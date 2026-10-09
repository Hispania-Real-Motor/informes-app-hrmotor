<?php

namespace Tests\Feature;

use App\Models\CampaignAttribution;
use App\Models\CampaignPlatformDailyMetric;
use App\Models\ReportUser;
use App\Models\SalesforceInterest;
use App\Models\SalesforceLead;
use App\Models\SalesforceOpportunity;
use App\Services\Campaigns\CampaignAttributionBuilderService;
use App\Services\Campaigns\CampaignSaleAmountResolver;
use App\Services\Campaigns\GoogleAdsClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ProvidesRot4CampaignContext;
use Tests\TestCase;

class CampaignDashboardTest extends TestCase
{
    use ProvidesRot4CampaignContext;
    use RefreshDatabase;

    private int $reportUserSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->setUpRot4CampaignContext();
        $this->withSession($this->authenticatedSession(ReportUser::ROLE_ADMIN));
    }

    public function test_dashboard_campanas_muestra_menu_y_endpoints(): void
    {
        $this->get('/informes/campanas')
            ->assertOk()
            ->assertSee('window.reportUserCanExport = true', false)
            ->assertSee('campaignCharts', false);

        $this->get('/informes/leads')
            ->assertOk()
            ->assertSee('/informes/campanas', false);
    }

    public function test_el_menu_oculta_campanas_en_area_manager_y_lo_muestra_en_director(): void
    {
        config()->set('services.informes_auth.enabled', true);

        $areaManagerSession = $this->authenticatedSession(ReportUser::ROLE_AREA_MANAGER);
        $directorSession = $this->authenticatedSession(ReportUser::ROLE_DIRECTOR);

        $this->withSession($areaManagerSession)
            ->get('/informes/leads')
            ->assertOk()
            ->assertDontSee('/informes/campanas', false);

        $this->withSession($directorSession)
            ->get('/informes/leads')
            ->assertOk()
            ->assertSee('/informes/campanas', false);
    }

    public function test_ui_drawer_and_columns_follow_platform_campaign_v1_contract(): void
    {
        $html = $this->get('/informes/campanas')->assertOk()->getContent();

        $this->assertStringContainsString('id="campaignCharts"', $html);
        $this->assertStringContainsString('id="platformComparison"', $html);
        $this->assertStringContainsString('id="campaignType"', $html);
        $this->assertStringContainsString('id="leadType"', $html);
        $this->assertStringContainsString('Tipo de interés', $html);
        $this->assertStringContainsString('Clasifica la campaña; no filtra el tipo del interés.', $html);
        $this->assertStringContainsString('Filtra por el tipo normalizado del interés.', $html);
        $this->assertStringContainsString('id="saleSubcategory"', $html);
        $this->assertStringContainsString('data-context="venta"', $html);
        $this->assertStringContainsString('data-context="tasacion"', $html);
        $this->assertStringNotContainsString('data-context="exposicion"', $html);
        $this->assertStringNotContainsString('data-context="branding"', $html);
        $this->assertStringNotContainsString('data-context="otros"', $html);
        $this->assertStringContainsString('option value="active" selected', $html);
        $this->assertStringContainsString('id="rankingsToggle"', $html);
        $this->assertStringContainsString('id="rankingsPopover"', $html);
        $this->assertStringContainsString('id="campaignNameChecklist"', $html);
        $this->assertStringContainsString('id="campaignNamesSelectAll"', $html);
        $this->assertStringContainsString('id="campaignNamesClear"', $html);
        $this->assertStringContainsString('id="advancedFiltersApply"', $html);
        $this->assertStringContainsString('campaigns.dailyChart.visibleSeries', file_get_contents(resource_path('js/reports/campaigns-dashboard.js')));
        $this->assertStringContainsString('campaigns.dailyChart.chartType', file_get_contents(resource_path('js/reports/campaigns-dashboard.js')));
        $this->assertStringContainsString('data-series', file_get_contents(resource_path('js/reports/campaigns-dashboard.js')));
        $this->assertStringContainsString('data-chart-type', file_get_contents(resource_path('js/reports/campaigns-dashboard.js')));
        $this->assertStringContainsString('campaign-detail-toggle', file_get_contents(resource_path('js/reports/campaigns-dashboard.js')));
        $this->assertStringContainsString('line-label-layer', file_get_contents(resource_path('js/reports/campaigns-dashboard.js')));
        $this->assertStringContainsString('campaignPointIcon', file_get_contents(resource_path('js/reports/campaigns-dashboard.js')));
        $this->assertStringContainsString("{ value: 'current_year', label: 'Año actual' }", file_get_contents(resource_path('js/reports/campaigns-dashboard.js')));
        $this->assertStringContainsString('Evolución de tasaciones y compras', file_get_contents(resource_path('js/reports/campaigns-dashboard.js')));
        $this->assertStringContainsString('const monthlyChartEnabled = false;', file_get_contents(resource_path('js/reports/campaigns-dashboard.js')));
        $this->assertStringContainsString('summaryLoadingController?.abort()', file_get_contents(resource_path('js/reports/campaigns-dashboard.js')));
        $this->assertStringContainsString('requestId !== latestReloadRequestId', file_get_contents(resource_path('js/reports/campaigns-dashboard.js')));
        $this->assertStringNotContainsString('id="reviewCampaigns"', $html);
        $this->assertStringNotContainsString('#7d494e', file_get_contents(resource_path('css/reports/leads-dashboard.css')));
        $this->assertStringNotContainsString('brand-block', $html);
        $this->assertStringNotContainsString('id="mediumAcquired"', $html);
        $this->assertStringNotContainsString('id="campaignAcquired"', $html);
        $this->assertStringNotContainsString('id="campaignId"', $html);
        $this->assertStringNotContainsString('id="campaignName"', $html);
        $this->assertStringContainsString('id="hasOpportunity"', $html);
        $this->assertStringContainsString('id="hasReservation"', $html);
        $this->assertStringContainsString('id="hasSale"', $html);
        $this->assertStringContainsString('id="classification"', $html);

        $this->assertStringNotContainsString('id="sourceAcquired"', $html);
        $this->assertStringNotContainsString('id="commercialUser"', $html);
        $this->assertStringNotContainsString('id="vehicleInterest"', $html);
        $this->assertStringNotContainsString('id="delegation"', $html);
        $this->assertStringNotContainsString('id="zone"', $html);
        $this->assertStringNotContainsString('id="leadStatus"', $html);
        $this->assertStringNotContainsString('data-column="campaign_source_type_label"', $html);
        $this->assertStringNotContainsString('data-column="match_status"', $html);
        $this->assertStringNotContainsString('data-column="platform_leads"', $html);
    }

    public function test_cache_identity_changes_when_same_direct_run_moves_from_running_to_completed(): void
    {
        DB::table('salesforce_opportunity_interest_direct_runs')
            ->where('id', $this->rot4DirectRunId)
            ->update(['status' => 'running', 'completed_at' => null]);
        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-cache-direct',
            'name' => 'Opportunity cache direct',
            'created_date' => '2026-05-11 10:00:00',
            'stage_name' => 'Abierta',
            'record_type_name' => 'Venta',
            'is_deleted' => false,
        ]);
        DB::table('campaign_lead_attributions')->insert($this->attributionRow([
            'interest_id' => '00Q-cache-direct',
            'campaign_id' => 'cache-direct',
            'campaign_name' => 'Cache direct',
            'opportunity_id' => '006-cache-direct',
            'has_opportunity' => false,
        ]));

        $this->getJson('/informes/campanas/data/summary?'.$this->campaignQuery())
            ->assertOk()
            ->assertJsonPath('kpis.opportunities', 0);

        DB::table('campaign_lead_attributions')->where('interest_id', '00Q-cache-direct')->update(['has_opportunity' => true]);
        DB::table('salesforce_opportunity_interest_direct_runs')
            ->where('id', $this->rot4DirectRunId)
            ->update([
                'status' => 'completed',
                'source_cutoff_at' => '2026-12-31 23:59:59',
                'completed_at' => '2026-12-31 23:59:59',
            ]);

        $this->getJson('/informes/campanas/data/summary?'.$this->campaignQuery())
            ->assertOk()
            ->assertJsonPath('kpis.opportunities', 1);
    }

    public function test_cache_identity_changes_when_same_f2_run_moves_from_running_to_completed(): void
    {
        DB::table('report_sync_runs')
            ->where('id', $this->rot4InterestRunId)
            ->update(['status' => 'running', 'source_cutoff_at' => null, 'completed_at' => null]);
        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-cache-interest',
            'name' => 'Opportunity cache Interest',
            'created_date' => '2026-05-11 10:00:00',
            'stage_name' => 'Abierta',
            'record_type_name' => 'Venta',
            'is_deleted' => false,
        ]);
        DB::table('campaign_lead_attributions')->insert($this->attributionRow([
            'interest_id' => '00Q-cache-interest',
            'campaign_id' => 'cache-interest',
            'campaign_name' => 'Cache Interest',
            'opportunity_id' => '006-cache-interest',
            'has_opportunity' => false,
        ]));

        $this->getJson('/informes/campanas/data/summary?'.$this->campaignQuery())
            ->assertOk()
            ->assertJsonPath('kpis.opportunities', 0);

        DB::table('campaign_lead_attributions')->where('interest_id', '00Q-cache-interest')->update(['has_opportunity' => true]);
        DB::table('report_sync_runs')
            ->where('id', $this->rot4InterestRunId)
            ->update([
                'status' => 'completed',
                'source_cutoff_at' => '2026-12-31 23:59:59',
                'completed_at' => '2026-12-31 23:59:59',
            ]);

        $this->getJson('/informes/campanas/data/summary?'.$this->campaignQuery())
            ->assertOk()
            ->assertJsonPath('kpis.opportunities', 1);
    }

    public function test_admin_ve_diagnostico_y_puede_exportar_pero_viewer_no(): void
    {
        config()->set('services.informes_auth.enabled', true);

        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'platform' => 'meta',
            'metric_date' => '2026-05-10',
            'campaign_id' => 'camp-export',
            'campaign_name' => 'Export Campaign',
            'spend' => 100,
        ]));

        $adminSession = $this->authenticatedSession(ReportUser::ROLE_ADMIN);
        $viewerSession = $this->authenticatedSession(ReportUser::ROLE_VIEWER);
        $directorSession = $this->authenticatedSession(ReportUser::ROLE_DIRECTOR);

        $this->withSession($adminSession)
            ->get('/informes/campanas')
            ->assertOk()
            ->assertSee('diagnosticsOpen', false)
            ->assertSee('campaignDiagnosticsModal', false)
            ->assertSee('window.reportUserCanExport = true', false)
            ->assertSee('window.reportUserCanAudit = true', false);

        $this->withSession($adminSession)
            ->getJson('/informes/campanas/data/summary?'.$this->campaignQuery())
            ->assertOk()
            ->assertJsonStructure(['diagnostics']);

        $this->withSession($adminSession)
            ->getJson('/informes/campanas/data/summary?'.$this->campaignQuery().'&include_diagnostics=0')
            ->assertOk()
            ->assertJsonMissingPath('diagnostics')
            ->assertJsonStructure(['rankings']);

        $this->withSession($adminSession)
            ->get('/informes/campanas/export/campaigns.csv?'.$this->campaignQuery())
            ->assertOk();

        $this->withSession($adminSession)
            ->getJson('/informes/campanas/data/kpi-audit?'.$this->campaignQuery().'&metric=leads_salesforce')
            ->assertOk();

        $this->withSession($adminSession)
            ->get('/informes/campanas/export/kpi-audit.csv?'.$this->campaignQuery().'&metric=leads_salesforce')
            ->assertOk();

        $this->withSession($viewerSession)
            ->get('/informes/campanas')
            ->assertRedirect('/informes/leads');

        $this->withSession($viewerSession)
            ->getJson('/informes/campanas/data/summary?'.$this->campaignQuery())
            ->assertForbidden();

        $this->withSession($viewerSession)
            ->get('/informes/campanas/export/campaigns.csv?'.$this->campaignQuery())
            ->assertForbidden();

        $this->withSession($viewerSession)
            ->getJson('/informes/campanas/data/kpi-audit?'.$this->campaignQuery().'&metric=leads_salesforce')
            ->assertForbidden();

        $this->withSession($viewerSession)
            ->get('/informes/campanas/export/kpi-audit.csv?'.$this->campaignQuery().'&metric=leads_salesforce')
            ->assertForbidden();

        $this->withSession($directorSession)
            ->get('/informes/campanas')
            ->assertOk()
            ->assertDontSee('diagnosticsOpen', false)
            ->assertDontSee('campaignDiagnosticsModal', false)
            ->assertSee('window.reportUserCanExport = true', false)
            ->assertSee('window.reportUserCanAudit = true', false);

        $this->withSession($directorSession)
            ->getJson('/informes/campanas/data/summary?'.$this->campaignQuery())
            ->assertOk()
            ->assertJsonMissingPath('diagnostics')
            ->assertJsonPath('warnings', []);

        $this->withSession($directorSession)
            ->get('/informes/campanas/export/campaigns.csv?'.$this->campaignQuery())
            ->assertOk();

        $this->withSession($directorSession)
            ->getJson('/informes/campanas/data/kpi-audit?'.$this->campaignQuery().'&metric=leads_salesforce')
            ->assertOk();

        $this->withSession($directorSession)
            ->get('/informes/campanas/export/kpi-audit.csv?'.$this->campaignQuery().'&metric=leads_salesforce')
            ->assertOk();

        $this->withSession($directorSession)
            ->get('/informes/campanas/export/attributions.csv?'.$this->campaignQuery())
            ->assertOk();
    }

    public function test_cierre_de_inversion_congela_metricas_y_solo_admin_puede_reabrir(): void
    {
        config()->set('services.informes_auth.enabled', true);
        $adminSession = $this->authenticatedSession(ReportUser::ROLE_ADMIN);

        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'metric_date' => '2026-05-10',
            'campaign_id' => 'camp-closure',
            'campaign_name' => 'Campaign closure',
            'spend' => 100,
            'impressions' => 1000,
            'clicks' => 100,
        ]));

        $this->withSession($adminSession)
            ->postJson('/informes/campanas/investment-closure', ['month' => '2026-05-01'])
            ->assertOk()
            ->assertJsonPath('investment_status', 'closed')
            ->assertJsonPath('commercial_results_status', 'open')
            ->assertJsonPath('snapshot_version', 1);

        CampaignPlatformDailyMetric::query()->where('campaign_id', 'camp-closure')->update(['spend' => 999]);

        $this->withSession($adminSession)
            ->getJson('/informes/campanas/data/summary?start_date=2026-05-01&end_date=2026-05-31')
            ->assertOk()
            ->assertJsonPath('kpis.spend', 100)
            ->assertJsonPath('investment_closure.investment_status', 'closed');

        $directorSession = $this->authenticatedSession(ReportUser::ROLE_DIRECTOR);
        $this->withSession($directorSession)
            ->postJson('/informes/campanas/investment-closure/reopen', ['month' => '2026-05-01', 'reason' => 'Correccion de datos de inversion'])
            ->assertForbidden();

        $this->withSession($adminSession)
            ->postJson('/informes/campanas/investment-closure/reopen', ['month' => '2026-05-01', 'reason' => 'Correccion de datos de inversion'])
            ->assertOk()
            ->assertJsonPath('investment_status', 'open');

        $this->assertDatabaseCount('campaign_investment_closure_snapshots', 1);
        $this->assertDatabaseCount('campaign_investment_closure_events', 2);
    }

    public function test_google_ads_query_usa_campaign_para_incluir_performance_max(): void
    {
        config()->set('services.google_ads.developer_token', 'dev-token');
        config()->set('services.google_ads.client_id', 'client-id');
        config()->set('services.google_ads.client_secret', 'client-secret');
        config()->set('services.google_ads.refresh_token', 'refresh-token');
        config()->set('services.google_ads.customer_ids', ['1234567890']);

        $capturedQuery = null;

        Http::fake(function ($request) use (&$capturedQuery) {
            if (str_contains($request->url(), 'oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'access-token'], 200);
            }

            $capturedQuery = $request->data()['query'] ?? null;

            return Http::response([['results' => []]], 200);
        });

        app(GoogleAdsClient::class)->searchDailyMetrics(
            '1234567890',
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-05-31'),
        );

        $this->assertStringContainsString('FROM campaign', $capturedQuery);
        $this->assertStringNotContainsString('FROM ad_group', $capturedQuery);
        $this->assertStringContainsString('campaign.advertising_channel_type', $capturedQuery);
        $this->assertStringContainsString('metrics.cost_micros', $capturedQuery);
        $this->assertStringNotContainsString('advertising_channel_type IN', $capturedQuery);
    }

    public function test_una_campana_sugerida_por_nombre_solo_sale_del_kpi_tras_clasificacion_explicita(): void
    {
        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'campaign_id' => 'campaign-test-explicit',
            'campaign_name' => 'promotion-prueba',
            'spend' => 250,
        ]));

        $before = $this->getJson('/informes/campanas/data/summary?'.$this->campaignQuery().'&campaign_status=')
            ->assertOk()
            ->json();
        $this->assertSame(250, $before['kpis']['spend']);
        $this->assertTrue($before['campaigns'][0]['test_name_candidate']);
        $this->assertSame('pending_review', $before['campaigns'][0]['operational_classification']);

        config()->set('services.informes_auth.enabled', true);
        $admin = ReportUser::query()->create([
            'name' => 'IT Auditor',
            'email' => 'it-auditor@example.test',
            'password' => 'secret-password',
            'role' => ReportUser::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $session = [
            'informes_authenticated' => true,
            'report_user_id' => $admin->id,
            'report_user_role' => ReportUser::ROLE_ADMIN,
            'report_user_email' => $admin->email,
        ];

        $this->withSession($session)->postJson('/informes/campanas/classifications', [
            'platform' => 'meta',
            'account_id' => 'act_1',
            'campaign_id' => 'campaign-test-explicit',
            'classification' => 'test',
            'reason' => 'Campana de prueba confirmada por Marketing.',
        ])->assertOk();

        $after = $this->withSession($session)
            ->getJson('/informes/campanas/data/summary?'.$this->campaignQuery().'&campaign_status=')
            ->assertOk()
            ->json();
        $this->assertSame(0, $after['kpis']['spend']);
        $this->assertCount(1, $after['campaign_universes']['test']);
        $this->assertSame($admin->id, DB::table('campaign_operational_classifications')->value('classified_by'));
    }

    public function test_google_ads_inventario_auditable_consulta_anuncio_y_ad_group(): void
    {
        config()->set('services.google_ads.developer_token', 'dev-token');
        config()->set('services.google_ads.client_id', 'client-id');
        config()->set('services.google_ads.client_secret', 'client-secret');
        config()->set('services.google_ads.refresh_token', 'refresh-token');
        config()->set('services.google_ads.customer_ids', ['1234567890']);

        $capturedQuery = null;
        Http::fake(function ($request) use (&$capturedQuery) {
            if (str_contains($request->url(), 'oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'access-token'], 200);
            }

            $capturedQuery = $request->data()['query'] ?? null;

            return Http::response([['results' => []]], 200);
        });

        app(GoogleAdsClient::class)->searchAttributionIdentifiers('1234567890');

        $this->assertStringContainsString('FROM ad_group_ad', $capturedQuery);
        $this->assertStringContainsString('ad_group.id', $capturedQuery);
        $this->assertStringContainsString('ad_group_ad.ad.id', $capturedQuery);
        $this->assertStringContainsString('campaign.id', $capturedQuery);
    }

    public function test_venta_y_tasacion_separan_inversion_impresiones_y_clicks(): void
    {
        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'platform' => 'google_ads',
            'metric_date' => '2026-05-10',
            'campaign_id' => 'venta-1',
            'campaign_name' => 'VENTAS 1',
            'campaign_status' => 'ENABLED',
            'spend' => 100,
            'impressions' => 1000,
            'clicks' => 100,
        ]));

        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'platform' => 'google_ads',
            'metric_date' => '2026-05-10',
            'campaign_id' => 'tasacion-1',
            'campaign_name' => 'TASADOR LANDING SEARCH 1',
            'campaign_status' => 'ENABLED',
            'spend' => 25,
            'impressions' => 250,
            'clicks' => 25,
        ]));

        $baseQuery = 'start_date=2026-05-01&end_date=2026-05-31&campaign_status=active';

        $venta = $this->getJson('/informes/campanas/data/summary?'.$baseQuery.'&context=venta')
            ->assertOk()
            ->json('kpis');
        $tasacion = $this->getJson('/informes/campanas/data/summary?'.$baseQuery.'&context=tasacion')
            ->assertOk()
            ->json('kpis');

        $this->assertSame(100, (int) $venta['spend']);
        $this->assertSame(1000, (int) $venta['impressions']);
        $this->assertSame(100, (int) $venta['clicks']);
        $this->assertSame(25, (int) $tasacion['spend']);
        $this->assertSame(250, (int) $tasacion['impressions']);
        $this->assertSame(25, (int) $tasacion['clicks']);

        $all = $this->getJson('/informes/campanas/data/summary?'.$baseQuery.'&context=all')
            ->assertOk()
            ->json('kpis');

        $this->assertSame(125, (int) $all['spend']);
        $this->assertSame(1250, (int) $all['impressions']);
        $this->assertSame(125, (int) $all['clicks']);

        $ventaRows = $this->getJson('/informes/campanas/data/campaigns?'.$baseQuery.'&context=venta')
            ->assertOk()
            ->json('items');
        $tasacionRows = $this->getJson('/informes/campanas/data/campaigns?'.$baseQuery.'&context=tasacion')
            ->assertOk()
            ->json('items');

        $this->assertSame(['VENTAS 1'], array_column($ventaRows, 'campaign_name'));
        $this->assertSame(['TASADOR LANDING SEARCH 1'], array_column($tasacionRows, 'campaign_name'));
    }

    public function test_venta_agrupa_todas_las_subcategorias_y_ventas_filtra_solo_venta(): void
    {
        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'campaign_id' => 'venta-real',
            'campaign_name' => 'Venta real',
            'spend' => 100,
        ]));

        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'campaign_id' => 'expo-real',
            'campaign_name' => 'Exposicion real',
            'spend' => 50,
        ]));

        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'campaign_id' => 'branding-real',
            'campaign_name' => 'Branding real',
            'spend' => 20,
        ]));

        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'campaign_id' => 'otros-real',
            'campaign_name' => 'Otros real',
            'spend' => 10,
        ]));

        DB::table('campaign_lead_attributions')->insert([
            $this->attributionRow([
                'interest_id' => '00Q-venta-real',
                'campaign_id' => 'venta-real',
                'campaign_name' => 'Venta real',
                'source_campaign_name' => 'Venta real',
                'campaign_type' => 'venta',
            ]),
            $this->attributionRow([
                'interest_id' => '00Q-expo-real',
                'campaign_id' => 'expo-real',
                'campaign_name' => 'Exposicion real',
                'source_campaign_name' => 'Exposicion real',
                'campaign_type' => 'exposicion',
            ]),
            $this->attributionRow([
                'interest_id' => '00Q-branding-real',
                'campaign_id' => 'branding-real',
                'campaign_name' => 'Branding real',
                'source_campaign_name' => 'Branding real',
                'campaign_type' => 'branding',
            ]),
            $this->attributionRow([
                'interest_id' => '00Q-otros-real',
                'campaign_id' => 'otros-real',
                'campaign_name' => 'Otros real',
                'source_campaign_name' => 'Otros real',
                'campaign_type' => 'otros',
            ]),
        ]);

        $baseQuery = 'start_date=2026-05-01&end_date=2026-05-31&campaign_status=active';

        $venta = $this->getJson('/informes/campanas/data/summary?'.$baseQuery.'&context=venta')
            ->assertOk()
            ->json('kpis');
        $ventas = $this->getJson('/informes/campanas/data/summary?'.$baseQuery.'&context=ventas')
            ->assertOk()
            ->json('kpis');

        $this->assertSame(180, (int) $venta['spend']);
        $this->assertSame(100, (int) $ventas['spend']);

        $ventaRows = $this->getJson('/informes/campanas/data/campaigns?'.$baseQuery.'&context=venta')
            ->assertOk()
            ->json('items');
        $ventasRows = $this->getJson('/informes/campanas/data/campaigns?'.$baseQuery.'&context=ventas')
            ->assertOk()
            ->json('items');

        $this->assertEqualsCanonicalizing(
            ['Venta real', 'Exposicion real', 'Branding real', 'Otros real'],
            array_column($ventaRows, 'campaign_name')
        );
        $this->assertSame(['Venta real'], array_column($ventasRows, 'campaign_name'));
    }

    public function test_atribucion_y_kpis_agregan_campaigns_sin_datos_personales(): void
    {
        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'platform' => 'meta',
            'metric_date' => '2026-05-10',
            'account_id' => 'act_1',
            'campaign_id' => 'camp-1',
            'campaign_name' => 'Spring Sale',
            'ad_id' => 'ad-1',
            'spend' => 200,
            'impressions' => 1000,
            'clicks' => 100,
            'platform_leads' => 8,
        ]));

        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-1',
            'name' => 'Lead Privado',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Convertido',
            'record_type_name' => 'Venta',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'persona_que_trabajo_id' => '005-real',
            'persona_que_trabajo_name' => 'Comercial Real',
            'fuente_origen' => 'Meta',
            'medio_origen' => 'Paid Social',
            'campaign_acquired' => 'Spring Sale',
            'acquired_id' => 'ad-1',
            'content_acquired' => 'creative-1',
            'vehicle_interest' => 'Vehiculo A',
            'phone' => '600 000 001',
            'email' => 'cliente@example.com',
            'is_converted' => true,
            'converted_opportunity_id' => '006-1',
            'portal_text' => 'Meta',
            'medio_nuevo' => 'Formulario',
            'delegacion_encargada_text' => 'Alcobendas',
        ]);

        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-invalid',
            'name' => 'Lead Sin Campana',
            'created_date' => '2026-05-10 11:00:00',
            'status' => 'Potencial',
            'record_type_name' => 'Venta',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'campaign_acquired' => 'none',
            'portal_text' => 'Meta',
            'medio_nuevo' => 'Formulario',
            'delegacion_encargada_text' => 'Alcobendas',
        ]);

        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-1',
            'name' => 'Oportunidad 1',
            'created_date' => '2026-05-11 10:00:00',
            'record_type_name' => 'Venta',
            'stage_name' => 'Contrato',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'owner_delegation' => 'Alcobendas',
            'account_phone' => '+34 600 000 001',
            'account_person_email' => 'cliente@example.com',
            'portal_resolved' => 'Meta',
            'portal_resolution_source' => 'lead',
            'portal_resolution_lead_id' => '00Q-1',
            'reservation' => true,
            'reservation_date' => '2026-05-12',
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-15',
            'amount' => 12000,
        ]);

        app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01')
        );

        $this->assertSame(1, CampaignAttribution::query()->count());
        $this->assertDatabaseHas('campaign_attributions', [
            'interest_id' => '00Q-1',
            'opportunity_id' => '006-1',
            'campaign_id' => 'camp-1',
            'campaign_name' => 'Spring Sale',
            'attribution_method' => 'ad_id_match',
            'attribution_confidence' => 'high',
            'match_status' => 'Cruzada por ID',
            'campaign_source_type' => 'platform_campaign',
            'has_opportunity' => true,
            'has_reservation' => true,
            'has_sale' => true,
            'sale_amount' => 12000,
        ]);
        $this->assertDatabaseHas('campaign_lead_attributions', [
            'interest_id' => '00Q-1',
            'campaign_id' => 'camp-1',
            'attribution_method' => 'ad_id_match',
            'attribution_confidence' => 'high',
            'match_status' => 'Cruzada por ID',
            'campaign_source_type' => 'platform_campaign',
        ]);

        $query = $this->campaignQuery();

        $summary = $this->getJson('/informes/campanas/data/summary?'.$query)
            ->assertOk()
            ->assertJsonPath('kpis.leads_salesforce', 1)
            ->assertJsonPath('kpis.platform_leads', 8)
            ->assertJsonPath('kpis.opportunities', 1)
            ->assertJsonPath('kpis.reservations', 1)
            ->assertJsonPath('kpis.sales', 1)
            ->assertJsonPath('kpis.sale_amount', 12000)
            ->assertJsonPath('kpis.roas', 60)
            ->assertJsonPath('kpis.estimated_roi', 59)
            ->assertJsonStructure([
                'charts' => ['daily_evolution', 'funnel', 'platforms'],
                'daily_investment_leads',
                'daily_results',
                'platform_comparison',
                'review_campaigns',
                'diagnostics' => [
                    'platform_campaigns',
                    'salesforce_origins',
                    'crossed_campaigns',
                    'salesforce_only_by_campaign',
                    'salesforce_only_by_origin',
                ],
            ])
            ->json('kpis');
        $this->assertEquals(200.0, $summary['spend']);

        $campaign = $this->getJson('/informes/campanas/data/campaigns?'.$query)
            ->assertOk()
            ->assertJsonPath('items.0.campaign_name', 'Spring Sale')
            ->assertJsonPath('items.0.match_status', 'Cruzada por ID')
            ->assertJsonPath('items.0.campaign_source_type', 'platform_campaign')
            ->assertJsonPath('items.0.leads_salesforce', 1)
            ->json('items.0');
        $this->assertEquals(200.0, $campaign['cost_per_lead']);

        $csv = $this->get('/informes/campanas/export/campaigns.csv?'.$query)
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Spring Sale', $csv);
        $this->assertStringContainsString('ID adquirido', $csv);
        $this->assertStringNotContainsString('Tipo', $csv);
        $this->assertStringNotContainsString('Estado de cruce', $csv);
        $this->assertStringNotContainsString('Campana plataforma', $csv);
        $this->assertStringNotContainsString('cliente@example.com', $csv);
        $this->assertStringNotContainsString('600 000 001', $csv);
        $this->assertStringNotContainsString('Lead Privado', $csv);
    }

    public function test_tasacion_usa_both_match_y_first_touch_account_sin_pii(): void
    {
        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'platform' => 'google_ads',
            'metric_date' => '2026-05-10',
            'account_id' => 'ads-1',
            'campaign_id' => 'tasacion-search-1',
            'campaign_name' => 'TASADOR LANDING SEARCH 1',
            'spend' => 100,
            'impressions' => 1000,
            'clicks' => 100,
        ]));

        $leadRows = [
            [
                'salesforce_id' => '00Q-tasacion-direct',
                'name' => 'Lead Tasacion Directo',
                'phone' => '600000001',
                'email' => 'directo@example.com',
                'converted_opportunity_id' => '006-tasacion-direct',
            ],
            [
                'salesforce_id' => '00Q-tasacion-account',
                'name' => 'Lead Tasacion Cuenta',
                'phone' => '600000002',
                'email' => 'cuenta@example.com',
                'converted_account_id' => '001-tasacion-account',
            ],
            [
                'salesforce_id' => '00Q-tasacion-contact',
                'name' => 'Lead Tasacion Contacto',
                'phone' => '600 000 003',
                'email' => 'contacto@example.com',
            ],
            [
                'salesforce_id' => '00Q-tasacion-name',
                'name' => 'Lead Tasacion Nombre',
                'phone' => '+34 600 000 004',
                'email' => 'nombre@example.com',
            ],
        ];

        foreach ($leadRows as $index => $overrides) {
            SalesforceLead::query()->create(array_merge([
                'created_date' => '2026-05-10 '.sprintf('%02d', 10 + $index).':00:00',
                'status' => 'Convertido',
                'record_type_name' => 'Tasación',
                'owner_id' => '005-real',
                'owner_name' => 'Comercial Real',
                'campaign_acquired' => 'TASADOR LANDING SEARCH 1',
                'fuente_origen' => 'Google Ads',
                'medio_origen' => 'CPC',
                'portal_text' => 'Google Ads',
                'medio_nuevo' => 'Formulario',
                'delegacion_encargada_text' => 'Alcobendas',
            ], $overrides));
        }

        $opportunityRows = [
            [
                'salesforce_id' => '006-tasacion-direct',
                'name' => 'Contrato directo',
                'account_id' => '001-direct',
                'account_phone' => '600000001',
                'account_person_email' => 'directo@example.com',
            ],
            [
                'salesforce_id' => '006-tasacion-account',
                'name' => 'Contrato por cuenta convertida',
                'account_id' => '001-tasacion-account',
                'account_phone' => '699999999',
                'account_person_email' => 'otra-persona@example.com',
            ],
            [
                'salesforce_id' => '006-tasacion-contact',
                'name' => 'Contrato por contacto de cuenta',
                'account_id' => '001-contact',
                'account_phone' => '+34 600 000 003',
                'account_company_email' => 'contacto@example.com',
            ],
            [
                'salesforce_id' => '006-tasacion-name',
                'name' => 'Nueva Oportunidad de : Cliente 600000004 nombre@example.com',
                'account_id' => '001-name',
                'account_phone' => null,
                'account_person_email' => null,
                'account_company_email' => null,
            ],
        ];

        foreach ($opportunityRows as $index => $overrides) {
            SalesforceOpportunity::query()->create(array_merge([
                'created_date' => '2026-05-11 '.sprintf('%02d', 10 + $index).':00:00',
                'record_type_name' => 'Tasación',
                'stage_name' => 'Generar contrato',
                'reservation' => false,
                'cv_signed' => true,
                'cv_signed_date' => '2026-05-15',
                'opo_for_importe_total' => -10000,
            ], $overrides));
        }

        app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01')
        );

        $this->assertSame(2, DB::table('campaign_lead_attributions')->where('has_purchase', true)->count());

        $this->assertDatabaseHas('campaign_attributions', [
            'interest_id' => '00Q-tasacion-direct',
            'opportunity_id' => '006-tasacion-direct',
            'opportunity_attribution_method' => 'both_match',
        ]);
        $this->assertDatabaseHas('campaign_attributions', [
            'interest_id' => '00Q-tasacion-account',
            'opportunity_id' => '006-tasacion-account',
            'opportunity_attribution_method' => 'account_first_touch',
        ]);
        $this->assertDatabaseMissing('campaign_attributions', [
            'interest_id' => '00Q-tasacion-contact',
            'opportunity_id' => '006-tasacion-contact',
        ]);
        $this->assertDatabaseMissing('campaign_attributions', [
            'interest_id' => '00Q-tasacion-name',
            'opportunity_id' => '006-tasacion-name',
        ]);

        $this->getJson('/informes/campanas/data/summary?'.$this->campaignQuery().'&context=tasacion')
            ->assertOk()
            ->assertJsonPath('kpis.purchases', 2);
    }

    public function test_tasacion_cuenta_varias_oportunidades_de_la_misma_cuenta_sin_duplicar_leads(): void
    {
        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'platform' => 'google_ads',
            'metric_date' => '2026-05-10',
            'account_id' => 'ads-1',
            'campaign_id' => 'tasacion-search-multi',
            'campaign_name' => 'TASADOR LANDING SEARCH 1',
            'spend' => 120,
            'impressions' => 1200,
            'clicks' => 120,
        ]));

        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-tasacion-multi',
            'name' => 'Lead Tasacion Multiple',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Convertido',
            'record_type_name' => 'Tasacion',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'campaign_acquired' => 'TASADOR LANDING SEARCH 1',
            'fuente_origen' => 'Google Ads',
            'medio_origen' => 'CPC',
            'email' => 'multi@example.com',
            'phone' => '600000111',
            'converted_account_id' => '001-tasacion-multi',
        ]);

        foreach ([
            '006-tasacion-multi-1',
            '006-tasacion-multi-2',
        ] as $index => $salesforceId) {
            SalesforceOpportunity::query()->create([
                'salesforce_id' => $salesforceId,
                'name' => 'Contrato tasacion multiple '.($index + 1),
                'created_date' => '2026-05-1'.($index + 1).' 10:00:00',
                'record_type_name' => 'Tasacion',
                'stage_name' => 'Generar contrato',
                'account_id' => '001-tasacion-multi',
                'reservation' => false,
                'cv_signed' => true,
                'cv_signed_date' => '2026-05-20',
                'opo_for_importe_total' => -5000,
            ]);
        }

        app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01')
        );

        $this->assertSame(2, DB::table('campaign_lead_attributions')->where('interest_id', '00Q-tasacion-multi')->count());
        $this->assertSame(2, DB::table('campaign_lead_attributions')->where('interest_id', '00Q-tasacion-multi')->where('has_purchase', true)->count());

        $this->getJson('/informes/campanas/data/summary?'.$this->campaignQuery().'&context=tasacion')
            ->assertOk()
            ->assertJsonPath('kpis.leads_salesforce', 1)
            ->assertJsonPath('kpis.opportunities', 2)
            ->assertJsonPath('kpis.purchases', 2);
    }

    public function test_meta_instantforms_se_agrupa_como_formulario_directo_meta_y_cuenta_como_venta(): void
    {
        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'platform' => 'meta',
            'metric_date' => '2026-05-10',
            'campaign_id' => 'meta-if-1',
            'campaign_name' => 'Prospeccion InstantForms Mayo',
            'spend' => 200,
            'impressions' => 1000,
            'clicks' => 100,
        ]));

        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'platform' => 'meta',
            'metric_date' => '2026-05-11',
            'campaign_id' => 'meta-if-2',
            'campaign_name' => 'Remarketing InstantForms Mayo',
            'spend' => 300,
            'impressions' => 1200,
            'clicks' => 90,
        ]));

        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-meta-direct-1',
            'name' => 'Lead Meta Directo 1',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Potencial',
            'record_type_name' => 'Venta',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'fuente_origen' => 'Facebook',
            'portal_text' => 'Meta',
            'campaign_acquired' => null,
        ]);

        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-meta-direct-2',
            'name' => 'Lead Meta Directo 2',
            'created_date' => '2026-05-11 10:00:00',
            'status' => 'Potencial',
            'record_type_name' => 'Venta',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'fuente_origen' => 'Facebook',
            'portal_text' => 'Meta',
            'campaign_acquired' => 'Prospeccion InstantForms Mayo',
        ]);

        app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01')
        );

        $this->assertDatabaseMissing('campaign_lead_attributions', ['interest_id' => '00Q-meta-direct-1']);
        $this->assertDatabaseHas('campaign_lead_attributions', [
            'interest_id' => '00Q-meta-direct-2',
            'campaign_name' => 'Formulario Directo Meta',
            'campaign_type' => 'venta',
            'campaign_acquired' => 'Formulario Directo Meta',
        ]);

        $campaigns = $this->getJson('/informes/campanas/data/campaigns?'.$this->campaignQuery().'&context=venta')
            ->assertOk()
            ->json('items');

        $row = collect($campaigns)->firstWhere('campaign_name', 'Formulario Directo Meta');

        $this->assertNotNull($row);
        $this->assertSame('meta', $row['platform']);
        $this->assertEquals(500.0, $row['spend']);
        $this->assertSame(1, $row['leads_salesforce']);
    }

    public function test_campana_tasacion_no_contabiliza_ventas_aunque_la_oportunidad_sea_de_venta(): void
    {
        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'platform' => 'google_ads',
            'metric_date' => '2026-05-10',
            'campaign_id' => 'tasacion-no-sale',
            'campaign_name' => 'TASADOR LANDING SEARCH 1',
            'spend' => 100,
            'impressions' => 1000,
            'clicks' => 100,
        ]));

        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-tasacion-no-sale',
            'name' => 'Lead Tasacion Sin Venta',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Convertido',
            'record_type_name' => 'Tasacion',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'fuente_origen' => 'Google Ads',
            'medio_origen' => 'CPC',
            'campaign_acquired' => 'TASADOR LANDING SEARCH 1',
            'converted_opportunity_id' => '006-tasacion-sale',
        ]);

        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-tasacion-sale',
            'name' => 'Oportunidad Venta Cruzada',
            'created_date' => '2026-05-11 10:00:00',
            'record_type_name' => 'Venta',
            'stage_name' => 'Contrato',
            'reservation' => true,
            'reservation_date' => '2026-05-12',
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-15',
            'opo_for_importe_total' => 15000,
        ]);

        app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01')
        );

        $this->assertDatabaseHas('campaign_lead_attributions', [
            'interest_id' => '00Q-tasacion-no-sale',
            'campaign_type' => 'tasacion',
            'has_sale' => false,
            'has_purchase' => false,
            'sold_amount' => null,
        ]);

        $this->getJson('/informes/campanas/data/summary?'.$this->campaignQuery().'&context=tasacion')
            ->assertOk()
            ->assertJsonPath('kpis.opportunities', 1)
            ->assertJsonPath('kpis.sales', 0)
            ->assertJsonPath('kpis.purchases', 0);
    }

    public function test_salesforce_campaign_without_platform_entra_en_kpis_y_tabla_como_campaigna_valida(): void
    {
        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-sf-only',
            'name' => 'Lead Salesforce',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Potencial',
            'record_type_name' => 'Venta',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'fuente_origen' => 'Google',
            'medio_origen' => 'cpc',
            'campaign_acquired' => 'Campana solo Salesforce',
            'portal_text' => 'Google',
            'medio_nuevo' => 'Formulario',
            'delegacion_encargada_text' => 'Alcobendas',
        ]);

        app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01')
        );

        $this->assertDatabaseHas('campaign_attributions', [
            'interest_id' => '00Q-sf-only',
            'platform' => 'salesforce',
            'campaign_name' => 'Campana solo Salesforce',
            'attribution_method' => 'salesforce_only',
            'match_status' => 'Sin inversion asociada',
            'campaign_source_type' => 'salesforce_campaign_without_spend',
        ]);
        $this->assertDatabaseHas('campaign_lead_attributions', [
            'interest_id' => '00Q-sf-only',
            'campaign_name' => 'Campana solo Salesforce',
            'source_campaign_name' => 'Campana solo Salesforce',
            'campaign_type' => 'venta',
        ]);

        $this->getJson('/informes/campanas/data/campaigns?'.$this->campaignQuery())
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.campaign_name', 'Campana solo Salesforce')
            ->assertJsonPath('items.0.platform', 'salesforce');

        $this->getJson('/informes/campanas/data/summary?'.$this->campaignQuery())
            ->assertOk()
            ->assertJsonPath('kpis.leads_salesforce', 1)
            ->assertJsonPath('source_reconciliation.leads.platform', 0)
            ->assertJsonPath('source_reconciliation.leads.salesforce_only', 1)
            ->assertJsonPath('source_reconciliation.leads.total_distinct', 1)
            ->assertJsonPath('diagnostics.salesforce_only_by_campaign', 1);
    }

    public function test_salesforce_only_omits_commercial_and_economic_metrics(): void
    {
        foreach (range(1, 10) as $index) {
            $opportunity = $index <= 4 ? '006-sf-only-'.$index : null;
            DB::table('campaign_lead_attributions')->insert($this->attributionRow([
                'interest_id' => '00Q-sf-only-'.$index,
                'platform' => 'salesforce',
                'campaign_id' => 'sf-only-campaign',
                'campaign_name' => 'Campaign Salesforce only',
                'source_campaign_name' => 'Campaign Salesforce only',
                'opportunity_id' => $opportunity,
                'has_opportunity' => $opportunity !== null,
                'has_reservation' => $index <= 3,
                'has_sale' => $index <= 2,
                'has_purchase' => $index === 1,
                'sold_amount' => $index <= 2 ? 1000 : null,
            ]));
        }

        $item = $this->getJson('/informes/campanas/data/campaigns?'.$this->campaignQuery().'&campaign_status=')
            ->assertOk()
            ->json('items.0');

        $this->assertSame(10, $item['leads_salesforce']);
        $this->assertSame(4, $item['opportunities']);
        $this->assertSame(0.4, $item['lead_to_opportunity']);
        foreach (['reservations', 'live_reservations', 'fallen_reservations', 'sales', 'purchases', 'sale_amount', 'appraisal_amount', 'spend', 'cpc', 'cost_per_lead', 'cost_per_opportunity', 'cost_per_reservation', 'cost_per_sale', 'cost_per_purchase', 'roas', 'estimated_roi'] as $key) {
            $this->assertNull($item[$key], $key);
        }

        $this->getJson('/informes/campanas/data/summary?'.$this->campaignQuery().'&campaign_status=')
            ->assertOk()
            ->assertJsonPath('kpis.leads_salesforce', 10)
            ->assertJsonPath('kpis.opportunities', 4)
            ->assertJsonPath('kpis.reservations', 0)
            ->assertJsonPath('kpis.sales', 0)
            ->assertJsonPath('kpis.purchases', 0)
            ->assertJsonPath('kpis.sale_amount', null)
            ->assertJsonPath('kpis.appraisal_amount', null);
    }

    public function test_tasador_generico_queda_excluido_de_metricas_de_campanas(): void
    {
        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-tasador-manual',
            'name' => 'Lead Tasador Manual',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Potencial',
            'record_type_name' => 'Tasación',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'fuente_origen' => 'Google Ads',
            'medio_origen' => 'cpc',
            'campaign_acquired' => 'tasador',
            'portal_text' => 'Google Ads',
            'medio_nuevo' => 'Formulario',
            'delegacion_encargada_text' => 'Alcobendas',
        ]);

        app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01')
        );

        $this->assertDatabaseHas('campaign_lead_attributions', [
            'interest_id' => '00Q-tasador-manual',
            'match_status' => 'excluded_campaign_tasador',
        ]);

        $this->getJson('/informes/campanas/data/summary?'.$this->campaignQuery().'&context=tasacion')
            ->assertOk()
            ->assertJsonPath('kpis.leads_salesforce', 0)
            ->assertJsonPath('kpis.spend', 0);

        $this->getJson('/informes/campanas/data/campaigns?'.$this->campaignQuery().'&context=tasacion')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('items', []);
    }

    public function test_platform_spend_without_salesforce_leads_is_review_tracking(): void
    {
        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'platform' => 'google_ads',
            'metric_date' => '2026-05-10',
            'account_id' => '123',
            'campaign_id' => 'camp-tracking',
            'campaign_name' => 'Tracking roto',
            'spend' => 900,
            'impressions' => 1000,
            'clicks' => 100,
        ]));

        $this->getJson('/informes/campanas/data/campaigns?'.$this->campaignQuery())
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.classification', 'Revisar tracking')
            ->assertJsonPath('items.0.match_status', 'Sin Interests Salesforce')
            ->assertJsonPath('items.0.campaign_source_type', 'platform_campaign')
            ->assertJsonPath('items.0.leads_salesforce', 0);

        $this->getJson('/informes/campanas/data/summary?'.$this->campaignQuery())
            ->assertOk()
            ->assertJsonPath('kpis.spend', 900)
            ->assertJsonPath('kpis.leads_salesforce', 0);
    }

    public function test_performance_max_y_removed_aparecen_en_la_sincronizacion_y_en_el_resumen(): void
    {
        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'platform' => 'google_ads',
            'metric_date' => '2026-05-10',
            'campaign_id' => 'camp-pmax-removed',
            'campaign_name' => 'PMAX Legacy',
            'campaign_status' => 'REMOVED',
            'advertising_channel_type' => 'PERFORMANCE_MAX',
            'spend' => 77,
            'impressions' => 777,
            'clicks' => 77,
        ]));

        $summary = $this->getJson('/informes/campanas/data/summary?start_date=2026-05-01&end_date=2026-05-31&campaign_status=inactive&context=all')
            ->assertOk()
            ->json('kpis');

        $this->assertSame(77, (int) $summary['spend']);
        $this->assertSame(777, (int) $summary['impressions']);
        $this->assertSame(77, (int) $summary['clicks']);

        $items = $this->getJson('/informes/campanas/data/campaigns?start_date=2026-05-01&end_date=2026-05-31&campaign_status=inactive&context=all')
            ->assertOk()
            ->json('items');

        $this->assertSame('PMAX Legacy', $items[0]['campaign_name']);
        $this->assertSame('PERFORMANCE_MAX', $items[0]['advertising_channel_type']);
    }

    public function test_ratios_devuelven_null_cuando_el_denominador_es_cero(): void
    {
        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'platform' => 'google_ads',
            'metric_date' => '2026-05-10',
            'account_id' => '123',
            'campaign_id' => 'camp-zero',
            'campaign_name' => 'Zero Clicks',
            'spend' => 30,
            'impressions' => 0,
            'clicks' => 0,
        ]));

        $this->getJson('/informes/campanas/data/campaigns?'.$this->campaignQuery())
            ->assertOk()
            ->assertJsonPath('items.0.ctr', null)
            ->assertJsonPath('items.0.cpc', null)
            ->assertJsonPath('items.0.cost_per_lead', null);
    }

    public function test_venta_posterior_cuenta_si_el_lead_nacio_en_el_periodo_y_esta_en_ventana(): void
    {
        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'platform' => 'meta',
            'metric_date' => '2026-05-10',
            'campaign_id' => 'camp-lead-pivot',
            'campaign_name' => 'Lead Pivot',
            'spend' => 100,
            'impressions' => 1000,
            'clicks' => 100,
        ]));

        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-lead-pivot',
            'name' => 'Lead Pivot',
            'created_date' => '2026-05-20 10:00:00',
            'status' => 'Convertido',
            'record_type_name' => 'Venta',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'fuente_origen' => 'Meta',
            'medio_origen' => 'Paid Social',
            'campaign_acquired' => 'Lead Pivot',
            'acquired_id' => 'camp-lead-pivot',
            'content_acquired' => null,
            'phone' => '600000001',
            'email' => 'leadpivot@example.com',
            'is_converted' => true,
            'converted_opportunity_id' => '006-lead-pivot',
            'portal_text' => 'Meta',
            'medio_nuevo' => 'Formulario',
            'delegacion_encargada_text' => 'Alcobendas',
        ]);

        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-lead-pivot',
            'name' => 'Oportunidad Lead Pivot',
            'created_date' => '2026-06-01 10:00:00',
            'record_type_name' => 'Venta',
            'stage_name' => 'Contrato',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'owner_delegation' => 'Alcobendas',
            'account_phone' => '+34 600000001',
            'account_person_email' => 'leadpivot@example.com',
            'portal_resolved' => 'Meta',
            'portal_resolution_source' => 'lead',
            'portal_resolution_lead_id' => '00Q-lead-pivot',
            'reservation' => true,
            'reservation_date' => '2026-06-02',
            'cv_signed' => true,
            'cv_signed_date' => '2026-06-05',
            'amount' => 0,
            'opo_for_importe_total' => 12000,
        ]);

        CampaignAttribution::query()->create([
            'interest_id' => '00Q-lead-pivot',
            'opportunity_id' => '006-lead-pivot',
            'platform' => 'meta',
            'account_id' => 'act_1',
            'campaign_id' => 'camp-lead-pivot',
            'campaign_name' => 'Lead Pivot',
            'campaign_name_key' => 'leadpivot',
            'interest_functional_created_at' => '2026-05-20 10:00:00',
            'opportunity_created_at' => '2026-06-01 10:00:00',
            'reservation_date' => '2026-06-02',
            'sale_date' => '2026-06-05',
            'sale_amount' => 12000,
            'has_opportunity' => true,
            'has_reservation' => true,
            'has_sale' => true,
            'attribution_method' => 'campaign_id_match',
            'attribution_confidence' => 'high',
            'match_status' => 'Cruzada por ID',
            'campaign_source_type' => 'platform_campaign',
        ]);
        DB::table('campaign_lead_attributions')->insert([
            'interest_id' => '00Q-lead-pivot',
            'interest_functional_created_at' => '2026-05-20 10:00:00',
            'interest_is_deleted' => false,
            'campaign_name' => 'Lead Pivot',
            'campaign_id' => 'camp-lead-pivot',
            'platform' => 'meta',
            'campaign_type' => 'venta',
            'opportunity_id' => '006-lead-pivot',
            'has_opportunity' => true,
            'has_reservation' => true,
            'has_sale' => true,
            'has_purchase' => false,
            'sold_amount' => 12000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/informes/campanas/data/summary?'.$this->campaignQuery())
            ->assertOk()
            ->assertJsonPath('period_mode', 'interest_pivot')
            ->assertJsonPath('kpis.leads_salesforce', 1)
            ->assertJsonPath('kpis.opportunities', 1)
            ->assertJsonPath('kpis.reservations', 1)
            ->assertJsonPath('kpis.sales', 1)
            ->assertJsonPath('kpis.sale_amount', 12000);
    }

    public function test_venta_del_periodo_no_cuenta_si_el_lead_nacio_fuera_del_periodo(): void
    {
        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'platform' => 'meta',
            'metric_date' => '2026-05-10',
            'campaign_id' => 'camp-old-lead',
            'campaign_name' => 'Old Lead',
            'spend' => 100,
            'impressions' => 1000,
            'clicks' => 100,
        ]));

        CampaignAttribution::query()->create([
            'interest_id' => '00Q-old-lead',
            'opportunity_id' => '006-old-lead',
            'platform' => 'meta',
            'campaign_id' => 'camp-old-lead',
            'campaign_name' => 'Old Lead',
            'campaign_name_key' => 'oldlead',
            'interest_functional_created_at' => '2026-04-20 10:00:00',
            'opportunity_created_at' => '2026-05-01 10:00:00',
            'reservation_date' => '2026-05-02',
            'sale_date' => '2026-05-05',
            'sale_amount' => 12000,
            'has_opportunity' => true,
            'has_reservation' => true,
            'has_sale' => true,
            'attribution_method' => 'campaign_id_match',
            'attribution_confidence' => 'high',
            'match_status' => 'Cruzada por ID',
            'campaign_source_type' => 'platform_campaign',
        ]);
        DB::table('campaign_lead_attributions')->insert([
            'interest_id' => '00Q-old-lead',
            'interest_functional_created_at' => '2026-04-20 10:00:00',
            'interest_is_deleted' => false,
            'campaign_name' => 'Old Lead',
            'campaign_id' => 'camp-old-lead',
            'platform' => 'meta',
            'campaign_type' => 'venta',
            'opportunity_id' => '006-old-lead',
            'has_opportunity' => true,
            'has_reservation' => true,
            'has_sale' => true,
            'has_purchase' => false,
            'sold_amount' => 12000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/informes/campanas/data/summary?'.$this->campaignQuery())
            ->assertOk()
            ->assertJsonPath('kpis.leads_salesforce', 0)
            ->assertJsonPath('kpis.opportunities', 0)
            ->assertJsonPath('kpis.reservations', 0)
            ->assertJsonPath('kpis.sales', 0)
            ->assertJsonPath('kpis.sale_amount', null);
    }

    public function test_salesforce_source_medium_only_does_not_enter_the_campaign_universe(): void
    {
        SalesforceInterest::query()->create([
            'salesforce_id' => '00Q-origin',
            'functional_created_at' => '2026-05-10 10:00:00',
            'salesforce_created_at' => '2026-05-10 10:00:00',
            'salesforce_last_modified_at' => '2026-05-10 10:00:00',
            'status' => 'Potencial',
            'type' => 'Venta',
            'owner_salesforce_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'source' => 'Google Maps',
            'medium' => 'Llamada',
            'origin_delegation' => 'Alcobendas',
            'is_deleted' => false,
        ]);

        app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01')
        );

        $this->assertDatabaseMissing('campaign_lead_attributions', ['interest_id' => '00Q-origin']);

        $this->getJson('/informes/campanas/data/campaigns?'.$this->campaignQuery())
            ->assertOk()
            ->assertJsonPath('total', 0);

        $summary = $this->getJson('/informes/campanas/data/summary?'.$this->campaignQuery())
            ->assertOk()
            ->json();
        $this->assertSame(0, $summary['kpis']['leads_salesforce']);
        $this->assertSame(0, $summary['diagnostics']['salesforce_origins'], json_encode($summary['diagnostics']));
        $this->assertSame(0, $summary['diagnostics']['salesforce_only_by_origin'], json_encode($summary['diagnostics']));
    }

    public function test_rankings_and_charts_no_recuperan_procedencias_sin_campaign_acquired(): void
    {
        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'platform' => 'meta',
            'metric_date' => '2026-05-10',
            'campaign_id' => 'camp-platform',
            'campaign_name' => 'Meta Real',
            'spend' => 350,
            'impressions' => 900,
            'clicks' => 90,
        ]));

        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-origin',
            'name' => 'Lead Chatbot',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Potencial',
            'record_type_name' => 'Venta',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'fuente_origen' => 'Chatbot',
            'medio_origen' => 'CPC',
            'portal_text' => 'Chatbot',
            'medio_nuevo' => 'CPC',
            'delegacion_encargada_text' => 'Alcobendas',
        ]);

        app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01')
        );

        $rankings = $this->getJson('/informes/campanas/data/rankings?'.$this->campaignQuery())
            ->assertOk()
            ->json('rankings');
        $rankingJson = json_encode($rankings);

        $this->assertStringContainsString('Meta Real', $rankingJson);
        $this->assertStringNotContainsString('Chatbot', $rankingJson);
        $this->assertArrayNotHasKey('salesforce_origin', $rankings);
        $this->assertArrayNotHasKey('review_investment_tracking', $rankings);

        $summary = $this->getJson('/informes/campanas/data/summary?'.$this->campaignQuery())
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('rankings', $summary);
        $this->assertSame(900, $summary['charts']['funnel'][0]['value']);
        $this->assertSame(90, $summary['charts']['funnel'][1]['value']);
        $this->assertSame(0, $summary['diagnostics']['salesforce_origins']);
    }

    public function test_periodo_local_madrid_incluye_y_excluye_bordes_utc_en_leads_salesforce(): void
    {
        $rows = [
            ['interest_id' => '00Q-local-start-1', 'interest_functional_created_at' => '2026-04-30 23:03:10'],
            ['interest_id' => '00Q-local-start-2', 'interest_functional_created_at' => '2026-04-30 23:20:51'],
            ['interest_id' => '00Q-local-mid', 'interest_functional_created_at' => '2026-05-15 12:00:00'],
            ['interest_id' => '00Q-local-end-out', 'interest_functional_created_at' => '2026-05-31 22:15:41'],
        ];

        foreach ($rows as $row) {
            DB::table('campaign_lead_attributions')->insert([
                'interest_id' => $row['interest_id'],
                'interest_functional_created_at' => $row['interest_functional_created_at'],
                'interest_is_deleted' => false,
                'campaign_name' => 'Expiey_Leads_Geo_Tasación_Nuevas Ubicaciones',
                'campaign_id' => null,
                'platform' => 'salesforce',
                'source_campaign_name' => 'Expiey_Leads_Geo_Tasación_Nuevas Ubicaciones',
                'campaign_type' => 'tasacion',
                'opportunity_id' => null,
                'has_opportunity' => false,
                'has_reservation' => false,
                'has_sale' => false,
                'has_purchase' => false,
                'sold_amount' => null,
                'source_acquired' => 'Google Ads',
                'medium_acquired' => 'CPC',
                'campaign_acquired' => 'Expiey_Leads_Geo_Tasación_Nuevas Ubicaciones',
                'acquired_id' => null,
                'content_acquired' => null,
                'interest_status' => 'Potencial',
                'interest_origin_delegation' => 'Alcobendas',
                'interest_origin_zone' => 'Madrid',
                'commercial_user_id' => null,
                'commercial_user_name' => null,
                'vehicle_interest' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $summary = $this->getJson('/informes/campanas/data/summary?'.$this->campaignQuery().'&context=tasacion')
            ->assertOk()
            ->json();

        $this->assertSame(3, $summary['kpis']['leads_salesforce']);

        $mayMonth = collect($summary['charts']['monthly_evolution'])->firstWhere('date', '2026-05-01');
        $mayFirstDay = collect($summary['charts']['daily_evolution'])->firstWhere('date', '2026-05-01');
        $mayLastDay = collect($summary['charts']['daily_evolution'])->firstWhere('date', '2026-05-31');

        $this->assertSame(3, $mayMonth['leads_salesforce']);
        $this->assertSame(2, $mayFirstDay['leads_salesforce']);
        $this->assertSame(0, $mayLastDay['leads_salesforce']);
    }

    public function test_google_maps_chatbot_and_exposicion_do_not_appear_as_main_campaigns(): void
    {
        foreach ([
            ['00Q-maps', 'Google Maps', 'Llamada'],
            ['00Q-chatbot', 'Chatbot', 'CPC'],
            ['00Q-expo', 'Exposicion', ''],
        ] as [$id, $source, $medium]) {
            SalesforceLead::query()->create([
                'salesforce_id' => $id,
                'name' => 'Lead '.$source,
                'created_date' => '2026-05-10 10:00:00',
                'status' => 'Potencial',
                'record_type_name' => 'Venta',
                'owner_id' => '005-real',
                'owner_name' => 'Comercial Real',
                'fuente_origen' => $source,
                'medio_origen' => $medium,
                'portal_text' => $source,
                'medio_nuevo' => $medium,
                'delegacion_encargada_text' => 'Alcobendas',
            ]);
        }

        app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01')
        );

        $payload = $this->getJson('/informes/campanas/data/campaigns?'.$this->campaignQuery())
            ->assertOk()
            ->json();

        $this->assertSame(0, $payload['total']);
        $this->assertSame([], $payload['items']);
    }

    public function test_sale_amount_column_when_available_calculates_roas_and_roi(): void
    {
        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'platform' => 'meta',
            'metric_date' => '2026-05-10',
            'account_id' => 'act_1',
            'campaign_id' => 'camp-amount',
            'campaign_name' => 'Campaign Amount',
            'ad_id' => 'ad-amount',
            'spend' => 500,
            'impressions' => 1000,
            'clicks' => 100,
        ]));

        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-amount',
            'name' => 'Lead Amount',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Convertido',
            'record_type_name' => 'Venta',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'campaign_acquired' => 'Campaign Amount',
            'acquired_id' => 'ad-amount',
            'converted_opportunity_id' => '006-amount',
            'phone' => '600 000 002',
            'email' => 'amount@example.com',
            'portal_text' => 'Meta',
            'medio_nuevo' => 'Formulario',
            'delegacion_encargada_text' => 'Alcobendas',
        ]);

        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-amount',
            'name' => 'Oportunidad Amount',
            'created_date' => '2026-05-11 10:00:00',
            'record_type_name' => 'Venta',
            'stage_name' => 'Contrato',
            'account_phone' => '+34 600 000 002',
            'account_person_email' => 'amount@example.com',
            'reservation' => true,
            'reservation_date' => '2026-05-12',
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-15',
            'amount' => 15000,
        ]);

        app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01')
        );

        $this->getJson('/informes/campanas/data/summary?'.$this->campaignQuery())
            ->assertOk()
            ->assertJsonPath('kpis.sale_amount', 15000)
            ->assertJsonPath('kpis.roas', 30)
            ->assertJsonPath('kpis.estimated_roi', 29);
    }

    public function test_opo_for_importe_total_tiene_prioridad_sobre_amount(): void
    {
        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'platform' => 'meta',
            'metric_date' => '2026-05-10',
            'account_id' => 'act_1',
            'campaign_id' => 'camp-priority',
            'campaign_name' => 'Campaign Priority',
            'ad_id' => 'ad-priority',
            'spend' => 1000,
            'impressions' => 1000,
            'clicks' => 100,
        ]));

        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-priority',
            'name' => 'Lead Priority',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Convertido',
            'record_type_name' => 'Venta',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'campaign_acquired' => 'Campaign Priority',
            'acquired_id' => 'ad-priority',
            'converted_opportunity_id' => '006-priority',
            'portal_text' => 'Meta',
            'medio_nuevo' => 'Formulario',
            'delegacion_encargada_text' => 'Alcobendas',
        ]);

        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-priority',
            'name' => 'Oportunidad Priority',
            'created_date' => '2026-05-11 10:00:00',
            'record_type_name' => 'Venta',
            'stage_name' => 'Contrato',
            'reservation' => true,
            'reservation_date' => '2026-05-12',
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-15',
            'amount' => 100,
            'opo_for_importe_total' => 25000,
        ]);

        app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01')
        );

        $this->getJson('/informes/campanas/data/summary?'.$this->campaignQuery())
            ->assertOk()
            ->assertJsonPath('kpis.sale_amount', 25000)
            ->assertJsonPath('kpis.roas', 25)
            ->assertJsonPath('kpis.estimated_roi', 24)
            ->assertJsonPath('diagnostics.sale_amount_field_used', 'opo_for_importe_total')
            ->assertJsonPath('diagnostics.sales_with_opo_for_importe_total', 1)
            ->assertJsonPath('diagnostics.sum_opo_for_importe_total_sales', 25000);
    }

    public function test_amount_zero_no_calcula_importe_roas_ni_roi_y_expone_estado_admin(): void
    {
        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'platform' => 'meta',
            'metric_date' => '2026-05-10',
            'account_id' => 'act_1',
            'campaign_id' => 'camp-zero-amount',
            'campaign_name' => 'Campaign Zero Amount',
            'ad_id' => 'ad-zero-amount',
            'spend' => 500,
            'impressions' => 1000,
            'clicks' => 100,
        ]));

        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-zero-amount',
            'name' => 'Lead Zero Amount',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Convertido',
            'record_type_name' => 'Venta',
            'owner_id' => '005-real',
            'owner_name' => 'Comercial Real',
            'campaign_acquired' => 'Campaign Zero Amount',
            'acquired_id' => 'ad-zero-amount',
            'converted_opportunity_id' => '006-zero-amount',
            'portal_text' => 'Meta',
            'medio_nuevo' => 'Formulario',
            'delegacion_encargada_text' => 'Alcobendas',
        ]);

        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-zero-amount',
            'name' => 'Oportunidad Zero Amount',
            'created_date' => '2026-05-11 10:00:00',
            'record_type_name' => 'Venta',
            'stage_name' => 'Contrato',
            'reservation' => true,
            'reservation_date' => '2026-05-12',
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-15',
            'amount' => 0,
            'opo_for_importe_total' => 0,
        ]);

        app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01')
        );

        $this->getJson('/informes/campanas/data/summary?'.$this->campaignQuery())
            ->assertOk()
            ->assertJsonPath('kpis.sales', 1)
            ->assertJsonPath('kpis.sale_amount', null)
            ->assertJsonPath('kpis.roas', null)
            ->assertJsonPath('kpis.estimated_roi', null)
            ->assertJsonPath('diagnostics.amount_field_status', 'exists_but_zero')
            ->assertJsonPath('diagnostics.sale_amount_field_used', 'none')
            ->assertJsonPath('diagnostics.attributed_sales_with_amount', 0);
    }

    public function test_sale_amount_resolver_lee_raw_payload_case_insensitive_y_decimal_formateado(): void
    {
        $opportunity = SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-raw-importe',
            'name' => 'Oportunidad Raw Importe',
            'created_date' => '2026-05-11 10:00:00',
            'record_type_name' => 'Venta',
            'stage_name' => 'Contrato',
            'reservation' => true,
            'reservation_date' => '2026-05-12',
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-15',
            'amount' => 0,
            'opo_for_importe_total' => null,
            'raw_payload' => [
                'OPO_FOR_Importe_total__C' => '12.345,67',
            ],
        ]);

        $this->assertSame(12345.67, app(CampaignSaleAmountResolver::class)->resolve($opportunity->fresh()));
    }

    public function test_builder_expone_rango_visible_hasta_el_dia_anterior_al_fin_exclusivo(): void
    {
        $result = app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-06-01'),
            CarbonImmutable::parse('2026-06-09')
        );

        $this->assertSame('2026-06-01', $result['range_start']);
        $this->assertSame('2026-06-08', $result['range_end']);
        $this->assertSame('2026-06-09', $result['range_end_exclusive']);
    }

    public function test_campaigns_review_excludes_store_visit_campaigns_without_leads(): void
    {
        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'platform' => 'google_ads',
            'campaign_id' => 'pmax-store-visits',
            'campaign_name' => 'PMAX Visitas a la tienda',
            'campaign_status' => 'ENABLED',
            'campaign_effective_status' => null,
            'spend' => 450,
            'clicks' => 900,
        ]));

        $summary = $this->getJson('/informes/campanas/data/summary?'.$this->campaignQuery().'&context=exposicion')
            ->assertOk()
            ->json();

        $this->assertSame([], $summary['review_campaigns']);
        $this->assertSame([], $summary['rankings']['review_campaigns']);
    }

    public function test_campaigns_review_prioritizes_economic_impact_for_zero_attribution(): void
    {
        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'campaign_id' => 'low-spend-zero-leads',
            'campaign_name' => 'Zero leads low spend',
            'spend' => 200,
            'clicks' => 20,
        ]));
        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'campaign_id' => 'high-spend-zero-leads',
            'campaign_name' => 'Zero leads high spend',
            'spend' => 12000,
            'clicks' => 800,
        ]));

        $summary = $this->getJson('/informes/campanas/data/summary?'.$this->campaignQuery())
            ->assertOk()
            ->json();

        $this->assertSame('high-spend-zero-leads', $summary['review_campaigns'][0]['campaign_id']);
        $this->assertSame(12000.0, (float) $summary['review_campaigns'][0]['economic_impact']);
    }

    public function test_sales_and_purchases_count_distinct_opportunities_inside_campaign(): void
    {
        DB::table('campaign_lead_attributions')->insert([
            $this->attributionRow([
                'interest_id' => '00Q-sale-1',
                'campaign_id' => 'sale-campaign',
                'campaign_name' => 'Venta real',
                'campaign_type' => 'venta',
                'opportunity_id' => '006-sale-shared',
                'has_reservation' => true,
                'has_sale' => true,
            ]),
            $this->attributionRow([
                'interest_id' => '00Q-sale-2',
                'campaign_id' => 'sale-campaign',
                'campaign_name' => 'Venta real',
                'campaign_type' => 'venta',
                'opportunity_id' => '006-sale-shared',
                'has_reservation' => true,
                'has_sale' => true,
            ]),
            $this->attributionRow([
                'interest_id' => '00Q-purchase-1',
                'campaign_id' => 'tasacion-campaign',
                'campaign_name' => 'Tasacion real',
                'campaign_type' => 'tasacion',
                'opportunity_id' => '006-purchase-shared',
                'has_purchase' => true,
            ]),
            $this->attributionRow([
                'interest_id' => '00Q-purchase-2',
                'campaign_id' => 'tasacion-campaign',
                'campaign_name' => 'Tasacion real',
                'campaign_type' => 'tasacion',
                'opportunity_id' => '006-purchase-shared',
                'has_purchase' => true,
            ]),
        ]);

        $summary = $this->getJson('/informes/campanas/data/summary?'.$this->campaignQuery().'&context=all')
            ->assertOk()
            ->json();

        $may = collect($summary['charts']['monthly_evolution'])
            ->firstWhere('date', '2026-05-01');

        $this->assertSame(1, $summary['kpis']['reservations']);
        $this->assertSame(1, $summary['kpis']['sales']);
        $this->assertSame(1, $summary['kpis']['purchases']);
        $this->assertSame(2, $summary['kpis']['result_count']);
        $this->assertSame(1, $may['reservations']);
        $this->assertSame(1, $may['sales']);
        $this->assertSame(1, $may['purchases']);
    }

    public function test_monthly_chart_deduplicates_leads_when_one_lead_has_multiple_opportunities(): void
    {
        DB::table('campaign_lead_attributions')->insert([
            $this->attributionRow([
                'interest_id' => '00Q-shared-lead',
                'campaign_id' => 'tasacion-shared-lead',
                'campaign_name' => 'Tasacion lead compartido',
                'source_campaign_name' => 'Tasacion lead compartido',
                'campaign_type' => 'tasacion',
                'opportunity_id' => '006-shared-lead-1',
                'has_opportunity' => true,
            ]),
            $this->attributionRow([
                'interest_id' => '00Q-shared-lead',
                'campaign_id' => 'tasacion-shared-lead',
                'campaign_name' => 'Tasacion lead compartido',
                'source_campaign_name' => 'Tasacion lead compartido',
                'campaign_type' => 'tasacion',
                'opportunity_id' => '006-shared-lead-2',
                'has_opportunity' => true,
            ]),
        ]);

        $summary = $this->getJson('/informes/campanas/data/summary?'.$this->campaignQuery().'&context=tasacion')
            ->assertOk()
            ->json();

        $may = collect($summary['charts']['monthly_evolution'])
            ->firstWhere('date', '2026-05-01');

        $this->assertSame(1, $summary['kpis']['leads_salesforce']);
        $this->assertSame(2, $summary['kpis']['opportunities']);
        $this->assertSame(1, $may['leads_salesforce']);
        $this->assertSame(2, $may['opportunities']);
    }

    public function test_summary_kpis_and_funnel_deduplicate_shared_opportunities_across_campaigns(): void
    {
        DB::table('campaign_lead_attributions')->insert([
            $this->attributionRow([
                'interest_id' => '00Q-purchase-cross-1',
                'campaign_id' => 'tasacion-cross-1',
                'campaign_name' => 'Tasacion cruzada 1',
                'source_campaign_name' => 'Tasacion cruzada 1',
                'campaign_type' => 'tasacion',
                'opportunity_id' => '006-purchase-cross',
                'has_opportunity' => true,
                'has_purchase' => true,
            ]),
            $this->attributionRow([
                'interest_id' => '00Q-purchase-cross-2',
                'campaign_id' => 'tasacion-cross-2',
                'campaign_name' => 'Tasacion cruzada 2',
                'source_campaign_name' => 'Tasacion cruzada 2',
                'campaign_type' => 'tasacion',
                'opportunity_id' => '006-purchase-cross',
                'has_opportunity' => true,
                'has_purchase' => true,
            ]),
        ]);

        $summary = $this->getJson('/informes/campanas/data/summary?'.$this->campaignQuery().'&context=tasacion')
            ->assertOk()
            ->json();

        $this->assertSame(2, $summary['kpis']['leads_salesforce']);
        $this->assertSame(1, $summary['kpis']['opportunities']);
        $this->assertSame(1, $summary['kpis']['purchases']);
        $this->assertSame(1, $summary['charts']['funnel'][3]['value']);
    }

    public function test_kpi_audit_endpoint_and_csv_explain_purchase_kpi_with_related_leads_and_campaigns(): void
    {
        config()->set('services.informes_auth.enabled', true);

        DB::table('campaign_lead_attributions')->insert([
            $this->attributionRow([
                'interest_id' => '00Q-audit-purchase-1',
                'campaign_id' => 'tasacion-audit-1',
                'campaign_name' => 'Tasacion audit 1',
                'source_campaign_name' => 'Tasacion audit 1',
                'campaign_type' => 'tasacion',
                'opportunity_id' => '006-audit-purchase',
                'has_opportunity' => true,
                'has_purchase' => true,
                'source_acquired' => 'Google',
                'medium_acquired' => 'CPC',
                'campaign_acquired' => 'Tasacion audit 1',
                'commercial_user_id' => '005-audit',
                'commercial_user_name' => 'Comercial Tasacion',
            ]),
            $this->attributionRow([
                'interest_id' => '00Q-audit-purchase-2',
                'campaign_id' => 'tasacion-audit-2',
                'campaign_name' => 'Tasacion audit 2',
                'source_campaign_name' => 'Tasacion audit 2',
                'campaign_type' => 'tasacion',
                'opportunity_id' => '006-audit-purchase',
                'has_opportunity' => true,
                'has_purchase' => true,
                'source_acquired' => 'Meta',
                'medium_acquired' => 'Paid Social',
                'campaign_acquired' => 'Tasacion audit 2',
                'commercial_user_id' => '005-audit',
                'commercial_user_name' => 'Comercial Tasacion',
            ]),
        ]);

        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-audit-purchase-1',
            'name' => 'Lead compra 1',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Convertido',
            'record_type_name' => 'Tasación',
            'owner_id' => '005-audit',
            'owner_name' => 'Comercial Tasacion',
            'fuente_origen' => 'Google',
            'medio_origen' => 'CPC',
            'portal_text' => 'Web',
            'campaign_acquired' => 'Tasacion audit 1',
        ]);

        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-audit-purchase-2',
            'name' => 'Lead compra 2',
            'created_date' => '2026-05-11 10:00:00',
            'status' => 'Convertido',
            'record_type_name' => 'Tasación',
            'owner_id' => '005-audit',
            'owner_name' => 'Comercial Tasacion',
            'fuente_origen' => 'Meta',
            'medio_origen' => 'Paid Social',
            'portal_text' => 'Meta',
            'campaign_acquired' => 'Tasacion audit 2',
        ]);

        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-audit-purchase',
            'name' => 'Oportunidad compra auditada',
            'created_date' => '2026-05-12 10:00:00',
            'cv_signed_date' => '2026-05-15',
            'record_type_name' => 'Tasacion',
            'stage_name' => 'Contrato',
            'owner_id' => '005-owner-audit',
            'owner_name' => 'Owner compra auditada',
            'account_id' => '001-audit',
            'account_name' => 'Cuenta auditada',
            'portal_resolved' => 'Web',
            'opportunity_source_normalized' => 'Google',
            'opo_for_importe_total' => 14500,
        ]);

        $adminSession = $this->authenticatedSession(ReportUser::ROLE_ADMIN);

        $summary = $this->withSession($adminSession)
            ->getJson('/informes/campanas/data/summary?'.$this->campaignQuery().'&context=tasacion')
            ->assertOk()
            ->json();

        $this->assertSame(1, $summary['kpis']['purchases']);

        $audit = $this->withSession($adminSession)
            ->getJson('/informes/campanas/data/kpi-audit?'.$this->campaignQuery().'&context=tasacion&metric=purchases')
            ->assertOk()
            ->json();

        $auditItem = $audit['items'][0];

        $this->assertSame('purchases', $audit['metric']);
        $this->assertSame(1, $audit['total']);
        $this->assertSame('006-audit-purchase', $auditItem['entity_id']);
        $this->assertSame(['00Q-audit-purchase-1', '00Q-audit-purchase-2'], $auditItem['interest_ids']);
        $this->assertSame(['Tasacion audit 1', 'Tasacion audit 2'], $auditItem['campaign_names']);
        $this->assertSame(['Google', 'Meta'], $auditItem['source_acquired_values']);
        $this->assertSame('Comercial Tasacion', $auditItem['managed_by']);
        $this->assertEquals(14500.0, $auditItem['purchase_amount']);

        $csv = $this->withSession($adminSession)
            ->get('/informes/campanas/export/kpi-audit.csv?'.$this->campaignQuery().'&context=tasacion&metric=purchases')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Compras', $csv);
        $this->assertStringContainsString('006-audit-purchase', $csv);
        $this->assertStringContainsString('00Q-audit-purchase-1 | 00Q-audit-purchase-2', $csv);
        $this->assertStringContainsString('Tasacion audit 1 | Tasacion audit 2', $csv);
    }

    public function test_review_campaigns_shows_leads_and_zero_opportunities_in_quality_case(): void
    {
        DB::table('campaign_lead_attributions')->insert([
            $this->attributionRow([
                'interest_id' => '00Q-review-quality-1',
                'campaign_id' => 'review-quality-campaign',
                'campaign_name' => 'Expiey_Catálogo_Campaign',
                'source_campaign_name' => 'Expiey_Catálogo_Campaign',
                'campaign_type' => 'venta',
                'opportunity_id' => null,
                'has_opportunity' => false,
            ]),
            $this->attributionRow([
                'interest_id' => '00Q-review-quality-2',
                'campaign_id' => 'review-quality-campaign',
                'campaign_name' => 'Expiey_Catálogo_Campaign',
                'source_campaign_name' => 'Expiey_Catálogo_Campaign',
                'campaign_type' => 'venta',
                'opportunity_id' => null,
                'has_opportunity' => false,
            ]),
        ]);

        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'platform' => 'meta',
            'campaign_id' => 'review-quality-campaign',
            'campaign_name' => 'Expiey_Catálogo_Campaign',
            'spend' => 250,
            'clicks' => 80,
            'impressions' => 1000,
        ]));

        $summary = $this->getJson('/informes/campanas/data/summary?'.$this->campaignQuery().'&context=all')
            ->assertOk()
            ->json();

        $review = collect($summary['review_campaigns'])
            ->firstWhere('campaign_name', 'Expiey_Catálogo_Campaign');

        $this->assertNotNull($review);
        $this->assertSame('Intereses / oportunidades', $review['metric']);
        $this->assertSame('2 / 0', $review['value']);
    }

    public function test_tipo_de_campana_y_tipo_del_lead_son_filtros_independientes(): void
    {
        DB::table('campaign_lead_attributions')->insert([
            $this->attributionRow([
                'interest_id' => '00Q-campaign-sale-lead-appraisal',
                'campaign_id' => 'campaign-sale-classification',
                'campaign_name' => 'Expiey_Espana_Marca',
                'source_campaign_name' => 'Expiey_Espana_Marca',
                'campaign_type' => 'venta',
                'interest_type' => 'tasacion',
            ]),
            $this->attributionRow([
                'interest_id' => '00Q-campaign-sale-lead-sale',
                'campaign_id' => 'campaign-sale-classification',
                'campaign_name' => 'Expiey_Espana_Marca',
                'source_campaign_name' => 'Expiey_Espana_Marca',
                'campaign_type' => 'venta',
                'interest_type' => 'venta',
            ]),
        ]);

        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-campaign-sale-lead-appraisal',
            'created_date' => '2026-05-10 10:00:00',
            'record_type_name' => 'TasaciÃ³n',
            'record_type_normalized' => 'tasacion',
        ]);
        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-campaign-sale-lead-sale',
            'created_date' => '2026-05-10 11:00:00',
            'record_type_name' => 'Venta',
            'record_type_normalized' => 'venta',
        ]);

        $allLeadTypes = $this->getJson('/informes/campanas/data/summary?'.$this->campaignQuery().'&context=ventas')
            ->assertOk()
            ->json();
        $appraisalsOnly = $this->getJson('/informes/campanas/data/summary?'.$this->campaignQuery().'&context=ventas&lead_type=Tasaci%C3%B3n')
            ->assertOk()
            ->json();

        $this->assertSame(2, $allLeadTypes['kpis']['leads_salesforce']);
        $this->assertSame(1, $appraisalsOnly['kpis']['leads_salesforce']);
        $this->assertSame('venta', $appraisalsOnly['campaigns'][0]['campaign_type']);
    }

    public function test_kpi_distinct_explica_solapamiento_y_auditoria_identifica_el_lead(): void
    {
        DB::table('campaign_lead_attributions')->insert([
            $this->attributionRow([
                'interest_id' => '00Q-overlap-campaigns',
                'campaign_id' => 'campaign-appraisal-a',
                'campaign_name' => 'Tasador Landing Search',
                'source_campaign_name' => 'TASADOR_LANDING_SEARCH_1',
                'campaign_acquired' => 'TASADOR_LANDING_SEARCH_1',
                'acquired_id' => 'ad-123',
                'campaign_type' => 'tasacion',
                'interest_type' => 'tasacion',
            ]),
            $this->attributionRow([
                'interest_id' => '00Q-overlap-campaigns',
                'platform' => 'google_ads',
                'campaign_id' => 'campaign-appraisal-b',
                'campaign_name' => 'Geo Tasacion',
                'source_campaign_name' => 'Expiey_Leads_Geo_Tasacion',
                'campaign_acquired' => 'Expiey_Leads_Geo_Tasacion',
                'acquired_id' => 'ad-123',
                'campaign_type' => 'tasacion',
                'interest_type' => 'tasacion',
            ]),
        ]);

        CampaignAttribution::query()->create([
            'interest_id' => '00Q-overlap-campaigns',
            'platform' => 'meta',
            'campaign_id' => 'campaign-appraisal-a',
            'campaign_name' => 'Tasador Landing Search',
            'campaign_acquired' => 'TASADOR_LANDING_SEARCH_1',
            'acquired_id' => 'ad-123',
            'attribution_method' => 'ad_id_match',
            'attribution_confidence' => 'high',
            'match_status' => 'matched',
            'campaign_source_type' => 'salesforce',
            'interest_functional_created_at' => '2026-05-10 10:00:00',
        ]);
        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-overlap-campaigns',
            'created_date' => '2026-05-10 10:00:00',
            'salesforce_last_modified_at' => '2026-05-12 08:00:00',
            'synced_at' => '2026-05-12 08:05:00',
            'record_type_name' => 'TasaciÃ³n',
            'record_type_normalized' => 'tasacion',
        ]);

        $summary = $this->getJson('/informes/campanas/data/summary?'.$this->campaignQuery().'&context=tasacion&campaign_status=')
            ->assertOk()
            ->json();

        $this->assertSame(1, $summary['kpis']['leads_salesforce']);
        $this->assertSame(2, $summary['kpis']['lead_attribution_appearances']);
        $this->assertSame(1, $summary['kpis']['lead_attribution_overlap']);
        $this->assertStringContainsString('1 aparici', implode(' ', $summary['warnings']));

        $audit = $this->getJson('/informes/campanas/data/attribution-audit?'.$this->campaignQuery().'&context=tasacion&campaign_status=')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->json('items');

        $this->assertSame(['00Q-overlap-campaigns'], collect($audit)->pluck('interest_id')->unique()->values()->all());
        $this->assertSame([2], collect($audit)->pluck('campaigns_for_interest')->unique()->values()->all());
        $this->assertTrue(collect($audit)->every(fn (array $row): bool => $row['overlaps_another_campaign']));
        $this->assertSame(['tasacion'], collect($audit)->pluck('interest_type')->unique()->values()->all());

        $csv = $this->get('/informes/campanas/export/attributions.csv?'.$this->campaignQuery().'&context=tasacion&campaign_status=')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('00Q-overlap-campaigns', $csv);
        $this->assertStringContainsString('TASADOR_LANDING_SEARCH_1', $csv);
        $this->assertStringContainsString('ad_id_match', $csv);
    }

    public function test_opportunity_eliminada_no_elimina_el_lead_de_campanas_pero_anula_metricas_dependientes(): void
    {
        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'campaign_id' => 'campaign-lifecycle',
            'campaign_name' => 'Venta lifecycle',
            'campaign_type' => 'branding',
        ]));
        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-campaign-deleted-opportunity',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Nuevo',
            'is_deleted' => false,
        ]);
        $opportunity = SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-campaign-deleted',
            'created_date' => '2026-05-11 10:00:00',
            'reservation' => true,
            'cv_signed' => true,
            'opo_for_importe_total' => 12000,
        ]);
        $opportunity->update([
            'is_deleted' => true,
            'salesforce_deleted_at' => '2026-09-01 10:00:00',
            'deletion_detection_source' => SalesforceOpportunity::DELETION_SOURCE_QUERY_ALL,
        ]);
        DB::table('campaign_lead_attributions')->insert($this->attributionRow([
            'interest_id' => '00Q-campaign-deleted-opportunity',
            'campaign_id' => 'campaign-lifecycle',
            'campaign_name' => 'Venta lifecycle',
            'campaign_type' => 'branding',
            'opportunity_id' => '006-campaign-deleted',
            'has_opportunity' => true,
            'has_reservation' => true,
            'has_sale' => true,
            'sold_amount' => 12000,
        ]));

        $summary = $this->getJson('/informes/campanas/data/summary?'.$this->campaignQuery())
            ->assertOk()
            ->json();

        $this->assertSame(1, data_get($summary, 'kpis.leads_salesforce'));
        $this->assertSame(0, data_get($summary, 'kpis.opportunities'));
        $this->assertSame(0, data_get($summary, 'kpis.reservations'));
        $this->assertSame(0, data_get($summary, 'kpis.sales'));
        $this->assertNull(data_get($summary, 'kpis.sale_amount'));
        $this->assertSame(1, data_get($summary, 'source_reconciliation.leads.platform'));
        $this->assertSame(0, data_get($summary, 'source_reconciliation.opportunities.platform'));
        $this->assertSame(0, data_get($summary, 'source_reconciliation.results.platform'));

        $leadAudit = $this->getJson('/informes/campanas/data/kpi-audit?'.$this->campaignQuery().'&context=branding')
            ->assertOk()
            ->json();
        $opportunityAudit = $this->getJson('/informes/campanas/data/kpi-audit?'.$this->campaignQuery().'&metric=opportunities')
            ->assertOk()
            ->json();

        $this->assertSame(1, $leadAudit['total']);
        $this->assertSame('00Q-campaign-deleted-opportunity', $leadAudit['items'][0]['entity_id']);
        $this->assertSame(0, $opportunityAudit['total']);
    }

    public function test_opportunity_eliminada_de_tasacion_conserva_lead_y_anula_compra_e_importes(): void
    {
        CampaignPlatformDailyMetric::query()->create($this->metricRow([
            'campaign_id' => 'campaign-lifecycle-appraisal',
            'campaign_name' => 'Tasacion lifecycle',
            'campaign_type' => 'tasacion',
        ]));
        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-campaign-deleted-purchase',
            'created_date' => '2026-05-10 10:00:00',
            'status' => 'Nuevo',
            'record_type_name' => 'Tasacion',
            'is_deleted' => false,
        ]);
        $opportunity = SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-campaign-deleted-purchase',
            'created_date' => '2026-05-11 10:00:00',
            'opo_for_importe_total' => -9000,
        ]);
        $opportunity->update([
            'is_deleted' => true,
            'salesforce_deleted_at' => '2026-09-01 10:00:00',
            'deletion_detection_source' => SalesforceOpportunity::DELETION_SOURCE_QUERY_ALL,
        ]);
        DB::table('campaign_lead_attributions')->insert($this->attributionRow([
            'interest_id' => '00Q-campaign-deleted-purchase',
            'campaign_id' => 'campaign-lifecycle-appraisal',
            'campaign_name' => 'Tasacion lifecycle',
            'campaign_type' => 'tasacion',
            'opportunity_id' => '006-campaign-deleted-purchase',
            'has_opportunity' => true,
            'has_purchase' => true,
        ]));

        $summary = $this->getJson('/informes/campanas/data/summary?'.$this->campaignQuery().'&context=tasacion')
            ->assertOk()
            ->json();

        $this->assertSame(1, data_get($summary, 'kpis.leads_salesforce'));
        $this->assertSame(0, data_get($summary, 'kpis.opportunities'));
        $this->assertSame(0, data_get($summary, 'kpis.purchases'));
        $this->assertNull(data_get($summary, 'kpis.appraisal_amount'));
    }

    private function authenticatedSession(string $role): array
    {
        $this->reportUserSequence++;
        $user = ReportUser::query()->create([
            'name' => 'Campaign '.$role,
            'email' => 'campaign-'.$role.'-'.$this->reportUserSequence.'@example.test',
            'password' => Hash::make('campaign-test-password'),
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

    private function campaignQuery(): string
    {
        return http_build_query([
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
        ]);
    }

    private function metricRow(array $overrides = []): array
    {
        $attributes = array_merge([
            'platform' => 'meta',
            'metric_date' => '2026-05-10',
            'account_id' => 'act_1',
            'campaign_id' => 'camp-1',
            'campaign_name' => 'Campana',
            'campaign_status' => 'ENABLED',
            'campaign_effective_status' => 'ACTIVE',
            'spend' => 0,
            'impressions' => 0,
            'clicks' => 0,
            'raw_payload' => [],
            'synced_at' => '2026-05-10 12:00:00',
        ], $overrides);

        $attributes['unique_key'] = CampaignPlatformDailyMetric::uniqueKey($attributes);

        return $attributes;
    }

    private function attributionRow(array $overrides = []): array
    {
        $row = array_merge([
            'interest_id' => '00Q-default',
            'interest_functional_created_at' => '2026-05-10 10:00:00',
            'interest_status' => 'Nuevo',
            'interest_type' => 'venta',
            'interest_is_deleted' => false,
            'platform' => 'meta',
            'campaign_id' => 'campaign-default',
            'campaign_name' => 'Campaign default',
            'source_campaign_name' => 'Campaign default',
            'campaign_type' => 'venta',
            'opportunity_id' => null,
            'has_reservation' => false,
            'has_sale' => false,
            'has_purchase' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);

        if (! array_key_exists('campaign_source_type', $overrides)) {
            $row['campaign_source_type'] = ($row['platform'] ?? null) === 'salesforce'
                ? 'salesforce_campaign_without_spend'
                : 'platform_campaign';
        }

        return $row;
    }
}
