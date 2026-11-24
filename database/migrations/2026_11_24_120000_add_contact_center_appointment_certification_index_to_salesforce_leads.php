<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'salesforce_leads';

    private const INDEX = 'sf_leads_contact_center_appointment_idx';

    private const COLUMNS = [
        'appointment_setter_id',
        'is_deleted',
        'salesforce_master_record_id',
        'appointment_capture_date',
    ];

    public function up(): void
    {
        $indexes = Schema::getIndexes(self::TABLE);
        $namedIndex = $this->indexNamed($indexes, self::INDEX);

        if ($namedIndex !== null) {
            if (! $this->sameColumns($namedIndex)) {
                throw new RuntimeException('El índice de Contact Center existe con columnas incompatibles.');
            }

            return;
        }

        if (collect($indexes)->contains(fn (array $index): bool => $this->sameColumns($index))) {
            return;
        }

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE `salesforce_leads` ADD INDEX `sf_leads_contact_center_appointment_idx` '.
                '(`appointment_setter_id`, `is_deleted`, `salesforce_master_record_id`, `appointment_capture_date`), '.
                'ALGORITHM=INPLACE, LOCK=NONE',
            );

            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->index(self::COLUMNS, self::INDEX);
        });
    }

    public function down(): void
    {
        $index = $this->indexNamed(Schema::getIndexes(self::TABLE), self::INDEX);

        if ($index === null || ! $this->sameColumns($index)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }

    /** @param array<int, array<string, mixed>> $indexes */
    private function indexNamed(array $indexes, string $name): ?array
    {
        foreach ($indexes as $index) {
            if (($index['name'] ?? null) === $name) {
                return $index;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $index */
    private function sameColumns(array $index): bool
    {
        return array_values($index['columns'] ?? []) === self::COLUMNS;
    }
};
