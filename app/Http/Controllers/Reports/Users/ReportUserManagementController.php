<?php

namespace App\Http\Controllers\Reports\Users;

use App\Http\Controllers\Controller;
use App\Models\MasterDelegation;
use App\Models\ReportUser;
use App\Support\ReportUserAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ReportUserManagementController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        abort_unless(ReportUserAccess::canManageReportUsers($request), 403);

        return view('reports.users.index', [
            'reportUserRole' => ReportUserAccess::role($request),
            'users' => ReportUser::query()
                ->with('masterDelegation')
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->orderBy('email')
                ->get(),
            'roleOptions' => ReportUser::roleOptions(),
            'permissionOptions' => ReportUser::availablePermissions(),
            'areaZoneOptions' => ReportUser::areaZoneOptions(),
            'delegationOptions' => MasterDelegation::query()->where('is_active', true)->orderBy('delegation_name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(ReportUserAccess::canManageReportUsers($request), 403);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('report_users', 'email')],
            'password' => ['required', 'string', 'min:12', 'max:255'],
            'role' => ['required', 'string', Rule::in(ReportUser::availableRoles())],
            'area_zone' => [
                Rule::requiredIf(fn (): bool => $request->input('role') === ReportUser::ROLE_AREA_MANAGER),
                'nullable',
                'string',
                Rule::in(array_keys(ReportUser::areaZoneOptions())),
            ],
            'master_delegation_id' => [
                Rule::requiredIf(fn (): bool => $request->input('role') === ReportUser::ROLE_DELEGATION_MANAGER),
                'nullable', 'integer', Rule::exists('master_delegations', 'id')->where('is_active', true),
            ],
            'salesforce_user_id' => [
                Rule::requiredIf(fn (): bool => $request->input('role') === ReportUser::ROLE_COMMERCIAL),
                'nullable', 'string', 'max:18', Rule::exists('salesforce_users', 'salesforce_id'),
            ],
            'is_active' => ['nullable', 'boolean'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in(ReportUser::availablePermissions())],
        ]);

        ReportUser::query()->create([
            'name' => ($data['name'] ?? null) ?: null,
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => $data['role'],
            'area_zone' => $data['role'] === ReportUser::ROLE_AREA_MANAGER ? $data['area_zone'] : null,
            'master_delegation_id' => $data['role'] === ReportUser::ROLE_DELEGATION_MANAGER ? $data['master_delegation_id'] : null,
            'salesforce_user_id' => $data['role'] === ReportUser::ROLE_COMMERCIAL ? $data['salesforce_user_id'] : null,
            'is_active' => (bool) ($data['is_active'] ?? false),
            'permissions' => array_values($data['permissions'] ?? []),
        ]);

        return redirect()
            ->route('reports.users.index')
            ->with('status', 'Usuario creado correctamente.');
    }

    public function edit(Request $request, ReportUser $reportUser): View|RedirectResponse
    {
        abort_unless(ReportUserAccess::canManageReportUsers($request), 403);

        return view('reports.users.edit', [
            'reportUserRole' => ReportUserAccess::role($request),
            'managedUser' => $reportUser,
            'roleOptions' => ReportUser::roleOptions(),
            'permissionOptions' => ReportUser::availablePermissions(),
            'areaZoneOptions' => ReportUser::areaZoneOptions(),
            'delegationOptions' => MasterDelegation::query()->where('is_active', true)->orderBy('delegation_name')->get(),
        ]);
    }

    public function update(Request $request, ReportUser $reportUser): RedirectResponse
    {
        abort_unless(ReportUserAccess::canManageReportUsers($request), 403);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('report_users', 'email')->ignore($reportUser->id)],
            'password' => ['nullable', 'string', 'min:12', 'max:255'],
            'role' => ['required', 'string', Rule::in(ReportUser::availableRoles())],
            'area_zone' => [
                Rule::requiredIf(fn (): bool => $request->input('role') === ReportUser::ROLE_AREA_MANAGER),
                'nullable',
                'string',
                Rule::in(array_keys(ReportUser::areaZoneOptions())),
            ],
            'master_delegation_id' => [
                Rule::requiredIf(fn (): bool => $request->input('role') === ReportUser::ROLE_DELEGATION_MANAGER),
                'nullable', 'integer', Rule::exists('master_delegations', 'id')->where('is_active', true),
            ],
            'salesforce_user_id' => [
                Rule::requiredIf(fn (): bool => $request->input('role') === ReportUser::ROLE_COMMERCIAL),
                'nullable', 'string', 'max:18', Rule::exists('salesforce_users', 'salesforce_id'),
            ],
            'is_active' => ['nullable', 'boolean'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in(ReportUser::availablePermissions())],
        ]);

        $isActive = (bool) ($data['is_active'] ?? false);
        $role = (string) $data['role'];
        $currentUserId = (int) $request->session()->get('report_user_id');

        if ($currentUserId === $reportUser->id && ! $isActive) {
            return back()->withErrors(['is_active' => 'No puedes desactivar tu propio usuario.'])->withInput();
        }

        if ($currentUserId === $reportUser->id && $role !== ReportUser::ROLE_ADMIN) {
            return back()->withErrors(['role' => 'No puedes quitarte a ti mismo el rol de administrador.'])->withInput();
        }

        if ($this->wouldRemoveLastActiveAdmin($reportUser, $role, $isActive)) {
            return back()->withErrors(['role' => 'Debe existir al menos un administrador activo.'])->withInput();
        }

        $payload = [
            'name' => ($data['name'] ?? null) ?: null,
            'email' => $data['email'],
            'role' => $role,
            'area_zone' => $role === ReportUser::ROLE_AREA_MANAGER ? $data['area_zone'] : null,
            'master_delegation_id' => $role === ReportUser::ROLE_DELEGATION_MANAGER ? $data['master_delegation_id'] : null,
            'salesforce_user_id' => $role === ReportUser::ROLE_COMMERCIAL ? $data['salesforce_user_id'] : null,
            'is_active' => $isActive,
            'permissions' => array_values($data['permissions'] ?? []),
        ];

        if (filled($data['password'] ?? null)) {
            $payload['password'] = $data['password'];
            $payload['password_changed_at'] = now();
        }

        $reportUser->fill($payload)->save();

        if ($currentUserId === $reportUser->id) {
            $request->session()->put('report_user_email', $reportUser->email);
            $request->session()->put('informes_user', $reportUser->email);
            $request->session()->put('report_user_role', $reportUser->role);
        }

        return redirect()
            ->route('reports.users.index')
            ->with('status', 'Usuario actualizado correctamente.');
    }

    public function destroy(Request $request, ReportUser $reportUser): RedirectResponse
    {
        abort_unless(ReportUserAccess::canManageReportUsers($request), 403);

        $currentUserId = (int) $request->session()->get('report_user_id');

        if ($currentUserId === $reportUser->id) {
            return back()->withErrors(['delete' => 'No puedes eliminar tu propio usuario.']);
        }

        if ($this->wouldDeleteLastActiveAdmin($reportUser)) {
            return back()->withErrors(['delete' => 'Debe existir al menos un administrador activo.']);
        }

        $reportUser->delete();

        return redirect()
            ->route('reports.users.index')
            ->with('status', 'Usuario eliminado correctamente.');
    }

    private function wouldRemoveLastActiveAdmin(ReportUser $reportUser, string $newRole, bool $newIsActive): bool
    {
        if ($reportUser->role !== ReportUser::ROLE_ADMIN || ! $reportUser->is_active) {
            return false;
        }

        if ($newRole === ReportUser::ROLE_ADMIN && $newIsActive) {
            return false;
        }

        return ReportUser::query()
            ->where('role', ReportUser::ROLE_ADMIN)
            ->where('is_active', true)
            ->whereKeyNot($reportUser->id)
            ->count() === 0;
    }

    private function wouldDeleteLastActiveAdmin(ReportUser $reportUser): bool
    {
        if ($reportUser->role !== ReportUser::ROLE_ADMIN || ! $reportUser->is_active) {
            return false;
        }

        return ReportUser::query()
            ->where('role', ReportUser::ROLE_ADMIN)
            ->where('is_active', true)
            ->whereKeyNot($reportUser->id)
            ->count() === 0;
    }
}
