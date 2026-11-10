<?php

namespace App\Console\Commands;

use App\Services\Salesforce\InterestMonthlyMaterializationService;
use App\Services\Salesforce\InterestMonthlyRecoveryService;
use App\Services\Salesforce\MonthlyInterestActivityRecoveryService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Isolated monthly F2/F2M/F5 refresh; never touches a published generation. */
final class SyncCurrentMonthInterestsCommand extends Command
{
    private const REASON = 'Scheduled isolated monthly Interest refresh';

    protected $signature = 'salesforce:sync-interest-monthly-current
        {--allow-salesforce-read : Explicit authorization for bounded Salesforce reads}';

    protected $description = 'Refresh the current month isolated Interest journals without publication.';

    public function handle(
        InterestMonthlyRecoveryService $f2,
        InterestMonthlyMaterializationService $f2m,
        MonthlyInterestActivityRecoveryService $f5,
    ): int {
        if ($this->option('allow-salesforce-read') !== true) {
            $this->error('Monthly Interest refresh requires explicit read authorization.');

            return self::FAILURE;
        }

        try {
            $now = CarbonImmutable::now('UTC');
            $localStart = $now->setTimezone('Europe/Madrid')->startOfMonth();
            $localEnd = $localStart->addMonth();
            $start = $localStart->utc();
            $end = $localEnd->utc();
            $cutoff = $now->subMinutes(2)->startOfMinute();
            if ($cutoff->lte($start)) {
                $this->line('SKIPPED: monthly Interest refresh has no safe cutoff yet.');

                return self::SUCCESS;
            }

            $latest = DB::table('salesforce_interest_monthly_recoveries')
                ->where('functional_start_at', $start)->where('functional_end_at', $end)
                ->orderByDesc('revision')->first();
            if ($latest !== null && $latest->status === 'completed') {
                $this->assertVerified($f2->status((int) $latest->id));
                if (! $this->completeDependents((int) $latest->id, $f2m, $f5)) {
                    return self::SUCCESS;
                }
                if ($latest->effective_cutoff_at !== null
                    && CarbonImmutable::parse($latest->effective_cutoff_at, 'UTC')->gte($cutoff)) {
                    $this->line('SKIPPED: monthly Interest revision is already current for this cutoff.');

                    return self::SUCCESS;
                }
            }

            $id = $latest !== null && $latest->status !== 'completed'
                ? (int) $latest->id
                : $f2->prepare($localStart, $localEnd, self::REASON, 500, $cutoff, $latest !== null);
            $status = $f2->status($id);
            if ($status['status'] !== 'completed') {
                $status = $f2->run($id, self::REASON, $status['status'] !== 'prepared');
            }
            $this->assertVerified($status);
            $this->completeDependents($id, $f2m, $f5);
            $this->line(json_encode(['monthly_recovery_id' => $id, 'status' => 'completed', 'published' => false], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            // The journals retain their checkpoints and safe diagnostics. Avoid leaking Salesforce payloads.
            $this->error('Monthly Interest refresh stopped. Inspect isolated journal status before resuming.');

            return self::FAILURE;
        }
    }

    private function completeDependents(
        int $recoveryId,
        InterestMonthlyMaterializationService $f2m,
        MonthlyInterestActivityRecoveryService $f5,
    ): bool {
        $materialization = DB::table('salesforce_interest_monthly_materializations')
            ->where('monthly_recovery_id', $recoveryId)->first();
        $materializationId = $materialization === null
            ? $f2m->prepare($recoveryId, self::REASON)
            : (int) $materialization->id;
        $materializationStatus = $f2m->status($materializationId);
        if ($materializationStatus['status'] !== 'completed') {
            $materializationStatus = $f2m->run($materializationId, self::REASON, $materializationStatus['status'] !== 'prepared');
        }
        $this->assertVerified($materializationStatus);

        $activity = DB::table('salesforce_interest_monthly_activity_recoveries')
            ->where('monthly_recovery_id', $recoveryId)->first();
        $activityId = $activity === null
            ? $f5->prepare($recoveryId, self::REASON)
            : (int) $activity->id;
        $activityStatus = $f5->status($activityId);
        if ($activityStatus['status'] !== 'completed') {
            $activityStatus = $f5->run($activityId, self::REASON, $activityStatus['status'] !== 'prepared');
        }
        $this->assertVerified($activityStatus);

        return $materialization !== null && $materialization->status === 'completed'
            && $activity !== null && $activity->status === 'completed';
    }

    /** @param array<string, mixed> $status */
    private function assertVerified(array $status): void
    {
        if ($status['status'] !== 'completed' || ! data_get($status, 'evidence.verification.verified', false)) {
            throw new RuntimeException('Monthly Interest evidence is incomplete.');
        }
    }
}
