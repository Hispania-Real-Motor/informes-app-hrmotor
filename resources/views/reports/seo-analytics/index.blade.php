<x-reports.app-shell title="SEO y Analytics" current-report="seo-analytics">
    <div class="wrap">
        <main>
            <x-reports.ui.page-header
                eyebrow="Marketing estratégico"
                title="SEO y Analytics"
                description="Visibilidad orgánica de Search Console e Intereses orgánicos registrados en Salesforce."
            >
                @if ($canManageAnalyticalRules || in_array($section, ['summary', 'traffic', 'search'], true))
                    <x-slot:actions>
                        @if ($canManageAnalyticalRules)
                            <a class="report-ui-button report-ui-button--secondary" href="{{ route('reports.seo-analytics.settings.index') }}">Configurar evaluación</a>
                        @endif
                        @if (in_array($section, ['summary', 'traffic', 'search'], true))
                            <form method="GET" action="{{ route('reports.seo-analytics.index') }}" class="report-ui-filter-bar">
                                <input type="hidden" name="section" value="{{ $section }}">
                                <label class="report-ui-field">
                                    <span class="report-ui-label">Periodo cerrado</span>
                                    <select class="report-ui-select" name="range">
                                        @foreach ($ranges as $rangeOption)
                                            <option value="{{ $rangeOption }}" @selected($range === $rangeOption)>{{ $rangeOption }} días</option>
                                        @endforeach
                                    </select>
                                </label>
                                <button class="report-ui-button report-ui-button--secondary" type="submit">Aplicar</button>
                            </form>
                        @endif
                    </x-slot:actions>
                @endif
            </x-reports.ui.page-header>

            <nav class="report-ui-tabs" aria-label="Secciones SEO y Analytics">
                @foreach ($sections as $sectionKey => $sectionLabel)
                    <a
                        href="{{ route('reports.seo-analytics.index', ['section' => $sectionKey, 'range' => $range]) }}"
                        class="report-ui-tab {{ $section === $sectionKey ? 'is-active' : '' }}"
                        @if ($section === $sectionKey) aria-current="page" @endif
                    >{{ $sectionLabel }}</a>
                @endforeach
            </nav>

            <section class="report-ui-data-panel" aria-label="Estado de las fuentes" style="margin-top: var(--report-ui-space-4)">
                <div class="report-ui-data-panel__body">
                    @foreach ($sources as $source)
                        <x-reports.ui.source-status :title="$source['title']" :detail="$source['detail']" :data-source="$source['key']">
                            <x-slot:indicator><span class="report-ui-badge">{{ $source['badge'] }}</span></x-slot:indicator>
                        </x-reports.ui.source-status>
                    @endforeach
                </div>
            </section>

            @if (in_array($section, ['summary', 'traffic'], true) && $common_period['end'])
                <p class="report-ui-help" style="margin: var(--report-ui-space-3) 0">
                    Periodo común cerrado: {{ $common_period['start'] }} — {{ $common_period['end'] }}.
                </p>
            @elseif ($section === 'search' && $search_console_period['end'])
                <p class="report-ui-help" style="margin: var(--report-ui-space-3) 0">
                    Periodo Search Console: {{ $search_console_period['start'] }} — {{ $search_console_period['end'] }}.
                </p>
            @endif

            @if ($section === 'summary')
                @if (! $has_search_console && ! $has_salesforce && ! $has_ga4)
                    <x-reports.ui.empty-state
                        kicker="Pendiente de sincronización"
                        title="Todavía no hay métricas SEO persistidas"
                        description="Las fuentes se sincronizan mediante CLI y scheduler; abrir esta pantalla nunca ejecuta llamadas externas."
                    />
                @else
                    <section class="report-ui-kpi-strip" aria-label="Indicadores SEO">
                        @foreach ([
                            ['Clicks orgánicos', $kpis['spain']['clicks'], null],
                            ['Impresiones orgánicas', $kpis['spain']['impressions'], null],
                            ['CTR', $kpis['spain']['ctr'], 'percent'],
                            ['Posición media', $kpis['spain']['position'], 'decimal'],
                            ['Interés orgánico (Salesforce)', $kpis['salesforce_interests'], null],
                            ['Conversiones web orgánicas (GA4)', $kpis['ga4_key_events'], 'ga4'],
                        ] as [$label, $value, $format])
                            <div class="report-ui-kpi-strip__item">
                                <div class="report-ui-kpi-strip__label">{{ $label }}</div>
                                <div class="report-ui-kpi-strip__value">
                                    @if ($value === null) —
                                    @elseif ($format === 'percent') {{ number_format($value * 100, 2, ',', '.') }}%
                                    @elseif ($format === 'decimal') {{ number_format($value, 2, ',', '.') }}
                                    @elseif ($format === 'ga4') {{ number_format($value, 2, ',', '.') }}
                                    @else {{ number_format($value, 0, ',', '.') }}
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </section>

                    <div class="report-ui-data-panel" style="margin-top: var(--report-ui-space-4)">
                        <div class="report-ui-data-panel__header"><x-reports.ui.section-header title="Tráfico de marca en España" description="Segmentación descriptiva basada exclusivamente en las variantes de marca configuradas." /></div>
                        <div class="report-ui-data-panel__body">
                            <p class="report-ui-help">La segmentación Marca/No marca puede no sumar el total de España porque Search Console excluye consultas anonimizadas al aplicar filtros de búsqueda.</p>
                        </div>
                        <div class="report-ui-data-panel__scroll" tabindex="0" aria-label="Tráfico de marca en España">
                            <table class="report-ui-table">
                                <thead><tr><th scope="col">Segmento</th><th scope="col" class="report-ui-table__numeric">Clicks</th><th scope="col" class="report-ui-table__numeric">Impresiones</th><th scope="col" class="report-ui-table__numeric">CTR</th><th scope="col" class="report-ui-table__numeric">Posición</th></tr></thead>
                                <tbody>
                                    @foreach (['brand' => 'Marca', 'non_brand' => 'No marca'] as $key => $label)
                                        <tr><td>{{ $label }}</td><td class="report-ui-table__numeric">{{ $segments[$key]['clicks'] === null ? '—' : number_format($segments[$key]['clicks'], 0, ',', '.') }}</td><td class="report-ui-table__numeric">{{ $segments[$key]['impressions'] === null ? '—' : number_format($segments[$key]['impressions'], 0, ',', '.') }}</td><td class="report-ui-table__numeric">{{ $segments[$key]['ctr'] === null ? '—' : number_format($segments[$key]['ctr'] * 100, 2, ',', '.').'%' }}</td><td class="report-ui-table__numeric">{{ $segments[$key]['position'] === null ? '—' : number_format($segments[$key]['position'], 2, ',', '.') }}</td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="report-ui-data-panel" style="margin-top: var(--report-ui-space-4)">
                        <div class="report-ui-data-panel__header"><x-reports.ui.section-header title="España y resto del mundo" /></div>
                        <div class="report-ui-data-panel__scroll" tabindex="0" aria-label="España y resto del mundo">
                            <table class="report-ui-table"><thead><tr><th scope="col">Ámbito</th><th scope="col" class="report-ui-table__numeric">Clicks</th><th scope="col" class="report-ui-table__numeric">Impresiones</th><th scope="col" class="report-ui-table__numeric">CTR</th><th scope="col" class="report-ui-table__numeric">Posición</th></tr></thead><tbody>
                                @foreach (['spain' => 'España', 'rest' => 'Resto del mundo'] as $key => $label)
                                    <tr class="{{ $key === 'spain' ? 'report-ui-table-row--highlight' : '' }}"><td>{{ $label }}</td><td class="report-ui-table__numeric">{{ $geography[$key]['clicks'] === null ? '—' : number_format($geography[$key]['clicks'], 0, ',', '.') }}</td><td class="report-ui-table__numeric">{{ $geography[$key]['impressions'] === null ? '—' : number_format($geography[$key]['impressions'], 0, ',', '.') }}</td><td class="report-ui-table__numeric">{{ $geography[$key]['ctr'] === null ? '—' : number_format($geography[$key]['ctr'] * 100, 2, ',', '.').'%' }}</td><td class="report-ui-table__numeric">{{ $geography[$key]['position'] === null ? '—' : number_format($geography[$key]['position'], 2, ',', '.') }}</td></tr>
                                @endforeach
                            </tbody></table>
                        </div>
                    </div>
                @endif

                <section class="report-ui-data-panel" style="margin-top: var(--report-ui-space-4)" aria-label="Comparativa diaria SEO">
                    <div class="report-ui-data-panel__header">
                        <x-reports.ui.section-header
                            title="Comparativa diaria"
                            description="Último día cerrado de cada fuente frente al mismo día de la semana de las cuatro semanas anteriores. Cada fuente puede tener una fecha cerrada diferente; el selector de periodo no modifica esta comparativa."
                        />
                    </div>
                    @if ($analytical_comparisons === [])
                        <div class="report-ui-data-panel__body">
                            <x-reports.ui.empty-state
                                title="Sin snapshots comparativos"
                                description="La comparativa estará disponible después de ejecutar su construcción programada con datos diarios locales."
                            />
                        </div>
                    @else
                        <div class="report-ui-data-panel__scroll" tabindex="0" aria-label="Comparativa diaria de métricas SEO">
                            <table class="report-ui-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Métrica</th>
                                        <th scope="col">Dato cerrado</th>
                                        <th scope="col" class="report-ui-table__numeric">Actual</th>
                                        <th scope="col" class="report-ui-table__numeric">Referencia semanal</th>
                                        <th scope="col" class="report-ui-table__numeric">Diferencia</th>
                                        <th scope="col" class="report-ui-table__numeric">Variación</th>
                                        <th scope="col" class="report-ui-table__numeric">D-364</th>
                                        <th scope="col">Cobertura</th>
                                        <th scope="col">Estado</th>
                                        <th scope="col">Lectura</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($analytical_comparisons as $comparison)
                                        <tr>
                                            <td>
                                                {{ $comparison['label'] }}
                                                <div class="report-ui-help">{{ $comparison['source'] }} · {{ $comparison['scope'] }}</div>
                                            </td>
                                            <td>{{ $comparison['data_date'] }}</td>
                                            <td class="report-ui-table__numeric">{{ $comparison['current'] }}</td>
                                            <td class="report-ui-table__numeric">{{ $comparison['baseline'] }}</td>
                                            <td class="report-ui-table__numeric">{{ $comparison['absolute_change'] }}</td>
                                            <td class="report-ui-table__numeric">
                                                @if ($comparison['baseline_is_zero'])
                                                    <span aria-label="La variación porcentual no se calcula cuando la referencia es cero." title="La variación porcentual no se calcula cuando la referencia es cero.">—</span>
                                                @else
                                                    {{ $comparison['relative_change'] }}
                                                @endif
                                            </td>
                                            <td class="report-ui-table__numeric">{{ $comparison['d364'] }}</td>
                                            <td><span class="report-ui-badge">{{ $comparison['coverage'] }}</span></td>
                                            <td><x-reports.ui.status :state="$comparison['status']" /></td>
                                            <td>
                                                {{ $comparison['reading'] }}
                                                @if ($comparison['rule_version'])
                                                    <div class="report-ui-help">{{ $comparison['direction_label'] }} · {{ $comparison['rule_version'] }}</div>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>

                <section class="report-ui-data-panel" style="margin-top: var(--report-ui-space-4)" aria-label="Señales analíticas recientes">
                    <div class="report-ui-data-panel__header">
                        <x-reports.ui.section-header title="Señales recientes" description="Últimos 30 días disponibles. Se muestra una sola interpretación vigente por snapshot y se omiten estados Correcto para reducir ruido." />
                    </div>
                    @if ($analytical_signals === [])
                        <div class="report-ui-data-panel__body"><x-reports.ui.empty-state title="Sin señales recientes" description="No existen evaluaciones no ordinarias para las fuentes y properties actuales." /></div>
                    @else
                        <div class="report-ui-data-panel__scroll" tabindex="0" aria-label="Histórico reciente de señales analíticas SEO">
                            <table class="report-ui-table">
                                <thead><tr><th scope="col">Fecha</th><th scope="col">Métrica</th><th scope="col">Estado</th><th scope="col">Dirección</th><th scope="col" class="report-ui-table__numeric">Actual</th><th scope="col" class="report-ui-table__numeric">Referencia</th><th scope="col" class="report-ui-table__numeric">Variación</th><th scope="col">Versión</th></tr></thead>
                                <tbody>
                                    @foreach ($analytical_signals as $signal)
                                        <tr>
                                            <td>{{ $signal['data_date'] }}</td>
                                            <td>{{ $signal['metric'] }}<div class="report-ui-help">{{ $signal['reading'] }}</div></td>
                                            <td><x-reports.ui.status :state="$signal['status']" /></td>
                                            <td>{{ $signal['direction_label'] }}</td>
                                            <td class="report-ui-table__numeric">{{ $signal['current'] }}</td>
                                            <td class="report-ui-table__numeric">{{ $signal['baseline'] }}</td>
                                            <td class="report-ui-table__numeric">{{ $signal['variation'] }}</td>
                                            <td><span class="report-ui-badge">{{ $signal['rule_version'] }}</span></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            @elseif ($section === 'traffic')
                <div class="report-ui-data-panel"><div class="report-ui-data-panel__header"><x-reports.ui.section-header title="Tráfico y conversión" description="Search Console, Interés orgánico Salesforce y Conversiones web orgánicas GA4 son fuentes distintas y no se suman." /></div>
                    @if ($daily === []) <div class="report-ui-data-panel__body"><x-reports.ui.empty-state title="Sin serie diaria sincronizada" description="Las fuentes se cargan exclusivamente mediante sus comandos y scheduler." /></div>
                    @else <div class="report-ui-data-panel__scroll" tabindex="0" aria-label="Tráfico orgánico diario"><table class="report-ui-table report-ui-table--sticky-header"><thead><tr><th scope="col">Fecha</th><th scope="col" class="report-ui-table__numeric">Clicks España</th><th scope="col" class="report-ui-table__numeric">Impresiones España</th><th scope="col" class="report-ui-table__numeric">CTR</th><th scope="col" class="report-ui-table__numeric">Posición</th><th scope="col" class="report-ui-table__numeric">Interés orgánico Salesforce</th><th scope="col" class="report-ui-table__numeric">Conversiones web orgánicas GA4</th></tr></thead><tbody>
                        @foreach ($daily as $row)<tr><td>{{ $row['date'] }}</td><td class="report-ui-table__numeric">{{ $row['clicks'] ?? '—' }}</td><td class="report-ui-table__numeric">{{ $row['impressions'] ?? '—' }}</td><td class="report-ui-table__numeric">{{ $row['ctr'] === null ? '—' : number_format($row['ctr'] * 100, 2, ',', '.').'%' }}</td><td class="report-ui-table__numeric">{{ $row['position'] === null ? '—' : number_format($row['position'], 2, ',', '.') }}</td><td class="report-ui-table__numeric">{{ $row['interests'] ?? '—' }}</td><td class="report-ui-table__numeric">{{ $row['ga4_key_events'] === null ? '—' : number_format($row['ga4_key_events'], 2, ',', '.') }}</td></tr>@endforeach
                    </tbody></table></div>@endif
                </div>
                <div class="report-ui-data-panel" style="margin-top: var(--report-ui-space-4)">
                    <div class="report-ui-data-panel__header"><x-reports.ui.section-header title="Conversiones web orgánicas (GA4)" description="Key Events atribuidos a Organic Search en plataforma web. No se suman a Interés orgánico Salesforce." /></div>
                    <div class="report-ui-data-panel__body">
                        <p class="report-ui-help">
                            España: {{ $ga4['spain']['key_events'] === null ? '—' : number_format($ga4['spain']['key_events'], 2, ',', '.') }} ·
                            Global: {{ $ga4['global']['key_events'] === null ? '—' : number_format($ga4['global']['key_events'], 2, ',', '.') }} ·
                            Resto: {{ $ga4['rest']['key_events'] === null ? '—' : number_format($ga4['rest']['key_events'], 2, ',', '.') }}
                        </p>
                    </div>
                    @if ($ga4['events']->isEmpty())
                        <div class="report-ui-data-panel__body"><x-reports.ui.empty-state title="Sin detalle de Key Events para el periodo" /></div>
                    @else
                        <div class="report-ui-data-panel__scroll" tabindex="0" aria-label="Conversiones web orgánicas por evento clave">
                            <table class="report-ui-table">
                                <thead><tr><th scope="col">Evento clave</th><th scope="col" class="report-ui-table__numeric">Conversiones atribuidas</th></tr></thead>
                                <tbody>@foreach ($ga4['events'] as $event)<tr><td>{{ $event->event_name }}</td><td class="report-ui-table__numeric">{{ number_format((float) $event->key_events, 2, ',', '.') }}</td></tr>@endforeach</tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @elseif ($section === 'search')
                @foreach ([['Principales búsquedas en España', $queries, 'query'], ['Principales páginas en España', $pages, 'page']] as [$title, $rows, $kind])
                    <div class="report-ui-data-panel" style="margin-bottom: var(--report-ui-space-4)"><div class="report-ui-data-panel__header"><x-reports.ui.section-header :title="$title" description="Principales resultados disponibles; no representan necesariamente todas las filas de Search Console." /></div>
                        @if ($rows->isEmpty()) <div class="report-ui-data-panel__body"><x-reports.ui.empty-state title="Sin ranking sincronizado" /></div>
                        @else <div class="report-ui-data-panel__scroll" tabindex="0" aria-label="{{ $title }}"><table class="report-ui-table"><thead><tr><th scope="col">{{ $kind === 'query' ? 'Consulta' : 'Página' }}</th>@if ($kind === 'query')<th scope="col">Tipo</th>@endif<th scope="col" class="report-ui-table__numeric">Clicks</th><th scope="col" class="report-ui-table__numeric">Impresiones</th><th scope="col" class="report-ui-table__numeric">CTR</th><th scope="col" class="report-ui-table__numeric">Posición</th></tr></thead><tbody>
                            @foreach ($rows as $row)<tr><td title="{{ $row->dimension_value }}">{{ $row->dimension_value }}</td>@if ($kind === 'query')<td><span class="report-ui-badge">{{ $row->brand_segment === 'brand' ? 'Marca' : 'No marca' }}</span></td>@endif<td class="report-ui-table__numeric">{{ number_format($row->clicks, 0, ',', '.') }}</td><td class="report-ui-table__numeric">{{ number_format($row->impressions, 0, ',', '.') }}</td><td class="report-ui-table__numeric">{{ $row->ctr === null ? '—' : number_format((float) $row->ctr * 100, 2, ',', '.').'%' }}</td><td class="report-ui-table__numeric">{{ $row->position === null ? '—' : number_format((float) $row->position, 2, ',', '.') }}</td></tr>@endforeach
                        </tbody></table></div>@endif
                    </div>
                @endforeach
                <details class="report-ui-data-panel"><summary class="report-ui-data-panel__header">Principales países</summary><div class="report-ui-data-panel__scroll" tabindex="0" aria-label="Principales países"><table class="report-ui-table"><thead><tr><th scope="col">País</th><th scope="col" class="report-ui-table__numeric">Clicks</th><th scope="col" class="report-ui-table__numeric">Impresiones</th></tr></thead><tbody>@foreach ($countries as $row)<tr class="{{ strtoupper($row->dimension_value) === 'ESP' ? 'report-ui-table-row--highlight' : '' }}"><td>{{ $row->dimension_value }}</td><td class="report-ui-table__numeric">{{ $row->clicks }}</td><td class="report-ui-table__numeric">{{ $row->impressions }}</td></tr>@endforeach</tbody></table></div></details>
            @elseif ($section === 'health')
                @if (! $health['available'])
                    <x-reports.ui.empty-state
                        kicker="Salud técnica"
                        title="Sin comprobaciones técnicas disponibles"
                        :description="$health['source']['detail']"
                    />
                @else
                    <section class="report-ui-kpi-strip" aria-label="Resumen de salud técnica SEO">
                        @foreach ([
                            ['URLs monitorizadas', data_get($health, 'stats.checked_urls', 0)],
                            ['HTTP 2xx', data_get($health, 'stats.http_2xx', 0)],
                            ['Con redirección', data_get($health, 'stats.redirected_urls', 0)],
                            ['HTTP 4xx/5xx', data_get($health, 'stats.http_4xx', 0) + data_get($health, 'stats.http_5xx', 0)],
                            ['Noindex', data_get($health, 'stats.noindex_urls', 0)],
                            ['Errores de red', data_get($health, 'stats.network_errors', 0)],
                        ] as [$label, $value])
                            <div class="report-ui-kpi-strip__item">
                                <div class="report-ui-kpi-strip__label">{{ $label }}</div>
                                <div class="report-ui-kpi-strip__value">{{ number_format((int) $value, 0, ',', '.') }}</div>
                            </div>
                        @endforeach
                    </section>

                    <section class="report-ui-data-panel" style="margin-top: var(--report-ui-space-4)">
                        <div class="report-ui-data-panel__header">
                            <x-reports.ui.section-header title="Infraestructura de rastreo" description="Comprobaciones descriptivas; no equivalen a una evaluación completa de indexabilidad." />
                        </div>
                        <div class="report-ui-data-panel__scroll" tabindex="0" aria-label="Infraestructura de rastreo SEO">
                            <table class="report-ui-table">
                                <thead><tr><th scope="col">Elemento</th><th scope="col">Resultado</th></tr></thead>
                                <tbody>
                                    <tr><td>Sitio configurado</td><td>{{ data_get($health, 'stats.site_host', '—') }}</td></tr>
                                    <tr><td>robots.txt HTTP</td><td>{{ data_get($health, 'stats.robots_status') ?? data_get($health, 'stats.robots_error_code', '—') }}</td></tr>
                                    <tr><td>Sitemaps declarados/configurados</td><td>{{ data_get($health, 'stats.sitemap_sources', 0) }}</td></tr>
                                    <tr><td>Documentos sitemap comprobados</td><td>{{ data_get($health, 'stats.sitemap_documents_checked', 0) }}</td></tr>
                                    <tr><td>Scan sitemap</td><td>@if (data_get($health, 'stats.sitemap_scan_complete') === true) Completo @elseif (data_get($health, 'stats.sitemap_scan_complete') === false) Parcial @else No disponible @endif</td></tr>
                                    <tr><td>Última comprobación</td><td>{{ data_get($health, 'stats.check_date', '—') }}</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="report-ui-data-panel" style="margin-top: var(--report-ui-space-4)">
                        <div class="report-ui-data-panel__header">
                            <x-reports.ui.section-header title="URLs monitorizadas" description="Hechos técnicos ordenados por prioridad de revisión; no constituyen scoring analítico." />
                        </div>
                        @if ($health['rows']->isEmpty())
                            <div class="report-ui-data-panel__body"><x-reports.ui.empty-state title="Sin resultados URL para la última comprobación" /></div>
                        @else
                            <div class="report-ui-data-panel__scroll" tabindex="0" aria-label="URLs monitorizadas por salud técnica SEO">
                                <table class="report-ui-table report-ui-table--sticky-header">
                                    <thead><tr><th scope="col">URL</th><th scope="col">Origen</th><th scope="col">HTTP</th><th scope="col">Redirecciones</th><th scope="col">Noindex</th><th scope="col">Canonical</th><th scope="col">Sitemap</th><th scope="col">Tiempo</th><th scope="col">Comprobado</th></tr></thead>
                                    <tbody>
                                        @foreach ($health['rows'] as $row)
                                            <tr>
                                                <td title="{{ $row->url }}">{{ $row->url }}</td>
                                                <td>@if ($row->is_strategic && $row->is_search_console) Estratégica + Search Console @elseif ($row->is_strategic) Estratégica @else Search Console @endif</td>
                                                <td>{{ $row->error_code ?: ($row->http_status ?? '—') }}</td>
                                                <td>{{ $row->redirect_count }}</td>
                                                <td>{{ $row->has_noindex === null ? '—' : ($row->has_noindex ? 'Sí' : 'No') }}</td>
                                                <td>@if ($row->canonical_count > 1) Múltiple @elseif ($row->body_truncated) — @elseif ($row->canonical_count === 0) Ausente @elseif ($row->canonical_matches_final === true) Coincide @elseif ($row->canonical_matches_final === false) Distinta @else — @endif</td>
                                                <td>{{ $row->in_sitemap === null ? '—' : ($row->in_sitemap ? 'Sí' : 'No') }}</td>
                                                <td>{{ $row->response_time_ms === null ? '—' : $row->response_time_ms.' ms' }}</td>
                                                <td>{{ $row->checked_at }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            @if ($health['total_count'] > $health['visible_count'])
                                <div class="report-ui-data-panel__body"><p class="report-ui-help">Mostrando {{ $health['visible_count'] }} de {{ $health['total_count'] }} URLs.</p></div>
                            @endif
                        @endif
                    </section>
                @endif
            @else
                <x-reports.ui.empty-state kicker="GEO / IA" title="SISTRIX AI Check pendiente" description="La configuración puede diagnosticarse por CLI; esta pantalla no consume créditos ni ejecuta AI Check." />
            @endif
        </main>
    </div>
</x-reports.app-shell>
