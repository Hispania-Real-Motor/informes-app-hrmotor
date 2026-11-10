<?php

namespace Tests\Feature;

use App\Services\Salesforce\InterestMonthlyContentHasher;
use App\Services\Salesforce\InterestMonthlyMaterializationService;
use App\Services\Salesforce\MonthlyInterestRecoveryLeaseLost;
use App\Services\Salesforce\SalesforceClient;
use App\Services\Salesforce\SalesforceInterestSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class InterestMonthlyMaterializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_materializes_only_frozen_monthly_identities_with_the_existing_functional_contract(): void
    {
        $recovery = $this->completedRecovery();
        $first = $this->record(1, '2026-10-02T08:00:00Z');
        $second = $this->record(2, '2026-10-03T08:00:00Z', true);
        $this->sourceRow($recovery, $first);
        $this->sourceRow($recovery, $second);
        $this->app->instance(SalesforceClient::class, new MonthlyMaterializationFakeClient(records: [$first, $second]));

        $service = app(InterestMonthlyMaterializationService::class);
        $id = $service->prepare($recovery, 'Materialize the frozen October functional inventory.');
        $status = $service->run($id, 'Materialize the frozen October functional inventory.');

        $this->assertSame('completed', $status['status']);
        $this->assertTrue($status['evidence']['verification']['verified']);
        $this->assertSame(2, $status['counters']['expected']);
        $this->assertSame(2, $status['counters']['materialized']);
        $this->assertSame(1, $status['counters']['soft_deleted_observed']);
        $this->assertSame('unavailable', $status['historical_certification']);
        $this->assertFalse($status['published']);
        $this->assertDatabaseHas('salesforce_interest_monthly_materialized_rows', [
            'monthly_materialization_id' => $id,
            'salesforce_id' => $first['Id'],
            'lead_salesforce_id' => $first['IN_Lead__c'],
            'status' => $first['IN_Estado__c'],
            'utm_campaign' => $first['IN_utm_campaign__c'],
            'content_matches_inventory' => true,
        ]);
        $this->assertNotContains('raw_payload', Schema::getColumnListing('salesforce_interest_monthly_materialized_rows'));
        $this->assertDatabaseCount('salesforce_interest_generations', 0);
        $this->assertDatabaseCount('salesforce_interest_monthly_activity_recoveries', 0);
    }

    public function test_it_separates_changed_missing_unexpected_and_duplicate_records(): void
    {
        $recovery = $this->completedRecovery();
        $first = $this->record(1, '2026-10-02T08:00:00Z');
        $second = $this->record(2, '2026-10-03T08:00:00Z');
        $changed = $first;
        $changed['IN_Estado__c'] = 'Changed';
        $unexpected = $this->record(3, '2026-10-04T08:00:00Z');
        $this->sourceRow($recovery, $first);
        $this->sourceRow($recovery, $second);
        $this->app->instance(SalesforceClient::class, new MonthlyMaterializationFakeClient(records: [$changed, $changed, $unexpected], returnAll: true));

        $service = app(InterestMonthlyMaterializationService::class);
        $id = $service->prepare($recovery, 'Audit mismatches without changing the frozen inventory.');
        $status = $service->run($id, 'Audit mismatches without changing the frozen inventory.');

        $this->assertFalse($status['evidence']['verification']['verified']);
        $this->assertSame(1, $status['counters']['content_changed']);
        $this->assertSame(1, $status['counters']['missing']);
        $this->assertSame(1, $status['counters']['unexpected']);
        $this->assertSame(1, $status['counters']['duplicates']);
        $this->assertDatabaseCount('salesforce_interest_monthly_materialized_rows', 1);
        $this->assertDatabaseHas('salesforce_interest_monthly_materialized_rows', ['salesforce_id' => $first['Id'], 'content_matches_inventory' => false]);
    }

    public function test_it_resumes_after_a_remote_failure_without_repeating_confirmed_batches(): void
    {
        $recovery = $this->completedRecovery();
        $records = [];
        for ($index = 1; $index <= 502; $index++) {
            $record = $this->record($index, '2026-10-02T08:00:00Z');
            $records[] = $record;
            $this->sourceRow($recovery, $record);
        }
        $this->app->instance(SalesforceClient::class, new MonthlyMaterializationFakeClient(records: $records, failOnCall: 2));

        $service = app(InterestMonthlyMaterializationService::class);
        $id = $service->prepare($recovery, 'Resume only after each materialized batch is committed.', 500);
        try {
            $service->run($id, 'Resume only after each materialized batch is committed.');
            $this->fail('Expected the synthetic remote interruption.');
        } catch (RuntimeException) {
            $failed = $service->status($id);
            $this->assertSame('failed', $failed['status']);
            $this->assertSame('materialize', $failed['phase']);
            $this->assertSame(500, $failed['counters']['materialized']);
        }

        $status = $service->run($id, 'Resume only after each materialized batch is committed.', true);
        $this->assertSame('completed', $status['status']);
        $this->assertSame(502, $status['counters']['materialized']);
        $this->assertSame(0, $status['counters']['missing']);
        $this->assertDatabaseCount('salesforce_interest_monthly_materialized_rows', 502);
    }

    public function test_it_records_a_functional_date_change_without_reclassifying_it_as_absence(): void
    {
        $recovery = $this->completedRecovery();
        $original = $this->record(1, '2026-10-02T08:00:00Z');
        $current = $original;
        $current['IN_Fecha_Creacion_Origen__c'] = '2026-10-06T08:00:00Z';
        $this->sourceRow($recovery, $original);
        $this->app->instance(SalesforceClient::class, new MonthlyMaterializationFakeClient(records: [$current]));

        $service = app(InterestMonthlyMaterializationService::class);
        $id = $service->prepare($recovery, 'Detect a changed functional date without expanding membership.');
        $status = $service->run($id, 'Detect a changed functional date without expanding membership.');

        $this->assertFalse($status['evidence']['verification']['verified']);
        $this->assertSame(1, $status['counters']['functional_date_changed']);
        $this->assertSame(1, $status['counters']['content_changed']);
        $this->assertSame(0, $status['counters']['missing']);
    }

    public function test_it_rejects_a_replaced_lease_without_advancing_the_checkpoint(): void
    {
        $recovery = $this->completedRecovery();
        $service = app(InterestMonthlyMaterializationService::class);
        $id = $service->prepare($recovery, 'Exercise the materialization lease fence safely.');
        $lease = (string) Str::uuid();
        DB::table('salesforce_interest_monthly_materializations')->where('id', $id)->update(['status' => 'running', 'lease_token' => $lease, 'heartbeat_at' => now('UTC')]);
        DB::table('salesforce_interest_monthly_materializations')->where('id', $id)->update(['lease_token' => (string) Str::uuid()]);

        $this->expectException(MonthlyInterestRecoveryLeaseLost::class);
        $this->invokePrivate($service, 'renew', $id, $lease);
    }

    public function test_next_revision_reuses_only_unchanged_verified_rows_and_fetches_changed_ids(): void
    {
        $first = $this->record(1, '2026-10-02T08:00:00Z');
        $second = $this->record(2, '2026-10-03T08:00:00Z');
        $previous = $this->completedRecovery(true);
        $this->sourceRow($previous, $first);
        $this->sourceRow($previous, $second);
        $this->app->instance(SalesforceClient::class, new MonthlyMaterializationFakeClient(records: [$first, $second]));
        $reason = 'Materialize a verified October revision without changing its source.';
        $service = app(InterestMonthlyMaterializationService::class);
        $previousRun = $service->prepare($previous, $reason);
        $this->assertTrue($service->run($previousRun, $reason)['evidence']['verification']['verified']);

        $changed = $second;
        $changed['IN_Estado__c'] = 'Changed';
        $next = $this->completedRecovery(true, 2);
        $this->sourceRow($next, $first);
        $this->sourceRow($next, $changed);
        $client = new MonthlyMaterializationFakeClient(records: [$changed]);
        $this->app->instance(SalesforceClient::class, $client);
        $service = app(InterestMonthlyMaterializationService::class);
        $nextRun = $service->prepare($next, $reason);
        $status = $service->run($nextRun, $reason);

        $this->assertSame($previousRun, $status['source_evidence']['reuse_materialization_id']);
        $this->assertSame(1, $client->calls());
        $this->assertSame(1, $status['counters']['reused']);
        $this->assertSame(1, $status['counters']['remote_received']);
        $this->assertSame(2, $status['counters']['materialized']);
        $this->assertTrue($status['evidence']['verification']['verified']);
        $this->assertDatabaseHas('salesforce_interest_monthly_materialized_rows', ['monthly_materialization_id' => $nextRun, 'salesforce_id' => $first['Id'], 'status' => 'Open']);
        $this->assertDatabaseHas('salesforce_interest_monthly_materialized_rows', ['monthly_materialization_id' => $nextRun, 'salesforce_id' => $second['Id'], 'status' => 'Changed']);
        $this->assertDatabaseCount('salesforce_interest_generations', 0);
    }

    private function completedRecovery(bool $verified = false, int $revision = 1): int
    {
        return DB::table('salesforce_interest_monthly_recoveries')->insertGetId([
            'recovery_identifier' => (string) Str::uuid(),
            'functional_start_at' => '2026-09-30 22:00:00',
            'functional_end_at' => '2026-10-31 23:00:00',
            'revision' => $revision, 'effective_cutoff_at' => $revision === 1 ? '2026-11-05 10:00:00' : '2026-11-06 10:00:00',
            'timezone' => 'Europe/Madrid', 'status' => 'completed', 'phase' => 'completed', 'batch_size' => 500,
            'pages_completed' => 1, 'counters' => json_encode([]), 'evidence' => json_encode(['verification' => ['verified' => $verified]]),
            'reason_hash' => hash('sha256', 'synthetic source'), 'started_at' => now('UTC'), 'completed_at' => now('UTC'), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string,mixed> $record */
    private function sourceRow(int $recovery, array $record): void
    {
        $mapped = app(SalesforceInterestSyncService::class)->mapRecord($record, (bool) $record['IsDeleted']);
        DB::table('salesforce_interest_monthly_recovery_rows')->insert([
            'monthly_recovery_id' => $recovery, 'salesforce_id' => $record['Id'],
            'content_hash' => app(InterestMonthlyContentHasher::class)->hash($mapped), 'is_deleted' => (bool) $record['IsDeleted'],
            'functional_created_at' => $mapped['functional_created_at'], 'system_modstamp_at' => $mapped['system_modstamp_at'], 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    private function record(int $number, string $created, bool $deleted = false): array
    {
        $id = 'a01'.str_pad((string) $number, 15, '0', STR_PAD_LEFT);

        return [
            'Id' => $id, 'CreatedDate' => $created, 'LastModifiedDate' => $created, 'SystemModstamp' => $created, 'IsDeleted' => $deleted,
            'IN_Lead__c' => '00Q'.str_pad((string) $number, 15, '0', STR_PAD_LEFT), 'IN_Account__c' => null,
            'IN_Lead_Origen_Migracion__c' => null, 'IN_Fecha_Creacion_Origen__c' => null, 'OwnerId' => '005'.str_pad((string) $number, 15, '0', STR_PAD_LEFT),
            'Owner' => ['Name' => 'Synthetic owner'], 'IN_Estado__c' => 'Open', 'IN_Tipo__c' => 'Lead', 'IN_Fuente_Origen__c' => 'Web',
            'IN_RP_Fuente_Original__c' => null, 'IN_Medio_Origen__c' => 'Organic', 'IN_Canal__c' => 'Digital', 'IN_Delegacion_Procedencia__c' => 'Madrid',
            'IN_utm_campaign__c' => 'october', 'IN_utm_id__c' => null, 'IN_utm_source__c' => 'google', 'IN_utm_medium__c' => 'organic',
            'IN_utm_content__c' => null, 'IN_utm_term__c' => null, 'IN_VEN_Vehiculo__c' => null, 'IN_TAS_Vehiculo__c' => null, 'IN_VEN_Oportunidad__c' => null,
        ];
    }

    private function invokePrivate(object $object, string $method, mixed ...$arguments): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$arguments);
    }
}

final class MonthlyMaterializationFakeClient extends SalesforceClient
{
    private int $calls = 0;

    public function calls(): int
    {
        return $this->calls;
    }

    /** @param list<array<string,mixed>> $records */
    public function __construct(
        private array $records = [],
        private ?int $failOnCall = null,
        private bool $returnAll = false,
    ) {}

    public function queryPages(string $soql, bool $includeDeleted = false): \Generator
    {
        $this->calls++;
        if (! $includeDeleted) {
            throw new RuntimeException('Monthly materialization must request queryAll.');
        }
        if ($this->calls === $this->failOnCall) {
            throw new RuntimeException('Sensitive remote detail must not enter diagnostics.');
        }
        preg_match_all("/'([A-Za-z0-9]{18})'/", $soql, $matches);
        $ids = $matches[1];
        yield $this->returnAll ? $this->records : array_values(array_filter($this->records, fn (array $record): bool => in_array($record['Id'], $ids, true)));
    }
}
