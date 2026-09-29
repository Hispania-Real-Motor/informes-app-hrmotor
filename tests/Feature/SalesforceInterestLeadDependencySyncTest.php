<?php

namespace Tests\Feature;

use App\Models\ReportSyncRun;
use App\Models\SalesforceInterest;
use App\Models\SalesforceInterestLeadDependency;
use App\Models\SalesforceInterestLeadDependencyRun;
use App\Services\Salesforce\SalesforceClient;
use App\Services\Salesforce\SalesforceInterestLeadDependencySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class SalesforceInterestLeadDependencySyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_is_additive_indexed_and_contains_no_pii_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('salesforce_interest_lead_dependency_runs', [
            'run_identifier', 'source_interest_sync_run_id', 'source_interest_cutoff_at', 'stats',
        ]));
        $this->assertTrue(Schema::hasColumns('salesforce_interest_lead_dependencies', [
            'dependency_run_id', 'salesforce_id', 'presence_status', 'is_origin_reference',
            'is_master_dependency', 'is_deleted', 'salesforce_master_record_id',
            'salesforce_last_modified_at', 'system_modstamp_at',
        ]));
        $columns = Schema::getColumnListing('salesforce_interest_lead_dependencies');
        $this->assertSame([], array_intersect($columns, [
            'name', 'email', 'phone', 'mobile_phone', 'address', 'raw_payload', 'owner_name',
        ]));
        $this->assertTrue(Schema::hasColumn('salesforce_interest_reconciliation_runs', 'lead_dependency_run_id'));
        $this->assertTrue(Schema::hasColumn('salesforce_interest_reconciliations', 'lead_evidence_source'));

        $foreignKeys = DB::select("PRAGMA foreign_key_list('salesforce_interest_lead_dependencies')");
        $this->assertTrue(collect($foreignKeys)->contains(
            fn (object $key): bool => $key->table === 'salesforce_interest_lead_dependency_runs'
                && $key->from === 'dependency_run_id'
                && strtolower((string) $key->on_delete) === 'cascade',
        ));
        $indexes = collect(DB::select("PRAGMA index_list('salesforce_interest_lead_dependencies')"))
            ->pluck('name');
        $this->assertTrue($indexes->contains('sf_int_lead_deps_run_salesforce_uq'));
        $this->assertTrue($indexes->contains('sf_int_lead_deps_pending_idx'));
        $reconciliationRunForeignKeys = DB::select("PRAGMA foreign_key_list('salesforce_interest_reconciliation_runs')");
        $this->assertTrue(collect($reconciliationRunForeignKeys)->contains(
            fn (object $key): bool => $key->table === 'salesforce_interest_lead_dependency_runs'
                && $key->from === 'lead_dependency_run_id',
        ));
    }

    public function test_sync_materializes_active_deleted_missing_and_invalid_using_query_all_only(): void
    {
        $source = $this->completedInterestRun();
        $active = $this->leadId(1);
        $deleted = $this->leadId(2);
        $missing = $this->leadId(3);
        foreach ([$active, $deleted, $missing, 'invalid-id'] as $index => $origin) {
            $this->interest($index + 1, $origin);
        }
        $client = $this->client([
            $active => $this->leadRecord($active),
            $deleted => $this->leadRecord($deleted, ['IsDeleted' => true]),
        ]);

        $result = $this->service($client)->sync('Synthetic dependency status validation');

        $this->assertSame('completed', $result['run']->status);
        $this->assertSame($source->id, $result['run']->source_interest_sync_run_id);
        $this->assertSame(1, $result['stats']['query_all_calls']);
        $this->assertSame(1, $result['stats']['active']);
        $this->assertSame(1, $result['stats']['deleted']);
        $this->assertSame(1, $result['stats']['missing']);
        $this->assertSame(1, $result['stats']['invalid']);
        $this->assertDatabaseHas('salesforce_interest_lead_dependencies', [
            'salesforce_id' => $deleted,
            'presence_status' => 'deleted',
            'is_deleted' => true,
        ]);
        $this->assertDatabaseHas('salesforce_interest_lead_dependencies', [
            'salesforce_id' => $missing,
            'presence_status' => 'missing',
            'is_deleted' => null,
        ]);
        $this->assertSame(1, $client->queryAllCalls);
        $this->assertSame(0, $client->queryCalls);
        $this->assertSame(0, $client->writeCalls);
        $this->assertStringContainsString(
            'SELECT Id, IsDeleted, MasterRecordId, LastModifiedDate, SystemModstamp FROM Lead WHERE Id IN',
            $client->soql[0],
        );
        $this->assertStringNotContainsString('Name', $client->soql[0]);
        $this->assertStringNotContainsString('Email', $client->soql[0]);
        $this->assertStringNotContainsString('Phone', $client->soql[0]);
        $this->assertStringNotContainsString('invalid-id', $client->soql[0]);
    }

    public function test_only_canonical_18_character_rest_ids_are_queried(): void
    {
        $this->completedInterestRun();
        $canonicalId = $this->leadId(4);
        $fifteenCharacterId = substr($canonicalId, 0, 15);
        $this->interest(4, $fifteenCharacterId);
        $this->interest(5, $canonicalId);
        $client = $this->client([
            $canonicalId => $this->leadRecord($canonicalId),
        ]);

        $result = $this->service($client)->sync('Synthetic canonical REST ID validation');

        $this->assertSame(1, $client->queryAllCalls);
        $this->assertStringContainsString($canonicalId, $client->soql[0]);
        $this->assertStringNotContainsString($fifteenCharacterId."'", $client->soql[0]);
        $this->assertDatabaseHas('salesforce_interest_lead_dependencies', [
            'dependency_run_id' => $result['run']->id,
            'salesforce_id' => $fifteenCharacterId,
            'presence_status' => 'invalid',
            'is_deleted' => null,
        ]);
        $this->assertDatabaseHas('salesforce_interest_lead_dependencies', [
            'dependency_run_id' => $result['run']->id,
            'salesforce_id' => $canonicalId,
            'presence_status' => 'active',
            'is_deleted' => false,
        ]);
        $this->assertSame(1, $result['stats']['invalid']);
        $this->assertSame(1, $result['stats']['active']);
        $this->assertSame(0, $result['stats']['missing']);
    }

    public function test_recursive_master_frontiers_are_batched_and_merge_origin_master_flags(): void
    {
        $this->completedInterestRun();
        $origin = $this->leadId(10);
        $middle = $this->leadId(11);
        $final = $this->leadId(12);
        $shared = $this->leadId(13);
        $this->interest(10, $origin);
        $this->interest(11, $middle);
        $this->interest(13, $shared);
        $client = $this->client([
            $origin => $this->leadRecord($origin, ['MasterRecordId' => $middle]),
            $middle => $this->leadRecord($middle, ['MasterRecordId' => $final]),
            $final => $this->leadRecord($final),
            $shared => $this->leadRecord($shared, ['MasterRecordId' => $final]),
        ]);

        $result = $this->service($client)->sync('Synthetic recursive master dependency validation');

        $this->assertSame(2, $client->queryAllCalls);
        $this->assertSame(4, SalesforceInterestLeadDependency::query()->count());
        $this->assertDatabaseHas('salesforce_interest_lead_dependencies', [
            'dependency_run_id' => $result['run']->id,
            'salesforce_id' => $middle,
            'is_origin_reference' => true,
            'is_master_dependency' => true,
        ]);
        $this->assertDatabaseHas('salesforce_interest_lead_dependencies', [
            'salesforce_id' => $final,
            'is_origin_reference' => false,
            'is_master_dependency' => true,
            'presence_status' => 'active',
        ]);
    }

    public function test_201_origins_use_three_remote_batches_not_one_query_per_id(): void
    {
        $this->completedInterestRun();
        $records = [];
        for ($index = 1; $index <= 201; $index++) {
            $id = $this->leadId(1000 + $index);
            $this->interest(1000 + $index, $id);
            $records[$id] = $this->leadRecord($id);
        }
        $client = $this->client($records);

        $result = $this->service($client)->sync('Synthetic bounded Salesforce batching validation');

        $this->assertSame(3, $client->queryAllCalls);
        $this->assertSame(201, $result['stats']['queried_ids']);
        $this->assertSame(201, SalesforceInterestLeadDependency::query()->count());
        foreach ($client->soql as $soql) {
            preg_match_all("/'[A-Za-z0-9]+'/", $soql, $matches);
            $this->assertLessThanOrEqual(100, count($matches[0]));
        }
    }

    public function test_zero_origins_completes_without_remote_queries(): void
    {
        $this->completedInterestRun();
        SalesforceInterest::query()->create([
            'salesforce_id' => 'a01000000000000900',
            'salesforce_created_at' => '2026-09-01 09:00:00',
            'salesforce_last_modified_at' => '2026-09-20 09:00:00',
        ]);
        $client = $this->client([]);

        $result = $this->service($client)->sync('Synthetic empty dependency universe validation');

        $this->assertSame('completed', $result['run']->status);
        $this->assertSame(0, $result['stats']['origins_seeded']);
        $this->assertSame(0, $client->queryAllCalls);
        $this->assertDatabaseCount('salesforce_interest_lead_dependencies', 0);
    }

    public function test_self_reference_and_cycle_are_captured_once_without_infinite_queries(): void
    {
        $this->completedInterestRun();
        $self = $this->leadId(910);
        $cycleA = $this->leadId(911);
        $cycleB = $this->leadId(912);
        $this->interest(910, $self);
        $this->interest(911, $cycleA);
        $client = $this->client([
            $self => $this->leadRecord($self, ['MasterRecordId' => $self]),
            $cycleA => $this->leadRecord($cycleA, ['MasterRecordId' => $cycleB]),
            $cycleB => $this->leadRecord($cycleB, ['MasterRecordId' => $cycleA]),
        ]);

        $this->service($client)->sync('Synthetic cyclic master capture validation');

        $this->assertSame(2, $client->queryAllCalls);
        $this->assertSame(3, SalesforceInterestLeadDependency::query()->count());
        $this->assertDatabaseHas('salesforce_interest_lead_dependencies', [
            'salesforce_id' => $cycleA,
            'is_origin_reference' => true,
            'is_master_dependency' => true,
        ]);
    }

    public function test_latest_interest_run_must_be_completed_before_remote_access(): void
    {
        $this->completedInterestRun();
        $this->interest(20, $this->leadId(20));
        $this->interestRun('failed', null);
        $client = $this->client([]);

        try {
            $this->service($client)->sync('Synthetic unstable Interest source validation');
            $this->fail('The dependency sync must reject a latest failed source run.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('stable completed source', $exception->getMessage());
        }

        $this->assertSame(0, $client->queryAllCalls);
        $this->assertDatabaseCount('salesforce_interest_lead_dependency_runs', 0);
    }

    public function test_missing_interest_sync_run_is_rejected_before_remote_access(): void
    {
        $client = $this->client([]);

        $this->expectException(RuntimeException::class);

        try {
            $this->service($client)->sync('Synthetic missing Interest source validation');
        } finally {
            $this->assertSame(0, $client->queryAllCalls);
            $this->assertDatabaseCount('salesforce_interest_lead_dependency_runs', 0);
        }
    }

    public function test_latest_running_interest_run_is_rejected_before_remote_access(): void
    {
        $this->completedInterestRun();
        $this->interest(21, $this->leadId(21));
        $this->interestRun('running', null);
        $client = $this->client([]);

        $this->expectException(RuntimeException::class);

        try {
            $this->service($client)->sync('Synthetic running Interest source validation');
        } finally {
            $this->assertSame(0, $client->queryAllCalls);
            $this->assertDatabaseCount('salesforce_interest_lead_dependency_runs', 0);
        }
    }

    public function test_interest_source_change_during_build_fails_without_publishing_snapshot(): void
    {
        $this->completedInterestRun();
        $origin = $this->leadId(30);
        $this->interest(30, $origin);
        $client = $this->client([$origin => $this->leadRecord($origin)]);
        $test = $this;
        $service = new class($client, $test) extends SalesforceInterestLeadDependencySyncService
        {
            private bool $changed = false;

            public function __construct(SalesforceClient $client, private readonly SalesforceInterestLeadDependencySyncTest $test)
            {
                parent::__construct($client);
            }

            protected function afterBatch(SalesforceInterestLeadDependencyRun $run, array $stats): void
            {
                if (! $this->changed) {
                    $this->changed = true;
                    $this->test->interestRun('completed', '2026-09-29 11:00:00');
                }
            }
        };

        try {
            $service->sync('Synthetic concurrent Interest source change');
            $this->fail('The dependency sync must fail when the Interest source changes.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Interest Lead dependency sync failed safely.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }

        $run = SalesforceInterestLeadDependencyRun::query()->sole();
        $this->assertSame('failed', $run->status);
        $this->assertSame('Interest Lead dependency sync failed safely.', $run->error_message);
    }

    public function test_remote_failure_is_sanitized_and_preserves_previous_completed_snapshot(): void
    {
        $this->completedInterestRun();
        $origin = $this->leadId(40);
        $this->interest(40, $origin);
        $previous = SalesforceInterestLeadDependencyRun::query()->create([
            'run_identifier' => '00000000-0000-4000-8000-000000000040',
            'reason' => 'Synthetic prior completed dependency snapshot',
            'status' => 'completed',
            'source_interest_sync_run_id' => 1,
            'source_interest_cutoff_at' => '2026-09-29 10:00:00',
            'started_at' => now()->subMinute(),
            'completed_at' => now()->subMinute(),
        ]);
        SalesforceInterestLeadDependency::query()->create([
            'dependency_run_id' => $previous->id,
            'salesforce_id' => $origin,
            'presence_status' => 'active',
            'is_origin_reference' => true,
        ]);
        $client = $this->client([], failAtCall: 1);

        try {
            $this->service($client)->sync('Synthetic safe remote failure validation');
            $this->fail('The remote failure must fail the run.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Interest Lead dependency sync failed safely.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('secret-value', $exception->getMessage());
        }

        $failed = SalesforceInterestLeadDependencyRun::query()->latest('id')->firstOrFail();
        $this->assertSame('failed', $failed->status);
        $this->assertSame(1, SalesforceInterestLeadDependency::query()
            ->where('dependency_run_id', $previous->id)->count());
    }

    public function test_completed_snapshot_cleanup_is_chunked_and_keeps_run_history(): void
    {
        $this->completedInterestRun();
        $oldRun = SalesforceInterestLeadDependencyRun::query()->create([
            'run_identifier' => '00000000-0000-4000-8000-000000000041',
            'reason' => 'Synthetic superseded dependency snapshot',
            'status' => 'completed',
            'source_interest_sync_run_id' => 1,
            'source_interest_cutoff_at' => '2026-09-29 09:00:00',
            'started_at' => now()->subMinute(),
            'completed_at' => now()->subMinute(),
        ]);
        foreach (array_chunk($this->dependencyRows($oldRun->id, 1001), 200) as $rows) {
            SalesforceInterestLeadDependency::query()->insert($rows);
        }
        $client = $this->client([]);
        $service = new class($client) extends SalesforceInterestLeadDependencySyncService
        {
            public int $cleanupChunks = 0;

            protected function afterCleanupChunk(int $rowsDeleted): void
            {
                $this->cleanupChunks++;
            }
        };

        $result = $service->sync('Synthetic bounded dependency cleanup validation');

        $this->assertSame(2, $service->cleanupChunks);
        $this->assertSame(0, SalesforceInterestLeadDependency::query()
            ->where('dependency_run_id', $oldRun->id)->count());
        $this->assertSame('completed', $result['run']->status);
        $this->assertDatabaseHas('salesforce_interest_lead_dependency_runs', ['id' => $oldRun->id]);
    }

    public function test_cleanup_failure_after_publication_does_not_degrade_completed_snapshot(): void
    {
        $this->completedInterestRun();
        $oldRun = SalesforceInterestLeadDependencyRun::query()->create([
            'run_identifier' => '00000000-0000-4000-8000-000000000042',
            'reason' => 'Synthetic dependency snapshot with interrupted cleanup',
            'status' => 'completed',
            'source_interest_sync_run_id' => 1,
            'source_interest_cutoff_at' => '2026-09-29 09:00:00',
            'started_at' => now()->subMinute(),
            'completed_at' => now()->subMinute(),
        ]);
        foreach (array_chunk($this->dependencyRows($oldRun->id, 1001), 200) as $rows) {
            SalesforceInterestLeadDependency::query()->insert($rows);
        }
        $client = $this->client([]);
        $service = new class($client) extends SalesforceInterestLeadDependencySyncService
        {
            private bool $failed = false;

            protected function afterCleanupChunk(int $rowsDeleted): void
            {
                if (! $this->failed) {
                    $this->failed = true;
                    throw new RuntimeException('Synthetic sensitive cleanup detail');
                }
            }
        };

        $result = $service->sync('Synthetic published dependency snapshot cleanup failure');

        $this->assertSame('completed', $result['run']->status);
        $this->assertNull($result['run']->error_message);
        $this->assertSame(1, $result['stats']['cleanup_errors']);
        $this->assertSame(1, SalesforceInterestLeadDependency::query()
            ->where('dependency_run_id', $oldRun->id)->count());
        $this->assertStringNotContainsString(
            'sensitive',
            json_encode($result['run']->fresh()->stats, JSON_THROW_ON_ERROR),
        );
    }

    public function test_command_requires_reason_and_lock_prevents_concurrent_runs(): void
    {
        $this->artisan('salesforce:sync-interest-lead-dependencies')->assertFailed();
        $lock = Cache::lock(SalesforceInterestLeadDependencySyncService::LOCK_KEY, 60);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('salesforce:sync-interest-lead-dependencies', [
                '--reason' => 'Synthetic concurrent dependency snapshot',
            ])->assertFailed();
        } finally {
            $lock->release();
        }
    }

    private function service(SalesforceClient $client): SalesforceInterestLeadDependencySyncService
    {
        return new SalesforceInterestLeadDependencySyncService($client);
    }

    private function completedInterestRun(): ReportSyncRun
    {
        return $this->interestRun('completed', '2026-09-29 10:00:00');
    }

    public function interestRun(string $status, ?string $cutoff): ReportSyncRun
    {
        return ReportSyncRun::query()->create([
            'dataset' => 'salesforce_interests',
            'source' => 'salesforce',
            'status' => $status,
            'source_cutoff_at' => $cutoff,
            'started_at' => '2026-09-29 09:00:00',
            'completed_at' => $status === 'running' ? null : '2026-09-29 10:00:00',
            'timezone' => 'UTC',
        ]);
    }

    private function interest(int $sequence, string $origin): SalesforceInterest
    {
        return SalesforceInterest::query()->create([
            'salesforce_id' => 'a01'.str_pad((string) $sequence, 15, '0', STR_PAD_LEFT),
            'salesforce_created_at' => '2026-09-01 09:00:00',
            'salesforce_last_modified_at' => '2026-09-20 09:00:00',
            'migration_origin_lead_id' => $origin,
            'is_deleted' => false,
        ]);
    }

    private function leadId(int $sequence): string
    {
        return '00Q'.str_pad((string) $sequence, 15, '0', STR_PAD_LEFT);
    }

    /** @return array<string, mixed> */
    private function leadRecord(string $id, array $overrides = []): array
    {
        return array_replace([
            'Id' => $id,
            'IsDeleted' => false,
            'MasterRecordId' => null,
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
            'salesforce_id' => $this->leadId(5000 + $index),
            'presence_status' => 'active',
            'is_origin_reference' => true,
            'is_master_dependency' => false,
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
