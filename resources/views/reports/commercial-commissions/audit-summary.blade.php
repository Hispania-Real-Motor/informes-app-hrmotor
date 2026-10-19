<x-reports.app-shell title="Auditoría de comisiones" body-class="campaigns-report">
    <x-slot:head>@vite(['resources/css/reports/leads-dashboard.css'])</x-slot:head>
    <main class="wrap">
        <section class="header"><div><div class="eyebrow">Solo lectura</div><h1>Comisiones finales</h1><p class="sub">Resultados finales y estado de aprobación de {{ $auditProjection['month_label'] }}.</p></div></section>
        <form method="GET" action="{{ url('/informes/comisiones-comerciales') }}" class="card commission-audit-month-filter">
            <input type="hidden" name="audit_scope" value="{{ $auditProjection['selected_scope'] }}">
            <label for="audit-month">Mes económico</label>
            <select id="audit-month" name="month">
                @foreach ($auditProjection['available_months'] as $monthOption)
                    <option value="{{ $monthOption['value'] }}" @selected($monthOption['value'] === $auditProjection['month'])>{{ $monthOption['label'] }}</option>
                @endforeach
            </select>
            <button type="submit" class="main-tab">Consultar</button>
        </form>
        <nav class="tabs-main" aria-label="Bloques auditables">
            @foreach ($auditProjection['scope_statuses'] as $scopeKey => $status)
                <a @class(['main-tab', 'active' => $scopeKey === $auditProjection['selected_scope']]) href="{{ request()->fullUrlWithQuery(['audit_scope' => $scopeKey]) }}">
                    {{ $auditProjectionService->scopeLabel($scopeKey) }}
                </a>
            @endforeach
        </nav>
        @php($scope = $auditProjection['scope'])
        <section class="card panel">
            <div class="panel-title"><h2>{{ $auditProjectionService->scopeLabel($scope['scope']) }}</h2><span class="type-pill {{ data_get($scope, 'status.variant') }}">{{ data_get($scope, 'status.label') }}</span></div>
            <div class="small">Aprobado por: {{ data_get($scope, 'status.approved_by.name', 'Pendiente') }} · {{ data_get($scope, 'status.approved_at', 'Sin fecha') }}</div>
            @if (! $scope['available'])<div class="notice commission-warning">{{ $scope['warning'] }}</div>@endif
            @foreach ($scope['alerts'] as $alert)<div class="notice">{{ $alert }}</div>@endforeach
            @if ($scope['available'])
                <div class="table-shell"><table><thead><tr><th>{{ $scope['scope'] === 'delegations' ? 'Delegación' : 'Entidad' }}</th>@if($scope['scope'] === 'delegations')<th>Jefe de tienda</th>@endif<th class="num">Comisión final</th></tr></thead><tbody>
                    @forelse ($scope['rows'] as $row)<tr><td>{{ $row['name'] }}</td>@if($scope['scope'] === 'delegations')<td>{{ $row['manager_name'] ?? 'No verificable' }}</td>@endif<td class="num">{{ number_format($row['final_total'], 2, ',', '.') }} EUR</td></tr>@empty<tr><td colspan="{{ $scope['scope'] === 'delegations' ? 3 : 2 }}">Sin resultados para este bloque.</td></tr>@endforelse
                </tbody></table></div>
            @endif
        </section>
        @include('reports.commercial-commissions.partials.closure-approval', ['approvalMonth' => $auditProjection['month']])
    </main>
</x-reports.app-shell>
