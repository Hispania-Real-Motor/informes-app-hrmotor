<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsPreflightTest extends TestCase
{
    use RefreshDatabase;

    public function test_preflight_reports_environment_without_running_an_operational_task(): void
    {
        $this->artisan('operations:preflight')
            ->assertSuccessful()
            ->expectsOutputToContain('"cache_round_trip":{"ok":true')
            ->expectsOutputToContain('"database":{"ok":true');

        $this->assertDatabaseCount('operational_task_runs', 0);
    }
}
