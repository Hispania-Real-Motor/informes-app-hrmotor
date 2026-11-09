<?php

namespace App\Http\Controllers\Reports\Leads;

use App\Http\Controllers\Controller;
use App\Services\Reports\Leads\InterestDatasetUnavailable;
use App\Services\Reports\Leads\October2026ProvisionalInterestReader;
use App\Services\Reports\Leads\ProvisionalInterestSourceUnavailable;
use App\Services\Salesforce\InterestGenerationReader;
use App\Support\ReportUserAccess;
use Illuminate\Http\Request;

class LeadDashboardController extends Controller
{
    private const OCTOBER_2026_PROVISIONAL_PERIOD = 'october_2026';

    public function index(
        Request $request,
        October2026ProvisionalInterestReader $octoberReader,
        InterestGenerationReader $interestGenerations,
    ) {
        $requestedPeriod = $request->query('period');
        $requestedPeriod = is_string($requestedPeriod) ? $requestedPeriod : null;
        $requestedOctober = $requestedPeriod === self::OCTOBER_2026_PROVISIONAL_PERIOD;
        if ($requestedOctober) {
            $this->assertOctoberAccess($request, $octoberReader);
        }
        $useOctoberByDefault = $requestedPeriod === null
            && $this->canUseOctober($request, $octoberReader)
            && ! $this->officialGenerationAvailable($interestGenerations);
        $usesOctober = $requestedOctober || $useOctoberByDefault;

        $response = view('reports.leads.index', [
            'reportUserCanExport' => ReportUserAccess::canExportReport($request, 'leads'),
            'reportUserCanAudit' => ! $usesOctober && ReportUserAccess::canAuditReport($request, 'leads'),
            'octoberProvisionalEnabled' => config('salesforce.october_2026_provisional_enabled') === true
                && ReportUserAccess::isAdmin($request),
            'initialLeadPeriod' => $usesOctober
                ? self::OCTOBER_2026_PROVISIONAL_PERIOD
                : $this->officialInitialPeriod($requestedPeriod),
            'isOctoberProvisional' => $usesOctober,
        ]);

        return $usesOctober ? response($response)->header('Cache-Control', 'no-store, private') : $response;
    }

    private function assertOctoberAccess(Request $request, October2026ProvisionalInterestReader $reader): void
    {
        abort_unless(config('salesforce.october_2026_provisional_enabled') === true, 404);
        abort_unless(ReportUserAccess::isAdmin($request), 403);

        try {
            $reader->dashboardProjection();
        } catch (ProvisionalInterestSourceUnavailable) {
            abort(503, 'La fuente provisional no supera las comprobaciones de integridad.');
        }
    }

    private function canUseOctober(Request $request, October2026ProvisionalInterestReader $reader): bool
    {
        if (config('salesforce.october_2026_provisional_enabled') !== true || ! ReportUserAccess::isAdmin($request)) {
            return false;
        }

        try {
            $reader->dashboardProjection();

            return true;
        } catch (ProvisionalInterestSourceUnavailable) {
            return false;
        }
    }

    private function officialGenerationAvailable(InterestGenerationReader $reader): bool
    {
        try {
            $reader->generation();

            return true;
        } catch (InterestDatasetUnavailable) {
            return false;
        }
    }

    private function officialInitialPeriod(?string $period): ?string
    {
        return in_array($period, ['current_month', 'previous_month', 'custom'], true) ? $period : null;
    }
}
