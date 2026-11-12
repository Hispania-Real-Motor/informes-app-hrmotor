<?php

namespace Tests\Feature;

use App\Models\ReportUser;
use App\Services\Reports\Leads\OperationalInterestMonthlyReader;
use App\Services\Reports\Leads\OperationalInterestQualitySnapshot;
use App\Services\Reports\Leads\ProvisionalInterestSourceUnavailable;
use App\Services\Salesforce\OperationalInterestMonthlySync;
use App\Services\Salesforce\SalesforceClient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperationalInterestMonthlySyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_changed_interests_remain_admitted_with_audited_coverage_and_november_dashboard(): void
    {
        Carbon::setTestNow('2026-11-12 12:00:00 UTC');
        $records = $this->records(1002);
        $fake = new OperationalInterestFakeClient($records);
        $fake->mutateOnVerify = [1, 2, 3, 4];
        $this->app->instance(SalesforceClient::class, $fake);
        $sync = app(OperationalInterestMonthlySync::class);
        $runId = $sync->prepare('2026-11');
        $result = $sync->run($runId);

        $this->assertSame('completed', $result['status']);
        $this->assertSame(1002, $result['extracted']);
        $this->assertSame(1002, $result['checked']);
        $this->assertSame(4, $result['changed']);
        $this->assertSame(4, app(OperationalInterestMonthlyReader::class)
            ->latestForMonth(2026, 11)['overview']['coverage']['change_categories']['functional_fields']);
        $this->assertSame(0, $result['quarantined']);
        $this->assertSame(4, DB::table('salesforce_interest_operational_incidents')->where('run_id', $runId)->count());
        $this->assertSame(1002, DB::table('salesforce_interest_operational_rows')->where('run_id', $runId)->count());
        $this->assertSame(0, DB::table('salesforce_interest_monthly_recovery_rows')->count());
        $this->assertSame(0, DB::table('salesforce_interest_generations')->count());
        $this->assertNotContains('raw_payload', Schema::getColumnListing('salesforce_interest_operational_rows'));
        $snapshot = app(OperationalInterestMonthlyReader::class)->latestForMonth(2026, 11);
        $this->assertSame(1002, $snapshot['overview']['coverage']['eligible_active_matching']);
        $this->assertFalse($snapshot['overview']['verification']['f2_verified']);

        config()->set('services.informes_auth.enabled', true);
        config()->set('salesforce.november_2026_provisional_enabled', false);
        $admin = $this->user(ReportUser::ROLE_ADMIN);
        $this->asReportUser($admin)->get('/informes/leads?period=november_2026')->assertNotFound();
        config()->set('salesforce.november_2026_provisional_enabled', true);
        $this->asReportUser($admin)->get('/informes/leads?period=november_2026')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        $this->asReportUser($admin)->getJson('/informes/leads/data/resumen?period=november_2026&status=Potencial')
            ->assertOk()->assertJsonPath('kpis.leads_totales', 998)
            ->assertJsonPath('coverage.changed_between_reads', 4)
            ->assertJsonPath('coverage.observed', 1002);
        $this->assertSame('Venta', DB::table('salesforce_interest_october_provisional_projection_rows')
            ->where('scope', 'operational_2026_11')->value('interest_type'));
        $this->assertSame('Madrid General', DB::table('salesforce_interest_october_provisional_projection_rows')
            ->where('scope', 'operational_2026_11')->value('origin_delegation'));
        $this->asReportUser($admin)->getJson('/informes/leads/data/resumen?'.http_build_query([
            'period' => 'november_2026', 'status' => 'Potencial', 'lead_type' => 'Venta',
            'lead_delegation' => 'Madrid General',
        ]))->assertOk()->assertJsonPath('kpis.leads_totales', 998);
        $this->assertFalse((bool) array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'salesforce_interest_operational_rows')
            || str_contains($sql, 'salesforce_interest_monthly_recovery_rows')));
        $viewer = $this->user(ReportUser::ROLE_VIEWER);
        $this->asReportUser($viewer)->getJson('/informes/leads/data/resumen?period=november_2026')->assertForbidden();
    }

    public function test_incomplete_second_read_keeps_previous_active_version_and_preserves_checkpoint(): void
    {
        Carbon::setTestNow('2026-11-12 12:00:00 UTC');
        $fake = new OperationalInterestFakeClient($this->records(3));
        $this->app->instance(SalesforceClient::class, $fake);
        $sync = app(OperationalInterestMonthlySync::class);
        $first = $sync->prepare('2026-11');
        $sync->run($first);
        $original = app(OperationalInterestMonthlyReader::class)->latestForMonth(2026, 11);

        $second = $sync->prepare('2026-11');
        $fake->dropOnVerify = 2;
        try {
            $sync->run($second);
            $this->fail('Incomplete coverage must fail.');
        } catch (\RuntimeException) {
            $this->assertSame('failed', $sync->status($second)['status']);
        }
        $this->assertSame($original['projection_key'], app(OperationalInterestMonthlyReader::class)
            ->latestForMonth(2026, 11)['projection_key']);
        $this->assertSame(3, DB::table('salesforce_interest_operational_runs')->where('id', $second)->value('extracted_count'));
        $this->assertSame(0, DB::table('salesforce_interest_monthly_recoveries')->count());
    }

    public function test_failed_extraction_resumes_from_the_last_atomic_page_without_duplicates(): void
    {
        Carbon::setTestNow('2026-11-12 12:00:00 UTC');
        $fake = new OperationalInterestFakeClient($this->records(502));
        $fake->failOnQuery = 2;
        $this->app->instance(SalesforceClient::class, $fake);
        $sync = app(OperationalInterestMonthlySync::class);
        $runId = $sync->prepare('2026-11');
        try {
            $sync->run($runId);
            $this->fail('Synthetic interruption was expected.');
        } catch (\RuntimeException) {
            $this->assertSame('extract', $sync->status($runId)['phase']);
            $this->assertSame(500, $sync->status($runId)['extracted']);
        }
        $fake->failOnQuery = null;
        $this->assertSame('completed', $sync->run($runId)['status']);
        $this->assertSame(502, DB::table('salesforce_interest_operational_rows')->where('run_id', $runId)->count());
    }

    public function test_failed_older_run_cannot_resume_after_a_newer_cutoff_was_activated(): void
    {
        Carbon::setTestNow('2026-11-12 12:00:00 UTC');
        $fake = new OperationalInterestFakeClient($this->records(502));
        $fake->failOnQuery = 2;
        $this->app->instance(SalesforceClient::class, $fake);
        $sync = app(OperationalInterestMonthlySync::class);
        $older = $sync->prepare('2026-11');
        try {
            $sync->run($older);
            $this->fail('The first run should stop after a confirmed page.');
        } catch (\RuntimeException) {
            $this->assertSame(500, $sync->status($older)['extracted']);
        }

        Carbon::setTestNow('2026-11-12 12:02:00 UTC');
        $fake->failOnQuery = null;
        $newer = $sync->prepare('2026-11');
        $this->assertSame('completed', $sync->run($newer)['status']);
        $queriesBeforeResume = $fake->queryCount();
        $activeProjection = DB::table('salesforce_interest_operational_months')
            ->where('month_key', '2026-11')->value('active_projection_key');

        try {
            $sync->run($older);
            $this->fail('The older run must be rejected before reading Salesforce.');
        } catch (\RuntimeException) {
            $this->assertSame('superseded', $sync->status($older)['error_stage']);
        }
        $this->assertSame($queriesBeforeResume, $fake->queryCount());
        $this->assertSame(500, $sync->status($older)['extracted']);
        $this->assertSame('extract', $sync->status($older)['phase']);
        $this->assertSame($newer, DB::table('salesforce_interest_operational_months')
            ->where('month_key', '2026-11')->value('active_run_id'));
        $this->assertSame($activeProjection, DB::table('salesforce_interest_operational_months')
            ->where('month_key', '2026-11')->value('active_projection_key'));
        $this->assertSame(500, DB::table('salesforce_interest_operational_rows')->where('run_id', $older)->count());

        Carbon::setTestNow('2026-11-12 12:00:00 UTC');
        $this->expectException(\RuntimeException::class);
        $sync->prepare('2026-11');
    }

    public function test_equal_cutoff_uses_run_id_to_prevent_an_older_run_from_replacing_the_active_one(): void
    {
        Carbon::setTestNow('2026-11-12 12:00:00 UTC');
        $fake = new OperationalInterestFakeClient($this->records(1));
        $fake->failOnQuery = 1;
        $this->app->instance(SalesforceClient::class, $fake);
        $sync = app(OperationalInterestMonthlySync::class);
        $older = $sync->prepare('2026-11');
        try {
            $sync->run($older);
            $this->fail('Synthetic failure was expected.');
        } catch (\RuntimeException) {
            $this->assertSame('failed', $sync->status($older)['status']);
        }
        $fake->failOnQuery = null;
        $newer = $sync->prepare('2026-11');
        $this->assertSame(DB::table('salesforce_interest_operational_runs')->where('id', $older)->value('effective_cutoff_at'),
            DB::table('salesforce_interest_operational_runs')->where('id', $newer)->value('effective_cutoff_at'));
        $sync->run($newer);
        $queries = $fake->queryCount();
        $this->expectException(\RuntimeException::class);
        try {
            $sync->run($older);
        } finally {
            $this->assertSame($queries, $fake->queryCount());
            $this->assertSame('superseded', $sync->status($older)['error_stage']);
            $this->assertSame($newer, DB::table('salesforce_interest_operational_months')
                ->where('month_key', '2026-11')->value('active_run_id'));
        }
    }

    public function test_activation_rechecks_the_active_version_under_the_month_lock(): void
    {
        Carbon::setTestNow('2026-11-12 12:00:00 UTC');
        $fake = new OperationalInterestFakeClient($this->records(1));
        $fake->failOnQuery = 1;
        $this->app->instance(SalesforceClient::class, $fake);
        $sync = app(OperationalInterestMonthlySync::class);
        $older = $sync->prepare('2026-11');
        try {
            $sync->run($older);
        } catch (\RuntimeException) {
            // Keep the failed candidate for the activation race simulation.
        }
        $fake->failOnQuery = null;
        $newer = $sync->prepare('2026-11');
        $sync->run($newer);

        $lease = (string) Str::uuid();
        $projectionKey = (string) Str::uuid();
        DB::table('salesforce_interest_operational_runs')->where('id', $older)
            ->update(['status' => 'running', 'phase' => 'activate', 'projection_key' => $projectionKey]);
        DB::table('salesforce_interest_operational_months')->where('month_key', '2026-11')
            ->update(['lease_token' => $lease, 'heartbeat_at' => now('UTC')]);
        $candidate = DB::table('salesforce_interest_operational_runs')->find($older);
        $this->expectException(\RuntimeException::class);
        try {
            (new \ReflectionMethod(OperationalInterestMonthlySync::class, 'activate'))->invoke($sync, $candidate, $lease);
        } finally {
            $this->assertSame($newer, DB::table('salesforce_interest_operational_months')
                ->where('month_key', '2026-11')->value('active_run_id'));
            $this->assertSame('completed', DB::table('salesforce_interest_operational_runs')
                ->where('id', $newer)->value('status'));
        }
    }

    public function test_live_lease_prevents_second_worker_and_revoked_lease_prevents_publication(): void
    {
        Carbon::setTestNow('2026-11-12 12:00:00 UTC');
        $fake = new OperationalInterestFakeClient($this->records(1));
        $fake->revokeOnQuery = true;
        $this->app->instance(SalesforceClient::class, $fake);
        $sync = app(OperationalInterestMonthlySync::class);
        $runId = $sync->prepare('2026-11');
        try {
            $sync->run($runId);
            $this->fail('Revoked worker should stop.');
        } catch (\RuntimeException) {
            $this->assertNull(DB::table('salesforce_interest_operational_months')->where('month_key', '2026-11')->value('active_run_id'));
            $this->assertDatabaseCount('salesforce_interest_operational_rows', 0);
        }
        $this->expectException(ProvisionalInterestSourceUnavailable::class);
        app(OperationalInterestMonthlyReader::class)->latestForMonth(2026, 11);
    }

    public function test_a_second_worker_cannot_claim_a_live_month(): void
    {
        Carbon::setTestNow('2026-11-12 12:00:00 UTC');
        $fake = new OperationalInterestFakeClient($this->records(1));
        $this->app->instance(SalesforceClient::class, $fake);
        $sync = app(OperationalInterestMonthlySync::class);
        $run = $sync->prepare('2026-11');
        $rejected = false;
        $fake->onQuery = function () use ($sync, $run, &$rejected): void {
            try {
                $sync->run($run);
            } catch (\RuntimeException) {
                $rejected = true;
            }
        };
        $this->assertSame('completed', $sync->run($run)['status']);
        $this->assertTrue($rejected);
        $this->assertSame(1, DB::table('salesforce_interest_operational_runs')->count());
    }

    public function test_invalid_active_pointer_fails_closed_for_explicit_and_default_november(): void
    {
        Carbon::setTestNow('2026-11-12 12:00:00 UTC');
        $this->app->instance(SalesforceClient::class, new OperationalInterestFakeClient($this->records(1)));
        $sync = app(OperationalInterestMonthlySync::class);
        $sync->run($sync->prepare('2026-11'));
        DB::table('salesforce_interest_operational_months')->where('month_key', '2026-11')
            ->update(['active_projection_key' => '00000000-0000-0000-0000-000000000000']);
        config()->set('services.informes_auth.enabled', true);
        config()->set('salesforce.november_2026_provisional_enabled', true);
        $admin = $this->user(ReportUser::ROLE_ADMIN);
        $this->asReportUser($admin)->get('/informes/leads?period=november_2026')->assertStatus(503);
        $this->asReportUser($admin)->get('/informes/leads')->assertStatus(503);
    }

    public function test_mapping_quarantine_is_counted_and_a_later_run_can_reincorporate_the_record(): void
    {
        Carbon::setTestNow('2026-11-12 12:00:00 UTC');
        $records = $this->records(3);
        $records[1]['LastModifiedDate'] = 'invalid';
        $this->app->instance(SalesforceClient::class, new OperationalInterestFakeClient($records));
        $sync = app(OperationalInterestMonthlySync::class);
        $first = $sync->prepare('2026-11');
        $firstStatus = $sync->run($first);
        $this->assertSame(3, $firstStatus['extracted']);
        $this->assertSame(1, $firstStatus['quarantined']);
        $this->assertSame(2, app(OperationalInterestMonthlyReader::class)
            ->latestForMonth(2026, 11)['overview']['coverage']['eligible_active_matching']);

        $this->app->instance(SalesforceClient::class, new OperationalInterestFakeClient($this->records(3)));
        $sync = app(OperationalInterestMonthlySync::class);
        $second = $sync->prepare('2026-11');
        $this->assertSame(0, $sync->run($second)['quarantined']);
        $this->assertSame(3, app(OperationalInterestMonthlyReader::class)
            ->latestForMonth(2026, 11)['overview']['coverage']['eligible_active_matching']);
        $this->assertDatabaseCount('salesforce_interest_operational_incidents', 1);
        $this->assertDatabaseCount('salesforce_interest_monthly_recoveries', 0);
    }

    public function test_difference_categories_are_value_free_and_distinguish_stamps_owner_and_relationships(): void
    {
        Carbon::setTestNow('2026-11-12 12:00:00 UTC');
        $fake = new OperationalInterestFakeClient($this->records(3));
        $fake->verifyOverrides = [
            1 => ['SystemModstamp' => '2026-11-06T10:00:00Z'],
            2 => ['Owner' => ['Name' => 'Synthetic owner']],
            3 => ['IN_Lead__c' => '00Q000000000000001'],
        ];
        $this->app->instance(SalesforceClient::class, $fake);
        $sync = app(OperationalInterestMonthlySync::class);
        $run = $sync->prepare('2026-11');
        $sync->run($run);
        $categories = app(OperationalInterestMonthlyReader::class)
            ->latestForMonth(2026, 11)['overview']['coverage']['change_categories'];
        $this->assertSame(1, $categories['system_modstamp']);
        $this->assertSame(1, $categories['owner_name']);
        $this->assertSame(1, $categories['relationships']);
        $this->assertStringNotContainsString('Synthetic owner', (string) DB::table('salesforce_interest_operational_runs')->where('id', $run)->value('overview'));
        $this->assertDatabaseMissing('salesforce_interest_operational_rows', ['owner_name_ciphertext' => 'Synthetic owner']);
    }

    public function test_soft_delete_and_reactivation_are_observed_without_erasing_prior_version(): void
    {
        Carbon::setTestNow('2026-11-12 12:00:00 UTC');
        $records = $this->records(2);
        $records[1]['IsDeleted'] = true;
        $this->app->instance(SalesforceClient::class, new OperationalInterestFakeClient($records));
        $sync = app(OperationalInterestMonthlySync::class);
        $first = $sync->prepare('2026-11');
        $sync->run($first);
        $firstSnapshot = app(OperationalInterestMonthlyReader::class)->latestForMonth(2026, 11);
        $this->assertSame(1, $firstSnapshot['overview']['coverage']['eligible_active_matching']);
        $this->assertSame(1, $firstSnapshot['overview']['coverage']['soft_deleted_observed']);

        $this->app->instance(SalesforceClient::class, new OperationalInterestFakeClient($this->records(2)));
        $sync = app(OperationalInterestMonthlySync::class);
        $sync->run($sync->prepare('2026-11'));
        $this->assertSame(2, app(OperationalInterestMonthlyReader::class)
            ->latestForMonth(2026, 11)['overview']['coverage']['eligible_active_matching']);
        $this->assertSame(1, DB::table('salesforce_interest_operational_rows')->where('run_id', $first)->where('is_deleted', true)->count());
        try {
            app(OperationalInterestMonthlyReader::class)->assertCurrent('2026-11', $first, $firstSnapshot['projection_key']);
            $this->fail('An older dashboard projection cannot pass the final pointer check.');
        } catch (ProvisionalInterestSourceUnavailable) {
            $this->assertTrue(true);
        }
    }

    public function test_nine_thousand_three_hundred_thirty_rows_with_four_changes_remain_bounded(): void
    {
        Carbon::setTestNow('2026-11-12 12:00:00 UTC');
        $fake = new OperationalInterestFakeClient($this->records(9330));
        $fake->mutateOnVerify = [1, 500, 5001, 9330];
        $this->app->instance(SalesforceClient::class, $fake);
        $sync = app(OperationalInterestMonthlySync::class);
        $run = $sync->prepare('2026-11');
        $result = $sync->run($run);
        $this->assertSame(9330, $result['extracted']);
        $this->assertSame(9330, $result['checked']);
        $this->assertSame(4, $result['changed']);
        $this->assertSame(0, $result['quarantined']);
        $this->assertLessThanOrEqual(40, $fake->queryCount());
        $this->assertSame(9330, app(OperationalInterestMonthlyReader::class)
            ->latestForMonth(2026, 11)['overview']['coverage']['eligible_active_matching']);
    }

    public function test_november_quality_reconciles_observed_active_and_unclassified_without_guessing_delegations(): void
    {
        Carbon::setTestNow('2026-11-12 12:00:00 UTC');
        $records = $this->records(9678);
        foreach ($records as $index => &$record) {
            if ($index >= 9671) {
                $record['IsDeleted'] = true;

                continue;
            }
            $record['IN_Estado__c'] = $index < 1258 ? 'Convertido' : ($index < 5496 ? 'Descartado' : 'Potencial');
            $record['IN_Delegacion_Procedencia__c'] = match (true) {
                $index < 2000 => null,
                $index < 3394 => 'General',
                $index < 4431 => 'Synthetic unknown',
                default => 'Madrid',
            };
        }
        unset($record);
        $fake = new OperationalInterestFakeClient($records);
        $fake->verifyOverrides = [
            5494 => ['SystemModstamp' => '2026-11-06T10:00:00Z'],
            5495 => ['SystemModstamp' => '2026-11-06T10:00:00Z'],
            5496 => ['SystemModstamp' => '2026-11-06T10:00:00Z'],
        ];
        $this->app->instance(SalesforceClient::class, $fake);
        $sync = app(OperationalInterestMonthlySync::class);
        $run = $sync->prepare('2026-11');
        $this->assertSame('completed', $sync->run($run)['status']);
        $quality = app(OperationalInterestQualitySnapshot::class)->rebuildActive('2026-11');

        $this->assertSame(['total' => 9671, 'blank' => 2000, 'recognized' => 5240,
            'general' => 1394, 'unknown_other' => 1037,
            'recognizable_without_alias' => null, 'relationships_insufficient' => null], $quality);
        $overview = app(OperationalInterestMonthlyReader::class)->latestForMonth(2026, 11)['overview'];
        $this->assertSame(9678, $overview['coverage']['observed']);
        $this->assertSame(9678, $overview['coverage']['admitted']);
        $this->assertSame(9671, $overview['coverage']['eligible_active_matching']);
        $this->assertSame(7, $overview['coverage']['soft_deleted_observed']);
        $this->assertSame(3, $overview['coverage']['changed_between_reads']);
        $this->assertSame($quality, $overview['coverage']['delegation_quality']);
        $this->assertSame(9671, array_sum(array_column($overview['functional_kpis']['statuses'], 'interests')));
        $this->assertSame(['Convertido' => 1258, 'Descartado' => 4238, 'Potencial' => 4175],
            collect($overview['functional_kpis']['statuses'])->pluck('interests', 'status')->all());
        $stored = (string) DB::table('salesforce_interest_operational_quality')->where('run_id', $run)
            ->value('delegation_quality');
        $this->assertStringNotContainsString('Synthetic unknown', $stored);
        $this->assertStringNotContainsString('a01', $stored);
        (require database_path('migrations/2026_11_17_120000_add_operational_interest_quality_snapshots.php'))->up();
    }

    public function test_operator_command_prepares_without_salesforce_and_requires_explicit_read_permission(): void
    {
        Carbon::setTestNow('2026-11-12 12:00:00 UTC');
        $this->artisan('salesforce:sync-interests-operational', ['action' => 'prepare', '--month' => '2026-11'])
            ->assertExitCode(0);
        $runId = (int) DB::table('salesforce_interest_operational_runs')->value('id');
        $this->artisan('salesforce:sync-interests-operational', ['action' => 'run', '--run-id' => $runId])
            ->assertExitCode(1);
        $this->assertSame('prepared', DB::table('salesforce_interest_operational_runs')->where('id', $runId)->value('status'));
    }

    public function test_excessive_salesforce_continuation_pages_abort_without_checkpoint_advance(): void
    {
        Carbon::setTestNow('2026-11-12 12:00:00 UTC');
        $fake = new OperationalInterestFakeClient($this->records(10));
        $fake->fragmentSize = 1;
        $this->app->instance(SalesforceClient::class, $fake);
        $sync = app(OperationalInterestMonthlySync::class);
        $run = $sync->prepare('2026-11');
        try {
            $sync->run($run);
            $this->fail('Remote continuation budget should abort.');
        } catch (\RuntimeException) {
            $this->assertSame('failed', $sync->status($run)['status']);
            $this->assertSame(0, $sync->status($run)['extracted']);
            $this->assertDatabaseCount('salesforce_interest_operational_rows', 0);
        }
    }

    public function test_nine_complete_salesforce_continuation_pages_are_accepted(): void
    {
        Carbon::setTestNow('2026-11-12 12:00:00 UTC');
        $fake = new OperationalInterestFakeClient($this->records(9));
        $fake->fragmentSize = 1;
        $this->app->instance(SalesforceClient::class, $fake);
        $sync = app(OperationalInterestMonthlySync::class);
        $run = $sync->prepare('2026-11');
        $this->assertSame('completed', $sync->run($run)['status']);
        $this->assertSame(9, DB::table('salesforce_interest_operational_rows')->where('run_id', $run)->count());
    }

    public function test_query_budget_survives_failure_and_resume(): void
    {
        Carbon::setTestNow('2026-11-12 12:00:00 UTC');
        $fake = new OperationalInterestFakeClient($this->records(1));
        $fake->failOnQuery = 1;
        $this->app->instance(SalesforceClient::class, $fake);
        $sync = app(OperationalInterestMonthlySync::class);
        $run = $sync->prepare('2026-11');
        DB::table('salesforce_interest_operational_runs')->where('id', $run)->update(['query_attempts' => 299]);
        try {
            $sync->run($run);
            $this->fail('Synthetic remote failure expected.');
        } catch (\RuntimeException) {
            $this->assertSame(300, $sync->status($run)['query_attempts']);
        }
        try {
            $sync->run($run);
            $this->fail('Exhausted budget must block another Salesforce call.');
        } catch (\RuntimeException) {
            $this->assertSame(1, $fake->queryCount());
            $this->assertSame(300, $sync->status($run)['query_attempts']);
        }
    }

    public function test_historical_month_boundaries_use_madrid_time_and_migration_is_repeatable(): void
    {
        Carbon::setTestNow('2026-11-12 12:00:00 UTC');
        $migration = require database_path('migrations/2026_11_16_120000_create_operational_interest_months.php');
        $migration->up();
        $sync = app(OperationalInterestMonthlySync::class);
        $run = $sync->prepare('2026-10');
        $period = DB::table('salesforce_interest_operational_runs')->where('id', $run)->first();
        $this->assertSame('2026-09-30 22:00:00', $period->functional_start_at);
        $this->assertSame('2026-10-31 23:00:00', $period->functional_end_at);
        $this->assertTrue(Schema::hasTable('salesforce_interest_operational_incidents'));
    }

    /** @return list<array<string,mixed>> */
    private function records(int $count): array
    {
        $rows = [];
        for ($n = 1; $n <= $count; $n++) {
            $rows[] = [
                'Id' => sprintf('a01%015d', $n), 'CreatedDate' => '2026-11-05T10:00:00Z',
                'LastModifiedDate' => '2026-11-05T10:00:00Z', 'SystemModstamp' => '2026-11-05T10:00:00Z',
                'IN_Fecha_Creacion_Origen__c' => null, 'IsDeleted' => false,
                'IN_Estado__c' => 'Potencial', 'IN_Tipo__c' => 'Venta',
                'IN_Delegacion_Procedencia__c' => 'Madrid',
                'IN_Fuente_Origen__c' => 'Portal', 'IN_Canal__c' => 'Formulario',
            ];
        }

        return $rows;
    }

    private function user(string $role): ReportUser
    {
        return ReportUser::create(['name' => 'Synthetic', 'email' => uniqid('', true).'@example.test',
            'password' => 'synthetic-password', 'role' => $role, 'is_active' => true]);
    }

    private function asReportUser(ReportUser $user): static
    {
        return $this->withSession(['informes_authenticated' => true, 'report_user_id' => $user->id,
            'report_user_role' => $user->role, 'report_user_email' => $user->email]);
    }
}

/** Salesforce responses are bounded and can evolve between extraction and verification. */
final class OperationalInterestFakeClient extends SalesforceClient
{
    public array $mutateOnVerify = [];

    public array $verifyOverrides = [];

    public ?int $dropOnVerify = null;

    public ?int $failOnQuery = null;

    public bool $revokeOnQuery = false;

    public ?int $fragmentSize = null;

    public ?\Closure $onQuery = null;

    private int $query = 0;

    private int $pass = 0;

    public function __construct(private readonly array $records) {}

    public function queryCount(): int
    {
        return $this->query;
    }

    public function queryPages(string $soql, bool $includeDeleted = false): \Generator
    {
        if (! $includeDeleted || ! str_contains($soql, 'FROM Interes__c') || ! str_contains($soql, 'LIMIT 500')) {
            throw new \RuntimeException('Operational query contract changed.');
        }
        $this->query++;
        if ($this->failOnQuery === $this->query) {
            throw new \RuntimeException('Synthetic remote failure with a private value.');
        }
        if ($this->onQuery !== null) {
            $callback = $this->onQuery;
            $this->onQuery = null;
            $callback();
        }
        if ($this->revokeOnQuery) {
            DB::table('salesforce_interest_operational_months')->where('month_key', '2026-11')
                ->update(['lease_token' => '00000000-0000-0000-0000-000000000000']);
        }
        $after = preg_match("/Id > '([^']+)'/", $soql, $matches) === 1 ? $matches[1] : null;
        if ($after === null) {
            $this->pass++;
        }
        $rows = array_values(array_filter($this->records, function (array $record) use ($after): bool {
            $n = (int) substr($record['Id'], 3);

            return ($after === null || strcmp($record['Id'], $after) > 0)
                && ! ($this->pass % 2 === 0 && $this->dropOnVerify === $n);
        }));
        $rows = array_slice($rows, 0, 500);
        if ($this->pass % 2 === 0) {
            foreach ($rows as &$row) {
                if (in_array((int) substr($row['Id'], 3), $this->mutateOnVerify, true)) {
                    $row['IN_Estado__c'] = 'Convertido';
                }
                $row = array_replace($row, $this->verifyOverrides[(int) substr($row['Id'], 3)] ?? []);
            }
            unset($row);
        }
        if ($this->fragmentSize !== null) {
            foreach (array_chunk($rows, $this->fragmentSize) as $page) {
                yield $page;
            }

            return;
        }
        yield $rows;
    }
}
