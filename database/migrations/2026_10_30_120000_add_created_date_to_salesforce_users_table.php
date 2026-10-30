<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_users', function (Blueprint $table): void {
            $table->dateTime('created_date')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_users', function (Blueprint $table): void {
            $table->dropColumn('created_date');
        });
    }
};
