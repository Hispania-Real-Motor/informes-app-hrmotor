<?php

namespace App\Services\Salesforce;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class SalesforceInterestFoundationResolver
{
    /**
     * Materialize the derived fields required by both Eloquent and bulk writes.
     *
     * Bulk insert/upsert operations do not dispatch Eloquent model events, so
     * callers must invoke this method before persisting each row.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function materialize(array $attributes): array
    {
        $person = $this->canonicalPerson(
            $attributes['account_salesforce_id'] ?? null,
            $attributes['lead_salesforce_id'] ?? null,
        );

        return [
            ...$attributes,
            'canonical_person_type' => $person['type'],
            'canonical_person_salesforce_id' => $person['salesforce_id'],
            'functional_created_at' => $this->functionalCreatedAt(
                $attributes['origin_created_at'] ?? null,
                $attributes['salesforce_created_at'] ?? null,
            ),
        ];
    }

    /** @return array{type: 'Account'|'Lead'|null, salesforce_id: string|null} */
    public function canonicalPerson(?string $accountId, ?string $leadId): array
    {
        $accountId = $this->cleanId($accountId);
        $leadId = $this->cleanId($leadId);

        if ($accountId !== null) {
            return ['type' => 'Account', 'salesforce_id' => $accountId];
        }

        if ($leadId !== null) {
            return ['type' => 'Lead', 'salesforce_id' => $leadId];
        }

        return ['type' => null, 'salesforce_id' => null];
    }

    public function functionalCreatedAt(
        CarbonInterface|string|null $originCreatedAt,
        CarbonInterface|string|null $salesforceCreatedAt,
    ): ?CarbonImmutable {
        $value = $this->presentDate($originCreatedAt)
            ? $originCreatedAt
            : $salesforceCreatedAt;

        return ! $this->presentDate($value)
            ? null
            : CarbonImmutable::parse($value);
    }

    private function presentDate(CarbonInterface|string|null $value): bool
    {
        return $value !== null && trim((string) $value) !== '';
    }

    private function cleanId(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
