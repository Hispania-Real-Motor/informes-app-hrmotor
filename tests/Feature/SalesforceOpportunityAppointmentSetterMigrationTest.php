<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SalesforceOpportunityAppointmentSetterMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_materializa_el_captador_de_cita_con_indices_por_evento(): void
    {
        $this->assertTrue(Schema::hasColumns('salesforce_opportunities', [
            'appointment_setter_id',
            'appointment_setter_name',
        ]));

        $indexes = collect(Schema::getIndexes('salesforce_opportunities'))->keyBy('name');

        $this->assertArrayHasKey('sf_opps_appt_setter_reservation_idx', $indexes);
        $this->assertSame([
            'appointment_setter_id',
            'reservation_date',
        ], $indexes['sf_opps_appt_setter_reservation_idx']['columns']);
        $this->assertArrayHasKey('sf_opps_appt_setter_cv_signed_idx', $indexes);
        $this->assertSame([
            'appointment_setter_id',
            'cv_signed_date',
        ], $indexes['sf_opps_appt_setter_cv_signed_idx']['columns']);
    }
}
