<?php

namespace App\Http\Controllers\Reports\Leads;

use App\Http\Controllers\Controller;
use App\Services\Reports\Leads\October2026ProvisionalInterestReader;
use App\Services\Reports\Leads\ProvisionalInterestSourceUnavailable;
use App\Support\ReportUserAccess;
use Illuminate\Http\Request;

final class October2026ProvisionalInterestController extends Controller
{
    public function __invoke(Request $request, October2026ProvisionalInterestReader $reader)
    {
        abort_unless(ReportUserAccess::isAdmin($request), 403);
        abort_unless(config('salesforce.october_2026_provisional_enabled') === true, 404);

        try {
            $overview = $reader->overview();
        } catch (ProvisionalInterestSourceUnavailable) {
            abort(503, 'La fuente provisional no supera las comprobaciones de integridad.');
        }

        return response()->view('reports.leads.october-2026-provisional', compact('overview'))
            ->header('Cache-Control', 'no-store, private');
    }
}
