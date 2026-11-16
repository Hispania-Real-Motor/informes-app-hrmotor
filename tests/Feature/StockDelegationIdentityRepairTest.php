<?php

namespace Tests\Feature;

use App\Models\SalesforceSaleSnapshot;
use App\Models\SalesforceVehicle;
use App\Models\StockAvailabilityAlert;
use App\Models\StockDailySnapshot;
use App\Models\StockDelegation;
use App\Services\Reports\Leads\LeadDelegationNormalizer;
use App\Services\Reports\Stock\SalesforceVehicleSyncService;
use App\Services\Reports\Stock\StockCapacityImportService;
use App\Services\Reports\Stock\StockDelegationConsolidationService;
use App\Services\Reports\Stock\StockDelegationService;
use App\Services\Salesforce\SalesforceClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class StockDelegationIdentityRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_salesforce_aliases_are_idempotent_and_keep_santa_barbara_independent(): void
    {
        $rivas = $this->delegation('Rivas', 'rivas', 320, 'Grupo Madrid', 'Zona Sur y Centro');
        $villarreal = $this->delegation(
            'Villarreal/Almassora',
            'villarreal almassora',
            180,
            'Grupo Castellón',
            'Zona Mediterraneo',
        );
        $client = new class extends SalesforceClient
        {
            public function __construct() {}

            public function query(string $soql): array
            {
                return [
                    $this->record('vehicle-rivas', 'a007R000010UF74QAG', 'HR Motor Rivas-Vaciamadrid'),
                    $this->record('vehicle-villarreal', 'a00Qx00000ZB5rRIAT', 'HR Motor Villarreal'),
                    $this->record('vehicle-santa-barbara', 'a00Qx00000o8EQqIAM', 'HR Motor Málaga Santa Bárbara'),
                ];
            }

            private function record(string $id, string $delegationId, string $delegationName): array
            {
                return [
                    'Id' => $id,
                    'PRO_SEL_Estado__c' => 'Disponible',
                    'PRO_BUS_Delegacion__c' => $delegationId,
                    'PRO_BUS_Delegacion__r' => ['Name' => $delegationName],
                ];
            }
        };

        $service = new SalesforceVehicleSyncService($client, app(StockDelegationService::class));
        $service->sync();
        $service->sync();

        $this->assertSame(1, StockDelegation::query()->operational()->where('normalized_key', 'rivas')->count());
        $this->assertSame(1, StockDelegation::query()->operational()->where('normalized_key', 'villarreal almassora')->count());
        $this->assertSame(1, StockDelegation::query()->operational()->where('normalized_key', 'hr motor malaga santa barbara')->count());
        $this->assertSame(3, SalesforceVehicle::query()->count());
        $this->assertSame($rivas->id, SalesforceVehicle::query()->where('salesforce_id', 'vehicle-rivas')->value('stock_delegation_id'));
        $this->assertSame($villarreal->id, SalesforceVehicle::query()->where('salesforce_id', 'vehicle-villarreal')->value('stock_delegation_id'));
        $this->assertDatabaseHas('stock_delegations', [
            'salesforce_id' => 'a00Qx00000o8EQqIAM',
            'canonical_name' => 'HR Motor Málaga Santa Bárbara',
            'capacity_total' => null,
            'commercial_group' => null,
            'zone' => null,
        ]);
    }

    public function test_repair_consolidates_rivas_without_deleting_history_and_is_idempotent(): void
    {
        $stockIdentity = $this->delegation('HR Motor Rivas-Vaciamadrid', 'hr motor rivas vaciamadrid', 320);
        $stockIdentity->update([
            'salesforce_id' => 'a007R000010UF74QAG',
            'salesforce_name' => 'HR Motor Rivas-Vaciamadrid',
        ]);
        $canonical = $this->delegation('Rivas', 'rivas', 320, 'Grupo Madrid', 'Zona Sur y Centro');
        $vehicle = SalesforceVehicle::query()->create([
            'salesforce_id' => 'vehicle-rivas',
            'state' => 'Disponible',
            'stock_delegation_id' => $stockIdentity->id,
            'is_in_stock' => true,
        ]);
        $snapshot = StockDailySnapshot::query()->create([
            'snapshot_date' => '2026-11-16',
            'salesforce_vehicle_id' => $vehicle->id,
            'vehicle_salesforce_id' => $vehicle->salesforce_id,
            'state' => 'Disponible',
            'stock_delegation_id' => $stockIdentity->id,
        ]);
        $sale = SalesforceSaleSnapshot::query()->create([
            'opportunity_salesforce_id' => 'sale-rivas',
            'stock_delegation_id' => $stockIdentity->id,
            'captured_at' => now(),
        ]);
        $alert = StockAvailabilityAlert::query()->create([
            'stock_delegation_id' => $stockIdentity->id,
            'state' => 'open',
            'opened_at' => now(),
        ]);

        $this->artisan('stock:repair-delegation-identities', ['--apply' => true])->assertSuccessful();
        $this->artisan('stock:repair-delegation-identities', ['--apply' => true])->assertSuccessful();

        $this->assertDatabaseHas('stock_delegations', [
            'id' => $canonical->id,
            'salesforce_id' => 'a007R000010UF74QAG',
            'canonical_name' => 'Rivas',
            'normalized_key' => 'rivas',
            'capacity_total' => 320,
            'commercial_group' => 'Grupo Madrid',
            'zone' => 'Zona Sur y Centro',
            'merged_into_id' => null,
        ]);
        $this->assertDatabaseHas('stock_delegations', [
            'id' => $stockIdentity->id,
            'salesforce_id' => null,
            'merged_into_id' => $canonical->id,
            'is_commercial' => false,
        ]);
        $candidateIds = [$stockIdentity->id, $canonical->id];
        $this->assertSame(2, StockDelegation::query()->whereIn('id', $candidateIds)->count());
        $this->assertSame(1, StockDelegation::query()->operational()->whereIn('id', $candidateIds)->count());
        $this->assertSame(320, StockDelegation::query()->operational()->whereIn('id', $candidateIds)->sum('capacity_total'));
        $this->assertSame(1, SalesforceVehicle::query()->count());
        $this->assertSame($canonical->id, $vehicle->fresh()->stock_delegation_id);
        $this->assertSame($canonical->id, $snapshot->fresh()->stock_delegation_id);
        $this->assertSame($canonical->id, $sale->fresh()->stock_delegation_id);
        $this->assertSame($canonical->id, $alert->fresh()->stock_delegation_id);
        $this->assertSame('resolved', $alert->fresh()->state);
    }

    public function test_repair_aborts_atomically_when_capacities_conflict(): void
    {
        $legacy = $this->delegation('HR Motor Rivas-Vaciamadrid', 'hr motor rivas vaciamadrid', 300);
        $legacy->update(['salesforce_id' => 'a007R000010UF74QAG']);
        $canonical = $this->delegation('Rivas', 'rivas', 320, 'Grupo Madrid', 'Zona Sur y Centro');

        try {
            app(StockDelegationConsolidationService::class)->consolidate(
                config('stock.delegation_identity_repairs'),
            );
            $this->fail('La reparación debía rechazar capacidades incompatibles.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Capacidades incompatibles', $exception->getMessage());
        }

        $this->assertSame('a007R000010UF74QAG', $legacy->fresh()->salesforce_id);
        $this->assertNull($legacy->fresh()->merged_into_id);
        $this->assertSame(320, $canonical->fresh()->capacity_total);
        $this->assertNull($canonical->fresh()->salesforce_id);
    }

    public function test_sync_uses_canonical_record_while_controlled_repair_is_pending(): void
    {
        $legacy = $this->delegation('HR Motor Rivas-Vaciamadrid', 'hr motor rivas vaciamadrid', 320);
        $legacy->update([
            'salesforce_id' => 'a007R000010UF74QAG',
            'salesforce_name' => 'HR Motor Rivas-Vaciamadrid',
        ]);
        $canonical = $this->delegation('Rivas', 'rivas', 320, 'Grupo Madrid', 'Zona Sur y Centro');
        $client = new class extends SalesforceClient
        {
            public function __construct() {}

            public function query(string $soql): array
            {
                return [[
                    'Id' => 'vehicle-rivas-pending-repair',
                    'PRO_SEL_Estado__c' => 'Disponible',
                    'PRO_BUS_Delegacion__c' => 'a007R000010UF74QAG',
                    'PRO_BUS_Delegacion__r' => ['Name' => 'HR Motor Rivas-Vaciamadrid'],
                ]];
            }
        };

        (new SalesforceVehicleSyncService($client, app(StockDelegationService::class)))->sync();

        $this->assertSame(2, StockDelegation::query()
            ->whereIn('id', [$legacy->id, $canonical->id])
            ->count());
        $this->assertSame($canonical->id, SalesforceVehicle::query()
            ->where('salesforce_id', 'vehicle-rivas-pending-repair')
            ->value('stock_delegation_id'));
        $this->assertSame('a007R000010UF74QAG', $legacy->fresh()->salesforce_id);
    }

    public function test_repair_preserves_villarreal_capacity_and_historical_spelling(): void
    {
        $stockIdentity = $this->delegation('HR Motor Villarreal', 'hr motor villarreal', 180);
        $stockIdentity->update([
            'salesforce_id' => 'a00Qx00000ZB5rRIAT',
            'salesforce_name' => 'HR Motor Villarreal',
        ]);
        $canonical = $this->delegation(
            'Villarreal/Almassora',
            'villarreal almassora',
            null,
            'Grupo Castellón',
            'Zona Mediterraneo',
        );

        $this->artisan('stock:repair-delegation-identities', ['--apply' => true])->assertSuccessful();

        $this->assertDatabaseHas('stock_delegations', [
            'id' => $canonical->id,
            'salesforce_id' => 'a00Qx00000ZB5rRIAT',
            'canonical_name' => 'Villarreal/Almassora',
            'normalized_key' => 'villarreal almassora',
            'capacity_total' => 180,
            'commercial_group' => 'Grupo Castellón',
            'zone' => 'Zona Mediterraneo',
        ]);
        $this->assertDatabaseHas('stock_delegations', [
            'id' => $stockIdentity->id,
            'salesforce_name' => 'HR Motor Villarreal',
            'merged_into_id' => $canonical->id,
        ]);
        $this->assertSame('Villarreal/Almassora', app(LeadDelegationNormalizer::class)->normalize('Villareal')['delegation']);
    }

    public function test_capacity_import_reuses_historical_villareal_alias(): void
    {
        $canonical = $this->delegation(
            'Villarreal/Almassora',
            'villarreal almassora',
            null,
            'Grupo Castellón',
            'Zona Mediterraneo',
        );
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'stock-villareal-'.uniqid().'.csv';
        file_put_contents($path, "Tienda;Plazas totales\nVillareal;180\n");

        try {
            app(StockCapacityImportService::class)->import($path, ';');
        } finally {
            @unlink($path);
        }

        $this->assertSame(180, $canonical->fresh()->capacity_total);
        $this->assertSame(1, StockDelegation::query()
            ->operational()
            ->where('normalized_key', 'villarreal almassora')
            ->count());
    }

    private function delegation(
        string $name,
        string $key,
        ?int $capacity,
        ?string $group = null,
        ?string $zone = null,
    ): StockDelegation {
        return StockDelegation::query()->create([
            'canonical_name' => $name,
            'normalized_key' => $key,
            'capacity_total' => $capacity,
            'commercial_group' => $group,
            'zone' => $zone,
            'is_commercial' => true,
        ]);
    }
}
