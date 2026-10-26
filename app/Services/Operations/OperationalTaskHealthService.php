<?php

namespace App\Services\Operations;

use App\Models\OperationalAlert;
use App\Models\OperationalTaskRun;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Support\Facades\DB;

class OperationalTaskHealthService
{
    public function __construct(private readonly OperationalTaskCatalog $catalog, private readonly OperationalAlertService $alerts) {}

    /** Bounded state pointers, not scans of execution history or report_sync_runs. */
    public function snapshot(): array
    {
        $states = DB::table('operational_task_states')->whereIn('task_key', array_keys($this->catalog->all()))->get()->keyBy('task_key');
        $ids = $states->flatMap(fn ($state) => [$state->last_run_id, $state->last_success_id, $state->last_failure_id, $state->active_run_id, $state->last_completed_id])->filter()->unique();
        $runs = OperationalTaskRun::query()->whereIn('id', $ids)->get()->keyBy('id');
        $now = CarbonImmutable::now('Europe/Madrid');
        $rows = [];
        foreach ($this->catalog->all() as $key => $task) {
            if (! ($task['enabled'] ?? true)) {
                continue;
            }
            $state = $states->get($key);
            $last = $runs->get($state?->last_run_id);
            $success = $runs->get($state?->last_success_id);
            $failure = $runs->get($state?->last_failure_id);
            $active = $runs->get($state?->active_run_id);
            $completed = $runs->get($state?->last_completed_id);
            if ($success && (OperationalTaskExitCode::forRun($success) !== OperationalTaskExitCode::Success)) {
                $success = null;
            }
            $cron = new CronExpression($task['cron']);
            $monitoringStartedAt = $state?->monitoring_started_at
                ? CarbonImmutable::parse($state->monitoring_started_at, 'UTC') : null;
            $firstExpected = $monitoringStartedAt
                ? CarbonImmutable::instance($cron->getNextRunDate($monitoringStartedAt->toDateTimeImmutable(), 0, false, $task['timezone'])) : null;
            $due = CarbonImmutable::instance($cron->getPreviousRunDate($now->subMinutes($task['grace_minutes'])->toDateTimeImmutable(), 0, true, $task['timezone']));
            if ($firstExpected && $due->lt($firstExpected)) {
                $due = null;
            }
            $next = CarbonImmutable::instance($cron->getNextRunDate($now->toDateTimeImmutable(), 0, false, $task['timezone']));
            // An unhealthy assessment proves monitor execution, never task health.
            $heartbeat = $success;
            if ($key === OperationalTaskCatalog::HEALTH_TASK_KEY && $completed
                && in_array(OperationalTaskExitCode::forRun($completed), [OperationalTaskExitCode::Success, OperationalTaskExitCode::Unhealthy], true)) {
                $heartbeat = $completed;
            }
            $stale = $monitoringStartedAt === null
                || ($due !== null && (! $heartbeat || $heartbeat->started_at->lt($due->utc())));
            $overdue = $state?->active_run_id !== null && (! $active
                || $active->status === 'unknown'
                || ($active->status === 'queued' && $active->queued_at->lt(now()->subMinutes(15)))
                || ($active->status === 'running' && (! $active->started_at || $active->started_at->lt(now()->subMinutes($task['window_minutes'])))));
            $status = match (true) {
                $overdue => 'unknown',
                $active !== null => $active->status,
                in_array($last?->status, ['failed', 'unknown'], true) => $last->status,
                $stale => $last ? 'stale' : 'never',
                in_array($last?->status, ['skipped', 'unhealthy'], true) => $last->status,
                $heartbeat !== null => 'success',
                default => 'never',
            };
            $awaitingFirstSchedule = $due === null && $monitoringStartedAt !== null;
            $rows[$key] = compact('key', 'task', 'last', 'success', 'failure', 'active', 'completed', 'due', 'next', 'stale', 'overdue', 'status', 'monitoringStartedAt', 'firstExpected', 'awaitingFirstSchedule');
        }

        return $rows;
    }

    /** Full checks run periodically; recovery checks touch alerts for one task. */
    public function check(?string $onlyTask = null): int
    {
        if ($onlyTask !== null) {
            $this->catalog->get($onlyTask);
        }
        $pendingAlerts = OperationalAlert::query()
            ->whereIn('technical_identifier', $onlyTask === null ? array_keys($this->catalog->all()) : [$onlyTask])
            ->whereIn('type', ['operational_task_stale', 'operational_monitor_stale', 'operational_task_failure', 'scheduled_task_failure'])
            ->whereIn('source', ['operations', 'scheduler'])->where('state', 'open')
            ->get(['technical_identifier', 'type'])->groupBy('technical_identifier');
        $unhealthy = 0;
        foreach ($this->snapshot() as $key => $row) {
            if ($onlyTask !== null && $onlyTask !== $key) {
                continue;
            }
            $isMonitor = $key === OperationalTaskCatalog::HEALTH_TASK_KEY;
            // The assessment currently being executed is not a failed heartbeat.
            // Its outcome will be recorded once; finish() checks only this key.
            if ($isMonitor && $row['active']?->status === 'running' && ! $row['overdue']) {
                continue;
            }
            $canResolve = $row['success'] !== null || ($isMonitor && $row['completed']
                && in_array(OperationalTaskExitCode::forRun($row['completed']), [OperationalTaskExitCode::Success, OperationalTaskExitCode::Unhealthy], true));
            if ($row['stale'] || $row['overdue'] || in_array($row['status'], ['failed', 'unknown'], true)
                || (! $canResolve && $pendingAlerts->has($key))
                || $pendingAlerts->get($key, collect())->contains(fn ($alert) => in_array($alert->type, ['operational_task_failure', 'scheduled_task_failure'], true))) {
                $unhealthy++;
            }
            $type = $isMonitor ? 'operational_monitor_stale' : 'operational_task_stale';
            if ($row['stale'] || $row['overdue']) {
                $this->alerts->open($type, 'high', 'operations', $key, 'Sin evidencia vigente dentro de la ventana exigible o ejecución bloqueada.');
            } elseif ($canResolve) {
                $this->alerts->resolve($type, 'operations', $key, 'Evidencia verificable dentro de la ventana exigible.');
                if ($isMonitor) {
                    $this->alerts->resolve('operational_task_stale', 'operations', $key, 'Heartbeat verificable del monitor tras el contrato anterior.');
                }
            }
        }

        return $unhealthy;
    }
}
