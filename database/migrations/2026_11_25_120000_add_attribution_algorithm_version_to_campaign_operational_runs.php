<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('campaign_operational_runs', 'attribution_algorithm_version')) {
            Schema::table('campaign_operational_runs', function (Blueprint $table): void {
                $table->string('attribution_algorithm_version', 80)->nullable()->after('projection_key');
                $table->index(['month_key', 'attribution_algorithm_version'], 'camp_op_runs_algorithm_idx');
            });
        }
    }

    public function down(): void
    {
        // Operational runs are audit evidence. Do not remove their algorithm
        // identity during a code rollback; a forward migration is required for
        // any future schema change.
    }
};
