<?php

namespace App\Http\Controllers\Reports\Operations;

use App\Http\Controllers\Controller;
use App\Models\OperationalAlert;
use App\Models\OperationalTaskRun;
use App\Services\Operations\OperationalTaskCatalog;
use App\Services\Operations\OperationalTaskHealthService;
use App\Services\Operations\OperationalTaskService;
use App\Support\ReportUserAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OperationalAlertController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless(ReportUserAccess::isAdmin($request), 403);

        $filters = $request->validate([
            'state' => ['nullable', Rule::in(['open', 'resolved'])],
            'severity' => ['nullable', Rule::in(['critical', 'high', 'medium', 'low'])],
            'type' => ['nullable', 'string', 'max:80'],
            'source' => ['nullable', 'string', 'max:120'],
        ]);

        $query = OperationalAlert::query()
            ->when($filters['state'] ?? null, fn ($builder, string $state) => $builder->where('state', $state))
            ->when($filters['severity'] ?? null, fn ($builder, string $severity) => $builder->where('severity', $severity))
            ->when($filters['type'] ?? null, fn ($builder, string $type) => $builder->where('type', $type))
            ->when($filters['source'] ?? null, fn ($builder, string $source) => $builder->where('source', $source));

        return view('reports.operations.alerts', [
            'alerts' => $query
                ->orderByRaw("CASE WHEN state = 'open' THEN 0 ELSE 1 END")
                ->orderByDesc('last_detected_at')
                ->orderByDesc('id')
                ->paginate(25)
                ->withQueryString(),
            'openCount' => OperationalAlert::query()->where('state', OperationalAlert::STATE_OPEN)->count(),
            'resolvedCount' => OperationalAlert::query()->where('state', OperationalAlert::STATE_RESOLVED)->count(),
            'types' => OperationalAlert::query()->distinct()->orderBy('type')->pluck('type'),
            'sources' => OperationalAlert::query()->distinct()->orderBy('source')->pluck('source'),
            'filters' => $filters,
            'runs' => OperationalTaskRun::query()->orderByDesc('id')->paginate(25, ['*'], 'runs_page')->withQueryString(),
            'processes' => app(OperationalTaskHealthService::class)->snapshot(),
            'updatedAt' => now('Europe/Madrid'),
        ]);
    }

    public function execute(Request $request, OperationalTaskCatalog $catalog, OperationalTaskService $tasks): RedirectResponse
    {
        abort_unless(ReportUserAccess::isAdmin($request), 403);
        abort_if(array_diff(array_keys($request->all()), ['_token', 'task_key', 'confirmed']) !== [], 422);
        $data = $request->validate([
            'task_key' => ['required', 'string', Rule::in(array_keys($catalog->all()))],
            'confirmed' => ['required', 'accepted'],
        ]);
        abort_unless($catalog->get($data['task_key'])['manual_allowed'], 403);
        $actor = ReportUserAccess::current($request)['id'];
        $rateKey = 'operations:manual:'.$actor;
        abort_if(RateLimiter::tooManyAttempts($rateKey, 10), 429, 'Demasiadas solicitudes operacionales.');
        RateLimiter::hit($rateKey, 60);
        $run = $tasks->requestManual($data['task_key'], $actor);

        return redirect()->route('reports.operational-alerts.index')
            ->with('operation_status', match ($run->status) {
                'queued' => 'En cola. Actualice el panel para consultar el resultado.',
                'running' => 'La tarea ya está ejecutándose. Actualice el panel para consultar el resultado.',
                'success' => 'La tarea ya ha finalizado correctamente.',
                'skipped' => 'La tarea fue omitida; no acredita un éxito. Consulte el historial.',
                default => 'La tarea presenta una incidencia. Consulte el historial operacional.',
            });
    }
}
