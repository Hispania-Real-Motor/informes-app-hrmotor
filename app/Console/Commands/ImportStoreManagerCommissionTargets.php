<?php

namespace App\Console\Commands;

use App\Models\CommercialCommissionClosure;
use App\Models\CommercialCommissionSnapshot;
use App\Models\StoreManagerCommissionTarget;
use App\Models\StoreManagerCommissionTargetEvent;
use App\Services\Reports\CommercialCommissions\StoreManagerCommissionTargetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ImportStoreManagerCommissionTargets extends Command
{
    private const TARGET_SOURCE_LABEL = 'Detalle por tienda — OBJETIVOS OCTUBRE ULTIMOS(1).xlsx';

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
        $source = hash_file('sha256', $path);
        $this->info(sprintf('Validated %d rows, totals %d/%d, source %s.', count($rows), $deliveryTotal, $purchaseTotal, $source));
        if (! $this->option('write')) {
            $this->comment('Dry-run: no database writes performed.');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($rows, $month, $source, $keys): void {
                $this->assertWriteAllowed($month, $rows, $source, $keys);

                $now = now();
                $targets = array_map(fn (array $row): array => [
                    'month' => $month,
                    'zone' => $row['zone'],
                    'store_key' => $keys->normalizeKey($row['store']),
                    'store_name' => $row['store'],
                    'delivery_target' => (int) $row['deliveries'],
                    'purchase_target' => (int) $row['purchases'],
                    'source_sha256' => $source,
                    'source_label' => self::TARGET_SOURCE_LABEL,
                    'source_type' => StoreManagerCommissionTargetService::SOURCE_TYPE_IMPORTED,
                    'source_month' => $month,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $rows);

                $keysByStore = collect($targets)->pluck('store_key')->all();
                $existing = StoreManagerCommissionTarget::query()
                    ->where('month', $month)
                    ->whereIn('store_key', $keysByStore)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('store_key');
                $changed = collect($targets)->filter(function (array $target) use ($existing): bool {
                    $stored = $existing->get($target['store_key']);

                    return $stored === null
                        || (int) $stored->delivery_target !== $target['delivery_target']
                        || (int) $stored->purchase_target !== $target['purchase_target']
                        || (string) ($stored->source_sha256 ?? '') !== $target['source_sha256']
                        || (string) ($stored->source_label ?? '') !== $target['source_label']
                        || (string) ($stored->source_type ?? StoreManagerCommissionTargetService::SOURCE_TYPE_IMPORTED) !== $target['source_type']
                        || (string) ($stored->source_month ?? '') !== $target['source_month'];
                })->values();

                if ($changed->isEmpty()) {
                    return;
                }

                StoreManagerCommissionTarget::query()->upsert(
                    $changed->all(),
                    ['month', 'store_key'],
                    ['zone', 'store_name', 'delivery_target', 'purchase_target', 'source_sha256', 'source_label', 'source_type', 'source_month', 'updated_at'],
                );

                $operationId = (string) Str::uuid();
                $persisted = StoreManagerCommissionTarget::query()
                    ->where('month', $month)
                    ->whereIn('store_key', $changed->pluck('store_key')->all())
                    ->get()
                    ->keyBy('store_key');
                $eventsAvailable = Schema::hasTable('store_manager_commission_target_events');

                $events = [];
                foreach ($changed as $target) {
                    $stored = $existing->get($target['store_key']);
                    $persistedTarget = $persisted->get($target['store_key']);
                    if ($persistedTarget === null || ! $eventsAvailable) {
                        continue;
                    }

                    $events[] = [
                        'target_id' => $persistedTarget->id,
                        'month' => $month,
                        'store_key' => $target['store_key'],
                        'operation_id' => $operationId,
                        'event_type' => StoreManagerCommissionTargetService::SOURCE_TYPE_IMPORTED,
                        'source_month' => $month,
                        'report_user_id' => null,
                        'before_delivery_target' => $stored?->delivery_target,
                        'before_purchase_target' => $stored?->purchase_target,
                        'after_delivery_target' => $target['delivery_target'],
                        'after_purchase_target' => $target['purchase_target'],
                        'metadata' => json_encode([
                            'source_type' => StoreManagerCommissionTargetService::SOURCE_TYPE_IMPORTED,
                            'source_sha256' => $source,
                            'source_label' => self::TARGET_SOURCE_LABEL,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($eventsAvailable && $events !== []) {
                    StoreManagerCommissionTargetEvent::query()->insertOrIgnore($events);
                }
            });
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Targets imported idempotently.');

        return self::SUCCESS;
    }

    /** @param array<int, array<string, string>> $rows */
    private function assertWriteAllowed(string $month, array $rows, string $source, StoreManagerCommissionTargetService $keys): void
    {
        if (! Schema::hasTable('commercial_commission_closures')) {
            return;
        }

        $closure = CommercialCommissionClosure::query()
            ->where([
                'month' => $month,
                'closure_scope' => CommercialCommissionClosure::SCOPE_DELEGATIONS,
            ])
            ->lockForUpdate()
            ->first();

        if ($closure?->status === CommercialCommissionClosure::STATUS_DEFINITIVE) {
            throw new \RuntimeException('Approved delegation period cannot be modified.');
        }

        if ($closure?->status !== CommercialCommissionClosure::STATUS_PENDING_APPROVAL) {
            return;
        }

        $snapshot = Schema::hasTable('commercial_commission_snapshots')
            ? CommercialCommissionSnapshot::query()
                ->where('closure_id', $closure->id)
                ->where('version', (int) $closure->snapshot_version)
                ->lockForUpdate()
                ->first()
            : null;

        $frozenTargets = $snapshot === null
            ? null
            : data_get($snapshot->payload, 'delegations.store_manager_targets');
        $incomingTargets = $this->canonicalTargetDefinitions($rows, $source, $month, $keys);
        $candidateTargets = is_array($frozenTargets)
            ? $this->canonicalTargetDefinitions($frozenTargets, null, null, $keys)
            : [];

        if ($snapshot === null || $incomingTargets !== $candidateTargets) {
            throw new \RuntimeException('Pending delegation candidate would become obsolete; prepare it again before changing targets.');
        }
    }

    /** @param array<int, array<string, mixed>> $targets */
    private function canonicalTargetDefinitions(array $targets, ?string $source, ?string $sourceMonth, StoreManagerCommissionTargetService $keys): array
    {
        return collect($targets)
            ->filter(fn (mixed $target): bool => is_array($target))
            ->map(function (array $target) use ($source, $sourceMonth, $keys): array {
                $storeKey = (string) ($target['store_key'] ?? $target['storeKey'] ?? '');
                $storeName = (string) ($target['store_name'] ?? $target['store'] ?? '');

                return [
                    'zone' => (string) ($target['zone'] ?? ''),
                    'store_key' => $source === null ? $storeKey : $keys->normalizeKey($storeKey),
                    'store_name' => $storeName,
                    'target_deliveries' => (int) ($target['target_deliveries'] ?? $target['deliveries'] ?? -1),
                    'target_purchases' => (int) ($target['target_purchases'] ?? $target['purchases'] ?? -1),
                    'target_source' => [
                        'sha256' => $source ?? (string) data_get($target, 'target_source.sha256', ''),
                        'label' => $source === null
                            ? (string) data_get($target, 'target_source.label', '')
                            : self::TARGET_SOURCE_LABEL,
                        'type' => $source === null
                            ? (string) data_get($target, 'target_source.type', '')
                            : StoreManagerCommissionTargetService::SOURCE_TYPE_IMPORTED,
                        'source_month' => $source === null
                            ? (string) data_get($target, 'target_source.source_month', '')
                            : $sourceMonth,
                    ],
                ];
            })
            ->sortBy('store_key')
            ->values()
            ->all();
    }
}
