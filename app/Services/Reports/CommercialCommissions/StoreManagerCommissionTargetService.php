<?php

namespace App\Services\Reports\CommercialCommissions;

use App\Models\CommercialCommissionClosure;
use App\Models\StoreManagerCommissionTarget;
use App\Models\StoreManagerCommissionTargetEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class StoreManagerCommissionTargetService
{
    public const SOURCE_TYPE_IMPORTED = 'imported';

    public const SOURCE_TYPE_INHERITED = 'inherited';

    public const SOURCE_TYPE_ADMIN = 'admin_override';

    private const OCTOBER_REFERENCE_FILE = 'database/seeders/StoreManagerCommissionTargetsOctober2026.csv';

    private const OCTOBER_REFERENCE_LABEL = 'Detalle por tienda — OBJETIVOS OCTUBRE ULTIMOS(1).xlsx';

    private const MATERIALIZATION_START = '2026-10-01';

    private const EDITABLE_FROM = '2026-11-01';

    private const MAX_MATERIALIZATION_MONTHS = 120;

    public function forMonth(CarbonImmutable|string $month): array
    {
        $key = $this->monthKey($month);
        if (! Schema::hasTable('store_manager_commission_targets')) {
            return [];
        }

        return $this->rowsForMonth($key)
            ->map(fn (StoreManagerCommissionTarget $target): array => $this->targetPayload($target))
            ->all();
    }

    /**
     * Materialize the sequential inheritance chain only when the selected
     * month is missing targets or has a partial set. Existing target values
     * always win; this method only inserts missing store keys.
     *
     * Definitive and pending delegation periods are read-only. They are not
     * completed automatically, so their snapshots remain the sole evidence.
     *
     * @return array<int, array<string, mixed>>
     */
    public function materializeForMonth(CarbonImmutable|string $month): array
    {
        $selected = $this->month($month);
        if (! Schema::hasTable('store_manager_commission_targets')
            || $selected->lt(CarbonImmutable::parse(self::MATERIALIZATION_START))) {
            return $this->forMonth($selected);
        }

        $selectedKey = $selected->format('Y-m');
        $existingRows = $this->rowsForMonth($selectedKey);
        if ($existingRows->isNotEmpty()) {
            $previousRows = $this->rowsForMonth($selected->subMonthNoOverflow()->format('Y-m'));
            $previousKeys = $previousRows->pluck('store_key')->all();
            $existingKeys = $existingRows->pluck('store_key')->all();
            $missingPreviousKeys = array_diff($previousKeys, $existingKeys);

            if ($previousRows->isEmpty()
                || ($missingPreviousKeys === [] && count($existingRows) >= count($previousRows))) {
                return $existingRows
                    ->map(fn (StoreManagerCommissionTarget $target): array => $this->targetPayload($target))
                    ->all();
            }
        }

        DB::transaction(function () use ($selected): void {
            $this->materializeChain($selected);
        });

        return $this->forMonth($selected);
    }

    /**
     * Update only changed monthly target values. The caller must pass only
     * the delivery and purchase values keyed by store_key; all identity,
     * zone and provenance fields come from the persisted rows.
     */
    public function updateForMonth(CarbonImmutable|string $month, array $values, int $reportUserId): int
    {
        $selected = $this->month($month);
        if (! $this->isEditableTargetMonth($selected)) {
            throw ValidationException::withMessages([
                'month' => 'Los objetivos originales de octubre de 2026 no se pueden modificar.',
            ]);
        }
        if (! Schema::hasTable('store_manager_commission_targets')) {
            throw ValidationException::withMessages([
                'targets' => 'No existe el esquema de objetivos de jefes de tienda.',
            ]);
        }

        return DB::transaction(function () use ($selected, $values, $reportUserId): int {
            $targets = $this->rowsForMonth($selected->format('Y-m'), true);
            if ($targets->isEmpty()) {
                throw ValidationException::withMessages([
                    'targets' => 'No hay objetivos materializados para el mes seleccionado.',
                ]);
            }

            $normalized = $this->normalizeEditableValues($values, $targets);
            $changes = $targets->filter(function (StoreManagerCommissionTarget $target) use ($normalized): bool {
                $incoming = $normalized[$target->store_key];

                return $target->delivery_target === null
                    || $target->purchase_target === null
                    || (int) $target->delivery_target !== $incoming['delivery_target']
                    || (int) $target->purchase_target !== $incoming['purchase_target'];
            });

            if ($changes->isEmpty()) {
                return 0;
            }

            $closure = $this->delegationClosure($selected->format('Y-m'), true);
            if ($closure?->status === CommercialCommissionClosure::STATUS_DEFINITIVE) {
                throw ValidationException::withMessages([
                    'month' => 'El bloque de Delegaciones es definitivo. Debe reabrirse antes de modificar objetivos.',
                ]);
            }
            if ($closure?->status === CommercialCommissionClosure::STATUS_PENDING_APPROVAL) {
                throw ValidationException::withMessages([
                    'month' => 'El candidato de Delegaciones está pendiente de aprobación. Debe prepararse de nuevo antes de modificar objetivos.',
                ]);
            }

            $operationId = (string) Str::uuid();
            $now = now();
            $updates = [];
            $events = [];
            foreach ($changes as $target) {
                $incoming = $normalized[$target->store_key];
                $before = [
                    'delivery_target' => $target->delivery_target === null ? null : (int) $target->delivery_target,
                    'purchase_target' => $target->purchase_target === null ? null : (int) $target->purchase_target,
                    'source_type' => $target->source_type ?: self::SOURCE_TYPE_IMPORTED,
                    'source_month' => $target->source_month,
                ];

                $updates[] = [
                    'month' => $target->month,
                    'store_key' => $target->store_key,
                    'delivery_target' => $incoming['delivery_target'],
                    'purchase_target' => $incoming['purchase_target'],
                    'source_type' => self::SOURCE_TYPE_ADMIN,
                    'source_month' => null,
                    'modified_by' => $reportUserId,
                    'updated_at' => $now,
                ];

                $events[] = [
                    'target_id' => $target->id,
                    'month' => $target->month,
                    'store_key' => $target->store_key,
                    'operation_id' => $operationId,
                    'event_type' => self::SOURCE_TYPE_ADMIN,
                    'source_month' => null,
                    'report_user_id' => $reportUserId,
                    'before_delivery_target' => $before['delivery_target'],
                    'before_purchase_target' => $before['purchase_target'],
                    'after_delivery_target' => $incoming['delivery_target'],
                    'after_purchase_target' => $incoming['purchase_target'],
                    'metadata' => [
                        'before_source_type' => $before['source_type'],
                        'before_source_month' => $before['source_month'],
                        'after_source_type' => self::SOURCE_TYPE_ADMIN,
                        'after_source_month' => null,
                    ],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            StoreManagerCommissionTarget::query()->upsert(
                $updates,
                ['month', 'store_key'],
                ['delivery_target', 'purchase_target', 'source_type', 'source_month', 'modified_by', 'updated_at'],
            );

            if ($events !== []) {
                $this->recordEvents($events);
            }

            return $changes->count();
        });
    }

    public function isEditableTargetMonth(CarbonImmutable|string $month): bool
    {
        return $this->month($month)->greaterThanOrEqualTo(CarbonImmutable::parse(self::EDITABLE_FROM));
    }

    public function originLabel(?string $sourceType): string
    {
        return match ($sourceType) {
            self::SOURCE_TYPE_INHERITED => 'Herencia mensual',
            self::SOURCE_TYPE_ADMIN => 'Modificación administrativa',
            default => 'Importación inicial',
        };
    }

    public function normalizeKey(string $name): string
    {
        $key = Str::of($name)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '-')->trim('-')->value();
        $key = (string) preg_replace('/^hr-motor-/', '', $key);

        return match ($key) {
            'a-coruna', 'acoruna' => 'a-coruna',
            'malaga-ortega' => 'malaga-ortega',
            'malaga-almacha' => 'malaga-almacha',
            'llica-de-vall' => 'llica-de-vall',
            default => $key,
        };
    }

    /**
     * Return the repository-controlled target reference for periods that have
     * an approved source file. The reference is intentionally independent from
     * the mutable target table used by the dashboard.
     *
     * @return array{month: string, source_sha256: string, source_label: string, targets: array<int, array<string, mixed>>, delivery_total: int, purchase_total: int}|null
     */
    public function approvedReferenceForMonth(CarbonImmutable|string $month): ?array
    {
        $key = $this->monthKey($month);
        if ($key !== '2026-10') {
            return null;
        }

        $path = base_path(self::OCTOBER_REFERENCE_FILE);
        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false || fgetcsv($handle) !== ['zone', 'store_name', 'delivery_target', 'purchase_target']) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            return null;
        }

        $targets = [];
        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null] || $row === []) {
                continue;
            }
            if (count($row) !== 4 || $row[0] === '' || $row[1] === '' || ! ctype_digit($row[2]) || ! ctype_digit($row[3])) {
                fclose($handle);

                return null;
            }

            [$zone, $storeName, $deliveryTarget, $purchaseTarget] = $row;
            $targets[] = [
                'zone' => $zone,
                'store_key' => $this->normalizeKey($storeName),
                'store_name' => $storeName,
                'target_deliveries' => (int) $deliveryTarget,
                'target_purchases' => (int) $purchaseTarget,
                'target_source' => [
                    'sha256' => hash('sha256', $contents),
                    'label' => self::OCTOBER_REFERENCE_LABEL,
                    'type' => self::SOURCE_TYPE_IMPORTED,
                    'source_month' => $key,
                ],
            ];
        }
        fclose($handle);

        return [
            'month' => $key,
            'source_sha256' => hash('sha256', $contents),
            'source_label' => self::OCTOBER_REFERENCE_LABEL,
            'targets' => $targets,
            'delivery_total' => array_sum(array_column($targets, 'target_deliveries')),
            'purchase_total' => array_sum(array_column($targets, 'target_purchases')),
        ];
    }

    private function materializeChain(CarbonImmutable $selected): void
    {
        $selectedKey = $selected->format('Y-m');
        $startKey = CarbonImmutable::parse(self::MATERIALIZATION_START)->format('Y-m');
        $rowsByMonth = StoreManagerCommissionTarget::query()
            ->whereBetween('month', [$startKey, $selectedKey])
            ->orderBy('month')
            ->orderBy('store_key')
            ->lockForUpdate()
            ->get()
            ->groupBy('month');

        if ($rowsByMonth->isEmpty()) {
            return;
        }

        $sourceMonth = (string) $rowsByMonth->keys()->sort()->first();
        $sourceRows = $rowsByMonth->get($sourceMonth, collect());
        $cursor = CarbonImmutable::createFromFormat('!Y-m', $sourceMonth)->addMonthNoOverflow()->startOfMonth();
        $operationId = $this->inheritanceOperationId($selectedKey);

        for ($attempt = 0; $attempt < self::MAX_MATERIALIZATION_MONTHS && $cursor->lessThanOrEqualTo($selected); $attempt++) {
            $monthKey = $cursor->format('Y-m');
            $currentRows = $rowsByMonth->get($monthKey, collect());
            $sourceByKey = $sourceRows->keyBy('store_key');
            $currentByKey = $currentRows->keyBy('store_key');
            $missing = $sourceByKey->reject(fn (StoreManagerCommissionTarget $target): bool => $currentByKey->has($target->store_key));

            if (! $this->hasCompleteTargetValues($sourceRows) || ! $this->hasCompleteTargetValues($currentRows)) {
                break;
            }

            if ($missing->isNotEmpty()) {
                if ($this->isProtectedMonth($monthKey)) {
                    break;
                }

                $this->insertInheritedTargets($monthKey, $missing, $operationId);
                $currentRows = $this->rowsForMonth($monthKey, true);
                $rowsByMonth->put($monthKey, $currentRows);
            }

            if ($currentRows->isEmpty()) {
                break;
            }

            $sourceRows = $currentRows;
            $cursor = $cursor->addMonthNoOverflow()->startOfMonth();
        }
    }

    /** @param Collection<int, StoreManagerCommissionTarget> $missing */
    private function insertInheritedTargets(string $month, Collection $missing, string $operationId): void
    {
        $sourceMonth = $missing->first()?->month;
        if ($sourceMonth === null) {
            return;
        }

        $now = now();
        $rows = $missing->map(fn (StoreManagerCommissionTarget $source): array => [
            'month' => $month,
            'zone' => $source->zone,
            'store_key' => $source->store_key,
            'store_name' => $source->store_name,
            'delivery_target' => $source->delivery_target,
            'purchase_target' => $source->purchase_target,
            'source_sha256' => $source->source_sha256,
            'source_label' => $source->source_label,
            'source_type' => self::SOURCE_TYPE_INHERITED,
            'source_month' => $sourceMonth,
            'imported_by' => null,
            'modified_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ])->values()->all();

        StoreManagerCommissionTarget::query()->insertOrIgnore($rows);
        if (! Schema::hasTable('store_manager_commission_target_events')) {
            return;
        }

        $persisted = StoreManagerCommissionTarget::query()
            ->where('month', $month)
            ->whereIn('store_key', $missing->pluck('store_key')->all())
            ->get()
            ->keyBy('store_key');

        $events = [];
        foreach ($missing as $source) {
            $target = $persisted->get($source->store_key);
            if (! $target || (string) $target->source_month !== $sourceMonth) {
                continue;
            }

            $events[] = [
                'target_id' => $target->id,
                'month' => $month,
                'store_key' => $target->store_key,
                'operation_id' => $operationId,
                'event_type' => self::SOURCE_TYPE_INHERITED,
                'source_month' => $sourceMonth,
                'report_user_id' => null,
                'before_delivery_target' => null,
                'before_purchase_target' => null,
                'after_delivery_target' => (int) $target->delivery_target,
                'after_purchase_target' => (int) $target->purchase_target,
                'metadata' => [
                    'source_type' => self::SOURCE_TYPE_INHERITED,
                    'source_sha256' => $target->source_sha256,
                    'source_label' => $target->source_label,
                ],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($events !== []) {
            $this->recordEvents($events);
        }
    }

    /** @param array<string, mixed> $values */
    private function normalizeEditableValues(array $values, Collection $targets): array
    {
        $knownKeys = $targets->pluck('store_key')->map(fn (mixed $key): string => (string) $key)->values()->all();
        $incomingKeys = array_map('strval', array_keys($values));
        sort($knownKeys);
        sort($incomingKeys);

        if ($knownKeys !== $incomingKeys) {
            throw ValidationException::withMessages([
                'targets' => 'El formulario debe conservar exactamente las tiendas materializadas del mes.',
            ]);
        }

        return collect($values)->mapWithKeys(function (mixed $value, string $storeKey): array {
            if (! is_array($value)
                || ! $this->isNonNegativeInteger($value['delivery_target'] ?? null)
                || ! $this->isNonNegativeInteger($value['purchase_target'] ?? null)) {
                throw ValidationException::withMessages([
                    'targets' => 'Los objetivos de cada tienda deben ser enteros no negativos.',
                ]);
            }

            return [$storeKey => [
                'delivery_target' => (int) $value['delivery_target'],
                'purchase_target' => (int) $value['purchase_target'],
            ]];
        })->all();
    }

    private function isNonNegativeInteger(mixed $value): bool
    {
        if (is_int($value)) {
            return $value >= 0;
        }

        return is_string($value) && preg_match('/^\d+$/D', $value) === 1;
    }

    /** @param Collection<int, StoreManagerCommissionTarget> $targets */
    private function hasCompleteTargetValues(Collection $targets): bool
    {
        return $targets->every(fn (StoreManagerCommissionTarget $target): bool =>
            $target->delivery_target !== null
            && $target->purchase_target !== null
            && (int) $target->delivery_target >= 0
            && (int) $target->purchase_target >= 0
        );
    }

    /** @return Collection<int, StoreManagerCommissionTarget> */
    private function rowsForMonth(string $month, bool $lock = false): Collection
    {
        $query = StoreManagerCommissionTarget::query()
            ->where('month', $month)
            ->orderBy('store_name');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /** @return array<string, mixed> */
    private function targetPayload(StoreManagerCommissionTarget $target): array
    {
        $sourceType = $target->source_type ?: self::SOURCE_TYPE_IMPORTED;
        $sourceMonth = $target->source_month ?: ($sourceType === self::SOURCE_TYPE_IMPORTED ? $target->month : null);

        return [
            'zone' => $target->zone,
            'store_key' => $target->store_key,
            'store_name' => $target->store_name,
            'target_deliveries' => $target->delivery_target,
            'target_purchases' => $target->purchase_target,
            'source_type' => $sourceType,
            'source_month' => $sourceMonth,
            'origin_label' => $this->originLabel($sourceType),
            'target_source' => [
                'sha256' => $target->source_sha256,
                'label' => $target->source_label,
                'type' => $sourceType,
                'source_month' => $sourceMonth,
            ],
        ];
    }

    private function isProtectedMonth(string $month): bool
    {
        $closure = $this->delegationClosure($month, true);

        return in_array($closure?->status, [
            CommercialCommissionClosure::STATUS_PENDING_APPROVAL,
            CommercialCommissionClosure::STATUS_DEFINITIVE,
        ], true);
    }

    private function delegationClosure(string $month, bool $lock = false): ?CommercialCommissionClosure
    {
        if (! Schema::hasTable('commercial_commission_closures')) {
            return null;
        }

        $query = CommercialCommissionClosure::query()->where([
            'month' => $month,
            'closure_scope' => CommercialCommissionClosure::SCOPE_DELEGATIONS,
        ]);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /** @param array<int, array<string, mixed>> $events */
    private function recordEvents(array $events): void
    {
        StoreManagerCommissionTargetEvent::query()->insertOrIgnore($events);
    }

    private function inheritanceOperationId(string $month): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'store-manager-target-inheritance:'.$month)->toString();
    }

    private function month(CarbonImmutable|string $month): CarbonImmutable
    {
        return $month instanceof CarbonImmutable
            ? $month->startOfMonth()
            : CarbonImmutable::createFromFormat('!Y-m', (string) $month)->startOfMonth();
    }

    private function monthKey(CarbonImmutable|string $month): string
    {
        return $this->month($month)->format('Y-m');
    }
}
