<?php

namespace App\Console\Commands;

use App\Services\Reports\Leads\October2026ProvisionalEvidenceInvalidator;
use Illuminate\Console\Command;
use Throwable;

final class InvalidateOctober2026ProvisionalInterestsCommand extends Command
{
    protected $signature = 'reports:invalidate-october-2026-provisional {--confirm : Explicitly revoke the local validation evidence}';

    protected $description = 'Fail-close the fixed October 2026 provisional view before approved journal maintenance.';

    public function handle(October2026ProvisionalEvidenceInvalidator $invalidator): int
    {
        if (! $this->option('confirm')) {
            $this->error('Invalidation requires --confirm.');

            return self::FAILURE;
        }

        try {
            $invalidator->revoke();
        } catch (Throwable) {
            $this->error('Evidence revocation was rejected safely.');

            return self::FAILURE;
        }

        $this->info('October 2026 provisional evidence revoked.');

        return self::SUCCESS;
    }
}
