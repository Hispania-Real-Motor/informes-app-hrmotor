<?php

namespace App\Services\Campaigns;

use App\Models\SalesforceOpportunity;
use App\Services\Reports\Leads\LeadDelegationNormalizer;
use App\Services\Reports\Leads\LeadRecordTypeNormalizer;
use App\Services\Reports\Leads\OperationalInterestSourceReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Administrative, versioned attribution. No official attribution or closure is written. */
final class CampaignOperationalAttributionBuilder
{
    private const LEASE_MINUTES = 5;

    public function __construct(
        private readonly OperationalInterestSourceReader $interests,
        private readonly CampaignAttributionBuilderService $matching,
        private readonly CampaignDashboardDatasetService $dashboard,
        private readonly CampaignValueNormalizer $normalizer,
        private readonly CampaignTypeResolver $types,
        private readonly LeadRecordTypeNormalizer $recordTypes,
        private readonly LeadDelegationNormalizer $delegations,
        private readonly CampaignSaleAmountResolver $amounts,
    ) {}

    public function build(int $year, int $month): array
    {
        if ($year !== 2026 || ! in_array($month, [10, 11], true)) {
            throw new RuntimeException('Período operativo no habilitado.');
        }

        $source = $this->interests->open($year, $month, true);
        $monthKey = $source['month'];
        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'Europe/Madrid');
        $endExclusive = $start->addMonth();
        $endDate = $month === 10
            ? $endExclusive->subDay()->toDateString()
            : min(
                $endExclusive->subDay()->toDateString(),
                CarbonImmutable::parse($source['effective_cutoff_at'], 'UTC')->setTimezone('Europe/Madrid')->toDateString(),
                CarbonImmutable::now('Europe/Madrid')->subDay()->toDateString()
            );
        if ($endDate < $start->toDateString()) {
            throw new RuntimeException('No hay un día completo disponible para la atribución operativa.');
        }
        $economic = $this->dashboard->operationalEconomicContext($monthKey, $endDate);
        if ($month === 10 && ($economic['closure_status'] !== 'closed' || $economic['snapshot_rows'] === [])) {
            throw new RuntimeException('Falta el snapshot económico cerrado de octubre.');
        }
        if ($month === 11 && $economic['closure_status'] !== 'open') {
            throw new RuntimeException('Noviembre ya no admite la fuente económica abierta.');
        }
        $opportunity = $this->opportunityEvidence($source);
        $active = DB::table('campaign_operational_months as m')
            ->join('campaign_operational_runs as r', 'r.id', '=', 'm.active_run_id')
            ->where('m.month_key', $monthKey)->first(['r.id', 'r.interest_run_id', 'r.projection_key', 'r.economic_fingerprint',
                'r.opportunity_sync_run_id', 'r.opportunity_sync_cutoff_at', 'r.direct_relationship_run_id',
                'r.direct_relationship_cutoff_at', 'r.status']);
        if ($active !== null && $active->status === 'completed'
            && (int) $active->interest_run_id === $source['run_id']
            && $active->projection_key === $source['projection_key']
            && $active->economic_fingerprint === $economic['fingerprint']
            && (int) $active->opportunity_sync_run_id === (int) $opportunity['opportunity_run_id']
            && (string) $active->opportunity_sync_cutoff_at === (string) $opportunity['opportunity_cutoff_at']
            && (int) $active->direct_relationship_run_id === (int) $opportunity['direct_run_id']
            && (string) $active->direct_relationship_cutoff_at === (string) $opportunity['direct_cutoff_at']) {
            return ['run_id' => (int) $active->id, 'month' => $monthKey, 'reused' => true];
        }

        $token = (string) Str::uuid();
        $runId = $this->claim($monthKey, $source, $economic, $opportunity, $token);
        $started = microtime(true);
        $count = 0;
        $processed = 0;
        $incidents = 0;
        $unresolvedRelationships = 0;

        try {
            $availablePlatforms = array_keys(array_filter($economic['availability']['platforms'],
                static fn (array $state): bool => $state['status'] === 'available'));
            $lookup = $this->matching->operationalMetricLookup(
                $start, CarbonImmutable::parse($endDate, 'Europe/Madrid')->addDay()->startOfDay(),
                $month === 10 ? $economic['snapshot_rows'] : null, $availablePlatforms,
                $economic['summary']['campaigns']
            );
            $this->interests->eachBatch($source, function (array $batch) use ($runId, $monthKey, $endDate, $token, $source, $opportunity, $lookup, &$count, &$processed, &$incidents, &$unresolvedRelationships): void {
                $batch = array_values(array_filter($batch, static fn (array $interest): bool => CarbonImmutable::parse($interest['functional_created_at'], 'UTC')
                    ->setTimezone('Europe/Madrid')->toDateString() <= $endDate));
                $relationshipEvidence = $opportunity['available']
                    ? $this->opportunitiesForBatch($batch, $opportunity['direct_run_id'], $source['run_id'])
                    : ['resolved' => [], 'unresolved' => 0];
                $opportunities = $relationshipEvidence['resolved'];
                $unresolvedRelationships += $relationshipEvidence['unresolved'];
                $rows = [];
                foreach ($batch as $interest) {
                    $processed++;
                    $lead = $this->asMatchingInterest($interest);
                    if (! $this->hasAcquisitionEvidence($lead)) {
                        continue;
                    }
                    $campaign = $this->matching->resolveOperationalCampaign($lead, $lookup);
                    $relationship = $opportunities[$interest['salesforce_id']] ?? null;
                    $flags = $opportunity['available']
                        ? $this->matching->operationalOpportunityFlags($lead, $relationship)
                        : null;
                    $type = $this->recordTypes->normalize($lead->record_type_name) === 'tasacion'
                        ? 'tasacion' : ($this->types->sourceCampaignType($lead->campaign_acquired) ?? 'venta');
                    if ($type === 'tasacion' && $flags !== null) {
                        $flags['has_sale'] = false;
                        $flags['sale_amount'] = null;
                    }
                    if ($type === 'venta' && $flags !== null) {
                        $flags['has_purchase'] = false;
                    }
                    $delegation = $this->delegations->normalize($interest['origin_delegation']);
                    if (in_array($campaign['method'], ['ambiguous', 'excluded', 'salesforce_only'], true)
                        || ($opportunity['available'] && filled($interest['inverse_opportunity_salesforce_id']) && $relationship === null)) {
                        $incidents++;
                    }
                    $rows[] = [
                        'run_id' => $runId,
                        'interest_key' => $this->key($interest['salesforce_id']),
                        'opportunity_key' => $relationship === null ? null : $this->key($relationship->salesforce_id),
                        'platform' => $campaign['platform'],
                        'campaign_id' => $campaign['campaign_source_type'] === 'platform_campaign' ? $campaign['campaign_id'] : null,
                        'campaign_name' => $campaign['campaign_source_type'] === 'platform_campaign' ? $campaign['campaign_name'] : null,
                        'campaign_source_type' => $campaign['campaign_source_type'],
                        'attribution_method' => $campaign['method'],
                        'match_status' => $campaign['match_status'],
                        'campaign_type' => $type,
                        'interest_status' => $interest['status'],
                        'interest_type' => $this->recordTypes->normalize($lead->record_type_name),
                        'interest_source' => $interest['source'],
                        'interest_medium' => $interest['medium'],
                        'interest_channel' => $interest['channel'],
                        'interest_origin_delegation' => $delegation['delegation'],
                        'interest_origin_zone' => $delegation['zone'],
                        'opportunity_relationship_status' => $relationship === null ? 'unresolved' : 'both_match',
                        'has_opportunity' => $flags['has_opportunity'] ?? null,
                        'has_reservation' => $flags['has_reservation'] ?? null,
                        'has_fallen_reservation' => $flags['has_fallen_reservation'] ?? null,
                        'has_sale' => $flags['has_sale'] ?? null,
                        'has_purchase' => $flags['has_purchase'] ?? null,
                        'sale_amount' => $flags['sale_amount'] ?? null,
                        'interest_functional_created_at' => $interest['functional_created_at'],
                        'created_at' => now(), 'updated_at' => now(),
                    ];
                }
                $this->interests->assertCurrent($source);
                DB::transaction(function () use ($monthKey, $token, $rows): void {
                    $this->assertLease($monthKey, $token);
                    foreach (array_chunk($rows, 100) as $chunk) {
                        DB::table('campaign_operational_attributions')->insert($chunk);
                    }
                    DB::table('campaign_operational_months')->where('month_key', $monthKey)
                        ->where('build_token', $token)->update(['lease_expires_at' => now()->addMinutes(self::LEASE_MINUTES), 'updated_at' => now()]);
                });
                $count += count($rows);
            });

            $this->interests->assertCurrent($source);
            if ($this->dashboard->operationalEconomicContext($monthKey, $endDate)['fingerprint'] !== $economic['fingerprint']
                || $this->opportunityEvidence($source) !== $opportunity) {
                throw new RuntimeException('Las fuentes cambiaron durante la preparación.');
            }
            DB::transaction(function () use ($monthKey, $token, $runId, $source, $economic, $count, $processed, $incidents, $opportunity, $unresolvedRelationships): void {
                $this->assertLease($monthKey, $token);
                $this->interests->assertCurrent($source);
                if ($this->dashboard->operationalEconomicContext($monthKey, $economic['end_date'])['fingerprint'] !== $economic['fingerprint']) {
                    throw new RuntimeException('La fuente económica cambió antes de activar.');
                }
                if ($this->opportunityEvidence($source) !== $opportunity) {
                    throw new RuntimeException('La relación Opportunity cambió antes de activar.');
                }
                DB::table('campaign_operational_runs')->where('id', $runId)->update([
                    'status' => 'completed', 'interests_processed' => $processed, 'attributions_count' => $count,
                    'incidents_count' => $incidents + $unresolvedRelationships,
                    'opportunity_coverage_complete' => $opportunity['available'] && $unresolvedRelationships === 0,
                    'completed_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('campaign_operational_months')->where('month_key', $monthKey)->where('build_token', $token)
                    ->update(['active_run_id' => $runId, 'build_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
            });
        } catch (Throwable $exception) {
            DB::transaction(function () use ($monthKey, $token, $runId): void {
                $state = DB::table('campaign_operational_months')->where('month_key', $monthKey)->lockForUpdate()->first();
                if ($state?->build_token === $token) {
                    DB::table('campaign_operational_runs')->where('id', $runId)->update(['status' => 'failed', 'updated_at' => now()]);
                    DB::table('campaign_operational_months')->where('month_key', $monthKey)->update([
                        'build_token' => null, 'lease_expires_at' => null, 'updated_at' => now(),
                    ]);
                }
            });
            throw $exception;
        }

        return [
            'run_id' => $runId, 'month' => $monthKey, 'reused' => false,
            'interests_processed' => $processed, 'attributions_count' => $count,
            'incidents_count' => $incidents + $unresolvedRelationships,
            'opportunity_coverage_complete' => $opportunity['available'] && $unresolvedRelationships === 0,
            'duration_seconds' => round(microtime(true) - $started, 2),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
        ];
    }

    private function claim(string $month, array $source, array $economic, array $opportunity, string $token): int
    {
        return DB::transaction(function () use ($month, $source, $economic, $opportunity, $token): int {
            DB::table('campaign_operational_months')->insertOrIgnore([
                'month_key' => $month, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $state = DB::table('campaign_operational_months')->where('month_key', $month)->lockForUpdate()->first();
            if ($state->build_token !== null && CarbonImmutable::parse($state->lease_expires_at)->isFuture()) {
                throw new RuntimeException('Ya existe una construcción operativa en curso.');
            }
            if ($state->build_token !== null) {
                DB::table('campaign_operational_runs')->where('month_key', $month)
                    ->where('status', 'building')->update(['status' => 'failed', 'updated_at' => now()]);
            }
            $this->interests->assertCurrent($source);
            $runId = DB::table('campaign_operational_runs')->insertGetId([
                'month_key' => $month, 'interest_run_id' => $source['run_id'],
                'projection_key' => $source['projection_key'], 'utm_contract_version' => $source['utm_contract_version'],
                'interest_cutoff_at' => $source['effective_cutoff_at'], 'interest_observed_at' => $source['observed_at'],
                'economic_fingerprint' => $economic['fingerprint'],
                'economic_evidence' => json_encode([
                    'end_date' => $economic['end_date'], 'closure_status' => $economic['closure_status'],
                    'closure_version' => $economic['closure_version'], 'availability' => $economic['availability'],
                ], JSON_THROW_ON_ERROR),
                'opportunity_sync_run_id' => $opportunity['opportunity_run_id'],
                'opportunity_sync_cutoff_at' => $opportunity['opportunity_cutoff_at'],
                'direct_relationship_run_id' => $opportunity['direct_run_id'],
                'direct_relationship_cutoff_at' => $opportunity['direct_cutoff_at'],
                'opportunity_coverage_complete' => $opportunity['available'],
                'status' => 'building', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('campaign_operational_months')->where('month_key', $month)->update([
                'build_token' => $token, 'lease_expires_at' => now()->addMinutes(self::LEASE_MINUTES), 'updated_at' => now(),
            ]);

            return (int) $runId;
        });
    }

    private function assertLease(string $month, string $token): void
    {
        $state = DB::table('campaign_operational_months')->where('month_key', $month)->lockForUpdate()->first();
        if ($state?->build_token !== $token || CarbonImmutable::parse($state->lease_expires_at)->isPast()) {
            throw new RuntimeException('Se ha perdido el lease de la atribución operativa.');
        }
    }

    private function opportunityEvidence(array $source): array
    {
        $direct = DB::table('salesforce_opportunity_interest_direct_runs')->orderByDesc('id')->first(['id', 'status', 'source_cutoff_at']);
        $opportunities = DB::table('report_sync_runs')->where('dataset', 'salesforce_opportunities')
            ->orderByDesc('id')->first(['id', 'status', 'source_cutoff_at']);
        $cutoff = (string) $source['observed_at'];

        return [
            'available' => $direct?->status === 'completed' && $opportunities?->status === 'completed'
                && $direct->source_cutoff_at >= $cutoff && $opportunities->source_cutoff_at >= $cutoff,
            'direct_run_id' => $direct?->id,
            'direct_cutoff_at' => $direct?->source_cutoff_at,
            'opportunity_run_id' => $opportunities?->id,
            'opportunity_cutoff_at' => $opportunities?->source_cutoff_at,
        ];
    }

    /** @return array{resolved: array<string, object>, unresolved: int} Only exact bidirectional relationships are admitted. */
    private function opportunitiesForBatch(array $batch, int $directRunId, int $sourceRunId): array
    {
        $inverseIds = array_values(array_unique(array_filter(array_column($batch, 'inverse_opportunity_salesforce_id'))));
        $interestIds = array_column($batch, 'salesforce_id');
        $directs = DB::table('salesforce_opportunity_interest_directs')->where('direct_run_id', $directRunId)
            ->whereIn('interest_salesforce_id', $interestIds)
            ->get(['opportunity_salesforce_id', 'interest_salesforce_id', 'reference_status', 'opportunity_is_deleted'])
            ->groupBy('interest_salesforce_id');
        $inverseCounts = $inverseIds === [] ? collect() : DB::table('salesforce_interest_operational_rows')
            ->where('run_id', $sourceRunId)->where('is_deleted', false)
            ->whereIn('inverse_opportunity_salesforce_id', $inverseIds)
            ->groupBy('inverse_opportunity_salesforce_id')
            ->select('inverse_opportunity_salesforce_id', DB::raw('COUNT(*) as total'))
            ->pluck('total', 'inverse_opportunity_salesforce_id');
        $columns = array_values(array_unique(array_merge([
            'salesforce_id', 'stage_name', 'record_type_name', 'reservation', 'reservation_date', 'cv_signed', 'cv_signed_date',
            'is_deleted', 'deletion_detection_source',
        ], $this->amounts->opportunitySelectColumns())));
        $opportunities = $inverseIds === [] ? collect() : DB::table('salesforce_opportunities')
            ->whereIn('salesforce_id', $inverseIds)->get($columns)->keyBy('salesforce_id');
        $resolved = [];
        $unresolved = 0;
        foreach ($batch as $interest) {
            $id = $interest['inverse_opportunity_salesforce_id'];
            $references = $directs->get($interest['salesforce_id'], collect());
            if ($id === null && $references->isEmpty()) {
                continue;
            }
            $direct = $references->first();
            $opportunity = $opportunities->get($id);
            if ($id === null || $references->count() !== 1 || (int) $inverseCounts->get($id, 0) !== 1
                || $direct?->opportunity_salesforce_id !== $id
                || $direct?->reference_status !== 'valid' || $direct?->opportunity_is_deleted
                || $opportunity === null
                || ($opportunity->is_deleted && $opportunity->deletion_detection_source === SalesforceOpportunity::DELETION_SOURCE_QUERY_ALL)) {
                $unresolved++;

                continue;
            }
            $resolved[$interest['salesforce_id']] = $opportunity;
        }

        return ['resolved' => $resolved, 'unresolved' => $unresolved];
    }

    private function asMatchingInterest(array $row): object
    {
        return (object) [
            'salesforce_id' => $row['salesforce_id'],
            'campaign_acquired' => $row['utm_campaign'], 'acquired_id' => $row['utm_id'],
            'content_acquired' => $row['utm_content'], 'source_acquired' => $row['utm_source'],
            'medium_acquired' => $row['utm_medium'], 'utm_term' => $row['utm_term'],
            'campaign_acquired_source_field' => 'salesforce_interest_operational_rows.utm_campaign',
            'acquired_id_source_field' => 'salesforce_interest_operational_rows.utm_id',
            'content_acquired_source_field' => 'salesforce_interest_operational_rows.utm_content',
            'record_type_name' => $row['type'], 'source' => $row['source'],
            'original_source' => $row['original_source'], 'medium' => $row['medium'], 'channel' => $row['channel'],
        ];
    }

    private function hasAcquisitionEvidence(object $interest): bool
    {
        return $this->normalizer->isValidAttributionValue($interest->campaign_acquired)
            || $this->normalizer->isValidAttributionValue($interest->acquired_id)
            || $this->normalizer->isValidAttributionValue($interest->content_acquired);
    }

    private function key(string $id): string
    {
        return hash_hmac('sha256', $id, (string) config('app.key'));
    }
}
