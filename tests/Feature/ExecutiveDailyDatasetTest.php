<?php

namespace Tests\Feature;

use App\Models\ReportSyncRun;
use App\Models\SalesforceLead;
use App\Models\SalesforceOpportunity;
use App\Services\Analytics\Executive\ExecutiveDailyDatasetService;
use App\Services\Analytics\Executive\ExecutiveMetricRulesEngine;
use App\Services\Reports\Leads\SalesforceLeadDashboardDatasetService;
use App\Services\Reports\ReservationsSales\ReservationsSalesDashboardDatasetService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\CreatesOpportunityDashboardRows;
use Tests\TestCase;

class ExecutiveDailyDatasetTest extends TestCase
{
    use CreatesOpportunityDashboardRows;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-01 08:00:00', ExecutiveDailyDatasetService::TIMEZONE));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_cutoff_dates_references_and_mtd_are_resolved_in_madrid_timezone(): void
    {
        $this->coverSources('2025-09-30', '2026-10-01 00:00:00');
        foreach (['2026-09-30', '2026-09-23', '2026-09-16', '2026-09-02', '2025-10-01'] as $date) {
            $this->lead('00Q-'.$date, $date.' 10:00:00');
        }
        $this->lead('00Q-mtd', '2026-09-10 10:00:00');
        $this->opportunityRow('006-reservation-current', [
            'created_date' => '2026-08-01 10:00:00',
            'reservation' => true,
            'reservation_date' => '2026-09-30',
        ]);
        $this->opportunityRow('006-sale-current', [
            'created_date' => '2026-08-01 10:00:00',
            'cv_signed' => true,
            'cv_signed_date' => '2026-09-30',
            'stage_name' => 'Contrato',
            'vehicle_interest_id' => '01t-sale-current',
        ]);

        $dataset = app(ExecutiveDailyDatasetService::class)->build(CarbonImmutable::parse('2026-10-01 09:00:00', 'Europe/Madrid'));

        $this->assertSame('2026-09-30', $dataset['cutoff']['date']);
        $this->assertSame('2026-09-01T00:00:00+02:00', $dataset['mtd_period']['start_inclusive']);
        $this->assertSame('2026-10-01T00:00:00+02:00', $dataset['mtd_period']['end_exclusive']);
        $this->assertSame(1, $dataset['metrics']['leads']['current']);
        $this->assertSame(1, $dataset['metrics']['leads']['references']['d7']);
        $this->assertSame(1, $dataset['metrics']['leads']['references']['d14']);
        $this->assertSame(0, $dataset['metrics']['leads']['references']['d21']);
        $this->assertSame(1, $dataset['metrics']['leads']['references']['d28']);
        $this->assertSame(1, $dataset['metrics']['leads']['references']['d364']);
        $this->assertSame(5, $dataset['metrics']['leads']['mtd']);
        $this->assertSame(1, $dataset['metrics']['reservas']['current']);
        $this->assertSame(1, $dataset['metrics']['ventas']['current']);
        $this->assertSame(ExecutiveMetricRulesEngine::defaultMetricConfigs()['leads'], $dataset['metrics']['leads']['engine_input']['config']);
    }

    public function test_january_first_evaluates_previous_year_december_thirty_first(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-01-01 08:00:00', ExecutiveDailyDatasetService::TIMEZONE));
        $this->coverSources('2026-01-01', '2027-01-01 00:00:00');
        $this->lead('00Q-year-close', '2026-12-31 10:00:00');

        $dataset = app(ExecutiveDailyDatasetService::class)->build();

        $this->assertSame('2026-12-31', $dataset['cutoff']['date']);
        $this->assertSame('2026-12-01T00:00:00+01:00', $dataset['mtd_period']['start_inclusive']);
        $this->assertSame(1, $dataset['metrics']['leads']['current']);
    }

    public function test_daily_metrics_reconcile_with_canonical_dashboard_services(): void
    {
        $this->coverSources('2025-09-30', '2026-10-01 00:00:00');
        $this->lead('00Q-active', '2026-09-30 09:00:00');
        $this->lead('00Q-deleted', '2026-09-30 10:00:00', ['is_deleted' => true]);
        $this->lead('00Q-unclassified', '2026-09-30 11:00:00', ['portal_text' => null]);
        $this->opportunityRow('006-reservation', [
            'created_date' => '2026-08-01 10:00:00',
            'reservation' => true,
            'reservation_date' => '2026-09-30',
        ]);
        $this->opportunityRow('006-sale', [
            'created_date' => '2026-08-01 10:00:00',
            'cv_signed' => true,
            'cv_signed_date' => '2026-09-30',
            'stage_name' => 'Contrato',
            'vehicle_interest_id' => '01t-sale',
        ]);

        $dataset = app(ExecutiveDailyDatasetService::class)->build();
        $leadSummary = app(SalesforceLeadDashboardDatasetService::class)->summary($this->leadRequest('2026-09-30', '2026-09-30'));
        $rvSummary = app(ReservationsSalesDashboardDatasetService::class)->summary($this->reservationSalesRequest('2026-09-30', '2026-09-30'));

        $this->assertSame(data_get($leadSummary, 'kpis.leads_totales'), $dataset['metrics']['leads']['current']);
        $this->assertSame(data_get($rvSummary, 'produccion_periodo.periodo_actual.reservas'), $dataset['metrics']['reservas']['current']);
        $this->assertSame(data_get($rvSummary, 'produccion_periodo.periodo_actual.ventas'), $dataset['metrics']['ventas']['current']);
    }

    public function test_zero_is_preserved_when_coverage_is_complete_and_absence_remains_null(): void
    {
        $this->coverSources('2025-09-30', '2026-10-01 00:00:00');
        $this->opportunityRow('006-coverage-only', [
            'created_date' => '2026-08-01 10:00:00',
            'reservation' => false,
            'cv_signed' => false,
        ]);
        $covered = app(ExecutiveDailyDatasetService::class)->build();

        $this->assertSame(0, $covered['metrics']['leads']['current']);
        $this->assertSame(0, $covered['metrics']['reservas']['current']);
        $this->assertSame(0, $covered['metrics']['ventas']['current']);
        $this->assertSame(0, $covered['metrics']['leads']['mtd']);

        ReportSyncRun::query()->delete();
        SalesforceLead::query()->delete();
        SalesforceOpportunity::query()->delete();
        Cache::flush();

        $missing = app(ExecutiveDailyDatasetService::class)->build();
        $this->assertNull($missing['metrics']['leads']['current']);
        $this->assertNull($missing['metrics']['reservas']['current']);
        $this->assertNull($missing['metrics']['ventas']['current']);
        $this->assertNull($missing['metrics']['leads']['mtd']);
    }

    public function test_recent_local_opportunity_without_report_sync_run_does_not_prove_coverage(): void
    {
        $this->coverLeadSource('2025-09-30', '2026-10-01 00:00:00');
        $this->opportunityRow('006-local-only', [
            'created_date' => '2026-08-01 10:00:00',
            'reservation' => true,
            'reservation_date' => '2026-09-30',
            'updated_at' => '2026-10-01 00:00:00',
        ]);

        $dataset = app(ExecutiveDailyDatasetService::class)->build();

        $this->assertNull($dataset['metrics']['reservas']['current']);
        $this->assertSame(ExecutiveMetricRulesEngine::HEALTH_PARTIAL, $dataset['metrics']['reservas']['data_health']);
        $this->assertFalse($dataset['metrics']['reservas']['day_complete']);
        $this->assertSame('report_sync_runs', $dataset['metrics']['reservas']['source_cutoff']['dataset_source']);
        $this->assertNotNull($dataset['metrics']['reservas']['source_cutoff']['local_updated_at']);
    }

    public function test_modified_run_completed_cannot_prove_historical_coverage(): void
    {
        $this->coverLeadSource('2025-09-30', '2026-10-01 00:00:00');
        $this->coverOpportunitySource('2025-09-30', '2026-10-01 00:00:00', '2026-10-01 00:00:00', mode: 'modified');

        $dataset = app(ExecutiveDailyDatasetService::class)->build();

        $this->assertNull($dataset['metrics']['reservas']['current']);
        $this->assertSame(ExecutiveMetricRulesEngine::HEALTH_PARTIAL, $dataset['metrics']['reservas']['data_health']);
        $this->assertSame('missing', $dataset['metrics']['reservas']['source_cutoff']['coverage_base_status']);
        $this->assertSame('modified', $dataset['metrics']['reservas']['source_cutoff']['freshness_mode']);
    }

    public function test_all_history_run_can_prove_historical_coverage_when_it_covers_the_range(): void
    {
        $this->coverLeadSource('2025-09-30', '2026-10-01 00:00:00');
        $this->coverOpportunitySource('2025-09-30', '2026-10-01 00:00:00', '2026-10-01 00:00:00', mode: 'all_history');

        $dataset = app(ExecutiveDailyDatasetService::class)->build();

        $this->assertSame(0, $dataset['metrics']['ventas']['current']);
        $this->assertSame(ExecutiveMetricRulesEngine::HEALTH_UPDATED, $dataset['metrics']['ventas']['data_health']);
        $this->assertSame('covered', $dataset['metrics']['ventas']['source_cutoff']['coverage_base_status']);
        $this->assertSame('all_history', $dataset['metrics']['ventas']['source_cutoff']['coverage_base_mode']);
    }

    public function test_base_run_must_cover_the_functional_range(): void
    {
        $this->coverLeadSource('2025-09-30', '2026-10-01 00:00:00');
        $this->coverOpportunitySource('2026-09-01', '2026-09-30', '2026-10-01 00:00:00');

        $dataset = app(ExecutiveDailyDatasetService::class)->build();

        $this->assertNull($dataset['metrics']['reservas']['current']);
        $this->assertSame(ExecutiveMetricRulesEngine::HEALTH_PARTIAL, $dataset['metrics']['reservas']['data_health']);
        $this->assertSame('missing', $dataset['metrics']['reservas']['source_cutoff']['coverage_base_status']);
    }

    public function test_completed_modified_run_supplies_freshness_but_not_base_coverage(): void
    {
        $this->coverLeadSource('2025-09-30', '2026-10-01 00:00:00');
        $this->coverOpportunitySource('2025-09-30', '2026-10-01 00:00:00', '2026-10-01 00:00:00');
        $this->coverOpportunitySource('2026-09-30', '2026-10-01 00:00:00', '2026-10-01 08:00:00', mode: 'modified');

        $dataset = app(ExecutiveDailyDatasetService::class)->build();

        $this->assertSame(0, $dataset['metrics']['ventas']['current']);
        $this->assertSame(ExecutiveMetricRulesEngine::HEALTH_UPDATED, $dataset['metrics']['ventas']['data_health']);
        $this->assertSame('period', $dataset['metrics']['ventas']['source_cutoff']['coverage_base_mode']);
        $this->assertSame('modified', $dataset['metrics']['ventas']['source_cutoff']['freshness_mode']);
    }

    public function test_opportunity_sync_metadata_queries_are_bounded_with_many_irrelevant_runs(): void
    {
        $this->coverLeadSource('2025-09-30', '2026-10-01 00:00:00');
        foreach (range(1, 150) as $day) {
            $date = CarbonImmutable::parse('2024-01-01', 'Europe/Madrid')->addDays($day);
            $this->coverOpportunitySource(
                $date->toDateString(),
                $date->addDay()->toDateString(),
                $date->addDay()->toDateTimeString(),
            );
        }
        foreach (['2026-09-30', '2026-09-23', '2026-09-16', '2026-09-09', '2026-09-02'] as $date) {
            $this->coverOpportunitySource($date, CarbonImmutable::parse($date)->addDay()->toDateString(), CarbonImmutable::parse($date)->addDay()->toDateString());
        }

        $opportunitySyncRunQueries = 0;
        DB::listen(function ($query) use (&$opportunitySyncRunQueries): void {
            if (str_contains($query->sql, 'report_sync_runs')
                && in_array('salesforce_opportunities', $query->bindings, true)) {
                $opportunitySyncRunQueries++;
            }
        });

        $dataset = app(ExecutiveDailyDatasetService::class)->build();

        $this->assertSame(0, $dataset['metrics']['reservas']['current']);
        $this->assertSame(ExecutiveMetricRulesEngine::HEALTH_UPDATED, $dataset['metrics']['reservas']['data_health']);
        $this->assertLessThanOrEqual(14, $opportunitySyncRunQueries);
    }

    public function test_failed_modified_run_after_base_coverage_is_a_data_incident(): void
    {
        $this->coverLeadSource('2025-09-30', '2026-10-01 00:00:00');
        $this->coverOpportunitySource('2025-09-30', '2026-10-01 00:00:00', '2026-10-01 00:00:00');
        $this->coverOpportunitySource('2026-09-30', '2026-10-01 00:00:00', '2026-10-01 08:00:00', 'failed', 'modified');

        $dataset = app(ExecutiveDailyDatasetService::class)->build();

        $this->assertTrue($dataset['metrics']['reservas']['data_incident']);
        $this->assertSame(ExecutiveMetricRulesEngine::HEALTH_INCIDENT, $dataset['metrics']['reservas']['data_health']);
        $this->assertNull($dataset['metrics']['reservas']['current']);
        $this->assertSame('period', $dataset['metrics']['reservas']['source_cutoff']['coverage_base_mode']);
        $this->assertSame('modified', $dataset['metrics']['reservas']['source_cutoff']['freshness_mode']);
    }

    public function test_running_run_after_base_coverage_is_a_data_incident(): void
    {
        $this->coverLeadSource('2025-09-30', '2026-10-01 00:00:00');
        $this->coverOpportunitySource('2025-09-30', '2026-10-01 00:00:00', '2026-10-01 00:00:00');
        $this->coverOpportunitySource('2026-09-30', '2026-10-01 00:00:00', null, 'running', 'modified');

        $dataset = app(ExecutiveDailyDatasetService::class)->build();

        $this->assertTrue($dataset['metrics']['ventas']['data_incident']);
        $this->assertSame(ExecutiveMetricRulesEngine::HEALTH_INCIDENT, $dataset['metrics']['ventas']['data_health']);
        $this->assertNull($dataset['metrics']['ventas']['current']);
    }

    public function test_failed_or_running_opportunity_sync_run_is_a_data_incident(): void
    {
        $this->coverLeadSource('2025-09-30', '2026-10-01 00:00:00');
        $this->coverOpportunitySource('2025-09-30', '2026-10-01 00:00:00', '2026-10-01 00:00:00', 'failed');

        $failed = app(ExecutiveDailyDatasetService::class)->build();
        $this->assertTrue($failed['metrics']['reservas']['data_incident']);
        $this->assertSame(ExecutiveMetricRulesEngine::HEALTH_INCIDENT, $failed['metrics']['reservas']['data_health']);
        $this->assertNull($failed['metrics']['reservas']['current']);

        ReportSyncRun::query()->where('dataset', 'salesforce_opportunities')->delete();
        $this->coverOpportunitySource('2025-09-30', '2026-10-01 00:00:00', '2026-10-01 00:00:00', 'running');

        $running = app(ExecutiveDailyDatasetService::class)->build();
        $this->assertTrue($running['metrics']['ventas']['data_incident']);
        $this->assertSame(ExecutiveMetricRulesEngine::HEALTH_INCIDENT, $running['metrics']['ventas']['data_health']);
    }

    public function test_completed_opportunity_sync_run_with_insufficient_cutoff_is_stale(): void
    {
        $this->coverLeadSource('2025-09-30', '2026-10-01 00:00:00');
        $this->coverOpportunitySource('2025-09-30', '2026-10-01 00:00:00', '2026-09-30 12:00:00');

        $dataset = app(ExecutiveDailyDatasetService::class)->build();

        $this->assertNull($dataset['metrics']['ventas']['current']);
        $this->assertSame(ExecutiveMetricRulesEngine::HEALTH_STALE, $dataset['metrics']['ventas']['data_health']);
        $this->assertFalse($dataset['metrics']['ventas']['engine_input']['day_complete']);
    }

    public function test_completed_opportunity_sync_run_with_sufficient_cutoff_publishes_zero(): void
    {
        $this->coverLeadSource('2025-09-30', '2026-10-01 00:00:00');
        $this->coverOpportunitySource('2025-09-30', '2026-10-01 00:00:00', '2026-10-01 00:00:00');

        $dataset = app(ExecutiveDailyDatasetService::class)->build();

        $this->assertSame(0, $dataset['metrics']['reservas']['current']);
        $this->assertSame(0, $dataset['metrics']['ventas']['current']);
        $this->assertSame(ExecutiveMetricRulesEngine::HEALTH_UPDATED, $dataset['metrics']['reservas']['data_health']);
    }

    public function test_required_reference_without_coverage_degrades_engine_input_to_partial(): void
    {
        $this->coverLeadSource('2025-09-30', '2026-10-01 00:00:00');
        $this->coverOpportunitySource('2026-09-30', '2026-10-01 00:00:00', '2026-10-01 00:00:00');

        $dataset = app(ExecutiveDailyDatasetService::class)->build();

        $this->assertSame(0, $dataset['metrics']['reservas']['current']);
        $this->assertNull($dataset['metrics']['reservas']['references']['d7']);
        $this->assertSame(ExecutiveMetricRulesEngine::HEALTH_PARTIAL, $dataset['metrics']['reservas']['data_health']);
        $this->assertFalse($dataset['metrics']['reservas']['engine_input']['day_complete']);
    }

    #[DataProvider('requiredReferenceDates')]
    public function test_each_required_weekly_reference_without_base_coverage_degrades_engine_input(string $referenceKey, string $missingDate): void
    {
        $this->coverLeadSource('2025-09-30', '2026-10-01 00:00:00');
        foreach (['2026-09-30', '2026-09-23', '2026-09-16', '2026-09-09', '2026-09-02'] as $date) {
            if ($date === $missingDate) {
                continue;
            }

            $this->coverOpportunitySource($date, CarbonImmutable::parse($date)->addDay()->toDateString(), CarbonImmutable::parse($date)->addDay()->toDateString());
        }

        $dataset = app(ExecutiveDailyDatasetService::class)->build();

        $this->assertNull($dataset['metrics']['reservas']['references'][$referenceKey]);
        $this->assertSame(ExecutiveMetricRulesEngine::HEALTH_PARTIAL, $dataset['metrics']['reservas']['data_health']);
        $this->assertFalse($dataset['metrics']['reservas']['day_complete']);
    }

    public static function requiredReferenceDates(): array
    {
        return [
            'D-7' => ['d7', '2026-09-23'],
            'D-14' => ['d14', '2026-09-16'],
            'D-21' => ['d21', '2026-09-09'],
            'D-28' => ['d28', '2026-09-02'],
        ];
    }

    public function test_required_reference_with_stale_cutoff_degrades_engine_input_to_stale(): void
    {
        $this->coverLeadSource('2025-09-30', '2026-10-01 00:00:00');
        foreach (['2026-09-30', '2026-09-16', '2026-09-09', '2026-09-02'] as $date) {
            $this->coverOpportunitySource($date, CarbonImmutable::parse($date)->addDay()->toDateString(), CarbonImmutable::parse($date)->addDay()->toDateString());
        }
        $this->coverOpportunitySource('2026-09-23', '2026-09-24', '2026-09-23 12:00:00');

        $dataset = app(ExecutiveDailyDatasetService::class)->build();

        $this->assertSame(0, $dataset['metrics']['ventas']['current']);
        $this->assertNull($dataset['metrics']['ventas']['references']['d7']);
        $this->assertSame(ExecutiveMetricRulesEngine::HEALTH_STALE, $dataset['metrics']['ventas']['data_health']);
        $this->assertFalse($dataset['metrics']['ventas']['engine_input']['day_complete']);
    }

    public function test_missing_d364_does_not_degrade_daily_evaluation(): void
    {
        $this->coverLeadSource('2025-09-30', '2026-10-01 00:00:00');
        foreach (['2026-09-30', '2026-09-23', '2026-09-16', '2026-09-09', '2026-09-02'] as $date) {
            $this->coverOpportunitySource($date, CarbonImmutable::parse($date)->addDay()->toDateString(), CarbonImmutable::parse($date)->addDay()->toDateString());
        }

        $dataset = app(ExecutiveDailyDatasetService::class)->build();

        $this->assertNull($dataset['metrics']['reservas']['references']['d364']);
        $this->assertTrue($dataset['metrics']['reservas']['day_complete']);
        $this->assertSame(ExecutiveMetricRulesEngine::HEALTH_UPDATED, $dataset['metrics']['reservas']['data_health']);
        $this->assertTrue($dataset['metrics']['reservas']['engine_input']['day_complete']);
    }

    public function test_missing_weekly_references_are_not_filled_with_zero(): void
    {
        $this->coverSources('2026-09-30', '2026-10-01 00:00:00');

        $dataset = app(ExecutiveDailyDatasetService::class)->build();

        $this->assertSame(0, $dataset['metrics']['leads']['current']);
        $this->assertNull($dataset['metrics']['leads']['references']['d7']);
        $this->assertNull($dataset['metrics']['leads']['references']['d14']);
        $this->assertNull($dataset['metrics']['leads']['references']['d21']);
        $this->assertNull($dataset['metrics']['leads']['references']['d28']);
        $this->assertNull($dataset['metrics']['leads']['references']['d364']);
    }

    public function test_closed_day_with_stale_or_incident_source_is_not_complete(): void
    {
        $this->coverSources('2026-09-01', '2026-09-30 12:00:00');
        $this->lead('00Q-stale', '2026-09-30 09:00:00', [
            'synced_at' => '2026-09-30 12:00:00',
            'salesforce_last_modified_at' => '2026-09-30 12:00:00',
            'updated_at' => '2026-09-30 12:00:00',
        ]);
        $stale = app(ExecutiveDailyDatasetService::class)->build();
        $this->assertNull($stale['metrics']['leads']['current']);
        $this->assertSame(ExecutiveMetricRulesEngine::HEALTH_STALE, $stale['metrics']['leads']['data_health']);

        Cache::flush();
        $this->coverSources('2025-09-30', '2026-10-01 00:00:00');
        foreach (['006-conflict-a' => 'Contrato', '006-conflict-b' => 'Cerrada Perdida'] as $id => $stage) {
            $this->opportunityRow($id, [
                'created_date' => '2026-08-01 10:00:00',
                'cv_signed' => true,
                'cv_signed_date' => '2026-09-30',
                'stage_name' => $stage,
                'vehicle_id' => 'a0V-conflict',
                'vehicle_plate' => '0000ABC',
                'vehicle_interest_id' => '01t-conflict',
            ]);
        }

        $incident = app(ExecutiveDailyDatasetService::class)->build();
        $this->assertTrue($incident['metrics']['ventas']['data_incident']);
        $this->assertSame(ExecutiveMetricRulesEngine::HEALTH_INCIDENT, $incident['metrics']['ventas']['data_health']);
        $this->assertNull($incident['metrics']['ventas']['current']);
    }

    public function test_reservation_and_sale_dates_are_imputed_by_their_own_fields_and_deduplicated(): void
    {
        $this->coverSources('2025-09-30', '2026-10-01 00:00:00');
        foreach (['006-res-a', '006-res-b'] as $id) {
            $this->opportunityRow($id, [
                'created_date' => '2026-08-01 10:00:00',
                'reservation' => true,
                'reservation_date' => '2026-09-30',
                'vehicle_interest_id' => '01t-reservation-duplicate',
            ]);
        }
        foreach (['006-sale-a', '006-sale-b'] as $id) {
            $this->opportunityRow($id, [
                'created_date' => '2026-08-01 10:00:00',
                'cv_signed' => true,
                'cv_signed_date' => '2026-09-30',
                'stage_name' => 'Contrato',
                'vehicle_interest_id' => '01t-sale-duplicate',
            ]);
        }
        $this->opportunityRow('006-created-on-cutoff-without-production', [
            'created_date' => '2026-09-30 10:00:00',
            'reservation' => false,
            'cv_signed' => false,
        ]);

        $dataset = app(ExecutiveDailyDatasetService::class)->build();

        $this->assertSame(1, $dataset['metrics']['reservas']['current']);
        $this->assertSame(1, $dataset['metrics']['ventas']['current']);
        $this->assertSame(1, $dataset['metrics']['reservas']['coverage']['current']['raw_value']);
    }

    public function test_dataset_does_not_expose_pii_or_salesforce_ids(): void
    {
        $this->coverSources('2025-09-30', '2026-10-01 00:00:00');
        $this->lead('00Q-sensitive', '2026-09-30 10:00:00', [
            'name' => 'Sensitive Lead',
            'email' => 'sensitive@example.test',
            'phone' => '+34999999999',
        ]);
        $this->opportunityRow('006-sensitive', [
            'name' => 'Sensitive Opportunity',
            'created_date' => '2026-08-01 10:00:00',
            'reservation' => true,
            'reservation_date' => '2026-09-30',
            'account_name' => 'Sensitive Account',
            'account_phone' => '+34888888888',
            'account_person_email' => 'account@example.test',
        ]);

        $json = json_encode(app(ExecutiveDailyDatasetService::class)->build());

        foreach (['00Q-sensitive', '006-sensitive', 'sensitive@example.test', '+34999999999', 'Sensitive Account', 'account@example.test'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    private function coverSources(string $start, string $cutoff): void
    {
        $this->coverLeadSource($start, $cutoff);
        $this->coverOpportunitySource($start, $cutoff, $cutoff);
    }

    private function coverLeadSource(string $start, string $cutoff): void
    {
        ReportSyncRun::query()->create([
            'dataset' => 'leads_dashboard',
            'source' => 'salesforce',
            'status' => 'completed',
            'period_start_at' => CarbonImmutable::parse($start, 'Europe/Madrid')->startOfDay(),
            'period_end_at' => CarbonImmutable::parse($cutoff, 'Europe/Madrid'),
            'source_cutoff_at' => CarbonImmutable::parse($cutoff, 'Europe/Madrid'),
            'started_at' => CarbonImmutable::parse($cutoff, 'Europe/Madrid')->subMinutes(5),
            'completed_at' => CarbonImmutable::parse($cutoff, 'Europe/Madrid'),
            'timezone' => 'Europe/Madrid',
            'stats' => [],
        ]);
    }

    private function coverOpportunitySource(
        string $start,
        string $end,
        ?string $cutoff = null,
        string $status = 'completed',
        string $mode = 'period',
    ): void {
        $endAt = CarbonImmutable::parse($end, 'Europe/Madrid');
        $completedAt = $status === 'completed'
            ? $endAt
            : ($status === 'failed' ? $endAt : null);

        ReportSyncRun::query()->create([
            'dataset' => 'salesforce_opportunities',
            'source' => 'salesforce',
            'status' => $status,
            'period_start_at' => CarbonImmutable::parse($start, 'Europe/Madrid')->startOfDay(),
            'period_end_at' => $endAt,
            'source_cutoff_at' => $cutoff !== null ? CarbonImmutable::parse($cutoff, 'Europe/Madrid') : null,
            'started_at' => $endAt->subMinutes(5),
            'completed_at' => $completedAt,
            'timezone' => 'Europe/Madrid',
            'stats' => ['mode' => $mode],
        ]);
    }

    private function lead(string $id, string $createdDate, array $overrides = []): SalesforceLead
    {
        return SalesforceLead::query()->create(array_merge([
            'salesforce_id' => $id,
            'name' => $id,
            'created_date' => $createdDate,
            'synced_at' => '2026-10-01 00:00:00',
            'salesforce_last_modified_at' => '2026-10-01 00:00:00',
            'is_deleted' => false,
            'status' => 'Nuevo',
            'owner_id' => '005-commercial',
            'owner_name' => 'Comercial',
            'portal_text' => 'Web',
            'delegacion_encargada_text' => 'HR MOTOR TORREJON',
        ], $overrides));
    }

    private function leadRequest(string $start, string $end): Request
    {
        return Request::create('/internal/leads', 'GET', [
            'period' => 'custom',
            'current_start' => $start,
            'current_end' => $end,
            'comparison_start' => CarbonImmutable::parse($start)->subDay()->toDateString(),
            'comparison_end' => CarbonImmutable::parse($start)->subDay()->toDateString(),
        ]);
    }

    private function reservationSalesRequest(string $start, string $end): Request
    {
        return Request::create('/internal/reservas-ventas', 'GET', [
            'period' => 'custom',
            'date_criterion' => 'created_date',
            'current_start' => $start,
            'current_end' => $end,
            'comparison_start' => CarbonImmutable::parse($start)->subDay()->toDateString(),
            'comparison_end' => CarbonImmutable::parse($start)->subDay()->toDateString(),
        ]);
    }
}
