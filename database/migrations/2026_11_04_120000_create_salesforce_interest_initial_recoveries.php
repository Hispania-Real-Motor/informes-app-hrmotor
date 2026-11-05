<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salesforce_interest_initial_recoveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('recovery_identifier')->unique('sf_iir_recovery_identifier_uq');
            $table->unsignedBigInteger('source_generation_id');
            $table->unsignedBigInteger('candidate_generation_id')->unique('sf_iir_candidate_generation_uq');
            $table->unsignedBigInteger('interest_run_id')->unique('sf_iir_interest_run_uq');
            $table->string('status', 32)->index('sf_iir_status_idx');
            $table->string('phase', 48)->index('sf_iir_phase_idx');
            $table->string('checkpoint_salesforce_id', 18)->nullable();
            $table->unsignedSmallInteger('reconciliation_pass')->default(1);
            $table->unsignedSmallInteger('max_reconciliation_passes');
            $table->unsignedSmallInteger('batch_size');
            $table->uuid('lease_token')->nullable();
            $table->dateTime('heartbeat_at')->nullable();
            $table->dateTime('last_checkpoint_at')->nullable();
            $table->dateTime('source_cutoff_at');
            $table->dateTime('watermark_at');
            $table->json('deletion_coverage')->nullable();
            $table->json('counters');
            $table->char('reason_hash', 64);
            $table->char('configuration_hash', 64);
            $table->string('error_code', 80)->nullable();
            $table->dateTime('started_at');
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'heartbeat_at'], 'sf_iir_status_heartbeat_idx');
            $table->index(['source_generation_id', 'status'], 'sf_iir_source_status_idx');
            $table->foreign('source_generation_id', 'sf_iir_source_fk')
                ->references('id')->on('salesforce_interest_generations');
            $table->foreign('candidate_generation_id', 'sf_iir_candidate_fk')
                ->references('id')->on('salesforce_interest_generations');
        });
    }

    public function down(): void
    {
        // Recovery checkpoints are operational evidence. Rollback never discards them.
        if (DB::table('salesforce_interest_initial_recoveries')->exists()) {
            throw new RuntimeException('Initial recovery evidence must be retained during application rollback.');
        }

        Schema::dropIfExists('salesforce_interest_initial_recoveries');
    }
};
