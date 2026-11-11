<?php

namespace App\Console\Commands;

use App\Services\Reports\Leads\MonthlyInterestProvisionalReader;
use Illuminate\Console\Command;
use Throwable;

final class ValidateMonthlyInterestProvisionalCommand extends Command
{
    protected $signature = 'reports:validate-monthly-interest-provisional
        {--recovery-id= : Completed and verified monthly F2 revision}
        {--confirm : Explicitly publish isolated monthly validation evidence}';

    protected $description = 'Validate one monthly F2/F2M/F5 revision and prepare a private dashboard projection.';

    public function handle(MonthlyInterestProvisionalReader $reader): int
    {
        $id = filter_var($this->option('recovery-id'), FILTER_VALIDATE_INT);
        if (! $this->option('confirm') || $id === false || $id < 1) {
            $this->error('A positive recovery ID and --confirm are required.');

            return self::FAILURE;
        }

        try {
            $overview = $reader->validate($id);
        } catch (Throwable) {
            $this->error('Monthly validation was rejected safely.');

            return self::FAILURE;
        }
        $this->line(json_encode(['validated' => true, 'recovery_id' => $id,
            'coverage' => $overview['coverage'], 'period' => $overview['period']], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
