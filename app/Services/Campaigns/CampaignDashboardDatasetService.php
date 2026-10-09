<?php

namespace App\Services\Campaigns;

use App\Models\CampaignAttribution;
use App\Models\CampaignOperationalClassification;
use App\Models\CampaignPlatformDailyMetric;
use App\Models\ReportSyncRun;
use App\Models\SalesforceOpportunity;
use App\Services\Reports\Leads\LeadRecordTypeNormalizer;
use App\Services\Reports\ReservationsSales\OpportunityInterestAttributionService;
use App\Services\Salesforce\SalesforceInterestSyncService;
use App\Support\ReportUserAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CampaignDashboardDatasetService
{
    private const CACHE_TTL_MINUTES = 10;

    private const REPORT_TIMEZONE = 'Europe/Madrid';

    public function __construct(
        private readonly CampaignValueNormalizer $normalizer,
        private readonly CampaignPerformanceClassifier $classifier,
        private readonly CampaignSaleAmountResolver $saleAmountResolver,
        private readonly CampaignTypeResolver $campaignTypeResolver,
        private readonly LeadRecordTypeNormalizer $leadRecordTypeNormalizer,
        private readonly CampaignInvestmentClosureService $investmentClosures,
        private readonly OpportunityInterestAttributionService $opportunityInterestAttribution,
    ) {}

    public function summary(Request $request): array
    {
        $includeDiagnostics = ReportUserAccess::isAdmin($request) && $request->boolean('include_diagnostics', true);
        $payload = $this->payload($request, $includeDiagnostics, true);
        $summary = $payload['summary'];
        $summary['campaigns'] = $payload['campaigns'];
        $summary['rankings'] = $payload['rankings'];

        if (! ReportUserAccess::isAdmin($request)) {
            $summary['warnings'] = $summary['public_warnings'] ?? [];
            unset($summary['diagnostics']);
        } elseif (! $includeDiagnostics) {
            unset($summary['diagnostics']);
        }

        return $summary;
    }

    public function campaignRows(Request $request): array
    {
        $payload = $this->payload($request, false, false);

        return [
            'ok' => true,
            'items' => $payload['campaigns'],
            'total' => count($payload['campaigns']),
        ];
    }

    public function rankings(Request $request): array
    {
        return [
            'ok' => true,
            'rankings' => $this->payload($request, false, false)['rankings'],
        ];
    }

    public function exportRows(Request $request): array
    {
        return $this->payload($request, false, false)['campaigns'];
    }

    public function attributionAuditRows(Request $request): array
    {
        $filters = $this->filters($request);
        $period = $this->period($filters);
        $query = DB::table('campaign_lead_attributions as cla')
            ->leftJoin('campaign_attributions as ca', 'ca.interest_id', '=', 'cla.interest_id')
            ->where('cla.interest_functional_created_at', '>=', $period['start_at'])
            ->where('cla.interest_functional_created_at', '<', $period['end_at']);

        $this->applyLeadAttributionFilters($query, $filters, 'cla');
        $this->applyCampaignContextFilter($query, $filters, 'cla');

        $rows = $query
            ->select([
                'cla.id as attribution_id',
                'cla.interest_id',
                'cla.interest_functional_created_at',
                'cla.campaign_name as resolved_campaign_name',
                'cla.campaign_id as resolved_campaign_id',
                'cla.source_campaign_name as raw_campaign_name',
                'cla.campaign_acquired',
                'cla.platform',
                'cla.campaign_type',
                'cla.acquired_id',
                'cla.content_acquired',
                'cla.attribution_method as row_attribution_method',
                'cla.attribution_confidence as row_attribution_confidence',
                'cla.match_status as row_match_status',
                'cla.campaign_source_type as row_campaign_source_type',
                'cla.matched_source_field',
                'cla.matched_source_value',
                'cla.matched_platform_field',
                'cla.matched_platform_value',
                'cla.match_candidate_count',
                'cla.attribution_candidates',
                'cla.first_touch_at',
                'cla.is_ambiguous',
                'cla.attribution_rule_version',
                'cla.opportunity_id',
                'cla.created_at as attribution_built_at',
                'cla.updated_at as attribution_updated_at',
                'ca.attribution_method as lead_attribution_method',
                'ca.attribution_confidence as lead_attribution_confidence',
                'ca.match_status as lead_match_status',
                'ca.campaign_source_type as lead_campaign_source_type',
                'cla.interest_type',
                'cla.interest_source',
                'cla.interest_original_source',
                'cla.interest_medium',
                'cla.interest_channel',
                'cla.interest_utm_term',
                'cla.interest_status',
                'cla.interest_origin_delegation',
                'cla.interest_origin_zone',
                'cla.interest_owner_id',
                'cla.interest_owner_name',
                'cla.interest_is_deleted',
                'cla.interest_sync_run_id',
                'cla.interest_sync_cutoff_at',
                'cla.opportunity_relationship_status',
            ])
            ->orderBy('cla.interest_id')
            ->orderBy('cla.id')
            ->get()
            ->map(function (object $row): array {
                $method = $row->row_attribution_method ?: $row->lead_attribution_method;
                $matchValue = $row->acquired_id ?: $row->content_acquired;

                return [
                    'attribution_id' => (int) $row->attribution_id,
                    'interest_id' => $row->interest_id,
                    'resolved_campaign_name' => $row->resolved_campaign_name,
                    'raw_campaign_name' => $row->raw_campaign_name ?: $row->campaign_acquired,
                    'platform' => $row->platform,
                    'ad_id' => $method === 'ad_id_match' ? $matchValue : null,
                    'adset_or_adgroup_id' => $method === 'adset_or_adgroup_id_match' ? $matchValue : null,
                    'campaign_id' => $row->resolved_campaign_id,
                    'raw_acquired_id' => $row->acquired_id,
                    'raw_content_id' => $row->content_acquired,
                    'match_type' => $method,
                    'match_confidence' => $row->row_attribution_confidence ?: $row->lead_attribution_confidence,
                    'match_status' => $row->row_match_status ?: $row->lead_match_status,
                    'matched_source_field' => $row->matched_source_field,
                    'matched_source_value' => $row->matched_source_value,
                    'matched_platform_field' => $row->matched_platform_field,
                    'matched_platform_value' => $row->matched_platform_value,
                    'match_candidate_count' => (int) $row->match_candidate_count,
                    'attribution_candidates' => $this->decodeJsonList($row->attribution_candidates),
                    'first_touch_at' => $row->first_touch_at,
                    'is_ambiguous' => (bool) $row->is_ambiguous,
                    'attribution_rule_version' => $row->attribution_rule_version,
                    'campaign_source_type' => $row->row_campaign_source_type ?: $row->lead_campaign_source_type,
                    'exclusion_reason' => ($row->row_campaign_source_type ?: $row->lead_campaign_source_type) === 'excluded_campaign'
                        ? ($row->row_match_status ?: $row->lead_match_status)
                        : null,
                    'exclusion_match_type' => ($row->row_campaign_source_type ?: $row->lead_campaign_source_type) === 'excluded_campaign'
                        ? 'exact_name'
                        : null,
                    'exclusion_match_value' => ($row->row_campaign_source_type ?: $row->lead_campaign_source_type) === 'excluded_campaign'
                        ? ($row->matched_source_value ?: $row->raw_campaign_name ?: $row->campaign_acquired)
                        : null,
                    'campaign_type' => $row->campaign_type,
                    'interest_type' => $row->interest_type,
                    'interest_source' => $row->interest_source,
                    'interest_original_source' => $row->interest_original_source,
                    'interest_medium' => $row->interest_medium,
                    'interest_channel' => $row->interest_channel,
                    'interest_utm_term' => $row->interest_utm_term,
                    'interest_status' => $row->interest_status,
                    'interest_origin_delegation' => $row->interest_origin_delegation,
                    'interest_origin_zone' => $row->interest_origin_zone,
                    'interest_owner_id' => $row->interest_owner_id,
                    'interest_owner_name' => $row->interest_owner_name,
                    'interest_functional_created_at' => $row->interest_functional_created_at,
                    'opportunity_id' => $row->opportunity_id,
                    'attribution_built_at' => $row->attribution_built_at,
                    'attribution_updated_at' => $row->attribution_updated_at,
                    'interest_is_deleted' => (bool) $row->interest_is_deleted,
                    'interest_sync_run_id' => $row->interest_sync_run_id,
                    'interest_sync_cutoff_at' => $row->interest_sync_cutoff_at,
                    'opportunity_relationship_status' => $row->opportunity_relationship_status,
                ];
            })
            ->values()
            ->all();

        $campaignCounts = collect($rows)
            ->groupBy('interest_id')
            ->map(fn (Collection $leadRows): int => $leadRows
                ->map(fn (array $row): string => implode('|', [
                    $row['platform'],
                    $row['campaign_id'],
                    $row['resolved_campaign_name'],
                ]))
                ->unique()
                ->count());

        return array_map(function (array $row) use ($campaignCounts): array {
            $row['campaigns_for_interest'] = (int) $campaignCounts->get($row['interest_id'], 1);
            $row['overlaps_another_campaign'] = $row['campaigns_for_interest'] > 1;

            return $row;
        }, $rows);
    }

    public function kpiAudit(Request $request): array
    {
        $filters = $this->filters($request);
        $period = $this->period($filters);
        $metric = $this->resolveAuditMetric($request->string('metric')->toString(), $filters['context']);

        return Cache::remember(
            'campaign-dashboard-kpi-audit-v1:'.md5(json_encode([
                'filters' => $filters,
                'period' => $period,
                'metric' => $metric,
                'version' => $this->dataVersion(),
            ])),
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn () => $this->buildKpiAuditPayload($filters, $period, $metric)
        );
    }

    public function exportKpiAuditRows(Request $request): array
    {
        return $this->kpiAudit($request)['items'] ?? [];
    }

    public function payload(Request $request, bool $includeDiagnostics = true, bool $includeFilters = true): array
    {
        $filters = $this->filters($request);
        $period = $this->period($filters);

        return Cache::remember(
            'campaign-dashboard-v4:'.md5(json_encode([
                'filters' => $filters,
                'period' => $period,
                'include_diagnostics' => $includeDiagnostics,
                'include_filters' => $includeFilters,
                'version' => $this->dataVersion(),
            ])),
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn () => $this->buildPayload($filters, $period, $includeDiagnostics, $includeFilters)
        );
    }

    private function buildKpiAuditPayload(array $filters, array $period, string $metric): array
    {
        $visibleRows = $this->visibleCampaignRows($filters, $period);
        $items = $this->kpiAuditRows($visibleRows, $filters, $period, $metric);

        return [
            'ok' => true,
            'metric' => $metric,
            'metric_label' => $this->auditMetricLabel($metric),
            'selected_context' => $filters['context'],
            'selected_context_label' => $this->contextLabel($filters['context']),
            'periodo_actual' => [
                'inicio' => $period['start'],
                'fin' => $period['end'],
            ],
            'total' => count($items),
            'items' => $items,
        ];
    }

    public function filters(Request $request): array
    {
        $requestedType = $this->normalizeContext($request->string('campaign_type')->toString());
        $context = $requestedType !== 'all'
            ? $requestedType
            : $request->string('context')->toString();

        return [
            'start_date' => $request->string('start_date')->toString(),
            'end_date' => $request->string('end_date')->toString(),
            'platform' => $request->string('platform')->toString(),
            'account_id' => $request->string('account_id')->toString(),
            'search' => $request->string('search')->toString(),
            'context' => $this->normalizeContext($context),
            'campaign_status' => $request->exists('campaign_status')
                ? $request->string('campaign_status')->toString()
                : 'active',
            'campaign_source_type' => $request->string('campaign_source_type')->toString(),
            'source_acquired' => $request->string('source_acquired')->toString(),
            'medium_acquired' => $request->string('medium_acquired')->toString(),
            'campaign_acquired' => $request->string('campaign_acquired')->toString(),
            'campaign_id' => $request->string('campaign_id')->toString(),
            'campaign_name' => $this->campaignNameFilter($request->input('campaign_name')),
            'campaign_type' => $requestedType === 'all' ? '' : $requestedType,
            'delegation' => $request->string('delegation')->toString(),
            'zone' => $request->string('zone')->toString(),
            'interest_status' => $request->string('interest_status')->toString()
                ?: $request->string('lead_status')->toString(),
            'lead_type' => $this->normalizeLeadTypeFilter($request->string('lead_type')->toString()),
            'has_opportunity' => $request->string('has_opportunity')->toString(),
            'has_reservation' => $request->string('has_reservation')->toString(),
            'has_sale' => $request->string('has_sale')->toString(),
            'classification' => $request->string('classification')->toString(),
            'commercial_user' => $request->string('commercial_user')->toString(),
            'vehicle_interest' => $request->string('vehicle_interest')->toString(),
        ];
    }

    private function buildPayload(array $filters, array $period, bool $includeDiagnostics = true, bool $includeFilters = true): array
    {
        $metricRows = $this->metricRows($filters, $period);
        $attributionRows = $this->attributionRows($filters, $period);
        $allCampaigns = $this->mergeRows($metricRows, $attributionRows, $filters);
        $allCampaigns = $this->applyOperationalClassifications($allCampaigns);
        $benchmarks = $this->benchmarks($this->platformRows($allCampaigns));

        $allCampaigns = array_map(function (array $row) use ($benchmarks): array {
            $row['classification'] = $this->classifier->classify($row, $benchmarks);

            return $row;
        }, $allCampaigns);

        $campaigns = $this->mainCampaignRows($allCampaigns, $filters);
        $executiveCampaigns = array_values(array_filter($campaigns, fn (array $row): bool => ($row['operational_classification'] ?? 'pending_review') !== 'test'));

        usort($campaigns, fn (array $a, array $b) => ($b['spend'] <=> $a['spend']) ?: ($b['leads_salesforce'] <=> $a['leads_salesforce']));
        usort($executiveCampaigns, fn (array $a, array $b) => ($b['spend'] <=> $a['spend']) ?: ($b['leads_salesforce'] <=> $a['leads_salesforce']));

        $attributionAnalytics = $this->attributionAnalytics($executiveCampaigns, $filters, $period);
        $summary = $this->summaryFromRows($executiveCampaigns, $allCampaigns, $filters, $period, $attributionAnalytics, $includeDiagnostics, $includeFilters);
        $summary['investment_closure'] = $this->investmentClosures->status($period['start']);
        $summary['campaign_universes'] = [
            'paid' => array_values(array_filter($campaigns, fn (array $row): bool => in_array($row['platform'] ?? null, ['google_ads', 'meta'], true) && ($row['operational_classification'] ?? null) !== 'test')),
            'salesforce_only' => array_values(array_filter($campaigns, fn (array $row): bool => ($row['platform'] ?? null) === 'salesforce')),
            'test' => array_values(array_filter($campaigns, fn (array $row): bool => ($row['operational_classification'] ?? null) === 'test')),
            'pending_review' => array_values(array_filter($campaigns, fn (array $row): bool => ($row['operational_classification'] ?? null) === 'pending_review')),
            'ambiguous' => array_values(array_filter($campaigns, fn (array $row): bool => str_contains(mb_strtolower((string) ($row['match_status'] ?? '')), 'ambigu'))),
            'unattributed' => array_values(array_filter($campaigns, fn (array $row): bool => blank($row['campaign_id'] ?? null) && blank($row['campaign_name'] ?? null))),
        ];
        $rankings = $this->rankingsFromRows($executiveCampaigns, $filters);

        return [
            'summary' => $summary,
            'campaigns' => array_values($campaigns),
            'rankings' => $rankings,
        ];
    }

    private function visibleCampaignRows(array $filters, array $period): array
    {
        $metricRows = $this->metricRows($filters, $period);
        $attributionRows = $this->attributionRows($filters, $period);
        $allCampaigns = $this->mergeRows($metricRows, $attributionRows, $filters);
        $allCampaigns = $this->applyOperationalClassifications($allCampaigns);
        $benchmarks = $this->benchmarks($this->platformRows($allCampaigns));

        $allCampaigns = array_map(function (array $row) use ($benchmarks): array {
            $row['classification'] = $this->classifier->classify($row, $benchmarks);

            return $row;
        }, $allCampaigns);

        return $this->mainCampaignRows($allCampaigns, $filters);
    }

    private function applyOperationalClassifications(array $rows): array
    {
        $classifications = CampaignOperationalClassification::query()->get()->keyBy(
            fn (CampaignOperationalClassification $row): string => implode('|', [$row->platform, $row->account_id, $row->campaign_id])
        );

        return array_map(function (array $row) use ($classifications): array {
            $campaignId = trim((string) ($row['campaign_id'] ?? ''));
            $key = implode('|', [(string) ($row['platform'] ?? ''), (string) ($row['account_id'] ?? ''), $campaignId]);
            $saved = $campaignId !== '' ? $classifications->get($key) : null;
            $row['operational_classification'] = $saved?->classification ?? 'pending_review';
            $row['operational_classification_reason'] = $saved?->reason ?? 'Pendiente de clasificación explícita por ID';
            $row['test_name_candidate'] = preg_match('/(?:^|[^a-z])(prueba|test)(?:[^a-z]|$)/i', (string) ($row['campaign_name'] ?? '')) === 1;

            return $row;
        }, $rows);
    }

    private function decodeJsonList(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function kpiAuditRows(array $visibleRows, array $filters, array $period, string $metric): array
    {
        $visibleRowKeys = array_fill_keys(array_map(fn (array $row): string => $this->rowKey($row), $visibleRows), true);

        if ($visibleRowKeys === []) {
            return [];
        }

        $query = DB::table('campaign_lead_attributions as cla')
            ->leftJoin('salesforce_opportunities as so', 'so.salesforce_id', '=', 'cla.opportunity_id')
            ->where('cla.interest_functional_created_at', '>=', $period['start_at'])
            ->where('cla.interest_functional_created_at', '<', $period['end_at']);

        $this->applyLeadAttributionFilters($query, $filters, 'cla');

        if (filled($filters['campaign_source_type'] ?? null)) {
            if ($filters['campaign_source_type'] === 'platform_campaign') {
                $query->where('cla.platform', '<>', 'salesforce');
            } else {
                $query->where('cla.platform', 'salesforce');
            }
        }

        $this->applyCampaignContextFilter($query, $filters, 'cla');

        $items = [];

        $query
            ->select([
                'cla.id',
                'cla.interest_id',
                'cla.interest_functional_created_at',
                'cla.platform',
                'cla.campaign_id',
                'cla.campaign_name',
                'cla.source_campaign_name',
                'cla.campaign_type',
                'cla.opportunity_id',
                'cla.has_opportunity',
                'cla.has_reservation',
                'cla.has_sale',
                'cla.has_purchase',
                'cla.sold_amount',
                'cla.source_acquired',
                'cla.medium_acquired',
                'cla.campaign_acquired',
                'cla.acquired_id',
                'cla.content_acquired',
                'cla.interest_status',
                'cla.interest_type',
                'cla.interest_source',
                'cla.interest_original_source',
                'cla.interest_medium',
                'cla.interest_channel',
                'cla.interest_utm_term',
                'cla.interest_origin_delegation',
                'cla.interest_origin_zone',
                'cla.interest_owner_id',
                'cla.interest_owner_name',
                'cla.interest_is_deleted',
                'cla.interest_sync_run_id',
                'cla.interest_sync_cutoff_at',
                'cla.opportunity_relationship_status',
                'cla.commercial_user_id',
                'cla.commercial_user_name',
                'cla.vehicle_interest',
                'so.created_date as opportunity_created_date',
                'so.close_date as opportunity_close_date',
                'so.cv_signed_date',
                'so.record_type_name',
                'so.stage_name',
                'so.owner_id as opportunity_owner_id',
                'so.owner_name as opportunity_owner_name',
                'so.account_id',
                'so.portal_original as opportunity_portal_original',
                'so.portal_resolved as opportunity_portal_resolved',
                'so.opportunity_source_raw',
                'so.opportunity_source_normalized',
                'so.opo_for_importe_total',
                'so.is_deleted as opportunity_is_deleted',
                'so.deletion_detection_source as opportunity_deletion_source',
            ])
            ->orderBy('cla.id')
            ->chunkById(1000, function ($chunk) use (&$items, $visibleRowKeys, $metric): void {
                foreach ($chunk as $row) {
                    $identity = $this->rowKey($this->attributionIdentityRow($row));

                    if (! isset($visibleRowKeys[$identity])) {
                        continue;
                    }

                    if (! $this->qualifiesAuditMetric($row, $metric)) {
                        continue;
                    }

                    $entityKey = $this->auditEntityKey($row, $metric);

                    if ($entityKey === null) {
                        continue;
                    }

                    $items[$entityKey] ??= $this->emptyAuditRow($metric, $entityKey, (int) $row->id);

                    $this->accumulateAuditRow($items[$entityKey], $row);
                }
            }, 'cla.id', 'id');

        $rows = array_values(array_map(fn (array $item): array => $this->finalizeAuditRow($item), $items));

        usort($rows, function (array $left, array $right): int {
            return [
                $left['metric_date'] ?? '9999-12-31',
                $left['entity_id'] ?? '',
            ] <=> [
                $right['metric_date'] ?? '9999-12-31',
                $right['entity_id'] ?? '',
            ];
        });

        return $rows;
    }

    private function metricRows(array $filters, array $period): array
    {
        $closedRows = $this->closedEconomicRows($filters, $period);
        if ($closedRows !== null) {
            return $closedRows;
        }

        $query = DB::table('campaign_platform_daily_metrics')
            ->where('metric_date', '>=', $period['start'])
            ->where('metric_date', '<=', $period['end']);

        $this->applyMetricFilters($query, $filters);

        return $query
            ->select([
                'platform',
                'account_id',
                DB::raw('MIN(account_name) as account_name'),
                'campaign_id',
                'campaign_name',
                DB::raw('MIN(campaign_status) as campaign_status'),
                DB::raw('MIN(campaign_effective_status) as campaign_effective_status'),
                DB::raw('MIN(campaign_start_date) as campaign_start_date'),
                DB::raw('MIN(campaign_end_date) as campaign_end_date'),
                DB::raw('MIN(advertising_channel_type) as advertising_channel_type'),
                DB::raw('MIN(advertising_channel_sub_type) as advertising_channel_sub_type'),
                DB::raw('MAX(CASE WHEN COALESCE(spend, 0) > 0 THEN metric_date ELSE NULL END) as last_spend_date'),
                DB::raw("'platform_campaign' as campaign_source_type"),
                DB::raw('SUM(COALESCE(spend, 0)) as spend'),
                DB::raw('SUM(COALESCE(impressions, 0)) as impressions'),
                DB::raw('SUM(COALESCE(clicks, 0)) as clicks'),
                DB::raw('SUM(COALESCE(platform_leads, 0)) as platform_leads'),
                DB::raw('SUM(CASE WHEN platform_leads IS NOT NULL THEN 1 ELSE 0 END) as platform_leads_rows'),
                DB::raw('SUM(COALESCE(platform_conversions, 0)) as platform_conversions'),
            ])
            ->groupBy('platform', 'account_id', 'campaign_id', 'campaign_name')
            ->get()
            ->map(fn ($row) => $this->normalizeDashboardRow((array) $row))
            ->filter(fn (array $row): bool => $this->includeCampaignForContext($row, $filters))
            ->values()
            ->all();
    }

    private function closedEconomicRows(array $filters, array $period): ?array
    {
        $start = CarbonImmutable::parse($period['start']);
        $end = CarbonImmutable::parse($period['end']);
        if (! $start->isSameDay($start->startOfMonth()) || ! $end->isSameDay($start->endOfMonth())) {
            return null;
        }

        $status = $this->investmentClosures->status($start->toDateString());
        if ($status['investment_status'] !== 'closed') {
            return null;
        }

        $snapshot = DB::table('campaign_investment_closures as cic')
            ->join('campaign_investment_closure_snapshots as s', function ($join): void {
                $join->on('s.closure_id', '=', 'cic.id')->on('s.version', '=', 'cic.snapshot_version');
            })
            ->where('cic.month', $start->toDateString())
            ->value('s.economic_rows');
        $rows = is_string($snapshot) ? json_decode($snapshot, true) : [];

        return collect($rows)->map(function (array $row): array {
            $row = array_merge([
                'account_name' => null, 'campaign_status' => null, 'campaign_effective_status' => null,
                'campaign_start_date' => null, 'campaign_end_date' => null, 'advertising_channel_type' => null,
                'advertising_channel_sub_type' => null, 'last_spend_date' => null, 'campaign_source_type' => 'platform_campaign',
                'platform_leads' => 0, 'platform_leads_rows' => 0, 'platform_conversions' => 0,
            ], $row);

            return $this->normalizeDashboardRow($row);
        })->filter(fn (array $row): bool => $this->snapshotRowMatchesMetricFilters($row, $filters))
            ->filter(fn (array $row): bool => $this->includeCampaignForContext($row, $filters))
            ->values()
            ->all();
    }

    private function snapshotRowMatchesMetricFilters(array $row, array $filters): bool
    {
        if (filled($filters['campaign_source_type'] ?? null) && $filters['campaign_source_type'] !== 'platform_campaign') {
            return false;
        }

        foreach (['platform', 'account_id', 'campaign_id'] as $field) {
            if (filled($filters[$field] ?? null) && (string) ($row[$field] ?? '') !== (string) $filters[$field]) {
                return false;
            }
        }

        $campaignNames = $filters['campaign_name'] ?? [];
        if (is_array($campaignNames) && $campaignNames !== [] && ! in_array($row['campaign_name'] ?? null, $campaignNames, true)) {
            return false;
        }
        if (! is_array($campaignNames) && filled($campaignNames) && (string) ($row['campaign_name'] ?? '') !== (string) $campaignNames) {
            return false;
        }

        if (filled($filters['search'] ?? null)) {
            $haystack = mb_strtolower(implode(' ', [(string) ($row['campaign_id'] ?? ''), (string) ($row['campaign_name'] ?? '')]));
            if (! str_contains($haystack, mb_strtolower((string) $filters['search']))) {
                return false;
            }
        }

        return true;
    }

    private function attributionRows(array $filters, array $period): array
    {
        $query = DB::table('campaign_lead_attributions as cla')
            ->leftJoin('salesforce_opportunities as so', 'so.salesforce_id', '=', 'cla.opportunity_id')
            ->where('cla.interest_functional_created_at', '>=', $period['start_at'])
            ->where('cla.interest_functional_created_at', '<', $period['end_at']);
        $query->where('cla.is_ambiguous', false)
            ->where(function ($query): void {
                $query->whereNull('cla.campaign_source_type')
                    ->orWhere('cla.campaign_source_type', '<>', 'excluded_campaign');
            });

        $this->applyLeadAttributionFilters($query, $filters, 'cla');

        $reportableOpportunity = $this->reportableOpportunitySql('so');

        return $query
            ->select([
                'cla.platform',
                DB::raw('NULL as account_id'),
                'cla.campaign_id',
                'cla.campaign_name',
                DB::raw('MIN(cla.campaign_source_type) as campaign_source_type'),
                DB::raw('MIN(cla.source_acquired) as source_acquired'),
                DB::raw('MIN(cla.medium_acquired) as medium_acquired'),
                DB::raw('MIN(cla.campaign_acquired) as campaign_acquired'),
                DB::raw('MIN(cla.source_campaign_name) as source_campaign_name'),
                DB::raw('MIN(cla.campaign_type) as campaign_type'),
                DB::raw('MIN(cla.acquired_id) as acquired_id'),
                DB::raw('MIN(cla.content_acquired) as content_acquired'),
                DB::raw("MIN(CASE WHEN cla.has_opportunity = 1 AND {$reportableOpportunity} THEN 'Cruzada por ID' ELSE 'Sin Interests Salesforce' END) as match_status"),
                DB::raw('COUNT(DISTINCT cla.interest_id) as leads_salesforce'),
                DB::raw("COUNT(DISTINCT CASE WHEN cla.has_opportunity = 1 AND {$reportableOpportunity} THEN cla.opportunity_id END) as opportunities"),
                DB::raw("COUNT(DISTINCT CASE WHEN cla.has_reservation = 1 AND {$reportableOpportunity} THEN cla.opportunity_id END) as reservations"),
                DB::raw("COUNT(DISTINCT CASE WHEN cla.has_reservation = 1 AND {$reportableOpportunity} AND (COALESCE(cla.campaign_type, '') = 'tasacion' OR cla.has_sale = 0) THEN cla.opportunity_id END) as live_reservations"),
                DB::raw("COUNT(DISTINCT CASE WHEN cla.has_reservation = 1 AND {$reportableOpportunity} AND COALESCE(cla.campaign_type, '') <> 'tasacion' AND cla.has_sale = 1 THEN cla.opportunity_id END) as fallen_reservations"),
                DB::raw("COUNT(DISTINCT CASE WHEN cla.has_sale = 1 AND {$reportableOpportunity} AND COALESCE(cla.campaign_type, '') <> 'tasacion' THEN cla.opportunity_id END) as sales"),
                DB::raw("SUM(CASE WHEN cla.has_sale = 1 AND {$reportableOpportunity} AND COALESCE(cla.campaign_type, '') <> 'tasacion' THEN COALESCE(cla.sold_amount, CASE WHEN COALESCE(so.opo_for_importe_total, 0) > 0 THEN so.opo_for_importe_total ELSE NULL END) ELSE 0 END) as sale_amount"),
                DB::raw("COUNT(DISTINCT CASE WHEN cla.has_sale = 1 AND {$reportableOpportunity} AND COALESCE(cla.campaign_type, '') <> 'tasacion' AND (cla.sold_amount IS NOT NULL OR COALESCE(so.opo_for_importe_total, 0) > 0) THEN cla.opportunity_id END) as sale_amount_rows"),
                DB::raw("COUNT(DISTINCT CASE WHEN cla.has_opportunity = 1 AND {$reportableOpportunity} AND cla.campaign_type = 'tasacion' THEN cla.opportunity_id END) as appraisals_generated"),
                DB::raw("COUNT(DISTINCT CASE WHEN cla.has_purchase = 1 AND {$reportableOpportunity} AND cla.campaign_type = 'tasacion' THEN cla.opportunity_id END) as purchases"),
                DB::raw("SUM(CASE WHEN cla.has_purchase = 1 AND {$reportableOpportunity} AND cla.campaign_type = 'tasacion' THEN ABS(COALESCE(so.opo_for_importe_total, 0)) ELSE 0 END) as appraisal_amount"),
                DB::raw("COUNT(DISTINCT CASE WHEN cla.has_purchase = 1 AND {$reportableOpportunity} AND cla.campaign_type = 'tasacion' AND so.opo_for_importe_total IS NOT NULL THEN cla.opportunity_id END) as appraisal_amount_rows"),
                DB::raw('MIN(cla.interest_status) as interest_status'),
                DB::raw('MIN(cla.interest_origin_delegation) as interest_origin_delegation'),
                DB::raw('MIN(cla.interest_origin_zone) as interest_origin_zone'),
                DB::raw('MIN(cla.commercial_user_id) as commercial_user_id'),
                DB::raw('MIN(cla.commercial_user_name) as commercial_user_name'),
                DB::raw('MIN(cla.vehicle_interest) as vehicle_interest'),
            ])
            ->groupBy('cla.platform', 'cla.campaign_id', 'cla.campaign_name')
            ->get()
            ->map(fn ($row) => $this->normalizeDashboardRow((array) $row))
            ->filter(fn (array $row): bool => $this->includeCampaignForContext($row, $filters))
            ->values()
            ->all();
    }

    private function mergeRows(array $metricRows, array $attributionRows, array $filters): array
    {
        $rows = [];
        $hasAttributionSpecificFilters = $this->hasAttributionSpecificFilters($filters);

        foreach ($metricRows as $row) {
            if ($hasAttributionSpecificFilters) {
                continue;
            }

            $key = $this->rowKey($row);
            $rows[$key] ??= $this->emptyCampaignRow($row);
            $rows[$key]['account_name'] = $row['account_name'];
            $rows[$key]['campaign_status'] = $row['campaign_status'] ?? null;
            $rows[$key]['campaign_effective_status'] = $row['campaign_effective_status'] ?? null;
            $rows[$key]['campaign_start_date'] = $row['campaign_start_date'] ?? null;
            $rows[$key]['campaign_end_date'] = $row['campaign_end_date'] ?? null;
            $rows[$key]['advertising_channel_type'] = $row['advertising_channel_type'] ?? null;
            $rows[$key]['advertising_channel_sub_type'] = $row['advertising_channel_sub_type'] ?? null;
            $rows[$key]['last_spend_date'] = $row['last_spend_date'] ?? null;
            $rows[$key]['campaign_status_label'] = $this->campaignStatusLabel($rows[$key]);
            $rows[$key]['campaign_type'] = $this->campaignType($rows[$key]);
            $rows[$key]['spend'] = round((float) $rows[$key]['spend'] + (float) $row['spend'], 2);
            $rows[$key]['impressions'] += (int) $row['impressions'];
            $rows[$key]['clicks'] += (int) $row['clicks'];

            if ((int) $row['platform_leads_rows'] > 0) {
                $rows[$key]['platform_leads'] = (int) ($rows[$key]['platform_leads'] ?? 0) + (int) $row['platform_leads'];
            }

            $rows[$key]['platform_conversions'] = (float) ($rows[$key]['platform_conversions'] ?? 0) + (float) $row['platform_conversions'];
            $rows[$key]['match_status'] = 'Sin Interests Salesforce';
            $rows[$key]['campaign_source_type'] = 'platform_campaign';
        }

        foreach ($attributionRows as $row) {
            $key = $this->rowKey($row);
            $rows[$key] ??= $this->emptyCampaignRow($row);

            $rows[$key]['source_acquired'] = $row['source_acquired'];
            $rows[$key]['medium_acquired'] = $row['medium_acquired'];
            $rows[$key]['campaign_acquired'] = $row['campaign_acquired'];
            $rows[$key]['source_campaign_name'] = $row['source_campaign_name'] ?? $row['campaign_acquired'];
            $rows[$key]['acquired_id'] = $row['acquired_id'] ?? null;
            $rows[$key]['content_acquired'] = $row['content_acquired'] ?? null;
            $rows[$key]['campaign_source_type'] = $this->deriveSourceType($row);
            $rows[$key]['match_status'] = $row['match_status'] ?: ($rows[$key]['campaign_source_type'] === 'salesforce_origin' ? 'Procedencia Salesforce' : 'Sin inversion asociada');
            $rows[$key]['campaign_type'] = $row['campaign_type'] ?? $this->campaignType($rows[$key]);
            $rows[$key]['leads_salesforce'] = (int) $row['leads_salesforce'];
            $rows[$key]['opportunities'] = (int) $row['opportunities'];
            if ($rows[$key]['campaign_source_type'] === 'salesforce_campaign_without_spend') {
                $rows[$key] = array_merge($rows[$key], $this->salesforceOnlyNotApplicableMetrics());

                continue;
            }

            $rows[$key]['reservations'] = (int) $row['reservations'];
            $rows[$key]['live_reservations'] = (int) $row['live_reservations'];
            $rows[$key]['fallen_reservations'] = (int) $row['fallen_reservations'];
            $rows[$key]['sales'] = (int) $row['sales'];
            $rows[$key]['sale_amount'] = (int) $row['sale_amount_rows'] > 0 ? (float) $row['sale_amount'] : null;
            $rows[$key]['appraisals_generated'] = (int) $row['appraisals_generated'];
            $rows[$key]['purchases'] = (int) $row['purchases'];
            $rows[$key]['appraisal_amount'] = (int) $row['appraisal_amount_rows'] > 0 ? (float) $row['appraisal_amount'] : null;
        }

        return array_values(array_map(fn (array $row) => $this->withDerivedState($this->withRatios($row)), $rows));
    }

    private function emptyCampaignRow(array $source): array
    {
        return [
            'platform' => $source['platform'] ?? 'unknown',
            'account_id' => $source['account_id'] ?? null,
            'account_name' => $source['account_name'] ?? null,
            'source_acquired' => $source['source_acquired'] ?? null,
            'medium_acquired' => $source['medium_acquired'] ?? null,
            'campaign_acquired' => $source['campaign_acquired'] ?? null,
            'source_campaign_name' => $source['source_campaign_name'] ?? ($source['campaign_acquired'] ?? null),
            'acquired_id' => $source['acquired_id'] ?? null,
            'content_acquired' => $source['content_acquired'] ?? null,
            'campaign_id' => $source['campaign_id'] ?? null,
            'campaign_name' => $source['campaign_name'] ?? null,
            'campaign_status' => $source['campaign_status'] ?? null,
            'campaign_effective_status' => $source['campaign_effective_status'] ?? null,
            'campaign_start_date' => $source['campaign_start_date'] ?? null,
            'campaign_end_date' => $source['campaign_end_date'] ?? null,
            'advertising_channel_type' => $source['advertising_channel_type'] ?? null,
            'advertising_channel_sub_type' => $source['advertising_channel_sub_type'] ?? null,
            'last_spend_date' => $source['last_spend_date'] ?? null,
            'campaign_type' => $this->campaignType($source),
            'campaign_status_label' => null,
            'match_status' => $source['match_status'] ?? null,
            'campaign_source_type' => $source['campaign_source_type'] ?? null,
            'spend' => 0.0,
            'impressions' => 0,
            'clicks' => 0,
            'platform_leads' => null,
            'platform_conversions' => null,
            'leads_salesforce' => 0,
            'opportunities' => 0,
            'reservations' => 0,
            'live_reservations' => 0,
            'fallen_reservations' => 0,
            'sales' => 0,
            'sale_amount' => null,
            'appraisals_generated' => 0,
            'purchases' => 0,
            'appraisal_amount' => null,
        ];
    }

    private function withRatios(array $row): array
    {
        // Compatibility: leads_salesforce remains part of the public payload,
        // but from ROT-4 onward its value is the number of Interests.
        $row['interests_salesforce'] = (int) ($row['leads_salesforce'] ?? 0);

        if ($this->deriveSourceType($row) === 'salesforce_campaign_without_spend') {
            return array_merge($row, $this->salesforceOnlyNotApplicableMetrics(), [
                'lead_to_opportunity' => $this->divide($row['opportunities'], $row['leads_salesforce']),
            ]);
        }

        $saleAmount = $row['sale_amount'];
        $spend = (float) $row['spend'];

        return array_merge($row, [
            'ctr' => $this->divide($row['clicks'], $row['impressions']),
            'cpc' => $this->divide($spend, $row['clicks']),
            'cost_per_lead' => $this->divide($spend, $row['leads_salesforce']),
            'cost_per_opportunity' => $this->divide($spend, $row['opportunities']),
            'cost_per_reservation' => $this->divide($spend, $row['reservations']),
            'cost_per_sale' => $this->divide($spend, $row['sales']),
            'roas' => $saleAmount !== null ? $this->divide($saleAmount, $spend) : null,
            'estimated_roi' => $saleAmount !== null ? $this->divide($saleAmount - $spend, $spend) : null,
            'cost_per_appraisal' => $this->divide($spend, $row['appraisals_generated'] ?? 0),
            'cost_per_purchase' => $this->divide($spend, $row['purchases'] ?? 0),
            'result_count' => $this->resultCountForRow($row),
            'cost_per_result' => $this->costPerResultForRow($row),
            'click_to_lead_salesforce' => $this->divide($row['leads_salesforce'], $row['clicks']),
            'click_to_lead_platform' => $row['platform_leads'] !== null ? $this->divide($row['platform_leads'], $row['clicks']) : null,
            'lead_to_opportunity' => $this->divide($row['opportunities'], $row['leads_salesforce']),
            'opportunity_to_reservation' => $this->divide($row['reservations'], $row['opportunities']),
            'reservation_to_sale' => $this->divide($row['sales'], $row['reservations']),
            'lead_to_sale' => $this->divide($row['sales'], $row['leads_salesforce']),
            'lead_to_purchase' => $this->divide($row['purchases'] ?? 0, $row['leads_salesforce']),
            'opportunity_to_purchase' => $this->divide($row['purchases'] ?? 0, $row['opportunities']),
        ]);
    }

    private function salesforceOnlyNotApplicableMetrics(): array
    {
        return array_fill_keys([
            'spend', 'impressions', 'clicks', 'platform_leads', 'platform_conversions',
            'reservations', 'live_reservations', 'fallen_reservations', 'sales', 'sale_amount',
            'appraisals_generated', 'purchases', 'appraisal_amount', 'ctr', 'cpc',
            'cost_per_lead', 'cost_per_opportunity', 'cost_per_reservation', 'cost_per_sale',
            'cost_per_appraisal', 'cost_per_purchase', 'roas', 'estimated_roi', 'result_count',
            'cost_per_result', 'click_to_lead_salesforce', 'click_to_lead_platform',
            'opportunity_to_reservation', 'reservation_to_sale', 'lead_to_sale',
            'lead_to_purchase', 'opportunity_to_purchase',
        ], null);
    }

    private function withDerivedState(array $row): array
    {
        $spend = (float) ($row['spend'] ?? 0);
        $leads = (int) ($row['leads_salesforce'] ?? 0);
        $status = $row['match_status'] ?? null;
        $sourceType = $this->deriveSourceType($row);

        if ($spend > 0 && $leads === 0) {
            $status = 'Sin Interests Salesforce';
        } elseif ($sourceType === 'salesforce_origin') {
            $status = 'Procedencia Salesforce';
        } elseif ($leads > 0 && $spend <= 0.0) {
            $status = 'Sin inversion asociada';
        } elseif ($status === null && $leads > 0) {
            $status = 'Cruzada';
        }

        $row['match_status'] = $status ?? 'Sin datos';
        $row['campaign_source_type'] = $sourceType;
        $row['campaign_source_type_label'] = $this->sourceTypeLabel($sourceType);
        $row['display_campaign'] = $this->displayCampaign($row);

        return $row;
    }

    private function deriveSourceType(array|object $row): string
    {
        $sourceType = data_get($row, 'campaign_source_type');

        if (in_array($sourceType, ['platform_campaign', 'salesforce_campaign_without_spend', 'salesforce_origin'], true)) {
            return $sourceType;
        }

        if ((string) data_get($row, 'platform') !== 'salesforce') {
            return 'platform_campaign';
        }

        if ($this->normalizer->isValidAttributionValue(data_get($row, 'campaign_acquired'))) {
            return 'salesforce_campaign_without_spend';
        }

        return 'salesforce_origin';
    }

    private function sourceTypeLabel(string $sourceType): string
    {
        return match ($sourceType) {
            'platform_campaign' => 'Campana plataforma',
            'salesforce_campaign_without_spend' => 'Campana Salesforce sin inversion',
            'salesforce_origin' => 'Procedencia Salesforce',
            default => 'Sin clasificar',
        };
    }

    private function includeCampaignForContext(array $row, array $filters): bool
    {
        if ($this->campaignTypeResolver->shouldExclude($row['source_campaign_name'] ?? ($row['campaign_name'] ?? null))) {
            return false;
        }

        $context = $filters['context'] ?? 'all';
        $rowType = $this->campaignType($row);

        if (in_array($context, ['all', 'todas', '', null], true)) {
            return true;
        }

        if ($context === 'venta') {
            return in_array($rowType, ['venta', 'exposicion', 'branding', 'otros'], true);
        }

        if ($context === 'ventas') {
            return $rowType === 'venta';
        }

        return $rowType === $context;
    }

    private function campaignType(array $row): string
    {
        if (in_array(($row['campaign_type'] ?? null), ['venta', 'tasacion', 'exposicion', 'branding', 'otros'], true)) {
            return (string) $row['campaign_type'];
        }

        $sourceCampaignName = $row['source_campaign_name'] ?? $row['campaign_acquired'] ?? null;

        if (filled($sourceCampaignName)) {
            $sourceType = $this->campaignTypeResolver->sourceCampaignType($sourceCampaignName);

            if ($sourceType !== null) {
                return $sourceType;
            }
        }

        return $this->campaignTypeResolver->typeFor(
            $row['platform'] ?? null,
            $row['campaign_id'] ?? null,
            $row['campaign_name'] ?? null,
        );
    }

    private function normalizeDashboardRow(array $row): array
    {
        $platform = $row['platform'] ?? null;
        $campaignName = $row['campaign_name'] ?? null;
        $sourceCampaignName = $row['source_campaign_name'] ?? ($row['campaign_acquired'] ?? null);

        if (
            $this->campaignTypeResolver->isMetaInstantFormsCampaign($platform, $campaignName)
            || $this->campaignTypeResolver->isMetaDirectFormCampaignName($sourceCampaignName)
        ) {
            $row['platform'] = 'meta';
            $row['campaign_id'] = $this->campaignTypeResolver->metaDirectFormCampaignId();
            $row['campaign_name'] = $this->campaignTypeResolver->metaDirectFormCampaignName();
            $row['source_campaign_name'] = $this->campaignTypeResolver->metaDirectFormCampaignName();
            $row['campaign_acquired'] = $this->campaignTypeResolver->metaDirectFormCampaignName();
            $row['campaign_type'] = 'venta';
        }

        return $row;
    }

    private function campaignStatusLabel(array $row): string
    {
        return $this->campaignIsActive($row) ? 'Activa' : 'Inactiva';
    }

    private function campaignIsActive(array $row): bool
    {
        $platform = (string) ($row['platform'] ?? '');
        $status = mb_strtoupper((string) ($row['campaign_status'] ?? ''));
        $effectiveStatus = mb_strtoupper((string) ($row['campaign_effective_status'] ?? ''));

        if ($status === '' && $effectiveStatus === '' && (int) ($row['leads_salesforce'] ?? 0) > 0) {
            return true;
        }

        return match ($platform) {
            'google_ads' => $status === 'ENABLED',
            'meta' => $effectiveStatus === 'ACTIVE',
            default => false,
        };
    }

    private function recordTypeSql(): string
    {
        return "LOWER(COALESCE(so.record_type_name, ''))";
    }

    private function isSaleAttributionRow(object $row): bool
    {
        return (bool) ($row->has_sale ?? false) && ($row->campaign_type ?? null) !== 'tasacion';
    }

    private function isPurchaseAttributionRow(object $row): bool
    {
        return (bool) ($row->has_purchase ?? false) && ($row->campaign_type ?? null) === 'tasacion';
    }

    private function resolveAuditMetric(?string $metric, ?string $context): string
    {
        $metric = $this->normalizer->key($metric);

        if (in_array($metric, [
            'leads_salesforce',
            'opportunities',
            'reservations',
            'live_reservations',
            'fallen_reservations',
            'sales',
            'appraisals_generated',
            'purchases',
            'result_count',
        ], true)) {
            return $metric;
        }

        return match ($this->normalizeContext($context)) {
            'venta' => 'sales',
            'tasacion' => 'purchases',
            'exposicion' => 'opportunities',
            'branding' => 'leads_salesforce',
            default => 'result_count',
        };
    }

    private function auditMetricLabel(string $metric): string
    {
        return match ($metric) {
            'leads_salesforce' => 'Intereses Salesforce',
            'opportunities' => 'Oportunidades',
            'reservations' => 'Reservas',
            'live_reservations' => 'Reservas vivas',
            'fallen_reservations' => 'Reservas caídas',
            'sales' => 'Ventas',
            'appraisals_generated' => 'Tasaciones generadas',
            'purchases' => 'Compras',
            default => 'Resultados',
        };
    }

    private function qualifiesAuditMetric(object $row, string $metric): bool
    {
        if ($metric !== 'leads_salesforce' && $this->isConfirmedDeletedOpportunityRow($row)) {
            return false;
        }

        return match ($metric) {
            'leads_salesforce' => filled($row->interest_id),
            'opportunities' => filled($row->opportunity_id) && (bool) $row->has_opportunity,
            'reservations' => filled($row->opportunity_id) && (bool) $row->has_reservation,
            'live_reservations' => filled($row->opportunity_id)
                && (bool) $row->has_reservation
                && (($row->campaign_type ?? null) === 'tasacion' || ! $this->isSaleAttributionRow($row)),
            'fallen_reservations' => filled($row->opportunity_id)
                && (bool) $row->has_reservation
                && ($row->campaign_type ?? null) !== 'tasacion'
                && $this->isSaleAttributionRow($row),
            'sales' => filled($row->opportunity_id) && $this->isSaleAttributionRow($row),
            'appraisals_generated' => filled($row->opportunity_id)
                && (bool) $row->has_opportunity
                && ($row->campaign_type ?? null) === 'tasacion',
            'purchases' => filled($row->opportunity_id) && $this->isPurchaseAttributionRow($row),
            'result_count' => filled($row->opportunity_id)
                && ($this->isSaleAttributionRow($row) || $this->isPurchaseAttributionRow($row)),
            default => false,
        };
    }

    private function auditEntityKey(object $row, string $metric): ?string
    {
        if ($metric === 'leads_salesforce') {
            return filled($row->interest_id) ? (string) $row->interest_id : null;
        }

        return filled($row->opportunity_id) ? (string) $row->opportunity_id : null;
    }

    private function emptyAuditRow(string $metric, string $entityId, int $firstAttributionId): array
    {
        return [
            'metric' => $metric,
            'metric_label' => $this->auditMetricLabel($metric),
            'entity_type' => $metric === 'leads_salesforce' ? 'interest' : 'opportunity',
            'entity_id' => $entityId,
            'first_attribution_id' => $firstAttributionId,
            'interest_ids' => [],
            'interest_functional_created_ats' => [],
            'interest_statuses' => [],
            'interest_types' => [],
            'interest_sources' => [],
            'interest_original_sources' => [],
            'interest_mediums' => [],
            'interest_channels' => [],
            'interest_utm_terms' => [],
            'interest_owner_ids' => [],
            'interest_owner_names' => [],
            'interest_lifecycle' => [],
            'interest_sync_run_ids' => [],
            'interest_sync_cutoffs' => [],
            'opportunity_relationship_statuses' => [],
            'opportunity_ids' => [],
            'opportunity_created_dates' => [],
            'opportunity_close_dates' => [],
            'cv_signed_dates' => [],
            'opportunity_record_types' => [],
            'opportunity_stages' => [],
            'opportunity_owner_ids' => [],
            'opportunity_owner_names' => [],
            'account_ids' => [],
            'opportunity_portals' => [],
            'opportunity_sources' => [],
            'platforms' => [],
            'campaign_ids' => [],
            'campaign_names' => [],
            'source_campaign_names' => [],
            'source_acquired_values' => [],
            'medium_acquired_values' => [],
            'campaign_acquired_values' => [],
            'acquired_ids' => [],
            'content_acquired_values' => [],
            'commercial_user_ids' => [],
            'commercial_user_names' => [],
            'interest_origin_delegations' => [],
            'interest_origin_zones' => [],
            'vehicle_interests' => [],
            'sale_amounts' => [],
            'purchase_amounts' => [],
            'metric_dates' => [],
        ];
    }

    private function accumulateAuditRow(array &$item, object $row): void
    {
        $this->pushAuditValue($item['interest_ids'], $row->interest_id);
        $this->pushAuditValue($item['interest_functional_created_ats'], $this->auditDate($row->interest_functional_created_at));
        $this->pushAuditValue($item['interest_statuses'], $row->interest_status);
        $this->pushAuditValue($item['interest_types'], $row->interest_type);
        $this->pushAuditValue($item['interest_sources'], $row->interest_source);
        $this->pushAuditValue($item['interest_original_sources'], $row->interest_original_source);
        $this->pushAuditValue($item['interest_mediums'], $row->interest_medium);
        $this->pushAuditValue($item['interest_channels'], $row->interest_channel);
        $this->pushAuditValue($item['interest_utm_terms'], $row->interest_utm_term);
        $this->pushAuditValue($item['interest_owner_ids'], $row->interest_owner_id);
        $this->pushAuditValue($item['interest_owner_names'], $row->interest_owner_name);
        $this->pushAuditValue($item['interest_lifecycle'], (bool) $row->interest_is_deleted ? 'deleted' : 'active');
        $this->pushAuditValue($item['interest_sync_run_ids'], $row->interest_sync_run_id);
        $this->pushAuditValue($item['interest_sync_cutoffs'], $row->interest_sync_cutoff_at);
        $this->pushAuditValue($item['opportunity_relationship_statuses'], $row->opportunity_relationship_status);

        $this->pushAuditValue($item['opportunity_ids'], $row->opportunity_id);
        $this->pushAuditValue($item['opportunity_created_dates'], $this->auditDate($row->opportunity_created_date));
        $this->pushAuditValue($item['opportunity_close_dates'], $this->auditDate($row->opportunity_close_date));
        $this->pushAuditValue($item['cv_signed_dates'], $this->auditDate($row->cv_signed_date));
        $this->pushAuditValue($item['opportunity_record_types'], $row->record_type_name);
        $this->pushAuditValue($item['opportunity_stages'], $row->stage_name);
        $this->pushAuditValue($item['opportunity_owner_ids'], $row->opportunity_owner_id);
        $this->pushAuditValue($item['opportunity_owner_names'], $row->opportunity_owner_name);
        $this->pushAuditValue($item['account_ids'], $row->account_id);
        $this->pushAuditValue($item['opportunity_portals'], $row->opportunity_portal_resolved ?: $row->opportunity_portal_original);
        $this->pushAuditValue($item['opportunity_sources'], $row->opportunity_source_normalized ?: $row->opportunity_source_raw);

        $this->pushAuditValue($item['platforms'], $row->platform);
        $this->pushAuditValue($item['campaign_ids'], $row->campaign_id);
        $this->pushAuditValue($item['campaign_names'], $row->campaign_name);
        $this->pushAuditValue($item['source_campaign_names'], $row->source_campaign_name);
        $this->pushAuditValue($item['source_acquired_values'], $row->source_acquired);
        $this->pushAuditValue($item['medium_acquired_values'], $row->medium_acquired);
        $this->pushAuditValue($item['campaign_acquired_values'], $row->campaign_acquired);
        $this->pushAuditValue($item['acquired_ids'], $row->acquired_id);
        $this->pushAuditValue($item['content_acquired_values'], $row->content_acquired);
        $this->pushAuditValue($item['commercial_user_ids'], $row->commercial_user_id);
        $this->pushAuditValue($item['commercial_user_names'], $row->commercial_user_name);
        $this->pushAuditValue($item['interest_origin_delegations'], $row->interest_origin_delegation);
        $this->pushAuditValue($item['interest_origin_zones'], $row->interest_origin_zone);
        $this->pushAuditValue($item['vehicle_interests'], $row->vehicle_interest);

        if ($this->isSaleAttributionRow($row)) {
            $saleAmount = $row->sold_amount !== null
                ? (float) $row->sold_amount
                : ((float) ($row->opo_for_importe_total ?? 0) > 0 ? (float) $row->opo_for_importe_total : null);

            if ($saleAmount !== null) {
                $this->pushAuditValue($item['sale_amounts'], round($saleAmount, 2));
            }
        }

        if ($this->isPurchaseAttributionRow($row)) {
            $purchaseAmount = abs((float) ($row->opo_for_importe_total ?? 0));

            if ($purchaseAmount > 0) {
                $this->pushAuditValue($item['purchase_amounts'], round($purchaseAmount, 2));
            }
        }

        $metricDate = $this->auditMetricDateForRow($row);

        if ($metricDate !== null) {
            $this->pushAuditValue($item['metric_dates'], $metricDate);
        }
    }

    private function finalizeAuditRow(array $item): array
    {
        sort($item['interest_ids']);
        sort($item['campaign_ids']);
        sort($item['campaign_names']);
        sort($item['source_campaign_names']);
        sort($item['metric_dates']);

        return array_merge($item, [
            'metric_date' => $item['metric_dates'][0] ?? ($item['interest_functional_created_ats'][0] ?? null),
            'interest_id' => $this->singleAuditValue($item['interest_ids']),
            'opportunity_id' => $this->singleAuditValue($item['opportunity_ids']),
            'campaign_id' => $this->singleAuditValue($item['campaign_ids']),
            'campaign_name' => $this->singleAuditValue($item['campaign_names']),
            'source_campaign_name' => $this->singleAuditValue($item['source_campaign_names']),
            'source_acquired' => $this->singleAuditValue($item['source_acquired_values']),
            'medium_acquired' => $this->singleAuditValue($item['medium_acquired_values']),
            'campaign_acquired' => $this->singleAuditValue($item['campaign_acquired_values']),
            'portal' => $this->singleAuditValue($item['opportunity_portals']),
            'managed_by' => $this->singleAuditValue($item['commercial_user_names']) ?? $this->singleAuditValue($item['opportunity_owner_names']),
            'sale_amount' => $item['sale_amounts'] !== [] ? round(array_sum($item['sale_amounts']), 2) : null,
            'purchase_amount' => $item['purchase_amounts'] !== [] ? round(array_sum($item['purchase_amounts']), 2) : null,
        ]);
    }

    private function pushAuditValue(array &$values, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        $normalized = is_string($value) ? trim($value) : $value;

        if ($normalized === '') {
            return;
        }

        if (! in_array($normalized, $values, true)) {
            $values[] = $normalized;
        }
    }

    private function singleAuditValue(array $values): mixed
    {
        return count($values) === 1 ? $values[0] : null;
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

    private function auditMetricDateForRow(object $row): ?string
    {
        return $this->auditDate($row->cv_signed_date)
            ?? $this->auditDate($row->opportunity_created_date)
            ?? $this->auditDate($row->interest_functional_created_at);
    }

    private function closedLostSql(): string
    {
        return "LOWER(COALESCE(so.stage_name, '')) = 'cerrada perdida'";
    }

    private function reportableOpportunitySql(?string $alias = null): string
    {
        $prefix = $alias === null ? '' : $alias.'.';
        $confirmedSource = SalesforceOpportunity::DELETION_SOURCE_QUERY_ALL;

        return "({$prefix}is_deleted = 0 OR {$prefix}deletion_detection_source IS NULL OR {$prefix}deletion_detection_source <> '{$confirmedSource}')";
    }

    private function isConfirmedDeletedOpportunityRow(object $row): bool
    {
        return (bool) ($row->opportunity_is_deleted ?? false)
            && ($row->opportunity_deletion_source ?? null) === SalesforceOpportunity::DELETION_SOURCE_QUERY_ALL;
    }

    private function ventaOpportunitySql(): string
    {
        $recordType = $this->recordTypeSql();

        return "((so.salesforce_id IS NULL AND ca.has_opportunity = 1) OR (($recordType LIKE '%venta%') OR ($recordType LIKE '%cambio%' AND COALESCE(so.opo_for_importe_total, 0) > 0)))";
    }

    private function tasacionOpportunitySql(): string
    {
        $recordType = $this->recordTypeSql();

        return "(($recordType LIKE '%tasaci%') OR ($recordType LIKE '%cambio%' AND COALESCE(so.opo_for_importe_total, 0) < 0))";
    }

    private function ventaSignedSql(): string
    {
        $legacySigned = '(so.salesforce_id IS NULL AND ca.has_sale = 1)';
        $opportunitySigned = "(so.cv_signed = 1 AND NOT ({$this->closedLostSql()}) AND {$this->ventaOpportunitySql()})";

        return "({$legacySigned} OR {$opportunitySigned})";
    }

    private function tasacionSignedSql(): string
    {
        return "(so.cv_signed = 1 AND NOT ({$this->closedLostSql()}) AND {$this->tasacionOpportunitySql()})";
    }

    private function displayCampaign(array $row): string
    {
        if (($row['campaign_source_type'] ?? null) === 'salesforce_origin') {
            return implode(' · ', array_filter([
                $row['source_acquired'] ?? null,
                $row['medium_acquired'] ?? null,
            ])) ?: ($row['campaign_name'] ?? '-');
        }

        return $row['campaign_name'] ?: ($row['campaign_acquired'] ?: ($row['campaign_id'] ?: '-'));
    }

    private function mainCampaignRows(array $rows, array $filters): array
    {
        $rows = array_values(array_filter($rows, fn (array $row): bool => $this->includeCampaignForContext($row, $filters)));

        if (filled($filters['campaign_status'] ?? null)) {
            $rows = array_values(array_filter($rows, function (array $row) use ($filters): bool {
                return $filters['campaign_status'] === 'active'
                    ? $this->campaignIsActive($row)
                    : ! $this->campaignIsActive($row);
            }));
        }

        if (filled($filters['classification'] ?? null)) {
            $rows = array_values(array_filter($rows, fn (array $row): bool => ($row['classification'] ?? null) === $filters['classification']));
        }

        return $rows;
    }

    private function platformRows(array $rows): array
    {
        return array_values(array_filter($rows, fn (array $row): bool => in_array(($row['platform'] ?? null), ['google_ads', 'meta'], true)));
    }

    private function summaryFromRows(array $rows, array $allRows, array $filters, array $period, array $attributionAnalytics, bool $includeDiagnostics = true, bool $includeFilters = true): array
    {
        $attributionTotals = $attributionAnalytics['totals'];
        $totals = $this->withRatios([
            'platform' => null,
            'account_id' => null,
            'account_name' => null,
            'source_acquired' => null,
            'medium_acquired' => null,
            'campaign_acquired' => null,
            'campaign_id' => null,
            'campaign_name' => null,
            'spend' => array_sum(array_column($rows, 'spend')),
            'impressions' => array_sum(array_column($rows, 'impressions')),
            'clicks' => array_sum(array_column($rows, 'clicks')),
            'platform_leads' => collect($rows)->contains(fn (array $row) => $row['platform_leads'] !== null)
                ? array_sum(array_map(fn (array $row) => (int) ($row['platform_leads'] ?? 0), $rows))
                : null,
            'platform_conversions' => array_sum(array_column($rows, 'platform_conversions')),
            'leads_salesforce' => $attributionTotals['leads_salesforce'],
            'opportunities' => $attributionTotals['opportunities'],
            'reservations' => $attributionTotals['reservations'],
            'live_reservations' => $attributionTotals['live_reservations'],
            'fallen_reservations' => $attributionTotals['fallen_reservations'],
            'sales' => $attributionTotals['sales'],
            'sale_amount' => $attributionTotals['sale_amount'],
            'appraisals_generated' => $attributionTotals['appraisals_generated'],
            'purchases' => $attributionTotals['purchases'],
            'appraisal_amount' => $attributionTotals['appraisal_amount'],
        ]);
        $totals['result_count'] = $this->resultCountForTotals($totals, $filters['context'] ?? 'all');
        $totals['cost_per_result'] = $this->divide((float) $totals['spend'], (int) $totals['result_count']);
        $totals['interests_salesforce'] = (int) $totals['leads_salesforce'];
        $totals['lead_attribution_appearances'] = array_sum(array_map(
            static fn (array $row): int => (int) ($row['leads_salesforce'] ?? 0),
            $rows
        ));
        $totals['lead_attribution_overlap'] = max(
            $totals['lead_attribution_appearances'] - (int) $totals['leads_salesforce'],
            0
        );
        $charts = $this->charts($rows, $filters, $period, $totals, $attributionAnalytics);
        $publicWarnings = $this->publicWarnings($totals);
        $datasetMetadata = $this->campaignDatasetMetadata($period);

        return [
            'ok' => count($rows) > 0,
            'selected_context' => $filters['context'],
            'selected_context_label' => $this->contextLabel($filters['context']),
            'period_mode' => 'interest_pivot',
            'periodo_actual' => [
                'inicio' => $period['start'],
                'fin' => $period['end'],
            ],
            'datos_actualizados' => $datasetMetadata['dataset_cutoff_at'],
            'salesforce_interests_synced_at' => $datasetMetadata['salesforce_interests_synced_at'],
            'salesforce_leads_synced_at' => $datasetMetadata['salesforce_leads_synced_at'],
            'meta_synced_at' => $datasetMetadata['meta_synced_at'],
            'google_synced_at' => $datasetMetadata['google_synced_at'],
            'attribution_built_at' => $datasetMetadata['attribution_built_at'],
            'dataset_generated_at' => $datasetMetadata['dataset_generated_at'],
            'dataset_cutoff_at' => $datasetMetadata['dataset_cutoff_at'],
            'dataset_timezone' => self::REPORT_TIMEZONE,
            'kpis' => $totals,
            'warnings' => $this->warnings($rows, $totals, $period, $filters),
            'public_warnings' => $publicWarnings,
            'charts' => $charts,
            'daily_investment_leads' => $charts['daily_evolution'],
            'daily_results' => $charts['daily_reservations_sales'],
            'platform_comparison' => $charts['platforms'],
            'review_campaigns' => $this->reviewCampaigns($rows, $filters),
            'source_reconciliation' => $this->sourceReconciliation($filters, $period),
            'diagnostics' => $includeDiagnostics ? $this->diagnostics($allRows, $period, $filters) : [],
            'filters' => $includeFilters ? $this->filterOptionsCached() : [],
        ];
    }

    private function rankingsFromRows(array $rows, array $filters): array
    {
        $paidSaleRows = $this->rowsWithPositiveResultAndSpend($rows, 'sales', 'cost_per_sale');
        $paidPurchaseRows = $this->rowsWithPositiveResultAndSpend($rows, 'purchases', 'cost_per_purchase');

        return [
            'top_spend' => $this->top(array_values(array_filter($rows, fn (array $row): bool => (float) ($row['spend'] ?? 0) > 0)), 'spend'),
            'top_impressions' => $this->top($rows, 'impressions'),
            'top_leads_salesforce' => $this->top($rows, 'leads_salesforce'),
            'top_opportunities' => $this->top($rows, 'opportunities'),
            'top_reservations' => $this->top($rows, 'reservations'),
            'top_sales' => $this->top($rows, 'sales'),
            'top_purchases' => $this->top($rows, 'purchases'),
            'top_sale_amount' => $this->top($rows, 'sale_amount', requireValue: true),
            'best_roas' => $this->top($rows, 'roas', requireValue: true),
            'best_ctr' => $this->top($rows, 'ctr', requireValue: true),
            'best_cpc' => $this->top($rows, 'cpc', ascending: true, requireValue: true),
            'best_cost_per_sale' => $this->top($paidSaleRows, 'cost_per_sale', ascending: true, requireValue: true),
            'best_cost_per_purchase' => $this->top($paidPurchaseRows, 'cost_per_purchase', ascending: true, requireValue: true),
            'best_cost_per_lead' => $this->top($rows, 'cost_per_lead', ascending: true, requireValue: true),
            'best_cost_per_opportunity' => $this->top($rows, 'cost_per_opportunity', ascending: true, requireValue: true),
            'best_cost_per_result' => $this->top($rows, 'cost_per_result', ascending: true, requireValue: true),
            'best_lead_to_purchase' => $this->top($rows, 'lead_to_purchase', requireValue: true),
            'worst_cost_per_sale' => $this->top($paidSaleRows, 'cost_per_sale', requireValue: true),
            'high_spend_low_conversion' => collect($rows)->sortByDesc('spend')->sortBy('sales')->take(5)->values()->all(),
            'many_leads_few_sales' => collect($rows)->sortByDesc('leads_salesforce')->sortBy('sales')->take(5)->values()->all(),
            'many_leads_few_purchases' => collect($rows)->sortByDesc('leads_salesforce')->sortBy('purchases')->take(5)->values()->all(),
            'review_campaigns' => $this->reviewCampaigns($rows, $filters),
            'review_tracking' => $this->classification($rows, 'Revisar tracking'),
            'boost' => $this->classification($rows, 'Potenciar'),
            'review' => $this->classification($rows, 'Revisar'),
            'stop' => $this->classification($rows, 'Parar'),
        ];
    }

    private function rowsWithPositiveResultAndSpend(array $rows, string $resultKey, string $metricKey): array
    {
        return array_values(array_filter($rows, function (array $row) use ($resultKey, $metricKey): bool {
            return (float) ($row['spend'] ?? 0) > 0
                && (int) ($row[$resultKey] ?? 0) > 0
                && ($row[$metricKey] ?? null) !== null
                && (float) ($row[$metricKey] ?? 0) > 0;
        }));
    }

    private function charts(array $rows, array $filters, array $period, array $totals, array $attributionAnalytics): array
    {
        return [
            'monthly_evolution' => $attributionAnalytics['monthly_evolution'],
            'daily_evolution' => $attributionAnalytics['daily_evolution'],
            'daily_reservations_sales' => $attributionAnalytics['daily_reservations_sales'],
            'funnel' => $this->funnelChart($totals, $filters['context'] ?? 'venta'),
            'platforms' => $this->platformBars($rows),
        ];
    }

    private function attributionAnalytics(array $rows, array $filters, array $period): array
    {
        $visibleRowKeys = array_fill_keys(array_map(fn (array $row): string => $this->rowKey($row), $rows), true);
        $monthlyEvolution = $this->emptyMonthlyEvolution($period);
        $dailyLeadCounts = [];
        $dailyLeadKeys = [];
        $dailyReservationCounts = [];
        $dailyReservationKeys = [];
        $dailySalesCounts = [];
        $dailySalesKeys = [];
        $dailyPurchaseCounts = [];
        $dailyPurchaseKeys = [];
        $monthlyLeadKeys = [];
        $monthlyOpportunityKeys = [];
        $monthlyReservationKeys = [];
        $monthlySaleKeys = [];
        $monthlyAppraisalKeys = [];
        $monthlyPurchaseKeys = [];
        $leadIds = [];
        $opportunities = [];

        if ($visibleRowKeys !== []) {
            $monthlyStart = CarbonImmutable::parse($period['end_local'])->subMonthsNoOverflow(23)->startOfMonth()->startOfDay();

            $query = DB::table('campaign_lead_attributions as cla')
                ->leftJoin('salesforce_opportunities as so', 'so.salesforce_id', '=', 'cla.opportunity_id')
                ->where('cla.interest_functional_created_at', '>=', $monthlyStart->utc())
                ->where('cla.interest_functional_created_at', '<', $period['end_at']);

            $this->applyLeadAttributionFilters($query, $filters, 'cla');

            if (filled($filters['campaign_source_type'] ?? null)) {
                if ($filters['campaign_source_type'] === 'platform_campaign') {
                    $query->where('cla.platform', '<>', 'salesforce');
                } else {
                    $query->where('cla.platform', 'salesforce');
                }
            }

            $this->applyCampaignContextFilter($query, $filters, 'cla');

            $query
                ->select([
                    'cla.id',
                    'cla.interest_functional_created_at',
                    'cla.platform',
                    'cla.campaign_id',
                    'cla.campaign_name',
                    'cla.source_campaign_name',
                    'cla.campaign_acquired',
                    'cla.campaign_type',
                    'cla.interest_id',
                    'cla.opportunity_id',
                    'cla.has_opportunity',
                    'cla.has_reservation',
                    'cla.has_sale',
                    'cla.has_purchase',
                    'cla.sold_amount',
                    'so.opo_for_importe_total',
                    'so.is_deleted as opportunity_is_deleted',
                    'so.deletion_detection_source as opportunity_deletion_source',
                ])
                ->orderBy('cla.id')
                ->chunkById(1000, function ($chunk) use (
                    $period,
                    $visibleRowKeys,
                    &$monthlyEvolution,
                    &$dailyLeadCounts,
                    &$dailyLeadKeys,
                    &$dailyReservationCounts,
                    &$dailyReservationKeys,
                    &$dailySalesCounts,
                    &$dailySalesKeys,
                    &$dailyPurchaseCounts,
                    &$dailyPurchaseKeys,
                    &$monthlyLeadKeys,
                    &$monthlyOpportunityKeys,
                    &$monthlyReservationKeys,
                    &$monthlySaleKeys,
                    &$monthlyAppraisalKeys,
                    &$monthlyPurchaseKeys,
                    &$leadIds,
                    &$opportunities,
                ): void {
                    foreach ($chunk as $row) {
                        $identity = $this->rowKey($this->attributionIdentityRow($row));

                        if (! isset($visibleRowKeys[$identity])) {
                            continue;
                        }

                        $leadCreatedAt = $this->reportDateTime($row->interest_functional_created_at);

                        if ($leadCreatedAt === null) {
                            continue;
                        }

                        $monthKey = $leadCreatedAt->format('Y-m');

                        if (isset($monthlyEvolution[$monthKey])) {
                            $this->accumulateMonthlyAttributionRow(
                                $monthlyEvolution[$monthKey],
                                $row,
                                $monthKey,
                                $monthlyLeadKeys,
                                $monthlyOpportunityKeys,
                                $monthlyReservationKeys,
                                $monthlySaleKeys,
                                $monthlyAppraisalKeys,
                                $monthlyPurchaseKeys,
                            );
                        }

                        $metricDate = $leadCreatedAt->toDateString();

                        if ($metricDate < $period['start'] || $metricDate > $period['end']) {
                            continue;
                        }

                        $this->accumulateDailyAndTotalAttributionRow(
                            $row,
                            $metricDate,
                            $leadIds,
                            $opportunities,
                            $dailyLeadCounts,
                            $dailyLeadKeys,
                            $dailyReservationCounts,
                            $dailyReservationKeys,
                            $dailySalesCounts,
                            $dailySalesKeys,
                            $dailyPurchaseCounts,
                            $dailyPurchaseKeys,
                        );
                    }
                }, 'cla.id', 'id');
        }

        return [
            'totals' => $this->finalizeAttributionTotals($leadIds, $opportunities),
            'daily_evolution' => $this->buildDailyLeadEvolution($filters, $period, $dailyLeadCounts),
            'daily_reservations_sales' => $this->buildDailyReservationSales($period, $dailyReservationCounts, $dailySalesCounts, $dailyPurchaseCounts),
            'monthly_evolution' => $this->buildMonthlyEvolution($monthlyEvolution, $filters, $period),
        ];
    }

    private function emptyMonthlyEvolution(array $period): array
    {
        $start = CarbonImmutable::parse($period['end_local'])->subMonthsNoOverflow(23)->startOfMonth()->startOfDay();
        $end = CarbonImmutable::parse($period['end_local'])->endOfDay();
        $months = [];
        $cursor = $start->startOfMonth();

        while ($cursor->lessThanOrEqualTo($end)) {
            $key = $cursor->format('Y-m');
            $months[$key] = [
                'date' => $cursor->toDateString(),
                'label' => ucfirst($cursor->locale('es')->translatedFormat('F')),
                'spend' => 0.0,
                'impressions' => 0,
                'clicks' => 0,
                'leads_salesforce' => 0,
                'opportunities' => 0,
                'reservations' => 0,
                'sales' => 0,
                'appraisals_generated' => 0,
                'purchases' => 0,
            ];
            $cursor = $cursor->addMonthNoOverflow();
        }

        return $months;
    }

    private function accumulateMonthlyAttributionRow(
        array &$month,
        object $row,
        string $monthKey,
        array &$leadKeys,
        array &$opportunityKeys,
        array &$reservationKeys,
        array &$saleKeys,
        array &$appraisalKeys,
        array &$purchaseKeys,
    ): void {
        if (filled($row->interest_id)) {
            $leadKey = $monthKey.'|'.$row->interest_id;

            if (! isset($leadKeys[$leadKey])) {
                $month['leads_salesforce']++;
                $leadKeys[$leadKey] = true;
            }
        }

        if ($this->isConfirmedDeletedOpportunityRow($row)) {
            return;
        }

        if (! filled($row->opportunity_id)) {
            return;
        }

        $opportunityId = (string) $row->opportunity_id;

        if ((bool) $row->has_opportunity) {
            $opportunityKey = $monthKey.'|'.$opportunityId;

            if (! isset($opportunityKeys[$opportunityKey])) {
                $month['opportunities']++;
                $opportunityKeys[$opportunityKey] = true;
            }
        }

        if ($row->platform === 'salesforce') {
            return;
        }

        if ((bool) $row->has_reservation) {
            $reservationKey = $monthKey.'|'.$opportunityId;

            if (! isset($reservationKeys[$reservationKey])) {
                $month['reservations']++;
                $reservationKeys[$reservationKey] = true;
            }
        }

        if ($this->isSaleAttributionRow($row)) {
            $saleKey = $monthKey.'|'.$opportunityId;

            if (! isset($saleKeys[$saleKey])) {
                $month['sales']++;
                $saleKeys[$saleKey] = true;
            }
        }

        if ((bool) $row->has_opportunity && $row->campaign_type === 'tasacion') {
            $appraisalKey = $monthKey.'|'.$opportunityId;

            if (! isset($appraisalKeys[$appraisalKey])) {
                $month['appraisals_generated']++;
                $appraisalKeys[$appraisalKey] = true;
            }
        }

        if ($this->isPurchaseAttributionRow($row)) {
            $purchaseKey = $monthKey.'|'.$opportunityId;

            if (! isset($purchaseKeys[$purchaseKey])) {
                $month['purchases']++;
                $purchaseKeys[$purchaseKey] = true;
            }
        }
    }

    private function accumulateDailyAndTotalAttributionRow(
        object $row,
        string $metricDate,
        array &$leadIds,
        array &$opportunities,
        array &$dailyLeadCounts,
        array &$dailyLeadKeys,
        array &$dailyReservationCounts,
        array &$dailyReservationKeys,
        array &$dailySalesCounts,
        array &$dailySalesKeys,
        array &$dailyPurchaseCounts,
        array &$dailyPurchaseKeys,
    ): void {
        if (filled($row->interest_id)) {
            $leadId = (string) $row->interest_id;
            $leadIds[$leadId] = true;
            $dailyLeadKey = $metricDate.'|'.$leadId;

            if (! isset($dailyLeadKeys[$dailyLeadKey])) {
                $dailyLeadCounts[$metricDate] = ($dailyLeadCounts[$metricDate] ?? 0) + 1;
                $dailyLeadKeys[$dailyLeadKey] = true;
            }
        }

        if ($this->isConfirmedDeletedOpportunityRow($row)) {
            return;
        }

        if (! filled($row->opportunity_id)) {
            return;
        }

        $opportunityId = (string) $row->opportunity_id;
        $isSale = $this->isSaleAttributionRow($row);
        $isTasacion = ($row->campaign_type ?? null) === 'tasacion';
        $saleAmount = null;

        if ($isSale) {
            $fallbackAmount = (float) ($row->opo_for_importe_total ?? 0);
            $saleAmount = $row->sold_amount !== null
                ? (float) $row->sold_amount
                : ($fallbackAmount > 0 ? $fallbackAmount : null);
        }

        $purchaseAmount = $this->isPurchaseAttributionRow($row)
            ? abs((float) ($row->opo_for_importe_total ?? 0))
            : null;

        $opportunities[$opportunityId] ??= [
            'has_opportunity' => false,
            'has_reservation' => false,
            'has_live_reservation' => false,
            'has_fallen_reservation' => false,
            'has_sale' => false,
            'has_appraisal_generated' => false,
            'has_purchase' => false,
            'sale_amount' => null,
            'appraisal_amount' => null,
        ];

        $opportunities[$opportunityId]['has_opportunity'] = $opportunities[$opportunityId]['has_opportunity'] || (bool) $row->has_opportunity;

        if ($row->platform === 'salesforce') {
            return;
        }
        $opportunities[$opportunityId]['has_reservation'] = $opportunities[$opportunityId]['has_reservation'] || (bool) $row->has_reservation;
        $opportunities[$opportunityId]['has_live_reservation'] = $opportunities[$opportunityId]['has_live_reservation']
            || ((bool) $row->has_reservation && ($isTasacion || ! $isSale));
        $opportunities[$opportunityId]['has_fallen_reservation'] = $opportunities[$opportunityId]['has_fallen_reservation']
            || ((bool) $row->has_reservation && ! $isTasacion && $isSale);
        $opportunities[$opportunityId]['has_sale'] = $opportunities[$opportunityId]['has_sale'] || $isSale;
        $opportunities[$opportunityId]['has_appraisal_generated'] = $opportunities[$opportunityId]['has_appraisal_generated']
            || ((bool) $row->has_opportunity && $isTasacion);
        $opportunities[$opportunityId]['has_purchase'] = $opportunities[$opportunityId]['has_purchase'] || $this->isPurchaseAttributionRow($row);

        if ($saleAmount !== null && $opportunities[$opportunityId]['sale_amount'] === null) {
            $opportunities[$opportunityId]['sale_amount'] = $saleAmount;
        }

        if ($purchaseAmount !== null && $opportunities[$opportunityId]['appraisal_amount'] === null) {
            $opportunities[$opportunityId]['appraisal_amount'] = $purchaseAmount;
        }

        if ((bool) $row->has_reservation) {
            $reservationKey = $metricDate.'|'.$opportunityId;

            if (! isset($dailyReservationKeys[$reservationKey])) {
                $dailyReservationCounts[$metricDate] = ($dailyReservationCounts[$metricDate] ?? 0) + 1;
                $dailyReservationKeys[$reservationKey] = true;
            }
        }

        if ($isSale) {
            $saleKey = $metricDate.'|'.$opportunityId;

            if (! isset($dailySalesKeys[$saleKey])) {
                $dailySalesCounts[$metricDate] = ($dailySalesCounts[$metricDate] ?? 0) + 1;
                $dailySalesKeys[$saleKey] = true;
            }
        }

        if ($this->isPurchaseAttributionRow($row)) {
            $purchaseKey = $metricDate.'|'.$opportunityId;

            if (! isset($dailyPurchaseKeys[$purchaseKey])) {
                $dailyPurchaseCounts[$metricDate] = ($dailyPurchaseCounts[$metricDate] ?? 0) + 1;
                $dailyPurchaseKeys[$purchaseKey] = true;
            }
        }
    }

    private function finalizeAttributionTotals(array $leadIds, array $opportunities): array
    {
        $saleAmounts = array_values(array_filter(array_map(
            static fn (array $row): ?float => $row['has_sale'] && $row['sale_amount'] !== null ? (float) $row['sale_amount'] : null,
            $opportunities
        ), static fn (?float $value): bool => $value !== null));
        $appraisalAmounts = array_values(array_filter(array_map(
            static fn (array $row): ?float => $row['has_purchase'] && $row['appraisal_amount'] !== null ? (float) $row['appraisal_amount'] : null,
            $opportunities
        ), static fn (?float $value): bool => $value !== null));

        return [
            'leads_salesforce' => count($leadIds),
            'opportunities' => count(array_filter($opportunities, static fn (array $row): bool => $row['has_opportunity'])),
            'reservations' => count(array_filter($opportunities, static fn (array $row): bool => $row['has_reservation'])),
            'live_reservations' => count(array_filter($opportunities, static fn (array $row): bool => $row['has_live_reservation'])),
            'fallen_reservations' => count(array_filter($opportunities, static fn (array $row): bool => $row['has_fallen_reservation'])),
            'sales' => count(array_filter($opportunities, static fn (array $row): bool => $row['has_sale'])),
            'sale_amount' => $saleAmounts !== [] ? round(array_sum($saleAmounts), 2) : null,
            'appraisals_generated' => count(array_filter($opportunities, static fn (array $row): bool => $row['has_appraisal_generated'])),
            'purchases' => count(array_filter($opportunities, static fn (array $row): bool => $row['has_purchase'])),
            'appraisal_amount' => $appraisalAmounts !== [] ? round(array_sum($appraisalAmounts), 2) : null,
        ];
    }

    private function buildDailyLeadEvolution(array $filters, array $period, array $dailyLeadCounts): array
    {
        if (filled($filters['campaign_source_type'] ?? null) && $filters['campaign_source_type'] !== 'platform_campaign') {
            $spendByDate = [];
        } else {
            $metricQuery = DB::table('campaign_platform_daily_metrics')
                ->where('metric_date', '>=', $period['start'])
                ->where('metric_date', '<=', $period['end']);
            $this->applyMetricFilters($metricQuery, array_merge($filters, ['campaign_source_type' => 'platform_campaign']));

            $spendByDate = $metricQuery
                ->select('metric_date', DB::raw('SUM(COALESCE(spend, 0)) as spend'))
                ->groupBy('metric_date')
                ->pluck('spend', 'metric_date');
        }

        $rows = [];
        $cursor = CarbonImmutable::parse($period['start'])->startOfDay();
        $end = CarbonImmutable::parse($period['end'])->startOfDay();

        while ($cursor->lessThanOrEqualTo($end)) {
            $date = $cursor->toDateString();
            $rows[] = [
                'date' => $date,
                'spend' => round((float) ($spendByDate[$date] ?? 0), 2),
                'leads_salesforce' => (int) ($dailyLeadCounts[$date] ?? 0),
            ];
            $cursor = $cursor->addDay();
        }

        return $rows;
    }

    private function buildMonthlyEvolution(array $monthlyEvolution, array $filters, array $period): array
    {
        if (filled($filters['campaign_source_type'] ?? null) && $filters['campaign_source_type'] !== 'platform_campaign') {
            return array_values($monthlyEvolution);
        }

        $start = CarbonImmutable::parse($period['end_local'])->subMonthsNoOverflow(23)->startOfMonth()->startOfDay();
        $end = CarbonImmutable::parse($period['end_local'])->endOfDay();

        $metricQuery = DB::table('campaign_platform_daily_metrics')
            ->where('metric_date', '>=', $start->toDateString())
            ->where('metric_date', '<=', $end->toDateString());
        $this->applyMetricFilters($metricQuery, array_merge($filters, ['campaign_source_type' => 'platform_campaign']));

        $metricQuery
            ->select([
                DB::raw('SUBSTR(metric_date, 1, 7) as metric_month'),
                'platform',
                'campaign_id',
                'campaign_name',
                DB::raw('SUM(COALESCE(spend, 0)) as spend'),
                DB::raw('SUM(COALESCE(impressions, 0)) as impressions'),
                DB::raw('SUM(COALESCE(clicks, 0)) as clicks'),
            ])
            ->groupBy(DB::raw('SUBSTR(metric_date, 1, 7)'), 'platform', 'campaign_id', 'campaign_name')
            ->get()
            ->each(function (object $row) use (&$monthlyEvolution, $filters): void {
                $campaign = (array) $row;

                if (! $this->includeCampaignForContext($campaign, $filters)) {
                    return;
                }

                $month = (string) $row->metric_month;

                if (! isset($monthlyEvolution[$month])) {
                    return;
                }

                $monthlyEvolution[$month]['spend'] = round($monthlyEvolution[$month]['spend'] + (float) $row->spend, 2);
                $monthlyEvolution[$month]['impressions'] += (int) $row->impressions;
                $monthlyEvolution[$month]['clicks'] += (int) $row->clicks;
            });

        return array_values($monthlyEvolution);
    }

    private function buildDailyReservationSales(array $period, array $dailyReservationCounts, array $dailySalesCounts, array $dailyPurchaseCounts): array
    {
        $rows = [];
        $cursor = CarbonImmutable::parse($period['start'])->startOfDay();
        $end = CarbonImmutable::parse($period['end'])->startOfDay();

        while ($cursor->lessThanOrEqualTo($end)) {
            $date = $cursor->toDateString();
            $rows[] = [
                'date' => $date,
                'reservations' => (int) ($dailyReservationCounts[$date] ?? 0),
                'sales' => (int) ($dailySalesCounts[$date] ?? 0),
                'purchases' => (int) ($dailyPurchaseCounts[$date] ?? 0),
            ];
            $cursor = $cursor->addDay();
        }

        return $rows;
    }

    private function monthlyEvolution(array $filters, array $period): array
    {
        $start = CarbonImmutable::parse($period['end_local'])->subMonthsNoOverflow(23)->startOfMonth()->startOfDay();
        $end = CarbonImmutable::parse($period['end_local'])->endOfDay();
        $startUtc = $start->utc();
        $endUtcExclusive = $end->addDay()->startOfDay()->utc();
        $months = [];
        $cursor = $start->startOfMonth();
        $leadKeysByMonth = [];
        $opportunityKeysByMonth = [];
        $reservationKeysByMonth = [];
        $saleKeysByMonth = [];
        $appraisalKeysByMonth = [];
        $purchaseKeysByMonth = [];

        while ($cursor->lessThanOrEqualTo($end)) {
            $key = $cursor->format('Y-m');
            $months[$key] = [
                'date' => $cursor->toDateString(),
                'label' => ucfirst($cursor->locale('es')->translatedFormat('F')),
                'spend' => 0.0,
                'impressions' => 0,
                'clicks' => 0,
                'leads_salesforce' => 0,
                'opportunities' => 0,
                'reservations' => 0,
                'sales' => 0,
                'appraisals_generated' => 0,
                'purchases' => 0,
            ];
            $cursor = $cursor->addMonthNoOverflow();
        }

        $metricQuery = DB::table('campaign_platform_daily_metrics')
            ->where('metric_date', '>=', $start->toDateString())
            ->where('metric_date', '<=', $end->toDateString());
        $this->applyMetricFilters($metricQuery, array_merge($filters, ['campaign_source_type' => 'platform_campaign']));

        $metricQuery
            ->select([
                DB::raw('SUBSTR(metric_date, 1, 7) as metric_month'),
                'platform',
                'campaign_id',
                'campaign_name',
                DB::raw('SUM(COALESCE(spend, 0)) as spend'),
                DB::raw('SUM(COALESCE(impressions, 0)) as impressions'),
                DB::raw('SUM(COALESCE(clicks, 0)) as clicks'),
            ])
            ->groupBy(DB::raw('SUBSTR(metric_date, 1, 7)'), 'platform', 'campaign_id', 'campaign_name')
            ->get()
            ->each(function (object $row) use (&$months, $filters): void {
                $campaign = (array) $row;

                if (! $this->includeCampaignForContext($campaign, $filters)) {
                    return;
                }

                $month = (string) $row->metric_month;

                if (! isset($months[$month])) {
                    return;
                }

                $months[$month]['spend'] = round($months[$month]['spend'] + (float) $row->spend, 2);
                $months[$month]['impressions'] += (int) $row->impressions;
                $months[$month]['clicks'] += (int) $row->clicks;
            });

        $attributionQuery = DB::table('campaign_lead_attributions as cla')
            ->leftJoin('salesforce_opportunities as so', 'so.salesforce_id', '=', 'cla.opportunity_id')
            ->where('cla.interest_functional_created_at', '>=', $startUtc)
            ->where('cla.interest_functional_created_at', '<', $endUtcExclusive);

        $this->applyLeadAttributionFilters($attributionQuery, $filters, 'cla');

        if (filled($filters['campaign_source_type'] ?? null)) {
            if ($filters['campaign_source_type'] === 'platform_campaign') {
                $attributionQuery->where('cla.platform', '<>', 'salesforce');
            } else {
                $attributionQuery->where('cla.platform', 'salesforce');
            }
        }

        $this->applyCampaignContextFilter($attributionQuery, $filters, 'cla');

        $attributionQuery
            ->select([
                'cla.interest_id',
                'cla.interest_functional_created_at',
                'cla.platform',
                'cla.campaign_id',
                'cla.campaign_name',
                'cla.opportunity_id',
                'cla.has_opportunity',
                'cla.has_reservation',
                'cla.has_sale',
                'cla.has_purchase',
                'cla.campaign_type',
                'so.is_deleted as opportunity_is_deleted',
                'so.deletion_detection_source as opportunity_deletion_source',
            ])
            ->get()
            ->each(function (object $row) use (&$months, $filters, &$opportunityKeysByMonth, &$reservationKeysByMonth, &$saleKeysByMonth, &$appraisalKeysByMonth, &$purchaseKeysByMonth): void {
                $campaign = [
                    'platform' => $row->platform,
                    'campaign_id' => $row->campaign_id,
                    'campaign_name' => $row->campaign_name,
                ];

                if (! $this->includeCampaignForContext($campaign, $filters)) {
                    return;
                }

                $month = $this->reportDateTime($row->interest_functional_created_at)?->format('Y-m');

                if ($month === null || ! isset($months[$month])) {
                    return;
                }

                $leadKey = $month.'|'.$row->interest_id;

                if (! isset($leadKeysByMonth[$leadKey])) {
                    $months[$month]['leads_salesforce']++;
                    $leadKeysByMonth[$leadKey] = true;
                }

                if ($this->isConfirmedDeletedOpportunityRow($row)) {
                    return;
                }

                if ((bool) $row->has_opportunity && filled($row->opportunity_id)) {
                    $opportunityKey = $month.'|'.$row->opportunity_id;

                    if (! isset($opportunityKeysByMonth[$opportunityKey])) {
                        $months[$month]['opportunities']++;
                        $opportunityKeysByMonth[$opportunityKey] = true;
                    }
                }

                if (filled($row->opportunity_id)) {
                    $opportunityId = (string) $row->opportunity_id;

                    if ((bool) $row->has_reservation) {
                        $reservationKey = $month.'|'.$opportunityId;

                        if (! isset($reservationKeysByMonth[$reservationKey])) {
                            $months[$month]['reservations']++;
                            $reservationKeysByMonth[$reservationKey] = true;
                        }
                    }

                    if ($this->isSaleAttributionRow($row)) {
                        $saleKey = $month.'|'.$opportunityId;

                        if (! isset($saleKeysByMonth[$saleKey])) {
                            $months[$month]['sales']++;
                            $saleKeysByMonth[$saleKey] = true;
                        }
                    }

                    if ((bool) $row->has_opportunity && $row->campaign_type === 'tasacion') {
                        $appraisalKey = $month.'|'.$opportunityId;

                        if (! isset($appraisalKeysByMonth[$appraisalKey])) {
                            $months[$month]['appraisals_generated']++;
                            $appraisalKeysByMonth[$appraisalKey] = true;
                        }
                    }

                    if ($this->isPurchaseAttributionRow($row)) {
                        $purchaseKey = $month.'|'.$opportunityId;

                        if (! isset($purchaseKeysByMonth[$purchaseKey])) {
                            $months[$month]['purchases']++;
                            $purchaseKeysByMonth[$purchaseKey] = true;
                        }
                    }
                }
            });

        return array_values($months);
    }

    private function dailyEvolution(array $filters, array $period): array
    {
        if (filled($filters['campaign_source_type'] ?? null) && $filters['campaign_source_type'] !== 'platform_campaign') {
            return [];
        }

        $metricQuery = DB::table('campaign_platform_daily_metrics')
            ->where('metric_date', '>=', $period['start'])
            ->where('metric_date', '<=', $period['end']);
        $this->applyMetricFilters($metricQuery, array_merge($filters, ['campaign_source_type' => 'platform_campaign']));

        $spendByDate = $metricQuery
            ->select('metric_date', DB::raw('SUM(COALESCE(spend, 0)) as spend'))
            ->groupBy('metric_date')
            ->pluck('spend', 'metric_date');

        $attributionQuery = DB::table('campaign_lead_attributions as cla')
            ->where('cla.interest_functional_created_at', '>=', $period['start_at'])
            ->where('cla.interest_functional_created_at', '<', $period['end_at']);

        $this->applyLeadAttributionFilters($attributionQuery, $filters, 'cla');

        if (filled($filters['campaign_source_type'] ?? null)) {
            if ($filters['campaign_source_type'] === 'platform_campaign') {
                $attributionQuery->where('cla.platform', '<>', 'salesforce');
            } else {
                $attributionQuery->where('cla.platform', 'salesforce');
            }
        }

        $this->applyCampaignContextFilter($attributionQuery, $filters, 'cla');

        $attributionByDate = $attributionQuery
            ->select([
                'cla.interest_id',
                'cla.interest_functional_created_at',
            ])
            ->get()
            ->reduce(function (array $carry, object $row): array {
                $metricDate = $this->reportDateTime($row->interest_functional_created_at)?->toDateString();

                if ($metricDate === null) {
                    return $carry;
                }

                $carry[$metricDate] ??= [];
                $carry[$metricDate][(string) $row->interest_id] = true;

                return $carry;
            }, []);

        $rows = [];
        $cursor = CarbonImmutable::parse($period['start'])->startOfDay();
        $end = CarbonImmutable::parse($period['end'])->startOfDay();

        while ($cursor->lessThanOrEqualTo($end)) {
            $date = $cursor->toDateString();
            $rows[] = [
                'date' => $date,
                'spend' => round((float) ($spendByDate[$date] ?? 0), 2),
                'leads_salesforce' => isset($attributionByDate[$date]) ? count($attributionByDate[$date]) : 0,
            ];
            $cursor = $cursor->addDay();
        }

        return $rows;
    }

    private function dailyReservationsSales(array $filters, array $period): array
    {
        $query = DB::table('campaign_lead_attributions as cla')
            ->leftJoin('salesforce_opportunities as so', 'so.salesforce_id', '=', 'cla.opportunity_id')
            ->where('cla.interest_functional_created_at', '>=', $period['start_at'])
            ->where('cla.interest_functional_created_at', '<', $period['end_at']);

        $this->applyLeadAttributionFilters($query, $filters, 'cla');

        if (filled($filters['campaign_source_type'] ?? null)) {
            if ($filters['campaign_source_type'] === 'platform_campaign') {
                $query->where('cla.platform', '<>', 'salesforce');
            } else {
                $query->where('cla.platform', 'salesforce');
            }
        }

        $this->applyCampaignContextFilter($query, $filters, 'cla');

        $rowsByDate = (clone $query)
            ->select([
                'cla.interest_functional_created_at',
                'cla.opportunity_id',
                'cla.has_reservation',
                'cla.has_sale',
                'cla.has_purchase',
                'cla.campaign_type',
                'so.is_deleted as opportunity_is_deleted',
                'so.deletion_detection_source as opportunity_deletion_source',
            ])
            ->get()
            ->reduce(function (array $carry, object $row): array {
                $metricDate = $this->reportDateTime($row->interest_functional_created_at)?->toDateString();

                if ($metricDate === null) {
                    return $carry;
                }

                if ($this->isConfirmedDeletedOpportunityRow($row)) {
                    return $carry;
                }

                if (filled($row->opportunity_id)) {
                    $opportunityId = (string) $row->opportunity_id;
                    $carry[$metricDate]['reservations_keys'] ??= [];
                    $carry[$metricDate]['sales_keys'] ??= [];
                    $carry[$metricDate]['purchases_keys'] ??= [];

                    if ((bool) $row->has_reservation) {
                        $carry[$metricDate]['reservations_keys'][$opportunityId] = true;
                    }

                    if ($this->isSaleAttributionRow($row)) {
                        $carry[$metricDate]['sales_keys'][$opportunityId] = true;
                    }

                    if ($this->isPurchaseAttributionRow($row)) {
                        $carry[$metricDate]['purchases_keys'][$opportunityId] = true;
                    }
                }

                return $carry;
            }, []);

        $rows = [];
        $cursor = CarbonImmutable::parse($period['start'])->startOfDay();
        $end = CarbonImmutable::parse($period['end'])->startOfDay();

        while ($cursor->lessThanOrEqualTo($end)) {
            $date = $cursor->toDateString();
            $rows[] = [
                'date' => $date,
                'reservations' => isset($rowsByDate[$date]['reservations_keys']) ? count($rowsByDate[$date]['reservations_keys']) : 0,
                'sales' => isset($rowsByDate[$date]['sales_keys']) ? count($rowsByDate[$date]['sales_keys']) : 0,
                'purchases' => isset($rowsByDate[$date]['purchases_keys']) ? count($rowsByDate[$date]['purchases_keys']) : 0,
            ];
            $cursor = $cursor->addDay();
        }

        return $rows;
    }

    private function funnelChart(array $totals, string $context = 'venta'): array
    {
        $base = [
            ['label' => 'Impresiones', 'value' => (int) ($totals['impressions'] ?? 0)],
            ['label' => 'Clicks', 'value' => (int) ($totals['clicks'] ?? 0)],
            ['label' => 'Intereses Salesforce', 'value' => (int) ($totals['leads_salesforce'] ?? 0)],
        ];

        $steps = match ($context) {
            'tasacion' => [
                ['label' => 'Oportunidades', 'value' => (int) ($totals['opportunities'] ?? 0)],
                ['label' => 'Compras', 'value' => (int) ($totals['purchases'] ?? 0)],
            ],
            'exposicion' => [
                ['label' => 'Oportunidades', 'value' => (int) ($totals['opportunities'] ?? 0)],
            ],
            'branding' => [
                ['label' => 'Oportunidades', 'value' => (int) ($totals['opportunities'] ?? 0)],
            ],
            default => [
                ['label' => 'Oportunidades', 'value' => (int) ($totals['opportunities'] ?? 0)],
                ['label' => 'Reservas', 'value' => (int) ($totals['reservations'] ?? 0)],
                ['label' => 'Ventas', 'value' => (int) ($totals['sales'] ?? 0)],
            ],
        };

        if (in_array($context, ['all', 'todas', '', null, 'otros'], true)) {
            $steps = [
                ['label' => 'Oportunidades', 'value' => (int) ($totals['opportunities'] ?? 0)],
                ['label' => 'Resultados', 'value' => (int) ($totals['result_count'] ?? 0)],
            ];
        }

        return array_merge($base, $steps);
    }

    private function distinctAttributionTotalsForRows(array $rows, array $filters, array $period): array
    {
        $visibleRowKeys = array_fill_keys(array_map(fn (array $row): string => $this->rowKey($row), $rows), true);

        if ($visibleRowKeys === []) {
            return [
                'leads_salesforce' => 0,
                'opportunities' => 0,
                'reservations' => 0,
                'live_reservations' => 0,
                'fallen_reservations' => 0,
                'sales' => 0,
                'sale_amount' => null,
                'appraisals_generated' => 0,
                'purchases' => 0,
                'appraisal_amount' => null,
            ];
        }

        $query = DB::table('campaign_lead_attributions as cla')
            ->leftJoin('salesforce_opportunities as so', 'so.salesforce_id', '=', 'cla.opportunity_id')
            ->where('cla.interest_functional_created_at', '>=', $period['start_at'])
            ->where('cla.interest_functional_created_at', '<', $period['end_at']);

        $this->applyLeadAttributionFilters($query, $filters, 'cla');

        if (filled($filters['campaign_source_type'] ?? null)) {
            if ($filters['campaign_source_type'] === 'platform_campaign') {
                $query->where('cla.platform', '<>', 'salesforce');
            } else {
                $query->where('cla.platform', 'salesforce');
            }
        }

        $this->applyCampaignContextFilter($query, $filters, 'cla');

        $leadIds = [];
        $opportunities = [];

        $query
            ->select([
                'cla.id',
                'cla.platform',
                'cla.campaign_id',
                'cla.campaign_name',
                'cla.source_campaign_name',
                'cla.campaign_acquired',
                'cla.campaign_type',
                'cla.campaign_source_type',
                'cla.interest_id',
                'cla.opportunity_id',
                'cla.has_opportunity',
                'cla.has_reservation',
                'cla.has_sale',
                'cla.has_purchase',
                'cla.sold_amount',
                'so.opo_for_importe_total',
                'so.is_deleted as opportunity_is_deleted',
                'so.deletion_detection_source as opportunity_deletion_source',
            ])
            ->orderBy('cla.id')
            ->chunkById(1000, function ($chunk) use (&$leadIds, &$opportunities, $visibleRowKeys): void {
                foreach ($chunk as $row) {
                    $identity = $this->rowKey($this->attributionIdentityRow($row));

                    if (! isset($visibleRowKeys[$identity])) {
                        continue;
                    }

                    if (filled($row->interest_id)) {
                        $leadIds[(string) $row->interest_id] = true;
                    }

                    if ($this->isConfirmedDeletedOpportunityRow($row)) {
                        continue;
                    }

                    if (! filled($row->opportunity_id)) {
                        continue;
                    }

                    $opportunityId = (string) $row->opportunity_id;
                    $saleAmount = null;

                    if ($this->isSaleAttributionRow($row)) {
                        $fallbackAmount = (float) ($row->opo_for_importe_total ?? 0);
                        $saleAmount = $row->sold_amount !== null
                            ? (float) $row->sold_amount
                            : ($fallbackAmount > 0 ? $fallbackAmount : null);
                    }

                    $purchaseAmount = $this->isPurchaseAttributionRow($row)
                        ? abs((float) ($row->opo_for_importe_total ?? 0))
                        : null;

                    $opportunities[$opportunityId] ??= [
                        'has_opportunity' => false,
                        'has_reservation' => false,
                        'has_live_reservation' => false,
                        'has_fallen_reservation' => false,
                        'has_sale' => false,
                        'has_appraisal_generated' => false,
                        'has_purchase' => false,
                        'sale_amount' => null,
                        'appraisal_amount' => null,
                    ];

                    $isSale = $this->isSaleAttributionRow($row);
                    $isTasacion = ($row->campaign_type ?? null) === 'tasacion';

                    $opportunities[$opportunityId]['has_opportunity'] = $opportunities[$opportunityId]['has_opportunity'] || (bool) $row->has_opportunity;
                    $opportunities[$opportunityId]['has_reservation'] = $opportunities[$opportunityId]['has_reservation'] || (bool) $row->has_reservation;
                    $opportunities[$opportunityId]['has_live_reservation'] = $opportunities[$opportunityId]['has_live_reservation']
                        || ((bool) $row->has_reservation && ($isTasacion || ! $isSale));
                    $opportunities[$opportunityId]['has_fallen_reservation'] = $opportunities[$opportunityId]['has_fallen_reservation']
                        || ((bool) $row->has_reservation && ! $isTasacion && $isSale);
                    $opportunities[$opportunityId]['has_sale'] = $opportunities[$opportunityId]['has_sale'] || $isSale;
                    $opportunities[$opportunityId]['has_appraisal_generated'] = $opportunities[$opportunityId]['has_appraisal_generated']
                        || ((bool) $row->has_opportunity && $isTasacion);
                    $opportunities[$opportunityId]['has_purchase'] = $opportunities[$opportunityId]['has_purchase'] || $this->isPurchaseAttributionRow($row);

                    if ($saleAmount !== null && $opportunities[$opportunityId]['sale_amount'] === null) {
                        $opportunities[$opportunityId]['sale_amount'] = $saleAmount;
                    }

                    if ($purchaseAmount !== null && $opportunities[$opportunityId]['appraisal_amount'] === null) {
                        $opportunities[$opportunityId]['appraisal_amount'] = $purchaseAmount;
                    }
                }
            }, 'cla.id', 'id');

        $saleAmounts = array_values(array_filter(array_map(
            static fn (array $row): ?float => $row['has_sale'] && $row['sale_amount'] !== null ? (float) $row['sale_amount'] : null,
            $opportunities
        ), static fn (?float $value): bool => $value !== null));
        $appraisalAmounts = array_values(array_filter(array_map(
            static fn (array $row): ?float => $row['has_purchase'] && $row['appraisal_amount'] !== null ? (float) $row['appraisal_amount'] : null,
            $opportunities
        ), static fn (?float $value): bool => $value !== null));

        return [
            'leads_salesforce' => count($leadIds),
            'opportunities' => count(array_filter($opportunities, static fn (array $row): bool => $row['has_opportunity'])),
            'reservations' => count(array_filter($opportunities, static fn (array $row): bool => $row['has_reservation'])),
            'live_reservations' => count(array_filter($opportunities, static fn (array $row): bool => $row['has_live_reservation'])),
            'fallen_reservations' => count(array_filter($opportunities, static fn (array $row): bool => $row['has_fallen_reservation'])),
            'sales' => count(array_filter($opportunities, static fn (array $row): bool => $row['has_sale'])),
            'sale_amount' => $saleAmounts !== [] ? round(array_sum($saleAmounts), 2) : null,
            'appraisals_generated' => count(array_filter($opportunities, static fn (array $row): bool => $row['has_appraisal_generated'])),
            'purchases' => count(array_filter($opportunities, static fn (array $row): bool => $row['has_purchase'])),
            'appraisal_amount' => $appraisalAmounts !== [] ? round(array_sum($appraisalAmounts), 2) : null,
        ];
    }

    private function attributionIdentityRow(object $row): array
    {
        $identity = $this->normalizeDashboardRow([
            'platform' => $row->platform,
            'campaign_id' => $row->campaign_id,
            'campaign_name' => $row->campaign_name,
            'campaign_acquired' => $row->campaign_acquired,
            'source_campaign_name' => $row->source_campaign_name ?: $row->campaign_acquired,
            'campaign_type' => $row->campaign_type,
            'campaign_source_type' => $row->campaign_source_type
                ?? ($row->platform === 'salesforce' ? 'salesforce_campaign_without_spend' : 'platform_campaign'),
        ]);

        $identity['campaign_source_type'] = $this->deriveSourceType($identity);

        return $identity;
    }

    private function platformBars(array $rows): array
    {
        return collect($rows)
            ->filter(fn (array $row): bool => in_array(($row['platform'] ?? null), ['google_ads', 'meta'], true))
            ->groupBy('platform')
            ->map(function ($items, string $platform): array {
                $spend = round((float) $items->sum('spend'), 2);
                $leads = (int) $items->sum('leads_salesforce');
                $opportunities = (int) $items->sum('opportunities');
                $purchases = (int) $items->sum('purchases');
                $sales = (int) $items->sum('sales');
                $resultCount = $this->resultCountForRows($items->all());
                $resultLabel = $this->resultLabelForContext($this->contextForRows($items->all()));

                return [
                    'platform' => $platform,
                    'spend' => $spend,
                    'impressions' => (int) $items->sum('impressions'),
                    'clicks' => (int) $items->sum('clicks'),
                    'leads_salesforce' => $leads,
                    'opportunities' => $opportunities,
                    'reservations' => (int) $items->sum('reservations'),
                    'sales' => $sales,
                    'purchases' => $purchases,
                    'result_label' => $resultLabel,
                    'result_count' => $resultCount,
                    'cost_per_lead' => $this->divide($spend, $leads),
                    'cost_per_opportunity' => $this->divide($spend, $opportunities),
                    'cost_per_purchase' => $this->divide($spend, $purchases),
                    'cost_per_sale' => $this->divide($spend, $sales),
                    'cost_per_result' => $this->divide($spend, $resultCount),
                    'lead_to_purchase' => $this->divide($purchases, $leads),
                ];
            })
            ->sortByDesc('spend')
            ->values()
            ->all();
    }

    private function reviewCampaigns(array $rows, array $filters): array
    {
        $rows = array_values($rows);
        $avgCostByType = collect($rows)
            ->groupBy(fn (array $row) => $this->campaignType($row))
            ->map(function ($items): ?float {
                $costs = $items
                    ->map(fn (array $row) => $this->costPerResultForRow($row))
                    ->filter(fn ($value) => $value !== null && $value > 0);

                return $costs->isNotEmpty() ? round((float) $costs->avg(), 4) : null;
            })
            ->all();

        return collect($rows)
            ->map(function (array $row) use ($avgCostByType): ?array {
                if ($this->campaignTypeResolver->isStoreVisitCampaign($row['campaign_name'] ?? null)) {
                    return null;
                }

                $spend = (float) ($row['spend'] ?? 0);
                $clicks = (int) ($row['clicks'] ?? 0);
                $leads = (int) ($row['leads_salesforce'] ?? 0);
                $opportunities = (int) ($row['opportunities'] ?? 0);
                $resultCount = $this->resultCountForRow($row);
                $costPerResult = $this->costPerResultForRow($row);
                $type = $this->campaignType($row);
                $avgCost = $avgCostByType[$type] ?? null;

                if ($spend > 100 && $leads === 0) {
                    return $this->reviewRow($row, 'Revisar tracking', 'inversión superior a 100 € y cero Interests', 'Intereses Salesforce', $leads, $costPerResult, $resultCount);
                }

                if ($clicks > 300 && $leads <= 1) {
                    return $this->reviewRow($row, 'Revisar conversión', 'más de 300 clicks e Interests bajos o nulos', 'Intereses Salesforce', $leads, $costPerResult, $resultCount);
                }

                if ($leads > 0 && $opportunities === 0) {
                    return $this->reviewRow($row, 'Revisar calidad', 'hay Interests Salesforce pero no oportunidades', 'Intereses / oportunidades', sprintf('%d / %d', $leads, $opportunities), $costPerResult, $resultCount);
                }

                if ($opportunities > 0 && $resultCount === 0) {
                    return $this->reviewRow($row, 'Revisar cierre', 'hay oportunidades pero no hay resultado final', 'Oportunidades / resultado', sprintf('%d / %d', $opportunities, $resultCount), $costPerResult, $resultCount);
                }

                if ($avgCost !== null && $costPerResult !== null && $costPerResult > ($avgCost * 2)) {
                    return $this->reviewRow($row, 'Coste alto', 'coste por resultado por encima del doble de la media del tipo', 'Coste por resultado', $costPerResult, $costPerResult, $resultCount);
                }

                if (! $this->campaignIsActive($row) && ($spend > 0 || $leads > 0 || $resultCount > 0)) {
                    return $this->reviewRow($row, 'Revisar estado', 'campana inactiva con inversion o resultados recientes', 'Resultado', $resultCount ?: $leads, $costPerResult, $resultCount);
                }

                return null;
            })
            ->filter()
            ->sortByDesc(fn (array $row) => $row['economic_impact'] ?? 0)
            ->take(8)
            ->values()
            ->all();
    }

    private function reviewRow(array $row, string $reason, string $detail, string $metric, string|float|int|null $value, float|int|null $costPerResult = null, ?int $resultCount = null): array
    {
        return [
            'campaign' => $this->displayCampaign($row),
            'campaign_name' => $row['campaign_name'] ?? null,
            'campaign_id' => $row['campaign_id'] ?? null,
            'platform' => $row['platform'] ?? null,
            'campaign_type' => $row['campaign_type'] ?? null,
            'reason' => $reason,
            'detail' => $detail,
            'metric' => $metric,
            'value' => $value,
            'cost_per_result' => $costPerResult,
            'result_count' => $resultCount,
            'economic_impact' => round((float) ($row['spend'] ?? 0), 2),
            'spend' => round((float) ($row['spend'] ?? 0), 2),
        ];
    }

    private function sourceReconciliation(array $filters, array $period): array
    {
        $query = DB::table('campaign_lead_attributions as cla')
            ->leftJoin('salesforce_opportunities as so', 'so.salesforce_id', '=', 'cla.opportunity_id')
            ->where('cla.interest_functional_created_at', '>=', $period['start_at'])
            ->where('cla.interest_functional_created_at', '<', $period['end_at']);
        $filtersWithoutSource = array_merge($filters, ['campaign_source_type' => '']);
        $this->applyLeadAttributionFilters($query, $filtersWithoutSource, 'cla');
        $this->applyCampaignContextFilter($query, $filtersWithoutSource, 'cla');

        $sets = [
            'platform' => ['leads' => [], 'opportunities' => [], 'results' => []],
            'salesforce_only' => ['leads' => [], 'opportunities' => [], 'results' => []],
        ];
        foreach ($query->get([
            'cla.interest_id', 'cla.opportunity_id', 'cla.platform', 'cla.campaign_type',
            'cla.has_opportunity', 'cla.has_sale', 'cla.has_purchase',
            'so.is_deleted as opportunity_is_deleted',
            'so.deletion_detection_source as opportunity_deletion_source',
        ]) as $row) {
            $source = $row->platform === 'salesforce' ? 'salesforce_only' : 'platform';
            if (filled($row->interest_id)) {
                $sets[$source]['leads'][(string) $row->interest_id] = true;
            }
            if ($this->isConfirmedDeletedOpportunityRow($row)) {
                continue;
            }
            if ((bool) $row->has_opportunity && filled($row->opportunity_id)) {
                $sets[$source]['opportunities'][(string) $row->opportunity_id] = true;
            }
            $isResult = ($row->campaign_type === 'tasacion' && (bool) $row->has_purchase)
                || ($row->campaign_type !== 'tasacion' && (bool) $row->has_sale);
            if ($isResult && filled($row->opportunity_id)) {
                $sets[$source]['results'][(string) $row->opportunity_id] = true;
            }
        }

        $result = [];
        foreach (['leads', 'opportunities', 'results'] as $metric) {
            $platform = array_keys($sets['platform'][$metric]);
            $salesforceOnly = array_keys($sets['salesforce_only'][$metric]);
            $result[$metric] = [
                'platform' => count($platform),
                'salesforce_only' => count($salesforceOnly),
                'total_distinct' => count(array_unique(array_merge($platform, $salesforceOnly))),
                'overlap' => count(array_intersect($platform, $salesforceOnly)),
            ];
        }

        return $result;
    }

    private function top(array $rows, string $key, bool $ascending = false, bool $requireValue = false): array
    {
        return collect($rows)
            ->when($requireValue, fn ($collection) => $collection->filter(fn (array $row) => $row[$key] !== null))
            ->sortBy($key, SORT_REGULAR, ! $ascending)
            ->take(5)
            ->values()
            ->all();
    }

    private function classification(array $rows, string $classification): array
    {
        return collect($rows)
            ->where('classification', $classification)
            ->sortByDesc('spend')
            ->take(5)
            ->values()
            ->all();
    }

    private function benchmarks(array $rows): array
    {
        $costs = collect($rows)->pluck('cost_per_sale')->filter(fn ($value) => $value !== null && $value > 0);
        $roas = collect($rows)->pluck('roas')->filter(fn ($value) => $value !== null && $value > 0);

        return [
            'avg_cost_per_sale' => $costs->isNotEmpty() ? round($costs->avg(), 4) : null,
            'avg_roas' => $roas->isNotEmpty() ? round($roas->avg(), 4) : null,
        ];
    }

    private function publicWarnings(array $totals): array
    {
        $overlap = (int) ($totals['lead_attribution_overlap'] ?? 0);

        if ($overlap === 0) {
            return [];
        }

        return [sprintf(
            'Las filas contienen %d apariciones adicionales de intereses atribuidos a más de una campaña. El KPI Intereses Salesforce usa IDs de interés únicos; la auditoría identifica los IDs solapados.',
            $overlap
        )];
    }

    private function warnings(array $rows, array $totals, array $period, array $filters): array
    {
        $warnings = $this->publicWarnings($totals);

        if (! filled(config('services.meta_ads.access_token')) || config('services.meta_ads.ad_account_ids') === []) {
            $warnings[] = 'Las credenciales de Meta Ads no estan configuradas. Se muestran datos disponibles de Salesforce y/o ultima inversion cacheada.';
        }

        if (! filled(config('services.google_ads.developer_token')) || config('services.google_ads.customer_ids') === []) {
            $warnings[] = 'Las credenciales de Google Ads no estan configuradas. Se muestran datos disponibles de Salesforce y/o ultima inversion cacheada.';
        }

        if (($totals['leads_salesforce'] ?? 0) > 0 && (float) ($totals['spend'] ?? 0) <= 0.0) {
            $warnings[] = 'Hay campanas Salesforce sin inversion asociada o procedencias sin coste. Revisar IDs/nombres de campana.';
        }

        if ((float) ($totals['spend'] ?? 0) > 0.0 && (int) ($totals['leads_salesforce'] ?? 0) === 0) {
            $warnings[] = 'Hay inversión de plataforma sin intereses Salesforce asociados.';
        }

        if (CampaignAttribution::query()->count() === 0) {
            $warnings[] = 'No hay atribuciones construidas para el periodo seleccionado.';
        }

        if (($totals['sales'] ?? 0) > 0 && ($totals['sale_amount'] ?? null) === null) {
            $warning = $this->saleAmountWarning($period, $filters);

            if ($warning !== null) {
                $warnings[] = $warning;
            }
        }

        $lastSyncedAt = $this->lastMetricSyncedAt();
        if ($lastSyncedAt === null) {
            $warnings[] = 'No hay inversion sincronizada todavia en campaign_platform_daily_metrics.';
        } elseif ($lastSyncedAt->lessThan(now()->subHours(36))) {
            $warnings[] = 'Los datos de inversion no se han actualizado en las ultimas 36 horas.';
        }

        $lastAttributionBuild = DB::table('campaign_lead_attributions')->max('updated_at');
        if ($lastAttributionBuild && $lastSyncedAt && CarbonImmutable::parse($lastAttributionBuild)->lessThan($lastSyncedAt)) {
            $warnings[] = 'Las metricas publicitarias son posteriores a la atribucion. Ejecuta campaigns:build-attribution antes de validar el panel.';
        }

        $lastInterestSync = ReportSyncRun::query()
            ->where('dataset', SalesforceInterestSyncService::DATASET)
            ->where('source', SalesforceInterestSyncService::SOURCE)
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->value('completed_at');
        if ($lastAttributionBuild && $lastInterestSync && CarbonImmutable::parse($lastAttributionBuild)->lessThan(CarbonImmutable::parse($lastInterestSync))) {
            $warnings[] = 'Salesforce Interests se sincronizó después de la atribución. Reconstruye atribuciones para propagar cambios y lifecycle.';
        }

        return array_values(array_unique($warnings));
    }

    private function saleAmountWarning(array $period, array $filters): ?string
    {
        if (! $this->saleAmountResolver->preferredColumnExists()) {
            return $this->saleAmountResolver->diagnosticMessage().' ROAS y ROI quedan sin dato hasta que exista ese campo local.';
        }

        $attributedSales = $this->attributionBase($period, $filters)
            ->where('has_sale', true)
            ->where(function ($query): void {
                $query->whereNull('campaign_type')->orWhere('campaign_type', '<>', 'tasacion');
            })
            ->count();

        if ($attributedSales === 0) {
            return null;
        }

        $attributedSalesWithAmount = $this->attributedSalesWithResolvedAmount($period, $filters, 'count');

        if ($attributedSalesWithAmount > 0) {
            return null;
        }

        $salesWithLocalOpportunity = $this->attributionBase($period, $filters)
            ->join('salesforce_opportunities as so', 'so.salesforce_id', '=', 'campaign_lead_attributions.opportunity_id')
            ->whereRaw($this->reportableOpportunitySql('so'))
            ->where('campaign_lead_attributions.has_sale', true)
            ->where(function ($query): void {
                $query
                    ->whereNull('campaign_lead_attributions.campaign_type')
                    ->orWhere('campaign_lead_attributions.campaign_type', '<>', 'tasacion');
            })
            ->count();

        if ($salesWithLocalOpportunity === 0) {
            return 'Hay ventas atribuidas sin oportunidad local asociada. Revisar cruce opportunity_id.';
        }

        $synchronizedPositiveAmounts = DB::table('salesforce_opportunities')
            ->whereRaw($this->reportableOpportunitySql())
            ->where('opo_for_importe_total', '>', 0)
            ->count();

        if ($synchronizedPositiveAmounts === 0) {
            return 'La columna opo_for_importe_total existe, pero no contiene importes sincronizados. Revisar sync de Opportunity.OPO_FOR_Importe_total__c.';
        }

        if ($this->attributedSalesWithOpportunityAmount($period, $filters, 'opo_for_importe_total', 'count') > 0) {
            return null;
        }

        return $this->saleAmountResolver->emptyAmountsMessage();
    }

    private function attributionBase(array $period, array $filters)
    {
        $query = DB::table('campaign_lead_attributions')
            ->where('interest_functional_created_at', '>=', $period['start_at'])
            ->where('interest_functional_created_at', '<', $period['end_at']);

        $this->applyLeadAttributionFilters($query, $filters);
        $this->applyCampaignContextFilter($query, $filters);

        return $query;
    }

    private function diagnostics(array $rows, array $period, array $filters): array
    {
        $attributionBase = DB::table('campaign_lead_attributions')
            ->where('interest_functional_created_at', '>=', $period['start_at'])
            ->where('interest_functional_created_at', '<', $period['end_at']);
        $legacyAttributionBase = DB::table('campaign_attributions')
            ->where('interest_functional_created_at', '>=', $period['start_at'])
            ->where('interest_functional_created_at', '<', $period['end_at']);
        $rowCollection = collect($rows);

        return [
            'last_meta_sync' => DB::table('campaign_platform_daily_metrics')->where('platform', 'meta')->max('synced_at'),
            'last_google_sync' => DB::table('campaign_platform_daily_metrics')->where('platform', 'google_ads')->max('synced_at'),
            'last_attribution_build' => DB::table('campaign_lead_attributions')->max('updated_at'),
            'meta_metric_rows' => DB::table('campaign_platform_daily_metrics')->where('platform', 'meta')->count(),
            'google_metric_rows' => DB::table('campaign_platform_daily_metrics')->where('platform', 'google_ads')->count(),
            'salesforce_leads_with_campaign_period' => $this->leadsWithAcquisitionNotNull($period),
            'valid_candidate_leads' => (clone $attributionBase)->count(),
            'built_attributions' => (clone $attributionBase)->count(),
            'campaigns_spend_without_salesforce_leads' => $rowCollection->filter(fn (array $row) => (float) $row['spend'] > 0 && (int) $row['leads_salesforce'] === 0)->count(),
            'campaigns_salesforce_without_spend' => $rowCollection->filter(fn (array $row) => (int) $row['leads_salesforce'] > 0 && (float) $row['spend'] <= 0.0)->count(),
            'campaigns_matched_by_id' => $rowCollection->filter(fn (array $row) => str_contains((string) ($row['match_status'] ?? ''), 'ID'))->count(),
            'campaigns_matched_by_name' => $rowCollection->filter(fn (array $row) => str_contains((string) ($row['match_status'] ?? ''), 'nombre'))->count(),
            'platform_campaigns' => $rowCollection->where('campaign_source_type', 'platform_campaign')->count(),
            'salesforce_origins' => (clone $legacyAttributionBase)->where('campaign_source_type', 'salesforce_origin')->count(),
            'salesforce_campaigns_without_spend' => (clone $legacyAttributionBase)->where('campaign_source_type', 'salesforce_campaign_without_spend')->count(),
            'crossed_campaigns' => $rowCollection->filter(fn (array $row) => str_starts_with((string) ($row['match_status'] ?? ''), 'Cruzada por'))->count(),
            'campaigns_without_crossing' => $rowCollection->filter(fn (array $row) => ! str_starts_with((string) ($row['match_status'] ?? ''), 'Cruzada por'))->count(),
            'leads_platform_campaigns' => $rowCollection->where('campaign_source_type', 'platform_campaign')->sum('leads_salesforce'),
            'leads_salesforce_origins' => (clone $legacyAttributionBase)->where('campaign_source_type', 'salesforce_origin')->count(),
            'attributed_sales' => $rowCollection->sum('sales'),
            'sales_with_amount_available' => $rowCollection->filter(fn (array $row) => (int) ($row['sales'] ?? 0) > 0 && ($row['sale_amount'] ?? null) !== null)->sum('sales'),
            'opportunities_cv_signed' => DB::table('salesforce_opportunities')->whereRaw($this->reportableOpportunitySql())->where('cv_signed', true)->count(),
            'attributed_sales_with_amount' => $this->attributedSalesWithResolvedAmount($period, $filters, 'count'),
            'attributed_sales_amount_sum' => $this->attributedSalesWithResolvedAmount($period, $filters, 'sum'),
            'sales_with_opo_for_importe_total' => $this->attributedSalesWithOpportunityAmount($period, $filters, 'opo_for_importe_total', 'count'),
            'sum_opo_for_importe_total_sales' => $this->attributedSalesWithOpportunityAmount($period, $filters, 'opo_for_importe_total', 'sum'),
            'sales_with_amount' => $this->attributedSalesWithOpportunityAmount($period, $filters, 'amount', 'count'),
            'sum_amount_sales' => $this->attributedSalesWithOpportunityAmount($period, $filters, 'amount', 'sum'),
            'amount_field_status' => $this->opportunityAmountFieldStatus('opo_for_importe_total'),
            'opo_for_importe_total_field_status' => $this->opportunityAmountFieldStatus('opo_for_importe_total'),
            'amount_fallback_field_status' => $this->opportunityAmountFieldStatus('amount'),
            'sale_amount_field_used' => $this->saleAmountFieldUsed($period, $filters),
            'candidates_with_campaign_acquired' => (clone $attributionBase)->whereNotNull('campaign_acquired')->where('campaign_acquired', '<>', '')->count(),
            'candidates_only_source_medium' => (clone $attributionBase)
                ->where('platform', 'salesforce')
                ->count(),
            'candidates_with_acquired_id' => (clone $attributionBase)->whereNotNull('acquired_id')->where('acquired_id', '<>', '')->count(),
            'candidates_with_content_acquired' => (clone $attributionBase)->whereNotNull('content_acquired')->where('content_acquired', '<>', '')->count(),
            'match_ad_id' => (clone $legacyAttributionBase)->where('attribution_method', 'ad_id_match')->count(),
            'match_adset_or_adgroup' => (clone $legacyAttributionBase)->where('attribution_method', 'adset_or_adgroup_id_match')->count(),
            'match_campaign_id' => (clone $legacyAttributionBase)->where('attribution_method', 'campaign_id_match')->count(),
            'match_campaign_name_exact' => (clone $legacyAttributionBase)->where(function ($query): void {
                $query->where('attribution_method', 'campaign_name_exact_match')
                    ->orWhere(function ($legacy): void {
                        $legacy->where('attribution_method', 'campaign_name_match')->where('attribution_confidence', 'medium');
                    });
            })->count(),
            'match_campaign_name_flexible' => (clone $legacyAttributionBase)->where(function ($query): void {
                $query->where('attribution_method', 'campaign_name_flexible_match')
                    ->orWhere(function ($legacy): void {
                        $legacy->where('attribution_method', 'campaign_name_match')->where('attribution_confidence', 'low');
                    });
            })->count(),
            'salesforce_only_by_campaign' => (clone $legacyAttributionBase)->where('campaign_source_type', 'salesforce_campaign_without_spend')->count(),
            'salesforce_only_by_origin' => (clone $legacyAttributionBase)->where('campaign_source_type', 'salesforce_origin')->count(),
        ];
    }

    private function opportunityAmountFieldStatus(string $column): string
    {
        if (! Schema::hasColumn('salesforce_opportunities', $column)) {
            return 'missing';
        }

        $cvSigned = DB::table('salesforce_opportunities')->whereRaw($this->reportableOpportunitySql())->where('cv_signed', true);
        $cvSignedCount = (clone $cvSigned)->count();
        $cvSignedWithAmount = (clone $cvSigned)->where($column, '>', 0)->count();

        if ($cvSignedCount > 0 && $cvSignedWithAmount === 0) {
            return 'exists_but_zero';
        }

        return 'exists_with_values';
    }

    private function attributedSalesWithOpportunityAmount(array $period, array $filters, string $column, string $aggregate): int|float
    {
        if (! Schema::hasColumn('salesforce_opportunities', $column)) {
            return $aggregate === 'sum' ? 0.0 : 0;
        }

        $query = DB::table('campaign_lead_attributions as cla')
            ->join('salesforce_opportunities as so', 'so.salesforce_id', '=', 'cla.opportunity_id')
            ->whereRaw($this->reportableOpportunitySql('so'))
            ->where('cla.interest_functional_created_at', '>=', $period['start_at'])
            ->where('cla.interest_functional_created_at', '<', $period['end_at'])
            ->where('cla.has_sale', true)
            ->where(function ($subQuery): void {
                $subQuery->whereNull('cla.campaign_type')->orWhere('cla.campaign_type', '<>', 'tasacion');
            })
            ->where("so.{$column}", '>', 0);

        $this->applyLeadAttributionFilters($query, $filters, 'cla');
        $this->applyCampaignContextFilter($query, $filters, 'cla');

        return $aggregate === 'sum'
            ? round((float) $query->sum("so.{$column}"), 2)
            : $query->count();
    }

    private function attributedSalesWithResolvedAmount(array $period, array $filters, string $aggregate): int|float
    {
        $query = DB::table('campaign_lead_attributions as cla')
            ->leftJoin('salesforce_opportunities as so', 'so.salesforce_id', '=', 'cla.opportunity_id')
            ->whereRaw($this->reportableOpportunitySql('so'))
            ->where('cla.interest_functional_created_at', '>=', $period['start_at'])
            ->where('cla.interest_functional_created_at', '<', $period['end_at'])
            ->where('cla.has_sale', true)
            ->where(function ($subQuery): void {
                $subQuery->whereNull('cla.campaign_type')->orWhere('cla.campaign_type', '<>', 'tasacion');
            });

        $this->applyLeadAttributionFilters($query, $filters, 'cla');
        $this->applyCampaignContextFilter($query, $filters, 'cla');

        $fallbackSql = 'NULL';

        if (Schema::hasColumn('salesforce_opportunities', 'opo_for_importe_total')) {
            $fallbackSql = 'CASE WHEN COALESCE(so.opo_for_importe_total, 0) > 0 THEN so.opo_for_importe_total ELSE NULL END';
        }

        if (Schema::hasColumn('salesforce_opportunities', 'amount')) {
            $fallbackSql = $fallbackSql === 'NULL'
                ? 'CASE WHEN COALESCE(so.amount, 0) > 0 THEN so.amount ELSE NULL END'
                : "COALESCE({$fallbackSql}, CASE WHEN COALESCE(so.amount, 0) > 0 THEN so.amount ELSE NULL END)";
        }

        $resolvedAmountSql = "COALESCE(cla.sold_amount, {$fallbackSql})";

        if ($aggregate === 'sum') {
            return round((float) ($query->whereRaw("{$resolvedAmountSql} > 0")->sum(DB::raw($resolvedAmountSql)) ?? 0), 2);
        }

        return (clone $query)->whereRaw("{$resolvedAmountSql} > 0")->count();
    }

    private function saleAmountFieldUsed(array $period, array $filters): string
    {
        if ($this->attributedSalesWithOpportunityAmount($period, $filters, 'opo_for_importe_total', 'count') > 0) {
            return 'opo_for_importe_total';
        }

        if ($this->attributedSalesWithOpportunityAmount($period, $filters, 'amount', 'count') > 0) {
            return 'amount';
        }

        return 'none';
    }

    private function leadsWithAcquisitionNotNull(array $period): int
    {
        return DB::table('salesforce_interests')
            ->where('is_deleted', false)
            ->where('functional_created_at', '>=', $period['start_at'])
            ->where('functional_created_at', '<', $period['end_at'])
            ->where(function ($query): void {
                foreach ([
                    'utm_campaign',
                    'utm_id',
                    'utm_content',
                    'source',
                    'medium',
                ] as $field) {
                    $query->orWhere(function ($subQuery) use ($field): void {
                        $subQuery->whereNotNull($field)->where($field, '<>', '');
                    });
                }
            })
            ->count();
    }

    private function applyMetricFilters($query, array $filters): void
    {
        if (filled($filters['campaign_source_type'] ?? null) && $filters['campaign_source_type'] !== 'platform_campaign') {
            $query->whereRaw('1 = 0');
        }

        foreach (['platform', 'account_id', 'campaign_id'] as $field) {
            if (filled($filters[$field] ?? null)) {
                $query->where($field, $filters[$field]);
            }
        }

        $campaignNames = $filters['campaign_name'] ?? [];

        if (is_array($campaignNames) && count($campaignNames) > 0) {
            $query->whereIn('campaign_name', $campaignNames);
        } elseif (filled($campaignNames ?? null)) {
            $query->where('campaign_name', $campaignNames);
        }

        if (filled($filters['campaign_status'] ?? null)) {
            $query->where(function ($subQuery) use ($filters): void {
                if ($filters['campaign_status'] === 'active') {
                    $subQuery
                        ->where(function ($active): void {
                            $active->where('platform', 'google_ads')->where('campaign_status', 'ENABLED');
                        })
                        ->orWhere(function ($active): void {
                            $active->where('platform', 'meta')->where('campaign_effective_status', 'ACTIVE');
                        });

                    return;
                }

                $subQuery
                    ->where(function ($inactive): void {
                        $inactive->where('platform', 'google_ads')->where(function ($status): void {
                            $status->whereNull('campaign_status')->orWhere('campaign_status', '<>', 'ENABLED');
                        });
                    })
                    ->orWhere(function ($inactive): void {
                        $inactive->where('platform', 'meta')->where(function ($status): void {
                            $status->whereNull('campaign_effective_status')->orWhere('campaign_effective_status', '<>', 'ACTIVE');
                        });
                    });
            });
        }

        if (filled($filters['search'] ?? null)) {
            $search = '%'.$filters['search'].'%';
            $query->where(function ($subQuery) use ($search): void {
                $subQuery
                    ->where('campaign_id', 'like', $search)
                    ->orWhere('campaign_name', 'like', $search);
            });
        }
    }

    private function applyAttributionFilters($query, array $filters, ?string $alias = null): void
    {
        $column = fn (string $field): string => $alias ? "{$alias}.{$field}" : $field;

        foreach ([
            'platform',
            'account_id',
            'campaign_source_type',
            'source_acquired',
            'medium_acquired',
            'campaign_acquired',
            'campaign_id',
            'interest_status',
            'vehicle_interest',
        ] as $field) {
            if (filled($filters[$field] ?? null)) {
                $query->where($column($field), $filters[$field]);
            }
        }

        $campaignNames = $filters['campaign_name'] ?? [];

        if (is_array($campaignNames) && count($campaignNames) > 0) {
            $query->whereIn($column('campaign_name'), $campaignNames);
        } elseif (filled($campaignNames ?? null)) {
            $query->where($column('campaign_name'), $campaignNames);
        }

        if (filled($filters['delegation'] ?? null)) {
            $query->where($column('interest_origin_delegation'), $filters['delegation']);
        }

        if (filled($filters['zone'] ?? null)) {
            $query->where($column('interest_origin_zone'), $filters['zone']);
        }

        if (filled($filters['commercial_user'] ?? null)) {
            $query->where(function ($subQuery) use ($filters, $column): void {
                $subQuery
                    ->where($column('commercial_user_id'), $filters['commercial_user'])
                    ->orWhere($column('commercial_user_name'), $filters['commercial_user']);
            });
        }

        if (filled($filters['lead_type'] ?? null)) {
            $query->where($column('interest_type'), $filters['lead_type']);
        }

        if (filled($filters['search'] ?? null)) {
            $search = '%'.$filters['search'].'%';
            $query->where(function ($subQuery) use ($search, $column): void {
                $subQuery
                    ->where($column('campaign_id'), 'like', $search)
                    ->orWhere($column('campaign_name'), 'like', $search)
                    ->orWhere($column('campaign_acquired'), 'like', $search)
                    ->orWhere($column('source_acquired'), 'like', $search)
                    ->orWhere($column('medium_acquired'), 'like', $search);
            });
        }

        foreach ([
            'has_opportunity' => 'has_opportunity',
            'has_reservation' => 'has_reservation',
            'has_sale' => 'has_sale',
        ] as $filter => $field) {
            $boolean = $this->booleanFilter($filters[$filter] ?? null);

            if ($boolean !== null) {
                $query->where($column($field), $boolean);
            }
        }
    }

    private function applyLeadAttributionFilters($query, array $filters, ?string $alias = null): void
    {
        $column = fn (string $field): string => $alias ? "{$alias}.{$field}" : $field;

        $query->where($column('interest_is_deleted'), false);

        foreach ([
            'platform',
            'campaign_id',
            'campaign_name',
            'source_acquired',
            'medium_acquired',
            'campaign_acquired',
            'interest_status',
            'vehicle_interest',
        ] as $field) {
            if (filled($filters[$field] ?? null)) {
                $query->where($column($field), $filters[$field]);
            }
        }

        if (filled($filters['delegation'] ?? null)) {
            $query->where($column('interest_origin_delegation'), $filters['delegation']);
        }

        if (filled($filters['zone'] ?? null)) {
            $query->where($column('interest_origin_zone'), $filters['zone']);
        }

        if (filled($filters['commercial_user'] ?? null)) {
            $query->where(function ($subQuery) use ($filters, $column): void {
                $subQuery
                    ->where($column('commercial_user_id'), $filters['commercial_user'])
                    ->orWhere($column('commercial_user_name'), $filters['commercial_user']);
            });
        }

        if (filled($filters['lead_type'] ?? null)) {
            $query->where($column('interest_type'), $filters['lead_type']);
        }

        if (filled($filters['search'] ?? null)) {
            $search = '%'.$filters['search'].'%';
            $query->where(function ($subQuery) use ($search, $column): void {
                $subQuery
                    ->where($column('campaign_id'), 'like', $search)
                    ->orWhere($column('campaign_name'), 'like', $search)
                    ->orWhere($column('source_campaign_name'), 'like', $search);
            });
        }

        foreach ([
            'has_opportunity' => 'has_opportunity',
            'has_reservation' => 'has_reservation',
            'has_sale' => 'has_sale',
            'has_purchase' => 'has_purchase',
        ] as $filter => $field) {
            $boolean = $this->booleanFilter($filters[$filter] ?? null);

            if ($boolean !== null) {
                $query->where($column($field), $boolean);
            }
        }
    }

    private function filterOptions(): array
    {
        return [
            'campaign_types' => [
                ['value' => 'all', 'label' => 'Todos'],
                ['value' => 'venta', 'label' => 'Venta'],
                ['value' => 'tasacion', 'label' => 'Tasacion'],
                ['value' => 'exposicion', 'label' => 'Exposicion'],
                ['value' => 'branding', 'label' => 'Branding'],
                ['value' => 'otros', 'label' => 'Otros'],
            ],
            'interest_types' => [
                ['value' => 'venta', 'label' => 'Venta'],
                ['value' => 'venta_con_cambio', 'label' => 'Venta con cambio'],
                ['value' => 'lead', 'label' => 'Lead'],
                ['value' => 'ayvens', 'label' => 'Ayvens'],
                ['value' => 'tasacion', 'label' => 'Tasación'],
            ],
            // Compatibility alias consumed by the current dashboard JavaScript.
            'lead_types' => [
                ['value' => 'venta', 'label' => 'Venta'],
                ['value' => 'venta_con_cambio', 'label' => 'Venta con cambio'],
                ['value' => 'lead', 'label' => 'Lead'],
                ['value' => 'ayvens', 'label' => 'Ayvens'],
                ['value' => 'tasacion', 'label' => 'Tasación'],
            ],
            'platforms' => collect($this->distinctFromBoth('platform'))
                ->filter(fn (string $platform): bool => in_array($platform, ['google_ads', 'meta'], true))
                ->values()
                ->all(),
            'source_types' => [
                ['value' => 'platform_campaign', 'label' => 'Campana plataforma'],
                ['value' => 'salesforce_campaign_without_spend', 'label' => 'Campana Salesforce sin inversion'],
            ],
            'classifications' => ['Potenciar', 'Revisar', 'Parar', 'Revisar tracking', 'Sin datos suficientes'],
            'campaign_statuses' => [
                ['value' => 'active', 'label' => 'Activas'],
                ['value' => 'inactive', 'label' => 'Inactivas'],
            ],
            'accounts' => DB::table('campaign_platform_daily_metrics')
                ->select('account_id', DB::raw('MIN(account_name) as account_name'))
                ->whereNotNull('account_id')
                ->groupBy('account_id')
                ->orderBy('account_id')
                ->get()
                ->map(fn ($row) => ['id' => $row->account_id, 'name' => $row->account_name])
                ->all(),
            'sources' => $this->distinct('campaign_lead_attributions', 'source_acquired'),
            'mediums' => $this->distinct('campaign_lead_attributions', 'medium_acquired'),
            'campaigns_acquired' => $this->distinct('campaign_lead_attributions', 'campaign_acquired'),
            'campaign_ids' => $this->distinctFromBoth('campaign_id'),
            'campaign_names' => $this->distinctFromBoth('campaign_name'),
            'delegations' => $this->distinct('campaign_lead_attributions', 'interest_origin_delegation'),
            'zones' => $this->distinct('campaign_lead_attributions', 'interest_origin_zone'),
            'interest_statuses' => $this->distinct('campaign_lead_attributions', 'interest_status'),
            'lead_statuses' => $this->distinct('campaign_lead_attributions', 'interest_status'),
            'commercials' => DB::table('campaign_lead_attributions')
                ->select('commercial_user_id', 'commercial_user_name')
                ->whereNotNull('commercial_user_name')
                ->distinct()
                ->orderBy('commercial_user_name')
                ->get()
                ->map(fn ($row) => ['id' => $row->commercial_user_id ?: $row->commercial_user_name, 'name' => $row->commercial_user_name])
                ->all(),
            'vehicles' => $this->distinct('campaign_lead_attributions', 'vehicle_interest'),
            'months' => $this->monthOptions(),
        ];
    }

    private function filterOptionsCached(): array
    {
        return Cache::remember(
            'campaign-dashboard-filter-options-v2:'.md5(json_encode($this->filterOptionsVersion())),
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn () => $this->filterOptions()
        );
    }

    private function normalizeContext(mixed $context): string
    {
        $context = $this->normalizer->key($context);

        return in_array($context, ['venta', 'ventas', 'tasacion', 'exposicion', 'branding', 'otros', 'all', 'todas'], true)
            ? ($context === 'todas' ? 'all' : $context)
            : 'all';
    }

    private function normalizeLeadTypeFilter(mixed $leadType): string
    {
        if (blank($leadType) || $this->normalizer->key($leadType) === 'all') {
            return '';
        }

        return $this->leadRecordTypeNormalizer->normalize($leadType) ?? '';
    }

    private function applyCampaignContextFilter($query, array $filters, ?string $alias = null): void
    {
        $context = $this->normalizeContext($filters['context'] ?? 'all');
        $column = $alias ? "{$alias}.campaign_type" : 'campaign_type';

        if ($context === 'all') {
            return;
        }

        if ($context === 'venta') {
            $query->whereIn($column, ['venta', 'exposicion', 'branding', 'otros']);

            return;
        }

        $query->where($column, $context === 'ventas' ? 'venta' : $context);
    }

    private function contextLabel(?string $context): string
    {
        return match ($this->normalizeContext($context)) {
            'venta' => 'Venta',
            'ventas' => 'Ventas',
            'tasacion' => 'Tasacion',
            'exposicion' => 'Exposicion',
            'branding' => 'Branding',
            'otros' => 'Otros',
            default => 'Todos',
        };
    }

    private function contextForRows(array $rows): string
    {
        $types = collect($rows)
            ->map(fn (array $row) => $this->campaignType($row))
            ->filter()
            ->unique()
            ->values();

        return $types->count() === 1 ? (string) $types->first() : 'all';
    }

    private function resultLabelForContext(?string $context): string
    {
        return match ($this->normalizeContext($context)) {
            'venta' => 'Ventas',
            'ventas' => 'Ventas',
            'tasacion' => 'Compras',
            'exposicion' => 'Oportunidades',
            'branding' => 'Intereses',
            'otros' => 'Resultados',
            default => 'Resultados',
        };
    }

    private function resultCountForRow(array $row): int
    {
        return match ($this->campaignType($row)) {
            'venta' => (int) ($row['sales'] ?? 0),
            'tasacion' => (int) ($row['purchases'] ?? 0),
            'exposicion' => (int) ($row['opportunities'] ?? 0),
            'branding' => (int) ($row['leads_salesforce'] ?? 0),
            'otros' => max((int) ($row['sales'] ?? 0), (int) ($row['purchases'] ?? 0), (int) ($row['opportunities'] ?? 0), (int) ($row['leads_salesforce'] ?? 0)),
            default => (int) ($row['sales'] ?? 0) + (int) ($row['purchases'] ?? 0),
        };
    }

    private function resultCountForRows(array $rows): int
    {
        return array_sum(array_map(fn (array $row) => $this->resultCountForRow($row), $rows));
    }

    private function resultCountForTotals(array $totals, ?string $context): int
    {
        return match ($this->normalizeContext($context)) {
            'venta' => (int) ($totals['sales'] ?? 0),
            'ventas' => (int) ($totals['sales'] ?? 0),
            'tasacion' => (int) ($totals['purchases'] ?? 0),
            'exposicion' => (int) ($totals['opportunities'] ?? 0),
            'branding' => (int) ($totals['leads_salesforce'] ?? 0),
            'otros' => max(
                (int) ($totals['sales'] ?? 0),
                (int) ($totals['purchases'] ?? 0),
                (int) ($totals['opportunities'] ?? 0),
                (int) ($totals['leads_salesforce'] ?? 0),
            ),
            default => (int) ($totals['sales'] ?? 0) + (int) ($totals['purchases'] ?? 0),
        };
    }

    private function costPerResultForRow(array $row): ?float
    {
        $resultCount = $this->resultCountForRow($row);

        if ($resultCount <= 0) {
            return null;
        }

        return $this->divide((float) ($row['spend'] ?? 0), $resultCount);
    }

    private function monthOptions(): array
    {
        $cursor = CarbonImmutable::now()->startOfMonth();
        $months = [];

        for ($index = 0; $index < 12; $index++) {
            $month = $cursor->subMonthsNoOverflow($index);
            $months[] = [
                'value' => $month->format('Y-m'),
                'label' => ucfirst($month->locale('es')->translatedFormat('F Y')),
                'start' => $month->startOfMonth()->toDateString(),
                'end' => $month->endOfMonth()->toDateString(),
            ];
        }

        return $months;
    }

    private function distinct(string $table, string $column): array
    {
        return DB::table($table)
            ->whereNotNull($column)
            ->where($column, '<>', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->all();
    }

    private function distinctFromBoth(string $column): array
    {
        return collect($this->distinct('campaign_attributions', $column))
            ->merge($this->distinct('campaign_lead_attributions', $column))
            ->merge($this->distinct('campaign_platform_daily_metrics', $column))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function period(array $filters): array
    {
        $end = $this->parseDate($filters['end_date'], CarbonImmutable::now(self::REPORT_TIMEZONE))->endOfDay();
        $start = $this->parseDate($filters['start_date'], $end->subDays(30))->startOfDay();

        if ($start->greaterThan($end)) {
            $start = $end->subDays(30)->startOfDay();
        }

        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'start_at' => $start->utc(),
            'end_at' => $end->addDay()->startOfDay()->utc(),
            'start_local' => $start,
            'end_local' => $end,
        ];
    }

    /**
     * @return array<int, string>|string
     */
    private function campaignNameFilter(mixed $value): array|string
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(
                fn ($item) => trim((string) $item),
                $value
            ), fn (string $item): bool => $item !== ''));
        }

        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        $parts = array_values(array_filter(array_map('trim', preg_split('/[|,]/', $value) ?: [])));

        return count($parts) > 1 ? $parts : $value;
    }

    private function rowKey(array $row): string
    {
        $sourceType = $row['campaign_source_type'] ?? '';
        $platform = $row['platform'] ?? 'unknown';
        $campaignId = $row['campaign_id'] ?? '';
        $campaignNameKey = $this->normalizer->key($row['campaign_name'] ?? '');

        /*
        * En campañas de plataforma, el campaign_id debe ser la clave principal.
        * No usamos account_id en la key porque puede venir informado en métricas
        * de plataforma y vacío en atribuciones Salesforce, lo que separaría
        * inversión y leads en filas distintas.
        */
        if ($sourceType === 'platform_campaign' && filled($campaignId)) {
            return implode('|', [
                $sourceType,
                $platform,
                $campaignId,
                $campaignNameKey,
            ]);
        }

        return implode('|', [
            $sourceType,
            $platform,
            $row['account_id'] ?? '',
            $campaignId,
            $campaignNameKey,
        ]);
    }

    private function hasAttributionSpecificFilters(array $filters): bool
    {
        foreach ([
            'source_acquired',
            'medium_acquired',
            'campaign_acquired',
            'campaign_source_type',
            'delegation',
            'zone',
            'interest_status',
            'has_opportunity',
            'has_reservation',
            'has_sale',
            'commercial_user',
            'vehicle_interest',
            'lead_type',
        ] as $key) {
            if ($key === 'campaign_source_type' && ($filters[$key] ?? null) === 'platform_campaign') {
                continue;
            }

            if (filled($filters[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function booleanFilter(?string $value): ?bool
    {
        if (! filled($value) || $value === 'all') {
            return null;
        }

        return in_array($value, ['1', 'true', 'yes', 'si', 's'], true)
            ? true
            : (in_array($value, ['0', 'false', 'no', 'n'], true) ? false : null);
    }

    private function divide(float|int|null $numerator, float|int|null $denominator): ?float
    {
        if ($denominator === null || (float) $denominator === 0.0) {
            return null;
        }

        return round(((float) $numerator) / ((float) $denominator), 4);
    }

    private function parseDate(?string $value, CarbonImmutable $fallback): CarbonImmutable
    {
        if (blank($value)) {
            return $fallback;
        }

        try {
            return CarbonImmutable::parse($value, self::REPORT_TIMEZONE)->setTimezone(self::REPORT_TIMEZONE);
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private function reportDateTime(mixed $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        return CarbonImmutable::parse($value)->setTimezone(self::REPORT_TIMEZONE);
    }

    private function lastUpdated(): ?CarbonImmutable
    {
        $updated = max(
            DB::table('campaign_lead_attributions')->max('updated_at') ?: '',
            CampaignPlatformDailyMetric::query()->max('updated_at') ?: ''
        );

        return $updated ? CarbonImmutable::parse($updated) : null;
    }

    private function lastMetricSyncedAt(): ?CarbonImmutable
    {
        $syncedAt = CampaignPlatformDailyMetric::query()->max('synced_at');

        return $syncedAt ? CarbonImmutable::parse($syncedAt) : null;
    }

    private function campaignDatasetMetadata(array $period): array
    {
        $interestRun = ReportSyncRun::query()
            ->where('dataset', SalesforceInterestSyncService::DATASET)
            ->where('source', SalesforceInterestSyncService::SOURCE)
            ->orderByDesc('id')
            ->first();
        $interestSync = $interestRun?->source_cutoff_at;
        $metaSync = CampaignPlatformDailyMetric::query()->where('platform', 'meta')->max('synced_at');
        $googleSync = CampaignPlatformDailyMetric::query()->where('platform', 'google_ads')->max('synced_at');
        $attributionBuild = DB::table('campaign_lead_attributions')->max('updated_at');
        $cutoff = collect([$interestSync, $metaSync, $googleSync, $attributionBuild])
            ->filter()
            ->map(fn ($value) => CarbonImmutable::parse($value))
            ->sortBy(fn (CarbonImmutable $value) => $value->getTimestamp())
            ->first();

        return [
            'salesforce_interests_synced_at' => $this->reportDateTime($interestSync)?->toIso8601String(),
            'salesforce_leads_synced_at' => $this->reportDateTime($interestSync)?->toIso8601String(),
            'meta_synced_at' => $this->reportDateTime($metaSync)?->toDateTimeString(),
            'google_synced_at' => $this->reportDateTime($googleSync)?->toDateTimeString(),
            'attribution_built_at' => $this->reportDateTime($attributionBuild)?->toDateTimeString(),
            'dataset_generated_at' => CarbonImmutable::now(self::REPORT_TIMEZONE)->toDateTimeString(),
            'dataset_cutoff_at' => $cutoff?->setTimezone(self::REPORT_TIMEZONE)->toDateTimeString(),
        ];
    }

    private function dataVersion(): array
    {
        return [
            'attributions_updated_at' => DB::table('campaign_lead_attributions')->max('updated_at'),
            'metrics_updated_at' => CampaignPlatformDailyMetric::query()->max('updated_at'),
            'opportunity_interest_context' => $this->opportunityInterestAttribution->capture(),
            'dashboard_cache_version' => Cache::get('campaign_dashboard_cache_version', 1),
        ];
    }

    private function filterOptionsVersion(): array
    {
        return [
            'campaign_lead_attributions_updated_at' => DB::table('campaign_lead_attributions')->max('updated_at'),
            'campaign_attributions_updated_at' => DB::table('campaign_attributions')->max('updated_at'),
            'metrics_updated_at' => CampaignPlatformDailyMetric::query()->max('updated_at'),
            'dashboard_cache_version' => Cache::get('campaign_dashboard_cache_version', 1),
        ];
    }
}
