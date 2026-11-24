<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('store_manager_commission_roster_members')
            || Schema::hasColumn('store_manager_commission_roster_members', 'imported_by')) {
            return;
        }

        Schema::table('store_manager_commission_roster_members', function (Blueprint $table): void {
            $table->string('imported_by', 255)->nullable()->after('recorded_by');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('store_manager_commission_roster_members')
            && Schema::hasColumn('store_manager_commission_roster_members', 'imported_by')) {
            Schema::table('store_manager_commission_roster_members', function (Blueprint $table): void {
                $table->dropColumn('imported_by');
            });
        }
    }
};
