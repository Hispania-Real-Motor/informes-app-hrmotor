<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_salesforce_organic_interest_daily_metrics', function (Blueprint $table): void {
            $table->id();
            $table->date('data_date')->unique('seo_sf_organic_interest_date_uq');
            $table->unsignedBigInteger('interest_count')->default(0);
            $table->string('source_timezone', 64);
            $table->unsignedBigInteger('source_interest_sync_run_id');
            $table->dateTime('source_interest_cutoff_at');
            $table->dateTime('extracted_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_salesforce_organic_interest_daily_metrics');
    }
};
