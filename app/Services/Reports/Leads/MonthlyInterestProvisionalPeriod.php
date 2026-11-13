<?php

namespace App\Services\Reports\Leads;

/** Explicit allowlist for administrative monthly dashboard periods. */
final class MonthlyInterestProvisionalPeriod
{
    /** @return null|array{year:int,month:int,flag:string,label:string} */
    public static function forKey(mixed $key): ?array
    {
        return match ($key) {
            'october_2026' => ['year' => 2026, 'month' => 10,
                'flag' => 'salesforce.october_2026_provisional_enabled', 'label' => 'Octubre de 2026'],
            'november_2026' => ['year' => 2026, 'month' => 11,
                'flag' => 'salesforce.november_2026_provisional_enabled', 'label' => 'Noviembre de 2026'],
            default => null,
        };
    }
}
