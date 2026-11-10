<x-reports.app-shell
    title="Stock"
    current-report="stock"
    body-class="stock-report"
    :updated-badge-text="$latestSnapshotDate
        ? 'Fotograf'.mb_chr(237).'a de stock: '.\Carbon\CarbonImmutable::parse($latestSnapshotDate)->format('d/m/Y')
        : 'Sin fotograf'.mb_chr(237).'a diaria'"
>
    <x-slot:head>
    @vite([
        'resources/css/reports/leads-dashboard.css',
        'resources/css/reports/stock-dashboard.css',
        'resources/js/reports/stock-dashboard.js',
    ])
    </x-slot:head>
<div class="wrap">
    <main>
        <section class="header stock-title">
            <div class="stock-title-copy">
                <div class="eyebrow">Stock, ventas y logística</div>
                <h1>Análisis integral del stock</h1>
                <p class="sub">Situación actual, rendimiento por tienda, rotación y recomendaciones logísticas explicadas.</p>
            </div>
            <div class="stock-title-meta" aria-label="Contexto del informe">
                <span>{{ number_format($saleSnapshotsCount, 0, ',', '.') }} snapshots económicos</span>
                <span>{{ $stockHistory['days'] }} fotografías diarias</span>
                <span>Periodo ventas: {{ \Carbon\CarbonImmutable::parse($filters['date_from'])->format('d/m/Y') }}–{{ \Carbon\CarbonImmutable::parse($filters['date_to'])->format('d/m/Y') }}</span>
                <span>Fuente: fotografía local Salesforce</span>
                <span>Corte: {{ $stockDatasetCutoff ? \Carbon\CarbonImmutable::parse($stockDatasetCutoff)->format('d/m/Y H:i:s') : 'pendiente' }}</span>
                @if ($summary['sales_stock_approximate'])
                    <span class="stock-warning-pill">Ventas/stock: aproximación</span>
                @elseif ($summary['sales_stock_unavailable'] ?? false)
                    <span class="stock-warning-pill">Ventas/stock: sin datos suficientes del período</span>
                @endif
            </div>
        </section>

        @php
            $tabQuery = request()->except([
                'section',
                'transfer_plan',
                'transfer_origin_id',
                'transfer_destination_id',
                'transfer_units',
            ]);
        @endphp
        <nav class="tabs-main stock-section-nav" aria-label="Secciones de stock">
            @foreach ([
                'summary' => 'Resumen',
                'delegations' => 'Delegaciones y ventas',
                'recommendations' => 'Recomendaciones',
                'vehicles' => 'Vehículos',
            ] as $key => $label)
                <a @class(['main-tab', 'active' => $activeStockTab === $key])
                   href="{{ route('reports.stock.index', array_merge($tabQuery, ['section' => $key])) }}">{{ $label }}</a>
            @endforeach
            @if ($isAdmin)
                <a @class(['main-tab', 'active' => $activeStockTab === 'capacities'])
                   href="{{ route('reports.stock.index', ['section' => 'capacities']) }}">Capacidades</a>
            @endif
        </nav>

        @if (session('status'))
            <div class="notice notice-success">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="notice">{{ $errors->first() }}</div>
        @endif

        @unless ($activeStockTab === 'capacities')
            @include('reports.stock.partials.filters')
        @endunless

        @if ($activeStockTab === 'summary')
            @include('reports.stock.partials.summary')
        @elseif ($activeStockTab === 'delegations')
            @include('reports.stock.partials.delegations')
            @include('reports.stock.partials.rankings')
        @elseif ($activeStockTab === 'recommendations')
            @include('reports.stock.partials.recommendations')
        @elseif ($activeStockTab === 'vehicles')
            @include('reports.stock.partials.vehicles')
        @elseif ($isAdmin && $activeStockTab === 'capacities')
            @include('reports.stock.partials.capacities')
        @endif
    </main>
</div>
</x-reports.app-shell>
