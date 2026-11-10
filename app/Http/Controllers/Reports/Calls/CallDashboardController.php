<?php

namespace App\Http\Controllers\Reports\Calls;

use App\Http\Controllers\Controller;
use App\Support\ReportUserAccess;
use Illuminate\Http\Request;

class CallDashboardController extends Controller
{
    public function index(Request $request)
    {
        return view('reports.calls.index', [
            'reportUserCanAudit' => ReportUserAccess::canAuditReport($request, 'calls'),
            'initialPeriod' => $request->query('period') === 'october_2026' ? 'october_2026' : null,
        ]);
    }
}
