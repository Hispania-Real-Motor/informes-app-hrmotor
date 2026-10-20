<?php

namespace Tests\Feature;

use App\Models\CommercialCommissionClosure;
use App\Models\CommercialCommissionClosureEvent;
use App\Models\CommercialCommissionSnapshot;
use App\Models\ReportUser;
use App\Models\SalesforceDelegationManagerHistory;
use App\Services\Reports\AreaManagerCommissions\AreaManagerCommissionDashboardService;
use App\Services\Reports\CallCenterCommissions\CallCenterCommissionDashboardService;
use App\Services\Reports\CommercialCommissions\CommercialCommissionClosureService;
use App\Services\Reports\CommercialCommissions\CommercialCommissionDashboardService;
use App\Services\Reports\CommercialCommissions\CommercialCommissionSourceReadinessService;
use App\Services\Reports\CommercialCommissions\CommissionMonthResolver;
use App\Services\Reports\ContactCenterCommissions\ContactCenterCommissionDashboardService;
use App\Services\Reports\FinancialCommissions\FinancialCommissionDashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class CommissionMonthClosureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $readiness = Mockery::mock(CommercialCommissionSourceReadinessService::class);
        $readiness->shouldReceive('inspect')->andReturnUsing(function (string $scope): array {
            $components = collect(CommercialCommissionClosureService::REQUIRED_COMPONENTS_BY_SCOPE[$scope])
                ->mapWithKeys(fn (string $key): array => [$key => [
                    'label' => $key, 'status' => 'ready', 'blocking' => false,
                    'updated_at' => now()->toIso8601String(), 'checked_at' => now()->toIso8601String(), 'message' => 'Fuente de prueba lista.',
                ]])->all();

            return ['ready' => true, 'components' => $components, 'blocking' => [], 'warnings' => [], 'source_state' => $components];
        });
        $this->app->instance(CommercialCommissionSourceReadinessService::class, $readiness);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_todas_las_pestanas_respetan_el_mismo_mes_incluido_el_actual(): void
    {
        CarbonImmutable::setTestNow('2026-08-05 10:00:00');

        foreach (['2026-08', '2026-06', '2026-05'] as $month) {
            $resolved = app(CommissionMonthResolver::class)->resolve($month)->format('Y-m');
            $this->assertSame($month, $resolved);
            $this->assertSame($month, app(CommercialCommissionDashboardService::class)->build($month, false, false, false)['month']);
            $this->assertSame($month, app(CallCenterCommissionDashboardService::class)->build($month, includeDetails: false)['month']);
            $this->assertSame($month, app(ContactCenterCommissionDashboardService::class)->build($month, includeDetails: false)['month']);
            $this->assertSame($month, app(AreaManagerCommissionDashboardService::class)->build($month)['month']);
            $this->assertSame($month, app(FinancialCommissionDashboardService::class)->build($month)['month']);
        }
    }

    public function test_matriz_http_mantiene_mes_y_conciliacion_en_las_seis_pestanas(): void
    {
        CarbonImmutable::setTestNow('2026-08-05 10:00:00');

        foreach (['summary', 'delegations', 'call-center', 'contact-center', 'area-manager', 'financials'] as $tab) {
            $this->get('/informes/comisiones-comerciales?month=2026-06&tab='.$tab)
                ->assertOk()
                ->assertSee('value="2026-06"', false)
                ->assertSee('Conciliacion del universo');
        }
    }

    public function test_mes_actual_es_provisional_y_no_puede_cerrarse(): void
    {
        CarbonImmutable::setTestNow('2026-08-05 10:00:00');
        $admin = $this->user(ReportUser::ROLE_ADMIN);
        $this->assertSame('provisional', app(CommercialCommissionClosureService::class)->status('2026-08')['status']);

        $this->actingAsReportUser($admin)
            ->post('/informes/comisiones-comerciales/closure/prepare', $this->closurePayload('2026-08'))
            ->assertSessionHasErrors('month');

        $this->assertDatabaseMissing('commercial_commission_closures', ['month' => '2026-08']);
    }

    public function test_administrador_prepara_y_direccion_aprueba_fotografia_definitiva_auditable(): void
    {
        CarbonImmutable::setTestNow('2026-08-05 10:00:00');
        $director = $this->user(ReportUser::ROLE_DIRECTOR);
        $admin = $this->user(ReportUser::ROLE_ADMIN);
        ReportUser::query()->create([
            'name' => 'Manager Norte',
            'email' => 'manager-norte@example.com',
            'password' => 'secret123',
            'role' => ReportUser::ROLE_AREA_MANAGER,
            'area_zone' => 'north',
            'is_active' => true,
        ]);

        $response = $this->actingAsReportUser($admin)
            ->post('/informes/comisiones-comerciales/closure/prepare', $this->closurePayload('2026-06'));
        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertNotSame('/informes/leads', parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));
        $this->assertDatabaseHas('commercial_commission_closures', [
            'month' => '2026-06',
            'status' => CommercialCommissionClosure::STATUS_PENDING_APPROVAL,
        ]);

        $this->actingAsReportUser($director)
            ->post('/informes/comisiones-comerciales/closure/approve', ['month' => '2026-06', 'closure_scope' => CommercialCommissionClosure::SCOPE_COMMERCIALS])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('commercial_commission_closures', [
            'month' => '2026-06',
            'status' => CommercialCommissionClosure::STATUS_DEFINITIVE,
            'approved_by' => $director->id,
            'snapshot_version' => 1,
        ]);
        $this->assertSame(1, CommercialCommissionSnapshot::query()->where('month', '2026-06')->count());
        $this->assertSame(
            '2026-06',
            data_get(CommercialCommissionSnapshot::query()->where('month', '2026-06')->firstOrFail()->payload, 'commercials.month')
        );
        $this->assertSame(['prepared', 'approved'], CommercialCommissionClosureEvent::query()->orderBy('id')->pluck('action')->all());
    }

    public function test_solo_direccion_o_administrador_pueden_aprobar_y_reabrir(): void
    {
        CarbonImmutable::setTestNow('2026-08-05 10:00:00');
        $viewer = $this->user(ReportUser::ROLE_VIEWER);

        $this->actingAsReportUser($viewer)
            ->post('/informes/comisiones-comerciales/closure/approve', ['month' => '2026-06'])
            ->assertRedirect();
        $this->actingAsReportUser($viewer)
            ->post('/informes/comisiones-comerciales/closure/reopen', ['month' => '2026-06', 'reason' => 'Corrección auditada'])
            ->assertRedirect();
    }

    public function test_prepare_is_admin_only_and_unconfigured_scopes_keep_legacy_approval_and_reopening(): void
    {
        CarbonImmutable::setTestNow('2026-08-05 10:00:00');
        $admin = $this->user(ReportUser::ROLE_ADMIN);
        $director = $this->user(ReportUser::ROLE_DIRECTOR);
        $auditor = $this->user(ReportUser::ROLE_COMMISSION_AUDITOR);

        $this->actingAsReportUser($director)->post('/informes/comisiones-comerciales/closure/prepare', $this->closurePayload('2026-06'))->assertForbidden();
        $this->actingAsReportUser($auditor)->post('/informes/comisiones-comerciales/closure/prepare', $this->closurePayload('2026-06'))->assertForbidden();
        $this->actingAsReportUser($auditor)->post('/informes/comisiones-comerciales/closure/approve', ['month' => '2026-06', 'closure_scope' => 'commercials'])->assertForbidden();
        $this->actingAsReportUser($admin)->post('/informes/comisiones-comerciales/closure/prepare', $this->closurePayload('2026-06'))->assertSessionHasNoErrors();
        $this->actingAsReportUser($director)->post('/informes/comisiones-comerciales/closure/approve', ['month' => '2026-06', 'closure_scope' => 'commercials'])->assertSessionHasNoErrors();
        $this->actingAsReportUser($auditor)->post('/informes/comisiones-comerciales/closure/reopen', ['month' => '2026-06', 'closure_scope' => 'commercials', 'reason' => 'Intento no autorizado'])->assertForbidden();
        $this->actingAsReportUser($director)->post('/informes/comisiones-comerciales/closure/reopen', ['month' => '2026-06', 'closure_scope' => 'commercials', 'reason' => 'Revisión ordenada por Dirección'])->assertSessionHasNoErrors();
        $this->actingAsReportUser($admin)->post('/informes/comisiones-comerciales/closure/prepare', $this->closurePayload('2026-06'))->assertSessionHasNoErrors();
        $this->actingAsReportUser($admin)->post('/informes/comisiones-comerciales/closure/approve', ['month' => '2026-06', 'closure_scope' => 'commercials'])->assertSessionHasNoErrors();
        $this->actingAsReportUser($admin)->post('/informes/comisiones-comerciales/closure/reopen', ['month' => '2026-06', 'closure_scope' => 'commercials', 'reason' => 'Reapertura administrativa auditada'])->assertSessionHasNoErrors();
    }

    public function test_los_cierres_de_comerciales_delegaciones_y_area_manager_son_independientes(): void
    {
        CarbonImmutable::setTestNow('2026-08-05 10:00:00');
        $director = $this->user(ReportUser::ROLE_DIRECTOR);
        $service = app(CommercialCommissionClosureService::class);
        $components = array_fill_keys(CommercialCommissionClosureService::REQUIRED_COMPONENTS, true);

        $service->prepare('2026-06', CommercialCommissionClosure::SCOPE_COMMERCIALS, $components, $director);
        $service->approve('2026-06', CommercialCommissionClosure::SCOPE_COMMERCIALS, $director);
        $service->prepare('2026-06', CommercialCommissionClosure::SCOPE_DELEGATIONS, $components, $director);

        $this->assertSame('definitive', $service->status('2026-06', CommercialCommissionClosure::SCOPE_COMMERCIALS)['status']);
        $this->assertSame('pending_approval', $service->status('2026-06', CommercialCommissionClosure::SCOPE_DELEGATIONS)['status']);
        $this->assertSame('pending_approval', $service->status('2026-06', CommercialCommissionClosure::SCOPE_AREA_MANAGER)['status']);
        $this->assertSame(2, CommercialCommissionSnapshot::query()->where('month', '2026-06')->count());

        $service->reopen('2026-06', CommercialCommissionClosure::SCOPE_COMMERCIALS, 'Corrección auditada de comerciales', $director);

        $this->assertSame('reopened', $service->status('2026-06', CommercialCommissionClosure::SCOPE_COMMERCIALS)['status']);
        $this->assertSame('pending_approval', $service->status('2026-06', CommercialCommissionClosure::SCOPE_DELEGATIONS)['status']);
    }

    public function test_area_manager_definitivo_lee_el_snapshot_para_direccion_y_zona_restringida(): void
    {
        $director = $this->user(ReportUser::ROLE_DIRECTOR);
        $manager = ReportUser::query()->create([
            'name' => 'Area Norte', 'email' => 'area-norte@example.test', 'password' => 'secret123',
            'role' => ReportUser::ROLE_AREA_MANAGER, 'area_zone' => 'north', 'is_active' => true,
        ]);
        $closure = CommercialCommissionClosure::query()->create([
            'month' => '2026-06', 'closure_scope' => CommercialCommissionClosure::SCOPE_AREA_MANAGER,
            'status' => CommercialCommissionClosure::STATUS_DEFINITIVE, 'snapshot_version' => 1,
            'formula_version' => 'test', 'data_cutoff_at' => now(),
        ]);
        CommercialCommissionSnapshot::query()->create([
            'closure_id' => $closure->id, 'month' => '2026-06', 'version' => 1, 'formula_version' => 'test',
            'data_cutoff_at' => now(), 'created_at' => now(), 'payload' => [
                'area_manager' => ['month' => '2026-06', 'summary_rows' => [['manager_key' => 'frozen-global', 'manager_name' => 'Congelado global', 'final_total' => 111, 'observations' => '', 'detail_rows' => [], 'kpi_summaries' => [], 'incidents' => []]], 'diagnostics' => [], 'global_incidents' => []],
                'area_manager_by_zone' => ['north' => ['month' => '2026-06', 'summary_rows' => [['manager_key' => 'frozen-north', 'manager_name' => 'Congelado norte', 'final_total' => 222, 'observations' => '', 'detail_rows' => [], 'kpi_summaries' => [], 'incidents' => []]], 'diagnostics' => [], 'global_incidents' => []]],
                'formula_settings' => [],
            ],
        ]);

        DB::table('salesforce_opportunities')->insert(['salesforce_id' => '006-live-change', 'name' => 'Cambio posterior', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAsReportUser($director)->get('/informes/comisiones-comerciales?tab=area-manager&month=2026-06')
            ->assertOk()
            ->assertViewHas('areaManagerDashboard', fn (array $dashboard): bool => data_get($dashboard, 'summary_rows.0.final_total') === 111);
        $this->actingAsReportUser($manager)->get('/informes/comisiones-comerciales?tab=area-manager&month=2026-06')
            ->assertOk()
            ->assertViewHas('areaManagerDashboard', fn (array $dashboard): bool => data_get($dashboard, 'summary_rows.0.final_total') === 222);
    }

    public function test_legacy_closure_and_audits_survive_while_scoped_closures_are_unique(): void
    {
        $legacy = CommercialCommissionClosure::query()->create(['month' => '2026-06', 'closure_scope' => CommercialCommissionClosure::SCOPE_LEGACY, 'status' => 'definitive']);
        CommercialCommissionSnapshot::query()->create(['closure_id' => $legacy->id, 'month' => '2026-06', 'version' => 1, 'formula_version' => 'legacy', 'data_cutoff_at' => now(), 'payload' => ['legacy' => true], 'created_at' => now()]);
        CommercialCommissionClosureEvent::query()->create(['closure_id' => $legacy->id, 'action' => 'approved', 'to_status' => 'definitive', 'created_at' => now()]);

        foreach (CommercialCommissionClosure::LEGACY_SCOPES as $scope) {
            CommercialCommissionClosure::query()->create(['month' => '2026-06', 'closure_scope' => $scope, 'status' => 'pending_approval']);
        }

        $this->assertSame(4, CommercialCommissionClosure::query()->where('month', '2026-06')->count());
        $this->assertSame(['legacy' => true], $legacy->snapshots()->firstOrFail()->payload);
        $this->assertSame(1, CommercialCommissionClosureEvent::query()->where('closure_id', $legacy->id)->count());
        $this->expectException(UniqueConstraintViolationException::class);
        CommercialCommissionClosure::query()->create(['month' => '2026-06', 'closure_scope' => CommercialCommissionClosure::SCOPE_COMMERCIALS, 'status' => 'pending_approval']);
    }

    public function test_status_endpoint_exposes_all_scoped_closures_without_legacy_alias(): void
    {
        $director = $this->user(ReportUser::ROLE_DIRECTOR);

        $this->actingAsReportUser($director)
            ->getJson('/informes/comisiones-comerciales/data/closure?month=2026-06')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonStructure(['closures' => ['commercials', 'delegations', 'area_manager']])
            ->assertJsonMissingPath('closures.financials')
            ->assertJsonMissing(['closure']);
    }

    public function test_status_endpoint_exposes_six_scopes_from_july_2026(): void
    {
        CarbonImmutable::setTestNow('2026-08-05 10:00:00');
        $director = $this->user(ReportUser::ROLE_DIRECTOR);

        $this->actingAsReportUser($director)
            ->getJson('/informes/comisiones-comerciales/data/closure?month=2026-07')
            ->assertOk()
            ->assertJsonStructure(['closures' => ['commercials', 'delegations', 'area_manager', 'financials', 'call_center', 'contact_center']]);
    }

    public function test_six_scopes_keep_independent_states_from_july(): void
    {
        CommercialCommissionClosure::query()->create(['month' => '2026-07', 'closure_scope' => 'financials', 'status' => 'definitive']);
        CommercialCommissionClosure::query()->create(['month' => '2026-07', 'closure_scope' => 'call_center', 'status' => 'reopened']);
        CommercialCommissionClosure::query()->create(['month' => '2026-07', 'closure_scope' => 'contact_center', 'status' => 'pending_approval']);
        $statuses = app(CommercialCommissionClosureService::class)->statuses('2026-07');

        $this->assertSame('definitive', $statuses['financials']['status']);
        $this->assertSame('reopened', $statuses['call_center']['status']);
        $this->assertSame('pending_approval', $statuses['contact_center']['status']);
        $this->assertSame('pending_approval', $statuses['commercials']['status']);
    }

    public function test_extended_scopes_cannot_be_mutated_before_july_2026(): void
    {
        CarbonImmutable::setTestNow('2026-08-05 10:00:00');
        $admin = $this->user(ReportUser::ROLE_ADMIN);
        $service = app(CommercialCommissionClosureService::class);

        foreach ([CommercialCommissionClosure::SCOPE_FINANCIALS, CommercialCommissionClosure::SCOPE_CALL_CENTER, CommercialCommissionClosure::SCOPE_CONTACT_CENTER] as $scope) {
            try {
                $service->prepare('2026-06', $scope, array_fill_keys(array_keys($service->requiredComponents($scope)), true), $admin);
                $this->fail('El scope extendido no debe aceptar junio de 2026.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('closure_scope', $exception->errors());
            }

            CommercialCommissionClosure::query()->create([
                'month' => '2026-06',
                'closure_scope' => $scope,
                'status' => CommercialCommissionClosure::STATUS_PENDING_APPROVAL,
                'component_statuses' => array_fill_keys(array_keys($service->requiredComponents($scope)), true),
            ]);

            foreach (['approve', 'reopen'] as $operation) {
                try {
                    $operation === 'approve'
                        ? $service->approve('2026-06', $scope, $admin)
                        : $service->reopen('2026-06', $scope, 'Reapertura histórica no permitida', $admin);
                    $this->fail(sprintf('%s no debe aceptar un scope extendido anterior a julio de 2026.', $operation));
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('closure_scope', $exception->errors());
                }
            }
        }
    }

    public function test_call_center_snapshot_always_uses_the_canonical_natural_month_dashboard(): void
    {
        CarbonImmutable::setTestNow('2026-08-05 10:00:00');
        $admin = $this->user(ReportUser::ROLE_ADMIN);
        $callCenter = Mockery::mock(CallCenterCommissionDashboardService::class);
        $callCenter->shouldReceive('build')
            ->once()
            ->with('2026-07', null, null, true)
            ->andReturn([
                'month' => '2026-07',
                'ready' => true,
                'issues' => [],
                'warnings' => [],
                'summary_rows' => [['agent_name' => 'Canónico', 'final_total' => 500]],
            ]);
        $this->app->instance(CallCenterCommissionDashboardService::class, $callCenter);
        $service = app(CommercialCommissionClosureService::class);
        $components = array_fill_keys(array_keys($service->requiredComponents(CommercialCommissionClosure::SCOPE_CALL_CENTER)), true);

        $service->prepare('2026-07', CommercialCommissionClosure::SCOPE_CALL_CENTER, $components, $admin);
        $service->approve('2026-07', CommercialCommissionClosure::SCOPE_CALL_CENTER, $admin);

        $snapshot = $service->definitiveSnapshot('2026-07', CommercialCommissionClosure::SCOPE_CALL_CENTER);
        $this->assertSame(500, data_get($snapshot, 'call_center.summary_rows.0.final_total'));
    }

    public function test_extended_scopes_create_independent_snapshots_and_reapproval_increments_version(): void
    {
        CarbonImmutable::setTestNow('2026-08-05 10:00:00');
        $admin = $this->user(ReportUser::ROLE_ADMIN);
        $director = $this->user(ReportUser::ROLE_DIRECTOR);
        $this->app->instance(FinancialCommissionDashboardService::class, $this->dashboardMock(FinancialCommissionDashboardService::class, 'final_commission'));
        $this->app->instance(CallCenterCommissionDashboardService::class, $this->dashboardMock(CallCenterCommissionDashboardService::class, 'final_total'));
        $this->app->instance(ContactCenterCommissionDashboardService::class, $this->dashboardMock(ContactCenterCommissionDashboardService::class, 'final_total'));
        $service = app(CommercialCommissionClosureService::class);

        foreach ([CommercialCommissionClosure::SCOPE_FINANCIALS, CommercialCommissionClosure::SCOPE_CALL_CENTER, CommercialCommissionClosure::SCOPE_CONTACT_CENTER] as $scope) {
            $components = array_fill_keys(array_keys($service->requiredComponents($scope)), true);
            $service->prepare('2026-07', $scope, $components, $admin);
            $service->approve('2026-07', $scope, $scope === CommercialCommissionClosure::SCOPE_CALL_CENTER ? $director : $admin);
            $this->assertSame(1, $service->status('2026-07', $scope)['snapshot_version']);
            $service->reopen('2026-07', $scope, 'Reapertura controlada para prueba', $admin);
            $closureId = CommercialCommissionClosure::query()->where(['month' => '2026-07', 'closure_scope' => $scope])->value('id');
            $this->assertSame(1, CommercialCommissionSnapshot::query()->where('closure_id', $closureId)->count());
            $service->prepare('2026-07', $scope, $components, $admin);
            $service->approve('2026-07', $scope, $admin);
            $this->assertSame(2, $service->status('2026-07', $scope)['snapshot_version']);
        }

        $this->assertSame(3, CommercialCommissionClosure::query()->where('month', '2026-07')->count());
        $this->assertSame(6, CommercialCommissionSnapshot::query()->where('month', '2026-07')->count());
    }

    public function test_delegation_snapshot_freezes_manager_and_three_manager_alert_against_future_changes(): void
    {
        CarbonImmutable::setTestNow('2026-08-05 10:00:00');
        $admin = $this->user(ReportUser::ROLE_ADMIN);
        $commercials = Mockery::mock(CommercialCommissionDashboardService::class);
        $commercials->shouldReceive('build')->andReturn([
            'month' => '2026-07', 'ready' => true, 'issues' => [], 'warnings' => [],
            'delegation_rows' => [[
                'delegation_name' => 'Alicante', 'total_commission' => 3100,
                'store_manager_salesforce_user_id' => '005PEDRO', 'store_manager_name' => 'Pedro',
                'store_manager_distinct_count' => 3, 'store_manager_alert' => 'Alicante ha tenido 3 jefes de tienda demostrados durante julio de 2026.',
            ]],
        ]);
        $this->app->instance(CommercialCommissionDashboardService::class, $commercials);
        $service = app(CommercialCommissionClosureService::class);
        $components = array_fill_keys(array_keys($service->requiredComponents('delegations')), true);

        $service->prepare('2026-07', 'delegations', $components, $admin);
        $service->approve('2026-07', 'delegations', $admin);
        SalesforceDelegationManagerHistory::query()->create([
            'source_key' => 'future', 'delegation_salesforce_id' => 'a01000000000001', 'delegation_name' => 'Alicante',
            'delegation_key' => 'alicante', 'manager_salesforce_user_id' => '005CARLOS', 'manager_name' => 'Carlos',
            'effective_at' => '2026-09-01', 'coverage_from' => '2026-09-01', 'coverage_to' => '2026-10-01',
            'observed_at' => now(), 'source' => 'daily_observation', 'evidence_reference' => 'future', 'history_verified' => true,
        ]);

        $snapshot = $service->definitiveSnapshot('2026-07', 'delegations');
        $this->assertSame('005PEDRO', data_get($snapshot, 'delegations.delegation_rows.0.store_manager_salesforce_user_id'));
        $this->assertSame(3100, data_get($snapshot, 'delegations.delegation_rows.0.total_commission'));
        $this->assertStringContainsString('3 jefes', data_get($snapshot, 'delegations.delegation_rows.0.store_manager_alert'));
    }

    private function closurePayload(string $month): array
    {
        return [
            'month' => $month,
            'closure_scope' => CommercialCommissionClosure::SCOPE_COMMERCIALS,
        ];
    }

    private function dashboardMock(string $class, string $totalKey): object
    {
        $mock = Mockery::mock($class);
        $mock->shouldReceive('build')->andReturn([
            'month' => '2026-07', 'ready' => true, 'issues' => [], 'warnings' => [],
            'summary_rows' => [['agent_name' => 'Prueba', $totalKey => 100]],
        ]);

        return $mock;
    }

    private function user(string $role): ReportUser
    {
        return ReportUser::query()->create([
            'name' => ucfirst($role),
            'email' => $role.'@example.com',
            'password' => 'secret123',
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function actingAsReportUser(ReportUser $user): static
    {
        config()->set('services.informes_auth.enabled', true);

        return $this->withSession([
            'informes_authenticated' => true,
            'report_user_id' => $user->id,
            'report_user_email' => $user->email,
            'report_user_role' => $user->role,
        ]);
    }
}
