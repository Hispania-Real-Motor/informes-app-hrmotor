<?php

namespace App\Services\Reports\CommercialCommissions;

use App\Models\SalesforceOpportunity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single local-replica definition of a company delivery.
 *
 * Delegations, Area Manager and Contact Center management commissions must
 * always use this exact universe.  It deliberately has no owner, delegation,
 * zone or attribution restriction.
 */
class EligibleDeliveryOpportunityQuery
{
    /** @param array<int, string> $columns */
    public function forPeriod(CarbonImmutable $periodStart, CarbonImmutable $periodEnd, array $columns = ['*']): Builder
    {
        return SalesforceOpportunity::query()
            ->select($columns)
            ->where('cv_signed', true)
            ->whereNotNull('cv_signed_date')
            ->whereDate('cv_signed_date', '>=', $periodStart->toDateString())
            ->whereDate('cv_signed_date', '<', $periodEnd->toDateString())
            ->whereRaw("LOWER(COALESCE(stage_name, '')) <> ?", ['cerrada perdida'])
            ->where(function (Builder $query): void {
                $query->whereRaw("LOWER(COALESCE(record_type_name, '')) = ?", ['venta'])
                    ->orWhereRaw("LOWER(COALESCE(record_type_name, '')) = ?", ['cambio'])
                    ->orWhereRaw("LOWER(COALESCE(name, '')) LIKE ?", ['%facilitea%']);
            });
    }
}
