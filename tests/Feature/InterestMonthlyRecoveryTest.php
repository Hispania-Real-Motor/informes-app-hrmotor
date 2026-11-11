<?php

namespace Tests\Feature;

use App\Services\Salesforce\InterestMonthlyRecoveryService;
use App\Services\Salesforce\MonthlyInterestRecoveryLeaseLost;
use App\Services\Salesforce\SalesforceClient;
use App\Services\Salesforce\SalesforceInterestSyncService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class InterestMonthlyRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_october_current_inventory_is_isolated_checkpointed_and_not_historically_certified(): void
    {
        $records = [
            $this->record('a01000000000000001', '2026-10-01T00:30:00Z'),
            $this->record('a01000000000000002', '2026-10-31T22:30:00Z', true),
        ];
        $this->app->instance(SalesforceClient::class, new class($records) extends SalesforceClient
        {
            public function __construct(private array $records) {}

            public function queryPages(string $soql, bool $includeDeleted = false): \Generator
            {
                $this->assertTrue($includeDeleted);
                $after = preg_match("/Id > '([^']+)'/", $soql, $matches) === 1 ? $matches[1] : null;
                yield array_values(array_filter($this->records, fn (array $row): bool => $after === null || strcmp($row['Id'], $after) > 0));
            }

            private function assertTrue(bool $value): void
            {
                if (! $value) {
                    throw new \RuntimeException('queryAll was not requested.');
                }
            }
        });

        $service = app(InterestMonthlyRecoveryService::class);
        $id = $service->prepare(CarbonImmutable::parse('2026-10-01', 'Europe/Madrid'), CarbonImmutable::parse('2026-11-01', 'Europe/Madrid'), 'Build isolated October current inventory safely.', 500);
        $status = $service->run($id, 'Build isolated October current inventory safely.');

        $this->assertSame('completed', $status['status']);
        $this->assertSame('not_attempted_blocked', $status['f5_status']);
        $this->assertSame('unavailable', $status['historical_certification']);
        $this->assertSame(2, $status['counters']['persisted']);
        $this->assertSame(1, $status['counters']['soft_deleted_observed']);
        $this->assertDatabaseCount('salesforce_interest_generations', 0);
        $this->assertDatabaseCount('salesforce_interest_monthly_recovery_rows', 2);
        $this->assertSame('not_observable_without_independent_evidence', $status['evidence']['hard_deletes']);
    }

    public function test_monthly_content_hash_ignores_technical_sync_time_and_detects_functional_change(): void
    {
        $monthly = app(InterestMonthlyRecoveryService::class);
        $hash = new \ReflectionMethod($monthly, 'contentHash');
        $hash->setAccessible(true);
        $record = $this->record('a01000000000000001', '2026-10-10T10:00:00Z');
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-10T10:00:00Z'));
        try {
            $mapped = app(SalesforceInterestSyncService::class)->mapRecord($record, false);
            Carbon::setTestNow(CarbonImmutable::parse('2026-11-10T11:00:00Z'));
            $mappedAgain = app(SalesforceInterestSyncService::class)->mapRecord($record, false);
            $this->assertNotEquals($mapped['synced_at'], $mappedAgain['synced_at']);
            $this->assertSame($hash->invoke($monthly, $mapped), $hash->invoke($monthly, $mappedAgain));
        } finally {
            Carbon::setTestNow();
        }
        $first = $hash->invoke($monthly, $mapped);
        $mapped['synced_at'] = now('UTC')->addHour();
        $this->assertSame($first, $hash->invoke($monthly, $mapped));
        $mapped['status'] = 'Changed';
        $this->assertNotSame($first, $hash->invoke($monthly, $mapped));
        $this->assertNotContains('payload', Schema::getColumnListing('salesforce_interest_monthly_recovery_rows'));
    }

    public function test_multiple_functional_changes_are_counted_without_reclassifying_records_as_missing(): void
    {
        $records = [$this->record('a01000000000000001', '2026-10-10T10:00:00Z'),
            $this->record('a01000000000000002', '2026-10-10T11:00:00Z')];
        $this->app->instance(SalesforceClient::class, new class($records) extends SalesforceClient
        {
            private int $reads = 0;

            public function __construct(private array $records) {}

            public function queryPages(string $soql, bool $includeDeleted = false): \Generator
            {
                $this->reads++;
                $records = $this->records;
                if ($this->reads === 2) {
                    foreach ($records as &$record) {
                        $record['IN_Estado__c'] = 'Changed';
                    }
                    unset($record);
                }
                yield $records;
            }
        });
        $service = app(InterestMonthlyRecoveryService::class);
        $reason = 'Count multiple changed monthly records safely.';
        $id = $service->prepare(CarbonImmutable::parse('2026-10-01', 'Europe/Madrid'),
            CarbonImmutable::parse('2026-11-01', 'Europe/Madrid'), $reason, 500,
            CarbonImmutable::parse('2026-11-05T10:00:00Z'));

        $status = $service->run($id, $reason);

        $this->assertSame('completed', $status['status']);
        $this->assertFalse($status['evidence']['verification']['verified']);
        $this->assertSame(2, $status['counters']['content_changed']);
        $this->assertSame(0, $status['counters']['remote_missing_local']);
        $this->assertSame(0, $status['counters']['local_missing_remote']);
        $this->assertDatabaseCount('salesforce_interest_monthly_recovery_rows', 2);
    }

    public function test_completed_month_can_start_a_new_immutable_revision_without_changing_the_previous_one(): void
    {
        $record = $this->record('a01000000000000001', '2026-10-10T10:00:00Z');
        $this->app->instance(SalesforceClient::class, new class($record) extends SalesforceClient
        {
            public function __construct(private array $record) {}

            public function queryPages(string $soql, bool $includeDeleted = false): \Generator
            {
                yield [$this->record];
            }
        });
        $service = app(InterestMonthlyRecoveryService::class);
        $start = CarbonImmutable::parse('2026-10-01', 'Europe/Madrid');
        $end = CarbonImmutable::parse('2026-11-01', 'Europe/Madrid');
        $reason = 'Create independent immutable monthly revisions.';
        $cutoff = CarbonImmutable::parse('2026-11-05T10:00:00Z');

        $first = $service->prepare($start, $end, $reason, 500, $cutoff);
        $service->run($first, $reason);
        $this->assertSame($first, $service->prepare($start, $end, $reason, 500, $cutoff));
        $this->assertSame($first, $service->prepare($start, $end, $reason, 500, $cutoff, true));

        $second = $service->prepare($start, $end, $reason, 500, $cutoff->addDay(), true);
        $this->assertNotSame($first, $second);
        $this->assertSame(1, $service->status($first)['revision']);
        $this->assertSame(2, $service->status($second)['revision']);
        $this->assertSame('completed', $service->status($first)['status']);
        $this->assertDatabaseCount('salesforce_interest_monthly_recovery_rows', 1);
        $service->run($second, $reason);
        $this->assertDatabaseCount('salesforce_interest_monthly_recovery_rows', 2);
        $this->assertDatabaseCount('salesforce_interest_generations', 0);
    }

    public function test_open_november_uses_a_past_effective_cutoff_and_keeps_the_month_open(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-10T12:00:00Z'));
        try {
            $client = new class extends SalesforceClient
            {
                public array $queries = [];

                public function __construct() {}

                public function queryPages(string $soql, bool $includeDeleted = false): \Generator
                {
                    $this->queries[] = $soql;
                    yield [];
                }
            };
            $this->app->instance(SalesforceClient::class, $client);
            $service = app(InterestMonthlyRecoveryService::class);
            $start = CarbonImmutable::parse('2026-11-01', 'Europe/Madrid');
            $end = CarbonImmutable::parse('2026-12-01', 'Europe/Madrid');
            $reason = 'Extract open November only to the authorized cutoff.';
            $cutoff = CarbonImmutable::parse('2026-11-10T11:55:00Z');
            $id = $service->prepare($start, $end, $reason, 500, $cutoff);
            $status = $service->run($id, $reason);

            $this->assertSame(1, $status['revision']);
            $this->assertFalse($status['evidence']['month_closed_at_cutoff']);
            $this->assertFalse($status['evidence']['as_of_cutoff_certified']);
            $this->assertSame('2026-11-10 11:55:00', $status['period']['effective_cutoff']);
            $this->assertCount(2, $client->queries);
            foreach ($client->queries as $soql) {
                $this->assertStringContainsString('CreatedDate <= 2026-11-10T11:55:00Z', $soql);
                $this->assertStringNotContainsString('SystemModstamp <=', $soql);
                $this->assertStringContainsString('IN_Fecha_Creacion_Origen__c', $soql);
            }
            $this->assertDatabaseCount('salesforce_interest_generations', 0);
            $this->expectException(\RuntimeException::class);
            $service->prepare($start, $end, $reason, 500, CarbonImmutable::parse('2026-11-10T12:01:00Z'), true);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_verification_of_more_than_two_batches_uses_bounded_sql_queries(): void
    {
        $records = [];
        for ($i = 1; $i <= 1001; $i++) {
            $records[] = $this->record(sprintf('a01%015d', $i), '2026-10-10T10:00:00Z');
        }
        $this->app->instance(SalesforceClient::class, new class($records) extends SalesforceClient
        {
            public function __construct(private array $records) {}

            public function queryPages(string $soql, bool $includeDeleted = false): \Generator
            {
                $after = preg_match("/Id > '([^']+)'/", $soql, $matches) === 1 ? $matches[1] : null;
                $page = array_values(array_filter($this->records, fn (array $row): bool => $after === null || strcmp($row['Id'], $after) > 0));
                yield array_slice($page, 0, 500);
            }
        });
        $batchSelects = 0;
        $batchUpdates = 0;
        DB::listen(function ($query) use (&$batchSelects, &$batchUpdates): void {
            $sql = strtolower($query->sql);
            if (! str_contains($sql, 'salesforce_interest_monthly_recovery_rows') || ! str_contains($sql, ' in (')) {
                return;
            }
            $batchSelects += (int) str_starts_with($sql, 'select');
            $batchUpdates += (int) str_starts_with($sql, 'update');
        });

        $service = app(InterestMonthlyRecoveryService::class);
        $reason = 'Verify a large monthly inventory in bounded database batches.';
        $id = $service->prepare(CarbonImmutable::parse('2026-10-01', 'Europe/Madrid'), CarbonImmutable::parse('2026-11-01', 'Europe/Madrid'), $reason, 500);
        $status = $service->run($id, $reason);

        $this->assertSame(1001, $status['counters']['remote_checked']);
        $this->assertTrue($status['evidence']['verification']['verified']);
        $this->assertSame(3, $batchSelects);
        $this->assertSame(3, $batchUpdates);
        $this->assertDatabaseCount('salesforce_interest_monthly_recovery_rows', 1001);
    }

    public function test_salesforce_failure_exposes_only_safe_monthly_diagnostic(): void
    {
        $this->app->instance(SalesforceClient::class, new class extends SalesforceClient
        {
            public function __construct() {}

            public function queryPages(string $soql, bool $includeDeleted = false): \Generator
            {
                throw new \RuntimeException('SELECT confidential_value FROM Interes__c');
                yield [];
            }
        });
        $service = app(InterestMonthlyRecoveryService::class);
        $id = $service->prepare(CarbonImmutable::parse('2026-10-01', 'Europe/Madrid'), CarbonImmutable::parse('2026-11-01', 'Europe/Madrid'), 'Diagnose monthly query failure safely.');
        try {
            $service->run($id, 'Diagnose monthly query failure safely.');
            $this->fail('Expected the synthetic query failure.');
        } catch (\RuntimeException) {
            $status = $service->status($id);
            $this->assertSame('failed', $status['status']);
            $this->assertSame('f2_extract', $status['phase']);
            $this->assertSame('salesforce_query', $status['diagnostic']['stage']);
            $this->assertSame('salesforce_query_failed', $status['diagnostic']['category']);
            $this->assertArrayNotHasKey('message', $status['diagnostic']['meta']);
        }
    }

    public function test_consecutive_renewals_keep_a_valid_lease_running(): void
    {
        [$service, $id, $lease] = $this->runningRecovery();

        Carbon::setTestNow(CarbonImmutable::parse('2026-11-05 10:00:00', 'UTC'));
        try {
            $this->invokePrivate($service, 'renew', $id, $lease);
            $this->invokePrivate($service, 'renew', $id, $lease);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame('running', DB::table('salesforce_interest_monthly_recoveries')->where('id', $id)->value('status'));
        $this->assertSame($lease, DB::table('salesforce_interest_monthly_recoveries')->where('id', $id)->value('lease_token'));
    }

    public function test_renew_rejects_a_lease_replaced_by_another_executor(): void
    {
        [$service, $id, $lease] = $this->runningRecovery();
        $otherLease = (string) Str::uuid();
        DB::table('salesforce_interest_monthly_recoveries')->where('id', $id)->update(['lease_token' => $otherLease]);

        try {
            $this->invokePrivate($service, 'renew', $id, $lease);
            $this->fail('Expected a replaced lease to be rejected.');
        } catch (MonthlyInterestRecoveryLeaseLost) {
            $this->assertSame('running', DB::table('salesforce_interest_monthly_recoveries')->where('id', $id)->value('status'));
            $this->assertSame($otherLease, DB::table('salesforce_interest_monthly_recoveries')->where('id', $id)->value('lease_token'));
        }
    }

    public function test_renew_rejects_a_revoked_lease(): void
    {
        [$service, $id, $lease] = $this->runningRecovery();
        DB::table('salesforce_interest_monthly_recoveries')->where('id', $id)->update(['status' => 'cancelled', 'lease_token' => null]);

        $this->expectException(MonthlyInterestRecoveryLeaseLost::class);
        $this->invokePrivate($service, 'renew', $id, $lease);
    }

    public function test_lease_loss_is_classified_without_exposing_exception_details(): void
    {
        [$service, $id, $lease] = $this->runningRecovery();

        $this->invokePrivate($service, 'recordFailure', $id, $lease, 'salesforce_query', new MonthlyInterestRecoveryLeaseLost);

        $status = $service->status($id);
        $this->assertSame('failed', $status['status']);
        $this->assertSame('salesforce_query', $status['diagnostic']['stage']);
        $this->assertSame('lease_lost', $status['diagnostic']['category']);
        $this->assertArrayNotHasKey('message', $status['diagnostic']['meta']);
    }

    /** @return array{InterestMonthlyRecoveryService, int, string} */
    private function runningRecovery(): array
    {
        $service = app(InterestMonthlyRecoveryService::class);
        $id = $service->prepare(CarbonImmutable::parse('2026-10-01', 'Europe/Madrid'), CarbonImmutable::parse('2026-11-01', 'Europe/Madrid'), 'Exercise the fenced lease renewal safely.');
        $lease = (string) Str::uuid();
        DB::table('salesforce_interest_monthly_recoveries')->where('id', $id)->update(['status' => 'running', 'lease_token' => $lease, 'heartbeat_at' => now('UTC')]);

        return [$service, $id, $lease];
    }

    private function invokePrivate(object $object, string $method, mixed ...$arguments): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$arguments);
    }

    /** @return array<string, mixed> */
    private function record(string $id, string $created, bool $deleted = false): array
    {
        return ['Id' => $id, 'CreatedDate' => $created, 'LastModifiedDate' => $created, 'SystemModstamp' => $created,
            'IsDeleted' => $deleted, 'IN_Lead__c' => null, 'IN_Account__c' => null, 'IN_Lead_Origen_Migracion__c' => null,
            'IN_Fecha_Creacion_Origen__c' => null, 'OwnerId' => null, 'Owner' => ['Name' => 'Synthetic owner']];
    }
}
