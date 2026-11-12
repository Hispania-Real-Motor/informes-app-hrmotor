<?php

namespace App\Console\Commands;

use App\Services\Reports\Leads\MonthlyInterestProvisionalReader;
use App\Services\Reports\Leads\ProvisionalInterestSourceUnavailable;
use App\Services\Salesforce\InterestMonthlyMaterializationService;
use App\Services\Salesforce\InterestMonthlyRecoveryService;
use App\Services\Salesforce\MonthlyInterestActivityRecoveryService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Isolated monthly F2/F2M/F5 refresh; never touches a published generation. */
final class SyncCurrentMonthInterestsCommand extends Command
{
    private const REASON = 'Scheduled isolated monthly Interest refresh';

    private const MAX_CONSECUTIVE_UNVERIFIED = 3;

    protected $signature = 'salesforce:sync-interest-monthly-current
        {--allow-salesforce-read : Explicit authorization for bounded Salesforce reads}';

    protected $description = 'Refresh the current month isolated Interest journals without publication.';

    public function handle(
        InterestMonthlyRecoveryService $f2,
        InterestMonthlyMaterializationService $f2m,
        MonthlyInterestActivityRecoveryService $f5,
        MonthlyInterestProvisionalReader $reader,
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
                $latestStatus = $f2->status((int) $latest->id);
                if ($this->isVerified($latestStatus)) {
                    $this->completeDependents((int) $latest->id, $f2m, $f5);
                    $projectionCreated = $this->ensureProjection((int) $latest->id, $reader);
                    if ($latest->effective_cutoff_at !== null
                        && CarbonImmutable::parse($latest->effective_cutoff_at, 'UTC')->gte($cutoff)) {
                        $this->line($projectionCreated
                            ? json_encode(['monthly_recovery_id' => (int) $latest->id, 'status' => 'completed', 'published' => false], JSON_THROW_ON_ERROR)
                            : 'SKIPPED: monthly Interest revision is already current for this cutoff.');

                        return self::SUCCESS;
                    }
                } elseif ($latest->effective_cutoff_at === null
                    || CarbonImmutable::parse($latest->effective_cutoff_at, 'UTC')->gte($cutoff)) {
                    throw new MonthlyInterestEvidenceUnverified;
                } elseif ($this->consecutiveUnverified($start, $end) >= self::MAX_CONSECUTIVE_UNVERIFIED) {
                    $this->error('Monthly Interest integrity retry limit reached. Review isolated journal before another revision.');

                    return self::FAILURE;
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
            $this->ensureProjection($id, $reader);
            $this->line(json_encode(['monthly_recovery_id' => $id, 'status' => 'completed', 'published' => false], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (MonthlyInterestEvidenceUnverified) {
            $this->error('Monthly Interest integrity verification failed. A later safe cutoff is required for a new revision.');

            return self::FAILURE;
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
    ): void {
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
    }

    private function ensureProjection(int $recoveryId, MonthlyInterestProvisionalReader $reader): bool
    {
        try {
            $reader->dashboardProjection($recoveryId);

            return false;
        } catch (ProvisionalInterestSourceUnavailable) {
            $reader->validate($recoveryId);
            $reader->dashboardProjection($recoveryId);

            return true;
        }
    }

    /** @param array<string, mixed> $status */
    private function assertVerified(array $status): void
    {
        if (! $this->isVerified($status)) {
            throw new MonthlyInterestEvidenceUnverified;
        }
    }

    /** @param array<string, mixed> $status */
    private function isVerified(array $status): bool
    {
        return $status['status'] === 'completed'
            && data_get($status, 'evidence.verification.verified') === true;
    }

    private function consecutiveUnverified(CarbonImmutable $start, CarbonImmutable $end): int
    {
        $recent = DB::table('salesforce_interest_monthly_recoveries')
            ->where('functional_start_at', $start)->where('functional_end_at', $end)
            ->orderByDesc('revision')->limit(self::MAX_CONSECUTIVE_UNVERIFIED)
            ->get(['status', 'evidence']);
        $count = 0;
        foreach ($recent as $revision) {
            if ($revision->status !== 'completed'
                || data_get(json_decode((string) $revision->evidence, true), 'verification.verified') === true) {
                break;
            }
            $count++;
        }

        return $count;
    }
}
