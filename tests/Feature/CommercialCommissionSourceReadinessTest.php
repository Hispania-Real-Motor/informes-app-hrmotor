<?php

namespace Tests\Feature;

use App\Models\ReportSyncRun;
use App\Services\Reports\CommercialCommissions\CommercialCommissionSourceReadinessService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CommercialCommissionSourceReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_zero_record_completed_sync_is_valid_readiness(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 10:00:00');
        $this->completedRun('salesforce_opportunities', ['queried' => 0, 'stored' => 0]);
        $this->completedRun('salesforce_tasaciones', ['queried' => 0, 'stored' => 0]);

        $state = app(CommercialCommissionSourceReadinessService::class)->inspect('call_center', '2026-07', ['issues' => []]);

        $this->assertTrue($state['ready']);
        $this->assertSame('ready', data_get($state, 'components.sales.status'));
        $this->assertSame('ready', data_get($state, 'components.tasaciones.status'));
    }

    public function test_current_month_accepts_contiguous_incremental_coverage_without_future_end_date(): void
    {
        CarbonImmutable::setTestNow('2026-10-30 09:07:00');
        foreach (['salesforce_opportunities', 'salesforce_tasaciones'] as $dataset) {
            $this->completedRunFor($dataset, '2026-10-01 00:00:00', '2026-10-15 00:00:00');
            $this->completedRunFor($dataset, '2026-10-15 00:00:00', '2026-10-30 08:42:00');
        }

        $state = app(CommercialCommissionSourceReadinessService::class)->inspect('call_center', '2026-10', ['issues' => []]);

        $this->assertTrue($state['ready']);
        $this->assertSame('ready', data_get($state, 'components.sales.status'));
        $this->assertSame(
            CarbonImmutable::parse('2026-10-30 08:42:00')->toIso8601String(),
            data_get($state, 'components.sales.updated_at')
        );
    }

    public function test_current_month_accepts_a_thirty_minute_tail_using_configured_freshness(): void
    {
        CarbonImmutable::setTestNow('2026-10-30 09:07:00');
        foreach (['salesforce_opportunities', 'salesforce_tasaciones'] as $dataset) {
            $this->completedRunFor($dataset, '2026-10-01 00:00:00', '2026-10-30 08:37:00');
        }

        $state = app(CommercialCommissionSourceReadinessService::class)->inspect('call_center', '2026-10', ['issues' => []]);

        $this->assertTrue($state['ready']);
        $this->assertSame('ready', data_get($state, 'components.tasaciones.status'));
    }

    public function test_current_month_marks_an_old_final_cut_as_stale(): void
    {
        CarbonImmutable::setTestNow('2026-10-30 09:07:00');
        foreach (['salesforce_opportunities', 'salesforce_tasaciones'] as $dataset) {
            $this->completedRunFor($dataset, '2026-10-01 00:00:00', '2026-10-28 09:06:00');
        }

        $state = app(CommercialCommissionSourceReadinessService::class)->inspect('call_center', '2026-10', ['issues' => []]);

        $this->assertFalse($state['ready']);
        $this->assertSame('stale', data_get($state, 'components.sales.status'));
        $this->assertStringContainsString('último corte', data_get($state, 'components.sales.message'));
    }

    public function test_current_month_accepts_overlapping_runs_when_the_union_is_continuous(): void
    {
        CarbonImmutable::setTestNow('2026-10-30 09:07:00');
        foreach (['salesforce_opportunities', 'salesforce_tasaciones'] as $dataset) {
            $this->completedRunFor($dataset, '2026-10-01 00:00:00', '2026-10-20 00:00:00');
            $this->completedRunFor($dataset, '2026-10-15 00:00:00', '2026-10-30 08:42:00');
        }

        $state = app(CommercialCommissionSourceReadinessService::class)->inspect('call_center', '2026-10', ['issues' => []]);

        $this->assertTrue($state['ready']);
    }

    public function test_current_month_real_gap_blocks_readiness(): void
    {
        CarbonImmutable::setTestNow('2026-10-30 09:07:00');
        foreach (['salesforce_opportunities', 'salesforce_tasaciones'] as $dataset) {
            $this->completedRunFor($dataset, '2026-10-01 00:00:00', '2026-10-10 00:00:00');
            $this->completedRunFor($dataset, '2026-10-11 00:00:00', '2026-10-30 08:42:00');
        }

        $state = app(CommercialCommissionSourceReadinessService::class)->inspect('call_center', '2026-10', ['issues' => []]);

        $this->assertFalse($state['ready']);
        $this->assertStringContainsString('hueco real', data_get($state, 'components.sales.message'));
    }

    public function test_closed_month_still_requires_one_completed_run_covering_the_full_natural_month(): void
    {
        CarbonImmutable::setTestNow('2026-10-30 12:00:00');
        foreach (['salesforce_opportunities', 'salesforce_tasaciones'] as $dataset) {
            $this->completedRunFor($dataset, '2026-09-01 00:00:00', '2026-09-15 00:00:00');
            $this->completedRunFor($dataset, '2026-09-15 00:00:00', '2026-10-01 00:00:00');
        }

        $state = app(CommercialCommissionSourceReadinessService::class)->inspect('call_center', '2026-09', ['issues' => []]);

        $this->assertFalse($state['ready']);
        $this->assertStringContainsString('cubra el mes', data_get($state, 'components.sales.message'));
    }

    public function test_delegation_reviews_use_internal_endpoint_status_not_salesforce_reviews(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 10:00:00');
        $this->completedRun('salesforce_opportunities');

        $state = app(CommercialCommissionSourceReadinessService::class)->inspect('delegations', '2026-07', [
            'issues' => [],
            'delegation_rows' => [[
                'delegation_name' => 'Alicante',
                'reviews_technical_status' => 'available',
                'reviews_fetched_at' => now()->toIso8601String(),
            ]],
        ]);

        $this->assertTrue($state['ready']);
        $this->assertSame('ready', data_get($state, 'components.reviews.status'));
        $this->assertArrayNotHasKey('salesforce_reviews', $state['source_state']);
    }

    public function test_failed_internal_reviews_endpoint_blocks_delegation_preparation(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 10:00:00');
        $this->completedRun('salesforce_opportunities');

        $state = app(CommercialCommissionSourceReadinessService::class)->inspect('delegations', '2026-07', [
            'issues' => [],
            'delegation_rows' => [[
                'delegation_name' => 'Alicante',
                'reviews_technical_status' => 'unavailable',
                'reviews_fetched_at' => null,
            ]],
        ]);

        $this->assertFalse($state['ready']);
        $this->assertTrue(data_get($state, 'components.reviews.blocking'));
        $this->assertStringContainsString('endpoint interno', data_get($state, 'components.reviews.message'));
    }

    public function test_recent_available_delegation_review_is_ready(): void
    {
        $state = $this->inspectDelegationRows([$this->availableReviewRow()]);

        $this->assertTrue($state['ready']);
        $this->assertSame('ready', data_get($state, 'components.reviews.status'));
        $this->assertFalse(data_get($state, 'components.reviews.blocking'));
    }

    public function test_stale_available_delegation_review_still_blocks(): void
    {
        $state = $this->inspectDelegationRows([
            $this->availableReviewRow(['reviews_fetched_at' => '2026-08-10T09:00:00Z']),
        ]);

        $this->assertFalse($state['ready']);
        $this->assertSame('stale', data_get($state, 'components.reviews.status'));
        $this->assertTrue(data_get($state, 'components.reviews.blocking'));
    }

    public function test_economically_inert_not_applicable_delegation_does_not_require_endpoint_freshness(): void
    {
        $state = $this->inspectDelegationRows([$this->inertNotApplicableReviewRow()]);

        $this->assertTrue($state['ready']);
        $this->assertSame('ready', data_get($state, 'components.reviews.status'));
        $this->assertFalse(data_get($state, 'components.reviews.blocking'));
        $this->assertNull(data_get($state, 'components.reviews.updated_at'));
        $this->assertStringNotContainsString('caché', data_get($state, 'components.reviews.message'));
    }

    public function test_recent_available_and_inert_not_applicable_delegations_are_ready(): void
    {
        $state = $this->inspectDelegationRows([
            $this->availableReviewRow(),
            $this->inertNotApplicableReviewRow(),
        ]);

        $this->assertTrue($state['ready']);
        $this->assertSame('ready', data_get($state, 'components.reviews.status'));
        $this->assertFalse(data_get($state, 'components.reviews.blocking'));
        $this->assertNotNull(data_get($state, 'components.reviews.updated_at'));
    }

    #[DataProvider('materialNotApplicableRows')]
    public function test_material_not_applicable_delegation_still_blocks(string $field, mixed $value): void
    {
        $state = $this->inspectDelegationRows([
            $this->inertNotApplicableReviewRow([$field => $value]),
        ]);

        $this->assertFalse($state['ready']);
        $this->assertSame('error', data_get($state, 'components.reviews.status'));
        $this->assertTrue(data_get($state, 'components.reviews.blocking'));
    }

    public static function materialNotApplicableRows(): array
    {
        return [
            'configured target' => ['target_deliveries', 1],
            'delivery activity' => ['deliveries_count', 1],
            'profitability activity' => ['rentability_total', 0.01],
            'objective reached' => ['objective_reached', true],
            'commission before reviews' => ['prima_final_before_reviews', 0.01],
            'review commission' => ['reviews_commission_amount', 0.01],
            'total commission' => ['total_commission', 0.01],
        ];
    }

    #[DataProvider('blockingReviewStatuses')]
    public function test_real_or_unknown_review_error_blocks_even_for_inert_delegation(string $status): void
    {
        $state = $this->inspectDelegationRows([
            $this->inertNotApplicableReviewRow(['reviews_technical_status' => $status]),
        ]);

        $this->assertFalse($state['ready']);
        $this->assertSame('error', data_get($state, 'components.reviews.status'));
        $this->assertTrue(data_get($state, 'components.reviews.blocking'));
    }

    public static function blockingReviewStatuses(): array
    {
        return [
            'not configured' => ['not_configured'],
            'transport error' => ['transport_error'],
            'remote error' => ['remote_error'],
            'unavailable' => ['unavailable'],
            'unknown status' => ['unexpected_status'],
        ];
    }

    public function test_no_delegations_keeps_zero_queries_as_ready(): void
    {
        $state = $this->inspectDelegationRows([]);

        $this->assertTrue($state['ready']);
        $this->assertSame('ready', data_get($state, 'components.reviews.status'));
        $this->assertFalse(data_get($state, 'components.reviews.blocking'));
    }

    public function test_legacy_manager_coverage_warning_is_hidden_without_losing_source_warnings(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 10:00:00');
        $this->completedRun('salesforce_opportunities');
        $legacyWarning = 'El responsable al cierre de Alicante está confirmado, pero no existe histórico suficiente para verificar si hubo rotaciones durante July de 2026.';
        $sourceWarning = 'La caché del endpoint interno no tiene una verificación reciente.';

        $state = app(CommercialCommissionSourceReadinessService::class)->inspect('delegations', '2026-07', [
            'issues' => [],
            'warnings' => [$legacyWarning, $sourceWarning],
            'delegation_rows' => [[
                'delegation_name' => 'Alicante',
                'store_manager_distinct_count' => 1,
                'store_manager_alert' => $legacyWarning,
                'reviews_technical_status' => 'available',
                'reviews_fetched_at' => now()->toIso8601String(),
            ]],
        ]);

        $this->assertNotContains($legacyWarning, $state['warnings']);
        $this->assertContains($sourceWarning, $state['warnings']);
    }

    public function test_manager_rotation_warning_is_kept_only_above_two_distinct_managers(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 10:00:00');
        $this->completedRun('salesforce_opportunities');
        $rotationWarning = 'Alicante ha tenido 3 jefes de tienda demostrados durante julio de 2026. Revisar la asignación de la comisión.';

        $state = app(CommercialCommissionSourceReadinessService::class)->inspect('delegations', '2026-07', [
            'issues' => [],
            'warnings' => [$rotationWarning],
            'delegation_rows' => [[
                'delegation_name' => 'Alicante',
                'store_manager_distinct_count' => 3,
                'store_manager_alert' => $rotationWarning,
                'reviews_technical_status' => 'available',
                'reviews_fetched_at' => now()->toIso8601String(),
            ]],
        ]);

        $this->assertContains($rotationWarning, $state['warnings']);
        $this->assertTrue($state['ready']);
    }

    private function completedRun(string $dataset, array $stats = ['queried' => 1, 'stored' => 1]): void
    {
        $this->completedRunFor($dataset, '2026-07-01 00:00:00', '2026-08-01 00:00:00', $stats);
    }

    private function completedRunFor(string $dataset, string $periodStart, string $periodEnd, array $stats = ['queried' => 1, 'stored' => 1]): void
    {
        ReportSyncRun::query()->create([
            'dataset' => $dataset,
            'source' => 'salesforce',
            'status' => 'completed',
            'period_start_at' => $periodStart,
            'period_end_at' => $periodEnd,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
            'timezone' => config('app.timezone'),
            'stats' => $stats,
        ]);
    }

    private function inspectDelegationRows(array $rows): array
    {
        CarbonImmutable::setTestNow('2026-08-10 10:00:00');
        $this->completedRun('salesforce_opportunities');

        return app(CommercialCommissionSourceReadinessService::class)->inspect('delegations', '2026-07', [
            'issues' => [],
            'delegation_rows' => $rows,
        ]);
    }

    private function availableReviewRow(array $overrides = []): array
    {
        return array_replace($this->inertNotApplicableReviewRow(), [
            'delegation_name' => 'Alicante',
            'reviews_technical_status' => 'available',
            'reviews_fetched_at' => '2026-08-10T10:00:00Z',
        ], $overrides);
    }

    private function inertNotApplicableReviewRow(array $overrides = []): array
    {
        return array_replace([
            'delegation_name' => 'Delegación residual',
            'target_deliveries' => 0,
            'deliveries_count' => 0,
            'objective_reached' => false,
            'rentability_total' => 0.0,
            'prima_final_before_reviews' => 0.0,
            'reviews_commission_amount' => 0.0,
            'total_commission' => 0.0,
            'reviews_technical_status' => 'not_applicable',
            'reviews_fetched_at' => null,
        ], $overrides);
    }
}
