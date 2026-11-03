<x-reports.app-shell title="Roster Contact Center" current-admin-page="commission-settings" body-class="campaigns-report report-users-page">
    <div class="wrap"><main>
        <section class="header"><div><div class="eyebrow">Administración</div><h1>Roster Contact Center</h1><p class="sub">La identidad económica es el UserId de Salesforce. El roster no calcula comisiones.</p></div></section>
        @if (session('status'))<div class="notice notice-success">{{ session('status') }}</div>@endif
        @if ($errors->any())<div class="notice">{{ $errors->first() }}</div>@endif
        <section class="card panel"><div class="panel-title"><div><h2>Añadir miembro</h2></div></div>
            <form method="POST" action="{{ route('reports.commission-settings.contact-center-roster.store') }}" class="report-user-form-grid">@csrf
                <div class="filter-group"><label>UserId Salesforce</label><input name="salesforce_user_id" value="{{ old('salesforce_user_id') }}" required></div>
                <div class="filter-group"><label>Nombre visible</label><input name="display_name" value="{{ old('display_name') }}" required></div>
                <div class="filter-group"><label>Vigente desde</label><input type="date" name="effective_from" value="{{ old('effective_from') }}" required></div>
                <div class="filter-group"><label>Vigente hasta</label><input type="date" name="effective_until" value="{{ old('effective_until') }}"></div>
                <div class="filter-actions"><button type="submit" class="main-tab active">Añadir</button></div>
            </form>
        </section>
        <section class="card panel"><div class="panel-title"><div><h2>Vigencias registradas</h2></div></div>
            @foreach ($members as $member)
                <form method="POST" action="{{ route('reports.commission-settings.contact-center-roster.update', $member) }}" class="report-user-form-grid">@csrf @method('PUT')
                    <div class="filter-group"><label>UserId Salesforce</label><input name="salesforce_user_id" value="{{ $member->salesforce_user_id }}" required></div>
                    <div class="filter-group"><label>Nombre visible</label><input name="display_name" value="{{ $member->display_name }}" required></div>
                    <div class="filter-group"><label>Vigente desde</label><input type="date" name="effective_from" value="{{ $member->effective_from->format('Y-m-d') }}" required></div>
                    <div class="filter-group"><label>Vigente hasta</label><input type="date" name="effective_until" value="{{ $member->effective_until?->format('Y-m-d') }}"></div>
                    <div class="filter-actions"><button type="submit" class="main-tab">Guardar</button></div>
                </form>
            @endforeach
        </section>
    </main></div>
</x-reports.app-shell>
