<?php

namespace Tests\Feature;

use App\Models\ReportSyncRun;
use App\Models\SeoSalesforceOrganicInterestDailyMetric;
use App\Models\SeoSearchConsoleDailyMetric;
use App\Models\SeoSearchConsoleDimensionMetric;
use App\Services\Salesforce\SalesforceInterestSyncService;
use App\Services\SeoAnalytics\SalesforceOrganicInterestProjectionService;
use App\Services\SeoAnalytics\SearchConsoleSyncService;
use App\Services\SeoAnalytics\SeoAnalyticsDatasetService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeoAnalyticsDashboardDataTest extends TestCase
{
    use RefreshDatabase;

    private const PROPERTY = 'sc-domain:example.test';

    public function test_complete_range_uses_common_cutoff_and_search_rankings_use_search_console_period(): void
    {
        $this->configureProperty();
        $this->seedSearchPeriod('2026-08-09', '2026-08-16');
        $this->seedSalesforcePeriod('2026-08-09', '2026-08-15');
        $this->completedRun(SearchConsoleSyncService::DATASET, '2026-08-16', ['property' => self::PROPERTY]);
        $this->completedRun(SalesforceOrganicInterestProjectionService::DATASET, '2026-08-15');
        $this->seedQueryRanking('2026-08-10', '2026-08-16', '<script>alert(1)</script>');
        Http::fake();

        $dataset = app(SeoAnalyticsDatasetService::class)->build('7', 'summary');
        $this->assertSame(['start' => '2026-08-09', 'end' => '2026-08-15'], $dataset['common_period']);
        $this->assertSame(['start' => '2026-08-10', 'end' => '2026-08-16'], $dataset['search_console_period']);
        $this->assertTrue($dataset['kpis']['spain']['available']);
        $this->assertSame(70, $dataset['kpis']['spain']['clicks']);
        $this->assertSame(6, $dataset['kpis']['salesforce_interests']);
        $this->assertSame(6, $dataset['kpis']['salesforce_leads']);
        $this->assertSame($dataset['daily'][0]['interests'], $dataset['daily'][0]['leads']);
        $this->assertLessThan(
            $dataset['kpis']['spain']['clicks'],
            $dataset['segments']['brand']['clicks'] + $dataset['segments']['non_brand']['clicks'],
        );

        $this->get('/informes/seo-analytics?range=7&section=summary')
            ->assertOk()
            ->assertSee('Periodo común cerrado: 2026-08-09 — 2026-08-15')
            ->assertSee('La segmentación Marca/No marca puede no sumar el total de España porque Search Console excluye consultas anonimizadas al aplicar filtros de búsqueda.');

        $this->get('/informes/seo-analytics?range=7&section=search')
            ->assertOk()
            ->assertSee('Periodo Search Console: 2026-08-10 — 2026-08-16')
            ->assertDontSee('Periodo común cerrado: 2026-08-09 — 2026-08-15')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
        Http::assertNothingSent();
    }

    public function test_partial_or_gapped_ranges_are_unavailable_but_complete_zero_days_remain_real_zero(): void
    {
        $this->configureProperty();
        $this->completedRun(SearchConsoleSyncService::DATASET, '2026-08-16', ['property' => self::PROPERTY]);
        $this->completedRun(SalesforceOrganicInterestProjectionService::DATASET, '2026-08-16');
        $this->seedSearchPeriod('2026-08-15', '2026-08-16');
        $this->seedSalesforcePeriod('2026-08-15', '2026-08-16');

        $partial = app(SeoAnalyticsDatasetService::class)->build('7', 'summary');
        $this->assertFalse($partial['kpis']['spain']['available']);
        $this->assertFalse($partial['segments']['brand']['available']);
        $this->assertNull($partial['kpis']['salesforce_leads']);
        $this->assertFalse($partial['has_search_console']);
        $this->assertFalse($partial['has_salesforce']);

        SeoSearchConsoleDailyMetric::query()->delete();
        SeoSalesforceOrganicInterestDailyMetric::query()->delete();
        $this->seedSearchPeriod('2026-08-10', '2026-08-16', ['2026-08-13']);
        $this->seedSalesforcePeriod('2026-08-10', '2026-08-16', ['2026-08-13']);
        $gapped = app(SeoAnalyticsDatasetService::class)->build('7', 'summary');
        $this->assertFalse($gapped['kpis']['spain']['available']);
        $this->assertNull($gapped['kpis']['salesforce_leads']);
        $this->assertFalse($gapped['has_search_console']);
        $this->assertFalse($gapped['has_salesforce']);

        SeoSearchConsoleDailyMetric::query()->delete();
        SeoSalesforceOrganicInterestDailyMetric::query()->delete();
        $this->seedSearchPeriod('2026-08-10', '2026-08-16', [], true);
        $this->seedSalesforcePeriod('2026-08-10', '2026-08-16', [], true);
        $completeZeros = app(SeoAnalyticsDatasetService::class)->build('7', 'summary');
        $this->assertTrue($completeZeros['kpis']['spain']['available']);
        $this->assertSame(0, $completeZeros['kpis']['spain']['clicks']);
        $this->assertNull($completeZeros['kpis']['spain']['ctr']);
        $this->assertSame(0, $completeZeros['kpis']['salesforce_leads']);
        $this->assertTrue($completeZeros['has_search_console']);
        $this->assertTrue($completeZeros['has_salesforce']);
    }

    public function test_each_source_remains_available_independently_only_with_complete_coverage(): void
    {
        $this->configureProperty();
        $this->seedSearchPeriod('2026-08-10', '2026-08-16');
        $this->completedRun(SearchConsoleSyncService::DATASET, '2026-08-16', ['property' => self::PROPERTY]);

        $searchOnly = app(SeoAnalyticsDatasetService::class)->build('7', 'summary');
        $this->assertTrue($searchOnly['kpis']['spain']['available']);
        $this->assertNull($searchOnly['kpis']['salesforce_leads']);

        SeoSearchConsoleDailyMetric::query()->delete();
        ReportSyncRun::query()->delete();
        config(['services.google_search_console.property' => null]);
        $this->seedSalesforcePeriod('2026-08-10', '2026-08-16');
        $this->completedRun(SalesforceOrganicInterestProjectionService::DATASET, '2026-08-16');

        $salesforceOnly = app(SeoAnalyticsDatasetService::class)->build('7', 'summary');
        $this->assertFalse($salesforceOnly['kpis']['spain']['available']);
        $this->assertSame(6, $salesforceOnly['kpis']['salesforce_leads']);
    }

    public function test_cutoff_comes_from_latest_completed_matching_property_despite_later_failed_or_running_runs(): void
    {
        $this->configureProperty();
        $this->seedSearchPeriod('2026-08-09', '2026-08-15');
        $this->completedRun(SearchConsoleSyncService::DATASET, '2026-08-15', ['property' => self::PROPERTY], '2026-08-15 06:00:00');
        $this->createSyncRun(SearchConsoleSyncService::DATASET, 'failed', '2026-08-16 06:00:00', ['property' => self::PROPERTY]);

        $failed = app(SeoAnalyticsDatasetService::class)->build('7', 'summary');
        $this->assertSame('2026-08-15', $failed['cutoffs']['search_console']);
        $this->assertSame('Error último sync', $failed['sources'][0]['badge']);
        $this->assertStringContainsString('Datos anteriores cerrados hasta: 2026-08-15', $failed['sources'][0]['detail']);

        $this->createSyncRun(SearchConsoleSyncService::DATASET, 'running', '2026-08-17 06:00:00', ['property' => self::PROPERTY]);
        $running = app(SeoAnalyticsDatasetService::class)->build('7', 'summary');
        $this->assertSame('2026-08-15', $running['cutoffs']['search_console']);
        $this->assertSame('Sincronizando', $running['sources'][0]['badge']);

        config(['services.google_search_console.property' => 'sc-domain:other.test']);
        $otherProperty = app(SeoAnalyticsDatasetService::class)->build('7', 'summary');
        $this->assertNull($otherProperty['cutoffs']['search_console']);
        $this->assertFalse($otherProperty['has_search_console']);
    }

    public function test_failed_run_for_another_property_does_not_change_configured_property_status(): void
    {
        $this->configureProperty();
        $this->completedRun(SearchConsoleSyncService::DATASET, '2026-08-15', ['property' => self::PROPERTY], '2026-08-15 06:00:00');
        $this->createSyncRun(SearchConsoleSyncService::DATASET, 'failed', '2026-08-16 06:00:00', ['property' => 'sc-domain:other.test']);

        $dataset = app(SeoAnalyticsDatasetService::class)->build('7', 'summary');

        $this->assertSame('2026-08-15', $dataset['cutoffs']['search_console']);
        $this->assertSame('Sincronizada', $dataset['sources'][0]['badge']);
        $this->assertStringNotContainsString('falló', $dataset['sources'][0]['detail']);
    }

    public function test_salesforce_source_status_preserves_completed_projection_cutoff_when_latest_projection_fails(): void
    {
        ReportSyncRun::query()->create([
            'dataset' => SalesforceInterestSyncService::DATASET,
            'source' => SalesforceInterestSyncService::SOURCE,
            'status' => 'completed',
            'period_start_at' => '2026-08-01 00:00:00',
            'period_end_at' => '2026-08-17 10:00:00',
            'source_cutoff_at' => '2026-08-17 10:00:00',
            'started_at' => '2026-08-17 09:00:00',
            'completed_at' => '2026-08-17 10:01:00',
            'timezone' => 'UTC',
        ]);
        $this->completedRun(SalesforceOrganicInterestProjectionService::DATASET, '2026-08-15', [], '2026-08-16 05:30:00');
        $this->createSyncRun(SalesforceOrganicInterestProjectionService::DATASET, 'failed', '2026-08-17 05:30:00');

        $dataset = app(SeoAnalyticsDatasetService::class)->build('7', 'summary');
        $source = collect($dataset['sources'])->firstWhere('key', 'salesforce');

        $this->assertSame('2026-08-15', $dataset['cutoffs']['salesforce']);
        $this->assertSame('Error último sync', $source['badge']);
        $this->assertStringContainsString('Datos anteriores cerrados hasta: 2026-08-15', $source['detail']);
    }

    public function test_range_whitelist_is_exact_text_and_invalid_values_fall_back_to_28(): void
    {
        foreach (['7', '28', '90'] as $valid) {
            $this->get('/informes/seo-analytics?range='.$valid)
                ->assertOk()
                ->assertSee('value="'.$valid.'" selected', false);
        }

        foreach (['999999', '7foo', '07', 'arbitrary'] as $invalid) {
            $this->get('/informes/seo-analytics?range='.$invalid)
                ->assertOk()
                ->assertSee('value="28" selected', false);
        }
    }

    public function test_scrollable_seo_tables_have_accessible_names_and_scoped_column_headers(): void
    {
        $view = file_get_contents(resource_path('views/reports/seo-analytics/index.blade.php'));
        $this->assertIsString($view);

        preg_match_all('/<div\b[^>]*class="[^"]*report-ui-data-panel__scroll[^"]*"[^>]*>/', $view, $scrollContainers);
        $this->assertNotEmpty($scrollContainers[0]);
        foreach ($scrollContainers[0] as $container) {
            $this->assertStringContainsString('tabindex="0"', $container);
            $this->assertMatchesRegularExpression('/aria-(?:label|labelledby)="[^"]+"/', $container);
        }

        preg_match_all('/<th\b[^>]*>/', $view, $headers);
        $this->assertNotEmpty($headers[0]);
        foreach ($headers[0] as $header) {
            $this->assertStringContainsString('scope="col"', $header);
        }
    }

    /** @param array<int, string> $missingDates */
    private function seedSearchPeriod(string $start, string $end, array $missingDates = [], bool $allZero = false): void
    {
        for ($date = CarbonImmutable::parse($start); $date->lessThanOrEqualTo(CarbonImmutable::parse($end)); $date = $date->addDay()) {
            if (in_array($date->toDateString(), $missingDates, true)) {
                continue;
            }

            foreach ([
                ['ALL', 'all', 20, 200],
                ['ESP', 'all', 10, 100],
                ['ESP', 'brand', 2, 20],
                ['ESP', 'non_brand', 3, 30],
            ] as [$scope, $segment, $clicks, $impressions]) {
                $clicks = $allZero ? 0 : $clicks;
                $impressions = $allZero ? 0 : $impressions;
                SeoSearchConsoleDailyMetric::query()->create([
                    'property' => self::PROPERTY,
                    'data_date' => $date,
                    'country_scope' => $scope,
                    'brand_segment' => $segment,
                    'clicks' => $clicks,
                    'impressions' => $impressions,
                    'ctr' => $impressions > 0 ? $clicks / $impressions : null,
                    'position' => $impressions > 0 ? 5 : null,
                    'source_timezone' => 'America/Los_Angeles',
                    'is_final' => true,
                    'extracted_at' => now(),
                ]);
            }
        }
    }

    /** @param array<int, string> $missingDates */
    private function seedSalesforcePeriod(string $start, string $end, array $missingDates = [], bool $allZero = false): void
    {
        for ($date = CarbonImmutable::parse($start); $date->lessThanOrEqualTo(CarbonImmutable::parse($end)); $date = $date->addDay()) {
            if (in_array($date->toDateString(), $missingDates, true)) {
                continue;
            }

            SeoSalesforceOrganicInterestDailyMetric::query()->create([
                'data_date' => $date,
                'interest_count' => $allZero || $date->equalTo(CarbonImmutable::parse($start)) ? 0 : 1,
                'source_interest_sync_run_id' => 1,
                'source_interest_cutoff_at' => $end.' 23:59:59',
                'source_timezone' => 'Europe/Madrid',
                'extracted_at' => now(),
            ]);
        }
    }

    private function seedQueryRanking(string $start, string $end, string $value): void
    {
        SeoSearchConsoleDimensionMetric::query()->create([
            'property' => self::PROPERTY,
            'period_days' => 7,
            'period_start' => $start,
            'period_end' => $end,
            'dimension_type' => 'query',
            'country_scope' => 'ESP',
            'rank' => 1,
            'dimension_value' => $value,
            'dimension_hash' => hash('sha256', $value),
            'brand_segment' => 'non_brand',
            'clicks' => 1,
            'impressions' => 10,
            'ctr' => .1,
            'position' => 2,
            'source_timezone' => 'America/Los_Angeles',
            'extracted_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $stats */
    private function completedRun(string $dataset, string $cutoff, array $stats = [], ?string $startedAt = null): void
    {
        $this->createSyncRun($dataset, 'completed', $startedAt ?? $cutoff.' 06:00:00', $stats, $cutoff);
    }

    /** @param array<string, mixed> $stats */
    private function createSyncRun(string $dataset, string $status, string $startedAt, array $stats = [], ?string $cutoff = null): void
    {
        ReportSyncRun::query()->create([
            'dataset' => $dataset,
            'source' => match ($dataset) {
                SearchConsoleSyncService::DATASET => 'google_search_console',
                SalesforceOrganicInterestProjectionService::DATASET => SalesforceOrganicInterestProjectionService::SOURCE,
                default => 'salesforce',
            },
            'status' => $status,
            'period_start_at' => $startedAt,
            'period_end_at' => $startedAt,
            'source_cutoff_at' => $cutoff,
            'started_at' => $startedAt,
            'completed_at' => $status === 'running' ? null : $startedAt,
            'timezone' => 'Europe/Madrid',
            'stats' => $stats,
        ]);
    }

    private function configureProperty(): void
    {
        config(['services.google_search_console.property' => self::PROPERTY]);
    }
}
