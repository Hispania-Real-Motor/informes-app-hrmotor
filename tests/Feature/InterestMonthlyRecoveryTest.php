<?php

namespace Tests\Feature;

use App\Services\Salesforce\InterestMonthlyRecoveryService;
use App\Services\Salesforce\SalesforceClient;
use App\Services\Salesforce\SalesforceInterestSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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

    /** @return array<string, mixed> */
    private function record(string $id, string $created, bool $deleted = false): array
    {
        return ['Id' => $id, 'CreatedDate' => $created, 'LastModifiedDate' => $created, 'SystemModstamp' => $created,
            'IsDeleted' => $deleted, 'IN_Lead__c' => null, 'IN_Account__c' => null, 'IN_Lead_Origen_Migracion__c' => null,
            'IN_Fecha_Creacion_Origen__c' => null, 'OwnerId' => null, 'Owner' => ['Name' => 'Synthetic owner']];
    }
}
