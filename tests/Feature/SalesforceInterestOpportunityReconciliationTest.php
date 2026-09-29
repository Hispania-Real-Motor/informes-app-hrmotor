<?php

namespace Tests\Feature;

use App\Models\ReportSyncRun;
use App\Models\SalesforceInterest;
use App\Models\SalesforceInterestOpportunityReconciliation;
use App\Models\SalesforceInterestOpportunityReconciliationRun;
use App\Models\SalesforceOpportunity;
use App\Services\Salesforce\SalesforceInterestOpportunityReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SalesforceInterestOpportunityReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private ReportSyncRun $interestRun;

    private ReportSyncRun $opportunityRun;

    protected function setUp(): void
    {
        parent::setUp();

        $this->interestRun = $this->interestSourceRun();
        $this->opportunityRun = $this->opportunitySourceRun();
    }

    public function test_schema_has_auditable_run_detail_fk_and_minimal_indexes(): void
    {
        $this->assertTrue(Schema::hasColumns('salesforce_interest_opportunity_reconciliation_runs', [
            'run_identifier', 'reason', 'status', 'source_interest_sync_run_id',
            'source_interest_cutoff_at', 'source_opportunity_sync_run_id',
            'source_opportunity_sync_status', 'source_opportunity_period_end_at',
            'source_opportunity_completed_at', 'stats', 'error_message',
        ]));
        $this->assertTrue(Schema::hasColumns('salesforce_interest_opportunity_reconciliations', [
            'reconciliation_run_id', 'interest_salesforce_id', 'opportunity_salesforce_id',
            'relationship_status', 'opportunity_presence_status', 'interest_is_deleted',
            'opportunity_is_deleted', 'opportunity_salesforce_deleted_at',
            'opportunity_deletion_detection_source', 'inverse_reference_count', 'requires_review',
        ]));

        $indexes = collect(DB::select("PRAGMA index_list('salesforce_interest_opportunity_reconciliations')"))
            ->pluck('name');
        $this->assertTrue($indexes->contains('sf_int_opp_recon_run_interest_uq'));
        $this->assertTrue($indexes->contains('sf_int_opp_recon_run_relation_idx'));
        $this->assertTrue($indexes->contains('sf_int_opp_recon_run_opp_idx'));

        $run = $this->completedAuditRun(90);
        $this->auditRow($run, 90);
        $run->delete();
        $this->assertDatabaseCount('salesforce_interest_opportunity_reconciliations', 0);
    }

    public function test_materializes_one_row_per_interest_with_relationship_presence_lifecycle_and_review_states(): void
    {
        Http::fake();
        $active = $this->opportunity(1);
        $deleted = $this->opportunity(2, [
            'is_deleted' => true,
            'salesforce_deleted_at' => '2026-09-29 10:00:00',
            'deletion_detection_source' => SalesforceOpportunity::DELETION_SOURCE_QUERY_ALL,
        ]);
        $missing = $this->opportunity(3, [
            'is_deleted' => false,
            'deletion_detection_source' => SalesforceOpportunity::PRESENCE_SOURCE_MISSING,
        ]);
        $unresolved = $this->opportunity(4, [
            'is_deleted' => true,
            'deletion_detection_source' => 'unexpected_source',
        ]);
        $shared = $this->opportunity(5);
        $deletedInterestOpportunity = $this->opportunity(6);

        $noInverse = $this->interest(1);
        $activeInterest = $this->interest(2, ['inverse_opportunity_salesforce_id' => $active->salesforce_id]);
        $deletedOpportunityInterest = $this->interest(3, ['inverse_opportunity_salesforce_id' => $deleted->salesforce_id]);
        $missingInterest = $this->interest(4, ['inverse_opportunity_salesforce_id' => $missing->salesforce_id]);
        $notLocalInterest = $this->interest(5, ['inverse_opportunity_salesforce_id' => $this->opportunityId(99)]);
        $unresolvedInterest = $this->interest(6, ['inverse_opportunity_salesforce_id' => $unresolved->salesforce_id]);
        $deletedInterest = $this->interest(7, [
            'inverse_opportunity_salesforce_id' => $deletedInterestOpportunity->salesforce_id,
            'is_deleted' => true,
        ]);
        $sharedA = $this->interest(8, ['inverse_opportunity_salesforce_id' => $shared->salesforce_id]);
        $sharedB = $this->interest(9, ['inverse_opportunity_salesforce_id' => $shared->salesforce_id]);
        $opportunityOnly = $this->opportunity(7);

        $sourceInterestsBefore = DB::table('salesforce_interests')->orderBy('id')->get()->all();
        $sourceOpportunitiesBefore = DB::table('salesforce_opportunities')->orderBy('id')->get()->all();
        $stats = app(SalesforceInterestOpportunityReconciliationService::class)
            ->run('Certify all unidirectional reconciliation states');
        $run = SalesforceInterestOpportunityReconciliationRun::query()->latest('id')->firstOrFail();

        $this->assertSame('completed', $run->status);
        $this->assertSame($this->interestRun->id, $run->source_interest_sync_run_id);
        $this->assertSame($this->opportunityRun->id, $run->source_opportunity_sync_run_id);
        $this->assertSame('completed', $run->source_opportunity_sync_status);
        $this->assertDatabaseCount('salesforce_interest_opportunity_reconciliations', 9);
        $this->assertResolution($run, $noInverse, 'no_inverse', 'not_applicable', false, 0);
        $this->assertResolution($run, $activeInterest, 'inverse_unique', 'present_active', false, 1);
        $this->assertResolution($run, $deletedOpportunityInterest, 'inverse_unique', 'present_deleted', true, 1);
        $this->assertResolution($run, $missingInterest, 'inverse_unique', 'present_missing', true, 1);
        $this->assertResolution($run, $notLocalInterest, 'inverse_unique', 'not_local', true, 1);
        $this->assertResolution($run, $unresolvedInterest, 'inverse_unique', 'present_unresolved', true, 1);
        $this->assertResolution($run, $deletedInterest, 'inverse_unique', 'present_active', false, 1);
        $this->assertResolution($run, $sharedA, 'inverse_shared', 'present_active', true, 2);
        $this->assertResolution($run, $sharedB, 'inverse_shared', 'present_active', true, 2);
        $this->assertDatabaseMissing('salesforce_interest_opportunity_reconciliations', [
            'opportunity_salesforce_id' => $opportunityOnly->salesforce_id,
        ]);

        $this->assertSame(9, $stats['interests_examined']);
        $this->assertSame(1, $stats['interests_without_inverse']);
        $this->assertSame(8, $stats['inverse_references']);
        $this->assertSame(7, $stats['distinct_opportunities_referenced']);
        $this->assertSame(6, $stats['inverse_unique']);
        $this->assertSame(2, $stats['inverse_shared']);
        $this->assertSame(1, $stats['opportunities_present_deleted']);
        $this->assertSame(1, $stats['opportunities_present_missing']);
        $this->assertSame(1, $stats['opportunities_not_local']);
        $this->assertSame(1, $stats['opportunities_present_unresolved']);
        $this->assertSame(1, $stats['interests_deleted']);
        $this->assertSame(6, $stats['requires_review']);
        $this->assertSame(0, $stats['errors']);
        Http::assertNothingSent();
        $this->assertEquals($sourceInterestsBefore, DB::table('salesforce_interests')->orderBy('id')->get()->all());
        $this->assertEquals($sourceOpportunitiesBefore, DB::table('salesforce_opportunities')->orderBy('id')->get()->all());
    }

    public function test_processes_multiple_chunks_with_bounded_queries_and_no_offset(): void
    {
        $opportunity = $this->opportunity(1);
        for ($index = 1; $index <= 201; $index++) {
            $this->interest($index, ['inverse_opportunity_salesforce_id' => $opportunity->salesforce_id]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $stats = app(SalesforceInterestOpportunityReconciliationService::class)
            ->run('Exercise bounded cursor processing over two chunks');
        $queries = collect(DB::getQueryLog());

        $this->assertSame(201, $stats['interests_examined']);
        $this->assertSame(201, $stats['inverse_shared']);
        $this->assertSame(
            [201],
            SalesforceInterestOpportunityReconciliation::query()
                ->pluck('inverse_reference_count')
                ->unique()
                ->values()
                ->all(),
        );
        $this->assertLessThanOrEqual(12, $queries->filter(fn (array $query): bool => str_contains(
            strtolower($query['query']),
            'salesforce_interests',
        ))->count());
        $this->assertLessThanOrEqual(8, $queries->filter(fn (array $query): bool => str_contains(
            strtolower($query['query']),
            'salesforce_opportunities',
        ))->count());
        $this->assertFalse($queries->contains(fn (array $query): bool => str_contains(
            strtolower($query['query']),
            ' offset ',
        )));
    }

    public function test_irrelevant_referenced_opportunity_change_does_not_invalidate_snapshot(): void
    {
        $opportunity = $this->opportunity(1, [
            'name' => 'Before reconciliation',
            'portal_original' => 'Original portal',
        ]);
        $interest = $this->interest(1, [
            'inverse_opportunity_salesforce_id' => $opportunity->salesforce_id,
        ]);
        $service = new class($opportunity->salesforce_id) extends SalesforceInterestOpportunityReconciliationService
        {
            public function __construct(private string $opportunityId) {}

            protected function beforeSourceValidation(
                SalesforceInterestOpportunityReconciliationRun $run,
                array $stats,
            ): void {
                SalesforceOpportunity::withoutGlobalScope(SalesforceOpportunity::ACTIVE_SCOPE)
                    ->where('salesforce_id', $this->opportunityId)
                    ->update([
                        'name' => 'Changed but irrelevant',
                        'portal_original' => 'Another portal',
                    ]);
            }
        };

        $service->run('Ignore dimensions outside the lifecycle evidence contract');
        $run = SalesforceInterestOpportunityReconciliationRun::query()->latest('id')->firstOrFail();

        $this->assertSame('completed', $run->status);
        $this->assertResolution($run, $interest, 'inverse_unique', 'present_active', false, 1);
    }

    public function test_unreferenced_opportunity_lifecycle_change_does_not_invalidate_snapshot(): void
    {
        $referenced = $this->opportunity(1);
        $unreferenced = $this->opportunity(2);
        $interest = $this->interest(1, [
            'inverse_opportunity_salesforce_id' => $referenced->salesforce_id,
        ]);
        $service = new class($unreferenced->salesforce_id) extends SalesforceInterestOpportunityReconciliationService
        {
            public function __construct(private string $opportunityId) {}

            protected function beforeSourceValidation(
                SalesforceInterestOpportunityReconciliationRun $run,
                array $stats,
            ): void {
                SalesforceOpportunity::withoutGlobalScope(SalesforceOpportunity::ACTIVE_SCOPE)
                    ->where('salesforce_id', $this->opportunityId)
                    ->update([
                        'is_deleted' => true,
                        'salesforce_deleted_at' => now('UTC'),
                        'deletion_detection_source' => SalesforceOpportunity::DELETION_SOURCE_QUERY_ALL,
                    ]);
            }
        };

        $service->run('Ignore lifecycle changes outside referenced Opportunities');
        $run = SalesforceInterestOpportunityReconciliationRun::query()->latest('id')->firstOrFail();

        $this->assertSame('completed', $run->status);
        $this->assertResolution($run, $interest, 'inverse_unique', 'present_active', false, 1);
        $this->assertTrue($unreferenced->fresh()->is_deleted);
    }

    public function test_rejects_unstable_latest_interest_or_opportunity_pipeline_before_starting(): void
    {
        $this->interestSourceRun(['status' => 'failed']);
        $this->expectException(RuntimeException::class);
        app(SalesforceInterestOpportunityReconciliationService::class)
            ->run('Reject an unstable latest Interest source');
    }

    public function test_rejects_unstable_opportunity_pipeline_before_starting(): void
    {
        $this->opportunitySourceRun(['status' => 'running', 'completed_at' => null]);
        $this->expectException(RuntimeException::class);
        app(SalesforceInterestOpportunityReconciliationService::class)
            ->run('Reject an unstable latest Opportunity source');
    }

    public function test_fails_without_publishing_when_f2_changes_during_materialization(): void
    {
        $this->interest(1);
        $service = new class extends SalesforceInterestOpportunityReconciliationService
        {
            protected function beforeSourceValidation(
                SalesforceInterestOpportunityReconciliationRun $run,
                array $stats,
            ): void {
                ReportSyncRun::query()->create([
                    'dataset' => 'salesforce_interests',
                    'source' => 'salesforce',
                    'status' => 'running',
                    'started_at' => now('UTC'),
                    'timezone' => 'UTC',
                ]);
            }
        };

        try {
            $service->run('Detect a concurrent Interest synchronization');
            $this->fail('Expected safe reconciliation failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Interest–Opportunity reconciliation failed safely.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }

        $this->assertSame('failed', SalesforceInterestOpportunityReconciliationRun::query()->latest('id')->value('status'));
        $this->assertDatabaseMissing('salesforce_interest_opportunity_reconciliation_runs', ['status' => 'completed']);
    }

    public function test_fails_without_publishing_when_opportunity_pipeline_run_changes(): void
    {
        $this->interest(1);
        $service = new class extends SalesforceInterestOpportunityReconciliationService
        {
            protected function beforeSourceValidation(
                SalesforceInterestOpportunityReconciliationRun $run,
                array $stats,
            ): void {
                ReportSyncRun::query()->create([
                    'dataset' => 'salesforce_opportunities',
                    'source' => 'salesforce',
                    'status' => 'completed',
                    'period_start_at' => now('UTC')->subHour(),
                    'period_end_at' => now('UTC'),
                    'source_cutoff_at' => now('UTC'),
                    'started_at' => now('UTC'),
                    'completed_at' => now('UTC'),
                    'timezone' => 'UTC',
                ]);
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('failed safely');
        try {
            $service->run('Detect a concurrent Opportunity synchronization');
        } finally {
            $this->assertSame('failed', SalesforceInterestOpportunityReconciliationRun::query()->latest('id')->value('status'));
        }
    }

    #[DataProvider('referencedOpportunityMutationProvider')]
    public function test_fails_when_referenced_opportunity_evidence_changes_during_run(string $mutation): void
    {
        $opportunityId = $this->opportunityId(1);
        if ($mutation !== 'appears') {
            $this->opportunity(1);
        }
        $this->interest(1, ['inverse_opportunity_salesforce_id' => $opportunityId]);
        $service = new class($opportunityId, $mutation) extends SalesforceInterestOpportunityReconciliationService
        {
            public function __construct(private string $opportunityId, private string $mutation) {}

            protected function beforeSourceValidation(
                SalesforceInterestOpportunityReconciliationRun $run,
                array $stats,
            ): void {
                $query = SalesforceOpportunity::withoutGlobalScope(SalesforceOpportunity::ACTIVE_SCOPE)
                    ->where('salesforce_id', $this->opportunityId);
                match ($this->mutation) {
                    'appears' => SalesforceOpportunity::query()->create([
                        'salesforce_id' => $this->opportunityId,
                        'is_deleted' => false,
                    ]),
                    'disappears' => $query->delete(),
                    'lifecycle' => $query->update([
                        'is_deleted' => true,
                        'salesforce_deleted_at' => now('UTC'),
                        'deletion_detection_source' => SalesforceOpportunity::DELETION_SOURCE_QUERY_ALL,
                    ]),
                };
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('failed safely');
        try {
            $service->run('Detect referenced Opportunity evidence mutation');
        } finally {
            $this->assertSame('failed', SalesforceInterestOpportunityReconciliationRun::query()->latest('id')->value('status'));
        }
    }

    /** @return array<string, array{string}> */
    public static function referencedOpportunityMutationProvider(): array
    {
        return [
            'appears after being absent' => ['appears'],
            'disappears after being present' => ['disappears'],
            'lifecycle changes' => ['lifecycle'],
        ];
    }

    public function test_failed_build_keeps_partial_current_and_latest_completed_but_removes_older_details(): void
    {
        $older = $this->completedAuditRun(80);
        $this->auditRow($older, 80);
        $valid = $this->completedAuditRun(81);
        $this->auditRow($valid, 81);
        for ($index = 1; $index <= 201; $index++) {
            $this->interest($index);
        }
        $service = new class extends SalesforceInterestOpportunityReconciliationService
        {
            protected function afterInterestChunk(int $cursor, array $stats): void
            {
                throw new RuntimeException('synthetic-sensitive-build-failure');
            }
        };

        try {
            $service->run('Retain safe diagnostics after a failed build');
            $this->fail('Expected safe reconciliation failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Interest–Opportunity reconciliation failed safely.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }

        $failed = SalesforceInterestOpportunityReconciliationRun::query()->latest('id')->firstOrFail();
        $this->assertSame('failed', $failed->status);
        $this->assertSame('Interest–Opportunity reconciliation failed safely.', $failed->error_message);
        $this->assertDatabaseMissing('salesforce_interest_opportunity_reconciliations', ['reconciliation_run_id' => $older->id]);
        $this->assertDatabaseHas('salesforce_interest_opportunity_reconciliations', ['reconciliation_run_id' => $valid->id]);
        $this->assertDatabaseHas('salesforce_interest_opportunity_reconciliations', ['reconciliation_run_id' => $failed->id]);
    }

    public function test_new_completed_snapshot_removes_superseded_details_in_cleanup_chunks(): void
    {
        $old = $this->completedAuditRun(70);
        $now = now();
        foreach (array_chunk(range(1, 1001), 200) as $chunk) {
            DB::table('salesforce_interest_opportunity_reconciliations')->insert(array_map(
                fn (int $index): array => [
                    'reconciliation_run_id' => $old->id,
                    'interest_salesforce_id' => $this->interestId($index + 1000),
                    'relationship_status' => 'no_inverse',
                    'opportunity_presence_status' => 'not_applicable',
                    'interest_is_deleted' => false,
                    'inverse_reference_count' => 0,
                    'requires_review' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $chunk,
            ));
        }
        $this->interest(1);
        $service = new class extends SalesforceInterestOpportunityReconciliationService
        {
            public int $cleanupChunks = 0;

            protected function afterCleanupChunk(int $rowsDeleted): void
            {
                $this->cleanupChunks++;
            }
        };

        $service->run('Replace a large superseded completed snapshot safely');
        $current = SalesforceInterestOpportunityReconciliationRun::query()->latest('id')->firstOrFail();
        $this->assertSame('completed', $current->status);
        $this->assertSame(2, $service->cleanupChunks);
        $this->assertDatabaseMissing('salesforce_interest_opportunity_reconciliations', ['reconciliation_run_id' => $old->id]);
        $this->assertDatabaseHas('salesforce_interest_opportunity_reconciliations', ['reconciliation_run_id' => $current->id]);
    }

    public function test_cleanup_failure_after_publication_keeps_completed_snapshot_and_records_metric(): void
    {
        $old = $this->completedAuditRun(60);
        $this->auditRow($old, 60);
        $this->interest(1);
        $service = new class extends SalesforceInterestOpportunityReconciliationService
        {
            protected function afterCleanupChunk(int $rowsDeleted): void
            {
                throw new RuntimeException('sensitive cleanup failure');
            }
        };

        $stats = $service->run('Publish valid snapshot despite later cleanup failure');
        $current = SalesforceInterestOpportunityReconciliationRun::query()->latest('id')->firstOrFail();
        $this->assertSame('completed', $current->status);
        $this->assertNull($current->error_message);
        $this->assertSame(1, $stats['cleanup_errors']);
        $this->assertSame(1, $current->stats['cleanup_errors']);
        $this->assertDatabaseHas('salesforce_interest_opportunity_reconciliations', ['reconciliation_run_id' => $current->id]);
    }

    public function test_command_validates_reason_outputs_stable_json_and_lock_is_exclusive(): void
    {
        $this->artisan('salesforce:reconcile-interest-opportunities', ['--reason' => 'short'])
            ->expectsOutputToContain('--reason debe contener entre 10 y 500 caracteres')
            ->assertFailed();

        $this->interest(1);
        $this->artisan('salesforce:reconcile-interest-opportunities', [
            '--reason' => 'Manual auditable Interest Opportunity snapshot',
        ])->expectsOutputToContain('INTEREST_OPPORTUNITY_RECONCILIATION_METRICS=')
            ->assertSuccessful();

        $lock = Cache::lock(SalesforceInterestOpportunityReconciliationService::LOCK_KEY, 60);
        $this->assertTrue($lock->get());
        try {
            $this->artisan('salesforce:reconcile-interest-opportunities', [
                '--reason' => 'Attempt while another reconciliation owns lock',
            ])->expectsOutputToContain('Another Interest–Opportunity reconciliation is already running.')
                ->assertFailed();
        } finally {
            $lock->release();
        }
    }

    private function assertResolution(
        SalesforceInterestOpportunityReconciliationRun $run,
        SalesforceInterest $interest,
        string $relationship,
        string $presence,
        bool $review,
        int $count,
    ): void {
        $this->assertDatabaseHas('salesforce_interest_opportunity_reconciliations', [
            'reconciliation_run_id' => $run->id,
            'interest_salesforce_id' => $interest->salesforce_id,
            'relationship_status' => $relationship,
            'opportunity_presence_status' => $presence,
            'requires_review' => $review,
            'inverse_reference_count' => $count,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function interestSourceRun(array $overrides = []): ReportSyncRun
    {
        return ReportSyncRun::query()->create(array_merge([
            'dataset' => 'salesforce_interests',
            'source' => 'salesforce',
            'status' => 'completed',
            'source_cutoff_at' => '2026-09-29 11:28:13',
            'started_at' => '2026-09-29 11:28:00',
            'completed_at' => '2026-09-29 11:28:14',
            'timezone' => 'UTC',
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function opportunitySourceRun(array $overrides = []): ReportSyncRun
    {
        return ReportSyncRun::query()->create(array_merge([
            'dataset' => 'salesforce_opportunities',
            'source' => 'salesforce',
            'status' => 'completed',
            'period_start_at' => '2026-09-29 08:00:00',
            'period_end_at' => '2026-09-29 11:30:00',
            'source_cutoff_at' => '2026-09-29 11:30:01',
            'started_at' => '2026-09-29 11:30:01',
            'completed_at' => '2026-09-29 11:30:02',
            'timezone' => 'UTC',
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function interest(int $sequence, array $overrides = []): SalesforceInterest
    {
        return SalesforceInterest::query()->create(array_merge([
            'salesforce_id' => $this->interestId($sequence),
            'salesforce_created_at' => '2026-09-29 10:00:00',
            'salesforce_last_modified_at' => '2026-09-29 10:00:00',
            'is_deleted' => false,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function opportunity(int $sequence, array $overrides = []): SalesforceOpportunity
    {
        return SalesforceOpportunity::query()->create(array_merge([
            'salesforce_id' => $this->opportunityId($sequence),
            'is_deleted' => false,
        ], $overrides));
    }

    private function completedAuditRun(int $sequence): SalesforceInterestOpportunityReconciliationRun
    {
        return SalesforceInterestOpportunityReconciliationRun::query()->create([
            'run_identifier' => sprintf('00000000-0000-4000-8000-%012d', $sequence),
            'reason' => 'Synthetic completed reconciliation snapshot',
            'status' => 'completed',
            'source_interest_sync_run_id' => $this->interestRun->id,
            'source_interest_cutoff_at' => $this->interestRun->source_cutoff_at,
            'source_opportunity_sync_run_id' => $this->opportunityRun->id,
            'source_opportunity_sync_status' => $this->opportunityRun->status,
            'source_opportunity_period_end_at' => $this->opportunityRun->period_end_at,
            'source_opportunity_completed_at' => $this->opportunityRun->completed_at,
            'started_at' => now('UTC')->subMinute(),
            'completed_at' => now('UTC'),
        ]);
    }

    private function auditRow(SalesforceInterestOpportunityReconciliationRun $run, int $sequence): void
    {
        SalesforceInterestOpportunityReconciliation::query()->create([
            'reconciliation_run_id' => $run->id,
            'interest_salesforce_id' => $this->interestId($sequence),
            'relationship_status' => 'no_inverse',
            'opportunity_presence_status' => 'not_applicable',
            'interest_is_deleted' => false,
            'inverse_reference_count' => 0,
            'requires_review' => false,
        ]);
    }

    private function interestId(int $sequence): string
    {
        return 'a01'.str_pad((string) $sequence, 15, '0', STR_PAD_LEFT);
    }

    private function opportunityId(int $sequence): string
    {
        return '006'.str_pad((string) $sequence, 15, '0', STR_PAD_LEFT);
    }
}
