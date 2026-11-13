<?php

namespace App\Services\Salesforce;

use App\Services\Reports\Leads\OperationalInterestIdentity;
use App\Services\Reports\Leads\OperationalInterestMonthlyReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Bounded Task/Event observation attached to exactly one operational Interest version. */
final class OperationalInterestActivitySync
{
    private const BLOCK = 250;

    private const PAGE = 500;

    private const MAX_QUERIES = 3000;

    private const MAX_PAGES = 3000;

    private const REMOTE_PAGE_REJECTION = 10;

    private const LEASE_SECONDS = 900;

    public function __construct(
        private readonly SalesforceClient $client,
        private readonly OperationalInterestMonthlyReader $reader,
        private readonly OperationalInterestIdentity $identity,
    ) {}

    public function prepare(string $month): int
    {
        if (preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', $month, $parts) !== 1) {
            throw new RuntimeException('Operational activity month is invalid.');
        }
        $source = $this->reader->latestForMonth((int) $parts[1], (int) $parts[2]);
        if (($source['overview']['interest_identity_key_id'] ?? null) !== $this->identity->keyId()) {
            throw new RuntimeException('Operational Interest projection identity key is incompatible.');
        }

        return DB::transaction(function () use ($month, $source): int {
            $pointer = DB::table('salesforce_interest_operational_months')->where('month_key', $month)->lockForUpdate()->first();
            $this->assertSource($pointer, $source['integrity_revision'], $source['projection_key']);
            $missingKeys = DB::table('salesforce_interest_october_provisional_projection_rows')
                ->where('scope', $source['scope'])->where('projection_key', $source['projection_key'])
                ->whereNull('interest_key')->exists();
            if ($missingKeys) {
                throw new RuntimeException('Operational Interest projection predates activity identity support.');
            }
            $pending = DB::table('salesforce_interest_operational_activity_runs')->where('month_key', $month)
                ->whereIn('status', ['prepared', 'running'])->lockForUpdate()->get();
            foreach ($pending as $prior) {
                if ($prior->status === 'prepared' || $this->live($prior)) {
                    throw new RuntimeException('Resume or inspect the existing operational activity run.');
                }
                DB::table('salesforce_interest_operational_activity_runs')->where('id', $prior->id)
                    ->update(['status' => 'failed', 'lease_token' => null,
                        'error_stage' => 'lease_expired', 'updated_at' => now()]);
            }

            return DB::table('salesforce_interest_operational_activity_runs')->insertGetId([
                'source_run_id' => $source['integrity_revision'], 'month_key' => $month,
                'status' => 'prepared', 'phase' => 'task_extract', 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    /** @return array<string, int|string|bool|null> */
    public function run(int $runId): array
    {
        $lease = $this->claim($runId);
        $stage = 'read';
        try {
            while (true) {
                $run = $this->owned($runId, $lease);
                $stage = $run->phase;
                if (in_array($run->phase, ['task_extract', 'event_extract', 'task_verify', 'event_verify'], true)) {
                    $this->assertSourceCurrent($run);
                    $block = $this->block((int) $run->source_run_id, $run->checkpoint_interest_id);
                    if ($block['ids'] === []) {
                        $this->advance($run, $lease);

                        continue;
                    }
                    $kind = str_starts_with($run->phase, 'task') ? 'Task' : 'Event';
                    $records = $this->read($run, $lease, $kind, $block['ids']);
                    $mapped = $this->map($run, $kind, $records, $block['ids']);
                    $this->persist($run, $lease, $mapped, $block['last']);

                    continue;
                }
                if ($run->phase === 'project') {
                    $this->project($run, $lease);

                    continue;
                }
                if ($run->phase === 'activate') {
                    $this->activate($run, $lease);

                    return $this->status($runId);
                }
                throw new RuntimeException('Operational activity phase is invalid.');
            }
        } catch (Throwable) {
            DB::table('salesforce_interest_operational_activity_runs')->where('id', $runId)
                ->where('lease_token', $lease)->where('status', 'running')
                ->update(['status' => 'failed', 'error_stage' => $stage, 'lease_token' => null, 'updated_at' => now()]);
            throw new RuntimeException('Operational activity run stopped; inspect safe status before resuming.');
        }
    }

    /** @return array<string, int|string|bool|array<string,int>|null> */
    public function status(int $runId): array
    {
        $run = DB::table('salesforce_interest_operational_activity_runs')->find($runId);
        if ($run === null) {
            throw new RuntimeException('Operational activity run does not exist.');
        }

        $pointer = DB::table('salesforce_interest_operational_months')->where('month_key', $run->month_key)->first();
        $source = DB::table('salesforce_interest_operational_runs')->find($run->source_run_id);
        $compatible = $pointer !== null && $source?->status === 'completed' && $source->phase === 'completed'
            && (int) $pointer->active_run_id === (int) $run->source_run_id
            && $pointer->active_projection_key === $source->projection_key;
        $reasons = ['new_during_verify' => 0, 'missing_on_verify' => 0, 'relation_changed' => 0];
        foreach (DB::table('salesforce_interest_operational_activity_rows')->where('run_id', $runId)
            ->whereNotNull('quarantined_at')->select('quarantine_reason')->selectRaw('COUNT(*) AS total')
            ->groupBy('quarantine_reason')->get() as $item) {
            if (array_key_exists($item->quarantine_reason, $reasons)) {
                $reasons[$item->quarantine_reason] = (int) $item->total;
            }
        }

        return ['run_id' => (int) $run->id, 'source_run_id' => (int) $run->source_run_id,
            'month' => $run->month_key, 'status' => $run->status, 'phase' => $run->phase,
            'extracted' => (int) $run->extracted_count, 'verified' => (int) $run->verified_count,
            'changed' => (int) $run->changed_count, 'pages' => (int) $run->pages_completed,
            'query_attempts' => (int) $run->query_attempts, 'error_stage' => $run->error_stage,
            'quarantined' => (int) $run->quarantined_count,
            'quarantine_reasons' => $reasons,
            'coverage_complete' => $run->coverage_complete === null ? null : (bool) $run->coverage_complete,
            'pointer_recorded' => (int) ($pointer->active_activity_run_id ?? 0) === (int) $run->id,
            'active' => $compatible && $run->status === 'completed' && $run->phase === 'completed'
                && (int) ($pointer->active_activity_run_id ?? 0) === (int) $run->id];
    }

    private function claim(int $runId): string
    {
        return DB::transaction(function () use ($runId): string {
            $run = DB::table('salesforce_interest_operational_activity_runs')->find($runId);
            if ($run === null || ! in_array($run->status, ['prepared', 'failed', 'running'], true)) {
                throw new RuntimeException('Operational activity run cannot be claimed.');
            }
            $pointer = DB::table('salesforce_interest_operational_months')
                ->where('month_key', $run->month_key)->lockForUpdate()->first();
            $source = DB::table('salesforce_interest_operational_runs')->find($run->source_run_id);
            if ($source?->status !== 'completed' || $source->phase !== 'completed') {
                throw new RuntimeException('Operational Interest source is incomplete.');
            }
            $this->assertSource($pointer, (int) $run->source_run_id, (string) $source?->projection_key);
            if ((int) ($pointer->active_activity_run_id ?? 0) >= $runId) {
                throw new RuntimeException('Operational activity run is superseded.');
            }
            $workers = DB::table('salesforce_interest_operational_activity_runs')->where('month_key', $run->month_key)
                ->where('status', 'running')->lockForUpdate()->get();
            if ($workers->contains(fn (object $worker): bool => $this->live($worker))) {
                throw new RuntimeException('Operational activity month already has a worker.');
            }
            foreach ($workers as $worker) {
                if ((int) $worker->id !== $runId) {
                    DB::table('salesforce_interest_operational_activity_runs')->where('id', $worker->id)
                        ->update(['status' => 'failed', 'lease_token' => null,
                            'error_stage' => 'lease_expired', 'updated_at' => now()]);
                }
            }
            $lease = (string) Str::uuid();
            DB::table('salesforce_interest_operational_activity_runs')->where('id', $runId)->update([
                'status' => 'running', 'lease_token' => $lease, 'heartbeat_at' => now('UTC'),
                'started_at' => $run->started_at ?? now('UTC'), 'error_stage' => null, 'updated_at' => now(),
            ]);

            return $lease;
        });
    }

    private function owned(int $runId, string $lease, bool $lock = false): object
    {
        $query = DB::table('salesforce_interest_operational_activity_runs')->where('id', $runId)
            ->where('lease_token', $lease)->where('status', 'running');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? throw new MonthlyInterestRecoveryLeaseLost;
    }

    private function live(object $run): bool
    {
        return $run->heartbeat_at !== null && CarbonImmutable::parse($run->heartbeat_at, 'UTC')
            ->gt(CarbonImmutable::now('UTC')->subSeconds(self::LEASE_SECONDS));
    }

    private function assertSource(?object $pointer, int $runId, string $projectionKey): void
    {
        if ($pointer === null || (int) $pointer->active_run_id !== $runId
            || $pointer->active_projection_key !== $projectionKey) {
            throw new RuntimeException('Operational Interest source version changed.');
        }
    }

    private function assertSourceCurrent(object $run): void
    {
        $source = DB::table('salesforce_interest_operational_runs')->find($run->source_run_id);
        if ($source?->status !== 'completed' || $source->phase !== 'completed') {
            throw new RuntimeException('Operational Interest source is incomplete.');
        }
        $pointer = DB::table('salesforce_interest_operational_months')->where('month_key', $run->month_key)->first();
        $this->assertSource($pointer, (int) $run->source_run_id, (string) $source?->projection_key);
    }

    /** @return array{ids:list<string>,last:string|null} */
    private function block(int $sourceRunId, ?string $after): array
    {
        $query = DB::table('salesforce_interest_operational_rows')->where('run_id', $sourceRunId)
            ->orderBy('salesforce_id')->limit(self::BLOCK);
        if ($after !== null) {
            $query->where('salesforce_id', '>', $after);
        }
        $ids = $query->pluck('salesforce_id')->all();

        return ['ids' => $ids, 'last' => $ids === [] ? null : last($ids)];
    }

    /** @return list<array<string,mixed>> */
    private function read(object $run, string $lease, string $kind, array $ids): array
    {
        if ($ids === [] || count($ids) > self::BLOCK
            || ($run->checkpoint_activity_id !== null && preg_match('/^[A-Za-z0-9]{18}$/', $run->checkpoint_activity_id) !== 1)) {
            throw new RuntimeException('Operational activity checkpoint is invalid.');
        }
        foreach ($ids as $id) {
            if (preg_match('/^[A-Za-z0-9]{18}$/', $id) !== 1) {
                throw new RuntimeException('Operational Interest block identity is invalid.');
            }
        }
        DB::transaction(function () use ($run, $lease): void {
            $current = $this->owned((int) $run->id, $lease, true);
            if ((int) $current->query_attempts >= self::MAX_QUERIES || (int) $current->pages_completed >= self::MAX_PAGES) {
                throw new RuntimeException('Operational activity query budget exhausted.');
            }
            DB::table('salesforce_interest_operational_activity_runs')->where('id', $run->id)->update([
                'query_attempts' => (int) $current->query_attempts + 1,
                'heartbeat_at' => now('UTC'), 'updated_at' => now(),
            ]);
        });
        $fields = $kind === 'Task' ? SalesforceInterestActivitySyncService::TASK_SOQL_FIELDS
            : SalesforceInterestActivitySyncService::EVENT_SOQL_FIELDS;
        $quoted = implode(', ', array_map(static fn (string $id): string => "'{$id}'", $ids));
        $cursor = $run->checkpoint_activity_id === null ? '' : " AND Id > '{$run->checkpoint_activity_id}'";
        $soql = "SELECT {$fields}\nFROM {$kind}\nWHERE WhatId IN ({$quoted}){$cursor}\nORDER BY Id ASC\nLIMIT ".self::PAGE;
        $records = [];
        $remotePages = 0;
        foreach ($this->client->queryPages($soql, true) as $page) {
            $this->renew((int) $run->id, $lease);
            if (++$remotePages >= self::REMOTE_PAGE_REJECTION) {
                throw new RuntimeException('Operational activity remote page budget exhausted.');
            }
            array_push($records, ...$page);
            if (count($records) > self::PAGE) {
                throw new RuntimeException('Operational activity page exceeded its bound.');
            }
        }

        return $records;
    }

    private function renew(int $runId, string $lease): void
    {
        $updated = DB::table('salesforce_interest_operational_activity_runs')->where('id', $runId)
            ->where('lease_token', $lease)->where('status', 'running')
            ->update(['heartbeat_at' => now('UTC'), 'updated_at' => now()]);
        if ($updated === 0) {
            $this->owned($runId, $lease);
        }
    }

    /** @return array{rows:list<array<string,mixed>>,last:string|null} */
    private function map(object $run, string $kind, array $records, array $interestIds): array
    {
        $allowed = array_fill_keys($interestIds, true);
        $rows = [];
        $last = $run->checkpoint_activity_id;
        foreach ($records as $record) {
            $id = trim((string) ($record['Id'] ?? ''));
            $interest = trim((string) ($record['WhatId'] ?? ''));
            if (preg_match('/^[A-Za-z0-9]{18}$/', $id) !== 1 || ! isset($allowed[$interest])
                || ($last !== null && strcmp($id, $last) <= 0)) {
                throw new RuntimeException('Operational activity identity or relation is invalid.');
            }
            $last = $id;
            $functional = null;
            if ($kind === 'Task' && filled($record['ActivityDate'] ?? null)) {
                $functional = CarbonImmutable::parse($record['ActivityDate'], 'Europe/Madrid')->startOfDay()->utc();
            } elseif ($kind === 'Event' && filled($record['StartDateTime'] ?? null)) {
                $functional = CarbonImmutable::parse($record['StartDateTime'], 'UTC')->utc();
            }
            $content = [
                'Id' => $id, 'WhatId' => $interest, 'WhoId' => $record['WhoId'] ?? null,
                'IsDeleted' => (bool) ($record['IsDeleted'] ?? false),
                'ActivityDate' => $record['ActivityDate'] ?? null,
                'StartDateTime' => $record['StartDateTime'] ?? null,
                'CreatedDate' => $record['CreatedDate'] ?? null,
                'LastModifiedDate' => $record['LastModifiedDate'] ?? null,
                'SystemModstamp' => $record['SystemModstamp'] ?? null,
            ];
            $rows[] = [
                'run_id' => $run->id, 'activity_kind' => $kind,
                'activity_salesforce_id' => $id, 'interest_salesforce_id' => $interest,
                'is_deleted' => $content['IsDeleted'], 'functional_activity_at' => $functional,
                'content_hash' => hash('sha256', json_encode($content, JSON_THROW_ON_ERROR)),
                'verified_at' => null, 'quarantined_at' => null, 'quarantine_reason' => null,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }

        return ['rows' => $rows, 'last' => $last];
    }

    /** @param array{rows:list<array<string,mixed>>,last:string|null} $mapped */
    private function persist(object $run, string $lease, array $mapped, string $blockLast): void
    {
        DB::transaction(function () use ($run, $lease, $mapped, $blockLast): void {
            $current = $this->owned((int) $run->id, $lease, true);
            if ($current->phase !== $run->phase || $current->checkpoint_interest_id !== $run->checkpoint_interest_id
                || $current->checkpoint_activity_id !== $run->checkpoint_activity_id) {
                throw new MonthlyInterestRecoveryLeaseLost;
            }
            $verify = str_ends_with($run->phase, '_verify');
            $changed = 0;
            $verified = 0;
            $newlyQuarantined = 0;
            $extracted = 0;
            if ($verify) {
                $kind = str_starts_with($run->phase, 'task') ? 'Task' : 'Event';
                $ids = array_column($mapped['rows'], 'activity_salesforce_id');
                $stored = $ids === [] ? collect() : DB::table('salesforce_interest_operational_activity_rows')
                    ->where('run_id', $run->id)->where('activity_kind', $kind)
                    ->whereIn('activity_salesforce_id', $ids)->get()->keyBy('activity_salesforce_id');
                $updatedRows = [];
                $newRows = [];
                $movedIds = [];
                foreach ($mapped['rows'] as $row) {
                    $previous = $stored->get($row['activity_salesforce_id']);
                    if ($previous === null) {
                        $row['quarantined_at'] = now('UTC');
                        $row['quarantine_reason'] = 'new_during_verify';
                        $newRows[] = $row;

                        continue;
                    }
                    if ($previous->interest_salesforce_id !== $row['interest_salesforce_id']) {
                        if ($previous->quarantined_at === null) {
                            $movedIds[] = $row['activity_salesforce_id'];
                            if ($previous->verified_at !== null) {
                                $verified--;
                            }
                        }

                        continue;
                    }
                    if ($previous->quarantined_at !== null) {
                        continue;
                    }
                    if (! hash_equals($previous->content_hash, $row['content_hash'])) {
                        $changed++;
                    }
                    $row['verified_at'] = now('UTC');
                    $updatedRows[] = $row;
                    $verified++;
                }
                if ($updatedRows !== []) {
                    DB::table('salesforce_interest_operational_activity_rows')->upsert($updatedRows,
                        ['run_id', 'activity_kind', 'activity_salesforce_id'],
                        ['is_deleted', 'functional_activity_at', 'content_hash', 'verified_at', 'updated_at']);
                }
                if ($newRows !== []) {
                    DB::table('salesforce_interest_operational_activity_rows')->insert($newRows);
                    $newlyQuarantined += count($newRows);
                }
                if ($movedIds !== []) {
                    $newlyQuarantined += DB::table('salesforce_interest_operational_activity_rows')->where('run_id', $run->id)
                        ->where('activity_kind', $kind)->whereIn('activity_salesforce_id', $movedIds)
                        ->whereNull('quarantined_at')
                        ->update(['quarantined_at' => now('UTC'), 'quarantine_reason' => 'relation_changed',
                            'verified_at' => null, 'updated_at' => now()]);
                }
            } elseif ($mapped['rows'] !== []) {
                $ids = array_column($mapped['rows'], 'activity_salesforce_id');
                $existing = DB::table('salesforce_interest_operational_activity_rows')->where('run_id', $run->id)
                    ->where('activity_kind', str_starts_with($run->phase, 'task') ? 'Task' : 'Event')
                    ->whereIn('activity_salesforce_id', $ids)->get()->keyBy('activity_salesforce_id');
                $insertRows = [];
                $movedIds = [];
                foreach ($mapped['rows'] as $row) {
                    $prior = $existing->get($row['activity_salesforce_id']);
                    if ($prior === null) {
                        $insertRows[] = $row;
                    } elseif ($prior->interest_salesforce_id !== $row['interest_salesforce_id']) {
                        $movedIds[] = $row['activity_salesforce_id'];
                    } else {
                        throw new RuntimeException('Operational activity identity was repeated in extraction.');
                    }
                }
                if ($insertRows !== []) {
                    DB::table('salesforce_interest_operational_activity_rows')->insert($insertRows);
                    $extracted = count($insertRows);
                }
                if ($movedIds !== []) {
                    $newlyQuarantined += DB::table('salesforce_interest_operational_activity_rows')->where('run_id', $run->id)
                        ->where('activity_kind', str_starts_with($run->phase, 'task') ? 'Task' : 'Event')
                        ->whereIn('activity_salesforce_id', $movedIds)->whereNull('quarantined_at')
                        ->update(['quarantined_at' => now('UTC'), 'quarantine_reason' => 'relation_changed',
                            'updated_at' => now()]);
                }
            }
            $terminal = count($mapped['rows']) < self::PAGE;
            if ($verify && $terminal) {
                $blockIds = $this->block((int) $run->source_run_id, $run->checkpoint_interest_id)['ids'];
                $newlyQuarantined += DB::table('salesforce_interest_operational_activity_rows')->where('run_id', $run->id)
                    ->where('activity_kind', $kind)->whereIn('interest_salesforce_id', $blockIds)
                    ->whereNull('verified_at')->whereNull('quarantined_at')
                    ->update(['quarantined_at' => now('UTC'), 'quarantine_reason' => 'missing_on_verify',
                        'updated_at' => now()]);
            }
            DB::table('salesforce_interest_operational_activity_runs')->where('id', $run->id)->update([
                'checkpoint_activity_id' => $terminal ? null : $mapped['last'],
                'checkpoint_interest_id' => $terminal ? $blockLast : $run->checkpoint_interest_id,
                'pages_completed' => (int) $current->pages_completed + 1,
                'extracted_count' => (int) $current->extracted_count + $extracted,
                'verified_count' => (int) $current->verified_count + $verified,
                'changed_count' => (int) $current->changed_count + $changed,
                'quarantined_count' => (int) $current->quarantined_count + $newlyQuarantined,
                'heartbeat_at' => now('UTC'), 'updated_at' => now(),
            ]);
        });
    }

    private function advance(object $run, string $lease): void
    {
        $next = match ($run->phase) {
            'task_extract' => 'event_extract',
            'event_extract' => 'task_verify',
            'task_verify' => 'event_verify',
            'event_verify' => 'project',
            default => throw new RuntimeException('Operational activity phase cannot advance.'),
        };
        DB::transaction(function () use ($run, $lease, $next): void {
            $current = $this->owned((int) $run->id, $lease, true);
            if ($current->phase !== $run->phase) {
                throw new MonthlyInterestRecoveryLeaseLost;
            }
            if ($run->phase === 'event_verify') {
                if (DB::table('salesforce_interest_operational_activity_rows')->where('run_id', $run->id)
                    ->whereNull('verified_at')->whereNull('quarantined_at')->exists()) {
                    throw new RuntimeException('Operational activity verification is incomplete.');
                }
            }
            DB::table('salesforce_interest_operational_activity_runs')->where('id', $run->id)->update([
                'phase' => $next, 'checkpoint_interest_id' => null, 'checkpoint_activity_id' => null,
                'heartbeat_at' => now('UTC'), 'updated_at' => now(),
            ]);
        });
    }

    private function project(object $run, string $lease): void
    {
        $this->assertIdentityKey($run);
        $query = DB::table('salesforce_interest_operational_activity_rows')->where('run_id', $run->id)
            ->where('is_deleted', false)->whereNull('quarantined_at')->whereNotNull('verified_at');
        if ($run->project_cursor_interest_id !== null) {
            $query->where('interest_salesforce_id', '>', $run->project_cursor_interest_id);
        }
        $groups = $query->select('interest_salesforce_id')->selectRaw('COUNT(*) as activities')
            ->selectRaw('SUM(CASE WHEN functional_activity_at IS NULL THEN 1 ELSE 0 END) as undated')
            ->selectRaw('MIN(functional_activity_at) as first_activity_at')
            ->selectRaw('MAX(functional_activity_at) as last_activity_at')
            ->groupBy('interest_salesforce_id')->orderBy('interest_salesforce_id')->limit(self::PAGE)->get();
        if ($groups->isEmpty()) {
            DB::transaction(function () use ($run, $lease): void {
                $current = $this->owned((int) $run->id, $lease, true);
                if ($current->phase !== 'project') {
                    throw new MonthlyInterestRecoveryLeaseLost;
                }
                DB::table('salesforce_interest_operational_activity_runs')->where('id', $run->id)
                    ->update(['phase' => 'activate', 'heartbeat_at' => now('UTC'), 'updated_at' => now()]);
            });

            return;
        }
        $summaries = [];
        foreach ($groups as $group) {
            $summaries[] = [
                'run_id' => $run->id,
                'interest_key' => $this->identity->key((int) $run->source_run_id, $group->interest_salesforce_id),
                'activities' => (int) $group->activities, 'undated' => (int) $group->undated,
                'first_activity_at' => $group->first_activity_at,
                'last_activity_at' => $group->last_activity_at,
            ];
        }
        DB::transaction(function () use ($run, $lease, $groups, $summaries): void {
            $current = $this->owned((int) $run->id, $lease, true);
            if ($current->phase !== 'project' || $current->project_cursor_interest_id !== $run->project_cursor_interest_id) {
                throw new MonthlyInterestRecoveryLeaseLost;
            }
            DB::table('salesforce_interest_operational_activity_summaries')->insert($summaries);
            DB::table('salesforce_interest_operational_activity_runs')->where('id', $run->id)->update([
                'project_cursor_interest_id' => $groups->last()->interest_salesforce_id,
                'heartbeat_at' => now('UTC'), 'updated_at' => now(),
            ]);
        });
    }

    private function activate(object $run, string $lease): void
    {
        DB::transaction(function () use ($run, $lease): void {
            $pointer = DB::table('salesforce_interest_operational_months')
                ->where('month_key', $run->month_key)->lockForUpdate()->first();
            $source = DB::table('salesforce_interest_operational_runs')->find($run->source_run_id);
            if ($source?->status !== 'completed' || $source->phase !== 'completed') {
                throw new RuntimeException('Operational Interest source is incomplete.');
            }
            $this->assertSource($pointer, (int) $run->source_run_id, (string) $source?->projection_key);
            $this->assertIdentityKey($run);
            if ((int) ($pointer->active_activity_run_id ?? 0) >= (int) $run->id) {
                throw new RuntimeException('Operational activity run is superseded.');
            }
            $current = $this->owned((int) $run->id, $lease, true);
            if ($current->phase !== 'activate'
                || DB::table('salesforce_interest_operational_activity_rows')->where('run_id', $run->id)
                    ->whereNull('verified_at')->whereNull('quarantined_at')->exists()) {
                throw new RuntimeException('Operational activity evidence is incomplete.');
            }
            $quarantined = DB::table('salesforce_interest_operational_activity_rows')->where('run_id', $run->id)
                ->whereNotNull('quarantined_at')->count();
            $verified = DB::table('salesforce_interest_operational_activity_rows')->where('run_id', $run->id)
                ->whereNotNull('verified_at')->whereNull('quarantined_at')->count();
            $total = DB::table('salesforce_interest_operational_activity_rows')->where('run_id', $run->id)->count();
            if ($verified !== (int) $current->verified_count || $quarantined !== (int) $current->quarantined_count
                || $verified + $quarantined !== $total) {
                throw new RuntimeException('Operational activity coverage counters are inconsistent.');
            }
            $summaryCount = DB::table('salesforce_interest_operational_activity_summaries')
                ->where('run_id', $run->id)->count();
            $activityInterests = DB::table('salesforce_interest_operational_activity_rows')->where('run_id', $run->id)
                ->where('is_deleted', false)->whereNull('quarantined_at')->whereNotNull('verified_at')
                ->distinct()->count('interest_salesforce_id');
            if ($summaryCount !== $activityInterests) {
                throw new RuntimeException('Operational activity projection is incomplete.');
            }
            DB::table('salesforce_interest_operational_activity_runs')->where('id', $run->id)->update([
                'status' => 'completed', 'phase' => 'completed', 'lease_token' => null,
                'coverage_complete' => $quarantined === 0,
                'completed_at' => now('UTC'), 'updated_at' => now(),
            ]);
            // A partial observation must not displace an earlier compatible version.
            $prior = $pointer->active_activity_run_id === null ? null
                : DB::table('salesforce_interest_operational_activity_runs')->find($pointer->active_activity_run_id);
            if ($quarantined === 0 || $prior?->source_run_id !== $run->source_run_id
                || $prior->status !== 'completed' || $prior->phase !== 'completed') {
                DB::table('salesforce_interest_operational_months')->where('month_key', $run->month_key)
                    ->update(['active_activity_run_id' => $run->id, 'updated_at' => now()]);
            }
        });
    }

    private function assertIdentityKey(object $run): void
    {
        $overview = DB::table('salesforce_interest_operational_runs')->where('id', $run->source_run_id)->value('overview');
        $source = is_string($overview) ? json_decode($overview, true, 64, JSON_THROW_ON_ERROR) : [];
        if (($source['interest_identity_key_id'] ?? null) !== $this->identity->keyId()) {
            throw new RuntimeException('Operational Interest projection identity key changed.');
        }
    }
}
