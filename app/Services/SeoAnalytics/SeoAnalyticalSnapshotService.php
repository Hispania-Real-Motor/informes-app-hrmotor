<?php

namespace App\Services\SeoAnalytics;

use App\Models\AnalyticalMetricSnapshot;
use App\Models\ReportSyncRun;
use App\Models\SeoGa4OrganicDailyMetric;
use App\Models\SeoSalesforceOrganicInterestDailyMetric;
use App\Models\SeoSearchConsoleDailyMetric;
use App\Services\Analytics\SameWeekdayComparisonEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class SeoAnalyticalSnapshotService
{
    public const DATASET = 'seo_analytical_snapshots';

    public const SOURCE = 'local_database';

    public function __construct(
        private readonly SameWeekdayComparisonEngine $engine,
        private readonly SeoAnalyticalMetricRegistry $registry,
        private readonly SeoSourceStateResolver $sourceStates,
        private readonly SearchConsoleClient $searchConsole,
        private readonly GoogleAnalyticsClient $analytics,
    ) {}

    /**
     * @return array{rows: int, evaluable: int, not_evaluable: int, metric_count: int, max_cutoff: ?CarbonImmutable, cutoffs: array<string, ?string>}
     */
    public function build(int $days): array
    {
        $rows = [];
        $cutoffs = ['search_console' => null, 'salesforce' => null, 'ga4' => null];

        $searchProperty = $this->searchConsole->configuredProperty();
        if ($this->searchConsole->configured() && is_string($searchProperty) && $searchProperty !== '') {
            $run = $this->sourceStates->latestCompletedRun(SearchConsoleSyncService::DATASET, $searchProperty);
            if ($run !== null) {
                $cutoffs['search_console'] = $this->sourceStates->cutoff($run)?->toDateString();
                $rows = array_merge($rows, $this->searchConsoleRows($searchProperty, $run, $days));
            }
        }

        $salesforceRun = $this->sourceStates->latestCompletedRun(SalesforceOrganicInterestProjectionService::DATASET);
        if ($salesforceRun !== null) {
            $cutoffs['salesforce'] = $this->sourceStates->cutoff($salesforceRun)?->toDateString();
            $rows = array_merge($rows, $this->salesforceRows($salesforceRun, $days));
        }

        $ga4Property = $this->analytics->configuredPropertyId();
        if ($this->analytics->configured() && is_string($ga4Property) && $ga4Property !== '') {
            $run = $this->sourceStates->latestCompletedRun(
                Ga4OrganicConversionSyncService::DATASET,
                $ga4Property,
                'property_id',
            );
            if ($run !== null) {
                $cutoffs['ga4'] = $this->sourceStates->cutoff($run)?->toDateString();
                $rows = array_merge($rows, $this->ga4Rows($ga4Property, $run, $days));
            }
        }

        if ($rows !== []) {
            DB::transaction(function () use ($rows): void {
                foreach (array_chunk($rows, 250) as $chunk) {
                    AnalyticalMetricSnapshot::query()->upsert(
                        $chunk,
                        ['module_key', 'metric_key', 'scope_key', 'source_identifier_hash', 'data_date'],
                        [
                            'metric_label', 'source_key', 'source_identifier', 'value_format', 'source_cutoff_at',
                            'current_value', 'd7_value', 'd14_value', 'd21_value', 'd28_value', 'reference_count',
                            'baseline_value', 'absolute_change', 'relative_change', 'd364_value',
                            'year_absolute_change', 'year_relative_change', 'is_evaluable', 'evaluation_reason',
                            'engine_version', 'computed_at', 'updated_at',
                        ],
                    );
                }
            });
        }

        $evaluable = count(array_filter($rows, static fn (array $row): bool => $row['is_evaluable']));
        $maxCutoff = collect($cutoffs)
            ->filter()
            ->map(fn (string $cutoff): CarbonImmutable => CarbonImmutable::parse($cutoff))
            ->sortByDesc(fn (CarbonImmutable $cutoff): int => $cutoff->getTimestamp())
            ->first();

        return [
            'rows' => count($rows),
            'evaluable' => $evaluable,
            'not_evaluable' => count($rows) - $evaluable,
            'metric_count' => count($this->registry->metrics()),
            'max_cutoff' => $maxCutoff,
            'cutoffs' => $cutoffs,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function searchConsoleRows(string $property, ReportSyncRun $run, int $days): array
    {
        $cutoff = $this->sourceStates->cutoff($run);
        if ($cutoff === null) {
            return [];
        }

        $series = SeoSearchConsoleDailyMetric::query()
            ->where('property', $property)
            ->where('country_scope', 'ESP')
            ->where('brand_segment', 'all')
            ->where('is_final', true)
            ->where('data_date', '>=', $cutoff->subDays($days - 1 + SameWeekdayComparisonEngine::YEAR_REFERENCE_OFFSET)->toDateString())
            ->where('data_date', '<', $cutoff->addDay()->toDateString())
            ->get(['data_date', 'clicks', 'impressions', 'ctr', 'position']);

        $definitions = array_values(array_filter(
            $this->registry->metrics(),
            static fn (array $metric): bool => $metric['source'] === 'search_console',
        ));

        return $this->buildRows($definitions, $series, $property, $run, $days);
    }

    /** @return array<int, array<string, mixed>> */
    private function salesforceRows(ReportSyncRun $run, int $days): array
    {
        $cutoff = $this->sourceStates->cutoff($run);
        if ($cutoff === null) {
            return [];
        }

        $series = SeoSalesforceOrganicInterestDailyMetric::query()
            ->where('data_date', '>=', $cutoff->subDays($days - 1 + SameWeekdayComparisonEngine::YEAR_REFERENCE_OFFSET)->toDateString())
            ->where('data_date', '<', $cutoff->addDay()->toDateString())
            ->get(['data_date', 'interest_count']);
        $definitions = array_values(array_filter(
            $this->registry->metrics(),
            static fn (array $metric): bool => $metric['source'] === 'salesforce',
        ));

        return $this->buildRows(
            $definitions,
            $series,
            SeoAnalyticalMetricRegistry::SALESFORCE_SOURCE_IDENTIFIER,
            $run,
            $days,
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function ga4Rows(string $property, ReportSyncRun $run, int $days): array
    {
        $cutoff = $this->sourceStates->cutoff($run);
        if ($cutoff === null) {
            return [];
        }

        $series = SeoGa4OrganicDailyMetric::query()
            ->where('property_id', $property)
            ->where('country_scope', 'ESP')
            ->where('data_date', '>=', $cutoff->subDays($days - 1 + SameWeekdayComparisonEngine::YEAR_REFERENCE_OFFSET)->toDateString())
            ->where('data_date', '<', $cutoff->addDay()->toDateString())
            ->get(['data_date', 'key_events']);
        $definitions = array_values(array_filter(
            $this->registry->metrics(),
            static fn (array $metric): bool => $metric['source'] === 'ga4',
        ));

        return $this->buildRows($definitions, $series, $property, $run, $days);
    }

    /**
     * @param  array<int, array{key: string, label: string, source: string, source_label: string, scope: string, format: string, field: string}>  $definitions
     * @param  Collection<int, mixed>  $series
     * @return array<int, array<string, mixed>>
     */
    private function buildRows(
        array $definitions,
        Collection $series,
        string $sourceIdentifier,
        ReportSyncRun $run,
        int $days,
    ): array {
        $cutoff = $this->sourceStates->cutoff($run);
        if ($cutoff === null) {
            return [];
        }

        $computedAt = now();
        $rows = [];
        foreach ($definitions as $definition) {
            $values = $series->mapWithKeys(function ($row) use ($definition): array {
                $value = $row->{$definition['field']};

                return [$row->data_date->toDateString() => $value];
            })->all();

            for ($offset = $days - 1; $offset >= 0; $offset--) {
                $target = $cutoff->subDays($offset);
                $comparison = $this->engine->compare(
                    $target,
                    array_key_exists($target->toDateString(), $values) ? $values[$target->toDateString()] : null,
                    $values,
                );
                $rows[] = [
                    'module_key' => SeoAnalyticalMetricRegistry::MODULE,
                    'metric_key' => $definition['key'],
                    'metric_label' => $definition['label'],
                    'source_key' => $definition['source'],
                    'source_identifier' => $sourceIdentifier,
                    'source_identifier_hash' => hash('sha256', $sourceIdentifier),
                    'scope_key' => $definition['scope'],
                    'value_format' => $definition['format'],
                    'data_date' => $comparison['target_date'],
                    'source_cutoff_at' => $run->source_cutoff_at,
                    'current_value' => $comparison['current_value'],
                    'd7_value' => $comparison['d7_value'],
                    'd14_value' => $comparison['d14_value'],
                    'd21_value' => $comparison['d21_value'],
                    'd28_value' => $comparison['d28_value'],
                    'reference_count' => $comparison['reference_count'],
                    'baseline_value' => $comparison['baseline_value'],
                    'absolute_change' => $comparison['absolute_change'],
                    'relative_change' => $comparison['relative_change'],
                    'd364_value' => $comparison['d364_value'],
                    'year_absolute_change' => $comparison['year_absolute_change'],
                    'year_relative_change' => $comparison['year_relative_change'],
                    'is_evaluable' => $comparison['is_evaluable'],
                    'evaluation_reason' => $comparison['evaluation_reason'],
                    'engine_version' => SameWeekdayComparisonEngine::VERSION,
                    'computed_at' => $computedAt,
                    'created_at' => $computedAt,
                    'updated_at' => $computedAt,
                ];
            }
        }

        return $rows;
    }
}
