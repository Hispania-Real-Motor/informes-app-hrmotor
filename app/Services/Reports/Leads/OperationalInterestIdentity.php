<?php

namespace App\Services\Reports\Leads;

use RuntimeException;

final class OperationalInterestIdentity
{
    public function key(int $runId, string $salesforceId): string
    {
        if ($runId < 1 || preg_match('/^[A-Za-z0-9]{18}$/', $salesforceId) !== 1) {
            throw new RuntimeException('Operational Interest identity is invalid.');
        }
        $secret = config('app.key');
        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('Operational Interest identity key is unavailable.');
        }

        return hash_hmac('sha256', 'operational-interest-v1|'.$runId.'|'.$salesforceId, $secret);
    }

    public function keyId(): string
    {
        $secret = config('app.key');
        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('Operational Interest identity key is unavailable.');
        }

        return substr(hash_hmac('sha256', 'operational-interest-identity-v1', $secret), 0, 16);
    }
}
