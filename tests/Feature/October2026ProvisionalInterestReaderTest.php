<?php

namespace Tests\Feature;

use App\Models\ReportUser;
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
        $overview = app(October2026ProvisionalInterestReader::class)->overview();

        $this->assertSame(2, $overview['coverage']['f2_inventory']);
        $this->assertSame(2, $overview['coverage']['materialized']);
        $this->assertSame(1, $overview['coverage']['eligible_active_matching']);
        $this->assertSame(1, $overview['coverage']['content_changed']);
        $this->assertSame(1, $overview['coverage']['canonical_identity_unavailable']);
        $this->assertSame('available_with_coverage_limits', $overview['availability']['leads_interest_overview']);
        $this->assertSame('unavailable', $overview['availability']['campaigns_calls_and_seo']);
        $this->assertArrayNotHasKey('salesforce_id', $overview);
    }

    public function test_it_fails_closed_when_a_materialization_source_snapshot_no_longer_matches_f2(): void
    {
        $this->seedValidJournals();
        DB::table('salesforce_interest_monthly_recovery_rows')->where('monthly_recovery_id', 1)->where('salesforce_id', $this->id(1))->update(['content_hash' => str_repeat('f', 64)]);

        $this->expectException(ProvisionalInterestSourceUnavailable::class);
        app(October2026ProvisionalInterestReader::class)->overview();
    }

    public function test_it_fails_closed_when_the_fixed_journal_is_not_the_complete_madrid_october_period(): void
    {
        $this->seedValidJournals();
        DB::table('salesforce_interest_monthly_recoveries')->where('id', 1)->update(['functional_end_at' => '2026-11-01 00:00:00']);

        $this->expectException(ProvisionalInterestSourceUnavailable::class);
        app(October2026ProvisionalInterestReader::class)->overview();
    }

    public function test_the_admin_only_screen_is_disabled_by_default_and_fails_closed_when_evidence_is_incomplete(): void
    {
        config()->set('services.informes_auth.enabled', true);
        $this->seedValidJournals();
        $admin = $this->user(ReportUser::ROLE_ADMIN);
        $viewer = $this->user(ReportUser::ROLE_VIEWER);

        $this->asReportUser($admin)->get('/informes/leads/provisional/october-2026')->assertNotFound();
        config()->set('salesforce.october_2026_provisional_enabled', true);
        $this->asReportUser($viewer)->get('/informes/leads/provisional/october-2026')->assertForbidden();
        $this->asReportUser($admin)->get('/informes/leads/provisional/october-2026')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('inventario provisional no certificado')
            ->assertDontSee($this->id(1));

        DB::table('salesforce_interest_monthly_activity_recoveries')->where('id', 1)->update(['phase' => 'verify_task']);
        $this->asReportUser($admin)->get('/informes/leads/provisional/october-2026')->assertStatus(503);
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
        foreach ([[1, str_repeat('a', 64), false], [2, str_repeat('b', 64), true]] as [$number, $hash, $deleted]) {
            DB::table('salesforce_interest_monthly_recovery_rows')->insert([
                'monthly_recovery_id' => 1, 'salesforce_id' => $this->id($number), 'content_hash' => $hash, 'is_deleted' => $deleted,
                'functional_created_at' => '2026-10-02 08:00:00', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $sourceManifest = $this->recoveryManifest();
        DB::table('salesforce_interest_monthly_materializations')->insert([
            'id' => 1, 'materialization_identifier' => (string) Str::uuid(), 'monthly_recovery_id' => 1, 'status' => 'completed', 'phase' => 'completed', 'batch_size' => 500, 'pages_completed' => 1,
            'counters' => '{}', 'source_evidence' => json_encode(['source_rows' => 2, 'source_sha256' => $sourceManifest]), 'reason_hash' => hash('sha256', 'synthetic materialization'),
            'started_at' => $now, 'completed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ([[1, str_repeat('a', 64), true, false, 'Madrid', $this->id(11)], [2, str_repeat('c', 64), false, true, null, null]] as [$number, $hash, $matching, $deleted, $delegation, $person]) {
            DB::table('salesforce_interest_monthly_materialized_rows')->insert([
                'monthly_materialization_id' => 1, 'monthly_recovery_row_id' => $number, 'salesforce_id' => $this->id($number), 'source_content_hash' => $number === 1 ? str_repeat('a', 64) : str_repeat('b', 64), 'content_hash' => $hash,
                'content_matches_inventory' => $matching, 'is_deleted' => $deleted, 'salesforce_created_at' => '2026-10-02 08:00:00', 'salesforce_last_modified_at' => '2026-10-02 08:00:00', 'functional_created_at' => '2026-10-02 08:00:00',
                'origin_delegation' => $delegation, 'canonical_person_salesforce_id' => $person, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $materializationManifest = $this->materializationManifest();
        DB::table('salesforce_interest_monthly_materializations')->where('id', 1)->update(['evidence' => json_encode(['manifest' => ['rows' => 2, 'sha256' => $materializationManifest], 'verification' => ['verified' => false]])]);
        DB::table('salesforce_interest_monthly_activity_recoveries')->insert([
            'id' => 1, 'recovery_identifier' => (string) Str::uuid(), 'monthly_recovery_id' => 1, 'status' => 'completed', 'phase' => 'completed', 'batch_size' => 500, 'extraction_strategy' => 'selective_whatid_v1', 'pages_completed' => 1,
            'counters' => '{}', 'source_evidence' => json_encode(['source_rows' => 2, 'source_sha256' => $sourceManifest]), 'evidence' => json_encode(['manifest' => ['rows' => 0, 'sha256' => hash('sha256', '')], 'verification' => ['verified' => false]]),
            'reason_hash' => hash('sha256', 'synthetic f5'), 'started_at' => $now, 'completed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function recoveryManifest(): string
    {
        return hash('sha256', $this->id(1).'|'.str_repeat('a', 64)."|0\n".$this->id(2).'|'.str_repeat('b', 64)."|1\n");
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
