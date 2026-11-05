<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_interest_monthly_recovery_rows', function (Blueprint $table): void {
            $table->dateTime('verified_at')->nullable()->after('system_modstamp_at');
            $table->index(['monthly_recovery_id', 'verified_at'], 'sf_imrr_verify_idx');
        });
    }

    public function down(): void
    {
        // Monthly recovery evidence is retained during application rollback.
    }
};
