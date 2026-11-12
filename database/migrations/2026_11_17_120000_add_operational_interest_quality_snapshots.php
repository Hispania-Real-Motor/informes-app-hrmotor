<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('salesforce_interest_operational_quality')) {
            Schema::create('salesforce_interest_operational_quality', function (Blueprint $table): void {
                $table->unsignedBigInteger('run_id')->primary();
                $table->uuid('projection_key');
                $table->json('delegation_quality');
                $table->timestamps();
                $table->foreign('run_id', 'sf_iop_quality_run_fk')->references('id')->on('salesforce_interest_operational_runs');
            });
        }
    }

    public function down(): void
    {
        // Keep administrative evidence available after code rollback.
    }
};
