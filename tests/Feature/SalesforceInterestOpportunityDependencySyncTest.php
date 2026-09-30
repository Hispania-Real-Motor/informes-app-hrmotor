<?php

namespace Tests\Feature;

use App\Models\ReportSyncRun;
use App\Models\SalesforceInterest;
use App\Models\SalesforceInterestOpportunityDependency;
use App\Models\SalesforceInterestOpportunityDependencyRun;
use App\Models\SalesforceOpportunity;
use App\Services\Salesforce\SalesforceClient;
use App\Services\Salesforce\SalesforceInterestOpportunityDependencySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class SalesforceInterestOpportunityDependencySyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_is_additive_indexed_and_contains_no_functional_or_pii_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('salesforce_interest_opportunity_dependency_runs', [
            'run_identifier', 'reason', 'status', 'source_interest_sync_run_id',
            'source_interest_cutoff_at', 'started_at', 'completed_at', 'stats', 'error_message',
        ]));
        $this->assertTrue(Schema::hasColumns('salesforce_interest_opportunity_dependencies', [
            'dependency_run_id', 'salesforce_id', 'presence_status', 'is_deleted',
            'salesforce_last_modified_at', 'system_modstamp_at',
        ]));
        $columns = Schema::getColumnListing('salesforce_interest_opportunity_dependencies');
        $this->assertSame([], array_intersect($columns, [
            'name', 'account_id', 'owner_id', 'email', 'phone', 'vehicle_id', 'amount',
            'portal', 'raw_payload',
        ]));

        $foreignKeys = collect(DB::select(
            "PRAGMA foreign_key_list('salesforce_interest_opportunity_dependencies')",
        ));
        $this->assertTrue($foreignKeys->contains(
            fn (object $key): bool => $key->table === 'salesforce_interest_opportunity_dependency_runs'
                && $key->from === 'dependency_run_id'
                && strtolower((string) $key->on_delete) === 'cascade',
        ));
        $indexes = collect(DB::select(
            "PRAGMA index_list('salesforce_interest_opportunity_dependencies')",
        ))->pluck('name');
        $this->assertTrue($indexes->contains('sf_int_opp_deps_run_salesforce_uq'));
        $this->assertTrue($indexes->contains('sf_int_opp_deps_pending_idx'));
        $this->assertTrue(Schema::hasColumn(
            'salesforce_interest_opportunity_reconciliation_runs',
            'opportunity_dependency_run_id',
        ));
        $this->assertTrue(Schema::hasColumn(
            'salesforce_interest_opportunity_reconciliations',
            'opportunity_evidence_source',
        ));
    }

    public function test_sync_seeds_distinct_non_blank_references_and_materializes_all_statuses_with_query_all_only(): void
    {
        $source = $this->completedInterestRun();
        $active = $this->opportunityId(1);
        $deleted = $this->opportunityId(2);
        $missing = $this->opportunityId(3);
        $invalid = substr($this->opportunityId(4), 0, 15);
        $this->interest(1, null);
        $this->interest(2, '   ');
        $this->interest(3, $active);
        $this->interest(4, $active);
        $this->interest(5, $deleted);
        $this->interest(6, $missing);
        $this->interest(7, $invalid);
        $legacy = $this->legacyOpportunity(1);
        $interestsBefore = DB::table('salesforce_interests')->orderBy('id')->get()->all();
        $opportunitiesBefore = DB::table('salesforce_opportunities')->orderBy('id')->get()->all();
        $client = $this->client([
            $active => $this->record($active),
            $deleted => $this->record($deleted, ['IsDeleted' => true]),
        ]);

        $result = $this->service($client)->sync('Certify minimal Opportunity dependency statuses');

        $this->assertSame('completed', $result['run']->status);
        $this->assertSame($source->id, $result['run']->source_interest_sync_run_id);
        $this->assertSame(4, $result['stats']['references_seeded']);
        $this->assertSame(1, $result['stats']['active']);
        $this->assertSame(1, $result['stats']['deleted']);
        $this->assertSame(1, $result['stats']['missing']);
        $this->assertSame(1, $result['stats']['invalid']);
        $this->assertSame(1, $client->queryAllCalls);
        $this->assertSame(3, $result['stats']['queried_ids']);
        $this->assertSame(0, $client->queryCalls);
        $this->assertSame(0, $client->writeCalls);
        $this->assertDatabaseCount('salesforce_interest_opportunity_dependencies', 4);
        $this->assertDatabaseHas('salesforce_interest_opportunity_dependencies', [
            'salesforce_id' => $active, 'presence_status' => 'active', 'is_deleted' => false,
        ]);
        $this->assertDatabaseHas('salesforce_interest_opportunity_dependencies', [
            'salesforce_id' => $deleted, 'presence_status' => 'deleted', 'is_deleted' => true,
        ]);
        $this->assertDatabaseHas('salesforce_interest_opportunity_dependencies', [
            'salesforce_id' => $missing, 'presence_status' => 'missing', 'is_deleted' => null,
        ]);
        $this->assertDatabaseHas('salesforce_interest_opportunity_dependencies', [
            'salesforce_id' => $invalid, 'presence_status' => 'invalid', 'is_deleted' => null,
        ]);
        $this->assertStringContainsString(
            'SELECT Id, IsDeleted, LastModifiedDate, SystemModstamp FROM Opportunity WHERE Id IN',
            $client->soql[0],
        );
        foreach (['Name', 'Account', 'Owner', 'Vehicle', 'Amount', 'Portal', $invalid."'"] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $client->soql[0]);
        }
        $this->assertEquals($interestsBefore, DB::table('salesforce_interests')->orderBy('id')->get()->all());
        $this->assertEquals($opportunitiesBefore, DB::table('salesforce_opportunities')->orderBy('id')->get()->all());
        $this->assertDatabaseHas('salesforce_opportunities', ['id' => $legacy->id]);
    }

    public function test_201_references_use_three_batches_of_at_most_100_without_n_plus_one(): void
    {
        $this->completedInterestRun();
        $records = [];
        for ($index = 1; $index <= 201; $index++) {
            $id = $this->opportunityId(1000 + $index);
            $this->interest($index, $id);
            $records[$id] = $this->record($id);
        }
        $client = $this->client($records);

        $result = $this->service($client)->sync('Certify bounded Opportunity queryAll batches');

        $this->assertSame(3, $client->queryAllCalls);
        $this->assertSame(3, $result['stats']['batches']);
        $this->assertSame(201, $result['stats']['queried_ids']);
        $this->assertSame(201, $result['stats']['active']);
        foreach ($client->soql as $soql) {
            preg_match_all("/'[A-Za-z0-9]+'/", $soql, $matches);
            $this->assertLessThanOrEqual(100, count($matches[0]));
        }
    }

    public function test_missing_or_unstable_interest_source_is_rejected_before_remote_access(): void
    {
        foreach (['missing', 'running', 'failed'] as $state) {
            ReportSyncRun::query()->delete();
            if ($state !== 'missing') {
                $this->interestRun($state, $state === 'running' ? null : '2026-09-30 08:00:00');
            }
            $client = $this->client([]);

            try {
                $this->service($client)->sync('Reject unavailable Interest source snapshot');
                $this->fail("Expected {$state} Interest source to be rejected.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('stable completed source', $exception->getMessage());
            }
            $this->assertSame(0, $client->queryAllCalls);
        }
    }

    public function test_interest_run_or_cutoff_change_during_build_fails_without_publishing(): void
    {
        $source = $this->completedInterestRun();
        $id = $this->opportunityId(20);
        $this->interest(20, $id);
        $client = $this->client([$id => $this->record($id)]);
        $service = new class($client, $source) extends SalesforceInterestOpportunityDependencySyncService
        {
            public function __construct(SalesforceClient $client, private ReportSyncRun $source)
            {
                parent::__construct($client);
            }

            protected function afterDependenciesBuilt(
                SalesforceInterestOpportunityDependencyRun $run,
                array $stats,
            ): void {
                $this->source->update(['source_cutoff_at' => '2026-09-30 10:00:00']);
            }
        };

        try {
            $service->sync('Reject a changed Interest cutoff during build');
            $this->fail('Expected changed cutoff to fail dependency sync.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Interest Opportunity dependency sync failed safely.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }

        $this->assertSame('failed', SalesforceInterestOpportunityDependencyRun::query()->sole()->status);
    }

    public function test_new_interest_run_during_build_fails_without_publishing(): void
    {
        $this->completedInterestRun();
        $id = $this->opportunityId(21);
        $this->interest(21, $id);
        $client = $this->client([$id => $this->record($id)]);
        $service = new class($client) extends SalesforceInterestOpportunityDependencySyncService
        {
            protected function afterDependenciesBuilt(
                SalesforceInterestOpportunityDependencyRun $run,
                array $stats,
            ): void {
                ReportSyncRun::query()->create([
                    'dataset' => 'salesforce_interests',
                    'source' => 'salesforce',
                    'status' => 'completed',
                    'source_cutoff_at' => '2026-09-30 10:00:00',
                    'started_at' => now('UTC'),
                    'completed_at' => now('UTC'),
                    'timezone' => 'UTC',
                ]);
            }
        };

        $this->expectException(RuntimeException::class);
        try {
            $service->sync('Reject a newer Interest run during dependency build');
        } finally {
            $this->assertSame('failed', SalesforceInterestOpportunityDependencyRun::query()->latest('id')->value('status'));
        }
    }

    public function test_pending_or_incomplete_coverage_cannot_publish_completed_snapshot(): void
    {
        $this->completedInterestRun();
        $id = $this->opportunityId(30);
        $this->interest(30, $id);
        $client = $this->client([$id => $this->record($id)]);
        $service = new class($client) extends SalesforceInterestOpportunityDependencySyncService
        {
            protected function afterBatch(SalesforceInterestOpportunityDependencyRun $run, array $stats): void
            {
                SalesforceInterestOpportunityDependency::query()
                    ->where('dependency_run_id', $run->id)
                    ->delete();
            }
        };

        $this->expectException(RuntimeException::class);
        try {
            $service->sync('Reject incomplete Opportunity dependency coverage');
        } finally {
            $this->assertSame('failed', SalesforceInterestOpportunityDependencyRun::query()->sole()->status);
            $this->assertDatabaseMissing('salesforce_interest_opportunity_dependency_runs', [
                'status' => 'completed',
            ]);
        }
    }

    public function test_remote_failure_is_sanitized_and_preserves_last_completed_snapshot(): void
    {
        $source = $this->completedInterestRun();
        $id = $this->opportunityId(40);
        $this->interest(40, $id);
        $previous = $this->completedDependencyRun($source, 40);
        $this->dependency($previous, $id);
        $client = $this->client([], failAtCall: 1);

        try {
            $this->service($client)->sync('Keep completed snapshot after safe remote failure');
            $this->fail('Expected remote dependency failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Interest Opportunity dependency sync failed safely.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('secret-value', $exception->getMessage());
        }

        $failed = SalesforceInterestOpportunityDependencyRun::query()->latest('id')->firstOrFail();
        $this->assertSame('failed', $failed->status);
        $this->assertDatabaseHas('salesforce_interest_opportunity_dependencies', [
            'dependency_run_id' => $previous->id,
        ]);
    }

    public function test_completed_cleanup_is_chunked_and_cleanup_failure_does_not_degrade_publication(): void
    {
        $source = $this->completedInterestRun();
        $old = $this->completedDependencyRun($source, 50);
        foreach (array_chunk($this->dependencyRows($old->id, 1001), 200) as $rows) {
            SalesforceInterestOpportunityDependency::query()->insert($rows);
        }
        $client = $this->client([]);
        $service = new class($client) extends SalesforceInterestOpportunityDependencySyncService
        {
            public int $cleanupChunks = 0;

            protected function afterCleanupChunk(int $rowsDeleted): void
            {
                $this->cleanupChunks++;
            }
        };

        $result = $service->sync('Remove superseded Opportunity dependencies in chunks');
        $this->assertSame(2, $service->cleanupChunks);
        $this->assertSame('completed', $result['run']->status);
        $this->assertDatabaseMissing('salesforce_interest_opportunity_dependencies', [
            'dependency_run_id' => $old->id,
        ]);

        $current = $result['run'];
        $this->dependency($current, $this->opportunityId(9999));
        $next = new class($client) extends SalesforceInterestOpportunityDependencySyncService
        {
            protected function afterCleanupChunk(int $rowsDeleted): void
            {
                throw new RuntimeException('sensitive cleanup exception');
            }
        };
        $published = $next->sync('Keep published dependency snapshot after cleanup failure');
        $this->assertSame('completed', $published['run']->status);
        $this->assertSame(1, $published['stats']['cleanup_errors']);
        $this->assertNull($published['run']->error_message);
    }

    public function test_command_requires_reason_and_lock_prevents_concurrent_runs(): void
    {
        $this->artisan('salesforce:sync-interest-opportunity-dependencies')->assertFailed();
        $lock = Cache::lock(SalesforceInterestOpportunityDependencySyncService::LOCK_KEY, 60);
        $this->assertTrue($lock->get());
        try {
            $this->artisan('salesforce:sync-interest-opportunity-dependencies', [
                '--reason' => 'Attempt concurrent Opportunity dependency snapshot',
            ])->assertFailed();
        } finally {
            $lock->release();
        }
    }

    private function service(SalesforceClient $client): SalesforceInterestOpportunityDependencySyncService
    {
        return new SalesforceInterestOpportunityDependencySyncService($client);
    }

    private function completedInterestRun(): ReportSyncRun
    {
        return $this->interestRun('completed', '2026-09-30 09:00:00');
    }

    private function interestRun(string $status, ?string $cutoff): ReportSyncRun
    {
        return ReportSyncRun::query()->create([
            'dataset' => 'salesforce_interests',
            'source' => 'salesforce',
            'status' => $status,
            'source_cutoff_at' => $cutoff,
            'started_at' => '2026-09-30 08:00:00',
            'completed_at' => $status === 'running' ? null : '2026-09-30 09:00:00',
            'timezone' => 'UTC',
        ]);
    }

    private function completedDependencyRun(
        ReportSyncRun $source,
        int $sequence,
    ): SalesforceInterestOpportunityDependencyRun {
        return SalesforceInterestOpportunityDependencyRun::query()->create([
            'run_identifier' => sprintf('10000000-0000-4000-8000-%012d', $sequence),
            'reason' => 'Synthetic completed Opportunity dependency snapshot',
            'status' => 'completed',
            'source_interest_sync_run_id' => $source->id,
            'source_interest_cutoff_at' => $source->source_cutoff_at,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);
    }

    private function dependency(
        SalesforceInterestOpportunityDependencyRun $run,
        string $id,
        array $overrides = [],
    ): SalesforceInterestOpportunityDependency {
        return SalesforceInterestOpportunityDependency::query()->create(array_merge([
            'dependency_run_id' => $run->id,
            'salesforce_id' => $id,
            'presence_status' => 'active',
            'is_deleted' => false,
        ], $overrides));
    }

    private function interest(int $sequence, ?string $opportunityId): SalesforceInterest
    {
        return SalesforceInterest::query()->create([
            'salesforce_id' => 'a01'.str_pad((string) $sequence, 15, '0', STR_PAD_LEFT),
            'salesforce_created_at' => '2026-09-01 09:00:00',
            'salesforce_last_modified_at' => '2026-09-20 09:00:00',
            'inverse_opportunity_salesforce_id' => $opportunityId,
            'is_deleted' => false,
        ]);
    }

    private function legacyOpportunity(int $sequence): SalesforceOpportunity
    {
        return SalesforceOpportunity::query()->create([
            'salesforce_id' => $this->opportunityId(9000 + $sequence),
            'is_deleted' => false,
        ]);
    }

    private function opportunityId(int $sequence): string
    {
        return '006'.str_pad((string) $sequence, 15, '0', STR_PAD_LEFT);
    }

    /** @return array<string, mixed> */
    private function record(string $id, array $overrides = []): array
    {
        return array_replace([
            'Id' => $id,
            'IsDeleted' => false,
            'LastModifiedDate' => '2026-09-20T09:00:00Z',
            'SystemModstamp' => '2026-09-20T09:05:00Z',
        ], $overrides);
    }

    /** @return list<array<string, mixed>> */
    private function dependencyRows(int $runId, int $count): array
    {
        $now = now();

        return array_map(fn (int $index): array => [
            'dependency_run_id' => $runId,
            'salesforce_id' => $this->opportunityId(5000 + $index),
            'presence_status' => 'active',
            'is_deleted' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ], range(1, $count));
    }

    /** @param array<string, array<string, mixed>> $records */
    private function client(array $records, ?int $failAtCall = null): SalesforceClient
    {
        return new class($records, $failAtCall) extends SalesforceClient
        {
            public int $queryAllCalls = 0;

            public int $queryCalls = 0;

            public int $writeCalls = 0;

            public array $soql = [];

            public function __construct(
                private readonly array $records,
                private readonly ?int $failAtCall,
            ) {}

            public function queryAll(string $soql): array
            {
                $this->queryAllCalls++;
                $this->soql[] = $soql;
                if ($this->failAtCall === $this->queryAllCalls) {
                    throw new RuntimeException('Authorization: Bearer secret-value');
                }
                preg_match_all("/'([A-Za-z0-9]+)'/", $soql, $matches);

                return array_values(array_intersect_key($this->records, array_flip($matches[1])));
            }

            public function query(string $soql): array
            {
                $this->queryCalls++;

                return [];
            }

            public function create(string $object, array $fields): string
            {
                $this->writeCalls++;
                throw new RuntimeException('Salesforce writes are forbidden.');
            }

            public function update(string $object, string $id, array $fields): void
            {
                $this->writeCalls++;
                throw new RuntimeException('Salesforce writes are forbidden.');
            }
        };
    }
}
