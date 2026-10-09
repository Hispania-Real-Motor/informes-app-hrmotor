<?php

namespace Tests\Feature;

use App\Models\CommercialDelegationSnapshot;
use App\Models\CommercialPerformanceMonthlyTarget;
use App\Models\ReportSyncRun;
use App\Models\ReportUser;
use App\Models\SalesforceInterest;
use App\Models\SalesforceOpportunity;
use App\Models\SalesforceOpportunityHistorySyncInterval;
use App\Models\SalesforceOpportunityStageTransition;
use App\Models\SalesforceUser;
use App\Services\Reports\ReservasVentas\OpportunityPortalNormalizer;
use App\Services\Reports\ReservationsSales\CommercialDelegationSnapshotService;
use App\Services\Reports\ReservationsSales\CommercialPerformanceDatasetService;
use App\Services\Reports\ReservationsSales\Sync\SalesforceOpportunitySyncService;
use App\Services\Salesforce\SalesforceClient;
use App\Services\Salesforce\SalesforceLeadFieldResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ReservationsSalesCommercialPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ReportSyncRun::query()->create([
            'dataset' => 'salesforce_interests',
            'source' => 'salesforce',
            'status' => 'completed',
            'source_cutoff_at' => '2026-10-09 09:00:00',
            'started_at' => '2026-10-09 09:00:00',
            'completed_at' => '2026-10-09 09:01:00',
        ]);
    }

    public function test_cache_base_no_reconstruye_interests_para_filtros_y_se_invalida_por_version(): void
    {
        Cache::flush();
        $this->commercial('005-cache', 'Comercial cache');
        $this->snapshot('005-cache', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        $this->seedPerformanceMetrics('005-cache', 'Comercial cache', 1, 0, 0, 0);
        $sourceQueries = [
            'interests' => 0,
            'opportunities' => 0,
            'transitions' => 0,
            'snapshots' => 0,
        ];
        DB::listen(function ($query) use (&$sourceQueries): void {
            foreach ([
                'interests' => 'salesforce_interests',
                'opportunities' => 'salesforce_opportunities',
                'transitions' => 'salesforce_opportunity_stage_transitions',
                'snapshots' => 'commercial_delegation_snapshots',
            ] as $source => $table) {
                if (str_contains($query->sql, $table)) {
                    $sourceQueries[$source]++;
                }
            }
        });
        $service = app(CommercialPerformanceDatasetService::class);

        $service->payload(['month' => '2026-08']);
        $firstBuildSourceQueries = $sourceQueries;
        $service->payload(['month' => '2026-08', 'zone' => 'Zona Mediterraneo', 'delegation' => 'Alicante', 'commercial' => '005-cache']);

        $this->assertGreaterThan(0, $firstBuildSourceQueries['interests']);
        $this->assertSame($firstBuildSourceQueries, $sourceQueries);

        Cache::forever('commercial_performance_cache_version', 2);
        $service->payload(['month' => '2026-08']);

        $this->assertGreaterThan($firstBuildSourceQueries['interests'], $sourceQueries['interests']);
    }

    public function test_cache_base_preserva_dataset_generated_at_hasta_que_cambia_la_version(): void
    {
        Cache::flush();
        $this->commercial('005-generated-at', 'Comercial timestamp');
        $this->snapshot('005-generated-at', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        $fixedNow = CarbonImmutable::parse('2026-09-08 12:00:00', 'Europe/Madrid');
        CarbonImmutable::setTestNow($fixedNow);

        try {
            $service = app(CommercialPerformanceDatasetService::class);
            $first = $service->payload(['month' => '2026-08']);
            $filtered = $service->payload(['month' => '2026-08', 'commercial' => '005-generated-at']);

            $this->assertSame($first['dataset_generated_at'], $filtered['dataset_generated_at']);

            CarbonImmutable::setTestNow($fixedNow->addSecond());
            Cache::forever('commercial_performance_cache_version', 2);
            $rebuilt = $service->payload(['month' => '2026-08']);

            $this->assertNotSame($first['dataset_generated_at'], $rebuilt['dataset_generated_at']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_cache_base_cambia_con_el_snapshot_f2_exacto(): void
    {
        Cache::flush();
        $fixedNow = CarbonImmutable::parse('2026-10-09 12:00:00', 'Europe/Madrid');
        CarbonImmutable::setTestNow($fixedNow);

        try {
            $service = app(CommercialPerformanceDatasetService::class);
            $first = $service->payload(['month' => '2026-08']);

            ReportSyncRun::query()->create([
                'dataset' => 'salesforce_interests',
                'source' => 'salesforce',
                'status' => 'completed',
                'source_cutoff_at' => '2026-10-09 10:00:00',
                'started_at' => '2026-10-09 10:00:00',
                'completed_at' => '2026-10-09 10:01:00',
            ]);
            CarbonImmutable::setTestNow($fixedNow->addSecond());

            $second = $service->payload(['month' => '2026-08']);

            $this->assertNotSame($first['dataset_generated_at'], $second['dataset_generated_at']);
            $this->assertNotSame(
                $first['data_quality']['interest_sync_run_id'],
                $second['data_quality']['interest_sync_run_id'],
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_sync_de_opportunities_invalida_la_version_compartida_sin_duplicar_la_especifica(): void
    {
        $dataset = file_get_contents(app_path('Services/Reports/ReservationsSales/CommercialPerformanceDatasetService.php'));
        $command = file_get_contents(app_path('Console/Commands/SalesforceSyncOpportunitiesCommand.php'));
        $invalidateStart = strpos($command, 'private function invalidateDashboardCache()');
        $invalidateEnd = strpos($command, 'private function periodStart', $invalidateStart);
        $invalidateBlock = substr($command, $invalidateStart, $invalidateEnd - $invalidateStart);

        $this->assertStringContainsString("Cache::get('reservas_ventas_dashboard_cache_version', 1)", $invalidateBlock);
        $this->assertStringNotContainsString('commercial_performance_cache_version', $invalidateBlock);
        $this->assertStringContainsString("Cache::get('reservas_ventas_dashboard_cache_version', 1)", $dataset);
        $this->assertStringContainsString("Cache::get('commercial_performance_cache_version', 1)", $dataset);
    }

    public function test_cache_persistente_recupera_la_base_serializada_sin_objetos_ni_error_con_filtro_comercial(): void
    {
        $store = 'commercial-performance-serializing-test';
        $path = storage_path('framework/cache/'.$store.'-'.bin2hex(random_bytes(6)));
        $originalDefault = config('cache.default');
        $originalStore = config("cache.stores.{$store}");
        config()->set("cache.stores.{$store}", [
            'driver' => 'file',
            'path' => $path,
            'lock_path' => $path,
        ]);
        config()->set('cache.default', $store);

        try {
            $this->assertFalse((bool) config('cache.serializable_classes'));
            $this->commercial('005-serializing-cache', 'Comercial cache serializante');
            $this->snapshot('005-serializing-cache', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
            $this->seedPerformanceMetrics('005-serializing-cache', 'Comercial cache serializante', 1, 1, 1, 0);
            $sourceQueries = 0;
            DB::listen(function ($query) use (&$sourceQueries): void {
                if (str_contains($query->sql, 'salesforce_leads')
                    || str_contains($query->sql, 'salesforce_opportunities')
                    || str_contains($query->sql, 'commercial_delegation_snapshots')) {
                    $sourceQueries++;
                }
            });

            $first = $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
                ->assertOk()
                ->json();
            $queriesAfterMiss = $sourceQueries;
            $second = $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08&commercial=005-serializing-cache')
                ->assertOk()
                ->json();

            $this->assertGreaterThan(0, $queriesAfterMiss);
            $this->assertSame($queriesAfterMiss, $sourceQueries);
            $this->assertSame(
                collect($first['items'])->firstWhere('commercial_id', '005-serializing-cache'),
                $second['items'][0],
            );

            $method = new \ReflectionMethod(CommercialPerformanceDatasetService::class, 'basePayload');
            $method->setAccessible(true);
            $base = $method->invoke(app(CommercialPerformanceDatasetService::class), '2026-08');
            array_walk_recursive($base, function (mixed $value): void {
                $this->assertFalse(is_object($value));
            });
        } finally {
            Cache::store($store)->flush();
            File::deleteDirectory($path);
            config()->set('cache.default', $originalDefault);
            config()->set("cache.stores.{$store}", $originalStore);
        }
    }

    public function test_data_quality_esta_segmentada_por_mes_seleccionado(): void
    {
        Cache::flush();
        $this->commercial('005-quality-history', 'Histórico no certificable');
        foreach (['2026-07-02', '2026-08-02', '2026-08-03'] as $index => $assignedAt) {
            $this->interestFromLegacyFixture([
                'salesforce_id' => '00Q-quality-history-'.$index,
                'name' => 'Lead histórico '.$index,
                'created_date' => $assignedAt,
                'fecha_asignacion' => $assignedAt,
                'status' => 'Potencial',
                'record_type_name' => 'Venta',
                'record_type_normalized' => 'venta',
                'owner_id' => '005-quality-history',
                'owner_name' => 'Histórico no certificable',
                'is_deleted' => false,
            ]);
        }

        $august = $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->json();
        $july = $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-07')
            ->assertOk()
            ->json();

        $this->assertSame(2, $august['data_quality']['uncertified_historical_events']);
        $this->assertSame(1, $july['data_quality']['uncertified_historical_events']);
        $this->assertSame(0, $august['data_quality']['unresolved_attribution_events']);
    }

    public function test_leads_materializados_agrupados_mantienen_el_numero_de_incidencias_por_evento(): void
    {
        Cache::flush();
        for ($index = 1; $index <= 3; $index++) {
            $this->interestFromLegacyFixture([
                'salesforce_id' => "00Q-unresolved-{$index}",
                'name' => "Lead sin responsable {$index}",
                'created_date' => '2026-08-01 08:00:00',
                'fecha_asignacion' => '2026-08-02 10:00:00',
                'status' => 'Potencial',
                'record_type_name' => 'Venta',
                'record_type_normalized' => 'venta',
                'owner_id' => '005-no-existe',
                'owner_name' => 'Responsable inexistente',
                'is_deleted' => false,
            ]);
        }

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonCount(0, 'items')
            ->assertJsonPath('data_incident.leads', 3)
            ->assertJsonPath('data_quality.unresolved_attribution_events', 3);
    }

    public function test_leads_materializados_agrupados_mantienen_eventos_historicos_no_certificables(): void
    {
        Cache::flush();
        $this->commercial('005-uncertified-leads', 'Comercial no certificable');
        for ($index = 1; $index <= 3; $index++) {
            $this->interestFromLegacyFixture([
                'salesforce_id' => "00Q-uncertified-{$index}",
                'name' => "Lead no certificable {$index}",
                'created_date' => '2026-08-01 08:00:00',
                'fecha_asignacion' => '2026-08-02 10:00:00',
                'status' => 'Potencial',
                'record_type_name' => 'Venta',
                'record_type_normalized' => 'venta',
                'owner_id' => '005-uncertified-leads',
                'owner_name' => 'Comercial no certificable',
                'is_deleted' => false,
            ]);
        }

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('data_quality.uncertified_historical_events', 3);
    }

    public function test_opportunities_y_cancelaciones_mantienen_un_evento_de_incidencia_por_atribucion(): void
    {
        Cache::flush();
        $this->coverHistoryMonth('2026-08');
        $this->opportunity('006-unresolved-events', [
            'owner_id' => '005-no-existe',
            'owner_name' => 'Responsable inexistente',
        ]);
        SalesforceOpportunityStageTransition::query()->create([
            'salesforce_history_id' => '0Jh-unresolved-events',
            'opportunity_salesforce_id' => '006-unresolved-events',
            'previous_stage' => 'Reserva',
            'new_stage' => 'Cerrada Perdida',
            'transitioned_at' => '2026-08-07 10:00:00',
            'reservation_date' => '2026-08-01',
            'owner_id' => '005-no-existe',
            'owner_name' => 'Responsable inexistente',
            'source' => 'OpportunityHistory',
            'is_reservation_cancellation' => true,
            'quality_status' => 'valid',
            'synced_at' => now(),
        ]);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('data_quality.unresolved_attribution_events', 2);
    }

    public function test_interest_con_owner_valido_y_nombre_vacio_usa_el_nombre_del_roster(): void
    {
        Cache::flush();
        $this->commercial('005-roster-name', 'Nombre del roster');
        $this->snapshot('005-roster-name', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        $this->interestFromLegacyFixture([
            'salesforce_id' => '00Q-roster-name',
            'name' => 'Lead con nombre vacío',
            'created_date' => '2026-08-01 08:00:00',
            'fecha_asignacion' => '2026-08-02 10:00:00',
            'status' => 'Convertido',
            'record_type_name' => 'Venta',
            'record_type_normalized' => 'venta',
            'owner_id' => '005-roster-name',
            'owner_name' => '   ',
            'is_deleted' => false,
        ]);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('items.0.commercial_id', '005-roster-name')
            ->assertJsonPath('items.0.commercial', 'Nombre del roster')
            ->assertJsonPath('items.0.leads', 1);
    }

    public function test_actividad_mensual_reutiliza_comercial_efectivo_y_fechas_de_cada_hito(): void
    {
        $this->coverHistoryMonth('2026-08');
        $this->commercial('005-worker', 'Comercial Worker');
        $this->snapshot('005-worker', 'Alicante', 'Zona Mediterraneo', '2026-05-01');

        $this->interestFromLegacyFixture([
            'salesforce_id' => '00Q-converted',
            'name' => 'Lead convertido',
            'created_date' => '2026-07-01 08:00:00',
            'fecha_asignacion' => '2026-08-05 10:00:00',
            'status' => 'Convertido',
            'record_type_name' => 'Lead',
            'record_type_normalized' => 'venta',
            'owner_id' => '005-worker',
            'owner_name' => 'Comercial Worker',
            'is_deleted' => false,
        ]);
        $this->interestFromLegacyFixture([
            'salesforce_id' => '00Q-appraisal',
            'name' => 'Tasación fuera',
            'created_date' => '2026-08-01 08:00:00',
            'fecha_asignacion' => '2026-08-06 10:00:00',
            'status' => 'Potencial',
            'record_type_name' => 'Tasación',
            'record_type_normalized' => 'tasacion',
            'owner_id' => '005-worker',
            'owner_name' => 'Comercial Worker',
            'is_deleted' => false,
        ]);

        $this->opportunity('006-activity', [
            'created_date' => '2026-08-02 10:00:00',
            'reservation' => true,
            'reservation_date' => '2026-08-10',
            'cv_signed' => true,
            'cv_signed_date' => '2026-08-20',
            'stage_name' => 'Contrato',
            'informe_rentabilidad' => 1500,
        ]);
        $this->opportunity('006-old-reservation-sale', [
            'created_date' => '2026-07-02 10:00:00',
            'reservation' => true,
            'reservation_date' => '2026-07-10',
            'cv_signed' => true,
            'cv_signed_date' => '2026-08-21',
            'stage_name' => 'Contrato',
            'informe_rentabilidad' => null,
        ]);
        $lost = $this->opportunity('006-cancelled', [
            'created_date' => '2026-07-03 10:00:00',
            'reservation' => true,
            'reservation_date' => '2026-07-11',
            'stage_name' => 'Cerrada Perdida',
            'close_date' => '2026-09-13',
            'salesforce_last_modified_at' => '2026-08-24 12:00:00',
        ]);
        SalesforceOpportunityStageTransition::query()->create([
            'salesforce_history_id' => '0Jh-history',
            'opportunity_salesforce_id' => $lost->salesforce_id,
            'previous_stage' => 'Reserva',
            'new_stage' => 'Cerrada Perdida',
            'transitioned_at' => '2026-08-22 11:00:00',
            'reservation_date' => '2026-07-11',
            'owner_id' => '005-worker',
            'owner_name' => 'Comercial Worker',
            'source' => 'OpportunityHistory',
            'is_reservation_cancellation' => true,
            'quality_status' => 'valid',
            'synced_at' => now(),
        ]);

        $response = $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('semantics.cohort', false)
            ->assertJsonPath('semantics.cancellation_date_field', 'salesforce_opportunity_stage_transitions.transitioned_at')
            ->assertJsonPath('items.0.commercial_id', '005-worker')
            ->assertJsonPath('items.0.leads', 1)
            ->assertJsonPath('items.0.opportunities', 1)
            ->assertJsonPath('items.0.reservations_total', 1)
            ->assertJsonPath('items.0.reservations_active', 0)
            ->assertJsonPath('items.0.sales', 2)
            ->assertJsonPath('items.0.cancellations', 1)
            ->assertJsonPath('items.0.reservation_to_sale_pct', 200)
            ->assertJsonPath('items.0.margin_total', 1500)
            ->assertJsonPath('items.0.sales_with_margin', 1)
            ->assertJsonPath('items.0.sales_without_margin', 1)
            ->assertJsonPath('items.0.margin_coverage_pct', 50);

        $this->assertSame(2, collect($response->json('evolution'))->firstWhere('month', '2026-07')['reservations_total']);
    }

    public function test_cancelacion_tardia_reclasifica_agosto_sin_mover_el_evento_de_septiembre_ni_duplicar(): void
    {
        Cache::flush();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 12:00:00', 'Europe/Madrid'));

        try {
            $this->commercial('005-late-cancel', 'Cancelación tardía');
            $this->snapshot('005-late-cancel', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
            $opportunity = $this->opportunity('006-late-cancel', [
                'owner_id' => '005-late-cancel',
                'owner_name' => 'Cancelación tardía',
                'created_date' => '2026-08-05 10:00:00',
                'salesforce_last_modified_at' => '2026-08-20 10:00:00',
                'reservation' => true,
                'reservation_date' => '2026-08-12',
                'stage_name' => 'Reserva',
            ]);
            $this->coverHistoryMonth('2026-08');
            $this->coverHistoryMonth('2026-09');
            Cache::forever('reservas_ventas_dashboard_cache_version', 4);
            Cache::forever('commercial_performance_cache_version', 9);

            $dataset = app(CommercialPerformanceDatasetService::class);
            $before = $dataset->payload(['month' => '2026-08']);
            $beforeRow = collect($before['items'])->firstWhere('commercial_id', '005-late-cancel');

            $this->assertSame(1, $beforeRow['reservations_total']);
            $this->assertSame(1, $beforeRow['reservations_valid_for_objective']);
            $this->assertSame(0, $beforeRow['reservations_dropped']);
            $this->assertSame(5.56, $beforeRow['fulfillment_pct']);
            $this->assertSame(0, $before['summary']['cancellations']);

            $client = new class extends SalesforceClient
            {
                public string $opportunitySoql = '';

                public function __construct() {}

                public function queryAll(string $soql): array
                {
                    return [];
                }

                public function query(string $soql): array
                {
                    if (! str_contains($soql, 'FROM Opportunity')) {
                        return [];
                    }

                    $this->opportunitySoql = $soql;

                    return [[
                        'Id' => '006-late-cancel',
                        'Name' => '006-late-cancel',
                        'CreatedDate' => '2026-08-05T10:00:00.000Z',
                        'LastModifiedDate' => '2026-09-10T10:00:00.000Z',
                        'StageName' => 'Cerrada Perdida',
                        'RecordType' => ['Name' => 'Venta'],
                        'OwnerId' => '005-late-cancel',
                        'Owner' => ['Name' => 'Cancelación tardía', 'IsActive' => true, 'USR_SEL_Delegacion__c' => 'Alicante'],
                        'Account' => [],
                        'OPO_CAS_Reserva__c' => true,
                        'OPO_FEC_Fecha_de_reserva__c' => '2026-08-12',
                        'OPO_CAS_Contrato_CV_firmado__c' => false,
                    ]];
                }
            };
            $sync = new SalesforceOpportunitySyncService(
                $client,
                app(OpportunityPortalNormalizer::class),
                app(SalesforceLeadFieldResolver::class),
            );
            $sync->sync(
                CarbonImmutable::parse('2026-09-10 00:00:00', 'UTC'),
                CarbonImmutable::parse('2026-09-11 00:00:00', 'UTC'),
                true,
            );
            SalesforceOpportunityStageTransition::query()->create([
                'salesforce_history_id' => '0Jh-late-cancel',
                'opportunity_salesforce_id' => '006-late-cancel',
                'previous_stage' => 'Reserva',
                'new_stage' => 'Cerrada Perdida',
                'transitioned_at' => '2026-09-10 10:00:00',
                'reservation_date' => '2026-08-12',
                'owner_id' => '005-late-cancel',
                'owner_name' => 'Cancelación tardía',
                'source' => 'OpportunityHistory',
                'is_reservation_cancellation' => true,
                'quality_status' => 'valid',
                'synced_at' => now(),
            ]);

            Cache::forever('reservas_ventas_dashboard_cache_version', 5);
            $after = $dataset->payload(['month' => '2026-08']);
            $afterRow = collect($after['items'])->firstWhere('commercial_id', '005-late-cancel');
            $september = $dataset->payload(['month' => '2026-09']);

            $this->assertStringContainsString('LastModifiedDate >= 2026-09-10T00:00:00Z', $client->opportunitySoql);
            $this->assertDatabaseCount('salesforce_opportunities', 1);
            $this->assertSame($opportunity->id, SalesforceOpportunity::query()->sole()->id);
            $this->assertSame('2026-08-12', SalesforceOpportunity::query()->sole()->reservation_date->toDateString());
            $this->assertSame('2026-09-10 10:00:00', SalesforceOpportunity::query()->sole()->salesforce_last_modified_at->format('Y-m-d H:i:s'));
            $this->assertSame(1, $afterRow['reservations_total']);
            $this->assertSame(0, $afterRow['reservations_valid_for_objective']);
            $this->assertSame(1, $afterRow['reservations_dropped']);
            $this->assertSame(0.0, $afterRow['fulfillment_pct']);
            $this->assertSame(0, $after['summary']['cancellations']);
            $this->assertSame(1, $september['summary']['cancellations']);
            $this->assertSame(9, Cache::get('commercial_performance_cache_version'));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_comparativas_equipo_usan_el_universo_evaluable_y_el_filtro_comercial_no_lo_recalcula(): void
    {
        foreach ([
            ['005-a', 'Ana', 'Alicante', 'Zona Mediterraneo'],
            ['005-b', 'Bea', 'Alicante', 'Zona Mediterraneo'],
            ['005-c', 'Cris', 'Murcia', 'Zona Levante'],
        ] as [$id, $name, $delegation, $zone]) {
            $this->commercial($id, $name);
            $this->snapshot($id, $delegation, $zone, '2026-05-01');
        }

        $this->seedPerformanceMetrics('005-a', 'Ana', 20, 25, 10, 8);
        $this->seedPerformanceMetrics('005-b', 'Bea', 20, 15, 5, 3);
        $this->seedPerformanceMetrics('005-c', 'Cris', 10, 10, 4, 2);

        $items = collect($this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->json('items'))
            ->keyBy('commercial_id');
        $ana = $items['005-a'];

        $this->assertSame(7.5, $ana['team_average_reservations']);
        $this->assertSame(2.5, $ana['team_reservations_deviation']);
        $this->assertSame(33.33, $ana['team_reservations_deviation_pct']);
        $this->assertSame(37.5, $ana['team_lead_to_reservation_pct']);
        $this->assertSame(12.5, $ana['lead_to_reservation_vs_team_pp']);
        $this->assertSame(37.5, $ana['team_opportunity_to_reservation_pct']);
        $this->assertSame(2.5, $ana['opportunity_to_reservation_vs_team_pp']);
        $this->assertSame(73.33, $ana['team_reservation_to_sale_pct']);
        $this->assertSame(6.67, $ana['reservation_to_sale_vs_team_pp']);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08&zone=Zona%20Mediterraneo')
            ->assertOk()
            ->assertJsonCount(2, 'items');
        $zoneItems = collect($this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08&zone=Zona%20Mediterraneo')
            ->assertOk()->json('items'))->keyBy('commercial_id');
        $this->assertSame(7.5, $zoneItems['005-a']['team_average_reservations']);
        $this->assertSame(37.5, $zoneItems['005-a']['team_lead_to_reservation_pct']);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08&delegation=Murcia')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.commercial_id', '005-c')
            ->assertJsonPath('items.0.team_average_reservations', 4)
            ->assertJsonPath('items.0.team_opportunity_to_reservation_pct', 40);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08&zone=Zona%20Mediterraneo&delegation=Alicante')
            ->assertOk()
            ->assertJsonCount(2, 'items');

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08&commercial=005-a')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.ranking', 1)
            ->assertJsonPath('items.0.team_average_reservations', 7.5)
            ->assertJsonPath('items.0.team_reservation_to_sale_pct', 73.33)
            ->assertJsonPath('items.0.reservation_to_sale_vs_team_pp', 6.67)
            ->assertJsonPath('universe.evaluable_commercials', 3)
            ->assertJsonPath('universe.global_target', 54)
            ->assertJsonPath('universe.global_fulfillment_pct', 35.19);
    }

    public function test_ranking_denso_conserva_empates_orden_y_referencias_al_filtrar_comercial(): void
    {
        Cache::flush();
        $dataset = app(CommercialPerformanceDatasetService::class);
        $dataset->payload(['month' => '2026-08']);
        $dataset->updateTarget('2026-08', 10, null);

        foreach ([
            ['005-rank-a', 'Ana ranking', 10],
            ['005-rank-b', 'Bea ranking', 10],
            ['005-rank-c', 'Cris ranking', 8],
        ] as [$id, $name, $reservations]) {
            $this->commercial($id, $name);
            $this->snapshot($id, 'Alicante', 'Zona Mediterraneo', '2026-05-01');
            $this->seedPerformanceMetrics($id, $name, 10, 10, $reservations, 0);
        }

        $unfiltered = $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('objective.reservations_target', 10)
            ->json('items');
        $byId = collect($unfiltered)->keyBy('commercial_id');

        $this->assertSame([
            ['commercial_id' => '005-rank-a', 'ranking' => 1, 'fulfillment_pct' => 100],
            ['commercial_id' => '005-rank-b', 'ranking' => 1, 'fulfillment_pct' => 100],
            ['commercial_id' => '005-rank-c', 'ranking' => 2, 'fulfillment_pct' => 80],
        ], collect($unfiltered)->map(fn (array $row): array => [
            'commercial_id' => $row['commercial_id'],
            'ranking' => $row['ranking'],
            'fulfillment_pct' => $row['fulfillment_pct'],
        ])->all());

        $filtered = $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08&commercial=005-rank-b')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.ranking', 1)
            ->json('items.0');

        $this->assertSame($byId['005-rank-b']['team_average_reservations'], $filtered['team_average_reservations']);
        $this->assertSame($byId['005-rank-b']['team_lead_to_reservation_pct'], $filtered['team_lead_to_reservation_pct']);
    }

    public function test_cumplimiento_agregado_suma_objetivos_individuales_en_resumen_evolucion_y_filtro(): void
    {
        foreach ([['005-target-a', 'Objetivo A'], ['005-target-b', 'Objetivo B']] as [$commercialId, $name]) {
            $this->commercial($commercialId, $name);
            $this->snapshot($commercialId, 'Alicante', 'Zona Mediterraneo', '2026-05-01');

            foreach (range(1, 18) as $index) {
                $this->opportunity("006-july-{$commercialId}-{$index}", [
                    'owner_id' => $commercialId, 'owner_name' => $name,
                    'created_date' => '2026-07-05 10:00:00',
                    'reservation' => true, 'reservation_date' => '2026-07-05',
                ]);
            }

            $augustReservations = $commercialId === '005-target-a' ? 9 : 18;
            foreach (range(1, $augustReservations) as $index) {
                $this->opportunity("006-august-{$commercialId}-{$index}", [
                    'owner_id' => $commercialId, 'owner_name' => $name,
                    'created_date' => '2026-08-05 10:00:00',
                    'reservation' => true, 'reservation_date' => '2026-08-05',
                ]);
            }
        }

        $response = $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('summary.reservations_total', 27)
            ->assertJsonPath('summary.objective', 36)
            ->assertJsonPath('summary.fulfillment_pct', 75)
            ->assertJsonPath('universe.global_fulfillment_pct', 75);
        $july = collect($response->json('evolution'))->firstWhere('month', '2026-07');
        $this->assertSame(36, $july['reservations_total']);
        $this->assertSame(36, $july['objective']);
        $this->assertSame(100, $july['fulfillment_pct']);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-07')
            ->assertOk()
            ->assertJsonPath('summary.reservations_total', 36)
            ->assertJsonPath('summary.objective', 36)
            ->assertJsonPath('summary.fulfillment_pct', 100);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08&commercial=005-target-a')
            ->assertOk()
            ->assertJsonPath('summary.reservations_total', 9)
            ->assertJsonPath('summary.objective', 18)
            ->assertJsonPath('summary.fulfillment_pct', 50)
            ->assertJsonPath('items.0.objective', 18)
            ->assertJsonPath('items.0.fulfillment_pct', 50)
            ->assertJsonPath('universe.global_target', 36)
            ->assertJsonPath('universe.global_fulfillment_pct', 75);
    }

    public function test_margen_medio_usa_solo_ventas_con_margen_informado(): void
    {
        $this->commercial('005-margin', 'Comercial margen');
        $this->snapshot('005-margin', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        foreach ([2000, 1500, null] as $index => $margin) {
            $this->opportunity('006-margin-'.$index, [
                'owner_id' => '005-margin', 'owner_name' => 'Comercial margen',
                'cv_signed' => true, 'cv_signed_date' => '2026-08-10',
                'informe_rentabilidad' => $margin,
            ]);
        }

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('items.0.sales', 3)
            ->assertJsonPath('items.0.margin_total', 3500)
            ->assertJsonPath('items.0.average_margin_per_sale', 1750)
            ->assertJsonPath('items.0.sales_with_margin', 2)
            ->assertJsonPath('items.0.sales_without_margin', 1);
    }

    public function test_objetivo_es_mensual_tiene_default_y_validacion_protegida(): void
    {
        $this->assertSame(18, CommercialPerformanceMonthlyTarget::DEFAULT_RESERVATIONS_TARGET);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('objective.reservations_target', 18)
            ->assertJsonPath('objective.is_explicit', false);

        $this->putJson('/informes/reservas-ventas/data/commercial-performance/target', [
            'month' => '2026-08',
            'reservations_target' => 20,
        ])->assertOk()->assertJsonPath('reservations_target', 20);

        $this->assertDatabaseHas('commercial_performance_monthly_targets', [
            'month' => '2026-08-01',
            'reservations_target' => 20,
            'is_explicit' => true,
        ]);
        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-07')
            ->assertOk()->assertJsonPath('objective.reservations_target', 18);
        app(CommercialPerformanceDatasetService::class)->updateTarget('2026-07', 23, null);
        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-07')
            ->assertOk()
            ->assertJsonPath('objective.reservations_target', 23)
            ->assertJsonPath('objective.is_explicit', true);
        foreach (['zone=Zona%20Norte', 'delegation=Bilbao', 'commercial=005-filter'] as $filter) {
            $this->getJson("/informes/reservas-ventas/data/commercial-performance?month=2026-08&{$filter}")
                ->assertOk()
                ->assertJsonPath('objective.reservations_target', 20);
        }
        $this->assertDatabaseHas('commercial_performance_monthly_targets', [
            'month' => '2026-08-01',
            'reservations_target' => 20,
        ]);
        $this->putJson('/informes/reservas-ventas/data/commercial-performance/target', [
            'month' => '2026-08', 'reservations_target' => 0,
        ])->assertUnprocessable();
    }

    public function test_permisos_separan_lectura_objetivo_y_auditoria_por_rol(): void
    {
        $admin = $this->reportUser(ReportUser::ROLE_ADMIN, 'admin-performance@example.test');
        $this->withSession($this->sessionFor($admin))
            ->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk();
        $this->withSession($this->sessionFor($admin))
            ->putJson('/informes/reservas-ventas/data/commercial-performance/target', [
                'month' => '2026-08', 'reservations_target' => 21,
            ])->assertOk();

        $director = $this->reportUser(ReportUser::ROLE_DIRECTOR, 'director-performance@example.test');
        $this->withSession($this->sessionFor($director))
            ->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('objective.reservations_target', 21);
        $this->withSession($this->sessionFor($director))
            ->putJson('/informes/reservas-ventas/data/commercial-performance/target', [
                'month' => '2026-08', 'reservations_target' => 30,
            ])->assertForbidden();
        $this->withSession($this->sessionFor($director))
            ->getJson('/informes/reservas-ventas/data/commercial-performance/audit?month=2026-08')
            ->assertOk();

        $areaManager = $this->reportUser(ReportUser::ROLE_AREA_MANAGER, 'area-performance@example.test', [
            'area_zone' => 'mediterranean',
        ]);
        $this->withSession($this->sessionFor($areaManager))
            ->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('objective.reservations_target', 21);
        $this->withSession($this->sessionFor($areaManager))
            ->putJson('/informes/reservas-ventas/data/commercial-performance/target', [
                'month' => '2026-08', 'reservations_target' => 30,
            ])->assertForbidden();
        $this->withSession($this->sessionFor($areaManager))
            ->getJson('/informes/reservas-ventas/data/commercial-performance/audit?month=2026-08')
            ->assertForbidden();

        $viewer = $this->reportUser(ReportUser::ROLE_VIEWER, 'viewer-performance@example.test');
        $this->withSession($this->sessionFor($viewer))
            ->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertForbidden();
        $this->withSession($this->sessionFor($viewer))
            ->putJson('/informes/reservas-ventas/data/commercial-performance/target', [
                'month' => '2026-08', 'reservations_target' => 30,
            ])->assertForbidden();
        $this->withSession($this->sessionFor($viewer))
            ->getJson('/informes/reservas-ventas/data/commercial-performance/audit?month=2026-08')
            ->assertForbidden();

        $delegationManager = $this->reportUser(ReportUser::ROLE_DELEGATION_MANAGER, 'delegation-performance@example.test');
        $this->withSession($this->sessionFor($delegationManager))
            ->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertForbidden();

        $this->assertDatabaseHas('commercial_performance_monthly_targets', [
            'month' => '2026-08-01',
            'reservations_target' => 21,
            'updated_by_report_user_id' => $admin->id,
        ]);
    }

    public function test_area_manager_queda_limitado_server_side_a_su_zona_y_sin_zona_falla_cerrado(): void
    {
        foreach ([
            ['005-area-own', 'Comercial propio', 'Alicante', 'Zona Mediterraneo'],
            ['005-area-other', 'Comercial ajeno', 'Bilbao', 'Zona Norte'],
            ['005-area-other-2', 'Comercial ajeno 2', 'Bilbao', 'Zona Norte'],
        ] as [$id, $name, $delegation, $zone]) {
            $this->commercial($id, $name);
            $this->snapshot($id, $delegation, $zone, '2026-05-01');
            $this->seedPerformanceMetrics($id, $name, 2, 2, 1, 0);
        }
        foreach ([
            ['006-area-quality-other', '005-area-other', 'Comercial ajeno'],
            ['006-area-quality-other-2', '005-area-other-2', 'Comercial ajeno 2'],
        ] as [$id, $ownerId, $ownerName]) {
            $this->opportunity($id, [
                'owner_id' => $ownerId,
                'owner_name' => $ownerName,
                'reservation' => true,
                'reservation_date' => '2026-08-15',
                'vehicle_interest_id' => '01t-area-quality-other-zone',
            ]);
        }
        $coverageStart = CarbonImmutable::parse('2026-08-01', 'Europe/Madrid')->utc();
        SalesforceOpportunityStageTransition::query()->create([
            'salesforce_history_id' => '0Jh-area-quality-unresolved',
            'opportunity_salesforce_id' => '006-area-quality-not-local',
            'previous_stage' => 'Reserva',
            'new_stage' => 'Cerrada Perdida',
            'transitioned_at' => '2026-08-20 10:00:00',
            'reservation_date' => null,
            'owner_id' => '005-area-other',
            'owner_name' => 'Comercial ajeno',
            'source' => 'OpportunityHistory',
            'is_reservation_cancellation' => false,
            'quality_status' => 'opportunity_not_local',
            'synced_at' => now(),
        ]);
        SalesforceOpportunityHistorySyncInterval::query()->create([
            'range_start' => $coverageStart,
            'range_end' => $coverageStart->addMonth(),
            'completed_at' => now(),
            'queried_rows' => 1,
            'unresolved_dependencies' => 1,
            'is_kpi_certified' => false,
        ]);

        $director = $this->reportUser(ReportUser::ROLE_DIRECTOR, 'director-area-quality@example.test');
        $globalPayload = $this->withSession($this->sessionFor($director))
            ->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('data_quality.duplicate_conflict_groups', 1)
            ->assertJsonPath('data_quality.observed_assignments', 3)
            ->assertJsonPath('data_quality.cancellation_unresolved_dependencies', 1)
            ->assertJsonPath('data_quality.cancellation_coverage_by_month.2026-08.unresolved_dependencies', 1)
            ->json();
        $this->assertSame(1, $globalPayload['data_incident']['reservations_total']);

        $areaManager = $this->reportUser(ReportUser::ROLE_AREA_MANAGER, 'area-scope@example.test', [
            'area_zone' => 'mediterranean',
        ]);
        $session = $this->sessionFor($areaManager);

        $payload = $this->withSession($session)
            ->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.commercial_id', '005-area-own')
            ->assertJsonPath('items.0.zone', 'Zona Mediterraneo')
            ->assertJsonPath('filters.zones.0', 'Zona Mediterraneo')
            ->assertJsonCount(1, 'filters.zones')
            ->assertJsonCount(1, 'filters.delegations')
            ->assertJsonCount(1, 'filters.commercials')
            ->assertJsonPath('data_quality.unresolved_attribution_events', null)
            ->assertJsonPath('data_quality.duplicate_conflict_groups', null)
            ->assertJsonPath('data_quality.uncertified_historical_events', null)
            ->assertJsonPath('data_quality.margin_conflict_groups', null)
            ->assertJsonPath('data_quality.invalid_cancellation_chronology', null)
            ->assertJsonPath('data_quality.organisation_changes_within_month', null)
            ->assertJsonPath('data_quality.cancellation_unresolved_dependencies', null)
            ->assertJsonPath('data_quality.cancellation_coverage_by_month.2026-08.unresolved_dependencies', null)
            ->assertJsonPath('data_quality.bootstrap_approved_assignments', 0)
            ->assertJsonPath('data_quality.observed_assignments', 1)
            ->assertJsonPath('data_quality.cancellations_available', false)
            ->assertJsonPath('data_incident', null)
            ->assertJsonPath('summary.leads', 2)
            ->assertJsonPath('summary.opportunities', 2)
            ->assertJsonPath('summary.reservations_total', 1)
            ->assertJsonPath('universe.evaluable_commercials', 1)
            ->assertJsonPath('universe.global_target', 18)
            ->json();
        $this->assertStringNotContainsString('005-area-other', json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertSame(18, $payload['objective']['reservations_target']);
        $globalCoverage = $globalPayload['data_quality']['cancellation_coverage_by_month']['2026-08'];
        $areaCoverage = $payload['data_quality']['cancellation_coverage_by_month']['2026-08'];
        foreach (['status', 'range_start', 'range_end', 'source_cutoff_at', 'certified_until'] as $key) {
            $this->assertArrayHasKey($key, $areaCoverage);
            $this->assertSame($globalCoverage[$key], $areaCoverage[$key]);
        }
        $this->assertArrayHasKey('unresolved_dependencies', $areaCoverage);
        $this->assertNull($areaCoverage['unresolved_dependencies']);
        $augustEvolution = collect($payload['evolution'])->firstWhere('month', '2026-08');
        $this->assertSame(2, $augustEvolution['leads']);
        $this->assertSame(2, $augustEvolution['opportunities']);
        $this->assertSame(1, $augustEvolution['reservations_total']);

        $this->withSession($session)
            ->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08&delegation=Alicante')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.commercial_id', '005-area-own');
        $this->withSession($session)
            ->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08&commercial=005-area-other')
            ->assertOk()
            ->assertJsonCount(0, 'items');
        $this->withSession($session)
            ->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08&zone=Zona%20Norte')
            ->assertOk()
            ->assertJsonCount(0, 'items')
            ->assertJsonPath('filters.zones.0', 'Zona Mediterraneo');

        $withoutZone = $this->reportUser(ReportUser::ROLE_AREA_MANAGER, 'area-without-zone@example.test');
        $this->withSession($this->sessionFor($withoutZone))
            ->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertForbidden();
    }

    public function test_migracion_usa_identificadores_mysql_validos_y_conserva_fk_y_unique(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_25_120000_create_commercial_performance_foundation.php'));
        $identifiers = [
            'commercial_performance_monthly_targets_month_unique',
            'commercial_perf_target_updated_user_fk',
            'commercial_deleg_snapshot_user_from_uq',
            'commercial_deleg_snapshot_single_open_uq',
            'commercial_deleg_snapshot_user_until_idx',
            'commercial_deleg_snapshot_deleg_from_idx',
            'sf_opp_stage_history_uq',
            'sf_opp_stage_transition_date_stage_idx',
            'sf_opp_stage_transition_opp_date_idx',
            'sf_opp_stage_transition_owner_date_idx',
            'sf_opp_stage_transition_valid_date_idx',
            'sf_opp_history_interval_range_uq',
            'sf_opp_history_interval_coverage_idx',
            'sf_opps_last_modified_idx',
        ];

        $this->assertStringContainsString("foreign('updated_by_report_user_id', 'commercial_perf_target_updated_user_fk')", $migration);
        $this->assertStringContainsString("unique('salesforce_history_id', 'sf_opp_stage_history_uq')", $migration);
        foreach ($identifiers as $identifier) {
            $this->assertLessThanOrEqual(64, strlen($identifier), $identifier);
        }

        $updater = $this->reportUser(ReportUser::ROLE_ADMIN, 'target-updater@example.test');
        $target = CommercialPerformanceMonthlyTarget::query()->create([
            'month' => '2026-08-01',
            'reservations_target' => 18,
            'is_explicit' => true,
            'updated_by_report_user_id' => $updater->id,
        ]);
        $updater->delete();
        $this->assertNull($target->fresh()->updated_by_report_user_id);

        SalesforceOpportunityStageTransition::query()->create([
            'salesforce_history_id' => '0Jh-unique-schema',
            'opportunity_salesforce_id' => '006-unique-schema',
            'new_stage' => 'Cerrada Perdida',
            'transitioned_at' => '2026-08-20 10:00:00',
            'source' => 'OpportunityHistory',
            'quality_status' => 'reservation_not_demonstrated',
            'synced_at' => now(),
        ]);

        try {
            SalesforceOpportunityStageTransition::query()->create([
                'salesforce_history_id' => '0Jh-unique-schema',
                'opportunity_salesforce_id' => '006-other-opportunity',
                'new_stage' => 'Cerrada Perdida',
                'transitioned_at' => '2026-08-21 10:00:00',
                'source' => 'OpportunityHistory',
                'quality_status' => 'reservation_not_demonstrated',
                'synced_at' => now(),
            ]);
            $this->fail('salesforce_history_id debe conservar una restricción UNIQUE.');
        } catch (QueryException) {
            $this->assertDatabaseCount('salesforce_opportunity_stage_transitions', 1);
        }
    }

    public function test_dashboard_reutiliza_un_unico_bloque_y_controles_de_organizacion(): void
    {
        $director = $this->reportUser(ReportUser::ROLE_DIRECTOR, 'director-ui-performance@example.test');
        $html = $this->withSession($this->sessionFor($director))
            ->get('/informes/reservas-ventas')
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, 'id="reportFilters"'));
        $this->assertSame(1, substr_count($html, 'id="zone"'));
        $this->assertSame(1, substr_count($html, 'id="commercialDelegation"'));
        $this->assertSame(1, substr_count($html, 'id="commercial"'));
        $this->assertSame(1, substr_count($html, 'id="resetFilters"'));
        $this->assertSame(1, substr_count($html, 'id="performanceMonth"'));
        $this->assertSame(1, substr_count($html, 'id="performanceTarget"'));
        $this->assertStringContainsString('Objetivo mensual por comercial', $html);
        $this->assertStringContainsString('independientemente de zona, delegación o comercial', $html);
        $this->assertMatchesRegularExpression('/id="performanceTarget"[^>]+readonly/', $html);
        $this->assertStringNotContainsString('id="savePerformanceTarget"', $html);
        $this->assertStringContainsString('id="loadPerformanceAudit"', $html);
        $this->assertStringNotContainsString('performance-filters', $html);
        $this->assertStringNotContainsString('performanceZone', $html);
        $this->assertStringNotContainsString('performanceDelegation', $html);
        $this->assertStringNotContainsString('performanceCommercial', $html);
        $this->assertStringContainsString('id="performanceColumnsButton"', $html);
        $this->assertStringContainsString('id="performanceSearch"', $html);
        $this->assertStringContainsString('id="performanceFreshness"', $html);
        $this->assertStringContainsString('Personalizar columnas', $html);
        $this->assertStringNotContainsString('Media reservas deleg.', $html);
        $this->assertStringNotContainsString('Media deleg.', $html);
        $this->assertStringNotContainsString('Carga bajo demanda.', $html);
        $this->assertStringNotContainsString('id="performanceAuditRows"', $html);
        $this->assertSame(2, substr_count($html, 'class="table-scroll-top is-hidden"'));

        $admin = $this->reportUser(ReportUser::ROLE_ADMIN, 'admin-ui-performance@example.test');
        $adminHtml = $this->withSession($this->sessionFor($admin))
            ->get('/informes/reservas-ventas')
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('id="savePerformanceTarget" disabled', $adminHtml);
        $this->assertStringContainsString('id="loadPerformanceAudit"', $adminHtml);

        $areaManager = $this->reportUser(ReportUser::ROLE_AREA_MANAGER, 'area-ui-performance@example.test', [
            'area_zone' => 'mediterranean',
        ]);
        $areaHtml = $this->withSession($this->sessionFor($areaManager))
            ->get('/informes/reservas-ventas')
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('panel-rendimiento-comercial', $areaHtml);
        $this->assertStringContainsString('id="performanceTarget"', $areaHtml);
        $this->assertStringNotContainsString('id="savePerformanceTarget"', $areaHtml);
        $this->assertStringNotContainsString('id="loadPerformanceAudit"', $areaHtml);

        $viewer = $this->reportUser(ReportUser::ROLE_VIEWER, 'viewer-ui-performance@example.test');
        $viewerHtml = $this->withSession($this->sessionFor($viewer))
            ->get('/informes/reservas-ventas')
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('id="performanceMonth"', $viewerHtml);
        $this->assertStringNotContainsString('id="performanceTarget"', $viewerHtml);
        $this->assertStringNotContainsString('panel-rendimiento-comercial', $viewerHtml);
    }

    public function test_dashboard_agrupa_informacion_secundaria_y_deja_fuera_avisos_operativos(): void
    {
        $director = $this->reportUser(ReportUser::ROLE_DIRECTOR, 'director-performance-context@example.test');
        $html = $this->withSession($this->sessionFor($director))
            ->get('/informes/reservas-ventas')
            ->assertOk()
            ->getContent();

        $detailsStart = strpos($html, '<details class="performance-data-context" id="performanceDataContext">');
        $detailsEnd = strpos($html, '</details>', $detailsStart);
        $this->assertNotFalse($detailsStart);
        $this->assertNotFalse($detailsEnd);

        $detailsHtml = substr($html, $detailsStart, $detailsEnd - $detailsStart);
        $this->assertStringContainsString('<summary class="performance-data-context__summary">', $detailsHtml);
        $this->assertStringContainsString('Información y calidad de datos', $detailsHtml);
        $this->assertStringNotContainsString(' open', substr($html, $detailsStart, 100));

        foreach ([
            'performanceSemantics',
            'performanceUniverse',
            'performanceFreshness',
            'performanceCancellationCoverage',
            'performanceDataIncident',
            'performanceQualityWarning',
        ] as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $detailsHtml, $id);
        }

        $this->assertStringNotContainsString('id="performanceLoadError"', $detailsHtml);
        $this->assertStringNotContainsString('id="performanceCurrentMonthNotice"', $detailsHtml);
        $this->assertLessThan($detailsStart, strpos($html, 'id="performanceLoadError"'));
        $this->assertLessThan($detailsStart, strpos($html, 'id="performanceCurrentMonthNotice"'));
    }

    public function test_aviso_compacto_solo_depende_de_cancelaciones_no_evaluables(): void
    {
        $javascript = file_get_contents(resource_path('js/reports/reservations-sales-dashboard.js'));
        $blade = file_get_contents(resource_path('views/reports/reservations-sales/index.blade.php'));
        $functionStart = strpos($javascript, 'function renderPerformanceLimitationNotice(quality)');
        $functionEnd = strpos($javascript, "\n}", $functionStart);
        $this->assertNotFalse($functionStart);
        $this->assertNotFalse($functionEnd);

        $function = substr($javascript, $functionStart, $functionEnd - $functionStart);
        $this->assertStringContainsString('quality.cancellations_available !== false', $function);
        $this->assertStringNotContainsString('data_incident', $function);
        $this->assertStringNotContainsString('uncertified_historical_events', $function);
        $this->assertStringNotContainsString('unresolved_attribution_events', $function);
        $this->assertStringContainsString("'performanceLimitationNotice'", $javascript);
        $this->assertStringContainsString("'performanceDataContextSummary'", $javascript);
        $this->assertStringContainsString(
            'Existen limitaciones de calidad o cobertura. Consulta “Información y calidad de datos”.',
            $blade,
        );

        $clearStart = strpos($javascript, 'function clearPerformancePresentation()');
        $clearEnd = strpos($javascript, 'function setPerformanceTargetState', $clearStart);
        $clearFunction = substr($javascript, $clearStart, $clearEnd - $clearStart);
        $clearableIdsStart = strpos($clearFunction, "['performanceUniverse'");
        $clearableIdsEnd = strpos($clearFunction, '].forEach', $clearableIdsStart);
        $clearableIds = substr($clearFunction, $clearableIdsStart, $clearableIdsEnd - $clearableIdsStart);
        $this->assertStringContainsString("'performanceCancellationCoverage'", $clearFunction);
        $this->assertStringContainsString("'performanceDataContextSummary'", $clearFunction);
        $this->assertStringNotContainsString("'performanceLimitationNotice'", $clearableIds);
        $this->assertStringContainsString(
            "document.getElementById('performanceLimitationNotice')?.classList.add('is-hidden');",
            $clearFunction,
        );
        $this->assertStringNotContainsString('performanceLimitationNotice.textContent', $clearFunction);
        $this->assertStringNotContainsString('.open', $clearFunction);
    }

    public function test_dashboard_es_autonomo_del_css_legacy_y_conserva_primitives_y_hooks_semanticos(): void
    {
        $director = $this->reportUser(ReportUser::ROLE_DIRECTOR, 'director-design-system@example.test');
        $html = $this->withSession($this->sessionFor($director))
            ->get('/informes/reservas-ventas')
            ->assertOk()
            ->getContent();
        $blade = file_get_contents(resource_path('views/reports/reservations-sales/index.blade.php'));
        $css = file_get_contents(resource_path('css/reports/reservations-sales-dashboard.css'));

        $this->assertStringContainsString('class="report-ui-page-header"', $html);
        $this->assertStringContainsString('class="report-ui-tabs"', $html);
        $this->assertStringContainsString('class="report-ui-tab active is-active" data-report-tab', $html);
        $this->assertStringContainsString('class="report-filters report-ui-filter-bar"', $html);
        $this->assertStringContainsString('class="report-ui-kpi-strip"', $html);
        $this->assertStringContainsString('class="report-ui-data-panel"', $html);
        $this->assertStringContainsString('class="performance-table report-ui-table report-ui-table--sticky-header"', $html);
        $this->assertStringContainsString('data-filter-scope="standard"', $html);
        $this->assertStringContainsString('data-filter-scope="performance"', $html);
        $this->assertStringContainsString('data-report-panel', $html);
        $this->assertStringContainsString('is-hidden', $html);
        $this->assertStringNotContainsString('resources/css/reports/leads-dashboard.css', $blade);
        $this->assertStringContainsString('resources/css/reports/reservations-sales-dashboard.css', $blade);
        $this->assertStringContainsString('.performance-table [data-column="ranking"]', $css);
        $this->assertStringContainsString('.performance-table [data-column="traffic_light"]', $css);
        $this->assertStringContainsString('.performance-table [data-column="commercial"]', $css);
        $this->assertStringContainsString('--performance-ranking-sticky-width:', $css);
        $this->assertStringContainsString('--performance-status-sticky-width:', $css);
        $this->assertStringContainsString('position: sticky;', $css);

        $javascript = file_get_contents(resource_path('js/reports/reservations-sales-dashboard.js'));
        $this->assertStringContainsString("item.classList.remove('active', 'is-active')", $javascript);
        $this->assertStringContainsString("button.classList.add('active', 'is-active')", $javascript);
        $this->assertStringContainsString("document.querySelectorAll('[data-report-tab]')", $javascript);
        $this->assertStringContainsString("document.querySelectorAll('[data-report-panel]')", $javascript);
        $this->assertStringNotContainsString("document.querySelectorAll('.main-tab')", $javascript);
        $this->assertStringNotContainsString("document.querySelectorAll('.tab-panel')", $javascript);
        $tabsStart = strpos($html, '<nav class="report-ui-tabs"');
        $tabsEnd = strpos($html, '</nav>', $tabsStart);
        $tabsHtml = substr($html, $tabsStart, $tabsEnd - $tabsStart);
        $this->assertStringNotContainsString('aria-current', $tabsHtml);
        $this->assertStringNotContainsString('aria-current', $javascript);
    }

    public function test_javascript_despacha_un_solo_dataset_segun_modo_y_preserva_objetivo_al_limpiar(): void
    {
        $javascript = file_get_contents(resource_path('js/reports/reservations-sales-dashboard.js'));

        $this->assertStringNotContainsString('performanceZone', $javascript);
        $this->assertStringNotContainsString('performanceDelegation', $javascript);
        $this->assertStringNotContainsString('performanceCommercial', $javascript);
        $this->assertStringContainsString("setParam(params, 'zone', document.getElementById('zone').value)", $javascript);
        $this->assertStringContainsString("setParam(params, 'delegation', document.getElementById('commercialDelegation').value)", $javascript);
        $this->assertStringContainsString("setParam(params, 'commercial', document.getElementById('commercial').value)", $javascript);
        $this->assertStringContainsString('if (isCommercialPerformanceMode())', $javascript);
        $this->assertStringContainsString("isCommercialPerformanceMode() || document.getElementById('period')?.value !== 'custom'", $javascript);
        $this->assertStringContainsString("const performanceColumnsStorageKey = 'reservationsSalesCommercialPerformanceColumnsV5';", $javascript);
        $this->assertStringNotContainsString("const performanceColumnsStorageKey = 'reservationsSalesCommercialPerformanceColumnsV4';", $javascript);
        $this->assertStringNotContainsString("localStorage.removeItem('reservationsSalesCommercialPerformanceColumnsV4')", $javascript);
        $this->assertStringContainsString("{ key: 'ranking', label: 'Ranking', alwaysVisible: true }", $javascript);
        $this->assertStringContainsString("{ key: 'reservations_dropped', label: 'Reservas caídas', defaultVisible: true }", $javascript);
        $this->assertStringContainsString("{ key: 'sales_dropped', label: 'Ventas caídas', defaultVisible: true }", $javascript);
        $this->assertStringContainsString("{ key: 'traffic_light', label: 'Estado', alwaysVisible: true }", $javascript);
        $this->assertStringContainsString("{ key: 'commercial', label: 'Comercial', alwaysVisible: true }", $javascript);
        $this->assertStringContainsString("{ key: 'lead_to_reservation_vs_team', label: 'Ratio Interés → Reserva · Comparativa con su delegación' }", $javascript);
        $this->assertStringContainsString("{ key: 'opportunity_to_reservation_vs_team', label: 'Ratio Oportunidad → Reserva · Comparativa con su delegación' }", $javascript);
        $this->assertStringContainsString("{ key: 'reservation_to_sale_vs_team', label: 'Ratio Reserva → Venta · Comparativa con su delegación' }", $javascript);
        $this->assertStringContainsString("label: 'Resumen'", $javascript);
        $this->assertStringContainsString("label: 'Actividad'", $javascript);
        $this->assertStringContainsString("label: 'Rentabilidad'", $javascript);
        $this->assertStringContainsString('data-performance-column-preset', $javascript);
        $this->assertStringContainsString('setPerformanceVisibleColumns(preset.columns);', $javascript);
        $this->assertStringContainsString('formatAvailablePercent(row.sale_drop_pct)', $javascript);
        $this->assertStringContainsString('function formatFunnelAudit', $javascript);
        $this->assertStringContainsString('if (!applies) return \'-\';', $javascript);
        $this->assertStringContainsString("['classification_conflict', 'Conflicto de clasificación']", $javascript);
        $this->assertStringContainsString('Reserva no demostrada', $javascript);
        $this->assertStringContainsString('Datos insuficientes: sin fecha de reserva ni fecha de CV', $javascript);
        $this->assertStringContainsString('formatFulfillmentCalculation(summary.reservations_valid_for_objective, summary.objective, summary.fulfillment_pct)', $javascript);
        $this->assertStringContainsString('formatFulfillmentCalculation(universe.global_reservations_valid_for_objective, universe.global_target, universe.global_fulfillment_pct)', $javascript);
        $this->assertStringContainsString('reservas computables / ${formatNumber(target)} de objetivo = ${formatPercent(fulfillmentPct)}', $javascript);
        $this->assertStringContainsString('formatSummaryMarginCoverage(summary)', $javascript);
        $this->assertStringContainsString('formatMarginWithCoverage(row.margin_total, row.margin_coverage_pct)', $javascript);
        $this->assertStringContainsString('formatMarginWithCoverage(row.average_margin_per_sale, row.margin_coverage_pct)', $javascript);
        $this->assertStringContainsString('(salesWithMargin / sales) * 100', $javascript);
        $this->assertStringContainsString('data.data_quality?.cancellation_coverage_by_month || {}', $javascript);
        $this->assertStringContainsString('function formatEvolutionCancellations(row, coverage)', $javascript);
        $this->assertStringContainsString('<strong>N/D</strong>', $javascript);
        $this->assertStringContainsString('return formatNumber(row.cancellations);', $javascript);
        $this->assertStringContainsString('formatNumber(row.sales_dropped)', $javascript);
        $this->assertStringContainsString('function bindPerformanceSearch()', $javascript);
        $this->assertStringContainsString("document.querySelectorAll('#performanceRows tr[data-search]')", $javascript);
        $this->assertStringContainsString("return value === 'Zona Mediterraneo' ? 'Zona Mediterráneo' : value;", $javascript);
        $this->assertStringContainsString("covered: 'Histórico del período certificado'", $javascript);
        $this->assertStringContainsString("partial: 'Histórico del período parcialmente certificado'", $javascript);
        $this->assertStringContainsString("uncovered: 'Sin histórico certificado para todo el período'", $javascript);
        $this->assertStringContainsString("return labels[status] || 'Cobertura no determinada';", $javascript);
        $this->assertStringContainsString("data.dataset_source === 'local_snapshot'", $javascript);
        $this->assertStringContainsString("? 'Informe generado con la fotografía local'", $javascript);
        $this->assertStringContainsString('Datos de Salesforce sincronizados:', $javascript);
        $this->assertStringContainsString('function formatPerformanceMonth', $javascript);
        $this->assertStringContainsString("return value === null || value === undefined ? 'N/D'", $javascript);
        $this->assertStringContainsString('function invalidatePerformanceAudit', $javascript);
        $this->assertStringContainsString('function initPerformanceScrolls', $javascript);
        $this->assertStringContainsString("bootstrap_approved: 'Bootstrap aprobado'", $javascript);
        $this->assertStringContainsString("observed: 'Observada'", $javascript);
        $this->assertStringContainsString("not_certifiable: 'No certificable'", $javascript);
        $this->assertStringNotContainsString('delegation_average_reservations', $javascript);
        $this->assertStringNotContainsString('delegation_lead_to_reservation_pct', $javascript);
        $this->assertStringContainsString('function formatDelegationRatioComparison(individualRatio, delegationRatio, difference)', $javascript);
        $this->assertStringContainsString('Delegación: ${reference}', $javascript);
        $this->assertStringContainsString('↑ ${absolute} puntos porcentuales por encima', $javascript);
        $this->assertStringContainsString('↓ ${absolute} puntos porcentuales por debajo', $javascript);
        $this->assertStringContainsString('→ Igual que su delegación', $javascript);
        $this->assertStringContainsString('Comparativa no disponible', $javascript);
        $this->assertStringNotContainsString('function formatTeamRatioComparison', $javascript);
        $this->assertStringContainsString("toLocaleString('es-ES', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} %", $javascript);
        $this->assertStringContainsString("toLocaleString('es-ES', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} pp", $javascript);

        $blade = file_get_contents(resource_path('views/reports/reservations-sales/index.blade.php'));
        $this->assertStringContainsString('>Personalizar columnas</button>', $blade);
        $this->assertStringContainsString('placeholder="Filtrar por nombre"', $blade);
        $this->assertStringContainsString('data-column="traffic_light">Estado</th>', $blade);
        $this->assertStringContainsString('Ratios de actividad mensual: cada hito se imputa a su propia fecha. No son ratios de cohorte y pueden superar el 100 %.', $blade);
        $this->assertStringNotContainsString('Conversión Lead → Reserva', $blade);
        $this->assertStringNotContainsString('Conversión Oportunidad → Reserva', $blade);
        $this->assertStringNotContainsString('Conversión Reserva → Venta', $blade);

        $rowsStart = strpos($javascript, 'function renderPerformanceRows(rows)');
        $rowsEnd = strpos($javascript, 'function bindPerformanceSearch()', $rowsStart);
        $rowsFunction = substr($javascript, $rowsStart, $rowsEnd - $rowsStart);
        $this->assertStringNotContainsString('<small>${escapeHtml(row.commercial_id', $rowsFunction);
        $this->assertStringContainsString('row.commercial_id', $javascript);

        $resetBlock = substr($javascript, strpos($javascript, 'function bindResetFilters()'), strpos($javascript, 'function bindFilters()') - strpos($javascript, 'function bindResetFilters()'));
        $this->assertStringNotContainsString("document.getElementById('performanceTarget').value", $resetBlock);

        $searchStart = strpos($javascript, 'function applyPerformanceSearchFilter()');
        $searchEnd = strpos($javascript, 'function formatPerformanceZone', $searchStart);
        $searchBlock = substr($javascript, $searchStart, $searchEnd - $searchStart);
        $this->assertStringNotContainsString('fetch(', $searchBlock);
    }

    public function test_javascript_bloquea_objetivo_hasta_una_carga_de_rendimiento_valida(): void
    {
        $javascript = file_get_contents(resource_path('js/reports/reservations-sales-dashboard.js'));
        $html = $this->get('/informes/reservas-ventas')->assertOk()->getContent();

        $this->assertStringContainsString('function setPerformanceTargetState(state, value = null)', $javascript);
        $this->assertStringContainsString("setPerformanceTargetState('loading');", $javascript);
        $this->assertStringContainsString("setPerformanceTargetState('available', data.objective?.reservations_target);", $javascript);
        $this->assertStringContainsString('window.reportUserCanManageCommercialPerformanceTarget === true', $javascript);
        $this->assertStringContainsString('if (!button || !target || !performanceTargetAvailable || button.disabled || target.disabled) return;', $javascript);
        $this->assertStringNotContainsString('reservations_target ?? 18', $javascript);
        $this->assertMatchesRegularExpression('/id="performanceTarget"[^>]+disabled/', $html);
        $this->assertStringContainsString('id="savePerformanceTarget" disabled', $html);
    }

    public function test_rendimiento_comercial_usa_el_ultimo_mes_cerrado_en_europe_madrid_por_defecto(): void
    {
        $director = $this->reportUser(ReportUser::ROLE_DIRECTOR, 'director-closed-month@example.test');

        foreach ([
            ['2026-09-14 12:00:00', '2026-09', '2026-08'],
            ['2026-10-01 00:00:00', '2026-10', '2026-09'],
            ['2027-01-01 00:00:00', '2027-01', '2026-12'],
        ] as [$now, $currentMonth, $defaultMonth]) {
            CarbonImmutable::setTestNow(CarbonImmutable::parse($now, 'Europe/Madrid'));

            try {
                $html = $this->withSession($this->sessionFor($director))
                    ->get('/informes/reservas-ventas')
                    ->assertOk()
                    ->getContent();

                $this->assertStringContainsString(
                    "id=\"performanceMonth\" type=\"month\" value=\"{$defaultMonth}\" data-default-month=\"{$defaultMonth}\"",
                    $html,
                );
                $this->assertStringContainsString("window.commercialPerformanceCurrentMonth = \"{$currentMonth}\";", $html);
            } finally {
                CarbonImmutable::setTestNow();
            }
        }
    }

    public function test_mes_actual_mantiene_objetivo_completo_y_semaforo_sin_prorrateo(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 12:00:00', 'Europe/Madrid'));

        try {
            $this->commercial('005-current-month', 'Comercial mes actual');
            $this->snapshot('005-current-month', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
            for ($index = 1; $index <= 9; $index++) {
                $this->opportunity('006-current-month-'.$index, [
                    'owner_id' => '005-current-month',
                    'owner_name' => 'Comercial mes actual',
                    'created_date' => '2026-09-05 10:00:00',
                    'reservation' => true,
                    'reservation_date' => '2026-09-05',
                ]);
            }

            $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-09')
                ->assertOk()
                ->assertJsonPath('objective.reservations_target', 18)
                ->assertJsonPath('items.0.objective', 18)
                ->assertJsonPath('items.0.fulfillment_pct', 50)
                ->assertJsonPath('items.0.traffic_light', 'red')
                ->assertJsonPath('universe.global_target', 18)
                ->assertJsonPath('universe.global_fulfillment_pct', 50);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_javascript_identifica_solo_el_mes_actual_como_provisional(): void
    {
        $javascript = file_get_contents(resource_path('js/reports/reservations-sales-dashboard.js'));
        $html = $this->get('/informes/reservas-ventas')->assertOk()->getContent();

        $this->assertStringContainsString('id="performanceCurrentMonthNotice"', $html);
        $this->assertStringContainsString('function renderPerformanceCurrentMonthNotice(month)', $javascript);
        $this->assertStringContainsString('month === window.commercialPerformanceCurrentMonth', $javascript);
        $this->assertStringContainsString('Mes en curso · Resultado provisional.', $javascript);
        $this->assertStringContainsString('El objetivo mensual no se prorratea', $javascript);
        $this->assertStringContainsString('renderPerformanceCurrentMonthNotice(data.month);', $javascript);
        $this->assertStringContainsString("'performanceCurrentMonthNotice'", $javascript);
    }

    public function test_historico_sin_snapshot_no_inventa_delegacion_ni_ranking(): void
    {
        $this->commercial('005-historic', 'Histórico');
        $this->opportunity('006-historic', [
            'owner_id' => '005-historic',
            'owner_name' => 'Histórico',
            'reservation' => true,
            'reservation_date' => '2026-08-05',
        ]);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('items.0.delegation', 'Histórico no certificable')
            ->assertJsonPath('items.0.delegation_certified', false)
            ->assertJsonPath('items.0.delegation_status', 'not_certifiable')
            ->assertJsonPath('items.0.ranking', null)
            ->assertJsonPath('items.0.team_average_reservations', null)
            ->assertJsonPath('items.0.team_reservations_deviation', null)
            ->assertJsonPath('items.0.team_reservations_deviation_pct', null)
            ->assertJsonPath('items.0.team_lead_to_reservation_pct', null)
            ->assertJsonPath('items.0.lead_to_reservation_vs_team_pp', null)
            ->assertJsonPath('items.0.team_opportunity_to_reservation_pct', null)
            ->assertJsonPath('items.0.opportunity_to_reservation_vs_team_pp', null)
            ->assertJsonPath('items.0.team_reservation_to_sale_pct', null)
            ->assertJsonPath('items.0.reservation_to_sale_vs_team_pp', null)
            ->assertJsonPath('filters.commercials.0.id', '005-historic');

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08&commercial=005-historic')
            ->assertOk()
            ->assertJsonPath('summary.fulfillment_pct', null)
            ->assertJsonPath('universe.global_fulfillment_pct', null);
    }

    public function test_bootstrap_aprobado_habilita_zona_delegacion_y_ranking_sin_llamarlo_observado(): void
    {
        $this->commercial('005-bootstrap-filter', 'Comercial bootstrap');
        CommercialDelegationSnapshot::query()->create([
            'salesforce_user_id' => '005-bootstrap-filter', 'delegation' => 'Alicante', 'zone' => 'Zona Mediterraneo',
            'observed_from' => '2026-03-31 22:00:00', 'observed_until' => '2026-08-01 00:00:00',
            'source' => CommercialDelegationSnapshotService::SOURCE_BUSINESS_BOOTSTRAP,
        ]);
        CommercialDelegationSnapshot::query()->create([
            'salesforce_user_id' => '005-bootstrap-filter', 'delegation' => 'Alicante', 'zone' => 'Zona Mediterraneo',
            'observed_from' => '2026-08-01 00:00:00',
            'source' => CommercialDelegationSnapshotService::SOURCE_OBSERVED,
        ]);
        $this->interestFromLegacyFixture([
            'salesforce_id' => '00Q-bootstrap-filter', 'name' => 'Actividad bootstrap',
            'created_date' => '2026-07-01', 'fecha_asignacion' => '2026-07-05',
            'status' => 'Potencial', 'record_type_name' => 'Venta', 'record_type_normalized' => 'venta',
            'owner_id' => '005-bootstrap-filter', 'owner_name' => 'Comercial bootstrap', 'is_deleted' => false,
        ]);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-07')
            ->assertOk()
            ->assertJsonPath('items.0.delegation_status', 'bootstrap_approved')
            ->assertJsonPath('items.0.ranking', 1)
            ->assertJsonPath('filters.zones.0', 'Zona Mediterraneo')
            ->assertJsonPath('filters.delegations.0', 'Alicante')
            ->assertJsonPath('filters.commercials.0.id', '005-bootstrap-filter')
            ->assertJsonPath('data_quality.delegation_history_evaluable_from', '2026-03-31 22:00:00')
            ->assertJsonPath('data_quality.delegation_history_bootstrap_from', '2026-03-31 22:00:00')
            ->assertJsonPath('data_quality.delegation_history_observed_from', '2026-08-01 00:00:00');
    }

    public function test_universo_incluye_venta_cambio_lead_ayvens_y_excluye_tasacion(): void
    {
        $this->commercial('005-universe', 'Universo');
        $this->snapshot('005-universe', 'Alicante', 'Zona Mediterraneo', '2026-05-01');

        foreach ([
            ['00Q-venta', 'Venta', 'venta'],
            ['00Q-cambio', 'Venta con cambio', 'venta_con_cambio'],
            ['00Q-lead', 'Lead', 'lead'],
            ['00Q-ayvens', 'Ayvens', 'ayvens'],
            ['00Q-tasacion', 'Tasación', 'tasacion'],
        ] as [$id, $raw, $normalized]) {
            $this->interestFromLegacyFixture([
                'salesforce_id' => $id,
                'name' => $id,
                'created_date' => '2026-08-01 08:00:00',
                'fecha_asignacion' => '2026-08-02 10:00:00',
                'status' => 'Potencial',
                'record_type_name' => $raw,
                'record_type_normalized' => $normalized,
                'owner_id' => '005-universe',
                'owner_name' => 'Universo',
                'is_deleted' => false,
            ]);
        }

        $this->opportunity('006-sale', ['owner_id' => '005-universe', 'owner_name' => 'Universo']);
        $this->opportunity('006-change', ['owner_id' => '005-universe', 'owner_name' => 'Universo', 'record_type_name' => 'Cambio']);
        $this->opportunity('006-appraisal', ['owner_id' => '005-universe', 'owner_name' => 'Universo', 'record_type_name' => 'Tasacion']);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('items.0.leads', 4)
            ->assertJsonPath('items.0.opportunities', 2);
    }

    public function test_intervalos_preservan_cambio_de_delegacion_e_inactivo_con_actividad(): void
    {
        SalesforceUser::query()->create([
            'salesforce_id' => '005-moved',
            'name' => 'Comercial inactivo',
            'profile_name' => 'Compra/Venta',
            'user_delegation' => 'HR MOTOR MURCIA',
            'is_active' => false,
        ]);
        CommercialDelegationSnapshot::query()->create([
            'salesforce_user_id' => '005-moved', 'delegation' => 'Alicante', 'zone' => 'Zona Mediterraneo',
            'observed_from' => '2026-01-01', 'observed_until' => '2026-07-31 22:00:00', 'source' => 'test',
        ]);
        CommercialDelegationSnapshot::query()->create([
            'salesforce_user_id' => '005-moved', 'delegation' => 'Murcia', 'zone' => 'Zona Mediterraneo',
            'observed_from' => '2026-07-31 22:00:00', 'source' => 'test',
        ]);
        $this->opportunity('006-june', [
            'owner_id' => '005-moved', 'owner_name' => 'Comercial inactivo',
            'created_date' => '2026-06-05', 'reservation' => true, 'reservation_date' => '2026-06-06',
        ]);
        $this->opportunity('006-august', [
            'owner_id' => '005-moved', 'owner_name' => 'Comercial inactivo',
            'created_date' => '2026-08-05', 'reservation' => true, 'reservation_date' => '2026-08-06',
        ]);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-06')
            ->assertOk()->assertJsonPath('items.0.delegation', 'Alicante');
        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()->assertJsonPath('items.0.delegation', 'Murcia');
    }

    public function test_cambio_de_perfil_no_elimina_actividad_historica_ni_crea_roster_futuro(): void
    {
        $this->commercial('005-historical-profile', 'Histórico tras perfil');
        $this->snapshot('005-historical-profile', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        $this->interestFromLegacyFixture([
            'salesforce_id' => '00Q-historical-profile', 'name' => 'Lead histórico',
            'created_date' => '2026-06-05 10:00:00', 'fecha_asignacion' => '2026-06-05 10:00:00',
            'status' => 'Potencial', 'record_type_name' => 'Venta', 'record_type_normalized' => 'venta',
            'owner_id' => '005-historical-profile', 'owner_name' => 'Histórico tras perfil', 'is_deleted' => false,
        ]);
        $this->opportunity('006-historical-profile', [
            'owner_id' => '005-historical-profile', 'owner_name' => 'Histórico tras perfil',
            'created_date' => '2026-06-06 10:00:00', 'reservation' => true, 'reservation_date' => '2026-06-10',
        ]);
        SalesforceUser::query()->where('salesforce_id', '005-historical-profile')->update(['profile_name' => 'Marketing']);
        app(CommercialDelegationSnapshotService::class)
            ->captureCurrentUsers(CarbonImmutable::parse('2026-08-01 00:00:00', 'UTC'));

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-06')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.commercial_id', '005-historical-profile')
            ->assertJsonPath('items.0.commercial', 'Histórico tras perfil')
            ->assertJsonPath('items.0.delegation', 'Alicante')
            ->assertJsonPath('items.0.leads', 1)
            ->assertJsonPath('items.0.opportunities', 1)
            ->assertJsonPath('items.0.reservations_total', 1);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-09')
            ->assertOk()
            ->assertJsonCount(0, 'items');
    }

    public function test_responsables_no_comerciales_quedan_en_incidencia_sin_objetivo_y_auditables(): void
    {
        foreach ([
            ['005-api-user', 'API User', 'API User'],
            ['005-marketing-user', 'Marketing', 'Marketing'],
            ['005-admin-user', 'Administrador', 'System Administrator'],
        ] as [$id, $name, $profile]) {
            SalesforceUser::query()->create([
                'salesforce_id' => $id, 'name' => $name, 'profile_name' => $profile,
                'user_delegation' => null, 'is_active' => true,
            ]);
        }
        $this->opportunity('006-api-user', [
            'owner_id' => '005-api-user', 'owner_name' => 'API User',
            'created_date' => '2026-08-02 10:00:00',
        ]);
        $this->opportunity('006-marketing-user', [
            'owner_id' => '005-marketing-user', 'owner_name' => 'Marketing',
            'created_date' => '2026-08-03 10:00:00',
            'reservation' => true, 'reservation_date' => '2026-08-04',
        ]);
        $this->opportunity('006-admin-user', [
            'owner_id' => '005-admin-user', 'owner_name' => 'Administrador',
            'created_date' => '2026-08-05 10:00:00',
            'cv_signed' => true, 'cv_signed_date' => '2026-08-06', 'stage_name' => 'Contrato',
        ]);
        SalesforceOpportunityStageTransition::query()->create([
            'salesforce_history_id' => '0Jh-api-user',
            'opportunity_salesforce_id' => '006-api-user',
            'previous_stage' => 'Reserva', 'new_stage' => 'Cerrada Perdida',
            'transitioned_at' => '2026-08-07 10:00:00', 'reservation_date' => '2026-08-01',
            'owner_id' => '005-api-user', 'owner_name' => 'API User',
            'source' => 'OpportunityHistory', 'is_reservation_cancellation' => true,
            'quality_status' => 'valid', 'synced_at' => now(),
        ]);
        $this->coverHistoryMonth('2026-08');

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonCount(0, 'items')
            ->assertJsonPath('data_incident.opportunities', 3)
            ->assertJsonPath('data_incident.reservations_total', 1)
            ->assertJsonPath('data_incident.sales', 1)
            ->assertJsonPath('data_incident.cancellations', 1)
            ->assertJsonPath('summary.objective', 0)
            ->assertJsonPath('summary.fulfillment_pct', null);

        $audit = collect($this->getJson('/informes/reservas-ventas/data/commercial-performance/audit?month=2026-08')
            ->assertOk()->json('items'));
        foreach (['006-api-user', '006-marketing-user', '006-admin-user'] as $opportunityId) {
            $rows = $audit->where('opportunity_id', $opportunityId);
            $this->assertNotEmpty($rows);
            $this->assertTrue($rows->every(fn (array $row): bool => $row['counted_in_metric'] === false));
            $this->assertTrue($rows->contains(fn (array $row): bool => $row['exclusion_reason'] === 'non_commercial_responsible'));
        }
    }

    public function test_summary_reconcilia_actividad_comercial_e_incidencia_sin_mostrarla_en_tabla(): void
    {
        $this->commercial('005-summary', 'Comercial resumen');
        $this->snapshot('005-summary', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        $this->opportunity('006-summary-commercial', [
            'owner_id' => '005-summary', 'owner_name' => 'Comercial resumen',
            'reservation' => true, 'reservation_date' => '2026-08-10',
        ]);
        $this->opportunity('006-summary-incident', [
            'owner_id' => '005-summary-missing', 'owner_name' => 'Responsable no resoluble',
            'reservation' => true, 'reservation_date' => '2026-08-11',
        ]);

        $payload = $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->json();
        $currentMonth = collect($payload['evolution'])->firstWhere('month', '2026-08');

        $this->assertCount(1, $payload['items']);
        $this->assertSame('005-summary', $payload['items'][0]['commercial_id']);
        $this->assertSame(1, $payload['items'][0]['reservations_total']);
        $this->assertSame(1, $payload['data_incident']['reservations_total']);
        $this->assertNull($payload['data_incident']['objective']);
        $this->assertNull($payload['data_incident']['fulfillment_pct']);
        $this->assertSame(2, $payload['summary']['reservations_total']);
        $this->assertSame(2, $currentMonth['reservations_total']);
        $this->assertSame(18, $payload['universe']['global_target']);
        $this->assertSame(5.56, $payload['universe']['global_fulfillment_pct']);
    }

    public function test_duplicado_con_atribucion_conflictiva_cuenta_una_vez_y_sale_del_ranking(): void
    {
        foreach ([['005-a', 'Ana'], ['005-b', 'Bea']] as [$id, $name]) {
            $this->commercial($id, $name);
            $this->snapshot($id, 'Alicante', 'Zona Mediterraneo', '2026-05-01');
            $this->opportunity('006-'.$id, [
                'owner_id' => $id,
                'owner_name' => $name,
                'reservation' => true,
                'reservation_date' => '2026-08-10',
                'vehicle_interest_id' => '01t-duplicate-performance',
            ]);
        }

        $response = $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('summary.reservations_total', 1)
            ->assertJsonPath('data_quality.duplicate_conflict_groups', 1);
        $incident = $response->json('data_incident');
        $this->assertSame(1, $incident['reservations_total']);
        $this->assertNull($incident['objective']);
        $this->assertNull($incident['fulfillment_pct']);

        $auditRows = collect($this->getJson('/informes/reservas-ventas/data/commercial-performance/audit?month=2026-08')
            ->assertOk()->json('items'))->where('event_type', 'reservation');
        $this->assertCount(2, $auditRows);
        $this->assertSame(1, $auditRows->where('counted_in_metric', true)->count());
        $this->assertSame('data_quality_incident', $auditRows->firstWhere('counted_in_metric', true)['metric_attribution']);
    }

    public function test_eventos_antes_y_despues_del_primer_snapshot_mantienen_una_fila_y_un_objetivo(): void
    {
        $this->commercial('005-partial', 'Parcial');
        $this->snapshot('005-partial', 'Alicante', 'Zona Mediterraneo', '2026-08-15');
        foreach ([['006-before', '2026-08-05'], ['006-after', '2026-08-20']] as [$id, $date]) {
            $this->opportunity($id, [
                'owner_id' => '005-partial', 'owner_name' => 'Parcial',
                'created_date' => $date, 'reservation' => true, 'reservation_date' => $date,
            ]);
        }

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.commercial_id', '005-partial')
            ->assertJsonPath('items.0.reservations_total', 2)
            ->assertJsonPath('items.0.objective', null)
            ->assertJsonPath('items.0.fulfillment_pct', null)
            ->assertJsonPath('items.0.traffic_light', null)
            ->assertJsonPath('items.0.evaluation_status', 'not_evaluable')
            ->assertJsonPath('items.0.delegation_certified', false)
            ->assertJsonPath('items.0.ranking', null);
    }

    public function test_cambio_de_delegacion_dentro_del_mes_no_duplica_fila_ni_objetivo(): void
    {
        $this->commercial('005-change', 'Cambio interno');
        CommercialDelegationSnapshot::query()->create([
            'salesforce_user_id' => '005-change', 'delegation' => 'Alicante', 'zone' => 'Zona Mediterraneo',
            'observed_from' => '2026-07-31 22:00:00', 'observed_until' => '2026-08-15 00:00:00', 'source' => 'test',
        ]);
        CommercialDelegationSnapshot::query()->create([
            'salesforce_user_id' => '005-change', 'delegation' => 'Murcia', 'zone' => 'Zona Mediterraneo',
            'observed_from' => '2026-08-15 00:00:00', 'observed_until' => '2026-08-31 22:00:00', 'source' => 'test',
        ]);
        foreach ([['006-old-team', '2026-08-05'], ['006-new-team', '2026-08-20']] as [$id, $date]) {
            $this->opportunity($id, [
                'owner_id' => '005-change', 'owner_name' => 'Cambio interno',
                'created_date' => $date, 'reservation' => true, 'reservation_date' => $date,
            ]);
        }

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.reservations_total', 2)
            ->assertJsonPath('items.0.objective', null)
            ->assertJsonPath('items.0.fulfillment_pct', null)
            ->assertJsonPath('items.0.traffic_light', null)
            ->assertJsonPath('items.0.evaluation_status', 'not_evaluable')
            ->assertJsonPath('items.0.delegation', 'Histórico no certificable')
            ->assertJsonPath('items.0.ranking', null);
    }

    public function test_roster_certificado_sin_actividad_se_excluye_de_tabla_objetivo_ranking_y_filtro(): void
    {
        $this->commercial('005-zero', 'Sin actividad');
        $this->snapshot('005-zero', 'Alicante', 'Zona Mediterraneo', '2026-05-01');

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonCount(0, 'items')
            ->assertJsonCount(0, 'filters.commercials')
            ->assertJsonPath('universe.evaluable_commercials', 0)
            ->assertJsonPath('universe.excluded_no_activity_commercials', 1)
            ->assertJsonPath('universe.global_target', 0)
            ->assertJsonPath('universe.global_fulfillment_pct', null);
    }

    public function test_partner_community_con_actividad_y_snapshot_valido_es_evaluable(): void
    {
        SalesforceUser::query()->create([
            'salesforce_id' => '005-partner', 'name' => 'Partner histórico',
            'profile_name' => 'Comerciales Partner Community', 'user_delegation' => 'Alicante', 'is_active' => false,
        ]);
        $this->snapshot('005-partner', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        $this->interestFromLegacyFixture([
            'salesforce_id' => '00Q-partner', 'name' => 'Lead partner',
            'created_date' => '2026-08-01', 'fecha_asignacion' => '2026-08-05',
            'status' => 'Potencial', 'record_type_name' => 'Venta', 'record_type_normalized' => 'venta',
            'owner_id' => '005-partner', 'owner_name' => 'Partner histórico', 'is_deleted' => false,
        ]);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('items.0.commercial_id', '005-partner')
            ->assertJsonPath('items.0.evaluable', true)
            ->assertJsonPath('items.0.objective', 18)
            ->assertJsonPath('items.0.ranking', 1);
    }

    public function test_cancelacion_aislada_no_activa_objetivo_ni_ranking(): void
    {
        $this->commercial('005-cancellation-only', 'Solo cancelación');
        $this->snapshot('005-cancellation-only', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        SalesforceOpportunityStageTransition::query()->create([
            'salesforce_history_id' => '0Jh-cancellation-only', 'opportunity_salesforce_id' => '006-no-local-cancellation',
            'previous_stage' => 'Reserva', 'new_stage' => 'Cerrada Perdida', 'transitioned_at' => '2026-08-10 10:00:00',
            'reservation_date' => '2026-07-20', 'owner_id' => '005-cancellation-only', 'owner_name' => 'Solo cancelación',
            'source' => 'OpportunityHistory', 'is_reservation_cancellation' => true, 'quality_status' => 'valid', 'synced_at' => now(),
        ]);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonCount(0, 'items')
            ->assertJsonPath('universe.excluded_no_activity_commercials', 1)
            ->assertJsonPath('universe.global_target', 0);
    }

    public function test_comparativas_equipo_mantienen_null_con_denominadores_cero(): void
    {
        $this->commercial('005-zero-denominators', 'Sin denominadores');
        $this->snapshot('005-zero-denominators', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        $this->opportunity('006-zero-denominators', [
            'owner_id' => '005-zero-denominators', 'owner_name' => 'Sin denominadores',
            'created_date' => '2026-07-01 10:00:00', 'cv_signed' => true,
            'cv_signed_date' => '2026-08-10', 'stage_name' => 'Contrato',
        ]);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('items.0.team_average_reservations', 0)
            ->assertJsonPath('items.0.team_reservations_deviation', 0)
            ->assertJsonPath('items.0.team_reservations_deviation_pct', null)
            ->assertJsonPath('items.0.team_lead_to_reservation_pct', null)
            ->assertJsonPath('items.0.lead_to_reservation_vs_team_pp', null)
            ->assertJsonPath('items.0.team_opportunity_to_reservation_pct', null)
            ->assertJsonPath('items.0.opportunity_to_reservation_vs_team_pp', null)
            ->assertJsonPath('items.0.team_reservation_to_sale_pct', null)
            ->assertJsonPath('items.0.reservation_to_sale_vs_team_pp', null);
    }

    public function test_mes_cubierto_sin_transiciones_muestra_cero_cancelaciones(): void
    {
        $this->commercial('005-covered-zero', 'Cubierto cero');
        $this->snapshot('005-covered-zero', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        $this->opportunity('006-covered-zero', [
            'owner_id' => '005-covered-zero', 'owner_name' => 'Cubierto cero',
            'reservation' => true, 'reservation_date' => '2026-08-05',
        ]);
        $this->coverHistoryMonth('2026-08');

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('data_quality.cancellation_coverage_status', 'covered')
            ->assertJsonPath('items.0.cancellations', 0)
            ->assertJsonPath('items.0.cancellation_pct', 0)
            ->assertJsonPath('items.0.delegation_cancellation_pct', 0);
    }

    public function test_mes_sin_cobertura_no_convierte_cancelaciones_en_cero(): void
    {
        $this->commercial('005-uncovered', 'Sin cobertura');
        $this->snapshot('005-uncovered', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        $this->opportunity('006-uncovered', [
            'owner_id' => '005-uncovered', 'owner_name' => 'Sin cobertura',
            'reservation' => true, 'reservation_date' => '2026-08-05',
        ]);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('data_quality.cancellation_coverage_status', 'uncovered')
            ->assertJsonPath('items.0.cancellations', null)
            ->assertJsonPath('items.0.cancellation_pct', null)
            ->assertJsonPath('items.0.delegation_cancellation_pct', null);
    }

    public function test_mes_con_cobertura_parcial_mantiene_cancelaciones_no_evaluables(): void
    {
        $this->commercial('005-partial-history', 'Cobertura parcial');
        $this->snapshot('005-partial-history', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        $this->opportunity('006-partial-history', [
            'owner_id' => '005-partial-history', 'owner_name' => 'Cobertura parcial',
            'reservation' => true, 'reservation_date' => '2026-08-05',
        ]);
        SalesforceOpportunityHistorySyncInterval::query()->create([
            'range_start' => '2026-08-01 22:00:00', 'range_end' => '2026-08-15 00:00:00',
            'completed_at' => now(), 'queried_rows' => 0,
        ]);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('data_quality.cancellation_coverage_status', 'partial')
            ->assertJsonPath('items.0.cancellations', null)
            ->assertJsonPath('items.0.cancellation_pct', null);
    }

    public function test_mes_actual_usa_el_ultimo_cutoff_sin_exigir_cobertura_hasta_la_consulta(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 15:00:00', 'Europe/Madrid'));
        $start = CarbonImmutable::parse('2026-08-01 00:00:00', 'Europe/Madrid')->utc();
        $cutoff = CarbonImmutable::parse('2026-08-26 07:10:00', 'Europe/Madrid')->utc();
        SalesforceOpportunityHistorySyncInterval::query()->create([
            'range_start' => $start, 'range_end' => $cutoff,
            'completed_at' => $cutoff, 'queried_rows' => 0,
        ]);

        $coverage = app(CommercialPerformanceDatasetService::class)
            ->historyCoverage(collect([CarbonImmutable::parse('2026-08-01', 'Europe/Madrid')]))['2026-08'];

        $this->assertSame('covered', $coverage['status']);
        $this->assertSame($cutoff->toIso8601String(), $coverage['source_cutoff_at']);
        $this->assertSame($cutoff->toIso8601String(), $coverage['certified_until']);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('data_quality.cancellations_available', true)
            ->assertJsonPath('data_quality.cancellation_certified_until', $cutoff->toIso8601String());
    }

    public function test_hueco_interno_antes_del_cutoff_deja_mes_actual_parcial(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 15:00:00', 'Europe/Madrid'));
        foreach ([
            ['2026-07-31 22:00:00', '2026-08-10 00:00:00'],
            ['2026-08-10 01:00:00', '2026-08-26 05:10:00'],
        ] as [$start, $end]) {
            SalesforceOpportunityHistorySyncInterval::query()->create([
                'range_start' => $start, 'range_end' => $end, 'completed_at' => $end, 'queried_rows' => 0,
            ]);
        }

        $coverage = app(CommercialPerformanceDatasetService::class)
            ->historyCoverage(collect([CarbonImmutable::parse('2026-08-01', 'Europe/Madrid')]))['2026-08'];

        $this->assertSame('partial', $coverage['status']);
        $this->assertSame('2026-08-10T00:00:00+00:00', $coverage['certified_until']);
    }

    public function test_mes_cerrado_exige_fin_completo_y_distingue_cobertura_incompleta_y_completa(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 15:00:00', 'Europe/Madrid'));
        $july = CarbonImmutable::parse('2026-07-01', 'Europe/Madrid');
        $start = $july->startOfMonth()->utc();
        $end = $july->addMonth()->startOfMonth()->utc();
        SalesforceOpportunityHistorySyncInterval::query()->create([
            'range_start' => $start, 'range_end' => $end->subHour(),
            'completed_at' => $end, 'queried_rows' => 0,
        ]);
        $service = app(CommercialPerformanceDatasetService::class);

        $this->assertSame('partial', $service->historyCoverage(collect([$july]))['2026-07']['status']);

        SalesforceOpportunityHistorySyncInterval::query()->create([
            'range_start' => $end->subHour(), 'range_end' => $end,
            'completed_at' => $end, 'queried_rows' => 0,
        ]);

        $this->assertSame('covered', $service->historyCoverage(collect([$july]))['2026-07']['status']);
    }

    public function test_dependencia_de_opportunity_no_resuelta_impide_cero_silencioso(): void
    {
        $this->commercial('005-unresolved-history', 'Dependencia pendiente');
        $this->snapshot('005-unresolved-history', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        $start = CarbonImmutable::parse('2026-08-01', 'Europe/Madrid')->utc();
        SalesforceOpportunityStageTransition::query()->create([
            'salesforce_history_id' => '0Jh-missing-opportunity',
            'opportunity_salesforce_id' => '006-reserved-in-july-not-local',
            'previous_stage' => 'Reserva', 'new_stage' => 'Cerrada Perdida',
            'transitioned_at' => '2026-08-20 10:00:00', 'reservation_date' => null,
            'source' => 'OpportunityHistory', 'is_reservation_cancellation' => false,
            'quality_status' => 'opportunity_not_local', 'synced_at' => now(),
        ]);
        SalesforceOpportunityHistorySyncInterval::query()->create([
            'range_start' => $start, 'range_end' => $start->addMonth(),
            'completed_at' => now(), 'queried_rows' => 1,
            'unresolved_dependencies' => 1, 'is_kpi_certified' => false,
        ]);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('data_quality.cancellations_available', false)
            ->assertJsonPath('data_quality.cancellation_unresolved_dependencies', 1)
            ->assertJsonPath('items.0.cancellations', null);
    }

    public function test_candidata_sin_etapa_previa_deja_cancelaciones_no_evaluables(): void
    {
        $this->commercial('005-no-previous-stage', 'Sin etapa previa');
        $this->snapshot('005-no-previous-stage', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        $start = CarbonImmutable::parse('2026-08-01', 'Europe/Madrid')->utc();
        SalesforceOpportunityStageTransition::query()->create([
            'salesforce_history_id' => '0Jh-no-previous-stage',
            'opportunity_salesforce_id' => '006-no-previous-stage',
            'previous_stage' => null, 'new_stage' => 'Cerrada Perdida',
            'transitioned_at' => '2026-08-20 10:00:00', 'reservation_date' => null,
            'source' => 'OpportunityHistory', 'is_reservation_cancellation' => false,
            'quality_status' => 'previous_stage_not_demonstrated', 'synced_at' => now(),
        ]);
        SalesforceOpportunityHistorySyncInterval::query()->create([
            'range_start' => $start, 'range_end' => $start->addMonth(),
            'completed_at' => now(), 'queried_rows' => 1,
            'unresolved_dependencies' => 1, 'is_kpi_certified' => false,
        ]);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('data_quality.cancellations_available', false)
            ->assertJsonPath('data_quality.cancellation_unresolved_dependencies', 1)
            ->assertJsonPath('items.0.cancellations', null);
    }

    public function test_intervalo_certificado_solapado_y_dependencia_resuelta_eliminan_deuda_antigua(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 15:00:00', 'Europe/Madrid'));
        $start = CarbonImmutable::parse('2026-08-01', 'Europe/Madrid')->utc();
        $cutoff = CarbonImmutable::parse('2026-08-26 10:00:00', 'Europe/Madrid')->utc();
        SalesforceOpportunityStageTransition::query()->create([
            'salesforce_history_id' => '0Jh-resolved-dependency',
            'opportunity_salesforce_id' => '006-resolved-dependency',
            'previous_stage' => 'Reserva', 'new_stage' => 'Cerrada Perdida',
            'transitioned_at' => '2026-08-10 10:00:00', 'reservation_date' => null,
            'source' => 'OpportunityHistory', 'is_reservation_cancellation' => false,
            'quality_status' => 'opportunity_not_local', 'synced_at' => now(),
        ]);
        SalesforceOpportunityHistorySyncInterval::query()->create([
            'range_start' => $start, 'range_end' => $start->addDays(15),
            'completed_at' => now(), 'queried_rows' => 1,
            'unresolved_dependencies' => 1, 'is_kpi_certified' => false,
        ]);
        SalesforceOpportunityHistorySyncInterval::query()->create([
            'range_start' => $start, 'range_end' => $cutoff,
            'completed_at' => now(), 'queried_rows' => 1,
            'unresolved_dependencies' => 0, 'is_kpi_certified' => true,
        ]);
        SalesforceOpportunityStageTransition::query()
            ->where('salesforce_history_id', '0Jh-resolved-dependency')
            ->update(['quality_status' => 'reservation_not_demonstrated', 'updated_at' => now()->addSecond()]);

        $coverage = app(CommercialPerformanceDatasetService::class)
            ->historyCoverage(collect([CarbonImmutable::parse('2026-08-01', 'Europe/Madrid')]))['2026-08'];

        $this->assertSame('covered', $coverage['status']);
        $this->assertSame(0, $coverage['unresolved_dependencies']);
        $this->assertSame($cutoff->toIso8601String(), $coverage['certified_until']);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('data_quality.cancellations_available', true)
            ->assertJsonPath('data_quality.cancellation_unresolved_dependencies', 0);
    }

    public function test_auditoria_restringida_expone_ids_atribucion_cobertura_y_exclusiones_sin_pii(): void
    {
        $this->commercial('005-audit', 'Auditable');
        $this->snapshot('005-audit', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        $this->interestFromLegacyFixture([
            'salesforce_id' => '00Q-audit', 'name' => 'PII que no debe salir',
            'created_date' => '2026-08-01', 'fecha_asignacion' => '2026-08-05',
            'status' => 'Potencial', 'record_type_name' => 'Venta', 'record_type_normalized' => 'venta',
            'owner_id' => '005-audit', 'owner_name' => 'Auditable', 'is_deleted' => false,
        ]);

        $response = $this->getJson('/informes/reservas-ventas/data/commercial-performance/audit?month=2026-08')
            ->assertOk()
            ->assertJsonPath('pii_excluded', true)
            ->assertJsonPath('items.0.interest_id', 'a01-audit')
            ->assertJsonMissingPath('items.0.lead_id')
            ->assertJsonPath('items.0.commercial_id', '005-audit')
            ->assertJsonPath('items.0.delegation', 'Alicante')
            ->assertJsonPath('items.0.delegation_status', 'observed')
            ->assertJsonPath('items.0.delegation_issue', null);
        $this->assertStringNotContainsString('PII que no debe salir', $response->getContent());
    }

    public function test_auditoria_aplica_zona_delegacion_y_comercial_antes_de_paginar(): void
    {
        foreach ([
            ['005-audit-alicante', 'Audit Alicante', 'Alicante', 'Zona Mediterraneo'],
            ['005-audit-murcia', 'Audit Murcia', 'Murcia', 'Zona Levante'],
        ] as [$id, $name, $delegation, $zone]) {
            $this->commercial($id, $name);
            $this->snapshot($id, $delegation, $zone, '2026-05-01');
        }
        foreach ([
            ['00Q-audit-alicante-1', '005-audit-alicante', 'Audit Alicante'],
            ['00Q-audit-alicante-2', '005-audit-alicante', 'Audit Alicante'],
            ['00Q-audit-murcia', '005-audit-murcia', 'Audit Murcia'],
            ['00Q-audit-incident', '005-missing-audit', 'No resoluble'],
        ] as [$id, $ownerId, $ownerName]) {
            $this->interestFromLegacyFixture([
                'salesforce_id' => $id, 'name' => 'Lead '.$id,
                'created_date' => '2026-08-01', 'fecha_asignacion' => '2026-08-05',
                'status' => 'Potencial', 'record_type_name' => 'Venta', 'record_type_normalized' => 'venta',
                'owner_id' => $ownerId, 'owner_name' => $ownerName, 'is_deleted' => false,
            ]);
        }

        $zone = $this->getJson('/informes/reservas-ventas/data/commercial-performance/audit?month=2026-08&zone=Zona%20Mediterraneo&per_page=1')
            ->assertOk()
            ->json();
        $delegation = $this->getJson('/informes/reservas-ventas/data/commercial-performance/audit?month=2026-08&delegation=Murcia')
            ->assertOk()
            ->json();
        $all = $this->getJson('/informes/reservas-ventas/data/commercial-performance/audit?month=2026-08&zone=Zona%20Mediterraneo&delegation=Alicante&commercial=005-audit-alicante')
            ->assertOk()
            ->json();

        $this->assertSame(2, $zone['pagination']['total']);
        $this->assertCount(1, $zone['items']);
        $this->assertTrue(collect($zone['items'])->every(fn (array $row): bool => $row['zone'] === 'Zona Mediterraneo'));
        $this->assertSame(1, $delegation['pagination']['total']);
        $this->assertSame('005-audit-murcia', $delegation['items'][0]['commercial_id']);
        $this->assertSame(2, $all['pagination']['total']);
        $this->assertTrue(collect($all['items'])->every(fn (array $row): bool => $row['commercial_id'] === '005-audit-alicante'));
    }

    public function test_auditoria_distingue_observacion_bootstrap_y_no_certificable(): void
    {
        foreach ([
            ['005-audit-observed', 'Audit observed'],
            ['005-audit-bootstrap', 'Audit bootstrap'],
            ['005-audit-uncertified', 'Audit uncertified'],
        ] as [$id, $name]) {
            $this->commercial($id, $name);
            $this->interestFromLegacyFixture([
                'salesforce_id' => '00Q-'.$id, 'name' => 'Dato excluido de respuesta',
                'created_date' => '2026-08-01', 'fecha_asignacion' => '2026-08-05',
                'status' => 'Potencial', 'record_type_name' => 'Venta', 'record_type_normalized' => 'venta',
                'owner_id' => $id, 'owner_name' => $name, 'is_deleted' => false,
            ]);
        }
        CommercialDelegationSnapshot::query()->create([
            'salesforce_user_id' => '005-audit-observed', 'delegation' => 'Alicante', 'zone' => 'Zona Mediterraneo',
            'observed_from' => '2026-07-31 22:00:00', 'source' => CommercialDelegationSnapshotService::SOURCE_OBSERVED,
        ]);
        CommercialDelegationSnapshot::query()->create([
            'salesforce_user_id' => '005-audit-bootstrap', 'delegation' => 'Alicante', 'zone' => 'Zona Mediterraneo',
            'observed_from' => '2026-03-31 22:00:00', 'observed_until' => '2026-08-15 00:00:00',
            'source' => CommercialDelegationSnapshotService::SOURCE_BUSINESS_BOOTSTRAP,
        ]);
        CommercialDelegationSnapshot::query()->create([
            'salesforce_user_id' => '005-audit-bootstrap', 'delegation' => 'Alicante', 'zone' => 'Zona Mediterraneo',
            'observed_from' => '2026-08-15 00:00:00', 'source' => CommercialDelegationSnapshotService::SOURCE_OBSERVED,
        ]);

        $items = collect($this->getJson('/informes/reservas-ventas/data/commercial-performance/audit?month=2026-08')
            ->assertOk()
            ->json('items'))
            ->keyBy('commercial_id');

        $this->assertSame('observed', $items['005-audit-observed']['delegation_status']);
        $this->assertTrue($items['005-audit-observed']['delegation_certified']);
        $this->assertTrue($items['005-audit-observed']['monthly_evaluable']);
        $this->assertTrue($items['005-audit-observed']['objective_applies']);
        $this->assertSame(18, $items['005-audit-observed']['monthly_objective']);
        $this->assertSame('bootstrap_approved', $items['005-audit-bootstrap']['delegation_status']);
        $this->assertTrue($items['005-audit-bootstrap']['delegation_certified']);
        $this->assertTrue($items['005-audit-bootstrap']['monthly_evaluable']);
        $this->assertSame('not_certifiable', $items['005-audit-uncertified']['delegation_status']);
        $this->assertSame('incomplete_history', $items['005-audit-uncertified']['delegation_issue']);
        $this->assertFalse($items['005-audit-uncertified']['monthly_evaluable']);
        $this->assertSame('not_evaluable', $items['005-audit-uncertified']['monthly_evaluation_status']);
        $this->assertFalse($items['005-audit-uncertified']['objective_applies']);
    }

    public function test_auditoria_directa_materializa_el_objetivo_mensual_por_defecto(): void
    {
        $this->commercial('005-audit-target-default', 'Auditoría objetivo default');
        $this->snapshot('005-audit-target-default', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        $this->interestFromLegacyFixture([
            'salesforce_id' => '00Q-audit-target-default', 'name' => 'Lead auditoría objetivo',
            'created_date' => '2026-08-01', 'fecha_asignacion' => '2026-08-05',
            'status' => 'Potencial', 'record_type_name' => 'Venta', 'record_type_normalized' => 'venta',
            'owner_id' => '005-audit-target-default', 'owner_name' => 'Auditoría objetivo default', 'is_deleted' => false,
        ]);
        $this->assertDatabaseMissing('commercial_performance_monthly_targets', ['month' => '2026-08-01']);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance/audit?month=2026-08')
            ->assertOk()
            ->assertJsonPath('items.0.monthly_objective', 18)
            ->assertJsonPath('items.0.monthly_evaluable', true);

        $this->assertDatabaseHas('commercial_performance_monthly_targets', [
            'month' => '2026-08-01',
            'reservations_target' => 18,
            'is_explicit' => false,
            'updated_by_report_user_id' => null,
        ]);
    }

    public function test_auditoria_no_sobrescribe_objetivo_mensual_explicito(): void
    {
        $this->commercial('005-audit-target-explicit', 'Auditoría objetivo explícito');
        $this->snapshot('005-audit-target-explicit', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        $now = now();
        DB::table('commercial_performance_monthly_targets')->insert([
            'month' => '2026-08-01',
            'reservations_target' => 22,
            'is_explicit' => true,
            'updated_by_report_user_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->interestFromLegacyFixture([
            'salesforce_id' => '00Q-audit-target-explicit', 'name' => 'Lead auditoría objetivo explícito',
            'created_date' => '2026-08-01', 'fecha_asignacion' => '2026-08-05',
            'status' => 'Potencial', 'record_type_name' => 'Venta', 'record_type_normalized' => 'venta',
            'owner_id' => '005-audit-target-explicit', 'owner_name' => 'Auditoría objetivo explícito', 'is_deleted' => false,
        ]);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance/audit?month=2026-08')
            ->assertOk()
            ->assertJsonPath('items.0.monthly_objective', 22);

        $this->assertDatabaseHas('commercial_performance_monthly_targets', [
            'month' => '2026-08-01',
            'reservations_target' => 22,
            'is_explicit' => true,
        ]);
        $this->assertDatabaseCount('commercial_performance_monthly_targets', 1);
    }

    public function test_auditoria_mantiene_evaluabilidad_mensual_en_evento_deduplicado(): void
    {
        $this->commercial('005-audit-deduplicated', 'Auditoría deduplicada');
        $this->snapshot('005-audit-deduplicated', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        foreach (['006-audit-deduplicated-a', '006-audit-deduplicated-b'] as $id) {
            $this->opportunity($id, [
                'owner_id' => '005-audit-deduplicated',
                'owner_name' => 'Auditoría deduplicada',
                'reservation' => true,
                'reservation_date' => '2026-08-10',
                'vehicle_interest_id' => '01t-audit-deduplicated',
            ]);
        }

        $deduplicated = collect($this->getJson('/informes/reservas-ventas/data/commercial-performance/audit?month=2026-08')
            ->assertOk()
            ->json('items'))
            ->first(fn (array $row): bool => $row['event_type'] === 'reservation' && ! $row['counted_in_metric']);

        $this->assertNotNull($deduplicated);
        $this->assertFalse($deduplicated['counted_in_metric']);
        $this->assertTrue($deduplicated['monthly_evaluable']);
        $this->assertSame('evaluable', $deduplicated['monthly_evaluation_status']);
        $this->assertTrue($deduplicated['objective_applies']);
        $this->assertSame(18, $deduplicated['monthly_objective']);
    }

    public function test_auditoria_de_agosto_excluye_exactamente_el_limite_superior(): void
    {
        $this->commercial('005-audit-boundary', 'Límite auditoría');
        $this->snapshot('005-audit-boundary', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        $this->opportunity('006-september-boundary', [
            'owner_id' => '005-audit-boundary', 'owner_name' => 'Límite auditoría',
            'created_date' => '2026-09-01 00:00:00',
        ]);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance/audit?month=2026-08')
            ->assertOk()
            ->assertJsonMissing(['opportunity_id' => '006-september-boundary']);
    }

    public function test_rendimiento_y_auditoria_excluyen_opportunity_eliminada_y_su_cancelacion(): void
    {
        $this->coverHistoryMonth('2026-08');
        $this->commercial('005-deleted-owner', 'Comercial eliminado');
        $this->snapshot('005-deleted-owner', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        $deleted = $this->opportunity('006-deleted-performance', [
            'owner_id' => '005-deleted-owner',
            'owner_name' => 'Comercial eliminado',
            'created_date' => '2026-08-02 10:00:00',
            'reservation' => true,
            'reservation_date' => '2026-08-03',
            'cv_signed' => true,
            'cv_signed_date' => '2026-08-04',
            'stage_name' => 'Cerrada Perdida',
            'is_deleted' => true,
            'deletion_detection_source' => 'query_all_deleted',
        ]);
        SalesforceOpportunityStageTransition::query()->create([
            'salesforce_history_id' => '0Jh-deleted-performance',
            'opportunity_salesforce_id' => $deleted->salesforce_id,
            'previous_stage' => 'Reserva',
            'new_stage' => 'Cerrada Perdida',
            'transitioned_at' => '2026-08-05 11:00:00',
            'reservation_date' => '2026-08-03',
            'owner_id' => '005-deleted-owner',
            'owner_name' => 'Comercial eliminado',
            'source' => 'OpportunityHistory',
            'is_reservation_cancellation' => true,
            'quality_status' => 'valid',
            'synced_at' => now(),
        ]);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('summary.opportunities', 0)
            ->assertJsonPath('summary.reservations_total', 0)
            ->assertJsonPath('summary.sales', 0)
            ->assertJsonPath('summary.cancellations', 0);
        $this->getJson('/informes/reservas-ventas/data/commercial-performance/audit?month=2026-08')
            ->assertOk()
            ->assertJsonMissing(['opportunity_id' => '006-deleted-performance']);
    }

    public function test_scheduler_evitar_solapes_y_deja_un_unico_propietario_de_snapshots(): void
    {
        $scheduler = file_get_contents(base_path('routes/console.php'));
        $opportunitiesCommand = file_get_contents(app_path('Console/Commands/SalesforceSyncOpportunitiesCommand.php'));
        $monthlyCommand = file_get_contents(app_path('Console/Commands/SalesforceSyncMonthlyCommercialCommand.php'));

        $this->assertStringContainsString("dailyAt('07:10')", $scheduler);
        $this->assertStringContainsString('salesforce:sync-opportunity-interest-direct --reason="Snapshot diario ROT-3 posterior al sync de Opportunities"', $scheduler);
        $this->assertStringContainsString("dailyAt('07:35')", $scheduler);
        $this->assertSame(1, substr_count($scheduler, 'salesforce:sync-opportunity-interest-direct'));
        $this->assertStringNotContainsString("dailyAt('02:45')", $scheduler);
        $this->assertStringNotContainsString('CommercialDelegationSnapshotService', $opportunitiesCommand);
        $this->assertStringNotContainsString('captureCurrentUsers', $opportunitiesCommand);
        $this->assertStringContainsString('CommercialDelegationSnapshotService', $monthlyCommand);
        $this->assertStringContainsString('captureCurrentUsers', $monthlyCommand);
        $this->assertStringNotContainsString('--bootstrap-performance-history', $scheduler);
    }

    public function test_funnel_clasifica_caidas_en_el_mes_de_reserva_y_excluye_cumplimiento(): void
    {
        Cache::flush();
        $this->commercial('005-funnel', 'Comercial funnel');
        $this->snapshot('005-funnel', 'Alicante', 'Zona Mediterraneo', '2026-05-01');

        $this->opportunity('006-reservation-dropped', [
            'owner_id' => '005-funnel', 'owner_name' => 'Comercial funnel',
            'reservation' => true, 'reservation_date' => '2026-08-20', 'stage_name' => 'Cerrada Perdida',
        ]);
        $this->opportunity('006-sale-dropped', [
            'owner_id' => '005-funnel', 'owner_name' => 'Comercial funnel',
            'reservation' => true, 'reservation_date' => '2026-08-21',
            'cv_signed' => true, 'cv_signed_date' => '2026-08-22', 'stage_name' => 'Cerrada Perdida',
        ]);
        $this->opportunity('006-reservation-active', [
            'owner_id' => '005-funnel', 'owner_name' => 'Comercial funnel',
            'reservation' => true, 'reservation_date' => '2026-08-23', 'stage_name' => 'Reserva',
        ]);
        $this->opportunity('006-sale-valid', [
            'owner_id' => '005-funnel', 'owner_name' => 'Comercial funnel',
            'reservation' => true, 'reservation_date' => '2026-07-20',
            'cv_signed' => true, 'cv_signed_date' => '2026-08-24', 'stage_name' => 'Contrato',
        ]);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('items.0.reservations_total', 3)
            ->assertJsonPath('items.0.reservations_active', 1)
            ->assertJsonPath('items.0.reservations_dropped', 1)
            ->assertJsonPath('items.0.sales', 1)
            ->assertJsonPath('items.0.sales_dropped', 1)
            ->assertJsonPath('items.0.reservations_valid_for_objective', 1)
            ->assertJsonPath('items.0.fulfillment_pct', 5.56)
            ->assertJsonPath('items.0.reservation_drop_pct', 33.33)
            ->assertJsonPath('items.0.sale_drop_pct', 100);
    }

    public function test_venta_caida_sin_reserva_demostrada_se_imputa_al_mes_cv_y_audita_la_excepcion(): void
    {
        Cache::flush();
        $this->commercial('005-sale-without-reservation', 'Venta sin reserva');
        $this->snapshot('005-sale-without-reservation', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        $this->opportunity('006-sale-without-reservation', [
            'owner_id' => '005-sale-without-reservation', 'owner_name' => 'Venta sin reserva',
            'reservation' => false, 'reservation_date' => null,
            'cv_signed' => true, 'cv_signed_date' => '2026-08-12', 'stage_name' => 'Cerrada Perdida',
        ]);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->assertJsonPath('items.0.reservations_total', 0)
            ->assertJsonPath('items.0.reservations_valid_for_objective', 0)
            ->assertJsonPath('items.0.sales_dropped', 1)
            ->assertJsonPath('items.0.sale_drop_pct', 100)
            ->assertJsonPath('items.0.fulfillment_pct', 0);

        $audit = collect($this->getJson('/informes/reservas-ventas/data/commercial-performance/audit?month=2026-08')
            ->assertOk()
            ->json('items'))
            ->firstWhere('event_type', 'sale_dropped');

        $this->assertSame('006-sale-without-reservation', $audit['opportunity_id']);
        $this->assertTrue($audit['counted_in_metric']);
        $this->assertTrue($audit['funnel']['sales_dropped']);
        $this->assertTrue($audit['funnel']['reservation_not_demonstrated']);
        $this->assertFalse($audit['funnel']['fulfillment_contribution']);
        $this->assertSame('reservation_not_demonstrated', $audit['funnel']['fulfillment_exclusion_reason']);
    }

    public function test_ratios_del_funnel_devuelven_null_con_denominadores_cero(): void
    {
        Cache::flush();
        $this->commercial('005-funnel-sale', 'Comercial solo venta');
        $this->snapshot('005-funnel-sale', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        $this->opportunity('006-funnel-sale', [
            'owner_id' => '005-funnel-sale',
            'owner_name' => 'Comercial solo venta',
            'created_date' => '2026-07-01 10:00:00',
            'cv_signed' => true,
            'cv_signed_date' => '2026-08-10',
            'stage_name' => 'Contrato',
        ]);

        $this->commercial('005-funnel-opportunity', 'Comercial solo oportunidad');
        $this->snapshot('005-funnel-opportunity', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        $this->opportunity('006-funnel-opportunity', [
            'owner_id' => '005-funnel-opportunity',
            'owner_name' => 'Comercial solo oportunidad',
            'created_date' => '2026-08-10 10:00:00',
        ]);

        $items = collect($this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')
            ->assertOk()
            ->json('items'))
            ->keyBy('commercial_id');

        $saleOnly = $items->get('005-funnel-sale');
        $this->assertNotNull($saleOnly);
        $this->assertSame(0, $saleOnly['leads']);
        $this->assertSame(0, $saleOnly['opportunities']);
        $this->assertSame(0, $saleOnly['reservations_total']);
        $this->assertSame(1, $saleOnly['sales']);
        $this->assertNull($saleOnly['lead_to_reservation_pct']);
        $this->assertNull($saleOnly['opportunity_to_reservation_pct']);
        $this->assertNull($saleOnly['reservation_to_sale_pct']);
        $this->assertNull($saleOnly['reservation_drop_pct']);

        $opportunityOnly = $items->get('005-funnel-opportunity');
        $this->assertNotNull($opportunityOnly);
        $this->assertSame(0, $opportunityOnly['sales_signed_reference']);
        $this->assertNull($opportunityOnly['sale_drop_pct']);
    }

    public function test_duplicado_reserva_viva_y_caida_excluye_clasificacion_ambigua(): void
    {
        Cache::flush();
        $this->commercial('005-conflict-reservation', 'Conflicto reserva');
        $this->snapshot('005-conflict-reservation', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        foreach ([['006-live', 'Reserva'], ['006-lost', 'Cerrada Perdida']] as [$id, $stage]) {
            $this->opportunity($id, ['owner_id' => '005-conflict-reservation', 'owner_name' => 'Conflicto reserva', 'reservation' => true, 'reservation_date' => '2026-08-10', 'stage_name' => $stage, 'vehicle_interest_id' => '01t-conflict-reservation']);
        }
        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')->assertOk()
            ->assertJsonPath('data_incident.reservations_total', 1)
            ->assertJsonPath('data_incident.reservations_active', 0)->assertJsonPath('data_incident.reservations_dropped', 0)
            ->assertJsonPath('data_incident.reservations_valid_for_objective', 0)->assertJsonPath('data_quality.duplicate_conflict_groups', 1);
    }

    public function test_ventas_caidas_duplicadas_no_duplican_kpi_denominador_ni_calidad(): void
    {
        Cache::flush();
        foreach (['006-drop-a', '006-drop-b'] as $id) {
            $this->opportunity($id, ['owner_id' => '005-missing', 'owner_name' => 'No resoluble', 'created_date' => '2025-01-01', 'cv_signed' => true, 'cv_signed_date' => '2026-08-10', 'stage_name' => 'Cerrada Perdida', 'vehicle_interest_id' => '01t-drop']);
        }
        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')->assertOk()
            ->assertJsonPath('data_incident.sales_dropped', 1)->assertJsonPath('data_incident.sales_signed_reference', 1)
            ->assertJsonPath('data_incident.objective', null)->assertJsonPath('data_incident.fulfillment_pct', null)
            ->assertJsonPath('data_quality.unresolved_attribution_events', 2);
    }

    public function test_duplicado_venta_valida_y_caida_excluye_clasificacion_ambigua(): void
    {
        Cache::flush();
        $this->commercial('005-conflict-sale', 'Conflicto venta');
        $this->snapshot('005-conflict-sale', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        foreach ([['006-sale-live', 'Contrato'], ['006-sale-lost', 'Cerrada Perdida']] as [$id, $stage]) {
            $this->opportunity($id, ['owner_id' => '005-conflict-sale', 'owner_name' => 'Conflicto venta', 'created_date' => '2025-01-01', 'cv_signed' => true, 'cv_signed_date' => '2026-08-10', 'stage_name' => $stage, 'vehicle_interest_id' => '01t-conflict-sale']);
        }

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')->assertOk()
            ->assertJsonPath('data_incident.sales', 0)
            ->assertJsonPath('data_incident.sales_dropped', 0)->assertJsonPath('data_incident.sales_signed_reference', 1)
            ->assertJsonPath('data_quality.duplicate_conflict_groups', 1);
    }

    public function test_venta_caida_no_resoluble_cuenta_calidad_una_vez(): void
    {
        Cache::flush();
        $this->opportunity('006-drop-unresolved', ['owner_id' => '005-missing', 'owner_name' => 'No resoluble', 'created_date' => '2025-01-01', 'cv_signed' => true, 'cv_signed_date' => '2026-08-10', 'stage_name' => 'Cerrada Perdida', 'vehicle_interest_id' => '01t-drop-unresolved']);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')->assertOk()
            ->assertJsonPath('data_incident.sales_dropped', 1)->assertJsonPath('data_incident.sales_signed_reference', 1)
            ->assertJsonPath('data_incident.objective', null)->assertJsonPath('data_incident.fulfillment_pct', null)
            ->assertJsonPath('data_quality.unresolved_attribution_events', 1);
    }

    public function test_venta_caida_no_certificable_cuenta_calidad_una_vez(): void
    {
        Cache::flush();
        $this->commercial('005-drop-uncertified', 'Venta no certificable');
        $this->opportunity('006-drop-uncertified', ['owner_id' => '005-drop-uncertified', 'owner_name' => 'Venta no certificable', 'created_date' => '2025-01-01', 'cv_signed' => true, 'cv_signed_date' => '2026-08-10', 'stage_name' => 'Cerrada Perdida', 'vehicle_interest_id' => '01t-drop-uncertified']);

        $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')->assertOk()
            ->assertJsonPath('items.0.sales_dropped', 1)->assertJsonPath('items.0.sales_signed_reference', 1)
            ->assertJsonPath('data_quality.uncertified_historical_events', 1);
    }

    public function test_ventas_caidas_duplicadas_con_conflicto_atribucion_reportan_un_solo_grupo(): void
    {
        Cache::flush();
        $this->commercial('005-drop-conflict-a', 'Venta caída A');
        $this->commercial('005-drop-conflict-b', 'Venta caída B');
        $this->snapshot('005-drop-conflict-a', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        $this->snapshot('005-drop-conflict-b', 'Murcia', 'Zona Levante', '2026-05-01');
        foreach ([['006-drop-conflict-a', '005-drop-conflict-a', 'Venta caída A'], ['006-drop-conflict-b', '005-drop-conflict-b', 'Venta caída B']] as [$id, $ownerId, $ownerName]) {
            $this->opportunity($id, ['owner_id' => $ownerId, 'owner_name' => $ownerName, 'created_date' => '2025-01-01', 'cv_signed' => true, 'cv_signed_date' => '2026-08-10', 'stage_name' => 'Cerrada Perdida', 'vehicle_interest_id' => '01t-drop-conflict']);
        }

        $payload = $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')->assertOk()->json();
        $incident = $payload['data_incident'];

        $this->assertSame(1, $incident['sales_dropped']);
        $this->assertSame(1, $incident['sales_signed_reference']);
        $this->assertSame(1, $payload['data_quality']['duplicate_conflict_groups']);
        $this->assertSame(0, $payload['data_quality']['unresolved_attribution_events']);
        $this->assertSame(0, $payload['data_quality']['uncertified_historical_events']);
    }

    public function test_conflicto_de_firma_con_reserva_demostrada_no_cuenta_para_cumplimiento(): void
    {
        Cache::flush();
        $this->commercial('005-conflict-sale-reservation', 'Conflicto firma');
        $this->snapshot('005-conflict-sale-reservation', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        foreach ([['006-sale-res-live', 'Contrato'], ['006-sale-res-lost', 'Cerrada Perdida']] as [$id, $stage]) {
            $this->opportunity($id, ['owner_id' => '005-conflict-sale-reservation', 'owner_name' => 'Conflicto firma', 'created_date' => '2025-01-01', 'reservation' => true, 'reservation_date' => '2026-08-05', 'cv_signed' => true, 'cv_signed_date' => '2026-08-10', 'stage_name' => $stage, 'vehicle_interest_id' => '01t-conflict-sale-reservation']);
        }

        $payload = $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')->assertOk()->json();
        $incident = $payload['data_incident'];

        $this->assertSame(1, $incident['reservations_total']);
        $this->assertSame(0, $incident['reservations_valid_for_objective']);
        $this->assertSame(0, $incident['reservations_active']);
        $this->assertSame(0, $incident['reservations_dropped']);
        $this->assertSame(0, $incident['sales']);
        $this->assertSame(0, $incident['sales_dropped']);
        $this->assertSame(1, $incident['sales_signed_reference']);
        $this->assertSame(1, $payload['data_quality']['duplicate_conflict_groups']);
    }

    public function test_conflicto_de_firma_fuera_del_mes_cv_invalida_reserva_del_mes(): void
    {
        Cache::flush();
        $this->commercial('005-cross-month-conflict', 'Conflicto entre meses');
        $this->snapshot('005-cross-month-conflict', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        foreach ([['006-cross-month-live', 'Contrato'], ['006-cross-month-lost', 'Cerrada Perdida']] as [$id, $stage]) {
            $this->opportunity($id, ['owner_id' => '005-cross-month-conflict', 'owner_name' => 'Conflicto entre meses', 'created_date' => '2025-01-01', 'reservation' => true, 'reservation_date' => '2026-08-05', 'cv_signed' => true, 'cv_signed_date' => '2026-09-05', 'stage_name' => $stage, 'vehicle_interest_id' => '01t-cross-month-conflict']);
        }

        $payload = $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')->assertOk()->json();
        $incident = $payload['data_incident'];

        $this->assertSame(1, $incident['reservations_total']);
        $this->assertSame(0, $incident['reservations_valid_for_objective']);
        $this->assertSame(0, $incident['reservations_active']);
        $this->assertSame(0, $incident['reservations_dropped']);
        $this->assertSame(0, $incident['sales']);
        $this->assertSame(0, $incident['sales_dropped']);
        $this->assertSame(1, $incident['sales_signed_reference']);
        $this->assertSame(1, $payload['data_quality']['duplicate_conflict_groups']);
    }

    public function test_auditoria_cross_month_excluye_venta_caida_con_conflicto_de_firma(): void
    {
        Cache::flush();
        $this->commercial('005-audit-cross-month-conflict', 'Auditoría conflicto entre meses');
        $this->snapshot('005-audit-cross-month-conflict', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        foreach ([['006-audit-cross-month-live', 'Contrato'], ['006-audit-cross-month-lost', 'Cerrada Perdida']] as [$id, $stage]) {
            $this->opportunity($id, ['owner_id' => '005-audit-cross-month-conflict', 'owner_name' => 'Auditoría conflicto entre meses', 'created_date' => '2025-01-01', 'reservation' => true, 'reservation_date' => '2026-08-05', 'cv_signed' => true, 'cv_signed_date' => '2026-09-05', 'stage_name' => $stage, 'vehicle_interest_id' => '01t-audit-cross-month-conflict']);
        }

        $audit = collect($this->getJson('/informes/reservas-ventas/data/commercial-performance/audit?month=2026-08')->assertOk()->json('items'));
        $droppedSale = $audit->firstWhere('event_type', 'sale_dropped');
        $reservations = $audit->where('event_type', 'reservation');

        $this->assertFalse($droppedSale['counted_in_metric']);
        $this->assertSame('data_quality_incident', $droppedSale['metric_attribution']);
        $this->assertTrue($droppedSale['classification_conflict']);
        $this->assertFalse($droppedSale['funnel']['sales_dropped']);
        $this->assertFalse($droppedSale['funnel']['fulfillment_contribution']);
        $this->assertSame('classification_conflict', $droppedSale['funnel']['fulfillment_exclusion_reason']);
        $this->assertCount(2, $reservations);
        $this->assertSame(1, $reservations->where('counted_in_metric', true)->count());
        $this->assertTrue($reservations->every(fn (array $row): bool => $row['classification_conflict']));
    }

    public function test_conflicto_de_firma_sin_reserva_no_deja_venta_auditable_contada(): void
    {
        Cache::flush();
        $this->commercial('005-audit-sale-no-reservation', 'Auditoría venta sin reserva');
        $this->snapshot('005-audit-sale-no-reservation', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        foreach ([['006-audit-sale-no-res-live', 'Contrato'], ['006-audit-sale-no-res-lost', 'Cerrada Perdida']] as [$id, $stage]) {
            $this->opportunity($id, ['owner_id' => '005-audit-sale-no-reservation', 'owner_name' => 'Auditoría venta sin reserva', 'created_date' => '2025-01-01', 'cv_signed' => true, 'cv_signed_date' => '2026-08-10', 'stage_name' => $stage, 'vehicle_interest_id' => '01t-audit-sale-no-reservation']);
        }

        $payload = $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')->assertOk()->json();
        $incident = $payload['data_incident'];
        $audit = collect($this->getJson('/informes/reservas-ventas/data/commercial-performance/audit?month=2026-08')->assertOk()->json('items'))
            ->filter(fn (array $row): bool => in_array($row['event_type'], ['sale', 'sale_dropped'], true));

        $this->assertSame(0, $incident['sales']);
        $this->assertSame(0, $incident['sales_dropped']);
        $this->assertSame(1, $incident['sales_signed_reference']);
        $this->assertCount(2, $audit);
        $this->assertTrue($audit->every(fn (array $row): bool => ! $row['counted_in_metric']));
        $this->assertTrue($audit->every(fn (array $row): bool => $row['classification_conflict']));
        $this->assertTrue($audit->every(fn (array $row): bool => $row['metric_attribution'] === 'data_quality_incident'));
        $this->assertTrue($audit->every(fn (array $row): bool => ! $row['funnel']['sales_valid'] && ! $row['funnel']['sales_dropped']));
    }

    public function test_conflicto_de_firma_no_colapsa_opportunities_ni_reservas(): void
    {
        Cache::flush();
        $this->commercial('005-audit-opportunity-conflict', 'Auditoría opportunity');
        $this->snapshot('005-audit-opportunity-conflict', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        foreach ([['006-audit-opportunity-live', 'Contrato'], ['006-audit-opportunity-lost', 'Cerrada Perdida']] as [$id, $stage]) {
            $this->opportunity($id, ['owner_id' => '005-audit-opportunity-conflict', 'owner_name' => 'Auditoría opportunity', 'created_date' => '2026-08-01', 'reservation' => true, 'reservation_date' => '2026-08-05', 'cv_signed' => true, 'cv_signed_date' => '2026-08-10', 'stage_name' => $stage, 'vehicle_interest_id' => '01t-audit-opportunity-conflict']);
        }

        $audit = collect($this->getJson('/informes/reservas-ventas/data/commercial-performance/audit?month=2026-08')->assertOk()->json('items'));
        $opportunities = $audit->where('event_type', 'opportunity');
        $reservations = $audit->where('event_type', 'reservation');
        $sales = $audit->filter(fn (array $row): bool => in_array($row['event_type'], ['sale', 'sale_dropped'], true));

        $this->assertCount(2, $opportunities);
        $this->assertSame(2, $opportunities->where('counted_in_metric', true)->count());
        $this->assertCount(2, $reservations);
        $this->assertSame(1, $reservations->where('counted_in_metric', true)->count());
        $this->assertTrue($reservations->every(fn (array $row): bool => $row['classification_conflict']));
        $this->assertTrue($reservations->every(fn (array $row): bool => ! $row['funnel']['reservations_active'] && ! $row['funnel']['reservations_dropped'] && ! $row['funnel']['reservations_valid_for_objective'] && ! $row['funnel']['fulfillment_contribution']));
        $this->assertCount(2, $sales);
        $this->assertTrue($sales->every(fn (array $row): bool => ! $row['counted_in_metric'] && $row['classification_conflict'] && $row['metric_attribution'] === 'data_quality_incident'));
    }

    public function test_record_type_excluido_no_contamina_conflicto_de_clasificacion(): void
    {
        Cache::flush();
        $this->commercial('005-audit-record-type', 'Auditoría RecordType');
        $this->snapshot('005-audit-record-type', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        $this->opportunity('006-audit-record-type-sale', ['owner_id' => '005-audit-record-type', 'owner_name' => 'Auditoría RecordType', 'record_type_name' => 'Venta', 'reservation' => true, 'reservation_date' => '2026-08-05', 'cv_signed' => true, 'cv_signed_date' => '2026-08-10', 'stage_name' => 'Contrato', 'vehicle_interest_id' => '01t-audit-record-type']);
        $this->opportunity('006-audit-record-type-excluded', ['owner_id' => '005-audit-record-type', 'owner_name' => 'Auditoría RecordType', 'record_type_name' => 'Tasación', 'reservation' => true, 'reservation_date' => '2026-08-05', 'cv_signed' => true, 'cv_signed_date' => '2026-08-10', 'stage_name' => 'Cerrada Perdida', 'vehicle_interest_id' => '01t-audit-record-type']);

        $payload = $this->getJson('/informes/reservas-ventas/data/commercial-performance?month=2026-08')->assertOk()->json();
        $commercial = collect($payload['items'])->firstWhere('commercial_id', '005-audit-record-type');
        $audit = collect($this->getJson('/informes/reservas-ventas/data/commercial-performance/audit?month=2026-08')->assertOk()->json('items'));
        $eligibleSale = $audit->first(fn (array $row): bool => $row['opportunity_id'] === '006-audit-record-type-sale' && $row['event_type'] === 'sale');
        $excluded = $audit->where('opportunity_id', '006-audit-record-type-excluded');

        $this->assertSame(1, $commercial['reservations_total']);
        $this->assertSame(1, $commercial['reservations_valid_for_objective']);
        $this->assertSame(1, $commercial['sales']);
        $this->assertSame(0, $commercial['sales_dropped']);
        $this->assertTrue($eligibleSale['counted_in_metric']);
        $this->assertFalse($eligibleSale['classification_conflict'] ?? false);
        $this->assertTrue($excluded->isNotEmpty());
        $this->assertTrue($excluded->every(fn (array $row): bool => ! $row['counted_in_metric'] && $row['exclusion_reason'] === 'record_type_excluded'));
        $this->assertTrue($excluded->every(fn (array $row): bool => ! ($row['classification_conflict'] ?? false)));
    }

    public function test_auditoria_marca_conflicto_de_clasificacion_de_reserva_viva_y_caida(): void
    {
        Cache::flush();
        $this->commercial('005-audit-classification-reservation', 'Auditoría reserva');
        $this->snapshot('005-audit-classification-reservation', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        foreach ([['006-audit-res-live', 'Reserva'], ['006-audit-res-lost', 'Cerrada Perdida']] as [$id, $stage]) {
            $this->opportunity($id, ['owner_id' => '005-audit-classification-reservation', 'owner_name' => 'Auditoría reserva', 'reservation' => true, 'reservation_date' => '2026-08-05', 'stage_name' => $stage, 'vehicle_interest_id' => '01t-audit-classification-reservation']);
        }

        $audit = collect($this->getJson('/informes/reservas-ventas/data/commercial-performance/audit?month=2026-08')->assertOk()->json('items'))
            ->where('event_type', 'reservation')->values();
        $this->assertCount(2, $audit);
        $this->assertSame(1, $audit->where('counted_in_metric', true)->count());
        $this->assertTrue($audit->every(fn (array $row): bool => $row['classification_conflict']));
        $this->assertTrue($audit->every(fn (array $row): bool => $row['metric_attribution'] === 'data_quality_incident'));
        $this->assertTrue($audit->every(fn (array $row): bool => $row['funnel']['classification_conflict']));
        $this->assertTrue($audit->every(fn (array $row): bool => ! $row['funnel']['reservations_active']));
    }

    public function test_auditoria_marca_conflicto_de_clasificacion_de_venta_con_reserva(): void
    {
        Cache::flush();
        $this->commercial('005-audit-classification-sale', 'Auditoría venta');
        $this->snapshot('005-audit-classification-sale', 'Alicante', 'Zona Mediterraneo', '2026-05-01');
        foreach ([['006-audit-sale-live', 'Contrato'], ['006-audit-sale-lost', 'Cerrada Perdida']] as [$id, $stage]) {
            $this->opportunity($id, ['owner_id' => '005-audit-classification-sale', 'owner_name' => 'Auditoría venta', 'created_date' => '2025-01-01', 'reservation' => true, 'reservation_date' => '2026-08-05', 'cv_signed' => true, 'cv_signed_date' => '2026-08-10', 'stage_name' => $stage, 'vehicle_interest_id' => '01t-audit-classification-sale']);
        }

        $audit = collect($this->getJson('/informes/reservas-ventas/data/commercial-performance/audit?month=2026-08')->assertOk()->json('items'))
            ->filter(fn (array $row): bool => in_array($row['event_type'], ['sale', 'sale_dropped'], true))->values();
        $this->assertCount(2, $audit);
        $this->assertSame(0, $audit->where('counted_in_metric', true)->count());
        $this->assertTrue($audit->every(fn (array $row): bool => $row['classification_conflict']));
        $this->assertTrue($audit->every(fn (array $row): bool => $row['metric_attribution'] === 'data_quality_incident'));
        $this->assertTrue($audit->every(fn (array $row): bool => $row['funnel']['classification_conflict']));
        $this->assertTrue($audit->every(fn (array $row): bool => ! $row['funnel']['sales_valid'] && ! $row['funnel']['sales_dropped']));
    }

    private function commercial(string $id, string $name): void
    {
        SalesforceUser::query()->create([
            'salesforce_id' => $id,
            'name' => $name,
            'profile_name' => 'Compra/Venta',
            'user_delegation' => 'HR MOTOR ALICANTE',
            'is_active' => true,
        ]);
    }

    private function snapshot(string $id, string $delegation, string $zone, string $from): void
    {
        CommercialDelegationSnapshot::query()->create([
            'salesforce_user_id' => $id,
            'delegation' => $delegation,
            'zone' => $zone,
            'observed_from' => $from,
            'source' => 'test',
        ]);
    }

    private function coverHistoryMonth(string $month): void
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, 'Europe/Madrid')->startOfMonth()->utc();
        SalesforceOpportunityHistorySyncInterval::query()->create([
            'range_start' => $start,
            'range_end' => $start->addMonth(),
            'completed_at' => now(),
            'queried_rows' => 0,
        ]);
    }

    private function seedPerformanceMetrics(
        string $commercialId,
        string $commercialName,
        int $leads,
        int $opportunities,
        int $reservations,
        int $sales,
    ): void {
        for ($index = 1; $index <= $leads; $index++) {
            SalesforceInterest::query()->create([
                'salesforce_id' => "a01-{$commercialId}-{$index}",
                'salesforce_created_at' => '2026-08-02 10:00:00',
                'salesforce_last_modified_at' => '2026-08-02 10:00:00',
                'type' => 'Venta',
                'owner_salesforce_id' => $commercialId,
                'owner_name' => $commercialName,
                'is_deleted' => false,
            ]);
        }

        for ($index = 1; $index <= $opportunities; $index++) {
            $this->opportunity("006-{$commercialId}-{$index}", [
                'owner_id' => $commercialId,
                'owner_name' => $commercialName,
                'reservation' => $index <= $reservations,
                'reservation_date' => $index <= $reservations ? '2026-08-05' : null,
                'cv_signed' => $index <= $sales,
                'cv_signed_date' => $index <= $sales ? '2026-08-10' : null,
                'stage_name' => $index <= $sales ? 'Contrato' : 'Reserva',
            ]);
        }
    }

    private function interestFromLegacyFixture(array $attributes): SalesforceInterest
    {
        $functionalCreatedAt = $attributes['fecha_asignacion'] ?? $attributes['created_date'] ?? '2026-08-01 08:00:00';
        $normalizedType = strtolower((string) ($attributes['record_type_normalized'] ?? ''));
        $type = match ($normalizedType) {
            'venta' => 'Venta',
            'tasacion' => 'Tasación',
            default => $attributes['record_type_name'] ?? 'Venta',
        };

        return SalesforceInterest::query()->create([
            'salesforce_id' => str_replace('00Q', 'a01', (string) $attributes['salesforce_id']),
            'salesforce_created_at' => $functionalCreatedAt,
            'salesforce_last_modified_at' => $functionalCreatedAt,
            'status' => $attributes['status'] ?? null,
            'type' => $type,
            'owner_salesforce_id' => $attributes['owner_id'] ?? null,
            'owner_name' => $attributes['owner_name'] ?? null,
            'is_deleted' => (bool) ($attributes['is_deleted'] ?? false),
        ]);
    }

    private function opportunity(string $id, array $overrides = []): SalesforceOpportunity
    {
        return SalesforceOpportunity::query()->create(array_merge([
            'salesforce_id' => $id,
            'name' => $id,
            'created_date' => '2026-08-01 10:00:00',
            'record_type_name' => 'Venta',
            'stage_name' => 'Reserva',
            'owner_id' => '005-worker',
            'owner_name' => 'Comercial Worker',
            'owner_delegation' => 'Alicante',
            'reservation' => false,
            'cv_signed' => false,
        ], $overrides));
    }

    private function reportUser(string $role, string $email, array $attributes = []): ReportUser
    {
        return ReportUser::query()->create(array_merge([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => $role,
            'is_active' => true,
        ], $attributes));
    }

    private function sessionFor(ReportUser $user): array
    {
        return [
            'informes_authenticated' => true,
            'report_user_id' => $user->id,
            'report_user_role' => $user->role,
            'report_user_email' => $user->email,
        ];
    }
}
