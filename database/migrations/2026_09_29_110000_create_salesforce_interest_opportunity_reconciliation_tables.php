<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salesforce_interest_opportunity_reconciliation_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_identifier')->unique('sf_int_opp_recon_runs_identifier_uq');
            $table->string('reason', 500);
            $table->string('status', 20)->index('sf_int_opp_recon_runs_status_idx');
            $table->unsignedBigInteger('source_interest_sync_run_id');
            $table->dateTime('source_interest_cutoff_at');
            $table->unsignedBigInteger('source_opportunity_sync_run_id');
            $table->string('source_opportunity_sync_status', 20);
            $table->dateTime('source_opportunity_period_end_at');
            $table->dateTime('source_opportunity_completed_at');
            $table->unsignedBigInteger('source_opportunity_presence_run_id')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('completed_at')->nullable();
            $table->json('stats')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('salesforce_interest_opportunity_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reconciliation_run_id');
            $table->string('interest_salesforce_id', 18);
            $table->string('opportunity_salesforce_id', 18)->nullable();
            $table->string('relationship_status', 32);
            $table->string('opportunity_presence_status', 32);
            $table->boolean('interest_is_deleted');
            $table->boolean('opportunity_is_deleted')->nullable();
            $table->dateTime('opportunity_salesforce_deleted_at')->nullable();
            $table->string('opportunity_deletion_detection_source')->nullable();
            $table->unsignedInteger('inverse_reference_count')->default(0);
            $table->boolean('requires_review')->default(false);
            $table->timestamps();

            $table->unique(
                ['reconciliation_run_id', 'interest_salesforce_id'],
                'sf_int_opp_recon_run_interest_uq',
            );
            $table->index(
                ['reconciliation_run_id', 'relationship_status'],
                'sf_int_opp_recon_run_relation_idx',
            );
            $table->index(
                ['reconciliation_run_id', 'opportunity_salesforce_id'],
                'sf_int_opp_recon_run_opp_idx',
            );
            $table->foreign('reconciliation_run_id', 'sf_int_opp_recon_run_fk')
                ->references('id')
                ->on('salesforce_interest_opportunity_reconciliation_runs')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salesforce_interest_opportunity_reconciliations');
        Schema::dropIfExists('salesforce_interest_opportunity_reconciliation_runs');
    }
};
