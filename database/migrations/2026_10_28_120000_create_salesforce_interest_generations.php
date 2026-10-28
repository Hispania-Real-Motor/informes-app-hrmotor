<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salesforce_interest_generations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('base_generation_id')->nullable()->index();
            $table->unsignedBigInteger('interest_run_id')->nullable()->unique();
            $table->unsignedBigInteger('activity_run_id')->nullable()->unique();
            $table->dateTime('cutoff_at');
            $table->string('status', 24)->index();
            $table->boolean('f2_complete')->default(false);
            $table->boolean('f5_complete')->default(false);
            $table->json('validation')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('published_at')->nullable();
        });
        Schema::create('salesforce_interest_publication', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('generation_id')->nullable();
            $table->dateTime('watermark_at')->nullable();
        });
        DB::table('salesforce_interest_publication')->insert(['id' => 1]);
        Schema::create('salesforce_interest_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('generation_id')->index();
            $table->uuid('batch_token')->index();
            $table->char('content_hash', 64);
            $table->dateTime('system_modstamp_at')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->dateTime('salesforce_deleted_at')->nullable();
            $table->string('deletion_detection_source')->nullable();
            $table->string('salesforce_id', 18)->index('sf_iv_salesforce_id_idx');
            $table->string('lead_salesforce_id', 18)->nullable()->index('sf_iv_lead_idx');
            $table->string('account_salesforce_id', 18)->nullable()->index('sf_iv_account_idx');
            $table->string('migration_origin_lead_id', 18)->nullable()->index('sf_iv_migration_lead_idx');
            $table->dateTime('salesforce_created_at');
            $table->dateTime('salesforce_last_modified_at');
            $table->dateTime('origin_created_at')->nullable();
            $table->dateTime('functional_created_at')->index('sf_iv_functional_date_idx');
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
            $table->string('inverse_opportunity_salesforce_id', 18)->nullable()->index('sf_iv_inverse_opp_idx');
            $table->string('canonical_person_type', 7)->nullable();
            $table->string('canonical_person_salesforce_id', 18)->nullable();
            $table->json('raw_payload')->nullable();
            $table->index(['is_deleted', 'functional_created_at', 'status', 'id'], 'sf_iv_summary_idx');
            $table->index(['is_deleted', 'medium', 'functional_created_at', 'id'], 'sf_iv_rot5_idx');
            $table->dateTime('synced_at')->nullable();
            $table->timestamps();

            $table->index(
                ['salesforce_last_modified_at', 'salesforce_id'],
                'sf_iv_modified_id_idx',
            );
            $table->index(
                ['canonical_person_type', 'canonical_person_salesforce_id', 'functional_created_at'],
                'sf_iv_person_date_idx',
            );
        });
        Schema::create('salesforce_interest_members', function (Blueprint $table): void {
            $table->unsignedBigInteger('generation_id');
            $table->string('salesforce_id', 18);
            $table->unsignedBigInteger('version_id');
            $table->string('migration_origin_lead_id', 18)->nullable();
            $table->primary(['generation_id', 'salesforce_id'], 'sf_im_pk');
            $table->unique(['generation_id', 'migration_origin_lead_id'], 'sf_im_origin_uq');
            $table->index(['generation_id', 'version_id'], 'sf_im_version_idx');
            $table->foreign('generation_id')->references('id')->on('salesforce_interest_generations');
            $table->foreign('version_id')->references('id')->on('salesforce_interest_versions');
        });
        Schema::create('salesforce_interest_conflicts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('generation_id')->index();
            $table->unsignedBigInteger('interest_run_id')->nullable();
            $table->char('fingerprint', 64)->unique();
            $table->string('salesforce_id', 18);
            $table->string('migration_origin_lead_id', 18)->nullable();
            $table->string('existing_owner_id', 18)->nullable();
            $table->string('kind', 40);
            $table->text('evidence'); // Encrypted; never emitted in ordinary logs or APIs.
            $table->string('review_status', 24)->default('pending');
            $table->text('resolution')->nullable();
            $table->dateTime('detected_at');
        });
    }

    public function down(): void
    {
        // Rollback must never discard candidates, evidence, or published data.
        if (DB::table('salesforce_interest_generations')->exists()) {
            throw new RuntimeException('Generation storage is not empty; retain it during application rollback.');
        }
        Schema::dropIfExists('salesforce_interest_conflicts');
        Schema::dropIfExists('salesforce_interest_members');
        Schema::dropIfExists('salesforce_interest_versions');
        Schema::dropIfExists('salesforce_interest_publication');
        Schema::dropIfExists('salesforce_interest_generations');
    }
};
