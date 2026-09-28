<?php

namespace App\Services\Salesforce;

use App\Models\SalesforceInterest;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

class SalesforceInterestChunkPersister
{
    /**
     * MySQL/MariaDB upsert is deliberately forbidden here: it ignores Laravel's
     * uniqueBy and may select migration_origin_lead_id instead of salesforce_id.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{inserted:int, updated:int, unchanged:int}
     */
    public function persist(array $rows): array
    {
        if ($rows === []) {
            return ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];
        }

        $existingBySalesforceId = SalesforceInterest::query()
            ->whereIn('salesforce_id', array_column($rows, 'salesforce_id'))
            ->get()
            ->keyBy('salesforce_id');

        $this->assertMigrationOriginsAreUnambiguous($rows);

        $inserts = [];
        $updates = [];
        $unchanged = 0;
        $now = now();

        foreach ($rows as $row) {
            /** @var SalesforceInterest|null $current */
            $current = $existingBySalesforceId->get((string) $row['salesforce_id']);

            if ($current === null) {
                $inserts[] = [
                    ...$row,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                continue;
            }

            if (! $this->hasPersistedChange($current, $row)) {
                $unchanged++;

                continue;
            }

            $updates[] = ['model' => $current, 'row' => $row];
        }

        if ($inserts !== []) {
            SalesforceInterest::query()->insert($inserts);
        }

        foreach ($updates as $update) {
            /** @var SalesforceInterest $current */
            $current = $update['model'];
            $values = $update['row'];
            unset($values['salesforce_id']);

            SalesforceInterest::query()
                ->whereKey($current->getKey())
                ->update($values);
        }

        return [
            'inserted' => count($inserts),
            'updated' => count($updates),
            'unchanged' => $unchanged,
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function assertMigrationOriginsAreUnambiguous(array $rows): void
    {
        $incomingOwners = [];

        foreach ($rows as $row) {
            $origin = $this->nullableString($row['migration_origin_lead_id'] ?? null);

            if ($origin !== null) {
                $incomingOwners[$origin][] = (string) $row['salesforce_id'];
            }
        }

        $conflictingIds = [];

        foreach ($incomingOwners as $salesforceIds) {
            $uniqueSalesforceIds = array_values(array_unique($salesforceIds));

            if (count($uniqueSalesforceIds) > 1) {
                array_push($conflictingIds, ...$uniqueSalesforceIds);
            }
        }

        if ($incomingOwners !== []) {
            $persistedOwners = SalesforceInterest::query()
                ->whereIn('migration_origin_lead_id', array_keys($incomingOwners))
                ->get(['salesforce_id', 'migration_origin_lead_id'])
                ->keyBy('migration_origin_lead_id');

            foreach ($incomingOwners as $origin => $incomingSalesforceIds) {
                /** @var SalesforceInterest|null $persistedOwner */
                $persistedOwner = $persistedOwners->get($origin);

                if ($persistedOwner === null) {
                    continue;
                }

                foreach (array_unique($incomingSalesforceIds) as $incomingSalesforceId) {
                    if ($incomingSalesforceId !== $persistedOwner->salesforce_id) {
                        $conflictingIds[] = $incomingSalesforceId;
                    }
                }
            }
        }

        $conflictingIds = array_values(array_unique($conflictingIds));

        if ($conflictingIds !== []) {
            throw new SalesforceInterestPersistenceConflict($conflictingIds);
        }
    }

    /** @param array<string, mixed> $incoming */
    private function hasPersistedChange(SalesforceInterest $current, array $incoming): bool
    {
        foreach ($incoming as $attribute => $value) {
            if ($attribute === 'salesforce_id' || $attribute === 'synced_at') {
                continue;
            }

            if ($this->normalize($current, $attribute, $current->getAttribute($attribute))
                !== $this->normalize($current, $attribute, $value)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(Model $model, string $attribute, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        $cast = strtolower((string) ($model->getCasts()[$attribute] ?? ''));
        $cast = explode(':', $cast, 2)[0];

        if (in_array($cast, ['array', 'json'], true)) {
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                $value = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
            }

            return $this->normalizeJson($value);
        }

        if (in_array($cast, ['bool', 'boolean'], true)) {
            return (bool) $value;
        }

        if (in_array($cast, ['int', 'integer'], true)) {
            return (int) $value;
        }

        if ($cast === 'date') {
            return CarbonImmutable::parse($value)->format('Y-m-d');
        }

        if (str_starts_with($cast, 'datetime') || $value instanceof DateTimeInterface) {
            return CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s');
        }

        return $value;
    }

    private function normalizeJson(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value === '' ? null : $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeJson($item);
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
