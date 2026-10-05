@php
    /** @var array<string, mixed> $summary */
    $metrics = $summary['metrics'];
    $alerts = $summary['alerts'];
@endphp

<x-reports.app-shell
    title="Resumen Ejecutivo"
    current-report="summary"
    :updated-badge-text="$summary['cutoff']['display_label']"
>
    <div class="wrap">
        <main>
            <x-reports.ui.page-header
                eyebrow="Visión estratégica"
                title="Resumen Ejecutivo"
                description="Situación global del último día cerrado y contexto acumulado del mes para Leads, Reservas y Ventas."
            >
                <x-slot:actions>
                    <span class="report-ui-badge">{{ $summary['cutoff']['display_label'] }}</span>
                    <span class="report-ui-badge">MTD: {{ $summary['mtd_period']['display_range'] }}</span>
                </x-slot:actions>
            </x-reports.ui.page-header>

            <section class="report-ui-card report-ui-card--muted" aria-labelledby="executive-data-health-title">
                <div class="report-ui-data-panel__body">
                    <x-reports.ui.section-header
                        title="Estado general de datos"
                        description="La salud del dato se mantiene separada de la situación de negocio."
                    />
                    <div class="report-ui-kpi-strip" aria-labelledby="executive-data-health-title">
                        <div class="report-ui-kpi-strip__item">
                            <div class="report-ui-kpi-strip__label">Evaluabilidad</div>
                            <div class="report-ui-kpi-strip__value" id="executive-data-health-title">
                                {{ $summary['data_health_summary']['headline'] }}
                            </div>
                            <div class="report-ui-kpi-strip__meta">
                                {{ $summary['data_health_summary']['all_evaluable'] ? 'Todas las métricas son evaluables.' : 'Hay métricas no evaluables o con cobertura incompleta.' }}
                            </div>
                        </div>
                        <div class="report-ui-kpi-strip__item">
                            <div class="report-ui-kpi-strip__label">Actualizado</div>
                            <div class="report-ui-kpi-strip__value">{{ $summary['data_health_summary']['counts']['actualizado'] }}</div>
                            <div class="report-ui-kpi-strip__meta">Métricas con cobertura completa</div>
                        </div>
                        <div class="report-ui-kpi-strip__item">
                            <div class="report-ui-kpi-strip__label">Parcial / desactualizado</div>
                            <div class="report-ui-kpi-strip__value">
                                {{ $summary['data_health_summary']['counts']['parcial'] + $summary['data_health_summary']['counts']['desactualizado'] }}
                            </div>
                            <div class="report-ui-kpi-strip__meta">Requieren revisar cobertura</div>
                        </div>
                        <div class="report-ui-kpi-strip__item">
                            <div class="report-ui-kpi-strip__label">Incidencias</div>
                            <div class="report-ui-kpi-strip__value">{{ $summary['data_health_summary']['counts']['incidencia'] }}</div>
                            <div class="report-ui-kpi-strip__meta">No generan alerta de negocio por sí mismas</div>
                        </div>
                    </div>
                </div>
            </section>

            <section aria-labelledby="executive-kpis-title">
                <x-reports.ui.section-header
                    title="KPIs principales"
                    description="Último día cerrado frente al baseline semanal; el MTD se muestra solo como contexto."
                />
                <div class="report-ui-kpi-strip" id="executive-kpis-title">
                    @foreach ($metrics as $metric)
                        <article class="report-ui-kpi-strip__item" aria-labelledby="executive-metric-{{ $metric['metric_key'] }}">
                            <div class="report-ui-kpi-strip__label" id="executive-metric-{{ $metric['metric_key'] }}">{{ $metric['label'] }}</div>
                            <div class="report-ui-kpi-strip__value">{{ $metric['display']['current'] }}</div>
                            <div class="report-ui-kpi-strip__meta">Baseline: {{ $metric['display']['baseline'] }}</div>
                            <div class="report-ui-kpi-strip__meta">Variación: {{ $metric['display']['variation_percent'] }}</div>
                            <div class="report-ui-kpi-strip__meta">MTD: {{ $metric['display']['mtd'] }}</div>
                            @if ($metric['display']['d364_reference'] !== '-')
                                <div class="report-ui-kpi-strip__meta">D-364 orientativo: {{ $metric['display']['d364_reference'] }}</div>
                            @endif
                            <div class="report-ui-kpi-strip__meta">
                                <x-reports.ui.status :state="$metric['status']['state']" :label="$metric['status']['label']" />
                                <span class="report-ui-badge">{{ $metric['direction']['label'] }}</span>
                                <x-reports.ui.status :state="$metric['data_health']['state']" :label="$metric['data_health']['label']" />
                            </div>
                            @unless ($metric['evaluable'])
                                <div class="report-ui-kpi-strip__meta">
                                    {{ $metric['reason_summaries'][0] ?? 'No evaluable con la cobertura disponible.' }}
                                </div>
                            @endunless
                        </article>
                    @endforeach
                </div>
            </section>

            <section aria-labelledby="executive-alerts-title">
                <x-reports.ui.section-header
                    title="Alertas prioritarias"
                    description="Solo se muestran alertas de negocio emitidas por el motor ejecutivo."
                />

                @if ($alerts === [])
                    <x-reports.ui.empty-state
                        kicker="Sin alertas"
                        title="No hay alertas de negocio activas para el último día evaluable."
                        title-id="executive-alerts-title"
                        description="Este estado no implica que todas las métricas estén completas; revisa la cobertura y calidad del dato."
                    />
                @else
                    <div class="report-ui-data-panel">
                        <div class="report-ui-data-panel__body">
                            @foreach ($alerts as $alert)
                                <article class="report-ui-card report-ui-card--muted">
                                    <div class="report-ui-data-panel__body">
                                        <h3>{{ $alert['label'] }}</h3>
                                        <p>
                                            <x-reports.ui.status :state="$alert['status']['state']" :label="$alert['status']['label']" />
                                            <span class="report-ui-badge">{{ $alert['direction']['label'] }}</span>
                                        </p>
                                        <p>
                                            Actual: {{ $alert['display']['current'] }} ·
                                            Baseline: {{ $alert['display']['baseline'] }} ·
                                            Variación: {{ $alert['display']['variation_percent'] }} ·
                                            Diferencia: {{ $alert['display']['absolute_difference'] }}
                                        </p>
                                        @if (filled($alert['confirmed_cause']))
                                            <p>Causa confirmada: {{ $alert['confirmed_cause'] }}</p>
                                        @else
                                            <p>Revisión requerida: no hay causa confirmada en el contrato ejecutivo V1.</p>
                                        @endif
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    </div>
                @endif
            </section>

            <section aria-labelledby="executive-coverage-title">
                <x-reports.ui.section-header
                    title="Cobertura y calidad"
                    description="Resumen secundario de salud técnica por métrica, sin identificadores de sincronización ni payloads de origen."
                />
                <div class="report-ui-data-panel">
                    <div class="report-ui-data-panel__scroll" tabindex="0">
                        <table class="report-ui-table">
                            <thead>
                                <tr>
                                    <th scope="col" id="executive-coverage-title">Métrica</th>
                                    <th scope="col">Salud</th>
                                    <th scope="col">Día completo</th>
                                    <th scope="col">Valor bruto</th>
                                    <th scope="col">MTD</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($metrics as $metric)
                                    <tr>
                                        <th scope="row">{{ $metric['label'] }}</th>
                                        <td><x-reports.ui.status :state="$metric['data_health']['state']" :label="$metric['data_health']['label']" /></td>
                                        <td>{{ $metric['day_complete'] ? 'Sí' : 'No' }}</td>
                                        <td>{{ $metric['coverage']['current']['raw_value'] ?? '—' }}</td>
                                        <td>{{ $metric['display']['mtd'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </main>
    </div>
</x-reports.app-shell>
