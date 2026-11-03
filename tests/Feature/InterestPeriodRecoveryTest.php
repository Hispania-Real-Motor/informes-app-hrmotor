<?php

namespace Tests\Feature;

use App\Models\ReportSyncRun;
use App\Models\SalesforceInterestActivityRun;
use App\Services\Reports\ReportSyncRunService;
use App\Services\Salesforce\InterestGenerationStore;
use App\Services\Salesforce\InterestPeriodRecoveryService;
use App\Services\Salesforce\SalesforceClient;
use App\Services\Salesforce\SalesforceInterestSyncService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InterestPeriodRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_period_recovery_reuses_seed_versions_persists_progress_and_never_publishes(): void
    {
        $record = $this->record('a01000000000000001', '2026-09-20T08:00:00Z');
        $source = $this->source([$record]);
        $version = app(InterestGenerationStore::class)->rows($source)->value('version.id');
        $this->app->instance(SalesforceClient::class, new class($record) extends SalesforceClient
        {
            public function __construct(private readonly array $record) {}

            public function queryPages(string $soql, bool $includeDeleted = false): \Generator
            {
                yield [$this->record];
            }

            public function getDeleted(string $object, CarbonInterface $start, CarbonInterface $end): array
            {
                return ['earliestDateAvailable' => $start->subDay()->toIso8601String(), 'latestDateCovered' => $end->addDay()->toIso8601String(), 'deletedRecords' => []];
            }
        });

        $service = app(InterestPeriodRecoveryService::class);
        $period = $service->prepare($source, CarbonImmutable::parse('2026-09-01', 'Europe/Madrid'), CarbonImmutable::parse('2026-10-01', 'Europe/Madrid'), 'Inspect September recovery coverage safely.');
        $status = $service->run($period);

        $this->assertSame('reconciled_unverified', $status['status']);
        $this->assertSame('coverage_blocked', $status['phase']);
        $this->assertSame(1, $status['pages_completed']);
        $this->assertSame(1, $status['counters']['identical']);
        $this->assertSame(0, $status['counters']['discrepancies']);
        $this->assertFalse($status['diagnostics']['certifiable']);
        $this->assertSame($version, app(InterestGenerationStore::class)->rows($source)->value('version.id'));
        $this->assertNull(app(InterestGenerationStore::class)->published());
    }

    public function test_period_recovery_records_new_and_seed_only_differences_without_writing_versions(): void
    {
        $sourceRecord = $this->record('a01000000000000001', '2026-09-20T08:00:00Z');
        $remoteRecord = $this->record('a01000000000000002', '2026-09-21T08:00:00Z');
        $source = $this->source([$sourceRecord]);
        $versions = app(InterestGenerationStore::class)->rows($source)->count();
        $this->app->instance(SalesforceClient::class, new class($remoteRecord) extends SalesforceClient
        {
            public function __construct(private readonly array $record) {}

            public function queryPages(string $soql, bool $includeDeleted = false): \Generator
            {
                yield [$this->record];
            }

            public function getDeleted(string $object, CarbonInterface $start, CarbonInterface $end): array
            {
                return ['earliestDateAvailable' => $start->subDay()->toIso8601String(), 'latestDateCovered' => $end->addDay()->toIso8601String(), 'deletedRecords' => []];
            }
        });

        $service = app(InterestPeriodRecoveryService::class);
        $period = $service->prepare($source, CarbonImmutable::parse('2026-09-01', 'Europe/Madrid'), CarbonImmutable::parse('2026-10-01', 'Europe/Madrid'), 'Record incomplete September coverage safely.');
        $status = $service->run($period);

        $this->assertSame(1, $status['counters']['new']);
        $this->assertSame(1, $status['counters']['candidate_only']);
        $this->assertSame(2, $status['counters']['discrepancies']);
        $this->assertSame(1, app(InterestGenerationStore::class)->rows($source)->count());
        $this->assertSame($versions, app(InterestGenerationStore::class)->rows($source)->count());
        $this->assertDatabaseHas('salesforce_interest_recovery_period_differences', ['recovery_period_id' => $period, 'kind' => 'new']);
        $this->assertDatabaseHas('salesforce_interest_recovery_period_differences', ['recovery_period_id' => $period, 'kind' => 'candidate_only']);
    }

    public function test_period_recovery_marks_a_logged_deletion_without_counting_it_as_an_unexplained_absence(): void
    {
        $record = $this->record('a01000000000000001', '2026-09-20T08:00:00Z');
        $source = $this->source([$record]);
        $this->app->instance(SalesforceClient::class, new class($record['Id']) extends SalesforceClient
        {
            public function __construct(private readonly string $id) {}

            public function queryPages(string $soql, bool $includeDeleted = false): \Generator
            {
                yield [];
            }

            public function getDeleted(string $object, CarbonInterface $start, CarbonInterface $end): array
            {
                return [
                    'earliestDateAvailable' => $start->subDay()->toIso8601String(),
                    'latestDateCovered' => $end->addDay()->toIso8601String(),
                    'deletedRecords' => [['id' => $this->id]],
                ];
            }
        });

        $service = app(InterestPeriodRecoveryService::class);
        $period = $service->prepare($source, CarbonImmutable::parse('2026-09-01', 'Europe/Madrid'), CarbonImmutable::parse('2026-10-01', 'Europe/Madrid'), 'Verify a logged September deletion safely.');
        $status = $service->run($period);

        $this->assertSame(1, $status['counters']['deleted']);
        $this->assertSame(0, $status['counters']['candidate_only']);
        $this->assertSame('covered', $status['diagnostics']['deletion_coverage']);
        $this->assertDatabaseHas('salesforce_interest_recovery_period_differences', ['recovery_period_id' => $period, 'kind' => 'deleted_verified']);
    }

    public function test_period_recovery_detects_an_interest_entering_from_an_older_functional_period(): void
    {
        $record = $this->record('a01000000000000001', '2026-08-20T08:00:00Z');
        $source = $this->source([$record]);
        $remote = $this->record($record['Id'], '2026-09-20T08:00:00Z');
        $this->app->instance(SalesforceClient::class, new class($remote) extends SalesforceClient
        {
            public function __construct(private readonly array $record) {}

            public function queryPages(string $soql, bool $includeDeleted = false): \Generator
            {
                yield [$this->record];
            }

            public function getDeleted(string $object, CarbonInterface $start, CarbonInterface $end): array
            {
                return ['earliestDateAvailable' => $start->subDay()->toIso8601String(), 'latestDateCovered' => $end->addDay()->toIso8601String(), 'deletedRecords' => []];
            }
        });

        $service = app(InterestPeriodRecoveryService::class);
        $period = $service->prepare($source, CarbonImmutable::parse('2026-09-01', 'Europe/Madrid'), CarbonImmutable::parse('2026-10-01', 'Europe/Madrid'), 'Detect an Interest entering September safely.');
        $status = $service->run($period);

        $this->assertSame(1, $status['counters']['modified']);
        $this->assertDatabaseHas('salesforce_interest_recovery_period_differences', ['recovery_period_id' => $period, 'kind' => 'entered_period']);
    }

    /** @param list<array<string, mixed>> $records */
    private function source(array $records): int
    {
        $store = app(InterestGenerationStore::class);
        $cutoff = CarbonImmutable::parse('2026-10-28 15:57:00', 'UTC');
        $id = $store->begin($cutoff, true);
        $run = app(ReportSyncRunService::class)->start('salesforce_interests', 'salesforce', $cutoff, $cutoff, 'UTC');
        $store->bindRun($id, $run);
        $rows = array_map(fn (array $record): array => app(SalesforceInterestSyncService::class)->mapRecord($record, false), $records);
        $store->persist($id, $rows);
        $store->finishF2($id);
        app(ReportSyncRunService::class)->complete(ReportSyncRun::findOrFail($run->id), $cutoff);
        $f5 = SalesforceInterestActivityRun::create([
            'run_identifier' => (string) Str::uuid(), 'reason' => 'Synthetic period recovery seed', 'status' => 'completed',
            'source_interest_sync_run_id' => $run->id, 'source_interest_cutoff_at' => $cutoff, 'source_cutoff_at' => $cutoff,
            'started_at' => now(), 'completed_at' => now(),
        ]);
        $store->finishF5($id, $f5);

        return $id;
    }

    /** @return array<string, mixed> */
    private function record(string $id, string $created): array
    {
        return ['Id' => $id, 'CreatedDate' => $created, 'LastModifiedDate' => $created, 'SystemModstamp' => $created,
            'IsDeleted' => false, 'IN_Lead__c' => null, 'IN_Account__c' => null, 'IN_Lead_Origen_Migracion__c' => null,
            'IN_Fecha_Creacion_Origen__c' => null, 'OwnerId' => null, 'Owner' => ['Name' => 'Synthetic owner']];
    }
}
