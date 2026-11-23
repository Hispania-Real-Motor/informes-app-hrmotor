<?php

namespace Tests\Feature;

use App\Models\CommercialCommissionClosure;
use App\Models\CommercialCommissionRosterMember;
use App\Models\CommercialCommissionSnapshot;
use App\Models\HrEmploymentDate;
use App\Models\ReportUser;
use App\Models\SalesforceDelegationManagerHistory;
use App\Models\SalesforceOpportunity;
use App\Models\SalesforceUser;
use App\Models\StoreManagerCommissionTarget;
use App\Services\Reports\CommercialCommissions\CommercialCommissionClosureService;
use App\Services\Reports\CommercialCommissions\CommercialCommissionDashboardService;
use App\Services\Reports\CommercialCommissions\CommercialCommissionDelegationReviewsService;
use App\Services\Reports\CommercialCommissions\CommercialCommissionFormulaConfigService;
use App\Services\Reports\CommercialCommissions\StoreManagerCommissionTargetService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StoreManagerSettlementIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_dashboard_calculates_the_complete_northern_store_settlement(): void
    {
        CarbonImmutable::setTestNow('2026-11-19 12:00:00');
        $this->seedStoreManagerFixture();

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-10', false, true, false);
        $row = collect($payload['delegation_rows'])->firstWhere('delegation_name', 'Norte Test');

        $this->assertNotNull($row);
        $this->assertSame('calculated', $row['store_manager_status']);
        $this->assertSame(100.0, $row['store_deliveries_incentive']);
        $this->assertSame(100.0, $row['store_purchases_incentive']);
        $this->assertSame(150.0, $row['store_reviews_adjustment']);
        $this->assertSame(120.0, data_get($row, 'team_components.TEAM-1.delivery_incentive'));
        $this->assertSame(100.0, data_get($row, 'team_components.TEAM-1.financing'));
        $this->assertSame(60.0, data_get($row, 'team_components.TEAM-1.guarantee'));
        $this->assertSame(280.0, data_get($row, 'team_components.TEAM-1.subtotal'));
        $this->assertSame(630.0, $row['total_commission']);
    }

    public function test_dashboard_uses_financial_commission_instead_of_financed_capital(): void
    {
        CarbonImmutable::setTestNow('2026-11-19 12:00:00');
        $this->seedStoreManagerFixture();
        SalesforceOpportunity::query()->where('owner_id', 'TEAM-1')->update(['financial_commission' => 800.0]);

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-10', false, true, false);
        $row = collect($payload['delegation_rows'])->firstWhere('delegation_name', 'Norte Test');

        $this->assertNotNull($row);
        $this->assertSame(0.0, data_get($row, 'team_components.TEAM-1.financing'));
        $this->assertSame(80.0, data_get($row, 'team_components.TEAM-1.subtotal'));
        $this->assertSame(430.0, $row['total_commission']);
    }

    public function test_verified_manager_is_exempt_without_an_hr_date(): void
    {
        CarbonImmutable::setTestNow('2026-11-19 12:00:00');
        $this->seedStoreManagerFixture();
        HrEmploymentDate::query()->where('salesforce_user_id', 'TEAM-1')->delete();
        SalesforceDelegationManagerHistory::query()->where('source_key', 'fixture-manager')->update([
            'manager_salesforce_user_id' => 'TEAM-1',
        ]);

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-10', false, true, false);
        $row = collect($payload['delegation_rows'])->firstWhere('delegation_name', 'Norte Test');

        $this->assertSame('calculated', $row['store_manager_status']);
        $this->assertSame(0.0, data_get($row, 'team_components.TEAM-1.penalty'));
        $this->assertSame(280.0, data_get($row, 'team_components.TEAM-1.subtotal'));
    }

    public function test_verified_closing_manager_without_full_month_coverage_keeps_settlement_pending(): void
    {
        CarbonImmutable::setTestNow('2026-11-19 12:00:00');
        $this->seedStoreManagerFixture();
        SalesforceDelegationManagerHistory::query()->where('source_key', 'fixture-manager')->update([
            'coverage_from' => '2026-10-15 00:00:00',
        ]);

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-10', false, true, false);
        $row = collect($payload['delegation_rows'])->firstWhere('delegation_name', 'Norte Test');

        $this->assertSame('pending_source', $row['store_manager_status']);
        $this->assertStringContainsString('Historial del responsable de tienda no verificable', implode(' ', $row['store_manager_issues']));
    }

    public function test_roster_member_without_deliveries_remains_in_the_team_breakdown(): void
    {
        CarbonImmutable::setTestNow('2026-11-19 12:00:00');
        $this->seedStoreManagerFixture();
        CommercialCommissionRosterMember::query()->create([
            'month' => '2026-10',
            'delegation_key' => 'norte-test',
            'delegation_name' => 'Norte Test',
            'salesforce_user_id' => 'TEAM-2',
            'member_name' => 'Comercial compartido',
            'coverage_from' => '2026-10-01 00:00:00',
            'coverage_to' => '2026-11-01 00:00:00',
            'source' => 'fixture',
            'evidence_reference' => 'fixture-roster-no-deliveries',
            'recorded_by' => 'integration-test',
            'history_verified' => true,
        ]);
        HrEmploymentDate::query()->create([
            'salesforce_user_id' => 'TEAM-2',
            'corporate_email' => 'fixture-two@example.test',
            'effective_date' => '2026-01-01',
            'source_label' => 'fixture',
            'source_reference' => 'fixture-hr-two',
        ]);

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-10', false, true, false);
        $row = collect($payload['delegation_rows'])->firstWhere('delegation_name', 'Norte Test');

        $this->assertArrayHasKey('TEAM-2', $row['team_components']);
        $this->assertSame(0, data_get($row, 'team_components.TEAM-2.deliveries'));
        $this->assertSame(-100.0, data_get($row, 'team_components.TEAM-2.subtotal'));
        $this->assertSame(530.0, $row['total_commission']);
    }

    public function test_shared_delivery_is_attributed_to_the_secondary_roster_member_without_losing_the_primary(): void
    {
        CarbonImmutable::setTestNow('2026-11-19 12:00:00');
        $this->seedStoreManagerFixture();
        CommercialCommissionRosterMember::query()->create([
            'month' => '2026-10',
            'delegation_key' => 'norte-test',
            'delegation_name' => 'Norte Test',
            'salesforce_user_id' => 'TEAM-2',
            'member_name' => 'Comercial compartido',
            'coverage_from' => '2026-10-01 00:00:00',
            'coverage_to' => '2026-11-01 00:00:00',
            'source' => 'fixture',
            'evidence_reference' => 'fixture-roster-shared',
            'recorded_by' => 'integration-test',
            'history_verified' => true,
        ]);
        HrEmploymentDate::query()->create([
            'salesforce_user_id' => 'TEAM-2',
            'corporate_email' => 'fixture-shared@example.test',
            'effective_date' => '2026-01-01',
            'source_label' => 'fixture',
            'source_reference' => 'fixture-hr-shared',
        ]);
        SalesforceOpportunity::query()->create([
            'salesforce_id' => 'DELIVERY-SHARED',
            'name' => 'Entrega compartida',
            'stage_name' => 'Cerrada ganada',
            'record_type_name' => 'Venta',
            'owner_id' => 'TEAM-1',
            'owner_name' => 'Comercial fixture',
            'owner_is_active' => true,
            'shared_delivery_id' => 'TEAM-2',
            'shared_delivery_name' => 'Comercial compartido',
            'delivery_store' => 'HR Motor Norte Test',
            'garantia_total' => 350.0,
            'financial_commission' => 900.0,
            'cv_signed' => true,
            'cv_signed_date' => '2026-10-29',
        ]);

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-10', false, true, false);
        $row = collect($payload['delegation_rows'])->firstWhere('delegation_name', 'Norte Test');

        $this->assertSame(2, $row['team_member_count']);
        $this->assertSame(0, data_get($row, 'team_components.TEAM-2.deliveries'));
        $this->assertSame(0, data_get($row, 'team_components.TEAM-2.primary_deliveries'));
        $this->assertSame(1, data_get($row, 'team_components.TEAM-2.shared_deliveries'));
        $this->assertSame(0.0, data_get($row, 'team_components.TEAM-2.delivery_incentive'));
        $this->assertSame(0.0, data_get($row, 'team_components.TEAM-2.financing'));
        $this->assertSame(0.0, data_get($row, 'team_components.TEAM-2.guarantee'));
        $this->assertSame(-100.0, data_get($row, 'team_components.TEAM-2.subtotal'));
    }

    public function test_shared_delivery_is_physical_only_and_cannot_reach_the_individual_minimum(): void
    {
        CarbonImmutable::setTestNow('2026-11-19 12:00:00');
        $this->seedStoreManagerFixture();
        $this->addSecondRosterMember();
        SalesforceOpportunity::query()->where('salesforce_id', 'DELIVERY-10')->delete();
        SalesforceOpportunity::query()->create([
            'salesforce_id' => 'DELIVERY-SHARED-ONLY',
            'name' => 'Entrega compartida única',
            'stage_name' => 'Cerrada ganada',
            'record_type_name' => 'Venta',
            'owner_id' => 'TEAM-1',
            'owner_name' => 'Comercial fixture',
            'shared_delivery_id' => 'TEAM-2',
            'shared_delivery_name' => 'Comercial compartido',
            'delivery_store' => 'HR Motor Norte Test',
            'garantia_total' => 350.0,
            'financial_commission' => 900.0,
            'cv_signed' => true,
            'cv_signed_date' => '2026-10-29',
        ]);

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-10', false, true, false);
        $row = collect($payload['delegation_rows'])->firstWhere('delegation_name', 'Norte Test');

        $this->assertSame(10, $row['deliveries_count']);
        $this->assertSame(9, $row['individual_deliveries_count']);
        $this->assertSame(1, $row['shared_deliveries_count']);
        $this->assertSame(9, data_get($row, 'team_components.TEAM-1.deliveries'));
        $this->assertSame(1, data_get($row, 'team_components.TEAM-1.shared_deliveries'));
        $this->assertSame(0.0, data_get($row, 'team_components.TEAM-1.delivery_incentive'));
        $this->assertSame(0.0, data_get($row, 'team_components.TEAM-1.financing'));
        $this->assertSame(0.0, data_get($row, 'team_components.TEAM-1.guarantee'));
        $this->assertSame(100.0, data_get($row, 'team_components.TEAM-1.penalty'));
        $this->assertSame(-100.0, data_get($row, 'team_components.TEAM-1.subtotal'));
        $this->assertSame(0, data_get($row, 'team_components.TEAM-2.deliveries'));
        $this->assertSame(1, data_get($row, 'team_components.TEAM-2.shared_deliveries'));
        $this->assertSame(0.0, data_get($row, 'team_components.TEAM-2.financing'));
        $this->assertSame(0.0, data_get($row, 'team_components.TEAM-2.guarantee'));
        $this->assertSame(100.0, data_get($row, 'team_components.TEAM-2.penalty'));
        $this->assertSame(-100.0, data_get($row, 'team_components.TEAM-2.subtotal'));
        $this->assertSame(150.0, $row['total_commission']);
    }

    public function test_ten_shared_deliveries_do_not_create_individual_economic_incentives(): void
    {
        CarbonImmutable::setTestNow('2026-11-19 12:00:00');
        $this->seedStoreManagerFixture();
        $this->addSecondRosterMember();
        SalesforceOpportunity::query()->where('record_type_name', 'Venta')->delete();

        foreach (range(1, 10) as $index) {
            SalesforceOpportunity::query()->create([
                'salesforce_id' => 'SHARED-ONLY-'.$index,
                'name' => 'Entrega compartida '.$index,
                'stage_name' => 'Cerrada ganada',
                'record_type_name' => 'Venta',
                'owner_id' => 'TEAM-1',
                'owner_name' => 'Comercial fixture',
                'shared_delivery_id' => 'TEAM-2',
                'shared_delivery_name' => 'Comercial compartido',
                'delivery_store' => 'HR Motor Norte Test',
                'garantia_total' => 350.0,
                'financial_commission' => 900.0,
                'cv_signed' => true,
                'cv_signed_date' => '2026-10-29',
            ]);
        }

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-10', false, true, false);
        $row = collect($payload['delegation_rows'])->firstWhere('delegation_name', 'Norte Test');

        $this->assertSame(10, $row['deliveries_count']);
        $this->assertSame(0, $row['individual_deliveries_count']);
        $this->assertSame(10, $row['shared_deliveries_count']);
        $this->assertSame(0, data_get($row, 'team_components.TEAM-1.deliveries'));
        $this->assertSame(0, data_get($row, 'team_components.TEAM-2.deliveries'));
        $this->assertSame(10, data_get($row, 'team_components.TEAM-1.shared_deliveries'));
        $this->assertSame(10, data_get($row, 'team_components.TEAM-2.shared_deliveries'));
        $this->assertSame(0.0, data_get($row, 'team_components.TEAM-1.financing'));
        $this->assertSame(0.0, data_get($row, 'team_components.TEAM-1.guarantee'));
        $this->assertSame(0.0, data_get($row, 'team_components.TEAM-2.financing'));
        $this->assertSame(0.0, data_get($row, 'team_components.TEAM-2.guarantee'));
        $this->assertSame(100.0, data_get($row, 'team_components.TEAM-1.penalty'));
        $this->assertSame(-100.0, data_get($row, 'team_components.TEAM-1.subtotal'));
        $this->assertSame(100.0, data_get($row, 'team_components.TEAM-2.penalty'));
        $this->assertSame(-100.0, data_get($row, 'team_components.TEAM-2.subtotal'));
    }

    public function test_roster_coverage_excludes_operations_outside_the_verified_interval(): void
    {
        CarbonImmutable::setTestNow('2026-11-19 12:00:00');
        $this->seedStoreManagerFixture();
        CommercialCommissionRosterMember::query()
            ->where('salesforce_user_id', 'TEAM-1')
            ->update(['coverage_from' => '2026-10-20 00:00:00']);
        SalesforceOpportunity::query()->create([
            'salesforce_id' => 'DELIVERY-IN-COVERAGE',
            'name' => 'Entrega dentro de vigencia',
            'stage_name' => 'Cerrada ganada',
            'record_type_name' => 'Venta',
            'owner_id' => 'TEAM-1',
            'owner_name' => 'Comercial fixture',
            'delivery_store' => 'HR Motor Norte Test',
            'garantia_total' => 350.0,
            'financial_commission' => 900.0,
            'cv_signed' => true,
            'cv_signed_date' => '2026-10-25',
        ]);

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-10', false, true, false);
        $row = collect($payload['delegation_rows'])->firstWhere('delegation_name', 'Norte Test');

        $this->assertSame('pending_source', $row['store_manager_status']);
        $this->assertSame(1, data_get($row, 'team_components.TEAM-1.deliveries'));
        $this->assertNull(data_get($row, 'team_components.TEAM-1.subtotal'));
        $this->assertStringContainsString('DELIVERY-1', implode(' ', $row['store_manager_issues']));
        $this->assertStringContainsString('vigencia', implode(' ', $row['store_manager_issues']));
    }

    public function test_overlapping_roster_assignments_block_the_affected_store_component(): void
    {
        CarbonImmutable::setTestNow('2026-11-19 12:00:00');
        $this->seedStoreManagerFixture();
        CommercialCommissionRosterMember::query()->create([
            'month' => '2026-10',
            'delegation_key' => 'otra-tienda',
            'delegation_name' => 'Otra tienda',
            'salesforce_user_id' => 'TEAM-1',
            'member_name' => 'Comercial fixture',
            'coverage_from' => '2026-10-15 00:00:00',
            'coverage_to' => '2026-10-25 00:00:00',
            'source' => 'fixture',
            'evidence_reference' => 'fixture-roster-conflict',
            'recorded_by' => 'integration-test',
            'history_verified' => true,
        ]);

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-10', false, true, false);
        $row = collect($payload['delegation_rows'])->firstWhere('delegation_name', 'Norte Test');

        $this->assertSame('pending_source', $row['store_manager_status']);
        $this->assertNull(data_get($row, 'team_components.TEAM-1.subtotal'));
        $this->assertStringContainsString('solapadas', implode(' ', $row['store_manager_issues']));
    }

    public function test_sale_in_a_later_month_does_not_make_a_second_purchase_for_the_store_plan(): void
    {
        CarbonImmutable::setTestNow('2026-11-19 12:00:00');
        $this->seedStoreManagerFixture();
        SalesforceOpportunity::query()->create([
            'salesforce_id' => 'SALE-FROM-APPRAISAL',
            'name' => 'Venta posterior de tasación',
            'stage_name' => 'Cerrada ganada',
            'record_type_name' => 'Venta',
            'owner_id' => 'TEAM-1',
            'owner_name' => 'Comercial fixture',
            'purchase_appraisal_id' => 'APPRAISAL-1',
            'delivery_store' => 'HR Motor Norte Test',
            'cv_signed' => true,
            'cv_signed_date' => '2026-11-03',
        ]);

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-10', false, true, false);
        $row = collect($payload['delegation_rows'])->firstWhere('delegation_name', 'Norte Test');

        $this->assertSame('calculated', $row['store_manager_status']);
        $this->assertSame(10, $row['purchases_count']);
        $this->assertNotNull($row['total_commission']);
        $this->assertStringNotContainsString('APPRAISAL-1 aparece firmada en más de un mes', implode(' ', $row['store_manager_issues']));
    }

    public function test_review_payload_outside_the_source_range_remains_pending(): void
    {
        CarbonImmutable::setTestNow('2026-11-19 12:00:00');
        $this->seedStoreManagerFixture();
        $reviews = Mockery::mock(CommercialCommissionDelegationReviewsService::class);
        $reviews->shouldReceive('forMonthAndDelegations')->once()->andReturn([
            'Norte Test' => [
                'reviews_count' => 1,
                'average_rating' => 6.0,
                'technical_status' => 'available',
                'fetched_at' => now()->toIso8601String(),
            ],
        ]);
        $this->app->instance(CommercialCommissionDelegationReviewsService::class, $reviews);

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-10', false, true, false);
        $row = collect($payload['delegation_rows'])->firstWhere('delegation_name', 'Norte Test');

        $this->assertSame('pending_source', $row['store_manager_status']);
        $this->assertNull($row['store_reviews_adjustment']);
        $this->assertStringContainsString('Payload de reseñas incoherente', implode(' ', $row['store_manager_issues']));
    }

    public function test_delivery_without_a_salesforce_owner_blocks_team_attribution(): void
    {
        CarbonImmutable::setTestNow('2026-11-19 12:00:00');
        $this->seedStoreManagerFixture();
        SalesforceOpportunity::query()->where('salesforce_id', 'DELIVERY-1')->update(['owner_id' => null]);

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-10', false, true, false);
        $row = collect($payload['delegation_rows'])->firstWhere('delegation_name', 'Norte Test');

        $this->assertSame('pending_source', $row['store_manager_status']);
        $this->assertStringContainsString('DELIVERY-1', implode(' ', $row['store_manager_issues']));
        $this->assertStringContainsString('propietario Salesforce', implode(' ', $row['store_manager_issues']));
    }

    public function test_unknown_delivery_store_is_blocking_and_is_not_attributed_to_the_owner_store(): void
    {
        CarbonImmutable::setTestNow('2026-11-19 12:00:00');
        $this->seedStoreManagerFixture();
        SalesforceOpportunity::query()->create([
            'salesforce_id' => 'DELIVERY-UNKNOWN-STORE',
            'name' => 'Entrega con tienda desconocida',
            'stage_name' => 'Cerrada ganada',
            'record_type_name' => 'Venta',
            'owner_id' => 'TEAM-1',
            'owner_name' => 'Comercial fixture',
            'owner_is_active' => true,
            'delivery_store' => 'HR Motor Tienda Fantasma',
            'garantia_total' => 350.0,
            'financial_commission' => 900.0,
            'cv_signed' => true,
            'cv_signed_date' => '2026-10-29',
        ]);

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-10', false, true, false);
        $row = collect($payload['delegation_rows'])->firstWhere('delegation_name', 'Norte Test');

        $this->assertSame(10, $row['deliveries_count']);
        $this->assertSame('pending_source', $row['store_manager_status']);
        $this->assertNull($row['total_commission']);
        $this->assertStringContainsString('DELIVERY-UNKNOWN-STORE', implode(' ', $row['store_manager_issues']));
        $this->assertStringContainsString('desconocida', implode(' ', $row['store_manager_issues']));
    }

    public function test_ambiguous_delivery_store_is_blocking_and_is_not_attributed_to_either_target(): void
    {
        CarbonImmutable::setTestNow('2026-11-19 12:00:00');
        $this->seedStoreManagerFixture();
        StoreManagerCommissionTarget::query()->create([
            'month' => '2026-10',
            'zone' => 'Norte',
            'store_key' => 'norte-test-duplicate',
            'store_name' => 'Norte Test',
            'delivery_target' => 10,
            'purchase_target' => 10,
            'source_sha256' => hash('sha256', 'fixture-duplicate'),
            'source_label' => 'fixture de integración',
        ]);

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-10', false, true, false);
        $rows = collect($payload['delegation_rows'])->filter(fn (array $row): bool => str_starts_with($row['store_manager_target_key'], 'norte-test'));

        $this->assertCount(2, $rows);
        $this->assertTrue($rows->every(fn (array $row): bool => $row['deliveries_count'] === 0
            && $row['store_manager_status'] === 'pending_source'
            && str_contains(implode(' ', $row['store_manager_issues']), 'ambigua')));
    }

    public function test_intraday_roster_start_does_not_cover_the_boundary_date_but_covers_the_next_date(): void
    {
        CarbonImmutable::setTestNow('2026-11-19 12:00:00');
        $this->seedStoreManagerFixture();
        CommercialCommissionRosterMember::query()
            ->where('salesforce_user_id', 'TEAM-1')
            ->update(['coverage_from' => '2026-10-20 18:00:00']);
        foreach ([
            ['id' => 'DELIVERY-INTRADAY-20', 'date' => '2026-10-20'],
            ['id' => 'DELIVERY-INTRADAY-21', 'date' => '2026-10-21'],
        ] as $delivery) {
            SalesforceOpportunity::query()->create([
                'salesforce_id' => $delivery['id'],
                'name' => 'Entrega '.$delivery['date'],
                'stage_name' => 'Cerrada ganada',
                'record_type_name' => 'Venta',
                'owner_id' => 'TEAM-1',
                'owner_name' => 'Comercial fixture',
                'owner_is_active' => true,
                'delivery_store' => 'HR Motor Norte Test',
                'garantia_total' => 350.0,
                'financial_commission' => 900.0,
                'cv_signed' => true,
                'cv_signed_date' => $delivery['date'],
            ]);
        }

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-10', false, true, false);
        $row = collect($payload['delegation_rows'])->firstWhere('delegation_name', 'Norte Test');

        $this->assertSame('pending_source', $row['store_manager_status']);
        $this->assertSame(1, data_get($row, 'team_components.TEAM-1.primary_deliveries'));
        $this->assertStringContainsString('DELIVERY-INTRADAY-20', implode(' ', $row['store_manager_issues']));
        $this->assertStringNotContainsString('DELIVERY-INTRADAY-21 queda fuera', implode(' ', $row['store_manager_issues']));
    }

    public function test_unknown_store_zone_blocks_the_economic_total_without_default_minimums(): void
    {
        CarbonImmutable::setTestNow('2026-11-19 12:00:00');
        $this->seedStoreManagerFixture();
        StoreManagerCommissionTarget::query()->where('store_key', 'norte-test')->update(['zone' => 'Zona no reconocida']);

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-10', false, true, false);
        $row = collect($payload['delegation_rows'])->firstWhere('delegation_name', 'Norte Test');

        $this->assertSame('pending_source', $row['store_manager_status']);
        $this->assertNull($row['total_commission']);
        $this->assertStringContainsString('Zona de tienda ausente o no reconocida', implode(' ', $row['store_manager_issues']));
    }

    public function test_conflicting_physical_delegation_ids_do_not_count_a_purchase_twice(): void
    {
        CarbonImmutable::setTestNow('2026-11-19 12:00:00');
        $this->seedStoreManagerFixture();
        SalesforceOpportunity::query()->create([
            'salesforce_id' => 'PURCHASE-CONFLICT',
            'name' => 'Tasación duplicada',
            'stage_name' => 'Cerrada ganada',
            'record_type_name' => 'Tasacion',
            'owner_id' => 'PURCHASE-OWNER',
            'owner_is_active' => true,
            'purchase_appraisal_id' => 'APPRAISAL-1',
            'purchase_delegation_id' => 'STORE-NORTE-CONFLICT',
            'purchase_delegation_name' => 'Norte Test',
            'cv_signed' => true,
            'cv_signed_date' => '2026-10-22',
        ]);

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-10', false, true, false);
        $row = collect($payload['delegation_rows'])->firstWhere('delegation_name', 'Norte Test');

        $this->assertSame(9, $row['purchases_count']);
        $this->assertSame('pending_source', $row['store_manager_status']);
        $this->assertStringContainsString('APPRAISAL-1', implode(' ', $row['store_manager_issues']));
        $this->assertNull($row['total_commission']);
    }

    public function test_purchase_appraisal_signed_in_another_month_is_pending(): void
    {
        CarbonImmutable::setTestNow('2026-11-19 12:00:00');
        $this->seedStoreManagerFixture();
        SalesforceOpportunity::query()->create([
            'salesforce_id' => 'PURCHASE-CROSS-MONTH',
            'name' => 'Tasación repetida en otro mes',
            'stage_name' => 'Cerrada ganada',
            'record_type_name' => 'Tasacion',
            'owner_id' => 'PURCHASE-OWNER',
            'owner_is_active' => true,
            'purchase_appraisal_id' => 'APPRAISAL-1',
            'purchase_delegation_id' => 'STORE-NORTE-1',
            'purchase_delegation_name' => 'Norte Test',
            'cv_signed' => true,
            'cv_signed_date' => '2026-09-30',
        ]);

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-10', false, true, false);
        $row = collect($payload['delegation_rows'])->firstWhere('delegation_name', 'Norte Test');

        $this->assertSame(9, $row['purchases_count']);
        $this->assertSame('pending_source', $row['store_manager_status']);
        $this->assertStringContainsString('más de un mes', implode(' ', $row['store_manager_issues']));
        $this->assertNull($row['total_commission']);
    }

    public function test_correct_october_candidate_passes_target_authorization_validation_without_approving(): void
    {
        $reference = $this->seedApprovedOctoberTargets();
        $dashboard = $this->storeManagerCandidateDashboard($reference['targets']);

        $this->invokeStoreManagerCandidateValidation($dashboard);
        $this->assertTrue(true);
    }

    public function test_october_candidate_is_rejected_when_the_approved_reference_is_unavailable(): void
    {
        $reference = $this->seedApprovedOctoberTargets();
        $targetService = Mockery::mock(StoreManagerCommissionTargetService::class);
        $targetService->shouldReceive('forMonth')->once()->with('2026-10')->andReturn($reference['targets']);
        $targetService->shouldReceive('approvedReferenceForMonth')->once()->with('2026-10')->andReturn(null);
        $this->app->instance(StoreManagerCommissionTargetService::class, $targetService);

        $this->expectException(ValidationException::class);
        $this->invokeStoreManagerCandidateValidation($this->storeManagerCandidateDashboard($reference['targets']));
    }

    public function test_candidate_with_available_reviews_but_missing_frozen_metrics_is_rejected(): void
    {
        $reference = $this->seedApprovedOctoberTargets();

        $this->expectException(ValidationException::class);
        $this->invokeStoreManagerCandidateValidation(
            $this->storeManagerCandidateDashboard($reference['targets'], null, null, 0.0),
        );
    }

    public function test_candidate_does_not_round_the_review_average_before_applying_the_threshold(): void
    {
        $reference = $this->seedApprovedOctoberTargets();

        $this->expectException(ValidationException::class);
        $this->invokeStoreManagerCandidateValidation(
            $this->storeManagerCandidateDashboard($reference['targets'], 1, 3.995, 150.0),
        );
    }

    #[DataProvider('validStoreManagerReviewSnapshots')]
    public function test_valid_frozen_review_snapshots_are_accepted(
        int $reviewsCount,
        ?float $averageRating,
        float $reviewAdjustment,
    ): void {
        $reference = $this->seedApprovedOctoberTargets();

        $this->invokeStoreManagerCandidateValidation(
            $this->storeManagerCandidateDashboard($reference['targets'], $reviewsCount, $averageRating, $reviewAdjustment),
        );
        $this->assertTrue(true);
    }

    /** @return array<string, array{int, ?float, float}> */
    public static function validStoreManagerReviewSnapshots(): array
    {
        return [
            'high rating' => [1, 4.2, 150.0],
            'below threshold without rounding' => [1, 3.995, -150.0],
            'verified zero reviews' => [0, null, -150.0],
        ];
    }

    public function test_tampered_snapshot_replacing_an_authorized_store_is_rejected(): void
    {
        $reference = $this->seedApprovedOctoberTargets();
        $targets = $reference['targets'];
        $targets[0]['store_key'] = 'fake-authorized-store';
        $targets[0]['store_name'] = 'Tienda no autorizada';

        $this->expectException(ValidationException::class);
        $this->invokeStoreManagerCandidateValidation($this->storeManagerCandidateDashboard($targets));
    }

    public function test_tampered_snapshot_with_altered_targets_is_rejected_even_when_rows_are_recalculated(): void
    {
        $reference = $this->seedApprovedOctoberTargets();
        $targets = $reference['targets'];
        $targets[0]['target_deliveries']++;
        $targets[0]['target_purchases']++;

        $this->expectException(ValidationException::class);
        $this->invokeStoreManagerCandidateValidation($this->storeManagerCandidateDashboard($targets));
    }

    public function test_closure_rejects_a_snapshot_with_a_contradictory_total(): void
    {
        CarbonImmutable::setTestNow('2026-11-19 12:00:00');
        $admin = ReportUser::query()->create([
            'name' => 'Settlement admin',
            'email' => 'settlement-admin@example.test',
            'password' => 'secret',
            'role' => ReportUser::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $row = [
            'store_manager_plan' => 'october_2026',
            'store_manager_status' => 'calculated',
            'store_manager_issues' => [],
            'reviews_technical_status' => 'available',
            'reviews_fetched_at' => now()->toIso8601String(),
            'store_manager_history_status' => 'verified',
            'store_deliveries_incentive' => 100.0,
            'store_purchases_incentive' => 100.0,
            'store_reviews_adjustment' => 150.0,
            'team_subtotals' => [280.0],
            'total_commission' => 650.0,
        ];
        $closure = CommercialCommissionClosure::query()->create([
            'month' => '2026-10',
            'closure_scope' => CommercialCommissionClosure::SCOPE_DELEGATIONS,
            'status' => CommercialCommissionClosure::STATUS_PENDING_APPROVAL,
            'component_statuses' => array_fill_keys(array_keys(app(CommercialCommissionClosureService::class)->requiredComponents('delegations', '2026-10')), true),
            'snapshot_version' => 1,
            'formula_version' => '2026-10-store-manager',
            'data_cutoff_at' => now(),
        ]);
        CommercialCommissionSnapshot::query()->create([
            'closure_id' => $closure->id,
            'month' => '2026-10',
            'version' => 1,
            'formula_version' => '2026-10-store-manager',
            'data_cutoff_at' => now(),
            'payload' => ['delegations' => ['delegation_rows' => array_fill(0, 34, $row)]],
            'source_state' => ['fixture' => true],
            'created_by' => $admin->id,
        ]);

        $this->expectException(ValidationException::class);
        app(CommercialCommissionClosureService::class)->approve('2026-10', 'delegations', $admin);
    }

    public function test_closure_rejects_subtotals_that_do_not_match_their_team_member(): void
    {
        CarbonImmutable::setTestNow('2026-11-19 12:00:00');
        $admin = ReportUser::query()->create([
            'name' => 'Settlement admin',
            'email' => 'settlement-subtotals@example.test',
            'password' => 'secret',
            'role' => ReportUser::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $row = [
            'delegation_name' => 'Store 1',
            'store_manager_target_key' => 'store-1',
            'store_manager_plan' => 'october_2026',
            'store_manager_status' => 'calculated',
            'store_manager_issues' => [],
            'reviews_technical_status' => 'available',
            'reviews_fetched_at' => now()->toIso8601String(),
            'store_manager_history_status' => 'verified',
            'team_roster_status' => 'verified',
            'team_member_count' => 1,
            'target_deliveries' => 10,
            'target_purchases' => 10,
            'deliveries_count' => 10,
            'individual_deliveries_count' => 10,
            'shared_deliveries_count' => 0,
            'purchases_count' => 10,
            'store_deliveries_incentive' => 100.0,
            'store_purchases_incentive' => 100.0,
            'store_reviews_adjustment' => 150.0,
            'team_components' => [
                'TEAM-1' => [
                    'delivery_incentive' => 120.0,
                    'financing' => 100.0,
                    'guarantee' => 60.0,
                    'penalty' => 0.0,
                    'subtotal' => 280.0,
                ],
            ],
            'team_subtotals' => ['TEAM-1' => 999.0],
            'total_commission' => 1349.0,
        ];
        $rows = array_map(fn (int $index): array => [
            ...$row,
            'delegation_name' => 'Store '.$index,
            'store_manager_target_key' => 'store-'.$index,
        ], range(1, 34));
        $closure = CommercialCommissionClosure::query()->create([
            'month' => '2026-10',
            'closure_scope' => CommercialCommissionClosure::SCOPE_DELEGATIONS,
            'status' => CommercialCommissionClosure::STATUS_PENDING_APPROVAL,
            'component_statuses' => array_fill_keys(array_keys(app(CommercialCommissionClosureService::class)->requiredComponents('delegations', '2026-10')), true),
            'snapshot_version' => 1,
            'formula_version' => CommercialCommissionFormulaConfigService::VERSION,
            'data_cutoff_at' => now(),
        ]);
        CommercialCommissionSnapshot::query()->create([
            'closure_id' => $closure->id,
            'month' => '2026-10',
            'version' => 1,
            'formula_version' => CommercialCommissionFormulaConfigService::VERSION,
            'data_cutoff_at' => now(),
            'payload' => [
                'delegations' => [
                    'delegation_rows' => $rows,
                    'store_manager_target_keys' => array_map(fn (int $index): string => 'store-'.$index, range(1, 34)),
                ],
            ],
            'source_state' => ['fixture' => true],
            'created_by' => $admin->id,
        ]);

        $this->expectException(ValidationException::class);
        app(CommercialCommissionClosureService::class)->approve('2026-10', 'delegations', $admin);
    }

    private function seedStoreManagerFixture(): void
    {
        SalesforceUser::query()->create([
            'salesforce_id' => 'TEAM-1',
            'name' => 'Comercial fixture',
            'is_active' => true,
        ]);
        SalesforceUser::query()->create([
            'salesforce_id' => 'TEAM-2',
            'name' => 'Comercial compartido',
            'is_active' => true,
        ]);
        StoreManagerCommissionTarget::query()->create([
            'month' => '2026-10',
            'zone' => 'Norte',
            'store_key' => 'norte-test',
            'store_name' => 'Norte Test',
            'delivery_target' => 10,
            'purchase_target' => 10,
            'source_sha256' => hash('sha256', 'fixture'),
            'source_label' => 'fixture de integración',
        ]);

        foreach (range(1, 10) as $index) {
            SalesforceOpportunity::query()->create([
                'salesforce_id' => 'DELIVERY-'.$index,
                'name' => 'Entrega '.$index,
                'stage_name' => 'Cerrada ganada',
                'record_type_name' => 'Venta',
                'owner_id' => 'TEAM-1',
                'owner_name' => 'Comercial fixture',
                'owner_is_active' => true,
                'delivery_store' => 'HR Motor Norte Test',
                'garantia_total' => 350.0,
                'financial_commission' => 900.0,
                'importe_financiado' => 30000.0,
                'cv_signed' => true,
                'cv_signed_date' => '2026-10-10',
            ]);
            SalesforceOpportunity::query()->create([
                'salesforce_id' => 'PURCHASE-'.$index,
                'name' => 'Tasación '.$index,
                'stage_name' => 'Cerrada ganada',
                'record_type_name' => 'Tasacion',
                'owner_id' => 'PURCHASE-OWNER',
                'owner_is_active' => true,
                'purchase_appraisal_id' => 'APPRAISAL-'.$index,
                'purchase_delegation_id' => 'STORE-NORTE-1',
                'purchase_delegation_name' => 'Norte Test',
                'cv_signed' => true,
                'cv_signed_date' => '2026-10-21',
            ]);
        }

        HrEmploymentDate::query()->create([
            'salesforce_user_id' => 'TEAM-1',
            'corporate_email' => 'fixture@example.test',
            'effective_date' => '2026-01-01',
            'source_label' => 'fixture',
            'source_reference' => 'fixture-hr',
        ]);
        SalesforceDelegationManagerHistory::query()->create([
            'source_key' => 'fixture-manager',
            'delegation_salesforce_id' => 'STORE-NORTE-1',
            'delegation_name' => 'Norte Test',
            'delegation_key' => 'norte-test',
            'manager_salesforce_user_id' => 'MANAGER-1',
            'manager_name' => 'Jefe fixture',
            'effective_at' => '2026-10-01 00:00:00',
            'coverage_from' => '2026-10-01 00:00:00',
            'coverage_to' => '2026-11-01 00:00:00',
            'observed_at' => '2026-10-31 23:59:00',
            'source' => 'fixture',
            'history_verified' => true,
        ]);
        CommercialCommissionRosterMember::query()->create([
            'month' => '2026-10',
            'delegation_key' => 'norte-test',
            'delegation_name' => 'Norte Test',
            'salesforce_user_id' => 'TEAM-1',
            'member_name' => 'Comercial fixture',
            'coverage_from' => '2026-10-01 00:00:00',
            'coverage_to' => '2026-11-01 00:00:00',
            'source' => 'fixture',
            'evidence_reference' => 'fixture-roster',
            'recorded_by' => 'integration-test',
            'history_verified' => true,
        ]);

        $reviews = Mockery::mock(CommercialCommissionDelegationReviewsService::class);
        $reviews->shouldReceive('forMonthAndDelegations')->zeroOrMoreTimes()->andReturn([
            'Norte Test' => [
                'reviews_count' => 1,
                'average_rating' => 4.2,
                'technical_status' => 'available',
                'fetched_at' => now()->toIso8601String(),
            ],
        ]);
        $this->app->instance(CommercialCommissionDelegationReviewsService::class, $reviews);
    }

    private function addSecondRosterMember(): void
    {
        CommercialCommissionRosterMember::query()->create([
            'month' => '2026-10',
            'delegation_key' => 'norte-test',
            'delegation_name' => 'Norte Test',
            'salesforce_user_id' => 'TEAM-2',
            'member_name' => 'Comercial compartido',
            'coverage_from' => '2026-10-01 00:00:00',
            'coverage_to' => '2026-11-01 00:00:00',
            'source' => 'fixture',
            'evidence_reference' => 'fixture-roster-shared',
            'recorded_by' => 'integration-test',
            'history_verified' => true,
        ]);
        HrEmploymentDate::query()->create([
            'salesforce_user_id' => 'TEAM-2',
            'corporate_email' => 'fixture-two@example.test',
            'effective_date' => '2026-01-01',
            'source_label' => 'fixture',
            'source_reference' => 'fixture-hr-two',
        ]);
    }

    /** @return array<string, mixed> */
    private function seedApprovedOctoberTargets(): array
    {
        $reference = app(StoreManagerCommissionTargetService::class)->approvedReferenceForMonth('2026-10');
        $this->assertNotNull($reference);

        foreach ($reference['targets'] as $target) {
            StoreManagerCommissionTarget::query()->create([
                'month' => '2026-10',
                'zone' => $target['zone'],
                'store_key' => $target['store_key'],
                'store_name' => $target['store_name'],
                'delivery_target' => $target['target_deliveries'],
                'purchase_target' => $target['target_purchases'],
                'source_sha256' => $target['target_source']['sha256'],
                'source_label' => $target['target_source']['label'],
            ]);
        }

        return $reference;
    }

    /** @param array<int, array<string, mixed>> $targets
     * @return array<string, mixed>
     */
    private function storeManagerCandidateDashboard(
        array $targets,
        ?int $reviewsCount = 1,
        ?float $averageRating = 4.2,
        float $reviewAdjustment = 150.0,
    ): array {
        $rows = array_map(fn (array $target): array => [
            'delegation_name' => $target['store_name'],
            'store_manager_target_key' => $target['store_key'],
            'store_manager_plan' => 'october_2026',
            'store_manager_status' => 'calculated',
            'store_manager_issues' => [],
            'reviews_technical_status' => 'available',
            'reviews_fetched_at' => now()->toIso8601String(),
            'store_manager_history_status' => 'verified',
            'team_roster_status' => 'verified',
            'team_member_count' => 0,
            'target_deliveries' => $target['target_deliveries'],
            'target_purchases' => $target['target_purchases'],
            'reviews_count' => $reviewsCount,
            'reviews_average_rating' => $averageRating,
            'deliveries_count' => 0,
            'individual_deliveries_count' => 0,
            'shared_deliveries_count' => 0,
            'purchases_count' => 0,
            'store_deliveries_incentive' => 0.0,
            'store_purchases_incentive' => 0.0,
            'store_reviews_adjustment' => $reviewAdjustment,
            'team_components' => [],
            'team_subtotals' => [],
            'total_commission' => max(0.0, $reviewAdjustment),
        ], $targets);

        return [
            'delegation_rows' => $rows,
            'store_manager_targets' => $targets,
            'store_manager_target_keys' => array_column($targets, 'store_key'),
        ];
    }

    /** @param array<string, mixed> $dashboard */
    private function invokeStoreManagerCandidateValidation(array $dashboard): void
    {
        $method = new \ReflectionMethod(CommercialCommissionClosureService::class, 'assertStoreManagerDashboardEconomicIntegrity');
        $method->setAccessible(true);
        $method->invoke(
            app(CommercialCommissionClosureService::class),
            $dashboard,
            '2026-10',
            CommercialCommissionFormulaConfigService::VERSION,
        );
    }
}
