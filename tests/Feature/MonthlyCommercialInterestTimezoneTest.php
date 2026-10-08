<?php

namespace Tests\Feature;

use App\Models\ReportSyncRun;
use App\Models\SalesforceInterest;
use App\Models\SalesforceInterestActivityRun;
use App\Services\Reports\MonthlyCommercial\MonthlyCommercialPeriodService;
use App\Services\Reports\MonthlyCommercial\MonthlyCommercialReportBuilder;
use App\Services\Salesforce\SalesforceInterestSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonthlyCommercialInterestTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_utc_execution_in_previous_day_uses_madrid_business_day_and_dst_rules(): void
    {
        $instant = CarbonImmutable::parse('2026-10-07T22:30:00Z');
        $periods = app(MonthlyCommercialPeriodService::class)->periods(30, $instant);

        $this->assertSame('2026-10-08', $periods['current_end']->toDateString());
        $this->assertSame('Europe/Madrid', $periods['current_end']->getTimezone()->getName());
        $this->assertSame('+02:00', $periods['current_end']->format('P'));

        $dst = app(MonthlyCommercialPeriodService::class)->periods(
            1,
            CarbonImmutable::parse('2026-03-29T01:30:00Z'),
        );
        $this->assertSame('2026-03-29T03:30:00+02:00', $dst['current_end']->toIso8601String());
    }

    public function test_builder_and_command_precheck_use_madrid_day_with_utc_interest_bounds(): void
    {
        $instant = CarbonImmutable::parse('2026-10-07T22:30:00Z');
        CarbonImmutable::setTestNow($instant);
        $this->alignedRuns($instant);
        $interest = new SalesforceInterest;
        $interest->forceFill([
            'salesforce_id' => 'a01000000000003001',
            'salesforce_created_at' => '2026-10-07 22:15:00',
            'salesforce_last_modified_at' => '2026-10-07 22:15:00',
            'functional_created_at' => '2026-10-07 22:15:00',
            'status' => 'Convertido',
            'is_deleted' => false,
        ])->save();

        $payload = app(MonthlyCommercialReportBuilder::class)->build(1, $instant);

        $this->assertSame('2026-10-08', $payload['fecha_analisis']);
        $this->assertSame(1, $payload['resumen_global']['leads_totales']);
        $this->artisan('reports:refresh-monthly-commercial', ['--days' => 1])
            ->doesntExpectOutputToContain('No hay Interests activos sincronizados')
            ->assertSuccessful();
    }

    private function alignedRuns(CarbonImmutable $cutoff): void
    {
        $f2 = ReportSyncRun::query()->create([
            'dataset' => SalesforceInterestSyncService::DATASET,
            'source' => SalesforceInterestSyncService::SOURCE,
            'status' => 'completed',
            'source_cutoff_at' => $cutoff,
            'started_at' => $cutoff->subMinute(),
            'completed_at' => $cutoff,
            'timezone' => 'UTC',
        ]);
        SalesforceInterestActivityRun::query()->create([
            'run_identifier' => (string) str()->uuid(),
            'reason' => 'Monthly Commercial timezone regression',
            'status' => 'completed',
            'source_interest_sync_run_id' => $f2->id,
            'source_interest_cutoff_at' => $f2->source_cutoff_at,
            'source_cutoff_at' => $cutoff,
            'started_at' => $cutoff->subMinute(),
            'completed_at' => $cutoff,
            'stats' => [],
        ]);
    }
}
