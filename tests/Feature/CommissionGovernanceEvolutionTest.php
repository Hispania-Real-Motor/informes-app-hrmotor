<?php

namespace Tests\Feature;

use App\Models\SalesforceDelegationManagerHistory;
use App\Services\Reports\CommercialCommissions\CommercialCommissionFormulaConfigService;
use App\Services\Reports\CommercialCommissions\DelegationManagerEvidenceService;
use App\Services\Reports\CommercialCommissions\DelegationManagerHistoryResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CommissionGovernanceEvolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_incremental_migration_upgrades_legacy_schema_without_losing_or_promoting_evidence(): void
    {
        $this->createLegacyHistoryTable();
        DB::table('salesforce_delegation_manager_history')->insert([
            'source_key' => 'legacy-observation',
            'delegation_salesforce_id' => 'a01000000000001',
            'delegation_name' => 'Alicante',
            'delegation_key' => 'alicante',
            'manager_salesforce_user_id' => '005LEGACY',
            'manager_name' => 'Responsable observado',
            'effective_at' => '2026-07-15 10:00:00',
            'observed_at' => '2026-07-15 10:00:00',
            'source' => 'current_observation',
            'history_verified' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_08_28_100000_add_coverage_metadata_to_salesforce_delegation_manager_history_table.php');
        $migration->up();
        $migration->up();

        foreach (['coverage_from', 'coverage_to', 'evidence_reference', 'recorded_by'] as $column) {
            $this->assertTrue(Schema::hasColumn('salesforce_delegation_manager_history', $column));
        }
        $this->assertTrue(Schema::hasIndex('salesforce_delegation_manager_history', 'sf_deleg_mgr_coverage_idx'));
        $legacy = DB::table('salesforce_delegation_manager_history')->where('source_key', 'legacy-observation')->first();
        $this->assertSame('2026-07-15 10:00:00', $legacy->coverage_from);
        $this->assertNull($legacy->coverage_to);
        $this->assertSame(0, (int) $legacy->history_verified);
        $this->assertNull($legacy->evidence_reference);
        $this->assertNull($legacy->recorded_by);
    }

    public function test_incremental_migration_is_a_no_op_on_the_final_fresh_schema(): void
    {
        $migration = require database_path('migrations/2026_08_28_100000_add_coverage_metadata_to_salesforce_delegation_manager_history_table.php');

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('salesforce_delegation_manager_history', 'coverage_from'));
        $this->assertTrue(Schema::hasIndex('salesforce_delegation_manager_history', 'sf_deleg_mgr_coverage_idx'));
    }

    public function test_resolver_degrades_to_unverified_when_legacy_schema_has_not_been_upgraded(): void
    {
        $this->createLegacyHistoryTable();
        Log::spy();

        $resolved = app(DelegationManagerHistoryResolver::class)->resolve(CarbonImmutable::parse('2026-07-01'), ['Alicante']);

        $this->assertSame('unverified', $resolved['alicante']['store_manager_history_status']);
        $this->assertNull($resolved['alicante']['store_manager_salesforce_user_id']);
        $this->assertNull($resolved['alicante']['store_manager_alert']);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $context === [
                'integration' => 'delegation_manager_history',
                'status' => 'schema_upgrade_pending',
            ]
        );
    }

    public function test_resolver_degrades_to_unverified_when_history_table_does_not_exist(): void
    {
        Schema::dropIfExists('salesforce_delegation_manager_history');
        Log::spy();

        $resolved = app(DelegationManagerHistoryResolver::class)->resolve(CarbonImmutable::parse('2026-07-01'), ['Alicante']);

        $this->assertSame('unverified', $resolved['alicante']['store_manager_history_status']);
        $this->assertNull($resolved['alicante']['store_manager_name']);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $context['status'] === 'table_missing'
        );
    }

    public function test_july_delegation_goal_is_derived_from_area_manager_while_june_keeps_legacy_goal(): void
    {
        $service = app(CommercialCommissionFormulaConfigService::class);
        $settings = $service->defaults();
        $settings['delegations']['goals']['alicante'] = ['label' => 'Alicante', 'target_deliveries' => 10];
        $settings['area_manager']['assignments']['alicante'] = [
            'label' => 'Alicante', 'manager_key' => 'david_baeza',
            'objectives' => ['deliveries' => 25, 'benefit' => 0, 'premium_guarantee' => 0, 'purchases' => 0],
        ];

        $service->saveForMonth('2026-06', $settings);
        $service->saveForMonth('2026-07', $settings);

        $this->assertSame(10, data_get($service->forMonth('2026-06'), 'delegations.goals.alicante.target_deliveries'));
        $this->assertSame(25, data_get($service->forMonth('2026-07'), 'delegations.goals.alicante.target_deliveries'));
    }

    public function test_last_verified_manager_receives_full_row_and_two_managers_do_not_raise_an_alert(): void
    {
        foreach ([
            ['source_key' => 'baseline', 'manager_salesforce_user_id' => '005A', 'manager_name' => 'Juan', 'effective_at' => '2026-07-01 00:00:00', 'coverage_from' => '2026-07-01 00:00:00', 'coverage_to' => '2026-07-20 10:00:00'],
            ['source_key' => 'change', 'manager_salesforce_user_id' => '005B', 'manager_name' => 'Pedro', 'effective_at' => '2026-07-20 10:00:00', 'coverage_from' => '2026-07-20 10:00:00', 'coverage_to' => '2026-08-01 00:00:00'],
        ] as $row) {
            SalesforceDelegationManagerHistory::query()->create($row + [
                'delegation_salesforce_id' => 'a01000000000001', 'delegation_name' => 'Alicante', 'delegation_key' => 'alicante',
                'observed_at' => '2026-08-01 00:00:00', 'source' => 'field_history', 'history_verified' => true,
            ]);
        }

        $resolved = app(DelegationManagerHistoryResolver::class)->resolve(CarbonImmutable::parse('2026-07-01'), ['Alicante'])['alicante'];

        $this->assertSame('005B', $resolved['store_manager_salesforce_user_id']);
        $this->assertSame('Pedro', $resolved['store_manager_name']);
        $this->assertSame(2, $resolved['store_manager_distinct_count']);
        $this->assertNull($resolved['store_manager_alert']);
    }

    public function test_current_observation_without_history_is_explicitly_unverifiable(): void
    {
        SalesforceDelegationManagerHistory::query()->create([
            'source_key' => 'current', 'delegation_salesforce_id' => 'a01000000000001', 'delegation_name' => 'Alicante',
            'delegation_key' => 'alicante', 'manager_salesforce_user_id' => '005B', 'manager_name' => 'Pedro',
            'effective_at' => '2026-07-20 10:00:00', 'observed_at' => '2026-07-20 10:00:00',
            'coverage_from' => '2026-07-20 00:00:00', 'coverage_to' => '2026-07-21 00:00:00',
            'source' => 'current_observation', 'history_verified' => false,
        ]);

        $resolved = app(DelegationManagerHistoryResolver::class)->resolve(CarbonImmutable::parse('2026-07-01'), ['Alicante'])['alicante'];

        $this->assertSame('unverified', $resolved['store_manager_history_status']);
        $this->assertNull($resolved['store_manager_salesforce_user_id']);
        $this->assertNull($resolved['store_manager_name']);
        $this->assertSame(1, $resolved['store_manager_distinct_count']);
        $this->assertNull($resolved['store_manager_alert']);
    }

    public function test_unverified_closing_manager_does_not_claim_verified_history(): void
    {
        $this->managerEvidence('unverified-closing', '005B', 'Pedro', '2026-07-01', '2026-08-01', false);

        $resolved = app(DelegationManagerHistoryResolver::class)->resolve(CarbonImmutable::parse('2026-07-01'), ['Alicante'])['alicante'];

        $this->assertSame('unverified', $resolved['store_manager_history_status']);
        $this->assertSame('unverified', $resolved['store_manager_closing_status']);
        $this->assertNull($resolved['store_manager_salesforce_user_id']);
        $this->assertNull($resolved['store_manager_name']);
    }

    public function test_verified_closing_manager_with_a_gap_does_not_claim_full_month_coverage(): void
    {
        $this->managerEvidence('verified-closing-gap', '005B', 'Pedro', '2026-07-15', '2026-08-01', true);

        $resolved = app(DelegationManagerHistoryResolver::class)->resolve(CarbonImmutable::parse('2026-07-01'), ['Alicante'])['alicante'];

        $this->assertSame('verified', $resolved['store_manager_history_status']);
        $this->assertSame('unverified', $resolved['store_manager_rotation_history_status']);
    }

    public function test_july_without_evidence_does_not_invent_a_manager(): void
    {
        $resolved = app(DelegationManagerHistoryResolver::class)->resolve(CarbonImmutable::parse('2026-07-01'), ['Alicante'])['alicante'];

        $this->assertNull($resolved['store_manager_salesforce_user_id']);
        $this->assertNull($resolved['store_manager_name']);
        $this->assertSame('unverified', $resolved['store_manager_history_status']);
    }

    public function test_unverified_closing_manager_is_not_shown_as_verified(): void
    {
        $this->managerEvidence('confirmed-close', '005B', 'Pedro', '2026-07-31 23:59:59', '2026-08-01 00:00:00', false);

        $resolved = app(DelegationManagerHistoryResolver::class)->resolve(CarbonImmutable::parse('2026-07-01'), ['Alicante'])['alicante'];

        $this->assertNull($resolved['store_manager_salesforce_user_id']);
        $this->assertSame('unverified', $resolved['store_manager_history_status']);
        $this->assertSame('unverified', $resolved['store_manager_closing_status']);
        $this->assertSame('unverified', $resolved['store_manager_rotation_history_status']);
        $this->assertSame(1, $resolved['store_manager_distinct_count']);
        $this->assertNull($resolved['store_manager_alert']);
    }

    public function test_month_end_evidence_does_not_mark_full_history_but_full_month_evidence_does(): void
    {
        $service = app(DelegationManagerEvidenceService::class);
        $base = [
            'delegation_id' => 'a01000000000001', 'delegation_name' => 'Alicante',
            'manager_id' => '005000000000001', 'manager_name' => 'Pedro',
            'month' => CarbonImmutable::parse('2026-07-01'), 'source' => 'it_confirmation',
            'reference' => 'ACTA-TEST', 'recorded_by' => 'test',
        ];

        $closing = $service->record($base + ['evidence_type' => DelegationManagerEvidenceService::TYPE_MONTH_END]);
        $this->assertFalse($closing->history_verified);
        $this->assertSame('2026-07-31 23:59:59', $closing->coverage_from->format('Y-m-d H:i:s'));

        $full = $service->record($base + ['evidence_type' => DelegationManagerEvidenceService::TYPE_FULL_MONTH]);
        $this->assertTrue($full->history_verified);
        $this->assertSame('2026-07-01 00:00:00', $full->coverage_from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-01 00:00:00', $full->coverage_to->format('Y-m-d H:i:s'));
    }

    public function test_august_observation_is_not_used_as_july_closing_manager(): void
    {
        $this->managerEvidence('august-observation', '005B', 'Pedro', '2026-08-01 09:00:00', '2026-08-02 00:00:00', false);

        $resolved = app(DelegationManagerHistoryResolver::class)->resolve(CarbonImmutable::parse('2026-07-01'), ['Alicante'])['alicante'];

        $this->assertNull($resolved['store_manager_salesforce_user_id']);
        $this->assertSame('unverified', $resolved['store_manager_closing_status']);
    }

    public function test_zero_changes_with_full_confirmed_coverage_is_verifiable_without_rotation_warning(): void
    {
        $this->managerEvidence('full-month', '005A', 'Juan', '2026-07-01', '2026-08-01', true);

        $resolved = app(DelegationManagerHistoryResolver::class)->resolve(CarbonImmutable::parse('2026-07-01'), ['Alicante'])['alicante'];

        $this->assertSame('verified', $resolved['store_manager_history_status']);
        $this->assertSame(1, $resolved['store_manager_distinct_count']);
        $this->assertNull($resolved['store_manager_alert']);
    }

    public function test_full_month_observations_without_a_manager_do_not_claim_a_verified_responsible(): void
    {
        $this->managerEvidence('vacant-month', '', '', '2026-07-01', '2026-08-01', true);

        $resolved = app(DelegationManagerHistoryResolver::class)->resolve(CarbonImmutable::parse('2026-07-01'), ['Alicante'])['alicante'];

        $this->assertSame('unverified', $resolved['store_manager_history_status']);
        $this->assertNull($resolved['store_manager_salesforce_user_id']);
        $this->assertSame(0, $resolved['store_manager_distinct_count']);
        $this->assertNull($resolved['store_manager_alert']);
    }

    public function test_old_verified_event_with_later_coverage_gap_does_not_verify_the_whole_month(): void
    {
        $this->managerEvidence('old-segment', '005A', 'Juan', '2026-07-01', '2026-07-10', true);

        $resolved = app(DelegationManagerHistoryResolver::class)->resolve(CarbonImmutable::parse('2026-07-01'), ['Alicante'])['alicante'];

        $this->assertSame('unverified', $resolved['store_manager_history_status']);
        $this->assertSame(1, $resolved['store_manager_distinct_count']);
        $this->assertNull($resolved['store_manager_alert']);
    }

    public function test_daily_local_tracking_covering_every_day_verifies_the_month(): void
    {
        $day = CarbonImmutable::parse('2026-07-01');

        while ($day->month === 7) {
            $this->managerEvidence(
                'daily-'.$day->toDateString(),
                '005A',
                'Juan',
                $day->startOfDay()->toDateTimeString(),
                $day->addDay()->startOfDay()->toDateTimeString(),
                true,
            );
            $day = $day->addDay();
        }

        $resolved = app(DelegationManagerHistoryResolver::class)->resolve(CarbonImmutable::parse('2026-07-01'), ['Alicante'])['alicante'];

        $this->assertSame('verified', $resolved['store_manager_history_status']);
        $this->assertSame('005A', $resolved['store_manager_salesforce_user_id']);
        $this->assertNull($resolved['store_manager_alert']);
    }

    public function test_three_distinct_managers_are_reported_without_prorating_the_final_manager(): void
    {
        $this->managerEvidence('one', '005A', 'Juan', '2026-07-01', '2026-07-10', true);
        $this->managerEvidence('two', '005B', 'Pedro', '2026-07-10', '2026-07-20', true);
        $this->managerEvidence('three', '005C', 'Carlos', '2026-07-20', '2026-08-01', true);

        $resolved = app(DelegationManagerHistoryResolver::class)->resolve(CarbonImmutable::parse('2026-07-01'), ['Alicante'])['alicante'];

        $this->assertSame('005C', $resolved['store_manager_salesforce_user_id']);
        $this->assertSame(3, $resolved['store_manager_distinct_count']);
        $this->assertStringContainsString('3 jefes de tienda', $resolved['store_manager_alert']);
        $this->assertStringContainsString('demostrados durante julio de 2026', $resolved['store_manager_alert']);
    }

    public function test_four_distinct_managers_report_the_real_count(): void
    {
        $this->managerEvidence('one', '005A', 'Juan', '2026-07-01', '2026-07-08', true);
        $this->managerEvidence('two', '005B', 'Pedro', '2026-07-08', '2026-07-16', true);
        $this->managerEvidence('three', '005C', 'Carlos', '2026-07-16', '2026-07-24', true);
        $this->managerEvidence('four', '005D', 'Luis', '2026-07-24', '2026-08-01', true);

        $resolved = app(DelegationManagerHistoryResolver::class)->resolve(CarbonImmutable::parse('2026-07-01'), ['Alicante'])['alicante'];

        $this->assertSame('005D', $resolved['store_manager_salesforce_user_id']);
        $this->assertSame(4, $resolved['store_manager_distinct_count']);
        $this->assertStringContainsString('4 jefes de tienda demostrados', $resolved['store_manager_alert']);
    }

    private function managerEvidence(string $sourceKey, string $managerId, string $managerName, string $from, string $to, bool $verified): void
    {
        SalesforceDelegationManagerHistory::query()->create([
            'source_key' => $sourceKey, 'delegation_salesforce_id' => 'a01000000000001', 'delegation_name' => 'Alicante',
            'delegation_key' => 'alicante', 'manager_salesforce_user_id' => $managerId, 'manager_name' => $managerName,
            'effective_at' => $from, 'coverage_from' => $from, 'coverage_to' => $to, 'observed_at' => $to,
            'source' => 'it_confirmation', 'evidence_reference' => 'TEST-1', 'recorded_by' => 'test', 'history_verified' => $verified,
        ]);
    }

    private function createLegacyHistoryTable(): void
    {
        Schema::dropIfExists('salesforce_delegation_manager_history');
        Schema::create('salesforce_delegation_manager_history', function (Blueprint $table): void {
            $table->id();
            $table->string('source_key', 120)->unique('sf_deleg_mgr_source_uq');
            $table->string('delegation_salesforce_id', 18)->index('sf_deleg_mgr_deleg_id_idx');
            $table->string('delegation_name');
            $table->string('delegation_key')->index('sf_deleg_mgr_key_idx');
            $table->string('manager_salesforce_user_id', 18)->nullable()->index('sf_deleg_mgr_user_idx');
            $table->string('manager_name')->nullable();
            $table->timestamp('effective_at');
            $table->timestamp('observed_at');
            $table->string('source', 32);
            $table->boolean('history_verified')->default(false);
            $table->timestamps();
            $table->index(['delegation_key', 'effective_at'], 'sf_deleg_mgr_key_effective_idx');
        });
    }
}
