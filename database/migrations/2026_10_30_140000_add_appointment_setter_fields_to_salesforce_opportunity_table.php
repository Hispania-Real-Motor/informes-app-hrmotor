<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesforce_opportunities', function (Blueprint $table): void {
            $table->string('appointment_setter_id', 18)
                ->nullable()
                ->after('owner_name');
            $table->string('appointment_setter_name')
                ->nullable()
                ->after('appointment_setter_id');
            $table->index(
                ['appointment_setter_id', 'reservation_date'],
                'sf_opps_appt_setter_reservation_idx'
            );
            $table->index(
                ['appointment_setter_id', 'cv_signed_date'],
                'sf_opps_appt_setter_cv_signed_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('salesforce_opportunities', function (Blueprint $table): void {
            $table->dropIndex('sf_opps_appt_setter_reservation_idx');
            $table->dropIndex('sf_opps_appt_setter_cv_signed_idx');
            $table->dropColumn([
                'appointment_setter_id',
                'appointment_setter_name',
            ]);
        });
    }
};
