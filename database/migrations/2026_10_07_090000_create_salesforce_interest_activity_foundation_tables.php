<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salesforce_interest_activity_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_identifier')->unique('sf_int_activity_runs_identifier_uq');
            $table->string('reason', 500);
            $table->string('status', 20)->index('sf_int_activity_runs_status_idx');
            $table->unsignedBigInteger('source_interest_sync_run_id');
            $table->dateTime('source_interest_cutoff_at');
            $table->dateTime('source_cutoff_at');
            $table->dateTime('started_at');
            $table->dateTime('completed_at')->nullable();
            $table->json('stats')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('salesforce_interest_activities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('activity_run_id');
            $table->string('activity_kind', 10);
            $table->string('activity_salesforce_id', 18);
            $table->string('interest_salesforce_id', 18)->nullable();
            $table->string('who_salesforce_id', 18)->nullable();
            $table->string('relationship_status', 40);
            $table->boolean('activity_is_deleted');
            $table->boolean('interest_is_deleted')->nullable();
            $table->date('activity_date')->nullable();
            $table->dateTime('start_datetime')->nullable();
            $table->dateTime('salesforce_created_at')->nullable();
            $table->dateTime('salesforce_last_modified_at')->nullable();
            $table->dateTime('system_modstamp_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['activity_run_id', 'activity_kind', 'activity_salesforce_id'],
                'sf_int_activities_run_kind_id_uq',
            );
            $table->index(
                ['activity_run_id', 'interest_salesforce_id'],
                'sf_int_activities_run_interest_idx',
            );
            $table->index(
                ['activity_run_id', 'relationship_status'],
                'sf_int_activities_run_relation_idx',
            );
            $table->foreign('activity_run_id', 'sf_int_activities_run_fk')
                ->references('id')
                ->on('salesforce_interest_activity_runs')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salesforce_interest_activities');
        Schema::dropIfExists('salesforce_interest_activity_runs');
    }
};
