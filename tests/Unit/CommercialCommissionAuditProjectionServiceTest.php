<?php

namespace Tests\Unit;

use App\Services\Reports\CommercialCommissions\CommercialCommissionAuditProjectionService;
use App\Services\Reports\CommercialCommissions\CommercialCommissionClosureService;
use Carbon\CarbonImmutable;
use Mockery;
use Tests\TestCase;

class CommercialCommissionAuditProjectionServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_only_selected_live_scope_is_built_without_details_and_projected_final_only(): void
    {
        $closures = Mockery::mock(CommercialCommissionClosureService::class);
        $closures->shouldReceive('statuses')->once()->with('2026-07')->andReturn([
            'delegations' => ['month' => '2026-07', 'status' => 'provisional', 'approved_by' => null, 'approved_at' => null],
            'commercials' => ['month' => '2026-07', 'status' => 'pending_approval', 'approved_by' => null, 'approved_at' => null],
        ]);
        $closures->shouldReceive('candidateOrDefinitiveSnapshot')->once()->with('2026-07', 'commercials')->andReturnNull();
        $closures->shouldReceive('buildLiveDashboard')->once()->with('2026-07', 'commercials', false)->andReturn([
            'summary_rows' => [[
                'commercial_name' => 'Ana', 'final_commission' => 125.5,
                'details' => [['opportunity_id' => '006-secret']], 'financing_total' => 999,
            ]],
            'diagnostics' => ['secret' => true],
        ]);

        $projection = (new CommercialCommissionAuditProjectionService($closures))->build('2026-07', 'commercials');

        $this->assertSame([['name' => 'Ana', 'final_total' => 125.5, 'alert' => null]], data_get($projection, 'scope.rows'));
        $encoded = json_encode($projection, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('006-secret', $encoded);
        $this->assertStringNotContainsString('diagnostics', $encoded);
        $this->assertStringNotContainsString('financing_total', $encoded);
        $this->assertSame([
            'status' => 'pending_approval',
            'label' => 'Pendiente de aprobación',
            'variant' => 'pending',
            'approved_by' => null,
            'approved_at' => null,
        ], data_get($projection, 'scope.status'));
    }

    public function test_definitive_scope_uses_snapshot_and_never_builds_live_dashboard(): void
    {
        $closures = Mockery::mock(CommercialCommissionClosureService::class);
        $closures->shouldReceive('statuses')->once()->andReturn([
            'delegations' => ['month' => '2026-07', 'status' => 'definitive', 'approved_by' => ['name' => 'Director'], 'approved_at' => '2026-08-10T10:00:00+02:00'],
        ]);
        $closures->shouldReceive('candidateOrDefinitiveSnapshot')->once()->with('2026-07', 'delegations')->andReturn([
            'delegations' => ['delegation_rows' => [[
                'delegation_name' => 'Alicante', 'total_commission' => 3100,
                'store_manager_salesforce_user_id' => '005SECRET', 'store_manager_name' => 'Pedro García',
                'store_manager_distinct_count' => 3, 'store_manager_alert' => 'Alicante ha tenido 3 jefes de tienda demostrados durante julio de 2026.',
                'store_manager_evidence_count' => 9, 'evidence_reference' => 'ACTA-SECRETA',
            ]]],
        ]);
        $closures->shouldNotReceive('buildLiveDashboard');

        $projection = (new CommercialCommissionAuditProjectionService($closures))->build('2026-07', 'delegations');

        $this->assertSame(3100.0, data_get($projection, 'scope.rows.0.final_total'));
        $this->assertSame('Pedro García', data_get($projection, 'scope.rows.0.manager_name'));
        $this->assertStringContainsString('3 jefes de tienda', data_get($projection, 'scope.rows.0.alert'));
        $this->assertSame([
            'status' => 'definitive',
            'label' => 'Definitivo',
            'variant' => 'group',
            'approved_by' => ['name' => 'Director'],
            'approved_at' => '2026-08-10T10:00:00+02:00',
        ], data_get($projection, 'scope.status'));
        $encoded = json_encode($projection, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('005SECRET', $encoded);
        $this->assertStringNotContainsString('ACTA-SECRETA', $encoded);
        $this->assertStringNotContainsString('store_manager_evidence_count', $encoded);
    }

    public function test_legacy_definitive_snapshot_without_manager_name_does_not_rebuild_or_expose_obsolete_alert(): void
    {
        $closures = Mockery::mock(CommercialCommissionClosureService::class);
        $closures->shouldReceive('statuses')->once()->andReturn([
            'delegations' => ['month' => '2026-07', 'status' => 'definitive', 'approved_by' => null, 'approved_at' => null],
        ]);
        $closures->shouldReceive('candidateOrDefinitiveSnapshot')->once()->andReturn([
            'delegations' => ['delegation_rows' => [[
                'delegation_name' => 'Alicante', 'total_commission' => 3100,
                'store_manager_alert' => 'El histórico no es verificable.',
            ]]],
        ]);
        $closures->shouldNotReceive('buildLiveDashboard');

        $projection = (new CommercialCommissionAuditProjectionService($closures))->build('2026-07', 'delegations');

        $this->assertNull(data_get($projection, 'scope.rows.0.manager_name'));
        $this->assertNull(data_get($projection, 'scope.rows.0.alert'));
        $this->assertSame([], data_get($projection, 'scope.alerts'));
    }

    public function test_selected_scope_failure_is_reported_without_a_fake_zero_result(): void
    {
        $closures = Mockery::mock(CommercialCommissionClosureService::class);
        $closures->shouldReceive('statuses')->once()->andReturn([
            'financials' => ['month' => '2026-07', 'status' => 'provisional', 'approved_by' => null, 'approved_at' => null],
            'commercials' => ['month' => '2026-07', 'status' => 'provisional', 'approved_by' => null, 'approved_at' => null],
        ]);
        $closures->shouldReceive('candidateOrDefinitiveSnapshot')->once()->with('2026-07', 'financials')->andReturnNull();
        $closures->shouldReceive('buildLiveDashboard')->once()->with('2026-07', 'financials', false)->andThrow(new \RuntimeException('sensitive failure'));
        $closures->shouldNotReceive('buildLiveDashboard')->with('2026-07', 'commercials', false);

        $projection = (new CommercialCommissionAuditProjectionService($closures))->build('2026-07', 'financials');

        $this->assertFalse(data_get($projection, 'scope.available'));
        $this->assertSame([], data_get($projection, 'scope.rows'));
        $this->assertStringContainsString('Bloque no disponible', data_get($projection, 'scope.warning'));
        $this->assertStringNotContainsString('sensitive failure', json_encode($projection, JSON_THROW_ON_ERROR));
    }

    public function test_auditor_can_select_july_and_available_months_are_localized_without_building_other_scopes(): void
    {
        CarbonImmutable::setTestNow('2026-08-31 12:00:00');
        $closures = Mockery::mock(CommercialCommissionClosureService::class);
        $closures->shouldReceive('statuses')->once()->with('2026-07')->andReturn([
            'commercials' => ['month' => '2026-07', 'status' => 'provisional', 'approved_by' => null, 'approved_at' => null],
            'delegations' => ['month' => '2026-07', 'status' => 'provisional', 'approved_by' => null, 'approved_at' => null],
        ]);
        $closures->shouldReceive('candidateOrDefinitiveSnapshot')->once()->with('2026-07', 'delegations')->andReturnNull();
        $closures->shouldReceive('buildLiveDashboard')->once()->with('2026-07', 'delegations', false)->andReturn(['delegation_rows' => []]);
        $closures->shouldNotReceive('buildLiveDashboard')->with('2026-07', 'commercials', false);

        $projection = (new CommercialCommissionAuditProjectionService($closures))->build('2026-07', 'delegations');

        $this->assertSame('2026-07', $projection['month']);
        $this->assertSame('delegations', $projection['selected_scope']);
        $this->assertSame([
            ['value' => '2026-08', 'label' => 'agosto de 2026'],
            ['value' => '2026-07', 'label' => 'julio de 2026'],
        ], $projection['available_months']);
    }

    public function test_auditor_month_is_limited_to_july_2026_and_current_month(): void
    {
        CarbonImmutable::setTestNow('2026-08-31 12:00:00');

        foreach ([['2026-06', '2026-07'], ['2026-09', '2026-08']] as [$requested, $expected]) {
            $closures = Mockery::mock(CommercialCommissionClosureService::class);
            $closures->shouldReceive('statuses')->once()->with($expected)->andReturn([
                'commercials' => ['month' => $expected, 'status' => 'provisional', 'approved_by' => null, 'approved_at' => null],
            ]);
            $closures->shouldReceive('candidateOrDefinitiveSnapshot')->once()->with($expected, 'commercials')->andReturnNull();
            $closures->shouldReceive('buildLiveDashboard')->once()->with($expected, 'commercials', false)->andReturn(['summary_rows' => []]);

            $projection = (new CommercialCommissionAuditProjectionService($closures))->build($requested, 'commercials');
            $this->assertSame($expected, $projection['month']);
        }
    }

    public function test_closure_statuses_are_projected_with_spanish_labels_and_existing_visual_variants(): void
    {
        $closures = Mockery::mock(CommercialCommissionClosureService::class);
        $closures->shouldReceive('statuses')->once()->andReturn([
            'commercials' => ['month' => '2026-07', 'status' => 'provisional', 'approved_by' => null, 'approved_at' => null],
            'delegations' => ['month' => '2026-07', 'status' => 'pending_approval', 'approved_by' => null, 'approved_at' => null],
            'area_manager' => ['month' => '2026-07', 'status' => 'definitive', 'approved_by' => null, 'approved_at' => null],
            'financials' => ['month' => '2026-07', 'status' => 'reopened', 'approved_by' => null, 'approved_at' => null],
        ]);
        $closures->shouldReceive('candidateOrDefinitiveSnapshot')->once()->andReturnNull();
        $closures->shouldReceive('buildLiveDashboard')->once()->andReturn(['summary_rows' => []]);

        $projection = (new CommercialCommissionAuditProjectionService($closures))->build('2026-07', 'commercials');

        $this->assertSame('Cálculo en curso', data_get($projection, 'scope_statuses.commercials.label'));
        $this->assertSame('Pendiente de aprobación', data_get($projection, 'scope_statuses.delegations.label'));
        $this->assertSame('Definitivo', data_get($projection, 'scope_statuses.area_manager.label'));
        $this->assertSame('group', data_get($projection, 'scope_statuses.area_manager.variant'));
        $this->assertSame('Reabierto', data_get($projection, 'scope_statuses.financials.label'));
        $this->assertSame('pending', data_get($projection, 'scope_statuses.financials.variant'));
    }
}
