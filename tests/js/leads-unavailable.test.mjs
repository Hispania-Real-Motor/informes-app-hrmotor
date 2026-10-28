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
