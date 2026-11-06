<?php

namespace Tests\Feature;

use App\Services\Salesforce\MonthlyInterestActivityRecoveryService;
use App\Services\Salesforce\SalesforceClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class MonthlyInterestActivityRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_an_isolated_f5_from_monthly_interests_and_keeps_f2_provisional(): void
    {
        $monthlyRecovery = $this->completedMonthlyRecovery(false);
        $first = $this->interestId(1);
        $second = $this->interestId(2);
        $this->monthlyInterest($monthlyRecovery, $first);
        $this->monthlyInterest($monthlyRecovery, $second, true);
        $client = new MonthlyActivityFakeClient(
            tasks: [$this->task(1, $first, false, '2026-11-06'), $this->task(2, $this->interestId(99))],
            events: [$this->event(1, $second, true)],
        );
        $service = new MonthlyInterestActivityRecoveryService($client);
        $run = $service->prepare($monthlyRecovery, 'Build isolated October F5 activity evidence safely.');

        $status = $service->run($run, 'Build isolated October F5 activity evidence safely.');

        $this->assertSame('completed', $status['status']);
        $this->assertSame($monthlyRecovery, $status['monthly_recovery_id']);
        $this->assertSame(2, $status['counters']['activities_related']);
        $this->assertSame(1, $status['counters']['discarded_other_period']);
        $this->assertSame(1, $status['counters']['activities_soft_deleted']);
        $this->assertSame(1, $status['counters']['interests_soft_deleted']);
        $this->assertFalse($status['source_evidence']['f2_verified']);
        $this->assertFalse($status['evidence']['combined_certified']);
        $this->assertSame('unavailable', $status['historical_certification']);
        $this->assertFalse($status['published']);
        $this->assertDatabaseCount('salesforce_interest_monthly_activity_rows', 2);
        $this->assertDatabaseCount('salesforce_interest_activities', 0);
        $this->assertDatabaseHas('salesforce_interest_monthly_activity_rows', ['activity_kind' => 'Task', 'interest_salesforce_id' => $first, 'activity_date' => '2026-11-06']);
        $this->assertDatabaseHas('salesforce_interest_monthly_activity_rows', ['activity_kind' => 'Event', 'interest_salesforce_id' => $second, 'activity_is_deleted' => true, 'interest_is_deleted' => true]);
        foreach ($client->soql as $soql) {
            $this->assertStringContainsString("What.Type = 'Interes__c'", $soql);
            $this->assertStringNotContainsString('WhatId IN', $soql);
        }
    }

    public function test_it_resumes_after_a_remote_failure_without_duplicates_or_advanced_checkpoint(): void
    {
        $monthlyRecovery = $this->completedMonthlyRecovery();
        $tasks = [];
        for ($index = 1; $index <= 502; $index++) {
            $id = $this->interestId($index);
            $this->monthlyInterest($monthlyRecovery, $id);
            $tasks[] = $this->task($index, $id);
        }
        $client = new MonthlyActivityFakeClient(tasks: $tasks, failOnCall: 2);
        $service = new MonthlyInterestActivityRecoveryService($client);
        $run = $service->prepare($monthlyRecovery, 'Resume F5 after a synthetic remote interruption safely.');

        try {
            $service->run($run, 'Resume F5 after a synthetic remote interruption safely.');
            $this->fail('Expected synthetic remote failure.');
        } catch (RuntimeException) {
            $failed = $service->status($run);
            $this->assertSame('failed', $failed['status']);
            $this->assertSame('task_extract', $failed['phase']);
            $this->assertSame(500, DB::table('salesforce_interest_monthly_activity_rows')->where('monthly_activity_recovery_id', $run)->count());
        }

        $completed = $service->run($run, 'Resume F5 after a synthetic remote interruption safely.', true);

        $this->assertSame('completed', $completed['status']);
        $this->assertSame(502, DB::table('salesforce_interest_monthly_activity_rows')->where('monthly_activity_recovery_id', $run)->count());
        $this->assertSame(502, $completed['counters']['activities_related']);
        $this->assertSame(502, $completed['evidence']['manifest']['rows']);
    }

    public function test_it_counts_changed_remote_content_without_counting_it_as_missing(): void
    {
        $monthlyRecovery = $this->completedMonthlyRecovery();
        $interest = $this->interestId(1);
        $original = $this->task(1, $interest, false, '2026-10-06');
        $changed = $this->task(1, $interest, false, '2026-10-07');
        $this->monthlyInterest($monthlyRecovery, $interest);
        $service = new MonthlyInterestActivityRecoveryService(new MonthlyActivityFakeClient(recordsByCall: [1 => [$original], 2 => [], 3 => [$changed], 4 => []]));
        $run = $service->prepare($monthlyRecovery, 'Verify that changed activity content remains distinct from absence.');

        $status = $service->run($run, 'Verify that changed activity content remains distinct from absence.');

        $this->assertSame(1, $status['counters']['content_changed']);
        $this->assertSame(0, $status['counters']['local_missing_remote']);
        $this->assertSame(0, $status['counters']['remote_missing_local']);
        $this->assertFalse($status['evidence']['verification']['verified']);
        $this->assertDatabaseHas('salesforce_interest_monthly_activity_rows', ['monthly_activity_recovery_id' => $run, 'activity_salesforce_id' => $this->taskId(1), 'verified_at' => null]);
    }

    public function test_it_counts_only_absent_local_rows_as_missing_remote(): void
    {
        $monthlyRecovery = $this->completedMonthlyRecovery();
        $interest = $this->interestId(1);
        $this->monthlyInterest($monthlyRecovery, $interest);
        $service = new MonthlyInterestActivityRecoveryService(new MonthlyActivityFakeClient(recordsByCall: [1 => [$this->task(1, $interest)], 2 => [], 3 => [], 4 => []]));
        $run = $service->prepare($monthlyRecovery, 'Verify that a truly absent activity remains missing remotely.');

        $status = $service->run($run, 'Verify that a truly absent activity remains missing remotely.');

        $this->assertSame(0, $status['counters']['content_changed']);
        $this->assertSame(1, $status['counters']['local_missing_remote']);
        $this->assertSame(0, $status['counters']['remote_missing_local']);
        $this->assertFalse($status['evidence']['verification']['verified']);
    }

    public function test_it_counts_remote_rows_without_a_local_activity_separately(): void
    {
        $monthlyRecovery = $this->completedMonthlyRecovery();
        $interest = $this->interestId(1);
        $this->monthlyInterest($monthlyRecovery, $interest);
        $service = new MonthlyInterestActivityRecoveryService(new MonthlyActivityFakeClient(recordsByCall: [1 => [], 2 => [], 3 => [$this->task(1, $interest)], 4 => []]));
        $run = $service->prepare($monthlyRecovery, 'Verify that a new remote activity has no local counterpart.');

        $status = $service->run($run, 'Verify that a new remote activity has no local counterpart.');

        $this->assertSame(0, $status['counters']['content_changed']);
        $this->assertSame(0, $status['counters']['local_missing_remote']);
        $this->assertSame(1, $status['counters']['remote_missing_local']);
        $this->assertFalse($status['evidence']['verification']['verified']);
    }

    public function test_it_resumes_verification_without_duplicate_discrepancy_counters(): void
    {
        $monthlyRecovery = $this->completedMonthlyRecovery();
        $tasks = [];
        for ($index = 1; $index <= 502; $index++) {
            $id = $this->interestId($index);
            $this->monthlyInterest($monthlyRecovery, $id);
            $tasks[] = $this->task($index, $id);
        }
        $service = new MonthlyInterestActivityRecoveryService(new MonthlyActivityFakeClient(tasks: $tasks, failOnCall: 5));
        $run = $service->prepare($monthlyRecovery, 'Resume the verification checkpoint without duplicate counters.');

        try {
            $service->run($run, 'Resume the verification checkpoint without duplicate counters.');
            $this->fail('Expected synthetic verification interruption.');
        } catch (RuntimeException) {
            $failed = $service->status($run);
            $this->assertSame('failed', $failed['status']);
            $this->assertSame('verify_task', $failed['phase']);
            $this->assertSame(500, $failed['counters']['remote_checked']);
            $this->assertSame(2, $failed['counters']['local_missing_remote']);
        }

        $completed = $service->run($run, 'Resume the verification checkpoint without duplicate counters.', true);

        $this->assertSame('completed', $completed['status']);
        $this->assertSame(502, $completed['counters']['remote_checked']);
        $this->assertSame(0, $completed['counters']['local_missing_remote']);
        $this->assertSame(0, $completed['counters']['content_changed']);
    }

    private function completedMonthlyRecovery(bool $verified = false): int
    {
        return DB::table('salesforce_interest_monthly_recoveries')->insertGetId([
            'recovery_identifier' => (string) str()->uuid(), 'functional_start_at' => '2026-09-30 22:00:00', 'functional_end_at' => '2026-10-31 23:00:00', 'timezone' => 'Europe/Madrid', 'status' => 'completed', 'phase' => 'completed', 'batch_size' => 500, 'pages_completed' => 1, 'counters' => '{}', 'reason_hash' => hash('sha256', 'synthetic monthly F2 recovery'), 'evidence' => json_encode(['verification' => ['verified' => $verified]]), 'started_at' => now('UTC'), 'completed_at' => now('UTC'), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function monthlyInterest(int $recovery, string $id, bool $deleted = false): void
    {
        DB::table('salesforce_interest_monthly_recovery_rows')->insert(['monthly_recovery_id' => $recovery, 'salesforce_id' => $id, 'content_hash' => hash('sha256', $id), 'is_deleted' => $deleted, 'functional_created_at' => '2026-10-01 08:00:00', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array<string,mixed> */
    private function task(int $index, string $whatId, bool $deleted = false, string $date = '2026-10-06'): array
    {
        return ['Id' => $this->taskId($index), 'WhatId' => $whatId, 'WhoId' => null, 'ActivityDate' => $date, 'CreatedDate' => '2026-10-05T08:00:00Z', 'IsDeleted' => $deleted, 'LastModifiedDate' => '2026-10-06T09:00:00Z', 'SystemModstamp' => '2026-10-06T09:01:00Z'];
    }

    /** @return array<string,mixed> */
    private function event(int $index, string $whatId, bool $deleted = false): array
    {
        return ['Id' => '00U'.str_pad((string) $index, 15, '0', STR_PAD_LEFT), 'WhatId' => $whatId, 'WhoId' => null, 'StartDateTime' => '2026-11-06T11:30:00Z', 'CreatedDate' => '2026-10-05T08:00:00Z', 'IsDeleted' => $deleted, 'LastModifiedDate' => '2026-11-06T09:00:00Z', 'SystemModstamp' => '2026-11-06T09:01:00Z'];
    }

    private function interestId(int $index): string
    {
        return 'a01'.str_pad((string) $index, 15, '0', STR_PAD_LEFT);
    }

    private function taskId(int $index): string
    {
        return '00T'.str_pad((string) $index, 15, '0', STR_PAD_LEFT);
    }
}

class MonthlyActivityFakeClient extends SalesforceClient
{
    /** @var list<string> */
    public array $soql = [];

    private int $calls = 0;

    /** @param list<array<string,mixed>> $tasks @param list<array<string,mixed>> $events @param array<int,list<array<string,mixed>>> $recordsByCall */
    public function __construct(private array $tasks = [], private array $events = [], private ?int $failOnCall = null, private array $recordsByCall = []) {}

    public function queryPages(string $soql, bool $includeDeleted = false): \Generator
    {
        $this->calls++;
        $this->soql[] = $soql;
        if ($this->failOnCall === $this->calls) {
            throw new RuntimeException('remote secret payload must not escape');
        }
        $records = $this->recordsByCall[$this->calls] ?? (str_contains($soql, 'FROM Task') ? $this->tasks : $this->events);
        $after = preg_match("/Id > '([^']+)'/", $soql, $matches) === 1 ? $matches[1] : null;
        preg_match('/LIMIT (\d+)/', $soql, $limit);
        $records = array_values(array_filter($records, fn (array $record): bool => $after === null || strcmp($record['Id'], $after) > 0));
        yield array_slice($records, 0, (int) ($limit[1] ?? 500));
    }
}
