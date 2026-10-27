<?php

namespace Tests\Feature;

use App\Models\OperationalTaskRun;
use App\Models\ReportSyncRun;
use App\Services\Campaigns\CampaignPublicationReadiness;
use App\Services\Operations\OperationalTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class CampaignPublicationRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_source_is_retried_through_its_existing_operational_reservation(): void
    {
        $this->sourcesPending('campaigns-sync-google');
        $run = $this->operationalRun('campaigns-sync-google', 'queued');
        $this->successfulChild($run);

        $this->artisan('campaigns:reconcile-publication')
            ->expectsOutputToContain('Dependencia recuperada: campaigns-sync-google.')
            ->assertExitCode(0);
    }

    public function test_pending_attribution_is_retried_after_sources_are_ready(): void
    {
        $this->mock(CampaignPublicationReadiness::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sources')->once()->andReturn(['ready' => true, 'ids' => ['meta' => 1]]);
            $mock->shouldReceive('attributionReady')->once()->andReturn(null);
        });
        $run = $this->operationalRun('campaigns-build-attribution', 'queued');
        $this->successfulChild($run);

        $this->artisan('campaigns:reconcile-publication')->assertExitCode(0);
    }

    public function test_publication_is_retried_after_a_valid_attribution(): void
    {
        $this->readyUntilPublication(false);
        $run = $this->operationalRun('reports-refresh-campaigns', 'queued');
        $this->successfulChild($run);

        $this->artisan('campaigns:reconcile-publication')->assertExitCode(0);
    }

    public function test_verified_publication_needs_no_child_task(): void
    {
        $this->readyUntilPublication(true);
        $this->mock(OperationalTaskService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('claim');
        });

        $this->artisan('campaigns:reconcile-publication')
            ->expectsOutputToContain('SKIPPED:')
            ->assertExitCode(0);
    }

    public function test_retry_limit_prevents_repeated_provider_calls(): void
    {
        $this->sourcesPending('campaigns-sync-google');
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->operationalRun('campaigns-sync-google', 'failed');
        }
        $this->mock(OperationalTaskService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('claim');
        });

        $this->artisan('campaigns:reconcile-publication')
            ->expectsOutputToContain('SKIPPED: límite diario')
            ->assertExitCode(0);
    }

    public function test_failed_dependency_can_recover_on_a_later_pass(): void
    {
        $this->mock(CampaignPublicationReadiness::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sources')->twice()->andReturn(['ready' => false,
                'retry_task' => 'campaigns-sync-google']);
        });
        $failed = $this->operationalRun('campaigns-sync-google', 'queued');
        $recovered = $this->operationalRun('campaigns-sync-google', 'queued');
        $this->mock(OperationalTaskService::class, function (MockInterface $mock) use ($failed, $recovered): void {
            $mock->shouldReceive('claim')->twice()->with('campaigns-sync-google', 'scheduler')
                ->andReturn($failed, $recovered);
            $mock->shouldReceive('execute')->once()->with($failed->id)->andReturnUsing(function () use ($failed): void {
                $failed->update(['status' => 'failed', 'finished_at' => now()]);
            });
            $mock->shouldReceive('execute')->once()->with($recovered->id)->andReturnUsing(function () use ($recovered): void {
                $recovered->update(['status' => 'success', 'finished_at' => now()]);
            });
        });

        $this->artisan('campaigns:reconcile-publication')->assertExitCode(1);
        $this->artisan('campaigns:reconcile-publication')->assertExitCode(0);
    }

    public function test_active_reconstruction_is_not_started_twice(): void
    {
        $this->mock(CampaignPublicationReadiness::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sources')->once()->andReturn(['ready' => true, 'ids' => ['meta' => 1]]);
            $mock->shouldReceive('attributionReady')->once()->andReturn(null);
        });
        $busy = $this->operationalRun('campaigns-build-attribution', 'skipped');
        $this->mock(OperationalTaskService::class, function (MockInterface $mock) use ($busy): void {
            $mock->shouldReceive('claim')->once()->with('campaigns-build-attribution', 'scheduler')->andReturn($busy);
            $mock->shouldNotReceive('execute');
        });

        $this->artisan('campaigns:reconcile-publication')
            ->expectsOutputToContain('SKIPPED:')
            ->assertExitCode(0);
    }

    public function test_real_operational_reservation_serializes_two_attribution_requests(): void
    {
        $tasks = app(OperationalTaskService::class);
        $first = $tasks->claim('campaigns-build-attribution', 'scheduler');
        $second = $tasks->claim('campaigns-build-attribution', 'scheduler');

        $this->assertSame('queued', $first->status);
        $this->assertSame('skipped', $second->status);
        $this->assertSame($first->id, (int) DB::table('operational_task_states')
            ->where('task_key', 'campaigns-build-attribution')->value('active_run_id'));
    }

    private function sourcesPending(string $task): void
    {
        $this->mock(CampaignPublicationReadiness::class, function (MockInterface $mock) use ($task): void {
            $mock->shouldReceive('sources')->once()->andReturn(['ready' => false, 'retry_task' => $task]);
        });
    }

    private function readyUntilPublication(bool $published): void
    {
        $this->mock(CampaignPublicationReadiness::class, function (MockInterface $mock) use ($published): void {
            $mock->shouldReceive('sources')->once()->andReturn(['ready' => true, 'ids' => ['meta' => 1]]);
            $mock->shouldReceive('attributionReady')->once()->andReturn(new ReportSyncRun);
            $mock->shouldReceive('attributionCovers')->once()->andReturn(true);
            $mock->shouldReceive('published')->once()->andReturn(['ready' => $published]);
        });
    }

    private function operationalRun(string $task, string $status): OperationalTaskRun
    {
        return OperationalTaskRun::query()->create([
            'task_key' => $task, 'origin' => 'scheduler', 'status' => $status,
            'arguments' => [], 'correlation_id' => (string) Str::uuid(),
            'queued_at' => now(), 'finished_at' => $status === 'queued' ? null : now(),
        ]);
    }

    private function successfulChild(OperationalTaskRun $run): void
    {
        $this->mock(OperationalTaskService::class, function (MockInterface $mock) use ($run): void {
            $mock->shouldReceive('claim')->once()->with($run->task_key, 'scheduler')->andReturn($run);
            $mock->shouldReceive('execute')->once()->with($run->id)->andReturnUsing(function () use ($run): void {
                $run->update(['status' => 'success', 'finished_at' => now()]);
            });
        });
    }
}
