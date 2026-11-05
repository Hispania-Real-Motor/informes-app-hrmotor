<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salesforce_interest_monthly_activity_recoveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('recovery_identifier')->unique('sf_imar_identifier_uq');
            $table->unsignedBigInteger('monthly_recovery_id');
            $table->string('status', 32);
            $table->string('phase', 48);
            $table->unsignedSmallInteger('batch_size');
            $table->string('checkpoint_activity_id', 18)->nullable();
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

            $table->unique('monthly_recovery_id', 'sf_imar_monthly_recovery_uq');
            $table->index(['status', 'heartbeat_at'], 'sf_imar_status_heartbeat_idx');
            $table->foreign('monthly_recovery_id', 'sf_imar_monthly_recovery_fk')
                ->references('id')->on('salesforce_interest_monthly_recoveries');
        });

        Schema::create('salesforce_interest_monthly_activity_rows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('monthly_activity_recovery_id');
            $table->string('activity_kind', 8);
            $table->string('activity_salesforce_id', 18);
            $table->string('interest_salesforce_id', 18);
            $table->string('who_salesforce_id', 18)->nullable();
            $table->boolean('activity_is_deleted')->default(false);
            $table->boolean('interest_is_deleted')->default(false);
            $table->date('activity_date')->nullable();
            $table->dateTime('start_datetime')->nullable();
            $table->dateTime('salesforce_created_at')->nullable();
            $table->dateTime('salesforce_last_modified_at')->nullable();
            $table->dateTime('system_modstamp_at')->nullable();
            $table->char('content_hash', 64);
            $table->dateTime('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['monthly_activity_recovery_id', 'activity_kind', 'activity_salesforce_id'], 'sf_imarr_activity_uq');
            $table->index(['monthly_activity_recovery_id', 'interest_salesforce_id'], 'sf_imarr_recovery_interest_idx');
            $table->foreign('monthly_activity_recovery_id', 'sf_imarr_recovery_fk')
                ->references('id')->on('salesforce_interest_monthly_activity_recoveries');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salesforce_interest_monthly_activity_rows');
        Schema::dropIfExists('salesforce_interest_monthly_activity_recoveries');
    }
};
