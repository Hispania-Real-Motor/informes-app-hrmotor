<?php

namespace App\Http\Controllers\Reports\Leads;

use App\Http\Controllers\Controller;
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
    ) {}

    public function resumen(Request $request): JsonResponse
    {
        return $this->timedJson($request, fn (?ReportServerTiming $timing): array => $this->dataset->summary($request, $timing));
    }

    public function kpis(Request $request): JsonResponse
    {
        return $this->resumen($request);
    }

    public function portales(Request $request): JsonResponse
    {
        return response()->json($this->dataset->portalRows($request));
    }

    public function portalDetalle(Request $request): JsonResponse
    {
        return $this->portales($request);
    }

    public function delegaciones(Request $request): JsonResponse
    {
        return response()->json($this->dataset->delegationRows($request));
    }

    public function comerciales(Request $request): JsonResponse
    {
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

    public function calidadDato(Request $request): JsonResponse
    {
        return response()->json([
            'items' => [],
            'message' => 'La calidad de dato CSV no se muestra en la fase Salesforce del dashboard.',
        ]);
    }

    public function kpiAudit(Request $request): JsonResponse
    {
        abort_unless(ReportUserAccess::canAuditReport($request, 'leads'), 403);

        return response()->json($this->dataset->kpiAudit($request));
    }

    public function leadAudit(Request $request): JsonResponse
    {
        abort_unless(ReportUserAccess::canAuditReport($request, 'leads'), 403);

        $ids = $request->input('ids', []);
        $ids = is_array($ids) ? $ids : preg_split('/[\s,;]+/', (string) $ids, -1, PREG_SPLIT_NO_EMPTY);

        return response()->json($this->dataset->leadAudit(array_slice($ids ?: [], 0, 200), $request));
    }

    public function exportKpiAuditCsv(Request $request): StreamedResponse
    {
        abort_unless(ReportUserAccess::canAuditReport($request, 'leads'), 403);

        $payload = $this->dataset->kpiAudit($request);
        $rows = $payload['items'] ?? [];
        $metric = $payload['metric'] ?? 'leads_totales';
        $headers = $rows === [] ? ['Interest ID'] : array_keys($rows[0]);

        return response()->streamDownload(function () use ($rows, $headers): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, $headers);

            foreach ($rows as $row) {
                fputcsv($output, array_map($this->csvValue(...), $row));
            }

            fclose($output);
        }, "intereses-auditoria-{$metric}.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportReconciliationAuditCsv(Request $request): StreamedResponse
    {
        abort_unless(ReportUserAccess::canAuditReport($request, 'leads'), 403);

        $rows = $this->dataset->reconciliationAudit($request);
        $headers = $rows === [] ? ['Interest ID'] : array_keys($rows[0]);

        return response()->streamDownload(function () use ($rows, $headers): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $headers);
            foreach ($rows as $row) {
                fputcsv($output, array_map($this->csvValue(...), $row));
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
