<x-reports.app-shell title="Intereses — vista administrativa" current-report="leads">
    <div class="wrap">
        <section class="card" aria-labelledby="provisional-title">
            <p class="eyebrow">Acceso restringido a administración</p>
            <h1 id="provisional-title">Vista administrativa de Intereses</h1>
            <p>Solo muestra agregados de septiembre y octubre de 2026 de la semilla técnica. No alimenta indicadores, cierres, comisiones, exportaciones ni informes oficiales.</p>
            <p>Corte de datos: {{ $preview['cutoff_at'] }} UTC. La cobertura y las relaciones de actividad no son suficientes para otros indicadores.</p>
        </section>
        <section class="card" aria-label="Agregados disponibles">
            <table>
                <thead><tr><th>Periodo</th><th>Intereses</th><th>Eliminados</th></tr></thead>
                <tbody>
                    @foreach ($preview['periods'] as $period)
                        <tr><td>{{ $period['period'] }}</td><td>{{ number_format($period['rows'], 0, ',', '.') }}</td><td>{{ number_format($period['deleted'], 0, ',', '.') }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    </div>
</x-reports.app-shell>
