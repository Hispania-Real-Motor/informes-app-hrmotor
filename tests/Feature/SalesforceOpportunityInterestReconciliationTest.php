<?php

namespace Tests\Feature;

use App\Models\ReportSyncRun;
use App\Models\SalesforceInterest;
use App\Models\SalesforceInterestOpportunityReconciliation;
use App\Models\SalesforceInterestOpportunityReconciliationRun;
use App\Models\SalesforceOpportunityInterestDirect;
use App\Models\SalesforceOpportunityInterestDirectRun;
use App\Models\SalesforceOpportunityInterestReconciliation;
use App\Models\SalesforceOpportunityInterestReconciliationRun;
use App\Services\Salesforce\SalesforceInterestSyncService;
use App\Services\Salesforce\SalesforceOpportunityInterestReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class SalesforceOpportunityInterestReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_preserves_both_evidences_cutoffs_cardinality_and_minimal_indexes(): void
    {
        $this->assertTrue(Schema::hasColumns('salesforce_opportunity_interest_reconciliation_runs', [
            'run_identifier', 'reason', 'status', 'direct_run_id', 'direct_cutoff_at',
            'inverse_run_id', 'inverse_interest_sync_run_id', 'inverse_interest_cutoff_at',
            'started_at', 'completed_at', 'stats', 'error_message',
        ]));
        $this->assertTrue(Schema::hasColumns('salesforce_opportunity_interest_reconciliations', [
            'reconciliation_run_id', 'opportunity_salesforce_id', 'direct_interest_salesforce_id',
            'inverse_interest_salesforce_ids', 'inverse_reference_count', 'relationship_status',
            'direct_reference_status', 'direct_interest_presence_status',
            'direct_interest_is_deleted', 'direct_opportunity_is_deleted',
            'inverse_opportunity_is_deleted', 'inverse_opportunity_presence_status',
            'requires_review',
        ]));
        $columns = Schema::getColumnListing('salesforce_opportunity_interest_reconciliations');
        $this->assertSame([], array_intersect($columns, [
            'name', 'account_id', 'lead_id', 'owner_id', 'email', 'phone', 'vehicle_id',
            'amount', 'raw_payload',
        ]));
        $indexes = collect(DB::select(
            "PRAGMA index_list('salesforce_opportunity_interest_reconciliations')",
        ))->pluck('name');
        $this->assertTrue($indexes->contains('sf_opp_int_recon_run_opp_uq'));
        $this->assertTrue($indexes->contains('sf_opp_int_recon_run_relation_idx'));
        $this->assertTrue($indexes->contains('sf_opp_int_recon_run_interest_idx'));
    }

    public function test_reconciles_match_direct_only_inverse_only_contradiction_shared_lifecycle_and_resolution(): void
    {
        $directRun = $this->directRun();
        $inverseRun = $this->inverseRun();
        $interestA = $this->interest(1);
        $interestB = $this->interest(2);
        $interestC = $this->interest(3);
        $interestD = $this->interest(4);
        $interestE = $this->interest(5);
        $interestF = $this->interest(6, true);
        $interestG = $this->interest(7);
        $opportunity1 = $this->opportunityId(1);
        $opportunity2 = $this->opportunityId(2);
        $opportunity3 = $this->opportunityId(3);
        $opportunity4 = $this->opportunityId(4);
        $opportunity5 = $this->opportunityId(5);
        $opportunity6 = $this->opportunityId(6);
        $opportunity7 = $this->opportunityId(7);
        $opportunity8 = $this->opportunityId(8);

        $this->direct($directRun, $opportunity1, $interestA->salesforce_id);
        $this->inverse($inverseRun, $opportunity1, $interestA->salesforce_id);
        $this->direct($directRun, $opportunity2, $interestB->salesforce_id);
        $this->inverse($inverseRun, $opportunity3, $interestC->salesforce_id);
        $this->direct($directRun, $opportunity4, $interestD->salesforce_id);
        $this->inverse($inverseRun, $opportunity4, $interestE->salesforce_id);
        $this->direct($directRun, $opportunity5, $interestF->salesforce_id);
        $this->inverse($inverseRun, $opportunity5, $interestF->salesforce_id);
        $this->inverse($inverseRun, $opportunity5, $interestG->salesforce_id);
        $this->direct($directRun, $opportunity6, substr($this->interestId(60), 0, 15), false, 'invalid');
        $this->direct($directRun, $opportunity7, $this->interestId(70), true);
        $this->direct($directRun, $opportunity8, $interestA->salesforce_id);

        $directBefore = DB::table('salesforce_opportunity_interest_directs')->orderBy('id')->get()->all();
        $inverseBefore = DB::table('salesforce_interest_opportunity_reconciliations')->orderBy('id')->get()->all();
        $interestsBefore = DB::table('salesforce_interests')->orderBy('id')->get()->all();

        $stats = app(SalesforceOpportunityInterestReconciliationService::class)
            ->run('Certify all bidirectional Opportunity Interest states');
        $run = SalesforceOpportunityInterestReconciliationRun::query()->latest('id')->firstOrFail();

        $this->assertSame('completed', $run->status);
        $this->assertSame($directRun->id, $run->direct_run_id);
        $this->assertSame($inverseRun->id, $run->inverse_run_id);
        $this->assertFalse($run->direct_cutoff_at->equalTo($run->inverse_interest_cutoff_at));
        $this->assertResolution($run, $opportunity1, 'both_match', false, 1);
        $this->assertResolution($run, $opportunity2, 'direct_only', true, 0);
        $this->assertResolution($run, $opportunity3, 'inverse_only', true, 1);
        $this->assertResolution($run, $opportunity4, 'contradiction', true, 1);
        $this->assertResolution($run, $opportunity5, 'inverse_shared', true, 2);
        $this->assertResolution($run, $opportunity6, 'unresolved', true, 0);
        $this->assertResolution($run, $opportunity7, 'direct_only', true, 0);
        $this->assertResolution($run, $opportunity8, 'direct_only', true, 0);
        $this->assertDatabaseHas('salesforce_opportunity_interest_reconciliations', [
            'reconciliation_run_id' => $run->id,
            'opportunity_salesforce_id' => $opportunity7,
            'direct_interest_presence_status' => 'not_local',
            'direct_opportunity_is_deleted' => true,
            'inverse_opportunity_is_deleted' => null,
        ]);
        $this->assertDatabaseHas('salesforce_opportunity_interest_reconciliations', [
            'reconciliation_run_id' => $run->id,
            'opportunity_salesforce_id' => $opportunity5,
            'direct_interest_is_deleted' => true,
        ]);
        $this->assertSame(8, $stats['opportunities_examined']);
        $this->assertSame(1, $stats['both_match']);
        $this->assertSame(3, $stats['direct_only']);
        $this->assertSame(1, $stats['inverse_only']);
        $this->assertSame(1, $stats['contradiction']);
        $this->assertSame(1, $stats['inverse_shared']);
        $this->assertSame(1, $stats['unresolved']);
        $this->assertSame(1, $stats['direct_opportunities_deleted']);
        $this->assertSame(0, $stats['inverse_opportunities_deleted']);
        $this->assertSame(0, $stats['lifecycle_mismatches']);
        $this->assertSame(1, $stats['direct_interests_not_local']);
        $this->assertSame(1, $stats['invalid_references']);
        $this->assertEquals($directBefore, DB::table('salesforce_opportunity_interest_directs')->orderBy('id')->get()->all());
        $this->assertEquals($inverseBefore, DB::table('salesforce_interest_opportunity_reconciliations')->orderBy('id')->get()->all());
        $this->assertEquals($interestsBefore, DB::table('salesforce_interests')->orderBy('id')->get()->all());
    }

    public function test_same_interest_may_be_referenced_by_multiple_direct_opportunities_without_constraint(): void
    {
        $directRun = $this->directRun();
        $inverseRun = $this->inverseRun();
        $interest = $this->interest(10);
        $this->direct($directRun, $this->opportunityId(10), $interest->salesforce_id);
        $this->direct($directRun, $this->opportunityId(11), $interest->salesforce_id);

        $stats = app(SalesforceOpportunityInterestReconciliationService::class)
            ->run('Preserve observed cardinality without enforcing one to one');

        $this->assertSame(2, $stats['direct_only']);
        $this->assertSame(2, SalesforceOpportunityInterestReconciliation::query()->count());
        $this->assertSame(2, SalesforceOpportunityInterestReconciliation::query()
            ->where('direct_interest_salesforce_id', $interest->salesforce_id)->count());
        $this->assertSame('completed', SalesforceInterestOpportunityReconciliationRun::findOrFail($inverseRun->id)->status);
    }

    public function test_preserves_direct_and_inverse_opportunity_lifecycle_independently(): void
    {
        $directRun = $this->directRun();
        $inverseRun = $this->inverseRun();
        $cases = [
            1 => [false, false, 'both_match'],
            2 => [true, true, 'both_match'],
            3 => [false, true, 'both_match'],
            4 => [true, false, 'both_match'],
        ];
        foreach ($cases as $sequence => [$directDeleted, $inverseDeleted]) {
            $interest = $this->interest($sequence);
            $opportunityId = $this->opportunityId($sequence);
            $this->direct($directRun, $opportunityId, $interest->salesforce_id, $directDeleted);
            $this->inverse($inverseRun, $opportunityId, $interest->salesforce_id, $inverseDeleted);
        }
        $directOnlyInterest = $this->interest(5);
        $this->direct($directRun, $this->opportunityId(5), $directOnlyInterest->salesforce_id, true);
        $inverseOnlyInterest = $this->interest(6);
        $this->inverse($inverseRun, $this->opportunityId(6), $inverseOnlyInterest->salesforce_id, true);

        $stats = app(SalesforceOpportunityInterestReconciliationService::class)
            ->run('Preserve independent Opportunity lifecycle evidence');
        $run = SalesforceOpportunityInterestReconciliationRun::query()->latest('id')->firstOrFail();

        foreach ($cases as $sequence => [$directDeleted, $inverseDeleted, $relationship]) {
            $this->assertDatabaseHas('salesforce_opportunity_interest_reconciliations', [
                'reconciliation_run_id' => $run->id,
                'opportunity_salesforce_id' => $this->opportunityId($sequence),
                'relationship_status' => $relationship,
                'direct_opportunity_is_deleted' => $directDeleted,
                'inverse_opportunity_is_deleted' => $inverseDeleted,
                'inverse_opportunity_presence_status' => $inverseDeleted
                    ? 'present_deleted'
                    : 'present_active',
            ]);
        }
        $this->assertDatabaseHas('salesforce_opportunity_interest_reconciliations', [
            'reconciliation_run_id' => $run->id,
            'opportunity_salesforce_id' => $this->opportunityId(1),
            'relationship_status' => 'both_match',
            'requires_review' => false,
        ]);
        foreach ([3, 4] as $sequence) {
            $this->assertDatabaseHas('salesforce_opportunity_interest_reconciliations', [
                'reconciliation_run_id' => $run->id,
                'opportunity_salesforce_id' => $this->opportunityId($sequence),
                'relationship_status' => 'both_match',
                'requires_review' => true,
            ]);
        }
        $this->assertDatabaseHas('salesforce_opportunity_interest_reconciliations', [
            'reconciliation_run_id' => $run->id,
            'opportunity_salesforce_id' => $this->opportunityId(5),
            'relationship_status' => 'direct_only',
            'direct_opportunity_is_deleted' => true,
            'inverse_opportunity_is_deleted' => null,
            'inverse_opportunity_presence_status' => null,
        ]);
        $this->assertDatabaseHas('salesforce_opportunity_interest_reconciliations', [
            'reconciliation_run_id' => $run->id,
            'opportunity_salesforce_id' => $this->opportunityId(6),
            'relationship_status' => 'inverse_only',
            'direct_opportunity_is_deleted' => null,
            'inverse_opportunity_is_deleted' => true,
            'inverse_opportunity_presence_status' => 'present_deleted',
        ]);
        $this->assertSame(3, $stats['direct_opportunities_deleted']);
        $this->assertSame(3, $stats['inverse_opportunities_deleted']);
        $this->assertSame(2, $stats['lifecycle_mismatches']);
        $this->assertSame(4, $stats['both_match']);
    }

    public function test_inverse_presence_and_review_signal_do_not_change_matching_identity(): void
    {
        $directRun = $this->directRun();
        $inverseRun = $this->inverseRun();
        $cases = [
            1 => ['present_active', false, false],
            2 => ['salesforce_missing', false, true],
            3 => ['present_unresolved', false, true],
            4 => ['present_active', true, true],
        ];
        foreach ($cases as $sequence => [$presence, $inverseReview]) {
            $interest = $this->interest($sequence);
            $opportunityId = $this->opportunityId($sequence);
            $this->direct($directRun, $opportunityId, $interest->salesforce_id);
            $this->inverse(
                $inverseRun,
                $opportunityId,
                $interest->salesforce_id,
                presenceStatus: $presence,
                requiresReview: $inverseReview,
            );
        }

        $stats = app(SalesforceOpportunityInterestReconciliationService::class)
            ->run('Preserve inverse evidence quality independently from identity');
        $run = SalesforceOpportunityInterestReconciliationRun::query()->latest('id')->firstOrFail();

        foreach ($cases as $sequence => [$presence, $inverseReview, $expectedReview]) {
            $this->assertDatabaseHas('salesforce_opportunity_interest_reconciliations', [
                'reconciliation_run_id' => $run->id,
                'opportunity_salesforce_id' => $this->opportunityId($sequence),
                'relationship_status' => 'both_match',
                'inverse_opportunity_presence_status' => $presence,
                'requires_review' => $expectedReview,
            ]);
        }
        $this->assertSame(4, $stats['both_match']);
        $this->assertSame(3, $stats['requires_review']);
        $this->assertSame(0, $stats['contradiction']);
    }

    public function test_rejects_inverse_snapshot_when_latest_interest_run_is_newer_running_or_failed(): void
    {
        foreach (['completed', 'running', 'failed'] as $status) {
            ReportSyncRun::query()->delete();
            SalesforceInterestOpportunityReconciliationRun::query()->delete();
            SalesforceOpportunityInterestDirectRun::query()->delete();
            $this->directRun();
            $this->inverseRun();
            ReportSyncRun::query()->create([
                'dataset' => SalesforceInterestSyncService::DATASET,
                'source' => SalesforceInterestSyncService::SOURCE,
                'status' => $status,
                'source_cutoff_at' => '2026-10-06 11:00:00',
                'started_at' => '2026-10-06 10:59:00',
                'completed_at' => $status === 'running' ? null : '2026-10-06 11:00:01',
                'timezone' => 'UTC',
            ]);

            try {
                app(SalesforceOpportunityInterestReconciliationService::class)
                    ->run('Reject stale inverse Interest source metadata');
                $this->fail("Expected latest Interest run {$status} to block reconciliation.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('not current', $exception->getMessage());
            }
            $this->assertDatabaseMissing('salesforce_opportunity_interest_reconciliation_runs', [
                'status' => 'completed',
            ]);
        }
    }

    public function test_interest_run_starting_after_materialization_fails_and_preserves_previous_completed(): void
    {
        $directRun = $this->directRun();
        $inverseRun = $this->inverseRun();
        $interest = $this->interest(30);
        $this->direct($directRun, $this->opportunityId(30), $interest->salesforce_id);
        $completed = $this->completedReconciliationRun($directRun, $inverseRun, 30);
        $this->reconciliation($completed, $this->opportunityId(90));
        $service = new class extends SalesforceOpportunityInterestReconciliationService
        {
            protected function afterSnapshotBuilt(
                SalesforceOpportunityInterestReconciliationRun $run,
                array $stats,
            ): void {
                ReportSyncRun::query()->create([
                    'dataset' => SalesforceInterestSyncService::DATASET,
                    'source' => SalesforceInterestSyncService::SOURCE,
                    'status' => 'running',
                    'started_at' => now('UTC'),
                    'timezone' => 'UTC',
                ]);
            }
        };

        try {
            $service->run('Detect concurrent Interest synchronization before publish');
            $this->fail('Expected concurrent Interest run to fail reconciliation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Bidirectional Opportunity Interest reconciliation failed safely.', $exception->getMessage());
        }

        $this->assertSame('failed', SalesforceOpportunityInterestReconciliationRun::query()->latest('id')->value('status'));
        $this->assertDatabaseHas('salesforce_opportunity_interest_reconciliations', [
            'reconciliation_run_id' => $completed->id,
        ]);
    }

    public function test_identical_reexecution_produces_the_same_logical_snapshot(): void
    {
        $directRun = $this->directRun();
        $inverseRun = $this->inverseRun();
        $interest = $this->interest(20);
        $opportunityId = $this->opportunityId(20);
        $this->direct($directRun, $opportunityId, $interest->salesforce_id);
        $this->inverse($inverseRun, $opportunityId, $interest->salesforce_id);
        $service = app(SalesforceOpportunityInterestReconciliationService::class);

        $service->run('Build first logically idempotent bidirectional snapshot');
        $first = SalesforceOpportunityInterestReconciliation::query()
            ->select($this->logicalColumns())
            ->orderBy('opportunity_salesforce_id')
            ->get()
            ->toArray();
        $service->run('Build second logically idempotent bidirectional snapshot');
        $second = SalesforceOpportunityInterestReconciliation::query()
            ->select($this->logicalColumns())
            ->orderBy('opportunity_salesforce_id')
            ->get()
            ->toArray();

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('salesforce_opportunity_interest_reconciliations', 1);
    }

    public function test_more_than_one_chunk_has_bounded_queries_and_no_offset(): void
    {
        $directRun = $this->directRun();
        $this->inverseRun();
        for ($index = 1; $index <= 201; $index++) {
            $interest = $this->interest($index);
            $this->direct($directRun, $this->opportunityId($index), $interest->salesforce_id);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $stats = app(SalesforceOpportunityInterestReconciliationService::class)
            ->run('Exercise bounded bidirectional reconciliation chunks');
        $queries = collect(DB::getQueryLog());

        $this->assertSame(201, $stats['opportunities_examined']);
        $this->assertSame(201, $stats['direct_only']);
        $this->assertLessThanOrEqual(8, $queries->filter(fn (array $query): bool => str_contains(
            strtolower($query['query']),
            'salesforce_opportunity_interest_directs',
        ))->count());
        $this->assertLessThanOrEqual(8, $queries->filter(fn (array $query): bool => str_contains(
            strtolower($query['query']),
            'salesforce_interests',
        ))->count());
        $this->assertFalse($queries->contains(fn (array $query): bool => str_contains(
            strtolower($query['query']),
            ' offset ',
        )));
    }

    public function test_source_change_during_build_fails_and_preserves_last_completed_snapshot(): void
    {
        $directRun = $this->directRun();
        $inverseRun = $this->inverseRun();
        $interest = $this->interest(1);
        $this->direct($directRun, $this->opportunityId(1), $interest->salesforce_id);
        $completed = $this->completedReconciliationRun($directRun, $inverseRun, 1);
        $this->reconciliation($completed, $this->opportunityId(90));
        $service = new class extends SalesforceOpportunityInterestReconciliationService
        {
            protected function afterSnapshotBuilt(
                SalesforceOpportunityInterestReconciliationRun $run,
                array $stats,
            ): void {
                SalesforceOpportunityInterestDirectRun::query()->create([
                    'run_identifier' => '60000000-0000-4000-8000-000000000099',
                    'reason' => 'Synthetic concurrent direct snapshot',
                    'status' => 'completed',
                    'source_cutoff_at' => '2026-10-06 12:00:00',
                    'started_at' => now()->subSecond(),
                    'completed_at' => now(),
                ]);
            }
        };

        try {
            $service->run('Reject evidence source change during reconciliation');
            $this->fail('Expected changed source to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Bidirectional Opportunity Interest reconciliation failed safely.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }

        $this->assertSame('failed', SalesforceOpportunityInterestReconciliationRun::query()->latest('id')->value('status'));
        $this->assertDatabaseHas('salesforce_opportunity_interest_reconciliations', [
            'reconciliation_run_id' => $completed->id,
        ]);
    }

    public function test_completed_cleanup_removes_superseded_detail_and_cleanup_failure_does_not_degrade_publication(): void
    {
        $directRun = $this->directRun();
        $inverseRun = $this->inverseRun();
        $interest = $this->interest(1);
        $this->direct($directRun, $this->opportunityId(1), $interest->salesforce_id);
        $old = $this->completedReconciliationRun($directRun, $inverseRun, 2);
        foreach (range(1, 1001) as $index) {
            $this->reconciliation($old, $this->opportunityId(1000 + $index));
        }
        $service = new class extends SalesforceOpportunityInterestReconciliationService
        {
            public int $cleanupChunks = 0;

            protected function afterCleanupChunk(int $rowsDeleted): void
            {
                $this->cleanupChunks++;
            }
        };
        $stats = $service->run('Remove superseded bidirectional detail in chunks');
        $current = SalesforceOpportunityInterestReconciliationRun::query()->latest('id')->firstOrFail();

        $this->assertSame(2, $service->cleanupChunks);
        $this->assertSame(0, $stats['cleanup_errors']);
        $this->assertDatabaseMissing('salesforce_opportunity_interest_reconciliations', [
            'reconciliation_run_id' => $old->id,
        ]);
        $this->assertDatabaseHas('salesforce_opportunity_interest_reconciliations', [
            'reconciliation_run_id' => $current->id,
        ]);

        $next = new class extends SalesforceOpportunityInterestReconciliationService
        {
            protected function afterCleanupChunk(int $rowsDeleted): void
            {
                throw new RuntimeException('sensitive cleanup detail');
            }
        };
        $published = $next->run('Keep published snapshot after cleanup failure');
        $publishedRun = SalesforceOpportunityInterestReconciliationRun::query()->latest('id')->firstOrFail();
        $this->assertSame('completed', $publishedRun->status);
        $this->assertSame(1, $published['cleanup_errors']);
        $this->assertNull($publishedRun->error_message);
    }

    public function test_command_requires_reason_and_lock_is_exclusive(): void
    {
        $this->artisan('salesforce:reconcile-opportunity-interests')->assertFailed();
        $lock = Cache::lock(SalesforceOpportunityInterestReconciliationService::LOCK_KEY, 60);
        $this->assertTrue($lock->get());
        try {
            $this->artisan('salesforce:reconcile-opportunity-interests', [
                '--reason' => 'Attempt while bidirectional reconciliation is locked',
            ])->assertFailed();
        } finally {
            $lock->release();
        }
    }

    private function assertResolution(
        SalesforceOpportunityInterestReconciliationRun $run,
        string $opportunityId,
        string $status,
        bool $review,
        int $inverseCount,
    ): void {
        $this->assertDatabaseHas('salesforce_opportunity_interest_reconciliations', [
            'reconciliation_run_id' => $run->id,
            'opportunity_salesforce_id' => $opportunityId,
            'relationship_status' => $status,
            'requires_review' => $review,
            'inverse_reference_count' => $inverseCount,
        ]);
    }

    private function directRun(): SalesforceOpportunityInterestDirectRun
    {
        return SalesforceOpportunityInterestDirectRun::query()->create([
            'run_identifier' => '70000000-0000-4000-8000-000000000001',
            'reason' => 'Synthetic direct evidence snapshot',
            'status' => 'completed',
            'source_cutoff_at' => '2026-10-06 10:00:00',
            'started_at' => '2026-10-06 09:59:00',
            'completed_at' => '2026-10-06 10:00:01',
        ]);
    }

    private function inverseRun(): SalesforceInterestOpportunityReconciliationRun
    {
        $interestRun = ReportSyncRun::query()->create([
            'dataset' => SalesforceInterestSyncService::DATASET,
            'source' => SalesforceInterestSyncService::SOURCE,
            'status' => 'completed',
            'source_cutoff_at' => '2026-10-06 09:00:00',
            'started_at' => '2026-10-06 08:59:00',
            'completed_at' => '2026-10-06 09:00:01',
            'timezone' => 'UTC',
        ]);

        return SalesforceInterestOpportunityReconciliationRun::query()->create([
            'run_identifier' => '80000000-0000-4000-8000-000000000001',
            'reason' => 'Synthetic inverse evidence snapshot',
            'status' => 'completed',
            'source_interest_sync_run_id' => $interestRun->id,
            'source_interest_cutoff_at' => $interestRun->source_cutoff_at,
            'source_opportunity_sync_run_id' => null,
            'source_opportunity_sync_status' => null,
            'source_opportunity_period_end_at' => null,
            'source_opportunity_completed_at' => null,
            'started_at' => '2026-10-06 09:00:00',
            'completed_at' => '2026-10-06 09:01:00',
        ]);
    }

    private function direct(
        SalesforceOpportunityInterestDirectRun $run,
        string $opportunityId,
        string $interestId,
        bool $deleted = false,
        string $referenceStatus = 'valid',
    ): SalesforceOpportunityInterestDirect {
        return SalesforceOpportunityInterestDirect::query()->create([
            'direct_run_id' => $run->id,
            'opportunity_salesforce_id' => $opportunityId,
            'interest_salesforce_id' => $interestId,
            'reference_status' => $referenceStatus,
            'opportunity_is_deleted' => $deleted,
        ]);
    }

    private function inverse(
        SalesforceInterestOpportunityReconciliationRun $run,
        string $opportunityId,
        string $interestId,
        bool $deleted = false,
        ?string $presenceStatus = null,
        bool $requiresReview = false,
    ): SalesforceInterestOpportunityReconciliation {
        return SalesforceInterestOpportunityReconciliation::query()->create([
            'reconciliation_run_id' => $run->id,
            'interest_salesforce_id' => $interestId,
            'opportunity_salesforce_id' => $opportunityId,
            'relationship_status' => 'inverse_unique',
            'opportunity_presence_status' => $presenceStatus ?? ($deleted ? 'present_deleted' : 'present_active'),
            'opportunity_evidence_source' => 'interest_opportunity_dependency_snapshot',
            'interest_is_deleted' => false,
            'opportunity_is_deleted' => $deleted,
            'inverse_reference_count' => 1,
            'requires_review' => $requiresReview,
        ]);
    }

    private function interest(int $sequence, bool $deleted = false): SalesforceInterest
    {
        return SalesforceInterest::query()->create([
            'salesforce_id' => $this->interestId($sequence),
            'salesforce_created_at' => '2026-10-01 09:00:00',
            'salesforce_last_modified_at' => '2026-10-05 09:00:00',
            'is_deleted' => $deleted,
        ]);
    }

    private function completedReconciliationRun(
        SalesforceOpportunityInterestDirectRun $directRun,
        SalesforceInterestOpportunityReconciliationRun $inverseRun,
        int $sequence,
    ): SalesforceOpportunityInterestReconciliationRun {
        return SalesforceOpportunityInterestReconciliationRun::query()->create([
            'run_identifier' => sprintf('90000000-0000-4000-8000-%012d', $sequence),
            'reason' => 'Synthetic completed bidirectional snapshot',
            'status' => 'completed',
            'direct_run_id' => $directRun->id,
            'direct_cutoff_at' => $directRun->source_cutoff_at,
            'inverse_run_id' => $inverseRun->id,
            'inverse_interest_sync_run_id' => $inverseRun->source_interest_sync_run_id,
            'inverse_interest_cutoff_at' => $inverseRun->source_interest_cutoff_at,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);
    }

    private function reconciliation(
        SalesforceOpportunityInterestReconciliationRun $run,
        string $opportunityId,
    ): SalesforceOpportunityInterestReconciliation {
        return SalesforceOpportunityInterestReconciliation::query()->create([
            'reconciliation_run_id' => $run->id,
            'opportunity_salesforce_id' => $opportunityId,
            'inverse_reference_count' => 0,
            'relationship_status' => 'direct_only',
            'direct_reference_status' => 'valid',
            'direct_interest_presence_status' => 'not_local',
            'requires_review' => true,
        ]);
    }

    private function opportunityId(int $sequence): string
    {
        return '006'.str_pad((string) $sequence, 15, '0', STR_PAD_LEFT);
    }

    private function interestId(int $sequence): string
    {
        return 'a01'.str_pad((string) $sequence, 15, '0', STR_PAD_LEFT);
    }

    /** @return list<string> */
    private function logicalColumns(): array
    {
        return [
            'opportunity_salesforce_id',
            'direct_interest_salesforce_id',
            'inverse_interest_salesforce_ids',
            'inverse_reference_count',
            'relationship_status',
            'direct_reference_status',
            'direct_interest_presence_status',
            'direct_interest_is_deleted',
            'direct_opportunity_is_deleted',
            'inverse_opportunity_is_deleted',
            'inverse_opportunity_presence_status',
            'requires_review',
        ];
    }
}
