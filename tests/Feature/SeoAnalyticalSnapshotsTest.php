<?php

namespace Tests\Feature;

use App\Models\AnalyticalMetricSnapshot;
use App\Models\ReportSyncRun;
use App\Models\SeoGa4OrganicDailyMetric;
use App\Models\SeoSalesforceOrganicInterestDailyMetric;
use App\Models\SeoSearchConsoleDailyMetric;
use App\Services\Analytics\SameWeekdayComparisonEngine;
use App\Services\SeoAnalytics\Ga4OrganicConversionSyncService;
use App\Services\SeoAnalytics\SalesforceOrganicInterestProjectionService;
use App\Services\SeoAnalytics\SearchConsoleSyncService;
use App\Services\SeoAnalytics\SeoAnalyticalComparisonDatasetService;
use App\Services\SeoAnalytics\SeoAnalyticalMetricRegistry;
use App\Services\SeoAnalytics\SeoAnalyticalSnapshotService;
use App\Services\SeoAnalytics\SeoAnalyticsDatasetService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeoAnalyticalSnapshotsTest extends TestCase
{
    use RefreshDatabase;

    private const SEARCH_PROPERTY = 'sc-domain:current.test';

    private const GA4_PROPERTY = '313695489';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google_search_console.client_id' => 'test-search-console-client',
            'services.google_search_console.client_secret' => 'test-search-console-secret',
            'services.google_search_console.refresh_token' => 'test-search-console-refresh',
            'services.google_search_console.property' => self::SEARCH_PROPERTY,
            'services.google_analytics.client_id' => 'test-google-analytics-client',
            'services.google_analytics.client_secret' => 'test-google-analytics-secret',
            'services.google_analytics.refresh_token' => 'test-google-analytics-refresh',
            'services.google_analytics.property_id' => self::GA4_PROPERTY,
            'salesforce.auth_mode' => 'client_credentials',
            'salesforce.token_url' => 'https://example.invalid/oauth2/token',
            'salesforce.client_id' => 'test-salesforce-client',
            'salesforce.client_secret' => 'test-salesforce-secret',
            'salesforce.refresh_token' => null,
        ]);

        Http::preventStrayRequests();
    }

    public function test_builder_creates_exactly_six_metrics_with_source_specific_cutoffs_and_one_series_query_per_source(): void
    {
        $this->completedRun(SearchConsoleSyncService::DATASET, '2026-08-16', ['property' => self::SEARCH_PROPERTY]);
        $this->completedRun(SalesforceOrganicInterestProjectionService::DATASET, '2026-08-18');
        $this->completedRun(Ga4OrganicConversionSyncService::DATASET, '2026-08-16', ['property_id' => self::GA4_PROPERTY]);
        $this->seedSearchSeries('2026-08-16');
        $this->seedSalesforceSeries('2026-08-18');
        $this->seedGa4Series('2026-08-16');

        $sourceQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$sourceQueries): void {
            foreach (['seo_search_console_daily_metrics', 'seo_salesforce_organic_interest_daily_metrics', 'seo_ga4_organic_daily_metrics'] as $table) {
                if (str_contains($query->sql, $table)) {
                    $sourceQueries[] = $table;
                }
            }
        });

        $result = app(SeoAnalyticalSnapshotService::class)->build(1);

        $this->assertSame(6, $result['rows']);
        $this->assertSame(6, AnalyticalMetricSnapshot::query()->count());
        $this->assertSame(1, count(array_filter($sourceQueries, fn (string $table): bool => $table === 'seo_search_console_daily_metrics')));
        $this->assertSame(1, count(array_filter($sourceQueries, fn (string $table): bool => $table === 'seo_salesforce_organic_interest_daily_metrics')));
        $this->assertSame(1, count(array_filter($sourceQueries, fn (string $table): bool => $table === 'seo_ga4_organic_daily_metrics')));
        $this->assertDatabaseHas('analytical_metric_snapshots', [
            'metric_key' => 'search_console_clicks', 'scope_key' => 'ESP', 'data_date' => '2026-08-16',
            'source_identifier_hash' => hash('sha256', self::SEARCH_PROPERTY), 'reference_count' => 4, 'is_evaluable' => true,
        ]);
        $this->assertDatabaseHas('analytical_metric_snapshots', [
            'metric_key' => 'salesforce_organic_interests', 'scope_key' => 'all', 'data_date' => '2026-08-18',
            'source_identifier' => 'salesforce-organic-interests',
        ]);
        $ga4 = AnalyticalMetricSnapshot::query()->where('metric_key', 'ga4_organic_key_events')->firstOrFail();
        $this->assertSame('2026-08-16', $ga4->data_date->toDateString());
        $this->assertSame('0.00002600', $ga4->current_value);
        $this->assertSame('same_weekday_v1', $ga4->engine_version);
        $this->assertSame([
            'search_console_clicks',
            'search_console_impressions',
            'search_console_ctr',
            'search_console_position',
            'salesforce_organic_interests',
            'ga4_organic_key_events',
        ], collect(app(SeoAnalyticalComparisonDatasetService::class)->build())->pluck('metric_key')->all());
    }

    public function test_builder_filters_search_console_and_ga4_by_current_property_scope_and_final_rows(): void
    {
        $this->completedRun(SearchConsoleSyncService::DATASET, '2026-08-16', ['property' => 'old-property'], '2026-08-17 05:15:00');
        $this->completedRun(SearchConsoleSyncService::DATASET, '2026-08-16', ['property' => self::SEARCH_PROPERTY], '2026-08-16 05:15:00');
        $this->completedRun(Ga4OrganicConversionSyncService::DATASET, '2026-08-16', ['property_id' => 'old-ga4'], '2026-08-17 05:45:00');
        $this->completedRun(Ga4OrganicConversionSyncService::DATASET, '2026-08-16', ['property_id' => self::GA4_PROPERTY], '2026-08-16 05:45:00');
        $this->seedSearchSeries('2026-08-16');
        $this->seedGa4Series('2026-08-16');

        SeoSearchConsoleDailyMetric::query()->create($this->searchRow('old-property', '2026-08-16', 'ESP', 'all', true, 999));
        SeoSearchConsoleDailyMetric::query()->create($this->searchRow(self::SEARCH_PROPERTY, '2026-08-16', 'ALL', 'all', true, 888));
        SeoSearchConsoleDailyMetric::query()->create($this->searchRow(self::SEARCH_PROPERTY, '2026-08-16', 'ESP', 'brand', true, 777));
        SeoSearchConsoleDailyMetric::query()->create($this->searchRow(self::SEARCH_PROPERTY, '2026-08-16', 'ESP', 'non_brand', true, 666));
        SeoGa4OrganicDailyMetric::query()->create($this->ga4Row('old-ga4', '2026-08-16', 'ESP', '999.000000'));
        SeoGa4OrganicDailyMetric::query()->create($this->ga4Row(self::GA4_PROPERTY, '2026-08-16', 'ALL', '888.000000'));

        app(SeoAnalyticalSnapshotService::class)->build(1);

        $clicks = AnalyticalMetricSnapshot::query()->where('metric_key', 'search_console_clicks')->firstOrFail();
        $ga4 = AnalyticalMetricSnapshot::query()->where('metric_key', 'ga4_organic_key_events')->firstOrFail();
        $this->assertSame('10.00000000', $clicks->current_value);
        $this->assertSame('0.00002600', $ga4->current_value);
        $this->assertSame(hash('sha256', self::SEARCH_PROPERTY), $clicks->source_identifier_hash);
        $this->assertSame(hash('sha256', self::GA4_PROPERTY), $ga4->source_identifier_hash);
    }

    public function test_missing_sources_do_not_block_available_sources_and_missing_history_is_persisted_without_invented_baseline(): void
    {
        $this->completedRun(SalesforceOrganicInterestProjectionService::DATASET, '2026-08-18');
        SeoSalesforceOrganicInterestDailyMetric::query()->create($this->salesforceRow('2026-08-18', 0));
        SeoSalesforceOrganicInterestDailyMetric::query()->create($this->salesforceRow('2026-08-11', 0));
        SeoSalesforceOrganicInterestDailyMetric::query()->create($this->salesforceRow('2026-08-04', 5));

        $result = app(SeoAnalyticalSnapshotService::class)->build(1);
        $snapshot = AnalyticalMetricSnapshot::query()->sole();

        $this->assertSame(1, $result['rows']);
        $this->assertSame('0.00000000', $snapshot->current_value);
        $this->assertSame(2, $snapshot->reference_count);
        $this->assertFalse($snapshot->is_evaluable);
        $this->assertSame('insufficient_history', $snapshot->evaluation_reason);
        $this->assertNull($snapshot->baseline_value);
    }

    public function test_rebuild_is_idempotent_updates_recent_values_and_preserves_history_outside_window(): void
    {
        $this->completedRun(Ga4OrganicConversionSyncService::DATASET, '2026-08-16', ['property_id' => self::GA4_PROPERTY]);
        $this->seedGa4Series('2026-08-16');
        $service = app(SeoAnalyticalSnapshotService::class);
        $service->build(1);
        $firstId = AnalyticalMetricSnapshot::query()->value('id');

        AnalyticalMetricSnapshot::query()->create($this->snapshotRow('ga4_organic_key_events', '2026-01-01', self::GA4_PROPERTY));
        SeoGa4OrganicDailyMetric::query()
            ->where('property_id', self::GA4_PROPERTY)
            ->where('country_scope', 'ESP')
            ->whereDate('data_date', '2026-08-16')
            ->update(['key_events' => '0.000052']);
        $service->build(1);

        $this->assertSame(2, AnalyticalMetricSnapshot::query()->count());
        $this->assertSame($firstId, AnalyticalMetricSnapshot::query()->whereDate('data_date', '2026-08-16')->value('id'));
        $this->assertSame('0.00005200', AnalyticalMetricSnapshot::query()->whereDate('data_date', '2026-08-16')->value('current_value'));
        $this->assertTrue(AnalyticalMetricSnapshot::query()->whereDate('data_date', '2026-01-01')->exists());
    }

    public function test_dashboard_reads_only_current_property_snapshots_is_range_independent_and_sends_no_http(): void
    {
        AnalyticalMetricSnapshot::query()->create($this->snapshotRow('search_console_clicks', '2026-08-15', 'old-property'));
        AnalyticalMetricSnapshot::query()->create($this->snapshotRow('search_console_clicks', '2026-08-16', self::SEARCH_PROPERTY));
        $ga4 = AnalyticalMetricSnapshot::query()->create($this->snapshotRow('ga4_organic_key_events', '2026-08-16', self::GA4_PROPERTY, '0.00002600', 'decimal'));
        $ga4->update([
            'reference_count' => 4,
            'baseline_value' => '0.00000000',
            'absolute_change' => '0.00002600',
            'relative_change' => null,
            'is_evaluable' => true,
            'evaluation_reason' => null,
        ]);
        Http::fake();

        $reader = app(SeoAnalyticalComparisonDatasetService::class);
        $rows = $reader->build();
        $this->assertCount(2, $rows);
        $this->assertSame('2026-08-16', $rows[0]['data_date']);

        $seven = app(SeoAnalyticsDatasetService::class)->build('7', 'summary')['analytical_comparisons'];
        $ninety = app(SeoAnalyticsDatasetService::class)->build('90', 'summary')['analytical_comparisons'];
        $this->assertSame($seven, $ninety);

        $this->get('/informes/seo-analytics?section=summary&range=7')
            ->assertOk()
            ->assertSee('Comparativa diaria')
            ->assertSee('Último día cerrado de cada fuente')
            ->assertSee('0,00')
            ->assertSee('Sin histórico suficiente')
            ->assertSee('La variación porcentual no se calcula cuando la referencia es cero.')
            ->assertDontSee('report-ui-status--ok')
            ->assertDontSee('report-ui-status--critical');
        Http::assertNothingSent();
    }

    public function test_integer_sources_stay_integer_while_baselines_and_absolute_changes_keep_fractional_precision(): void
    {
        $clicks = AnalyticalMetricSnapshot::query()->create(
            $this->snapshotRow('search_console_clicks', '2026-08-16', self::SEARCH_PROPERTY, '10.00000000')
        );
        $clicks->update([
            'reference_count' => 4,
            'baseline_value' => '9.50000000',
            'absolute_change' => '0.50000000',
            'd364_value' => '5.00000000',
            'is_evaluable' => true,
            'evaluation_reason' => null,
        ]);
        $leads = AnalyticalMetricSnapshot::query()->create(
            $this->snapshotRow(
                'salesforce_organic_interests',
                '2026-08-18',
                SeoAnalyticalMetricRegistry::SALESFORCE_SOURCE_IDENTIFIER,
                '9.00000000',
            )
        );
        $leads->update([
            'reference_count' => 3,
            'baseline_value' => '9.33333333',
            'absolute_change' => '-0.33333333',
            'd364_value' => '4.00000000',
            'is_evaluable' => true,
            'evaluation_reason' => null,
        ]);

        $rows = collect(app(SeoAnalyticalComparisonDatasetService::class)->build())->keyBy('metric_key');

        $this->assertSame('10', $rows['search_console_clicks']['current']);
        $this->assertSame('9,50', $rows['search_console_clicks']['baseline']);
        $this->assertSame('+0,50', $rows['search_console_clicks']['absolute_change']);
        $this->assertSame('5', $rows['search_console_clicks']['d364']);
        $this->assertSame('9', $rows['salesforce_organic_interests']['current']);
        $this->assertSame('9,33', $rows['salesforce_organic_interests']['baseline']);
        $this->assertSame('-0,33', $rows['salesforce_organic_interests']['absolute_change']);
        $this->assertSame('4', $rows['salesforce_organic_interests']['d364']);
    }

    public function test_command_validates_days_records_run_and_succeeds_without_sources(): void
    {
        $this->disableIntegrations();

        $this->artisan('seo:build-analytical-snapshots', ['--days' => 0])->assertFailed();
        $this->artisan('seo:build-analytical-snapshots', ['--days' => 91])->assertFailed();
        $this->artisan('seo:build-analytical-snapshots', ['--days' => 'text'])->assertFailed();
        $this->artisan('seo:build-analytical-snapshots')
            ->expectsOutputToContain('Sin fuentes completadas')
            ->assertSuccessful();

        $run = ReportSyncRun::query()->where('dataset', SeoAnalyticalSnapshotService::DATASET)->sole();
        $this->assertSame('completed', $run->status);
        $this->assertSame('local_database', $run->source);
        $this->assertSame(SameWeekdayComparisonEngine::VERSION, data_get($run->stats, 'engine_version'));
        $this->assertSame(30, data_get($run->stats, 'requested_days'));
        $this->assertSame(0, data_get($run->stats, 'snapshots_upserted'));
        $this->assertArrayNotHasKey('token', $run->stats);
    }

    public function test_comparison_contract_and_scheduler_are_fixed_and_not_environment_driven(): void
    {
        $this->assertSame(SameWeekdayComparisonEngine::REFERENCE_OFFSETS, config('seo_analytics.analytical_comparison.reference_offsets_days'));
        $this->assertSame(SameWeekdayComparisonEngine::MINIMUM_REFERENCE_SAMPLES, config('seo_analytics.analytical_comparison.minimum_reference_samples'));
        $this->assertSame(SameWeekdayComparisonEngine::YEAR_REFERENCE_OFFSET, config('seo_analytics.analytical_comparison.year_reference_offset_days'));
        $this->assertSame(30, config('seo_analytics.analytical_comparison.snapshot_refresh_days'));
        $this->assertSame(90, config('seo_analytics.analytical_comparison.max_snapshot_build_days'));
        $this->assertSame(SameWeekdayComparisonEngine::VERSION, config('seo_analytics.analytical_comparison.engine_version'));

        $scheduler = file_get_contents(base_path('routes/console.php'));
        $this->assertStringContainsString("Schedule::command('seo:build-analytical-snapshots')", $scheduler);
        $this->assertStringNotContainsString("Schedule::command('seo:build-analytical-snapshots --days=", $scheduler);
        $this->assertStringContainsString("dailyAt('06:15')", $scheduler);
        $this->assertStringContainsString("'seo-build-analytical-snapshots'", $scheduler);
        $this->assertStringContainsString('withoutOverlapping(120)', $scheduler);
        foreach (["dailyAt('05:15')", "dailyAt('05:30')", "dailyAt('05:45')", "dailyAt('06:00')"] as $existingTime) {
            $this->assertStringContainsString($existingTime, $scheduler);
        }
    }

    public function test_builder_default_consumes_snapshot_refresh_days_without_relaxing_maximum(): void
    {
        $this->disableIntegrations();

        config([
            'seo_analytics.analytical_comparison.snapshot_refresh_days' => 2,
            'seo_analytics.analytical_comparison.max_snapshot_build_days' => 120,
        ]);

        $this->artisan('seo:build-analytical-snapshots')
            ->expectsOutputToContain('Sin fuentes completadas')
            ->assertSuccessful();
        $run = ReportSyncRun::query()->where('dataset', SeoAnalyticalSnapshotService::DATASET)->sole();
        $this->assertSame(2, data_get($run->stats, 'requested_days'));

        $this->artisan('seo:build-analytical-snapshots', ['--days' => 91])->assertFailed();
        $this->assertSame(1, ReportSyncRun::query()->where('dataset', SeoAnalyticalSnapshotService::DATASET)->count());
    }

    public function test_command_failure_is_sanitized_and_recorded_without_partial_snapshots(): void
    {
        $this->completedRun(SalesforceOrganicInterestProjectionService::DATASET, '2026-08-18');
        $this->seedSalesforceSeries('2026-08-18');
        $migration = require database_path('migrations/2026_08_19_090000_create_analytical_metric_snapshots_table.php');
        $migration->down();

        try {
            $this->artisan('seo:build-analytical-snapshots', ['--days' => 1])->assertFailed();
            $run = ReportSyncRun::query()->where('dataset', SeoAnalyticalSnapshotService::DATASET)->sole();
            $this->assertSame('failed', $run->status);
            $this->assertNotNull($run->error_message);
            $this->assertLessThanOrEqual(2000, mb_strlen((string) $run->error_message));
        } finally {
            $migration->up();
        }

        $this->assertSame(0, AnalyticalMetricSnapshot::query()->count());
    }

    private function disableIntegrations(): void
    {
        config([
            'services.google_search_console.client_id' => null,
            'services.google_search_console.client_secret' => null,
            'services.google_search_console.refresh_token' => null,
            'services.google_search_console.property' => null,
            'services.google_analytics.client_id' => null,
            'services.google_analytics.client_secret' => null,
            'services.google_analytics.refresh_token' => null,
            'services.google_analytics.property_id' => null,
            'salesforce.client_id' => null,
            'salesforce.client_secret' => null,
        ]);
    }

    private function seedSearchSeries(string $target): void
    {
        foreach ([0 => 10, 7 => 8, 14 => 9, 21 => 10, 28 => 11, 364 => 5] as $offset => $clicks) {
            SeoSearchConsoleDailyMetric::query()->create($this->searchRow(
                self::SEARCH_PROPERTY,
                CarbonImmutable::parse($target)->subDays($offset)->toDateString(),
                'ESP',
                'all',
                true,
                $clicks,
            ));
        }
    }

    private function seedSalesforceSeries(string $target): void
    {
        foreach ([0 => 10, 7 => 8, 14 => 9, 21 => 10, 28 => 11, 364 => 5] as $offset => $leads) {
            SeoSalesforceOrganicInterestDailyMetric::query()->create($this->salesforceRow(
                CarbonImmutable::parse($target)->subDays($offset)->toDateString(),
                $leads,
            ));
        }
    }

    private function seedGa4Series(string $target): void
    {
        foreach ([0 => '0.000026', 7 => '0.000004', 14 => '0.000026', 21 => '0.000048', 28 => '0.000026', 364 => '0.000004'] as $offset => $value) {
            SeoGa4OrganicDailyMetric::query()->create($this->ga4Row(
                self::GA4_PROPERTY,
                CarbonImmutable::parse($target)->subDays($offset)->toDateString(),
                'ESP',
                $value,
            ));
        }
    }

    /** @return array<string, mixed> */
    private function searchRow(string $property, string $date, string $scope, string $segment, bool $final, int $clicks): array
    {
        return [
            'property' => $property, 'data_date' => $date, 'country_scope' => $scope, 'brand_segment' => $segment,
            'clicks' => $clicks, 'impressions' => $clicks * 10, 'ctr' => '0.10000000', 'position' => '2.5000',
            'source_timezone' => 'America/Los_Angeles', 'is_final' => $final, 'extracted_at' => now(),
        ];
    }

    /** @return array<string, mixed> */
    private function salesforceRow(string $date, int $leads): array
    {
        return [
            'data_date' => $date,
            'interest_count' => $leads,
            'source_timezone' => 'Europe/Madrid',
            'source_interest_sync_run_id' => 1,
            'source_interest_cutoff_at' => $date.' 23:59:59',
            'extracted_at' => now(),
        ];
    }

    /** @return array<string, mixed> */
    private function ga4Row(string $property, string $date, string $scope, string $value): array
    {
        return ['property_id' => $property, 'data_date' => $date, 'country_scope' => $scope, 'key_events' => $value, 'source_timezone' => 'Europe/Madrid', 'extracted_at' => now()];
    }

    /** @return array<string, mixed> */
    private function snapshotRow(string $metric, string $date, string $identifier, string $current = '10.00000000', string $format = 'integer'): array
    {
        $definition = collect(app(SeoAnalyticalMetricRegistry::class)->metrics())->firstWhere('key', $metric);

        return [
            'module_key' => 'seo', 'metric_key' => $metric, 'metric_label' => $definition['label'],
            'source_key' => $definition['source'], 'source_identifier' => $identifier,
            'source_identifier_hash' => hash('sha256', $identifier), 'scope_key' => $definition['scope'],
            'value_format' => $format, 'data_date' => $date, 'source_cutoff_at' => $date.' 00:00:00',
            'current_value' => $current, 'd7_value' => null, 'd14_value' => null, 'd21_value' => null, 'd28_value' => null,
            'reference_count' => 2, 'baseline_value' => null, 'absolute_change' => null, 'relative_change' => null,
            'd364_value' => null, 'year_absolute_change' => null, 'year_relative_change' => null,
            'is_evaluable' => false, 'evaluation_reason' => 'insufficient_history',
            'engine_version' => SameWeekdayComparisonEngine::VERSION, 'computed_at' => now(),
        ];
    }

    /** @param array<string, mixed> $stats */
    private function completedRun(string $dataset, string $cutoff, array $stats = [], ?string $startedAt = null): void
    {
        ReportSyncRun::query()->create([
            'dataset' => $dataset, 'source' => 'test', 'status' => 'completed',
            'period_start_at' => $cutoff.' 00:00:00', 'period_end_at' => $cutoff.' 00:00:00',
            'source_cutoff_at' => $cutoff.' 00:00:00', 'started_at' => $startedAt ?? $cutoff.' 06:00:00',
            'completed_at' => $startedAt ?? $cutoff.' 06:01:00', 'timezone' => 'Europe/Madrid', 'stats' => $stats,
        ]);
    }
}
