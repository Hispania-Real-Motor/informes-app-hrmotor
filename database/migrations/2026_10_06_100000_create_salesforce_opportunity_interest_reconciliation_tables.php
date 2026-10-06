<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salesforce_opportunity_interest_reconciliation_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_identifier')->unique('sf_opp_int_recon_runs_identifier_uq');
            $table->string('reason', 500);
            $table->string('status', 20)->index('sf_opp_int_recon_runs_status_idx');
            $table->unsignedBigInteger('direct_run_id');
            $table->dateTime('direct_cutoff_at');
            $table->unsignedBigInteger('inverse_run_id');
            $table->unsignedBigInteger('inverse_interest_sync_run_id');
            $table->dateTime('inverse_interest_cutoff_at');
            $table->dateTime('started_at');
            $table->dateTime('completed_at')->nullable();
            $table->json('stats')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('direct_run_id', 'sf_opp_int_recon_direct_run_fk')
                ->references('id')
                ->on('salesforce_opportunity_interest_direct_runs');
            $table->foreign('inverse_run_id', 'sf_opp_int_recon_inverse_run_fk')
                ->references('id')
                ->on('salesforce_interest_opportunity_reconciliation_runs');
        });

        Schema::create('salesforce_opportunity_interest_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('reconciliation_run_id');
            $table->string('opportunity_salesforce_id', 18);
            $table->string('direct_interest_salesforce_id', 18)->nullable();
            $table->json('inverse_interest_salesforce_ids')->nullable();
            $table->unsignedInteger('inverse_reference_count')->default(0);
            $table->string('relationship_status', 32);
            $table->string('direct_reference_status', 20)->nullable();
            $table->string('direct_interest_presence_status', 24);
            $table->boolean('direct_interest_is_deleted')->nullable();
            $table->boolean('direct_opportunity_is_deleted')->nullable();
            $table->boolean('inverse_opportunity_is_deleted')->nullable();
            $table->string('inverse_opportunity_presence_status', 32)->nullable();
            $table->boolean('requires_review')->default(false);
            $table->timestamps();

            $table->unique(
                ['reconciliation_run_id', 'opportunity_salesforce_id'],
                'sf_opp_int_recon_run_opp_uq',
            );
            $table->index(
                ['reconciliation_run_id', 'relationship_status'],
                'sf_opp_int_recon_run_relation_idx',
            );
            $table->index(
                ['reconciliation_run_id', 'direct_interest_salesforce_id'],
                'sf_opp_int_recon_run_interest_idx',
            );
            $table->foreign('reconciliation_run_id', 'sf_opp_int_recon_run_fk')
                ->references('id')
                ->on('salesforce_opportunity_interest_reconciliation_runs')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salesforce_opportunity_interest_reconciliations');
        Schema::dropIfExists('salesforce_opportunity_interest_reconciliation_runs');
    }
};
