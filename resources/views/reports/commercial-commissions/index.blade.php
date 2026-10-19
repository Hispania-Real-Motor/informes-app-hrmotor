<x-reports.app-shell
    title="Comisiones Comerciales"
    current-report="commercial-commissions"
    body-class="campaigns-report commercial-commissions-report"
    :updated-badge-text="! empty($dashboard['last_updated_label'])
        ? 'Datos actualizados: '.$dashboard['last_updated_label']
        : 'Datos actualizados: pendiente'"
>
    <x-slot:head>
    @vite([
        'resources/css/reports/leads-dashboard.css',
        'resources/js/reports/commercial-commissions-dashboard.js',
    ])
    <script>
        window.reportUserRole = @json($reportUserRole ?? 'viewer');
    </script>
    </x-slot:head>
@php
    $activeCommissionTab = $activeCommissionTab ?? 'summary';
    $summaryRows = collect($dashboard['summary_rows'] ?? []);
    $delegationRows = collect($dashboard['delegation_rows'] ?? []);
    $callCenterSummaryRows = collect($callCenterDashboard['summary_rows'] ?? []);
    $contactCenterSummaryRows = collect($contactCenterDashboard['summary_rows'] ?? []);
    $areaManagerSummaryRows = collect($areaManagerDashboard['summary_rows'] ?? []);
    $financialSummaryRows = collect($financialDashboard['summary_rows'] ?? []);
    $summaryTabUrl = request()->fullUrlWithQuery(['tab' => 'summary']);
    $delegationsTabUrl = request()->fullUrlWithQuery(['tab' => 'delegations']);
    $callCenterTabUrl = request()->fullUrlWithQuery(['tab' => 'call-center']);
    $contactCenterTabUrl = request()->fullUrlWithQuery(['tab' => 'contact-center']);
    $areaManagerTabUrl = request()->fullUrlWithQuery(['tab' => 'area-manager']);
    $financialsTabUrl = request()->fullUrlWithQuery(['tab' => 'financials']);
    $delegationDeliveriesAuditUrl = route('reports.commercial-commissions.export.delegation-deliveries', [
        'month' => $dashboard['month'] ?? null,
    ]);
    $commissionExportUrl = route('reports.commercial-commissions.export.commissions', array_filter([
        'month' => $dashboard['month'] ?? null,
        'call_center_contract_from' => $callCenterDashboard['contract_from'] ?? null,
        'call_center_contract_to' => $callCenterDashboard['contract_to'] ?? null,
    ]));
    $stockLabel = 'Stock +'.(int) ($formulaSettings['stock']['days_threshold'] ?? 150);
    $bonusLabel = 'Bonus +'.(int) ($formulaSettings['bonus']['start_after_delivery'] ?? 15);
    $defaultCommercialId = $summaryRows->first()['commercial_id'] ?? null;
    $callCenterDefaultAgentKey = $callCenterSummaryRows->first()['agent_key'] ?? null;
    $totalFinalCommission = (float) $summaryRows->sum('final_commission');
    $totalPrimaAdjusted = (float) $summaryRows->sum('prima_adjusted');
    $totalPenalties = (float) $summaryRows->sum('total_penalties');
    $totalDeliveries = (int) $summaryRows->sum('deliveries_count');
    $totalDelegationDeliveries = (int) $delegationRows->sum('deliveries_count');
    $totalDelegationPrimaFinal = (float) $delegationRows->sum('prima_final');
    $totalDelegationCommissions = (float) $delegationRows->sum('total_commission');
    $delegationGoalsConfigured = (int) $delegationRows->filter(fn (array $row) => ($row['target_deliveries'] ?? 0) > 0)->count();
    $callCenterTotalAutomatic = (float) $callCenterSummaryRows->sum('automatic_total');
    $callCenterTotalPurchases = (float) $callCenterSummaryRows->sum('purchase_commission');
    $callCenterTotalSales = (float) $callCenterSummaryRows->sum('sales_commission');
    $callCenterTotalChanges = (float) $callCenterSummaryRows->sum('changes_commission');
    $callCenterTotalGerman = (float) $callCenterSummaryRows->sum('german_negotiation_commission');
    $callCenterTotalFacilitea = (float) $callCenterSummaryRows->sum('facilitea_commission');
    $callCenterCountPurchases = (int) $callCenterSummaryRows->sum('purchase_count');
    $callCenterCountSales = (int) $callCenterSummaryRows->sum('sales_count');
    $callCenterCountChanges = (int) $callCenterSummaryRows->sum('changes_count');
    $callCenterCountGerman = (int) $callCenterSummaryRows->sum('german_negotiation_count');
    $callCenterCountFacilitea = (int) $callCenterSummaryRows->sum('facilitea_count');
    $kpiTooltips = [
        'Mes analizado' => 'Mes cerrado usado por el informe. Pivota por cv_signed_date, que viene de Fecha_firma_contrato__c.',
        'Oportunidades' => 'Conteo de salesforce_opportunities con cv_signed=true, stage_name distinto de Cerrada perdida, record_type Venta/Cambio/Tasacion y gestion_de_venta false o null.',
        'Resenas' => 'Regla actual: reseñas cuyo CreatedDate cae dentro del mes, atribuidas por OwnerId. El porcentaje divide esas reseñas entre las operaciones elegibles del comercial del mismo mes. Puede superar el 100 % si hay varias reseñas por operación o si ambos universos no tienen relación uno-a-uno.',
        'Comerciales' => 'Numero de usuarios visibles en resumen. Solo perfiles Salesforce Compra/Venta y Comerciales Partner Community con actividad real del mes.',
        'Comision final' => 'Formula: max(prima ajustada - penalizaciones, 0) + producto financiacion + producto garantias.',
        'Prima ajustada' => 'Formula: prima total x tramo de entregas.',
        'Penalizaciones' => 'Suma de penalizacion por garantias, penalizacion por resenas y penalizacion por financiacion.',
        'Entregas' => 'Conteo de oportunidades de tipo Venta y Cambio del mes. Cada entrega no compartida suma '.number_format((float) ($formulaSettings['sales']['solo_delivery_amount'] ?? 60), 2, ',', '.').' EUR.',
        'Compras liquidadas' => 'Hasta mayo de 2026 son compras historicas liquidadas cuando se vende el vehiculo. Desde junio de 2026: comercial cobra Tasacion 60 EUR y Cambio 85 EUR en el mes; tasador cobra Tasacion + Cambio por tramo mensual.',
        'Compartidas' => 'La entrega compartida reparte '.number_format((float) ($formulaSettings['sales']['shared_owner_delivery_amount'] ?? 30), 2, ',', '.').' EUR para owner y '.number_format((float) ($formulaSettings['sales']['shared_secondary_delivery_amount'] ?? 30), 2, ',', '.').' EUR para el comercial compartido.',
        'Ventas' => 'Desde junio de 2026: Venta 60 EUR, Tasacion 60 EUR y Cambio 85 EUR para comerciales. El tasador cobra sus ventas a 60 EUR y sus compras por tramo mensual.',
        'Stock label' => 'Suma de '.number_format((float) ($formulaSettings['stock']['amount'] ?? 10), 2, ',', '.').' EUR por cada entrega con dias de stock >= '.number_format((float) ($formulaSettings['stock']['days_threshold'] ?? 150), 0, ',', '.').'.',
        'Bonus label' => 'Suma de '.number_format((float) ($formulaSettings['bonus']['amount_per_delivery'] ?? 30), 2, ',', '.').' EUR por cada entrega por encima de la numero '.number_format((float) ($formulaSettings['bonus']['start_after_delivery'] ?? 15), 0, ',', '.').'.',
        'Prima total' => 'Formula: ventas + compras + compartidas - descuento 5% + '.$stockLabel.' + '.$bonusLabel.'.',
        'Prima neta' => 'Formula: max(prima ajustada - penalizaciones, 0).',
        'Prod. financiacion' => 'Producto calculado por tramos sobre Beneficio_financiacion_comercial__c.',
        'Prod. garantias' => 'Producto calculado por tramos sobre Garant_a_Total__c.',
    ];
    $tooltip = static fn (string $label): string => $kpiTooltips[$label] ?? '';
    $closureStatusLabels = [
        'provisional' => 'Provisional',
        'pending_approval' => 'Pendiente de aprobación',
        'definitive' => 'Definitivo',
        'reopened' => 'Reabierto',
    ];
    $closureStatus = $commissionClosure['status'] ?? 'provisional';
    $visibleDashboardWarnings = $activeCommissionTab === 'delegations'
        ? $delegationRows
            ->filter(fn (array $row): bool => (int) ($row['store_manager_distinct_count'] ?? 0) > 2)
            ->pluck('store_manager_alert')->filter()->unique()->values()
        : collect($dashboard['warnings'] ?? []);
@endphp
<div class="wrap">
    <main>
        <section class="tab-panel active">
            <section class="filters card commission-filters">
                <form method="GET" class="commission-filter-form">
                    <input type="hidden" name="tab" value="{{ $activeCommissionTab }}">
                    @if (! empty($callCenterDashboard['contract_from']))
                        <input type="hidden" name="call_center_contract_from" value="{{ $callCenterDashboard['contract_from'] }}">
                    @endif
                    @if (! empty($callCenterDashboard['contract_to']))
                        <input type="hidden" name="call_center_contract_to" value="{{ $callCenterDashboard['contract_to'] }}">
                    @endif
                    <div class="filter-group">
                        <label for="month">Mes cerrado</label>
                        <input type="month" id="month" name="month" value="{{ $dashboard['month'] }}">
                    </div>
                    <div class="filter-actions commission-filter-actions">
                        <button type="submit" class="main-tab">Cargar informe</button>
                        <a id="commissionExport" href="{{ $commissionExportUrl }}" class="main-tab active">Exportar comisiones</a>
                        @if ($canAuditEconomicReviews)
                            <a href="{{ route('reports.commercial-commissions.export.reviews-audit', ['month' => $dashboard['month']]) }}" class="main-tab">Auditar reseñas</a>
                        @endif
                    </div>
                </form>
            </section>

            @if ($canSeeUniverseReconciliation)
            <section class="card panel commission-universe-panel">
                <div class="panel-title">
                    <div>
                        <h2>Conciliacion del universo</h2>
                        <div class="small">Los IDs y motivos se conservan en los detalles y exportaciones del bloque activo.</div>
                    </div>
                </div>
                <div class="commission-formula-strip">
                    <span><strong>{{ number_format($universeReconciliation['base'], 0, ',', '.') }}</strong> {{ $universeReconciliation['baseLabel'] }}</span>
                    <span>− <strong>{{ number_format($universeReconciliation['excluded'], 0, ',', '.') }}</strong> {{ $universeReconciliation['excludedLabel'] }}</span>
                    <span>+ <strong>{{ number_format($universeReconciliation['included'], 0, ',', '.') }}</strong> {{ $universeReconciliation['includedLabel'] }}</span>
                    <span>= <strong>{{ number_format($universeReconciliation['shown'], 0, ',', '.') }}</strong> {{ $universeReconciliation['shownLabel'] }}</span>
                </div>
                @foreach ($universeReconciliation['notes'] as $note)
                    <div class="small">{{ $note }}</div>
                @endforeach
            </section>
            @endif

            @if (($adminPreparationOverview ?? []) !== [])
                <section class="card panel commission-closure-panel" aria-labelledby="admin-preparation-title">
                    <div class="panel-title">
                        <div>
                            <h2 id="admin-preparation-title">Preparación de comisiones · {{ \Carbon\CarbonImmutable::createFromFormat('!Y-m', $dashboard['month'])->locale('es')->translatedFormat('F \d\e Y') }}</h2>
                            <div class="small">Las fuentes se validan en el servidor. No se aceptan confirmaciones manuales del navegador.</div>
                        </div>
                    </div>
                    @if (($hasCustomCallCenterFilters ?? false) && $closureScope === 'call_center')
                        <div class="notice commission-warning" role="alert">
                            La vista contiene filtros contractuales personalizados. El cierre siempre congelará el mes natural completo
                            ({{ \Carbon\CarbonImmutable::createFromFormat('!Y-m', $dashboard['month'])->startOfMonth()->format('d/m/Y') }} -
                            {{ \Carbon\CarbonImmutable::createFromFormat('!Y-m', $dashboard['month'])->endOfMonth()->format('d/m/Y') }})
                            por {{ number_format(data_get($canonicalCallCenterClosureSummary, 'final_total', 0), 2, ',', '.') }} EUR. Restablece el rango mensual antes de preparar.
                        </div>
                    @endif
                    <div class="table-shell commission-closure-table-shell">
                        <table class="commission-closure-table">
                            <thead><tr><th>Bloque</th><th>Fuentes</th><th>Estado</th><th>Acción</th></tr></thead>
                            <tbody>
                                @foreach ($adminPreparationOverview as $preparation)
                                    @php
                                        $status = data_get($preparation, 'status.status');
                                        $displayStatus = data_get($preparation, 'status.is_prepared') ? ($closureStatusLabels[$status] ?? $status) : 'Sin preparar';
                                        $readiness = $preparation['readiness'] ?? null;
                                        $isActiveScope = $preparation['scope'] === $closureScope;
                                        $customRangeBlocksPrepare = $isActiveScope && $preparation['scope'] === 'call_center' && ($hasCustomCallCenterFilters ?? false);
                                    @endphp
                                    <tr>
                                        <td><strong>{{ $preparation['label'] }}</strong></td>
                                        <td>
                                            @if ($readiness === null)
                                                <span class="small">Abre el bloque para verificar sus fuentes.</span>
                                            @else
                                                @foreach (($readiness['components'] ?? []) as $source)
                                                    <div class="commission-source-state"><span class="type-pill {{ $source['blocking'] ? 'pending' : 'group' }}">{{ $source['blocking'] ? 'Revisar' : 'OK' }}</span> {{ $source['label'] }}@if($source['updated_at']) · {{ \Carbon\CarbonImmutable::parse($source['updated_at'])->format('d/m/Y H:i') }}@endif</div>
                                                @endforeach
                                                @foreach (($readiness['blocking'] ?? []) as $blocking)<div class="small commission-source-error">{{ $blocking }}</div>@endforeach
                                                @foreach (($readiness['warnings'] ?? []) as $warning)<div class="small commission-source-warning">{{ $warning }}</div>@endforeach
                                            @endif
                                        </td>
                                        <td><span class="type-pill {{ $status === 'definitive' ? 'group' : 'pending' }}">{{ $displayStatus }}</span></td>
                                        <td>
                                            @if ($status === 'pending_approval' && data_get($preparation, 'status.is_prepared'))
                                                <span class="small">Pendiente del aprobador</span>
                                            @elseif ($status === 'definitive')
                                                <span class="small">Cierre definitivo</span>
                                            @elseif (! $isActiveScope)
                                                <span class="small">Selecciona su pestaña</span>
                                            @elseif ($customRangeBlocksPrepare)
                                                <span class="small">Restablece el rango mensual para preparar</span>
                                            @elseif ($readiness !== null && $readiness['ready'])
                                                <form method="POST" action="{{ route('reports.commercial-commissions.closure.prepare') }}">@csrf<input type="hidden" name="month" value="{{ $dashboard['month'] }}"><input type="hidden" name="closure_scope" value="{{ $preparation['scope'] }}"><button type="submit" class="main-tab active">Preparar {{ $preparation['label'] }}</button></form>
                                            @else
                                                <span class="small">Fuentes pendientes</span>
                                            @endif

                                            @if (data_get($preparation, 'status.is_prepared') && $status === 'definitive' && ($canReopenEconomicClosures ?? false))
                                                <details class="commission-exception-actions">
                                                    <summary>Acciones excepcionales</summary>
                                                        <form method="POST" action="{{ route('reports.commercial-commissions.closure.reopen') }}" class="commission-exception-form">@csrf<input type="hidden" name="month" value="{{ $dashboard['month'] }}"><input type="hidden" name="closure_scope" value="{{ $preparation['scope'] }}"><label>Motivo de reapertura<input name="reason" minlength="10" required></label><button type="submit" class="filter-reset">Reabrir</button></form>
                                                </details>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif

            @include('reports.commercial-commissions.partials.closure-approval', ['approvalMonth' => $dashboard['month']])

            @if ($errors->has('export'))
                <div class="notice commission-warning" role="alert">
                    {{ $errors->first('export') }}
                </div>
            @endif

            @if (! $dashboard['ready'])
                <div class="notice">
                    El informe no esta listo para calculo final. Hay bloqueos de configuracion o logica que impiden sacar comisiones definitivas.
                </div>
            @endif

            @foreach ($dashboard['issues'] as $issue)
                <div class="notice">{{ $issue }}</div>
            @endforeach

            @foreach ($visibleDashboardWarnings as $warning)
                <div class="notice commission-warning">{{ $warning }}</div>
            @endforeach

            <section class="kpis dashboard-kpis">
                <article class="card campaign-kpi">
                    <div class="kpi-label"><span class="kpi-tooltip" data-kpi-tooltip="{{ $tooltip('Mes analizado') }}">Mes analizado</span></div>
                    <div class="kpi-value">{{ $dashboard['month_label'] }}</div>
                    <div class="kpi-hint">Periodo cerrado seleccionado para contraste.</div>
                </article>
                <article class="card campaign-kpi">
                    <div class="kpi-label"><span class="kpi-tooltip" data-kpi-tooltip="{{ $tooltip('Oportunidades') }}">Oportunidades</span></div>
                    <div class="kpi-value">{{ number_format($dashboard['diagnostics']['opportunities_total'], 0, ',', '.') }}</div>
                    <div class="kpi-hint">CV firmados de Venta, Cambio y Tasacion en el mes.</div>
                </article>
                <article class="card campaign-kpi">
                    <div class="kpi-label"><span class="kpi-tooltip" data-kpi-tooltip="{{ $tooltip('Resenas') }}">Resenas</span></div>
                    <div class="kpi-value">{{ number_format($dashboard['diagnostics']['reviews_count'], 0, ',', '.') }}</div>
                    <div class="kpi-hint">Objeto Resena__c creado dentro del mes.</div>
                </article>
            </section>

            @if ($canSeeSyncDiagnostics ?? false)
                <section class="card panel">
                    <div class="panel-title">
                        <div>
                            <h2>Diagnostico de datos base</h2>
                            <div class="small">Volumen real sincronizado para el mes seleccionado.</div>
                        </div>
                    </div>
                    <div class="campaign-diagnostics commission-diagnostics-grid">
                        <div class="diagnostic-item">
                            <span>Ventas / Cambios</span>
                            <strong>{{ number_format($dashboard['diagnostics']['sales_count'], 0, ',', '.') }}</strong>
                        </div>
                        <div class="diagnostic-item">
                            <span>Compras base</span>
                            <strong>{{ number_format($dashboard['diagnostics']['purchases_count'], 0, ',', '.') }}</strong>
                        </div>
                        <div class="diagnostic-item">
                            <span>Operaciones</span>
                            <strong>{{ number_format($dashboard['diagnostics']['operations_count'], 0, ',', '.') }}</strong>
                        </div>
                        <div class="diagnostic-item">
                            <span>Compartidas</span>
                            <strong>{{ number_format($dashboard['diagnostics']['shared_sales_count'], 0, ',', '.') }}</strong>
                        </div>
                        <div class="diagnostic-item">
                            <span>{{ $stockLabel }}</span>
                            <strong>{{ number_format($dashboard['diagnostics']['stock_150_count'], 0, ',', '.') }}</strong>
                        </div>
                        <div class="diagnostic-item">
                            <span>Comerciales detectados</span>
                            <strong>{{ number_format($dashboard['diagnostics']['commercials_count'], 0, ',', '.') }}</strong>
                        </div>
                        <div class="diagnostic-item">
                            <span>Usuarios sincronizados</span>
                            <strong>{{ number_format($dashboard['diagnostics']['synced_users_count'] ?? 0, 0, ',', '.') }}</strong>
                        </div>
                        <div class="diagnostic-item">
                            <span>Filtro Gestion de venta</span>
                            <strong>{{ $dashboard['diagnostics']['sale_management_filter_applied'] ? 'Aplicado' : 'No aplicado' }}</strong>
                        </div>
                        <div class="diagnostic-item">
                            <span>Vehiculos vendibles enlazados</span>
                            <strong>{{ number_format($dashboard['diagnostics']['sold_vehicle_links_count'] ?? 0, 0, ',', '.') }}</strong>
                        </div>
                        <div class="diagnostic-item">
                            <span>Ventas con comprador Product2</span>
                            <strong>{{ number_format($dashboard['diagnostics']['sales_with_product_buyer_count'] ?? 0, 0, ',', '.') }}</strong>
                        </div>
                        <div class="diagnostic-item">
                            <span>Compras historicas candidatas</span>
                            <strong>{{ number_format($dashboard['diagnostics']['historical_purchase_candidates_count'] ?? 0, 0, ',', '.') }}</strong>
                        </div>
                        <div class="diagnostic-item">
                            <span>Compras liquidadas</span>
                            <strong>{{ number_format($dashboard['diagnostics']['matched_purchase_commissions_count'] ?? 0, 0, ',', '.') }}</strong>
                        </div>
                    </div>
                </section>
            @endif

            <section class="card panel">
                    <div class="panel-title">
                        <div>
                            <h2>Comerciales</h2>
                            <div class="small">La financiacion se calcula sobre Venta + Cambio + Tasacion y las compras solo se liquidan al propietario de la compra cuando ese vehiculo se vende.</div>
                        </div>
                    </div>

                    <nav class="tabs-main commission-inner-tabs" aria-label="Vista de comisiones">
                        <a href="{{ $summaryTabUrl }}" class="main-tab{{ $activeCommissionTab === 'summary' ? ' active' : '' }}" data-commission-tab-trigger="summary" data-commission-tab-url="{{ $summaryTabUrl }}" data-commission-tab-current="{{ $activeCommissionTab === 'summary' ? 'true' : 'false' }}">Comerciales</a>
                        <a href="{{ $delegationsTabUrl }}" class="main-tab{{ $activeCommissionTab === 'delegations' ? ' active' : '' }}" data-commission-tab-trigger="delegations" data-commission-tab-url="{{ $delegationsTabUrl }}" data-commission-tab-current="{{ $activeCommissionTab === 'delegations' ? 'true' : 'false' }}">Delegaciones</a>
                        @unless ($isAreaRestricted ?? false)
                            @if ($canViewCallContactDetails ?? false)
                                <a href="{{ $callCenterTabUrl }}" class="main-tab{{ $activeCommissionTab === 'call-center' ? ' active' : '' }}" data-commission-tab-trigger="call-center" data-commission-tab-url="{{ $callCenterTabUrl }}" data-commission-tab-current="{{ $activeCommissionTab === 'call-center' ? 'true' : 'false' }}">Call Center</a>
                                <a href="{{ $contactCenterTabUrl }}" class="main-tab{{ $activeCommissionTab === 'contact-center' ? ' active' : '' }}" data-commission-tab-trigger="contact-center" data-commission-tab-url="{{ $contactCenterTabUrl }}" data-commission-tab-current="{{ $activeCommissionTab === 'contact-center' ? 'true' : 'false' }}">Contact Center</a>
                            @else
                                <span class="main-tab is-disabled" aria-disabled="true">Call Center</span>
                                <span class="main-tab is-disabled" aria-disabled="true">Contact Center</span>
                            @endif
                        @endunless
                        <a href="{{ $areaManagerTabUrl }}" class="main-tab{{ $activeCommissionTab === 'area-manager' ? ' active' : '' }}" data-commission-tab-trigger="area-manager" data-commission-tab-url="{{ $areaManagerTabUrl }}" data-commission-tab-current="{{ $activeCommissionTab === 'area-manager' ? 'true' : 'false' }}">Area Manager</a>
                        @unless ($isAreaRestricted ?? false)
                            <a href="{{ $financialsTabUrl }}" class="main-tab{{ $activeCommissionTab === 'financials' ? ' active' : '' }}" data-commission-tab-trigger="financials" data-commission-tab-url="{{ $financialsTabUrl }}" data-commission-tab-current="{{ $activeCommissionTab === 'financials' ? 'true' : 'false' }}">Financieros</a>
                        @endunless
                        @if ($canManageFinancingPenalties ?? false)
                            <a href="{{ route('reports.commission-penalties.index') }}" class="main-tab">Penalizaciones financieras</a>
                        @endif
                    </nav>

                    <div @class(['is-hidden' => $activeCommissionTab !== 'summary']) data-commission-tab-panel="summary">
                        <section class="campaign-context-grid commission-context-grid">
                            <article class="card campaign-context-card">
                                <span class="kpi-tooltip" data-kpi-tooltip="{{ $tooltip('Comerciales') }}">Comerciales</span>
                                <strong>{{ number_format($summaryRows->count(), 0, ',', '.') }}</strong>
                            </article>
                            <article class="card campaign-context-card">
                                <span class="kpi-tooltip" data-kpi-tooltip="{{ $tooltip('Comision final') }}">Comision final</span>
                                <strong>{{ number_format($totalFinalCommission, 2, ',', '.') }} EUR</strong>
                            </article>
                            <article class="card campaign-context-card">
                                <span class="kpi-tooltip" data-kpi-tooltip="{{ $tooltip('Prima ajustada') }}">Prima ajustada</span>
                                <strong>{{ number_format($totalPrimaAdjusted, 2, ',', '.') }} EUR</strong>
                            </article>
                            <article class="card campaign-context-card">
                                <span class="kpi-tooltip" data-kpi-tooltip="{{ $tooltip('Penalizaciones') }}">Penalizaciones</span>
                                <strong class="commission-penalty-text">{{ number_format($totalPenalties, 2, ',', '.') }} EUR</strong>
                            </article>
                        </section>

                        <section class="platform-comparison-grid commission-overview-grid">
                            <article class="card platform-comparison-card">
                                <div class="platform-comparison-head">
                                    <strong>Volumen comercial</strong>
                                    <span>Conteos agregados del mes seleccionado.</span>
                                </div>
                                <div class="platform-comparison-metrics">
                                    <div class="platform-metric-item"><span>Entregas</span><strong>{{ number_format($totalDeliveries, 0, ',', '.') }}</strong></div>
                                    <div class="platform-metric-item"><span>Compras liquidadas</span><strong>{{ number_format($summaryRows->sum('purchases_amount'), 2, ',', '.') }} EUR</strong></div>
                                    <div class="platform-metric-item"><span>Compartidas</span><strong>{{ number_format($summaryRows->sum('shared_amount'), 2, ',', '.') }} EUR</strong></div>
                                    <div class="platform-metric-item"><span>Comerciales visibles</span><strong>{{ number_format($summaryRows->count(), 0, ',', '.') }}</strong></div>
                                </div>
                            </article>
                            <article class="card platform-comparison-card">
                                <div class="platform-comparison-head">
                                    <strong>Base variable</strong>
                                    <span>Conceptos antes de ajustar por tramos y penalizaciones.</span>
                                </div>
                                <div class="platform-comparison-metrics">
                                    <div class="platform-metric-item"><span>Ventas</span><strong>{{ number_format($summaryRows->sum('sales_amount'), 2, ',', '.') }} EUR</strong></div>
                                    <div class="platform-metric-item"><span>{{ $stockLabel }}</span><strong>{{ number_format($summaryRows->sum('stock_150_amount'), 2, ',', '.') }} EUR</strong></div>
                                    <div class="platform-metric-item"><span>{{ $bonusLabel }}</span><strong>{{ number_format($summaryRows->sum('bonus_15_amount'), 2, ',', '.') }} EUR</strong></div>
                                    <div class="platform-metric-item"><span>Prima total</span><strong>{{ number_format($summaryRows->sum('prima_total'), 2, ',', '.') }} EUR</strong></div>
                                </div>
                            </article>
                            <article class="card platform-comparison-card">
                                <div class="platform-comparison-head">
                                    <strong>Productos y ajustes</strong>
                                    <span>Impacto final despues de penalizaciones.</span>
                                </div>
                                <div class="platform-comparison-metrics">
                                    <div class="platform-metric-item"><span>Penalizaciones</span><strong class="commission-penalty-text">{{ number_format($totalPenalties, 2, ',', '.') }} EUR</strong></div>
                                    <div class="platform-metric-item"><span>Prima neta</span><strong>{{ number_format($summaryRows->sum('prima_after_penalties'), 2, ',', '.') }} EUR</strong></div>
                                    <div class="platform-metric-item"><span>Prod. financiacion</span><strong>{{ number_format($summaryRows->sum('financing_product_amount'), 2, ',', '.') }} EUR</strong></div>
                                    <div class="platform-metric-item"><span>Prod. garantias</span><strong>{{ number_format($summaryRows->sum('guarantee_product_amount'), 2, ',', '.') }} EUR</strong></div>
                                </div>
                            </article>
                        </section>

                        <div class="commission-summary-toolbar">
                            <div class="commission-summary-toolbar-copy">
                                <div class="small">
                                    Resumen limitado a perfiles Salesforce <strong>Compra/Venta</strong> y <strong>Comerciales Partner Community</strong>.
                                </div>
                                <div class="filter-group commission-summary-filter-group">
                                    <label for="commissionSummarySearch">Filtrar comercial</label>
                                    <input
                                        type="search"
                                        id="commissionSummarySearch"
                                        placeholder="Nombre o ID de Salesforce"
                                        autocomplete="off"
                                        data-commercial-summary-search-input
                                    >
                                </div>
                            </div>
                            <div class="columns-menu">
                                <button type="button" class="filter-reset" id="commissionSummaryColumnsButton">Columnas</button>
                                <div class="columns-popover card is-hidden" id="commissionSummaryColumnsPopover"></div>
                            </div>
                        </div>

                        <div class="commission-summary-table-wrap">
                            <div class="table-scrollbar-top" id="commissionSummaryTableScrollTop" aria-hidden="true">
                                <div class="table-scrollbar-spacer" id="commissionSummaryTableScrollTopSpacer"></div>
                            </div>
                            <div class="table-shell" id="commissionSummaryTableShell" style="height: 700px;">
                                <table id="commissionSummaryTable" data-sortable-table="commission-summary">
                                    <thead>
                                    <tr>
                                    <th data-sortable="true" data-column="commercial_name">Comercial</th>
                                    <th data-sortable="true" data-column="commission_mode">Tipo</th>
                                    <th class="num" data-sortable="true" data-column="final_commission">Comision final</th>
                                    <th class="num" data-sortable="true" data-column="deliveries_count">Entregas</th>
                                    <th class="num" data-sortable="true" data-column="sales_count">N ventas</th>
                                    <th class="num" data-sortable="true" data-column="sales_amount">Ventas</th>
                                    <th class="num" data-sortable="true" data-column="appraisals_count">N tasaciones</th>
                                    <th class="num" data-sortable="true" data-column="appraisals_amount">Tasaciones</th>
                                    <th class="num" data-sortable="true" data-column="changes_count">N cambios</th>
                                    <th class="num" data-sortable="true" data-column="changes_amount">Cambios</th>
                                    <th class="num" data-sortable="true" data-column="purchases_amount">Compras</th>
                                    <th class="num is-hidden" data-sortable="true" data-column="appraiser_purchase_tier">Tramo tasador</th>
                                    <th class="num is-hidden" data-sortable="true" data-column="appraiser_purchase_rate">EUR/compra tasador</th>
                                    <th class="num is-hidden" data-sortable="true" data-column="appraiser_financing_commission">Financiacion tasador 3%</th>
                                    <th class="num is-hidden" data-sortable="true" data-column="appraiser_speed_amount">Incentivo rapidez tasador</th>
                                    <th class="num" data-sortable="true" data-column="shared_amount">Compartidas</th>
                                    <th class="num is-hidden" data-sortable="true" data-column="discount_penalty_amount">Descuento 5%</th>
                                    <th class="num is-hidden" data-sortable="true" data-column="stock_150_amount">{{ $stockLabel }}</th>
                                    <th class="num is-hidden" data-sortable="true" data-column="bonus_15_amount">{{ $bonusLabel }}</th>
                                    <th class="num is-hidden" data-sortable="true" data-column="prima_total">Prima total</th>
                                    <th class="num is-hidden" data-sortable="true" data-column="delivery_bracket">Tramo</th>
                                    <th class="num" data-sortable="true" data-column="prima_adjusted">Prima ajustada</th>
                                    <th class="num" data-sortable="true" data-column="reviews_penalty">Pen. resenas</th>
                                    <th class="num is-hidden" data-sortable="true" data-column="reviews_percentage">% resenas</th>
                                    <th class="num" data-sortable="true" data-column="financing_penalty">Pen. financiacion</th>
                                    <th class="num" data-sortable="true" data-column="financing_cancellation_penalty_amount">Pen. cancelacion fin.</th>
                                    <th class="num is-hidden" data-sortable="true" data-column="financing_percentage">% financiacion</th>
                                    <th class="num" data-sortable="true" data-column="total_penalties">Penalizaciones</th>
                                    <th class="num is-hidden" data-sortable="true" data-column="financing_product_amount">Prod. financiacion</th>
                                    <th class="num is-hidden" data-sortable="true" data-column="guarantee_product_amount">Prod. garantias</th>
                                    </tr>
                                    </thead>
                                    <tbody data-sort-body="commission-summary">
                                    @foreach ($summaryRows as $row)
                                        <tr data-commercial-summary-row data-commercial-summary-search="{{ \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii($row['commercial_name'].' '.$row['commercial_id'])) }}">
                                            <td data-column="commercial_name">{{ $row['commercial_name'] }}</td>
                                            <td data-column="commission_mode">{{ $row['commission_mode'] ?? 'Comercial' }}</td>
                                            <td class="num" data-column="final_commission" data-sort-value="{{ $row['final_commission'] }}"><strong>{{ number_format($row['final_commission'], 2, ',', '.') }}</strong></td>
                                        <td class="num" data-column="deliveries_count" data-sort-value="{{ $row['deliveries_count'] }}">{{ number_format($row['deliveries_count'], 0, ',', '.') }}</td>
                                        <td class="num" data-column="sales_count" data-sort-value="{{ $row['sales_count'] ?? 0 }}">{{ number_format($row['sales_count'] ?? $row['deliveries_count'], 0, ',', '.') }}</td>
                                        <td class="num" data-column="sales_amount" data-sort-value="{{ $row['sales_amount'] }}">{{ number_format($row['sales_amount'], 2, ',', '.') }}</td>
                                        <td class="num" data-column="appraisals_count" data-sort-value="{{ $row['appraisals_count'] ?? 0 }}">{{ number_format($row['appraisals_count'] ?? 0, 0, ',', '.') }}</td>
                                        <td class="num" data-column="appraisals_amount" data-sort-value="{{ $row['appraisals_amount'] ?? 0 }}">{{ number_format($row['appraisals_amount'] ?? 0, 2, ',', '.') }}</td>
                                        <td class="num" data-column="changes_count" data-sort-value="{{ $row['changes_count'] ?? 0 }}">{{ number_format($row['changes_count'] ?? 0, 0, ',', '.') }}</td>
                                        <td class="num" data-column="changes_amount" data-sort-value="{{ $row['changes_amount'] ?? 0 }}">{{ number_format($row['changes_amount'] ?? 0, 2, ',', '.') }}</td>
                                        <td class="num" data-column="purchases_amount" data-sort-value="{{ $row['purchases_amount'] }}">{{ number_format($row['purchases_amount'], 2, ',', '.') }}</td>
                                        <td class="num is-hidden" data-column="appraiser_purchase_tier">{{ $row['appraiser_purchase_tier'] ?? '-' }}</td>
                                        <td class="num is-hidden" data-column="appraiser_purchase_rate" data-sort-value="{{ $row['appraiser_purchase_rate'] ?? 0 }}">{{ number_format($row['appraiser_purchase_rate'] ?? 0, 2, ',', '.') }}</td>
                                        <td class="num is-hidden" data-column="appraiser_financing_commission" data-sort-value="{{ $row['appraiser_financing_commission'] ?? 0 }}">{{ number_format($row['appraiser_financing_commission'] ?? 0, 2, ',', '.') }}</td>
                                        <td class="num is-hidden" data-column="appraiser_speed_amount" data-sort-value="{{ $row['appraiser_speed_amount'] ?? 0 }}">{{ number_format($row['appraiser_speed_amount'] ?? 0, 2, ',', '.') }}</td>
                                        <td class="num" data-column="shared_amount" data-sort-value="{{ $row['shared_amount'] }}">{{ number_format($row['shared_amount'], 2, ',', '.') }}</td>
                                            <td class="num is-hidden" data-column="discount_penalty_amount" data-sort-value="{{ $row['discount_penalty_amount'] }}">{{ number_format($row['discount_penalty_amount'], 2, ',', '.') }}</td>
                                        <td class="num is-hidden" data-column="stock_150_amount" data-sort-value="{{ $row['stock_150_amount'] }}">{{ number_format($row['stock_150_amount'], 2, ',', '.') }}</td>
                                        <td class="num is-hidden" data-column="bonus_15_amount" data-sort-value="{{ $row['bonus_15_amount'] }}">{{ number_format($row['bonus_15_amount'], 2, ',', '.') }}</td>
                                        <td class="num is-hidden" data-column="prima_total" data-sort-value="{{ $row['prima_total'] }}">{{ number_format($row['prima_total'], 2, ',', '.') }}</td>
                                        <td class="num is-hidden" data-column="delivery_bracket" data-sort-value="{{ $row['delivery_bracket_percent'] }}">{{ $row['delivery_bracket_label'] }} · {{ number_format($row['delivery_bracket_percent'], 0, ',', '.') }}%</td>
                                        <td class="num" data-column="prima_adjusted" data-sort-value="{{ $row['prima_adjusted'] }}">{{ number_format($row['prima_adjusted'], 2, ',', '.') }}</td>
                                        <td class="num commission-penalty-text" data-column="reviews_penalty" data-sort-value="{{ $row['reviews_penalty'] }}">{{ number_format($row['reviews_penalty'], 2, ',', '.') }}</td>
                                        <td class="num is-hidden" data-column="reviews_percentage" data-sort-value="{{ $row['reviews_percentage'] }}">{{ number_format($row['reviews_percentage'], 2, ',', '.') }}%</td>
                                        <td class="num commission-penalty-text" data-column="financing_penalty" data-sort-value="{{ $row['financing_penalty'] }}">{{ number_format($row['financing_penalty'], 2, ',', '.') }}</td>
                                        <td class="num commission-penalty-text" data-column="financing_cancellation_penalty_amount" data-sort-value="{{ $row['financing_cancellation_penalty_amount'] ?? 0 }}">{{ number_format($row['financing_cancellation_penalty_amount'] ?? 0, 2, ',', '.') }}</td>
                                        <td class="num is-hidden" data-column="financing_percentage" data-sort-value="{{ $row['financing_percentage'] }}">{{ number_format($row['financing_percentage'], 2, ',', '.') }}%</td>
                                        <td class="num commission-penalty-text" data-column="total_penalties" data-sort-value="{{ $row['total_penalties'] }}">{{ number_format($row['total_penalties'], 2, ',', '.') }}</td>
                                        <td class="num is-hidden" data-column="financing_product_amount" data-sort-value="{{ $row['financing_product_amount'] }}">{{ number_format($row['financing_product_amount'], 2, ',', '.') }}</td>
                                            <td class="num is-hidden" data-column="guarantee_product_amount" data-sort-value="{{ $row['guarantee_product_amount'] }}">{{ number_format($row['guarantee_product_amount'], 2, ',', '.') }}</td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    </div>

                    <div @class(['is-hidden' => $activeCommissionTab !== 'delegations']) data-commission-tab-panel="delegations">
                        <section class="campaign-context-grid commission-context-grid">
                            <article class="card campaign-context-card">
                                <span>Delegaciones</span>
                                <strong>{{ number_format($delegationRows->count(), 0, ',', '.') }}</strong>
                            </article>
                            <article class="card campaign-context-card">
                                <span>Metas configuradas</span>
                                <strong>{{ number_format($delegationGoalsConfigured, 0, ',', '.') }}</strong>
                            </article>
                            <article class="card campaign-context-card">
                                <span>Prima final delegaciones</span>
                                <strong>{{ number_format($totalDelegationPrimaFinal, 2, ',', '.') }} EUR</strong>
                            </article>
                            <article class="card campaign-context-card">
                                <span>Comisiones delegaciones</span>
                                <strong>{{ number_format($totalDelegationCommissions, 2, ',', '.') }} EUR</strong>
                            </article>
                        </section>

                        <section class="platform-comparison-grid commission-overview-grid">
                            <article class="card platform-comparison-card">
                                <div class="platform-comparison-head">
                                    <strong>Volumen por delegacion</strong>
                                    <span>Se usan todas las entregas de la delegacion, aunque la compra proceda de otra.</span>
                                </div>
                                <div class="platform-comparison-metrics">
                                    <div class="platform-metric-item"><span>Entregas</span><strong>{{ number_format($totalDelegationDeliveries, 0, ',', '.') }}</strong></div>
                                    <div class="platform-metric-item"><span>Rentabilidad total</span><strong>{{ number_format($delegationRows->sum('rentability_total'), 2, ',', '.') }} EUR</strong></div>
                                    <div class="platform-metric-item"><span>Bonus importe financiado</span><strong>{{ number_format($delegationRows->sum('financed_amount_bonus_amount'), 2, ',', '.') }} EUR</strong></div>
                                    <div class="platform-metric-item"><span>Bonus rentabilidad</span><strong>{{ number_format($delegationRows->sum('profitability_bonus_amount'), 2, ',', '.') }} EUR</strong></div>
                                </div>
                            </article>
                        </section>

                        <div class="small commission-delegation-note">
                            Entregas: oportunidades con contrato CV firmado y fecha de firma en el mes, no cerradas perdidas, de tipo Venta o Cambio; tambien se incluye cualquier oportunidad cuyo nombre contenga Facilitea. La delegacion se obtiene de Tienda de entrega-compra.
                        </div>

                        @if ($canAuditDelegationDeliveries)
                            <div class="filter-actions commission-filter-actions">
                                <a class="main-tab" href="{{ $delegationDeliveriesAuditUrl }}">Descargar CSV de auditoria de entregas</a>
                            </div>
                        @endif

                        <div class="commission-delegation-table-wrap">
                            <div class="table-scrollbar-top" id="commissionDelegationTableScrollTop" aria-hidden="true">
                                <div class="table-scrollbar-spacer" id="commissionDelegationTableScrollTopSpacer"></div>
                            </div>

                            <div class="table-shell commission-delegation-table-shell" id="commissionDelegationTableShell">
                            <table data-sortable-table="commission-delegations" id="commissionDelegationTable">
                                <thead>
                                <tr>
                                    <th data-sortable="true">Delegacion</th>
                                    <th data-sortable="true">Jefe de tienda</th>
                                    <th class="num" data-sortable="true">Comision total</th>
                                    <th class="num" data-sortable="true">Meta entregas</th>
                                    <th class="num" data-sortable="true">Entregas</th>
                                    <th class="num" data-sortable="true">% objetivo</th>
                                    <th class="num" data-sortable="true">% comision</th>
                                    <th class="num" data-sortable="true">Rentabilidad total</th>
                                    <th class="num" data-sortable="true">Rent. op. media</th>
                                    <th class="num" data-sortable="true">Prima final</th>
                                    <th class="num" data-sortable="true">Reseñas</th>
                                    <th class="num" data-sortable="true">Media nota</th>
                                    <th class="num" data-sortable="true">Com. reseñas</th>
                                    <th class="num" data-sortable="true">% rentabilidad</th>
                                    <th class="num" data-sortable="true">Bonus rent.</th>
                                    <th class="num" data-sortable="true">% imp. financiado</th>
                                    <th class="num" data-sortable="true">Bonus imp. fin.</th>
                                </tr>
                                </thead>
                                <tbody data-sort-body="commission-delegations">
                                @foreach ($delegationRows as $row)
                                    <tr>
                                        <td>{{ $row['delegation_name'] }}</td>
                                        <td>
                                            {{ $row['store_manager_name'] ?? 'No verificable' }}
                                            @if ((int) ($row['store_manager_distinct_count'] ?? 0) > 2 && filled($row['store_manager_alert'] ?? null))
                                                <span class="type-pill pending" title="{{ $row['store_manager_alert'] }}">Revisar</span>
                                            @endif
                                        </td>
                                        <td @class([
                                            'num',
                                            'commission-goal-hit' => $row['objective_reached'],
                                            'commission-goal-miss' => ! $row['objective_reached'],
                                        ]) data-sort-value="{{ $row['total_commission'] }}">
                                            <strong>{{ number_format($row['total_commission'], 2, ',', '.') }}</strong>
                                        </td>
                                        <td class="num" data-sort-value="{{ $row['target_deliveries'] }}">{{ number_format($row['target_deliveries'], 0, ',', '.') }}</td>
                                        <td class="num" data-sort-value="{{ $row['deliveries_count'] }}">{{ number_format($row['deliveries_count'], 0, ',', '.') }}</td>
                                        <td @class([
                                            'num',
                                            'commission-goal-hit' => $row['objective_reached'],
                                            'commission-goal-miss' => ! $row['objective_reached'],
                                        ]) data-sort-value="{{ $row['objective_percentage'] ?? -1 }}">
                                            {{ $row['objective_percentage'] === null ? '-' : number_format($row['objective_percentage'], 2, ',', '.').'%' }}
                                        </td>
                                        <td @class([
                                            'num',
                                            'commission-goal-hit' => $row['objective_reached'],
                                            'commission-goal-miss' => ! $row['objective_reached'],
                                        ]) data-sort-value="{{ $row['objective_commission_percent'] }}">
                                            {{ number_format($row['objective_commission_percent'], 2, ',', '.') }}%
                                        </td>
                                        <td class="num" data-sort-value="{{ $row['rentability_total'] }}">{{ number_format($row['rentability_total'], 2, ',', '.') }}</td>
                                        <td class="num" data-sort-value="{{ $row['average_rentability'] }}">{{ number_format($row['average_rentability'], 2, ',', '.') }}</td>
                                        <td @class([
                                            'num',
                                            'commission-goal-hit' => $row['objective_reached'],
                                            'commission-goal-miss' => ! $row['objective_reached'],
                                        ]) data-sort-value="{{ $row['prima_final'] }}">
                                            <strong>{{ number_format($row['prima_final'], 2, ',', '.') }}</strong>
                                        </td>
                                        <td class="num" data-sort-value="{{ $row['reviews_count'] }}">{{ number_format($row['reviews_count'], 0, ',', '.') }}</td>
                                        <td class="num" data-sort-value="{{ $row['reviews_average_rating'] ?? -1 }}">
                                            {{ $row['reviews_average_rating'] === null ? '-' : number_format($row['reviews_average_rating'], 2, ',', '.') }}
                                        </td>
                                        <td class="num" data-sort-value="{{ $row['reviews_commission_amount'] }}">
                                            {{ $row['reviews_commission_amount'] > 0 ? '+' : '' }}{{ number_format($row['reviews_commission_amount'], 2, ',', '.') }}
                                        </td>
                                        <td class="num" data-sort-value="{{ $row['financing_profitability_percentage'] }}">{{ number_format($row['financing_profitability_percentage'], 2, ',', '.') }}%</td>
                                        <td class="num" data-sort-value="{{ $row['profitability_bonus_amount'] }}">
                                            {{ $row['profitability_bonus_percent'] > 0 ? '+' : '' }}{{ number_format($row['profitability_bonus_amount'], 2, ',', '.') }}
                                        </td>
                                        <td class="num" data-sort-value="{{ $row['financed_amount_percentage'] }}">{{ number_format($row['financed_amount_percentage'], 2, ',', '.') }}%</td>
                                        <td class="num" data-sort-value="{{ $row['financed_amount_bonus_amount'] }}">
                                            {{ $row['financed_amount_bonus_percent'] > 0 ? '+' : '' }}{{ number_format($row['financed_amount_bonus_amount'], 2, ',', '.') }}
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                            </div>
                        </div>
                    </div>

                    <div @class(['is-hidden' => $activeCommissionTab !== 'summary']) data-commission-tab-panel="summary">
                        @if ($activeCommissionTab === 'summary')
                        <section class="panel-title compact commission-commercial-detail-header">
                            <div>
                                <h2>Detalle por comercial</h2>
                                <div class="small">Selecciona un comercial para auditar entregas, compras, compartidas, stock y resenas debajo del resumen global.</div>
                            </div>
                        </section>
                        <div class="commission-detail-shell" data-agent-browser>
                            <aside class="card commission-commercial-picker">
                                <div class="filter-group">
                                    <label for="commissionCommercialSearch">Buscar comercial</label>
                                    <input
                                        type="search"
                                        id="commissionCommercialSearch"
                                        placeholder="Nombre o ID de Salesforce"
                                        autocomplete="off"
                                        data-agent-search-input
                                    >
                                </div>
                                <div class="commission-commercial-list">
                                    @foreach ($summaryRows as $row)
                                        <button
                                            type="button"
                                            class="commission-commercial-option{{ $row['commercial_id'] === $defaultCommercialId ? ' is-active' : '' }}"
                                            data-agent-option
                                            data-agent-id="{{ $row['commercial_id'] }}"
                                            data-agent-search="{{ \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii($row['commercial_name'].' '.$row['commercial_id'])) }}"
                                        >
                                            <strong>{{ $row['commercial_name'] }}</strong>
                                            <span>{{ $row['commercial_id'] }}</span>
                                            <small>
                                                Entregas {{ number_format($row['deliveries_count'], 0, ',', '.') }}
                                                · Comision {{ number_format($row['final_commission'], 2, ',', '.') }} EUR
                                            </small>
                                        </button>
                                    @endforeach
                                </div>
                            </aside>

                            <section class="commission-detail-stage">
                                <div class="empty-state is-hidden" data-agent-empty>
                                    No hay comerciales que coincidan con la busqueda.
                                </div>

                                @foreach ($summaryRows as $row)
                                    @php
                                        $isMonthlyOperationCommission = array_key_exists('commission_mode', $row);
                                        $isAppraiserCommission = (bool) ($row['is_appraiser'] ?? false);
                                        $deliveriesDetails = $isMonthlyOperationCommission
                                            ? ($row['details']['operations'] ?? [])
                                            : ($row['details']['deliveries'] ?? []);
                                        $purchasesDetails = $row['details']['purchases'] ?? [];
                                        $sharedDetails = $row['details']['shared'] ?? [];
                                        $stockDetails = $row['details']['stock_150'] ?? [];
                                        $reviewsDetails = $row['details']['reviews'] ?? [];
                                        $financingCancellationDetails = $row['details']['financing_cancellations'] ?? [];
                                        $appraiserSalesDetails = $row['details']['appraiser_sales'] ?? [];
                                        $appraiserFinancingDetails = $row['details']['appraiser_financing'] ?? [];
                                        $appraiserSpeedDetails = $row['details']['appraiser_speed'] ?? [];
                                    @endphp
                                    <article
                                        class="commission-commercial-panel{{ $row['commercial_id'] === $defaultCommercialId ? ' is-active' : ' is-hidden' }}"
                                        data-agent-panel
                                        data-agent-id="{{ $row['commercial_id'] }}"
                                    >
                                        <section class="card panel commission-commercial-hero">
                                            <div class="panel-title compact">
                                                <div>
                                                    <h2>{{ $row['commercial_name'] }}</h2>
                                                    <div class="small">ID {{ $row['commercial_id'] }} · Comision final {{ number_format($row['final_commission'], 2, ',', '.') }} EUR</div>
                                                </div>
                                            </div>
                                            <div class="campaign-context-grid commission-commercial-metrics">
                                                <article class="card campaign-context-card">
                                                    <span>Entregas</span>
                                                    <strong>{{ number_format($row['deliveries_count'], 0, ',', '.') }}</strong>
                                                </article>
                                                <article class="card campaign-context-card">
                                                    <span>Prima ajustada</span>
                                                    <strong>{{ number_format($row['prima_adjusted'], 2, ',', '.') }} EUR</strong>
                                                </article>
                                                <article class="card campaign-context-card">
                                                    <span>Penalizaciones</span>
                                                    <strong class="commission-penalty-text">{{ number_format($row['total_penalties'], 2, ',', '.') }} EUR</strong>
                                                </article>
                                                <article class="card campaign-context-card">
                                                    <span>Pen. cancelacion fin.</span>
                                                    <strong class="commission-penalty-text">{{ number_format($row['financing_cancellation_penalty_amount'] ?? 0, 2, ',', '.') }} EUR</strong>
                                                </article>
                                                <article class="card campaign-context-card">
                                                    <span>Prod. financiacion</span>
                                                    <strong>{{ number_format($row['financing_product_amount'], 2, ',', '.') }} EUR</strong>
                                                </article>
                                            </div>
                                            <nav class="tabs-main commission-detail-tabs" aria-label="Detalle del comercial">
                                                @if ($isAppraiserCommission)
                                                    <button type="button" class="main-tab active" data-commercial-detail-tab-trigger="purchases">Compras tasador</button>
                                                    <button type="button" class="main-tab" data-commercial-detail-tab-trigger="appraiser-sales">Ventas tasador</button>
                                                    <button type="button" class="main-tab" data-commercial-detail-tab-trigger="appraiser-financing">Financiacion 3%</button>
                                                    <button type="button" class="main-tab" data-commercial-detail-tab-trigger="appraiser-speed">Rapidez venta</button>
                                                @else
                                                    <button type="button" class="main-tab active" data-commercial-detail-tab-trigger="{{ $isMonthlyOperationCommission ? 'operations' : 'deliveries' }}">{{ $isMonthlyOperationCommission ? 'Operaciones' : 'Entregas' }}</button>
                                                    <button type="button" class="main-tab" data-commercial-detail-tab-trigger="purchases">{{ $isMonthlyOperationCommission ? 'Tasaciones y cambios' : 'Compras cobradas' }}</button>
                                                    <button type="button" class="main-tab" data-commercial-detail-tab-trigger="shared">Compartidas</button>
                                                    <button type="button" class="main-tab" data-commercial-detail-tab-trigger="stock">{{ $stockLabel }}</button>
                                                    <button type="button" class="main-tab" data-commercial-detail-tab-trigger="reviews">Resenas</button>
                                                @endif
                                                <button type="button" class="main-tab" data-commercial-detail-tab-trigger="financing-cancellations">Cancelaciones financiacion</button>
                                            </nav>
                                        </section>

                                        <section class="commission-detail-grid">
                                            @if ($isMonthlyOperationCommission && ! $isAppraiserCommission)
                                                <div class="table-shell" data-commercial-detail-tab-panel="operations">
                                                    <table data-sortable-table="operations-{{ $row['commercial_id'] }}">
                                                        <thead>
                                                        <tr><th colspan="7">Operaciones del mes</th></tr>
                                                        <tr><th data-sortable="true">ID</th><th data-sortable="true">Oportunidad</th><th data-sortable="true">Tipo</th><th data-sortable="true">Fecha</th><th data-sortable="true">Matricula</th><th data-sortable="true">Motivo</th><th class="num" data-sortable="true">Comision</th></tr>
                                                        </thead>
                                                        <tbody data-sort-body="operations-{{ $row['commercial_id'] }}">
                                                        @forelse ($deliveriesDetails as $detail)
                                                            <tr><td>{{ $detail['opportunity_id'] }}</td><td>{{ $detail['opportunity_name'] }}</td><td>{{ $detail['record_type_name'] }}</td><td>{{ $detail['cv_signed_date'] }}</td><td>{{ $detail['vehicle_plate'] ?: '-' }}</td><td>{{ $detail['reason'] }}</td><td class="num">{{ number_format($detail['commission_amount'], 2, ',', '.') }}</td></tr>
                                                        @empty
                                                            <tr><td colspan="7">Sin operaciones comisionables.</td></tr>
                                                        @endforelse
                                                        </tbody>
                                                    </table>
                                                </div>

                                                <div class="table-shell is-hidden" data-commercial-detail-tab-panel="purchases">
                                                    <table data-sortable-table="monthly-purchases-{{ $row['commercial_id'] }}">
                                                        <thead>
                                                        <tr><th colspan="7">Tasaciones y cambios del mes</th></tr>
                                                        <tr><th data-sortable="true">ID</th><th data-sortable="true">Oportunidad</th><th data-sortable="true">Tipo</th><th data-sortable="true">Fecha</th><th data-sortable="true">Matricula</th><th data-sortable="true">Motivo</th><th class="num" data-sortable="true">Comision</th></tr>
                                                        </thead>
                                                        <tbody data-sort-body="monthly-purchases-{{ $row['commercial_id'] }}">
                                                        @forelse ($purchasesDetails as $detail)
                                                            <tr><td>{{ $detail['opportunity_id'] }}</td><td>{{ $detail['opportunity_name'] }}</td><td>{{ $detail['record_type_name'] }}</td><td>{{ $detail['cv_signed_date'] }}</td><td>{{ $detail['vehicle_plate'] ?: '-' }}</td><td>{{ $detail['reason'] }}</td><td class="num">{{ number_format($detail['commission_amount'], 2, ',', '.') }}</td></tr>
                                                        @empty
                                                            <tr><td colspan="7">Sin tasaciones ni cambios comisionables.</td></tr>
                                                        @endforelse
                                                        </tbody>
                                                    </table>
                                                </div>
                                            @endif

                                            @if ($isAppraiserCommission)
                                                <div class="table-shell" data-commercial-detail-tab-panel="purchases">
                                                    <table data-sortable-table="appraiser-purchases-{{ $row['commercial_id'] }}">
                                                        <thead>
                                                        <tr><th colspan="8">Compras tasador</th></tr>
                                                        <tr><th data-sortable="true">ID</th><th data-sortable="true">Oportunidad</th><th data-sortable="true">Tipo</th><th data-sortable="true">Fecha compra</th><th data-sortable="true">Matricula</th><th data-sortable="true">Tramo</th><th class="num" data-sortable="true">EUR/op.</th><th class="num" data-sortable="true">Comision</th></tr>
                                                        </thead>
                                                        <tbody data-sort-body="appraiser-purchases-{{ $row['commercial_id'] }}">
                                                        @forelse ($purchasesDetails as $detail)
                                                            <tr><td>{{ $detail['opportunity_id'] }}</td><td>{{ $detail['opportunity_name'] }}</td><td>{{ $detail['record_type_name'] }}</td><td>{{ $detail['cv_signed_date'] }}</td><td>{{ $detail['vehicle_plate'] ?: '-' }}</td><td>{{ $detail['tier_label'] }}</td><td class="num">{{ number_format($detail['rate_per_operation'], 2, ',', '.') }}</td><td class="num">{{ number_format($detail['commission_amount'], 2, ',', '.') }}</td></tr>
                                                        @empty
                                                            <tr><td colspan="8">Sin compras tasador en el mes.</td></tr>
                                                        @endforelse
                                                        </tbody>
                                                    </table>
                                                </div>

                                                <div class="table-shell is-hidden" data-commercial-detail-tab-panel="appraiser-sales">
                                                    <table data-sortable-table="appraiser-sales-{{ $row['commercial_id'] }}">
                                                        <thead><tr><th colspan="6">Ventas tasador</th></tr><tr><th data-sortable="true">ID</th><th data-sortable="true">Oportunidad</th><th data-sortable="true">Fecha</th><th data-sortable="true">Matricula</th><th data-sortable="true">Motivo</th><th class="num" data-sortable="true">Comision</th></tr></thead>
                                                        <tbody data-sort-body="appraiser-sales-{{ $row['commercial_id'] }}">
                                                        @forelse ($appraiserSalesDetails as $detail)
                                                            <tr><td>{{ $detail['opportunity_id'] }}</td><td>{{ $detail['opportunity_name'] }}</td><td>{{ $detail['cv_signed_date'] }}</td><td>{{ $detail['vehicle_plate'] ?: '-' }}</td><td>{{ $detail['reason'] }}</td><td class="num">{{ number_format($detail['commission_amount'], 2, ',', '.') }}</td></tr>
                                                        @empty
                                                            <tr><td colspan="6">Sin ventas tasador en el mes.</td></tr>
                                                        @endforelse
                                                        </tbody>
                                                    </table>
                                                </div>

                                                <div class="table-shell is-hidden" data-commercial-detail-tab-panel="appraiser-financing">
                                                    <table data-sortable-table="appraiser-financing-{{ $row['commercial_id'] }}">
                                                        <thead><tr><th colspan="5">Financiacion tasador al 3%</th></tr><tr><th data-sortable="true">ID</th><th data-sortable="true">Oportunidad</th><th data-sortable="true">Fecha</th><th class="num" data-sortable="true">Beneficio</th><th class="num" data-sortable="true">Comision 3%</th></tr></thead>
                                                        <tbody data-sort-body="appraiser-financing-{{ $row['commercial_id'] }}">
                                                        @forelse ($appraiserFinancingDetails as $detail)
                                                            <tr><td>{{ $detail['opportunity_id'] }}</td><td>{{ $detail['opportunity_name'] }}</td><td>{{ $detail['cv_signed_date'] }}</td><td class="num">{{ number_format($detail['financing_benefit'], 2, ',', '.') }}</td><td class="num">{{ number_format($detail['commission_amount'], 2, ',', '.') }}</td></tr>
                                                        @empty
                                                            <tr><td colspan="5">Sin financiacion comisionable.</td></tr>
                                                        @endforelse
                                                        </tbody>
                                                    </table>
                                                </div>

                                                <div class="table-shell is-hidden" data-commercial-detail-tab-panel="appraiser-speed">
                                                    <table data-sortable-table="appraiser-speed-{{ $row['commercial_id'] }}">
                                                        <thead><tr><th colspan="8">Incentivo rapidez de venta</th></tr><tr><th data-sortable="true">Matricula</th><th data-sortable="true">Compra</th><th data-sortable="true">Fecha compra</th><th data-sortable="true">Venta posterior</th><th data-sortable="true">Fecha venta</th><th class="num" data-sortable="true">Dias</th><th data-sortable="true">Tramo</th><th class="num" data-sortable="true">Incentivo</th></tr></thead>
                                                        <tbody data-sort-body="appraiser-speed-{{ $row['commercial_id'] }}">
                                                        @forelse ($appraiserSpeedDetails as $detail)
                                                            <tr><td>{{ $detail['vehicle_plate'] ?: '-' }}</td><td>{{ $detail['purchase_opportunity_name'] }}</td><td>{{ $detail['purchase_date'] }}</td><td>{{ $detail['sale_opportunity_name'] ?: '-' }}</td><td>{{ $detail['sale_date'] ?: '-' }}</td><td class="num">{{ $detail['days_to_sale'] ?? '-' }}</td><td>{{ $detail['incentive_band'] }}</td><td class="num">{{ number_format($detail['commission_amount'], 2, ',', '.') }}</td></tr>
                                                        @empty
                                                            <tr><td colspan="8">Sin compras para auditar rapidez.</td></tr>
                                                        @endforelse
                                                        </tbody>
                                                    </table>
                                                </div>
                                            @endif

                                            <div class="table-shell is-hidden" data-commercial-detail-tab-panel="financing-cancellations">
                                                <table data-sortable-table="financing-cancellations-{{ $row['commercial_id'] }}">
                                                    <thead>
                                                    <tr><th colspan="6">Cancelaciones anticipadas de financiacion</th></tr>
                                                    <tr><th>Comercial</th><th>Email</th><th>ID Salesforce</th><th>Hoja</th><th class="num">Fila</th><th class="num">Penalizacion</th></tr>
                                                    </thead>
                                                    <tbody data-sort-body="financing-cancellations-{{ $row['commercial_id'] }}">
                                                    @forelse ($financingCancellationDetails as $detail)
                                                        <tr>
                                                            <td>{{ $detail['commercial_name'] }}</td>
                                                            <td>{{ $detail['commercial_email'] }}</td>
                                                            <td>{{ $detail['commercial_id'] }}</td>
                                                            <td>{{ $detail['source_sheet'] ?: '-' }}</td>
                                                            <td class="num">{{ $detail['source_row'] ?: '-' }}</td>
                                                            <td class="num commission-penalty-text">{{ number_format($detail['amount'], 2, ',', '.') }}</td>
                                                        </tr>
                                                    @empty
                                                        <tr><td colspan="6">Sin cancelaciones anticipadas cargadas para este comercial.</td></tr>
                                                    @endforelse
                                                    </tbody>
                                                </table>
                                            </div>

                                            @if (! $isMonthlyOperationCommission)
                                            <div class="table-shell" data-commercial-detail-tab-panel="deliveries">
                                                <table data-sortable-table="deliveries-{{ $row['commercial_id'] }}">
                                                    <thead>
                                                    <tr><th colspan="6">Entregas · {{ number_format(count($deliveriesDetails), 0, ',', '.') }}</th></tr>
                                                    <tr>
                                                        <th data-sortable="true">ID</th>
                                                        <th data-sortable="true">Oportunidad</th>
                                                        <th data-sortable="true">Tipo</th>
                                                        <th data-sortable="true">Fecha</th>
                                                        <th data-sortable="true">Matricula</th>
                                                        <th class="num" data-sortable="true">Importe</th>
                                                    </tr>
                                                    </thead>
                                                    <tbody data-sort-body="deliveries-{{ $row['commercial_id'] }}">
                                                    @forelse ($deliveriesDetails as $detail)
                                                        <tr>
                                                            <td>{{ $detail['opportunity_id'] }}</td>
                                                            <td>{{ $detail['opportunity_name'] }}</td>
                                                            <td>{{ $detail['record_type_name'] }}</td>
                                                            <td>{{ $detail['cv_signed_date'] }}</td>
                                                            <td>{{ $detail['vehicle_plate'] ?: '-' }}</td>
                                                            <td class="num">{{ number_format($detail['amount'], 2, ',', '.') }}</td>
                                                        </tr>
                                                    @empty
                                                        <tr><td colspan="6">Sin entregas.</td></tr>
                                                    @endforelse
                                                    </tbody>
                                                </table>
                                            </div>

                                            <div class="table-shell is-hidden" data-commercial-detail-tab-panel="purchases">
                                                <table data-sortable-table="purchases-{{ $row['commercial_id'] }}">
                                                    <thead>
                                                    <tr><th colspan="7">Compras cobradas este mes · {{ number_format(count($purchasesDetails), 0, ',', '.') }}</th></tr>
                                                    <tr>
                                                        <th data-sortable="true">Matricula</th>
                                                        <th data-sortable="true">Compra</th>
                                                        <th data-sortable="true">Tipo compra</th>
                                                        <th data-sortable="true">Fecha compra</th>
                                                        <th data-sortable="true">Venta posterior</th>
                                                        <th class="num" data-sortable="true">Rentabilidad</th>
                                                        <th class="num" data-sortable="true">Comision</th>
                                                    </tr>
                                                    </thead>
                                                    <tbody data-sort-body="purchases-{{ $row['commercial_id'] }}">
                                                    @forelse ($purchasesDetails as $detail)
                                                        <tr>
                                                            <td>{{ $detail['vehicle_plate'] }}</td>
                                                            <td>{{ $detail['purchase_opportunity_name'] }}</td>
                                                            <td>{{ $detail['purchase_record_type_name'] }}</td>
                                                            <td>{{ $detail['purchase_date'] }}</td>
                                                            <td>{{ $detail['sale_opportunity_name'] }}</td>
                                                            <td class="num">{{ number_format($detail['rentability_amount'], 2, ',', '.') }}</td>
                                                            <td class="num">{{ number_format($detail['commission_amount'], 2, ',', '.') }}</td>
                                                        </tr>
                                                    @empty
                                                        <tr><td colspan="7">Sin compras liquidadas en el mes.</td></tr>
                                                    @endforelse
                                                    </tbody>
                                                </table>
                                            </div>
                                            @endif

                                            <div class="table-shell is-hidden" data-commercial-detail-tab-panel="shared">
                                                <table data-sortable-table="shared-{{ $row['commercial_id'] }}">
                                                    <thead>
                                                    <tr><th colspan="5">Compartidas · {{ number_format(count($sharedDetails), 0, ',', '.') }}</th></tr>
                                                    <tr>
                                                        <th data-sortable="true">ID</th>
                                                        <th data-sortable="true">Oportunidad</th>
                                                        <th data-sortable="true">Propietario</th>
                                                        <th data-sortable="true">Fecha</th>
                                                        <th class="num" data-sortable="true">Importe</th>
                                                    </tr>
                                                    </thead>
                                                    <tbody data-sort-body="shared-{{ $row['commercial_id'] }}">
                                                    @forelse ($sharedDetails as $detail)
                                                        <tr>
                                                            <td>{{ $detail['opportunity_id'] }}</td>
                                                            <td>{{ $detail['opportunity_name'] }}</td>
                                                            <td>{{ $detail['owner_name'] }}</td>
                                                            <td>{{ $detail['cv_signed_date'] }}</td>
                                                            <td class="num">{{ number_format($detail['amount'], 2, ',', '.') }}</td>
                                                        </tr>
                                                    @empty
                                                        <tr><td colspan="5">Sin compartidas.</td></tr>
                                                    @endforelse
                                                    </tbody>
                                                </table>
                                            </div>

                                            <div class="table-shell is-hidden" data-commercial-detail-tab-panel="stock">
                                                <table data-sortable-table="stock-{{ $row['commercial_id'] }}">
                                                    <thead>
                                                    <tr><th colspan="6">{{ $stockLabel }} · {{ number_format(count($stockDetails), 0, ',', '.') }}</th></tr>
                                                    <tr>
                                                        <th data-sortable="true">ID</th>
                                                        <th data-sortable="true">Oportunidad</th>
                                                        <th data-sortable="true">Matricula</th>
                                                        <th data-sortable="true">Fecha entrada</th>
                                                        <th class="num" data-sortable="true">Dias</th>
                                                        <th class="num" data-sortable="true">Importe</th>
                                                    </tr>
                                                    </thead>
                                                    <tbody data-sort-body="stock-{{ $row['commercial_id'] }}">
                                                    @forelse ($stockDetails as $detail)
                                                        <tr>
                                                            <td>{{ $detail['opportunity_id'] }}</td>
                                                            <td>{{ $detail['opportunity_name'] }}</td>
                                                            <td>{{ $detail['vehicle_plate'] }}</td>
                                                            <td>{{ $detail['vehicle_entry_date'] }}</td>
                                                            <td class="num">{{ number_format($detail['vehicle_days_in_stock'], 0, ',', '.') }}</td>
                                                            <td class="num">{{ number_format($detail['amount'], 2, ',', '.') }}</td>
                                                        </tr>
                                                    @empty
                                                        <tr><td colspan="6">Sin vehiculos +150.</td></tr>
                                                    @endforelse
                                                    </tbody>
                                                </table>
                                            </div>

                                            <div class="table-shell commission-reviews-table is-hidden" data-commercial-detail-tab-panel="reviews">
                                                <table data-sortable-table="reviews-{{ $row['commercial_id'] }}">
                                                    <thead>
                                                    <tr><th colspan="5">Resenas del mes · {{ number_format(count($reviewsDetails), 0, ',', '.') }}</th></tr>
                                                    <tr>
                                                        <th data-sortable="true">ID resena</th>
                                                        <th data-sortable="true">Fecha</th>
                                                        <th data-sortable="true">Oportunidad</th>
                                                        <th data-sortable="true">Propietario oportunidad</th>
                                                        <th data-sortable="true">Propietario resena</th>
                                                    </tr>
                                                    </thead>
                                                    <tbody data-sort-body="reviews-{{ $row['commercial_id'] }}">
                                                    @forelse ($reviewsDetails as $detail)
                                                        <tr>
                                                            <td>{{ $detail['review_id'] }}</td>
                                                            <td>{{ $detail['created_date'] }}</td>
                                                            <td>{{ $detail['opportunity_name'] ?: $detail['opportunity_id'] }}</td>
                                                            <td>{{ $detail['opportunity_owner_name'] }}</td>
                                                            <td>{{ $detail['review_owner_name'] }}</td>
                                                        </tr>
                                                    @empty
                                                        <tr><td colspan="5">Sin resenas en el mes.</td></tr>
                                                    @endforelse
                                                    </tbody>
                                                </table>
                                            </div>
                                        </section>
                                    </article>
                                @endforeach
                            </section>
                        </div>
                        @endif
                    </div>

                    @if ($canViewCallContactDetails ?? false)
                    <div @class(['is-hidden' => $activeCommissionTab !== 'call-center']) data-commission-tab-panel="call-center">
                        <section class="card panel">
                            <div class="panel-title compact">
                                <div>
                                    <h2>Comisiones Call Center</h2>
                                    <div class="small">Compras, ventas, cambios, negociaciones German y Facilitea auditables desde la misma pantalla mensual.</div>
                                </div>
                            </div>

                            @foreach ($callCenterDashboard['issues'] ?? [] as $issue)
                                <div class="notice">{{ $issue }}</div>
                            @endforeach

                            @foreach ($callCenterDashboard['warnings'] ?? [] as $warning)
                                <div class="notice commission-warning">{{ $warning }}</div>
                            @endforeach

                            @include('reports.commercial-commissions.partials.call-center-tab')
                        </section>
                    </div>

                    <div @class(['is-hidden' => $activeCommissionTab !== 'contact-center']) data-commission-tab-panel="contact-center">
                        <section class="card panel">
                            <div class="panel-title compact">
                                <div>
                                    <h2>Comisiones Contact Center</h2>
                                    <div class="small">Citas, oportunidades, reservas y ventas imputadas por captador de cita en el mes cerrado.</div>
                                </div>
                            </div>

                            @foreach ($contactCenterDashboard['issues'] ?? [] as $issue)
                                <div class="notice">{{ $issue }}</div>
                            @endforeach

                            @foreach ($contactCenterDashboard['warnings'] ?? [] as $warning)
                                <div class="notice commission-warning">{{ $warning }}</div>
                            @endforeach

                            @include('reports.commercial-commissions.partials.contact-center-tab')
                        </section>
                    </div>
                    @endif

                    <div @class(['is-hidden' => $activeCommissionTab !== 'area-manager']) data-commission-tab-panel="area-manager">
                        <section class="card panel">
                            <div class="panel-title compact">
                                <div>
                                    <h2>Comisiones Area Manager</h2>
                                    <div class="small">KPIs por delegacion, llave de zona y detalle auditable por manager.</div>
                                </div>
                            </div>

                            @foreach ($areaManagerDashboard['issues'] ?? [] as $issue)
                                <div class="notice">{{ $issue }}</div>
                            @endforeach

                            @foreach ($areaManagerDashboard['warnings'] ?? [] as $warning)
                                <div class="notice commission-warning">{{ $warning }}</div>
                            @endforeach

                            @include('reports.commercial-commissions.partials.area-manager-tab')
                        </section>
                    </div>

                    <div @class(['is-hidden' => $activeCommissionTab !== 'financials']) data-commission-tab-panel="financials">
                        <section class="card panel">
                            <div class="panel-title compact">
                                <div>
                                    <h2>Comisiones Financieros</h2>
                                    <div class="small">Responsables resueltos por `zona_financiera__c` y detalle auditable por delegacion.</div>
                                </div>
                            </div>

                            @foreach ($financialDashboard['issues'] ?? [] as $issue)
                                <div class="notice">{{ $issue }}</div>
                            @endforeach

                            @foreach ($financialDashboard['warnings'] ?? [] as $warning)
                                <div class="notice commission-warning">{{ $warning }}</div>
                            @endforeach

                            @include('reports.commercial-commissions.partials.financial-tab')
                        </section>
                    </div>
            </section>
        </section>
    </main>
</div>
</x-reports.app-shell>
