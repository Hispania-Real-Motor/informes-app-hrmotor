const tableSortState = new Map();
const commissionSummaryColumnsStorageKey = 'commissionSummaryColumns';
let syncCommissionSummaryScrollbar = () => {};
let syncCommissionDelegationScrollbar = () => {};
const commissionSummaryColumnDefinitions = [
    { key: 'commercial_name', label: 'Comercial', alwaysVisible: true },
    { key: 'commission_mode', label: 'Tipo', alwaysVisible: true },
    { key: 'final_commission', label: 'Comision final', alwaysVisible: true },
    { key: 'deliveries_count', label: 'Entregas', alwaysVisible: true },
    { key: 'sales_count', label: 'N ventas', alwaysVisible: true },
    { key: 'sales_amount', label: 'Ventas', alwaysVisible: true },
    { key: 'appraisals_count', label: 'N tasaciones', alwaysVisible: true },
    { key: 'appraisals_amount', label: 'Tasaciones', alwaysVisible: true },
    { key: 'changes_count', label: 'N cambios', alwaysVisible: true },
    { key: 'changes_amount', label: 'Cambios', alwaysVisible: true },
    { key: 'purchases_amount', label: 'Compras', alwaysVisible: true },
    { key: 'appraiser_purchase_tier', label: 'Tramo tasador' },
    { key: 'appraiser_purchase_rate', label: 'EUR/compra tasador' },
    { key: 'appraiser_financing_commission', label: 'Financiacion tasador 3%' },
    { key: 'appraiser_speed_amount', label: 'Incentivo rapidez tasador' },
    { key: 'shared_amount', label: 'Compartidas', alwaysVisible: true },
    { key: 'discount_penalty_amount', label: 'Descuento 5%' },
    { key: 'stock_150_amount', label: 'Stock +150' },
    { key: 'bonus_15_amount', label: 'Bonus +15' },
    { key: 'prima_total', label: 'Prima total' },
    { key: 'delivery_bracket', label: 'Tramo' },
    { key: 'prima_adjusted', label: 'Prima ajustada', alwaysVisible: true },
    { key: 'reviews_penalty', label: 'Pen. resenas', alwaysVisible: true },
    { key: 'reviews_percentage', label: '% resenas' },
    { key: 'financing_penalty', label: 'Pen. financiacion', alwaysVisible: true },
    { key: 'financing_cancellation_penalty_amount', label: 'Pen. cancelacion fin.', alwaysVisible: true },
    { key: 'financing_percentage', label: '% financiacion' },
    { key: 'total_penalties', label: 'Penalizaciones', alwaysVisible: true },
    { key: 'financing_product_amount', label: 'Prod. financiacion' },
    { key: 'guarantee_product_amount', label: 'Prod. garantias' },
];
let commissionSummaryVisibleColumns = loadVisibleColumns(
    commissionSummaryColumnsStorageKey,
    commissionSummaryColumnDefinitions
);

document.addEventListener('DOMContentLoaded', () => {
    bindCommissionTabs();
    bindCallCenterBrowser();
    bindAgentBrowsers();
    bindCommissionExportMonth();
    bindCommercialSummarySearch();
    bindCommissionDetailTabs();
    bindSortableTables();
    initCommissionSummaryColumns();
    initCommissionSummaryHorizontalScroll();
    initCommissionDelegationHorizontalScroll();
    initHorizontalScrollRegions();
    bindKpiTooltips();
    bindCommissionIncidentDialogs();
});

function bindCommissionIncidentDialogs() {
    document.querySelectorAll('[data-commission-incident-trigger]').forEach((trigger) => {
        trigger.addEventListener('click', () => {
            const dialog = document.getElementById(trigger.dataset.commissionIncidentTrigger);
            if (dialog && typeof dialog.showModal === 'function') {
                dialog.showModal();
            }
        });
    });
}

function bindCommissionExportMonth() {
    const monthInput = document.getElementById('month');
    const exportLink = document.getElementById('commissionExport');

    if (!monthInput || !exportLink) {
        return;
    }

    monthInput.addEventListener('change', () => {
        if (!monthInput.value) {
            return;
        }

        const url = new URL(exportLink.href, window.location.origin);
        url.searchParams.set('month', monthInput.value);
        exportLink.href = url.toString();
    });
}

function bindCommissionTabs() {
    const triggers = [...document.querySelectorAll('[data-commission-tab-trigger]')];
    const panels = [...document.querySelectorAll('[data-commission-tab-panel]')];

    if (!triggers.length || !panels.length) {
        return;
    }

    const activate = (targetTab) => {
        triggers.forEach((trigger) => {
            trigger.classList.toggle('active', trigger.dataset.commissionTabTrigger === targetTab);
        });

        panels.forEach((panel) => {
            panel.classList.toggle('is-hidden', panel.dataset.commissionTabPanel !== targetTab);
        });

        window.requestAnimationFrame(() => {
            if (targetTab === 'summary') {
                syncCommissionSummaryScrollbar();
            }

            if (targetTab === 'delegations') {
                syncCommissionDelegationScrollbar();
                window.requestAnimationFrame(() => syncCommissionDelegationScrollbar());
            }

            syncHorizontalScrollRegions();
        });
    };

    triggers.forEach((trigger) => {
        trigger.addEventListener('click', () => {
            if (trigger.dataset.commissionTabCurrent === 'true') {
                activate(trigger.dataset.commissionTabTrigger);
                return;
            }

            const targetUrl = trigger.dataset.commissionTabUrl;

            if (targetUrl) {
                window.location.assign(targetUrl);
                return;
            }

            activate(trigger.dataset.commissionTabTrigger);
        });
    });

    const currentTrigger = triggers.find((trigger) => trigger.dataset.commissionTabCurrent === 'true') || triggers[0];

    if (currentTrigger) {
        activate(currentTrigger.dataset.commissionTabTrigger);
    }
}

function bindAgentBrowsers() {
    document.querySelectorAll('[data-agent-browser]').forEach((browser) => {
        bindAgentBrowser(browser);
    });
}

function bindCommercialSummarySearch() {
    const input = document.querySelector('[data-commercial-summary-search-input]');
    const rows = [...document.querySelectorAll('[data-commercial-summary-row]')];

    if (!input || !rows.length) {
        return;
    }

    const filterRows = () => {
        const term = (input.value || '').trim().toLowerCase();

        rows.forEach((row) => {
            const haystack = row.dataset.commercialSummarySearch || '';
            row.classList.toggle('is-hidden', term !== '' && !haystack.includes(term));
        });
    };

    input.addEventListener('input', filterRows);
    filterRows();
}

function bindCallCenterBrowser() {
    const browser = document.querySelector('[data-call-center-browser]');
    const payloadNode = document.getElementById('callCenterAgentPayload');

    if (!browser || !payloadNode) {
        return;
    }

    const searchInput = browser.querySelector('[data-agent-search-input]');
    const emptyState = browser.querySelector('[data-agent-empty]');
    const options = [...browser.querySelectorAll('[data-agent-option]')];
    const panelRoot = browser.querySelector('[data-call-center-panel-root]');

    if (!options.length || !panelRoot) {
        return;
    }

    let payload = {};

    try {
        payload = JSON.parse(payloadNode.textContent || '{}');
    } catch (error) {
        payload = {};
    }

    let activeTab = 'purchases';

    const visibleOptions = () => options.filter((option) => !option.classList.contains('is-hidden'));

    const activateAgent = (agentId) => {
        const row = payload[agentId];

        options.forEach((option) => {
            option.classList.toggle('is-active', option.dataset.agentId === agentId && !option.classList.contains('is-hidden'));
        });

        if (!row) {
            panelRoot.classList.add('is-hidden');
            return;
        }

        panelRoot.classList.remove('is-hidden');
        panelRoot.dataset.agentId = agentId;
        panelRoot.innerHTML = renderCallCenterAgentPanel(row, activeTab);
        bindCallCenterPanelTabs(panelRoot, (nextTab) => {
            activeTab = nextTab;
            activateAgent(agentId);
        });
        panelRoot.querySelectorAll('table[data-sortable-table]').forEach((table) => makeTableSortable(table));
    };

    const filterOptions = () => {
        const term = (searchInput?.value || '').trim().toLowerCase();

        options.forEach((option) => {
            const haystack = option.dataset.agentSearch || '';
            option.classList.toggle('is-hidden', term !== '' && !haystack.includes(term));
        });

        const firstVisible = visibleOptions()[0];
        emptyState?.classList.toggle('is-hidden', Boolean(firstVisible));

        if (firstVisible) {
            activateAgent(firstVisible.dataset.agentId);
        } else {
            panelRoot.classList.add('is-hidden');
        }
    };

    options.forEach((option) => {
        option.addEventListener('click', () => {
            activateAgent(option.dataset.agentId);
        });
    });

    searchInput?.addEventListener('input', filterOptions);
    filterOptions();
}

function bindCallCenterPanelTabs(panelRoot, onChange) {
    const triggers = [...panelRoot.querySelectorAll('[data-commercial-detail-tab-trigger]')];

    if (!triggers.length) {
        return;
    }

    triggers.forEach((trigger) => {
        trigger.addEventListener('click', () => {
            onChange(trigger.dataset.commercialDetailTabTrigger);
        });
    });
}

function renderCallCenterAgentPanel(row, activeTab) {
    return `
        <section class="call-center-agent-hero">
            ${renderCallCenterMetricCard('Agente / Captador', row.agent_name)}
            ${renderCallCenterMetricCard('Total automatico', `${formatCurrencyEs(row.automatic_total)} EUR`)}
            ${renderCallCenterMetricCard('Total final', `${formatCurrencyEs(row.final_total)} EUR`)}
            ${renderCallCenterMetricCard('Observaciones', row.observations || 'Sin incidencias')}
        </section>

        <nav class="tabs-main commission-detail-tabs" aria-label="Detalle Call Center por bloque">
            ${renderCallCenterTabButton('purchases', 'Compras', activeTab)}
            ${renderCallCenterTabButton('sales', 'Ventas', activeTab)}
            ${renderCallCenterTabButton('changes', 'Cambios', activeTab)}
            ${renderCallCenterTabButton('german', 'Negociaciones German', activeTab)}
            ${renderCallCenterTabButton('facilitea', 'Facilitea', activeTab)}
        </nav>

        <div class="commission-detail-grid">
            <div class="${activeTab === 'purchases' ? '' : 'is-hidden'}" data-commercial-detail-tab-panel="purchases">
                <div class="table-shell">
                    ${renderCallCenterTable(
                        `call-center-purchases-${escapeHtml(row.agent_key)}`,
                        [
                            ['Opportunity Id', (detail) => detail.opportunity_id || '-'],
                            ['Opportunity Name', (detail) => detail.opportunity_name || '-'],
                            ['Record Type', (detail) => detail.record_type_name || '-'],
                            ['Captador', (detail) => detail.captador || '-'],
                            ['Comision Captador', (detail) => formatCurrencyEs(detail.commission_amount), 'num'],
                            ['Fecha firma contrato', (detail) => detail.contract_signed_date || '-'],
                            ['Vehiculo a tasar', (detail) => detail.vehicle_to_appraise || '-'],
                            ['Fecha captador', (detail) => detail.capture_date || '-'],
                            ['Account Name', (detail) => detail.account_name || '-'],
                        ],
                        row.details?.purchases || [],
                        'Sin compras/tasaciones comisionables para este agente.'
                    )}
                </div>
            </div>

            <div class="${activeTab === 'sales' ? '' : 'is-hidden'}" data-commercial-detail-tab-panel="sales">
                <div class="table-shell">
                    ${renderCallCenterTable(
                        `call-center-sales-${escapeHtml(row.agent_key)}`,
                        [
                            ['Opportunity Id', (detail) => detail.opportunity_id || '-'],
                            ['Opportunity Name', (detail) => detail.opportunity_name || '-'],
                            ['Record Type', (detail) => detail.record_type_name || '-'],
                            ['Captador', (detail) => detail.captador || '-'],
                            ['Comision Captador', (detail) => formatCurrencyEs(detail.commission_amount), 'num'],
                            ['Fecha firma contrato', (detail) => detail.contract_signed_date || '-'],
                            ['Vehiculo a tasar', (detail) => detail.vehicle_to_appraise || '-'],
                            ['Vehiculo de interes', (detail) => detail.vehicle_interest || '-'],
                            ['Account Name', (detail) => detail.account_name || '-'],
                            ['Fuente de origen', (detail) => detail.source || '-'],
                            ['Opportunity Owner', (detail) => detail.owner_name || '-'],
                        ],
                        row.details?.sales || [],
                        'Sin ventas comisionables para este agente.'
                    )}
                </div>
            </div>

            <div class="${activeTab === 'changes' ? '' : 'is-hidden'}" data-commercial-detail-tab-panel="changes">
                <div class="table-shell">
                    ${renderCallCenterTable(
                        `call-center-changes-${escapeHtml(row.agent_key)}`,
                        [
                            ['Opportunity Id', (detail) => detail.opportunity_id || '-'],
                            ['Opportunity Name', (detail) => detail.opportunity_name || '-'],
                            ['Record Type', (detail) => detail.record_type_name || '-'],
                            ['Captador', (detail) => detail.captador || '-'],
                            ['Comision Captador', (detail) => formatCurrencyEs(detail.commission_amount), 'num'],
                            ['Fecha firma contrato', (detail) => detail.contract_signed_date || '-'],
                            ['Vehiculo a tasar', (detail) => detail.vehicle_to_appraise || '-'],
                            ['Vehiculo de interes', (detail) => detail.vehicle_interest || '-'],
                            ['Account Name', (detail) => detail.account_name || '-'],
                            ['Fuente de origen', (detail) => detail.source || '-'],
                            ['Opportunity Owner', (detail) => detail.owner_name || '-'],
                        ],
                        row.details?.changes || [],
                        'Sin cambios comisionables para este agente.'
                    )}
                </div>
            </div>

            <div class="${activeTab === 'german' ? '' : 'is-hidden'}" data-commercial-detail-tab-panel="german">
                <div class="table-shell">
                    ${renderCallCenterTable(
                        `call-center-german-${escapeHtml(row.agent_key)}`,
                        [
                            ['Tasacion Id', (detail) => detail.tasacion_id || '-'],
                            ['Tasacion', (detail) => detail.tasacion_name || '-'],
                            ['Opportunity Id', (detail) => detail.opportunity_id || '-'],
                            ['Opportunity Name', (detail) => detail.opportunity_name || '-'],
                            ['Fecha firma contrato', (detail) => detail.contract_signed_date || '-'],
                            ['Seguimiento', (detail) => detail.tracking_name || '-'],
                            ['Negociacion 1', (detail) => detail.negotiation_1 || '-'],
                            ['Negociacion 2', (detail) => detail.negotiation_2 || '-'],
                            ['Negociacion 3', (detail) => detail.negotiation_3 || '-'],
                            ['Negociacion 4', (detail) => detail.negotiation_4 || '-'],
                            ['Importe', (detail) => formatCurrencyEs(detail.commission_amount), 'num'],
                        ],
                        row.details?.german_negotiations || [],
                        'Sin negociaciones German para este agente en el mes.'
                    )}
                </div>
            </div>

            <div class="${activeTab === 'facilitea' ? '' : 'is-hidden'}" data-commercial-detail-tab-panel="facilitea">
                <div class="table-shell">
                    ${renderCallCenterTable(
                        `call-center-facilitea-${escapeHtml(row.agent_key)}`,
                        [
                            ['Opportunity Id', (detail) => detail.opportunity_id || '-'],
                            ['Opportunity Owner', (detail) => detail.owner_name || '-'],
                            ['Dias entrega', (detail) => detail.delivery_days ?? '-', 'num'],
                            ['Opportunity Name', (detail) => detail.opportunity_name || '-'],
                            ['Account Name', (detail) => detail.account_name || '-'],
                            ['Fecha firma contrato', (detail) => detail.contract_signed_date || '-'],
                            ['Coopropietario', (detail) => detail.coowner_name || '-'],
                            ['Delegacion propietario', (detail) => detail.owner_delegation || '-'],
                            ['Vehiculo de interes', (detail) => detail.vehicle_interest || '-'],
                            ['Fecha entrega', (detail) => detail.delivery_date || '-'],
                            ['Importe', (detail) => formatCurrencyEs(detail.commission_amount), 'num'],
                        ],
                        row.details?.facilitea || [],
                        'Sin operaciones Facilitea para este agente en el mes.'
                    )}
                </div>
            </div>
        </div>
    `;
}

function renderCallCenterMetricCard(label, value) {
    return `
        <article class="card campaign-context-card">
            <span>${escapeHtml(label)}</span>
            <strong>${escapeHtml(value)}</strong>
        </article>
    `;
}

function renderCallCenterTabButton(key, label, activeTab) {
    return `
        <button type="button" class="main-tab ${activeTab === key ? 'active' : ''}" data-commercial-detail-tab-trigger="${escapeHtml(key)}">
            ${escapeHtml(label)}
        </button>
    `;
}

function renderCallCenterTable(tableId, columns, rows, emptyMessage) {
    const header = columns
        .map(([label, , align]) => `<th ${align === 'num' ? 'class="num"' : ''} data-sortable="true">${escapeHtml(label)}</th>`)
        .join('');
    const body = rows.length
        ? rows.map((row) => `
            <tr>
                ${columns.map(([, formatter, align]) => {
                    const value = formatter(row);
                    const rendered = value === null || value === undefined || value === '' ? '-' : String(value);

                    return `<td ${align === 'num' ? 'class="num"' : ''}>${escapeHtml(rendered)}</td>`;
                }).join('')}
            </tr>
        `).join('')
        : `<tr><td colspan="${columns.length}">${escapeHtml(emptyMessage)}</td></tr>`;

    return `
        <table data-sortable-table="${escapeHtml(tableId)}">
            <thead>
                <tr>${header}</tr>
            </thead>
            <tbody data-sort-body="${escapeHtml(tableId)}">
                ${body}
            </tbody>
        </table>
    `;
}

function formatCurrencyEs(value) {
    return formatNumberEs(value, 2);
}

function formatNumberEs(value, decimals = 0) {
    const number = Number(value || 0);

    if (Number.isNaN(number)) {
        return decimals > 0 ? '0,00' : '0';
    }

    return number.toLocaleString('es-ES', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });
}

function bindAgentBrowser(browser) {
    const searchInput = browser.querySelector('[data-agent-search-input]');
    const emptyState = browser.querySelector('[data-agent-empty]');
    const options = [...browser.querySelectorAll('[data-agent-option]')];
    const panels = [...browser.querySelectorAll('[data-agent-panel]')];

    if (!options.length || !panels.length) {
        return;
    }

    const visibleOptions = () => options.filter((option) => !option.classList.contains('is-hidden'));

    const activateAgent = (agentId) => {
        let matched = false;

        options.forEach((option) => {
            const isActive = option.dataset.agentId === agentId && !option.classList.contains('is-hidden');
            option.classList.toggle('is-active', isActive);
            matched = matched || isActive;
        });

        panels.forEach((panel) => {
            const isActive = panel.dataset.agentId === agentId && matched;
            panel.classList.toggle('is-hidden', !isActive);
            panel.classList.toggle('is-active', isActive);
        });

        if (!matched) {
            panels.forEach((panel) => panel.classList.add('is-hidden'));
        }
    };

    const filterOptions = () => {
        const term = (searchInput?.value || '').trim().toLowerCase();

        options.forEach((option) => {
            const haystack = option.dataset.agentSearch || '';
            option.classList.toggle('is-hidden', term !== '' && !haystack.includes(term));
        });

        const firstVisible = visibleOptions()[0];
        emptyState?.classList.toggle('is-hidden', Boolean(firstVisible));

        if (firstVisible) {
            activateAgent(firstVisible.dataset.agentId);
        } else {
            activateAgent('');
        }
    };

    options.forEach((option) => {
        option.addEventListener('click', () => {
            activateAgent(option.dataset.agentId);
        });
    });

    searchInput?.addEventListener('input', filterOptions);
    filterOptions();
}

function bindCommissionDetailTabs() {
    const commercialPanels = [...document.querySelectorAll('[data-agent-panel]')];

    commercialPanels.forEach((panel) => {
        const triggers = [...panel.querySelectorAll('[data-commercial-detail-tab-trigger]')];
        const detailPanels = [...panel.querySelectorAll('[data-commercial-detail-tab-panel]')];

        if (!triggers.length || !detailPanels.length) {
            return;
        }

        const activate = (targetTab) => {
            triggers.forEach((trigger) => {
                trigger.classList.toggle('active', trigger.dataset.commercialDetailTabTrigger === targetTab);
            });

            detailPanels.forEach((detailPanel) => {
                detailPanel.classList.toggle('is-hidden', detailPanel.dataset.commercialDetailTabPanel !== targetTab);
            });

            window.requestAnimationFrame(() => syncHorizontalScrollRegions());
        };

        triggers.forEach((trigger) => {
            trigger.addEventListener('click', () => {
                activate(trigger.dataset.commercialDetailTabTrigger);
            });
        });

        activate(triggers[0].dataset.commercialDetailTabTrigger);
    });
}

function initHorizontalScrollRegions() {
    document.querySelectorAll('[data-horizontal-scroll-region]').forEach((region) => {
        initHorizontalScrollRegion(region);
    });
}

function initHorizontalScrollRegion(region) {
    if (!region || region.dataset.horizontalScrollReady === 'true') {
        return;
    }

    const topScroll = region.querySelector('[data-scroll-top]');
    const bottomScroll = region.querySelector('[data-scroll-bottom]');
    const topSpacer = region.querySelector('[data-scroll-spacer-top]');
    const bottomSpacer = region.querySelector('[data-scroll-spacer-bottom]');
    const shell = region.querySelector('[data-scroll-shell]');
    const table = region.querySelector('[data-scroll-table]');

    if (!topScroll || !bottomScroll || !topSpacer || !bottomSpacer || !shell || !table) {
        return;
    }

    let syncing = false;

    const syncScrollbar = () => {
        const contentWidth = Math.max(table.scrollWidth, table.offsetWidth, shell.scrollWidth);
        topSpacer.style.width = `${contentWidth}px`;
        bottomSpacer.style.width = `${contentWidth}px`;

        if (syncing) {
            return;
        }

        syncing = true;
        topScroll.scrollLeft = shell.scrollLeft;
        bottomScroll.scrollLeft = shell.scrollLeft;
        syncing = false;
    };

    const syncShellFromProxy = (proxy) => {
        proxy.addEventListener('scroll', () => {
            if (syncing) {
                return;
            }

            syncing = true;
            shell.scrollLeft = proxy.scrollLeft;
            topScroll.scrollLeft = proxy.scrollLeft;
            bottomScroll.scrollLeft = proxy.scrollLeft;
            syncing = false;
        });
    };

    syncShellFromProxy(topScroll);
    syncShellFromProxy(bottomScroll);

    shell.addEventListener('scroll', () => {
        if (syncing) {
            return;
        }

        syncing = true;
        topScroll.scrollLeft = shell.scrollLeft;
        bottomScroll.scrollLeft = shell.scrollLeft;
        syncing = false;
    });

    if (typeof ResizeObserver === 'function') {
        const resizeObserver = new ResizeObserver(() => syncScrollbar());
        resizeObserver.observe(table);
        resizeObserver.observe(shell);
        region._horizontalResizeObserver = resizeObserver;
    } else {
        window.addEventListener('resize', syncScrollbar);
    }

    region.dataset.horizontalScrollReady = 'true';
    region._syncHorizontalScroll = syncScrollbar;
    window.requestAnimationFrame(syncScrollbar);
}

function syncHorizontalScrollRegions() {
    document.querySelectorAll('[data-horizontal-scroll-region]').forEach((region) => {
        if (typeof region._syncHorizontalScroll === 'function') {
            region._syncHorizontalScroll();
        }
    });
}

function bindSortableTables() {
    document.querySelectorAll('table[data-sortable-table]').forEach((table) => {
        makeTableSortable(table);
    });
}

function makeTableSortable(table) {
    const tbody = table.querySelector('tbody[data-sort-body]');
    const headers = [...table.querySelectorAll('thead th[data-sortable="true"]')];

    if (!tbody || !headers.length) {
        return;
    }

    headers.forEach((header) => {
        const columnIndex = header.cellIndex;

        header.addEventListener('click', () => {
            const stateKey = tbody.dataset.sortBody;
            const current = tableSortState.get(stateKey);
            const direction = current?.columnIndex === columnIndex && current.direction === 'asc' ? 'desc' : 'asc';
            const state = { columnIndex, direction };

            tableSortState.set(stateKey, state);
            sortRowsByColumn(tbody, columnIndex, direction);
            updateSortIndicators(table, state);
        });
    });
}

function sortRowsByColumn(tbody, columnIndex, direction) {
    const rows = [...tbody.querySelectorAll('tr')];
    const sortableRows = rows.filter((row) => row.children.length > 1);
    const fillerRows = rows.filter((row) => row.children.length <= 1);
    const multiplier = direction === 'asc' ? 1 : -1;

    sortableRows.sort((a, b) => {
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

    [...sortableRows, ...fillerRows].forEach((row) => tbody.appendChild(row));
}

function parseSortableValue(value) {
    const raw = String(value || '').trim();

    if (raw === '' || raw === '-') {
        return { empty: true, type: 'text', value: '' };
    }

    const primary = raw.split('(')[0].trim();
    const normalized = primary
        .replaceAll('EUR', '')
        .replaceAll('eur', '')
        .replaceAll('€', '')
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
    table.querySelectorAll('thead th[data-sortable="true"]').forEach((header) => {
        header.querySelector('.sort-indicator')?.remove();

        if (header.cellIndex === state.columnIndex) {
            header.insertAdjacentHTML('beforeend', ` <span class="sort-indicator">${state.direction === 'asc' ? '▲' : '▼'}</span>`);
        }
    });
}

function initCommissionSummaryColumns() {
    const table = document.getElementById('commissionSummaryTable');
    const button = document.getElementById('commissionSummaryColumnsButton');
    const popover = document.getElementById('commissionSummaryColumnsPopover');

    if (!table || !button || !popover) {
        return;
    }

    renderCommissionSummaryColumnsPopover();
    applyCommissionSummaryColumnVisibility();

    button.addEventListener('click', () => {
        popover.classList.toggle('is-hidden');
    });

    popover.addEventListener('change', (event) => {
        const input = event.target.closest('[data-column-toggle]');

        if (!input) {
            return;
        }

        const visible = new Set(commissionSummaryVisibleColumns);
        const key = input.dataset.columnToggle;

        if (input.checked) {
            visible.add(key);
        } else {
            visible.delete(key);
        }

        commissionSummaryVisibleColumns = commissionSummaryColumnDefinitions
            .filter((column) => column.alwaysVisible || visible.has(column.key))
            .map((column) => column.key);

        localStorage.setItem(
            commissionSummaryColumnsStorageKey,
            JSON.stringify(commissionSummaryVisibleColumns)
        );

        applyCommissionSummaryColumnVisibility();
    });

    document.addEventListener('click', (event) => {
        if (!event.target.closest('.columns-menu')) {
            popover.classList.add('is-hidden');
        }
    });
}

function initCommissionSummaryHorizontalScroll() {
    initHorizontalScroll(
        'commissionSummaryTableScrollTop',
        'commissionSummaryTableScrollTopSpacer',
        'commissionSummaryTableShell',
        'commissionSummaryTable',
        (syncFn) => {
            syncCommissionSummaryScrollbar = syncFn;
        }
    );
}

function initCommissionDelegationHorizontalScroll() {
    initHorizontalScroll(
        'commissionDelegationTableScrollTop',
        'commissionDelegationTableScrollTopSpacer',
        'commissionDelegationTableShell',
        'commissionDelegationTable',
        (syncFn) => {
            syncCommissionDelegationScrollbar = syncFn;
        }
    );
}

function initHorizontalScroll(topId, spacerId, shellId, tableId, setSyncFn) {
    const topScroll = document.getElementById(topId);
    const spacer = document.getElementById(spacerId);
    const shell = document.getElementById(shellId);
    const table = document.getElementById(tableId);

    if (!topScroll || !spacer || !shell || !table) {
        return;
    }

    let syncingFromTop = false;
    let syncingFromShell = false;

    const syncScrollbar = () => {
        const contentWidth = Math.max(table.scrollWidth, table.offsetWidth, shell.scrollWidth);
        spacer.style.width = `${contentWidth}px`;
        topScroll.scrollLeft = shell.scrollLeft;
    };

    setSyncFn(syncScrollbar);

    topScroll.addEventListener('scroll', () => {
        if (syncingFromShell) {
            return;
        }

        syncingFromTop = true;
        shell.scrollLeft = topScroll.scrollLeft;
        syncingFromTop = false;
    });

    shell.addEventListener('scroll', () => {
        if (syncingFromTop) {
            return;
        }

        syncingFromShell = true;
        topScroll.scrollLeft = shell.scrollLeft;
        syncingFromShell = false;
    });

    if (typeof ResizeObserver === 'function') {
        const resizeObserver = new ResizeObserver(() => syncScrollbar());
        resizeObserver.observe(table);
        resizeObserver.observe(shell);
    } else {
        window.addEventListener('resize', syncScrollbar);
    }

    window.addEventListener('load', syncScrollbar);
    syncScrollbar();
}

function renderCommissionSummaryColumnsPopover() {
    const popover = document.getElementById('commissionSummaryColumnsPopover');

    if (!popover) {
        return;
    }

    popover.innerHTML = commissionSummaryColumnDefinitions
        .filter((column) => !column.alwaysVisible)
        .map((column) => `
            <label class="column-option switch-option">
                <input type="checkbox" data-column-toggle="${escapeHtml(column.key)}" ${commissionSummaryVisibleColumns.includes(column.key) ? 'checked' : ''}>
                <span>${escapeHtml(column.label)}</span>
            </label>
        `)
        .join('');
}

function applyCommissionSummaryColumnVisibility() {
    document.querySelectorAll('#commissionSummaryTable [data-column]').forEach((cell) => {
        cell.classList.toggle('is-hidden', !commissionSummaryVisibleColumns.includes(cell.dataset.column));
    });

    syncCommissionSummaryScrollbar();
}

function loadVisibleColumns(storageKey, definitions) {
    const defaults = definitions
        .filter((column) => column.alwaysVisible)
        .map((column) => column.key);

    try {
        const stored = JSON.parse(localStorage.getItem(storageKey) || '[]');

        if (!Array.isArray(stored)) {
            return defaults;
        }

        return definitions
            .filter((column) => column.alwaysVisible || stored.includes(column.key))
            .map((column) => column.key);
    } catch (error) {
        return defaults;
    }
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function bindKpiTooltips() {
    const tooltips = {
        'Mes analizado': 'Mes cerrado usado por el informe. Pivota por cv_signed_date, que viene de Fecha_firma_contrato__c.',
        Oportunidades: 'Conteo de salesforce_opportunities con cv_signed=true, stage_name distinto de Cerrada perdida, record_type Venta/Cambio/Tasacion y gestion_de_venta false o null.',
        Resenas: 'Conteo de salesforce_reviews creadas dentro del mes. Fuente Salesforce: objeto Resena__c.',
        Estado: 'Indica si existen bloqueos de datos o estructura que impiden calcular el informe.',
        Comerciales: 'Numero de comerciales o tasadores con actividad real en el resumen: venta/cambio del mes o compra liquidada por venta posterior del vehiculo.',
        'Comision final': 'Formula: max(prima ajustada - penalizaciones, 0) + producto financiacion + producto garantias.',
        'Prima ajustada': 'Formula: prima total x tramo de entregas.',
        Penalizaciones: 'Suma de penalizacion por garantias, penalizacion por resenas y penalizacion por financiacion.',
        Entregas: 'Conteo de oportunidades de tipo Venta y Cambio del mes. Cada entrega suma 60 EUR.',
        Operaciones: 'Conteo de oportunidades de tipo Venta, Cambio y Tasacion del mes.',
        'Compras liquidadas': 'Compras historicas enlazadas a una venta del mes. Se atribuyen al Comprador_oportunidad__c del Product2 y la formula es: precio_venta - precio_compra - descuento + beneficio_financiacion + garantia. Sobre ese resultado se aplica el 1.8%.',
        Compartidas: 'Suma de 30 EUR por cada oportunidad con Entrega_Compartida__c.',
        Ventas: 'Importe calculado como entregas x 60 EUR.',
        'Compras / Tasaciones': 'Tasaciones con Captador informado, gestion de venta false y Comision Captador sumada como importe final.',
        Cambios: 'Cambios comisionados una sola vez dentro del bloque de ventas/cambios usando Comision Captador.',
        'Negociaciones German': 'Tasaciones con Seguimiento German y Negociaci_n_1__c informada. Si falta fecha firma contrato en local, se usa CreatedDate del registro sincronizado.',
        Facilitea: 'Operaciones Facilitea validas con owner Vanessa/Vanesa y 5 EUR por operacion.',
        'Agentes / captadores': 'Numero de filas agrupadas por captador o agente dentro del mes y rango de contrato activo.',
        'Operaciones base': 'Oportunidades locales del rango activo evaluadas para compras, ventas, cambios y Facilitea.',
        'Tasaciones sync': 'Tasaciones sincronizadas del rango activo usando Fecha firma contrato y, si no existe, fallback a CreatedDate.',
        'Sin captador': 'Conteo auditor de oportunidades con señales reales de Call Center pero sin Captador principal informado.',
        'Comisiones vacias': 'Operaciones con Comision Captador vacia; se computan a 0 EUR y quedan visibles para revision.',
        'Total automatico': 'Suma automatica de compras, ventas, cambios, negociaciones German y Facilitea.',
        Compras: 'Suma de Comision Captador del bloque compras/tasaciones.',
        'Neg. German': 'Suma de negociaciones atribuidas a German Olsen a 5 EUR por tasacion valida.',
        'Stock +150': 'Suma de 10 EUR por cada entrega con Dias_en_stock__c >= 150.',
        'Bonus +15': 'Suma de 30 EUR por cada entrega a partir de la numero 16.',
        'Prima total': 'Formula: ventas + compras + compartidas - descuento 5% + stock +150 + bonus +15.',
        'Prima neta': 'Formula: max(prima ajustada - penalizaciones, 0).',
        'Prod. financiacion': 'Producto calculado por tramos sobre Beneficio_financiacion_comercial__c.',
        'Prod. garantias': 'Producto calculado por tramos sobre Garant_a_Total__c.',
    };

    document.querySelectorAll('.commercial-commissions-report .campaign-kpi .kpi-label, .commercial-commissions-report .campaign-context-card > span, .commercial-commissions-report .platform-metric-item > span')
        .forEach((element) => {
            const label = element.textContent.trim();
            const tooltip = tooltips[label];

            if (!tooltip) {
                return;
            }

            element.classList.add('kpi-tooltip');
            element.dataset.kpiTooltip = tooltip;
            element.removeAttribute('title');
        });
}
