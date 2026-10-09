<?php

namespace App\Services\Campaigns;

use App\Models\ReportSyncRun;
use App\Models\SalesforceOpportunity;
use App\Services\Reports\Leads\LeadDelegationNormalizer;
use App\Services\Reports\Leads\LeadRecordTypeNormalizer;
use App\Services\Reports\ReservationsSales\OpportunityInterestAttributionService;
use App\Services\Salesforce\SalesforceInterestSyncService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CampaignAttributionBuilderService
{
    private const ATTRIBUTION_RULE_VERSION = '2026-09-03.1';

    private const INTEREST_CHUNK_SIZE = 1000;

    private const UPSERT_CHUNK_SIZE = 25;

    private const OPPORTUNITY_LOOKUP_CHUNK_SIZE = 500;

    private const UNRESOLVED_WRITE_CHUNK_SIZE = 500;

    public function __construct(
        private readonly CampaignValueNormalizer $normalizer,
        private readonly CampaignSaleAmountResolver $saleAmountResolver,
        private readonly CampaignTypeResolver $campaignTypeResolver,
        private readonly LeadRecordTypeNormalizer $leadRecordTypeNormalizer,
        private readonly LeadDelegationNormalizer $delegationNormalizer,
        private readonly OpportunityInterestAttributionService $opportunityInterestAttribution,
    ) {}

    public function build(CarbonInterface $start, CarbonInterface $end, bool $dryRun = false): array
    {
        $startedAt = microtime(true);
        $start = CarbonImmutable::parse($start)->setTimezone('Europe/Madrid')->startOfDay();
        $end = CarbonImmutable::parse($end)->setTimezone('Europe/Madrid');
        $interestContext = $this->interestSourceContext();
        $opportunityInterestContext = $this->opportunityInterestAttribution->capture();
        $metrics = $this->metricLookup($start, $end);
        $stats = $this->emptyStats($start, $end);
        $stats['interest_sync_run_id'] = $interestContext['id'];
        $stats['interest_sync_cutoff_at'] = $interestContext['cutoff'];
        $stats['opportunity_interest_context'] = $opportunityInterestContext;
        $interests = $this->candidateInterests($start, $end, $stats);
        $opportunities = $this->candidateOpportunities($interests);
        $now = now();
        $currentRows = $dryRun ? $this->currentAttributions($start, $end) : [];
        $simulatedRows = [];

        if (! $dryRun) {
            DB::beginTransaction();
        }

        try {
            if (! $dryRun) {
                DB::table('campaign_attributions')->where('interest_functional_created_at', '>=', $start->utc())->where('interest_functional_created_at', '<', $end->utc())->delete();
                DB::table('campaign_lead_attributions')->where('interest_functional_created_at', '>=', $start->utc())->where('interest_functional_created_at', '<', $end->utc())->delete();
            }

            $assignments = $this->assignOpportunities(
                $interests,
                $opportunities,
                $opportunityInterestContext,
                $this->claimedOpportunityIds($dryRun ? $start : null, $dryRun ? $end : null),
            );
            $campaignAttributionBatch = [];
            $leadAttributionBatch = [];

            foreach ($interests as $lead) {
                $campaign = $this->resolveCampaign($lead, $metrics);
                $primaryAssignment = $assignments['primary'][(string) $lead->salesforce_id] ?? null;
                $leadAssignments = $assignments['detail'][(string) $lead->salesforce_id] ?? [];
                $primaryRow = $this->makeAttributionRow($lead, $campaign, $primaryAssignment, $interestContext, $now);
                $simulatedRows[] = $primaryRow;

                $this->countCampaignMatch($stats, $campaign);

                if (! $dryRun) {
                    $campaignAttributionBatch[] = $primaryRow;
                }

                if ($leadAssignments === []) {
                    if (! $dryRun) {
                        $leadAttributionBatch[] = $primaryRow;
                    }
                } else {
                    foreach ($leadAssignments as $assignment) {
                        if (! $dryRun) {
                            $leadAttributionBatch[] = $this->makeAttributionRow($lead, $campaign, $assignment, $interestContext, $now);
                        }
                    }
                }

                $stats['saved_attributions']++;
                $stats['opportunities'] += $primaryRow['has_opportunity'] ? 1 : 0;
                $stats['reservations'] += $primaryRow['has_reservation'] ? 1 : 0;
                $stats['fallen_reservations'] += $primaryRow['has_fallen_reservation'] ? 1 : 0;
                $stats['sales'] += $primaryRow['has_sale'] ? 1 : 0;
                $this->countSaleAmountStats($stats, $primaryAssignment['opportunity'] ?? null, [
                    'has_opportunity' => $primaryRow['has_opportunity'],
                    'has_reservation' => $primaryRow['has_reservation'],
                    'has_fallen_reservation' => $primaryRow['has_fallen_reservation'],
                    'has_sale' => $primaryRow['has_sale'],
                    'has_purchase' => $primaryRow['has_purchase'],
                    'sale_amount' => $primaryRow['sale_amount'],
                ]);

                if (! $dryRun && (count($campaignAttributionBatch) >= self::UPSERT_CHUNK_SIZE || count($leadAttributionBatch) >= (self::UPSERT_CHUNK_SIZE * 4))) {
                    $this->flushAttributions($campaignAttributionBatch, $leadAttributionBatch);
                    $campaignAttributionBatch = [];
                    $leadAttributionBatch = [];
                }
            }

            if (! $dryRun) {
                $this->flushAttributions($campaignAttributionBatch, $leadAttributionBatch);
                $this->validateInterestSourceContext($interestContext);
                $this->opportunityInterestAttribution->validate($opportunityInterestContext);
                DB::commit();
            }
        } catch (Throwable $exception) {
            if (! $dryRun) {
                DB::rollBack();
            }
            throw $exception;
        }

        if ($stats['salesforce_only'] > 0) {
            $stats['warnings'][] = 'Hay campanas Salesforce sin inversion asociada o procedencias sin coste. Revisar IDs/nombres de campana.';
        }

        if ($stats['sales'] > 0 && ! $this->saleAmountResolver->preferredColumnExists()) {
            $stats['warnings'][] = $this->saleAmountResolver->diagnosticMessage();
        }

        $stats['sale_amount_field_used'] = $stats['sales_with_opo_for_importe_total'] > 0
            ? 'opo_for_importe_total'
            : ($stats['sales_with_amount'] > 0 ? 'amount' : 'none');
        $stats['sale_amount_sum'] = round((float) $stats['sale_amount_sum'], 2);
        $stats['duration_seconds'] = round(microtime(true) - $startedAt, 2);
        $stats['peak_memory_mb'] = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
        $stats = array_merge($stats, $this->topDiagnostics($start, $end));

        if ($stats['total_interests_in_range'] === 0 && ($stats['top_platform_spend'] ?? []) !== []) {
            $stats['warnings'][] = sprintf(
                'No hay Interests en salesforce_interests entre %s y %s. Revisa el snapshot F2 local antes de reconstruir.',
                $start->toDateString(),
                $end->subDay()->toDateString(),
            );
        }

        if ($dryRun) {
            $stats['dry_run'] = true;
            $stats['simulation'] = $this->simulationSummary($interests, $currentRows, $simulatedRows);
            $this->validateInterestSourceContext($interestContext);
            $this->opportunityInterestAttribution->validate($opportunityInterestContext);
        } else {
            $this->invalidateCache();
        }

        return $stats;
    }

    private function candidateInterests(CarbonInterface $start, CarbonInterface $end, array &$stats): Collection
    {
        $base = DB::table('salesforce_interests')
            ->where('is_deleted', false)
            ->where('functional_created_at', '>=', CarbonImmutable::parse($start)->utc())
            ->where('functional_created_at', '<', CarbonImmutable::parse($end)->utc());
        $stats['total_interests_in_range'] = (clone $base)->count();
        $interests = collect();

        (clone $base)->orderBy('id')->select([
            'id', 'salesforce_id', DB::raw('functional_created_at as created_date'), 'status',
            DB::raw('type as record_type_name'), DB::raw('owner_salesforce_id as owner_id'), 'owner_name',
            DB::raw('source as fuente_origen'), DB::raw('medium as medio_origen'),
            DB::raw('utm_campaign as campaign_acquired'), DB::raw('utm_id as acquired_id'),
            DB::raw('utm_content as content_acquired'), DB::raw('utm_source as source_acquired'),
            DB::raw('utm_medium as medium_acquired'), DB::raw('account_salesforce_id as converted_account_id'),
            DB::raw('inverse_opportunity_salesforce_id as converted_opportunity_id'), 'origin_delegation',
            'source', 'original_source', 'medium', 'channel', 'utm_term',
            'sale_vehicle_salesforce_id', 'appraisal_vehicle_salesforce_id', 'is_deleted',
        ])->chunkById(self::INTEREST_CHUNK_SIZE, function (Collection $chunk) use ($interests, &$stats): void {
            foreach ($chunk as $interest) {
                $interest->campaign_acquired_source_field = 'salesforce_interests.utm_campaign';
                $interest->acquired_id_source_field = 'salesforce_interests.utm_id';
                $interest->content_acquired_source_field = 'salesforce_interests.utm_content';
                $interest->source_origin_effective = $interest->fuente_origen;
                $interest->campaign_field_resolution = [
                    'utm_campaign' => ['source_field' => 'salesforce_interests.utm_campaign'],
                    'utm_id' => ['source_field' => 'salesforce_interests.utm_id'],
                    'utm_source' => ['source_field' => 'salesforce_interests.utm_source'],
                    'utm_medium' => ['source_field' => 'salesforce_interests.utm_medium'],
                    'utm_content' => ['source_field' => 'salesforce_interests.utm_content'],
                    'source_origin' => ['source_field' => 'salesforce_interests.source'],
                    'original_source' => ['source_field' => 'salesforce_interests.original_source'],
                    'medium' => ['source_field' => 'salesforce_interests.medium'],
                    'channel' => ['source_field' => 'salesforce_interests.channel'],
                    'utm_term' => ['source_field' => 'salesforce_interests.utm_term'],
                ];
                $normalizedType = $this->leadRecordTypeNormalizer->normalize($interest->record_type_name);
                $interest->vehicle_interest = $normalizedType === 'tasacion'
                    ? $interest->appraisal_vehicle_salesforce_id
                    : $interest->sale_vehicle_salesforce_id;

                $hasAcquisitionEvidence = collect([
                    $interest->campaign_acquired, $interest->acquired_id, $interest->content_acquired,
                    $interest->source_acquired, $interest->medium_acquired, $interest->source,
                    $interest->original_source, $interest->medium, $interest->channel, $interest->utm_term,
                ])->contains(fn ($value): bool => $this->normalizer->isValidAttributionValue($value));
                if (! $hasAcquisitionEvidence) {
                    $stats['interests_without_acquisition_evidence']++;
                }

                if ($this->normalizer->isValidAttributionValue($interest->campaign_acquired)) {
                    $stats['interests_with_acquisition_not_null']++;
                }

                $this->countFieldResolutionSources($stats, $interest);
                $excludedReason = $this->campaignTypeResolver->excludedReason($interest->campaign_acquired);
                if ($excludedReason !== null && $this->normalizer->isValidAttributionValue($interest->campaign_acquired)) {
                    $stats['excluded_campaigns']++;
                    $stats['excluded_by_reason'][$excludedReason]['count'] = ($stats['excluded_by_reason'][$excludedReason]['count'] ?? 0) + 1;
                }
                $this->countLeadAcquisitionShape($stats, $interest);
                $interests->push($interest);
            }
        }, 'id');

        $stats['candidate_interests'] = $interests->count();
        $stats['processed_interests'] = $interests->count();

        return $interests;
    }

    private function normalizeLeadCampaign(object $lead): void
    {
        $hasMetaInstantFormsName = $this->campaignTypeResolver->isMetaDirectFormCampaignName($lead->campaign_acquired ?? null);
        if (! $hasMetaInstantFormsName) {
            return;
        }

        $lead->campaign_acquired = $this->campaignTypeResolver->metaDirectFormCampaignName();
        $lead->acquired_id = $this->campaignTypeResolver->metaDirectFormCampaignId();
    }

    private function metricLookup(CarbonInterface $start, CarbonInterface $end): array
    {
        $rows = DB::table('campaign_platform_daily_metrics')
            ->where('metric_date', '>=', $start->toDateString())
            ->where('metric_date', '<', CarbonImmutable::parse($end)->toDateString())
            ->select([
                'platform',
                'account_id',
                'campaign_id',
                'campaign_name',
                'adset_id',
                'ad_group_id',
                'ad_id',
            ])
            ->get();

        if (Schema::hasTable('campaign_platform_identifiers')) {
            $rows = $rows->concat(
                DB::table('campaign_platform_identifiers')->select([
                    'platform',
                    'account_id',
                    'campaign_id',
                    'campaign_name',
                    'adset_id',
                    'ad_group_id',
                    'ad_id',
                ])->get()
            );
        }

        $lookup = [
            'ad' => [],
            'adset' => [],
            'ad_group' => [],
            'campaign_id' => [],
            'campaign_name' => [],
            'campaign_name_flexible' => [],
        ];

        foreach ($rows as $row) {
            $row = $this->normalizeMetricLookupRow($row);
            $payload = [
                'platform' => $row->platform,
                'account_id' => $row->account_id,
                'campaign_id' => $row->campaign_id,
                'campaign_name' => $row->campaign_name,
            ];

            foreach ([
                'ad' => $row->ad_id,
                'adset' => $row->adset_id,
                'ad_group' => $row->ad_group_id,
                'campaign_id' => $row->campaign_id,
            ] as $type => $value) {
                $key = $this->normalizer->compactKey($value);

                if ($key !== '') {
                    $lookup[$type][$key][] = $payload + [
                        'matched_platform_field' => $type,
                        'matched_platform_value' => (string) $value,
                    ];
                }
            }

            $nameKey = $this->normalizer->key($row->campaign_name);
            if ($nameKey !== '') {
                $lookup['campaign_name'][$nameKey][] = $payload + [
                    'matched_platform_field' => 'campaign_name',
                    'matched_platform_value' => (string) $row->campaign_name,
                ];
            }

            $flexibleNameKey = $this->normalizer->flexibleCampaignKey($row->campaign_name);
            if ($flexibleNameKey !== '') {
                $lookup['campaign_name_flexible'][$flexibleNameKey][] = $payload + [
                    'matched_platform_field' => 'campaign_name_flexible',
                    'matched_platform_value' => (string) $row->campaign_name,
                ];
            }
        }

        foreach ($lookup as &$matchesByKey) {
            foreach ($matchesByKey as &$matches) {
                $matches = collect($matches)
                    ->unique(fn (array $match) => implode('|', [
                        $match['platform'] ?? '',
                        $match['account_id'] ?? '',
                        $match['campaign_id'] ?? '',
                        $match['campaign_name'] ?? '',
                        $match['matched_platform_field'] ?? '',
                        $match['matched_platform_value'] ?? '',
                    ]))
                    ->values()
                    ->all();
            }
        }
        unset($matchesByKey, $matches);

        return $lookup;
    }

    private function normalizeMetricLookupRow(object $row): object
    {
        if ($this->campaignTypeResolver->isMetaInstantFormsCampaign($row->platform ?? null, $row->campaign_name ?? null)) {
            $row->campaign_id = $this->campaignTypeResolver->metaDirectFormCampaignId();
            $row->campaign_name = $this->campaignTypeResolver->metaDirectFormCampaignName();
        }

        return $row;
    }

    private function resolveCampaign(object $lead, array $metrics): array
    {
        $excludedReason = $this->campaignTypeResolver->excludedReason($lead->campaign_acquired);
        if ($excludedReason !== null && $this->normalizer->isValidAttributionValue($lead->campaign_acquired)) {
            return $this->excludedCampaign($lead, $excludedReason);
        }

        $idCandidates = array_filter([
            ($lead->acquired_id_source_field ?? 'salesforce_interests.utm_id') => $lead->acquired_id,
            ($lead->content_acquired_source_field ?? 'salesforce_interests.utm_content') => $lead->content_acquired,
        ], fn ($value) => $this->normalizer->isValidAttributionValue($value));

        $resolved = [];
        foreach ([
            'ad' => 'ad_id_match',
            'adset' => 'adset_or_adgroup_id_match',
            'ad_group' => 'adset_or_adgroup_id_match',
            'campaign_id' => 'campaign_id_match',
        ] as $type => $method) {
            foreach ($idCandidates as $sourceField => $candidate) {
                $matches = $metrics[$type][$this->normalizer->compactKey($candidate)] ?? [];

                if (count($matches) === 1) {
                    $resolved[] = ['source_field' => $sourceField, 'source_value' => $candidate, 'match' => $matches[0], 'method' => $method, 'type' => $type];
                } elseif (count($matches) > 1) {
                    return $this->ambiguousCampaign($lead, 'ID ambiguo entre plataformas/campanas', $sourceField, $candidate, $matches);
                }
            }
        }

        $distinct = collect($resolved)->groupBy(fn (array $row): string => $this->candidateCampaignIdentity($row['match']))->values();
        if ($distinct->count() > 1) {
            return $this->ambiguousCampaign($lead, 'IDs publicitarios contradictorios', 'identificadores_publicitarios', null, collect($resolved)->pluck('match')->all());
        }
        if ($distinct->count() === 1) {
            $priority = ['ad' => 1, 'adset' => 2, 'ad_group' => 3, 'campaign_id' => 4];
            $winner = $distinct->first()->sortBy(fn (array $row): int => $priority[$row['type']])->first();

            return array_merge($winner['match'], [
                'method' => $winner['method'], 'confidence' => 'high', 'match_status' => 'Cruzada por ID',
                'campaign_source_type' => 'platform_campaign', 'matched_to_platform' => true,
                'matched_source_field' => $winner['source_field'], 'matched_source_value' => (string) $winner['source_value'],
                'match_candidate_count' => count($resolved),
            ]);
        }

        // Meta Direct Form inferido solo se aplica tras agotar IDs originales.
        $this->normalizeLeadCampaign($lead);

        if ($this->normalizer->isValidAttributionValue($lead->campaign_acquired)) {
            $nameKey = $this->normalizer->key($lead->campaign_acquired);
            $matches = $metrics['campaign_name'][$nameKey] ?? [];

            if (count($matches) === 1) {
                return array_merge($matches[0], [
                    'method' => 'campaign_name_exact_match',
                    'confidence' => 'medium',
                    'match_status' => 'Cruzada por nombre exacto normalizado',
                    'campaign_source_type' => 'platform_campaign',
                    'matched_to_platform' => true,
                    'matched_source_field' => $lead->campaign_acquired_source_field ?? 'salesforce_interests.utm_campaign',
                    'matched_source_value' => (string) $lead->campaign_acquired,
                    'match_candidate_count' => 1,
                ]);
            }

            if (count($matches) > 1) {
                return $this->ambiguousCampaign($lead, 'Nombre exacto ambiguo entre campanas', $lead->campaign_acquired_source_field ?? 'salesforce_interests.utm_campaign', $lead->campaign_acquired, $matches);
            }

            $flexibleNameKey = $this->normalizer->flexibleCampaignKey($lead->campaign_acquired);
            $matches = $metrics['campaign_name_flexible'][$flexibleNameKey] ?? [];

            if (count($matches) === 1) {
                return array_merge($matches[0], [
                    'method' => 'campaign_name_flexible_match',
                    'confidence' => 'low',
                    'match_status' => 'Cruzada por nombre flexible',
                    'campaign_source_type' => 'platform_campaign',
                    'matched_to_platform' => true,
                    'matched_source_field' => $lead->campaign_acquired_source_field ?? 'salesforce_interests.utm_campaign',
                    'matched_source_value' => (string) $lead->campaign_acquired,
                    'match_candidate_count' => 1,
                ]);
            }

            if (count($matches) > 1) {
                return $this->ambiguousCampaign($lead, 'Nombre flexible ambiguo entre campanas', $lead->campaign_acquired_source_field ?? 'salesforce_interests.utm_campaign', $lead->campaign_acquired, $matches);
            }
        }

        if ($this->normalizer->isValidAttributionValue($lead->campaign_acquired)) {
            return $this->salesforceOnlyCampaign(
                $lead,
                sourceField: $lead->campaign_acquired_source_field,
                sourceValue: $lead->campaign_acquired,
            );
        }

        if ($this->normalizer->isValidAttributionValue($lead->acquired_id)) {
            return $this->salesforceOnlyCampaign(
                $lead,
                sourceField: $lead->acquired_id_source_field,
                sourceValue: $lead->acquired_id,
            );
        }

        if ($this->normalizer->isValidAttributionValue($lead->content_acquired)) {
            return $this->salesforceOnlyCampaign(
                $lead,
                sourceField: $lead->content_acquired_source_field,
                sourceValue: $lead->content_acquired,
            );
        }

        $originEvidence = $this->firstInterestOriginEvidence($lead);
        if ($originEvidence !== null) {
            return $this->salesforceOriginCampaign($lead, $originEvidence['field'], $originEvidence['value']);
        }

        return $this->salesforceOnlyCampaign($lead);
    }

    private function candidateCampaignIdentity(array $match): string
    {
        return filled($match['campaign_id'] ?? null)
            ? ($match['platform'] ?? '').'|'.$match['campaign_id']
            : ($match['platform'] ?? '').'|'.$this->normalizer->key($match['campaign_name'] ?? '');
    }

    private function excludedCampaign(object $lead, string $reason): array
    {
        return [
            'platform' => 'excluded',
            'account_id' => null,
            'campaign_id' => null,
            'campaign_name' => $this->normalizer->clean($lead->campaign_acquired),
            'method' => 'excluded',
            'confidence' => 'none',
            'match_status' => $reason,
            'campaign_source_type' => 'excluded_campaign',
            'matched_to_platform' => false,
            'matched_source_field' => $lead->campaign_acquired_source_field ?? 'salesforce_interests.utm_campaign',
            'matched_source_value' => $this->normalizer->clean($lead->campaign_acquired),
            'matched_platform_field' => null,
            'matched_platform_value' => null,
            'match_candidate_count' => 0,
        ];
    }

    private function salesforceOnlyCampaign(
        object $lead,
        string $status = 'Sin inversion asociada',
        ?string $sourceField = null,
        mixed $sourceValue = null,
        int $candidateCount = 0,
    ): array {
        return [
            'platform' => 'salesforce',
            'account_id' => null,
            'campaign_id' => $this->normalizer->isValidAttributionValue($lead->acquired_id) ? $lead->acquired_id : null,
            'campaign_name' => $this->normalizer->clean($lead->campaign_acquired),
            'method' => 'salesforce_only',
            'confidence' => 'low',
            'match_status' => $status,
            'campaign_source_type' => 'salesforce_campaign_without_spend',
            'matched_to_platform' => false,
            'matched_source_field' => $sourceField,
            'matched_source_value' => $sourceValue === null ? null : (string) $sourceValue,
            'matched_platform_field' => null,
            'matched_platform_value' => null,
            'match_candidate_count' => $candidateCount,
        ];
    }

    private function salesforceOriginCampaign(object $interest, string $sourceField, string $sourceValue): array
    {
        return [
            'platform' => 'salesforce',
            'account_id' => null,
            'campaign_id' => null,
            'campaign_name' => $this->originLabel(
                $this->firstValidValue($interest->source, $interest->original_source),
                $this->firstValidValue($interest->medium, $interest->channel),
            ),
            'method' => 'salesforce_only',
            'confidence' => 'low',
            'match_status' => 'Procedencia Salesforce sin campaña publicitaria',
            'campaign_source_type' => 'salesforce_origin',
            'matched_to_platform' => false,
            'matched_source_field' => $sourceField,
            'matched_source_value' => $sourceValue,
            'matched_platform_field' => null,
            'matched_platform_value' => null,
            'match_candidate_count' => 1,
        ];
    }

    /** @return array{field:string,value:string}|null */
    private function firstInterestOriginEvidence(object $interest): ?array
    {
        foreach ([
            'salesforce_interests.source' => $interest->source,
            'salesforce_interests.original_source' => $interest->original_source,
            'salesforce_interests.medium' => $interest->medium,
            'salesforce_interests.channel' => $interest->channel,
        ] as $field => $value) {
            if ($this->normalizer->isValidAttributionValue($value)) {
                return ['field' => $field, 'value' => $this->normalizer->clean($value)];
            }
        }

        return null;
    }

    private function ambiguousCampaign(object $lead, string $reason, string $sourceField, mixed $sourceValue, array $candidates): array
    {
        return [
            'platform' => 'ambiguous',
            'account_id' => null,
            'campaign_id' => null,
            'campaign_name' => null,
            'method' => 'ambiguous',
            'confidence' => 'none',
            'match_status' => $reason,
            'campaign_source_type' => 'ambiguous_attribution',
            'matched_to_platform' => false,
            'matched_source_field' => $sourceField,
            'matched_source_value' => (string) $sourceValue,
            'matched_platform_field' => null,
            'matched_platform_value' => null,
            'match_candidate_count' => count($candidates),
            'candidates' => $candidates,
        ];
    }

    private function isManualGoogleTasadorCampaign(mixed $campaignName): bool
    {
        return $this->normalizer->key($campaignName) === 'tasador';
    }

    private function originLabel(mixed $source, mixed $medium): ?string
    {
        $parts = array_filter([
            $this->normalizer->isValidAttributionValue($source) ? $this->normalizer->clean($source) : null,
            $this->normalizer->isValidAttributionValue($medium) ? $this->normalizer->clean($medium) : null,
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private function firstValidValue(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if ($this->normalizer->isValidAttributionValue($value)) {
                return $this->normalizer->clean($value);
            }
        }

        return null;
    }

    private function candidateOpportunities(Collection $leads): Collection
    {
        $convertedIds = $leads
            ->pluck('converted_opportunity_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
        $convertedAccountIds = $leads
            ->pluck('converted_account_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $columns = array_values(array_unique(array_merge([
            'salesforce_id',
            'name',
            'created_date',
            'stage_name',
            'record_type_name',
            'account_id',
            'reservation',
            'reservation_date',
            'cv_signed',
            'cv_signed_date',
        ], $this->saleAmountResolver->opportunitySelectColumns())));

        if ($convertedIds === [] && $convertedAccountIds === []) {
            return collect();
        }

        $opportunities = collect();

        foreach (array_chunk($convertedIds, self::OPPORTUNITY_LOOKUP_CHUNK_SIZE) as $ids) {
            DB::table('salesforce_opportunities')
                ->whereIn('salesforce_id', $ids)
                ->where(function ($query): void {
                    $query->where('is_deleted', false)
                        ->orWhereNull('deletion_detection_source')
                        ->orWhere('deletion_detection_source', '<>', SalesforceOpportunity::DELETION_SOURCE_QUERY_ALL);
                })
                ->select($columns)
                ->get()
                ->each(function (object $opportunity) use ($opportunities): void {
                    $opportunities[(string) $opportunity->salesforce_id] = $opportunity;
                });
        }

        foreach (array_chunk($convertedAccountIds, self::OPPORTUNITY_LOOKUP_CHUNK_SIZE) as $accountIds) {
            DB::table('salesforce_opportunities')
                ->whereIn('account_id', $accountIds)
                ->where(function ($query): void {
                    $query->where('is_deleted', false)
                        ->orWhereNull('deletion_detection_source')
                        ->orWhere('deletion_detection_source', '<>', SalesforceOpportunity::DELETION_SOURCE_QUERY_ALL);
                })
                ->select($columns)
                ->get()
                ->each(function (object $opportunity) use ($opportunities): void {
                    $opportunities[(string) $opportunity->salesforce_id] = $opportunity;
                });
        }

        return $opportunities;
    }

    private function assignOpportunities(
        Collection $leads,
        Collection $opportunities,
        array $opportunityInterestContext,
        array $claimedOpportunityIds = [],
    ): array {
        $primaryAssignments = [];
        $detailAssignments = [];
        $unresolvedRows = [];
        $resolvedUnresolvedIds = [];
        $claimedOpportunityIds = array_fill_keys(
            array_values(array_filter(array_map(
                static fn ($value): string => (string) $value,
                array_keys($claimedOpportunityIds)
            ))),
            true
        );

        $leadById = $leads->keyBy('salesforce_id');
        foreach ($opportunities->chunk(self::OPPORTUNITY_LOOKUP_CHUNK_SIZE) as $chunk) {
            $resolutions = $this->opportunityInterestAttribution->resolve($chunk->values(), $opportunityInterestContext);
            foreach ($chunk as $opportunity) {
                $resolution = $resolutions->get((string) $opportunity->salesforce_id, []);
                if (($resolution['relationship_status'] ?? null) !== 'both_match') {
                    continue;
                }

                $interest = $leadById->get($resolution['interest_id'] ?? null);
                if ($interest === null) {
                    continue;
                }

                $this->storeOpportunityAssignment(
                    $primaryAssignments,
                    $detailAssignments,
                    $claimedOpportunityIds,
                    $interest,
                    [
                        'opportunity' => $opportunity,
                        'method' => 'both_match',
                        'confidence' => 'high',
                        'relationship_status' => 'both_match',
                    ],
                );
            }
        }

        $indexes = $this->interestMatchIndexes($leads);
        $leadById = $indexes['lead_by_id'];

        foreach ($opportunities->sortBy('created_date') as $opportunity) {
            if (isset($claimedOpportunityIds[$opportunity->salesforce_id])) {
                continue;
            }

            $accountId = (string) ($opportunity->account_id ?? '');
            $candidateRows = collect($this->indexedValues($indexes['account'], $accountId))
                ->map(fn (string $leadId): array => ['lead_id' => $leadId, 'method' => 'account_first_touch'])
                ->values();

            $this->assignBestOpportunityCandidate(
                $primaryAssignments,
                $detailAssignments,
                $claimedOpportunityIds,
                $unresolvedRows,
                $resolvedUnresolvedIds,
                $leadById,
                $opportunity,
                $candidateRows,
                'medium'
            );
        }

        $this->persistUnresolvedAttributions($unresolvedRows, $resolvedUnresolvedIds);

        return [
            'primary' => $primaryAssignments,
            'detail' => $detailAssignments,
        ];
    }

    private function storeOpportunityAssignment(
        array &$primaryAssignments,
        array &$detailAssignments,
        array &$claimedOpportunityIds,
        object $lead,
        array $assignment,
    ): void {
        $leadId = (string) $lead->salesforce_id;
        $opportunityId = (string) ($assignment['opportunity']?->salesforce_id ?? '');

        if ($opportunityId === '' || isset($claimedOpportunityIds[$opportunityId])) {
            return;
        }

        $primaryAssignments[$leadId] ??= [
            'opportunity' => $assignment['opportunity'] ?? null,
            'method' => $assignment['method'] ?? null,
            'confidence' => $assignment['confidence'] ?? null,
            'relationship_status' => $assignment['relationship_status'] ?? null,
        ];
        $detailAssignments[$leadId] ??= [];
        $detailAssignments[$leadId][] = $assignment;
        $claimedOpportunityIds[$opportunityId] = true;
    }

    private function claimedOpportunityIds(?CarbonInterface $excludedStart = null, ?CarbonInterface $excludedEnd = null): array
    {
        $query = DB::table('campaign_attributions')
            ->whereNotNull('opportunity_id')
            ->when($excludedStart && $excludedEnd, function ($query) use ($excludedStart, $excludedEnd): void {
                $query->where(function ($period) use ($excludedStart, $excludedEnd): void {
                    $period->where('interest_functional_created_at', '<', CarbonImmutable::parse($excludedStart)->utc())
                        ->orWhere('interest_functional_created_at', '>=', CarbonImmutable::parse($excludedEnd)->utc());
                });
            });

        return $query
            ->pluck('opportunity_id')
            ->filter(fn ($value): bool => filled($value))
            ->mapWithKeys(fn ($value): array => [(string) $value => true])
            ->all();
    }

    private function currentAttributions(CarbonInterface $start, CarbonInterface $end): array
    {
        return DB::table('campaign_attributions')
            ->where('interest_functional_created_at', '>=', CarbonImmutable::parse($start)->utc())
            ->where('interest_functional_created_at', '<', CarbonImmutable::parse($end)->utc())
            ->select(['interest_id', 'platform', 'campaign_id', 'campaign_name', 'attribution_method', 'is_ambiguous', 'campaign_source_type', 'matched_source_field', 'matched_source_value', 'matched_platform_field', 'matched_platform_value', 'match_candidate_count', 'campaign_acquired', 'acquired_id', 'content_acquired', 'source_acquired', 'medium_acquired'])
            ->get()
            ->keyBy('interest_id')
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    private function simulationSummary(Collection $leads, array $currentRows, array $simulatedRows): array
    {
        $sets = ['attributed' => [], 'ambiguous' => [], 'unattributed' => [], 'excluded' => []];
        $changes = ['same_campaign_same_method' => [], 'attribution_method_changed' => [], 'campaign_identity_changed' => [], 'new_attribution' => [], 'removed_attribution' => [], 'new_ambiguous' => [], 'ambiguity_resolved' => [], 'became_unattributed' => []];
        $transitions = [];
        $details = [];
        $simulatedLeadIds = [];

        foreach ($simulatedRows as $row) {
            $leadId = (string) $row['interest_id'];
            $simulatedLeadIds[$leadId] = true;
            $state = $row['campaign_source_type'] === 'excluded_campaign' ? 'excluded'
                : (($row['is_ambiguous'] ?? false) ? 'ambiguous' : ($this->hasCampaignIdentity($row) ? 'attributed' : 'unattributed'));
            $sets[$state][] = $leadId;
            $current = $currentRows[$leadId] ?? null;
            $currentAmbiguous = (bool) ($current['is_ambiguous'] ?? false);
            if ($current && ! $currentAmbiguous && ($row['is_ambiguous'] ?? false)) {
                $changes['new_ambiguous'][] = $leadId;
            }
            if ($currentAmbiguous && ! ($row['is_ambiguous'] ?? false)) {
                $changes['ambiguity_resolved'][] = $leadId;
            }
            if ($current && ! $currentAmbiguous && $this->hasCampaignIdentity($current) && $state === 'unattributed') {
                $changes['became_unattributed'][] = $leadId;
            }
            if ($current === null && $state === 'attributed') {
                $changes['new_attribution'][] = $leadId;
            } elseif ($current) {
                if ($this->campaignIdentity($current) === $this->campaignIdentity($row)) {
                    $changes[($current['attribution_method'] ?? null) === ($row['attribution_method'] ?? null) ? 'same_campaign_same_method' : 'attribution_method_changed'][] = $leadId;
                } else {
                    $changes['campaign_identity_changed'][] = $leadId;
                    $key = $this->campaignIdentity($current).' -> '.$this->campaignIdentity($row);
                    $transitions[$key] = ($transitions[$key] ?? 0) + 1;
                    $details[] = [
                        'interest_id' => $leadId,
                        'transition' => $key,
                        'current' => $this->diagnosticAttribution($current),
                        'simulated' => $this->diagnosticAttribution($row),
                        'input' => [
                            'campaign_acquired' => $row['campaign_acquired'] ?? null,
                            'acquired_id' => $row['acquired_id'] ?? null,
                            'content_acquired' => $row['content_acquired'] ?? null,
                            'fuente_origen' => $row['source_acquired'] ?? null,
                            'medio_origen' => $row['medium_acquired'] ?? null,
                        ],
                    ];
                }
            }
        }

        foreach (array_keys($currentRows) as $leadId) {
            if (! isset($simulatedLeadIds[(string) $leadId])) {
                $changes['removed_attribution'][] = (string) $leadId;
            }
        }

        $leadTypes = $leads->countBy(fn (object $lead): string => $this->leadRecordTypeNormalizer->normalize($lead->record_type_name ?? null) ?? 'null')->all();
        $nullRawTypes = $leads->filter(fn (object $lead): bool => $this->leadRecordTypeNormalizer->normalize($lead->record_type_name ?? null) === null)
            ->countBy(fn (object $lead): string => $lead->record_type_name === null ? 'null' : ($lead->record_type_name === '' ? 'empty' : (string) $lead->record_type_name))->all();
        $universe = $leads->pluck('salesforce_id')->map(fn ($id): string => (string) $id)->sort()->values()->all();
        $partition = collect($sets)->flatten()->sort()->values()->all();

        if ($universe !== $partition || count($sets['attributed']) !== count(array_unique($sets['attributed']))) {
            throw new \LogicException('La conciliacion de la simulacion de atribucion no cierra.');
        }

        return [
            'campaign_interests_examined' => count($universe), 'current_attributions' => count($currentRows), 'simulated_attributions' => count($simulatedRows),
            'unchanged' => count($changes['same_campaign_same_method']),
            'sets' => collect($sets)->map(fn (array $ids): array => ['count' => count($ids), 'sample_ids' => array_slice($ids, 0, 20)])->all(),
            'changes' => collect($changes)->map(fn (array $ids): array => ['count' => count($ids), 'sample_ids' => array_slice(array_values(array_unique($ids)), 0, 20)])->all(),
            'campaign_identity_transitions' => collect($transitions)->sortDesc()->take(20)->map(fn (int $count, string $transition): array => ['transition' => $transition, 'count' => $count])->values()->all(),
            'campaign_identity_change_details' => array_slice($details, 0, 20),
            'interest_types' => $leadTypes,
            'null_record_type_raw' => $nullRawTypes,
        ];
    }

    private function campaignIdentity(array $row): string
    {
        $platform = (string) ($row['platform'] ?? '');

        return filled($row['campaign_id'] ?? null)
            ? $platform.'|'.(string) $row['campaign_id']
            : $platform.'|'.$this->normalizer->key($row['campaign_name'] ?? '');
    }

    private function hasCampaignIdentity(array $row): bool
    {
        return filled($row['campaign_id'] ?? null) || filled($row['campaign_name'] ?? null);
    }

    private function diagnosticAttribution(array $row): array
    {
        return collect($row)->only(['platform', 'campaign_id', 'campaign_name', 'attribution_method', 'campaign_source_type', 'matched_source_field', 'matched_source_value', 'matched_platform_field', 'matched_platform_value', 'match_candidate_count'])->all();
    }

    private function interestMatchIndexes(Collection $leads): array
    {
        $leadById = $leads->keyBy('salesforce_id');
        $accountIndex = [];

        foreach ($leads as $lead) {
            $accountId = (string) ($lead->converted_account_id ?? '');
            if ($accountId !== '') {
                $accountIndex[$accountId][] = $lead->salesforce_id;
            }
        }

        return [
            'lead_by_id' => $leadById,
            'account' => $accountIndex,
        ];
    }

    private function assignBestOpportunityCandidate(
        array &$primaryAssignments,
        array &$detailAssignments,
        array &$claimedOpportunityIds,
        array &$unresolvedRows,
        array &$resolvedUnresolvedIds,
        Collection $leadById,
        object $opportunity,
        Collection $candidateRows,
        string $defaultConfidence,
    ): void {
        $candidateRows = $candidateRows
            ->map(function (array $row) use ($leadById): ?array {
                $lead = $leadById->get($row['lead_id']);

                return $lead ? ['lead' => $lead, 'method' => $row['method']] : null;
            })
            ->filter()
            ->values();

        $highestPriority = (int) $candidateRows->max(fn (array $row): int => $this->interestAttributionPriority($row['lead']));
        $precedenceCandidates = $candidateRows
            ->filter(fn (array $row): bool => $this->interestAttributionPriority($row['lead']) === $highestPriority)
            ->sortBy(fn (array $row): string => CarbonImmutable::parse($row['lead']->created_date)->format('YmdHis'))
            ->values();
        $campaignSignatures = $precedenceCandidates
            ->map(fn (array $row): string => implode('|', [
                $this->normalizer->compactKey($row['lead']->acquired_id),
                $this->normalizer->compactKey($row['lead']->content_acquired),
                $this->normalizer->key($row['lead']->campaign_acquired),
                $this->normalizer->key($row['lead']->source),
                $this->normalizer->key($row['lead']->original_source),
                $this->normalizer->key($row['lead']->medium),
                $this->normalizer->key($row['lead']->channel),
            ]))
            ->filter(fn (string $signature): bool => trim($signature, '|') !== '')
            ->unique();

        if ($campaignSignatures->count() > 1) {
            $opportunityId = (string) $opportunity->salesforce_id;
            $unresolvedRows[$opportunityId] = [
                'entity_type' => 'opportunity',
                'entity_salesforce_id' => $opportunityId,
                'status' => 'ambiguous',
                'reason' => 'Varias campañas de first touch con la misma precedencia',
                'candidates' => json_encode($precedenceCandidates->map(fn (array $row): array => [
                    'interest_id' => $row['lead']->salesforce_id,
                    'interest_functional_created_at' => $row['lead']->created_date,
                    'campaign_acquired' => $row['lead']->campaign_acquired,
                    'acquired_id' => $row['lead']->acquired_id,
                    'content_acquired' => $row['lead']->content_acquired,
                ])->all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'rule_version' => self::ATTRIBUTION_RULE_VERSION,
                'evaluated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            return;
        }

        $candidateRows = $precedenceCandidates;

        $candidateRow = $candidateRows->first();
        $candidate = $candidateRow['lead'] ?? null;

        if (! $candidate) {
            return;
        }

        $resolvedUnresolvedIds[(string) $opportunity->salesforce_id] = true;

        $this->storeOpportunityAssignment(
            $primaryAssignments,
            $detailAssignments,
            $claimedOpportunityIds,
            $candidate,
            [
                'opportunity' => $opportunity,
                'method' => $candidateRows->count() > 1 && $this->interestAttributionPriority($candidate) > 1
                    ? 'account_interest_campaign_match'
                    : $candidateRow['method'],
                'confidence' => $candidateRows->count() > 1 ? 'low' : $defaultConfidence,
            ],
        );
    }

    private function persistUnresolvedAttributions(array $unresolvedRows, array $resolvedUnresolvedIds): void
    {
        $resolvedIds = array_values(array_diff(array_keys($resolvedUnresolvedIds), array_keys($unresolvedRows)));
        foreach (array_chunk($resolvedIds, self::UNRESOLVED_WRITE_CHUNK_SIZE) as $ids) {
            DB::table('campaign_unresolved_attributions')
                ->where('entity_type', 'opportunity')
                ->whereIn('entity_salesforce_id', $ids)
                ->delete();
        }

        foreach (array_chunk(array_values($unresolvedRows), self::UNRESOLVED_WRITE_CHUNK_SIZE) as $rows) {
            DB::table('campaign_unresolved_attributions')->upsert(
                $rows,
                ['entity_type', 'entity_salesforce_id'],
                ['status', 'reason', 'candidates', 'rule_version', 'evaluated_at', 'updated_at'],
            );
        }
    }

    private function indexedValues(array $index, ?string $key): array
    {
        return $key === null || $key === '' ? [] : ($index[$key] ?? []);
    }

    private function interestAttributionPriority(object $lead): int
    {
        if ($this->normalizer->isValidAttributionValue($lead->campaign_acquired)) {
            return 3;
        }

        if ($this->normalizer->isValidAttributionValue($lead->acquired_id)
            || $this->normalizer->isValidAttributionValue($lead->content_acquired)) {
            return 2;
        }

        if ($this->normalizer->isValidAttributionValue($lead->source_acquired)
            || $this->normalizer->isValidAttributionValue($lead->medium_acquired)
            || $this->normalizer->isValidAttributionValue($lead->source)
            || $this->normalizer->isValidAttributionValue($lead->original_source)
            || $this->normalizer->isValidAttributionValue($lead->medium)
            || $this->normalizer->isValidAttributionValue($lead->channel)) {
            return 1;
        }

        return 0;
    }

    private function opportunityFlags(object $lead, ?object $opportunity): array
    {
        if (! $opportunity) {
            return [
                'has_opportunity' => false,
                'has_reservation' => false,
                'has_fallen_reservation' => false,
                'has_sale' => false,
                'has_purchase' => false,
                'reservation_date' => null,
                'sale_date' => null,
                'sale_amount' => null,
            ];
        }

        $stage = (string) $opportunity->stage_name;
        $isClosedLost = strcasecmp($stage, 'Cerrada Perdida') === 0;
        $hasOpportunity = true;
        $hasReservation = (bool) $opportunity->reservation;
        $recordType = $this->normalizer->compactKey($opportunity->record_type_name);
        $candidateSaleAmount = $this->saleAmountResolver->resolve($opportunity);
        $hasSignedContract = (bool) $opportunity->cv_signed
            && filled($opportunity->cv_signed_date)
            && ! $isClosedLost;
        $hasSale = $hasSignedContract
            && ($recordType === 'venta' || ($recordType === 'cambio' && $candidateSaleAmount !== null && $candidateSaleAmount > 0));
        $hasPurchase = $hasSignedContract && $recordType === 'tasacion';
        $saleAmount = $hasSale ? $candidateSaleAmount : null;

        if ($recordType === 'cambio' && $saleAmount !== null && $saleAmount < 0) {
            $saleAmount = null;
        }

        return [
            'has_opportunity' => $hasOpportunity,
            'has_reservation' => $hasReservation,
            'has_fallen_reservation' => $hasReservation && $isClosedLost,
            'has_sale' => $hasSale,
            'has_purchase' => $hasPurchase,
            'reservation_date' => $hasReservation ? $opportunity->reservation_date : null,
            'sale_date' => $hasSale ? $opportunity->cv_signed_date : null,
            'sale_amount' => $saleAmount,
        ];
    }

    private function flushAttributions(array $campaignRows, array $leadRows): void
    {
        if ($campaignRows === [] && $leadRows === []) {
            return;
        }

        if ($campaignRows !== []) {
            $attributionRows = array_map(function (array $row): array {
                unset($row['has_purchase']);

                return $row;
            }, $campaignRows);

            foreach (array_chunk($attributionRows, self::UPSERT_CHUNK_SIZE) as $chunk) {
                DB::table('campaign_attributions')->insert($chunk);
            }
        }

        if ($leadRows !== []) {
            $payload = array_map(function (array $row): array {
                $sourceCampaignType = $row['interest_type'] === 'tasacion'
                    ? 'tasacion'
                    : ($this->campaignTypeResolver->sourceCampaignType($row['campaign_acquired']) ?? 'venta');

                return [
                    'lead_id' => null,
                    'interest_id' => $row['interest_id'],
                    'lead_created_date' => null,
                    'interest_functional_created_at' => $row['interest_functional_created_at'],
                    'interest_status' => $row['interest_status'],
                    'interest_type' => $row['interest_type'],
                    'interest_source' => $row['interest_source'],
                    'interest_original_source' => $row['interest_original_source'],
                    'interest_medium' => $row['interest_medium'],
                    'interest_channel' => $row['interest_channel'],
                    'interest_utm_term' => $row['interest_utm_term'],
                    'interest_origin_delegation' => $row['interest_origin_delegation'],
                    'interest_origin_zone' => $row['interest_origin_zone'],
                    'interest_owner_id' => $row['interest_owner_id'],
                    'interest_owner_name' => $row['interest_owner_name'],
                    'interest_is_deleted' => $row['interest_is_deleted'],
                    'interest_sync_run_id' => $row['interest_sync_run_id'],
                    'interest_sync_cutoff_at' => $row['interest_sync_cutoff_at'],
                    'opportunity_relationship_status' => $row['opportunity_relationship_status'],
                    'campaign_name' => $row['campaign_name'],
                    'campaign_id' => $row['campaign_id'],
                    'platform' => $row['platform'],
                    'source_campaign_name' => $row['campaign_acquired'],
                    'campaign_type' => $sourceCampaignType,
                    'opportunity_id' => $row['opportunity_id'],
                    'has_opportunity' => $row['has_opportunity'],
                    'has_reservation' => $row['has_reservation'],
                    'has_sale' => $sourceCampaignType === 'venta' && $row['has_sale'],
                    'has_purchase' => $sourceCampaignType === 'tasacion' && $row['has_purchase'],
                    'sold_amount' => $sourceCampaignType === 'venta' ? $row['sale_amount'] : null,
                    'source_acquired' => $row['source_acquired'],
                    'medium_acquired' => $row['medium_acquired'],
                    'campaign_acquired' => $row['campaign_acquired'],
                    'acquired_id' => $row['acquired_id'],
                    'content_acquired' => $row['content_acquired'],
                    'attribution_method' => $row['attribution_method'],
                    'attribution_confidence' => $row['attribution_confidence'],
                    'match_status' => $row['match_status'],
                    'campaign_source_type' => $row['campaign_source_type'],
                    'matched_source_field' => $row['matched_source_field'],
                    'matched_source_value' => $row['matched_source_value'],
                    'matched_platform_field' => $row['matched_platform_field'],
                    'matched_platform_value' => $row['matched_platform_value'],
                    'match_candidate_count' => $row['match_candidate_count'],
                    'attribution_candidates' => $row['attribution_candidates'],
                    'first_touch_at' => $row['first_touch_at'],
                    'is_ambiguous' => $row['is_ambiguous'],
                    'attribution_rule_version' => $row['attribution_rule_version'],
                    'lead_status' => null,
                    'lead_delegation' => null,
                    'lead_zone' => null,
                    'commercial_user_id' => $row['commercial_user_id'],
                    'commercial_user_name' => $row['commercial_user_name'],
                    'vehicle_interest' => $row['vehicle_interest'],
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at'],
                ];
            }, $leadRows);

            foreach (array_chunk($payload, self::UPSERT_CHUNK_SIZE) as $chunk) {
                DB::table('campaign_lead_attributions')->insert($chunk);
            }
        }
    }

    private function makeAttributionRow(object $lead, array $campaign, ?array $assignment, array $interestContext, mixed $now): array
    {
        $opportunity = $assignment['opportunity'] ?? null;
        $opportunityFlags = $this->opportunityFlags($lead, $opportunity);
        $interestType = $this->leadRecordTypeNormalizer->normalize($lead->record_type_name);
        $sourceCampaignType = $interestType === 'tasacion'
            ? 'tasacion'
            : $this->campaignTypeResolver->sourceCampaignType($lead->campaign_acquired);

        if ($sourceCampaignType === 'tasacion') {
            $opportunityFlags['has_sale'] = false;
            $opportunityFlags['sale_date'] = null;
            $opportunityFlags['sale_amount'] = null;
        }

        if ($sourceCampaignType === 'venta') {
            $opportunityFlags['has_purchase'] = false;
        }

        $delegation = $this->delegationNormalizer->normalize($lead->origin_delegation);

        return [
            'lead_id' => null,
            'interest_id' => $lead->salesforce_id,
            'opportunity_id' => $opportunity?->salesforce_id,
            'platform' => $campaign['platform'],
            'account_id' => $campaign['account_id'],
            'campaign_id' => $campaign['campaign_id'],
            'campaign_name' => $campaign['campaign_name'],
            'campaign_name_key' => $this->normalizer->key($campaign['campaign_name']),
            'source_acquired' => $lead->source_acquired,
            'medium_acquired' => $lead->medium_acquired,
            'campaign_acquired' => $lead->campaign_acquired,
            'acquired_id' => $lead->acquired_id,
            'acquired_id_key' => $this->normalizer->compactKey($lead->acquired_id),
            'content_acquired' => $lead->content_acquired,
            'content_acquired_key' => $this->normalizer->compactKey($lead->content_acquired),
            'vehicle_interest' => $lead->vehicle_interest,
            'lead_status' => null,
            'lead_created_at' => null,
            'interest_functional_created_at' => $lead->created_date,
            'interest_status' => $lead->status,
            'interest_type' => $interestType,
            'interest_source' => $lead->source,
            'interest_original_source' => $lead->original_source,
            'interest_medium' => $lead->medium,
            'interest_channel' => $lead->channel,
            'interest_utm_term' => $lead->utm_term,
            'interest_origin_delegation' => $delegation['delegation'],
            'interest_origin_zone' => $delegation['zone'],
            'interest_owner_id' => $lead->owner_id,
            'interest_owner_name' => $lead->owner_name,
            'interest_is_deleted' => (bool) $lead->is_deleted,
            'interest_sync_run_id' => $interestContext['id'],
            'interest_sync_cutoff_at' => $interestContext['cutoff'],
            'opportunity_relationship_status' => $assignment['relationship_status'] ?? ($assignment === null ? 'no_reference' : 'account_first_touch'),
            'opportunity_created_at' => $opportunity?->created_date,
            'reservation_date' => $opportunityFlags['reservation_date'],
            'sale_date' => $opportunityFlags['sale_date'],
            'sale_amount' => $opportunityFlags['sale_amount'],
            'has_opportunity' => $opportunityFlags['has_opportunity'],
            'has_reservation' => $opportunityFlags['has_reservation'],
            'has_fallen_reservation' => $opportunityFlags['has_fallen_reservation'],
            'has_sale' => $opportunityFlags['has_sale'],
            'has_purchase' => $opportunityFlags['has_purchase'],
            'lead_delegation' => null,
            'lead_zone' => null,
            'commercial_user_id' => $lead->owner_id,
            'commercial_user_name' => $lead->owner_name,
            'attribution_method' => $campaign['method'],
            'attribution_confidence' => $campaign['confidence'],
            'opportunity_attribution_method' => $assignment['method'] ?? null,
            'opportunity_attribution_confidence' => $assignment['confidence'] ?? null,
            'match_status' => $campaign['match_status'],
            'campaign_source_type' => $campaign['campaign_source_type'],
            'matched_source_field' => $campaign['matched_source_field'] ?? null,
            'matched_source_value' => $campaign['matched_source_value'] ?? null,
            'matched_platform_field' => $campaign['matched_platform_field'] ?? null,
            'matched_platform_value' => $campaign['matched_platform_value'] ?? null,
            'match_candidate_count' => $campaign['match_candidate_count'] ?? 0,
            'attribution_candidates' => json_encode($campaign['candidates'] ?? [[
                'campaign_id' => $campaign['campaign_id'] ?? null,
                'campaign_name' => $campaign['campaign_name'] ?? null,
                'method' => $campaign['method'] ?? null,
            ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'first_touch_at' => $lead->created_date,
            'is_ambiguous' => ($campaign['method'] ?? null) === 'ambiguous',
            'attribution_rule_version' => self::ATTRIBUTION_RULE_VERSION,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function countCampaignMatch(array &$stats, array $campaign): void
    {
        match ($campaign['method']) {
            'ad_id_match' => $stats['match_ad_id']++,
            'adset_or_adgroup_id_match' => $stats['match_adset_or_adgroup']++,
            'campaign_id_match' => $stats['match_campaign_id']++,
            'campaign_name_exact_match' => $stats['match_campaign_name_exact']++,
            'campaign_name_flexible_match' => $stats['match_campaign_name_flexible']++,
            'salesforce_only' => $stats['salesforce_only']++,
            default => null,
        };

        $stats['match_campaign_name'] = $stats['match_campaign_name_exact'] + $stats['match_campaign_name_flexible'];

        match ($campaign['campaign_source_type'] ?? null) {
            'platform_campaign' => $stats['source_type_platform_campaign']++,
            'salesforce_campaign_without_spend' => $stats['source_type_salesforce_campaign_without_spend']++,
            'salesforce_origin' => $stats['source_type_salesforce_origin']++,
            default => null,
        };

        if ($campaign['matched_to_platform']) {
            $stats['matched_to_platform']++;
        }
    }

    private function countLeadAcquisitionShape(array &$stats, object $lead): void
    {
        $hasCampaign = $this->normalizer->isValidAttributionValue($lead->campaign_acquired);
        $hasSourceOrMedium = $this->normalizer->isValidAttributionValue($lead->source_acquired)
            || $this->normalizer->isValidAttributionValue($lead->medium_acquired);

        if ($hasCampaign) {
            $stats['candidates_with_campaign_acquired']++;
        }

        if (! $hasCampaign && $hasSourceOrMedium) {
            $stats['candidates_only_source_medium']++;
        }

        if ($this->normalizer->isValidAttributionValue($lead->acquired_id)) {
            $stats['candidates_with_acquired_id']++;
        }

        if ($this->normalizer->isValidAttributionValue($lead->content_acquired)) {
            $stats['candidates_with_content_acquired']++;
        }
    }

    private function countFieldResolutionSources(array &$stats, object $lead): void
    {
        foreach ($lead->campaign_field_resolution ?? [] as $dimension => $resolution) {
            $source = $resolution['source_field'] ?? 'none';
            $stats['field_resolution_sources'][$dimension][$source] =
                ($stats['field_resolution_sources'][$dimension][$source] ?? 0) + 1;
        }
    }

    private function countSaleAmountStats(array &$stats, ?object $opportunity, array $opportunityFlags): void
    {
        if (! $opportunityFlags['has_sale']) {
            return;
        }

        if ($opportunity !== null) {
            $stats['sales_with_opportunity_found']++;
        }

        if ($opportunity !== null && $this->saleAmountResolver->positiveValue($opportunity, 'opo_for_importe_total') !== null) {
            $stats['sales_with_opo_for_importe_total']++;
        }

        if ($opportunity !== null && $this->saleAmountResolver->positiveValue($opportunity, 'amount') !== null) {
            $stats['sales_with_amount']++;
        }

        $saleAmount = $opportunityFlags['sale_amount'];

        if ($saleAmount !== null && (float) $saleAmount > 0) {
            $stats['sales_with_sale_amount']++;
            $stats['sale_amount_sum'] += (float) $saleAmount;
        }
    }

    private function emptyStats(CarbonImmutable $start, CarbonImmutable $end): array
    {
        return [
            'range_start' => $start->toDateString(),
            'range_end' => $end->subDay()->toDateString(),
            'range_end_exclusive' => $end->toDateString(),
            'interest_source_table' => 'salesforce_interests',
            'total_interests_in_range' => 0,
            'interests_with_acquisition_not_null' => 0,
            'candidate_interests' => 0,
            'discarded_invalid_values' => 0,
            'interests_without_acquisition_evidence' => 0,
            'excluded_campaigns' => 0,
            'excluded_by_reason' => [],
            'discarded_by_date' => 0,
            'processed_interests' => 0,
            'saved_attributions' => 0,
            'matched_to_platform' => 0,
            'match_ad_id' => 0,
            'match_adset_or_adgroup' => 0,
            'match_campaign_id' => 0,
            'match_campaign_name' => 0,
            'match_campaign_name_exact' => 0,
            'match_campaign_name_flexible' => 0,
            'salesforce_only' => 0,
            'source_type_platform_campaign' => 0,
            'source_type_salesforce_campaign_without_spend' => 0,
            'source_type_salesforce_origin' => 0,
            'candidates_with_campaign_acquired' => 0,
            'candidates_only_source_medium' => 0,
            'candidates_with_acquired_id' => 0,
            'candidates_with_content_acquired' => 0,
            'opportunities' => 0,
            'reservations' => 0,
            'fallen_reservations' => 0,
            'sales' => 0,
            'sales_with_opportunity_found' => 0,
            'sales_with_opo_for_importe_total' => 0,
            'sales_with_amount' => 0,
            'sales_with_sale_amount' => 0,
            'sale_amount_sum' => 0.0,
            'sale_amount_field_used' => 'none',
            'duration_seconds' => 0.0,
            'peak_memory_mb' => 0.0,
            'top_campaign_acquired' => [],
            'top_source_medium' => [],
            'top_acquired_id' => [],
            'top_content_acquired' => [],
            'top_platform_spend' => [],
            'field_resolution_sources' => [],
            'warnings' => [],
        ];
    }

    private function topDiagnostics(CarbonInterface $start, CarbonInterface $end): array
    {
        $attributions = DB::table('campaign_attributions')
            ->where('interest_functional_created_at', '>=', CarbonImmutable::parse($start)->utc())
            ->where('interest_functional_created_at', '<', CarbonImmutable::parse($end)->utc());

        return [
            'top_campaign_acquired' => $this->topAttributionValues(clone $attributions, ['campaign_acquired']),
            'top_source_medium' => $this->topAttributionValues(clone $attributions, ['source_acquired', 'medium_acquired']),
            'top_acquired_id' => $this->topAttributionValues(clone $attributions, ['acquired_id']),
            'top_content_acquired' => $this->topAttributionValues(clone $attributions, ['content_acquired']),
            'top_platform_spend' => $this->topPlatformSpend($start, $end),
        ];
    }

    private function topAttributionValues($query, array $columns): array
    {
        foreach ($columns as $column) {
            $query->whereNotNull($column)->where($column, '<>', '');
        }

        return $query
            ->select(array_merge($columns, [DB::raw('COUNT(*) as total')]))
            ->groupBy(...$columns)
            ->orderByDesc('total')
            ->limit(20)
            ->get()
            ->map(function (object $row) use ($columns): array {
                $label = implode(' + ', array_map(fn (string $column) => (string) ($row->{$column} ?? ''), $columns));

                return ['valor' => $label, 'total' => (int) $row->total];
            })
            ->all();
    }

    private function topPlatformSpend(CarbonInterface $start, CarbonInterface $end): array
    {
        return DB::table('campaign_platform_daily_metrics')
            ->where('metric_date', '>=', $start->toDateString())
            ->where('metric_date', '<', CarbonImmutable::parse($end)->toDateString())
            ->select([
                'platform',
                'campaign_id',
                'campaign_name',
                DB::raw('SUM(COALESCE(spend, 0)) as spend'),
            ])
            ->groupBy('platform', 'campaign_id', 'campaign_name')
            ->orderByDesc('spend')
            ->limit(20)
            ->get()
            ->map(fn (object $row): array => [
                'plataforma' => (string) $row->platform,
                'campaign_id' => (string) $row->campaign_id,
                'campaign_name' => (string) $row->campaign_name,
                'spend' => round((float) $row->spend, 2),
            ])
            ->all();
    }

    private function invalidateCache(): void
    {
        Cache::forever('campaign_dashboard_cache_version', ((int) Cache::get('campaign_dashboard_cache_version', 1)) + 1);
    }

    /** @return array{id:int,cutoff:string} */
    private function interestSourceContext(): array
    {
        $run = $this->latestInterestRun();
        if ($run === null || $run->status !== 'completed' || $run->source_cutoff_at === null) {
            throw new \RuntimeException('El snapshot F2 de Interests no está disponible o no está completado.');
        }

        return [
            'id' => (int) $run->id,
            'cutoff' => $run->source_cutoff_at->toIso8601String(),
        ];
    }

    /** @param array{id:int,cutoff:string} $context */
    private function validateInterestSourceContext(array $context): void
    {
        $run = $this->latestInterestRun();
        if ($run === null
            || (int) $run->id !== $context['id']
            || $run->status !== 'completed'
            || $run->source_cutoff_at?->toIso8601String() !== $context['cutoff']) {
            throw new \RuntimeException('El snapshot F2 de Interests cambió durante la construcción de Campañas.');
        }
    }

    private function latestInterestRun(): ?ReportSyncRun
    {
        return ReportSyncRun::query()
            ->where('dataset', SalesforceInterestSyncService::DATASET)
            ->where('source', SalesforceInterestSyncService::SOURCE)
            ->orderByDesc('id')
            ->first();
    }
}
