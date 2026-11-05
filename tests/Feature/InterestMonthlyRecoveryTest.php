<?php

namespace Tests\Feature;

use App\Services\Salesforce\InterestMonthlyRecoveryService;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    /** @return array<string, mixed> */
    private function record(string $id, string $created, bool $deleted = false): array
    {
        return ['Id' => $id, 'CreatedDate' => $created, 'LastModifiedDate' => $created, 'SystemModstamp' => $created,
            'IsDeleted' => $deleted, 'IN_Lead__c' => null, 'IN_Account__c' => null, 'IN_Lead_Origen_Migracion__c' => null,
            'IN_Fecha_Creacion_Origen__c' => null, 'OwnerId' => null, 'Owner' => ['Name' => 'Synthetic owner']];
    }
}
