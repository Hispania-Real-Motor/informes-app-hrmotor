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
        return ['recovery_id' => (int) $r->id, 'source_generation_id' => (int) $r->source_generation_id,
            'candidate_generation_id' => (int) $r->candidate_generation_id, 'status' => $r->status, 'phase' => $r->phase,
            'percent' => $r->phase === 'completed' ? 100.0 : null, // remote totals are intentionally not guessed
            'records_observed' => (int) ($c['records_observed'] ?? 0), 'records_confirmed' => (int) ($c['records_confirmed'] ?? 0),
            'batches_completed' => (int) ($c['batches_completed'] ?? 0), 'changes_applied' => (int) ($c['changes_applied'] ?? 0),
            'errors' => (int) ($c['errors'] ?? 0), 'conflicts' => (int) ($c['conflicts'] ?? 0),
            'last_checkpoint_at' => $r->last_checkpoint_at, 'failure_code' => $r->error_code,
            'deletion_coverage' => $r->deletion_coverage === null ? null : $this->json($r->deletion_coverage)];
    }

    /** @return array<string,mixed> */
    private function run(int $id, string $reason, bool $resume): array
    {
        $lease = $this->claim($id, $reason, $resume);
        try {
            while (true) {
                $r = $this->owned($id, $lease);
                if ($r->phase === 'deletion_log') {
                    $stats = [];
                    $this->interests->applyDeletionLog((int) $r->candidate_generation_id, CarbonImmutable::parse($r->source_cutoff_at, 'UTC'), CarbonImmutable::parse($r->watermark_at, 'UTC'), $stats);
                    $this->checkpoint($id, $lease, 'f2_extract', null, ['coverage' => ['earliest_date_available' => $stats['deletion_log_earliest'] ?? null, 'latest_date_covered' => $stats['deletion_log_covered_through'] ?? null, 'records' => $stats['deletion_log_records'] ?? 0]]);
                    continue;
                }
                if (in_array($r->phase, ['f2_extract', 'f2_reconcile'], true)) {
                    $this->batch($id, $lease, $r, $r->phase === 'f2_reconcile');
                    continue;
                }
                if ($r->phase === 'f2_verify') {
                    $f2 = $this->verify((int) $r->candidate_generation_id);
                    if (! $this->clean($f2)) {
                        throw new RuntimeException('Initial recovery F2 verification is not clean.');
                    }
                    if (! $this->store->generation((int) $r->candidate_generation_id)->f2_complete) {
                        $this->store->finishF2((int) $r->candidate_generation_id);
                    }
                    $run = ReportSyncRun::findOrFail($r->interest_run_id);
                    if ($run->status !== 'completed') {
                        $this->runs->complete($run, CarbonImmutable::parse($r->watermark_at, 'UTC'), ['mode' => 'initial_current_recovery_resumable', 'f2' => $f2]);
                    }
                    $this->checkpoint($id, $lease, 'f5', null, ['f2' => $f2]);
                    continue;
                }
                if ($r->phase === 'f5') {
                    // F5 resumes safely by rebuilding a fresh isolated run. Partial F5 rows stay unattached evidence.
                    if ($this->store->generation((int) $r->candidate_generation_id)->status === 'certified') {
                        $this->checkpoint($id, $lease, 'completed', null, []);
                        continue;
                    }
                    $f5 = $this->activities->syncCurrentRecovery($reason, (int) $r->candidate_generation_id);
                    $a = $this->activities->manifest($f5['run']);
                    $v = $this->activities->verifyCurrentRecovery($f5['run'], (int) $r->candidate_generation_id);
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
                    $this->store->certifyInitialCurrentRecovery((int) $r->candidate_generation_id, $evidence);
                    $this->checkpoint($id, $lease, 'completed', null, []);
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
                $r = $this->owned($id, $lease, true);
                $counters = $this->json($r->counters);
                $counters['errors'] = (int) ($counters['errors'] ?? 0) + 1;
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
        $records = $this->read($this->interests->currentRecoverySoql($r->checkpoint_salesforce_id, (int) $r->batch_size));
        if (count($records) > (int) $r->batch_size) {
            throw new RuntimeException('Salesforce batch exceeded its configured bound.');
        }
        $last = $r->checkpoint_salesforce_id; $rows = [];
        foreach ($records as $record) {
            $sfId = trim((string) data_get($record, 'Id'));
            if (preg_match('/^[A-Za-z0-9]{18}$/', $sfId) !== 1 || ($last !== null && strcmp($sfId, $last) <= 0)) {
                throw new RuntimeException('Salesforce Interest recovery cursor or ordering is invalid.');
            }
            $last = $sfId; $rows[] = $this->interests->mapRecord($record, (bool) data_get($record, 'IsDeleted', false));
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
                foreach ($write as $key => $_) { $write[$key] += $result[$key]; }
            }
            $phase = $reconcile ? 'f2_reconcile' : 'f2_extract'; $cursor = $last; $pass = null;
            if (count($records) < (int) $r->batch_size) {
                $cursor = null;
                if (! $reconcile) { $phase = 'f2_reconcile'; }
                elseif ((int) $r->reconciliation_pass >= (int) $r->max_reconciliation_passes) { $phase = 'f2_verify'; }
                else { $pass = (int) $r->reconciliation_pass + 1; }
            }
            $this->checkpoint($id, $lease, $phase, $cursor, ['pass' => $pass, 'delta' => ['records_observed' => count($records), 'records_confirmed' => count($records), 'batches_completed' => 1, 'changes_applied' => $write['inserted'] + $write['updated'], 'conflicts' => $write['conflicts']]]);
        });
    }

    /** @return list<array<string,mixed>> */
    private function read(string $soql): array
    {
        $last = null;
        for ($try = 1; $try <= 3; $try++) {
            try { $rows = []; foreach ($this->client->queryPages($soql, true) as $page) { foreach ($page as $row) { $rows[] = $row; } } return $rows; }
            catch (Throwable $e) { $last = $e; if ($try < 3) { usleep($try * 200000); } }
        }
        throw new RuntimeException('Salesforce batch read exhausted its bounded retries.', 0, $last);
    }

    private function checkpoint(int $id, string $lease, string $phase, ?string $cursor, array $data): void
    {
        DB::transaction(function () use ($id, $lease, $phase, $cursor, $data): void {
            $r = $this->owned($id, $lease, true); $c = $this->json($r->counters);
            foreach (($data['delta'] ?? []) as $key => $value) { $c[$key] = ($c[$key] ?? 0) + $value; }
            if (isset($data['f2'])) { $c['f2'] = $data['f2']; }
            $update = ['phase' => $phase, 'checkpoint_salesforce_id' => $cursor, 'counters' => json_encode($c, JSON_THROW_ON_ERROR), 'heartbeat_at' => now('UTC'), 'last_checkpoint_at' => now('UTC'), 'updated_at' => now()];
            if (isset($data['coverage'])) { $update['deletion_coverage'] = json_encode($data['coverage'], JSON_THROW_ON_ERROR); }
            if ($data['pass'] ?? null) { $update['reconciliation_pass'] = $data['pass']; }
            DB::table('salesforce_interest_initial_recoveries')->where('id', $id)->where('lease_token', $lease)->update($update);
        });
    }

    /** @return array<string,int> */
    private function verify(int $generation): array
    {
        $s = ['remote_rows'=>0,'candidate_rows'=>0,'content_matches'=>0,'content_mismatches'=>0,'state_mismatches'=>0,'salesforce_only'=>0,'candidate_only_unexplained'=>0,'tombstones'=>0,'conflicts'=>(int) DB::table('salesforce_interest_conflicts')->where('generation_id',$generation)->count()];
        $local = $this->candidateRows($generation); $local->rewind(); $previous = null;
        foreach ($this->client->queryPages($this->interests->currentRecoverySoql(), true) as $records) foreach ($records as $record) {
            $row = $this->interests->mapRecord($record, (bool) data_get($record, 'IsDeleted', false)); $sf = $row['salesforce_id'];
            if (($previous !== null && strcmp($sf, $previous) <= 0)) throw new RuntimeException('Salesforce Interest verification ordering is invalid.');
            $previous = $sf; $s['remote_rows']++;
            while ($local->valid() && strcmp($local->current()->salesforce_id, $sf) < 0) { $this->localOnly($local->current(), $s); $local->next(); }
            if (! $local->valid() || $local->current()->salesforce_id !== $sf) { $s['salesforce_only']++; continue; }
            $current = $local->current(); if (hash_equals($current->content_hash, $this->store->hash($row))) $s['content_matches']++; else { $s['content_mismatches']++; $s['state_mismatches'] += (int) ((bool) $current->is_deleted !== (bool) $row['is_deleted']); } $local->next();
        }
        while ($local->valid()) { $this->localOnly($local->current(), $s); $local->next(); }
        $s['candidate_rows'] = $s['content_matches'] + $s['content_mismatches'] + $s['candidate_only_unexplained'] + $s['tombstones']; return $s;
    }

    /** @return Generator<int,object> */
    private function candidateRows(int $generation): Generator
    {
        $last = null; while (true) { $q = $this->store->rows($generation)->select(['version.salesforce_id','version.content_hash','version.is_deleted','version.deletion_detection_source'])->orderBy('version.salesforce_id')->limit(500); if ($last !== null) $q->where('version.salesforce_id','>',$last); $rows = $q->get(); if ($rows->isEmpty()) return; foreach ($rows as $row) { $last = $row->salesforce_id; yield $row; } }
    }
    /** @param array<string,int> $s */ private function localOnly(object $row, array &$s): void { ((bool) $row->is_deleted && $row->deletion_detection_source === 'get_deleted') ? $s['tombstones']++ : $s['candidate_only_unexplained']++; }
    /** @param array<string,int> $s */ private function clean(array $s): bool { return $s['content_mismatches']===0 && $s['salesforce_only']===0 && $s['candidate_only_unexplained']===0 && $s['conflicts']===0; }
    private function claim(int $id,string $reason,bool $resume): string { return DB::transaction(function () use($id,$reason,$resume): string { $r=DB::table('salesforce_interest_initial_recoveries')->where('id',$id)->lockForUpdate()->first(); if ($r===null || !hash_equals($r->reason_hash,hash('sha256',trim($reason))) || ($r->status==='running' && $this->live($r)) || (!$resume && $r->status!=='prepared') || ($resume && !in_array($r->status,['prepared','failed','running'],true))) throw new RuntimeException('Initial recovery is not compatible or safely resumable.'); $lease=(string) Str::uuid(); DB::table('salesforce_interest_initial_recoveries')->where('id',$id)->update(['status'=>'running','lease_token'=>$lease,'heartbeat_at'=>now('UTC'),'error_code'=>null,'failed_at'=>null,'updated_at'=>now()]); return $lease; }); }
    private function owned(int $id,string $lease,bool $lock=false): object { $q=DB::table('salesforce_interest_initial_recoveries')->where('id',$id)->where('lease_token',$lease); if($lock)$q->lockForUpdate(); return $q->first() ?? throw new RuntimeException('Initial recovery lease was lost.'); }
    private function live(object $r): bool { return $r->heartbeat_at !== null && CarbonImmutable::parse($r->heartbeat_at,'UTC')->gt(now('UTC')->subSeconds(self::LEASE_SECONDS)); }
    private function validate(string $reason,int $passes,int $batch): void { if(mb_strlen(trim($reason))<10||mb_strlen(trim($reason))>500||$passes<2||$passes>10||$batch<50||$batch>self::MAX_BATCH_SIZE) throw new RuntimeException('Initial recovery configuration is invalid.'); }
    private function configurationHash(int $source,string $reason,int $passes,int $batch): string { return hash('sha256',json_encode([$source,hash('sha256',trim($reason)),$passes,$batch],JSON_THROW_ON_ERROR)); }
    /** @return array<string,mixed> */ private function json(?string $value): array { return $value===null?[]:json_decode($value,true,64,JSON_THROW_ON_ERROR); }
    /** @return array<string,int> */ private function emptyCounters(): array { return ['records_observed'=>0,'records_confirmed'=>0,'batches_completed'=>0,'changes_applied'=>0,'errors'=>0,'conflicts'=>0]; }
    private function code(Throwable $e): string { return match(true) { str_contains($e->getMessage(),'cursor'),str_contains($e->getMessage(),'ordering')=>'integrity_cursor_or_order',str_contains($e->getMessage(),'verification'),str_contains($e->getMessage(),'conflict')=>'integrity_verification',str_contains($e->getMessage(),'retries')=>'remote_transient_exhausted',default=>'recovery_failed'}; }
}
