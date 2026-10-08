<?php

namespace Tests\Feature;

use App\Models\MasterPortal;
use App\Models\ReportSyncRun;
use App\Models\ReportUser;
use App\Models\SalesforceLead;
use App\Models\SalesforceLeadActivitySummary;
use App\Models\SalesforceUser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Concerns\MirrorsLeadFixturesToInterestReporting;
use Tests\TestCase;

class SalesforceDashboardRowsTest extends TestCase
{
    use MirrorsLeadFixturesToInterestReporting;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-13 12:00:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_comerciales_solo_muestra_usuarios_activos_de_perfiles_permitidos_y_resuelve_gestor(): void
    {
        $this->commercial('005-worker', 'Comercial Worker', 'Compra/Venta', true, 'HR MOTOR TORREJON');
        $this->commercial('005-discard', 'Comercial Descarte', 'Comerciales Partner Community', true, 'HR MOTOR TORREJON');
        $this->commercial('005-owner', 'Comercial Owner', 'Compra/Venta', true, 'HR MOTOR TORREJON');
        $this->commercial('005-api', 'API User', 'Administrador del sistema');
        $this->commercial('005-inactive', 'Inactivo', 'Compra/Venta', false);

        $this->lead('00Q1', 'Convertido', ['persona_que_trabajo_id' => '005-worker', 'persona_que_trabajo_name' => 'Comercial Worker']);
        $this->lead('00Q2', 'Descartado', ['propietario_descarte_id' => '005-discard', 'propietario_descarte_name' => 'Comercial Descarte']);
        $this->lead('00Q3', 'Potencial', ['owner_id' => '005-owner', 'owner_name' => 'Comercial Owner']);
        $this->lead('00Q4', 'Potencial', ['owner_id' => '005-api', 'owner_name' => 'API User']);
        $this->lead('00Q5', 'Potencial', ['owner_id' => '005-inactive', 'owner_name' => 'Inactivo']);

        $response = $this->getJson('/informes/leads/data/commercials');
        $names = collect($response->json('items'))->pluck('comercial')->all();

        $this->assertContains('Comercial Worker', $names);
        $this->assertContains('Comercial Descarte', $names);
        $this->assertContains('Comercial Owner', $names);
        $this->assertNotContains('API User', $names);
        $this->assertNotContains('Inactivo', $names);

        $worker = collect($response->json('items'))->firstWhere('comercial', 'Comercial Worker');
        $this->assertSame('Torrejón', $worker['commercial_delegation']);
        $this->assertSame('Zona Sur y Centro', $worker['zone']);

        $quality = $this->getJson('/informes/leads/data/summary')->assertOk()->json('kpis');
        $this->assertSame(2, $quality['without_eligible_commercial']);
        $this->assertSame(0, $quality['without_commercial_delegation']);
        $this->assertArrayHasKey('unclassified', $quality);
    }

    public function test_portales_agrupa_y_calcula_llamadas_formularios_conversion_y_descarte_sin_grupo_visible(): void
    {
        MasterPortal::create(['portal_original' => 'Google Maps', 'portal_group' => 'Google', 'is_active' => true]);

        $this->lead('00Q1', 'Convertido', ['medio_nuevo' => 'Llamada', 'fuente_nuevo' => 'Google Maps']);
        $this->lead('00Q2', 'Descartado', ['medio_nuevo' => 'Formulario', 'portal_text' => 'Google Maps']);
        $this->lead('00Q3', 'Potencial', ['medio_nuevo' => 'Formulario', 'portal_text' => 'Google Maps']);

        $row = collect($this->getJson('/informes/leads/data/portals')->json('items'))
            ->firstWhere('portal', 'Google Maps');

        $this->assertArrayNotHasKey('grupo_portal', $row);
        $this->assertSame(3, $row['leads_totales']);
        $this->assertSame(1, $row['llamadas']);
        $this->assertSame(2, $row['formularios']);
        $this->assertSame(1, $row['convertidos']);
        $this->assertSame(33.33, $row['conversion_pct']);
        $this->assertSame(1, $row['descartados']);
    }

    public function test_delegaciones_usa_prioridad_salesforce_y_sin_clasificar_si_no_hay_valor(): void
    {
        $this->commercial('005-owner', 'Comercial Owner', 'Compra/Venta', true, 'HR MOTOR TORREJON');

        $this->lead('00Q1', 'Potencial', [
            'medio_nuevo' => 'Llamada',
            'fuente_nuevo' => 'Google Maps',
            'delegacion_encargada_text' => 'Madrid',
        ]);
        $this->lead('00Q2', 'Potencial', [
            'medio_nuevo' => 'Formulario',
            'portal_text' => 'Web',
            'delegacion_encargada' => 'HR MOTOR MADRID',
        ]);
        $this->lead('00Q3', 'Potencial', ['medio_nuevo' => 'Formulario', 'portal_text' => 'Sin mapa']);

        $rows = collect($this->getJson('/informes/leads/data/delegations')->json('items'));

        $this->assertSame(2, $rows->firstWhere('delegacion', 'Madrid General')['leads_totales']);
        $this->assertSame(1, $rows->firstWhere('delegacion', 'Sin clasificar')['potenciales_sin_trabajar']);
    }

    public function test_summary_compara_periodo_actual_y_comparado(): void
    {
        $this->lead('00Q1', 'Convertido', ['created_date' => '2026-05-10 10:00:00']);
        $this->lead('00Q2', 'Potencial', ['created_date' => '2026-05-11 10:00:00']);
        $this->lead('00Q3', 'Descartado', ['created_date' => '2026-04-01 10:00:00']);

        $response = $this->getJson('/informes/leads/data/summary');

        $this->assertSame(2, $response->json('kpis.leads_totales'));
        $this->assertSame(1, $response->json('kpis.convertidos'));
        $conversion = collect($response->json('comparativa'))->firstWhere('key', 'convertidos');
        $this->assertEquals(50.0, $conversion['periodo_actual_pct']);
        $this->assertEquals(0.0, $conversion['periodo_comparado_pct']);
        $this->assertEquals(50.0, $conversion['diferencia_pct_puntos']);
    }

    public function test_metadata_usa_ultimo_run_incremental_para_frescura_sin_alterar_filas_ni_cutoff_de_cobertura(): void
    {
        Cache::flush();

        $lead = $this->lead('00Q-freshness', 'Potencial', [
            'synced_at' => '2026-05-01 08:00:00',
            'salesforce_last_modified_at' => '2026-05-01 07:55:00',
        ]);
        SalesforceLead::query()->whereKey($lead->id)->update([
            'synced_at' => '2026-05-01 08:00:00',
            'updated_at' => '2026-05-01 08:00:00',
        ]);
        $summary = SalesforceLeadActivitySummary::create([
            'lead_salesforce_id' => $lead->salesforce_id,
            'total_actividades' => 0,
            'total_tasks' => 0,
            'total_events' => 0,
        ]);
        SalesforceLeadActivitySummary::query()->whereKey($summary->id)->update([
            'updated_at' => '2026-05-02 09:00:00',
        ]);

        ReportSyncRun::create([
            'dataset' => 'leads_dashboard',
            'source' => 'salesforce',
            'status' => 'completed',
            'period_start_at' => '2026-04-12 00:00:00',
            'period_end_at' => '2026-05-14 00:00:00',
            'source_cutoff_at' => '2026-05-10 23:00:00',
            'started_at' => '2026-05-10 22:50:00',
            'completed_at' => '2026-05-10 23:05:00',
            'timezone' => 'Europe/Madrid',
        ]);
        $run = ReportSyncRun::create([
            'dataset' => 'leads_dashboard',
            'source' => 'salesforce',
            'status' => 'completed',
            'period_start_at' => '2026-05-11 12:00:00',
            'period_end_at' => '2026-05-13 12:00:00',
            'source_cutoff_at' => '2026-05-13 11:45:00',
            'started_at' => '2026-05-13 11:40:00',
            'completed_at' => '2026-05-13 11:50:00',
            'timezone' => 'Europe/Madrid',
        ]);

        $leadBefore = $lead->fresh();
        $summaryBefore = $summary->fresh();
        $response = $this->getJson('/informes/leads/data/summary')->assertOk();

        $f2 = ReportSyncRun::query()->where('dataset', 'salesforce_interests')->latest('id')->firstOrFail();
        $f2CutoffUtc = CarbonImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $f2->source_cutoff_at->format('Y-m-d H:i:s'),
            'UTC',
        )->toIso8601String();
        $response
            ->assertJsonPath('salesforce_leads_synced_at', $f2CutoffUtc)
            ->assertJsonPath('dataset_sync_run_id', $f2->id)
            ->assertJsonPath('dataset_sync_run_status', 'completed')
            ->assertJsonPath('dataset_cutoff_at', $f2CutoffUtc);

        $leadAfter = $lead->fresh();
        $summaryAfter = $summary->fresh();
        $this->assertTrue($leadAfter->synced_at->equalTo($leadBefore->synced_at));
        $this->assertTrue($leadAfter->updated_at->equalTo($leadBefore->updated_at));
        $this->assertTrue($summaryAfter->updated_at->equalTo($summaryBefore->updated_at));
    }

    public function test_kpi_audit_exporta_leads_filtrados_por_metrica(): void
    {
        $this->commercial('005-worker', 'Comercial Worker', 'Compra/Venta', true, 'HR MOTOR TORREJON');

        $this->lead('00Q1', 'Convertido', [
            'created_date' => '2026-05-10 10:00:00',
            'persona_que_trabajo_id' => '005-worker',
            'persona_que_trabajo_name' => 'Comercial Worker',
            'medio_nuevo' => 'Formulario',
            'portal_text' => 'Web',
            'campaign_acquired' => 'Campaña Test',
            'converted_account_id' => '001-1',
            'converted_opportunity_id' => '006-1',
        ]);
        $this->lead('00Q2', 'Potencial', [
            'created_date' => '2026-05-11 10:00:00',
            'medio_nuevo' => 'Llamada',
            'fuente_nuevo' => 'Google Maps',
        ]);

        $payload = $this->getJson('/informes/leads/data/kpi-audit?metric=convertidos')
            ->assertOk()
            ->json();

        $this->assertSame('convertidos', $payload['metric']);
        $this->assertSame(1, $payload['total']);
        $this->assertSame('00Q1', $payload['items'][0]['interest_id']);
        $this->assertArrayNotHasKey('campaign_acquired', $payload['items'][0]);

        $this->get('/informes/leads/export/kpi-audit.csv?metric=convertidos')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_direccion_puede_auditar_y_exportar_leads(): void
    {
        config()->set('services.informes_auth.enabled', true);

        $this->lead('00Q-director-audit', 'Convertido', [
            'record_type_name' => 'Tasación',
            'record_type_normalized' => 'tasacion',
            'delegacion_encargada_text' => 'Zona Madrid',
            'salesforce_last_modified_at' => '2026-05-12 08:00:00',
            'synced_at' => '2026-05-12 08:05:00',
        ]);

        $session = [
            'informes_authenticated' => true,
            'report_user_role' => ReportUser::ROLE_DIRECTOR,
            'report_user_email' => 'director@hrmotor.com',
        ];

        $this->withSession($session)
            ->get('/informes/leads')
            ->assertOk()
            ->assertSee('window.reportUserCanExport = true', false)
            ->assertSee('window.reportUserCanAudit = true', false);

        $payload = $this->withSession($session)
            ->getJson('/informes/leads/data/kpi-audit?metric=convertidos')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->json('items.0');

        $this->assertSame('Tasación', $payload['type_raw']);
        $this->assertSame('tasacion', $payload['type_normalized']);
        $this->assertSame('Zona Madrid', $payload['origin_delegation_raw']);
        $this->assertSame('Madrid General', $payload['origin_delegation_normalized']);

        $csv = $this->withSession($session)
            ->get('/informes/leads/export/kpi-audit.csv?metric=convertidos')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('origin_delegation_raw', $csv);
        $this->assertStringContainsString('00Q-director-audit', $csv);
        $this->assertStringContainsString('Zona Madrid', $csv);
    }

    public function test_export_de_conciliacion_incluye_activos_eliminados_y_fusiones(): void
    {
        $this->lead('00Q-active-audit', 'Potencial', [
            'is_deleted' => false,
            'source_origin_new' => 'Málaga',
        ]);
        $this->lead('00Q-merged-audit', 'Potencial', [
            'is_deleted' => true,
            'salesforce_master_record_id' => '00Q-master-audit',
            'deletion_detection_source' => 'query_all',
        ]);

        $csv = $this->get('/informes/leads/export/reconciliation-audit.csv')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->streamedContent();

        $this->assertStringContainsString('00Q-active-audit', $csv);
        $this->assertStringContainsString('00Q-merged-audit', $csv);
        $this->assertStringContainsString('source_interest_sync_run_id', $csv);
        $this->assertStringContainsString('source_activity_run_id', $csv);
        $this->assertStringContainsString('is_deleted', $csv);
        $this->assertStringContainsString('No', $csv);
    }

    public function test_backfill_historico_dry_run_no_escribe_y_ejecucion_marca_origen_local(): void
    {
        $this->lead('00Q-legacy-backfill', 'Potencial', [
            'record_type_name' => 'Tasación',
            'record_type_normalized' => null,
            'medio_nuevo' => 'Llamada',
            'fuente_nuevo' => 'Google Maps',
            'source_origin_new' => 'New source',
            'channel_new' => 'WhatsApp',
            'medium_origin_new' => 'New medium',
            'delegation_origin_new' => 'Alcobendas',
            'resolved_portal' => null,
            'synced_at' => null,
            'sync_metadata_source' => null,
        ]);

        $this->artisan('salesforce:backfill-lead-audit-metadata', ['--dry-run' => true])
            ->assertSuccessful();
        $this->assertDatabaseHas('salesforce_leads', [
            'salesforce_id' => '00Q-legacy-backfill',
            'record_type_normalized' => null,
            'sync_metadata_source' => null,
        ]);

        $this->artisan('salesforce:backfill-lead-audit-metadata')->assertSuccessful();
        $this->assertDatabaseHas('salesforce_leads', [
            'salesforce_id' => '00Q-legacy-backfill',
            'record_type_normalized' => 'tasacion',
            'resolved_portal' => 'New source',
            'resolved_channel' => 'WhatsApp',
            'portal_resolution_source' => 'Fuente_origen__c',
            'sync_metadata_source' => 'legacy_local_backfill',
        ]);
    }

    public function test_filtros_y_tabla_delegaciones_no_exponen_brutos_ni_emails(): void
    {
        $this->lead('00Q10', 'Potencial', ['delegacion_encargada_text' => 'leadsmadrid@hrmotor.com']);
        $this->lead('00Q11', 'Potencial', ['delegacion_encargada_text' => 'Zona Madrid']);
        $this->lead('00Q12', 'Potencial', ['delegacion_encargada_text' => 'Tudela']);
        $this->lead('00Q13', 'Potencial', ['delegacion_encargada_text' => 'Web Alicante']);
        $this->lead('00Q14', 'Potencial', ['delegacion_encargada_text' => 'Llamada directa']);

        $summary = $this->getJson('/informes/leads/data/summary');
        $leadDelegations = $summary->json('filters.lead_delegations');
        $commercialDelegations = $summary->json('filters.commercial_delegations');
        $zones = $summary->json('filters.zones');

        $this->assertContains('Madrid General', $leadDelegations);
        $this->assertContains('Fontellas', $leadDelegations);
        $this->assertContains('Sin clasificar', $leadDelegations);
        $this->assertNotContains('leadsmadrid@hrmotor.com', $leadDelegations);
        $this->assertNotContains('Zona Madrid', $leadDelegations);
        $this->assertNotContains('Tudela', $leadDelegations);
        $this->assertNotContains('Web Alicante', $leadDelegations);
        $this->assertNotContains('Llamada directa', $leadDelegations);
        $this->assertArrayNotHasKey('lead_groups', $summary->json('filters'));
        $this->assertNotContains('Grupo Madrid', $commercialDelegations);
        $this->assertNotContains('Madrid General', $commercialDelegations);
        $this->assertContains('Zona Sur y Centro', $zones);
        $this->assertContains('Zona Norte', $zones);
        $this->assertNotContains('Tudela', $zones);

        $rows = collect($this->getJson('/informes/leads/data/delegations')->json('items'));

        $this->assertSame(2, $rows->firstWhere('delegacion', 'Madrid General')['leads_totales']);
        $this->assertSame(1, $rows->firstWhere('delegacion', 'Fontellas')['leads_totales']);
        $this->assertSame(2, $rows->firstWhere('delegacion', 'Sin clasificar')['leads_totales']);
    }

    private function commercial(string $id, string $name, string $profile, bool $active = true, ?string $delegation = null): void
    {
        SalesforceUser::create([
            'salesforce_id' => $id,
            'name' => $name,
            'profile_name' => $profile,
            'user_delegation' => $delegation,
            'is_active' => $active,
        ]);
    }

    private function lead(string $id, string $status, array $overrides = []): SalesforceLead
    {
        return SalesforceLead::create(array_merge([
            'salesforce_id' => $id,
            'name' => $id,
            'created_date' => '2026-05-10 10:00:00',
            'status' => $status,
            'owner_id' => $overrides['owner_id'] ?? '005-owner',
            'owner_name' => $overrides['owner_name'] ?? 'Comercial Owner',
            'medio_nuevo' => 'Formulario',
            'portal_text' => 'Web',
        ], $overrides));
    }
}
