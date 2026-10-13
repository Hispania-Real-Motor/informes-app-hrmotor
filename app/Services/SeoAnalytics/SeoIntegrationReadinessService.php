<?php

namespace App\Services\SeoAnalytics;

use App\Models\ReportSyncRun;
use App\Services\Salesforce\SalesforceInterestSyncService;

final class SeoIntegrationReadinessService
{
    public function __construct(
        private readonly SearchConsoleClient $searchConsole,
        private readonly GoogleAnalyticsClient $analytics,
        private readonly SistrixClient $sistrix,
    ) {}

    /** @return array<int, array{key: string, title: string, detail: string, badge: string}> */
    public function sources(): array
    {
        $salesforceConfigured = $this->salesforceAvailable();

        return [
            $this->source(
                'search-console',
                'Search Console',
                $this->searchConsole->configured(),
                'Pendiente de configurar',
                'Configuración detectada · acceso pendiente de validar'
            ),
            $this->source(
                'salesforce',
                'Salesforce',
                $salesforceConfigured,
                'F2 Interests no dispone de un último run completed con cutoff',
                'F2 Interests disponible · proyección SEO local preparada'
            ),
            $this->source(
                'ga4',
                'Google Analytics 4',
                $this->analytics->configured(),
                'Pendiente de configurar',
                'Configuración detectada · acceso pendiente de validar'
            ),
            $this->source(
                'sistrix',
                'SISTRIX AI Check',
                $this->sistrix->configured(),
                'Pendiente de conectar',
                'API configurada · acceso básico pendiente de validar'
            ),
        ];
    }

    private function salesforceAvailable(): bool
    {
        $latest = ReportSyncRun::query()
            ->where('dataset', SalesforceInterestSyncService::DATASET)
            ->where('source', SalesforceInterestSyncService::SOURCE)
            ->orderByDesc('id')
            ->first();

        return $latest?->status === 'completed' && $latest->source_cutoff_at !== null;
    }

    /** @return array{key: string, title: string, detail: string, badge: string} */
    private function source(
        string $key,
        string $title,
        bool $configured,
        string $missingDetail,
        string $configuredDetail,
    ): array {
        return [
            'key' => $key,
            'title' => $title,
            'detail' => $configured ? $configuredDetail : $missingDetail,
            'badge' => $configured ? 'Configurada' : 'No configurada',
        ];
    }
}
