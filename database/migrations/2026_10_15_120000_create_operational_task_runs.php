<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operational_alerts', fn (Blueprint $table) => $table->index(['technical_identifier', 'state'], 'ops_alerts_task_state_idx'));
        Schema::create('operational_task_states', function (Blueprint $table): void {
            $table->string('task_key', 100)->primary();
            $table->unsignedBigInteger('active_run_id')->nullable();
            $table->unsignedBigInteger('last_run_id')->nullable();
            $table->unsignedBigInteger('last_success_id')->nullable();
            $table->unsignedBigInteger('last_failure_id')->nullable();
            $table->timestamp('manual_available_at')->nullable();
            $table->timestamps();
        });
        Schema::create('operational_task_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('correlation_id')->unique();
            $table->string('task_key', 100);
            $table->string('origin', 20);
            // Keep the historical numeric actor even if the account is deleted.
            $table->unsignedBigInteger('report_user_id')->nullable()->index();
            $table->string('status', 30);
            $table->unsignedInteger('attempt')->default(1);
            $table->json('arguments');
            $table->timestamp('queued_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('exit_code')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->string('summary', 250)->nullable();
            $table->string('error', 250)->nullable();
            $table->timestamps();
            $table->index(['task_key', 'status', 'id'], 'ops_runs_task_status_idx');
            $table->index(['task_key', 'started_at'], 'ops_runs_task_started_idx');
            $table->index(['status', 'finished_at', 'id'], 'ops_runs_retention_idx');
        });
    }

    public function down(): void
    {
        Schema::table('operational_alerts', fn (Blueprint $table) => $table->dropIndex('ops_alerts_task_state_idx'));
        Schema::dropIfExists('operational_task_runs');
        Schema::dropIfExists('operational_task_states');
    }
};
