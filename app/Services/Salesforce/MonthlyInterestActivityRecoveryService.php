<?php

namespace App\Services\Salesforce;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Materializes an isolated, current-state F5 journal for one completed monthly F2 recovery. */
final class MonthlyInterestActivityRecoveryService
{
    private const LEASE_SECONDS = 900;

    private const DEFAULT_BATCH_SIZE = 500;

    private const ID_PATTERN = '/^[A-Za-z0-9]{18}$/';

    public function __construct(private readonly SalesforceClient $client) {}

    public function prepare(int $monthlyRecoveryId, string $reason, int $batch = self::DEFAULT_BATCH_SIZE): int
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500 || $batch < 50 || $batch > 1000) {
            throw new RuntimeException('Monthly F5 requires a reason and a safe batch size.');
        }

        return DB::transaction(function () use ($monthlyRecoveryId, $reason, $batch): int {
            $source = DB::table('salesforce_interest_monthly_recoveries')->where('id', $monthlyRecoveryId)->lockForUpdate()->first();
            if ($source === null || $source->status !== 'completed' || $source->phase !== 'completed') {
                throw new RuntimeException('Monthly F5 requires a completed monthly F2 recovery.');
            }
            $existing = DB::table('salesforce_interest_monthly_activity_recoveries')->where('monthly_recovery_id', $monthlyRecoveryId)->lockForUpdate()->first();
            if ($existing !== null) {
                return (int) $existing->id;
            }
            $snapshot = $this->sourceSnapshot($monthlyRecoveryId);

            return DB::table('salesforce_interest_monthly_activity_recoveries')->insertGetId([
                'recovery_identifier' => (string) Str::uuid(), 'monthly_recovery_id' => $monthlyRecoveryId,
                'status' => 'prepared', 'phase' => 'task_extract', 'batch_size' => $batch,
                'pages_completed' => 0, 'counters' => json_encode($this->emptyCounters(), JSON_THROW_ON_ERROR),
                'source_evidence' => json_encode([
                    'monthly_recovery_id' => $monthlyRecoveryId,
                    'f2_verified' => (bool) data_get(json_decode((string) $source->evidence, true) ?: [], 'verification.verified', false),
                    'historical_certification' => false,
                    'source_rows' => $snapshot['rows'], 'source_sha256' => $snapshot['sha256'],
                ], JSON_THROW_ON_ERROR),
                'reason_hash' => hash('sha256', $reason), 'started_at' => now('UTC'), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    /** @return array<string, mixed> */
    public function run(int $id, string $reason, bool $resume = false): array
    {
        $lease = $this->claim($id, $reason, $resume);
        $stage = 'recovery_loop';
        try {
            while (true) {
                $run = $this->owned($id, $lease);
                if (in_array($run->phase, ['task_extract', 'event_extract'], true)) {
                    $stage = 'salesforce_query';
                    $kind = $run->phase === 'task_extract' ? 'Task' : 'Event';
                    $records = $this->read($this->soql($kind, $run->checkpoint_activity_id, (int) $run->batch_size), (int) $run->batch_size, $id, $lease);
                    $this->persistPage($run, $lease, $kind, $records, $stage);
                    continue;
                }
                if (in_array($run->phase, ['verify_task', 'verify_event'], true)) {
                    $stage = 'verification_query';
                    $kind = $run->phase === 'verify_task' ? 'Task' : 'Event';
                    $records = $this->read($this->soql($kind, $run->checkpoint_activity_id, (int) $run->batch_size), (int) $run->batch_size, $id, $lease);
                    $this->verifyPage($run, $lease, $kind, $records, $stage);
                    continue;
                }
                if ($run->phase === 'completed') {
                    $updated = DB::table('salesforce_interest_monthly_activity_recoveries')->where('id', $id)->where('lease_token', $lease)->where('status', 'running')->update(['status' => 'completed', 'completed_at' => now('UTC'), 'lease_token' => null, 'updated_at' => now()]);
                    if ($updated !== 1) {
                        throw new MonthlyInterestRecoveryLeaseLost;
                    }

                    return $this->status($id);
                }
                throw new RuntimeException('Monthly F5 checkpoint is not runnable.');
            }
        } catch (Throwable $e) {
            $this->recordFailure($id, $lease, $stage, $e);
            throw $e;
        }
    }

    public function cancel(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $run = DB::table('salesforce_interest_monthly_activity_recoveries')->where('id', $id)->lockForUpdate()->first();
            if ($run === null || $run->status === 'completed' || ($run->status === 'running' && $this->live($run))) {
                throw new RuntimeException('Monthly F5 cannot be cancelled safely.');
            }
            DB::table('salesforce_interest_monthly_activity_recoveries')->where('id', $id)->update(['status' => 'cancelled', 'phase' => 'cancelled', 'cancelled_at' => now('UTC'), 'lease_token' => null, 'updated_at' => now()]);
        });
    }

    /** @return array<string, mixed> */
    public function status(int $id): array
    {
        $run = DB::table('salesforce_interest_monthly_activity_recoveries')->find($id) ?? throw new RuntimeException('Monthly F5 does not exist.');

        return ['run_id' => (int) $run->id, 'monthly_recovery_id' => (int) $run->monthly_recovery_id, 'status' => $run->status, 'phase' => $run->phase, 'pages_completed' => (int) $run->pages_completed, 'counters' => json_decode($run->counters, true, 64, JSON_THROW_ON_ERROR), 'source_evidence' => json_decode($run->source_evidence, true, 64, JSON_THROW_ON_ERROR), 'evidence' => $run->evidence === null ? null : json_decode($run->evidence, true, 64, JSON_THROW_ON_ERROR), 'diagnostic' => $run->error_code === null ? null : ['stage' => $run->error_stage, 'category' => $run->error_code, 'meta' => $run->error_meta === null ? [] : json_decode($run->error_meta, true, 16, JSON_THROW_ON_ERROR)], 'historical_certification' => 'unavailable', 'published' => false];
    }

    /** @return array{rows:int,sha256:string} */
    public function manifest(int $id): array
    {
        $hash = hash_init('sha256');
        $rows = 0;
        DB::table('salesforce_interest_monthly_activity_rows')->where('monthly_activity_recovery_id', $id)->orderBy('activity_kind')->orderBy('activity_salesforce_id')->chunk(500, function ($items) use ($hash, &$rows): void {
            foreach ($items as $item) {
                hash_update($hash, $item->content_hash."\n");
                $rows++;
            }
        });

        return ['rows' => $rows, 'sha256' => hash_final($hash)];
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
                throw new RuntimeException('Monthly F5 response exceeded its configured bound.');
            }
        }

        return $rows;
    }

    /** @param list<array<string,mixed>> $records */
    private function persistPage(object $run, string $lease, string $kind, array $records, string &$stage): void
    {
        $mapped = $this->mapPage((int) $run->monthly_recovery_id, $kind, $records, $run->checkpoint_activity_id, $stage);
        $mapped['rows'] = array_map(function (array $row) use ($run): array {
            $row['monthly_activity_recovery_id'] = $run->id;

            return $row;
        }, $mapped['rows']);
        DB::transaction(function () use ($run, $lease, $kind, $records, $mapped, &$stage): void {
            $this->owned((int) $run->id, $lease, true);
            $stage = 'database_persist';
            if ($mapped['rows'] !== []) {
                DB::table('salesforce_interest_monthly_activity_rows')->upsert($mapped['rows'], ['monthly_activity_recovery_id', 'activity_kind', 'activity_salesforce_id'], ['interest_salesforce_id', 'who_salesforce_id', 'activity_is_deleted', 'interest_is_deleted', 'activity_date', 'start_datetime', 'salesforce_created_at', 'salesforce_last_modified_at', 'system_modstamp_at', 'content_hash', 'updated_at']);
            }
            $counters = $this->counters($run);
            $this->accumulate($counters, $kind, $records, $mapped);
            $terminal = count($records) < (int) $run->batch_size;
            $next = $terminal ? ($kind === 'Task' ? 'event_extract' : 'verify_task') : $run->phase;
            $stage = 'checkpoint_update';
            $updated = DB::table('salesforce_interest_monthly_activity_recoveries')->where('id', $run->id)->where('lease_token', $lease)->where('status', 'running')->update(['phase' => $next, 'checkpoint_activity_id' => $terminal ? null : $mapped['last'], 'pages_completed' => DB::raw('pages_completed + 1'), 'counters' => json_encode($counters, JSON_THROW_ON_ERROR), 'heartbeat_at' => now('UTC'), 'last_checkpoint_at' => now('UTC'), 'updated_at' => now()]);
            if ($updated !== 1) {
                throw new MonthlyInterestRecoveryLeaseLost;
            }
        });
    }

    /** @param list<array<string,mixed>> $records */
    private function verifyPage(object $run, string $lease, string $kind, array $records, string &$stage): void
    {
        $mapped = $this->mapPage((int) $run->monthly_recovery_id, $kind, $records, $run->checkpoint_activity_id, $stage);
        DB::transaction(function () use ($run, $lease, $kind, $records, $mapped, &$stage): void {
            $this->owned((int) $run->id, $lease, true);
            $counters = $this->counters($run);
            foreach ($mapped['rows'] as $row) {
                $stage = 'verification_database';
                $local = DB::table('salesforce_interest_monthly_activity_rows')->where('monthly_activity_recovery_id', $run->id)->where('activity_kind', $kind)->where('activity_salesforce_id', $row['activity_salesforce_id'])->first();
                $counters['remote_checked']++;
                if ($local === null) {
                    $counters['remote_missing_local']++;
                } elseif (hash_equals($local->content_hash, $row['content_hash']) === false) {
                    $counters['content_changed']++;
                } else {
                    DB::table('salesforce_interest_monthly_activity_rows')->where('id', $local->id)->update(['verified_at' => now('UTC'), 'updated_at' => now()]);
                }
            }
            $terminal = count($records) < (int) $run->batch_size;
            $next = $terminal ? ($kind === 'Task' ? 'verify_event' : 'completed') : $run->phase;
            $evidence = null;
            if ($terminal && $kind === 'Event') {
                $counters['local_missing_remote'] = DB::table('salesforce_interest_monthly_activity_rows')->where('monthly_activity_recovery_id', $run->id)->whereNull('verified_at')->count();
                $source = $this->sourceSnapshot((int) $run->monthly_recovery_id);
                $sourceEvidence = json_decode($run->source_evidence, true, 64, JSON_THROW_ON_ERROR);
                $sourceMatches = $source['rows'] === $sourceEvidence['source_rows'] && hash_equals($sourceEvidence['source_sha256'], $source['sha256']);
                $verified = $sourceMatches && $counters['remote_missing_local'] === 0 && $counters['local_missing_remote'] === 0 && $counters['content_changed'] === 0;
                $evidence = ['source' => 'current_state_monthly_f5_inventory', 'manifest' => $this->manifest((int) $run->id), 'verification' => ['verified' => $verified, 'source_f2_unchanged' => $sourceMatches, 'remote_checked' => $counters['remote_checked'], 'remote_missing_local' => $counters['remote_missing_local'], 'local_missing_remote' => $counters['local_missing_remote'], 'content_changed' => $counters['content_changed']], 'f2_verified_at_start' => (bool) $sourceEvidence['f2_verified'], 'combined_certified' => false, 'historical_certification' => false];
            }
            $stage = 'checkpoint_update';
            $updated = DB::table('salesforce_interest_monthly_activity_recoveries')->where('id', $run->id)->where('lease_token', $lease)->where('status', 'running')->update(['phase' => $next, 'checkpoint_activity_id' => $terminal ? null : $mapped['last'], 'pages_completed' => DB::raw('pages_completed + 1'), 'counters' => json_encode($counters, JSON_THROW_ON_ERROR), 'evidence' => $evidence === null ? $run->evidence : json_encode($evidence, JSON_THROW_ON_ERROR), 'heartbeat_at' => now('UTC'), 'last_checkpoint_at' => now('UTC'), 'updated_at' => now()]);
            if ($updated !== 1) {
                throw new MonthlyInterestRecoveryLeaseLost;
            }
        });
    }

    /** @param list<array<string,mixed>> $records @return array{rows:list<array<string,mixed>>,last:?string,unresolved:int} */
    private function mapPage(int $monthlyRecoveryId, string $kind, array $records, ?string $checkpoint, string &$stage): array
    {
        $interestById = $this->resolveInterests($monthlyRecoveryId, $records);
        $rows = [];
        $last = $checkpoint;
        $unresolved = 0;
        foreach ($records as $record) {
            $stage = 'mapping';
            $activityId = trim((string) data_get($record, 'Id'));
            if (preg_match(self::ID_PATTERN, $activityId) !== 1 || ($last !== null && strcmp($activityId, $last) <= 0)) {
                throw new RuntimeException('Monthly F5 received invalid or unordered activity content.');
            }
            $last = $activityId;
            $interestId = trim((string) data_get($record, 'WhatId'));
            $interest = $interestById[$interestId] ?? null;
            if ($interest === null) {
                $unresolved++;
                continue;
            }
            $stage = 'content_hash';
            $row = ['monthly_activity_recovery_id' => null, 'activity_kind' => $kind, 'activity_salesforce_id' => $activityId, 'interest_salesforce_id' => $interestId, 'who_salesforce_id' => $this->nullableId(data_get($record, 'WhoId')), 'activity_is_deleted' => (bool) data_get($record, 'IsDeleted', false), 'interest_is_deleted' => (bool) $interest->is_deleted, 'activity_date' => $kind === 'Task' ? $this->date(data_get($record, 'ActivityDate')) : null, 'start_datetime' => $kind === 'Event' ? $this->dateTime(data_get($record, 'StartDateTime')) : null, 'salesforce_created_at' => $this->dateTime(data_get($record, 'CreatedDate')), 'salesforce_last_modified_at' => $this->dateTime(data_get($record, 'LastModifiedDate')), 'system_modstamp_at' => $this->dateTime(data_get($record, 'SystemModstamp'))];
            $row['content_hash'] = $this->contentHash($row);
            $row['created_at'] = now();
            $row['updated_at'] = now();
            $rows[] = $row;
        }

        return ['rows' => $rows, 'last' => $last, 'unresolved' => $unresolved];
    }

    /** @param list<array<string,mixed>> $records @return array<string,object> */
    private function resolveInterests(int $monthlyRecoveryId, array $records): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn (array $record): string => trim((string) data_get($record, 'WhatId')), $records), fn (string $id): bool => preg_match(self::ID_PATTERN, $id) === 1)));
        $result = [];
        foreach (array_chunk($ids, self::DEFAULT_BATCH_SIZE) as $chunk) {
            foreach (DB::table('salesforce_interest_monthly_recovery_rows')->where('monthly_recovery_id', $monthlyRecoveryId)->whereIn('salesforce_id', $chunk)->get(['salesforce_id', 'is_deleted']) as $interest) {
                $result[$interest->salesforce_id] = $interest;
            }
        }

        return $result;
    }

    private function soql(string $kind, ?string $checkpoint, int $batch): string
    {
        $fields = $kind === 'Task' ? SalesforceInterestActivitySyncService::TASK_SOQL_FIELDS : SalesforceInterestActivitySyncService::EVENT_SOQL_FIELDS;
        $cursor = $checkpoint === null ? '' : " AND Id > '{$checkpoint}'";

        return "SELECT {$fields}\nFROM {$kind}\nWHERE What.Type = 'Interes__c'{$cursor}\nORDER BY Id ASC\nLIMIT {$batch}";
    }

    /** @param array<string,int> $counters @param list<array<string,mixed>> $records @param array{rows:list<array<string,mixed>>,last:?string,unresolved:int} $mapped */
    private function accumulate(array &$counters, string $kind, array $records, array $mapped): void
    {
        $counters[$kind === 'Task' ? 'tasks_queried' : 'events_queried'] += count($records);
        $counters['activities_related'] += count($mapped['rows']);
        $counters['relationships_unresolved'] += $mapped['unresolved'];
        $counters['discarded_other_period'] += $mapped['unresolved'];
        foreach ($mapped['rows'] as $row) {
            $counters[$row['activity_is_deleted'] ? 'activities_soft_deleted' : 'activities_active']++;
            if ($row['interest_is_deleted']) {
                $counters['interests_soft_deleted']++;
            }
        }
    }

    /** @return array{rows:int,sha256:string} */
    private function sourceSnapshot(int $monthlyRecoveryId): array
    {
        $hash = hash_init('sha256');
        $rows = 0;
        DB::table('salesforce_interest_monthly_recovery_rows')->where('monthly_recovery_id', $monthlyRecoveryId)->orderBy('salesforce_id')->chunk(500, function ($items) use ($hash, &$rows): void {
            foreach ($items as $item) {
                hash_update($hash, $item->salesforce_id.':'.(int) $item->is_deleted."\n");
                $rows++;
            }
        });

        return ['rows' => $rows, 'sha256' => hash_final($hash)];
    }

    private function claim(int $id, string $reason, bool $resume): string
    {
        return DB::transaction(function () use ($id, $reason, $resume): string {
            $run = DB::table('salesforce_interest_monthly_activity_recoveries')->where('id', $id)->lockForUpdate()->first();
            if ($run === null || hash_equals($run->reason_hash, hash('sha256', trim($reason))) === false || ($run->status === 'running' && $this->live($run)) || ($resume === false && $run->status !== 'prepared') || ($resume && in_array($run->status, ['prepared', 'failed', 'running'], true) === false)) {
                throw new RuntimeException('Monthly F5 is not compatible or safely resumable.');
            }
            $lease = (string) Str::uuid();
            DB::table('salesforce_interest_monthly_activity_recoveries')->where('id', $id)->update(['status' => 'running', 'lease_token' => $lease, 'heartbeat_at' => now('UTC'), 'error_code' => null, 'error_stage' => null, 'error_meta' => null, 'failed_at' => null, 'updated_at' => now()]);

            return $lease;
        });
    }

    private function owned(int $id, string $lease, bool $lock = false): object
    {
        $query = DB::table('salesforce_interest_monthly_activity_recoveries')->where('id', $id)->where('lease_token', $lease)->where('status', 'running');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? throw new MonthlyInterestRecoveryLeaseLost;
    }

    private function renew(int $id, string $lease): void
    {
        $updated = DB::table('salesforce_interest_monthly_activity_recoveries')->where('id', $id)->where('lease_token', $lease)->where('status', 'running')->update(['heartbeat_at' => now('UTC'), 'updated_at' => now()]);
        if ($updated === 1 || DB::table('salesforce_interest_monthly_activity_recoveries')->where('id', $id)->where('lease_token', $lease)->where('status', 'running')->exists()) {
            return;
        }
        throw new MonthlyInterestRecoveryLeaseLost;
    }

    private function live(object $run): bool
    {
        return $run->heartbeat_at !== null && CarbonImmutable::parse($run->heartbeat_at, 'UTC')->gt(now('UTC')->subSeconds(self::LEASE_SECONDS));
    }

    /** @return array<string,int> */
    private function emptyCounters(): array
    {
        return ['tasks_queried' => 0, 'events_queried' => 0, 'activities_related' => 0, 'activities_active' => 0, 'activities_soft_deleted' => 0, 'interests_soft_deleted' => 0, 'relationships_unresolved' => 0, 'discarded_other_period' => 0, 'duplicates' => 0, 'anomalies' => 0, 'remote_checked' => 0, 'remote_missing_local' => 0, 'local_missing_remote' => 0, 'content_changed' => 0];
    }

    /** @return array<string,int> */
    private function counters(object $run): array
    {
        return array_replace($this->emptyCounters(), json_decode($run->counters, true, 64, JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $row */
    private function contentHash(array $row): string
    {
        unset($row['monthly_activity_recovery_id'], $row['content_hash'], $row['created_at'], $row['updated_at'], $row['verified_at']);
        ksort($row);
        foreach ($row as $key => $value) {
            if ($value instanceof CarbonInterface) {
                $row[$key] = $value->utc()->toIso8601String();
            }
        }

        return hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
    }

    private function nullableId(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function date(mixed $value): ?string
    {
        return blank($value) ? null : CarbonImmutable::parse($value)->toDateString();
    }

    private function dateTime(mixed $value): ?CarbonImmutable
    {
        return blank($value) ? null : CarbonImmutable::parse($value, 'UTC')->utc();
    }

    private function recordFailure(int $id, string $lease, string $stage, Throwable $error): void
    {
        $category = $error instanceof MonthlyInterestRecoveryLeaseLost ? 'lease_lost' : ($error instanceof QueryException ? 'database_persist_failed' : match ($stage) {
            'salesforce_query', 'verification_query' => 'salesforce_query_failed', 'mapping' => 'mapping_failed', 'content_hash' => 'hash_failed', 'database_persist' => 'database_persist_failed', 'checkpoint_update' => 'checkpoint_failed', 'verification_database' => 'verification_database_failed', default => 'unexpected_failure',
        });
        $meta = ['exception_type' => class_basename($error), 'pages_completed' => (int) DB::table('salesforce_interest_monthly_activity_recoveries')->where('id', $id)->value('pages_completed'), 'occurred_at' => now('UTC')->toIso8601String()];
        $updated = DB::table('salesforce_interest_monthly_activity_recoveries')->where('id', $id)->where('lease_token', $lease)->where('status', 'running')->update(['status' => 'failed', 'failed_at' => now('UTC'), 'error_code' => $category, 'error_stage' => $stage, 'error_meta' => json_encode($meta, JSON_THROW_ON_ERROR), 'lease_token' => null, 'updated_at' => now()]);
        if ($updated === 1) {
            Log::warning('salesforce_interest_monthly_activity_recovery_failed', ['recovery_id' => $id, 'stage' => $stage, 'category' => $category, 'exception_type' => $meta['exception_type'], 'pages_completed' => $meta['pages_completed']]);
        }
    }
}
