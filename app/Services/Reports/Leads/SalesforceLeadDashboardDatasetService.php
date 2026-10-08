<?php

namespace App\Services\Reports\Leads;

use App\Models\MasterPortal;
use App\Models\ReportSyncRun;
use App\Models\SalesforceLead;
use App\Models\SalesforceLeadActivitySummary;
use App\Models\SalesforceUser;
use App\Support\ReportServerTiming;
use App\Support\ReportUserAccess;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SalesforceLeadDashboardDatasetService
{
    private const DATASET_TIMEZONE = 'Europe/Madrid';

    private const COMMERCIAL_PROFILES = [
        'Compra/Venta',
        'Comerciales Partner Community',
    ];

    private const CACHE_TTL_MINUTES = 10;

    // Dirección quiere que los no clasificados cuenten en KPIs generales por ahora.
    private const INCLUDE_UNCLASSIFIED_IN_TOTALS = true;

    private const TECHNICAL_OWNER_IDS = [
        '0052X00000AP4U5QAL',
        '0057R00000AKkz0QAD',
        '0057R00000CQGZaQAP',
    ];

    private const TECHNICAL_OWNER_NAMES = [
        'admin adesso',
        'api user',
        'carlos torres',
    ];

    private ?Collection $commercialUsersCache = null;

    private ?Collection $portalMapCache = null;

    private array $portalGroupResolutionCache = [];

    private readonly LeadClassificationResolver $classificationResolver;

    public function __construct(
        private readonly LeadDelegationNormalizer $delegationNormalizer,
        private readonly LeadDashboardAiInsightsService $aiInsights,
        private readonly LeadRecordTypeNormalizer $recordTypeNormalizer,
        private readonly LeadPortalResolver $portalResolver,
        ?LeadClassificationResolver $classificationResolver = null,
    ) {
        $this->classificationResolver = $classificationResolver
            ?? new LeadClassificationResolver($this->portalResolver);
    }

    public function payload(Request $request, string $context = 'summary', ?ReportServerTiming $timing = null): array
    {
        @set_time_limit(120);

        $filters = $this->filters($request, $context);
        $periods = $this->periods($filters);

        $resolve = function () use ($filters, $periods, $timing): array {
            $key = $this->cacheKey($filters, $periods);
            $payload = Cache::remember(
                $key,
                now()->addMinutes(self::CACHE_TTL_MINUTES),
                function () use ($filters, $periods, $timing): array {
                    $timing?->mark('leads-cache-miss');

                    return $this->buildPayload($filters, $periods, $timing);
                }
            );

            if ($timing !== null && ! $timing->has('leads-cache-miss')) {
                $timing->mark('leads-cache-hit');
            }

            return $payload;
        };

        return $timing?->measure('leads-total', $resolve) ?? $resolve();
    }

    public function summary(Request $request, ?ReportServerTiming $timing = null): array
    {
        return $this->payload($request, 'summary', $timing)['summary'];
    }

    public function executiveLeadTotal(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $filters = $this->filters(Request::create('/internal/executive/leads', 'GET'), 'summary');
        $period = ['start' => $start, 'end' => $end];
        $bucket = $this->emptyBucket();

        $this->eachPeriodLead($period, function (array $lead) use (&$bucket, $filters): void {
            if (! $this->passesFilters($lead, $filters)) {
                return;
            }

            $this->addToBucket($bucket, $lead);
        });

        $metadata = $this->syncMetadata($period);

        return [
            'value' => $this->finalizeBucket($bucket)['leads_totales'],
            'coverage' => $metadata['metadata_coverage'],
            'source_cutoff' => [
                'dataset_cutoff_at' => $metadata['dataset_cutoff_at'],
                'salesforce_leads_synced_at' => $metadata['salesforce_leads_synced_at'],
                'activities_synced_at' => $metadata['activities_synced_at'],
                'sync_run_id' => $metadata['sync_run_id'],
                'sync_run_status' => $metadata['sync_run_status'],
            ],
        ];
    }

    public function kpiAudit(Request $request): array
    {
        $filters = $this->filters($request, 'summary');
        $periods = $this->periods($filters);
        $metric = $this->resolveAuditMetric($request->string('metric')->toString());

        return Cache::remember(
            'lead-dashboard-audit-v1:'.md5(json_encode([
                'filters' => $filters,
                'period' => $this->periodPayload($periods['current']),
                'metric' => $metric,
                'version' => $this->dataVersion(),
            ])),
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn () => $this->buildAuditPayload($filters, $periods['current'], $metric)
        );
    }

    /** @param list<string> $salesforceIds */
    public function leadAudit(array $salesforceIds, ?Request $request = null): array
    {
        $ids = collect($salesforceIds)
            ->map(fn (mixed $id) => trim((string) $id))
            ->filter()
            ->unique()
            ->take(200)
            ->values();
        $rows = SalesforceLead::query()
            ->whereIn('salesforce_id', $ids)
            ->get()
            ->keyBy('salesforce_id');

        $accessFilters = $request ? $this->filters($request, 'summary') : null;

        return [
            'ok' => true,
            'items' => $ids->map(function (string $id) use ($rows, $accessFilters): ?array {
                /** @var SalesforceLead|null $row */
                $row = $rows->get($id);

                if ($row === null) {
                    return [
                        'salesforce_id' => $id,
                        'exists_local' => false,
                        'salesforce_state' => 'not_synchronized',
                    ];
                }

                $lead = $this->decorateLead($row);
                if ($accessFilters !== null && ! $this->passesAccessScope($lead, $accessFilters)) {
                    return null;
                }

                return [
                    'salesforce_id' => $id,
                    'exists_local' => true,
                    'salesforce_state' => $lead['is_deleted'] ? 'deleted_or_merged' : 'active_at_last_sync',
                    'status' => $lead['status'],
                    'record_type_raw' => $lead['lead_type_raw'],
                    'record_type_normalized' => $lead['lead_type_normalized'],
                    'channel_resolved' => $lead['canal'],
                    'portal_resolved' => $lead['portal'],
                    'portal_resolution_source' => $lead['portal_resolution_source'],
                    'source_origin_new_raw' => $lead['source_origin_new'],
                    'source_effective' => $lead['portal'],
                    'source_resolution' => $lead['source_resolution'],
                    'channel_new_raw' => $lead['channel_new'],
                    'channel_resolution' => $lead['channel_resolution'],
                    'medium_origin_new_raw' => $lead['medium_origin_new'],
                    'medio_origen_legacy_raw' => $lead['medio_origen'],
                    'medium_effective' => $lead['medio_efectivo'],
                    'medium_resolution' => $lead['medium_resolution'],
                    'delegation_origin_new_raw' => $lead['delegation_origin_new'],
                    'delegation_resolution' => $lead['delegation_resolution'],
                    'delegation_effective_normalized' => $lead['lead_delegation'],
                    'delegation_effective_source' => $lead['lead_delegation_effective_source'],
                    'portal_text_raw' => $lead['portal_text'],
                    'fuente_nuevo_raw' => $lead['fuente_nuevo'],
                    'lea_sel_fuente_origen_raw' => $lead['fuente_origen'],
                    'medio_nuevo_raw' => $lead['medio_nuevo'],
                    'created_date' => $lead['created_date'],
                    'salesforce_last_modified_at' => $lead['salesforce_last_modified_at'],
                    'synced_at' => $lead['synced_at'],
                    'salesforce_deleted_at' => $lead['salesforce_deleted_at'],
                    'deletion_detection_source' => $lead['deletion_detection_source'],
                    'salesforce_master_record_id' => $row->salesforce_master_record_id,
                    'sync_metadata_source' => $row->sync_metadata_source,
                    'is_converted' => (bool) $row->is_converted,
                    'converted_date' => $row->converted_date,
                    'converted_opportunity_id' => $row->converted_opportunity_id,
                    'local_updated_at' => $row->updated_at,
                ];
            })->filter()->values()->all(),
        ];
    }

    public function reconciliationAudit(Request $request): array
    {
        $filters = $this->filters($request, 'summary');
        $period = $this->periods($filters)['current'];

        return SalesforceLead::query()
            ->where('created_date', '>=', $period['start'])
            ->where('created_date', '<=', $period['end'])
            ->orderBy('created_date')
            ->orderBy('salesforce_id')
            ->get()
            ->map(function (SalesforceLead $row) use ($filters): ?array {
                $lead = $this->decorateLead($row);
                if (! $this->passesAccessScope($lead, $filters)) {
                    return null;
                }
                $exclusionReasons = $this->auditFilterExclusionReasons($lead, $filters);
                if ($row->is_deleted) {
                    array_unshift($exclusionReasons, filled($row->salesforce_master_record_id) ? 'merged' : 'deleted');
                }
                $included = $exclusionReasons === [];

                return [
                    'lead_id' => $row->salesforce_id,
                    'created_date' => $this->auditDate($row->created_date),
                    'last_modified_date' => $this->auditDate($row->salesforce_last_modified_at),
                    'synced_at' => $this->auditDate($row->synced_at),
                    'local_updated_at' => $this->auditDate($row->updated_at),
                    'sync_metadata_source' => $row->sync_metadata_source,
                    'salesforce_state' => $row->is_deleted
                        ? (filled($row->salesforce_master_record_id) ? 'merged' : 'deleted')
                        : 'active',
                    'is_deleted' => (bool) $row->is_deleted,
                    'deleted_at' => $this->auditDate($row->salesforce_deleted_at),
                    'deletion_detection_source' => $row->deletion_detection_source,
                    'master_record_id' => $row->salesforce_master_record_id,
                    'status' => $row->status,
                    'owner_id' => $lead['owner_id'],
                    'owner_name' => $lead['owner_name'],
                    'effective_commercial_id' => $lead['gestor_id'],
                    'effective_commercial_name' => $lead['gestor_nombre'],
                    'commercial_delegation' => $lead['commercial_delegation'],
                    'commercial_zone' => $lead['commercial_zone'],
                    'commercial_is_eligible' => ! $lead['is_without_eligible_commercial'],
                    'without_eligible_commercial' => $lead['is_without_eligible_commercial'],
                    'without_commercial_delegation' => $lead['is_without_commercial_delegation'],
                    'unclassified' => $lead['is_unclassified'],
                    'is_converted' => (bool) $row->is_converted,
                    'converted_date' => $this->auditDate($row->converted_date),
                    'converted_opportunity_id' => $row->converted_opportunity_id,
                    'record_type_raw' => $lead['lead_type_raw'],
                    'record_type_normalized' => $lead['lead_type_normalized'],
                    'portal_resolved' => $lead['portal'],
                    'portal_resolution_source' => $lead['portal_resolution_source'],
                    'source_origin_new_raw' => $lead['source_origin_new'],
                    'source_effective' => $lead['portal'],
                    'source_resolution' => $lead['source_resolution'],
                    'channel_new_raw' => $lead['channel_new'],
                    'channel_resolution' => $lead['channel_resolution'],
                    'medium_origin_new_raw' => $lead['medium_origin_new'],
                    'medio_origen_legacy_raw' => $lead['medio_origen'],
                    'medium_effective' => $lead['medio_efectivo'],
                    'medium_resolution' => $lead['medium_resolution'],
                    'delegation_origin_new_raw' => $lead['delegation_origin_new'],
                    'delegation_resolution' => $lead['delegation_resolution'],
                    'delegation_effective_normalized' => $lead['lead_delegation'],
                    'delegation_effective_source' => $lead['lead_delegation_effective_source'],
                    'portal_text_raw' => $row->portal_text,
                    'fuente_nuevo_raw' => $row->fuente_nuevo,
                    'fuente_origen_raw' => $row->fuente_origen,
                    'medio_nuevo_raw' => $row->medio_nuevo,
                    'channel_resolved' => $lead['canal'],
                    'delegation_raw' => $lead['lead_delegation_raw'],
                    'delegation_normalized' => $lead['lead_delegation'],
                    'included_in_active_dataset' => $included,
                    'inclusion_exclusion_reason' => $included ? 'included' : implode('|', $exclusionReasons),
                ];
            })->filter()
            ->values()
            ->all();
    }

    public function commercialRows(Request $request): array
    {
        $payload = $this->payload($request, 'commercials');

        return [
            'ok' => true,
            'zones' => $payload['commercial_zones'],
            'delegations' => $payload['commercial_delegations'],
            'commercials' => $payload['commercials'],
            'items' => $payload['commercials'],
        ];
    }

    public function delegationRows(Request $request): array
    {
        return ['items' => $this->payload($request, 'delegations')['delegations']];
    }

    public function portalRows(Request $request): array
    {
        return ['items' => $this->payload($request, 'portals')['portals']];
    }

    public function filters(Request $request, string $context = 'summary'): array
    {
        $accessDelegation = ReportUserAccess::delegationName($request);
        $accessCommercial = ReportUserAccess::salesforceUserId($request);
        $accessZone = ReportUserAccess::isAreaManager($request) ? ReportUserAccess::areaZoneLabel($request) : null;

        return [
            'context' => $context,
            'period' => $request->string('period')->toString() ?: 'last_30_days',
            'current_start' => $request->string('current_start')->toString(),
            'current_end' => $request->string('current_end')->toString(),
            'comparison_start' => $request->string('comparison_start')->toString(),
            'comparison_end' => $request->string('comparison_end')->toString(),
            'portal' => $request->string('portal')->toString(),
            'lead_delegation' => $request->string('lead_delegation')->toString()
                ?: $request->string('delegation')->toString(),
            'lead_type' => $request->string('lead_type')->toString(),
            'commercial_delegation' => $request->string('commercial_delegation')->toString(),
            'zone' => $request->string('zone')->toString(),
            'commercial' => $request->string('commercial')->toString(),
            'exposition_mode' => $request->string('exposition_mode')->toString() ?: 'with',
            'access_delegation' => $accessDelegation,
            'access_commercial' => $accessCommercial,
            'access_zone' => $accessZone,
        ];
    }

    public function periods(array $filters): array
    {
        $now = CarbonImmutable::now(self::DATASET_TIMEZONE);
        $period = $filters['period'] ?: 'last_30_days';

        if ($period === 'custom') {
            $currentStart = $this->parseDate($filters['current_start'], $now->subDays(30)->startOfDay())->startOfDay();
            $currentEnd = $this->parseDate($filters['current_end'], $now)->endOfDay();
            $comparisonStart = $this->parseDate($filters['comparison_start'], $currentStart->subDays((int) floor($currentStart->diffInDays($currentEnd)) + 1))->startOfDay();
            $comparisonEnd = $this->parseDate($filters['comparison_end'], $currentStart->subDay())->endOfDay();

            return [
                'current' => ['start' => $currentStart, 'end' => $currentEnd],
                'previous' => ['start' => $comparisonStart, 'end' => $comparisonEnd],
            ];
        }

        if ($period === 'current_month') {
            $currentStart = $now->startOfMonth();
            $currentEnd = $now;
            $previousStart = $currentStart->subMonthNoOverflow();
            $previousEndCandidate = $previousStart->addDays((int) floor($currentStart->diffInDays($currentEnd)))->endOfDay();
            $previousEnd = $previousEndCandidate->lessThanOrEqualTo($previousStart->endOfMonth())
                ? $previousEndCandidate
                : $previousStart->endOfMonth();

            return [
                'current' => ['start' => $currentStart, 'end' => $currentEnd],
                'previous' => ['start' => $previousStart, 'end' => $previousEnd],
            ];
        }

        if ($period === 'previous_month') {
            $currentStart = $now->subMonthNoOverflow()->startOfMonth();
            $currentEnd = $now->subMonthNoOverflow()->endOfMonth();
            $previousStart = $currentStart->subMonthNoOverflow()->startOfMonth();
            $previousEnd = $currentStart->subMonthNoOverflow()->endOfMonth();

            return [
                'current' => ['start' => $currentStart, 'end' => $currentEnd],
                'previous' => ['start' => $previousStart, 'end' => $previousEnd],
            ];
        }

        return [
            'current' => ['start' => $now->subDays(30), 'end' => $now],
            'previous' => ['start' => $now->subDays(60), 'end' => $now->subDays(30)],
        ];
    }

    private function buildAuditPayload(array $filters, array $period, string $metric): array
    {
        $items = [];

        $this->eachPeriodLead($period, function (array $lead) use (&$items, $filters, $metric): void {
            if (! $this->passesFilters($lead, $filters)) {
                return;
            }

            if (! $this->qualifiesAuditMetric($lead, $metric)) {
                return;
            }

            $items[] = [
                'metric' => $metric,
                'metric_label' => $this->auditMetricLabel($metric),
                'lead_id' => $lead['salesforce_id'] ?? null,
                'lead_name' => $lead['lead_name'] ?? null,
                'created_date' => $this->auditDate($lead['created_date'] ?? null),
                'status' => $lead['status'] ?? null,
                'lead_type' => $lead['lead_type'] ?? null,
                'lead_type_raw' => $lead['lead_type_raw'] ?? null,
                'lead_type_normalized' => $lead['lead_type_normalized'] ?? null,
                'portal' => $lead['portal'] ?? null,
                'portal_resolution_source' => $lead['portal_resolution_source'] ?? null,
                'portal_text' => $lead['portal_text'] ?? null,
                'portal_group' => $lead['grupo_portal'] ?? null,
                'channel' => $lead['canal'] ?? null,
                'lead_delegation_raw' => $lead['lead_delegation_raw'] ?? null,
                'lead_delegation' => $lead['lead_delegation'] ?? null,
                'lead_zone' => $lead['lead_zone'] ?? null,
                'commercial_delegation' => $lead['commercial_delegation'] ?? null,
                'commercial_zone' => $lead['commercial_zone'] ?? null,
                'commercial_is_eligible' => ! ($lead['is_without_eligible_commercial'] ?? true),
                'without_eligible_commercial' => (bool) ($lead['is_without_eligible_commercial'] ?? false),
                'without_commercial_delegation' => (bool) ($lead['is_without_commercial_delegation'] ?? false),
                'unclassified' => (bool) ($lead['is_unclassified'] ?? false),
                'gestor_id' => $lead['gestor_id'] ?? null,
                'gestor_nombre' => $lead['gestor_nombre'] ?? null,
                'owner_id' => $lead['owner_id'] ?? null,
                'owner_name' => $lead['owner_name'] ?? null,
                'persona_que_trabajo_id' => $lead['persona_que_trabajo_id'] ?? null,
                'persona_que_trabajo_name' => $lead['persona_que_trabajo_name'] ?? null,
                'propietario_descarte_id' => $lead['propietario_descarte_id'] ?? null,
                'propietario_descarte_name' => $lead['propietario_descarte_name'] ?? null,
                'phone' => $lead['phone'] ?? null,
                'mobile_phone' => $lead['mobile_phone'] ?? null,
                'email' => $lead['email'] ?? null,
                'campaign_acquired' => $lead['campaign_acquired'] ?? null,
                'acquired_id' => $lead['acquired_id'] ?? null,
                'content_acquired' => $lead['content_acquired'] ?? null,
                'fuente_origen' => $lead['fuente_origen'] ?? null,
                'medio_origen' => $lead['medio_origen'] ?? null,
                'fuente_nuevo' => $lead['fuente_nuevo'] ?? null,
                'medio_nuevo' => $lead['medio_nuevo'] ?? null,
                'vehicle_interest' => $lead['vehicle_interest'] ?? null,
                'converted_account_id' => $lead['converted_account_id'] ?? null,
                'converted_opportunity_id' => $lead['converted_opportunity_id'] ?? null,
                'salesforce_last_modified_at' => $lead['salesforce_last_modified_at'] ?? null,
                'synced_at' => $lead['synced_at'] ?? null,
                'is_deleted' => (bool) ($lead['is_deleted'] ?? false),
                'salesforce_deleted_at' => $lead['salesforce_deleted_at'] ?? null,
                'deletion_detection_source' => $lead['deletion_detection_source'] ?? null,
                'is_convertido' => (bool) ($lead['is_convertido'] ?? false),
                'is_descartado' => (bool) ($lead['is_descartado'] ?? false),
                'is_potencial' => (bool) ($lead['is_potencial'] ?? false),
                'is_potencial_sin_trabajar' => (bool) ($lead['is_potencial_sin_trabajar'] ?? false),
                'is_lead_sin_asignar' => (bool) ($lead['is_lead_sin_asignar'] ?? false),
                'is_gestionado' => (bool) ($lead['is_gestionado'] ?? false),
                'is_llamada' => (bool) ($lead['is_llamada'] ?? false),
                'is_formulario' => (bool) ($lead['is_formulario'] ?? false),
            ];
        });

        usort($items, fn (array $a, array $b) => [$a['created_date'] ?? '', $a['lead_id'] ?? ''] <=> [$b['created_date'] ?? '', $b['lead_id'] ?? '']);

        return [
            'ok' => true,
            'metric' => $metric,
            'metric_label' => $this->auditMetricLabel($metric),
            'periodo_actual' => $this->periodPayload($period),
            'total' => count($items),
            'items' => $items,
        ];
    }

    public function decorateLead(mixed $lead, mixed $summary = null, ?CarbonInterface $referenceDate = null): array
    {
        $referenceDate = $referenceDate ? CarbonImmutable::parse($referenceDate) : CarbonImmutable::now();
        $status = (string) data_get($lead, 'status', '');
        $isConverted = $status === 'Convertido';
        $isDiscarded = $status === 'Descartado';
        $isPotential = $status === 'Potencial';
        $classification = $this->classificationResolver->resolve($lead);
        $channel = $classification['channel'];
        $portal = $classification['portal'];
        $recordTypeRaw = $this->clean(data_get($lead, 'record_type_name'));
        $recordTypeNormalized = $this->clean(data_get($lead, 'record_type_normalized'))
            ?? $this->recordTypeNormalizer->normalize($recordTypeRaw);
        $manager = $this->resolveSimplifiedManager($lead, $isConverted, $isDiscarded);
        $leadDelegation = $this->resolveLeadDelegation($lead, $recordTypeNormalized, $manager, $classification['fields']['delegation']);
        $accessLeadDelegation = $this->resolveLeadDelegation(
            $lead,
            $recordTypeNormalized,
            $manager,
            $this->classificationResolver->resolveLegacyDelegation($lead),
        );
        $commercialUser = $manager['id'] ? $this->commercialUsers()->get($manager['id']) : null;
        $commercialDelegation = $this->normalizeCommercialDelegation(data_get($commercialUser, 'user_delegation'));
        $withoutEligibleCommercial = $commercialUser === null;
        $withoutCommercialDelegation = ! $withoutEligibleCommercial && ! $commercialDelegation['is_classified'];
        $unclassified = ! $leadDelegation['is_classified'];

        $totalActivities = (int) (data_get($summary, 'total_actividades') ?? 0);
        $lastActivity = data_get($summary, 'fecha_ultima_actividad');
        $lastActivityAt = $lastActivity ? CarbonImmutable::parse($lastActivity) : null;
        $hasRecentActivity = $lastActivityAt !== null
            && $lastActivityAt->lessThanOrEqualTo($referenceDate)
            && $lastActivityAt->greaterThanOrEqualTo($referenceDate->subDays(3));
        $isUnassigned = $isPotential && $this->isTechnicalOwner(
            data_get($lead, 'owner_id'),
            data_get($lead, 'owner_name'),
        );
        $potentialWithoutWork = $isPotential && ! $isUnassigned && ($totalActivities === 0 || ! $hasRecentActivity);
        $managed = $isConverted || $isDiscarded || ($isPotential && $hasRecentActivity);

        return [
            'id' => data_get($lead, 'id'),
            'salesforce_id' => data_get($lead, 'salesforce_id'),
            'lead_name' => data_get($lead, 'name'),
            'created_date' => data_get($lead, 'created_date'),
            'status' => $status,
            'lead_type' => $recordTypeRaw,
            'lead_type_raw' => $recordTypeRaw,
            'lead_type_normalized' => $recordTypeNormalized,
            'owner_id' => data_get($lead, 'owner_id'),
            'owner_name' => data_get($lead, 'owner_name'),
            'persona_que_trabajo_id' => data_get($lead, 'persona_que_trabajo_id'),
            'persona_que_trabajo_name' => data_get($lead, 'persona_que_trabajo_name'),
            'propietario_descarte_id' => data_get($lead, 'propietario_descarte_id'),
            'propietario_descarte_name' => data_get($lead, 'propietario_descarte_name'),
            'phone' => data_get($lead, 'phone'),
            'mobile_phone' => data_get($lead, 'mobile_phone'),
            'email' => data_get($lead, 'email'),
            'campaign_acquired' => data_get($lead, 'campaign_acquired'),
            'acquired_id' => data_get($lead, 'acquired_id'),
            'content_acquired' => data_get($lead, 'content_acquired'),
            'fuente_origen' => data_get($lead, 'fuente_origen'),
            'medio_origen' => data_get($lead, 'medio_origen'),
            'source_origin_new' => data_get($lead, 'source_origin_new'),
            'medium_origin_new' => data_get($lead, 'medium_origin_new'),
            'channel_new' => data_get($lead, 'channel_new'),
            'delegation_origin_new' => data_get($lead, 'delegation_origin_new'),
            'source_resolution' => $classification['fields']['source'],
            'channel_resolution' => $classification['fields']['channel'],
            'medium_resolution' => $classification['fields']['medium'],
            'delegation_resolution' => $classification['fields']['delegation'],
            'medio_efectivo' => $classification['fields']['medium']['effective_value'],
            'fuente_nuevo' => data_get($lead, 'fuente_nuevo'),
            'medio_nuevo' => data_get($lead, 'medio_nuevo'),
            'vehicle_interest' => data_get($lead, 'vehicle_interest'),
            'converted_account_id' => data_get($lead, 'converted_account_id'),
            'converted_opportunity_id' => data_get($lead, 'converted_opportunity_id'),
            'is_convertido' => $isConverted,
            'is_descartado' => $isDiscarded,
            'is_potencial' => $isPotential,
            'is_potencial_sin_trabajar' => $potentialWithoutWork,
            'is_lead_sin_asignar' => $isUnassigned,
            'is_gestionado' => $managed,
            'is_llamada' => $channel === 'Llamada',
            'is_formulario' => $channel === 'Formulario',
            'canal' => $channel,
            'portal' => $portal,
            'portal_resolution_source' => $classification['portal_resolution_source'],
            'portal_text' => data_get($lead, 'portal_text'),
            'synced_at' => data_get($lead, 'synced_at'),
            'salesforce_last_modified_at' => data_get($lead, 'salesforce_last_modified_at'),
            'is_deleted' => (bool) data_get($lead, 'is_deleted', false),
            'salesforce_deleted_at' => data_get($lead, 'salesforce_deleted_at'),
            'deletion_detection_source' => data_get($lead, 'deletion_detection_source'),
            'grupo_portal' => $this->portalGroup($portal),
            'lead_delegation' => $leadDelegation['delegation'],
            'lead_group' => $leadDelegation['group'],
            'lead_zone' => $leadDelegation['zone'],
            'lead_delegation_raw' => $leadDelegation['raw'],
            'lead_delegation_is_classified' => $leadDelegation['is_classified'],
            'lead_delegation_effective_source' => $leadDelegation['effective_source'],
            'lead_access_delegation' => $accessLeadDelegation['delegation'],
            'commercial_delegation' => $commercialDelegation['delegation'],
            'commercial_group' => $commercialDelegation['group'],
            'commercial_zone' => $commercialDelegation['zone'],
            'commercial_delegation_raw' => $commercialDelegation['raw'],
            'commercial_delegation_is_classified' => $commercialDelegation['is_classified'],
            'zona' => $commercialDelegation['zone'],
            'gestor_id' => $manager['id'],
            'gestor_nombre' => data_get($commercialUser, 'name') ?? $manager['name'],
            'gestor_es_comercial' => $commercialUser !== null,
            'is_without_eligible_commercial' => $withoutEligibleCommercial,
            'is_without_commercial_delegation' => $withoutCommercialDelegation,
            'is_unclassified' => $unclassified,
            'is_exposicion' => Str::lower($portal) === Str::lower('Exposición'),
            'total_actividades' => $totalActivities,
            'fecha_ultima_actividad' => $lastActivityAt,
        ];
    }

    private function buildPayload(array $filters, array $periods, ?ReportServerTiming $timing = null): array
    {
        $current = $this->emptyBucket();
        $previous = $this->emptyBucket();
        $commercialZoneGroups = [];
        $commercialDelegationGroups = [];
        $commercialGroups = [];
        $delegationGroups = [];
        $portalGroups = [];
        $filterOptions = $this->emptyFilterOptionsAccumulator();

        $currentPeriod = function () use (&$current, &$commercialZoneGroups, &$commercialDelegationGroups, &$commercialGroups, &$delegationGroups, &$portalGroups, &$filterOptions, $filters, $periods): void {
            $this->eachPeriodLead($periods['current'], function (array $lead) use (
                &$current,
                &$commercialZoneGroups,
                &$commercialDelegationGroups,
                &$commercialGroups,
                &$delegationGroups,
                &$portalGroups,
                &$filterOptions,
                $filters,
            ): void {
                if (! $this->passesAccessScope($lead, $filters)) {
                    return;
                }

                $this->collectFilterOptions($filterOptions, $lead);

                if (! $this->passesFilters($lead, $filters)) {
                    return;
                }

                $this->addToBucket($current, $lead);

                if ($lead['gestor_es_comercial']) {
                    $this->addGroup($commercialZoneGroups, $lead['commercial_zone'], $lead['commercial_zone'], [], $lead);
                    $this->addGroup($commercialDelegationGroups, $lead['commercial_delegation'].'|'.$lead['commercial_zone'], $lead['commercial_delegation'], [
                        'zone' => $lead['commercial_zone'],
                    ], $lead);
                    $this->addGroup($commercialGroups, $lead['gestor_id'], $lead['gestor_nombre'], [
                        'commercial_delegation' => $lead['commercial_delegation'],
                        'zone' => $lead['commercial_zone'],
                    ], $lead);
                }

                $this->addGroup($delegationGroups, $lead['lead_delegation'], $lead['lead_delegation'], [], $lead);
                $this->addGroup($portalGroups, $lead['portal'], $lead['portal'], [], $lead);
            });
        };
        if ($timing !== null) {
            $timing->measure('leads-current-total', $currentPeriod);
        } else {
            $currentPeriod();
        }

        $previousPeriod = function () use (&$previous, $filters, $periods): void {
            $this->eachPeriodLead($periods['previous'], function (array $lead) use (&$previous, $filters): void {
                if (! $this->passesFilters($lead, $filters)) {
                    return;
                }

                $this->addToBucket($previous, $lead);
            });
        };
        if ($timing !== null) {
            $timing->measure('leads-previous-total', $previousPeriod);
        } else {
            $previousPeriod();
        }

        $groups = function () use (&$commercialZones, &$commercialDelegations, &$commercials, &$delegations, &$portals, $commercialZoneGroups, $commercialDelegationGroups, $commercialGroups, $delegationGroups, $portalGroups): void {
            $commercialZones = $this->finalizeGroups($commercialZoneGroups, 'zone');
            $commercialDelegations = $this->finalizeGroups($commercialDelegationGroups, 'commercial_delegation');
            $commercials = $this->finalizeGroups($commercialGroups, 'comercial');
            $delegations = $this->finalizeGroups($delegationGroups, 'lead_delegation');
            $portals = $this->finalizeGroups($portalGroups, 'portal');
        };
        if ($timing !== null) {
            $timing->measure('leads-groups', $groups);
        } else {
            $groups();
        }

        $finalize = function () use (&$current, &$previous, &$comparison, &$syncMetadata, $periods): void {
            $current = $this->finalizeBucket($current);
            $previous = $this->finalizeBucket($previous);
            $comparison = $this->compactComparison($current, $previous);
            $syncMetadata = $this->syncMetadata($periods['current']);
        };
        if ($timing !== null) {
            $timing->measure('leads-finalize', $finalize);
        } else {
            $finalize();
        }
        $hasCurrentData = $current['leads_totales'] > 0;
        $hasAnyPeriodData = $hasCurrentData || $previous['leads_totales'] > 0;
        $aiPayload = $hasAnyPeriodData
            ? $this->aiPayload($filters, $periods, $current, $previous, $comparison, $portals, $commercials, $delegations)
            : null;
        $insights = fn (): array => $aiPayload !== null
            ? $this->aiInsights->generate($aiPayload, $timing)
            : ['insights' => [], 'source' => 'none'];
        $executiveInsights = $timing?->measure('leads-insights', $insights) ?? $insights();
        $emptyMessage = $syncMetadata['salesforce_leads_synced_at']
            ? 'No hay leads que coincidan con el periodo y los filtros seleccionados.'
            : 'No hay datos de leads sincronizados.';

        $filterOptionsPayload = fn (): array => $this->filterOptionsFromAccumulator($filterOptions);
        $filterOptionsPayload = $timing?->measure('leads-filters', $filterOptionsPayload) ?? $filterOptionsPayload();

        return [
            'summary' => [
                'ok' => true,
                'empty' => ! $hasCurrentData,
                'message' => $hasCurrentData ? null : $emptyMessage,
                'periodo_actual' => $this->periodPayload($periods['current']),
                'periodo_comparado' => $this->periodPayload($periods['previous']),
                'datos_actualizados' => $syncMetadata['dataset_cutoff_at'],
                'salesforce_leads_synced_at' => $syncMetadata['salesforce_leads_synced_at'],
                'activities_synced_at' => $syncMetadata['activities_synced_at'],
                'dataset_generated_at' => $syncMetadata['dataset_generated_at'],
                'dataset_cutoff_at' => $syncMetadata['dataset_cutoff_at'],
                'dataset_timezone' => $syncMetadata['timezone'],
                'dataset_period_start' => $syncMetadata['period_start'],
                'dataset_period_end' => $syncMetadata['period_end'],
                'dataset_sync_run_id' => $syncMetadata['sync_run_id'],
                'dataset_sync_run_status' => $syncMetadata['sync_run_status'],
                'metadata_coverage' => $syncMetadata['metadata_coverage'],
                'kpis' => $current,
                'comparativa' => $comparison,
                'insights' => $executiveInsights['insights'],
                'executive_insights' => $executiveInsights['insights'],
                'executive_insights_source' => $executiveInsights['source'],
                'filters' => $filterOptionsPayload,
            ],
            'commercial_zones' => $commercialZones,
            'commercial_delegations' => $commercialDelegations,
            'commercials' => $commercials,
            'delegations' => $delegations,
            'portals' => $portals,
        ];
    }

    protected function eachPeriodLead(array $period, callable $callback): void
    {
        $referenceDate = CarbonImmutable::parse($period['end']);

        $this->baseQuery($period)->chunkById(1000, function (Collection $rows) use ($callback, $referenceDate): void {
            foreach ($rows as $row) {
                $callback($this->decorateLead($row, $row, $referenceDate));
            }
        }, 'salesforce_leads.id', 'id');
    }

    private function baseQuery(array $period): Builder
    {
        return $this->baseLeadQuery()
            ->where('salesforce_leads.created_date', '>=', $period['start'])
            ->where('salesforce_leads.created_date', '<=', $period['end']);
    }

    private function baseLeadQuery(): Builder
    {
        $leadColumns = collect((new SalesforceLead)->getFillable())
            ->reject(fn (string $column) => $column === 'raw_payload')
            ->map(fn (string $column) => 'salesforce_leads.'.$column)
            ->prepend('salesforce_leads.id')
            ->push('salesforce_leads.created_at')
            ->push('salesforce_leads.updated_at')
            ->all();

        return SalesforceLead::query()
            ->leftJoin('salesforce_lead_activity_summaries as summaries', 'summaries.lead_salesforce_id', '=', 'salesforce_leads.salesforce_id')
            ->where('salesforce_leads.is_deleted', false)
            ->orderBy('salesforce_leads.id')
            ->select(array_merge($leadColumns, [
                'summaries.total_actividades',
                'summaries.fecha_ultima_actividad',
            ]));
    }

    private function passesFilters(array $lead, array $filters): bool
    {
        if (! $this->passesAccessScope($lead, $filters)) {
            return false;
        }
        if ($filters['portal'] && $lead['portal'] !== $filters['portal']) {
            return false;
        }

        if ($filters['lead_delegation'] && $lead['lead_delegation'] !== $filters['lead_delegation']) {
            return false;
        }

        if (! $this->passesLeadTypeFilter($lead['lead_type_normalized'], $filters['lead_type'])) {
            return false;
        }

        if ($filters['commercial_delegation'] && $lead['commercial_delegation'] !== $filters['commercial_delegation']) {
            return false;
        }

        if ($filters['zone'] && $lead['commercial_zone'] !== $filters['zone']) {
            return false;
        }

        if ($filters['commercial'] && $lead['gestor_id'] !== $filters['commercial']) {
            return false;
        }

        if ($filters['exposition_mode'] === 'without' && $lead['is_exposicion']) {
            return false;
        }

        return true;
    }

    private function passesAccessScope(array $lead, array $filters): bool
    {
        if (filled($filters['access_commercial'] ?? null) && $lead['gestor_id'] !== $filters['access_commercial']) {
            return false;
        }
        if (filled($filters['access_zone'] ?? null) && $lead['commercial_zone'] !== $filters['access_zone']) {
            return false;
        }
        if (filled($filters['access_delegation'] ?? null)
            && $lead['commercial_delegation'] !== $filters['access_delegation']
            && $lead['lead_access_delegation'] !== $filters['access_delegation']) {
            return false;
        }

        return true;
    }

    private function passesLeadTypeFilter(?string $recordTypeNormalized, ?string $filter): bool
    {
        if (blank($filter) || $filter === 'all') {
            return true;
        }

        $normalizedFilter = $this->recordTypeNormalizer->normalize($filter);

        if ($normalizedFilter === LeadRecordTypeNormalizer::VENTA) {
            return in_array($recordTypeNormalized, $this->recordTypeNormalizer->ventaFilterTypes(), true);
        }

        return $normalizedFilter !== null && $recordTypeNormalized === $normalizedFilter;
    }

    /** @return array<int, string> */
    private function auditFilterExclusionReasons(array $lead, array $filters): array
    {
        $reasons = [];

        if ($filters['portal'] && $lead['portal'] !== $filters['portal']) {
            $reasons[] = 'portal_filter';
        }
        if ($filters['lead_delegation'] && $lead['lead_delegation'] !== $filters['lead_delegation']) {
            $reasons[] = 'lead_delegation_filter';
        }
        if (! $this->passesLeadTypeFilter($lead['lead_type_normalized'], $filters['lead_type'])) {
            $reasons[] = 'lead_type_filter';
        }
        if ($filters['commercial_delegation'] && $lead['commercial_delegation'] !== $filters['commercial_delegation']) {
            $reasons[] = 'commercial_delegation_filter';
        }
        if ($filters['zone'] && $lead['commercial_zone'] !== $filters['zone']) {
            $reasons[] = 'zone_filter';
        }
        if ($filters['commercial'] && $lead['gestor_id'] !== $filters['commercial']) {
            $reasons[] = 'commercial_filter';
        }
        if ($filters['exposition_mode'] === 'without' && $lead['is_exposicion']) {
            $reasons[] = 'exposition_filter';
        }

        return $reasons;
    }

    private function resolveAuditMetric(?string $metric): string
    {
        $metric = trim((string) $metric);

        return in_array($metric, [
            'leads_totales',
            'convertidos',
            'descartados',
            'potenciales',
            'potenciales_sin_trabajar',
            'leads_unassigned',
            'gestionados',
            'llamadas',
            'formularios',
            'without_eligible_commercial',
            'without_commercial_delegation',
            'unclassified',
        ], true) ? $metric : 'leads_totales';
    }

    private function auditMetricLabel(string $metric): string
    {
        return match ($metric) {
            'convertidos' => 'Convertidos',
            'descartados' => 'Descartados',
            'potenciales' => 'Potenciales',
            'potenciales_sin_trabajar' => 'Potenciales sin trabajar',
            'leads_unassigned' => 'Leads sin asignar',
            'gestionados' => 'Gestionados',
            'llamadas' => 'Llamadas',
            'formularios' => 'Formularios',
            'without_eligible_commercial' => 'Sin comercial elegible',
            'without_commercial_delegation' => 'Sin delegación comercial',
            'unclassified' => 'Sin clasificar',
            default => 'Leads totales',
        };
    }

    private function qualifiesAuditMetric(array $lead, string $metric): bool
    {
        return match ($metric) {
            'convertidos' => (bool) ($lead['is_convertido'] ?? false),
            'descartados' => (bool) ($lead['is_descartado'] ?? false),
            'potenciales' => (bool) ($lead['is_potencial'] ?? false),
            'potenciales_sin_trabajar' => (bool) ($lead['is_potencial_sin_trabajar'] ?? false),
            'leads_unassigned' => (bool) ($lead['is_lead_sin_asignar'] ?? false),
            'gestionados' => (bool) ($lead['is_gestionado'] ?? false),
            'llamadas' => (bool) ($lead['is_llamada'] ?? false),
            'formularios' => (bool) ($lead['is_formulario'] ?? false),
            'without_eligible_commercial' => (bool) ($lead['is_without_eligible_commercial'] ?? false),
            'without_commercial_delegation' => (bool) ($lead['is_without_commercial_delegation'] ?? false),
            'unclassified' => (bool) ($lead['is_unclassified'] ?? false),
            default => true,
        };
    }

    private function isTechnicalOwner(mixed $ownerId, mixed $ownerName): bool
    {
        return in_array((string) $ownerId, self::TECHNICAL_OWNER_IDS, true)
            || in_array($this->normalizeOwnerName($ownerName), self::TECHNICAL_OWNER_NAMES, true);
    }

    private function normalizeOwnerName(mixed $ownerName): string
    {
        return Str::of((string) $ownerName)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();
    }

    private function zoneFieldForContext(array $filters): string
    {
        if (($filters['context'] ?? 'summary') === 'commercials') {
            return 'commercial_zone';
        }

        if (in_array(($filters['context'] ?? 'summary'), ['summary', 'portals'], true) && filled($filters['commercial_delegation'])) {
            // En resumen y portales la zona representa lead_zone; si se fuerza una delegación comercial,
            // la zona acompaña ese mismo eje para evitar mezclar dos criterios de atribución.
            return 'commercial_zone';
        }

        return 'lead_zone';
    }

    private function addGroup(array &$groups, string $key, string $label, array $extra, array $lead): void
    {
        $groups[$key] ??= [
            'key' => $key,
            'label' => $label,
            'extra' => $extra,
            'bucket' => $this->emptyBucket(),
        ];

        $this->addToBucket($groups[$key]['bucket'], $lead);
    }

    private function finalizeGroups(array $groups, string $labelKey): array
    {
        $rows = [];

        foreach ($groups as $group) {
            $rows[] = array_merge($group['extra'], $this->finalizeBucket($group['bucket']), [
                'group_key' => $group['key'],
                $labelKey => $group['label'],
                'nombre' => $group['label'],
                'comercial' => $group['label'],
                'delegacion' => $group['label'],
                'commercial_id' => $labelKey === 'comercial' ? $group['key'] : null,
            ]);
        }

        usort($rows, fn (array $a, array $b) => ($b['leads_totales'] ?? 0) <=> ($a['leads_totales'] ?? 0));

        return array_values($rows);
    }

    private function resolveLeadDelegation(mixed $lead, ?string $recordTypeNormalized, array $manager, array $resolution): array
    {
        $raw = $resolution['effective_value'];
        $source = $raw !== null ? $resolution['source_field'] : null;

        if ($raw === null && $recordTypeNormalized === LeadRecordTypeNormalizer::EXPOSICION) {
            $contextualCandidates = [
                'persona_que_trabajo_delegation' => $this->clean(data_get($lead, 'persona_que_trabajo_delegation')),
                'owner_delegation' => $this->clean(data_get($lead, 'owner_delegation')),
                'salesforce_users.user_delegation' => $this->clean(data_get($this->commercialUsers()->get($manager['id']), 'user_delegation')),
            ];

            foreach ($contextualCandidates as $contextualSource => $candidate) {
                if ($candidate !== null) {
                    $raw = $candidate;
                    $source = $contextualSource;

                    break;
                }
            }
        }

        return array_merge($this->delegationNormalizer->normalize($raw), [
            'effective_source' => $source ?? 'fallback',
        ]);
    }

    private function normalizeCommercialDelegation(mixed $raw): array
    {
        $normalized = $this->delegationNormalizer->normalize($this->clean($raw));

        if (Str::endsWith($normalized['delegation'], ' General')) {
            return [
                'raw' => $normalized['raw'],
                'delegation' => LeadDelegationNormalizer::UNCLASSIFIED,
                'group' => LeadDelegationNormalizer::NO_GROUP,
                'zone' => LeadDelegationNormalizer::UNCLASSIFIED,
                'is_classified' => false,
                'raw_unmapped' => $normalized['raw'],
            ];
        }

        return $normalized;
    }

    private function resolveSimplifiedManager(mixed $lead, bool $isConverted, bool $isDiscarded): array
    {
        $fields = $isConverted
            ? [['persona_que_trabajo_id', 'persona_que_trabajo_name'], ['owner_id', 'owner_name']]
            : ($isDiscarded
                ? [['propietario_descarte_id', 'propietario_descarte_name'], ['persona_que_trabajo_id', 'persona_que_trabajo_name'], ['owner_id', 'owner_name']]
                : [['owner_id', 'owner_name']]);

        foreach ($fields as [$idField, $nameField]) {
            $id = $this->clean(data_get($lead, $idField));

            if ($id) {
                return ['id' => $id, 'name' => $this->clean(data_get($lead, $nameField)) ?? $id];
            }
        }

        return ['id' => null, 'name' => 'Sin comercial'];
    }

    private function addToBucket(array &$bucket, array $lead): void
    {
        if (! self::INCLUDE_UNCLASSIFIED_IN_TOTALS && $lead['lead_delegation'] === 'Sin clasificar') {
            return;
        }

        $bucket['leads_totales']++;
        $bucket['convertidos'] += $lead['is_convertido'] ? 1 : 0;
        $bucket['descartados'] += $lead['is_descartado'] ? 1 : 0;
        $bucket['potenciales'] += $lead['is_potencial'] ? 1 : 0;
        $bucket['potenciales_sin_trabajar'] += $lead['is_potencial_sin_trabajar'] ? 1 : 0;
        $bucket['leads_unassigned'] += $lead['is_lead_sin_asignar'] ? 1 : 0;
        $bucket['without_eligible_commercial'] += $lead['is_without_eligible_commercial'] ? 1 : 0;
        $bucket['without_commercial_delegation'] += $lead['is_without_commercial_delegation'] ? 1 : 0;
        $bucket['unclassified'] += $lead['is_unclassified'] ? 1 : 0;
        $bucket['gestionados'] += $lead['is_gestionado'] ? 1 : 0;
        $bucket['llamadas'] += $lead['is_llamada'] ? 1 : 0;
        $bucket['formularios'] += $lead['is_formulario'] ? 1 : 0;
    }

    private function finalizeBucket(array $bucket): array
    {
        $total = $bucket['leads_totales'];

        return array_merge($bucket, [
            'conversion_pct' => $this->percentage($bucket['convertidos'], $total),
            'descarte_pct' => $this->percentage($bucket['descartados'], $total),
            'potenciales_pct' => $this->percentage($bucket['potenciales'], $total),
            'potenciales_sin_trabajar_pct' => $this->percentage($bucket['potenciales_sin_trabajar'], $total),
            'leads_unassigned_pct' => $this->percentage($bucket['leads_unassigned'], $total),
            'without_eligible_commercial_pct' => $this->percentage($bucket['without_eligible_commercial'], $total),
            'without_commercial_delegation_pct' => $this->percentage($bucket['without_commercial_delegation'], $total),
            'unclassified_pct' => $this->percentage($bucket['unclassified'], $total),
            'gestionados_pct' => $this->percentage($bucket['gestionados'], $total),
            'llamadas_pct' => $this->percentage($bucket['llamadas'], $total),
            'formularios_pct' => $this->percentage($bucket['formularios'], $total),
        ]);
    }

    private function emptyBucket(): array
    {
        return [
            'leads_totales' => 0,
            'convertidos' => 0,
            'descartados' => 0,
            'potenciales' => 0,
            'potenciales_sin_trabajar' => 0,
            'leads_unassigned' => 0,
            'without_eligible_commercial' => 0,
            'without_commercial_delegation' => 0,
            'unclassified' => 0,
            'gestionados' => 0,
            'llamadas' => 0,
            'formularios' => 0,
        ];
    }

    private function compactComparison(array $current, array $previous): array
    {
        $metrics = [
            ['key' => 'leads_totales', 'label' => 'Leads totales'],
            ['key' => 'convertidos', 'label' => 'Convertidos', 'percent_key' => 'conversion_pct'],
            ['key' => 'descartados', 'label' => 'Descartados', 'percent_key' => 'descarte_pct'],
            ['key' => 'potenciales', 'label' => 'Potenciales'],
            ['key' => 'potenciales_sin_trabajar', 'label' => 'Potenciales sin trabajar'],
            ['key' => 'leads_unassigned', 'label' => 'Leads sin asignar'],
            ['key' => 'without_eligible_commercial', 'label' => 'Sin comercial elegible'],
            ['key' => 'without_commercial_delegation', 'label' => 'Sin delegación comercial'],
            ['key' => 'unclassified', 'label' => 'Sin clasificar'],
            ['key' => 'gestionados', 'label' => 'Gestionados', 'percent_key' => 'gestionados_pct'],
            ['key' => 'llamadas', 'label' => 'Llamadas', 'percent_key' => 'llamadas_pct'],
            ['key' => 'formularios', 'label' => 'Formularios', 'percent_key' => 'formularios_pct'],
        ];

        return array_map(function (array $metric) use ($current, $previous) {
            $currentValue = $current[$metric['key']] ?? null;
            $previousValue = $previous[$metric['key']] ?? null;
            $percentKey = $metric['percent_key'] ?? null;
            $currentPercent = $percentKey ? ($current[$percentKey] ?? null) : null;
            $previousPercent = $percentKey ? ($previous[$percentKey] ?? null) : null;

            return [
                'key' => $metric['key'],
                'metrica' => $metric['label'],
                'periodo_actual' => $currentValue,
                'periodo_actual_pct' => $currentPercent,
                'periodo_comparado' => $previousValue,
                'periodo_comparado_pct' => $previousPercent,
                'diferencia' => is_numeric($currentValue) && is_numeric($previousValue) ? round($currentValue - $previousValue, 2) : null,
                'diferencia_pct_puntos' => is_numeric($currentPercent) && is_numeric($previousPercent) ? round($currentPercent - $previousPercent, 2) : null,
                'variacion_pct' => $percentKey
                    ? null
                    : (is_numeric($currentValue) && is_numeric($previousValue) && (float) $previousValue !== 0.0
                        ? round((($currentValue - $previousValue) / $previousValue) * 100, 2)
                        : null),
                'is_compact' => $percentKey !== null,
                'is_percentage' => false,
            ];
        }, $metrics);
    }

    private function aiPayload(array $filters, array $periods, array $current, array $previous, array $comparison, array $portals, array $commercials, array $delegations): array
    {
        return [
            'periodo_actual' => $this->periodPayload($periods['current']),
            'periodo_comparado' => $this->periodPayload($periods['previous']),
            'filtros' => [
                'tipo_lead' => $filters['lead_type'] ?: 'all',
                'delegacion_lead' => $filters['lead_delegation'] ?: null,
                'delegacion_comercial' => $filters['commercial_delegation'] ?: null,
                'zona' => $filters['zone'] ?: null,
                'portal' => $filters['portal'] ?: null,
                'comercial' => $filters['commercial'] ?: null,
                'exposicion' => $filters['exposition_mode'] === 'without' ? 'excluir' : 'incluir',
            ],
            'kpis' => collect($current)->only([
                'leads_totales',
                'convertidos',
                'conversion_pct',
                'descartados',
                'descarte_pct',
                'potenciales',
                'potenciales_sin_trabajar',
                'leads_unassigned',
                'gestionados',
                'gestionados_pct',
            ])->all(),
            'comparativa' => [
                'conversion_delta_pp' => $this->deltaFromComparison($comparison, 'convertidos'),
                'descarte_delta_pp' => $this->deltaFromComparison($comparison, 'descartados'),
                'gestionados_delta_pp' => $this->deltaFromComparison($comparison, 'gestionados'),
                'potenciales_sin_trabajar_delta' => $this->deltaFromComparison($comparison, 'potenciales_sin_trabajar', false),
            ],
            'rankings' => [
                'comerciales_pendientes' => collect($commercials)
                    ->sortByDesc('potenciales_sin_trabajar')
                    ->take(5)
                    ->map(fn (array $row) => collect($row)->only(['comercial', 'leads_totales', 'potenciales_sin_trabajar', 'gestionados_pct'])->all())
                    ->values()
                    ->all(),
                'delegaciones_descartes' => collect($delegations)
                    ->sortByDesc('descartados')
                    ->take(5)
                    ->map(fn (array $row) => collect($row)->only(['lead_delegation', 'leads_totales', 'descartados', 'descarte_pct', 'potenciales_sin_trabajar'])->all())
                    ->values()
                    ->all(),
                'portales_baja_conversion' => collect($portals)
                    ->filter(fn (array $row) => ($row['leads_totales'] ?? 0) > 0)
                    ->sortBy([
                        ['conversion_pct', 'asc'],
                        ['leads_totales', 'desc'],
                    ])
                    ->take(5)
                    ->map(fn (array $row) => collect($row)->only(['portal', 'leads_totales', 'convertidos', 'conversion_pct', 'descartados', 'descarte_pct'])->all())
                    ->values()
                    ->all(),
            ],
            'cache_version' => Cache::get('lead_dashboard_cache_version', 1),
        ];
    }

    private function deltaFromComparison(array $comparison, string $key, bool $percent = true): float|int|null
    {
        $row = collect($comparison)->firstWhere('key', $key);

        return $percent
            ? data_get($row, 'diferencia_pct_puntos')
            : data_get($row, 'diferencia');
    }

    private function comparison(array $current, array $previous): array
    {
        $metrics = [
            ['key' => 'leads_totales', 'label' => 'Leads totales', 'ratio' => false],
            ['key' => 'convertidos', 'label' => 'Convertidos', 'ratio' => false],
            ['key' => 'conversion_pct', 'label' => '% conversión', 'ratio' => true],
            ['key' => 'descartados', 'label' => 'Descartados', 'ratio' => false],
            ['key' => 'descarte_pct', 'label' => '% descarte', 'ratio' => true],
            ['key' => 'potenciales', 'label' => 'Potenciales', 'ratio' => false],
            ['key' => 'potenciales_sin_trabajar', 'label' => 'Potenciales sin trabajar', 'ratio' => false],
            ['key' => 'leads_unassigned', 'label' => 'Leads sin asignar', 'ratio' => false],
            ['key' => 'gestionados', 'label' => 'Gestionados', 'ratio' => false],
            ['key' => 'gestionados_pct', 'label' => '% gestionados', 'ratio' => true],
            ['key' => 'llamadas', 'label' => 'Llamadas', 'ratio' => false],
            ['key' => 'formularios', 'label' => 'Formularios', 'ratio' => false],
            ['key' => 'llamadas_pct', 'label' => '% llamadas', 'ratio' => true],
            ['key' => 'formularios_pct', 'label' => '% formularios', 'ratio' => true],
        ];

        return array_map(function (array $metric) use ($current, $previous) {
            $currentValue = $current[$metric['key']] ?? null;
            $previousValue = $previous[$metric['key']] ?? null;

            return [
                'key' => $metric['key'],
                'metrica' => $metric['label'],
                'periodo_actual' => $currentValue,
                'periodo_comparado' => $previousValue,
                'diferencia' => is_numeric($currentValue) && is_numeric($previousValue) ? round($currentValue - $previousValue, 2) : null,
                'variacion_pct' => is_numeric($currentValue) && is_numeric($previousValue) && (float) $previousValue !== 0.0
                    ? round((($currentValue - $previousValue) / $previousValue) * 100, 2)
                    : null,
                'is_percentage' => $metric['ratio'],
            ];
        }, $metrics);
    }

    private function insights(array $current, array $previous, array $portals, array $commercials, array $delegations): array
    {
        if ($current['leads_totales'] === 0) {
            return ['No hay datos suficientes para generar conclusiones del periodo actual.'];
        }

        $insights = [
            'Hay '.$current['potenciales_sin_trabajar'].' potenciales sin trabajar en el periodo actual.',
        ];

        $conversionDiff = round(($current['conversion_pct'] ?? 0) - ($previous['conversion_pct'] ?? 0), 2);
        $insights[] = 'La conversión '.($conversionDiff >= 0 ? 'sube ' : 'baja ').abs($conversionDiff).' puntos frente al periodo comparado.';

        $discardDiff = round(($current['descarte_pct'] ?? 0) - ($previous['descarte_pct'] ?? 0), 2);
        $insights[] = 'El descarte '.($discardDiff >= 0 ? 'sube ' : 'baja ').abs($discardDiff).' puntos frente al periodo comparado.';

        if (! empty($portals[0])) {
            $insights[] = 'El portal '.$portals[0]['portal'].' concentra el mayor volumen de leads.';
        }

        $delegationsByPending = $delegations;
        usort($delegationsByPending, fn (array $a, array $b) => ($b['potenciales_sin_trabajar'] ?? 0) <=> ($a['potenciales_sin_trabajar'] ?? 0));
        if (! empty($delegationsByPending[0])) {
            $insights[] = 'La delegación '.$delegationsByPending[0]['lead_delegation'].' acumula más potenciales sin trabajar.';
        }

        $commercialsByPending = $commercials;
        usort($commercialsByPending, fn (array $a, array $b) => ($b['potenciales_sin_trabajar'] ?? 0) <=> ($a['potenciales_sin_trabajar'] ?? 0));
        if (! empty($commercialsByPending[0])) {
            $insights[] = 'El comercial '.$commercialsByPending[0]['comercial'].' acumula más potenciales sin trabajar.';
        }

        return array_slice($insights, 0, 6);
    }

    private function actionableInsights(array $current, array $previous, array $portals, array $commercials, array $delegations): array
    {
        if ($current['leads_totales'] === 0) {
            return ['No hay datos suficientes para generar conclusiones del periodo actual.'];
        }

        $insights = [];

        if ($current['potenciales_sin_trabajar'] > 0) {
            $insights[] = 'Hay '.$current['potenciales_sin_trabajar'].' potenciales sin trabajar en el periodo seleccionado.';
        }

        $conversionDiff = round(($current['conversion_pct'] ?? 0) - ($previous['conversion_pct'] ?? 0), 2);
        if ($conversionDiff < 0) {
            $insights[] = 'La conversion baja '.abs($conversionDiff).' puntos frente al periodo comparado.';
        }

        $discardDiff = round(($current['descarte_pct'] ?? 0) - ($previous['descarte_pct'] ?? 0), 2);
        if ($discardDiff > 0) {
            $insights[] = 'El descarte sube '.abs($discardDiff).' puntos frente al periodo comparado.';
        }

        $volumeThreshold = max(3, (int) ceil($current['leads_totales'] * 0.15));
        $weakPortal = collect($portals)
            ->filter(fn (array $portal) => ($portal['leads_totales'] ?? 0) >= $volumeThreshold
                && ($portal['conversion_pct'] ?? 0) < max(3, ($current['conversion_pct'] ?? 0) - 2))
            ->sortByDesc('leads_totales')
            ->first();
        if ($weakPortal) {
            $insights[] = 'El portal '.$weakPortal['portal'].' concentra alto volumen y baja conversion.';
        }

        $delegationsByPending = $delegations;
        usort($delegationsByPending, fn (array $a, array $b) => ($b['potenciales_sin_trabajar'] ?? 0) <=> ($a['potenciales_sin_trabajar'] ?? 0));
        if (! empty($delegationsByPending[0]) && ($delegationsByPending[0]['potenciales_sin_trabajar'] ?? 0) > 0) {
            $insights[] = 'La delegacion '.$delegationsByPending[0]['lead_delegation'].' acumula mas potenciales sin trabajar.';
        }

        $delegationsByDiscard = $delegations;
        usort($delegationsByDiscard, fn (array $a, array $b) => ($b['descartados'] ?? 0) <=> ($a['descartados'] ?? 0));
        if (! empty($delegationsByDiscard[0])
            && ($delegationsByDiscard[0]['descartados'] ?? 0) >= $volumeThreshold
            && ($delegationsByDiscard[0]['descarte_pct'] ?? 0) > max(30, ($current['descarte_pct'] ?? 0) + 5)) {
            $insights[] = 'La delegacion '.$delegationsByDiscard[0]['lead_delegation'].' concentra un nivel alto de descartes.';
        }

        $commercialsByPending = $commercials;
        usort($commercialsByPending, fn (array $a, array $b) => ($b['potenciales_sin_trabajar'] ?? 0) <=> ($a['potenciales_sin_trabajar'] ?? 0));
        if (! empty($commercialsByPending[0]) && (($commercialsByPending[0]['potenciales_sin_trabajar'] ?? 0) >= 3
            || (($commercialsByPending[0]['leads_totales'] ?? 0) >= $volumeThreshold && ($commercialsByPending[0]['gestionados_pct'] ?? 100) < 50))) {
            $insights[] = 'El comercial '.$commercialsByPending[0]['comercial'].' acumula muchos leads pendientes o baja gestion.';
        }

        return array_slice($insights ?: ['No se detectan alertas relevantes con los filtros actuales.'], 0, 6);
    }

    private function filterOptions(array $rows): array
    {
        $rows = collect($rows);

        $commercialDelegations = $this->commercialUsers()
            ->pluck('user_delegation')
            ->map(fn ($delegation) => $this->normalizeCommercialDelegation($delegation)['delegation'])
            ->filter()
            ->unique();

        $zones = collect($this->delegationNormalizer->knownZones())
            ->merge($rows->pluck('commercial_zone'))
            ->filter()
            ->reject(fn (string $zone) => $zone === LeadDelegationNormalizer::UNCLASSIFIED)
            ->unique();

        return [
            'commercials' => $this->commercialUsers()
                ->map(fn ($user, string $id) => ['id' => $id, 'name' => $user['name']])
                ->sortBy('name')
                ->values()
                ->all(),
            'portals' => $rows
                ->pluck('portal')
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all(),
            'lead_delegations' => $this->delegationNormalizer->sortLabels($rows->pluck('lead_delegation')->all()),
            'lead_types' => $this->leadTypeFilterLabels(),
            'commercial_delegations' => $this->delegationNormalizer->sortLabels($commercialDelegations->all()),
            'zones' => $this->delegationNormalizer->sortLabels($zones->all()),
        ];
    }

    private function emptyFilterOptionsAccumulator(): array
    {
        return [
            'portals' => [],
            'lead_delegations' => [],
            'zones' => [],
        ];
    }

    private function collectFilterOptions(array &$options, array $lead): void
    {
        if (filled($lead['portal'] ?? null)) {
            $options['portals'][$lead['portal']] = true;
        }

        if (filled($lead['lead_delegation'] ?? null)) {
            $options['lead_delegations'][$lead['lead_delegation']] = true;
        }

        if (filled($lead['commercial_zone'] ?? null) && $lead['commercial_zone'] !== LeadDelegationNormalizer::UNCLASSIFIED) {
            $options['zones'][$lead['commercial_zone']] = true;
        }
    }

    private function filterOptionsFromAccumulator(array $options): array
    {
        $commercialDelegations = $this->commercialUsers()
            ->pluck('user_delegation')
            ->map(fn ($delegation) => $this->normalizeCommercialDelegation($delegation)['delegation'])
            ->filter()
            ->unique();

        $zones = collect($this->delegationNormalizer->knownZones())
            ->merge(array_keys($options['zones']))
            ->filter()
            ->reject(fn (string $zone) => $zone === LeadDelegationNormalizer::UNCLASSIFIED)
            ->unique();

        return [
            'commercials' => $this->commercialUsers()
                ->map(fn ($user, string $id) => ['id' => $id, 'name' => $user['name']])
                ->sortBy('name')
                ->values()
                ->all(),
            'portals' => collect(array_keys($options['portals']))
                ->filter()
                ->sort()
                ->values()
                ->all(),
            'lead_delegations' => $this->delegationNormalizer->sortLabels(array_keys($options['lead_delegations'])),
            'lead_types' => $this->leadTypeFilterLabels(),
            'commercial_delegations' => $this->delegationNormalizer->sortLabels($commercialDelegations->all()),
            'zones' => $this->delegationNormalizer->sortLabels($zones->all()),
        ];
    }

    private function cacheKey(array $filters, array $periods): string
    {
        $cacheFilters = $filters;

        // El contexto solo cambia el resultado cuando hay un filtro de zona:
        // comerciales filtra por zona comercial y el resto por zona del lead.
        // Sin zona, todos los endpoints construyen el mismo dataset completo y
        // deben reutilizarlo para no recalcularlo cuatro veces en cada recarga.
        $cacheFilters['context'] = filled($filters['zone'])
            ? $this->zoneFieldForContext($filters)
            : 'shared';

        return 'lead-dashboard-v9:'.md5(json_encode([
            'filters' => $cacheFilters,
            'periods' => [
                'current' => $this->periodPayload($periods['current']),
                'previous' => $this->periodPayload($periods['previous']),
            ],
            'version' => $this->dataVersion(),
        ]));
    }

    /** @return list<string> */
    private function leadTypeFilterLabels(): array
    {
        return [
            $this->recordTypeNormalizer->label(LeadRecordTypeNormalizer::TASACION),
            $this->recordTypeNormalizer->label(LeadRecordTypeNormalizer::VENTA),
        ];
    }

    protected function dataVersion(): array
    {
        return [
            'dashboard_cache_version' => Cache::get('lead_dashboard_cache_version', 1),
        ];
    }

    private function periodPayload(array $period): array
    {
        return [
            'inicio' => CarbonImmutable::parse($period['start'])->toDateString(),
            'fin' => CarbonImmutable::parse($period['end'])->toDateString(),
        ];
    }

    protected function lastUpdated(): ?CarbonImmutable
    {
        $updated = SalesforceLead::query()->max('updated_at');

        return $updated ? CarbonImmutable::parse($updated) : null;
    }

    /** @return array<string, mixed> */
    protected function syncMetadata(array $period): array
    {
        $completedRuns = Schema::hasTable('report_sync_runs')
            ? ReportSyncRun::query()
                ->where('dataset', 'leads_dashboard')
                ->where('source', 'salesforce')
                ->where('status', 'completed')
            : null;
        $latestCompletedRun = $completedRuns !== null
            ? (clone $completedRuns)
                ->latest('completed_at')
                ->latest('id')
                ->first()
            : null;
        $coveringCompletedRun = $completedRuns !== null
            ? (clone $completedRuns)
                ->where('period_start_at', '<=', $period['start'])
                ->where('period_end_at', '>=', $period['end'])
                ->latest('completed_at')
                ->latest('id')
                ->first()
            : null;
        $leadsSyncedAt = SalesforceLead::query()->max('synced_at');
        $activitiesSyncedAt = SalesforceLeadActivitySummary::query()->max('updated_at');
        $leadsCutoff = $leadsSyncedAt
            ? CarbonImmutable::parse($leadsSyncedAt)
            : $this->lastUpdated();
        $activitiesCutoff = $activitiesSyncedAt ? CarbonImmutable::parse($activitiesSyncedAt) : null;
        $cutoff = $coveringCompletedRun?->source_cutoff_at
            ? CarbonImmutable::parse($coveringCompletedRun->source_cutoff_at)
            : collect([$leadsCutoff, $activitiesCutoff])
                ->filter()
                ->sortBy(fn (CarbonImmutable $date) => $date->getTimestamp())
                ->first();
        $leadsFreshness = $latestCompletedRun?->source_cutoff_at
            ? CarbonImmutable::parse($latestCompletedRun->source_cutoff_at)
            : $leadsCutoff;
        $activitiesFreshness = $latestCompletedRun?->source_cutoff_at
            ? CarbonImmutable::parse($latestCompletedRun->source_cutoff_at)
            : $activitiesCutoff;
        $periodLeadQuery = SalesforceLead::query()
            ->where('created_date', '>=', $period['start'])
            ->where('created_date', '<=', $period['end']);
        $periodCount = (clone $periodLeadQuery)->count();

        return [
            'salesforce_leads_synced_at' => $leadsFreshness?->toDateTimeString(),
            'activities_synced_at' => $activitiesFreshness?->toDateTimeString(),
            'dataset_generated_at' => CarbonImmutable::now(self::DATASET_TIMEZONE)->toDateTimeString(),
            'dataset_cutoff_at' => $cutoff?->toDateTimeString(),
            'period_start' => CarbonImmutable::parse($period['start'])->toDateTimeString(),
            'period_end' => CarbonImmutable::parse($period['end'])->toDateTimeString(),
            'timezone' => self::DATASET_TIMEZONE,
            'sync_run_id' => $latestCompletedRun?->id,
            'sync_run_status' => $latestCompletedRun?->status,
            'metadata_coverage' => [
                'total' => $periodCount,
                'without_synced_at' => (clone $periodLeadQuery)->whereNull('synced_at')->count(),
                'without_last_modified_at' => (clone $periodLeadQuery)->whereNull('salesforce_last_modified_at')->count(),
            ],
        ];
    }

    private function percentage(int|float $value, int|float $total): ?float
    {
        return $total > 0 ? round(($value / $total) * 100, 2) : null;
    }

    private function commercialUsers(): Collection
    {
        if ($this->commercialUsersCache !== null) {
            return $this->commercialUsersCache;
        }

        return $this->commercialUsersCache = SalesforceUser::query()
            ->where('is_active', true)
            ->whereIn('profile_name', self::COMMERCIAL_PROFILES)
            ->get()
            ->keyBy('salesforce_id')
            ->map(fn (SalesforceUser $user) => [
                'name' => $user->name,
                'profile_name' => $user->profile_name,
                'user_delegation' => $user->user_delegation,
            ]);
    }

    private function portalMap(): Collection
    {
        return $this->portalMapCache ??= MasterPortal::query()
            ->where('is_active', true)
            ->get()
            ->mapWithKeys(fn (MasterPortal $portal) => [$this->normalizeComparable($portal->portal_original) => $portal->portal_group]);
    }

    private function portalGroup(string $portal): string
    {
        if (array_key_exists($portal, $this->portalGroupResolutionCache)) {
            return $this->portalGroupResolutionCache[$portal];
        }

        return $this->portalGroupResolutionCache[$portal] = $this->portalMap()->get($this->normalizeComparable($portal)) ?? $portal ?: 'Sin clasificar';
    }

    private function parseDate(?string $value, CarbonImmutable $fallback): CarbonImmutable
    {
        if (blank($value)) {
            return $fallback;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private function auditDate(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function clean(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function normalizeComparable(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->ascii()
            ->replace(['hr motor ', '.', ',', '-', '_', '/'], [''])
            ->replaceMatches('/\s+/', '')
            ->toString();
    }
}
