<?php

namespace Tests\Feature;

use App\Services\Operations\OperationalTaskCatalog;
use App\Services\Operations\OperationalTaskService;
use Tests\TestCase;

class CampaignDailyOperationalAutomationTest extends TestCase
{
    public function test_daily_automation_is_disabled_by_default(): void
    {
        config(['campaign_operational.daily_automation_enabled' => false]);

        $this->artisan('campaigns:sync-daily-operational')->assertFailed();
    }

    public function test_catalog_keeps_daily_automation_disabled_and_excludes_official_interest_pipeline(): void
    {
        config(['campaign_operational.daily_automation_enabled' => false]);
        $task = app(OperationalTaskCatalog::class)->get('campaigns-sync-daily-operational');

        $this->assertFalse($task['enabled']);
        $this->assertSame('campaigns:sync-daily-operational', $task['command']);
        $this->assertStringNotContainsString('salesforce-sync-interest-reporting', json_encode($task, JSON_THROW_ON_ERROR));
    }

    public function test_daily_automation_stops_before_attribution_when_a_dependency_fails(): void
    {
        config([
            'campaign_operational.daily_automation_enabled' => true,
            'campaign_operational.november_2026_enabled' => true,
        ]);
        $this->mock(OperationalTaskService::class, function ($mock): void {
            $mock->shouldReceive('claim')->once()->andThrow(new \RuntimeException('dependency unavailable'));
        });

        $this->artisan('campaigns:sync-daily-operational')->assertFailed();
    }

    public function test_auto_activation_is_disabled_by_default(): void
    {
        $this->assertFalse(config('campaign_operational.daily_automation_auto_activate_enabled'));
    }
}
