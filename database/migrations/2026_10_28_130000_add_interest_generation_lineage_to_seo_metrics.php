<?php

use IlluminateDatabaseMigrationsMigration;
use IlluminateDatabaseSchemaBlueprint;
use IlluminateSupportFacadesSchema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_salesforce_organic_interest_daily_metrics', function (Blueprint $table): void {
            $table->unsignedBigInteger('interest_generation_id')->nullable()->after('source_interest_sync_run_id');
            $table->index(['interest_generation_id', 'data_date'], 'seo_sf_organic_interest_generation_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('seo_salesforce_organic_interest_daily_metrics', function (Blueprint $table): void {
            $table->dropIndex('seo_sf_organic_interest_generation_date_idx');
            $table->dropColumn('interest_generation_id');
        });
    }
};
