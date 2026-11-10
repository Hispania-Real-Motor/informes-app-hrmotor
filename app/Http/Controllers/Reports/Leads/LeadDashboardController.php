<?php

namespace App\Http\Controllers\Reports\Leads;

use App\Http\Controllers\Controller;
use App\Services\Reports\Leads\InterestDatasetUnavailable;
use App\Services\Reports\Leads\MonthlyInterestProvisionalReader;
use App\Services\Reports\Leads\October2026ProvisionalInterestReader;
use App\Services\Reports\Leads\ProvisionalInterestSourceUnavailable;
use App\Services\Salesforce\InterestGenerationReader;
use App\Support\ReportUserAccess;
use Illuminate\Http\Request;

class LeadDashboardController extends Controller
{
    private const OCTOBER_2026_PROVISIONAL_PERIOD = 'october_2026';

    private const NOVEMBER_2026_PROVISIONAL_PERIOD = 'november_2026';

    public function index(
        Request $request,
        October2026ProvisionalInterestReader $octoberReader,
        MonthlyInterestProvisionalReader $monthlyReader,
        InterestGenerationReader $interestGenerations,
    ) {
        $requestedPeriod = $request->query('period');
        $requestedPeriod = is_string($requestedPeriod) ? $requestedPeriod : null;
        $requestedOctober = $requestedPeriod === self::OCTOBER_2026_PROVISIONAL_PERIOD;
        $requestedNovember = $requestedPeriod === self::NOVEMBER_2026_PROVISIONAL_PERIOD;
        if ($requestedOctober) {
            $this->assertOctoberAccess($request, $octoberReader);
        }
        if ($requestedNovember) {
            $this->assertNovemberAccess($request, $monthlyReader);
        }
        $officialAvailable = $requestedPeriod === null && $this->officialGenerationAvailable($interestGenerations);
        $useNovemberByDefault = $requestedPeriod === null && ! $officialAvailable
            && $this->canUseNovember($request, $monthlyReader);
        $useOctoberByDefault = $requestedPeriod === null
            && ! $useNovemberByDefault
            && $this->canUseOctober($request, $octoberReader)
            && ! $officialAvailable;
        $usesOctober = $requestedOctober || $useOctoberByDefault;
        $usesNovember = $requestedNovember || $useNovemberByDefault;
        $usesProvisional = $usesOctober || $usesNovember;

        $response = view('reports.leads.index', [
            'reportUserCanExport' => ReportUserAccess::canExportReport($request, 'leads'),
            'reportUserCanAudit' => ! $usesProvisional && ReportUserAccess::canAuditReport($request, 'leads'),
            'octoberProvisionalEnabled' => config('salesforce.october_2026_provisional_enabled') === true
                && ReportUserAccess::isAdmin($request),
            'novemberProvisionalEnabled' => config('salesforce.november_2026_provisional_enabled') === true
                && ReportUserAccess::isAdmin($request),
            'initialLeadPeriod' => $usesProvisional
                ? ($usesNovember ? self::NOVEMBER_2026_PROVISIONAL_PERIOD : self::OCTOBER_2026_PROVISIONAL_PERIOD)
                : $this->officialInitialPeriod($requestedPeriod),
            'isOctoberProvisional' => $usesProvisional,
            'provisionalPeriodLabel' => $usesNovember ? 'Noviembre 2026' : 'Octubre 2026',
        ]);

        return $usesProvisional ? response($response)->header('Cache-Control', 'no-store, private') : $response;
    }

    private function assertOctoberAccess(Request $request, October2026ProvisionalInterestReader $reader): void
    {
        abort_unless(config('salesforce.october_2026_provisional_enabled') === true, 404);
        abort_unless(ReportUserAccess::isAdmin($request), 403);

        try {
            $reader->dashboardProjection();
        } catch (ProvisionalInterestSourceUnavailable) {
            abort(503, 'Los datos de octubre están pendientes de actualización.');
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

    private function assertNovemberAccess(Request $request, MonthlyInterestProvisionalReader $reader): void
    {
        abort_unless(config('salesforce.november_2026_provisional_enabled') === true, 404);
        abort_unless(ReportUserAccess::isAdmin($request), 403);

        try {
            $reader->latestForMonth(2026, 11);
        } catch (ProvisionalInterestSourceUnavailable) {
            abort(503, 'Los datos de noviembre están pendientes de validación.');
        }
    }

    private function canUseNovember(Request $request, MonthlyInterestProvisionalReader $reader): bool
    {
        if (config('salesforce.november_2026_provisional_enabled') !== true || ! ReportUserAccess::isAdmin($request)) {
            return false;
        }

        try {
            $reader->latestForMonth(2026, 11);

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
        return in_array($period, ['last_30_days', 'current_month', 'previous_month', 'custom'], true) ? $period : null;
    }
}
