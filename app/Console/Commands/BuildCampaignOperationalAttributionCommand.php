<?php

namespace App\Console\Commands;

use App\Services\Campaigns\CampaignOperationalAttributionBuilder;
use Illuminate\Console\Command;
use Throwable;

final class BuildCampaignOperationalAttributionCommand extends Command
{
    protected $signature = 'campaigns:build-operational-attribution {month : Mes YYYY-MM de 2026} {--confirm : Autoriza solo la preparación local}';

    protected $description = 'Prepara atribución provisional versionada sin activarla ni modificar la atribución oficial.';

    public function handle(CampaignOperationalAttributionBuilder $builder): int
    {
        $month = (string) $this->argument('month');
        if (! preg_match('/\A2026-(10|11)\z/D', $month) || ! $this->option('confirm')) {
            $this->error('Indique 2026-10 o 2026-11 y --confirm.');

            return self::FAILURE;
        }

        try {
            $result = $builder->build(2026, (int) substr($month, 5, 2));
        } catch (Throwable) {
            $this->error('No se preparó una nueva atribución. Revise las fuentes y el estado administrativo de forma segura.');

            return self::FAILURE;
        }

        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
