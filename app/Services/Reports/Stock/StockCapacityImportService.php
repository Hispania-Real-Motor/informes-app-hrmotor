<?php

namespace App\Services\Reports\Stock;

use App\Models\StockDelegation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class StockCapacityImportService
{
    public function __construct(
        private readonly CapacityFileReader $reader,
        private readonly StockDelegationService $delegations,
        private readonly StockDelegationNormalizer $normalizer,
    ) {}

    public function import(string $path, string $delimiter = ',', ?string $extension = null): array
    {
        $rows = $this->reader->read($path, $delimiter, $extension);
        [$headerIndex, $nameIndex, $capacityIndex] = $this->locateColumns($rows);
        $capacities = [];

        foreach (array_slice($rows, $headerIndex + 1) as $row) {
            $name = trim((string) ($row[$nameIndex] ?? ''));
            $capacity = $this->integer($row[$capacityIndex] ?? null);
            if ($name === '' || $capacity === null) {
                continue;
            }

            $key = $this->normalizer->normalize($name)['normalized_key'];
            if (! isset($capacities[$key]) || $capacity > $capacities[$key]['capacity']) {
                $capacities[$key] = ['name' => $name, 'capacity' => $capacity];
            }
        }

        if ($capacities === []) {
            throw new RuntimeException('No se encontraron capacidades válidas en la columna Plazas totales.');
        }

        DB::transaction(function () use ($capacities): void {
            StockDelegation::query()->operational()->update([
                'capacity_total' => null,
                'capacity_source_name' => null,
                'capacity_updated_at' => null,
                'is_commercial' => true,
            ]);

            StockDelegation::query()
                ->operational()
                ->whereIn('normalized_key', $this->normalizer->nonCommercialKeys())
                ->update(['is_commercial' => false]);

            foreach ($capacities as $capacity) {
                $this->delegations->applyCapacity($capacity['name'], $capacity['capacity']);
            }
        });

        return [
            'imported' => count($capacities),
            'rows' => array_values($capacities),
        ];
    }

    private function locateColumns(array $rows): array
    {
        foreach ($rows as $rowIndex => $row) {
            $headers = collect($row)->map(fn ($value) => $this->header($value))->all();
            $capacityIndex = collect($headers)->search(
                fn (string $header): bool => str_contains($header, 'plazas totales')
                    || str_contains($header, 'total plazas'),
            );

            if ($capacityIndex === false) {
                continue;
            }

            $nameIndex = collect($headers)->search(
                fn (string $header): bool => str_contains($header, 'delegacion')
                    || str_contains($header, 'tienda')
                    || str_contains($header, 'centro'),
            );

            return [$rowIndex, $nameIndex === false ? 0 : $nameIndex, $capacityIndex];
        }

        throw new RuntimeException('No se encontró la cabecera “Plazas totales”.');
    }

    private function header(mixed $value): string
    {
        return Str::of((string) $value)->lower()->ascii()->squish()->toString();
    }

    private function integer(mixed $value): ?int
    {
        if (is_string($value)) {
            $value = preg_replace('/[^\d\-]/', '', $value);
        }

        return is_numeric($value) ? max((int) $value, 0) : null;
    }
}
