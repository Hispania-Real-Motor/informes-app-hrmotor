<?php

namespace App\Services\Salesforce;

use App\Models\ReportSyncRun;
use App\Services\Reports\ReportSyncRunService;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Operator-only, resumable current-state reconstruction. It never publishes. */
final class InterestInitialCurrentRecoveryService
{
    public const MAX_PASSES = 3;

    public const DEFAULT_BATCH_SIZE = 500;

    private const MAX_BATCH_SIZE = 1000;

    private const LEASE_SECONDS = 900;

    public function __construct(
        private readonly SalesforceClient $client,
        private readonly SalesforceInterestSyncService $interests,
        private readonly SalesforceInterestActivitySyncService $activities,
        private readonly InterestGenerationStore $store,
        private readonly ReportSyncRunService $runs,
    ) {}

    /** @return array<string,mixed> */
    public function recover(int $sourceId, string $reason, int $passes = self::MAX_PASSES, int $batch = self::DEFAULT_BATCH_SIZE): array
    {
        $this->validate($reason, $passes, $batch);
        $source = $this->store->generation($sourceId);
        $start = CarbonImmutable::parse($source->cutoff_at, 'UTC');
        $requested = now('UTC')->subMinutes(2)->startOfMinute();
        $preflight = $this->client->getDeleted('Interes__c', $start, $requested);
        $earliest = CarbonImmutable::parse($preflight['earliestDateAvailable'], 'UTC');
        $covered = CarbonImmutable::parse($preflight['latestDateCovered'], 'UTC')->startOfMinute();
        if ($earliest->gt($start) || $covered->lte($start)) {
            throw new RuntimeException('Salesforce deletion history does not cover the recovery source cutoff.');
        }
        $watermark = $covered->lt($requested) ? $covered : $requested;
        $hash = $this->configurationHash($sourceId, $reason, $passes, $batch);
        $existing = DB::table('salesforce_interest_initial_recoveries')->where('configuration_hash', $hash)
            ->whereNotIn('status', ['completed', 'cancelled'])->latest('id')->value('id');
        if ($existing !== null) {
            return $this->run((int) $existing, $reason, true);
        }
        $candidate = $this->store->beginInitialRecovery($sourceId, $watermark);
        $run = $this->runs->start(SalesforceInterestSyncService::DATASET, SalesforceInterestSyncService::SOURCE, $start, $watermark, 'UTC');
        try {
            $this->store->bindRun($candidate, $run);
            $id = DB::table('salesforce_interest_initial_recoveries')->insertGetId([
                'recovery_identifier' => (string) Str::uuid(), 'source_generation_id' => $sourceId,
                'candidate_generation_id' => $candidate, 'interest_run_id' => $run->id, 'status' => 'prepared',
                'phase' => 'deletion_log', 'max_reconciliation_passes' => $passes, 'reconciliation_pass' => 1,
                'batch_size' => $batch, 'source_cutoff_at' => $start, 'watermark_at' => $watermark,
                'counters' => json_encode($this->emptyCounters(), JSON_THROW_ON_ERROR), 'reason_hash' => hash('sha256', trim($reason)),
                'configuration_hash' => $hash, 'started_at' => now('UTC'), 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            $this->store->fail($candidate);
            $this->runs->fail($run, $e);
            throw $e;
        }

        return $this->run($id, $reason, false);
    }

    /** @return array<string,mixed> */
    public function resume(int $recoveryId, string $reason): array
    {
        return $this->run($recoveryId, $reason, true);
    }

    public function cancel(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $r = DB::table('salesforce_interest_initial_recoveries')->where('id', $id)->lockForUpdate()->first();
            if ($r === null || in_array($r->status, ['completed', 'cancelled'], true) || ($r->status === 'running' && $this->live($r))) {
                throw new RuntimeException('Initial recovery cannot be cancelled safely.');
            }
            DB::table('salesforce_interest_initial_recoveries')->where('id', $id)->update(['status' => 'cancelled', 'phase' => 'cancelled', 'cancelled_at' => now('UTC'), 'lease_token' => null, 'updated_at' => now()]);
        });
    }

    /** @return array<string,mixed> */
    public function status(int $id): array
    {
        $r = DB::table('salesforce_interest_initial_recoveries')->find($id) ?? throw new RuntimeException('Initial recovery does not exist.');
        $c = $this->json($r->counters);
        $candidate = DB::table('salesforce_interest_generations')->select(['status', 'f5_complete', 'activity_run_id'])
            ->where('id', $r->candidate_generation_id)->first();
        $f5Status = match (true) {
            $candidate !== null && $candidate->status === 'certified' => 'certified',
            $candidate !== null && (bool) $candidate->f5_complete && $candidate->activity_run_id !== null => 'associated_unverified',
            default => $c['f5_status'] ?? 'pending',
        };

        return ['recovery_id' => (int) $r->id, 'source_generation_id' => (int) $r->source_generation_id,
            'candidate_generation_id' => (int) $r->candidate_generation_id, 'status' => $r->status, 'phase' => $r->phase,
            'reconciliation_pass' => (int) $r->reconciliation_pass, 'max_reconciliation_passes' => (int) $r->max_reconciliation_passes,
            'percent' => $r->phase === 'completed' ? 100.0 : null, // remote totals are intentionally not guessed
            'records_observed' => (int) ($c['records_observed'] ?? 0), 'records_confirmed' => (int) ($c['records_confirmed'] ?? 0),
            'batches_completed' => (int) ($c['batches_completed'] ?? 0), 'changes_applied' => (int) ($c['changes_applied'] ?? 0),
            'errors' => (int) ($c['errors'] ?? 0), 'conflicts' => (int) ($c['conflicts'] ?? 0),
            'last_checkpoint_at' => $r->last_checkpoint_at, 'failure_code' => $r->error_code,
            'f5_status' => $f5Status, 'verification' => $c['verification'] ?? null,
            'lease_expires_at' => $r->heartbeat_at === null ? null : CarbonImmutable::parse($r->heartbeat_at, 'UTC')->addSeconds(self::LEASE_SECONDS)->toIso8601String(),
            'deletion_coverage' => $r->deletion_coverage === null ? null : $this->json($r->deletion_coverage)];
    }

    /** @return array<string,mixed> */
    private function run(int $id, string $reason, bool $resume): array
    {
        $lease = $this->claim($id, $reason, $resume);
        try {
            while (true) {
                $this->renewLease($id, $lease);
                $r = $this->owned($id, $lease);
                if ($r->phase === 'deletion_log') {
                    $stats = [];
                    $this->interests->applyDeletionLog((int) $r->candidate_generation_id, CarbonImmutable::parse($r->source_cutoff_at, 'UTC'), CarbonImmutable::parse($r->watermark_at, 'UTC'), $stats, fn (): bool => $this->renewLease($id, $lease));
                    $this->renewLease($id, $lease);
                    $this->checkpoint($id, $lease, 'f2_extract', null, ['coverage' => ['earliest_date_available' => $stats['deletion_log_earliest'] ?? null, 'latest_date_covered' => $stats['deletion_log_covered_through'] ?? null, 'records' => $stats['deletion_log_records'] ?? 0]]);

                    continue;
                }
                if (in_array($r->phase, ['f2_extract', 'f2_reconcile'], true)) {
                    $this->batch($id, $lease, $r, $r->phase === 'f2_reconcile');

                    continue;
                }
                if ($r->phase === 'f2_verify') {
                    $this->verifyBatch($id, $lease, $r);

                    continue;
                }
                if ($r->phase === 'f5') {
                    // F5 resumes safely by rebuilding a fresh isolated run. Partial F5 rows stay unattached evidence.
                    if ($this->store->generation((int) $r->candidate_generation_id)->status === 'certified') {
                        $this->checkpoint($id, $lease, 'completed', null, ['f5_status' => 'certified']);

                        continue;
                    }
                    $this->checkpoint($id, $lease, 'f5', null, ['f5_status' => 'materializing']);
                    $heartbeat = fn (): bool => $this->renewLease($id, $lease);
                    $finalizationGuard = fn (): bool => $this->guardF5Finalization($id, $lease);
                    $f5 = $this->activities->syncCurrentRecovery($reason, (int) $r->candidate_generation_id, $heartbeat, $finalizationGuard);
                    $this->checkpoint($id, $lease, 'f5', null, ['f5_status' => 'associated_unverified']);
                    $a = $this->activities->manifest($f5['run'], $heartbeat);
                    $v = $this->activities->verifyCurrentRecovery($f5['run'], (int) $r->candidate_generation_id, $heartbeat);
                    if (! $v['matches']) {
                        throw new RuntimeException('Initial recovery F5 verification is not clean.');
                    }
                    $manifest = $this->store->manifest((int) $r->candidate_generation_id);
                    $c = $this->json($r->counters);
                    $evidence = ['source' => 'initial_current_recovery', 'source_generation_id' => (int) $r->source_generation_id, 'recovered_generation_id' => (int) $r->candidate_generation_id,
                        'source_cutoff' => (string) $r->source_cutoff_at, 'watermark' => (string) $r->watermark_at, 'interest_run_id' => (int) $r->interest_run_id,
                        'reconciliation_passes' => (int) $r->max_reconciliation_passes, 'deletion_coverage' => $r->deletion_coverage === null ? [] : $this->json($r->deletion_coverage),
                        'f2' => [...($c['f2'] ?? []), 'manifest_sha256' => $manifest['sha256'], 'identifiers_sha256' => $manifest['identifiers_sha256']],
                        'f5' => ['run_id' => $f5['run']->id, 'cutoff_at' => (string) $r->watermark_at, 'rows' => $a['rows'], 'manifest_sha256' => $a['sha256'], 'verification_sha256' => $v['sha256'], 'verified' => true]];
                    DB::transaction(function () use ($id, $lease, $r, $evidence): void {
                        $this->owned($id, $lease, true);
                        $this->store->certifyInitialCurrentRecovery((int) $r->candidate_generation_id, $evidence);
                        $this->checkpoint($id, $lease, 'completed', null, ['f5_status' => 'verified']);
                    });

                    continue;
                }
                if ($r->phase === 'completed') {
                    DB::table('salesforce_interest_initial_recoveries')->where('id', $id)->where('lease_token', $lease)->update(['status' => 'completed', 'completed_at' => now('UTC'), 'lease_token' => null, 'updated_at' => now()]);
                    $status = $this->status($id);
                    $generation = $this->store->generation((int) $r->candidate_generation_id);
                    $evidence = json_decode((string) $generation->validation, true, 64, JSON_THROW_ON_ERROR);

                    return ['generation_id' => (int) $r->candidate_generation_id, 'evidence' => $evidence, ...$status];
                }
                throw new RuntimeException('Initial recovery checkpoint is not runnable.');
            }
        } catch (Throwable $e) {
            DB::transaction(function () use ($id, $lease, $e): void {
                $r = DB::table('salesforce_interest_initial_recoveries')->where('id', $id)->where('lease_token', $lease)->lockForUpdate()->first();
                if ($r === null) {
                    return; // A replacement owner fenced this stale worker.
                }
                $counters = $this->json($r->counters);
                $counters['errors'] = (int) ($counters['errors'] ?? 0) + 1;
                if ($r->phase === 'f5') {
                    $counters['f5_status'] = DB::table('salesforce_interest_generations')
                        ->where('id', $r->candidate_generation_id)->value('f5_complete')
                        ? 'associated_unverified'
                        : 'failed';
                }
                DB::table('salesforce_interest_initial_recoveries')->where('id', $id)->where('lease_token', $lease)->update([
                    'status' => 'failed', 'error_code' => $this->code($e), 'failed_at' => now('UTC'),
                    'lease_token' => null, 'counters' => json_encode($counters, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                ]);
            });
            throw $e;
        }
    }

    private function batch(int $id, string $lease, object $r, bool $reconcile): void
    {
        $this->renewLease($id, $lease);
        $records = $this->read($this->interests->currentRecoverySoql($r->checkpoint_salesforce_id, (int) $r->batch_size));
        $this->renewLease($id, $lease);
        if (count($records) > (int) $r->batch_size) {
            throw new RuntimeException('Salesforce batch exceeded its configured bound.');
        }
        $last = $r->checkpoint_salesforce_id;
        $rows = [];
        foreach ($records as $record) {
            $sfId = trim((string) data_get($record, 'Id'));
            if (preg_match('/^[A-Za-z0-9]{18}$/', $sfId) !== 1 || ($last !== null && strcmp($sfId, $last) <= 0)) {
                throw new RuntimeException('Salesforce Interest recovery cursor or ordering is invalid.');
            }
            $last = $sfId;
            $rows[] = $this->interests->mapRecord($record, (bool) data_get($record, 'IsDeleted', false));
        }
        if ($reconcile && $rows !== []) {
            $current = $this->store->rows((int) $r->candidate_generation_id)->whereIn('version.salesforce_id', array_column($rows, 'salesforce_id'))->get()->keyBy('salesforce_id');
            $rows = array_values(array_filter($rows, fn (array $row): bool => ($current->get($row['salesforce_id'])?->content_hash ?? null) !== $this->store->hash($row)));
        }
        // The recovery row is held from the final lease check through both
        // immutable writes and the checkpoint acknowledgement. If a stale
        // worker wakes up, it loses this check and cannot write another batch.
        DB::transaction(function () use ($id, $lease, $r, $rows, $records, $reconcile, $last): void {
            $this->owned($id, $lease, true);
            $write = ['inserted' => 0, 'updated' => 0, 'conflicts' => 0];
            foreach (array_chunk($rows, 200) as $chunk) {
                $result = $this->store->persist((int) $r->candidate_generation_id, $chunk);
                foreach ($write as $key => $_) {
                    $write[$key] += $result[$key];
                }
            }
            $phase = $reconcile ? 'f2_reconcile' : 'f2_extract';
            $cursor = $last;
            $pass = null;
            if (count($records) < (int) $r->batch_size) {
                $cursor = null;
                if (! $reconcile) {
                    $phase = 'f2_reconcile';
                } else {
                    $phase = 'f2_verify';
                }
            }
            $checkpoint = ['pass' => $pass, 'delta' => ['records_observed' => count($records), 'records_confirmed' => count($records), 'batches_completed' => 1, 'changes_applied' => $write['inserted'] + $write['updated'], 'conflicts' => $write['conflicts']]];
            if ($phase === 'f2_verify') {
                $checkpoint['verification'] = $this->emptyVerification();
            }
            $this->checkpoint($id, $lease, $phase, $cursor, $checkpoint);
        });
    }

    /** @return list<array<string,mixed>> */
    private function read(string $soql): array
    {
        $last = null;
        for ($try = 1; $try <= 3; $try++) {
            try {
                $rows = [];
                foreach ($this->client->queryPages($soql, true) as $page) {
                    foreach ($page as $row) {
                        $rows[] = $row;
                    }
                }

                return $rows;
            } catch (Throwable $e) {
                $last = $e;
                if ($try < 3) {
                    usleep($try * 200000);
                }
            }
        }
        throw new RuntimeException('Salesforce batch read exhausted its bounded retries.', 0, $last);
    }

    private function checkpoint(int $id, string $lease, string $phase, ?string $cursor, array $data): void
    {
        DB::transaction(function () use ($id, $lease, $phase, $cursor, $data): void {
            $r = $this->owned($id, $lease, true);
            $c = $this->json($r->counters);
            foreach (($data['delta'] ?? []) as $key => $value) {
                $c[$key] = ($c[$key] ?? 0) + $value;
            }
            if (isset($data['f2'])) {
                $c['f2'] = $data['f2'];
            }
            if (isset($data['verification'])) {
                $c['verification'] = $data['verification'];
            }
            if (isset($data['f5_status'])) {
                $c['f5_status'] = $data['f5_status'];
            }
            $update = ['phase' => $phase, 'checkpoint_salesforce_id' => $cursor, 'counters' => json_encode($c, JSON_THROW_ON_ERROR), 'heartbeat_at' => now('UTC'), 'last_checkpoint_at' => now('UTC'), 'updated_at' => now()];
            if (isset($data['coverage'])) {
                $update['deletion_coverage'] = json_encode($data['coverage'], JSON_THROW_ON_ERROR);
            }
            if ($data['pass'] ?? null) {
                $update['reconciliation_pass'] = $data['pass'];
            }
            DB::table('salesforce_interest_initial_recoveries')->where('id', $id)->where('lease_token', $lease)->update($update);
        });
    }

    /**
     * Checkpointed merge verification. Both remote and local streams are keyset
     * paginated by Salesforce Id, therefore a failure resumes from the last
     * fully compared range instead of repeating a multi-million-row scan.
     */
    private function verifyBatch(int $id, string $lease, object $recovery): void
    {
        $this->renewLease($id, $lease);
        $cursor = $recovery->checkpoint_salesforce_id;
        $records = $this->read($this->interests->currentRecoverySoql($cursor, (int) $recovery->batch_size));
        $this->renewLease($id, $lease);
        if (count($records) > (int) $recovery->batch_size) {
            throw new RuntimeException('Salesforce verification batch exceeded its configured bound.');
        }
        $counters = $this->json($recovery->counters);
        $verification = array_replace($this->emptyVerification(), is_array($counters['verification'] ?? null) ? $counters['verification'] : []);
        $mapped = [];
        $last = $cursor;
        foreach ($records as $record) {
            $row = $this->interests->mapRecord($record, (bool) data_get($record, 'IsDeleted', false));
            $sfId = (string) $row['salesforce_id'];
            if ($last !== null && strcmp($sfId, $last) <= 0) {
                throw new RuntimeException('Salesforce Interest verification ordering is invalid.');
            }
            $last = $sfId;
            $mapped[] = $row;
        }

        if ($last !== null) {
            $local = $this->candidateRows((int) $recovery->candidate_generation_id, $cursor, $last, fn (): bool => $this->renewLease($id, $lease));
            $local->rewind();
            foreach ($mapped as $row) {
                $sfId = (string) $row['salesforce_id'];
                while ($local->valid() && strcmp((string) $local->current()->salesforce_id, $sfId) < 0) {
                    $this->localOnly($local->current(), $verification);
                    $local->next();
                }
                $verification['remote_rows']++;
                if (! $local->valid() || (string) $local->current()->salesforce_id !== $sfId) {
                    $verification['salesforce_only']++;

                    continue;
                }
                $current = $local->current();
                if (hash_equals((string) $current->content_hash, $this->store->hash($row))) {
                    $verification['content_matches']++;
                } else {
                    $verification['content_mismatches']++;
                    $verification['state_mismatches'] += (int) ((bool) $current->is_deleted !== (bool) $row['is_deleted']);
                }
                $local->next();
            }
            while ($local->valid()) {
                $this->localOnly($local->current(), $verification);
                $local->next();
            }
        }

        if (count($records) === (int) $recovery->batch_size) {
            $this->checkpoint($id, $lease, 'f2_verify', $last, ['verification' => $verification,
                'delta' => ['batches_completed' => 1]]);

            return;
        }

        // The terminal remote page proves that remaining local identities are
        // candidate-only. This scan is also keyset paginated and heartbeats on
        // every page, so it is fenced even for a large hard-delete population.
        foreach ($this->candidateRows((int) $recovery->candidate_generation_id, $last, null, fn (): bool => $this->renewLease($id, $lease)) as $row) {
            $this->localOnly($row, $verification);
        }
        $verification['conflicts'] = (int) DB::table('salesforce_interest_conflicts')->where('generation_id', $recovery->candidate_generation_id)->count();
        $verification['candidate_rows'] = $verification['content_matches'] + $verification['content_mismatches']
            + $verification['candidate_only_unexplained'] + $verification['tombstones'];
        if (! $this->clean($verification)) {
            if ((int) $recovery->reconciliation_pass < (int) $recovery->max_reconciliation_passes) {
                $this->checkpoint($id, $lease, 'f2_reconcile', null, [
                    'pass' => (int) $recovery->reconciliation_pass + 1,
                    'verification' => $verification,
                    'delta' => ['batches_completed' => 1],
                ]);

                return;
            }
            throw new RuntimeException('Initial recovery F2 verification is not clean.');
        }
        DB::transaction(function () use ($id, $lease, $recovery, $verification): void {
            $this->owned($id, $lease, true);
            if (! $this->store->generation((int) $recovery->candidate_generation_id)->f2_complete) {
                $this->store->finishF2((int) $recovery->candidate_generation_id);
            }
            $run = ReportSyncRun::findOrFail($recovery->interest_run_id);
            if ($run->status !== 'completed') {
                $this->runs->complete($run, CarbonImmutable::parse($recovery->watermark_at, 'UTC'), ['mode' => 'initial_current_recovery_resumable', 'f2' => $verification]);
            }
            $this->checkpoint($id, $lease, 'f5', null, ['f2' => $verification, 'verification' => $verification,
                'f5_status' => 'pending', 'delta' => ['batches_completed' => 1]]);
        });
    }

    /** @return Generator<int,object> */
    private function candidateRows(int $generation, ?string $after = null, ?string $through = null, ?callable $heartbeat = null): Generator
    {
        $last = $after;
        while (true) {
            $query = $this->store->rows($generation)->select(['version.salesforce_id', 'version.content_hash', 'version.is_deleted', 'version.deletion_detection_source'])
                ->orderBy('version.salesforce_id')->limit(500);
            if ($last !== null) {
                $query->where('version.salesforce_id', '>', $last);
            }
            if ($through !== null) {
                $query->where('version.salesforce_id', '<=', $through);
            }
            $rows = $query->get();
            if ($rows->isEmpty()) {
                return;
            }
            if ($heartbeat !== null && $heartbeat() !== true) {
                throw new RuntimeException('Initial recovery lease was lost.');
            }
            foreach ($rows as $row) {
                $last = $row->salesforce_id;
                yield $row;
            }
        }
    }

    /** @param array<string,int> $s */
    private function localOnly(object $row, array &$s): void
    {
        ((bool) $row->is_deleted && $row->deletion_detection_source === 'get_deleted') ? $s['tombstones']++ : $s['candidate_only_unexplained']++;
    }

    /** @param array<string,int> $s */
    private function clean(array $s): bool
    {
        return $s['content_mismatches'] === 0 && $s['salesforce_only'] === 0 && $s['candidate_only_unexplained'] === 0 && $s['conflicts'] === 0;
    }

    private function claim(int $id, string $reason, bool $resume): string
    {
        return DB::transaction(function () use ($id, $reason, $resume): string {
            $r = DB::table('salesforce_interest_initial_recoveries')->where('id', $id)->lockForUpdate()->first();
            if ($r === null || ! hash_equals($r->reason_hash, hash('sha256', trim($reason))) || ($r->status === 'running' && $this->live($r)) || (! $resume && $r->status !== 'prepared') || ($resume && ! in_array($r->status, ['prepared', 'failed', 'running'], true))) {
                throw new RuntimeException('Initial recovery is not compatible or safely resumable.');
            }

            $lease = (string) Str::uuid();
            DB::table('salesforce_interest_initial_recoveries')->where('id', $id)->update(['status' => 'running', 'lease_token' => $lease, 'heartbeat_at' => now('UTC'), 'error_code' => null, 'failed_at' => null, 'updated_at' => now()]);

            return $lease;
        });
    }

    private function renewLease(int $id, string $lease): bool
    {
        $updated = DB::table('salesforce_interest_initial_recoveries')->where('id', $id)->where('lease_token', $lease)->where('status', 'running')->update(['heartbeat_at' => now('UTC'), 'updated_at' => now()]);
        if ($updated !== 1) {
            throw new RuntimeException('Initial recovery lease was lost.');
        }

        return true;
    }

    private function guardF5Finalization(int $id, string $lease): bool
    {
        $this->owned($id, $lease, true);

        return $this->renewLease($id, $lease);
    }

    private function owned(int $id, string $lease, bool $lock = false): object
    {
        $q = DB::table('salesforce_interest_initial_recoveries')->where('id', $id)->where('lease_token', $lease);
        if ($lock) {
            $q->lockForUpdate();
        }

        return $q->first() ?? throw new RuntimeException('Initial recovery lease was lost.');
    }

    private function live(object $r): bool
    {
        return $r->heartbeat_at !== null && CarbonImmutable::parse($r->heartbeat_at, 'UTC')->gt(now('UTC')->subSeconds(self::LEASE_SECONDS));
    }

    private function validate(string $reason, int $passes, int $batch): void
    {
        if (mb_strlen(trim($reason)) < 10 || mb_strlen(trim($reason)) > 500 || $passes < 2 || $passes > 10 || $batch < 50 || $batch > self::MAX_BATCH_SIZE) {
            throw new RuntimeException('Initial recovery configuration is invalid.');
        }
    }

    private function configurationHash(int $source, string $reason, int $passes, int $batch): string
    {
        return hash('sha256', json_encode([$source, hash('sha256', trim($reason)), $passes, $batch], JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed> */
    private function json(?string $value): array
    {
        return $value === null ? [] : json_decode($value, true, 64, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,int> */
    private function emptyCounters(): array
    {
        return ['records_observed' => 0, 'records_confirmed' => 0, 'batches_completed' => 0, 'changes_applied' => 0, 'errors' => 0, 'conflicts' => 0];
    }

    /** @return array<string,int> */
    private function emptyVerification(): array
    {
        return ['remote_rows' => 0, 'candidate_rows' => 0, 'content_matches' => 0, 'content_mismatches' => 0, 'state_mismatches' => 0, 'salesforce_only' => 0, 'candidate_only_unexplained' => 0, 'tombstones' => 0, 'conflicts' => 0];
    }

    private function code(Throwable $e): string
    {
        return match (true) {
            str_contains($e->getMessage(), 'cursor'),str_contains($e->getMessage(), 'ordering') => 'integrity_cursor_or_order',str_contains($e->getMessage(), 'verification'),str_contains($e->getMessage(), 'conflict') => 'integrity_verification',str_contains($e->getMessage(), 'retries') => 'remote_transient_exhausted',default => 'recovery_failed'
        };
    }
}
