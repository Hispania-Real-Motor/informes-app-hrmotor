<?php

namespace Tests\Unit;

use App\Models\SalesforceOpportunity;
use App\Models\SalesforceSaleSnapshot;
use App\Models\SalesforceVehicle;
use App\Models\StockDailySnapshot;
use App\Models\StockDelegation;
use App\Services\Reports\Stock\SalesforceSaleSnapshotService;
use App\Services\Reports\Stock\SalesforceSignedSaleSyncService;
use App\Services\Reports\Stock\SalesforceVehicleSyncService;
use App\Services\Reports\Stock\StockCapacityImportService;
use App\Services\Reports\Stock\StockDailySnapshotService;
use App\Services\Reports\Stock\StockDelegationMutex;
use App\Services\Reports\Stock\StockDelegationService;
use App\Services\Salesforce\SalesforceClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockDataFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sincroniza_solo_los_tres_estados_de_stock_y_desactiva_los_que_salen(): void
    {
        $client = new class extends SalesforceClient
        {
            public array $records = [];

            public function __construct() {}

            public function query(string $soql): array
            {
                return $this->records;
            }
        };
        $client->records = [
            $this->vehicleRecord('01t-1', 'Disponible', 'HR MOTOR RIVAS-VACIA MADRID'),
            $this->vehicleRecord('01t-2', 'Reservado', 'HR MOTOR RIVAS-VACIA MADRID'),
            $this->vehicleRecord('01t-3', 'Bloqueado', 'HR MOTOR MANTENIMIENTO'),
        ];

        $service = new SalesforceVehicleSyncService(
            $client,
            app(StockDelegationService::class),
            app(StockDelegationMutex::class),
        );
        $result = $service->sync();

        $this->assertSame(3, $result['saved']);
        $this->assertStringContainsString(
            "PRO_SEL_Estado__c IN ('Disponible', 'Reservado', 'Bloqueado')",
            $result['soql'],
        );
        $this->assertSame(3, SalesforceVehicle::query()->where('is_in_stock', true)->count());
        $this->assertTrue(
            StockDelegation::query()->where('salesforce_name', 'HR MOTOR RIVAS-VACIA MADRID')->firstOrFail()->is_commercial
        );
        $this->assertFalse(
            StockDelegation::query()->where('salesforce_name', 'HR MOTOR MANTENIMIENTO')->firstOrFail()->is_commercial
        );

        $client->records = [$this->vehicleRecord('01t-1', 'Disponible', 'HR MOTOR RIVAS-VACIA MADRID')];
        $service->sync();

        $this->assertTrue(SalesforceVehicle::query()->where('salesforce_id', '01t-1')->firstOrFail()->is_in_stock);
        $this->assertFalse(SalesforceVehicle::query()->where('salesforce_id', '01t-2')->firstOrFail()->is_in_stock);
    }

    public function test_importa_plazas_totales_y_enlaza_la_delegacion_normalizada(): void
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'capacities-'.uniqid().'.csv';
        file_put_contents($path, "Tienda;Parking taller;Plazas totales\nRivas;9;120\n");

        try {
            $result = app(StockCapacityImportService::class)->import($path, ';');
        } finally {
            @unlink($path);
        }

        $this->assertSame(1, $result['imported']);
        $this->assertDatabaseHas('stock_delegations', [
            'canonical_name' => 'Rivas',
            'capacity_total' => 120,
            'is_commercial' => true,
        ]);
    }

    public function test_utiliza_precio_financiado_solo_cuando_product2_esta_marcado_solo_financiado(): void
    {
        $client = new class extends SalesforceClient
        {
            public array $records = [];

            public function __construct() {}

            public function query(string $soql): array
            {
                return $this->records;
            }
        };
        $financed = $this->vehicleRecord('01t-financed', 'Disponible', 'HR MOTOR RIVAS-VACIA MADRID');
        $financed['Solo_financiado__c'] = true;
        $financed['PRO_DIV_Precio_de_venta__c'] = 15000;
        $financed['PRO_DIV_Precio_venta_financiado__c'] = 14000;
        $normal = $this->vehicleRecord('01t-normal', 'Disponible', 'HR MOTOR RIVAS-VACIA MADRID');
        $normal['Solo_financiado__c'] = false;
        $normal['PRO_DIV_Precio_de_venta__c'] = 16000;
        $normal['PRO_DIV_Precio_venta_financiado__c'] = 14500;
        $client->records = [$financed, $normal];

        (new SalesforceVehicleSyncService(
            $client,
            app(StockDelegationService::class),
            app(StockDelegationMutex::class),
        ))->sync();

        $this->assertDatabaseHas('salesforce_vehicles', [
            'salesforce_id' => '01t-financed',
            'sale_price' => 14000,
            'normal_sale_price' => 15000,
            'financed_sale_price' => 14000,
            'only_financed' => true,
        ]);
        $this->assertDatabaseHas('salesforce_vehicles', [
            'salesforce_id' => '01t-normal',
            'sale_price' => 16000,
            'normal_sale_price' => 16000,
            'financed_sale_price' => 14500,
            'only_financed' => false,
        ]);
    }

    public function test_badajoz_existe_sin_stock_y_la_sincronizacion_reutiliza_su_registro(): void
    {
        $this->assertDatabaseHas('stock_delegations', [
            'canonical_name' => 'Badajoz',
            'normalized_key' => 'badajoz',
            'commercial_group' => 'Independientes',
            'zone' => 'Zona Sur y Centro',
            'is_commercial' => true,
        ]);

        $client = new class extends SalesforceClient
        {
            public function __construct() {}

            public function query(string $soql): array
            {
                return [[
                    'Id' => '01t-badajoz',
                    'PRO_SEL_Estado__c' => 'Disponible',
                    'PRO_BUS_Delegacion__c' => 'a0D-badajoz',
                    'PRO_BUS_Delegacion__r' => ['Name' => 'HR MOTOR BADAJOZ'],
                ]];
            }
        };

        (new SalesforceVehicleSyncService(
            $client,
            app(StockDelegationService::class),
            app(StockDelegationMutex::class),
        ))->sync();

        $this->assertSame(1, StockDelegation::query()->where('normalized_key', 'badajoz')->count());
        $this->assertDatabaseHas('stock_delegations', [
            'canonical_name' => 'Badajoz',
            'normalized_key' => 'badajoz',
            'salesforce_id' => 'a0D-badajoz',
            'salesforce_name' => 'HR MOTOR BADAJOZ',
        ]);
        $this->assertDatabaseHas('salesforce_vehicles', [
            'salesforce_id' => '01t-badajoz',
            'stock_delegation_id' => StockDelegation::query()->where('normalized_key', 'badajoz')->value('id'),
        ]);
    }

    public function test_fotografia_diaria_es_idempotente_y_calcula_tramos_y_antiguedad(): void
    {
        $vehicle = SalesforceVehicle::query()->create([
            'salesforce_id' => '01t-snapshot',
            'state' => 'Disponible',
            'sale_price' => 14999,
            'entry_date' => '2026-07-01',
            'is_in_stock' => true,
        ]);
        $service = app(StockDailySnapshotService::class);
        $date = CarbonImmutable::parse('2026-07-29');

        $service->capture($date);
        $vehicle->update(['sale_price' => 15100]);
        $service->capture($date);

        $this->assertSame(1, StockDailySnapshot::query()->count());
        $snapshot = StockDailySnapshot::query()->firstOrFail();
        $this->assertSame('15.000–20.000 €', $snapshot->price_band);
        $this->assertSame(28, $snapshot->days_in_stock);
        $this->assertSame('15100.00', $snapshot->sale_price);
    }

    public function test_fotografia_de_venta_no_cambia_tras_la_primera_captura(): void
    {
        $opportunity = SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-sale-1',
            'name' => 'Cambio firmado',
            'record_type_name' => 'Cambio',
            'cv_signed' => true,
            'cv_signed_date' => '2026-07-20',
            'delivery_store' => null,
            'vehicle_interest_id' => '01t-sold',
            'vehicle_entry_date' => '2026-06-01',
            'contract_vehicle_sale_amount' => 18000,
            'vehicle_purchase_price' => 14000,
            'appraised_vehicle_id' => '01t-trade',
            'trade_in_amount' => 5000,
            'management_cost' => 300,
            'logistics_cost' => 250,
            'transfer_cost' => 100,
            'garantia_total' => 450,
            'opo_div_descuento' => 200,
            'opo_for_importe_total' => 13300,
        ]);
        $service = app(SalesforceSaleSnapshotService::class);

        $service->capture($opportunity);
        $opportunity->update([
            'contract_vehicle_sale_amount' => 99999,
            'vehicle_purchase_price' => 1,
        ]);
        $service->capture($opportunity->fresh());

        $this->assertSame(1, SalesforceSaleSnapshot::query()->count());
        $snapshot = SalesforceSaleSnapshot::query()->firstOrFail();
        $this->assertSame('18000.00', $snapshot->sale_price);
        $this->assertSame('14000.00', $snapshot->purchase_price);
        $this->assertSame(49, $snapshot->rotation_days);
        $this->assertContains('missing_delivery_store', $snapshot->quality_issues);
    }

    public function test_sincronizador_especifico_de_ventas_no_reconstruye_leads_y_guarda_importes_contractuales(): void
    {
        $client = new class extends SalesforceClient
        {
            public string $soql = '';

            public function __construct() {}

            public function query(string $soql): array
            {
                $this->soql = $soql;

                return [[
                    'Id' => '006-fast-sale',
                    'Name' => 'Venta rápida',
                    'CreatedDate' => '2026-07-20T08:30:00.000Z',
                    'LastModifiedDate' => '2026-07-29T10:45:00.000Z',
                    'RecordType' => ['Name' => 'Venta'],
                    'OPO_CAS_Contrato_CV_firmado__c' => true,
                    'Fecha_firma_contrato__c' => '2026-07-28',
                    'Tienda_de_entrega__c' => 'HR MOTOR RIVAS-VACIA MADRID',
                    'OPP_BUS_Vehiculo_de_interes__c' => '01t-fast',
                    'OPP_BUS_Vehiculo_de_interes__r' => [
                        'PRO_TEX_Matricula__c' => '1234ABC',
                        'PRO_DIV_Precio_de_venta__c' => 19000,
                        'PRO_DIV_Precio_de_compra__c' => 14000,
                        'Plan_Auto_Plus__c' => 390,
                        'CAE__c' => 250,
                        'PRO_FEC_Fecha_entrada__c' => '2026-06-01',
                    ],
                    'OPO_FOR_Importe_vehiculo_venta__c' => 18500,
                    'OPO_FOR_Importe_total__c' => 18750,
                ]];
            }
        };
        SalesforceSaleSnapshot::query()->create([
            'opportunity_salesforce_id' => '006-fast-sale',
            'captured_at' => now(),
        ]);
        SalesforceOpportunity::query()->create([
            'salesforce_id' => '006-fast-sale',
            'is_deleted' => true,
            'salesforce_deleted_at' => '2026-07-28 10:00:00',
            'deletion_detection_source' => 'query_all_deleted',
        ]);
        $service = new SalesforceSignedSaleSyncService($client);
        $result = $service->sync(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-30'),
        );

        $this->assertSame(1, $result['saved']);
        $this->assertStringNotContainsString('FROM Lead', $client->soql);
        $this->assertStringContainsString('CreatedDate', $client->soql);
        $this->assertStringContainsString('LastModifiedDate', $client->soql);
        $this->assertDatabaseHas('salesforce_opportunities', [
            'salesforce_id' => '006-fast-sale',
            'created_date' => '2026-07-20 08:30:00',
            'salesforce_last_modified_at' => '2026-07-29 10:45:00',
            'cv_signed' => true,
            'contract_vehicle_sale_amount' => 18500,
            'vehicle_purchase_price' => 14000,
            'plan_auto_plus_amount' => 390,
            'cae_amount' => 250,
            'is_deleted' => true,
            'salesforce_deleted_at' => '2026-07-28 10:00:00',
            'deletion_detection_source' => 'query_all_deleted',
        ]);

        SalesforceOpportunity::withoutGlobalScope(SalesforceOpportunity::ACTIVE_SCOPE)->where('salesforce_id', '006-fast-sale')->update([
            'created_date' => null,
            'salesforce_last_modified_at' => null,
        ]);
        $service->sync(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-30'),
        );
        $this->assertDatabaseHas('salesforce_opportunities', [
            'salesforce_id' => '006-fast-sale',
            'created_date' => '2026-07-20 08:30:00',
            'salesforce_last_modified_at' => '2026-07-29 10:45:00',
        ]);

        $service->sync(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-07-30'),
        );
        $this->assertDatabaseHas('salesforce_opportunities', [
            'salesforce_id' => '006-fast-sale',
            'created_date' => '2026-07-20 08:30:00',
            'salesforce_last_modified_at' => '2026-07-29 10:45:00',
        ]);
        $this->assertDatabaseHas('salesforce_sale_snapshots', [
            'opportunity_salesforce_id' => '006-fast-sale',
            'plan_auto_plus_amount' => 390,
            'cae_amount' => 250,
        ]);
    }

    private function vehicleRecord(string $id, string $state, string $delegation): array
    {
        return [
            'Id' => $id,
            'Name' => 'Vehículo '.$id,
            'PRO_TEX_Matricula__c' => strtoupper(substr($id, -3)).'ABC',
            'PRO_SEL_Marca__c' => 'Peugeot',
            'PRO_TEX_Modelo__c' => '208',
            'PRO_TEX_Version__c' => 'Allure',
            'Segmento__c' => 'Utilitario',
            'PRO_SEL_Combustible__c' => 'Gasolina',
            'PRO_SEL_Carroceria__c' => 'Berlina',
            'PRO_NUM_Kilometraje__c' => 50000,
            'PRO_SEL_Estado__c' => $state,
            'PRO_BUS_Delegacion__c' => 'a0D-'.$delegation,
            'PRO_BUS_Delegacion__r' => ['Name' => $delegation],
            'PRO_DIV_Precio_de_compra__c' => 10000,
            'PRO_DIV_Precio_de_venta__c' => 14000,
            'PRO_FEC_Fecha_entrada__c' => '2026-07-01',
            'Comprador_oportunidad__c' => '005-buyer',
            'Comprador_oportunidad__r' => ['Name' => 'Comprador'],
            'Procedencia_de_compra__c' => 'Compra directa',
        ];
    }
}
