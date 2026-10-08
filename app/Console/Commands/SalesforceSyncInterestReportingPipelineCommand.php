<?php

namespace App\Console\Commands;

use App\Services\Salesforce\SalesforceInterestReportingPipelineService;
use App\Support\IntegrationErrorSanitizer;
use Illuminate\Console\Command;
use Throwable;

final class SalesforceSyncInterestReportingPipelineCommand extends Command
{
    protected $signature = 'salesforce:sync-interest-reporting';

    protected $description = 'Ejecuta de forma serializada F2 incremental y F5 para los informes Interest.';

    public function handle(
        SalesforceInterestReportingPipelineService $pipeline,
    ): int {
        try {
            $result = $pipeline->run();
            $f2Run = $result['interest_run'];
            $f5Run = $result['activity_run'];

            $this->line('INTEREST_REPORTING_PIPELINE='.json_encode([
                'status' => 'completed',
                'interest_sync_run_id' => $f2Run->id,
                'activity_run_id' => $f5Run->id,
                'source_cutoff_at' => $f2Run->source_cutoff_at->toIso8601String(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('El pipeline Interest no se completó de forma coherente.');
            $this->line(IntegrationErrorSanitizer::sanitizeMessage($exception->getMessage()));

            return self::FAILURE;
        }
    }
}
