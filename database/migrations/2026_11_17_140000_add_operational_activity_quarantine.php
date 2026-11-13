<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('salesforce_interest_operational_activity_rows', 'quarantined_at')) {
            Schema::table('salesforce_interest_operational_activity_rows', function (Blueprint $table): void {
                $table->dateTime('quarantined_at')->nullable();
                $table->string('quarantine_reason', 32)->nullable();
                $table->index(['run_id', 'quarantined_at'], 'sf_iop_act_quarantine_idx');
            });
        }
        if (! Schema::hasColumn('salesforce_interest_operational_activity_runs', 'quarantined_count')) {
            Schema::table('salesforce_interest_operational_activity_runs', function (Blueprint $table): void {
                $table->unsignedInteger('quarantined_count')->default(0);
                $table->boolean('coverage_complete')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Preserve diagnostic evidence and completed run semantics on code rollback.
    }
};
