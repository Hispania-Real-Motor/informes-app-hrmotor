<?php

namespace Tests\Feature;

use App\Services\Operations\OperationalTaskCatalog;
use App\Services\Operations\OperationalTaskOutput;
use App\Services\Salesforce\InterestMonthlyRecoveryService;
use App\Services\Salesforce\SalesforceClient;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SyncCurrentMonthInterestsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_operational_task_is_disabled_by_default_and_cannot_read_without_explicit_authorization(): void
    {
        $catalog = app(OperationalTaskCatalog::class);
        $task = $catalog->get('salesforce-sync-interest-monthly-current');
        $this->assertFalse($task['enabled']);
        $this->assertFalse($task['manual_allowed']);
        $this->assertFalse($catalog->isScheduled('salesforce-sync-interest-monthly-current'));
        $this->assertSame('salesforce:sync-interest-monthly-current', $task['command']);
        $this->assertSame(1, Artisan::call($task['command']));
        $this->assertDatabaseCount('salesforce_interest_monthly_recoveries', 0);

        config()->set('salesforce.interest_monthly_current_sync_enabled', true);
        config()->set('operations.scheduled_task_keys', 'salesforce-sync-interest-monthly-current');
        $this->assertTrue($catalog->isScheduled('salesforce-sync-interest-monthly-current'));
        config()->set('operations.scheduled_task_keys', 'operations-health');
        $this->assertFalse($catalog->isScheduled('salesforce-sync-interest-monthly-current'));
    }

    public function test_empty_open_month_can_refresh_all_three_isolated_journals_without_publication_and_is_idempotent(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-10T12:00:00Z'));
        try {
            $client = new class extends SalesforceClient
            {
                public int $calls = 0;

                public function __construct() {}

                public function queryPages(string $soql, bool $includeDeleted = false): \Generator
                {
                    $this->calls++;
                    yield [];
                }
            };
            $this->app->instance(SalesforceClient::class, $client);
            $this->assertSame(0, Artisan::call('salesforce:sync-interest-monthly-current', ['--allow-salesforce-read' => true]));
            $this->assertDatabaseCount('salesforce_interest_monthly_recoveries', 1);
            $this->assertDatabaseCount('salesforce_interest_monthly_materializations', 1);
            $this->assertDatabaseCount('salesforce_interest_monthly_activity_recoveries', 1);
            $this->assertDatabaseCount('salesforce_interest_generations', 0);
            $this->assertNull(DB::table('salesforce_interest_publication')->where('id', 1)->value('generation_id'));
            $firstCalls = $client->calls;

            $this->assertSame(0, Artisan::call('salesforce:sync-interest-monthly-current', ['--allow-salesforce-read' => true]));
            $this->assertSame($firstCalls, $client->calls);
            $this->assertDatabaseCount('salesforce_interest_monthly_recoveries', 1);
            $output = new OperationalTaskOutput('salesforce-sync-interest-monthly-current');
            $output->writeln(Artisan::output());
            $this->assertTrue($output->skipped);

            Carbon::setTestNow(CarbonImmutable::parse('2026-11-11T12:00:00Z'));
            $this->assertSame(0, Artisan::call('salesforce:sync-interest-monthly-current', ['--allow-salesforce-read' => true]));
            $this->assertDatabaseCount('salesforce_interest_monthly_recoveries', 2);
            $this->assertDatabaseCount('salesforce_interest_monthly_materializations', 2);
            $this->assertDatabaseCount('salesforce_interest_monthly_activity_recoveries', 2);
            $this->assertDatabaseCount('salesforce_interest_generations', 0);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_unsafe_first_minute_does_not_prepare_a_future_cutoff(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-31T23:01:30Z'));
        try {
            $this->assertSame(0, Artisan::call('salesforce:sync-interest-monthly-current', ['--allow-salesforce-read' => true]));
            $this->assertDatabaseCount('salesforce_interest_monthly_recoveries', 0);
            $output = new OperationalTaskOutput('salesforce-sync-interest-monthly-current');
            $output->writeln(Artisan::output());
            $this->assertTrue($output->skipped);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_failed_monthly_inventory_is_resumed_before_a_new_revision_is_prepared(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-10T12:00:00Z'));
        try {
            $client = new class extends SalesforceClient
            {
                public int $calls = 0;

                public function __construct() {}

                public function queryPages(string $soql, bool $includeDeleted = false): \Generator
                {
                    $this->calls++;
                    if ($this->calls === 1) {
                        throw new \RuntimeException('Synthetic sensitive Salesforce failure');
                    }
                    yield [];
                }
            };
            $this->app->instance(SalesforceClient::class, $client);
            $this->assertSame(1, Artisan::call('salesforce:sync-interest-monthly-current', ['--allow-salesforce-read' => true]));
            $failed = DB::table('salesforce_interest_monthly_recoveries')->first();
            $this->assertSame('failed', $failed->status);
            $this->assertSame('f2_extract', $failed->phase);
            $this->assertSame(0, $failed->pages_completed);
            $this->assertStringNotContainsString('Synthetic sensitive', Artisan::output());

            $this->assertSame(0, Artisan::call('salesforce:sync-interest-monthly-current', ['--allow-salesforce-read' => true]));
            $this->assertDatabaseCount('salesforce_interest_monthly_recoveries', 1);
            $this->assertSame('completed', DB::table('salesforce_interest_monthly_recoveries')->first()->status);
            $this->assertDatabaseCount('salesforce_interest_generations', 0);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_november_refresh_keeps_a_completed_october_inventory_unchanged(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-10T12:00:00Z'));
        try {
            $this->app->instance(SalesforceClient::class, new class extends SalesforceClient
            {
                public function __construct() {}

                public function queryPages(string $soql, bool $includeDeleted = false): \Generator
                {
                    yield [];
                }
            });
            $f2 = app(InterestMonthlyRecoveryService::class);
            $reason = 'Preserve the October monthly evidence during November refresh.';
            $octoberId = $f2->prepare(
                CarbonImmutable::parse('2026-10-01', 'Europe/Madrid'),
                CarbonImmutable::parse('2026-11-01', 'Europe/Madrid'),
                $reason,
                500,
                CarbonImmutable::parse('2026-11-05T10:00:00Z'),
            );
            $f2->run($octoberId, $reason);
            $before = DB::table('salesforce_interest_monthly_recoveries')->find($octoberId);

            $this->assertSame(0, Artisan::call('salesforce:sync-interest-monthly-current', ['--allow-salesforce-read' => true]));
            $after = DB::table('salesforce_interest_monthly_recoveries')->find($octoberId);
            $this->assertSame($before->evidence, $after->evidence);
            $this->assertSame($before->counters, $after->counters);
            $this->assertSame($before->updated_at, $after->updated_at);
            $this->assertSame('completed', $after->status);
            $this->assertDatabaseCount('salesforce_interest_monthly_recoveries', 2);
        } finally {
            Carbon::setTestNow();
        }
    }
}
