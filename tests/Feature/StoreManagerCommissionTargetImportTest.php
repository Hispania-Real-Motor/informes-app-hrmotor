<?php

namespace Tests\Feature;

use App\Models\CommercialCommissionClosure;
use App\Models\CommercialCommissionSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StoreManagerCommissionTargetImportTest extends TestCase
{
    use RefreshDatabase;

    private const FILE = 'database/seeders/StoreManagerCommissionTargetsOctober2026.csv';

    public function test_dry_run_validates_the_file_even_when_delegations_are_definitive(): void
    {
        $closure = $this->closure(CommercialCommissionClosure::SCOPE_DELEGATIONS, CommercialCommissionClosure::STATUS_DEFINITIVE);

        $this->artisan('commissions:import-store-manager-targets', [
            'file' => self::FILE,
            '--month' => '2026-10',
        ])->assertExitCode(0);

        $this->assertDatabaseCount('store_manager_commission_targets', 0);
        $this->assertDatabaseHas('commercial_commission_closures', [
            'id' => $closure->id,
            'status' => CommercialCommissionClosure::STATUS_DEFINITIVE,
        ]);
    }

    public function test_write_allows_reopened_delegations_when_financials_are_definitive(): void
    {
        $delegations = $this->closure(CommercialCommissionClosure::SCOPE_DELEGATIONS, CommercialCommissionClosure::STATUS_REOPENED);
        $financials = $this->closure(CommercialCommissionClosure::SCOPE_FINANCIALS, CommercialCommissionClosure::STATUS_DEFINITIVE);

        $this->artisan('commissions:import-store-manager-targets', [
            'file' => self::FILE,
            '--month' => '2026-10',
            '--write' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('store_manager_commission_targets', 34);
        $this->assertDatabaseHas('store_manager_commission_targets', [
            'month' => '2026-10',
            'store_key' => 'a-coruna',
            'source_label' => 'Detalle por tienda — OBJETIVOS OCTUBRE ULTIMOS(1).xlsx',
        ]);
        $this->assertSame(CommercialCommissionClosure::STATUS_REOPENED, $delegations->fresh()->status);
        $this->assertSame(CommercialCommissionClosure::STATUS_DEFINITIVE, $financials->fresh()->status);
    }

    public function test_write_rejects_a_definitive_delegations_closure_inside_the_transaction(): void
    {
        $this->closure(CommercialCommissionClosure::SCOPE_DELEGATIONS, CommercialCommissionClosure::STATUS_DEFINITIVE);

        $this->artisan('commissions:import-store-manager-targets', [
            'file' => self::FILE,
            '--month' => '2026-10',
            '--write' => true,
        ])->assertExitCode(1);

        $this->assertDatabaseCount('store_manager_commission_targets', 0);
    }

    public function test_write_rejects_a_pending_candidate_that_does_not_freeze_the_incoming_targets(): void
    {
        $closure = $this->closure(CommercialCommissionClosure::SCOPE_DELEGATIONS, CommercialCommissionClosure::STATUS_PENDING_APPROVAL, 1);
        CommercialCommissionSnapshot::query()->create([
            'closure_id' => $closure->id,
            'month' => '2026-10',
            'version' => 1,
            'formula_version' => '2026-10-store-manager',
            'data_cutoff_at' => now(),
            'payload' => ['delegations' => ['store_manager_targets' => []]],
            'source_state' => [],
        ]);

        $this->artisan('commissions:import-store-manager-targets', [
            'file' => self::FILE,
            '--month' => '2026-10',
            '--write' => true,
        ])->assertExitCode(1);

        $this->assertDatabaseCount('store_manager_commission_targets', 0);
        $this->assertSame(CommercialCommissionClosure::STATUS_PENDING_APPROVAL, $closure->fresh()->status);
        $this->assertDatabaseCount('commercial_commission_snapshots', 1);
    }

    public function test_second_identical_import_is_idempotent_and_preserves_audit_fields(): void
    {
        $arguments = [
            'file' => self::FILE,
            '--month' => '2026-10',
            '--write' => true,
        ];

        $this->artisan('commissions:import-store-manager-targets', $arguments)->assertExitCode(0);
        $before = DB::table('store_manager_commission_targets')
            ->orderBy('store_key')
            ->get(['month', 'zone', 'store_key', 'store_name', 'delivery_target', 'purchase_target', 'source_sha256', 'source_label'])
            ->all();

        $this->artisan('commissions:import-store-manager-targets', $arguments)->assertExitCode(0);
        $after = DB::table('store_manager_commission_targets')
            ->orderBy('store_key')
            ->get(['month', 'zone', 'store_key', 'store_name', 'delivery_target', 'purchase_target', 'source_sha256', 'source_label'])
            ->all();

        $this->assertCount(34, $after);
        $this->assertSame(907, (int) DB::table('store_manager_commission_targets')->sum('delivery_target'));
        $this->assertSame(499, (int) DB::table('store_manager_commission_targets')->sum('purchase_target'));
        $this->assertEquals($before, $after);
    }

    private function closure(string $scope, string $status, int $snapshotVersion = 0): CommercialCommissionClosure
    {
        return CommercialCommissionClosure::query()->create([
            'month' => '2026-10',
            'closure_scope' => $scope,
            'status' => $status,
            'component_statuses' => [],
            'snapshot_version' => $snapshotVersion,
            'formula_version' => 'fixture',
            'data_cutoff_at' => now(),
        ]);
    }
}
