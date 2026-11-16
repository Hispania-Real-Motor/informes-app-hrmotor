<?php

namespace App\Http\Controllers\Reports\Stock;

use App\Http\Controllers\Controller;
use App\Models\StockDelegation;
use App\Services\Reports\Stock\StockCapacityImportService;
use App\Services\Reports\Stock\StockDelegationNormalizer;
use App\Support\ReportUserAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StockCapacityController extends Controller
{
    public function import(Request $request, StockCapacityImportService $importer): RedirectResponse
    {
        abort_unless(ReportUserAccess::isAdmin($request), 403);

        $data = $request->validate([
            'capacity_file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:10240'],
            'delimiter' => ['nullable', 'string', Rule::in([',', ';'])],
        ]);
        $file = $data['capacity_file'];
        $result = $importer->import(
            $file->getRealPath(),
            $data['delimiter'] ?? ',',
            $file->getClientOriginalExtension(),
        );

        return redirect()
            ->route('reports.stock.index', ['section' => 'capacities'])
            ->with('status', "Capacidades importadas correctamente: {$result['imported']} tiendas.");
    }

    public function update(Request $request, StockDelegationNormalizer $normalizer): RedirectResponse
    {
        abort_unless(ReportUserAccess::isAdmin($request), 403);

        $data = $request->validate([
            'capacities' => ['required', 'array'],
            'capacities.*' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);

        DB::transaction(function () use ($data, $normalizer): void {
            foreach ($data['capacities'] as $delegationId => $capacity) {
                $delegation = StockDelegation::query()->operational()->findOrFail((int) $delegationId);
                $hasCapacity = $capacity !== null && $capacity !== '';
                $delegation->update([
                    'capacity_total' => $hasCapacity ? (int) $capacity : null,
                    'capacity_source_name' => $hasCapacity ? 'Edición manual' : null,
                    'capacity_updated_at' => $hasCapacity ? now() : null,
                    // Commercial status is determined by the approved location list,
                    // not by whether a capacity has been assigned yet.
                    'is_commercial' => $normalizer->isCommercial($delegation->canonical_name),
                ]);
            }
        });

        return redirect()
            ->route('reports.stock.index', ['section' => 'capacities'])
            ->with('status', 'Capacidades actualizadas correctamente.');
    }
}
