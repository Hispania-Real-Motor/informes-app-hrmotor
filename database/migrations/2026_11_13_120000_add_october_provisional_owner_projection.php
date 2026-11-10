<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('salesforce_interest_october_provisional_projection_rows', 'owner_key')) {
            Schema::table('salesforce_interest_october_provisional_projection_rows', function (Blueprint $table): void {
                $table->string('owner_key', 64)->nullable();
                $table->string('commercial_delegation', 191)->nullable();
                $table->string('commercial_zone', 191)->nullable();
                $table->boolean('owner_is_technical')->default(false);
                $table->index(['projection_key', 'owner_key'], 'oct_prov_owner_key_idx');
                $table->index(['projection_key', 'commercial_delegation'], 'oct_prov_com_deleg_idx');
                $table->index(['projection_key', 'commercial_zone'], 'oct_prov_com_zone_idx');
            });
        }

        if (! Schema::hasTable('salesforce_interest_october_provisional_owners')) {
            Schema::create('salesforce_interest_october_provisional_owners', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->uuid('projection_key');
                $table->string('owner_key', 64);
                $table->text('name_ciphertext')->nullable();
                $table->timestamps();
                $table->unique(['projection_key', 'owner_key'], 'oct_prov_owner_label_uq');
            });
        }
    }

    public function down(): void
    {
        // Historical validation evidence must remain available for a safe rollback.
    }
};
