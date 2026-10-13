<?php

namespace Tests\Feature;

use App\Models\ReportSyncRun;
use App\Services\Salesforce\SalesforceClient;
use App\Services\Salesforce\SalesforceInterestSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class SeoIntegrationDiagnosticCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_diagnostic_performs_no_http_and_prints_no_secrets(): void
    {
        $secret = 'must-never-be-printed';
        $this->configureIntegrations($secret);
        $this->completedF2();
        Http::fake();

        $this->artisan('seo:diagnose-integrations')
            ->expectsOutputToContain('sin red')
            ->doesntExpectOutputToContain($secret)
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_live_diagnostic_uses_only_read_operations_and_sanitizes_output(): void
    {
        $secret = 'must-never-be-printed';
        $this->configureIntegrations($secret);
        $this->completedF2();
        $this->mock(SalesforceClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('describe');
        });
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'synthetic-access']),
            'https://www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => [[
                'siteUrl' => 'sc-domain:example.test',
                'permissionLevel' => 'siteOwner',
            ]]]),
            'https://analyticsadmin.googleapis.com/v1beta/properties/123' => Http::response([
                'name' => 'properties/123',
                'timeZone' => 'Europe/Madrid',
            ]),
            'https://analyticsdata.googleapis.com/v1beta/properties/123/metadata' => Http::response([
                'name' => 'properties/123/metadata',
                'dimensions' => [],
                'metrics' => [],
            ]),
            'https://analyticsadmin.googleapis.com/v1beta/properties/123/keyEvents*' => Http::response([
                'keyEvents' => [['eventName' => 'synthetic_event']],
            ]),
            'https://analyticsadmin.googleapis.com/v1beta/properties/123/dataStreams*' => Http::response([
                'dataStreams' => [[
                    'name' => 'properties/123/dataStreams/1',
                    'type' => 'WEB_DATA_STREAM',
                    'displayName' => 'Synthetic web',
                    'webStreamData' => ['defaultUri' => 'https://example.test'],
                ]],
            ]),
            'https://api.sistrix.com/credits' => Http::response(['answer' => [['credits' => 1]]]),
        ]);

        $this->artisan('seo:diagnose-integrations', ['--live' => true])
            ->expectsOutputToContain('F2 Interests: completed')
            ->expectsOutputToContain('Proyección SEO Interest: missing')
            ->expectsOutputToContain('Property accesible: sí')
            ->expectsOutputToContain('synthetic_event')
            ->expectsOutputToContain('Web streams: 1')
            ->expectsOutputToContain('Synthetic web')
            ->expectsOutputToContain('API SISTRIX: accesible')
            ->expectsOutputToContain('AI Check: pendiente de verificar')
            ->doesntExpectOutputToContain($secret)
            ->assertExitCode(0);

        Http::assertSentCount(8);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'ai.check'));
        $this->assertTrue(Http::recorded()->every(function (array $exchange): bool {
            $request = $exchange[0];

            return $request->method() === 'GET'
                || ($request->method() === 'POST' && in_array($request->url(), [
                    'https://oauth2.googleapis.com/token',
                    'https://api.sistrix.com/credits',
                ], true));
        }));
    }

    private function configureIntegrations(string $secret): void
    {
        config([
            'salesforce.auth_mode' => 'client_credentials',
            'salesforce.client_id' => 'salesforce-client',
            'salesforce.client_secret' => $secret,
            'services.google_search_console.client_id' => 'search-client',
            'services.google_search_console.client_secret' => $secret,
            'services.google_search_console.refresh_token' => $secret,
            'services.google_search_console.property' => 'sc-domain:example.test',
            'services.google_analytics.client_id' => 'analytics-client',
            'services.google_analytics.client_secret' => $secret,
            'services.google_analytics.refresh_token' => $secret,
            'services.google_analytics.property_id' => '123',
            'services.sistrix.api_key' => $secret,
        ]);
    }

    private function completedF2(): void
    {
        ReportSyncRun::query()->create([
            'dataset' => SalesforceInterestSyncService::DATASET,
            'source' => SalesforceInterestSyncService::SOURCE,
            'status' => 'completed',
            'period_start_at' => '2026-10-09 00:00:00',
            'period_end_at' => '2026-10-09 10:00:00',
            'source_cutoff_at' => '2026-10-09 10:00:00',
            'started_at' => '2026-10-09 09:59:00',
            'completed_at' => '2026-10-09 10:01:00',
            'timezone' => 'UTC',
        ]);
    }
}
