<?php

namespace App\Services\Reports\CallCenterCommissions\Sync;

use App\Models\SalesforceTasacion;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RuntimeException;

class SalesforceTasacionSyncService
{
    private const SYNC_CHUNK_DAYS = 31;
    private const HISTORY_START = '2020-01-01';

    private const QUERY_PROFILES = [
        'oportunidad_relation' => [
            'opportunity_id' => 'Oportunidad__c',
            'opportunity_name' => 'Oportunidad__r.Name',
            'opportunity_contract_signed_date' => 'Oportunidad__r.Fecha_firma_contrato__c',
            'opportunity_cv_signed' => 'Oportunidad__r.OPO_CAS_Contrato_CV_firmado__c',
        ],
        // Safe forward-compatible fallback: only fields confirmed in the
        // Tasacion__c schema are queried when the relationship is unavailable.
        'without_relation' => [
            'opportunity_id' => null,
            'opportunity_name' => null,
            'opportunity_contract_signed_date' => null,
            'opportunity_cv_signed' => null,
        ],
    ];

    private const BASE_SELECT_FIELDS = [
        'Id',
        'Name',
        'CreatedDate',
        'Seguimiento__c',
        'Negociaci_n_1__c',
        'Negociaci_n_2__c',
        'Negociaci_n_3__c',
        'Negociaci_n_4__c',
    ];

    public function __construct(
        private readonly SalesforceClient $client,
    ) {
    }

    public function sync(CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        $saved = 0;
        $seen = [];
        $soqls = [];
        $usedProfiles = [];

        $chunkStart = CarbonImmutable::parse($periodStart);
        $finalEnd = CarbonImmutable::parse($periodEnd);

        while ($chunkStart->lessThan($finalEnd)) {
            $chunkEnd = $chunkStart->addDays(self::SYNC_CHUNK_DAYS)->min($finalEnd);
            $result = $this->queryChunk($chunkStart, $chunkEnd);
            $soqls[] = $result['soql'];
            $usedProfiles[$result['profile']] = true;

            foreach ($result['records'] as $record) {
                $salesforceId = (string) data_get($record, 'Id');

                if ($salesforceId === '' || isset($seen[$salesforceId])) {
                    continue;
                }

                $seen[$salesforceId] = true;
                $saved += $this->saveRecord($record, $result['profile']);
            }

            $chunkStart = $chunkEnd;
        }

        return [
            'soql' => implode("\n\n-- chunk --\n\n", $soqls),
            'queried' => count($seen),
            'saved' => $saved,
            'profiles' => array_keys($usedProfiles),
        ];
    }

    public function syncAllHistory(?CarbonInterface $periodEnd = null): array
    {
        $start = CarbonImmutable::parse(self::HISTORY_START)->startOfDay();
        $end = $periodEnd
            ? CarbonImmutable::parse($periodEnd)
            : CarbonImmutable::now()->addDay()->startOfDay();

        return $this->sync($start, $end);
    }

    public function soql(CarbonInterface $periodStart, CarbonInterface $periodEnd): string
    {
        $preview = $this->previewQueryProfile($periodStart, $periodEnd);

        return $preview['soql'];
    }

    private function queryChunk(CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        $errors = [];

        foreach (self::QUERY_PROFILES as $profileName => $profile) {
            $fields = $this->selectFields($profile);

            while ($fields !== []) {
                $soql = $this->buildSoql($fields, $periodStart, $periodEnd);

                try {
                    return [
                        'records' => $this->client->query($soql),
                        'soql' => $soql,
                        'profile' => $profileName,
                    ];
                } catch (RuntimeException $exception) {
                    $invalidField = $this->invalidFieldFromMessage($exception->getMessage());

                    if ($invalidField === null || ! in_array($invalidField, $fields, true)) {
                        $errors[] = $exception->getMessage();
                        break;
                    }

                    $fields = array_values(array_filter(
                        $fields,
                        fn (string $field): bool => $field !== $invalidField
                    ));
                }
            }
        }

        throw new RuntimeException($errors !== [] ? $errors[0] : 'No se pudo construir una query valida para Tasacion__c.');
    }

    private function previewQueryProfile(CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        $profile = self::QUERY_PROFILES['oportunidad_relation'];

        return [
            'profile' => 'oportunidad_relation',
            'soql' => $this->buildSoql($this->selectFields($profile), $periodStart, $periodEnd),
        ];
    }

    private function selectFields(array $profile): array
    {
        $fields = self::BASE_SELECT_FIELDS;

        foreach (['opportunity_id', 'opportunity_name', 'opportunity_contract_signed_date', 'opportunity_cv_signed'] as $key) {
            if (filled($profile[$key] ?? null)) {
                $fields[] = $profile[$key];
            }
        }

        return array_values(array_unique(array_filter($fields)));
    }

    private function buildSoql(array $fields, CarbonInterface $periodStart, CarbonInterface $periodEnd): string
    {
        $startDateTime = $this->soqlDateTime($periodStart);
        $endDateTime = $this->soqlDateTime($periodEnd);
        $startDate = CarbonImmutable::parse($periodStart)->utc()->toDateString();
        $endDate = CarbonImmutable::parse($periodEnd)->utc()->toDateString();
        $whereClauses = [
            "(CreatedDate >= {$startDateTime} AND CreatedDate < {$endDateTime})",
        ];

        foreach ([
            'Oportunidad__r.Fecha_firma_contrato__c',
        ] as $field) {
            if (in_array($field, $fields, true)) {
                $whereClauses[] = "({$field} >= {$startDate} AND {$field} < {$endDate})";
            }
        }

        $select = implode(",\n    ", $fields);
        $where = implode("\n        OR ", $whereClauses);

        return <<<SOQL
SELECT
    {$select}
FROM Tasacion__c
WHERE
    IsDeleted = false
    AND (
        {$where}
    )
SOQL;
    }

    private function saveRecord(array $record, string $profileName): int
    {
        $profile = self::QUERY_PROFILES[$profileName] ?? [];

        SalesforceTasacion::updateOrCreate(
            ['salesforce_id' => (string) data_get($record, 'Id')],
            [
                'name' => data_get($record, 'Name'),
                'created_date' => data_get($record, 'CreatedDate'),
                'opportunity_salesforce_id' => $this->candidateValue($record, [
                    $profile['opportunity_id'] ?? null,
                    'Oportunidad__c',
                ]),
                'opportunity_name' => $this->candidateValue($record, [
                    $profile['opportunity_name'] ?? null,
                    'Oportunidad__r.Name',
                ]),
                'contract_signed_date' => $this->candidateValue($record, [
                    $profile['opportunity_contract_signed_date'] ?? null,
                    'Oportunidad__r.Fecha_firma_contrato__c',
                ]),
                'cv_signed' => (bool) $this->candidateValue($record, [
                    $profile['opportunity_cv_signed'] ?? null,
                    'Oportunidad__r.OPO_CAS_Contrato_CV_firmado__c',
                ]),
                'tracking_name' => $this->candidateValue($record, ['Seguimiento__c']),
                'negotiation_1' => $this->candidateValue($record, ['Negociaci_n_1__c']),
                'negotiation_2' => $this->candidateValue($record, ['Negociaci_n_2__c']),
                'negotiation_3' => $this->candidateValue($record, ['Negociaci_n_3__c']),
                'negotiation_4' => $this->candidateValue($record, ['Negociaci_n_4__c']),
                'source_query_profile' => $profileName,
                'raw_payload' => $record,
            ]
        );

        return 1;
    }

    private function candidateValue(array $record, array $paths): mixed
    {
        foreach ($paths as $path) {
            if (! filled($path)) {
                continue;
            }

            $value = data_get($record, $path);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function invalidFieldFromMessage(string $message): ?string
    {
        if (preg_match("/No such column '([^']+)' on entity/i", $message, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/\\b([A-Za-z0-9_]+__r\\.[A-Za-z0-9_]+|[A-Za-z0-9_]+__c)\\b/', $message, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function soqlDateTime(CarbonInterface $date): string
    {
        return CarbonImmutable::parse($date)->utc()->format('Y-m-d\\TH:i:s\\Z');
    }
}
