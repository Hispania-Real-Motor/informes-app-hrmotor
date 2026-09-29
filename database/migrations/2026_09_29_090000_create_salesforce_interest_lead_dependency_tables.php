<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salesforce_interest_lead_dependency_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_identifier')->unique('sf_int_lead_dep_runs_identifier_uq');
            $table->string('reason', 500);
            $table->string('status', 20)->index('sf_int_lead_dep_runs_status_idx');
            $table->unsignedBigInteger('source_interest_sync_run_id')->nullable();
            $table->dateTime('source_interest_cutoff_at')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('completed_at')->nullable();
            $table->json('stats')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('salesforce_interest_lead_dependencies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('dependency_run_id');
            $table->string('salesforce_id', 18);
            $table->string('presence_status', 20);
            $table->boolean('is_origin_reference')->default(false);
            $table->boolean('is_master_dependency')->default(false);
            $table->boolean('is_deleted')->nullable();
            $table->string('salesforce_master_record_id', 18)->nullable();
            $table->dateTime('salesforce_last_modified_at')->nullable();
            $table->dateTime('system_modstamp_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['dependency_run_id', 'salesforce_id'],
                'sf_int_lead_deps_run_salesforce_uq',
            );
            $table->index(
                ['dependency_run_id', 'presence_status', 'id'],
                'sf_int_lead_deps_pending_idx',
            );
            $table->foreign('dependency_run_id', 'sf_int_lead_deps_run_fk')
                ->references('id')
                ->on('salesforce_interest_lead_dependency_runs')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salesforce_interest_lead_dependencies');
        Schema::dropIfExists('salesforce_interest_lead_dependency_runs');
    }
};
