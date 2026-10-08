@php
    $currentReport = $currentReport ?? 'leads';
    $currentAdminPage = $currentAdminPage ?? null;
    $updatedBadgeText = $updatedBadgeText ?? 'Cargando fotografía local...';
    $adminLinks = [
        ['key' => 'operational-alerts', 'label' => 'Alertas', 'route' => 'reports.operational-alerts.index'],
        ['key' => 'users', 'label' => 'Usuarios', 'route' => 'reports.users.index'],
        ['key' => 'access-settings', 'label' => 'Permisos', 'route' => 'reports.access-settings.index'],
        ['key' => 'commission-settings', 'label' => 'Coeficientes', 'route' => 'reports.commission-settings.index'],
    ];
    $tabs = [
        ['key' => 'leads', 'label' => 'Intereses', 'subtitle' => 'Captacion y seguimiento comercial', 'route' => 'reports.leads.index'],
        ['key' => 'reservations-sales', 'label' => 'Reservas / Ventas', 'subtitle' => 'Reservas, ventas y contratos', 'route' => 'reports.reservations-sales.index'],
        ['key' => 'calls', 'label' => 'Llamadas', 'subtitle' => 'Actividad telefonica y atencion', 'route' => 'reports.calls.index'],
        ['key' => 'campaigns', 'label' => 'Campañas', 'subtitle' => 'Rentabilidad digital', 'route' => 'reports.campaigns.index'],
        ['key' => 'commercial-commissions', 'label' => 'Comisiones Comerciales', 'subtitle' => 'Calculo mensual por comercial', 'route' => 'reports.commercial-commissions.index'],
        ['key' => 'stock', 'label' => 'Stock', 'subtitle' => 'Vehiculos, capacidad y rotacion', 'route' => 'reports.stock.index'],
    ];

    $visibleTabs = array_values(array_filter($tabs, function (array $tab): bool {
        if (! \Illuminate\Support\Facades\Route::has($tab['route'])) {
            return false;
        }

        return \App\Support\ReportUserAccess::canViewReport(request(), $tab['key']);
    }));
    $reportUserDisplayName = \App\Support\ReportUserAccess::displayName(request());
@endphp

<header class="app-header">
    <div class="header-actions">
        @if (filled($reportUserDisplayName))
            <div class="header-user-greeting">Hola {{ $reportUserDisplayName }}</div>
        @endif
        <div class="badge" id="updatedBadge">{{ $updatedBadgeText }}</div>
        @if (\App\Support\ReportUserAccess::canManageReportUsers(request()))
            @foreach ($adminLinks as $link)
                @if (\Illuminate\Support\Facades\Route::has($link['route']))
                    <a
                        href="{{ route($link['route']) }}"
                        @class(['header-link', 'active' => $currentAdminPage === $link['key']])
                    >
                        {{ $link['label'] }}
                    </a>
                @endif
            @endforeach
        @endif
        @if (\App\Support\ReportUserAccess::canManageFinancingPenalties(request()) && ! \App\Support\ReportUserAccess::canManageReportUsers(request()))
            <a href="{{ route('reports.commission-penalties.index') }}" class="header-link">Penalizaciones</a>
        @endif
        <form method="POST" action="{{ route('logout') }}" class="logout-form">
                @csrf
                <button type="submit" class="logout-button">Cerrar sesión</button>
        </form>
    </div>
</header>

<nav class="report-switch" aria-label="Informes comerciales">
    @foreach ($visibleTabs as $tab)
        <a
            href="{{ route($tab['route']) }}"
            data-report-tooltip="{{ $tab['subtitle'] }}"
            aria-label="{{ $tab['label'] }}: {{ $tab['subtitle'] }}"
            @class(['active' => $currentReport === $tab['key']])
        >
            <strong>{{ $tab['label'] }}</strong>
        </a>
    @endforeach
</nav>
