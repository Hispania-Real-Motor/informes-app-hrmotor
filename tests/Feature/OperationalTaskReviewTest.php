<?php

namespace Tests\Feature;

use App\Models\OperationalAlert;
use App\Models\OperationalTaskRun;
use App\Models\ReportUser;
use App\Services\Operations\OperationalAlertService;
use App\Services\Operations\OperationalTaskCatalog;
use App\Services\Operations\OperationalTaskExitCode;
use App\Services\Operations\OperationalTaskHealthService;
use App\Services\Operations\OperationalTaskService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsOperationalSchedule;
use Tests\TestCase;

class OperationalTaskReviewTest extends TestCase
{
    use AssertsOperationalSchedule, RefreshDatabase;

    private const KEY = 'salesforce-sync-commercial-reviews';

    public static function outcomes(): array
    {
        return [
            'completed success' => ['success', 0, true, true, 0, self::KEY],
            'false success without start' => ['success', 0, false, true, 4, self::KEY],
            'false success without finish' => ['success', 0, true, false, 4, self::KEY],
            'false success with error exit' => ['success', 7, true, true, 4, self::KEY],
            'failed' => ['failed', 7, true, true, 1, self::KEY],
            'legitimate omission' => ['skipped', 2, true, true, 3, self::KEY],
            'reservation prevented overlap' => ['skipped', null, false, true, 4, self::KEY],
            'unknown' => ['unknown', null, true, false, 4, self::KEY],
            'queued' => ['queued', null, false, false, 4, self::KEY],
            'running' => ['running', null, true, false, 4, self::KEY],
            'monitor incidents' => ['unhealthy', 5, true, true, 5, 'operations-health'],
            'invalid incidents on business task' => ['unhealthy', 5, true, true, 4, self::KEY],
        ];
    }

    #[DataProvider('outcomes')]
    public function test_exit_matrix_requires_real_evidence(string $status, ?int $exit, bool $started, bool $finished, int $expected, string $key): void
    {
        $run = new OperationalTaskRun([
            'task_key' => $key, 'status' => $status, 'exit_code' => $exit,
            'started_at' => $started ? now() : null, 'finished_at' => $finished ? now() : null,
        ]);
        $this->assertSame($expected, OperationalTaskExitCode::forRun($run)->value);
    }

    public function test_wrapper_preserves_schedules_and_returns_invalid_blocked_and_omitted_codes(): void
    {
        $this->assertOperationalSchedule();
        $this->artisan('operations:run', ['task' => 'migrate:fresh'])->assertExitCode(2);
        $actor = ReportUser::query()->where('role', 'admin')->firstOrFail();
        $manual = app(OperationalTaskService::class)->claim(self::KEY, 'manual', $actor->id);
        $this->artisan('operations:run', ['task' => self::KEY])->assertExitCode(4);
        $this->assertSame('queued', $manual->fresh()->status);
        $this->assertDatabaseHas('operational_task_runs', ['task_key' => self::KEY, 'status' => 'skipped', 'started_at' => null]);

        $lock = Cache::lock('stock-analysis-daily-sync', 7200);
        $this->assertTrue($lock->get());
        try {
            $this->artisan('operations:run', ['task' => 'stock-sync-daily'])->assertExitCode(3);
        } finally {
            $lock->release();
        }
        $this->assertDatabaseMissing('operational_alerts', ['type' => 'operational_task_failure', 'technical_identifier' => 'stock-sync-daily']);
        $this->assertNull(DB::table('operational_task_states')->where('task_key', 'stock-sync-daily')->value('last_success_id'));
    }

    public function test_monitor_cli_distinguishes_findings_and_sanitized_technical_failure(): void
    {
        $this->mock(OperationalTaskHealthService::class, function ($mock): void {
            $mock->shouldReceive('check')->withNoArgs()->once()->andReturn(4);
        });
        $this->artisan('operations:check-health')->assertExitCode(5);
        $this->mock(OperationalTaskHealthService::class, function ($mock): void {
            $mock->shouldReceive('check')->withNoArgs()->once()->andThrow(new \RuntimeException('Authorization: secret person@example.test'));
        });
        $this->artisan('operations:check-health')->expectsOutput('No se pudo completar la comprobación operacional.')->assertExitCode(1);
        $this->assertDatabaseCount('operational_task_runs', 0);
        $this->assertDatabaseCount('operational_alerts', 0);
    }

    public function test_first_monitor_run_does_not_invent_historical_successes(): void
    {
        $this->artisan('operations:run', ['task' => 'operations-health'])->assertExitCode(0);
        $this->assertDatabaseCount('operational_task_runs', 1);
        $this->assertDatabaseCount('operational_alerts', 0);
        $snapshot = app(OperationalTaskHealthService::class)->snapshot();
        $this->assertSame('never', $snapshot[self::KEY]['status']);
        $this->assertTrue($snapshot[self::KEY]['awaitingFirstSchedule']);
        $this->assertNull($snapshot[self::KEY]['success']);
        $this->assertSame('success', $snapshot['operations-health']['status']);
        $this->assertSame(18, collect(app(OperationalTaskCatalog::class)->all())->where('manual_allowed', true)->count());
    }

    public static function cadences(): array
    {
        return [
            'daily after missed install window' => [self::KEY, '2026-10-15 02:21:00', '2026-10-16 04:20:00'],
            'quarter hourly' => ['salesforce-sync-monthly-commercial', '2026-10-15 10:01:00', '2026-10-15 10:25:00'],
            'hourly' => ['salesforce-sync-interest-reporting', '2026-10-15 10:06:00', '2026-10-15 11:35:00'],
        ];
    }

    #[DataProvider('cadences')]
    public function test_initial_observation_expires_at_first_future_occurrence_plus_margin(string $key, string $activation, string $deadline): void
    {
        $start = CarbonImmutable::parse($activation, 'Europe/Madrid');
        $end = CarbonImmutable::parse($deadline, 'Europe/Madrid');
        DB::table('operational_task_states')->where('task_key', $key)->update(['monitoring_started_at' => $start->utc()]);
        $this->travelTo($end->subSecond());
        $health = app(OperationalTaskHealthService::class);
        $row = $health->snapshot()[$key];
        $this->assertSame('never', $row['status']);
        $this->assertFalse($row['stale']);
        $this->assertNull($row['due']);
        $this->assertSame(0, $health->check($key));
        $this->assertSame($start->format('Y-m-d H:i:s'), CarbonImmutable::parse(DB::table('operational_task_states')->where('task_key', $key)->value('monitoring_started_at'), 'UTC')->timezone('Europe/Madrid')->format('Y-m-d H:i:s'));
        $this->travelTo($end);
        $this->assertTrue($health->snapshot()[$key]['stale']);
        $this->assertSame(1, $health->check($key));
        $this->assertDatabaseHas('operational_alerts', ['type' => 'operational_task_stale', 'technical_identifier' => $key, 'state' => 'open']);
    }

    public function test_failed_first_attempt_is_an_incident_even_before_initial_deadline(): void
    {
        $run = app(OperationalTaskService::class)->claim(self::KEY, 'scheduler');
        app(OperationalTaskService::class)->finish($run, false, 7, 'Fallo sintético.');
        $this->assertSame(1, app(OperationalTaskHealthService::class)->check(self::KEY));
        $this->assertDatabaseHas('operational_alerts', ['type' => 'operational_task_failure', 'technical_identifier' => self::KEY]);
    }

    public function test_monitor_with_many_stale_tasks_is_not_a_recursive_technical_failure(): void
    {
        DB::table('operational_task_states')->update(['monitoring_started_at' => now()->subDays(2)]);
        for ($i = 0; $i < 3; $i++) {
            $this->artisan('operations:run', ['task' => 'operations-health'])->assertExitCode(5);
        }
        $this->assertSame(3, OperationalTaskRun::query()->where('status', 'unhealthy')->count());
        $this->assertSame(22, OperationalAlert::query()->where('state', 'open')->count());
        $this->assertDatabaseMissing('operational_alerts', ['technical_identifier' => 'operations-health', 'state' => 'open']);
        $this->assertSame([3], OperationalAlert::query()->pluck('occurrences')->unique()->values()->all());
        $state = DB::table('operational_task_states')->where('task_key', 'operations-health')->first();
        $this->assertNull($state->last_success_id);
        $this->assertNull($state->last_failure_id);
        $this->assertNotNull($state->last_completed_id);
        $this->assertNull($state->active_run_id);
        $this->assertSame('unhealthy', app(OperationalTaskHealthService::class)->snapshot()['operations-health']['status']);
        $this->get('/informes/alertas-operativas')->assertOk()->assertSee('Incidencias de salud');
    }

    public function test_external_checker_detects_a_totally_stopped_scheduler(): void
    {
        DB::table('operational_task_states')->update(['monitoring_started_at' => now()->subDays(2)]);
        $this->artisan('operations:check-health')->assertExitCode(5);
        $this->assertDatabaseHas('operational_alerts', ['type' => 'operational_monitor_stale', 'technical_identifier' => 'operations-health']);
        $this->assertDatabaseCount('operational_task_runs', 0);
    }

    public function test_technical_monitor_failure_recovers_to_unhealthy_without_false_success(): void
    {
        $this->mock(OperationalTaskHealthService::class, function ($mock): void {
            $mock->shouldReceive('check')->withNoArgs()->once()->andThrow(new \RuntimeException('secret'));
        });
        $this->artisan('operations:run', ['task' => 'operations-health'])->assertExitCode(1);
        $this->assertDatabaseHas('operational_alerts', ['type' => 'operational_task_failure', 'technical_identifier' => 'operations-health', 'state' => 'open']);
        $this->mock(OperationalTaskHealthService::class, function ($mock): void {
            $mock->shouldReceive('check')->withNoArgs()->once()->andReturn(3);
            $mock->shouldReceive('check')->with('operations-health')->once()->andReturn(0);
        });
        $this->artisan('operations:run', ['task' => 'operations-health'])->assertExitCode(5);
        $this->assertDatabaseHas('operational_alerts', ['type' => 'operational_task_failure', 'technical_identifier' => 'operations-health', 'state' => 'resolved']);
        $this->assertNull(DB::table('operational_task_states')->where('task_key', 'operations-health')->value('last_success_id'));
        $this->assertStringNotContainsString('secret', OperationalTaskRun::all()->toJson().OperationalAlert::all()->toJson());
    }

    public function test_release_requires_confirmation_closed_reason_and_an_orphan_candidate(): void
    {
        $tasks = app(OperationalTaskService::class);
        $run = $tasks->claim(self::KEY, 'scheduler');
        $run->update(['status' => 'running', 'started_at' => now()]);
        $this->artisan('operations:release', ['run' => $run->id, '--reason' => 'scheduler_stopped'])->assertExitCode(2);
        $this->artisan('operations:release', ['run' => $run->id, '--reason' => 'secret email@example.test', '--confirm-stopped' => true])->assertExitCode(2);
        $this->artisan('operations:release', ['run' => $run->id, '--reason' => 'scheduler_stopped', '--reference' => 'token=secret', '--confirm-stopped' => true])->assertExitCode(2);
        $this->artisan('operations:release', ['run' => $run->id, '--reason' => 'scheduler_stopped', '--confirm-stopped' => true])->assertExitCode(4);
        $this->assertDatabaseCount('operational_task_releases', 0);
        $this->assertSame('running', $run->fresh()->status);
        $this->assertSame($run->id, DB::table('operational_task_states')->where('task_key', self::KEY)->value('active_run_id'));
    }

    public function test_release_audits_verified_os_principal_or_explicitly_unknown_actor_atomically(): void
    {
        $tasks = app(OperationalTaskService::class);
        $actor = ReportUser::query()->where('role', 'admin')->firstOrFail();
        $run = $tasks->claim(self::KEY, 'manual', $actor->id);
        $run->update(['status' => 'running', 'started_at' => now()->subHours(3)]);
        $tasks->jobFailed($run->id);
        $releasedAt = now();
        $this->artisan('operations:release', ['run' => $run->id, '--reason' => 'worker_stopped', '--reference' => 'OPS-123', '--confirm-stopped' => true])->assertExitCode(0);
        $audit = DB::table('operational_task_releases')->sole();
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        $this->assertSame($uid === null ? 'unverified_cli' : 'os_uid', $audit->actor_type);
        $this->assertSame($uid, $audit->actor_identifier);
        $this->assertSame('worker_stopped', $audit->reason);
        $this->assertSame('OPS-123', $audit->reference);
        $this->assertSame($run->correlation_id, $audit->run_correlation_id);
        $this->assertSame($releasedAt->format('Y-m-d H:i:s'), $audit->released_at);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame($actor->id, $run->fresh()->report_user_id);
        $this->assertNull(DB::table('operational_task_states')->where('task_key', self::KEY)->value('last_success_id'));
        $this->artisan('operations:release', ['run' => $run->id, '--reason' => 'worker_stopped', '--confirm-stopped' => true])->assertExitCode(4);
        $this->assertDatabaseCount('operational_task_releases', 1);
    }

    public function test_expired_queue_release_cannot_revive_a_late_job_or_be_called_from_http(): void
    {
        $tasks = app(OperationalTaskService::class);
        $actor = ReportUser::query()->where('role', 'admin')->firstOrFail();
        $run = $tasks->claim(self::KEY, 'manual', $actor->id);
        $this->travel(16)->minutes();
        $this->artisan('operations:release', ['run' => $run->id, '--reason' => 'queue_expired', '--confirm-stopped' => true])->assertExitCode(0);
        Artisan::shouldReceive('call')->never();
        $tasks->execute($run->id);
        $this->assertSame('failed', $run->fresh()->status);
        $this->postJson('/informes/alertas-operativas/ejecutar', ['task_key' => 'operations:release', 'confirmed' => true])->assertUnprocessable();
        $this->assertNotContains('operations:release', array_column(app(OperationalTaskCatalog::class)->all(), 'command'));
    }

    public function test_release_does_not_remove_reservation_when_audit_cannot_be_persisted(): void
    {
        $tasks = app(OperationalTaskService::class);
        $run = $tasks->claim(self::KEY, 'scheduler');
        $run->update(['status' => 'unknown', 'started_at' => now()->subHours(3)]);
        Schema::drop('operational_task_releases');
        $this->artisan('operations:release', ['run' => $run->id, '--reason' => 'scheduler_stopped', '--confirm-stopped' => true])
            ->expectsOutput('No se pudo registrar la liberación; compruebe el estado antes de reintentar.')
            ->assertExitCode(1);
        $this->assertSame('unknown', $run->fresh()->status);
        $this->assertNull($run->fresh()->finished_at);
        $this->assertSame($run->id, DB::table('operational_task_states')->where('task_key', self::KEY)->value('active_run_id'));
    }

    public function test_recovery_of_one_task_does_not_increment_other_stale_alerts(): void
    {
        DB::table('operational_task_states')->update(['monitoring_started_at' => now()->subDays(2)]);
        $health = app(OperationalTaskHealthService::class);
        $health->check();
        $other = OperationalAlert::query()->where('technical_identifier', 'salesforce-sync-calls')->sole();
        Artisan::shouldReceive('call')->once()->andReturn(0);
        $run = app(OperationalTaskService::class)->claim(self::KEY, 'scheduler');
        app(OperationalTaskService::class)->execute($run->id);
        $this->assertSame(1, $other->fresh()->occurrences);
        $this->assertDatabaseHas('operational_alerts', ['type' => 'operational_task_stale', 'technical_identifier' => self::KEY, 'state' => 'resolved']);
    }

    public function test_initial_margin_never_resolves_a_preexisting_alert_without_real_evidence(): void
    {
        app(OperationalAlertService::class)->open('operational_task_stale', 'high', 'operations', self::KEY, 'Incidencia sintética previa.');
        $health = app(OperationalTaskHealthService::class);
        $this->assertTrue($health->snapshot()[self::KEY]['awaitingFirstSchedule']);
        $this->assertSame(1, $health->check(self::KEY));
        $this->assertDatabaseHas('operational_alerts', ['technical_identifier' => self::KEY, 'state' => 'open']);
        Artisan::shouldReceive('call')->once()->andReturn(0);
        $tasks = app(OperationalTaskService::class);
        $run = $tasks->claim(self::KEY, 'scheduler');
        $tasks->execute($run->id);
        $this->assertSame(0, $health->check(self::KEY));
        $this->assertDatabaseHas('operational_alerts', ['technical_identifier' => self::KEY, 'state' => 'resolved']);
    }

    public function test_real_monitor_assessment_resolves_its_legacy_stale_alert(): void
    {
        app(OperationalAlertService::class)->open('operational_task_stale', 'high', 'operations', 'operations-health', 'Incidencia sintética del contrato previo.');
        $this->artisan('operations:run', ['task' => 'operations-health'])->assertExitCode(0);
        $this->assertDatabaseHas('operational_alerts', ['technical_identifier' => 'operations-health', 'state' => 'resolved']);
    }

    public function test_queued_retry_does_not_hide_an_unresolved_failure_with_a_recent_old_success(): void
    {
        $tasks = app(OperationalTaskService::class);
        Artisan::shouldReceive('call')->twice()->andReturn(0, 7);
        $success = $tasks->claim(self::KEY, 'scheduler');
        $tasks->execute($success->id);
        $failure = $tasks->claim(self::KEY, 'scheduler');
        $tasks->execute($failure->id);
        $retry = $tasks->claim(self::KEY, 'scheduler');
        $this->assertSame('queued', $retry->status);
        $this->assertSame(1, app(OperationalTaskHealthService::class)->check(self::KEY));
        $this->assertDatabaseHas('operational_alerts', ['type' => 'operational_task_failure', 'technical_identifier' => self::KEY, 'state' => 'open']);
    }

    public function test_initial_observation_and_next_occurrence_survive_both_dst_transitions(): void
    {
        foreach (['2026-03-28T03:00:00+01:00', '2026-10-25T02:30:00+02:00'] as $date) {
            $activation = CarbonImmutable::parse($date)->timezone('Europe/Madrid');
            DB::table('operational_task_states')->where('task_key', self::KEY)->update(['monitoring_started_at' => $activation->utc()]);
            $this->travelTo($activation);
            $row = app(OperationalTaskHealthService::class)->snapshot()[self::KEY];
            $this->assertTrue($row['firstExpected']->gt($activation));
            $this->assertFalse($row['stale']);
            $this->assertSame('Europe/Madrid', $row['firstExpected']->timezoneName);
        }
    }
}
