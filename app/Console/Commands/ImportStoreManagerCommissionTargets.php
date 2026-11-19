<?php

namespace App\Console\Commands;

use App\Models\StoreManagerCommissionTarget;
use App\Services\Reports\CommercialCommissions\StoreManagerCommissionTargetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportStoreManagerCommissionTargets extends Command
{
    protected $signature = 'commissions:import-store-manager-targets
        {file=database/seeders/StoreManagerCommissionTargetsOctober2026.csv}
        {--month=2026-10}
        {--write : Persist the validated rows; otherwise run as a dry-run}';

    protected $description = 'Validate and optionally import monthly store-manager targets';

    public function handle(StoreManagerCommissionTargetService $keys): int
    {
        $path = base_path($this->argument('file'));
        if (! is_file($path)) {
            $this->error('Target file not found.');
            return self::FAILURE;
        }

        $handle = fopen($path, 'rb');
        $header = fgetcsv($handle);
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) !== 4) {
                $this->error('Invalid column count in target file.');
                return self::FAILURE;
            }
            [$zone, $store, $deliveries, $purchases] = $row;
            if (! ctype_digit($deliveries) || ! ctype_digit($purchases) || $zone === '' || $store === '') {
                $this->error('Invalid target value for '.$store.'.');
                return self::FAILURE;
            }
            $rows[] = compact('zone', 'store', 'deliveries', 'purchases');
        }
        fclose($handle);

        $deliveryTotal = array_sum(array_column($rows, 'deliveries'));
        $purchaseTotal = array_sum(array_column($rows, 'purchases'));
        if (count($rows) !== 34 || $deliveryTotal !== 907 || $purchaseTotal !== 499) {
            $this->error('Expected 34 rows and totals 907/499.');
            return self::FAILURE;
        }

        $month = (string) $this->option('month');
        $source = hash_file('sha256', $path);
        $this->info(sprintf('Validated %d rows, totals %d/%d, source %s.', count($rows), $deliveryTotal, $purchaseTotal, $source));
        if (! $this->option('write')) {
            $this->comment('Dry-run: no database writes performed.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($rows, $month, $source, $keys): void {
            foreach ($rows as $row) {
                StoreManagerCommissionTarget::query()->updateOrCreate(
                    ['month' => $month, 'store_key' => $keys->normalizeKey($row['store'])],
                    [
                        'zone' => $row['zone'], 'store_name' => $row['store'],
                        'delivery_target' => (int) $row['deliveries'], 'purchase_target' => (int) $row['purchases'],
                        'source_sha256' => $source, 'source_label' => 'Detalle por tienda — OBJETIVOS OCTUBRE ULTIMOS(1).xlsx',
                    ]
                );
            }
        });

        $this->info('Targets imported idempotently.');
        return self::SUCCESS;
    }
}
