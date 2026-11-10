<?php

namespace App\Services\Reports\Leads;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

    /** @param null|\Closure():void $beforeValidationPublication @param null|\Closure():void $beforeOverviewFinalCheck */
    public function __construct(
        private readonly ?\Closure $beforeValidationPublication = null,
        private readonly ?\Closure $beforeOverviewFinalCheck = null,
    ) {}

    /** @return array<string, mixed> */
    public function overview(): array
    {
        try {
            $revision = $this->revision();
            $validation = DB::table('salesforce_interest_october_provisional_validations')->where('scope', 'october_2026_provisional')->first();
            if ($validation === null || (int) $validation->monthly_recovery_id !== self::RECOVERY_ID
                || (int) $validation->monthly_materialization_id !== self::MATERIALIZATION_ID
                || (int) $validation->monthly_activity_recovery_id !== self::ACTIVITY_RUN_ID
                || (int) $validation->integrity_revision !== $revision) {
                throw new ProvisionalInterestSourceUnavailable;
            }

            $overview = $this->json($validation->overview);
            if ($this->beforeOverviewFinalCheck !== null) {
                ($this->beforeOverviewFinalCheck)();
            }
            if ($this->revision() !== $revision || ! isset($overview['coverage'], $overview['by_delegation'])) {
                throw new ProvisionalInterestSourceUnavailable;
            }

            return $overview;
        } catch (QueryException) {
            throw new ProvisionalInterestSourceUnavailable;
        }
    }

    /**
     * Returns the only projection that HTTP is allowed to read.  The
     * projection contains categorical aggregates inputs, never an Interest
     * identifier or Salesforce payload, and is bound to the validated
     * integrity revision.
     *
     * @return array{overview:array<string,mixed>,projection_key:string,integrity_revision:int}
     */
    public function dashboardProjection(): array
    {
        $overview = $this->overview();
        $revision = $this->revision();

        try {
            $validation = DB::table('salesforce_interest_october_provisional_validations')
                ->where('scope', 'october_2026_provisional')
                ->first(['integrity_revision', 'projection_key']);
            $projectionKey = is_object($validation) ? $validation->projection_key : null;
            if (! is_object($validation) || (int) $validation->integrity_revision !== $revision
                || ! is_string($projectionKey) || ! Str::isUuid($projectionKey)
                || ! DB::table('salesforce_interest_october_provisional_projection_rows')
                    ->where('scope', 'october_2026_provisional')
                    ->where('integrity_revision', $revision)
                    ->where('projection_key', $projectionKey)
                    ->exists()) {
                throw new ProvisionalInterestSourceUnavailable;
            }
        } catch (QueryException) {
            throw new ProvisionalInterestSourceUnavailable;
        }

        if ($this->revision() !== $revision) {
            throw new ProvisionalInterestSourceUnavailable;
        }

        return ['overview' => $overview, 'projection_key' => $projectionKey, 'integrity_revision' => $revision];
    }

    public function assertDashboardProjectionCurrent(int $revision, string $projectionKey): void
    {
        try {
            $matches = DB::table('salesforce_interest_october_provisional_validations')
                ->where('scope', 'october_2026_provisional')
                ->where('integrity_revision', $revision)
                ->where('projection_key', $projectionKey)
                ->exists();
        } catch (QueryException) {
            throw new ProvisionalInterestSourceUnavailable;
        }

        if (! $matches || $this->revision() !== $revision) {
            throw new ProvisionalInterestSourceUnavailable;
        }
    }

    /** Performs the explicit, bounded administrative validation; never called by HTTP. */
    public function validate(): array
    {
        $totalStartedAt = hrtime(true);
        $metrics = [];
        $revision = $this->revision();
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

        $phaseStartedAt = hrtime(true);
        $sourceManifests = $this->sourceManifests($recoveryId);
        $metrics['f2_source_hash_ns'] = hrtime(true) - $phaseStartedAt;
        $materializationEvidence = $this->json($materialization->evidence);
        $materializationSource = $this->json($materialization->source_evidence);
        $activityEvidence = $this->json($activity->evidence);
        $activitySource = $this->json($activity->source_evidence);

        $this->assertSourceSnapshot($materializationSource, $sourceManifests['materialization']);
        $this->assertSourceSnapshot($activitySource, $sourceManifests['activity']);
        $phaseStartedAt = hrtime(true);
        $materializationManifest = $this->materializationManifest($materializationId);
        $metrics['f2m_manifest_hash_ns'] = hrtime(true) - $phaseStartedAt;
        $phaseStartedAt = hrtime(true);
        $activityManifest = $this->activityManifest($activityRunId);
        $metrics['f5_manifest_hash_ns'] = hrtime(true) - $phaseStartedAt;
        $this->assertManifest($materializationEvidence, $materializationManifest);
        $this->assertManifest($activityEvidence, $activityManifest);

        $phaseStartedAt = hrtime(true);
        $quality = DB::table('salesforce_interest_monthly_materialized_rows')
            ->where('monthly_materialization_id', $materializationId)
            ->selectRaw('COUNT(*) as materialized')
            ->selectRaw('SUM(CASE WHEN content_matches_inventory = 1 THEN 1 ELSE 0 END) as content_matching')
            ->selectRaw('SUM(CASE WHEN content_matches_inventory = 1 AND is_deleted = 0 THEN 1 ELSE 0 END) as eligible_active_matching')
            ->selectRaw('SUM(CASE WHEN content_matches_inventory = 0 THEN 1 ELSE 0 END) as content_changed')
            ->selectRaw('SUM(CASE WHEN is_deleted = 1 THEN 1 ELSE 0 END) as soft_deleted')
            ->selectRaw('SUM(CASE WHEN canonical_person_salesforce_id IS NULL OR canonical_person_salesforce_id = ? THEN 1 ELSE 0 END) as canonical_identity_unavailable', [''])
            ->first();
        $metrics['coverage_aggregate_ns'] = hrtime(true) - $phaseStartedAt;
        $phaseStartedAt = hrtime(true);
        $missing = DB::table('salesforce_interest_monthly_recovery_rows as source')
            ->leftJoin('salesforce_interest_monthly_materialized_rows as materialized', function ($join) use ($materializationId): void {
                $join->on('materialized.salesforce_id', '=', 'source.salesforce_id')
                    ->where('materialized.monthly_materialization_id', '=', $materializationId);
            })
            ->where('source.monthly_recovery_id', $recoveryId)
            ->whereNull('materialized.id')
            ->count();
        $metrics['missing_aggregate_ns'] = hrtime(true) - $phaseStartedAt;

        $phaseStartedAt = hrtime(true);
        $classification = $this->classification($materializationId);
        $metrics['delegation_classification_ns'] = hrtime(true) - $phaseStartedAt;

        $this->assertClassificationReconciles($classification, (int) $quality->eligible_active_matching);
        $phaseStartedAt = hrtime(true);
        $functionalKpis = $this->functionalKpis($materializationId);
        $metrics['functional_kpis_ns'] = hrtime(true) - $phaseStartedAt;
        $this->assertFunctionalKpisReconcile($functionalKpis, (int) $quality->eligible_active_matching);

        $projectionKey = (string) Str::uuid();
        $phaseStartedAt = hrtime(true);
        $this->buildDashboardProjection($materializationId, $revision, $projectionKey);
        $metrics['dashboard_projection_ns'] = hrtime(true) - $phaseStartedAt;
        $projected = DB::table('salesforce_interest_october_provisional_projection_rows')
            ->where('projection_key', $projectionKey)->count();
        $withoutLabel = DB::table('salesforce_interest_october_provisional_projection_rows as row')
            ->leftJoin('salesforce_interest_october_provisional_owners as owner', function ($join): void {
                $join->on('owner.projection_key', '=', 'row.projection_key')
                    ->on('owner.owner_key', '=', 'row.owner_key');
            })
            ->where('row.projection_key', $projectionKey)->whereNull('owner.id')->exists();
        if ($projected !== (int) $quality->eligible_active_matching || $withoutLabel) {
            throw new ProvisionalInterestSourceUnavailable;
        }

        $overview = [
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
            'by_delegation' => $classification['by_delegation'],
            'classification' => $classification,
            'functional_kpis' => $functionalKpis,
            'projection_contract' => 'observed_owners_v1',
            'owner_assignment' => ['source' => 'f2m_owner_observed', 'delegation_source' => 'commercial_delegation_snapshots', 'delegation_cutoff_utc' => self::END_UTC, 'historical_owner_certified' => false],
            'verification' => [
                'f2_verified' => (bool) data_get($this->json($recovery->evidence), 'verification.verified', false),
                'materialization_verified' => (bool) data_get($materializationEvidence, 'verification.verified', false),
                'f5_verified' => (bool) data_get($activityEvidence, 'verification.verified', false),
                'f2_unchanged_since_journals' => true,
                'historical_certification' => 'unavailable',
            ],
            'availability' => [
                'leads_interest_overview' => 'available_with_coverage_limits',
                'provisional_interest_kpis' => 'available_with_coverage_limits',
                'observed_owners' => 'available_observed_not_historically_certified',
                'commercials_eligible' => 'unavailable_missing_historical_salesforce_user_snapshot',
                'commercial_delegations' => 'unavailable_missing_historical_salesforce_user_snapshot',
                'activity_kpis' => 'unavailable_missing_relationship_status_and_historical_f5_certification',
                'direction_kpis_and_comparisons' => 'unavailable',
                'reservations_sales_and_commercial_performance' => 'unavailable',
                'campaigns_calls_and_seo' => 'unavailable',
            ],
        ];

        if ($this->revision() !== $revision) {
            throw new ProvisionalInterestSourceUnavailable;
        }
        if ($this->beforeValidationPublication !== null) {
            ($this->beforeValidationPublication)();
        }
        $metrics['total_ns'] = hrtime(true) - $totalStartedAt;
        DB::transaction(function () use ($revision, $projectionKey, $sourceManifests, $materializationManifest, $activityManifest, $overview, $metrics): void {
            $current = DB::table('salesforce_interest_monthly_integrity_revisions')->where('scope', 'october_2026_provisional')->lockForUpdate()->value('revision');
            if ((int) $current !== $revision) {
                throw new ProvisionalInterestSourceUnavailable;
            }
            DB::table('salesforce_interest_october_provisional_validations')->updateOrInsert(
                ['scope' => 'october_2026_provisional'],
                ['monthly_recovery_id' => self::RECOVERY_ID, 'monthly_materialization_id' => self::MATERIALIZATION_ID, 'monthly_activity_recovery_id' => self::ACTIVITY_RUN_ID,
                    'integrity_revision' => $revision, 'projection_key' => $projectionKey, 'evidence' => json_encode(['algorithms' => ['f2m_source' => 'salesforce_id|content_hash|is_deleted\\n', 'f5_source' => 'salesforce_id:is_deleted\\n'], 'f2m_source' => $sourceManifests['materialization'], 'f5_source' => $sourceManifests['activity'], 'f2m_manifest' => $materializationManifest, 'f5_manifest' => $activityManifest], JSON_THROW_ON_ERROR),
                    'overview' => json_encode($overview, JSON_THROW_ON_ERROR), 'metrics' => json_encode($metrics, JSON_THROW_ON_ERROR), 'validated_at' => now('UTC'), 'updated_at' => now(), 'created_at' => now()]
            );
            DB::table('salesforce_interest_october_provisional_projection_rows')
                ->where('scope', 'october_2026_provisional')
                ->where('projection_key', '!=', $projectionKey)
                ->delete();
            DB::table('salesforce_interest_october_provisional_owners')
                ->where('projection_key', '!=', $projectionKey)
                ->delete();
        });

        return $overview;
    }

    /** @return array<string, int> */
    public function validationMetrics(): array
    {
        try {
            $metrics = $this->json(DB::table('salesforce_interest_october_provisional_validations')
                ->where('scope', 'october_2026_provisional')
                ->value('metrics'));
        } catch (QueryException) {
            throw new ProvisionalInterestSourceUnavailable;
        }

        foreach ($metrics as $key => $value) {
            if (! is_string($key) || ! is_int($value)) {
                throw new ProvisionalInterestSourceUnavailable;
            }
        }

        return $metrics;
    }

    private function revision(): int
    {
        try {
            $revision = DB::table('salesforce_interest_monthly_integrity_revisions')->where('scope', 'october_2026_provisional')->value('revision');
        } catch (\Throwable) {
            throw new ProvisionalInterestSourceUnavailable;
        }
        if (! is_int($revision) && ! ctype_digit((string) $revision)) {
            throw new ProvisionalInterestSourceUnavailable;
        }

        return (int) $revision;
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
        foreach (DB::table('salesforce_interest_monthly_recovery_rows')->where('monthly_recovery_id', $recoveryId)->orderBy('salesforce_id')->cursor() as $item) {
            hash_update($materializationHash, $item->salesforce_id.'|'.$item->content_hash.'|'.(int) $item->is_deleted."\n");
            hash_update($activityHash, $item->salesforce_id.':'.(int) $item->is_deleted."\n");
            $rows++;
        }

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

    /**
     * Applies the same normalizer that the official Interest dashboard uses to
     * the fixed, valid F2M universe. The input value is intentionally never
     * persisted in this public-administration snapshot: unknown values are
     * represented by a stable fingerprint and frequency so the audit remains
     * actionable without exposing a possible free-text personal value.
     *
     * @return array{total_included:int,classified:int,unclassified:int,raw_empty:int,raw_unmapped:int,raw_literal_general:int,by_delegation:list<array{delegation:string,interests:int}>,by_group:list<array{group:string,interests:int}>,by_zone:list<array{zone:string,interests:int}>,unrecognized_value_frequencies:list<array{fingerprint:string,interests:int}>,data_quality_review:array{required:int,empty_origin:int,unmapped_origin:int,literal_general:int}}
     */
    private function classification(int $materializationId): array
    {
        $normalizer = app(LeadDelegationNormalizer::class);
        $total = 0;
        $classified = 0;
        $empty = 0;
        $unmapped = 0;
        $literalGeneral = 0;
        $delegations = [];
        $groups = [];
        $zones = [];
        $unknownValues = [];

        foreach (DB::table('salesforce_interest_monthly_materialized_rows')
            ->where('monthly_materialization_id', $materializationId)
            ->where('content_matches_inventory', true)
            ->where('is_deleted', false)
            ->orderBy('salesforce_id')
            ->cursor() as $row) {
            $normalized = $normalizer->normalize($row->origin_delegation);
            $total++;
            $delegations[$normalized['delegation']] = ($delegations[$normalized['delegation']] ?? 0) + 1;
            $groups[$normalized['group']] = ($groups[$normalized['group']] ?? 0) + 1;
            $zones[$normalized['zone']] = ($zones[$normalized['zone']] ?? 0) + 1;

            if ($normalized['is_classified']) {
                $classified++;

                continue;
            }

            if ($normalized['raw'] === null) {
                $empty++;

                continue;
            }

            $unmapped++;
            if (mb_strtolower((string) $normalized['raw']) === 'general') {
                $literalGeneral++;
            }
            $fingerprint = hash('sha256', (string) $normalized['raw']);
            $unknownValues[$fingerprint] = ($unknownValues[$fingerprint] ?? 0) + 1;
        }

        return [
            'total_included' => $total,
            'classified' => $classified,
            'unclassified' => $empty + $unmapped,
            'raw_empty' => $empty,
            'raw_unmapped' => $unmapped,
            'raw_literal_general' => $literalGeneral,
            'by_delegation' => $this->labelCounts($delegations, 'delegation', $normalizer),
            'by_group' => $this->labelCounts($groups, 'group', $normalizer),
            'by_zone' => $this->labelCounts($zones, 'zone', $normalizer),
            'unrecognized_value_frequencies' => collect($unknownValues)
                ->map(fn (int $interests, string $fingerprint): array => ['fingerprint' => $fingerprint, 'interests' => $interests])
                ->sortByDesc('interests')->values()->all(),
            'data_quality_review' => [
                'required' => $empty + $unmapped,
                'empty_origin' => $empty,
                'unmapped_origin' => $unmapped,
                'literal_general' => $literalGeneral,
            ],
        ];
    }

    /**
     * Calculates only the Interest dimensions whose value is present in the
     * valid F2M row. Commercial attribution and activity KPIs deliberately
     * stay unavailable: their official formula depends on historical user and
     * relationship evidence that this monthly snapshot does not retain.
     *
     * @return array{total:int,statuses:list<array{status:string,interests:int}>,types:list<array{type:string,interests:int}>,sources:list<array{source:string,interests:int}>,media:list<array{medium:string,interests:int}>,channels:list<array{channel:string,interests:int}>}
     */
    private function functionalKpis(int $materializationId): array
    {
        $types = [];
        $statuses = [];
        $sources = [];
        $media = [];
        $channels = [];
        $total = 0;
        $recordTypes = app(LeadRecordTypeNormalizer::class);

        foreach (DB::table('salesforce_interest_monthly_materialized_rows')
            ->where('monthly_materialization_id', $materializationId)
            ->where('content_matches_inventory', true)
            ->where('is_deleted', false)
            ->orderBy('salesforce_id')
            ->cursor() as $row) {
            $total++;
            $status = $this->cleanLabel($row->status);
            $source = $this->cleanLabel($row->source);
            $medium = $this->cleanLabel($row->medium);
            $channel = $this->cleanLabel($row->channel);
            $type = $recordTypes->label($recordTypes->normalize($row->type)) ?? 'Sin clasificar';

            $statuses[$status] = ($statuses[$status] ?? 0) + 1;
            $types[$type] = ($types[$type] ?? 0) + 1;
            $sources[$source] = ($sources[$source] ?? 0) + 1;
            $media[$medium] = ($media[$medium] ?? 0) + 1;
            $channels[$channel] = ($channels[$channel] ?? 0) + 1;
        }

        return [
            'total' => $total,
            'statuses' => $this->countRows($statuses, 'status'),
            'types' => $this->countRows($types, 'type'),
            'sources' => $this->countRows($sources, 'source'),
            'media' => $this->countRows($media, 'medium'),
            'channels' => $this->countRows($channels, 'channel'),
        ];
    }

    /**
     * Materializes a fresh, isolated candidate projection. It is not made
     * visible until the validation row is switched under the revision lock.
     * Abandoned candidates therefore cannot be read by HTTP.
     */
    private function buildDashboardProjection(int $materializationId, int $revision, string $projectionKey): void
    {
        $normalizer = app(LeadDelegationNormalizer::class);
        $recordTypes = app(LeadRecordTypeNormalizer::class);
        $owners = app(ObservedInterestOwnerProjection::class);
        $now = now();

        DB::table('salesforce_interest_monthly_materialized_rows')
            ->where('monthly_materialization_id', $materializationId)
            ->where('content_matches_inventory', true)
            ->where('is_deleted', false)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($normalizer, $recordTypes, $owners, $revision, $projectionKey, $now): void {
                [$assignments, $labels] = $owners->forRows($rows, self::END_UTC, $projectionKey);
                $batch = [];
                foreach ($rows as $row) {
                    $delegation = $normalizer->normalize($row->origin_delegation);
                    $batch[] = [
                        'scope' => 'october_2026_provisional',
                        'integrity_revision' => $revision,
                        'projection_key' => $projectionKey,
                        'interest_status' => $this->projectionLabel($row->status),
                        'interest_type' => $recordTypes->label($recordTypes->normalize($row->type)) ?? 'Sin clasificar',
                        'source' => $this->projectionLabel($row->source),
                        'medium' => $this->projectionLabel($row->medium),
                        'channel' => $this->projectionLabel($row->channel),
                        'origin_delegation' => $this->projectionLabel($delegation['delegation']),
                        'origin_group' => $this->projectionLabel($delegation['group']),
                        'origin_zone' => $this->projectionLabel($delegation['zone']),
                        ...$assignments[(int) $row->id],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                DB::table('salesforce_interest_october_provisional_projection_rows')->insert($batch);
                DB::table('salesforce_interest_october_provisional_owners')->insertOrIgnore($labels);
            });
    }

    private function projectionLabel(mixed $value): string
    {
        return mb_substr($this->cleanLabel($value), 0, 191);
    }

    private function cleanLabel(mixed $value): string
    {
        $value = trim((string) $value);

        return $value === '' ? 'Sin clasificar' : $value;
    }

    /** @param array<string, int> $counts @return list<array<string, int|string>> */
    private function countRows(array $counts, string $label): array
    {
        return collect($counts)
            ->map(fn (int $interests, string $value): array => [$label => $value, 'interests' => $interests])
            ->sortBy([
                ['interests', 'desc'],
                [$label, 'asc'],
            ])
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $functionalKpis */
    private function assertFunctionalKpisReconcile(array $functionalKpis, int $expectedTotal): void
    {
        $total = (int) ($functionalKpis['total'] ?? -1);
        if ($total !== $expectedTotal) {
            throw new ProvisionalInterestSourceUnavailable;
        }

        foreach (['statuses', 'types', 'sources', 'media', 'channels'] as $dimension) {
            $rows = $functionalKpis[$dimension] ?? null;
            if (! is_array($rows) || collect($rows)->sum('interests') !== $total) {
                throw new ProvisionalInterestSourceUnavailable;
            }
        }
    }

    /** @param array<string, int> $counts @return list<array<string, int|string>> */
    private function labelCounts(array $counts, string $label, LeadDelegationNormalizer $normalizer): array
    {
        return collect($normalizer->sortLabels(array_keys($counts)))
            ->map(fn (string $value): array => [$label => $value, 'interests' => $counts[$value]])
            ->sortByDesc('interests')->values()->all();
    }

    /** @param array<string, mixed> $classification */
    private function assertClassificationReconciles(array $classification, int $expectedTotal): void
    {
        $total = (int) ($classification['total_included'] ?? -1);
        $allDimensions = [
            $classification['by_delegation'] ?? null,
            $classification['by_group'] ?? null,
            $classification['by_zone'] ?? null,
        ];

        if ($total !== $expectedTotal
            || (int) ($classification['classified'] ?? -1) + (int) ($classification['unclassified'] ?? -1) !== $total
            || (int) ($classification['raw_empty'] ?? -1) + (int) ($classification['raw_unmapped'] ?? -1) !== (int) ($classification['unclassified'] ?? -1)
            || (int) ($classification['raw_literal_general'] ?? -1) > (int) ($classification['raw_unmapped'] ?? -1)) {
            throw new ProvisionalInterestSourceUnavailable;
        }

        foreach ($allDimensions as $dimension) {
            if (! is_array($dimension) || collect($dimension)->sum('interests') !== $total) {
                throw new ProvisionalInterestSourceUnavailable;
            }
        }
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
        foreach ($query->cursor() as $item) {
            hash_update($hash, $table === 'salesforce_interest_monthly_materialized_rows'
                ? $item->salesforce_id.'|'.$item->content_hash."\n"
                : $item->content_hash."\n");
            $rows++;
        }

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
