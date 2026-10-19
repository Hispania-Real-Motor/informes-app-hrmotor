<?php

namespace Tests\Concerns;

use App\Services\Operations\OperationalTaskCatalog;
use Illuminate\Console\Scheduling\Schedule;

trait AssertsOperationalSchedule
{
    protected function assertOperationalSchedule(): void
    {
        $baseline = require base_path('tests/Fixtures/operational_schedule_baseline.php');
        $baseline['salesforce-sync-commercial-reviews'] = ['salesforce:sync-commercial-reviews', ['--months' => '2'], '20 2 * * *', 120];
        $baseline['operations-health'] = ['operations:check-health', [], '*/5 * * * *', 5];
        $tasks = app(OperationalTaskCatalog::class)->all();
        $events = collect(app(Schedule::class)->events())->keyBy('description');
        $this->assertCount(count($baseline), $tasks);
        $this->assertCount(count($baseline), $events);
        foreach ($baseline as $key => [$command, $arguments, $cron, $window]) {
            $this->assertSame($command, $tasks[$key]['command']);
            $this->assertSame($arguments, $tasks[$key]['arguments']);
            $this->assertSame($cron, $tasks[$key]['cron']);
            $this->assertSame($window, $tasks[$key]['window_minutes']);
            $event = $events->get($key);
            $this->assertNotNull($event);
            $this->assertSame($cron, $event->expression);
            $this->assertSame('Europe/Madrid', $event->timezone);
            $this->assertTrue($event->withoutOverlapping);
            $this->assertSame($window, $event->expiresAt);
            $this->assertStringContainsString('operations:run', $event->command);
            $this->assertStringContainsString($key, $event->command);
            $this->assertStringNotContainsString('task=', $event->command);
            $this->assertSame($key === OperationalTaskCatalog::HEALTH_TASK_KEY, str_contains($event->command, '--scheduler'));
        }
    }
}
