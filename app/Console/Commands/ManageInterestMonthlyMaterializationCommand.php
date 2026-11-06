<?php

namespace App\Console\Commands;

use App\Services\Salesforce\InterestMonthlyMaterializationService;
use Illuminate\Console\Command;
use RuntimeException;

/** Operator-only control plane for the isolated monthly F2 materialization journal. */
final class ManageInterestMonthlyMaterializationCommand extends Command
{
    protected $signature = 'interests:monthly-f2-materialization
        {action : prepare|run|status|manifest|cancel}
        {--monthly-recovery= : Completed monthly F2 recovery ID}
        {--materialization= : Monthly F2 materialization ID}
        {--reason= : Operational reason, 10-500 characters}
        {--batch-size=500 : Bounded Salesforce ID block size (50-500)}
        {--resume : Resume a failed materialization}
        {--confirm-salesforce : Explicit authorization for the remote read}';

    protected $description = 'Manage an isolated, non-published monthly functional Interest materialization.';

    public function handle(InterestMonthlyMaterializationService $service): int
    {
        try {
            return match ($this->argument('action')) {
                'prepare' => $this->prepare($service),
                'run' => $this->runMaterialization($service),
                'status' => $this->display($service->status($this->materializationId())),
                'manifest' => $this->display($service->manifest($this->materializationId())),
                'cancel' => $this->cancel($service),
                default => throw new RuntimeException('Unsupported monthly F2 materialization action.'),
            };
        } catch (RuntimeException) {
            $this->error('Monthly F2 materialization operation was rejected or failed safely.');

            return self::FAILURE;
        }
    }

    private function prepare(InterestMonthlyMaterializationService $service): int
    {
        $batch = filter_var($this->option('batch-size'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 50, 'max_range' => 500]]);
        if ($batch === false) {
            throw new RuntimeException('A safe materialization batch size is required.');
        }

        return $this->display([
            'materialization_id' => $service->prepare($this->monthlyRecoveryId(), $this->reason(), $batch),
            'published' => false,
        ]);
    }

    private function runMaterialization(InterestMonthlyMaterializationService $service): int
    {
        if (! $this->option('confirm-salesforce')) {
            throw new RuntimeException('Remote reads require explicit operator authorization.');
        }

        return $this->display($service->run($this->materializationId(), $this->reason(), (bool) $this->option('resume')));
    }

    private function cancel(InterestMonthlyMaterializationService $service): int
    {
        $id = $this->materializationId();
        $service->cancel($id);

        return $this->display($service->status($id));
    }

    private function monthlyRecoveryId(): int
    {
        $id = filter_var($this->option('monthly-recovery'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new RuntimeException('A completed monthly F2 recovery ID is required.');
        }

        return $id;
    }

    private function materializationId(): int
    {
        $id = filter_var($this->option('materialization'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new RuntimeException('A monthly F2 materialization ID is required.');
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

    /** @param array<string,mixed> $payload */
    private function display(array $payload): int
    {
        $this->line(json_encode($payload, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
