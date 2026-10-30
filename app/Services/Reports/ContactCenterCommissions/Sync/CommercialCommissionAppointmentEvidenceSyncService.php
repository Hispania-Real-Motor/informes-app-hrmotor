<?php

namespace App\Services\Reports\ContactCenterCommissions\Sync;

use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class CommercialCommissionAppointmentEvidenceSyncService
{
    public const DATASET = 'commercial_commission_appointment_evidence';

    private const PERSIST_CHUNK_SIZE = 200;

    public function __construct(private readonly SalesforceClient $client) {}

    /** @return array{soql: string, queried: int, saved: int} */
    public function sync(CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        $soql = $this->soql($periodStart, $periodEnd);
        $queried = 0;
        $saved = 0;

        foreach ($this->client->queryPages($soql) as $records) {
            $rows = array_values(array_filter(array_map(
                fn (array $record): ?array => $this->mapRecord($record),
                $records,
            )));
            $queried += count($records);

            foreach (array_chunk($rows, self::PERSIST_CHUNK_SIZE) as $chunk) {
                DB::table('commercial_commission_appointment_evidences')->upsert(
                    $chunk,
                    ['salesforce_interest_id'],
                    [
                        'appointment_setter_id',
                        'appointment_setter_name',
                        'appointment_capture_date',
                        'salesforce_last_modified_at',
                        'raw_payload',
                        'synced_at',
                        'updated_at',
                    ],
                );
                $saved += count($chunk);
            }
        }

        return compact('soql', 'queried', 'saved');
    }

    public function soql(CarbonInterface $periodStart, CarbonInterface $periodEnd): string
    {
        $start = CarbonImmutable::parse($periodStart)->toDateString();
        $end = CarbonImmutable::parse($periodEnd)->toDateString();

        return <<<SOQL
SELECT
    Id,
    IN_Captador_de_Cita__c,
    IN_Captador_de_Cita__r.Name,
    IN_Fecha_Captador_de_Cita__c,
    LastModifiedDate
FROM Interes__c
WHERE IN_Fecha_Captador_de_Cita__c >= {$start}
    AND IN_Fecha_Captador_de_Cita__c < {$end}
ORDER BY IN_Fecha_Captador_de_Cita__c ASC, Id ASC
SOQL;
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
            'salesforce_interest_id' => $id,
            'appointment_setter_id' => data_get($record, 'IN_Captador_de_Cita__c'),
            'appointment_setter_name' => data_get($record, 'IN_Captador_de_Cita__r.Name'),
            'appointment_capture_date' => data_get($record, 'IN_Fecha_Captador_de_Cita__c'),
            'salesforce_last_modified_at' => $this->parseDateTime(data_get($record, 'LastModifiedDate')),
            'raw_payload' => json_encode($record, JSON_THROW_ON_ERROR),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function parseDateTime(mixed $value): ?CarbonImmutable
    {
        return filled($value) ? CarbonImmutable::parse((string) $value)->utc() : null;
    }
}
