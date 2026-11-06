<?php

namespace Tests\Feature;

use App\Services\Salesforce\MonthlyInterestReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MonthlyInterestReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_exact_missing_ids_without_claiming_unretained_historical_evidence(): void
    {
        $recovery = DB::table('salesforce_interest_monthly_recoveries')->insertGetId(['recovery_identifier' => (string) str()->uuid(), 'functional_start_at' => '2026-09-30 22:00:00', 'functional_end_at' => '2026-10-31 23:00:00', 'timezone' => 'Europe/Madrid', 'status' => 'completed', 'phase' => 'completed', 'batch_size' => 500, 'pages_completed' => 1, 'counters' => json_encode(['content_changed' => 2]), 'evidence' => json_encode(['verification' => ['verified' => false]]), 'reason_hash' => hash('sha256', 'test'), 'started_at' => now(), 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $first = 'a01'.str_repeat('0', 14).'1';
        $second = 'a01'.str_repeat('0', 14).'2';
        $source = DB::table('salesforce_interest_monthly_recovery_rows')->insertGetId(['monthly_recovery_id' => $recovery, 'salesforce_id' => $first, 'content_hash' => hash('sha256', $first), 'is_deleted' => false, 'functional_created_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('salesforce_interest_monthly_recovery_rows')->insert(['monthly_recovery_id' => $recovery, 'salesforce_id' => $second, 'content_hash' => hash('sha256', $second), 'is_deleted' => true, 'functional_created_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $materialization = DB::table('salesforce_interest_monthly_materializations')->insertGetId(['materialization_identifier' => (string) str()->uuid(), 'monthly_recovery_id' => $recovery, 'status' => 'completed', 'phase' => 'completed', 'batch_size' => 500, 'pages_completed' => 1, 'counters' => '{}', 'source_evidence' => '{}', 'evidence' => '{}', 'reason_hash' => hash('sha256', 'test'), 'started_at' => now(), 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('salesforce_interest_monthly_materialized_rows')->insert(['monthly_materialization_id' => $materialization, 'monthly_recovery_row_id' => $source, 'salesforce_id' => $first, 'source_content_hash' => hash('sha256', $first), 'content_hash' => hash('sha256', 'changed'), 'content_matches_inventory' => false, 'is_deleted' => false, 'salesforce_created_at' => now(), 'salesforce_last_modified_at' => now(), 'functional_created_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $run = DB::table('salesforce_interest_monthly_activity_recoveries')->insertGetId(['recovery_identifier' => (string) str()->uuid(), 'monthly_recovery_id' => $recovery, 'status' => 'completed', 'phase' => 'completed', 'batch_size' => 500, 'pages_completed' => 1, 'counters' => json_encode(['remote_missing_local' => 30, 'content_changed' => 1]), 'source_evidence' => '{}', 'evidence' => json_encode(['verification' => ['verified' => false]]), 'reason_hash' => hash('sha256', 'test'), 'started_at' => now(), 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $service = app(MonthlyInterestReconciliationService::class);
        $summary = $service->summary($recovery, $materialization, $run);

        $this->assertSame(1, $summary['materialization']['missing_from_materialization']);
        $this->assertSame(1, $summary['materialization']['content_changed']);
        $this->assertFalse($summary['f5']['additional_remote_ids_identifiable']);
        $this->assertSame([['salesforce_id' => $second, 'evidence_sha256' => hash('sha256', $second)]], $service->missingIdentifiers($recovery, $materialization));
    }
}
