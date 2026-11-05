<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salesforce_interest_monthly_recoveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('recovery_identifier')->unique('sf_imr_identifier_uq');
            $table->dateTime('functional_start_at');
            $table->dateTime('functional_end_at');
            $table->string('timezone', 64);
            $table->string('status', 32);
            $table->string('phase', 48);
            $table->string('checkpoint_salesforce_id', 18)->nullable();
            $table->unsignedSmallInteger('batch_size');
            $table->unsignedInteger('pages_completed')->default(0);
            $table->uuid('lease_token')->nullable();
            $table->dateTime('heartbeat_at')->nullable();
            $table->dateTime('last_checkpoint_at')->nullable();
            $table->dateTime('extraction_started_at')->nullable();
            $table->json('counters');
            $table->json('evidence')->nullable();
            $table->char('reason_hash', 64);
            $table->string('error_code', 80)->nullable();
            $table->dateTime('started_at');
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['functional_start_at', 'functional_end_at'], 'sf_imr_period_uq');
            $table->index(['status', 'heartbeat_at'], 'sf_imr_status_heartbeat_idx');
        });

        Schema::create('salesforce_interest_monthly_recovery_rows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('monthly_recovery_id');
            $table->string('salesforce_id', 18);
            $table->char('content_hash', 64);
            $table->boolean('is_deleted')->default(false);
            $table->dateTime('functional_created_at');
            $table->dateTime('system_modstamp_at')->nullable();
            $table->json('payload');
            $table->timestamps();

            $table->unique(['monthly_recovery_id', 'salesforce_id'], 'sf_imrr_recovery_id_uq');
            $table->index(['monthly_recovery_id', 'functional_created_at'], 'sf_imrr_period_date_idx');
            $table->foreign('monthly_recovery_id', 'sf_imrr_recovery_fk')
                ->references('id')->on('salesforce_interest_monthly_recoveries');
        });
    }

    public function down(): void
    {
        if (DB::table('salesforce_interest_monthly_recoveries')->exists()) {
            throw new RuntimeException('Monthly recovery evidence must be retained during rollback.');
        }

        Schema::dropIfExists('salesforce_interest_monthly_recovery_rows');
        Schema::dropIfExists('salesforce_interest_monthly_recoveries');
    }
};
