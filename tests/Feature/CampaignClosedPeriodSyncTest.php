<?php

namespace Tests\Feature;

use App\Models\CampaignPlatformDailyMetric;
use App\Services\Campaigns\CampaignAttributionBuilderService;
use App\Services\Campaigns\CampaignMetricsRepository;
use App\Services\Campaigns\GoogleAdsClient;
use App\Services\Campaigns\GoogleCampaignSyncService;
use App\Services\Campaigns\MetaAdsClient;
use App\Services\Campaigns\MetaCampaignSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\Concerns\ProvidesRot4CampaignContext;
use Tests\TestCase;

class CampaignClosedPeriodSyncTest extends TestCase
{
    use ProvidesRot4CampaignContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRot4CampaignContext();
    }

    public function test_google_120_day_sync_clamps_to_open_month_and_preserves_closed_rows_and_snapshot(): void
    {
        $this->closedSeptember();
        $this->metric('google_ads', '2026-09-20', 41);
        $this->metric('google_ads', '2026-10-20', 12);
        $closedBefore = $this->closedEvidence();
        $requestedStart = null;
        $this->mock(GoogleAdsClient::class, function (MockInterface $mock) use (&$requestedStart): void {
            $mock->shouldReceive('missingConfigurationWarnings')->andReturn([]);
            $mock->shouldReceive('customerIds')->andReturn(['account-1']);
            $mock->shouldReceive('searchDailyMetrics')->twice()->andReturnUsing(
                function ($account, $start) use (&$requestedStart): array {
                    $requestedStart = $start->toDateString();

                    return [[
                        'segments' => ['date' => '2026-10-21'],
                        'campaign' => ['id' => 'campaign-1', 'name' => 'Venta', 'status' => 'ENABLED'],
                        'metrics' => ['costMicros' => '25000000', 'impressions' => '50',
                            'clicks' => '5', 'conversions' => '1'],
                    ]];
                }
            );
            $mock->shouldReceive('searchAttributionIdentifiers')->twice()->andReturn([]);
        });

        $service = app(GoogleCampaignSyncService::class);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $result = $service->sync(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-10-27'));
            $this->assertSame(1, $result['saved']);
            $this->assertContains('Intervalo acotado al primer día posterior al último cierre de inversión.', $result['warnings']);
        }

        $this->assertSame('2026-10-01', $requestedStart);
        $this->assertSame($closedBefore, $this->closedEvidence());
        $this->assertEquals(41, CampaignPlatformDailyMetric::query()->where('platform', 'google_ads')
            ->whereDate('metric_date', '2026-09-20')->firstOrFail()->spend);
        $this->assertEquals(25, CampaignPlatformDailyMetric::query()->where('platform', 'google_ads')
            ->whereDate('metric_date', '2026-10-21')->firstOrFail()->spend);
        $this->assertSame(2, CampaignPlatformDailyMetric::query()->where('platform', 'google_ads')->count());
        $this->assertSame(2, DB::table('report_sync_runs')->where('dataset', 'campaign_platform_daily_metrics')
            ->where('source', 'google_ads')->where('status', 'completed')->count());
    }

    public function test_meta_sync_clamps_to_open_month_without_touching_closed_investment(): void
    {
        $this->closedSeptember();
        $this->metric('meta', '2026-09-20', 41);
        $closedBefore = $this->closedEvidence();
        $this->mock(MetaAdsClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('missingConfigurationWarnings')->andReturn([]);
            $mock->shouldReceive('adAccountIds')->andReturn(['act_1']);
            $mock->shouldReceive('insights')->once()->withArgs(
                fn ($account, $start, $end): bool => $start->toDateString() === '2026-10-01'
                    && $end->toDateString() === '2026-10-27'
            )->andReturn([['date_start' => '2026-10-21', 'account_id' => '1',
                'campaign_id' => 'campaign-1', 'campaign_name' => 'Venta', 'spend' => '25']]);
            $mock->shouldReceive('campaigns')->once()->andReturn([]);
            $mock->shouldReceive('attributionIdentifiers')->once()->andReturn([]);
        });

        $result = app(MetaCampaignSyncService::class)->sync(
            CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-10-27')
        );

        $this->assertSame(1, $result['saved']);
        $this->assertSame($closedBefore, $this->closedEvidence());
        $this->assertEquals(41, CampaignPlatformDailyMetric::query()->where('platform', 'meta')
            ->whereDate('metric_date', '2026-09-20')->firstOrFail()->spend);
        $this->assertEquals(25, CampaignPlatformDailyMetric::query()->where('platform', 'meta')
            ->whereDate('metric_date', '2026-10-21')->firstOrFail()->spend);
        $this->assertSame('2026-10-01', json_decode(DB::table('report_sync_runs')
            ->where('source', 'meta')->value('stats'), true)['range_start']);
    }

    public function test_fully_closed_interval_does_not_call_provider_or_change_data(): void
    {
        $this->closedSeptember();
        $this->metric('google_ads', '2026-09-20', 41);
        $before = $this->closedEvidence();
        $this->mock(GoogleAdsClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('missingConfigurationWarnings')->andReturn([]);
            $mock->shouldNotReceive('searchDailyMetrics');
        });

        $result = app(GoogleCampaignSyncService::class)->sync(
            CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-10-01')
        );

        $this->assertSame(0, $result['saved']);
        $this->assertSame($before, $this->closedEvidence());
        $skipped = DB::table('report_sync_runs')->where('dataset', 'campaign_platform_daily_metrics')->first();
        $this->assertSame('skipped', $skipped->status);
        $this->assertSame('closed_period', json_decode($skipped->stats, true)['reason']);
    }

    public function test_incomplete_meta_pagination_preserves_existing_open_investment(): void
    {
        $this->metric('meta', '2026-10-20', 41);
        $before = DB::table('campaign_platform_daily_metrics')->orderBy('id')->get()->toJson();
        config()->set('services.meta_ads.access_token', 'synthetic');
        config()->set('services.meta_ads.ad_account_ids', ['act_1']);
        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/*' => Http::response([
            'data' => [], 'paging' => ['next' => 'next-page-without-cursor'],
        ])]);

        $result = app(MetaCampaignSyncService::class)->sync(
            CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-27')
        );

        $this->assertSame(0, $result['saved']);
        $this->assertCount(1, $result['warnings']);
        $this->assertSame($before, DB::table('campaign_platform_daily_metrics')->orderBy('id')->get()->toJson());
        $this->assertSame('failed', DB::table('report_sync_runs')->where('source', 'meta')->value('status'));
    }

    public function test_invalid_google_date_rolls_back_open_metrics_and_marks_run_failed(): void
    {
        $this->metric('google_ads', '2026-10-20', 41);
        $before = DB::table('campaign_platform_daily_metrics')->orderBy('id')->get()->toJson();
        $this->mock(GoogleAdsClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('missingConfigurationWarnings')->andReturn([]);
            $mock->shouldReceive('customerIds')->andReturn(['account-1']);
            $mock->shouldReceive('searchDailyMetrics')->andReturn([[
                'segments' => ['date' => '2026-10-32'],
                'campaign' => ['id' => 'campaign-1', 'name' => 'Venta'],
                'metrics' => ['costMicros' => '25000000'],
            ]]);
            $mock->shouldReceive('searchAttributionIdentifiers')->andReturn([]);
        });

        try {
            app(GoogleCampaignSyncService::class)->sync(
                CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-27')
            );
            $this->fail('La fecha inválida debe detener la sincronización.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Fecha diaria de Google Ads no válida.', $exception->getMessage());
        }

        $this->assertSame($before, DB::table('campaign_platform_daily_metrics')->orderBy('id')->get()->toJson());
        $this->assertSame('failed', DB::table('report_sync_runs')->where('source', 'google_ads')->value('status'));
    }

    public function test_month_closed_during_provider_request_cannot_be_overwritten(): void
    {
        $this->metric('google_ads', '2026-10-20', 41);
        $before = DB::table('campaign_platform_daily_metrics')->orderBy('id')->get()->toJson();
        $this->mock(GoogleAdsClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('missingConfigurationWarnings')->andReturn([]);
            $mock->shouldReceive('customerIds')->andReturn(['account-1']);
            $mock->shouldReceive('searchDailyMetrics')->andReturnUsing(function (): array {
                DB::table('campaign_investment_closures')->insert([
                    'month' => '2026-10-01', 'status' => 'closed', 'snapshot_version' => 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                return [[
                    'segments' => ['date' => '2026-10-20'],
                    'campaign' => ['id' => 'campaign-1', 'name' => 'Venta'],
                    'metrics' => ['costMicros' => '25000000'],
                ]];
            });
            $mock->shouldReceive('searchAttributionIdentifiers')->andReturn([]);
        });

        $result = app(GoogleCampaignSyncService::class)->sync(
            CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-11-01')
        );

        $this->assertSame(0, $result['saved']);
        $this->assertStringContainsString('se cerró durante la consulta', implode(' ', $result['warnings']));
        $this->assertSame($before, DB::table('campaign_platform_daily_metrics')->orderBy('id')->get()->toJson());
        $this->assertSame('failed', DB::table('report_sync_runs')->where('source', 'google_ads')->value('status'));
    }

    public function test_closed_attribution_can_be_simulated_but_not_rebuilt_in_write_mode(): void
    {
        $this->closedSeptember();
        $builder = app(CampaignAttributionBuilderService::class);
        $start = CarbonImmutable::parse('2026-09-01');
        $end = CarbonImmutable::parse('2026-10-01');
        $before = $this->closedEvidence();

        $simulation = $builder->build($start, $end, true);
        $this->assertTrue($simulation['dry_run']);
        $this->assertSame($before, $this->closedEvidence());

        $this->expectException(\LogicException::class);
        $builder->build($start, $end);
    }

    public function test_mixed_attribution_build_preserves_closed_month_rows(): void
    {
        $this->closedSeptember();
        DB::table('campaign_attributions')->insert([
            'interest_id' => '00Q-closed-attribution',
            'interest_functional_created_at' => '2026-09-20 10:00:00',
            'platform' => 'google_ads', 'campaign_id' => 'campaign-1',
            'created_at' => '2026-09-30 10:00:00', 'updated_at' => '2026-09-30 10:00:00',
        ]);
        $before = DB::table('campaign_attributions')->orderBy('id')->get()->toJson();
        $closedBefore = $this->closedEvidence();

        $result = app(CampaignAttributionBuilderService::class)->build(
            CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-10-27')
        );

        $this->assertSame('2026-10-01', $result['range_start']);
        $this->assertContains('Atribuciones acotadas al primer día posterior al último cierre de inversión.', $result['warnings']);
        $this->assertSame($before, DB::table('campaign_attributions')->orderBy('id')->get()->toJson());
        $this->assertSame($closedBefore, $this->closedEvidence());
    }

    private function closedSeptember(): void
    {
        $id = DB::table('campaign_investment_closures')->insertGetId([
            'month' => '2026-09-01', 'status' => 'closed', 'snapshot_version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('campaign_investment_closure_snapshots')->insert([
            'closure_id' => $id, 'version' => 1, 'economic_rows' => '[]',
            'source_cutoff_at' => now(), 'rule_version' => 'test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function metric(string $platform, string $date, int $spend): void
    {
        app(CampaignMetricsRepository::class)->upsert([
            'platform' => $platform, 'account_id' => $platform === 'meta' ? '1' : 'account-1',
            'campaign_id' => 'campaign-1', 'campaign_name' => 'Venta',
            'metric_date' => $date, 'spend' => $spend,
        ]);
    }

    private function closedEvidence(): array
    {
        return [
            DB::table('campaign_investment_closures')->orderBy('id')->get()->toJson(),
            DB::table('campaign_investment_closure_snapshots')->orderBy('id')->get()->toJson(),
            DB::table('campaign_platform_daily_metrics')->whereDate('metric_date', '2026-09-20')->orderBy('id')->get()->toJson(),
        ];
    }
}
