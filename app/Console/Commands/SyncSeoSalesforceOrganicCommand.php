<?php

namespace App\Console\Commands;

use App\Services\SeoAnalytics\SalesforceOrganicInterestProjectionService;
use App\Support\IntegrationErrorSanitizer;
use Illuminate\Console\Command;
use Throwable;

class SyncSeoSalesforceOrganicCommand extends Command
{
    protected $signature = 'seo:sync-salesforce-organic {--days=120 : Dias cerrados que se sincronizan (1-480)}';

    protected $description = 'Proyecta diariamente los Intereses organicos del snapshot local F2 para SEO.';

    public function handle(SalesforceOrganicInterestProjectionService $projection): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
        if ($days === false || $days < 1 || $days > (int) config('seo_analytics.max_history_sync_days', 480)) {
            $this->error('--days debe ser un entero entre 1 y 480.');

            return self::FAILURE;
        }

        try {
            $result = $projection->sync($days);
            $this->info('Intereses organicos Salesforce proyectados hasta '.$result['period_end']->toDateString().'.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Error proyectando Intereses organicos Salesforce.');
            $this->line(IntegrationErrorSanitizer::sanitizeMessage($exception->getMessage(), 300));

            return self::FAILURE;
        }
    }
}
