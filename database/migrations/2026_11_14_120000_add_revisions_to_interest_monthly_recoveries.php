<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'salesforce_interest_monthly_recoveries';

    public function up(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'revision')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unsignedInteger('revision')->default(1);
            });
        }
        if (! Schema::hasColumn(self::TABLE, 'effective_cutoff_at')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dateTime('effective_cutoff_at')->nullable();
            });
        }

        if (Schema::hasIndex(self::TABLE, 'sf_imr_period_uq')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropUnique('sf_imr_period_uq');
            });
        }

        if (! Schema::hasIndex(self::TABLE, 'sf_imr_period_revision_uq')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(['functional_start_at', 'functional_end_at', 'revision'], 'sf_imr_period_revision_uq');
            });
        }
    }

    public function down(): void
    {
        if (DB::table(self::TABLE)->exists()) {
            throw new RuntimeException('Monthly recovery evidence must be retained during rollback.');
        }

        if (Schema::hasIndex(self::TABLE, 'sf_imr_period_revision_uq')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropUnique('sf_imr_period_revision_uq');
            });
        }
        if (! Schema::hasIndex(self::TABLE, 'sf_imr_period_uq')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(['functional_start_at', 'functional_end_at'], 'sf_imr_period_uq');
            });
        }
        if (Schema::hasColumn(self::TABLE, 'revision')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropColumn(['revision', 'effective_cutoff_at']);
            });
        }
    }
};
