<?php

namespace App\Services\Reports\Leads;

use App\Services\Salesforce\InterestMonthlyMaterializationService;
use App\Services\Salesforce\MonthlyInterestActivityRecoveryService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Read model for a validated monthly revision; never a published generation. */
final class MonthlyInterestProvisionalReader
{
    public function __construct(
        private readonly InterestMonthlyMaterializationService $materializations,
        private readonly MonthlyInterestActivityRecoveryService $activities,
        private readonly LeadDelegationNormalizer $delegations,
        private readonly LeadRecordTypeNormalizer $types,
        private readonly ObservedInterestOwnerProjection $owners,
        private readonly ?\Closure $beforePublication = null,
        private readonly ?\Closure $beforeHttpFinalCheck = null,
    ) {}

    /** Administrative operation only. Journal and manifest scans never run in HTTP. */
    public function validate(int $recoveryId): array
    {
        $recovery = DB::table('salesforce_interest_monthly_recoveries')->find($recoveryId);
        if ($recovery === null || $recovery->status !== 'completed' || $recovery->phase !== 'completed'
            || ! data_get($this->json($recovery->evidence), 'verification.verified', false)) {
            throw new ProvisionalInterestSourceUnavailable;
        }
        $materialization = DB::table('salesforce_interest_monthly_materializations')
            ->where('monthly_recovery_id', $recoveryId)->first();
        $activity = DB::table('salesforce_interest_monthly_activity_recoveries')
            ->where('monthly_recovery_id', $recoveryId)->first();
        if ($materialization === null || $activity === null
            || $materialization->status !== 'completed' || $activity->status !== 'completed'
            || $materialization->phase !== 'completed' || $activity->phase !== 'completed'
            || ! data_get($this->json($materialization->evidence), 'verification.verified', false)
            || ! data_get($this->json($activity->evidence), 'verification.verified', false)) {
            throw new ProvisionalInterestSourceUnavailable;
        }

        $scope = October2026ProvisionalEvidenceInvalidator::monthlyScope($recoveryId);
        DB::table('salesforce_interest_monthly_integrity_revisions')->insertOrIgnore([
            'scope' => $scope, 'revision' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $revision = $this->revision($scope);
        $source = $this->sourceManifests($recoveryId);
        $f2mEvidence = $this->json($materialization->evidence);
        $f5Evidence = $this->json($activity->evidence);
        $f2mSource = $this->json($materialization->source_evidence);
        $f5Source = $this->json($activity->source_evidence);
        if ($source['f2m'] !== ['rows' => $f2mSource['source_rows'] ?? null, 'sha256' => $f2mSource['source_sha256'] ?? null]
            || $source['f5'] !== ['rows' => $f5Source['source_rows'] ?? null, 'sha256' => $f5Source['source_sha256'] ?? null]
            || $this->materializations->manifest((int) $materialization->id) !== ($f2mEvidence['manifest'] ?? null)
            || $this->activities->manifest((int) $activity->id) !== ($f5Evidence['manifest'] ?? null)) {
            throw new ProvisionalInterestSourceUnavailable;
        }
        $quality = DB::table('salesforce_interest_monthly_materialized_rows')
            ->where('monthly_materialization_id', $materialization->id)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN content_matches_inventory = 1 AND is_deleted = 0 THEN 1 ELSE 0 END) as eligible')
            ->selectRaw('SUM(CASE WHEN is_deleted = 1 THEN 1 ELSE 0 END) as soft_deleted')
            ->first();
        if ((int) $quality->total !== $source['f2m']['rows']) {
            throw new ProvisionalInterestSourceUnavailable;
        }

        $start = CarbonImmutable::parse($recovery->functional_start_at, 'UTC')->setTimezone('Europe/Madrid');
        $end = CarbonImmutable::parse($recovery->functional_end_at, 'UTC')->setTimezone('Europe/Madrid');
        if ($start->day !== 1 || ! $start->equalTo($start->startOfDay()) || ! $end->equalTo($start->addMonth())) {
            throw new ProvisionalInterestSourceUnavailable;
        }
        $key = (string) Str::uuid();
        $this->buildProjection((int) $materialization->id, $scope, $revision, $key, (string) $recovery->functional_end_at);
        $projection = DB::table('salesforce_interest_october_provisional_projection_rows')
            ->where('scope', $scope)->where('projection_key', $key);
        if ((clone $projection)->count() !== (int) $quality->eligible || $this->revision($scope) !== $revision) {
            throw new ProvisionalInterestSourceUnavailable;
        }
        $overview = [
            'validated_at' => now('UTC')->toIso8601String(),
            'period' => ['label' => mb_convert_case($start->locale('es')->translatedFormat('F Y'), MB_CASE_TITLE, 'UTF-8'),
                'start_utc' => (string) $recovery->functional_start_at, 'end_utc' => (string) $recovery->functional_end_at,
                'timezone' => 'Europe/Madrid', 'effective_cutoff' => $recovery->effective_cutoff_at],
            'coverage' => ['f2_inventory' => $source['f2m']['rows'], 'materialized' => (int) $quality->total,
                'eligible_active_matching' => (int) $quality->eligible, 'soft_deleted_observed' => (int) $quality->soft_deleted],
            'classification' => [
                'by_delegation' => $this->distribution($projection, 'origin_delegation', 'delegation'),
                'by_group' => $this->distribution($projection, 'origin_group', 'group'),
                'by_zone' => $this->distribution($projection, 'origin_zone', 'zone'),
            ],
            'functional_kpis' => ['total' => (int) $quality->eligible,
                'statuses' => $this->distribution($projection, 'interest_status', 'status'),
                'types' => $this->distribution($projection, 'interest_type', 'type'),
                'sources' => $this->distribution($projection, 'source', 'source'),
                'media' => $this->distribution($projection, 'medium', 'medium'),
                'channels' => $this->distribution($projection, 'channel', 'channel')],
            'projection_contract' => 'observed_owners_v1',
            'verification' => ['f2_verified' => true, 'materialization_verified' => true, 'f5_verified' => true,
                'historical_certification' => 'unavailable'],
            'owner_assignment' => ['source' => 'f2m_owner_observed', 'delegation_source' => 'commercial_delegation_snapshots',
                'delegation_cutoff_utc' => (string) $recovery->functional_end_at, 'historical_owner_certified' => false],
        ];
        $overview['by_delegation'] = $overview['classification']['by_delegation'];

        if ($this->beforePublication !== null) {
            ($this->beforePublication)();
        }

        DB::transaction(function () use ($scope, $revision, $key, $recoveryId, $materialization, $activity, $overview, $source, $f2mEvidence, $f5Evidence, $quality): void {
            $current = DB::table('salesforce_interest_monthly_integrity_revisions')->where('scope', $scope)->lockForUpdate()->value('revision');
            $candidateCount = DB::table('salesforce_interest_october_provisional_projection_rows')
                ->where('scope', $scope)->where('projection_key', $key)->count();
            if ((int) $current !== $revision || $candidateCount !== (int) $quality->eligible) {
                throw new ProvisionalInterestSourceUnavailable;
            }
            DB::table('salesforce_interest_october_provisional_validations')->updateOrInsert(['scope' => $scope], [
                'monthly_recovery_id' => $recoveryId, 'monthly_materialization_id' => $materialization->id,
                'monthly_activity_recovery_id' => $activity->id, 'integrity_revision' => $revision,
                'projection_key' => $key, 'evidence' => json_encode(['source' => $source,
                    'f2m_manifest' => $f2mEvidence['manifest'], 'f5_manifest' => $f5Evidence['manifest']], JSON_THROW_ON_ERROR),
                'overview' => json_encode($overview, JSON_THROW_ON_ERROR), 'metrics' => json_encode([], JSON_THROW_ON_ERROR),
                'validated_at' => now('UTC'), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('salesforce_interest_october_provisional_projection_rows')
                ->where('scope', $scope)->where('projection_key', '!=', $key)->delete();
        });

        return $overview;
    }

    /** @return array{overview:array<string,mixed>,projection_key:string,integrity_revision:int,scope:string,recovery_id:int} */
    public function dashboardProjection(int $recoveryId): array
    {
        $scope = October2026ProvisionalEvidenceInvalidator::monthlyScope($recoveryId);
        try {
            $revision = $this->revision($scope);
            $row = DB::table('salesforce_interest_october_provisional_validations')->where('scope', $scope)->first();
            if ($row === null || (int) $row->monthly_recovery_id !== $recoveryId
                || (int) $row->integrity_revision !== $revision || ! Str::isUuid((string) $row->projection_key)) {
                throw new ProvisionalInterestSourceUnavailable;
            }
            $overview = $this->json($row->overview);
            if ($this->beforeHttpFinalCheck !== null) {
                ($this->beforeHttpFinalCheck)();
            }
            if (! isset($overview['coverage'], $overview['classification'], $overview['functional_kpis'])
                || $this->revision($scope) !== $revision) {
                throw new ProvisionalInterestSourceUnavailable;
            }

            return ['overview' => $overview, 'projection_key' => $row->projection_key,
                'integrity_revision' => $revision, 'scope' => $scope, 'recovery_id' => $recoveryId];
        } catch (Throwable) {
            throw new ProvisionalInterestSourceUnavailable;
        }
    }

    /** A prepared revision never displaces the last complete validated projection. */
    public function latestForMonth(int $year, int $month): array
    {
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            throw new ProvisionalInterestSourceUnavailable;
        }
        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'Europe/Madrid');
        try {
            $run = DB::table('salesforce_interest_monthly_recoveries as recovery')
                ->join('salesforce_interest_october_provisional_validations as validation', 'validation.monthly_recovery_id', '=', 'recovery.id')
                ->where('functional_start_at', $start->utc())
                ->where('functional_end_at', $start->addMonth()->utc())
                ->where('recovery.status', 'completed')
                ->where('recovery.phase', 'completed')
                ->orderByDesc('recovery.revision')->first(['recovery.id']);
        } catch (Throwable) {
            throw new ProvisionalInterestSourceUnavailable;
        }
        if ($run === null) {
            throw new ProvisionalInterestSourceUnavailable;
        }

        return $this->dashboardProjection((int) $run->id);
    }

    public function assertCurrent(int $recoveryId, int $revision, string $key): void
    {
        $scope = October2026ProvisionalEvidenceInvalidator::monthlyScope($recoveryId);
        try {
            $valid = DB::table('salesforce_interest_october_provisional_validations')
                ->where('scope', $scope)->where('integrity_revision', $revision)
                ->where('projection_key', $key)->exists();
            if (! $valid || $this->revision($scope) !== $revision) {
                throw new ProvisionalInterestSourceUnavailable;
            }
        } catch (Throwable) {
            throw new ProvisionalInterestSourceUnavailable;
        }
    }

    private function revision(string $scope): int
    {
        $revision = DB::table('salesforce_interest_monthly_integrity_revisions')->where('scope', $scope)->value('revision');
        if (! is_int($revision) && ! ctype_digit((string) $revision)) {
            throw new ProvisionalInterestSourceUnavailable;
        }

        return (int) $revision;
    }

    /** @return array{f2m:array{rows:int,sha256:string},f5:array{rows:int,sha256:string}} */
    private function sourceManifests(int $recoveryId): array
    {
        $f2m = hash_init('sha256');
        $f5 = hash_init('sha256');
        $count = 0;
        foreach (DB::table('salesforce_interest_monthly_recovery_rows')->where('monthly_recovery_id', $recoveryId)->orderBy('salesforce_id')->cursor() as $row) {
            hash_update($f2m, $row->salesforce_id.'|'.$row->content_hash.'|'.(int) $row->is_deleted."\n");
            hash_update($f5, $row->salesforce_id.':'.(int) $row->is_deleted."\n");
            $count++;
        }

        return ['f2m' => ['rows' => $count, 'sha256' => hash_final($f2m)],
            'f5' => ['rows' => $count, 'sha256' => hash_final($f5)]];
    }

    private function buildProjection(int $materializationId, string $scope, int $revision, string $key, string $cutoffUtc): void
    {
        DB::table('salesforce_interest_monthly_materialized_rows')
            ->where('monthly_materialization_id', $materializationId)
            ->where('content_matches_inventory', true)->where('is_deleted', false)
            ->orderBy('id')->chunkById(500, function ($rows) use ($scope, $revision, $key, $cutoffUtc): void {
                [$assignments, $labels] = $this->owners->forRows($rows, $cutoffUtc, $key);
                $batch = [];
                foreach ($rows as $row) {
                    $delegation = $this->delegations->normalize($row->origin_delegation);
                    $batch[] = ['scope' => $scope, 'integrity_revision' => $revision, 'projection_key' => $key,
                        'interest_status' => $this->label($row->status),
                        'interest_type' => $this->types->label($this->types->normalize($row->type)) ?? 'Sin clasificar',
                        'source' => $this->label($row->source), 'medium' => $this->label($row->medium),
                        'channel' => $this->label($row->channel), 'origin_delegation' => $this->label($delegation['delegation']),
                        'origin_group' => $this->label($delegation['group']), 'origin_zone' => $this->label($delegation['zone']),
                        ...$assignments[(int) $row->id], 'created_at' => now(), 'updated_at' => now()];
                }
                DB::table('salesforce_interest_october_provisional_projection_rows')->insert($batch);
                DB::table('salesforce_interest_october_provisional_owners')->insertOrIgnore($labels);
            });
    }

    /** @return list<array<string,int|string>> */
    private function distribution(Builder $query, string $column, string $label): array
    {
        return (clone $query)->select($column)->selectRaw('COUNT(*) as interests')
            ->groupBy($column)->orderByDesc('interests')->get()
            ->map(fn (object $row): array => [$label => $row->{$column}, 'interests' => (int) $row->interests])->all();
    }

    private function label(mixed $value): string
    {
        $value = trim((string) $value);

        return mb_substr($value === '' ? 'Sin clasificar' : $value, 0, 191);
    }

    /** @return array<string,mixed> */
    private function json(mixed $value): array
    {
        try {
            $decoded = json_decode((string) $value, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new ProvisionalInterestSourceUnavailable;
        }
        if (! is_array($decoded)) {
            throw new ProvisionalInterestSourceUnavailable;
        }

        return $decoded;
    }
}
