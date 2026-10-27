<?php

namespace Tests\Feature;

use App\Jobs\Operations\ExecuteOperationalTask;
use App\Models\OperationalAlert;
use App\Models\OperationalTaskRun;
use App\Models\ReportUser;
use App\Services\Campaigns\GoogleAdsClient;
use App\Services\Campaigns\GoogleCampaignSyncService;
use App\Services\Campaigns\MetaAdsClient;
use App\Services\Campaigns\MetaCampaignSyncService;
use App\Services\Operations\OperationalAlertService;
use App\Services\Operations\OperationalTaskCatalog;
use App\Services\Operations\OperationalTaskHealthService;
use App\Services\Operations\OperationalTaskOutput;
use App\Services\Operations\OperationalTaskService;
use App\Services\Reports\CommercialCommissions\Sync\SalesforceReviewSyncService;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Tests\Concerns\AssertsOperationalSchedule;
use Tests\TestCase;

class OperationalTasksTest extends TestCase
{
    use AssertsOperationalSchedule, RefreshDatabase;

    private const KEY = 'salesforce-sync-commercial-reviews';

    public function test_catalog_and_registered_scheduler_preserve_every_production_contract(): void
    {
        $this->assertOperationalSchedule();
        $this->artisan('schedule:list', ['--timezone' => 'Europe/Madrid'])->assertSuccessful();
        $tasks = app(OperationalTaskCatalog::class)->all();
        $this->assertCount(18, array_filter($tasks, fn ($t) => $t['manual_allowed']));
        foreach ($tasks as $task) {
            foreach (['--fresh', '--all', '--all-history', '--debug-soql', '--to'] as $forbidden) {
                $this->assertArrayNotHasKey($forbidden, $task['arguments']);
            }
            $this->assertNotNull(Artisan::all()[$task['command']] ?? null);
        }
        foreach (['reports-prune-transversal-data', 'stock-sync-daily', 'seo-send-executive-daily-email', 'operations-health'] as $key) {
            $this->assertFalse($tasks[$key]['manual_allowed']);
        }
    }

    public static function roles(): array
    {
        return array_map(fn ($role) => [$role], ['director', 'direction', 'direccion', 'viewer', 'commission_auditor', 'area_manager', 'area_manager_own_area', 'delegation_manager', 'marketing', 'financial', 'commercial', 'stock_only']);
    }

    #[DataProvider('roles')]
    public function test_non_admin_cannot_read_or_force_execution(string $role): void
    {
        Queue::fake();
        $user = $this->actor($role);
        $this->asReportUser($user);
        $this->get('/informes/alertas-operativas')->assertForbidden();
        $this->post('/informes/alertas-operativas/ejecutar', ['task_key' => self::KEY, 'confirmed' => '1'])->assertForbidden();
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('operational_task_runs', 0);
    }

    public function test_admin_dispatches_immediately_with_actor_and_fixed_arguments(): void
    {
        Queue::fake();
        $this->post('/informes/alertas-operativas/ejecutar', ['task_key' => self::KEY, 'confirmed' => '1'])
            ->assertRedirect(route('reports.operational-alerts.index'))
            ->assertSessionHas('operation_status');
        Queue::assertPushedOn('operations', ExecuteOperationalTask::class);
        $run = OperationalTaskRun::query()->sole();
        $this->assertSame('queued', $run->status);
        $this->assertNotNull($run->report_user_id);
        $this->assertSame(['--months' => '2'], $run->arguments);
        $this->assertNull($run->started_at);
        $this->get('/informes/alertas-operativas')->assertOk()->assertSee('En cola')->assertSee('report-ui-table', false)->assertDontSee('leads-dashboard.css');
    }

    public function test_invalid_key_forbidden_task_and_scope_injection_are_rejected(): void
    {
        Queue::fake();
        $this->postJson('/informes/alertas-operativas/ejecutar', ['task_key' => 'evil', 'confirmed' => true])->assertUnprocessable();
        $this->postJson('/informes/alertas-operativas/ejecutar', ['task_key' => 'reports-prune-transversal-data', 'confirmed' => true])->assertForbidden();
        foreach (['command' => 'migrate:fresh', 'arguments' => ['--all' => true], 'from' => '2020-01-01'] as $field => $value) {
            $this->postJson('/informes/alertas-operativas/ejecutar', ['task_key' => self::KEY, 'confirmed' => true, $field => $value])->assertUnprocessable();
        }
        $this->postJson('/informes/alertas-operativas/ejecutar', ['task_key' => self::KEY])->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    public function test_csrf_is_enforced_with_real_forgery_middleware(): void
    {
        Queue::fake();
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->post('/informes/alertas-operativas/ejecutar', ['task_key' => self::KEY, 'confirmed' => true])->assertStatus(419);
        $this->withSession(['_token' => 'synthetic-csrf'])->post('/informes/alertas-operativas/ejecutar', [
            '_token' => 'synthetic-csrf', 'task_key' => self::KEY, 'confirmed' => true,
        ])->assertRedirect();
        Queue::assertPushed(ExecuteOperationalTask::class);
    }

    public function test_execution_transitions_and_duplicate_delivery_do_not_execute_twice(): void
    {
        $run = $this->tasks()->claim(self::KEY, 'scheduler', 999);
        $this->assertNull($run->report_user_id);
        Artisan::shouldReceive('call')->once()->with('salesforce:sync-commercial-reviews', ['--months' => '2'], \Mockery::type(OutputInterface::class))
            ->andReturnUsing(function () use ($run) {
                $this->assertSame('running', $run->fresh()->status);
                $this->assertNotNull($run->fresh()->started_at);

                return 0;
            });
        $this->tasks()->execute($run->id);
        $this->tasks()->execute($run->id);
        $this->tasks()->jobFailed($run->id);
        $this->assertSame('success', $run->fresh()->status);
        $this->assertSame(0, $run->fresh()->exit_code);
        $this->assertNotNull($run->fresh()->duration_ms);
    }

    public function test_non_zero_exit_creates_deduplicated_failure_then_real_recovery_resolves(): void
    {
        DB::table('operational_task_states')->where('task_key', self::KEY)->update(['monitoring_started_at' => now()->subDays(2)]);
        Artisan::shouldReceive('call')->twice()->andReturn(7, 0);
        $run = $this->tasks()->claim(self::KEY, 'scheduler');
        $this->tasks()->execute($run->id);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame(7, $run->fresh()->exit_code);
        $this->assertDatabaseHas('operational_alerts', ['type' => 'operational_task_failure', 'technical_identifier' => self::KEY, 'state' => 'open']);
        $health = app(OperationalTaskHealthService::class);
        $health->check();
        $health->check();
        $this->assertSame(1, OperationalAlert::query()->where('type', 'operational_task_stale')->where('technical_identifier', self::KEY)->count());
        $next = $this->tasks()->claim(self::KEY, 'scheduler');
        $this->tasks()->execute($next->id);
        $this->assertDatabaseHas('operational_alerts', ['type' => 'operational_task_failure', 'technical_identifier' => self::KEY, 'state' => 'resolved']);
        $this->assertDatabaseHas('operational_alerts', ['type' => 'operational_task_stale', 'technical_identifier' => self::KEY, 'state' => 'resolved']);
        $this->assertSame('success', $health->snapshot()[self::KEY]['status']);
    }

    public function test_exception_content_never_reaches_ledger_or_alerts(): void
    {
        Artisan::shouldReceive('call')->once()->andThrow(new \RuntimeException('Authorization: Bearer secret email=person@example.test Salesforce payload'));
        $run = $this->tasks()->claim(self::KEY, 'scheduler');
        $this->tasks()->execute($run->id);
        $this->assertSame('failed', $run->fresh()->status);
        $data = $run->fresh()->toJson().OperationalAlert::all()->toJson();
        foreach (['secret', 'person@example.test', 'Bearer', 'payload'] as $secret) {
            $this->assertStringNotContainsString($secret, $data);
        }
    }

    public function test_persistent_reservation_coordinates_both_origins_and_manual_double_click(): void
    {
        $actor = $this->actor();
        $manual = $this->tasks()->claim(self::KEY, 'manual', $actor->id);
        $this->assertSame('skipped', $this->tasks()->claim(self::KEY, 'scheduler')->status);
        // Simulate a later concurrent request after cooldown; reservation still holds.
        DB::table('operational_task_states')->where('task_key', self::KEY)->update(['manual_available_at' => null]);
        $this->assertSame('skipped', $this->tasks()->claim(self::KEY, 'manual', $actor->id)->status);
        $this->tasks()->finish($manual, false, null, 'Solicitud cancelada de prueba.');
        $scheduled = $this->tasks()->claim(self::KEY, 'scheduler');
        $this->assertSame('skipped', $this->tasks()->claim(self::KEY, 'manual', $actor->id)->status);
        $this->assertSame($scheduled->id, DB::table('operational_task_states')->where('task_key', self::KEY)->value('active_run_id'));
    }

    public function test_manual_cooldown_rejects_immediate_duplicate(): void
    {
        Queue::fake();
        $payload = ['task_key' => self::KEY, 'confirmed' => true];
        $this->postJson('/informes/alertas-operativas/ejecutar', $payload)->assertRedirect();
        $this->postJson('/informes/alertas-operativas/ejecutar', $payload)->assertStatus(429);
        Queue::assertPushed(ExecuteOperationalTask::class, 1);
    }

    public function test_disabled_or_demoted_actor_is_rechecked_by_worker(): void
    {
        Artisan::shouldReceive('call')->never();
        foreach (['is_active' => false, 'role' => 'director'] as $field => $value) {
            $user = $this->actor();
            $run = $this->tasks()->claim(self::KEY, 'manual', $user->id);
            $user->update([$field => $value]);
            (new ExecuteOperationalTask($run->id))->handle($this->tasks());
            $this->assertSame('failed', $run->fresh()->status);
            DB::table('operational_task_states')->where('task_key', self::KEY)->update(['manual_available_at' => null]);
        }
    }

    public function test_worker_stopped_and_late_delivery_never_execute_expired_request(): void
    {
        Artisan::shouldReceive('call')->never();
        $run = $this->tasks()->claim(self::KEY, 'manual', $this->actor()->id);
        $this->travel(16)->minutes();
        $row = app(OperationalTaskHealthService::class)->snapshot()[self::KEY];
        $this->assertTrue($row['overdue']);
        $this->assertSame('unknown', $row['status']);
        $this->tasks()->execute($run->id);
        $this->assertSame('failed', $run->fresh()->status);
    }

    public function test_worker_failure_before_start_and_unknown_running_fail_closed(): void
    {
        $run = $this->tasks()->claim(self::KEY, 'scheduler');
        (new ExecuteOperationalTask($run->id))->failed(new \Exception('secret'));
        $this->assertSame('failed', $run->fresh()->status);
        $running = $this->tasks()->claim(self::KEY, 'scheduler');
        $running->update(['status' => 'running', 'started_at' => now()->subHours(3)]);
        $this->assertSame('unknown', app(OperationalTaskHealthService::class)->snapshot()[self::KEY]['status']);
        (new ExecuteOperationalTask($running->id))->failed(new \Exception('secret'));
        $this->assertSame('unknown', $running->fresh()->status);
        $this->assertSame('skipped', $this->tasks()->claim(self::KEY, 'scheduler')->status);
        $this->artisan('operations:release', ['run' => $running->id])->assertFailed();
        $this->artisan('operations:release', ['run' => $running->id, '--confirm-stopped' => true, '--reason' => 'scheduler_stopped'])->assertSuccessful();
        $this->assertSame('failed', $running->fresh()->status);
    }

    public function test_cadences_never_executed_stale_and_madrid_dst(): void
    {
        // RefreshDatabase migrates at real time; activation must precede the simulated DST day.
        DB::table('operational_task_states')->update([
            'monitoring_started_at' => CarbonImmutable::parse('2026-10-24 00:00:00', 'Europe/Madrid')->utc(),
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-10-25 06:40:00', 'Europe/Madrid'));
        $health = app(OperationalTaskHealthService::class);
        $snapshot = $health->snapshot();
        $this->assertSame('never', $snapshot[self::KEY]['status']);
        $this->assertTrue($snapshot[self::KEY]['stale']);
        $this->assertSame('Europe/Madrid', $snapshot[self::KEY]['next']->timezoneName);
        $this->assertSame('02:20', $snapshot[self::KEY]['next']->format('H:i'));
        $this->assertSame('06:30', $snapshot['salesforce-sync-monthly-commercial']['due']->format('H:i'));
        $this->assertSame('06:05', $snapshot['salesforce-sync-interest-reporting']['due']->format('H:i'));
        $run = $this->tasks()->claim('salesforce-sync-monthly-commercial', 'scheduler');
        $run->update(['status' => 'running', 'started_at' => now()->subHours(2)]);
        $this->tasks()->finish($run, true, 0);
        $this->assertSame('stale', $health->snapshot()['salesforce-sync-monthly-commercial']['status']);
    }

    public function test_repeated_madrid_hour_keeps_distinct_scheduled_instants(): void
    {
        $task = app(OperationalTaskCatalog::class)->get(self::KEY);
        $cron = new CronExpression($task['cron']);
        // Explicit UTC instants retain both folds of 02:00, unlike a local time-travel string.
        $firstHour = CarbonImmutable::parse('2026-10-25T00:15:00Z')->setTimezone($task['timezone']);
        $secondHour = CarbonImmutable::parse('2026-10-25T01:15:00Z')->setTimezone($task['timezone']);
        $firstNext = CarbonImmutable::instance($cron->getNextRunDate($firstHour->toDateTimeImmutable(), 0, false, $task['timezone']));
        $secondNext = CarbonImmutable::instance($cron->getNextRunDate($secondHour->toDateTimeImmutable(), 0, false, $task['timezone']));
        $firstAfter = CarbonImmutable::parse('2026-10-25T00:30:00Z')->setTimezone($task['timezone']);
        $secondAfter = CarbonImmutable::parse('2026-10-25T01:30:00Z')->setTimezone($task['timezone']);
        $firstDue = CarbonImmutable::instance($cron->getPreviousRunDate($firstAfter->toDateTimeImmutable(), 0, true, $task['timezone']));
        $secondDue = CarbonImmutable::instance($cron->getPreviousRunDate($secondAfter->toDateTimeImmutable(), 0, true, $task['timezone']));

        $this->assertSame('2026-10-25 00:20', $firstNext->utc()->format('Y-m-d H:i'));
        $this->assertSame('2026-10-25 01:20', $secondNext->utc()->format('Y-m-d H:i'));
        $this->assertSame('2026-10-25 00:20', $firstDue->utc()->format('Y-m-d H:i'));
        $this->assertSame('2026-10-25 01:20', $secondDue->utc()->format('Y-m-d H:i'));
        $this->assertSame('Europe/Madrid', $firstNext->timezoneName);
        $this->assertSame('Europe/Madrid', $secondNext->timezoneName);
    }

    public function test_never_executed_task_waits_for_first_schedule_then_becomes_stale(): void
    {
        config()->set('campaign_current_status.enabled', false);
        DB::table('operational_task_states')->where('task_key', self::KEY)->update([
            'monitoring_started_at' => CarbonImmutable::parse('2026-10-25T01:15:00Z'),
        ]);
        $health = app(OperationalTaskHealthService::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-25T01:15:00Z'));
        $pending = $health->snapshot();
        $this->assertArrayNotHasKey('campaigns-sync-current-status', $pending);
        $this->assertSame('never', $pending[self::KEY]['status']);
        $this->assertNull($pending[self::KEY]['due']);
        $this->assertTrue($pending[self::KEY]['awaitingFirstSchedule']);
        $this->assertFalse($pending[self::KEY]['stale']);

        $this->travelTo(CarbonImmutable::parse('2026-10-25T03:30:00Z'));
        $overdue = $health->snapshot()[self::KEY];
        $this->assertSame('never', $overdue['status']);
        $this->assertNotNull($overdue['due']);
        $this->assertTrue($overdue['stale']);
    }

    public function test_panel_reads_are_bounded_and_never_run_commands(): void
    {
        Artisan::shouldReceive('call')->never();
        DB::enableQueryLog();
        $this->get('/informes/alertas-operativas')->assertOk()->assertSee('Nunca ejecutado')->assertSee('Actualizar estado')->assertSee('Confirmo ejecutar');
        $this->assertLessThan(20, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_dispatch_failure_is_audited_without_raw_exception(): void
    {
        Queue::shouldReceive('connection')->with('operations')->andThrow(new \RuntimeException('password=secret'));
        $run = $this->tasks()->requestManual(self::KEY, $this->actor()->id);
        $this->assertSame('failed', $run->status);
        $this->assertStringNotContainsString('secret', $run->toJson());
    }

    public function test_dedicated_queue_stays_asynchronous_even_when_default_is_sync(): void
    {
        config(['queue.default' => 'sync']);
        Artisan::shouldReceive('call')->never();
        $run = $this->tasks()->requestManual(self::KEY, $this->actor()->id);
        $this->assertSame('queued', $run->status);
        $this->assertDatabaseHas('jobs', ['queue' => 'operations', 'attempts' => 0]);
        $this->assertGreaterThan(14400, config('queue.connections.operations.retry_after'));
    }

    public function test_stock_internal_lock_does_not_certify_success(): void
    {
        DB::table('operational_task_states')->where('task_key', 'stock-sync-daily')->update(['monitoring_started_at' => now()->subDays(2)]);
        $lock = Cache::lock('stock-analysis-daily-sync', 7200);
        $this->assertTrue($lock->get());
        $this->artisan('stock:sync-daily')->assertExitCode(2);
        $lock->release();
        Artisan::shouldReceive('call')->once()->andReturn(2);
        $run = $this->tasks()->claim('stock-sync-daily', 'scheduler');
        $this->tasks()->execute($run->id);
        $this->assertSame('skipped', $run->fresh()->status);
        $this->assertNull(DB::table('operational_task_states')->where('task_key', 'stock-sync-daily')->value('last_success_id'));
        $this->assertTrue(app(OperationalTaskHealthService::class)->snapshot()['stock-sync-daily']['stale']);
    }

    public function test_manual_success_and_failed_delivery_keep_original_actor_after_deletion(): void
    {
        $user = $this->actor();
        $run = $this->tasks()->claim(self::KEY, 'manual', $user->id);
        Artisan::shouldReceive('call')->once()->andReturn(0);
        (new ExecuteOperationalTask($run->id))->handle($this->tasks());
        $this->assertSame('success', $run->fresh()->status);
        $user->delete();
        $this->assertSame($user->id, $run->fresh()->report_user_id);
    }

    public function test_history_pruning_preserves_active_unknown_open_and_state_evidence(): void
    {
        $old = now()->subDays(100);
        $create = fn (string $key, string $status) => OperationalTaskRun::query()->create([
            'task_key' => $key, 'origin' => 'scheduler', 'status' => $status,
            'arguments' => [], 'correlation_id' => (string) Str::uuid(),
            'queued_at' => $old, 'started_at' => $old, 'finished_at' => in_array($status, ['running', 'unknown', 'queued'], true) ? null : $old,
        ]);
        $closed = $create('old-closed', 'success');
        $active = $create('old-active', 'running');
        $unknown = $create('old-unknown', 'unknown');
        $open = $create('old-open', 'failed');
        $evidence = $create('old-evidence', 'success');
        DB::table('operational_task_states')->insert(['task_key' => 'old-evidence', 'last_success_id' => $evidence->id]);
        app(OperationalAlertService::class)->open('operational_task_failure', 'high', 'operations', 'old-open', 'Fallo sintético.');
        $this->artisan('reports:prune-transversal-data', ['--dry-run' => true])->assertSuccessful();
        $this->assertNotNull($closed->fresh());
        $this->artisan('reports:prune-transversal-data')->assertSuccessful();
        $this->assertNull($closed->fresh());
        foreach ([$active, $unknown, $open, $evidence] as $protected) {
            $this->assertNotNull($protected->fresh());
        }
    }

    public function test_both_dst_boundaries_keep_local_daily_time(): void
    {
        foreach (['2026-03-29 05:00:00', '2026-10-25 05:00:00'] as $date) {
            $this->travelTo(CarbonImmutable::parse($date, 'Europe/Madrid'));
            $row = app(OperationalTaskHealthService::class)->snapshot()['seo-sync-search-console'];
            $this->assertSame('05:15', $row['next']->format('H:i'));
            $this->assertSame('Europe/Madrid', $row['next']->timezoneName);
        }
    }

    public function test_legacy_seo_skip_is_not_a_successful_heartbeat(): void
    {
        config(['services.google_search_console.client_id' => '', 'services.google_search_console.client_secret' => '', 'services.google_search_console.refresh_token' => '']);
        $run = $this->tasks()->claim('seo-sync-search-console', 'scheduler');
        $this->tasks()->execute($run->id);
        $this->assertSame('skipped', $run->fresh()->status);
        $this->assertNull(DB::table('operational_task_states')->where('task_key', $run->task_key)->value('last_success_id'));
    }

    public function test_ads_partial_or_unconfigured_output_retains_flags_only(): void
    {
        foreach (['campaigns-sync-meta', 'campaigns-sync-google'] as $key) {
            $output = new OperationalTaskOutput($key);
            $output->writeln('No se ha podido actualizar la inversion (account-secret). token=secret');
            $output->writeln('Meta Ads configurado: no');
            $this->assertTrue($output->partialFailure);
            $this->assertTrue($output->skipped);
            $this->assertStringNotContainsString('secret', json_encode($output));
            $closedOutput = new OperationalTaskOutput($key);
            $closedOutput->writeln('Intervalo omitido: no contiene días de inversión abiertos.');
            $this->assertTrue($closedOutput->skipped);
        }
        Artisan::shouldReceive('call')->once()->andReturnUsing(function ($command, $args, $output) {
            $output->writeln('No se ha podido actualizar la inversion de Meta Ads.');

            return 0;
        });
        $run = $this->tasks()->claim('campaigns-sync-meta', 'scheduler');
        $this->tasks()->execute($run->id);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame(0, $run->fresh()->exit_code);

        Artisan::shouldReceive('call')->once()->andReturnUsing(function ($command, $args, $output) {
            $output->writeln('No se ha podido actualizar la inversion de Google Ads.');

            return 0;
        });
        $googleRun = $this->tasks()->claim('campaigns-sync-google', 'scheduler');
        $this->tasks()->execute($googleRun->id);
        $this->assertSame('failed', $googleRun->fresh()->status);
        $this->assertSame(0, $googleRun->fresh()->exit_code);
    }

    public function test_http_conflict_while_scheduler_is_running_never_dispatches(): void
    {
        Queue::fake();
        $run = $this->tasks()->claim(self::KEY, 'scheduler');
        $run->update(['status' => 'running', 'started_at' => now()]);
        $this->postJson('/informes/alertas-operativas/ejecutar', ['task_key' => self::KEY, 'confirmed' => true])->assertStatus(409);
        Queue::assertNothingPushed();
        $this->assertSame(1, OperationalTaskRun::query()->where('status', 'running')->count());
        $this->assertSame(1, OperationalTaskRun::query()->where('status', 'skipped')->count());
    }

    public function test_admin_rate_limit_is_per_actor_and_never_replaces_non_admin_403(): void
    {
        $admin = $this->actor();
        $this->asReportUser($admin);
        RateLimiter::hit('operations:manual:'.$admin->id, 60);
        for ($i = 0; $i < 10; $i++) {
            RateLimiter::hit('operations:manual:'.$admin->id, 60);
        }
        $this->postJson('/informes/alertas-operativas/ejecutar', ['task_key' => self::KEY, 'confirmed' => true])->assertStatus(429);
        $this->asReportUser($this->actor('director'));
        for ($i = 0; $i < 12; $i++) {
            $this->postJson('/informes/alertas-operativas/ejecutar', ['task_key' => self::KEY, 'confirmed' => true])->assertForbidden();
        }
    }

    public function test_tampering_persisted_arguments_does_not_change_effective_command_scope(): void
    {
        $run = $this->tasks()->claim(self::KEY, 'scheduler');
        $run->update(['arguments' => ['--all' => true, '--fresh' => true]]);
        Artisan::shouldReceive('call')->once()->with('salesforce:sync-commercial-reviews', ['--months' => '2'], \Mockery::any())->andReturn(0);
        $this->tasks()->execute($run->id);
        $this->assertSame('success', $run->fresh()->status);
    }

    public function test_ads_provider_errors_are_sanitized_before_logging(): void
    {
        Log::spy();
        $meta = \Mockery::mock(MetaAdsClient::class);
        $meta->shouldReceive('missingConfigurationWarnings')->once()->andReturn([]);
        $meta->shouldReceive('adAccountIds')->once()->andReturn(['synthetic-account-id']);
        $meta->shouldReceive('insights')->once()->andThrow(new \RuntimeException('token=secret person@example.test response body'));
        $this->app->instance(MetaAdsClient::class, $meta);
        $google = \Mockery::mock(GoogleAdsClient::class);
        $google->shouldReceive('missingConfigurationWarnings')->once()->andReturn([]);
        $google->shouldReceive('customerIds')->once()->andReturn(['synthetic-customer-id']);
        $google->shouldReceive('searchDailyMetrics')->once()->andThrow(new \RuntimeException('token=secret person@example.test response body'));
        $this->app->instance(GoogleAdsClient::class, $google);
        foreach ([MetaCampaignSyncService::class, GoogleCampaignSyncService::class] as $service) {
            $result = app($service)->sync(now()->subDay(), now());
            $this->assertNotEmpty($result['warnings']);
            foreach (['secret', 'person@example.test', 'response body', 'synthetic-account-id', 'synthetic-customer-id'] as $sensitive) {
                $this->assertStringNotContainsString($sensitive, json_encode($result['warnings']));
            }
        }
        Log::shouldHaveReceived('warning')->twice()->with(\Mockery::on(fn ($message) => ! str_contains($message, 'secret') && ! str_contains($message, 'example.test')));
    }

    public function test_ambiguous_dispatch_failure_never_releases_an_already_running_worker(): void
    {
        Queue::shouldReceive('connection')->with('operations')->andReturnSelf();
        Queue::shouldReceive('push')->once()->andReturnUsing(function () {
            OperationalTaskRun::query()->sole()->update(['status' => 'running', 'started_at' => now()]);
            throw new \RuntimeException('Ambiguous acknowledgement; secret');
        });
        $run = $this->tasks()->requestManual(self::KEY, $this->actor()->id);
        $this->assertSame('running', $run->status);
        $this->assertSame($run->id, DB::table('operational_task_states')->where('task_key', self::KEY)->value('active_run_id'));
        $this->assertSame('skipped', $this->tasks()->claim(self::KEY, 'scheduler')->status);
    }

    public function test_scheduler_wrapper_executes_fixed_review_contract_and_records_null_actor(): void
    {
        $this->mock(SalesforceReviewSyncService::class, function ($mock): void {
            $mock->shouldReceive('sync')->once()->andReturn(['queried' => 0, 'saved' => 0]);
        });
        $this->artisan('operations:run', ['task' => self::KEY])->assertSuccessful();
        $run = OperationalTaskRun::query()->where('task_key', self::KEY)->sole();
        $this->assertSame('scheduler', $run->origin);
        $this->assertNull($run->report_user_id);
        $this->assertSame('success', $run->status);
        $this->assertDatabaseHas('report_sync_runs', ['dataset' => 'commercial_reviews', 'status' => 'completed']);
        $count = OperationalTaskRun::query()->count();
        $this->artisan('operations:run', ['task' => 'migrate:fresh'])->assertFailed();
        $this->assertSame($count, OperationalTaskRun::query()->count());
    }

    public function test_two_concurrent_http_processes_enqueue_exactly_one_execution(): void
    {
        $relativeDirectory = 'storage/framework/testing/ops-concurrency-'.Str::uuid();
        $directory = base_path($relativeDirectory);
        mkdir($directory, 0777, true);
        $database = $directory.'/database.sqlite';
        touch($database);
        $connection = 'ops-concurrency';
        config(['database.connections.'.$connection => [
            'driver' => 'sqlite', 'database' => $database,
            'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 10000,
        ]]);
        $processes = [];
        try {
            $this->artisan('migrate', ['--database' => $connection, '--force' => true])->assertSuccessful();
            ReportUser::on($connection)->create([
                'id' => 1, 'email' => 'concurrent-admin@example.test',
                'password' => 'synthetic-password', 'role' => 'admin', 'is_active' => true,
            ]);
            for ($i = 0; $i < 2; $i++) {
                // Bootstrap may atomically rebuild manifests before reaching the shared barrier.
                $processCachePrefix = $relativeDirectory.'/process-'.$i;
                $process = new Process([
                    PHP_BINARY, base_path('tests/Fixtures/operational_http_request.php'),
                    $database, $directory.'/ready-'.$i, $directory.'/start',
                ], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
                    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '',
                    'APP_CONFIG_CACHE' => $processCachePrefix.'-config.php',
                    'APP_SERVICES_CACHE' => $processCachePrefix.'-services.php',
                    'APP_PACKAGES_CACHE' => $processCachePrefix.'-packages.php',
                    'APP_ROUTES_CACHE' => $processCachePrefix.'-routes.php',
                    'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array',
                ]);
                $process->setTimeout(30);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            while (! is_file($directory.'/ready-0') || ! is_file($directory.'/ready-1')) {
                foreach ($processes as $process) {
                    if ($process->isTerminated()) {
                        $this->fail('Fixture HTTP sintético: '.$process->getErrorOutput().$process->getOutput());
                    }
                }
                if (microtime(true) > $deadline) {
                    $this->fail('Los procesos HTTP sintéticos no alcanzaron la barrera.');
                }
                usleep(10000);
            }
            touch($directory.'/start');
            $statuses = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $statuses[] = trim($process->getOutput());
            }
            sort($statuses);
            $this->assertSame(['302', '429'], $statuses);
            $this->assertSame(1, DB::connection($connection)->table('operational_task_runs')->where('status', 'queued')->count());
            $this->assertSame(1, DB::connection($connection)->table('jobs')->where('queue', 'operations')->count());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            DB::purge($connection);
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    private function tasks(): OperationalTaskService
    {
        return app(OperationalTaskService::class);
    }

    private function actor(string $role = 'admin'): ReportUser
    {
        return ReportUser::query()->create([
            'email' => uniqid('ops-', true).'@example.test',
            'password' => 'synthetic-test-password',
            'role' => $role, 'is_active' => true,
        ]);
    }

    private function asReportUser(ReportUser $user): void
    {
        $this->withSession(['informes_authenticated' => true, 'report_user_id' => $user->id]);
    }
}
