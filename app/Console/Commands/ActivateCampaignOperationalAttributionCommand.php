<?php

namespace App\Console\Commands;

use App\Services\Campaigns\CampaignOperationalAttributionBuilder;
use Illuminate\Console\Command;
use Throwable;

final class ActivateCampaignOperationalAttributionCommand extends Command
{
    protected $signature = 'campaigns:activate-operational-attribution {month : Mes YYYY-MM de 2026} {run : ID del run preparado} {--confirm : Autoriza la activación provisional}';

    protected $description = 'Activa un run provisional preparado tras comprobar de nuevo todas sus fuentes.';

    public function handle(CampaignOperationalAttributionBuilder $builder): int
    {
        $month = (string) $this->argument('month');
        $run = (string) $this->argument('run');
        if (! preg_match('/\A2026-(10|11)\z/D', $month) || ! ctype_digit($run)
            || (int) $run < 1 || ! $this->option('confirm')) {
            $this->error('Indique 2026-10 o 2026-11, un run positivo y --confirm.');

            return self::FAILURE;
        }

        try {
            $result = $builder->activate(2026, (int) substr($month, 5, 2), (int) $run);
        } catch (Throwable) {
            $this->error('No se activó la atribución. Revise la evidencia administrativa de forma segura.');

            return self::FAILURE;
        }

        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
