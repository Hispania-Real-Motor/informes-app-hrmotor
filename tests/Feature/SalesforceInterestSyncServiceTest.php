<?php

namespace Tests\Feature;

use App\Models\ReportSyncRun;
use App\Models\SalesforceInterest;
use App\Models\SalesforceInterestSyncError;
use App\Services\Reports\ReportSyncRunService;
use App\Services\Salesforce\SalesforceClient;
use App\Services\Salesforce\SalesforceInterestChunkPersister;
use App\Services\Salesforce\SalesforceInterestFoundationResolver;
use App\Services\Salesforce\SalesforceInterestSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class SalesforceInterestSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_sync_is_paginated_materialized_audited_and_read_only(): void
    {
        $client = $this->client(
            activePages: [
                [$this->record('a01000000000000101', ['IN_Lead__c' => '00Q000000000000101'])],
                [
                    $this->record('a01000000000000102', [
                        'IN_Account__c' => '001000000000000102',
                        'IN_Tipo__c' => 'Venta con cambio',
                        'IN_Estado__c' => 'Estado futuro',
                    ]),
                    $this->record('a01000000000000103', [
                        'IN_Lead__c' => '00Q000000000000103',
                        'IN_Account__c' => '001000000000000103',
                        'IN_Fecha_Creacion_Origen__c' => '2026-08-01T08:30:00Z',
                    ]),
                ],
            ],
            deletedPages: [[$this->record('a01000000000000104', ['IsDeleted' => true])]],
        );
        $cutoff = CarbonImmutable::parse('2026-09-25T12:00:00Z');

        $result = $this->service($client)->sync(SalesforceInterestSyncService::MODE_FULL, $cutoff);

        $this->assertSame(3, $result['stats']['pages']);
        $this->assertSame(4, $result['stats']['queried']);
        $this->assertSame(4, $result['stats']['inserted']);
        $this->assertSame(1, $result['stats']['deleted']);
        $this->assertSame(0, $result['stats']['errors']);
        $this->assertSame('completed', $result['run']->status);
        $this->assertSame('2026-09-25 12:00:00', $result['run']->source_cutoff_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $result['run']->timezone);
        $this->assertSame('Lead', SalesforceInterest::query()->find(1)?->canonical_person_type);
        $this->assertDatabaseHas('salesforce_interests', [
            'salesforce_id' => 'a01000000000000103',
            'canonical_person_type' => 'Account',
            'canonical_person_salesforce_id' => '001000000000000103',
            'functional_created_at' => '2026-08-01 08:30:00',
        ]);
        $this->assertDatabaseHas('salesforce_interests', [
            'salesforce_id' => 'a01000000000000102',
            'status' => 'Estado futuro',
            'type' => 'Venta con cambio',
        ]);
        $this->assertDatabaseHas('salesforce_interests', [
            'salesforce_id' => 'a01000000000000104',
            'is_deleted' => true,
            'salesforce_last_modified_at' => '2026-09-20 09:00:00',
            'salesforce_deleted_at' => '2026-09-20 09:05:00',
            'deletion_detection_source' => SalesforceInterestSyncService::DELETION_SOURCE_QUERY_ALL,
        ]);
        $payload = SalesforceInterest::query()->where('salesforce_id', 'a01000000000000101')->value('raw_payload');
        $this->assertArrayNotHasKey('attributes', $payload);
        $this->assertStringContainsString('FROM Interes__c', $client->calls[0]['soql']);
        $this->assertStringContainsString('SystemModstamp <= 2026-09-25T12:00:00Z', $client->calls[0]['soql']);
        $this->assertFalse($client->calls[0]['include_deleted']);
        $this->assertTrue($client->calls[1]['include_deleted']);
        $this->assertSame(0, $client->writeCalls);
    }

    public function test_large_salesforce_page_is_persisted_in_bounded_local_chunks(): void
    {
        $records = [];

        for ($index = 1; $index <= 401; $index++) {
            $records[] = $this->record(sprintf('a01%015d', $index));
        }

        $persister = new class extends SalesforceInterestChunkPersister
        {
            public array $batchSizes = [];

            public function persist(array $rows): array
            {
                $this->batchSizes[] = count($rows);

                return parent::persist($rows);
            }
        };

        $result = $this->service($this->client([$records], []), $persister)->sync(
            SalesforceInterestSyncService::MODE_FULL,
            CarbonImmutable::parse('2026-09-25T12:00:00Z'),
        );

        $this->assertSame([200, 200, 1], $persister->batchSizes);
        $this->assertLessThanOrEqual(200, max($persister->batchSizes));
        $this->assertSame(1, $result['stats']['pages']);
        $this->assertSame(401, $result['stats']['inserted']);
    }

    public function test_incremental_uses_only_completed_watermark_with_configurable_overlap(): void
    {
        $this->completedRun('2026-09-25 10:00:00');
        ReportSyncRun::query()->create([
            'dataset' => SalesforceInterestSyncService::DATASET,
            'source' => 'salesforce',
            'status' => 'failed',
            'period_start_at' => '2026-09-25 10:00:00',
            'period_end_at' => '2026-09-25 11:00:00',
            'source_cutoff_at' => '2026-09-25 11:00:00',
            'started_at' => '2026-09-25 11:00:00',
            'completed_at' => '2026-09-25 11:01:00',
            'timezone' => 'UTC',
        ]);
        $client = $this->client([[]], [[]]);

        $result = $this->service($client)->sync(
            SalesforceInterestSyncService::MODE_INCREMENTAL,
            CarbonImmutable::parse('2026-09-25T12:00:00Z'),
            300,
        );

        $this->assertSame('2026-09-25T09:55:00+00:00', $result['stats']['window_start']);
        $this->assertSame(300, $result['stats']['overlap_seconds']);
        $this->assertStringContainsString('SystemModstamp >= 2026-09-25T09:55:00Z', $client->calls[0]['soql']);
        $this->assertStringContainsString('ORDER BY SystemModstamp ASC, Id ASC', $client->calls[0]['soql']);
        $this->assertSame('2026-09-25 12:00:00', $result['run']->source_cutoff_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_same_window_is_idempotent_and_deleted_interest_can_be_reactivated(): void
    {
        $cutoff = CarbonImmutable::parse('2026-09-25T12:00:00Z');
        $record = $this->record('a01000000000000105');
        $first = $this->service($this->client([[$record]], [[]]))
            ->sync(SalesforceInterestSyncService::MODE_FULL, $cutoff);
        $second = $this->service($this->client([[$record]], [[]]))
            ->sync(SalesforceInterestSyncService::MODE_FULL, $cutoff);

        $this->assertSame(1, $first['stats']['inserted']);
        $this->assertSame(1, $second['stats']['unchanged']);
        $this->assertDatabaseCount('salesforce_interests', 1);

        $deleted = $this->record('a01000000000000105', ['IsDeleted' => true]);
        $this->service($this->client([[]], [[$deleted]]))
            ->sync(SalesforceInterestSyncService::MODE_FULL, $cutoff);
        $reactivated = $this->service($this->client([[$record]], [[]]))
            ->sync(SalesforceInterestSyncService::MODE_FULL, $cutoff);

        $this->assertSame(1, $reactivated['stats']['reactivated']);
        $this->assertDatabaseHas('salesforce_interests', [
            'salesforce_id' => 'a01000000000000105',
            'is_deleted' => false,
            'salesforce_deleted_at' => null,
            'deletion_detection_source' => null,
        ]);
    }

    public function test_later_page_failure_keeps_partial_rows_and_does_not_advance_watermark(): void
    {
        $this->completedRun('2026-09-25 10:00:00');
        $client = $this->client(
            activePages: [[$this->record('a01000000000000106')]],
            deletedPages: [],
            failAfterActivePages: 1,
        );

        try {
            $this->service($client)->sync(
                SalesforceInterestSyncService::MODE_INCREMENTAL,
                CarbonImmutable::parse('2026-09-25T12:00:00Z'),
            );
            $this->fail('The sync should fail after the first page.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('[redacted]', $exception->getMessage());
        }

        $this->assertDatabaseHas('salesforce_interests', ['salesforce_id' => 'a01000000000000106']);
        $failed = ReportSyncRun::query()->where('status', 'failed')->latest('id')->firstOrFail();
        $this->assertNull($failed->source_cutoff_at);
        $this->assertSame(1, $failed->stats['errors']);
        $this->assertDatabaseHas('salesforce_interest_sync_errors', [
            'report_sync_run_id' => $failed->id,
            'salesforce_id' => null,
            'phase' => 'active_query',
        ]);
        $this->assertStringNotContainsString('secret-value', $failed->error_message);
        $this->assertSame(
            '2026-09-25 10:00:00',
            ReportSyncRun::query()->where('status', 'completed')->max('source_cutoff_at'),
        );
    }

    public function test_persistence_error_is_audited_by_salesforce_id_and_fails_run(): void
    {
        $duplicateMigrationLead = 'SENSITIVE-ID-00001';
        $records = [
            $this->record('a01000000000000107', ['IN_Lead_Origen_Migracion__c' => $duplicateMigrationLead]),
            $this->record('a01000000000000108', ['IN_Lead_Origen_Migracion__c' => $duplicateMigrationLead]),
        ];

        try {
            $this->service($this->client([$records], [[]]))
                ->sync(SalesforceInterestSyncService::MODE_FULL, CarbonImmutable::parse('2026-09-25T12:00:00Z'));
            $this->fail('The duplicate migration origin must fail persistence.');
        } catch (RuntimeException $exception) {
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString($duplicateMigrationLead, $exception->getMessage());
            $this->assertStringNotContainsString('insert into', strtolower($exception->getMessage()));
        }

        $failed = ReportSyncRun::query()->where('status', 'failed')->latest('id')->firstOrFail();
        $error = SalesforceInterestSyncError::query()
            ->where('report_sync_run_id', $failed->id)
            ->where('salesforce_id', 'a01000000000000108')
            ->firstOrFail();
        $this->assertSame('active_persist', $error->phase);
        $this->assertSame('Interest record could not be persisted locally.', $error->error_message);
        $this->assertStringNotContainsString($duplicateMigrationLead, $error->error_message);
        $this->assertStringNotContainsString('insert into', strtolower($error->error_message));
        $this->assertNull($failed->source_cutoff_at);
        $this->assertDatabaseCount('salesforce_interests', 0);
    }

    public function test_new_interest_cannot_take_migration_origin_owned_by_another_interest(): void
    {
        $origin = '00Q000000000000901';
        $ownerId = 'a01000000000000901';
        $incomingId = 'a01000000000000902';

        $this->service($this->client([[$this->record($ownerId, ['IN_Lead_Origen_Migracion__c' => $origin])]], []))
            ->sync(SalesforceInterestSyncService::MODE_FULL, CarbonImmutable::parse('2026-09-25T10:00:00Z'));

        try {
            $this->service($this->client([[$this->record($incomingId, ['IN_Lead_Origen_Migracion__c' => $origin])]], []))
                ->sync(SalesforceInterestSyncService::MODE_FULL, CarbonImmutable::parse('2026-09-25T11:00:00Z'));
            $this->fail('A migration origin already owned by another Interest must fail.');
        } catch (RuntimeException) {
            $this->assertDatabaseHas('salesforce_interest_sync_errors', [
                'salesforce_id' => $incomingId,
                'phase' => 'active_persist',
            ]);
        }

        $this->assertDatabaseHas('salesforce_interests', [
            'salesforce_id' => $ownerId,
            'migration_origin_lead_id' => $origin,
        ]);
        $this->assertDatabaseMissing('salesforce_interests', ['salesforce_id' => $incomingId]);
    }

    public function test_interest_can_update_while_retaining_its_own_migration_origin(): void
    {
        $origin = '00Q000000000000903';
        $salesforceId = 'a01000000000000903';
        $cutoff = CarbonImmutable::parse('2026-09-25T12:00:00Z');

        $this->service($this->client([[$this->record($salesforceId, [
            'IN_Lead_Origen_Migracion__c' => $origin,
            'IN_Estado__c' => 'Potencial',
        ])]], []))->sync(SalesforceInterestSyncService::MODE_FULL, $cutoff);

        $result = $this->service($this->client([[$this->record($salesforceId, [
            'IN_Lead_Origen_Migracion__c' => $origin,
            'IN_Estado__c' => 'Convertido',
        ])]], []))->sync(SalesforceInterestSyncService::MODE_FULL, $cutoff);

        $this->assertSame(1, $result['stats']['updated']);
        $this->assertDatabaseHas('salesforce_interests', [
            'salesforce_id' => $salesforceId,
            'migration_origin_lead_id' => $origin,
            'status' => 'Convertido',
        ]);
    }

    public function test_interest_cannot_change_to_migration_origin_owned_by_another_interest(): void
    {
        $firstId = 'a01000000000000904';
        $secondId = 'a01000000000000905';
        $firstOrigin = '00Q000000000000904';
        $secondOrigin = '00Q000000000000905';
        $cutoff = CarbonImmutable::parse('2026-09-25T12:00:00Z');

        $this->service($this->client([[
            $this->record($firstId, ['IN_Lead_Origen_Migracion__c' => $firstOrigin]),
            $this->record($secondId, ['IN_Lead_Origen_Migracion__c' => $secondOrigin]),
        ]], []))->sync(SalesforceInterestSyncService::MODE_FULL, $cutoff);

        try {
            $this->service($this->client([[$this->record($firstId, [
                'IN_Lead_Origen_Migracion__c' => $secondOrigin,
            ])]], []))->sync(SalesforceInterestSyncService::MODE_FULL, $cutoff);
            $this->fail('Changing to an origin owned by another Interest must fail.');
        } catch (RuntimeException) {
            $this->assertDatabaseHas('salesforce_interest_sync_errors', [
                'salesforce_id' => $firstId,
                'phase' => 'active_persist',
            ]);
        }

        $this->assertDatabaseHas('salesforce_interests', [
            'salesforce_id' => $firstId,
            'migration_origin_lead_id' => $firstOrigin,
        ]);
        $this->assertDatabaseHas('salesforce_interests', [
            'salesforce_id' => $secondId,
            'migration_origin_lead_id' => $secondOrigin,
        ]);
    }

    public function test_bulk_insert_is_plain_and_allows_multiple_null_migration_origins(): void
    {
        DB::enableQueryLog();
        $records = [
            $this->record('a01000000000000906'),
            $this->record('a01000000000000907'),
        ];

        $result = $this->service($this->client([$records], []))->sync(
            SalesforceInterestSyncService::MODE_FULL,
            CarbonImmutable::parse('2026-09-25T12:00:00Z'),
        );

        $interestWrites = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query): bool => str_contains(strtolower($query), 'insert into "salesforce_interests"'));

        $this->assertSame(2, $result['stats']['inserted']);
        $this->assertDatabaseCount('salesforce_interests', 2);
        $this->assertNotEmpty($interestWrites);
        $this->assertTrue($interestWrites->every(
            fn (string $query): bool => ! str_contains(strtolower($query), 'on conflict')
                && ! str_contains(strtolower($query), 'on duplicate'),
        ), 'Interest writes must never use upsert semantics because MySQL considers every UNIQUE index.');
    }

    public function test_bulk_failure_fails_run_even_when_every_diagnostic_replay_succeeds(): void
    {
        $persister = new class extends SalesforceInterestChunkPersister
        {
            public function persist(array $rows): array
            {
                if (count($rows) > 1) {
                    throw new RuntimeException('insert into sensitive_bulk_value');
                }

                return parent::persist($rows);
            }
        };
        $records = [
            $this->record('a01000000000000908'),
            $this->record('a01000000000000909'),
        ];

        try {
            $this->service($this->client([$records], []), $persister)->sync(
                SalesforceInterestSyncService::MODE_FULL,
                CarbonImmutable::parse('2026-09-25T12:00:00Z'),
            );
            $this->fail('The original bulk failure must remain fatal.');
        } catch (RuntimeException $exception) {
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('sensitive_bulk_value', $exception->getMessage());
        }

        $failed = ReportSyncRun::query()->where('status', 'failed')->latest('id')->firstOrFail();
        $this->assertNull($failed->source_cutoff_at);
        $this->assertDatabaseHas('salesforce_interest_sync_errors', [
            'report_sync_run_id' => $failed->id,
            'salesforce_id' => null,
            'phase' => 'active_persist_bulk',
            'error_message' => 'Interest record could not be persisted locally.',
        ]);
        $this->assertDatabaseHas('salesforce_interests', ['salesforce_id' => 'a01000000000000908']);
        $this->assertDatabaseHas('salesforce_interests', ['salesforce_id' => 'a01000000000000909']);
    }

    public function test_bulk_failure_audits_individual_failure_and_still_fails_run(): void
    {
        $failingId = 'a01000000000000911';
        $persister = new class($failingId) extends SalesforceInterestChunkPersister
        {
            public function __construct(private readonly string $failingId) {}

            public function persist(array $rows): array
            {
                if (count($rows) > 1 || ($rows[0]['salesforce_id'] ?? null) === $this->failingId) {
                    throw new RuntimeException('insert into sensitive_row_value');
                }

                return parent::persist($rows);
            }
        };
        $records = [
            $this->record('a01000000000000910'),
            $this->record($failingId),
        ];

        try {
            $this->service($this->client([$records], []), $persister)->sync(
                SalesforceInterestSyncService::MODE_FULL,
                CarbonImmutable::parse('2026-09-25T12:00:00Z'),
            );
            $this->fail('Bulk and individual persistence failures must fail the run.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('sensitive_row_value', $exception->getMessage());
        }

        $failed = ReportSyncRun::query()->where('status', 'failed')->latest('id')->firstOrFail();
        $this->assertNull($failed->source_cutoff_at);
        $this->assertDatabaseHas('salesforce_interest_sync_errors', [
            'report_sync_run_id' => $failed->id,
            'salesforce_id' => null,
            'phase' => 'active_persist_bulk',
        ]);
        $this->assertDatabaseHas('salesforce_interest_sync_errors', [
            'report_sync_run_id' => $failed->id,
            'salesforce_id' => $failingId,
            'phase' => 'active_persist',
        ]);
        $this->assertDatabaseHas('salesforce_interests', ['salesforce_id' => 'a01000000000000910']);
        $this->assertDatabaseMissing('salesforce_interests', ['salesforce_id' => $failingId]);
    }

    public function test_service_rejects_negative_overlap_before_remote_access(): void
    {
        $this->completedRun('2026-09-25 10:00:00');
        $client = $this->client([[]], [[]]);

        try {
            $this->service($client)->sync(
                SalesforceInterestSyncService::MODE_INCREMENTAL,
                overlapSeconds: -1,
            );
            $this->fail('Negative overlap must be rejected.');
        } catch (\InvalidArgumentException) {
            $this->assertSame([], $client->calls);
        }
    }

    public function test_command_rejects_non_integer_overlap_values(): void
    {
        foreach (['', '3.5', 'abc', '-1'] as $invalidOverlap) {
            $this->artisan('salesforce:sync-interests', ['--overlap-seconds' => $invalidOverlap])
                ->expectsOutput('--overlap-seconds debe ser un entero mayor o igual que cero.')
                ->assertFailed();
        }
    }

    public function test_incremental_without_completed_watermark_is_rejected_before_remote_access(): void
    {
        $client = $this->client([[]], [[]]);

        $this->expectException(RuntimeException::class);

        try {
            $this->service($client)->sync(SalesforceInterestSyncService::MODE_INCREMENTAL);
        } finally {
            $this->assertSame([], $client->calls);
            $this->assertDatabaseCount('report_sync_runs', 0);
        }
    }

    private function service(
        SalesforceClient $client,
        ?SalesforceInterestChunkPersister $chunkPersister = null,
    ): SalesforceInterestSyncService {
        return new SalesforceInterestSyncService(
            $client,
            app(SalesforceInterestFoundationResolver::class),
            $chunkPersister ?? app(SalesforceInterestChunkPersister::class),
            app(ReportSyncRunService::class),
        );
    }

    private function completedRun(string $cutoff): ReportSyncRun
    {
        return ReportSyncRun::query()->create([
            'dataset' => SalesforceInterestSyncService::DATASET,
            'source' => SalesforceInterestSyncService::SOURCE,
            'status' => 'completed',
            'period_start_at' => '2026-09-25 09:00:00',
            'period_end_at' => $cutoff,
            'source_cutoff_at' => $cutoff,
            'started_at' => '2026-09-25 09:00:00',
            'completed_at' => $cutoff,
            'timezone' => 'UTC',
        ]);
    }

    /** @return array<string, mixed> */
    private function record(string $id, array $overrides = []): array
    {
        return array_replace([
            'attributes' => ['type' => 'Interes__c'],
            'Id' => $id,
            'CreatedDate' => '2026-09-01T09:00:00Z',
            'LastModifiedDate' => '2026-09-20T09:00:00Z',
            'SystemModstamp' => '2026-09-20T09:05:00Z',
            'IsDeleted' => false,
            'IN_Lead__c' => null,
            'IN_Account__c' => null,
            'IN_Lead_Origen_Migracion__c' => null,
            'IN_Fecha_Creacion_Origen__c' => null,
            'OwnerId' => '005000000000000001',
            'Owner' => ['attributes' => ['type' => 'User'], 'Name' => 'Usuario sintético'],
            'IN_Estado__c' => 'Potencial',
            'IN_Tipo__c' => 'Venta',
            'IN_Fuente_Origen__c' => 'Web',
            'IN_RP_Fuente_Original__c' => 'fixture',
            'IN_Medio_Origen__c' => 'Organic',
            'IN_Canal__c' => 'Formulario',
            'IN_Delegacion_Procedencia__c' => 'Delegación sintética',
            'IN_utm_campaign__c' => 'campaign',
            'IN_utm_id__c' => 'campaign-id',
            'IN_utm_source__c' => 'source',
            'IN_utm_medium__c' => 'medium',
            'IN_utm_content__c' => 'content',
            'IN_utm_term__c' => 'term',
            'IN_VEN_Vehiculo__c' => null,
            'IN_TAS_Vehiculo__c' => null,
            'IN_VEN_Oportunidad__c' => null,
        ], $overrides);
    }

    private function client(
        array $activePages = [],
        array $deletedPages = [],
        ?int $failAfterActivePages = null,
    ): SalesforceClient {
        return new class($activePages, $deletedPages, $failAfterActivePages) extends SalesforceClient
        {
            public array $calls = [];

            public int $writeCalls = 0;

            public function __construct(
                private readonly array $activePages,
                private readonly array $deletedPages,
                private readonly ?int $failAfterActivePages,
            ) {}

            public function queryPages(string $soql, bool $includeDeleted = false): \Generator
            {
                $this->calls[] = ['soql' => $soql, 'include_deleted' => $includeDeleted];
                $pages = $includeDeleted ? $this->deletedPages : $this->activePages;

                foreach ($pages as $index => $page) {
                    yield $page;

                    if (! $includeDeleted && $this->failAfterActivePages === $index + 1) {
                        throw new RuntimeException('Authorization: Bearer secret-value');
                    }
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
}
