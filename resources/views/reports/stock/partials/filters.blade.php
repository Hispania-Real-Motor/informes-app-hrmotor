<details class="card panel stock-filter-panel" open>
    <summary>
        <span>
            <strong>Filtros del análisis</strong>
            <small>Se aplican al stock actual y a las ventas del periodo siempre que el dato esté disponible.</small>
        </span>
        <span class="stock-filter-summary">Mostrar / ocultar</span>
    </summary>
    <form method="GET" action="{{ route('reports.stock.index') }}" class="stock-filters">
        <input type="hidden" name="section" value="{{ $activeStockTab }}">
        <div class="filter-group">
            <label for="stock_date_from">Desde</label>
            <input id="stock_date_from" name="date_from" type="date" value="{{ $filters['date_from'] }}">
        </div>
        <div class="filter-group">
            <label for="stock_date_to">Hasta</label>
            <input id="stock_date_to" name="date_to" type="date" value="{{ $filters['date_to'] }}">
        </div>
        @foreach ([
            'delegation' => ['Delegación', $filterOptions['delegations']],
            'zone' => ['Zona', $filterOptions['zones']],
            'brand' => ['Marca', $filterOptions['brands']],
            'model' => ['Modelo', $filterOptions['models']],
            'segment' => ['Segmento', $filterOptions['segments']],
            'body' => ['Carrocería', $filterOptions['bodies']],
            'fuel' => ['Combustible', $filterOptions['fuels']],
            'price_band' => ['Tramo de precio', $filterOptions['price_bands']],
            'state' => ['Estado', $filterOptions['states']],
            'buyer' => ['Responsable de compra', $filterOptions['buyers']],
            'purchase_source' => ['Procedencia de compra', $filterOptions['purchase_sources']],
        ] as $name => [$label, $options])
            <div class="filter-group">
                <label for="stock_{{ $name }}">{{ $label }}</label>
                <select id="stock_{{ $name }}" name="{{ $name }}">
                    <option value="">Todos</option>
                    @foreach ($options as $option)
                        <option value="{{ $option }}" @selected($filters[$name] === $option)>{{ $option }}</option>
                    @endforeach
                </select>
            </div>
        @endforeach
        @foreach ([
            'price_min' => ['Precio mínimo', '€'],
            'price_max' => ['Precio máximo', '€'],
            'mileage_min' => ['Km mínimos', 'km'],
            'mileage_max' => ['Km máximos', 'km'],
            'days_min' => ['Días mínimos', 'días'],
            'days_max' => ['Días máximos', 'días'],
        ] as $name => [$label, $unit])
            <div class="filter-group">
                <label for="stock_{{ $name }}">{{ $label }}</label>
                <div class="stock-input-unit">
                    <input id="stock_{{ $name }}" name="{{ $name }}" type="number" min="0" value="{{ $filters[$name] }}">
                    <span>{{ $unit }}</span>
                </div>
            </div>
        @endforeach
        <div class="stock-filter-actions">
            <div class="stock-period-buttons">
                <button type="button" data-period-days="30">30 días</button>
                <button type="button" data-period-days="120">120 días</button>
                <button type="button" data-current-month>Mes actual</button>
                <a class="secondary-button" href="{{ route('reports.stock.index', ['section' => $activeStockTab, 'date_from' => '2026-10-01', 'date_to' => '2026-10-31']) }}">Octubre 2026</a>
            </div>
            <a class="secondary-button" href="{{ route('reports.stock.index', ['section' => $activeStockTab]) }}">Limpiar</a>
            <button class="main-tab active" type="submit">Aplicar filtros</button>
        </div>
    </form>
</details>
<script type="application/json" id="stockGeneralModelsByBrand">@json($filterOptions['models_by_brand'] ?? [])</script>
