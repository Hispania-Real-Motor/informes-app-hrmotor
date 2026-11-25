<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SalesforceLeadContactCenterAppointmentIndexMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_covers_the_contact_center_roster_predicate_without_redundant_single_column_indexes(): void
    {
        $indexes = collect(Schema::getIndexes('salesforce_leads'))->keyBy('name');

        $this->assertArrayHasKey('sf_leads_contact_center_appointment_idx', $indexes);
        $this->assertSame([
            'appointment_setter_id',
            'is_deleted',
            'salesforce_master_record_id',
            'appointment_capture_date',
        ], $indexes['sf_leads_contact_center_appointment_idx']['columns']);
    }

    public function test_migration_is_safe_when_the_named_index_was_partially_created(): void
    {
        $migration = require database_path('migrations/2026_11_24_120000_add_contact_center_appointment_certification_index_to_salesforce_leads.php');
        $migration->down();

        Schema::table('salesforce_leads', function (Blueprint $table): void {
            $table->index([
                'appointment_setter_id',
                'is_deleted',
                'salesforce_master_record_id',
                'appointment_capture_date',
            ], 'sf_leads_contact_center_appointment_idx');
        });

        $migration->up();

        $this->assertTrue(Schema::hasIndex('salesforce_leads', 'sf_leads_contact_center_appointment_idx'));
    }

    public function test_migration_reuses_an_equivalent_index_with_another_name(): void
    {
        $migration = require database_path('migrations/2026_11_24_120000_add_contact_center_appointment_certification_index_to_salesforce_leads.php');
        $migration->down();

        Schema::table('salesforce_leads', function (Blueprint $table): void {
            $table->index([
                'appointment_setter_id',
                'is_deleted',
                'salesforce_master_record_id',
                'appointment_capture_date',
            ], 'existing_equivalent_contact_center_idx');
        });

        $migration->up();

        $this->assertTrue(Schema::hasIndex('salesforce_leads', 'existing_equivalent_contact_center_idx'));
        $this->assertFalse(Schema::hasIndex('salesforce_leads', 'sf_leads_contact_center_appointment_idx'));
    }

    public function test_migration_rejects_the_expected_name_with_incompatible_columns(): void
    {
        $migration = require database_path('migrations/2026_11_24_120000_add_contact_center_appointment_certification_index_to_salesforce_leads.php');
        $migration->down();

        Schema::table('salesforce_leads', function (Blueprint $table): void {
            $table->index(['appointment_setter_id'], 'sf_leads_contact_center_appointment_idx');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('columnas incompatibles');
        $migration->up();
    }
}
