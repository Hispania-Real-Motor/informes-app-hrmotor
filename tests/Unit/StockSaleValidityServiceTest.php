<?php

namespace Tests\Unit;

use App\Models\SalesforceOpportunity;
use App\Models\SalesforceSaleSnapshot;
use App\Services\Reports\Stock\StockDashboardDatasetService;
use App\Services\Reports\Stock\StockSaleValidityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockSaleValidityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_excluye_cerrada_perdida_y_conserva_el_snapshot_economico(): void
    {
        $valid = $this->opportunity('006-valid', 'Contrato', '01t-shared');
        $lost = $this->opportunity('006-lost', 'Cerrada perdida', '01t-shared');
        $validSnapshot = $this->snapshot($valid, 18000);
        $lostSnapshot = $this->snapshot($lost, 22000);

        $result = app(StockSaleValidityService::class)->reconcile();

        $this->assertSame(1, $result['valid']);
        $this->assertSame(1, $result['invalid']);
        $this->assertTrue($validSnapshot->fresh()->is_valid);
        $this->assertFalse($lostSnapshot->fresh()->is_valid);
        $this->assertSame(StockSaleValidityService::REASON_CLOSED_LOST, $lostSnapshot->fresh()->invalid_reason);
        $this->assertSame('22000.00', $lostSnapshot->fresh()->sale_price);
        $this->assertSame(1, app(StockDashboardDatasetService::class)->build([
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ], 'summary')['summary']['sales']);
    }

    public function test_elige_la_venta_con_fecha_de_firma_mas_reciente(): void
    {
        $first = $this->opportunity('006-first', 'Contrato', '01t-duplicate');
        $second = $this->opportunity('006-second', 'Cerrada ganada', '01t-duplicate');
        $firstSnapshot = $this->snapshot($first, 18000, '2026-07-20');
        $secondSnapshot = $this->snapshot($second, 18500, '2026-07-22');
        $service = app(StockSaleValidityService::class);

        $result = $service->reconcile();

        $this->assertSame(1, $result['valid']);
        $this->assertSame(1, $result['duplicates']);
        $this->assertSame(
            StockSaleValidityService::REASON_DUPLICATE_NOT_SELECTED,
            $firstSnapshot->fresh()->invalid_reason,
        );
        $this->assertSame('006-second', $firstSnapshot->fresh()->selected_opportunity_salesforce_id);
        $this->assertTrue($secondSnapshot->fresh()->is_valid);
        $this->assertSame(1, app(StockDashboardDatasetService::class)->build([
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ], 'summary')['summary']['sales']);

        $second->update(['stage_name' => 'Cerrada perdida']);
        $service->reconcile();

        $this->assertTrue($firstSnapshot->fresh()->is_valid);
        $this->assertNull($firstSnapshot->fresh()->invalid_reason);
        $this->assertFalse($secondSnapshot->fresh()->is_valid);
        $this->assertSame(
            StockSaleValidityService::REASON_CLOSED_LOST,
            $secondSnapshot->fresh()->invalid_reason,
        );
        $this->assertSame(1, app(StockDashboardDatasetService::class)->build([
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ], 'summary')['summary']['sales']);
    }

    public function test_fecha_de_firma_empatada_marca_todas_las_ventas_como_ambiguas(): void
    {
        $first = $this->opportunity('006-tie-a', 'Contrato', '01t-tie');
        $second = $this->opportunity('006-tie-b', 'Contrato', '01t-tie');
        $firstSnapshot = $this->snapshot($first, 18000, '2026-07-20');
        $secondSnapshot = $this->snapshot($second, 19000, '2026-07-20');

        $result = app(StockSaleValidityService::class)->reconcile();

        $this->assertSame(0, $result['valid']);
        $this->assertSame(2, $result['duplicates']);
        $this->assertSame(StockSaleValidityService::REASON_DUPLICATE_AMBIGUOUS, $firstSnapshot->fresh()->invalid_reason);
        $this->assertSame(StockSaleValidityService::REASON_DUPLICATE_AMBIGUOUS, $secondSnapshot->fresh()->invalid_reason);
        $this->assertNull($firstSnapshot->fresh()->selected_opportunity_salesforce_id);
    }

    public function test_excluye_venta_firmada_sin_fecha_de_firma(): void
    {
        $opportunity = $this->opportunity('006-without-date', 'Contrato', '01t-no-date');
        $opportunity->update(['cv_signed_date' => null]);
        $snapshot = $this->snapshot($opportunity, 18000);

        app(StockSaleValidityService::class)->reconcile();

        $this->assertFalse($snapshot->fresh()->is_valid);
        $this->assertSame('missing_signed_date', $snapshot->fresh()->invalid_reason);
    }

    public function test_invalida_borrado_confirmado_pero_conserva_sin_cambios_el_snapshot_ausente_localmente(): void
    {
        $deleted = $this->opportunity('006-deleted', 'Contrato', '01t-deleted');
        $deleted->update([
            'is_deleted' => true,
            'salesforce_deleted_at' => '2026-09-01 10:00:00',
            'deletion_detection_source' => SalesforceOpportunity::DELETION_SOURCE_QUERY_ALL,
        ]);
        $deletedSnapshot = $this->snapshot($deleted, 18000);
        $missingSnapshot = SalesforceSaleSnapshot::query()->create([
            'opportunity_salesforce_id' => '006-missing',
            'record_type' => 'Venta',
            'signed_date' => '2026-07-20',
            'vehicle_salesforce_id' => '01t-missing',
            'sale_price' => 17000,
            'is_valid' => true,
            'captured_at' => now(),
        ]);

        $result = app(StockSaleValidityService::class)->reconcile();

        $this->assertSame(1, $result['invalid']);
        $this->assertSame(1, $result['unchecked']);
        $this->assertFalse($deletedSnapshot->fresh()->is_valid);
        $this->assertSame(
            StockSaleValidityService::REASON_OPPORTUNITY_CONFIRMED_DELETED,
            $deletedSnapshot->fresh()->invalid_reason,
        );
        $this->assertTrue($missingSnapshot->fresh()->is_valid);
        $this->assertNull($missingSnapshot->fresh()->invalid_reason);
        $this->assertNull($missingSnapshot->fresh()->validity_checked_at);
    }

    public function test_comando_reconcilia_exclusivamente_la_validez_de_snapshots_existentes(): void
    {
        $deleted = $this->opportunity('006-command-deleted', 'Contrato', '01t-command-deleted');
        $deleted->update([
            'is_deleted' => true,
            'deletion_detection_source' => SalesforceOpportunity::DELETION_SOURCE_QUERY_ALL,
        ]);
        $snapshot = $this->snapshot($deleted, 18000);

        $this->artisan('stock:reconcile-sale-validity')
            ->expectsOutputToContain('STOCK_SALE_VALIDITY_METRICS=')
            ->assertSuccessful();

        $this->assertFalse($snapshot->fresh()->is_valid);
        $this->assertSame(
            StockSaleValidityService::REASON_OPPORTUNITY_CONFIRMED_DELETED,
            $snapshot->fresh()->invalid_reason,
        );
    }

    private function opportunity(string $id, string $stage, string $vehicleId): SalesforceOpportunity
    {
        return SalesforceOpportunity::query()->create([
            'salesforce_id' => $id,
            'record_type_name' => 'Venta',
            'stage_name' => $stage,
            'cv_signed' => true,
            'cv_signed_date' => '2026-07-20',
            'vehicle_interest_id' => $vehicleId,
        ]);
    }

    private function snapshot(SalesforceOpportunity $opportunity, int $price, string $signedDate = '2026-07-20'): SalesforceSaleSnapshot
    {
        return SalesforceSaleSnapshot::query()->create([
            'opportunity_salesforce_id' => $opportunity->salesforce_id,
            'record_type' => 'Venta',
            'signed_date' => $signedDate,
            'vehicle_salesforce_id' => $opportunity->vehicle_interest_id,
            'sale_price' => $price,
            'is_valid' => true,
            'captured_at' => now(),
        ]);
    }
}
