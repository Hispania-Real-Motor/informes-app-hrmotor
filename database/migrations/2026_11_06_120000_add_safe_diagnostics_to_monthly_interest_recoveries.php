<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_interest_monthly_recoveries', function (Blueprint $table): void {
            $table->string('error_stage', 48)->nullable()->after('error_code');
            $table->json('error_meta')->nullable()->after('error_stage');
        });
    }

    public function down(): void
    {
        // Operational diagnostic evidence is retained during application rollback.
    }
};
