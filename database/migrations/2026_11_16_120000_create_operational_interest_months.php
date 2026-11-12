<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('salesforce_interest_operational_months')) {
            Schema::create('salesforce_interest_operational_months', function (Blueprint $table): void {
                $table->string('month_key', 7)->primary();
                $table->unsignedBigInteger('active_run_id')->nullable();
                $table->uuid('active_projection_key')->nullable();
                $table->uuid('lease_token')->nullable();
                $table->dateTime('heartbeat_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('salesforce_interest_operational_runs')) {
            Schema::create('salesforce_interest_operational_runs', function (Blueprint $table): void {
                $table->id();
                $table->uuid('run_identifier')->unique('sf_iop_run_identifier_uq');
                $table->string('month_key', 7);
                $table->dateTime('functional_start_at');
                $table->dateTime('functional_end_at');
                $table->dateTime('effective_cutoff_at');
                $table->string('status', 24);
                $table->string('phase', 24);
                $table->string('extract_cursor', 18)->nullable();
                $table->string('verify_cursor', 18)->nullable();
                $table->unsignedBigInteger('project_cursor')->nullable();
                $table->unsignedInteger('extracted_count')->default(0);
                $table->unsignedInteger('verified_count')->default(0);
                $table->unsignedInteger('changed_count')->default(0);
                $table->unsignedInteger('quarantined_count')->default(0);
                $table->unsignedInteger('pages_completed')->default(0);
                $table->unsignedInteger('query_attempts')->default(0);
                $table->uuid('projection_key')->nullable();
                $table->json('overview')->nullable();
                $table->string('error_stage', 32)->nullable();
                $table->dateTime('started_at')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->timestamps();
                $table->index(['month_key', 'status'], 'sf_iop_runs_month_status_idx');
            });
        }

        if (! Schema::hasTable('salesforce_interest_operational_rows')) {
            Schema::create('salesforce_interest_operational_rows', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('run_id');
                $table->string('salesforce_id', 18);
                $table->char('content_hash', 64);
                $table->json('field_fingerprints');
                $table->dateTime('functional_created_at');
                $table->dateTime('observed_at');
                $table->dateTime('verified_at')->nullable();
                $table->boolean('is_deleted');
                $table->string('status', 191)->nullable();
                $table->string('type', 191)->nullable();
                $table->string('source', 191)->nullable();
                $table->string('medium', 191)->nullable();
                $table->string('channel', 191)->nullable();
                $table->string('origin_delegation', 191)->nullable();
                $table->string('owner_salesforce_id', 18)->nullable();
                $table->text('owner_name_ciphertext')->nullable();
                $table->string('lead_salesforce_id', 18)->nullable();
                $table->string('account_salesforce_id', 18)->nullable();
                $table->string('sale_vehicle_salesforce_id', 18)->nullable();
                $table->string('appraisal_vehicle_salesforce_id', 18)->nullable();
                $table->string('inverse_opportunity_salesforce_id', 18)->nullable();
                $table->timestamps();
                $table->unique(['run_id', 'salesforce_id'], 'sf_iop_rows_run_id_uq');
                $table->index(['run_id', 'verified_at'], 'sf_iop_rows_verified_idx');
                $table->foreign('run_id', 'sf_iop_rows_run_fk')->references('id')->on('salesforce_interest_operational_runs');
            });
        }

        if (! Schema::hasTable('salesforce_interest_operational_incidents')) {
            Schema::create('salesforce_interest_operational_incidents', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('run_id');
                $table->string('salesforce_id', 18);
                $table->string('category', 40);
                $table->unsignedSmallInteger('change_mask')->nullable();
                $table->dateTime('observed_at');
                $table->dateTime('verified_at')->nullable();
                $table->timestamps();
                $table->unique(['run_id', 'salesforce_id', 'category'], 'sf_iop_incident_identity_uq');
                $table->index(['salesforce_id', 'category'], 'sf_iop_incident_history_idx');
                $table->foreign('run_id', 'sf_iop_incident_run_fk')->references('id')->on('salesforce_interest_operational_runs');
            });
        }
    }

    public function down(): void
    {
        // Operational observations and their active pointer must survive rollback.
    }
};
