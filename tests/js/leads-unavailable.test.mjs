import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const dashboardScript = readFileSync(new URL('../../resources/js/reports/leads-dashboard.js', import.meta.url), 'utf8');
const octoberFilterScript = readFileSync(new URL('../../public/js/reports/october-2026-dashboard-filters.js', import.meta.url), 'utf8');
const dashboardTemplate = readFileSync(new URL('../../resources/views/reports/leads/index.blade.php', import.meta.url), 'utf8');

test('October copy stays concise and comparison without a source is hidden', () => {
    assert.match(dashboardTemplate, /value="october_2026"[^>]*>Octubre 2026<\/option>/);
    assert.match(dashboardTemplate, /Octubre 2026 · datos reconstruidos/);
    assert.match(dashboardTemplate, /id="comparisonPeriodLabel"/);
    assert.match(dashboardTemplate, /class="card period-card @if\(\$isOctoberProvisional \?\? false\) is-hidden @endif"/);
    assert.match(dashboardTemplate, /class="card panel @if\(\$isOctoberProvisional \?\? false\) is-hidden @endif"/);
    assert.doesNotMatch(dashboardTemplate, /provisional y no certificado|snapshot histórico|\bF2M?\b|\bF5\b/i);
    assert.match(dashboardScript, /data\.provisional/);
    assert.doesNotMatch(dashboardScript, /provisional no certificado|snapshot histórico|requiere actividad histórica acreditada/i);
});

test('the October unassigned card labels observed technical owners without claiming commercial eligibility', () => {
    const { context } = createDashboardContext(async () => ({ ok: true, json: async () => ({}) }));
    const cards = vm.runInContext('provisionalKpiCards({ leads_unassigned: 2 })', context);
    const unassigned = cards.find((card) => card.label === 'Intereses sin asignar');
    assert.equal(unassigned.value, '2');
    assert.match(unassigned.hint, /propietario técnico observado/);
    const unavailable = vm.runInContext('provisionalKpiCards({ leads_unassigned: null })', context)
        .find((card) => card.label === 'Intereses sin asignar');
    assert.match(unavailable.hint, /Sin datos suficientes/);
});

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

function createDashboardContext(fetch, {
    initialLeadPeriod = null,
    periodOptions = ['last_30_days', 'current_month', 'previous_month', 'october_2026', 'november_2026', 'custom'],
} = {}) {
    const elements = new Map();
    const navigations = [];
    const documentListeners = new Map();
    const element = (id) => {
        if (!elements.has(id)) {
            const listeners = new Map();
            elements.set(id, {
                value: '',
                options: id === 'period' ? periodOptions.map((value) => ({ value })) : [],
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
                insertAdjacentHTML(_position, html) {
                    this.innerHTML += html;
                },
            });
        }

        return elements.get(id);
    };

    const context = vm.createContext({
        document: {
            addEventListener: (name, listener) => documentListeners.set(name, listener),
            getElementById: element,
            querySelectorAll: () => [],
        },
        window: {
            localStorage: { getItem: () => null, setItem() {} },
            reportUserCanAudit: false,
            initialLeadPeriod,
            isOctoberProvisional: false,
            location: {
                origin: 'https://informes.example.test',
                assign: (url) => navigations.push(url),
            },
        },
        fetch,
        AbortController,
        URL,
        URLSearchParams,
        Intl,
        Map,
        Set,
        console,
    });

    vm.runInContext(dashboardScript, context);

    return {
        context,
        element,
        navigations,
        start: () => documentListeners.get('DOMContentLoaded')?.(),
    };
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

test('October provisional controls send exact combined dimensions instead of filtering rendered labels', () => {
    const { context, element } = createDashboardContext(async () => ({ ok: true, json: async () => ({}) }));
    const octoberFilters = [
        ['octoberGroup', 'group', 'Grupo Madrid'],
        ['octoberMedium', 'medium', 'Orgánico'],
        ['octoberChannel', 'channel', 'Formulario'],
        ['octoberStatus', 'status', 'Potencial'],
    ].map(([id, dimension, value]) => {
        const control = element(id);
        control.dataset = { octoberFilter: dimension };
        control.value = value;

        return control;
    });

    context.document.querySelectorAll = (selector) => selector === '[data-october-filter]' ? octoberFilters : [];
    vm.runInContext(octoberFilterScript, context);

    ['octoberGroup', 'octoberMedium', 'octoberChannel', 'octoberStatus'].forEach((id) => {
        assert.match(dashboardTemplate, new RegExp(`id="${id}"`));
    });
    element('period').value = 'october_2026';
    element('leadDelegation').value = 'Madrid General';
    element('portal').value = 'Web';

    const query = new URLSearchParams(vm.runInContext('currentFilters()', context));
    assert.equal(query.get('period'), 'october_2026');
    assert.equal(query.get('lead_delegation'), 'Madrid General');
    assert.equal(query.get('portal'), 'Web');
    assert.equal(query.get('group'), 'Grupo Madrid');
    assert.equal(query.get('medium'), 'Orgánico');
    assert.equal(query.get('channel'), 'Formulario');
    assert.equal(query.get('status'), 'Potencial');
});

test('official and provisional KPI cards preserve their own descriptions and unavailable values', () => {
    const { context, element } = createDashboardContext(async () => ({ ok: true, json: async () => ({}) }));
    const kpis = {
        leads_totales: 0,
        convertidos: 2,
        descartados: 1,
        potenciales: 3,
        conversion_pct: 40,
        descarte_pct: 20,
        without_eligible_commercial: null,
        without_commercial_delegation: null,
        unclassified: 4,
        leads_unassigned: null,
        potenciales_sin_trabajar: null,
        gestionados: null,
        gestionados_pct: null,
        llamadas: null,
        llamadas_pct: null,
        formularios: null,
        formularios_pct: null,
    };

    vm.runInContext(`renderKpis(${JSON.stringify(kpis)}, false)`, context);
    assert.match(element('summaryKpis').innerHTML, /Bolsa viva/);
    assert.match(element('summaryKpis').innerHTML, /Solo Status Potencial/);
    assert.doesNotMatch(element('summaryKpis').innerHTML, /requiere actividad histórica acreditada/);

    vm.runInContext(`renderKpis(${JSON.stringify(kpis)}, true)`, context);
    assert.match(element('summaryKpis').innerHTML, /Muestra del período validado/);
    assert.match(element('summaryKpis').innerHTML, /Sin datos suficientes/);
    assert.doesNotMatch(element('summaryKpis').innerHTML, /provisional|no certificado|snapshot histórico|F5/i);
});

test('the initial period is applied before the first dashboard request', async (t) => {
    const periods = ['current_month', 'previous_month', 'custom', 'last_30_days', 'october_2026', 'november_2026', null];

    for (const initialLeadPeriod of periods) {
        await t.test(initialLeadPeriod || 'default', async () => {
            const calls = [];
            const { context, element, start } = createDashboardContext(async (url) => {
                calls.push(url);

                return {
                    ok: true,
                    json: async () => url.includes('/summary?') ? { generation_id: 42, filters: {} } : {},
                };
            }, { initialLeadPeriod });

            element('period').value = 'last_30_days';
            element('customPeriods').classList.add('is-hidden');
            vm.runInContext(`
                bindTabs = bindFilters = bindResetFilters = bindTableSorting = bindLeadCommercialTabs = () => {};
                initLeadCommercialColumns = initLeadCommercialZoneColumns = initLeadCommercialDelegationColumns = () => {};
                bindLeadCommercialSearch = bindReconciliationAudit = bindLeadReloadRetry = () => {};
                renderSummary = renderFilterOptions = renderCommercialZones = renderCommercialDelegations = renderCommercials = renderDelegations = renderPortals = () => {};
            `, context);

            await start();

            const expectedPeriod = initialLeadPeriod || 'last_30_days';
            assert.equal(element('period').value, expectedPeriod);
            assert.equal(
                new URL(calls[0], 'https://informes.example.test').searchParams.get('period'),
                expectedPeriod
            );
            assert.equal(hasClass(element('customPeriods'), 'is-hidden'), expectedPeriod !== 'custom');
            assert.equal(element('currentStart').value, '');
            assert.equal(element('currentEnd').value, '');
        });
    }

    await t.test('does not apply October when the authorized option is absent', async () => {
        const calls = [];
        const { context, element, start } = createDashboardContext(async (url) => {
            calls.push(url);

            return {
                ok: true,
                json: async () => url.includes('/summary?') ? { generation_id: 42, filters: {} } : {},
            };
        }, {
            initialLeadPeriod: 'october_2026',
            periodOptions: ['last_30_days', 'current_month', 'previous_month', 'custom'],
        });

        element('period').value = 'last_30_days';
        vm.runInContext(`
            bindTabs = bindFilters = bindResetFilters = bindTableSorting = bindLeadCommercialTabs = () => {};
            initLeadCommercialColumns = initLeadCommercialZoneColumns = initLeadCommercialDelegationColumns = () => {};
            bindLeadCommercialSearch = bindReconciliationAudit = bindLeadReloadRetry = () => {};
            renderSummary = renderFilterOptions = renderCommercialZones = renderCommercialDelegations = renderCommercials = renderDelegations = renderPortals = () => {};
        `, context);

        await start();

        assert.equal(element('period').value, 'last_30_days');
        assert.equal(new URL(calls[0], 'https://informes.example.test').searchParams.get('period'), 'last_30_days');
    });
});

test('leaving October provisional and resetting filters reloads every official dashboard period', async () => {
    const calls = [];
    const { context, element, navigations } = createDashboardContext(async (...args) => {
        calls.push(args);

        return { ok: true, json: async () => ({}) };
    });
    context.window.isOctoberProvisional = true;
    vm.runInContext('bindFilters(); bindResetFilters();', context);

    const period = element('period');
    for (const officialPeriod of ['current_month', 'previous_month', 'custom', 'last_30_days']) {
        period.value = officialPeriod;
        await period.listener('change')({ currentTarget: period });
    }

    assert.deepEqual(navigations, [
        '/informes/leads?period=current_month',
        '/informes/leads?period=previous_month',
        '/informes/leads?period=custom',
        '/informes/leads?period=last_30_days',
    ]);
    assert.equal(calls.length, 0);

    await element('resetFilters').listener('click')();
    assert.deepEqual(navigations, [
        '/informes/leads?period=current_month',
        '/informes/leads?period=previous_month',
        '/informes/leads?period=custom',
        '/informes/leads?period=last_30_days',
        '/informes/leads?period=last_30_days',
    ]);
    assert.equal(calls.length, 0);
});

test('selecting November navigates to its authorized monthly dashboard', async () => {
    const { context, element, navigations } = createDashboardContext(async () => ({ ok: true, json: async () => ({}) }));
    vm.runInContext('bindFilters();', context);
    const period = element('period');
    period.value = 'november_2026';
    await period.listener('change')({ currentTarget: period });

    assert.deepEqual(navigations, ['/informes/leads?period=november_2026']);
});
