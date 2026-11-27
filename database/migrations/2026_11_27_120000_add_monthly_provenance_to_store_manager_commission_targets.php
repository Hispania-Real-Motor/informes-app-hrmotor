<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_manager_commission_targets', function (Blueprint $table): void {
            $table->string('source_type', 32)->default('imported')->after('source_label');
            $table->string('source_month', 7)->nullable()->after('source_type');
            $table->foreignId('modified_by')->nullable()->after('imported_by')->constrained('report_users')->nullOnDelete();
            $table->index(['month', 'source_type']);
        });

        DB::table('store_manager_commission_targets')
            ->whereNull('source_month')
            ->update(['source_month' => DB::raw('month')]);

        Schema::create('store_manager_commission_target_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('target_id')->nullable()->constrained('store_manager_commission_targets')->nullOnDelete();
            $table->string('month', 7);
            $table->string('store_key', 120);
            $table->uuid('operation_id');
            $table->string('event_type', 32);
            $table->string('source_month', 7)->nullable();
            $table->foreignId('report_user_id')->nullable()->constrained('report_users')->nullOnDelete();
            $table->unsignedInteger('before_delivery_target')->nullable();
            $table->unsignedInteger('before_purchase_target')->nullable();
            $table->unsignedInteger('after_delivery_target');
            $table->unsignedInteger('after_purchase_target');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['month', 'store_key']);
            $table->unique(['operation_id', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_manager_commission_target_events');

        Schema::table('store_manager_commission_targets', function (Blueprint $table): void {
            $table->dropForeign(['modified_by']);
            $table->dropIndex(['month', 'source_type']);
            $table->dropColumn(['source_type', 'source_month', 'modified_by']);
        });
    }
};
