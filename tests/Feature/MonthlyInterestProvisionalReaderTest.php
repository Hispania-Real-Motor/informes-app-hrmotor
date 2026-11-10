<?php

namespace Tests\Feature;

use App\Models\ReportUser;
use App\Models\SalesforceUser;
use App\Services\Reports\Leads\LeadDelegationNormalizer;
use App\Services\Reports\Leads\LeadRecordTypeNormalizer;
use App\Services\Reports\Leads\MonthlyInterestProvisionalReader;
use App\Services\Reports\Leads\October2026ProvisionalEvidenceInvalidator;
use App\Services\Reports\Leads\ObservedInterestOwnerProjection;
use App\Services\Reports\Leads\ProvisionalInterestSourceUnavailable;
use App\Services\Reports\ReservationsSales\CommercialDelegationSnapshotService;
use App\Services\Salesforce\InterestMonthlyMaterializationService;
use App\Services\Salesforce\MonthlyInterestActivityRecoveryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MonthlyInterestProvisionalReaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_november_validation_publishes_only_a_verified_revision_and_reads_a_bounded_projection(): void
    {
        $this->seedNovember();
        $reader = app(MonthlyInterestProvisionalReader::class);
        $overview = $reader->validate(2);
        $snapshot = $reader->latestForMonth(2026, 11);

        $this->assertSame(1, $overview['coverage']['eligible_active_matching']);
        $this->assertSame(2, $snapshot['recovery_id']);
        $this->assertSame('monthly_recovery_2', $snapshot['scope']);
        $this->assertSame(1, DB::table('salesforce_interest_october_provisional_projection_rows')
            ->where('scope', $snapshot['scope'])->where('projection_key', $snapshot['projection_key'])->count());
        $this->assertSame([['delegation' => 'Madrid General', 'interests' => 1]], $overview['by_delegation']);
        $this->assertDatabaseCount('salesforce_interest_generations', 0);
    }

    public function test_november_owner_uses_stable_identity_encrypted_label_and_observed_commercial_filters(): void
    {
        $ownerId = '005000000000000001';
        $this->seedNovember($ownerId, 'Comercial observado');
        DB::table('commercial_delegation_snapshots')->insert([
            'salesforce_user_id' => $ownerId, 'delegation' => 'Madrid', 'zone' => 'Zona Sur y Centro',
            'observed_from' => '2026-11-05 00:00:00', 'source' => 'salesforce_user_observation',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(MonthlyInterestProvisionalReader::class)->validate(2);
        $key = hash_hmac('sha256', $ownerId, (string) config('app.key'));
        $projection = DB::table('salesforce_interest_october_provisional_projection_rows')->where('scope', 'monthly_recovery_2')->first();
        $this->assertSame($key, $projection->owner_key);
        $this->assertSame('Madrid', $projection->commercial_delegation);
        $this->assertSame('Zona Sur y Centro', $projection->commercial_zone);
        $this->assertDatabaseMissing('salesforce_interest_october_provisional_owners', ['name_ciphertext' => 'Comercial observado']);

        config()->set('services.informes_auth.enabled', true);
        config()->set('salesforce.november_2026_provisional_enabled', true);
        $admin = $this->user(ReportUser::ROLE_ADMIN);
        $this->asReportUser($admin)->getJson('/informes/leads/data/resumen?period=november_2026&commercial='.$key.'&commercial_delegation=Madrid')
            ->assertOk()->assertJsonPath('kpis.leads_totales', 1);
        $this->asReportUser($admin)->getJson('/informes/leads/data/resumen?period=november_2026&commercial='.$key.'&commercial_delegation=Barcelona')
            ->assertOk()->assertJsonPath('kpis.leads_totales', 0);
    }

    public function test_commercial_observation_invalidates_november_evidence_transactionally(): void
    {
        $ownerId = '005000000000000001';
        $this->seedNovember($ownerId, 'Comercial observado');
        DB::table('commercial_delegation_snapshots')->insert([
            'salesforce_user_id' => $ownerId, 'delegation' => 'Madrid', 'zone' => 'Zona Sur y Centro',
            'observed_from' => '2026-11-05 00:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        SalesforceUser::query()->create([
            'salesforce_id' => $ownerId, 'name' => 'Comercial observado', 'is_active' => true,
            'profile_name' => 'Compra/Venta', 'user_delegation' => 'Barcelona',
        ]);
        $reader = app(MonthlyInterestProvisionalReader::class);
        $reader->validate(2);
        $snapshot = $reader->dashboardProjection(2);

        try {
            DB::transaction(function (): void {
                app(CommercialDelegationSnapshotService::class)->captureCurrentUsers(CarbonImmutable::parse('2026-11-10 10:00:00', 'UTC'));
                throw new \RuntimeException('Synthetic rollback');
            });
        } catch (\RuntimeException) {
        }
        $reader->assertCurrent(2, $snapshot['integrity_revision'], $snapshot['projection_key']);

        app(CommercialDelegationSnapshotService::class)->captureCurrentUsers(CarbonImmutable::parse('2026-11-10 10:00:00', 'UTC'));
        $this->expectException(ProvisionalInterestSourceUnavailable::class);
        $reader->dashboardProjection(2);
    }

    public function test_content_change_invalidates_the_snapshot_in_the_same_transaction_and_rollback_preserves_it(): void
    {
        $this->seedNovember();
        $reader = app(MonthlyInterestProvisionalReader::class);
        $reader->validate(2);
        $snapshot = $reader->dashboardProjection(2);

        try {
            DB::transaction(function (): void {
                DB::table('salesforce_interest_monthly_materialized_rows')->where('monthly_materialization_id', 2)
                    ->update(['status' => 'Changed']);
                app(October2026ProvisionalEvidenceInvalidator::class)->afterContentMutation(2);
                throw new \RuntimeException('Synthetic rollback');
            });
        } catch (\RuntimeException) {
        }
        $reader->assertCurrent(2, $snapshot['integrity_revision'], $snapshot['projection_key']);

        DB::transaction(function (): void {
            DB::table('salesforce_interest_monthly_materialized_rows')->where('monthly_materialization_id', 2)
                ->update(['status' => 'Changed']);
            app(October2026ProvisionalEvidenceInvalidator::class)->afterContentMutation(2);
        });
        $this->expectException(ProvisionalInterestSourceUnavailable::class);
        $reader->dashboardProjection(2);
    }

    public function test_incomplete_f5_cannot_publish_a_monthly_projection(): void
    {
        $this->seedNovember();
        DB::table('salesforce_interest_monthly_activity_recoveries')->where('id', 2)->update(['phase' => 'verify_event']);

        $this->expectException(ProvisionalInterestSourceUnavailable::class);
        app(MonthlyInterestProvisionalReader::class)->validate(2);
    }

    public function test_newer_completed_revision_without_validation_blocks_older_snapshot(): void
    {
        $this->seedNovember();
        app(MonthlyInterestProvisionalReader::class)->validate(2);
        $newer = (array) DB::table('salesforce_interest_monthly_recoveries')->find(2);
        $newer['id'] = 3;
        $newer['recovery_identifier'] = (string) Str::uuid();
        $newer['revision'] = 2;
        DB::table('salesforce_interest_monthly_recoveries')->insert($newer);

        $this->expectException(ProvisionalInterestSourceUnavailable::class);
        app(MonthlyInterestProvisionalReader::class)->latestForMonth(2026, 11);
    }

    public function test_validation_chunks_more_than_five_hundred_interests(): void
    {
        $this->seedNovember();
        $now = now();
        $hash = str_repeat('a', 64);
        $sources = [];
        for ($number = 2; $number <= 501; $number++) {
            $sources[] = ['monthly_recovery_id' => 2, 'salesforce_id' => sprintf('a01%015d', $number),
                'content_hash' => $hash, 'is_deleted' => false, 'functional_created_at' => '2026-11-02 08:00:00',
                'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('salesforce_interest_monthly_recovery_rows')->insert($sources);
        $materialized = [];
        foreach (DB::table('salesforce_interest_monthly_recovery_rows')->where('monthly_recovery_id', 2)
            ->where('salesforce_id', '!=', 'a01000000000000001')->get(['id', 'salesforce_id']) as $source) {
            $materialized[] = ['monthly_materialization_id' => 2, 'monthly_recovery_row_id' => $source->id,
                'salesforce_id' => $source->salesforce_id, 'source_content_hash' => $hash, 'content_hash' => $hash,
                'content_matches_inventory' => true, 'is_deleted' => false,
                'salesforce_created_at' => '2026-11-02 08:00:00',
                'salesforce_last_modified_at' => '2026-11-02 08:00:00',
                'functional_created_at' => '2026-11-02 08:00:00',
                'status' => 'Potencial', 'type' => 'Venta', 'source' => 'Web', 'medium' => 'Orgánico',
                'channel' => 'Formulario', 'origin_delegation' => 'Madrid',
                'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('salesforce_interest_monthly_materialized_rows')->insert($materialized);
        $f2m = hash_init('sha256');
        $f5 = hash_init('sha256');
        foreach (DB::table('salesforce_interest_monthly_recovery_rows')->where('monthly_recovery_id', 2)
            ->orderBy('salesforce_id')->get(['salesforce_id', 'content_hash']) as $source) {
            hash_update($f2m, $source->salesforce_id.'|'.$source->content_hash."|0\n");
            hash_update($f5, $source->salesforce_id.":0\n");
        }
        DB::table('salesforce_interest_monthly_materializations')->where('id', 2)->update([
            'source_evidence' => json_encode(['source_rows' => 501, 'source_sha256' => hash_final($f2m)]),
            'evidence' => json_encode(['manifest' => app(InterestMonthlyMaterializationService::class)->manifest(2),
                'verification' => ['verified' => true]]),
        ]);
        DB::table('salesforce_interest_monthly_activity_recoveries')->where('id', 2)->update([
            'source_evidence' => json_encode(['source_rows' => 501, 'source_sha256' => hash_final($f5)]),
        ]);

        $reader = app(MonthlyInterestProvisionalReader::class);
        $overview = $reader->validate(2);
        $this->assertSame(501, $overview['coverage']['eligible_active_matching']);
        $this->assertSame(501, DB::table('salesforce_interest_october_provisional_projection_rows')
            ->where('scope', 'monthly_recovery_2')->count());
    }

    public function test_a_concurrent_validation_cannot_publish_a_removed_candidate(): void
    {
        $this->seedNovember();
        $outer = $this->readerWithHooks(beforePublication: function (): void {
            app(MonthlyInterestProvisionalReader::class)->validate(2);
        });

        try {
            $outer->validate(2);
            $this->fail('The stale candidate must be rejected.');
        } catch (ProvisionalInterestSourceUnavailable) {
            $snapshot = app(MonthlyInterestProvisionalReader::class)->dashboardProjection(2);
            $this->assertSame(1, DB::table('salesforce_interest_october_provisional_projection_rows')
                ->where('scope', $snapshot['scope'])->where('projection_key', $snapshot['projection_key'])->count());
        }
    }

    public function test_a_write_during_validation_or_http_read_fails_closed(): void
    {
        $this->seedNovember();
        $invalidator = app(October2026ProvisionalEvidenceInvalidator::class);
        $interrupted = $this->readerWithHooks(beforePublication: fn () => $invalidator->afterContentMutation(2));
        $this->expectException(ProvisionalInterestSourceUnavailable::class);
        $interrupted->validate(2);
    }

    public function test_http_detects_revision_change_between_its_first_and_last_checks(): void
    {
        $this->seedNovember();
        app(MonthlyInterestProvisionalReader::class)->validate(2);
        $invalidator = app(October2026ProvisionalEvidenceInvalidator::class);
        $reader = $this->readerWithHooks(beforeHttpFinalCheck: fn () => $invalidator->afterContentMutation(2));

        $this->expectException(ProvisionalInterestSourceUnavailable::class);
        $reader->dashboardProjection(2);
    }

    public function test_only_an_admin_can_read_november_and_http_never_scans_monthly_journal_rows(): void
    {
        config()->set('services.informes_auth.enabled', true);
        $this->seedNovember();
        app(MonthlyInterestProvisionalReader::class)->validate(2);
        $admin = $this->user(ReportUser::ROLE_ADMIN);
        $viewer = $this->user(ReportUser::ROLE_VIEWER);

        $this->asReportUser($admin)->get('/informes/leads?period=november_2026')->assertNotFound();
        config()->set('salesforce.november_2026_provisional_enabled', true);
        $this->asReportUser($viewer)->get('/informes/leads?period=november_2026')->assertForbidden();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->asReportUser($admin)->get('/informes/leads?period=november_2026')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Noviembre de 2026')
            ->assertSee('Actualizado hasta 10/11/2026 11:00');
        $this->asReportUser($admin)->getJson('/informes/leads/data/resumen?period=november_2026')
            ->assertOk()->assertJsonPath('kpis.leads_totales', 1)
            ->assertJsonPath('kpis.gestionados', null);
        $this->assertFalse(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'salesforce_interest_monthly_recovery_rows')
            || str_contains($sql, 'salesforce_interest_monthly_materialized_rows')
            || str_contains($sql, 'salesforce_interest_monthly_activity_rows')));
    }

    private function seedNovember(?string $ownerId = null, ?string $ownerName = null): void
    {
        $now = now();
        $id = 'a01000000000000001';
        $hash = str_repeat('a', 64);
        DB::table('salesforce_interest_monthly_recoveries')->insert([
            'id' => 2, 'recovery_identifier' => (string) Str::uuid(), 'functional_start_at' => '2026-10-31 23:00:00',
            'functional_end_at' => '2026-11-30 23:00:00', 'effective_cutoff_at' => '2026-11-10 10:00:00',
            'timezone' => 'Europe/Madrid', 'revision' => 1, 'status' => 'completed', 'phase' => 'completed',
            'batch_size' => 500, 'pages_completed' => 1, 'counters' => '{}',
            'evidence' => json_encode(['verification' => ['verified' => true]]),
            'reason_hash' => hash('sha256', 'synthetic november source'), 'started_at' => $now,
            'completed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $rowId = DB::table('salesforce_interest_monthly_recovery_rows')->insertGetId([
            'monthly_recovery_id' => 2, 'salesforce_id' => $id, 'content_hash' => $hash,
            'is_deleted' => false, 'functional_created_at' => '2026-11-02 08:00:00',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $f2mSource = ['rows' => 1, 'sha256' => hash('sha256', $id.'|'.$hash."|0\n")];
        $f5Source = ['rows' => 1, 'sha256' => hash('sha256', $id.":0\n")];
        DB::table('salesforce_interest_monthly_materializations')->insert([
            'id' => 2, 'materialization_identifier' => (string) Str::uuid(), 'monthly_recovery_id' => 2,
            'status' => 'completed', 'phase' => 'completed', 'batch_size' => 500, 'pages_completed' => 1,
            'counters' => '{}', 'source_evidence' => json_encode(['source_rows' => 1, 'source_sha256' => $f2mSource['sha256']]),
            'reason_hash' => hash('sha256', 'synthetic november materialization'), 'started_at' => $now,
            'completed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('salesforce_interest_monthly_materialized_rows')->insert([
            'monthly_materialization_id' => 2, 'monthly_recovery_row_id' => $rowId,
            'salesforce_id' => $id, 'source_content_hash' => $hash, 'content_hash' => $hash,
            'content_matches_inventory' => true, 'is_deleted' => false,
            'salesforce_created_at' => '2026-11-02 08:00:00',
            'salesforce_last_modified_at' => '2026-11-02 08:00:00',
            'functional_created_at' => '2026-11-02 08:00:00',
            'status' => 'Potencial', 'type' => 'Venta', 'source' => 'Web', 'medium' => 'Orgánico',
            'channel' => 'Formulario', 'origin_delegation' => 'Madrid',
            'owner_salesforce_id' => $ownerId, 'owner_name' => $ownerName,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $f2mManifest = app(InterestMonthlyMaterializationService::class)->manifest(2);
        DB::table('salesforce_interest_monthly_materializations')->where('id', 2)->update([
            'evidence' => json_encode(['manifest' => $f2mManifest, 'verification' => ['verified' => true]]),
        ]);
        $f5Manifest = app(MonthlyInterestActivityRecoveryService::class)->manifest(2);
        DB::table('salesforce_interest_monthly_activity_recoveries')->insert([
            'id' => 2, 'recovery_identifier' => (string) Str::uuid(), 'monthly_recovery_id' => 2,
            'status' => 'completed', 'phase' => 'completed', 'batch_size' => 500,
            'extraction_strategy' => 'selective_whatid_v1', 'pages_completed' => 1, 'counters' => '{}',
            'source_evidence' => json_encode(['source_rows' => 1, 'source_sha256' => $f5Source['sha256']]),
            'evidence' => json_encode(['manifest' => $f5Manifest, 'verification' => ['verified' => true]]),
            'reason_hash' => hash('sha256', 'synthetic november f5'), 'started_at' => $now,
            'completed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function user(string $role): ReportUser
    {
        return ReportUser::create(['name' => 'Synthetic', 'email' => uniqid('', true).'@example.test',
            'password' => 'synthetic-password', 'role' => $role, 'is_active' => true]);
    }

    private function readerWithHooks(?\Closure $beforePublication = null, ?\Closure $beforeHttpFinalCheck = null): MonthlyInterestProvisionalReader
    {
        return new MonthlyInterestProvisionalReader(
            app(InterestMonthlyMaterializationService::class),
            app(MonthlyInterestActivityRecoveryService::class),
            app(LeadDelegationNormalizer::class),
            app(LeadRecordTypeNormalizer::class),
            app(ObservedInterestOwnerProjection::class),
            $beforePublication,
            $beforeHttpFinalCheck,
        );
    }

    private function asReportUser(ReportUser $user): static
    {
        return $this->withSession(['informes_authenticated' => true, 'report_user_id' => $user->id,
            'report_user_role' => $user->role, 'report_user_email' => $user->email]);
    }
}
