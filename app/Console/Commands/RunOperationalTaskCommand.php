<?php

namespace App\Console\Commands;

use App\Services\Operations\OperationalTaskCatalog;
use App\Services\Operations\OperationalTaskService;
use Illuminate\Console\Command;

class RunOperationalTaskCommand extends Command
{
    protected $signature = 'operations:run {task : Clave cerrada del catálogo operacional}';

    protected $description = 'Ejecuta un contrato fijo con auditoría y exclusión compartida.';

    public function handle(OperationalTaskCatalog $catalog, OperationalTaskService $tasks): int
    {
        $key = (string) $this->argument('task');
        if (! array_key_exists($key, $catalog->all())) {
            $this->error('Tarea desconocida.');

            return self::FAILURE;
        }
        $run = $tasks->claim($key, 'scheduler');
        if ($run->status !== 'skipped') {
            $tasks->execute($run->id);
        }

        return $run->refresh()->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
