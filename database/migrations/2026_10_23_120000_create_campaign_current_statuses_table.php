<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_current_statuses', function (Blueprint $table): void {
            $table->id();
            $table->string('platform', 16);
            $table->string('account_id', 30);
            $table->string('campaign_id', 30);
            $table->string('sync_source', 64)->index();
            $table->string('raw_status', 64)->nullable();
            $table->string('configured_status', 64)->nullable();
            $table->string('last_valid_raw_status', 64)->nullable();
            $table->string('last_valid_configured_status', 64)->nullable();
            $table->timestamp('last_observed_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->foreignId('report_sync_run_id')->nullable()->constrained('report_sync_runs')->nullOnDelete();
            $table->timestamps();
            $table->unique(['platform', 'account_id', 'campaign_id'], 'campaign_current_status_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_current_statuses');
    }
};
