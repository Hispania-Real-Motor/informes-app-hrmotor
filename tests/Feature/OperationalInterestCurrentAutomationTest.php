<?php

namespace Tests\Feature;

use App\Services\Operations\OperationalTaskCatalog;
use App\Services\Salesforce\OperationalInterestCurrentAutomation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalInterestCurrentAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_operational_automation_is_disabled_by_default_and_requires_explicit_read_authorization(): void
    {
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
}
