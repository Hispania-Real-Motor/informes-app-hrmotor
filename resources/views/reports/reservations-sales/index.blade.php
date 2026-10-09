@php
    $performanceNow = now('Europe/Madrid');
    $performanceCurrentMonth = $performanceNow->format('Y-m');
    $performanceDefaultMonth = $performanceNow->subMonthNoOverflow()->format('Y-m');
@endphp

<x-reports.app-shell title="Reservas / Ventas" current-report="reservations-sales" :updated-badge-text="'Cargando fotograf'.mb_chr(237).'a local...'">
    <x-slot:head>
        @vite([
            'resources/css/reports/reservations-sales-dashboard.css',
            'resources/js/reports/reservations-sales-dashboard.js'
        ])
    </x-slot:head>
<div class="wrap reservations-sales-report">
    <script>
        window.reportUserCanExport = @json($reportUserCanExport ?? false);
        window.reportUserCanViewCommercialPerformance = @json($reportUserCanViewCommercialPerformance ?? false);
        window.reportUserCanManageCommercialPerformanceTarget = @json($reportUserCanManageCommercialPerformanceTarget ?? false);
        window.commercialPerformanceCurrentMonth = @json($performanceCurrentMonth);
        window.reportCsrfToken = @json(csrf_token());
    </script>
    <x-reports.ui.page-header title="Reservas / Ventas" />

    <nav class="report-ui-tabs" aria-label="Pestanas del informe">
        <button type="button" class="report-ui-tab active is-active" data-report-tab data-report-panel-target="panel-resumen" aria-controls="panel-resumen">Resumen direccion</button>
        <button type="button" class="report-ui-tab" data-report-tab data-report-panel-target="panel-comerciales" aria-controls="panel-comerciales">Comerciales / delegaciones / zonas</button>
        <button type="button" class="report-ui-tab" data-report-tab data-report-panel-target="panel-portales" aria-controls="panel-portales">Portales / procedencia</button>
        @if ($reportUserCanViewCommercialPerformance ?? false)
            <button type="button" class="report-ui-tab" data-report-tab data-report-panel-target="panel-rendimiento-comercial" aria-controls="panel-rendimiento-comercial">Rendimiento comercial</button>
        @endif
    </nav>

    <section class="report-filters report-ui-filter-bar" id="reportFilters" data-filter-mode="standard" aria-label="Filtros del informe">
        <div class="report-ui-filter-bar__fields">
        <div class="report-ui-field" data-filter-scope="standard">
            <label class="report-ui-label" for="period">Periodo</label>
            <select class="report-ui-select" id="period">
                <option value="last_30_days">Ultimos 30 dias</option>
                <option value="current_month">Mes actual</option>
                <option value="previous_month">Mes anterior</option>
                <option value="custom">Personalizado</option>
            </select>
        </div>

        <div class="report-ui-field" data-filter-scope="legacy-date-criterion">
            <label class="report-ui-label" for="dateCriterion">Criterio de fecha</label>
            <select class="report-ui-select" id="dateCriterion">
                <option value="created_date">Fecha de creacion</option>
                <option value="reservation_date">Fecha de reserva</option>
                <option value="cv_signed_date">Fecha de firma contrato</option>
            </select>
        </div>

        <div class="report-ui-field" data-filter-scope="standard">
            <label class="report-ui-label" for="opportunityType">Tipo de oportunidad</label>
            <select class="report-ui-select" id="opportunityType">
                <option value="all">Todos</option>
                <option value="Tasacion">Tasación</option>
                <option value="Venta">Venta</option>
            </select>
        </div>

        @if ($reportUserCanViewCommercialPerformance ?? false)
        <div class="report-ui-field performance-month-control is-hidden" data-filter-scope="performance">
            <label class="report-ui-label" for="performanceMonth">Mes natural</label>
            <input class="report-ui-input" id="performanceMonth" type="month" value="{{ $performanceDefaultMonth }}" data-default-month="{{ $performanceDefaultMonth }}">
        </div>
        @endif

        <div class="report-ui-field shared-delegation-control">
            <label class="report-ui-label" for="commercialDelegation" id="commercialDelegationLabel">Delegacion comercial</label>
            <select class="report-ui-select" id="commercialDelegation">
                <option value="">Todas</option>
            </select>
        </div>

        <div class="report-ui-field shared-zone-control">
            <label class="report-ui-label" for="zone">Zona</label>
            <select class="report-ui-select" id="zone">
                <option value="">Todas</option>
            </select>
        </div>

        <div class="report-ui-field shared-commercial-control">
            <label class="report-ui-label" for="commercial">Comercial</label>
            <select class="report-ui-select" id="commercial">
                <option value="">Todos</option>
            </select>
        </div>
        @if ($reportUserCanViewCommercialPerformance ?? false)
        <div class="report-ui-field performance-target-field is-hidden" data-filter-scope="performance">
            <label class="report-ui-label" for="performanceTarget">Objetivo mensual por comercial</label>
            <div class="performance-target-control">
                <input class="report-ui-input" id="performanceTarget" type="number" min="1" step="1" inputmode="numeric" @if ($reportUserCanManageCommercialPerformanceTarget ?? false) disabled @else readonly @endif>
                @if ($reportUserCanManageCommercialPerformanceTarget ?? false)
                    <button type="button" class="report-ui-button" id="savePerformanceTarget" disabled>Guardar</button>
                @endif
            </div>
            <small class="performance-target-help">Aplica a todos los comerciales evaluables del mes seleccionado, independientemente de zona, delegación o comercial.</small>
        </div>
        @endif
        </div>

        <div class="report-ui-filter-bar__actions shared-reset-control">
            <button type="button" class="report-ui-button report-ui-button--secondary" id="resetFilters">Limpiar filtros</button>
        </div>
    </section>

    <section class="reservations-custom-periods report-ui-filter-bar is-hidden" id="customPeriods" aria-label="Periodos personalizados">
        <div class="report-ui-filter-bar__fields">
        <div class="report-ui-field">
            <label class="report-ui-label" for="currentStart">Inicio actual</label>
            <input class="report-ui-input" type="date" id="currentStart">
        </div>
        <div class="report-ui-field">
            <label class="report-ui-label" for="currentEnd">Fin actual</label>
            <input class="report-ui-input" type="date" id="currentEnd">
        </div>
        <div class="report-ui-field">
            <label class="report-ui-label" for="comparisonStart">Inicio comparado</label>
            <input class="report-ui-input" type="date" id="comparisonStart">
        </div>
        <div class="report-ui-field">
            <label class="report-ui-label" for="comparisonEnd">Fin comparado</label>
            <input class="report-ui-input" type="date" id="comparisonEnd">
        </div>
        </div>
    </section>

    <main>
        <section id="panel-resumen" class="active" data-report-panel>
            <div class="report-ui-card reservations-message" id="loadingMessage" role="status">Cargando fotografía local...</div>
            <div class="report-ui-card report-ui-empty-state reservations-message is-hidden" id="emptyMessage">No hay oportunidades sincronizadas para el periodo seleccionado.</div>

            <section class="period-strip">
                <div class="report-ui-card report-ui-card--muted period-card">
                    <span>Periodo actual</span>
                    <strong id="currentPeriodLabel">-</strong>
                    <small id="currentPeriodTechnical">Inicio incluido · fin excluido</small>
                </div>
                <div class="report-ui-card report-ui-card--muted period-card">
                    <span>Periodo comparado</span>
                    <strong id="comparisonPeriodLabel">-</strong>
                    <small id="comparisonPeriodTechnical">Inicio incluido · fin excluido</small>
                </div>
            </section>

            <section class="report-ui-data-panel reservations-data-quality-panel is-hidden" id="reservationsDataQualityPanel">
                <div class="report-ui-data-panel__header">
                    <x-reports.ui.section-header title="Alertas de calidad del dato" description="Eventos repetidos por vehículo y fecha. Cada grupo cuenta una sola vez en el KPI.">
                        <x-slot:actions>
                            <span class="reservations-quality-count report-ui-badge" id="reservationsDataQualityCount">0</span>
                        </x-slot:actions>
                    </x-reports.ui.section-header>
                </div>
                <div class="reservations-data-quality-incidents report-ui-data-panel__body" id="reservationsDataQualityIncidents"></div>
            </section>

            <section class="report-ui-data-panel reservations-production-panel">
                <div class="report-ui-data-panel__header">
                    <x-reports.ui.section-header title="Producción del período" description="Reservas por fecha de reserva y Ventas por fecha de firma CV. Cada hito usa [inicio incluido, fin excluido)." />
                </div>
                <div class="report-ui-kpi-strip" id="productionKpis" aria-label="Indicadores de producción del período"></div>
                <div class="report-ui-data-panel__scroll" tabindex="0" aria-label="Comparativa de producción del período actual y comparado">
                    <table class="report-ui-table report-ui-table--sticky-header">
                        <thead>
                        <tr>
                            <th scope="col">Métrica de producción</th>
                            <th scope="col" class="report-ui-table__numeric">Período actual</th>
                            <th scope="col" class="report-ui-table__numeric">Período comparado</th>
                            <th scope="col" class="report-ui-table__numeric">Diferencia</th>
                        </tr>
                        </thead>
                        <tbody id="productionComparisonRows"></tbody>
                    </table>
                </div>
            </section>

            <section class="report-ui-card report-ui-card--muted reservations-current-state" aria-label="Estado actual de reservas">
                <div>
                    <span>Estado actual</span>
                    <strong>Reservas vivas actuales (todas las fechas)</strong>
                    <small>No forma parte de Producción ni de la Cohorte.</small>
                </div>
                <div class="reservations-current-state__value" id="currentLiveReservations">-</div>
                <div id="currentLiveReservationsAudit"></div>
            </section>

            <section class="report-ui-data-panel reservations-cohort-panel">
                <div class="report-ui-data-panel__header">
                    <x-reports.ui.section-header title="Cohorte de oportunidades creadas en el período" description="La cohorte se define por CreatedDate. Sus reservas, caídas y firmas describen el estado/resultados actuales de esas oportunidades y pueden ocurrir después del período de creación." />
                </div>
                <div class="reservations-cohort-contract">
                    <span>Fecha que define la cohorte</span>
                    <strong id="universeDateLabel">Fecha de creación</strong>
                </div>
                <div class="report-ui-kpi-strip" id="cohortKpis" aria-label="Resultados actuales de la cohorte de creación"></div>
                <div class="report-ui-data-panel__scroll" tabindex="0" aria-label="Comparativa de la cohorte actual y comparada">
                    <table class="report-ui-table report-ui-table--sticky-header">
                        <thead>
                        <tr>
                            <th scope="col">Resultado actual de la cohorte</th>
                            <th scope="col" class="report-ui-table__numeric">Período actual</th>
                            <th scope="col" class="report-ui-table__numeric">Período comparado</th>
                            <th scope="col" class="report-ui-table__numeric">Diferencia</th>
                        </tr>
                        </thead>
                        <tbody id="cohortComparisonRows"></tbody>
                    </table>
                </div>
            </section>

        </section>

        <section id="panel-comerciales" data-report-panel>
            <section class="report-ui-data-panel">
                <div class="report-ui-data-panel__header">
                    <x-reports.ui.section-header title="Zonas" description="Agrupado por zona comercial del owner" />
                </div>
                <div class="report-ui-data-panel__scroll" tabindex="0" aria-label="Resultados por zona comercial">
                    <table class="report-ui-table report-ui-table--sticky-header">
                        <thead>
                        <tr>
                            <th scope="col">Zona</th>
                            <th scope="col" class="report-ui-table__numeric">Oportunidades totales</th>
                            <th scope="col" class="report-ui-table__numeric">Reservas vivas</th>
                            <th scope="col" class="report-ui-table__numeric">Oportunidades caidas</th>
                            <th scope="col" class="report-ui-table__numeric">Contratos CV firmados</th>
                        </tr>
                        </thead>
                        <tbody id="commercialZoneRows"></tbody>
                    </table>
                </div>
            </section>

            <section class="report-ui-data-panel">
                <div class="report-ui-data-panel__header">
                    <x-reports.ui.section-header title="Delegaciones" description="Agrupado por delegacion comercial del owner" />
                </div>
                <div class="report-ui-data-panel__scroll" tabindex="0" aria-label="Resultados por delegación comercial">
                    <table class="report-ui-table report-ui-table--sticky-header">
                        <thead>
                        <tr>
                            <th scope="col">Delegacion comercial</th>
                            <th scope="col">Zona</th>
                            <th scope="col" class="report-ui-table__numeric">Oportunidades totales</th>
                            <th scope="col" class="report-ui-table__numeric">Reservas vivas</th>
                            <th scope="col" class="report-ui-table__numeric">Oportunidades caidas</th>
                            <th scope="col" class="report-ui-table__numeric">Contratos CV firmados</th>
                        </tr>
                        </thead>
                        <tbody id="commercialDelegationRows"></tbody>
                    </table>
                </div>
            </section>

            <section class="report-ui-data-panel">
                <div class="report-ui-data-panel__header">
                    <x-reports.ui.section-header title="Comerciales" description="Agrupado por responsable de la oportunidad">
                        <x-slot:actions>
                    <div class="reservations-columns-menu" data-columns-menu>
                        <button type="button" class="report-ui-button report-ui-button--secondary" id="reservationsCommercialColumnsButton">Columnas</button>
                        <div class="reservations-columns-popover report-ui-card is-hidden" id="reservationsCommercialColumnsPopover"></div>
                    </div>
                        </x-slot:actions>
                    </x-reports.ui.section-header>
                </div>
                <div class="reservations-commercial-filter-bar report-ui-filter-bar">
                    <div class="report-ui-filter-bar__fields">
                    <div class="report-ui-field">
                        <label class="report-ui-label" for="reservationsCommercialSearch">Buscar comercial</label>
                        <input class="report-ui-input" id="reservationsCommercialSearch" type="search" placeholder="Filtrar por nombre o ID Salesforce">
                    </div>
                    </div>
                </div>
                <div class="report-ui-data-panel__scroll" tabindex="0" aria-label="Resultados por comercial">
                    <table class="report-ui-table report-ui-table--sticky-header" id="reservationsCommercialTable">
                        <thead>
                        <tr>
                            <th scope="col" data-column="comercial">Comercial</th>
                            <th scope="col" data-column="commercial_delegation">Delegacion comercial</th>
                            <th scope="col" data-column="zone">Zona</th>
                            <th scope="col" class="report-ui-table__numeric" data-column="oportunidades_totales">Oportunidades totales</th>
                            <th scope="col" class="report-ui-table__numeric" data-column="reservas_vivas">Reservas vivas</th>
                            <th scope="col" class="report-ui-table__numeric is-hidden" data-column="reservas_vivas_pct">% reservas vivas</th>
                            <th scope="col" class="report-ui-table__numeric is-hidden" data-column="reservas_vivas_participation_pct">% participacion reservas</th>
                            <th scope="col" class="report-ui-table__numeric" data-column="oportunidades_caidas">Oportunidades caidas</th>
                            <th scope="col" class="report-ui-table__numeric is-hidden" data-column="oportunidades_caidas_pct">% oportunidades caidas</th>
                            <th scope="col" class="report-ui-table__numeric is-hidden" data-column="oportunidades_caidas_participation_pct">% participacion caidas</th>
                            <th scope="col" class="report-ui-table__numeric" data-column="cv_firmados">Contratos CV firmados</th>
                            <th scope="col" class="report-ui-table__numeric is-hidden" data-column="cv_firmados_pct">% contratos CV firmados</th>
                            <th scope="col" class="report-ui-table__numeric is-hidden" data-column="cv_firmados_participation_pct">% participacion CV</th>
                        </tr>
                        </thead>
                        <tbody id="commercialRows"></tbody>
                    </table>
                </div>
            </section>
        </section>

        <section id="panel-portales" data-report-panel>
            <section class="report-ui-data-panel">
                <div class="report-ui-data-panel__header">
                    <x-reports.ui.section-header title="Portales / Procedencia" description="Procedencia reconstruida desde oportunidad o lead relacionado" />
                </div>
                <div class="report-ui-data-panel__scroll" tabindex="0" aria-label="Resultados por portal o procedencia">
                    <table class="report-ui-table report-ui-table--sticky-header">
                        <thead>
                        <tr>
                            <th scope="col">Portal / Procedencia</th>
                            <th scope="col" class="report-ui-table__numeric">Oportunidades totales</th>
                            <th scope="col" class="report-ui-table__numeric">Reservas vivas</th>
                            <th scope="col" class="report-ui-table__numeric">Oportunidades caidas</th>
                            <th scope="col" class="report-ui-table__numeric">Contratos CV firmados</th>
                        </tr>
                        </thead>
                        <tbody id="portalRows"></tbody>
                    </table>
                </div>
            </section>
        </section>

        @if ($reportUserCanViewCommercialPerformance ?? false)
        <section id="panel-rendimiento-comercial" data-report-panel>
            <div class="report-ui-card reservations-message is-hidden" id="performanceLoading" role="status">Cargando rendimiento comercial local...</div>
            <div class="performance-note performance-note--quality performance-note--compact is-hidden" id="performanceCurrentMonthNotice"></div>
            <div class="performance-note performance-note--quality performance-note--compact is-hidden" id="performanceLimitationNotice">
                Existen limitaciones de calidad o cobertura. Consulta “Información y calidad de datos”.
            </div>
            <div class="performance-note performance-note--error is-hidden" id="performanceLoadError" role="alert">
                <span data-performance-load-error-message></span>
                <button type="button" class="report-ui-button report-ui-button--secondary" id="retryCommercialPerformance">Reintentar</button>
            </div>
            <details class="performance-data-context" id="performanceDataContext">
                <summary class="performance-data-context__summary">
                    <span>Información y calidad de datos</span>
                    <span class="performance-data-context__status is-hidden" id="performanceDataContextSummary"></span>
                </summary>
                <div class="performance-data-context__body">
                    <section class="performance-data-context__section" aria-labelledby="performanceMethodologyTitle">
                        <h3 id="performanceMethodologyTitle">Metodología</h3>
                        <p id="performanceSemantics">Actividad mensual, no cohorte. Cada hito se asigna al mes en que ocurre; por ello, algunos ratios pueden superar el 100 %.</p>
                    </section>
                    <section class="performance-data-context__section" aria-labelledby="performanceUniverseTitle">
                        <h3 id="performanceUniverseTitle">Universo</h3>
                        <p class="is-hidden" id="performanceUniverse"></p>
                    </section>
                    <section class="performance-data-context__section" aria-labelledby="performanceFreshnessTitle">
                        <h3 id="performanceFreshnessTitle">Actualización</h3>
                        <p class="is-hidden" id="performanceFreshness"></p>
                    </section>
                    <section class="performance-data-context__section" aria-labelledby="performanceCancellationCoverageTitle">
                        <h3 id="performanceCancellationCoverageTitle">Cobertura de cancelaciones</h3>
                        <p class="is-hidden" id="performanceCancellationCoverage"></p>
                    </section>
                    <section class="performance-data-context__section" aria-labelledby="performanceDataQualityTitle">
                        <h3 id="performanceDataQualityTitle">Calidad de datos</h3>
                        <p class="is-hidden" id="performanceDataIncident"></p>
                        <p class="is-hidden" id="performanceQualityWarning"></p>
                    </section>
                </div>
            </details>
            <section class="report-ui-kpi-strip" id="performanceKpis" aria-label="Indicadores de rendimiento comercial"></section>

            <section class="report-ui-data-panel">
                <div class="report-ui-data-panel__header">
                    <x-reports.ui.section-header title="Rendimiento por comercial" description="Ranking y referencias de delegación exclusivos de comerciales con actividad real y asignación mensual certificable. Las filas no evaluables se conservan sin objetivo, ranking ni comparación.">
                        <x-slot:actions>
                    <div class="reservations-columns-menu" data-columns-menu>
                            <button type="button" class="report-ui-button report-ui-button--secondary" id="performanceColumnsButton" aria-expanded="false" aria-controls="performanceColumnsPopover">Personalizar columnas</button>
                        <div class="reservations-columns-popover report-ui-card is-hidden" id="performanceColumnsPopover"></div>
                    </div>
                        </x-slot:actions>
                    </x-reports.ui.section-header>
                </div>
                <div class="performance-commercial-filter-bar report-ui-filter-bar">
                    <div class="report-ui-filter-bar__fields">
                        <div class="report-ui-field">
                            <label class="report-ui-label" for="performanceSearch">Buscar comercial</label>
                            <input class="report-ui-input" id="performanceSearch" type="search" placeholder="Filtrar por nombre" autocomplete="off">
                        </div>
                    </div>
                </div>
                <p class="performance-ratios-help">Ratios de actividad mensual: cada hito se imputa a su propia fecha. No son ratios de cohorte y pueden superar el 100 %.</p>
                <div class="table-scroll-top is-hidden" data-scroll-target="performanceTableWrap" aria-hidden="true"><div></div></div>
                <div class="report-ui-data-panel__scroll performance-table-wrap" id="performanceTableWrap" tabindex="0" aria-label="Rendimiento por comercial">
                    <table class="performance-table report-ui-table report-ui-table--sticky-header" id="performanceTable">
                        <thead><tr>
                            <th scope="col" data-column="ranking">Ranking</th><th scope="col" data-column="traffic_light">Estado</th><th scope="col" data-column="commercial">Comercial</th><th scope="col" data-column="delegation">Delegación</th><th scope="col" data-column="zone">Zona</th>
                            <th scope="col" class="report-ui-table__numeric" data-column="leads">Intereses</th><th scope="col" class="report-ui-table__numeric" data-column="opportunities">Oportunidades</th><th scope="col" class="report-ui-table__numeric" data-column="reservations_total">Reservas totales</th><th scope="col" class="report-ui-table__numeric" data-column="team_average_reservations">Media delegación</th><th scope="col" class="report-ui-table__numeric" data-column="team_reservations_deviation">Desviación vs delegación</th><th scope="col" class="report-ui-table__numeric" data-column="reservations_active">Reservas vivas</th><th scope="col" class="report-ui-table__numeric" data-column="reservations_dropped">Reservas caídas</th>
                            <th scope="col" class="report-ui-table__numeric" data-column="objective">Objetivo</th><th scope="col" class="report-ui-table__numeric" data-column="fulfillment_pct">Cumplimiento</th>
                            <th scope="col" class="report-ui-table__numeric" data-column="lead_to_reservation_pct">Interés → Reserva</th><th scope="col" class="report-ui-table__numeric" data-column="lead_to_reservation_vs_team"><span>Ratio Interés → Reserva</span><small>Comparativa con su delegación</small></th><th scope="col" class="report-ui-table__numeric" data-column="opportunity_to_reservation_pct">Oportunidad → Reserva</th><th scope="col" class="report-ui-table__numeric" data-column="opportunity_to_reservation_vs_team"><span>Ratio Oportunidad → Reserva</span><small>Comparativa con su delegación</small></th>
                            <th scope="col" class="report-ui-table__numeric" data-column="sales">Ventas válidas</th><th scope="col" class="report-ui-table__numeric" data-column="sales_dropped">Ventas caídas</th><th scope="col" class="report-ui-table__numeric" data-column="reservation_to_sale_pct">Reserva → Venta</th><th scope="col" class="report-ui-table__numeric" data-column="reservation_drop_pct">% Reserva caída</th><th scope="col" class="report-ui-table__numeric" data-column="sale_drop_pct">% Venta caída</th><th scope="col" class="report-ui-table__numeric" data-column="reservation_to_sale_vs_team"><span>Ratio Reserva → Venta</span><small>Comparativa con su delegación</small></th>
                            <th scope="col" class="report-ui-table__numeric" data-column="cancellations">Cancelaciones</th><th scope="col" class="report-ui-table__numeric" data-column="cancellation_pct">% cancelación</th>
                            <th scope="col" class="report-ui-table__numeric" data-column="margin_total" title="Rentabilidad acumulada de las ventas con margen informado.">Margen total</th>
                            <th scope="col" class="report-ui-table__numeric" data-column="average_margin_per_sale" title="Media calculada únicamente sobre ventas con margen informado.">Margen medio</th>
                            <th scope="col" class="report-ui-table__numeric" data-column="margin_coverage_pct">Cobertura margen</th>
                        </tr></thead>
                        <tbody id="performanceRows"></tbody>
                    </table>
                </div>
            </section>

            <section class="report-ui-data-panel">
                <div class="report-ui-data-panel__header">
                    <x-reports.ui.section-header title="Evolución mensual" description="Mes seleccionado y tres meses anteriores" />
                </div>
                <div class="table-scroll-top is-hidden" data-scroll-target="performanceEvolutionWrap" aria-hidden="true"><div></div></div>
                <div class="report-ui-data-panel__scroll" id="performanceEvolutionWrap" tabindex="0" aria-label="Evolución mensual del rendimiento comercial">
                    <table class="performance-evolution-table report-ui-table report-ui-table--sticky-header">
                        <thead><tr>
                            <th scope="col">Mes</th><th scope="col" class="report-ui-table__numeric">Intereses</th><th scope="col" class="report-ui-table__numeric">Oportunidades</th><th scope="col" class="report-ui-table__numeric">Reservas totales</th>
                            <th scope="col" class="report-ui-table__numeric">Reservas vivas</th><th scope="col" class="report-ui-table__numeric">Reservas caídas</th><th scope="col" class="report-ui-table__numeric">Ventas válidas</th><th scope="col" class="report-ui-table__numeric">Ventas caídas</th><th scope="col" class="report-ui-table__numeric">Cancelaciones</th><th scope="col" class="report-ui-table__numeric">Cumplimiento</th>
                            <th scope="col" class="report-ui-table__numeric">Interés → Reserva</th><th scope="col" class="report-ui-table__numeric">Oport. → Reserva</th><th scope="col" class="report-ui-table__numeric">Reserva → Venta</th>
                            <th scope="col" class="report-ui-table__numeric">% Reserva caída</th><th scope="col" class="report-ui-table__numeric">% Venta caída</th><th scope="col" class="report-ui-table__numeric">% cancelación</th><th scope="col" class="report-ui-table__numeric">Margen total</th><th scope="col" class="report-ui-table__numeric">Margen medio</th>
                        </tr></thead>
                        <tbody id="performanceEvolutionRows"></tbody>
                    </table>
                </div>
            </section>

            @if ($reportUserCanAuditCommercialPerformance ?? false)
            <section class="report-ui-data-panel" aria-labelledby="performanceAuditTitle">
                <div class="report-ui-data-panel__header">
                    <x-reports.ui.section-header id="performanceAuditTitle" title="Auditoría de Rendimiento comercial" description="Trazabilidad local de IDs, hitos, atribución, cobertura e incidencias.">
                        <x-slot:actions>
                            <button type="button" class="report-ui-button report-ui-button--secondary" id="loadPerformanceAudit">Cargar auditoría</button>
                        </x-slot:actions>
                    </x-reports.ui.section-header>
                </div>
                <div class="performance-note performance-note--info is-hidden" id="performanceAuditStatus" role="status"></div>
                <div id="performanceAuditResult" class="report-ui-data-panel__body is-hidden"></div>
            </section>
            @endif
        </section>
        @endif
    </main>
</div>
</x-reports.app-shell>
