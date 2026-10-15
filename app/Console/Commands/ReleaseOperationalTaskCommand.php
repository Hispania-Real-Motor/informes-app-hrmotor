<?php

namespace App\Console\Commands;

use App\Models\OperationalTaskRun;
use App\Services\Operations\OperationalTaskService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReleaseOperationalTaskCommand extends Command
{
    protected $signature = 'operations:release {run : ID de ejecución} {--confirm-stopped : Confirma que el operador verificó la terminación del proceso}';

    protected $description = 'Libera una reserva huérfana solo tras verificación humana de terminación.';

    public function handle(OperationalTaskService $tasks): int
    {
        if (! $this->option('confirm-stopped')) {
            $this->error('Verifique primero que el proceso y sus workers han terminado.');

            return self::FAILURE;
        }
        $id = filter_var($this->argument('run'), FILTER_VALIDATE_INT);
        if (! $id || ! ($run = OperationalTaskRun::query()->find($id))) {
            return self::FAILURE;
        }
        $released = DB::transaction(function () use ($run, $tasks): bool {
            $state = DB::table('operational_task_states')->where('task_key', $run->task_key)->lockForUpdate()->first();
            $run->refresh();
            if (! $state || (int) $state->active_run_id !== $run->id || ! in_array($run->status, ['queued', 'running', 'unknown'], true)) {
                return false;
            }
            if ($run->status === 'unknown') {
                // Re-enter a terminal transition without ever claiming success.
                $run->update(['status' => 'running']);
            }
            $tasks->finish($run, false, null, 'Reserva liberada por operador CLI tras confirmar proceso detenido.');

            return true;
        }, 3);
        if (! $released) {
            $this->error('La ejecución no tiene una reserva activa liberable.');

            return self::FAILURE;
        }
        $this->info('Solicitud de liberación registrada; consulte el ledger.');

        return self::SUCCESS;
    }
}
