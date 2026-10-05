<?php

namespace App\Http\Controllers\Reports\Summary;

use App\Http\Controllers\Controller;
use App\Services\Analytics\Executive\ExecutiveSummaryService;
use App\Support\ReportUserAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SummaryDashboardController extends Controller
{
    public function __construct(
        private readonly ExecutiveSummaryService $executiveSummary,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        if (ReportUserAccess::canViewReport($request, 'summary')) {
            return view('reports.summary.index', [
                'summary' => $this->executiveSummary->build(),
            ]);
        }

        $routeName = ReportUserAccess::defaultOperationalRouteName($request);

        abort_if($routeName === null, 403);

        return redirect()->route($routeName);
    }
}
