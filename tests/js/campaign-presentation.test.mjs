import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import { activityMetrics, hasDetailActivity, costDisplay, costDenominators } from '../../resources/js/reports/campaign-presentation.js';

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
    Intl, hasDetailActivity, costDisplay, costDenominators,
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
        { campaign_name: 'EMPTY', spend: 0 },
        { campaign_name: '<script>paused</script>', campaign_status_label: 'Inactiva', spend: 100, cost_per_result: null, result_count: 0 },
        { campaign_name: 'ZERO-COST', spend: 0, sales: 1, cost_per_result: 0, result_count: 1 },
    ];
    const before = JSON.stringify(sandbox.rows);
    vm.runInContext('renderCampaignTables(rows)', sandbox);
    assert.ok(!root.innerHTML.includes('EMPTY'));
    assert.ok(root.innerHTML.includes('&lt;script&gt;paused&lt;/script&gt;'));
    assert.ok(root.innerHTML.includes('Estado no disponible'));
    assert.ok(root.innerHTML.includes('No calculable'));
    assert.ok(root.innerHTML.includes(money(0)));
    assert.ok(root.innerHTML.includes('/informes/campanas/export/campaigns.csv'));
    assert.equal(JSON.stringify(sandbox.rows), before);
});

test('sorting, context columns and incomplete monthly costs retain their contracts', () => {
    assert.equal(vm.runInContext('numericChartValue(null)', sandbox), null);
    assert.equal(vm.runInContext('numericChartValue(0)', sandbox), 0);
    const chart = vm.runInContext("grafanaMetricChartHtml([{spend: 100, result_count: 0, cost_per_result: null}], {key: 'cost_per_result', label: 'Coste', formatter: formatMoney})", sandbox);
    assert.ok(chart.includes('No calculable'));
    assert.ok(chart.includes('points=""'));
    assert.equal(vm.runInContext("columnAllowedInContext('cost_per_result', 'venta')", sandbox), false);
    assert.equal(vm.runInContext("columnAllowedInContext('cost_per_result', 'tasacion')", sandbox), false);
    assert.equal(vm.runInContext("columnAllowedInContext('cost_per_result', 'all')", sandbox), true);
    assert.equal(vm.runInContext('monthlyCostPerResultForRow({ spend: 100, sales: 0, purchases: 0 })', sandbox), null);
    assert.equal(vm.runInContext('monthlyCostPerResultForRow({ spend: 0, sales: 1, purchases: 0 })', sandbox), 0);
    assert.equal(vm.runInContext("sortedCampaignRows([{spend: 1}, {spend: 2}], 'all')[0].spend", sandbox), 2);
});

test('historical active, paused, removed, unknown and Salesforce states never confirm current activity', () => {
    for (const status of ['ACTIVE', 'ENABLED', 'PAUSED', 'REMOVED', null]) {
        sandbox.statusRow = { campaign_name: 'STATE', campaign_status: status, campaign_effective_status: status, campaign_status_label: 'Activa', leads_salesforce: 3 };
        const html = vm.runInContext("renderCampaignTable('all', 'Detalle', '', [statusRow])", sandbox);
        assert.ok(html.includes('Estado no disponible'));
        assert.ok(!html.includes('>Activa<'));
    }
    sandbox.statusRow = { campaign_name: 'SF', platform: 'salesforce', campaign_source_type: 'salesforce_campaign_without_spend', leads_salesforce: 1 };
    const html = vm.runInContext("renderCampaignTable('all', 'Detalle', '', [statusRow])", sandbox);
    assert.ok(html.includes('Estado no disponible'));
    assert.ok(html.includes('No aplica'));
});
