<?php

namespace App\Services\Reports\MonthlyCommercial;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class MonthlyCommercialPeriodService
{
    public const TIMEZONE = 'Europe/Madrid';

    public function periods(int $days = 30, ?CarbonInterface $now = null): array
    {
        $days = max($days, 1);
        $end = ($now ? CarbonImmutable::parse($now) : CarbonImmutable::now(self::TIMEZONE))
            ->setTimezone(self::TIMEZONE);
        $start = $end->subDays($days);
        $previousEnd = $start;
        $previousStart = $previousEnd->subDays($days);

        return [
            'current_start' => $start,
            'current_end' => $end,
            'previous_start' => $previousStart,
            'previous_end' => $previousEnd,
            'payload' => [
                'periodo_actual' => [
                    'inicio' => $start->toIso8601String(),
                    'fin' => $end->toIso8601String(),
                    'rango' => '[inicio, fin)',
                    'dias' => $days,
                ],
                'periodo_anterior' => [
                    'inicio' => $previousStart->toIso8601String(),
                    'fin' => $previousEnd->toIso8601String(),
                    'rango' => '[inicio, fin)',
                    'dias' => $days,
                ],
            ],
        ];
    }
}
