<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_delegations', function (Blueprint $table): void {
            $table->foreignId('merged_into_id')
                ->nullable()
                ->after('is_commercial')
                ->constrained('stock_delegations')
                ->nullOnDelete();
            $table->index(['merged_into_id', 'is_commercial'], 'stock_delegations_operational_idx');
        });

        $this->addSelfMergeGuard();
    }

    public function down(): void
    {
        $this->dropSelfMergeGuard();

        Schema::table('stock_delegations', function (Blueprint $table): void {
            $table->dropIndex('stock_delegations_operational_idx');
            $table->dropConstrainedForeignId('merged_into_id');
        });
    }

    private function addSelfMergeGuard(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER stock_delegations_no_self_merge_insert
                BEFORE INSERT ON stock_delegations
                WHEN NEW.merged_into_id = NEW.id
                BEGIN
                    SELECT RAISE(ABORT, 'stock delegation cannot merge into itself');
                END
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER stock_delegations_no_self_merge_update
                BEFORE UPDATE OF merged_into_id ON stock_delegations
                WHEN NEW.merged_into_id = NEW.id
                BEGIN
                    SELECT RAISE(ABORT, 'stock delegation cannot merge into itself');
                END
                SQL);

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            // MariaDB and MySQL reject CHECK constraints that reference the
            // AUTO_INCREMENT primary key. Triggers provide the same invariant
            // without depending on that unsupported expression.
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER stock_delegations_no_self_merge_insert
                BEFORE INSERT ON stock_delegations
                FOR EACH ROW
                BEGIN
                    IF NEW.merged_into_id = NEW.id THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'stock delegation cannot merge into itself';
                    END IF;
                END
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER stock_delegations_no_self_merge_update
                BEFORE UPDATE ON stock_delegations
                FOR EACH ROW
                BEGIN
                    IF NEW.merged_into_id = NEW.id THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'stock delegation cannot merge into itself';
                    END IF;
                END
                SQL);

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement(
                'ALTER TABLE stock_delegations ADD CONSTRAINT stock_delegations_no_self_merge '
                .'CHECK (merged_into_id IS NULL OR merged_into_id <> id)',
            );
        }
    }

    private function dropSelfMergeGuard(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS stock_delegations_no_self_merge_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS stock_delegations_no_self_merge_update');

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS stock_delegations_no_self_merge_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS stock_delegations_no_self_merge_update');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE stock_delegations DROP CONSTRAINT stock_delegations_no_self_merge');
        }
    }
};
