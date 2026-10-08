@php
    $canView = static fn (string $key): bool => in_array($key, $accessibleReportKeys, true);
    $isReportActive = static fn (string $key): bool => $currentReport === $key;
    $isAdminActive = static fn (string $key): bool => $currentAdminPage === $key;

    $commercialLinks = array_values(array_filter([
        $canView('leads') ? ['key' => 'leads', 'label' => 'Intereses', 'route' => 'reports.leads.index', 'icon' => 'leads'] : null,
        $canView('reservations-sales') ? ['key' => 'reservations-sales', 'label' => 'Reservas / Ventas', 'route' => 'reports.reservations-sales.index', 'icon' => 'sales'] : null,
        $canView('calls') ? ['key' => 'calls', 'label' => 'Llamadas', 'route' => 'reports.calls.index', 'icon' => 'calls'] : null,
    ]));
    $marketingLinks = array_values(array_filter([
        $canView('campaigns') ? ['key' => 'campaigns', 'label' => 'Campa'.mb_chr(241).'as', 'route' => 'reports.campaigns.index', 'icon' => 'campaigns'] : null,
        $canView('seo-analytics') ? ['key' => 'seo-analytics', 'label' => 'SEO y Analytics', 'route' => 'reports.seo-analytics.index', 'icon' => 'analytics'] : null,
    ]));
    $operationsLinks = array_values(array_filter([
        $canView('stock') ? ['key' => 'stock', 'label' => 'Stock', 'route' => 'reports.stock.index', 'icon' => 'stock'] : null,
    ]));
    $administrationLinks = [];

    if ($canManageAdministration) {
        $administrationLinks = [
            ['key' => 'operational-alerts', 'label' => 'Alertas', 'route' => 'reports.operational-alerts.index', 'icon' => 'alerts'],
            ['key' => 'users', 'label' => 'Usuarios', 'route' => 'reports.users.index', 'icon' => 'users'],
            ['key' => 'access-settings', 'label' => 'Permisos', 'route' => 'reports.access-settings.index', 'icon' => 'permissions'],
            ['key' => 'commission-settings', 'label' => 'Coeficientes', 'route' => 'reports.commission-settings.index', 'icon' => 'settings'],
            ['key' => 'commission-penalties', 'label' => 'Penalizaciones', 'route' => 'reports.commission-penalties.index', 'icon' => 'penalties'],
        ];
    } elseif ($canManageFinancingPenalties) {
        $administrationLinks = [
            ['key' => 'commission-penalties', 'label' => 'Penalizaciones', 'route' => 'reports.commission-penalties.index', 'icon' => 'penalties'],
        ];
    }
@endphp

<aside class="app-sidebar" id="app-sidebar" data-sidebar aria-label="Navegaci&oacute;n principal">
    <div class="app-sidebar-brand">
        <img src="/brand/logo-horizontal.svg" alt="HR Motor" width="184" height="48">
        <span>Informes</span>
        <button class="app-sidebar-close" type="button" data-sidebar-close aria-label="Cerrar navegaci&oacute;n">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>

    <nav class="app-navigation" aria-label="M&oacute;dulos de informes">
        @if ($canView('summary'))
            <a href="{{ route('reports.index') }}" @class(['app-nav-link', 'is-active' => $isReportActive('summary')]) @if ($isReportActive('summary')) aria-current="page" @endif>
                <x-reports.nav-icon name="summary" />
                <span>Resumen</span>
            </a>
        @endif

        @foreach ([
            ['label' => 'Comercial', 'links' => $commercialLinks],
            ['label' => 'Marketing', 'links' => $marketingLinks],
            ['label' => 'Operaciones', 'links' => $operationsLinks],
        ] as $group)
            @if ($group['links'] !== [])
                <section class="app-nav-group" aria-labelledby="nav-{{ \Illuminate\Support\Str::slug($group['label']) }}">
                    <h2 id="nav-{{ \Illuminate\Support\Str::slug($group['label']) }}">{{ $group['label'] }}</h2>
                    @foreach ($group['links'] as $link)
                        <a href="{{ route($link['route']) }}" @class(['app-nav-link', 'is-active' => $isReportActive($link['key'])]) @if ($isReportActive($link['key'])) aria-current="page" @endif>
                            <x-reports.nav-icon :name="$link['icon']" />
                            <span>{{ $link['label'] }}</span>
                        </a>
                    @endforeach
                </section>
            @endif
        @endforeach

        @if ($canView('commercial-commissions'))
            <a href="{{ route('reports.commercial-commissions.index') }}" @class(['app-nav-link', 'is-active' => $isReportActive('commercial-commissions')]) @if ($isReportActive('commercial-commissions')) aria-current="page" @endif>
                <x-reports.nav-icon name="commissions" />
                <span>Comisiones</span>
            </a>
        @endif

        @if ($administrationLinks !== [])
            <section class="app-nav-group app-nav-admin" aria-labelledby="nav-administration">
                <h2 id="nav-administration">Administraci&oacute;n</h2>
                @foreach ($administrationLinks as $link)
                    @if (\Illuminate\Support\Facades\Route::has($link['route']))
                        <a href="{{ route($link['route']) }}" @class(['app-nav-link', 'is-active' => $isAdminActive($link['key'])]) @if ($isAdminActive($link['key'])) aria-current="page" @endif>
                            <x-reports.nav-icon :name="$link['icon']" />
                            <span>{{ $link['label'] }}</span>
                        </a>
                    @endif
                @endforeach
            </section>
        @endif
    </nav>
</aside>
