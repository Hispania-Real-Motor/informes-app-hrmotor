<?php

namespace Tests\Feature;

use App\Models\SalesforceOpportunity;
use App\Models\SalesforceOpportunityInterestDirect;
use App\Models\SalesforceOpportunityInterestDirectRun;
use App\Services\Salesforce\SalesforceClient;
use App\Services\Salesforce\SalesforceOpportunityInterestDirectSyncService;
use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class SalesforceOpportunityInterestDirectSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_is_additive_auditable_and_excludes_pii_and_payloads(): void
    {
        $this->assertTrue(Schema::hasColumns('salesforce_opportunity_interest_direct_runs', [
            'run_identifier', 'reason', 'status', 'source_cutoff_at', 'started_at',
            'completed_at', 'stats', 'error_message',
        ]));
        $this->assertTrue(Schema::hasColumns('salesforce_opportunity_interest_directs', [
            'direct_run_id', 'opportunity_salesforce_id', 'interest_salesforce_id',
            'reference_status', 'opportunity_is_deleted', 'salesforce_last_modified_at',
            'system_modstamp_at',
        ]));
        $columns = Schema::getColumnListing('salesforce_opportunity_interest_directs');
        $this->assertSame([], array_intersect($columns, [
            'name', 'account_id', 'lead_id', 'owner_id', 'email', 'phone', 'vehicle_id',
            'amount', 'raw_payload',
        ]));
        $foreignKeys = collect(DB::select(
            "PRAGMA foreign_key_list('salesforce_opportunity_interest_directs')",
        ));
        $this->assertTrue($foreignKeys->contains(
            fn (object $key): bool => $key->table === 'salesforce_opportunity_interest_direct_runs'
                && $key->from === 'direct_run_id'
                && strtolower((string) $key->on_delete) === 'cascade',
        ));
    }

    public function test_sync_uses_fixed_cutoff_query_all_pagination_chunks_and_preserves_deleted_and_invalid(): void
    {
        $legacy = SalesforceOpportunity::query()->create([
            'salesforce_id' => $this->opportunityId(9000),
            'is_deleted' => false,
        ]);
        $legacyBefore = DB::table('salesforce_opportunities')->orderBy('id')->get()->all();
        $records = [];
        for ($index = 1; $index <= 201; $index++) {
            $records[] = $this->record(
                $this->opportunityId($index),
                $index === 201 ? substr($this->interestId($index), 0, 15) : $this->interestId($index),
                $index === 200,
            );
        }
        $client = $this->client([$records, []]);

        $result = $this->service($client)->sync('Capture direct Opportunity Interest evidence safely');

        $this->assertSame('completed', $result['run']->status);
        $this->assertSame(2, $result['stats']['pages']);
        $this->assertSame(201, $result['stats']['queried']);
        $this->assertSame(201, $result['stats']['persisted']);
        $this->assertSame(2, $result['stats']['chunks']);
        $this->assertSame(200, $result['stats']['active']);
        $this->assertSame(1, $result['stats']['deleted']);
        $this->assertSame(200, $result['stats']['valid_references']);
        $this->assertSame(1, $result['stats']['invalid_references']);
        $this->assertTrue($client->includeDeleted[0]);
        $this->assertCount(1, $client->soql);
        $soql = $client->soql[0];
        $this->assertStringContainsString(
            'SELECT Id, HRM_Interes_Origen__c, IsDeleted, LastModifiedDate, SystemModstamp',
            $soql,
        );
        $this->assertStringContainsString('HRM_Interes_Origen__c != null', $soql);
        $this->assertStringContainsString(
            'SystemModstamp <= '.str_replace('+00:00', 'Z', (string) $result['stats']['cutoff']),
            $soql,
        );
        foreach (['Name', 'Account', 'Lead', 'Owner', 'Amount', 'Vehicle'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $soql);
        }
        $this->assertDatabaseHas('salesforce_opportunity_interest_directs', [
            'opportunity_salesforce_id' => $this->opportunityId(200),
            'opportunity_is_deleted' => true,
        ]);
        $this->assertDatabaseHas('salesforce_opportunity_interest_directs', [
            'opportunity_salesforce_id' => $this->opportunityId(201),
            'reference_status' => 'invalid',
        ]);
        $this->assertSame(0, $client->writeCalls);
        $this->assertEquals($legacyBefore, DB::table('salesforce_opportunities')->orderBy('id')->get()->all());
        $this->assertDatabaseHas('salesforce_opportunities', ['id' => $legacy->id]);
    }

    public function test_empty_snapshot_is_valid_and_reexecution_is_logically_idempotent(): void
    {
        $first = $this->service($this->client([[]]))->sync('Publish an empty direct evidence snapshot');
        $second = $this->service($this->client([[]]))->sync('Repeat the empty direct evidence snapshot');

        $this->assertSame('completed', $first['run']->status);
        $this->assertSame('completed', $second['run']->status);
        $this->assertSame(0, $second['stats']['queried']);
        $this->assertDatabaseCount('salesforce_opportunity_interest_directs', 0);
        $this->assertDatabaseCount('salesforce_opportunity_interest_direct_runs', 2);
    }

    public function test_remote_failure_is_sanitized_and_does_not_replace_completed_snapshot(): void
    {
        $completed = $this->completedRun(1);
        SalesforceOpportunityInterestDirect::query()->create([
            'direct_run_id' => $completed->id,
            'opportunity_salesforce_id' => $this->opportunityId(1),
            'interest_salesforce_id' => $this->interestId(1),
            'reference_status' => 'valid',
            'opportunity_is_deleted' => false,
        ]);
        $client = $this->client([], fail: true);

        try {
            $this->service($client)->sync('Keep completed direct snapshot after remote failure');
            $this->fail('Expected the remote failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Direct Opportunity Interest sync failed safely.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('secret-value', $exception->getMessage());
        }

        $this->assertSame('failed', SalesforceOpportunityInterestDirectRun::query()->latest('id')->value('status'));
        $this->assertDatabaseHas('salesforce_opportunity_interest_directs', ['direct_run_id' => $completed->id]);
        $this->assertSame(0, $client->writeCalls);
    }

    public function test_intermediate_persistence_failure_leaves_failed_run_without_replacing_completed_snapshot(): void
    {
        $completed = $this->completedRun(2);
        SalesforceOpportunityInterestDirect::query()->create([
            'direct_run_id' => $completed->id,
            'opportunity_salesforce_id' => $this->opportunityId(900),
            'interest_salesforce_id' => $this->interestId(900),
            'reference_status' => 'valid',
            'opportunity_is_deleted' => false,
        ]);
        $records = [];
        for ($index = 1; $index <= 201; $index++) {
            $records[] = $this->record($this->opportunityId($index), $this->interestId($index));
        }
        $client = $this->client([$records]);
        $service = new class($client) extends SalesforceOpportunityInterestDirectSyncService
        {
            protected function afterPersistChunk(
                SalesforceOpportunityInterestDirectRun $run,
                array $stats,
            ): void {
                if ($stats['chunks'] === 1) {
                    throw new RuntimeException('SQL bindings synthetic-sensitive-value');
                }
            }
        };

        try {
            $service->sync('Fail safely after the first persisted direct chunk');
            $this->fail('Expected persistence failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Direct Opportunity Interest sync failed safely.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('synthetic-sensitive-value', $exception->getMessage());
        }

        $failed = SalesforceOpportunityInterestDirectRun::query()->latest('id')->firstOrFail();
        $this->assertSame('failed', $failed->status);
        $this->assertSame('Direct Opportunity Interest sync failed safely.', $failed->error_message);
        $this->assertDatabaseHas('salesforce_opportunity_interest_directs', ['direct_run_id' => $completed->id]);
        $this->assertSame(200, SalesforceOpportunityInterestDirect::query()
            ->where('direct_run_id', $failed->id)->count());
    }

    public function test_command_validates_reason_and_lock_is_exclusive(): void
    {
        $this->artisan('salesforce:sync-opportunity-interest-direct')->assertFailed();
        $lock = Cache::lock(SalesforceOpportunityInterestDirectSyncService::LOCK_KEY, 60);
        $this->assertTrue($lock->get());
        try {
            $this->artisan('salesforce:sync-opportunity-interest-direct', [
                '--reason' => 'Attempt while the direct snapshot lock is owned',
            ])->assertFailed();
        } finally {
            $lock->release();
        }
    }

    private function service(SalesforceClient $client): SalesforceOpportunityInterestDirectSyncService
    {
        return new SalesforceOpportunityInterestDirectSyncService($client);
    }

    private function completedRun(int $sequence): SalesforceOpportunityInterestDirectRun
    {
        return SalesforceOpportunityInterestDirectRun::query()->create([
            'run_identifier' => sprintf('50000000-0000-4000-8000-%012d', $sequence),
            'reason' => 'Synthetic completed direct snapshot',
            'status' => 'completed',
            'source_cutoff_at' => '2026-10-06 08:00:00',
            'started_at' => '2026-10-06 07:59:00',
            'completed_at' => '2026-10-06 08:00:01',
        ]);
    }

    /** @return array<string, mixed> */
    private function record(string $opportunityId, string $interestId, bool $deleted = false): array
    {
        return [
            'Id' => $opportunityId,
            'HRM_Interes_Origen__c' => $interestId,
            'IsDeleted' => $deleted,
            'LastModifiedDate' => '2026-10-06T07:00:00Z',
            'SystemModstamp' => '2026-10-06T07:05:00Z',
        ];
    }

    /** @param list<list<array<string, mixed>>> $pages */
    private function client(array $pages, bool $fail = false): SalesforceClient
    {
        return new class($pages, $fail) extends SalesforceClient
        {
            public array $soql = [];

            public array $includeDeleted = [];

            public int $writeCalls = 0;

            public function __construct(private array $pages, private bool $fail) {}

            public function queryPages(string $soql, bool $includeDeleted = false): Generator
            {
                $this->soql[] = $soql;
                $this->includeDeleted[] = $includeDeleted;
                if ($this->fail) {
                    throw new RuntimeException('Authorization: Bearer secret-value');
                }
                foreach ($this->pages as $page) {
                    yield $page;
                }
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

    private function opportunityId(int $sequence): string
    {
        return '006'.str_pad((string) $sequence, 15, '0', STR_PAD_LEFT);
    }

    private function interestId(int $sequence): string
    {
        return 'a01'.str_pad((string) $sequence, 15, '0', STR_PAD_LEFT);
    }
}
