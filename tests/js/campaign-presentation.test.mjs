import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import { advertisingEligibility, advertisingDetailRows, detailMoneyPresentation, historicalStatusPresentation, activityMetrics, hasDetailActivity, costDisplay, costDenominators, costPresentation, contiguousChartSegments } from '../../resources/js/reports/campaign-presentation.js';

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
    Intl, advertisingEligibility, advertisingDetailRows, detailMoneyPresentation, historicalStatusPresentation, hasDetailActivity, costDisplay, costDenominators, costPresentation, contiguousChartSegments,
    localStorage: { getItem: () => null },
    document: { addEventListener() {}, getElementById: () => null },
    window: { reportUserCanExport: true, reportUserCanAudit: true },
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
        assert.ok(html.includes(expected));
        assert.ok(html.includes('No representa una consulta actual'));
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
    assert.ok(blade.includes('no verifica el estado actual'));
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
    memory.set('hrmotor_campaign_columns_v6', JSON.stringify(['campaign']));
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
