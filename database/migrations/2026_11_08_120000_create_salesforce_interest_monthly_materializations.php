<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salesforce_interest_monthly_materializations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('materialization_identifier')->unique('sf_imm_identifier_uq');
            $table->unsignedBigInteger('monthly_recovery_id');
            $table->string('status', 32);
            $table->string('phase', 48);
            $table->string('checkpoint_salesforce_id', 18)->nullable();
            $table->unsignedSmallInteger('batch_size');
            $table->unsignedInteger('pages_completed')->default(0);
            $table->uuid('lease_token')->nullable();
            $table->dateTime('heartbeat_at')->nullable();
            $table->dateTime('last_checkpoint_at')->nullable();
            $table->json('counters');
            $table->json('source_evidence');
            $table->json('evidence')->nullable();
            $table->char('reason_hash', 64);
            $table->string('error_code', 80)->nullable();
            $table->string('error_stage', 80)->nullable();
            $table->json('error_meta')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique('monthly_recovery_id', 'sf_imm_monthly_recovery_uq');
            $table->index(['status', 'heartbeat_at'], 'sf_imm_status_heartbeat_idx');
            $table->foreign('monthly_recovery_id', 'sf_imm_monthly_recovery_fk')
                ->references('id')->on('salesforce_interest_monthly_recoveries');
        });

        Schema::create('salesforce_interest_monthly_materialized_rows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('monthly_materialization_id');
            $table->unsignedBigInteger('monthly_recovery_row_id');
            $table->string('salesforce_id', 18);
            $table->char('source_content_hash', 64);
            $table->char('content_hash', 64);
            $table->boolean('content_matches_inventory');
            $table->boolean('is_deleted')->default(false);
            $table->dateTime('salesforce_deleted_at')->nullable();
            $table->string('deletion_detection_source', 32)->nullable();
            $table->string('lead_salesforce_id', 18)->nullable();
            $table->string('account_salesforce_id', 18)->nullable();
            $table->string('migration_origin_lead_id', 18)->nullable();
            $table->dateTime('salesforce_created_at');
            $table->dateTime('salesforce_last_modified_at');
            $table->dateTime('system_modstamp_at')->nullable();
            $table->dateTime('origin_created_at')->nullable();
            $table->dateTime('functional_created_at');
            $table->string('owner_salesforce_id', 18)->nullable();
            $table->string('owner_name')->nullable();
            $table->string('status')->nullable();
            $table->string('type')->nullable();
            $table->string('source')->nullable();
            $table->text('original_source')->nullable();
            $table->string('medium')->nullable();
            $table->string('channel')->nullable();
            $table->string('origin_delegation')->nullable();
            $table->string('utm_campaign', 70)->nullable();
            $table->string('utm_id', 70)->nullable();
            $table->string('utm_source', 70)->nullable();
            $table->string('utm_medium', 70)->nullable();
            $table->string('utm_content', 70)->nullable();
            $table->string('utm_term', 255)->nullable();
            $table->string('sale_vehicle_salesforce_id', 18)->nullable();
            $table->string('appraisal_vehicle_salesforce_id', 18)->nullable();
            $table->string('inverse_opportunity_salesforce_id', 18)->nullable();
            $table->string('canonical_person_type', 7)->nullable();
            $table->string('canonical_person_salesforce_id', 18)->nullable();
            $table->timestamps();

            $table->unique(['monthly_materialization_id', 'salesforce_id'], 'sf_immr_materialization_id_uq');
            $table->index(['monthly_materialization_id', 'functional_created_at'], 'sf_immr_period_date_idx');
            $table->index(['monthly_materialization_id', 'lead_salesforce_id'], 'sf_immr_lead_idx');
            $table->index(['monthly_materialization_id', 'account_salesforce_id'], 'sf_immr_account_idx');
            $table->index(['monthly_materialization_id', 'migration_origin_lead_id'], 'sf_immr_migration_lead_idx');
            $table->index(['monthly_materialization_id', 'canonical_person_salesforce_id'], 'sf_immr_person_idx');
            $table->foreign('monthly_materialization_id', 'sf_immr_materialization_fk')
                ->references('id')->on('salesforce_interest_monthly_materializations');
            $table->foreign('monthly_recovery_row_id', 'sf_immr_source_row_fk')
                ->references('id')->on('salesforce_interest_monthly_recovery_rows');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('salesforce_interest_monthly_materializations')
            && DB::table('salesforce_interest_monthly_materializations')->exists()) {
            throw new RuntimeException('Monthly materialization evidence must be retained during rollback.');
        }

        Schema::dropIfExists('salesforce_interest_monthly_materialized_rows');
        Schema::dropIfExists('salesforce_interest_monthly_materializations');
    }
};
