<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('campaign_operational_months')) {
            Schema::create('campaign_operational_months', function (Blueprint $table): void {
                $table->string('month_key', 7)->primary();
                $table->unsignedBigInteger('active_run_id')->nullable();
                $table->string('build_token', 64)->nullable();
                $table->timestamp('lease_expires_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('campaign_operational_runs')) {
            Schema::create('campaign_operational_runs', function (Blueprint $table): void {
                $table->id();
                $table->string('month_key', 7)->index('camp_op_runs_month_idx');
                $table->unsignedBigInteger('interest_run_id');
                $table->string('projection_key', 64);
                $table->unsignedTinyInteger('utm_contract_version');
                $table->timestamp('interest_cutoff_at');
                $table->timestamp('interest_observed_at');
                $table->string('economic_fingerprint', 64);
                $table->json('economic_evidence');
                $table->unsignedBigInteger('opportunity_sync_run_id')->nullable();
                $table->timestamp('opportunity_sync_cutoff_at')->nullable();
                $table->unsignedBigInteger('direct_relationship_run_id')->nullable();
                $table->timestamp('direct_relationship_cutoff_at')->nullable();
                $table->string('relationship_fingerprint', 64);
                $table->boolean('opportunity_coverage_complete')->default(false);
                $table->string('status', 20);
                $table->unsignedInteger('interests_processed')->default(0);
                $table->unsignedInteger('attributions_count')->default(0);
                $table->unsignedInteger('incidents_count')->default(0);
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('activated_at')->nullable();
                $table->timestamps();
                $table->foreign('interest_run_id', 'camp_op_runs_interest_fk')->references('id')->on('salesforce_interest_operational_runs');
            });
        }

        if (! Schema::hasTable('campaign_operational_attributions')) {
            Schema::create('campaign_operational_attributions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('run_id')->constrained('campaign_operational_runs')->restrictOnDelete();
                $table->string('interest_key', 64);
                $table->string('opportunity_key', 64)->nullable();
                $table->string('platform', 32);
                $table->string('campaign_id')->nullable();
                $table->string('campaign_name')->nullable();
                $table->string('campaign_source_type', 80);
                $table->string('attribution_method', 80);
                $table->string('match_status')->nullable();
                $table->string('campaign_type', 32)->nullable();
                $table->string('interest_status')->nullable();
                $table->string('interest_type')->nullable();
                $table->string('interest_source')->nullable();
                $table->string('interest_medium')->nullable();
                $table->string('interest_channel')->nullable();
                $table->string('interest_origin_delegation')->nullable();
                $table->string('interest_origin_zone')->nullable();
                $table->string('opportunity_relationship_status', 40)->nullable();
                $table->boolean('has_opportunity')->nullable();
                $table->boolean('has_reservation')->nullable();
                $table->boolean('has_fallen_reservation')->nullable();
                $table->boolean('has_sale')->nullable();
                $table->boolean('has_purchase')->nullable();
                $table->decimal('sale_amount', 16, 2)->nullable();
                $table->timestamp('interest_functional_created_at');
                $table->timestamps();
                $table->unique(['run_id', 'interest_key'], 'camp_op_attr_interest_uq');
                $table->unique(['run_id', 'opportunity_key'], 'camp_op_attr_opp_uq');
                $table->index(['run_id', 'platform', 'campaign_id'], 'camp_op_attr_campaign_idx');
            });
        }
    }

    public function down(): void
    {
        // Historical attribution evidence must survive application rollback.
    }
};
