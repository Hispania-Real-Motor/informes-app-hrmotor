<?php

namespace App\Http\Controllers\Reports\ReservationsSales;

use App\Http\Controllers\Controller;
use App\Support\ReportUserAccess;
use Illuminate\Http\Request;

class ReservationsSalesDashboardController extends Controller
{
    public function index(Request $request)
    {
        return view('reports.reservations-sales.index', [
            'reportUserCanExport' => ReportUserAccess::canExport($request),
            'reportUserCanViewCommercialPerformance' => ReportUserAccess::canViewCommercialPerformance($request),
            'reportUserCanManageCommercialPerformanceTarget' => ReportUserAccess::canManageCommercialPerformanceTarget($request),
            'reportUserCanAuditCommercialPerformance' => ReportUserAccess::canAuditCommercialPerformance($request),
            'initialPeriod' => $request->query('period') === 'october_2026' ? 'october_2026' : null,
        ]);
    }
}
