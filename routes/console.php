<?php

use App\Services\Operations\OperationalTaskCatalog;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Fixed commands/options/cadences live in the catalog. Both origins claim
// the same durable reservation; the scheduler mutex remains an outer guard.
foreach (app(OperationalTaskCatalog::class)->all() as $key => $task) {
    Schedule::command('operations:run', [$key])
        ->name($key)
        ->cron($task['cron'])
        ->timezone($task['timezone'])
        ->withoutOverlapping($task['window_minutes']);
}
