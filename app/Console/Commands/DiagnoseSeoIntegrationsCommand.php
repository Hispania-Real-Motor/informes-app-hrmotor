<?php

namespace App\Console\Commands;

use App\Models\ReportSyncRun;
use App\Services\Salesforce\SalesforceInterestSyncService;
use App\Services\SeoAnalytics\GoogleAnalyticsClient;
use App\Services\SeoAnalytics\SalesforceOrganicInterestProjectionService;
use App\Services\SeoAnalytics\SearchConsoleClient;
use App\Services\SeoAnalytics\SistrixClient;
use App\Support\IntegrationErrorSanitizer;
use Illuminate\Console\Command;
use Throwable;

class DiagnoseSeoIntegrationsCommand extends Command
{
    protected $signature = 'seo:diagnose-integrations {--live : Ejecuta verificaciones externas exclusivamente read-only}';

    protected $description = 'Diagnostica de forma segura la configuracion y el acceso read-only de las fuentes SEO.';

    public function handle(
        SearchConsoleClient $searchConsole,
        GoogleAnalyticsClient $analytics,
        SistrixClient $sistrix,
    ): int {
        $this->components->info($this->option('live')
            ? 'Diagnóstico SEO/Analytics live (solo lectura)'
            : 'Diagnóstico SEO/Analytics de configuración (sin red)');

        $salesforceState = $this->salesforceState();
        $this->configurationSummary($salesforceState, $searchConsole, $analytics, $sistrix);

        if (! $this->option('live')) {
            return self::SUCCESS;
        }

        $failed = false;
        $failed = ! $this->diagnoseSalesforce($salesforceState) || $failed;
        $failed = ! $this->diagnoseSearchConsole($searchConsole) || $failed;
        $failed = ! $this->diagnoseAnalytics($analytics) || $failed;
        $failed = ! $this->diagnoseSistrix($sistrix) || $failed;

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function configurationSummary(
        array $salesforceState,
        SearchConsoleClient $searchConsole,
        GoogleAnalyticsClient $analytics,
        SistrixClient $sistrix,
    ): void {
        $this->table(['Fuente', 'Configuración', 'Identificador no secreto'], [
            ['Salesforce', $this->state($salesforceState['available']), 'F2 Interests / proyección local'],
            ['Search Console', $this->state($searchConsole->configured()), $searchConsole->configuredProperty() ?? '-'],
            ['Google Analytics 4', $this->state($analytics->configured()), $analytics->configuredPropertyId() ?? '-'],
            ['SISTRIX', $this->state($sistrix->configured()), '-'],
        ]);
    }

    /** @param array{available: bool, f2_status: string, f2_cutoff: ?string, projection_status: string, projection_cutoff: ?string} $state */
    private function diagnoseSalesforce(array $state): bool
    {
        $this->newLine();
        $this->components->twoColumnDetail('Salesforce (local)', $state['available'] ? 'disponible' : 'pendiente');
        $this->line('F2 Interests: '.$state['f2_status']);
        $this->line('Cutoff F2: '.($state['f2_cutoff'] ?? '-'));
        $this->line('Proyección SEO Interest: '.$state['projection_status']);
        $this->line('Cutoff proyección: '.($state['projection_cutoff'] ?? '-'));

        return $state['available'];
    }

    private function diagnoseSearchConsole(SearchConsoleClient $client): bool
    {
        $this->newLine();
        $this->components->twoColumnDetail('Search Console', $client->configured() ? 'configurada' : 'pendiente');

        if (! $client->configured()) {
            return true;
        }

        try {
            $result = $client->diagnose();
            $this->line('Property configurada: '.($result['property'] ?? '-'));
            $this->line('Property accesible: '.($result['accessible'] ? 'sí' : 'no'));
            $this->table(
                ['Property accesible', 'Permiso'],
                collect($result['sites'])->map(fn (array $site): array => [
                    $site['property'],
                    $site['permission'],
                ])->all()
            );

            return true;
        } catch (Throwable $exception) {
            return $this->reportFailure('Search Console', $exception);
        }
    }

    private function diagnoseAnalytics(GoogleAnalyticsClient $client): bool
    {
        $this->newLine();
        $this->components->twoColumnDetail('Google Analytics 4', $client->configured() ? 'configurada' : 'pendiente');

        if (! $client->configured()) {
            return true;
        }

        try {
            $result = $client->diagnose();
            $this->line('Property: '.($result['property_id'] ?? '-'));
            $this->line('Property accesible: '.($result['accessible'] ? 'sí' : 'no'));
            $this->line('Metadata: '.($result['metadata'] ? 'accesible' : 'no accesible'));
            $this->line(sprintf('Dimensiones: %d · Métricas: %d', $result['dimensions'], $result['metrics']));
            $this->line('Timezone: '.($result['timezone'] ?? '-'));
            $this->line('Web streams: '.$result['web_stream_count']);
            $this->table(
                ['Stream', 'Tipo', 'Nombre', 'URI web'],
                collect($result['data_streams'])->map(fn (array $stream): array => [
                    $stream['name'],
                    $stream['type'],
                    $stream['display_name'],
                    $stream['default_uri'] ?? '-',
                ])->all()
            );
            $this->line('Key Events: '.($result['key_events'] === [] ? 'ninguno' : implode(', ', $result['key_events'])));

            return true;
        } catch (Throwable $exception) {
            return $this->reportFailure('Google Analytics 4', $exception);
        }
    }

    private function diagnoseSistrix(SistrixClient $client): bool
    {
        $this->newLine();
        $this->components->twoColumnDetail('SISTRIX', $client->configured() ? 'configurada' : 'pendiente de conectar');

        if (! $client->configured()) {
            return true;
        }

        try {
            $result = $client->diagnose();
            $this->line('API SISTRIX: '.($result['api_accessible'] ? 'accesible' : 'no verificada'));
            $this->line('AI Check: pendiente de verificar');

            return true;
        } catch (Throwable $exception) {
            return $this->reportFailure('SISTRIX', $exception);
        }
    }

    private function reportFailure(string $source, Throwable $exception): bool
    {
        $this->error($source.': no accesible.');
        $this->line(IntegrationErrorSanitizer::sanitizeMessage($exception->getMessage(), 300));

        return false;
    }

    /** @return array{available: bool, f2_status: string, f2_cutoff: ?string, projection_status: string, projection_cutoff: ?string} */
    private function salesforceState(): array
    {
        $f2 = ReportSyncRun::query()
            ->where('dataset', SalesforceInterestSyncService::DATASET)
            ->where('source', SalesforceInterestSyncService::SOURCE)
            ->orderByDesc('id')
            ->first();
        $projection = ReportSyncRun::query()
            ->where('dataset', SalesforceOrganicInterestProjectionService::DATASET)
            ->where('source', SalesforceOrganicInterestProjectionService::SOURCE)
            ->orderByDesc('id')
            ->first();
        $completedProjection = ReportSyncRun::query()
            ->where('dataset', SalesforceOrganicInterestProjectionService::DATASET)
            ->where('source', SalesforceOrganicInterestProjectionService::SOURCE)
            ->where('status', 'completed')
            ->whereNotNull('source_cutoff_at')
            ->orderByDesc('id')
            ->first();

        return [
            'available' => $f2?->status === 'completed' && $f2->source_cutoff_at !== null,
            'f2_status' => $f2?->status ?? 'missing',
            'f2_cutoff' => $f2?->source_cutoff_at?->utc()->toIso8601String(),
            'projection_status' => $projection?->status ?? 'missing',
            'projection_cutoff' => $completedProjection?->source_cutoff_at?->utc()->toIso8601String(),
        ];
    }

    private function state(bool $configured): string
    {
        return $configured ? 'OK' : 'PENDIENTE';
    }
}
