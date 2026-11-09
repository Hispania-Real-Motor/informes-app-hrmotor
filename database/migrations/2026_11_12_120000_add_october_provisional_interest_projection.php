<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('salesforce_interest_october_provisional_validations', 'projection_key')) {
            Schema::table('salesforce_interest_october_provisional_validations', function (Blueprint $table): void {
                $table->uuid('projection_key')->nullable()->after('integrity_revision');
            });
        }

        if (! Schema::hasTable('salesforce_interest_october_provisional_projection_rows')) {
            Schema::create('salesforce_interest_october_provisional_projection_rows', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('scope', 64);
                $table->unsignedBigInteger('integrity_revision');
                $table->uuid('projection_key');
                $table->string('interest_status', 191);
                $table->string('interest_type', 191);
                $table->string('source', 191);
                $table->string('medium', 191);
                $table->string('channel', 191);
                $table->string('origin_delegation', 191);
                $table->string('origin_group', 191);
                $table->string('origin_zone', 191);
                $table->timestamps();

                $table->index(['scope', 'integrity_revision', 'projection_key'], 'oct_prov_proj_snapshot_idx');
                $table->index(['projection_key', 'interest_status'], 'oct_prov_proj_status_idx');
                $table->index(['projection_key', 'interest_type'], 'oct_prov_proj_type_idx');
                $table->index(['projection_key', 'origin_delegation'], 'oct_prov_proj_delegation_idx');
                $table->index(['projection_key', 'origin_group'], 'oct_prov_proj_group_idx');
                $table->index(['projection_key', 'origin_zone'], 'oct_prov_proj_zone_idx');
                $table->index(['projection_key', 'source'], 'oct_prov_proj_source_idx');
                $table->index(['projection_key', 'medium'], 'oct_prov_proj_medium_idx');
                $table->index(['projection_key', 'channel'], 'oct_prov_proj_channel_idx');
            });
        }
    }

    public function down(): void
    {
        // A rollback must not remove evidence that may be required to fail closed.
    }
};
