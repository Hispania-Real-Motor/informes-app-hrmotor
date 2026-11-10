<x-reports.app-shell title="Llamadas" current-report="calls" :updated-badge-text="'Cargando fotograf'.mb_chr(237).'a local...'">
    <x-slot:head>
        @vite([
            'resources/css/reports/leads-dashboard.css',
            'resources/js/reports/calls-dashboard.js'
        ])
    </x-slot:head>
<div class="wrap">
    <section class="filters card">
        <div class="filter-group">
            <label for="period">Periodo</label>
            <select id="period">
                <option value="last_30_days">Ultimos 30 dias</option>
                <option value="current_month">Mes actual</option>
                <option value="previous_month">Mes anterior</option>
                <option value="october_2026" @selected(($initialPeriod ?? null) === 'october_2026')>Octubre 2026</option>
                <option value="custom">Personalizado</option>
            </select>
        </div>
        <div class="filter-group">
            <label for="team">Equipo</label>
            <select id="team"><option value="">Todos</option></select>
        </div>
        <div class="filter-group">
            <label for="direction">Tipo llamada</label>
            <select id="direction"><option value="">Todas</option></select>
        </div>
        <div class="filter-group">
            <label for="status">Estado</label>
            <select id="status"><option value="">Todas</option></select>
        </div>
        <div class="filter-group">
            <label for="origin">Origen llamada</label>
            <select id="origin"><option value="">Todos</option></select>
        </div>
        <div class="filter-group">
            <label for="delegation">Delegacion</label>
            <select id="delegation"><option value="">Todas</option></select>
        </div>
        <div class="filter-group">
            <label for="zone">Zona</label>
            <select id="zone"><option value="">Todas</option></select>
        </div>
        <div class="filter-group">
            <label for="portal">Portal</label>
            <select id="portal"><option value="">Todos</option></select>
        </div>
        <div class="filter-group">
            <label for="user">Usuario/agente</label>
            <select id="user"><option value="">Todos</option></select>
        </div>
        <div class="filter-actions filter-actions-with-audit">
            <button type="button" class="filter-reset" id="resetFilters">Limpiar filtros</button>
            @if ($reportUserCanAudit ?? false)
                <a class="filter-reset filter-audit-button" id="callsAuditExport" href="/informes/llamadas/export/audit.csv">Auditar llamadas</a>
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
        <button class="main-tab active" data-panel="panel-resumen">Resumen direccion</button>
        <button class="main-tab" data-panel="panel-agentes">Comerciales / agentes</button>
        <button class="main-tab" data-panel="panel-delegaciones">Delegaciones / zonas</button>
        <button class="main-tab" data-panel="panel-portales">Portales / procedencia</button>
    </nav>

    <main>
        <section id="panel-resumen" class="tab-panel active">
            <div class="notice" id="loadingMessage">Cargando fotografía local...</div>
            <div class="notice is-hidden" id="emptyMessage">No hay llamadas sincronizadas para el periodo seleccionado.</div>
            <section class="card panel is-hidden" id="callsReconciliation"></section>

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

            <section class="call-center-grid">
                <article class="card panel call-center-panel">
                    <div class="panel-title">
                        <div>
                            <h2>Atencion global</h2>
                            <div class="small">Atendidas frente a perdidas</div>
                        </div>
                    </div>
                    <div class="donut-grid" id="answeredLostChart"></div>
                </article>

                <article class="card panel call-center-panel">
                    <div class="panel-title">
                        <div>
                            <h2>Origen operativo</h2>
                            <div class="small">Directas a comercial frente a portales</div>
                        </div>
                    </div>
                    <div class="donut-grid" id="originChart"></div>
                </article>

                <article class="card panel call-center-panel">
                    <div class="panel-title">
                        <div>
                            <h2>Atendidas por equipo</h2>
                            <div class="small">Carga atendida por grupo operativo</div>
                        </div>
                    </div>
                    <div class="dashboard-bars" id="teamBars"></div>
                </article>
            </section>

            <section class="origin-summary" id="originBreakdown"></section>

            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Evolucion diaria</h2>
                        <div class="small">Llamadas por dia del periodo actual</div>
                    </div>
                </div>
                <div class="daily-chart" id="dailyEvolution"></div>
            </section>

            <section class="kpis dashboard-kpis" id="summaryKpis"></section>

            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Rankings operativos</h2>
                        <div class="small">Portales, agentes y desbordes destacados</div>
                    </div>
                </div>
                <div class="priority-grid" id="callsRankings"></div>
            </section>

            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Conclusiones automaticas</h2>
                        <div class="small">Reglas basadas en KPIs reales del periodo</div>
                    </div>
                </div>
                <div class="insights" id="insights"></div>
            </section>

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

        <section id="panel-agentes" class="tab-panel">
            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Comerciales</h2>
                        <div class="small">Llamadas asignadas a comerciales</div>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Usuario/agente</th>
                            <th>Delegacion</th>
                            <th class="num">Total llamadas</th>
                            <th class="num">Atendidas</th>
                            <th class="num">No atendidas/perdidas</th>
                            <th class="num">Entrantes</th>
                            <th class="num">Salientes</th>
                            <th class="num">Directas atendidas</th>
                            <th class="num">Portales atendidas</th>
                            <th class="num">Tiempo medio conversacion</th>
                        </tr>
                        </thead>
                        <tbody id="commercialRows"></tbody>
                    </table>
                </div>
            </section>

            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Atencion al Cliente</h2>
                        <div class="small">Agentes de atencion y casos especiales clasificados</div>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Usuario/agente</th>
                            <th class="num">Total llamadas</th>
                            <th class="num">Atendidas</th>
                            <th class="num">No atendidas/perdidas</th>
                            <th class="num">Entrantes</th>
                            <th class="num">Salientes</th>
                            <th class="num">Desbordes</th>
                            <th class="num">Tiempo medio conversacion</th>
                        </tr>
                        </thead>
                        <tbody id="customerServiceRows"></tbody>
                    </table>
                </div>
            </section>

            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Contact Center</h2>
                        <div class="small">Agentes de contact center detectados por mapping</div>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Usuario/agente</th>
                            <th class="num">Total llamadas</th>
                            <th class="num">Atendidas</th>
                            <th class="num">No atendidas/perdidas</th>
                            <th class="num">Entrantes</th>
                            <th class="num">Salientes</th>
                            <th class="num">Desbordes</th>
                            <th class="num">Tiempo medio conversacion</th>
                        </tr>
                        </thead>
                        <tbody id="contactCenterRows"></tbody>
                    </table>
                </div>
            </section>

            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Tasadores</h2>
                        <div class="small">Usuarios operativos no clasificados como comerciales o agentes</div>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Usuario/agente</th>
                            <th class="num">Total llamadas</th>
                            <th class="num">Atendidas</th>
                            <th class="num">No atendidas/perdidas</th>
                            <th class="num">Entrantes</th>
                            <th class="num">Salientes</th>
                            <th class="num">Tiempo medio conversacion</th>
                        </tr>
                        </thead>
                        <tbody id="appraiserRows"></tbody>
                    </table>
                </div>
            </section>
        </section>

        <section id="panel-delegaciones" class="tab-panel">
            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Zonas</h2>
                        <div class="small">Agrupado por zona comercial del responsable operativo</div>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Zona</th>
                            <th class="num">Total llamadas</th>
                            <th class="num">Atendidas</th>
                            <th class="num">No atendidas/perdidas</th>
                            <th class="num">Entrantes</th>
                            <th class="num">Salientes</th>
                            <th class="num">Desbordes</th>
                            <th class="num">Tiempo medio conversacion</th>
                        </tr>
                        </thead>
                        <tbody id="zoneRows"></tbody>
                    </table>
                </div>
            </section>

            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Delegaciones</h2>
                        <div class="small">Agrupado por delegacion comercial del responsable operativo</div>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Delegacion</th>
                            <th class="num">Total llamadas</th>
                            <th class="num">Atendidas</th>
                            <th class="num">No atendidas/perdidas</th>
                            <th class="num">Entrantes</th>
                            <th class="num">Salientes</th>
                            <th class="num">Desbordes</th>
                            <th class="num">Tiempo medio conversacion</th>
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
                        <h2>Portales / Procedencia</h2>
                        <div class="small">Procedencia normalizada desde Portales Salesforce</div>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Portal / Procedencia</th>
                            <th class="num">Total llamadas</th>
                            <th class="num">Atendidas</th>
                            <th class="num">No atendidas/perdidas</th>
                            <th class="num">Entrantes</th>
                            <th class="num">Desbordes</th>
                            <th class="num">Tiempo medio conversacion</th>
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
