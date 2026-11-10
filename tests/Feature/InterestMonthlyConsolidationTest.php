<?php

namespace Tests\Feature;

use App\Services\Salesforce\InterestMonthlyConsolidationService;
use App\Services\Salesforce\InterestMonthlyMaterializationService;
use App\Services\Salesforce\MonthlyInterestActivityRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InterestMonthlyConsolidationTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = 'Synthetic monthly staging audit';

    public function test_staging_references_verified_months_without_creating_or_publishing_a_generation(): void
    {
        $this->seedMonth(2, 11, 501);
        $service = app(InterestMonthlyConsolidationService::class);
        $id = $service->prepare([2], self::REASON);
        $result = $service->run($id, self::REASON);

        $this->assertSame('completed', $result['status']);
        $this->assertSame(501, $result['rows_staged']);
        $this->assertSame(2, $result['pages_completed']);
        $this->assertSame(501, $result['manifest']['rows']);
        $this->assertFalse($result['manifest']['global_coverage_verified']);
        $this->assertFalse($result['published']);
        $this->assertDatabaseCount('salesforce_interest_monthly_consolidation_members', 501);
        $this->assertDatabaseCount('salesforce_interest_generations', 0);
        $this->assertNull(DB::table('salesforce_interest_publication')->where('id', 1)->value('generation_id'));
    }

    public function test_administrative_command_stages_only_local_evidence_and_exposes_safe_status(): void
    {
        $this->seedMonth(2, 11, 1);
        $this->assertSame(0, Artisan::call('salesforce:stage-interest-months', [
            '--materialization' => ['2'], '--reason' => self::REASON,
        ]));
        $this->assertSame(0, Artisan::call('salesforce:stage-interest-months', [
            '--run' => '1', '--status' => true,
        ]));
        $this->assertStringContainsString('"published":false', Artisan::output());
        $this->assertDatabaseCount('salesforce_interest_generations', 0);
    }

    public function test_resuming_after_a_committed_page_does_not_duplicate_members(): void
    {
        $this->seedMonth(2, 11, 501);
        $service = app(InterestMonthlyConsolidationService::class);
        $id = $service->prepare([2], self::REASON);
        $service->run($id, self::REASON);
        $source = DB::table('salesforce_interest_monthly_consolidation_sources')->where('consolidation_id', $id)->first();
        $checkpoint = DB::table('salesforce_interest_monthly_materialized_rows')
            ->where('monthly_materialization_id', 2)->orderBy('id')->skip(499)->value('id');
        DB::table('salesforce_interest_monthly_consolidation_members')->where('source_id', $source->id)
            ->where('materialized_row_id', '>', $checkpoint)->delete();
        DB::table('salesforce_interest_monthly_consolidation_sources')->where('id', $source->id)
            ->update(['checkpoint_row_id' => $checkpoint, 'status' => 'running']);
        DB::table('salesforce_interest_monthly_consolidations')->where('id', $id)
            ->update(['status' => 'failed', 'pages_completed' => 1, 'rows_staged' => 500, 'manifest' => null]);

        $result = $service->run($id, self::REASON, true);
        $this->assertSame('completed', $result['status']);
        $this->assertSame(501, $result['rows_staged']);
        $this->assertDatabaseCount('salesforce_interest_monthly_consolidation_members', 501);
    }

    public function test_a_content_change_without_row_count_change_rejects_staging(): void
    {
        $this->seedMonth(2, 11, 1);
        $service = app(InterestMonthlyConsolidationService::class);
        $id = $service->prepare([2], self::REASON);
        DB::table('salesforce_interest_monthly_materialized_rows')->where('monthly_materialization_id', 2)
            ->update(['content_hash' => str_repeat('b', 64), 'source_content_hash' => str_repeat('b', 64)]);

        try {
            $service->run($id, self::REASON);
            $this->fail('Changed source content must invalidate staging.');
        } catch (\RuntimeException) {
            $this->assertSame('failed', $service->status($id)['status']);
            $this->assertDatabaseCount('salesforce_interest_monthly_consolidation_members', 1);
            $this->assertDatabaseCount('salesforce_interest_generations', 0);
        }
    }

    public function test_live_lease_and_obsolete_revision_block_a_second_worker(): void
    {
        $this->seedMonth(2, 11, 1);
        $service = app(InterestMonthlyConsolidationService::class);
        $id = $service->prepare([2], self::REASON);
        DB::table('salesforce_interest_monthly_consolidations')->where('id', $id)
            ->update(['status' => 'running', 'lease_token' => (string) Str::uuid(), 'heartbeat_at' => now('UTC')]);
        try {
            $service->run($id, self::REASON, true);
            $this->fail('A second active worker must be rejected.');
        } catch (\RuntimeException) {
            $this->assertSame('running', $service->status($id)['status']);
        }
        DB::table('salesforce_interest_monthly_consolidations')->where('id', $id)->update(['status' => 'failed']);
        DB::table('salesforce_interest_monthly_integrity_revisions')->where('scope', 'monthly_recovery_2')
            ->update(['revision' => DB::raw('revision + 1')]);
        try {
            $service->run($id, self::REASON, true);
            $this->fail('An obsolete source must be rejected.');
        } catch (\RuntimeException) {
            $this->assertSame('failed', $service->status($id)['status']);
        }
    }

    public function test_same_salesforce_identity_in_two_months_blocks_global_staging(): void
    {
        $this->seedMonth(2, 11, 1);
        $this->seedMonth(3, 10, 1);
        $service = app(InterestMonthlyConsolidationService::class);
        $id = $service->prepare([2, 3], self::REASON);

        try {
            $service->run($id, self::REASON);
            $this->fail('Cross-month identity overlap must remain unresolved.');
        } catch (\RuntimeException) {
            $this->assertSame('failed', $service->status($id)['status']);
            $this->assertSame('identity_conflict', $service->status($id)['diagnostic']);
            $this->assertDatabaseCount('salesforce_interest_generations', 0);
        }
    }

    private function seedMonth(int $id, int $month, int $size): void
    {
        $now = now();
        $start = $month === 11 ? '2026-10-31 23:00:00' : '2026-09-30 22:00:00';
        $end = $month === 11 ? '2026-11-30 23:00:00' : '2026-10-31 23:00:00';
        $functional = $month === 11 ? '2026-11-02 08:00:00' : '2026-10-02 08:00:00';
        $hash = str_repeat('a', 64);
        DB::table('salesforce_interest_monthly_recoveries')->insert([
            'id' => $id, 'recovery_identifier' => (string) Str::uuid(),
            'functional_start_at' => $start, 'functional_end_at' => $end,
            'effective_cutoff_at' => $functional, 'timezone' => 'Europe/Madrid', 'revision' => 1,
            'status' => 'completed', 'phase' => 'completed', 'batch_size' => 500, 'pages_completed' => 1,
            'counters' => '{}', 'evidence' => json_encode(['verification' => ['verified' => true]]),
            'reason_hash' => hash('sha256', self::REASON), 'started_at' => $now, 'completed_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('salesforce_interest_monthly_materializations')->insert([
            'id' => $id, 'materialization_identifier' => (string) Str::uuid(), 'monthly_recovery_id' => $id,
            'status' => 'completed', 'phase' => 'completed', 'batch_size' => 500, 'pages_completed' => 1,
            'counters' => '{}', 'source_evidence' => '{}',
            'reason_hash' => hash('sha256', self::REASON), 'started_at' => $now, 'completed_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $f2rows = [];
        for ($n = 1; $n <= $size; $n++) {
            $sfid = sprintf('a01%015d', $n);
            $f2rows[] = ['monthly_recovery_id' => $id, 'salesforce_id' => $sfid,
                'content_hash' => $hash, 'is_deleted' => false, 'functional_created_at' => $functional,
                'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($f2rows, 500) as $chunk) {
            DB::table('salesforce_interest_monthly_recovery_rows')->insert($chunk);
        }
        $materialized = [];
        foreach (DB::table('salesforce_interest_monthly_recovery_rows')->where('monthly_recovery_id', $id)->get(['id', 'salesforce_id']) as $source) {
            $materialized[] = [
                'monthly_materialization_id' => $id, 'monthly_recovery_row_id' => $source->id,
                'salesforce_id' => $source->salesforce_id, 'source_content_hash' => $hash,
                'content_hash' => $hash, 'content_matches_inventory' => true, 'is_deleted' => false,
                'salesforce_created_at' => $functional, 'salesforce_last_modified_at' => $functional,
                'functional_created_at' => $functional,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($materialized, 500) as $chunk) {
            DB::table('salesforce_interest_monthly_materialized_rows')->insert($chunk);
        }
        $f2mManifest = app(InterestMonthlyMaterializationService::class)->manifest($id);
        DB::table('salesforce_interest_monthly_materializations')->where('id', $id)->update([
            'evidence' => json_encode(['manifest' => $f2mManifest, 'verification' => ['verified' => true]]),
        ]);
        $f5Manifest = app(MonthlyInterestActivityRecoveryService::class)->manifest($id);
        DB::table('salesforce_interest_monthly_activity_recoveries')->insert([
            'id' => $id, 'recovery_identifier' => (string) Str::uuid(), 'monthly_recovery_id' => $id,
            'status' => 'completed', 'phase' => 'completed', 'batch_size' => 500,
            'extraction_strategy' => 'selective_whatid_v1', 'pages_completed' => 1,
            'counters' => '{}', 'source_evidence' => '{}',
            'evidence' => json_encode(['manifest' => $f5Manifest, 'verification' => ['verified' => true]]),
            'reason_hash' => hash('sha256', self::REASON), 'started_at' => $now, 'completed_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
