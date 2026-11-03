<?php

namespace App\Services\Salesforce;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Resumable, read-only Salesforce comparison for a functional period.
 *
 * It deliberately creates neither an Interest generation nor a publication:
 * present-state SOQL cannot prove historical entries/exits by itself. The
 * persisted journal is preparation evidence for a later, independently
 * certified period publication.
 */
final class InterestPeriodRecoveryService
{
    private const LOOKUP_SIZE = 500;

    public function __construct(
        private readonly SalesforceClient $client,
        private readonly SalesforceInterestSyncService $interests,
        private readonly InterestGenerationStore $store,
    ) {}

    public function prepare(int $sourceGenerationId, CarbonInterface $start, CarbonInterface $end, string $reason): int
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw new RuntimeException('Period recovery requires a bounded operational reason.');
        }
        $start = CarbonImmutable::parse($start, 'Europe/Madrid')->startOfDay()->utc();
        $end = CarbonImmutable::parse($end, 'Europe/Madrid')->startOfDay()->utc();
        if ($end->lte($start) || $end->diffInDays($start) > 62) {
            throw new RuntimeException('Period recovery requires a bounded semi-open functional range.');
        }

        return DB::transaction(function () use ($sourceGenerationId, $start, $end, $reason): int {
            $pointer = DB::table('salesforce_interest_publication')->where('id', 1)->lockForUpdate()->first();
            $source = DB::table('salesforce_interest_generations')->where('id', $sourceGenerationId)->lockForUpdate()->first();
            if ($pointer === null || $source === null || $source->base_generation_id !== null
                || $source->status !== 'validating' || (bool) $source->f2_complete === false || (bool) $source->f5_complete === false
                || DB::table('salesforce_interest_conflicts')->where('generation_id', $sourceGenerationId)->exists()) {
                throw new RuntimeException('Period recovery source is not an intact initial seed.');
            }
            $existing = DB::table('salesforce_interest_recovery_periods')
                ->where('source_generation_id', $sourceGenerationId)
                ->where('functional_start_at', $start)->where('functional_end_at', $end)->lockForUpdate()->first();
            if ($existing !== null) {
                return (int) $existing->id;
            }
            $expected = $this->sourceRows($sourceGenerationId, $start, $end)->count();

            return DB::table('salesforce_interest_recovery_periods')->insertGetId([
                'source_generation_id' => $sourceGenerationId,
                'functional_start_at' => $start,
                'functional_end_at' => $end,
                'timezone' => 'Europe/Madrid',
                'status' => 'prepared',
                'phase' => 'preparation',
                'pages_completed' => 0,
                'rows_expected' => $expected,
                'counters' => json_encode($this->emptyCounters(), JSON_THROW_ON_ERROR),
                'diagnostics' => json_encode(['coverage' => 'unverified'], JSON_THROW_ON_ERROR),
                'reason_hash' => hash('sha256', $reason),
                'started_at' => now('UTC'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /** @return array<string, mixed> */
    public function run(int $periodId, bool $resume = false): array
    {
        [$period, $lease] = DB::transaction(function () use ($periodId, $resume): array {
            $period = DB::table('salesforce_interest_recovery_periods')->where('id', $periodId)->lockForUpdate()->first();
            $staleRunning = $resume && $period !== null && $period->status === 'running'
                && $period->heartbeat_at !== null && CarbonImmutable::parse($period->heartbeat_at, 'UTC')->lte(now('UTC')->subMinutes(15));
            if ($period === null || (in_array($period->status, ['prepared', 'failed'], true) === false && $staleRunning === false)) {
                throw new RuntimeException('Period recovery is not resumable.');
            }
            $lease = (string) Str::uuid();
            DB::table('salesforce_interest_recovery_periods')->where('id', $periodId)->update([
                'status' => 'running', 'phase' => 'salesforce_query', 'lease_token' => $lease, 'heartbeat_at' => now('UTC'),
                'failed_at' => null, 'error_code' => null, 'updated_at' => now(),
            ]);

            return [$period, $lease];
        });

        try {
            $this->assertSourceStillIntact($period);
            $start = CarbonImmutable::parse($period->functional_start_at, 'UTC');
            $end = CarbonImmutable::parse($period->functional_end_at, 'UTC');
            $checkpoint = $period->checkpoint_salesforce_id;
            $this->reconcileDeletionLog($periodId, $lease, $start, $end);
            foreach ($this->client->queryPages($this->interests->functionalPeriodSoql($start, $end, $checkpoint), false) as $records) {
                $this->processPage($periodId, $lease, $records, $start, $end);
            }
            $this->finishComparison($periodId, $lease, $start, $end);

            return $this->status($periodId);
        } catch (Throwable $exception) {
            DB::table('salesforce_interest_recovery_periods')->where('id', $periodId)->where('lease_token', $lease)->update([
                'status' => 'failed', 'phase' => 'failed', 'failed_at' => now('UTC'), 'error_code' => 'recovery_read_or_compare_failed', 'updated_at' => now(),
            ]);
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function status(int $periodId): array
    {
        $period = DB::table('salesforce_interest_recovery_periods')->find($periodId)
            ?? throw new RuntimeException('Period recovery does not exist.');

        return [
            'period_id' => (int) $period->id,
            'source_generation_id' => (int) $period->source_generation_id,
            'functional_start_at' => (string) $period->functional_start_at,
            'functional_end_at' => (string) $period->functional_end_at,
            'status' => $period->status,
            'phase' => $period->phase,
            'pages_completed' => (int) $period->pages_completed,
            'rows_expected' => $period->rows_expected === null ? null : (int) $period->rows_expected,
            'counters' => json_decode($period->counters, true, 64, JSON_THROW_ON_ERROR),
            'diagnostics' => json_decode($period->diagnostics, true, 64, JSON_THROW_ON_ERROR),
            'checkpoint_present' => $period->checkpoint_salesforce_id !== null,
            'elapsed_seconds' => CarbonImmutable::parse($period->started_at)->diffInSeconds(now('UTC')),
        ];
    }

    /** @param list<array<string, mixed>> $records */
    private function processPage(int $periodId, string $lease, array $records, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $period = DB::table('salesforce_interest_recovery_periods')->find($periodId);
        if ($period === null || $period->status !== 'running' || $period->lease_token !== $lease) {
            throw new RuntimeException('Period recovery state changed concurrently.');
        }
        $rows = [];
        $lastId = (string) ($period->checkpoint_salesforce_id ?? '');
        foreach ($records as $record) {
            $row = $this->interests->mapRecord($record, false);
            $id = (string) $row['salesforce_id'];
            if (preg_match('/^[A-Za-z0-9]{18}$/', $id) !== 1 || ($lastId !== '' && strcmp($id, $lastId) <= 0)) {
                throw new RuntimeException('Salesforce period cursor is invalid.');
            }
            $functional = $row['functional_created_at'] === null ? null : CarbonImmutable::parse($row['functional_created_at'])->utc();
            if ($functional === null || $functional->lt($start) || $functional->gte($end)) {
                throw new RuntimeException('Salesforce period filter is inconsistent with mapped content.');
            }
            $lastId = $id;
            $rows[] = $row;
        }
        if ($rows === []) {
            return;
        }
        foreach (array_chunk($rows, self::LOOKUP_SIZE) as $chunk) {
            $this->compareChunk($periodId, $lease, $chunk, $start, $end);
        }
        DB::table('salesforce_interest_recovery_periods')->where('id', $periodId)->where('lease_token', $lease)->update([
            'checkpoint_salesforce_id' => $lastId,
            'pages_completed' => DB::raw('pages_completed + 1'),
            'heartbeat_at' => now('UTC'),
            'updated_at' => now(),
        ]);
    }

    /** @param list<array<string, mixed>> $rows */
    private function compareChunk(int $periodId, string $lease, array $rows, CarbonImmutable $start, CarbonImmutable $end): void
    {
        DB::transaction(function () use ($periodId, $lease, $rows, $start, $end): void {
            $period = DB::table('salesforce_interest_recovery_periods')->where('id', $periodId)->lockForUpdate()->first();
            if ($period === null || $period->status !== 'running' || $period->lease_token !== $lease) {
                throw new RuntimeException('Period recovery state changed concurrently.');
            }
            $ids = array_column($rows, 'salesforce_id');
            $local = $this->store->rows((int) $period->source_generation_id)
                ->whereIn('version.salesforce_id', $ids)->get()->keyBy('salesforce_id');
            $deletions = DB::table('salesforce_interest_recovery_period_differences')
                ->where('recovery_period_id', $periodId)->where('kind', 'deleted_verified')
                ->whereIn('salesforce_id', $ids)->pluck('salesforce_id')->flip();
            $counters = json_decode($period->counters, true, 64, JSON_THROW_ON_ERROR);
            foreach ($rows as $row) {
                $hash = $this->store->hash($row);
                if (DB::table('salesforce_interest_recovery_period_seen')->insertOrIgnore([
                    'recovery_period_id' => $periodId, 'salesforce_id' => $row['salesforce_id'], 'content_hash' => $hash,
                ]) !== 1) {
                    continue;
                }
                $counters['remote_rows']++;
                $source = $local->get($row['salesforce_id']);
                $wasInPeriod = $source !== null && $source->functional_created_at !== null
                    && CarbonImmutable::parse($source->functional_created_at)->utc()->gte($start)
                    && CarbonImmutable::parse($source->functional_created_at)->utc()->lt($end);
                $kind = $deletions->has($row['salesforce_id']) ? 'reactivated' : ($source === null ? 'new' : ($wasInPeriod === false
                    ? 'entered_period' : (hash_equals($source->content_hash, $hash) === false ? 'content_mismatch' : null)));
                if ($kind === null) {
                    $counters['identical']++;

                    continue;
                }
                if ($kind === 'reactivated') {
                    $counters['reactivated']++;
                } else {
                    $counters[$kind === 'new' ? 'new' : 'modified']++;
                }
                $counters['discrepancies']++;
                $difference = [
                    'recovery_period_id' => $periodId, 'salesforce_id' => $row['salesforce_id'], 'kind' => $kind,
                    'observed_hash' => $hash, 'source_hash' => $source?->content_hash, 'observed_at' => now('UTC'),
                ];
                if ($kind === 'reactivated') {
                    DB::table('salesforce_interest_recovery_period_differences')->where('recovery_period_id', $periodId)
                        ->where('salesforce_id', $row['salesforce_id'])->update($difference);
                } else {
                    DB::table('salesforce_interest_recovery_period_differences')->insertOrIgnore($difference);
                }
            }
            DB::table('salesforce_interest_recovery_periods')->where('id', $periodId)->update([
                'counters' => json_encode($counters, JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);
        });
    }

    private function finishComparison(int $periodId, string $lease, CarbonImmutable $start, CarbonImmutable $end): void
    {
        DB::transaction(function () use ($periodId, $lease, $start, $end): void {
            $period = DB::table('salesforce_interest_recovery_periods')->where('id', $periodId)->lockForUpdate()->first();
            if ($period === null || $period->status !== 'running' || $period->lease_token !== $lease) {
                throw new RuntimeException('Period recovery state changed concurrently.');
            }
            $candidateOnly = $this->sourceRows((int) $period->source_generation_id, $start, $end)
                ->leftJoin('salesforce_interest_recovery_period_seen as seen', function ($join) use ($periodId): void {
                    $join->on('seen.salesforce_id', '=', 'version.salesforce_id')->where('seen.recovery_period_id', '=', $periodId);
                })->whereNull('seen.salesforce_id')
                ->whereNotExists(function (Builder $query) use ($periodId): void {
                    $query->selectRaw('1')->from('salesforce_interest_recovery_period_differences as difference')
                        ->whereColumn('difference.salesforce_id', 'version.salesforce_id')
                        ->where('difference.recovery_period_id', $periodId)->where('difference.kind', 'deleted_verified');
                })->count();
            $this->recordCandidateOnlyDifferences($periodId, (int) $period->source_generation_id, $start, $end);
            $counters = json_decode($period->counters, true, 64, JSON_THROW_ON_ERROR);
            $counters['candidate_only'] = $candidateOnly;
            $counters['discrepancies'] += $candidateOnly;
            $diagnostics = [
                ...json_decode($period->diagnostics, true, 64, JSON_THROW_ON_ERROR),
                'coverage' => 'insufficient_historical_entry_exit_evidence',
                'certifiable' => false,
                'publication_compatible' => false,
                'candidate_only' => $candidateOnly,
                'f5' => 'pending_independent_period_coverage',
            ];
            DB::table('salesforce_interest_recovery_periods')->where('id', $periodId)->update([
                'status' => 'reconciled_unverified', 'phase' => 'coverage_blocked', 'counters' => json_encode($counters, JSON_THROW_ON_ERROR),
                'diagnostics' => json_encode($diagnostics, JSON_THROW_ON_ERROR), 'completed_at' => now('UTC'), 'updated_at' => now(),
            ]);
        });
    }

    private function reconcileDeletionLog(int $periodId, string $lease, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $period = DB::table('salesforce_interest_recovery_periods')->where('id', $periodId)->first();
        if ($period === null || $period->lease_token !== $lease) {
            throw new RuntimeException('Period recovery lease changed concurrently.');
        }
        $source = $this->store->generation((int) $period->source_generation_id);
        $cutoff = CarbonImmutable::parse($source->cutoff_at, 'UTC');
        $through = CarbonImmutable::now('UTC')->subMinutes(2)->startOfMinute();
        $log = $this->client->getDeleted('Interes__c', $cutoff, $through);
        $earliest = CarbonImmutable::parse($log['earliestDateAvailable'])->utc();
        $latest = CarbonImmutable::parse($log['latestDateCovered'])->utc();
        $covered = $earliest->lte($cutoff) && $latest->gte($through);
        $deleted = [];
        foreach ($log['deletedRecords'] as $record) {
            $id = trim((string) ($record['id'] ?? ''));
            if (preg_match('/^[A-Za-z0-9]{18}$/', $id) !== 1) {
                throw new RuntimeException('Salesforce deletion log record is invalid.');
            }
            $deleted[] = $id;
        }
        foreach (array_chunk($deleted, self::LOOKUP_SIZE) as $ids) {
            $local = $this->sourceRows((int) $period->source_generation_id, $start, $end)
                ->whereIn('version.salesforce_id', $ids)->get(['version.salesforce_id', 'version.content_hash']);
            $inserts = $local->map(fn (object $row): array => [
                'recovery_period_id' => $periodId, 'salesforce_id' => $row->salesforce_id,
                'kind' => 'deleted_verified', 'observed_hash' => null, 'source_hash' => $row->content_hash,
                'observed_at' => now('UTC'),
            ])->all();
            if ($inserts !== []) {
                DB::transaction(function () use ($periodId, $lease, $inserts): void {
                    $locked = DB::table('salesforce_interest_recovery_periods')->where('id', $periodId)->lockForUpdate()->first();
                    if ($locked === null || $locked->status !== 'running' || $locked->lease_token !== $lease) {
                        throw new RuntimeException('Period recovery lease changed concurrently.');
                    }
                    DB::table('salesforce_interest_recovery_period_differences')->insertOrIgnore($inserts);
                    DB::table('salesforce_interest_recovery_periods')->where('id', $periodId)->where('lease_token', $lease)->update([
                        'heartbeat_at' => now('UTC'), 'updated_at' => now(),
                    ]);
                });
            }
        }
        DB::transaction(function () use ($periodId, $lease, $covered, $earliest, $latest): void {
            $period = DB::table('salesforce_interest_recovery_periods')->where('id', $periodId)->lockForUpdate()->first();
            if ($period === null || $period->lease_token !== $lease) {
                throw new RuntimeException('Period recovery lease changed concurrently.');
            }
            $counters = json_decode($period->counters, true, 64, JSON_THROW_ON_ERROR);
            $counters['deleted'] = DB::table('salesforce_interest_recovery_period_differences')
                ->where('recovery_period_id', $periodId)->where('kind', 'deleted_verified')->count();
            $diagnostics = json_decode($period->diagnostics, true, 64, JSON_THROW_ON_ERROR);
            $diagnostics['deletion_coverage'] = $covered ? 'covered' : 'insufficient';
            $diagnostics['deletion_earliest_available'] = $earliest->toIso8601String();
            $diagnostics['deletion_latest_covered'] = $latest->toIso8601String();
            DB::table('salesforce_interest_recovery_periods')->where('id', $periodId)->update([
                'phase' => 'salesforce_query', 'counters' => json_encode($counters, JSON_THROW_ON_ERROR),
                'diagnostics' => json_encode($diagnostics, JSON_THROW_ON_ERROR), 'heartbeat_at' => now('UTC'), 'updated_at' => now(),
            ]);
        });
    }

    private function assertSourceStillIntact(object $period): void
    {
        $source = $this->store->generation((int) $period->source_generation_id);
        if ($source->base_generation_id !== null || $source->status !== 'validating' || (bool) $source->f2_complete === false || (bool) $source->f5_complete === false) {
            throw new RuntimeException('Period recovery source changed and can no longer be used.');
        }
    }

    private function recordCandidateOnlyDifferences(int $periodId, int $generationId, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $batch = [];
        $query = $this->sourceRows($generationId, $start, $end)
            ->leftJoin('salesforce_interest_recovery_period_seen as seen', function ($join) use ($periodId): void {
                $join->on('seen.salesforce_id', '=', 'version.salesforce_id')->where('seen.recovery_period_id', '=', $periodId);
            })->whereNull('seen.salesforce_id')->whereNotExists(function (Builder $query) use ($periodId): void {
                $query->selectRaw('1')->from('salesforce_interest_recovery_period_differences as difference')
                    ->whereColumn('difference.salesforce_id', 'version.salesforce_id')
                    ->where('difference.recovery_period_id', $periodId)->where('difference.kind', 'deleted_verified');
            })
            ->orderBy('version.salesforce_id')
            ->select(['version.salesforce_id', 'version.content_hash']);
        foreach ($query->cursor() as $row) {
            $batch[] = [
                'recovery_period_id' => $periodId, 'salesforce_id' => $row->salesforce_id,
                'kind' => 'candidate_only', 'observed_hash' => null, 'source_hash' => $row->content_hash,
                'observed_at' => now('UTC'),
            ];
            if (count($batch) === self::LOOKUP_SIZE) {
                DB::table('salesforce_interest_recovery_period_differences')->insertOrIgnore($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            DB::table('salesforce_interest_recovery_period_differences')->insertOrIgnore($batch);
        }
    }

    private function sourceRows(int $generationId, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return $this->store->rows($generationId)->where('version.is_deleted', false)
            ->where('version.functional_created_at', '>=', $start)->where('version.functional_created_at', '<', $end);
    }

    /** @return array<string, int> */
    private function emptyCounters(): array
    {
        return ['remote_rows' => 0, 'identical' => 0, 'new' => 0, 'modified' => 0, 'deleted' => 0, 'reactivated' => 0, 'candidate_only' => 0, 'discrepancies' => 0];
    }
}
