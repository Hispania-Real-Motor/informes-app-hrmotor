<?php

namespace App\Services\Reports\Calls;

use App\Services\Reports\Leads\LeadDelegationNormalizer;
use App\Support\ReportServerTiming;
use App\Support\ReportUserAccess;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;

class CallDashboardDatasetService
{
    private const CACHE_TTL_MINUTES = 10;

    private const SHARED_CACHE_LOCK_SECONDS = 15;

    private const SHARED_CACHE_WAIT_SECONDS = 6;

    private ?array $agentDisplayNames = null;

    private array $matchingUserIdentityCache = [];

    public function __construct(
        private readonly CallMetricsAggregator $aggregator,
        private readonly LeadDelegationNormalizer $delegationNormalizer,
        private readonly CallClassificationRules $rules,
    ) {}

    public function summary(Request $request, ?ReportServerTiming $timing = null): array
    {
        $filters = $this->filters($request);
        $periods = $this->periods($filters);

        return $this->rememberEndpoint('summary', $filters, $periods, $timing, fn () => $this->buildSummary($filters, $periods, $timing));
    }

    public function agentRows(Request $request, ?ReportServerTiming $timing = null): array
    {
        $filters = $this->filters($request);
        $periods = $this->periods($filters);

        return $this->rememberEndpoint('agents', $filters, $periods, $timing, fn () => $this->buildAgentPayload($filters, $periods, $timing));
    }

    public function delegationRows(Request $request, ?ReportServerTiming $timing = null): array
    {
        $filters = $this->filters($request);
        $periods = $this->periods($filters);

        return $this->rememberEndpoint('delegations', $filters, $periods, $timing, fn () => $this->buildDelegationPayload($filters, $periods, $timing));
    }

    public function portalRows(Request $request): array
    {
        $filters = $this->filters($request);
        $periods = $this->periods($filters);

        return $this->rememberEndpoint('portals', $filters, $periods, null, fn () => $this->buildPortalPayload($filters, $periods));
    }

    public function auditRows(Request $request): array
    {
        return $this->auditRowsLazy($request)->values()->all();
    }

    public function auditRowsLazy(Request $request): LazyCollection
    {
        $filters = $this->filters($request);
        $period = $this->periods($filters)['current'];
        $includedQuery = $this->baseFilteredQuery($filters, $period)
            ->select('salesforce_id')
            ->distinct();

        $auditQuery = DB::table('salesforce_calls as audit_calls')
            ->where('created_date', '>=', $period['start'])
            ->where('created_date', '<', $period['end']);
        $this->applyAccessScope($auditQuery, $filters);

        return $auditQuery
            ->leftJoinSub($includedQuery, 'filtered_calls', function ($join): void {
                $join->on('filtered_calls.salesforce_id', '=', 'audit_calls.salesforce_id');
            })
            ->select('audit_calls.*')
            ->addSelect('filtered_calls.salesforce_id as included_task_id')
            ->orderBy('audit_calls.created_date')
            ->orderBy('audit_calls.salesforce_id')
            ->cursor()
            ->map(function ($row): array {
                $parseDebug = is_string($row->parse_debug ?? null)
                    ? (json_decode($row->parse_debug, true) ?: [])
                    : (array) ($row->parse_debug ?? []);
                $includedByUniverse = (bool) ($row->included_in_dashboard ?? true);
                $includedByFilters = $includedByUniverse && $row->included_task_id !== null;
                $rawDuration = (int) ($row->call_duration_seconds ?? $row->parsed_duration_seconds ?? 0);
                $adjustedDuration = (int) ($row->adjusted_duration_seconds ?? 0);
                $reason = $includedByFilters
                    ? 'included'
                    : ($row->dashboard_exclusion_reason ?: 'dashboard_filter');
                $team = $row->operational_team ?: 'unassigned';

                return [
                    'task_id' => $row->salesforce_id,
                    'created_date' => $row->created_date,
                    'included_in_kpi' => $includedByFilters,
                    'exclusion_reason' => $reason,
                    'call_object_present' => filled($row->call_object),
                    'raw_result' => $row->result_raw,
                    'interpreted_result' => $row->call_status,
                    'answered' => (bool) ($row->is_answered ?? false),
                    'raw_duration_seconds' => $rawDuration,
                    'adjusted_duration_seconds' => $adjustedDuration,
                    'discounted_seconds' => max(0, $rawDuration - $adjustedDuration),
                    'origin_type' => $row->call_origin,
                    'portal' => $row->portal_resolved,
                    'team' => $team,
                    'agent_salesforce_user_id' => $row->operational_user_id,
                    'classification_rule_version' => $row->classification_rule_version ?: 'legacy_unversioned',
                    'classification_raw_values' => data_get($parseDebug, 'parsed', []),
                    'last_modified_date' => $row->last_modified_date,
                    'call_object' => $row->call_object,
                    'included_in_dashboard_universe' => $includedByUniverse,
                    'included_by_current_filters' => $includedByFilters,
                    'inclusion_exclusion_reason' => $reason,
                    'result_original' => $row->result_raw,
                    'answered_by_raw' => data_get($parseDebug, 'parsed.answered_by_raw'),
                    'result_interpreted' => $row->call_status,
                    'duration_initial_seconds' => $rawDuration,
                    'seconds_deducted' => max(0, $rawDuration - $adjustedDuration),
                    'duration_adjusted_seconds' => $adjustedDuration,
                    'portal_raw' => $row->portales_raw,
                    'portal_resolved' => $row->portal_resolved,
                    'portal_resolution_source' => $row->portal_resolution_source,
                    'what_id' => $row->what_id,
                    'interest_relationship_status' => data_get($parseDebug, 'portal_debug.relationship_status'),
                    'interest_exact_match' => (bool) data_get($parseDebug, 'portal_debug.interest_matched', false),
                    'interest_id' => data_get($parseDebug, 'portal_debug.interest_id'),
                    'interest_is_deleted' => data_get($parseDebug, 'portal_debug.interest_is_deleted'),
                    'interest_source_fallback' => data_get($parseDebug, 'portal_debug.interest_source_raw'),
                    'portal_provenance' => $row->portal_resolution_source,
                    'team_resolved' => $team,
                    'operational_user_id' => $row->operational_user_id,
                    'operational_user_name' => $row->operational_user_name,
                    'classified_at' => $row->classified_at,
                ];
            });
    }

    public function auditColumns(): array
    {
        return [
            'task_id', 'created_date', 'included_in_kpi', 'exclusion_reason',
            'call_object_present', 'raw_result', 'interpreted_result', 'answered',
            'raw_duration_seconds', 'adjusted_duration_seconds', 'discounted_seconds',
            'origin_type', 'portal', 'team', 'agent_salesforce_user_id',
            'classification_rule_version', 'classification_raw_values',
            'last_modified_date', 'call_object', 'included_in_dashboard_universe',
            'included_by_current_filters', 'inclusion_exclusion_reason', 'result_original',
            'answered_by_raw', 'result_interpreted', 'duration_initial_seconds',
            'seconds_deducted', 'duration_adjusted_seconds', 'portal_raw',
            'portal_resolved', 'portal_resolution_source', 'team_resolved',
            'what_id', 'interest_relationship_status', 'interest_exact_match',
            'interest_id', 'interest_is_deleted', 'interest_source_fallback', 'portal_provenance',
            'operational_user_id', 'operational_user_name', 'classified_at',
        ];
    }

    public function payload(Request $request): array
    {
        $summary = $this->summary($request);
        $agents = $this->agentRows($request);
        $delegations = $this->delegationRows($request);
        $portals = $this->portalRows($request);

        return [
            'summary' => $summary,
            'teams' => $agents['teams'],
            'agents' => $agents['agents'],
            'commercials' => $agents['commercials'],
            'customer_service' => $agents['customer_service'],
            'contact_center' => $agents['contact_center'],
            'appraisers' => $agents['appraisers'],
            'zones' => $delegations['zones'],
            'delegations' => $delegations['delegations'],
            'portals' => $portals['items'],
        ];
    }

    public function prewarmFilterOptions(): void
    {
        $this->filterOptions();
    }

    private function rememberEndpoint(string $endpoint, array $filters, array $periods, ?ReportServerTiming $timing, callable $callback): array
    {
        $resolve = function () use ($endpoint, $filters, $periods, $timing, $callback): array {
            $payload = Cache::remember(
                'calls-dashboard:'.$endpoint.':'.md5(json_encode([
                    'endpoint' => $endpoint,
                    'filters' => $filters,
                    'periods' => [
                        'current' => $this->periodPayload($periods['current']),
                        'previous' => $this->periodPayload($periods['previous']),
                    ],
                    'version' => $this->dataVersion(),
                ], JSON_UNESCAPED_UNICODE)),
                now()->addMinutes(self::CACHE_TTL_MINUTES),
                function () use ($endpoint, $timing, $callback): array {
                    $timing?->mark('calls-'.$endpoint.'-miss');

                    return $callback();
                }
            );

            if ($timing !== null && ! $timing->has('calls-'.$endpoint.'-miss')) {
                $timing->mark('calls-'.$endpoint.'-hit');
            }

            return $payload;
        };

        return $timing?->measure('calls-'.$endpoint.'-total', $resolve) ?? $resolve();
    }

    private function buildSummary(array $filters, array $periods, ?ReportServerTiming $timing = null): array
    {
        $current = $timing?->measure('calls-current', fn (): array => $this->summaryBucket($filters, $periods['current']))
            ?? $this->summaryBucket($filters, $periods['current']);
        $previous = $timing?->measure('calls-previous', fn (): array => $this->summaryBucket($filters, $periods['previous']))
            ?? $this->summaryBucket($filters, $periods['previous']);
        [$agents, $teams] = $timing?->measure('calls-agents-teams', fn (): array => $this->sharedAgentAndTeamMetricRows($filters, $periods['current'], $timing))
            ?? $this->sharedAgentAndTeamMetricRows($filters, $periods['current']);
        $portals = $timing?->measure('calls-portals', fn (): array => $this->portalMetricRows($filters, $periods['current']))
            ?? $this->portalMetricRows($filters, $periods['current']);
        $charts = $timing?->measure('calls-daily', fn (): array => $this->summaryCharts($filters, $periods['current'], $current, $teams))
            ?? $this->summaryCharts($filters, $periods['current'], $current, $teams);
        $rankings = $timing?->measure('calls-ranking', fn (): array => $this->summaryRankings($filters, $periods['current'], $portals, $agents))
            ?? $this->summaryRankings($filters, $periods['current'], $portals, $agents);
        $reconciliation = $timing?->measure('calls-reconciliation', fn (): array => $this->reconciliationCounts($filters, $periods['current']))
            ?? $this->reconciliationCounts($filters, $periods['current']);
        $metadata = fn (): ?string => $this->lastUpdated()?->toDateTimeString();
        $cutoff = $timing !== null
            ? $timing->measure('calls-metadata', $metadata)
            : $metadata();
        $filterOptions = $timing?->measure('calls-filters', fn (): array => $this->filterOptions($timing))
            ?? $this->filterOptions();

        return [
            'ok' => $current['total_calls'] > 0 || $previous['total_calls'] > 0,
            'message' => $current['total_calls'] > 0 ? null : 'No hay llamadas sincronizadas para el periodo seleccionado.',
            'periodo_actual' => $this->periodPayload($periods['current']),
            'periodo_comparado' => $this->periodPayload($periods['previous']),
            'datos_actualizados' => $cutoff,
            'dataset_cutoff_at' => $cutoff,
            'dataset_generated_at' => CarbonImmutable::now()->toDateTimeString(),
            'dataset_source' => 'local_snapshot',
            'classification_rule_version' => CallClassificationRules::VERSION,
            'reconciliation' => [
                'raw_type_call_tasks' => $reconciliation['raw_universe'],
                'dashboard_call_object_tasks' => $reconciliation['dashboard_universe'],
                'excluded_without_call_object' => $reconciliation['missing_call_object'],
                'excluded_test_profile' => $reconciliation['excluded_test_profile'],
                'included_operational_tasks' => $reconciliation['dashboard_universe'],
            ],
            'kpis' => $current,
            'comparativa' => $this->comparison($current, $previous),
            'charts' => $charts,
            'rankings' => $rankings,
            'insights' => $this->insights($current, $previous, $rankings),
            'filters' => $filterOptions,
        ];
    }

    private function buildAgentPayload(array $filters, array $periods, ?ReportServerTiming $timing = null): array
    {
        [$agents, $teams] = $timing?->measure('calls-agents-query', fn (): array => $this->sharedAgentAndTeamMetricRows($filters, $periods['current'], $timing))
            ?? $this->sharedAgentAndTeamMetricRows($filters, $periods['current']);

        $finalize = fn (): array => [
            'ok' => true,
            'teams' => $teams,
            'agents' => $agents,
            'commercials' => $this->filterAgentsByTeam($agents, 'commercial'),
            'customer_service' => $this->filterAgentsByTeam($agents, 'customer_service'),
            'contact_center' => $this->filterAgentsByTeam($agents, 'contact_center'),
            'appraisers' => $this->filterAgentsByTeam($agents, 'appraiser'),
            'items' => $agents,
        ];

        return $timing?->measure('calls-agents-finalize', $finalize) ?? $finalize();
    }

    private function buildDelegationPayload(array $filters, array $periods, ?ReportServerTiming $timing = null): array
    {
        [$zones, $delegations] = $timing?->measure('calls-delegations-query', fn (): array => $this->zoneAndDelegationMetricRows($filters, $periods['current']))
            ?? $this->zoneAndDelegationMetricRows($filters, $periods['current']);

        $finalize = fn (): array => [
            'ok' => true,
            'zones' => $zones,
            'delegations' => $delegations,
            'items' => $delegations,
        ];

        return $timing?->measure('calls-delegations-finalize', $finalize) ?? $finalize();
    }

    private function buildPortalPayload(array $filters, array $periods): array
    {
        return [
            'ok' => true,
            'items' => $this->portalMetricRows($filters, $periods['current']),
        ];
    }

    private function summaryBucket(array $filters, array $period): array
    {
        $row = $this->baseFilteredQuery($filters, $period)
            ->selectRaw($this->metricsSelectSql())
            ->first();

        return $this->finalizeBucket($this->bucketFromRow($row));
    }

    private function teamMetricRows(array $filters, array $period): array
    {
        $teamSql = $this->effectiveTeamSql();

        $rows = $this->baseFilteredQuery($filters, $period)
            ->whereRaw($this->operationalTeamConditionSql())
            ->selectRaw($teamSql.' as team')
            ->selectRaw($this->metricsSelectSql())
            ->groupByRaw($teamSql)
            ->get();

        $groups = [];

        foreach ($rows as $row) {
            $team = (string) $row->team;

            $this->addAggregatedGroup($groups, $team, $this->teamLabel($team), [
                'team' => $team,
            ], $this->bucketFromRow($row), 0);
        }

        $unassigned = $this->baseFilteredQuery($filters, $period)
            ->whereRaw($teamSql." NOT IN ('commercial', 'customer_service', 'contact_center', 'appraiser')")
            ->selectRaw($this->metricsSelectSql())
            ->first();
        $unassignedBucket = $this->bucketFromRow($unassigned);
        if (($unassignedBucket['total_calls'] ?? 0) > 0) {
            $this->addAggregatedGroup($groups, 'unassigned', 'Sin equipo', [
                'team' => 'unassigned',
            ], $unassignedBucket, 0);
        }

        $rows = $this->finalizeGroups($groups, 'team_label');
        $order = array_flip(['commercial', 'customer_service', 'contact_center', 'appraiser', 'unassigned']);
        usort($rows, fn (array $a, array $b): int => ($order[$a['team']] ?? 99) <=> ($order[$b['team']] ?? 99));

        return $rows;
    }

    private function agentMetricRows(array $filters, array $period): array
    {
        return $this->agentAndTeamMetricRows($filters, $period)[0];
    }

    /** @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>} */
    private function sharedAgentAndTeamMetricRows(array $filters, array $period, ?ReportServerTiming $timing = null): array
    {
        return $this->rememberSharedAggregate(
            'agents',
            $filters,
            $period,
            fn (): array => $this->agentAndTeamMetricRows($filters, $period),
            $timing,
        );
    }

    private function rememberSharedAggregate(string $name, array $filters, array $period, callable $builder, ?ReportServerTiming $timing = null): array
    {
        $key = 'calls-dashboard:shared:'.$name.':'.md5(json_encode([
            'filters' => $filters,
            'period' => $this->periodPayload($period),
            'version' => $this->dataVersion(),
        ], JSON_UNESCAPED_UNICODE));
        $cached = Cache::get($key);

        if (is_array($cached)) {
            $timing?->mark('calls-'.$name.'-shared-hit');

            return $cached;
        }

        $store = Cache::store();

        try {
            $lock = $store->lock($key.':lock', self::SHARED_CACHE_LOCK_SECONDS);
        } catch (\Throwable) {
            return $this->rememberSharedAggregateWithoutLock($key, $name, $builder, $timing);
        }

        try {
            return $lock->block(self::SHARED_CACHE_WAIT_SECONDS, function () use ($key, $name, $builder, $timing): array {
                $cached = Cache::get($key);

                if (is_array($cached)) {
                    $timing?->mark('calls-'.$name.'-shared-hit');

                    return $cached;
                }

                $timing?->mark('calls-'.$name.'-shared-miss');
                $value = $builder();
                Cache::put($key, $value, now()->addMinutes(self::CACHE_TTL_MINUTES));

                return $value;
            });
        } catch (LockTimeoutException) {
            return $this->rememberSharedAggregateWithoutLock($key, $name, $builder, $timing);
        }
    }

    private function rememberSharedAggregateWithoutLock(string $key, string $name, callable $builder, ?ReportServerTiming $timing): array
    {
        $cached = Cache::get($key);

        if (is_array($cached)) {
            $timing?->mark('calls-'.$name.'-shared-hit');

            return $cached;
        }

        $timing?->mark('calls-'.$name.'-shared-miss');

        return Cache::remember($key, now()->addMinutes(self::CACHE_TTL_MINUTES), $builder);
    }

    /** @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>} */
    private function agentAndTeamMetricRows(array $filters, array $period): array
    {
        $teamSql = $this->effectiveTeamSql();
        $delegationSql = $this->effectiveDelegationSql();
        $zoneSql = $this->effectiveZoneSql();

        $rows = $this->baseFilteredQuery($filters, $period)
            ->whereRaw($this->operationalTeamConditionSql())
            ->selectRaw($teamSql.' as team')
            ->addSelect([
                'operational_user_id',
                'operational_user_name',
                'normalized_user_key',
                'destination_agent_name',
                'destination_agent_code',
                'owner_name',
                'owner_profile_name',
            ])
            ->selectRaw('MIN(id) as first_id')
            ->selectRaw($this->preferredLabelSql($delegationSql).' as delegation')
            ->selectRaw($this->preferredLabelSql($zoneSql).' as zone')
            ->selectRaw($this->metricsSelectSql())
            ->groupByRaw($teamSql)
            ->groupBy([
                'operational_user_id',
                'operational_user_name',
                'normalized_user_key',
                'destination_agent_name',
                'destination_agent_code',
                'owner_name',
                'owner_profile_name',
            ])
            ->orderBy('first_id')
            ->get();

        $agentGroups = [];
        $teamGroups = [];

        foreach ($rows as $row) {
            $team = (string) $row->team;

            if (! $this->rules->isOperationalTeam($team)) {
                continue;
            }

            $displayName = $this->displayUserName($row);
            $key = $this->userGroupKey($row, $team);
            $delegation = $row->delegation ?: LeadDelegationNormalizer::UNCLASSIFIED;
            $zone = $row->zone ?: LeadDelegationNormalizer::UNCLASSIFIED;

            if ($team === 'commercial' && (! $this->isValidOperationalLabel($delegation) || ! $this->isValidOperationalLabel($zone))) {
                continue;
            }

            $bucket = $this->bucketFromRow($row);
            $this->addAggregatedGroup($agentGroups, $key, $displayName, [
                'team' => $team,
                'team_label' => $this->teamLabel($team),
                'delegation' => $delegation,
                'zone' => $zone,
            ], $bucket, (int) $row->first_id);
            $this->addAggregatedGroup($teamGroups, $team, $this->teamLabel($team), [
                'team' => $team,
            ], $bucket, 0);
        }

        $unassigned = $this->baseFilteredQuery($filters, $period)
            ->whereRaw($teamSql." NOT IN ('commercial', 'customer_service', 'contact_center', 'appraiser')")
            ->selectRaw($this->metricsSelectSql())
            ->first();
        $unassignedBucket = $this->bucketFromRow($unassigned);
        if (($unassignedBucket['total_calls'] ?? 0) > 0) {
            $this->addAggregatedGroup($teamGroups, 'unassigned', 'Sin equipo', [
                'team' => 'unassigned',
            ], $unassignedBucket, 0);
        }

        $teams = $this->finalizeGroups($teamGroups, 'team_label');
        $order = array_flip(['commercial', 'customer_service', 'contact_center', 'appraiser', 'unassigned']);
        usort($teams, fn (array $a, array $b): int => ($order[$a['team']] ?? 99) <=> ($order[$b['team']] ?? 99));

        return [$this->finalizeGroups($agentGroups, 'user_name'), $teams];
    }

    private function zoneMetricRows(array $filters, array $period): array
    {
        return $this->zoneAndDelegationMetricRows($filters, $period)[0];
    }

    private function delegationMetricRows(array $filters, array $period): array
    {
        return $this->zoneAndDelegationMetricRows($filters, $period)[1];
    }

    /** @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>} */
    private function zoneAndDelegationMetricRows(array $filters, array $period): array
    {
        $delegationSql = $this->effectiveDelegationSql();
        $zoneSql = $this->effectiveZoneSql();

        $rows = $this->baseFilteredQuery($filters, $period)
            ->whereRaw($this->operationalTeamConditionSql())
            ->selectRaw($delegationSql.' as delegation')
            ->selectRaw($zoneSql.' as zone')
            ->selectRaw($this->metricsSelectSql())
            ->groupByRaw($delegationSql)
            ->groupByRaw($zoneSql)
            ->get();

        $zoneGroups = [];
        $delegationGroups = [];

        foreach ($rows as $row) {
            $delegation = $row->delegation ?: LeadDelegationNormalizer::UNCLASSIFIED;
            $zone = $row->zone ?: LeadDelegationNormalizer::UNCLASSIFIED;
            $bucket = $this->bucketFromRow($row);

            $this->addAggregatedGroup($zoneGroups, $zone, $zone, [], $bucket, 0);
            $this->addAggregatedGroup($delegationGroups, $delegation, $delegation, [], $bucket, 0);
        }

        return [
            $this->finalizeGroups($zoneGroups, 'zone'),
            $this->finalizeGroups($delegationGroups, 'delegation'),
        ];
    }

    private function portalMetricRows(array $filters, array $period): array
    {
        $rows = $this->baseFilteredQuery($filters, $period)
            ->where('call_origin', 'portal')
            ->where(function (QueryBuilder $query): void {
                $query->whereNull('portal_resolved')
                    ->orWhereNotIn('portal_resolved', [
                        CallPortalNormalizer::COMMERCIAL_DIRECT,
                        'Llamada directa',
                    ]);
            })
            ->selectRaw("COALESCE(NULLIF(portal_resolved, ''), 'Sin clasificar') as portal")
            ->selectRaw($this->metricsSelectSql())
            ->groupByRaw("COALESCE(NULLIF(portal_resolved, ''), 'Sin clasificar')")
            ->get();

        $groups = [];

        foreach ($rows as $row) {
            $portal = $row->portal ?: CallPortalNormalizer::UNCLASSIFIED;

            $this->addAggregatedGroup($groups, $portal, $portal, [
                'call_origin' => 'portal',
                'call_origin_label' => $this->originLabel('portal'),
            ], $this->bucketFromRow($row), 0);
        }

        return $this->finalizeGroups($groups, 'portal');
    }

    private function baseFilteredQuery(array $filters, array $period, bool $includeUser = true): QueryBuilder
    {
        return $this->applyBaseFilters(DB::table('salesforce_calls'), $filters, $period, $includeUser);
    }

    private function exclusionCount(array $filters, array $period, string $reason): int
    {
        $query = DB::table('salesforce_calls')
            ->where('created_date', '>=', $period['start'])
            ->where('created_date', '<', $period['end'])
            ->where('dashboard_exclusion_reason', $reason);
        $this->applyAccessScope($query, $filters);

        return $query->count();
    }

    /** @return array{raw_universe: int, dashboard_universe: int, missing_call_object: int, excluded_test_profile: int} */
    private function reconciliationCounts(array $filters, array $period): array
    {
        $query = DB::table('salesforce_calls')
            ->where('created_date', '>=', $period['start'])
            ->where('created_date', '<', $period['end']);
        $this->applyAccessScope($query, $filters);

        $row = $query
            ->selectRaw('COUNT(*) as raw_universe')
            ->selectRaw('COALESCE(SUM(CASE WHEN included_in_dashboard = 1 THEN 1 ELSE 0 END), 0) as dashboard_universe')
            ->selectRaw("COALESCE(SUM(CASE WHEN dashboard_exclusion_reason = 'missing_call_object' THEN 1 ELSE 0 END), 0) as missing_call_object")
            ->selectRaw('COALESCE(SUM(CASE WHEN dashboard_exclusion_reason = ? THEN 1 ELSE 0 END), 0) as excluded_test_profile', [CallClassificationRules::EXCLUDED_TEST_PROFILE_REASON])
            ->first();

        return [
            'raw_universe' => (int) ($row->raw_universe ?? 0),
            'dashboard_universe' => (int) ($row->dashboard_universe ?? 0),
            'missing_call_object' => (int) ($row->missing_call_object ?? 0),
            'excluded_test_profile' => (int) ($row->excluded_test_profile ?? 0),
        ];
    }

    private function applyBaseFilters(QueryBuilder $query, array $filters, array $period, bool $includeUser = true): QueryBuilder
    {
        $query
            ->where('included_in_dashboard', true)
            ->where('created_date', '>=', $period['start'])
            ->where('created_date', '<', $period['end']);

        if ($filters['direction'] !== '') {
            $query->where('direction', $filters['direction']);
        }

        if ($filters['status'] !== '') {
            $query->where('call_status', $filters['status']);
        }

        if ($filters['portal'] !== '') {
            $query->where('portal_resolved', $filters['portal']);
        }

        if ($filters['team'] !== '') {
            if ($filters['team'] === 'unassigned') {
                $query->whereRaw('NOT '.$this->operationalTeamConditionSql());
            } else {
                $query->whereRaw($this->effectiveTeamSql().' = ?', [$filters['team']]);
            }
        }

        if ($filters['origin'] !== '') {
            if ($filters['origin'] === 'commercial_direct') {
                $query->whereIn('call_origin', ['commercial_direct', 'switchboard']);
            } else {
                $query->where('call_origin', $filters['origin']);
            }
        }

        if ($filters['delegation'] !== '') {
            $query->whereRaw($this->effectiveDelegationSql().' = ?', [$filters['delegation']]);
        }

        if ($filters['zone'] !== '') {
            $query->whereRaw($this->effectiveZoneSql().' = ?', [$filters['zone']]);
        }

        if ($includeUser && $filters['user'] !== '') {
            $this->applyUserFilter($query, $filters, $period);
        }
        $this->applyAccessScope($query, $filters);

        return $query;
    }

    private function applyAccessScope(QueryBuilder $query, array $filters): void
    {
        if (filled($filters['access_commercial'] ?? null)) {
            $query->where('operational_user_id', $filters['access_commercial']);
        }
        if (filled($filters['access_delegation'] ?? null)) {
            $query->whereRaw($this->effectiveDelegationSql().' = ?', [$filters['access_delegation']]);
        }
        if (filled($filters['access_zone'] ?? null)) {
            $query->whereRaw($this->effectiveZoneSql().' = ?', [$filters['access_zone']]);
        }
    }

    private function applyUserFilter(QueryBuilder $query, array $filters, array $period): void
    {
        $filter = $filters['user'];
        $team = $this->teamFromUserFilter($filter);
        $identities = $this->matchingUserIdentities($filters, $period);

        if ($team !== null) {
            $query->whereRaw($this->effectiveTeamSql().' = ?', [$team]);
        }

        $query->where(function (QueryBuilder $query) use ($filter, $identities): void {
            $query
                ->where('operational_user_id', $filter)
                ->orWhere('operational_user_name', $filter)
                ->orWhere('normalized_user_key', $filter)
                ->orWhere('destination_agent_name', $filter)
                ->orWhere('owner_name', $filter);

            foreach ($identities as $identity) {
                foreach (['operational_user_id', 'operational_user_name', 'normalized_user_key', 'destination_agent_name', 'owner_name'] as $column) {
                    $value = data_get($identity, $column);

                    if (filled($value)) {
                        $query->orWhere($column, $value);
                    }
                }
            }
        });
    }

    private function matchingUserIdentities(array $filters, array $period): array
    {
        $filter = $filters['user'];
        $cacheKey = md5(json_encode([
            'filter' => $filter,
            'filters' => array_merge($filters, ['user' => '']),
            'period' => $this->periodPayload($period),
        ], JSON_UNESCAPED_UNICODE));

        if (array_key_exists($cacheKey, $this->matchingUserIdentityCache)) {
            return $this->matchingUserIdentityCache[$cacheKey];
        }

        $teamFilter = $this->teamFromUserFilter($filter);
        $lookupFilters = array_merge($filters, ['user' => '']);

        if ($teamFilter !== null && $lookupFilters['team'] === '') {
            $lookupFilters['team'] = $teamFilter;
        }

        $rows = $this->identityRowsQuery($lookupFilters, $period)->get();
        $matches = [];

        foreach ($rows as $row) {
            $team = (string) $row->team;
            $displayName = $this->displayUserName($row);
            $key = $this->userGroupKey($row, $team);

            if ($key === $filter
                || (string) $row->operational_user_id === $filter
                || (string) $row->operational_user_name === $filter
                || (string) $row->normalized_user_key === $filter
                || (string) $row->destination_agent_name === $filter
                || (string) $row->owner_name === $filter
                || $displayName === $filter
            ) {
                $matches[] = $row;
            }
        }

        return $this->matchingUserIdentityCache[$cacheKey] = $matches;
    }

    private function identityRowsQuery(?array $filters = null, ?array $period = null): QueryBuilder
    {
        $query = DB::table('salesforce_calls');

        if ($filters !== null && $period !== null) {
            $query = $this->applyBaseFilters($query, $filters, $period, false);
        } else {
            $query->where('included_in_dashboard', true);
        }

        return $query
            ->whereRaw($this->operationalTeamConditionSql())
            ->selectRaw($this->effectiveTeamSql().' as team')
            ->addSelect([
                'operational_user_id',
                'operational_user_name',
                'normalized_user_key',
                'destination_agent_name',
                'destination_agent_code',
                'owner_name',
                'owner_profile_name',
            ])
            ->selectRaw('MIN(id) as first_id')
            ->groupByRaw($this->effectiveTeamSql())
            ->groupBy([
                'operational_user_id',
                'operational_user_name',
                'normalized_user_key',
                'destination_agent_name',
                'destination_agent_code',
                'owner_name',
                'owner_profile_name',
            ])
            ->orderBy('first_id');
    }

    private function teamFromUserFilter(string $filter): ?string
    {
        if (! str_contains($filter, '|')) {
            return null;
        }

        $team = Str::before($filter, '|');

        return $this->rules->isOperationalTeam($team) ? $team : null;
    }

    private function metricsSelectSql(): string
    {
        $originSql = $this->effectiveOriginSql();
        $teamSql = $this->effectiveTeamSql();
        $commercialSql = $this->commercialMetricConditionSql();
        $answeredSql = $this->answeredConditionSql();
        $lostSql = $this->lostConditionSql();
        $overflowSql = $this->overflowConditionSql();
        $overflowDenominatorSql = $this->overflowDenominatorConditionSql();

        return implode(",\n", [
            'COUNT(*) as total_calls',
            "SUM(CASE WHEN {$originSql} = 'commercial_direct' THEN 1 ELSE 0 END) as commercial_direct_calls",
            "SUM(CASE WHEN {$originSql} = 'commercial_direct' AND {$answeredSql} THEN 1 ELSE 0 END) as commercial_direct_answered",
            "SUM(CASE WHEN {$originSql} = 'commercial_direct' AND {$lostSql} THEN 1 ELSE 0 END) as commercial_direct_lost",
            "SUM(CASE WHEN {$originSql} = 'portal' THEN 1 ELSE 0 END) as portal_calls",
            "SUM(CASE WHEN {$originSql} = 'portal' AND {$answeredSql} THEN 1 ELSE 0 END) as portal_answered",
            "SUM(CASE WHEN {$originSql} = 'portal' AND {$lostSql} THEN 1 ELSE 0 END) as portal_lost",
            "SUM(CASE WHEN {$answeredSql} THEN 1 ELSE 0 END) as answered",
            "SUM(CASE WHEN {$lostSql} THEN 1 ELSE 0 END) as not_answered",
            "SUM(CASE WHEN direction = 'inbound' THEN 1 ELSE 0 END) as inbound",
            "SUM(CASE WHEN direction = 'outbound' THEN 1 ELSE 0 END) as outbound",
            "SUM(CASE WHEN direction = 'unknown' THEN 1 ELSE 0 END) as unknown_direction",
            "SUM(CASE WHEN {$answeredSql} AND {$commercialSql} THEN 1 ELSE 0 END) as answered_commercial",
            "SUM(CASE WHEN {$answeredSql} AND {$teamSql} = 'customer_service' THEN 1 ELSE 0 END) as answered_customer_service",
            "SUM(CASE WHEN {$answeredSql} AND {$teamSql} = 'contact_center' THEN 1 ELSE 0 END) as answered_contact_center",
            "SUM(CASE WHEN {$answeredSql} AND {$teamSql} = 'appraiser' THEN 1 ELSE 0 END) as answered_appraiser",
            "SUM(CASE WHEN {$answeredSql} AND adjusted_duration_seconds IS NOT NULL THEN adjusted_duration_seconds ELSE 0 END) as adjusted_duration_answered_sum",
            "SUM(CASE WHEN {$answeredSql} AND adjusted_duration_seconds IS NOT NULL THEN 1 ELSE 0 END) as answered_duration_count",
            "SUM(CASE WHEN {$overflowSql} THEN 1 ELSE 0 END) as overflow_count",
            "SUM(CASE WHEN {$overflowDenominatorSql} THEN 1 ELSE 0 END) as overflow_denominator",
            "AVG(CASE WHEN {$answeredSql} THEN adjusted_duration_seconds ELSE NULL END) as avg_talk_seconds",
        ]);
    }

    private function answeredConditionSql(): string
    {
        return "({$this->notAbandonedConditionSql()} AND (COALESCE(call_status = 'answered', 0) OR COALESCE(is_answered, 0) = 1))";
    }

    private function lostConditionSql(): string
    {
        $answeredSql = $this->answeredConditionSql();

        return "((NOT {$answeredSql}) OR call_status = 'not_answered' OR COALESCE(is_lost, 0) = 1)";
    }

    private function overflowConditionSql(): string
    {
        return 'COALESCE(is_overflow, 0) = 1';
    }

    private function overflowDenominatorConditionSql(): string
    {
        $originSql = $this->effectiveOriginSql();
        $answeredSql = $this->answeredConditionSql();
        $portalSql = $this->overflowPortalKeySql();
        $pollSql = $this->overflowPollConditionSql();

        return "({$originSql} = 'portal'
            AND {$answeredSql}
            AND (({$portalSql} NOT IN ('web', 'google maps')) OR {$pollSql}))";
    }

    private function overflowPortalKeySql(): string
    {
        return "LOWER(TRIM(COALESCE(portal_resolved, '')))";
    }

    private function overflowPollConditionSql(): string
    {
        return "(poll_value IS NULL OR TRIM(COALESCE(poll_value, '')) IN ('', '1', '2'))";
    }

    private function notAbandonedConditionSql(): string
    {
        return "UPPER(TRIM(COALESCE(result_raw, ''))) <> 'ABANDONED'";
    }

    private function effectiveOriginSql(): string
    {
        return "CASE
            WHEN call_origin = 'switchboard' THEN 'commercial_direct'
            WHEN call_origin IS NULL OR TRIM(call_origin) = '' THEN 'commercial_direct'
            ELSE call_origin
        END";
    }

    private function effectiveTeamSql(): string
    {
        $nameSql = $this->effectiveUserNameSql();
        $compactNameSql = "REPLACE(REPLACE(REPLACE({$nameSql}, ' ', ''), '-', ''), '.', '')";

        return "CASE
            WHEN owner_profile_name LIKE '%System Administrator%' OR owner_profile_name LIKE '%Administrator%' THEN 'system'
            WHEN {$nameSql} LIKE '%palomo%' THEN 'contact_center'
            WHEN {$compactNameSql} IN ('carlossoria') THEN 'system'
            WHEN {$compactNameSql} IN (
                'vanessasanjuan',
                'vanesasanjuan',
                'callcenterfontellas'
            ) THEN 'customer_service'
            WHEN {$compactNameSql} IN (
                'yuleidisgarcia',
                'mariavidal',
                'mariavidalperez',
                'vanesagerman',
                'joseignaciopalomo',
                'josepalomocasas',
                'joseignaciopalomocasas',
                'nurialarrosa'
            ) THEN 'contact_center'
            WHEN operational_team IN ('commercial', 'customer_service', 'contact_center', 'appraiser', 'system', 'unassigned') THEN operational_team
            ELSE 'unassigned'
        END";
    }

    private function effectiveDelegationSql(): string
    {
        $teamSql = $this->effectiveTeamSql();

        return "CASE
            WHEN {$teamSql} = 'customer_service' AND delegation = ".$this->sqlString(CallClassificationRules::CUSTOMER_SERVICE_LABEL).' THEN '.$this->sqlString(LeadDelegationNormalizer::UNCLASSIFIED)."
            WHEN {$teamSql} = 'contact_center' AND delegation = ".$this->sqlString(CallClassificationRules::CONTACT_CENTER_LABEL).' THEN '.$this->sqlString(LeadDelegationNormalizer::UNCLASSIFIED)."
            WHEN {$teamSql} = 'appraiser' AND delegation = ".$this->sqlString(CallClassificationRules::APPRAISER_LABEL).' THEN '.$this->sqlString(LeadDelegationNormalizer::UNCLASSIFIED)."
            ELSE COALESCE(NULLIF(delegation, ''), ".$this->sqlString(LeadDelegationNormalizer::UNCLASSIFIED).')
        END';
    }

    private function effectiveZoneSql(): string
    {
        $teamSql = $this->effectiveTeamSql();

        return "CASE
            WHEN {$teamSql} = 'customer_service' AND zone = ".$this->sqlString(CallClassificationRules::CUSTOMER_SERVICE_LABEL).' THEN '.$this->sqlString(LeadDelegationNormalizer::UNCLASSIFIED)."
            WHEN {$teamSql} = 'contact_center' AND zone = ".$this->sqlString(CallClassificationRules::CONTACT_CENTER_LABEL).' THEN '.$this->sqlString(LeadDelegationNormalizer::UNCLASSIFIED)."
            WHEN {$teamSql} = 'appraiser' AND zone = ".$this->sqlString(CallClassificationRules::APPRAISER_LABEL).' THEN '.$this->sqlString(LeadDelegationNormalizer::UNCLASSIFIED)."
            ELSE COALESCE(NULLIF(zone, ''), ".$this->sqlString(LeadDelegationNormalizer::UNCLASSIFIED).')
        END';
    }

    private function operationalTeamConditionSql(): string
    {
        $teamSql = $this->effectiveTeamSql();

        return "({$teamSql} IN ('commercial', 'customer_service', 'contact_center', 'appraiser')
            AND {$this->commercialOperationalConditionSql()})";
    }

    private function commercialOperationalConditionSql(): string
    {
        $teamSql = $this->effectiveTeamSql();

        return "({$teamSql} <> 'commercial'
            OR {$this->validCommercialLabelsConditionSql()})";
    }

    private function commercialMetricConditionSql(): string
    {
        return "({$this->effectiveTeamSql()} = 'commercial'
            AND {$this->validCommercialLabelsConditionSql()})";
    }

    private function validCommercialLabelsConditionSql(): string
    {
        return "({$this->validOperationalLabelConditionSql('delegation')}
            AND {$this->validOperationalLabelConditionSql('zone')})";
    }

    private function validOperationalLabelConditionSql(string $labelSql): string
    {
        $unclassified = $this->sqlString(LeadDelegationNormalizer::UNCLASSIFIED);

        return "({$labelSql} IS NOT NULL AND TRIM({$labelSql}) <> '' AND {$labelSql} <> {$unclassified})";
    }

    private function isValidOperationalLabel(?string $label): bool
    {
        $label = trim((string) $label);

        return $label !== '' && $label !== LeadDelegationNormalizer::UNCLASSIFIED;
    }

    private function effectiveUserNameSql(): string
    {
        return "LOWER(TRIM(COALESCE(
            NULLIF(normalized_user_key, ''),
            NULLIF(operational_user_name, ''),
            NULLIF(destination_agent_name, ''),
            NULLIF(owner_name, ''),
            ''
        )))";
    }

    private function preferredLabelSql(string $labelSql): string
    {
        return 'COALESCE(MAX(NULLIF('.$labelSql.', '.$this->sqlString(LeadDelegationNormalizer::UNCLASSIFIED).')), '.$this->sqlString(LeadDelegationNormalizer::UNCLASSIFIED).')';
    }

    private function sqlString(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    private function bucketFromRow(mixed $row): array
    {
        $bucket = $this->aggregator->emptyBucket();

        foreach (array_keys($bucket) as $key) {
            $bucket[$key] = (int) (data_get($row, $key, 0) ?? 0);
        }

        return $bucket;
    }

    private function finalizeBucket(array $bucket): array
    {
        $result = $this->aggregator->finalize(array_merge($this->aggregator->emptyBucket(), $bucket));
        $result['avg_talk_seconds'] = $result['average_talk_seconds'];
        $result['attention_rate'] = $result['answered_pct'];
        $result['direct_answered'] = $result['commercial_direct_answered'];
        $result['direct_lost'] = $result['commercial_direct_lost'];
        $result['inbound_calls'] = $result['inbound'];
        $result['outbound_calls'] = $result['outbound'];
        $result['answered_by_commercial'] = $result['answered_commercial'];
        $result['answered_by_customer_service'] = $result['answered_customer_service'];
        $result['answered_by_contact_center'] = $result['answered_contact_center'];
        $result['answered_by_appraiser'] = $result['answered_appraiser'];
        $result['overflow_count'] = $result['overflow_count'] ?? 0;
        $result['overflows'] = $result['overflow_count'];

        return $result;
    }

    private function summaryCharts(array $filters, array $period, array $bucket, array $teams): array
    {
        return [
            'answered_vs_lost' => [
                ['label' => 'Atendidas', 'value' => $bucket['answered']],
                ['label' => 'Perdidas', 'value' => $bucket['not_answered']],
            ],
            'direct_vs_portal' => [
                ['label' => 'Directas a comercial', 'value' => $bucket['commercial_direct_calls']],
                ['label' => 'Portales', 'value' => $bucket['portal_calls']],
            ],
            'answered_by_team' => collect($teams)
                ->filter(fn (array $row): bool => ($row['answered'] ?? 0) > 0)
                ->map(fn (array $row): array => [
                    'label' => $row['team_label'],
                    'value' => $row['answered'],
                ])
                ->values()
                ->all(),
            'daily_evolution' => $this->dailyEvolutionRows($filters, $period),
        ];
    }

    private function summaryRankings(array $filters, array $period, array $portals, array $agents): array
    {
        $delegations = $this->delegationRankingRows($filters, $period);

        $commercials = array_values(array_filter(
            $agents,
            function (array $row): bool {
                return ($row['team'] ?? $row['team_type'] ?? null) === 'commercial';
            }
        ));

        return [
            'top_portals_by_calls' => $this->topRows($portals, 'total_calls'),
            'top_portals_by_lost' => $this->topRows($portals, 'not_answered'),
            'top_agents_by_answered' => $this->topRows($agents, 'answered'),
            'top_teams_by_answered' => [],
            'top_commercials_by_calls' => $this->topRows($commercials, 'total_calls'),
            'top_commercials_by_answered' => $this->topRows($commercials, 'answered'),
            'top_delegations_by_calls' => $this->topRows($delegations, 'total_calls'),
            'top_delegations_by_lost' => $this->topRows($delegations, 'not_answered'),
            'top_overflows_by_portal' => $this->topRows($portals, 'overflow_count'),
        ];
    }

    private function delegationRankingRows(array $filters, array $period): array
    {
        $delegationSql = $this->effectiveDelegationSql();

        return $this->baseFilteredQuery($filters, $period)
            ->whereRaw($this->operationalTeamConditionSql())
            ->whereRaw($this->validOperationalLabelConditionSql($delegationSql))
            ->selectRaw($delegationSql.' as delegation')
            ->selectRaw('COUNT(*) as total_calls')
            ->selectRaw("SUM(CASE WHEN call_status = 'answered' THEN 1 ELSE 0 END) as answered")
            ->selectRaw("SUM(CASE WHEN call_status = 'answered' THEN 0 ELSE 1 END) as not_answered")
            ->groupByRaw($delegationSql)
            ->get()
            ->map(fn (mixed $row) => [
                'delegation' => (string) $row->delegation,
                'total_calls' => (int) $row->total_calls,
                'answered' => (int) $row->answered,
                'not_answered' => (int) $row->not_answered,
            ])
            ->values()
            ->all();
    }

    private function dailyEvolutionRows(array $filters, array $period): array
    {
        $rows = $this->baseFilteredQuery($filters, $period)
            ->selectRaw('DATE(created_date) as date')
            ->selectRaw($this->metricsSelectSql())
            ->groupByRaw('DATE(created_date)')
            ->orderBy('date')
            ->get();

        return $rows
            ->map(fn (mixed $row) => array_merge($this->finalizeBucket($this->bucketFromRow($row)), [
                'date' => (string) $row->date,
            ]))
            ->values()
            ->all();
    }

    private function topRows(array $rows, string $metric, int $limit = 5): array
    {
        return collect($rows)
            ->filter(fn (array $row) => (int) ($row[$metric] ?? 0) > 0)
            ->sortByDesc(fn (array $row) => (int) ($row[$metric] ?? 0))
            ->take($limit)
            ->values()
            ->all();
    }

    private function addAggregatedGroup(array &$groups, string $key, string $label, array $extra, array $bucket, int $firstId): void
    {
        if (! isset($groups[$key])) {
            $groups[$key] = [
                'label' => $label,
                'extra' => $extra,
                'bucket' => $this->aggregator->emptyBucket(),
                'first_id' => $firstId,
            ];
        }

        foreach ($bucket as $metric => $value) {
            $groups[$key]['bucket'][$metric] = ($groups[$key]['bucket'][$metric] ?? 0) + (int) $value;
        }
    }

    private function finalizeGroups(array $groups, string $labelKey): array
    {
        $rows = [];

        foreach ($groups as $group) {
            $rows[] = array_merge($group['extra'], $this->finalizeBucket($group['bucket']), [
                $labelKey => $group['label'],
                'nombre' => $group['label'],
            ]);
        }

        usort($rows, fn (array $a, array $b) => [-(int) ($a['total_calls'] ?? 0), Str::ascii((string) ($a[$labelKey] ?? ''))]
            <=> [-(int) ($b['total_calls'] ?? 0), Str::ascii((string) ($b[$labelKey] ?? ''))]);

        return array_values($rows);
    }

    private function filterAgentsByTeam(array $agents, string $team): array
    {
        return array_values(array_filter($agents, function (array $agent) use ($team): bool {
            if (($agent['team'] ?? null) !== $team) {
                return false;
            }

            if ($team !== 'commercial') {
                return true;
            }

            return $this->isValidOperationalLabel($agent['delegation'] ?? null)
                && $this->isValidOperationalLabel($agent['zone'] ?? null);
        }));
    }

    private function userGroupKey(mixed $call, string $team): string
    {
        return $this->rules->userGroupKey(
            $team,
            data_get($call, 'operational_user_id'),
            data_get($call, 'normalized_user_key') ?: $this->displayUserName($call),
            data_get($call, 'destination_agent_name'),
            data_get($call, 'owner_name'),
            data_get($call, 'owner_profile_name'),
        );
    }

    private function displayUserName(mixed $call): string
    {
        $mapped = $this->mappedAgentName($call);

        if ($mapped !== null) {
            return $this->rules->canonicalUserName($mapped);
        }

        return $this->rules->canonicalUserName(
            data_get($call, 'operational_user_name'),
            data_get($call, 'destination_agent_name'),
            data_get($call, 'owner_name'),
        );
    }

    private function mappedAgentName(mixed $call): ?string
    {
        $names = $this->agentDisplayNames();
        $code = filled(data_get($call, 'destination_agent_code')) ? Str::upper(data_get($call, 'destination_agent_code')) : null;

        if ($code && isset($names['by_code'][$code])) {
            return $names['by_code'][$code];
        }

        foreach ([data_get($call, 'operational_user_name'), data_get($call, 'destination_agent_name'), data_get($call, 'owner_name')] as $candidate) {
            $key = $this->rules->normalizeName($candidate);

            if ($key !== '' && isset($names['by_name'][$key])) {
                return $names['by_name'][$key];
            }
        }

        return null;
    }

    private function agentDisplayNames(): array
    {
        if ($this->agentDisplayNames !== null) {
            return $this->agentDisplayNames;
        }

        $byCode = [];
        $byName = [];

        DB::table('call_agent_mappings')
            ->where('active', true)
            ->get()
            ->each(function (mixed $mapping) use (&$byCode, &$byName): void {
                if (filled($mapping->agent_code)) {
                    $byCode[Str::upper($mapping->agent_code)] = $mapping->user_name;
                }

                $key = $mapping->normalized_name ?: $this->rules->normalizeName($mapping->user_name);

                if ($key !== '') {
                    $byName[$key] = $mapping->user_name;
                }
            });

        return $this->agentDisplayNames = ['by_code' => $byCode, 'by_name' => $byName];
    }

    private function comparison(array $current, array $previous): array
    {
        $metrics = [
            ['key' => 'total_calls', 'label' => 'Total llamadas'],
            ['key' => 'answered', 'label' => 'Atendidas'],
            ['key' => 'not_answered', 'label' => 'No atendidas'],
            ['key' => 'overflow_count', 'label' => 'Desbordes'],
            ['key' => 'inbound', 'label' => 'Entrantes'],
            ['key' => 'outbound', 'label' => 'Salientes'],
            ['key' => 'average_talk_seconds', 'label' => 'Tiempo medio conversacion', 'seconds' => true],
        ];

        return array_map(fn (array $metric) => [
            'key' => $metric['key'],
            'metrica' => $metric['label'],
            'periodo_actual' => $current[$metric['key']] ?? 0,
            'periodo_comparado' => $previous[$metric['key']] ?? 0,
            'diferencia' => round(($current[$metric['key']] ?? 0) - ($previous[$metric['key']] ?? 0), 2),
            'is_seconds' => (bool) ($metric['seconds'] ?? false),
        ], $metrics);
    }

    private function insights(array $current, array $previous, array $rankings): array
    {
        if ($current['total_calls'] === 0) {
            return ['No hay llamadas sincronizadas para el periodo seleccionado.'];
        }

        $insights = [];
        $lostPortal = $rankings['top_portals_by_lost'][0] ?? null;
        $answeredTeam = $rankings['top_teams_by_answered'][0] ?? null;
        $overflowPortal = $rankings['top_overflows_by_portal'][0] ?? null;

        if ($lostPortal !== null) {
            $insights[] = 'El portal '.$lostPortal['portal'].' concentra el mayor volumen de llamadas perdidas: '.$lostPortal['not_answered'].'.';
        }

        if ($answeredTeam !== null) {
            $insights[] = 'El equipo '.$answeredTeam['team_label'].' atiende el mayor volumen de llamadas: '.$answeredTeam['answered'].'.';
        }

        if ($overflowPortal !== null) {
            $insights[] = 'El portal '.$overflowPortal['portal'].' genera mas desbordes: '.$overflowPortal['overflow_count'].'.';
        }

        if ($current['commercial_direct_calls'] > 0 && $current['portal_calls'] > 0) {
            $directRate = $current['commercial_direct_answered_pct'];
            $portalRate = $current['portal_answered_pct'];
            $better = $directRate >= $portalRate ? 'directas a comercial' : 'de portal';
            $insights[] = 'Las llamadas '.$better.' tienen mejor ratio de atencion (directas '.$directRate.'%, portales '.$portalRate.'%).';
        }

        if ($previous['total_calls'] > 0) {
            $deltaLost = round($current['not_answered_pct'] - $previous['not_answered_pct'], 2);
            $trend = $deltaLost > 0 ? 'aumenta' : ($deltaLost < 0 ? 'disminuye' : 'se mantiene');
            $insights[] = 'El porcentaje de llamadas perdidas '.$trend.' frente al periodo comparado ('.$this->signedPercent($deltaLost).' pp).';
        }

        if ($previous['average_talk_seconds'] > 0) {
            $deltaSeconds = round($current['average_talk_seconds'] - $previous['average_talk_seconds'], 2);
            $trend = $deltaSeconds > 0 ? 'sube' : ($deltaSeconds < 0 ? 'baja' : 'se mantiene');
            $insights[] = 'El tiempo medio de conversacion '.$trend.' frente al periodo comparado ('.$this->secondsText(abs($deltaSeconds)).').';
        }

        return $insights ?: [
            'Atendidas: '.$current['answered'].' de '.$current['total_calls'].' llamadas.',
            'No atendidas o perdidas: '.$current['not_answered'].'.',
            'Desbordes: '.$current['overflow_count'].'.',
        ];
    }

    private function filters(Request $request): array
    {
        $origin = $request->string('origin')->toString();
        if ($origin === 'switchboard') {
            $origin = 'commercial_direct';
        }

        $period = $request->string('period')->toString() ?: 'last_30_days';
        $period = match ($period) {
            'month_current' => 'current_month',
            'month_previous' => 'previous_month',
            default => $period,
        };

        return [
            'period' => $period,
            'current_start' => $request->string('current_start')->toString(),
            'current_end' => $request->string('current_end')->toString(),
            'comparison_start' => $request->string('comparison_start')->toString(),
            'comparison_end' => $request->string('comparison_end')->toString(),
            'team' => $request->string('team')->toString(),
            'direction' => $request->string('direction')->toString(),
            'status' => $request->string('status')->toString(),
            'origin' => $origin,
            'delegation' => $request->string('delegation')->toString(),
            'zone' => $request->string('zone')->toString(),
            'portal' => $request->string('portal')->toString(),
            'user' => $request->string('user')->toString(),
            'access_commercial' => ReportUserAccess::salesforceUserId($request),
            'access_delegation' => ReportUserAccess::delegationName($request),
            'access_zone' => ReportUserAccess::isAreaManager($request) ? ReportUserAccess::areaZoneLabel($request) : null,
        ];
    }

    private function periods(array $filters): array
    {
        $now = CarbonImmutable::now();

        if ($filters['period'] === 'custom') {
            $currentStart = $this->parseDate($filters['current_start'], $now->subDays(30))->startOfDay();
            $currentEnd = $this->parseDate($filters['current_end'], $now)->endOfDay();
            $comparisonStart = $this->parseDate($filters['comparison_start'], $currentStart->subDays((int) floor($currentStart->diffInDays($currentEnd)) + 1))->startOfDay();
            $comparisonEnd = $this->parseDate($filters['comparison_end'], $currentStart->subDay())->endOfDay();

            return [
                'current' => ['start' => $currentStart, 'end' => $currentEnd],
                'previous' => ['start' => $comparisonStart, 'end' => $comparisonEnd],
            ];
        }

        if ($filters['period'] === 'current_month') {
            $currentStart = $now->startOfMonth();

            return [
                'current' => ['start' => $currentStart, 'end' => $now],
                'previous' => ['start' => $currentStart->subMonthNoOverflow(), 'end' => $currentStart->subDay()->endOfDay()],
            ];
        }

        if ($filters['period'] === 'previous_month') {
            $currentStart = $now->subMonthNoOverflow()->startOfMonth();

            return [
                'current' => ['start' => $currentStart, 'end' => $currentStart->endOfMonth()],
                'previous' => ['start' => $currentStart->subMonthNoOverflow()->startOfMonth(), 'end' => $currentStart->subMonthNoOverflow()->endOfMonth()],
            ];
        }

        return [
            'current' => ['start' => $now->subDays(30), 'end' => $now],
            'previous' => ['start' => $now->subDays(60), 'end' => $now->subDays(30)],
        ];
    }

    private function filterOptions(?ReportServerTiming $timing = null): array
    {
        $key = 'calls-dashboard:filters:'.md5((string) $this->dataVersion());
        $cached = Cache::get($key);

        if (is_array($cached)) {
            $timing?->mark('calls-filters-hit');

            return $cached;
        }

        $timing?->mark('calls-filters-miss');

        return Cache::remember(
            $key,
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn () => [
                'teams' => collect(['commercial', 'customer_service', 'contact_center', 'appraiser', 'unassigned'])
                    ->map(fn (string $id) => ['id' => $id, 'name' => $this->teamLabel($id)])
                    ->all(),
                'directions' => [
                    ['id' => 'inbound', 'name' => 'Entrante'],
                    ['id' => 'outbound', 'name' => 'Saliente'],
                    ['id' => 'unknown', 'name' => 'Sin clasificar'],
                ],
                'statuses' => [
                    ['id' => 'answered', 'name' => 'Atendida'],
                    ['id' => 'not_answered', 'name' => 'No atendida'],
                ],
                'origins' => [
                    ['id' => 'commercial_direct', 'name' => 'Llamada directa a comercial'],
                    ['id' => 'portal', 'name' => 'Portal / Procedencia'],
                ],
                'delegations' => $this->sortLabels($this->distinctColumnValues('delegation')),
                'zones' => $this->sortLabels(collect($this->delegationNormalizer->knownZones())
                    ->merge($this->distinctColumnValues('zone'))
                    ->all()),
                'portals' => $this->sortLabels(DB::table('salesforce_calls')
                    ->where('included_in_dashboard', true)
                    ->where('call_origin', 'portal')
                    ->where(function (QueryBuilder $query): void {
                        $query->whereNull('portal_resolved')
                            ->orWhereNotIn('portal_resolved', [
                                CallPortalNormalizer::COMMERCIAL_DIRECT,
                                'Llamada directa',
                            ]);
                    })
                    ->distinct()
                    ->pluck('portal_resolved')
                    ->filter()
                    ->all()),
                'users' => $this->userFilterOptions(),
            ]
        );
    }

    private function distinctColumnValues(string $column): array
    {
        return DB::table('salesforce_calls')
            ->selectRaw($column.' as label')
            ->where('included_in_dashboard', true)
            ->whereNotNull($column)
            ->where($column, '<>', '')
            ->groupBy($column)
            ->orderBy($column)
            ->pluck('label')
            ->filter()
            ->all();
    }

    private function userFilterOptions(): array
    {
        $options = [];

        foreach ($this->identityRowsQuery()->get() as $row) {
            $team = (string) $row->team;

            if (! $this->rules->isOperationalTeam($team)) {
                continue;
            }

            $key = $this->userGroupKey($row, $team);

            $options[$key] ??= [
                'id' => $key,
                'name' => $this->displayUserName($row),
            ];
        }

        return collect($options)
            ->sortBy(fn (array $user) => Str::ascii($user['name']))
            ->values()
            ->all();
    }

    private function sortLabels(iterable $labels): array
    {
        return collect($labels)
            ->filter()
            ->unique()
            ->sortBy(fn (string $label) => $label === LeadDelegationNormalizer::UNCLASSIFIED ? 'zzzzzz' : Str::ascii($label))
            ->values()
            ->all();
    }

    private function teamLabel(?string $team): string
    {
        return match ($team) {
            'commercial' => 'Comerciales',
            'customer_service' => 'Atencion al Cliente',
            'contact_center' => 'Contact Center',
            'appraiser' => 'Tasadores',
            'unassigned' => 'Sin equipo',
            'system' => 'Sistema / Sin agente',
            default => 'Sin clasificar',
        };
    }

    private function originLabel(?string $origin): string
    {
        return match ($origin) {
            'commercial_direct' => 'Llamada directa a comercial',
            default => 'Portal / Procedencia',
        };
    }

    private function secondsText(int|float $seconds): string
    {
        $seconds = (int) round($seconds);

        return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    private function signedPercent(float $value): string
    {
        return ($value > 0 ? '+' : '').number_format($value, 2, '.', '');
    }

    private function dataVersion(): int
    {
        return (int) Cache::get('salesforce_calls_dashboard_cache_version', 1);
    }

    private function periodPayload(array $period): array
    {
        return [
            'inicio' => CarbonImmutable::parse($period['start'])->toDateString(),
            'fin' => CarbonImmutable::parse($period['end'])->toDateString(),
        ];
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

    private function lastUpdated(): ?CarbonImmutable
    {
        $updated = DB::table('salesforce_calls')->max('updated_at');

        return $updated ? CarbonImmutable::parse($updated) : null;
    }
}
