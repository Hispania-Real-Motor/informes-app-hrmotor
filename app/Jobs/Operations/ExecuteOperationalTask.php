<?php

namespace App\Jobs\Operations;

use App\Services\Operations\OperationalTaskService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ExecuteOperationalTask implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 14400;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $runId) {}

    public function handle(OperationalTaskService $tasks): void
    {
        $tasks->execute($this->runId);
    }

    public function failed(?Throwable $exception): void
    {
        app(OperationalTaskService::class)->jobFailed($this->runId);
    }
}
