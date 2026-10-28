<?php

namespace App\Services\Salesforce;

use App\Models\ReportSyncRun;
use App\Models\SalesforceInterestActivityRun;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Immutable row versions, copy-on-write membership, and a single publication fence. */
final class InterestGenerationStore
{
    public function generation(int $id): object
    {
        return DB::table('salesforce_interest_generations')->find($id)
            ?? throw new RuntimeException('Interest generation does not exist.');
    }

    public function published(): ?object
    {
        $id = DB::table('salesforce_interest_publication')->where('id', 1)->value('generation_id');

        return $id === null ? null : $this->generation((int) $id);
    }

    public function rows(int $id): Builder
    {
        return DB::table('salesforce_interest_versions as version')
            ->join('salesforce_interest_members as member', 'member.version_id', '=', 'version.id')
            ->where('member.generation_id', $id)->select('version.*');
    }

    /** Initial candidates start empty; the legacy live table is never a trusted source. */
    public function begin(CarbonInterface $cutoff, bool $initial = false): int
    {
        $base = $this->published();
        if (($base === null) !== $initial) {
            throw new RuntimeException('An explicit initial reconstruction is required without a certified base.');
        }
        if ($base !== null && CarbonImmutable::parse($base->cutoff_at)->gte($cutoff)) {
            throw new RuntimeException('Candidate cutoff must advance the certified watermark.');
        }
        $id = DB::table('salesforce_interest_generations')->insertGetId([
            'base_generation_id' => $base?->id, 'cutoff_at' => $cutoff,
            'status' => 'preparing', 'started_at' => now('UTC'),
        ]);
        try {
            if ($base !== null) {
                // Only the small membership index is copied, never raw_payload or row values.
                DB::table('salesforce_interest_members')->where('generation_id', $base->id)
                    ->orderBy('salesforce_id')->chunkById(1000, function ($rows) use ($id): void {
                        DB::table('salesforce_interest_members')->insert($rows->map(fn ($row) => [
                            'generation_id' => $id, 'salesforce_id' => $row->salesforce_id,
                            'version_id' => $row->version_id, 'migration_origin_lead_id' => $row->migration_origin_lead_id,
                        ])->all());
                    }, 'salesforce_id');
            }
            DB::table('salesforce_interest_generations')->where('id', $id)->update(['status' => 'syncing']);
        } catch (\Throwable $exception) {
            $this->fail($id);
            throw $exception;
        }

        return $id;
    }

    public function bindRun(int $id, ReportSyncRun $run): void
    {
        DB::transaction(function () use ($id, $run): void {
            $generation = $this->writable($id);
            if ($generation->interest_run_id !== null && (int) $generation->interest_run_id !== $run->id) {
                throw new RuntimeException('Candidate already belongs to another F2 run.');
            }
            DB::table('salesforce_interest_generations')->where('id', $id)->update(['interest_run_id' => $run->id]);
        });
    }

    /** @param list<array<string,mixed>> $rows */
    public function persist(int $id, array $rows): array
    {
        return DB::transaction(function () use ($id, $rows): array {
            $generation = $this->writable($id); // Serializes writers even after a cache lock expires.
            $stats = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'conflicts' => 0];
            $ids = array_column($rows, 'salesforce_id');
            $existing = $this->rows($id)->whereIn('version.salesforce_id', $ids)->get()->keyBy('salesforce_id');
            $origins = [];
            foreach ($rows as &$row) {
                $row['migration_origin_lead_id'] = trim((string) ($row['migration_origin_lead_id'] ?? '')) ?: null;
                if ($row['migration_origin_lead_id'] !== null) {
                    $origins[$row['migration_origin_lead_id']][$row['salesforce_id']] = true;
                }
            }
            unset($row);
            $owners = DB::table('salesforce_interest_members')->where('generation_id', $id)
                ->whereIn('migration_origin_lead_id', array_keys($origins))->get()->keyBy('migration_origin_lead_id');
            $baseOwners = $generation->base_generation_id === null ? collect() : DB::table('salesforce_interest_members')
                ->where('generation_id', $generation->base_generation_id)
                ->whereIn('migration_origin_lead_id', array_keys($origins))->get()->keyBy('migration_origin_lead_id');
            $batch = (string) Str::uuid();
            $inserts = [];
            $accepted = [];
            $seen = [];
            $conflicts = [];
            foreach ($rows as $row) {
                $sfId = (string) $row['salesforce_id'];
                $origin = $row['migration_origin_lead_id'];
                $current = $existing->get($sfId);
                $owner = $owners->get($origin)?->salesforce_id;
                $baseOwner = $baseOwners->get($origin)?->salesforce_id;
                $hash = $this->hash($row);
                $kind = match (true) {
                    $origin !== null && count($origins[$origin]) > 1 => 'duplicate_in_block',
                    $baseOwner !== null && $baseOwner !== $sfId => 'base_owner_conflict',
                    $owner !== null && $owner !== $sfId => 'duplicate_between_blocks',
                    $current !== null && $current->migration_origin_lead_id !== null
                        && $current->migration_origin_lead_id !== $origin => 'owner_change',
                    isset($seen[$sfId]) && $seen[$sfId] !== $hash => 'retry_conflict',
                    default => null,
                };
                if ($kind !== null) {
                    $evidence = $this->quarantine($generation, $row, $kind, $baseOwner ?? $owner);
                    $conflicts[$evidence['fingerprint']] = $evidence;
                    $stats['conflicts']++;

                    continue;
                }
                if (isset($seen[$sfId]) || ($current !== null && $current->content_hash === $hash)) {
                    $stats['unchanged']++;

                    continue;
                }
                $seen[$sfId] = $hash;
                $accepted[$sfId] = $origin;
                $inserts[] = [...$row, 'generation_id' => $id, 'batch_token' => $batch,
                    'content_hash' => $hash, 'created_at' => now(), 'updated_at' => now()];
                $stats[$current === null ? 'inserted' : 'updated']++;
            }
            if ($conflicts !== []) {
                $known = DB::table('salesforce_interest_conflicts')->whereIn('fingerprint', array_keys($conflicts))->pluck('fingerprint')->all();
                $new = array_diff_key($conflicts, array_fill_keys($known, true));
                if ($new !== []) {
                    DB::table('salesforce_interest_conflicts')->insert(array_values($new));
                }
            }
            if ($inserts !== []) {
                DB::table('salesforce_interest_versions')->insert($inserts);
                $versions = DB::table('salesforce_interest_versions')->where('batch_token', $batch)->get(['id', 'salesforce_id']);
                // Candidate membership only. Immutable versions and every other generation remain untouched.
                DB::table('salesforce_interest_members')->where('generation_id', $id)->whereIn('salesforce_id', array_keys($accepted))->delete();
                DB::table('salesforce_interest_members')->insert($versions->map(fn ($version) => [
                    'generation_id' => $id, 'salesforce_id' => $version->salesforce_id,
                    'version_id' => $version->id, 'migration_origin_lead_id' => $accepted[$version->salesforce_id],
                ])->all());
            }

            return $stats;
        });
    }

    public function finishF2(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $this->writable($id);
            $conflicts = DB::table('salesforce_interest_conflicts')->where('generation_id', $id)->exists();
            DB::table('salesforce_interest_generations')->where('id', $id)->update([
                'status' => $conflicts ? 'conflict' : 'validating', 'f2_complete' => ! $conflicts,
            ]);
        });
        if (! $this->generation($id)->f2_complete) {
            throw new RuntimeException('Candidate has unresolved integrity conflicts.');
        }
    }

    public function finishF5(int $id, SalesforceInterestActivityRun $run): void
    {
        DB::transaction(function () use ($id, $run): void {
            $generation = DB::table('salesforce_interest_generations')->where('id', $id)->lockForUpdate()->first();
            if ($generation === null || $generation->status !== 'validating' || ! $generation->f2_complete
                || ($generation->activity_run_id !== null && (int) $generation->activity_run_id !== (int) $run->id)
                || $run->status !== 'completed' || (int) $run->source_interest_sync_run_id !== (int) $generation->interest_run_id
                || ! $run->source_interest_cutoff_at->equalTo($generation->cutoff_at)
                || ! $run->source_cutoff_at->equalTo($generation->cutoff_at)) {
                throw new RuntimeException('F5 does not certify this F2 candidate and cutoff.');
            }
            DB::table('salesforce_interest_generations')->where('id', $id)->update([
                'f5_complete' => true, 'activity_run_id' => $run->id,
            ]);
        });
    }

    /** Initial evidence is an independently reviewed manifest digest, never a legacy run ID. */
    public function certify(int $id, ?array $initialEvidence = null): void
    {
        DB::transaction(function () use ($id, $initialEvidence): void {
            $g = DB::table('salesforce_interest_generations')->where('id', $id)->lockForUpdate()->first();
            if ($g === null || ! in_array($g->status, ['validating', 'certified', 'published'], true) || ! $g->f2_complete || ! $g->f5_complete
                || DB::table('salesforce_interest_conflicts')->where('generation_id', $id)->exists()) {
                throw new RuntimeException('Candidate is not complete and conflict-free.');
            }
            $this->assertAligned($g);
            if ($g->base_generation_id === null) {
                $manifest = $this->manifest($id);
                $reportedManifest = $initialEvidence['manifest'] ?? null;
                if (! is_array($reportedManifest)
                    || (int) ($reportedManifest['rows'] ?? -1) !== $manifest['rows']
                    || (int) ($reportedManifest['deleted'] ?? -1) !== $manifest['deleted']
                    || ! hash_equals($manifest['sha256'], (string) ($reportedManifest['sha256'] ?? ''))
                    || ! hash_equals($manifest['sha256'], (string) ($initialEvidence['manifest_sha256'] ?? ''))) {
                    throw new RuntimeException('Initial evidence does not match the candidate manifest.');
                }
                foreach (['identifiers', 'counts', 'deletions', 'relationships', 'coverage', 'f5'] as $check) {
                    if (($initialEvidence['checks'][$check] ?? false) !== true) {
                        throw new RuntimeException('Initial reconstruction requires independent coverage validation.');
                    }
                }
                if (($initialEvidence['source'] ?? '') !== 'isolated_salesforce_reconstruction'
                    || (int) ($initialEvidence['f5']['run_id'] ?? 0) !== (int) $g->activity_run_id
                    || ! hash_equals((string) $g->cutoff_at, (string) ($initialEvidence['f5']['cutoff_at'] ?? ''))) {
                    throw new RuntimeException('Initial source is not verified.');
                }
                $external = $initialEvidence['external'] ?? null;
                if (! is_array($external)
                    || in_array($external['source'] ?? '', ['candidate_manifest', 'legacy_table'], true)
                    || ! hash_equals($manifest['identifiers_sha256'], (string) ($external['identifiers_sha256'] ?? ''))
                    || (int) ($external['rows'] ?? -1) !== $manifest['rows']
                    || (int) ($external['deleted'] ?? -1) !== $manifest['deleted']
                    || ! is_string($external['relationships_sha256'] ?? null)
                    || ! is_string($external['coverage_sha256'] ?? null)) {
                    throw new RuntimeException('Independent source evidence does not match the candidate.');
                }
            }
            if ($g->status !== 'validating') {
                return;
            }
            DB::table('salesforce_interest_generations')->where('id', $id)->update([
                'status' => 'certified', 'completed_at' => now('UTC'),
                'validation' => json_encode($initialEvidence ?? ['f2_f5_aligned' => true], JSON_THROW_ON_ERROR),
            ]);
        });
    }

    /** Deterministic candidate digest for independent initial coverage review. No IDs in output. */
    public function manifest(int $id): array
    {
        $g = $this->generation($id);
        $hash = hash_init('sha256');
        $identityHash = hash_init('sha256');
        hash_update($hash, json_encode([$g->id, $g->cutoff_at, $g->interest_run_id, $g->activity_run_id], JSON_THROW_ON_ERROR));
        $count = 0;
        $deleted = 0;
        $this->rows($id)->select(['version.id', 'version.salesforce_id', 'version.content_hash', 'version.is_deleted'])
            ->chunkById(1000, function ($rows) use ($hash, &$count, &$deleted): void {
                foreach ($rows as $row) {
                    hash_update($hash, $row->salesforce_id.'|'.$row->content_hash."\n");
                    hash_update($identityHash, $row->salesforce_id.'|'.$row->content_hash.'|'.(int) $row->is_deleted."\n");
                    $count++;
                    $deleted += (int) $row->is_deleted;
                }
            }, 'version.id', 'id');

        return ['generation_id' => $id, 'cutoff_at' => $g->cutoff_at,
            'rows' => $count, 'deleted' => $deleted, 'sha256' => hash_final($hash),
            'identifiers_sha256' => hash_final($identityHash)];
    }

    public function publish(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $pointer = DB::table('salesforce_interest_publication')->where('id', 1)->lockForUpdate()->first();
            $g = DB::table('salesforce_interest_generations')->where('id', $id)->lockForUpdate()->first();
            if ($pointer !== null && (int) $pointer->generation_id === $id && $g?->status === 'published') {
                return;
            }
            if ($pointer === null || $g === null || $g->status !== 'certified'
                || $pointer->generation_id != $g->base_generation_id || ! $g->f2_complete || ! $g->f5_complete
                || DB::table('salesforce_interest_conflicts')->where('generation_id', $id)->exists()) {
                throw new RuntimeException('Candidate cannot be published against the current base.');
            }
            $this->assertAligned($g);
            DB::table('salesforce_interest_publication')->where('id', 1)->update([
                'generation_id' => $id, 'watermark_at' => $g->cutoff_at,
            ]);
            DB::table('salesforce_interest_generations')->where('id', $id)->update([
                'status' => 'published', 'published_at' => now('UTC'),
            ]);
        });
    }

    public function fail(int $id): void
    {
        DB::table('salesforce_interest_generations')->where('id', $id)
            ->whereIn('status', ['preparing', 'syncing', 'validating'])
            ->update(['status' => 'failed', 'completed_at' => now('UTC')]);
    }

    private function assertAligned(object $g): void
    {
        $f2 = ReportSyncRun::find($g->interest_run_id);
        $f5 = SalesforceInterestActivityRun::find($g->activity_run_id);
        if ($f2?->status !== 'completed' || $f5?->status !== 'completed'
            || (int) $f5->source_interest_sync_run_id !== (int) $f2->id
            || ! $f2->source_cutoff_at?->equalTo($g->cutoff_at)
            || ! $f5->source_interest_cutoff_at?->equalTo($g->cutoff_at)
            || ! $f5->source_cutoff_at?->equalTo($g->cutoff_at)) {
            throw new RuntimeException('Publication requires aligned completed F2 and F5.');
        }
    }

    private function writable(int $id): object
    {
        $g = DB::table('salesforce_interest_generations')->where('id', $id)->lockForUpdate()->first();
        if ($g === null || $g->status !== 'syncing') {
            throw new RuntimeException('Only a syncing candidate accepts writes.');
        }

        return $g;
    }

    private function hash(array $row): string
    {
        unset($row['synced_at'], $row['created_at'], $row['updated_at']);
        ksort($row);

        return hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
    }

    private function quarantine(object $generation, array $row, string $kind, ?string $owner): array
    {
        $fingerprint = hash('sha256', $generation->id.'|'.$kind.'|'.$this->hash($row).'|'.$owner);

        return [
            'generation_id' => $generation->id, 'interest_run_id' => $generation->interest_run_id,
            'fingerprint' => $fingerprint, 'salesforce_id' => $row['salesforce_id'],
            'migration_origin_lead_id' => $row['migration_origin_lead_id'], 'existing_owner_id' => $owner,
            'kind' => $kind, 'evidence' => Crypt::encryptString(json_encode($row, JSON_THROW_ON_ERROR)),
            'review_status' => 'pending', 'detected_at' => now('UTC'),
        ];
    }
}
