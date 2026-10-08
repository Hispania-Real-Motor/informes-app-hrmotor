<x-reports.app-shell title="Intereses" current-report="leads" :updated-badge-text="'Cargando fotograf'.mb_chr(237).'a local...'">
    <x-slot:head>
        @vite([
            'resources/css/reports/leads-dashboard.css',
            'resources/js/reports/leads-dashboard.js'
        ])
    </x-slot:head>
<div class="wrap">
    <script>
        window.reportUserCanExport = @json($reportUserCanExport ?? false);
        window.reportUserCanAudit = @json($reportUserCanAudit ?? false);
    </script>
    <section class="filters card">
        <div class="filter-group">
            <label for="period">Periodo</label>
            <select id="period">
                <option value="last_30_days">Ultimos 30 dias</option>
                <option value="current_month">Mes actual</option>
                <option value="previous_month">Mes anterior</option>
                <option value="custom">Personalizado</option>
            </select>
        </div>

        <div class="filter-group">
            <label for="leadType">Tipo de Interés</label>
            <select id="leadType">
                <option value="all">Todos</option>
                <option value="Tasacion">Tasación</option>
                <option value="Venta">Venta</option>
            </select>
        </div>

        <div class="filter-group">
            <label for="leadDelegation">Delegación de procedencia</label>
            <select id="leadDelegation">
                <option value="">Todas</option>
            </select>
        </div>

        <div class="filter-group">
            <label for="commercialDelegation">Delegacion comercial</label>
            <select id="commercialDelegation">
                <option value="">Todas</option>
            </select>
        </div>

        <div class="filter-group">
            <label for="zone">Zona</label>
            <select id="zone">
                <option value="">Todas</option>
            </select>
        </div>

        <div class="filter-group">
            <label for="portal">Fuente</label>
            <select id="portal">
                <option value="">Todos</option>
            </select>
        </div>

        <div class="filter-group">
            <label for="commercial">Comercial</label>
            <select id="commercial">
                <option value="">Todos</option>
            </select>
        </div>

        <div class="filter-actions filter-actions-with-audit">
            <button type="button" class="filter-reset" id="resetFilters">Limpiar filtros</button>
            @if($reportUserCanAudit ?? false)
                <a class="filter-reset filter-audit-button" id="reconciliationAudit" href="/informes/leads/export/reconciliation-audit.csv">Auditar activos/eliminados</a>
            @endif
        </div>
    </section>

    <section class="filters card custom-periods is-hidden" id="customPeriods">
        <div class="filter-group">
            <label for="currentStart">Inicio actual</label>
            <input type="date" id="currentStart">
        </div>
        <div class="filter-group">
            <label for="currentEnd">Fin actual</label>
            <input type="date" id="currentEnd">
        </div>
        <div class="filter-group">
            <label for="comparisonStart">Inicio comparado</label>
            <input type="date" id="comparisonStart">
        </div>
        <div class="filter-group">
            <label for="comparisonEnd">Fin comparado</label>
            <input type="date" id="comparisonEnd">
        </div>
    </section>

    <nav class="tabs-main" aria-label="Pestanas del informe">
        <button class="main-tab active" data-panel="panel-resumen">Resumen Dirección</button>
        <button class="main-tab" data-panel="panel-comerciales">Comerciales/Delegaciones/Zonas</button>
        <button class="main-tab" data-panel="panel-delegaciones">Delegaciones de procedencia</button>
        <button class="main-tab" data-panel="panel-portales">Fuentes</button>
    </nav>

    <main>
        <section id="panel-resumen" class="tab-panel active">
            <div class="notice" id="loadingMessage">Cargando fotografía local...</div>
            <div class="notice is-hidden" id="emptyMessage">No hay datos sincronizados para el periodo seleccionado.</div>

            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Resumen ejecutivo</h2>
                        <div class="small">Conclusiones generadas con KPIs reales del periodo</div>
                    </div>
                </div>
                <div class="insights" id="insights"></div>
            </section>

            <section class="period-strip">
                <div class="card period-card">
                    <span>Periodo actual</span>
                    <strong id="currentPeriodLabel">-</strong>
                </div>
                <div class="card period-card">
                    <span>Periodo comparado</span>
                    <strong id="comparisonPeriodLabel">-</strong>
                </div>
            </section>

            <section class="kpis dashboard-kpis" id="summaryKpis"></section>

            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Comparativa basica</h2>
                        <div class="small">Periodo actual frente al periodo comparado</div>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Metrica</th>
                            <th class="num">Periodo actual</th>
                            <th class="num">Periodo comparado</th>
                            <th class="num">Diferencia</th>
                        </tr>
                        </thead>
                        <tbody id="comparisonRows"></tbody>
                    </table>
                </div>
            </section>

        </section>

        <section id="panel-comerciales" class="tab-panel">
            <nav class="tabs-main" aria-label="Cuadros de comerciales, delegaciones y zonas">
                <button type="button" class="main-tab active" data-lead-commercial-tab-trigger="commercials">Comerciales</button>
                <button type="button" class="main-tab" data-lead-commercial-tab-trigger="delegations">Delegaciones</button>
                <button type="button" class="main-tab" data-lead-commercial-tab-trigger="zones">Zonas</button>
            </nav>

            <div id="commercialPanels">
            <section class="card panel is-hidden" data-commercial-section="zones" data-lead-commercial-tab-panel="zones">
                <div class="panel-title">
                    <div>
                        <h2>Zonas</h2>
                        <div class="small">Agrupado por zona comercial del usuario atribuido</div>
                    </div>
                    <div class="columns-menu">
                        <button type="button" class="filter-reset" id="leadCommercialZoneColumnsButton">Añadir/Quitar columna</button>
                        <div class="columns-popover card is-hidden" id="leadCommercialZoneColumnsPopover"></div>
                    </div>
                </div>
                <div class="table-wrap">
                    <table id="leadCommercialZonesTable">
                        <thead>
                        <tr>
                            <th data-column="zone">Zona</th>
                            <th class="num" data-column="leads_totales">Intereses totales</th>
                            <th class="num" data-column="convertidos">Convertidos</th>
                            <th class="num is-hidden" data-column="conversion_pct">% convertidos</th>
                            <th class="num" data-column="descartados">Descartados</th>
                            <th class="num is-hidden" data-column="descarte_pct">% descartados</th>
                            <th class="num" data-column="potenciales">Potenciales</th>
                            <th class="num" data-column="potenciales_sin_trabajar">Potenciales sin trabajar</th>
                            <th class="num" data-column="gestionados">Gestionados</th>
                            <th class="num is-hidden" data-column="gestionados_pct">% gestionados</th>
                        </tr>
                        </thead>
                        <tbody id="commercialZoneRows"></tbody>
                    </table>
                </div>
            </section>

            <section class="card panel is-hidden" data-commercial-section="delegations" data-lead-commercial-tab-panel="delegations">
                <div class="panel-title">
                    <div>
                        <h2>Delegaciones</h2>
                        <div class="small">Agrupado por delegacion comercial del usuario atribuido</div>
                    </div>
                    <div class="columns-menu">
                        <button type="button" class="filter-reset" id="leadCommercialDelegationColumnsButton">Añadir/Quitar columna</button>
                        <div class="columns-popover card is-hidden" id="leadCommercialDelegationColumnsPopover"></div>
                    </div>
                </div>
                <div class="table-wrap">
                    <table id="leadCommercialDelegationsTable">
                        <thead>
                        <tr>
                            <th data-column="commercial_delegation">Delegacion comercial</th>
                            <th data-column="zone">Zona</th>
                            <th class="num" data-column="leads_totales">Intereses totales</th>
                            <th class="num" data-column="convertidos">Convertidos</th>
                            <th class="num is-hidden" data-column="conversion_pct">% convertidos</th>
                            <th class="num" data-column="descartados">Descartados</th>
                            <th class="num is-hidden" data-column="descarte_pct">% descartados</th>
                            <th class="num" data-column="potenciales">Potenciales</th>
                            <th class="num" data-column="potenciales_sin_trabajar">Potenciales sin trabajar</th>
                            <th class="num" data-column="gestionados">Gestionados</th>
                            <th class="num is-hidden" data-column="gestionados_pct">% gestionados</th>
                        </tr>
                        </thead>
                        <tbody id="commercialDelegationRows"></tbody>
                    </table>
                </div>
            </section>

            <section class="card panel" data-commercial-section="commercials" data-lead-commercial-tab-panel="commercials">
                <div class="panel-title">
                    <div>
                        <h2>Comerciales</h2>
                        <div class="small">Solo usuarios activos con perfiles comerciales permitidos</div>
                    </div>
                    <div class="columns-menu">
                        <button type="button" class="filter-reset" id="leadCommercialColumnsButton">Añadir/Quitar columna</button>
                        <div class="columns-popover card is-hidden" id="leadCommercialColumnsPopover"></div>
                    </div>
                </div>
                <div class="filters card compact-filters">
                    <div class="filter-group">
                        <label for="leadCommercialSearch">Buscar comercial</label>
                        <input id="leadCommercialSearch" type="search" placeholder="Filtrar por nombre o ID Salesforce">
                    </div>
                </div>
                <div class="table-wrap">
                    <table id="leadCommercialTable">
                        <thead>
                        <tr>
                            <th data-column="comercial">Comercial</th>
                            <th data-column="commercial_delegation">Delegacion comercial</th>
                            <th data-column="zone">Zona</th>
                            <th class="num" data-column="leads_totales">Intereses totales</th>
                            <th class="num" data-column="convertidos">Convertidos</th>
                            <th class="num is-hidden" data-column="conversion_pct">% convertidos</th>
                            <th class="num" data-column="descartados">Descartados</th>
                            <th class="num is-hidden" data-column="descarte_pct">% descartados</th>
                            <th class="num" data-column="potenciales">Potenciales</th>
                            <th class="num" data-column="potenciales_sin_trabajar">Potenciales sin trabajar</th>
                            <th class="num" data-column="gestionados">Gestionados</th>
                            <th class="num is-hidden" data-column="gestionados_pct">% gestionados</th>
                        </tr>
                        </thead>
                        <tbody id="commercialRows"></tbody>
                    </table>
                </div>
            </section>
            </div>
        </section>

        <section id="panel-delegaciones" class="tab-panel">
            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Delegaciones de procedencia</h2>
                        <div class="small">Total de Intereses frente a potenciales con owner técnico</div>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Delegación de procedencia</th>
                            <th class="num">Intereses totales</th>
                            <th class="num">Potencial con owner generico</th>
                            <th class="num">% pendiente</th>
                        </tr>
                        </thead>
                        <tbody id="delegationRows"></tbody>
                    </table>
                </div>
            </section>
        </section>

        <section id="panel-portales" class="tab-panel">
            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Fuentes</h2>
                        <div class="small">Fuente del Interest en Salesforce</div>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Fuente</th>
                            <th class="num">Intereses totales</th>
                            <th class="num">Convertidos</th>
                            <th class="num">Descartados</th>
                            <th class="num">Potenciales</th>
                            <th class="num">Potenciales sin trabajar</th>
                            <th class="num">Gestionados</th>
                            <th class="num">Llamadas</th>
                            <th class="num">Formularios</th>
                        </tr>
                        </thead>
                        <tbody id="portalRows"></tbody>
                    </table>
                </div>
            </section>
        </section>
    </main>
</div>
</x-reports.app-shell>
