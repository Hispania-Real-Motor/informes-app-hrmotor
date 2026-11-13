<?php

namespace App\Services\Salesforce;

use App\Services\Reports\Leads\LeadDelegationNormalizer;
use App\Services\Reports\Leads\LeadRecordTypeNormalizer;
use App\Services\Reports\Leads\ObservedInterestOwnerProjection;
use App\Services\Reports\Leads\OperationalInterestIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Current-state, provisional monthly observations; never an F2 verification or official generation. */
final class OperationalInterestMonthlySync
{
    private const BATCH = 500;

    private const MAX_PAGES = 250;

    private const MAX_QUERY_ATTEMPTS = 300;

    private const MAX_REMOTE_PAGES_PER_BATCH = 10;

    private const LEASE_SECONDS = 300;

    private const SUPERSEDED = 1001;

    public function __construct(
        private readonly SalesforceClient $client,
        private readonly SalesforceInterestSyncService $interests,
        private readonly InterestMonthlyContentHasher $hasher,
        private readonly InterestMonthlyDifferenceClassifier $differences,
        private readonly LeadDelegationNormalizer $delegations,
        private readonly LeadRecordTypeNormalizer $types,
        private readonly ObservedInterestOwnerProjection $owners,
        private readonly OperationalInterestIdentity $identity,
    ) {}

    public function prepare(string $month): int
    {
        if (! preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', $month, $parts)) {
            throw new RuntimeException('Operational month must use YYYY-MM.');
        }
        $start = CarbonImmutable::create((int) $parts[1], (int) $parts[2], 1, 0, 0, 0, 'Europe/Madrid');
        $now = CarbonImmutable::now('UTC');
        $cutoff = $now->subMinutes(2)->startOfMinute();
        if ($start->utc()->gte($cutoff)) {
            throw new RuntimeException('Operational month has no safe extraction cutoff.');
        }

        return DB::transaction(function () use ($month, $start, $cutoff): int {
            DB::table('salesforce_interest_operational_months')->insertOrIgnore([
                'month_key' => $month, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $owner = DB::table('salesforce_interest_operational_months')->where('month_key', $month)->lockForUpdate()->first();
            if ($this->live($owner)) {
                throw new RuntimeException('Operational month already has a running worker.');
            }
            if ($this->superseded($cutoff, PHP_INT_MAX, $owner)) {
                throw new RuntimeException('Operational cutoff is older than the active version.');
            }
            $recent = DB::table('salesforce_interest_operational_runs')->where('month_key', $month)
                ->whereIn('status', ['prepared', 'running'])->orderByDesc('id')->first();
            if ($recent !== null) {
                throw new RuntimeException('Resume or inspect the existing operational run before preparing another.');
            }

            return DB::table('salesforce_interest_operational_runs')->insertGetId([
                'run_identifier' => (string) Str::uuid(), 'month_key' => $month,
                'utm_contract_version' => 2,
                'functional_start_at' => $start->utc(), 'functional_end_at' => $start->addMonth()->utc(),
                'effective_cutoff_at' => $cutoff, 'status' => 'prepared', 'phase' => 'extract',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    /** Resumes only the recorded phase. A completed run is immutable. */
    public function run(int $runId): array
    {
        $lease = $this->claim($runId);
        $stage = 'extract';
        try {
            while (true) {
                $run = $this->owned($runId, $lease);
                $stage = $run->phase;
                if ($run->phase === 'extract' || $run->phase === 'verify') {
                    if ((int) $run->pages_completed >= self::MAX_PAGES) {
                        throw new RuntimeException('Operational Salesforce page budget exhausted.');
                    }
                    $cursor = $run->phase === 'extract' ? $run->extract_cursor : $run->verify_cursor;
                    $records = $this->readPage($run, $lease, $cursor);
                    $this->persistPage($run, $lease, $records);

                    continue;
                }
                if ($run->phase === 'project') {
                    $this->projectPage($run, $lease);

                    continue;
                }
                if ($run->phase === 'activate') {
                    $this->activate($run, $lease);

                    return $this->status($runId);
                }
                throw new RuntimeException('Operational run phase is invalid.');
            }
        } catch (Throwable $error) {
            $errorStage = $error instanceof RuntimeException && $error->getCode() === self::SUPERSEDED
                ? 'superseded' : $stage;
            DB::transaction(function () use ($runId, $lease, $errorStage): void {
                $month = DB::table('salesforce_interest_operational_runs')->where('id', $runId)->value('month_key');
                $owner = DB::table('salesforce_interest_operational_months')->where('month_key', $month)->lockForUpdate()->first();
                if ($owner !== null && $owner->lease_token === $lease) {
                    DB::table('salesforce_interest_operational_runs')->where('id', $runId)
                        ->where('status', 'running')->update(['status' => 'failed', 'error_stage' => $errorStage, 'updated_at' => now()]);
                    DB::table('salesforce_interest_operational_months')->where('month_key', $month)
                        ->where('lease_token', $lease)->update(['lease_token' => null, 'heartbeat_at' => null, 'updated_at' => now()]);
                }
            });
            // The CLI reports a fixed message. Remote errors, SQL and record contents are not logged here.
            throw new RuntimeException('Operational Interest run stopped; inspect safe status and resume.');
        }
    }

    public function status(int $runId): array
    {
        $run = DB::table('salesforce_interest_operational_runs')->find($runId);
        if ($run === null) {
            throw new RuntimeException('Operational run does not exist.');
        }

        return [
            'run_id' => (int) $run->id, 'month' => $run->month_key, 'status' => $run->status,
            'phase' => $run->phase, 'extracted' => (int) $run->extracted_count,
            'checked' => (int) $run->verified_count, 'changed' => (int) $run->changed_count,
            'quarantined' => (int) $run->quarantined_count, 'pages' => (int) $run->pages_completed,
            'query_attempts' => (int) $run->query_attempts,
            'error_stage' => $run->error_stage, 'active' => (int) DB::table('salesforce_interest_operational_months')
                ->where('month_key', $run->month_key)->value('active_run_id') === (int) $run->id,
        ];
    }

    private function claim(int $runId): string
    {
        $lease = DB::transaction(function () use ($runId): ?string {
            $run = DB::table('salesforce_interest_operational_runs')->where('id', $runId)->first();
            if ($run === null || ! in_array($run->status, ['prepared', 'failed', 'running'], true)) {
                throw new RuntimeException('Operational run cannot be claimed.');
            }
            $month = DB::table('salesforce_interest_operational_months')->where('month_key', $run->month_key)->lockForUpdate()->first();
            if ($month === null || $this->live($month)) {
                throw new RuntimeException('Operational month already has a running worker.');
            }
            if ($this->superseded($run->effective_cutoff_at, (int) $run->id, $month)) {
                DB::table('salesforce_interest_operational_runs')->where('id', $runId)
                    ->update(['status' => 'failed', 'error_stage' => 'superseded', 'updated_at' => now()]);

                return null;
            }
            $lease = (string) Str::uuid();
            DB::table('salesforce_interest_operational_months')->where('month_key', $run->month_key)
                ->update(['lease_token' => $lease, 'heartbeat_at' => now('UTC'), 'updated_at' => now()]);
            DB::table('salesforce_interest_operational_runs')->where('id', $runId)
                ->update(['status' => 'running', 'error_stage' => null, 'started_at' => $run->started_at ?? now('UTC'), 'updated_at' => now()]);

            return $lease;
        });
        if ($lease === null) {
            throw new RuntimeException('Operational run is superseded.', self::SUPERSEDED);
        }

        return $lease;
    }

    /** Called while the month row is locked; equal cutoffs are ordered by run ID. */
    private function superseded(CarbonImmutable|string $cutoff, int $runId, object $month): bool
    {
        if ($month->active_run_id === null) {
            if ($month->active_projection_key !== null) {
                throw new RuntimeException('Operational active pointer is inconsistent.');
            }

            return false;
        }
        $active = DB::table('salesforce_interest_operational_runs')->where('id', $month->active_run_id)->first();
        if ($active === null || $active->month_key !== $month->month_key || $active->status !== 'completed'
            || $active->phase !== 'completed' || $active->projection_key !== $month->active_projection_key) {
            throw new RuntimeException('Operational active pointer is inconsistent.');
        }
        $candidateCutoff = $cutoff instanceof CarbonImmutable ? $cutoff : CarbonImmutable::parse($cutoff, 'UTC');
        $activeCutoff = CarbonImmutable::parse($active->effective_cutoff_at, 'UTC');

        return $candidateCutoff->lt($activeCutoff)
            || ($candidateCutoff->equalTo($activeCutoff) && $runId <= (int) $active->id);
    }

    private function live(object $month): bool
    {
        return $month->lease_token !== null && $month->heartbeat_at !== null
            && CarbonImmutable::parse($month->heartbeat_at, 'UTC')->gt(CarbonImmutable::now('UTC')->subSeconds(self::LEASE_SECONDS));
    }

    private function owned(int $runId, string $lease, bool $lock = false): object
    {
        $query = DB::table('salesforce_interest_operational_runs')->where('id', $runId)->where('status', 'running');
        $run = $query->first();
        if ($run === null) {
            throw new MonthlyInterestRecoveryLeaseLost;
        }

        $monthQuery = DB::table('salesforce_interest_operational_months')->where('month_key', $run->month_key);
        if ($lock) {
            $monthQuery->lockForUpdate();
        }
        $month = $monthQuery->first();
        if ($month === null || $month->lease_token !== $lease) {
            throw new MonthlyInterestRecoveryLeaseLost;
        }
        if ($lock) {
            $run = $query->lockForUpdate()->first();
            if ($run === null) {
                throw new MonthlyInterestRecoveryLeaseLost;
            }
        }

        return $run;
    }

    private function renew(int $runId, string $lease): void
    {
        $run = $this->owned($runId, $lease);
        $updated = DB::table('salesforce_interest_operational_months')
            ->where('month_key', $run->month_key)->where('lease_token', $lease)
            ->update(['heartbeat_at' => now('UTC'), 'updated_at' => now()]);
        if ($updated === 0) {
            $this->owned($runId, $lease); // MariaDB reports zero for an unchanged second.
        }
    }

    /** @return list<array<string,mixed>> */
    private function readPage(object $run, string $lease, ?string $cursor): array
    {
        DB::transaction(function () use ($run, $lease): void {
            $current = $this->owned((int) $run->id, $lease, true);
            if ((int) $current->query_attempts >= self::MAX_QUERY_ATTEMPTS) {
                throw new RuntimeException('Operational Salesforce query attempt budget exhausted.');
            }
            DB::table('salesforce_interest_operational_runs')->where('id', $run->id)
                ->update(['query_attempts' => (int) $current->query_attempts + 1, 'updated_at' => now()]);
        });
        $this->renew((int) $run->id, $lease);
        $soql = $this->interests->functionalPeriodSoql(
            CarbonImmutable::parse($run->functional_start_at, 'UTC'),
            CarbonImmutable::parse($run->functional_end_at, 'UTC'), $cursor, true,
            CarbonImmutable::parse($run->effective_cutoff_at, 'UTC'),
        )."\nLIMIT ".self::BATCH;
        $rows = [];
        $remotePages = 0;
        foreach ($this->client->queryPages($soql, true) as $page) {
            $this->renew((int) $run->id, $lease);
            if (++$remotePages >= self::MAX_REMOTE_PAGES_PER_BATCH) {
                throw new RuntimeException('Operational Salesforce pagination budget exhausted.');
            }
            foreach ($page as $record) {
                $rows[] = $record;
            }
            if (count($rows) > self::BATCH) {
                throw new RuntimeException('Operational Salesforce page exceeded its bound.');
            }
        }

        return $rows;
    }

    /** @param list<array<string,mixed>> $records */
    private function persistPage(object $run, string $lease, array $records): void
    {
        $last = $run->phase === 'extract' ? $run->extract_cursor : $run->verify_cursor;
        $mapped = [];
        foreach ($records as $record) {
            $id = (string) ($record['Id'] ?? '');
            if (preg_match('/^[A-Za-z0-9]{18}$/', $id) !== 1 || ($last !== null && strcmp($id, $last) <= 0)) {
                throw new RuntimeException('Operational Salesforce identities are invalid or unordered.');
            }
            $last = $id;
            if (! is_string($record['CreatedDate'] ?? null) || trim($record['CreatedDate']) === '') {
                throw new RuntimeException('Operational Salesforce record has no creation date.');
            }
            $created = CarbonImmutable::parse($record['CreatedDate'], 'UTC');
            $origin = $record['IN_Fecha_Creacion_Origen__c'] ?? null;
            $functional = CarbonImmutable::parse(is_string($origin) && trim($origin) !== '' ? $origin : $record['CreatedDate'], 'UTC');
            if ($created->gt(CarbonImmutable::parse($run->effective_cutoff_at, 'UTC'))
                || $functional->lt(CarbonImmutable::parse($run->functional_start_at, 'UTC'))
                || $functional->gte(CarbonImmutable::parse($run->functional_end_at, 'UTC'))) {
                throw new RuntimeException('Operational Salesforce membership changed or is invalid.');
            }
            try {
                $value = $this->interests->mapRecord($record, (bool) ($record['IsDeleted'] ?? false));
                if ((string) $value['salesforce_id'] !== $id || $value['functional_created_at'] === null
                    || ! CarbonImmutable::parse($value['functional_created_at'], 'UTC')->equalTo($functional)) {
                    throw new RuntimeException('Operational mapping disagrees with functional membership.');
                }
                $mapped[$id] = $value;
            } catch (Throwable) {
                // Membership and identity were checked independently. The record remains in coverage.
                $mapped[$id] = null;
            }
        }

        DB::transaction(function () use ($run, $lease, $records, $mapped, $last): void {
            $current = $this->owned((int) $run->id, $lease, true);
            if ($current->phase !== $run->phase) {
                throw new MonthlyInterestRecoveryLeaseLost;
            }
            $ids = array_keys($mapped);
            $previousRows = $run->phase === 'verify' && $ids !== []
                ? DB::table('salesforce_interest_operational_rows')->where('run_id', $run->id)->whereIn('salesforce_id', $ids)
                    ->get(['salesforce_id', 'content_hash', 'field_fingerprints'])->keyBy('salesforce_id') : collect();
            $previousIncidents = $run->phase === 'verify' && $ids !== []
                ? DB::table('salesforce_interest_operational_incidents')->where('run_id', $run->id)->whereIn('salesforce_id', $ids)
                    ->where('category', 'mapping')->pluck('salesforce_id')->all() : [];
            $changed = 0;
            $rowsToWrite = [];
            $mappingIncidents = [];
            $changedIncidents = [];
            $invalidIds = [];
            $recoveredIds = [];
            foreach ($mapped as $id => $value) {
                $previous = $previousRows->get($id);
                if ($run->phase === 'verify' && $previous === null && ! in_array($id, $previousIncidents, true)) {
                    throw new RuntimeException('Operational verification encountered an unobserved identity.');
                }
                if ($value === null) {
                    $invalidIds[] = $id;
                    $mappingIncidents[] = ['run_id' => $run->id, 'salesforce_id' => $id, 'category' => 'mapping',
                        'observed_at' => now('UTC'), 'verified_at' => $run->phase === 'verify' ? now('UTC') : null,
                        'created_at' => now(), 'updated_at' => now()];

                    continue;
                }
                $hash = $this->hasher->hash($value);
                $fingerprints = $this->differences->fingerprints($value);
                if ($previous !== null && ! hash_equals((string) $previous->content_hash, $hash)) {
                    $changed++;
                    $changedIncidents[] = [
                        'run_id' => $run->id, 'salesforce_id' => $id, 'category' => 'changed_between_reads',
                        'change_mask' => $this->differences->changedMask($previous->field_fingerprints, $fingerprints),
                        'observed_at' => now('UTC'), 'verified_at' => now('UTC'),
                        'created_at' => now(), 'updated_at' => now(),
                    ];
                }
                $recoveredIds[] = $id;
                $rowsToWrite[] = ['run_id' => $run->id, 'salesforce_id' => $id,
                    ...$this->storedRow($value, $hash, $fingerprints, $run->phase === 'verify', (int) $run->utm_contract_version)];
            }
            if ($invalidIds !== []) {
                DB::table('salesforce_interest_operational_rows')->where('run_id', $run->id)
                    ->whereIn('salesforce_id', $invalidIds)->delete();
                DB::table('salesforce_interest_operational_incidents')->upsert($mappingIncidents,
                    ['run_id', 'salesforce_id', 'category'], ['observed_at', 'verified_at', 'updated_at']);
            }
            if ($recoveredIds !== []) {
                DB::table('salesforce_interest_operational_incidents')->where('run_id', $run->id)
                    ->where('category', 'mapping')->whereIn('salesforce_id', $recoveredIds)->delete();
                DB::table('salesforce_interest_operational_rows')->upsert($rowsToWrite, ['run_id', 'salesforce_id'],
                    array_diff(array_keys($rowsToWrite[0]), ['run_id', 'salesforce_id', 'created_at']));
            }
            if ($changedIncidents !== []) {
                DB::table('salesforce_interest_operational_incidents')->insertOrIgnore($changedIncidents);
            }
            $updates = ['pages_completed' => (int) $current->pages_completed + 1,
                'updated_at' => now(), 'status' => 'running'];
            if ($run->phase === 'extract') {
                $updates['extract_cursor'] = $last;
                $updates['extracted_count'] = (int) $current->extracted_count + count($records);
                if (count($records) < self::BATCH) {
                    $updates['phase'] = 'verify';
                }
            } else {
                $updates['verify_cursor'] = $last;
                $updates['verified_count'] = (int) $current->verified_count + count($records);
                $updates['changed_count'] = (int) $current->changed_count + $changed;
                if (count($records) < self::BATCH) {
                    if ((int) $updates['verified_count'] !== (int) $current->extracted_count
                        || DB::table('salesforce_interest_operational_rows')->where('run_id', $run->id)->whereNull('verified_at')->exists()
                        || DB::table('salesforce_interest_operational_incidents')->where('run_id', $run->id)
                            ->where('category', 'mapping')->whereNull('verified_at')->exists()) {
                        throw new RuntimeException('Operational verification coverage is incomplete.');
                    }
                    $updates['phase'] = 'project';
                    $updates['projection_key'] = (string) Str::uuid();
                    $updates['quarantined_count'] = DB::table('salesforce_interest_operational_incidents')
                        ->where('run_id', $run->id)->where('category', 'mapping')->count();
                }
            }
            DB::table('salesforce_interest_operational_runs')->where('id', $run->id)->update($updates);
            DB::table('salesforce_interest_operational_months')->where('month_key', $run->month_key)
                ->where('lease_token', $lease)->update(['heartbeat_at' => now('UTC'), 'updated_at' => now()]);
        });
    }

    private function storedRow(array $value, string $hash, array $fingerprints, bool $verified, int $utmContractVersion): array
    {
        $fields = ['status', 'type', 'source', 'medium', 'channel', 'origin_delegation', 'owner_salesforce_id',
            'lead_salesforce_id', 'account_salesforce_id', 'sale_vehicle_salesforce_id',
            'appraisal_vehicle_salesforce_id', 'inverse_opportunity_salesforce_id'];
        $protectedFields = ['original_source', 'utm_campaign', 'utm_content', 'utm_id', 'utm_medium', 'utm_source', 'utm_term'];
        $row = ['content_hash' => $hash, 'field_fingerprints' => json_encode($fingerprints, JSON_THROW_ON_ERROR),
            'functional_created_at' => $value['functional_created_at'],
            'observed_at' => now('UTC'), 'verified_at' => $verified ? now('UTC') : null,
            'is_deleted' => (bool) $value['is_deleted'], 'owner_name_ciphertext' => $value['owner_name'] === null ? null : Crypt::encryptString((string) $value['owner_name']),
            'created_at' => now(), 'updated_at' => now()];
        foreach ($fields as $field) {
            $row[$field] = $value[$field] ?? null;
        }
        if ($utmContractVersion >= 2) {
            foreach ($protectedFields as $field) {
                $row[$field.'_ciphertext'] = ($value[$field] ?? null) === null ? null : Crypt::encryptString((string) $value[$field]);
            }
        }

        return $row;
    }

    private function projectPage(object $run, string $lease): void
    {
        $rows = DB::table('salesforce_interest_operational_rows')->where('run_id', $run->id)
            ->where('id', '>', $run->project_cursor ?? 0)->where('is_deleted', false)
            ->orderBy('id')->limit(self::BATCH)->get();
        if ($rows->isEmpty()) {
            DB::transaction(function () use ($run, $lease): void {
                $this->owned((int) $run->id, $lease, true);
                DB::table('salesforce_interest_operational_runs')->where('id', $run->id)->update(['phase' => 'activate', 'updated_at' => now()]);
            });

            return;
        }
        foreach ($rows as $row) {
            $row->owner_name = $row->owner_name_ciphertext === null ? null : Crypt::decryptString($row->owner_name_ciphertext);
        }
        $key = (string) $run->projection_key;
        [$assignments, $labels] = $this->owners->forRows($rows, (string) $run->started_at, $key);
        $batch = [];
        $scope = $this->scope($run->month_key);
        foreach ($rows as $row) {
            $delegation = $this->delegations->normalize($row->origin_delegation);
            $batch[] = ['scope' => $scope, 'integrity_revision' => $run->id, 'projection_key' => $key,
                'interest_key' => $this->identity->key((int) $run->id, (string) $row->salesforce_id),
                'interest_status' => $this->label($row->status),
                'interest_type' => $this->types->label($this->types->normalize($row->type)) ?? 'Sin clasificar',
                'source' => $this->label($row->source), 'medium' => $this->label($row->medium),
                'channel' => $this->label($row->channel), 'origin_delegation' => $this->label($delegation['delegation']),
                'origin_group' => $this->label($delegation['group']), 'origin_zone' => $this->label($delegation['zone']),
                ...$assignments[(int) $row->id], 'created_at' => now(), 'updated_at' => now()];
        }
        DB::transaction(function () use ($run, $lease, $rows, $batch, $labels): void {
            $current = $this->owned((int) $run->id, $lease, true);
            if ($current->phase !== 'project' || $current->project_cursor !== $run->project_cursor) {
                throw new MonthlyInterestRecoveryLeaseLost;
            }
            DB::table('salesforce_interest_october_provisional_projection_rows')->insert($batch);
            DB::table('salesforce_interest_october_provisional_owners')->insertOrIgnore($labels);
            DB::table('salesforce_interest_operational_runs')->where('id', $run->id)
                ->update(['project_cursor' => $rows->last()->id, 'updated_at' => now()]);
            DB::table('salesforce_interest_operational_months')->where('month_key', $run->month_key)
                ->where('lease_token', $lease)->update(['heartbeat_at' => now('UTC'), 'updated_at' => now()]);
        });
    }

    private function activate(object $run, string $lease): void
    {
        $scope = $this->scope($run->month_key);
        $projection = DB::table('salesforce_interest_october_provisional_projection_rows')
            ->where('scope', $scope)->where('projection_key', $run->projection_key);
        $admitted = DB::table('salesforce_interest_operational_rows')->where('run_id', $run->id);
        $projectable = (clone $admitted)->where('is_deleted', false)->count();
        if ((clone $projection)->count() !== $projectable
            || (clone $admitted)->whereNull('verified_at')->exists()
            || (int) $run->extracted_count !== (int) $run->verified_count) {
            throw new RuntimeException('Operational projection or coverage is incomplete.');
        }
        $start = CarbonImmutable::parse($run->functional_start_at, 'UTC')->setTimezone('Europe/Madrid');
        $overview = [
            'validated_at' => now('UTC')->toIso8601String(),
            'period' => ['label' => mb_convert_case($start->locale('es')->translatedFormat('F Y'), MB_CASE_TITLE, 'UTF-8'),
                'start_utc' => $run->functional_start_at, 'end_utc' => $run->functional_end_at,
                'timezone' => 'Europe/Madrid', 'effective_cutoff' => $run->effective_cutoff_at],
            'coverage' => ['observed' => (int) $run->extracted_count, 'admitted' => (clone $admitted)->count(),
                'eligible_active_matching' => $projectable, 'quarantined' => (int) $run->quarantined_count,
                'changed_between_reads' => (int) $run->changed_count,
                'change_categories' => $this->changeCategories((int) $run->id),
                'soft_deleted_observed' => (clone $admitted)->where('is_deleted', true)->count()],
            'classification' => ['by_delegation' => $this->distribution($projection, 'origin_delegation', 'delegation'),
                'by_group' => $this->distribution($projection, 'origin_group', 'group'),
                'by_zone' => $this->distribution($projection, 'origin_zone', 'zone')],
            'functional_kpis' => ['total' => $projectable,
                'statuses' => $this->distribution($projection, 'interest_status', 'status'),
                'types' => $this->distribution($projection, 'interest_type', 'type'),
                'sources' => $this->distribution($projection, 'source', 'source'),
                'media' => $this->distribution($projection, 'medium', 'medium'),
                'channels' => $this->distribution($projection, 'channel', 'channel')],
            'projection_contract' => 'observed_owners_v1',
            'interest_identity_key_id' => $this->identity->keyId(),
            'verification' => ['operational_coverage_checked' => true, 'historical_certification' => 'unavailable',
                'f2_verified' => false, 'f5_verified' => false],
        ];
        $overview['by_delegation'] = $overview['classification']['by_delegation'];
        DB::transaction(function () use ($run, $lease, $overview, $scope, $projectable): void {
            $month = DB::table('salesforce_interest_operational_months')
                ->where('month_key', $run->month_key)->lockForUpdate()->first();
            if ($month === null || $month->lease_token !== $lease
                || DB::table('salesforce_interest_october_provisional_projection_rows')
                    ->where('scope', $scope)->where('projection_key', $run->projection_key)->count() !== $projectable) {
                throw new MonthlyInterestRecoveryLeaseLost;
            }
            $this->owned((int) $run->id, $lease, true);
            if ($this->superseded($run->effective_cutoff_at, (int) $run->id, $month)) {
                throw new RuntimeException('Operational run is superseded.', self::SUPERSEDED);
            }
            DB::table('salesforce_interest_operational_runs')->where('id', $run->id)->update([
                'status' => 'completed', 'phase' => 'completed', 'overview' => json_encode($overview, JSON_THROW_ON_ERROR),
                'completed_at' => now('UTC'), 'updated_at' => now(),
            ]);
            DB::table('salesforce_interest_operational_months')->where('month_key', $run->month_key)->update([
                'active_run_id' => $run->id, 'active_projection_key' => $run->projection_key,
                'lease_token' => null, 'heartbeat_at' => null, 'updated_at' => now(),
            ]);
        });
    }

    private function scope(string $month): string
    {
        return 'operational_'.str_replace('-', '_', $month);
    }

    private function label(mixed $value): string
    {
        $value = trim((string) $value);

        return mb_substr($value === '' ? 'Sin clasificar' : $value, 0, 191);
    }

    private function distribution(Builder $query, string $column, string $label): array
    {
        return (clone $query)->select($column)->selectRaw('COUNT(*) as interests')
            ->groupBy($column)->orderByDesc('interests')->get()
            ->map(fn (object $row): array => [$label => $row->{$column}, 'interests' => (int) $row->interests])->all();
    }

    private function changeCategories(int $runId): array
    {
        $counts = $this->differences->emptyCounts();
        $grouped = DB::table('salesforce_interest_operational_incidents')->where('run_id', $runId)
            ->where('category', 'changed_between_reads')->select('change_mask')
            ->selectRaw('COUNT(*) as total')->groupBy('change_mask')->get();
        foreach ($grouped as $row) {
            $one = $this->differences->countMask($this->differences->emptyCounts(), (int) $row->change_mask);
            foreach ($one as $category => $value) {
                $counts[$category] += $value * (int) $row->total;
            }
        }

        return $counts;
    }
}
