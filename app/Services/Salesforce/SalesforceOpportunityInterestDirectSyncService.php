<?php

namespace App\Services\Salesforce;

use App\Models\SalesforceOpportunityInterestDirect;
use App\Models\SalesforceOpportunityInterestDirectRun;
use App\Support\IntegrationErrorSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SalesforceOpportunityInterestDirectSyncService
{
    public const LOCK_KEY = 'salesforce_opportunity_interest_direct_sync';

    public const SOQL_FIELDS = 'Id, HRM_Interes_Origen__c, IsDeleted, LastModifiedDate, SystemModstamp';

    private const LOCK_TTL_SECONDS = 21600;

    private const PERSIST_CHUNK_SIZE = 200;

    private const CLEANUP_CHUNK_SIZE = 1000;

    private const SALESFORCE_ID_PATTERN = '/^[A-Za-z0-9]{18}$/';

    public function __construct(
        private readonly SalesforceClient $client,
    ) {}

    /** @return array{run: SalesforceOpportunityInterestDirectRun, stats: array<string, int|float|string|null>} */
    public function sync(string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw new RuntimeException('Direct Opportunity Interest sync requires a reason between 10 and 500 characters.');
        }

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL_SECONDS);
        if (! $lock->get()) {
            throw new RuntimeException('Another direct Opportunity Interest sync is already running.');
        }

        $run = null;
        $cutoff = CarbonImmutable::now('UTC');
        $stats = $this->initialStats($cutoff);
        $startedAt = microtime(true);

        try {
            try {
                $run = SalesforceOpportunityInterestDirectRun::query()->create([
                    'run_identifier' => (string) Str::uuid(),
                    'reason' => $reason,
                    'status' => 'running',
                    'source_cutoff_at' => $cutoff,
                    'started_at' => now('UTC'),
                    'stats' => $stats,
                ]);

                $this->syncPages($run, $cutoff, $stats);
                $this->afterSnapshotBuilt($run, $stats);
                $this->assertSnapshotComplete($run, $stats);
                $stats['duration_seconds'] = round(microtime(true) - $startedAt, 3);

                $run->update([
                    'status' => 'completed',
                    'completed_at' => now('UTC'),
                    'stats' => $stats,
                    'error_message' => null,
                ]);
            } catch (Throwable) {
                $stats['errors']++;
                $stats['duration_seconds'] = round(microtime(true) - $startedAt, 3);
                try {
                    $run?->update([
                        'status' => 'failed',
                        'completed_at' => now('UTC'),
                        'stats' => $stats,
                        'error_message' => IntegrationErrorSanitizer::sanitizeMessage(
                            'Direct Opportunity Interest sync failed safely.',
                        ),
                    ]);
                } catch (Throwable) {
                }

                if ($run !== null) {
                    try {
                        $this->cleanupDetailedSnapshots($run, false);
                    } catch (Throwable) {
                        $stats['cleanup_errors']++;
                        try {
                            $run->update(['stats' => $stats]);
                        } catch (Throwable) {
                        }
                    }
                }

                throw new RuntimeException('Direct Opportunity Interest sync failed safely.');
            }

            try {
                $this->cleanupDetailedSnapshots($run, true);
            } catch (Throwable) {
                $stats['cleanup_errors']++;
                try {
                    $run->update(['stats' => $stats]);
                } catch (Throwable) {
                }
            }

            return ['run' => $run, 'stats' => $stats];
        } finally {
            $lock->release();
        }
    }

    /** @param array<string, int|float|string|null> $stats */
    private function syncPages(
        SalesforceOpportunityInterestDirectRun $run,
        CarbonImmutable $cutoff,
        array &$stats,
    ): void {
        foreach ($this->client->queryPages($this->soql($cutoff), true) as $records) {
            $stats['pages']++;
            $stats['queried'] += count($records);
            $rows = [];

            foreach ($records as $record) {
                $rows[] = $this->mapRecord($run, $record);
            }

            foreach (array_chunk($rows, self::PERSIST_CHUNK_SIZE) as $chunk) {
                DB::table('salesforce_opportunity_interest_directs')->insert($chunk);
                $stats['persisted'] += count($chunk);
                $stats['chunks']++;
                foreach ($chunk as $row) {
                    $stats[$row['opportunity_is_deleted'] ? 'deleted' : 'active']++;
                    $stats[$row['reference_status'] === 'valid' ? 'valid_references' : 'invalid_references']++;
                }
                $this->afterPersistChunk($run, $stats);
            }
        }
    }

    /** @param array<string, mixed> $record @return array<string, mixed> */
    private function mapRecord(SalesforceOpportunityInterestDirectRun $run, array $record): array
    {
        $opportunityId = trim((string) data_get($record, 'Id'));
        if (! $this->isValidSalesforceId($opportunityId)) {
            throw new RuntimeException('Salesforce Opportunity returned a non-canonical Id.');
        }

        $interestId = trim((string) data_get($record, 'HRM_Interes_Origen__c'));
        $referenceStatus = $this->isValidSalesforceId($interestId) ? 'valid' : 'invalid';
        $now = now();

        return [
            'direct_run_id' => $run->id,
            'opportunity_salesforce_id' => $opportunityId,
            'interest_salesforce_id' => $interestId === '' ? null : $interestId,
            'reference_status' => $referenceStatus,
            'opportunity_is_deleted' => (bool) data_get($record, 'IsDeleted', false),
            'salesforce_last_modified_at' => $this->parseDateTime(data_get($record, 'LastModifiedDate')),
            'system_modstamp_at' => $this->parseDateTime(data_get($record, 'SystemModstamp')),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function soql(CarbonImmutable $cutoff): string
    {
        return 'SELECT '.self::SOQL_FIELDS."\n"
            ."FROM Opportunity\n"
            ."WHERE HRM_Interes_Origen__c != null\n"
            .'    AND SystemModstamp <= '.$cutoff->format('Y-m-d\TH:i:s\Z')."\n"
            .'ORDER BY SystemModstamp ASC, Id ASC';
    }

    /** @param array<string, int|float|string|null> $stats */
    private function assertSnapshotComplete(
        SalesforceOpportunityInterestDirectRun $run,
        array $stats,
    ): void {
        $persisted = SalesforceOpportunityInterestDirect::query()
            ->where('direct_run_id', $run->id)
            ->count();
        if ($persisted !== $stats['queried'] || $persisted !== $stats['persisted']) {
            throw new RuntimeException('Direct Opportunity Interest snapshot is incomplete.');
        }
    }

    private function cleanupDetailedSnapshots(
        SalesforceOpportunityInterestDirectRun $currentRun,
        bool $completed,
    ): void {
        $protectedRunIds = [$currentRun->id];
        if (! $completed) {
            $latestCompleted = SalesforceOpportunityInterestDirectRun::query()
                ->where('status', 'completed')
                ->orderByDesc('completed_at')
                ->orderByDesc('id')
                ->value('id');
            if ($latestCompleted !== null) {
                $protectedRunIds[] = (int) $latestCompleted;
            }
        }

        while (true) {
            $ids = SalesforceOpportunityInterestDirect::query()
                ->whereNotIn('direct_run_id', $protectedRunIds)
                ->whereIn('direct_run_id', SalesforceOpportunityInterestDirectRun::query()
                    ->select('id')
                    ->whereIn('status', ['completed', 'failed']))
                ->orderBy('id')
                ->limit(self::CLEANUP_CHUNK_SIZE)
                ->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }

            DB::transaction(fn (): int => SalesforceOpportunityInterestDirect::query()
                ->whereIn('id', $ids->all())
                ->whereNotIn('direct_run_id', $protectedRunIds)
                ->delete());
            $this->afterCleanupChunk($ids->count());
        }
    }

    private function isValidSalesforceId(string $id): bool
    {
        return preg_match(self::SALESFORCE_ID_PATTERN, $id) === 1;
    }

    private function parseDateTime(mixed $value): ?CarbonImmutable
    {
        return blank($value) ? null : CarbonImmutable::parse($value)->utc();
    }

    /** @return array<string, int|float|string|null> */
    private function initialStats(CarbonImmutable $cutoff): array
    {
        return [
            'cutoff' => $cutoff->toIso8601String(),
            'pages' => 0,
            'queried' => 0,
            'persisted' => 0,
            'chunks' => 0,
            'active' => 0,
            'deleted' => 0,
            'valid_references' => 0,
            'invalid_references' => 0,
            'errors' => 0,
            'cleanup_errors' => 0,
            'duration_seconds' => 0.0,
        ];
    }

    /** @param array<string, int|float|string|null> $stats */
    protected function afterPersistChunk(
        SalesforceOpportunityInterestDirectRun $run,
        array $stats,
    ): void {}

    /** @param array<string, int|float|string|null> $stats */
    protected function afterSnapshotBuilt(
        SalesforceOpportunityInterestDirectRun $run,
        array $stats,
    ): void {}

    protected function afterCleanupChunk(int $rowsDeleted): void {}
}
