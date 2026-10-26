import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import { advertisingEligibility, advertisingDetailRows, detailMoneyPresentation, currentStatusPresentation, historicalStatusPresentation, activityMetrics, hasDetailActivity, costDisplay, costDenominators, costPresentation, contiguousChartSegments } from '../../resources/js/reports/campaign-presentation.js';

const money = (value) => new Intl.NumberFormat('es-ES', { style: 'currency', currency: 'EUR' }).format(value);

test('only quantitative activity keeps a detail row; null is never coerced to confirmed zero', () => {
    const empty = Object.fromEntries(activityMetrics.map((key) => [key, 0]));
    assert.equal(hasDetailActivity(empty), false);
    assert.equal(hasDetailActivity({ campaign_status: 'ACTIVE', spend: null }), false);
    for (const key of activityMetrics) {
        assert.equal(hasDetailActivity({ ...empty, [key]: 1 }), true, key);
    }
    assert.equal(hasDetailActivity({ campaign_status: 'PAUSED', spend: 100 }), true);
    assert.equal(hasDetailActivity({ spend: 0, leads_salesforce: 1 }), true);
    assert.equal(hasDetailActivity({ spend: -1 }), true);
    assert.equal(hasDetailActivity({ spend: 'invalid' }), false);
});

test('costs distinguish zero, positive, zero denominator, unknown source and not applicable', () => {
    for (const [key, denominator] of Object.entries(costDenominators)) {
        assert.equal(costDisplay(0, { spend: 0, [denominator]: 1 }, key, money), money(0));
        assert.equal(costDisplay(100, { spend: 100, [denominator]: 1 }, key, money), money(100));
        assert.equal(costDisplay(null, { spend: 100, [denominator]: 0 }, key, money), 'No calculable');
        assert.equal(costDisplay(null, { spend: 100 }, key, money), 'No calculable');
        assert.equal(costDisplay(null, { spend: null, [denominator]: 1 }, key, money), 'Sin datos');
        assert.equal(costDisplay(null, { campaign_source_type: 'salesforce_campaign_without_spend' }, key, money), 'No aplica');
    }
});

// Execute the real dashboard rendering and sorting functions without a browser or network.
const source = fs.readFileSync(new URL('../../resources/js/reports/campaigns-dashboard.js', import.meta.url), 'utf8');
const root = { innerHTML: '' };
const sandbox = {
    Intl, URLSearchParams, advertisingEligibility, advertisingDetailRows, detailMoneyPresentation, currentStatusPresentation, historicalStatusPresentation, hasDetailActivity, costDisplay, costDenominators, costPresentation, contiguousChartSegments,
    localStorage: { getItem: () => null },
    document: { addEventListener() {}, getElementById: () => null, querySelectorAll: () => [] },
    window: { reportUserCanExport: true, reportUserCanAudit: true, clearTimeout() {}, setTimeout: () => 0 },
};
vm.createContext(sandbox);
vm.runInContext(source.replace(/^import .*;\r?\n/, ''), sandbox);

test('real detail rendering preserves shared rows, hides empty rows and escapes campaign names', () => {
    sandbox.document.getElementById = (id) => id === 'campaignTables' ? root : null;
    vm.runInContext('renderColumnsPopover = () => {}; applyColumnVisibility = () => {}; syncCampaignTableScrollWidth = () => {};', sandbox);
    sandbox.rows = [
        { platform: 'meta', campaign_name: 'EMPTY', spend: 0 },
        { platform: 'meta', campaign_name: '<script>paused</script>', campaign_status_label: 'Inactiva', spend: 100, cost_per_result: null, result_count: 0 },
        { platform: 'google_ads', campaign_name: 'ZERO-COST', spend: 0.001, purchases: 100, cost_per_purchase: 0 },
    ];
    const before = JSON.stringify(sandbox.rows);
    vm.runInContext('renderCampaignTables(rows)', sandbox);
    assert.ok(!root.innerHTML.includes('EMPTY'));
    assert.ok(root.innerHTML.includes('&lt;script&gt;paused&lt;/script&gt;'));
    assert.ok(root.innerHTML.includes('Estado no disponible'));
    assert.ok(root.innerHTML.includes('>—</td>'));
    assert.ok(root.innerHTML.includes(money(0)));
    assert.ok(root.innerHTML.includes('/informes/campanas/export/campaigns.csv'));
    assert.equal(JSON.stringify(sandbox.rows), before);
});

test('sorting, context columns and incomplete monthly costs retain their contracts', () => {
    assert.equal(vm.runInContext('numericChartValue(null)', sandbox), null);
    assert.equal(vm.runInContext('numericChartValue(0)', sandbox), 0);
    const chart = vm.runInContext("grafanaMetricChartHtml([{spend: 100, result_count: 0, cost_per_result: null}], {key: 'cost_per_result', label: 'Coste', formatter: formatMoney})", sandbox);
    assert.ok(chart.includes('No calculable'));
    assert.ok(!chart.includes('<polyline'));
    const originChart = vm.runInContext("grafanaMetricChartHtml([{campaign_source_type: 'salesforce_origin', spend: 0, result_count: 1, cost_per_result: 0}], {key: 'cost_per_result', label: 'Coste', formatter: formatMoney})", sandbox);
    assert.ok(originChart.includes('Sin datos'));
    assert.ok(!originChart.includes('<polyline'));
    assert.ok(!originChart.includes('class="grafana-point-dot '));
    assert.equal(vm.runInContext("columnAllowedInContext('cost_per_result', 'venta')", sandbox), false);
    assert.equal(vm.runInContext("columnAllowedInContext('cost_per_result', 'tasacion')", sandbox), false);
    assert.equal(vm.runInContext("columnAllowedInContext('cost_per_result', 'all')", sandbox), false);
    assert.equal(vm.runInContext('monthlyCostPerResultForRow({ spend: 100, sales: 0, purchases: 0 })', sandbox), null);
    assert.equal(vm.runInContext('monthlyCostPerResultForRow({ spend: 0, sales: 1, purchases: 0 })', sandbox), 0);
    assert.equal(vm.runInContext("sortedCampaignRows([{spend: 1}, {spend: 2}], 'all')[0].spend", sandbox), 2);
});

test('historical labels are presented only with platform evidence, without inferring from interests', () => {
    for (const [platform, field, state, label, expected] of [
        ['meta', 'campaign_effective_status', 'ACTIVE', 'Activa', 'Activa (histórico)'],
        ['meta', 'campaign_effective_status', 'PAUSED', 'Inactiva', 'Inactiva (histórico)'],
        ['meta', 'campaign_effective_status', 'DELETED', 'Inactiva', 'Inactiva (histórico)'],
        ['google_ads', 'campaign_status', 'ENABLED', 'Activa', 'Activa (histórico)'],
        ['google_ads', 'campaign_status', 'PAUSED', 'Inactiva', 'Inactiva (histórico)'],
        ['google_ads', 'campaign_status', 'REMOVED', 'Inactiva', 'Inactiva (histórico)'],
    ]) {
        sandbox.statusRow = { campaign_name: 'SYNTHETIC', platform, [field]: state, campaign_status_label: label };
        const before = JSON.stringify(sandbox.statusRow);
        const html = vm.runInContext("renderCampaignTable('all', 'Detalle', '', [statusRow])", sandbox);
        assert.equal(historicalStatusPresentation(sandbox.statusRow).text, expected);
        assert.ok(html.includes('Estado no disponible'));
        assert.ok(!html.includes('(histórico)</td>'));
        assert.equal(JSON.stringify(sandbox.statusRow), before);
    }
    for (const row of [
        { platform: 'meta', campaign_status_label: 'Inactiva', leads_salesforce: 3 },
        { platform: 'meta', campaign_status_label: null, leads_salesforce: 3 },
        { platform: 'google_ads', campaign_status: 'UNKNOWN', campaign_status_label: 'Inactiva' },
        { platform: 'google_ads', campaign_status: 'ENABLED', campaign_status_label: null },
        { platform: 'salesforce', campaign_status_label: 'Activa', leads_salesforce: 1 },
        { platform: 'meta', campaign_source_type: 'salesforce_origin', campaign_effective_status: 'ACTIVE', campaign_status_label: 'Activa' },
        { platform: 'salesforce', campaign_source_type: 'salesforce_campaign_without_spend', leads_salesforce: 1 },
        {},
    ]) {
        assert.equal(historicalStatusPresentation(row).text, 'Estado no disponible');
    }
});

test('only explicit provider campaign states admit the backend historical label', () => {
    const platforms = [
        ['meta', 'campaign_effective_status', ['ACTIVE', 'PAUSED', 'DELETED', 'ARCHIVED', 'IN_PROCESS', 'WITH_ISSUES']],
        ['google_ads', 'campaign_status', ['ENABLED', 'PAUSED', 'REMOVED']],
    ];
    for (const [platform, field, recognized] of platforms) {
        for (const state of recognized) {
            for (const label of ['Activa', 'Inactiva']) {
                const row = { platform, [field]: state, campaign_status_label: label };
                const before = JSON.stringify(row);
                assert.equal(historicalStatusPresentation(row).text, `${label} (histórico)`);
                assert.equal(JSON.stringify(row), before);
            }
        }
        const otherPlatformStates = platform === 'meta' ? ['ENABLED', 'REMOVED'] : ['ACTIVE', 'DELETED', 'ARCHIVED', 'WITH_ISSUES'];
        for (const state of [null, undefined, '', 'UNKNOWN', 'UNSPECIFIED', 'FUTURE_STATE', 'not-a-state',
            '<script>alert(1)</script>', 'paused', ' PAUSED ', 'PAUSED\n', 0, 1, true, {}, ['PAUSED'], ...otherPlatformStates]) {
            for (const label of ['Activa', 'Inactiva']) {
                sandbox.invalidStatusRow = { platform, [field]: state, campaign_status_label: label };
                assert.equal(historicalStatusPresentation(sandbox.invalidStatusRow).text, 'Estado no disponible');
                const html = vm.runInContext("renderCampaignTable('all', 'Detalle', '', [invalidStatusRow])", sandbox);
                assert.ok(html.includes('Estado no disponible'));
                assert.ok(!html.includes('>Inactiva (histórico)<'));
                assert.ok(!html.includes('>Activa (histórico)<'));
                assert.ok(!html.includes('<script>'));
            }
        }
        for (const source of ['salesforce_campaign_without_spend', 'salesforce_origin']) {
            assert.equal(historicalStatusPresentation({ platform, [field]: recognized[0], campaign_status_label: 'Activa', campaign_source_type: source }).text, 'Estado no disponible');
        }
    }
});

test('Salesforce origin zeros and incomplete investment never become verified zero costs', () => {
    for (const key of Object.keys(costDenominators)) {
        const denominator = costDenominators[key];
        for (const value of [null, 0, 25]) {
            assert.equal(costDisplay(value, { campaign_source_type: 'salesforce_origin', spend: 0, [denominator]: 2 }, key, money), 'Sin datos');
            assert.equal(costDisplay(value, { campaign_source_type: 'salesforce_campaign_without_spend', spend: 0, [denominator]: 2 }, key, money), 'No aplica');
        }
        assert.equal(costDisplay(0, { spend: null, [denominator]: 1 }, key, money), 'Sin datos');
        assert.equal(costDisplay(0, { spend: 0, [denominator]: 0 }, key, money), 'No calculable');
        assert.equal(costDisplay(-5, { spend: -10, [denominator]: 2 }, key, money), money(-5));
    }
});

test('cost explanations match zero divisors, unavailable investment and inapplicable metrics', () => {
    assert.match(costPresentation(null, { spend: 20, sales: 0 }, 'cost_per_sale', money).tooltip, /Divisor cero/);
    assert.match(costPresentation(0, { spend: null, sales: 1 }, 'cost_per_sale', money).tooltip, /Inversión desconocida/);
    assert.match(costPresentation(0, { campaign_source_type: 'salesforce_origin' }, 'cost_per_sale', money).tooltip, /sin inversión publicitaria verificable/);
    assert.match(costPresentation(null, { campaign_source_type: 'salesforce_campaign_without_spend' }, 'cost_per_sale', money).tooltip, /Métrica no aplicable/);
    sandbox.tooltipRow = { spend: 10, sales: 0, cost_per_sale: null };
    const html = vm.runInContext("renderCampaignTable('all', 'Detalle', '', [tooltipRow])", sandbox);
    assert.ok(html.includes('title="Divisor cero:'));
});

test('real charts split at gaps while preserving zero, negatives and terminal null', () => {
    const render = (values) => {
        sandbox.chartRows = values.map((value, index) => ({ date: '2026-0' + (index + 1) + '-01', spend: value }));
        return vm.runInContext("grafanaMetricChartHtml(chartRows, {key: 'spend', label: 'Inversión', className: 'spend', formatter: formatMoney})", sandbox);
    };
    const gap = render([10, null, 20]);
    const lines = [...gap.matchAll(/<polyline[^>]*points="([^"]*)"/g)].map((match) => match[1]);
    assert.equal(lines.length, 2);
    assert.ok(lines.every((points) => !points.includes(' ')), 'isolated months must not be connected');
    assert.equal((gap.match(/class="grafana-point-dot /g) || []).length, 2);
    assert.ok(!render([null, null, null]).includes('<polyline'));
    assert.match(render([10, null]), /grafana-panel-value">Sin datos/);
    const signed = render([-10, 0, 20]);
    assert.equal((signed.match(/<polyline/g) || []).length, 1);
    assert.equal((signed.match(/class="grafana-point-dot /g) || []).length, 3);
    const coordinates = [{value: -1}, {value: 0}, {value: null}, {value: 2}];
    assert.deepEqual(contiguousChartSegments(coordinates).map((segment) => segment.map((p) => p.value)), [[-1, 0], [2]]);
});

test('historical state selector is explicit without changing option values', () => {
    const blade = fs.readFileSync(new URL('../../resources/views/reports/campaigns/index.blade.php', import.meta.url), 'utf8');
    assert.ok(blade.includes('Estado histórico (filtro)'));
    assert.ok(blade.includes('value="active" selected>Activas según criterio histórico'));
    assert.ok(blade.includes('value="inactive">Inactivas según criterio histórico'));
    assert.ok(!blade.includes('id="campaignStatusHelp"'));
});


test('advertising eligibility requires verified positive spend and never infers it from commerce', () => {
    for (const platform of ['meta', 'google_ads']) {
        for (const spend of [1, 0.01, '2.50', '1e2']) assert.equal(advertisingEligibility({platform, spend}).eligible, true);
        for (const spend of [0, -1, null, undefined, '', ' ', 'bad', '0x10', NaN, Infinity, true, {}, []]) {
            assert.equal(advertisingEligibility({platform, spend, sales: 4, leads_salesforce: 20}).eligible, false);
        }
    }
    assert.equal(advertisingEligibility({platform: 'salesforce', spend: 100}).eligible, false);
    assert.equal(advertisingEligibility({platform: 'meta', campaign_source_type: 'salesforce_origin', spend: 100}).eligible, false);
    const source = [{platform: 'meta', spend: 10}, {platform: 'meta', spend: 0}, {platform: 'google_ads', spend: -1}, {platform: 'salesforce', spend: 100}];
    const before = JSON.stringify(source);
    const detail = advertisingDetailRows(source);
    assert.equal(detail.rows.length, 1);
    assert.equal(detail.excludedTotal, 3);
    assert.deepEqual(detail.excludedByReason, {zero_spend: 1, negative_spend: 1, other_platform: 1});
    assert.equal(JSON.stringify(source), before);
});

test('detail columns remove functional results, use purchase costs and migrate only their own preferences', () => {
    const memory = new Map([['hrmotor_campaign_columns_v5', JSON.stringify(['campaign', 'result_count', 'cost_per_result', 'sales'])], ['unrelated', 'keep']]);
    sandbox.localStorage = {getItem: (key) => memory.get(key) ?? null, setItem: (key, value) => memory.set(key, value)};
    const migrated = Array.from(vm.runInContext('loadVisibleColumns()', sandbox));
    assert.deepEqual(migrated, ['campaign', 'sales', 'cost_per_purchase']);
    assert.equal(memory.get('unrelated'), 'keep');
    assert.ok(memory.has('hrmotor_campaign_columns_v5'));
    memory.set('hrmotor_campaign_columns_v7', JSON.stringify(['campaign']));
    assert.deepEqual(Array.from(vm.runInContext('loadVisibleColumns()', sandbox)), ['campaign']);
    for (const context of ['all', 'tasacion', 'otros']) {
        assert.equal(vm.runInContext(`columnAllowedInContext('cost_per_purchase', '${context}')`, sandbox), true);
    }
    const html = vm.runInContext("renderCampaignTable('all', 'Detalle', '', [{spend: 10, purchases: 2, cost_per_purchase: 5}])", sandbox);
    assert.ok(!html.includes('data-column="result_count"'));
    assert.ok(!html.includes('data-column="cost_per_result"'));
    assert.equal((html.match(/Coste por compra/g) || []).length, 1);
});

test('only detail monetary cells use a dash while preserving reasons, valid zeros and negatives', () => {
    assert.equal(detailMoneyPresentation(0, {spend: 0, purchases: 1}, 'cost_per_purchase', money).text, money(0));
    assert.equal(detailMoneyPresentation(-5, {spend: -10, purchases: 2}, 'cost_per_purchase', money).text, money(-5));
    for (const row of [{spend: 10, purchases: 0}, {spend: 10}, {spend: null}, {campaign_source_type: 'salesforce_origin'}, {campaign_source_type: 'salesforce_campaign_without_spend'}]) {
        const display = detailMoneyPresentation(null, row, 'cost_per_purchase', money);
        assert.equal(display.text, '—');
        assert.ok(display.tooltip.length > 0);
    }
    assert.equal(detailMoneyPresentation(null, {}, 'sale_amount', money).text, '—');
    assert.equal(costDisplay(null, {spend: 10, purchases: 0}, 'cost_per_purchase', money), 'No calculable');
});

test('review cards exclude Salesforce and zero spend before limiting to eight', () => {
    const reviewRoot = {innerHTML: ''};
    sandbox.document.getElementById = (id) => id === 'reviewCampaigns' ? reviewRoot : null;
    sandbox.reviewRows = [
        ...Array.from({length: 8}, () => ({platform: 'salesforce', campaign: 'SF-EXCLUDED', spend: 1000})),
        {platform: 'meta', campaign: '<b>paid</b>', spend: 100},
        {platform: 'google_ads', campaign: 'NO-SPEND', spend: 0},
    ];
    const before = JSON.stringify(sandbox.reviewRows);
    vm.runInContext('renderReviewCampaigns(reviewRows)', sandbox);
    assert.ok(!reviewRoot.innerHTML.includes('SF-EXCLUDED'));
    assert.ok(!reviewRoot.innerHTML.includes('NO-SPEND'));
    assert.ok(reviewRoot.innerHTML.includes('&lt;b&gt;paid&lt;/b&gt;'));
    assert.equal((reviewRoot.innerHTML.match(/class="review-card"/g) || []).length, 1);
    assert.equal(JSON.stringify(sandbox.reviewRows), before);
});


test('real advertising detail keeps positive spend without conversions and reports exclusions', () => {
    const detailRoot = {innerHTML: ''};
    sandbox.document.getElementById = (id) => id === 'campaignTables' ? detailRoot : null;
    sandbox.detailRows = [
        {platform: 'meta', campaign_name: 'SPEND-NO-CONVERSIONS', spend: 20, sales: 0, purchases: 0},
        {platform: 'google_ads', campaign_name: 'PARTIAL', spend: 10, cost_per_purchase: null},
        {platform: 'salesforce', campaign_name: 'SF-HIDDEN', spend: 30},
        {platform: 'meta', campaign_name: 'COMMERCIAL-WITHOUT-SPEND', spend: 0, sales: 4, leads_salesforce: 9},
        {platform: 'meta', campaign_name: 'NEGATIVE', spend: -2},
        {platform: 'google_ads', campaign_name: 'UNKNOWN', spend: null},
    ];
    const before = JSON.stringify(sandbox.detailRows);
    vm.runInContext('renderCampaignTables(detailRows)', sandbox);
    for (const name of ['SPEND-NO-CONVERSIONS', 'PARTIAL']) assert.ok(detailRoot.innerHTML.includes(name));
    for (const name of ['SF-HIDDEN', 'COMMERCIAL-WITHOUT-SPEND', 'NEGATIVE', 'UNKNOWN']) assert.ok(!detailRoot.innerHTML.includes(name));
    assert.ok(detailRoot.innerHTML.includes('Filas excluidas del detalle publicitario: 4'));
    assert.ok(detailRoot.innerHTML.includes('inversión negativa: 1'));
    assert.equal(JSON.stringify(sandbox.detailRows), before);
    for (const value of [true, '0x10', [], {}]) {
        assert.equal(detailMoneyPresentation(value, {spend: 10, purchases: 2}, 'cost_per_purchase', money).text, '—');
    }
});


test('current status uses independent metadata, expires at 24 hours and never falls back to history', () => {
    const now = Date.parse('2026-10-25T12:00:00Z');
    const row = { platform: 'meta', campaign_status_label: 'Activa', current_status: {
        availability: 'fresh', label: 'Pausada', raw_status: 'PAUSED', verified_at: '2026-10-24T12:00:01Z',
    }};
    assert.equal(currentStatusPresentation(row, now).text, 'Pausada');
    assert.match(currentStatusPresentation(row, now).tooltip, /Europe\/Madrid/);
    assert.match(currentStatusPresentation(row, now).tooltip, /filtro conserva/);
    assert.equal(currentStatusPresentation(row, now + 1000).text, 'Estado no actualizado');
    row.current_status.availability = 'unavailable';
    assert.equal(currentStatusPresentation(row, now).text, 'Estado no disponible');
    row.current_status.availability = 'stale';
    assert.equal(currentStatusPresentation(row, now).text, 'Estado no actualizado');
    assert.equal(currentStatusPresentation({platform: 'meta', campaign_status_label: 'Activa'}, now).text, 'Estado no disponible');
    assert.equal(currentStatusPresentation({platform: 'salesforce'}, now).text, 'Estado no disponible');
    row.current_status.availability = 'fresh';
    row.current_status.verified_at = '2026-10-26T12:00:00Z';
    assert.equal(currentStatusPresentation(row, now).text, 'Estado no disponible');
});

test('real dashboard formatters group four digits, preserve precision, negatives and numeric sorting', () => {
    for (const [value, expected] of [[0, '0'], [9, '9'], [22, '22'], [999, '999'], [1000, '1.000'], [1234, '1.234'], [4959, '4.959'], [5040, '5.040'], [9999, '9.999'], [10000, '10.000'], [301331, '301.331'], [1234567, '1.234.567'], [-1234.5, '-1.234,5']]) {
        assert.equal(vm.runInContext(`formatNumber(${value})`, sandbox), expected);
        assert.equal(vm.runInContext(`formatAxisValue(${value})`, sandbox), expected);
    }
    for (const [value, expected] of [[0.14, '0,14'], [0, '0,00'], [1000, '1.000,00'], [1234.56, '1.234,56'], [43286.42, '43.286,42'], [-1234.5, '-1.234,50'], [1000000, '1.000.000,00']]) {
        const formatted = vm.runInContext(`formatMoney(${value})`, sandbox);
        assert.equal(formatted.replace(/\s/g, ' '), expected + ' €');
        assert.equal(vm.runInContext(`formatCompactAxisValue(${value}, true)`, sandbox), formatted);
    }
    assert.equal(vm.runInContext('formatPercentRatio(12.345)', sandbox), '1.234,5%');
    assert.equal(vm.runInContext('formatMultiplier(1234.5)', sandbox), '1.234,50');
    assert.equal(vm.runInContext('formatMoney(null)', sandbox), 'Sin datos');
    assert.equal(vm.runInContext('formatReviewValue({value: "1234 / 9999"})', sandbox), '1.234 / 9.999');
    sandbox.numericRows = [{spend: 9999}, {spend: 1000}, {spend: -1234}];
    vm.runInContext("tableSort.all = {key: 'spend', direction: 'asc'}", sandbox);
    assert.deepEqual(Array.from(vm.runInContext("sortedCampaignRows(numericRows, 'all').map(row => row.spend)", sandbox)), [-1234, 1000, 9999]);
    assert.equal(sandbox.numericRows[0].spend, 9999);
});

test('KPI audit action stays in the heading and grouped values reach real cards', () => {
    const kpis = {innerHTML: '', insertAdjacentHTML(_position, html) { this.innerHTML += html; }};
    sandbox.document.getElementById = (id) => id === 'summaryKpis' ? kpis : null;
    sandbox.window.reportUserCanAudit = true;
    vm.runInContext('renderKpis({spend: 43286.42, impressions: 4959, clicks: 5040, leads_salesforce: 301331})', sandbox);
    assert.match(kpis.innerHTML, /43\.286,42/);
    assert.match(kpis.innerHTML, /4\.959/);
    assert.match(kpis.innerHTML, /5\.040/);
    assert.match(kpis.innerHTML, /301\.331/);
    assert.match(kpis.innerHTML, /campaign-kpi-heading[\s\S]*kpi-actions[\s\S]*kpi-value/);
    sandbox.window.reportUserCanAudit = false;
    vm.runInContext('renderKpis({spend: 4959})', sandbox);
    assert.ok(!kpis.innerHTML.includes('kpi-audit-link'));
    assert.ok(kpis.innerHTML.includes('4.959'));
    sandbox.window.reportUserCanAudit = true;
});

test('platform comparison uses restrained identities and never fabricates an absent provider', () => {
    const panel = {classList: {remove() {}}};
    const root = {innerHTML: ''};
    sandbox.document.getElementById = (id) => id === 'platformComparisonPanel' ? panel : id === 'platformComparison' ? root : null;
    vm.runInContext("currentContext = 'all'; renderPlatformComparison([{platform:'meta',spend:43286.42,leads_salesforce:4959,opportunities:5040,result_count:22}])", sandbox);
    assert.equal((root.innerHTML.match(/platform-comparison-card--meta/g) || []).length, 1);
    assert.ok(!root.innerHTML.includes('platform-comparison-card--google'));
    assert.ok(root.innerHTML.includes('43.286,42'));
    assert.ok(root.innerHTML.includes('4.959'));
    vm.runInContext("renderPlatformComparison([{platform:'google_ads',spend:4959,result_count:0}])", sandbox);
    assert.ok(root.innerHTML.includes('platform-comparison-card--google'));
    assert.ok(!root.innerHTML.includes('platform-comparison-card--meta'));
});

test('sync diagnostic distinguishes operational success from old metrics and failed attempts', () => {
    const root = {innerHTML: ''};
    sandbox.document.getElementById = (id) => id === 'campaignDiagnostics' ? root : null;
    const checked = vm.runInContext("syncOperationPresentation({verified_at:'2026-10-26T10:00:00+00:00',last_attempt_status:'success'}, '2026-05-10 09:00:00', Date.parse('2026-10-26T12:00:00Z'))", sandbox);
    assert.ok(checked.value.includes('11:00'));
    assert.ok(checked.detail.includes('11:00'));
    assert.ok(checked.detail.includes('correcto'));
    const failed = vm.runInContext("syncOperationPresentation({verified_at:'2026-10-20T10:00:00+00:00',last_attempt_status:'failed'}, null, Date.parse('2026-10-26T12:00:00Z'))", sandbox);
    assert.ok(failed.value.includes('Antigua'));
    assert.ok(failed.detail.includes('fallido'));
    const unknown = vm.runInContext("syncOperationPresentation({last_attempt_status:'unknown'}, null)", sandbox);
    assert.equal(unknown.value, 'Sin ejecución verificada');
    assert.ok(unknown.detail.includes('no concluyente'));
    sandbox.diagnostics = {meta_sync_operation:{verified_at:null,last_attempt_status:null},google_sync_operation:{verified_at:'2026-10-26T10:00:00+00:00',last_attempt_status:'failed'},last_google_sync:'2026-05-10 09:00:00',meta_metric_rows:0,google_metric_rows:4959};
    vm.runInContext('renderDiagnostics(diagnostics)', sandbox);
    assert.ok(root.innerHTML.includes('Sin ejecución verificada'));
    assert.ok(root.innerHTML.includes('4.959'));
    assert.ok(root.innerHTML.includes('fallido'));
    assert.ok(root.innerHTML.includes('Último dato de métricas'));
});

test('current status is rendered and escaped, legacy columns migrate without altering identifiers', () => {
    const memory = new Map([['hrmotor_campaign_columns_v6', JSON.stringify(['campaign', 'campaign_status_label', 'spend'])]]);
    sandbox.localStorage = {getItem: key => memory.get(key) ?? null, setItem: (key, value) => memory.set(key, value)};
    assert.deepEqual(Array.from(vm.runInContext('loadVisibleColumns()', sandbox)), ['campaign', 'current_status', 'spend']);
    sandbox.currentRow = {platform: 'meta', campaign_id: '9007199254740993', spend: 1234.56, campaign_name: '<b>name</b>', current_status: {availability: 'fresh', verified_at: new Date().toISOString(), raw_status: 'PAUSED', label: '<img src=x>'}};
    const before = JSON.stringify(sandbox.currentRow);
    const html = vm.runInContext("renderCampaignTable('all', 'Detalle', '', [currentRow])", sandbox);
    assert.ok(html.includes('Estado actual'));
    assert.ok(html.includes('&lt;img src=x&gt;'));
    assert.ok(html.includes('1.234,56'));
    assert.equal(sandbox.currentRow.campaign_id, '9007199254740993');
    assert.ok(!html.includes('<img src=x>'));
    assert.equal(JSON.stringify(sandbox.currentRow), before);
});


test('open page expires only state cells without fetching or rebuilding the table', () => {
    const cell = { dataset: {statusExpiresAt: String(Date.now() - 1)}, textContent: 'Activa', title: 'Verificación previa.' };
    sandbox.document.querySelectorAll = () => [cell];
    const before = root.innerHTML;
    vm.runInContext('scheduleCurrentStatusExpiry()', sandbox);
    assert.equal(cell.textContent, 'Estado no actualizado');
    assert.match(cell.title, /24 horas/);
    assert.equal(cell.dataset.statusExpiresAt, undefined);
    assert.equal(root.innerHTML, before);
    sandbox.document.querySelectorAll = () => [];
});


test('real numeric table tooltips use the same grouped text without losing cost explanations', () => {
    sandbox.tooltipRow = {platform: 'meta', campaign_name: 'Synthetic', spend: 1234.56, clicks: 1234, purchases: 0, cost_per_purchase: null};
    const before = JSON.stringify(sandbox.tooltipRow);
    const html = vm.runInContext("renderCampaignTable('all', 'Detalle', '', [tooltipRow])", sandbox);
    assert.ok(html.includes('data-column="clicks" title="1.234">1.234</td>'));
    assert.ok(!html.includes('title="1234"'));
    assert.ok(html.includes('Divisor cero: no hay resultados para calcular el coste.'));
    assert.equal(JSON.stringify(sandbox.tooltipRow), before);
});

test('ambiguous economic identity never confirms an individual account status', () => {
    const result = currentStatusPresentation({ platform: 'meta', current_status: { identity_ambiguous: true, availability: 'fresh', label: 'Activa', verified_at: new Date().toISOString() } });
    assert.equal(result.text, 'Estado no disponible');
    assert.match(result.tooltip, /Identidad de cuenta ambigua/);
});

test('opening diagnostics again fetches fresh operational evidence for unchanged filters', async () => {
    const diagnosticRoot = {innerHTML: ''};
    sandbox.document.getElementById = (id) => id === 'campaignDiagnostics' ? diagnosticRoot : null;
    sandbox.AbortController = AbortController;
    sandbox.currentFilters = () => 'period=2026-10';
    const calls = [];
    sandbox.fetchJson = async (url, message, options) => {
        calls.push({url, options});
        return {diagnostics: {meta_sync_operation: {verified_at: null, last_attempt_status: 'failed'}}};
    };
    await vm.runInContext('loadDiagnostics()', sandbox);
    await vm.runInContext('loadDiagnostics()', sandbox);
    assert.equal(calls.length, 2);
    assert.ok(calls.every(({options}) => options.cache === 'no-store'));
    assert.ok(calls.every(({url}) => url.includes('include_diagnostics=1')));
});
