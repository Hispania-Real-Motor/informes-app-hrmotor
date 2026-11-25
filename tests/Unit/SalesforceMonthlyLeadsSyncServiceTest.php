<?php

namespace Tests\Unit;

use App\Models\SalesforceLead;
use App\Services\Reports\Leads\LeadPortalResolver;
use App\Services\Reports\Leads\LeadRecordTypeNormalizer;
use App\Services\Reports\MonthlyCommercial\Sync\SalesforceMonthlyLeadsSyncService;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesforceMonthlyLeadsSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_soql_y_sync_persisten_campos_nuevos_sin_reutilizar_columnas_legacy(): void
    {
        $record = [
            'Id' => '00Q-new-fields',
            'Name' => 'Lead synthetic',
            'CreatedDate' => '2026-05-10T10:00:00.000+0000',
            'LastModifiedDate' => '2026-05-10T11:00:00.000+0000',
            'Status' => 'Potencial',
            'RecordType' => ['Name' => 'Venta'],
            'Medio_Nuevo__c' => 'Llamada',
            'Portal_Text__c' => 'Legacy portal',
            'LEA_SEL_Fuente_Origen__c' => 'Legacy source',
            'LEA_SEL_Medio_Origen__c' => 'Legacy medium',
            'Fuente_origen__c' => 'New source',
            'Medio_origen__c' => 'New medium',
            'Canal__c' => 'WhatsApp',
            'Delegacion_procedencia__c' => 'Alcobendas',
            'Campa_a_Adquirida__c' => 'Legacy campaign',
            'Id_Adquirido__c' => 'legacy-id',
            'Fuente_Adquirida__c' => 'legacy-acquired-source',
            'Medio_Adquirido__c' => 'legacy-acquired-medium',
            'Contenido_Adquirido__c' => 'legacy-content',
            'utm_campaign__c' => 'new-campaign',
            'utm_id__c' => 'new-id',
            'utm_source__c' => 'new-acquired-source',
            'utm_medium__c' => 'new-acquired-medium',
            'utm_content__c' => 'new-content',
            'Phone' => '+34 612 34 56 78',
            'MobilePhone' => '613-45-67-89',
        ];
        $client = new class($record) extends SalesforceClient
        {
            public function __construct(private readonly array $record) {}

            public function queryPages(string $soql, bool $includeDeleted = false): \Generator
            {
                yield [$this->record];
            }
        };
        $service = new SalesforceMonthlyLeadsSyncService(
            $client,
            new LeadRecordTypeNormalizer,
            new LeadPortalResolver,
        );
        $start = CarbonImmutable::parse('2026-05-01');
        $end = CarbonImmutable::parse('2026-06-01');
        $soql = $service->soql($start, $end);

        foreach ([
            'Fuente_origen__c', 'Medio_origen__c', 'Canal__c', 'Delegacion_procedencia__c',
            'utm_campaign__c', 'utm_id__c', 'utm_source__c', 'utm_medium__c', 'utm_content__c',
            'Fuente_Adquirida__c', 'Medio_Adquirido__c',
        ] as $field) {
            $this->assertStringContainsString($field, $soql);
        }

        $first = $service->syncCampaignLeads($start, $end);
        $second = $service->syncCampaignLeads($start, $end);

        $this->assertSame(1, $first['inserted']);
        $this->assertSame(1, $second['unchanged']);
        $this->assertDatabaseCount('salesforce_leads', 1);
        $this->assertDatabaseHas('salesforce_leads', [
            'salesforce_id' => '00Q-new-fields',
            'fuente_origen' => 'Legacy source',
            'medio_origen' => 'Legacy medium',
            'source_origin_new' => 'New source',
            'medium_origin_new' => 'New medium',
            'channel_new' => 'WhatsApp',
            'delegation_origin_new' => 'Alcobendas',
            'acquired_source_legacy' => 'legacy-acquired-source',
            'acquired_medium_legacy' => 'legacy-acquired-medium',
            'utm_campaign_new' => 'new-campaign',
            'utm_id_new' => 'new-id',
            'utm_source_new' => 'new-acquired-source',
            'utm_medium_new' => 'new-acquired-medium',
            'utm_content_new' => 'new-content',
            'phone' => '+34 612 34 56 78',
            'phone_normalized' => '612345678',
            'mobile_phone' => '613-45-67-89',
            'mobile_phone_normalized' => '613456789',
        ]);
        $trace = SalesforceLead::query()->firstOrFail()->field_resolution;
        $this->assertSame('New source', data_get($trace, 'source.effective_value'));
        $this->assertSame('Fuente_origen__c', data_get($trace, 'source.source_field'));
        $this->assertTrue(data_get($trace, 'source.conflict'));
        $this->assertSame('WhatsApp', data_get($trace, 'channel.effective_value'));
        $this->assertSame('Canal__c', data_get($trace, 'channel.source_field'));
        $this->assertSame('New medium', data_get($trace, 'medium.effective_value'));
        $this->assertSame('Medio_origen__c', data_get($trace, 'medium.source_field'));
        $this->assertSame('Alcobendas', data_get($trace, 'delegation.effective_value'));
        $this->assertSame('Delegacion_procedencia__c', data_get($trace, 'delegation.source_field'));
        $this->assertDatabaseHas('salesforce_leads', [
            'salesforce_id' => '00Q-new-fields',
            'resolved_portal' => 'New source',
            'portal_resolution_source' => 'Fuente_origen__c',
            'resolved_channel' => 'WhatsApp',
        ]);
        $this->assertSame('new-campaign', data_get($trace, 'utm_campaign.effective_value'));
        $this->assertSame('utm_campaign__c', data_get($trace, 'utm_campaign.source_field'));
    }

    public function test_query_reducida_no_borra_campos_nuevos_previamente_persistidos(): void
    {
        SalesforceLead::query()->create([
            'salesforce_id' => '00Q-optional-fallback',
            'created_date' => '2026-05-10 10:00:00',
            'source_origin_new' => 'Preserved source',
            'utm_campaign_new' => 'Preserved campaign',
            'phone' => '614 56 78 90',
            'phone_normalized' => '614567890',
            'mobile_phone' => '+34 615 67 89 01',
            'mobile_phone_normalized' => '615678901',
            'field_resolution' => ['source' => ['effective_value' => 'Preserved source']],
        ]);
        $client = new class extends SalesforceClient
        {
            public function __construct() {}

            public function queryPages(string $soql, bool $includeDeleted = false): \Generator
            {
                if (str_contains($soql, "\n    Fuente_origen__c,")) {
                    throw new \RuntimeException('INVALID_FIELD synthetic optional field');
                }

                yield [[
                    'Id' => '00Q-optional-fallback',
                    'Name' => 'Updated through reduced query',
                    'CreatedDate' => '2026-05-10T10:00:00.000+0000',
                    'LastModifiedDate' => '2026-05-11T10:00:00.000+0000',
                    'Status' => 'Potencial',
                    'RecordType' => ['Name' => 'Venta'],
                    'LEA_SEL_Fuente_Origen__c' => 'Legacy source',
                    'LEA_SEL_Medio_Origen__c' => 'Legacy medium',
                    'Portal_Text__c' => 'Legacy portal',
                ]];
            }
        };
        $service = new SalesforceMonthlyLeadsSyncService(
            $client,
            new LeadRecordTypeNormalizer,
            new LeadPortalResolver,
        );

        $result = $service->syncCampaignLeads(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01'),
        );

        $this->assertCount(1, $result['warnings']);
        $this->assertFalse($result['appointment_fields_available']);
        $this->assertDatabaseHas('salesforce_leads', [
            'salesforce_id' => '00Q-optional-fallback',
            'name' => 'Updated through reduced query',
            'source_origin_new' => 'Preserved source',
            'utm_campaign_new' => 'Preserved campaign',
            'phone' => '614 56 78 90',
            'phone_normalized' => '614567890',
            'mobile_phone' => '+34 615 67 89 01',
            'mobile_phone_normalized' => '615678901',
        ]);
        $this->assertSame(
            'Preserved source',
            data_get(SalesforceLead::query()->firstOrFail()->field_resolution, 'source.effective_value'),
        );
    }

    public function test_cambio_y_null_de_telefonos_actualizan_las_claves_derivadas(): void
    {
        $client = new class extends SalesforceClient
        {
            public array $record = [
                'Id' => '00Q-phone-change',
                'Name' => 'Lead synthetic',
                'CreatedDate' => '2026-05-10T10:00:00.000+0000',
                'LastModifiedDate' => '2026-05-10T11:00:00.000+0000',
                'Status' => 'Potencial',
                'RecordType' => ['Name' => 'Venta'],
                'Phone' => '616 78 90 12',
                'MobilePhone' => null,
            ];

            public function __construct() {}

            public function queryPages(string $soql, bool $includeDeleted = false): \Generator
            {
                yield [$this->record];
            }
        };
        $service = new SalesforceMonthlyLeadsSyncService(
            $client,
            new LeadRecordTypeNormalizer,
            new LeadPortalResolver,
        );
        $start = CarbonImmutable::parse('2026-05-01');
        $end = CarbonImmutable::parse('2026-06-01');

        $service->syncCampaignLeads($start, $end);
        $this->assertDatabaseHas('salesforce_leads', [
            'salesforce_id' => '00Q-phone-change',
            'phone_normalized' => '616789012',
            'mobile_phone_normalized' => null,
        ]);

        $client->record['Phone'] = null;
        $client->record['MobilePhone'] = '+34 617-89-01-23';
        $service->syncCampaignLeads($start, $end);

        $this->assertDatabaseHas('salesforce_leads', [
            'salesforce_id' => '00Q-phone-change',
            'phone_normalized' => null,
            'mobile_phone_normalized' => '617890123',
        ]);
    }

    public function test_guarda_leads_de_salesforce_con_relaciones_anidadas(): void
    {
        $client = new class extends SalesforceClient
        {
            public string $lastSoql = '';

            public function __construct() {}

            public function query(string $soql): array
            {
                $this->lastSoql = $soql;

                return [
                    [
                        'Id' => '00Q1',
                        'Name' => 'Lead Uno',
                        'CreatedDate' => '2026-05-01T10:00:00.000+0000',
                        'LastModifiedDate' => '2026-05-12T10:00:00.000+0000',
                        'LastActivityDate' => '2026-05-02',
                        'Status' => 'Convertido',
                        'RecordType' => ['Name' => 'Tasacion'],
                        'OwnerId' => '005-owner-1',
                        'Owner' => ['Name' => 'Owner Uno'],
                        'Captador_de_cita__c' => '005-setter-1',
                        'Captador_de_cita__r' => ['Name' => 'Maria Vidal'],
                        'Persona_que_trabaj__c' => '005-worker-1',
                        'Persona_que_trabaj__r' => ['Name' => 'Worker Uno'],
                        'Propietario_cuando_se_descarto__c' => null,
                        'Propietario_cuando_se_descarto__r' => null,
                        'Fecha_Asignacion__c' => '2026-05-01T10:05:00.000+0000',
                        'Fecha_captador__c' => '2026-05-01',
                        'Cita_llamada__c' => true,
                        'Cita_Tienda__c' => false,
                        'Acudi_a_la_cita__c' => 'ACUDIO',
                        'Comercial_que_atiende_en_tienda__c' => '005-store-1',
                        'Comercial_que_atiende_en_tienda__r' => ['Name' => 'Comercial Tienda 1'],
                        'Estado_del_candidato_formula__c' => 'Citado',
                        'LEA_SEL_Fuente_Origen__c' => 'Fuente',
                        'LEA_SEL_Medio_Origen__c' => 'Medio',
                        'Portal_Text__c' => 'Web',
                        'Delegacion_Encargada_Text__c' => 'Madrid',
                    ],
                    [
                        'Id' => '00Q2',
                        'Name' => 'Lead Dos',
                        'CreatedDate' => '2026-05-03T11:00:00.000+0000',
                        'LastModifiedDate' => '2026-05-12T11:00:00.000+0000',
                        'LastActivityDate' => null,
                        'Status' => 'Potencial',
                        'RecordType' => ['Name' => 'Venta con cambio'],
                        'OwnerId' => '005-owner-2',
                        'Owner' => ['Name' => 'Owner Dos'],
                        'Persona_que_trabaj__c' => null,
                        'Persona_que_trabaj__r' => null,
                        'Propietario_cuando_se_descarto__c' => null,
                        'Propietario_cuando_se_descarto__r' => null,
                        'Fecha_Asignacion__c' => null,
                        'LEA_SEL_Fuente_Origen__c' => null,
                        'LEA_SEL_Medio_Origen__c' => null,
                        'Portal_Text__c' => 'Meta',
                        'Delegacion_Encargada_Text__c' => null,
                    ],
                ];
            }

            public function queryAll(string $soql): array
            {
                return [];
            }

            public function queryPages(string $soql, bool $includeDeleted = false): \Generator
            {
                yield $this->query($soql);
            }
        };

        $service = new SalesforceMonthlyLeadsSyncService(
            $client,
            new LeadRecordTypeNormalizer,
            new LeadPortalResolver,
        );
        $result = $service->sync(
            CarbonImmutable::parse('2026-03-14 13:37:27', 'UTC'),
            CarbonImmutable::parse('2026-05-13 13:37:27', 'UTC'),
        );

        $this->assertSame(2, $result['queried']);
        $this->assertTrue($result['appointment_fields_available']);
        $this->assertSame(2, $result['saved']);
        $this->assertStringContainsString('RecordType.Name', $result['soql']);
        $this->assertStringContainsString('Captador_de_cita__c', $result['soql']);
        $this->assertStringContainsString('CreatedDate >= 2026-03-14T13:37:27Z', $result['soql']);
        $this->assertStringContainsString('CreatedDate < 2026-05-13T13:37:27Z', $result['soql']);
        $this->assertStringContainsString('Fecha_captador__c >= 2026-03-14', $result['soql']);
        $this->assertStringContainsString('Fecha_captador__c < 2026-05-13', $result['soql']);
        $this->assertStringContainsString('LastModifiedDate >= 2026-03-14T13:37:27Z', $result['soql']);

        $this->assertSame(2, SalesforceLead::query()->count());
        $this->assertDatabaseHas('salesforce_leads', [
            'salesforce_id' => '00Q1',
            'owner_name' => 'Owner Uno',
            'record_type_name' => 'Tasacion',
            'record_type_normalized' => 'tasacion',
            'appointment_setter_name' => 'Maria Vidal',
            'appointment_call' => true,
            'appointment_attended_status' => 'ACUDIO',
            'medio_origen' => 'Medio',
            'persona_que_trabajo_name' => 'Worker Uno',
            'portal_text' => 'Web',
            'resolved_portal' => 'Web',
            'portal_resolution_source' => 'Portal_Text__c',
            'is_deleted' => false,
        ]);
    }

    public function test_actualiza_leads_antiguos_modificados_y_marca_eliminados(): void
    {
        SalesforceLead::create([
            'salesforce_id' => '00Q-OLD',
            'name' => 'Lead antiguo antes del cambio',
            'created_date' => '2025-01-01 10:00:00',
            'status' => 'Potencial',
            'record_type_name' => 'Venta',
            'record_type_normalized' => 'venta',
            'portal_text' => 'Web',
            'resolved_portal' => 'Web',
            'synced_at' => '2026-05-10 09:00:00',
            'is_deleted' => false,
        ]);

        SalesforceLead::create([
            'salesforce_id' => '00Q-HARD-DELETED',
            'name' => 'Hard deleted',
            'created_date' => '2026-05-12 09:00:00',
            'status' => 'Potencial',
            'record_type_name' => 'Venta',
            'synced_at' => '2026-05-10 09:00:00',
            'is_deleted' => false,
        ]);

        $client = new class extends SalesforceClient
        {
            public function __construct() {}

            public function query(string $soql): array
            {
                return [[
                    'Id' => '00Q-OLD',
                    'Name' => 'Lead antiguo modificado',
                    'CreatedDate' => '2025-01-01T10:00:00.000+0000',
                    'LastModifiedDate' => '2026-05-12T10:00:00.000+0000',
                    'Status' => 'Convertido',
                    'RecordType' => ['Name' => 'Tasación'],
                    'Portal_Text__c' => 'Coches.net',
                ]];
            }

            public function queryAll(string $soql): array
            {
                return [[
                    'Id' => '00Q-DELETED',
                    'Name' => 'Eliminado',
                    'CreatedDate' => '2026-01-01T10:00:00.000+0000',
                    'IsDeleted' => true,
                    'MasterRecordId' => '00Q-MASTER',
                    'LastModifiedDate' => '2026-05-12T11:00:00.000+0000',
                    'Status' => 'Potencial',
                    'RecordType' => ['Name' => 'Venta'],
                    'Medio_Nuevo__c' => 'Llamada',
                    'Fuente_Nuevo__c' => 'Coches.net Coche Nuevo',
                    'Portal_Text__c' => 'Coches.net',
                ]];
            }

            public function queryPages(string $soql, bool $includeDeleted = false): \Generator
            {
                yield $this->query($soql);
            }
        };

        $service = new SalesforceMonthlyLeadsSyncService(
            $client,
            new LeadRecordTypeNormalizer,
            new LeadPortalResolver,
        );
        $result = $service->sync(
            CarbonImmutable::parse('2026-05-11 00:00:00', 'UTC'),
            CarbonImmutable::parse('2026-05-13 00:00:00', 'UTC'),
        );

        $this->assertSame(2, $result['deleted']);
        $this->assertSame(1, $result['deleted_query_all']);
        $this->assertSame(1, $result['deleted_missing']);
        $this->assertDatabaseHas('salesforce_leads', [
            'salesforce_id' => '00Q-OLD',
            'status' => 'Convertido',
            'record_type_normalized' => 'tasacion',
            'resolved_portal' => 'Coches.net',
        ]);
        $this->assertDatabaseHas('salesforce_leads', [
            'salesforce_id' => '00Q-DELETED',
            'is_deleted' => true,
            'resolved_portal' => 'Coches.net Coche Nuevo',
            'deletion_detection_source' => 'query_all',
            'salesforce_master_record_id' => '00Q-MASTER',
        ]);
        $this->assertDatabaseHas('salesforce_leads', [
            'salesforce_id' => '00Q-HARD-DELETED',
            'is_deleted' => true,
            'deletion_detection_source' => 'missing_from_salesforce',
        ]);
    }

    public function test_no_reescribe_lead_identico_y_actualiza_un_cambio_real(): void
    {
        $client = new class extends SalesforceClient
        {
            public array $record = [
                'Id' => '00Q-stable',
                'Name' => 'Lead estable',
                'CreatedDate' => '2026-05-01T10:00:00.000+0000',
                'LastModifiedDate' => '2026-05-01T11:00:00.000+0000',
                'Status' => 'Potencial',
                'RecordType' => ['Name' => 'Venta'],
                'OwnerId' => '005-owner',
                'Owner' => ['Name' => 'Owner'],
                'Portal_Text__c' => 'Web',
            ];

            public function __construct() {}

            public function queryAll(string $soql): array
            {
                return [];
            }

            public function queryPages(string $soql, bool $includeDeleted = false): \Generator
            {
                yield [$this->record];
            }
        };

        $service = new SalesforceMonthlyLeadsSyncService(
            $client,
            new LeadRecordTypeNormalizer,
            new LeadPortalResolver,
        );
        $start = CarbonImmutable::parse('2026-05-01', 'UTC');
        $end = CarbonImmutable::parse('2026-05-03', 'UTC');

        CarbonImmutable::setTestNow('2026-05-03 10:00:00');
        $first = $service->sync($start, $end);
        $lead = SalesforceLead::where('salesforce_id', '00Q-stable')->firstOrFail();
        $originalUpdatedAt = $lead->updated_at;
        $originalSyncedAt = $lead->synced_at;

        CarbonImmutable::setTestNow('2026-05-03 10:15:00');
        $second = $service->sync($start, $end);
        $lead->refresh();

        $this->assertSame(1, $first['inserted']);
        $this->assertSame(1, $first['active_inserted']);
        $this->assertSame(1, $first['persisted_inserted']);
        $this->assertSame(1, $second['unchanged']);
        $this->assertSame(1, $second['active_unchanged']);
        $this->assertSame(1, $second['persisted_unchanged']);
        $this->assertSame(0, $second['updated']);
        $this->assertTrue($lead->updated_at->equalTo($originalUpdatedAt));
        $this->assertTrue($lead->synced_at->equalTo($originalSyncedAt));

        $client->record['Name'] = 'Lead modificado';
        $client->record['LastModifiedDate'] = '2026-05-03T10:20:00.000+0000';
        CarbonImmutable::setTestNow('2026-05-03 10:30:00');
        $third = $service->sync($start, $end);
        $lead->refresh();

        $this->assertSame(1, $third['updated']);
        $this->assertSame('Lead modificado', $lead->name);
        $this->assertFalse($lead->updated_at->equalTo($originalUpdatedAt));
        $this->assertFalse($lead->synced_at->equalTo($originalSyncedAt));
    }

    public function test_no_reescribe_deleted_merged_identico_y_actualiza_transicion_desde_activo(): void
    {
        $active = [
            'Id' => '00Q-deleted',
            'Name' => 'Lead a eliminar',
            'CreatedDate' => '2026-05-01T10:00:00.000+0000',
            'LastModifiedDate' => '2026-05-01T11:00:00.000+0000',
            'Status' => 'Potencial',
            'RecordType' => ['Name' => 'Venta'],
            'Portal_Text__c' => 'Web',
        ];
        $client = new class($active) extends SalesforceClient
        {
            public array $records;

            public array $deleted = [];

            public function __construct(array $active)
            {
                $this->records = [$active];
            }

            public function queryAll(string $soql): array
            {
                return $this->deleted;
            }

            public function queryPages(string $soql, bool $includeDeleted = false): \Generator
            {
                yield $this->records;
            }
        };

        $service = new SalesforceMonthlyLeadsSyncService(
            $client,
            new LeadRecordTypeNormalizer,
            new LeadPortalResolver,
        );
        $start = CarbonImmutable::parse('2026-05-01', 'UTC');
        $end = CarbonImmutable::parse('2026-05-03', 'UTC');

        CarbonImmutable::setTestNow('2026-05-03 10:00:00');
        $service->sync($start, $end);

        $client->records = [];
        $client->deleted = [[
            ...$active,
            'IsDeleted' => true,
            'MasterRecordId' => '00Q-master',
            'LastModifiedDate' => '2026-05-03T10:05:00.000+0000',
        ]];
        CarbonImmutable::setTestNow('2026-05-03 10:15:00');
        $transition = $service->sync($start, $end);
        $lead = SalesforceLead::where('salesforce_id', '00Q-deleted')->firstOrFail();
        $deletedUpdatedAt = $lead->updated_at;
        $deletedSyncedAt = $lead->synced_at;

        $this->assertSame(1, $transition['deleted_merged_changed']);
        $this->assertSame(0, $transition['active_updated']);
        $this->assertSame(1, $transition['persisted_updated']);
        $this->assertTrue($lead->is_deleted);
        $this->assertSame('00Q-master', $lead->salesforce_master_record_id);

        CarbonImmutable::setTestNow('2026-05-03 10:30:00');
        $repeated = $service->sync($start, $end);
        $lead->refresh();

        $this->assertSame(1, $repeated['deleted_merged_unchanged']);
        $this->assertSame(0, $repeated['deleted_merged_changed']);
        $this->assertTrue($lead->updated_at->equalTo($deletedUpdatedAt));
        $this->assertTrue($lead->synced_at->equalTo($deletedSyncedAt));
    }
}
