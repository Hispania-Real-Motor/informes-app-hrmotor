<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_manager_commission_roster_members', function (Blueprint $table): void {
            $table->id();
            $table->string('month', 7);
            $table->string('delegation_key', 120);
            $table->string('delegation_name', 120);
            $table->string('salesforce_user_id', 18);
            $table->string('member_name')->nullable();
            $table->timestamp('coverage_from');
            $table->timestamp('coverage_to')->nullable();
            $table->string('source', 32);
            $table->string('evidence_reference', 255);
            $table->string('recorded_by', 255)->nullable();
            $table->boolean('history_verified')->default(false);
            $table->timestamps();
            $table->unique(
                ['month', 'delegation_key', 'salesforce_user_id', 'coverage_from'],
                'store_mgr_roster_member_period_uq',
            );
            $table->index(
                ['month', 'delegation_key', 'coverage_from', 'coverage_to'],
                'store_mgr_roster_month_delegation_idx',
            );
            $table->index(['salesforce_user_id', 'coverage_from'], 'store_mgr_roster_user_start_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_manager_commission_roster_members');
    }
};
