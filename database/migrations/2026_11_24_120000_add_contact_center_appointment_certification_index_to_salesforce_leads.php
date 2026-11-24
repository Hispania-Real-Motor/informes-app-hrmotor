<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_leads', function (Blueprint $table): void {
            $table->index(
                ['appointment_setter_id', 'is_deleted', 'salesforce_master_record_id', 'appointment_capture_date'],
                'sf_leads_contact_center_appointment_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_leads', function (Blueprint $table): void {
            $table->dropIndex('sf_leads_contact_center_appointment_idx');
        });
    }
};
