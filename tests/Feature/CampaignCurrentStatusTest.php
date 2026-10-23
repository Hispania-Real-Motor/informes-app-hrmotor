<?php

namespace Tests\Feature;

use App\Services\Campaigns\CampaignCurrentStatusService;
use App\Services\Operations\OperationalTaskCatalog;
use App\Services\Operations\OperationalTaskHealthService;
use App\Services\Operations\OperationalTaskService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CampaignCurrentStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'services.meta_ads.access_token' => 'synthetic-token', 'services.meta_ads.ad_account_ids' => ['act_123'],
            'services.google_ads.customer_ids' => [],
        ]);
    }

    private function fake(array $responses): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($responses);
    }

    private function reservation(): int
    {
        config(['campaign_current_status.enabled' => true]);
        $run = app(OperationalTaskService::class)->claim(CampaignCurrentStatusService::TASK, 'scheduler');
        DB::table('operational_task_runs')->where('id', $run->id)->update(['status' => 'running', 'started_at' => now()]);

        return $run->id;
    }

    private function row(string $platform = 'meta', string $account = '123', string $id = '9007199254740993'): array
    {
        return ['platform' => $platform, 'account_id' => $account, 'campaign_id' => $id, 'spend' => 1234.56, 'sales' => 2];
    }

    private function inventory(string $status = 'ACTIVE'): array
    {
        return ['data' => [['id' => '9007199254740993', 'account_id' => '123', 'status' => 'ACTIVE', 'effective_status' => $status]]];
    }

    public function test_ambiguous_identity_does_not_query_an_arbitrary_account(): void
    {
        $row = $this->row();
        $row['current_status'] = ['identity_ambiguous' => true];
        DB::enableQueryLog();
        $decorated = app(CampaignCurrentStatusService::class)->decorate([$row])[0];
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame([], $queries);
        $this->assertSame('unavailable', $decorated['current_status']['availability']);
        $this->assertSame('ambiguous_account_identity', $decorated['current_status']['reason']);
        $this->assertNull($decorated['current_status']['account_id']);
        $this->assertNull($decorated['current_status']['verified_at']);
    }

    public function test_disabled_by_default_and_direct_execution_requires_a_persistent_reservation(): void
    {
        $this->assertFalse(config('campaign_current_status.enabled'));
        $task = app(OperationalTaskCatalog::class)->get(CampaignCurrentStatusService::TASK);
        $this->assertFalse($task['enabled']);
        $this->assertFalse($task['manual_allowed']);
        $this->assertArrayNotHasKey(CampaignCurrentStatusService::TASK, app(OperationalTaskHealthService::class)->snapshot());
        $this->artisan('campaigns:sync-current-status')->assertFailed();
        config(['campaign_current_status.enabled' => true]);
        $this->artisan('campaigns:sync-current-status', ['--operational-run' => '999'])->assertFailed();
        Http::assertNothingSent();
        $this->assertDatabaseCount('report_sync_runs', 0);
        $this->assertDatabaseCount('campaign_current_statuses', 0);
    }

    public function test_enabled_task_runs_through_the_existing_operational_lifecycle(): void
    {
        config(['campaign_current_status.enabled' => true]);
        $this->fake(['graph.facebook.com/*' => Http::response($this->inventory('PAUSED'))]);
        $this->artisan('operations:run', ['task' => CampaignCurrentStatusService::TASK])->assertSuccessful();
        $this->assertDatabaseHas('operational_task_runs', ['task_key' => CampaignCurrentStatusService::TASK, 'status' => 'success']);
        $this->assertDatabaseHas('operational_task_states', ['task_key' => CampaignCurrentStatusService::TASK, 'active_run_id' => null]);
        $this->assertDatabaseHas('campaign_current_statuses', ['last_valid_raw_status' => 'PAUSED']);
    }

    public static function states(): array
    {
        return [
            ['ACTIVE', 'Activa'], ['PAUSED', 'Pausada'], ['DELETED', 'Eliminada'], ['ARCHIVED', 'Archivada'],
            ['IN_PROCESS', 'En proceso'], ['WITH_ISSUES', 'Con incidencias'], ['FUTURE_VALUE', 'Estado no disponible'],
            ['UNKNOWN', 'Estado no disponible'], [null, 'Estado no disponible'],
        ];
    }

    #[DataProvider('states')]
    public function test_explicit_meta_states_preserve_economic_values(?string $code, string $label): void
    {
        $run = $this->reservation();
        $this->fake(['graph.facebook.com/*' => Http::response($this->inventory($code ?? 'ACTIVE'))]);
        if ($code === null) {
            $inventory = $this->inventory();
            $inventory['data'][0]['effective_status'] = null;
            $this->fake(['graph.facebook.com/*' => Http::response($inventory)]);
        }
        $tables = ['campaign_platform_daily_metrics', 'campaign_attributions', 'campaign_lead_attributions', 'campaign_investment_closures', 'campaign_investment_closure_snapshots', 'campaign_investment_closure_events'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $stats = app(CampaignCurrentStatusService::class)->sync($run);
        $this->assertSame(0, $stats['failed']);
        $row = $this->row();
        $decorated = app(CampaignCurrentStatusService::class)->decorate([$row])[0];
        $this->assertSame($label, $decorated['current_status']['label']);
        $this->assertSame($row, array_diff_key($decorated, ['current_status' => true]));
        $this->assertSame('9007199254740993', $decorated['current_status']['campaign_id']);
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson(), $table);
        }
    }

    public function test_upsert_idempotence_freshness_boundary_and_failure_preserve_last_valid_state(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-24 12:00:00', 'UTC'));
        $run = $this->reservation();
        $this->fake(['graph.facebook.com/*' => Http::response($this->inventory())]);
        $service = app(CampaignCurrentStatusService::class);
        $service->sync($run);
        $service->sync($run);
        $this->assertDatabaseCount('campaign_current_statuses', 1);
        $verified = DB::table('campaign_current_statuses')->value('last_verified_at');
        $this->travel(86399)->seconds();
        $this->assertSame('fresh', $service->decorate([$this->row()])[0]['current_status']['availability']);
        $this->travel(1)->seconds();
        $this->assertSame('stale', $service->decorate([$this->row()])[0]['current_status']['availability']);
        $this->fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'secret-must-not-persist']], 401)]);
        $this->assertSame(1, $service->sync($run)['failed']);
        $this->assertSame($verified, DB::table('campaign_current_statuses')->value('last_verified_at'));
        $this->assertDatabaseHas('campaign_current_statuses', ['last_valid_raw_status' => 'ACTIVE']);
        $this->assertStringNotContainsString('secret-must-not-persist', DB::table('report_sync_runs')->get()->toJson());
        $this->travelBack();
    }

    public function test_unknown_new_observation_retains_last_valid_but_is_not_presented_as_current(): void
    {
        $run = $this->reservation();
        $service = app(CampaignCurrentStatusService::class);
        $this->fake(['graph.facebook.com/*' => Http::response($this->inventory())]);
        $service->sync($run);
        $verified = DB::table('campaign_current_statuses')->value('last_verified_at');
        $this->fake(['graph.facebook.com/*' => Http::response($this->inventory('NEW_STATE'))]);
        $service->sync($run);
        $this->assertDatabaseHas('campaign_current_statuses', ['raw_status' => 'NEW_STATE', 'last_valid_raw_status' => 'ACTIVE', 'last_verified_at' => $verified]);
        $this->assertSame('unavailable', $service->decorate([$this->row()])[0]['current_status']['availability']);
    }

    public function test_complete_pagination_is_required_and_partial_accounts_do_not_overwrite_data(): void
    {
        $run = $this->reservation();
        $this->fake(['graph.facebook.com/*' => Http::sequence()
            ->push($this->inventory() + ['paging' => ['next' => 'not-followed', 'cursors' => ['after' => 'cursor']]])
            ->push(['data' => [['id' => '2', 'account_id' => '123', 'status' => 'PAUSED', 'effective_status' => 'PAUSED']]])]);
        $service = app(CampaignCurrentStatusService::class);
        $this->assertSame(2, $service->sync($run)['campaigns']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/campaigns')
            && json_decode($request['effective_status'], true) === ['ACTIVE', 'PAUSED', 'DELETED', 'ARCHIVED', 'IN_PROCESS', 'WITH_ISSUES']
            && ! isset($request['filtering']) && ! isset($request['time_range']));
        $before = DB::table('campaign_current_statuses')->orderBy('id')->get()->toJson();
        $this->fake(['graph.facebook.com/*' => Http::response($this->inventory('PAUSED') + ['paging' => ['next' => 'not-followed']])]);
        $this->assertSame(1, $service->sync($run)['failed']);
        $this->assertSame($before, DB::table('campaign_current_statuses')->orderBy('id')->get()->toJson());
        $this->assertSame('stale', $service->decorate([$this->row()])[0]['current_status']['availability']);
    }

    public function test_missing_campaign_is_not_deleted_or_kept_fresh_and_accounts_are_isolated(): void
    {
        config(['services.meta_ads.ad_account_ids' => ['act_123', 'act_456']]);
        $run = $this->reservation();
        $this->fake([
            '*act_123/campaigns*' => Http::response($this->inventory()),
            '*act_456/campaigns*' => Http::response(['data' => [['id' => '9007199254740993', 'account_id' => '456', 'status' => 'PAUSED', 'effective_status' => 'PAUSED']]]),
        ]);
        $service = app(CampaignCurrentStatusService::class);
        $this->assertSame(2, $service->sync($run)['campaigns']);
        $this->assertDatabaseCount('campaign_current_statuses', 2);
        $rows = $service->decorate([$this->row(), $this->row('meta', '456')]);
        $this->assertSame(['Activa', 'Pausada'], array_column(array_column($rows, 'current_status'), 'label'));
        $this->fake(['*act_123/campaigns*' => Http::response(['data' => []]), '*act_456/campaigns*' => Http::response([], 403)]);
        $this->assertSame(1, $service->sync($run)['failed']);
        $this->assertDatabaseCount('campaign_current_statuses', 2);
        $this->assertSame('stale', $service->decorate([$this->row()])[0]['current_status']['availability']);
    }

    public function test_google_attribute_query_is_paged_without_metrics_and_removed_is_supported(): void
    {
        config(['services.google_ads.developer_token' => 'synthetic', 'services.google_ads.client_id' => 'synthetic',
            'services.google_ads.client_secret' => 'synthetic', 'services.google_ads.refresh_token' => 'synthetic',
            'services.google_ads.customer_ids' => ['123-456-7890'], 'services.meta_ads.ad_account_ids' => []]);
        $this->fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'synthetic']),
            'googleads.googleapis.com/*' => Http::sequence()
                ->push(['results' => [['customer' => ['id' => '1234567890'], 'campaign' => ['id' => '1', 'status' => 'ENABLED']]], 'nextPageToken' => 'second'])
                ->push(['results' => [['customer' => ['id' => '1234567890'], 'campaign' => ['id' => '2', 'status' => 'REMOVED']]]]),
        ]);
        $service = app(CampaignCurrentStatusService::class);
        $this->assertSame(2, $service->sync($this->reservation())['campaigns']);
        $this->assertSame('Eliminada', $service->decorate([$this->row('google_ads', '1234567890', '2')])[0]['current_status']['label']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'googleAds:search') && ($request['pageToken'] ?? null) === 'second'
            && ! str_contains($request['query'], 'metrics.') && ! str_contains($request['query'], 'segments.date'));
    }

    public function test_reservations_and_lock_prevent_overlap_without_provider_requests(): void
    {
        $run = $this->reservation();
        $duplicate = app(OperationalTaskService::class)->claim(CampaignCurrentStatusService::TASK, 'scheduler');
        $this->assertSame('skipped', $duplicate->status);
        $lock = Cache::lock('campaign-current-status-sync', 14400);
        $this->assertTrue($lock->get());
        try {
            app(CampaignCurrentStatusService::class)->sync($run);
            $this->fail('Overlap must be rejected.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Sincronización ya en curso.', $error->getMessage());
        } finally {
            $lock->release();
        }
        Http::assertNothingSent();
    }

    public function test_unverifiable_and_salesforce_identities_are_neutral_without_remote_calls(): void
    {
        $service = app(CampaignCurrentStatusService::class);
        $rows = $service->decorate([
            ['platform' => 'salesforce', 'campaign_id' => '123'],
            ['platform' => 'meta', 'campaign_id' => '123'],
            ['platform' => 'google_ads', 'account_id' => '123', 'campaign_id' => 9007199254740993],
        ]);
        foreach ($rows as $row) {
            $this->assertSame('unavailable', $row['current_status']['availability']);
        }
        Http::assertNothingSent();
    }

    public function test_disabled_scheduler_and_manual_entry_points_do_not_claim_or_sync(): void
    {
        $event = collect(app(Schedule::class)->events())->firstWhere('description', CampaignCurrentStatusService::TASK);
        $this->assertNotNull($event);
        $this->assertFalse($event->filtersPass($this->app));
        $this->post('/informes/alertas-operativas/ejecutar', ['task_key' => CampaignCurrentStatusService::TASK, 'confirmed' => '1'])->assertForbidden();
        $this->assertDatabaseCount('operational_task_runs', 0);
        Http::assertNothingSent();
    }

    public function test_disabling_after_queue_reservation_prevents_execution(): void
    {
        config(['campaign_current_status.enabled' => true]);
        $tasks = app(OperationalTaskService::class);
        $run = $tasks->claim(CampaignCurrentStatusService::TASK, 'scheduler');
        config(['campaign_current_status.enabled' => false]);
        $tasks->execute($run->id);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertDatabaseCount('campaign_current_statuses', 0);
        Http::assertNothingSent();
    }

    public function test_state_lookup_is_one_batch_and_future_timestamps_are_not_current(): void
    {
        $service = app(CampaignCurrentStatusService::class);
        $run = $this->reservation();
        $this->fake(['graph.facebook.com/*' => Http::response($this->inventory())]);
        $service->sync($run);
        DB::enableQueryLog();
        $service->decorate(array_fill(0, 100, $this->row()));
        $this->assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
        DB::table('campaign_current_statuses')->update(['last_verified_at' => now('UTC')->addMinute()]);
        $this->assertSame('unavailable', $service->decorate([$this->row()])[0]['current_status']['availability']);
    }

    public function test_google_status_mapping_and_incomplete_pagination_preserve_previous_inventory(): void
    {
        config(['services.google_ads.developer_token' => 'synthetic', 'services.google_ads.client_id' => 'synthetic',
            'services.google_ads.client_secret' => 'synthetic', 'services.google_ads.refresh_token' => 'synthetic',
            'services.google_ads.customer_ids' => ['123'], 'services.meta_ads.ad_account_ids' => []]);
        $items = [];
        foreach (['ENABLED', 'PAUSED', 'REMOVED', 'UNKNOWN', 'UNSPECIFIED', 'NEW_VALUE'] as $index => $status) {
            $items[] = ['customer' => ['id' => '123'], 'campaign' => ['id' => (string) ($index + 1), 'status' => $status]];
        }
        $this->fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'synthetic']),
            'googleads.googleapis.com/*' => Http::response(['results' => $items])]);
        $service = app(CampaignCurrentStatusService::class);
        $run = $this->reservation();
        $this->assertSame(6, $service->sync($run)['campaigns']);
        $rows = $service->decorate(array_map(fn ($id) => $this->row('google_ads', '123', (string) $id), range(1, 6)));
        $this->assertSame(['Activa', 'Pausada', 'Eliminada', 'Estado no disponible', 'Estado no disponible', 'Estado no disponible'], array_column(array_column($rows, 'current_status'), 'label'));
        $before = DB::table('campaign_current_statuses')->orderBy('id')->get()->toJson();
        $this->fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'synthetic']),
            'googleads.googleapis.com/*' => Http::response(['results' => $items, 'nextPageToken' => 'repeated'])]);
        $this->assertSame(1, $service->sync($run)['failed']);
        $this->assertSame($before, DB::table('campaign_current_statuses')->orderBy('id')->get()->toJson());
    }

    public function test_invalid_account_response_and_malformed_state_fail_without_inventory_writes(): void
    {
        $run = $this->reservation();
        $service = app(CampaignCurrentStatusService::class);
        foreach ([['account_id' => '456'], ['effective_status' => '<script>'], ['id' => 12345]] as $invalid) {
            $inventory = $this->inventory();
            $inventory['data'][0] = array_merge($inventory['data'][0], $invalid);
            $this->fake(['graph.facebook.com/*' => Http::response($inventory)]);
            $this->assertSame(1, $service->sync($run)['failed']);
            $this->assertDatabaseCount('campaign_current_statuses', 0);
        }
        $inventory = $this->inventory();
        unset($inventory['data'][0]['effective_status']);
        $this->fake(['graph.facebook.com/*' => Http::response($inventory)]);
        $this->assertSame(1, $service->sync($run)['failed']);
        $this->assertDatabaseCount('campaign_current_statuses', 0);
    }

    public function test_unique_identity_is_enforced_by_database(): void
    {
        $run = $this->reservation();
        $this->fake(['graph.facebook.com/*' => Http::response($this->inventory())]);
        app(CampaignCurrentStatusService::class)->sync($run);
        $existing = (array) DB::table('campaign_current_statuses')->first();
        unset($existing['id']);
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('campaign_current_statuses')->insert($existing);
    }

    public function test_missing_or_failed_audit_cannot_confirm_freshness_even_with_a_recent_timestamp(): void
    {
        $run = $this->reservation();
        $this->fake(['graph.facebook.com/*' => Http::response($this->inventory())]);
        $service = app(CampaignCurrentStatusService::class);
        $service->sync($run);
        DB::table('report_sync_runs')->where('dataset', CampaignCurrentStatusService::DATASET)->update(['status' => 'failed']);
        $this->assertSame('stale', $service->decorate([$this->row()])[0]['current_status']['availability']);
        DB::table('report_sync_runs')->where('dataset', CampaignCurrentStatusService::DATASET)->delete();
        $this->assertNull(DB::table('campaign_current_statuses')->value('report_sync_run_id'));
        $this->assertSame('stale', $service->decorate([$this->row()])[0]['current_status']['availability']);
    }
}
