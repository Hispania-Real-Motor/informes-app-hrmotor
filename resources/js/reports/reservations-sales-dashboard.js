const fmt = new Intl.NumberFormat('es-ES');
const tableSortState = new Map();
let reservationsReloadController = null;
let latestReservationsReloadRequestId = 0;
let performanceReloadController = null;
let latestPerformanceReloadRequestId = 0;
let performanceTargetAvailable = false;
const performanceColumnsStorageKey = 'reservationsSalesCommercialPerformanceColumnsV5';
const performanceColumnDefinitions = [
    { key: 'ranking', label: 'Ranking', alwaysVisible: true },
    { key: 'traffic_light', label: 'Estado', alwaysVisible: true },
    { key: 'commercial', label: 'Comercial', alwaysVisible: true },
    { key: 'delegation', label: 'Delegación', defaultVisible: true },
    { key: 'zone', label: 'Zona' },
    { key: 'leads', label: 'Intereses' },
    { key: 'opportunities', label: 'Oportunidades' },
    { key: 'reservations_total', label: 'Reservas totales', defaultVisible: true },
    { key: 'team_average_reservations', label: 'Media delegación' },
    { key: 'team_reservations_deviation', label: 'Desviación vs delegación' },
    { key: 'reservations_active', label: 'Reservas vivas', defaultVisible: true },
    { key: 'reservations_dropped', label: 'Reservas caídas', defaultVisible: true },
    { key: 'objective', label: 'Objetivo' },
    { key: 'fulfillment_pct', label: 'Cumplimiento', defaultVisible: true },
    { key: 'lead_to_reservation_pct', label: 'Interés → Reserva' },
    { key: 'lead_to_reservation_vs_team', label: 'Ratio Interés → Reserva · Comparativa con su delegación' },
    { key: 'opportunity_to_reservation_pct', label: 'Oportunidad → Reserva' },
    { key: 'opportunity_to_reservation_vs_team', label: 'Ratio Oportunidad → Reserva · Comparativa con su delegación' },
    { key: 'sales', label: 'Ventas válidas', defaultVisible: true },
    { key: 'sales_dropped', label: 'Ventas caídas', defaultVisible: true },
    { key: 'reservation_to_sale_pct', label: 'Reserva → Venta' },
    { key: 'reservation_drop_pct', label: '% Reserva caída' },
    { key: 'sale_drop_pct', label: '% Venta caída' },
    { key: 'reservation_to_sale_vs_team', label: 'Ratio Reserva → Venta · Comparativa con su delegación' },
    { key: 'cancellations', label: 'Cancelaciones' },
    { key: 'cancellation_pct', label: '% cancelación' },
    { key: 'margin_total', label: 'Margen total', defaultVisible: true },
    { key: 'average_margin_per_sale', label: 'Margen medio', defaultVisible: true },
    { key: 'margin_coverage_pct', label: 'Cobertura margen' },
];
const performanceColumnPresets = {
    summary: {
        label: 'Resumen',
        columns: ['ranking', 'traffic_light', 'commercial', 'delegation', 'reservations_total', 'reservations_active', 'reservations_dropped', 'sales', 'sales_dropped', 'fulfillment_pct', 'margin_total', 'average_margin_per_sale'],
    },
    activity: {
        label: 'Actividad',
        columns: ['ranking', 'traffic_light', 'commercial', 'delegation', 'leads', 'opportunities', 'reservations_total', 'reservations_active', 'reservations_dropped', 'sales', 'sales_dropped', 'lead_to_reservation_pct', 'opportunity_to_reservation_pct', 'reservation_to_sale_pct', 'reservation_drop_pct', 'sale_drop_pct', 'cancellations', 'cancellation_pct'],
    },
    profitability: {
        label: 'Rentabilidad',
        columns: ['ranking', 'traffic_light', 'commercial', 'delegation', 'sales', 'sales_dropped', 'fulfillment_pct', 'margin_total', 'average_margin_per_sale', 'margin_coverage_pct'],
    },
};
let performanceVisibleColumns = loadVisibleColumns(performanceColumnsStorageKey, performanceColumnDefinitions);
const reservationsCommercialColumnsStorageKey = 'reservationsCommercialColumns';
const reservationsCommercialColumnDefinitions = [
    { key: 'comercial', label: 'Comercial', alwaysVisible: true },
    { key: 'commercial_delegation', label: 'Delegacion comercial', alwaysVisible: true },
    { key: 'zone', label: 'Zona', alwaysVisible: true },
    { key: 'oportunidades_totales', label: 'Oportunidades totales', alwaysVisible: true },
    { key: 'reservas_vivas', label: 'Reservas vivas', alwaysVisible: true },
    { key: 'reservas_vivas_pct', label: '% reservas vivas' },
    { key: 'reservas_vivas_participation_pct', label: '% participacion reservas' },
    { key: 'oportunidades_caidas', label: 'Oportunidades caidas', alwaysVisible: true },
    { key: 'oportunidades_caidas_pct', label: '% oportunidades caidas' },
    { key: 'oportunidades_caidas_participation_pct', label: '% participacion caidas' },
    { key: 'cv_firmados', label: 'Contratos CV firmados', alwaysVisible: true },
    { key: 'cv_firmados_pct', label: '% contratos CV firmados' },
    { key: 'cv_firmados_participation_pct', label: '% participacion CV' },
];
let reservationsCommercialVisibleColumns = loadVisibleColumns(
    reservationsCommercialColumnsStorageKey,
    reservationsCommercialColumnDefinitions
);

document.addEventListener('DOMContentLoaded', async () => {
    bindTabs();
    bindFilters();
    bindResetFilters();
    bindTableSorting();
    initReservationsCommercialColumns();
    bindReservationsCommercialSearch();
    bindCommercialPerformance();
    setFilterMode('panel-resumen');
    toggleCustomPeriods();
    await reloadAllData();
});

function bindTabs() {
    document.querySelectorAll('[data-report-tab]').forEach((button) => {
        button.addEventListener('click', async () => {
            const panelId = button.dataset.reportPanelTarget;

            document.querySelectorAll('[data-report-tab]').forEach((item) => {
                item.classList.remove('active', 'is-active');
            });
            document.querySelectorAll('[data-report-panel]').forEach((panel) => panel.classList.remove('active'));

            button.classList.add('active', 'is-active');
            document.getElementById(panelId)?.classList.add('active');
            setFilterMode(panelId);

            if (panelId === 'panel-rendimiento-comercial') {
                reservationsReloadController?.abort();
                await reloadCommercialPerformance();
            } else {
                performanceReloadController?.abort();
                await reloadAllData();
            }
        });
    });
}

function setFilterMode(panelId) {
    const performanceMode = panelId === 'panel-rendimiento-comercial';
    const summaryMode = panelId === 'panel-resumen';
    const filters = document.getElementById('reportFilters');
    if (!filters) return;

    filters.dataset.filterMode = performanceMode ? 'performance' : (summaryMode ? 'summary' : 'standard');
    filters.querySelectorAll('[data-filter-scope="standard"]').forEach((control) => {
        control.classList.toggle('is-hidden', performanceMode);
    });
    filters.querySelectorAll('[data-filter-scope="legacy-date-criterion"]').forEach((control) => {
        control.classList.toggle('is-hidden', performanceMode || summaryMode);
    });
    filters.querySelectorAll('[data-filter-scope="performance"]').forEach((control) => {
        control.classList.toggle('is-hidden', !performanceMode);
    });

    const delegationLabel = document.getElementById('commercialDelegationLabel');
    if (delegationLabel) delegationLabel.textContent = performanceMode ? 'Delegación' : 'Delegacion comercial';
    toggleCustomPeriods();
    if (performanceMode) refreshPerformanceScrolls();
}

function isCommercialPerformanceMode() {
    return document.getElementById('reportFilters')?.dataset.filterMode === 'performance';
}

function isSummaryMode() {
    return document.getElementById('reportFilters')?.dataset.filterMode === 'summary';
}

function bindCommercialPerformance() {
    if (!window.reportUserCanViewCommercialPerformance) return;

    initPerformanceColumns();
    bindPerformanceSearch();
    initPerformanceScrolls();
    window.addEventListener('resize', refreshPerformanceScrolls);
    document.getElementById('performanceMonth')?.addEventListener('change', reloadCommercialPerformance);

    document.getElementById('savePerformanceTarget')?.addEventListener('click', saveCommercialPerformanceTarget);
    document.getElementById('loadPerformanceAudit')?.addEventListener('click', reloadCommercialPerformanceAudit);
    document.getElementById('retryCommercialPerformance')?.addEventListener('click', reloadCommercialPerformance);
}

function commercialPerformanceQuery() {
    const params = new URLSearchParams({ month: document.getElementById('performanceMonth').value });
    setParam(params, 'zone', document.getElementById('zone').value);
    setParam(params, 'delegation', document.getElementById('commercialDelegation').value);
    setParam(params, 'commercial', document.getElementById('commercial').value);
    return params.toString();
}

async function reloadCommercialPerformance() {
    if (!window.reportUserCanViewCommercialPerformance) return;

    performanceReloadController?.abort();
    const controller = new AbortController();
    performanceReloadController = controller;
    const requestId = ++latestPerformanceReloadRequestId;
    invalidatePerformanceAudit();
    setPerformanceTargetState('loading');
    clearPerformancePresentation();
    document.getElementById('performanceLoading')?.classList.remove('is-hidden');
    document.getElementById('performanceLoadError')?.classList.add('is-hidden');

    try {
        const data = await fetchJson(`/informes/reservas-ventas/data/commercial-performance?${commercialPerformanceQuery()}`, {
            signal: controller.signal,
        });
        if (requestId !== latestPerformanceReloadRequestId) return;

        if (renderPerformanceFilters(data.filters || {})) {
            await reloadCommercialPerformance();
            return;
        }

        renderCommercialPerformance(data);
    } catch (error) {
        if (error?.name !== 'AbortError') {
            const loadError = document.getElementById('performanceLoadError');
            loadError.querySelector('[data-performance-load-error-message]').textContent = 'No se pudo cargar el rendimiento comercial con los filtros seleccionados.';
            loadError.classList.remove('is-hidden');
        }
    } finally {
        if (requestId === latestPerformanceReloadRequestId) {
            document.getElementById('performanceLoading')?.classList.add('is-hidden');
        }
    }
}

function clearPerformancePresentation() {
    ['performanceKpis', 'performanceRows', 'performanceEvolutionRows'].forEach((id) => {
        const element = document.getElementById(id);
        if (element) element.innerHTML = '';
    });
    ['performanceUniverse', 'performanceFreshness', 'performanceCancellationCoverage', 'performanceDataIncident', 'performanceQualityWarning', 'performanceCurrentMonthNotice', 'performanceDataContextSummary'].forEach((id) => {
        const element = document.getElementById(id);
        if (!element) return;
        element.textContent = '';
        element.classList.add('is-hidden');
    });
    document.getElementById('performanceLimitationNotice')?.classList.add('is-hidden');
}

function setPerformanceTargetState(state, value = null) {
    const target = document.getElementById('performanceTarget');
    const button = document.getElementById('savePerformanceTarget');
    if (!target) return;

    const parsedValue = Number(value);
    const available = state === 'available' && Number.isInteger(parsedValue) && parsedValue >= 1;
    const canManage = window.reportUserCanManageCommercialPerformanceTarget === true;
    performanceTargetAvailable = available && canManage;
    target.disabled = canManage && !available;
    target.readOnly = !canManage;
    if (button) button.disabled = !performanceTargetAvailable;
    target.value = available ? String(parsedValue) : '';
}

async function saveCommercialPerformanceTarget() {
    const button = document.getElementById('savePerformanceTarget');
    const target = document.getElementById('performanceTarget');
    if (!button || !target || !performanceTargetAvailable || button.disabled || target.disabled) return;

    const value = Number(target.value);
    if (!Number.isInteger(value) || value < 1) {
        window.alert('El objetivo debe ser un entero mayor que cero.');
        return;
    }

    const requestId = latestPerformanceReloadRequestId;
    button.disabled = true;
    target.disabled = true;

    try {
        await fetchJson('/informes/reservas-ventas/data/commercial-performance/target', {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': window.reportCsrfToken,
            },
            body: JSON.stringify({
                month: document.getElementById('performanceMonth').value,
                reservations_target: value,
            }),
        });
        await reloadCommercialPerformance();
    } catch (error) {
        window.alert('No se pudo guardar el objetivo. Debe ser un entero mayor que cero.');
        if (requestId === latestPerformanceReloadRequestId && performanceTargetAvailable) {
            target.disabled = false;
            button.disabled = false;
        }
    }
}

function renderCommercialPerformance(data) {
    const commercialSelected = String(document.getElementById('commercial')?.value || '') !== '';

    setPerformanceTargetState('available', data.objective?.reservations_target);
    renderPerformanceCurrentMonthNotice(data.month);
    renderPerformanceUniverse(data.universe || {}, commercialSelected);
    renderPerformanceFreshness(data);
    renderPerformanceDataIncident(data.data_incident);
    renderPerformanceKpis(data.summary || {}, data.universe || {}, commercialSelected);
    renderPerformanceRows(data.items || []);
    renderPerformanceEvolution(data.evolution || [], data.data_quality?.cancellation_coverage_by_month || {});
    applyPerformanceColumnVisibility();
    refreshPerformanceScrolls();

    const quality = data.data_quality || {};
    const coverageNotice = document.getElementById('performanceCancellationCoverage');
    const certifiedCutoff = quality.cancellation_certified_until;
    const sourceCutoff = quality.cancellation_source_cutoff_at;
    const coverageStatus = formatCoverageStatus(quality.cancellation_coverage_status);
    if (quality.cancellations_available && certifiedCutoff) {
        coverageNotice.textContent = `${coverageStatus}. OpportunityHistory está cubierto hasta ${formatDateTime(certifiedCutoff)}. Este corte certifica las transiciones del período; los cambios de estado posteriores se incorporan mediante la sincronización incremental y pueden reclasificar retrospectivamente reservas o ventas del mes.`;
    } else if (sourceCutoff) {
        coverageNotice.textContent = `Cancelaciones no evaluables. ${coverageStatus}. Último corte consultado: ${formatDateTime(sourceCutoff)}${certifiedCutoff ? `; continuidad certificada hasta ${formatDateTime(certifiedCutoff)}` : ''}. Los cambios de estado posteriores pueden reclasificar retrospectivamente reservas o ventas del mes.`;
    } else {
        coverageNotice.textContent = `${coverageStatus}. Cancelaciones no evaluables: no existe un corte OpportunityHistory certificado para todo el período.`;
    }
    coverageNotice.classList.remove('is-hidden');
    const warning = document.getElementById('performanceQualityWarning');
    const uncertified = Number(quality.uncertified_historical_events || 0);
    const conflicts = Number(quality.duplicate_conflict_groups || 0) + Number(quality.unresolved_attribution_events || 0);
    const messages = [];
    if (!quality.cancellations_available) messages.push(`Cancelaciones no evaluables: ${coverageStatus.toLocaleLowerCase('es')} en OpportunityHistory.`);
    if (Number(quality.cancellation_unresolved_dependencies || 0) > 0) messages.push(`${formatNumber(quality.cancellation_unresolved_dependencies)} dependencias de Opportunity no resueltas impiden certificar el KPI.`);
    if (Number(quality.invalid_cancellation_chronology || 0) > 0) messages.push(`${formatNumber(quality.invalid_cancellation_chronology)} transiciones tienen una reserva posterior y se excluyen como incidencia.`);
    if (uncertified > 0) messages.push(`${formatNumber(uncertified)} eventos sin asignación histórica evaluable; conservan su actividad individual y quedan fuera del ranking de delegación.`);
    if (Number(quality.organisation_changes_within_month || 0) > 0) messages.push(`${formatNumber(quality.organisation_changes_within_month)} comerciales cambiaron de delegación o zona durante el mes; no se ha elegido una asignación mensual arbitraria.`);
    if (conflicts > 0) messages.push(`${formatNumber(conflicts)} incidencias de atribución permanecen fuera del ranking individual.`);
    warning.textContent = messages.join(' ');
    warning.classList.toggle('is-hidden', messages.length === 0);

    renderPerformanceContextSummary(data, quality);
    renderPerformanceLimitationNotice(quality);
}

function renderPerformanceContextSummary(data, quality) {
    const summary = document.getElementById('performanceDataContextSummary');
    if (!summary) return;

    const parts = [formatCoverageStatus(quality.cancellation_coverage_status)];
    if (data.dataset_generated_at) parts.push(`Informe generado ${formatDateTime(data.dataset_generated_at)}`);
    summary.textContent = parts.join(' · ');
    summary.classList.remove('is-hidden');
}

function renderPerformanceLimitationNotice(quality) {
    const notice = document.getElementById('performanceLimitationNotice');
    if (!notice) return;

    notice.classList.toggle('is-hidden', quality.cancellations_available !== false);
}

function renderPerformanceCurrentMonthNotice(month) {
    const notice = document.getElementById('performanceCurrentMonthNotice');
    if (!notice) return;

    const isCurrentMonth = month === window.commercialPerformanceCurrentMonth;
    notice.textContent = isCurrentMonth
        ? 'Mes en curso · Resultado provisional. Los datos muestran la actividad acumulada hasta el momento. El objetivo mensual no se prorratea; el cumplimiento y el estado comparan el avance actual con el objetivo completo del mes.'
        : '';
    notice.classList.toggle('is-hidden', !isCurrentMonth);
}

async function reloadCommercialPerformanceAudit() {
    const button = document.getElementById('loadPerformanceAudit');
    const status = document.getElementById('performanceAuditStatus');
    button.disabled = true;
    status.textContent = 'Cargando auditoría local...';
    status.classList.remove('performance-note--error');
    status.classList.add('performance-note--info');
    status.classList.remove('is-hidden');

    try {
        const params = new URLSearchParams({
            month: document.getElementById('performanceMonth').value,
            per_page: '200',
        });
        setParam(params, 'zone', document.getElementById('zone').value);
        setParam(params, 'delegation', document.getElementById('commercialDelegation').value);
        setParam(params, 'commercial', document.getElementById('commercial').value);
        const data = await fetchJson(`/informes/reservas-ventas/data/commercial-performance/audit?${params}`);
        renderCommercialPerformanceAudit(data);
        status.textContent = `${formatNumber(data.pagination?.total || 0)} eventos auditables. Cobertura de cancelaciones: ${formatCoverageStatus(data.coverage_status)}.`;
    } catch (error) {
        status.textContent = 'No se pudo cargar la auditoría con los filtros seleccionados.';
        status.classList.remove('performance-note--info');
        status.classList.add('performance-note--error');
    } finally {
        button.disabled = false;
    }
}

function renderCommercialPerformanceAudit(data) {
    const result = document.getElementById('performanceAuditResult');
    result.innerHTML = `
        <div class="table-scroll-top is-hidden" data-scroll-target="performanceAuditWrap" aria-hidden="true"><div></div></div>
        <div class="report-ui-data-panel__scroll performance-audit-wrap" id="performanceAuditWrap" tabindex="0" aria-label="Eventos de auditoría de rendimiento comercial">
            <table class="performance-audit-table report-ui-table report-ui-table--sticky-header">
                <thead><tr>
                    <th scope="col">Evento</th><th scope="col">Fecha</th><th scope="col">ID Interest</th><th scope="col">ID oportunidad</th>
                    <th scope="col">Responsable</th><th scope="col">Delegación / cobertura</th><th scope="col">Universo mensual</th><th scope="col">Funnel / cumplimiento</th><th scope="col">Contado</th><th scope="col">Incidencia / exclusión</th>
                </tr></thead>
                <tbody id="performanceAuditRows"></tbody>
            </table>
        </div>`;
    result.classList.remove('is-hidden');
    const root = document.getElementById('performanceAuditRows');
    const rows = data.items || [];
    if (!rows.length) {
        root.innerHTML = '<tr><td colspan="10">No hay eventos auditables para el filtro.</td></tr>';
        initPerformanceScrolls();
        refreshPerformanceScrolls();
        return;
    }

    root.innerHTML = rows.map((row) => `<tr>
        <td>${escapeHtml(row.event_type || '-')}</td><td>${escapeHtml(formatDate(row.event_at))}</td>
        <td>${escapeHtml(row.interest_id || '-')}</td><td>${escapeHtml(row.opportunity_id || '-')}</td>
        <td><strong>${escapeHtml(row.commercial || '-')}</strong><br><small>${escapeHtml(row.commercial_id || '-')}</small></td>
        <td>${escapeHtml(row.delegation || '-')}<br><small>${escapeHtml(formatDelegationStatus(row.delegation_status, row.delegation_issue))}</small></td>
        <td>${escapeHtml(formatEvaluationStatus(row.monthly_evaluation_status, row.monthly_evaluation_reason))}<br><small>${row.objective_applies ? `Objetivo ${escapeHtml(formatNumber(row.monthly_objective))}` : 'Sin objetivo mensual'}</small></td>
        <td>${escapeHtml(formatFunnelAudit(row.funnel))}</td>
        <td>${row.counted_in_metric ? 'Sí' : 'No'}</td><td>${escapeHtml(row.exclusion_reason || row.deduplication_status || '-')}<br><small>${escapeHtml(row.metric_attribution || '-')}</small></td>
    </tr>`).join('');
    initPerformanceScrolls();
    refreshPerformanceScrolls();
}

function formatFunnelAudit(funnel = {}) {
    const applies = funnel.reservations_total || funnel.reservations_active || funnel.reservations_dropped || funnel.sales_valid || funnel.sales_dropped || funnel.data_insufficient || funnel.classification_conflict;
    if (!applies) return '-';
    const labels = [
        ['reservations_total', 'Reserva total'], ['reservations_active', 'Reserva viva'], ['reservations_dropped', 'Reserva caída'],
        ['sales_valid', 'Venta válida'], ['sales_dropped', 'Venta caída'], ['classification_conflict', 'Conflicto de clasificación'],
    ].filter(([key]) => funnel[key]).map(([, label]) => label);
    if (Object.hasOwn(funnel, 'fulfillment_contribution')) labels.push(funnel.fulfillment_contribution ? 'Contribuye a cumplimiento' : 'No contribuye a cumplimiento');
    const exclusionLabels = { reservation_not_demonstrated: 'Reserva no demostrada', reservation_dropped: 'Reserva caída', sale_dropped: 'Venta caída', classification_conflict: 'Conflicto de clasificación' };
    if (funnel.reservation_not_demonstrated) labels.push('Reserva no demostrada');
    if (funnel.fulfillment_exclusion_reason && !labels.includes(exclusionLabels[funnel.fulfillment_exclusion_reason])) labels.push(exclusionLabels[funnel.fulfillment_exclusion_reason] || 'No aplica');
    if (funnel.data_insufficient) labels.push('Datos insuficientes: sin fecha de reserva ni fecha de CV');
    return [...new Set(labels)].join(' · ');
}

function formatDelegationStatus(status, issue) {
    const labels = {
        observed: 'Observada',
        bootstrap_approved: 'Bootstrap aprobado',
        not_certifiable: 'No certificable',
    };
    const issues = {
        incomplete_history: 'Cobertura incompleta',
        organisation_change_within_month: 'Cambio intramensual',
        missing_commercial_identity: 'Identidad no disponible',
        future_period: 'Periodo futuro',
    };
    const label = labels[status] || 'No certificable';
    const detail = issues[issue];

    return detail ? `${label} · ${detail}` : label;
}

function invalidatePerformanceAudit() {
    const result = document.getElementById('performanceAuditResult');
    if (result) {
        result.innerHTML = '';
        result.classList.add('is-hidden');
    }
    document.getElementById('performanceAuditStatus')?.classList.add('is-hidden');
}

function renderPerformanceFilters(filters) {
    const changedSelections = [
        fillPerformanceSelect('zone', filters.zones || [], (value) => ({ value, label: formatPerformanceZone(value) }), 'Todas'),
        fillPerformanceSelect('commercialDelegation', filters.delegations || [], (value) => ({ value, label: value }), 'Todas'),
        fillPerformanceSelect('commercial', filters.commercials || [], (value) => ({ value: value.id, label: value.name }), 'Todos'),
    ];

    return changedSelections.some(Boolean);
}

function fillPerformanceSelect(id, values, mapper, allLabel) {
    const select = document.getElementById(id);
    const selected = select.value;
    select.innerHTML = `<option value="">${allLabel}</option>`;
    values.forEach((value) => {
        const option = mapper(value);
        select.insertAdjacentHTML('beforeend', `<option value="${escapeHtml(option.value)}">${escapeHtml(option.label)}</option>`);
    });
    const selectionIsValid = [...select.options].some((option) => option.value === selected);
    if (selectionIsValid) select.value = selected;

    return selected !== '' && !selectionIsValid;
}

function renderPerformanceUniverse(universe, commercialSelected) {
    const note = document.getElementById('performanceUniverse');
    if (!note) return;
    const commercialExplanation = commercialSelected
        ? ' El KPI superior de cumplimiento muestra al comercial seleccionado; el universo, ranking y referencias de delegación no se recalculan.'
        : ' El universo, ranking y referencias de delegación corresponden a los filtros de Zona y Delegación.';
    note.textContent = `${formatNumber(universe.evaluable_commercials || 0)} comerciales evaluables · ${formatNumber(universe.active_not_evaluable_commercials || 0)} con actividad no evaluable · ${formatNumber(universe.excluded_no_activity_commercials || 0)} excluidos sin actividad · objetivo individual ${formatNumber(universe.individual_target || 0)} · objetivo global ${formatNumber(universe.global_target || 0)} · cumplimiento global ${formatAvailablePercent(universe.global_fulfillment_pct)}.${commercialExplanation}`;
    note.classList.remove('is-hidden');
}

function renderPerformanceFreshness(data) {
    const note = document.getElementById('performanceFreshness');
    if (!note) return;

    const generatedAt = data.dataset_generated_at;
    const source = data.dataset_source === 'local_snapshot' ? 'Informe generado con la fotografía local' : 'Informe generado';
    note.textContent = generatedAt
        ? `${source}: ${formatDateTime(generatedAt)}.`
        : '';
    note.classList.toggle('is-hidden', !generatedAt);
}

function renderPerformanceDataIncident(incident) {
    const note = document.getElementById('performanceDataIncident');
    if (!note) return;
    if (!incident) {
        note.textContent = '';
        note.classList.add('is-hidden');
        return;
    }
    note.textContent = `Incidencia de datos (fuera del universo evaluable): ${formatNumber(incident.leads)} leads · ${formatNumber(incident.opportunities)} oportunidades · ${formatNumber(incident.reservations_total)} reservas totales · ${formatNumber(incident.reservations_active)} reservas vivas · ${formatNumber(incident.reservations_dropped)} reservas caídas · ${formatNumber(incident.sales)} ventas válidas · ${formatNumber(incident.sales_dropped)} ventas caídas · ${formatAvailableNumber(incident.cancellations)} cancelaciones · ${formatCurrency(incident.margin_total)} margen total. No recibe objetivo, ranking ni comparativa de delegación.`;
    note.classList.remove('is-hidden');
}

function renderPerformanceKpis(summary, universe, commercialSelected) {
    const fulfillment = commercialSelected
        ? ['Cumplimiento comercial', formatFulfillmentCalculation(summary.reservations_valid_for_objective, summary.objective, summary.fulfillment_pct)]
        : ['Cumplimiento global', formatFulfillmentCalculation(universe.global_reservations_valid_for_objective, universe.global_target, universe.global_fulfillment_pct)];
    const marginCoverage = formatSummaryMarginCoverage(summary);
    const cards = [
        ['Intereses', formatNumber(summary.leads)],
        ['Oportunidades', formatNumber(summary.opportunities)],
        ['Reservas totales', formatNumber(summary.reservations_total)],
        ['Reservas vivas', formatNumber(summary.reservations_active)],
        ['Reservas caídas', formatNumber(summary.reservations_dropped)],
        ['Ventas válidas', formatNumber(summary.sales)],
        ['Ventas caídas', formatNumber(summary.sales_dropped)],
        fulfillment,
        ['Margen total', formatCurrency(summary.margin_total), marginCoverage],
    ];
    document.getElementById('performanceKpis').innerHTML = cards.map(([label, value, detail]) => `
        <div class="report-ui-kpi-strip__item"><div class="report-ui-kpi-strip__label">${escapeHtml(label)}</div><div class="report-ui-kpi-strip__value">${escapeHtml(value)}</div>${detail ? `<div class="performance-kpi-detail">${escapeHtml(detail)}</div>` : ''}</div>
    `).join('');
}

function formatFulfillmentCalculation(reservations, target, fulfillmentPct) {
    if (reservations === null || reservations === undefined
        || target === null || target === undefined
        || fulfillmentPct === null || fulfillmentPct === undefined) {
        return 'N/D';
    }

    return `${formatNumber(reservations)} reservas computables / ${formatNumber(target)} de objetivo = ${formatPercent(fulfillmentPct)}`;
}

function formatSummaryMarginCoverage(summary) {
    const sales = Number(summary.sales);
    const salesWithMargin = Number(summary.sales_with_margin);
    const salesWithoutMargin = Number(summary.sales_without_margin);

    if (!Number.isFinite(sales) || sales <= 0
        || !Number.isFinite(salesWithMargin)
        || !Number.isFinite(salesWithoutMargin)
        || salesWithoutMargin <= 0) {
        return '';
    }

    return `Cobertura margen: ${formatPercent((salesWithMargin / sales) * 100)} · ${formatNumber(salesWithoutMargin)} ventas sin margen informado`;
}

function renderPerformanceRows(rows) {
    const root = document.getElementById('performanceRows');
    if (!rows.length) {
        root.innerHTML = `<tr><td colspan="${performanceVisibleColumns.length}">No hay actividad comercial para los filtros seleccionados.</td></tr>`;
        return;
    }

    root.innerHTML = rows.map((row) => `<tr data-search="${escapeHtml(`${row.commercial || ''} ${row.commercial_id || ''}`.trim())}">
        <td class="report-ui-table__numeric" data-column="ranking">${escapeHtml(row.ranking ?? '-')}</td>
        <td data-column="traffic_light">${performanceLight(row.traffic_light)}</td>
        <td data-column="commercial"><strong>${escapeHtml(row.commercial || '-')}</strong>${row.evaluable ? '' : `<br><small>${escapeHtml(formatEvaluationStatus(row.evaluation_status, row.evaluation_reason))}</small>`}</td><td data-column="delegation">${escapeHtml(row.delegation || '-')}</td><td data-column="zone">${escapeHtml(formatPerformanceZone(row.zone || '-'))}</td>
        <td class="report-ui-table__numeric" data-column="leads">${formatNumber(row.leads)}</td><td class="report-ui-table__numeric" data-column="opportunities">${formatNumber(row.opportunities)}</td><td class="report-ui-table__numeric" data-column="reservations_total">${formatNumber(row.reservations_total)}</td><td class="report-ui-table__numeric" data-column="team_average_reservations">${formatTeamNumber(row.team_average_reservations)}</td><td class="report-ui-table__numeric" data-column="team_reservations_deviation">${formatReservationsDeviation(row.team_reservations_deviation, row.team_reservations_deviation_pct)}</td><td class="report-ui-table__numeric" data-column="reservations_active">${formatNumber(row.reservations_active)}</td><td class="report-ui-table__numeric" data-column="reservations_dropped">${formatNumber(row.reservations_dropped)}</td>
        <td class="report-ui-table__numeric" data-column="objective">${formatAvailableNumber(row.objective)}</td><td class="report-ui-table__numeric" data-column="fulfillment_pct">${formatAvailablePercent(row.fulfillment_pct)}</td>
        <td class="report-ui-table__numeric" data-column="lead_to_reservation_pct">${formatAvailablePercent(row.lead_to_reservation_pct)}</td><td class="report-ui-table__numeric" data-column="lead_to_reservation_vs_team">${formatDelegationRatioComparison(row.lead_to_reservation_pct, row.team_lead_to_reservation_pct, row.lead_to_reservation_vs_team_pp)}</td><td class="report-ui-table__numeric" data-column="opportunity_to_reservation_pct">${formatAvailablePercent(row.opportunity_to_reservation_pct)}</td><td class="report-ui-table__numeric" data-column="opportunity_to_reservation_vs_team">${formatDelegationRatioComparison(row.opportunity_to_reservation_pct, row.team_opportunity_to_reservation_pct, row.opportunity_to_reservation_vs_team_pp)}</td>
        <td class="report-ui-table__numeric" data-column="sales">${formatNumber(row.sales)}</td><td class="report-ui-table__numeric" data-column="sales_dropped">${formatNumber(row.sales_dropped)}</td><td class="report-ui-table__numeric" data-column="reservation_to_sale_pct">${formatAvailablePercent(row.reservation_to_sale_pct)}</td><td class="report-ui-table__numeric" data-column="reservation_drop_pct">${formatAvailablePercent(row.reservation_drop_pct)}</td><td class="report-ui-table__numeric" data-column="sale_drop_pct">${formatAvailablePercent(row.sale_drop_pct)}</td><td class="report-ui-table__numeric" data-column="reservation_to_sale_vs_team">${formatDelegationRatioComparison(row.reservation_to_sale_pct, row.team_reservation_to_sale_pct, row.reservation_to_sale_vs_team_pp)}</td>
        <td class="report-ui-table__numeric" data-column="cancellations">${formatAvailableNumber(row.cancellations)}</td><td class="report-ui-table__numeric" data-column="cancellation_pct">${formatAvailablePercent(row.cancellation_pct)}</td>
        <td class="report-ui-table__numeric" data-column="margin_total" title="Rentabilidad acumulada de las ventas con margen informado.">${formatMarginWithCoverage(row.margin_total, row.margin_coverage_pct)}</td>
        <td class="report-ui-table__numeric" data-column="average_margin_per_sale" title="Media calculada únicamente sobre ventas con margen informado.">${formatMarginWithCoverage(row.average_margin_per_sale, row.margin_coverage_pct)}</td>
        <td class="report-ui-table__numeric" data-column="margin_coverage_pct">${formatAvailablePercent(row.margin_coverage_pct)}</td>
    </tr>`).join('') + `<tr class="is-hidden" data-performance-search-empty><td colspan="${performanceVisibleColumns.length}">No hay comerciales que coincidan con la búsqueda.</td></tr>`;
    applyPerformanceSearchFilter();
}

function bindPerformanceSearch() {
    document.getElementById('performanceSearch')?.addEventListener('input', applyPerformanceSearchFilter);
}

function applyPerformanceSearchFilter() {
    const term = String(document.getElementById('performanceSearch')?.value || '')
        .trim()
        .toLocaleLowerCase('es');
    let matches = 0;

    document.querySelectorAll('#performanceRows tr[data-search]').forEach((row) => {
        const haystack = String(row.dataset.search || '').toLocaleLowerCase('es');
        const visible = term === '' || haystack.includes(term);
        row.classList.toggle('is-hidden', !visible);
        if (visible) matches++;
    });

    document.querySelector('#performanceRows [data-performance-search-empty]')
        ?.classList.toggle('is-hidden', term === '' || matches > 0);
}

function formatPerformanceZone(value) {
    return value === 'Zona Mediterraneo' ? 'Zona Mediterráneo' : value;
}

function formatCoverageStatus(status) {
    const labels = {
        covered: 'Histórico del período certificado',
        partial: 'Histórico del período parcialmente certificado',
        uncovered: 'Sin histórico certificado para todo el período',
    };

    return labels[status] || 'Cobertura no determinada';
}

function formatEvaluationStatus(status, reason) {
    const labels = {
        evaluable: 'Evaluable',
        not_evaluable: 'No evaluable',
        excluded_no_activity: 'Sin actividad real',
        data_incident: 'Incidencia de datos',
        excluded_by_business_rule: 'Excluido por regla de negocio',
    };
    const reasons = {
        incomplete_history: 'Cobertura histórica incompleta',
        organisation_change_within_month: 'Cambio organizativo intramensual',
        missing_commercial_identity: 'Identidad comercial no disponible',
        no_real_activity: 'Sin actividad real',
        data_quality_incident: 'Conflicto de atribución',
    };
    const label = labels[status] || 'No evaluable';
    const detail = reasons[reason];
    return detail && detail !== label ? `${label} · ${detail}` : label;
}

function renderPerformanceEvolution(rows, coverageByMonth) {
    const root = document.getElementById('performanceEvolutionRows');
    root.innerHTML = rows.map((row) => `<tr>
        <td title="${escapeHtml(formatPerformanceMonth(row.month, true))}"><strong>${escapeHtml(formatPerformanceMonth(row.month))}</strong></td><td class="report-ui-table__numeric">${formatNumber(row.leads)}</td><td class="report-ui-table__numeric">${formatNumber(row.opportunities)}</td>
        <td class="report-ui-table__numeric">${formatNumber(row.reservations_total)}</td><td class="report-ui-table__numeric">${formatNumber(row.reservations_active)}</td><td class="report-ui-table__numeric">${formatNumber(row.reservations_dropped)}</td><td class="report-ui-table__numeric">${formatNumber(row.sales)}</td><td class="report-ui-table__numeric">${formatNumber(row.sales_dropped)}</td><td class="report-ui-table__numeric">${formatEvolutionCancellations(row, coverageByMonth[row.month])}</td>
        <td class="report-ui-table__numeric">${formatAvailablePercent(row.fulfillment_pct)}</td><td class="report-ui-table__numeric">${formatAvailablePercent(row.lead_to_reservation_pct)}</td><td class="report-ui-table__numeric">${formatAvailablePercent(row.opportunity_to_reservation_pct)}</td>
        <td class="report-ui-table__numeric">${formatAvailablePercent(row.reservation_to_sale_pct)}</td><td class="report-ui-table__numeric">${formatAvailablePercent(row.reservation_drop_pct)}</td><td class="report-ui-table__numeric">${formatAvailablePercent(row.sale_drop_pct)}</td><td class="report-ui-table__numeric">${formatAvailablePercent(row.cancellation_pct)}</td><td class="report-ui-table__numeric">${formatCurrency(row.margin_total)}</td><td class="report-ui-table__numeric">${formatCurrency(row.average_margin_per_sale)}</td>
    </tr>`).join('');
}

function formatEvolutionCancellations(row, coverage) {
    if (row.cancellations !== null && row.cancellations !== undefined) {
        return formatNumber(row.cancellations);
    }

    const status = formatCoverageStatus(coverage?.status);
    const certifiedUntil = coverage?.certified_until
        ? ` · Certificado hasta ${formatDateTime(coverage.certified_until)}`
        : '';

    return `<span class="performance-cancellation-unavailable"><strong>N/D</strong><small>${escapeHtml(status + certifiedUntil)}</small></span>`;
}

function formatMarginWithCoverage(value, coveragePct) {
    const coverage = Number(coveragePct);
    const detail = coveragePct !== null && coveragePct !== undefined
        && Number.isFinite(coverage) && coverage < 100
        ? `<small>Cobertura margen: ${escapeHtml(formatPercent(coverage))}</small>`
        : '';

    return `<span class="performance-margin-value">${escapeHtml(formatCurrency(value))}${detail}</span>`;
}

function performanceLight(value) {
    const labels = { green: 'Verde', yellow: 'Amarillo', orange: 'Naranja', red: 'Rojo' };
    if (!Object.hasOwn(labels, value)) return '-';
    return `<span class="performance-light performance-light--${value}">${labels[value]}</span>`;
}

function initPerformanceColumns() {
    const button = document.getElementById('performanceColumnsButton');
    const popover = document.getElementById('performanceColumnsPopover');
    if (!button || !popover) return;

    const presets = Object.entries(performanceColumnPresets)
        .map(([key, preset]) => `<button type="button" class="report-ui-button report-ui-button--secondary reservations-column-preset" data-performance-column-preset="${escapeHtml(key)}">${escapeHtml(preset.label)}</button>`)
        .join('');
    const columns = performanceColumnDefinitions
        .filter((column) => !column.alwaysVisible)
        .map((column) => `
            <label class="reservations-column-option">
                <input type="checkbox" data-performance-column-toggle="${escapeHtml(column.key)}" ${performanceVisibleColumns.includes(column.key) ? 'checked' : ''}>
                <span>${escapeHtml(column.label)}</span>
            </label>`)
        .join('');
    popover.innerHTML = `<fieldset class="reservations-column-presets"><legend>Vistas</legend><div>${presets}</div></fieldset><fieldset class="reservations-column-options"><legend>Columnas</legend>${columns}</fieldset>`;
    applyPerformanceColumnVisibility();

    button.addEventListener('click', () => {
        const hidden = popover.classList.toggle('is-hidden');
        button.setAttribute('aria-expanded', String(!hidden));
    });
    popover.addEventListener('change', (event) => {
        const input = event.target.closest('[data-performance-column-toggle]');
        if (!input) return;

        const visible = new Set(performanceVisibleColumns);
        input.checked ? visible.add(input.dataset.performanceColumnToggle) : visible.delete(input.dataset.performanceColumnToggle);
        setPerformanceVisibleColumns([...visible]);
    });
    popover.addEventListener('click', (event) => {
        const button = event.target.closest('[data-performance-column-preset]');
        if (!button) return;

        const preset = performanceColumnPresets[button.dataset.performanceColumnPreset];
        if (!preset) return;

        setPerformanceVisibleColumns(preset.columns);
    });
    document.addEventListener('click', (event) => {
        if (!event.target.closest('[data-columns-menu]')) {
            popover.classList.add('is-hidden');
            button.setAttribute('aria-expanded', 'false');
        }
    });
}

function setPerformanceVisibleColumns(columns) {
    const visible = new Set(columns);
    performanceVisibleColumns = performanceColumnDefinitions
        .filter((column) => column.alwaysVisible || visible.has(column.key))
        .map((column) => column.key);
    localStorage.setItem(performanceColumnsStorageKey, JSON.stringify(performanceVisibleColumns));
    document.querySelectorAll('[data-performance-column-toggle]').forEach((input) => {
        input.checked = performanceVisibleColumns.includes(input.dataset.performanceColumnToggle);
    });
    applyPerformanceColumnVisibility();
}

function applyPerformanceColumnVisibility() {
    document.querySelectorAll('#performanceTable [data-column]').forEach((cell) => {
        cell.classList.toggle('is-hidden', !performanceVisibleColumns.includes(cell.dataset.column));
    });
    document.querySelector('#performanceRows [data-performance-search-empty]')
        ?.setAttribute('colspan', String(performanceVisibleColumns.length));
    refreshPerformanceScrolls();
}

function initPerformanceScrolls() {
    document.querySelectorAll('.table-scroll-top[data-scroll-target]').forEach((top) => {
        if (top.dataset.scrollBound === 'true') return;
        const bottom = document.getElementById(top.dataset.scrollTarget);
        if (!bottom) return;

        let syncing = false;
        top.addEventListener('scroll', () => {
            if (syncing) return;
            syncing = true;
            bottom.scrollLeft = top.scrollLeft;
            syncing = false;
        });
        bottom.addEventListener('scroll', () => {
            if (syncing) return;
            syncing = true;
            top.scrollLeft = bottom.scrollLeft;
            syncing = false;
        });
        top.dataset.scrollBound = 'true';
    });
}

function refreshPerformanceScrolls() {
    window.requestAnimationFrame(() => {
        document.querySelectorAll('.table-scroll-top[data-scroll-target]').forEach((top) => {
            const bottom = document.getElementById(top.dataset.scrollTarget);
            const spacer = top.firstElementChild;
            if (!bottom || !spacer) return;

            spacer.style.width = `${bottom.scrollWidth}px`;
            top.classList.toggle('is-hidden', bottom.scrollWidth <= bottom.clientWidth + 1);
            top.scrollLeft = bottom.scrollLeft;
        });
    });
}

function formatPerformanceMonth(value, includeYear = false) {
    const [year, month] = String(value || '').split('-').map(Number);
    if (!year || !month) return String(value || '-');

    const date = new Date(Date.UTC(year, month - 1, 1));
    return new Intl.DateTimeFormat('es-ES', includeYear
        ? { month: 'long', year: 'numeric', timeZone: 'UTC' }
        : { month: 'long', timeZone: 'UTC' }).format(date);
}

function bindResetFilters() {
    document.getElementById('resetFilters')?.addEventListener('click', async () => {
        if (isCommercialPerformanceMode()) {
            document.getElementById('performanceMonth').value = document.getElementById('performanceMonth').dataset.defaultMonth;
            document.getElementById('zone').value = '';
            document.getElementById('commercialDelegation').value = '';
            document.getElementById('commercial').value = '';
            await reloadCommercialPerformance();
            return;
        }

        [
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
        document.getElementById('dateCriterion').value = 'created_date';
        document.getElementById('opportunityType').value = 'all';
        toggleCustomPeriods();
        await reloadAllData();
    });
}

function bindFilters() {
    ['zone', 'commercialDelegation', 'commercial'].forEach((id) => {
        document.getElementById(id)?.addEventListener('change', async () => {
            if (id === 'zone') {
                document.getElementById('commercialDelegation').value = '';
                document.getElementById('commercial').value = '';
            } else if (id === 'commercialDelegation') {
                document.getElementById('commercial').value = '';
            }

            if (isCommercialPerformanceMode()) {
                await reloadCommercialPerformance();
            } else {
                await reloadAllData();
            }
        });
    });

    [
        'period',
        'dateCriterion',
        'opportunityType',
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
    reservationsReloadController?.abort();
    reservationsReloadController = new AbortController();
    const requestId = ++latestReservationsReloadRequestId;
    const requestOptions = { signal: reservationsReloadController.signal };
    setLoadingState(true);

    try {
        const filters = currentFilters();
        const summary = await fetchJson(`/informes/reservas-ventas/data/summary?${filters}`, requestOptions);
        if (requestId !== latestReservationsReloadRequestId) return;
        if (renderFilterOptions(summary.filters || {})) {
            await reloadAllData();
            return;
        }
        renderSummary(summary);

        const [commercials, portals] = await Promise.all([
            fetchJson(`/informes/reservas-ventas/data/commercials?${filters}`, requestOptions),
            fetchJson(`/informes/reservas-ventas/data/portals?${filters}`, requestOptions),
        ]);
        if (requestId !== latestReservationsReloadRequestId) return;

        renderCommercialZones(commercials.zones || []);
        renderCommercialDelegations(commercials.delegations || []);
        renderCommercials(commercials.commercials || commercials.items || []);
        renderPortals(portals.items || []);
    } catch (error) {
        if (error?.name !== 'AbortError' && requestId === latestReservationsReloadRequestId) {
            showLoadError(error);
        }
    } finally {
        if (requestId === latestReservationsReloadRequestId) {
            setLoadingState(false);
        }
    }
}

function renderSummary(data) {
    document.getElementById('updatedBadge').textContent = data.datos_actualizados
        ? `Datos de Salesforce sincronizados: ${formatDateTime(data.datos_actualizados)}`
        : 'Datos de Salesforce sincronizados: pendiente';
    document.getElementById('currentPeriodLabel').textContent = periodText(data.periodo_actual);
    document.getElementById('comparisonPeriodLabel').textContent = periodText(data.periodo_comparado);
    document.getElementById('currentPeriodTechnical').textContent = periodTechnicalText(data.periodo_actual);
    document.getElementById('comparisonPeriodTechnical').textContent = periodTechnicalText(data.periodo_comparado);
    document.getElementById('universeDateLabel').textContent = data.cohorte_creacion?.date_label || 'Fecha de creación';

    const empty = document.getElementById('emptyMessage');
    empty.classList.toggle('is-hidden', Boolean(data.ok));
    empty.textContent = data.message || 'No hay oportunidades sincronizadas para el periodo seleccionado.';

    renderProduction(data.produccion_periodo || {});
    renderCreationCohort(data.cohorte_creacion || {});
    renderCurrentLiveReservations(data.kpis || {});
    renderDataQuality(data.data_quality || {});
}

function renderDataQuality(quality) {
    const panel = document.getElementById('reservationsDataQualityPanel');
    const root = document.getElementById('reservationsDataQualityIncidents');
    const incidents = quality.incidents || [];

    panel?.classList.toggle('is-hidden', incidents.length === 0);
    if (!root) return;
    document.getElementById('reservationsDataQualityCount').textContent = formatNumber(quality.duplicate_event_groups || incidents.length);
    root.innerHTML = incidents.map((incident) => `
        <article class="reservations-data-quality-incident">
            <div>
                <strong>${incident.type === 'sale' ? 'Venta duplicada' : 'Reserva duplicada'} · ${escapeHtml(incident.vehicle_plate || incident.vehicle_id || 'Vehículo sin referencia visible')}</strong>
                <span>${escapeHtml(incident.event_date || 'Sin fecha')} · ${formatNumber((incident.opportunity_ids || []).length)} oportunidades</span>
            </div>
            <code>${escapeHtml((incident.opportunity_ids || []).join(', '))}</code>
            ${incident.conflicting_fields?.length ? `<small>Desglose en incidencia por conflicto en: ${escapeHtml(incident.conflicting_fields.join(', '))}</small>` : '<small>Atribución común; se contabiliza una sola vez.</small>'}
        </article>
    `).join('');
}

function renderProduction(production) {
    const current = production.periodo_actual || {};
    const cards = [
        { label: 'Reservas', value: formatNumber(current.reservas), hint: 'Por reservation_date · incluye vivas, caídas y firmadas', metric: 'reservas_totales' },
        { label: 'Ventas', value: formatNumber(current.ventas), hint: 'Por cv_signed_date · firmadas y no Cerrada Perdida', metric: 'cv_firmados_periodo' },
    ];

    renderSummaryCards('productionKpis', cards);
    renderComparison('productionComparisonRows', production.comparativa || []);
}

function renderCreationCohort(cohort) {
    const current = cohort.periodo_actual || {};
    renderSummaryCards('cohortKpis', [
        { label: 'Oportunidades totales', value: formatNumber(current.oportunidades_totales), hint: 'Pertenencia por CreatedDate' },
        { label: 'Reservas vivas', value: formatNumber(current.reservas_vivas), hint: `${formatPercent(current.reservas_vivas_pct)} sobre la cohorte` },
        { label: 'Oportunidades caídas', value: formatNumber(current.oportunidades_caidas), hint: `${formatPercent(current.oportunidades_caidas_pct)} sobre la cohorte` },
        { label: 'Contratos CV firmados', value: formatNumber(current.cv_firmados), hint: `${formatPercent(current.cv_firmados_pct)} sobre la cohorte` },
    ]);
    renderComparison('cohortComparisonRows', cohort.comparativa || []);
}

function renderCurrentLiveReservations(kpis) {
    const value = document.getElementById('currentLiveReservations');
    const audit = document.getElementById('currentLiveReservationsAudit');
    if (value) value.textContent = formatNumber(kpis.reservas_vivas_actuales_salesforce);
    if (audit) audit.innerHTML = kpiAuditLinkHtml('reservas_vivas_actuales_salesforce', 'Reservas vivas actuales');
}

function renderSummaryCards(rootId, cards) {
    const root = document.getElementById(rootId);
    if (!root) return;

    root.innerHTML = '';

    cards.forEach((card) => {
        root.insertAdjacentHTML('beforeend', `
            <div class="report-ui-kpi-strip__item">
                <div>
                    <div class="report-ui-kpi-strip__label">${escapeHtml(card.label)}</div>
                    <div class="report-ui-kpi-strip__value">${escapeHtml(card.value)}</div>
                    <div class="report-ui-kpi-strip__meta">${escapeHtml(card.hint)}</div>
                    ${kpiAuditLinkHtml(card.metric, card.label)}
                </div>
            </div>
        `);
    });
}

function renderComparison(rootId, rows) {
    const root = document.getElementById(rootId);
    if (!root) return;
    root.innerHTML = '';

    if (!rows.length) {
        root.innerHTML = '<tr><td colspan="4">No hay datos para comparar.</td></tr>';
        return;
    }

    rows.forEach((row) => {
        root.insertAdjacentHTML('beforeend', `
            <tr>
                <td><strong>${escapeHtml(row.metrica)}</strong></td>
                <td class="report-ui-table__numeric" data-sort-value="${escapeHtml(row.periodo_actual ?? '')}">${formatComparisonValue(row, 'periodo_actual')}</td>
                <td class="report-ui-table__numeric" data-sort-value="${escapeHtml(row.periodo_comparado ?? '')}">${formatComparisonValue(row, 'periodo_comparado')}</td>
                <td class="report-ui-table__numeric" data-sort-value="${escapeHtml(row.diferencia ?? '')}">${formatComparisonDiff(row)}</td>
            </tr>
        `);
    });

    applyStoredSort(root);
}

function renderCommercialZones(rows) {
    renderRows('commercialZoneRows', rows, [
        [(row) => row.zone || '-'],
        [(row) => formatNumber(row.oportunidades_totales), true],
        [(row) => formatCountConversionParticipation(row.reservas_vivas, row.reservas_vivas_pct, row.reservas_vivas_participation_pct), true, (row) => row.reservas_vivas, true],
        [(row) => formatCountConversionParticipation(row.oportunidades_caidas, row.oportunidades_caidas_pct, row.oportunidades_caidas_participation_pct), true, (row) => row.oportunidades_caidas, true],
        [(row) => formatCountConversionParticipation(row.cv_firmados, row.cv_firmados_pct, row.cv_firmados_participation_pct), true, (row) => row.cv_firmados, true],
    ], 'No hay datos de zonas para los filtros seleccionados.');
}

function renderCommercialDelegations(rows) {
    renderRows('commercialDelegationRows', rows, [
        [(row) => row.commercial_delegation || '-'],
        [(row) => row.zone || '-'],
        [(row) => formatNumber(row.oportunidades_totales), true],
        [(row) => formatCountConversionParticipation(row.reservas_vivas, row.reservas_vivas_pct, row.reservas_vivas_participation_pct), true, (row) => row.reservas_vivas, true],
        [(row) => formatCountConversionParticipation(row.oportunidades_caidas, row.oportunidades_caidas_pct, row.oportunidades_caidas_participation_pct), true, (row) => row.oportunidades_caidas, true],
        [(row) => formatCountConversionParticipation(row.cv_firmados, row.cv_firmados_pct, row.cv_firmados_participation_pct), true, (row) => row.cv_firmados, true],
    ], 'No hay datos de delegaciones para los filtros seleccionados.');
}

function renderCommercials(rows) {
    renderRows('commercialRows', rows, [
        [(row) => row.comercial || '-', false, null, false, 'comercial'],
        [(row) => row.commercial_delegation || '-', false, null, false, 'commercial_delegation'],
        [(row) => row.zone || '-', false, null, false, 'zone'],
        [(row) => formatNumber(row.oportunidades_totales), true, (row) => row.oportunidades_totales, false, 'oportunidades_totales'],
        [(row) => formatCountPercent(row.reservas_vivas, row.reservas_vivas_pct), true, (row) => row.reservas_vivas, true, 'reservas_vivas'],
        [(row) => formatPercent(row.reservas_vivas_pct), true, (row) => row.reservas_vivas_pct, false, 'reservas_vivas_pct'],
        [(row) => formatPercent(row.reservas_vivas_participation_pct), true, (row) => row.reservas_vivas_participation_pct, false, 'reservas_vivas_participation_pct'],
        [(row) => formatCountPercent(row.oportunidades_caidas, row.oportunidades_caidas_pct), true, (row) => row.oportunidades_caidas, true, 'oportunidades_caidas'],
        [(row) => formatPercent(row.oportunidades_caidas_pct), true, (row) => row.oportunidades_caidas_pct, false, 'oportunidades_caidas_pct'],
        [(row) => formatPercent(row.oportunidades_caidas_participation_pct), true, (row) => row.oportunidades_caidas_participation_pct, false, 'oportunidades_caidas_participation_pct'],
        [(row) => formatCountPercent(row.cv_firmados, row.cv_firmados_pct), true, (row) => row.cv_firmados, true, 'cv_firmados'],
        [(row) => formatPercent(row.cv_firmados_pct), true, (row) => row.cv_firmados_pct, false, 'cv_firmados_pct'],
        [(row) => formatPercent(row.cv_firmados_participation_pct), true, (row) => row.cv_firmados_participation_pct, false, 'cv_firmados_participation_pct'],
    ], 'No hay datos de comerciales para los filtros seleccionados.', (row) => ({
        'data-search': `${row.comercial || ''} ${row.commercial_id || row.group_key || ''}`.trim(),
    }));

    applyReservationsCommercialColumnVisibility();
    applyReservationsCommercialSearchFilter();
}

function renderPortals(rows) {
    renderRows('portalRows', rows, [
        [(row) => row.portal || '-'],
        [(row) => formatNumber(row.oportunidades_totales), true],
        [(row) => formatCountConversionParticipation(row.reservas_vivas, row.reservas_vivas_pct, row.reservas_vivas_participation_pct), true, (row) => row.reservas_vivas, true],
        [(row) => formatCountConversionParticipation(row.oportunidades_caidas, row.oportunidades_caidas_pct, row.oportunidades_caidas_participation_pct), true, (row) => row.oportunidades_caidas, true],
        [(row) => formatCountConversionParticipation(row.cv_firmados, row.cv_firmados_pct, row.cv_firmados_participation_pct), true, (row) => row.cv_firmados, true],
    ], 'No hay datos de portales para los filtros seleccionados.');
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
            const className = numeric ? ' class="report-ui-table__numeric"' : '';
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

function initReservationsCommercialColumns() {
    const button = document.getElementById('reservationsCommercialColumnsButton');
    const popover = document.getElementById('reservationsCommercialColumnsPopover');

    if (!button || !popover) {
        return;
    }

    renderReservationsCommercialColumnsPopover();
    applyReservationsCommercialColumnVisibility();

    button.addEventListener('click', () => {
        popover.classList.toggle('is-hidden');
    });

    popover.addEventListener('change', (event) => {
        const input = event.target.closest('[data-column-toggle]');

        if (!input) {
            return;
        }

        const visible = new Set(reservationsCommercialVisibleColumns);
        const key = input.dataset.columnToggle;

        if (input.checked) {
            visible.add(key);
        } else {
            visible.delete(key);
        }

        reservationsCommercialVisibleColumns = reservationsCommercialColumnDefinitions
            .filter((column) => column.alwaysVisible || visible.has(column.key))
            .map((column) => column.key);

        localStorage.setItem(
            reservationsCommercialColumnsStorageKey,
            JSON.stringify(reservationsCommercialVisibleColumns)
        );
        applyReservationsCommercialColumnVisibility();
    });

    document.addEventListener('click', (event) => {
        if (!event.target.closest('[data-columns-menu]')) {
            popover.classList.add('is-hidden');
        }
    });
}

function renderReservationsCommercialColumnsPopover() {
    const root = document.getElementById('reservationsCommercialColumnsPopover');

    if (!root) {
        return;
    }

    root.innerHTML = reservationsCommercialColumnDefinitions
        .filter((column) => !column.alwaysVisible)
        .map((column) => `
            <label class="reservations-column-option">
                <input type="checkbox" data-column-toggle="${escapeHtml(column.key)}" ${reservationsCommercialVisibleColumns.includes(column.key) ? 'checked' : ''}>
                <span>${escapeHtml(column.label)}</span>
            </label>
        `)
        .join('');
}

function applyReservationsCommercialColumnVisibility() {
    document.querySelectorAll('#reservationsCommercialTable [data-column]').forEach((cell) => {
        cell.classList.toggle('is-hidden', !reservationsCommercialVisibleColumns.includes(cell.dataset.column));
    });
}

function bindReservationsCommercialSearch() {
    document.getElementById('reservationsCommercialSearch')?.addEventListener('input', applyReservationsCommercialSearchFilter);
}

function applyReservationsCommercialSearchFilter() {
    const term = String(document.getElementById('reservationsCommercialSearch')?.value || '')
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
        header.querySelector('[data-sort-indicator]')?.remove();

        if (index === state.columnIndex) {
            header.insertAdjacentHTML('beforeend', ` <span class="reservations-sort-indicator" data-sort-indicator>${state.direction === 'asc' ? '▲' : '▼'}</span>`);
        }
    });
}

function currentFilters() {
    const params = new URLSearchParams();
    const dateCriterion = isSummaryMode()
        ? 'created_date'
        : document.getElementById('dateCriterion')?.value;

    setParam(params, 'period', document.getElementById('period')?.value);
    setParam(params, 'date_criterion', dateCriterion);
    setParam(params, 'opportunity_type', document.getElementById('opportunityType')?.value);
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

function renderFilterOptions(filters) {
    const changedSelections = [
        fillSelect('commercial', filters.commercials || [], 'id', 'name'),
        fillSelect('commercialDelegation', (filters.commercial_delegations || []).map((item) => ({ id: item, name: item })), 'id', 'name'),
        fillSelect('zone', (filters.zones || []).map((item) => ({ id: item, name: item })), 'id', 'name'),
    ];

    return changedSelections.some(Boolean);
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

    const selectionIsValid = [...select.options].some((option) => option.value === current);
    select.value = selectionIsValid ? current : '';

    return current !== '' && !selectionIsValid;
}

function buildKpiAuditUrl(metric) {
    const params = new URLSearchParams(currentFilters());
    params.set('metric', metric);

    return `/informes/reservas-ventas/export/kpi-audit.csv?${params.toString()}`;
}

function kpiAuditLinkHtml(metric, label) {
    if (!window.reportUserCanExport || !metric) {
        return '';
    }

    return `<div class="reservations-kpi-actions"><a class="reservations-kpi-audit-link report-ui-button report-ui-button--ghost" href="${escapeHtml(buildKpiAuditUrl(metric))}" title="Auditar ${escapeHtml(label)}">Auditar KPI</a></div>`;
}

function setLoadingState(isLoading) {
    const loading = document.getElementById('loadingMessage');

    loading?.classList.toggle('is-hidden', !isLoading);

    if (isLoading) {
        document.getElementById('updatedBadge').textContent = 'Cargando fotografía local...';
        document.getElementById('emptyMessage')?.classList.add('is-hidden');
        [
            'productionKpis',
            'productionComparisonRows',
            'cohortKpis',
            'cohortComparisonRows',
            'currentLiveReservations',
            'currentLiveReservationsAudit',
            'insights',
            'commercialZoneRows',
            'commercialDelegationRows',
            'commercialRows',
            'portalRows',
        ].forEach((id) => {
            const element = document.getElementById(id);
            if (element) element.innerHTML = '';
        });
        document.getElementById('reservationsDataQualityPanel')?.classList.add('is-hidden');
    }

    document.querySelector('main')?.classList.toggle('dashboard-is-loading', isLoading);
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
        isCommercialPerformanceMode() || document.getElementById('period')?.value !== 'custom'
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

function periodTechnicalText(period) {
    const technical = period?.technical;
    if (!technical?.start_inclusive || !technical?.end_exclusive) {
        return 'Inicio incluido · fin excluido';
    }

    const timezone = technical.timezone || 'UTC';
    return `Inicio incluido: ${formatTechnicalDateTime(technical.start_inclusive, timezone)} · fin excluido: ${formatTechnicalDateTime(technical.end_exclusive, timezone)} · ${timezone}`;
}

function formatTechnicalDateTime(value, timeZone) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value || '-';

    return new Intl.DateTimeFormat('es-ES', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        timeZone,
    }).format(date);
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

    return `${Number(value).toLocaleString('es-ES', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} %`;
}

function formatAvailableNumber(value) {
    return value === null || value === undefined ? 'N/D' : formatNumber(value);
}

function formatAvailablePercent(value) {
    return value === null || value === undefined ? 'N/D' : formatPercent(value);
}

function formatTeamNumber(value) {
    return value === null || value === undefined || Number.isNaN(Number(value))
        ? 'N/D'
        : Number(value).toLocaleString('es-ES', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
}

function formatSignedTeamNumber(value, suffix = '') {
    if (value === null || value === undefined || Number.isNaN(Number(value))) return 'N/D';

    const rounded = Math.round(Number(value) * 10) / 10;
    const sign = rounded > 0 ? '+' : '';
    const formatted = Math.abs(rounded).toLocaleString('es-ES', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

    return `${sign}${rounded < 0 ? '-' : ''}${formatted}${suffix}`;
}

function formatReservationsDeviation(deviation, deviationPct) {
    if (deviation === null || deviation === undefined || deviationPct === null || deviationPct === undefined) return 'N/D';

    return `${formatSignedTeamNumber(deviation)} (${formatSignedTeamNumber(deviationPct, ' %')})`;
}

function formatDelegationRatioComparison(individualRatio, delegationRatio, difference) {
    const individual = escapeHtml(formatAvailablePercent(individualRatio));
    const reference = delegationRatio === null || delegationRatio === undefined
        ? 'N/D'
        : `${escapeHtml(formatTeamNumber(delegationRatio))} %`;
    let state = 'unavailable';
    let comparison = 'Comparativa no disponible';

    if (individualRatio !== null && individualRatio !== undefined
        && delegationRatio !== null && delegationRatio !== undefined
        && difference !== null && difference !== undefined) {
        const rounded = Math.round(Number(difference) * 10) / 10;
        const absolute = escapeHtml(formatTeamNumber(Math.abs(rounded)));
        if (rounded > 0) {
            state = 'above';
            comparison = `↑ ${absolute} puntos porcentuales por encima`;
        } else if (rounded < 0) {
            state = 'below';
            comparison = `↓ ${absolute} puntos porcentuales por debajo`;
        } else {
            state = 'equal';
            comparison = '→ Igual que su delegación';
        }
    }

    return `<span class="performance-comparison"><strong class="performance-comparison__commercial">${individual}</strong><span class="performance-comparison__reference">Delegación: ${reference}</span><span class="performance-comparison__delta performance-comparison__delta--${state}">${comparison}</span></span>`;
}

function formatCurrency(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '-';
    }

    return new Intl.NumberFormat('es-ES', { style: 'currency', currency: 'EUR' }).format(Number(value));
}

function formatSignedNumber(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '-';
    }

    const number = Number(value);
    return `${number > 0 ? '+' : ''}${fmt.format(number)}`;
}

function formatCountPercent(count, percent) {
    return `<span class="reservations-metric-value">${escapeHtml(formatNumber(count))}</span><span class="reservations-metric-percent">(${escapeHtml(formatPercent(percent))})</span>`;
}

function formatCountConversionParticipation(count, ratioOverOpportunities, participation) {
    return `<span class="reservations-metric-value">${escapeHtml(formatNumber(count))}</span>`
        + `<span class="reservations-metric-percent">Sobre oportunidades ${escapeHtml(formatPercent(ratioOverOpportunities))}</span>`
        + `<span class="reservations-metric-percent">Participación ${escapeHtml(formatPercent(participation))}</span>`;
}

function formatComparisonValue(row, key) {
    const value = row[key];
    const percent = row[`${key}_pct`];

    return row.is_compact ? formatCountPercent(value, percent) : escapeHtml(formatNumber(value));
}

function formatComparisonDiff(row) {
    const count = formatDiff(row.diferencia, false);

    if (!row.is_compact || row.diferencia_pct_puntos === null || row.diferencia_pct_puntos === undefined) {
        return escapeHtml(count);
    }

    return `<span class="reservations-metric-value">${escapeHtml(count)}</span><span class="reservations-metric-percent">(${escapeHtml(formatDiff(row.diferencia_pct_puntos, true))})</span>`;
}

function formatDiff(value, isPercentage) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '-';
    }

    const number = Number(value);
    const sign = number > 0 ? '+' : '';

    return isPercentage
        ? `${sign}${number.toLocaleString('es-ES', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} pp`
        : `${sign}${fmt.format(number)}`;
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
    const defaultColumns = definitions
        .filter((column) => column.alwaysVisible || column.defaultVisible)
        .map((column) => column.key);

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
