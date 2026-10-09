<?php

namespace App\Services\Reports\ReservationsSales;

use App\Models\ReportSyncRun;
use App\Models\SalesforceInterest;
use App\Models\SalesforceOpportunityInterestDirect;
use App\Models\SalesforceOpportunityInterestDirectRun;
use App\Services\Reports\ReservasVentas\OpportunityPortalNormalizer;
use App\Services\Salesforce\SalesforceInterestSyncService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use RuntimeException;

class OpportunityInterestAttributionService
{
    public function __construct(private readonly OpportunityPortalNormalizer $portalNormalizer) {}

    /** @return array<string, mixed> */
    public function capture(?CarbonInterface $requiredOpportunityCutoff = null): array
    {
        $direct = SalesforceOpportunityInterestDirectRun::query()->orderByDesc('id')->first();
        $interest = $this->latestInterestRun();
        $available = $direct?->status === 'completed'
            && $direct->source_cutoff_at !== null
            && $interest?->status === 'completed'
            && $interest->source_cutoff_at !== null;
        $reason = null;

        if ($direct === null) {
            $reason = 'direct_snapshot_missing';
        } elseif ($direct->status !== 'completed' || $direct->source_cutoff_at === null) {
            $reason = 'direct_snapshot_not_completed';
        } elseif ($requiredOpportunityCutoff !== null && $direct->source_cutoff_at->lt($requiredOpportunityCutoff)) {
            $available = false;
            $reason = 'direct_snapshot_stale';
        } elseif ($interest === null || $interest->status !== 'completed' || $interest->source_cutoff_at === null) {
            $available = false;
            $reason = 'interest_snapshot_not_completed';
        }

        return [
            'available' => $available,
            'reason' => $reason,
            'direct_latest_id' => $direct?->id,
            'direct_run_id' => $direct?->status === 'completed' ? $direct->id : null,
            'direct_cutoff_at' => $direct?->source_cutoff_at?->toIso8601String(),
            'direct_status' => $direct?->status,
            'interest_run_id' => $interest?->id,
            'interest_cutoff_at' => $interest?->source_cutoff_at?->toIso8601String(),
            'interest_status' => $interest?->status,
            'required_opportunity_cutoff_at' => $requiredOpportunityCutoff?->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, object>  $opportunities
     * @param  array<string, mixed>  $context
     * @return Collection<string, array<string, mixed>>
     */
    public function resolve(Collection $opportunities, array $context): Collection
    {
        $opportunityIds = $opportunities->pluck('salesforce_id')->filter()->map(fn ($id) => (string) $id)->unique()->values();
        if ($opportunityIds->isEmpty()) {
            return collect();
        }

        if (! ($context['available'] ?? false)) {
            return $opportunityIds->mapWithKeys(fn (string $opportunityId): array => [
                $opportunityId => $this->resolution(null, collect(), collect(), $context),
            ]);
        }

        $directs = collect();
        if (filled($context['direct_run_id'] ?? null)) {
            $directs = SalesforceOpportunityInterestDirect::query()
                ->select([
                    'opportunity_salesforce_id', 'interest_salesforce_id', 'reference_status',
                    'opportunity_is_deleted',
                ])
                ->where('direct_run_id', $context['direct_run_id'])
                ->whereIn('opportunity_salesforce_id', $opportunityIds->all())
                ->get()
                ->keyBy('opportunity_salesforce_id');
        }

        $directInterestIds = $directs->pluck('interest_salesforce_id')->filter()->map(fn ($id) => (string) $id);
        $interests = SalesforceInterest::query()
            ->select(['salesforce_id', 'inverse_opportunity_salesforce_id', 'source', 'is_deleted'])
            ->where(function ($query) use ($opportunityIds, $directInterestIds): void {
                $query->whereIn('inverse_opportunity_salesforce_id', $opportunityIds->all());
                if ($directInterestIds->isNotEmpty()) {
                    $query->orWhereIn('salesforce_id', $directInterestIds->unique()->values()->all());
                }
            })
            ->get();
        $interestById = $interests->keyBy(fn (SalesforceInterest $interest): string => (string) $interest->salesforce_id);
        $inverseByOpportunity = $interests
            ->filter(fn (SalesforceInterest $interest): bool => filled($interest->inverse_opportunity_salesforce_id))
            ->groupBy(fn (SalesforceInterest $interest): string => (string) $interest->inverse_opportunity_salesforce_id);

        return $opportunityIds->mapWithKeys(function (string $opportunityId) use ($context, $directs, $interestById, $inverseByOpportunity): array {
            $direct = $directs->get($opportunityId);
            $inverse = $inverseByOpportunity->get($opportunityId, collect());

            return [$opportunityId => $this->resolution($direct, $inverse, $interestById, $context)];
        });
    }

    /** @param array<string, mixed> $context */
    public function validate(array $context): void
    {
        $currentDirect = SalesforceOpportunityInterestDirectRun::query()->orderByDesc('id')->first();
        $currentInterest = $this->latestInterestRun();

        if ($currentDirect?->id !== ($context['direct_latest_id'] ?? null)
            || $currentDirect?->status !== ($context['direct_status'] ?? null)
            || $currentDirect?->source_cutoff_at?->toIso8601String() !== ($context['direct_cutoff_at'] ?? null)
            || $currentInterest?->id !== ($context['interest_run_id'] ?? null)
            || $currentInterest?->status !== ($context['interest_status'] ?? null)
            || $currentInterest?->source_cutoff_at?->toIso8601String() !== ($context['interest_cutoff_at'] ?? null)) {
            throw new RuntimeException('Las fuentes locales de atribución cambiaron durante la construcción del informe.');
        }
    }

    /** @param array<string, mixed> $resolution @return array{portal:string,source:string} */
    public function effectivePortal(object $opportunity, array $resolution): array
    {
        $opportunityPortal = $this->portalNormalizer->normalize($opportunity->portal_original);
        if ($opportunityPortal['is_valid_final'] && $opportunityPortal['is_conclusive']) {
            return ['portal' => $opportunityPortal['portal'], 'source' => 'opportunity'];
        }

        if (($resolution['relationship_status'] ?? null) === 'both_match'
            && $this->portalNormalizer->isUsefulSource($resolution['interest_source'] ?? null)) {
            $interestPortal = $this->portalNormalizer->normalize($resolution['interest_source']);

            return ['portal' => $interestPortal['portal'], 'source' => 'interest'];
        }

        if ($this->portalNormalizer->isUsefulSource($opportunity->opportunity_source_raw)) {
            return [
                'portal' => $this->portalNormalizer->normalize($opportunity->opportunity_source_raw)['portal'],
                'source' => 'opportunity_source',
            ];
        }

        if ($this->portalNormalizer->isFallbackExpositionRaw($opportunity->portal_original)) {
            return ['portal' => OpportunityPortalNormalizer::EXPOSITION, 'source' => 'fallback_exposicion'];
        }

        if ($this->portalNormalizer->isFallbackWebRaw($opportunity->portal_original)) {
            return ['portal' => OpportunityPortalNormalizer::WEB, 'source' => 'fallback_web'];
        }

        return ['portal' => OpportunityPortalNormalizer::UNCLASSIFIED, 'source' => 'unclassified'];
    }

    /** @param Collection<int, SalesforceInterest> $inverse @param Collection<string, SalesforceInterest> $interestById @param array<string,mixed> $context */
    private function resolution(mixed $direct, Collection $inverse, Collection $interestById, array $context): array
    {
        $directId = filled($direct?->interest_salesforce_id) ? (string) $direct->interest_salesforce_id : null;
        $inverseIds = $inverse->pluck('salesforce_id')->map(fn ($id) => (string) $id)->filter()->unique()->values();
        $directInterest = $directId === null ? null : $interestById->get($directId);
        $status = match (true) {
            ! ($context['available'] ?? false) => 'unresolved',
            $direct !== null && ($direct->reference_status !== 'valid' || $direct->opportunity_is_deleted === true) => 'unresolved',
            $inverseIds->count() > 1 => 'inverse_shared',
            $directId !== null && $inverseIds->count() === 1 && $inverseIds->first() !== $directId => 'contradiction',
            $directId !== null && $inverseIds->count() === 1 && $directInterest !== null => 'both_match',
            $directId !== null && $directInterest === null => 'unresolved',
            $directId !== null => 'direct_only',
            $inverseIds->count() === 1 => 'inverse_only',
            default => 'no_reference',
        };

        return [
            'relationship_status' => $status,
            'direct_interest_id' => $directId,
            'inverse_interest_ids' => $inverseIds->all(),
            'inverse_reference_count' => $inverseIds->count(),
            'interest_id' => $status === 'both_match' ? $directId : null,
            'interest_source' => $status === 'both_match' ? $directInterest?->source : null,
            'interest_is_deleted' => $status === 'both_match' ? $directInterest?->is_deleted : null,
            'direct_reference_status' => $direct?->reference_status,
            'direct_opportunity_is_deleted' => $direct?->opportunity_is_deleted,
        ];
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
