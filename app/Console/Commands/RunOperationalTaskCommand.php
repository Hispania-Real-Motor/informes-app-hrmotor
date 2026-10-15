<?php

namespace App\Console\Commands;

use App\Services\Operations\OperationalTaskCatalog;
use App\Services\Operations\OperationalTaskExitCode;
use App\Services\Operations\OperationalTaskService;
use Illuminate\Console\Command;
use Throwable;

class RunOperationalTaskCommand extends Command
{
    protected $signature = 'operations:run {task : Clave cerrada del catálogo operacional}';

    protected $description = 'Ejecuta un contrato fijo con auditoría y exclusión compartida.';

    public function handle(OperationalTaskCatalog $catalog, OperationalTaskService $tasks): int
    {
        $key = (string) $this->argument('task');
        if (! array_key_exists($key, $catalog->all())) {
            $this->error('Tarea desconocida.');

            return OperationalTaskExitCode::InvalidRequest->value;
        }
        try {
            $run = $tasks->claim($key, 'scheduler');
            if ($run->status !== 'skipped') {
                $tasks->execute($run->id);
            }

            return OperationalTaskExitCode::forRun($run->refresh())->value;
        } catch (Throwable) {
            $this->error('No se pudo completar el contrato operacional.');

            return OperationalTaskExitCode::TechnicalFailure->value;
        }
    }
}
