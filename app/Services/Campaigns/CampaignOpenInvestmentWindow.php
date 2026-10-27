<?php

namespace App\Services\Campaigns;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Coordinates investment writes with month closures without changing closed snapshots. */
class CampaignOpenInvestmentWindow
{
    private const LOCK_NAME = 'campaign-investment-economic-write';

    public function start(CarbonInterface $start, CarbonInterface $end): ?CarbonImmutable
    {
        $first = CarbonImmutable::parse($start);
        $lastClosedMonth = DB::selectOne(
            'SELECT MAX(month) AS last_closed_month FROM campaign_investment_closures WHERE status = ? AND month < ?',
            ['closed', $end->toDateString()],
            false,
        )?->last_closed_month;

        if ($lastClosedMonth !== null) {
            $cutoff = CarbonImmutable::parse($lastClosedMonth, $first->getTimezone())->startOfMonth()->addMonth();
            if ($first->lessThan($cutoff)) {
                $first = $cutoff;
            }
        }

        return $first->lessThan($end) ? $first : null;
    }

    public function synchronized(callable $callback): mixed
    {
        $usesNamedLock = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
        if ($usesNamedLock) {
            $result = DB::selectOne('SELECT GET_LOCK(?, 30) AS acquired', [self::LOCK_NAME], false);
            if ((int) ($result->acquired ?? 0) !== 1) {
                throw new RuntimeException('No se pudo reservar la protección de cierres de Campañas.');
            }
        }

        try {
            return $callback();
        } finally {
            if ($usesNamedLock) {
                DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [self::LOCK_NAME], false);
            }
        }
    }
}
