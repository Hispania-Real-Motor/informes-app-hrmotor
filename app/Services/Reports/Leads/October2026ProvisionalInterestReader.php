<?php

namespace App\Services\Reports\Leads;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Read-only contract for the isolated October 2026 monthly journals.
 *
 * This deliberately does not implement, decorate, or replace the published
 * Interest reader. Its only consumer is the restricted provisional screen.
 */
final class October2026ProvisionalInterestReader
{
    private const START_UTC = '2026-09-30 22:00:00';

    private const END_UTC = '2026-10-31 23:00:00';

    private const RECOVERY_ID = 1;

    private const MATERIALIZATION_ID = 1;

    private const ACTIVITY_RUN_ID = 1;

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $recoveryId = self::RECOVERY_ID;
        $materializationId = self::MATERIALIZATION_ID;
        $activityRunId = self::ACTIVITY_RUN_ID;

        $recovery = DB::table('salesforce_interest_monthly_recoveries')->find($recoveryId);
        $materialization = DB::table('salesforce_interest_monthly_materializations')->find($materializationId);
        $activity = DB::table('salesforce_interest_monthly_activity_recoveries')->find($activityRunId);

        if ($recovery === null || $materialization === null || $activity === null
            || (int) $materialization->monthly_recovery_id !== $recoveryId
            || (int) $activity->monthly_recovery_id !== $recoveryId) {
            throw new ProvisionalInterestSourceUnavailable;
        }

        $this->assertCompleted($recovery);
        $this->assertCompleted($materialization);
        $this->assertCompleted($activity);
        $this->assertOctoberPeriod($recovery);

        $sourceManifests = $this->sourceManifests($recoveryId);
        $materializationEvidence = $this->json($materialization->evidence);
        $materializationSource = $this->json($materialization->source_evidence);
        $activityEvidence = $this->json($activity->evidence);
        $activitySource = $this->json($activity->source_evidence);

        $this->assertSourceSnapshot($materializationSource, $sourceManifests['materialization']);
        $this->assertSourceSnapshot($activitySource, $sourceManifests['activity']);
        $this->assertManifest($materializationEvidence, $this->materializationManifest($materializationId));
        $this->assertManifest($activityEvidence, $this->activityManifest($activityRunId));

        $quality = DB::table('salesforce_interest_monthly_materialized_rows')
            ->where('monthly_materialization_id', $materializationId)
            ->selectRaw('COUNT(*) as materialized')
            ->selectRaw('SUM(CASE WHEN content_matches_inventory = 1 THEN 1 ELSE 0 END) as content_matching')
            ->selectRaw('SUM(CASE WHEN content_matches_inventory = 1 AND is_deleted = 0 THEN 1 ELSE 0 END) as eligible_active_matching')
            ->selectRaw('SUM(CASE WHEN content_matches_inventory = 0 THEN 1 ELSE 0 END) as content_changed')
            ->selectRaw('SUM(CASE WHEN is_deleted = 1 THEN 1 ELSE 0 END) as soft_deleted')
            ->selectRaw('SUM(CASE WHEN canonical_person_salesforce_id IS NULL OR canonical_person_salesforce_id = ? THEN 1 ELSE 0 END) as canonical_identity_unavailable', [''])
            ->first();
        $missing = DB::table('salesforce_interest_monthly_recovery_rows as source')
            ->leftJoin('salesforce_interest_monthly_materialized_rows as materialized', function ($join) use ($materializationId): void {
                $join->on('materialized.salesforce_id', '=', 'source.salesforce_id')
                    ->where('materialized.monthly_materialization_id', '=', $materializationId);
            })
            ->where('source.monthly_recovery_id', $recoveryId)
            ->whereNull('materialized.id')
            ->count();

        $byDelegation = DB::table('salesforce_interest_monthly_materialized_rows')
            ->where('monthly_materialization_id', $materializationId)
            ->where('content_matches_inventory', true)
            ->where('is_deleted', false)
            ->selectRaw("COALESCE(NULLIF(TRIM(origin_delegation), ''), 'Sin clasificar') as delegation")
            ->selectRaw('COUNT(*) as interests')
            ->groupBy('delegation')
            ->orderByDesc('interests')
            ->orderBy('delegation')
            ->limit(100)
            ->get()
            ->map(fn (object $row): array => ['delegation' => $row->delegation, 'interests' => (int) $row->interests])
            ->all();

        return [
            'period' => ['label' => 'Octubre de 2026', 'start_utc' => self::START_UTC, 'end_utc' => self::END_UTC, 'timezone' => 'Europe/Madrid'],
            'coverage' => [
                'f2_inventory' => $sourceManifests['materialization']['rows'],
                'materialized' => (int) $quality->materialized,
                'matching_current_content' => (int) $quality->content_matching,
                'eligible_active_matching' => (int) $quality->eligible_active_matching,
                'missing_from_materialization' => $missing,
                'content_changed' => (int) $quality->content_changed,
                'soft_deleted_observed' => (int) $quality->soft_deleted,
                'canonical_identity_unavailable' => (int) $quality->canonical_identity_unavailable,
            ],
            'by_delegation' => $byDelegation,
            'verification' => [
                'f2_verified' => (bool) data_get($this->json($recovery->evidence), 'verification.verified', false),
                'materialization_verified' => (bool) data_get($materializationEvidence, 'verification.verified', false),
                'f5_verified' => (bool) data_get($activityEvidence, 'verification.verified', false),
                'f2_unchanged_since_journals' => true,
                'historical_certification' => 'unavailable',
            ],
            'availability' => [
                'leads_interest_overview' => 'available_with_coverage_limits',
                'direction_kpis_and_comparisons' => 'unavailable',
                'reservations_sales_and_commercial_performance' => 'unavailable',
                'campaigns_calls_and_seo' => 'unavailable',
            ],
        ];
    }

    private function assertCompleted(object $run): void
    {
        if ($run->status !== 'completed' || $run->phase !== 'completed') {
            throw new ProvisionalInterestSourceUnavailable;
        }
    }

    private function assertOctoberPeriod(object $recovery): void
    {
        try {
            $startMatches = CarbonImmutable::parse($recovery->functional_start_at, 'UTC')->equalTo(CarbonImmutable::parse(self::START_UTC, 'UTC'));
            $endMatches = CarbonImmutable::parse($recovery->functional_end_at, 'UTC')->equalTo(CarbonImmutable::parse(self::END_UTC, 'UTC'));
        } catch (\Throwable) {
            throw new ProvisionalInterestSourceUnavailable;
        }

        if ($recovery->timezone !== 'Europe/Madrid' || ! $startMatches || ! $endMatches) {
            throw new ProvisionalInterestSourceUnavailable;
        }
    }

    /**
     * Reproduces the distinct, persisted source-evidence contracts of F2M and
     * F5 in one ordered scan of their common F2 journal.
     *
     * @return array{materialization:array{rows:int,sha256:string},activity:array{rows:int,sha256:string}}
     */
    private function sourceManifests(int $recoveryId): array
    {
        $materializationHash = hash_init('sha256');
        $activityHash = hash_init('sha256');
        $rows = 0;
        DB::table('salesforce_interest_monthly_recovery_rows')->where('monthly_recovery_id', $recoveryId)->orderBy('salesforce_id')->chunk(500, function ($items) use ($materializationHash, $activityHash, &$rows): void {
            foreach ($items as $item) {
                hash_update($materializationHash, $item->salesforce_id.'|'.$item->content_hash.'|'.(int) $item->is_deleted."\n");
                hash_update($activityHash, $item->salesforce_id.':'.(int) $item->is_deleted."\n");
                $rows++;
            }
        });

        return [
            'materialization' => ['rows' => $rows, 'sha256' => hash_final($materializationHash)],
            'activity' => ['rows' => $rows, 'sha256' => hash_final($activityHash)],
        ];
    }

    /** @return array{rows:int,sha256:string} */
    private function materializationManifest(int $materializationId): array
    {
        return $this->manifest('salesforce_interest_monthly_materialized_rows', 'monthly_materialization_id', $materializationId, ['salesforce_id', 'content_hash']);
    }

    /** @return array{rows:int,sha256:string} */
    private function activityManifest(int $activityRunId): array
    {
        return $this->manifest('salesforce_interest_monthly_activity_rows', 'monthly_activity_recovery_id', $activityRunId, ['activity_kind', 'activity_salesforce_id', 'content_hash']);
    }

    /** @param list<string> $orderBy @return array{rows:int,sha256:string} */
    private function manifest(string $table, string $foreignKey, int $id, array $orderBy): array
    {
        $hash = hash_init('sha256');
        $rows = 0;
        $query = DB::table($table)->where($foreignKey, $id);
        foreach ($orderBy as $column) {
            $query->orderBy($column);
        }
        $query->chunk(500, function ($items) use ($hash, &$rows, $table): void {
            foreach ($items as $item) {
                hash_update($hash, $table === 'salesforce_interest_monthly_materialized_rows'
                    ? $item->salesforce_id.'|'.$item->content_hash."\n"
                    : $item->content_hash."\n");
                $rows++;
            }
        });

        return ['rows' => $rows, 'sha256' => hash_final($hash)];
    }

    /** @param array<string, mixed> $evidence @param array{rows:int,sha256:string} $actual */
    private function assertManifest(array $evidence, array $actual): void
    {
        $expected = data_get($evidence, 'manifest');
        if (! is_array($expected) || (int) ($expected['rows'] ?? -1) !== $actual['rows']
            || ! is_string($expected['sha256'] ?? null) || ! hash_equals($expected['sha256'], $actual['sha256'])) {
            throw new ProvisionalInterestSourceUnavailable;
        }
    }

    /** @param array<string, mixed> $evidence @param array{rows:int,sha256:string} $actual */
    private function assertSourceSnapshot(array $evidence, array $actual): void
    {
        if ((int) ($evidence['source_rows'] ?? -1) !== $actual['rows']
            || ! is_string($evidence['source_sha256'] ?? null) || ! hash_equals($evidence['source_sha256'], $actual['sha256'])) {
            throw new ProvisionalInterestSourceUnavailable;
        }
    }

    /** @return array<string, mixed> */
    private function json(?string $value): array
    {
        try {
            $decoded = $value === null ? [] : json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ProvisionalInterestSourceUnavailable;
        }

        if (! is_array($decoded)) {
            throw new ProvisionalInterestSourceUnavailable;
        }

        return $decoded;
    }
}
