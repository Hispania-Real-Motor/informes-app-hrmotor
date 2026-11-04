<?php

namespace Tests\Feature;

use App\Models\ReportSyncRun;
use App\Models\ReportUser;
use App\Models\SalesforceInterestActivityRun;
use App\Services\Reports\ReportSyncRunService;
use App\Services\Reports\Leads\InterestProvisionalPreviewService;
use App\Services\Salesforce\InterestGenerationStore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InterestProvisionalPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_provisional_preview_is_admin_only_and_does_not_make_official_dataset_available(): void
    {
        config()->set('services.informes_auth.enabled', true);
        config()->set('salesforce.interest_provisional_preview_enabled', true);
        $this->seedProvisionalGeneration();
        $admin = $this->user(ReportUser::ROLE_ADMIN);
        $viewer = $this->user(ReportUser::ROLE_VIEWER);

        $this->asReportUser($admin)->get('/informes/leads/provisional/september-october-2026')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Vista provisional no certificada');
        $this->asReportUser($viewer)->get('/informes/leads/provisional/september-october-2026')->assertForbidden();
        $this->asReportUser($admin)->getJson('/informes/leads/data/summary')
            ->assertStatus(503)->assertJsonPath('error', 'interest_dataset_unavailable');
    }

    public function test_provisional_preview_is_disabled_by_default(): void
    {
        config()->set('services.informes_auth.enabled', true);
        $this->seedProvisionalGeneration();

        $this->asReportUser($this->user(ReportUser::ROLE_ADMIN))
            ->get('/informes/leads/provisional/september-october-2026')->assertNotFound();
    }

    public function test_provisional_preview_aggregates_months_without_selecting_versions(): void
    {
        config()->set('salesforce.interest_provisional_preview_enabled', true);
        $this->seedProvisionalGeneration([
            $this->interest('a01000000000000001', '2026-08-31 22:00:00', false),
            $this->interest('a01000000000000002', '2026-09-30 21:59:59', true),
            $this->interest('a01000000000000003', '2026-09-30 22:00:00', false),
            $this->interest('a01000000000000004', '2026-10-31 22:59:59', true),
            $this->interest('a01000000000000005', '2026-10-31 23:00:00', false),
        ]);

        DB::enableQueryLog();
        $preview = app(InterestProvisionalPreviewService::class)->septemberOctober2026();
        $queries = collect(DB::getQueryLog())->pluck('query')->filter(
            fn (string $query): bool => str_contains($query, 'salesforce_interest_members')
        );

        $this->assertSame([
            ['period' => '2026-09', 'rows' => 2, 'deleted' => 1],
            ['period' => '2026-10', 'rows' => 2, 'deleted' => 1],
        ], $preview['periods']);
        $this->assertNotEmpty($queries);
        $queries->each(function (string $query): void {
            $this->assertStringNotContainsString('version.*', $query);
            $this->assertStringContainsString('interest_count', $query);
            $this->assertStringContainsString('deleted_count', $query);
        });
    }

    public function test_provisional_preview_returns_zeroes_for_an_empty_month(): void
    {
        config()->set('salesforce.interest_provisional_preview_enabled', true);
        $this->seedProvisionalGeneration([
            $this->interest('a01000000000000001', '2026-09-15 12:00:00'),
        ]);

        $preview = app(InterestProvisionalPreviewService::class)->septemberOctober2026();

        $this->assertSame(['period' => '2026-10', 'rows' => 0, 'deleted' => 0], $preview['periods'][1]);
    }

    /** @param list<array<string, mixed>>|null $interests */
    private function seedProvisionalGeneration(?array $interests = null): void
    {
        $store = app(InterestGenerationStore::class);
        $cutoff = CarbonImmutable::parse('2026-10-28 15:57:00', 'UTC');
        $generation = $store->begin($cutoff, true);
        $run = app(ReportSyncRunService::class)->start('salesforce_interests', 'salesforce', $cutoff, $cutoff, 'UTC');
        $store->bindRun($generation, $run);
        $store->persist($generation, $interests ?? [$this->interest('a01000000000000001', '2026-09-15 12:00:00')]);
        $store->finishF2($generation);
        app(ReportSyncRunService::class)->complete(ReportSyncRun::findOrFail($run->id), $cutoff);
        $f5 = SalesforceInterestActivityRun::create([
            'run_identifier' => (string) Str::uuid(), 'reason' => 'Synthetic provisional preview seed', 'status' => 'completed',
            'source_interest_sync_run_id' => $run->id, 'source_interest_cutoff_at' => $cutoff, 'source_cutoff_at' => $cutoff,
            'started_at' => now(), 'completed_at' => now(),
        ]);
        $store->finishF5($generation, $f5);
    }

    /** @return array<string, mixed> */
    private function interest(string $salesforceId, string $functionalCreatedAt, bool $isDeleted = false): array
    {
        return [
            'salesforce_id' => $salesforceId, 'migration_origin_lead_id' => null, 'is_deleted' => $isDeleted,
            'salesforce_created_at' => $functionalCreatedAt, 'salesforce_last_modified_at' => $functionalCreatedAt,
            'functional_created_at' => $functionalCreatedAt, 'raw_payload' => '{"synthetic":true}',
        ];
    }

    private function user(string $role): ReportUser
    {
        return ReportUser::create([
            'name' => 'Synthetic', 'email' => uniqid('', true).'@example.test', 'password' => 'synthetic-password',
            'role' => $role, 'is_active' => true,
        ]);
    }

    private function asReportUser(ReportUser $user): static
    {
        return $this->withSession([
            'informes_authenticated' => true, 'report_user_id' => $user->id,
            'report_user_role' => $user->role, 'report_user_email' => $user->email,
        ]);
    }
}
