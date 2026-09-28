<?php

namespace App\Console\Commands;

use App\Services\Salesforce\SalesforceInterestSyncService;
use App\Support\IntegrationErrorSanitizer;
use Illuminate\Console\Command;
use Throwable;

class SalesforceSyncInterestsCommand extends Command
{
    protected $signature = 'salesforce:sync-interests
        {--full : Ejecuta la carga inicial completa con corte fijo}
        {--overlap-seconds= : Solape UTC para el incremental; por defecto usa la configuración}';

    protected $description = 'Replica Interes__c localmente mediante consultas Salesforce de solo lectura.';

    public function handle(SalesforceInterestSyncService $sync): int
    {
        $mode = (bool) $this->option('full')
            ? SalesforceInterestSyncService::MODE_FULL
            : SalesforceInterestSyncService::MODE_INCREMENTAL;
        $overlap = $this->option('overlap-seconds');

        if ($overlap !== null && preg_match('/^\d+$/', (string) $overlap) !== 1) {
            $this->error('--overlap-seconds debe ser un entero mayor o igual que cero.');

            return self::FAILURE;
        }

        try {
            $result = $sync->sync($mode, overlapSeconds: $overlap === null ? null : (int) $overlap);
            $stats = $result['stats'];

            $this->info('Sincronización local de Interests completada.');
            $this->line('Modo: '.$stats['mode']);
            $this->line('Ventana UTC: '.($stats['window_start'] ?? 'inicio completo').' → '.$stats['window_end']);
            $this->line('Páginas/registros: '.$stats['pages'].'/'.$stats['queried']);
            $this->line('Insertados/actualizados/sin cambios: '.$stats['inserted'].'/'.$stats['updated'].'/'.$stats['unchanged']);
            $this->line('Eliminados/reactivados/errores: '.$stats['deleted'].'/'.$stats['reactivated'].'/'.$stats['errors']);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('La sincronización local de Interests ha fallado.');
            $this->line(IntegrationErrorSanitizer::sanitizeMessage($exception->getMessage()));

            return self::FAILURE;
        }
    }
}
