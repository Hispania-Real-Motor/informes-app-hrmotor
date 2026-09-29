<?php

namespace App\Services\Salesforce;

use App\Models\ReportSyncRun;
use App\Models\SalesforceInterest;
use App\Models\SalesforceInterestSyncError;
use App\Services\Reports\ReportSyncRunService;
use App\Support\IntegrationErrorSanitizer;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class SalesforceInterestSyncService
{
    public const DATASET = 'salesforce_interests';

    public const SOURCE = 'salesforce';

    public const MODE_FULL = 'full';

    public const MODE_INCREMENTAL = 'incremental';

    public const DELETION_SOURCE_QUERY_ALL = 'query_all_deleted';

    private const UTC = 'UTC';

    private const PERSIST_CHUNK_SIZE = 200;

    public function __construct(
        private readonly SalesforceClient $client,
        private readonly SalesforceInterestFoundationResolver $foundationResolver,
        private readonly SalesforceInterestChunkPersister $chunkPersister,
        private readonly ReportSyncRunService $syncRuns,
    ) {}

    /** @return array<string, mixed> */
    public function sync(
        string $mode = self::MODE_INCREMENTAL,
        ?CarbonInterface $cutoff = null,
        ?int $overlapSeconds = null,
    ): array {
        if (! in_array($mode, [self::MODE_FULL, self::MODE_INCREMENTAL], true)) {
            throw new InvalidArgumentException('Interest sync mode must be full or incremental.');
        }

        $cutoff = CarbonImmutable::parse($cutoff ?? now(self::UTC))->utc()->startOfSecond();
        $overlapSeconds ??= (int) config('salesforce.interest_sync_overlap_seconds', 300);

        if ($overlapSeconds < 0) {
            throw new InvalidArgumentException('Interest sync overlap must be greater than or equal to zero.');
        }
        $watermark = $mode === self::MODE_INCREMENTAL ? $this->lastCompletedCutoff() : null;

        if ($mode === self::MODE_INCREMENTAL && $watermark === null) {
            throw new RuntimeException('Interest incremental sync requires a completed watermark. Run full mode first.');
        }

        $windowStart = $watermark?->subSeconds($overlapSeconds);
        $run = $this->syncRuns->start(
            self::DATASET,
            self::SOURCE,
            $windowStart ?? CarbonImmutable::create(1970, 1, 1, 0, 0, 0, self::UTC),
            $cutoff,
            self::UTC,
        );
        $stats = $this->emptyStats($mode, $windowStart, $cutoff, $overlapSeconds);

        try {
            $this->syncPages($run, $this->activeSoql($windowStart, $cutoff), false, 'active', $stats);
            $this->syncPages($run, $this->deletedSoql($windowStart, $cutoff), true, 'deleted', $stats);
            $this->syncRuns->complete($run, $cutoff, $stats);

            return ['run' => $run->fresh(), 'stats' => $stats];
        } catch (Throwable $exception) {
            $stats['errors'] = SalesforceInterestSyncError::query()
                ->where('report_sync_run_id', $run->id)
                ->count();
            $run->update(['stats' => $stats]);
            $this->syncRuns->fail($run, $exception);

            throw new RuntimeException(
                IntegrationErrorSanitizer::sanitizeMessage($exception->getMessage()),
            );
        }
    }

    public function activeSoql(?CarbonInterface $windowStart, CarbonInterface $cutoff): string
    {
        return $this->soql(false, $windowStart, $cutoff);
    }

    public function deletedSoql(?CarbonInterface $windowStart, CarbonInterface $cutoff): string
    {
        return $this->soql(true, $windowStart, $cutoff);
    }

    private function soql(bool $deleted, ?CarbonInterface $windowStart, CarbonInterface $cutoff): string
    {
        $lowerBound = $windowStart === null
            ? ''
            : '    AND SystemModstamp >= '.$this->soqlDateTime($windowStart)."\n";
        $deletedValue = $deleted ? 'true' : 'false';

        return <<<SOQL
SELECT
    Id,
    CreatedDate,
    LastModifiedDate,
    SystemModstamp,
    IsDeleted,
    IN_Lead__c,
    IN_Account__c,
    IN_Lead_Origen_Migracion__c,
    IN_Fecha_Creacion_Origen__c,
    OwnerId,
    Owner.Name,
    IN_Estado__c,
    IN_Tipo__c,
    IN_Fuente_Origen__c,
    IN_RP_Fuente_Original__c,
    IN_Medio_Origen__c,
    IN_Canal__c,
    IN_Delegacion_Procedencia__c,
    IN_utm_campaign__c,
    IN_utm_id__c,
    IN_utm_source__c,
    IN_utm_medium__c,
    IN_utm_content__c,
    IN_utm_term__c,
    IN_VEN_Vehiculo__c,
    IN_TAS_Vehiculo__c,
    IN_VEN_Oportunidad__c
FROM Interes__c
WHERE
    IsDeleted = {$deletedValue}
{$lowerBound}    AND SystemModstamp <= {$this->soqlDateTime($cutoff)}
ORDER BY SystemModstamp ASC, Id ASC
SOQL;
    }

    /** @param array<string, mixed> $stats */
    private function syncPages(
        ReportSyncRun $run,
        string $soql,
        bool $includeDeleted,
        string $phase,
        array &$stats,
    ): void {
        $processingPage = false;

        try {
            foreach ($this->client->queryPages($soql, $includeDeleted) as $records) {
                $processingPage = true;
                $stats['pages']++;
                $stats['queried'] += count($records);
                $rows = [];

                foreach ($records as $record) {
                    $salesforceId = trim((string) data_get($record, 'Id'));

                    try {
                        if ($salesforceId === '') {
                            throw new RuntimeException('Salesforce Interest record has no Id.');
                        }

                        $rows[] = $this->mapRecord($record, $includeDeleted);
                    } catch (Throwable $exception) {
                        $this->recordError($run, $salesforceId ?: null, $phase.'_map', $exception);
                        throw $exception;
                    }
                }

                foreach (array_chunk($rows, self::PERSIST_CHUNK_SIZE) as $chunk) {
                    $this->persistChunk($run, $chunk, $phase, $stats);
                }

                $processingPage = false;
            }
        } catch (Throwable $exception) {
            if (! $processingPage) {
                $this->recordError($run, null, $phase.'_query', $exception);
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $record */
    private function mapRecord(array $record, bool $deleted): array
    {
        $row = [
            'salesforce_id' => data_get($record, 'Id'),
            'lead_salesforce_id' => data_get($record, 'IN_Lead__c'),
            'account_salesforce_id' => data_get($record, 'IN_Account__c'),
            'migration_origin_lead_id' => data_get($record, 'IN_Lead_Origen_Migracion__c'),
            'salesforce_created_at' => $this->parseDateTime(data_get($record, 'CreatedDate')),
            'salesforce_last_modified_at' => $this->parseDateTime(data_get($record, 'LastModifiedDate')),
            'origin_created_at' => $this->parseDateTime(data_get($record, 'IN_Fecha_Creacion_Origen__c')),
            'owner_salesforce_id' => data_get($record, 'OwnerId'),
            'owner_name' => data_get($record, 'Owner.Name'),
            'status' => data_get($record, 'IN_Estado__c'),
            'type' => data_get($record, 'IN_Tipo__c'),
            'source' => data_get($record, 'IN_Fuente_Origen__c'),
            'original_source' => data_get($record, 'IN_RP_Fuente_Original__c'),
            'medium' => data_get($record, 'IN_Medio_Origen__c'),
            'channel' => data_get($record, 'IN_Canal__c'),
            'origin_delegation' => data_get($record, 'IN_Delegacion_Procedencia__c'),
            'utm_campaign' => data_get($record, 'IN_utm_campaign__c'),
            'utm_id' => data_get($record, 'IN_utm_id__c'),
            'utm_source' => data_get($record, 'IN_utm_source__c'),
            'utm_medium' => data_get($record, 'IN_utm_medium__c'),
            'utm_content' => data_get($record, 'IN_utm_content__c'),
            'utm_term' => data_get($record, 'IN_utm_term__c'),
            'sale_vehicle_salesforce_id' => data_get($record, 'IN_VEN_Vehiculo__c'),
            'appraisal_vehicle_salesforce_id' => data_get($record, 'IN_TAS_Vehiculo__c'),
            'inverse_opportunity_salesforce_id' => data_get($record, 'IN_VEN_Oportunidad__c'),
            'raw_payload' => json_encode($this->withoutAttributesMetadata($record), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'synced_at' => CarbonImmutable::now(self::UTC)->startOfSecond(),
            'is_deleted' => $deleted,
            'salesforce_deleted_at' => $deleted ? $this->parseDateTime(data_get($record, 'SystemModstamp')) : null,
            'deletion_detection_source' => $deleted ? self::DELETION_SOURCE_QUERY_ALL : null,
        ];

        return $this->foundationResolver->materialize($row);
    }

    /** @param list<array<string, mixed>> $rows @param array<string, mixed> $stats */
    private function persistChunk(ReportSyncRun $run, array $rows, string $phase, array &$stats): void
    {
        $reactivatedIds = $phase === 'active'
            ? SalesforceInterest::query()
                ->whereIn('salesforce_id', array_column($rows, 'salesforce_id'))
                ->where('is_deleted', true)
                ->pluck('salesforce_id')
                ->all()
            : [];

        try {
            $result = $this->chunkPersister->persist($rows);
        } catch (SalesforceInterestPersistenceConflict $conflict) {
            foreach ($conflict->salesforceIds as $salesforceId) {
                $this->recordPersistenceError($run, $salesforceId, $phase.'_persist', $conflict);
            }

            throw new RuntimeException('Interest chunk contains conflicting migration origins.');
        } catch (Throwable $bulkException) {
            $this->recordPersistenceError($run, null, $phase.'_persist_bulk', $bulkException);

            foreach ($rows as $row) {
                try {
                    $this->chunkPersister->persist([$row]);
                } catch (Throwable $rowException) {
                    $this->recordPersistenceError(
                        $run,
                        (string) $row['salesforce_id'],
                        $phase.'_persist',
                        $rowException,
                    );
                }
            }

            throw new RuntimeException('Interest chunk bulk persistence failed.');
        }

        $stats['inserted'] += $result['inserted'];
        $stats['updated'] += $result['updated'];
        $stats['unchanged'] += $result['unchanged'];
        $stats['reactivated'] += count($reactivatedIds);
        $stats['deleted'] += $phase === 'deleted' ? count($rows) : 0;
    }

    private function recordError(ReportSyncRun $run, ?string $salesforceId, string $phase, Throwable $exception): void
    {
        SalesforceInterestSyncError::query()->create([
            'report_sync_run_id' => $run->id,
            'salesforce_id' => $salesforceId,
            'phase' => mb_substr($phase, 0, 32),
            'error_code' => mb_substr(class_basename($exception), 0, 120),
            'error_message' => IntegrationErrorSanitizer::sanitizeMessage($exception->getMessage(), 1000),
            'occurred_at' => now(self::UTC),
        ]);
    }

    private function recordPersistenceError(
        ReportSyncRun $run,
        ?string $salesforceId,
        string $phase,
        Throwable $exception,
    ): void {
        $technicalCode = $exception instanceof QueryException
            ? (string) ($exception->errorInfo[0] ?? 'database_query_error')
            : class_basename($exception);

        SalesforceInterestSyncError::query()->create([
            'report_sync_run_id' => $run->id,
            'salesforce_id' => $salesforceId,
            'phase' => mb_substr($phase, 0, 32),
            'error_code' => mb_substr($technicalCode, 0, 120),
            'error_message' => 'Interest record could not be persisted locally.',
            'occurred_at' => now(self::UTC),
        ]);
    }

    private function lastCompletedCutoff(): ?CarbonImmutable
    {
        $cutoff = ReportSyncRun::query()
            ->where('dataset', self::DATASET)
            ->where('source', self::SOURCE)
            ->where('status', 'completed')
            ->whereNotNull('source_cutoff_at')
            ->orderByDesc('source_cutoff_at')
            ->value('source_cutoff_at');

        return $cutoff === null ? null : CarbonImmutable::parse($cutoff)->utc();
    }

    /** @return array<string, mixed> */
    private function emptyStats(string $mode, ?CarbonInterface $windowStart, CarbonInterface $cutoff, int $overlapSeconds): array
    {
        return [
            'mode' => $mode,
            'window_start' => $windowStart?->utc()->toIso8601String(),
            'window_end' => $cutoff->utc()->toIso8601String(),
            'overlap_seconds' => $overlapSeconds,
            'pages' => 0,
            'queried' => 0,
            'inserted' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'deleted' => 0,
            'reactivated' => 0,
            'errors' => 0,
            'cutoff' => $cutoff->utc()->toIso8601String(),
        ];
    }

    private function soqlDateTime(CarbonInterface $date): string
    {
        return CarbonImmutable::parse($date)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private function parseDateTime(mixed $value): ?CarbonImmutable
    {
        return blank($value) ? null : CarbonImmutable::parse($value)->utc();
    }

    private function withoutAttributesMetadata(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        unset($value['attributes']);

        foreach ($value as $key => $item) {
            $value[$key] = $this->withoutAttributesMetadata($item);
        }

        return $value;
    }
}
