<x-reports.app-shell title="Intereses — octubre 2026 provisional" current-report="leads">
    <div class="wrap">
        <section class="card" aria-labelledby="provisional-october-title">
            <p class="eyebrow">Acceso restringido a administración</p>
            <h1 id="provisional-october-title">Octubre de 2026: inventario provisional no certificado</h1>
            <p>Esta lectura aislada utiliza los journals mensuales validados y no sustituye ninguna fuente publicada, KPI, cierre, exportación ni informe oficial.</p>
            <p>Periodo funcional: {{ $overview['period']['start_utc'] }} UTC a {{ $overview['period']['end_utc'] }} UTC ({{ $overview['period']['timezone'] }}).</p>
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

        <section class="card" aria-labelledby="delegations-title">
            <h2 id="delegations-title">Intereses activos coincidentes por delegación de procedencia</h2>
            <p>La procedencia se normaliza con el mismo diccionario de delegaciones del informe oficial. No se infiere de owner, comercial, cuenta ni Lead relacionado.</p>
            <table>
                <thead><tr><th>Delegación</th><th>Intereses</th></tr></thead>
                <tbody>
                    @forelse ($overview['by_delegation'] as $row)
                        <tr><td>{{ $row['delegation'] }}</td><td>{{ number_format($row['interests'], 0, ',', '.') }}</td></tr>
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
                </tbody>
            </table>
            <p>Los valores no reconocidos se agregan por frecuencia y huella de auditoría; la pantalla no expone valores libres ni identificadores.</p>
        </section>

        <section class="card" aria-labelledby="groups-title">
            <h2 id="groups-title">Distribución por grupo y zona de procedencia</h2>
            <table>
                <thead><tr><th>Grupo</th><th>Intereses</th></tr></thead>
                <tbody>
                    @foreach ($overview['classification']['by_group'] as $row)
                        <tr><td>{{ $row['group'] }}</td><td>{{ number_format($row['interests'], 0, ',', '.') }}</td></tr>
                    @endforeach
                </tbody>
            </table>
            <table>
                <thead><tr><th>Zona</th><th>Intereses</th></tr></thead>
                <tbody>
                    @foreach ($overview['classification']['by_zone'] as $row)
                        <tr><td>{{ $row['zone'] }}</td><td>{{ number_format($row['interests'], 0, ',', '.') }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        <section class="card" aria-labelledby="limits-title">
            <h2 id="limits-title">Límites de uso</h2>
            <p>F2 permanece sin certificación histórica: {{ $overview['verification']['f2_verified'] ? 'la comprobación actual no sustituye la certificación histórica.' : 'la verificación F2 original no fue satisfactoria.' }} F5 tampoco certifica este inventario.</p>
            <p>Dirección, comparativas y KPI; Reservas/Ventas y rendimiento comercial; Campañas, Llamadas y SEO continúan no disponibles desde esta fuente provisional. Los journals no exponen identificadores ni payloads en esta pantalla.</p>
        </section>
    </div>
</x-reports.app-shell>
