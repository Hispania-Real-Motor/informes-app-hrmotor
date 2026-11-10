<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salesforce_interest_monthly_consolidations', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 24);
            $table->char('reason_hash', 64);
            $table->uuid('lease_token')->nullable();
            $table->dateTime('heartbeat_at')->nullable();
            $table->unsignedBigInteger('pages_completed')->default(0);
            $table->unsignedBigInteger('rows_staged')->default(0);
            $table->json('manifest')->nullable();
            $table->string('error_code', 48)->nullable();
            $table->timestamps();
            $table->index(['status', 'heartbeat_at'], 'sf_imc_status_heartbeat_idx');
        });

        Schema::create('salesforce_interest_monthly_consolidation_sources', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('consolidation_id');
            $table->unsignedBigInteger('monthly_recovery_id');
            $table->unsignedBigInteger('monthly_materialization_id');
            $table->unsignedBigInteger('monthly_activity_recovery_id');
            $table->dateTime('functional_start_at');
            $table->dateTime('functional_end_at');
            $table->dateTime('effective_cutoff_at')->nullable();
            $table->unsignedBigInteger('integrity_revision');
            $table->char('f2m_sha256', 64);
            $table->char('f5_sha256', 64);
            $table->unsignedBigInteger('f2m_rows');
            $table->unsignedBigInteger('checkpoint_row_id')->default(0);
            $table->string('status', 24)->default('pending');
            $table->timestamps();
            $table->unique(['consolidation_id', 'monthly_recovery_id'], 'sf_imcs_recovery_uq');
            $table->unique(['consolidation_id', 'monthly_materialization_id'], 'sf_imcs_materialization_uq');
            $table->index(['consolidation_id', 'functional_start_at'], 'sf_imcs_period_idx');
            $table->foreign('consolidation_id', 'sf_imcs_consolidation_fk')->references('id')->on('salesforce_interest_monthly_consolidations');
            $table->foreign('monthly_recovery_id', 'sf_imcs_recovery_fk')->references('id')->on('salesforce_interest_monthly_recoveries');
            $table->foreign('monthly_materialization_id', 'sf_imcs_materialization_fk')->references('id')->on('salesforce_interest_monthly_materializations');
            $table->foreign('monthly_activity_recovery_id', 'sf_imcs_activity_fk')->references('id')->on('salesforce_interest_monthly_activity_recoveries');
        });

        Schema::create('salesforce_interest_monthly_consolidation_members', function (Blueprint $table): void {
            $table->unsignedBigInteger('consolidation_id');
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('materialized_row_id');
            $table->string('salesforce_id', 18);
            $table->char('content_hash', 64);
            $table->boolean('is_deleted');
            $table->string('migration_origin_lead_id', 18)->nullable();
            $table->primary(['consolidation_id', 'salesforce_id'], 'sf_imcm_pk');
            $table->unique(['consolidation_id', 'migration_origin_lead_id'], 'sf_imcm_origin_uq');
            $table->index(['source_id', 'materialized_row_id'], 'sf_imcm_source_row_idx');
            $table->foreign('consolidation_id', 'sf_imcm_consolidation_fk')->references('id')->on('salesforce_interest_monthly_consolidations');
            $table->foreign('source_id', 'sf_imcm_source_fk')->references('id')->on('salesforce_interest_monthly_consolidation_sources');
            $table->foreign('materialized_row_id', 'sf_imcm_row_fk')->references('id')->on('salesforce_interest_monthly_materialized_rows');
        });
    }

    public function down(): void
    {
        if (DB::table('salesforce_interest_monthly_consolidations')->exists()) {
            throw new RuntimeException('Monthly consolidation evidence must be retained during rollback.');
        }
        Schema::dropIfExists('salesforce_interest_monthly_consolidation_members');
        Schema::dropIfExists('salesforce_interest_monthly_consolidation_sources');
        Schema::dropIfExists('salesforce_interest_monthly_consolidations');
    }
};
