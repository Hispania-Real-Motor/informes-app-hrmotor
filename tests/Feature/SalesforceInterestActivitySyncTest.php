<?php

namespace Tests\Feature;

use App\Models\ReportSyncRun;
use App\Models\SalesforceInterest;
use App\Models\SalesforceInterestActivity;
use App\Models\SalesforceInterestActivityRun;
use App\Services\Salesforce\SalesforceClient;
use App\Services\Salesforce\SalesforceInterestActivitySyncService;
use App\Services\Salesforce\SalesforceInterestSyncService;
use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SalesforceInterestActivitySyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_is_additive_auditable_and_excludes_pii_and_payloads(): void
    {
        $this->assertTrue(Schema::hasColumns('salesforce_interest_activity_runs', [
            'run_identifier', 'reason', 'status', 'source_interest_sync_run_id',
            'source_interest_cutoff_at', 'source_cutoff_at', 'started_at', 'completed_at',
            'stats', 'error_message',
        ]));
        $this->assertTrue(Schema::hasColumns('salesforce_interest_activities', [
            'activity_run_id', 'activity_kind', 'activity_salesforce_id',
            'interest_salesforce_id', 'who_salesforce_id', 'relationship_status',
            'activity_is_deleted', 'interest_is_deleted', 'activity_date', 'start_datetime',
            'salesforce_created_at', 'salesforce_last_modified_at', 'system_modstamp_at',
        ]));
        $columns = Schema::getColumnListing('salesforce_interest_activities');
        $this->assertSame([], array_intersect($columns, [
            'subject', 'description', 'name', 'email', 'phone', 'owner_name', 'raw_payload',
        ]));
        $foreignKeys = collect(DB::select("PRAGMA foreign_key_list('salesforce_interest_activities')"));
        $this->assertTrue($foreignKeys->contains(
            fn (object $key): bool => $key->table === 'salesforce_interest_activity_runs'
                && $key->from === 'activity_run_id'
                && strtolower((string) $key->on_delete) === 'cascade',
        ));
        $indexes = collect(DB::select("PRAGMA index_list('salesforce_interest_activities')"))
            ->pluck('name');
        $this->assertTrue($indexes->contains('sf_int_activities_run_kind_id_uq'));
        $this->assertTrue($indexes->contains('sf_int_activities_run_interest_idx'));
        $this->assertTrue($indexes->contains('sf_int_activities_run_relation_idx'));
    }

    public function test_materializes_direct_task_and_event_without_who_and_preserves_dates_and_lifecycle(): void
    {
        $source = $this->completedInterestRun();
        $activeInterest = $this->interest(1);
        $deletedInterest = $this->interest(2, true);
        $unrelated = $this->interestId(99);
        $client = new InterestActivityFakeClient(
            tasks: [
                $this->task(1, $activeInterest->salesforce_id, $this->leadId(1)),
                $this->task(2, $deletedInterest->salesforce_id, null, true),
                $this->task(3, $unrelated, null),
            ],
            events: [
                $this->event(1, $activeInterest->salesforce_id, null),
            ],
        );
        $interestsBefore = DB::table('salesforce_interests')->orderBy('id')->get()->all();

        $result = $this->service($client)->sync('Capture direct Interest activity evidence safely');

        $this->assertSame('completed', $result['run']->status);
        $this->assertSame($source->id, $result['run']->source_interest_sync_run_id);
        $this->assertSame(2, $result['stats']['source_interests']);
        $this->assertSame(3, $result['stats']['tasks_examined']);
        $this->assertSame(1, $result['stats']['events_examined']);
        $this->assertSame(3, $result['stats']['direct_relations']);
        $this->assertSame(1, $result['stats']['activities_deleted']);
        $this->assertSame(1, $result['stats']['unresolved_references']);
        $this->assertSame(1, $result['stats']['interest_not_in_source']);
        $this->assertDatabaseHas('salesforce_interest_activities', [
            'activity_kind' => 'Task',
            'activity_salesforce_id' => $this->taskId(1),
            'interest_salesforce_id' => $activeInterest->salesforce_id,
            'who_salesforce_id' => $this->leadId(1),
            'activity_date' => '2026-10-06',
            'start_datetime' => null,
            'relationship_status' => 'resolved',
        ]);
        $this->assertDatabaseHas('salesforce_interest_activities', [
            'activity_kind' => 'Task',
            'activity_salesforce_id' => $this->taskId(2),
            'who_salesforce_id' => null,
            'activity_is_deleted' => true,
            'interest_is_deleted' => true,
        ]);
        $event = SalesforceInterestActivity::query()
            ->where('activity_salesforce_id', $this->eventId(1))
            ->sole();
        $this->assertSame('2026-10-06T11:30:00+00:00', $event->start_datetime->toIso8601String());
        $this->assertNull($event->activity_date);
        $this->assertSame('2026-10-05T08:00:00+00:00', $event->salesforce_created_at->toIso8601String());
        $this->assertEquals($interestsBefore, DB::table('salesforce_interests')->orderBy('id')->get()->all());
        $this->assertSame(0, $client->writeCalls);
        $this->assertCount(2, $client->soql);
        foreach ($client->soql as $soql) {
            $this->assertStringContainsString("What.Type = 'Interes__c'", $soql);
            $this->assertStringContainsString('SystemModstamp <= ', $soql);
            $this->assertStringNotContainsString('Subject', $soql);
            $this->assertStringNotContainsString('Description', $soql);
        }
        $this->assertStringContainsString('ActivityDate', $client->soql[0]);
        $this->assertStringContainsString('StartDateTime', $client->soql[1]);
        $this->assertSame([true, true], $client->includeDeleted);
    }

    public function test_remote_queries_are_constant_with_large_interest_source_and_pages_and_persistence_are_chunked(): void
    {
        $this->completedInterestRun();
        for ($index = 1; $index <= 2001; $index++) {
            $this->interest($index);
        }
        $tasks = [];
        for ($index = 1; $index <= 401; $index++) {
            $tasks[] = $this->task($index, $this->interestId(1), null);
        }
        $events = [];
        for ($index = 1; $index <= 251; $index++) {
            $events[] = $this->event($index, $this->interestId(2), null);
        }
        $client = new InterestActivityFakeClient($tasks, $events, pageSize: 250);
        $interestSelects = 0;
        DB::listen(function ($query) use (&$interestSelects): void {
            if (str_contains(strtolower($query->sql), 'from "salesforce_interests"')) {
                $interestSelects++;
            }
        });

        $result = $this->service($client)->sync('Verify bounded Interest activity batching and pagination');

        $this->assertSame(2001, $result['stats']['source_interests']);
        $this->assertSame(2, $result['stats']['query_all_calls']);
        $this->assertSame(2, $client->queryCalls);
        $this->assertSame(401, $result['stats']['tasks_examined']);
        $this->assertSame(251, $result['stats']['events_examined']);
        $this->assertGreaterThan(1, $result['stats']['task_pages']);
        $this->assertGreaterThan(1, $result['stats']['event_pages']);
        $this->assertGreaterThan(4, $result['stats']['persist_chunks']);
        $this->assertSame(652, $result['stats']['rows_materialized']);
        $this->assertDatabaseCount('salesforce_interest_activities', 652);
        $this->assertLessThanOrEqual(
            1 + $result['stats']['task_pages'] + $result['stats']['event_pages'],
            $interestSelects,
        );
        foreach ($client->soql as $soql) {
            $this->assertStringContainsString("What.Type = 'Interes__c'", $soql);
            $this->assertStringNotContainsString('WhatId IN', $soql);
        }
    }

    #[DataProvider('sourceInterestCounts')]
    public function test_source_interest_count_does_not_change_remote_query_count(int $interestCount): void
    {
        $this->completedInterestRun();
        for ($index = 1; $index <= $interestCount; $index++) {
            $this->interest($index);
        }
        $client = new InterestActivityFakeClient;

        $result = $this->service($client)->sync('Count source Interests without remote query fan out');

        $this->assertSame($interestCount, $result['stats']['source_interests']);
        $this->assertSame(2, $result['stats']['query_all_calls']);
        $this->assertSame(2, $client->queryCalls);
    }

    /** @return array<string, array{int}> */
    public static function sourceInterestCounts(): array
    {
        return [
            'one Interest' => [1],
            'two hundred one Interests' => [201],
            'several thousand Interests' => [2001],
        ];
    }

    public function test_invalid_activity_id_and_non_source_interest_are_audited_without_inference(): void
    {
        $this->completedInterestRun();
        $interest = $this->interest(1);
        $invalidActivity = $this->task(1, $interest->salesforce_id, null);
        $invalidActivity['Id'] = substr($this->taskId(1), 0, 15);
        $invalidReference = $this->task(3, substr($this->interestId(3), 0, 15), null);
        $client = new InterestActivityFakeClient(tasks: [
            $invalidActivity,
            $this->task(2, $this->interestId(999), null),
            $invalidReference,
        ]);

        $result = $this->service($client)->sync('Audit invalid activity evidence without heuristics');

        $this->assertSame(0, $result['stats']['direct_relations']);
        $this->assertSame(3, $result['stats']['unresolved_references']);
        $this->assertSame(1, $result['stats']['invalid_activity_ids']);
        $this->assertSame(1, $result['stats']['interest_not_in_source']);
        $this->assertSame(1, $result['stats']['invalid_interest_references']);
        $this->assertDatabaseHas('salesforce_interest_activities', [
            'activity_salesforce_id' => substr($this->taskId(1), 0, 15),
            'relationship_status' => 'invalid_activity_id',
        ]);
        $this->assertDatabaseHas('salesforce_interest_activities', [
            'activity_salesforce_id' => $this->taskId(2),
            'interest_salesforce_id' => $this->interestId(999),
            'relationship_status' => 'interest_not_in_source',
            'interest_is_deleted' => null,
        ]);
        $this->assertDatabaseHas('salesforce_interest_activities', [
            'activity_salesforce_id' => $this->taskId(3),
            'relationship_status' => 'invalid_interest_reference',
            'interest_is_deleted' => null,
        ]);
    }

    public function test_reexecution_of_same_source_is_logically_idempotent(): void
    {
        $this->completedInterestRun();
        $this->interest(1);
        $records = [$this->task(1, $this->interestId(1), null)];

        $first = $this->service(new InterestActivityFakeClient($records))
            ->sync('Build the first deterministic activity snapshot');
        $firstRows = SalesforceInterestActivity::query()
            ->where('activity_run_id', $first['run']->id)
            ->get()
            ->map(fn (SalesforceInterestActivity $row): array => collect([
                'activity_kind', 'activity_salesforce_id', 'interest_salesforce_id',
                'who_salesforce_id', 'relationship_status', 'activity_is_deleted',
                'interest_is_deleted', 'activity_date', 'start_datetime',
                'salesforce_created_at', 'salesforce_last_modified_at', 'system_modstamp_at',
            ])->mapWithKeys(fn (string $attribute): array => [
                $attribute => $row->getRawOriginal($attribute),
            ])->all())
            ->all();

        $second = $this->service(new InterestActivityFakeClient($records))
            ->sync('Rebuild the same deterministic activity snapshot');
        $secondRows = SalesforceInterestActivity::query()
            ->where('activity_run_id', $second['run']->id)
            ->get()
            ->map(fn (SalesforceInterestActivity $row): array => collect([
                'activity_kind', 'activity_salesforce_id', 'interest_salesforce_id',
                'who_salesforce_id', 'relationship_status', 'activity_is_deleted',
                'interest_is_deleted', 'activity_date', 'start_datetime',
                'salesforce_created_at', 'salesforce_last_modified_at', 'system_modstamp_at',
            ])->mapWithKeys(fn (string $attribute): array => [
                $attribute => $row->getRawOriginal($attribute),
            ])->all())
            ->all();

        $this->assertSame($firstRows, $secondRows);
        $this->assertDatabaseCount('salesforce_interest_activities', 1);
        $this->assertDatabaseCount('salesforce_interest_activity_runs', 2);
    }

    public function test_missing_running_or_failed_interest_source_is_rejected_before_remote_access(): void
    {
        foreach (['missing', 'running', 'failed'] as $state) {
            ReportSyncRun::query()->delete();
            if ($state !== 'missing') {
                $this->interestRun($state, $state === 'running' ? null : '2026-10-07 08:00:00');
            }
            $client = new InterestActivityFakeClient;

            try {
                $this->service($client)->sync('Reject an unstable Interest source snapshot');
                $this->fail("Expected {$state} source rejection.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('stable completed source', $exception->getMessage());
            }
            $this->assertSame(0, $client->queryCalls);
        }
    }

    #[DataProvider('concurrentInterestRunStatuses')]
    public function test_new_interest_run_during_build_fails_safely_and_preserves_previous_completed(
        string $concurrentStatus,
    ): void {
        $this->completedInterestRun();
        $this->interest(1);
        $previous = $this->completedActivityRun();
        SalesforceInterestActivity::query()->create($this->detailRow($previous, 900));
        $client = new InterestActivityFakeClient([$this->task(1, $this->interestId(1), null)]);
        $service = new class($client, $concurrentStatus) extends SalesforceInterestActivitySyncService
        {
            public function __construct(SalesforceClient $client, private string $concurrentStatus)
            {
                parent::__construct($client);
            }

            protected function afterSnapshotBuilt(SalesforceInterestActivityRun $run, array $stats): void
            {
                ReportSyncRun::query()->create([
                    'dataset' => SalesforceInterestSyncService::DATASET,
                    'source' => SalesforceInterestSyncService::SOURCE,
                    'status' => $this->concurrentStatus,
                    'source_cutoff_at' => $this->concurrentStatus === 'running'
                        ? null
                        : '2026-10-07 09:00:00',
                    'started_at' => now('UTC'),
                    'completed_at' => $this->concurrentStatus === 'completed' ? now('UTC') : null,
                    'timezone' => 'UTC',
                ]);
            }
        };

        try {
            $service->sync('Reject a concurrent Interest synchronization safely');
            $this->fail('Expected concurrent source failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Interest activity sync failed safely.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }

        $failed = SalesforceInterestActivityRun::query()->latest('id')->firstOrFail();
        $this->assertSame('failed', $failed->status);
        $this->assertSame('completed', $previous->fresh()->status);
        $this->assertDatabaseHas('salesforce_interest_activities', ['activity_run_id' => $previous->id]);
    }

    /** @return array<string, array{string}> */
    public static function concurrentInterestRunStatuses(): array
    {
        return [
            'running' => ['running'],
            'failed' => ['failed'],
            'completed' => ['completed'],
        ];
    }

    public function test_remote_and_persistence_failures_are_sanitized_and_do_not_publish(): void
    {
        $this->completedInterestRun();
        $this->interest(1);
        $client = new InterestActivityFakeClient(
            tasks: [$this->task(1, $this->interestId(1), null)],
            failOnQuery: 2,
        );

        try {
            $this->service($client)->sync('Sanitize a remote activity synchronization failure');
            $this->fail('Expected remote failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Interest activity sync failed safely.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('sensitive-value', $exception->getMessage());
        }
        $this->assertSame('failed', SalesforceInterestActivityRun::query()->latest('id')->value('status'));
        $this->assertDatabaseCount('salesforce_interest_activities', 1);

        ReportSyncRun::query()->where('status', 'running')->delete();
        $service = new class(new InterestActivityFakeClient([$this->task(2, $this->interestId(1), null)])) extends SalesforceInterestActivitySyncService
        {
            protected function afterPersistChunk(SalesforceInterestActivityRun $run, array $stats): void
            {
                throw new RuntimeException('SQL bindings sensitive-value');
            }
        };

        try {
            $service->sync('Sanitize a local activity persistence failure');
            $this->fail('Expected persistence failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Interest activity sync failed safely.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    public function test_cleanup_is_chunked_and_cleanup_failure_does_not_degrade_published_snapshot(): void
    {
        $this->completedInterestRun();
        $old = $this->completedActivityRun();
        $now = now();
        foreach (array_chunk(range(1, 1001), 200) as $indexes) {
            SalesforceInterestActivity::query()->insert(array_map(
                fn (int $index): array => $this->detailRow($old, $index, $now),
                $indexes,
            ));
        }
        $service = new class(new InterestActivityFakeClient) extends SalesforceInterestActivitySyncService
        {
            public int $cleanupChunks = 0;

            protected function afterCleanupChunk(int $rowsDeleted): void
            {
                $this->cleanupChunks++;
            }
        };

        $result = $service->sync('Remove superseded activity detail in bounded chunks');
        $this->assertSame('completed', $result['run']->status);
        $this->assertSame(2, $service->cleanupChunks);
        $this->assertDatabaseMissing('salesforce_interest_activities', ['activity_run_id' => $old->id]);

        SalesforceInterestActivity::query()->create($this->detailRow($result['run'], 2000));
        $failing = new class(new InterestActivityFakeClient) extends SalesforceInterestActivitySyncService
        {
            protected function afterCleanupChunk(int $rowsDeleted): void
            {
                throw new RuntimeException('sensitive cleanup error');
            }
        };
        $next = $failing->sync('Keep published activity snapshot after cleanup failure');
        $this->assertSame('completed', $next['run']->status);
        $this->assertSame(1, $next['stats']['cleanup_errors']);
        $this->assertNull($next['run']->error_message);
    }

    public function test_command_requires_reason_and_lock_is_exclusive(): void
    {
        $this->artisan('salesforce:sync-interest-activities')->assertFailed();
        $lock = Cache::lock(SalesforceInterestActivitySyncService::LOCK_KEY, 60);
        $this->assertTrue($lock->get());
        try {
            $this->artisan('salesforce:sync-interest-activities', [
                '--reason' => 'Attempt while the activity snapshot lock is owned',
            ])->assertFailed();
        } finally {
            $lock->release();
        }
    }

    private function service(SalesforceClient $client): SalesforceInterestActivitySyncService
    {
        return new SalesforceInterestActivitySyncService($client);
    }

    private function completedInterestRun(): ReportSyncRun
    {
        return $this->interestRun('completed', '2026-10-07 08:00:00');
    }

    private function interestRun(string $status, ?string $cutoff): ReportSyncRun
    {
        return ReportSyncRun::query()->create([
            'dataset' => SalesforceInterestSyncService::DATASET,
            'source' => SalesforceInterestSyncService::SOURCE,
            'status' => $status,
            'source_cutoff_at' => $cutoff,
            'started_at' => now('UTC'),
            'completed_at' => $status === 'completed' ? now('UTC') : null,
            'timezone' => 'UTC',
        ]);
    }

    private function interest(int $index, bool $deleted = false): SalesforceInterest
    {
        return SalesforceInterest::query()->create([
            'salesforce_id' => $this->interestId($index),
            'salesforce_created_at' => '2026-10-01 08:00:00',
            'salesforce_last_modified_at' => '2026-10-01 08:00:00',
            'is_deleted' => $deleted,
        ]);
    }

    private function completedActivityRun(): SalesforceInterestActivityRun
    {
        return SalesforceInterestActivityRun::query()->create([
            'run_identifier' => (string) str()->uuid(),
            'reason' => 'Previously completed activity foundation snapshot',
            'status' => 'completed',
            'source_interest_sync_run_id' => 1,
            'source_interest_cutoff_at' => '2026-10-07 08:00:00',
            'source_cutoff_at' => '2026-10-07 08:10:00',
            'started_at' => now('UTC'),
            'completed_at' => now('UTC'),
            'stats' => [],
        ]);
    }

    /** @return array<string, mixed> */
    private function detailRow(SalesforceInterestActivityRun $run, int $index, mixed $now = null): array
    {
        $now ??= now();

        return [
            'activity_run_id' => $run->id,
            'activity_kind' => 'Task',
            'activity_salesforce_id' => $this->taskId($index),
            'interest_salesforce_id' => $this->interestId($index),
            'relationship_status' => 'resolved',
            'activity_is_deleted' => false,
            'interest_is_deleted' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @return array<string, mixed> */
    private function task(int $index, string $whatId, ?string $whoId, bool $deleted = false): array
    {
        return [
            'Id' => $this->taskId($index),
            'WhatId' => $whatId,
            'WhoId' => $whoId,
            'ActivityDate' => '2026-10-06',
            'CreatedDate' => '2026-10-05T08:00:00Z',
            'IsDeleted' => $deleted,
            'LastModifiedDate' => '2026-10-06T09:00:00Z',
            'SystemModstamp' => '2026-10-06T09:01:00Z',
        ];
    }

    /** @return array<string, mixed> */
    private function event(int $index, string $whatId, ?string $whoId): array
    {
        return [
            'Id' => $this->eventId($index),
            'WhatId' => $whatId,
            'WhoId' => $whoId,
            'StartDateTime' => '2026-10-06T11:30:00Z',
            'CreatedDate' => '2026-10-05T08:00:00Z',
            'IsDeleted' => false,
            'LastModifiedDate' => '2026-10-06T09:00:00Z',
            'SystemModstamp' => '2026-10-06T09:01:00Z',
        ];
    }

    private function interestId(int $index): string
    {
        return 'a01'.str_pad((string) $index, 15, '0', STR_PAD_LEFT);
    }

    private function taskId(int $index): string
    {
        return '00T'.str_pad((string) $index, 15, '0', STR_PAD_LEFT);
    }

    private function eventId(int $index): string
    {
        return '00U'.str_pad((string) $index, 15, '0', STR_PAD_LEFT);
    }

    private function leadId(int $index): string
    {
        return '00Q'.str_pad((string) $index, 15, '0', STR_PAD_LEFT);
    }
}

class InterestActivityFakeClient extends SalesforceClient
{
    /** @var list<string> */
    public array $soql = [];

    /** @var list<bool> */
    public array $includeDeleted = [];

    public int $queryCalls = 0;

    public int $writeCalls = 0;

    /** @param list<array<string, mixed>> $tasks @param list<array<string, mixed>> $events */
    public function __construct(
        private array $tasks = [],
        private array $events = [],
        private int $pageSize = 2000,
        private ?int $failOnQuery = null,
    ) {}

    public function queryPages(string $soql, bool $includeDeleted = false): Generator
    {
        $this->queryCalls++;
        $this->soql[] = $soql;
        $this->includeDeleted[] = $includeDeleted;
        if ($this->failOnQuery === $this->queryCalls) {
            throw new RuntimeException('remote sensitive-value token SQL');
        }

        $records = str_contains($soql, 'FROM Task') ? $this->tasks : $this->events;
        if ($records === []) {
            yield [];

            return;
        }
        foreach (array_chunk($records, $this->pageSize) as $page) {
            yield $page;
        }
    }

    public function create(string $object, array $fields): string
    {
        $this->writeCalls++;

        return 'forbidden';
    }

    public function update(string $object, string $id, array $fields): void
    {
        $this->writeCalls++;
    }
}
