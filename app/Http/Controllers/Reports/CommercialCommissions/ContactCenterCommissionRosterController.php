<?php

namespace App\Http\Controllers\Reports\CommercialCommissions;

use App\Http\Controllers\Controller;
use App\Models\ContactCenterCommissionRosterMember;
use App\Services\Reports\ContactCenterCommissions\ContactCenterCommissionRosterService;
use App\Support\ReportUserAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ContactCenterCommissionRosterController extends Controller
{
    public function __construct(private readonly ContactCenterCommissionRosterService $roster) {}

    public function index(Request $request): View
    {
        abort_unless(ReportUserAccess::canManageReportUsers($request), 403);

        return view('reports.commercial-commissions.contact-center-roster', [
            'members' => ContactCenterCommissionRosterMember::query()->orderBy('salesforce_user_id')->orderBy('effective_from')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(ReportUserAccess::canManageReportUsers($request), 403);
        $this->roster->save($this->validated($request));

        return redirect()->route('reports.commission-settings.contact-center-roster.index')->with('status', 'Miembro del roster añadido correctamente.');
    }

    public function update(Request $request, ContactCenterCommissionRosterMember $member): RedirectResponse
    {
        abort_unless(ReportUserAccess::canManageReportUsers($request), 403);
        $this->roster->save($this->validated($request), $member);

        return redirect()->route('reports.commission-settings.contact-center-roster.index')->with('status', 'Vigencia del roster actualizada correctamente.');
    }

    /** @return array{salesforce_user_id:string,display_name:string,effective_from:string,effective_until:?string} */
    private function validated(Request $request): array
    {
        return $request->validate([
            'salesforce_user_id' => ['required', 'string', 'regex:/^005[A-Za-z0-9]{12,15}$/'],
            'display_name' => ['required', 'string', 'max:255'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ]);
    }
}
