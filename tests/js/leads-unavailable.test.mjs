import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const dashboardScript = readFileSync(new URL('../../resources/js/reports/leads-dashboard.js', import.meta.url), 'utf8');
const dashboardTemplate = readFileSync(new URL('../../resources/views/reports/leads/index.blade.php', import.meta.url), 'utf8');

function createClassList() {
    const classes = new Set();

    return {
        add: (...items) => items.forEach((item) => classes.add(item)),
        remove: (...items) => items.forEach((item) => classes.delete(item)),
        toggle: (item, force) => {
            const shouldAdd = force === undefined ? !classes.has(item) : force;
            classes[shouldAdd ? 'add' : 'delete'](item);
            return shouldAdd;
        },
        contains: (item) => classes.has(item),
    };
}

function createDashboardContext(fetch) {
    const elements = new Map();
    const element = (id) => {
        if (!elements.has(id)) {
            const listeners = new Map();
            elements.set(id, {
                value: '',
                innerHTML: 'old figures',
                textContent: '',
                title: '',
                disabled: false,
                classList: createClassList(),
                attributes: new Map(),
                addEventListener: (name, listener) => listeners.set(name, listener),
                listener: (name) => listeners.get(name),
                setAttribute(name, value) {
                    this.attributes.set(name, value);
                },
            });
        }

        return elements.get(id);
    };

    const context = vm.createContext({
        document: {
            addEventListener() {},
            getElementById: element,
            querySelectorAll: () => [],
        },
        window: {
            localStorage: { getItem: () => null, setItem() {} },
            reportUserCanAudit: false,
        },
        fetch,
        AbortController,
        URLSearchParams,
        Intl,
        Map,
        Set,
        console,
    });

    vm.runInContext(dashboardScript, context);

    return { context, element };
}

function hasClass(element, className) {
    return element.classList.contains(className);
}

test('the dashboard state IDs used by JavaScript exist in the Blade template', () => {
    [
        'leadLoadState',
        'leadLoadingState',
        'leadUnavailableState',
        'leadGenericErrorState',
        'retryLeadLoad',
        'leadReportContent',
    ].forEach((id) => {
        assert.match(dashboardTemplate, new RegExp(`id="${id}"`));
    });

    assert.doesNotMatch(dashboardScript, /loadingMessage/);
});

test('the loading state is visible and clears previous figures while the initial request is pending', async () => {
    let resolveSummary;
    const { context, element } = createDashboardContext((url) => {
        if (url.includes('/summary?')) {
            return new Promise((resolve) => {
                resolveSummary = resolve;
            });
        }

        return Promise.resolve({ ok: true, json: async () => ({}) });
    });

    vm.runInContext('renderSummary = renderFilterOptions = renderCommercialZones = renderCommercialDelegations = renderCommercials = renderDelegations = renderPortals = () => {};', context);
    const loading = vm.runInContext('reloadAllData()', context);

    assert.equal(hasClass(element('leadLoadState'), 'is-hidden'), false);
    assert.equal(hasClass(element('leadLoadingState'), 'is-hidden'), false);
    assert.equal(hasClass(element('leadReportContent'), 'is-hidden'), true);
    assert.equal(element('leadLoadState').attributes.get('aria-busy'), 'true');
    assert.equal(element('summaryKpis').innerHTML, '');

    resolveSummary({ ok: true, json: async () => ({ generation_id: 42, filters: {} }) });
    await loading;

    assert.equal(hasClass(element('leadLoadState'), 'is-hidden'), true);
    assert.equal(hasClass(element('leadReportContent'), 'is-hidden'), false);
});

test('a known 503 clears old figures and shows the certified-data availability state', async () => {
    const { context, element } = createDashboardContext(async () => ({
        ok: false,
        status: 503,
        json: async () => ({ error: 'interest_dataset_unavailable' }),
    }));

    await vm.runInContext('reloadAllData()', context);

    assert.equal(element('summaryKpis').innerHTML, '');
    assert.equal(element('comparisonRows').innerHTML, '');
    assert.equal(element('commercialRows').innerHTML, '');
    assert.equal(element('delegationRows').innerHTML, '');
    assert.equal(element('portalRows').innerHTML, '');
    assert.equal(element('updatedBadge').textContent, 'Información temporalmente no disponible');
    assert.equal(hasClass(element('leadLoadState'), 'is-hidden'), false);
    assert.equal(hasClass(element('leadUnavailableState'), 'is-hidden'), false);
    assert.equal(hasClass(element('leadGenericErrorState'), 'is-hidden'), true);
    assert.equal(hasClass(element('leadReportContent'), 'is-hidden'), true);
    assert.equal(hasClass(element('leadLoadingState'), 'is-hidden'), true);
});

test('unexpected failures use the generic message without exposing endpoint details', async () => {
    const { context, element } = createDashboardContext(async () => ({
        ok: false,
        status: 500,
        json: async () => ({ message: 'Internal error at /informes/leads/data/summary' }),
    }));

    await vm.runInContext('reloadAllData()', context);

    assert.equal(element('updatedBadge').textContent, 'Información no disponible');
    assert.equal(hasClass(element('leadGenericErrorState'), 'is-hidden'), false);
    assert.equal(hasClass(element('leadUnavailableState'), 'is-hidden'), true);
    assert.equal(element('summaryKpis').innerHTML, '');
});

test('unexpected 503 payloads, invalid JSON, and connection errors use the generic safe state', async (t) => {
    const failures = [
        async () => ({ ok: false, status: 503, json: async () => { throw new SyntaxError('Unexpected token'); } }),
        async () => ({ ok: true, status: 200, json: async () => { throw new SyntaxError('Unexpected token'); } }),
        async () => { throw new TypeError('Network request failed for /informes/leads/data/summary'); },
    ];

    for (const fetch of failures) {
        await t.test('shows a generic state', async () => {
            const { context, element } = createDashboardContext(fetch);

            await vm.runInContext('reloadAllData()', context);

            assert.equal(hasClass(element('leadGenericErrorState'), 'is-hidden'), false);
            assert.equal(element('updatedBadge').textContent, 'Información no disponible');
        });
    }
});

test('the retry control reloads the dashboard and restores it after a successful response', async () => {
    let retrying = false;
    let resolveSummary;
    const { context, element } = createDashboardContext((url) => {
        if (!retrying) {
            return Promise.resolve({ ok: false, status: 503, json: async () => ({ error: 'interest_dataset_unavailable' }) });
        }

        if (url.includes('/summary?')) {
            return new Promise((resolve) => {
                resolveSummary = resolve;
            });
        }

        return Promise.resolve({ ok: true, json: async () => ({}) });
    });

    vm.runInContext('renderSummary = renderFilterOptions = renderCommercialZones = renderCommercialDelegations = renderCommercials = renderDelegations = renderPortals = () => {};', context);
    vm.runInContext('bindLeadReloadRetry()', context);
    await vm.runInContext('reloadAllData()', context);

    const retry = element('retryLeadLoad');
    retrying = true;
    const retryLoad = retry.listener('click')({ preventDefault() {}, currentTarget: retry });

    assert.equal(hasClass(element('leadLoadState'), 'is-hidden'), false);
    assert.equal(hasClass(element('leadLoadingState'), 'is-hidden'), false);
    assert.equal(hasClass(retry, 'is-hidden'), true);
    assert.equal(retry.disabled, true);

    resolveSummary({ ok: true, json: async () => ({ generation_id: 42, filters: {} }) });
    await retryLoad;

    assert.equal(hasClass(element('leadLoadState'), 'is-hidden'), true);
    assert.equal(hasClass(element('leadReportContent'), 'is-hidden'), false);
    assert.equal(retry.disabled, false);
    assert.equal(element('leadReportContent').attributes.get('aria-hidden'), 'false');
});

test('an aborted obsolete request cannot replace the current dashboard state', async () => {
    let calls = 0;
    const { context, element } = createDashboardContext((url, options) => {
        calls += 1;

        if (calls === 1) {
            return new Promise((resolve, reject) => {
                options.signal.addEventListener('abort', () => reject(Object.assign(new Error('Aborted'), { name: 'AbortError' })));
            });
        }

        return Promise.resolve({
            ok: false,
            status: 503,
            json: async () => ({ error: 'interest_dataset_unavailable' }),
        });
    });

    const first = vm.runInContext('reloadAllData()', context);
    await vm.runInContext('reloadAllData()', context);
    await first;

    assert.ok(calls >= 2);
    assert.equal(hasClass(element('leadUnavailableState'), 'is-hidden'), false);
    assert.equal(element('updatedBadge').textContent, 'Información temporalmente no disponible');
});

test('AJAX requests and CSV exports pin the generation returned by summary', async () => {
    const calls = [];
    const { context } = createDashboardContext(async (url, options) => {
        calls.push({ url, headers: options.headers });
        return { ok: true, json: async () => (url.includes('/summary?') ? { generation_id: 42, filters: {} } : {}) };
    });

    vm.runInContext('renderSummary = renderFilterOptions = renderCommercialZones = renderCommercialDelegations = renderCommercials = renderDelegations = renderPortals = () => {};', context);
    await vm.runInContext('reloadAllData()', context);

    assert.equal(calls.length, 4);
    assert.equal(calls[0].headers['X-Interest-Generation'], undefined);
    for (const call of calls.slice(1)) assert.equal(call.headers['X-Interest-Generation'], '42');
    assert.match(vm.runInContext("buildKpiAuditUrl('leads_totales')", context), /interest_generation=42/);
});
