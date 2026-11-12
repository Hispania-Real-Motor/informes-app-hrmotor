<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('salesforce_interest_october_provisional_projection_rows', 'interest_key')) {
            Schema::table('salesforce_interest_october_provisional_projection_rows', function (Blueprint $table): void {
                $table->char('interest_key', 64)->nullable();
                $table->unique(['projection_key', 'interest_key'], 'sf_iop_projection_interest_uq');
            });
        }
        if (! Schema::hasColumn('salesforce_interest_operational_months', 'active_activity_run_id')) {
            Schema::table('salesforce_interest_operational_months', function (Blueprint $table): void {
                $table->unsignedBigInteger('active_activity_run_id')->nullable();
            });
        }
        if (! Schema::hasTable('salesforce_interest_operational_activity_runs')) {
            Schema::create('salesforce_interest_operational_activity_runs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('source_run_id');
                $table->string('month_key', 7);
                $table->string('status', 24);
                $table->string('phase', 24);
                $table->string('checkpoint_interest_id', 18)->nullable();
                $table->string('checkpoint_activity_id', 18)->nullable();
                $table->string('project_cursor_interest_id', 18)->nullable();
                $table->unsignedInteger('query_attempts')->default(0);
                $table->unsignedInteger('pages_completed')->default(0);
                $table->unsignedInteger('extracted_count')->default(0);
                $table->unsignedInteger('verified_count')->default(0);
                $table->unsignedInteger('changed_count')->default(0);
                $table->uuid('lease_token')->nullable();
                $table->dateTime('heartbeat_at')->nullable();
                $table->string('error_stage', 32)->nullable();
                $table->dateTime('started_at')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->timestamps();
                $table->index(['month_key', 'status'], 'sf_iop_act_month_status_idx');
                $table->foreign('source_run_id', 'sf_iop_act_source_fk')->references('id')->on('salesforce_interest_operational_runs');
            });
        }
        if (! Schema::hasTable('salesforce_interest_operational_activity_rows')) {
            Schema::create('salesforce_interest_operational_activity_rows', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('run_id');
                $table->string('activity_kind', 5);
                $table->string('activity_salesforce_id', 18);
                $table->string('interest_salesforce_id', 18);
                $table->boolean('is_deleted');
                $table->dateTime('functional_activity_at')->nullable();
                $table->char('content_hash', 64);
                $table->dateTime('verified_at')->nullable();
                $table->timestamps();
                $table->unique(['run_id', 'activity_kind', 'activity_salesforce_id'], 'sf_iop_act_identity_uq');
                $table->index(['run_id', 'interest_salesforce_id'], 'sf_iop_act_interest_idx');
                $table->index(['run_id', 'verified_at'], 'sf_iop_act_verified_idx');
                $table->foreign('run_id', 'sf_iop_act_rows_run_fk')->references('id')->on('salesforce_interest_operational_activity_runs');
            });
        }
        if (! Schema::hasTable('salesforce_interest_operational_activity_summaries')) {
            Schema::create('salesforce_interest_operational_activity_summaries', function (Blueprint $table): void {
                $table->unsignedBigInteger('run_id');
                $table->char('interest_key', 64);
                $table->unsignedInteger('activities');
                $table->unsignedInteger('undated');
                $table->dateTime('first_activity_at')->nullable();
                $table->dateTime('last_activity_at')->nullable();
                $table->primary(['run_id', 'interest_key'], 'sf_iop_act_summary_pk');
                $table->foreign('run_id', 'sf_iop_act_summary_run_fk')->references('id')->on('salesforce_interest_operational_activity_runs');
            });
        }
    }

    public function down(): void
    {
        // Completed operational evidence and its pointers remain for a safe code rollback.
    }
};
