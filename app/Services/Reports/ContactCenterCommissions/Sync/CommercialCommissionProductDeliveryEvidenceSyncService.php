<?php

namespace App\Services\Reports\ContactCenterCommissions\Sync;

use App\Models\SalesforceOpportunity;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class CommercialCommissionProductDeliveryEvidenceSyncService
{
    public const DATASET = 'commercial_commission_product_delivery_evidence';

    private const PERSIST_CHUNK_SIZE = 200;

    private const PRODUCT_ID_QUERY_CHUNK_SIZE = 100;

    public function __construct(private readonly SalesforceClient $client) {}

    /** @return array{soql: string, queried: int, saved: int, opportunity_product_queries: int} */
    public function sync(CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        $soqls = [$this->soql($periodStart, $periodEnd)];
        $queried = 0;
        $saved = 0;

        [$queried, $saved] = $this->persistQuery($soqls[0], $queried, $saved);
        $productIds = SalesforceOpportunity::query()
            ->where('cv_signed', true)
            ->whereDate('cv_signed_date', '>=', CarbonImmutable::parse($periodStart)->toDateString())
            ->whereDate('cv_signed_date', '<', CarbonImmutable::parse($periodEnd)->toDateString())
            ->whereNotNull('vehicle_interest_id')
            ->distinct()
            ->pluck('vehicle_interest_id')
            ->map(fn (mixed $id): string => trim((string) $id))
            ->filter()
            ->values();

        foreach ($productIds->chunk(self::PRODUCT_ID_QUERY_CHUNK_SIZE) as $ids) {
            $soql = $this->soqlForIds($ids->all());
            $soqls[] = $soql;
            [$queried, $saved] = $this->persistQuery($soql, $queried, $saved);
        }

        return [
            'soql' => implode("\n\n-- Product2 Opportunity coverage chunk --\n\n", $soqls),
            'queried' => $queried,
            'saved' => $saved,
            'opportunity_product_queries' => max(count($soqls) - 1, 0),
        ];
    }

    public function soql(CarbonInterface $periodStart, CarbonInterface $periodEnd): string
    {
        $start = CarbonImmutable::parse($periodStart)->toDateString();
        $end = CarbonImmutable::parse($periodEnd)->toDateString();

        return $this->select()."\nWHERE Fecha_entregado__c >= {$start}"
            ."\n    AND Fecha_entregado__c < {$end}"
            ."\nORDER BY Fecha_entregado__c ASC, Id ASC";
    }

    /** @param list<string> $ids */
    private function soqlForIds(array $ids): string
    {
        $escaped = array_map(
            fn (string $id): string => "'".str_replace("'", "\\'", $id)."'",
            $ids,
        );

        return $this->select()."\nWHERE Id IN (".implode(', ', $escaped).")\nORDER BY Id ASC";
    }

    private function select(): string
    {
        return "SELECT\n    Id,\n    PRO_SEL_Estado__c,\n    Fecha_entregado__c,\n    PRO_TEX_Matricula__c,\n    LastModifiedDate\nFROM Product2";
    }

    /** @return array{0: int, 1: int} */
    private function persistQuery(string $soql, int $queried, int $saved): array
    {
        foreach ($this->client->queryPages($soql) as $records) {
            $queried += count($records);
            $rows = array_values(array_filter(array_map(
                fn (array $record): ?array => $this->mapRecord($record),
                $records,
            )));
            foreach (array_chunk($rows, self::PERSIST_CHUNK_SIZE) as $chunk) {
                DB::table('commercial_commission_product_delivery_evidences')->upsert(
                    $chunk,
                    ['salesforce_product_id'],
                    ['state', 'delivery_date', 'plate', 'salesforce_last_modified_at', 'raw_payload', 'synced_at', 'updated_at'],
                );
                $saved += count($chunk);
            }
        }

        return [$queried, $saved];
    }

    /** @return array<string, mixed>|null */
    private function mapRecord(array $record): ?array
    {
        $id = trim((string) data_get($record, 'Id'));
        if ($id === '') {
            return null;
        }
        $now = now();

        return [
            'salesforce_product_id' => $id,
            'state' => data_get($record, 'PRO_SEL_Estado__c'),
            'delivery_date' => data_get($record, 'Fecha_entregado__c'),
            'plate' => data_get($record, 'PRO_TEX_Matricula__c'),
            'salesforce_last_modified_at' => filled(data_get($record, 'LastModifiedDate'))
                ? CarbonImmutable::parse((string) data_get($record, 'LastModifiedDate'))->utc()
                : null,
            'raw_payload' => json_encode($record, JSON_THROW_ON_ERROR),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
