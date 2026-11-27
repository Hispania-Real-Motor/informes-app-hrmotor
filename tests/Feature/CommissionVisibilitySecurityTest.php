<?php

namespace Tests\Feature;

use App\Models\CommercialCommissionClosure;
use App\Models\CommercialCommissionSnapshot;
use App\Models\ReportUser;
use App\Services\Reports\CallCenterCommissions\CallCenterCommissionDashboardService;
use App\Services\Reports\CommercialCommissions\CommercialCommissionAuditProjectionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class CommissionVisibilitySecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_director_can_open_call_and_contact_details_without_admin_actions(): void
    {
        $director = $this->user(ReportUser::ROLE_DIRECTOR);

        foreach (['call-center', 'contact-center'] as $tab) {
            $this->actingAsReportUser($director)->get('/informes/comisiones-comerciales?month=2026-07&tab='.$tab)
                ->assertOk()
                ->assertViewHas('activeCommissionTab', $tab)
                ->assertDontSee('aria-disabled="true"', false);
        }
    }

    public function test_director_does_not_receive_extended_closure_actions_before_july(): void
    {
        $director = $this->user(ReportUser::ROLE_DIRECTOR);

        $this->actingAsReportUser($director)->get('/informes/comisiones-comerciales?month=2026-06')
            ->assertOk()
            ->assertViewHas('directorApprovalOverview', fn (array $overview): bool => count($overview) === 3)
            ->assertDontSee('Aprobar Comerciales')
            ->assertDontSee('Aprobar Call Center');
    }

    public function test_admin_can_open_call_and_contact_details(): void
    {
        $admin = $this->user(ReportUser::ROLE_ADMIN);

        $this->actingAsReportUser($admin)->get('/informes/comisiones-comerciales?month=2026-07&tab=call-center')
            ->assertOk()->assertViewHas('activeCommissionTab', 'call-center');
        $this->actingAsReportUser($admin)->get('/informes/comisiones-comerciales?month=2026-07&tab=contact-center')
            ->assertOk()
            ->assertViewHas('activeCommissionTab', 'contact-center')
            ->assertSee('Comisiones Contact Center')
            ->assertSee('Preparación de comisiones · julio de 2026')
            ->assertDontSee('name="components[', false)
            ->assertDontSee('Aprobar como definitivo');
    }

    public function test_contact_center_definitive_snapshot_renders_for_admin(): void
    {
        $closure = CommercialCommissionClosure::query()->create([
            'month' => '2026-07',
            'closure_scope' => CommercialCommissionClosure::SCOPE_CONTACT_CENTER,
            'status' => CommercialCommissionClosure::STATUS_DEFINITIVE,
            'snapshot_version' => 1,
            'formula_version' => 'test',
            'data_cutoff_at' => now(),
        ]);
        CommercialCommissionSnapshot::query()->create([
            'closure_id' => $closure->id,
            'month' => '2026-07',
            'version' => 1,
            'formula_version' => 'test',
            'data_cutoff_at' => now(),
            'payload' => ['contact_center' => [
                'month' => '2026-07', 'month_label' => 'julio 2026', 'ready' => true,
                'issues' => [], 'warnings' => [], 'diagnostics' => [], 'summary_rows' => [],
            ]],
            'created_at' => now(),
        ]);

        $this->actingAsReportUser($this->user(ReportUser::ROLE_ADMIN))
            ->get('/informes/comisiones-comerciales?month=2026-07&tab=contact-center')
            ->assertOk()
            ->assertViewHas('contactCenterDashboard', fn (array $dashboard): bool => $dashboard['month'] === '2026-07')
            ->assertSee('Comisiones Contact Center');
    }

    public function test_delegations_and_auditor_render_safely_while_history_schema_upgrade_is_pending(): void
    {
        $this->createLegacyHistoryTable();

        $this->actingAsReportUser($this->user(ReportUser::ROLE_ADMIN))
            ->get('/informes/comisiones-comerciales?month=2026-07&tab=delegations')
            ->assertOk()
            ->assertDontSee('histórico suficiente')
            ->assertDontSee('responsable al cierre');

        $this->actingAsReportUser($this->user(ReportUser::ROLE_COMMISSION_AUDITOR))
            ->get('/informes/comisiones-comerciales?month=2026-07')
            ->assertOk()
            ->assertViewIs('reports.commercial-commissions.audit-summary')
            ->assertViewHas('auditProjection', function (array $projection): bool {
                return count($projection['scope_statuses']) === 6
                    && ! array_key_exists('details', $projection['scope'])
                    && ! array_key_exists('diagnostics', $projection['scope']);
            })
            ->assertDontSee('Diagnóstico')
            ->assertDontSee('Opportunity');
    }

    public function test_director_has_a_consolidated_approval_action_for_each_prepared_scope(): void
    {
        foreach (CommercialCommissionClosure::CLOSABLE_SCOPES as $scope) {
            CommercialCommissionClosure::query()->create([
                'month' => '2026-07',
                'closure_scope' => $scope,
                'status' => CommercialCommissionClosure::STATUS_PENDING_APPROVAL,
                'component_statuses' => [],
            ]);
        }

        $response = $this->actingAsReportUser($this->user(ReportUser::ROLE_DIRECTOR))
            ->get('/informes/comisiones-comerciales?month=2026-07')
            ->assertOk()
            ->assertViewHas('directorApprovalOverview', fn (array $overview): bool => count($overview) === 6)
            ->assertDontSee('Diagnóstico Contact Center')
            ->assertDontSee('Detalle Contact Center');

        foreach (['Comerciales', 'Delegaciones', 'Área Manager', 'Financieros', 'Call Center', 'Contact Center'] as $label) {
            $response->assertSee($label);
        }
        $response->assertSee('Aprobar');
    }

    public function test_director_consolidated_surface_shows_definitive_approval_metadata_and_reopen_action(): void
    {
        $approver = $this->user(ReportUser::ROLE_ADMIN);
        $closure = CommercialCommissionClosure::query()->create([
            'month' => '2026-07',
            'closure_scope' => CommercialCommissionClosure::SCOPE_FINANCIALS,
            'status' => CommercialCommissionClosure::STATUS_DEFINITIVE,
            'approved_by' => $approver->id,
            'approved_at' => '2026-08-15 12:30:00',
            'snapshot_version' => 1,
            'formula_version' => 'test',
            'data_cutoff_at' => now(),
        ]);
        CommercialCommissionSnapshot::query()->create([
            'closure_id' => $closure->id,
            'month' => '2026-07',
            'version' => 1,
            'formula_version' => 'test',
            'data_cutoff_at' => now(),
            'payload' => ['financials' => [
                'summary_rows' => [['responsible_name' => 'Zona', 'final_commission' => 321.45]],
            ]],
            'created_at' => now(),
        ]);

        $this->actingAsReportUser($this->user(ReportUser::ROLE_DIRECTOR))
            ->get('/informes/comisiones-comerciales?month=2026-07')
            ->assertOk()
            ->assertSee('321,45 EUR')
            ->assertSee($approver->name)
            ->assertSee('Reabrir')
            ->assertDontSee('Aprobar');
    }

    public function test_auditor_receives_only_dedicated_projection_even_when_forcing_detailed_tabs(): void
    {
        $projection = Mockery::mock(CommercialCommissionAuditProjectionService::class);
        $projection->shouldReceive('scopeLabel')->andReturn('Comerciales');
        $projection->shouldReceive('build')->twice()->andReturn([
            'month' => '2026-07', 'month_label' => 'julio de 2026', 'selected_scope' => 'commercials',
            'available_months' => [['value' => '2026-07', 'label' => 'julio de 2026']],
            'scope_statuses' => ['commercials' => ['status' => 'provisional', 'approved_by' => null, 'approved_at' => null]],
            'scope' => ['scope' => 'commercials', 'status' => ['status' => 'provisional', 'approved_by' => null, 'approved_at' => null], 'rows' => [], 'alerts' => [], 'available' => true, 'warning' => null],
        ]);
        $this->app->instance(CommercialCommissionAuditProjectionService::class, $projection);
        $auditor = $this->user(ReportUser::ROLE_COMMISSION_AUDITOR);

        foreach (['call-center', 'contact-center'] as $tab) {
            $this->actingAsReportUser($auditor)->get('/informes/comisiones-comerciales?month=2026-07&tab='.$tab)
                ->assertOk()
                ->assertViewIs('reports.commercial-commissions.audit-summary')
                ->assertDontSee('Diagnóstico')
                ->assertDontSee('Jefe de tienda')
                ->assertDontSee('Opportunity');
        }
    }

    public function test_auditor_month_selector_and_scope_links_preserve_each_other(): void
    {
        $projection = Mockery::mock(CommercialCommissionAuditProjectionService::class);
        $projection->shouldReceive('scopeLabel')->andReturnUsing(fn (string $scope): string => $scope === 'delegations' ? 'Delegaciones' : 'Comerciales');
        $projection->shouldReceive('build')->once()->with('2026-07', 'delegations')->andReturn([
            'month' => '2026-07', 'month_label' => 'julio de 2026', 'selected_scope' => 'delegations',
            'available_months' => [
                ['value' => '2026-08', 'label' => 'agosto de 2026'],
                ['value' => '2026-07', 'label' => 'julio de 2026'],
            ],
            'scope_statuses' => [
                'commercials' => ['status' => 'provisional', 'approved_by' => null, 'approved_at' => null],
                'delegations' => ['status' => 'provisional', 'approved_by' => null, 'approved_at' => null],
            ],
            'scope' => ['scope' => 'delegations', 'status' => ['status' => 'provisional', 'approved_by' => null, 'approved_at' => null], 'rows' => [], 'alerts' => [], 'available' => true, 'warning' => null],
        ]);
        $this->app->instance(CommercialCommissionAuditProjectionService::class, $projection);

        $response = $this->actingAsReportUser($this->user(ReportUser::ROLE_COMMISSION_AUDITOR))
            ->get('/informes/comisiones-comerciales?month=2026-07&audit_scope=delegations')
            ->assertOk()
            ->assertSee('Mes económico')
            ->assertSee('julio de 2026')
            ->assertSee('agosto de 2026')
            ->assertSee('name="audit_scope" value="delegations"', false)
            ->assertSee('value="2026-07" selected', false);

        $this->assertStringContainsString('month=2026-07', $response->getContent());
        $this->assertStringContainsString('audit_scope=commercials', html_entity_decode($response->getContent()));
    }

    public function test_auditor_delegations_render_frozen_manager_and_translated_definitive_status_without_sensitive_history(): void
    {
        $projection = Mockery::mock(CommercialCommissionAuditProjectionService::class);
        $projection->shouldReceive('scopeLabel')->andReturn('Delegaciones');
        $projection->shouldReceive('build')->once()->andReturn([
            'month' => '2026-07', 'month_label' => 'julio de 2026', 'selected_scope' => 'delegations',
            'available_months' => [['value' => '2026-07', 'label' => 'julio de 2026']],
            'scope_statuses' => ['delegations' => ['status' => 'definitive', 'label' => 'Definitivo', 'variant' => 'group', 'approved_by' => ['name' => 'Dirección'], 'approved_at' => '2026-08-28 10:00:00']],
            'scope' => [
                'scope' => 'delegations',
                'status' => ['status' => 'definitive', 'label' => 'Definitivo', 'variant' => 'group', 'approved_by' => ['name' => 'Dirección'], 'approved_at' => '2026-08-28 10:00:00'],
                'rows' => [['name' => 'Alicante', 'manager_name' => 'Pedro García', 'final_total' => 1398.09, 'alert' => null]],
                'alerts' => [], 'available' => true, 'warning' => null,
            ],
        ]);
        $this->app->instance(CommercialCommissionAuditProjectionService::class, $projection);

        $this->actingAsReportUser($this->user(ReportUser::ROLE_COMMISSION_AUDITOR))
            ->get('/informes/comisiones-comerciales?month=2026-07&audit_scope=delegations')
            ->assertOk()
            ->assertSee('Jefe de tienda')
            ->assertSee('Pedro García')
            ->assertSee('1.398,09 EUR')
            ->assertSee('type-pill group', false)
            ->assertSee('Definitivo')
            ->assertDontSee('definitive')
            ->assertDontSee('Revisar')
            ->assertDontSee('005')
            ->assertDontSee('evidence');
    }

    public function test_auditor_closure_status_endpoint_exposes_only_approval_metadata(): void
    {
        $auditor = $this->user(ReportUser::ROLE_COMMISSION_AUDITOR);

        $response = $this->actingAsReportUser($auditor)
            ->getJson('/informes/comisiones-comerciales/data/closure?month=2026-07')
            ->assertOk()
            ->assertJsonStructure(['closures' => ['commercials' => [
                'month', 'closure_scope', 'status', 'approved_by', 'approved_at',
            ]]])
            ->assertJsonMissingPath('closures.commercials.component_statuses')
            ->assertJsonMissingPath('closures.commercials.issues')
            ->assertJsonMissingPath('closures.commercials.data_cutoff_at')
            ->assertJsonMissingPath('closures.commercials.formula_version');

        $this->assertSame(
            ['month', 'closure_scope', 'status', 'approved_by', 'approved_at'],
            array_keys($response->json('closures.commercials')),
        );
    }

    public function test_detailed_exports_and_penalty_management_are_denied_to_restricted_roles(): void
    {
        $director = $this->user(ReportUser::ROLE_DIRECTOR);
        $auditor = $this->user(ReportUser::ROLE_COMMISSION_AUDITOR);

        $this->actingAsReportUser($director)->get('/informes/comisiones-comerciales/export/call-center-missing-captador.csv?month=2026-07')->assertForbidden();
        $this->actingAsReportUser($auditor)->get('/informes/comisiones-comerciales/export/comisiones.xlsx?month=2026-07')->assertForbidden();
        $this->actingAsReportUser($auditor)->get('/informes/comisiones-comerciales/export/reviews-audit.csv?month=2026-07')->assertForbidden();
        $this->actingAsReportUser($auditor)->get('/informes/penalizaciones-financiacion')->assertForbidden();
    }

    public function test_filtered_call_center_view_discloses_the_canonical_monthly_closure_total(): void
    {
        $callCenter = Mockery::mock(CallCenterCommissionDashboardService::class);
        $callCenter->shouldReceive('build')->once()->with('2026-07', '2026-07-10', '2026-07-20')->andReturn([
            'month' => '2026-07', 'month_label' => 'julio 2026', 'ready' => true, 'issues' => [], 'warnings' => [],
            'diagnostics' => [], 'summary_rows' => [],
        ]);
        $callCenter->shouldReceive('build')->once()->with('2026-07', null, null, false)->andReturn([
            'month' => '2026-07', 'month_label' => 'julio 2026', 'ready' => true, 'issues' => [], 'warnings' => [],
            'diagnostics' => [], 'summary_rows' => [['agent_name' => 'Canónico', 'final_total' => 500]],
        ]);
        $this->app->instance(CallCenterCommissionDashboardService::class, $callCenter);
        $admin = $this->user(ReportUser::ROLE_ADMIN);

        $this->actingAsReportUser($admin)
            ->get('/informes/comisiones-comerciales?month=2026-07&tab=call-center&call_center_contract_from=2026-07-10&call_center_contract_to=2026-07-20')
            ->assertOk()
            ->assertViewHas('canonicalCallCenterClosureSummary', fn (array $summary): bool => $summary['final_total'] === 500.0)
            ->assertSee('El cierre siempre congelará el mes natural completo')
            ->assertSee('500,00 EUR');
    }

    private function user(string $role): ReportUser
    {
        return ReportUser::query()->create([
            'name' => ucfirst($role), 'email' => $role.'-security@example.test', 'password' => 'secret123',
            'role' => $role, 'is_active' => true,
        ]);
    }

    private function actingAsReportUser(ReportUser $user): static
    {
        config()->set('services.informes_auth.enabled', true);

        return $this->withSession([
            'informes_authenticated' => true, 'report_user_id' => $user->id,
            'report_user_email' => $user->email, 'report_user_role' => $user->role,
        ]);
    }

    private function createLegacyHistoryTable(): void
    {
        Schema::dropIfExists('salesforce_delegation_manager_history');
        Schema::create('salesforce_delegation_manager_history', function (Blueprint $table): void {
            $table->id();
            $table->string('source_key', 120)->unique('sf_deleg_mgr_source_uq');
            $table->string('delegation_salesforce_id', 18);
            $table->string('delegation_name');
            $table->string('delegation_key');
            $table->string('manager_salesforce_user_id', 18)->nullable();
            $table->string('manager_name')->nullable();
            $table->timestamp('effective_at');
            $table->timestamp('observed_at');
            $table->string('source', 32);
            $table->boolean('history_verified')->default(false);
            $table->timestamps();
        });
    }
}
