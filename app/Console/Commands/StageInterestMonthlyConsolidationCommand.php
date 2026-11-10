<?php

namespace App\Console\Commands;

use App\Services\Salesforce\InterestMonthlyConsolidationService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/** Administrative, local-only staging. No Salesforce query or publication. */
final class StageInterestMonthlyConsolidationCommand extends Command
{
    protected $signature = 'salesforce:stage-interest-months
        {--materialization=* : Verified monthly F2M IDs to stage in one immutable candidate}
        {--run= : Existing consolidation ID to resume or inspect}
        {--reason= : Required audit reason for preparation and resume}
        {--resume : Resume a failed or expired staging run}
        {--status : Show safe status without writing}';

    protected $description = 'Stage verified monthly F2M/F5 sources for later independent global coverage review.';

    public function handle(InterestMonthlyConsolidationService $service): int
    {
        try {
            $runId = $this->option('run');
            if ($this->option('status')) {
                if (! is_string($runId) || ! ctype_digit($runId) || (int) $runId < 1) {
                    throw new RuntimeException('A valid staging run is required for status.');
                }
                $this->line(json_encode($service->status((int) $runId), JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
            $reason = $this->option('reason');
            if (! is_string($reason) || mb_strlen(trim($reason)) < 10) {
                throw new RuntimeException('A staging audit reason is required.');
            }
            if ($runId !== null) {
                if (! is_string($runId) || ! ctype_digit($runId) || (int) $runId < 1
                    || $this->option('materialization') !== [] || ! $this->option('resume')) {
                    throw new RuntimeException('Resume requires only a valid existing staging run.');
                }
                $id = (int) $runId;
            } else {
                if ($this->option('resume')) {
                    throw new RuntimeException('A staging run is required to resume.');
                }
                $values = $this->option('materialization');
                if (! is_array($values) || $values === [] || count(array_filter($values,
                    fn (mixed $value): bool => is_string($value) && ctype_digit($value) && (int) $value > 0)) !== count($values)) {
                    throw new RuntimeException('Verified monthly materialization IDs are required.');
                }
                $id = $service->prepare(array_map('intval', $values), $reason);
            }
            $this->line(json_encode($service->run($id, $reason, (bool) $this->option('resume')), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Monthly consolidation staging stopped. Inspect the local staging journal.');

            return self::FAILURE;
        }
    }
}
