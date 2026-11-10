const fmt = new Intl.NumberFormat('es-ES');
const tableSortState = new Map();
let callsReloadController = null;
let latestCallsReloadRequestId = 0;

document.addEventListener('DOMContentLoaded', async () => {
    bindTabs();
    bindFilters();
    bindResetFilters();
    bindTableSorting();
    const requestedPeriod = new URLSearchParams(window.location.search).get('period');
    if (requestedPeriod === 'october_2026' && document.getElementById('period')) {
        document.getElementById('period').value = requestedPeriod;
    }
    toggleCustomPeriods();
    await reloadAllData();
});

function bindTabs() {
    document.querySelectorAll('.main-tab').forEach((button) => {
        button.addEventListener('click', () => {
            const panelId = button.dataset.panel;
            document.querySelectorAll('.main-tab').forEach((item) => item.classList.remove('active'));
            document.querySelectorAll('.tab-panel').forEach((panel) => panel.classList.remove('active'));
            button.classList.add('active');
            document.getElementById(panelId)?.classList.add('active');
        });
    });
}

function bindFilters() {
    [
        'period',
        'team',
        'direction',
        'status',
        'origin',
        'delegation',
        'zone',
        'portal',
        'user',
        'currentStart',
        'currentEnd',
        'comparisonStart',
        'comparisonEnd',
    ].forEach((id) => {
        document.getElementById(id)?.addEventListener('change', async () => {
            if (id === 'period') {
                toggleCustomPeriods();
                syncPeriodUrl();
            }

            await reloadAllData();
        });
    });
}

function bindResetFilters() {
    document.getElementById('resetFilters')?.addEventListener('click', async () => {
        [
            'team',
            'direction',
            'status',
            'origin',
            'delegation',
            'zone',
            'portal',
            'user',
            'currentStart',
            'currentEnd',
            'comparisonStart',
            'comparisonEnd',
        ].forEach((id) => {
            const element = document.getElementById(id);

            if (element) {
                element.value = '';
            }
        });

        document.getElementById('period').value = 'last_30_days';
        syncPeriodUrl();
        toggleCustomPeriods();
        await reloadAllData();
    });
}

async function reloadAllData() {
    callsReloadController?.abort();
    callsReloadController = new AbortController();
    const requestId = ++latestCallsReloadRequestId;
    const requestOptions = { signal: callsReloadController.signal };
    const filters = currentFilters();
    setLoadingState(true);
    const auditExport = document.getElementById('callsAuditExport');
    if (auditExport) auditExport.href = `/informes/llamadas/export/audit.csv?${filters}`;

    try {
        const summary = await fetchJson(`/informes/llamadas/data/summary?${filters}`, requestOptions);
        if (requestId !== latestCallsReloadRequestId) return;
        renderSummary(summary);
        renderFilterOptions(summary.filters || {});

        const [agents, delegations, portals] = await Promise.all([
            fetchJson(`/informes/llamadas/data/agents?${filters}`, requestOptions),
            fetchJson(`/informes/llamadas/data/delegations?${filters}`, requestOptions),
            fetchJson(`/informes/llamadas/data/portals?${filters}`, requestOptions),
        ]);
        if (requestId !== latestCallsReloadRequestId) return;

        renderCommercialAgents(agents.commercials || []);
        renderSupportAgents('customerServiceRows', agents.customer_service || [], 'No hay datos de Atencion al Cliente para los filtros seleccionados.');
        renderSupportAgents('contactCenterRows', agents.contact_center || [], 'No hay datos de Contact Center para los filtros seleccionados.');
        renderAppraiserAgents(agents.appraisers || []);
        renderZones(delegations.zones || []);
        renderDelegations(delegations.delegations || delegations.items || []);
        renderPortals(portals.items || []);
    } catch (error) {
        if (error?.name !== 'AbortError' && requestId === latestCallsReloadRequestId) {
            showLoadError(error);
        }
    } finally {
        if (requestId === latestCallsReloadRequestId) {
            setLoadingState(false);
        }
    }
}

function renderSummary(data) {
    document.getElementById('updatedBadge').textContent = data.datos_actualizados
        ? `Datos actualizados: ${formatDateTime(data.datos_actualizados)}`
        : 'Datos actualizados: pendiente';
    document.getElementById('currentPeriodLabel').textContent = periodText(data.periodo_actual);
    document.getElementById('comparisonPeriodLabel').textContent = periodText(data.periodo_comparado);
    renderCallsReconciliation(data.reconciliation || {}, data.classification_rule_version);

    const empty = document.getElementById('emptyMessage');
    empty.classList.toggle('is-hidden', Boolean(data.ok));
    empty.textContent = data.message || 'No hay llamadas sincronizadas para el periodo seleccionado.';

    renderKpis(data.kpis || {});
    renderDashboardVisuals(data);
    renderComparison(data.comparativa || []);
    renderInsights(data.insights || []);
}

function renderCallsReconciliation(reconciliation, ruleVersion) {
    const root = document.getElementById('callsReconciliation');
    if (!root) return;
    const raw = Number(reconciliation.raw_type_call_tasks || 0);
    const included = Number(reconciliation.dashboard_call_object_tasks || 0);
    const excluded = Number(reconciliation.excluded_without_call_object || 0);
    root.classList.toggle('is-hidden', raw === 0 && included === 0 && excluded === 0);
    root.innerHTML = `
        <div class="panel-title">
            <div>
                <h2>Conciliacion del universo de llamadas</h2>
                <div class="small">Task Type=Call - sin CallObject = llamadas integradas analizadas.</div>
            </div>
        </div>
        <div class="campaign-diagnostics">
            <div class="diagnostic-item"><span>Tasks tipo Call</span><strong>${formatNumber(raw)}</strong></div>
            <div class="diagnostic-item"><span>Excluidas sin CallObject</span><strong>${formatNumber(excluded)}</strong></div>
            <div class="diagnostic-item"><span>Incluidas en dashboard</span><strong>${formatNumber(included)}</strong></div>
            <div class="diagnostic-item"><span>Version de reglas</span><strong>${escapeHtml(ruleVersion || 'legacy')}</strong></div>
        </div>
    `;
}

function renderKpis(kpis) {
    const root = document.getElementById('summaryKpis');
    const cards = [
        ['Tiempo medio conversacion', formatSeconds(kpis.average_talk_seconds), 'Solo llamadas atendidas', 'TM'],
        ['Desbordes', formatNumber(kpis.overflow_count), `${formatPercent(kpis.overflow_pct)} sobre base operativa`, 'OV'],
    ];

    root.innerHTML = '';
    cards.forEach(([label, value, hint, icon]) => {
        root.insertAdjacentHTML('beforeend', `
            <div class="card kpi call-kpi">
                <div class="ico">${escapeHtml(icon)}</div>
                <div>
                    <div class="kpi-label">${escapeHtml(label)}</div>
                    <div class="kpi-value">${escapeHtml(value)}</div>
                    <div class="kpi-hint">${escapeHtml(hint)}</div>
                </div>
            </div>
        `);
    });
}

function renderDashboardVisuals(data) {
    const kpis = data.kpis || {};
    const charts = data.charts || {};
    const rankings = data.rankings || {};

    renderDonut('answeredLostChart', charts.answered_vs_lost || [], ['#202a45', '#d52233']);
    renderDonut('originChart', charts.direct_vs_portal || [], ['#202a45', '#64748b']);
    renderBars('teamBars', charts.answered_by_team || []);
    renderOriginBreakdown(kpis);
    renderDailyEvolution(charts.daily_evolution || []);
    renderRankings(rankings);
}

function renderDonut(rootId, items, colors) {
    const root = document.getElementById(rootId);
    const total = items.reduce((sum, item) => sum + Number(item.value || 0), 0);
    let cursor = 0;
    const gradient = items.map((item, index) => {
        const value = total > 0 ? (Number(item.value || 0) / total) * 100 : 0;
        const start = cursor;
        cursor += value;
        return `${colors[index % colors.length]} ${start}% ${cursor}%`;
    }).join(', ');

    root.innerHTML = `
        <div class="donut" style="background: conic-gradient(${gradient || '#e5e7eb 0 100%'})">
            <span>${escapeHtml(total > 0 ? formatNumber(total) : '-')}</span>
        </div>
        <div class="donut-legend">
            ${items.map((item, index) => `
                <div class="legend-row">
                    <span><i style="background:${colors[index % colors.length]}"></i>${escapeHtml(item.label)}</span>
                    <strong>${formatCountPercentOfTotal(item.value, total)}</strong>
                </div>
            `).join('')}
        </div>
    `;
}

function renderBars(rootId, items) {
    const root = document.getElementById(rootId);
    const max = Math.max(1, ...items.map((item) => Number(item.value || 0)));
    const total = items.reduce((sum, item) => sum + Number(item.value || 0), 0);

    root.innerHTML = items.length
        ? items.map((item) => {
            const pct = Math.max(2, (Number(item.value || 0) / max) * 100);

            return `
                <div class="bar-metric">
                    <div class="bar-metric-head">
                        <span>${escapeHtml(item.label)}</span>
                        <strong>${formatCountPercentOfTotal(item.value, total)}</strong>
                    </div>
                    <div class="bar-track"><span style="width:${pct}%"></span></div>
                </div>
            `;
        }).join('')
        : '<div class="empty-state">Sin datos</div>';
}

function renderOriginBreakdown(kpis) {
    const root = document.getElementById('originBreakdown');
    const groups = [
        ['Global', kpis.total_calls, kpis.answered, kpis.not_answered],
        ['Directas a comercial', kpis.commercial_direct_calls, kpis.commercial_direct_answered, kpis.commercial_direct_lost],
        ['Portales', kpis.portal_calls, kpis.portal_answered, kpis.portal_lost],
    ];

    root.innerHTML = groups.map(([label, total, answered, lost]) => `
        <article class="card origin-card">
            <span>${escapeHtml(label)}</span>
            <strong>${escapeHtml(formatNumber(total))}</strong>
            <div class="origin-card-row"><em>Atendidas</em><b>${formatCountPercentOfTotal(answered, total)}</b></div>
            <div class="origin-card-row"><em>Perdidas</em><b>${formatCountPercentOfTotal(lost, total)}</b></div>
        </article>
    `).join('');
}

function renderDailyEvolution(rows) {
    const root = document.getElementById('dailyEvolution');
    const max = Math.max(1, ...rows.map((row) => Number(row.total_calls || 0)));

    root.innerHTML = rows.length
        ? rows.map((row) => {
            const height = Math.max(8, (Number(row.total_calls || 0) / max) * 100);

            const total = formatNumber(row.total_calls);
            const date = formatDate(row.date);

            return `
                <div
                    class="daily-bar"
                    title="${escapeHtml(date)}: ${escapeHtml(total)} llamadas"
                    data-tooltip="${escapeHtml(date)} · ${escapeHtml(total)} llamadas"
                    aria-label="${escapeHtml(date)} · ${escapeHtml(total)} llamadas"
                >
                    <span style="height:${height}%"></span>
                    <small>${escapeHtml(shortDate(row.date))}</small>
                </div>
            `;
        }).join('')
        : '<div class="empty-state">Sin datos diarios</div>';
}

function renderRankings(rankings) {
    const root = document.getElementById('callsRankings');
    const safeRankings = rankings || {};
    const groups = [
        ['Top portales por llamadas', safeRankings.top_portals_by_calls || [], 'portal', 'total_calls'],
        ['Top portales por perdidas', safeRankings.top_portals_by_lost || [], 'portal', 'not_answered'],
        ['Top comerciales por llamadas', safeRankings.top_commercials_by_calls || [], 'user_name', 'total_calls'],
        ['Top delegaciones por llamadas', safeRankings.top_delegations_by_calls || [], 'delegation', 'total_calls'],
        ['Top agentes por atendidas', safeRankings.top_agents_by_answered || [], 'user_name', 'answered'],
        ['Top desbordes por portal', safeRankings.top_overflows_by_portal || [], 'portal', 'overflow_count'],
    ];

    root.innerHTML = groups.map(([title, rows, labelKey, metricKey]) => {
        const items = rows.length
            ? rows.map((row) => `
                <div class="portal-row">
                    <span>${escapeHtml(row[labelKey] || '-')}</span>
                    <strong>${escapeHtml(formatNumber(row[metricKey]))}</strong>
                </div>
            `).join('')
            : '<div class="empty-state">Sin datos</div>';

        return `
            <article class="priority-item">
                <div class="priority-head"><b>${escapeHtml(title)}</b></div>
                <div class="portal-list">${items}</div>
            </article>
        `;
    }).join('');
}

function renderComparison(rows) {
    renderRows('comparisonRows', rows, [
        [(row) => row.metrica],
        [(row) => formatComparisonValue(row.periodo_actual, row.is_seconds), true, (row) => row.periodo_actual, true],
        [(row) => formatComparisonValue(row.periodo_comparado, row.is_seconds), true, (row) => row.periodo_comparado, true],
        [(row) => formatComparisonDiff(row.diferencia, row.is_seconds), true, (row) => row.diferencia, true],
    ], 'No hay datos para comparar.');
}

function renderInsights(items) {
    const root = document.getElementById('insights');
    root.innerHTML = '';

    if (!items.length) {
        root.innerHTML = '<div class="priority-item">No hay datos suficientes para generar conclusiones.</div>';
        return;
    }

    items.forEach((item) => {
        root.insertAdjacentHTML('beforeend', `<div class="priority-item">${escapeHtml(item)}</div>`);
    });
}

function renderCommercialAgents(rows) {
    renderMetricRows('commercialRows', rows, [
        [(row) => row.user_name || '-'],
        [(row) => row.delegation || '-'],
    ], 'No hay datos de comerciales para los filtros seleccionados.', {
        overflow: false,
        directPortalAnswered: true,
    });
}

function renderSupportAgents(rootId, rows, emptyMessage) {
    renderMetricRows(rootId, rows, [
        [(row) => row.user_name || '-'],
    ], emptyMessage, {
        overflow: true,
    });
}

function renderAppraiserAgents(rows) {
    renderMetricRows('appraiserRows', rows, [
        [(row) => row.user_name || '-'],
    ], 'No hay datos de tasadores para los filtros seleccionados.', {
        overflow: false,
    });
}

function renderZones(rows) {
    renderMetricRows('zoneRows', rows, [[(row) => row.zone || '-']], 'No hay datos de zonas para los filtros seleccionados.', {
        overflow: true,
    });
}

function renderDelegations(rows) {
    renderMetricRows('delegationRows', rows, [
        [(row) => row.delegation || '-'],
    ], 'No hay datos de delegaciones para los filtros seleccionados.', {
        overflow: true,
    });
}

function renderPortals(rows) {
    renderMetricRows('portalRows', rows, [
        [(row) => row.portal || '-'],
    ], 'No hay datos de portales para los filtros seleccionados.', {
        overflow: true,
        outbound: false,
    });
}

function renderMetricRows(rootId, rows, leadingColumns, emptyMessage, options = {}) {
    const includeOverflow = options.overflow !== false;
    const includeOutbound = options.outbound !== false;
    const includeDirectPortalAnswered = Boolean(options.directPortalAnswered);
    const metricColumns = [
        [(row) => formatNumber(row.total_calls), true],
        [(row) => formatCountPercent(row.answered, row.answered_pct), true, (row) => row.answered, true],
        [(row) => formatCountPercent(row.not_answered, row.not_answered_pct), true, (row) => row.not_answered, true],
        [(row) => formatCountPercent(row.inbound, row.inbound_pct), true, (row) => row.inbound, true],
    ];

    if (includeOutbound) {
        metricColumns.push([(row) => formatCountPercent(row.outbound, row.outbound_pct), true, (row) => row.outbound, true]);
    }

    if (includeDirectPortalAnswered) {
        metricColumns.push(
            [(row) => formatNumber(row.commercial_direct_answered), true, (row) => row.commercial_direct_answered, false],
            [(row) => formatNumber(row.portal_answered), true, (row) => row.portal_answered, false],
        );
    }

    if (includeOverflow) {
        metricColumns.push([(row) => formatCountPercent(row.overflow_count, row.overflow_pct), true, (row) => row.overflow_count, true]);
    }

    metricColumns.push([(row) => formatSeconds(row.average_talk_seconds), true, (row) => row.average_talk_seconds, false]);

    renderRows(rootId, rows, [
        ...leadingColumns,
        ...metricColumns,
    ], emptyMessage);
}

function renderRows(rootId, rows, columns, emptyMessage) {
    const root = document.getElementById(rootId);
    root.innerHTML = '';

    if (!rows.length) {
        root.innerHTML = `<tr><td colspan="${columns.length}">${escapeHtml(emptyMessage)}</td></tr>`;
        return;
    }

    rows.forEach((row) => {
        const cells = columns.map(([formatter, numeric, sortFormatter, html], index) => {
            const value = formatter(row) ?? '-';
            const className = numeric ? ' class="num"' : '';
            const content = html ? value : (index === 0 ? `<strong>${escapeHtml(value)}</strong>` : escapeHtml(value));
            const sortValue = sortFormatter ? ` data-sort-value="${escapeHtml(sortFormatter(row) ?? '')}"` : '';

            return `<td${className}${sortValue}>${content}</td>`;
        }).join('');

        root.insertAdjacentHTML('beforeend', `<tr>${cells}</tr>`);
    });

    applyStoredSort(root);
}

function bindTableSorting() {
    document.querySelectorAll('table').forEach((table) => makeTableSortable(table));
}

function makeTableSortable(table) {
    table.querySelectorAll('thead th').forEach((header, index) => {
        header.dataset.sortable = 'true';
        header.addEventListener('click', () => {
            const tbody = table.querySelector('tbody');
            if (!tbody) {
                return;
            }

            const current = tableSortState.get(tbody.id);
            const direction = current?.columnIndex === index && current.direction === 'asc' ? 'desc' : 'asc';
            const state = { columnIndex: index, direction };

            tableSortState.set(tbody.id, state);
            sortRowsByColumn(table, index, direction);
            updateSortIndicators(table, state);
        });
    });
}

function applyStoredSort(tbody) {
    const table = tbody.closest('table');
    const state = tableSortState.get(tbody.id);

    if (!table || !state || state.columnIndex >= table.querySelectorAll('thead th').length) {
        return;
    }

    sortRowsByColumn(table, state.columnIndex, state.direction);
    updateSortIndicators(table, state);
}

function sortRowsByColumn(table, columnIndex, direction) {
    const tbody = table.querySelector('tbody');
    const multiplier = direction === 'asc' ? 1 : -1;
    const rows = [...tbody.querySelectorAll('tr')];

    rows.sort((a, b) => {
        const aCell = a.children[columnIndex];
        const bCell = b.children[columnIndex];
        const aValue = parseSortableValue(aCell?.dataset.sortValue || aCell?.textContent);
        const bValue = parseSortableValue(bCell?.dataset.sortValue || bCell?.textContent);

        if (aValue.empty && bValue.empty) {
            return 0;
        }

        if (aValue.empty) {
            return 1;
        }

        if (bValue.empty) {
            return -1;
        }

        if (aValue.type === 'number' && bValue.type === 'number') {
            return (aValue.value - bValue.value) * multiplier;
        }

        return aValue.value.localeCompare(bValue.value, 'es', { sensitivity: 'base' }) * multiplier;
    });

    rows.forEach((row) => tbody.appendChild(row));
}

function parseSortableValue(value) {
    const raw = String(value || '').trim();

    if (raw === '' || raw === '-') {
        return { empty: true, type: 'text', value: '' };
    }

    const primary = raw.split('(')[0].trim();
    const normalized = primary.replaceAll('%', '').replace(/\s+/g, '').replace(/^\+/, '');
    const numericCandidate = normalized.includes(',')
        ? normalized.replaceAll('.', '').replace(',', '.')
        : (/^-?\d{1,3}(\.\d{3})+(\.\d+)?$/.test(normalized) ? normalized.replaceAll('.', '') : normalized);
    const number = Number(numericCandidate);

    if (!Number.isNaN(number) && /^-?\d+(\.\d+)?$/.test(numericCandidate)) {
        return { empty: false, type: 'number', value: number };
    }

    return { empty: false, type: 'text', value: raw.toLocaleLowerCase('es') };
}

function updateSortIndicators(table, state) {
    table.querySelectorAll('thead th').forEach((header, index) => {
        header.querySelector('.sort-indicator')?.remove();

        if (index === state.columnIndex) {
            header.insertAdjacentHTML('beforeend', ` <span class="sort-indicator">${state.direction === 'asc' ? '▲' : '▼'}</span>`);
        }
    });
}

function renderFilterOptions(filters) {
    fillSelect('team', filters.teams || [], 'id', 'name');
    fillSelect('direction', filters.directions || [], 'id', 'name');
    fillSelect('status', filters.statuses || [], 'id', 'name');
    fillSelect('origin', filters.origins || [], 'id', 'name');
    fillSelect('delegation', (filters.delegations || []).map((item) => ({ id: item, name: item })), 'id', 'name');
    fillSelect('zone', (filters.zones || []).map((item) => ({ id: item, name: item })), 'id', 'name');
    fillSelect('portal', (filters.portals || []).map((item) => ({ id: item, name: item })), 'id', 'name');
    fillSelect('user', filters.users || [], 'id', 'name');
}

function fillSelect(id, items, valueKey, labelKey) {
    const select = document.getElementById(id);
    const current = select.value;
    const first = select.querySelector('option')?.outerHTML || '<option value="">Todos</option>';

    select.innerHTML = first;

    items.forEach((item) => {
        const option = document.createElement('option');
        option.value = item[valueKey];
        option.textContent = item[labelKey];
        select.appendChild(option);
    });

    select.value = [...select.options].some((option) => option.value === current) ? current : '';
}

function currentFilters() {
    const params = new URLSearchParams();

    setParam(params, 'period', document.getElementById('period')?.value);
    setParam(params, 'team', document.getElementById('team')?.value);
    setParam(params, 'direction', document.getElementById('direction')?.value);
    setParam(params, 'status', document.getElementById('status')?.value);
    setParam(params, 'origin', document.getElementById('origin')?.value);
    setParam(params, 'delegation', document.getElementById('delegation')?.value);
    setParam(params, 'zone', document.getElementById('zone')?.value);
    setParam(params, 'portal', document.getElementById('portal')?.value);
    setParam(params, 'user', document.getElementById('user')?.value);

    if (document.getElementById('period')?.value === 'custom') {
        setParam(params, 'current_start', document.getElementById('currentStart')?.value);
        setParam(params, 'current_end', document.getElementById('currentEnd')?.value);
        setParam(params, 'comparison_start', document.getElementById('comparisonStart')?.value);
        setParam(params, 'comparison_end', document.getElementById('comparisonEnd')?.value);
    }

    return params.toString();
}

function syncPeriodUrl() {
    const url = new URL(window.location.href);
    if (document.getElementById('period')?.value === 'october_2026') {
        url.searchParams.set('period', 'october_2026');
    } else {
        url.searchParams.delete('period');
    }
    window.history.replaceState(null, '', `${url.pathname}${url.search}`);
}

function setLoadingState(isLoading) {
    document.getElementById('loadingMessage')?.classList.toggle('is-hidden', !isLoading);

    if (isLoading) {
        document.getElementById('updatedBadge').textContent = 'Cargando fotografía local...';
        document.getElementById('emptyMessage')?.classList.add('is-hidden');
        [
            'teamBars',
            'callsReconciliation',
            'insights',
            'comparisonRows',
            'commercialRows',
            'customerServiceRows',
            'contactCenterRows',
            'appraiserRows',
            'zoneRows',
            'delegationRows',
            'portalRows',
        ].forEach((id) => {
            const element = document.getElementById(id);
            if (element) element.innerHTML = '';
            if (id === 'callsReconciliation') element?.classList.add('is-hidden');
        });
    }
}

function showLoadError(error) {
    const empty = document.getElementById('emptyMessage');
    empty.classList.remove('is-hidden');
    empty.textContent = error?.message || 'No se han podido cargar los datos de Salesforce.';
}

function setParam(params, key, value) {
    if (value) {
        params.set(key, value);
    }
}

function toggleCustomPeriods() {
    document.getElementById('customPeriods')?.classList.toggle('is-hidden', document.getElementById('period')?.value !== 'custom');
}

async function fetchJson(url, options = {}) {
    const response = await fetch(url, {
        ...options,
        headers: { Accept: 'application/json', ...(options.headers || {}) },
    });

    if (!response.ok) {
        throw new Error(`Error cargando ${url}`);
    }

    return response.json();
}

function periodText(period) {
    return period ? `${formatDate(period.inicio) || '-'} a ${formatDate(period.fin) || '-'}` : '-';
}

function formatDate(value) {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? value || '-' : new Intl.DateTimeFormat('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric' }).format(date);
}

function shortDate(value) {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value || '-';
    }

    return new Intl.DateTimeFormat('es-ES', { day: '2-digit', month: '2-digit' }).format(date);
}

function formatDateTime(value) {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? value || '-' : new Intl.DateTimeFormat('es-ES', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(date);
}

function formatNumber(value) {
    return value === null || value === undefined || Number.isNaN(Number(value)) ? '-' : fmt.format(Number(value));
}

function formatPercent(value) {
    return value === null || value === undefined || Number.isNaN(Number(value)) ? '-' : `${Number(value).toFixed(2)}%`;
}

function formatSeconds(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '-';
    }

    const seconds = Math.max(0, Math.round(Number(value)));
    return `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
}

function formatCountPercent(count, percent) {
    return `<span class="metric-value">${escapeHtml(formatNumber(count))}</span><span class="metric-percent">(${escapeHtml(formatPercent(percent))})</span>`;
}

function formatCountPercentOfTotal(count, total) {
    return `<span class="metric-value">${escapeHtml(formatNumber(count))}</span><span class="metric-percent">(${escapeHtml(formatPercent(percentOf(count, total)))})</span>`;
}

function percentOf(value, total) {
    const numerator = Number(value || 0);
    const denominator = Number(total || 0);

    if (!denominator) {
        return 0;
    }

    return (numerator / denominator) * 100;
}

function formatComparisonValue(value, seconds) {
    return escapeHtml(seconds ? formatSeconds(value) : formatNumber(value));
}

function formatComparisonDiff(value, seconds) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '-';
    }

    const number = Number(value);
    const sign = number > 0 ? '+' : '';
    return escapeHtml(seconds ? `${sign}${formatSeconds(Math.abs(number))}` : `${sign}${fmt.format(number)}`);
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}
