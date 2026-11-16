<?php

namespace App\Services\Reports\Stock;

use App\Models\SalesforceVehicle;
use App\Models\StockAvailabilityAlert;
use App\Models\StockDelegation;
use App\Services\Operations\OperationalAlertService;

class StockAvailabilityAlertService
{
    public function __construct(
        private readonly StockDelegationNormalizer $normalizer,
        private readonly OperationalAlertService $operationalAlerts,
    ) {}

    public function evaluate(): array
    {
        $result = ['opened' => 0, 'resolved' => 0, 'errors' => 0];
        $available = SalesforceVehicle::query()
            ->where('is_in_stock', true)
            ->where('state', 'Disponible')
            ->selectRaw('stock_delegation_id, COUNT(*) as total')
            ->groupBy('stock_delegation_id')
            ->pluck('total', 'stock_delegation_id');
        $activeByDelegation = StockAvailabilityAlert::query()
            ->where('state', 'open')
            ->latest('id')
            ->get()
            ->unique('stock_delegation_id')
            ->keyBy('stock_delegation_id');

        StockDelegation::query()->operational()->whereNotNull('capacity_total')->each(
            function (StockDelegation $delegation) use ($activeByDelegation, $available, &$result): void {
                if (! $this->normalizer->isCommercial($delegation->canonical_name)) {
                    return;
                }

                $active = $activeByDelegation->get($delegation->id);
                $hasAvailable = (int) ($available[$delegation->id] ?? 0) > 0;

                if ($hasAvailable) {
                    if ($active) {
                        $this->resolve($active, $result);
                    }

                    return;
                }

                $active ??= StockAvailabilityAlert::query()->create([
                    'stock_delegation_id' => $delegation->id,
                    'state' => 'open',
                    'opened_at' => now(),
                ]);

                if ($active->wasRecentlyCreated) {
                    $result['opened']++;
                    $activeByDelegation->put($delegation->id, $active);
                }

                $this->publishAdministrativeAlert($active, $delegation);
            },
        );

        return $result;
    }

    private function publishAdministrativeAlert(
        StockAvailabilityAlert $alert,
        StockDelegation $delegation,
    ): void {
        $this->operationalAlerts->open(
            type: 'stock_availability',
            severity: 'high',
            source: 'stock:sync-daily',
            technicalIdentifier: 'stock-delegation-'.$delegation->id,
            message: 'La delegación '.$delegation->canonical_name.' no tiene vehículos disponibles.',
            context: ['stock_delegation_id' => $delegation->id],
        );

        $alert->update(['last_error' => null]);
    }

    private function resolve(StockAvailabilityAlert $alert, array &$result): void
    {
        $alert->update(['state' => 'resolved', 'resolved_at' => now(), 'last_error' => null]);
        $this->operationalAlerts->resolve(
            type: 'stock_availability',
            source: 'stock:sync-daily',
            technicalIdentifier: 'stock-delegation-'.$alert->stock_delegation_id,
            resolution: 'La delegación ha recuperado vehículos disponibles.',
        );
        $result['resolved']++;
    }
}
