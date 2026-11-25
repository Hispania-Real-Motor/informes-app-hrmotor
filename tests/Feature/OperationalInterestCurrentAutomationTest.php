<?php

namespace Tests\Feature;

use App\Services\Operations\OperationalTaskCatalog;
use App\Services\Salesforce\OperationalInterestCurrentAutomation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperationalInterestCurrentAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_operational_automation_is_disabled_by_default_and_requires_explicit_read_authorization(): void
    {
        // The assertion describes the safe application default, independent
        // of a developer's Herd .env overrides.
        config()->set('operations.operational_interest_current_sync_enabled', false);
        $task = app(OperationalTaskCatalog::class)->get('salesforce-sync-interest-operational-current');

        $this->assertFalse($task['enabled']);
        $this->assertFalse($task['manual_allowed']);
        $this->assertFalse(app(OperationalTaskCatalog::class)->isScheduled('salesforce-sync-interest-operational-current'));
        $this->mock(OperationalInterestCurrentAutomation::class, function ($mock): void {
            $mock->shouldNotReceive('run');
        });

        $this->artisan('salesforce:sync-interest-operational-current')->assertFailed();
    }

    public function test_automation_reports_a_safe_failure_without_exposing_dependency_details(): void
    {
        config()->set('operations.operational_interest_current_sync_enabled', true);
        $this->mock(OperationalInterestCurrentAutomation::class, function ($mock): void {
            $mock->shouldReceive('run')->once()->andThrow(new \RuntimeException('contract unavailable'));
        });

        $this->artisan('salesforce:sync-interest-operational-current', ['--allow-salesforce-read' => true])
            ->assertFailed();
    }

    public function test_failed_run_with_an_older_cutoff_is_not_reused_after_a_new_active_pointer(): void
    {
        $activeProjection = (string) Str::uuid();
        $activeId = DB::table('salesforce_interest_operational_runs')->insertGetId([
            'run_identifier' => (string) Str::uuid(), 'month_key' => '2026-11',
            'functional_start_at' => '2026-11-01 00:00:00', 'functional_end_at' => '2026-12-01 00:00:00',
            'effective_cutoff_at' => '2026-11-20 10:00:00', 'status' => 'completed', 'phase' => 'completed',
            'projection_key' => $activeProjection, 'overview' => json_encode([]),
            'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('salesforce_interest_operational_months')->insert([
            'month_key' => '2026-11', 'active_run_id' => $activeId,
            'active_projection_key' => $activeProjection, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $failed = DB::table('salesforce_interest_operational_runs')->insertGetId([
            'run_identifier' => (string) Str::uuid(), 'month_key' => '2026-11',
            'functional_start_at' => '2026-11-01 00:00:00', 'functional_end_at' => '2026-12-01 00:00:00',
            'effective_cutoff_at' => '2026-11-19 10:00:00', 'status' => 'failed', 'phase' => 'extract',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $automation = app(OperationalInterestCurrentAutomation::class);
        $method = new \ReflectionMethod($automation, 'isSupersededByActive');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($automation, DB::table('salesforce_interest_operational_runs')->find($failed)));
    }
}
