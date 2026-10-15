<?php

namespace App\Console\Commands;

use App\Services\Operations\OperationalTaskHealthService;
use Illuminate\Console\Command;

class CheckOperationalTaskHealthCommand extends Command
{
    protected $signature = 'operations:check-health';

    protected $description = 'Comprueba evidencia operacional sin ejecutar sincronizaciones.';

    public function handle(OperationalTaskHealthService $health): int
    {
        $this->info('Tareas sin evidencia suficiente: '.$health->check());

        return self::SUCCESS;
    }
}
