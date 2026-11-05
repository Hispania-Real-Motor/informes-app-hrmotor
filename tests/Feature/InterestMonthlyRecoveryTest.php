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
        $mapped = app(SalesforceInterestSyncService::class)->mapRecord($this->record('a01000000000000001', '2026-10-10T10:00:00Z'), false);
        $first = $hash->invoke($monthly, $mapped);
        $mapped['synced_at'] = now('UTC')->addHour();
        $this->assertSame($first, $hash->invoke($monthly, $mapped));
        $mapped['status'] = 'Changed';
        $this->assertNotSame($first, $hash->invoke($monthly, $mapped));
        $this->assertNotContains('payload', Schema::getColumnListing('salesforce_interest_monthly_recovery_rows'));
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
