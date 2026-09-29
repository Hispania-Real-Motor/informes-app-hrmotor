<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_interest_reconciliation_runs', function (Blueprint $table): void {
            $table->unsignedBigInteger('lead_dependency_run_id')->nullable()->after('status');
            $table->foreign('lead_dependency_run_id', 'sf_interest_recon_lead_dep_run_fk')
                ->references('id')
                ->on('salesforce_interest_lead_dependency_runs');
        });

        Schema::table('salesforce_interest_reconciliations', function (Blueprint $table): void {
            $table->string('lead_evidence_source', 40)->nullable()->after('current_lead_alignment');
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_interest_reconciliations', function (Blueprint $table): void {
            $table->dropColumn('lead_evidence_source');
        });

        Schema::table('salesforce_interest_reconciliation_runs', function (Blueprint $table): void {
            $table->dropForeign('sf_interest_recon_lead_dep_run_fk');
            $table->dropColumn('lead_dependency_run_id');
        });
    }
};
