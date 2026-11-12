<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_interest_monthly_recovery_rows', function (Blueprint $table): void {
            $table->unsignedTinyInteger('last_seen_pass')->default(0)->after('verified_at');
            $table->dateTime('hash_matched_at')->nullable()->after('last_seen_pass');
            $table->dateTime('first_mismatch_at')->nullable()->after('hash_matched_at');
            $table->char('initial_content_hash', 64)->nullable()->after('first_mismatch_at');
            $table->dateTime('initial_system_modstamp_at')->nullable()->after('initial_content_hash');
            $table->index(['monthly_recovery_id', 'last_seen_pass'], 'sf_imrr_seen_pass_idx');
            $table->index(['monthly_recovery_id', 'first_mismatch_at'], 'sf_imrr_mismatch_idx');
        });
    }

    public function down(): void
    {
        // Completed and incomplete monthly evidence must survive rollback.
    }
};
