<?php

namespace Tests\Feature;

use App\Models\SalesforceInterest;
use App\Models\SalesforceInterestReconciliation;
use App\Models\SalesforceInterestReconciliationRun;
use App\Models\SalesforceLead;
use App\Services\Salesforce\SalesforceInterestReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class SalesforceInterestReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconciles_strong_relationships_masters_deletions_orphans_and_canonical_person(): void
    {
        $exact = $this->lead(1);
        $withoutInterest = $this->lead(2);
        $deleted = $this->lead(3, ['is_deleted' => true]);
        $master = $this->lead(5);
        $merged = $this->lead(4, [
            'is_deleted' => true,
            'salesforce_master_record_id' => $master->salesforce_id,
        ]);
        $missingMaster = $this->lead(6, ['salesforce_master_record_id' => $this->leadId(600)]);
        $selfMaster = $this->lead(7);
        $selfMaster->update(['salesforce_master_record_id' => $selfMaster->salesforce_id]);
        $cycleA = $this->lead(8);
        $cycleB = $this->lead(9);
        $cycleA->update(['salesforce_master_record_id' => $cycleB->salesforce_id]);
        $cycleB->update(['salesforce_master_record_id' => $cycleA->salesforce_id]);
        $sharedAccount = '001000000000000777';
        $historicA = $this->lead(10);
        $historicB = $this->lead(11);

        $this->interest(1, ['migration_origin_lead_id' => $exact->salesforce_id, 'lead_salesforce_id' => $exact->salesforce_id]);
        $this->interest(3, [
            'migration_origin_lead_id' => $deleted->salesforce_id,
            'lead_salesforce_id' => $deleted->salesforce_id,
            'is_deleted' => true,
        ]);
        $this->interest(4, ['migration_origin_lead_id' => $merged->salesforce_id, 'lead_salesforce_id' => $master->salesforce_id]);
        $this->interest(12, ['migration_origin_lead_id' => $this->leadId(999)]);
        $this->interest(13, ['account_salesforce_id' => '001000000000000013']);
        $this->interest(10, ['migration_origin_lead_id' => $historicA->salesforce_id, 'account_salesforce_id' => $sharedAccount]);
        $this->interest(11, ['migration_origin_lead_id' => $historicB->salesforce_id, 'account_salesforce_id' => $sharedAccount]);
        $inconsistent = $this->interest(14, ['account_salesforce_id' => '001000000000000014']);
        DB::table('salesforce_interests')->where('id', $inconsistent->id)->update([
            'canonical_person_type' => 'Lead',
            'canonical_person_salesforce_id' => $this->leadId(14),
        ]);

        $leadSnapshot = SalesforceLead::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $interestSnapshot = SalesforceInterest::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $stats = app(SalesforceInterestReconciliationService::class)->run('Synthetic FOUNDATION-3 reconciliation');

        $this->assertSame(11, $stats['leads_examined']);
        $this->assertSame(8, $stats['interests_examined']);
        $this->assertSame(5, $stats['exact']);
        $this->assertSame(6, $stats['lead_without_interest']);
        $this->assertSame(1, $stats['interest_origin_missing_lead']);
        $this->assertSame(2, $stats['interest_without_migration_origin']);
        $this->assertDatabaseHas('salesforce_interest_reconciliations', [
            'subject_salesforce_id' => $exact->salesforce_id,
            'relationship_status' => 'exact',
            'canonical_person_status' => 'coherent_lead',
        ]);
        $this->assertDatabaseHas('salesforce_interest_reconciliations', [
            'subject_salesforce_id' => $withoutInterest->salesforce_id,
            'relationship_status' => 'lead_without_interest',
        ]);
        $this->assertDatabaseHas('salesforce_interest_reconciliations', [
            'subject_salesforce_id' => $merged->salesforce_id,
            'master_status' => 'direct',
            'resolved_master_lead_id' => $master->salesforce_id,
            'current_lead_alignment' => 'matches_master',
            'lead_is_deleted' => true,
        ]);
        $this->assertDatabaseHas('salesforce_interest_reconciliations', [
            'subject_salesforce_id' => $missingMaster->salesforce_id,
            'master_status' => 'missing',
            'has_conflict' => true,
        ]);
        $this->assertDatabaseHas('salesforce_interest_reconciliations', [
            'subject_salesforce_id' => $selfMaster->salesforce_id,
            'master_status' => 'self_reference',
        ]);
        $this->assertDatabaseHas('salesforce_interest_reconciliations', [
            'subject_salesforce_id' => $cycleA->salesforce_id,
            'master_status' => 'cycle',
        ]);
        $this->assertDatabaseHas('salesforce_interest_reconciliations', [
            'subject_salesforce_id' => $inconsistent->salesforce_id,
            'subject_type' => 'interest',
            'canonical_person_status' => 'inconsistent',
            'has_conflict' => true,
        ]);
        $this->assertSame(2, SalesforceInterestReconciliation::query()
            ->where('migration_origin_lead_id', '!=', null)
            ->whereIn('subject_salesforce_id', [$historicA->salesforce_id, $historicB->salesforce_id])
            ->count());
        $this->assertSame($leadSnapshot, SalesforceLead::query()->orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertSame($interestSnapshot, SalesforceInterest::query()->orderBy('id')->get()->map->getRawOriginal()->all());
    }

    public function test_resolves_multi_hop_master_chain_without_rewriting_historical_origin(): void
    {
        $origin = $this->lead(20);
        $middle = $this->lead(21);
        $final = $this->lead(22);
        $origin->update(['salesforce_master_record_id' => $middle->salesforce_id]);
        $middle->update(['salesforce_master_record_id' => $final->salesforce_id]);
        $this->interest(20, [
            'migration_origin_lead_id' => $origin->salesforce_id,
            'lead_salesforce_id' => $final->salesforce_id,
        ]);

        app(SalesforceInterestReconciliationService::class)->run('Synthetic master chain validation');

        $this->assertDatabaseHas('salesforce_interest_reconciliations', [
            'subject_salesforce_id' => $origin->salesforce_id,
            'lead_salesforce_id' => $origin->salesforce_id,
            'immediate_master_lead_id' => $middle->salesforce_id,
            'resolved_master_lead_id' => $final->salesforce_id,
            'master_status' => 'chain',
            'current_lead_alignment' => 'matches_master',
        ]);
    }

    public function test_structural_conflict_matrix_covers_foreign_current_lead_master_mismatch_and_no_current_lead(): void
    {
        $foreignOrigin = $this->lead(50);
        $foreignCurrent = $this->lead(51);
        $this->interest(50, [
            'migration_origin_lead_id' => $foreignOrigin->salesforce_id,
            'lead_salesforce_id' => $foreignCurrent->salesforce_id,
        ]);

        $masterOrigin = $this->lead(52);
        $master = $this->lead(53);
        $masterOrigin->update(['salesforce_master_record_id' => $master->salesforce_id]);
        $this->interest(52, [
            'migration_origin_lead_id' => $masterOrigin->salesforce_id,
            'lead_salesforce_id' => $masterOrigin->salesforce_id,
        ]);

        $noCurrentLead = $this->lead(54);
        $this->interest(54, ['migration_origin_lead_id' => $noCurrentLead->salesforce_id]);

        $stats = app(SalesforceInterestReconciliationService::class)->run('Synthetic conflict matrix validation');

        $this->assertDatabaseHas('salesforce_interest_reconciliations', [
            'subject_salesforce_id' => $foreignOrigin->salesforce_id,
            'current_lead_alignment' => 'other',
            'has_conflict' => true,
        ]);
        $this->assertDatabaseHas('salesforce_interest_reconciliations', [
            'subject_salesforce_id' => $masterOrigin->salesforce_id,
            'master_status' => 'direct',
            'current_lead_alignment' => 'matches_origin',
            'has_conflict' => true,
        ]);
        $this->assertDatabaseHas('salesforce_interest_reconciliations', [
            'subject_salesforce_id' => $noCurrentLead->salesforce_id,
            'current_lead_alignment' => 'no_current_lead',
            'has_conflict' => false,
        ]);
        $this->assertSame(
            $stats['conflicts'],
            SalesforceInterestReconciliation::query()->where('has_conflict', true)->count(),
        );
    }

    public function test_identical_reexecution_is_logically_idempotent_and_keeps_only_latest_valid_snapshot(): void
    {
        $lead = $this->lead(30);
        $this->interest(30, ['migration_origin_lead_id' => $lead->salesforce_id]);
        $service = app(SalesforceInterestReconciliationService::class);

        $firstStats = $service->run('First synthetic idempotency run');
        $firstLogicalRows = $this->logicalRows();
        $secondStats = $service->run('Second synthetic idempotency run');

        $this->assertSame($firstStats['exact'], $secondStats['exact']);
        $this->assertSame($firstLogicalRows, $this->logicalRows());
        $this->assertDatabaseCount('salesforce_interest_reconciliation_runs', 2);
        $this->assertDatabaseCount('salesforce_interest_reconciliations', 1);
    }

    public function test_processes_more_than_one_chunk_with_bounded_source_queries(): void
    {
        for ($index = 1; $index <= 201; $index++) {
            $lead = $this->lead(1000 + $index);
            $this->interest(1000 + $index, ['migration_origin_lead_id' => $lead->salesforce_id]);
        }

        DB::enableQueryLog();
        $stats = app(SalesforceInterestReconciliationService::class)->run('Synthetic multi chunk validation');
        $queries = collect(DB::getQueryLog())->pluck('query');

        $this->assertSame(201, $stats['leads_examined']);
        $this->assertSame(201, $stats['interests_examined']);
        $this->assertSame(201, $stats['exact']);
        $this->assertSame(201, $stats['rows_materialized']);
        $this->assertGreaterThanOrEqual(2, $queries->filter(
            fn (string $sql): bool => str_contains($sql, 'from "salesforce_leads"')
                && str_contains($sql, '"id" > ?'),
        )->count());
        $leadSelects = $queries->filter(fn (string $sql): bool => str_starts_with(strtolower($sql), 'select')
            && str_contains($sql, 'from "salesforce_leads"'));
        $interestSelects = $queries->filter(fn (string $sql): bool => str_starts_with(strtolower($sql), 'select')
            && str_contains($sql, 'from "salesforce_interests"'));
        $this->assertLessThanOrEqual(6, $leadSelects->count());
        $this->assertLessThanOrEqual(6, $interestSelects->count());
        $this->assertFalse($queries->contains(fn (string $sql): bool => str_contains(strtolower($sql), ' offset ')));
    }

    public function test_resolves_master_outside_the_origin_lead_chunk(): void
    {
        $finalMasterId = $this->leadId(4999);
        $origin = $this->lead(4000, ['salesforce_master_record_id' => $finalMasterId]);
        for ($index = 1; $index <= 199; $index++) {
            $this->lead(4000 + $index);
        }
        $finalMaster = $this->lead(4999);
        $this->interest(4000, [
            'migration_origin_lead_id' => $origin->salesforce_id,
            'lead_salesforce_id' => $finalMaster->salesforce_id,
        ]);

        app(SalesforceInterestReconciliationService::class)->run('Synthetic cross chunk master validation');

        $this->assertDatabaseHas('salesforce_interest_reconciliations', [
            'subject_salesforce_id' => $origin->salesforce_id,
            'master_status' => 'direct',
            'resolved_master_lead_id' => $finalMaster->salesforce_id,
            'current_lead_alignment' => 'matches_master',
            'has_conflict' => false,
        ]);
    }

    public function test_expands_loaded_master_to_resolve_next_master_outside_the_chunk(): void
    {
        $middleId = $this->leadId(9001);
        $finalId = $this->leadId(9999);
        $origin = $this->lead(9000, ['salesforce_master_record_id' => $middleId]);
        $middle = $this->lead(9001, ['salesforce_master_record_id' => $finalId]);
        for ($index = 2; $index < 200; $index++) {
            $this->lead(9000 + $index);
        }
        $final = $this->lead(9999);
        $this->interest(9000, [
            'migration_origin_lead_id' => $origin->salesforce_id,
            'lead_salesforce_id' => $final->salesforce_id,
        ]);

        app(SalesforceInterestReconciliationService::class)->run('Synthetic loaded frontier expansion validation');

        $this->assertDatabaseHas('salesforce_interest_reconciliations', [
            'subject_salesforce_id' => $origin->salesforce_id,
            'immediate_master_lead_id' => $middle->salesforce_id,
            'resolved_master_lead_id' => $final->salesforce_id,
            'master_status' => 'chain',
            'current_lead_alignment' => 'matches_master',
            'has_conflict' => false,
        ]);
    }

    public function test_failure_in_intermediate_chunk_never_publishes_partial_snapshot(): void
    {
        for ($index = 1; $index <= 201; $index++) {
            $this->lead(2000 + $index);
        }
        $service = new class extends SalesforceInterestReconciliationService
        {
            private int $chunks = 0;

            protected function afterLeadChunk(int $cursor, array $stats): void
            {
                $this->chunks++;
                if ($this->chunks === 1) {
                    throw new RuntimeException('Synthetic sensitive failure');
                }
            }
        };

        try {
            $service->run('Synthetic partial failure validation');
            $this->fail('The reconciliation must fail after the injected chunk error.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Interest reconciliation failed safely.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }

        $run = SalesforceInterestReconciliationRun::query()->sole();
        $this->assertSame('failed', $run->status);
        $this->assertSame('Interest reconciliation failed safely.', $run->error_message);
        $this->assertSame(200, $run->stats['last_lead_id_processed']);
        $this->assertDatabaseCount('salesforce_interest_reconciliations', 200);
        $this->assertDatabaseMissing('salesforce_interest_reconciliation_runs', ['status' => 'completed']);
    }

    public function test_two_failed_runs_keep_only_latest_partial_detail_and_preserve_both_run_metrics(): void
    {
        for ($index = 1; $index <= 201; $index++) {
            $this->lead(5000 + $index);
        }

        $this->runFailingService('First synthetic failed snapshot');
        $firstRun = SalesforceInterestReconciliationRun::query()->sole();
        $this->assertSame(200, SalesforceInterestReconciliation::query()->count());

        $this->runFailingService('Second synthetic failed snapshot');
        $runs = SalesforceInterestReconciliationRun::query()->orderBy('id')->get();

        $this->assertCount(2, $runs);
        $this->assertNotNull($runs[0]->stats);
        $this->assertNotNull($runs[1]->stats);
        $this->assertSame(200, SalesforceInterestReconciliation::query()->count());
        $this->assertSame(0, SalesforceInterestReconciliation::query()
            ->where('reconciliation_run_id', $firstRun->id)->count());
        $this->assertSame(200, SalesforceInterestReconciliation::query()
            ->where('reconciliation_run_id', $runs[1]->id)->count());
    }

    public function test_success_after_failure_keeps_only_new_completed_snapshot_detail(): void
    {
        for ($index = 1; $index <= 201; $index++) {
            $this->lead(6000 + $index);
        }

        $this->runFailingService('Synthetic failed snapshot before success');
        $stats = app(SalesforceInterestReconciliationService::class)
            ->run('Synthetic completed snapshot after failure');
        $runs = SalesforceInterestReconciliationRun::query()->orderBy('id')->get();

        $this->assertSame(201, $stats['rows_materialized']);
        $this->assertSame(['failed', 'completed'], $runs->pluck('status')->all());
        $this->assertSame(201, SalesforceInterestReconciliation::query()->count());
        $this->assertSame(0, SalesforceInterestReconciliation::query()
            ->where('reconciliation_run_id', $runs[0]->id)->count());
        $this->assertSame(201, SalesforceInterestReconciliation::query()
            ->where('reconciliation_run_id', $runs[1]->id)->count());
    }

    public function test_superseded_snapshot_cleanup_uses_multiple_bounded_chunks(): void
    {
        $oldRun = SalesforceInterestReconciliationRun::query()->create([
            'run_identifier' => '00000000-0000-4000-8000-000000000001',
            'reason' => 'Synthetic completed snapshot to supersede',
            'status' => 'completed',
            'started_at' => now()->subMinute(),
            'completed_at' => now()->subMinute(),
            'stats' => ['rows_materialized' => 1001],
        ]);
        foreach (array_chunk($this->syntheticResolutionRows($oldRun->id, 1001), 200) as $rows) {
            SalesforceInterestReconciliation::query()->insert($rows);
        }
        $this->lead(7000);
        $service = new class extends SalesforceInterestReconciliationService
        {
            public int $cleanupChunks = 0;

            protected function afterCleanupChunk(int $rowsDeleted): void
            {
                $this->cleanupChunks++;
            }
        };

        $service->run('Synthetic bounded cleanup validation');
        $currentRun = SalesforceInterestReconciliationRun::query()->latest('id')->firstOrFail();

        $this->assertSame(2, $service->cleanupChunks);
        $this->assertSame(0, SalesforceInterestReconciliation::query()
            ->where('reconciliation_run_id', $oldRun->id)->count());
        $this->assertSame(1, SalesforceInterestReconciliation::query()
            ->where('reconciliation_run_id', $currentRun->id)->count());
    }

    public function test_cleanup_failure_after_publication_keeps_completed_snapshot_and_next_run_finishes_cleanup(): void
    {
        $oldRun = SalesforceInterestReconciliationRun::query()->create([
            'run_identifier' => '00000000-0000-4000-8000-000000000002',
            'reason' => 'Synthetic completed snapshot with interrupted cleanup',
            'status' => 'completed',
            'started_at' => now()->subMinute(),
            'completed_at' => now()->subMinute(),
            'stats' => ['rows_materialized' => 1001],
        ]);
        foreach (array_chunk($this->syntheticResolutionRows($oldRun->id, 1001), 200) as $rows) {
            SalesforceInterestReconciliation::query()->insert($rows);
        }
        $this->lead(7100);
        $service = new class extends SalesforceInterestReconciliationService
        {
            private bool $failed = false;

            protected function afterCleanupChunk(int $rowsDeleted): void
            {
                if (! $this->failed) {
                    $this->failed = true;
                    throw new RuntimeException('Synthetic cleanup failure with sensitive detail');
                }
            }
        };

        $stats = $service->run('Synthetic published snapshot cleanup failure');
        $publishedRun = SalesforceInterestReconciliationRun::query()->latest('id')->firstOrFail();

        $this->assertSame('completed', $publishedRun->status);
        $this->assertNull($publishedRun->error_message);
        $this->assertSame(1, $stats['cleanup_errors']);
        $this->assertSame(1, $publishedRun->stats['cleanup_errors']);
        $this->assertSame(1, SalesforceInterestReconciliation::query()
            ->where('reconciliation_run_id', $publishedRun->id)->count());
        $this->assertSame(1, SalesforceInterestReconciliation::query()
            ->where('reconciliation_run_id', $oldRun->id)->count());

        $nextStats = app(SalesforceInterestReconciliationService::class)
            ->run('Synthetic retry of pending snapshot cleanup');
        $latestRun = SalesforceInterestReconciliationRun::query()->latest('id')->firstOrFail();

        $this->assertSame(0, $nextStats['cleanup_errors']);
        $this->assertSame('completed', $latestRun->status);
        $this->assertSame(1, SalesforceInterestReconciliation::query()->count());
        $this->assertSame(1, SalesforceInterestReconciliation::query()
            ->where('reconciliation_run_id', $latestRun->id)->count());
        $this->assertSame(0, SalesforceInterestReconciliation::query()
            ->whereIn('reconciliation_run_id', [$oldRun->id, $publishedRun->id])->count());
    }

    public function test_failed_run_retries_completed_cleanup_residue_without_removing_last_valid_snapshot(): void
    {
        $oldRun = SalesforceInterestReconciliationRun::query()->create([
            'run_identifier' => '00000000-0000-4000-8000-000000000004',
            'reason' => 'Synthetic superseded snapshot with cleanup residue',
            'status' => 'completed',
            'started_at' => now()->subMinutes(2),
            'completed_at' => now()->subMinutes(2),
            'stats' => ['rows_materialized' => 1001],
        ]);
        foreach (array_chunk($this->syntheticResolutionRows($oldRun->id, 1001), 200) as $rows) {
            SalesforceInterestReconciliation::query()->insert($rows);
        }
        for ($index = 1; $index <= 201; $index++) {
            $this->lead(7300 + $index);
        }

        $cleanupFailingService = new class extends SalesforceInterestReconciliationService
        {
            private bool $failed = false;

            protected function afterCleanupChunk(int $rowsDeleted): void
            {
                if (! $this->failed) {
                    $this->failed = true;
                    throw new RuntimeException('Synthetic cleanup failure with sensitive detail');
                }
            }
        };

        $publishedStats = $cleanupFailingService->run('Synthetic completed snapshot before failed retry');
        $publishedRun = SalesforceInterestReconciliationRun::query()->latest('id')->firstOrFail();

        $this->assertSame('completed', $publishedRun->status);
        $this->assertSame(1, $publishedStats['cleanup_errors']);
        $this->assertSame(201, SalesforceInterestReconciliation::query()
            ->where('reconciliation_run_id', $publishedRun->id)->count());
        $this->assertSame(1, SalesforceInterestReconciliation::query()
            ->where('reconciliation_run_id', $oldRun->id)->count());

        $this->runFailingService('Synthetic failed run retries pending completed cleanup');
        $failedRun = SalesforceInterestReconciliationRun::query()->latest('id')->firstOrFail();

        $this->assertSame('failed', $failedRun->status);
        $this->assertSame('completed', $publishedRun->fresh()->status);
        $this->assertSame(201, SalesforceInterestReconciliation::query()
            ->where('reconciliation_run_id', $publishedRun->id)->count());
        $this->assertSame(200, SalesforceInterestReconciliation::query()
            ->where('reconciliation_run_id', $failedRun->id)->count());
        $this->assertSame(0, SalesforceInterestReconciliation::query()
            ->where('reconciliation_run_id', $oldRun->id)->count());
        $this->assertSame(2, SalesforceInterestReconciliation::query()
            ->distinct()->count('reconciliation_run_id'));
    }

    public function test_failed_run_without_previous_completed_keeps_only_its_own_partial_detail(): void
    {
        for ($index = 1; $index <= 201; $index++) {
            $this->lead(7600 + $index);
        }

        $this->runFailingService('Synthetic old failed detail without completed snapshot');
        $oldFailedRun = SalesforceInterestReconciliationRun::query()->latest('id')->firstOrFail();
        $this->runFailingService('Synthetic current failed detail without completed snapshot');
        $currentFailedRun = SalesforceInterestReconciliationRun::query()->latest('id')->firstOrFail();

        $this->assertSame(0, SalesforceInterestReconciliation::query()
            ->where('reconciliation_run_id', $oldFailedRun->id)->count());
        $this->assertSame(200, SalesforceInterestReconciliation::query()
            ->where('reconciliation_run_id', $currentFailedRun->id)->count());
        $this->assertSame(1, SalesforceInterestReconciliation::query()
            ->distinct()->count('reconciliation_run_id'));
    }

    public function test_failed_run_removes_multiple_completed_residues_except_latest_valid_snapshot(): void
    {
        $olderCompleted = $this->completedRunWithSyntheticDetail(
            '00000000-0000-4000-8000-000000000005',
            now()->subMinutes(3),
        );
        $latestCompleted = $this->completedRunWithSyntheticDetail(
            '00000000-0000-4000-8000-000000000006',
            now()->subMinutes(2),
        );
        $this->lead(7900);

        $this->runFailingService('Synthetic failed run cleans multiple completed residues');
        $failedRun = SalesforceInterestReconciliationRun::query()->latest('id')->firstOrFail();

        $this->assertSame(0, SalesforceInterestReconciliation::query()
            ->where('reconciliation_run_id', $olderCompleted->id)->count());
        $this->assertSame(1, SalesforceInterestReconciliation::query()
            ->where('reconciliation_run_id', $latestCompleted->id)->count());
        $this->assertSame(1, SalesforceInterestReconciliation::query()
            ->where('reconciliation_run_id', $failedRun->id)->count());
    }

    public function test_cleanup_failure_during_failed_run_preserves_latest_completed_and_safe_error_boundary(): void
    {
        $olderCompleted = SalesforceInterestReconciliationRun::query()->create([
            'run_identifier' => '00000000-0000-4000-8000-000000000007',
            'reason' => 'Synthetic superseded snapshot before failed cleanup',
            'status' => 'completed',
            'started_at' => now()->subMinutes(3),
            'completed_at' => now()->subMinutes(3),
            'stats' => ['rows_materialized' => 1001],
        ]);
        foreach (array_chunk($this->syntheticResolutionRows($olderCompleted->id, 1001), 200) as $rows) {
            SalesforceInterestReconciliation::query()->insert($rows);
        }
        $latestCompleted = $this->completedRunWithSyntheticDetail(
            '00000000-0000-4000-8000-000000000008',
            now()->subMinutes(2),
        );
        $this->lead(7950);
        $service = new class extends SalesforceInterestReconciliationService
        {
            protected function afterLeadChunk(int $cursor, array $stats): void
            {
                throw new RuntimeException('Synthetic reconciliation secret');
            }

            protected function afterCleanupChunk(int $rowsDeleted): void
            {
                throw new RuntimeException('Synthetic cleanup secret');
            }
        };

        try {
            $service->run('Synthetic failed reconciliation with failed cleanup');
            $this->fail('The reconciliation must fail at the safe service boundary.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Interest reconciliation failed safely.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        $failedRun = SalesforceInterestReconciliationRun::query()->latest('id')->firstOrFail();

        $this->assertSame('failed', $failedRun->status);
        $this->assertSame('Interest reconciliation failed safely.', $failedRun->error_message);
        $this->assertSame(1, SalesforceInterestReconciliation::query()
            ->where('reconciliation_run_id', $latestCompleted->id)->count());
        $this->assertSame(1, SalesforceInterestReconciliation::query()
            ->where('reconciliation_run_id', $failedRun->id)->count());
        $this->assertSame(1, SalesforceInterestReconciliation::query()
            ->where('reconciliation_run_id', $olderCompleted->id)->count());
    }

    public function test_reconciliation_failure_preserves_previous_completed_snapshot_as_valid(): void
    {
        $completedRun = SalesforceInterestReconciliationRun::query()->create([
            'run_identifier' => '00000000-0000-4000-8000-000000000003',
            'reason' => 'Synthetic valid snapshot before reconciliation failure',
            'status' => 'completed',
            'started_at' => now()->subMinute(),
            'completed_at' => now()->subMinute(),
            'stats' => ['rows_materialized' => 1],
        ]);
        SalesforceInterestReconciliation::query()->insert(
            $this->syntheticResolutionRows($completedRun->id, 1),
        );
        $this->lead(7200);

        $this->runFailingService('Synthetic construction failure after valid snapshot');
        $failedRun = SalesforceInterestReconciliationRun::query()->latest('id')->firstOrFail();

        $this->assertSame('failed', $failedRun->status);
        $this->assertSame('completed', $completedRun->fresh()->status);
        $this->assertSame(1, SalesforceInterestReconciliation::query()
            ->where('reconciliation_run_id', $completedRun->id)->count());
    }

    public function test_lock_prevents_concurrent_persistent_reconciliation(): void
    {
        $lock = Cache::lock(SalesforceInterestReconciliationService::LOCK_KEY, 60);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('salesforce:reconcile-interests-local', [
                '--reason' => 'Synthetic concurrent reconciliation',
            ])->expectsOutputToContain('Ya existe otra reconciliación')->assertFailed();
        } finally {
            $lock->release();
        }

        $this->assertDatabaseCount('salesforce_interest_reconciliation_runs', 0);
    }

    public function test_command_requires_reason_and_outputs_only_aggregate_metrics(): void
    {
        $this->artisan('salesforce:reconcile-interests-local')->assertFailed();
        $this->lead(40);

        $this->artisan('salesforce:reconcile-interests-local', [
            '--reason' => 'Synthetic manual command validation',
        ])->expectsOutputToContain('INTEREST_RECONCILIATION_METRICS=')
            ->assertSuccessful();
    }

    private function lead(int $sequence, array $overrides = []): SalesforceLead
    {
        return SalesforceLead::query()->create(array_replace([
            'salesforce_id' => $this->leadId($sequence),
            'created_date' => '2026-01-01 10:00:00',
            'is_deleted' => false,
            'salesforce_master_record_id' => null,
        ], $overrides));
    }

    private function interest(int $sequence, array $overrides = []): SalesforceInterest
    {
        return SalesforceInterest::query()->create(array_replace([
            'salesforce_id' => 'a01'.str_pad((string) $sequence, 15, '0', STR_PAD_LEFT),
            'salesforce_created_at' => '2026-01-01 10:00:00',
            'salesforce_last_modified_at' => '2026-01-01 10:00:00',
            'migration_origin_lead_id' => null,
            'lead_salesforce_id' => null,
            'account_salesforce_id' => null,
            'is_deleted' => false,
        ], $overrides));
    }

    private function leadId(int $sequence): string
    {
        return '00Q'.str_pad((string) $sequence, 15, '0', STR_PAD_LEFT);
    }

    private function logicalRows(): array
    {
        return SalesforceInterestReconciliation::query()
            ->orderBy('subject_type')
            ->orderBy('subject_salesforce_id')
            ->get()
            ->map(fn (SalesforceInterestReconciliation $row): array => [
                'subject_type' => $row->subject_type,
                'subject_salesforce_id' => $row->subject_salesforce_id,
                'lead_salesforce_id' => $row->lead_salesforce_id,
                'interest_salesforce_id' => $row->interest_salesforce_id,
                'relationship_status' => $row->relationship_status,
                'master_status' => $row->master_status,
                'canonical_person_status' => $row->canonical_person_status,
                'has_conflict' => $row->has_conflict,
            ])->all();
    }

    private function runFailingService(string $reason): void
    {
        $service = new class extends SalesforceInterestReconciliationService
        {
            protected function afterLeadChunk(int $cursor, array $stats): void
            {
                throw new RuntimeException('Synthetic failed reconciliation');
            }
        };

        try {
            $service->run($reason);
        } catch (RuntimeException) {
        }
    }

    private function completedRunWithSyntheticDetail(
        string $identifier,
        \DateTimeInterface $completedAt,
    ): SalesforceInterestReconciliationRun {
        $run = SalesforceInterestReconciliationRun::query()->create([
            'run_identifier' => $identifier,
            'reason' => 'Synthetic completed snapshot retention fixture',
            'status' => 'completed',
            'started_at' => $completedAt,
            'completed_at' => $completedAt,
            'stats' => ['rows_materialized' => 1],
        ]);
        SalesforceInterestReconciliation::query()->insert(
            $this->syntheticResolutionRows($run->id, 1),
        );

        return $run;
    }

    /** @return list<array<string, mixed>> */
    private function syntheticResolutionRows(int $runId, int $count): array
    {
        $rows = [];
        $now = now();

        for ($index = 1; $index <= $count; $index++) {
            $salesforceId = '00Q'.str_pad((string) (8000 + $index), 15, '0', STR_PAD_LEFT);
            $rows[] = [
                'reconciliation_run_id' => $runId,
                'subject_type' => 'lead',
                'subject_salesforce_id' => $salesforceId,
                'lead_salesforce_id' => $salesforceId,
                'interest_salesforce_id' => null,
                'migration_origin_lead_id' => null,
                'immediate_master_lead_id' => null,
                'resolved_master_lead_id' => null,
                'relationship_status' => 'lead_without_interest',
                'master_status' => 'none',
                'canonical_person_status' => 'not_applicable',
                'current_lead_alignment' => 'not_applicable',
                'lead_is_deleted' => false,
                'interest_is_deleted' => null,
                'has_conflict' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }
}
