<x-reports.app-shell title="Intereses — octubre 2026 provisional" current-report="leads">
    <x-slot:head>
        @vite([
            'resources/css/reports/leads-dashboard.css',
            'resources/js/reports/october-2026-provisional.js',
        ])
    </x-slot:head>

    <div class="wrap">
        <section class="card" aria-labelledby="provisional-october-title">
            <p class="eyebrow">Acceso restringido a administración</p>
            <h1 id="provisional-october-title">Octubre de 2026: inventario provisional no certificado</h1>
            <p>Esta lectura aislada utiliza los journals mensuales validados y no sustituye ninguna fuente publicada, KPI, cierre, exportación ni informe oficial.</p>
            <p>Periodo funcional: {{ $overview['period']['start_utc'] }} UTC a {{ $overview['period']['end_utc'] }} UTC ({{ $overview['period']['timezone'] }}).</p>
        </section>

        <section class="filters card" aria-labelledby="provisional-controls-title">
            <h2 id="provisional-controls-title">Explorar agregados provisionales</h2>
            <div class="filter-group">
                <label for="provisionalPeriod">Periodo</label>
                <select id="provisionalPeriod" disabled>
                    <option>Octubre de 2026 · provisional</option>
                </select>
            </div>
            <div class="filter-group">
                <label for="provisionalDimension">Explorar distribución precalculada</label>
                <select id="provisionalDimension">
                    <option value="">Todas las distribuciones</option>
                    <option value="delegation">Delegación de procedencia</option>
                    <option value="group">Grupo de procedencia</option>
                    <option value="zone">Zona de procedencia</option>
                    <option value="status">Estado</option>
                    <option value="type">Tipo normalizado</option>
                    <option value="source">Fuente</option>
                    <option value="medium">Medio</option>
                    <option value="channel">Canal</option>
                </select>
            </div>
            <div class="filter-group">
                <label for="provisionalSearch">Buscar valores de la distribución seleccionada</label>
                <input id="provisionalSearch" type="search" autocomplete="off" placeholder="Seleccione primero una distribución" disabled>
            </div>
            <div class="filter-actions">
                <button class="filter-reset" id="resetProvisionalFilters" type="button">Restablecer exploración</button>
            </div>
            <p class="small" id="provisionalSelection" aria-live="polite">Seleccione una distribución para consultar sus valores precalculados.</p>
            <p class="small">Estos controles de exploración no son filtros del informe: solo muestran un agregado independiente ya incluido en el snapshot validado. No recalculan KPI ni combinan dimensiones.</p>
        </section>

        <section class="card" aria-labelledby="coverage-title">
            <h2 id="coverage-title">Cobertura comprobable</h2>
            <table>
                <thead><tr><th>Medida</th><th>Valor</th><th>Interpretación</th></tr></thead>
                <tbody>
                    <tr><td>Inventario F2</td><td>{{ number_format($overview['coverage']['f2_inventory'], 0, ',', '.') }}</td><td>Identidades del journal mensual de origen.</td></tr>
                    <tr><td>Materializados</td><td>{{ number_format($overview['coverage']['materialized'], 0, ',', '.') }}</td><td>Estado actual observado; no fotografía histórica.</td></tr>
                    <tr><td>Activos con contenido coincidente</td><td>{{ number_format($overview['coverage']['eligible_active_matching'], 0, ',', '.') }}</td><td>Única población incluida en los agregados inferiores.</td></tr>
                    <tr><td>Ausentes de materialización</td><td>{{ number_format($overview['coverage']['missing_from_materialization'], 0, ',', '.') }}</td><td>No disponibles para la lectura provisional.</td></tr>
                    <tr><td>Contenido cambiado</td><td>{{ number_format($overview['coverage']['content_changed'], 0, ',', '.') }}</td><td>Excluidos: no se reconstruye su valor histórico.</td></tr>
                    <tr><td>Soft deletes observados</td><td>{{ number_format($overview['coverage']['soft_deleted_observed'], 0, ',', '.') }}</td><td>Excluidos de los agregados; hard deletes no acreditados.</td></tr>
                    <tr><td>Sin identidad canónica</td><td>{{ number_format($overview['coverage']['canonical_identity_unavailable'], 0, ',', '.') }}</td><td>Limitación de relación, no una ausencia demostrada.</td></tr>
                </tbody>
            </table>
        </section>

        <section class="card" data-provisional-distribution="delegation" aria-labelledby="delegations-title">
            <h2 id="delegations-title">Intereses activos coincidentes por delegación de procedencia</h2>
            <p>La procedencia se normaliza con el mismo diccionario de delegaciones del informe oficial. No se infiere de owner, comercial, cuenta ni Lead relacionado.</p>
            <table>
                <thead><tr><th>Delegación</th><th>Intereses</th></tr></thead>
                <tbody>
                    @forelse ($overview['by_delegation'] as $row)
                        <tr data-provisional-row data-provisional-label="{{ $row['delegation'] }}"><td>{{ $row['delegation'] }}</td><td>{{ number_format($row['interests'], 0, ',', '.') }}</td></tr>
                    @empty
                        <tr><td colspan="2">No hay filas aptas para mostrar.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section class="card" aria-labelledby="classification-title">
            <h2 id="classification-title">Calidad de clasificación de procedencia</h2>
            <table>
                <thead><tr><th>Medida</th><th>Intereses</th></tr></thead>
                <tbody>
                    <tr><td>Universo incluido</td><td>{{ number_format($overview['classification']['total_included'], 0, ',', '.') }}</td></tr>
                    <tr><td>Procedencia clasificada</td><td>{{ number_format($overview['classification']['classified'], 0, ',', '.') }}</td></tr>
                    <tr><td>Sin clasificar</td><td>{{ number_format($overview['classification']['unclassified'], 0, ',', '.') }}</td></tr>
                    <tr><td>Procedencia vacía</td><td>{{ number_format($overview['classification']['raw_empty'], 0, ',', '.') }}</td></tr>
                    <tr><td>Valor no reconocido</td><td>{{ number_format($overview['classification']['raw_unmapped'], 0, ',', '.') }}</td></tr>
                    <tr><td>Valor literal «General»</td><td>{{ number_format($overview['classification']['raw_literal_general'], 0, ',', '.') }}</td></tr>
                </tbody>
            </table>
            <p>Los valores no reconocidos se agregan por frecuencia y huella de auditoría; la pantalla no expone valores libres ni identificadores.</p>
        </section>

        <section class="card" data-provisional-collection aria-labelledby="groups-title">
            <h2 id="groups-title">Distribuciones normalizadas de procedencia</h2>
            <div data-provisional-distribution="group">
                <h3>Grupo de procedencia</h3>
                <table>
                    <thead><tr><th>Grupo</th><th>Intereses</th></tr></thead>
                    <tbody>
                        @foreach ($overview['classification']['by_group'] as $row)
                            <tr data-provisional-row data-provisional-label="{{ $row['group'] }}"><td>{{ $row['group'] }}</td><td>{{ number_format($row['interests'], 0, ',', '.') }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div data-provisional-distribution="zone">
                <h3>Zona de procedencia</h3>
                <table>
                    <thead><tr><th>Zona</th><th>Intereses</th></tr></thead>
                    <tbody>
                        @foreach ($overview['classification']['by_zone'] as $row)
                            <tr data-provisional-row data-provisional-label="{{ $row['zone'] }}"><td>{{ $row['zone'] }}</td><td>{{ number_format($row['interests'], 0, ',', '.') }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card" data-provisional-collection aria-labelledby="functional-kpis-title">
            <h2 id="functional-kpis-title">Agregados provisionales disponibles</h2>
            <p>Estado, tipo normalizado, fuente, medio y canal se calculan durante la validación administrativa sobre los {{ number_format($overview['functional_kpis']['total'], 0, ',', '.') }} Interests F2M activos y con contenido coincidente. Son agregados provisionales, no KPI oficiales certificados, y no se recalculan al abrir esta página.</p>
            <div data-provisional-distribution="status">
                <h3>Estado</h3>
                <table>
                    <thead><tr><th>Estado</th><th>Intereses</th></tr></thead>
                    <tbody>
                        @foreach ($overview['functional_kpis']['statuses'] as $row)
                            <tr data-provisional-row data-provisional-label="{{ $row['status'] }}"><td>{{ $row['status'] }}</td><td>{{ number_format($row['interests'], 0, ',', '.') }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div data-provisional-distribution="type">
                <h3>Tipo normalizado</h3>
                <table>
                    <thead><tr><th>Tipo normalizado</th><th>Intereses</th></tr></thead>
                    <tbody>
                        @foreach ($overview['functional_kpis']['types'] as $row)
                            <tr data-provisional-row data-provisional-label="{{ $row['type'] }}"><td>{{ $row['type'] }}</td><td>{{ number_format($row['interests'], 0, ',', '.') }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @foreach (['sources' => 'Fuente', 'media' => 'Medio', 'channels' => 'Canal'] as $dimension => $heading)
                <div data-provisional-distribution="{{ $dimension === 'sources' ? 'source' : ($dimension === 'media' ? 'medium' : 'channel') }}">
                    <h3>{{ $heading }}</h3>
                    <table>
                        <thead><tr><th>{{ $heading }}</th><th>Intereses</th></tr></thead>
                        <tbody>
                            @foreach ($overview['functional_kpis'][$dimension] as $row)
                                @php($label = $row[$dimension === 'sources' ? 'source' : ($dimension === 'media' ? 'medium' : 'channel')])
                                <tr data-provisional-row data-provisional-label="{{ $label }}"><td>{{ $label }}</td><td>{{ number_format($row['interests'], 0, ',', '.') }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
        </section>

        <section class="card" aria-labelledby="limits-title">
            <h2 id="limits-title">Indicadores no disponibles con esta evidencia</h2>
            <p>F2 permanece sin certificación histórica: {{ $overview['verification']['f2_verified'] ? 'la comprobación actual no sustituye la certificación histórica.' : 'la verificación F2 original no fue satisfactoria.' }} F5 tampoco certifica este inventario.</p>
            <p>Los comerciales elegibles y sus delegaciones no están disponibles: F2M no conserva la instantánea histórica de usuarios requerida por la fórmula oficial. Tampoco se calculan KPI de actividad: F5 no acredita historia y esta evidencia no conserva el estado de relación requerido por la fórmula oficial.</p>
            <p>Dirección y comparativas; Reservas/Ventas y rendimiento comercial; Campañas, Llamadas y SEO continúan no disponibles desde esta fuente provisional. Los journals no exponen identificadores ni payloads en esta pantalla.</p>
        </section>
    </div>

</x-reports.app-shell>
