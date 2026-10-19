<?php

namespace Tests\Feature;

use App\Console\Commands\RunOperationalTaskCommand;
use App\Models\OperationalTaskRun;
use App\Services\Operations\OperationalAlertService;
use App\Services\Operations\OperationalTaskHealthService;
use App\Services\Operations\OperationalTaskService;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

class OperationalMonitorSchedulerTest extends TestCase
{
    use RefreshDatabase;

    public static function incidentCounts(): array
    {
        return [[0], [1], [4]];
    }

    #[DataProvider('incidentCounts')]
    public function test_laravel_scheduler_accepts_completed_assessments_without_losing_their_outcome(int $incidents): void
    {
        $this->mock(OperationalTaskHealthService::class, function ($mock) use ($incidents): void {
            $mock->shouldReceive('check')->withNoArgs()->once()->andReturn($incidents);
            $mock->shouldReceive('check')->with('operations-health')->once()->andReturn(0);
        });
        // Run the real scheduler and wrapper in-process, without spawning integrations.
        $schedule = new Schedule('Europe/Madrid');
        $schedule->call(fn () => Artisan::call('operations:run', ['task' => 'operations-health', '--scheduler' => true]) === 0)->everyMinute();
        $this->app->instance(Schedule::class, $schedule);
        Event::fake([ScheduledTaskFailed::class]);
        $this->artisan('schedule:run')->assertSuccessful();
        Event::assertNotDispatched(ScheduledTaskFailed::class);
        $run = OperationalTaskRun::query()->sole();
        $this->assertSame($incidents ? 'unhealthy' : 'success', $run->status);
        $this->assertSame($incidents ? 5 : 0, $run->exit_code);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
        $state = DB::table('operational_task_states')->where('task_key', 'operations-health')->first();
        $this->assertNull($state->active_run_id);
        $this->assertSame($run->id, $state->last_completed_id);
        if ($incidents) {
            $this->assertNull($state->last_success_id);
        }
    }

    public function test_real_incidents_persist_across_scheduled_assessments_and_direct_contract_stays_five(): void
    {
        $key = 'seo-sync-ga4-organic';
        app(OperationalAlertService::class)->open('scheduled_task_failure', 'high', 'scheduler', $key, 'Fallo histórico sintético.');
        for ($i = 0; $i < 2; $i++) {
            $this->artisan('operations:run', ['task' => 'operations-health', '--scheduler' => true])->assertExitCode(0);
        }
        $this->artisan('operations:check-health')->assertExitCode(5);
        $this->artisan('operations:run', ['task' => 'operations-health'])->assertExitCode(5);
        $this->assertSame(3, OperationalTaskRun::query()->where('status', 'unhealthy')->where('exit_code', 5)->count());
        $this->assertDatabaseHas('operational_alerts', ['type' => 'scheduled_task_failure', 'technical_identifier' => $key, 'state' => 'open']);
        $this->assertDatabaseMissing('operational_alerts', ['technical_identifier' => 'operations-health', 'state' => 'open']);
        $this->travel(31)->minutes();
        $this->artisan('operations:check-health')->assertExitCode(5);
        $this->assertDatabaseHas('operational_alerts', ['type' => 'operational_monitor_stale', 'state' => 'open']);
    }

    public static function technicalFailures(): array
    {
        return [['exit', 1], ['unexpected', 7], ['exception', null]];
    }

    #[DataProvider('technicalFailures')]
    public function test_technical_failures_still_produce_a_scheduler_failure(string $kind, ?int $exit): void
    {
        if ($kind === 'exception') {
            $this->mock(OperationalTaskHealthService::class, fn ($mock) => $mock->shouldReceive('check')->withNoArgs()->once()->andThrow(new \RuntimeException('secret')));
        } else {
            Artisan::shouldReceive('call')->once()->with('operations:check-health', [], \Mockery::any())->andReturn($exit);
        }
        $command = app(RunOperationalTaskCommand::class);
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $this->assertSame(1, $tester->execute(['task' => 'operations-health', '--scheduler' => true]));
        $this->assertDatabaseHas('operational_task_runs', ['task_key' => 'operations-health', 'status' => 'failed']);
        $this->assertDatabaseHas('operational_alerts', ['type' => 'operational_task_failure', 'state' => 'open']);
        $this->assertStringNotContainsString('secret', OperationalTaskRun::all()->toJson());
    }

    public function test_scheduler_really_reports_a_technical_monitor_failure(): void
    {
        $this->mock(OperationalTaskHealthService::class, fn ($mock) => $mock->shouldReceive('check')->withNoArgs()->once()->andThrow(new \RuntimeException('synthetic')));
        $schedule = new Schedule('Europe/Madrid');
        $schedule->call(fn () => Artisan::call('operations:run', ['task' => 'operations-health', '--scheduler' => true]) === 0)->everyMinute();
        $this->app->instance(Schedule::class, $schedule);
        Event::fake([ScheduledTaskFailed::class]);
        $this->artisan('schedule:run')->assertSuccessful();
        Event::assertDispatched(ScheduledTaskFailed::class);
    }

    public function test_missing_database_cannot_be_normalized(): void
    {
        Schema::drop('operational_task_states');
        $this->artisan('operations:run', ['task' => 'operations-health', '--scheduler' => true])->assertExitCode(1);
    }

    public function test_persisted_unhealthy_is_not_accepted_when_completion_reconciliation_fails(): void
    {
        $this->mock(OperationalTaskHealthService::class, function ($mock): void {
            $mock->shouldReceive('check')->withNoArgs()->once()->andReturn(1);
            $mock->shouldReceive('check')->with('operations-health')->once()->andThrow(new \RuntimeException('private'));
        });
        $this->artisan('operations:run', ['task' => 'operations-health', '--scheduler' => true])->assertExitCode(1);
        $this->assertDatabaseHas('operational_task_runs', ['status' => 'unhealthy', 'exit_code' => 5]);
    }

    public function test_lost_reservation_or_failed_audit_does_not_credit_scheduler_success(): void
    {
        $this->mock(OperationalTaskHealthService::class, function ($mock): void {
            $mock->shouldReceive('check')->with('operations-health')->once()->andReturn(0);
            $mock->shouldReceive('check')->withNoArgs()->once()->andReturnUsing(function (): int {
                DB::table('operational_task_states')->where('task_key', 'operations-health')->update(['active_run_id' => null]);

                return 1;
            });
        });
        $this->artisan('operations:run', ['task' => 'operations-health', '--scheduler' => true])->assertExitCode(4);
        $this->assertDatabaseMissing('operational_task_runs', ['status' => 'unhealthy']);
    }

    public function test_scheduler_mode_does_not_expand_the_catalog_or_override_busy_monitor(): void
    {
        $this->artisan('operations:run', ['task' => 'seo-sync-ga4-organic', '--scheduler' => true])->assertExitCode(2);
        $run = app(OperationalTaskService::class)->claim('operations-health', 'scheduler');
        $this->artisan('operations:run', ['task' => 'operations-health', '--scheduler' => true])->assertExitCode(4);
        $this->assertSame('queued', $run->fresh()->status);
    }

    public function test_audit_failure_keeps_the_reservation_and_returns_a_technical_failure(): void
    {
        $this->mock(OperationalTaskHealthService::class, function ($mock): void {
            $mock->shouldReceive('check')->withNoArgs()->once()->andReturnUsing(function (): int {
                Schema::drop('operational_task_runs');

                return 1;
            });
        });
        $this->artisan('operations:run', ['task' => 'operations-health', '--scheduler' => true])->assertExitCode(1);
        $this->assertNotNull(DB::table('operational_task_states')->where('task_key', 'operations-health')->value('active_run_id'));
    }

    public static function integrations(): array
    {
        return [['seo-sync-ga4-organic'], ['seo-sync-search-console'], ['salesforce-sync-tasaciones']];
    }

    #[DataProvider('integrations')]
    public function test_historical_failure_requires_real_recovery_and_preserves_history(string $key): void
    {
        $alerts = app(OperationalAlertService::class);
        $alert = $alerts->open('scheduled_task_failure', 'high', 'scheduler', $key, 'Fallo histórico sintético.');
        $tasks = app(OperationalTaskService::class);
        Artisan::shouldReceive('call')->once()->andReturn(1);
        $failed = $tasks->claim($key, 'scheduler');
        $tasks->execute($failed->id);
        $this->assertSame('open', $alert->fresh()->state);
        $skipped = $tasks->claim($key, 'scheduler');
        $tasks->finish($skipped, false, null, null, true);
        $this->assertSame('skipped', $skipped->fresh()->status);
        $this->assertSame('open', $alert->fresh()->state);
        Artisan::shouldReceive('call')->once()->andReturn(0);
        $success = $tasks->claim($key, 'scheduler');
        $tasks->execute($success->id);
        $this->assertSame('resolved', $alert->fresh()->state);
        $this->assertNotNull($alert->fresh()->resolved_at);
        $this->assertDatabaseHas('operational_alerts', ['type' => 'operational_task_failure', 'technical_identifier' => $key, 'state' => 'resolved']);
        $this->assertSame('failed', $failed->fresh()->status);
        $this->assertSame('success', $success->fresh()->status);
        $this->assertSame($alert->id, $alerts->open('scheduled_task_failure', 'high', 'scheduler', $key, 'Reaparición sintética.')->id);
        $this->assertSame('open', $alert->fresh()->state);
    }
}
