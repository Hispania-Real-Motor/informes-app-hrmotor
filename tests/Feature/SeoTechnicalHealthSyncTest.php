<?php

namespace Tests\Feature;

use App\Models\ReportSyncRun;
use App\Models\SeoTechnicalUrl;
use App\Models\SeoTechnicalUrlCheck;
use App\Services\SeoAnalytics\SeoTechnicalDnsResolver;
use App\Services\SeoAnalytics\SeoTechnicalHealthSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Tests\Concerns\AssertsOperationalSchedule;
use Tests\TestCase;

class SeoTechnicalHealthSyncTest extends TestCase
{
    use AssertsOperationalSchedule;
    use RefreshDatabase;

    public function test_registry_daily_checks_active_flags_and_findings_are_idempotent(): void
    {
        CarbonImmutable::setTestNow('2026-08-18 07:00:00 Europe/Madrid');
        $this->configureSite(['strategic_urls' => ['https://example.test/old']]);
        $this->bindPublicDns();
        $this->fakeWebsite();

        $first = app(SeoTechnicalHealthSyncService::class)->sync();
        $this->assertSame(2, $first['stats']['checked_urls']);
        $this->assertSame(1, $first['stats']['http_2xx']);
        $this->assertSame(1, $first['stats']['http_4xx']);
        $this->assertSame(2, SeoTechnicalUrl::query()->count());
        $this->assertSame(2, SeoTechnicalUrlCheck::query()->count());
        $this->assertNull(SeoTechnicalUrl::query()->where('url', 'https://example.test/old')->value('in_sitemap'));

        app(SeoTechnicalHealthSyncService::class)->sync();
        $this->assertSame(2, SeoTechnicalUrlCheck::query()->count());

        config(['seo_analytics.technical_health.strategic_urls' => []]);
        app(SeoTechnicalHealthSyncService::class)->sync();
        $this->assertFalse((bool) SeoTechnicalUrl::query()->where('url', 'https://example.test/old')->value('is_active'));
        $this->assertSame(2, SeoTechnicalUrlCheck::query()->count());
        $this->assertDatabaseHas('seo_technical_url_checks', ['http_status' => 404]);
    }

    public function test_network_error_and_page_500_are_persisted_without_aborting_other_urls(): void
    {
        $this->configureSite(['strategic_urls' => ['https://example.test/error', 'https://example.test/network']]);
        $this->bindPublicDns();
        Http::fake(function (Request $request) {
            return match ($request->url()) {
                'https://example.test/robots.txt' => Http::response('', 404),
                'https://example.test/error' => Http::response('failure', 500, ['Content-Type' => 'text/plain']),
                'https://example.test/network' => Http::failedConnection('synthetic timeout'),
                default => Http::response('<title>Home</title>', 200, ['Content-Type' => 'text/html']),
            };
        });

        $result = app(SeoTechnicalHealthSyncService::class)->sync();

        $this->assertSame(3, $result['stats']['checked_urls']);
        $this->assertSame(1, $result['stats']['http_5xx']);
        $this->assertSame(1, $result['stats']['network_errors']);
        $this->assertDatabaseHas('seo_technical_url_checks', ['http_status' => 500]);
        $this->assertDatabaseHas('seo_technical_url_checks', ['error_code' => 'network_error']);
    }

    public function test_body_read_error_becomes_one_finding_and_other_urls_continue(): void
    {
        $this->configureSite(['strategic_urls' => ['https://example.test/broken', 'https://example.test/good']]);
        $this->bindPublicDns();
        $stream = Mockery::mock(StreamInterface::class);
        $stream->shouldReceive('isSeekable')->once()->andReturnFalse();
        $stream->shouldReceive('eof')->once()->andReturnFalse();
        $stream->shouldReceive('read')->once()->andThrow(new RuntimeException('synthetic stream timeout'));
        $stream->shouldReceive('close')->once();
        Http::fake(function (Request $request) use ($stream) {
            return match ($request->url()) {
                'https://example.test/robots.txt' => Http::response('', 404),
                'https://example.test/broken' => Http::response($stream, 200, ['Content-Type' => 'text/html']),
                default => Http::response('<meta name="robots" content="index">', 200, ['Content-Type' => 'text/html']),
            };
        });

        $result = app(SeoTechnicalHealthSyncService::class)->sync();

        $this->assertSame(3, $result['stats']['checked_urls']);
        $this->assertSame(1, $result['stats']['network_errors']);
        $this->assertDatabaseHas('seo_technical_url_checks', ['error_code' => 'body_read_error', 'body_truncated' => true]);
        $this->assertDatabaseHas('seo_technical_url_checks', ['http_status' => 200, 'error_code' => null]);
    }

    public function test_command_skip_failure_metadata_and_scheduler_contract(): void
    {
        config(['seo_analytics.technical_health.site_url' => null]);
        Http::fake();
        $this->artisan('seo:sync-technical-health')->expectsOutputToContain('SKIPPED')->assertSuccessful();
        Http::assertNothingSent();

        $this->configureSite([
            'strategic_urls' => ['https://example.test/a', 'https://example.test/b'],
            'max_urls' => 2,
        ]);
        $this->artisan('seo:sync-technical-health')->assertFailed();
        $run = ReportSyncRun::query()->where('dataset', SeoTechnicalHealthSyncService::DATASET)->firstOrFail();
        $this->assertSame('failed', $run->status);
        $this->assertSame(['site_host' => 'example.test'], $run->stats);

        $this->assertOperationalSchedule();
    }

    public function test_successful_command_completes_report_run_with_aggregated_non_secret_stats(): void
    {
        $this->configureSite();
        $this->bindPublicDns();
        $this->fakeWebsite();

        $this->artisan('seo:sync-technical-health')->assertSuccessful();

        $run = ReportSyncRun::query()->where('dataset', SeoTechnicalHealthSyncService::DATASET)->firstOrFail();
        $this->assertSame('completed', $run->status);
        $this->assertSame('example.test', data_get($run->stats, 'site_host'));
        $this->assertSame(1, data_get($run->stats, 'checked_urls'));
        $this->assertNotNull($run->source_cutoff_at);
        $this->assertStringNotContainsString('https://', json_encode($run->stats, JSON_THROW_ON_ERROR));
    }

    public function test_schema_has_daily_unique_fk_and_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('seo_technical_urls', [
            'url', 'url_hash', 'host', 'is_active', 'is_strategic', 'is_search_console',
            'search_console_rank', 'search_console_clicks', 'search_console_impressions',
            'in_sitemap', 'sitemap_url', 'first_selected_at', 'last_selected_at', 'sitemap_checked_at',
        ]));
        $this->assertTrue(Schema::hasColumns('seo_technical_url_checks', [
            'seo_technical_url_id', 'check_date', 'checked_at', 'final_url', 'http_status',
            'redirect_count', 'response_time_ms', 'has_noindex', 'canonical_url',
            'canonical_count', 'canonical_matches_final', 'body_truncated', 'error_code', 'error_message',
        ]));
        $urlIndexes = collect(DB::select("PRAGMA index_list('seo_technical_urls')"))->pluck('name');
        $checkIndexes = collect(DB::select("PRAGMA index_list('seo_technical_url_checks')"))->pluck('name');
        $this->assertContains('seo_technical_urls_hash_uq', $urlIndexes);
        $this->assertContains('seo_technical_urls_active_idx', $urlIndexes);
        $this->assertContains('seo_technical_urls_host_idx', $urlIndexes);
        $this->assertContains('seo_technical_urls_sc_rank_idx', $urlIndexes);
        $this->assertContains('seo_technical_checks_daily_uq', $checkIndexes);
        $this->assertContains('seo_technical_checks_date_idx', $checkIndexes);
        $this->assertContains('seo_technical_checks_status_idx', $checkIndexes);
        $this->assertContains('seo_technical_checks_error_idx', $checkIndexes);

        $url = SeoTechnicalUrl::query()->create([
            'url' => 'https://example.test/', 'url_hash' => hash('sha256', 'https://example.test/'),
            'host' => 'example.test', 'first_selected_at' => now(), 'last_selected_at' => now(),
        ]);
        SeoTechnicalUrlCheck::query()->create(['seo_technical_url_id' => $url->id, 'check_date' => '2026-08-18', 'checked_at' => now()]);
        $this->expectException(QueryException::class);
        SeoTechnicalUrlCheck::query()->create(['seo_technical_url_id' => $url->id, 'check_date' => '2026-08-18', 'checked_at' => now()]);
    }

    /** @param array<string, mixed> $overrides */
    private function configureSite(array $overrides = []): void
    {
        config(['seo_analytics.technical_health' => array_replace(
            config('seo_analytics.technical_health'),
            ['site_url' => 'https://example.test', 'allowed_hosts' => [], 'strategic_urls' => [], 'sitemap_urls' => []],
            $overrides,
        )]);
    }

    private function bindPublicDns(): void
    {
        app()->instance(SeoTechnicalDnsResolver::class, new class extends SeoTechnicalDnsResolver
        {
            public function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
    }

    private function fakeWebsite(): void
    {
        Http::fake(function (Request $request) {
            return match ($request->url()) {
                'https://example.test/robots.txt' => Http::response('', 404),
                'https://example.test/old' => Http::response('not found', 404, ['Content-Type' => 'text/html']),
                default => Http::response('<meta name="robots" content="index"><link rel="canonical" href="/">', 200, ['Content-Type' => 'text/html']),
            };
        });
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }
}
