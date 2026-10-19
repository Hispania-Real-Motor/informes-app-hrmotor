<x-reports.app-shell title="Permisos de informes" current-admin-page="access-settings" body-class="campaigns-report report-users-page">
    <x-slot:head>
    @vite(['resources/css/reports/leads-dashboard.css'])
    </x-slot:head>
<div class="wrap">
    <main>
        <section class="header">
            <div>
                <div class="eyebrow">Administracion</div>
                <h1>Permisos por informe</h1>
                <p class="sub">Configura el rol minimo que puede abrir cada informe. La jerarquia es Administrador, Direccion, Area Manager y Viewer.</p>
            </div>
        </section>

        @if (session('status'))
            <div class="notice notice-success">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="notice">{{ $errors->first() }}</div>
        @endif

        <section class="card panel">
            <div class="panel-title">
                <div>
                    <h2>Reglas de acceso</h2>
                    <div class="small">Un rol superior siempre hereda acceso a los informes abiertos para roles inferiores.</div>
                </div>
            </div>

            <form method="POST" action="{{ route('reports.access-settings.update') }}" class="report-access-grid">
                @csrf
                @method('PUT')

                @foreach ($reportDefinitions as $reportKey => $definition)
                    <article class="card report-access-card">
                        <strong>{{ $definition['label'] }}</strong>
                        <span class="small">Ruta {{ $definition['route'] }}</span>
                        <div class="filter-group">
                            <label for="minimum_role_{{ $reportKey }}">Rol minimo</label>
                            <select id="minimum_role_{{ $reportKey }}" name="minimum_roles[{{ $reportKey }}]">
                                @foreach ($roleOptions as $roleValue => $roleLabel)
                                    <option value="{{ $roleValue }}" @selected(old("minimum_roles.$reportKey", $minimumRoles[$reportKey] ?? $definition['default_minimum_role']) === $roleValue)>{{ $roleLabel }}</option>
                                @endforeach
                            </select>
                        </div>
                    </article>
                @endforeach

                <div class="report-user-form-actions">
                    <button type="submit" class="main-tab active">Guardar permisos</button>
                </div>
            </form>
        </section>
        <section class="card panel">
            <h2>Aprobadores de cierres de comisiones</h2>
            <p>Sin asignación previa: Administrador o Dirección. Tras asignar, solo el usuario seleccionado puede aprobar. La asignación no concede acceso a detalles ni permisos administrativos.</p>
            <form method="POST" action="{{ route('reports.access-settings.commission-approvers') }}" class="report-access-grid">
                @csrf @method('PUT')
                @foreach ($commissionScopes as $scope)
                    @php($assignment = $commissionApprovers->get($scope))
                    <div class="filter-group">
                        <label for="approver_{{ $scope }}">{{ ['commercials'=>'Comerciales','delegations'=>'Delegaciones','area_manager'=>'Area Manager','financials'=>'Financieros','call_center'=>'Call Center','contact_center'=>'Contact Center'][$scope] }}</label>
                        <select id="approver_{{ $scope }}" name="approvers[{{ $scope }}]">
                            <option value="">{{ $assignment?->configured_at ? 'Seleccione un usuario activo (aprobación bloqueada sin usuario válido)' : 'Sin asignación: política legacy' }}</option>
                            @foreach ($approverUsers as $user)
                                <option value="{{ $user->id }}" @selected((string) old("approvers.$scope", $assignment?->report_user_id) === (string) $user->id)>{{ $user->name }} · {{ $user->email }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
                <button type="submit" class="main-tab active">Guardar aprobadores</button>
            </form>
        </section>
    </main>
</div>
</x-reports.app-shell>
