const numberFormatter = new Intl.NumberFormat('es-ES');
const moneyFormatter = new Intl.NumberFormat('es-ES', { style: 'currency', currency: 'EUR' });
const columnsStorageKey = 'hrmotor_campaign_columns_v5';
const rankingsStorageKey = 'campaigns.visibleRankings.v7';
const campaignContextStorageKey = 'campaigns.selectedContext.v1';
const dailySeriesStorageKey = 'campaigns.dailyChart.visibleSeries';
const dailyTypeStorageKey = 'campaigns.dailyChart.chartType';
const monthlyMonthsStorageKey = 'campaigns.monthlyChart.months';
const monthlyMetricsStorageKey = 'campaigns.monthlyChart.metrics.v2';
const monthlyCompareStorageKey = 'campaigns.monthlyChart.compare';
const campaignNameSelectionStorageKey = 'campaigns.campaignNames.selected';
const metricChartsVisibilityStorageKey = 'campaigns.metricCharts.visible.v1';
const campaignPointIcon = '/brand/campaign-point.svg';
const monthlyChartEnabled = false;
const campaignPeriodMinDate = '2026-01-01';
const campaignPeriodMaxDate = '2026-12-31';

let campaignRows = [];
let tableSort = {
    all: { key: 'spend', direction: 'desc' },
    venta: { key: 'sales', direction: 'desc' },
    tasacion: { key: 'purchases', direction: 'desc' },
    exposicion: { key: 'opportunities', direction: 'desc' },
    branding: { key: 'leads_salesforce', direction: 'desc' },
    otros: { key: 'spend', direction: 'desc' },
};
let searchTimer = null;
let syncingTableScroll = false;
let dailyVisibleSeries = [];
let dailyChartType = 'lines';
let currentCharts = {};
let currentRankings = {};
let currentContext = 'all';
let campaignDetailsVisible = true;
let campaignNameSelections = [];
let monthlySelectedMonths = [];
let monthlyVisibleMetrics = [];
let monthlyCompareMode = 'none';
let currentDiagnostics = {};
let currentDiagnosticsKey = '';
let diagnosticsLoadingPromise = null;
let metricChartsVisible = loadMetricChartsVisible();
let summaryLoadingController = null;
let diagnosticsLoadingController = null;
let latestReloadRequestId = 0;
let lastRenderedFilterOptionsKey = '';
let lastRenderedCampaignNamesKey = '';
const ventaSubcontexts = ['ventas', 'exposicion', 'branding', 'otros'];
const ventaContexts = ['venta', ...ventaSubcontexts];

const dailySeriesDefinitions = [
    { key: 'spend', label: 'Inversión', formatter: formatMoney, className: 'spend' },
    { key: 'leads_salesforce', label: 'Intereses SF', formatter: formatNumber, className: 'leads' },
];

const conversionSeriesDefinitions = [
    { key: 'reservations', label: 'Reservas', formatter: formatNumber, className: 'reservations' },
    { key: 'sales', label: 'Ventas', formatter: formatNumber, className: 'sales' },
];

const monthlySeriesDefinitions = [
    { key: 'spend', label: 'Inversión', formatter: formatMoney, className: 'spend' },
    { key: 'impressions', label: 'Impresiones', formatter: formatNumber, className: 'impressions' },
    { key: 'clicks', label: 'Clicks', formatter: formatNumber, className: 'clicks' },
    { key: 'leads_salesforce', label: 'Intereses', formatter: formatNumber, className: 'leads' },
    { key: 'opportunities', label: 'Oportunidades', formatter: formatNumber, className: 'opportunities' },
    { key: 'reservations', label: 'Reservas', formatter: formatNumber, className: 'reservations' },
    { key: 'sales', label: 'Ventas', formatter: formatNumber, className: 'sales' },
    { key: 'purchases', label: 'Compras', formatter: formatNumber, className: 'purchases' },
    { key: 'cost_per_result', label: 'Coste por resultado', formatter: formatMoney, className: 'results' },
];

const monthlyTasacionSeriesDefinitions = [
    { key: 'spend', label: 'Inversión', formatter: formatMoney, className: 'spend' },
    { key: 'leads_salesforce', label: 'Intereses', formatter: formatNumber, className: 'leads' },
    { key: 'opportunities', label: 'Oportunidades', formatter: formatNumber, className: 'opportunities' },
    { key: 'purchases', label: 'Compras', formatter: formatNumber, className: 'purchases' },
];

const rankingDefinitions = [
    { key: 'top_spend', title: 'Campañas con más inversión', metric: 'spend', formatter: formatMoney, visible: true },
    { key: 'top_impressions', title: 'Campañas con más impresiones', metric: 'impressions', formatter: formatNumber, visible: false },
    { key: 'top_leads_salesforce', title: 'Más intereses Salesforce', metric: 'leads_salesforce', formatter: formatNumber, visible: true },
    { key: 'top_opportunities', title: 'Mas oportunidades', metric: 'opportunities', formatter: formatNumber, visible: false },
    { key: 'top_reservations', title: 'Mas reservas', metric: 'reservations', formatter: formatNumber, visible: false },
    { key: 'top_sales', title: 'Mas ventas', metric: 'sales', formatter: formatNumber, visible: true },
    { key: 'top_purchases', title: 'Mas compras', metric: 'purchases', formatter: formatNumber, visible: false },
    { key: 'top_sale_amount', title: 'Mayor importe vendido', metric: 'sale_amount', formatter: formatMoney, visible: false },
    { key: 'best_roas', title: 'Mejor ROAS', metric: 'roas', formatter: formatMultiplier, visible: false },
    { key: 'best_ctr', title: 'Mejor CTR', metric: 'ctr', formatter: formatPercentRatio, visible: false },
    { key: 'best_cpc', title: 'Mejor CPC', metric: 'cpc', formatter: formatMoney, visible: false },
    { key: 'best_cost_per_sale', title: 'Mejor coste por venta', metric: 'cost_per_sale', formatter: formatMoney, visible: true },
    { key: 'best_cost_per_purchase', title: 'Mejor coste por compra', metric: 'cost_per_purchase', formatter: formatMoney, visible: false },
    { key: 'best_cost_per_lead', title: 'Mejor coste por interés', metric: 'cost_per_lead', formatter: formatMoney, visible: false },
    { key: 'best_cost_per_opportunity', title: 'Mejor coste por oportunidad', metric: 'cost_per_opportunity', formatter: formatMoney, visible: false },
    { key: 'best_cost_per_result', title: 'Mejor coste por resultado', metric: 'cost_per_result', formatter: formatMoney, visible: false },
    { key: 'best_lead_to_purchase', title: 'Mejor conversión interés → compra', metric: 'lead_to_purchase', formatter: formatPercentRatio, visible: false },
    { key: 'worst_cost_per_sale', title: 'Peor coste por venta', metric: 'cost_per_sale', formatter: formatMoney, visible: false },
    { key: 'high_spend_low_conversion', title: 'Mucho gasto y poca conversión', metric: 'spend', formatter: formatMoney, visible: false },
    { key: 'many_leads_few_sales', title: 'Muchos intereses y pocas ventas', metric: 'leads_salesforce', formatter: formatNumber, visible: false },
    { key: 'many_leads_few_purchases', title: 'Muchos intereses y pocas compras', metric: 'leads_salesforce', formatter: formatNumber, visible: false },
    { key: 'review_campaigns', title: 'Campañas a revisar', metric: 'value', formatter: formatNumber, visible: true },
    { key: 'review_tracking', title: 'Revisar tracking', metric: 'spend', formatter: formatMoney, visible: true },
    { key: 'boost', title: 'Potenciar', metric: 'spend', formatter: formatMoney, visible: true },
    { key: 'review', title: 'Revisar', metric: 'spend', formatter: formatMoney, visible: false },
    { key: 'stop', title: 'Parar', metric: 'spend', formatter: formatMoney, visible: false },
];

const columnDefinitions = [
    { key: 'campaign', label: 'Campaña', visible: true, formatter: (_value, row) => campaignLabel(row) },
    { key: 'platform', label: 'Plataforma', visible: true, formatter: formatPlatform },
    { key: 'campaign_status_label', label: 'Estado', visible: true },
    { key: 'spend', label: 'Inversión', visible: true, numeric: true, formatter: formatMoney },
    { key: 'impressions', label: 'Impresiones', visible: true, numeric: true, formatter: formatNumber },
    { key: 'clicks', label: 'Clicks', visible: true, numeric: true, formatter: formatNumber },
    { key: 'ctr', label: 'CTR', visible: true, numeric: true, formatter: formatPercentRatio },
    { key: 'cpc', label: 'CPC', visible: true, numeric: true, formatter: formatMoney },
    { key: 'leads_salesforce', label: 'Intereses Salesforce', visible: true, numeric: true, formatter: formatNumber },
    { key: 'opportunities', label: 'Oportunidades', visible: true, numeric: true, formatter: formatNumber },
    { key: 'reservations', label: 'Reservas', visible: true, numeric: true, formatter: formatNumber },
    { key: 'sales', label: 'Ventas', visible: true, numeric: true, formatter: formatNumber },
    { key: 'sale_amount', label: 'Importe vendido', visible: true, numeric: true, formatter: formatMoney },
    { key: 'purchases', label: 'Compras', visible: true, numeric: true, formatter: formatNumber },
    { key: 'cost_per_lead', label: 'Coste por interés', visible: true, numeric: true, formatter: formatMoney },
    { key: 'cost_per_opportunity', label: 'Coste por oportunidad', visible: true, numeric: true, formatter: formatMoney },
    { key: 'cost_per_reservation', label: 'Coste por reserva', visible: true, numeric: true, formatter: formatMoney },
    { key: 'cost_per_sale', label: 'Coste por venta', visible: true, numeric: true, formatter: formatMoney },
    { key: 'cost_per_purchase', label: 'Coste por compra', visible: true, numeric: true, formatter: formatMoney },
    { key: 'cost_per_result', label: 'Coste por resultado', visible: true, numeric: true, formatter: formatMoney },
    { key: 'result_count', label: 'Resultados', visible: true, numeric: true, formatter: formatNumber },
    { key: 'roas', label: 'ROAS', visible: true, numeric: true, formatter: formatMultiplier },
    { key: 'estimated_roi', label: 'ROI estimado', visible: true, numeric: true, formatter: formatPercentRatio },
    { key: 'account_id', label: 'Cuenta', visible: false },
    { key: 'campaign_id', label: 'Campaign ID', visible: false },
    { key: 'source_acquired', label: 'Fuente adquirida', visible: false },
    { key: 'medium_acquired', label: 'Medio adquirido', visible: false },
    { key: 'acquired_id', label: 'ID adquirido', visible: false },
    { key: 'content_acquired', label: 'Contenido adquirido', visible: false },
    { key: 'classification', label: 'Clasificación', visible: false },
    { key: 'campaign_start_date', label: 'Fecha inicio de campaña', visible: false, formatter: formatDate },
    { key: 'campaign_end_date', label: 'Fecha fin de campaña', visible: false, formatter: formatDate },
    { key: 'last_spend_date', label: 'Última fecha con inversión', visible: false, formatter: formatDate },
    { key: 'appraisals_generated', label: 'Tasaciones generadas', visible: false, numeric: true, formatter: formatNumber },
    { key: 'cost_per_appraisal', label: 'Coste por tasación', visible: false, numeric: true, formatter: formatMoney },
    { key: 'lead_to_opportunity', label: 'Interés → oportunidad', visible: false, numeric: true, formatter: formatPercentRatio },
    { key: 'opportunity_to_reservation', label: 'Oportunidad -> reserva', visible: false, numeric: true, formatter: formatPercentRatio },
    { key: 'reservation_to_sale', label: 'Reserva -> venta', visible: false, numeric: true, formatter: formatPercentRatio },
    { key: 'lead_to_sale', label: 'Interés → venta', visible: false, numeric: true, formatter: formatPercentRatio },
    { key: 'lead_to_purchase', label: 'Interés → compra', visible: false, numeric: true, formatter: formatPercentRatio },
    { key: 'opportunity_to_purchase', label: 'Oportunidad -> compra', visible: false, numeric: true, formatter: formatPercentRatio },
];

currentContext = loadCampaignContext();
dailyVisibleSeries = loadDailyVisibleSeries();
dailyChartType = loadDailyChartType();
campaignNameSelections = loadCampaignNameSelections();
monthlyVisibleMetrics = loadMonthlyVisibleMetrics();
monthlyCompareMode = loadMonthlyCompareMode();

let visibleColumns = loadVisibleColumns();
let visibleRankings = loadVisibleRankings(currentContext);

document.addEventListener('DOMContentLoaded', async () => {
    populatePeriodPreset();
    applyCampaignDateBounds();
    setDefaultDates();
    bindTabs();
    bindFilters();
    bindResetFilters();
    bindSorting();
    bindDrawer();
    bindDiagnosticsModal();
    bindCampaignNameActions();
    bindColumns();
    bindRankingSettings();
    bindMonthlyChartControls();
    bindMetricChartsToggle();
    bindCampaignTableActions();
    bindTableScroll();
    syncCampaignTypeSelect();
    syncContextTabs();
    applyColumnVisibility();
    await reloadAllData();
});

function bindTabs() {
    document.querySelectorAll('.main-tab[data-context]').forEach((button) => {
        button.addEventListener('click', async () => {
            const targetContext = button.dataset.context === 'venta'
                ? currentVentaSubcontext()
                : button.dataset.context;

            if (!targetContext || targetContext === currentContext) {
                return;
            }

            currentContext = targetContext;
            persistCampaignContext();
            syncCampaignTypeSelect();
            visibleRankings = loadVisibleRankings(currentContext);
            monthlyVisibleMetrics = defaultMonthlyMetricKeys(currentContext);
            persistMonthlyMetrics();
            renderRankingsPopover();
            await reloadAllData();
            syncCampaignTableScrollWidth();
        });
    });
}

function bindDiagnosticsModal() {
    const drawer = document.getElementById('campaignDiagnosticsModal');
    const openButton = document.getElementById('diagnosticsOpen');
    const closeButton = document.getElementById('campaignDiagnosticsClose');
    const backdrop = document.getElementById('campaignDiagnosticsCloseBackdrop');

    if (!drawer || !openButton) {
        return;
    }

    const open = async () => {
        drawer.classList.add('is-open');
        drawer.setAttribute('aria-hidden', 'false');
        try {
            await loadDiagnostics();
        } catch (_) {
            // El modal permanece abierto para mostrar el mensaje de error renderizado.
        }
    };
    const close = () => {
        drawer.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
    };

    openButton.addEventListener('click', open);
    closeButton?.addEventListener('click', close);
    backdrop?.addEventListener('click', close);
}

function bindResetFilters() {
    document.getElementById('resetFilters')?.addEventListener('click', async () => {
        [
            'campaignType',
            'leadType',
            'platform',
            'campaignSearch',
            'campaignStatus',
            'hasOpportunity',
            'hasReservation',
            'hasSale',
            'classification',
        ].forEach((id) => {
            const element = document.getElementById(id);

            if (element) {
                element.value = '';
            }
        });

        document.getElementById('periodPreset').value = 'last_30_days';
        document.getElementById('campaignStatus').value = 'active';
        const saleSubcategory = document.getElementById('saleSubcategory');
        if (saleSubcategory) {
            saleSubcategory.value = 'venta';
        }
        currentContext = 'all';
        persistCampaignContext();
        syncCampaignTypeSelect();
        visibleRankings = loadVisibleRankings(currentContext);
        monthlyVisibleMetrics = defaultMonthlyMetricKeys(currentContext);
        persistMonthlyMetrics();
        renderRankingsPopover();
        campaignNameSelections = [];
        persistCampaignNameSelections();
        renderCampaignNameChecklist(campaignRows);
        setDefaultDates();
        await reloadAllData();
    });
}

function bindFilters() {
    [
        'startDate',
        'endDate',
        'platform',
        'campaignStatus',
        'hasOpportunity',
        'hasReservation',
        'hasSale',
        'classification',
        'leadType',
    ].forEach((id) => {
        document.getElementById(id)?.addEventListener('change', reloadAllData);
    });

    document.getElementById('periodPreset')?.addEventListener('change', async (event) => {
        applyPeriodPreset(event.target.value);
        await reloadAllData();
    });

    ['startDate', 'endDate'].forEach((id) => {
        document.getElementById(id)?.addEventListener('change', () => {
            const preset = document.getElementById('periodPreset');
            if (preset) {
                preset.value = 'custom';
            }
        });
    });

    document.getElementById('campaignSearch')?.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(reloadAllData, 350);
    });

    document.getElementById('campaignType')?.addEventListener('change', async (event) => {
        currentContext = event.target.value === 'venta'
            ? currentVentaSubcontext()
            : (event.target.value || 'all');
        persistCampaignContext();
        visibleRankings = loadVisibleRankings(currentContext);
        monthlyVisibleMetrics = defaultMonthlyMetricKeys(currentContext);
        persistMonthlyMetrics();
        renderRankingsPopover();
        syncContextTabs();
        await reloadAllData();
    });

    document.getElementById('saleSubcategory')?.addEventListener('change', async (event) => {
        if (document.getElementById('campaignType')?.value !== 'venta') {
            return;
        }

        currentContext = normalizeCampaignContext(event.target.value || 'venta');
        persistCampaignContext();
        visibleRankings = loadVisibleRankings(currentContext);
        monthlyVisibleMetrics = defaultMonthlyMetricKeys(currentContext);
        persistMonthlyMetrics();
        renderRankingsPopover();
        syncContextTabs();
        await reloadAllData();
    });
}

function bindSorting() {
    const root = document.getElementById('campaignTables');

    root?.addEventListener('click', (event) => {
        const header = event.target.closest('th[data-sortable="true"]');
        if (!header) {
            return;
        }

        const tableType = header.closest('table')?.dataset.tableType;
        const key = header.dataset.key;

        if (!tableType || !key) {
            return;
        }

        const current = tableSort[tableType] || { key: 'spend', direction: 'desc' };
        tableSort = {
            ...tableSort,
            [tableType]: {
                key,
                direction: current.key === key && current.direction === 'desc' ? 'asc' : 'desc',
            },
        };

        renderCampaignTables(campaignRows);
    });
}

function bindDrawer() {
    const drawer = document.getElementById('advancedFiltersDrawer');
    const open = () => {
        drawer?.classList.add('is-open');
        drawer?.setAttribute('aria-hidden', 'false');
    };
    const close = () => {
        drawer?.classList.remove('is-open');
        drawer?.setAttribute('aria-hidden', 'true');
    };

    document.getElementById('advancedFiltersOpen')?.addEventListener('click', open);
    document.getElementById('advancedFiltersClose')?.addEventListener('click', close);
    document.getElementById('advancedFiltersCloseBackdrop')?.addEventListener('click', close);
}

function bindCampaignNameActions() {
    document.getElementById('campaignNamesSelectAll')?.addEventListener('click', () => {
        campaignNameSelections = [...new Set(campaignRows.map((row) => row.campaign_name).filter(Boolean))];
        persistCampaignNameSelections();
        renderCampaignNameChecklist(campaignRows);
    });

    document.getElementById('campaignNamesClear')?.addEventListener('click', () => {
        campaignNameSelections = [];
        persistCampaignNameSelections();
        renderCampaignNameChecklist(campaignRows);
    });

    document.getElementById('advancedFiltersApply')?.addEventListener('click', async () => {
        const drawer = document.getElementById('advancedFiltersDrawer');
        drawer?.classList.remove('is-open');
        drawer?.setAttribute('aria-hidden', 'true');
        await reloadAllData();
    });
}

function bindColumns() {
    renderColumnsPopover();
    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }

        const toggle = target.closest('#columnsToggle');
        const popover = document.getElementById('columnsPopover');

        if (toggle && popover) {
            popover.classList.toggle('is-hidden');
            return;
        }

        if (popover && !target.closest('.columns-menu')) {
            popover.classList.add('is-hidden');
        }
    });

    document.addEventListener('change', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLInputElement)) {
            return;
        }

        if (!target.matches('[data-column-toggle]')) {
            return;
        }

        const column = target.dataset.columnToggle;
        if (!column) {
            return;
        }

        if (target.checked) {
            if (!visibleColumns.includes(column)) {
                visibleColumns = [...visibleColumns, column];
            }
        } else if (visibleColumns.length > 1) {
            visibleColumns = visibleColumns.filter((item) => item !== column);
        } else {
            target.checked = true;
            return;
        }

        persistVisibleColumns();
        applyColumnVisibility();
        syncCampaignTableScrollWidth();
    });
}

function bindRankingSettings() {
    renderRankingsPopover();
    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }

        const toggle = target.closest('#rankingsToggle');
        const popover = document.getElementById('rankingsPopover');

        if (toggle && popover) {
            popover.classList.toggle('is-hidden');
            return;
        }

        if (popover && !target.closest('.ranking-settings')) {
            popover.classList.add('is-hidden');
        }
    });

    document.addEventListener('change', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLInputElement) || !target.matches('[data-ranking-toggle]')) {
            return;
        }

        const ranking = target.dataset.rankingToggle;
        if (!ranking) {
            return;
        }

        if (target.checked) {
            if (!visibleRankings.includes(ranking)) {
                visibleRankings = [...visibleRankings, ranking];
            }
        } else if (visibleRankings.length > 1) {
            visibleRankings = visibleRankings.filter((item) => item !== ranking);
        } else {
            target.checked = true;
            return;
        }

        persistVisibleRankings();
        renderRankings(currentRankings || {});
    });
}

function bindDailyChartControls() {
    document.querySelectorAll('[data-series]').forEach((button) => {
        button.addEventListener('click', () => {
            const series = button.dataset.series;

            if (!series) {
                return;
            }

            if (dailyVisibleSeries.includes(series)) {
                if (dailyVisibleSeries.length === 1) {
                    return;
                }

                dailyVisibleSeries = dailyVisibleSeries.filter((item) => item !== series);
            } else {
                dailyVisibleSeries = [...dailyVisibleSeries, series];
            }

            localStorage.setItem(dailySeriesStorageKey, JSON.stringify(dailyVisibleSeries));
            renderCharts(currentCharts);
        });
    });

    document.querySelectorAll('[data-chart-type]').forEach((button) => {
        button.addEventListener('click', () => {
            const type = button.dataset.chartType === 'lines' ? 'lines' : 'bars';
            dailyChartType = type;
            localStorage.setItem(dailyTypeStorageKey, type);
            renderCharts(currentCharts);
        });
    });
}

function bindTableScroll() {
    const root = document.getElementById('campaignTables');
    if (!root) {
        return;
    }

    root.addEventListener('scroll', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }

        const proxy = target.matches('[data-table-scroll-proxy]');
        const wrap = target.matches('[data-table-wrap]');

        if (!proxy && !wrap) {
            return;
        }

        if (syncingTableScroll) {
            return;
        }

        syncingTableScroll = true;

        const tableType = target.dataset.tableScrollProxy || target.dataset.tableWrap;
        const counterpart = [...root.querySelectorAll(proxy ? '[data-table-wrap]' : '[data-table-scroll-proxy]')]
            .find((element) => element.dataset.tableWrap === tableType || element.dataset.tableScrollProxy === tableType);

        if (counterpart) {
            counterpart.scrollLeft = target.scrollLeft;
        }

        syncingTableScroll = false;
    }, true);

    window.addEventListener('resize', syncCampaignTableScrollWidth);
}

function bindMonthlyChartControls() {
    const root = document.getElementById('campaignCharts');

    root?.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }

        const monthButton = target.closest('[data-month-key]');
        const rangeButton = target.closest('[data-month-range]');
        const metricButton = target.closest('[data-month-metric]');
        const compareButton = target.closest('[data-compare-mode]');

        if (monthButton) {
            const monthKey = monthButton.dataset.monthKey;
            if (!monthKey) {
                return;
            }

            if (monthlySelectedMonths.includes(monthKey)) {
                if (monthlySelectedMonths.length === 1) {
                    return;
                }
                monthlySelectedMonths = monthlySelectedMonths.filter((item) => item !== monthKey);
            } else {
                monthlySelectedMonths = [...monthlySelectedMonths, monthKey];
            }

            persistMonthlySelection();
            renderCharts(currentCharts);
            return;
        }

        if (rangeButton) {
            const value = rangeButton.dataset.monthRange;
            if (!value) {
                return;
            }

            applyMonthlyRange(value, currentCharts.monthly_evolution || []);
            persistMonthlySelection();
            renderCharts(currentCharts);
            return;
        }

        if (metricButton) {
            const metric = metricButton.dataset.monthMetric;
            if (!metric) {
                return;
            }

            if (monthlyVisibleMetrics.includes(metric)) {
                if (monthlyVisibleMetrics.length === 1) {
                    return;
                }
                monthlyVisibleMetrics = monthlyVisibleMetrics.filter((item) => item !== metric);
            } else {
                monthlyVisibleMetrics = [...monthlyVisibleMetrics, metric];
            }

            persistMonthlyMetrics();
            renderCharts(currentCharts);
            return;
        }

        if (compareButton) {
            const mode = compareButton.dataset.compareMode || 'none';
            monthlyCompareMode = mode === 'prev_year' ? 'prev_year' : 'none';
            persistMonthlyCompareMode();
            renderCharts(currentCharts);
        }
    });
}

async function reloadAllData() {
    latestReloadRequestId += 1;
    const requestId = latestReloadRequestId;
    summaryLoadingController?.abort();
    summaryLoadingController = new AbortController();
    setLoadingState(true);

    try {
        const query = currentFilters();
        const params = new URLSearchParams(query);
        params.set('include_diagnostics', '0');
        const summary = await fetchJson(
            `/informes/campanas/data/summary?${params.toString()}`,
            'Error al cargar datos de campañas.',
            { signal: summaryLoadingController.signal },
        );

        if (requestId !== latestReloadRequestId) {
            return;
        }

        const campaignItems = Array.isArray(summary?.campaigns) ? summary.campaigns : [];

        setUpdatedBadge(summary?.datos_actualizados, false, summary);
        renderSummary(summary || {});
        renderFilterOptions(summary.filters || {}, campaignItems);
        campaignRows = campaignItems;
        renderCampaignTables(campaignRows);
        currentRankings = (summary && summary.rankings) || {};
        renderRankings((summary && summary.rankings) || {});
        currentDiagnostics = {};
        currentDiagnosticsKey = '';
        renderDiagnostics({});
        const exportLink = document.getElementById('exportCsv');
        if (exportLink) {
            exportLink.href = `/informes/campanas/export/campaigns.csv?${query}`;
        }
        const attributionAuditLink = document.getElementById('exportAttributionAudit');
        if (attributionAuditLink) {
            attributionAuditLink.href = `/informes/campanas/export/attributions.csv?${query}`;
        }
    } catch (error) {
        if (isAbortError(error) || requestId !== latestReloadRequestId) {
            return;
        }
        renderLoadFailure(error);
    } finally {
        if (requestId === latestReloadRequestId) {
            setLoadingState(false);
        }
    }
}

async function loadDiagnostics() {
    const root = document.getElementById('campaignDiagnostics');
    const query = currentFilters();

    if (currentDiagnosticsKey === query && Object.keys(currentDiagnostics).length > 0) {
        renderDiagnostics(currentDiagnostics);

        return currentDiagnostics;
    }

    if (root) {
        root.innerHTML = '<div class="empty-state">Cargando diagnóstico...</div>';
    }

    if (diagnosticsLoadingPromise && currentDiagnosticsKey === query) {
        return diagnosticsLoadingPromise;
    }

    const params = new URLSearchParams(query);
    params.set('include_diagnostics', '1');
    params.set('include_filters', '0');

    currentDiagnosticsKey = query;
    diagnosticsLoadingController?.abort();
    diagnosticsLoadingController = new AbortController();
    diagnosticsLoadingPromise = fetchJson(
        `/informes/campanas/data/summary?${params.toString()}`,
        'Error al cargar diagnóstico de campañas.',
        { signal: diagnosticsLoadingController.signal },
    )
        .then((summary) => {
            currentDiagnostics = (summary && summary.diagnostics) || {};
            renderDiagnostics(currentDiagnostics);

            return currentDiagnostics;
        })
        .catch((error) => {
            if (isAbortError(error)) {
                return currentDiagnostics;
            }

            currentDiagnostics = {};
            currentDiagnosticsKey = '';

            if (root) {
                root.innerHTML = `<div class="empty-state">${escapeHtml(error?.message || 'Error cargando diagnóstico')}</div>`;
            }

            throw error;
        })
        .finally(() => {
            diagnosticsLoadingPromise = null;
            diagnosticsLoadingController = null;
        });

    return diagnosticsLoadingPromise;
}

function renderSummary(data) {
    renderWarnings(data.warnings || []);
    renderInvestmentClosure(data.investment_closure || null);
    currentContext = data.selected_context || currentContext;
    syncCampaignTypeSelect();
    syncContextTabs();

    if (!data.ok) {
        showStatusMessage('No hay campañas con estos filtros.', 'empty');
        renderKpisState('No hay campañas con estos filtros.');
        renderCharts({});
        renderPlatformComparison([], 'No hay campañas con estos filtros.');
        renderReviewCampaigns([], 'No hay campañas con estos filtros.');
        renderDiagnostics({});
        renderRankings({}, 'No hay campañas con estos filtros.');
        renderCampaignTables([], 'No hay campañas con estos filtros.');
        return;
    }

    hideStatusMessage();
    renderKpis(data.kpis || {}, currentContext);
    renderCharts(data.charts || {});
    renderPlatformComparison(data.platform_comparison || []);
    renderReviewCampaigns(data.review_campaigns || []);
    renderSourceReconciliation(data.source_reconciliation || {});
    renderDiagnostics(data.diagnostics || {});
}

function renderInvestmentClosure(closure) {
    const root = document.getElementById('investmentClosureStatus');
    if (!root) return;

    if (!closure) {
        root.textContent = '';
        return;
    }

    const investment = closure.investment_status === 'closed' ? 'Cerrada' : 'Abierta';
    root.textContent = `Inversión: ${investment}. Resultados comerciales: Abiertos.`;
}

function renderSourceReconciliation(reconciliation) {
    const root = document.getElementById('salesforceReconciliation');
    if (!root) return;

    if (!window.reportUserCanSeeSourceReconciliation) {
        root.classList.add('is-hidden');
        root.innerHTML = '';
        return;
    }

    const definitions = [
        ['Intereses', reconciliation.leads],
        ['Oportunidades', reconciliation.opportunities],
        ['Resultados', reconciliation.results],
    ].filter(([, row]) => row);

    root.classList.toggle('is-hidden', definitions.length === 0);
    root.innerHTML = definitions.length ? `
        <div class="panel-title">
            <div>
                <h2>Conciliacion por origen</h2>
                <div class="small">Plataformas + Salesforce-only = total distinto; los solapamientos se muestran aparte.</div>
            </div>
        </div>
        <div class="campaign-diagnostics">
            ${definitions.map(([label, row]) => `
                <div class="diagnostic-item">
                    <span>${escapeHtml(label)}</span>
                    <strong>${formatNumber(row.platform)} + ${formatNumber(row.salesforce_only)} = ${formatNumber(row.total_distinct)}</strong>
                    <small>Solapamientos: ${formatNumber(row.overlap)}</small>
                </div>
            `).join('')}
        </div>
    ` : '';
}

function renderWarnings(warnings) {
    const root = document.getElementById('warnings');

    root.innerHTML = '';

    warnings.forEach((warning) => {
        root.insertAdjacentHTML('beforeend', `<div class="notice">${escapeHtml(warning)}</div>`);
    });
}

function renderKpis(kpis, context = 'all') {
    const root = document.getElementById('summaryKpis');

    if (!root) {
        return;
    }

    root.innerHTML = '';
    kpiCardsForContext(context, kpis).forEach((card) => {
        root.insertAdjacentHTML('beforeend', `
            <div class="card kpi campaign-kpi">
                <div class="kpi-copy">
                    <div class="kpi-label">${escapeHtml(card.label)}</div>
                    <div class="kpi-value">${escapeHtml(card.value)}</div>
                    <div class="kpi-hint">${escapeHtml(card.hint || '')}</div>
                    ${campaignKpiAuditLinkHtml(card.metric, card.label)}
                </div>
            </div>
        `);
    });
}

function renderKpisState(message) {
    const root = document.getElementById('summaryKpis');

    if (!root) {
        return;
    }

    root.innerHTML = `<div class="empty-state">${escapeHtml(message)}</div>`;
}

function dailyEvolutionHtml(rows) {
    const chartClass = dailyChartType === 'lines' ? 'campaign-evolution-lines' : 'campaign-evolution-bars';
    const content = rows.length ? (
        dailyChartType === 'lines'
            ? dailyLineChartHtml(rows)
            : dailyBarsChartHtml(rows)
    ) : '<div class="empty-state">Sin datos</div>';

    return `
        <article class="card panel campaign-chart-card campaign-chart-wide">
            <div class="panel-title compact">
                <div>
                    <h2>Evolución diaria</h2>
                    <div class="small">Inversión diaria e intereses creados</div>
                </div>
            </div>
            <div class="daily-chart-toolbar">
                <div class="daily-series-toggles">
                    ${dailySeriesDefinitions.map((series) => `
                        <button type="button" class="daily-chip ${dailyVisibleSeries.includes(series.key) ? 'is-active' : ''}" data-series="${escapeHtml(series.key)}">
                            ${dailyVisibleSeries.includes(series.key) ? '✓ ' : ''}${escapeHtml(series.label)}
                        </button>
                    `).join('')}
                </div>
                <div class="daily-type-toggle">
                    <button type="button" class="${dailyChartType === 'bars' ? 'is-active' : ''}" data-chart-type="bars">Barras</button>
                    <button type="button" class="${dailyChartType === 'lines' ? 'is-active' : ''}" data-chart-type="lines">Lineas</button>
                </div>
            </div>
            <div class="campaign-evolution ${chartClass}" style="--chart-days:${Math.max(rows.length, 1)}">${content}</div>
        </article>
    `;
}

function dailyBarsChartHtml(rows) {
    return groupedBarsChartHtml(rows, activeDailySeries(), dailyTooltip);
}

function groupedBarsChartHtml(rows, seriesDefinitions, tooltipFactory) {
    const labelEvery = axisLabelEvery(rows.length);

    return rows.map((row, index) => {
        const tooltip = tooltipFactory(row);
        const edgeClass = index === 0 ? ' is-first' : (index === rows.length - 1 ? ' is-last' : '');

        return `
            <div class="evolution-day${edgeClass}" title="${escapeHtml(tooltip)}" data-tooltip="${escapeHtml(tooltip)}">
                <div class="evolution-bars-group">
                    ${seriesDefinitions
                        .map((series) => `
                            <span class="evolution-bar ${escapeHtml(series.className)}" style="height:${barHeight(row[series.key], dailyMax(rows, series.key))}%"></span>
                        `).join('')}
                </div>
                <small>${showDateLabel(index, rows.length, labelEvery) ? escapeHtml(formatShortDate(row.date)) : ''}</small>
            </div>
        `;
    }).join('');
}

function dailyLineChartHtml(rows) {
    return genericLineChartHtml(rows, activeDailySeries(), dailyTooltip);
}

function reservationsSalesHtml(rows) {
    const content = rows.length
        ? groupedBarsChartHtml(rows, conversionSeriesDefinitions, conversionTooltip)
        : '<div class="empty-state">Sin datos</div>';
    const title = currentContext === 'tasacion' ? 'Evolución de tasaciones y compras' : 'Evolución de reservas y ventas';
    const subtitle = currentContext === 'tasacion'
        ? 'Tasaciones/citas y compras de intereses del periodo'
        : 'Reservas y ventas de intereses del periodo';

    return `
        <article class="card panel campaign-chart-card campaign-chart-wide">
            <div class="panel-title compact">
                <div>
                    <h2>${escapeHtml(title)}</h2>
                    <div class="small">${escapeHtml(subtitle)}</div>
                </div>
            </div>
            <div class="campaign-evolution campaign-evolution-bars campaign-evolution-results" style="--chart-days:${Math.max(rows.length, 1)}">${content}</div>
        </article>
    `;
}

function genericLineChartHtml(rows, seriesDefinitions, tooltipFactory, showPoints = false, options = {}) {
    const width = 100;
    const chartHeight = 96;
    const showYAxis = options.showYAxis === true;
    const sharedScale = options.sharedScale === true;
    const labelEvery = axisLabelEvery(rows.length);
    const sharedMax = sharedScale ? maxAcrossSeries(rows, seriesDefinitions) : null;
    const axisMax = showYAxis ? (sharedMax ?? maxAcrossSeries(rows, seriesDefinitions)) : null;
    const axisFormatter = options.yAxisFormatter || formatAxisValue;
    const gridLines = showYAxis ? lineGridHtml(chartHeight) : '';
    const axisFrame = showYAxis ? lineAxisFrameHtml(chartHeight) : '';
    const yAxis = showYAxis ? lineYAxisHtml(chartHeight, axisMax, axisFormatter) : '';
    const seriesSvg = seriesDefinitions
        .map((series) => {
            const max = sharedScale ? sharedMax : dailyMax(rows, series.key);
            const coordinates = rows.map((row, index) => ({
                x: lineX(index, rows.length),
                y: lineY(row[series.key], max, chartHeight),
            }));
            const points = coordinates.map((point) => `${point.x.toFixed(2)},${point.y.toFixed(2)}`).join(' ');

            return `<polyline class="line-series ${escapeHtml(series.className)}" points="${points}" />`;
        }).join('');
    const hoverPoints = rows.map((row, index) => {
        const x = lineX(index, rows.length);
        const tooltip = tooltipFactory(row);

        return `<span class="line-hover-point" style="left:${x.toFixed(2)}%" title="${escapeHtml(tooltip)}" data-tooltip="${escapeHtml(tooltip)}"></span>`;
    }).join('');
    const labels = rows.map((row, index) => `
        ${showDateLabel(index, rows.length, labelEvery)
            ? `<span class="line-axis-label ${index === 0 ? 'is-first' : (index === rows.length - 1 ? 'is-last' : '')}" style="left:${lineX(index, rows.length).toFixed(2)}%">${escapeHtml(formatShortDate(row.date))}</span>`
            : ''}
    `).join('');

    return `
        <div class="campaign-line-chart ${showYAxis ? 'has-y-axis' : ''}">
            ${yAxis}
            <svg viewBox="-0.75 0 ${width + 1.5} ${chartHeight}" preserveAspectRatio="none" aria-hidden="true">
                ${axisFrame}
                ${gridLines}
                ${seriesSvg}
            </svg>
            <div class="line-point-layer">${pointLayerHtml(rows, seriesDefinitions, tooltipFactory, showPoints, chartHeight, sharedMax)}</div>
            <div class="line-hover-layer">${hoverPoints}</div>
            <div class="line-label-layer">${labels}</div>
        </div>
    `;
}

function pointLayerHtml(rows, seriesDefinitions, tooltipFactory, showPoints, chartHeight, sharedMax = null) {
    if (!showPoints) {
        return '';
    }

    return seriesDefinitions
        .filter((series) => !series.isCompare)
        .flatMap((series) => {
            const max = sharedMax ?? dailyMax(rows, series.key);
            return rows.map((row, index) => {
                const x = lineX(index, rows.length);
                const y = lineY(row[series.key], max, chartHeight);
                const yPercent = (y / chartHeight) * 100;
                const tooltip = tooltipFactory(row);

                return `<span class="line-point-dot ${escapeHtml(series.className)}" style="left:${x.toFixed(2)}%;top:${yPercent.toFixed(2)}%" title="${escapeHtml(tooltip)}" data-tooltip="${escapeHtml(tooltip)}" aria-hidden="true"></span>`;
            });
        })
        .join('');
}

function platformBarsHtml(rows) {
    const maxSpend = Math.max(...rows.map((row) => Number(row.spend || 0)), 0);
    const maxLeads = Math.max(...rows.map((row) => Number(row.leads_salesforce || 0)), 0);
    const maxSales = Math.max(...rows.map((row) => Number(row.sales || 0)), 0);
    const content = rows.length
        ? rows.map((row) => `
            <div class="platform-chart-group" title="${escapeHtml(platformTooltip(row))}" data-tooltip="${escapeHtml(platformTooltip(row))}">
                <strong>${escapeHtml(formatPlatform(row.platform))}</strong>
                ${metricBarHtml('Inversión', row.spend, maxSpend, formatMoney)}
                ${metricBarHtml('Intereses SF', row.leads_salesforce, maxLeads, formatNumber)}
                ${metricBarHtml('Ventas', row.sales, maxSales, formatNumber)}
            </div>
        `).join('')
        : '<div class="empty-state">Sin datos</div>';

    return `
        <article class="card panel campaign-chart-card">
            <div class="panel-title compact">
                <div>
                    <h2>Por plataforma</h2>
                    <div class="small">Inversión, intereses y ventas</div>
                </div>
            </div>
            <div class="campaign-bar-list">${content}</div>
        </article>
    `;
}

function normalizeCampaignContext(value) {
    const key = String(value || '').toLowerCase();
    return ['all', 'venta', 'ventas', 'tasacion', 'exposicion', 'branding', 'otros'].includes(key) ? key : 'all';
}

function presentationCampaignContext(value = currentContext) {
    const normalized = normalizeCampaignContext(value);

    return normalized === 'ventas' ? 'venta' : normalized;
}

function isVentaContext(value = currentContext) {
    return ventaContexts.includes(normalizeCampaignContext(value));
}

function isVentaGroupedContext(value = currentContext) {
    return isVentaContext(value);
}

function currentVentaSubcontext() {
    const selected = normalizeCampaignContext(document.getElementById('saleSubcategory')?.value || currentContext);
    return isVentaContext(selected) ? selected : 'venta';
}

function persistCampaignContext() {
    localStorage.setItem(campaignContextStorageKey, currentContext);
}

function syncCampaignTypeSelect() {
    const select = document.getElementById('campaignType');
    if (!select) {
        return;
    }

    select.value = isVentaContext(currentContext) ? 'venta' : normalizeCampaignContext(currentContext);
    syncSaleSubcategorySelect();
}

function syncSaleSubcategorySelect() {
    const group = document.getElementById('saleSubcategoryGroup');
    const select = document.getElementById('saleSubcategory');
    const shouldShow = document.getElementById('campaignType')?.value === 'venta' || isVentaContext(currentContext);

    if (group) {
        group.style.display = shouldShow ? '' : 'none';
    }

    if (select) {
        select.value = isVentaContext(currentContext) ? normalizeCampaignContext(currentContext) : 'venta';
    }
}

function syncContextTabs() {
    document.querySelectorAll('.main-tab[data-context]').forEach((button) => {
        if (button.dataset.context === 'venta') {
            button.classList.toggle('active', isVentaContext(currentContext));
            return;
        }

        button.classList.toggle('active', normalizeCampaignContext(button.dataset.context) === normalizeCampaignContext(currentContext));
    });
    syncSaleSubcategorySelect();
}

function loadVisibleRankings(context = currentContext) {
    try {
        const saved = JSON.parse(localStorage.getItem(rankingsStorageKey) || 'null');
        if (Array.isArray(saved) && saved.length) {
            const known = saved.filter((key) => rankingDefinitions.some((ranking) => ranking.key === key));
            if (known.length) {
                return known.filter((key) => rankingAllowedInContext(key, context));
            }
        }
    } catch (error) {
        return defaultRankingKeys(context);
    }

    return defaultRankingKeys(context);
}

function defaultRankingKeys(context = currentContext) {
    switch (presentationCampaignContext(context)) {
        case 'venta':
            return ['best_cost_per_sale', 'review_campaigns', 'top_spend'];
        case 'tasacion':
            return ['best_cost_per_purchase', 'review_campaigns', 'top_spend'];
        case 'exposicion':
            return ['best_cost_per_opportunity', 'review_campaigns', 'top_spend'];
        case 'branding':
            return ['best_cpc', 'review_campaigns', 'top_spend'];
        case 'otros':
        case 'all':
        default:
            return ['best_cost_per_sale', 'review_campaigns', 'top_spend'];
    }
}

function rankingAllowedInContext(key, context = currentContext) {
    const normalized = presentationCampaignContext(context);

    const hidden = {
        venta: ['top_purchases', 'best_cost_per_purchase', 'best_lead_to_purchase', 'top_impressions', 'best_cost_per_result'],
        tasacion: ['top_sales', 'best_cost_per_sale', 'best_roas', 'worst_cost_per_sale', 'top_impressions', 'best_cost_per_result'],
        exposicion: ['top_sales', 'top_purchases', 'best_cost_per_sale', 'best_cost_per_purchase', 'best_roas', 'worst_cost_per_sale'],
        branding: ['top_sales', 'top_purchases', 'best_cost_per_sale', 'best_cost_per_purchase', 'best_cost_per_opportunity', 'best_lead_to_purchase', 'worst_cost_per_sale'],
        all: ['best_roas', 'worst_cost_per_sale', 'many_leads_few_sales', 'many_leads_few_purchases'],
        otros: ['top_sales', 'top_purchases', 'best_cost_per_sale', 'best_cost_per_purchase', 'best_lead_to_purchase', 'worst_cost_per_sale'],
    };

    return !(hidden[normalized] || []).includes(key);
}

function renderCharts(charts) {
    const root = document.getElementById('campaignCharts');

    if (!root) {
        return;
    }

    currentCharts = charts || {};
    root.innerHTML = metricChartsShellHtml(charts.monthly_evolution || []);
}

function bindMetricChartsToggle() {
    document.addEventListener('click', (event) => {
        const target = event.target;

        if (!(target instanceof HTMLElement) || !target.closest('#metricChartsToggle')) {
            return;
        }

        metricChartsVisible = !metricChartsVisible;
        localStorage.setItem(metricChartsVisibilityStorageKey, metricChartsVisible ? '1' : '0');
        renderCharts(currentCharts || {});
    });
}

function loadMetricChartsVisible() {
    return localStorage.getItem(metricChartsVisibilityStorageKey) !== '0';
}

function metricChartsShellHtml(rows) {
    const visibleRows = metricChartRows(rows);
    const charts = metricChartsVisible
        ? `<div class="grafana-metric-grid">${metricChartDefinitions(currentContext).map((definition) => grafanaMetricChartHtml(visibleRows, definition)).join('')}</div>`
        : '';

    return `
        <section class="card panel grafana-charts-shell">
            <div class="panel-title">
                <div>
                    <h2>Evolución mensual</h2>
                    <div class="small">Últimos seis meses por métrica principal</div>
                </div>
                <button type="button" class="main-tab" id="metricChartsToggle" aria-expanded="${metricChartsVisible ? 'true' : 'false'}">
                    ${metricChartsVisible ? 'Ocultar gráficas' : 'Mostrar gráficas'}
                </button>
            </div>
            ${charts}
        </section>
    `;
}

function metricChartRows(rows) {
    return (rows || [])
        .slice(-6)
        .map((row) => {
            const results = numericChartValue(row.result_count) ?? monthlyResultCountForRow(row);
            const spend = Number(row.spend || 0);
            const costPerResult = numericChartValue(row.cost_per_result);

            return {
                ...row,
                result_count: results,
                cost_per_result: costPerResult ?? (results > 0 ? spend / results : null),
            };
        });
}

function metricChartDefinitions(context = currentContext) {
    const normalized = presentationCampaignContext(context);
    const resultLabel = ({
        venta: 'Ventas',
        tasacion: 'Compras',
        exposicion: 'Oportunidades',
        branding: 'Intereses',
        otros: 'Resultados',
        all: 'Ventas / Compras',
    })[normalized] || 'Resultados';

    return [
        { key: 'spend', label: 'Inversión', formatter: formatMoney, className: 'spend' },
        { key: 'result_count', label: resultLabel, formatter: formatNumber, className: 'sales' },
        { key: 'cost_per_result', label: 'Coste por resultado', formatter: formatMoney, className: 'results' },
        { key: 'leads_salesforce', label: 'Intereses Salesforce', formatter: formatNumber, className: 'leads' },
    ];
}

function grafanaMetricChartHtml(rows, definition) {
    if (!rows.length) {
        return `
            <article class="grafana-panel">
                <div class="grafana-panel-title">${escapeHtml(definition.label)}</div>
                <div class="empty-state">Sin datos</div>
            </article>
        `;
    }

    const values = rows.map((row) => numericChartValue(row[definition.key]));
    const finiteValues = values.filter((value) => value !== null);
    const max = Math.max(...finiteValues, 0);
    const min = Math.min(...finiteValues, 0);
    const range = max - min || 1;
    const width = 100;
    const height = 72;
    const plotTop = 6;
    const plotBottom = 66;
    const gridRatios = [0.75, 0.5, 0.25];
    const coordinates = rows.map((row, index) => {
        const value = numericChartValue(row[definition.key]) ?? 0;
        const x = rows.length === 1 ? 50 : (index / (rows.length - 1)) * width;
        const y = plotBottom - (((value - min) / range) * (plotBottom - plotTop));

        return { x, y, value };
    });
    const points = coordinates.map((point) => `${point.x.toFixed(2)},${point.y.toFixed(2)}`).join(' ');
    const lastValue = finiteValues.length ? finiteValues[finiteValues.length - 1] : null;
    const previousValue = finiteValues.length > 1 ? finiteValues[finiteValues.length - 2] : null;
    const delta = previousValue !== null && previousValue !== 0 && lastValue !== null
        ? (lastValue - previousValue) / previousValue
        : null;
    const deltaClass = grafanaDeltaClass(definition, delta);
    const yAxisTicks = gridRatios.map((ratio) => {
        const y = plotBottom - (ratio * (plotBottom - plotTop));
        const value = min + (range * ratio);

        return {
            top: (y / height) * 100,
            label: formatGrafanaAxisValue(value, definition),
            y,
        };
    });

    return `
        <article class="grafana-panel">
            <div class="grafana-panel-head">
                <div>
                    <div class="grafana-panel-title">${escapeHtml(definition.label)}</div>
                    <div class="grafana-panel-value">${escapeHtml(definition.formatter(lastValue))}</div>
                </div>
                <div class="grafana-delta-block">
                    <span class="grafana-delta ${deltaClass}">${delta === null ? 'Sin datos' : escapeHtml(formatPercentRatio(delta))}</span>
                    <span class="grafana-delta-note">Comparación con mes anterior.</span>
                </div>
            </div>
            <div class="grafana-chart">
                <div class="grafana-chart-inner">
                    <div class="grafana-y-axis" aria-hidden="true">
                        ${yAxisTicks.map((tick) => `<span class="grafana-y-axis-tick" style="top:${tick.top.toFixed(4)}%">${escapeHtml(tick.label)}</span>`).join('')}
                    </div>
                    <div class="grafana-chart-body">
                        <div class="grafana-plot">
                            <svg viewBox="0 0 100 72" preserveAspectRatio="none" aria-hidden="true">
                                ${yAxisTicks.map((tick) => `<line class="grafana-grid-line" x1="0" x2="100" y1="${tick.y.toFixed(2)}" y2="${tick.y.toFixed(2)}" />`).join('')}
                                <polyline class="grafana-line ${escapeHtml(definition.className)}" points="${points}" />
                            </svg>
                            <div class="grafana-point-layer">
                                ${coordinates.map((point) => `<span class="grafana-point-dot ${escapeHtml(definition.className)}" style="left:${point.x.toFixed(4)}%;top:${(point.y / height * 100).toFixed(4)}%"></span>`).join('')}
                            </div>
                            <div class="grafana-hover-layer">
                                ${rows.map((row, index) => grafanaHoverZoneHtml(row, coordinates, index, definition)).join('')}
                            </div>
                        </div>
                        <div class="grafana-axis-labels">
                            ${rows.map((row, index) => {
                                const edgeClass = index === 0 ? 'is-first' : (index === rows.length - 1 ? 'is-last' : '');
                                return `<span class="${edgeClass}" style="left:${coordinates[index].x.toFixed(2)}%">${escapeHtml(formatMonthLabel(row))}</span>`;
                            }).join('')}
                        </div>
                    </div>
                </div>
            </div>
        </article>
    `;
}

function grafanaHoverZoneHtml(row, coordinates, index, definition) {
    const total = coordinates.length;
    const point = coordinates[index];
    const leftBoundary = total <= 1
        ? 0
        : (index === 0 ? 0 : (coordinates[index - 1].x + point.x) / 2);
    const rightBoundary = total <= 1
        ? 100
        : (index === total - 1 ? 100 : (point.x + coordinates[index + 1].x) / 2);
    const width = Math.max(rightBoundary - leftBoundary, total === 1 ? 100 : 0);
    const anchor = width > 0 ? ((point.x - leftBoundary) / width) * 100 : 50;
    const tooltip = `${formatGrafanaTooltipMonthLabel(row)}\n${definition.label}: ${definition.formatter(numericChartValue(row[definition.key]))}`;
    const edgeClass = index === 0 ? 'is-first' : (index === total - 1 ? 'is-last' : '');

    return `
        <span
            class="grafana-hover-zone ${edgeClass}"
            style="left:${leftBoundary.toFixed(2)}%;width:${width.toFixed(2)}%;--guide-x:${anchor.toFixed(2)}%;--tooltip-x:${anchor.toFixed(2)}%;--tooltip-y:${(point.y / 72 * 100).toFixed(2)}%"
            data-tooltip="${escapeHtml(tooltip)}"
            aria-hidden="true"
        ></span>
    `;
}

function numericChartValue(value) {
    const number = Number(value);

    return Number.isFinite(number) ? number : null;
}

function formatMonthLabel(row) {
    const date = String(row.date || '');
    const match = date.match(/^(\d{4})-(\d{2})/);

    if (!match) {
        return date;
    }

    return shortMonthName(Number(match[2]));
}

function formatGrafanaTooltipMonthLabel(row) {
    const date = String(row.date || '');
    const match = date.match(/^(\d{4})-(\d{2})/);

    if (!match) {
        return formatMonthLabel(row);
    }

    return `${shortMonthName(Number(match[2]))} ${match[1]}`;
}

function shortMonthName(month) {
    return ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'][month - 1] || String(month || '');
}

function formatGrafanaAxisValue(value, definition) {
    return formatCompactAxisValue(value, definition.key === 'spend' || definition.key === 'cost_per_result');
}

function formatCompactAxisValue(value, useCurrency = false) {
    const number = Number(value);
    if (!Number.isFinite(number)) {
        return '0';
    }

    const abs = Math.abs(number);
    let formatted;

    if (abs >= 1000000) {
        formatted = `${trimTrailingZero((number / 1000000).toFixed(abs >= 10000000 ? 0 : 1))}M`;
    } else if (abs >= 1000) {
        formatted = `${trimTrailingZero((number / 1000).toFixed(abs >= 10000 ? 0 : 1))}k`;
    } else {
        formatted = String(Math.round(number));
    }

    return useCurrency ? `${formatted} €` : formatted;
}

function trimTrailingZero(value) {
    return String(value).replace(/\.0$/, '').replace('.', ',');
}

function grafanaDeltaClass(definition, delta) {
    if (delta === null || Number(delta) === 0) {
        return 'is-neutral';
    }

    if (definition.key === 'cost_per_result') {
        return delta > 0 ? 'is-negative' : 'is-positive';
    }

    return delta > 0 ? 'is-positive' : 'is-negative';
}

function monthlyEvolutionHtml(rows) {
    const visibleRows = visibleMonthlyRows(rows);
    const selectedRows = selectedMonthlyRows(visibleRows);
    const definitions = selectedMonthlySeries();
    const content = selectedRows.length
        ? genericLineChartHtml(
            buildMonthlyChartRows(rows, selectedRows),
            buildMonthlySeriesDefinitions(definitions),
            monthlyTooltip,
            true,
            {
                sharedScale: true,
                showYAxis: true,
                yAxisFormatter: monthlyAxisFormatter(definitions),
            },
        )
        : '<div class="empty-state">Sin datos</div>';

    return `
        <article class="card panel campaign-chart-card campaign-chart-wide monthly-chart-card">
            <div class="panel-title compact">
                <div>
                    <h2>Evolución mensual</h2>
                    <div class="small">Últimos meses seleccionados. La comparación con el año anterior está desactivada por defecto.</div>
                </div>
            </div>
            <div class="monthly-chart-toolbar">
                <div class="monthly-chart-ranges">
                    <button type="button" class="monthly-pill" data-month-range="3">Últimos 3 meses</button>
                    <button type="button" class="monthly-pill" data-month-range="6">Últimos 6 meses</button>
                    <button type="button" class="monthly-pill" data-month-range="12">Últimos 12 meses</button>
                    <button type="button" class="monthly-pill" data-month-range="all">Todo</button>
                </div>
                <div class="monthly-chart-compare">
                    <button type="button" class="monthly-pill ${monthlyCompareMode === 'none' ? 'is-active' : ''}" data-compare-mode="none">Sin comparación</button>
                    <button type="button" class="monthly-pill ${monthlyCompareMode === 'prev_year' ? 'is-active' : ''}" data-compare-mode="prev_year">Año anterior</button>
                </div>
            </div>
            <div class="monthly-chart-filters">
                <div>
                    <div class="monthly-filter-label">Métricas</div>
                    <div class="monthly-pill-group" id="monthlyMetricPills">
                        ${monthlySeriesDefinitions.map((series) => `
                            <button type="button" class="monthly-pill ${monthlyVisibleMetrics.includes(series.key) ? 'is-active' : ''}" data-month-metric="${escapeHtml(series.key)}">
                                ${escapeHtml(series.label)}
                            </button>
                        `).join('')}
                    </div>
                </div>
            </div>
            <div class="monthly-chart-legend">
                ${monthlyLegendHtml(definitions)}
            </div>
            <div class="campaign-evolution campaign-evolution-lines monthly-chart-figure">${content}</div>
        </article>
    `;
}

function monthlyLegendHtml(definitions) {
    const series = buildMonthlySeriesDefinitions(definitions).filter((definition) => !definition.isCompare);

    return series.map((definition) => `
        <span class="chart-legend-item">
            <i class="chart-legend-swatch ${escapeHtml(definition.className)}"></i>
            <span>${escapeHtml(definition.label)}</span>
        </span>
    `).join('');
}

function visibleMonthlyRows(rows) {
    return rows || [];
}

function funnelHtml(rows) {
    const context = presentationCampaignContext(currentContext);
    const steps = funnelStepsForContext(context, rows || []);
    const max = Math.max(...steps.map((row) => Number(row.value || 0)), 0);
    const content = steps.length
        ? steps.map((row, index) => {
            const previous = index > 0 ? Number(steps[index - 1].value || 0) : null;
            const rate = previous ? Number(row.value || 0) / previous : null;
            const tooltip = `${row.label}: ${formatNumber(Number(row.value || 0))}${rate === null ? '' : `\nConversion etapa anterior: ${formatPercentRatio(rate)}`}`;

            return metricBarHtml(row.label, row.value, max, formatNumber, tooltip, true);
        }).join('')
        : '<div class="empty-state">Sin datos</div>';

    return `
        <article class="card panel campaign-chart-card">
            <div class="panel-title compact">
                <div>
                    <h2>Embudo simple</h2>
                    <div class="small">${escapeHtml(funnelSubtitleForContext(context))}</div>
                </div>
            </div>
            <div class="campaign-bar-list campaign-funnel-list">${content}</div>
        </article>
    `;
}

function funnelStepsForContext(context, rows) {
    if ((rows || []).every((row) => row && typeof row === 'object' && Object.hasOwn(row, 'label') && Object.hasOwn(row, 'value'))) {
        const labelsByContext = {
            venta: ['Clicks', 'Intereses Salesforce', 'Oportunidades', 'Reservas', 'Ventas'],
            tasacion: ['Clicks', 'Intereses Salesforce', 'Oportunidades', 'Compras'],
            exposicion: ['Clicks', 'Intereses Salesforce', 'Oportunidades'],
            branding: ['Clicks', 'Intereses Salesforce', 'Oportunidades'],
            otros: ['Clicks', 'Intereses Salesforce', 'Oportunidades', 'Resultados'],
            all: ['Clicks', 'Intereses Salesforce', 'Oportunidades', 'Resultados'],
        };

        const allowedLabels = labelsByContext[context] || labelsByContext.all;

        return rows.filter((row) => allowedLabels.includes(row.label));
    }

    const totals = {
        impressions: rows.reduce((sum, row) => sum + Number(row.impressions || 0), 0),
        clicks: rows.reduce((sum, row) => sum + Number(row.clicks || 0), 0),
        leads_salesforce: rows.reduce((sum, row) => sum + Number(row.leads_salesforce || 0), 0),
        opportunities: rows.reduce((sum, row) => sum + Number(row.opportunities || 0), 0),
        reservations: rows.reduce((sum, row) => sum + Number(row.reservations || 0), 0),
        sales: rows.reduce((sum, row) => sum + Number(row.sales || 0), 0),
        purchases: rows.reduce((sum, row) => sum + Number(row.purchases || 0), 0),
    };

    switch (context) {
        case 'tasacion':
            return [
                { label: 'Clicks', value: totals.clicks },
                { label: 'Intereses Salesforce', value: totals.leads_salesforce },
                { label: 'Oportunidades', value: totals.opportunities },
                { label: 'Compras', value: totals.purchases },
            ];
        case 'exposicion':
            return [
                { label: 'Clicks', value: totals.clicks },
                { label: 'Intereses Salesforce', value: totals.leads_salesforce },
                { label: 'Oportunidades', value: totals.opportunities },
            ];
        case 'branding':
            return [
                { label: 'Clicks', value: totals.clicks },
                { label: 'Intereses Salesforce', value: totals.leads_salesforce },
                { label: 'Oportunidades', value: totals.opportunities },
            ];
        case 'otros':
        case 'all':
        default:
            return [
                { label: 'Clicks', value: totals.clicks },
                { label: 'Intereses Salesforce', value: totals.leads_salesforce },
                { label: 'Oportunidades', value: totals.opportunities },
                { label: 'Resultados', value: totals.sales + totals.purchases + totals.opportunities + totals.leads_salesforce },
            ];
    }
}

function funnelSubtitleForContext(context) {
    switch (context) {
        case 'tasacion':
            return 'Clicks, intereses Salesforce, oportunidades y compras';
        case 'exposicion':
            return 'Clicks, intereses Salesforce y oportunidades';
        case 'branding':
            return 'Clicks, intereses Salesforce y oportunidades';
        case 'otros':
        case 'all':
        default:
            return 'Clicks, intereses Salesforce, oportunidades y resultado agregado';
    }
}

function renderPlatformComparison(rows, emptyMessage = '') {
    const panel = document.getElementById('platformComparisonPanel');
    const root = document.getElementById('platformComparison');

    if (!panel || !root) {
        return;
    }

    if (!Array.isArray(rows) || rows.length === 0) {
        panel.classList.remove('is-hidden');
        root.innerHTML = `<div class="empty-state">${escapeHtml(emptyMessage || 'No hay campañas con estos filtros.')}</div>`;
        return;
    }

    const ordered = ['google_ads', 'meta'].map((platform) => {
        const match = (rows || []).find((row) => row.platform === platform);
        return match || emptyPlatformComparisonRow(platform);
    });

    const maxSpend = Math.max(...ordered.map((row) => Number(row.spend || 0)), 0);
    const maxLeads = Math.max(...ordered.map((row) => Number(row.leads_salesforce || 0)), 0);
    const maxOpportunities = Math.max(...ordered.map((row) => Number(row.opportunities || 0)), 0);
    const maxResult = Math.max(...ordered.map((row) => Number(row.result_count || 0)), 0);

    panel.classList.remove('is-hidden');
    root.innerHTML = ordered.map((row) => platformComparisonCardHtml(row, maxSpend, maxLeads, maxOpportunities, maxResult)).join('');
}

function emptyPlatformComparisonRow(platform) {
    return {
        platform,
        spend: 0,
        leads_salesforce: 0,
        opportunities: 0,
        reservations: 0,
        sales: 0,
        purchases: 0,
        result_count: 0,
        cost_per_lead: null,
        cost_per_opportunity: null,
        cost_per_purchase: null,
        cost_per_sale: null,
        cost_per_result: null,
    };
}

function platformComparisonCardHtml(row, maxSpend, maxLeads, maxOpportunities, maxResult) {
    const context = presentationCampaignContext(currentContext);
    const resultLabel = platformResultLabelForContext(context);
    const resultValue = platformResultValueForContext(row, context);
    const resultCost = platformResultCostForContext(row, context);
    const costLabel = {
        venta: 'Coste por venta',
        tasacion: 'Coste por compra',
        exposicion: 'Coste por oportunidad',
        branding: 'Coste por interés',
        otros: 'Coste por resultado',
        all: 'Coste por venta / compra',
    }[context] || 'Coste por resultado';

    return `
        <article class="card platform-comparison-card">
            <div class="platform-comparison-head">
                <strong>${escapeHtml(formatPlatform(row.platform))}</strong>
                <span>${escapeHtml(platformComparisonSubtitleForContext(context))}</span>
            </div>
            <div class="platform-comparison-metrics">
                ${platformMetricItemHtml('Inversión', row.spend, formatMoney)}
                ${platformMetricItemHtml('Intereses Salesforce', row.leads_salesforce, formatNumber)}
                ${platformMetricItemHtml('Oportunidades', row.opportunities, formatNumber)}
                ${platformMetricItemHtml(resultLabel, resultValue, formatNumber)}
                ${platformMetricItemHtml(costLabel, resultCost, formatMoney)}
            </div>
        </article>
    `;
}

function platformMetricItemHtml(label, value, formatter) {
    return `
        <div class="platform-metric-item">
            <span>${escapeHtml(label)}</span>
            <strong>${escapeHtml(formatter(value))}</strong>
        </div>
    `;
}

function platformComparisonSubtitleForContext(context) {
    switch (context) {
        case 'tasacion':
            return 'Google Ads y Meta Ads con foco en compras';
        case 'exposicion':
            return 'Google Ads y Meta Ads con foco en oportunidades';
        case 'branding':
            return 'Google Ads y Meta Ads con foco en intereses';
        case 'all':
            return 'Google Ads y Meta Ads con ventas y compras agregadas';
        case 'otros':
        default:
            return 'Google Ads y Meta Ads con resultados agregados';
    }
}

function platformResultLabelForContext(context) {
    switch (presentationCampaignContext(context)) {
        case 'venta':
            return 'Ventas';
        case 'tasacion':
            return 'Compras';
        case 'exposicion':
            return 'Oportunidades';
        case 'branding':
            return 'Intereses Salesforce';
        case 'all':
            return 'Ventas / Compras';
        case 'otros':
        default:
            return 'Resultados';
    }
}

function platformResultValueForContext(row, context) {
    switch (presentationCampaignContext(context)) {
        case 'venta':
            return Number(row.sales || 0);
        case 'tasacion':
            return Number(row.purchases || 0);
        case 'exposicion':
            return Number(row.opportunities || 0);
        case 'branding':
            return Number(row.leads_salesforce || 0);
        case 'otros':
            return Number(row.result_count || 0);
        case 'all':
        default:
            return Number(row.sales || 0) + Number(row.purchases || 0);
    }
}

function platformResultCostForContext(row, context) {
    if (presentationCampaignContext(context) === 'all') {
        const results = Number(row.sales || 0) + Number(row.purchases || 0);
        return results > 0 ? Number(row.spend || 0) / results : null;
    }

    switch (presentationCampaignContext(context)) {
        case 'venta':
            return row.cost_per_sale;
        case 'tasacion':
            return row.cost_per_purchase;
        case 'exposicion':
            return row.cost_per_opportunity;
        case 'branding':
            return row.cost_per_lead;
        case 'otros':
        default:
            return row.cost_per_result;
    }
}

function renderReviewCampaigns(rows, emptyMessage = 'Sin campañas a revisar') {
    const root = document.getElementById('reviewCampaigns');

    if (!root) {
        return;
    }

    root.innerHTML = rows.length
        ? rows.slice(0, 8).map((row) => `
            <article class="review-card">
                <div class="review-card-copy">
                    <strong>${escapeHtml(row.campaign || row.campaign_name || '-')}</strong>
                    <small>${escapeHtml(formatPlatform(row.platform))} · ${escapeHtml(row.detail || row.reason || '')}</small>
                </div>
                <div class="review-card-metric">
                    <span>${escapeHtml(row.metric || 'Resultado')}</span>
                    <strong>${escapeHtml(formatReviewValue(row))}</strong>
                </div>
            </article>
        `).join('')
        : `<div class="empty-state">${escapeHtml(emptyMessage)}</div>`;
}

function loadCampaignContext() {
    try {
        return normalizeCampaignContext(localStorage.getItem(campaignContextStorageKey) || 'all');
    } catch (error) {
        return 'all';
    }
}

function monthlyResultCountForRow(row) {
    const context = presentationCampaignContext(currentContext);

    switch (context) {
        case 'venta':
            return Number(row.sales || 0);
        case 'tasacion':
            return Number(row.purchases || 0);
        case 'exposicion':
            return Number(row.opportunities || 0);
        case 'branding':
            return Number(row.leads_salesforce || 0);
        case 'otros':
            return Math.max(
                Number(row.sales || 0),
                Number(row.purchases || 0),
                Number(row.opportunities || 0),
                Number(row.leads_salesforce || 0),
            );
        case 'all':
        default:
            return Number(row.sales || 0) + Number(row.purchases || 0);
    }
}

function monthlyCostPerResultForRow(row) {
    const resultCount = monthlyResultCountForRow(row);
    return resultCount > 0 ? Number(row.spend || 0) / resultCount : 0;
}

function bindCampaignTableActions() {
    document.getElementById('campaignTables')?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-toggle-campaign-detail]');
        if (!button) {
            return;
        }

        campaignDetailsVisible = !campaignDetailsVisible;
        renderCampaignTables(campaignRows);
    });
}

function kpiCardsForContext(context, kpis) {
    const normalized = presentationCampaignContext(context);
    const totalResults = formatNumber(kpis.result_count);

    switch (normalized) {
        case 'venta':
            return [
                { label: 'Inversión', value: formatMoney(kpis.spend), hint: `CPC ${formatMoney(kpis.cpc)}` },
                { label: 'Intereses Salesforce', value: formatNumber(kpis.leads_salesforce), hint: `CPL ${formatMoney(kpis.cost_per_lead)}`, metric: 'leads_salesforce' },
                { label: 'Oportunidades', value: formatNumber(kpis.opportunities), hint: `CPO ${formatMoney(kpis.cost_per_opportunity)}`, metric: 'opportunities' },
                { label: 'Reservas', value: formatNumber(kpis.reservations), hint: `CPR ${formatMoney(kpis.cost_per_reservation)}`, metric: 'reservations' },
                { label: 'Ventas', value: formatNumber(kpis.sales), hint: `CPV ${formatMoney(kpis.cost_per_sale)}`, metric: 'sales' },
                { label: 'Importe vendido', value: formatMoney(kpis.sale_amount), hint: `ROAS ${formatMultiplier(kpis.roas)} · ROI ${formatPercentRatio(kpis.estimated_roi)}`, metric: 'sales' },
                { label: 'Coste por venta', value: formatMoney(kpis.cost_per_sale), hint: `Resultados ${formatNumber(kpis.sales)}`, metric: 'sales' },
                { label: 'ROAS / ROI', value: formatMultiplier(kpis.roas), hint: `ROI ${formatPercentRatio(kpis.estimated_roi)}` },
            ];
        case 'tasacion':
            return [
                { label: 'Inversión', value: formatMoney(kpis.spend), hint: `CPC ${formatMoney(kpis.cpc)}` },
                { label: 'Intereses Salesforce', value: formatNumber(kpis.leads_salesforce), hint: `CPL ${formatMoney(kpis.cost_per_lead)}`, metric: 'leads_salesforce' },
                { label: 'Oportunidades', value: formatNumber(kpis.opportunities), hint: `CPO ${formatMoney(kpis.cost_per_opportunity)}`, metric: 'opportunities' },
                { label: 'Compras', value: formatNumber(kpis.purchases), hint: `CP compra ${formatMoney(kpis.cost_per_purchase)}`, metric: 'purchases' },
                { label: 'Coste por interés', value: formatMoney(kpis.cost_per_lead), hint: `Intereses ${formatNumber(kpis.leads_salesforce)}`, metric: 'leads_salesforce' },
                { label: 'Coste por oportunidad', value: formatMoney(kpis.cost_per_opportunity), hint: `Oportunidades ${formatNumber(kpis.opportunities)}`, metric: 'opportunities' },
                { label: 'Coste por compra', value: formatMoney(kpis.cost_per_purchase), hint: `Compras ${formatNumber(kpis.purchases)}`, metric: 'purchases' },
                { label: 'Conversión interés → compra', value: formatPercentRatio(kpis.lead_to_purchase), hint: `Oportunidad → compra ${formatPercentRatio(kpis.opportunity_to_purchase)}`, metric: 'purchases' },
            ];
        case 'exposicion':
            return [
                { label: 'Inversión', value: formatMoney(kpis.spend), hint: `CPC ${formatMoney(kpis.cpc)}` },
                { label: 'Impresiones', value: formatNumber(kpis.impressions), hint: `CTR ${formatPercentRatio(kpis.ctr)}` },
                { label: 'Clicks', value: formatNumber(kpis.clicks), hint: `Intereses SF ${formatNumber(kpis.leads_salesforce)}` },
                { label: 'Intereses Salesforce', value: formatNumber(kpis.leads_salesforce), hint: `CPL ${formatMoney(kpis.cost_per_lead)}`, metric: 'leads_salesforce' },
                { label: 'Oportunidades', value: formatNumber(kpis.opportunities), hint: `CPO ${formatMoney(kpis.cost_per_opportunity)}`, metric: 'opportunities' },
                { label: 'Coste por interés', value: formatMoney(kpis.cost_per_lead), hint: `Intereses ${formatNumber(kpis.leads_salesforce)}`, metric: 'leads_salesforce' },
                { label: 'Coste por oportunidad', value: formatMoney(kpis.cost_per_opportunity), hint: `Oportunidades ${formatNumber(kpis.opportunities)}`, metric: 'opportunities' },
                { label: 'CTR / CPC', value: formatPercentRatio(kpis.ctr), hint: `CPC ${formatMoney(kpis.cpc)}` },
            ];
        case 'branding':
            return [
                { label: 'Inversión', value: formatMoney(kpis.spend), hint: `CPC ${formatMoney(kpis.cpc)}` },
                { label: 'Impresiones', value: formatNumber(kpis.impressions), hint: `CTR ${formatPercentRatio(kpis.ctr)}` },
                { label: 'Clicks', value: formatNumber(kpis.clicks), hint: `Intereses SF ${formatNumber(kpis.leads_salesforce)}` },
                { label: 'CTR', value: formatPercentRatio(kpis.ctr), hint: `CPC ${formatMoney(kpis.cpc)}` },
                { label: 'CPC', value: formatMoney(kpis.cpc), hint: `Clicks ${formatNumber(kpis.clicks)}` },
                { label: 'Intereses Salesforce', value: formatNumber(kpis.leads_salesforce), hint: `CPL ${formatMoney(kpis.cost_per_lead)}`, metric: 'leads_salesforce' },
                { label: 'Coste por interés', value: formatMoney(kpis.cost_per_lead), hint: `Intereses ${formatNumber(kpis.leads_salesforce)}`, metric: 'leads_salesforce' },
                { label: 'Oportunidades', value: formatNumber(kpis.opportunities), hint: `Total ${totalResults}`, metric: 'opportunities' },
            ];
        case 'otros':
        case 'all':
        default:
            return [
                { label: 'Inversión', value: formatMoney(kpis.spend), hint: `CPC ${formatMoney(kpis.cpc)}` },
                { label: 'Impresiones', value: formatNumber(kpis.impressions), hint: `CTR ${formatPercentRatio(kpis.ctr)}` },
                { label: 'Clicks', value: formatNumber(kpis.clicks), hint: `Intereses SF ${formatNumber(kpis.leads_salesforce)}` },
                { label: 'Intereses Salesforce', value: formatNumber(kpis.leads_salesforce), hint: `CPL ${formatMoney(kpis.cost_per_lead)}`, metric: 'leads_salesforce' },
                { label: 'Oportunidades', value: formatNumber(kpis.opportunities), hint: `CPO ${formatMoney(kpis.cost_per_opportunity)}`, metric: 'opportunities' },
                { label: 'Reservas', value: formatNumber(kpis.reservations), hint: `CPR ${formatMoney(kpis.cost_per_reservation)}`, metric: 'reservations' },
                { label: 'Ventas / Compras', value: totalResults, hint: `Ventas ${formatNumber(kpis.sales)} · Compras ${formatNumber(kpis.purchases)}`, metric: 'result_count' },
                {
                    label: normalized === 'all' ? 'Coste por venta / compra' : 'Coste por resultado',
                    value: formatMoney(kpis.cost_per_result),
                    hint: normalized === 'all' ? `Ventas / Compras ${totalResults}` : `Resultados ${totalResults}`,
                    metric: 'result_count',
                },
            ];
    }
}

function renderFilterOptions(filters, rows = []) {
    const filtersKey = JSON.stringify({
        campaign_types: filters.campaign_types || [],
        lead_types: filters.lead_types || [],
        platforms: filters.platforms || [],
        campaign_statuses: filters.campaign_statuses || [],
        classifications: filters.classifications || [],
    });

    if (filtersKey !== lastRenderedFilterOptionsKey) {
        populateCampaignTypeSelect(filters.campaign_types || []);
        populateSelect('leadType', filters.lead_types || [], 'Todos');
        populatePlatformSelect(filters.platforms || []);
        populateSelect('campaignStatus', filters.campaign_statuses || [], 'Todas');
        populateSelect('classification', filters.classifications || [], 'Todas');
        lastRenderedFilterOptionsKey = filtersKey;
    }

    renderCampaignNameChecklist(rows);
    syncCampaignTypeSelect();
}

function populateCampaignTypeSelect(options) {
    const select = document.getElementById('campaignType');
    if (!select) {
        return;
    }

    const current = isVentaContext(currentContext) ? 'venta' : normalizeCampaignContext(select.value || currentContext);
    const allowed = ['all', 'venta', 'tasacion'];

    select.innerHTML = (options || []).filter((option) => {
        const value = typeof option === 'object' ? option.value : option;
        return allowed.includes(normalizeCampaignContext(value));
    }).map((option) => {
        const value = typeof option === 'object' ? option.value : option;
        const label = typeof option === 'object' ? option.label : option;

        return `<option value="${escapeHtml(value || 'all')}">${escapeHtml(label || 'Todos')}</option>`;
    }).join('');

    select.value = [...select.options].some((option) => option.value === current) ? current : 'all';
    syncSaleSubcategorySelect();
}

function populatePlatformSelect(options) {
    const select = document.getElementById('platform');
    if (!select) {
        return;
    }

    const current = select.value || '';
    const filtered = (options || []).filter((option) => {
        const value = typeof option === 'object' ? option.value : option;
        return ['google_ads', 'meta'].includes(value);
    });

    select.innerHTML = '<option value="">Todas</option>';
    filtered.forEach((option) => {
        const value = typeof option === 'object' ? option.value : option;
        const label = typeof option === 'object' ? option.label : option;
        select.insertAdjacentHTML('beforeend', `<option value="${escapeHtml(value)}">${escapeHtml(label || formatPlatform(value))}</option>`);
    });

    select.value = [...select.options].some((option) => option.value === current) ? current : '';
}

function populateSelect(id, options, emptyLabel) {
    const select = document.getElementById(id);
    if (!select) {
        return;
    }

    const current = select.value || '';
    select.innerHTML = `<option value="">${escapeHtml(emptyLabel)}</option>`;

    (options || []).forEach((option) => {
        const value = typeof option === 'object' ? option.value : option;
        const label = typeof option === 'object' ? option.label : option;
        if (!value) {
            return;
        }

        select.insertAdjacentHTML('beforeend', `<option value="${escapeHtml(value)}">${escapeHtml(label || value)}</option>`);
    });

    select.value = [...select.options].some((option) => option.value === current) ? current : '';
}

function renderCampaignNameChecklist(rows) {
    const root = document.getElementById('campaignNameChecklist');
    if (!root) {
        return;
    }

    const options = [...new Map((rows || [])
        .filter((row) => row.campaign_name)
        .map((row) => [row.campaign_name, row]))
        .values()];
    const checklistKey = JSON.stringify({
        options: options.map((row) => [row.campaign_name, row.platform || '']),
        selected: [...campaignNameSelections].sort(),
    });

    if (checklistKey === lastRenderedCampaignNamesKey) {
        return;
    }

    root.innerHTML = options.length
        ? options.map((row) => {
            const name = row.campaign_name;
            const checked = campaignNameSelections.includes(name);

            return `
                <label class="campaign-name-option">
                    <input type="checkbox" value="${escapeHtml(name)}" ${checked ? 'checked' : ''}>
                    <span>
                        <strong>${escapeHtml(name)}</strong>
                        <small>${escapeHtml(formatPlatform(row.platform))}</small>
                    </span>
                </label>
            `;
        }).join('')
        : '<div class="empty-state">No hay campañas disponibles</div>';

    persistCampaignNameSelections();
    lastRenderedCampaignNamesKey = checklistKey;

    root.querySelectorAll('input[type="checkbox"]').forEach((input) => {
        input.addEventListener('change', () => {
            campaignNameSelections = [...root.querySelectorAll('input[type="checkbox"]:checked')].map((item) => item.value);
            persistCampaignNameSelections();
            lastRenderedCampaignNamesKey = '';
        });
    });
}

function loadCampaignNameSelections() {
    try {
        const saved = JSON.parse(localStorage.getItem(campaignNameSelectionStorageKey) || 'null');
        return Array.isArray(saved) ? saved.filter((value) => typeof value === 'string' && value.trim()) : [];
    } catch (error) {
        return [];
    }
}

function loadDailyVisibleSeries() {
    try {
        const saved = JSON.parse(localStorage.getItem(dailySeriesStorageKey) || 'null');
        const defaults = dailySeriesDefinitions.map((series) => series.key);
        if (!Array.isArray(saved) || !saved.length) {
            return defaults.slice(0, 1);
        }

        const known = saved.filter((key) => dailySeriesDefinitions.some((series) => series.key === key));
        return known.length ? known : defaults.slice(0, 1);
    } catch (error) {
        return dailySeriesDefinitions.slice(0, 1).map((series) => series.key);
    }
}

function loadDailyChartType() {
    try {
        const saved = localStorage.getItem(dailyTypeStorageKey) || 'lines';
        return saved === 'bars' ? 'bars' : 'lines';
    } catch (error) {
        return 'lines';
    }
}

function loadMonthlyVisibleMetrics() {
    const defaults = defaultMonthlyMetricKeys();

    try {
        const saved = JSON.parse(localStorage.getItem(monthlyMetricsStorageKey) || 'null');
        if (!Array.isArray(saved) || !saved.length) {
            return defaults;
        }

        const known = saved.filter((key) => monthlySeriesDefinitions.some((series) => series.key === key));
        return known.length ? known : defaults;
    } catch (error) {
        return defaults;
    }
}

function defaultMonthlyMetricKeys(context = currentContext) {
    switch (presentationCampaignContext(context)) {
        case 'venta':
            return ['spend', 'leads_salesforce', 'sales'];
        case 'tasacion':
            return ['spend', 'leads_salesforce', 'purchases'];
        case 'exposicion':
            return ['spend', 'leads_salesforce', 'opportunities'];
        case 'branding':
            return ['spend', 'impressions', 'leads_salesforce'];
        case 'otros':
        case 'all':
        default:
            return ['spend', 'leads_salesforce', 'cost_per_result'];
    }
}

function loadMonthlyCompareMode() {
    try {
        const saved = localStorage.getItem(monthlyCompareStorageKey) || 'none';
        return saved === 'prev_year' ? 'prev_year' : 'none';
    } catch (error) {
        return 'none';
    }
}

function persistCampaignNameSelections() {
    localStorage.setItem(campaignNameSelectionStorageKey, JSON.stringify(campaignNameSelections));
}

function currentFilters() {
    const params = new URLSearchParams();

    setParam(params, 'start_date', document.getElementById('startDate')?.value);
    setParam(params, 'end_date', document.getElementById('endDate')?.value);
    setParam(params, 'platform', document.getElementById('platform')?.value);
    setParam(params, 'context', currentContext);
    setParam(params, 'lead_type', document.getElementById('leadType')?.value);
    setParam(params, 'campaign_status', document.getElementById('campaignStatus')?.value);
    setParam(params, 'search', document.getElementById('campaignSearch')?.value);
    appendArrayParam(params, 'campaign_name', campaignNameSelections);
    setParam(params, 'has_opportunity', document.getElementById('hasOpportunity')?.value);
    setParam(params, 'has_reservation', document.getElementById('hasReservation')?.value);
    setParam(params, 'has_sale', document.getElementById('hasSale')?.value);
    setParam(params, 'classification', document.getElementById('classification')?.value);

    return params.toString();
}

function currentCampaignFilters() {
    return currentFilters();
}

function buildCampaignKpiAuditUrl(metric) {
    const params = new URLSearchParams(currentFilters());
    params.set('metric', metric);

    return `/informes/campanas/export/kpi-audit.csv?${params.toString()}`;
}

function campaignKpiAuditLinkHtml(metric, label) {
    if (!window.reportUserCanAudit || !metric) {
        return '';
    }

    return `<div class="kpi-actions"><a class="kpi-audit-link" href="${escapeHtml(buildCampaignKpiAuditUrl(metric))}" title="Auditar ${escapeHtml(label)}">Auditar KPI</a></div>`;
}

function persistMonthlySelection() {
    localStorage.setItem(monthlyMonthsStorageKey, JSON.stringify(monthlySelectedMonths));
}

function persistMonthlyMetrics() {
    localStorage.setItem(monthlyMetricsStorageKey, JSON.stringify(monthlyVisibleMetrics));
}

function persistMonthlyCompareMode() {
    localStorage.setItem(monthlyCompareStorageKey, monthlyCompareMode);
}

function loadSavedMonthlySelection() {
    try {
        const saved = JSON.parse(localStorage.getItem(monthlyMonthsStorageKey) || 'null');
        return Array.isArray(saved) ? saved.filter((value) => typeof value === 'string' && value.trim()) : [];
    } catch (error) {
        return [];
    }
}

function selectedMonthlyRows(rows) {
    if (!rows.length) {
        monthlySelectedMonths = [];
        return rows;
    }

    const availableMonths = rows.map((row) => monthKeyFromDate(row.date));
    const savedMonths = loadSavedMonthlySelection().filter((month) => availableMonths.includes(month));

    if (!monthlySelectedMonths.length) {
        monthlySelectedMonths = savedMonths.length ? savedMonths : availableMonths.slice(-6);
        persistMonthlySelection();
    } else {
        monthlySelectedMonths = monthlySelectedMonths.filter((month) => availableMonths.includes(month));
        if (!monthlySelectedMonths.length) {
            monthlySelectedMonths = availableMonths.slice(-6);
            persistMonthlySelection();
        }
    }

    const selectedRows = rows.filter((row) => monthlySelectedMonths.includes(monthKeyFromDate(row.date)));
    if (!selectedRows.length && availableMonths.length) {
        monthlySelectedMonths = availableMonths.slice(-6);
        persistMonthlySelection();
        return rows.filter((row) => monthlySelectedMonths.includes(monthKeyFromDate(row.date)));
    }

    return selectedRows;
}

function selectedMonthlySeries() {
    if (!monthlyVisibleMetrics.length) {
        monthlyVisibleMetrics = defaultMonthlyMetricKeys(currentContext);
    }

    return monthlyVisibleMetrics.filter((key) => monthlySeriesDefinitions.some((series) => series.key === key));
}

function buildMonthlyChartRows(allRows, selectedRows) {
    const monthMap = new Map((allRows || []).map((row) => [monthKeyFromDate(row.date), row]));
    const compareOffset = monthlyCompareMode === 'prev_year' ? 12 : 0;

    return (selectedRows || []).map((row) => {
        const monthKey = monthKeyFromDate(row.date);
        const compareKey = shiftMonthKey(monthKey, compareOffset);
        const compareRow = compareOffset ? monthMap.get(compareKey) : null;
        const chartRow = {
            date: row.date,
            month_key: monthKey,
            label: monthLabel(row),
            result_count: monthlyResultCountForRow(row),
        };

        selectedMonthlySeries().forEach((metric) => {
            if (metric === 'cost_per_result') {
                chartRow[metric] = monthlyCostPerResultForRow(row);
                chartRow[`${metric}_compare`] = compareRow ? monthlyCostPerResultForRow(compareRow) : 0;
                return;
            }

            chartRow[metric] = Number(row[metric] || 0);
            chartRow[`${metric}_compare`] = Number(compareRow?.[metric] || 0);
        });

        return chartRow;
    });
}

function buildMonthlySeriesDefinitions(metrics) {
    return (metrics || []).flatMap((metric) => {
        const definition = monthlySeriesDefinitions.find((series) => series.key === metric);
        if (!definition) {
            return [];
        }

        return [
            definition,
            monthlyCompareMode === 'prev_year'
                ? {
                    ...definition,
                    key: `${definition.key}_compare`,
                    label: `${definition.label} año anterior`,
                    className: `${definition.className} is-compare`,
                    isCompare: true,
                }
                : null,
        ].filter(Boolean);
    });
}

function axisLabelEvery(length) {
    if (length <= 6) {
        return 1;
    }

    if (length <= 12) {
        return 2;
    }

    return Math.ceil(length / 6);
}

function showDateLabel(index, length, labelEvery) {
    if (index === 0 || index === length - 1) {
        return true;
    }

    return index % Math.max(labelEvery, 1) === 0;
}

function dailyMax(rows, key) {
    return Math.max(...(rows || []).map((row) => Number(row?.[key] || 0)), 0);
}

function maxAcrossSeries(rows, seriesDefinitions) {
    return Math.max(...(seriesDefinitions || []).map((series) => dailyMax(rows, series.key)), 0);
}

function barHeight(value, max) {
    if (max <= 0) {
        return 0;
    }

    return Math.max(4, (Number(value || 0) / max) * 100);
}

function lineX(index, length) {
    if (length <= 1) {
        return 50;
    }

    return (index / (length - 1)) * 100;
}

function lineY(value, max, chartHeight) {
    if (max <= 0) {
        return chartHeight / 2;
    }

    const ratio = Number(value || 0) / max;
    return chartHeight - (ratio * chartHeight);
}

function dailyTooltip(row) {
    return [
        formatDate(row.date),
        `Inversión: ${formatMoney(row.spend)}`,
        `Intereses Salesforce: ${formatNumber(row.leads_salesforce)}`,
    ].join('\n');
}

function conversionTooltip(row) {
    const context = presentationCampaignContext(currentContext);

    if (context === 'tasacion') {
        return [
            formatDate(row.date),
            `Oportunidades: ${formatNumber(row.opportunities)}`,
            `Compras: ${formatNumber(row.purchases)}`,
        ].join('\n');
    }

    return [
        formatDate(row.date),
        `Reservas: ${formatNumber(row.reservations)}`,
        `Ventas: ${formatNumber(row.sales)}`,
    ].join('\n');
}

function platformTooltip(row) {
    return [
        formatPlatform(row.platform),
        `Inversión: ${formatMoney(row.spend)}`,
        `Intereses Salesforce: ${formatNumber(row.leads_salesforce)}`,
        `Oportunidades: ${formatNumber(row.opportunities)}`,
        `Resultados: ${formatNumber(platformResultValueForContext(row, presentationCampaignContext(currentContext)))}`,
    ].join('\n');
}

function monthlyTooltip(row) {
    const lines = [row.label || monthLabel(row)];

    selectedMonthlySeries().forEach((metric) => {
        const definition = monthlySeriesDefinitions.find((series) => series.key === metric);

        if (!definition) {
            return;
        }

        lines.push(`${definition.label}: ${definition.formatter(row[metric])}`);

        if (monthlyCompareMode === 'prev_year') {
            lines.push(`${definition.label} año anterior: ${definition.formatter(row[`${metric}_compare`])}`);
        }
    });

    return lines.join('\n');
}

function monthlyAxisFormatter(definitions) {
    const selectedDefinitions = (definitions || [])
        .map((metric) => monthlySeriesDefinitions.find((series) => series.key === metric))
        .filter(Boolean);

    if (selectedDefinitions.length === 1) {
        return selectedDefinitions[0].formatter;
    }

    const formatterNames = [...new Set(selectedDefinitions.map((definition) => definition.formatter?.name || ''))];

    if (formatterNames.length === 1 && selectedDefinitions[0]?.formatter) {
        return selectedDefinitions[0].formatter;
    }

    return formatAxisValue;
}

function formatAxisValue(value) {
    const numeric = Number(value || 0);
    const absolute = Math.abs(numeric);

    if (absolute >= 1000000) {
        return `${(numeric / 1000000).toFixed(1)}M`;
    }

    if (absolute >= 1000) {
        return `${(numeric / 1000).toFixed(1)}k`;
    }

    return formatNumber(numeric);
}

function lineGridHtml(chartHeight) {
    return [0.75, 0.5, 0.25].map((ratio) => {
        const y = (chartHeight - (ratio * chartHeight)).toFixed(2);
        return `<line class="line-grid" x1="0" x2="100" y1="${y}" y2="${y}" />`;
    }).join('');
}

function lineAxisFrameHtml(chartHeight) {
    return `
        <line class="line-axis-frame" x1="0" x2="0" y1="0" y2="${chartHeight}" />
        <line class="line-axis-frame is-bottom" x1="0" x2="100" y1="${chartHeight}" y2="${chartHeight}" />
    `;
}

function lineYAxisHtml(_chartHeight, max, formatter) {
    const safeMax = Number(max || 0);
    const ratios = [1, 0.75, 0.5, 0.25, 0];

    return `
        <div class="line-y-axis" aria-hidden="true">
            ${ratios.map((ratio) => {
                const value = safeMax * ratio;
                const top = (100 - (ratio * 100)).toFixed(2);

                return `
                    <span class="line-y-axis-tick" style="top:${top}%">
                        ${escapeHtml(formatter(value))}
                    </span>
                `;
            }).join('')}
        </div>
    `;
}

function applyMonthlyRange(value, rows) {
    const availableMonths = (rows || []).map((row) => monthKeyFromDate(row.date));

    if (value === 'all') {
        monthlySelectedMonths = availableMonths;
        return;
    }

    const months = Number(value);
    monthlySelectedMonths = availableMonths.slice(-months);
}

function activeColumns(context = currentContext) {
    return columnDefinitions.filter((column) => visibleColumns.includes(column.key) && columnAllowedInContext(column.key, context));
}

function applyColumnVisibility() {
    document.querySelectorAll('#campaignTables table[data-table-type] [data-column]').forEach((cell) => {
        const tableType = cell.closest('table')?.dataset.tableType || currentContext;
        cell.classList.toggle('is-hidden', !visibleColumns.includes(cell.dataset.column) || !columnAllowedInContext(cell.dataset.column, tableType));
    });
}

function columnAllowedInContext(key, context = currentContext) {
    const hiddenByContext = {
        venta: ['purchases', 'cost_per_lead', 'cost_per_opportunity', 'cost_per_purchase', 'cost_per_result', 'result_count', 'lead_to_purchase', 'opportunity_to_purchase', 'appraisals_generated', 'cost_per_appraisal', 'appraisal_amount'],
        tasacion: ['reservations', 'sales', 'sale_amount', 'cost_per_reservation', 'cost_per_sale', 'cost_per_result', 'result_count', 'roas', 'estimated_roi', 'opportunity_to_reservation', 'reservation_to_sale', 'lead_to_sale'],
        exposicion: ['reservations', 'sales', 'sale_amount', 'purchases', 'cost_per_reservation', 'cost_per_sale', 'cost_per_purchase', 'cost_per_result', 'result_count', 'roas', 'estimated_roi', 'opportunity_to_reservation', 'reservation_to_sale', 'lead_to_sale', 'lead_to_purchase', 'opportunity_to_purchase', 'appraisals_generated', 'cost_per_appraisal', 'appraisal_amount'],
        branding: ['reservations', 'sales', 'sale_amount', 'purchases', 'cost_per_reservation', 'cost_per_sale', 'cost_per_purchase', 'cost_per_opportunity', 'cost_per_result', 'result_count', 'roas', 'estimated_roi', 'opportunity_to_reservation', 'reservation_to_sale', 'lead_to_sale', 'lead_to_purchase', 'opportunity_to_purchase', 'appraisals_generated', 'cost_per_appraisal', 'appraisal_amount'],
        otros: ['appraisals_generated', 'cost_per_appraisal', 'appraisal_amount', 'cost_per_purchase'],
        all: ['appraisals_generated', 'cost_per_appraisal', 'appraisal_amount', 'cost_per_purchase'],
    };

    return !(hiddenByContext[presentationCampaignContext(context)] || []).includes(key);
}

function syncCampaignTableScrollWidth() {
    document.querySelectorAll('#campaignTables [data-table-scroll-proxy]').forEach((proxy) => {
        const tableType = proxy.dataset.tableScrollProxy;
        const wrap = [...document.querySelectorAll('#campaignTables [data-table-wrap]')]
            .find((element) => element.dataset.tableWrap === tableType);
        const table = wrap?.querySelector('table');
        const spacer = proxy.firstElementChild;

        if (spacer && table) {
            spacer.style.width = `${table.scrollWidth}px`;
        }
    });
}

function setDefaultDates() {
    const end = lastCompleteDay();
    const start = new Date();
    start.setDate(end.getDate() - 30);

    document.getElementById('startDate').value = clampCampaignDate(start);
    document.getElementById('endDate').value = clampCampaignDate(end);
}

function populatePeriodPreset() {
    const select = document.getElementById('periodPreset');
    if (!select) {
        return;
    }

    const options = [
        { value: 'last_30_days', label: 'Últimos 30 días' },
        { value: 'current_month', label: 'Mes actual' },
        { value: 'current_year', label: 'Año actual' },
        ...monthPresetOptions(),
        { value: 'custom', label: 'Personalizado' },
    ];

    select.innerHTML = options.map((option) => `
        <option value="${escapeHtml(option.value)}" data-start="${escapeHtml(option.start || '')}" data-end="${escapeHtml(option.end || '')}">
            ${escapeHtml(option.label)}
        </option>
    `).join('');
    select.value = 'last_30_days';
}

function monthPresetOptions() {
    const formatter = new Intl.DateTimeFormat('es-ES', { month: 'long', year: 'numeric' });
    const lastVisibleMonth = campaignLastVisibleMonth();
    const months = [];

    for (let monthIndex = lastVisibleMonth.getMonth(); monthIndex >= 0; monthIndex -= 1) {
        const month = new Date(2026, monthIndex, 1);
        const end = new Date(month.getFullYear(), month.getMonth() + 1, 0);
        const label = formatter.format(month).replace(/^./, (letter) => letter.toUpperCase());

        months.push({
            value: `month_${month.getFullYear()}_${String(month.getMonth() + 1).padStart(2, '0')}`,
            label,
            start: clampCampaignDate(month),
            end: clampCampaignDate(end),
        });
    }

    return months;
}

function applyPeriodPreset(value) {
    const startDate = document.getElementById('startDate');
    const endDate = document.getElementById('endDate');

    if (!startDate || !endDate || value === 'custom') {
        return;
    }

    if (value === 'last_30_days') {
        setDefaultDates();
        return;
    }

    if (value === 'current_month') {
        const today = new Date();
        const monthStart = new Date(today.getFullYear(), today.getMonth(), 1);
        const end = lastCompleteDay();
        startDate.value = clampCampaignDate(monthStart);
        endDate.value = clampCampaignDate(end < monthStart ? monthStart : end);
        return;
    }

    if (value === 'current_year') {
        const today = new Date();
        const yearStart = new Date(today.getFullYear(), 0, 1);
        const end = lastCompleteDay();
        startDate.value = clampCampaignDate(yearStart);
        endDate.value = clampCampaignDate(end < yearStart ? yearStart : end);
        return;
    }

    const selected = [...document.querySelectorAll('#periodPreset option')].find((option) => option.value === value);
    if (selected?.dataset.start && selected?.dataset.end) {
        startDate.value = selected.dataset.start;
        endDate.value = selected.dataset.end;
    }
}

function applyCampaignDateBounds() {
    ['startDate', 'endDate'].forEach((id) => {
        const input = document.getElementById(id);

        if (!input) {
            return;
        }

        input.min = campaignPeriodMinDate;
        input.max = campaignPeriodMaxDate;
    });
}

function campaignLastVisibleMonth() {
    const today = lastCompleteDay();

    if (today.getFullYear() < 2026) {
        return new Date(2026, 0, 1);
    }

    if (today.getFullYear() > 2026) {
        return new Date(2026, 11, 1);
    }

    return new Date(2026, today.getMonth(), 1);
}

function clampCampaignDate(date) {
    const min = new Date(`${campaignPeriodMinDate}T00:00:00`);
    const max = new Date(`${campaignPeriodMaxDate}T00:00:00`);
    const value = new Date(date);

    if (value < min) {
        return toInputDate(min);
    }

    if (value > max) {
        return toInputDate(max);
    }

    return toInputDate(value);
}

function setLoadingState(isLoading) {
    document.getElementById('loadingMessage')?.classList.toggle('is-hidden', !isLoading);

    if (isLoading) {
        setUpdatedBadge(null, true);
        hideStatusMessage();
    }
}

function setUpdatedBadge(updatedAt = null, isLoading = false, metadata = {}) {
    const badge = document.getElementById('updatedBadge');
    if (!badge) {
        return;
    }

    if (!updatedAt) {
        badge.textContent = isLoading
            ? 'Cargando datos de Salesforce...'
            : 'Sin fecha de actualizacion de Salesforce';
        return;
    }

    const parsedDate = new Date(String(updatedAt).replace(' ', 'T'));
    const label = Number.isNaN(parsedDate.getTime())
        ? String(updatedAt)
        : new Intl.DateTimeFormat('es-ES', {
            dateStyle: 'short',
            timeStyle: 'short',
        }).format(parsedDate);

    badge.textContent = `Datos actualizados: ${label}`;
    badge.title = [
        metadata.salesforce_interests_synced_at ? `Salesforce Interests: ${metadata.salesforce_interests_synced_at}` : null,
        metadata.meta_synced_at ? `Meta Ads: ${metadata.meta_synced_at}` : null,
        metadata.google_synced_at ? `Google Ads: ${metadata.google_synced_at}` : null,
        metadata.attribution_built_at ? `Atribuciones: ${metadata.attribution_built_at}` : null,
        metadata.dataset_generated_at ? `Dataset generado: ${metadata.dataset_generated_at}` : null,
        metadata.dataset_timezone ? `Zona horaria: ${metadata.dataset_timezone}` : null,
    ].filter(Boolean).join('\n');
}

function renderLoadFailure(error) {
    const badge = document.getElementById('updatedBadge');
    if (badge) {
        badge.textContent = 'No se pudieron actualizar los datos de Salesforce';
    }

    campaignRows = [];
    currentRankings = {};
    currentDiagnostics = {};
    currentDiagnosticsKey = '';
    currentCharts = {};

    renderWarnings([]);
    renderKpisState('No se han podido cargar los datos de campañas.');
    renderCharts({});
    renderPlatformComparison([], 'No se han podido cargar los datos de campañas.');
    renderReviewCampaigns([], 'No se han podido cargar los datos de campañas.');
    renderDiagnostics({});
    renderRankings({}, 'No se han podido cargar los datos de campañas.');
    renderCampaignTables([], 'No se han podido cargar los datos de campañas.');
    showStatusMessage(loadErrorMessage(error), 'error');
}

function showStatusMessage(message, type = 'empty') {
    const empty = document.getElementById('emptyMessage');
    if (!empty) {
        return;
    }

    empty.classList.remove('is-error', 'is-empty');
    empty.classList.add(type === 'error' ? 'is-error' : 'is-empty');
    empty.classList.remove('is-hidden');
    empty.textContent = message;
}

function hideStatusMessage() {
    const empty = document.getElementById('emptyMessage');
    if (!empty) {
        return;
    }

    empty.classList.add('is-hidden');
    empty.classList.remove('is-error', 'is-empty');
}

function loadErrorMessage(error) {
    const message = String(error?.message || '').trim();

    if (!message || message.startsWith('Error cargando /informes/campanas/')) {
        return 'Error al cargar datos de campañas.';
    }

    return message;
}

function lastCompleteDay() {
    const date = new Date();
    date.setHours(0, 0, 0, 0);
    date.setDate(date.getDate() - 1);

    return date;
}

async function fetchJson(url, fallbackMessage = `Error cargando ${url}`, options = {}) {
    const response = await fetch(url, {
        headers: { Accept: 'application/json' },
        signal: options.signal,
    });
    const contentType = response.headers.get('content-type') || '';
    const isJson = contentType.includes('application/json');
    const payload = isJson
        ? await response.json().catch(() => null)
        : await response.text().catch(() => '');

    if (!response.ok) {
        throw new Error(extractApiErrorMessage(payload, fallbackMessage));
    }

    if (isJson) {
        return payload;
    }

    return payload ? JSON.parse(payload) : null;
}

function extractApiErrorMessage(payload, fallbackMessage) {
    if (payload && typeof payload === 'object' && typeof payload.message === 'string') {
        const message = payload.message.trim();

        if (message && message !== 'Server Error') {
            return message;
        }
    }

    if (typeof payload === 'string') {
        const message = payload.trim();

        if (message && message.length <= 180 && !message.startsWith('<')) {
            return message;
        }
    }

    return fallbackMessage;
}

function isAbortError(error) {
    return error?.name === 'AbortError';
}

function setParam(params, key, value) {
    if (value) {
        params.set(key, value);
    }
}

function appendArrayParam(params, key, values) {
    (values || []).forEach((value) => {
        if (value) {
            params.append(`${key}[]`, value);
        }
    });
}

function campaignLabel(row) {
    return row.display_campaign || row.campaign || row.campaign_name || row.campaign_acquired || row.campaign_id || '-';
}

function periodText(period) {
    if (!period) {
        return '-';
    }

    return `${formatDate(period.inicio)} a ${formatDate(period.fin)}`;
}

function toInputDate(date) {
    return [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, '0'),
        String(date.getDate()).padStart(2, '0'),
    ].join('-');
}

function monthKeyFromDate(value) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
}

function shiftMonthKey(monthKey, offset) {
    if (!monthKey || !offset) {
        return monthKey;
    }

    const [year, month] = monthKey.split('-').map(Number);
    const date = new Date(year, month - 1 + offset, 1);
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
}

function monthLabel(row) {
    const date = new Date(row.date || row.metric_date || row.month_key || row.metric_month || row.month || row.date_from || '');
    if (Number.isNaN(date.getTime())) {
        return row.label || '-';
    }

    return new Intl.DateTimeFormat('es-ES', { month: 'short', year: 'numeric' }).format(date);
}

function formatDate(value) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return value || '-';
    }

    return new Intl.DateTimeFormat('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric' }).format(date);
}

function formatShortDate(value) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return value || '-';
    }

    return new Intl.DateTimeFormat('es-ES', { day: '2-digit', month: '2-digit' }).format(date);
}

function formatDateTime(value) {
    const date = new Date(value);
    if (!value || Number.isNaN(date.getTime())) {
        return value || 'Sin datos';
    }

    return new Intl.DateTimeFormat('es-ES', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(date);
}

function formatNumber(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return 'Sin datos';
    }

    return numberFormatter.format(Number(value));
}

function formatMoney(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return 'Sin datos';
    }

    return moneyFormatter.format(Number(value));
}

function formatPercentRatio(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return 'Sin datos';
    }

    return `${(Number(value) * 100).toFixed(1)}%`;
}

function formatMultiplier(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return 'Sin datos';
    }

    return Number(value).toFixed(2);
}

function formatPlatform(value) {
    return {
        google_ads: 'Google Ads',
        meta: 'Meta Ads',
        salesforce: 'Salesforce',
    }[value] || value || '-';
}

function formatReviewValue(row) {
    return row.value !== null && row.value !== undefined ? (Number.isFinite(Number(row.value)) ? formatNumber(row.value) : String(row.value)) : 'Sin datos';
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function cellHtml([value, numeric, rawValue, columnKey]) {
    const classes = ['table-cell'];
    if (numeric) {
        classes.push('num');
    }

    return `<td class="${classes.join(' ')}" data-column="${escapeHtml(columnKey)}" title="${escapeHtml(rawValue ?? '')}">${escapeHtml(value)}</td>`;
}

function sortedCampaignRows(rows, tableType) {
    const sortState = tableSort[tableType] || tableSort.all;
    const direction = sortState.direction === 'asc' ? 1 : -1;

    return [...(rows || [])].sort((a, b) => {
        const left = a[sortState.key];
        const right = b[sortState.key];

        if (left === null || left === undefined || left === '') {
            return 1;
        }

        if (right === null || right === undefined || right === '') {
            return -1;
        }

        if (typeof left === 'number' || typeof right === 'number') {
            return (Number(left) - Number(right)) * direction;
        }

        return String(left).localeCompare(String(right), 'es', { sensitivity: 'base' }) * direction;
    });
}

function metricBarHtml(label, value, max, formatter, tooltip = null, useLogScale = false) {
    const numericValue = Number(value);
    const hasNumericValue = Number.isFinite(numericValue) && numericValue > 0;
    const denominator = max > 0 ? max : 1;
    const width = useLogScale && hasNumericValue
        ? Math.max(4, (Math.log10(numericValue + 1) / Math.log10(denominator + 1)) * 100)
        : (hasNumericValue ? (numericValue / denominator) * 100 : 0);
    const displayValue = formatter(hasNumericValue || numericValue === 0 ? numericValue : null);

    return `
        <div class="campaign-metric-bar" ${tooltip ? `title="${escapeHtml(tooltip)}" data-tooltip="${escapeHtml(tooltip)}"` : ''}>
            <div>
                <span>${escapeHtml(label)}</span>
                <strong>${escapeHtml(displayValue)}</strong>
            </div>
            <i><b style="width:${Math.min(100, Math.max(0, width))}%"></b></i>
        </div>
    `;
}

function renderColumnsPopover() {
    const root = document.getElementById('columnsPopover');
    if (!root) {
        return;
    }

    root.innerHTML = columnDefinitions.map((column) => `
        <label class="column-option switch-option">
            <input type="checkbox" data-column-toggle="${escapeHtml(column.key)}" ${visibleColumns.includes(column.key) ? 'checked' : ''}>
            <span>${escapeHtml(column.label)}</span>
        </label>
    `).join('');
}

function renderRankingsPopover() {
    const root = document.getElementById('rankingsPopover');
    if (!root) {
        return;
    }

    root.innerHTML = rankingDefinitions.map((ranking) => `
        <label class="column-option switch-option">
            <input type="checkbox" data-ranking-toggle="${escapeHtml(ranking.key)}" ${visibleRankings.includes(ranking.key) ? 'checked' : ''}>
            <span>${escapeHtml(ranking.title)}</span>
        </label>
    `).join('');
}

function renderRankings(rankings, emptyMessage = '') {
    const root = document.getElementById('rankingsGrid');
    if (!root) {
        return;
    }

    currentRankings = rankings || {};
    const context = presentationCampaignContext(currentContext);
    const visible = rankingDefinitions.filter((ranking) => visibleRankings.includes(ranking.key) && rankingAllowedInContext(ranking.key, context));

    if (emptyMessage) {
        root.innerHTML = `<div class="empty-state">${escapeHtml(emptyMessage)}</div>`;
        return;
    }

    root.innerHTML = visible.length
        ? visible.map((ranking) => {
            const rows = rankingRowsForDisplay(currentRankings[ranking.key] || [], ranking);
            const max = Math.max(...rows.map((row) => Number(row[ranking.metric] || 0)), 0);
            const body = rows.length
                ? rows.map((row) => metricBarHtml(
                    campaignLabel(row),
                    row[ranking.metric],
                    max,
                    ranking.formatter,
                    rankingTooltip(row, ranking),
                )).join('')
                : '<div class="empty-state">Sin datos</div>';

            return `
                <article class="card panel">
                    <div class="panel-title compact">
                        <div>
                            <h2>${escapeHtml(ranking.title)}</h2>
                        </div>
                    </div>
                    <div class="campaign-bar-list">${body}</div>
                </article>
            `;
        }).join('')
        : '<div class="empty-state">Sin rankings visibles</div>';
}

function rankingRowsForDisplay(rows, ranking) {
    if (!Array.isArray(rows)) {
        return [];
    }

    if (ranking.key === 'best_cost_per_sale') {
        return rows.filter((row) => Number(row.sales || 0) > 0 && Number(row.spend || 0) > 0 && Number(row.cost_per_sale || 0) > 0);
    }

    if (ranking.key === 'best_cost_per_purchase') {
        return rows.filter((row) => Number(row.purchases || 0) > 0 && Number(row.spend || 0) > 0 && Number(row.cost_per_purchase || 0) > 0);
    }

    return rows;
}

function rankingTooltip(row, ranking) {
    if (ranking.key !== 'review_campaigns') {
        return null;
    }

    return [row.reason, row.detail, row.metric ? `${row.metric}: ${formatReviewValue(row)}` : null]
        .filter(Boolean)
        .join('\n');
}

function renderDiagnostics(diagnostics) {
    const root = document.getElementById('campaignDiagnostics');
    if (!root) {
        return;
    }

    const entries = [
        ['Última sync Meta', formatDateTime(diagnostics.last_meta_sync)],
        ['Última sync Google Ads', formatDateTime(diagnostics.last_google_sync)],
        ['Filas Meta', formatNumber(diagnostics.meta_metric_rows)],
        ['Filas Google Ads', formatNumber(diagnostics.google_metric_rows)],
    ];

    if (entries.every(([, value]) => value === 'Sin datos')) {
        root.innerHTML = '<div class="empty-state">Sin diagnóstico disponible</div>';
        return;
    }

    root.innerHTML = entries.map(([label, value]) => `
        <div class="diagnostic-item">
            <span>${escapeHtml(label)}</span>
            <strong>${escapeHtml(value)}</strong>
        </div>
    `).join('');
}

function renderCampaignTables(rows, emptyMessage = null) {
    const root = document.getElementById('campaignTables');
    if (!root) {
        return;
    }

    const context = presentationCampaignContext(currentContext);
    const titleMap = {
        venta: ['Detalle de campañas de venta', 'Vista detallada de campañas orientadas a venta'],
        tasacion: ['Detalle de campañas de tasación', 'Vista detallada de campañas orientadas a tasación'],
        exposicion: ['Detalle de campañas de exposición', 'Vista detallada de campañas orientadas a exposición'],
        branding: ['Detalle de campañas de branding', 'Vista detallada de campañas orientadas a branding'],
        otros: ['Detalle de campañas', 'Vista general de campañas no clasificadas en un tipo principal'],
        all: ['Detalle de campañas', 'Vista general por tipos de campaña'],
    };
    const [title, subtitle] = titleMap[context] || titleMap.all;

    root.innerHTML = renderCampaignTable(context, title, subtitle, rows || [], emptyMessage);
    renderColumnsPopover();
    applyColumnVisibility();
    syncCampaignTableScrollWidth();
}

function renderCampaignTable(tableType, title, subtitle, rows, emptyMessage = null) {
    const columns = activeColumns(tableType);
    const sortedRows = sortedCampaignRows(rows, tableType);
    const emptyColspan = Math.max(columns.length, 1);
    const headers = columns.map((column) => `
        <th data-column="${escapeHtml(column.key)}" ${column.numeric ? 'class="num"' : ''} data-sortable="true" data-key="${escapeHtml(column.key)}">
            ${escapeHtml(column.label)}
        </th>
    `).join('');
    const body = sortedRows.length
        ? sortedRows.map((row) => `
            <tr>
                ${columns.map((column) => {
                    const formatter = column.formatter || ((value) => value);
                    return cellHtml([
                        formatter(row[column.key], row),
                        column.numeric,
                        row[column.key],
                        column.key,
                    ]);
                }).join('')}
            </tr>
        `).join('')
        : `<tr><td colspan="${emptyColspan}">${escapeHtml(emptyMessage || 'No hay campañas para los filtros seleccionados.')}</td></tr>`;

    return `
        <article class="card panel campaign-table-card">
            <div class="panel-title compact">
                <div>
                    <h2>${escapeHtml(title)}</h2>
                    <div class="small">${escapeHtml(subtitle)}</div>
                </div>
                <div class="campaign-table-actions">
                    <button type="button" class="main-tab campaign-detail-toggle" data-toggle-campaign-detail>
                        ${campaignDetailsVisible ? 'Ocultar detalle de campañas' : 'Mostrar detalle de campañas'}
                    </button>
                    <div class="columns-menu">
                        <button type="button" class="main-tab" id="columnsToggle">Columnas</button>
                        <div class="columns-popover card is-hidden" id="columnsPopover"></div>
                    </div>
                    ${window.reportUserCanExport ? '<a class="main-tab" id="exportCsv" href="/informes/campanas/export/campaigns.csv">Export CSV</a>' : ''}
                    ${window.reportUserCanAudit ? '<a class="main-tab" id="exportAttributionAudit" href="/informes/campanas/export/attributions.csv">Auditar atribuciones</a>' : ''}
                </div>
            </div>
            <div class="${campaignDetailsVisible ? '' : 'is-hidden'}" id="campaignDetailBody">
                <div class="table-scroll-proxy" data-table-scroll-proxy="${escapeHtml(tableType)}"><div></div></div>
                <div class="table-wrap" data-table-wrap="${escapeHtml(tableType)}">
                    <table data-table-type="${escapeHtml(tableType)}">
                        <thead>
                            <tr>${headers}</tr>
                        </thead>
                        <tbody>
                            ${body}
                        </tbody>
                    </table>
                </div>
            </div>
        </article>
    `;
}

function loadVisibleColumns() {
    try {
        const saved = JSON.parse(localStorage.getItem(columnsStorageKey) || 'null');
        const defaults = columnDefinitions.filter((column) => column.visible).map((column) => column.key);

        if (!Array.isArray(saved) || !saved.length) {
            return defaults;
        }

        const known = saved.filter((key) => columnDefinitions.some((column) => column.key === key));
        return known.length ? known : defaults;
    } catch (error) {
        return columnDefinitions.filter((column) => column.visible).map((column) => column.key);
    }
}

function persistVisibleColumns() {
    localStorage.setItem(columnsStorageKey, JSON.stringify(visibleColumns));
}

function persistVisibleRankings() {
    localStorage.setItem(rankingsStorageKey, JSON.stringify(visibleRankings));
}
