<?php

namespace App\Services\Salesforce;

use App\Services\Reports\Leads\October2026ProvisionalEvidenceInvalidator;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Materializes the current functional representation of an already frozen F2
 * monthly identity inventory. It is never a generation, publication source,
 * or substitute for historical certification.
 */
final class InterestMonthlyMaterializationService
{
    private const LEASE_SECONDS = 900;

    private const DEFAULT_BATCH_SIZE = 500;

    private const ID_PATTERN = '/^[A-Za-z0-9]{18}$/';

    public function __construct(
        private readonly SalesforceClient $client,
        private readonly SalesforceInterestSyncService $interests,
        private readonly InterestMonthlyContentHasher $hasher,
        private readonly October2026ProvisionalEvidenceInvalidator $octoberEvidence = new October2026ProvisionalEvidenceInvalidator,
    ) {}

    public function prepare(int $monthlyRecoveryId, string $reason, int $batch = self::DEFAULT_BATCH_SIZE): int
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500 || $batch < 50 || $batch > self::DEFAULT_BATCH_SIZE) {
            throw new RuntimeException('Monthly F2 materialization requires a reason and a safe batch size.');
        }

        return DB::transaction(function () use ($monthlyRecoveryId, $reason, $batch): int {
            $source = DB::table('salesforce_interest_monthly_recoveries')->where('id', $monthlyRecoveryId)->lockForUpdate()->first();
            if ($source === null || $source->status !== 'completed' || $source->phase !== 'completed') {
                throw new RuntimeException('Monthly F2 materialization requires a completed monthly inventory.');
            }
            $existing = DB::table('salesforce_interest_monthly_materializations')->where('monthly_recovery_id', $monthlyRecoveryId)->lockForUpdate()->first();
            if ($existing !== null) {
                return (int) $existing->id;
            }
            $snapshot = $this->sourceSnapshot($monthlyRecoveryId);
            $reuse = $this->reusableMaterialization($source);

            return DB::table('salesforce_interest_monthly_materializations')->insertGetId([
                'materialization_identifier' => (string) Str::uuid(),
                'monthly_recovery_id' => $monthlyRecoveryId,
                'status' => 'prepared',
                'phase' => 'materialize',
                'batch_size' => $batch,
                'pages_completed' => 0,
                'counters' => json_encode($this->emptyCounters($snapshot['rows']), JSON_THROW_ON_ERROR),
                'source_evidence' => json_encode([
                    'monthly_recovery_id' => $monthlyRecoveryId,
                    'source_rows' => $snapshot['rows'],
                    'source_sha256' => $snapshot['sha256'],
                    'reuse_materialization_id' => $reuse['id'] ?? null,
                    'reuse_recovery_id' => $reuse['recovery_id'] ?? null,
                    'reuse_manifest' => $reuse['manifest'] ?? null,
                    'reuse_source_snapshot' => $reuse['source_snapshot'] ?? null,
                    'f2_verified' => (bool) data_get(json_decode((string) $source->evidence, true) ?: [], 'verification.verified', false),
                    'historical_certification' => false,
                ], JSON_THROW_ON_ERROR),
                'reason_hash' => hash('sha256', $reason),
                'started_at' => now('UTC'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /** @return array<string,mixed> */
    public function run(int $id, string $reason, bool $resume = false): array
    {
        $lease = $this->claim($id, $reason, $resume);
        $stage = 'materialization_loop';
        try {
            while (true) {
                $run = $this->owned($id, $lease);
                if ($run->phase === 'materialize') {
                    $stage = 'source_inventory_query';
                    $sourceRows = $this->sourcePage((int) $run->monthly_recovery_id, $run->checkpoint_salesforce_id, (int) $run->batch_size);
                    if ($sourceRows === []) {
                        $this->complete($run, $lease);

                        continue;
                    }
                    $sourceEvidence = json_decode($run->source_evidence, true, 64, JSON_THROW_ON_ERROR);
                    $reusedRows = $this->reusablePage($run, $sourceRows, $sourceEvidence['reuse_materialization_id'] ?? null);
                    $pending = array_values(array_filter($sourceRows, fn (object $row): bool => ! isset($reusedRows[$row->salesforce_id])));
                    $stage = 'salesforce_query';
                    $records = $pending === [] ? [] : $this->read($this->interests->monthlyMaterializationSoql(array_map(fn (object $row): string => $row->salesforce_id, $pending)), (int) $run->batch_size, $id, $lease);
                    $this->persistPage($run, $lease, $sourceRows, $records, $reusedRows, $stage);

                    continue;
                }
                if ($run->phase === 'completed') {
                    $updated = DB::table('salesforce_interest_monthly_materializations')->where('id', $id)->where('lease_token', $lease)->where('status', 'running')->update([
                        'status' => 'completed', 'completed_at' => now('UTC'), 'lease_token' => null, 'updated_at' => now(),
                    ]);
                    if ($updated !== 1) {
                        throw new MonthlyInterestRecoveryLeaseLost;
                    }

                    return $this->status($id);
                }
                throw new RuntimeException('Monthly F2 materialization checkpoint is not runnable.');
            }
        } catch (Throwable $error) {
            $this->recordFailure($id, $lease, $stage, $error);
            throw $error;
        }
    }

    public function cancel(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $run = DB::table('salesforce_interest_monthly_materializations')->where('id', $id)->lockForUpdate()->first();
            if ($run === null || $run->status === 'completed' || ($run->status === 'running' && $this->live($run))) {
                throw new RuntimeException('Monthly F2 materialization cannot be cancelled safely.');
            }
            DB::table('salesforce_interest_monthly_materializations')->where('id', $id)->update([
                'status' => 'cancelled', 'phase' => 'cancelled', 'cancelled_at' => now('UTC'), 'lease_token' => null, 'updated_at' => now(),
            ]);
        });
    }

    /** @return array<string,mixed> */
    public function status(int $id): array
    {
        $run = DB::table('salesforce_interest_monthly_materializations')->find($id)
            ?? throw new RuntimeException('Monthly F2 materialization does not exist.');

        return [
            'materialization_id' => (int) $run->id,
            'monthly_recovery_id' => (int) $run->monthly_recovery_id,
            'status' => $run->status,
            'phase' => $run->phase,
            'pages_completed' => (int) $run->pages_completed,
            'counters' => json_decode($run->counters, true, 64, JSON_THROW_ON_ERROR),
            'source_evidence' => json_decode($run->source_evidence, true, 64, JSON_THROW_ON_ERROR),
            'evidence' => $run->evidence === null ? null : json_decode($run->evidence, true, 64, JSON_THROW_ON_ERROR),
            'diagnostic' => $run->error_code === null ? null : [
                'stage' => $run->error_stage,
                'category' => $run->error_code,
                'meta' => $run->error_meta === null ? [] : json_decode($run->error_meta, true, 16, JSON_THROW_ON_ERROR),
            ],
            'historical_certification' => 'unavailable',
            'published' => false,
        ];
    }

    /** @return array{rows:int,sha256:string} */
    public function manifest(int $id): array
    {
        $hash = hash_init('sha256');
        $rows = 0;
        DB::table('salesforce_interest_monthly_materialized_rows')->where('monthly_materialization_id', $id)->orderBy('salesforce_id')->chunk(500, function ($items) use ($hash, &$rows): void {
            foreach ($items as $item) {
                hash_update($hash, $item->salesforce_id.'|'.$item->content_hash."\n");
                $rows++;
            }
        });

        return ['rows' => $rows, 'sha256' => hash_final($hash)];
    }

    /** @return list<object> */
    private function sourcePage(int $monthlyRecoveryId, ?string $checkpoint, int $batch): array
    {
        $query = DB::table('salesforce_interest_monthly_recovery_rows')->where('monthly_recovery_id', $monthlyRecoveryId)->orderBy('salesforce_id')->limit($batch);
        if ($checkpoint !== null) {
            $query->where('salesforce_id', '>', $checkpoint);
        }

        return $query->get(['id', 'salesforce_id', 'content_hash', 'functional_created_at'])->all();
    }

    /** @return array{id:int,recovery_id:int,manifest:array{rows:int,sha256:string},source_snapshot:array{rows:int,sha256:string}}|null */
    private function reusableMaterialization(object $source): ?array
    {
        if ((int) ($source->revision ?? 1) < 2) {
            return null;
        }
        $previous = DB::table('salesforce_interest_monthly_recoveries')
            ->where('functional_start_at', $source->functional_start_at)
            ->where('functional_end_at', $source->functional_end_at)
            ->where('revision', (int) $source->revision - 1)->first();
        if ($previous === null || $previous->status !== 'completed') {
            return null;
        }
        $run = DB::table('salesforce_interest_monthly_materializations')
            ->where('monthly_recovery_id', $previous->id)->where('status', 'completed')->first();
        if ($run === null) {
            return null;
        }
        $sourceEvidence = json_decode($run->source_evidence, true, 64, JSON_THROW_ON_ERROR);
        $evidence = json_decode((string) $run->evidence, true, 64, JSON_THROW_ON_ERROR);
        $previousSnapshot = $this->sourceSnapshot((int) $previous->id);
        $previousManifest = $this->manifest((int) $run->id);
        if (! ($sourceEvidence['f2_verified'] ?? false) || ! data_get($evidence, 'verification.verified', false)
            || $previousSnapshot !== ['rows' => $sourceEvidence['source_rows'] ?? null, 'sha256' => $sourceEvidence['source_sha256'] ?? null]
            || $previousManifest !== ($evidence['manifest'] ?? null)) {
            return null;
        }

        return ['id' => (int) $run->id, 'recovery_id' => (int) $previous->id,
            'manifest' => $previousManifest, 'source_snapshot' => $previousSnapshot];
    }

    /** @param list<object> $sourceRows @return array<string,array<string,mixed>> */
    private function reusablePage(object $run, array $sourceRows, mixed $previousId): array
    {
        if (! is_int($previousId) || $previousId < 1) {
            return [];
        }
        $sources = collect($sourceRows)->keyBy('salesforce_id');
        $rows = [];
        $previous = DB::table('salesforce_interest_monthly_materialized_rows')
            ->where('monthly_materialization_id', $previousId)
            ->whereIn('salesforce_id', $sources->keys()->all())->get();
        foreach ($previous as $item) {
            $source = $sources->get($item->salesforce_id);
            if ($source === null || ! (bool) $item->content_matches_inventory
                || ! hash_equals($source->content_hash, $item->source_content_hash)
                || ! hash_equals($item->content_hash, $item->source_content_hash)) {
                continue;
            }
            $row = (array) $item;
            unset($row['id']);
            $row['monthly_materialization_id'] = $run->id;
            $row['monthly_recovery_row_id'] = $source->id;
            $row['created_at'] = now();
            $row['updated_at'] = now();
            $rows[$item->salesforce_id] = $row;
        }

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function read(string $soql, int $limit, int $id, string $lease): array
    {
        $rows = [];
        $this->renew($id, $lease);
        foreach ($this->client->queryPages($soql, true) as $page) {
            $this->renew($id, $lease);
            foreach ($page as $row) {
                $rows[] = $row;
            }
            if (count($rows) > $limit) {
                throw new RuntimeException('Monthly F2 materialization response exceeded its configured bound.');
            }
        }

        return $rows;
    }

    /** @param list<object> $sourceRows @param list<array<string,mixed>> $records @param array<string,array<string,mixed>> $reusedRows */
    private function persistPage(object $run, string $lease, array $sourceRows, array $records, array $reusedRows, string &$stage): void
    {
        $expected = [];
        foreach ($sourceRows as $source) {
            $expected[$source->salesforce_id] = $source;
        }
        $mappedRows = array_values($reusedRows);
        $received = array_fill_keys(array_keys($reusedRows), true);
        $duplicates = 0;
        $unexpected = 0;
        foreach ($records as $record) {
            $stage = 'mapping';
            $mapped = $this->interests->mapRecord($record, (bool) data_get($record, 'IsDeleted', false));
            $salesforceId = trim((string) ($mapped['salesforce_id'] ?? ''));
            $stage = 'record_validation';
            if (preg_match(self::ID_PATTERN, $salesforceId) !== 1) {
                throw new RuntimeException('Monthly F2 materialization received an invalid Salesforce identity.');
            }
            if (! isset($expected[$salesforceId])) {
                $unexpected++;

                continue;
            }
            if (isset($received[$salesforceId])) {
                $duplicates++;

                continue;
            }
            $received[$salesforceId] = true;
            $stage = 'content_hash';
            $hash = $this->hasher->hash($mapped);
            $source = $expected[$salesforceId];
            $mappedRows[] = $this->materializedRow($run, $source, $mapped, $hash);
        }
        $missing = count($expected) - count($received);
        $functionalDateChanges = 0;
        foreach ($mappedRows as $row) {
            if (! CarbonImmutable::parse($row['functional_created_at'], 'UTC')->equalTo(CarbonImmutable::parse($expected[$row['salesforce_id']]->functional_created_at, 'UTC'))) {
                $functionalDateChanges++;
            }
        }
        $last = (string) $sourceRows[array_key_last($sourceRows)]->salesforce_id;

        DB::transaction(function () use ($run, $lease, $sourceRows, $records, $reusedRows, $mappedRows, $missing, $duplicates, $unexpected, $functionalDateChanges, $last, &$stage): void {
            $this->owned((int) $run->id, $lease, true);
            $counters = $this->counters($run);
            if ($mappedRows !== []) {
                $stage = 'database_persist';
                DB::table('salesforce_interest_monthly_materialized_rows')->upsert($mappedRows, ['monthly_materialization_id', 'salesforce_id'], [
                    'monthly_recovery_row_id', 'source_content_hash', 'content_hash', 'content_matches_inventory', 'is_deleted', 'salesforce_deleted_at', 'deletion_detection_source',
                    'lead_salesforce_id', 'account_salesforce_id', 'migration_origin_lead_id', 'salesforce_created_at', 'salesforce_last_modified_at', 'system_modstamp_at', 'origin_created_at', 'functional_created_at',
                    'owner_salesforce_id', 'owner_name', 'status', 'type', 'source', 'original_source', 'medium', 'channel', 'origin_delegation', 'utm_campaign', 'utm_id', 'utm_source', 'utm_medium', 'utm_content', 'utm_term',
                    'sale_vehicle_salesforce_id', 'appraisal_vehicle_salesforce_id', 'inverse_opportunity_salesforce_id', 'canonical_person_type', 'canonical_person_salesforce_id', 'updated_at',
                ]);
                $this->octoberEvidence->afterContentMutation((int) $run->monthly_recovery_id);
            }
            $counters['requested'] += count($sourceRows);
            $counters['remote_received'] += count($records);
            $counters['reused'] += count($reusedRows);
            $counters['materialized'] += count($mappedRows);
            $counters['missing'] += $missing;
            $counters['unexpected'] += $unexpected;
            $counters['duplicates'] += $duplicates;
            foreach ($mappedRows as $row) {
                $counters[$row['content_matches_inventory'] ? 'content_matching' : 'content_changed']++;
                $counters['soft_deleted_observed'] += (int) $row['is_deleted'];
                $counters['lead_present'] += (int) ($row['lead_salesforce_id'] !== null);
                $counters['account_present'] += (int) ($row['account_salesforce_id'] !== null);
                $counters['canonical_person_missing'] += (int) ($row['canonical_person_salesforce_id'] === null);
            }
            $counters['functional_date_changed'] += $functionalDateChanges;
            $stage = 'checkpoint_update';
            $updated = DB::table('salesforce_interest_monthly_materializations')->where('id', $run->id)->where('lease_token', $lease)->where('status', 'running')->update([
                'checkpoint_salesforce_id' => $last,
                'pages_completed' => DB::raw('pages_completed + 1'),
                'counters' => json_encode($counters, JSON_THROW_ON_ERROR),
                'heartbeat_at' => now('UTC'), 'last_checkpoint_at' => now('UTC'), 'updated_at' => now(),
            ]);
            if ($updated !== 1) {
                throw new MonthlyInterestRecoveryLeaseLost;
            }
        });
    }

    private function complete(object $run, string $lease): void
    {
        DB::transaction(function () use ($run, $lease): void {
            $this->owned((int) $run->id, $lease, true);
            $counters = $this->counters($run);
            $source = $this->sourceSnapshot((int) $run->monthly_recovery_id);
            $sourceEvidence = json_decode($run->source_evidence, true, 64, JSON_THROW_ON_ERROR);
            $sourceMatches = $source['rows'] === $sourceEvidence['source_rows'] && hash_equals($sourceEvidence['source_sha256'], $source['sha256']);
            $manifest = $this->manifest((int) $run->id);
            $reuseId = $sourceEvidence['reuse_materialization_id'] ?? null;
            $reuseMatches = $reuseId === null || ($this->manifest((int) $reuseId) === ($sourceEvidence['reuse_manifest'] ?? null)
                && $this->sourceSnapshot((int) $sourceEvidence['reuse_recovery_id']) === ($sourceEvidence['reuse_source_snapshot'] ?? null));
            $verified = $sourceMatches && $reuseMatches && $counters['expected'] === $counters['requested'] && $counters['missing'] === 0
                && $counters['unexpected'] === 0 && $counters['duplicates'] === 0 && $counters['content_changed'] === 0 && $counters['functional_date_changed'] === 0;
            $evidence = [
                'source' => 'current_state_monthly_f2_materialization',
                'manifest' => $manifest,
                'verification' => [
                    'verified' => $verified,
                    'source_f2_unchanged' => $sourceMatches,
                    'reuse_source_unchanged' => $reuseMatches,
                    'expected' => $counters['expected'], 'materialized' => $counters['materialized'],
                    'missing' => $counters['missing'], 'unexpected' => $counters['unexpected'], 'duplicates' => $counters['duplicates'],
                    'content_changed' => $counters['content_changed'], 'functional_date_changed' => $counters['functional_date_changed'],
                ],
                'hard_deletes' => 'not_observable_without_independent_evidence',
                'historical_certification' => false,
                'published' => false,
            ];
            $updated = DB::table('salesforce_interest_monthly_materializations')->where('id', $run->id)->where('lease_token', $lease)->where('status', 'running')->update([
                'phase' => 'completed', 'checkpoint_salesforce_id' => null, 'counters' => json_encode($counters, JSON_THROW_ON_ERROR),
                'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR), 'heartbeat_at' => now('UTC'), 'last_checkpoint_at' => now('UTC'), 'updated_at' => now(),
            ]);
            if ($updated !== 1) {
                throw new MonthlyInterestRecoveryLeaseLost;
            }
        });
    }

    /** @param array<string,mixed> $mapped @return array<string,mixed> */
    private function materializedRow(object $run, object $source, array $mapped, string $hash): array
    {
        foreach (['salesforce_created_at', 'salesforce_last_modified_at', 'functional_created_at'] as $field) {
            if (blank($mapped[$field] ?? null)) {
                throw new RuntimeException('Monthly F2 materialization record lacks a required functional date.');
            }
        }

        return [
            'monthly_materialization_id' => $run->id, 'monthly_recovery_row_id' => $source->id, 'salesforce_id' => $mapped['salesforce_id'],
            'source_content_hash' => $source->content_hash, 'content_hash' => $hash, 'content_matches_inventory' => hash_equals($source->content_hash, $hash),
            'is_deleted' => (bool) $mapped['is_deleted'], 'salesforce_deleted_at' => $mapped['salesforce_deleted_at'], 'deletion_detection_source' => $mapped['deletion_detection_source'],
            'lead_salesforce_id' => $mapped['lead_salesforce_id'], 'account_salesforce_id' => $mapped['account_salesforce_id'], 'migration_origin_lead_id' => $mapped['migration_origin_lead_id'],
            'salesforce_created_at' => $mapped['salesforce_created_at'], 'salesforce_last_modified_at' => $mapped['salesforce_last_modified_at'], 'system_modstamp_at' => $mapped['system_modstamp_at'],
            'origin_created_at' => $mapped['origin_created_at'], 'functional_created_at' => $mapped['functional_created_at'],
            'owner_salesforce_id' => $mapped['owner_salesforce_id'], 'owner_name' => $mapped['owner_name'], 'status' => $mapped['status'], 'type' => $mapped['type'],
            'source' => $mapped['source'], 'original_source' => $mapped['original_source'], 'medium' => $mapped['medium'], 'channel' => $mapped['channel'], 'origin_delegation' => $mapped['origin_delegation'],
            'utm_campaign' => $mapped['utm_campaign'], 'utm_id' => $mapped['utm_id'], 'utm_source' => $mapped['utm_source'], 'utm_medium' => $mapped['utm_medium'], 'utm_content' => $mapped['utm_content'], 'utm_term' => $mapped['utm_term'],
            'sale_vehicle_salesforce_id' => $mapped['sale_vehicle_salesforce_id'], 'appraisal_vehicle_salesforce_id' => $mapped['appraisal_vehicle_salesforce_id'], 'inverse_opportunity_salesforce_id' => $mapped['inverse_opportunity_salesforce_id'],
            'canonical_person_type' => $mapped['canonical_person_type'], 'canonical_person_salesforce_id' => $mapped['canonical_person_salesforce_id'],
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    /** @return array{rows:int,sha256:string} */
    private function sourceSnapshot(int $monthlyRecoveryId): array
    {
        $hash = hash_init('sha256');
        $rows = 0;
        DB::table('salesforce_interest_monthly_recovery_rows')->where('monthly_recovery_id', $monthlyRecoveryId)->orderBy('salesforce_id')->chunk(500, function ($items) use ($hash, &$rows): void {
            foreach ($items as $item) {
                hash_update($hash, $item->salesforce_id.'|'.$item->content_hash.'|'.(int) $item->is_deleted."\n");
                $rows++;
            }
        });

        return ['rows' => $rows, 'sha256' => hash_final($hash)];
    }

    private function claim(int $id, string $reason, bool $resume): string
    {
        return DB::transaction(function () use ($id, $reason, $resume): string {
            $run = DB::table('salesforce_interest_monthly_materializations')->where('id', $id)->lockForUpdate()->first();
            if ($run === null || ! hash_equals((string) $run->reason_hash, hash('sha256', trim($reason)))
                || ($run->status === 'running' && $this->live($run)) || (! $resume && $run->status !== 'prepared')
                || ($resume && ! in_array($run->status, ['prepared', 'failed', 'running'], true))) {
                throw new RuntimeException('Monthly F2 materialization is not compatible or safely resumable.');
            }
            $lease = (string) Str::uuid();
            DB::table('salesforce_interest_monthly_materializations')->where('id', $id)->update([
                'status' => 'running', 'lease_token' => $lease, 'heartbeat_at' => now('UTC'),
                'error_code' => null, 'error_stage' => null, 'error_meta' => null, 'failed_at' => null, 'updated_at' => now(),
            ]);

            return $lease;
        });
    }

    private function owned(int $id, string $lease, bool $lock = false): object
    {
        $query = DB::table('salesforce_interest_monthly_materializations')->where('id', $id)->where('lease_token', $lease)->where('status', 'running');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? throw new MonthlyInterestRecoveryLeaseLost;
    }

    private function renew(int $id, string $lease): void
    {
        $updated = DB::table('salesforce_interest_monthly_materializations')->where('id', $id)->where('lease_token', $lease)->where('status', 'running')->update([
            'heartbeat_at' => now('UTC'), 'updated_at' => now(),
        ]);
        if ($updated === 1 || DB::table('salesforce_interest_monthly_materializations')->where('id', $id)->where('lease_token', $lease)->where('status', 'running')->exists()) {
            return;
        }
        throw new MonthlyInterestRecoveryLeaseLost;
    }

    private function live(object $run): bool
    {
        return $run->heartbeat_at !== null && CarbonImmutable::parse($run->heartbeat_at, 'UTC')->gt(now('UTC')->subSeconds(self::LEASE_SECONDS));
    }

    /** @return array<string,int> */
    private function emptyCounters(int $expected): array
    {
        return ['expected' => $expected, 'requested' => 0, 'remote_received' => 0, 'reused' => 0, 'materialized' => 0, 'content_matching' => 0, 'content_changed' => 0, 'missing' => 0, 'unexpected' => 0, 'duplicates' => 0, 'soft_deleted_observed' => 0, 'functional_date_changed' => 0, 'lead_present' => 0, 'account_present' => 0, 'canonical_person_missing' => 0];
    }

    /** @return array<string,int> */
    private function counters(object $run): array
    {
        $stored = json_decode($run->counters, true, 64, JSON_THROW_ON_ERROR);

        return array_replace($this->emptyCounters((int) ($stored['expected'] ?? 0)), $stored);
    }

    private function recordFailure(int $id, string $lease, string $stage, Throwable $error): void
    {
        $category = $error instanceof MonthlyInterestRecoveryLeaseLost ? 'lease_lost' : ($error instanceof QueryException ? 'database_write_failed' : match ($stage) {
            'source_inventory_query' => 'source_inventory_query_failed', 'salesforce_query' => 'salesforce_query_failed',
            'mapping' => 'mapping_failed', 'record_validation' => 'invalid_record', 'content_hash' => 'hash_failed',
            'database_persist' => 'database_write_failed', 'checkpoint_update' => 'checkpoint_failed', default => 'unexpected_failure',
        });
        $meta = ['exception_type' => class_basename($error), 'pages_completed' => (int) DB::table('salesforce_interest_monthly_materializations')->where('id', $id)->value('pages_completed'), 'occurred_at' => now('UTC')->toIso8601String()];
        $updated = DB::table('salesforce_interest_monthly_materializations')->where('id', $id)->where('lease_token', $lease)->where('status', 'running')->update([
            'status' => 'failed', 'failed_at' => now('UTC'), 'error_code' => $category, 'error_stage' => $stage,
            'error_meta' => json_encode($meta, JSON_THROW_ON_ERROR), 'lease_token' => null, 'updated_at' => now(),
        ]);
        if ($updated === 1) {
            Log::warning('salesforce_interest_monthly_materialization_failed', ['materialization_id' => $id, 'stage' => $stage, 'category' => $category, 'exception_type' => $meta['exception_type'], 'pages_completed' => $meta['pages_completed']]);
        }
    }
}
