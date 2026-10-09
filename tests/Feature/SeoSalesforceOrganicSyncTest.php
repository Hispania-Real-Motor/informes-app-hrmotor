<?php

namespace Tests\Feature;

use App\Models\ReportSyncRun;
use App\Models\SeoSalesforceOrganicInterestDailyMetric;
use App\Services\Reports\ReportSyncRunService;
use App\Services\Salesforce\SalesforceInterestSyncService;
use App\Services\SeoAnalytics\SalesforceOrganicInterestProjectionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SeoSalesforceOrganicSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_projects_only_active_exact_organic_interests_by_functional_madrid_day_and_preserves_zeroes(): void
    {
        $source = $this->completedF2('2026-08-19 10:00:00');
        $this->interest('active-organic', '2026-08-16 22:30:00', 'Orgánico');
        $this->interest('deleted-organic', '2026-08-17 10:00:00', 'Orgánico', true);
        $this->interest('utm-only', '2026-08-17 11:00:00', 'Paid', false, 'organic', 'Other');
        $this->interest('source-only', '2026-08-17 12:00:00', null, false, null, 'Orgánico');
        DB::table('seo_salesforce_organic_daily_metrics')->insert([
            'data_date' => '2026-08-18', 'lead_count' => 999, 'source_timezone' => 'Europe/Madrid',
            'extracted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        Http::fake();

        $result = $this->service()->sync(2, CarbonImmutable::parse('2026-08-19 12:00:00 Europe/Madrid'));

        $this->assertSame(1, $result['stats']['queried_interests']);
        $this->assertSame(1, $result['stats']['organic_interests']);
        $this->assertSame($source->id, $result['stats']['source_interest_sync_run_id']);
        $this->assertDatabaseHas('seo_salesforce_organic_interest_daily_metrics', [
            'data_date' => '2026-08-17', 'interest_count' => 1, 'source_interest_sync_run_id' => $source->id,
        ]);
        $this->assertDatabaseHas('seo_salesforce_organic_interest_daily_metrics', ['data_date' => '2026-08-18', 'interest_count' => 0]);
        $this->assertDatabaseHas('seo_salesforce_organic_daily_metrics', ['data_date' => '2026-08-18', 'lead_count' => 999]);
        Http::assertNothingSent();
    }

    public function test_cutoff_uses_the_earlier_closed_day_and_does_not_certify_f2_cutoff_day(): void
    {
        $this->completedF2('2026-08-18 12:00:00');

        $result = $this->service()->sync(1, CarbonImmutable::parse('2026-08-20 12:00:00 Europe/Madrid'));

        $this->assertSame('2026-08-17', $result['period_end']->toDateString());
        $this->assertDatabaseHas('seo_salesforce_organic_interest_daily_metrics', ['data_date' => '2026-08-17']);
        $this->assertDatabaseMissing('seo_salesforce_organic_interest_daily_metrics', ['data_date' => '2026-08-18']);
    }

    public function test_madrid_boundaries_cover_dst_transitions_and_functional_date_is_authoritative(): void
    {
        $this->completedF2('2026-03-31 10:00:00');
        $this->interest('dst-march', '2026-03-28 23:30:00', 'Orgánico', false, null, null, '2025-01-01 00:00:00');
        $this->service()->sync(3, CarbonImmutable::parse('2026-03-31 12:00:00 Europe/Madrid'));
        $this->assertDatabaseHas('seo_salesforce_organic_interest_daily_metrics', ['data_date' => '2026-03-29', 'interest_count' => 1]);

        DB::table('report_sync_runs')->delete();
        $this->completedF2('2026-10-27 10:00:00');
        $this->interest('dst-october', '2026-10-24 22:30:00', 'Orgánico');
        $this->service()->sync(3, CarbonImmutable::parse('2026-10-27 12:00:00 Europe/Madrid'));
        $this->assertDatabaseHas('seo_salesforce_organic_interest_daily_metrics', ['data_date' => '2026-10-25', 'interest_count' => 1]);
    }

    public function test_projection_is_idempotent_and_supports_the_480_day_window(): void
    {
        $this->completedF2('2026-08-19 10:00:00');
        $this->interest('organic', '2026-08-17 10:00:00', 'Orgánico');
        $now = CarbonImmutable::parse('2026-08-19 12:00:00 Europe/Madrid');

        $this->service()->sync(480, $now);
        $this->service()->sync(480, $now);

        $this->assertSame(480, SeoSalesforceOrganicInterestDailyMetric::query()->count());
        $this->assertSame(2, ReportSyncRun::query()->where('dataset', SalesforceOrganicInterestProjectionService::DATASET)->where('status', 'completed')->count());
    }

    #[DataProvider('unstableF2Statuses')]
    public function test_it_fails_closed_when_the_latest_f2_is_missing_or_unstable(?string $status): void
    {
        if ($status !== null) {
            $this->f2($status, $status === 'completed' ? null : '2026-08-19 10:00:00');
        }

        $this->expectException(RuntimeException::class);
        $this->service()->sync(1, CarbonImmutable::parse('2026-08-19 12:00:00 Europe/Madrid'));
    }

    public static function unstableF2Statuses(): array
    {
        return [[null], ['running'], ['failed'], ['completed']];
    }

    public function test_source_change_after_read_fails_without_publishing_rows(): void
    {
        $this->completedF2('2026-08-19 10:00:00');
        $service = new class(app(ReportSyncRunService::class)) extends SalesforceOrganicInterestProjectionService
        {
            protected function afterInterestsRead(ReportSyncRun $run, array $stats): void
            {
                ReportSyncRun::query()->create([
                    'dataset' => SalesforceInterestSyncService::DATASET, 'source' => SalesforceInterestSyncService::SOURCE,
                    'status' => 'running', 'period_start_at' => now(), 'period_end_at' => now(),
                    'started_at' => now(), 'timezone' => 'UTC',
                ]);
            }
        };

        try {
            $service->sync(1, CarbonImmutable::parse('2026-08-19 12:00:00 Europe/Madrid'));
            $this->fail('Expected safe source-change failure.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('seo_salesforce_organic_interest_daily_metrics', 0);
            $this->assertDatabaseHas('report_sync_runs', [
                'dataset' => SalesforceOrganicInterestProjectionService::DATASET,
                'status' => 'failed', 'error_message' => 'SEO Interest projection failed safely.',
            ]);
        }
    }

    public function test_source_change_during_persistence_rolls_back_the_projection(): void
    {
        $this->completedF2('2026-08-19 10:00:00');
        $service = new class(app(ReportSyncRunService::class)) extends SalesforceOrganicInterestProjectionService
        {
            protected function afterProjectionPersisted(ReportSyncRun $run, array $stats): void
            {
                ReportSyncRun::query()->create([
                    'dataset' => SalesforceInterestSyncService::DATASET, 'source' => SalesforceInterestSyncService::SOURCE,
                    'status' => 'failed', 'period_start_at' => now(), 'period_end_at' => now(),
                    'started_at' => now(), 'completed_at' => now(), 'timezone' => 'UTC',
                    'error_message' => 'safe test failure',
                ]);
            }
        };

        try {
            $service->sync(1, CarbonImmutable::parse('2026-08-19 12:00:00 Europe/Madrid'));
            $this->fail('Expected safe source-change failure.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('seo_salesforce_organic_interest_daily_metrics', 0);
            $this->assertDatabaseHas('report_sync_runs', [
                'dataset' => SalesforceOrganicInterestProjectionService::DATASET, 'status' => 'failed',
            ]);
        }
    }

    private function service(): SalesforceOrganicInterestProjectionService
    {
        return app(SalesforceOrganicInterestProjectionService::class);
    }

    private function completedF2(string $cutoff): ReportSyncRun
    {
        return $this->f2('completed', $cutoff);
    }

    private function f2(string $status, ?string $cutoff): ReportSyncRun
    {
        return ReportSyncRun::query()->create([
            'dataset' => SalesforceInterestSyncService::DATASET, 'source' => SalesforceInterestSyncService::SOURCE,
            'status' => $status, 'period_start_at' => '2026-01-01 00:00:00',
            'period_end_at' => $cutoff ?? '2026-08-19 10:00:00',
            'source_cutoff_at' => $status === 'completed' ? $cutoff : null,
            'started_at' => '2026-08-19 09:00:00',
            'completed_at' => $status === 'running' ? null : '2026-08-19 10:01:00', 'timezone' => 'UTC',
        ]);
    }

    private function interest(
        string $suffix,
        string $functionalCreatedAt,
        ?string $medium,
        bool $deleted = false,
        ?string $utmMedium = null,
        ?string $source = null,
        ?string $salesforceCreatedAt = null,
    ): void {
        $id = str_pad(substr((string) preg_replace('/[^A-Za-z0-9]/', '', $suffix), 0, 15), 18, '0');
        DB::table('salesforce_interests')->insert([
            'salesforce_id' => $id, 'salesforce_created_at' => $salesforceCreatedAt ?? $functionalCreatedAt,
            'salesforce_last_modified_at' => $functionalCreatedAt, 'origin_created_at' => $salesforceCreatedAt,
            'functional_created_at' => $functionalCreatedAt, 'medium' => $medium,
            'utm_medium' => $utmMedium, 'source' => $source, 'is_deleted' => $deleted,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
