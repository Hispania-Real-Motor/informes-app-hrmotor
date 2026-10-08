<?php

namespace Tests\Feature;

use App\Models\SalesforceCall;
use App\Models\SalesforceInterest;
use App\Models\SalesforceUser;
use App\Services\Reports\Calls\CallClassificationRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CallsReprocessClassificationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reprocesa_origen_portal_duracion_y_equipo_de_llamadas_existentes(): void
    {
        Cache::flush();

        SalesforceCall::create([
            'salesforce_id' => 'legacy-switchboard',
            'created_date' => '2026-05-20 10:00:00',
            'owner_name' => 'Tasador Uno',
            'owner_profile_name' => 'Standard User',
            'operational_user_name' => 'Tasador Uno',
            'operational_team' => 'unclassified',
            'owner_team' => 'unclassified',
            'portales_raw' => 'Llamada directa',
            'call_origin' => 'switchboard',
            'portal_resolved' => 'Llamada directa',
            'portal_resolution_source' => 'switchboard',
            'call_duration_seconds' => 80,
            'adjusted_duration_seconds' => 70,
            'call_status' => 'answered',
            'is_answered' => true,
            'is_lost' => false,
            'direction' => 'inbound',
        ]);

        SalesforceCall::create([
            'salesforce_id' => 'portal-web',
            'created_date' => '2026-05-20 10:00:00',
            'owner_name' => 'Vanessa San Juan',
            'owner_profile_name' => 'Standard User',
            'operational_user_name' => 'Vanessa San Juan',
            'operational_team' => 'unclassified',
            'owner_team' => 'unclassified',
            'portales_raw' => 'Web Pamplona',
            'call_origin' => 'portal',
            'portal_resolved' => 'Web Pamplona',
            'portal_resolution_source' => 'portales_field',
            'call_duration_seconds' => 80,
            'adjusted_duration_seconds' => 70,
            'call_status' => 'answered',
            'is_answered' => true,
            'is_lost' => false,
            'direction' => 'inbound',
        ]);

        $this->artisan('reports:reprocess-calls-classification', [
            '--from' => '2026-05-01',
            '--to' => '2026-05-31',
            '--reason' => 'Prueba de regresión controlada',
        ])->assertExitCode(0);

        $direct = SalesforceCall::where('salesforce_id', 'legacy-switchboard')->firstOrFail();
        $portal = SalesforceCall::where('salesforce_id', 'portal-web')->firstOrFail();

        $this->assertSame('commercial_direct', $direct->call_origin);
        $this->assertSame('Comercial directo', $direct->portal_resolved);
        $this->assertSame('commercial_direct', $direct->portal_resolution_source);
        $this->assertSame(75, $direct->adjusted_duration_seconds);
        $this->assertSame('unassigned', $direct->operational_team);

        $this->assertSame('portal', $portal->call_origin);
        $this->assertSame('Web', $portal->portal_resolved);
        $this->assertSame('customer_service', $portal->operational_team);
        $this->assertSame('Sin clasificar', $portal->delegation);
        $this->assertSame('Sin clasificar', $portal->zone);
        $this->assertSame(0, SalesforceCall::where('call_origin', 'switchboard')->count());
        $this->assertGreaterThan(1, Cache::get('salesforce_calls_dashboard_cache_version'));
        $this->assertDatabaseCount('salesforce_call_classification_history', 2);
    }

    public function test_exige_periodo_y_el_modo_simulacion_no_modifica(): void
    {
        $this->artisan('reports:reprocess-calls-classification')->assertExitCode(1);

        SalesforceCall::create([
            'salesforce_id' => 'dry-call',
            'created_date' => '2026-05-20 10:00:00',
            'call_status' => 'not_answered',
            'result_raw' => 'ANSWERED',
        ]);

        $this->artisan('reports:reprocess-calls-classification', [
            '--from' => '2026-05-01', '--to' => '2026-05-31', '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertSame('not_answered', SalesforceCall::where('salesforce_id', 'dry-call')->value('call_status'));
        $this->assertDatabaseCount('salesforce_call_classification_history', 0);
    }

    public function test_reproceso_usa_interest_local_exacto_en_lote(): void
    {
        SalesforceInterest::create([
            'salesforce_id' => 'a01AAA000000000AAA',
            'salesforce_created_at' => '2026-05-20 09:00:00',
            'salesforce_last_modified_at' => '2026-05-20 09:00:00',
            'source' => 'Coches.net',
            'is_deleted' => false,
        ]);
        SalesforceCall::create([
            'salesforce_id' => 'call-local-source',
            'created_date' => '2026-05-20 10:00:00',
            'who_id' => '00QAAA000000000AAA',
            'what_id' => 'a01AAA000000000AAA',
            'portales_raw' => '3CX',
            'call_origin' => 'portal',
            'portal_resolved' => 'Google Maps',
            'portal_resolution_source' => 'unclassified',
            'call_duration_seconds' => 80,
            'call_status' => 'answered',
            'is_answered' => true,
            'is_lost' => false,
        ]);

        $this->artisan('reports:reprocess-calls-classification', [
            '--from' => '2026-05-01',
            '--to' => '2026-05-31',
            '--reason' => 'Reclasificacion local exacta de Interest.',
        ])->assertExitCode(0);

        $call = SalesforceCall::where('salesforce_id', 'call-local-source')->firstOrFail();
        $this->assertSame('Coches.net', $call->portal_resolved);
        $this->assertSame('portal', $call->call_origin);
        $this->assertSame(CallClassificationRules::VERSION, $call->classification_rule_version);
        $this->assertSame(70, $call->adjusted_duration_seconds);
        $this->assertSame('interest', $call->portal_resolution_source);
        $this->assertSame('exact_interest', data_get($call->parse_debug, 'portal_debug.relationship_status'));
        $this->assertSame('00QAAA000000000AAA', $call->who_id);
    }

    public function test_reproceso_con_interest_local_ausente_preserva_clasificacion_visible_y_operativa(): void
    {
        SalesforceCall::create([
            'salesforce_id' => 'call-missing-local-lead',
            'created_date' => '2026-05-20 10:00:00',
            'what_id' => 'a01AAA000000000AAA',
            'portales_raw' => '3CX',
            'call_origin' => 'portal',
            'portal_resolved' => 'Coches.net',
            'portal_resolution_source' => 'interest',
            'call_duration_seconds' => 80,
            'adjusted_duration_seconds' => 70,
            'call_status' => 'answered',
            'is_answered' => true,
            'is_lost' => false,
            'is_overflow' => false,
            'overflow_reason' => null,
        ]);

        $this->artisan('reports:reprocess-calls-classification', [
            '--from' => '2026-05-01',
            '--to' => '2026-05-31',
            '--reason' => 'Conservar procedencia cuando el Interest local no existe.',
        ])->assertExitCode(0);

        $call = SalesforceCall::where('salesforce_id', 'call-missing-local-lead')->firstOrFail();
        $this->assertSame('Coches.net', $call->portal_resolved);
        $this->assertSame('historical_preserved', $call->portal_resolution_source);
        $this->assertSame('portal', $call->call_origin);
        $this->assertSame(70, $call->adjusted_duration_seconds);
        $this->assertFalse($call->is_overflow);
        $this->assertNull($call->overflow_reason);
        $this->assertTrue(data_get($call->parse_debug, 'portal_debug.preserved_historical'));
    }

    public function test_reproceso_con_lead_local_ausente_preserva_duracion_operativa_nullable(): void
    {
        SalesforceCall::create([
            'salesforce_id' => 'call-missing-local-null-duration',
            'created_date' => '2026-05-20 10:00:00',
            'what_id' => 'a01BBB000000000AAA',
            'portales_raw' => '3CX',
            'call_origin' => 'portal',
            'portal_resolved' => 'Coches.net',
            'portal_resolution_source' => 'interest',
            'call_duration_seconds' => 80,
            'adjusted_duration_seconds' => null,
            'call_status' => 'answered',
            'is_answered' => true,
            'is_lost' => false,
            'is_overflow' => false,
            'overflow_reason' => null,
        ]);

        $this->artisan('reports:reprocess-calls-classification', [
            '--from' => '2026-05-20',
            '--to' => '2026-05-20',
            '--reason' => 'nullable operational duration regression',
        ])->assertSuccessful();

        $call = SalesforceCall::query()
            ->where('salesforce_id', 'call-missing-local-null-duration')
            ->firstOrFail();

        $this->assertSame('Coches.net', $call->portal_resolved);
        $this->assertSame('historical_preserved', $call->portal_resolution_source);
        $this->assertSame('portal', $call->call_origin);
        $this->assertNull($call->adjusted_duration_seconds);
        $this->assertFalse($call->is_overflow);
        $this->assertNull($call->overflow_reason);
        $this->assertTrue(data_get($call->parse_debug, 'portal_debug.preserved_historical'));
    }

    public function test_reprocesado_considera_respondido_por_como_atendida_sin_pisar_abandoned(): void
    {
        foreach ([
            'answered-by' => ['description' => 'Respondido por: Agente Uno', 'result_raw' => null],
            'abandoned' => ['description' => "Resultado: ABANDONED\nRespondido por: Agente Uno", 'result_raw' => 'ABANDONED'],
        ] as $id => $values) {
            SalesforceCall::query()->create([
                'salesforce_id' => $id,
                'created_date' => '2026-05-20 10:00:00',
                'description' => $values['description'],
                'result_raw' => $values['result_raw'],
                'call_status' => 'not_answered',
                'is_answered' => false,
                'is_lost' => true,
            ]);
        }

        $this->artisan('reports:reprocess-calls-classification', [
            '--from' => '2026-05-01',
            '--to' => '2026-05-31',
            '--reason' => 'Validacion de Respondido por en historico.',
        ])->assertExitCode(0);

        $this->assertTrue((bool) SalesforceCall::query()->where('salesforce_id', 'answered-by')->value('is_answered'));
        $this->assertFalse((bool) SalesforceCall::query()->where('salesforce_id', 'abandoned')->value('is_answered'));
    }

    public function test_excluye_perfil_de_pruebas_con_call_object_y_lo_mantiene_auditable(): void
    {
        SalesforceUser::create([
            'salesforce_id' => '005-test-profile',
            'name' => 'Usuario de prueba',
            'profile_name' => 'Pruebas comunidad comercial',
            'is_active' => true,
        ]);
        SalesforceCall::create([
            'salesforce_id' => 'test-profile-call',
            'created_date' => '2026-05-20 10:00:00',
            'call_object' => 'present',
            'operational_user_id' => '005-test-profile',
            'owner_id' => '005-test-profile',
            'owner_profile_name' => 'Standard User',
            'operational_team' => 'commercial',
            'call_status' => 'answered',
            'is_answered' => true,
            'is_lost' => false,
            'call_duration_seconds' => 30,
        ]);

        $this->artisan('reports:reprocess-calls-classification', [
            '--from' => '2026-05-01',
            '--to' => '2026-05-31',
            '--reason' => 'Excluir perfil de pruebas de los indicadores.',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('salesforce_calls', [
            'salesforce_id' => 'test-profile-call',
            'included_in_dashboard' => false,
            'dashboard_exclusion_reason' => 'excluded_test_profile',
        ]);
    }
}
