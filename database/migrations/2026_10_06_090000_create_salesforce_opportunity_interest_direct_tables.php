<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salesforce_opportunity_interest_direct_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_identifier')->unique('sf_opp_int_direct_runs_identifier_uq');
            $table->string('reason', 500);
            $table->string('status', 20)->index('sf_opp_int_direct_runs_status_idx');
            $table->dateTime('source_cutoff_at');
            $table->dateTime('started_at');
            $table->dateTime('completed_at')->nullable();
            $table->json('stats')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('salesforce_opportunity_interest_directs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('direct_run_id');
            $table->string('opportunity_salesforce_id', 18);
            $table->string('interest_salesforce_id', 18)->nullable();
            $table->string('reference_status', 20);
            $table->boolean('opportunity_is_deleted');
            $table->dateTime('salesforce_last_modified_at')->nullable();
            $table->dateTime('system_modstamp_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['direct_run_id', 'opportunity_salesforce_id'],
                'sf_opp_int_direct_run_opp_uq',
            );
            $table->index(
                ['direct_run_id', 'interest_salesforce_id'],
                'sf_opp_int_direct_run_interest_idx',
            );
            $table->foreign('direct_run_id', 'sf_opp_int_direct_run_fk')
                ->references('id')
                ->on('salesforce_opportunity_interest_direct_runs')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salesforce_opportunity_interest_directs');
        Schema::dropIfExists('salesforce_opportunity_interest_direct_runs');
    }
};
