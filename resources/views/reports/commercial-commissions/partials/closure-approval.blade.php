@php
    $closureStatusLabels = ['provisional'=>'Provisional','pending_approval'=>'Pendiente de aprobación','definitive'=>'Definitivo','reopened'=>'Reabierto'];
@endphp
            @if (($directorApprovalOverview ?? []) !== [])
                <section class="card panel commission-closure-panel" aria-labelledby="director-approval-title">
                    <div class="panel-title"><div><h2 id="director-approval-title">Aprobación de comisiones · {{ \Carbon\CarbonImmutable::createFromFormat('!Y-m', $approvalMonth)->locale('es')->translatedFormat('F \d\e Y') }}</h2><div class="small">Cada importe corresponde exactamente a la fotografía preparada por Administración.</div></div></div>
                    <div class="table-shell commission-closure-table-shell">
                        <table class="commission-closure-table">
                            <thead><tr><th>Bloque</th><th>Estado</th><th class="num">Importe</th><th>Aprobado por</th><th>Fecha</th><th>Acción</th></tr></thead>
                            <tbody>
                                @foreach ($directorApprovalOverview as $approval)
                                    @php
                                        $status = data_get($approval, 'status.status');
                                        $displayStatus = data_get($approval, 'status.is_prepared') ? ($closureStatusLabels[$status] ?? $status) : 'Sin preparar';
                                    @endphp
                                    <tr>
                                        <td><strong>{{ $approval['label'] }}</strong>@foreach (($approval['alerts'] ?? []) as $alert)<div class="small commission-source-warning">{{ $alert }}</div>@endforeach</td>
                                        <td><span class="type-pill {{ $status === 'definitive' ? 'group' : 'pending' }}">{{ $displayStatus }}</span></td>
                                        <td class="num">{{ $approval['final_total'] === null ? '—' : number_format($approval['final_total'], 2, ',', '.').' EUR' }}</td>
                                        <td>{{ data_get($approval, 'status.approved_by.name', '—') }}</td>
                                        <td>{{ data_get($approval, 'status.approved_at') ? \Carbon\CarbonImmutable::parse(data_get($approval, 'status.approved_at'))->format('d/m/Y H:i') : '—' }}</td>
                                        <td>
                                            @if ($status === 'pending_approval' && data_get($approval, 'status.is_prepared'))
                                                <form method="POST" action="{{ route('reports.commercial-commissions.closure.approve') }}">@csrf<input type="hidden" name="month" value="{{ $approval['month'] }}"><input type="hidden" name="closure_scope" value="{{ $approval['scope'] }}"><button class="main-tab active" type="submit">Aprobar</button></form>
                                            @elseif ($status === 'definitive' && ($canReopenEconomicClosures ?? false))
                                                <details class="commission-exception-actions"><summary>Reabrir</summary><form method="POST" action="{{ route('reports.commercial-commissions.closure.reopen') }}" class="commission-exception-form">@csrf<input type="hidden" name="month" value="{{ $approval['month'] }}"><input type="hidden" name="closure_scope" value="{{ $approval['scope'] }}"><label>Motivo obligatorio<input name="reason" minlength="10" required></label><button class="filter-reset" type="submit">Confirmar reapertura</button></form></details>
                                            @else
                                                <span class="small">Pendiente de preparación</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif
