<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('salesforce_interest_operational_kpi_projections')) {
            Schema::create('salesforce_interest_operational_kpi_projections', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('source_run_id');
                $table->unsignedBigInteger('activity_run_id');
                $table->uuid('source_projection_key');
                $table->string('status', 16);
                $table->uuid('lease_token')->nullable();
                $table->dateTime('heartbeat_at')->nullable();
                $table->unsignedBigInteger('project_cursor')->default(0);
                $table->unsignedInteger('rows_count')->default(0);
                $table->boolean('activity_coverage_complete');
                $table->dateTime('reference_at');
                $table->dateTime('commercial_users_observed_at');
                $table->char('commercial_users_fingerprint', 64)->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->timestamps();
                $table->unique(['source_run_id', 'activity_run_id'], 'sf_iop_kpi_source_activity_uq');
                $table->foreign('source_run_id', 'sf_iop_kpi_source_fk')->references('id')->on('salesforce_interest_operational_runs');
                $table->foreign('activity_run_id', 'sf_iop_kpi_activity_fk')->references('id')->on('salesforce_interest_operational_activity_runs');
            });
        }

        if (! Schema::hasTable('salesforce_interest_operational_kpi_rows')) {
            Schema::create('salesforce_interest_operational_kpi_rows', function (Blueprint $table): void {
                $table->unsignedBigInteger('projection_id');
                $table->char('interest_key', 64);
                $table->boolean('managed')->nullable();
                $table->boolean('potential_unworked')->nullable();
                $table->boolean('without_eligible_commercial');
                $table->boolean('without_commercial_delegation');
                $table->primary(['projection_id', 'interest_key'], 'sf_iop_kpi_rows_pk');
                $table->foreign('projection_id', 'sf_iop_kpi_rows_projection_fk')->references('id')->on('salesforce_interest_operational_kpi_projections');
            });
        }
    }

    public function down(): void
    {
        // Completed projections are evidence linked to immutable operational runs.
    }
};
