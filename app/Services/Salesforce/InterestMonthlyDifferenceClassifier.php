<?php

namespace App\Services\Salesforce;

use RuntimeException;

/** Restricted, value-free evidence for differences between current-state reads. */
final class InterestMonthlyDifferenceClassifier
{
    public const UNKNOWN = 1024;

    private const GROUPS = [
        'functional_fields' => [1, ['status', 'type', 'source', 'original_source', 'medium', 'channel', 'origin_delegation',
            'utm_campaign', 'utm_id', 'utm_source', 'utm_medium', 'utm_content', 'utm_term']],
        'relationships' => [2, ['lead_salesforce_id', 'account_salesforce_id', 'migration_origin_lead_id',
            'sale_vehicle_salesforce_id', 'appraisal_vehicle_salesforce_id', 'inverse_opportunity_salesforce_id']],
        'owner_identity' => [4, ['owner_salesforce_id']],
        'owner_name' => [8, ['owner_name']],
        'system_modstamp' => [16, ['system_modstamp_at']],
        'functional_membership' => [32, ['salesforce_created_at', 'origin_created_at', 'functional_created_at']],
        'deletion' => [64, ['is_deleted', 'salesforce_deleted_at', 'deletion_detection_source']],
        'derived_identity' => [128, ['canonical_person_type', 'canonical_person_salesforce_id']],
        'last_modified_date' => [512, ['salesforce_last_modified_at']],
    ];

    public function __construct(private readonly InterestMonthlyContentHasher $hasher) {}

    /** @param array<string, mixed> $mapped @return array<string, string|int> */
    public function fingerprints(array $mapped): array
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Monthly diagnostic key is unavailable.');
        }
        $result = ['version' => 1, 'key_id' => substr(hash_hmac('sha256', 'monthly-interest-diagnostic-v1', $key), 0, 16)];
        foreach (self::GROUPS as $name => [, $fields]) {
            $values = [];
            foreach ($fields as $field) {
                $values[$field] = $mapped[$field] ?? null;
            }
            $result[$name] = hash_hmac('sha256', $this->hasher->hash($values), $key);
        }

        return $result;
    }

    /** @param array<string, string|int> $current */
    public function changedMask(?string $stored, array $current): int
    {
        if ($stored === null) {
            return self::UNKNOWN;
        }
        $previous = json_decode($stored, true);
        if (! is_array($previous) || ($previous['version'] ?? null) !== 1
            || ! is_string($previous['key_id'] ?? null) || ! hash_equals($previous['key_id'], (string) ($current['key_id'] ?? ''))) {
            return self::UNKNOWN;
        }
        $mask = 0;
        foreach (self::GROUPS as $name => [$bit]) {
            if (! is_string($previous[$name] ?? null) || ! is_string($current[$name] ?? null)) {
                return self::UNKNOWN;
            }
            if (! hash_equals($previous[$name], $current[$name])) {
                $mask |= $bit;
            }
        }

        return $mask === 0 ? self::UNKNOWN : $mask;
    }

    /** @return array<string, int> */
    public function emptyCounts(): array
    {
        return array_fill_keys([...array_keys(self::GROUPS), 'unclassified'], 0);
    }

    /** @param array<string, int> $counts @return array<string, int> */
    public function countMask(array $counts, int $mask): array
    {
        if ($mask === self::UNKNOWN) {
            $counts['unclassified']++;

            return $counts;
        }
        foreach (self::GROUPS as $name => [$bit]) {
            if (($mask & $bit) !== 0) {
                $counts[$name]++;
            }
        }

        return $counts;
    }
}
