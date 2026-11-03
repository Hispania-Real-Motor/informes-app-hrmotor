<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_commission_appointment_evidences', function (Blueprint $table): void {
            $table->id();
            $table->string('salesforce_interest_id', 18);
            $table->string('appointment_setter_id', 18)->nullable();
            $table->string('appointment_setter_name')->nullable();
            $table->date('appointment_capture_date')->nullable();
            $table->timestamp('salesforce_last_modified_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();
            $table->unique('salesforce_interest_id', 'cc_appt_evidence_interest_uq');
            $table->index(['appointment_setter_id', 'appointment_capture_date'], 'cc_appt_evidence_agent_date_idx');
            $table->index('appointment_capture_date', 'cc_appt_evidence_date_idx');
        });

        Schema::create('commercial_commission_product_delivery_evidences', function (Blueprint $table): void {
            $table->id();
            $table->string('salesforce_product_id', 18);
            $table->string('state')->nullable();
            $table->date('delivery_date')->nullable();
            $table->string('plate')->nullable();
            $table->timestamp('salesforce_last_modified_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();
            $table->unique('salesforce_product_id', 'cc_product_delivery_id_uq');
            $table->index('delivery_date', 'cc_product_delivery_date_idx');
            $table->index(['state', 'delivery_date'], 'cc_product_delivery_state_date_idx');
        });

        Schema::create('commercial_commission_delivery_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->string('reconciliation_key', 64);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('opportunity_salesforce_id', 18)->nullable();
            $table->string('product_salesforce_id', 18)->nullable();
            $table->string('plate')->nullable();
            $table->date('opportunity_cv_signed_date')->nullable();
            $table->date('product_delivery_date')->nullable();
            $table->string('product_state')->nullable();
            $table->string('discrepancy_type');
            $table->timestamp('reconciled_at');
            $table->timestamps();
            $table->unique('reconciliation_key', 'cc_delivery_recon_key_uq');
            $table->index(['period_start', 'period_end', 'discrepancy_type'], 'cc_delivery_recon_period_type_idx');
            $table->index('opportunity_salesforce_id', 'cc_delivery_recon_opp_idx');
            $table->index('product_salesforce_id', 'cc_delivery_recon_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_commission_delivery_reconciliations');
        Schema::dropIfExists('commercial_commission_product_delivery_evidences');
        Schema::dropIfExists('commercial_commission_appointment_evidences');
    }
};
