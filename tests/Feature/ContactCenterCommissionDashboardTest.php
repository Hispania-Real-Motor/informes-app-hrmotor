<?php

namespace Tests\Feature;

use App\Models\ReportSyncRun;
use App\Models\ReportUser;
use App\Models\SalesforceLead;
use App\Models\SalesforceOpportunity;
use App\Services\Reports\ContactCenterCommissions\ContactCenterCommissionDashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactCenterCommissionDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_dashboard_calcula_contact_center_por_citas_oportunidades_reservas_y_ventas(): void
    {
        $this->createLead([
            'salesforce_id' => '00Q-MARIA-1',
            'name' => 'Lead Maria 1',
            'appointment_setter_id' => '005-maria',
            'appointment_setter_name' => 'Maria Vidal',
            'appointment_capture_date' => '2026-05-03',
            'appointment_call' => true,
            'appointment_store' => false,
            'appointment_attended_status' => 'ACUDIO',
            'phone' => '600 000 001',
            'converted_opportunity_id' => '006-1',
            'portal_text' => 'Web',
        ]);

        $this->createLead([
            'salesforce_id' => '00Q-MARIA-2',
            'name' => 'Lead Maria 2',
            'appointment_setter_id' => '005-maria',
            'appointment_setter_name' => 'Maria Vidal',
            'appointment_capture_date' => '2026-05-10',
            'appointment_call' => false,
            'appointment_store' => true,
            'appointment_attended_status' => 'NO ACUDIO',
            'phone' => '600000002',
            'portal_text' => 'Web',
        ]);

        $this->createLead([
            'salesforce_id' => '00Q-YULEIDIS-1',
            'name' => 'Lead Yuleidis 1',
            'appointment_setter_id' => '005-yuleidis',
            'appointment_setter_name' => 'Yuleidis Garcia',
            'appointment_capture_date' => '2026-04-28',
            'appointment_call' => true,
            'appointment_store' => false,
            'appointment_attended_status' => 'ACUDIO',
            'phone' => '600000003',
            'portal_text' => 'Web',
        ]);

        $this->createOpportunity([
            'salesforce_id' => '006-1',
            'name' => 'Opportunity Maria',
            'created_date' => '2026-05-05 10:00:00',
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'owner_name' => 'Comercial Uno',
            'owner_delegation' => 'Madrid',
            'account_name' => 'Cuenta Maria',
            'account_phone' => '600000001',
            'reservation' => true,
            'reservation_date' => '2026-05-06',
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-20',
            'raw_payload' => [
                'Captador__c' => 'Maria Paz Vidal Perez',
                'Fecha_captador__c' => '2026-05-03',
            ],
        ]);

        $this->createOpportunity([
            'salesforce_id' => '006-2',
            'name' => 'Opportunity Yuleidis',
            'created_date' => '2026-05-12 10:00:00',
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'owner_name' => 'Comercial Dos',
            'owner_delegation' => 'Bilbao',
            'account_name' => 'Cuenta Yuleidis',
            'account_phone' => '600000003',
            'reservation' => false,
            'reservation_date' => null,
            'cv_signed' => true,
            'cv_signed_date' => '2026-05-15',
            'raw_payload' => [
                'Captador__c' => 'Yuleidis Garcia',
                'Fecha_captador__c' => '2026-04-28',
            ],
        ]);

        $payload = app(ContactCenterCommissionDashboardService::class)->build('2026-05');

        $this->assertTrue($payload['ready']);
        $this->assertStringNotContainsString(
            'ventas del mes sin una cita previa imputable al Contact Center',
            collect($payload['warnings'])->implode(' | ')
        );
        $this->assertSame(2, $payload['diagnostics']['appointments_count']);
        $this->assertSame(2, $payload['diagnostics']['sales_count']);
        $this->assertSame(1, $payload['diagnostics']['opportunity_links_count']);
        $this->assertSame(1, $payload['diagnostics']['reservation_links_count']);
        $this->assertSame(0, $payload['diagnostics']['sales_without_appointment_count']);

        $rows = collect($payload['summary_rows']);
        $maria = $rows->firstWhere('agent_name', 'Maria Paz Vidal Perez');
        $yuleidis = $rows->firstWhere('agent_name', 'Yuleidis Garcia');

        $this->assertSame(2, $maria['appointment_count']);
        $this->assertSame(1, $maria['opportunity_count']);
        $this->assertSame(1, $maria['reservation_count']);
        $this->assertSame(1, $maria['sales_count']);
        $this->assertSame(1, $maria['show_count']);
        $this->assertEquals(0.5, $maria['sales_ratio']);
        $this->assertEquals(5.0, $maria['opportunity_commission']);
        $this->assertEquals(12.0, $maria['sales_commission']);
        $this->assertEquals(2.0, $maria['ratio_bonus']);
        $this->assertEquals(19.0, $maria['final_total']);
        $this->assertSame('OK', $maria['review_status']);

        $this->assertSame(0, $yuleidis['appointment_count']);
        $this->assertSame(1, $yuleidis['sales_count']);
        $this->assertEquals(12.0, $yuleidis['final_total']);
        $this->assertEquals(0.0, $yuleidis['sales_ratio']);

        $this->assertSame([], $payload['global_incidents']);
    }

    public function test_director_ve_contact_center_como_pestana_en_comisiones_comerciales(): void
    {
        config()->set('services.informes_auth.enabled', true);

        $this->createLead([
            'salesforce_id' => '00Q-VIEW-1',
            'name' => 'Lead Vista',
            'appointment_setter_id' => '005-view',
            'appointment_setter_name' => 'Maria German',
            'appointment_capture_date' => '2026-05-08',
            'appointment_call' => true,
            'phone' => '600000009',
            'portal_text' => 'Web',
        ]);

        $session = [
            'informes_authenticated' => true,
            'report_user_role' => ReportUser::ROLE_DIRECTOR,
            'report_user_email' => 'director@hrmotor.com',
        ];

        $this->withSession($session)
            ->get('/informes/comisiones-comerciales?month=2026-05')
            ->assertOk()
            ->assertSee('Contact Center')
            ->assertSee('Comisiones Contact Center')
            ->assertSee('Citas concertadas');
    }

    public function test_responsable_contact_center_uses_company_deliveries_and_keeps_agents_separate(): void
    {
        CarbonImmutable::setTestNow('2026-10-15 10:00:00');
        $this->completeOpportunityCoverage('2025-09-01', '2025-10-01');

        foreach (range(1, 100) as $index) {
            $this->createOpportunity(['salesforce_id' => '006-reference-'.$index, 'name' => 'Venta referencia '.$index, 'cv_signed' => true, 'cv_signed_date' => '2025-09-15']);
        }
        foreach (range(1, 101) as $index) {
            $this->createOpportunity(['salesforce_id' => '006-current-'.$index, 'name' => $index === 1 ? 'Facilitea actual' : 'Venta actual '.$index, 'record_type_name' => $index === 1 ? 'Otro' : 'Venta', 'cv_signed' => true, 'cv_signed_date' => '2026-09-15']);
        }
        $this->createOpportunity(['salesforce_id' => '006-lost', 'name' => 'Cerrada perdida', 'stage_name' => 'Cerrada Perdida', 'cv_signed' => true, 'cv_signed_date' => '2026-09-15']);
        $this->createOpportunity(['salesforce_id' => '006-unsigned', 'name' => 'No firmada', 'cv_signed' => false, 'cv_signed_date' => '2026-09-15']);

        $payload = app(ContactCenterCommissionDashboardService::class)->build('2026-09');
        $responsible = $payload['responsible_contact_center'];

        $this->assertTrue($payload['ready']);
        $this->assertSame([], $payload['summary_rows']);
        $this->assertSame('Vanesa Germán', $responsible['responsible_name']);
        $this->assertSame(101, $responsible['actual_deliveries']);
        $this->assertSame(100, $responsible['reference_deliveries']);
        $this->assertSame(505.0, $responsible['final_total']);
        $this->assertSame(1, $responsible['additional_deliveries']);
        $this->assertTrue(collect($responsible['actual_delivery_details'])->contains('is_facilitea', true));
    }

    public function test_responsable_contact_center_is_not_active_before_september_2026(): void
    {
        $payload = app(ContactCenterCommissionDashboardService::class)->build('2026-08');

        $this->assertNull($payload['responsible_contact_center']);
    }

    public function test_responsable_contact_center_blocks_when_reference_coverage_is_missing(): void
    {
        $this->createOpportunity(['salesforce_id' => '006-reference', 'cv_signed' => true, 'cv_signed_date' => '2025-09-15']);
        $this->createOpportunity(['salesforce_id' => '006-current', 'cv_signed' => true, 'cv_signed_date' => '2026-09-15']);

        $payload = app(ContactCenterCommissionDashboardService::class)->build('2026-09');

        $this->assertFalse($payload['ready']);
        $this->assertFalse($payload['responsible_contact_center']['certifiable']);
        $this->assertSame(0.0, $payload['responsible_contact_center']['final_total']);
    }

    public function test_responsable_contact_center_applies_all_integer_thresholds_without_float_rounding(): void
    {
        CarbonImmutable::setTestNow('2026-10-15 10:00:00');
        $this->completeOpportunityCoverage('2025-09-01', '2025-10-01');

        foreach ([89 => 0.0, 90 => 350.0, 99 => 350.0, 100 => 500.0, 101 => 505.0, 150 => 750.0] as $actual => $expected) {
            SalesforceOpportunity::query()->delete();
            $this->createResponsibleDeliveries(100, $actual);

            $responsible = app(ContactCenterCommissionDashboardService::class)->build('2026-09')['responsible_contact_center'];

            $this->assertSame($expected, $responsible['final_total'], 'Actual='.$actual);
        }

        SalesforceOpportunity::query()->delete();
        $this->createResponsibleDeliveries(101, 91);
        $responsible = app(ContactCenterCommissionDashboardService::class)->build('2026-09')['responsible_contact_center'];
        $this->assertSame(350.0, $responsible['final_total']);
    }

    public function test_responsable_contact_center_blocks_a_zero_reference_despite_complete_coverage(): void
    {
        CarbonImmutable::setTestNow('2026-10-15 10:00:00');
        $this->completeOpportunityCoverage('2025-09-01', '2025-10-01');
        $this->createResponsibleDeliveries(0, 1);

        $payload = app(ContactCenterCommissionDashboardService::class)->build('2026-09');

        $this->assertFalse($payload['ready']);
        $this->assertFalse($payload['responsible_contact_center']['certifiable']);
        $this->assertStringContainsString('no tiene entregas elegibles', $payload['issues'][0]);
    }

    private function createResponsibleDeliveries(int $reference, int $actual): void
    {
        if ($reference > 0) {
            foreach (range(1, $reference) as $index) {
                $this->createOpportunity(['salesforce_id' => '006-threshold-reference-'.$index, 'cv_signed' => true, 'cv_signed_date' => '2025-09-15']);
            }
        }
        if ($actual > 0) {
            foreach (range(1, $actual) as $index) {
                $this->createOpportunity(['salesforce_id' => '006-threshold-current-'.$index, 'cv_signed' => true, 'cv_signed_date' => '2026-09-15']);
            }
        }
    }

    private function completeOpportunityCoverage(string $from, string $to): void
    {
        ReportSyncRun::query()->create([
            'dataset' => 'salesforce_opportunities', 'source' => 'salesforce', 'status' => 'completed',
            'period_start_at' => $from, 'period_end_at' => $to, 'started_at' => now()->subMinute(),
            'completed_at' => now(), 'timezone' => config('app.timezone'), 'stats' => ['queried' => 0, 'stored' => 0],
        ]);
    }

    private function createLead(array $attributes): void
    {
        SalesforceLead::query()->create(array_merge([
            'salesforce_id' => '00Q-default',
            'name' => 'Lead',
            'created_date' => '2026-05-01 10:00:00',
            'last_activity_date' => '2026-05-01',
            'status' => 'Nuevo',
            'record_type_name' => 'Lead',
            'owner_id' => '005-owner',
            'owner_name' => 'Owner',
            'appointment_setter_id' => null,
            'appointment_setter_name' => null,
            'fecha_asignacion' => '2026-05-01 10:00:00',
            'appointment_capture_date' => null,
            'appointment_call' => false,
            'appointment_store' => false,
            'appointment_attended_status' => null,
            'store_commercial_id' => null,
            'store_commercial_name' => null,
            'candidate_status_formula' => 'Citado',
            'fuente_origen' => null,
            'medio_origen' => null,
            'campaign_acquired' => null,
            'acquired_id' => null,
            'content_acquired' => null,
            'vehicle_interest' => null,
            'phone' => null,
            'mobile_phone' => null,
            'email' => null,
            'is_converted' => false,
            'converted_date' => null,
            'converted_account_id' => null,
            'converted_contact_id' => null,
            'converted_opportunity_id' => null,
            'medio_nuevo' => null,
            'fuente_nuevo' => null,
            'remitente_lead' => null,
            'portal_text' => null,
            'delegacion_encargada_text' => null,
            'delegacion_encargada_bueno' => null,
            'delegacion_encargada' => null,
            'delegacion_original' => null,
            'raw_payload' => [],
        ], $attributes));
    }

    private function createOpportunity(array $attributes): void
    {
        SalesforceOpportunity::query()->create(array_merge([
            'salesforce_id' => '006-default',
            'name' => 'Opportunity',
            'created_date' => '2026-05-01 10:00:00',
            'close_date' => null,
            'amount' => null,
            'opo_for_importe_total' => null,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'owner_id' => null,
            'owner_name' => 'Owner',
            'owner_is_active' => true,
            'owner_delegation' => 'Madrid',
            'delivery_store' => null,
            'account_id' => null,
            'account_name' => 'Cuenta',
            'account_phone' => null,
            'account_person_email' => null,
            'account_company_email' => null,
            'shared_delivery_id' => null,
            'shared_delivery_name' => null,
            'garantia_total' => null,
            'beneficio_financiacion_comercial' => null,
            'importe_financiado' => null,
            'gestion_de_venta' => false,
            'opo_div_descuento' => null,
            'informe_rentabilidad' => null,
            'rentabilidad_financiera' => null,
            'vehicle_interest_id' => null,
            'vehicle_sale_price' => null,
            'vehicle_purchase_price' => null,
            'vehicle_purchase_source' => null,
            'vehicle_purchase_date' => null,
            'vehicle_buyer_id' => null,
            'vehicle_buyer_name' => null,
            'vehicle_plate' => null,
            'vehicle_entry_date' => null,
            'vehicle_days_in_stock' => null,
            'portal_original' => null,
            'opportunity_source_raw' => null,
            'opportunity_source_normalized' => null,
            'portal_resolved' => 'Web',
            'portal_resolution_source' => null,
            'portal_resolution_lead_id' => null,
            'portal_resolution_debug' => null,
            'reservation' => false,
            'reservation_date' => null,
            'cv_signed' => false,
            'cv_signed_date' => null,
            'raw_payload' => [],
        ], $attributes));
    }
}
