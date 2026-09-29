<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salesforce_interests', function (Blueprint $table): void {
            $table->id();
            $table->string('salesforce_id', 18)->unique('sf_interests_salesforce_id_uq');
            $table->string('lead_salesforce_id', 18)->nullable()->index('sf_interests_lead_idx');
            $table->string('account_salesforce_id', 18)->nullable()->index('sf_interests_account_idx');
            $table->string('migration_origin_lead_id', 18)->nullable()->unique('sf_interests_migration_lead_uq');
            $table->dateTime('salesforce_created_at');
            $table->dateTime('salesforce_last_modified_at');
            $table->dateTime('origin_created_at')->nullable();
            $table->dateTime('functional_created_at')->index('sf_interests_functional_date_idx');
            $table->string('owner_salesforce_id', 18)->nullable();
            $table->string('owner_name')->nullable();
            $table->string('status')->nullable();
            $table->string('type')->nullable();
            $table->string('source')->nullable();
            $table->text('original_source')->nullable();
            $table->string('medium')->nullable();
            $table->string('channel')->nullable();
            $table->string('origin_delegation')->nullable();
            $table->string('utm_campaign', 70)->nullable();
            $table->string('utm_id', 70)->nullable();
            $table->string('utm_source', 70)->nullable();
            $table->string('utm_medium', 70)->nullable();
            $table->string('utm_content', 70)->nullable();
            $table->string('utm_term', 255)->nullable();
            $table->string('sale_vehicle_salesforce_id', 18)->nullable();
            $table->string('appraisal_vehicle_salesforce_id', 18)->nullable();
            $table->string('inverse_opportunity_salesforce_id', 18)->nullable()->index('sf_interests_inverse_opp_idx');
            $table->string('canonical_person_type', 7)->nullable();
            $table->string('canonical_person_salesforce_id', 18)->nullable();
            $table->json('raw_payload')->nullable();
            $table->dateTime('synced_at')->nullable();
            $table->timestamps();

            $table->index(
                ['salesforce_last_modified_at', 'salesforce_id'],
                'sf_interests_modified_id_idx',
            );
            $table->index(
                ['canonical_person_type', 'canonical_person_salesforce_id', 'functional_created_at'],
                'sf_interests_person_date_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salesforce_interests');
    }
};
