<?php

namespace Tests\Feature;

use App\Services\Salesforce\InterestMonthlyMaterializationService;
use App\Services\Salesforce\InterestMonthlyRecoveryService;
use App\Services\Salesforce\MonthlyInterestActivityRecoveryService;
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
        foreach (['system_modstamp_at', 'salesforce_last_modified_at', 'owner_name'] as $field) {
            $changed = app(SalesforceInterestSyncService::class)->mapRecord($record, false);
            $changed[$field] = $field === 'owner_name' ? 'Another synthetic owner' : CarbonImmutable::parse('2026-11-12T08:36:13Z');
            $this->assertNotSame($first, $hash->invoke($monthly, $changed), $field);
        }
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
        $this->assertTrue($status['evidence']['verification']['verified']);
        $this->assertSame(2, $status['counters']['initial_content_changed']);
        $this->assertSame(2, $status['counters']['stabilized_rows']);
        $this->assertSame(2, $status['counters']['verification_pass']);
        $this->assertSame(0, $status['counters']['content_changed']);
        $this->assertSame(0, $status['counters']['remote_missing_local']);
        $this->assertSame(0, $status['counters']['local_missing_remote']);
        $this->assertDatabaseCount('salesforce_interest_monthly_recovery_rows', 2);
        $this->assertSame(2, DB::table('salesforce_interest_monthly_recovery_rows')->whereNotNull('first_mismatch_at')->whereNotNull('hash_matched_at')->whereNotNull('initial_content_hash')->count());
    }

    public function test_persistent_content_change_is_not_reclassified_as_absent_or_verified(): void
    {
        $record = $this->record('a01000000000000001', '2026-11-05T10:00:00Z');
        $client = new class($record) extends SalesforceClient
        {
            public int $fullReads = 0;

            public int $selectiveReads = 0;

            public function __construct(private array $record) {}

            public function queryPages(string $soql, bool $includeDeleted = false): \Generator
            {
                $record = $this->record;
                if (str_contains($soql, 'Id IN (')) {
                    $this->selectiveReads++;
                    $record['IN_Estado__c'] = 'Second';
                } else {
                    $this->fullReads++;
                    $record['IN_Estado__c'] = match ($this->fullReads) {
                        1 => 'First', 2 => 'Second', default => 'Third',
                    };
                }
                yield [$record];
            }
        };
        $this->app->instance(SalesforceClient::class, $client);
        $service = app(InterestMonthlyRecoveryService::class);
        $reason = 'Keep persistent content changes unverified.';
        $id = $service->prepare(CarbonImmutable::parse('2026-11-01', 'Europe/Madrid'),
            CarbonImmutable::parse('2026-12-01', 'Europe/Madrid'), $reason, 500,
            CarbonImmutable::parse('2026-11-11T10:00:00Z'));

        $status = $service->run($id, $reason);

        $this->assertSame('completed', $status['status']);
        $this->assertFalse($status['evidence']['verification']['verified']);
        $this->assertSame(1, $status['counters']['initial_content_changed']);
        $this->assertSame(1, $status['counters']['content_changed']);
        $this->assertSame(0, $status['counters']['local_missing_remote']);
        $this->assertSame(3, $client->fullReads);
        $this->assertSame(1, $client->selectiveReads);
        $row = DB::table('salesforce_interest_monthly_recovery_rows')->where('monthly_recovery_id', $id)->first();
        $this->assertNotNull($row->verified_at);
        $this->assertNull($row->hash_matched_at);
        $this->assertNotNull($row->first_mismatch_at);
        $this->assertNotNull($row->initial_content_hash);
    }

    public function test_growing_month_with_9330_rows_records_new_second_pass_differences_without_promotion(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-12T11:10:00Z'));
        try {
            $client = new class extends SalesforceClient
            {
                public int $fullReads = 0;

                public int $selectiveReads = 0;

                public function __construct() {}

                public function queryPages(string $soql, bool $includeDeleted = false): \Generator
                {
                    if (str_contains($soql, 'Id IN (')) {
                        $this->selectiveReads++;
                        preg_match_all("/'(a01[0-9]{15})'/", $soql, $matches);
                        yield array_map(fn (string $id): array => $this->record((int) substr($id, 3), 1), $matches[1]);

                        return;
                    }
                    $this->fullReads++;
                    $phase = intdiv($this->fullReads - 1, 19);
                    $after = preg_match("/Id > '(a01[0-9]{15})'/", $soql, $matches) === 1
                        ? (int) substr($matches[1], 3) : 0;
                    $rows = [];
                    for ($number = $after + 1; $number <= min($after + 500, 9330); $number++) {
                        $rows[] = $this->record($number, $phase);
                    }
                    yield $rows;
                }

                private function record(int $number, int $phase): array
                {
                    $record = ['Id' => sprintf('a01%015d', $number),
                        'CreatedDate' => '2026-11-05T10:00:00Z',
                        'LastModifiedDate' => '2026-11-05T10:00:00Z',
                        'SystemModstamp' => '2026-11-05T10:00:00Z',
                        'IsDeleted' => false, 'Owner' => ['Name' => 'Synthetic owner']];
                    if ($phase === 1 && $number <= 2) {
                        $record['IN_Estado__c'] = 'First change';
                    }
                    if ($phase === 2) {
                        if ($number === 1) {
                            $record['IN_Estado__c'] = 'First change';
                        } elseif ($number === 2) {
                            $record['IN_Estado__c'] = 'Second change';
                        } elseif ($number === 3) {
                            $record['Owner']['Name'] = 'Another synthetic owner';
                        } elseif ($number === 4) {
                            $record['SystemModstamp'] = '2026-11-12T10:57:09Z';
                        } elseif ($number === 5) {
                            $record['IN_Estado__c'] = 'New change';
                        }
                    }

                    return $record;
                }
            };
            $this->app->instance(SalesforceClient::class, $client);
            $service = app(InterestMonthlyRecoveryService::class);
            $reason = 'Reproduce a growing month with moving Salesforce values.';
            $id = $service->prepare(CarbonImmutable::parse('2026-11-01', 'Europe/Madrid'),
                CarbonImmutable::parse('2026-12-01', 'Europe/Madrid'), $reason, 500,
                CarbonImmutable::parse('2026-11-12T10:54:00Z'));

            $status = $service->run($id, $reason);

            $this->assertSame('completed', $status['status']);
            $this->assertFalse($status['evidence']['verification']['verified']);
            $this->assertSame(9330, $status['counters']['persisted']);
            $this->assertSame(9330, $status['counters']['remote_checked']);
            $this->assertSame(2, $status['counters']['initial_content_changed']);
            $this->assertSame(2, $status['counters']['stabilized_rows']);
            $this->assertSame(4, $status['counters']['content_changed']);
            $this->assertSame(0, $status['counters']['remote_missing_local']);
            $this->assertSame(0, $status['counters']['local_missing_remote']);
            $this->assertSame(38, $status['counters']['verification_query_attempts']);
            $this->assertSame(1, $status['counters']['reconciliation_read_attempts']);
            $this->assertSame(57, $client->fullReads);
            $this->assertSame(1, $client->selectiveReads);
            $this->assertSame(2, DB::table('salesforce_interest_monthly_difference_diagnostics')->where('monthly_recovery_id', $id)->where('verification_pass', 1)->count());
            $this->assertSame(2, DB::table('salesforce_interest_monthly_difference_diagnostics')->where('monthly_recovery_id', $id)->where('verification_pass', 0)->count());
            $this->assertSame(4, DB::table('salesforce_interest_monthly_difference_diagnostics')->where('monthly_recovery_id', $id)->where('verification_pass', 2)->count());
            $this->assertSame(5, DB::table('salesforce_interest_monthly_recovery_rows')->where('monthly_recovery_id', $id)->whereNotNull('first_mismatch_at')->count());
            $this->assertSame(1, $status['counters']['diagnostic_categories']['owner_name']);
            $this->assertSame(1, $status['counters']['diagnostic_categories']['system_modstamp']);
            $this->assertSame(2, $status['counters']['diagnostic_categories']['functional_fields']);
            $this->assertDatabaseCount('salesforce_interest_generations', 0);
            $this->assertDatabaseCount('salesforce_interest_monthly_materializations', 0);
            $this->assertDatabaseCount('salesforce_interest_monthly_activity_recoveries', 0);
            foreach ([InterestMonthlyMaterializationService::class, MonthlyInterestActivityRecoveryService::class] as $dependent) {
                try {
                    app($dependent)->prepare($id, $reason);
                    $this->fail('An unverified F2 must not prepare a dependent journal.');
                } catch (\RuntimeException $exception) {
                    $this->assertStringContainsString('verified monthly', $exception->getMessage());
                }
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_lease_replacement_during_verification_cannot_publish_diagnostics_or_advance_checkpoint(): void
    {
        $record = $this->record('a01000000000000001', '2026-11-05T10:00:00Z');
        $client = new class($record) extends SalesforceClient
        {
            public int $recoveryId = 0;

            private int $reads = 0;

            public function __construct(private array $record) {}

            public function queryPages(string $soql, bool $includeDeleted = false): \Generator
            {
                $this->reads++;
                if ($this->reads === 2) {
                    DB::table('salesforce_interest_monthly_recoveries')->where('id', $this->recoveryId)
                        ->update(['lease_token' => (string) Str::uuid(), 'heartbeat_at' => now('UTC')->subHour()]);
                }
                yield [$this->record];
            }
        };
        $this->app->instance(SalesforceClient::class, $client);
        $service = app(InterestMonthlyRecoveryService::class);
        $reason = 'Reject replaced lease during monthly verification.';
        $id = $service->prepare(CarbonImmutable::parse('2026-11-01', 'Europe/Madrid'),
            CarbonImmutable::parse('2026-12-01', 'Europe/Madrid'), $reason, 500,
            CarbonImmutable::parse('2026-11-11T10:00:00Z'));
        $client->recoveryId = $id;

        try {
            $service->run($id, $reason);
            $this->fail('The replaced lease must stop verification.');
        } catch (MonthlyInterestRecoveryLeaseLost) {
            $stopped = $service->status($id);
            $this->assertSame('running', $stopped['status']);
            $this->assertSame('verify', $stopped['phase']);
            $this->assertSame(0, $stopped['counters']['remote_checked']);
            $this->assertSame(1, $stopped['counters']['verification_query_attempts']);
            $this->assertDatabaseCount('salesforce_interest_monthly_difference_diagnostics', 0);
        }

        $resumed = $service->run($id, $reason, true);
        $this->assertTrue($resumed['evidence']['verification']['verified']);
        $this->assertSame(2, $resumed['counters']['verification_query_attempts']);
    }

    public function test_more_than_100_changes_do_not_trigger_selective_reconciliation(): void
    {
        $records = [];
        for ($number = 1; $number <= 101; $number++) {
            $records[] = $this->record(sprintf('a01%015d', $number), '2026-11-05T10:00:00Z');
        }
        $client = new class($records) extends SalesforceClient
        {
            public int $fullReads = 0;

            public int $selectiveReads = 0;

            public function __construct(private array $records) {}

            public function queryPages(string $soql, bool $includeDeleted = false): \Generator
            {
                if (str_contains($soql, 'Id IN (')) {
                    $this->selectiveReads++;
                    yield [];

                    return;
                }
                $this->fullReads++;
                $records = $this->records;
                if ($this->fullReads === 2) {
                    foreach ($records as &$record) {
                        $record['IN_Estado__c'] = 'Changed';
                    }
                    unset($record);
                }
                yield $records;
            }
        };
        $this->app->instance(SalesforceClient::class, $client);
        $service = app(InterestMonthlyRecoveryService::class);
        $reason = 'Stop selective reconciliation beyond the fixed bound.';
        $id = $service->prepare(CarbonImmutable::parse('2026-11-01', 'Europe/Madrid'),
            CarbonImmutable::parse('2026-12-01', 'Europe/Madrid'), $reason, 500,
            CarbonImmutable::parse('2026-11-11T10:00:00Z'));

        $status = $service->run($id, $reason);

        $this->assertFalse($status['evidence']['verification']['verified']);
        $this->assertSame(101, $status['counters']['content_changed']);
        $this->assertSame(1, $status['counters']['verification_pass']);
        $this->assertSame(0, $status['counters']['stabilization_attempts']);
        $this->assertSame(0, $client->selectiveReads);
        $this->assertSame(101, DB::table('salesforce_interest_monthly_difference_diagnostics')->where('monthly_recovery_id', $id)->count());
    }

    public function test_local_member_absent_from_later_period_query_remains_unverified_without_a_guessed_cause(): void
    {
        $records = [$this->record('a01000000000000001', '2026-11-05T10:00:00Z'),
            $this->record('a01000000000000002', '2026-11-05T10:00:00Z')];
        $this->app->instance(SalesforceClient::class, new class($records) extends SalesforceClient
        {
            private int $reads = 0;

            public function __construct(private array $records) {}

            public function queryPages(string $soql, bool $includeDeleted = false): \Generator
            {
                $this->reads++;
                yield $this->reads === 1 ? $this->records : [$this->records[1]];
            }
        });
        $service = app(InterestMonthlyRecoveryService::class);
        $reason = 'Keep absent monthly members blocked without inventing a cause.';
        $id = $service->prepare(CarbonImmutable::parse('2026-11-01', 'Europe/Madrid'),
            CarbonImmutable::parse('2026-12-01', 'Europe/Madrid'), $reason, 500,
            CarbonImmutable::parse('2026-11-11T10:00:00Z'));

        $status = $service->run($id, $reason);

        $this->assertFalse($status['evidence']['verification']['verified']);
        $this->assertSame(1, $status['counters']['local_missing_remote']);
        $this->assertSame(0, $status['counters']['content_changed']);
        $this->assertSame(0, $status['counters']['stabilization_attempts']);
        $this->assertDatabaseCount('salesforce_interest_monthly_difference_diagnostics', 0);
    }

    public function test_post_cutoff_system_modstamp_does_not_remove_a_valid_monthly_member(): void
    {
        $record = $this->record('a01000000000000001', '2026-11-05T10:00:00Z');
        $record['LastModifiedDate'] = '2026-11-12T08:36:13Z';
        $record['SystemModstamp'] = '2026-11-12T08:36:13Z';
        $this->app->instance(SalesforceClient::class, new class($record) extends SalesforceClient
        {
            public function __construct(private array $record) {}

            public function queryPages(string $soql, bool $includeDeleted = false): \Generator
            {
                yield [$this->record];
            }
        });
        $service = app(InterestMonthlyRecoveryService::class);
        $reason = 'Prove later system stamps preserve functional membership.';
        $id = $service->prepare(CarbonImmutable::parse('2026-11-01', 'Europe/Madrid'),
            CarbonImmutable::parse('2026-12-01', 'Europe/Madrid'), $reason, 500,
            CarbonImmutable::parse('2026-11-12T08:34:00Z'));

        $status = $service->run($id, $reason);

        $this->assertTrue($status['evidence']['verification']['verified']);
        $this->assertSame(1, $status['counters']['persisted']);
        $this->assertSame('2026-11-12 08:36:13', DB::table('salesforce_interest_monthly_recovery_rows')->where('monthly_recovery_id', $id)->value('system_modstamp_at'));
        $soql = app(SalesforceInterestSyncService::class)->functionalPeriodSoql(
            CarbonImmutable::parse('2026-11-01', 'Europe/Madrid'), CarbonImmutable::parse('2026-12-01', 'Europe/Madrid'),
            null, true, CarbonImmutable::parse('2026-11-12T08:34:00Z'));
        $this->assertStringContainsString('CreatedDate <=', $soql);
        $this->assertStringNotContainsString('SystemModstamp <=', $soql);
    }

    public function test_reconciliation_rejects_changed_membership_and_resumes_without_overwriting_the_checkpoint(): void
    {
        $record = $this->record('a01000000000000001', '2026-11-05T10:00:00Z');
        $client = new class($record) extends SalesforceClient
        {
            public bool $badMembership = true;

            private int $fullReads = 0;

            public function __construct(private array $record) {}

            public function queryPages(string $soql, bool $includeDeleted = false): \Generator
            {
                $record = $this->record;
                if (str_contains($soql, 'Id IN (')) {
                    $record['IN_Estado__c'] = 'Changed';
                    if ($this->badMembership) {
                        $record['IN_Fecha_Creacion_Origen__c'] = '2026-10-30T10:00:00Z';
                    }
                } else {
                    $this->fullReads++;
                    $record['IN_Estado__c'] = $this->fullReads === 1 ? 'Original' : 'Changed';
                }
                yield [$record];
            }
        };
        $this->app->instance(SalesforceClient::class, $client);
        $service = app(InterestMonthlyRecoveryService::class);
        $reason = 'Resume after unsafe reconciliation membership change.';
        $id = $service->prepare(CarbonImmutable::parse('2026-11-01', 'Europe/Madrid'),
            CarbonImmutable::parse('2026-12-01', 'Europe/Madrid'), $reason, 500,
            CarbonImmutable::parse('2026-11-11T10:00:00Z'));

        try {
            $service->run($id, $reason);
            $this->fail('Out-of-month content must be rejected.');
        } catch (\RuntimeException) {
            $failed = $service->status($id);
            $this->assertSame('failed', $failed['status']);
            $this->assertSame('reconcile', $failed['phase']);
            $this->assertSame('reconciliation_invalid', $failed['diagnostic']['category']);
            $this->assertSame(1, $failed['counters']['initial_content_changed']);
            $this->assertSame(1, $failed['counters']['reconciliation_read_attempts']);
            $this->assertSame(1, $failed['counters']['persisted']);
            $this->assertSame(1, DB::table('salesforce_interest_monthly_recovery_rows')->where('monthly_recovery_id', $id)->whereNull('initial_content_hash')->count());
            $this->assertArrayNotHasKey('message', $failed['diagnostic']['meta']);
        }

        $client->badMembership = false;
        $resumed = $service->run($id, $reason, true);
        $this->assertTrue($resumed['evidence']['verification']['verified']);
        $this->assertSame(2, $resumed['counters']['reconciliation_read_attempts']);
        $this->assertSame(1, $resumed['counters']['persisted']);
    }

    public function test_reconciliation_remote_reads_stop_after_three_failed_attempts(): void
    {
        $record = $this->record('a01000000000000001', '2026-11-05T10:00:00Z');
        $client = new class($record) extends SalesforceClient
        {
            public int $selectiveReads = 0;

            private int $fullReads = 0;

            public function __construct(private array $record) {}

            public function queryPages(string $soql, bool $includeDeleted = false): \Generator
            {
                if (str_contains($soql, 'Id IN (')) {
                    $this->selectiveReads++;
                    throw new \RuntimeException('Synthetic sensitive remote failure');
                }
                $this->fullReads++;
                $record = $this->record;
                $record['IN_Estado__c'] = $this->fullReads === 1 ? 'Original' : 'Changed';
                yield [$record];
            }
        };
        $this->app->instance(SalesforceClient::class, $client);
        $service = app(InterestMonthlyRecoveryService::class);
        $reason = 'Limit selective reconciliation remote reads safely.';
        $id = $service->prepare(CarbonImmutable::parse('2026-11-01', 'Europe/Madrid'),
            CarbonImmutable::parse('2026-12-01', 'Europe/Madrid'), $reason, 500,
            CarbonImmutable::parse('2026-11-11T10:00:00Z'));

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            try {
                $service->run($id, $reason, $attempt > 1);
                $this->fail('A remote failure or exhausted read budget must stop reconciliation.');
            } catch (\RuntimeException) {
                $status = $service->status($id);
                $this->assertSame('failed', $status['status']);
                $this->assertSame('reconcile', $status['phase']);
                $this->assertSame(min($attempt, 3), $status['counters']['reconciliation_read_attempts']);
                $this->assertSame(min($attempt, 3), $client->selectiveReads);
                $this->assertSame(1, $status['counters']['persisted']);
                $this->assertSame(1, DB::table('salesforce_interest_monthly_recovery_rows')->where('monthly_recovery_id', $id)->whereNull('initial_content_hash')->count());
                $this->assertArrayNotHasKey('message', $status['diagnostic']['meta']);
            }
        }
    }

    public function test_full_verification_query_budget_persists_across_resumes(): void
    {
        $record = $this->record('a01000000000000001', '2026-11-05T10:00:00Z');
        $client = new class($record) extends SalesforceClient
        {
            public int $remoteReads = 0;

            public function __construct(private array $record) {}

            public function queryPages(string $soql, bool $includeDeleted = false): \Generator
            {
                $this->remoteReads++;
                if ($this->remoteReads > 1) {
                    throw new \RuntimeException('Synthetic sensitive verification failure');
                }
                yield [$this->record];
            }
        };
        $this->app->instance(SalesforceClient::class, $client);
        $service = app(InterestMonthlyRecoveryService::class);
        $reason = 'Bound full verification reads across retries.';
        $id = $service->prepare(CarbonImmutable::parse('2026-11-01', 'Europe/Madrid'),
            CarbonImmutable::parse('2026-12-01', 'Europe/Madrid'), $reason, 500,
            CarbonImmutable::parse('2026-11-11T10:00:00Z'));

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $service->run($id, $reason, $attempt > 1);
                $this->fail('Verification must stop after a failure or exhausted query budget.');
            } catch (\RuntimeException) {
                $status = $service->status($id);
                $this->assertSame('failed', $status['status']);
                $this->assertSame('verify', $status['phase']);
                $this->assertSame(min($attempt, 4), $status['counters']['verification_query_attempts']);
                $this->assertSame(1 + min($attempt, 4), $client->remoteReads);
                $this->assertSame(1, $status['counters']['persisted']);
                $this->assertArrayNotHasKey('message', $status['diagnostic']['meta']);
            }
        }
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
        $firstRow = DB::table('salesforce_interest_monthly_recovery_rows')->where('monthly_recovery_id', $first)->first();
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
        $preserved = DB::table('salesforce_interest_monthly_recovery_rows')->where('monthly_recovery_id', $first)->first();
        $this->assertSame($firstRow->content_hash, $preserved->content_hash);
        $this->assertSame($firstRow->diagnostic_fingerprints, $preserved->diagnostic_fingerprints);
        $this->assertDatabaseCount('salesforce_interest_monthly_difference_diagnostics', 0);
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
        $this->assertSame(6, $batchUpdates);
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
