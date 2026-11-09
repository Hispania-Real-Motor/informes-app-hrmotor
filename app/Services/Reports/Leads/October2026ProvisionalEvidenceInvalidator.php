<?php

namespace App\Services\Reports\Leads;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Invalidates only the fixed October 2026 evidence after an authorised
 * journal-content mutation. Callers must invoke it inside that same database
 * transaction, after the content write and before the transaction commits.
 */
final class October2026ProvisionalEvidenceInvalidator
{
    public const RECOVERY_ID = 1;

    private const SCOPE = 'october_2026_provisional';

    public function afterContentMutation(int $monthlyRecoveryId): void
    {
        if ($monthlyRecoveryId !== self::RECOVERY_ID) {
            return;
        }

        $updated = DB::table('salesforce_interest_monthly_integrity_revisions')
            ->where('scope', self::SCOPE)
            ->update(['revision' => DB::raw('revision + 1'), 'updated_at' => now()]);

        if ($updated !== 1) {
            throw new RuntimeException('October provisional evidence revision is unavailable.');
        }
    }

    /** Explicit fail-closed operational revocation before approved direct maintenance. */
    public function revoke(): void
    {
        $this->afterContentMutation(self::RECOVERY_ID);
    }
}
