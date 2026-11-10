<?php

namespace App\Services\Reports\Leads;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Invalidates monthly evidence in the same transaction as a journal mutation. */
final class October2026ProvisionalEvidenceInvalidator
{
    public const RECOVERY_ID = 1;

    private const SCOPE = 'october_2026_provisional';

    public function afterContentMutation(int $monthlyRecoveryId): void
    {
        $scope = self::monthlyScope($monthlyRecoveryId);
        DB::table('salesforce_interest_monthly_integrity_revisions')->insertOrIgnore([
            'scope' => $scope, 'revision' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->increment($scope);

        if ($monthlyRecoveryId === self::RECOVERY_ID) {
            $this->increment(self::SCOPE);
        }
    }

    public static function monthlyScope(int $monthlyRecoveryId): string
    {
        if ($monthlyRecoveryId < 1) {
            throw new RuntimeException('Monthly recovery identifier is invalid.');
        }

        return 'monthly_recovery_'.$monthlyRecoveryId;
    }

    private function increment(string $scope): void
    {
        $updated = DB::table('salesforce_interest_monthly_integrity_revisions')
            ->where('scope', $scope)
            ->update(['revision' => DB::raw('revision + 1'), 'updated_at' => now()]);

        if ($updated !== 1) {
            throw new RuntimeException('Monthly provisional evidence revision is unavailable.');
        }
    }

    /** Explicit fail-closed operational revocation before approved direct maintenance. */
    public function revoke(): void
    {
        $this->afterContentMutation(self::RECOVERY_ID);
    }
}
