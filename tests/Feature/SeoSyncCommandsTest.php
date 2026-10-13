<?php

namespace Tests\Feature;

use App\Models\ReportSyncRun;
use App\Services\Salesforce\SalesforceInterestSyncService;
use App\Services\SeoAnalytics\SalesforceOrganicInterestProjectionService;
use App\Services\SeoAnalytics\SearchConsoleSyncService;
use App\Services\SeoAnalytics\SeoAnalyticsDatasetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeoSyncCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_unconfigured_commands_skip_without_http_and_invalid_days_fail(): void
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
        Http::fake();
        ReportSyncRun::query()->create([
            'dataset' => SalesforceInterestSyncService::DATASET,
            'source' => SalesforceInterestSyncService::SOURCE,
            'status' => 'completed',
            'period_start_at' => now()->subDay(),
            'period_end_at' => now(),
            'source_cutoff_at' => now(),
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
            'timezone' => 'UTC',
        ]);

        $this->artisan('seo:sync-search-console')->expectsOutputToContain('SKIPPED')->assertSuccessful();
        $this->artisan('seo:sync-salesforce-organic')->expectsOutputToContain('Intereses organicos')->assertSuccessful();
        $this->artisan('seo:sync-ga4-organic')->expectsOutputToContain('SKIPPED')->assertSuccessful();
        $this->artisan('seo:sync-search-console', ['--days' => 0])->assertFailed();
        $this->artisan('seo:sync-salesforce-organic', ['--days' => 481])->assertFailed();
        $this->artisan('seo:sync-ga4-organic', ['--days' => 0])->assertFailed();
        $this->artisan('seo:sync-ga4-organic', ['--days' => 481])->assertFailed();
        $this->artisan('seo:sync-ga4-organic', ['--days' => 'invalid'])->assertFailed();
        Http::assertNothingSent();
        $this->assertDatabaseHas('report_sync_runs', [
            'dataset' => SalesforceOrganicInterestProjectionService::DATASET,
            'source' => SalesforceOrganicInterestProjectionService::SOURCE,
            'status' => 'completed',
        ]);
    }

    public function test_scheduler_declares_independent_windows_and_locks(): void
    {
        $scheduler = file_get_contents(base_path('routes/console.php'));

        $this->assertStringContainsString('seo:sync-search-console --days=120', $scheduler);
        $this->assertStringContainsString("dailyAt('05:15')", $scheduler);
        $this->assertStringContainsString('seo:sync-salesforce-organic --days=120', $scheduler);
        $this->assertStringContainsString("dailyAt('05:30')", $scheduler);
        $this->assertStringContainsString('seo:sync-ga4-organic --days=120', $scheduler);
        $this->assertStringContainsString("dailyAt('05:45')", $scheduler);
        $this->assertLessThan(strpos($scheduler, "dailyAt('05:45')"), strpos($scheduler, "dailyAt('05:30')"));
        $this->assertLessThan(strpos($scheduler, "dailyAt('06:30')"), strpos($scheduler, "dailyAt('06:15')"));
        $this->assertSame(1, substr_count($scheduler, "Schedule::command('salesforce:sync-interest-reporting')"));
        $this->assertStringContainsString('->hourlyAt(5)', $scheduler);
        $this->assertLessThan(
            strpos($scheduler, "Schedule::command('seo:sync-salesforce-organic --days=120')"),
            strpos($scheduler, "Schedule::command('salesforce:sync-interest-reporting')"),
        );
        $this->assertGreaterThanOrEqual(3, substr_count($scheduler, 'withoutOverlapping(120)'));
    }

    public function test_failed_search_console_command_keeps_property_metadata_and_previous_completed_cutoff(): void
    {
        $property = 'sc-domain:example.test';
        config([
            'services.google_search_console.client_id' => 'synthetic-client',
            'services.google_search_console.client_secret' => 'synthetic-secret',
            'services.google_search_console.refresh_token' => 'synthetic-refresh',
            'services.google_search_console.property' => $property,
        ]);
        ReportSyncRun::query()->create([
            'dataset' => SearchConsoleSyncService::DATASET,
            'source' => 'google_search_console',
            'status' => 'completed',
            'period_start_at' => '2026-08-09 00:00:00',
            'period_end_at' => '2026-08-15 00:00:00',
            'source_cutoff_at' => '2026-08-15 00:00:00',
            'started_at' => '2026-08-15 05:15:00',
            'completed_at' => '2026-08-15 05:16:00',
            'timezone' => 'America/Los_Angeles',
            'stats' => ['property' => $property],
        ]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'synthetic-token']),
            '*' => Http::response(['error' => ['message' => 'synthetic failure']], 503),
        ]);

        $this->artisan('seo:sync-search-console', ['--days' => 7])->assertFailed();

        $failed = ReportSyncRun::query()
            ->where('dataset', SearchConsoleSyncService::DATASET)
            ->where('status', 'failed')
            ->latest('started_at')
            ->firstOrFail();
        $this->assertSame($property, data_get($failed->stats, 'property'));
        $this->assertSame(['property' => $property], $failed->stats);

        $dataset = app(SeoAnalyticsDatasetService::class)->build('7', 'summary');
        $this->assertSame('2026-08-15', $dataset['cutoffs']['search_console']);
        $this->assertSame('Error último sync', $dataset['sources'][0]['badge']);
        $this->assertStringContainsString('Datos anteriores cerrados hasta: 2026-08-15', $dataset['sources'][0]['detail']);
    }

    public function test_failed_ga4_command_keeps_non_secret_property_metadata(): void
    {
        config([
            'services.google_analytics.client_id' => 'synthetic-client',
            'services.google_analytics.client_secret' => 'synthetic-secret',
            'services.google_analytics.refresh_token' => 'synthetic-refresh',
            'services.google_analytics.property_id' => '123',
        ]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'synthetic-token']),
            '*' => Http::response(['error' => ['message' => 'synthetic failure']], 503),
        ]);

        $this->artisan('seo:sync-ga4-organic', ['--days' => 7])->assertFailed();

        $failed = ReportSyncRun::query()
            ->where('dataset', 'seo_ga4_organic_conversions')
            ->where('status', 'failed')
            ->firstOrFail();
        $this->assertSame(['property_id' => '123'], $failed->stats);
        $this->assertStringNotContainsString('synthetic-secret', (string) $failed->error_message);
        $this->assertStringNotContainsString('synthetic-refresh', (string) $failed->error_message);
    }
}
