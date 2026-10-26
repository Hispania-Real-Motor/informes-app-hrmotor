<?php

namespace App\Console\Commands;

use App\Services\Campaigns\CampaignCurrentStatusService;
use Illuminate\Console\Command;
use Throwable;

class SyncCampaignCurrentStatus extends Command
{
    protected $signature = 'campaigns:sync-current-status {--operational-run= : Reserva interna del catálogo operacional}';

    protected $description = 'Actualiza exclusivamente el inventario operativo de campañas mediante una reserva operacional';

    public function handle(CampaignCurrentStatusService $statuses): int
    {
        try {
            $run = $this->option('operational-run');
            if (! is_string($run) || ! preg_match('/\A[1-9][0-9]{0,18}\z/D', $run)) {
                $this->error('Utilice operations:run campaigns-sync-current-status con la tarea activada.');

                return self::FAILURE;
            }
            $stats = $statuses->sync((int) $run);
            $this->info(sprintf('Cuentas: %d; campañas leídas: %d; cuentas fallidas: %d.', $stats['accounts'], $stats['campaigns'], $stats['failed']));

            return $stats['accounts'] > 0 && $stats['failed'] === 0 ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            $this->error('Inventario no actualizado. Revise la activación y la supervisión operacional.');

            return self::FAILURE;
        }
    }
}
