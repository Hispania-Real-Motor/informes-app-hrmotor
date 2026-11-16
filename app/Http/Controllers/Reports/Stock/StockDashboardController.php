<?php

namespace App\Http\Controllers\Reports\Stock;

use App\Http\Controllers\Controller;
use App\Models\SalesforceOpportunity;
use App\Models\SalesforceSaleSnapshot;
use App\Models\SalesforceVehicle;
use App\Models\StockCatalogValue;
use App\Models\StockDailySnapshot;
use App\Models\StockDelegation;
use App\Services\Reports\Stock\StockCatalogNormalizer;
use App\Services\Reports\Stock\StockDashboardDatasetService;
use App\Services\Reports\Stock\StockDelegationNormalizer;
use App\Support\ReportUserAccess;
use App\Support\SimpleXlsxWorkbookWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StockDashboardController extends Controller
{
    public function index(
        Request $request,
        StockDelegationNormalizer $normalizer,
        StockDashboardDatasetService $datasetService,
        StockCatalogNormalizer $catalogNormalizer,
    ): View {
        $capacityDelegations = StockDelegation::query()
            ->operational()
            ->get()
            ->map(function (StockDelegation $delegation) use ($normalizer): StockDelegation {
                $delegation->setAttribute('is_commercial', $normalizer->isCommercial($delegation->canonical_name));

                return $delegation;
            })
            ->sortBy(fn (StockDelegation $delegation): string => sprintf(
                '%d-%s',
                $delegation->is_commercial ? 0 : 1,
                $delegation->normalized_key,
            ))
            ->values();

        $activeTab = $request->query('section');
        if (in_array($activeTab, ['sales', 'rankings'], true)) {
            $activeTab = 'delegations';
        }
        $allowedTabs = ['summary', 'delegations', 'recommendations', 'vehicles'];
        if (ReportUserAccess::isAdmin($request)) {
            $allowedTabs[] = 'capacities';
        }
        $activeTab = in_array($activeTab, $allowedTabs, true) ? $activeTab : 'summary';
        $transferOriginDelegations = $capacityDelegations
            ->where('is_commercial', true)
            ->values();
        $excludedDestinationKeys = collect(config('stock.excluded_destination_keys', []))
            ->map(fn ($value): string => $normalizer->key((string) $value))
            ->all();
        $transferDestinationDelegations = $transferOriginDelegations
            ->reject(fn (StockDelegation $delegation): bool => in_array(
                $normalizer->key($delegation->canonical_name),
                $excludedDestinationKeys,
                true,
            ))
            ->values();

        if ($activeTab === 'recommendations' && $request->query('transfer_plan') === '1') {
            $request->validate([
                'transfer_origin_id' => [
                    'required',
                    'integer',
                    Rule::in($transferOriginDelegations->pluck('id')->all()),
                ],
                'transfer_destination_id' => [
                    'required',
                    'integer',
                    'different:transfer_origin_id',
                    Rule::in($transferDestinationDelegations->pluck('id')->all()),
                ],
                'transfer_units' => [
                    'required',
                    'integer',
                    'min:1',
                    'max:'.max((int) config('stock.directed_transfer_max_units', 150), 1),
                ],
            ], [
                'transfer_origin_id.required' => 'Selecciona una delegación de origen.',
                'transfer_origin_id.in' => 'La delegación de origen no es válida para Stock.',
                'transfer_destination_id.required' => 'Selecciona una delegación de destino.',
                'transfer_destination_id.different' => 'La delegación de destino debe ser distinta del origen.',
                'transfer_destination_id.in' => 'La delegación de destino no es válida para recomendaciones.',
                'transfer_units.required' => 'Indica la capacidad del camión.',
                'transfer_units.integer' => 'La capacidad del camión debe ser un número entero.',
                'transfer_units.min' => 'La capacidad del camión debe ser mayor que cero.',
                'transfer_units.max' => 'La capacidad del camión no puede superar :max vehículos por simulación.',
            ]);
        }

        $dataset = $datasetService->build($request->query(), $activeTab);
        $quality = $activeTab === 'summary' ? $this->qualityMetrics($catalogNormalizer) : [];
        $canApproveCatalogAliases = ReportUserAccess::canApproveStockCatalogAliases($request);

        return view('reports.stock.index', [
            ...$dataset,
            'reportUserRole' => ReportUserAccess::role($request),
            'isAdmin' => ReportUserAccess::isAdmin($request),
            'capacityDelegations' => $capacityDelegations,
            'transferOriginDelegations' => $transferOriginDelegations,
            'transferDestinationDelegations' => $transferDestinationDelegations,
            'activeStockTab' => $activeTab,
            'latestSnapshotDate' => StockDailySnapshot::query()->max('snapshot_date'),
            'saleSnapshotsCount' => SalesforceSaleSnapshot::query()->count(),
            'stockDatasetCutoff' => SalesforceVehicle::query()->where('is_in_stock', true)->max('last_seen_stock_at'),
            'stockRuleVersion' => '2026-08-05.1',
            'quality' => $quality,
            'canApproveCatalogAliases' => $canApproveCatalogAliases,
            'catalogAliasTargets' => $canApproveCatalogAliases
                ? StockCatalogValue::query()
                    ->where('object_api_name', 'Product2')
                    ->where('is_active', true)
                    ->orderBy('field_api_name')
                    ->orderBy('label')
                    ->get(['id', 'field_api_name', 'api_value', 'label'])
                : collect(),
        ]);
    }

    private function qualityMetrics(StockCatalogNormalizer $catalogNormalizer): array
    {
        $vehicleCounts = SalesforceVehicle::query()
            ->where('is_in_stock', true)
            ->selectRaw('SUM(CASE WHEN entry_date IS NULL THEN 1 ELSE 0 END) AS missing_entry_date')
            ->selectRaw('SUM(CASE WHEN stock_delegation_id IS NULL THEN 1 ELSE 0 END) AS missing_delegation')
            ->selectRaw('SUM(CASE WHEN brand IS NULL THEN 1 ELSE 0 END) AS missing_brand')
            ->selectRaw('SUM(CASE WHEN model IS NULL THEN 1 ELSE 0 END) AS missing_model')
            ->selectRaw('SUM(CASE WHEN segment IS NULL THEN 1 ELSE 0 END) AS missing_segment')
            ->selectRaw('SUM(CASE WHEN fuel IS NULL THEN 1 ELSE 0 END) AS missing_fuel')
            ->selectRaw('SUM(CASE WHEN entry_date > ? THEN 1 ELSE 0 END) AS future_entry_date', [today(config('app.timezone'))->toDateString()])
            ->toBase()
            ->first();
        $saleCounts = SalesforceSaleSnapshot::query()
            ->selectRaw('SUM(CASE WHEN signed_date IS NULL THEN 1 ELSE 0 END) AS missing_signed_date')
            ->selectRaw('SUM(CASE WHEN delivery_store IS NULL THEN 1 ELSE 0 END) AS missing_delivery_store')
            ->selectRaw('SUM(CASE WHEN vehicle_entry_date IS NULL THEN 1 ELSE 0 END) AS missing_entry_date')
            ->selectRaw('SUM(CASE WHEN sale_price IS NULL THEN 1 ELSE 0 END) AS missing_price')
            ->toBase()
            ->first();
        $opportunityCounts = SalesforceOpportunity::query()
            ->selectRaw('SUM(CASE WHEN cv_signed_date IS NOT NULL AND cv_signed = 0 THEN 1 ELSE 0 END) AS signed_date_without_contract')
            ->selectRaw("SUM(CASE WHEN cv_signed = 1 AND LOWER(stage_name) = 'cerrada perdida' THEN 1 ELSE 0 END) AS signed_closed_lost")
            ->toBase()
            ->first();
        $qualityVehicles = SalesforceVehicle::query()
            ->where('is_in_stock', true)
            ->get(['salesforce_id', 'brand', 'model', 'segment', 'fuel', 'body', 'purchase_source']);

        return [
            'stock_missing_entry_date' => (int) ($vehicleCounts->missing_entry_date ?? 0),
            'stock_missing_delegation' => (int) ($vehicleCounts->missing_delegation ?? 0),
            'stock_missing_brand' => (int) ($vehicleCounts->missing_brand ?? 0),
            'stock_missing_model' => (int) ($vehicleCounts->missing_model ?? 0),
            'stock_missing_segment' => (int) ($vehicleCounts->missing_segment ?? 0),
            'stock_missing_fuel' => (int) ($vehicleCounts->missing_fuel ?? 0),
            'stock_delivered' => SalesforceVehicle::query()
                ->where('is_in_stock', true)
                ->whereIn('salesforce_id', SalesforceSaleSnapshot::query()->where('is_valid', true)->select('vehicle_salesforce_id'))
                ->count(),
            'stock_commercial_without_zone' => SalesforceVehicle::query()
                ->where('is_in_stock', true)
                ->whereHas('delegation', fn ($query) => $query->where('is_commercial', true)->whereNull('zone'))
                ->count(),
            'sales_missing_signed_date' => (int) ($saleCounts->missing_signed_date ?? 0),
            'sales_missing_delivery_store' => (int) ($saleCounts->missing_delivery_store ?? 0),
            'sales_missing_entry_date' => (int) ($saleCounts->missing_entry_date ?? 0),
            'sales_missing_price' => (int) ($saleCounts->missing_price ?? 0),
            'signed_date_without_contract' => (int) ($opportunityCounts->signed_date_without_contract ?? 0),
            'signed_closed_lost' => (int) ($opportunityCounts->signed_closed_lost ?? 0),
            'duplicate_valid_vehicle' => SalesforceSaleSnapshot::query()
                ->where('invalid_reason', 'duplicate_valid_vehicle')
                ->whereNotNull('vehicle_salesforce_id')
                ->distinct()
                ->count('vehicle_salesforce_id'),
            'signed_unexpected_stage' => $this->unexpectedSignedStageQuery()->count(),
            'future_entry_date' => (int) ($vehicleCounts->future_entry_date ?? 0),
            'stores_without_capacity' => StockDelegation::query()
                ->operational()
                ->where('is_commercial', true)
                ->where(fn ($query) => $query->whereNull('capacity_total')->orWhere('capacity_total', '<=', 0))
                ->count(),
            'catalog_duplicates' => $catalogNormalizer->duplicateGroups($qualityVehicles)->count(),
            'non_operational_catalog_values' => $catalogNormalizer->excludedVehicles($qualityVehicles)->count(),
        ];
    }

    public function exportQualityXlsx(
        Request $request,
        SimpleXlsxWorkbookWriter $workbookWriter,
        StockCatalogNormalizer $catalogNormalizer,
    ) {
        try {
            $vehicles = SalesforceVehicle::query()->where('is_in_stock', true);
            $sales = SalesforceSaleSnapshot::query();
            $allVehicles = $vehicles->clone()->get();
            $path = $workbookWriter->write([
                $this->vehicleQualitySheet('Stock sin entrada', $vehicles->clone()->whereNull('entry_date')->get()),
                $this->vehicleQualitySheet('Stock sin delegacion', $vehicles->clone()->whereNull('stock_delegation_id')->get()),
                $this->vehicleQualitySheet('Stock sin marca', $vehicles->clone()->whereNull('brand')->get()),
                $this->vehicleQualitySheet('Stock sin modelo', $vehicles->clone()->whereNull('model')->get()),
                $this->vehicleQualitySheet('Stock sin segmento', $vehicles->clone()->whereNull('segment')->get()),
                $this->vehicleQualitySheet('Stock sin combustible', $vehicles->clone()->whereNull('fuel')->get()),
                $this->vehicleQualitySheet('Entregados aun en stock', $vehicles->clone()
                    ->whereIn('salesforce_id', SalesforceSaleSnapshot::query()->where('is_valid', true)->select('vehicle_salesforce_id'))->get()),
                $this->vehicleQualitySheet('Tiendas sin zona', $vehicles->clone()
                    ->whereHas('delegation', fn ($query) => $query->where('is_commercial', true)->whereNull('zone'))->get()),
                $this->saleQualitySheet('Ventas sin firma', $sales->clone()->whereNull('signed_date')->get()),
                $this->saleQualitySheet('Ventas sin tienda', $sales->clone()->whereNull('delivery_store')->get()),
                $this->saleQualitySheet('Ventas sin entrada', $sales->clone()->whereNull('vehicle_entry_date')->get()),
                $this->saleQualitySheet('Ventas sin precio', $sales->clone()->whereNull('sale_price')->get()),
                $this->opportunityQualitySheet('Firma sin contrato', SalesforceOpportunity::query()
                    ->whereNotNull('cv_signed_date')->where('cv_signed', false)->get()),
                $this->opportunityQualitySheet('Firmados cerrada perdida', SalesforceOpportunity::query()
                    ->where('cv_signed', true)->whereRaw('LOWER(stage_name) = ?', ['cerrada perdida'])->get()),
                $this->saleQualitySheet('Vehiculos venta duplicada', $sales->clone()
                    ->where('invalid_reason', 'duplicate_valid_vehicle')->get()),
                $this->opportunityQualitySheet('Fases inesperadas', $this->unexpectedSignedStageQuery()->get()),
                $this->vehicleQualitySheet('Entradas futuras', $vehicles->clone()
                    ->whereDate('entry_date', '>', today(config('app.timezone')))->get()),
                $this->delegationQualitySheet('Tiendas sin capacidad', StockDelegation::query()
                    ->operational()
                    ->where('is_commercial', true)
                    ->where(fn ($query) => $query->whereNull('capacity_total')->orWhere('capacity_total', '<=', 0))
                    ->get()),
                $this->catalogDuplicateQualitySheet('Catalogos duplicados', $catalogNormalizer->duplicateGroups($allVehicles)),
                $this->vehicleQualitySheet('Valores no operativos', $catalogNormalizer->excludedVehicles($allVehicles)),
            ]);

            return response()
                ->download($path, 'calidad-dato-stock-'.now()->format('Ymd-His').'.xlsx', [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ])
                ->deleteFileAfterSend(true);
        } catch (\Throwable $exception) {
            Log::error('No se pudo exportar la calidad de datos de stock.', ['exception' => $exception]);

            return redirect()
                ->route('reports.stock.index', ['section' => 'summary'])
                ->withErrors(['quality_export' => 'No se pudo generar el Excel de calidad del dato.']);
        }
    }

    /** @return array{name: string, headers: array<int, string>, rows: array<int, array<int, string|int|float|null>>} */
    private function vehicleQualitySheet(string $name, iterable $vehicles): array
    {
        return [
            'name' => $name,
            'headers' => ['ID Salesforce vehiculo', 'Matricula', 'Vehiculo', 'Estado', 'Delegacion Salesforce', 'Fecha entrada', 'Segmento', 'Combustible'],
            'rows' => collect($vehicles)->map(fn (SalesforceVehicle $vehicle): array => [
                $vehicle->salesforce_id,
                $vehicle->plate,
                $vehicle->name,
                $vehicle->state,
                $vehicle->salesforce_delegation_name,
                $vehicle->entry_date?->toDateString(),
                $vehicle->segment,
                $vehicle->fuel,
            ])->all(),
        ];
    }

    /** @return array{name: string, headers: array<int, string>, rows: array<int, array<int, string|int|float|null>>} */
    private function saleQualitySheet(string $name, iterable $sales): array
    {
        $sales = collect($sales);
        $vehiclesBySalesforceId = SalesforceVehicle::query()
            ->whereIn('salesforce_id', $sales->pluck('vehicle_salesforce_id')->filter()->unique()->values())
            ->get(['salesforce_id', 'plate'])
            ->keyBy('salesforce_id');

        return [
            'name' => $name,
            'headers' => ['ID Salesforce oportunidad', 'ID Salesforce vehiculo', 'Matricula', 'Oportunidad', 'Tipo', 'Fecha firma', 'Tienda entrega', 'Fecha entrada', 'Precio contractual'],
            'rows' => $sales->map(fn (SalesforceSaleSnapshot $sale): array => [
                $sale->opportunity_salesforce_id,
                $sale->vehicle_salesforce_id,
                $sale->vehicle_plate ?: $vehiclesBySalesforceId->get($sale->vehicle_salesforce_id)?->plate,
                $sale->opportunity_name,
                $sale->record_type,
                $sale->signed_date?->toDateString(),
                $sale->delivery_store,
                $sale->vehicle_entry_date?->toDateString(),
                $sale->sale_price,
            ])->all(),
        ];
    }

    private function opportunityQualitySheet(string $name, iterable $opportunities): array
    {
        return [
            'name' => $name,
            'headers' => ['ID Salesforce oportunidad', 'ID Salesforce vehiculo', 'Matricula', 'Oportunidad', 'Tipo', 'Fase', 'Contrato firmado', 'Fecha firma', 'Tienda entrega'],
            'rows' => collect($opportunities)->map(fn (SalesforceOpportunity $opportunity): array => [
                $opportunity->salesforce_id,
                $opportunity->vehicle_interest_id,
                $opportunity->vehicle_plate,
                $opportunity->name,
                $opportunity->record_type_name,
                $opportunity->stage_name,
                $opportunity->cv_signed ? 'Sí' : 'No',
                $opportunity->cv_signed_date?->toDateString(),
                $opportunity->delivery_store,
            ])->all(),
        ];
    }

    private function delegationQualitySheet(string $name, iterable $delegations): array
    {
        return [
            'name' => $name,
            'headers' => ['Delegación', 'Nombre Salesforce', 'Zona', 'Grupo', 'Capacidad'],
            'rows' => collect($delegations)->map(fn (StockDelegation $delegation): array => [
                $delegation->canonical_name,
                $delegation->salesforce_name,
                $delegation->zone,
                $delegation->commercial_group,
                $delegation->capacity_total,
            ])->all(),
        ];
    }

    private function catalogDuplicateQualitySheet(string $name, iterable $groups): array
    {
        return [
            'name' => $name,
            'headers' => ['Catálogo', 'Clave normalizada', 'Valores originales', 'IDs Salesforce de vehículos'],
            'rows' => collect($groups)->map(fn (array $group): array => [
                $group['dimension'],
                $group['normalized_key'],
                implode(' | ', $group['raw_values']),
                implode(' | ', $group['vehicles']),
            ])->all(),
        ];
    }

    private function unexpectedSignedStageQuery()
    {
        $expected = collect(config('stock.expected_signed_stages', []))
            ->map(fn ($stage): string => Str::of((string) $stage)->lower()->ascii()->squish()->toString())
            ->values()
            ->all();

        return SalesforceOpportunity::query()
            ->where('cv_signed', true)
            ->where(function ($query) use ($expected): void {
                $query->whereNull('stage_name')
                    ->orWhereNotIn(\DB::raw('LOWER(stage_name)'), [...$expected, 'cerrada perdida']);
            });
    }
}
