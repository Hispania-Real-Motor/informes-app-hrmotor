<?php

namespace App\Services\Salesforce;

use Carbon\CarbonInterface;

/** Canonical functional fingerprint shared by monthly F2 inventory consumers. */
final class InterestMonthlyContentHasher
{
    /** @param array<string, mixed> $mapped */
    public function hash(array $mapped): string
    {
        unset($mapped['raw_payload'], $mapped['synced_at'], $mapped['created_at'], $mapped['updated_at']);

        return hash('sha256', json_encode($this->canonicalize($mapped), JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if ($value instanceof CarbonInterface) {
            return $value->utc()->toIso8601String();
        }
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
