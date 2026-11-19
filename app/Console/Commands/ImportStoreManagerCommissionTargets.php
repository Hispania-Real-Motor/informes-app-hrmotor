<?php

namespace App\Console\Commands;

use App\Models\StoreManagerCommissionTarget;
use App\Services\Reports\CommercialCommissions\StoreManagerCommissionTargetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        if ($header !== ['zone', 'store_name', 'delivery_target', 'purchase_target']) {
            $this->error('Invalid target CSV header.');

            return self::FAILURE;
        }
        $rows = [];
        $keysSeen = [];
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
            $storeKey = $keys->normalizeKey($store);
            if (isset($keysSeen[$storeKey]) || ! in_array($zone, ['Levante', 'Norte', 'Cataluña', 'Sur', 'Madrid'], true)) {
                $this->error('Duplicate or unknown store mapping for '.$store.'.');

                return self::FAILURE;
            }
            $keysSeen[$storeKey] = true;
            $rows[] = compact('zone', 'store', 'deliveries', 'purchases', 'storeKey');
        }
        fclose($handle);

        $deliveryTotal = array_sum(array_column($rows, 'deliveries'));
        $purchaseTotal = array_sum(array_column($rows, 'purchases'));
        if (count($rows) !== 34 || $deliveryTotal !== 907 || $purchaseTotal !== 499) {
            $this->error('Expected 34 rows and totals 907/499.');

            return self::FAILURE;
        }

        $month = (string) $this->option('month');
        if (Schema::hasTable('commercial_commission_closures')) {
            $approved = DB::table('commercial_commission_closures')
                ->where('month', $month)->where('status', 'definitive')->exists();
            if ($approved) {
                $this->error('Approved delegation period cannot be modified.');

                return self::FAILURE;
            }
        }
        $source = hash_file('sha256', $path);
        $this->info(sprintf('Validated %d rows, totals %d/%d, source %s.', count($rows), $deliveryTotal, $purchaseTotal, $source));
        if (!$this->option('write')) {
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
