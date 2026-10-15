<?php

namespace App\Console\Commands;

use App\Services\Operations\OperationalTaskExitCode;
use App\Services\Operations\OperationalTaskService;
use Illuminate\Console\Command;
use Throwable;

class ReleaseOperationalTaskCommand extends Command
{
    protected $signature = 'operations:release
        {run : ID de ejecución}
        {--confirm-stopped : Confirma verificación externa de terminación del proceso}
        {--reason= : worker_stopped, scheduler_stopped, queue_expired o process_terminated}
        {--reference= : Referencia opcional OPS seguida de guion y hasta 12 dígitos}';

    protected $description = 'Libera una reserva huérfana con confirmación externa y auditoría.';

    public function handle(OperationalTaskService $tasks): int
    {
        $id = filter_var($this->argument('run'), FILTER_VALIDATE_INT);
        $reason = (string) $this->option('reason');
        $reference = $this->option('reference');
        if (! $this->option('confirm-stopped') || ! $id || $id < 1
            || ! in_array($reason, OperationalTaskService::RELEASE_REASONS, true)
            || ($reference !== null && ! preg_match('/\AOPS-[0-9]{1,12}\z/D', $reference))) {
            $this->error('Se requieren ID válido, motivo permitido y confirmación de proceso detenido. No incluya texto libre ni datos personales.');

            return OperationalTaskExitCode::InvalidRequest->value;
        }
        try {
            if (! $tasks->release($id, $reason, $reference)) {
                $this->error('La reserva no es huérfana o no cumple la política de liberación.');

                return OperationalTaskExitCode::Blocked->value;
            }
        } catch (Throwable) {
            $this->error('No se pudo registrar la liberación; compruebe el estado antes de reintentar.');

            return OperationalTaskExitCode::TechnicalFailure->value;
        }
        $this->info('Reserva liberada con registro auditable.');

        return OperationalTaskExitCode::Success->value;
    }
}
