<?php

namespace App\Http\Controllers\Reports\Leads;

use App\Http\Controllers\Controller;
use App\Services\Reports\Leads\InterestProvisionalPreviewService;
use App\Support\ReportUserAccess;
use Illuminate\Http\Request;

final class InterestProvisionalPreviewController extends Controller
{
    public function __invoke(Request $request, InterestProvisionalPreviewService $preview)
    {
        abort_unless(ReportUserAccess::isAdmin($request), 403);
        abort_unless(config('salesforce.interest_provisional_preview_enabled') === true, 404);

        return response()->view('reports.leads.provisional-preview', [
            'preview' => $preview->septemberOctober2026(),
        ])->header('Cache-Control', 'no-store, private');
    }
}
