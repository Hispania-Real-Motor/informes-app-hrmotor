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
                    @php
                        $assignment = $commissionApprovers->get($scope);
                        $blocked = $assignment === null
                            || ($assignment->configured_at === null && $assignment->report_user_id !== null)
                            || ($assignment->configured_at !== null && ! $assignment->user?->is_active);
                        $scopeLabel = $commissionScopeLabels[$scope];
                    @endphp
                    <div class="filter-group">
                        <label for="approver_{{ $scope }}">{{ $scopeLabel }}</label>
                        @if ($blocked)
                            <p class="notice" role="status">{{ $scopeLabel }}: aprobación bloqueada. El aprobador está inactivo, ha sido eliminado o la configuración no es válida. Seleccione un sustituto activo.</p>
                        @endif
                        <select id="approver_{{ $scope }}" name="approvers[{{ $scope }}]" @error("approvers.$scope") aria-invalid="true" aria-describedby="approver_error_{{ $scope }}" @enderror>
                            <option value="">{{ $assignment?->configured_at ? 'Seleccione un sustituto activo (sin retorno a legacy)' : 'Sin asignación: política legacy' }}</option>
                            @foreach ($approverUsers as $user)
                                <option value="{{ $user->id }}" @selected((string) old("approvers.$scope", $assignment?->report_user_id) === (string) $user->id)>{{ $user->name }} · {{ $user->email }}</option>
                            @endforeach
                        </select>
                        @error("approvers.$scope")<p id="approver_error_{{ $scope }}" role="alert">{{ $message }}</p>@enderror
                    </div>
                @endforeach
                <button type="submit" class="main-tab active">Guardar aprobadores</button>
            </form>
        </section>
    </main>
</div>
</x-reports.app-shell>
