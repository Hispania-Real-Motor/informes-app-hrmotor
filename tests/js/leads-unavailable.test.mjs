import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

test('a 503 for uncertified interests clears old figures and shows the availability message', async () => {
    const elements = new Map();
    const element = (id) => {
        if (!elements.has(id)) {
            elements.set(id, {
                value: '', innerHTML: 'old figures', textContent: '',
                classList: { toggle() {}, add() {}, remove() {} },
            });
        }
        return elements.get(id);
    };
    const context = vm.createContext({
        document: { addEventListener() {}, getElementById: element },
        window: { localStorage: { getItem: () => null } },
        fetch: async () => ({ ok: false, status: 503, json: async () => ({ error: 'interest_dataset_unavailable' }) }),
        AbortController, URLSearchParams, Intl, Map, Set, console,
    });
    const script = readFileSync(new URL('../../resources/js/reports/leads-dashboard.js', import.meta.url), 'utf8');
    vm.runInContext(script, context);
    await vm.runInContext('reloadAllData()', context);

    assert.equal(element('kpiGrid').innerHTML, '');
    assert.equal(element('updatedBadge').textContent, 'Datos no disponibles');
    assert.match(element('emptyMessage').textContent, /no está disponible.*no están certificados/);
});


test('AJAX requests and CSV exports pin the generation returned by summary', async () => {
    const calls = [];
    const elements = new Map();
    const element = (id) => {
        if (!elements.has(id)) elements.set(id, { value: '', innerHTML: '', textContent: '', classList: { toggle() {}, add() {}, remove() {} } });
        return elements.get(id);
    };
    const context = vm.createContext({
        document: { addEventListener() {}, getElementById: element },
        window: { localStorage: { getItem: () => null } },
        fetch: async (url, options) => {
            calls.push({ url, headers: options.headers });
            return { ok: true, json: async () => url.includes('/summary?') ? { generation_id: 42 } : {} };
        },
        AbortController, URLSearchParams, Intl, Map, Set, console,
    });
    vm.runInContext(readFileSync(new URL('../../resources/js/reports/leads-dashboard.js', import.meta.url), 'utf8'), context);
    vm.runInContext('renderSummary = renderFilterOptions = renderCommercialZones = renderCommercialDelegations = renderCommercials = renderDelegations = renderPortals = () => {};', context);
    await vm.runInContext('reloadAllData()', context);
    assert.equal(calls.length, 4);
    assert.equal(calls[0].headers['X-Interest-Generation'], undefined);
    for (const call of calls.slice(1)) assert.equal(call.headers['X-Interest-Generation'], '42');
    assert.match(vm.runInContext("buildKpiAuditUrl('leads_totales')", context), /interest_generation=42/);
});
