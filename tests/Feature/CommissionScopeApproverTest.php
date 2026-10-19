<?php

namespace Tests\Feature;

use App\Models\CommercialCommissionApprover;
use App\Models\CommercialCommissionClosure;
use App\Models\CommercialCommissionSnapshot;
use App\Models\ReportUser;
use App\Services\Reports\CommercialCommissions\CommercialCommissionClosureService;
use App\Services\Reports\CommercialCommissions\CommercialCommissionDashboardService;
use App\Services\Reports\FinancialCommissions\FinancialCommissionDashboardService;
use App\Support\ReportUserAccess;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CommissionScopeApproverTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'financial'): ReportUser
    {
        return ReportUser::create(['name' => 'Synthetic', 'email' => uniqid().'@example.test', 'password' => 'synthetic-password', 'role' => $role, 'is_active' => true]);
    }

    private function login(ReportUser $user): static
    {
        config()->set('services.informes_auth.enabled', true);

        return $this->withSession(['informes_authenticated' => true, 'report_user_id' => $user->id, 'report_user_role' => $user->role, 'report_user_email' => $user->email]);
    }

    private function assign(string $scope, ReportUser $user): void
    {
        CommercialCommissionApprover::where('closure_scope', $scope)->firstOrFail()->update(['report_user_id' => $user->id, 'configured_at' => now()]);
    }

    private function candidate(string $scope): CommercialCommissionClosure
    {
        $closure = CommercialCommissionClosure::create(['month' => '2026-07', 'closure_scope' => $scope, 'status' => 'pending_approval', 'component_statuses' => array_fill_keys(CommercialCommissionClosureService::REQUIRED_COMPONENTS_BY_SCOPE[$scope], true), 'snapshot_version' => 1]);
        $dashboard = $scope === 'commercials'
            ? app(CommercialCommissionDashboardService::class)->build('2026-07', false, false, false)
            : ['month' => '2026-07', 'month_label' => 'julio 2026', 'ready' => true, 'issues' => [], 'warnings' => [], 'diagnostics' => [], 'summary_rows' => [], 'delegation_rows' => [], 'detail_rows' => []];
        CommercialCommissionSnapshot::create(['closure_id' => $closure->id, 'month' => '2026-07', 'version' => 1, 'formula_version' => 'test', 'data_cutoff_at' => now(), 'payload' => [$scope => $dashboard], 'created_at' => now()]);

        return $closure;
    }

    private function approve(ReportUser $user, string $scope)
    {
        return $this->login($user)->post('/informes/comisiones-comerciales/closure/approve', ['month' => '2026-07', 'closure_scope' => $scope]);
    }

    public function test_assigned_financial_approves_snapshot_and_records_real_actor(): void
    {
        $user = $this->user();
        $this->assign('financials', $user);
        $closure = $this->candidate('financials');
        $snapshot = $closure->snapshots()->first()->getAttributes();
        $this->approve($user, 'financials')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('commercial_commission_closures', ['id' => $closure->id, 'approved_by' => $user->id, 'status' => 'definitive']);
        $this->assertDatabaseHas('commercial_commission_closure_events', ['closure_id' => $closure->id, 'action' => 'approved', 'report_user_id' => $user->id]);
        $this->assertSame($snapshot, $closure->snapshots()->first()->getAttributes());
        foreach (array_diff(CommercialCommissionClosure::CLOSABLE_SCOPES, ['financials']) as $scope) {
            $this->candidate($scope);
            $before = $this->economicState();
            $this->approve($user, $scope)->assertForbidden();
            $this->assertSame($before, $this->economicState());
        }
    }

    public function test_specific_assignment_overrides_admin_director_and_other_users(): void
    {
        $this->assign('financials', $this->user());
        foreach (['financial', 'admin', 'director'] as $role) {
            $this->approve($this->user($role), 'financials')->assertForbidden();
        }
    }

    public function test_disabled_or_deleted_assignment_never_restores_legacy(): void
    {
        $user = $this->user();
        $admin = $this->user('admin');
        $director = $this->user('director');
        $this->assign('financials', $user);
        $closure = $this->candidate('financials');
        $before = $this->economicState();
        $configuredAt = DB::table('commercial_commission_approvers')->where('closure_scope', 'financials')->value('configured_at');
        $user->update(['is_active' => false]);

        foreach (['inactive', 'deleted'] as $state) {
            if ($state === 'deleted') {
                $user->delete();
            }
            // Authentication invalidates the stale session and redirects to login.
            $this->approve($user, 'financials')->assertRedirect(route('login'));
            foreach ([$admin, $director] as $legacyApprover) {
                $this->approve($legacyApprover, 'financials')->assertForbidden();
            }
            $this->assertSame($before, $this->economicState());
            $this->assertSame('pending_approval', $closure->fresh()->status);
            $this->assertNull($closure->fresh()->approved_by);
            $this->assertNull($closure->fresh()->approved_at);
            $this->assertDatabaseCount('commercial_commission_snapshots', 1);
            $this->assertSame(0, DB::table('commercial_commission_closure_events')->where('action', 'approved')->count());
            $this->assertNull(app(CommercialCommissionClosureService::class)->definitiveSnapshot('2026-07', 'financials'));
            $assignment = CommercialCommissionApprover::where('closure_scope', 'financials')->firstOrFail();
            $this->assertSame($configuredAt, $assignment->getRawOriginal('configured_at'));
            $this->assertSame($state === 'deleted' ? null : $user->id, $assignment->report_user_id);
        }
    }

    private function economicState(): array
    {
        return collect(['commercial_commission_closures', 'commercial_commission_snapshots', 'commercial_commission_closure_events'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all()])->all();
    }

    public function test_six_assignments_and_same_user_for_multiple_scopes(): void
    {
        foreach (CommercialCommissionClosure::CLOSABLE_SCOPES as $scope) {
            $user = $this->user('director');
            $this->assign($scope, $user);
            $this->candidate($scope);
            $this->approve($user, $scope)->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->assertSame(6, CommercialCommissionApprover::whereNotNull('configured_at')->distinct()->count('report_user_id'));
        $user = $this->user('director');
        foreach (['commercials', 'financials'] as $scope) {
            $this->assign($scope, $user);
        }
        $request = Request::create('/');
        $request->setLaravelSession($this->app['session.store']);
        $this->login($user);
        $request->session()->put(['informes_authenticated' => true, 'report_user_id' => $user->id]);
        $this->assertSame(['commercials', 'financials'], ReportUserAccess::commissionApprovalScopes($request));
    }

    public function test_unconfigured_scope_keeps_legacy_and_invalid_scope_is_validated(): void
    {
        $this->candidate('commercials');
        $this->approve($this->user('director'), 'commercials')->assertRedirect()->assertSessionHasNoErrors();
        $this->approve($this->user(), 'invalid')->assertSessionHasErrors('closure_scope');
    }

    public function test_admin_assignment_is_audited_and_inactive_user_is_rejected(): void
    {
        $admin = $this->user('admin');
        $user = $this->user();
        $payload = array_fill_keys(CommercialCommissionClosure::CLOSABLE_SCOPES, null);
        $payload['financials'] = $user->id;
        $this->login($admin)->put('/informes/permisos-informes/commission-approvers', ['approvers' => $payload])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('commercial_commission_approver_events', ['closure_scope' => 'financials', 'report_user_id' => $admin->id]);
        $user->update(['is_active' => false]);
        $this->login($admin)->put('/informes/permisos-informes/commission-approvers', ['approvers' => $payload])->assertSessionHasErrors('approvers.financials');
    }

    public function test_assignment_does_not_grant_administrative_actions(): void
    {
        $user = $this->user();
        $this->assign('financials', $user);
        foreach (['prepare', 'reopen'] as $action) {
            $this->login($user)->post('/informes/comisiones-comerciales/closure/'.$action, ['month' => '2026-07', 'closure_scope' => 'financials'])->assertForbidden();
        }
        foreach (['/informes/permisos-informes', '/informes/configuracion-comisiones', '/informes/penalizaciones-financiacion', '/informes/usuarios'] as $url) {
            $this->login($user)->get($url)->assertForbidden();
        }
    }

    public function test_shared_approver_can_approve_two_scopes(): void
    {
        $user = $this->user('director');
        foreach (['commercials', 'financials'] as $scope) {
            $this->assign($scope, $user);
            $closure = $this->candidate($scope);
            $this->approve($user, $scope)->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame($user->id, $closure->fresh()->approved_by);
        }
    }

    public function test_financial_ui_only_receives_assigned_financial_summary_and_keeps_tab_restriction(): void
    {
        $user = $this->user();
        $this->assign('financials', $user);
        $this->assign('commercials', $user);
        $this->candidate('financials');
        $this->login($user)->get('/informes/comisiones-comerciales?month=2026-07&tab=summary')
            ->assertOk()->assertViewHas('activeCommissionTab', 'financials')
            ->assertViewHas('canApproveEconomicClosures', true)
            ->assertViewHas('directorApprovalOverview', fn (array $rows): bool => array_column($rows, 'scope') === ['financials'])
            ->assertSee('name="closure_scope" value="financials"', false)
            ->assertDontSee('name="closure_scope" value="commercials"', false)
            ->assertDontSee('Confirmar reapertura');
        $this->login($user)->post('/informes/comisiones-comerciales/adjustments', [])->assertForbidden();
        $this->login($user)->put('/informes/permisos-informes/commission-approvers', [])->assertForbidden();
        $this->login($user)->put('/informes/configuracion-comisiones', [])->assertForbidden();
    }

    public function test_only_pending_finished_available_scopes_can_be_approved(): void
    {
        $user = $this->user();
        $this->assign('financials', $user);
        $closure = $this->candidate('financials');
        foreach (['provisional', 'reopened', 'definitive'] as $status) {
            $closure->update(['status' => $status]);
            $before = $this->economicState();
            $this->approve($user, 'financials')->assertSessionHasErrors('month');
            $this->assertNull($closure->fresh()->approved_by);
            $this->assertNull($closure->fresh()->approved_at);
            $this->assertSame($before, $this->economicState());
        }
        $this->login($user)->post('/informes/comisiones-comerciales/closure/approve', ['month' => '2026-06', 'closure_scope' => 'financials'])->assertSessionHasErrors('closure_scope');
        $this->login($user)->post('/informes/comisiones-comerciales/closure/approve', ['month' => now()->format('Y-m'), 'closure_scope' => 'financials'])->assertSessionHasErrors('month');
    }

    public function test_reassignment_is_audited_without_rewriting_historical_approval(): void
    {
        $this->freezeTime();
        $admin = $this->user('admin');
        $first = $this->user();
        $second = $this->user();
        $payload = array_fill_keys(CommercialCommissionClosure::CLOSABLE_SCOPES, null);
        $payload['financials'] = $first->id;
        $this->login($admin)->get('/informes/permisos-informes')->assertOk()
            ->assertViewHas('commissionScopes', CommercialCommissionClosure::CLOSABLE_SCOPES);
        $this->login($admin)->put('/informes/permisos-informes/commission-approvers', ['approvers' => $payload])->assertSessionHasNoErrors();
        $closure = $this->candidate('financials');
        $this->approve($first, 'financials')->assertSessionHasNoErrors();
        $historicalState = $this->economicState();
        $payload['financials'] = $second->id;
        $this->login($admin)->put('/informes/permisos-informes/commission-approvers', ['approvers' => $payload])->assertSessionHasNoErrors();
        $event = DB::table('commercial_commission_approver_events')->latest('id')->first();
        $this->assertSame(['previous_user_id' => $first->id, 'new_user_id' => $second->id, 'actor_user_id' => $admin->id], json_decode($event->context, true));
        $this->assertSame($historicalState, $this->economicState());
        $this->assertSame('financials', $event->closure_scope);
        $this->assertSame($admin->id, $event->report_user_id);
        $this->assertSame(now()->toDateTimeString(), $event->created_at);
        $this->assertSame($first->id, $closure->fresh()->approved_by);
        $this->assertSame('definitive', $closure->fresh()->status);
        $payload['financials'] = null;
        $this->login($admin)->put('/informes/permisos-informes/commission-approvers', ['approvers' => $payload])->assertSessionHasErrors('approvers.financials');
    }

    private function approverPayload(): array
    {
        return CommercialCommissionApprover::query()->pluck('report_user_id', 'closure_scope')->all();
    }

    public function test_admin_sees_exactly_one_approval_form_per_authorized_pending_scope(): void
    {
        $admin = $this->user('admin');
        $other = $this->user('director');
        foreach (['commercials', 'financials', 'delegations'] as $scope) {
            $this->candidate($scope);
        }
        $this->assign('financials', $admin);
        $this->assign('delegations', $other);
        $response = $this->login($admin)->get('/informes/comisiones-comerciales?month=2026-07');
        $response->assertOk()->assertViewHas('directorApprovalOverview', fn (array $rows): bool => array_column($rows, 'scope') === ['commercials', 'area_manager', 'financials', 'call_center', 'contact_center']);
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $forms = $xpath->query('//form[@action="'.route('reports.commercial-commissions.closure.approve').'"]');
        $this->assertSame(2, $forms->length);
        $scopes = [];
        foreach ($forms as $form) {
            $scopes[] = $xpath->query('.//input[@name="closure_scope"]', $form)->item(0)->getAttribute('value');
        }
        $this->assertSame(['commercials', 'financials'], $scopes);
        $this->approve($admin, 'delegations')->assertForbidden();
        $this->approve($admin, 'commercials')->assertRedirect()->assertSessionHasNoErrors();
        $this->approve($admin, 'financials')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('commercial_commission_closure_events')->where('action', 'approved')->count());
        $response = $this->login($admin)->get('/informes/comisiones-comerciales?month=2026-07');
        $response->assertOk()->assertSee('Motivo de reapertura')->assertSee('Preparación de comisiones');
        $this->assertSame(2, substr_count($response->getContent(), 'action="'.route('reports.commercial-commissions.closure.reopen').'"'));
    }

    public function test_blocked_form_requires_explicit_active_replacement_and_rolls_back_all_scopes(): void
    {
        $admin = $this->user('admin');
        $inactive = $this->user();
        $replacement = $this->user();
        $this->assign('financials', $inactive);
        $this->assign('commercials', $admin);
        $inactive->update(['is_active' => false]);
        $response = $this->login($admin)->get('/informes/permisos-informes');
        $response->assertOk()->assertSee('Financieros: aprobación bloqueada.')
            ->assertDontSee('value="'.$inactive->id.'"', false);
        $payload = $this->approverPayload();
        $payload['financials'] = null;
        $payload['commercials'] = $replacement->id;
        $before = CommercialCommissionApprover::query()->get()->toArray();
        $this->login($admin)->followingRedirects()->from('/informes/permisos-informes')->put('/informes/permisos-informes/commission-approvers', ['approvers' => $payload])
            ->assertOk()->assertSee('Seleccione un aprobador activo para Financieros; la asignación explícita no se puede vaciar.')
            ->assertSee('id="approver_error_financials"', false)
            ->assertSee('aria-describedby="approver_error_financials"', false);
        $this->assertSame($before, CommercialCommissionApprover::query()->get()->toArray());
        $this->assertDatabaseCount('commercial_commission_approver_events', 0);
        $payload['financials'] = $replacement->id;
        $payload['commercials'] = $admin->id;
        $this->login($admin)->put('/informes/permisos-informes/commission-approvers', ['approvers' => $payload])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('commercial_commission_approvers', ['closure_scope' => 'financials', 'report_user_id' => $replacement->id]);
        $this->assertDatabaseCount('commercial_commission_approver_events', 1);
        $saved = CommercialCommissionApprover::query()->get()->toArray();
        $this->login($admin)->put('/informes/permisos-informes/commission-approvers', ['approvers' => $payload])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($saved, CommercialCommissionApprover::query()->get()->toArray());
        $this->assertDatabaseCount('commercial_commission_approver_events', 1);
        $this->assertNull(CommercialCommissionApprover::where('closure_scope', 'delegations')->first()->configured_at);
    }

    public function test_deleted_approver_is_shown_blocked_and_can_be_replaced(): void
    {
        $admin = $this->user('admin');
        $deleted = $this->user();
        $replacement = $this->user();
        $this->assign('financials', $deleted);
        $deleted->delete();
        $this->login($admin)->get('/informes/permisos-informes')->assertOk()->assertSee('Financieros: aprobación bloqueada.');
        $payload = $this->approverPayload();
        $payload['financials'] = $replacement->id;
        $this->login($admin)->put('/informes/permisos-informes/commission-approvers', ['approvers' => $payload])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('commercial_commission_approvers', ['closure_scope' => 'financials', 'report_user_id' => $replacement->id]);
        $this->assertNotNull(CommercialCommissionApprover::where('closure_scope', 'financials')->first()->configured_at);
    }

    public function test_nonexistent_inactive_and_manipulated_assignments_leave_all_assignments_intact(): void
    {
        $admin = $this->user('admin');
        $active = $this->user();
        $inactive = $this->user();
        $inactive->update(['is_active' => false]);
        $before = CommercialCommissionApprover::query()->get()->toArray();
        foreach ([$inactive->id, 999999999] as $invalidId) {
            $payload = $this->approverPayload();
            $payload['commercials'] = $active->id;
            $payload['financials'] = $invalidId;
            $this->login($admin)->put('/informes/permisos-informes/commission-approvers', ['approvers' => $payload])->assertSessionHasErrors('approvers.financials');
            $this->assertSame($before, CommercialCommissionApprover::query()->get()->toArray());
            $this->assertDatabaseCount('commercial_commission_approver_events', 0);
        }
        $payload = $this->approverPayload();
        $payload['injected_scope'] = $active->id;
        $this->login($admin)->put('/informes/permisos-informes/commission-approvers', ['approvers' => $payload])->assertSessionHasErrors('approvers');
        $this->assertSame($before, CommercialCommissionApprover::query()->get()->toArray());
    }

    public function test_missing_candidate_invalid_scope_and_other_pending_scope_do_not_change_economics(): void
    {
        $user = $this->user();
        $this->assign('financials', $user);
        $this->candidate('commercials');
        $before = $this->economicState();
        $this->approve($user, 'commercials')->assertForbidden();
        $this->approve($user, 'financials')->assertSessionHasErrors('month');
        $this->approve($user, 'invalid')->assertSessionHasErrors('closure_scope');
        $this->assertSame($before, $this->economicState());
        $closure = $this->candidate('financials');
        $closure->snapshots()->delete();
        $before = $this->economicState();
        $this->approve($user, 'financials')->assertSessionHasErrors('snapshot');
        $this->assertSame($before, $this->economicState());
    }

    public function test_missing_or_inconsistent_assignment_denies_instead_of_restoring_legacy(): void
    {
        $admin = $this->user('admin');
        $this->candidate('financials');
        $assignment = CommercialCommissionApprover::where('closure_scope', 'financials')->firstOrFail();
        $assignment->update(['report_user_id' => $admin->id]);
        $before = $this->economicState();
        $this->approve($admin, 'financials')->assertForbidden();
        $payload = $this->approverPayload();
        $this->login($admin)->put('/informes/permisos-informes/commission-approvers', ['approvers' => $payload])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull($assignment->fresh()->configured_at);
        $assignment->delete();
        $this->approve($admin, 'financials')->assertForbidden();
        $this->assertSame($before, $this->economicState());
        $this->login($admin)->get('/informes/comisiones-comerciales?month=2026-07')->assertOk()
            ->assertViewHas('directorApprovalOverview', fn (array $rows): bool => ! in_array('financials', array_column($rows, 'scope'), true));
        $assignmentsBefore = CommercialCommissionApprover::query()->get()->toArray();
        $payload = array_fill_keys(CommercialCommissionClosure::CLOSABLE_SCOPES, null);
        $payload['commercials'] = $admin->id;
        $this->login($admin)->put('/informes/permisos-informes/commission-approvers', ['approvers' => $payload])->assertSessionHasErrors('approvers');
        $this->assertSame($assignmentsBefore, CommercialCommissionApprover::query()->get()->toArray());
    }

    public function test_assignment_cannot_grant_report_access_to_stock_only_user(): void
    {
        $user = $this->user('stock_only');
        $this->assign('financials', $user);
        $this->candidate('financials');
        $before = $this->economicState();
        $this->login($user)->postJson('/informes/comisiones-comerciales/closure/approve', ['month' => '2026-07', 'closure_scope' => 'financials'])->assertForbidden();
        $request = Request::create('/');
        $request->setLaravelSession($this->app['session.store']);
        $this->assertSame([], ReportUserAccess::commissionApprovalScopes($request));
        $this->assertSame($before, $this->economicState());
    }

    public function test_initial_schema_contains_exactly_six_unique_unconfigured_scopes(): void
    {
        $expected = CommercialCommissionClosure::CLOSABLE_SCOPES;
        sort($expected);
        $this->assertSame($expected, CommercialCommissionApprover::orderBy('closure_scope')->pluck('closure_scope')->all());
        $this->assertDatabaseCount('commercial_commission_approvers', 6);
        $this->assertSame(0, CommercialCommissionApprover::whereNotNull('configured_at')->orWhereNotNull('report_user_id')->count());
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('commercial_commission_approvers')->insert(['closure_scope' => 'financials']);
    }

    public function test_assignment_foreign_key_rejects_nonexistent_user(): void
    {
        $this->expectException(QueryException::class);
        DB::table('commercial_commission_approvers')->where('closure_scope', 'financials')->update(['report_user_id' => 999999999]);
    }

    public function test_feature_migration_rollback_only_removes_its_own_tables(): void
    {
        $closure = $this->candidate('financials');
        $before = $this->economicState();
        $migration = require database_path('migrations/2026_10_19_120000_create_commercial_commission_approvers.php');
        try {
            $migration->down();
            $this->assertFalse(Schema::hasTable('commercial_commission_approvers'));
            $this->assertFalse(Schema::hasTable('commercial_commission_approver_events'));
            foreach (['report_users', 'report_access_settings', 'commercial_commission_closures', 'commercial_commission_snapshots', 'commercial_commission_closure_events'] as $table) {
                $this->assertTrue(Schema::hasTable($table));
            }
            $this->assertSame($before, $this->economicState());
        } finally {
            $migration->up();
        }
    }

    public function test_approval_never_rebuilds_dashboards_and_resolution_is_cached_per_request(): void
    {
        $user = $this->user();
        $this->assign('financials', $user);
        $this->candidate('financials');
        $financial = \Mockery::mock(FinancialCommissionDashboardService::class);
        $financial->shouldNotReceive('build');
        $this->app->instance(FinancialCommissionDashboardService::class, $financial);
        $this->approve($user, 'financials')->assertRedirect()->assertSessionHasNoErrors();
        $request = Request::create('/');
        $request->setLaravelSession($this->app['session.store']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->assertSame(['financials'], ReportUserAccess::commissionApprovalScopes($request));
            foreach (CommercialCommissionClosure::CLOSABLE_SCOPES as $scope) {
                $this->assertSame($scope === 'financials', ReportUserAccess::canApproveCommissionClosure($request, $scope));
            }
            $queries = collect(DB::getQueryLog());
            $this->assertSame(1, $queries->filter(fn (array $query): bool => str_contains($query['query'], 'commercial_commission_approvers'))->count());
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_administration_persists_six_assignments_in_one_audited_operation(): void
    {
        $admin = $this->user('admin');
        $payload = [];
        foreach (CommercialCommissionClosure::CLOSABLE_SCOPES as $scope) {
            $payload[$scope] = $this->user()->id;
        }
        $this->login($admin)->put('/informes/permisos-informes/commission-approvers', ['approvers' => $payload])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($payload, $this->approverPayload());
        $this->assertDatabaseCount('commercial_commission_approver_events', 6);
        $this->assertSame(6, CommercialCommissionApprover::whereNotNull('configured_at')->count());
    }
}
