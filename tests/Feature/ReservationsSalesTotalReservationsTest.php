<?php

namespace Tests\Feature;

use App\Models\MasterDelegation;
use App\Models\ReportUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\CreatesOpportunityDashboardRows;
use Tests\TestCase;

class ReservationsSalesTotalReservationsTest extends TestCase
{
    use CreatesOpportunityDashboardRows;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_october_2026_uses_native_opportunity_dates_without_published_interests(): void
    {
        $this->reservation('006-october', '2026-10-31', [
            'created_date' => '2026-10-01 10:00:00',
            'vehicle_interest_id' => '01t-october',
        ]);
        $this->reservation('006-november', '2026-11-01', [
            'created_date' => '2026-11-01 10:00:00',
            'vehicle_interest_id' => '01t-november',
        ]);

        $this->get('/informes/reservas-ventas?period=october_2026')
            ->assertOk()
            ->assertSee('value="october_2026" selected', false);

        $this->getJson('/informes/reservas-ventas/data/summary?period=october_2026')
            ->assertOk()
            ->assertJsonPath('periodo_actual.inicio', '2026-10-01')
            ->assertJsonPath('periodo_actual.fin', '2026-10-31')
            ->assertJsonPath('kpis.oportunidades_totales', 1)
            ->assertJsonPath('kpis.reservas_totales', 1);
    }

    public function test_reservas_totales_usa_fecha_de_reserva_estado_independiente_y_compara_periodos(): void
    {
        $this->reservation('006-live-a', '2026-05-10', [
            'created_date' => '2026-04-20 10:00:00',
            'vehicle_interest_id' => '01t-live',
        ]);
        $this->reservation('006-live-duplicate', '2026-05-10', [
            'created_date' => '2026-04-21 10:00:00',
            'vehicle_interest_id' => '01t-live',
        ]);
        $this->reservation('006-lost', '2026-05-11', [
            'created_date' => '2026-04-22 10:00:00',
            'stage_name' => 'Cerrada Perdida',
            'vehicle_interest_id' => '01t-lost',
        ]);
        $this->reservation('006-signed', '2026-05-12', [
            'created_date' => '2026-04-23 10:00:00',
            'stage_name' => 'Contrato',
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-20',
            'vehicle_interest_id' => '01t-signed',
        ]);
        $this->reservation('006-april', '2026-04-15', [
            'created_date' => '2026-03-20 10:00:00',
            'vehicle_interest_id' => '01t-april',
        ]);
        $this->reservation('006-created-may-reserved-june', '2026-06-01', [
            'created_date' => '2026-05-05 10:00:00',
            'vehicle_interest_id' => '01t-june',
        ]);
        $this->opportunityRow('006-undated', [
            'created_date' => '2026-05-06 10:00:00',
            'reservation' => true,
            'reservation_date' => null,
            'vehicle_interest_id' => '01t-undated',
        ]);
        $this->reservation('006-deleted', '2026-05-13', [
            'created_date' => '2026-05-07 10:00:00',
            'vehicle_interest_id' => '01t-deleted',
            'is_deleted' => true,
            'deletion_detection_source' => 'query_all_deleted',
        ]);

        $payload = $this->getJson('/informes/reservas-ventas/data/summary?'.http_build_query($this->filters()))
            ->assertOk()
            ->json();

        $this->assertSame(3, data_get($payload, 'kpis.reservas_totales'));
        $this->assertSame(3, data_get($payload, 'produccion_periodo.periodo_actual.reservas'));
        $this->assertSame(1, data_get($payload, 'produccion_periodo.periodo_comparado.reservas'));
        $this->assertSame(2, data_get($payload, 'kpis.oportunidades_totales'));
        $this->assertSame(2, data_get($payload, 'kpis.reservas_vivas'));
        $this->assertSame(0, data_get($payload, 'kpis.cv_firmados'));

        $comparison = collect($payload['comparativa'])->keyBy('key');
        $this->assertSame([
            'oportunidades_totales',
            'reservas_vivas',
            'reservas_totales',
            'oportunidades_caidas',
            'cv_firmados',
        ], collect($payload['comparativa'])->pluck('key')->all());
        $this->assertSame(3, $comparison['reservas_totales']['periodo_actual']);
        $this->assertSame(1, $comparison['reservas_totales']['periodo_comparado']);
        $this->assertSame(2, $comparison['reservas_totales']['diferencia']);
        $this->assertNull($comparison['reservas_totales']['periodo_actual_pct']);
        $this->assertNull($comparison['reservas_totales']['diferencia_pct_puntos']);

        $productionComparison = collect(data_get($payload, 'produccion_periodo.comparativa'))->keyBy('key');
        $this->assertSame(3, $productionComparison['reservas']['periodo_actual']);
        $this->assertSame(1, $productionComparison['reservas']['periodo_comparado']);
        $this->assertSame(2, $productionComparison['reservas']['diferencia']);
    }

    public function test_reservas_totales_reutiliza_identidad_de_vehiculo_fecha_y_fallback_a_opportunity(): void
    {
        $this->reservation('006-vehicle-a', '2026-05-10', ['vehicle_interest_id' => '01t-shared']);
        $this->reservation('006-vehicle-b', '2026-05-10', ['vehicle_interest_id' => '01t-shared']);
        $this->reservation('006-vehicle-second-date', '2026-05-11', ['vehicle_interest_id' => '01t-shared']);
        $this->reservation('006-plate-a', '2026-05-12', ['vehicle_plate' => '1234 ABC']);
        $this->reservation('006-plate-b', '2026-05-12', ['vehicle_plate' => '1234-ABC']);
        $this->reservation('006-fallback-a', '2026-05-13');
        $this->reservation('006-fallback-b', '2026-05-13');

        $this->getJson('/informes/reservas-ventas/data/summary?'.http_build_query($this->filters()))
            ->assertOk()
            ->assertJsonPath('kpis.reservas_totales', 5);
    }

    public function test_reservas_totales_respeta_filtros_y_scope_de_zona(): void
    {
        $this->reservation('006-mediterraneo-venta', '2026-05-10', [
            'owner_id' => '005-mediterraneo',
            'owner_delegation' => 'Alicante',
        ]);
        $this->reservation('006-mediterraneo-tasacion', '2026-05-11', [
            'owner_id' => '005-tasacion',
            'owner_delegation' => 'Alicante',
            'record_type_name' => 'Tasacion',
        ]);
        $this->reservation('006-sur', '2026-05-12', [
            'owner_id' => '005-sur',
            'owner_delegation' => 'Alcobendas',
        ]);

        $this->getJson('/informes/reservas-ventas/data/summary?'.http_build_query(array_merge($this->filters(), [
            'zone' => 'Zona Mediterraneo',
        ])))
            ->assertOk()
            ->assertJsonPath('kpis.reservas_totales', 2);

        $this->getJson('/informes/reservas-ventas/data/summary?'.http_build_query(array_merge($this->filters(), [
            'commercial_delegation' => 'Alicante',
            'opportunity_type' => 'Venta',
            'commercial' => '005-mediterraneo',
        ])))
            ->assertOk()
            ->assertJsonPath('kpis.reservas_totales', 1);
        $this->getJson('/informes/reservas-ventas/data/summary?'.http_build_query(array_merge($this->filters(), [
            'opportunity_type' => 'Tasación',
        ])))
            ->assertOk()
            ->assertJsonPath('kpis.reservas_totales', 1);

        $manager = ReportUser::query()->create([
            'name' => 'Area Manager Test',
            'email' => 'area-manager-total-reservations@example.test',
            'password' => Hash::make('password'),
            'role' => ReportUser::ROLE_AREA_MANAGER,
            'area_zone' => 'mediterranean',
            'is_active' => true,
        ]);
        $session = $this->sessionFor($manager);

        $this->withSession($session)
            ->getJson('/informes/reservas-ventas/data/summary?'.http_build_query($this->filters()))
            ->assertOk()
            ->assertJsonPath('kpis.reservas_totales', 2);
        $this->withSession($session)
            ->getJson('/informes/reservas-ventas/data/summary?'.http_build_query(array_merge($this->filters(), [
                'zone' => 'Zona Sur y Centro',
            ])))
            ->assertOk()
            ->assertJsonPath('kpis.reservas_totales', 0);

        $delegation = MasterDelegation::query()->create([
            'delegation_name' => 'Alicante',
            'commercial_group' => 'Independientes',
            'is_active' => true,
        ]);
        $delegationManager = ReportUser::query()->create([
            'name' => 'Delegation Manager Test',
            'email' => 'delegation-manager-total-reservations@example.test',
            'password' => Hash::make('password'),
            'role' => ReportUser::ROLE_DELEGATION_MANAGER,
            'master_delegation_id' => $delegation->id,
            'is_active' => true,
        ]);
        $this->withSession($this->sessionFor($delegationManager))
            ->getJson('/informes/reservas-ventas/data/summary?'.http_build_query($this->filters()))
            ->assertForbidden();

        $commercial = ReportUser::query()->create([
            'name' => 'Commercial Test',
            'email' => 'commercial-total-reservations@example.test',
            'password' => Hash::make('password'),
            'role' => ReportUser::ROLE_COMMERCIAL,
            'salesforce_user_id' => '005-sur',
            'is_active' => true,
        ]);
        $this->withSession($this->sessionFor($commercial))
            ->getJson('/informes/reservas-ventas/data/summary?'.http_build_query($this->filters()))
            ->assertForbidden();
    }

    public function test_eventos_fuera_de_cohorte_alimentan_opciones_sin_escapar_del_scope_de_zona(): void
    {
        $this->reservation('006-event-option-own', '2026-05-10', [
            'created_date' => '2026-04-10 10:00:00',
            'owner_id' => '005-event-option-own',
            'owner_name' => 'Comercial Evento',
            'owner_delegation' => 'Alicante',
            'vehicle_interest_id' => '01t-event-option-own',
        ]);
        $this->reservation('006-event-option-other', '2026-05-11', [
            'created_date' => '2026-04-11 10:00:00',
            'owner_id' => '005-event-option-other',
            'owner_name' => 'Comercial Ajeno',
            'owner_delegation' => 'Alcobendas',
            'vehicle_interest_id' => '01t-event-option-other',
        ]);
        $this->opportunityRow('006-sale-option-own', [
            'created_date' => '2026-04-12 10:00:00',
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-12',
            'stage_name' => 'Contrato',
            'owner_id' => '005-sale-option-own',
            'owner_name' => 'Comercial Venta Evento',
            'owner_delegation' => 'Alicante',
            'vehicle_interest_id' => '01t-sale-option-own',
        ]);
        $this->opportunityRow('006-sale-option-other', [
            'created_date' => '2026-04-13 10:00:00',
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-13',
            'stage_name' => 'Contrato',
            'owner_id' => '005-sale-option-other',
            'owner_name' => 'Comercial Venta Ajena',
            'owner_delegation' => 'Alcobendas',
            'vehicle_interest_id' => '01t-sale-option-other',
        ]);

        $global = $this->getJson('/informes/reservas-ventas/data/summary?'.http_build_query($this->filters()))
            ->assertOk()
            ->assertJsonPath('kpis.reservas_totales', 2)
            ->assertJsonPath('produccion_periodo.periodo_actual.ventas', 2)
            ->json();

        $this->assertEqualsCanonicalizing(
            ['005-event-option-own', '005-event-option-other', '005-sale-option-own', '005-sale-option-other'],
            collect(data_get($global, 'filters.commercials'))->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['Alicante', 'Alcobendas'],
            data_get($global, 'filters.commercial_delegations'),
        );

        $manager = ReportUser::query()->create([
            'name' => 'Area Manager Filter Options',
            'email' => 'area-manager-filter-options@example.test',
            'password' => Hash::make('password'),
            'role' => ReportUser::ROLE_AREA_MANAGER,
            'area_zone' => 'mediterranean',
            'is_active' => true,
        ]);
        $scoped = $this->withSession($this->sessionFor($manager))
            ->getJson('/informes/reservas-ventas/data/summary?'.http_build_query($this->filters()))
            ->assertOk()
            ->assertJsonPath('kpis.reservas_totales', 1)
            ->assertJsonPath('produccion_periodo.periodo_actual.ventas', 1)
            ->json();

        $this->assertEqualsCanonicalizing(
            ['005-event-option-own', '005-sale-option-own'],
            collect(data_get($scoped, 'filters.commercials'))->pluck('id')->all(),
        );
        $this->assertSame(['Alicante'], data_get($scoped, 'filters.commercial_delegations'));
        $this->assertNotContains('Comercial Ajeno', collect(data_get($scoped, 'filters.commercials'))->pluck('name')->all());
        $this->assertNotContains('Alcobendas', data_get($scoped, 'filters.commercial_delegations'));
    }

    public function test_duplicado_fuera_de_cohorte_aparece_en_calidad_y_auditoria_del_total(): void
    {
        foreach (['a', 'b'] as $suffix) {
            $this->reservation('006-event-quality-'.$suffix, '2026-05-10', [
                'created_date' => '2026-04-10 10:00:00',
                'vehicle_interest_id' => '01t-event-quality',
            ]);
        }

        $payload = $this->getJson('/informes/reservas-ventas/data/summary?'.http_build_query($this->filters()))
            ->assertOk()
            ->assertJsonPath('kpis.reservas_totales', 1)
            ->assertJsonPath('data_quality.duplicate_event_groups', 1)
            ->json();
        $incidents = collect(data_get($payload, 'data_quality.incidents'));

        $this->assertCount(1, $incidents);
        $this->assertSame('reservation', $incidents->first()['type']);
        $this->assertEqualsCanonicalizing(
            ['006-event-quality-a', '006-event-quality-b'],
            $incidents->first()['opportunity_ids'],
        );

        $audit = $this->getJson('/informes/reservas-ventas/data/kpi-audit?'.http_build_query(array_merge(
            $this->filters(),
            ['metric' => 'reservas_totales'],
        )))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('audit_rows', 2)
            ->json();

        $this->assertSame(1, collect($audit['items'])->where('counted_in_kpi', true)->count());
    }

    public function test_calidad_une_eventos_legacy_y_del_periodo_sin_duplicar_grupos(): void
    {
        foreach (['a', 'b'] as $suffix) {
            $this->reservation('006-overlap-'.$suffix, '2026-05-10', [
                'created_date' => '2026-05-05 10:00:00',
                'vehicle_interest_id' => '01t-overlap',
            ]);
            $this->reservation('006-legacy-'.$suffix, '2026-04-10', [
                'created_date' => '2026-05-06 10:00:00',
                'vehicle_interest_id' => '01t-legacy-only',
            ]);
            $this->opportunityRow('006-cv-'.$suffix, [
                'created_date' => '2026-05-07 10:00:00',
                'stage_name' => 'Contrato',
                'cv_signed' => true,
                'cv_signed_date' => '2026-05-20',
                'vehicle_interest_id' => '01t-cv-quality',
            ]);
        }

        $payload = $this->getJson('/informes/reservas-ventas/data/summary?'.http_build_query($this->filters()))
            ->assertOk()
            ->assertJsonPath('kpis.reservas_totales', 1)
            ->assertJsonPath('data_quality.duplicate_event_groups', 3)
            ->json();
        $incidents = collect(data_get($payload, 'data_quality.incidents'));
        $reservationIncidents = $incidents->where('type', 'reservation')->values();

        $this->assertCount(2, $reservationIncidents);
        $this->assertCount(2, $reservationIncidents->pluck('group_key')->unique());
        $this->assertCount(1, $incidents->where('type', 'sale'));
        $this->assertEqualsCanonicalizing(
            [
                ['006-overlap-a', '006-overlap-b'],
                ['006-legacy-a', '006-legacy-b'],
            ],
            $reservationIncidents->pluck('opportunity_ids')->all(),
        );
    }

    public function test_auditoria_y_csv_reconstruyen_reservas_totales_deduplicadas_sin_nueva_pii(): void
    {
        $this->reservation('006-audit-a', '2026-05-10', [
            'vehicle_interest_id' => '01t-audit',
            'name' => 'NOMBRE OPORTUNIDAD NO EXPORTABLE',
            'account_name' => 'CLIENTE NO EXPORTABLE',
        ]);
        $this->reservation('006-audit-duplicate', '2026-05-10', [
            'vehicle_interest_id' => '01t-audit',
        ]);
        $this->reservation('006-audit-lost', '2026-05-11', [
            'stage_name' => 'Cerrada Perdida',
            'vehicle_interest_id' => '01t-audit-lost',
        ]);
        $this->reservation('006-audit-signed', '2026-05-12', [
            'cv_signed' => true,
            'stage_name' => 'Contrato',
            'vehicle_interest_id' => '01t-audit-signed',
        ]);

        $query = http_build_query(array_merge($this->filters(), ['metric' => 'reservas_totales']));
        $audit = $this->getJson('/informes/reservas-ventas/data/kpi-audit?'.$query)
            ->assertOk()
            ->assertJsonPath('metric', 'reservas_totales')
            ->assertJsonPath('total', 3)
            ->assertJsonPath('audit_rows', 4)
            ->json();

        $this->assertSame(3, collect($audit['items'])->where('counted_in_kpi', true)->count());
        $this->assertSame(['2026-05-10', '2026-05-11', '2026-05-12'], collect($audit['items'])->pluck('metric_date')->unique()->sort()->values()->all());
        $this->assertSame(2, collect($audit['items'])->where('duplicate_group_size', 2)->count());

        $csv = $this->get('/informes/reservas-ventas/export/kpi-audit.csv?'.$query)
            ->assertOk()
            ->streamedContent();
        [$header, $records] = $this->csvRecords($csv);
        $countedIndex = array_search('Contado en KPI', $header, true);

        $this->assertSame(3, collect($records)->where($countedIndex, '1')->count());
        $this->assertNotContains('Opportunity name', $header);
        $this->assertNotContains('Account name', $header);
        $this->assertStringNotContainsString('NOMBRE OPORTUNIDAD NO EXPORTABLE', $csv);
        $this->assertStringNotContainsString('CLIENTE NO EXPORTABLE', $csv);
    }

    public function test_resumen_y_comparativa_presentan_las_etiquetas_inequivocas(): void
    {
        $javascript = file_get_contents(resource_path('js/reports/reservations-sales-dashboard.js'));
        $blade = file_get_contents(resource_path('views/reports/reservations-sales/index.blade.php'));

        $this->assertStringContainsString("label: 'Reservas'", $javascript);
        $this->assertStringContainsString("label: 'Ventas'", $javascript);
        $this->assertStringContainsString('Por reservation_date · incluye vivas, caídas y firmadas', $javascript);
        $this->assertStringContainsString('Por cv_signed_date · firmadas y no Cerrada Perdida', $javascript);
        $this->assertStringContainsString('Producción del período', $blade);
        $this->assertStringContainsString('Cohorte de oportunidades creadas en el período', $blade);
        $this->assertStringContainsString('Reservas vivas actuales (todas las fechas)', $blade);
        $this->assertMatchesRegularExpression('/data-filter-scope="standard"[^>]*>\s*<label[^>]*for="period"/s', $blade);
        $this->assertMatchesRegularExpression('/data-filter-scope="legacy-date-criterion"[^>]*>\s*<label[^>]*for="dateCriterion"/s', $blade);
        $this->assertStringContainsString('performanceMode || summaryMode', $javascript);
        $this->assertStringContainsString("const dateCriterion = isSummaryMode()\n        ? 'created_date'", $javascript);
        $setFilterModeSource = substr(
            $javascript,
            strpos($javascript, 'function setFilterMode('),
            strpos($javascript, 'function isCommercialPerformanceMode(') - strpos($javascript, 'function setFilterMode('),
        );
        $this->assertStringNotContainsString("dateCriterion').value", $setFilterModeSource);
        $this->assertStringNotContainsString('Comparativa basica', $blade);
    }

    private function reservation(string $id, string $reservationDate, array $overrides = []): void
    {
        $this->opportunityRow($id, array_merge([
            'reservation' => true,
            'reservation_date' => $reservationDate,
            'stage_name' => 'Reserva',
            'vehicle_interest_id' => null,
            'vehicle_plate' => null,
        ], $overrides));
    }

    private function filters(): array
    {
        return [
            'period' => 'custom',
            'date_criterion' => 'created_date',
            'current_start' => '2026-05-01',
            'current_end' => '2026-05-31',
            'comparison_start' => '2026-04-01',
            'comparison_end' => '2026-04-30',
        ];
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
