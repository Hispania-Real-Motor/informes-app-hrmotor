<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_attributions', function (Blueprint $table): void {
            $table->dropUnique('campaign_attributions_opportunity_id_unique');
            $table->index('opportunity_id', 'campaign_attributions_opportunity_id_index');
        });

        Schema::table('campaign_attributions', function (Blueprint $table): void {
            $table->string('lead_id')->nullable()->change();
            $table->string('interest_id', 18)->nullable()->unique('campaign_attr_interest_uq');
            $table->dateTime('interest_functional_created_at')->nullable()->index('campaign_attr_interest_date_idx');
            $table->string('interest_status')->nullable();
            $table->string('interest_type')->nullable();
            $table->string('interest_source')->nullable();
            $table->string('interest_original_source')->nullable();
            $table->string('interest_medium')->nullable();
            $table->string('interest_channel')->nullable();
            $table->string('interest_utm_term')->nullable();
            $table->string('interest_origin_delegation')->nullable();
            $table->string('interest_origin_zone')->nullable();
            $table->string('interest_owner_id', 18)->nullable();
            $table->string('interest_owner_name')->nullable();
            $table->boolean('interest_is_deleted')->nullable();
            $table->unsignedBigInteger('interest_sync_run_id')->nullable();
            $table->dateTime('interest_sync_cutoff_at')->nullable();
            $table->string('opportunity_relationship_status')->nullable();
            $table->index(['interest_functional_created_at', 'campaign_id'], 'campaign_attr_interest_date_campaign_idx');
        });

        Schema::table('campaign_lead_attributions', function (Blueprint $table): void {
            $table->string('lead_id')->nullable()->change();
            $table->string('interest_id', 18)->nullable()->index('campaign_interest_attr_interest_idx');
            $table->dateTime('interest_functional_created_at')->nullable()->index('campaign_interest_attr_date_idx');
            $table->string('interest_status')->nullable();
            $table->string('interest_type')->nullable();
            $table->string('interest_source')->nullable();
            $table->string('interest_original_source')->nullable();
            $table->string('interest_medium')->nullable();
            $table->string('interest_channel')->nullable();
            $table->string('interest_utm_term')->nullable();
            $table->string('interest_origin_delegation')->nullable();
            $table->string('interest_origin_zone')->nullable();
            $table->string('interest_owner_id', 18)->nullable();
            $table->string('interest_owner_name')->nullable();
            $table->boolean('interest_is_deleted')->nullable();
            $table->unsignedBigInteger('interest_sync_run_id')->nullable();
            $table->dateTime('interest_sync_cutoff_at')->nullable();
            $table->string('opportunity_relationship_status')->nullable();
            $table->index(['interest_id', 'opportunity_id'], 'campaign_interest_attr_interest_opp_idx');
        });
    }

    public function down(): void
    {
        DB::table('campaign_lead_attributions')->whereNotNull('interest_id')->delete();
        DB::table('campaign_attributions')->whereNotNull('interest_id')->delete();

        Schema::table('campaign_lead_attributions', function (Blueprint $table): void {
            $table->dropIndex('campaign_interest_attr_interest_opp_idx');
            $table->dropIndex('campaign_interest_attr_date_idx');
            $table->dropIndex('campaign_interest_attr_interest_idx');
            $table->dropColumn([
                'interest_id', 'interest_functional_created_at', 'interest_status', 'interest_type',
                'interest_source', 'interest_original_source', 'interest_medium', 'interest_channel', 'interest_utm_term',
                'interest_origin_delegation', 'interest_origin_zone', 'interest_owner_id',
                'interest_owner_name', 'interest_is_deleted', 'interest_sync_run_id',
                'interest_sync_cutoff_at', 'opportunity_relationship_status',
            ]);
            $table->string('lead_id')->nullable(false)->change();
        });

        Schema::table('campaign_attributions', function (Blueprint $table): void {
            $table->dropIndex('campaign_attributions_opportunity_id_index');
            $table->unique('opportunity_id', 'campaign_attributions_opportunity_id_unique');
            $table->dropIndex('campaign_attr_interest_date_campaign_idx');
            $table->dropIndex('campaign_attr_interest_date_idx');
            $table->dropUnique('campaign_attr_interest_uq');
            $table->dropColumn([
                'interest_id', 'interest_functional_created_at', 'interest_status', 'interest_type',
                'interest_source', 'interest_original_source', 'interest_medium', 'interest_channel', 'interest_utm_term',
                'interest_origin_delegation', 'interest_origin_zone', 'interest_owner_id',
                'interest_owner_name', 'interest_is_deleted', 'interest_sync_run_id',
                'interest_sync_cutoff_at', 'opportunity_relationship_status',
            ]);
            $table->string('lead_id')->nullable(false)->change();
        });
    }
};
