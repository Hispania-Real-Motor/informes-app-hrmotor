<?php

namespace Tests\Feature;

use App\Services\Reports\Leads\October2026ProvisionalInterestDashboardDatasetService;
use App\Services\Reports\Leads\OperationalInterestMonthlyReader;
use App\Services\Salesforce\OperationalInterestActivitySync;
use App\Services\Salesforce\OperationalInterestMonthlySync;
use App\Services\Salesforce\SalesforceClient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationalInterestActivitySyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));
        Carbon::setTestNow('2026-11-12 12:00:00 UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_selective_task_event_evidence_publishes_only_for_the_current_interest_version(): void
    {
        $fake = new OperationalActivityFakeClient($this->interests(501), [
            $this->activity('Task', 1, 1, '2026-12-03'),
            $this->activity('Task', 2, 3, '2026-11-08', true),
        ], [$this->activity('Event', 3, 501, '2026-11-10T10:00:00Z')]);
        $fake->changeTaskStampOnVerify = true;
        $this->app->instance(SalesforceClient::class, $fake);
        $source = app(OperationalInterestMonthlySync::class);
        $sourceRun = $source->prepare('2026-11');
        $source->run($sourceRun);
        $activity = app(OperationalInterestActivitySync::class);
        $run = $activity->prepare('2026-11');
        $result = $activity->run($run);

        $this->assertSame('completed', $result['status']);
        $this->assertSame(3, $result['extracted']);
        $this->assertSame(3, $result['verified']);
        $this->assertSame(1, $result['changed']);
        $this->assertTrue($result['active']);
        $this->assertSame(2, DB::table('salesforce_interest_operational_activity_summaries')->where('run_id', $run)->count());
        $this->assertStringContainsString('WhatId IN', implode(' ', $fake->activitySoql));
        $this->assertStringNotContainsString('ActivityDate >=', implode(' ', $fake->activitySoql));
        $this->assertStringNotContainsString('CreatedDate >=', implode(' ', $fake->activitySoql));
        $snapshot = app(OperationalInterestMonthlyReader::class)->latestForMonth(2026, 11);
        $this->assertSame($sourceRun, $snapshot['integrity_revision']);
        $this->assertSame($run, $snapshot['activity_run_id']);
        $summary = app(October2026ProvisionalInterestDashboardDatasetService::class)
            ->summary(Request::create('/informes/leads/data/resumen', 'GET', ['period' => 'november_2026']));
        $this->assertSame(501, $summary['kpis']['leads_totales']);
        $this->assertSame(2, $summary['kpis']['activities']);
        $this->assertSame(2, $summary['kpis']['interests_with_activity']);
        $this->assertNull($summary['kpis']['gestionados']);
        $this->assertSame(0, $summary['kpis']['llamadas']);
        $this->assertSame(1, app(October2026ProvisionalInterestDashboardDatasetService::class)
            ->summary(Request::create('/informes/leads/data/resumen', 'GET', [
                'period' => 'november_2026', 'lead_delegation' => 'Madrid General',
            ]))['kpis']['activities']);
        $this->assertSame(0, DB::table('salesforce_interest_generations')->count());
    }

    public function test_failed_query_resumes_without_advancing_the_checkpoint(): void
    {
        $fake = new OperationalActivityFakeClient($this->interests(2), [$this->activity('Task', 1, 1, '2026-11-07')], []);
        $this->app->instance(SalesforceClient::class, $fake);
        $source = app(OperationalInterestMonthlySync::class);
        $source->run($source->prepare('2026-11'));
        $activity = app(OperationalInterestActivitySync::class);
        $run = $activity->prepare('2026-11');
        $fake->failActivityQuery = 1;
        try {
            $activity->run($run);
            $this->fail('The synthetic failure should stop the activity run.');
        } catch (\RuntimeException) {
            $this->assertSame('failed', $activity->status($run)['status']);
            $this->assertSame(0, $activity->status($run)['extracted']);
        }
        $fake->failActivityQuery = null;
        $this->assertSame('completed', $activity->run($run)['status']);
        $this->assertSame(1, DB::table('salesforce_interest_operational_activity_rows')->where('run_id', $run)->count());
    }

    public function test_failure_inside_a_full_activity_block_resumes_without_duplicates(): void
    {
        $tasks = [];
        for ($n = 1; $n <= 501; $n++) {
            $tasks[] = $this->activity('Task', $n, 1, '2026-11-07');
        }
        $fake = new OperationalActivityFakeClient($this->interests(2), $tasks, []);
        $fake->failActivityQuery = 2;
        $this->app->instance(SalesforceClient::class, $fake);
        $source = app(OperationalInterestMonthlySync::class);
        $source->run($source->prepare('2026-11'));
        $activity = app(OperationalInterestActivitySync::class);
        $run = $activity->prepare('2026-11');

        try {
            $activity->run($run);
            $this->fail('The second Task page should fail.');
        } catch (\RuntimeException) {
            $this->assertSame(500, $activity->status($run)['extracted']);
            $this->assertSame(1, $activity->status($run)['pages']);
        }
        $fake->failActivityQuery = null;
        $this->assertSame('completed', $activity->run($run)['status']);
        $this->assertSame(501, $activity->status($run)['extracted']);
        $this->assertSame(501, $activity->status($run)['verified']);
        $this->assertSame(501, DB::table('salesforce_interest_operational_activity_rows')->where('run_id', $run)->count());
        $this->assertSame(1, DB::table('salesforce_interest_operational_activity_summaries')->where('run_id', $run)->count());
    }

    public function test_missing_activity_in_second_read_preserves_interest_version_without_publishing_activity(): void
    {
        $fake = new OperationalActivityFakeClient($this->interests(2), [$this->activity('Task', 1, 1, '2026-11-07')], []);
        $fake->omitTaskOnVerify = true;
        $this->app->instance(SalesforceClient::class, $fake);
        $source = app(OperationalInterestMonthlySync::class);
        $sourceRun = $source->prepare('2026-11');
        $source->run($sourceRun);
        $activity = app(OperationalInterestActivitySync::class);
        $run = $activity->prepare('2026-11');
        try {
            $activity->run($run);
            $this->fail('Incomplete Task coverage must not activate.');
        } catch (\RuntimeException) {
            $this->assertSame('failed', $activity->status($run)['status']);
        }
        $this->assertNull(DB::table('salesforce_interest_operational_months')->where('month_key', '2026-11')
            ->value('active_activity_run_id'));
        $this->assertSame($sourceRun, app(OperationalInterestMonthlyReader::class)
            ->latestForMonth(2026, 11)['integrity_revision']);
    }

    public function test_newer_interest_version_rejects_prepared_activity_without_remote_reads(): void
    {
        $fake = new OperationalActivityFakeClient($this->interests(2), [$this->activity('Task', 1, 1, '2026-11-07')], []);
        $this->app->instance(SalesforceClient::class, $fake);
        $source = app(OperationalInterestMonthlySync::class);
        $source->run($source->prepare('2026-11'));
        $activity = app(OperationalInterestActivitySync::class);
        $run = $activity->prepare('2026-11');
        Carbon::setTestNow('2026-11-12 12:02:00 UTC');
        $newer = $source->prepare('2026-11');
        $source->run($newer);

        try {
            $activity->run($run);
            $this->fail('The pinned Interest version changed.');
        } catch (\RuntimeException) {
            $this->assertSame([], $fake->activitySoql);
            $this->assertSame($newer, app(OperationalInterestMonthlyReader::class)
                ->latestForMonth(2026, 11)['integrity_revision']);
            $this->assertNull(DB::table('salesforce_interest_operational_months')->where('month_key', '2026-11')
                ->value('active_activity_run_id'));
        }
    }

    public function test_unexpected_relation_cannot_enter_the_activity_journal(): void
    {
        $fake = new OperationalActivityFakeClient($this->interests(2), [$this->activity('Task', 1, 1, '2026-11-07')], []);
        $fake->unexpectedRelation = true;
        $this->app->instance(SalesforceClient::class, $fake);
        $source = app(OperationalInterestMonthlySync::class);
        $source->run($source->prepare('2026-11'));
        $activity = app(OperationalInterestActivitySync::class);
        $run = $activity->prepare('2026-11');
        try {
            $activity->run($run);
            $this->fail('An unexpected WhatId must be rejected.');
        } catch (\RuntimeException) {
            $this->assertSame(0, DB::table('salesforce_interest_operational_activity_rows')->where('run_id', $run)->count());
            $this->assertNull(DB::table('salesforce_interest_operational_months')->where('month_key', '2026-11')
                ->value('active_activity_run_id'));
        }
    }

    public function test_lost_lease_blocks_rows_checkpoints_and_activation(): void
    {
        $fake = new OperationalActivityFakeClient($this->interests(2), [$this->activity('Task', 1, 1, '2026-11-07')], []);
        $this->app->instance(SalesforceClient::class, $fake);
        $source = app(OperationalInterestMonthlySync::class);
        $source->run($source->prepare('2026-11'));
        $activity = app(OperationalInterestActivitySync::class);
        $run = $activity->prepare('2026-11');
        $fake->onActivityQuery = function () use ($run): void {
            DB::table('salesforce_interest_operational_activity_runs')->where('id', $run)
                ->update(['lease_token' => '00000000-0000-4000-8000-000000000001']);
        };

        try {
            $activity->run($run);
            $this->fail('A worker with a replaced lease cannot persist activity.');
        } catch (\RuntimeException) {
            $this->assertSame(0, DB::table('salesforce_interest_operational_activity_rows')->where('run_id', $run)->count());
            $this->assertSame(0, $activity->status($run)['pages']);
            $this->assertNull(DB::table('salesforce_interest_operational_months')->where('month_key', '2026-11')
                ->value('active_activity_run_id'));
        }
    }

    public function test_preparing_another_run_does_not_create_a_second_active_worker(): void
    {
        $fake = new OperationalActivityFakeClient($this->interests(2), [], []);
        $this->app->instance(SalesforceClient::class, $fake);
        $source = app(OperationalInterestMonthlySync::class);
        $source->run($source->prepare('2026-11'));
        $activity = app(OperationalInterestActivitySync::class);
        $run = $activity->prepare('2026-11');

        try {
            $activity->prepare('2026-11');
            $this->fail('A prepared run must be resumed before another is created.');
        } catch (\RuntimeException) {
            $this->assertSame(1, DB::table('salesforce_interest_operational_activity_runs')->count());
            $this->assertSame('prepared', $activity->status($run)['status']);
            $this->assertSame([], $fake->activitySoql);
        }
    }

    /** @return list<array<string,mixed>> */
    private function interests(int $count): array
    {
        $rows = [];
        for ($n = 1; $n <= $count; $n++) {
            $rows[] = ['Id' => sprintf('a01%015d', $n), 'CreatedDate' => '2026-11-05T10:00:00Z',
                'LastModifiedDate' => '2026-11-05T10:00:00Z', 'SystemModstamp' => '2026-11-05T10:00:00Z',
                'IN_Fecha_Creacion_Origen__c' => null, 'IsDeleted' => false,
                'IN_Estado__c' => 'Potencial', 'IN_Tipo__c' => 'Venta',
                'IN_Delegacion_Procedencia__c' => $count > 2 && $n === $count ? 'Barcelona' : 'Madrid',
                'IN_Fuente_Origen__c' => 'Portal',
                'IN_Canal__c' => 'Formulario'];
        }

        return $rows;
    }

    private function activity(string $kind, int $id, int $interest, string $functional, bool $deleted = false): array
    {
        return ['Id' => sprintf('00T%015d', $id), 'WhatId' => sprintf('a01%015d', $interest),
            'WhoId' => null, 'ActivityDate' => $kind === 'Task' ? $functional : null,
            'StartDateTime' => $kind === 'Event' ? $functional : null, 'CreatedDate' => '2026-11-06T10:00:00Z',
            'LastModifiedDate' => '2026-11-06T10:00:00Z', 'SystemModstamp' => '2026-11-06T10:00:00Z',
            'IsDeleted' => $deleted];
    }
}

final class OperationalActivityFakeClient extends SalesforceClient
{
    public ?int $failActivityQuery = null;

    public bool $omitTaskOnVerify = false;

    public bool $changeTaskStampOnVerify = false;

    public bool $unexpectedRelation = false;

    public array $activitySoql = [];

    public ?\Closure $onActivityQuery = null;

    private int $activityQueries = 0;

    private array $passes = ['Task' => 0, 'Event' => 0];

    public function __construct(private readonly array $interests, private readonly array $tasks, private readonly array $events) {}

    public function queryPages(string $soql, bool $includeDeleted = false): \Generator
    {
        if (! $includeDeleted) {
            throw new \RuntimeException('The activity reader must include observable soft deletes.');
        }
        if (str_contains($soql, 'FROM Interes__c')) {
            $after = preg_match("/Id > '([^']+)'/", $soql, $matches) === 1 ? $matches[1] : null;
            yield array_slice(array_values(array_filter($this->interests,
                static fn (array $row): bool => $after === null || strcmp($row['Id'], $after) > 0)), 0, 500);

            return;
        }
        $kind = str_contains($soql, 'FROM Task') ? 'Task' : 'Event';
        $this->activitySoql[] = $soql;
        $this->activityQueries++;
        if ($this->onActivityQuery !== null) {
            ($this->onActivityQuery)();
        }
        if ($this->failActivityQuery === $this->activityQueries) {
            throw new \RuntimeException('Synthetic private remote error.');
        }
        $after = preg_match("/Id > '([^']+)'/", $soql, $matches) === 1 ? $matches[1] : null;
        if ($after === null) {
            $this->passes[$kind]++;
        }
        preg_match('/WhatId IN \(([^)]+)\)/', $soql, $matches);
        $ids = array_map(static fn (string $id): string => trim($id, " '\""), explode(',', $matches[1] ?? ''));
        $rows = $kind === 'Task' ? $this->tasks : $this->events;
        $rows = array_values(array_filter($rows, static fn (array $row): bool => in_array($row['WhatId'], $ids, true)
            && ($after === null || strcmp($row['Id'], $after) > 0)));
        if ($kind === 'Task' && $this->passes[$kind] >= 2) {
            if ($this->omitTaskOnVerify) {
                $rows = [];
            }
            if ($this->changeTaskStampOnVerify && $rows !== []) {
                $rows[0]['SystemModstamp'] = '2026-11-07T10:00:00Z';
            }
        }
        if ($kind === 'Task' && $this->unexpectedRelation && $rows !== []) {
            $rows[0]['WhatId'] = 'a01000000000009999';
        }
        yield array_slice($rows, 0, 500);
    }
}
