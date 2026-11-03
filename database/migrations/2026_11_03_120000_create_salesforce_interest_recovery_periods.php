<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salesforce_interest_recovery_periods', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('source_generation_id');
            $table->dateTime('functional_start_at');
            $table->dateTime('functional_end_at');
            $table->string('timezone', 64);
            $table->string('status', 32)->index();
            $table->string('phase', 48)->index();
            $table->string('checkpoint_salesforce_id', 18)->nullable();
            $table->uuid('lease_token')->nullable();
            $table->dateTime('heartbeat_at')->nullable();
            $table->unsignedInteger('pages_completed')->default(0);
            $table->unsignedBigInteger('rows_expected')->nullable();
            $table->json('counters');
            $table->json('diagnostics');
            $table->char('reason_hash', 64);
            $table->dateTime('started_at');
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->timestamps();
            $table->unique(['source_generation_id', 'functional_start_at', 'functional_end_at'], 'sf_irp_source_window_uq');
            $table->index(['source_generation_id', 'status'], 'sf_irp_source_status_idx');
            $table->foreign('source_generation_id')->references('id')->on('salesforce_interest_generations');
        });

        Schema::create('salesforce_interest_recovery_period_seen', function (Blueprint $table): void {
            $table->unsignedBigInteger('recovery_period_id');
            $table->string('salesforce_id', 18);
            $table->char('content_hash', 64);
            $table->primary(['recovery_period_id', 'salesforce_id'], 'sf_irps_pk');
            $table->foreign('recovery_period_id', 'sf_irps_period_fk')
                ->references('id')->on('salesforce_interest_recovery_periods');
        });

        Schema::create('salesforce_interest_recovery_period_differences', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('recovery_period_id');
            $table->string('salesforce_id', 18);
            $table->string('kind', 32);
            $table->char('observed_hash', 64)->nullable();
            $table->char('source_hash', 64)->nullable();
            $table->dateTime('observed_at');
            $table->unique(['recovery_period_id', 'salesforce_id'], 'sf_irpd_period_interest_uq');
            $table->index(['recovery_period_id', 'kind'], 'sf_irpd_period_kind_idx');
            $table->foreign('recovery_period_id', 'sf_irpd_period_fk')
                ->references('id')->on('salesforce_interest_recovery_periods');
        });
    }

    public function down(): void
    {
        if (DB::table('salesforce_interest_recovery_periods')->exists()) {
            throw new \RuntimeException('Recovery evidence must be retained during application rollback.');
        }

        Schema::dropIfExists('salesforce_interest_recovery_period_differences');
        Schema::dropIfExists('salesforce_interest_recovery_period_seen');
        Schema::dropIfExists('salesforce_interest_recovery_periods');
    }
};
