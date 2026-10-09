<x-reports.app-shell :title="'Campa'.mb_chr(241).'as'" current-report="campaigns" body-class="campaigns-report" :updated-badge-text="'Cargando fotograf'.mb_chr(237).'a local...'">
    <x-slot:head>
    @vite([
        'resources/css/reports/leads-dashboard.css',
        'resources/js/reports/campaigns-dashboard.js'
    ])
    <script>
        window.reportUserRole = @json($reportUserRole ?? 'viewer');
        window.reportUserCanExport = @json($reportUserCanExport ?? false);
        window.reportUserCanAudit = @json($reportUserCanAudit ?? false);
        window.reportUserCanSeeSourceReconciliation = @json($reportUserCanSeeSourceReconciliation ?? false);
    </script>
    </x-slot:head>
<div class="wrap">
    <section class="filters card campaigns-filter-bar">
        <div class="filter-group">
            <label for="periodPreset">Periodo</label>
            <select id="periodPreset"></select>
        </div>
        <div class="filter-group">
            <label for="startDate">Inicio</label>
            <input type="date" id="startDate" min="2026-01-01" max="2026-12-31">
        </div>
        <div class="filter-group">
            <label for="endDate">Fin</label>
            <input type="date" id="endDate" min="2026-01-01" max="2026-12-31">
        </div>
        <div class="filter-group">
            <label for="platform">Plataforma</label>
            <select id="platform"><option value="">Todas</option></select>
        </div>
        <div class="filter-group">
            <label for="campaignType">Tipo de campaña</label>
            <div class="small">Clasifica la campaña; no filtra el tipo del interés.</div>
            <select id="campaignType">
                <option value="all" selected>Todos</option>
                <option value="venta">Venta</option>
                <option value="tasacion">Tasación</option>
            </select>
        </div>
        <div class="filter-group">
            <label for="leadType">Tipo de interés</label>
            <div class="small">Filtra por el tipo normalizado del interés.</div>
            <select id="leadType"><option value="">Todos</option></select>
        </div>
        <div class="filter-group" id="saleSubcategoryGroup">
            <label for="saleSubcategory">Subcategoría venta</label>
            <select id="saleSubcategory">
                <option value="venta" selected>Todos</option>
                <option value="ventas">Ventas</option>
                <option value="exposicion">Exposición</option>
                <option value="branding">Branding</option>
                <option value="otros">Otros</option>
            </select>
        </div>
        <div class="filter-group">
            <label for="campaignStatus">Estado de campaña</label>
            <select id="campaignStatus">
                <option value="">Todas</option>
                <option value="active" selected>Activas</option>
                <option value="inactive">Inactivas</option>
            </select>
        </div>
        <div class="filter-group campaign-search">
            <label for="campaignSearch">Buscar campaña</label>
            <input type="search" id="campaignSearch" placeholder="Nombre, ID, fuente o medio">
        </div>
        <div class="filter-actions campaign-filter-actions">
            <button type="button" class="main-tab" id="advancedFiltersOpen">Filtros avanzados</button>
            <button type="button" class="filter-reset" id="resetFilters">Limpiar filtros</button>
        </div>
    </section>

    <aside class="campaign-drawer" id="advancedFiltersDrawer" aria-hidden="true">
        <div class="campaign-drawer-backdrop" id="advancedFiltersCloseBackdrop"></div>
        <section class="campaign-drawer-panel card" aria-label="Filtros avanzados">
            <div class="panel-title">
                <div>
                    <h2>Filtros avanzados</h2>
                    <div class="small">Segmenta campañas reales por campaña, resultado y clasificación</div>
                </div>
                <button type="button" class="main-tab" id="advancedFiltersClose">Cerrar</button>
            </div>
            <div class="campaign-advanced-grid">
                <div class="filter-group">
                    <label>Nombre de campaña</label>
                    <div class="campaign-name-toolbar">
                        <button type="button" class="main-tab" id="campaignNamesSelectAll">Seleccionar todas</button>
                        <button type="button" class="filter-reset" id="campaignNamesClear">Limpiar</button>
                    </div>
                    <div class="campaign-name-checklist" id="campaignNameChecklist"></div>
                </div>
                <div class="filter-group">
                    <label for="hasOpportunity">Oportunidad</label>
                    <select id="hasOpportunity">
                        <option value="">Todas</option>
                        <option value="yes">Sí</option>
                        <option value="no">No</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="hasReservation">Reserva</label>
                    <select id="hasReservation">
                        <option value="">Todas</option>
                        <option value="yes">Sí</option>
                        <option value="no">No</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="hasSale">Venta</label>
                    <select id="hasSale">
                        <option value="">Todas</option>
                        <option value="yes">Sí</option>
                        <option value="no">No</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="classification">Clasificación</label>
                    <select id="classification"><option value="">Todas</option></select>
                </div>
            </div>
            <div class="filter-actions campaign-filter-actions">
                <button type="button" class="main-tab active" id="advancedFiltersApply">Aplicar filtros</button>
            </div>
        </section>
    </aside>

    @if (\App\Support\ReportUserAccess::canSeeSyncDiagnostics(request()))
    <aside class="campaign-drawer campaign-diagnostics-drawer" id="campaignDiagnosticsModal" aria-hidden="true">
        <div class="campaign-drawer-backdrop" id="campaignDiagnosticsCloseBackdrop"></div>
        <section class="campaign-drawer-panel card" aria-label="Diagnóstico de sincronización">
            <div class="panel-title">
                <div>
                    <h2>Diagnóstico de sincronización</h2>
                    <div class="small">Estado de inversión, atribución y calidad de tracking</div>
                </div>
                <button type="button" class="main-tab" id="campaignDiagnosticsClose">Cerrar</button>
            </div>
            <div class="campaign-diagnostics" id="campaignDiagnostics"></div>
        </section>
    </aside>
    @endif

    <main>
        <section class="tab-panel active">
            <div class="notice" id="loadingMessage">Cargando datos de campañas...</div>
            <div class="notice is-hidden" id="emptyMessage">No hay campañas de plataforma para el periodo seleccionado.</div>
            <div id="warnings"></div>
            <section class="card panel is-hidden" id="salesforceReconciliation"></section>
            <div class="campaign-legacy-context" aria-hidden="true">
                <span id="periodLabel"></span>
                <span id="pivotLabel"></span>
                <span id="selectedContextLabel"></span>
                <span id="campaignUpdatedBadge"></span>
            </div>

            <section class="campaign-topbar">
                <nav class="tabs-main campaign-context-tabs" aria-label="Contexto de campañas">
                    <button type="button" class="main-tab active" data-context="all">Todos</button>
                    <button type="button" class="main-tab" data-context="venta">Venta</button>
                    <button type="button" class="main-tab" data-context="tasacion">Tasación</button>
                </nav>
                @if (\App\Support\ReportUserAccess::canSeeSyncDiagnostics(request()))
                <div class="campaign-topbar-actions">
                    <button type="button" class="main-tab" id="diagnosticsOpen">Ver diagnóstico</button>
                </div>
                @endif
            </section>

            <section class="kpis dashboard-kpis" id="summaryKpis"></section>
            <p class="small" id="investmentClosureStatus" aria-live="polite"></p>

            <section class="card panel is-hidden" id="platformComparisonPanel">
                <div class="panel-title">
                    <div>
                        <h2>Comparativa por plataforma</h2>
                        <div class="small">Google Ads frente a Meta Ads en el contexto seleccionado</div>
                    </div>
                </div>
                <div class="campaign-bar-list platform-comparison-grid" id="platformComparison"></div>
            </section>

            <section class="campaign-charts-grid" id="campaignCharts"></section>

            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Rankings</h2>
                        <div class="small">Campañas reales de Google Ads y Meta Ads</div>
                    </div>
                    <div class="ranking-settings">
                        <button type="button" class="main-tab" id="rankingsToggle">Configurar rankings</button>
                        <div class="ranking-popover card is-hidden" id="rankingsPopover"></div>
                    </div>
                </div>
                <div class="priority-grid" id="rankingsGrid"></div>
            </section>

            <section id="campaignTables"></section>
        </section>
    </main>
</div>
</x-reports.app-shell>
