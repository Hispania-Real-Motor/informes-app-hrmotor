<?php

namespace App\Services\Operations;

use App\Models\OperationalTaskRun;

enum OperationalTaskExitCode: int
{
    case Success = 0;
    case TechnicalFailure = 1;
    case InvalidRequest = 2;
    case Skipped = 3;
    case Blocked = 4;
    case Unhealthy = 5;

    public static function forRun(OperationalTaskRun $run): self
    {
        return match (true) {
            $run->status === 'success' && $run->started_at !== null
                && $run->finished_at !== null && $run->exit_code === 0 => self::Success,
            $run->status === 'unhealthy' && $run->task_key === OperationalTaskCatalog::HEALTH_TASK_KEY
                && $run->started_at !== null && $run->finished_at !== null
                && $run->exit_code === self::Unhealthy->value => self::Unhealthy,
            $run->status === 'failed' => self::TechnicalFailure,
            $run->status === 'skipped' && $run->started_at !== null && $run->finished_at !== null => self::Skipped,
            default => self::Blocked,
        };
    }
}
