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
        window.initialLeadPeriod = @json($initialLeadPeriod ?? null);
        window.isOctoberProvisional = @json($isOctoberProvisional ?? false);
    </script>
    <section class="lead-load-state is-hidden" id="leadLoadState" aria-live="polite">
        <div class="is-hidden" id="leadLoadingState" role="status">
            <span class="lead-load-state__spinner" aria-hidden="true"></span>
            <span class="lead-load-state__eyebrow">Informe de Intereses</span>
            <h2>Cargando información</h2>
            <p>Estamos consultando los datos disponibles.</p>
        </div>
        <div class="is-hidden" id="leadUnavailableState">
            <span class="lead-load-state__eyebrow">Informe de Intereses</span>
            <h2>Información temporalmente no disponible</h2>
            <p>Los datos de este período no están disponibles.</p>
            <p class="lead-load-state__secondary">Prueba otro período o inténtalo de nuevo más tarde.</p>
        </div>
        <div class="is-hidden" id="leadGenericErrorState" role="alert">
            <span class="lead-load-state__eyebrow">Informe de Intereses</span>
            <h2>No se ha podido cargar la información. Inténtalo de nuevo.</h2>
        </div>
        <button class="main-tab lead-load-state__retry is-hidden" id="retryLeadLoad" type="button">Volver a intentar</button>
    </section>

    <div id="leadReportContent" aria-busy="true" aria-hidden="true">
    <section class="filters card">
        <div class="filter-group">
            <label for="period">Periodo</label>
            <select id="period">
                <option value="last_30_days" @selected(($initialLeadPeriod ?? null) === null)>Ultimos 30 dias</option>
                <option value="current_month">Mes actual</option>
                <option value="previous_month">Mes anterior</option>
                @if ($octoberProvisionalEnabled ?? false)
                    <option value="october_2026" @selected(($initialLeadPeriod ?? null) === 'october_2026')>Octubre 2026</option>
                @endif
                @if ($novemberProvisionalEnabled ?? false)
                    <option value="november_2026" @selected(($initialLeadPeriod ?? null) === 'november_2026')>Noviembre 2026</option>
                @endif
                <option value="custom">Personalizado</option>
            </select>
        </div>

        <div class="filter-group">
            <label for="leadType">Tipo de Interés</label>
            <select id="leadType">
                <option value="all">Todos</option>
                <option value="Tasacion">Tasación</option>
                <option value="Venta">Venta</option>
                @if($isOctoberProvisional ?? false)
                    <option value="Venta con cambio">Venta con cambio</option>
                @endif
            </select>
        </div>

        <div class="filter-group">
            <label for="leadDelegation">Delegación de procedencia</label>
            <select id="leadDelegation">
                <option value="">Todas</option>
            </select>
        </div>

        @if($isOctoberProvisional ?? false)
            <div class="filter-group">
                <label for="octoberGroup">Grupo de procedencia</label>
                <select id="octoberGroup" data-lead-filter-control data-october-filter="group"><option value="">Todos</option></select>
            </div>
        @endif

        <div class="filter-group">
            <label for="commercialDelegation">{{ ($isOctoberProvisional ?? false) ? 'Delegación comercial observada' : 'Delegacion comercial' }}</label>
            <select id="commercialDelegation">
                <option value="">Todas</option>
            </select>
        </div>

        <div class="filter-group">
            <label for="zone">{{ ($isOctoberProvisional ?? false) ? 'Zona de procedencia' : 'Zona' }}</label>
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

        @if($isOctoberProvisional ?? false)
            <div class="filter-group">
                <label for="octoberMedium">Medio</label>
                <select id="octoberMedium" data-lead-filter-control data-october-filter="medium"><option value="">Todos</option></select>
            </div>
            <div class="filter-group">
                <label for="octoberChannel">Canal</label>
                <select id="octoberChannel" data-lead-filter-control data-october-filter="channel"><option value="">Todos</option></select>
            </div>
            <div class="filter-group">
                <label for="octoberStatus">Estado</label>
                <select id="octoberStatus" data-lead-filter-control data-october-filter="status"><option value="">Todos</option></select>
            </div>
            <script src="{{ asset('js/reports/october-2026-dashboard-filters.js') }}" defer></script>
        @endif

        <div class="filter-group">
            <label for="commercial">{{ ($isOctoberProvisional ?? false) ? 'Propietario observado' : 'Comercial' }}</label>
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

    @if($isOctoberProvisional ?? false)
        <section class="notice" id="octoberProvisionalNotice" aria-live="polite">
            <strong>{{ ($initialLeadPeriod ?? null) === 'november_2026' ? 'Noviembre de 2026' : 'Octubre 2026 · datos reconstruidos.' }}</strong>
            @if(($initialLeadPeriod ?? null) === 'november_2026' && ($provisionalUpdatedUntil ?? null))
                <span>Actualizado hasta {{ $provisionalUpdatedUntil }}</span>
            @endif
            Los filtros se aplican a los Intereses activos disponibles de ese mes. Algunas métricas comerciales y de actividad todavía no tienen datos suficientes.
        </section>
    @endif

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
                <div class="card period-card @if($isOctoberProvisional ?? false) is-hidden @endif">
                    <span>Periodo comparado</span>
                    <strong id="comparisonPeriodLabel">-</strong>
                </div>
            </section>

            <section class="kpis dashboard-kpis" id="summaryKpis"></section>

            <section class="card panel @if($isOctoberProvisional ?? false) is-hidden @endif">
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
                @if($isOctoberProvisional ?? false)
                    <div class="notice">Asignación observada: el propietario disponible no acredita el responsable histórico al cierre de octubre.</div>
                @endif
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
                            <th data-column="commercial_delegation">{{ ($isOctoberProvisional ?? false) ? 'Delegación observada' : 'Delegacion comercial' }}</th>
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
                        <input id="leadCommercialSearch" type="search" placeholder="{{ ($isOctoberProvisional ?? false) ? 'Filtrar por nombre o clave' : 'Filtrar por nombre o ID Salesforce' }}">
                    </div>
                </div>
                <div class="table-wrap">
                    <table id="leadCommercialTable">
                        <thead>
                        <tr>
                            <th data-column="comercial">{{ ($isOctoberProvisional ?? false) ? 'Propietario observado' : 'Comercial' }}</th>
                            <th data-column="commercial_delegation">{{ ($isOctoberProvisional ?? false) ? 'Delegación observada' : 'Delegacion comercial' }}</th>
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
                        <div class="small">{{ ($isOctoberProvisional ?? false) ? 'Intereses por delegación de procedencia. La asignación comercial no está disponible para octubre.' : 'Total de Intereses frente a potenciales con owner técnico' }}</div>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Delegación de procedencia</th>
                            <th class="num">Intereses totales</th>
                            <th class="num">{{ ($isOctoberProvisional ?? false) ? 'No asignados' : 'Potencial con owner generico' }}</th>
                            <th class="num">{{ ($isOctoberProvisional ?? false) ? '% no asignados' : '% pendiente' }}</th>
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
                        <div class="small">{{ ($isOctoberProvisional ?? false) ? 'Intereses por fuente. La actividad y la gestión requieren más datos.' : 'Fuente del Interest en Salesforce' }}</div>
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
</div>
</x-reports.app-shell>
