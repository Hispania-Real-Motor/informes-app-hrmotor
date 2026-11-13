<?php

namespace App\Http\Controllers\Reports\Leads;

use App\Http\Controllers\Controller;
use App\Services\Reports\Leads\InterestDatasetUnavailable;
use App\Services\Reports\Leads\MonthlyInterestProvisionalPeriod;
use App\Services\Reports\Leads\MonthlyInterestProvisionalReader;
use App\Services\Reports\Leads\October2026ProvisionalInterestReader;
use App\Services\Reports\Leads\OperationalInterestMonthlyReader;
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
        OperationalInterestMonthlyReader $operationalReader,
        InterestGenerationReader $interestGenerations,
    ) {
        $requestedPeriod = $request->query('period');
        $requestedPeriod = is_string($requestedPeriod) ? $requestedPeriod : null;
        $requestedOctober = $requestedPeriod === self::OCTOBER_2026_PROVISIONAL_PERIOD;
        $requestedMonth = MonthlyInterestProvisionalPeriod::forKey($requestedPeriod);
        $requestedMonthly = $requestedMonth !== null && ! $requestedOctober;
        if ($requestedOctober) {
            $this->assertOctoberAccess($request, $octoberReader);
        }
        if ($requestedMonthly) {
            $this->assertMonthlyAccess($request, $monthlyReader, $operationalReader, $requestedMonth);
        }
        $officialAvailable = $requestedPeriod === null && $this->officialGenerationAvailable($interestGenerations);
        $useMonthlyByDefault = $requestedPeriod === null && ! $officialAvailable
            && $this->canUseMonthly($request, $monthlyReader, $operationalReader, MonthlyInterestProvisionalPeriod::forKey(self::NOVEMBER_2026_PROVISIONAL_PERIOD));
        $useOctoberByDefault = $requestedPeriod === null
            && ! $useMonthlyByDefault
            && $this->canUseOctober($request, $octoberReader)
            && ! $officialAvailable;
        $usesOctober = $requestedOctober || $useOctoberByDefault;
        $usesMonthly = $requestedMonthly || $useMonthlyByDefault;
        $usesProvisional = $usesOctober || $usesMonthly;
        $operationalOctober = $usesOctober && $operationalReader->hasActiveForMonth(2026, 10);
        $monthlyPeriodKey = $requestedMonthly ? $requestedPeriod
            : ($useMonthlyByDefault ? self::NOVEMBER_2026_PROVISIONAL_PERIOD
                : ($operationalOctober ? self::OCTOBER_2026_PROVISIONAL_PERIOD : null));
        $monthlyPeriod = MonthlyInterestProvisionalPeriod::forKey($monthlyPeriodKey);
        $monthlySnapshot = $monthlyPeriod !== null
            ? $this->monthlySnapshot($monthlyReader, $operationalReader, $monthlyPeriod) : null;
        $operationalMonth = isset($monthlySnapshot['operational_month']);
        $monthlyTimestamp = $operationalMonth
            ? data_get($monthlySnapshot, 'overview.validated_at')
            : data_get($monthlySnapshot, 'overview.period.effective_cutoff');

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
            'isOperationalMonthly' => $operationalMonth,
            'provisionalUpdatedUntil' => is_string($monthlyTimestamp) && $monthlyTimestamp !== ''
                ? CarbonImmutable::parse($monthlyTimestamp, 'UTC')->setTimezone('Europe/Madrid')->format('d/m/Y H:i')
                : null,
        ]);

        return $usesProvisional ? response($response)->header('Cache-Control', 'no-store, private') : $response;
    }

    private function assertOctoberAccess(Request $request, October2026ProvisionalInterestReader $reader): void
    {
        abort_unless(config('salesforce.october_2026_provisional_enabled') === true, 404);
        abort_unless(ReportUserAccess::isAdmin($request), 403);

        try {
            $operational = app(OperationalInterestMonthlyReader::class);
            $operational->hasActiveForMonth(2026, 10)
                ? $operational->latestForMonth(2026, 10) : $reader->dashboardProjection();
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
            $operational = app(OperationalInterestMonthlyReader::class);
            $operational->hasActiveForMonth(2026, 10)
                ? $operational->latestForMonth(2026, 10) : $reader->dashboardProjection();

            return true;
        } catch (ProvisionalInterestSourceUnavailable) {
            return false;
        }
    }

    /** @param array{year:int,month:int,flag:string,label:string} $period */
    private function assertMonthlyAccess(Request $request, MonthlyInterestProvisionalReader $reader, OperationalInterestMonthlyReader $operational, array $period): void
    {
        abort_unless(config($period['flag']) === true, 404);
        abort_unless(ReportUserAccess::isAdmin($request), 403);

        try {
            $this->monthlySnapshot($reader, $operational, $period);
        } catch (ProvisionalInterestSourceUnavailable) {
            abort(503, 'Los datos mensuales están pendientes de validación.');
        }
    }

    /** @param array{year:int,month:int,flag:string,label:string} $period */
    private function canUseMonthly(Request $request, MonthlyInterestProvisionalReader $reader, OperationalInterestMonthlyReader $operational, array $period): bool
    {
        if (config($period['flag']) !== true || ! ReportUserAccess::isAdmin($request)) {
            return false;
        }

        try {
            $this->monthlySnapshot($reader, $operational, $period);

            return true;
        } catch (ProvisionalInterestSourceUnavailable) {
            // An activated operational pointer must never silently fall back to October.
            if ($operational->hasActiveForMonth($period['year'], $period['month'])) {
                abort(503, 'Los datos mensuales están pendientes de validación.');
            }

            return false;
        }
    }

    /** @param array{year:int,month:int,flag:string,label:string} $period */
    private function monthlySnapshot(MonthlyInterestProvisionalReader $reader, OperationalInterestMonthlyReader $operational, array $period): array
    {
        if ($operational->hasActiveForMonth($period['year'], $period['month'])) {
            return $operational->latestForMonth($period['year'], $period['month']);
        }

        return $reader->latestForMonth($period['year'], $period['month']);
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
