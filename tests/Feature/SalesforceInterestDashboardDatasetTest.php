<?php

namespace Tests\Feature;

use App\Models\ReportSyncRun;
use App\Models\SalesforceInterest;
use App\Models\SalesforceInterestActivity;
use App\Models\SalesforceInterestActivityRun;
use App\Models\SalesforceLead;
use App\Models\SalesforceUser;
use App\Services\Reports\Leads\LeadClassificationResolver;
use App\Services\Reports\Leads\LeadDashboardAiInsightsService;
use App\Services\Reports\Leads\LeadDelegationNormalizer;
use App\Services\Reports\Leads\LeadPortalResolver;
use App\Services\Reports\Leads\LeadRecordTypeNormalizer;
use App\Services\Reports\Leads\SalesforceInterestDashboardDatasetService;
use App\Services\Salesforce\SalesforceInterestActivitySyncService;
use App\Services\Salesforce\SalesforceInterestReportingPipelineService;
use App\Services\Salesforce\SalesforceInterestSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class SalesforceInterestDashboardDatasetTest extends TestCase
{
    use RefreshDatabase;

    public function test_interest_is_the_fact_functional_date_and_direct_f5_activity_drive_kpis(): void
    {
        CarbonImmutable::setTestNow('2026-10-08 12:00:00');
        [$f2, $f5] = $this->alignedRuns();
        SalesforceUser::query()->create([
            'salesforce_id' => '005000000000000001',
            'name' => 'Comercial Interest',
            'profile_name' => 'Compra/Venta',
            'user_delegation' => 'Alcobendas',
            'is_active' => true,
        ]);
        $interest = $this->interest([
            'salesforce_id' => 'a01000000000000001',
            'salesforce_created_at' => '2026-09-01 08:00:00',
            'origin_created_at' => '2026-10-07 08:00:00',
            'status' => 'Potencial',
            'type' => 'Venta con cambio',
            'source' => 'Web orgánica',
            'medium' => 'Web',
            'channel' => 'Formulario',
            'origin_delegation' => 'Alcobendas',
            'owner_salesforce_id' => '005000000000000001',
            'owner_name' => 'Comercial Interest',
        ]);
        SalesforceLead::query()->create([
            'salesforce_id' => '00Q000000000000001',
            'created_date' => '2026-10-07 08:00:00',
            'status' => 'Convertido',
            'is_deleted' => false,
        ]);
        SalesforceInterestActivity::query()->create([
            'activity_run_id' => $f5->id,
            'activity_kind' => 'Task',
            'activity_salesforce_id' => '00T000000000000001',
            'interest_salesforce_id' => $interest->salesforce_id,
            'who_salesforce_id' => '00Q999999999999999',
            'relationship_status' => 'resolved',
            'activity_is_deleted' => false,
            'interest_is_deleted' => false,
            'activity_date' => '2026-10-07',
            'salesforce_created_at' => '2026-10-07 10:00:00',
        ]);

        $summary = app(SalesforceInterestDashboardDatasetService::class)->summary($this->request());

        $this->assertSame(1, $summary['kpis']['leads_totales']);
        $this->assertSame(1, $summary['kpis']['potenciales']);
        $this->assertSame(0, $summary['kpis']['potenciales_sin_trabajar']);
        $this->assertSame(1, $summary['kpis']['formularios']);
        $this->assertSame('salesforce_interests', $summary['functional_source']);
        $this->assertSame($f2->id, $summary['dataset_sync_run_id']);
    }

    public function test_deleted_unresolved_and_deleted_activities_do_not_enter_valid_activity(): void
    {
        [, $f5] = $this->alignedRuns();
        $active = $this->interest(['salesforce_id' => 'a01000000000000002', 'status' => 'Potencial']);
        $this->interest(['salesforce_id' => 'a01000000000000003', 'status' => 'Convertido', 'is_deleted' => true]);
        foreach ([
            ['relationship_status' => 'interest_not_in_source', 'activity_is_deleted' => false],
            ['relationship_status' => 'resolved', 'activity_is_deleted' => true],
        ] as $index => $state) {
            SalesforceInterestActivity::query()->create(array_merge([
                'activity_run_id' => $f5->id,
                'activity_kind' => 'Event',
                'activity_salesforce_id' => '00U'.str_pad((string) $index, 15, '0', STR_PAD_LEFT),
                'interest_salesforce_id' => $active->salesforce_id,
                'interest_is_deleted' => false,
                'start_datetime' => '2026-10-07 11:00:00',
            ], $state));
        }

        $summary = app(SalesforceInterestDashboardDatasetService::class)->summary($this->request());

        $this->assertSame(1, $summary['kpis']['leads_totales']);
        $this->assertSame(1, $summary['kpis']['potenciales_sin_trabajar']);
    }

    public function test_madrid_business_day_and_month_boundaries_are_converted_to_utc(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08 12:00:00', 'Europe/Madrid'));
        $this->alignedRuns();
        $this->interest([
            'salesforce_id' => 'a01000000000001001',
            'functional_created_at' => '2026-10-07 22:30:00',
            'origin_created_at' => '2026-10-07 22:30:00',
            'status' => 'Potencial',
        ]);
        $this->interest([
            'salesforce_id' => 'a01000000000001002',
            'functional_created_at' => '2026-09-30 22:30:00',
            'origin_created_at' => '2026-09-30 22:30:00',
            'status' => 'Potencial',
        ]);

        $service = app(SalesforceInterestDashboardDatasetService::class);
        $day = $service->summary($this->customRequest('2026-10-08', '2026-10-08'));
        $month = $service->summary($this->customRequest('2026-10-01', '2026-10-31'));

        $this->assertSame(1, $day['kpis']['leads_totales']);
        $this->assertSame(2, $month['kpis']['leads_totales']);
        $this->assertSame('2026-10-08T00:00:00+02:00', $day['dataset_period_start']);
        $this->assertStringContainsString('+00:00', $day['dataset_cutoff_at']);
    }

    public function test_executive_uses_f2_without_f5_and_never_queries_activity_tables(): void
    {
        $f2 = $this->completedF2();
        $this->interest([
            'salesforce_id' => 'a01000000000001003',
            'functional_created_at' => '2026-10-07 22:30:00',
            'origin_created_at' => '2026-10-07 22:30:00',
        ]);
        $f5Queries = 0;
        DB::listen(function ($query) use (&$f5Queries): void {
            if (str_contains($query->sql, 'salesforce_interest_activit')) {
                $f5Queries++;
            }
        });

        $sample = app(SalesforceInterestDashboardDatasetService::class)->executiveLeadTotal(
            CarbonImmutable::parse('2026-10-08 00:00:00', 'Europe/Madrid'),
            CarbonImmutable::parse('2026-10-08 23:59:59', 'Europe/Madrid'),
        );

        $this->assertSame(1, $sample['value']);
        $this->assertSame($f2->id, $sample['source_cutoff']['sync_run_id']);
        $this->assertNull($sample['source_cutoff']['activities_synced_at']);
        $this->assertSame(0, $f5Queries);
    }

    public function test_operational_dashboard_still_requires_f5(): void
    {
        $this->completedF2();

        $this->expectException(RuntimeException::class);
        app(SalesforceInterestDashboardDatasetService::class)->summary($this->request());
    }

    public function test_executive_fails_safe_when_f2_changes_during_count(): void
    {
        $this->completedF2();
        $service = new class(app(LeadDelegationNormalizer::class), app(LeadRecordTypeNormalizer::class), app(LeadDashboardAiInsightsService::class), app(LeadPortalResolver::class), app(LeadClassificationResolver::class)) extends SalesforceInterestDashboardDatasetService
        {
            protected function beforeExecutiveSourceValidation(): void
            {
                ReportSyncRun::query()->create([
                    'dataset' => SalesforceInterestSyncService::DATASET,
                    'source' => SalesforceInterestSyncService::SOURCE,
                    'status' => 'running',
                    'started_at' => now('UTC'),
                    'timezone' => 'UTC',
                ]);
            }
        };

        $this->expectException(RuntimeException::class);
        $service->executiveLeadTotal(
            CarbonImmutable::parse('2026-10-08 00:00:00', 'Europe/Madrid'),
            CarbonImmutable::parse('2026-10-08 23:59:59', 'Europe/Madrid'),
        );
    }

    public function test_interest_audits_are_interest_centric_and_explain_exclusions(): void
    {
        $this->alignedRuns();
        $active = $this->interest([
            'salesforce_id' => 'a01000000000001004',
            'status' => 'Convertido',
            'source' => 'Fuente A',
        ]);
        $deleted = $this->interest([
            'salesforce_id' => 'a01000000000001005',
            'status' => 'Convertido',
            'source' => 'Fuente A',
            'is_deleted' => true,
            'salesforce_deleted_at' => '2026-10-07 12:00:00',
            'deletion_detection_source' => 'query_all_deleted',
        ]);

        $service = app(SalesforceInterestDashboardDatasetService::class);
        $audit = $service->kpiAudit($this->request('convertidos'));
        $row = $audit['items'][0];
        $this->assertSame($active->salesforce_id, $row['interest_id']);
        foreach (['functional_created_at', 'salesforce_created_at', 'type_raw', 'type_normalized', 'source', 'original_source', 'medium', 'channel', 'owner_id', 'effective_commercial_id', 'total_direct_activities', 'source_interest_sync_run_id', 'source_activity_run_id'] as $field) {
            $this->assertArrayHasKey($field, $row);
        }
        foreach (['lead_id', 'lead_name', 'phone', 'mobile_phone', 'email', 'converted_opportunity_id', 'campaign_acquired'] as $field) {
            $this->assertArrayNotHasKey($field, $row);
        }

        $reconciliation = collect($service->reconciliationAudit($this->request()))->keyBy('interest_id');
        $this->assertTrue($reconciliation[$active->salesforce_id]['included_in_active_dataset']);
        $this->assertFalse($reconciliation[$deleted->salesforce_id]['included_in_active_dataset']);
        $this->assertContains('deleted', $reconciliation[$deleted->salesforce_id]['exclusion_reasons']);

        $filtered = collect($service->reconciliationAudit($this->customRequest('2026-10-01', '2026-10-08', ['portal' => 'Otra fuente'])))->keyBy('interest_id');
        $this->assertFalse($filtered[$active->salesforce_id]['included_in_active_dataset']);
        $this->assertContains('source', $filtered[$active->salesforce_id]['exclusion_reasons']);
    }

    public function test_unaligned_f2_and_f5_fail_safe_and_audit_excludes_pii(): void
    {
        $this->alignedRuns();
        $this->interest(['salesforce_id' => 'a01000000000000004', 'status' => 'Convertido']);
        $audit = app(SalesforceInterestDashboardDatasetService::class)->kpiAudit($this->request('leads_totales'));
        $encoded = json_encode($audit, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('phone', $encoded);
        $this->assertStringNotContainsString('email', $encoded);
        $this->assertArrayHasKey('interest_id', $audit['items'][0]);

        ReportSyncRun::query()->create([
            'dataset' => SalesforceInterestSyncService::DATASET,
            'source' => SalesforceInterestSyncService::SOURCE,
            'status' => 'running',
            'started_at' => now('UTC'),
            'timezone' => 'UTC',
        ]);

        $this->expectException(RuntimeException::class);
        app(SalesforceInterestDashboardDatasetService::class)->summary($this->request());
    }

    public function test_campaign_attribution_remains_wired_to_legacy_lead_dataset(): void
    {
        $source = file_get_contents(app_path('Services/Campaigns/CampaignAttributionBuilderService.php'));
        $this->assertStringContainsString('SalesforceLeadDashboardDatasetService', $source);
        $this->assertStringNotContainsString('SalesforceInterestDashboardDatasetService', $source);
    }

    public function test_pipeline_serializes_f2_then_f5_and_rejects_overlap(): void
    {
        [$f2, $f5] = $this->alignedRuns();
        $service = new class(app(SalesforceInterestSyncService::class), app(SalesforceInterestActivitySyncService::class), $f2, $f5) extends SalesforceInterestReportingPipelineService
        {
            public array $phases = [];

            public function __construct(
                SalesforceInterestSyncService $interests,
                SalesforceInterestActivitySyncService $activities,
                private ReportSyncRun $f2,
                private SalesforceInterestActivityRun $f5,
            ) {
                parent::__construct($interests, $activities);
            }

            protected function syncInterests(): ReportSyncRun
            {
                $this->phases[] = 'f2';

                return $this->f2;
            }

            protected function syncActivities(): SalesforceInterestActivityRun
            {
                $this->phases[] = 'f5';

                return $this->f5;
            }
        };

        $result = $service->run();
        $this->assertSame(['f2', 'f5'], $service->phases);
        $this->assertSame($f2->id, $result['interest_run']->id);
        $this->assertSame($f5->id, $result['activity_run']->id);

        $lock = Cache::lock(SalesforceInterestReportingPipelineService::LOCK_KEY, 60);
        $this->assertTrue($lock->get());
        try {
            $this->expectException(RuntimeException::class);
            $service->run();
        } finally {
            $lock->release();
        }
    }

    public function test_interest_and_activity_queries_are_bounded_by_chunks_not_rows(): void
    {
        $this->alignedRuns();
        foreach (range(1, 1001) as $sequence) {
            $this->interest([
                'salesforce_id' => 'a01'.str_pad((string) $sequence, 15, '0', STR_PAD_LEFT),
                'status' => 'Potencial',
            ]);
        }

        $sourceQueries = 0;
        $activityQueries = 0;
        DB::listen(function ($query) use (&$sourceQueries, &$activityQueries): void {
            $sourceQueries += str_contains($query->sql, 'from "salesforce_interests"') ? 1 : 0;
            $activityQueries += str_contains($query->sql, 'from "salesforce_interest_activities"') ? 1 : 0;
        });

        $summary = app(SalesforceInterestDashboardDatasetService::class)->summary($this->request());

        $this->assertSame(1001, $summary['kpis']['leads_totales']);
        $this->assertLessThanOrEqual(8, $sourceQueries);
        $this->assertLessThanOrEqual(2, $activityQueries);
    }

    public function test_comparison_uses_interest_vocabulary_and_exports_iterate_in_chunks(): void
    {
        $this->alignedRuns();
        foreach (range(1, 2001) as $sequence) {
            $this->interest([
                'salesforce_id' => 'a02'.str_pad((string) $sequence, 15, '0', STR_PAD_LEFT),
                'status' => 'Convertido',
            ]);
        }

        $activityQueries = 0;
        DB::listen(function ($query) use (&$activityQueries): void {
            if (str_contains($query->sql, 'from "salesforce_interest_activities"')) {
                $activityQueries++;
            }
        });

        $summary = app(SalesforceInterestDashboardDatasetService::class)->summary($this->request());
        $labels = collect($summary['comparativa'])->pluck('metrica')->implode(' ');
        $this->assertStringNotContainsString('Leads', $labels);

        $rows = app(SalesforceInterestDashboardDatasetService::class)
            ->kpiAuditRows($this->request('convertidos'));
        $this->assertSame(2001, $rows->count());
        $this->assertLessThanOrEqual(6, $activityQueries);
    }

    public function test_pipeline_never_runs_f5_after_f2_failure_and_rejects_failed_f5(): void
    {
        [$f2, $f5] = $this->alignedRuns();
        $service = new class(app(SalesforceInterestSyncService::class), app(SalesforceInterestActivitySyncService::class), $f5) extends SalesforceInterestReportingPipelineService
        {
            public bool $activitiesCalled = false;

            public function __construct(
                SalesforceInterestSyncService $interests,
                SalesforceInterestActivitySyncService $activities,
                private SalesforceInterestActivityRun $f5,
            ) {
                parent::__construct($interests, $activities);
            }

            protected function syncInterests(): ReportSyncRun
            {
                throw new RuntimeException('synthetic F2 failure');
            }

            protected function syncActivities(): SalesforceInterestActivityRun
            {
                $this->activitiesCalled = true;

                return $this->f5;
            }
        };

        try {
            $service->run();
            $this->fail('The synthetic F2 failure should abort the pipeline.');
        } catch (RuntimeException) {
            $this->assertFalse($service->activitiesCalled);
        }

        $f5->forceFill(['status' => 'failed'])->save();
        $invalid = new class(app(SalesforceInterestSyncService::class), app(SalesforceInterestActivitySyncService::class), $f2, $f5) extends SalesforceInterestReportingPipelineService
        {
            public function __construct(
                SalesforceInterestSyncService $interests,
                SalesforceInterestActivitySyncService $activities,
                private ReportSyncRun $f2,
                private SalesforceInterestActivityRun $f5,
            ) {
                parent::__construct($interests, $activities);
            }

            protected function syncInterests(): ReportSyncRun
            {
                return $this->f2;
            }

            protected function syncActivities(): SalesforceInterestActivityRun
            {
                return $this->f5;
            }
        };

        $this->expectException(RuntimeException::class);
        $invalid->run();
    }

    private function request(?string $metric = null): Request
    {
        return Request::create('/informes/leads/data/summary', 'GET', array_filter([
            'period' => 'custom',
            'current_start' => '2026-10-01',
            'current_end' => '2026-10-08',
            'comparison_start' => '2026-09-01',
            'comparison_end' => '2026-09-08',
            'metric' => $metric,
        ]));
    }

    private function customRequest(string $start, string $end, array $extra = []): Request
    {
        return Request::create('/informes/leads/data/summary', 'GET', array_merge([
            'period' => 'custom',
            'current_start' => $start,
            'current_end' => $end,
            'comparison_start' => $start,
            'comparison_end' => $end,
        ], $extra));
    }

    private function completedF2(): ReportSyncRun
    {
        return ReportSyncRun::query()->create([
            'dataset' => SalesforceInterestSyncService::DATASET,
            'source' => SalesforceInterestSyncService::SOURCE,
            'status' => 'completed',
            'source_cutoff_at' => '2026-10-08 10:00:00',
            'started_at' => '2026-10-08 09:59:00',
            'completed_at' => '2026-10-08 10:00:00',
            'timezone' => 'UTC',
        ]);
    }

    private function alignedRuns(): array
    {
        $f2 = $this->completedF2();
        $f5 = SalesforceInterestActivityRun::query()->create([
            'run_identifier' => (string) str()->uuid(),
            'reason' => 'Aligned activity snapshot for ROT-1 tests',
            'status' => 'completed',
            'source_interest_sync_run_id' => $f2->id,
            'source_interest_cutoff_at' => $f2->source_cutoff_at,
            'source_cutoff_at' => '2026-10-08 10:05:00',
            'started_at' => '2026-10-08 10:04:00',
            'completed_at' => '2026-10-08 10:05:00',
            'stats' => [],
        ]);

        return [$f2, $f5];
    }

    private function interest(array $attributes): SalesforceInterest
    {
        $interest = new SalesforceInterest;
        $interest->forceFill(array_merge([
            'salesforce_created_at' => '2026-10-07 08:00:00',
            'salesforce_last_modified_at' => '2026-10-07 08:00:00',
            'functional_created_at' => '2026-10-07 08:00:00',
            'is_deleted' => false,
        ], $attributes));
        $interest->save();

        return $interest;
    }
}
