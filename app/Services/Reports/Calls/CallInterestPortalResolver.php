<?php

namespace App\Services\Reports\Calls;

class CallInterestPortalResolver
{
    public function __construct(
        private readonly CallPortalNormalizer $portalNormalizer,
    ) {}

    /** @return array{operational:array<string,mixed>,visible:array<string,mixed>,debug:array<string,mixed>} */
    public function resolve(mixed $portalesRaw, mixed $whatId, mixed $interest, ?array $existingVisible = null): array
    {
        $taskPortal = $this->portalNormalizer->normalize($portalesRaw);
        $reference = $this->referenceStatus($whatId, $interest);
        $interestSource = $interest === null
            ? $this->portalNormalizer->normalizeInterestSource(null)
            : $this->portalNormalizer->normalizeInterestSource(data_get($interest, 'source'));
        $debug = [
            'what_id' => $this->portalNormalizer->clean($whatId),
            'relationship_status' => $reference,
            'interest_matched' => $reference === 'exact_interest',
            'interest_id' => data_get($interest, 'salesforce_id'),
            'interest_is_deleted' => $interest === null ? null : (bool) data_get($interest, 'is_deleted'),
            'interest_source_raw' => data_get($interest, 'source'),
            'interest_source_used' => false,
            'preserved_historical' => false,
        ];

        if ($portalesRaw === null || $taskPortal['portal'] !== CallPortalNormalizer::UNCLASSIFIED) {
            return ['operational' => $taskPortal, 'visible' => $taskPortal, 'debug' => $debug];
        }

        if ($reference === 'exact_interest' && $interestSource['portal'] !== CallPortalNormalizer::UNCLASSIFIED) {
            $resolved = array_merge($interestSource, ['source' => 'interest']);
            $debug['interest_source_used'] = true;

            return ['operational' => $resolved, 'visible' => $resolved, 'debug' => $debug];
        }

        if ($reference !== 'exact_interest' && $existingVisible !== null && $this->isMeaningfulHistorical($existingVisible)) {
            $preserved = array_merge($existingVisible, ['source' => 'historical_preserved']);
            $debug['preserved_historical'] = true;

            return ['operational' => $preserved, 'visible' => $preserved, 'debug' => $debug];
        }

        return ['operational' => $taskPortal, 'visible' => $taskPortal, 'debug' => $debug];
    }

    private function referenceStatus(mixed $whatId, mixed $interest): string
    {
        $whatId = $this->portalNormalizer->clean($whatId);

        if ($whatId === null) {
            return 'no_reference';
        }

        if (preg_match('/^[A-Za-z0-9]{18}$/', $whatId) !== 1) {
            return 'invalid_reference';
        }

        return $interest === null ? 'interest_not_local' : 'exact_interest';
    }

    private function isMeaningfulHistorical(array $visible): bool
    {
        return ($visible['portal'] ?? null) !== null
            && $visible['portal'] !== CallPortalNormalizer::UNCLASSIFIED;
    }
}
