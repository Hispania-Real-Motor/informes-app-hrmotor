<?php

namespace App\Services\Operations;

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
        $ids = $states->flatMap(fn ($state) => [$state->last_run_id, $state->last_success_id, $state->last_failure_id, $state->active_run_id])->filter()->unique();
        $runs = OperationalTaskRun::query()->whereIn('id', $ids)->get()->keyBy('id');
        $now = CarbonImmutable::now('Europe/Madrid');
        $rows = [];
        foreach ($this->catalog->all() as $key => $task) {
            $state = $states->get($key);
            $last = $runs->get($state?->last_run_id);
            $success = $runs->get($state?->last_success_id);
            $failure = $runs->get($state?->last_failure_id);
            $active = $runs->get($state?->active_run_id);
            $cron = new CronExpression($task['cron']);
            // Latest occurrence whose explicit operational grace has elapsed.
            $due = CarbonImmutable::instance($cron->getPreviousRunDate($now->subMinutes($task['grace_minutes'])->toDateTimeImmutable(), 0, true, $task['timezone']));
            $next = CarbonImmutable::instance($cron->getNextRunDate($now->toDateTimeImmutable(), 0, false, $task['timezone']));
            // Start must cover the expected occurrence. A very old run finishing
            // today cannot mask missed schedules.
            $stale = ! $success || $success->status !== 'success' || ! $success->finished_at
                || ! $success->started_at || $success->started_at->lt($due->utc());
            $overdue = $active && ($active->status === 'unknown'
                || ($active->status === 'queued' && $active->queued_at->lt(now()->subMinutes(15)))
                || ($active->status === 'running' && $active->started_at?->lt(now()->subMinutes($task['window_minutes']))));
            $status = $overdue ? 'unknown' : ($active?->status ?? ($last?->status === 'failed' ? 'failed' : ($stale ? ($last ? 'stale' : 'never') : ($last?->status === 'skipped' ? 'skipped' : 'success'))));
            $rows[$key] = compact('key', 'task', 'last', 'success', 'failure', 'active', 'due', 'next', 'stale', 'overdue', 'status');
        }

        return $rows;
    }

    public function check(): int
    {
        $unhealthy = 0;
        foreach ($this->snapshot() as $key => $row) {
            if ($row['stale'] || $row['overdue']) {
                $unhealthy++;
                $this->alerts->open('operational_task_stale', 'high', 'operations', $key, 'Sin éxito verificable dentro de la ventana esperada o ejecución bloqueada.');
            } else {
                $this->alerts->resolve('operational_task_stale', 'operations', $key, 'Éxito verificable dentro de la ventana esperada.');
            }
        }

        return $unhealthy;
    }
}
