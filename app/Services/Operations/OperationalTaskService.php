<?php

namespace App\Services\Operations;

use App\Jobs\Operations\ExecuteOperationalTask;
use App\Models\OperationalTaskRun;
use App\Models\ReportUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Throwable;

class OperationalTaskService
{
    public const RELEASE_REASONS = ['worker_stopped', 'scheduler_stopped', 'queue_expired', 'process_terminated'];

    public function __construct(
        private readonly OperationalTaskCatalog $catalog,
        private readonly OperationalAlertService $alerts,
    ) {}

    public function requestManual(string $key, int $actor): OperationalTaskRun
    {
        abort_unless($this->catalog->get($key)['manual_allowed'], 403);
        abort_unless(ReportUser::query()->whereKey($actor)->where('is_active', true)->where('role', ReportUser::ROLE_ADMIN)->exists(), 403);
        // A dedicated database connection cannot fall back to sync/deferred.
        abort_unless(config('queue.connections.operations.driver') === 'database', 503);
        $run = $this->claim($key, 'manual', $actor);
        abort_if($run->status === 'skipped', 409, 'La tarea ya tiene una ejecución activa.');

        try {
            Queue::connection('operations')->push(new ExecuteOperationalTask($run->id), '', 'operations');
        } catch (Throwable) {
            // An ambiguous Queue acknowledgment is not proof that no worker started.
            DB::transaction(function () use ($run): void {
                DB::table('operational_task_states')->where('task_key', $run->task_key)->lockForUpdate()->first();
                $run->refresh();
                if ($run->status === 'queued') {
                    $this->finish($run, false, null, 'No se pudo entregar la ejecución a Queue.');
                }
            }, 3);
        }

        return $run->refresh();
    }

    public function claim(string $key, string $origin, ?int $actor = null): OperationalTaskRun
    {
        $task = $this->catalog->get($key);
        if (! in_array($origin, ['scheduler', 'manual'], true)) {
            throw new \InvalidArgumentException('Invalid operational origin.');
        }
        DB::table('operational_task_states')->insertOrIgnore([
            'task_key' => $key, 'created_at' => now(), 'updated_at' => now(), 'monitoring_started_at' => now(),
        ]);

        return DB::transaction(function () use ($key, $origin, $actor, $task): OperationalTaskRun {
            $state = DB::table('operational_task_states')->where('task_key', $key)->lockForUpdate()->first();
            if ($origin === 'manual' && $state->manual_available_at && now()->lt($state->manual_available_at)) {
                abort(429, 'Espere al menos un minuto entre solicitudes de esta tarea.');
            }
            $busy = $state->active_run_id !== null;
            $run = OperationalTaskRun::query()->create([
                'task_key' => $key, 'origin' => $origin,
                'report_user_id' => $origin === 'manual' ? $actor : null,
                'correlation_id' => (string) Str::uuid(),
                'status' => $busy ? 'skipped' : 'queued',
                'arguments' => $task['arguments'], 'queued_at' => now(),
                'finished_at' => $busy ? now() : null,
                'summary' => $busy ? 'Solapamiento evitado por reserva persistente.' : 'Pendiente de inicio.',
            ]);
            if (! $busy) {
                DB::table('operational_task_states')->where('task_key', $key)->update([
                    'active_run_id' => $run->id, 'last_run_id' => $run->id,
                    'manual_available_at' => $origin === 'manual' ? now()->addMinute() : $state->manual_available_at,
                    'updated_at' => now(),
                ]);
            }

            return $run;
        }, 3);
    }

    public function execute(int $id): void
    {
        $run = OperationalTaskRun::query()->findOrFail($id);
        $task = $this->catalog->get($run->task_key);
        $started = DB::transaction(function () use ($run, $task): bool {
            $state = DB::table('operational_task_states')->where('task_key', $run->task_key)->lockForUpdate()->first();
            $run->refresh();
            if ((int) $state->active_run_id !== $run->id || $run->status !== 'queued') {
                return false;
            }
            // A delayed or duplicate delivery can never revive an expired request.
            if ($run->origin === 'manual' && ($run->queued_at->lt(now()->subMinutes(15))
                || ! ReportUser::query()->whereKey($run->report_user_id)->where('is_active', true)->where('role', ReportUser::ROLE_ADMIN)->exists())) {
                $this->finish($run, false, null, 'Solicitud caducada o administrador ya no autorizado.');

                return false;
            }
            $run->update(['status' => 'running', 'started_at' => now(), 'summary' => 'Ejecución iniciada.', 'arguments' => $task['arguments']]);

            return true;
        }, 3);

        if (! $started) {
            return;
        }
        try {
            // No output capture: providers may emit PII or credentials to stdout.
            // Only the fixed catalog is executable, never persisted/request options.
            $output = new OperationalTaskOutput($run->task_key);
            $exit = Artisan::call($task['command'], $task['arguments'], $output);
            $skipped = $output->skipped || ($task['command'] === 'stock:sync-daily' && $exit === 2);
            $healthIncidents = $run->task_key === OperationalTaskCatalog::HEALTH_TASK_KEY && $exit === OperationalTaskExitCode::Unhealthy->value;
            $success = $exit === 0 && ! $skipped && ! $output->partialFailure;
            $error = $success || $skipped || $healthIncidents ? null : 'El comando falló o comunicó una integración parcial.';
        } catch (Throwable) {
            // Deliberate information minimization; never persist/report raw exceptions.
            $success = false;
            $exit = null;
            $skipped = false;
            $error = 'La ejecución produjo una excepción. Revisar el sistema de integración por un canal autorizado.';
        }
        // Persistence and recovery failures must reach the caller, never masquerade as completion.
        $this->finish($run, $success, $exit, $error, $skipped);
    }

    public function finish(OperationalTaskRun $run, bool $success, ?int $exit, ?string $error = null, bool $skipped = false): void
    {
        $healthIncidents = $run->task_key === OperationalTaskCatalog::HEALTH_TASK_KEY
            && $exit === OperationalTaskExitCode::Unhealthy->value && ! $skipped && $error === null;
        DB::transaction(function () use ($run, $success, $exit, $error, $skipped, $healthIncidents): void {
            $state = DB::table('operational_task_states')->where('task_key', $run->task_key)->lockForUpdate()->first();
            $run->refresh();
            if ((int) $state->active_run_id !== $run->id || ! in_array($run->status, ['queued', 'running'], true)) {
                return;
            }
            $run->update([
                'status' => $healthIncidents ? 'unhealthy' : ($skipped ? 'skipped' : ($success ? 'success' : 'failed')), 'exit_code' => $exit,
                'finished_at' => now(),
                'duration_ms' => $run->started_at ? max(0, (int) $run->started_at->diffInMilliseconds(now())) : null,
                'summary' => $healthIncidents ? 'Comprobación completada con incidencias de salud.' : ($skipped ? 'Trabajo omitido; sin heartbeat de éxito.' : ($success ? 'Comando finalizado correctamente.' : 'Ejecución fallida.')),
                'error' => $error,
            ]);
            $changes = ['active_run_id' => null, 'updated_at' => now(), 'last_completed_id' => $run->id];
            if (! $skipped && ! $healthIncidents) {
                $changes[$success ? 'last_success_id' : 'last_failure_id'] = $run->id;
            }
            DB::table('operational_task_states')->where('task_key', $run->task_key)->update($changes);
            if (! $skipped) {
                $this->reconcileFailure($run->task_key, $success || $healthIncidents);
            }
        }, 3);

        if ($success || $healthIncidents) {
            app(OperationalTaskHealthService::class)->check($run->task_key);
        }
    }

    public function release(int $id, string $reason, ?string $reference = null): bool
    {
        if (! in_array($reason, self::RELEASE_REASONS, true)
            || ($reference !== null && ! preg_match('/\AOPS-[0-9]{1,12}\z/D', $reference))) {
            throw new \InvalidArgumentException('Invalid release metadata.');
        }
        $run = OperationalTaskRun::query()->find($id);
        if (! $run) {
            return false;
        }

        return DB::transaction(function () use ($run, $reason, $reference): bool {
            $state = DB::table('operational_task_states')->where('task_key', $run->task_key)->lockForUpdate()->first();
            $run->refresh();
            $window = $this->catalog->get($run->task_key)['window_minutes'];
            $orphanCandidate = match ($run->status) {
                'queued' => $run->queued_at->lt(now()->subMinutes(15)),
                'running' => $run->started_at !== null && $run->started_at->lt(now()->subMinutes($window)),
                'unknown' => true,
                default => false,
            };
            if (! $state || (int) $state->active_run_id !== $run->id || ! $orphanCandidate
                || ($reason === 'queue_expired' && $run->status !== 'queued')
                || ($reason === 'worker_stopped' && $run->origin !== 'manual')
                || ($reason === 'scheduler_stopped' && $run->origin !== 'scheduler')) {
                return false;
            }
            // Effective UID is a verified OS principal, never a claimed human.
            // USER/SUDO_USER/get_current_user and arbitrary --actor are not trusted.
            $uid = function_exists('posix_geteuid') ? posix_geteuid() : null;
            DB::table('operational_task_releases')->insert([
                'run_id' => $run->id, 'task_key' => $run->task_key,
                'run_correlation_id' => $run->correlation_id,
                'actor_type' => $uid === null ? 'unverified_cli' : 'os_uid',
                'actor_identifier' => $uid,
                'reason' => $reason, 'reference' => $reference, 'released_at' => now(),
            ]);
            if ($run->status === 'unknown') {
                $run->update(['status' => 'running']);
            }
            $this->finish($run, false, null, 'Reserva liberada tras confirmación externa de proceso detenido. Motivo: '.$reason.'.');

            return true;
        }, 3);
    }

    public function jobFailed(int $id): void
    {
        $run = OperationalTaskRun::query()->find($id);
        if (! $run) {
            return;
        }
        DB::transaction(function () use ($run): void {
            $state = DB::table('operational_task_states')->where('task_key', $run->task_key)->lockForUpdate()->first();
            $run->refresh();
            if (! $state || (int) $state->active_run_id !== $run->id) {
                return;
            }
            if ($run->status === 'queued') {
                $this->finish($run, false, null, 'Queue no pudo iniciar la ejecución.');
            } elseif ($run->status === 'running') {
                // Hard termination cannot prove that every child process is dead.
                $run->update(['status' => 'unknown', 'summary' => 'Worker interrumpido; reserva conservada.', 'error' => 'Fin del proceso no verificable.']);
                $this->reconcileFailure($run->task_key, false);
            }
        }, 3);
    }

    private function reconcileFailure(string $key, bool $success): void
    {
        if ($success) {
            foreach (['operational_task_failure', 'scheduled_task_failure'] as $type) {
                $this->alerts->resolve($type, $type === 'scheduled_task_failure' ? 'scheduler' : 'operations', $key, 'Ejecución posterior correcta.');
            }
        } else {
            $this->alerts->open('operational_task_failure', 'high', 'operations', $key, 'Una tarea operacional ha fallado. Consulte su registro de ejecución.');
        }
    }
}
