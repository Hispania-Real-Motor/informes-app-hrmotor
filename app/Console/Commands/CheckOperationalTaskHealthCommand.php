<?php

namespace App\Console\Commands;

use App\Services\Operations\OperationalTaskExitCode;
use App\Services\Operations\OperationalTaskHealthService;
use Illuminate\Console\Command;
use Throwable;

class CheckOperationalTaskHealthCommand extends Command
{
    protected $signature = 'operations:check-health';

    protected $description = 'Comprueba evidencia operacional sin ejecutar sincronizaciones.';

    public function handle(OperationalTaskHealthService $health): int
    {
        try {
            $unhealthy = $health->check();
            $this->info('Tareas con incidencias exigibles: '.$unhealthy);

            return ($unhealthy > 0 ? OperationalTaskExitCode::Unhealthy : OperationalTaskExitCode::Success)->value;
        } catch (Throwable) {
            // Do not recurse into the same potentially unavailable alert/DB stack.
            $this->error('No se pudo completar la comprobación operacional.');

            return OperationalTaskExitCode::TechnicalFailure->value;
        }
    }
}
