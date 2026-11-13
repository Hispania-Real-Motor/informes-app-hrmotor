<?php

namespace App\Http\Controllers\Reports\Campaigns;

use App\Http\Controllers\Controller;
use App\Models\CampaignOperationalClassification;
use App\Support\ReportUserAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CampaignDashboardController extends Controller
{
    public function index(Request $request)
    {
        if (! ReportUserAccess::canViewCampaigns($request)) {
            return redirect()->route('reports.leads.index');
        }

        $response = response()->view('reports.campaigns.index', [
            'reportUserRole' => ReportUserAccess::role($request),
            'reportUserCanExport' => ReportUserAccess::canExportReport($request, 'campaigns'),
            'reportUserCanAudit' => ReportUserAccess::canAuditReport($request, 'campaigns'),
            'reportUserCanViewOperationalCampaigns' => ReportUserAccess::isAdmin($request)
                && (config('salesforce.october_2026_provisional_enabled') === true
                    || config('salesforce.november_2026_provisional_enabled') === true),
            'campaignOperationalOctoberEnabled' => config('salesforce.october_2026_provisional_enabled') === true,
            'campaignOperationalNovemberEnabled' => config('salesforce.november_2026_provisional_enabled') === true,
        ]);

        return $request->query('source') === 'operational'
            ? $response->header('Cache-Control', 'no-store, private') : $response;
    }

    public function classify(Request $request)
    {
        abort_unless(ReportUserAccess::isAdmin($request) || ReportUserAccess::isDirector($request), 403);
        $data = $request->validate([
            'platform' => ['required', 'in:meta,google_ads'],
            'account_id' => ['nullable', 'string', 'max:255'],
            'campaign_id' => ['required', 'string', 'max:255'],
            'classification' => ['required', 'in:real,test,pending_review'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $classification = CampaignOperationalClassification::query()->updateOrCreate(
            [
                'platform' => $data['platform'],
                'account_id' => trim((string) ($data['account_id'] ?? '')),
                'campaign_id' => trim($data['campaign_id']),
            ],
            [
                'classification' => $data['classification'],
                'reason' => $data['reason'],
                'classified_by' => ReportUserAccess::reportUser($request)?->id,
                'classified_at' => now(),
            ],
        );
        Cache::forever('campaign_dashboard_cache_version', (int) Cache::get('campaign_dashboard_cache_version', 1) + 1);

        return response()->json(['ok' => true, 'classification' => $classification]);
    }
}
