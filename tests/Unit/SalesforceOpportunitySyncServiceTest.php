<?php

namespace Tests\Unit;

use App\Models\LeadRaw;
use App\Models\SalesforceLead;
use App\Models\SalesforceOpportunity;
use App\Services\Reports\ReservasVentas\OpportunityPortalNormalizer;
use App\Services\Reports\ReservationsSales\Sync\SalesforceOpportunitySyncService;
use App\Services\Salesforce\SalesforceClient;
use App\Services\Salesforce\SalesforceLeadFieldResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesforceOpportunitySyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_incremental_descubre_por_last_modified_sin_usarlo_como_fecha_funcional(): void
    {
        $service = new SalesforceOpportunitySyncService(
            new class extends SalesforceClient
            {
                public function __construct() {}
            },
            app(OpportunityPortalNormalizer::class),
            app(SalesforceLeadFieldResolver::class),
        );
        $soql = $service->modifiedSoql(
            CarbonImmutable::parse('2026-08-24', 'UTC'),
            CarbonImmutable::parse('2026-08-26', 'UTC'),
        );

        $this->assertStringContainsString('LastModifiedDate >= 2026-08-24T00:00:00Z', $soql);
        $this->assertStringContainsString('LastModifiedDate < 2026-08-26T00:00:00Z', $soql);
        $this->assertStringContainsString('OPO_FEC_Fecha_de_reserva__c', $soql);
        $this->assertStringContainsString('Fecha_firma_contrato__c', $soql);
        $this->assertStringNotContainsString('CloseDate AS cancellation', $soql);
    }

    public function test_sync_marca_borrado_confirmado_por_query_all_sin_borrar_la_fila(): void
    {
        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006AAAAAAAAAAAA',
            'name' => 'Historica',
            'created_date' => '2026-08-20 09:00:00',
            'salesforce_last_modified_at' => '2026-08-30 08:00:00',
        ]);
        $client = new class extends SalesforceClient
        {
            public string $deletedSoql = '';

            public function __construct() {}

            public function queryAll(string $soql): array
            {
                $this->deletedSoql = $soql;

                return [[
                    'Id' => '006AAAAAAAAAAAA',
                    'IsDeleted' => true,
                    'SystemModstamp' => '2026-09-01T12:00:00.000Z',
                ], [
                    'Id' => '006ZZZZZZZZZZZZ',
                    'IsDeleted' => true,
                    'SystemModstamp' => '2026-09-01T12:00:00.000Z',
                ]];
            }

            public function query(string $soql): array
            {
                return [];
            }
        };

        $result = $this->service($client)->sync(
            CarbonImmutable::parse('2026-09-01 00:00:00', 'UTC'),
            CarbonImmutable::parse('2026-09-02 00:00:00', 'UTC'),
            true,
        );

        $this->assertStringContainsString('IsDeleted = true', $client->deletedSoql);
        $this->assertStringContainsString('SystemModstamp >= 2026-09-01T00:00:00Z', $client->deletedSoql);
        $this->assertStringNotContainsString('LastModifiedDate >=', $client->deletedSoql);
        $this->assertSame(1, $result['deletions']['changed']);
        $this->assertSame(1, $result['deletions']['matched_local']);
        $this->assertNull(SalesforceOpportunity::query()->where('salesforce_id', '006AAAAAAAAAAAA')->first());
        $this->assertDatabaseHas('salesforce_opportunities', [
            'salesforce_id' => '006AAAAAAAAAAAA',
            'is_deleted' => true,
            'salesforce_deleted_at' => '2026-09-01 12:00:00',
            'salesforce_last_modified_at' => '2026-08-30 08:00:00',
            'deletion_detection_source' => 'query_all_deleted',
        ]);
        $this->assertDatabaseMissing('salesforce_opportunities', ['salesforce_id' => '006ZZZZZZZZZZZZ']);

        $second = $this->service($client)->sync(
            CarbonImmutable::parse('2026-09-01 00:00:00', 'UTC'),
            CarbonImmutable::parse('2026-09-02 00:00:00', 'UTC'),
            true,
        );

        $this->assertSame(0, $second['deletions']['changed']);
        $this->assertSame(1, $second['deletions']['unchanged']);
        $this->assertDatabaseHas('salesforce_opportunities', [
            'salesforce_id' => '006AAAAAAAAAAAA',
            'salesforce_last_modified_at' => '2026-08-30 08:00:00',
        ]);
    }

    public function test_sync_reactiva_una_opportunity_que_salesforce_devuelve_activa(): void
    {
        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006BBBBBBBBBBBB',
            'name' => 'Marcada previamente',
            'is_deleted' => true,
            'salesforce_deleted_at' => '2026-08-31 10:00:00',
            'deletion_detection_source' => 'query_all_deleted',
        ]);
        $client = new class extends SalesforceClient
        {
            public function __construct() {}

            public function queryAll(string $soql): array
            {
                return [];
            }

            public function query(string $soql): array
            {
                return [[
                    'Id' => '006BBBBBBBBBBBB',
                    'Name' => 'Reaparecida',
                    'CreatedDate' => '2026-09-01T09:00:00.000Z',
                    'LastModifiedDate' => '2026-09-01T11:00:00.000Z',
                    'StageName' => 'Abierta',
                    'RecordType' => ['Name' => 'Venta'],
                    'Account' => [],
                ]];
            }
        };

        $this->service($client)->sync(
            CarbonImmutable::parse('2026-09-01 00:00:00', 'UTC'),
            CarbonImmutable::parse('2026-09-02 00:00:00', 'UTC'),
            true,
        );

        $this->assertDatabaseHas('salesforce_opportunities', [
            'salesforce_id' => '006BBBBBBBBBBBB',
            'is_deleted' => false,
            'salesforce_deleted_at' => null,
            'deletion_detection_source' => null,
        ]);
        $this->assertSame('Reaparecida', SalesforceOpportunity::query()->where('salesforce_id', '006BBBBBBBBBBBB')->value('name'));
    }

    public function test_guarda_oportunidades_y_resuelve_portal_desde_salesforce_o_lead(): void
    {
        $client = new class extends SalesforceClient
        {
            public function __construct() {}

            public function queryAll(string $soql): array
            {
                return [];
            }

            public function query(string $soql): array
            {
                if (str_contains($soql, 'FROM Lead')) {
                    if (! str_contains($soql, 'Fuente_origen__c')) {
                        throw new \RuntimeException('La consulta auxiliar de Leads no contiene la fuente nueva esperada.');
                    }

                    return [
                        [
                            'Id' => '00Q-lead-1',
                            'CreatedDate' => '2026-05-10T10:00:00.000+0000',
                            'Phone' => '600 000 001',
                            'MobilePhone' => null,
                            'Email' => 'cliente@example.com',
                            'Fuente_origen__c' => 'Coches.net',
                            'Portal_Text__c' => 'Meta',
                        ],
                        [
                            'Id' => '00Q-lead-2',
                            'CreatedDate' => '2026-05-11T10:00:00.000+0000',
                            'Phone' => '600000002',
                            'MobilePhone' => null,
                            'Email' => 'expo@example.com',
                            'Portal_Text__c' => 'Google Maps',
                        ],
                    ];
                }

                return [
                    [
                        'Id' => '006-opportunity-1',
                        'Name' => 'Venta directa',
                        'CreatedDate' => '2026-05-01T10:00:00.000+0000',
                        'CloseDate' => '2026-05-31',
                        'Amount' => 12500.50,
                        'OPO_FOR_Importe_total__c' => 13000.75,
                        'Comisi_n_Financiera__c' => 1234.56,
                        'OPO_DIV_Descuento_financiera__c' => 78.90,
                        'zona_financiera__c' => 'Zona Carlos',
                        'StageName' => 'Reserva',
                        'RecordType' => ['Name' => 'Venta'],
                        'OwnerId' => '005-owner-1',
                        'Owner' => ['Name' => 'Comercial Uno', 'USR_SEL_Delegacion__c' => 'Alcobendas'],
                        'AccountId' => '001-1',
                        'Account' => [
                            'Name' => 'Cuenta 1',
                            'Phone' => '600000000',
                            'PersonEmail' => null,
                            'AC_C_EMA_email__c' => null,
                        ],
                        'Portal__c' => 'Web',
                        'Captador_de_cita__c' => '005Qx00000GTjweIAD',
                        'Captador_de_cita__r' => ['Name' => 'Maria Vidal Perez'],
                        'Captador__c' => 'Jose Mari',
                        'Comisi_n_Captador__c' => 5.0,
                        'Fecha_captador__c' => '2026-05-01',
                        'Captador_2__c' => 'German Olsen',
                        'Captador_3__c' => null,
                        'Captador_4__c' => null,
                        'Fecha_captado_2__c' => '2026-05-02',
                        'Fecha_captador_3__c' => null,
                        'Fecha_captador_4__c' => null,
                        'OPO_CAS_Reserva__c' => true,
                        'OPO_FEC_Fecha_de_reserva__c' => '2026-05-02',
                        'OPO_CAS_Contrato_CV_firmado__c' => false,
                        'Fecha_firma_contrato__c' => null,
                        'Tienda_de_entrega__c' => 'HR MOTOR ALICANTE',
                        'Gestion_de_venta__c' => false,
                        'OPO_FEC_Fecha_entrega__c' => '2026-05-10',
                        'Delegacion_del_propietario__c' => 'CAPTADOR U ONLINE',
                        'Facturado_Facilitea__c' => false,
                        'Numero_Factura_Facilitea__c' => null,
                        'OPO_BUS_Vehiculo_a_tasar__c' => '01t-appraise-1',
                        'OPO_BUS_Vehiculo_a_tasar__r' => [
                            'Name' => '1234ABC Opel Corsa',
                        ],
                        'OPP_BUS_Vehiculo_de_interes__r' => [
                            'Name' => '5678DEF Peugeot 308',
                            'PRO_DIV_Precio_de_venta__c' => 12000,
                            'PRO_DIV_Precio_de_compra__c' => 9000,
                            'Procedencia_de_compra__c' => 'Compra directa',
                            'PRO_FEC_Fecha_compra__c' => '2026-05-03',
                            'Comprador_oportunidad__c' => '005-buyer-1',
                            'Comprador_oportunidad__r' => ['Name' => 'Comprador Uno'],
                        ],
                    ],
                    [
                        'Id' => '006-opportunity-2',
                        'Name' => 'Venta reconstruida',
                        'CreatedDate' => '2026-05-03T10:00:00.000+0000',
                        'Amount' => 18000,
                        'OPO_FOR_Importe_total__c' => 18100,
                        'StageName' => 'Contrato',
                        'RecordType' => ['Name' => 'Cambio'],
                        'OwnerId' => '005-owner-2',
                        'Owner' => ['Name' => 'Comercial Dos', 'USR_SEL_Delegacion__c' => 'Sant Boi'],
                        'AccountId' => '001-2',
                        'Account' => [
                            'Phone' => '+34 600 000 001',
                            'PersonEmail' => 'cliente@example.com',
                            'AC_C_EMA_email__c' => null,
                        ],
                        'Portal__c' => null,
                        'Captador_de_cita__c' => '005-contact-center-2',
                        'Captador_de_cita__r' => ['Name' => 'Yuleidis Garcia'],
                        'OPO_CAS_Reserva__c' => true,
                        'OPO_FEC_Fecha_de_reserva__c' => '2026-05-04',
                        'OPO_CAS_Contrato_CV_firmado__c' => true,
                        'Fecha_firma_contrato__c' => '2026-05-05',
                        'Gestion_de_venta__c' => true,
                    ],
                    [
                        'Id' => '006-opportunity-3',
                        'Name' => 'Exposicion con alternativa',
                        'CreatedDate' => '2026-05-06T10:00:00.000+0000',
                        'Amount' => 9000,
                        'OPO_FOR_Importe_total__c' => 0,
                        'StageName' => 'Cerrada Perdida',
                        'RecordType' => ['Name' => 'Tasacion'],
                        'OwnerId' => '005-owner-3',
                        'Owner' => ['Name' => 'Comercial Tres', 'USR_SEL_Delegacion__c' => 'Bilbao'],
                        'AccountId' => '001-3',
                        'Account' => [
                            'Phone' => '600000002',
                            'PersonEmail' => 'expo@example.com',
                            'AC_C_EMA_email__c' => null,
                        ],
                        'Portal__c' => 'Exposición',
                        'OPO_CAS_Reserva__c' => true,
                        'OPO_CAS_Contrato_CV_firmado__c' => true,
                        'Gestion_de_venta__c' => false,
                    ],
                ];
            }
        };

        $service = $this->service($client);
        $result = $service->sync(
            CarbonImmutable::parse('2026-04-01 00:00:00', 'UTC'),
            CarbonImmutable::parse('2026-06-01 00:00:00', 'UTC'),
        );

        $this->assertSame(3, $result['queried']);
        $this->assertSame(3, $result['saved']);
        $this->assertStringContainsString('FROM Opportunity', $result['soql']);
        $this->assertStringContainsString('Amount', $result['soql']);
        $this->assertStringContainsString('OPO_FOR_Importe_total__c', $result['soql']);
        $this->assertStringContainsString('OPO_FEC_Fecha_de_reserva__c', $result['soql']);
        $this->assertStringContainsString('Fecha_firma_contrato__c', $result['soql']);
        $this->assertStringContainsString('Tienda_de_entrega__c', $result['soql']);
        $this->assertStringContainsString('Gestion_de_venta__c', $result['soql']);
        $this->assertStringContainsString('Captador_de_cita__c', $result['soql']);
        $this->assertStringContainsString('Captador_de_cita__r.Name', $result['soql']);
        $this->assertStringContainsString('Captador__c', $result['soql']);
        $this->assertStringContainsString('Comisi_n_Captador__c', $result['soql']);
        $this->assertStringContainsString('Fecha_captador__c', $result['soql']);
        $this->assertStringContainsString('Captador_2__c', $result['soql']);
        $this->assertStringContainsString('Fecha_captado_2__c', $result['soql']);
        $this->assertStringContainsString('OPO_FEC_Fecha_entrega__c', $result['soql']);
        $this->assertStringContainsString('Delegacion_del_propietario__c', $result['soql']);
        $this->assertStringContainsString('Facturado_Facilitea__c', $result['soql']);
        $this->assertStringContainsString('Numero_Factura_Facilitea__c', $result['soql']);
        $this->assertStringContainsString('OPO_BUS_Vehiculo_a_tasar__c', $result['soql']);
        $this->assertStringContainsString('OPO_BUS_Vehiculo_a_tasar__r.Name', $result['soql']);
        $this->assertStringContainsString('OPP_BUS_Vehiculo_de_interes__r.Name', $result['soql']);
        $this->assertStringContainsString('PRO_DIV_Precio_de_venta__c', $result['soql']);
        $this->assertStringContainsString('Procedencia_de_compra__c', $result['soql']);
        $this->assertStringContainsString('PRO_FEC_Fecha_compra__c', $result['soql']);
        $this->assertStringContainsString('Comprador_oportunidad__c', $result['soql']);

        $this->assertDatabaseHas('salesforce_opportunities', [
            'salesforce_id' => '006-opportunity-1',
            'record_type_name' => 'Venta',
            'owner_delegation' => 'Alcobendas',
            'appointment_setter_id' => '005Qx00000GTjweIAD',
            'appointment_setter_name' => 'Maria Vidal Perez',
            'delivery_store' => 'HR MOTOR ALICANTE',
            'amount' => 12500.50,
            'opo_for_importe_total' => 13000.75,
            'financial_commission' => 1234.56,
            'financial_discount' => 78.90,
            'financial_zone' => 'Zona Carlos',
            'portal_resolved' => 'Web',
            'portal_resolution_source' => 'opportunity',
            'reservation' => true,
            'cv_signed' => false,
            'gestion_de_venta' => false,
            'vehicle_sale_price' => 12000,
            'vehicle_purchase_price' => 9000,
            'vehicle_purchase_source' => 'Compra directa',
            'vehicle_purchase_date' => '2026-05-03 00:00:00',
            'vehicle_buyer_id' => '005-buyer-1',
            'vehicle_buyer_name' => 'Comprador Uno',
        ]);
        $this->assertDatabaseHas('salesforce_opportunities', [
            'salesforce_id' => '006-opportunity-2',
            'portal_resolved' => 'Coches.net',
            'amount' => 18000,
            'opo_for_importe_total' => 18100,
            'portal_resolution_source' => 'lead',
            'portal_resolution_lead_id' => '00Q-lead-1',
            'cv_signed' => true,
            'gestion_de_venta' => true,
        ]);
        $this->assertDatabaseHas('salesforce_opportunities', [
            'salesforce_id' => '006-opportunity-3',
            'portal_resolved' => 'Google Maps',
            'portal_resolution_source' => 'lead',
            'stage_name' => 'Cerrada Perdida',
            'appointment_setter_id' => null,
            'appointment_setter_name' => null,
        ]);

        $storedOpportunity = SalesforceOpportunity::query()->where('salesforce_id', '006-opportunity-1')->firstOrFail();

        $this->assertSame('Jose Mari', $storedOpportunity->raw_payload['Captador__c'] ?? null);
        $this->assertSame('005Qx00000GTjweIAD', $storedOpportunity->raw_payload['Captador_de_cita__c'] ?? null);
        $this->assertSame('Maria Vidal Perez', data_get($storedOpportunity->raw_payload, 'Captador_de_cita__r.Name'));
        $this->assertEquals(5.0, $storedOpportunity->raw_payload['Comisi_n_Captador__c'] ?? null);
        $this->assertSame('German Olsen', $storedOpportunity->raw_payload['Captador_2__c'] ?? null);
        $this->assertSame('2026-05-10', $storedOpportunity->raw_payload['OPO_FEC_Fecha_entrega__c'] ?? null);
        $this->assertSame('1234ABC Opel Corsa', data_get($storedOpportunity->raw_payload, 'OPO_BUS_Vehiculo_a_tasar__r.Name'));
        $this->assertSame('5678DEF Peugeot 308', data_get($storedOpportunity->raw_payload, 'OPP_BUS_Vehiculo_de_interes__r.Name'));

        $storedLeadOpportunity = SalesforceOpportunity::query()->where('salesforce_id', '006-opportunity-2')->firstOrFail();
        $this->assertSame('Coches.net', data_get($storedLeadOpportunity->portal_resolution_debug, 'selectedLeadSourceNewRaw'));
        $this->assertSame('Meta', data_get($storedLeadOpportunity->portal_resolution_debug, 'selectedLeadLegacyPortalRaw'));
        $this->assertSame('Fuente_origen__c', data_get($storedLeadOpportunity->portal_resolution_debug, 'selectedLeadEffectiveSourceField'));
        $this->assertTrue(data_get($storedLeadOpportunity->portal_resolution_debug, 'selectedLeadConflict'));
    }

    public function test_fallback_leads_raw_reconstruye_fuente_nueva_desde_raw_payload_y_conserva_legacy_sin_ella(): void
    {
        LeadRaw::query()->create([
            'salesforce_id' => '00Q-raw-new',
            'lead_created_at' => '2026-05-20 10:00:00',
            'remitente_lead' => 'nuevo@example.com',
            'portal' => 'Google Maps',
            'raw_payload' => [
                'Email' => 'nuevo@example.com',
                'Fuente_origen__c' => 'Wallapop',
                'Portal_Text__c' => 'Google Maps',
            ],
        ]);
        LeadRaw::query()->create([
            'salesforce_id' => '00Q-raw-legacy',
            'lead_created_at' => '2026-05-19 10:00:00',
            'remitente_lead' => 'legacy@example.com',
            'portal' => 'Google Maps',
            'raw_payload' => [
                'Email' => 'legacy@example.com',
                'Portal_Text__c' => 'Google Maps',
            ],
        ]);

        $client = new class extends SalesforceClient
        {
            public function __construct() {}

            public function query(string $soql): array
            {
                $this->assertLeadQuery($soql);

                return [];
            }

            private function assertLeadQuery(string $soql): void
            {
                if (! str_contains($soql, 'FROM Lead') || ! str_contains($soql, 'Fuente_origen__c')) {
                    throw new \RuntimeException('La consulta auxiliar de Leads no contiene la fuente nueva esperada.');
                }
            }
        };
        $service = $this->service($client);
        $opportunities = [
            $this->opportunityForLeadRaw('nuevo@example.com'),
            $this->opportunityForLeadRaw('legacy@example.com'),
        ];
        $matches = $service->relatedLeadMatchesForOpportunities($opportunities);

        $newResult = $service->resolvePortalForRecord($opportunities[0], $matches);
        $legacyResult = $service->resolvePortalForRecord($opportunities[1], $matches);

        $this->assertSame('Wallapop', $newResult['portal']);
        $this->assertSame('Fuente_origen__c', data_get($newResult, 'debug.selectedLeadEffectiveSourceField'));
        $this->assertSame('Google Maps', $legacyResult['portal']);
        $this->assertSame('Portal_Text__c', data_get($legacyResult, 'debug.selectedLeadEffectiveSourceField'));
        $this->assertTrue(data_get($legacyResult, 'debug.selectedLeadUsedFallback'));
    }

    public function test_matching_de_lead_no_cambia_cuando_otro_registro_aporta_el_telefono_bruto_de_otro_candidato(): void
    {
        $target = $this->opportunityForPhone('+34 600 000 001');
        $other = $this->opportunityForPhone('600000001');
        $client = new class extends SalesforceClient
        {
            public function __construct() {}

            public function query(string $soql): array
            {
                $records = [];

                if (str_contains($soql, "'+34 600 000 001'")) {
                    $records[] = $this->lead('00Q-target-lead', '+34 600 000 001', 'Coches.net', '2026-05-10T10:00:00.000+0000');
                }

                if (str_contains($soql, "'600000001'")) {
                    $records[] = $this->lead('00Q-batch-lead', '600000001', 'Wallapop', '2026-05-11T10:00:00.000+0000');
                }

                return $records;
            }

            private function lead(string $id, string $phone, string $source, string $createdDate): array
            {
                return [
                    'Id' => $id,
                    'CreatedDate' => $createdDate,
                    'Phone' => $phone,
                    'MobilePhone' => null,
                    'Email' => null,
                    'Fuente_origen__c' => $source,
                    'Portal_Text__c' => null,
                    'LEA_SEL_Fuente_Origen__c' => null,
                    'Fuente_Nuevo__c' => null,
                ];
            }
        };
        $service = $this->service($client);

        $isolated = $service->resolvePortalForRecord($target, $service->relatedLeadMatchesForOpportunities([$target]));
        $batched = $service->resolvePortalForRecord($target, $service->relatedLeadMatchesForOpportunities([$target, $other]));

        $this->assertSame('Wallapop', $isolated['portal']);
        $this->assertSame($isolated, $batched);
        $this->assertSame('00Q-batch-lead', $batched['lead_id']);
    }

    public function test_opportunity_sin_email_ni_telefono_no_consulta_leads_y_conserva_su_fallback(): void
    {
        $client = new class extends SalesforceClient
        {
            public int $queries = 0;

            public function __construct() {}

            public function query(string $soql): array
            {
                $this->queries++;

                return [];
            }
        };
        $service = $this->service($client);
        $target = $this->opportunityForPhone('', portal: 'Buscador');
        $matches = $service->relatedLeadMatchesForOpportunities([$target]);
        $result = $service->resolvePortalForRecord($target, $matches);

        $this->assertSame(0, $client->queries);
        $this->assertSame('Web', $result['portal']);
        $this->assertSame('fallback_web', $result['source']);
    }

    public function test_matching_no_importa_un_lead_recuperado_exclusivamente_por_otro_registro_del_lote(): void
    {
        $target = $this->opportunityForPhone('+34 611 000 001', portal: 'Exposicion');
        $other = $this->opportunityForPhone('611000001');
        $client = new class extends SalesforceClient
        {
            public function __construct() {}

            public function query(string $soql): array
            {
                if (! str_contains($soql, "'611000001'")) {
                    return [];
                }

                return [[
                    'Id' => '00Q-other-only',
                    'CreatedDate' => '2026-05-11T10:00:00.000+0000',
                    'Phone' => '611000001',
                    'MobilePhone' => null,
                    'Email' => null,
                    'Fuente_origen__c' => 'Web',
                    'Portal_Text__c' => null,
                    'LEA_SEL_Fuente_Origen__c' => null,
                    'Fuente_Nuevo__c' => null,
                ]];
            }
        };
        $service = $this->service($client);

        $isolated = $service->resolvePortalForRecord($target, $service->relatedLeadMatchesForOpportunities([$target]));
        $batched = $service->resolvePortalForRecord($target, $service->relatedLeadMatchesForOpportunities([$target, $other]));

        $this->assertSame('Web', $isolated['portal']);
        $this->assertSame('lead', $isolated['source']);
        $this->assertSame($isolated, $batched);
    }

    public function test_fallback_local_de_email_se_decide_por_identificador_y_no_por_resultado_global_del_lote(): void
    {
        LeadRaw::query()->create([
            'salesforce_id' => '00Q-email-fallback',
            'lead_created_at' => '2026-05-10 10:00:00',
            'remitente_lead' => 'target@example.test',
            'portal' => 'Coches.net',
            'raw_payload' => [
                'Email' => 'target@example.test',
                'Portal_Text__c' => 'Coches.net',
            ],
        ]);
        $target = $this->opportunityForLeadRaw('target@example.test');
        $other = $this->opportunityForLeadRaw('other@example.test');
        $client = new class extends SalesforceClient
        {
            public function __construct() {}

            public function query(string $soql): array
            {
                if (! str_contains($soql, "'other@example.test'")) {
                    return [];
                }

                return [[
                    'Id' => '00Q-other-email',
                    'CreatedDate' => '2026-05-11T10:00:00.000+0000',
                    'Phone' => null,
                    'MobilePhone' => null,
                    'Email' => 'other@example.test',
                    'Fuente_origen__c' => 'Wallapop',
                ]];
            }
        };
        $service = $this->service($client);

        $isolated = $service->resolvePortalForRecord($target, $service->relatedLeadMatchesForOpportunities([$target]));
        $batched = $service->resolvePortalForRecord($target, $service->relatedLeadMatchesForOpportunities([$target, $other]));

        $this->assertSame('Coches.net', $isolated['portal']);
        $this->assertSame('00Q-email-fallback', $isolated['lead_id']);
        $this->assertSame($isolated, $batched);
    }

    public function test_empate_temporal_de_leads_se_resuelve_por_salesforce_id(): void
    {
        $target = $this->opportunityForPhone('633 000 001');
        $client = new class extends SalesforceClient
        {
            public function __construct() {}

            public function query(string $soql): array
            {
                return [
                    $this->lead('00Q-z', 'Wallapop'),
                    $this->lead('00Q-a', 'Coches.net'),
                ];
            }

            private function lead(string $id, string $source): array
            {
                return [
                    'Id' => $id,
                    'CreatedDate' => '2026-05-10T10:00:00.000+0000',
                    'Phone' => '633-000-001',
                    'MobilePhone' => null,
                    'Email' => null,
                    'Fuente_origen__c' => $source,
                ];
            }
        };
        $service = $this->service($client);

        $result = $service->resolvePortalForRecord($target, $service->relatedLeadMatchesForOpportunities([$target]));

        $this->assertSame('Coches.net', $result['portal']);
        $this->assertSame('00Q-a', $result['lead_id']);
    }

    public function test_matching_es_invariante_en_primera_ultima_y_siguiente_posicion_de_chunk(): void
    {
        $target = $this->opportunityForPhone('+34 622 000 001');
        $other = $this->opportunityForPhone('622000001');
        $fillers = collect(range(1, 99))
            ->map(fn (int $number): array => $this->opportunityForPhone('7'.str_pad((string) $number, 8, '0', STR_PAD_LEFT)))
            ->all();
        $client = new class extends SalesforceClient
        {
            public function __construct() {}

            public function query(string $soql): array
            {
                $records = [];

                if (str_contains($soql, "'+34 622 000 001'")) {
                    $records[] = $this->lead('00Q-stable-target', '+34 622 000 001', 'Coches.net');
                }

                if (str_contains($soql, "'622000001'")) {
                    $records[] = $this->lead('00Q-shared-normalized', '622000001', 'Wallapop');
                }

                return $records;
            }

            private function lead(string $id, string $phone, string $source): array
            {
                return [
                    'Id' => $id,
                    'CreatedDate' => $id === '00Q-shared-normalized'
                        ? '2026-05-11T10:00:00.000+0000'
                        : '2026-05-10T10:00:00.000+0000',
                    'Phone' => $phone,
                    'MobilePhone' => null,
                    'Email' => null,
                    'Fuente_origen__c' => $source,
                ];
            }
        };
        $service = $this->service($client);
        $batches = [
            [$target, ...$fillers, $other],
            [...$fillers, $target, $other],
            [...$fillers, $other, $target],
        ];
        $isolated = $service->resolvePortalForRecord($target, $service->relatedLeadMatchesForOpportunities([$target]));

        foreach ($batches as $batch) {
            $result = $service->resolvePortalForRecord($target, $service->relatedLeadMatchesForOpportunities($batch));

            $this->assertSame($isolated, $result);
        }
    }

    public function test_busqueda_telefonica_usa_variantes_in_acotadas_sin_wildcards_por_digito(): void
    {
        $client = new class extends SalesforceClient
        {
            /** @var list<string> */
            public array $queries = [];

            public function __construct() {}

            public function query(string $soql): array
            {
                $this->queries[] = $soql;

                return [];
            }
        };
        $opportunities = collect(range(1, 100))
            ->map(fn (int $number): array => $this->opportunityForPhone('6'.str_pad((string) $number, 8, '0', STR_PAD_LEFT)))
            ->all();

        $this->service($client)->relatedLeadMatchesForOpportunities($opportunities);

        $this->assertCount(5, $client->queries);
        foreach ($client->queries as $soql) {
            $this->assertStringContainsString('Phone IN (', $soql);
            $this->assertStringContainsString('MobilePhone IN (', $soql);
            $this->assertStringNotContainsString(' LIKE ', $soql);
            $this->assertStringNotContainsString('%6%0%0%0%0%0%0%0%1%', $soql);
            $this->assertLessThanOrEqual(5000, strlen($soql));
        }
    }

    public function test_telefonos_sin_resultado_salesforce_no_activan_full_scan_de_leads_raw(): void
    {
        LeadRaw::query()->create([
            'salesforce_id' => '00Q-phone-raw',
            'lead_created_at' => '2026-05-10 10:00:00',
            'raw_payload' => ['Phone' => '644 000 001', 'Portal_Text__c' => 'Coches.net'],
        ]);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $client = new class extends SalesforceClient
        {
            public function __construct() {}

            public function query(string $soql): array
            {
                return [];
            }
        };
        $matches = $this->service($client)->relatedLeadMatchesForOpportunities([
            $this->opportunityForPhone('644000001'),
            $this->opportunityForPhone('655000001'),
        ]);

        $this->assertCount(0, $matches);
        $this->assertFalse(collect($queries)->contains(
            fn (string $sql): bool => str_contains(strtolower($sql), 'leads_raw'),
        ));
    }

    public function test_indice_local_descubre_formatos_telefonicos_no_cubiertos_por_variantes_textuales(): void
    {
        foreach ([
            '612 34 56 78',
            '+34 612 34 56 78',
            '612-345-678 / extensión 90',
        ] as $index => $leadPhone) {
            $normalized = preg_replace('/\D+/', '', $leadPhone);
            $normalized = preg_replace('/^34(?=\d{9}$)/', '', $normalized ?? '');
            $salesforceId = '00QINDEXED'.str_pad((string) $index, 5, '0', STR_PAD_LEFT);
            SalesforceLead::query()->create([
                'salesforce_id' => $salesforceId,
                'created_date' => '2026-05-10 10:00:00',
                'phone' => $leadPhone,
                'phone_normalized' => $normalized,
                'is_deleted' => false,
            ]);
            $client = new class($salesforceId, $leadPhone) extends SalesforceClient
            {
                /** @var list<string> */
                public array $queries = [];

                public function __construct(private readonly string $leadId, private readonly string $phone) {}

                public function query(string $soql): array
                {
                    $this->queries[] = $soql;

                    if (! str_contains($soql, 'Id IN (')) {
                        return [];
                    }

                    return [[
                        'Id' => $this->leadId,
                        'CreatedDate' => '2026-05-10T10:00:00.000+0000',
                        'Phone' => $this->phone,
                        'MobilePhone' => null,
                        'Email' => null,
                        'Fuente_origen__c' => 'Coches.net',
                    ]];
                }
            };
            $target = $this->opportunityForPhone($leadPhone);

            $result = $this->service($client)->resolvePortalForRecord(
                $target,
                $this->service($client)->relatedLeadMatchesForOpportunities([$target]),
            );

            $this->assertSame('Coches.net', $result['portal']);
            $this->assertSame($salesforceId, $result['lead_id']);
            $this->assertTrue(collect($client->queries)->contains(
                fn (string $soql): bool => str_contains($soql, 'Id IN (') && str_contains($soql, $salesforceId),
            ));
        }
    }

    public function test_indice_local_solo_descubre_ids_y_la_validacion_final_usa_salesforce_vivo(): void
    {
        SalesforceLead::query()->create([
            'salesforce_id' => '00QLIVEVALIDATE1',
            'created_date' => '2026-05-10 10:00:00',
            'phone' => '623 45 67 89',
            'phone_normalized' => '623456789',
            'source_origin_new' => 'Fuente local obsoleta',
            'is_deleted' => false,
        ]);
        $client = new class extends SalesforceClient
        {
            public function __construct() {}

            public function query(string $soql): array
            {
                if (str_contains($soql, 'Id IN (')) {
                    return [[
                        'Id' => '00QLIVEVALIDATE1',
                        'CreatedDate' => '2026-05-10T10:00:00.000+0000',
                        'Phone' => '699999999',
                        'MobilePhone' => null,
                        'Email' => null,
                        'Fuente_origen__c' => 'Wallapop',
                    ]];
                }

                return [];
            }
        };
        $target = $this->opportunityForPhone('623456789', portal: 'Exposicion');
        $service = $this->service($client);
        $result = $service->resolvePortalForRecord($target, $service->relatedLeadMatchesForOpportunities([$target]));

        $this->assertSame('Exposición', $result['portal']);
        $this->assertSame('fallback_exposicion', $result['source']);
        $this->assertNull($result['lead_id']);
    }

    public function test_id_descubierto_localmente_que_salesforce_no_devuelve_no_es_candidato(): void
    {
        SalesforceLead::query()->create([
            'salesforce_id' => '00QMISSINGLIVE001',
            'created_date' => '2026-05-10 10:00:00',
            'phone_normalized' => '625678901',
            'is_deleted' => false,
        ]);
        $client = new class extends SalesforceClient
        {
            public array $queries = [];

            public function __construct() {}

            public function query(string $soql): array
            {
                $this->queries[] = $soql;

                return [];
            }
        };
        $target = $this->opportunityForPhone('625 67 89 01', portal: 'Exposicion');
        $service = $this->service($client);
        $result = $service->resolvePortalForRecord($target, $service->relatedLeadMatchesForOpportunities([$target]));

        $this->assertSame('fallback_exposicion', $result['source']);
        $this->assertNull($result['lead_id']);
        $this->assertCount(1, $client->queries);
        $this->assertStringContainsString("Id IN ('00QMISSINGLIVE001')", $client->queries[0]);
    }

    public function test_indice_descarta_borrados_y_usa_fuente_viva_del_lead_activo(): void
    {
        SalesforceLead::query()->create([
            'salesforce_id' => '00QDELETED000001',
            'created_date' => '2026-05-11 10:00:00',
            'phone_normalized' => '634567890',
            'is_deleted' => true,
        ]);
        SalesforceLead::query()->create([
            'salesforce_id' => '00QACTIVE0000001',
            'created_date' => '2026-05-10 10:00:00',
            'mobile_phone_normalized' => '634567890',
            'source_origin_new' => 'Fuente local obsoleta',
            'is_deleted' => false,
        ]);
        $client = new class extends SalesforceClient
        {
            public array $queries = [];

            public function __construct() {}

            public function query(string $soql): array
            {
                $this->queries[] = $soql;

                if (str_contains($soql, 'Id IN (')) {
                    return [[
                        'Id' => '00QACTIVE0000001',
                        'CreatedDate' => '2026-05-10T10:00:00.000+0000',
                        'Phone' => null,
                        'MobilePhone' => '+34 634 56 78 90',
                        'Email' => null,
                        'Fuente_origen__c' => 'Wallapop',
                    ]];
                }

                return [];
            }
        };
        $target = $this->opportunityForPhone('634-567-890');
        $service = $this->service($client);
        $isolated = $service->resolvePortalForRecord($target, $service->relatedLeadMatchesForOpportunities([$target]));
        $batched = $service->resolvePortalForRecord(
            $target,
            $service->relatedLeadMatchesForOpportunities([$this->opportunityForPhone('611111111'), $target]),
        );

        $this->assertSame($isolated, $batched);
        $this->assertSame('Wallapop', $isolated['portal']);
        $this->assertSame('00QACTIVE0000001', $isolated['lead_id']);
        $this->assertFalse(collect($client->queries)->contains(
            fn (string $soql): bool => str_contains($soql, '00QDELETED000001'),
        ));
    }

    public function test_reintenta_sin_email_de_empresa_cuando_salesforce_rechaza_el_campo_opcional(): void
    {
        $client = new class extends SalesforceClient
        {
            public int $queries = 0;

            public function __construct() {}

            public function queryAll(string $soql): array
            {
                return [];
            }

            public function query(string $soql): array
            {
                $this->queries++;

                if (str_contains($soql, 'Account.AC_C_EMA_email__c')) {
                    throw new \RuntimeException('Salesforce SOQL: error remoto HTTP 400.');
                }

                return [];
            }
        };

        $service = $this->service($client);
        $result = $service->sync(
            CarbonImmutable::parse('2026-07-01 00:00:00', 'UTC'),
            CarbonImmutable::parse('2026-07-02 00:00:00', 'UTC'),
        );

        $this->assertSame(2, $client->queries);
        $this->assertSame(0, $result['saved']);
        $this->assertStringNotContainsString('Account.AC_C_EMA_email__c', $result['soql']);
    }

    public function test_no_reintenta_errores_remotos_que_no_sean_un_rechazo_http_400(): void
    {
        $client = new class extends SalesforceClient
        {
            public int $queries = 0;

            public function __construct() {}

            public function queryAll(string $soql): array
            {
                return [];
            }

            public function query(string $soql): array
            {
                $this->queries++;

                throw new \RuntimeException('Salesforce SOQL: error remoto HTTP 503.');
            }
        };

        $service = $this->service($client);

        try {
            $service->sync(
                CarbonImmutable::parse('2026-07-01 00:00:00', 'UTC'),
                CarbonImmutable::parse('2026-07-02 00:00:00', 'UTC'),
            );
            $this->fail('Se esperaba propagar el error remoto.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Salesforce SOQL: error remoto HTTP 503.', $exception->getMessage());
        }

        $this->assertSame(1, $client->queries);
    }

    public function test_sincroniza_por_ids_con_query_acotada_y_mapeo_canonico_idempotente(): void
    {
        $client = new class extends SalesforceClient
        {
            public array $queries = [];

            public function __construct() {}

            public function query(string $soql): array
            {
                $this->queries[] = $soql;

                return [[
                    'Id' => '006-by-id',
                    'Name' => 'Recuperada por ID',
                    'CreatedDate' => '2026-03-01T10:00:00.000+0000',
                    'LastModifiedDate' => '2026-08-20T10:00:00.000+0000',
                    'StageName' => 'Cerrada Perdida',
                    'RecordType' => ['Name' => 'Venta'],
                    'OwnerId' => '005-owner',
                    'Owner' => ['Name' => 'Comercial', 'IsActive' => true, 'USR_SEL_Delegacion__c' => 'Alicante'],
                    'OPO_CAS_Reserva__c' => true,
                    'OPO_FEC_Fecha_de_reserva__c' => '2026-03-10',
                    'OPO_CAS_Contrato_CV_firmado__c' => false,
                    'Captador_de_cita__c' => '005Qx00000GTjweIAD',
                    'Captador_de_cita__r' => ['Name' => 'Maria Vidal Perez'],
                ]];
            }
        };
        $service = $this->service($client);

        $first = $service->syncBySalesforceIds(['006-by-id', '006-by-id']);
        $second = $service->syncBySalesforceIds(collect(['006-by-id']));

        $this->assertSame(1, $first['requested']);
        $this->assertSame(1, $first['saved']);
        $this->assertSame(1, $second['saved']);
        $this->assertDatabaseCount('salesforce_opportunities', 1);
        $this->assertDatabaseHas('salesforce_opportunities', [
            'salesforce_id' => '006-by-id',
            'reservation' => true,
            'reservation_date' => '2026-03-10 00:00:00',
            'owner_id' => '005-owner',
            'appointment_setter_id' => '005Qx00000GTjweIAD',
            'appointment_setter_name' => 'Maria Vidal Perez',
        ]);
        $this->assertStringContainsString("Id IN ('006-by-id')", $first['soql']);
        $this->assertStringNotContainsString('CreatedDate >=', $first['soql']);
    }

    public function test_resincronizacion_por_ids_actualiza_el_captador_de_cita(): void
    {
        $client = new class extends SalesforceClient
        {
            public array $record = [
                'Id' => '006-appointment-setter-update',
                'Name' => 'Oportunidad con cambio de captador',
                'CreatedDate' => '2026-03-01T10:00:00.000+0000',
                'LastModifiedDate' => '2026-03-01T10:00:00.000+0000',
                'StageName' => 'Reserva',
                'RecordType' => ['Name' => 'Venta'],
                'Account' => [],
                'Captador_de_cita__c' => '005Qx00000UserAIAA',
                'Captador_de_cita__r' => ['Name' => 'Usuario A'],
            ];

            public function __construct() {}

            public function query(string $soql): array
            {
                return [$this->record];
            }
        };
        $service = $this->service($client);

        $service->syncBySalesforceIds(['006-appointment-setter-update']);
        $client->record['Captador_de_cita__c'] = '005Qx00000UserBIAA';
        $client->record['Captador_de_cita__r'] = ['Name' => 'Usuario B'];
        $service->syncBySalesforceIds(['006-appointment-setter-update']);

        $this->assertDatabaseCount('salesforce_opportunities', 1);
        $this->assertDatabaseHas('salesforce_opportunities', [
            'salesforce_id' => '006-appointment-setter-update',
            'appointment_setter_id' => '005Qx00000UserBIAA',
            'appointment_setter_name' => 'Usuario B',
        ]);

        unset($client->record['Captador_de_cita__c'], $client->record['Captador_de_cita__r']);
        $service->syncBySalesforceIds(['006-appointment-setter-update']);

        $this->assertDatabaseHas('salesforce_opportunities', [
            'salesforce_id' => '006-appointment-setter-update',
            'appointment_setter_id' => null,
            'appointment_setter_name' => null,
        ]);
    }

    public function test_sincronizacion_por_ids_chunking_y_escapado(): void
    {
        $client = new class extends SalesforceClient
        {
            public array $queries = [];

            public function __construct() {}

            public function query(string $soql): array
            {
                $this->queries[] = $soql;

                return [];
            }
        };
        $ids = collect(range(1, 204))->map(fn (int $number): string => sprintf('006-%03d', $number));
        $ids->push("006-quote'escaped");

        $result = $this->service($client)
            ->syncBySalesforceIds($ids);

        $this->assertSame(205, $result['requested']);
        $this->assertCount(3, $client->queries);
        $this->assertStringContainsString("006-quote\\'escaped", end($client->queries));
        $this->assertSame(205, count($result['missing']));
    }

    private function opportunityForLeadRaw(string $email): array
    {
        return [
            'Portal__c' => '3CX',
            'Fuente_de_Origen__c' => null,
            'Account' => [
                'Phone' => null,
                'PersonEmail' => $email,
                'AC_C_EMA_email__c' => null,
            ],
        ];
    }

    private function opportunityForPhone(string $phone, string $portal = '3CX'): array
    {
        return [
            'Portal__c' => $portal,
            'Fuente_de_Origen__c' => null,
            'Account' => [
                'Phone' => $phone,
                'PersonEmail' => null,
                'AC_C_EMA_email__c' => null,
            ],
        ];
    }

    private function service(SalesforceClient $client): SalesforceOpportunitySyncService
    {
        return new SalesforceOpportunitySyncService(
            $client,
            app(OpportunityPortalNormalizer::class),
            app(SalesforceLeadFieldResolver::class),
        );
    }
}
