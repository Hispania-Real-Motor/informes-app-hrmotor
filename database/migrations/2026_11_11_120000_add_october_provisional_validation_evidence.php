<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SCOPE = 'october_2026_provisional';

    public function up(): void
    {
        if (! Schema::hasTable('salesforce_interest_monthly_integrity_revisions')) {
            Schema::create('salesforce_interest_monthly_integrity_revisions', function (Blueprint $table): void {
                $table->string('scope', 64)->primary();
                $table->unsignedBigInteger('revision')->default(0);
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('salesforce_interest_october_provisional_validations')) {
            Schema::create('salesforce_interest_october_provisional_validations', function (Blueprint $table): void {
                $table->string('scope', 64)->primary();
                $table->unsignedBigInteger('monthly_recovery_id');
                $table->unsignedBigInteger('monthly_materialization_id');
                $table->unsignedBigInteger('monthly_activity_recovery_id');
                $table->unsignedBigInteger('integrity_revision');
                $table->json('evidence');
                $table->json('overview');
                $table->json('metrics');
                $table->dateTime('validated_at');
                $table->timestamps();
            });
        }
        DB::table('salesforce_interest_monthly_integrity_revisions')->insertOrIgnore([
            'scope' => self::SCOPE, 'revision' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Operational evidence deliberately survives rollback so a rollback never permits stale reads.
    }
};
