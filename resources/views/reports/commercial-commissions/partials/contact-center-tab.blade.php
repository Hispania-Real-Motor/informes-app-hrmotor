@php
    $contactCenterMonthStart = \Carbon\CarbonImmutable::parse(($contactCenterDashboard['month'] ?? $dashboard['month']).'-01');
    $contactCenterMonthEnd = $contactCenterMonthStart->addMonth();
    $contactCenterSummaryRows = collect($contactCenterDashboard['summary_rows'] ?? []);
    $contactCenterResponsible = $contactCenterDashboard['responsible_contact_center'] ?? null;
    $contactCenterGlobalIncidents = collect($contactCenterDashboard['global_incidents'] ?? []);
    $contactCenterDefaultAgentKey = $contactCenterSummaryRows->first()['agent_key'] ?? null;
    $contactCenterTotalAppointments = (int) $contactCenterSummaryRows->sum('appointment_count');
    $contactCenterTotalOpportunities = (int) $contactCenterSummaryRows->sum('opportunity_count');
    $contactCenterTotalReservations = (int) $contactCenterSummaryRows->sum('reservation_count');
    $contactCenterTotalSales = (int) $contactCenterSummaryRows->sum('sales_count');
    $contactCenterTotalAutomatic = (float) $contactCenterSummaryRows->sum('automatic_total');
    $contactCenterTotalShow = (int) $contactCenterSummaryRows->sum('show_count');
    $contactCenterTooltips = [
        'Citas concertadas' => 'Leads con Fecha_captador__c dentro del mes, captador de cita del equipo Contact Center y al menos una de las marcas Cita_llamada__c o Cita_Tienda__c activa.',
        'Citas con oportunidad' => 'Oportunidades del equipo Contact Center con Fecha_captador__c dentro del mes y algun hito posterior antes del cierre operativo.',
        'Reservas detectadas' => 'Reservas del equipo Contact Center posteriores a la fecha de captacion y dentro de la misma ventana de cierre operativo.',
        'Ventas confirmadas' => 'Oportunidades del equipo Contact Center con Contrato CV firmado = true y Stage distinto de Cerrada perdida, imputadas al mes de firma.',
        'Ratio venta / cita' => 'Ventas confirmadas del mes dividido entre citas concertadas del mismo agente en ese mes.',
        'Extra >3%' => 'Si el ratio venta/cita supera el 3%, se suman 2 EUR por cada venta confirmada.',
        'Bonus ventas' => 'Tramo maximo por volumen: 10 ventas = 100 EUR, 15 = 250 EUR, 20 = 500 EUR.',
        'Show informativo' => 'Dato informativo. Cuenta citas con Acudi_a_la_cita__c = ACUDIO, pero no genera importe por ahora.',
        'Total automatico' => 'Comision citas con oportunidad + comision ventas + extra por ratio + bonus de volumen.',
    ];
    $contactCenterTooltip = static fn (string $label): string => $contactCenterTooltips[$label] ?? '';
@endphp

<section class="call-center-kpis-grid contact-center-kpis-grid">
    <article class="card campaign-context-card">
        <span class="kpi-tooltip" data-kpi-tooltip="{{ $contactCenterTooltip('Citas concertadas') }}">Citas concertadas</span>
        <strong>{{ number_format($contactCenterTotalAppointments, 0, ',', '.') }}</strong>
    </article>
    <article class="card campaign-context-card">
        <span class="kpi-tooltip" data-kpi-tooltip="{{ $contactCenterTooltip('Citas con oportunidad') }}">Citas con oportunidad</span>
        <strong>{{ number_format($contactCenterTotalOpportunities, 0, ',', '.') }}</strong>
    </article>
    <article class="card campaign-context-card">
        <span class="kpi-tooltip" data-kpi-tooltip="{{ $contactCenterTooltip('Reservas detectadas') }}">Reservas detectadas</span>
        <strong>{{ number_format($contactCenterTotalReservations, 0, ',', '.') }}</strong>
    </article>
    <article class="card campaign-context-card">
        <span class="kpi-tooltip" data-kpi-tooltip="{{ $contactCenterTooltip('Ventas confirmadas') }}">Ventas confirmadas</span>
        <strong>{{ number_format($contactCenterTotalSales, 0, ',', '.') }}</strong>
    </article>
    <article class="card campaign-context-card">
        <span class="kpi-tooltip" data-kpi-tooltip="{{ $contactCenterTooltip('Total automatico') }}">Total automatico</span>
        <strong>{{ number_format($contactCenterTotalAutomatic, 2, ',', '.') }} EUR</strong>
    </article>
</section>

@if (is_array($contactCenterResponsible))
    <section class="card panel contact-center-responsible-panel">
        <div class="panel-title compact">
            <div>
                <h3>Responsable Contact Center</h3>
                <div class="small">Comisión independiente de agentes, basada exclusivamente en entregas elegibles de toda la empresa.</div>
            </div>
            <strong>{{ number_format((float) ($contactCenterResponsible['final_total'] ?? 0), 2, ',', '.') }} EUR</strong>
        </div>
        @if (!empty($contactCenterResponsible['issues']))
            <div class="notice">{{ implode(' ', $contactCenterResponsible['issues']) }}</div>
        @endif
        <div class="platform-comparison-metrics">
            <div class="platform-metric-item"><span>Responsable</span><strong>{{ $contactCenterResponsible['responsible_name'] }}</strong></div>
            <div class="platform-metric-item"><span>Mes liquidado</span><strong>{{ $contactCenterResponsible['month'] }}</strong></div>
            <div class="platform-metric-item"><span>Mes referencia</span><strong>{{ $contactCenterResponsible['reference_month'] }}</strong></div>
            <div class="platform-metric-item"><span>Entregas actuales</span><strong>{{ number_format((int) $contactCenterResponsible['actual_deliveries'], 0, ',', '.') }}</strong></div>
            <div class="platform-metric-item"><span>Entregas referencia</span><strong>{{ number_format((int) $contactCenterResponsible['reference_deliveries'], 0, ',', '.') }}</strong></div>
            <div class="platform-metric-item"><span>Cumplimiento</span><strong>{{ $contactCenterResponsible['compliance_percent'] === null ? 'No calculable' : number_format((float) $contactCenterResponsible['compliance_percent'], 2, ',', '.').'%' }}</strong></div>
            <div class="platform-metric-item"><span>Tramo</span><strong>{{ $contactCenterResponsible['bracket'] }}</strong></div>
            <div class="platform-metric-item"><span>Entregas adicionales</span><strong>{{ number_format((int) $contactCenterResponsible['additional_deliveries'], 0, ',', '.') }}</strong></div>
            <div class="platform-metric-item"><span>Importe base</span><strong>{{ number_format((float) $contactCenterResponsible['base_amount'], 2, ',', '.') }} EUR</strong></div>
            <div class="platform-metric-item"><span>Importe adicional</span><strong>{{ number_format((float) $contactCenterResponsible['additional_amount'], 2, ',', '.') }} EUR</strong></div>
        </div>
        @foreach (['actual' => 'Entregas del mes liquidado', 'reference' => 'Entregas del mismo mes del año anterior'] as $cohort => $title)
            <div class="table-shell">
                <h4>{{ $title }}</h4>
                <table data-sortable-table="contact-center-responsible-{{ $cohort }}">
                    <thead><tr><th>Opportunity ID</th><th>Nombre</th><th>Fecha firma</th><th>Tipo</th><th>Etapa</th><th>Owner</th><th>Tienda / Delegación</th><th>Facilitea</th><th>Motivo inclusión</th></tr></thead>
                    <tbody data-sort-body="contact-center-responsible-{{ $cohort }}">
                    @forelse (($contactCenterResponsible[$cohort.'_delivery_details'] ?? []) as $detail)
                        <tr><td>{{ $detail['opportunity_id'] }}</td><td>{{ $detail['opportunity_name'] ?: '-' }}</td><td>{{ $detail['contract_signed_date'] ?? '-' }}</td><td>{{ $detail['record_type_name'] ?: '-' }}</td><td>{{ $detail['stage_name'] ?: '-' }}</td><td>{{ $detail['owner_name'] ?: '-' }}</td><td>{{ $detail['delegation'] ?: '-' }}</td><td>{{ $detail['is_facilitea'] ? 'Sí' : 'No' }}</td><td>{{ $detail['inclusion_reason'] }}</td></tr>
                    @empty
                        <tr><td colspan="9">Sin entregas elegibles.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        @endforeach
    </section>
@endif

<section class="platform-comparison-grid commission-overview-grid contact-center-overview-grid">
    <article class="card platform-comparison-card">
        <div class="platform-comparison-head">
            <strong>Base mensual</strong>
            <span>Resumen agrupado por captador de cita para {{ $contactCenterDashboard['month_label'] ?? $dashboard['month_label'] }}.</span>
        </div>
        <div class="platform-comparison-metrics">
            <div class="platform-metric-item"><span>Agentes</span><strong>{{ number_format($contactCenterSummaryRows->count(), 0, ',', '.') }}</strong></div>
            <div class="platform-metric-item"><span>Citas</span><strong>{{ number_format($contactCenterDashboard['diagnostics']['appointments_count'] ?? 0, 0, ',', '.') }}</strong></div>
            <div class="platform-metric-item"><span>Ventas</span><strong>{{ number_format($contactCenterDashboard['diagnostics']['sales_count'] ?? 0, 0, ',', '.') }}</strong></div>
            <div class="platform-metric-item"><span>Show</span><strong>{{ number_format($contactCenterTotalShow, 0, ',', '.') }}</strong></div>
            <div class="platform-metric-item"><span>Incidencias</span><strong>{{ number_format($contactCenterGlobalIncidents->count(), 0, ',', '.') }}</strong></div>
        </div>
    </article>
    <article class="card platform-comparison-card">
        <div class="platform-comparison-head">
            <strong>Reglas activas</strong>
            <span>Ventas por mes de firma. Citas con oportunidad usan ventana de cierre hasta el {{ \Carbon\CarbonImmutable::parse($contactCenterDashboard['closure_cutoff_date'] ?? $contactCenterMonthEnd->toDateString())->translatedFormat('d/m/Y') }}.</span>
        </div>
        <div class="platform-comparison-metrics">
            <div class="platform-metric-item"><span class="kpi-tooltip" data-kpi-tooltip="{{ $contactCenterTooltip('Ratio venta / cita') }}">Ratio venta / cita</span><strong>> 3% activa extra</strong></div>
            <div class="platform-metric-item"><span class="kpi-tooltip" data-kpi-tooltip="{{ $contactCenterTooltip('Extra >3%') }}">Extra >3%</span><strong>+2 EUR por venta</strong></div>
            <div class="platform-metric-item"><span class="kpi-tooltip" data-kpi-tooltip="{{ $contactCenterTooltip('Bonus ventas') }}">Bonus ventas</span><strong>100 / 250 / 500 EUR</strong></div>
            <div class="platform-metric-item"><span class="kpi-tooltip" data-kpi-tooltip="{{ $contactCenterTooltip('Show informativo') }}">Show informativo</span><strong>Sin importe</strong></div>
        </div>
    </article>
    @if (($reportUserRole ?? null) === \App\Models\ReportUser::ROLE_ADMIN)
        <article class="card platform-comparison-card call-center-diagnostics-card">
            <div class="platform-comparison-head">
                <strong>Diagnostico y resync</strong>
                <span>Contact Center necesita leads y oportunidades actualizados del mes revisado y del mes previo.</span>
            </div>
            <div class="call-center-diagnostics-list">
                <div class="call-center-diagnostics-row">
                    <span>Leads / actividades</span>
                    <code>php artisan salesforce:sync-monthly-commercial --days=120</code>
                </div>
                <div class="call-center-diagnostics-row">
                    <span>Oportunidades</span>
                    <code>php artisan salesforce:sync-opportunities --from={{ $contactCenterMonthStart->subMonth()->toDateString() }} --to={{ $contactCenterMonthEnd->toDateString() }}</code>
                </div>
            </div>
        </article>
    @endif
</section>

@if ($contactCenterSummaryRows->isNotEmpty())
    <div class="table-shell call-center-summary-shell">
        <table data-sortable-table="contact-center-summary">
            <thead>
            <tr>
                <th data-sortable="true">Agente / Captador</th>
                <th class="num" data-sortable="true">Total final</th>
                <th class="num" data-sortable="true">Citas</th>
                <th class="num" data-sortable="true">Citas con oportunidad</th>
                <th class="num" data-sortable="true">Reservas</th>
                <th class="num" data-sortable="true">Ventas</th>
                <th class="num" data-sortable="true">% venta / cita</th>
                <th class="num" data-sortable="true">Com. citas</th>
                <th class="num" data-sortable="true">Com. ventas</th>
                <th class="num" data-sortable="true">Extra >3%</th>
                <th class="num" data-sortable="true">Bonus ventas</th>
                <th class="num" data-sortable="true">Show</th>
                <th class="num" data-sortable="true">Estado revision</th>
            </tr>
            </thead>
            <tbody data-sort-body="contact-center-summary">
            @foreach ($contactCenterSummaryRows as $row)
                <tr>
                    <td data-sort-value="{{ $row['agent_name'] }}">{{ $row['agent_name'] }}</td>
                    <td class="num" data-sort-value="{{ $row['final_total'] }}"><strong>{{ number_format($row['final_total'], 2, ',', '.') }}</strong></td>
                    <td class="num" data-sort-value="{{ $row['appointment_count'] }}">{{ number_format($row['appointment_count'], 0, ',', '.') }}</td>
                    <td class="num" data-sort-value="{{ $row['opportunity_count'] }}">{{ number_format($row['opportunity_count'], 0, ',', '.') }}</td>
                    <td class="num" data-sort-value="{{ $row['reservation_count'] }}">{{ number_format($row['reservation_count'], 0, ',', '.') }}</td>
                    <td class="num" data-sort-value="{{ $row['sales_count'] }}">{{ number_format($row['sales_count'], 0, ',', '.') }}</td>
                    <td class="num" data-sort-value="{{ $row['sales_ratio'] }}">{{ number_format($row['sales_ratio'] * 100, 2, ',', '.') }}%</td>
                    <td class="num" data-sort-value="{{ $row['opportunity_commission'] }}">{{ number_format($row['opportunity_commission'], 2, ',', '.') }}</td>
                    <td class="num" data-sort-value="{{ $row['sales_commission'] }}">{{ number_format($row['sales_commission'], 2, ',', '.') }}</td>
                    <td class="num" data-sort-value="{{ $row['ratio_bonus'] }}">{{ number_format($row['ratio_bonus'], 2, ',', '.') }}</td>
                    <td class="num" data-sort-value="{{ $row['volume_bonus'] }}">{{ number_format($row['volume_bonus'], 2, ',', '.') }}</td>
                    <td class="num" data-sort-value="{{ $row['show_rate'] }}">{{ number_format($row['show_count'], 0, ',', '.') }} / {{ number_format($row['show_rate'] * 100, 2, ',', '.') }}%</td>
                    <td class="num" data-sort-value="{{ $row['review_status'] }}">{{ $row['review_status'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <section class="commission-detail-shell commission-contact-center-detail-shell" data-agent-browser>
        <aside class="card commission-commercial-picker">
            <div class="filter-group">
                <label for="contactCenterAgentSearch">Buscar agente</label>
                <input
                    type="search"
                    id="contactCenterAgentSearch"
                    placeholder="Filtrar captador o agente"
                    autocomplete="off"
                    data-agent-search-input
                >
            </div>
            <div class="commission-commercial-list">
                @foreach ($contactCenterSummaryRows as $row)
                    <button
                        type="button"
                        class="commission-commercial-option{{ $row['agent_key'] === $contactCenterDefaultAgentKey ? ' is-active' : '' }}"
                        data-agent-option
                        data-agent-id="{{ $row['agent_key'] }}"
                        data-agent-search="{{ \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii($row['agent_name'].' '.$row['review_status'].' '.$row['observations'])) }}"
                    >
                        <strong>{{ $row['agent_name'] }}</strong>
                        <span>{{ number_format($row['final_total'], 2, ',', '.') }} EUR</span>
                        <small>{{ number_format($row['appointment_count'], 0, ',', '.') }} citas | {{ number_format($row['sales_count'], 0, ',', '.') }} ventas</small>
                    </button>
                @endforeach
            </div>
            <div class="notice is-hidden" data-agent-empty>No hay agentes que coincidan con la busqueda.</div>
        </aside>

        <section class="commission-detail-stage">
            @foreach ($contactCenterSummaryRows as $row)
                <article
                    class="commission-commercial-panel{{ $row['agent_key'] === $contactCenterDefaultAgentKey ? ' is-active' : ' is-hidden' }}"
                    data-agent-panel
                    data-agent-id="{{ $row['agent_key'] }}"
                >
                    <section class="call-center-agent-hero">
                        <article class="card campaign-context-card">
                            <span>Agente / Captador</span>
                            <strong>{{ $row['agent_name'] }}</strong>
                        </article>
                        <article class="card campaign-context-card">
                            <span>Total automatico</span>
                            <strong>{{ number_format($row['automatic_total'], 2, ',', '.') }} EUR</strong>
                        </article>
                        <article class="card campaign-context-card">
                            <span>% venta / cita</span>
                            <strong>{{ number_format($row['sales_ratio'] * 100, 2, ',', '.') }}%</strong>
                        </article>
                        <article class="card campaign-context-card">
                            <span>Estado revision</span>
                            <strong>{{ $row['review_status'] }}</strong>
                        </article>
                    </section>

                    <nav class="tabs-main commission-detail-tabs" aria-label="Detalle Contact Center por bloque">
                        <button type="button" class="main-tab active" data-commercial-detail-tab-trigger="appointments">Citas</button>
                        <button type="button" class="main-tab" data-commercial-detail-tab-trigger="opportunities">Citas con oportunidad</button>
                        <button type="button" class="main-tab" data-commercial-detail-tab-trigger="reservations">Reservas</button>
                        <button type="button" class="main-tab" data-commercial-detail-tab-trigger="sales">Ventas</button>
                        <button type="button" class="main-tab" data-commercial-detail-tab-trigger="incidents">Incidencias</button>
                    </nav>

                    <div class="commission-detail-grid">
                        <div data-commercial-detail-tab-panel="appointments">
                            <div class="table-shell">
                                <table data-sortable-table="contact-center-appointments-{{ $row['agent_key'] }}">
                                    <thead>
                                    <tr>
                                        <th data-sortable="true">Lead Id</th>
                                        <th data-sortable="true">Lead</th>
                                        <th data-sortable="true">Telefono</th>
                                        <th data-sortable="true">Fecha captacion</th>
                                        <th data-sortable="true">Cita llamada</th>
                                        <th data-sortable="true">Cita tienda</th>
                                        <th data-sortable="true">Acudio</th>
                                        <th data-sortable="true">Estado candidato</th>
                                        <th data-sortable="true">Propietario lead</th>
                                        <th data-sortable="true">Comercial tienda</th>
                                        <th data-sortable="true">Portal</th>
                                        <th data-sortable="true">Motivo inclusion</th>
                                    </tr>
                                    </thead>
                                    <tbody data-sort-body="contact-center-appointments-{{ $row['agent_key'] }}">
                                    @forelse ($row['details']['appointments'] as $detail)
                                        <tr>
                                            <td>{{ $detail['lead_id'] }}</td>
                                            <td>{{ $detail['lead_name'] !== '' ? $detail['lead_name'] : '-' }}</td>
                                            <td>{{ $detail['phone_normalized'] !== '' ? $detail['phone_normalized'] : '-' }}</td>
                                            <td>{{ $detail['capture_date'] ?? '-' }}</td>
                                            <td>{{ $detail['appointment_call'] ? 'Si' : 'No' }}</td>
                                            <td>{{ $detail['appointment_store'] ? 'Si' : 'No' }}</td>
                                            <td>{{ $detail['attended_status'] !== '' ? $detail['attended_status'] : '-' }}</td>
                                            <td>{{ $detail['candidate_status'] !== '' ? $detail['candidate_status'] : '-' }}</td>
                                            <td>{{ $detail['owner_name'] !== '' ? $detail['owner_name'] : '-' }}</td>
                                            <td>{{ $detail['store_commercial_name'] !== '' ? $detail['store_commercial_name'] : '-' }}</td>
                                            <td>{{ $detail['portal'] !== '' ? $detail['portal'] : '-' }}</td>
                                            <td>{{ $detail['inclusion_reason'] }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="12">Sin citas imputables para este agente.</td></tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="is-hidden" data-commercial-detail-tab-panel="opportunities">
                            <div class="table-shell">
                                <table data-sortable-table="contact-center-opportunities-{{ $row['agent_key'] }}">
                                    <thead>
                                    <tr>
                                        <th data-sortable="true">Lead Id</th>
                                        <th data-sortable="true">Opportunity Id</th>
                                        <th data-sortable="true">Opportunity Name</th>
                                        <th data-sortable="true">Telefono</th>
                                        <th data-sortable="true">Fecha cita</th>
                                        <th data-sortable="true">Fecha oportunidad</th>
                                        <th data-sortable="true">Etapa</th>
                                        <th data-sortable="true">Owner</th>
                                        <th data-sortable="true">Delegacion</th>
                                        <th data-sortable="true">Tipo</th>
                                        <th data-sortable="true">Origen de cruce</th>
                                        <th class="num" data-sortable="true">Importe</th>
                                    </tr>
                                    </thead>
                                    <tbody data-sort-body="contact-center-opportunities-{{ $row['agent_key'] }}">
                                    @forelse ($row['details']['opportunities'] as $detail)
                                        <tr>
                                            <td>{{ $detail['lead_id'] }}</td>
                                            <td>{{ $detail['opportunity_id'] }}</td>
                                            <td>{{ $detail['opportunity_name'] !== '' ? $detail['opportunity_name'] : '-' }}</td>
                                            <td>{{ $detail['phone_normalized'] !== '' ? $detail['phone_normalized'] : '-' }}</td>
                                            <td>{{ $detail['capture_date'] ?? '-' }}</td>
                                            <td>{{ $detail['opportunity_created_date'] ?? '-' }}</td>
                                            <td>{{ $detail['stage_name'] !== '' ? $detail['stage_name'] : '-' }}</td>
                                            <td>{{ $detail['owner_name'] !== '' ? $detail['owner_name'] : '-' }}</td>
                                            <td>{{ $detail['delegation'] !== '' ? $detail['delegation'] : '-' }}</td>
                                            <td>{{ $detail['record_type_name'] !== '' ? $detail['record_type_name'] : '-' }}</td>
                                            <td>{{ $detail['link_origin'] }}</td>
                                            <td class="num">{{ number_format($detail['commission_amount'], 2, ',', '.') }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="12">Sin citas con oportunidad para este agente.</td></tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="is-hidden" data-commercial-detail-tab-panel="reservations">
                            <div class="table-shell">
                                <table data-sortable-table="contact-center-reservations-{{ $row['agent_key'] }}">
                                    <thead>
                                    <tr>
                                        <th data-sortable="true">Opportunity Id</th>
                                        <th data-sortable="true">Opportunity Name</th>
                                        <th data-sortable="true">Fecha reserva</th>
                                        <th data-sortable="true">Telefono</th>
                                        <th data-sortable="true">Matricula</th>
                                        <th data-sortable="true">Cuenta</th>
                                        <th data-sortable="true">Etapa</th>
                                        <th data-sortable="true">Portal</th>
                                        <th data-sortable="true">Pendiente contrato</th>
                                        <th data-sortable="true">Observaciones</th>
                                    </tr>
                                    </thead>
                                    <tbody data-sort-body="contact-center-reservations-{{ $row['agent_key'] }}">
                                    @forelse ($row['details']['reservations'] as $detail)
                                        <tr>
                                            <td>{{ $detail['opportunity_id'] }}</td>
                                            <td>{{ $detail['opportunity_name'] !== '' ? $detail['opportunity_name'] : '-' }}</td>
                                            <td>{{ $detail['reservation_date'] ?? '-' }}</td>
                                            <td>{{ $detail['phone_normalized'] !== '' ? $detail['phone_normalized'] : '-' }}</td>
                                            <td>{{ $detail['vehicle_plate'] !== '' ? $detail['vehicle_plate'] : '-' }}</td>
                                            <td>{{ $detail['account_name'] !== '' ? $detail['account_name'] : '-' }}</td>
                                            <td>{{ $detail['stage_name'] !== '' ? $detail['stage_name'] : '-' }}</td>
                                            <td>{{ $detail['portal'] !== '' ? $detail['portal'] : '-' }}</td>
                                            <td>{{ $detail['pending_contract'] ? 'Si' : 'No' }}</td>
                                            <td>{{ $detail['observations'] }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="10">Sin reservas detectadas para este agente.</td></tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="is-hidden" data-commercial-detail-tab-panel="sales">
                            <div class="table-shell">
                                <table data-sortable-table="contact-center-sales-{{ $row['agent_key'] }}">
                                    <thead>
                                    <tr>
                                        <th data-sortable="true">Opportunity Id</th>
                                        <th data-sortable="true">Opportunity Name</th>
                                        <th data-sortable="true">Telefono</th>
                                        <th data-sortable="true">Fecha firma</th>
                                        <th data-sortable="true">Stage</th>
                                        <th data-sortable="true">Owner</th>
                                        <th data-sortable="true">Delegacion</th>
                                        <th data-sortable="true">Vehiculo</th>
                                        <th data-sortable="true">Tipo</th>
                                        <th class="num" data-sortable="true">Venta</th>
                                        <th data-sortable="true">Extra ratio</th>
                                        <th data-sortable="true">Observaciones</th>
                                    </tr>
                                    </thead>
                                    <tbody data-sort-body="contact-center-sales-{{ $row['agent_key'] }}">
                                    @forelse ($row['details']['sales'] as $detail)
                                        <tr>
                                            <td>{{ $detail['opportunity_id'] }}</td>
                                            <td>{{ $detail['opportunity_name'] !== '' ? $detail['opportunity_name'] : '-' }}</td>
                                            <td>{{ $detail['phone_normalized'] !== '' ? $detail['phone_normalized'] : '-' }}</td>
                                            <td>{{ $detail['contract_signed_date'] ?? '-' }}</td>
                                            <td>{{ $detail['stage_name'] !== '' ? $detail['stage_name'] : '-' }}</td>
                                            <td>{{ $detail['owner_name'] !== '' ? $detail['owner_name'] : '-' }}</td>
                                            <td>{{ $detail['delegation'] !== '' ? $detail['delegation'] : '-' }}</td>
                                            <td>{{ $detail['vehicle_plate'] !== '' ? $detail['vehicle_plate'] : '-' }}</td>
                                            <td>{{ $detail['record_type_name'] !== '' ? $detail['record_type_name'] : '-' }}</td>
                                            <td class="num">{{ number_format($detail['sale_commission_amount'], 2, ',', '.') }}</td>
                                            <td>{{ $detail['ratio_bonus_applied'] ? 'Si' : 'No' }}</td>
                                            <td>{{ $detail['observations'] }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="12">Sin ventas imputadas para este agente.</td></tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="is-hidden" data-commercial-detail-tab-panel="incidents">
                            <div class="table-shell">
                                <table data-sortable-table="contact-center-incidents-{{ $row['agent_key'] }}">
                                    <thead>
                                    <tr>
                                        <th data-sortable="true">Tipo</th>
                                        <th data-sortable="true">Referencia Id</th>
                                        <th data-sortable="true">Referencia</th>
                                        <th data-sortable="true">Telefono</th>
                                        <th data-sortable="true">Fecha</th>
                                        <th data-sortable="true">Motivo</th>
                                    </tr>
                                    </thead>
                                    <tbody data-sort-body="contact-center-incidents-{{ $row['agent_key'] }}">
                                    @forelse ($row['details']['incidents'] as $detail)
                                        <tr>
                                            <td>{{ $detail['type'] }}</td>
                                            <td>{{ $detail['reference_id'] }}</td>
                                            <td>{{ $detail['reference_name'] !== '' ? $detail['reference_name'] : '-' }}</td>
                                            <td>{{ $detail['phone_normalized'] !== '' ? $detail['phone_normalized'] : '-' }}</td>
                                            <td>{{ $detail['event_date'] ?? '-' }}</td>
                                            <td>{{ $detail['reason'] }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="6">Sin incidencias asociadas a este agente.</td></tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </article>
            @endforeach
        </section>
    </section>
@else
    <div class="notice">No hay comisiones de Contact Center para el mes seleccionado con la informacion local sincronizada.</div>
@endif

@if ($contactCenterGlobalIncidents->isNotEmpty())
    <section class="card panel contact-center-global-incidents-panel">
        <div class="panel-title compact">
            <div>
                <h3>Incidencias globales</h3>
                <div class="small">Incidencias de datos detectadas durante el calculo automatico del Contact Center.</div>
            </div>
        </div>
        <div class="table-shell">
            <table data-sortable-table="contact-center-global-incidents">
                <thead>
                <tr>
                    <th data-sortable="true">Tipo</th>
                    <th data-sortable="true">Referencia Id</th>
                    <th data-sortable="true">Referencia</th>
                    <th data-sortable="true">Telefono</th>
                    <th data-sortable="true">Fecha</th>
                    <th data-sortable="true">Motivo</th>
                </tr>
                </thead>
                <tbody data-sort-body="contact-center-global-incidents">
                @foreach ($contactCenterGlobalIncidents as $incident)
                    <tr>
                        <td>{{ $incident['type'] }}</td>
                        <td>{{ $incident['reference_id'] }}</td>
                        <td>{{ $incident['reference_name'] !== '' ? $incident['reference_name'] : '-' }}</td>
                        <td>{{ $incident['phone_normalized'] !== '' ? $incident['phone_normalized'] : '-' }}</td>
                        <td>{{ $incident['event_date'] ?? '-' }}</td>
                        <td>{{ $incident['reason'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif
