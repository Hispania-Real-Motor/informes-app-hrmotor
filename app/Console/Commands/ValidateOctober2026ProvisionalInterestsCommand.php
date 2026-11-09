<?php

namespace App\Console\Commands;

use App\Services\Reports\Leads\October2026ProvisionalInterestReader;
use Illuminate\Console\Command;
use Throwable;

final class ValidateOctober2026ProvisionalInterestsCommand extends Command
{
    protected $signature = 'reports:validate-october-2026-provisional {--confirm : Explicitly write the local validation evidence}';

    protected $description = 'Validate the fixed October 2026 provisional F2/F2M/F5 journals without Salesforce access.';

    public function handle(October2026ProvisionalInterestReader $reader): int
    {
        if (! $this->option('confirm')) {
            $this->error('Validation requires --confirm.');

            return self::FAILURE;
        }
        try {
            $overview = $reader->validate();
        } catch (Throwable) {
            $this->error('Validation was rejected safely.');

            return self::FAILURE;
        }
        $this->line(json_encode(['validated' => true, 'coverage' => $overview['coverage'], 'metrics_ns' => $reader->validationMetrics()], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
