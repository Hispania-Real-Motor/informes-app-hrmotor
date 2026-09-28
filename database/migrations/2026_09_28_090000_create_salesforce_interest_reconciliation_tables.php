<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salesforce_interest_reconciliation_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_identifier')->unique('sf_interest_recon_runs_identifier_uq');
            $table->string('reason', 500);
            $table->string('status', 20)->index('sf_interest_recon_runs_status_idx');
            $table->dateTime('started_at');
            $table->dateTime('completed_at')->nullable();
            $table->json('stats')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('salesforce_interest_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('reconciliation_run_id');
            $table->string('subject_type', 10);
            $table->string('subject_salesforce_id', 18);
            $table->string('lead_salesforce_id', 18)->nullable();
            $table->string('interest_salesforce_id', 18)->nullable();
            $table->string('migration_origin_lead_id', 18)->nullable();
            $table->string('immediate_master_lead_id', 18)->nullable();
            $table->string('resolved_master_lead_id', 18)->nullable();
            $table->string('relationship_status', 48);
            $table->string('master_status', 32);
            $table->string('canonical_person_status', 32);
            $table->string('current_lead_alignment', 32);
            $table->boolean('lead_is_deleted')->nullable();
            $table->boolean('interest_is_deleted')->nullable();
            $table->boolean('has_conflict')->default(false);
            $table->timestamps();

            $table->unique(
                ['reconciliation_run_id', 'subject_type', 'subject_salesforce_id'],
                'sf_interest_recon_subject_uq',
            );
            $table->index(
                ['reconciliation_run_id', 'relationship_status'],
                'sf_interest_recon_run_relation_idx',
            );
            $table->foreign('reconciliation_run_id', 'sf_interest_recon_run_fk')
                ->references('id')
                ->on('salesforce_interest_reconciliation_runs')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salesforce_interest_reconciliations');
        Schema::dropIfExists('salesforce_interest_reconciliation_runs');
    }
};
