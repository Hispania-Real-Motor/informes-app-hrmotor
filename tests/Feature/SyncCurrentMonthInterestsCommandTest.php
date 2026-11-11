<?php

namespace Tests\Feature;

use App\Services\Operations\OperationalTaskCatalog;
use App\Services\Operations\OperationalTaskOutput;
use App\Services\Reports\Leads\MonthlyInterestProvisionalReader;
use App\Services\Reports\Leads\ProvisionalInterestSourceUnavailable;
use App\Services\Salesforce\InterestMonthlyMaterializationService;
use App\Services\Salesforce\InterestMonthlyRecoveryService;
use App\Services\Salesforce\MonthlyInterestActivityRecoveryService;
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

    public function test_new_revision_stays_invisible_until_dependents_and_projection_are_complete(): void
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
            $command = 'salesforce:sync-interest-monthly-current';
            $options = ['--allow-salesforce-read' => true];
            $this->assertSame(0, Artisan::call($command, $options));
            $reader = app(MonthlyInterestProvisionalReader::class);
            $first = $reader->latestForMonth(2026, 11);
            $this->assertGreaterThan(0, $first['recovery_id']);

            Carbon::setTestNow(CarbonImmutable::parse('2026-11-11T12:00:00Z'));
            $reason = 'Synthetic second monthly revision';
            $f2 = app(InterestMonthlyRecoveryService::class);
            $secondId = $f2->prepare(
                CarbonImmutable::parse('2026-11-01', 'Europe/Madrid'),
                CarbonImmutable::parse('2026-12-01', 'Europe/Madrid'),
                $reason,
                500,
                CarbonImmutable::parse('2026-11-11T11:58:00Z'),
                true,
            );
            $this->assertSame($first['projection_key'], $reader->latestForMonth(2026, 11)['projection_key']);
            $f2->run($secondId, $reason);
            $this->assertSame($first['projection_key'], $reader->latestForMonth(2026, 11)['projection_key']);
            $f2m = app(InterestMonthlyMaterializationService::class);
            $f2m->run($f2m->prepare($secondId, $reason), $reason);
            $this->assertSame($first['projection_key'], $reader->latestForMonth(2026, 11)['projection_key']);
            $f5 = app(MonthlyInterestActivityRecoveryService::class);
            $f5->run($f5->prepare($secondId, $reason), $reason);
            $this->assertSame($first['projection_key'], $reader->latestForMonth(2026, 11)['projection_key']);

            $this->assertSame(0, Artisan::call($command, $options));
            $second = $reader->latestForMonth(2026, 11);
            $this->assertSame($secondId, $second['recovery_id']);
            $this->assertNotSame($first['projection_key'], $second['projection_key']);
            $this->assertSame(0, Artisan::call($command, $options));
            $this->assertSame($second['projection_key'], $reader->latestForMonth(2026, 11)['projection_key']);
            $this->assertDatabaseCount('salesforce_interest_generations', 0);
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

    public function test_changed_f2_is_preserved_and_a_later_revision_completes_the_isolated_pipeline(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-11T12:22:00Z'));
        try {
            $client = new class extends SalesforceClient
            {
                public int $inventoryReads = 0;

                public function __construct() {}

                public function queryPages(string $soql, bool $includeDeleted = false): \Generator
                {
                    if (str_contains($soql, 'FROM Interes__c')) {
                        if (str_contains($soql, 'Id IN (') || str_contains($soql, "Id > '")) {
                            yield str_contains($soql, 'Id IN (') ? [$this->record('Changed')] : [];

                            return;
                        }
                        $this->inventoryReads++;
                        yield [$this->record($this->inventoryReads === 1 ? 'Original' : 'Changed')];

                        return;
                    }
                    yield [];
                }

                private function record(string $status): array
                {
                    return ['Id' => 'a01000000000000001', 'CreatedDate' => '2026-11-05T10:00:00Z',
                        'LastModifiedDate' => '2026-11-05T10:00:00Z', 'SystemModstamp' => '2026-11-05T10:00:00Z',
                        'IsDeleted' => false, 'IN_Estado__c' => $status, 'IN_Lead__c' => null,
                        'IN_Account__c' => null, 'IN_Lead_Origen_Migracion__c' => null,
                        'IN_Fecha_Creacion_Origen__c' => null, 'OwnerId' => null,
                        'Owner' => ['Name' => 'Synthetic owner']];
                }
            };
            $this->app->instance(SalesforceClient::class, $client);
            $command = 'salesforce:sync-interest-monthly-current';
            $options = ['--allow-salesforce-read' => true];

            $this->assertSame(1, Artisan::call($command, $options));
            $first = DB::table('salesforce_interest_monthly_recoveries')->first();
            $firstRow = DB::table('salesforce_interest_monthly_recovery_rows')->where('monthly_recovery_id', $first->id)->first();
            $this->assertSame('completed', $first->status);
            $this->assertSame('completed', $first->phase);
            $this->assertFalse(data_get(json_decode($first->evidence, true), 'verification.verified'));
            $this->assertSame(1, data_get(json_decode($first->evidence, true), 'verification.content_changed'));
            $this->assertStringContainsString('integrity verification failed', Artisan::output());
            $this->assertStringNotContainsString('a01000000000000001', Artisan::output());
            $this->assertDatabaseCount('salesforce_interest_monthly_materializations', 0);
            $this->assertDatabaseCount('salesforce_interest_monthly_activity_recoveries', 0);
            $this->assertDatabaseCount('salesforce_interest_october_provisional_validations', 0);
            $unavailable = false;
            try {
                app(MonthlyInterestProvisionalReader::class)->latestForMonth(2026, 11);
            } catch (ProvisionalInterestSourceUnavailable) {
                $unavailable = true;
            }
            $this->assertTrue($unavailable);

            $this->assertSame(1, Artisan::call($command, $options));
            $this->assertSame(2, $client->inventoryReads);
            $this->assertDatabaseCount('salesforce_interest_monthly_recoveries', 1);

            $reason = 'Reject materialization of an unverified monthly inventory.';
            try {
                app(InterestMonthlyMaterializationService::class)->prepare((int) $first->id, $reason);
                $this->fail('F2M must reject an unverified F2.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('verified monthly inventory', $exception->getMessage());
            }
            try {
                app(MonthlyInterestActivityRecoveryService::class)->prepare((int) $first->id, $reason);
                $this->fail('F5 must reject an unverified F2.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('verified monthly F2', $exception->getMessage());
            }

            Carbon::setTestNow(CarbonImmutable::parse('2026-11-11T12:32:00Z'));
            $this->assertSame(0, Artisan::call($command, $options));
            $second = DB::table('salesforce_interest_monthly_recoveries')->orderByDesc('revision')->first();
            $this->assertSame(2, $second->revision);
            $this->assertSame('completed', $second->status);
            $this->assertTrue(data_get(json_decode($second->evidence, true), 'verification.verified'));
            $this->assertSame('2026-11-11 12:30:00', $second->effective_cutoff_at);
            $this->assertDatabaseCount('salesforce_interest_monthly_materializations', 1);
            $this->assertDatabaseCount('salesforce_interest_monthly_activity_recoveries', 1);
            $this->assertSame((int) $second->id, app(MonthlyInterestProvisionalReader::class)->latestForMonth(2026, 11)['recovery_id']);
            $this->assertDatabaseCount('salesforce_interest_generations', 0);
            $this->assertSame($first->evidence, DB::table('salesforce_interest_monthly_recoveries')->find($first->id)->evidence);
            $this->assertSame($firstRow->content_hash, DB::table('salesforce_interest_monthly_recovery_rows')->where('monthly_recovery_id', $first->id)->value('content_hash'));
            $this->assertSame(0, Artisan::call($command, $options));
            $this->assertDatabaseCount('salesforce_interest_monthly_recoveries', 2);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_two_invalid_revisions_do_not_retry_without_a_later_cutoff(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-11T12:22:00Z'));
        try {
            $client = new class extends SalesforceClient
            {
                public int $reads = 0;

                public function __construct() {}

                public function queryPages(string $soql, bool $includeDeleted = false): \Generator
                {
                    if (str_contains($soql, "FROM Interes__c") && ! str_contains($soql, "Id > '")) {
                        $this->reads++;
                        yield [['Id' => 'a01000000000000001', 'CreatedDate' => '2026-11-05T10:00:00Z',
                            'LastModifiedDate' => '2026-11-05T10:00:00Z', 'SystemModstamp' => '2026-11-05T10:00:00Z',
                            'IsDeleted' => false, 'IN_Estado__c' => match ($this->reads) {
                                1 => 'First', 2, 3 => 'Second', default => 'Third',
                            }, 'IN_Lead__c' => null, 'IN_Account__c' => null,
                            'IN_Lead_Origen_Migracion__c' => null, 'IN_Fecha_Creacion_Origen__c' => null,
                            'OwnerId' => null, 'Owner' => ['Name' => 'Synthetic owner']]];

                        return;
                    }
                    yield [];
                }
            };
            $this->app->instance(SalesforceClient::class, $client);
            $command = 'salesforce:sync-interest-monthly-current';
            $options = ['--allow-salesforce-read' => true];
            $this->assertSame(1, Artisan::call($command, $options));
            Carbon::setTestNow(CarbonImmutable::parse('2026-11-11T12:32:00Z'));
            $this->assertSame(1, Artisan::call($command, $options));
            $this->assertDatabaseCount('salesforce_interest_monthly_recoveries', 2);
            $this->assertSame(4, $client->reads);
            $this->assertSame(1, Artisan::call($command, $options));
            $this->assertSame(4, $client->reads);
            $this->assertDatabaseCount('salesforce_interest_monthly_recoveries', 2);
            $this->assertDatabaseCount('salesforce_interest_monthly_materializations', 0);
            $this->assertDatabaseCount('salesforce_interest_monthly_activity_recoveries', 0);
            foreach (DB::table('salesforce_interest_monthly_recoveries')->get() as $revision) {
                $this->assertFalse(data_get(json_decode($revision->evidence, true), 'verification.verified'));
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_remote_failure_in_new_revision_resumes_without_replacing_the_invalid_first_revision(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-11T12:22:00Z'));
        try {
            $client = new class extends SalesforceClient
            {
                public int $reads = 0;

                public function __construct() {}

                public function queryPages(string $soql, bool $includeDeleted = false): \Generator
                {
                    if (str_contains($soql, 'FROM Interes__c') && ! str_contains($soql, "Id > '")) {
                        $this->reads++;
                        if ($this->reads === 3) {
                            throw new \RuntimeException('Synthetic sensitive Salesforce failure');
                        }
                        yield [['Id' => 'a01000000000000001', 'CreatedDate' => '2026-11-05T10:00:00Z',
                            'LastModifiedDate' => '2026-11-05T10:00:00Z', 'SystemModstamp' => '2026-11-05T10:00:00Z',
                            'IsDeleted' => false, 'IN_Estado__c' => $this->reads === 1 ? 'First' : 'Second',
                            'IN_Lead__c' => null, 'IN_Account__c' => null,
                            'IN_Lead_Origen_Migracion__c' => null, 'IN_Fecha_Creacion_Origen__c' => null,
                            'OwnerId' => null, 'Owner' => ['Name' => 'Synthetic owner']]];

                        return;
                    }
                    yield [];
                }
            };
            $this->app->instance(SalesforceClient::class, $client);
            $command = 'salesforce:sync-interest-monthly-current';
            $options = ['--allow-salesforce-read' => true];
            $this->assertSame(1, Artisan::call($command, $options));
            $first = DB::table('salesforce_interest_monthly_recoveries')->first();
            Carbon::setTestNow(CarbonImmutable::parse('2026-11-11T12:32:00Z'));
            $this->assertSame(1, Artisan::call($command, $options));
            $second = DB::table('salesforce_interest_monthly_recoveries')->orderByDesc('revision')->first();
            $this->assertSame('failed', $second->status);
            $this->assertSame('f2_extract', $second->phase);
            $this->assertSame(0, $second->pages_completed);
            $this->assertStringNotContainsString('Synthetic sensitive', Artisan::output());
            $this->assertSame(0, Artisan::call($command, $options));
            $this->assertDatabaseCount('salesforce_interest_monthly_recoveries', 2);
            $this->assertSame('completed', DB::table('salesforce_interest_monthly_recoveries')->find($second->id)->status);
            $this->assertSame($first->evidence, DB::table('salesforce_interest_monthly_recoveries')->find($first->id)->evidence);
        } finally {
            Carbon::setTestNow();
        }
    }
}
