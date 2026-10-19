<?php

namespace App\Http\Controllers\Reports\CommercialCommissions;

use App\Http\Controllers\Controller;
use App\Models\CommercialCommissionAdjustment;
use App\Models\CommercialCommissionApprover;
use App\Models\CommercialCommissionClosure;
use App\Models\ReportUser;
use App\Services\Reports\CommercialCommissions\CommercialCommissionClosureService;
use App\Support\ReportUserAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CommercialCommissionClosureController extends Controller
{
    public function status(Request $request, CommercialCommissionClosureService $closures): JsonResponse
    {
        abort_unless(ReportUserAccess::canViewCommercialCommissions($request), 403);
        $statuses = $closures->statuses($request->query('month'));

        if (ReportUserAccess::isCommissionAuditor($request)) {
            $statuses = collect($statuses)->map(fn (array $status): array => [
                'month' => $status['month'],
                'closure_scope' => $status['closure_scope'],
                'status' => $status['status'],
                'approved_by' => isset($status['approved_by']['name'])
                    ? ['name' => $status['approved_by']['name']]
                    : null,
                'approved_at' => $status['approved_at'],
            ])->all();
        }

        return response()->json([
            'ok' => true,
            'closures' => $statuses,
        ]);
    }

    public function prepare(Request $request, CommercialCommissionClosureService $closures): RedirectResponse
    {
        abort_unless(ReportUserAccess::canPrepareEconomicClosures($request), 403);
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'closure_scope' => ['required', Rule::in(CommercialCommissionClosure::CLOSABLE_SCOPES)],
        ]);
        $closures->prepare($data['month'], $data['closure_scope'], $this->currentUser($request));

        return back()->with('status', 'Mes preparado y pendiente de aprobación.');
    }

    public function approve(Request $request, CommercialCommissionClosureService $closures): RedirectResponse
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m'], 'closure_scope' => ['required', Rule::in(CommercialCommissionClosure::CLOSABLE_SCOPES)]]);
        DB::transaction(function () use ($request, $data, $closures): void {
            // Assignment -> active actor -> closure: same order as administrative edits.
            $assignment = CommercialCommissionApprover::query()->where('closure_scope', $data['closure_scope'])->lockForUpdate()->first();
            abort_unless($assignment !== null, 403);
            $actor = ReportUser::query()->whereKey($request->session()->get('report_user_id'))->where('is_active', true)->lockForUpdate()->first();
            abort_unless($actor !== null, 403);
            $request->attributes->set('resolved_report_user', $actor);
            $request->attributes->remove('commission_approvers');
            abort_unless(ReportUserAccess::canApproveCommissionClosure($request, $data['closure_scope']), 403);
            $closures->approve($data['month'], $data['closure_scope'], $actor);
        }, 3);

        return back()->with('status', 'Cierre económico aprobado y fotografiado como definitivo.');
    }

    public function reopen(Request $request, CommercialCommissionClosureService $closures): RedirectResponse
    {
        abort_unless(ReportUserAccess::canReopenEconomicClosures($request), 403);
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'closure_scope' => ['required', Rule::in(CommercialCommissionClosure::CLOSABLE_SCOPES)],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $closures->reopen($data['month'], $data['closure_scope'], $data['reason'], $this->currentUser($request));

        return back()->with('status', 'Mes reabierto. El cierre anterior permanece en el historial.');
    }

    public function storeAdjustment(Request $request, CommercialCommissionClosureService $closures): RedirectResponse
    {
        abort_unless(ReportUserAccess::canManageEconomicClosures($request), 403);
        $data = $request->validate([
            'operation_id' => ['required', 'string', 'max:64'],
            'original_month' => ['required', 'date_format:Y-m'],
            'application_month' => ['nullable', 'date_format:Y-m'],
            'amount' => ['required', 'numeric'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        CommercialCommissionAdjustment::query()->create([
            'operation_id' => $data['operation_id'],
            'original_month' => $data['original_month'],
            'application_month' => $closures->nextOpenMonth($data['application_month'] ?? null),
            'amount' => $data['amount'],
            'reason' => $data['reason'],
            'status' => 'pending',
            'created_by' => $this->currentUser($request)->id,
            'source_context' => ['source' => 'manual_audited_adjustment'],
        ]);

        return back()->with('status', 'Ajuste económico registrado para el siguiente mes abierto.');
    }

    private function currentUser(Request $request): ReportUser
    {
        $user = ReportUser::query()->find((int) $request->session()->get('report_user_id'));
        abort_unless($user !== null, 403, 'No se ha podido resolver el usuario de auditoría.');

        return $user;
    }
}
