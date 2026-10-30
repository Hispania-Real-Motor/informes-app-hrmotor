<x-reports.app-shell title="Coeficientes de comisiones" current-admin-page="commission-settings" body-class="campaigns-report report-users-page">
    <x-slot:head>
    @vite(['resources/css/reports/leads-dashboard.css'])
    </x-slot:head>
@php
    $selectedMonthKey = $selectedMonth->format('Y-m');
    $openMonthKey = $openMonth->format('Y-m');
    $financingLabels = [
        'Tramo 1 (> 50000)',
        'Tramo 2 (>= 30001)',
        'Tramo 3 (>= 25001)',
        'Tramo 4 (>= 17001)',
        'Tramo 5 (>= 12001)',
        'Tramo 6 (>= 8001)',
        'Tramo 7 (>= 5001)',
        'Tramo 8 (>= 1)',
    ];
    $guaranteeLabels = [
        'Tramo 1 (> 20400)',
        'Tramo 2 (>= 14401)',
        'Tramo 3 (>= 9601)',
        'Tramo 4 (>= 5401)',
        'Tramo 5 (>= 3501)',
        'Tramo 6 (>= 1)',
    ];
@endphp
<div class="wrap">
    <main>
        <section class="header">
            <div>
                <div class="eyebrow">Administracion</div>
                <h1>Coeficientes de comisiones</h1>
                <p class="sub">Solo el rol administrador puede editar coeficientes. El mes en curso permanece abierto y cualquier mes cerrado puede abrirse temporalmente para revision.</p>
            </div>
            <div class="filter-actions">
                <a class="main-tab" href="{{ route('reports.commission-settings.contact-center-roster.index') }}">Roster Contact Center</a>
            </div>
        </section>

        @if (session('status'))
            <div class="notice notice-success">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="notice">{{ $errors->first() }}</div>
        @endif

        <section class="filters card commission-settings-filter">
            <form method="GET" class="commission-settings-month-form">
                <div class="filter-group">
                    <label for="month">Mes a revisar</label>
                    <input type="month" id="month" name="month" value="{{ $selectedMonthKey }}">
                </div>
                <div class="filter-group">
                    <label>Mes abierto</label>
                    <input type="text" value="{{ $openMonthKey }}" disabled>
                </div>
                <div class="filter-group">
                    <label>Estado</label>
                    <input type="text" value="{{ $isEditableMonth ? 'Editable' : 'Cerrado' }}" disabled>
                </div>
                <div class="filter-actions commission-filter-actions">
                    <button type="submit" class="main-tab">Cargar configuracion</button>
                </div>
            </form>
            @if (! $isEditableMonth)
                <div class="commission-settings-status-block">
                    <div class="notice commission-settings-inline-notice">
                        El mes {{ $selectedMonthKey }} esta cerrado. Puedes revisar su configuracion historica y, si lo necesitas, abrirlo temporalmente para editarlo.
                    </div>

                    @if ($canTemporarilyUnlockMonth)
                        <div class="commission-settings-inline-unlock">
                            <div class="small">
                                {{ $isTemporarilyUnlocked
                                    ? 'Este mes esta abierto temporalmente para esta sesion. Guarda los cambios para que vuelva a cerrarse.'
                                    : 'Puedes abrir temporalmente cualquier mes cerrado para verificar calculos. Al guardar se volvera a cerrar automaticamente.' }}
                            </div>

                            @if (! $isTemporarilyUnlocked)
                                <form method="POST" action="{{ route('reports.commission-settings.unlock') }}">
                                    @csrf
                                    <input type="hidden" name="month" value="{{ $selectedMonthKey }}">
                                    <button type="submit" class="main-tab active">Abrir mes temporalmente</button>
                                </form>
                            @endif
                        </div>
                    @endif
                </div>
            @endif
        </section>

        <form method="POST" action="{{ route('reports.commission-settings.update') }}">
            @csrf
            @method('PUT')
            <input type="hidden" name="month" value="{{ $selectedMonthKey }}">
            <section class="card panel">
                <div class="panel-title compact">
                    <div>
                        <h3>Objetivo general de compañía</h3>
                        <div class="small">Aún no se utiliza en fórmulas; vacío significa pendiente de configurar.</div>
                    </div>
                </div>
                <div class="filter-group">
                    <label for="company_delivery_target">Objetivo entregas compañía</label>
                    <input id="company_delivery_target" name="company_delivery_target" type="number" step="1" min="1" value="{{ old('company_delivery_target', $settings['company_delivery_target'] ?? '') }}" @disabled(! $isEditableMonth)>
                </div>
            </section>

            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Ventas, compras, stock y bonus</h2>
                        <div class="small">Importes directos y reglas base del mes seleccionado.</div>
                    </div>
                </div>

                <div class="report-user-form-grid">
                    <div class="filter-group">
                        <label for="sales_solo_delivery_amount">Entrega normal</label>
                        <input id="sales_solo_delivery_amount" name="sales[solo_delivery_amount]" type="number" step="0.01" min="0" value="{{ old('sales.solo_delivery_amount', $settings['sales']['solo_delivery_amount']) }}" @disabled(! $isEditableMonth)>
                    </div>
                    <div class="filter-group">
                        <label for="sales_shared_owner_delivery_amount">Entrega compartida owner</label>
                        <input id="sales_shared_owner_delivery_amount" name="sales[shared_owner_delivery_amount]" type="number" step="0.01" min="0" value="{{ old('sales.shared_owner_delivery_amount', $settings['sales']['shared_owner_delivery_amount']) }}" @disabled(! $isEditableMonth)>
                    </div>
                    <div class="filter-group">
                        <label for="sales_shared_secondary_delivery_amount">Entrega compartida secundario</label>
                        <input id="sales_shared_secondary_delivery_amount" name="sales[shared_secondary_delivery_amount]" type="number" step="0.01" min="0" value="{{ old('sales.shared_secondary_delivery_amount', $settings['sales']['shared_secondary_delivery_amount']) }}" @disabled(! $isEditableMonth)>
                    </div>
                    <div class="filter-group">
                        <label for="purchases_commission_percent">Comision compra</label>
                        <input id="purchases_commission_percent" name="purchases[commission_percent]" type="number" step="0.0001" min="0" max="1" value="{{ old('purchases.commission_percent', $settings['purchases']['commission_percent']) }}" @disabled(! $isEditableMonth)>
                    </div>
                    <div class="filter-group">
                        <label for="stock_days_threshold">Stock dias minimo</label>
                        <input id="stock_days_threshold" name="stock[days_threshold]" type="number" step="1" min="0" value="{{ old('stock.days_threshold', $settings['stock']['days_threshold']) }}" @disabled(! $isEditableMonth)>
                    </div>
                    <div class="filter-group">
                        <label for="stock_amount">Stock importe</label>
                        <input id="stock_amount" name="stock[amount]" type="number" step="0.01" min="0" value="{{ old('stock.amount', $settings['stock']['amount']) }}" @disabled(! $isEditableMonth)>
                    </div>
                    <div class="filter-group">
                        <label for="bonus_start_after_delivery">Bonus desde entrega</label>
                        <input id="bonus_start_after_delivery" name="bonus[start_after_delivery]" type="number" step="1" min="0" value="{{ old('bonus.start_after_delivery', $settings['bonus']['start_after_delivery']) }}" @disabled(! $isEditableMonth)>
                    </div>
                    <div class="filter-group">
                        <label for="bonus_amount_per_delivery">Bonus por entrega extra</label>
                        <input id="bonus_amount_per_delivery" name="bonus[amount_per_delivery]" type="number" step="0.01" min="0" value="{{ old('bonus.amount_per_delivery', $settings['bonus']['amount_per_delivery']) }}" @disabled(! $isEditableMonth)>
                    </div>
                </div>
            </section>

            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Tramos y penalizaciones</h2>
                        <div class="small">Los tramos solo aplican a perfiles que no tengan 100% fijo por regla de negocio.</div>
                    </div>
                </div>

                <div class="report-user-form-grid">
                    <div class="filter-group">
                        <label for="delivery_brackets_0_max">Tramo 1 max entregas</label>
                        <input id="delivery_brackets_0_max" name="delivery_brackets[0][max_deliveries]" type="number" step="1" min="0" value="{{ old('delivery_brackets.0.max_deliveries', $settings['delivery_brackets'][0]['max_deliveries']) }}" @disabled(! $isEditableMonth)>
                    </div>
                    <div class="filter-group">
                        <label for="delivery_brackets_0_percent">Tramo 1 porcentaje</label>
                        <input id="delivery_brackets_0_percent" name="delivery_brackets[0][percent]" type="number" step="0.0001" min="0" max="1" value="{{ old('delivery_brackets.0.percent', $settings['delivery_brackets'][0]['percent']) }}" @disabled(! $isEditableMonth)>
                    </div>
                    <div class="filter-group">
                        <label for="delivery_brackets_1_max">Tramo 2 max entregas</label>
                        <input id="delivery_brackets_1_max" name="delivery_brackets[1][max_deliveries]" type="number" step="1" min="0" value="{{ old('delivery_brackets.1.max_deliveries', $settings['delivery_brackets'][1]['max_deliveries']) }}" @disabled(! $isEditableMonth)>
                    </div>
                    <div class="filter-group">
                        <label for="delivery_brackets_1_percent">Tramo 2 porcentaje</label>
                        <input id="delivery_brackets_1_percent" name="delivery_brackets[1][percent]" type="number" step="0.0001" min="0" max="1" value="{{ old('delivery_brackets.1.percent', $settings['delivery_brackets'][1]['percent']) }}" @disabled(! $isEditableMonth)>
                    </div>
                    <div class="filter-group">
                        <label for="delivery_brackets_2_percent">Tramo 3 porcentaje</label>
                        <input id="delivery_brackets_2_percent" name="delivery_brackets[2][percent]" type="number" step="0.0001" min="0" max="1" value="{{ old('delivery_brackets.2.percent', $settings['delivery_brackets'][2]['percent']) }}" @disabled(! $isEditableMonth)>
                    </div>
                    <div class="filter-group">
                        <label for="penalties_guarantee_total_threshold">Garantias minimo sin penalizar</label>
                        <input id="penalties_guarantee_total_threshold" name="penalties[guarantee_total_threshold]" type="number" step="0.01" min="0" value="{{ old('penalties.guarantee_total_threshold', $settings['penalties']['guarantee_total_threshold']) }}" @disabled(! $isEditableMonth)>
                    </div>
                    <div class="filter-group">
                        <label for="penalties_guarantee_percent">Penalizacion garantias</label>
                        <input id="penalties_guarantee_percent" name="penalties[guarantee_percent]" type="number" step="0.0001" min="0" max="1" value="{{ old('penalties.guarantee_percent', $settings['penalties']['guarantee_percent']) }}" @disabled(! $isEditableMonth)>
                    </div>
                    <div class="filter-group">
                        <label for="penalties_reviews_low_threshold">Resenas tramo 1 hasta</label>
                        <input id="penalties_reviews_low_threshold" name="penalties[reviews_low_threshold]" type="number" step="0.01" min="0" max="100" value="{{ old('penalties.reviews_low_threshold', $settings['penalties']['reviews_low_threshold']) }}" @disabled(! $isEditableMonth)>
                    </div>
                    <div class="filter-group">
                        <label for="penalties_reviews_low_percent">Penalizacion resenas tramo 1</label>
                        <input id="penalties_reviews_low_percent" name="penalties[reviews_low_percent]" type="number" step="0.0001" min="0" max="1" value="{{ old('penalties.reviews_low_percent', $settings['penalties']['reviews_low_percent']) }}" @disabled(! $isEditableMonth)>
                    </div>
                    <div class="filter-group">
                        <label for="penalties_reviews_mid_threshold">Resenas tramo 2 hasta</label>
                        <input id="penalties_reviews_mid_threshold" name="penalties[reviews_mid_threshold]" type="number" step="0.01" min="0" max="100" value="{{ old('penalties.reviews_mid_threshold', $settings['penalties']['reviews_mid_threshold']) }}" @disabled(! $isEditableMonth)>
                    </div>
                    <div class="filter-group">
                        <label for="penalties_reviews_mid_percent">Penalizacion resenas tramo 2</label>
                        <input id="penalties_reviews_mid_percent" name="penalties[reviews_mid_percent]" type="number" step="0.0001" min="0" max="1" value="{{ old('penalties.reviews_mid_percent', $settings['penalties']['reviews_mid_percent']) }}" @disabled(! $isEditableMonth)>
                    </div>
                    <div class="filter-group">
                        <label for="penalties_financing_percentage_threshold">Financiacion minima</label>
                        <input id="penalties_financing_percentage_threshold" name="penalties[financing_percentage_threshold]" type="number" step="0.01" min="0" max="100" value="{{ old('penalties.financing_percentage_threshold', $settings['penalties']['financing_percentage_threshold']) }}" @disabled(! $isEditableMonth)>
                    </div>
                    <div class="filter-group">
                        <label for="penalties_financing_percent">Penalizacion financiacion</label>
                        <input id="penalties_financing_percent" name="penalties[financing_percent]" type="number" step="0.0001" min="0" max="1" value="{{ old('penalties.financing_percent', $settings['penalties']['financing_percent']) }}" @disabled(! $isEditableMonth)>
                    </div>
                </div>
            </section>
            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Producto financiacion</h2>
                        <div class="small">Cada fila representa el importe minimo del tramo y su porcentaje aplicado.</div>
                    </div>
                </div>
                <div class="report-settings-brackets-grid">
                    @foreach ($settings['financing_product_brackets'] as $index => $bracket)
                        <article class="card report-access-card">
                            <strong>{{ $financingLabels[$index] ?? 'Tramo '.($index + 1) }}</strong>
                            <div class="filter-group">
                                <label for="financing_product_brackets_{{ $index }}_min">Importe minimo</label>
                                <input id="financing_product_brackets_{{ $index }}_min" name="financing_product_brackets[{{ $index }}][min_amount]" type="number" step="0.01" min="0" value="{{ old("financing_product_brackets.$index.min_amount", $bracket['min_amount']) }}" @disabled(! $isEditableMonth)>
                            </div>
                            <div class="filter-group">
                                <label for="financing_product_brackets_{{ $index }}_percent">Porcentaje</label>
                                <input id="financing_product_brackets_{{ $index }}_percent" name="financing_product_brackets[{{ $index }}][percent]" type="number" step="0.0001" min="0" max="1" value="{{ old("financing_product_brackets.$index.percent", $bracket['percent']) }}" @disabled(! $isEditableMonth)>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>

            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Producto garantias</h2>
                        <div class="small">Cada fila representa el importe minimo del tramo y su porcentaje aplicado.</div>
                    </div>
                </div>
                <div class="report-settings-brackets-grid">
                    @foreach ($settings['guarantee_product_brackets'] as $index => $bracket)
                        <article class="card report-access-card">
                            <strong>{{ $guaranteeLabels[$index] ?? 'Tramo '.($index + 1) }}</strong>
                            <div class="filter-group">
                                <label for="guarantee_product_brackets_{{ $index }}_min">Importe minimo</label>
                                <input id="guarantee_product_brackets_{{ $index }}_min" name="guarantee_product_brackets[{{ $index }}][min_amount]" type="number" step="0.01" min="0" value="{{ old("guarantee_product_brackets.$index.min_amount", $bracket['min_amount']) }}" @disabled(! $isEditableMonth)>
                            </div>
                            <div class="filter-group">
                                <label for="guarantee_product_brackets_{{ $index }}_percent">Porcentaje</label>
                                <input id="guarantee_product_brackets_{{ $index }}_percent" name="guarantee_product_brackets[{{ $index }}][percent]" type="number" step="0.0001" min="0" max="1" value="{{ old("guarantee_product_brackets.$index.percent", $bracket['percent']) }}" @disabled(! $isEditableMonth)>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>

            @unless ($usesSharedDelegationDeliveryGoals ?? false)
            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Metas por delegacion</h2>
                        <div class="small">Estas metas afectan al cuadro de delegaciones. Si el mes no tiene metas propias, hereda automaticamente las del mes anterior hasta que se guarde una configuracion nueva.</div>
                    </div>
                </div>

                <div class="report-settings-brackets-grid">
                    @forelse ($availableDelegations as $delegation)
                        @php
                            $delegationGoal = $settings['delegations']['goals'][$delegation['key']]['target_deliveries'] ?? 0;
                        @endphp
                        <article class="card report-access-card">
                            <strong>{{ $delegation['label'] }}</strong>
                            <input type="hidden" name="delegations[goals][{{ $delegation['key'] }}][label]" value="{{ $delegation['label'] }}">
                            <div class="filter-group">
                                <label for="delegation_goal_{{ $delegation['key'] }}">Meta entregas</label>
                                <input
                                    id="delegation_goal_{{ $delegation['key'] }}"
                                    name="delegations[goals][{{ $delegation['key'] }}][target_deliveries]"
                                    type="number"
                                    step="1"
                                    min="0"
                                    value="{{ old("delegations.goals.{$delegation['key']}.target_deliveries", $delegationGoal) }}"
                                    @disabled(! $isEditableMonth)
                                >
                            </div>
                        </article>
                    @empty
                        <article class="card report-access-card">
                            <strong>Sin delegaciones detectadas</strong>
                            <div class="small">Todavia no hay valores de `owner_delegation` disponibles en `salesforce_opportunities` para generar metas editables.</div>
                        </article>
                    @endforelse
                </div>
            </section>
            @else
                <div class="notice">
                    Desde julio de 2026 la meta de entregas de Delegaciones se edita una sola vez en los objetivos de Area Manager.
                </div>
            @endunless

            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Area Manager</h2>
                        <div class="small">Bases por KPI, llaves de zona y objetivos editables por delegacion. Si un mes no tiene configuracion propia, hereda automaticamente la ultima disponible.</div>
                    </div>
                </div>

                <div class="report-user-form-grid">
                    <div class="filter-group">
                        <label for="area_manager_base_deliveries">Base entregas</label>
                        <input id="area_manager_base_deliveries" name="area_manager[kpi_bases][deliveries]" type="number" step="0.01" min="0" value="{{ old('area_manager.kpi_bases.deliveries', $settings['area_manager']['kpi_bases']['deliveries'] ?? 150) }}" @disabled(! $isEditableMonth)>
                    </div>
                    <div class="filter-group">
                        <label for="area_manager_base_benefit">Base beneficio</label>
                        <input id="area_manager_base_benefit" name="area_manager[kpi_bases][benefit]" type="number" step="0.01" min="0" value="{{ old('area_manager.kpi_bases.benefit', $settings['area_manager']['kpi_bases']['benefit'] ?? 150) }}" @disabled(! $isEditableMonth)>
                    </div>
                    <div class="filter-group">
                        <label for="area_manager_base_guarantee">Base garantia premium</label>
                        <input id="area_manager_base_guarantee" name="area_manager[kpi_bases][guarantee]" type="number" step="0.01" min="0" value="{{ old('area_manager.kpi_bases.guarantee', $settings['area_manager']['kpi_bases']['guarantee'] ?? 100) }}" @disabled(! $isEditableMonth)>
                    </div>
                    <div class="filter-group">
                        <label for="area_manager_base_purchases">Base compras</label>
                        <input id="area_manager_base_purchases" name="area_manager[kpi_bases][purchases]" type="number" step="0.01" min="0" value="{{ old('area_manager.kpi_bases.purchases', $settings['area_manager']['kpi_bases']['purchases'] ?? 100) }}" @disabled(! $isEditableMonth)>
                    </div>
                </div>

                <div class="report-settings-brackets-grid">
                    @foreach (($settings['area_manager']['zone_keys'] ?? []) as $index => $zoneKey)
                        <article class="card report-access-card">
                            <strong>Llave zona {{ $index + 1 }}</strong>
                            <div class="filter-group">
                                <label for="area_manager_zone_{{ $index }}_min">Cumplimiento minimo %</label>
                                <input id="area_manager_zone_{{ $index }}_min" name="area_manager[zone_keys][{{ $index }}][min_percent]" type="number" step="0.01" min="0" value="{{ old("area_manager.zone_keys.$index.min_percent", $zoneKey['min_percent'] ?? 0) }}" @disabled(! $isEditableMonth)>
                            </div>
                            <div class="filter-group">
                                <label for="area_manager_zone_{{ $index }}_multiplier">Multiplicador</label>
                                <input id="area_manager_zone_{{ $index }}_multiplier" name="area_manager[zone_keys][{{ $index }}][multiplier]" type="number" step="0.01" min="0" value="{{ old("area_manager.zone_keys.$index.multiplier", $zoneKey['multiplier'] ?? 0) }}" @disabled(! $isEditableMonth)>
                            </div>
                        </article>
                    @endforeach
                </div>

                <div class="report-settings-brackets-grid">
                    @forelse ($availableAreaManagerDelegations as $delegation)
                        @php
                            $assignment = $settings['area_manager']['assignments'][$delegation['key']] ?? null;
                            $objectives = $assignment['objectives'] ?? [];
                        @endphp
                        <article class="card report-access-card">
                            <strong>{{ $delegation['label'] }}</strong>
                            <input type="hidden" name="area_manager[assignments][{{ $delegation['key'] }}][label]" value="{{ $delegation['label'] }}">
                            <div class="filter-group">
                                <label for="area_manager_manager_{{ $delegation['key'] }}">Manager</label>
                                <select id="area_manager_manager_{{ $delegation['key'] }}" name="area_manager[assignments][{{ $delegation['key'] }}][manager_key]" @disabled(! $isEditableMonth)>
                                    <option value="">Sin asignar</option>
                                    @foreach ($areaManagerDefinitions as $manager)
                                        <option value="{{ $manager['key'] }}" @selected(old("area_manager.assignments.{$delegation['key']}.manager_key", $assignment['manager_key'] ?? '') === $manager['key'])>
                                            {{ $manager['label'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="filter-group">
                                <input type="hidden" name="area_manager[assignments][{{ $delegation['key'] }}][active]" value="0">
                                <label class="switch-option">
                                    <input type="checkbox" name="area_manager[assignments][{{ $delegation['key'] }}][active]" value="1" @checked(old("area_manager.assignments.{$delegation['key']}.active", $assignment['active'] ?? true)) @disabled(! $isEditableMonth)>
                                    <span>Delegacion activa</span>
                                </label>
                            </div>
                            <div class="filter-group">
                                <label for="area_manager_deliveries_{{ $delegation['key'] }}">Objetivo entregas</label>
                                <input id="area_manager_deliveries_{{ $delegation['key'] }}" name="area_manager[assignments][{{ $delegation['key'] }}][objectives][deliveries]" type="number" step="0.01" min="0" value="{{ old("area_manager.assignments.{$delegation['key']}.objectives.deliveries", $objectives['deliveries'] ?? 0) }}" @disabled(! $isEditableMonth)>
                            </div>
                            <div class="filter-group">
                                <label for="area_manager_benefit_{{ $delegation['key'] }}">Objetivo beneficio</label>
                                <input id="area_manager_benefit_{{ $delegation['key'] }}" name="area_manager[assignments][{{ $delegation['key'] }}][objectives][benefit]" type="number" step="0.01" min="0" value="{{ old("area_manager.assignments.{$delegation['key']}.objectives.benefit", $objectives['benefit'] ?? 0) }}" @disabled(! $isEditableMonth)>
                            </div>
                            <div class="filter-group">
                                <label for="area_manager_guarantee_{{ $delegation['key'] }}">Objetivo garantia premium</label>
                                <input id="area_manager_guarantee_{{ $delegation['key'] }}" name="area_manager[assignments][{{ $delegation['key'] }}][objectives][guarantee]" type="number" step="0.01" min="0" value="{{ old("area_manager.assignments.{$delegation['key']}.objectives.guarantee", $objectives['guarantee'] ?? 0) }}" @disabled(! $isEditableMonth)>
                            </div>
                            <div class="filter-group">
                                <label for="area_manager_purchases_{{ $delegation['key'] }}">Objetivo compras</label>
                                <input id="area_manager_purchases_{{ $delegation['key'] }}" name="area_manager[assignments][{{ $delegation['key'] }}][objectives][purchases]" type="number" step="0.01" min="0" value="{{ old("area_manager.assignments.{$delegation['key']}.objectives.purchases", $objectives['purchases'] ?? 0) }}" @disabled(! $isEditableMonth)>
                            </div>
                        </article>
                    @empty
                        <article class="card report-access-card">
                            <strong>Sin delegaciones detectadas</strong>
                            <div class="small">No hay delegaciones suficientes para configurar Area Manager todavia.</div>
                        </article>
                    @endforelse
                </div>
            </section>

            <section class="card panel">
                <div class="panel-title">
                    <div>
                        <h2>Financieros</h2>
                        <div class="small">Tramos configurables por periodo para % financiado, rentabilidad y % garantias premium.</div>
                    </div>
                </div>

                <div class="report-settings-brackets-grid">
                    <article class="card report-access-card">
                        <strong>% financiado</strong>
                        <div class="small">Se aplica sobre la comision neta = Comision financiera - Descuento financiero.</div>
                        @foreach (($settings['financials']['financed_percentage_brackets'] ?? []) as $index => $bracket)
                            <div class="filter-group">
                                <label for="financials_financed_{{ $index }}_min">Min % tramo {{ $index + 1 }}</label>
                                <input id="financials_financed_{{ $index }}_min" name="financials[financed_percentage_brackets][{{ $index }}][min_percent]" type="number" step="0.0001" min="0" value="{{ old("financials.financed_percentage_brackets.$index.min_percent", $bracket['min_percent'] ?? 0) }}" @disabled(! $isEditableMonth)>
                            </div>
                            <div class="filter-group">
                                <label for="financials_financed_{{ $index }}_incentive">Incentivo tramo {{ $index + 1 }}</label>
                                <input id="financials_financed_{{ $index }}_incentive" name="financials[financed_percentage_brackets][{{ $index }}][incentive]" type="number" step="0.0001" min="0" max="1" value="{{ old("financials.financed_percentage_brackets.$index.incentive", $bracket['incentive'] ?? 0) }}" @disabled(! $isEditableMonth)>
                            </div>
                        @endforeach
                    </article>

                    <article class="card report-access-card">
                        <strong>Rentabilidad</strong>
                        <div class="small">Solo cuentan operaciones con tipo de interes informado y distinto de los excluidos.</div>
                        @foreach (($settings['financials']['profitability_brackets'] ?? []) as $index => $bracket)
                            <div class="filter-group">
                                <label for="financials_profitability_{{ $index }}_min">Min % tramo {{ $index + 1 }}</label>
                                <input id="financials_profitability_{{ $index }}_min" name="financials[profitability_brackets][{{ $index }}][min_percent]" type="number" step="0.0001" min="0" value="{{ old("financials.profitability_brackets.$index.min_percent", $bracket['min_percent'] ?? 0) }}" @disabled(! $isEditableMonth)>
                            </div>
                            <div class="filter-group">
                                <label for="financials_profitability_{{ $index }}_incentive">Incentivo tramo {{ $index + 1 }}</label>
                                <input id="financials_profitability_{{ $index }}_incentive" name="financials[profitability_brackets][{{ $index }}][incentive]" type="number" step="0.0001" min="0" max="1" value="{{ old("financials.profitability_brackets.$index.incentive", $bracket['incentive'] ?? 0) }}" @disabled(! $isEditableMonth)>
                            </div>
                        @endforeach
                    </article>

                    <article class="card report-access-card">
                        <strong>% garantias premium</strong>
                        <div class="small">Se aplica sobre la suma de `Garant_a_Total__c` de la zona.</div>
                        @foreach (($settings['financials']['guarantee_percentage_brackets'] ?? []) as $index => $bracket)
                            <div class="filter-group">
                                <label for="financials_guarantee_{{ $index }}_min">Min % tramo {{ $index + 1 }}</label>
                                <input id="financials_guarantee_{{ $index }}_min" name="financials[guarantee_percentage_brackets][{{ $index }}][min_percent]" type="number" step="0.0001" min="0" value="{{ old("financials.guarantee_percentage_brackets.$index.min_percent", $bracket['min_percent'] ?? 0) }}" @disabled(! $isEditableMonth)>
                            </div>
                            <div class="filter-group">
                                <label for="financials_guarantee_{{ $index }}_incentive">Incentivo tramo {{ $index + 1 }}</label>
                                <input id="financials_guarantee_{{ $index }}_incentive" name="financials[guarantee_percentage_brackets][{{ $index }}][incentive]" type="number" step="0.0001" min="0" max="1" value="{{ old("financials.guarantee_percentage_brackets.$index.incentive", $bracket['incentive'] ?? 0) }}" @disabled(! $isEditableMonth)>
                            </div>
                        @endforeach
                    </article>

                    <article class="card report-access-card">
                        <strong>Tipos excluidos en rentabilidad</strong>
                        <div class="small">Si coinciden con `Inter_s_elegido__c`, la operacion sale del bloque 2.</div>
                        @foreach (($settings['financials']['excluded_interest_rates'] ?? []) as $index => $interestRate)
                            <div class="filter-group">
                                <label for="financials_interest_excluded_{{ $index }}">Tipo {{ $index + 1 }}</label>
                                <input id="financials_interest_excluded_{{ $index }}" name="financials[excluded_interest_rates][{{ $index }}]" type="text" value="{{ old("financials.excluded_interest_rates.$index", $interestRate) }}" @disabled(! $isEditableMonth)>
                            </div>
                        @endforeach
                    </article>

                    <article class="card report-access-card">
                        <strong>Reglas por responsable financiero</strong>
                        <div class="small">Las reglas especiales se resuelven mediante claves estables de zona financiera desde la configuración de aplicación. El OwnerId comercial y el nombre visible no seleccionan la regla.</div>
                    </article>
                </div>
            </section>

            @if ($isEditableMonth)
                <div class="report-user-form-actions">
                    <button type="submit" class="main-tab active">Guardar coeficientes del mes</button>
                </div>
            @endif
        </form>
    </main>
</div>
</x-reports.app-shell>
