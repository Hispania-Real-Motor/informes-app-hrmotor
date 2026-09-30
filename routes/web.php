<?php

use App\Http\Controllers\Auth\InformesLoginController;
use App\Http\Controllers\Auth\ReportUserPasswordResetController;
use App\Http\Controllers\Reports\Calls\CallDashboardController;
use App\Http\Controllers\Reports\Calls\CallDashboardDataController;
use App\Http\Controllers\Reports\Campaigns\CampaignDashboardController;
use App\Http\Controllers\Reports\Campaigns\CampaignDashboardDataController;
use App\Http\Controllers\Reports\Campaigns\CampaignInvestmentClosureController;
use App\Http\Controllers\Reports\CommercialCommissions\CommercialCommissionClosureController;
use App\Http\Controllers\Reports\CommercialCommissions\CommercialCommissionDashboardController;
use App\Http\Controllers\Reports\CommercialCommissions\CommercialCommissionFormulaSettingsController;
use App\Http\Controllers\Reports\CommercialCommissions\CommercialFinancingPenaltyImportController;
use App\Http\Controllers\Reports\Leads\LeadDashboardController;
use App\Http\Controllers\Reports\Leads\LeadDashboardDataController;
use App\Http\Controllers\Reports\Leads\MonthlyCommercialReportDataController;
use App\Http\Controllers\Reports\Operations\OperationalAlertController;
use App\Http\Controllers\Reports\ReservationsSales\ReservationsSalesDashboardController;
use App\Http\Controllers\Reports\ReservationsSales\ReservationsSalesDashboardDataController;
use App\Http\Controllers\Reports\SeoAnalytics\SeoAnalyticalRuleSettingsController;
use App\Http\Controllers\Reports\SeoAnalytics\SeoAnalyticsDashboardController;
use App\Http\Controllers\Reports\SeoAnalytics\SeoExecutiveEmailSettingsController;
use App\Http\Controllers\Reports\Settings\ReportAccessManagementController;
use App\Http\Controllers\Reports\Stock\StockCapacityController;
use App\Http\Controllers\Reports\Stock\StockCatalogAliasApprovalController;
use App\Http\Controllers\Reports\Stock\StockDashboardController;
use App\Http\Controllers\Reports\Summary\SummaryDashboardController;
use App\Http\Controllers\Reports\Users\ReportUserManagementController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('reports.leads.index');
});

Route::get('/login', [InformesLoginController::class, 'show'])->name('login');
Route::post('/login', [InformesLoginController::class, 'login'])->name('login.post');
Route::post('/logout', [InformesLoginController::class, 'logout'])->name('logout');
Route::get('/password/forgot', [ReportUserPasswordResetController::class, 'showRequestForm'])->name('password.request');
Route::post('/password/email', [ReportUserPasswordResetController::class, 'sendResetLink'])->name('password.email');
Route::get('/password/reset/{token}', [ReportUserPasswordResetController::class, 'showResetForm'])->name('password.reset');
Route::post('/password/reset', [ReportUserPasswordResetController::class, 'reset'])->name('password.update');

Route::middleware('reports.auth')->group(function () {
    Route::get('informes', [SummaryDashboardController::class, 'index'])->name('reports.index');

    Route::get('informes/seo-analytics', [SeoAnalyticsDashboardController::class, 'index'])
        ->middleware('report.access:seo-analytics')
        ->name('reports.seo-analytics.index');

    Route::prefix('informes/seo-analytics/configuracion')
        ->name('reports.seo-analytics.settings.')
        ->group(function () {
            Route::get('/', [SeoAnalyticalRuleSettingsController::class, 'index'])->name('index');
            Route::put('/', [SeoAnalyticalRuleSettingsController::class, 'update'])->name('update');
            Route::put('/correo-diario', [SeoExecutiveEmailSettingsController::class, 'update'])->name('email.update');
        });

    Route::prefix('informes/leads')
        ->name('reports.leads.')
        ->middleware('report.access:leads')
        ->group(function () {
            Route::get('/', [LeadDashboardController::class, 'index'])->name('index');

            Route::get('/data/resumen', [LeadDashboardDataController::class, 'resumen'])->name('data.resumen');
            Route::get('/data/summary', [LeadDashboardDataController::class, 'resumen'])->name('data.summary');
            Route::get('/data/kpis', [LeadDashboardDataController::class, 'kpis'])->name('data.kpis');
            Route::get('/data/portales', [LeadDashboardDataController::class, 'portales'])->name('data.portales');
            Route::get('/data/portals', [LeadDashboardDataController::class, 'portales'])->name('data.portals');
            Route::get('/data/portal-detalle', [LeadDashboardDataController::class, 'portalDetalle'])->name('data.portal-detalle');
            Route::get('/data/delegaciones', [LeadDashboardDataController::class, 'delegaciones'])->name('data.delegaciones');
            Route::get('/data/delegations', [LeadDashboardDataController::class, 'delegaciones'])->name('data.delegations');
            Route::get('/data/comerciales', [LeadDashboardDataController::class, 'comerciales'])->name('data.comerciales');
            Route::get('/data/commercials', [LeadDashboardDataController::class, 'comerciales'])->name('data.commercials');
            Route::get('/data/comparativa', [LeadDashboardDataController::class, 'comparativa'])->name('data.comparativa');
            Route::get('/data/calidad-dato', [LeadDashboardDataController::class, 'calidadDato'])->name('data.calidad-dato');
            Route::get('/data/kpi-audit', [LeadDashboardDataController::class, 'kpiAudit'])->name('data.kpi-audit');
            Route::get('/data/lead-audit', [LeadDashboardDataController::class, 'leadAudit'])->name('data.lead-audit');
            Route::get('/export/kpi-audit.csv', [LeadDashboardDataController::class, 'exportKpiAuditCsv'])->name('export.kpi-audit');
            Route::get('/export/reconciliation-audit.csv', [LeadDashboardDataController::class, 'exportReconciliationAuditCsv'])->name('export.reconciliation-audit');

            Route::get('/data/monthly-commercial/summary', [MonthlyCommercialReportDataController::class, 'summary'])->name('data.monthly-commercial.summary');
            Route::get('/data/monthly-commercial/evolution', [MonthlyCommercialReportDataController::class, 'evolution'])->name('data.monthly-commercial.evolution');
            Route::get('/data/monthly-commercial/kpis', [MonthlyCommercialReportDataController::class, 'kpis'])->name('data.monthly-commercial.kpis');
            Route::get('/data/monthly-commercial/commercial-pending', [MonthlyCommercialReportDataController::class, 'commercialPending'])->name('data.monthly-commercial.commercial-pending');
            Route::get('/data/monthly-commercial/commercial-performance', [MonthlyCommercialReportDataController::class, 'commercialPerformance'])->name('data.monthly-commercial.commercial-performance');
            Route::get('/data/monthly-commercial/portals', [MonthlyCommercialReportDataController::class, 'portals'])->name('data.monthly-commercial.portals');
            Route::get('/data/monthly-commercial/delegations', [MonthlyCommercialReportDataController::class, 'delegations'])->name('data.monthly-commercial.delegations');
            Route::get('/data/monthly-commercial/delegation-pending', [MonthlyCommercialReportDataController::class, 'delegationPending'])->name('data.monthly-commercial.delegation-pending');
        });

    Route::prefix('informes/reservas-ventas')
        ->name('reports.reservations-sales.')
        ->middleware('report.access:reservations-sales')
        ->group(function () {
            Route::get('/', [ReservationsSalesDashboardController::class, 'index'])->name('index');
            Route::get('/data/summary', [ReservationsSalesDashboardDataController::class, 'summary'])->name('data.summary');
            Route::get('/data/commercials', [ReservationsSalesDashboardDataController::class, 'commercials'])->name('data.commercials');
            Route::get('/data/portals', [ReservationsSalesDashboardDataController::class, 'portals'])->name('data.portals');
            Route::get('/data/commercial-performance', [ReservationsSalesDashboardDataController::class, 'commercialPerformance'])->name('data.commercial-performance');
            Route::get('/data/commercial-performance/audit', [ReservationsSalesDashboardDataController::class, 'commercialPerformanceAudit'])->name('data.commercial-performance.audit');
            Route::put('/data/commercial-performance/target', [ReservationsSalesDashboardDataController::class, 'updateCommercialPerformanceTarget'])->name('data.commercial-performance.target');
            Route::get('/data/kpi-audit', [ReservationsSalesDashboardDataController::class, 'kpiAudit'])->name('data.kpi-audit');
            Route::get('/export/kpi-audit.csv', [ReservationsSalesDashboardDataController::class, 'exportKpiAuditCsv'])->name('export.kpi-audit');
        });

    Route::prefix('informes/llamadas')
        ->name('reports.calls.')
        ->middleware('report.access:calls')
        ->group(function () {
            Route::get('/', [CallDashboardController::class, 'index'])->name('index');
            Route::get('/data/summary', [CallDashboardDataController::class, 'summary'])->name('data.summary');
            Route::get('/data/agents', [CallDashboardDataController::class, 'agents'])->name('data.agents');
            Route::get('/data/delegations', [CallDashboardDataController::class, 'delegations'])->name('data.delegations');
            Route::get('/data/portals', [CallDashboardDataController::class, 'portals'])->name('data.portals');
            Route::get('/data/audit', [CallDashboardDataController::class, 'audit'])->name('data.audit');
            Route::get('/export/audit.csv', [CallDashboardDataController::class, 'exportAuditCsv'])->name('export.audit');
        });

    Route::prefix('informes/campanas')
        ->name('reports.campaigns.')
        ->middleware('report.access:campaigns')
        ->group(function () {
            Route::get('/', [CampaignDashboardController::class, 'index'])->name('index');
            Route::post('/classifications', [CampaignDashboardController::class, 'classify'])->name('classifications.store');
            Route::get('/data/summary', [CampaignDashboardDataController::class, 'summary'])->name('data.summary');
            Route::get('/data/campaigns', [CampaignDashboardDataController::class, 'campaigns'])->name('data.campaigns');
            Route::get('/data/rankings', [CampaignDashboardDataController::class, 'rankings'])->name('data.rankings');
            Route::get('/data/kpi-audit', [CampaignDashboardDataController::class, 'kpiAudit'])->name('data.kpi-audit');
            Route::get('/data/attribution-audit', [CampaignDashboardDataController::class, 'attributionAudit'])->name('data.attribution-audit');
            Route::get('/data/investment-closure', [CampaignInvestmentClosureController::class, 'status'])->name('investment-closure.status');
            Route::post('/investment-closure', [CampaignInvestmentClosureController::class, 'close'])->name('investment-closure.close');
            Route::post('/investment-closure/reopen', [CampaignInvestmentClosureController::class, 'reopen'])->name('investment-closure.reopen');
            Route::get('/export/campaigns.csv', [CampaignDashboardDataController::class, 'exportCampaignsCsv'])->name('export.campaigns');
            Route::get('/export/kpi-audit.csv', [CampaignDashboardDataController::class, 'exportKpiAuditCsv'])->name('export.kpi-audit');
            Route::get('/export/attributions.csv', [CampaignDashboardDataController::class, 'exportAttributionsCsv'])->name('export.attributions');
        });

    Route::prefix('informes/comisiones-comerciales')
        ->name('reports.commercial-commissions.')
        ->middleware('report.access:commercial-commissions')
        ->group(function () {
            Route::get('/', [CommercialCommissionDashboardController::class, 'index'])->name('index');
            Route::get('/export/comisiones.xlsx', [CommercialCommissionDashboardController::class, 'exportCommissionsXlsx'])->name('export.commissions');
            Route::get('/export/call-center-missing-captador.csv', [CommercialCommissionDashboardController::class, 'exportCallCenterMissingCaptadorCsv'])->name('export.call-center-missing-captador');
            Route::get('/export/delegation-deliveries.csv', [CommercialCommissionDashboardController::class, 'exportDelegationDeliveriesCsv'])->name('export.delegation-deliveries');
            Route::get('/export/reviews-audit.csv', [CommercialCommissionDashboardController::class, 'exportReviewsAuditCsv'])->name('export.reviews-audit');
            Route::get('/data/closure', [CommercialCommissionClosureController::class, 'status'])->name('closure.status');
            Route::post('/closure/prepare', [CommercialCommissionClosureController::class, 'prepare'])->name('closure.prepare');
            Route::post('/closure/approve', [CommercialCommissionClosureController::class, 'approve'])->name('closure.approve');
            Route::post('/closure/reopen', [CommercialCommissionClosureController::class, 'reopen'])->name('closure.reopen');
            Route::post('/adjustments', [CommercialCommissionClosureController::class, 'storeAdjustment'])->name('adjustments.store');
        });

    Route::prefix('informes/stock')
        ->name('reports.stock.')
        ->middleware('report.access:stock')
        ->group(function () {
            Route::get('/', [StockDashboardController::class, 'index'])->name('index');
            Route::get('/exportar/calidad-dato.xlsx', [StockDashboardController::class, 'exportQualityXlsx'])->name('export.quality');
            Route::post('/capacidades/importar', [StockCapacityController::class, 'import'])->name('capacities.import');
            Route::put('/capacidades', [StockCapacityController::class, 'update'])->name('capacities.update');
            Route::post('/catalogo/aliases/aprobar', [StockCatalogAliasApprovalController::class, 'store'])->name('catalog-aliases.approve');
        });

    Route::prefix('informes/usuarios')
        ->name('reports.users.')
        ->group(function () {
            Route::get('/', [ReportUserManagementController::class, 'index'])->name('index');
            Route::post('/', [ReportUserManagementController::class, 'store'])->name('store');
            Route::get('/{reportUser}/editar', [ReportUserManagementController::class, 'edit'])->name('edit');
            Route::put('/{reportUser}', [ReportUserManagementController::class, 'update'])->name('update');
            Route::delete('/{reportUser}', [ReportUserManagementController::class, 'destroy'])->name('destroy');
        });

    Route::get('informes/alertas-operativas', [OperationalAlertController::class, 'index'])
        ->name('reports.operational-alerts.index');

    Route::prefix('informes/permisos-informes')
        ->name('reports.access-settings.')
        ->group(function () {
            Route::get('/', [ReportAccessManagementController::class, 'index'])->name('index');
            Route::put('/', [ReportAccessManagementController::class, 'update'])->name('update');
        });

    Route::prefix('informes/configuracion-comisiones')
        ->name('reports.commission-settings.')
        ->group(function () {
            Route::get('/', [CommercialCommissionFormulaSettingsController::class, 'index'])->name('index');
            Route::post('/unlock', [CommercialCommissionFormulaSettingsController::class, 'unlock'])->name('unlock');
            Route::put('/', [CommercialCommissionFormulaSettingsController::class, 'update'])->name('update');
        });

    Route::prefix('informes/penalizaciones-financiacion')
        ->name('reports.commission-penalties.')
        ->group(function () {
            Route::get('/', [CommercialFinancingPenaltyImportController::class, 'index'])->name('index');
            Route::post('/', [CommercialFinancingPenaltyImportController::class, 'store'])->name('store');
        });
});
