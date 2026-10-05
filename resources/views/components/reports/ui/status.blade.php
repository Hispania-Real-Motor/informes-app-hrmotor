@props(['state', 'label' => null])

@php
    $states = [
        'ok' => 'Correcto',
        'observation' => 'Observación',
        'deviation' => 'Desviación relevante',
        'critical' => 'Crítico',
        'not-evaluable' => 'No evaluable',
    ];
    $resolvedState = array_key_exists($state, $states) ? $state : 'not-evaluable';
@endphp

<span
    {{ $attributes->class(['report-ui-status', 'report-ui-status--'.$resolvedState]) }}
    data-report-status="{{ $resolvedState }}"
>
    <svg class="report-ui-status__icon" viewBox="0 0 24 24" aria-hidden="true">
        @switch($resolvedState)
            @case('ok')
                <path d="m5 12 4 4L19 6" />
                @break
            @case('observation')
                <path d="M12 3 2.8 20h18.4L12 3Zm0 6v5m0 3h.01" />
                @break
            @case('deviation')
                <path d="M4 18 10 12l4 3 6-8m-5 0h5v5" />
                @break
            @case('critical')
                <path d="M8 3h8l5 5v8l-5 5H8l-5-5V8l5-5Zm4 5v5m0 3h.01" />
                @break
            @default
                <circle cx="12" cy="12" r="9" />
                <path d="M8 12h8" />
        @endswitch
    </svg>
    <span>{{ $label ?? $states[$resolvedState] }}</span>
</span>
