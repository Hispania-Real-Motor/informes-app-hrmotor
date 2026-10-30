<?php

namespace App\Services\Reports\ContactCenterCommissions;

use App\Models\CommercialCommissionDeliveryReconciliation;
use App\Models\CommercialCommissionProductDeliveryEvidence;
use App\Models\SalesforceOpportunity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class CommercialCommissionDeliveryReconciliationService
{
    /** @return array{rows: int, classifications: array<string, int>} */
    public function reconcile(CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        $start = CarbonImmutable::parse($periodStart)->toDateString();
        $end = CarbonImmutable::parse($periodEnd)->toDateString();
        $opportunities = SalesforceOpportunity::query()
            ->where('cv_signed', true)
            ->whereDate('cv_signed_date', '>=', $start)
            ->whereDate('cv_signed_date', '<', $end)
            ->get(['salesforce_id', 'vehicle_interest_id', 'cv_signed_date']);
        $productIds = $opportunities->pluck('vehicle_interest_id')->filter()->unique()->values();
        $duplicateProductIds = $opportunities->pluck('vehicle_interest_id')
            ->filter()
            ->countBy()
            ->filter(fn (int $count): bool => $count > 1)
            ->keys()
            ->flip();
        $products = CommercialCommissionProductDeliveryEvidence::query()
            ->where(function ($query) use ($start, $end, $productIds): void {
                $query->whereBetween('delivery_date', [$start, CarbonImmutable::parse($end)->subDay()->toDateString()]);
                if ($productIds->isNotEmpty()) {
                    $query->orWhereIn('salesforce_product_id', $productIds);
                }
            })
            ->get()
            ->keyBy('salesforce_product_id');

        $now = now();
        $rows = [];
        $seenProducts = [];
        foreach ($opportunities as $opportunity) {
            $productId = filled($opportunity->vehicle_interest_id) ? (string) $opportunity->vehicle_interest_id : null;
            $product = $productId === null ? null : $products->get($productId);
            if ($productId !== null) {
                $seenProducts[$productId] = true;
            }
            $type = $this->classification($opportunity, $product, $duplicateProductIds->has($productId));
            $rows[] = $this->row($start, $end, $opportunity->salesforce_id, $productId, $product, $opportunity->cv_signed_date, $type, $now);
        }
        foreach ($products as $product) {
            if (! isset($seenProducts[$product->salesforce_product_id])) {
                $rows[] = $this->row($start, $end, null, $product->salesforce_product_id, $product, null, 'PRODUCT2_ONLY', $now);
            }
        }

        DB::transaction(function () use ($start, $end, $rows): void {
            CommercialCommissionDeliveryReconciliation::query()
                ->where('period_start', $start)
                ->where('period_end', $end)
                ->delete();
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('commercial_commission_delivery_reconciliations')->insert($chunk);
            }
        });

        $classifications = [];
        foreach ($rows as $row) {
            $classifications[$row['discrepancy_type']] = ($classifications[$row['discrepancy_type']] ?? 0) + 1;
        }

        return ['rows' => count($rows), 'classifications' => $classifications];
    }

    private function classification(
        SalesforceOpportunity $opportunity,
        ?CommercialCommissionProductDeliveryEvidence $product,
        bool $duplicateProductLink,
    ): string
    {
        if ($duplicateProductLink) {
            return 'DUPLICATE_PRODUCT2_LINK';
        }
        if ($product === null) {
            return 'OPPORTUNITY_ONLY';
        }
        if ($product->delivery_date?->toDateString() !== $opportunity->cv_signed_date?->toDateString()) {
            return 'DATE_MISMATCH';
        }
        if ($product->state !== 'Entregado') {
            return 'STATE_MISMATCH';
        }

        return 'MATCH';
    }

    private function row(
        string $start,
        string $end,
        ?string $opportunityId,
        ?string $productId,
        ?CommercialCommissionProductDeliveryEvidence $product,
        mixed $opportunityDate,
        string $type,
        mixed $now,
    ): array {
        return [
            'reconciliation_key' => hash('sha256', implode('|', [$start, $end, $opportunityId, $productId, $type])),
            'period_start' => $start,
            'period_end' => $end,
            'opportunity_salesforce_id' => $opportunityId,
            'product_salesforce_id' => $productId,
            'plate' => $product?->plate,
            'opportunity_cv_signed_date' => $opportunityDate,
            'product_delivery_date' => $product?->delivery_date,
            'product_state' => $product?->state,
            'discrepancy_type' => $type,
            'reconciled_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
