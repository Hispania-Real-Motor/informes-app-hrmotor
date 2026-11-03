<?php

namespace Tests\Feature;

use App\Models\ReportSyncRun;
use App\Models\ReportUser;
use App\Models\SalesforceInterestActivityRun;
use App\Services\Reports\ReportSyncRunService;
use App\Services\Salesforce\InterestGenerationStore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->session($admin)->get('/informes/leads/provisional/september-october-2026')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Vista provisional no certificada');
        $this->session($viewer)->get('/informes/leads/provisional/september-october-2026')->assertForbidden();
        $this->session($admin)->getJson('/informes/leads/data/summary')
            ->assertStatus(503)->assertJsonPath('error', 'interest_dataset_unavailable');
    }

    public function test_provisional_preview_is_disabled_by_default(): void
    {
        config()->set('services.informes_auth.enabled', true);
        $this->seedProvisionalGeneration();

        $this->session($this->user(ReportUser::ROLE_ADMIN))
            ->get('/informes/leads/provisional/september-october-2026')->assertNotFound();
    }

    private function seedProvisionalGeneration(): void
    {
        $store = app(InterestGenerationStore::class);
        $cutoff = CarbonImmutable::parse('2026-10-28 15:57:00', 'UTC');
        $generation = $store->begin($cutoff, true);
        $run = app(ReportSyncRunService::class)->start('salesforce_interests', 'salesforce', $cutoff, $cutoff, 'UTC');
        $store->bindRun($generation, $run);
        $store->persist($generation, [[
            'salesforce_id' => 'a01000000000000001', 'migration_origin_lead_id' => null, 'is_deleted' => false,
            'salesforce_created_at' => '2026-09-15 12:00:00', 'salesforce_last_modified_at' => '2026-09-15 12:00:00',
            'functional_created_at' => '2026-09-15 12:00:00', 'raw_payload' => '{"synthetic":true}',
        ]]);
        $store->finishF2($generation);
        app(ReportSyncRunService::class)->complete(ReportSyncRun::findOrFail($run->id), $cutoff);
        $f5 = SalesforceInterestActivityRun::create([
            'run_identifier' => (string) Str::uuid(), 'reason' => 'Synthetic provisional preview seed', 'status' => 'completed',
            'source_interest_sync_run_id' => $run->id, 'source_interest_cutoff_at' => $cutoff, 'source_cutoff_at' => $cutoff,
            'started_at' => now(), 'completed_at' => now(),
        ]);
        $store->finishF5($generation, $f5);
    }

    private function user(string $role): ReportUser
    {
        return ReportUser::create([
            'name' => 'Synthetic', 'email' => uniqid('', true).'@example.test', 'password' => 'synthetic-password',
            'role' => $role, 'is_active' => true,
        ]);
    }

    private function session(ReportUser $user): static
    {
        return $this->withSession([
            'informes_authenticated' => true, 'report_user_id' => $user->id,
            'report_user_role' => $user->role, 'report_user_email' => $user->email,
        ]);
    }
}
