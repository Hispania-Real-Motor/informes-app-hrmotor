<?php

namespace App\Http\Controllers\Reports\Leads;

use App\Http\Controllers\Controller;
use App\Services\Reports\Leads\MonthlyInterestProvisionalPeriod;
use App\Services\Reports\Leads\October2026ProvisionalInterestDashboardDatasetService;
use App\Services\Reports\Leads\ProvisionalInterestSourceUnavailable;
use App\Services\Reports\Leads\SalesforceInterestDashboardDatasetService;
use App\Support\ReportServerTiming;
use App\Support\ReportUserAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeadDashboardDataController extends Controller
{
    public function __construct(
        private readonly SalesforceInterestDashboardDatasetService $dataset,
        private readonly October2026ProvisionalInterestDashboardDatasetService $octoberDataset,
    ) {}

    public function resumen(Request $request): JsonResponse
    {
        if ($this->isProvisional($request)) {
            return $this->provisionalJson(fn (): array => $this->octoberDataset->summary($request));
        }

        return $this->timedJson($request, fn (?ReportServerTiming $timing): array => $this->dataset->summary($request, $timing));
    }

    public function kpis(Request $request): JsonResponse
    {
        return $this->resumen($request);
    }

    public function portales(Request $request): JsonResponse
    {
        if ($this->isProvisional($request)) {
            return $this->provisionalJson(fn (): array => $this->octoberDataset->sourceRows($request));
        }

        return response()->json($this->dataset->portalRows($request));
    }

    public function portalDetalle(Request $request): JsonResponse
    {
        return $this->portales($request);
    }

    public function delegaciones(Request $request): JsonResponse
    {
        if ($this->isProvisional($request)) {
            return $this->provisionalJson(fn (): array => $this->octoberDataset->delegationRows($request));
        }

        return response()->json($this->dataset->delegationRows($request));
    }

    public function comerciales(Request $request): JsonResponse
    {
        if ($this->isProvisional($request)) {
            return $this->provisionalJson(fn (): array => $this->octoberDataset->commercialRows($request));
        }

        return response()->json($this->dataset->commercialRows($request));
    }

    public function comparativa(Request $request): JsonResponse
    {
        return $this->resumen($request);
    }

    private function timedJson(Request $request, callable $callback): JsonResponse
    {
        $timing = ReportServerTiming::forRequest($request);
        $response = response()->json($callback($timing));

        if ($timing !== null && $timing->headerValue() !== '') {
            $response->headers->set('Server-Timing', $timing->headerValue());
        }

        return $response;
    }

    private function isProvisional(Request $request): bool
    {
        return $request->query('period') === 'october_2026'
            || MonthlyInterestProvisionalPeriod::forKey($request->query('period')) !== null;
    }

    private function provisionalJson(callable $callback): JsonResponse
    {
        $month = MonthlyInterestProvisionalPeriod::forKey(request()->query('period'));
        $flag = $month['flag'] ?? 'salesforce.october_2026_provisional_enabled';
        abort_unless(config($flag) === true, 404);
        abort_unless(ReportUserAccess::isAdmin(request()), 403);

        try {
            return response()->json($callback())->header('Cache-Control', 'no-store, private');
        } catch (ProvisionalInterestSourceUnavailable) {
            abort(503, 'Los datos mensuales están pendientes de validación.');
        }
    }

    public function calidadDato(Request $request): JsonResponse
    {
        if ($this->isProvisional($request)) {
            return $this->provisionalJson(fn (): array => [
                'items' => [],
                'message' => 'El detalle de auditoría no está disponible para este inventario mensual.',
            ]);
        }

        return response()->json([
            'items' => [],
            'message' => 'La calidad de dato CSV no se muestra en la fase Salesforce del dashboard.',
        ]);
    }

    public function kpiAudit(Request $request): JsonResponse
    {
        abort_if($this->isProvisional($request), 404);
        abort_unless(ReportUserAccess::canAuditReport($request, 'leads'), 403);

        return response()->json($this->dataset->kpiAudit($request));
    }

    public function leadAudit(Request $request): JsonResponse
    {
        abort_if($this->isProvisional($request), 404);
        abort_unless(ReportUserAccess::canAuditReport($request, 'leads'), 403);

        $ids = $request->input('ids', []);
        $ids = is_array($ids) ? $ids : preg_split('/[\s,;]+/', (string) $ids, -1, PREG_SPLIT_NO_EMPTY);

        return response()->json($this->dataset->leadAudit(array_slice($ids ?: [], 0, 200), $request));
    }

    public function exportKpiAuditCsv(Request $request): StreamedResponse
    {
        abort_if($this->isProvisional($request), 404);
        abort_unless(ReportUserAccess::canAuditReport($request, 'leads'), 403);

        $rows = $this->dataset->kpiAuditRows($request);
        $metric = $request->string('metric')->toString() ?: 'leads_totales';

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'w');
            $headerWritten = false;

            foreach ($rows as $row) {
                if (! $headerWritten) {
                    fputcsv($output, array_keys($row));
                    $headerWritten = true;
                }
                fputcsv($output, array_map($this->csvValue(...), $row));
            }
            if (! $headerWritten) {
                fputcsv($output, ['Interest ID']);
            }

            fclose($output);
        }, "intereses-auditoria-{$metric}.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportReconciliationAuditCsv(Request $request): StreamedResponse
    {
        abort_if($this->isProvisional($request), 404);
        abort_unless(ReportUserAccess::canAuditReport($request, 'leads'), 403);

        $rows = $this->dataset->reconciliationAuditRows($request);

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            $headerWritten = false;
            foreach ($rows as $row) {
                if (! $headerWritten) {
                    fputcsv($output, array_keys($row));
                    $headerWritten = true;
                }
                fputcsv($output, array_map($this->csvValue(...), $row));
            }
            if (! $headerWritten) {
                fputcsv($output, ['Interest ID']);
            }
            fclose($output);
        }, 'intereses-conciliacion.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function csvValue(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 'Si' : 'No';
        }

        if (is_array($value)) {
            return json_encode(
                $value,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            );
        }

        return $value;
    }
}
