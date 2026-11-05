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

/** Builds an isolated present-state monthly inventory; it never certifies or publishes history. */
final class InterestMonthlyRecoveryService
{
    private const LEASE_SECONDS = 900;

    private const DEFAULT_BATCH_SIZE = 500;

    public function __construct(
        private readonly SalesforceClient $client,
        private readonly SalesforceInterestSyncService $interests,
    ) {}

    public function prepare(CarbonInterface $start, CarbonInterface $end, string $reason, int $batch = self::DEFAULT_BATCH_SIZE): int
    {
        $reason = trim($reason);
        $localStart = CarbonImmutable::parse($start, 'Europe/Madrid');
        $localEnd = CarbonImmutable::parse($end, 'Europe/Madrid');
        $start = $localStart->startOfDay()->utc();
        $end = $localEnd->startOfDay()->utc();
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500 || $localStart->day !== 1 || $localEnd->day !== 1
            || ! $localEnd->startOfDay()->equalTo($localStart->startOfDay()->addMonth()) || $batch < 50 || $batch > 1000) {
            throw new RuntimeException('Monthly recovery requires one bounded functional month, a reason, and a safe batch size.');
        }

        return DB::transaction(function () use ($start, $end, $reason, $batch): int {
            $existing = DB::table('salesforce_interest_monthly_recoveries')->where('functional_start_at', $start)->where('functional_end_at', $end)->lockForUpdate()->first();
            if ($existing !== null) {
                return (int) $existing->id;
            }

            return DB::table('salesforce_interest_monthly_recoveries')->insertGetId([
                'recovery_identifier' => (string) Str::uuid(), 'functional_start_at' => $start, 'functional_end_at' => $end,
                'timezone' => 'Europe/Madrid', 'status' => 'prepared', 'phase' => 'f2_extract', 'batch_size' => $batch,
                'pages_completed' => 0, 'counters' => json_encode($this->emptyCounters(), JSON_THROW_ON_ERROR),
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
                $r = $this->owned($id, $lease);
                if ($r->phase === 'f2_extract') {
                    $stage = 'salesforce_query';
                    $this->renew($id, $lease);
                    $records = $this->read($this->interests->functionalPeriodSoql(CarbonImmutable::parse($r->functional_start_at, 'UTC'), CarbonImmutable::parse($r->functional_end_at, 'UTC'), $r->checkpoint_salesforce_id, true)."\nLIMIT ".(int) $r->batch_size, (int) $r->batch_size, $id, $lease);
                    $this->renew($id, $lease);
                    $this->persistPage($r, $lease, $records, $stage);

                    continue;
                }
                if ($r->phase === 'verify') {
                    $this->verify($r, $lease, $stage);

                    continue;
                }
                if ($r->phase === 'completed') {
                    $stage = 'finalization';
                    DB::table('salesforce_interest_monthly_recoveries')->where('id', $id)->where('lease_token', $lease)->update(['status' => 'completed', 'completed_at' => now('UTC'), 'lease_token' => null, 'updated_at' => now()]);

                    return $this->status($id);
                }
                throw new RuntimeException('Monthly recovery checkpoint is not runnable.');
            }
        } catch (Throwable $e) {
            $this->recordFailure($id, $lease, $stage, $e);
            throw $e;
        }
    }

    public function cancel(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $r = DB::table('salesforce_interest_monthly_recoveries')->where('id', $id)->lockForUpdate()->first();
            if ($r === null || ($r->status === 'running' && $this->live($r)) || $r->status === 'completed') {
                throw new RuntimeException('Monthly recovery cannot be cancelled safely.');
            }
            DB::table('salesforce_interest_monthly_recoveries')->where('id', $id)->update(['status' => 'cancelled', 'phase' => 'cancelled', 'cancelled_at' => now('UTC'), 'lease_token' => null, 'updated_at' => now()]);
        });
    }

    /** @return array<string, mixed> */
    public function status(int $id): array
    {
        $r = DB::table('salesforce_interest_monthly_recoveries')->find($id) ?? throw new RuntimeException('Monthly recovery does not exist.');

        return ['recovery_id' => (int) $r->id, 'period' => ['start' => (string) $r->functional_start_at, 'end' => (string) $r->functional_end_at, 'timezone' => $r->timezone], 'status' => $r->status, 'phase' => $r->phase, 'checkpoint_present' => $r->checkpoint_salesforce_id !== null, 'pages_completed' => (int) $r->pages_completed, 'counters' => json_decode($r->counters, true, 64, JSON_THROW_ON_ERROR), 'evidence' => $r->evidence === null ? null : json_decode($r->evidence, true, 64, JSON_THROW_ON_ERROR), 'diagnostic' => $r->error_code === null ? null : ['stage' => $r->error_stage, 'category' => $r->error_code, 'meta' => $r->error_meta === null ? [] : json_decode($r->error_meta, true, 16, JSON_THROW_ON_ERROR)], 'f5_status' => 'not_attempted_blocked', 'historical_certification' => 'unavailable'];
    }

    /** @return list<array<string, mixed>> */
    private function read(string $soql, int $limit, int $id, string $lease): array
    {
        $rows = [];
        foreach ($this->client->queryPages($soql, true) as $page) {
            $this->renew($id, $lease);
            foreach ($page as $row) {
                $rows[] = $row;
            }
            if (count($rows) > $limit) {
                throw new RuntimeException('Monthly recovery response exceeded its configured bound.');
            }
        }

        return $rows;
    }

    /** @param list<array<string, mixed>> $records */
    private function persistPage(object $r, string $lease, array $records, string &$stage): void
    {
        if (count($records) > (int) $r->batch_size) {
            throw new RuntimeException('Monthly recovery batch exceeded its configured bound.');
        }
        $rows = [];
        $last = $r->checkpoint_salesforce_id;
        foreach ($records as $record) {
            $stage = 'mapping';
            $mapped = $this->interests->mapRecord($record, (bool) data_get($record, 'IsDeleted', false));
            $id = (string) $mapped['salesforce_id'];
            $functional = CarbonImmutable::parse($mapped['functional_created_at'], 'UTC');
            $stage = 'record_validation';
            if (preg_match('/^[A-Za-z0-9]{18}$/', $id) !== 1 || ($last !== null && strcmp($id, $last) <= 0) || $functional->lt(CarbonImmutable::parse($r->functional_start_at, 'UTC')) || $functional->gte(CarbonImmutable::parse($r->functional_end_at, 'UTC'))) {
                throw new RuntimeException('Monthly recovery received invalid, unordered, or out-of-period content.');
            }
            $last = $id;
            $stage = 'content_hash';
            $rows[] = ['monthly_recovery_id' => $r->id, 'salesforce_id' => $id, 'content_hash' => $this->contentHash($mapped), 'is_deleted' => (bool) $mapped['is_deleted'], 'functional_created_at' => $functional, 'system_modstamp_at' => $mapped['system_modstamp_at'], 'created_at' => now(), 'updated_at' => now()];
        }
        DB::transaction(function () use ($r, $lease, $rows, $records, $last, &$stage): void {
            $this->owned((int) $r->id, $lease, true);
            if ($rows !== []) {
                $stage = 'database_persist';
                DB::table('salesforce_interest_monthly_recovery_rows')->upsert($rows, ['monthly_recovery_id', 'salesforce_id'], ['content_hash', 'is_deleted', 'functional_created_at', 'system_modstamp_at', 'updated_at']);
            }
            $next = count($records) === (int) $r->batch_size ? 'f2_extract' : 'verify';
            $c = $this->counters($r);
            $c['observed'] += count($records);
            $c['persisted'] += count($rows);
            $c['soft_deleted_observed'] += count(array_filter($rows, fn (array $row): bool => $row['is_deleted']));
            $stage = 'checkpoint_update';
            DB::table('salesforce_interest_monthly_recoveries')->where('id', $r->id)->where('lease_token', $lease)->update(['phase' => $next, 'checkpoint_salesforce_id' => $next === 'f2_extract' ? $last : null, 'pages_completed' => DB::raw('pages_completed + 1'), 'counters' => json_encode($c, JSON_THROW_ON_ERROR), 'extraction_started_at' => $r->extraction_started_at ?? now('UTC'), 'heartbeat_at' => now('UTC'), 'last_checkpoint_at' => now('UTC'), 'updated_at' => now()]);
        });
    }

    private function verify(object $r, string $lease, string &$stage): void
    {
        $counters = $this->counters($r);
        $cursor = $counters['verify_checkpoint'] ?? null;
        $this->renew((int) $r->id, $lease);
        $stage = 'verification_query';
        $records = $this->read($this->interests->functionalPeriodSoql(CarbonImmutable::parse($r->functional_start_at, 'UTC'), CarbonImmutable::parse($r->functional_end_at, 'UTC'), $cursor, true)."\nLIMIT ".(int) $r->batch_size, (int) $r->batch_size, (int) $r->id, $lease);
        $last = $cursor;
        DB::transaction(function () use ($r, $lease, $records, &$counters, &$last, &$stage): void {
            $this->owned((int) $r->id, $lease, true);
            foreach ($records as $record) {
                $stage = 'verification_mapping';
                $mapped = $this->interests->mapRecord($record, (bool) data_get($record, 'IsDeleted', false));
                $id = (string) $mapped['salesforce_id'];
                if (($last !== null && strcmp($id, $last) <= 0) || preg_match('/^[A-Za-z0-9]{18}$/', $id) !== 1) {
                    throw new RuntimeException('Monthly verification cursor is invalid.');
                }
                $last = $id;
                $local = DB::table('salesforce_interest_monthly_recovery_rows')->where('monthly_recovery_id', $r->id)->where('salesforce_id', $id)->first();
                $stage = 'verification_hash';
                $hash = $this->contentHash($mapped);
                $counters['remote_checked']++;
                if ($local === null) {
                    $counters['remote_missing_local']++;
                } else {
                    if (! hash_equals($local->content_hash, $hash)) {
                        $counters['content_changed']++;
                    }
                    $stage = 'verification_database';
                    DB::table('salesforce_interest_monthly_recovery_rows')->where('id', $local->id)->update(['verified_at' => now('UTC'), 'updated_at' => now()]);
                }
            }
            $terminal = count($records) < (int) $r->batch_size;
            if ($terminal) {
                $localOnly = DB::table('salesforce_interest_monthly_recovery_rows')->where('monthly_recovery_id', $r->id)->whereNull('verified_at')->count();
                $counters['local_missing_remote'] += $localOnly;
                $verified = $counters['remote_missing_local'] === 0 && $counters['local_missing_remote'] === 0 && $counters['content_changed'] === 0;
                $evidence = ['source' => 'current_state_monthly_inventory', 'rows' => $counters['persisted'], 'verification' => ['verified' => $verified, 'remote_checked' => $counters['remote_checked'], 'remote_missing_local' => $counters['remote_missing_local'], 'local_missing_remote' => $counters['local_missing_remote'], 'content_changed' => $counters['content_changed']], 'hard_deletes' => 'not_observable_without_independent_evidence', 'f5' => 'not_attempted_blocked', 'historical_certification' => false];
                $phase = 'completed';
            } else {
                $counters['verify_checkpoint'] = $last;
                $evidence = null;
                $phase = 'verify';
            }
            $stage = 'verification_database';
            DB::table('salesforce_interest_monthly_recoveries')->where('id', $r->id)->where('lease_token', $lease)->update(['phase' => $phase, 'counters' => json_encode($counters, JSON_THROW_ON_ERROR), 'evidence' => $evidence === null ? $r->evidence : json_encode($evidence, JSON_THROW_ON_ERROR), 'heartbeat_at' => now('UTC'), 'last_checkpoint_at' => now('UTC'), 'updated_at' => now()]);
        });
    }

    private function claim(int $id, string $reason, bool $resume): string
    {
        return DB::transaction(function () use ($id, $reason, $resume): string {
            $r = DB::table('salesforce_interest_monthly_recoveries')->where('id', $id)->lockForUpdate()->first();
            if ($r === null || ! hash_equals((string) $r->reason_hash, hash('sha256', trim($reason)))
                || ($r->status === 'running' && $this->live($r)) || (! $resume && $r->status !== 'prepared')
                || ($resume && ! in_array($r->status, ['prepared', 'failed', 'running'], true))) {
                throw new RuntimeException('Monthly recovery is not compatible or safely resumable.');
            }
            $lease = (string) Str::uuid();
            DB::table('salesforce_interest_monthly_recoveries')->where('id', $id)->update([
                'status' => 'running', 'lease_token' => $lease, 'heartbeat_at' => now('UTC'),
                'error_code' => null, 'error_stage' => null, 'error_meta' => null, 'failed_at' => null, 'updated_at' => now(),
            ]);

            return $lease;
        });
    }

    private function owned(int $id, string $lease, bool $lock = false): object
    {
        $q = DB::table('salesforce_interest_monthly_recoveries')->where('id', $id)->where('lease_token', $lease);
        if ($lock) {
            $q->lockForUpdate();
        }

        return $q->first() ?? throw new RuntimeException('Monthly recovery lease was lost.');
    }

    private function live(object $r): bool
    {
        return $r->heartbeat_at !== null && CarbonImmutable::parse($r->heartbeat_at, 'UTC')->gt(now('UTC')->subSeconds(self::LEASE_SECONDS));
    }

    /** @return array<string,int> */
    private function emptyCounters(): array
    {
        return ['observed' => 0, 'persisted' => 0, 'soft_deleted_observed' => 0, 'remote_checked' => 0, 'remote_missing_local' => 0, 'local_missing_remote' => 0, 'content_changed' => 0, 'verify_checkpoint' => null];
    }

    /** @return array<string,int> */
    private function counters(object $r): array
    {
        return array_replace($this->emptyCounters(), json_decode($r->counters, true, 64, JSON_THROW_ON_ERROR));
    }

    private function renew(int $id, string $lease): void
    {
        if (DB::table('salesforce_interest_monthly_recoveries')->where('id', $id)->where('lease_token', $lease)->where('status', 'running')->update(['heartbeat_at' => now('UTC'), 'updated_at' => now()]) !== 1) {
            throw new RuntimeException('Monthly recovery lease was lost.');
        }
    }

    private function recordFailure(int $id, string $lease, string $stage, Throwable $e): void
    {
        $category = $e instanceof QueryException ? $this->databaseCategory($e) : match ($stage) {
            'salesforce_query', 'verification_query' => 'salesforce_query_failed',
            'mapping' => 'mapping_failed',
            'record_validation' => 'invalid_record',
            'content_hash' => 'hash_failed',
            'database_persist' => 'database_write_failed',
            'checkpoint_update' => 'checkpoint_failed',
            'verification_mapping' => 'verification_mapping_failed',
            'verification_hash' => 'verification_hash_failed',
            'verification_database' => 'verification_failed',
            'finalization' => 'checkpoint_failed',
            default => $e instanceof RuntimeException && $e->getMessage() === 'Monthly recovery lease was lost.' ? 'lease_lost' : 'unexpected_failure',
        };
        $meta = ['exception_type' => class_basename($e), 'pages_completed' => (int) DB::table('salesforce_interest_monthly_recoveries')->where('id', $id)->value('pages_completed'), 'occurred_at' => now('UTC')->toIso8601String()];
        if ($e instanceof QueryException && isset($e->errorInfo[0], $e->errorInfo[1])) {
            $meta['sql_state'] = substr((string) $e->errorInfo[0], 0, 5);
            $meta['driver_code'] = (int) $e->errorInfo[1];
        }
        $updated = DB::table('salesforce_interest_monthly_recoveries')->where('id', $id)->where('lease_token', $lease)->update(['status' => 'failed', 'failed_at' => now('UTC'), 'error_code' => $category, 'error_stage' => $stage, 'error_meta' => json_encode($meta, JSON_THROW_ON_ERROR), 'lease_token' => null, 'updated_at' => now()]);
        if ($updated === 1) {
            Log::warning('salesforce_interest_monthly_recovery_failed', ['recovery_id' => $id, 'stage' => $stage, 'category' => $category, 'exception_type' => $meta['exception_type'], 'pages_completed' => $meta['pages_completed'], 'occurred_at' => $meta['occurred_at']]);
        }
    }

    private function databaseCategory(QueryException $e): string
    {
        return in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true) ? 'database_lock_timeout' : 'database_write_failed';
    }

    /** @param array<string, mixed> $mapped */
    private function contentHash(array $mapped): string
    {
        unset($mapped['raw_payload'], $mapped['synced_at'], $mapped['created_at'], $mapped['updated_at']);

        return hash('sha256', json_encode($this->canonicalize($mapped), JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if ($value instanceof CarbonInterface) {
            return $value->utc()->toIso8601String();
        }
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
