<?php

namespace App\Services\Salesforce;

use App\Services\Reports\Leads\October2026ProvisionalEvidenceInvalidator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Stages verified monthly references only. No generation or publication transition is possible here. */
final class InterestMonthlyConsolidationService
{
    private const BATCH_SIZE = 500;

    private const LEASE_SECONDS = 900;

    public function __construct(
        private readonly InterestMonthlyMaterializationService $f2m,
        private readonly MonthlyInterestActivityRecoveryService $f5,
    ) {}

    /** @param list<int> $materializationIds */
    public function prepare(array $materializationIds, string $reason): int
    {
        if ($materializationIds === [] || count($materializationIds) > 1200
            || count($materializationIds) !== count(array_unique($materializationIds))
            || mb_strlen(trim($reason)) < 10 || mb_strlen(trim($reason)) > 500) {
            throw new RuntimeException('Monthly consolidation requires distinct sources and an audit reason.');
        }

        $sources = [];
        foreach ($materializationIds as $materializationId) {
            if (! is_int($materializationId) || $materializationId < 1) {
                throw new RuntimeException('Monthly consolidation source identifier is invalid.');
            }
            $source = $this->source($materializationId);
            $key = $source['functional_start_at'].'|'.$source['functional_end_at'];
            if (isset($sources[$key])) {
                throw new RuntimeException('Monthly consolidation cannot include two revisions of one period.');
            }
            $sources[$key] = $source;
        }
        ksort($sources);

        return DB::transaction(function () use ($sources, $reason): int {
            $id = DB::table('salesforce_interest_monthly_consolidations')->insertGetId([
                'status' => 'prepared', 'reason_hash' => hash('sha256', trim($reason)),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($sources as $source) {
                DB::table('salesforce_interest_monthly_consolidation_sources')->insert([
                    'consolidation_id' => $id, ...$source, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $id;
        });
    }

    /** @return array<string,mixed> */
    public function run(int $id, string $reason, bool $resume = false): array
    {
        $lease = $this->claim($id, $reason, $resume);
        try {
            $sources = DB::table('salesforce_interest_monthly_consolidation_sources')
                ->where('consolidation_id', $id)->orderBy('functional_start_at')->get(['id']);
            foreach ($sources as $source) {
                while ($this->stagePage($id, (int) $source->id, $lease)) {
                    // Every page and checkpoint commit in one bounded transaction.
                }
            }
            $this->finish($id, $lease);

            return $this->status($id);
        } catch (Throwable $error) {
            $code = $error instanceof MonthlyInterestRecoveryLeaseLost ? 'lease_lost'
                : ($error->getMessage() === 'Monthly consolidation identity conflict.' ? 'identity_conflict' : 'source_or_database_failure');
            DB::table('salesforce_interest_monthly_consolidations')
                ->where('id', $id)->where('lease_token', $lease)->where('status', 'running')
                ->update(['status' => 'failed', 'error_code' => $code, 'lease_token' => null, 'updated_at' => now()]);
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    public function status(int $id): array
    {
        $run = DB::table('salesforce_interest_monthly_consolidations')->find($id)
            ?? throw new RuntimeException('Monthly consolidation does not exist.');
        $sources = DB::table('salesforce_interest_monthly_consolidation_sources')
            ->where('consolidation_id', $id)->orderBy('functional_start_at')->get([
                'monthly_recovery_id', 'monthly_materialization_id', 'monthly_activity_recovery_id',
                'functional_start_at', 'functional_end_at', 'effective_cutoff_at', 'status', 'f2m_rows',
            ]);

        return [
            'consolidation_id' => $id, 'status' => $run->status,
            'pages_completed' => (int) $run->pages_completed, 'rows_staged' => (int) $run->rows_staged,
            'sources' => $sources->map(fn (object $source): array => (array) $source)->all(),
            'manifest' => $run->manifest === null ? null : json_decode($run->manifest, true, 64, JSON_THROW_ON_ERROR),
            'diagnostic' => $run->error_code, 'published' => false, 'historical_certification' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function source(int $materializationId): array
    {
        $materialization = DB::table('salesforce_interest_monthly_materializations')->find($materializationId);
        $recovery = $materialization === null ? null : DB::table('salesforce_interest_monthly_recoveries')->find($materialization->monthly_recovery_id);
        $activity = $recovery === null ? null : DB::table('salesforce_interest_monthly_activity_recoveries')
            ->where('monthly_recovery_id', $recovery->id)->where('status', 'completed')->first();
        if ($recovery === null || $materialization->status !== 'completed' || $activity === null
            || $recovery->status !== 'completed' || $materialization->phase !== 'completed' || $activity->phase !== 'completed') {
            throw new RuntimeException('Monthly consolidation source is incomplete.');
        }
        foreach ([$recovery, $materialization, $activity] as $journal) {
            $evidence = json_decode((string) $journal->evidence, true, 64, JSON_THROW_ON_ERROR);
            if (data_get($evidence, 'verification.verified') !== true) {
                throw new RuntimeException('Monthly consolidation requires verified F2, F2M and F5.');
            }
        }
        $materializationEvidence = json_decode((string) $materialization->evidence, true, 64, JSON_THROW_ON_ERROR);
        $activityEvidence = json_decode((string) $activity->evidence, true, 64, JSON_THROW_ON_ERROR);
        $f2mManifest = $this->f2m->manifest($materializationId);
        $f5Manifest = $this->f5->manifest((int) $activity->id);
        if ($f2mManifest !== ($materializationEvidence['manifest'] ?? null)
            || $f5Manifest !== ($activityEvidence['manifest'] ?? null)) {
            throw new RuntimeException('Monthly consolidation source manifest has changed.');
        }
        $latest = DB::table('salesforce_interest_monthly_recoveries')
            ->where('functional_start_at', $recovery->functional_start_at)
            ->where('functional_end_at', $recovery->functional_end_at)->orderByDesc('revision')->value('id');
        if ((int) $latest !== (int) $recovery->id) {
            throw new RuntimeException('Monthly consolidation source revision is obsolete.');
        }
        $scope = October2026ProvisionalEvidenceInvalidator::monthlyScope((int) $recovery->id);
        DB::table('salesforce_interest_monthly_integrity_revisions')->insertOrIgnore([
            'scope' => $scope, 'revision' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $revision = DB::table('salesforce_interest_monthly_integrity_revisions')->where('scope', $scope)->value('revision');
        if ($revision === null) {
            throw new RuntimeException('Monthly consolidation source has no integrity revision.');
        }

        return [
            'monthly_recovery_id' => (int) $recovery->id,
            'monthly_materialization_id' => $materializationId,
            'monthly_activity_recovery_id' => (int) $activity->id,
            'functional_start_at' => $recovery->functional_start_at,
            'functional_end_at' => $recovery->functional_end_at,
            'effective_cutoff_at' => $recovery->effective_cutoff_at,
            'integrity_revision' => (int) $revision,
            'f2m_sha256' => $f2mManifest['sha256'],
            'f5_sha256' => $f5Manifest['sha256'],
            'f2m_rows' => $f2mManifest['rows'],
        ];
    }

    private function stagePage(int $id, int $sourceId, string $lease): bool
    {
        return DB::transaction(function () use ($id, $sourceId, $lease): bool {
            $run = $this->owned($id, $lease);
            $source = DB::table('salesforce_interest_monthly_consolidation_sources')
                ->where('id', $sourceId)->where('consolidation_id', $id)->lockForUpdate()->first();
            if ($source === null) {
                throw new RuntimeException('Monthly consolidation source is missing.');
            }
            if ($source->status === 'completed') {
                return false;
            }
            $this->assertRevision($source);
            $rows = DB::table('salesforce_interest_monthly_materialized_rows')
                ->where('monthly_materialization_id', $source->monthly_materialization_id)
                ->where('id', '>', $source->checkpoint_row_id)->orderBy('id')->limit(self::BATCH_SIZE)->get([
                    'id', 'salesforce_id', 'content_hash', 'source_content_hash', 'content_matches_inventory',
                    'is_deleted', 'functional_created_at', 'migration_origin_lead_id',
                ]);
            if ($rows->isEmpty()) {
                DB::table('salesforce_interest_monthly_consolidation_sources')->where('id', $sourceId)
                    ->update(['status' => 'completed', 'updated_at' => now()]);

                return false;
            }
            $members = [];
            $ids = [];
            $origins = [];
            foreach ($rows as $row) {
                $date = CarbonImmutable::parse($row->functional_created_at, 'UTC');
                if (! $row->content_matches_inventory || ! hash_equals($row->content_hash, $row->source_content_hash)
                    || $date->lt(CarbonImmutable::parse($source->functional_start_at, 'UTC'))
                    || $date->gte(CarbonImmutable::parse($source->functional_end_at, 'UTC'))
                    || isset($ids[$row->salesforce_id])
                    || ($row->migration_origin_lead_id !== null && isset($origins[$row->migration_origin_lead_id]))) {
                    throw new RuntimeException('Monthly consolidation identity conflict.');
                }
                $ids[$row->salesforce_id] = true;
                if ($row->migration_origin_lead_id !== null) {
                    $origins[$row->migration_origin_lead_id] = true;
                }
                $members[] = [
                    'consolidation_id' => $id, 'source_id' => $sourceId, 'materialized_row_id' => $row->id,
                    'salesforce_id' => $row->salesforce_id, 'content_hash' => $row->content_hash,
                    'is_deleted' => $row->is_deleted, 'migration_origin_lead_id' => $row->migration_origin_lead_id,
                ];
            }
            $existingIds = DB::table('salesforce_interest_monthly_consolidation_members')
                ->where('consolidation_id', $id)->whereIn('salesforce_id', array_keys($ids))->exists();
            $existingOrigins = $origins !== [] && DB::table('salesforce_interest_monthly_consolidation_members')
                ->where('consolidation_id', $id)->whereIn('migration_origin_lead_id', array_keys($origins))->exists();
            if ($existingIds || $existingOrigins) {
                throw new RuntimeException('Monthly consolidation identity conflict.');
            }
            DB::table('salesforce_interest_monthly_consolidation_members')->insert($members);
            DB::table('salesforce_interest_monthly_consolidation_sources')->where('id', $sourceId)
                ->update(['checkpoint_row_id' => $rows->last()->id, 'status' => 'running', 'updated_at' => now()]);
            DB::table('salesforce_interest_monthly_consolidations')->where('id', $id)
                ->update(['pages_completed' => $run->pages_completed + 1, 'rows_staged' => $run->rows_staged + $rows->count(),
                    'heartbeat_at' => now('UTC'), 'updated_at' => now()]);

            return true;
        });
    }

    private function finish(int $id, string $lease): void
    {
        $sources = DB::table('salesforce_interest_monthly_consolidation_sources')
            ->where('consolidation_id', $id)->orderBy('functional_start_at')->get();
        foreach ($sources as $source) {
            $this->renew($id, $lease);
            if ($source->status !== 'completed') {
                throw new RuntimeException('Monthly consolidation has an incomplete source.');
            }
            $this->assertRevision($source);
            $this->assertSourceManifest($source);
        }
        $hash = hash_init('sha256');
        $count = 0;
        $pages = 0;
        DB::table('salesforce_interest_monthly_consolidation_members')->where('consolidation_id', $id)
            ->orderBy('salesforce_id')->chunkById(500, function ($items) use ($hash, &$count, &$pages, $id, $lease): void {
                if (++$pages % 20 === 0) {
                    $this->renew($id, $lease);
                }
                foreach ($items as $item) {
                    hash_update($hash, $item->salesforce_id.'|'.$item->content_hash.'|'.(int) $item->is_deleted."\n");
                    $count++;
                }
            }, 'salesforce_id');
        DB::transaction(function () use ($id, $lease, $sources, $hash, $count): void {
            $run = $this->owned($id, $lease);
            foreach ($sources as $source) {
                $this->assertRevision($source);
            }
            if ((int) $run->rows_staged !== $count) {
                throw new RuntimeException('Monthly consolidation member count changed.');
            }
            $manifest = [
                'rows' => $count, 'sha256' => hash_final($hash),
                'sources' => $sources->map(fn (object $source): array => [
                    'monthly_recovery_id' => (int) $source->monthly_recovery_id,
                    'monthly_materialization_id' => (int) $source->monthly_materialization_id,
                    'monthly_activity_recovery_id' => (int) $source->monthly_activity_recovery_id,
                    'functional_start_at' => $source->functional_start_at,
                    'functional_end_at' => $source->functional_end_at,
                    'effective_cutoff_at' => $source->effective_cutoff_at,
                    'f2m_sha256' => $source->f2m_sha256, 'f5_sha256' => $source->f5_sha256,
                ])->all(),
                'global_coverage_verified' => false, 'historical_certification' => false,
            ];
            DB::table('salesforce_interest_monthly_consolidations')->where('id', $id)
                ->update(['status' => 'completed', 'lease_token' => null,
                    'manifest' => json_encode($manifest, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        });
    }

    private function assertSourceManifest(object $source): void
    {
        $f2m = $this->f2m->manifest((int) $source->monthly_materialization_id);
        $f5 = $this->f5->manifest((int) $source->monthly_activity_recovery_id);
        if ($f2m['rows'] !== (int) $source->f2m_rows
            || ! hash_equals($source->f2m_sha256, $f2m['sha256'])
            || ! hash_equals($source->f5_sha256, $f5['sha256'])) {
            throw new RuntimeException('Monthly consolidation source manifest changed.');
        }
    }

    private function assertRevision(object $source): void
    {
        $scope = October2026ProvisionalEvidenceInvalidator::monthlyScope((int) $source->monthly_recovery_id);
        $revision = DB::table('salesforce_interest_monthly_integrity_revisions')->where('scope', $scope)->value('revision');
        $latest = DB::table('salesforce_interest_monthly_recoveries')
            ->where('functional_start_at', $source->functional_start_at)
            ->where('functional_end_at', $source->functional_end_at)->orderByDesc('revision')->value('id');
        if ($revision === null || (int) $revision !== (int) $source->integrity_revision
            || (int) $latest !== (int) $source->monthly_recovery_id) {
            throw new RuntimeException('Monthly consolidation source revision changed.');
        }
    }

    private function claim(int $id, string $reason, bool $resume): string
    {
        return DB::transaction(function () use ($id, $reason, $resume): string {
            $run = DB::table('salesforce_interest_monthly_consolidations')->where('id', $id)->lockForUpdate()->first();
            if ($run === null || ! hash_equals($run->reason_hash, hash('sha256', trim($reason)))
                || ($run->status === 'running' && $run->heartbeat_at !== null
                    && CarbonImmutable::parse($run->heartbeat_at, 'UTC')->gt(now('UTC')->subSeconds(self::LEASE_SECONDS)))
                || (! $resume && $run->status !== 'prepared')
                || ($resume && ! in_array($run->status, ['prepared', 'running', 'failed'], true))) {
                throw new RuntimeException('Monthly consolidation is not safely resumable.');
            }
            $lease = (string) Str::uuid();
            DB::table('salesforce_interest_monthly_consolidations')->where('id', $id)
                ->update(['status' => 'running', 'lease_token' => $lease, 'heartbeat_at' => now('UTC'),
                    'error_code' => null, 'updated_at' => now()]);

            return $lease;
        });
    }

    private function owned(int $id, string $lease): object
    {
        return DB::table('salesforce_interest_monthly_consolidations')->where('id', $id)
            ->where('lease_token', $lease)->where('status', 'running')->lockForUpdate()->first()
            ?? throw new MonthlyInterestRecoveryLeaseLost;
    }

    private function renew(int $id, string $lease): void
    {
        DB::transaction(function () use ($id, $lease): void {
            $this->owned($id, $lease);
            DB::table('salesforce_interest_monthly_consolidations')->where('id', $id)
                ->where('lease_token', $lease)->where('status', 'running')
                ->update(['heartbeat_at' => now('UTC'), 'updated_at' => now()]);
        });
    }
}
