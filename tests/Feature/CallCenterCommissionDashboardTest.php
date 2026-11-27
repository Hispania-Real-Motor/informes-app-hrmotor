<?php

namespace Tests\Feature;

use App\Models\ReportUser;
use App\Models\SalesforceOpportunity;
use App\Models\SalesforceTasacion;
use App\Models\SalesforceUser;
use App\Services\Reports\CallCenterCommissions\CallCenterCommissionDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CallCenterCommissionDashboardTest extends TestCase
{
    use RefreshDatabase;

    private int $reportUserSequence = 0;

    public function test_call_center_se_muestra_como_pestana_dentro_de_comisiones_comerciales(): void
    {
        config()->set('services.informes_auth.enabled', true);

        SalesforceUser::query()->create([
            'salesforce_id' => '005-CC-1',
            'name' => 'Comercial Demo',
            'is_active' => true,
            'profile_name' => 'Compra/Venta',
        ]);

        SalesforceOpportunity::query()->create([
            'salesforce_id' => 'CC-TAB-1',
            'name' => 'Venta demo',
            'created_date' => '2026-05-05 10:00:00',
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'owner_id' => '005-CC-1',
            'owner_name' => 'Comercial Demo',
            'owner_is_active' => true,
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-05',
            'gestion_de_venta' => false,
            'vehicle_plate' => '1234ABC',
            'raw_payload' => [
                'Captador__c' => 'Vanesa',
                'Comisi_n_Captador__c' => 5,
            ],
        ]);

        $adminSession = $this->authenticatedSession(ReportUser::ROLE_ADMIN);
        $directorSession = $this->authenticatedSession(ReportUser::ROLE_DIRECTOR);
        $areaManagerSession = $this->authenticatedSession(ReportUser::ROLE_AREA_MANAGER);
        $viewerSession = $this->authenticatedSession(ReportUser::ROLE_VIEWER);

        $this->withSession($adminSession)
            ->get('/informes/leads')
            ->assertOk()
            ->assertDontSee('/informes/comisiones-call-center', false);

        $this->withSession($directorSession)
            ->get('/informes/leads')
            ->assertOk()
            ->assertDontSee('/informes/comisiones-call-center', false);

        $this->withSession($directorSession)
            ->get('/informes/comisiones-comerciales?month=2026-05')
            ->assertOk()
            ->assertSee('Comerciales')
            ->assertSee('Detalle por comercial')
            ->assertSee('Delegaciones')
            ->assertSee('Call Center')
            ->assertSee('Contact Center');

        $this->withSession($areaManagerSession)
            ->get('/informes/leads')
            ->assertOk()
            ->assertDontSee('/informes/comisiones-call-center', false);

        $this->withSession($areaManagerSession)
            ->get('/informes/comisiones-comerciales')
            ->assertOk()
            ->assertDontSee('>Call Center<', false);

        $this->withSession($viewerSession)
            ->get('/informes/comisiones-comerciales')
            ->assertRedirect('/informes/leads');
    }

    public function test_dashboard_calcula_bloques_de_call_center_y_negociaciones_desde_tasaciones(): void
    {
        $this->createCallCenterOpportunity([
            'salesforce_id' => 'CC-PURCHASE-1',
            'name' => 'Tasacion Jose Mari',
            'record_type_name' => 'Tasacion',
            'owner_name' => 'Owner Uno',
            'account_name' => 'Cuenta Compra 1',
            'cv_signed_date' => '2026-05-05',
            'gestion_de_venta' => false,
            'raw_payload' => [
                'Captador__c' => 'Jose Mari',
                'Comisi_n_Captador__c' => 5,
                'Fecha_captador__c' => '2026-05-01',
                'OPO_BUS_Vehiculo_a_tasar__r' => ['Name' => '1111AAA Opel Corsa'],
            ],
        ]);

        $this->createCallCenterOpportunity([
            'salesforce_id' => 'CC-PURCHASE-2',
            'name' => 'Tasacion Coches.net',
            'record_type_name' => 'Tasacion',
            'owner_name' => 'Owner Dos',
            'account_name' => 'Cuenta Compra 2',
            'cv_signed_date' => '2026-05-06',
            'gestion_de_venta' => false,
            'raw_payload' => [
                'Captador__c' => 'Coches.net',
                'Comisi_n_Captador__c' => 5,
                'OPO_BUS_Vehiculo_a_tasar__r' => ['Name' => '2222BBB Peugeot 208'],
            ],
        ]);

        $this->createCallCenterOpportunity([
            'salesforce_id' => 'CC-SALE-1',
            'name' => 'Venta Vanesa',
            'record_type_name' => 'Venta',
            'owner_name' => 'Owner Tres',
            'account_name' => 'Cuenta Venta 1',
            'cv_signed_date' => '2026-05-07',
            'gestion_de_venta' => false,
            'opportunity_source_raw' => 'WEB',
            'raw_payload' => [
                'Captador__c' => 'Vanesa',
                'Comisi_n_Captador__c' => 5,
                'OPP_BUS_Vehiculo_de_interes__r' => ['Name' => '3333CCC Mazda 3'],
            ],
        ]);

        $this->createCallCenterOpportunity([
            'salesforce_id' => 'CC-CHANGE-1',
            'name' => 'Cambio Aimar',
            'record_type_name' => 'Cambio',
            'owner_name' => 'Owner Cuatro',
            'account_name' => 'Cuenta Cambio 1',
            'cv_signed_date' => '2026-05-08',
            'gestion_de_venta' => false,
            'opportunity_source_raw' => 'WEB',
            'raw_payload' => [
                'Captador__c' => 'Aimar',
                'Comisi_n_Captador__c' => 5,
                'OPP_BUS_Vehiculo_de_interes__r' => ['Name' => '4444DDD Seat Leon'],
            ],
        ]);

        $this->createCallCenterOpportunity([
            'salesforce_id' => 'CC-FACILITEA-1',
            'name' => 'FACILITEA 123456 5555EEE',
            'record_type_name' => 'Venta',
            'owner_name' => 'Vanessa Sanjuan',
            'owner_delegation' => 'ONLINE',
            'account_name' => 'Cuenta Facilitea',
            'cv_signed_date' => '2026-05-09',
            'gestion_de_venta' => false,
            'stage_name' => 'Contrato',
            'opportunity_source_raw' => 'FACILITEA',
            'shared_delivery_name' => 'Costa Plamenov',
            'raw_payload' => [
                'Fuente_de_Origen__c' => 'FACILITEA',
                'OPO_FEC_Fecha_entrega__c' => '2026-05-12',
                'Delegacion_del_propietario__c' => 'CAPTADOR U ONLINE',
                'OPP_BUS_Vehiculo_de_interes__r' => ['Name' => '5555EEE Renault Clio'],
                'Facturado_Facilitea__c' => true,
            ],
        ]);

        $this->createCallCenterOpportunity([
            'salesforce_id' => 'CC-MISSING-1',
            'name' => 'Tasacion sin comision',
            'record_type_name' => 'Tasacion',
            'owner_name' => 'Owner Cinco',
            'account_name' => 'Cuenta Compra 3',
            'cv_signed_date' => '2026-05-10',
            'gestion_de_venta' => false,
            'raw_payload' => [
                'Captador__c' => 'Miriam Gonzalez',
                'Comisi_n_Captador__c' => null,
            ],
        ]);

        $this->createCallCenterOpportunity([
            'salesforce_id' => 'CC-EXCLUDED-1',
            'name' => 'Facilitea gestion venta',
            'record_type_name' => 'Venta',
            'owner_name' => 'Vanessa Sanjuan',
            'account_name' => 'Cuenta Excluida',
            'cv_signed_date' => '2026-05-11',
            'gestion_de_venta' => true,
            'stage_name' => 'Contrato',
            'opportunity_source_raw' => 'FACILITEA',
            'raw_payload' => [
                'Captador__c' => 'Vanesa',
                'Comisi_n_Captador__c' => 5,
            ],
        ]);

        SalesforceTasacion::query()->create([
            'salesforce_id' => 'a02-tasacion-1',
            'name' => 'Tasacion German 1',
            'created_date' => '2026-05-09 10:00:00',
            'opportunity_salesforce_id' => '006-german-1',
            'opportunity_name' => 'Negociacion German 1',
            'contract_signed_date' => '2026-05-09',
            'cv_signed' => true,
            'tracking_name' => null,
            'negotiation_1' => null,
            'negotiation_2' => 'Seguimiento',
            'negotiation_3' => null,
            'negotiation_4' => null,
            'source_query_profile' => 'opportunity_relation',
            'raw_payload' => [
                'Seguimiento__c' => 'German',
                'Negociaci_n_1__c' => 'Primera llamada',
            ],
        ]);

        $payload = app(CallCenterCommissionDashboardService::class)->build('2026-05');

        $this->assertTrue($payload['ready']);
        $this->assertSame(7, $payload['diagnostics']['monthly_opportunities']);
        $this->assertSame(1, $payload['diagnostics']['monthly_tasaciones']);
        $this->assertSame(3, $payload['diagnostics']['purchases_count']);
        $this->assertSame(1, $payload['diagnostics']['sales_count']);
        $this->assertSame(1, $payload['diagnostics']['changes_count']);
        $this->assertSame(1, $payload['diagnostics']['german_negotiations_count']);
        $this->assertSame(1, $payload['diagnostics']['facilitea_count']);
        $this->assertSame(1, $payload['diagnostics']['missing_commission_count']);

        $rows = collect($payload['summary_rows']);

        $this->assertEquals(5.0, $rows->firstWhere('agent_name', 'Jose Mari')['purchase_commission']);
        $this->assertEquals(5.0, $rows->firstWhere('agent_name', 'Coches.net')['purchase_commission']);
        $this->assertEquals(5.0, $rows->firstWhere('agent_name', 'Vanesa')['sales_commission']);
        $this->assertEquals(5.0, $rows->firstWhere('agent_name', 'Aimar')['changes_commission']);
        $this->assertEquals(5.0, $rows->firstWhere('agent_name', 'German Olsen')['german_negotiation_commission']);
        $this->assertEquals(5.0, $rows->firstWhere('agent_name', 'Vanessa Sanjuan')['facilitea_commission']);
        $this->assertEquals(0.0, $rows->firstWhere('agent_name', 'Miriam Gonzalez')['automatic_total']);
        $this->assertStringContainsString('Comision Captador', collect($payload['warnings'])->implode(' | '));
    }

    public function test_dashboard_cuenta_negociaciones_german_por_created_date_cuando_falta_fecha_firma_y_cv_en_tasacion(): void
    {
        SalesforceTasacion::query()->create([
            'salesforce_id' => 'a02-real-1',
            'name' => 'Tasacion German real',
            'created_date' => '2026-05-11 10:00:00',
            'opportunity_salesforce_id' => null,
            'opportunity_name' => null,
            'contract_signed_date' => null,
            'cv_signed' => false,
            'tracking_name' => 'German',
            'negotiation_1' => 'Seguimiento real',
            'negotiation_2' => null,
            'negotiation_3' => null,
            'negotiation_4' => null,
            'source_query_profile' => 'without_relation',
            'raw_payload' => [
                'CreatedDate' => '2026-05-11T10:00:00.000+0000',
                'Seguimiento__c' => 'German',
                'Negociaci_n_1__c' => 'Seguimiento real',
            ],
        ]);

        $payload = app(CallCenterCommissionDashboardService::class)->build('2026-05');
        $rows = collect($payload['summary_rows']);

        $this->assertSame(1, $payload['diagnostics']['monthly_tasaciones']);
        $this->assertSame(1, $payload['diagnostics']['german_negotiations_count']);
        $this->assertEquals(5.0, $rows->firstWhere('agent_name', 'German Olsen')['german_negotiation_commission']);
    }

    public function test_dashboard_no_cuenta_como_sin_captador_oportunidades_sin_senales_reales_de_call_center(): void
    {
        $this->createCallCenterOpportunity([
            'salesforce_id' => 'CC-NO-SIGNAL-1',
            'name' => 'Venta sin senales',
            'record_type_name' => 'Venta',
            'cv_signed_date' => '2026-05-12',
            'raw_payload' => [
                'Captador__c' => null,
                'Comisi_n_Captador__c' => null,
                'Fecha_captador__c' => null,
            ],
        ]);

        $this->createCallCenterOpportunity([
            'salesforce_id' => 'CC-SIGNAL-1',
            'name' => 'Venta auditable',
            'record_type_name' => 'Venta',
            'cv_signed_date' => '2026-05-13',
            'raw_payload' => [
                'Captador__c' => null,
                'Comisi_n_Captador__c' => 5,
            ],
        ]);

        $payload = app(CallCenterCommissionDashboardService::class)->build('2026-05');
        $audit = app(CallCenterCommissionDashboardService::class)->missingCaptadorAudit('2026-05');

        $this->assertSame(1, $payload['diagnostics']['missing_captador_count']);
        $this->assertCount(1, $audit['rows']);
        $this->assertSame('CC-SIGNAL-1', $audit['rows'][0]['opportunity_id']);
        $this->assertStringNotContainsString('sin Captador__c', collect($payload['warnings'])->implode(' | '));
    }

    public function test_director_no_puede_exportar_csv_detallado_de_oportunidades_sin_captador(): void
    {
        config()->set('services.informes_auth.enabled', true);

        $this->createCallCenterOpportunity([
            'salesforce_id' => 'CC-CSV-1',
            'name' => 'Venta CSV',
            'record_type_name' => 'Venta',
            'cv_signed_date' => '2026-05-14',
            'raw_payload' => [
                'Captador__c' => null,
                'Comisi_n_Captador__c' => 5,
            ],
        ]);

        $session = $this->authenticatedSession(ReportUser::ROLE_DIRECTOR);

        $this->withSession($session)
            ->get('/informes/comisiones-comerciales/export/call-center-missing-captador.csv?month=2026-05')
            ->assertForbidden();
    }

    public function test_director_puede_abrir_la_pestana_detallada_de_call_center(): void
    {
        config()->set('services.informes_auth.enabled', true);

        SalesforceTasacion::query()->create([
            'salesforce_id' => 'a02-view-1',
            'name' => 'Tasacion German vista',
            'created_date' => '2026-05-11 10:00:00',
            'tracking_name' => 'German',
            'negotiation_1' => 'Seguimiento real',
            'source_query_profile' => 'without_relation',
            'raw_payload' => [
                'CreatedDate' => '2026-05-11T10:00:00.000+0000',
                'Seguimiento__c' => 'German',
                'Negociaci_n_1__c' => 'Seguimiento real',
            ],
        ]);

        $session = $this->authenticatedSession(ReportUser::ROLE_DIRECTOR);

        $this->withSession($session)
            ->get('/informes/comisiones-comerciales?tab=call-center&month=2026-05')
            ->assertOk()
            ->assertViewHas('activeCommissionTab', 'call-center')
            ->assertDontSee('Diagnostico y resync')
            ->assertDontSee('salesforce:sync-tasaciones')
            ->assertDontSee('aria-disabled="true"', false)
            ->assertSee('Comisiones Call Center');
    }

    private function authenticatedSession(string $role): array
    {
        $this->reportUserSequence++;
        $user = ReportUser::query()->create([
            'name' => 'Call Center '.$role,
            'email' => 'call-center-'.$role.'-'.$this->reportUserSequence.'@example.test',
            'password' => Hash::make('call-center-test-password'),
            'role' => $role,
            'area_zone' => $role === ReportUser::ROLE_AREA_MANAGER ? 'north' : null,
            'is_active' => true,
        ]);

        return [
            'informes_authenticated' => true,
            'report_user_id' => $user->id,
            'report_user_role' => $user->role,
            'report_user_email' => $user->email,
        ];
    }

    private function createCallCenterOpportunity(array $attributes): void
    {
        SalesforceOpportunity::query()->create(array_merge([
            'salesforce_id' => 'CC-DEFAULT',
            'name' => 'Opportunity',
            'created_date' => '2026-05-01 10:00:00',
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'owner_name' => 'Owner',
            'owner_delegation' => 'Online',
            'account_name' => 'Cuenta',
            'opportunity_source_raw' => 'WEB',
            'shared_delivery_name' => null,
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-01',
            'gestion_de_venta' => false,
            'raw_payload' => [],
        ], $attributes));
    }
}
