<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_commission_approvers', function (Blueprint $table): void {
            $table->id();
            $table->string('closure_scope', 32)->unique();
            $table->foreignId('report_user_id')->nullable()->constrained('report_users')->nullOnDelete();
            $table->timestamp('configured_at')->nullable();
            $table->timestamps();
        });
        foreach (['commercials', 'delegations', 'area_manager', 'financials', 'call_center', 'contact_center'] as $scope) {
            DB::table('commercial_commission_approvers')->insert(['closure_scope' => $scope]);
        }
        Schema::create('commercial_commission_approver_events', function (Blueprint $table): void {
            $table->id();
            $table->string('closure_scope', 32)->index();
            $table->foreignId('report_user_id')->nullable()->constrained('report_users')->nullOnDelete();
            $table->json('context');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_commission_approver_events');
        Schema::dropIfExists('commercial_commission_approvers');
    }
};
