<?php

namespace App\Services\Salesforce;

use App\Services\Reports\ReportSyncRunService;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Exceptional, read-only Salesforce reconciliation used to recover the first
 * certified Interest generation. It never publishes and never treats the
 * technical seed as historical proof.
 */
final class InterestInitialCurrentRecoveryService
{
    private const PAGE_LOOKUP_SIZE = 500;

    public const MAX_PASSES = 3;

    public function __construct(
        private readonly SalesforceClient $client,
        private readonly SalesforceInterestSyncService $interests,
        private readonly SalesforceInterestActivitySyncService $activities,
        private readonly InterestGenerationStore $store,
        private readonly ReportSyncRunService $runs,
    ) {}

    /** @return array<string, mixed> */
    public function recover(int $sourceGenerationId, string $reason, int $maxPasses = self::MAX_PASSES): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500 || $maxPasses < 2 || $maxPasses > 10) {
            throw new RuntimeException('Initial recovery requires a bounded operational reason and pass count.');
        }

        $source = $this->store->generation($sourceGenerationId);
        $watermark = CarbonImmutable::now('UTC')->subMinutes(2)->startOfMinute();
        $candidateId = $this->store->beginInitialRecovery($sourceGenerationId, $watermark);
        $run = $this->runs->start(
            SalesforceInterestSyncService::DATASET,
            SalesforceInterestSyncService::SOURCE,
            CarbonImmutable::parse($source->cutoff_at, 'UTC'),
            $watermark,
            'UTC',
        );
        $this->store->bindRun($candidateId, $run);

        try {
            $deletionStats = [];
            // A hard-delete log is the only evidence accepted for a record no
            // longer returned by queryAll. Coverage failure closes the flow.
            $this->interests->applyDeletionLog(
                $candidateId,
                CarbonImmutable::parse($source->cutoff_at, 'UTC'),
                $watermark,
                $deletionStats,
            );

            $passes = [];
            for ($pass = 1; $pass <= $maxPasses; $pass++) {
                $applied = $this->reconcilePass($candidateId);
                $verification = $this->verify($candidateId);
                $passes[] = ['applied' => $applied, 'verification' => $verification];
                if ($this->isClean($verification)) {
                    break;
                }
                if ($pass === $maxPasses) {
                    throw new RuntimeException('Initial recovery did not converge within its bounded passes.');
                }
            }
            $final = $passes[array_key_last($passes)]['verification'];
            if (! $this->isClean($final)) {
                throw new RuntimeException('Initial recovery verification is not clean.');
            }

            $this->store->finishF2($candidateId);
            $this->runs->complete($run, $watermark, [
                'mode' => 'initial_current_recovery', 'source_generation_id' => $sourceGenerationId,
                'passes' => count($passes), 'f2' => $final, 'deletion' => $deletionStats,
            ]);
            $f5 = $this->activities->syncCurrentRecovery($reason, $candidateId);
            $manifest = $this->store->manifest($candidateId);
            $activityManifest = $this->activities->manifest($f5['run']);
            $activityVerification = $this->activities->verifyCurrentRecovery($f5['run'], $candidateId);
            if (! $activityVerification['matches']) {
                throw new RuntimeException('Initial recovery F5 verification is not clean.');
            }
            $evidence = [
                'source' => 'initial_current_recovery',
                'source_generation_id' => $sourceGenerationId,
                'recovered_generation_id' => $candidateId,
                'source_cutoff' => (string) $source->cutoff_at,
                'watermark' => (string) $this->store->generation($candidateId)->cutoff_at,
                'interest_run_id' => $run->id,
                'reconciliation_passes' => count($passes),
                'deletion_coverage' => [
                    'earliest_date_available' => $deletionStats['deletion_log_earliest'] ?? null,
                    'latest_date_covered' => $deletionStats['deletion_log_covered_through'] ?? null,
                    'records' => $deletionStats['deletion_log_records'] ?? 0,
                ],
                'f2' => [...$final, 'manifest_sha256' => $manifest['sha256'], 'identifiers_sha256' => $manifest['identifiers_sha256']],
                'f5' => [
                    'run_id' => $f5['run']->id,
                    'cutoff_at' => (string) $this->store->generation($candidateId)->cutoff_at,
                    'rows' => $activityManifest['rows'],
                    'manifest_sha256' => $activityManifest['sha256'],
                    'verification_sha256' => $activityVerification['sha256'],
                    'verified' => $activityVerification['matches'],
                ],
            ];
            $this->store->certifyInitialCurrentRecovery($candidateId, $evidence);

            return ['generation_id' => $candidateId, 'evidence' => $evidence];
        } catch (Throwable $exception) {
            $this->runs->fail($run, $exception);
            $this->store->fail($candidateId);
            throw $exception;
        }
    }

    /** @return array<string, int> */
    private function reconcilePass(int $generationId): array
    {
        $stats = ['remote_rows' => 0, 'content_matches' => 0, 'content_mismatches' => 0, 'salesforce_only' => 0, 'written' => 0, 'conflicts' => 0];
        $previousId = null;
        foreach ($this->client->queryPages($this->interests->currentRecoverySoql(), true) as $records) {
            $rows = [];
            foreach ($records as $record) {
                $id = trim((string) data_get($record, 'Id'));
                if (preg_match('/^[A-Za-z0-9]{18}$/', $id) !== 1) {
                    throw new RuntimeException('Salesforce Interest reconciliation record is invalid.');
                }
                if ($previousId !== null && strcmp($id, $previousId) <= 0) {
                    throw new RuntimeException('Salesforce Interest reconciliation ordering is invalid.');
                }
                $previousId = $id;
                $rows[] = $this->interests->mapRecord($record, (bool) data_get($record, 'IsDeleted', false));
            }
            foreach (array_chunk($rows, self::PAGE_LOOKUP_SIZE) as $chunk) {
                $current = $this->store->rows($generationId)
                    ->whereIn('version.salesforce_id', array_column($chunk, 'salesforce_id'))->get()->keyBy('salesforce_id');
                $changes = [];
                foreach ($chunk as $row) {
                    $stats['remote_rows']++;
                    $existing = $current->get($row['salesforce_id']);
                    if ($existing === null) {
                        $stats['salesforce_only']++;
                        $changes[] = $row;
                    } elseif (hash_equals($existing->content_hash, $this->store->hash($row))) {
                        $stats['content_matches']++;
                    } else {
                        $stats['content_mismatches']++;
                        $changes[] = $row;
                    }
                }
                if ($changes !== []) {
                    $result = $this->store->persist($generationId, $changes);
                    $stats['written'] += $result['inserted'] + $result['updated'];
                    $stats['conflicts'] += $result['conflicts'];
                }
            }
        }

        return $stats;
    }

    /** @return array<string, int> */
    private function verify(int $generationId): array
    {
        $stats = ['remote_rows' => 0, 'candidate_rows' => 0, 'content_matches' => 0, 'content_mismatches' => 0,
            'state_mismatches' => 0, 'salesforce_only' => 0, 'candidate_only_unexplained' => 0, 'tombstones' => 0,
            'conflicts' => (int) DB::table('salesforce_interest_conflicts')->where('generation_id', $generationId)->count()];
        $cursor = $this->candidateRows($generationId);
        $cursor->rewind();
        $previousId = null;
        foreach ($this->client->queryPages($this->interests->currentRecoverySoql(), true) as $records) {
            foreach ($records as $record) {
                $row = $this->interests->mapRecord($record, (bool) data_get($record, 'IsDeleted', false));
                $remoteId = (string) $row['salesforce_id'];
                if (preg_match('/^[A-Za-z0-9]{18}$/', $remoteId) !== 1) {
                    throw new RuntimeException('Salesforce Interest reconciliation record is invalid.');
                }
                if ($previousId !== null && strcmp($remoteId, $previousId) <= 0) {
                    throw new RuntimeException('Salesforce Interest reconciliation ordering is invalid.');
                }
                $previousId = $remoteId;
                $stats['remote_rows']++;
                while ($cursor->valid() && strcmp((string) $cursor->current()->salesforce_id, $remoteId) < 0) {
                    $this->countCandidateOnly($cursor->current(), $stats);
                    $cursor->next();
                }
                if (! $cursor->valid() || (string) $cursor->current()->salesforce_id !== $remoteId) {
                    $stats['salesforce_only']++;

                    continue;
                }
                $local = $cursor->current();
                if (hash_equals($local->content_hash, $this->store->hash($row))) {
                    $stats['content_matches']++;
                } else {
                    $stats['content_mismatches']++;
                    if ((bool) $local->is_deleted !== (bool) $row['is_deleted']) {
                        $stats['state_mismatches']++;
                    }
                }
                $cursor->next();
            }
        }
        while ($cursor->valid()) {
            $this->countCandidateOnly($cursor->current(), $stats);
            $cursor->next();
        }
        $stats['candidate_rows'] = $stats['content_matches'] + $stats['content_mismatches']
            + $stats['candidate_only_unexplained'] + $stats['tombstones'];

        return $stats;
    }

    /** @return Generator<int, object> */
    private function candidateRows(int $generationId): Generator
    {
        $last = null;
        while (true) {
            $query = $this->store->rows($generationId)->select([
                'version.salesforce_id', 'version.content_hash', 'version.is_deleted', 'version.deletion_detection_source',
            ])->orderBy('version.salesforce_id')->limit(self::PAGE_LOOKUP_SIZE);
            if ($last !== null) {
                $query->where('version.salesforce_id', '>', $last);
            }
            $rows = $query->get();
            if ($rows->isEmpty()) {
                return;
            }
            foreach ($rows as $row) {
                $last = $row->salesforce_id;
                yield $row;
            }
        }
    }

    /** @param array<string, int> $stats */
    private function countCandidateOnly(object $row, array &$stats): void
    {
        if ((bool) $row->is_deleted && $row->deletion_detection_source === 'get_deleted') {
            $stats['tombstones']++;

            return;
        }
        $stats['candidate_only_unexplained']++;
    }

    /** @param array<string, int> $verification */
    private function isClean(array $verification): bool
    {
        return $verification['content_mismatches'] === 0
            && $verification['salesforce_only'] === 0
            && $verification['candidate_only_unexplained'] === 0
            && $verification['conflicts'] === 0;
    }
}
