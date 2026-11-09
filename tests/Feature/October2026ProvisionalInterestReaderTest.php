<?php

namespace Tests\Feature;

use App\Models\ReportUser;
use App\Services\Reports\Leads\LeadDelegationNormalizer;
use App\Services\Reports\Leads\October2026ProvisionalEvidenceInvalidator;
use App\Services\Reports\Leads\October2026ProvisionalInterestReader;
use App\Services\Reports\Leads\ProvisionalInterestSourceUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class October2026ProvisionalInterestReaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reads_only_the_fixed_validated_october_journals_without_exposing_row_identifiers(): void
    {
        $this->seedValidJournals();
        $this->assertNotSame($this->materializationSourceManifest(), $this->activitySourceManifest());
        app(October2026ProvisionalInterestReader::class)->validate();
        $overview = app(October2026ProvisionalInterestReader::class)->overview();

        $this->assertSame(2, $overview['coverage']['f2_inventory']);
        $this->assertSame(2, $overview['coverage']['materialized']);
        $this->assertSame(1, $overview['coverage']['eligible_active_matching']);
        $this->assertSame(1, $overview['coverage']['content_changed']);
        $this->assertSame(1, $overview['coverage']['canonical_identity_unavailable']);
        $this->assertSame(1, $overview['classification']['total_included']);
        $this->assertSame(1, $overview['classification']['classified']);
        $this->assertSame(0, $overview['classification']['unclassified']);
        $this->assertSame([['delegation' => 'Madrid General', 'interests' => 1]], $overview['by_delegation']);
        $this->assertSame(1, collect($overview['classification']['by_group'])->sum('interests'));
        $this->assertSame(1, collect($overview['classification']['by_zone'])->sum('interests'));
        $this->assertSame('available_with_coverage_limits', $overview['availability']['leads_interest_overview']);
        $this->assertSame('unavailable', $overview['availability']['campaigns_calls_and_seo']);
        $this->assertArrayNotHasKey('salesforce_id', $overview);
    }

    public function test_it_uses_the_official_delegation_normalizer_without_inferencing_a_commercial_delegation(): void
    {
        $normalizer = app(LeadDelegationNormalizer::class);
        $expected = [
            'HR MOTOR TORREJON' => ['Torrejón', 'Grupo Madrid', 'Zona Sur y Centro'],
            'Sant Boi de Llobregat' => ['Sant Boi', 'Grupo Barcelona', 'Zona Cataluña'],
            'Valencia Paterna' => ['Paterna', 'Grupo Valencia', 'Zona Mediterraneo'],
            'Sevilla-Alcalá de Guadaira-HR Motor' => ['Alcalá de Guadaira', 'Grupo Sevilla', 'Zona Sur y Centro'],
            'Malaga Centro' => ['Málaga Centro', 'Grupo Málaga', 'Zona Sur y Centro'],
            'Zona Navarra' => ['Navarra General', 'Grupo Navarra', 'Zona Norte'],
            'Castellon general' => ['Castellón General', 'Grupo Castellón', 'Zona Mediterraneo'],
            'HR MOTOR BILBAO' => ['Bilbao', 'Grupo País Vasco', 'Zona Norte'],
        ];
        foreach ($expected as $raw => [$delegation, $group, $zone]) {
            $result = $normalizer->normalize($raw);
            $this->assertSame($delegation, $result['delegation']);
            $this->assertSame($group, $result['group']);
            $this->assertSame($zone, $result['zone']);
            $this->assertTrue($result['is_classified']);
        }

        $this->seedValidJournals();
        DB::table('salesforce_interest_monthly_materialized_rows')
            ->where('monthly_materialization_id', 1)
            ->where('salesforce_id', $this->id(1))
            ->update(['origin_delegation' => 'not an official delegation']);

        $overview = app(October2026ProvisionalInterestReader::class)->validate();

        $this->assertSame(0, $overview['classification']['classified']);
        $this->assertSame(1, $overview['classification']['raw_unmapped']);
        $this->assertSame(0, $overview['classification']['raw_literal_general']);
        $this->assertSame('Sin clasificar', $overview['by_delegation'][0]['delegation']);
        $stored = (string) DB::table('salesforce_interest_october_provisional_validations')->where('scope', 'october_2026_provisional')->value('overview');
        $this->assertStringNotContainsString('not an official delegation', $stored);
    }

    public function test_it_keeps_the_literal_general_within_the_unrecognized_origin_audit(): void
    {
        $this->seedValidJournals();
        DB::table('salesforce_interest_monthly_materialized_rows')
            ->where('monthly_materialization_id', 1)
            ->where('salesforce_id', $this->id(1))
            ->update(['origin_delegation' => 'General']);

        $overview = app(October2026ProvisionalInterestReader::class)->validate();

        $this->assertSame(1, $overview['classification']['total_included']);
        $this->assertSame(0, $overview['classification']['classified']);
        $this->assertSame(1, $overview['classification']['raw_unmapped']);
        $this->assertSame(1, $overview['classification']['raw_literal_general']);
        $this->assertSame(1, $overview['classification']['data_quality_review']['literal_general']);
        $this->assertSame('Sin clasificar', $overview['by_delegation'][0]['delegation']);
    }

    public function test_it_distinguishes_an_empty_origin_from_an_unmapped_value_without_reclassifying_it(): void
    {
        $this->seedValidJournals();
        DB::table('salesforce_interest_monthly_materialized_rows')
            ->where('monthly_materialization_id', 1)
            ->where('salesforce_id', $this->id(1))
            ->update(['origin_delegation' => '   ']);

        $overview = app(October2026ProvisionalInterestReader::class)->validate();

        $this->assertSame(1, $overview['classification']['unclassified']);
        $this->assertSame(1, $overview['classification']['raw_empty']);
        $this->assertSame(0, $overview['classification']['raw_unmapped']);
        $this->assertSame(1, $overview['classification']['data_quality_review']['required']);
    }

    public function test_it_rejects_pending_validation_and_invalidates_a_same_count_content_change(): void
    {
        $this->seedValidJournals();
        $reader = app(October2026ProvisionalInterestReader::class);
        $this->expectException(ProvisionalInterestSourceUnavailable::class);
        $reader->overview();
    }

    public function test_a_validated_http_read_does_not_scan_the_three_row_journals(): void
    {
        $this->seedValidJournals();
        $reader = app(October2026ProvisionalInterestReader::class);
        $reader->validate();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $reader->overview();
        $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");

        $this->assertStringNotContainsString('salesforce_interest_monthly_recovery_rows', $queries);
        $this->assertStringNotContainsString('salesforce_interest_monthly_materialized_rows', $queries);
        $this->assertStringNotContainsString('salesforce_interest_monthly_activity_rows', $queries);
        $this->assertStringContainsString('salesforce_interest_october_provisional_validations', $queries);
    }

    public function test_a_content_change_without_a_row_count_change_invalidates_evidence(): void
    {
        $this->seedValidJournals();
        $reader = app(October2026ProvisionalInterestReader::class);
        $reader->validate();
        DB::transaction(function (): void {
            DB::table('salesforce_interest_monthly_materialized_rows')->where('monthly_materialization_id', 1)->where('salesforce_id', $this->id(1))->update(['content_hash' => str_repeat('f', 64)]);
            app(October2026ProvisionalEvidenceInvalidator::class)->afterContentMutation(1);
        });

        $this->expectException(ProvisionalInterestSourceUnavailable::class);
        $reader->overview();
    }

    public function test_a_rolled_back_authorised_content_change_preserves_the_current_evidence(): void
    {
        $this->seedValidJournals();
        $reader = app(October2026ProvisionalInterestReader::class);
        $reader->validate();
        $revision = (int) DB::table('salesforce_interest_monthly_integrity_revisions')->where('scope', 'october_2026_provisional')->value('revision');

        try {
            DB::transaction(function (): void {
                DB::table('salesforce_interest_monthly_materialized_rows')->where('monthly_materialization_id', 1)->where('salesforce_id', $this->id(1))->update(['content_hash' => str_repeat('f', 64)]);
                app(October2026ProvisionalEvidenceInvalidator::class)->afterContentMutation(1);
                throw new \RuntimeException('synthetic rollback');
            });
        } catch (\RuntimeException) {
            // Intentional: both the content mutation and its invalidation roll back.
        }

        $this->assertSame($revision, (int) DB::table('salesforce_interest_monthly_integrity_revisions')->where('scope', 'october_2026_provisional')->value('revision'));
        $this->assertSame(str_repeat('a', 64), DB::table('salesforce_interest_monthly_materialized_rows')->where('monthly_materialization_id', 1)->where('salesforce_id', $this->id(1))->value('content_hash'));
        $this->assertSame(2, $reader->overview()['coverage']['materialized']);
    }

    public function test_operational_metadata_does_not_invalidate_content_evidence(): void
    {
        $this->seedValidJournals();
        $reader = app(October2026ProvisionalInterestReader::class);
        $reader->validate();
        $revision = (int) DB::table('salesforce_interest_monthly_integrity_revisions')->where('scope', 'october_2026_provisional')->value('revision');

        DB::table('salesforce_interest_monthly_recoveries')->where('id', 1)->update(['heartbeat_at' => now(), 'updated_at' => now()]);

        $this->assertSame($revision, (int) DB::table('salesforce_interest_monthly_integrity_revisions')->where('scope', 'october_2026_provisional')->value('revision'));
        $this->assertSame(2, $reader->overview()['coverage']['f2_inventory']);
    }

    public function test_an_explicit_operational_revocation_fails_closed_without_scanning_the_journals(): void
    {
        $this->seedValidJournals();
        $reader = app(October2026ProvisionalInterestReader::class);
        $reader->validate();
        app(October2026ProvisionalEvidenceInvalidator::class)->revoke();

        $this->expectException(ProvisionalInterestSourceUnavailable::class);
        $reader->overview();
    }

    public function test_a_content_mutation_concurrent_with_validation_cannot_publish_stale_evidence(): void
    {
        $this->seedValidJournals();
        $reader = app(October2026ProvisionalInterestReader::class);
        $reader->validate();
        $previousRevision = (int) DB::table('salesforce_interest_october_provisional_validations')->where('scope', 'october_2026_provisional')->value('integrity_revision');

        $interrupted = new October2026ProvisionalInterestReader(
            beforeValidationPublication: fn () => app(October2026ProvisionalEvidenceInvalidator::class)->revoke(),
        );

        try {
            $interrupted->validate();
            $this->fail('Concurrent invalidation must prevent publication.');
        } catch (ProvisionalInterestSourceUnavailable) {
            $this->assertSame($previousRevision, (int) DB::table('salesforce_interest_october_provisional_validations')->where('scope', 'october_2026_provisional')->value('integrity_revision'));
        }

        $this->expectException(ProvisionalInterestSourceUnavailable::class);
        $reader->overview();
    }

    public function test_http_rechecks_the_revision_before_returning_a_cached_overview(): void
    {
        $this->seedValidJournals();
        app(October2026ProvisionalInterestReader::class)->validate();
        $reader = new October2026ProvisionalInterestReader(
            beforeOverviewFinalCheck: fn () => app(October2026ProvisionalEvidenceInvalidator::class)->revoke(),
        );

        $this->expectException(ProvisionalInterestSourceUnavailable::class);
        $reader->overview();
    }

    public function test_it_rejects_a_f5_source_snapshot_that_does_not_match_its_own_contract(): void
    {
        $this->seedValidJournals();
        app(October2026ProvisionalInterestReader::class)->validate();
        $this->authorisedChange(fn () => DB::table('salesforce_interest_monthly_activity_recoveries')->where('id', 1)->update([
            'source_evidence' => json_encode(['source_rows' => 2, 'source_sha256' => str_repeat('f', 64)]),
        ]));

        $this->expectException(ProvisionalInterestSourceUnavailable::class);
        app(October2026ProvisionalInterestReader::class)->overview();
    }

    public function test_it_fails_closed_when_a_materialization_source_snapshot_no_longer_matches_f2(): void
    {
        $this->seedValidJournals();
        app(October2026ProvisionalInterestReader::class)->validate();
        $this->authorisedChange(fn () => DB::table('salesforce_interest_monthly_recovery_rows')->where('monthly_recovery_id', 1)->where('salesforce_id', $this->id(1))->update(['content_hash' => str_repeat('f', 64)]));

        $this->expectException(ProvisionalInterestSourceUnavailable::class);
        app(October2026ProvisionalInterestReader::class)->overview();
    }

    public function test_it_fails_closed_when_the_fixed_journal_is_not_the_complete_madrid_october_period(): void
    {
        $this->seedValidJournals();
        app(October2026ProvisionalInterestReader::class)->validate();
        $this->authorisedChange(fn () => DB::table('salesforce_interest_monthly_recoveries')->where('id', 1)->update(['functional_end_at' => '2026-11-01 00:00:00']));

        $this->expectException(ProvisionalInterestSourceUnavailable::class);
        app(October2026ProvisionalInterestReader::class)->overview();
    }

    public function test_the_admin_only_screen_is_disabled_by_default_and_fails_closed_when_evidence_is_incomplete(): void
    {
        config()->set('services.informes_auth.enabled', true);
        $this->seedValidJournals();
        app(October2026ProvisionalInterestReader::class)->validate();
        $admin = $this->user(ReportUser::ROLE_ADMIN);
        $viewer = $this->user(ReportUser::ROLE_VIEWER);

        $this->asReportUser($admin)->get('/informes/leads/provisional/october-2026')->assertNotFound();
        config()->set('salesforce.october_2026_provisional_enabled', true);
        $this->asReportUser($viewer)->get('/informes/leads/provisional/october-2026')->assertForbidden();
        $this->asReportUser($admin)->get('/informes/leads/provisional/october-2026')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('inventario provisional no certificado')
            ->assertDontSee($this->id(1));

        $this->authorisedChange(fn () => DB::table('salesforce_interest_monthly_activity_recoveries')->where('id', 1)->update(['phase' => 'verify_task']));
        $this->asReportUser($admin)->get('/informes/leads/provisional/october-2026')->assertStatus(503);
    }

    public function test_representative_october_volume_is_validated_outside_http_and_http_reads_only_the_snapshot(): void
    {
        $this->seedRepresentativeJournals();
        $reader = app(October2026ProvisionalInterestReader::class);

        $this->assertDatabaseCount('salesforce_interest_monthly_recovery_rows', 34_239);
        $this->assertDatabaseCount('salesforce_interest_monthly_materialized_rows', 34_229);
        $this->assertDatabaseCount('salesforce_interest_monthly_activity_rows', 47_572);
        $reader->validate();
        $metrics = $reader->validationMetrics();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $startedAt = hrtime(true);
        $overview = $reader->overview();
        $httpDurationNs = hrtime(true) - $startedAt;
        $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");

        $this->assertSame(34_239, $overview['coverage']['f2_inventory']);
        $this->assertSame(34_229, $overview['coverage']['materialized']);
        $this->assertSame(10, $overview['coverage']['missing_from_materialization']);
        $this->assertArrayHasKey('total_ns', $metrics);
        $this->assertGreaterThan(0, $metrics['total_ns']);
        $this->assertGreaterThan(0, $httpDurationNs);
        $this->assertStringNotContainsString('salesforce_interest_monthly_recovery_rows', $queries);
        $this->assertStringNotContainsString('salesforce_interest_monthly_materialized_rows', $queries);
        $this->assertStringNotContainsString('salesforce_interest_monthly_activity_rows', $queries);
        $this->assertLessThanOrEqual(3, count(DB::getQueryLog()));
    }

    public function test_mariadb_can_reapply_the_additive_evidence_migration_without_row_triggers(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MariaDB-specific migration assertions run in the MariaDB CI job.');
        }

        $migration = require database_path('migrations/2026_11_11_120000_add_october_provisional_validation_evidence.php');
        $migration->up();
        $tables = [
            'salesforce_interest_monthly_recoveries',
            'salesforce_interest_monthly_recovery_rows',
            'salesforce_interest_monthly_materializations',
            'salesforce_interest_monthly_materialized_rows',
            'salesforce_interest_monthly_activity_recoveries',
            'salesforce_interest_monthly_activity_rows',
        ];
        $placeholders = implode(', ', array_fill(0, count($tables), '?'));
        $triggers = DB::select("SELECT trigger_name FROM information_schema.triggers WHERE trigger_schema = DATABASE() AND event_object_table IN ({$placeholders})", $tables);

        $this->assertDatabaseCount('salesforce_interest_monthly_integrity_revisions', 1);
        $this->assertSame([], $triggers);
    }

    private function seedValidJournals(): void
    {
        $now = now();
        DB::table('salesforce_interest_monthly_recoveries')->insert([
            'id' => 1, 'recovery_identifier' => (string) Str::uuid(), 'functional_start_at' => '2026-09-30 22:00:00', 'functional_end_at' => '2026-10-31 23:00:00',
            'timezone' => 'Europe/Madrid', 'status' => 'completed', 'phase' => 'completed', 'batch_size' => 500, 'pages_completed' => 1,
            'counters' => json_encode(['persisted' => 2]), 'evidence' => json_encode(['verification' => ['verified' => false]]), 'reason_hash' => hash('sha256', 'synthetic october source'),
            'started_at' => $now, 'completed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $sourceRowIds = [];
        foreach ([[1, str_repeat('a', 64), false], [2, str_repeat('b', 64), true]] as [$number, $hash, $deleted]) {
            $sourceRowIds[$number] = DB::table('salesforce_interest_monthly_recovery_rows')->insertGetId([
                'monthly_recovery_id' => 1, 'salesforce_id' => $this->id($number), 'content_hash' => $hash, 'is_deleted' => $deleted,
                'functional_created_at' => '2026-10-02 08:00:00', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $materializationSourceManifest = $this->materializationSourceManifest();
        $activitySourceManifest = $this->activitySourceManifest();
        DB::table('salesforce_interest_monthly_materializations')->insert([
            'id' => 1, 'materialization_identifier' => (string) Str::uuid(), 'monthly_recovery_id' => 1, 'status' => 'completed', 'phase' => 'completed', 'batch_size' => 500, 'pages_completed' => 1,
            'counters' => '{}', 'source_evidence' => json_encode(['source_rows' => 2, 'source_sha256' => $materializationSourceManifest]), 'reason_hash' => hash('sha256', 'synthetic materialization'),
            'started_at' => $now, 'completed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ([[1, str_repeat('a', 64), true, false, 'Madrid', $this->id(11)], [2, str_repeat('c', 64), false, true, null, null]] as [$number, $hash, $matching, $deleted, $delegation, $person]) {
            DB::table('salesforce_interest_monthly_materialized_rows')->insert([
                'monthly_materialization_id' => 1, 'monthly_recovery_row_id' => $sourceRowIds[$number], 'salesforce_id' => $this->id($number), 'source_content_hash' => $number === 1 ? str_repeat('a', 64) : str_repeat('b', 64), 'content_hash' => $hash,
                'content_matches_inventory' => $matching, 'is_deleted' => $deleted, 'salesforce_created_at' => '2026-10-02 08:00:00', 'salesforce_last_modified_at' => '2026-10-02 08:00:00', 'functional_created_at' => '2026-10-02 08:00:00',
                'origin_delegation' => $delegation, 'canonical_person_salesforce_id' => $person, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $materializationManifest = $this->materializationManifest();
        DB::table('salesforce_interest_monthly_materializations')->where('id', 1)->update(['evidence' => json_encode(['manifest' => ['rows' => 2, 'sha256' => $materializationManifest], 'verification' => ['verified' => false]])]);
        DB::table('salesforce_interest_monthly_activity_recoveries')->insert([
            'id' => 1, 'recovery_identifier' => (string) Str::uuid(), 'monthly_recovery_id' => 1, 'status' => 'completed', 'phase' => 'completed', 'batch_size' => 500, 'extraction_strategy' => 'selective_whatid_v1', 'pages_completed' => 1,
            'counters' => '{}', 'source_evidence' => json_encode(['source_rows' => 2, 'source_sha256' => $activitySourceManifest]), 'evidence' => json_encode(['manifest' => ['rows' => 0, 'sha256' => hash('sha256', '')], 'verification' => ['verified' => false]]),
            'reason_hash' => hash('sha256', 'synthetic f5'), 'started_at' => $now, 'completed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function seedRepresentativeJournals(): void
    {
        $now = now();
        $f2Rows = 34_239;
        $f2mRows = 34_229;
        $f5Rows = 47_572;
        DB::table('salesforce_interest_monthly_recoveries')->insert([
            'id' => 1, 'recovery_identifier' => (string) Str::uuid(), 'functional_start_at' => '2026-09-30 22:00:00', 'functional_end_at' => '2026-10-31 23:00:00',
            'timezone' => 'Europe/Madrid', 'status' => 'completed', 'phase' => 'completed', 'batch_size' => 500, 'pages_completed' => 69,
            'counters' => json_encode(['persisted' => $f2Rows]), 'evidence' => json_encode(['verification' => ['verified' => false]]), 'reason_hash' => hash('sha256', 'representative october source'),
            'started_at' => $now, 'completed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $f2Source = hash_init('sha256');
        $f5Source = hash_init('sha256');
        foreach (array_chunk(range(1, $f2Rows), 500) as $numbers) {
            $batch = [];
            foreach ($numbers as $number) {
                $id = $this->id($number);
                $hash = hash('sha256', 'source-'.$number);
                $batch[] = ['monthly_recovery_id' => 1, 'salesforce_id' => $id, 'content_hash' => $hash, 'is_deleted' => false, 'functional_created_at' => '2026-10-02 08:00:00', 'created_at' => $now, 'updated_at' => $now];
                hash_update($f2Source, $id.'|'.$hash."|0\n");
                hash_update($f5Source, $id.":0\n");
            }
            DB::table('salesforce_interest_monthly_recovery_rows')->insert($batch);
        }
        $sourceRowIds = DB::table('salesforce_interest_monthly_recovery_rows')
            ->where('monthly_recovery_id', 1)
            ->pluck('id', 'salesforce_id')
            ->all();
        DB::table('salesforce_interest_monthly_materializations')->insert([
            'id' => 1, 'materialization_identifier' => (string) Str::uuid(), 'monthly_recovery_id' => 1, 'status' => 'completed', 'phase' => 'completed', 'batch_size' => 500, 'pages_completed' => 69,
            'counters' => '{}', 'source_evidence' => json_encode(['source_rows' => $f2Rows, 'source_sha256' => hash_final($f2Source)]), 'reason_hash' => hash('sha256', 'representative materialization'),
            'started_at' => $now, 'completed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $f2mManifest = hash_init('sha256');
        foreach (array_chunk(range(1, $f2mRows), 500) as $numbers) {
            $batch = [];
            foreach ($numbers as $number) {
                $id = $this->id($number);
                $sourceHash = hash('sha256', 'source-'.$number);
                $contentHash = hash('sha256', 'materialized-'.$number);
                $batch[] = ['monthly_materialization_id' => 1, 'monthly_recovery_row_id' => $sourceRowIds[$id], 'salesforce_id' => $id, 'source_content_hash' => $sourceHash, 'content_hash' => $contentHash, 'content_matches_inventory' => true, 'is_deleted' => false, 'salesforce_created_at' => '2026-10-02 08:00:00', 'salesforce_last_modified_at' => '2026-10-02 08:00:00', 'functional_created_at' => '2026-10-02 08:00:00', 'created_at' => $now, 'updated_at' => $now];
                hash_update($f2mManifest, $id.'|'.$contentHash."\n");
            }
            DB::table('salesforce_interest_monthly_materialized_rows')->insert($batch);
        }
        DB::table('salesforce_interest_monthly_materializations')->where('id', 1)->update(['evidence' => json_encode(['manifest' => ['rows' => $f2mRows, 'sha256' => hash_final($f2mManifest)], 'verification' => ['verified' => false]])]);
        DB::table('salesforce_interest_monthly_activity_recoveries')->insert([
            'id' => 1, 'recovery_identifier' => (string) Str::uuid(), 'monthly_recovery_id' => 1, 'status' => 'completed', 'phase' => 'completed', 'batch_size' => 500, 'extraction_strategy' => 'selective_whatid_v1', 'pages_completed' => 96,
            'counters' => '{}', 'source_evidence' => json_encode(['source_rows' => $f2Rows, 'source_sha256' => hash_final($f5Source)]), 'reason_hash' => hash('sha256', 'representative f5'),
            'started_at' => $now, 'completed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $f5Manifest = hash_init('sha256');
        foreach (array_chunk(range(1, $f5Rows), 500) as $numbers) {
            $batch = [];
            foreach ($numbers as $number) {
                $activityId = '00T'.str_pad((string) $number, 15, '0', STR_PAD_LEFT);
                $contentHash = hash('sha256', 'activity-'.$number);
                $batch[] = ['monthly_activity_recovery_id' => 1, 'activity_kind' => 'Task', 'activity_salesforce_id' => $activityId, 'interest_salesforce_id' => $this->id((($number - 1) % $f2Rows) + 1), 'activity_is_deleted' => false, 'interest_is_deleted' => false, 'content_hash' => $contentHash, 'created_at' => $now, 'updated_at' => $now];
                hash_update($f5Manifest, $contentHash."\n");
            }
            DB::table('salesforce_interest_monthly_activity_rows')->insert($batch);
        }
        DB::table('salesforce_interest_monthly_activity_recoveries')->where('id', 1)->update(['evidence' => json_encode(['manifest' => ['rows' => $f5Rows, 'sha256' => hash_final($f5Manifest)], 'verification' => ['verified' => false]])]);
    }

    private function materializationSourceManifest(): string
    {
        return hash('sha256', $this->id(1).'|'.str_repeat('a', 64)."|0\n".$this->id(2).'|'.str_repeat('b', 64)."|1\n");
    }

    private function authorisedChange(\Closure $change): void
    {
        DB::transaction(function () use ($change): void {
            $change();
            app(October2026ProvisionalEvidenceInvalidator::class)->afterContentMutation(1);
        });
    }

    private function activitySourceManifest(): string
    {
        return hash('sha256', $this->id(1).":0\n".$this->id(2).":1\n");
    }

    private function materializationManifest(): string
    {
        return hash('sha256', $this->id(1).'|'.str_repeat('a', 64)."\n".$this->id(2).'|'.str_repeat('c', 64)."\n");
    }

    private function id(int $number): string
    {
        return 'a01'.str_pad((string) $number, 15, '0', STR_PAD_LEFT);
    }

    private function user(string $role): ReportUser
    {
        return ReportUser::create(['name' => 'Synthetic', 'email' => uniqid('', true).'@example.test', 'password' => 'synthetic-password', 'role' => $role, 'is_active' => true]);
    }

    private function asReportUser(ReportUser $user): static
    {
        return $this->withSession(['informes_authenticated' => true, 'report_user_id' => $user->id, 'report_user_role' => $user->role, 'report_user_email' => $user->email]);
    }
}
