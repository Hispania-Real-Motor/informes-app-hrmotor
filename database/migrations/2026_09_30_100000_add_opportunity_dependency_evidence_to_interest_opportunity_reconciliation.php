<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_interest_opportunity_reconciliation_runs', function (Blueprint $table): void {
            $table->unsignedBigInteger('opportunity_dependency_run_id')->nullable()->after('status');
            $table->foreign('opportunity_dependency_run_id', 'sf_int_opp_recon_dep_run_fk')
                ->references('id')
                ->on('salesforce_interest_opportunity_dependency_runs');

            $table->unsignedBigInteger('source_opportunity_sync_run_id')->nullable()->change();
            $table->string('source_opportunity_sync_status', 20)->nullable()->change();
            $table->dateTime('source_opportunity_period_end_at')->nullable()->change();
            $table->dateTime('source_opportunity_completed_at')->nullable()->change();
        });

        Schema::table('salesforce_interest_opportunity_reconciliations', function (Blueprint $table): void {
            $table->string('opportunity_evidence_source', 48)->nullable()
                ->after('opportunity_presence_status');
        });
    }

    public function down(): void
    {
        DB::table('salesforce_interest_opportunity_reconciliation_runs')
            ->whereNotNull('opportunity_dependency_run_id')
            ->delete();

        Schema::table('salesforce_interest_opportunity_reconciliations', function (Blueprint $table): void {
            $table->dropColumn('opportunity_evidence_source');
        });

        Schema::table('salesforce_interest_opportunity_reconciliation_runs', function (Blueprint $table): void {
            $table->dropForeign('sf_int_opp_recon_dep_run_fk');
            $table->dropColumn('opportunity_dependency_run_id');

            $table->unsignedBigInteger('source_opportunity_sync_run_id')->nullable(false)->change();
            $table->string('source_opportunity_sync_status', 20)->nullable(false)->change();
            $table->dateTime('source_opportunity_period_end_at')->nullable(false)->change();
            $table->dateTime('source_opportunity_completed_at')->nullable(false)->change();
        });
    }
};
