<?php

namespace App\Console\Commands;

use App\Services\Salesforce\MonthlyInterestActivityRecoveryService;
use Illuminate\Console\Command;
use RuntimeException;

final class ManageMonthlyInterestActivityRecoveryCommand extends Command
{
    protected $signature = 'interests:monthly-f5
        {action : prepare|run|status|manifest|cancel}
        {--monthly-recovery= : Completed monthly F2 recovery ID}
        {--run= : Monthly F5 run ID}
        {--reason= : Operational reason, 10-500 characters}
        {--resume : Resume a failed F5 run}
        {--confirm-salesforce : Explicit authorization for the remote read}';

    protected $description = 'Manage an isolated, non-published monthly Interest F5 recovery.';

    public function handle(MonthlyInterestActivityRecoveryService $service): int
    {
        try {
            return match ($this->argument('action')) {
                'prepare' => $this->prepare($service),
                'run' => $this->run($service),
                'status' => $this->display($service->status($this->runId())),
                'manifest' => $this->display($service->manifest($this->runId())),
                'cancel' => $this->cancel($service),
                default => throw new RuntimeException('Unsupported monthly F5 action.'),
            };
        } catch (RuntimeException $exception) {
            $this->error('Monthly F5 operation was rejected or failed safely.');

            return self::FAILURE;
        }
    }

    private function prepare(MonthlyInterestActivityRecoveryService $service): int
    {
        $id = $service->prepare($this->monthlyRecoveryId(), $this->reason());

        return $this->display(['run_id' => $id, 'published' => false]);
    }

    private function run(MonthlyInterestActivityRecoveryService $service): int
    {
        if (! $this->option('confirm-salesforce')) {
            throw new RuntimeException('Remote reads require explicit operator authorization.');
        }

        return $this->display($service->run($this->runId(), $this->reason(), (bool) $this->option('resume')));
    }

    private function cancel(MonthlyInterestActivityRecoveryService $service): int
    {
        $service->cancel($this->runId());

        return $this->display($service->status($this->runId()));
    }

    private function monthlyRecoveryId(): int
    {
        $id = filter_var($this->option('monthly-recovery'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new RuntimeException('A monthly F2 recovery ID is required.');
        }

        return $id;
    }

    private function runId(): int
    {
        $id = filter_var($this->option('run'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new RuntimeException('A monthly F5 run ID is required.');
        }

        return $id;
    }

    private function reason(): string
    {
        $reason = trim((string) $this->option('reason'));
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw new RuntimeException('A 10-500 character operational reason is required.');
        }

        return $reason;
    }

    /** @param array<string, mixed> $payload */
    private function display(array $payload): int
    {
        $this->line(json_encode($payload, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
