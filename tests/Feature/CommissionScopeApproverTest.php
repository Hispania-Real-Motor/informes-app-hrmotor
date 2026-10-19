<?php

namespace Tests\Feature;

use App\Models\CommercialCommissionApprover;
use App\Models\CommercialCommissionClosure;
use App\Models\CommercialCommissionSnapshot;
use App\Models\ReportUser;
use App\Services\Reports\CommercialCommissions\CommercialCommissionClosureService;
use App\Support\ReportUserAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        CommercialCommissionSnapshot::create(['closure_id' => $closure->id, 'month' => '2026-07', 'version' => 1, 'formula_version' => 'test', 'data_cutoff_at' => now(), 'payload' => [$scope => ['summary_rows' => []]], 'created_at' => now()]);

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
            $this->approve($user, $scope)->assertForbidden();
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
        $this->assign('financials', $user);
        $user->update(['is_active' => false]);
        $this->approve($user, 'financials')->assertStatus(302);
        $admin = $this->user('admin');
        $this->approve($admin, 'financials')->assertForbidden();
        $user->delete();
        $this->approve($admin, 'financials')->assertForbidden();
        $this->assertNotNull(CommercialCommissionApprover::where('closure_scope', 'financials')->first()->configured_at);
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
            $this->approve($user, 'financials')->assertSessionHasErrors('month');
            $this->assertNull($closure->fresh()->approved_by);
        }
        $this->login($user)->post('/informes/comisiones-comerciales/closure/approve', ['month' => '2026-06', 'closure_scope' => 'financials'])->assertSessionHasErrors('closure_scope');
        $this->login($user)->post('/informes/comisiones-comerciales/closure/approve', ['month' => now()->format('Y-m'), 'closure_scope' => 'financials'])->assertSessionHasErrors('month');
    }

    public function test_reassignment_is_audited_without_rewriting_historical_approval(): void
    {
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
        $payload['financials'] = $second->id;
        $this->login($admin)->put('/informes/permisos-informes/commission-approvers', ['approvers' => $payload])->assertSessionHasNoErrors();
        $event = DB::table('commercial_commission_approver_events')->latest('id')->first();
        $this->assertSame(['previous_user_id' => $first->id, 'new_user_id' => $second->id, 'actor_user_id' => $admin->id], json_decode($event->context, true));
        $this->assertSame($first->id, $closure->fresh()->approved_by);
        $this->assertSame('definitive', $closure->fresh()->status);
        $payload['financials'] = null;
        $this->login($admin)->put('/informes/permisos-informes/commission-approvers', ['approvers' => $payload])->assertSessionHasErrors('approvers.financials');
    }
}
