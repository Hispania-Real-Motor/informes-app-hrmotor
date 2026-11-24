<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_opportunities', function (Blueprint $table): void {
            $table->string('purchase_appraisal_id')->nullable()->index();
            $table->string('purchase_delegation_id')->nullable()->index();
            $table->string('purchase_delegation_name')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_opportunities', function (Blueprint $table): void {
            $table->dropIndex(['purchase_appraisal_id']);
            $table->dropIndex(['purchase_delegation_id']);
            $table->dropIndex(['purchase_delegation_name']);
            $table->dropColumn(['purchase_appraisal_id', 'purchase_delegation_id', 'purchase_delegation_name']);
        });
    }
};
