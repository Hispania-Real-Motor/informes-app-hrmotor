<?php

namespace Tests\Unit;

use App\Models\CommercialCommissionAppointmentEvidence;
use App\Models\CommercialCommissionDeliveryReconciliation;
use App\Models\CommercialCommissionProductDeliveryEvidence;
use App\Models\SalesforceOpportunity;
use App\Services\Reports\ContactCenterCommissions\CommercialCommissionDeliveryReconciliationService;
use App\Services\Reports\ContactCenterCommissions\Sync\CommercialCommissionAppointmentEvidenceSyncService;
use App\Services\Reports\ContactCenterCommissions\Sync\CommercialCommissionProductDeliveryEvidenceSyncService;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommercialCommissionEvidenceSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_modelos_evidence_usan_las_tablas_plurales_reales(): void
    {
        CommercialCommissionAppointmentEvidence::query()->create([
            'salesforce_interest_id' => 'a0I000000000000099',
            'synced_at' => now(),
        ]);
        CommercialCommissionProductDeliveryEvidence::query()->create([
            'salesforce_product_id' => '01t000000000000099',
            'synced_at' => now(),
        ]);

        $this->assertDatabaseHas('commercial_commission_appointment_evidences', [
            'salesforce_interest_id' => 'a0I000000000000099',
        ]);
        $this->assertDatabaseHas('commercial_commission_product_delivery_evidences', [
            'salesforce_product_id' => '01t000000000000099',
        ]);
    }

    public function test_sync_citas_acotado_materializa_id_fecha_null_y_actualiza_sin_duplicar(): void
    {
        $client = new class extends SalesforceClient
        {
            public array $record = [
                'Id' => 'a0I000000000000001',
                'IN_Captador_de_Cita__c' => '005Qx00000GTjweIAD',
                'IN_Captador_de_Cita__r' => ['Name' => 'Maria Vidal Perez'],
                'IN_Fecha_Captador_de_Cita__c' => '2026-09-10',
                'LastModifiedDate' => '2026-09-10T10:00:00.000Z',
            ];

            public function __construct() {}

            public function queryPages(string $soql, bool $includeDeleted = false): Generator
            {
                yield [$this->record];
            }
        };
        $service = new CommercialCommissionAppointmentEvidenceSyncService($client);
        $start = CarbonImmutable::parse('2026-09-01');
        $end = CarbonImmutable::parse('2026-10-01');

        $first = $service->sync($start, $end);
        $client->record['IN_Captador_de_Cita__c'] = '005Qx00000Hf7gYIAR';
        $client->record['IN_Captador_de_Cita__r'] = ['Name' => 'Estefany Taborda'];
        $service->sync($start, $end);

        $this->assertStringContainsString('FROM Interes__c', $first['soql']);
        $this->assertStringContainsString('IN_Captador_de_Cita__r.Name', $first['soql']);
        $this->assertStringNotContainsString('Lead', $first['soql']);
        $this->assertDatabaseCount('commercial_commission_appointment_evidences', 1);
        $this->assertDatabaseHas('commercial_commission_appointment_evidences', [
            'salesforce_interest_id' => 'a0I000000000000001',
            'appointment_setter_id' => '005Qx00000Hf7gYIAR',
            'appointment_setter_name' => 'Estefany Taborda',
            'appointment_capture_date' => '2026-09-10',
        ]);

        unset($client->record['IN_Captador_de_Cita__c'], $client->record['IN_Captador_de_Cita__r']);
        $service->sync($start, $end);
        $this->assertDatabaseHas('commercial_commission_appointment_evidences', [
            'salesforce_interest_id' => 'a0I000000000000001',
            'appointment_setter_id' => null,
            'appointment_setter_name' => null,
        ]);
    }

    public function test_sync_product2_no_modifica_stock_y_cubre_productos_de_opportunities_firmadas(): void
    {
        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006000000000000001',
            'cv_signed' => true,
            'cv_signed_date' => '2026-09-10',
            'vehicle_interest_id' => '01t000000000000001',
        ]);
        $client = new class extends SalesforceClient
        {
            public function __construct() {}

            public function queryPages(string $soql, bool $includeDeleted = false): Generator
            {
                if (str_contains($soql, 'Id IN')) {
                    yield [[
                        'Id' => '01t000000000000001', 'PRO_SEL_Estado__c' => 'Entregado',
                        'Fecha_entregado__c' => '2026-09-10', 'PRO_TEX_Matricula__c' => '1234ABC',
                        'LastModifiedDate' => '2026-09-10T10:00:00.000Z',
                    ]];

                    return;
                }
                yield [[
                    'Id' => '01t000000000000002', 'PRO_SEL_Estado__c' => 'Entregado',
                    'Fecha_entregado__c' => '2026-09-15', 'PRO_TEX_Matricula__c' => '5678DEF',
                    'LastModifiedDate' => '2026-09-15T10:00:00.000Z',
                ]];
            }
        };
        $result = (new CommercialCommissionProductDeliveryEvidenceSyncService($client))->sync(
            CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-10-01'),
        );

        $this->assertStringContainsString('Fecha_entregado__c >= 2026-09-01', $result['soql']);
        $this->assertSame(1, $result['opportunity_product_queries']);
        $this->assertDatabaseCount('commercial_commission_product_delivery_evidences', 2);
        $this->assertDatabaseMissing('salesforce_vehicles', ['salesforce_id' => '01t000000000000001']);
    }

    public function test_reconciliacion_persiste_las_cinco_clasificaciones_sin_corregir_fuentes(): void
    {
        foreach ([
            ['006-match', '01t-match'], ['006-date', '01t-date'], ['006-state', '01t-state'], ['006-only', null],
        ] as [$opportunityId, $productId]) {
            SalesforceOpportunity::query()->create([
                'salesforce_id' => $opportunityId, 'cv_signed' => true,
                'cv_signed_date' => '2026-09-10', 'vehicle_interest_id' => $productId,
            ]);
        }
        foreach ([
            ['01t-match', 'Entregado', '2026-09-10'], ['01t-date', 'Entregado', '2026-09-11'],
            ['01t-state', 'Reservado', '2026-09-10'], ['01t-product-only', 'Entregado', '2026-09-12'],
        ] as [$id, $state, $date]) {
            CommercialCommissionProductDeliveryEvidence::query()->create([
                'salesforce_product_id' => $id, 'state' => $state, 'delivery_date' => $date,
                'synced_at' => now(),
            ]);
        }

        $result = app(CommercialCommissionDeliveryReconciliationService::class)->reconcile(
            CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-10-01'),
        );

        $this->assertSame(5, $result['rows']);
        foreach (['MATCH', 'OPPORTUNITY_ONLY', 'PRODUCT2_ONLY', 'DATE_MISMATCH', 'STATE_MISMATCH'] as $type) {
            $this->assertDatabaseHas('commercial_commission_delivery_reconciliations', ['discrepancy_type' => $type]);
        }
        $this->assertSame(5, CommercialCommissionDeliveryReconciliation::query()->count());
    }

    public function test_reconciliacion_prioriza_conflicto_cuando_product2_tiene_dos_opportunities(): void
    {
        foreach (['006-duplicate-a', '006-duplicate-b'] as $id) {
            SalesforceOpportunity::query()->create([
                'salesforce_id' => $id, 'cv_signed' => true,
                'cv_signed_date' => '2026-09-10', 'vehicle_interest_id' => '01t-duplicate',
            ]);
        }
        CommercialCommissionProductDeliveryEvidence::query()->create([
            'salesforce_product_id' => '01t-duplicate', 'state' => 'Entregado',
            'delivery_date' => '2026-09-10', 'plate' => '1234ABC', 'synced_at' => now(),
        ]);

        app(CommercialCommissionDeliveryReconciliationService::class)->reconcile(
            CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-10-01'),
        );

        $this->assertDatabaseCount('commercial_commission_delivery_reconciliations', 2);
        foreach (['006-duplicate-a', '006-duplicate-b'] as $id) {
            $this->assertDatabaseHas('commercial_commission_delivery_reconciliations', [
                'opportunity_salesforce_id' => $id,
                'product_salesforce_id' => '01t-duplicate',
                'discrepancy_type' => 'DUPLICATE_PRODUCT2_LINK',
            ]);
        }
        $this->assertDatabaseMissing('commercial_commission_delivery_reconciliations', ['discrepancy_type' => 'MATCH']);
    }

    public function test_sync_citas_elimina_evidencia_obsoleta_solo_despues_de_lectura_completa(): void
    {
        $client = new class extends SalesforceClient
        {
            public array $records = [[
                'Id' => 'a0I-stale', 'IN_Fecha_Captador_de_Cita__c' => '2026-09-10',
                'LastModifiedDate' => '2026-09-10T10:00:00.000Z',
            ]];

            public function __construct() {}

            public function queryPages(string $soql, bool $includeDeleted = false): Generator
            {
                yield $this->records;
            }
        };
        $service = new CommercialCommissionAppointmentEvidenceSyncService($client);
        $start = CarbonImmutable::parse('2026-09-01');
        $end = CarbonImmutable::parse('2026-10-01');
        $service->sync($start, $end);
        $client->records = [];
        $service->sync($start, $end);

        $this->assertDatabaseMissing('commercial_commission_appointment_evidences', ['salesforce_interest_id' => 'a0I-stale']);
    }
}
