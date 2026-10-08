const fmt = new Intl.NumberFormat('es-ES');
const tableSortState = new Map();
const leadCommercialColumnsStorageKey = 'leadCommercialColumns';
const leadCommercialColumnDefinitions = [
    { key: 'comercial', label: 'Comercial', alwaysVisible: true },
    { key: 'commercial_delegation', label: 'Delegacion comercial', alwaysVisible: true },
    { key: 'zone', label: 'Zona', alwaysVisible: true },
    { key: 'leads_totales', label: 'Intereses totales', alwaysVisible: true },
    { key: 'convertidos', label: 'Convertidos', alwaysVisible: true },
    { key: 'conversion_pct', label: '% convertidos' },
    { key: 'descartados', label: 'Descartados', alwaysVisible: true },
    { key: 'descarte_pct', label: '% descartados' },
    { key: 'potenciales', label: 'Potenciales', alwaysVisible: true },
    { key: 'potenciales_sin_trabajar', label: 'Potenciales sin trabajar', alwaysVisible: true },
    { key: 'gestionados', label: 'Gestionados', alwaysVisible: true },
    { key: 'gestionados_pct', label: '% gestionados' },
];
const leadCommercialZoneColumnsStorageKey = 'leadCommercialZoneColumns';
const leadCommercialZoneColumnDefinitions = [
    { key: 'zone', label: 'Zona', alwaysVisible: true },
    { key: 'leads_totales', label: 'Intereses totales', alwaysVisible: true },
    { key: 'convertidos', label: 'Convertidos', alwaysVisible: true },
    { key: 'conversion_pct', label: '% convertidos' },
    { key: 'descartados', label: 'Descartados', alwaysVisible: true },
    { key: 'descarte_pct', label: '% descartados' },
    { key: 'potenciales', label: 'Potenciales', alwaysVisible: true },
    { key: 'potenciales_sin_trabajar', label: 'Potenciales sin trabajar', alwaysVisible: true },
    { key: 'gestionados', label: 'Gestionados', alwaysVisible: true },
    { key: 'gestionados_pct', label: '% gestionados' },
];
const leadCommercialDelegationColumnsStorageKey = 'leadCommercialDelegationColumns';
const leadCommercialDelegationColumnDefinitions = [
    { key: 'commercial_delegation', label: 'Delegacion comercial', alwaysVisible: true },
    { key: 'zone', label: 'Zona', alwaysVisible: true },
    { key: 'leads_totales', label: 'Intereses totales', alwaysVisible: true },
    { key: 'convertidos', label: 'Convertidos', alwaysVisible: true },
    { key: 'conversion_pct', label: '% convertidos' },
    { key: 'descartados', label: 'Descartados', alwaysVisible: true },
    { key: 'descarte_pct', label: '% descartados' },
    { key: 'potenciales', label: 'Potenciales', alwaysVisible: true },
    { key: 'potenciales_sin_trabajar', label: 'Potenciales sin trabajar', alwaysVisible: true },
    { key: 'gestionados', label: 'Gestionados', alwaysVisible: true },
    { key: 'gestionados_pct', label: '% gestionados' },
];
let leadCommercialVisibleColumns = loadVisibleColumns(
    leadCommercialColumnsStorageKey,
    leadCommercialColumnDefinitions
);
let leadCommercialZoneVisibleColumns = loadVisibleColumns(
    leadCommercialZoneColumnsStorageKey,
    leadCommercialZoneColumnDefinitions
);
let leadCommercialDelegationVisibleColumns = loadVisibleColumns(
    leadCommercialDelegationColumnsStorageKey,
    leadCommercialDelegationColumnDefinitions
);
let leadReloadController = null;
let latestLeadReloadRequestId = 0;

document.addEventListener('DOMContentLoaded', async () => {
    bindTabs();
    bindFilters();
    bindResetFilters();
    bindTableSorting();
    bindLeadCommercialTabs();
    initLeadCommercialColumns();
    initLeadCommercialZoneColumns();
    initLeadCommercialDelegationColumns();
    bindLeadCommercialSearch();
    bindReconciliationAudit();
    toggleCustomPeriods();
    await reloadAllData();
});

function bindReconciliationAudit() {
    document.getElementById('reconciliationAudit')?.addEventListener('click', (event) => {
        event.currentTarget.href = `/informes/leads/export/reconciliation-audit.csv?${currentFilters()}`;
    });
}

function bindTabs() {
    document.querySelectorAll('.main-tab[data-panel]').forEach((button) => {
        button.addEventListener('click', () => {
            const panelId = button.dataset.panel;

            document.querySelectorAll('.main-tab[data-panel]').forEach((item) => item.classList.remove('active'));
            document.querySelectorAll('.tab-panel').forEach((panel) => panel.classList.remove('active'));

            button.classList.add('active');
            document.getElementById(panelId)?.classList.add('active');
        });
    });
}

function bindLeadCommercialTabs() {
    const root = document.getElementById('panel-comerciales');

    if (!root) {
        return;
    }

    const triggers = [...root.querySelectorAll('[data-lead-commercial-tab-trigger]')];
    const panels = [...root.querySelectorAll('[data-lead-commercial-tab-panel]')];

    if (!triggers.length || !panels.length) {
        return;
    }

    const activate = (targetTab) => {
        const targetPanel = panels.find((panel) => panel.dataset.leadCommercialTabPanel === targetTab);

        if (!targetPanel) {
            return;
        }

        triggers.forEach((trigger) => {
            trigger.classList.toggle('active', trigger.dataset.leadCommercialTabTrigger === targetTab);
        });
        panels.forEach((panel) => {
            const isActive = panel.dataset.leadCommercialTabPanel === targetTab;

            panel.classList.toggle('active', isActive);
            panel.classList.toggle('is-hidden', !isActive);
            panel.toggleAttribute('hidden', !isActive);
        });
    };

    activate('commercials');

    triggers.forEach((trigger) => {
        trigger.addEventListener('click', (event) => {
            event.preventDefault();
            activate(trigger.dataset.leadCommercialTabTrigger);
        });
    });
}

function bindResetFilters() {
    document.getElementById('resetFilters')?.addEventListener('click', async () => {
        [
            'portal',
            'leadDelegation',
            'commercialDelegation',
            'zone',
            'commercial',
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
        document.getElementById('leadType').value = 'all';
        toggleCustomPeriods();
        await reloadAllData();
    });
}

function bindFilters() {
    [
        'period',
        'portal',
        'leadType',
        'leadDelegation',
        'commercialDelegation',
        'zone',
        'commercial',
        'currentStart',
        'currentEnd',
        'comparisonStart',
        'comparisonEnd',
    ].forEach((id) => {
        document.getElementById(id)?.addEventListener('change', async () => {
            if (id === 'period') {
                toggleCustomPeriods();
            }

            await reloadAllData();
        });
    });
}

async function reloadAllData() {
    leadReloadController?.abort();
    leadReloadController = new AbortController();
    const requestId = ++latestLeadReloadRequestId;
    const requestOptions = { signal: leadReloadController.signal };
    const filters = currentFilters();
    setLoadingState(true);

    try {
        const summary = await fetchJson(`/informes/leads/data/summary?${filters}`, requestOptions);
        if (requestId !== latestLeadReloadRequestId) return;
        renderSummary(summary);
        renderFilterOptions(summary.filters || {});

        const [commercials, delegations, portals] = await Promise.all([
            fetchJson(`/informes/leads/data/commercials?${filters}`, requestOptions),
            fetchJson(`/informes/leads/data/delegations?${filters}`, requestOptions),
            fetchJson(`/informes/leads/data/portals?${filters}`, requestOptions),
        ]);
        if (requestId !== latestLeadReloadRequestId) return;

        renderCommercialZones(commercials.zones || []);
        renderCommercialDelegations(commercials.delegations || []);
        renderCommercials(commercials.commercials || commercials.items || []);
        renderDelegations(delegations.items || []);
        renderPortals(portals.items || []);
    } catch (error) {
        if (error?.name !== 'AbortError' && requestId === latestLeadReloadRequestId) {
            showLoadError(error);
        }
    } finally {
        if (requestId === latestLeadReloadRequestId) {
            setLoadingState(false);
        }
    }
}

function renderSummary(data) {
    const updatedBadge = document.getElementById('updatedBadge');
    updatedBadge.textContent = data.datos_actualizados
        ? `Datos actualizados: ${formatDateTime(data.datos_actualizados)}`
        : 'Datos actualizados: pendiente';
    updatedBadge.title = [
        data.salesforce_leads_synced_at ? `Intereses: ${formatDateTime(data.salesforce_leads_synced_at)}` : null,
        data.activities_synced_at ? `Actividades: ${formatDateTime(data.activities_synced_at)}` : null,
        data.dataset_generated_at ? `Dataset: ${formatDateTime(data.dataset_generated_at)}` : null,
        data.dataset_cutoff_at ? `Corte de sincronizacion: ${formatDateTime(data.dataset_cutoff_at)}` : null,
        data.dataset_sync_run_id ? `Ejecucion de sincronizacion: #${data.dataset_sync_run_id}` : null,
        data.metadata_coverage ? `Cobertura: ${data.metadata_coverage.total - data.metadata_coverage.without_synced_at}/${data.metadata_coverage.total} con synced_at` : null,
        data.dataset_timezone ? `Zona horaria: ${data.dataset_timezone}` : null,
    ].filter(Boolean).join('\n');
    document.getElementById('currentPeriodLabel').textContent = periodText(data.periodo_actual);
    document.getElementById('comparisonPeriodLabel').textContent = periodText(data.periodo_comparado);

    const empty = document.getElementById('emptyMessage');
    const hasNoData = data.empty ?? !data.ok;
    empty.classList.toggle('is-hidden', !hasNoData);
    empty.textContent = data.message || 'No hay Interests que coincidan con el periodo y los filtros seleccionados.';

    renderKpis(data.kpis || {});
    renderComparison(data.comparativa || []);
    renderInsights(data.insights || []);
}

function renderKpis(kpis) {
    const root = document.getElementById('summaryKpis');
    const cards = [
        { label: 'Intereses totales', value: formatNumber(kpis.leads_totales), hint: 'Muestra del periodo', metric: 'leads_totales' },
        { label: 'Convertidos', value: formatNumber(kpis.convertidos), hint: `${formatPercent(kpis.conversion_pct)} sobre total`, metric: 'convertidos' },
        { label: 'Descartados', value: formatNumber(kpis.descartados), hint: `${formatPercent(kpis.descarte_pct)} sobre total`, metric: 'descartados' },
        { label: 'Potenciales', value: formatNumber(kpis.potenciales), hint: 'Bolsa viva', metric: 'potenciales' },
        { label: 'Sin comercial elegible', value: formatNumber(kpis.without_eligible_commercial), hint: 'Sin usuario activo de perfil comercial válido', metric: 'without_eligible_commercial' },
        { label: 'Sin delegación comercial', value: formatNumber(kpis.without_commercial_delegation), hint: 'Comercial elegible sin delegación resoluble', metric: 'without_commercial_delegation' },
        { label: 'Sin clasificar', value: formatNumber(kpis.unclassified), hint: 'Interest válido sin delegación clasificada', metric: 'unclassified' },
        { label: 'Intereses sin asignar', value: formatNumber(kpis.leads_unassigned), hint: 'Bolsa viva técnica', metric: 'leads_unassigned' },
        { label: 'Potenciales sin trabajar', value: formatNumber(kpis.potenciales_sin_trabajar), hint: 'Solo Status Potencial', metric: 'potenciales_sin_trabajar' },
        { label: 'Gestionados', value: formatNumber(kpis.gestionados), hint: `${formatPercent(kpis.gestionados_pct)} sobre total`, metric: 'gestionados' },
        { label: 'Llamadas', value: formatNumber(kpis.llamadas), hint: `${formatPercent(kpis.llamadas_pct)} del total`, metric: 'llamadas' },
        { label: 'Formularios', value: formatNumber(kpis.formularios), hint: `${formatPercent(kpis.formularios_pct)} del total`, metric: 'formularios' },
    ];

    root.innerHTML = '';

    cards.forEach((card) => {
        root.insertAdjacentHTML('beforeend', `
            <div class="card kpi">
                <div class="kpi-copy">
                    <div class="kpi-label">${escapeHtml(card.label)}</div>
                    <div class="kpi-value">${escapeHtml(card.value)}</div>
                    <div class="kpi-hint">${escapeHtml(card.hint)}</div>
                    ${kpiAuditLinkHtml(card.metric, card.label)}
                </div>
            </div>
        `);
    });
}

function renderComparison(rows) {
    const root = document.getElementById('comparisonRows');
    root.innerHTML = '';

    if (!rows.length) {
        root.innerHTML = '<tr><td colspan="4">No hay datos para comparar.</td></tr>';
        return;
    }

    rows.forEach((row) => {
        root.insertAdjacentHTML('beforeend', `
            <tr>
                <td><strong>${escapeHtml(row.metrica)}</strong></td>
                <td class="num" data-sort-value="${escapeHtml(row.periodo_actual ?? '')}">${formatComparisonValue(row, 'periodo_actual')}</td>
                <td class="num" data-sort-value="${escapeHtml(row.periodo_comparado ?? '')}">${formatComparisonValue(row, 'periodo_comparado')}</td>
                <td class="num" data-sort-value="${escapeHtml(row.diferencia ?? '')}">${formatComparisonDiff(row)}</td>
            </tr>
        `);
    });

    applyStoredSort(root);
}

function renderInsights(items) {
    const root = document.getElementById('insights');
    root.innerHTML = '';

    if (!items.length) {
        root.innerHTML = '<div class="priority-item">No hay datos suficientes para generar conclusiones.</div>';
        return;
    }

    items.forEach((item) => {
        if (typeof item === 'string') {
            root.insertAdjacentHTML('beforeend', `<div class="priority-item">${escapeHtml(item)}</div>`);
            return;
        }

        const priority = normalizePriority(item.prioridad || 'media');

        root.insertAdjacentHTML('beforeend', `
            <article class="priority-item priority-${escapeHtml(priority)}">
                <div class="priority-head">
                    <b>${escapeHtml(item.titulo || 'Conclusión')}</b>
                    <span class="priority-badge">${escapeHtml(priority)}</span>
                </div>
                <p>${escapeHtml(item.problema_detectado || '')}</p>
                <p><b>Evidencia:</b> ${escapeHtml(item.evidencia || '')}</p>
                <p><b>Recomendación:</b> ${escapeHtml(item.recomendacion || '')}</p>
            </article>
        `);
    });
}

function renderCommercials(rows) {
    renderRows('commercialRows', rows, [
        [(row) => row.comercial, false, null, false, 'comercial'],
        [(row) => row.commercial_delegation || '-', false, null, false, 'commercial_delegation'],
        [(row) => row.zone || '-', false, null, false, 'zone'],
        [(row) => formatNumber(row.leads_totales), true, (row) => row.leads_totales, false, 'leads_totales'],
        [(row) => formatNumber(row.convertidos), true, (row) => row.convertidos, false, 'convertidos'],
        [(row) => formatPercent(row.conversion_pct), true, (row) => row.conversion_pct, false, 'conversion_pct'],
        [(row) => formatNumber(row.descartados), true, (row) => row.descartados, false, 'descartados'],
        [(row) => formatPercent(row.descarte_pct), true, (row) => row.descarte_pct, false, 'descarte_pct'],
        [(row) => formatNumber(row.potenciales), true, (row) => row.potenciales, false, 'potenciales'],
        [(row) => formatNumber(row.potenciales_sin_trabajar), true, (row) => row.potenciales_sin_trabajar, false, 'potenciales_sin_trabajar'],
        [(row) => formatNumber(row.gestionados), true, (row) => row.gestionados, false, 'gestionados'],
        [(row) => formatPercent(row.gestionados_pct), true, (row) => row.gestionados_pct, false, 'gestionados_pct'],
    ], 'No hay datos de comerciales para los filtros seleccionados.', (row) => ({
        'data-search': `${row.comercial || ''} ${row.commercial_id || row.group_key || ''}`.trim(),
    }));

    applyLeadCommercialColumnVisibility();
    applyCommercialSearchFilter();
}

function renderCommercialZones(rows) {
    renderRows('commercialZoneRows', rows, [
        [(row) => row.zone || '-', false, null, false, 'zone'],
        [(row) => formatNumber(row.leads_totales), true, (row) => row.leads_totales, false, 'leads_totales'],
        [(row) => formatNumber(row.convertidos), true, (row) => row.convertidos, false, 'convertidos'],
        [(row) => formatPercent(row.conversion_pct), true, (row) => row.conversion_pct, false, 'conversion_pct'],
        [(row) => formatNumber(row.descartados), true, (row) => row.descartados, false, 'descartados'],
        [(row) => formatPercent(row.descarte_pct), true, (row) => row.descarte_pct, false, 'descarte_pct'],
        [(row) => formatNumber(row.potenciales), true, (row) => row.potenciales, false, 'potenciales'],
        [(row) => formatNumber(row.potenciales_sin_trabajar), true, (row) => row.potenciales_sin_trabajar, false, 'potenciales_sin_trabajar'],
        [(row) => formatNumber(row.gestionados), true, (row) => row.gestionados, false, 'gestionados'],
        [(row) => formatPercent(row.gestionados_pct), true, (row) => row.gestionados_pct, false, 'gestionados_pct'],
    ], 'No hay datos de zonas para los filtros seleccionados.');

    applyLeadCommercialZoneColumnVisibility();
}

function renderCommercialDelegations(rows) {
    renderRows('commercialDelegationRows', rows, [
        [(row) => row.commercial_delegation, false, null, false, 'commercial_delegation'],
        [(row) => row.zone || '-', false, null, false, 'zone'],
        [(row) => formatNumber(row.leads_totales), true, (row) => row.leads_totales, false, 'leads_totales'],
        [(row) => formatNumber(row.convertidos), true, (row) => row.convertidos, false, 'convertidos'],
        [(row) => formatPercent(row.conversion_pct), true, (row) => row.conversion_pct, false, 'conversion_pct'],
        [(row) => formatNumber(row.descartados), true, (row) => row.descartados, false, 'descartados'],
        [(row) => formatPercent(row.descarte_pct), true, (row) => row.descarte_pct, false, 'descarte_pct'],
        [(row) => formatNumber(row.potenciales), true, (row) => row.potenciales, false, 'potenciales'],
        [(row) => formatNumber(row.potenciales_sin_trabajar), true, (row) => row.potenciales_sin_trabajar, false, 'potenciales_sin_trabajar'],
        [(row) => formatNumber(row.gestionados), true, (row) => row.gestionados, false, 'gestionados'],
        [(row) => formatPercent(row.gestionados_pct), true, (row) => row.gestionados_pct, false, 'gestionados_pct'],
    ], 'No hay datos de delegaciones comerciales para los filtros seleccionados.');

    applyLeadCommercialDelegationColumnVisibility();
}

function renderDelegations(rows) {
    renderRows('delegationRows', rows, [
        [(row) => row.delegacion],
        [(row) => formatNumber(row.leads_totales), true],
        [(row) => formatNumber(row.leads_unassigned), true],
        [(row) => formatPercent(row.leads_unassigned_pct), true, (row) => row.leads_unassigned_pct],
    ], 'No hay datos de delegaciones para los filtros seleccionados.');
}

function renderPortals(rows) {
    renderRows('portalRows', rows, [
        [(row) => row.portal],
        [(row) => formatNumber(row.leads_totales), true],
        [(row) => formatCountPercent(row.convertidos, row.conversion_pct), true, (row) => row.convertidos, true],
        [(row) => formatCountPercent(row.descartados, row.descarte_pct), true, (row) => row.descartados, true],
        [(row) => formatNumber(row.potenciales), true],
        [(row) => formatNumber(row.potenciales_sin_trabajar), true],
        [(row) => formatCountPercent(row.gestionados, row.gestionados_pct), true, (row) => row.gestionados, true],
        [(row) => formatCountPercent(row.llamadas, row.llamadas_pct), true, (row) => row.llamadas, true],
        [(row) => formatCountPercent(row.formularios, row.formularios_pct), true, (row) => row.formularios, true],
    ], 'No hay datos de fuentes para los filtros seleccionados.');
}

function renderRows(rootId, rows, columns, emptyMessage, rowMeta = null) {
    const root = document.getElementById(rootId);
    root.innerHTML = '';

    if (!rows.length) {
        root.innerHTML = `<tr><td colspan="${columns.length}">${escapeHtml(emptyMessage)}</td></tr>`;
        return;
    }

    rows.forEach((row) => {
        const cells = columns.map(([formatter, numeric, sortFormatter, html, columnKey], index) => {
            const value = formatter(row) ?? '-';
            const className = numeric ? ' class="num"' : '';
            const content = html ? value : (index === 0 ? `<strong>${escapeHtml(value)}</strong>` : escapeHtml(value));
            const sortValue = sortFormatter ? ` data-sort-value="${escapeHtml(sortFormatter(row) ?? '')}"` : '';
            const columnAttr = columnKey ? ` data-column="${escapeHtml(columnKey)}"` : '';

            return `<td${className}${sortValue}${columnAttr}>${content}</td>`;
        }).join('');

        const attrs = rowMeta ? rowMeta(row) : {};
        const attrString = Object.entries(attrs)
            .filter(([, value]) => value !== null && value !== undefined && value !== '')
            .map(([key, value]) => ` ${escapeHtml(key)}="${escapeHtml(value)}"`)
            .join('');

        root.insertAdjacentHTML('beforeend', `<tr${attrString}>${cells}</tr>`);
    });

    applyStoredSort(root);
}

function initLeadCommercialColumns() {
    initColumnToggleMenu({
        buttonId: 'leadCommercialColumnsButton',
        popoverId: 'leadCommercialColumnsPopover',
        storageKey: leadCommercialColumnsStorageKey,
        definitions: leadCommercialColumnDefinitions,
        getVisible: () => leadCommercialVisibleColumns,
        setVisible: (value) => {
            leadCommercialVisibleColumns = value;
        },
        applyVisibility: applyLeadCommercialColumnVisibility,
    });
}

function initLeadCommercialZoneColumns() {
    initColumnToggleMenu({
        buttonId: 'leadCommercialZoneColumnsButton',
        popoverId: 'leadCommercialZoneColumnsPopover',
        storageKey: leadCommercialZoneColumnsStorageKey,
        definitions: leadCommercialZoneColumnDefinitions,
        getVisible: () => leadCommercialZoneVisibleColumns,
        setVisible: (value) => {
            leadCommercialZoneVisibleColumns = value;
        },
        applyVisibility: applyLeadCommercialZoneColumnVisibility,
    });
}

function initLeadCommercialDelegationColumns() {
    initColumnToggleMenu({
        buttonId: 'leadCommercialDelegationColumnsButton',
        popoverId: 'leadCommercialDelegationColumnsPopover',
        storageKey: leadCommercialDelegationColumnsStorageKey,
        definitions: leadCommercialDelegationColumnDefinitions,
        getVisible: () => leadCommercialDelegationVisibleColumns,
        setVisible: (value) => {
            leadCommercialDelegationVisibleColumns = value;
        },
        applyVisibility: applyLeadCommercialDelegationColumnVisibility,
    });
}

function applyLeadCommercialColumnVisibility() {
    document.querySelectorAll('#leadCommercialTable [data-column]').forEach((cell) => {
        cell.classList.toggle('is-hidden', !leadCommercialVisibleColumns.includes(cell.dataset.column));
    });
}

function applyLeadCommercialZoneColumnVisibility() {
    document.querySelectorAll('#leadCommercialZonesTable [data-column]').forEach((cell) => {
        cell.classList.toggle('is-hidden', !leadCommercialZoneVisibleColumns.includes(cell.dataset.column));
    });
}

function applyLeadCommercialDelegationColumnVisibility() {
    document.querySelectorAll('#leadCommercialDelegationsTable [data-column]').forEach((cell) => {
        cell.classList.toggle('is-hidden', !leadCommercialDelegationVisibleColumns.includes(cell.dataset.column));
    });
}

function initColumnToggleMenu({
    buttonId,
    popoverId,
    storageKey,
    definitions,
    getVisible,
    setVisible,
    applyVisibility,
}) {
    const button = document.getElementById(buttonId);
    const popover = document.getElementById(popoverId);

    if (!button || !popover) {
        return;
    }

    renderColumnTogglePopover(popover, definitions, getVisible());
    applyVisibility();

    button.addEventListener('click', () => {
        popover.classList.toggle('is-hidden');
    });

    popover.addEventListener('change', (event) => {
        const input = event.target.closest('[data-column-toggle]');

        if (!input) {
            return;
        }

        const visible = new Set(getVisible());
        const key = input.dataset.columnToggle;

        if (input.checked) {
            visible.add(key);
        } else {
            visible.delete(key);
        }

        const nextVisible = definitions
            .filter((column) => column.alwaysVisible || visible.has(column.key))
            .map((column) => column.key);

        setVisible(nextVisible);
        localStorage.setItem(storageKey, JSON.stringify(nextVisible));
        applyVisibility();
    });

    document.addEventListener('click', (event) => {
        if (!event.target.closest('.columns-menu')) {
            popover.classList.add('is-hidden');
        }
    });
}

function renderColumnTogglePopover(root, definitions, visibleColumns) {
    if (!root) {
        return;
    }

    root.innerHTML = definitions
        .filter((column) => !column.alwaysVisible)
        .map((column) => `
            <label class="column-option switch-option">
                <input type="checkbox" data-column-toggle="${escapeHtml(column.key)}" ${visibleColumns.includes(column.key) ? 'checked' : ''}>
                <span>${escapeHtml(column.label)}</span>
            </label>
        `)
        .join('');
}

function bindLeadCommercialSearch() {
    document.getElementById('leadCommercialSearch')?.addEventListener('input', applyCommercialSearchFilter);
}

function applyCommercialSearchFilter() {
    const term = String(document.getElementById('leadCommercialSearch')?.value || '')
        .trim()
        .toLocaleLowerCase('es');

    document.querySelectorAll('#commercialRows tr').forEach((row) => {
        const haystack = String(row.dataset.search || '').toLocaleLowerCase('es');
        row.classList.toggle('is-hidden', term !== '' && !haystack.includes(term));
    });
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
    const normalized = primary
        .replaceAll('%', '')
        .replace(/\s+/g, '')
        .replace(/^\+/, '');
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
    fillSelect('commercial', filters.commercials || [], 'id', 'name');
    fillSelect('leadDelegation', (filters.lead_delegations || []).map((item) => ({ id: item, name: item })), 'id', 'name');
    fillSelect('commercialDelegation', (filters.commercial_delegations || []).map((item) => ({ id: item, name: item })), 'id', 'name');
    fillSelect('zone', (filters.zones || []).map((item) => ({ id: item, name: item })), 'id', 'name');
    fillSelect('portal', (filters.portals || []).map((item) => ({ id: item, name: item })), 'id', 'name');
}

function fillSelect(id, items, valueKey, labelKey) {
    const select = document.getElementById(id);
    if (!select) {
        return;
    }
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
    setParam(params, 'portal', document.getElementById('portal')?.value);
    setParam(params, 'lead_type', document.getElementById('leadType')?.value);
    setParam(params, 'lead_delegation', document.getElementById('leadDelegation')?.value);
    setParam(params, 'commercial_delegation', document.getElementById('commercialDelegation')?.value);
    setParam(params, 'zone', document.getElementById('zone')?.value);
    setParam(params, 'commercial', document.getElementById('commercial')?.value);

    if (document.getElementById('period')?.value === 'custom') {
        setParam(params, 'current_start', document.getElementById('currentStart')?.value);
        setParam(params, 'current_end', document.getElementById('currentEnd')?.value);
        setParam(params, 'comparison_start', document.getElementById('comparisonStart')?.value);
        setParam(params, 'comparison_end', document.getElementById('comparisonEnd')?.value);
    }

    return params.toString();
}

function buildKpiAuditUrl(metric) {
    const params = new URLSearchParams(currentFilters());
    params.set('metric', metric);

    return `/informes/leads/export/kpi-audit.csv?${params.toString()}`;
}

function kpiAuditLinkHtml(metric, label) {
    if (!window.reportUserCanAudit || !metric) {
        return '';
    }

    return `<div class="kpi-actions"><a class="kpi-audit-link" href="${escapeHtml(buildKpiAuditUrl(metric))}" title="Auditar ${escapeHtml(label)}">Auditar KPI</a></div>`;
}

function setLoadingState(isLoading) {
    const loading = document.getElementById('loadingMessage');

    loading?.classList.toggle('is-hidden', !isLoading);

    if (isLoading) {
        document.getElementById('updatedBadge').textContent = 'Cargando fotografía local...';
        document.getElementById('emptyMessage')?.classList.add('is-hidden');
        [
            'kpiGrid',
            'comparisonRows',
            'insights',
            'actionableInsights',
            'commercialZoneRows',
            'commercialDelegationRows',
            'commercialRows',
            'delegationRows',
            'portalRows',
        ].forEach((id) => {
            const element = document.getElementById(id);
            if (element) element.innerHTML = '';
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
    document.getElementById('customPeriods')?.classList.toggle(
        'is-hidden',
        document.getElementById('period')?.value !== 'custom'
    );
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
    if (!period) {
        return '-';
    }

    return `${formatDate(period.inicio) || '-'} a ${formatDate(period.fin) || '-'}`;
}

function formatDate(value) {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value || '-';
    }

    return new Intl.DateTimeFormat('es-ES', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(date);
}

function formatDateTime(value) {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value || '-';
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
        return '-';
    }

    return fmt.format(Number(value));
}

function formatPercent(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '-';
    }

    return `${Number(value).toFixed(1)}%`;
}

function formatCountPercent(count, percent) {
    return `<span class="metric-value">${escapeHtml(formatNumber(count))}</span><span class="metric-percent">(${escapeHtml(formatPercent(percent))})</span>`;
}

function formatComparisonValue(row, key) {
    const value = row[key];
    const percent = row[`${key}_pct`];

    return row.is_compact ? formatCountPercent(value, percent) : escapeHtml(formatMetric(value, false));
}

function formatComparisonDiff(row) {
    const count = formatDiff(row.diferencia, false);

    if (!row.is_compact || row.diferencia_pct_puntos === null || row.diferencia_pct_puntos === undefined) {
        return escapeHtml(count);
    }

    return `<span class="metric-value">${escapeHtml(count)}</span><span class="metric-percent">(${escapeHtml(formatDiff(row.diferencia_pct_puntos, true))})</span>`;
}

function formatMetric(value, isPercentage) {
    return isPercentage ? formatPercent(value) : formatNumber(value);
}

function formatDiff(value, isPercentage) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '-';
    }

    const number = Number(value);
    const sign = number > 0 ? '+' : '';

    return isPercentage
        ? `${sign}${number.toFixed(1)} pp`
        : `${sign}${fmt.format(number)}`;
}

function formatSigned(value) {
    const number = Number(value);
    const sign = number > 0 ? '+' : '';

    return `${sign}${number.toFixed(1)}`;
}

function normalizePriority(value) {
    const priority = String(value || 'media')
        .trim()
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '');

    if (priority === 'alta') {
        return 'alta';
    }

    if (priority === 'baja') {
        return 'baja';
    }

    return 'media';
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function loadVisibleColumns(storageKey, definitions) {
    const defaultColumns = definitions.filter((column) => column.alwaysVisible).map((column) => column.key);

    try {
        const stored = JSON.parse(localStorage.getItem(storageKey) || '[]');

        if (!Array.isArray(stored) || !stored.length) {
            return defaultColumns;
        }

        const valid = stored.filter((key) => definitions.some((column) => column.key === key));

        return definitions
            .filter((column) => column.alwaysVisible || valid.includes(column.key))
            .map((column) => column.key);
    } catch (error) {
        return defaultColumns;
    }
}
