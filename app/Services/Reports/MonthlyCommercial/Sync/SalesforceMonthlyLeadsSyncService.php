<?php

namespace App\Services\Reports\MonthlyCommercial\Sync;

use App\Models\SalesforceLead;
use App\Services\Reports\Leads\LeadClassificationResolver;
use App\Services\Reports\Leads\LeadPortalResolver;
use App\Services\Reports\Leads\LeadRecordTypeNormalizer;
use App\Services\Salesforce\SalesforceClient;
use App\Services\Salesforce\SalesforceLeadFieldResolver;
use App\Services\Salesforce\SalesforcePhoneNormalizer;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RuntimeException;

class SalesforceMonthlyLeadsSyncService
{
    private readonly LeadClassificationResolver $classificationResolver;

    public function __construct(
        private readonly SalesforceClient $client,
        private readonly LeadRecordTypeNormalizer $recordTypeNormalizer,
        private readonly LeadPortalResolver $portalResolver,
        private readonly ChangedRowUpsert $changedRowUpsert = new ChangedRowUpsert,
        private readonly SalesforceLeadFieldResolver $fieldResolver = new SalesforceLeadFieldResolver,
        private readonly SalesforcePhoneNormalizer $phoneNormalizer = new SalesforcePhoneNormalizer,
        ?LeadClassificationResolver $classificationResolver = null,
    ) {
        $this->classificationResolver = $classificationResolver
            ?? new LeadClassificationResolver($this->portalResolver, $this->fieldResolver);
    }

    public function sync(CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        return $this->syncWithScope($periodStart, $periodEnd, false);
    }

    public function syncCampaignLeads(CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        return $this->syncWithScope($periodStart, $periodEnd, true);
    }

    private function syncWithScope(CarbonInterface $periodStart, CarbonInterface $periodEnd, bool $campaignOnly): array
    {
        $soql = $this->leadSoql($periodStart, $periodEnd, true, $campaignOnly);
        $warnings = [];
        $appointmentFieldsAvailable = true;

        $syncedAt = CarbonImmutable::now()->startOfSecond();

        try {
            $result = $this->persistPages($this->client->queryPages($soql), $syncedAt, true);
        } catch (RuntimeException $exception) {
            if (! $this->looksLikeMissingOptionalField($exception->getMessage())) {
                throw $exception;
            }

            $warnings[] = 'La query de Lead con campos opcionales fallo. Revisa API names de dashboard/campanas. Error: '.$exception->getMessage();
            $appointmentFieldsAvailable = false;
            $soql = $this->leadSoql($periodStart, $periodEnd, false, $campaignOnly);
            $result = $this->persistPages($this->client->queryPages($soql), $syncedAt, false);
        }

        $seenIds = $result['seen_ids'];
        unset($result['seen_ids']);

        $deleted = $campaignOnly
            ? ['queried' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0]
            : $this->syncDeleted($periodStart, $periodEnd, $syncedAt);
        $missing = $campaignOnly ? 0 : $this->reconcileMissing($periodStart, $periodEnd, $syncedAt, $seenIds);
        $deletedChanged = $deleted['inserted'] + $deleted['updated'] + $missing;
        $persistedInserted = $result['inserted'] + $deleted['inserted'];
        $persistedUpdated = $result['updated'] + $deleted['updated'] + $missing;
        $persistedUnchanged = $result['unchanged'] + $deleted['unchanged'];

        return [
            'soql' => $soql,
            'queried' => $result['queried'],
            'saved' => $result['saved'],
            'active_inserted' => $result['inserted'],
            'active_updated' => $result['updated'],
            'active_unchanged' => $result['unchanged'],
            'persisted_inserted' => $persistedInserted,
            'persisted_updated' => $persistedUpdated,
            'persisted_unchanged' => $persistedUnchanged,
            'inserted' => $persistedInserted,
            'updated' => $persistedUpdated,
            'unchanged' => $persistedUnchanged,
            'deleted' => $deleted['queried'] + $missing,
            'deleted_query_all' => $deleted['queried'],
            'deleted_missing' => $missing,
            'deleted_merged_changed' => $deletedChanged,
            'deleted_merged_unchanged' => $deleted['unchanged'],
            'synced_at' => $syncedAt->toIso8601String(),
            'appointment_fields_available' => $appointmentFieldsAvailable,
            'warnings' => $warnings,
        ];
    }

    public function soql(CarbonInterface $periodStart, CarbonInterface $periodEnd): string
    {
        return $this->leadSoql($periodStart, $periodEnd, true, false);
    }

    public function baseSoql(CarbonInterface $periodStart, CarbonInterface $periodEnd): string
    {
        return $this->leadSoql($periodStart, $periodEnd, false, false);
    }

    public function campaignLeadsSoql(CarbonInterface $periodStart, CarbonInterface $periodEnd): string
    {
        return $this->leadSoql($periodStart, $periodEnd, true, true);
    }

    /**
     * @param  iterable<array<int, array<string, mixed>>>  $pages
     * @return array{queried:int, saved:int, inserted:int, updated:int, unchanged:int, seen_ids:array<string, true>}
     */
    private function persistPages(iterable $pages, CarbonImmutable $syncedAt, bool $includeOptionalFields): array
    {
        $queried = 0;
        $saved = 0;
        $inserted = 0;
        $updated = 0;
        $unchanged = 0;
        $seenIds = [];

        foreach ($pages as $records) {
            $queried += count($records);
            $values = [];

            foreach ($records as $record) {
                if (blank(data_get($record, 'Id'))) {
                    continue;
                }

                $seenIds[(string) data_get($record, 'Id')] = true;

                $recordTypeName = data_get($record, 'RecordType.Name');
                $classification = $this->classificationResolver->resolve($record);

                $values[] = [
                    'salesforce_id' => data_get($record, 'Id'),
                    'name' => data_get($record, 'Name'),
                    'created_date' => $this->parseDateTime(data_get($record, 'CreatedDate')),
                    'last_activity_date' => data_get($record, 'LastActivityDate'),
                    'status' => data_get($record, 'Status'),
                    'record_type_name' => $recordTypeName,
                    'record_type_normalized' => $this->recordTypeNormalizer->normalize($recordTypeName),
                    'owner_id' => data_get($record, 'OwnerId'),
                    'owner_name' => data_get($record, 'Owner.Name'),
                    'appointment_setter_id' => data_get($record, 'Captador_de_cita__c'),
                    'appointment_setter_name' => data_get($record, 'Captador_de_cita__r.Name'),
                    'persona_que_trabajo_id' => data_get($record, 'Persona_que_trabaj__c'),
                    'persona_que_trabajo_name' => data_get($record, 'Persona_que_trabaj__r.Name'),
                    'propietario_descarte_id' => data_get($record, 'Propietario_cuando_se_descarto__c'),
                    'propietario_descarte_name' => data_get($record, 'Propietario_cuando_se_descarto__r.Name'),
                    'fecha_asignacion' => $this->parseDateTime(data_get($record, 'Fecha_Asignacion__c')),
                    'appointment_capture_date' => data_get($record, 'Fecha_captador__c'),
                    'appointment_call' => (bool) data_get($record, 'Cita_llamada__c', false),
                    'appointment_store' => (bool) data_get($record, 'Cita_Tienda__c', false),
                    'appointment_attended_status' => data_get($record, 'Acudi_a_la_cita__c'),
                    'store_commercial_id' => data_get($record, 'Comercial_que_atiende_en_tienda__c'),
                    'store_commercial_name' => data_get($record, 'Comercial_que_atiende_en_tienda__r.Name'),
                    'candidate_status_formula' => data_get($record, 'Estado_del_candidato_formula__c'),
                    'fuente_origen' => data_get($record, 'LEA_SEL_Fuente_Origen__c'),
                    'medio_origen' => data_get($record, 'LEA_SEL_Medio_Origen__c'),
                    'source_origin_new' => data_get($record, 'Fuente_origen__c'),
                    'medium_origin_new' => data_get($record, 'Medio_origen__c'),
                    'channel_new' => data_get($record, 'Canal__c'),
                    'delegation_origin_new' => data_get($record, 'Delegacion_procedencia__c'),
                    'campaign_acquired' => data_get($record, 'Campa_a_Adquirida__c'),
                    'acquired_id' => data_get($record, 'Id_Adquirido__c'),
                    'content_acquired' => data_get($record, 'Contenido_Adquirido__c'),
                    'acquired_source_legacy' => data_get($record, 'Fuente_Adquirida__c'),
                    'acquired_medium_legacy' => data_get($record, 'Medio_Adquirido__c'),
                    'utm_campaign_new' => data_get($record, 'utm_campaign__c'),
                    'utm_id_new' => data_get($record, 'utm_id__c'),
                    'utm_source_new' => data_get($record, 'utm_source__c'),
                    'utm_medium_new' => data_get($record, 'utm_medium__c'),
                    'utm_content_new' => data_get($record, 'utm_content__c'),
                    'field_resolution' => json_encode(
                        $classification['fields'],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                    ),
                    'vehicle_interest' => data_get($record, 'LEA_BUS_Vehiculo_de_interes__c'),
                    'phone' => data_get($record, 'Phone'),
                    'phone_normalized' => $this->phoneNormalizer->normalize(data_get($record, 'Phone')),
                    'mobile_phone' => data_get($record, 'MobilePhone'),
                    'mobile_phone_normalized' => $this->phoneNormalizer->normalize(data_get($record, 'MobilePhone')),
                    'email' => data_get($record, 'Email'),
                    'is_converted' => (bool) data_get($record, 'IsConverted', false),
                    'converted_date' => $this->parseDateTime(data_get($record, 'ConvertedDate')),
                    'converted_account_id' => data_get($record, 'ConvertedAccountId'),
                    'converted_contact_id' => data_get($record, 'ConvertedContactId'),
                    'converted_opportunity_id' => data_get($record, 'ConvertedOpportunityId'),
                    'medio_nuevo' => data_get($record, 'Medio_Nuevo__c'),
                    'fuente_nuevo' => data_get($record, 'Fuente_Nuevo__c'),
                    'remitente_lead' => data_get($record, 'Remitente_Lead__c'),
                    'portal_text' => data_get($record, 'Portal_Text__c'),
                    'resolved_channel' => $classification['channel'],
                    'resolved_portal' => $classification['portal'],
                    'portal_resolution_source' => $classification['portal_resolution_source'],
                    'salesforce_last_modified_at' => $this->parseDateTime(data_get($record, 'LastModifiedDate')),
                    'synced_at' => $syncedAt,
                    'is_deleted' => false,
                    'salesforce_deleted_at' => null,
                    'salesforce_master_record_id' => null,
                    'deletion_detection_source' => null,
                    'sync_metadata_source' => 'salesforce_sync',
                    'delegacion_encargada_text' => data_get($record, 'Delegacion_Encargada_Text__c'),
                    'delegacion_encargada_bueno' => data_get($record, 'Delegacion_Encargada_Bueno__c'),
                    'delegacion_encargada' => data_get($record, 'Delegacion_Encargada__c'),
                    'delegacion_original' => null,
                    'raw_payload' => json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];

                if (! $includeOptionalFields) {
                    foreach ($this->optionalPersistedFields() as $optionalField) {
                        unset($values[array_key_last($values)][$optionalField]);
                    }
                }

                $saved++;
            }

            foreach (array_chunk($values, 200) as $chunk) {
                $stats = $this->changedRowUpsert->persist(
                    SalesforceLead::class,
                    $chunk,
                    'salesforce_id',
                    ['synced_at'],
                );
                $inserted += $stats['inserted'];
                $updated += $stats['updated'];
                $unchanged += $stats['unchanged'];
            }

            unset($records);
        }

        return [
            'queried' => $queried,
            'saved' => $saved,
            'inserted' => $inserted,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'seen_ids' => $seenIds,
        ];
    }

    private function leadSoql(CarbonInterface $periodStart, CarbonInterface $periodEnd, bool $includeOptionalDashboardFields, bool $campaignOnly): string
    {
        $start = $this->soqlDateTime($periodStart);
        $end = $this->soqlDateTime($periodEnd);
        $startDate = CarbonImmutable::parse($periodStart)->utc()->toDateString();
        $endDate = CarbonImmutable::parse($periodEnd)->utc()->toDateString();
        $optionalFields = $includeOptionalDashboardFields
            ? <<<'SOQL'
    Medio_Nuevo__c,
    Fuente_Nuevo__c,
    Fuente_origen__c,
    Medio_origen__c,
    Canal__c,
    Delegacion_procedencia__c,
    Remitente_Lead__c,
    Delegacion_Encargada_Bueno__c,
    Delegacion_Encargada__c,
    Campa_a_Adquirida__c,
    Id_Adquirido__c,
    Contenido_Adquirido__c,
    Fuente_Adquirida__c,
    Medio_Adquirido__c,
    utm_campaign__c,
    utm_id__c,
    utm_source__c,
    utm_medium__c,
    utm_content__c,
    LEA_BUS_Vehiculo_de_interes__c,
    Phone,
    MobilePhone,
    Email,
    IsConverted,
    ConvertedDate,
    ConvertedAccountId,
    ConvertedContactId,
    ConvertedOpportunityId,
    Captador_de_cita__c,
    Captador_de_cita__r.Name,
    Fecha_captador__c,
    Cita_llamada__c,
    Cita_Tienda__c,
    Acudi_a_la_cita__c,
    Comercial_que_atiende_en_tienda__c,
    Comercial_que_atiende_en_tienda__r.Name,
    Estado_del_candidato_formula__c,
SOQL
            : '';
        $dateWhere = $includeOptionalDashboardFields
            ? <<<SOQL
    AND (
        (CreatedDate >= {$start} AND CreatedDate < {$end})
        OR (Fecha_captador__c >= {$startDate} AND Fecha_captador__c < {$endDate})
        OR (LastModifiedDate >= {$start} AND LastModifiedDate < {$end})
    )
SOQL
            : <<<SOQL
    AND (
        (CreatedDate >= {$start} AND CreatedDate < {$end})
        OR (LastModifiedDate >= {$start} AND LastModifiedDate < {$end})
    )
SOQL;

        $campaignWhere = $campaignOnly
            ? <<<'SOQL'
    AND (
        Campa_a_Adquirida__c != null
        OR Id_Adquirido__c != null
        OR Contenido_Adquirido__c != null
        OR LEA_SEL_Fuente_Origen__c != null
        OR LEA_SEL_Medio_Origen__c != null
    )
SOQL
            : '';

        return <<<SOQL
SELECT
    Id,
    Name,
    CreatedDate,
    LastModifiedDate,
    IsDeleted,
    MasterRecordId,
    LastActivityDate,
    Status,
    RecordType.Name,
    OwnerId,
    Owner.Name,
    Persona_que_trabaj__c,
    Persona_que_trabaj__r.Name,
    Propietario_cuando_se_descarto__c,
    Propietario_cuando_se_descarto__r.Name,
    Fecha_Asignacion__c,
    LEA_SEL_Fuente_Origen__c,
    LEA_SEL_Medio_Origen__c,
{$optionalFields}
    Portal_Text__c,
    Delegacion_Encargada_Text__c
FROM Lead
WHERE
    IsDeleted = false
{$dateWhere}
{$campaignWhere}
SOQL;
    }

    /** @return array{queried:int, inserted:int, updated:int, unchanged:int} */
    private function syncDeleted(CarbonInterface $periodStart, CarbonInterface $periodEnd, CarbonImmutable $syncedAt): array
    {
        $start = $this->soqlDateTime($periodStart);
        $end = $this->soqlDateTime($periodEnd);
        $soql = <<<SOQL
SELECT
    Id,
    Name,
    CreatedDate,
    LastModifiedDate,
    IsDeleted,
    Status,
    RecordType.Name,
    OwnerId,
    Medio_Nuevo__c,
    Fuente_Nuevo__c,
    Portal_Text__c,
    LEA_SEL_Fuente_Origen__c,
    LEA_SEL_Medio_Origen__c,
    Remitente_Lead__c,
    IsConverted,
    ConvertedDate,
    ConvertedOpportunityId,
    MasterRecordId
FROM Lead
WHERE IsDeleted = true
    AND LastModifiedDate >= {$start}
    AND LastModifiedDate < {$end}
SOQL;

        $deletedRecords = $this->client->queryAll($soql);
        $values = [];

        foreach ($deletedRecords as $record) {
            $id = data_get($record, 'Id');

            if (blank($id)) {
                continue;
            }

            $recordTypeName = data_get($record, 'RecordType.Name');
            $portalResolution = $this->portalResolver->resolve([
                'medio_nuevo' => data_get($record, 'Medio_Nuevo__c'),
                'fuente_nuevo' => data_get($record, 'Fuente_Nuevo__c'),
                'portal_text' => data_get($record, 'Portal_Text__c'),
                'fuente_origen' => data_get($record, 'LEA_SEL_Fuente_Origen__c'),
            ]);
            $values[] = [
                'salesforce_id' => $id,
                'name' => data_get($record, 'Name'),
                'created_date' => $this->parseDateTime(data_get($record, 'CreatedDate')),
                'status' => data_get($record, 'Status'),
                'record_type_name' => $recordTypeName,
                'record_type_normalized' => $this->recordTypeNormalizer->normalize($recordTypeName),
                'owner_id' => data_get($record, 'OwnerId'),
                'medio_nuevo' => data_get($record, 'Medio_Nuevo__c'),
                'fuente_nuevo' => data_get($record, 'Fuente_Nuevo__c'),
                'portal_text' => data_get($record, 'Portal_Text__c'),
                'fuente_origen' => data_get($record, 'LEA_SEL_Fuente_Origen__c'),
                'medio_origen' => data_get($record, 'LEA_SEL_Medio_Origen__c'),
                'remitente_lead' => data_get($record, 'Remitente_Lead__c'),
                'is_converted' => (bool) data_get($record, 'IsConverted', false),
                'converted_date' => $this->parseDateTime(data_get($record, 'ConvertedDate')),
                'converted_opportunity_id' => data_get($record, 'ConvertedOpportunityId'),
                'resolved_channel' => $portalResolution['channel'],
                'resolved_portal' => $portalResolution['portal'],
                'portal_resolution_source' => $portalResolution['source'],
                'is_deleted' => true,
                'salesforce_deleted_at' => $this->parseDateTime(data_get($record, 'LastModifiedDate')),
                'salesforce_master_record_id' => data_get($record, 'MasterRecordId'),
                'deletion_detection_source' => 'query_all',
                'sync_metadata_source' => 'salesforce_sync',
                'salesforce_last_modified_at' => $this->parseDateTime(data_get($record, 'LastModifiedDate')),
                'synced_at' => $syncedAt,
                'raw_payload' => json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }

        $stats = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];
        foreach (array_chunk($values, 200) as $chunk) {
            $chunkStats = $this->changedRowUpsert->persist(
                SalesforceLead::class,
                $chunk,
                'salesforce_id',
                ['synced_at', 'created_date'],
                ['created_date'],
            );
            foreach ($stats as $key => $value) {
                $stats[$key] += $chunkStats[$key];
            }
        }

        return ['queried' => count($values), ...$stats];
    }

    /** @param array<string, true> $seenIds */
    private function reconcileMissing(
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
        CarbonImmutable $syncedAt,
        array $seenIds,
    ): int {
        $changed = 0;

        SalesforceLead::query()
            ->select(['id', 'salesforce_id'])
            ->where('is_deleted', false)
            ->where('created_date', '>=', $periodStart)
            ->where('created_date', '<', $periodEnd)
            ->chunkById(500, function ($leads) use (&$changed, $seenIds, $syncedAt): void {
                $missingIds = $leads
                    ->reject(fn (SalesforceLead $lead): bool => isset($seenIds[(string) $lead->salesforce_id]))
                    ->pluck('id');

                if ($missingIds->isEmpty()) {
                    return;
                }

                $changed += SalesforceLead::query()
                    ->where('is_deleted', false)
                    ->whereIn('id', $missingIds)
                    ->update([
                        'is_deleted' => true,
                        'salesforce_deleted_at' => $syncedAt,
                        'deletion_detection_source' => 'missing_from_salesforce',
                        'sync_metadata_source' => 'missing_reconciliation',
                        'synced_at' => $syncedAt,
                        'updated_at' => $syncedAt,
                    ]);
            });

        return $changed;
    }

    private function looksLikeMissingOptionalField(string $message): bool
    {
        return str_contains($message, 'INVALID_FIELD')
            || str_contains($message, 'No such column')
            || str_contains($message, 'field');
    }

    /** @return list<string> */
    private function optionalPersistedFields(): array
    {
        return [
            'appointment_setter_id',
            'appointment_setter_name',
            'appointment_capture_date',
            'appointment_call',
            'appointment_store',
            'appointment_attended_status',
            'store_commercial_id',
            'store_commercial_name',
            'candidate_status_formula',
            'campaign_acquired',
            'acquired_id',
            'content_acquired',
            'acquired_source_legacy',
            'acquired_medium_legacy',
            'source_origin_new',
            'medium_origin_new',
            'channel_new',
            'delegation_origin_new',
            'utm_campaign_new',
            'utm_id_new',
            'utm_source_new',
            'utm_medium_new',
            'utm_content_new',
            'field_resolution',
            'vehicle_interest',
            'phone',
            'phone_normalized',
            'mobile_phone',
            'mobile_phone_normalized',
            'email',
            'is_converted',
            'converted_date',
            'converted_account_id',
            'converted_contact_id',
            'converted_opportunity_id',
            'medio_nuevo',
            'fuente_nuevo',
            'remitente_lead',
            'resolved_channel',
            'resolved_portal',
            'portal_resolution_source',
        ];
    }

    private function soqlDateTime(CarbonInterface $date): string
    {
        return CarbonImmutable::parse($date)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private function parseDateTime(mixed $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        return CarbonImmutable::parse($value);
    }
}
