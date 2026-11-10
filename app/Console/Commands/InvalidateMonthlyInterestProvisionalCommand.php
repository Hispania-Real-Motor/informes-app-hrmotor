<?php

namespace App\Console\Commands;

use App\Services\Reports\Leads\October2026ProvisionalEvidenceInvalidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class InvalidateMonthlyInterestProvisionalCommand extends Command
{
    protected $signature = 'reports:invalidate-monthly-interest-provisional
        {--recovery-id= : Monthly F2 revision whose provisional evidence must be revoked}
        {--confirm : Explicitly revoke the local validation evidence}';

    protected $description = 'Revoke one monthly dashboard projection before approved direct journal maintenance.';

    public function handle(October2026ProvisionalEvidenceInvalidator $invalidator): int
    {
        $id = filter_var($this->option('recovery-id'), FILTER_VALIDATE_INT);
        if (! $this->option('confirm') || $id === false || $id < 1) {
            $this->error('A positive recovery ID and --confirm are required.');

            return self::FAILURE;
        }
        try {
            if (! DB::table('salesforce_interest_monthly_recoveries')->where('id', $id)->exists()) {
                throw new \RuntimeException('Monthly recovery does not exist.');
            }
            $invalidator->afterContentMutation($id);
        } catch (Throwable) {
            $this->error('Monthly invalidation was rejected safely.');

            return self::FAILURE;
        }
        $this->line(json_encode(['invalidated' => true, 'recovery_id' => $id], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
