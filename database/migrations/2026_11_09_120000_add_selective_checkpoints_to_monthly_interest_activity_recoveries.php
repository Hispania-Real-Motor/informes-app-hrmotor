<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_interest_monthly_activity_recoveries', function (Blueprint $table): void {
            $table->string('extraction_strategy', 40)->default('legacy_global_v1')->after('batch_size');
            $table->string('checkpoint_interest_id', 18)->nullable()->after('checkpoint_activity_id');
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_interest_monthly_activity_recoveries', function (Blueprint $table): void {
            $table->dropColumn(['extraction_strategy', 'checkpoint_interest_id']);
        });
    }
};
