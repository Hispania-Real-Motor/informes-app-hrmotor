<?php

namespace App\Http\Controllers\Reports\Settings;

use App\Http\Controllers\Controller;
use App\Models\CommercialCommissionApprover;
use App\Models\CommercialCommissionClosure;
use App\Models\ReportAccessSetting;
use App\Models\ReportUser;
use App\Support\ReportUserAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ReportAccessManagementController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        abort_unless(ReportUserAccess::canManageReportUsers($request), 403);

        return view('reports.settings.access', [
            'reportUserRole' => ReportUserAccess::role($request),
            'reportDefinitions' => ReportUserAccess::reportDefinitions(),
            'minimumRoles' => ReportUserAccess::minimumRolesByReport($request),
            'commissionScopes' => CommercialCommissionClosure::CLOSABLE_SCOPES,
            'commissionScopeLabels' => CommercialCommissionApprover::SCOPE_LABELS,
            'commissionApprovers' => CommercialCommissionApprover::query()->with('user:id,is_active')->get()->keyBy('closure_scope'),
            'approverUsers' => ReportUser::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email']),
            'roleOptions' => ReportUserAccess::reportMinimumRoleOptions(),
        ]);
    }

    public function updateApprovers(Request $request): RedirectResponse
    {
        abort_unless(ReportUserAccess::canManageReportUsers($request), 403);
        $rules = ['approvers' => ['required', 'array:'.implode(',', CommercialCommissionClosure::CLOSABLE_SCOPES)]];
        foreach (CommercialCommissionClosure::CLOSABLE_SCOPES as $scope) {
            $rules["approvers.$scope"] = ['present', 'nullable', 'integer', Rule::exists('report_users', 'id')->where('is_active', true)];
        }
        $attributes = [];
        foreach (CommercialCommissionApprover::SCOPE_LABELS as $scope => $label) {
            $attributes["approvers.$scope"] = "aprobador de $label";
        }
        $data = $request->validate($rules, [], $attributes);
        DB::transaction(function () use ($data, $request): void {
            // Lock all scope rows first, then users in primary-key order. Approval
            // holds its scope row until the economic transaction has committed.
            $assignments = CommercialCommissionApprover::query()->whereIn('closure_scope', CommercialCommissionClosure::CLOSABLE_SCOPES)->orderBy('id')->lockForUpdate()->get();
            if ($assignments->count() !== count(CommercialCommissionClosure::CLOSABLE_SCOPES)) {
                throw ValidationException::withMessages(['approvers' => 'La configuración de scopes está incompleta. Revise la integridad de las asignaciones.']);
            }
            $actorId = (int) $request->session()->get('report_user_id');
            $users = ReportUser::query()->whereIn('id', array_unique(array_filter([...array_values($data['approvers']), $actorId])))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor = $users->get($actorId);
            abort_unless($actor?->is_active && $actor->isAdmin(), 403);
            foreach ($assignments as $assignment) {
                $id = $data['approvers'][$assignment->closure_scope];
                // An explicit assignment cannot be reset to legacy policy.
                if ($id === null) {
                    if ($assignment->configured_at !== null) {
                        throw ValidationException::withMessages(['approvers.'.$assignment->closure_scope => 'Seleccione un aprobador activo para '.CommercialCommissionApprover::SCOPE_LABELS[$assignment->closure_scope].'; la asignación explícita no se puede vaciar.']);
                    }

                    continue;
                }
                $user = $users->get((int) $id);
                if (! $user?->is_active) {
                    throw ValidationException::withMessages(['approvers.'.$assignment->closure_scope => 'El aprobador de '.CommercialCommissionApprover::SCOPE_LABELS[$assignment->closure_scope].' debe existir y estar activo.']);
                }
                if ((int) $assignment->report_user_id === (int) $id && $assignment->configured_at !== null) {
                    continue;
                }
                DB::table('commercial_commission_approver_events')->insert([
                    'closure_scope' => $assignment->closure_scope,
                    'report_user_id' => $actorId,
                    'context' => json_encode(['previous_user_id' => $assignment->report_user_id, 'new_user_id' => (int) $id, 'actor_user_id' => $actorId], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
                $assignment->update(['report_user_id' => $id, 'configured_at' => now()]);
            }
        }, 3);

        return redirect()->route('reports.access-settings.index')->with('status', 'Aprobadores de comisiones actualizados.');
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless(ReportUserAccess::canManageReportUsers($request), 403);

        $definitions = ReportUserAccess::reportDefinitions();
        $rules = [];

        foreach (array_keys($definitions) as $reportKey) {
            $rules["minimum_roles.$reportKey"] = ['required', 'string', Rule::in(array_keys(ReportUserAccess::reportMinimumRoleOptions()))];
        }

        $data = $request->validate($rules);

        foreach ($definitions as $reportKey => $definition) {
            ReportAccessSetting::query()->updateOrCreate(
                ['report_key' => $reportKey],
                ['minimum_role' => $data['minimum_roles'][$reportKey]]
            );
        }

        ReportUserAccess::flushResolvedSettings();

        return redirect()
            ->route('reports.access-settings.index')
            ->with('status', 'Permisos por informe actualizados correctamente.');
    }
}
