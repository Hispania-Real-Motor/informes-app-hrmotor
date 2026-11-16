<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
    }

    public function down(): void
    {
        Schema::table('stock_delegations', function (Blueprint $table): void {
            $table->dropIndex('stock_delegations_operational_idx');
            $table->dropConstrainedForeignId('merged_into_id');
        });
    }
};
