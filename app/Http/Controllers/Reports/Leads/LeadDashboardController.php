<?php

namespace App\Http\Controllers\Reports\Leads;

use App\Http\Controllers\Controller;
use App\Services\Reports\Leads\October2026ProvisionalInterestReader;
use App\Services\Reports\Leads\ProvisionalInterestSourceUnavailable;
use App\Support\ReportUserAccess;
use Illuminate\Http\Request;

class LeadDashboardController extends Controller
{
    private const OCTOBER_2026_PROVISIONAL_PERIOD = 'october_2026';

    public function index(Request $request, October2026ProvisionalInterestReader $octoberReader)
    {
        if ($request->query('period') === self::OCTOBER_2026_PROVISIONAL_PERIOD) {
            return $this->october2026Provisional($request, $octoberReader);
        }

        return view('reports.leads.index', [
            'reportUserCanExport' => ReportUserAccess::canExportReport($request, 'leads'),
            'reportUserCanAudit' => ReportUserAccess::canAuditReport($request, 'leads'),
            'octoberProvisionalEnabled' => config('salesforce.october_2026_provisional_enabled') === true
                && ReportUserAccess::isAdmin($request),
        ]);
    }

    private function october2026Provisional(Request $request, October2026ProvisionalInterestReader $reader)
    {
        abort_unless(config('salesforce.october_2026_provisional_enabled') === true, 404);
        abort_unless(ReportUserAccess::isAdmin($request), 403);

        try {
            $overview = $reader->overview();
        } catch (ProvisionalInterestSourceUnavailable) {
            abort(503, 'La fuente provisional no supera las comprobaciones de integridad.');
        }

        return response()->view('reports.leads.october-2026-provisional', compact('overview'))
            ->header('Cache-Control', 'no-store, private');
    }
}
