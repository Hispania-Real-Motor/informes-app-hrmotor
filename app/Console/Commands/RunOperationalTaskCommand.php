<?php

namespace App\Console\Commands;

use App\Services\Operations\OperationalTaskCatalog;
use App\Services\Operations\OperationalTaskExitCode;
use App\Services\Operations\OperationalTaskService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class RunOperationalTaskCommand extends Command
{
    protected $signature = 'operations:run {task : Clave cerrada del catálogo operacional} {--scheduler : Adapta exclusivamente una evaluación completada del monitor al scheduler}';

    protected $description = 'Ejecuta un contrato fijo con auditoría y exclusión compartida.';

    public function handle(OperationalTaskCatalog $catalog, OperationalTaskService $tasks): int
    {
        $key = (string) $this->argument('task');
        try {
            if (! array_key_exists($key, $catalog->all()) || ($this->option('scheduler') && $key !== OperationalTaskCatalog::HEALTH_TASK_KEY)) {
                $this->error('Tarea desconocida.');

                return OperationalTaskExitCode::InvalidRequest->value;
            }
            $run = $tasks->claim($key, 'scheduler');
            if ($run->status !== 'skipped') {
                $tasks->execute($run->id);
            }

            $outcome = OperationalTaskExitCode::forRun($run->refresh());
            if ($this->option('scheduler') && $outcome === OperationalTaskExitCode::Unhealthy) {
                // Only a persisted assessment with a completed reservation is a technical success.
                $completed = DB::table('operational_task_states')->where('task_key', $key)
                    ->whereNull('active_run_id')->where('last_completed_id', $run->id)->exists();

                return ($completed ? OperationalTaskExitCode::Success : OperationalTaskExitCode::TechnicalFailure)->value;
            }

            return $outcome->value;
        } catch (Throwable) {
            $this->error('No se pudo completar el contrato operacional.');

            return OperationalTaskExitCode::TechnicalFailure->value;
        }
    }
}
