<?php

namespace App\Services\Reports\Leads;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Administrative, resumable derivation of the official Interest classifications. */
final class OperationalInterestKpiProjector
{
    private const BATCH = 500;

    private const LEASE_SECONDS = 300;

    public function __construct(
        private readonly OperationalInterestMonthlyReader $months,
        private readonly OperationalInterestIdentity $identity,
        private readonly SalesforceInterestDashboardDatasetService $official,
    ) {}

    public function build(int $year, int $month): array
    {
        $snapshot = $this->months->latestForMonth($year, $month);
        if ($snapshot['activity_run_id'] === null) {
            throw new ProvisionalInterestSourceUnavailable;
        }
        $activity = DB::table('salesforce_interest_operational_activity_runs')->find($snapshot['activity_run_id']);
        if ($activity === null || $activity->completed_at === null) {
            throw new ProvisionalInterestSourceUnavailable;
        }
        $newQuarantineCount = DB::table('salesforce_interest_operational_activity_rows')
            ->where('run_id', $snapshot['activity_run_id'])->where('quarantine_reason', 'new_during_verify')
            ->whereNotNull('quarantined_at')->count();
        $globalActivityUncertainty = ! $snapshot['activity_coverage_complete']
            && $newQuarantineCount !== $snapshot['activity_quarantined_count'];
        $reference = CarbonImmutable::parse($activity->completed_at, 'UTC');
        $periodEnd = CarbonImmutable::parse($snapshot['overview']['period']['end_utc'], 'UTC');
        if ($periodEnd->lte($reference)) {
            $reference = $periodEnd->subSecond();
        }
        $usersFingerprint = $this->commercialUsersFingerprint();
        [$id, $lease, $completed] = $this->claim($snapshot, $reference);
        if ($completed) {
            return $this->status($id);
        }

        try {
            while (true) {
                $this->assertCurrent($snapshot);
                $projection = DB::table('salesforce_interest_operational_kpi_projections')->find($id);
                $rows = DB::table('salesforce_interest_operational_rows')
                    ->where('run_id', $snapshot['integrity_revision'])->where('is_deleted', false)
                    ->where('id', '>', $projection->project_cursor)->orderBy('id')->limit(self::BATCH)->get();
                if ($rows->isEmpty()) {
                    $this->complete($snapshot, $id, $lease, $usersFingerprint);

                    return $this->status($id);
                }
                $keys = $rows->mapWithKeys(fn (object $row): array => [
                    $row->salesforce_id => $this->identity->key($snapshot['integrity_revision'], $row->salesforce_id),
                ])->all();
                $summaries = DB::table('salesforce_interest_operational_activity_summaries')
                    ->where('run_id', $snapshot['activity_run_id'])->whereIn('interest_key', array_values($keys))
                    ->get()->keyBy('interest_key');
                $affectedInterests = $snapshot['activity_coverage_complete'] || $globalActivityUncertainty ? []
                    : DB::table('salesforce_interest_operational_activity_rows')
                        ->where('run_id', $snapshot['activity_run_id'])
                        ->where('quarantine_reason', 'new_during_verify')->whereNotNull('quarantined_at')
                        ->whereIn('interest_salesforce_id', array_keys($keys))
                        ->pluck('interest_salesforce_id')->flip()->all();
                $batch = [];
                foreach ($rows as $row) {
                    $key = $keys[$row->salesforce_id];
                    $summary = $summaries->get($key);
                    $ownerName = $row->owner_name_ciphertext === null ? null : Crypt::decryptString($row->owner_name_ciphertext);
                    $classified = $this->official->decorateLead(
                        (object) ['status' => $row->status, 'type' => $row->type,
                            'origin_delegation' => $row->origin_delegation,
                            'owner_salesforce_id' => $row->owner_salesforce_id, 'owner_name' => $ownerName,
                            'source' => $row->source, 'channel' => $row->channel],
                        (object) ['total_actividades' => $summary->activities ?? 0,
                            'fecha_ultima_actividad' => $summary?->last_activity_at === null
                                ? null : CarbonImmutable::parse($summary->last_activity_at, 'UTC')],
                        $reference,
                    );
                    $potential = $classified['is_potencial'];
                    $recent = $potential && $classified['is_gestionado'];
                    $complete = $snapshot['activity_coverage_complete']
                        || (! $globalActivityUncertainty && ! array_key_exists($row->salesforce_id, $affectedInterests));
                    $batch[] = [
                        'projection_id' => $id,
                        'interest_key' => $key,
                        'managed' => ($complete || ! $potential || $recent) ? $classified['is_gestionado'] : null,
                        'potential_unworked' => ($complete || ! $potential || $recent || $classified['is_lead_sin_asignar'])
                            ? $classified['is_potencial_sin_trabajar'] : null,
                        'without_eligible_commercial' => $classified['is_without_eligible_commercial'],
                        'without_commercial_delegation' => $classified['is_without_commercial_delegation'],
                    ];
                }
                DB::transaction(function () use ($snapshot, $id, $lease, $projection, $rows, $batch): void {
                    $this->assertCurrent($snapshot);
                    $owner = DB::table('salesforce_interest_operational_kpi_projections')->where('id', $id)->lockForUpdate()->first();
                    if ($owner?->lease_token !== $lease || $owner->status !== 'running'
                        || (int) $owner->project_cursor !== (int) $projection->project_cursor) {
                        throw new ProvisionalInterestSourceUnavailable;
                    }
                    DB::table('salesforce_interest_operational_kpi_rows')->insert($batch);
                    DB::table('salesforce_interest_operational_kpi_projections')->where('id', $id)->update([
                        'project_cursor' => $rows->last()->id,
                        'rows_count' => (int) $owner->rows_count + count($batch),
                        'heartbeat_at' => now('UTC'), 'updated_at' => now(),
                    ]);
                });
            }
        } catch (Throwable) {
            DB::table('salesforce_interest_operational_kpi_projections')->where('id', $id)
                ->where('lease_token', $lease)->where('status', 'running')->update([
                    'status' => 'failed', 'lease_token' => null, 'heartbeat_at' => null, 'updated_at' => now(),
                ]);
            throw new ProvisionalInterestSourceUnavailable;
        }
    }

    private function claim(array $snapshot, CarbonImmutable $reference): array
    {
        return DB::transaction(function () use ($snapshot, $reference): array {
            DB::table('salesforce_interest_operational_kpi_projections')->insertOrIgnore([
                'source_run_id' => $snapshot['integrity_revision'],
                'activity_run_id' => $snapshot['activity_run_id'],
                'source_projection_key' => $snapshot['projection_key'],
                'status' => 'prepared',
                'activity_coverage_complete' => $snapshot['activity_coverage_complete'],
                'reference_at' => $reference,
                'commercial_users_observed_at' => now('UTC'),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $query = DB::table('salesforce_interest_operational_kpi_projections')
                ->where('source_run_id', $snapshot['integrity_revision'])
                ->where('activity_run_id', $snapshot['activity_run_id']);
            $projection = (clone $query)->lockForUpdate()->first();
            if ($projection === null || $projection->source_projection_key !== $snapshot['projection_key']
                || (bool) $projection->activity_coverage_complete !== $snapshot['activity_coverage_complete']) {
                throw new ProvisionalInterestSourceUnavailable;
            }
            if ($projection->status === 'completed') {
                return [(int) $projection->id, '', true];
            }
            if ($projection->lease_token !== null && $projection->heartbeat_at !== null
                && CarbonImmutable::parse($projection->heartbeat_at, 'UTC')->gt(now('UTC')->subSeconds(self::LEASE_SECONDS))) {
                throw new RuntimeException('Operational KPI projection already has a worker.');
            }
            // A retry derives every row from one current local user observation.
            DB::table('salesforce_interest_operational_kpi_rows')->where('projection_id', $projection->id)->delete();
            $lease = (string) Str::uuid();
            (clone $query)->update(['status' => 'running', 'lease_token' => $lease,
                'heartbeat_at' => now('UTC'), 'project_cursor' => 0, 'rows_count' => 0,
                'reference_at' => $reference, 'commercial_users_observed_at' => now('UTC'), 'updated_at' => now()]);

            return [(int) $projection->id, $lease, false];
        });
    }

    private function complete(array $snapshot, int $id, string $lease, string $usersFingerprint): void
    {
        if (! hash_equals($usersFingerprint, $this->commercialUsersFingerprint())) {
            throw new ProvisionalInterestSourceUnavailable;
        }
        DB::transaction(function () use ($snapshot, $id, $lease, $usersFingerprint): void {
            $month = DB::table('salesforce_interest_operational_months')
                ->where('month_key', $snapshot['operational_month'])->lockForUpdate()->first();
            $projection = DB::table('salesforce_interest_operational_kpi_projections')->where('id', $id)->lockForUpdate()->first();
            if ($month === null || (int) $month->active_run_id !== $snapshot['integrity_revision']
                || $month->active_projection_key !== $snapshot['projection_key']
                || (int) $month->active_activity_run_id !== $snapshot['activity_run_id']
                || $projection?->lease_token !== $lease || $projection->status !== 'running') {
                throw new ProvisionalInterestSourceUnavailable;
            }
            $expected = DB::table('salesforce_interest_operational_rows')
                ->where('run_id', $snapshot['integrity_revision'])->where('is_deleted', false)->count();
            if ((int) $projection->rows_count !== $expected
                || DB::table('salesforce_interest_operational_kpi_rows')->where('projection_id', $id)->count() !== $expected) {
                throw new ProvisionalInterestSourceUnavailable;
            }
            DB::table('salesforce_interest_operational_kpi_projections')->where('id', $id)->update([
                'status' => 'completed', 'lease_token' => null, 'heartbeat_at' => null,
                'commercial_users_fingerprint' => $usersFingerprint,
                'completed_at' => now('UTC'), 'updated_at' => now(),
            ]);
        });
    }

    private function commercialUsersFingerprint(): string
    {
        $context = hash_init('sha256', HASH_HMAC, (string) config('app.key'));
        foreach (DB::table('salesforce_users')->orderBy('salesforce_id')->orderBy('id')
            ->cursor(['salesforce_id', 'is_active', 'profile_name', 'user_delegation']) as $row) {
            hash_update($context, json_encode([$row->salesforce_id, $row->is_active,
                $row->profile_name, $row->user_delegation], JSON_THROW_ON_ERROR));
        }

        return hash_final($context);
    }

    private function assertCurrent(array $snapshot): void
    {
        $this->months->assertCurrent($snapshot['operational_month'], $snapshot['integrity_revision'], $snapshot['projection_key']);
        $this->months->assertActivityPointer($snapshot['operational_month'], $snapshot['activity_pointer_id']);
    }

    private function status(int $id): array
    {
        $projection = DB::table('salesforce_interest_operational_kpi_projections')->find($id);

        return ['projection_id' => $id, 'source_run_id' => (int) $projection->source_run_id,
            'activity_run_id' => (int) $projection->activity_run_id,
            'status' => $projection->status, 'rows' => (int) $projection->rows_count,
            'activity_coverage_complete' => (bool) $projection->activity_coverage_complete];
    }
}
