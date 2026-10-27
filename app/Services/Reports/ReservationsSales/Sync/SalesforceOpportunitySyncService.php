<?php

namespace App\Services\Reports\ReservationsSales\Sync;

use App\Models\LeadRaw;
use App\Models\SalesforceLead;
use App\Models\SalesforceOpportunity;
use App\Services\Reports\ReservasVentas\OpportunityPortalNormalizer;
use App\Services\Salesforce\SalesforceClient;
use App\Services\Salesforce\SalesforceLeadFieldResolver;
use App\Services\Salesforce\SalesforcePhoneNormalizer;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class SalesforceOpportunitySyncService
{
    private const SYNC_CHUNK_DAYS = 7;

    private const IDS_CHUNK_SIZE = 100;

    private const LEAD_EMAIL_CHUNK_SIZE = 80;

    private const LEAD_PHONE_CHUNK_SIZE = 20;

    private const LEAD_ID_CHUNK_SIZE = 100;

    private const LOCAL_PHONE_CHUNK_SIZE = 100;

    public function __construct(
        private readonly SalesforceClient $client,
        private readonly OpportunityPortalNormalizer $portalNormalizer,
        private readonly SalesforceLeadFieldResolver $leadFieldResolver,
        private readonly SalesforcePhoneNormalizer $phoneNormalizer = new SalesforcePhoneNormalizer,
    ) {}

    public function sync(CarbonInterface $periodStart, CarbonInterface $periodEnd, bool $modifiedOnly = false): array
    {
        $saved = 0;
        $soqls = [];
        $seen = [];
        $includeCompanyEmail = true;
        $stats = $this->emptyStats();
        $deletionStats = $this->syncDeleted($periodStart, $periodEnd);

        $chunkStart = CarbonImmutable::parse($periodStart);
        $finalEnd = CarbonImmutable::parse($periodEnd);

        while ($chunkStart->lessThan($finalEnd)) {
            $chunkEnd = $chunkStart->addDays(self::SYNC_CHUNK_DAYS)->min($finalEnd);
            $soql = $this->buildSoql($chunkStart, $chunkEnd, $includeCompanyEmail, $modifiedOnly);
            $records = $this->queryOpportunities($soql, $chunkStart, $chunkEnd, $includeCompanyEmail, $modifiedOnly);
            $soqls[] = $soql;

            $records = collect($records)
                ->filter(fn (array $record) => filled(data_get($record, 'Id')))
                ->reject(function (array $record) use (&$seen): bool {
                    $id = (string) data_get($record, 'Id');

                    if (isset($seen[$id])) {
                        return true;
                    }

                    $seen[$id] = true;

                    return false;
                })
                ->values()
                ->all();

            $leadMatches = $this->relatedLeadMatches($records);

            foreach ($records as $record) {
                $this->saveRecord($record, $leadMatches, $stats);
                $saved++;
            }

            $chunkStart = $chunkEnd;
        }

        return [
            'soql' => implode("\n\n-- chunk --\n\n", $soqls),
            'queried' => count($seen),
            'saved' => $saved,
            'stats' => $stats,
            'deletions' => $deletionStats,
        ];
    }

    public function syncBySalesforceIds(Collection|array $salesforceIds): array
    {
        $ids = collect($salesforceIds)
            ->map(fn (mixed $id): string => trim((string) $id))
            ->filter()
            ->unique()
            ->values();
        $stats = $this->emptyStats();
        $saved = 0;
        $seen = [];
        $soqls = [];
        $includeCompanyEmail = true;

        foreach ($ids->chunk(self::IDS_CHUNK_SIZE) as $chunk) {
            $where = 'Id IN ('.$chunk
                ->map(fn (string $id): string => "'".$this->escape($id)."'")
                ->implode(', ').')';
            $soql = $this->buildOpportunitySoql($where, $includeCompanyEmail);

            try {
                $records = $this->client->query($soql);
            } catch (RuntimeException $exception) {
                if (! $includeCompanyEmail || ! str_contains($exception->getMessage(), 'HTTP 400')) {
                    throw $exception;
                }

                $includeCompanyEmail = false;
                $soql = $this->buildOpportunitySoql($where, false);
                $records = $this->client->query($soql);
            }
            $soqls[] = $soql;
            $records = collect($records)
                ->filter(fn (array $record): bool => filled(data_get($record, 'Id')))
                ->reject(function (array $record) use (&$seen): bool {
                    $id = (string) data_get($record, 'Id');
                    if (isset($seen[$id])) {
                        return true;
                    }

                    $seen[$id] = true;

                    return false;
                })
                ->values()
                ->all();
            $leadMatches = $this->relatedLeadMatches($records);

            foreach ($records as $record) {
                $this->saveRecord($record, $leadMatches, $stats);
                $saved++;
            }
        }

        return [
            'requested' => $ids->count(),
            'queried' => count($seen),
            'saved' => $saved,
            'missing' => $ids->diff(array_keys($seen))->values()->all(),
            'soql' => implode("\n\n-- chunk --\n\n", $soqls),
            'stats' => $stats,
        ];
    }

    public function soql(CarbonInterface $periodStart, CarbonInterface $periodEnd): string
    {
        return $this->buildSoql($periodStart, $periodEnd, true);
    }

    public function modifiedSoql(CarbonInterface $periodStart, CarbonInterface $periodEnd): string
    {
        return $this->buildSoql($periodStart, $periodEnd, true, true);
    }

    public function testSoql(): string
    {
        return <<<'SOQL'
SELECT Id, Name, CreatedDate, StageName FROM Opportunity WHERE IsDeleted = false ORDER BY CreatedDate DESC LIMIT 5
SOQL;
    }

    public function resolvePortalForRecord(array $opportunity, Collection $leads): array
    {
        return $this->resolvePortal($opportunity, $leads);
    }

    public function relatedLeadMatchesForOpportunities(array $opportunities): Collection
    {
        return $this->relatedLeadMatches($opportunities);
    }

    private function buildSoql(
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
        bool $includeCompanyEmail,
        bool $modifiedOnly = false,
    ): string {
        $startDateTime = $this->soqlDateTime($periodStart);
        $endDateTime = $this->soqlDateTime($periodEnd);
        $startDate = CarbonImmutable::parse($periodStart)->utc()->toDateString();
        $endDate = CarbonImmutable::parse($periodEnd)->utc()->toDateString();
        $where = $modifiedOnly
            ? "LastModifiedDate >= {$startDateTime} AND LastModifiedDate < {$endDateTime}"
            : <<<SOQL
(
        (CreatedDate >= {$startDateTime} AND CreatedDate < {$endDateTime})
        OR (OPO_FEC_Fecha_de_reserva__c >= {$startDate} AND OPO_FEC_Fecha_de_reserva__c < {$endDate})
        OR (Fecha_firma_contrato__c >= {$startDate} AND Fecha_firma_contrato__c < {$endDate})
    )
SOQL;

        return $this->buildOpportunitySoql($where, $includeCompanyEmail);
    }

    private function buildOpportunitySoql(string $where, bool $includeCompanyEmail): string
    {
        $companyEmailSelect = $includeCompanyEmail ? "    Account.AC_C_EMA_email__c,\n" : '';

        return <<<SOQL
SELECT
    Id,
    Name,
    CreatedDate,
    LastModifiedDate,
    CloseDate,
    Amount,
    OPO_FOR_Importe_total__c,
    OPO_FOR_Importe_vehiculo_venta__c,
    OPO_FOR_Importe_vehiculo_a_cambio__c,
    StageName,
    RecordType.Name,
    OwnerId,
    Owner.Name,
    Owner.IsActive,
    Owner.USR_SEL_Delegacion__c,
    AccountId,
    Account.Name,
    Account.Phone,
    Account.PersonEmail,
{$companyEmailSelect}    Portal__c,
    Fuente_de_Origen__c,
    OPO_CAS_Reserva__c,
    OPO_FEC_Fecha_de_reserva__c,
    OPO_CAS_Contrato_CV_firmado__c,
    Fecha_firma_contrato__c,
    Entrega_Compartida__c,
    Entrega_Compartida__r.Name,
    Tienda_de_entrega__c,
    Garant_a_Total__c,
    Beneficio_financiacion_comercial__c,
    Importe_financiado__c,
    Gestion_de_venta__c,
    Costes_de_gestion__c,
    Costes_de_Logistica_Incluido__c,
    OPO_DIV_Coste_Traslado__c,
    Descuento_Logistica__c,
    OPO_DIV_Descuento__c,
    Comisi_n_Financiera__c,
    OPO_DIV_Descuento_financiera__c,
    Inter_s_elegido__c,
    zona_financiera__c,
    Tipo_de_registro_oportunidad__c,
    Financiaci_n_pagada__c,
    Fecha_pagada_financiacion__c,
    Porcentaje_del_importe_financiado__c,
    Captador_de_cita__c,
    Captador_de_cita__r.Name,
    Captador__c,
    Comisi_n_Captador__c,
    Fecha_captador__c,
    Captador_2__c,
    Captador_3__c,
    Captador_4__c,
    Fecha_captado_2__c,
    Fecha_captador_3__c,
    Fecha_captador_4__c,
    OPO_FEC_Fecha_entrega__c,
    Delegacion_del_propietario__c,
    Facturado_Facilitea__c,
    Numero_Factura_Facilitea__c,
    informe_rentabilidad__c,
    Rentabilidad_financiera__c,
    OPO_BUS_Vehiculo_a_tasar__c,
    OPO_BUS_Vehiculo_a_tasar__r.Name,
    OPO_BUS_Vehiculo_a_tasar__r.PRO_TEX_Matricula__c,
    OPP_BUS_Vehiculo_de_interes__c,
    OPP_BUS_Vehiculo_de_interes__r.Name,
    OPP_BUS_Vehiculo_de_interes__r.PRO_DIV_Precio_de_venta__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_DIV_Precio_de_compra__c,
    OPP_BUS_Vehiculo_de_interes__r.Plan_Auto_Plus__c,
    OPP_BUS_Vehiculo_de_interes__r.CAE__c,
    OPP_BUS_Vehiculo_de_interes__r.Procedencia_de_compra__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_FEC_Fecha_compra__c,
    OPP_BUS_Vehiculo_de_interes__r.Comprador_oportunidad__c,
    OPP_BUS_Vehiculo_de_interes__r.Comprador_oportunidad__r.Name,
    OPP_BUS_Vehiculo_de_interes__r.PRO_TEX_Matricula__c,
    OPP_BUS_Vehiculo_de_interes__r.PRO_FEC_Fecha_entrada__c,
    OPP_BUS_Vehiculo_de_interes__r.Dias_en_stock__c
FROM Opportunity
WHERE
    IsDeleted = false
    AND {$where}
SOQL;
    }

    private function emptyStats(): array
    {
        return [
            'material_changed' => 0,
            'opportunity' => 0,
            'lead' => 0,
            'opportunity_source' => 0,
            'fallback_exposicion' => 0,
            'fallback_web' => 0,
            'unclassified' => 0,
            'reservas_vivas' => 0,
            'caidas' => 0,
            'cv_firmados' => 0,
        ];
    }

    private function queryOpportunities(
        string &$soql,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
        bool &$includeCompanyEmail,
        bool $modifiedOnly,
    ): array {
        try {
            return $this->client->query($soql);
        } catch (RuntimeException $exception) {
            if (! $includeCompanyEmail || ! str_contains($exception->getMessage(), 'HTTP 400')) {
                throw $exception;
            }

            // Salesforce may hide inaccessible field names from sanitized errors.
            // Retry a rejected query once without the optional company email.
            $includeCompanyEmail = false;
            $soql = $this->buildSoql($periodStart, $periodEnd, false, $modifiedOnly);

            return $this->client->query($soql);
        }
    }

    private function saveRecord(array $record, Collection $leadMatches, array &$stats): void
    {
        $portal = $this->resolvePortal($record, $leadMatches);
        $source = $this->portalNormalizer->normalize(data_get($record, 'Fuente_de_Origen__c'));
        $stage = (string) data_get($record, 'StageName', '');
        $isClosedLost = strcasecmp($stage, 'Cerrada Perdida') === 0;
        $reservation = (bool) data_get($record, 'OPO_CAS_Reserva__c', false);
        $cvSigned = (bool) data_get($record, 'OPO_CAS_Contrato_CV_firmado__c', false);

        $opportunity = SalesforceOpportunity::withoutGlobalScope(SalesforceOpportunity::ACTIVE_SCOPE)->updateOrCreate(
            ['salesforce_id' => data_get($record, 'Id')],
            [
                'name' => data_get($record, 'Name'),
                'created_date' => $this->parseDateTime(data_get($record, 'CreatedDate')),
                'salesforce_last_modified_at' => $this->parseDateTime(data_get($record, 'LastModifiedDate')),
                'close_date' => data_get($record, 'CloseDate'),
                'amount' => $this->salesforceValue($record, 'Amount'),
                'opo_for_importe_total' => $this->salesforceValue($record, 'OPO_FOR_Importe_total__c'),
                'contract_vehicle_sale_amount' => $this->salesforceValue($record, 'OPO_FOR_Importe_vehiculo_venta__c'),
                'plan_auto_plus_amount' => $this->salesforceValue($record, 'OPP_BUS_Vehiculo_de_interes__r.Plan_Auto_Plus__c'),
                'cae_amount' => $this->salesforceValue($record, 'OPP_BUS_Vehiculo_de_interes__r.CAE__c'),
                'trade_in_amount' => $this->salesforceValue($record, 'OPO_FOR_Importe_vehiculo_a_cambio__c'),
                'stage_name' => $stage,
                'record_type_name' => data_get($record, 'RecordType.Name'),
                'owner_id' => data_get($record, 'OwnerId'),
                'owner_name' => data_get($record, 'Owner.Name'),
                'owner_is_active' => (bool) data_get($record, 'Owner.IsActive', true),
                'owner_delegation' => data_get($record, 'Owner.USR_SEL_Delegacion__c'),
                'report_owner_delegation' => data_get($record, 'Delegacion_del_propietario__c'),
                'delivery_store' => data_get($record, 'Tienda_de_entrega__c'),
                'account_id' => data_get($record, 'AccountId'),
                'account_name' => data_get($record, 'Account.Name'),
                'account_phone' => data_get($record, 'Account.Phone'),
                'account_person_email' => data_get($record, 'Account.PersonEmail'),
                'account_company_email' => data_get($record, 'Account.AC_C_EMA_email__c'),
                'shared_delivery_id' => data_get($record, 'Entrega_Compartida__c'),
                'shared_delivery_name' => data_get($record, 'Entrega_Compartida__r.Name'),
                'garantia_total' => $this->salesforceValue($record, 'Garant_a_Total__c'),
                'beneficio_financiacion_comercial' => $this->salesforceValue($record, 'Beneficio_financiacion_comercial__c'),
                'importe_financiado' => $this->salesforceValue($record, 'Importe_financiado__c'),
                'financial_commission' => $this->salesforceValue($record, 'Comisi_n_Financiera__c'),
                'financial_discount' => $this->salesforceValue($record, 'OPO_DIV_Descuento_financiera__c'),
                'interest_rate' => data_get($record, 'Inter_s_elegido__c'),
                'financial_zone' => data_get($record, 'zona_financiera__c'),
                'opportunity_record_type_formula' => data_get($record, 'Tipo_de_registro_oportunidad__c'),
                'financing_paid' => $this->salesforceValue($record, 'Financiaci_n_pagada__c'),
                'financing_paid_date' => data_get($record, 'Fecha_pagada_financiacion__c'),
                'financed_amount_ratio' => $this->salesforceValue($record, 'Porcentaje_del_importe_financiado__c'),
                'gestion_de_venta' => (bool) $this->salesforceValue($record, 'Gestion_de_venta__c'),
                'management_cost' => $this->salesforceValue($record, 'Costes_de_gestion__c'),
                'logistics_cost' => $this->salesforceValue($record, 'Costes_de_Logistica_Incluido__c'),
                'transfer_cost' => $this->salesforceValue($record, 'OPO_DIV_Coste_Traslado__c'),
                'logistics_discount' => $this->salesforceValue($record, 'Descuento_Logistica__c'),
                'opo_div_descuento' => $this->salesforceValue($record, 'OPO_DIV_Descuento__c'),
                'informe_rentabilidad' => $this->salesforceValue($record, 'informe_rentabilidad__c'),
                'rentabilidad_financiera' => $this->salesforceValue($record, 'Rentabilidad_financiera__c'),
                'vehicle_interest_id' => data_get($record, 'OPP_BUS_Vehiculo_de_interes__c'),
                'appraised_vehicle_id' => data_get($record, 'OPO_BUS_Vehiculo_a_tasar__c'),
                'appraised_vehicle_plate' => data_get($record, 'OPO_BUS_Vehiculo_a_tasar__r.PRO_TEX_Matricula__c'),
                'vehicle_sale_price' => $this->salesforceValue($record, 'OPP_BUS_Vehiculo_de_interes__r.PRO_DIV_Precio_de_venta__c'),
                'vehicle_purchase_price' => $this->salesforceValue($record, 'OPP_BUS_Vehiculo_de_interes__r.PRO_DIV_Precio_de_compra__c'),
                'vehicle_purchase_source' => data_get($record, 'OPP_BUS_Vehiculo_de_interes__r.Procedencia_de_compra__c'),
                'vehicle_purchase_date' => data_get($record, 'OPP_BUS_Vehiculo_de_interes__r.PRO_FEC_Fecha_compra__c'),
                'vehicle_buyer_id' => data_get($record, 'OPP_BUS_Vehiculo_de_interes__r.Comprador_oportunidad__c'),
                'vehicle_buyer_name' => data_get($record, 'OPP_BUS_Vehiculo_de_interes__r.Comprador_oportunidad__r.Name'),
                'vehicle_plate' => data_get($record, 'OPP_BUS_Vehiculo_de_interes__r.PRO_TEX_Matricula__c'),
                'vehicle_entry_date' => data_get($record, 'OPP_BUS_Vehiculo_de_interes__r.PRO_FEC_Fecha_entrada__c'),
                'vehicle_days_in_stock' => data_get($record, 'OPP_BUS_Vehiculo_de_interes__r.Dias_en_stock__c'),
                'portal_original' => data_get($record, 'Portal__c'),
                'opportunity_source_raw' => data_get($record, 'Fuente_de_Origen__c'),
                'opportunity_source_normalized' => $source['portal'],
                'portal_resolved' => $portal['portal'],
                'portal_resolution_source' => $portal['source'],
                'portal_resolution_lead_id' => $portal['lead_id'],
                'portal_resolution_debug' => $portal['debug'],
                'reservation' => $reservation,
                'reservation_date' => data_get($record, 'OPO_FEC_Fecha_de_reserva__c'),
                'cv_signed' => $cvSigned,
                'cv_signed_date' => data_get($record, 'Fecha_firma_contrato__c'),
                'raw_payload' => $record,
                'is_deleted' => false,
                'salesforce_deleted_at' => null,
                'deletion_detection_source' => null,
            ]
        );

        if ($opportunity->wasRecentlyCreated || $opportunity->wasChanged()) {
            $stats['material_changed']++;
        }
        $stats[$portal['source']]++;
        $stats['reservas_vivas'] += $reservation && ! $cvSigned && ! $isClosedLost ? 1 : 0;
        $stats['caidas'] += $isClosedLost ? 1 : 0;
        $stats['cv_firmados'] += $cvSigned && ! $isClosedLost ? 1 : 0;
    }

    private function relatedLeadMatches(array $opportunities): Collection
    {
        $emails = collect();
        $phones = collect();

        foreach ($opportunities as $record) {
            foreach ([data_get($record, 'Account.PersonEmail'), data_get($record, 'Account.AC_C_EMA_email__c')] as $email) {
                if (filled($email)) {
                    $emails->push(Str::lower(trim((string) $email)));
                }
            }

            if (filled(data_get($record, 'Account.Phone'))) {
                $phones->push($this->phoneNormalizer->normalize(data_get($record, 'Account.Phone')));
            }
        }

        $matches = collect();
        $emailValues = $emails->unique()->values();
        $phoneValues = $phones->filter()->unique()->values();

        foreach ($emailValues->chunk(self::LEAD_EMAIL_CHUNK_SIZE) as $chunk) {
            $matches = $matches->merge($this->queryLeads($chunk->all(), []));
        }

        [$localLeadIds, $locallyMatchedPhones] = $this->localLeadIdsForPhones($phoneValues->all());

        foreach (collect($localLeadIds)->chunk(self::LEAD_ID_CHUNK_SIZE) as $chunk) {
            $matches = $matches->merge($this->queryLeadsByIds($chunk->all()));
        }

        $remoteFallbackPhones = $phoneValues->diff($locallyMatchedPhones)->values();
        foreach ($remoteFallbackPhones->chunk(self::LEAD_PHONE_CHUNK_SIZE) as $chunk) {
            $phoneVariants = $chunk
                ->flatMap(fn (string $phone): array => $this->phoneQueryVariants($phone))
                ->unique()
                ->values()
                ->all();
            $matches = $matches->merge($this->queryLeads([], $phoneVariants));
        }

        return $matches
            ->unique(fn (array $lead): string => (string) data_get($lead, 'Id'))
            ->sort(function (array $left, array $right): int {
                $createdDateComparison = strcmp(
                    (string) data_get($right, 'CreatedDate'),
                    (string) data_get($left, 'CreatedDate'),
                );

                return $createdDateComparison !== 0
                    ? $createdDateComparison
                    : strcmp((string) data_get($left, 'Id'), (string) data_get($right, 'Id'));
            })
            ->values();
    }

    /**
     * @param  list<string>  $phones
     * @return array{0:list<string>,1:list<string>}
     */
    private function localLeadIdsForPhones(array $phones): array
    {
        if ($phones === [] || ! Schema::hasColumns('salesforce_leads', ['phone_normalized', 'mobile_phone_normalized'])) {
            return [[], []];
        }

        $ids = [];
        $matchedPhones = [];
        $phoneMap = array_fill_keys($phones, true);

        foreach (collect($phones)->chunk(self::LOCAL_PHONE_CHUNK_SIZE) as $chunk) {
            $values = $chunk->all();
            $rows = SalesforceLead::query()
                ->select(['salesforce_id', 'phone_normalized', 'mobile_phone_normalized'])
                ->where('is_deleted', false)
                ->where(function ($query) use ($values): void {
                    $query->whereIn('phone_normalized', $values)
                        ->orWhereIn('mobile_phone_normalized', $values);
                })
                ->orderBy('salesforce_id')
                ->get();

            foreach ($rows as $row) {
                if (filled($row->salesforce_id)) {
                    $ids[(string) $row->salesforce_id] = true;
                }

                foreach ([$row->phone_normalized, $row->mobile_phone_normalized] as $phone) {
                    if ($phone !== null && isset($phoneMap[$phone])) {
                        $matchedPhones[$phone] = true;
                    }
                }
            }
        }

        return [array_keys($ids), array_keys($matchedPhones)];
    }

    private function salesforceValue(array $record, string $key): mixed
    {
        if (array_key_exists($key, $record)) {
            return $record[$key];
        }

        $lowerKey = mb_strtolower($key);

        foreach ($record as $recordKey => $value) {
            if (mb_strtolower((string) $recordKey) === $lowerKey) {
                return $value;
            }
        }

        return data_get($record, $key);
    }

    private function queryLeads(array $emails, array $phones): array
    {
        $clauses = [];

        if ($emails !== []) {
            $in = implode(', ', array_map(fn (string $value) => "'".$this->escape($value)."'", $emails));
            $clauses[] = "Email IN ({$in})";
        }

        if ($phones !== []) {
            $in = implode(', ', array_map(fn (string $value) => "'".$this->escape($value)."'", $phones));
            $clauses[] = "Phone IN ({$in})";
            $clauses[] = "MobilePhone IN ({$in})";
        }

        if ($clauses === []) {
            return [];
        }

        $where = implode(' OR ', $clauses);

        $records = $this->client->query(<<<SOQL
SELECT
    Id,
    CreatedDate,
    Phone,
    MobilePhone,
    Email,
    Portal_Text__c,
    LEA_SEL_Fuente_Origen__c,
    Fuente_Nuevo__c,
    Fuente_origen__c
FROM Lead
WHERE
    IsDeleted = false
    AND ({$where})
ORDER BY CreatedDate DESC
SOQL);

        $matchedEmails = collect($records)
            ->map(fn (array $lead): string => Str::lower(trim((string) data_get($lead, 'Email'))))
            ->filter()
            ->unique()
            ->all();
        $fallbackEmails = array_values(array_diff($emails, $matchedEmails));

        return array_merge(
            $records,
            $this->queryLeadRawFallback($fallbackEmails),
        );
    }

    /** @param list<string> $ids */
    private function queryLeadsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $in = implode(', ', array_map(fn (string $value) => "'".$this->escape($value)."'", $ids));

        return $this->client->query(<<<SOQL
SELECT
    Id,
    CreatedDate,
    Phone,
    MobilePhone,
    Email,
    Portal_Text__c,
    LEA_SEL_Fuente_Origen__c,
    Fuente_Nuevo__c,
    Fuente_origen__c
FROM Lead
WHERE
    IsDeleted = false
    AND Id IN ({$in})
ORDER BY CreatedDate DESC
SOQL);
    }

    private function queryLeadRawFallback(array $emails): array
    {
        if ($emails === [] || ! Schema::hasTable('leads_raw')) {
            return [];
        }

        $emailMap = collect($emails)
            ->filter()
            ->mapWithKeys(fn (string $value) => [Str::lower(trim($value)) => true])
            ->all();

        $query = LeadRaw::query()
            ->select(['salesforce_id', 'lead_created_at', 'fuente_nuevo', 'lea_sel_fuente_origen', 'portal', 'portal_value', 'remitente_lead', 'raw_payload'])
            ->whereIn('remitente_lead', array_keys($emailMap));

        return $query->get()
            ->filter(function (LeadRaw $lead) use ($emailMap): bool {
                $payload = is_array($lead->raw_payload) ? $lead->raw_payload : [];
                $emailCandidates = [
                    Str::lower(trim((string) ($lead->remitente_lead ?? ''))),
                    Str::lower(trim((string) data_get($payload, 'Email', ''))),
                    Str::lower(trim((string) data_get($payload, 'PersonEmail', ''))),
                ];

                return collect($emailCandidates)->filter()->contains(fn (string $value) => isset($emailMap[$value]));
            })
            ->map(function (LeadRaw $lead): array {
                $payload = is_array($lead->raw_payload) ? $lead->raw_payload : [];

                return [
                    'Id' => $lead->salesforce_id ?: 'lead-raw-'.$lead->id,
                    'Name' => data_get($payload, 'Name'),
                    'CreatedDate' => optional($lead->lead_created_at)->toIso8601String(),
                    'Phone' => data_get($payload, 'Phone'),
                    'MobilePhone' => data_get($payload, 'MobilePhone'),
                    'Email' => data_get($payload, 'Email') ?: $lead->remitente_lead,
                    'Portal_Text__c' => data_get($payload, 'Portal_Text__c') ?: $lead->portal,
                    'LEA_SEL_Fuente_Origen__c' => data_get($payload, 'LEA_SEL_Fuente_Origen__c') ?: $lead->lea_sel_fuente_origen,
                    'Fuente_Nuevo__c' => data_get($payload, 'Fuente_Nuevo__c') ?: $lead->fuente_nuevo,
                    'Fuente_origen__c' => data_get($payload, 'Fuente_origen__c'),
                ];
            })
            ->sortByDesc('CreatedDate')
            ->values()
            ->all();
    }

    private function resolvePortal(array $opportunity, Collection $leads): array
    {
        $rawPortal = $this->portalNormalizer->clean(data_get($opportunity, 'Portal__c'));
        $normalizedPortal = $this->portalNormalizer->normalize($rawPortal);
        $sourceRaw = $this->portalNormalizer->clean(data_get($opportunity, 'Fuente_de_Origen__c'));
        $sourceNormalized = $this->portalNormalizer->normalize($sourceRaw);
        $debug = [
            'rawPortal' => $rawPortal,
            'normalizedPortal' => $normalizedPortal['portal'],
            'opportunitySourceRaw' => $sourceRaw,
            'opportunitySourceNormalized' => $sourceNormalized['portal'],
            'selectedLeadId' => null,
            'selectedLeadSourceNewRaw' => null,
            'selectedLeadLegacyPortalRaw' => null,
            'selectedLeadLegacySourceField' => null,
            'selectedLeadPortalRaw' => null,
            'selectedLeadEffectiveSourceField' => null,
            'selectedLeadUsedFallback' => null,
            'selectedLeadConflict' => null,
            'reason' => null,
        ];

        if ($normalizedPortal['is_valid_final'] && $normalizedPortal['is_conclusive']) {
            $debug['reason'] = 'opportunity_portal_conclusive';

            return ['portal' => $normalizedPortal['portal'], 'source' => 'opportunity', 'lead_id' => null, 'debug' => $debug];
        }

        $emails = collect([data_get($opportunity, 'Account.PersonEmail'), data_get($opportunity, 'Account.AC_C_EMA_email__c')])
            ->filter()
            ->map(fn ($email) => Str::lower(trim((string) $email)))
            ->values()
            ->all();
        $phone = $this->phoneNormalizer->normalize(data_get($opportunity, 'Account.Phone'));

        $candidate = $leads
            ->filter(function (array $lead) use ($emails, $phone): bool {
                $emailMatch = filled(data_get($lead, 'Email')) && in_array(Str::lower(trim((string) data_get($lead, 'Email'))), $emails, true);
                $phoneMatch = $phone !== null && in_array($phone, [
                    $this->phoneNormalizer->normalize(data_get($lead, 'Phone')),
                    $this->phoneNormalizer->normalize(data_get($lead, 'MobilePhone')),
                ], true);

                return $emailMatch || $phoneMatch;
            })
            ->first(fn (array $lead): bool => $this->isEligibleLeadSource($lead));

        if ($candidate) {
            $leadResolution = $this->resolveLeadSource($candidate);
            $leadPortalRaw = $leadResolution['effective_value'];
            $leadPortal = $this->portalNormalizer->normalize($leadPortalRaw);
            $debug['selectedLeadId'] = data_get($candidate, 'Id');
            $debug['selectedLeadSourceNewRaw'] = $leadResolution['new_raw'];
            $debug['selectedLeadLegacyPortalRaw'] = $leadResolution['legacy_value'];
            $debug['selectedLeadLegacySourceField'] = $leadResolution['legacy_source_field'];
            $debug['selectedLeadPortalRaw'] = $leadPortalRaw;
            $debug['selectedLeadEffectiveSourceField'] = $leadResolution['source_field'];
            $debug['selectedLeadUsedFallback'] = $leadResolution['used_fallback'];
            $debug['selectedLeadConflict'] = $leadResolution['conflict'];
            $debug['reason'] = $leadResolution['source_field'] === 'Fuente_origen__c'
                ? 'lead_related_new_source'
                : 'lead_related_valid_portal';

            return [
                'portal' => $leadPortal['portal'],
                'source' => 'lead',
                'lead_id' => data_get($candidate, 'Id'),
                'debug' => array_merge($debug, ['lead_created_date' => data_get($candidate, 'CreatedDate')]),
            ];
        }

        if ($this->portalNormalizer->isUsefulSource($sourceRaw)) {
            $debug['reason'] = 'opportunity_source_valid';

            return ['portal' => $sourceNormalized['portal'], 'source' => 'opportunity_source', 'lead_id' => null, 'debug' => $debug];
        }

        if ($this->portalNormalizer->isFallbackExpositionRaw($rawPortal)) {
            $debug['reason'] = 'fallback_exposicion_no_alternative';

            return ['portal' => OpportunityPortalNormalizer::EXPOSITION, 'source' => 'fallback_exposicion', 'lead_id' => null, 'debug' => $debug];
        }

        if ($this->portalNormalizer->isFallbackWebRaw($rawPortal)) {
            $debug['reason'] = 'fallback_web_non_conclusive_portal';

            return ['portal' => OpportunityPortalNormalizer::WEB, 'source' => 'fallback_web', 'lead_id' => null, 'debug' => $debug];
        }

        $debug['reason'] = 'unclassified_no_valid_source';

        return ['portal' => OpportunityPortalNormalizer::UNCLASSIFIED, 'source' => 'unclassified', 'lead_id' => null, 'debug' => $debug];
    }

    private function isEligibleLeadSource(array $lead): bool
    {
        $resolution = $this->resolveLeadSource($lead);

        return $resolution['source_field'] === 'Fuente_origen__c'
            || $this->portalNormalizer->isValidForLead($resolution['effective_value']);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveLeadSource(array $lead): array
    {
        $legacy = $this->legacyLeadSource($lead);

        return array_merge(
            $this->leadFieldResolver->resolve(
                data_get($lead, 'Fuente_origen__c'),
                'Fuente_origen__c',
                $legacy['value'],
                $legacy['field'] ?? 'fallback',
            ),
            [
                'legacy_value' => $legacy['value'],
                'legacy_source_field' => $legacy['field'],
            ],
        );
    }

    /**
     * @return array{value:?string,field:?string}
     */
    private function legacyLeadSource(array $lead): array
    {
        foreach ([
            ['Portal_Text__c', data_get($lead, 'Portal_Text__c')],
            ['LEA_SEL_Fuente_Origen__c', data_get($lead, 'LEA_SEL_Fuente_Origen__c')],
            ['Fuente_Nuevo__c', data_get($lead, 'Fuente_Nuevo__c')],
        ] as [$field, $value]) {
            if (($clean = $this->portalNormalizer->clean($value)) !== null) {
                return ['value' => $clean, 'field' => $field];
            }
        }

        return ['value' => null, 'field' => null];
    }

    private function soqlDateTime(CarbonInterface $date): string
    {
        return CarbonImmutable::parse($date)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private function parseDateTime(mixed $value): ?CarbonImmutable
    {
        return blank($value) ? null : CarbonImmutable::parse($value);
    }

    /** @return array{queried:int,matched_local:int,changed:int,unchanged:int} */
    private function syncDeleted(CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        $stats = ['queried' => 0, 'matched_local' => 0, 'changed' => 0, 'unchanged' => 0];
        $chunkStart = CarbonImmutable::parse($periodStart);
        $finalEnd = CarbonImmutable::parse($periodEnd);

        while ($chunkStart->lessThan($finalEnd)) {
            $chunkEnd = $chunkStart->addDays(self::SYNC_CHUNK_DAYS)->min($finalEnd);
            $chunkStats = $this->syncDeletedChunk($chunkStart, $chunkEnd);
            foreach ($stats as $key => $value) {
                $stats[$key] += $chunkStats[$key];
            }
            $chunkStart = $chunkEnd;
        }

        return $stats;
    }

    /** @return array{queried:int,matched_local:int,changed:int,unchanged:int} */
    private function syncDeletedChunk(CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        $start = $this->soqlDateTime($periodStart);
        $end = $this->soqlDateTime($periodEnd);
        $records = collect($this->client->queryAll(<<<SOQL
SELECT Id, IsDeleted, SystemModstamp
FROM Opportunity
WHERE IsDeleted = true
    AND SystemModstamp >= {$start}
    AND SystemModstamp < {$end}
SOQL))
            ->filter(fn (mixed $record): bool => is_array($record) && filled(data_get($record, 'Id')))
            ->values();

        $lookupIds = $records
            ->flatMap(function (array $record): array {
                $id = (string) data_get($record, 'Id');

                return [$id, substr($id, 0, 15)];
            })
            ->unique()
            ->values();
        if ($lookupIds->isEmpty()) {
            return ['queried' => 0, 'matched_local' => 0, 'changed' => 0, 'unchanged' => 0];
        }

        $localRows = SalesforceOpportunity::withoutGlobalScope(SalesforceOpportunity::ACTIVE_SCOPE)
            ->select(['id', 'salesforce_id', 'is_deleted', 'salesforce_deleted_at', 'deletion_detection_source'])
            ->whereIn('salesforce_id', $lookupIds)
            ->get();
        $localRowsByCanonicalId = $localRows
            ->groupBy(fn (SalesforceOpportunity $row): string => substr((string) $row->salesforce_id, 0, 15));
        $updates = [];
        $unchanged = 0;

        foreach ($records as $record) {
            foreach ($localRowsByCanonicalId->get(substr((string) data_get($record, 'Id'), 0, 15), collect()) as $row) {
                $deletedAt = $this->parseDateTime(data_get($record, 'SystemModstamp'));
                $sameDeletedAt = $row->salesforce_deleted_at?->toIso8601String() === $deletedAt?->toIso8601String();
                if ($row->is_deleted
                    && $sameDeletedAt
                    && $row->deletion_detection_source === SalesforceOpportunity::DELETION_SOURCE_QUERY_ALL) {
                    $unchanged++;

                    continue;
                }

                $updates[] = ['id' => (int) $row->id, 'deleted_at' => $deletedAt];
            }
        }

        $this->bulkMarkDeleted($updates);

        return [
            'queried' => $records->count(),
            'matched_local' => $localRows->count(),
            'changed' => count($updates),
            'unchanged' => $unchanged,
        ];
    }

    /** @param list<array{id:int,deleted_at:?CarbonImmutable}> $updates */
    private function bulkMarkDeleted(array $updates): void
    {
        if ($updates === []) {
            return;
        }

        $grammar = DB::connection()->getQueryGrammar();
        $table = $grammar->wrapTable('salesforce_opportunities');
        $idColumn = $grammar->wrap('id');
        $bindings = [];

        $cases = [];
        foreach ($updates as $update) {
            $cases[] = 'WHEN ? THEN ?';
            $bindings[] = $update['id'];
            $bindings[] = $update['deleted_at'];
        }
        $deletedAtColumn = $grammar->wrap('salesforce_deleted_at');
        $dateAssignment = "{$deletedAtColumn} = CASE {$idColumn} ".implode(' ', $cases)." ELSE {$deletedAtColumn} END";

        $ids = array_column($updates, 'id');
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $bindings[] = now();
        array_push($bindings, ...$ids);

        DB::update(
            "UPDATE {$table} SET "
            .$grammar->wrap('is_deleted').' = 1, '
            .$dateAssignment.', '
            .$grammar->wrap('deletion_detection_source')." = '".SalesforceOpportunity::DELETION_SOURCE_QUERY_ALL."', "
            .$grammar->wrap('updated_at')." = ? WHERE {$idColumn} IN ({$placeholders})",
            $bindings,
        );
    }

    /** @return list<string> */
    private function phoneQueryVariants(string $normalizedPhone): array
    {
        if (strlen($normalizedPhone) !== 9) {
            return [$normalizedPhone];
        }

        $grouped = implode(' ', str_split($normalizedPhone, 3));

        return [
            $normalizedPhone,
            $grouped,
            implode('-', str_split($normalizedPhone, 3)),
            implode('.', str_split($normalizedPhone, 3)),
            '34'.$normalizedPhone,
            '+34'.$normalizedPhone,
            '+34 '.$grouped,
        ];
    }

    private function escape(string $value): string
    {
        return str_replace("'", "\\'", $value);
    }
}
