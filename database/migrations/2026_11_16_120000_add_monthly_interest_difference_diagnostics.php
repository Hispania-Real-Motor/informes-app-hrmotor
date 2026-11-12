<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_interest_monthly_recovery_rows', function (Blueprint $table): void {
            $table->json('diagnostic_fingerprints')->nullable()->after('initial_system_modstamp_at');
        });

        Schema::create('salesforce_interest_monthly_difference_diagnostics', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('monthly_recovery_id');
            $table->unsignedBigInteger('monthly_recovery_row_id');
            $table->unsignedTinyInteger('verification_pass');
            $table->unsignedSmallInteger('category_mask');
            $table->dateTime('observed_at');

            $table->unique(['monthly_recovery_row_id', 'verification_pass'], 'sf_imdd_row_pass_uq');
            $table->index(['monthly_recovery_id', 'verification_pass'], 'sf_imdd_recovery_pass_idx');
            $table->foreign('monthly_recovery_id', 'sf_imdd_recovery_fk')
                ->references('id')->on('salesforce_interest_monthly_recoveries');
            $table->foreign('monthly_recovery_row_id', 'sf_imdd_row_fk')
                ->references('id')->on('salesforce_interest_monthly_recovery_rows');
        });
    }

    public function down(): void
    {
        // Diagnostic evidence and completed monthly journals must survive rollback.
    }
};
