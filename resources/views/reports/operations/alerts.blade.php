<x-reports.app-shell title="Alertas operativas" current-admin-page="operational-alerts">
<div class="wrap">
    <main>
        <x-reports.ui.page-header
            eyebrow="Administración"
            title="Alertas operativas"
            description="Salud de procesos e incidencias persistentes, sin credenciales ni respuestas externas."
        />

        <section class="report-ui-data-panel" aria-labelledby="process-health-title">
            <div class="report-ui-data-panel__body">
                <h2 id="process-health-title">Salud de procesos / Sincronizaciones</h2>
                <p>Actualizado: {{ $updatedAt->format('d/m/Y H:i:s T') }}.
                    <a class="report-ui-button report-ui-button--secondary" href="{{ request()->fullUrl() }}">Actualizar estado</a>
                </p>
                <p>Los estados se basan en evidencia persistente. El fin de un comando no certifica por sí solo la readiness del dato.</p>
                @if (session('operation_status'))
                    <p role="status">{{ session('operation_status') }}</p>
                @endif
                @if ($errors->any())
                    <p role="alert">Solicitud no válida. Revise la tarea y la confirmación.</p>
                @endif
            </div>
            <div class="report-ui-table-shell" tabindex="0" aria-label="Estado de procesos; tabla desplazable">
                <table class="report-ui-table">
                    <thead><tr>
                        <th scope="col">Tarea / módulo</th><th scope="col">Periodicidad Madrid</th>
                        <th scope="col">Estado</th><th scope="col">Última ejecución</th>
                        <th scope="col">Último éxito</th><th scope="col">Último fallo</th>
                        <th scope="col">Duración / origen</th><th scope="col">Ventana / próxima</th>
                        <th scope="col">Acción</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($processes as $key => $process)
                        @php
                            $task = $process['task'];
                            $labels = ['never' => 'Nunca ejecutado', 'queued' => 'En cola', 'running' => 'Ejecutándose', 'success' => 'Correcto', 'failed' => 'Fallido', 'stale' => 'Retrasado', 'unhealthy' => 'Incidencias de salud', 'unknown' => 'Desconocido / retrasado', 'skipped' => 'Omitido / solapamiento evitado'];
                            $visual = match ($process['status']) { 'success' => 'ok', 'failed' => 'critical', 'stale', 'unhealthy' => 'deviation', 'queued', 'running' => 'observation', default => 'not-evaluable' };
                            $date = fn ($value) => $value?->setTimezone('Europe/Madrid')->format('d/m H:i:s T') ?? 'Sin evidencia';
                        @endphp
                        <tr>
                            <th scope="row">{{ $task['label'] }}<br><span class="report-ui-badge">{{ $task['module'] }}</span></th>
                            <td><code>{{ $task['cron'] }}</code><br>Margen: {{ $task['grace_minutes'] }} min</td>
                            <td><x-reports.ui.status :state="$visual" :label="$labels[$process['status']] ?? 'Desconocido'" /></td>
                            <td>{{ $date($process['last']?->started_at ?? $process['last']?->queued_at) }}<br>{{ $process['last']?->summary }}</td>
                            <td>{{ $date($process['success']?->finished_at) }}</td>
                            <td>{{ $date($process['failure']?->finished_at) }}</td>
                            <td>{{ $process['last']?->duration_ms !== null ? number_format($process['last']->duration_ms / 1000, 1, ',', '.').' s' : 'Sin evidencia' }}<br>{{ $process['last']?->origin ?? 'Sin evidencia' }}</td>
                            <td>Última exigible: {{ $date($process['due']) }}<br>Próxima: {{ $date($process['next']) }}<br>Ventana: {{ $task['window_minutes'] }} min</td>
                            <td>
                                @if ($task['manual_allowed'])
                                    @if ($process['active'])
                                        <button class="report-ui-button" disabled>En ejecución / en cola</button>
                                    @else
                                        <form method="POST" action="{{ route('reports.operational-alerts.execute') }}">
                                            @csrf
                                            <input type="hidden" name="task_key" value="{{ $key }}">
                                            <label class="report-ui-label">
                                                <input type="checkbox" name="confirmed" value="1" required>
                                                Confirmo ejecutar {{ $task['label'] }} con alcance ordinario fijo.
                                            </label>
                                            <button class="report-ui-button" type="submit">Ejecutar</button>
                                        </form>
                                    @endif
                                @else
                                    <span class="report-ui-badge">Solo scheduler</span>
                                    <p>{{ $task['policy'] }}</p>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
        <section class="report-ui-data-panel" aria-label="Historial de ejecuciones">
            <div class="report-ui-data-panel__body"><h2>Últimas ejecuciones</h2></div>
            <div class="report-ui-table-shell" tabindex="0" aria-label="Historial desplazable">
                <table class="report-ui-table">
                    <thead><tr><th scope="col">ID / correlación</th><th scope="col">Tarea</th><th scope="col">Origen / actor</th><th scope="col">Estado / salida</th><th scope="col">Encolado / inicio / fin</th><th scope="col">Resumen</th></tr></thead>
                    <tbody>
                    @forelse ($runs as $run)
                        <tr>
                            <td>{{ $run->id }}<br>{{ $run->correlation_id }}<br>Intento {{ $run->attempt }}</td>
                            <td>{{ $run->task_key }}</td>
                            <td>{{ $run->origin }} / {{ $run->report_user_id ?? 'Sin actor humano' }}</td>
                            <td>{{ $labels[$run->status] ?? 'Desconocido' }} / {{ $run->exit_code ?? 'Sin código' }}</td>
                            <td>{{ $date($run->queued_at) }}<br>{{ $date($run->started_at) }}<br>{{ $date($run->finished_at) }}</td>
                            <td>{{ $run->summary }}<br>{{ $run->error }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6">No hay ejecuciones registradas.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            {{ $runs->links() }}
        </section>

        <x-reports.ui.section-header title="Alertas operativas" description="Incidencias abiertas y resueltas, con filtros y paginación." />
        <section class="report-ui-kpi-strip">
            <article class="report-ui-kpi-strip__item">
                <span class="report-ui-kpi-strip__label">Abiertas</span>
                <strong class="report-ui-kpi-strip__value">{{ number_format($openCount, 0, ',', '.') }}</strong>
            </article>
            <article class="report-ui-kpi-strip__item">
                <span class="report-ui-kpi-strip__label">Resueltas en retención</span>
                <strong>{{ number_format($resolvedCount, 0, ',', '.') }}</strong>
            </article>
        </section>

        <section class="report-ui-data-panel">
            <form method="GET" action="{{ route('reports.operational-alerts.index') }}" class="report-ui-filter-bar">
                <div class="report-ui-filter-bar__fields">
                <div class="report-ui-field">
                    <label class="report-ui-label" for="state">Estado</label>
                    <select class="report-ui-select" id="state" name="state">
                        <option value="">Todos</option>
                        <option value="open" @selected(($filters['state'] ?? '') === 'open')>Abierta</option>
                        <option value="resolved" @selected(($filters['state'] ?? '') === 'resolved')>Resuelta</option>
                    </select>
                </div>
                <div class="report-ui-field">
                    <label class="report-ui-label" for="severity">Severidad</label>
                    <select class="report-ui-select" id="severity" name="severity">
                        <option value="">Todas</option>
                        @foreach (['critical' => 'Crítica', 'high' => 'Alta', 'medium' => 'Media', 'low' => 'Baja'] as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['severity'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="report-ui-field">
                    <label class="report-ui-label" for="type">Tipo</label>
                    <select class="report-ui-select" id="type" name="type">
                        <option value="">Todos</option>
                        @foreach ($types as $type)
                            <option value="{{ $type }}" @selected(($filters['type'] ?? '') === $type)>{{ $type }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="report-ui-field">
                    <label class="report-ui-label" for="source">Origen</label>
                    <select class="report-ui-select" id="source" name="source">
                        <option value="">Todos</option>
                        @foreach ($sources as $source)
                            <option value="{{ $source }}" @selected(($filters['source'] ?? '') === $source)>{{ $source }}</option>
                        @endforeach
                    </select>
                </div>
                </div>
                <div class="report-ui-filter-bar__actions">
                    <button type="submit" class="report-ui-button">Filtrar</button>
                    <a href="{{ route('reports.operational-alerts.index') }}" class="report-ui-button report-ui-button--secondary">Limpiar</a>
                </div>
            </form>
        </section>

        <section class="report-ui-data-panel">
            <div class="report-ui-table-shell" tabindex="0" aria-label="Alertas desplazables">
                <table class="report-ui-table">
                    <thead>
                    <tr>
                        <th scope="col">Estado</th>
                        <th scope="col">Severidad</th>
                        <th scope="col">Tipo / origen</th>
                        <th scope="col">Mensaje</th>
                        <th scope="col">Identificador técnico</th>
                        <th scope="col">Última detección</th>
                        <th scope="col">Resolución</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($alerts as $alert)
                        <tr>
                            <td>{{ $alert->state === 'open' ? 'Abierta' : 'Resuelta' }}</td>
                            <td>{{ ucfirst($alert->severity) }}</td>
                            <td>{{ $alert->type }}<br><span class="report-ui-badge">{{ $alert->source }}</span></td>
                            <td>{{ $alert->message }}<br><span class="report-ui-badge">{{ $alert->occurrences }} detección(es)</span></td>
                            <td>{{ $alert->technical_identifier }}</td>
                            <td>{{ $alert->last_detected_at?->copy()->timezone('Europe/Madrid')->format('d/m/Y H:i:s T') }}</td>
                            <td>
                                {{ $alert->resolution ?: '-' }}
                                @if ($alert->resolved_at)
                                    <br><span class="report-ui-badge">{{ $alert->resolved_at->copy()->timezone('Europe/Madrid')->format('d/m/Y H:i:s T') }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7">No hay alertas para los filtros seleccionados.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="report-ui-data-panel__body">{{ $alerts->links() }}</div>
        </section>
    </main>
</div>
</x-reports.app-shell>
