<?php

namespace Tests\Feature;

use App\Models\ReportUser;
use App\Services\Campaigns\CampaignOperationalAttributionBuilder;
use App\Services\Reports\Leads\ProvisionalInterestSourceUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CampaignOperationalAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('c', 32)));
        config()->set('campaign_operational.october_2026_enabled', true);
        config()->set('services.informes_auth.enabled', true);
    }

    public function test_closed_october_build_is_versioned_and_does_not_touch_official_or_economic_rows(): void
    {
        $source = $this->operationalMonth('2026-10', 2);
        $this->closedOctober();
        $beforeClosure = DB::table('campaign_investment_closures')->first();
        $beforeSnapshot = DB::table('campaign_investment_closure_snapshots')->first();
        $first = app(CampaignOperationalAttributionBuilder::class)->build(2026, 10);
        $this->assertSame('prepared', $first['status']);
        $this->assertNull(DB::table('campaign_operational_months')->value('active_run_id'));
        $this->assertSame(2, $first['attributions_count']);
        $this->assertFalse($first['opportunity_coverage_complete']);
        $this->assertSame($source, (int) DB::table('campaign_operational_runs')->value('interest_run_id'));
        $this->assertSame(0, DB::table('campaign_attributions')->count());
        $this->assertSame(0, DB::table('campaign_lead_attributions')->count());
        $this->assertEquals($beforeClosure, DB::table('campaign_investment_closures')->first());
        $this->assertEquals($beforeSnapshot, DB::table('campaign_investment_closure_snapshots')->first());
        $this->assertSame(2, DB::table('campaign_operational_attributions')->count());
        $this->assertFalse(Schema::hasColumn('campaign_operational_attributions', 'utm_campaign'));
        $this->assertFalse(Schema::hasColumn('campaign_operational_attributions', 'owner_name'));
        $this->assertFalse(Str::contains(DB::table('campaign_operational_attributions')->first()->interest_key, 'a01'));
        $this->assertSame($first['run_id'], app(CampaignOperationalAttributionBuilder::class)->build(2026, 10)['run_id']);
        $this->assertSame(1, DB::table('campaign_operational_runs')->count());
        $this->assertSame('completed', app(CampaignOperationalAttributionBuilder::class)->activate(2026, 10, $first['run_id'])['status']);
        $this->assertSame($first['run_id'], DB::table('campaign_operational_months')->value('active_run_id'));
        $this->assertTrue(app(CampaignOperationalAttributionBuilder::class)->activate(2026, 10, $first['run_id'])['reused']);
    }

    public function test_admin_sees_provisional_interest_counts_with_unknown_opportunities_and_version_invalidation(): void
    {
        $this->operationalMonth('2026-10', 2);
        $this->closedOctober();
        $this->buildAndActivate(2026, 10);
        $admin = $this->reportUser(ReportUser::ROLE_ADMIN);
        $this->withSession($this->sessionFor($admin));
        $url = '/informes/campanas/data/summary?source=operational&start_date=2026-10-01&end_date=2026-10-31&campaign_status=';
        $this->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('availability.mode', 'operational_provisional')
            ->assertJsonPath('kpis.interests_salesforce', 2)
            ->assertJsonPath('kpis.opportunities', null)
            ->assertJsonPath('operational_evidence.economic_state', 'closed');
        $this->getJson('/informes/campanas/data/summary?start_date=2026-10-01&end_date=2026-10-31')
            ->assertOk()->assertJsonMissingPath('operational_evidence');
        DB::table('report_sync_runs')->insert([
            'dataset' => 'campaign_platform_daily_metrics', 'source' => 'meta', 'status' => 'completed',
            'period_start_at' => '2026-11-01 00:00:00', 'period_end_at' => '2026-12-01 00:00:00',
            'source_cutoff_at' => '2026-11-13 00:00:00', 'completed_at' => '2026-11-13 00:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->getJson($url)->assertOk()->assertJsonPath('availability.mode', 'operational_provisional')
            ->assertJsonPath('kpis.interests_salesforce', 2);
        $this->assertSame(1, DB::table('campaign_operational_runs')->count());
        $snapshot = DB::table('campaign_investment_closure_snapshots')->value('economic_rows');
        $changed = json_decode($snapshot, true);
        $changed[0]['spend'] = 101;
        DB::table('campaign_investment_closure_snapshots')->update(['economic_rows' => json_encode($changed)]);
        $this->getJson($url)->assertOk()->assertJsonPath('availability.mode', 'platform_independent_only')
            ->assertJsonPath('kpis.interests_salesforce', null);
        DB::table('campaign_investment_closure_snapshots')->update(['economic_rows' => $snapshot]);
        DB::table('salesforce_interest_operational_months')->where('month_key', '2026-10')
            ->update(['active_projection_key' => (string) Str::uuid()]);
        $this->getJson($url)->assertOk()->assertJsonPath('availability.mode', 'platform_independent_only')
            ->assertJsonPath('kpis.interests_salesforce', null);
    }

    public function test_prepared_run_cannot_activate_after_closed_snapshot_content_changes(): void
    {
        $this->operationalMonth('2026-10', 2);
        $this->closedOctober();
        $prepared = app(CampaignOperationalAttributionBuilder::class)->build(2026, 10);
        $snapshot = json_decode(DB::table('campaign_investment_closure_snapshots')->value('economic_rows'), true);
        $snapshot[0]['spend'] = 123;
        DB::table('campaign_investment_closure_snapshots')->update(['economic_rows' => json_encode($snapshot)]);

        try {
            app(CampaignOperationalAttributionBuilder::class)->activate(2026, 10, $prepared['run_id']);
            $this->fail('Una preparación con cierre modificado no debe activarse.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Las fuentes preparadas ya no están vigentes.', $exception->getMessage());
        }

        $this->assertNull(DB::table('campaign_operational_months')->value('active_run_id'));
        $this->assertSame('prepared', DB::table('campaign_operational_runs')->value('status'));
    }

    public function test_utm_v1_is_rejected_and_non_admin_cannot_read_operational_attribution(): void
    {
        $this->operationalMonth('2026-10', 1);
        $this->closedOctober();
        $this->expectException(ProvisionalInterestSourceUnavailable::class);
        app(CampaignOperationalAttributionBuilder::class)->build(2026, 10);
    }

    public function test_non_admin_is_denied_even_when_a_provisional_run_exists(): void
    {
        $this->operationalMonth('2026-10', 2);
        $this->closedOctober();
        $this->buildAndActivate(2026, 10);
        $director = $this->reportUser(ReportUser::ROLE_DIRECTOR);
        $this->withSession($this->sessionFor($director))
            ->getJson('/informes/campanas/data/summary?source=operational&start_date=2026-10-01&end_date=2026-10-31')
            ->assertForbidden();
    }

    public function test_campaign_flags_are_independent_and_disabled_by_default(): void
    {
        $this->operationalMonth('2026-10', 2);
        $this->closedOctober();
        $prepared = app(CampaignOperationalAttributionBuilder::class)->build(2026, 10);
        $admin = $this->reportUser(ReportUser::ROLE_ADMIN);
        $this->withSession($this->sessionFor($admin));
        $url = '/informes/campanas/data/summary?source=operational&start_date=2026-10-01&end_date=2026-10-31';
        config()->set('salesforce.october_2026_provisional_enabled', true);
        config()->set('campaign_operational.october_2026_enabled', false);
        $this->getJson($url)->assertNotFound();
        $this->assertNull(DB::table('campaign_operational_months')->value('active_run_id'));
        config()->set('campaign_operational.october_2026_enabled', true);
        $this->getJson($url)->assertOk()->assertJsonPath('availability.mode', 'platform_independent_only');
        app(CampaignOperationalAttributionBuilder::class)->activate(2026, 10, $prepared['run_id']);
        $this->getJson($url)->assertOk()->assertJsonPath('availability.mode', 'operational_provisional');
        config()->set('campaign_operational.october_2026_enabled', false);
        $this->getJson($url)->assertNotFound();
    }

    public function test_migration_rollback_preserves_prepared_and_active_evidence(): void
    {
        $this->operationalMonth('2026-10', 2);
        $this->closedOctober();
        $run = $this->buildAndActivate(2026, 10);
        $migration = require base_path('database/migrations/2026_11_19_120000_create_campaign_operational_attributions.php');
        $migration->down();
        $this->assertTrue(Schema::hasTable('campaign_operational_months'));
        $this->assertTrue(Schema::hasTable('campaign_operational_runs'));
        $this->assertTrue(Schema::hasTable('campaign_operational_attributions'));
        $this->assertSame($run['run_id'], (int) DB::table('campaign_operational_months')->value('active_run_id'));
        $this->assertSame(2, DB::table('campaign_operational_attributions')->count());
    }

    public function test_more_than_two_pages_are_staged_without_journal_reads_in_http(): void
    {
        $this->operationalMonth('2026-10', 2, 1001);
        $this->closedOctober();
        $result = $this->buildAndActivate(2026, 10);
        $this->assertSame(1001, $result['interests_processed']);
        $this->assertSame(1001, $result['attributions_count']);
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        $admin = $this->reportUser(ReportUser::ROLE_ADMIN);
        $this->withSession($this->sessionFor($admin))
            ->getJson('/informes/campanas/data/summary?source=operational&start_date=2026-10-01&end_date=2026-10-31&campaign_status=')
            ->assertOk()->assertJsonPath('kpis.interests_salesforce', 1001);
        $this->assertFalse((bool) array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'salesforce_interest_operational_rows')));
    }

    public function test_open_november_uses_covered_meta_and_keeps_uncovered_google_unavailable(): void
    {
        $this->operationalMonth('2026-11', 2);
        config()->set('campaign_operational.november_2026_enabled', true);
        DB::table('campaign_platform_daily_metrics')->insert([
            'unique_key' => hash('sha256', 'november-meta'), 'platform' => 'meta',
            'metric_date' => '2026-11-05', 'campaign_id' => 'campaign-1',
            'campaign_name' => 'Campaña sintética', 'spend' => 75,
            'impressions' => 500, 'clicks' => 40, 'platform_leads' => 2,
            'platform_conversions' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('report_sync_runs')->insert([
            'dataset' => 'campaign_platform_daily_metrics', 'source' => 'meta', 'status' => 'completed',
            'period_start_at' => '2026-10-31 23:00:00', 'period_end_at' => '2026-11-13 00:00:00',
            'source_cutoff_at' => '2026-11-13 01:00:00',
            'started_at' => '2026-11-13 01:00:00', 'completed_at' => '2026-11-13 01:01:00',
            'timezone' => 'Europe/Madrid', 'stats' => json_encode([
                'range_start' => '2026-11-01', 'range_end_exclusive' => '2026-11-13',
            ], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $result = $this->buildAndActivate(2026, 11);
        $this->assertSame(2, $result['attributions_count']);
        $admin = $this->reportUser(ReportUser::ROLE_ADMIN);
        $this->withSession($this->sessionFor($admin))
            ->getJson('/informes/campanas/data/summary?source=operational&start_date=2026-11-01&end_date=2026-11-30&campaign_status=')
            ->assertOk()->assertJsonPath('availability.mode', 'operational_provisional')
            ->assertJsonPath('availability.platforms.meta.status', 'available')
            ->assertJsonPath('availability.platforms.google_ads.status', 'blocked')
            ->assertJsonPath('kpis.interests_salesforce', 2);
    }

    public function test_live_build_lease_rejects_a_competing_worker_without_changing_the_pointer(): void
    {
        $this->operationalMonth('2026-10', 2);
        $this->closedOctober();
        DB::table('campaign_operational_months')->insert([
            'month_key' => '2026-10', 'build_token' => (string) Str::uuid(),
            'lease_expires_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            app(CampaignOperationalAttributionBuilder::class)->build(2026, 10);
            $this->fail('A second owner must not claim a live month.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Ya existe una construcción operativa en curso.', $exception->getMessage());
        }
        $this->assertNull(DB::table('campaign_operational_months')->where('month_key', '2026-10')->value('active_run_id'));
        $this->assertSame(0, DB::table('campaign_operational_runs')->count());
    }

    public function test_exact_direct_and_inverse_opportunity_enables_reservation_sale_and_amount_without_duplicates(): void
    {
        $sourceRun = $this->operationalMonth('2026-10', 2);
        $this->closedOctober();
        $interestId = sprintf('a01%015d', 1);
        $opportunityId = sprintf('006%015d', 1);
        DB::table('salesforce_interest_operational_rows')->where('run_id', $sourceRun)
            ->where('salesforce_id', $interestId)->update(['inverse_opportunity_salesforce_id' => $opportunityId]);
        $directRun = DB::table('salesforce_opportunity_interest_direct_runs')->insertGetId([
            'run_identifier' => (string) Str::uuid(), 'reason' => 'Synthetic', 'status' => 'completed',
            'source_cutoff_at' => '2026-11-03 00:00:00', 'started_at' => '2026-11-03 00:00:00',
            'completed_at' => '2026-11-03 00:00:00',
            'stats' => json_encode(['reference_fingerprint' => str_repeat('a', 64)]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('salesforce_opportunity_interest_directs')->insert([
            'direct_run_id' => $directRun, 'opportunity_salesforce_id' => $opportunityId,
            'interest_salesforce_id' => $interestId, 'reference_status' => 'valid',
            'opportunity_is_deleted' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('salesforce_opportunities')->insert([
            'salesforce_id' => $opportunityId, 'record_type_name' => 'Venta',
            'stage_name' => 'Generar contrato', 'reservation' => true,
            'reservation_date' => '2026-10-20', 'cv_signed' => true,
            'cv_signed_date' => '2026-10-25', 'opo_for_importe_total' => 20000,
            'is_deleted' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('report_sync_runs')->insert([
            'dataset' => 'salesforce_opportunities', 'source' => 'salesforce', 'status' => 'completed',
            'source_cutoff_at' => '2026-11-03 00:00:00', 'started_at' => '2026-11-03 00:00:00',
            'completed_at' => '2026-11-03 00:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $result = $this->buildAndActivate(2026, 10);
        $this->assertTrue($result['opportunity_coverage_complete']);
        $admin = $this->reportUser(ReportUser::ROLE_ADMIN);
        $this->withSession($this->sessionFor($admin))
            ->getJson('/informes/campanas/data/summary?source=operational&start_date=2026-10-01&end_date=2026-10-31&campaign_status=')
            ->assertOk()->assertJsonPath('kpis.opportunities', 1)
            ->assertJsonPath('kpis.reservations', 1)
            ->assertJsonPath('kpis.sales', 1)
            ->assertJsonPath('kpis.sale_amount', 20000);
        $this->assertSame(1, DB::table('campaign_operational_attributions')->whereNotNull('opportunity_key')->count());

        $contradictoryRun = DB::table('salesforce_opportunity_interest_direct_runs')->insertGetId([
            'run_identifier' => (string) Str::uuid(), 'reason' => 'Synthetic conflict', 'status' => 'completed',
            'source_cutoff_at' => '2026-11-04 00:00:00', 'started_at' => '2026-11-04 00:00:00',
            'completed_at' => '2026-11-04 00:00:00',
            'stats' => json_encode(['reference_fingerprint' => str_repeat('b', 64)]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('salesforce_opportunity_interest_directs')->insert([
            'direct_run_id' => $contradictoryRun, 'opportunity_salesforce_id' => $opportunityId,
            'interest_salesforce_id' => sprintf('a01%015d', 2), 'reference_status' => 'valid',
            'opportunity_is_deleted' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $conflicted = $this->buildAndActivate(2026, 10);
        $this->assertNotSame($result['run_id'], $conflicted['run_id']);
        $this->assertFalse($conflicted['opportunity_coverage_complete']);
        $this->assertSame('completed', DB::table('campaign_operational_runs')->where('id', $result['run_id'])->value('status'));
        $this->getJson('/informes/campanas/data/summary?source=operational&start_date=2026-10-01&end_date=2026-10-31&campaign_status=')
            ->assertOk()->assertJsonPath('kpis.sales', null);

        DB::table('report_sync_runs')->insert([
            'dataset' => 'salesforce_opportunities', 'source' => 'salesforce', 'status' => 'completed',
            'source_cutoff_at' => '2026-11-05 00:00:00', 'started_at' => '2026-11-05 00:00:00',
            'completed_at' => '2026-11-05 00:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $stale = $this->buildAndActivate(2026, 10);
        $this->assertFalse($stale['opportunity_coverage_complete']);
        $this->getJson('/informes/campanas/data/summary?source=operational&start_date=2026-10-01&end_date=2026-10-31&campaign_status=')
            ->assertOk()->assertJsonPath('kpis.opportunities', null);
    }

    private function buildAndActivate(int $year, int $month): array
    {
        $builder = app(CampaignOperationalAttributionBuilder::class);
        $result = $builder->build($year, $month);
        $builder->activate($year, $month, $result['run_id']);

        return $result;
    }

    private function operationalMonth(string $month, int $utmVersion, int $count = 2): int
    {
        $start = $month.'-01 00:00:00';
        $october = $month === '2026-10';
        $key = (string) Str::uuid();
        $run = DB::table('salesforce_interest_operational_runs')->insertGetId([
            'run_identifier' => (string) Str::uuid(), 'month_key' => $month,
            'functional_start_at' => $start, 'functional_end_at' => $october ? '2026-11-01 00:00:00' : '2026-12-01 00:00:00',
            'effective_cutoff_at' => $october ? '2026-11-01 00:00:00' : '2026-11-12 12:00:00',
            'status' => 'completed', 'phase' => 'completed',
            'projection_key' => $key, 'utm_contract_version' => $utmVersion,
            'overview' => json_encode(['coverage' => ['eligible_active_matching' => $count],
                'classification' => [], 'functional_kpis' => []], JSON_THROW_ON_ERROR),
            'completed_at' => $october ? '2026-11-02 00:00:00' : '2026-11-12 12:30:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('salesforce_interest_operational_months')->insert([
            'month_key' => $month, 'active_run_id' => $run, 'active_projection_key' => $key,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $rows = [];
        for ($n = 1; $n <= $count; $n++) {
            $rows[] = [
                'run_id' => $run, 'salesforce_id' => sprintf('a01%015d', $n),
                'content_hash' => hash('sha256', (string) $n), 'field_fingerprints' => '{}',
                'functional_created_at' => $october ? '2026-10-05 10:00:00' : '2026-11-05 10:00:00',
                'observed_at' => $october ? '2026-11-02 00:00:00' : '2026-11-12 12:30:00',
                'is_deleted' => false, 'status' => 'Potencial', 'type' => 'Venta',
                'utm_campaign_ciphertext' => $utmVersion >= 2 ? Crypt::encryptString('Campaña sintética') : null,
                'utm_id_ciphertext' => $utmVersion >= 2 ? Crypt::encryptString('campaign-1') : null,
                'created_at' => now(), 'updated_at' => now(),
            ];
            if (count($rows) === 100 || $n === $count) {
                DB::table('salesforce_interest_operational_rows')->insert($rows);
                $rows = [];
            }
        }

        return (int) $run;
    }

    private function closedOctober(): void
    {
        $closure = DB::table('campaign_investment_closures')->insertGetId([
            'month' => '2026-10-01', 'status' => 'closed', 'snapshot_version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('campaign_investment_closure_snapshots')->insert([
            'closure_id' => $closure, 'version' => 1, 'source_cutoff_at' => '2026-11-01 00:00:00',
            'rule_version' => 'test', 'economic_rows' => json_encode([[
                'platform' => 'meta', 'account_id' => 'account-1', 'campaign_id' => 'campaign-1',
                'campaign_name' => 'Campaña sintética', 'spend' => 100, 'impressions' => 1000,
                'clicks' => 100, 'platform_leads' => 3, 'platform_leads_rows' => 1,
                'platform_conversions' => 1,
            ]], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function reportUser(string $role): ReportUser
    {
        return ReportUser::create(['name' => 'Synthetic', 'email' => uniqid('', true).'@example.test',
            'password' => 'synthetic-password', 'role' => $role, 'is_active' => true]);
    }

    private function sessionFor(ReportUser $user): array
    {
        return ['informes_authenticated' => true, 'report_user_id' => $user->id,
            'report_user_role' => $user->role, 'report_user_email' => $user->email];
    }
}
