<?php

namespace App\Services\Salesforce;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Builds bounded, read-only evidence directly from Salesforce for bootstrap review. */
final class InterestExternalEvidenceService
{
    private const SOURCE = 'isolated_salesforce_direct_extraction_v1';

    public function __construct(
        private readonly SalesforceClient $client,
    ) {}

    /** @return array<string, mixed> */
    public function collect(int $generationId): array
    {
        $snapshot = $this->snapshot($generationId);
        if ($snapshot['base_generation_id'] !== null) {
            throw new RuntimeException('External evidence is restricted to an initial generation.');
        }

        $cutoff = CarbonImmutable::parse($snapshot['cutoff_at'], 'UTC');
        $soql = 'SELECT Id, CreatedDate, LastModifiedDate, SystemModstamp, IsDeleted FROM Interes__c WHERE SystemModstamp <= '
            .$cutoff->format('Y-m-d\TH:i:s\Z').' ORDER BY Id ASC';
        $pages = 0;
        $rows = 0;
        $deletedCount = 0;
        $lastId = null;
        $identifiers = hash_init('sha256');
        foreach ($this->client->queryPages($soql, true) as $page) {
            $pages++;
            foreach ($page as $record) {
                $id = trim((string) data_get($record, 'Id'));
                if ($id === '' || ($lastId !== null && strcmp($id, $lastId) <= 0)) {
                    throw new RuntimeException('Salesforce evidence contains an invalid or duplicate identity.');
                }
                $lastId = $id;
                $deleted = (bool) data_get($record, 'IsDeleted', false);
                $deletedCount += (int) $deleted;
                $rows++;
                hash_update($identifiers, $id.'|'.(int) $deleted."\n");
            }
        }

        $relation = $this->relationships($cutoff);
        $concurrent = $this->snapshot($generationId) === $snapshot;
        // A later Salesforce snapshot cannot prove historical content at the cutoff.
        $certifiable = false;

        return [
            'source' => self::SOURCE,
            'identifiers_sha256' => hash_final($identifiers),
            'rows' => $rows,
            'deleted' => $deletedCount,
            'relationships_sha256' => $relation['hash'],
            'certifiable' => $certifiable,
            'reasons' => $certifiable ? [] : array_values(array_filter([
                $concurrent ? null : 'generation_changed_during_extraction',
                $rows === 0 ? 'empty_salesforce_snapshot' : null,
                $relation['unverifiable'] > 0 ? 'unverifiable_activity_history' : null,
                'historical_content_cannot_be_proven_from_a_later_snapshot',
            ])),
            'coverage_sha256' => hash('sha256', json_encode([
                'cutoff_at' => $cutoff->toIso8601String(),
                'interest_run_id' => (int) $snapshot['interest_run_id'],
                'activity_run_id' => (int) $snapshot['activity_run_id'],
                'pages' => $pages,
                'received' => $rows + $relation['rows'],
                'done' => true,
                'continuations_complete' => true,
                'relationships_pages' => $relation['pages'],
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    /** @return array{hash: string, pages: int} */
    private function relationships(CarbonImmutable $cutoff): array
    {
        $hash = hash_init('sha256');
        $relationPages = 0;
        $relationRows = 0;
        foreach (['Task', 'Event'] as $kind) {
            $fields = $kind === 'Task'
                ? SalesforceInterestActivitySyncService::TASK_SOQL_FIELDS
                : SalesforceInterestActivitySyncService::EVENT_SOQL_FIELDS;
            $soql = 'SELECT '.$fields." FROM {$kind} WHERE What.Type = 'Interes__c' AND SystemModstamp <= "
                .$cutoff->format('Y-m-d\TH:i:s\Z').' ORDER BY SystemModstamp ASC, Id ASC';
            foreach ($this->client->queryPages($soql, true) as $records) {
                $relationPages++;
                $relationRows += count($records);
                foreach ($records as $record) {
                    hash_update($hash, $kind.'|'.json_encode([
                        'Id' => data_get($record, 'Id'),
                        'WhatId' => data_get($record, 'WhatId'),
                        'IsDeleted' => (bool) data_get($record, 'IsDeleted', false),
                    ], JSON_THROW_ON_ERROR)."\n");
                }
            }
        }

        return ['hash' => hash_final($hash), 'pages' => $relationPages, 'rows' => $relationRows, 'unverifiable' => 0];
    }

    /** @return array<string, mixed> */
    private function snapshot(int $generationId): array
    {
        $row = DB::table('salesforce_interest_generations')->where('id', $generationId)->first([
            'id', 'base_generation_id', 'status', 'cutoff_at', 'interest_run_id', 'activity_run_id', 'f2_complete', 'f5_complete',
        ]);
        if ($row === null) {
            throw new RuntimeException('Generation does not exist.');
        }

        return (array) $row;
    }

}
