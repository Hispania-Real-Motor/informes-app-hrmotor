<?php

namespace Tests\Feature;

use App\Models\ReportSyncRun;
use App\Services\Reports\CommercialCommissions\CommercialCommissionDashboardService;
use App\Services\Reports\ReservationsSales\CommercialPerformanceDatasetService;
use App\Services\Reports\ReservationsSales\ReservationsSalesDashboardDatasetService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Concerns\CreatesOpportunityDashboardRows;
use Tests\TestCase;

class ReservationsSalesCohortAndDuplicateQualityTest extends TestCase
{
    use CreatesOpportunityDashboardRows;
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

    public function test_reservas_ventas_no_devuelve_conclusiones_evaluativas_ni_recomendaciones(): void
    {
        $this->opportunityRow('006-descriptive', [
            'created_date' => '2026-07-15 10:00:00',
            'reservation' => true,
            'reservation_date' => '2026-07-20',
            'stage_name' => 'Reserva',
        ]);

        $payload = $this->getJson('/informes/reservas-ventas/data/summary?'.http_build_query($this->julyFilters()))
            ->assertOk()
            ->json();

        $this->assertSame([], $payload['executive_insights']);
        $this->assertSame([], $payload['insights']);
        $this->assertSame('none', $payload['executive_insights_source']);
        $this->assertArrayNotHasKey('recommendation', $payload);
        $this->assertArrayNotHasKey('recomendacion', $payload);
        $this->assertArrayNotHasKey('problem_detected', $payload);
        $this->assertArrayNotHasKey('problema_detectado', $payload);

        $html = $this->get('/informes/reservas-ventas')->assertOk()->getContent();
        foreach (['baja conversión', 'problemas de cierre', 'necesita formación', 'portal ineficiente'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, mb_strtolower($html));
        }
    }

    public function test_fecha_de_creacion_define_una_cohorte_unica_y_admite_resultados_posteriores(): void
    {
        $this->opportunityRow('006-cohort', [
            'created_date' => '2026-07-15 10:00:00',
            'cv_signed' => true,
            'cv_signed_date' => '2026-08-03',
            'stage_name' => 'Contrato',
            'vehicle_interest_id' => '01t-cohort',
        ]);
        $this->opportunityRow('006-outside', [
            'created_date' => '2026-06-30 10:00:00',
            'cv_signed' => true,
            'cv_signed_date' => '2026-07-20',
            'stage_name' => 'Contrato',
            'vehicle_interest_id' => '01t-outside',
        ]);

        $this->getJson('/informes/reservas-ventas/data/summary?'.http_build_query($this->julyFilters()))
            ->assertOk()
            ->assertJsonPath('universe_date_criterion', 'created_date')
            ->assertJsonPath('universe_date_label', 'Fecha de creación')
            ->assertJsonPath('kpis.oportunidades_totales', 1)
            ->assertJsonPath('kpis.cv_firmados', 1);
    }

    public function test_produccion_y_cohorte_separan_creacion_reserva_y_firma_por_sus_fechas_propias(): void
    {
        $this->opportunityRow('006-a', [
            'created_date' => '2026-06-15 10:00:00',
            'reservation' => true,
            'reservation_date' => '2026-07-10',
            'cv_signed' => true,
            'cv_signed_date' => '2026-08-05',
            'stage_name' => 'Contrato',
            'vehicle_interest_id' => '01t-a',
        ]);
        $this->opportunityRow('006-b', [
            'created_date' => '2026-07-15 10:00:00',
            'reservation' => true,
            'reservation_date' => '2026-07-20',
            'cv_signed' => true,
            'cv_signed_date' => '2026-08-06',
            'stage_name' => 'Contrato',
            'vehicle_interest_id' => '01t-b',
        ]);

        $july = $this->getJson('/informes/reservas-ventas/data/summary?'.http_build_query($this->julyFilters()))
            ->assertOk()
            ->assertJsonPath('kpis.oportunidades_totales', 1)
            ->assertJsonPath('kpis.cv_firmados', 1)
            ->assertJsonPath('produccion_periodo.periodo_actual.reservas', 2)
            ->assertJsonPath('produccion_periodo.periodo_actual.ventas', 0)
            ->assertJsonPath('cohorte_creacion.periodo_actual.oportunidades_totales', 1)
            ->assertJsonPath('cohorte_creacion.periodo_actual.cv_firmados', 1)
            ->json();
        $this->assertSame('created_date', data_get($july, 'cohorte_creacion.date_criterion'));

        $augustFilters = array_merge($this->julyFilters(), [
            'current_start' => '2026-08-01',
            'current_end' => '2026-08-31',
            'comparison_start' => '2026-07-01',
            'comparison_end' => '2026-07-31',
        ]);
        $this->getJson('/informes/reservas-ventas/data/summary?'.http_build_query($augustFilters))
            ->assertOk()
            ->assertJsonPath('kpis.oportunidades_totales', 0)
            ->assertJsonPath('kpis.cv_firmados', 0)
            ->assertJsonPath('produccion_periodo.periodo_actual.reservas', 0)
            ->assertJsonPath('produccion_periodo.periodo_actual.ventas', 2)
            ->assertJsonPath('cohorte_creacion.periodo_actual.oportunidades_totales', 0)
            ->assertJsonPath('cohorte_creacion.periodo_comparado.oportunidades_totales', 1);
    }

    public function test_ventas_de_produccion_deduplican_y_excluyen_conflictos_de_clasificacion_sin_precedencia(): void
    {
        foreach ([
            ['006-valid', 'Contrato'],
            ['006-lost', 'Cerrada Perdida'],
        ] as [$id, $stage]) {
            $this->opportunityRow($id, [
                'created_date' => '2026-07-10 10:00:00',
                'cv_signed' => true,
                'cv_signed_date' => '2026-07-22',
                'stage_name' => $stage,
                'vehicle_interest_id' => '01t-classification-conflict',
            ]);
        }

        $payload = $this->getJson('/informes/reservas-ventas/data/summary?'.http_build_query($this->julyFilters()))
            ->assertOk()
            ->assertJsonPath('kpis.cv_firmados', 1)
            ->assertJsonPath('produccion_periodo.periodo_actual.ventas', 0)
            ->json();

        $incident = collect(data_get($payload, 'data_quality.incidents'))
            ->first(fn (array $item): bool => $item['type'] === 'sale'
                && in_array('classification', $item['conflicting_fields'], true));
        $this->assertNotNull($incident);
        $this->assertSame('data_quality_incident', $incident['breakdown_status']);

        $audit = $this->getJson('/informes/reservas-ventas/data/kpi-audit?'.http_build_query(array_merge(
            $this->julyFilters(),
            ['metric' => 'cv_firmados_periodo'],
        )))
            ->assertOk()
            ->assertJsonPath('metric', 'cv_firmados_periodo')
            ->assertJsonPath('total', 0)
            ->assertJsonPath('audit_rows', 2)
            ->json();

        $this->assertSame(0, collect($audit['items'])->where('counted_in_kpi', true)->count());
        $this->assertSame(['classification_conflict'], collect($audit['items'])->pluck('quality_status')->unique()->values()->all());
    }

    public function test_produccion_simple_reconcilia_fechas_de_hito_con_rendimiento_comercial(): void
    {
        Cache::flush();
        $this->opportunityRow('006-reconciliation', [
            'created_date' => '2026-06-15 10:00:00',
            'reservation' => true,
            'reservation_date' => '2026-07-10',
            'cv_signed' => true,
            'cv_signed_date' => '2026-08-05',
            'stage_name' => 'Contrato',
            'vehicle_interest_id' => '01t-reconciliation',
        ]);

        $augustFilters = array_merge($this->julyFilters(), [
            'current_start' => '2026-08-01',
            'current_end' => '2026-08-31',
            'comparison_start' => '2026-07-01',
            'comparison_end' => '2026-07-31',
        ]);
        $summary = $this->getJson('/informes/reservas-ventas/data/summary?'.http_build_query($augustFilters))
            ->assertOk()
            ->assertJsonPath('produccion_periodo.periodo_actual.ventas', 1)
            ->assertJsonPath('produccion_periodo.periodo_comparado.reservas', 1)
            ->json();
        $performance = app(CommercialPerformanceDatasetService::class)->payload(['month' => '2026-08']);

        $this->assertSame(data_get($summary, 'produccion_periodo.periodo_actual.ventas'), data_get($performance, 'summary.sales'));
        $this->assertSame(
            data_get($summary, 'produccion_periodo.periodo_comparado.reservas'),
            data_get(collect($performance['evolution'])->firstWhere('month', '2026-07'), 'reservations_total'),
        );
    }

    public function test_venta_de_produccion_compatible_reconcilia_con_el_scope_temporal_basico_de_comisiones(): void
    {
        $this->opportunityRow('006-commission-compatible', [
            'created_date' => '2026-06-15 10:00:00',
            'cv_signed' => true,
            'cv_signed_date' => '2026-08-05',
            'stage_name' => 'Contrato',
            'owner_is_active' => true,
            'gestion_de_venta' => false,
            'vehicle_interest_id' => '01t-commission-compatible',
        ]);

        $augustFilters = array_merge($this->julyFilters(), [
            'current_start' => '2026-08-01',
            'current_end' => '2026-08-31',
            'comparison_start' => '2026-07-01',
            'comparison_end' => '2026-07-31',
        ]);
        $summary = $this->getJson('/informes/reservas-ventas/data/summary?'.http_build_query($augustFilters))
            ->assertOk()
            ->assertJsonPath('produccion_periodo.periodo_actual.ventas', 1)
            ->json();

        $method = new \ReflectionMethod(CommercialCommissionDashboardService::class, 'monthlyOpportunities');
        $commissionIds = $method->invoke(
            app(CommercialCommissionDashboardService::class),
            CarbonImmutable::parse('2026-08-01', config('app.timezone')),
            CarbonImmutable::parse('2026-09-01', config('app.timezone')),
        )->pluck('salesforce_id')->all();

        $this->assertSame(1, data_get($summary, 'produccion_periodo.periodo_actual.ventas'));
        $this->assertSame(['006-commission-compatible'], $commissionIds);
    }

    public function test_ventas_y_reservas_duplicadas_cuentan_una_vez_y_exponen_la_incidencia(): void
    {
        foreach ([
            ['006-sale-a', '005-a', 'Comercial A', 'Alcobendas'],
            ['006-sale-b', '005-b', 'Comercial B', 'Alicante'],
        ] as [$id, $ownerId, $ownerName, $delegation]) {
            $this->opportunityRow($id, [
                'created_date' => '2026-07-10 10:00:00',
                'cv_signed' => true,
                'cv_signed_date' => '2026-07-22',
                'stage_name' => 'Contrato',
                'vehicle_interest_id' => '01t-duplicated-sale',
                'vehicle_plate' => '1234ABC',
                'owner_id' => $ownerId,
                'owner_name' => $ownerName,
                'owner_delegation' => $delegation,
            ]);
        }

        foreach (['006-reservation-a', '006-reservation-b'] as $id) {
            $this->opportunityRow($id, [
                'created_date' => '2026-07-12 10:00:00',
                'reservation' => true,
                'reservation_date' => '2026-07-25',
                'vehicle_interest_id' => '01t-duplicated-reservation',
                'vehicle_plate' => '5678DEF',
            ]);
        }

        $summary = $this->getJson('/informes/reservas-ventas/data/summary?'.http_build_query($this->julyFilters()))
            ->assertOk()
            ->assertJsonPath('kpis.oportunidades_totales', 4)
            ->assertJsonPath('kpis.cv_firmados', 1)
            ->assertJsonPath('kpis.reservas_vivas', 1)
            ->assertJsonPath('produccion_periodo.periodo_actual.ventas', 1)
            ->assertJsonPath('data_quality.duplicate_event_groups', 2)
            ->json();

        $saleIncident = collect(data_get($summary, 'data_quality.incidents'))->firstWhere('type', 'sale');
        $this->assertSame(['006-sale-a', '006-sale-b'], $saleIncident['opportunity_ids']);
        $this->assertContains('owner_id', $saleIncident['conflicting_fields']);
        $this->assertNotContains('delivery_store', $saleIncident['conflicting_fields']);
        $this->assertSame('data_quality_incident', $saleIncident['breakdown_status']);

        $commercials = $this->getJson('/informes/reservas-ventas/data/commercials?'.http_build_query($this->julyFilters()))
            ->assertOk()
            ->json('commercials');
        $incidentRow = collect($commercials)->firstWhere('comercial', 'Incidencia de datos');
        $this->assertSame(1, $incidentRow['cv_firmados']);

        $this->getJson('/informes/reservas-ventas/data/kpi-audit?'.http_build_query(array_merge(
            $this->julyFilters(),
            ['metric' => 'cv_firmados'],
        )))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('audit_rows', 2)
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.quality_status', 'duplicate_event');

        $this->getJson('/informes/reservas-ventas/data/kpi-audit?'.http_build_query(array_merge(
            $this->julyFilters(),
            ['metric' => 'cv_firmados_periodo'],
        )))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('audit_rows', 2)
            ->assertJsonCount(2, 'items');
    }

    public function test_kpi_y_csv_comparten_ids_incluso_sin_relaciones_y_minimizan_datos_personales(): void
    {
        $pii = [
            'account_name' => 'PERSONA FICTICIA DETECTABLE',
            'account_phone' => '+34999999999',
            'account_person_email' => 'persona-detectable@example.test',
            'account_company_email' => 'empresa-detectable@example.test',
        ];
        $rows = [
            '006-normal' => [],
            '006-without-account' => array_merge($pii, ['account_id' => null]),
            '006-without-owner' => ['owner_id' => null, 'owner_name' => null],
            '006-without-product' => ['vehicle_interest_id' => null, 'vehicle_plate' => null],
            '006-duplicate-a' => ['vehicle_interest_id' => '01t-duplicate', 'reservation_date' => '2026-07-20'],
            '006-duplicate-b' => ['vehicle_interest_id' => '01t-duplicate', 'reservation_date' => '2026-07-20'],
        ];

        foreach ($rows as $id => $overrides) {
            $this->opportunityRow($id, array_merge([
                'name' => 'OPPORTUNITY '.$id.' PERSONA FICTICIA',
                'created_date' => '2026-07-15 10:00:00',
                'reservation' => true,
                'reservation_date' => '2026-07-'.str_pad((string) (10 + count($overrides)), 2, '0', STR_PAD_LEFT),
                'stage_name' => 'Reserva',
            ], $overrides));
        }

        $filters = array_merge($this->julyFilters(), ['metric' => 'oportunidades_totales']);
        $request = Request::create('/diagnostics/reservas-ventas', 'GET', $filters);
        $kpiIds = app(ReservationsSalesDashboardDatasetService::class)->cohortOpportunityIds($request);
        $summary = $this->getJson('/informes/reservas-ventas/data/summary?'.http_build_query($this->julyFilters()))
            ->assertOk()
            ->json();
        $csv = $this->get('/informes/reservas-ventas/export/kpi-audit.csv?'.http_build_query($filters))
            ->assertOk()
            ->streamedContent();
        [$header, $records] = $this->csvRecords($csv);
        $idIndex = array_search('Opportunity ID', $header, true);
        $csvIds = collect($records)->pluck($idIndex)->sort()->values()->all();

        $this->assertSame(count($kpiIds), data_get($summary, 'kpis.oportunidades_totales'));
        $this->assertSame($kpiIds, $csvIds);
        foreach (['Opportunity name', 'Account name', 'Account phone', 'Account person email', 'Account company email'] as $forbiddenColumn) {
            $this->assertNotContains($forbiddenColumn, $header);
        }
        foreach (array_merge(array_values($pii), ['PERSONA FICTICIA']) as $forbiddenValue) {
            $this->assertStringNotContainsString($forbiddenValue, $csv);
        }

        $this->opportunityRow('006-production-sale-private', array_merge($pii, [
            'name' => 'VENTA PERSONA FICTICIA',
            'created_date' => '2026-06-15 10:00:00',
            'cv_signed' => true,
            'cv_signed_date' => '2026-07-21',
            'stage_name' => 'Contrato',
            'vehicle_interest_id' => '01t-production-sale-private',
        ]));
        $productionSaleFilters = array_merge($this->julyFilters(), ['metric' => 'cv_firmados_periodo']);
        $productionSaleCsv = $this->get('/informes/reservas-ventas/export/kpi-audit.csv?'.http_build_query($productionSaleFilters))
            ->assertOk()
            ->streamedContent();
        [$productionSaleHeader] = $this->csvRecords($productionSaleCsv);
        foreach (['Opportunity name', 'Account name', 'Account phone', 'Account person email', 'Account company email'] as $forbiddenColumn) {
            $this->assertNotContains($forbiddenColumn, $productionSaleHeader);
        }
        foreach (array_merge(array_values($pii), ['PERSONA FICTICIA']) as $forbiddenValue) {
            $this->assertStringNotContainsString($forbiddenValue, $productionSaleCsv);
        }

        $duplicateFilters = array_merge($this->julyFilters(), ['metric' => 'reservas_vivas']);
        $duplicateCsv = $this->get('/informes/reservas-ventas/export/kpi-audit.csv?'.http_build_query($duplicateFilters))
            ->assertOk()
            ->streamedContent();
        [$duplicateHeader, $duplicateRecords] = $this->csvRecords($duplicateCsv);
        $countedIndex = array_search('Contado en KPI', $duplicateHeader, true);
        $duplicateIdIndex = array_search('Opportunity ID', $duplicateHeader, true);

        $this->assertEqualsCanonicalizing(array_keys($rows), array_column($duplicateRecords, $duplicateIdIndex));
        $this->assertContains('0', array_column($duplicateRecords, $countedIndex));
    }

    private function csvRecords(string $content): array
    {
        $lines = array_values(array_filter(
            preg_split('/\r\n|\n|\r/', trim($content)),
            fn (string $line): bool => $line !== ''
        ));
        $records = array_map(function (string $line): array {
            $record = str_getcsv($line);
            $record[0] = ltrim($record[0], "\xEF\xBB\xBF");

            return $record;
        }, $lines);

        return [array_shift($records), $records];
    }

    private function julyFilters(): array
    {
        return [
            'period' => 'custom',
            'date_criterion' => 'created_date',
            'current_start' => '2026-07-01',
            'current_end' => '2026-07-31',
            'comparison_start' => '2026-06-01',
            'comparison_end' => '2026-06-30',
        ];
    }
}
