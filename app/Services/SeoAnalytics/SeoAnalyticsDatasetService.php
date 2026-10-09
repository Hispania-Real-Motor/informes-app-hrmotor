<?php

namespace App\Services\SeoAnalytics;

use App\Models\SeoGa4OrganicDailyMetric;
use App\Models\SeoGa4OrganicKeyEventDailyMetric;
use App\Models\SeoSalesforceOrganicInterestDailyMetric;
use App\Models\SeoSearchConsoleDailyMetric;
use App\Models\SeoSearchConsoleDimensionMetric;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class SeoAnalyticsDatasetService
{
    public function __construct(
        private readonly SeoTechnicalHealthDatasetService $technicalHealth,
        private readonly SeoSourceStatusDatasetService $sourceStatus,
        private readonly SeoAnalyticalComparisonDatasetService $analyticalComparisons,
        private readonly SeoAnalyticalEvaluationDatasetService $analyticalEvaluations,
    ) {}

    /** @return array<string, mixed> */
    public function build(mixed $requestedRange, mixed $requestedSection): array
    {
        $ranges = config('seo_analytics.dashboard_ranges', [7, 28, 90]);
        $allowedRanges = array_map('strval', $ranges);
        $range = is_string($requestedRange) && in_array($requestedRange, $allowedRanges, true)
            ? (int) $requestedRange
            : (int) config('seo_analytics.default_dashboard_range', 28);
        $sections = ['summary', 'traffic', 'search', 'health', 'geo'];
        $section = is_string($requestedSection) && in_array($requestedSection, $sections, true)
            ? $requestedSection
            : 'summary';

        $sourceStatus = $this->sourceStatus->build();
        $property = $sourceStatus['search_property'];
        $ga4PropertyId = $sourceStatus['ga4_property_id'];
        $searchCutoff = $sourceStatus['cutoffs']['search_console'];
        $salesforceCutoff = $sourceStatus['cutoffs']['salesforce'];
        $ga4Cutoff = $sourceStatus['cutoffs']['ga4'];
        $commonCutoff = collect([$searchCutoff, $salesforceCutoff, $ga4Cutoff])
            ->filter()
            ->sortBy(fn (CarbonImmutable $cutoff): int => $cutoff->getTimestamp())
            ->first();
        $commonStart = $commonCutoff?->subDays($range - 1);
        $searchStart = $searchCutoff?->subDays($range - 1);

        $searchRows = ($property && $searchCutoff && $commonCutoff)
            ? SeoSearchConsoleDailyMetric::query()
                ->where('property', $property)
                ->where('is_final', true)
                ->where('data_date', '>=', $commonStart->toDateString())
                ->where('data_date', '<', $commonCutoff->addDay()->toDateString())
                ->get()
            : collect();
        $salesforceRows = ($salesforceCutoff && $commonCutoff)
            ? SeoSalesforceOrganicInterestDailyMetric::query()
                ->where('data_date', '>=', $commonStart->toDateString())
                ->where('data_date', '<', $commonCutoff->addDay()->toDateString())
                ->get()
            : collect();
        $ga4Rows = ($ga4PropertyId && $ga4Cutoff && $commonCutoff)
            ? SeoGa4OrganicDailyMetric::query()
                ->where('property_id', $ga4PropertyId)
                ->where('data_date', '>=', $commonStart->toDateString())
                ->where('data_date', '<', $commonCutoff->addDay()->toDateString())
                ->get()
            : collect();

        $spain = $this->summarize($searchRows->where('country_scope', 'ESP')->where('brand_segment', 'all'), $commonStart, $commonCutoff);
        $global = $this->summarize($searchRows->where('country_scope', 'ALL')->where('brand_segment', 'all'), $commonStart, $commonCutoff);
        $brand = $this->summarize($searchRows->where('country_scope', 'ESP')->where('brand_segment', 'brand'), $commonStart, $commonCutoff);
        $nonBrand = $this->summarize($searchRows->where('country_scope', 'ESP')->where('brand_segment', 'non_brand'), $commonStart, $commonCutoff);
        $rest = $this->restMetric($global, $spain);
        $salesforceInterests = $this->hasCompleteCoverage($salesforceRows, $commonStart, $commonCutoff)
            ? (int) $salesforceRows->sum('interest_count')
            : null;
        $hasSearchConsole = $spain['available'] && $global['available'];
        $hasSalesforce = $salesforceInterests !== null;
        $ga4Spain = $this->ga4Metric($ga4Rows->where('country_scope', 'ESP'), $commonStart, $commonCutoff);
        $ga4Global = $this->ga4Metric($ga4Rows->where('country_scope', 'ALL'), $commonStart, $commonCutoff);
        $ga4Rest = $this->ga4RestMetric($ga4Global, $ga4Spain);
        $hasGa4 = $ga4Spain['available'];

        $dailySearch = $searchRows->where('country_scope', 'ESP')->where('brand_segment', 'all')->keyBy(
            fn (SeoSearchConsoleDailyMetric $row): string => $row->data_date->toDateString()
        );
        $dailySalesforce = $salesforceRows->keyBy(
            fn (SeoSalesforceOrganicInterestDailyMetric $row): string => $row->data_date->toDateString()
        );
        $dailyGa4 = $ga4Rows->where('country_scope', 'ESP')->keyBy(
            fn (SeoGa4OrganicDailyMetric $row): string => $row->data_date->toDateString()
        );
        $daily = [];
        if ($commonStart && $commonCutoff) {
            for ($date = $commonStart; $date->lessThanOrEqualTo($commonCutoff); $date = $date->addDay()) {
                $key = $date->toDateString();
                $sc = $dailySearch->get($key);
                $sf = $dailySalesforce->get($key);
                $ga4 = $dailyGa4->get($key);
                $daily[] = [
                    'date' => $key,
                    'clicks' => $sc?->clicks,
                    'impressions' => $sc?->impressions,
                    'ctr' => $sc?->ctr !== null ? (float) $sc->ctr : null,
                    'position' => $sc?->position !== null ? (float) $sc->position : null,
                    'interests' => $sf?->interest_count,
                    'leads' => $sf?->interest_count,
                    'ga4_key_events' => $ga4?->key_events !== null ? (float) $ga4->key_events : null,
                ];
            }
        }

        $dimensions = collect();
        if ($property && $searchStart && $searchCutoff) {
            $dimensions = SeoSearchConsoleDimensionMetric::query()
                ->where('property', $property)
                ->where('period_days', $range)
                ->whereDate('period_end', $searchCutoff->toDateString())
                ->orderBy('dimension_type')
                ->orderBy('rank')
                ->get();
        }
        $visibleLimit = (int) config('seo_analytics.visible_dimension_limit', 50);
        $ga4Events = collect();
        if ($ga4PropertyId && $commonStart && $commonCutoff) {
            $ga4Events = SeoGa4OrganicKeyEventDailyMetric::query()
                ->selectRaw('event_name, SUM(key_events) as key_events')
                ->where('property_id', $ga4PropertyId)
                ->where('country_scope', 'ESP')
                ->where('data_date', '>=', $commonStart->toDateString())
                ->where('data_date', '<', $commonCutoff->addDay()->toDateString())
                ->groupBy('event_name')
                ->orderByDesc('key_events')
                ->orderBy('event_name')
                ->limit((int) config('seo_analytics.visible_ga4_event_limit', 50))
                ->get();
        }
        $health = $section === 'health' ? $this->technicalHealth->build() : null;
        $sources = $sourceStatus['sources'];
        $sources[] = ['key' => 'sistrix', 'title' => 'SISTRIX AI Check', 'detail' => filled(config('services.sistrix.api_key')) ? 'Acceso AI pendiente de verificar' : 'Pendiente de conectar', 'badge' => filled(config('services.sistrix.api_key')) ? 'Configuración detectada' : 'No configurada'];
        if ($health !== null) {
            $sources[] = $health['source'];
        }

        return [
            'range' => $range,
            'ranges' => $ranges,
            'section' => $section,
            'sections' => [
                'summary' => 'Resumen',
                'traffic' => 'Tráfico y conversión',
                'search' => 'Búsquedas y páginas',
                'health' => 'Salud técnica',
                'geo' => 'GEO / IA',
            ],
            'common_period' => ['start' => $commonStart?->toDateString(), 'end' => $commonCutoff?->toDateString()],
            'search_console_period' => ['start' => $searchStart?->toDateString(), 'end' => $searchCutoff?->toDateString()],
            'cutoffs' => ['search_console' => $searchCutoff?->toDateString(), 'salesforce' => $salesforceCutoff?->toDateString(), 'ga4' => $ga4Cutoff?->toDateString()],
            'sources' => $sources,
            'has_search_console' => $hasSearchConsole,
            'has_salesforce' => $hasSalesforce,
            'has_ga4' => $hasGa4,
            'kpis' => [
                'spain' => $spain,
                'salesforce_interests' => $salesforceInterests,
                'salesforce_leads' => $salesforceInterests,
                'ga4_key_events' => $ga4Spain['key_events'],
            ],
            'segments' => ['brand' => $brand, 'non_brand' => $nonBrand],
            'geography' => [
                'spain' => $spain,
                'rest' => $rest,
            ],
            'daily' => $daily,
            'ga4' => [
                'spain' => $ga4Spain,
                'global' => $ga4Global,
                'rest' => $ga4Rest,
                'events' => $ga4Events,
            ],
            'queries' => $dimensions->where('dimension_type', 'query')->take($visibleLimit)->values(),
            'pages' => $dimensions->where('dimension_type', 'page')->take($visibleLimit)->values(),
            'countries' => $dimensions->where('dimension_type', 'country')->take(100)->values(),
            'health' => $health,
            'analytical_comparisons' => $section === 'summary' ? $this->analyticalComparisons->build() : [],
            'analytical_signals' => $section === 'summary' ? $this->analyticalEvaluations->recentSignals() : [],
        ];
    }

    /** @param Collection<int, SeoSearchConsoleDailyMetric> $rows
     * @return array{available: bool, clicks: ?int, impressions: ?int, ctr: ?float, position: ?float}
     */
    private function summarize(Collection $rows, ?CarbonImmutable $start, ?CarbonImmutable $end): array
    {
        if (! $this->hasCompleteCoverage($rows, $start, $end)) {
            return $this->emptyMetric();
        }

        $clicks = (int) $rows->sum('clicks');
        $impressions = (int) $rows->sum('impressions');
        $weightedPosition = $rows->sum(fn ($row): float => (float) ($row->position ?? 0) * (int) $row->impressions);

        return [
            'available' => true,
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr' => $impressions > 0 ? $clicks / $impressions : null,
            'position' => $impressions > 0 ? $weightedPosition / $impressions : null,
        ];
    }

    private function hasCompleteCoverage(Collection $rows, ?CarbonImmutable $start, ?CarbonImmutable $end): bool
    {
        if (! $start || ! $end || $end->lessThan($start)) {
            return false;
        }

        $dates = $rows->mapWithKeys(fn ($row): array => [$row->data_date->toDateString() => true]);
        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            if (! $dates->has($date->toDateString())) {
                return false;
            }
        }

        return $dates->count() === (int) $start->diffInDays($end) + 1;
    }

    /** @return array{available: false, clicks: null, impressions: null, ctr: null, position: null} */
    private function emptyMetric(): array
    {
        return ['available' => false, 'clicks' => null, 'impressions' => null, 'ctr' => null, 'position' => null];
    }

    private function restMetric(array $global, array $spain): array
    {
        if (! $global['available'] || ! $spain['available']
            || $global['clicks'] < $spain['clicks']
            || $global['impressions'] < $spain['impressions']) {
            return $this->emptyMetric();
        }

        $clicks = $global['clicks'] - $spain['clicks'];
        $impressions = $global['impressions'] - $spain['impressions'];
        $positionNumerator = ($global['position'] !== null && $spain['position'] !== null)
            ? ($global['position'] * $global['impressions']) - ($spain['position'] * $spain['impressions'])
            : null;

        return [
            'available' => true,
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr' => $impressions > 0 ? $clicks / $impressions : null,
            'position' => $impressions > 0 && $positionNumerator !== null && $positionNumerator >= 0
                ? $positionNumerator / $impressions
                : null,
        ];
    }

    /** @return array{available: bool, key_events: ?float} */
    private function ga4Metric(Collection $rows, ?CarbonImmutable $start, ?CarbonImmutable $end): array
    {
        if (! $this->hasCompleteCoverage($rows, $start, $end)) {
            return ['available' => false, 'key_events' => null];
        }

        return ['available' => true, 'key_events' => (float) $rows->sum('key_events')];
    }

    /** @return array{available: bool, key_events: ?float} */
    private function ga4RestMetric(array $global, array $spain): array
    {
        if (! $global['available'] || ! $spain['available']) {
            return ['available' => false, 'key_events' => null];
        }

        $difference = $global['key_events'] - $spain['key_events'];
        if ($difference < 0) {
            return ['available' => false, 'key_events' => null];
        }

        return ['available' => true, 'key_events' => $difference];
    }
}
