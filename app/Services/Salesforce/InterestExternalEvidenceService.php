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
        private readonly SalesforceInterestSyncService $interests,
    ) {}

    /** @return array<string, mixed> */
    public function collect(int $generationId): array
    {
        $generation = DB::table('salesforce_interest_generations')->where('id', $generationId)->first();
        if ($generation === null) {
            throw new RuntimeException('Generation does not exist.');
        }
        if ($generation->base_generation_id !== null) {
            throw new RuntimeException('External evidence is restricted to an initial generation.');
        }

        $cutoff = CarbonImmutable::parse($generation->cutoff_at, 'UTC');
        $records = [];
        $pages = 0;
        $declared = null;
        foreach ([false, true] as $deleted) {
            $soql = $deleted
                ? $this->interests->deletedSoql(null, $cutoff)
                : $this->interests->activeSoql(null, $cutoff);
            foreach ($this->client->queryPages($soql, $deleted) as $page) {
                $pages++;
                foreach ($page as $record) {
                    $id = trim((string) data_get($record, 'Id'));
                    if ($id === '') {
                        throw new RuntimeException('Salesforce evidence contains a record without an identifier.');
                    }
                    if (isset($records[$id]) && (bool) data_get($records[$id], 'IsDeleted') !== (bool) data_get($record, 'IsDeleted')) {
                        throw new RuntimeException('Salesforce evidence contains an identity with conflicting deletion state.');
                    }
                    $records[$id] = $record;
                }
            }
        }

        $relation = $this->relationships($cutoff, $pages);
        ksort($records);
        $identifiers = hash_init('sha256');
        $deletedCount = 0;
        foreach ($records as $id => $record) {
            $deleted = (bool) data_get($record, 'IsDeleted', false);
            $deletedCount += (int) $deleted;
            hash_update($identifiers, $id.'|'.hash('sha256', $this->canonical($record)).'|'.(int) $deleted."\n");
        }

        return [
            'source' => self::SOURCE,
            'identifiers_sha256' => hash_final($identifiers),
            'rows' => count($records),
            'deleted' => $deletedCount,
            'relationships_sha256' => $relation['hash'],
            'coverage_sha256' => hash('sha256', json_encode([
                'cutoff_at' => $cutoff->toIso8601String(),
                'interest_run_id' => (int) $generation->interest_run_id,
                'activity_run_id' => (int) $generation->activity_run_id,
                'pages' => $pages,
                'received' => count($records),
                'done' => true,
                'continuations_complete' => true,
                'relationships_pages' => $relation['pages'],
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    /** @return array{hash: string, pages: int} */
    private function relationships(CarbonImmutable $cutoff, int &$pages): array
    {
        $hash = hash_init('sha256');
        $relationPages = 0;
        foreach (['Task', 'Event'] as $kind) {
            $fields = $kind === 'Task'
                ? SalesforceInterestActivitySyncService::TASK_SOQL_FIELDS
                : SalesforceInterestActivitySyncService::EVENT_SOQL_FIELDS;
            $soql = 'SELECT '.$fields." FROM {$kind} WHERE What.Type = 'Interes__c' AND SystemModstamp <= "
                .$cutoff->format('Y-m-d\TH:i:s\Z').' ORDER BY SystemModstamp ASC, Id ASC';
            foreach ($this->client->queryPages($soql, true) as $records) {
                $relationPages++;
                $pages++;
                foreach ($records as $record) {
                    hash_update($hash, $kind.'|'.json_encode([
                        'Id' => data_get($record, 'Id'),
                        'WhatId' => data_get($record, 'WhatId'),
                        'IsDeleted' => (bool) data_get($record, 'IsDeleted', false),
                    ], JSON_THROW_ON_ERROR)."\n");
                }
            }
        }

        return ['hash' => hash_final($hash), 'pages' => $relationPages];
    }

    private function canonical(array $record): string
    {
        $copy = $record;
        unset($copy['attributes']);
        $this->sort($copy);

        return json_encode($copy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function sort(array &$value): void
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->sort($item);
            }
        }
        unset($item);
        ksort($value);
    }
}
