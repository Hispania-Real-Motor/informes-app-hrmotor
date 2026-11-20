<?php

namespace Tests\Feature;

use App\Models\CommercialCommissionClosure;
use App\Models\StoreManagerCommissionTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class StoreManagerTeamRosterEvidenceImportTest extends TestCase
{
    use RefreshDatabase;

    private string $relativePath = 'storage/framework/testing/store-manager-roster.csv';

    protected function tearDown(): void
    {
        File::delete(base_path($this->relativePath));
        parent::tearDown();
    }

    public function test_roster_import_is_auditable_idempotent_and_supports_dry_run(): void
    {
        StoreManagerCommissionTarget::query()->create([
            'month' => '2026-10',
            'zone' => 'Norte',
            'store_key' => 'a-coruna',
            'store_name' => 'A Coruña',
            'delivery_target' => 10,
            'purchase_target' => 10,
            'source_sha256' => hash('sha256', 'fixture'),
            'source_label' => 'fixture',
        ]);
        $this->writeCsv("month,delegation_name,salesforce_user_id,member_name,coverage_from,coverage_to,source,evidence_reference,recorded_by,history_verified\n2026-10,HR Motor || A Coruña,005000000000001,Persona fixture,2026-10-01 00:00:00,2026-11-01 00:00:00,direction_confirmation,fixture-roster,fixture-actor,true\n");

        $this->artisan('commissions:import-store-manager-roster', ['file' => $this->relativePath])
            ->assertExitCode(0);
        $this->assertDatabaseCount('store_manager_commission_roster_members', 0);

        $this->artisan('commissions:import-store-manager-roster', [
            'file' => $this->relativePath,
            '--apply' => true,
            '--imported-by' => 'report-user:fixture',
        ])
            ->assertExitCode(0);
        $this->artisan('commissions:import-store-manager-roster', [
            'file' => $this->relativePath,
            '--apply' => true,
            '--imported-by' => 'report-user:fixture',
        ])
            ->assertExitCode(0);

        $this->assertDatabaseCount('store_manager_commission_roster_members', 1);
        $this->assertDatabaseHas('store_manager_commission_roster_members', [
            'delegation_key' => 'a-coruna',
            'salesforce_user_id' => '005000000000001',
            'history_verified' => true,
            'recorded_by' => 'fixture-actor',
            'imported_by' => 'report-user:fixture',
        ]);
    }

    public function test_roster_import_rejects_overlapping_assignments_across_stores_atomically(): void
    {
        foreach ([['a-coruna', 'A Coruña'], ['madrid', 'Madrid']] as [$storeKey, $storeName]) {
            StoreManagerCommissionTarget::query()->create([
                'month' => '2026-10',
                'zone' => 'Norte',
                'store_key' => $storeKey,
                'store_name' => $storeName,
                'delivery_target' => 10,
                'purchase_target' => 10,
                'source_sha256' => hash('sha256', 'fixture-'.$storeKey),
                'source_label' => 'fixture',
            ]);
        }
        $this->writeCsv("month,delegation_name,salesforce_user_id,member_name,coverage_from,coverage_to,source,evidence_reference,recorded_by,history_verified\n2026-10,A Coruña,005000000000001,Persona fixture,2026-10-01 00:00:00,2026-11-01 00:00:00,direction_confirmation,fixture-a,fixture-actor,true\n2026-10,Madrid,005000000000001,Persona fixture,2026-10-15 00:00:00,2026-11-01 00:00:00,direction_confirmation,fixture-b,fixture-actor,true\n");

        $this->artisan('commissions:import-store-manager-roster', [
            'file' => $this->relativePath,
            '--apply' => true,
            '--imported-by' => 'report-user:fixture',
        ])->assertExitCode(1);

        $this->assertDatabaseCount('store_manager_commission_roster_members', 0);
    }

    public function test_roster_import_cannot_change_a_definitive_delegation_period(): void
    {
        StoreManagerCommissionTarget::query()->create([
            'month' => '2026-10',
            'zone' => 'Norte',
            'store_key' => 'a-coruna',
            'store_name' => 'A Coruña',
            'delivery_target' => 10,
            'purchase_target' => 10,
            'source_sha256' => hash('sha256', 'fixture'),
            'source_label' => 'fixture',
        ]);
        CommercialCommissionClosure::query()->create([
            'month' => '2026-10',
            'closure_scope' => CommercialCommissionClosure::SCOPE_DELEGATIONS,
            'status' => CommercialCommissionClosure::STATUS_DEFINITIVE,
            'component_statuses' => [],
            'snapshot_version' => 1,
            'formula_version' => 'fixture',
            'data_cutoff_at' => now(),
        ]);
        $this->writeCsv("month,delegation_name,salesforce_user_id,member_name,coverage_from,coverage_to,source,evidence_reference,recorded_by,history_verified\n2026-10,A Coruña,005000000000001,Persona fixture,2026-10-01 00:00:00,2026-11-01 00:00:00,direction_confirmation,fixture-roster,fixture-actor,true\n");

        $this->artisan('commissions:import-store-manager-roster', [
            'file' => $this->relativePath,
            '--apply' => true,
            '--imported-by' => 'report-user:fixture',
        ])
            ->assertExitCode(1);

        $this->assertDatabaseCount('store_manager_commission_roster_members', 0);
    }

    private function writeCsv(string $contents): void
    {
        File::ensureDirectoryExists(dirname(base_path($this->relativePath)));
        File::put(base_path($this->relativePath), $contents);
    }
}
