<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commercial_commission_appointment_evidences', function (Blueprint $table): void {
            $table->uuid('sync_token')->nullable()->index('cc_appt_evidence_sync_token_idx');
        });
        Schema::table('commercial_commission_product_delivery_evidences', function (Blueprint $table): void {
            $table->uuid('sync_token')->nullable()->index('cc_product_delivery_sync_token_idx');
        });
    }

    public function down(): void
    {
        Schema::table('commercial_commission_appointment_evidences', function (Blueprint $table): void {
            $table->dropIndex('cc_appt_evidence_sync_token_idx');
            $table->dropColumn('sync_token');
        });
        Schema::table('commercial_commission_product_delivery_evidences', function (Blueprint $table): void {
            $table->dropIndex('cc_product_delivery_sync_token_idx');
            $table->dropColumn('sync_token');
        });
    }
};
