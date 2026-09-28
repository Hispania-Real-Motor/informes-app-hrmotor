<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_interests', function (Blueprint $table): void {
            $table->boolean('is_deleted')->default(false)->after('synced_at');
            $table->dateTime('salesforce_deleted_at')->nullable()->after('is_deleted');
            $table->string('deletion_detection_source')->nullable()->after('salesforce_deleted_at');
        });

        Schema::create('salesforce_interest_sync_errors', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('report_sync_run_id');
            $table->string('salesforce_id', 18)->nullable()->index('sf_interest_errors_sf_id_idx');
            $table->string('phase', 32);
            $table->string('error_code', 120);
            $table->string('error_message', 1000);
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->index(
                ['report_sync_run_id', 'phase'],
                'sf_interest_errors_run_phase_idx',
            );
            $table->foreign('report_sync_run_id', 'sf_interest_errors_run_fk')
                ->references('id')
                ->on('report_sync_runs')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salesforce_interest_sync_errors');

        Schema::table('salesforce_interests', function (Blueprint $table): void {
            $table->dropColumn([
                'is_deleted',
                'salesforce_deleted_at',
                'deletion_detection_source',
            ]);
        });
    }
};
