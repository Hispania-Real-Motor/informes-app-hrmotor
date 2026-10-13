<?php

namespace App\Services\SeoAnalytics;

use App\Models\ReportSyncRun;
use App\Services\Salesforce\SalesforceInterestSyncService;
use Carbon\CarbonImmutable;

final class SeoSourceStatusDatasetService
{
    public function __construct(
        private readonly SearchConsoleClient $searchConsole,
        private readonly GoogleAnalyticsClient $analytics,
        private readonly SeoSourceStateResolver $sourceStates,
    ) {}

    /**
     * @return array{
     *     search_property: ?string,
     *     ga4_property_id: ?string,
     *     cutoffs: array{search_console: ?CarbonImmutable, salesforce: ?CarbonImmutable, ga4: ?CarbonImmutable},
     *     sources: array<int, array{key: string, title: string, detail: string, badge: string}>
     * }
     */
    public function build(): array
    {
        $configuredProperty = $this->searchConsole->configuredProperty();
        $searchCompletedRun = $this->sourceStates->latestCompletedRun(
            SearchConsoleSyncService::DATASET,
            $configuredProperty,
        );
        $property = $configuredProperty ?? data_get($searchCompletedRun?->stats, 'property');
        $property = is_string($property) && $property !== '' ? $property : null;
        $salesforceCompletedRun = $this->sourceStates->latestCompletedRun(SalesforceOrganicInterestProjectionService::DATASET);
        $salesforceLatestRun = $this->sourceStates->latestRun(SalesforceOrganicInterestProjectionService::DATASET);
        $interestLatestRun = ReportSyncRun::query()
            ->where('dataset', SalesforceInterestSyncService::DATASET)
            ->where('source', SalesforceInterestSyncService::SOURCE)
            ->orderByDesc('id')
            ->first();
        $ga4PropertyId = $this->analytics->configuredPropertyId();
        $ga4CompletedRun = $ga4PropertyId
            ? $this->sourceStates->latestCompletedRun(
                Ga4OrganicConversionSyncService::DATASET,
                $ga4PropertyId,
                'property_id',
            )
            : null;
        $searchCutoff = $property ? $this->sourceStates->cutoff($searchCompletedRun) : null;
        $salesforceCutoff = $this->sourceStates->cutoff($salesforceCompletedRun);
        $ga4Cutoff = $ga4PropertyId ? $this->sourceStates->cutoff($ga4CompletedRun) : null;

        return [
            'search_property' => $property,
            'ga4_property_id' => $ga4PropertyId,
            'cutoffs' => [
                'search_console' => $searchCutoff,
                'salesforce' => $salesforceCutoff,
                'ga4' => $ga4Cutoff,
            ],
            'sources' => [
                $this->source(
                    'search-console',
                    'Search Console',
                    $this->searchConsole->configured(),
                    $searchCutoff,
                    SearchConsoleSyncService::DATASET,
                    $property,
                ),
                $this->salesforceSource($salesforceLatestRun, $interestLatestRun, $salesforceCutoff),
                $ga4PropertyId
                    ? $this->source(
                        'ga4',
                        'Google Analytics 4',
                        $this->analytics->configured(),
                        $ga4Cutoff,
                        Ga4OrganicConversionSyncService::DATASET,
                        $ga4PropertyId,
                        'property_id',
                    )
                    : [
                        'key' => 'ga4',
                        'title' => 'Google Analytics 4',
                        'detail' => 'Pendiente de configurar',
                        'badge' => 'No configurada',
                    ],
            ],
        ];
    }

    /** @return array{key: string, title: string, detail: string, badge: string} */
    private function salesforceSource(
        ?ReportSyncRun $projectionRun,
        ?ReportSyncRun $interestRun,
        ?CarbonImmutable $cutoff,
    ): array {
        $key = 'salesforce';
        $title = 'Salesforce';
        if ($projectionRun?->status === 'failed') {
            return compact('key', 'title') + [
                'detail' => $cutoff
                    ? 'Datos anteriores cerrados hasta: '.$cutoff->toDateString().'. La última proyección falló.'
                    : 'La última proyección local finalizó con error técnico.',
                'badge' => 'Error último sync',
            ];
        }
        if ($projectionRun?->status === 'running') {
            return compact('key', 'title') + [
                'detail' => $cutoff
                    ? 'Datos anteriores cerrados hasta: '.$cutoff->toDateString().'. Proyección en curso.'
                    : 'Proyección local en curso; todavía no existe un cutoff completado.',
                'badge' => 'Sincronizando',
            ];
        }

        $stableInterestSource = $interestRun?->status === 'completed'
            && $interestRun->source_cutoff_at !== null;
        if (! $stableInterestSource) {
            return compact('key', 'title') + [
                'detail' => $cutoff
                    ? 'Datos anteriores cerrados hasta: '.$cutoff->toDateString().'. F2 Interests no está disponible actualmente.'
                    : 'F2 Interests no dispone de un último run completed con cutoff.',
                'badge' => 'F2 no disponible',
            ];
        }
        if ($cutoff !== null) {
            return compact('key', 'title') + [
                'detail' => 'Intereses orgánicos proyectados desde F2 hasta: '.$cutoff->toDateString(),
                'badge' => 'Sincronizada',
            ];
        }

        return compact('key', 'title') + [
            'detail' => 'F2 Interests disponible; proyección SEO todavía no ejecutada.',
            'badge' => 'Sin datos',
        ];
    }

    /** @return array{key: string, title: string, detail: string, badge: string} */
    private function source(
        string $key,
        string $title,
        bool $configured,
        ?CarbonImmutable $cutoff,
        string $dataset,
        ?string $property = null,
        string $propertyStat = 'property',
    ): array {
        $latestRun = $this->sourceStates->latestRun($dataset, $property, $propertyStat);
        if ($latestRun?->status === 'failed') {
            $detail = $cutoff
                ? 'Datos anteriores cerrados hasta: '.$cutoff->toDateString().'. La última sincronización falló.'
                : 'La última sincronización finalizó con error técnico.';

            return compact('key', 'title', 'detail') + ['badge' => 'Error último sync'];
        }
        if ($latestRun?->status === 'running') {
            return compact('key', 'title') + [
                'detail' => $cutoff
                    ? 'Datos cerrados hasta: '.$cutoff->toDateString().'. Sincronización en curso.'
                    : 'Sincronización en curso; todavía no existe un cutoff completado.',
                'badge' => 'Sincronizando',
            ];
        }
        if ($cutoff) {
            return compact('key', 'title') + [
                'detail' => 'Datos cerrados hasta: '.$cutoff->toDateString(),
                'badge' => 'Sincronizada',
            ];
        }

        return compact('key', 'title') + [
            'detail' => $configured ? 'Configuración detectada; sin datos sincronizados' : 'Pendiente de configurar',
            'badge' => $configured ? 'Sin datos' : 'No configurada',
        ];
    }
}
