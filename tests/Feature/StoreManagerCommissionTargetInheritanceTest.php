<?php

namespace Tests\Feature;

use App\Models\CommercialCommissionClosure;
use App\Models\ReportUser;
use App\Models\StoreManagerCommissionTarget;
use App\Models\StoreManagerCommissionTargetEvent;
use App\Services\Reports\CommercialCommissions\StoreManagerCommissionTargetService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StoreManagerCommissionTargetInheritanceTest extends TestCase
{
    use RefreshDatabase;

    private const FILE = 'database/seeders/StoreManagerCommissionTargetsOctober2026.csv';

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_october_keeps_the_34_imported_targets_and_totals(): void
    {
        $this->importOctober();

        $this->assertDatabaseCount('store_manager_commission_targets', 34);
        $this->assertSame(907, (int) StoreManagerCommissionTarget::query()->where('month', '2026-10')->sum('delivery_target'));
        $this->assertSame(499, (int) StoreManagerCommissionTarget::query()->where('month', '2026-10')->sum('purchase_target'));
        $this->assertDatabaseHas('store_manager_commission_targets', [
            'month' => '2026-10',
            'source_type' => StoreManagerCommissionTargetService::SOURCE_TYPE_IMPORTED,
            'source_month' => '2026-10',
        ]);
    }

    public function test_november_inherits_october_once_and_records_provenance(): void
    {
        $this->importOctober();

        $rows = app(StoreManagerCommissionTargetService::class)->materializeForMonth('2026-11');

        $this->assertCount(34, $rows);
        $this->assertSame(907, array_sum(array_column($rows, 'target_deliveries')));
        $this->assertSame(499, array_sum(array_column($rows, 'target_purchases')));
        $this->assertSame(
            34,
            StoreManagerCommissionTarget::query()
                ->where('month', '2026-11')
                ->where('source_type', StoreManagerCommissionTargetService::SOURCE_TYPE_INHERITED)
                ->where('source_month', '2026-10')
                ->count(),
        );
        $this->assertSame(34, StoreManagerCommissionTargetEvent::query()->where('month', '2026-11')->count());
    }

    public function test_december_follows_the_sequential_chain_and_admin_changes_flow_forward_only(): void
    {
        $this->importOctober();
        $service = app(StoreManagerCommissionTargetService::class);
        $service->materializeForMonth('2026-11');

        $admin = ReportUser::query()->create([
            'name' => 'Targets Admin',
            'email' => 'targets-admin@example.test',
            'password' => 'secret',
            'role' => ReportUser::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $november = collect($service->forMonth('2026-11'))->mapWithKeys(fn (array $target): array => [
            $target['store_key'] => [
                'delivery_target' => $target['target_deliveries'],
                'purchase_target' => $target['target_purchases'],
            ],
        ])->all();
        $november['a-coruna'] = ['delivery_target' => 35, 'purchase_target' => 18];

        $this->assertSame(1, $service->updateForMonth('2026-11', $november, $admin->id));
        $december = $service->materializeForMonth('2026-12');
        $aCoruna = collect($december)->firstWhere('store_key', 'a-coruna');

        $this->assertSame(35, $aCoruna['target_deliveries']);
        $this->assertSame(18, $aCoruna['target_purchases']);
        $this->assertSame(30, (int) StoreManagerCommissionTarget::query()->where(['month' => '2026-10', 'store_key' => 'a-coruna'])->value('delivery_target'));
        $this->assertSame(18, (int) StoreManagerCommissionTarget::query()->where(['month' => '2026-12', 'store_key' => 'a-coruna'])->value('purchase_target'));
        $this->assertSame(StoreManagerCommissionTargetService::SOURCE_TYPE_ADMIN, StoreManagerCommissionTarget::query()->where(['month' => '2026-11', 'store_key' => 'a-coruna'])->value('source_type'));
        $this->assertSame('2026-11', $aCoruna['source_month']);
    }

    public function test_materialized_december_is_not_rewritten_after_a_later_november_change(): void
    {
        $this->importOctober();
        $service = app(StoreManagerCommissionTargetService::class);
        $service->materializeForMonth('2026-12');

        $admin = ReportUser::query()->create([
            'name' => 'Targets Admin',
            'email' => 'materialized-admin@example.test',
            'password' => 'secret',
            'role' => ReportUser::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $values = collect($service->forMonth('2026-11'))->mapWithKeys(fn (array $target): array => [
            $target['store_key'] => [
                'delivery_target' => $target['target_deliveries'],
                'purchase_target' => $target['target_purchases'],
            ],
        ])->all();
        $values['a-coruna']['delivery_target'] = 35;

        $this->assertSame(1, $service->updateForMonth('2026-11', $values, $admin->id));
        $december = StoreManagerCommissionTarget::query()->where([
            'month' => '2026-12',
            'store_key' => 'a-coruna',
        ])->firstOrFail();

        $this->assertSame(30, (int) $december->delivery_target);
        $this->assertSame(18, (int) $december->purchase_target);
        $this->assertSame(StoreManagerCommissionTargetService::SOURCE_TYPE_INHERITED, $december->source_type);
        $this->assertSame('2026-11', $december->source_month);
    }

    public function test_repeated_materialization_is_idempotent_and_does_not_add_events(): void
    {
        $this->importOctober();
        $service = app(StoreManagerCommissionTargetService::class);

        $service->materializeForMonth('2026-12');
        $before = StoreManagerCommissionTargetEvent::query()->count();
        $timestamps = StoreManagerCommissionTarget::query()
            ->where('month', '2026-11')
            ->pluck('updated_at', 'store_key')
            ->map(fn ($timestamp): ?string => $timestamp?->toDateTimeString())
            ->all();

        $service->materializeForMonth('2026-12');

        $this->assertSame($before, StoreManagerCommissionTargetEvent::query()->count());
        $this->assertSame(
            $timestamps,
            StoreManagerCommissionTarget::query()
                ->where('month', '2026-11')
                ->pluck('updated_at', 'store_key')
                ->map(fn ($timestamp): ?string => $timestamp?->toDateTimeString())
                ->all(),
        );
    }

    public function test_partial_month_is_completed_without_overwriting_existing_values(): void
    {
        $this->importOctober();
        $source = StoreManagerCommissionTarget::query()->where(['month' => '2026-10', 'store_key' => 'a-coruna'])->firstOrFail();
        StoreManagerCommissionTarget::query()->create([
            'month' => '2026-11',
            'zone' => $source->zone,
            'store_key' => $source->store_key,
            'store_name' => $source->store_name,
            'delivery_target' => 35,
            'purchase_target' => 18,
            'source_sha256' => $source->source_sha256,
            'source_label' => $source->source_label,
        ]);

        $rows = app(StoreManagerCommissionTargetService::class)->materializeForMonth('2026-11');

        $this->assertCount(34, $rows);
        $this->assertSame(35, (int) StoreManagerCommissionTarget::query()->where(['month' => '2026-11', 'store_key' => 'a-coruna'])->value('delivery_target'));
        $this->assertSame(33, StoreManagerCommissionTargetEvent::query()->where('month', '2026-11')->count());
    }

    public function test_definitive_or_pending_month_is_not_completed_or_changed(): void
    {
        $this->importOctober();
        $this->closure('2026-11', CommercialCommissionClosure::STATUS_DEFINITIVE);

        $service = app(StoreManagerCommissionTargetService::class);
        $this->assertSame([], $service->materializeForMonth('2026-11'));
        $this->assertDatabaseCount('store_manager_commission_target_events', 34);

        $this->closure('2026-12', CommercialCommissionClosure::STATUS_PENDING_APPROVAL);
        $service->materializeForMonth('2026-12');
        $this->assertSame(0, StoreManagerCommissionTarget::query()->where('month', '2026-12')->count());
    }

    public function test_pending_candidate_rejects_target_changes(): void
    {
        $this->importOctober();
        $service = app(StoreManagerCommissionTargetService::class);
        $service->materializeForMonth('2026-11');
        $this->closure('2026-11', CommercialCommissionClosure::STATUS_PENDING_APPROVAL);
        $admin = ReportUser::query()->create([
            'name' => 'Targets Admin',
            'email' => 'pending-admin@example.test',
            'password' => 'secret',
            'role' => ReportUser::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $values = collect($service->forMonth('2026-11'))->mapWithKeys(fn (array $target): array => [
            $target['store_key'] => [
                'delivery_target' => $target['target_deliveries'] + ($target['store_key'] === 'a-coruna' ? 1 : 0),
                'purchase_target' => $target['target_purchases'],
            ],
        ])->all();

        $this->expectException(ValidationException::class);
        $service->updateForMonth('2026-11', $values, $admin->id);
    }

    public function test_only_an_authorized_admin_can_edit_targets_from_coefficients(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-15 12:00:00'));
        $this->importOctober();
        $service = app(StoreManagerCommissionTargetService::class);
        $service->materializeForMonth('2026-11');
        $values = collect($service->forMonth('2026-11'))->mapWithKeys(fn (array $target): array => [
            $target['store_key'] => [
                'delivery_target' => $target['target_deliveries'],
                'purchase_target' => $target['target_purchases'],
            ],
        ])->all();
        $values['a-coruna']['delivery_target'] = 35;

        $admin = ReportUser::query()->create([
            'name' => 'Targets Admin',
            'email' => 'route-admin@example.test',
            'password' => 'secret',
            'role' => ReportUser::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $director = ReportUser::query()->create([
            'name' => 'Director',
            'email' => 'director@example.test',
            'password' => 'secret',
            'role' => ReportUser::ROLE_DIRECTOR,
            'is_active' => true,
        ]);

        $this->withSession($this->sessionPayload($director))
            ->put('/informes/configuracion-comisiones', [
                'month' => '2026-11',
                'targets' => $values,
                'store_manager_targets' => 1,
            ])
            ->assertForbidden();

        $this->withSession($this->sessionPayload($admin))
            ->put('/informes/configuracion-comisiones', [
                'month' => '2026-11',
                'targets' => $values,
                'store_manager_targets' => 1,
            ])
            ->assertRedirect('/informes/configuracion-comisiones?month=2026-11');

        $this->assertSame(35, (int) StoreManagerCommissionTarget::query()->where(['month' => '2026-11', 'store_key' => 'a-coruna'])->value('delivery_target'));
        $this->assertDatabaseHas('store_manager_commission_target_events', [
            'month' => '2026-11',
            'store_key' => 'a-coruna',
            'event_type' => StoreManagerCommissionTargetService::SOURCE_TYPE_ADMIN,
            'report_user_id' => $admin->id,
            'before_delivery_target' => 30,
            'after_delivery_target' => 35,
        ]);
    }

    private function importOctober(): void
    {
        $this->artisan('commissions:import-store-manager-targets', [
            'file' => self::FILE,
            '--month' => '2026-10',
            '--write' => true,
        ])->assertExitCode(0);
    }

    private function closure(string $month, string $status): CommercialCommissionClosure
    {
        return CommercialCommissionClosure::query()->create([
            'month' => $month,
            'closure_scope' => CommercialCommissionClosure::SCOPE_DELEGATIONS,
            'status' => $status,
            'component_statuses' => [],
            'snapshot_version' => 0,
            'formula_version' => 'fixture',
            'data_cutoff_at' => now(),
        ]);
    }

    private function sessionPayload(ReportUser $user): array
    {
        return [
            'informes_authenticated' => true,
            'report_user_id' => $user->id,
            'report_user_role' => $user->role,
            'report_user_email' => $user->email,
        ];
    }
}
