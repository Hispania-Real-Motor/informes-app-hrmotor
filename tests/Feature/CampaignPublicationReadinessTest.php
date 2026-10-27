<?php

namespace Tests\Feature;

use App\Models\ReportSyncRun;
use App\Services\Campaigns\CampaignPublicationReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\ProvidesRot4CampaignContext;
use Tests\TestCase;

class CampaignPublicationReadinessTest extends TestCase
{
    use ProvidesRot4CampaignContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRot4CampaignContext();
    }

    public function test_unchanged_salesforce_syncs_do_not_rebuild_but_material_changes_do(): void
    {
        $readiness = app(CampaignPublicationReadiness::class);
        $this->seedSources();
        $initial = $readiness->sources();
        $this->assertTrue($initial['ready']);
        $this->completeBuild($initial['ids']);
        $this->assertFalse($readiness->needsAttribution($initial['ids']));

        $this->sourceRun('salesforce_interests', 'salesforce', ['inserted' => 0, 'updated' => 0, 'reactivated' => 0]);
        $this->sourceRun('salesforce_opportunities', 'salesforce', [
            'material_changed' => 0, 'deleted_changed' => 0, 'history_saved' => 0,
        ]);
        DB::table('salesforce_opportunity_interest_direct_runs')->where('id', $this->rot4DirectRunId)
            ->update(['completed_at' => now()->addMinute()]);
        $unchanged = $readiness->sources();
        $this->assertTrue($unchanged['ready']);
        $this->assertFalse($readiness->needsAttribution($unchanged['ids']));

        $this->sourceRun('salesforce_interests', 'salesforce', ['inserted' => 0, 'updated' => 1, 'reactivated' => 0]);
        $this->assertTrue($readiness->needsAttribution($readiness->sources()['ids']));
    }

    public function test_opportunity_and_direct_reference_changes_require_rebuild(): void
    {
        $readiness = app(CampaignPublicationReadiness::class);
        $this->seedSources();
        $this->completeBuild($readiness->sources()['ids']);

        $this->sourceRun('salesforce_opportunities', 'salesforce', [
            'material_changed' => 1, 'deleted_changed' => 0,
        ]);
        DB::table('salesforce_opportunity_interest_direct_runs')->where('id', $this->rot4DirectRunId)
            ->update(['completed_at' => now()->addMinute()]);
        $this->assertTrue($readiness->needsAttribution($readiness->sources()['ids']));

        $this->seedSources();
        $this->completeBuild($readiness->sources()['ids']);
        DB::table('salesforce_opportunity_interest_direct_runs')->where('id', $this->rot4DirectRunId)
            ->update(['stats' => json_encode(['reference_fingerprint' => str_repeat('d', 64)])]);
        $this->assertTrue($readiness->needsAttribution($readiness->sources()['ids']));
    }

    public function test_manual_completed_source_run_is_accepted_and_failed_latest_run_blocks_publication(): void
    {
        $readiness = app(CampaignPublicationReadiness::class);
        $this->seedSources();
        $this->assertTrue($readiness->sources()['ready']);
        $failed = $this->sourceRun('campaign_platform_daily_metrics', 'google_ads');
        $failed->update(['status' => 'failed']);
        $this->assertFalse($readiness->sources()['ready']);

        $this->sourceRun('campaign_platform_daily_metrics', 'google_ads');
        $this->assertTrue($readiness->sources()['ready']);
    }

    public function test_advertising_source_must_cover_the_open_period(): void
    {
        $readiness = app(CampaignPublicationReadiness::class);
        $this->seedSources();
        $start = CarbonImmutable::now('Europe/Madrid')->subDays(5)->startOfDay();
        $end = CarbonImmutable::now('Europe/Madrid');
        $this->assertTrue($readiness->sources($start, $end)['ready']);

        ReportSyncRun::query()->where('dataset', 'campaign_platform_daily_metrics')
            ->where('source', 'google_ads')->latest('id')->firstOrFail()
            ->update(['period_start_at' => $start->addDay()]);
        $this->assertFalse($readiness->sources($start, $end)['ready']);
    }

    public function test_manual_success_after_failed_operation_restores_advertising_source_readiness(): void
    {
        $readiness = app(CampaignPublicationReadiness::class);
        $this->seedSources();
        DB::table('operational_task_runs')->insert([
            'task_key' => 'campaigns-sync-google', 'origin' => 'scheduler',
            'status' => 'failed', 'arguments' => '{}',
            'correlation_id' => (string) Str::uuid(),
            'queued_at' => now()->addMinute(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertFalse($readiness->sources()['ready']);

        $this->sourceRun('campaign_platform_daily_metrics', 'google_ads')->update([
            'completed_at' => now()->addMinutes(2),
        ]);
        $this->assertTrue($readiness->sources()['ready']);
    }

    public function test_publication_requires_same_attribution_lineage_and_a_persisted_snapshot(): void
    {
        $readiness = app(CampaignPublicationReadiness::class);
        $this->seedSources();
        $ids = $readiness->sources()['ids'];
        $build = $this->completeBuild($ids);
        $this->assertFalse($readiness->published()['ready']);
        $snapshotId = DB::table('campaign_report_snapshots')->insertGetId([
            'period_start' => '2026-10-01', 'period_end' => '2026-10-27', 'filters_hash' => 'synthetic',
            'summary' => '{}', 'campaigns' => '[]', 'rankings' => '[]', 'warnings' => '[]',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->sourceRun(CampaignPublicationReadiness::PUBLICATION_DATASET, 'campaigns', [
            'snapshot_id' => $snapshotId, 'attribution_run_id' => $build->id, 'source_ids' => $ids,
        ]);
        $this->assertTrue($readiness->published()['ready']);

        $this->sourceRun('campaign_platform_daily_metrics', 'google_ads');
        $this->assertFalse($readiness->published()['ready']);
    }

    public function test_published_snapshot_does_not_certify_a_period_outside_its_coverage(): void
    {
        $readiness = app(CampaignPublicationReadiness::class);
        $this->seedSources();
        $ids = $readiness->sources()['ids'];
        $build = $this->completeBuild($ids);
        $start = CarbonImmutable::now('Europe/Madrid')->subDays(5)->startOfDay();
        $end = CarbonImmutable::now('Europe/Madrid');
        $snapshotId = DB::table('campaign_report_snapshots')->insertGetId([
            'period_start' => $start->addDay()->toDateString(),
            'period_end' => $end->toDateString(), 'filters_hash' => 'synthetic',
            'summary' => '{}', 'campaigns' => '[]', 'rankings' => '[]', 'warnings' => '[]',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->sourceRun(CampaignPublicationReadiness::PUBLICATION_DATASET, 'campaigns', [
            'snapshot_id' => $snapshotId, 'attribution_run_id' => $build->id, 'source_ids' => $ids,
        ]);

        $this->assertFalse($readiness->published($start, $end)['ready']);
    }

    public function test_refresh_refuses_to_store_snapshot_without_verified_dependencies(): void
    {
        $this->artisan('reports:refresh-campaigns', ['--days' => 1, '--store' => true])
            ->assertExitCode(1);
        $this->assertDatabaseCount('campaign_report_snapshots', 0);
        $this->assertDatabaseHas('report_sync_runs', [
            'dataset' => CampaignPublicationReadiness::PUBLICATION_DATASET,
            'status' => 'failed',
        ]);
    }

    public function test_automatic_build_rebuilds_short_range_then_skips_unchanged_sources(): void
    {
        $this->seedSources();
        $readiness = app(CampaignPublicationReadiness::class);
        $short = $this->completeBuild($readiness->sources()['ids']);
        $short->update(['stats' => array_merge($short->stats, [
            'range_start' => CarbonImmutable::now('Europe/Madrid')->subDay()->toDateString(),
            'range_end_exclusive' => CarbonImmutable::now('Europe/Madrid')->toDateString(),
        ])]);

        $this->artisan('campaigns:build-attribution', ['--days' => 3, '--require-ready' => true])
            ->assertExitCode(0);
        $this->assertSame(2, ReportSyncRun::query()->where('dataset', CampaignPublicationReadiness::ATTRIBUTION_DATASET)->count());

        $this->sourceRun('salesforce_interests', 'salesforce', ['inserted' => 0, 'updated' => 0, 'reactivated' => 0]);
        $this->artisan('campaigns:build-attribution', ['--days' => 3, '--require-ready' => true])
            ->expectsOutputToContain('SKIPPED:')
            ->assertExitCode(0);
        $this->assertSame(2, ReportSyncRun::query()->where('dataset', CampaignPublicationReadiness::ATTRIBUTION_DATASET)->count());
    }

    private function seedSources(): void
    {
        $now = CarbonImmutable::now('UTC');
        DB::table('report_sync_runs')->where('id', $this->rot4InterestRunId)->update([
            'status' => 'completed', 'source_cutoff_at' => $now->subMinutes(30),
            'completed_at' => $now->subMinutes(30),
            'stats' => json_encode(['inserted' => 0, 'updated' => 0, 'reactivated' => 0]),
        ]);
        $this->sourceRun('campaign_platform_daily_metrics', 'meta');
        $this->sourceRun('campaign_platform_daily_metrics', 'google_ads');
        $this->sourceRun('salesforce_opportunities', 'salesforce', [
            'material_changed' => 0, 'deleted_changed' => 0, 'history_saved' => 0,
        ]);
        DB::table('salesforce_opportunity_interest_direct_runs')->where('id', $this->rot4DirectRunId)->update([
            'status' => 'completed', 'source_cutoff_at' => $now,
            'completed_at' => $now->addMinute(),
            'stats' => json_encode(['reference_fingerprint' => str_repeat('c', 64)]),
        ]);
    }

    private function completeBuild(array $ids): ReportSyncRun
    {
        return $this->sourceRun(CampaignPublicationReadiness::ATTRIBUTION_DATASET, 'campaigns', ['source_ids' => $ids]);
    }

    private function sourceRun(string $dataset, string $source, array $stats = []): ReportSyncRun
    {
        return ReportSyncRun::query()->create([
            'dataset' => $dataset, 'source' => $source, 'status' => 'completed',
            'period_start_at' => $dataset === 'campaign_platform_daily_metrics' ? now()->subDays(7) : null,
            'period_end_at' => $dataset === 'campaign_platform_daily_metrics' ? now()->addDay() : null,
            'started_at' => now()->subMinute(), 'completed_at' => now(),
            'source_cutoff_at' => now(), 'timezone' => 'Europe/Madrid', 'stats' => $stats,
        ]);
    }
}
