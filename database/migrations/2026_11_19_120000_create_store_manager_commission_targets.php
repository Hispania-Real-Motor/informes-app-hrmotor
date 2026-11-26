<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_manager_commission_targets', function (Blueprint $table): void {
            $table->id();
            $table->string('month', 7);
            $table->string('zone', 80);
            $table->string('store_key', 120);
            $table->string('store_name', 120);
            $table->unsignedInteger('delivery_target')->nullable();
            $table->unsignedInteger('purchase_target')->nullable();
            $table->string('source_sha256', 64);
            $table->string('source_label', 255);
            $table->foreignId('imported_by')->nullable()->constrained('report_users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['month', 'store_key']);
            $table->index(['month', 'zone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_manager_commission_targets');
    }
};
