<?php

namespace App\Console\Commands;

use App\Services\Campaigns\CampaignDailyOperationalAutomation;
use Illuminate\Console\Command;
use Throwable;

final class SyncCampaignDailyOperationalCommand extends Command
{
    protected $signature = 'campaigns:sync-daily-operational';

    protected $description = 'Actualiza fuentes de Campañas y prepara atribución provisional sin publicar la fuente oficial.';

    public function handle(CampaignDailyOperationalAutomation $automation): int
    {
        try {
            $this->line(json_encode($automation->run(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('La actualización operacional de Campañas no se completó.');

            return self::FAILURE;
        }
    }
}
