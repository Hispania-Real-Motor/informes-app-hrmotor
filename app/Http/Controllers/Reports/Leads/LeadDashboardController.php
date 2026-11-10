<?php

namespace App\Http\Controllers\Reports\Leads;

use App\Http\Controllers\Controller;
use App\Services\Reports\Leads\InterestDatasetUnavailable;
use App\Services\Reports\Leads\MonthlyInterestProvisionalPeriod;
use App\Services\Reports\Leads\MonthlyInterestProvisionalReader;
use App\Services\Reports\Leads\October2026ProvisionalInterestReader;
use App\Services\Reports\Leads\ProvisionalInterestSourceUnavailable;
use App\Services\Salesforce\InterestGenerationReader;
use App\Support\ReportUserAccess;
use Carbon\CarbonImmutable;
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
        $requestedMonth = MonthlyInterestProvisionalPeriod::forKey($requestedPeriod);
        $requestedMonthly = $requestedMonth !== null;
        if ($requestedOctober) {
            $this->assertOctoberAccess($request, $octoberReader);
        }
        if ($requestedMonthly) {
            $this->assertMonthlyAccess($request, $monthlyReader, $requestedMonth);
        }
        $officialAvailable = $requestedPeriod === null && $this->officialGenerationAvailable($interestGenerations);
        $useMonthlyByDefault = $requestedPeriod === null && ! $officialAvailable
            && $this->canUseMonthly($request, $monthlyReader, MonthlyInterestProvisionalPeriod::forKey(self::NOVEMBER_2026_PROVISIONAL_PERIOD));
        $useOctoberByDefault = $requestedPeriod === null
            && ! $useMonthlyByDefault
            && $this->canUseOctober($request, $octoberReader)
            && ! $officialAvailable;
        $usesOctober = $requestedOctober || $useOctoberByDefault;
        $usesMonthly = $requestedMonthly || $useMonthlyByDefault;
        $usesProvisional = $usesOctober || $usesMonthly;
        $monthlyPeriodKey = $requestedMonthly ? $requestedPeriod : ($useMonthlyByDefault ? self::NOVEMBER_2026_PROVISIONAL_PERIOD : null);
        $monthlyPeriod = MonthlyInterestProvisionalPeriod::forKey($monthlyPeriodKey);
        $monthlyCutoff = $monthlyPeriod !== null
            ? data_get($monthlyReader->latestForMonth($monthlyPeriod['year'], $monthlyPeriod['month']), 'overview.period.effective_cutoff')
            : null;

        $response = view('reports.leads.index', [
            'reportUserCanExport' => ReportUserAccess::canExportReport($request, 'leads'),
            'reportUserCanAudit' => ! $usesProvisional && ReportUserAccess::canAuditReport($request, 'leads'),
            'octoberProvisionalEnabled' => config('salesforce.october_2026_provisional_enabled') === true
                && ReportUserAccess::isAdmin($request),
            'novemberProvisionalEnabled' => config('salesforce.november_2026_provisional_enabled') === true
                && ReportUserAccess::isAdmin($request),
            'initialLeadPeriod' => $usesProvisional
                ? ($monthlyPeriodKey ?? self::OCTOBER_2026_PROVISIONAL_PERIOD)
                : $this->officialInitialPeriod($requestedPeriod),
            'isOctoberProvisional' => $usesProvisional,
            'provisionalPeriodLabel' => $monthlyPeriod['label'] ?? 'Octubre 2026',
            'provisionalUpdatedUntil' => is_string($monthlyCutoff) && $monthlyCutoff !== ''
                ? CarbonImmutable::parse($monthlyCutoff, 'UTC')->setTimezone('Europe/Madrid')->format('d/m/Y H:i')
                : null,
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

    /** @param array{year:int,month:int,flag:string,label:string} $period */
    private function assertMonthlyAccess(Request $request, MonthlyInterestProvisionalReader $reader, array $period): void
    {
        abort_unless(config($period['flag']) === true, 404);
        abort_unless(ReportUserAccess::isAdmin($request), 403);

        try {
            $reader->latestForMonth($period['year'], $period['month']);
        } catch (ProvisionalInterestSourceUnavailable) {
            abort(503, 'Los datos mensuales están pendientes de validación.');
        }
    }

    /** @param array{year:int,month:int,flag:string,label:string} $period */
    private function canUseMonthly(Request $request, MonthlyInterestProvisionalReader $reader, array $period): bool
    {
        if (config($period['flag']) !== true || ! ReportUserAccess::isAdmin($request)) {
            return false;
        }

        try {
            $reader->latestForMonth($period['year'], $period['month']);

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
