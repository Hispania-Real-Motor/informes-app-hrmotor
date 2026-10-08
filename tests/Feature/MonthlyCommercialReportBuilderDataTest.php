<?php

namespace Tests\Feature;

use App\Models\ReportSyncRun;
use App\Models\SalesforceInterest;
use App\Models\SalesforceInterestActivity;
use App\Models\SalesforceInterestActivityRun;
use App\Models\SalesforceUser;
use App\Services\Reports\MonthlyCommercial\MonthlyCommercialReportBuilder;
use App\Services\Salesforce\SalesforceInterestSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonthlyCommercialReportBuilderDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_builder_genera_payload_con_datos_sin_salir_a_cero(): void
    {
        $now = CarbonImmutable::parse('2026-05-13 12:00:00', 'UTC');
        $f2 = ReportSyncRun::query()->create([
            'dataset' => SalesforceInterestSyncService::DATASET,
            'source' => SalesforceInterestSyncService::SOURCE,
            'status' => 'completed',
            'source_cutoff_at' => $now,
            'started_at' => $now->subMinute(),
            'completed_at' => $now,
            'timezone' => 'UTC',
        ]);
        $f5 = SalesforceInterestActivityRun::query()->create([
            'run_identifier' => (string) str()->uuid(),
            'reason' => 'Aligned Monthly Commercial activity snapshot',
            'status' => 'completed',
            'source_interest_sync_run_id' => $f2->id,
            'source_interest_cutoff_at' => $f2->source_cutoff_at,
            'source_cutoff_at' => $now,
            'started_at' => $now->subMinute(),
            'completed_at' => $now,
            'stats' => [],
        ]);

        SalesforceUser::create([
            'salesforce_id' => '005-owner',
            'name' => 'Comercial Demo',
            'profile_name' => 'Compra/Venta',
            'is_active' => true,
        ]);

        SalesforceInterest::create([
            'salesforce_id' => 'a01000000000000001',
            'salesforce_created_at' => $now->subDays(3),
            'salesforce_last_modified_at' => $now,
            'status' => 'Convertido',
            'owner_salesforce_id' => '005-owner',
            'owner_name' => 'Comercial Demo',
            'source' => 'Web',
            'is_deleted' => false,
        ]);

        SalesforceInterest::create([
            'salesforce_id' => 'a01000000000000002',
            'salesforce_created_at' => $now->subDays(2),
            'salesforce_last_modified_at' => $now,
            'status' => 'Descartado',
            'owner_salesforce_id' => '005-owner',
            'owner_name' => 'Comercial Demo',
            'source' => 'Meta',
            'is_deleted' => false,
        ]);

        SalesforceInterest::create([
            'salesforce_id' => 'a01000000000000003',
            'salesforce_created_at' => $now->subDay(),
            'salesforce_last_modified_at' => $now,
            'status' => 'Potencial',
            'owner_salesforce_id' => '005-owner',
            'owner_name' => 'Comercial Demo',
            'source' => 'Google Maps',
            'is_deleted' => false,
        ]);

        SalesforceInterestActivity::create([
            'activity_run_id' => $f5->id,
            'activity_salesforce_id' => '00T000000000000001',
            'interest_salesforce_id' => 'a01000000000000001',
            'activity_kind' => 'Task',
            'relationship_status' => 'resolved',
            'activity_is_deleted' => false,
            'interest_is_deleted' => false,
            'salesforce_created_at' => $now->subDays(3)->addMinutes(30),
            'activity_date' => $now->subDays(3)->toDateString(),
        ]);

        $payload = app(MonthlyCommercialReportBuilder::class)->build(30, $now);

        $this->assertSame(3, $payload['resumen_global']['leads_totales']);
        $this->assertSame(1, $payload['resumen_global']['leads_convertidos']);
        $this->assertSame(1, $payload['resumen_global']['leads_descartados']);
        $this->assertSame(1, $payload['resumen_global']['leads_potenciales']);
        $this->assertSame(1, $payload['resumen_global']['potenciales_sin_seguimiento_mayor_3_dias']);
    }
}
