<?php

namespace App\Http\Controllers\Reports\ReservationsSales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReservationsSales\CommercialPerformanceAuditRequest;
use App\Http\Requests\Reports\ReservationsSales\CommercialPerformanceRequest;
use App\Http\Requests\Reports\ReservationsSales\CommercialPerformanceTargetRequest;
use App\Services\Reports\ReservationsSales\CommercialPerformanceAuditService;
use App\Services\Reports\ReservationsSales\CommercialPerformanceDatasetService;
use App\Services\Reports\ReservationsSales\ReservationsSalesDashboardDatasetService;
use App\Support\CsvValueSerializer;
use App\Support\ReportUserAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReservationsSalesDashboardDataController extends Controller
{
    public function __construct(
        private readonly ReservationsSalesDashboardDatasetService $dataset,
        private readonly CommercialPerformanceDatasetService $commercialPerformance,
        private readonly CommercialPerformanceAuditService $commercialPerformanceAudit,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        return response()->json($this->dataset->summary($request));
    }

    public function commercials(Request $request): JsonResponse
    {
        return response()->json($this->dataset->commercialRows($request));
    }

    public function portals(Request $request): JsonResponse
    {
        return response()->json($this->dataset->portalRows($request));
    }

    public function commercialPerformance(CommercialPerformanceRequest $request): JsonResponse
    {
        $filters = $request->validated();
        if (ReportUserAccess::isAreaManager($request)) {
            $accessZone = ReportUserAccess::areaZoneLabel($request);
            abort_if($accessZone === null, 403, 'El usuario no tiene una zona configurada.');
            $filters['access_zone'] = $accessZone;
        }

        return response()->json($this->commercialPerformance->payload($filters));
    }

    public function updateCommercialPerformanceTarget(CommercialPerformanceTargetRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $target = $this->commercialPerformance->updateTarget(
            $validated['month'],
            (int) $validated['reservations_target'],
            ReportUserAccess::reportUser($request)?->id,
        );

        return response()->json([
            'ok' => true,
            'month' => $target->month->format('Y-m'),
            'reservations_target' => $target->reservations_target,
        ]);
    }

    public function commercialPerformanceAudit(CommercialPerformanceAuditRequest $request): JsonResponse
    {
        return response()->json($this->commercialPerformanceAudit->payload($request->validated()));
    }

    public function kpiAudit(Request $request): JsonResponse
    {
        abort_unless(ReportUserAccess::canAuditReport($request, 'reservations-sales'), 403);

        return response()->json($this->dataset->kpiAudit($request));
    }

    public function exportKpiAuditCsv(Request $request): StreamedResponse
    {
        abort_unless(ReportUserAccess::canAuditReport($request, 'reservations-sales'), 403);

        $payload = $this->dataset->kpiAudit($request);
        $rows = $payload['items'] ?? [];
        $metric = $payload['metric'] ?? 'oportunidades_totales';
        $headers = [
            'Metrica',
            'Opportunity ID',
            'Vehicle ID',
            'Vehicle plate',
            'Fecha metrica',
            'Created date',
            'Close date',
            'Reservation date',
            'CV signed date',
            'Record type',
            'Stage',
            'Owner ID',
            'Owner name',
            'Delivery store',
            'Delegacion comercial',
            'Zona comercial',
            'Account ID',
            'Portal original',
            'Portal resuelto',
            'Origen resolucion portal',
            'Interest resolucion portal',
            'Estado relacion Interest',
            'Interest directo',
            'Interests inversos',
            'Interest source usada',
            'Interest eliminado',
            'Direct run ID',
            'Direct cutoff',
            'F2 run ID',
            'F2 cutoff',
            'Fuente oportunidad raw',
            'Fuente oportunidad normalizada',
            'Reserva viva',
            'Oportunidad caida',
            'CV firmado',
            'Estado calidad',
            'Grupo duplicado',
            'Tamano grupo',
            'Contado en KPI',
            'Oportunidades afectadas',
            'Campos en conflicto',
            'Estado desglose',
        ];

        return response()->streamDownload(function () use ($rows, $headers): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $headers);

            foreach ($rows as $row) {
                fputcsv($output, [
                    $row['metric_label'] ?? null,
                    $row['opportunity_id'] ?? null,
                    $row['vehicle_id'] ?? null,
                    $row['vehicle_plate'] ?? null,
                    $row['metric_date'] ?? null,
                    $row['created_date'] ?? null,
                    $row['close_date'] ?? null,
                    $row['reservation_date'] ?? null,
                    $row['cv_signed_date'] ?? null,
                    $row['record_type_name'] ?? null,
                    $row['stage_name'] ?? null,
                    $row['owner_id'] ?? null,
                    $row['owner_name'] ?? null,
                    $row['delivery_store'] ?? null,
                    $row['commercial_delegation'] ?? null,
                    $row['zone'] ?? null,
                    $row['account_id'] ?? null,
                    $row['portal_original'] ?? null,
                    $row['portal_resolved'] ?? null,
                    $row['portal_resolution_source'] ?? null,
                    $row['interest_id'] ?? null,
                    $row['relationship_status'] ?? null,
                    $row['direct_interest_id'] ?? null,
                    CsvValueSerializer::serialize($row['inverse_interest_ids'] ?? []),
                    $row['interest_source'] ?? null,
                    ($row['interest_is_deleted'] ?? null) === null
                        ? null
                        : ($row['interest_is_deleted'] ? '1' : '0'),
                    $row['direct_run_id'] ?? null,
                    $row['direct_cutoff_at'] ?? null,
                    $row['f2_run_id'] ?? null,
                    $row['f2_cutoff_at'] ?? null,
                    $row['opportunity_source_raw'] ?? null,
                    $row['opportunity_source_normalized'] ?? null,
                    ($row['is_reserva_viva'] ?? false) ? '1' : '0',
                    ($row['is_caida'] ?? false) ? '1' : '0',
                    ($row['is_cv_firmado'] ?? false) ? '1' : '0',
                    $row['quality_status'] ?? null,
                    $row['duplicate_group_key'] ?? null,
                    $row['duplicate_group_size'] ?? 1,
                    ($row['counted_in_kpi'] ?? false) ? '1' : '0',
                    CsvValueSerializer::serialize($row['affected_opportunity_ids'] ?? []),
                    CsvValueSerializer::serialize($row['conflicting_fields'] ?? []),
                    $row['breakdown_status'] ?? null,
                ]);
            }

            fclose($output);
        }, "reservas-ventas-auditoria-{$metric}.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
