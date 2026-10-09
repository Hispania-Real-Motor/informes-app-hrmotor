<?php

namespace Tests\Feature;

use App\Models\ReportSyncRun;
use App\Models\SeoGa4OrganicDailyMetric;
use App\Models\SeoGa4OrganicKeyEventDailyMetric;
use App\Services\SeoAnalytics\Ga4OrganicConversionSyncService;
use App\Services\SeoAnalytics\SalesforceOrganicInterestProjectionService;
use App\Services\SeoAnalytics\SearchConsoleSyncService;
use App\Services\SeoAnalytics\SeoAnalyticsDatasetService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeoGa4DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_ga4_is_separate_fractional_kpi_daily_series_and_escaped_event_breakdown_without_http(): void
    {
        $this->configureGa4();
        $this->completedRun(Ga4OrganicConversionSyncService::DATASET, '2026-08-16', ['property_id' => '123']);
        $this->seedGa4Period('2026-08-10', '2026-08-16', '0.250000');
        SeoGa4OrganicKeyEventDailyMetric::query()->create([
            'property_id' => '123', 'data_date' => '2026-08-16', 'country_scope' => 'ESP',
            'event_name' => '<script>alert(1)</script>',
            'event_hash' => hash('sha256', '<script>alert(1)</script>'),
            'key_events' => '1.750000', 'source_timezone' => 'Europe/Madrid', 'extracted_at' => now(),
        ]);
        $this->assertSame(1, SeoGa4OrganicKeyEventDailyMetric::query()->count());
        Http::fake();

        $dataset = app(SeoAnalyticsDatasetService::class)->build('7', 'summary');
        $this->assertSame(1, SeoGa4OrganicKeyEventDailyMetric::query()
            ->where('property_id', '123')
            ->where('data_date', '>=', '2026-08-10')
            ->where('data_date', '<', '2026-08-17')
            ->count());
        $this->assertCount(1, $dataset['ga4']['events']);
        $this->assertSame(['start' => '2026-08-10', 'end' => '2026-08-16'], $dataset['common_period']);
        $this->assertTrue($dataset['has_ga4']);
        $this->assertSame(1.75, $dataset['kpis']['ga4_key_events']);
        $this->assertNull($dataset['kpis']['salesforce_interests']);
        $this->assertSame(.25, $dataset['daily'][0]['ga4_key_events']);

        $this->get('/informes/seo-analytics?range=7&section=summary')
            ->assertOk()
            ->assertSee('Interés orgánico (Salesforce)')
            ->assertSee('Conversiones web orgánicas (GA4)')
            ->assertDontSee('Total Leads')
            ->assertDontSee('Leads totales Salesforce + GA4');
        $this->get('/informes/seo-analytics?range=7&section=traffic')
            ->assertOk()
            ->assertSee('Conversiones web orgánicas GA4')
            ->assertSee('1,75')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
        Http::assertNothingSent();
    }

    public function test_ga4_requires_complete_coverage_but_complete_zero_rows_are_available(): void
    {
        $this->configureGa4();
        $this->completedRun(Ga4OrganicConversionSyncService::DATASET, '2026-08-16', ['property_id' => '123']);
        $this->seedGa4Period('2026-08-15', '2026-08-16', '0.250000');

        $partial = app(SeoAnalyticsDatasetService::class)->build('7', 'summary');
        $this->assertFalse($partial['has_ga4']);
        $this->assertNull($partial['kpis']['ga4_key_events']);

        SeoGa4OrganicDailyMetric::query()->delete();
        $this->seedGa4Period('2026-08-10', '2026-08-16', '0.000000');
        $zero = app(SeoAnalyticsDatasetService::class)->build('7', 'summary');
        $this->assertTrue($zero['has_ga4']);
        $this->assertSame(0.0, $zero['kpis']['ga4_key_events']);
    }

    public function test_common_cutoff_includes_ga4_without_changing_search_period_or_blocking_other_sources(): void
    {
        config([
            'services.google_search_console.property' => 'sc-domain:example.test',
            'services.google_analytics.property_id' => '123',
        ]);
        $this->completedRun(SearchConsoleSyncService::DATASET, '2026-08-14', ['property' => 'sc-domain:example.test']);
        $this->completedRun(SalesforceOrganicInterestProjectionService::DATASET, '2026-08-16');
        $this->completedRun(Ga4OrganicConversionSyncService::DATASET, '2026-08-13', ['property_id' => '123']);

        $withGa4 = app(SeoAnalyticsDatasetService::class)->build('7', 'summary');
        $this->assertSame('2026-08-13', $withGa4['common_period']['end']);
        $this->assertSame('2026-08-14', $withGa4['search_console_period']['end']);

        config(['services.google_analytics.property_id' => null]);
        $withoutGa4 = app(SeoAnalyticsDatasetService::class)->build('7', 'summary');
        $this->assertSame('2026-08-14', $withoutGa4['common_period']['end']);
    }

    public function test_ga4_completed_run_isolated_by_property_id_and_negative_rest_is_unavailable(): void
    {
        $this->configureGa4();
        $this->completedRun(Ga4OrganicConversionSyncService::DATASET, '2026-08-16', ['property_id' => 'other']);
        $other = app(SeoAnalyticsDatasetService::class)->build('7', 'summary');
        $this->assertNull($other['cutoffs']['ga4']);

        ReportSyncRun::query()->delete();
        $this->completedRun(Ga4OrganicConversionSyncService::DATASET, '2026-08-16', ['property_id' => '123']);
        $this->seedGa4Period('2026-08-10', '2026-08-16', '1.000000', '0.500000');
        $dataset = app(SeoAnalyticsDatasetService::class)->build('7', 'traffic');
        $this->assertFalse($dataset['ga4']['rest']['available']);
        $this->assertNull($dataset['ga4']['rest']['key_events']);
    }

    public function test_ga4_source_status_keeps_completed_cutoff_across_failed_and_running_runs(): void
    {
        $this->configureGa4();
        $this->completedRun(Ga4OrganicConversionSyncService::DATASET, '2026-08-15', ['property_id' => '123']);
        $this->createRun(Ga4OrganicConversionSyncService::DATASET, 'failed', '2026-08-16 05:45:00', ['property_id' => '123']);

        $failed = app(SeoAnalyticsDatasetService::class)->build('7', 'summary');
        $this->assertSame('2026-08-15', $failed['cutoffs']['ga4']);
        $this->assertSame('Error último sync', $failed['sources'][2]['badge']);

        $this->createRun(Ga4OrganicConversionSyncService::DATASET, 'running', '2026-08-17 05:45:00', ['property_id' => '123']);
        $running = app(SeoAnalyticsDatasetService::class)->build('7', 'summary');
        $this->assertSame('2026-08-15', $running['cutoffs']['ga4']);
        $this->assertSame('Sincronizando', $running['sources'][2]['badge']);
    }

    private function seedGa4Period(string $start, string $end, string $spain, ?string $global = null): void
    {
        for ($date = CarbonImmutable::parse($start); $date->lessThanOrEqualTo(CarbonImmutable::parse($end)); $date = $date->addDay()) {
            foreach (['ESP' => $spain, 'ALL' => $global ?? $spain] as $scope => $value) {
                SeoGa4OrganicDailyMetric::query()->create([
                    'property_id' => '123', 'data_date' => $date, 'country_scope' => $scope,
                    'key_events' => $value, 'source_timezone' => 'Europe/Madrid', 'extracted_at' => now(),
                ]);
            }
        }
    }

    /** @param array<string, mixed> $stats */
    private function completedRun(string $dataset, string $cutoff, array $stats = []): void
    {
        $this->createRun($dataset, 'completed', $cutoff.' 05:00:00', $stats, $cutoff);
    }

    /** @param array<string, mixed> $stats */
    private function createRun(string $dataset, string $status, string $startedAt, array $stats = [], ?string $cutoff = null): void
    {
        ReportSyncRun::query()->create([
            'dataset' => $dataset,
            'source' => $dataset === SalesforceOrganicInterestProjectionService::DATASET ? 'local_database' : 'google',
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

    private function configureGa4(): void
    {
        config([
            'services.google_analytics.client_id' => 'synthetic-client',
            'services.google_analytics.client_secret' => 'synthetic-secret',
            'services.google_analytics.refresh_token' => 'synthetic-refresh',
            'services.google_analytics.property_id' => '123',
        ]);
    }
}
