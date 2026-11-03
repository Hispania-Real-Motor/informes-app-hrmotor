<?php

namespace Tests\Feature;

use App\Models\CommercialCommissionAppointmentEvidence;
use App\Models\CommercialCommissionDeliveryReconciliation;
use App\Models\ContactCenterCommissionRosterMember;
use App\Models\ReportSyncRun;
use App\Models\ReportUser;
use App\Models\SalesforceLead;
use App\Models\SalesforceOpportunity;
use App\Models\SalesforceUser;
use App\Services\Reports\CommercialCommissions\CommercialCommissionFormulaConfigService;
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

    public function test_september_uses_the_roster_evidence_plan_and_company_target_without_leads(): void
    {
        CarbonImmutable::setTestNow('2026-10-15 10:00:00');
        app(CommercialCommissionFormulaConfigService::class)->saveForMonth('2026-09', ['company_delivery_target' => 1000]);
        $this->completeOpportunityCoverage('2026-09-01', '2026-10-01');
        ReportSyncRun::query()->create([
            'dataset' => 'commercial_commission_appointment_evidence', 'source' => 'salesforce', 'status' => 'completed',
            'period_start_at' => '2026-09-01', 'period_end_at' => '2026-10-01', 'started_at' => now()->subMinute(),
            'completed_at' => now(), 'timezone' => config('app.timezone'), 'stats' => ['queried' => 0, 'stored' => 0],
        ]);

        $agents = [
            '005Qx00000GTjweIAD' => ['Maria Paz Vidal', '2026-04-10 14:11:59'],
            '005Qx00000Hf7gYIAR' => ['Estefany Taborda', '2026-06-02 08:26:54'],
            '005Qx00000I63X7IAJ' => ['Rafael Polanco', '2026-06-22 07:24:25'],
            '005Qx00000JfwwLIAR' => ['Aaron Bordas', '2026-09-09 13:54:40'],
            '005Qx00000HauJdIAJ' => ['Maria German', '2026-05-29 11:59:45'],
            '005Qx00000HEQyXIAX' => ['Yuleidis Garcia', '2026-05-18 08:56:47'],
            '005Qx00000JfETuIAN' => ['Sandra Royo', '2026-09-09 07:42:09'],
        ];
        foreach ($agents as $id => [$name, $createdDate]) {
            SalesforceUser::query()->create(['salesforce_id' => $id, 'name' => $name, 'created_date' => $createdDate, 'is_active' => true, 'commission_appraiser' => false]);
        }
        foreach (range(1, 20) as $index) {
            CommercialCommissionAppointmentEvidence::query()->create(['salesforce_interest_id' => 'a0I-maria-'.$index, 'appointment_setter_id' => '005Qx00000GTjweIAD', 'appointment_setter_name' => 'Maria Vidal Perez', 'appointment_capture_date' => '2026-09-'.str_pad((string) min($index, 28), 2, '0', STR_PAD_LEFT), 'synced_at' => now()]);
        }
        CommercialCommissionAppointmentEvidence::query()->create(['salesforce_interest_id' => 'a0I-same-name-wrong-id', 'appointment_setter_id' => '005-not-in-roster', 'appointment_setter_name' => 'Maria Paz Vidal', 'appointment_capture_date' => '2026-09-20', 'synced_at' => now()]);
        $this->createLead([
            'salesforce_id' => '00Q-LEGACY-MARIA',
            'appointment_setter_id' => '005Qx00000GTjweIAD',
            'appointment_setter_name' => 'Maria Paz Vidal',
            'appointment_capture_date' => '2026-09-20',
            'appointment_call' => true,
        ]);
        foreach (range(1, 2) as $index) {
            $this->createOpportunity(['salesforce_id' => '006-reservation-'.$index, 'appointment_setter_id' => '005Qx00000GTjweIAD', 'reservation' => true, 'reservation_date' => '2026-09-10', 'cv_signed' => false]);
        }
        foreach (range(1, 1012) as $index) {
            $this->createOpportunity(['salesforce_id' => '006-company-'.$index, 'name' => 'Venta compañía '.$index, 'appointment_setter_id' => $index <= 12 ? '005Qx00000GTjweIAD' : null, 'cv_signed' => true, 'cv_signed_date' => '2026-09-15']);
        }
        CommercialCommissionDeliveryReconciliation::query()->create(['reconciliation_key' => str_repeat('a', 64), 'period_start' => '2026-09-01', 'period_end' => '2026-10-01', 'opportunity_salesforce_id' => '006-company-1', 'discrepancy_type' => 'DATE_MISMATCH', 'reconciled_at' => now()]);
        CommercialCommissionDeliveryReconciliation::query()->create(['reconciliation_key' => str_repeat('b', 64), 'period_start' => '2026-09-01', 'period_end' => '2026-10-01', 'opportunity_salesforce_id' => '006-company-2', 'discrepancy_type' => 'DUPLICATE_PRODUCT2_LINK', 'reconciled_at' => now()]);

        $payload = app(ContactCenterCommissionDashboardService::class)->build('2026-09');
        $maria = collect($payload['summary_rows'])->firstWhere('salesforce_user_id', '005Qx00000GTjweIAD');

        $this->assertTrue($payload['ready']);
        $this->assertSame('roster_evidence', $payload['plan']);
        $this->assertCount(7, $payload['summary_rows']);
        $thresholds = [
            '005Qx00000GTjweIAD' => 7,
            '005Qx00000Hf7gYIAR' => 5,
            '005Qx00000I63X7IAJ' => 5,
            '005Qx00000JfwwLIAR' => 5,
            '005Qx00000HauJdIAJ' => 7,
            '005Qx00000HEQyXIAX' => 7,
            '005Qx00000JfETuIAN' => 5,
        ];
        foreach ($thresholds as $salesforceUserId => $threshold) {
            $this->assertSame($threshold, collect($payload['summary_rows'])->firstWhere('salesforce_user_id', $salesforceUserId)['conversion_threshold']);
        }
        $this->assertSame(7, $maria['conversion_threshold']);
        $this->assertSame(20, $maria['appointment_count']);
        $this->assertSame(2, $maria['reservation_count']);
        $this->assertTrue($maria['conversion_passed']);
        $this->assertSame(12, $maria['delivery_count']);
        $this->assertSame(135.0, $maria['delivery_incentive']);
        $this->assertSame(50.0, $maria['company_bonus']);
        $this->assertSame(185.0, $maria['final_total']);
        $this->assertArrayHasKey('product2_statuses', $maria['details']['deliveries'][0]);
        $this->assertSame(560.0, $payload['responsible_contact_center']['final_total']);
        $this->assertSame(1012, $payload['diagnostics']['company_deliveries']);
        $this->assertSame(['005-not-in-roster'], $payload['diagnostics']['unrostered_appointment_setters']);
    }

    public function test_august_keeps_the_legacy_contact_center_plan(): void
    {
        $this->createLead([
            'salesforce_id' => '00Q-LEGACY-AUGUST',
            'appointment_setter_name' => 'Maria Vidal',
            'appointment_capture_date' => '2026-08-10',
            'appointment_call' => true,
        ]);

        $payload = app(ContactCenterCommissionDashboardService::class)->build('2026-08');

        $this->assertArrayNotHasKey('plan', $payload);
        $this->assertNull($payload['responsible_contact_center']);
        $this->assertSame(1, $payload['summary_rows'][0]['appointment_count']);
    }

    public function test_missing_company_target_is_never_presented_as_a_zero_bonus(): void
    {
        CarbonImmutable::setTestNow('2026-10-15 10:00:00');
        $this->completeOpportunityCoverage('2026-09-01', '2026-10-01');
        $this->completeAppointmentEvidenceCoverage('2026-09-01', '2026-10-01');

        $payload = app(ContactCenterCommissionDashboardService::class)->build('2026-09');
        $row = $payload['summary_rows'][0];

        $this->assertFalse($payload['ready']);
        $this->assertNull($row['company_bonus']);
        $this->assertNull($row['final_total']);
        $this->assertFalse($row['certifiable']);
        $this->assertNull($payload['responsible_contact_center']['final_total']);
        $this->assertStringContainsString('Falta objetivo general', implode(' ', $payload['issues']));
    }

    public function test_responsible_remains_certifiable_when_appointment_evidence_is_unavailable(): void
    {
        CarbonImmutable::setTestNow('2026-10-15 10:00:00');
        app(CommercialCommissionFormulaConfigService::class)->saveForMonth('2026-09', ['company_delivery_target' => 1000]);
        $this->completeOpportunityCoverage('2026-09-01', '2026-10-01');
        foreach (range(1, 1012) as $index) {
            $this->createOpportunity(['salesforce_id' => '006-vanesa-'.$index, 'cv_signed' => true, 'cv_signed_date' => '2026-09-15']);
        }

        $payload = app(ContactCenterCommissionDashboardService::class)->build('2026-09');

        $this->assertFalse($payload['ready']);
        $this->assertTrue($payload['responsible_contact_center']['certifiable']);
        $this->assertSame(560.0, $payload['responsible_contact_center']['final_total']);
    }

    public function test_responsible_does_not_calculate_a_partial_amount_without_opportunity_coverage(): void
    {
        CarbonImmutable::setTestNow('2026-10-15 10:00:00');
        app(CommercialCommissionFormulaConfigService::class)->saveForMonth('2026-09', ['company_delivery_target' => 1000]);
        $this->completeAppointmentEvidenceCoverage('2026-09-01', '2026-10-01');
        foreach (range(1, 1012) as $index) {
            $this->createOpportunity(['salesforce_id' => '006-unverified-'.$index, 'cv_signed' => true, 'cv_signed_date' => '2026-09-15']);
        }

        $payload = app(ContactCenterCommissionDashboardService::class)->build('2026-09');

        $this->assertFalse($payload['ready']);
        $this->assertFalse($payload['responsible_contact_center']['certifiable']);
        $this->assertNull($payload['responsible_contact_center']['final_total']);
        $this->assertNull($payload['responsible_contact_center']['base_amount']);
    }

    public function test_multiple_roster_periods_aggregate_one_monthly_row_and_audit_the_gap(): void
    {
        CarbonImmutable::setTestNow('2026-10-15 10:00:00');
        $userId = '005Qx00000GTjweIAD';
        ContactCenterCommissionRosterMember::query()->where('salesforce_user_id', $userId)->delete();
        ContactCenterCommissionRosterMember::query()->create(['salesforce_user_id' => $userId, 'display_name' => 'Nombre A', 'effective_from' => '2026-09-01', 'effective_until' => '2026-09-10']);
        ContactCenterCommissionRosterMember::query()->create(['salesforce_user_id' => $userId, 'display_name' => 'Nombre B', 'effective_from' => '2026-09-20', 'effective_until' => '2026-09-30']);
        app(CommercialCommissionFormulaConfigService::class)->saveForMonth('2026-09', ['company_delivery_target' => 100]);
        $this->completeOpportunityCoverage('2026-09-01', '2026-10-01');
        $this->completeAppointmentEvidenceCoverage('2026-09-01', '2026-10-01');
        SalesforceUser::query()->create(['salesforce_id' => $userId, 'name' => 'Maria Paz Vidal', 'created_date' => '2026-04-10 14:11:59', 'is_active' => true, 'commission_appraiser' => false]);
        foreach (['2026-09-05', '2026-09-25', '2026-09-15'] as $index => $date) {
            CommercialCommissionAppointmentEvidence::query()->create(['salesforce_interest_id' => 'a0I-period-'.$index, 'appointment_setter_id' => $userId, 'appointment_capture_date' => $date, 'synced_at' => now()]);
        }
        foreach (range(1, 10) as $index) {
            $this->createOpportunity(['salesforce_id' => '006-period-'.$index, 'appointment_setter_id' => $userId, 'reservation' => $index === 1, 'reservation_date' => $index === 1 ? '2026-09-05' : null, 'cv_signed' => true, 'cv_signed_date' => $index <= 5 ? '2026-09-05' : '2026-09-25']);
        }
        $this->createOpportunity(['salesforce_id' => '006-period-gap', 'appointment_setter_id' => $userId, 'reservation' => true, 'reservation_date' => '2026-09-15', 'cv_signed' => true, 'cv_signed_date' => '2026-09-15']);

        $payload = app(ContactCenterCommissionDashboardService::class)->build('2026-09');
        $row = collect($payload['summary_rows'])->firstWhere('salesforce_user_id', $userId);

        $this->assertSame('Nombre B', $row['agent_name']);
        $this->assertSame(2, $row['appointment_count']);
        $this->assertSame(1, $row['reservation_count']);
        $this->assertSame(10, $row['delivery_count']);
        $this->assertSame(105.0, $row['delivery_incentive']);
        $this->assertCount(2, $row['roster_periods']);
        $this->assertTrue(collect($payload['global_incidents'])->contains('type', 'EVENT_OUTSIDE_ROSTER_EFFECTIVE_RANGE'));
    }

    public function test_roster_events_are_counted_only_in_their_own_month_and_inactive_users_remain_eligible(): void
    {
        CarbonImmutable::setTestNow('2026-12-15 10:00:00');
        foreach (['2026-09', '2026-10', '2026-11'] as $month) {
            app(CommercialCommissionFormulaConfigService::class)->saveForMonth($month, ['company_delivery_target' => 100]);
            $start = CarbonImmutable::createFromFormat('!Y-m', $month)->startOfMonth();
            $this->completeOpportunityCoverage($start->toDateString(), $start->addMonth()->toDateString());
            $this->completeAppointmentEvidenceCoverage($start->toDateString(), $start->addMonth()->toDateString());
        }

        SalesforceUser::query()->create([
            'salesforce_id' => '005Qx00000GTjweIAD',
            'name' => 'Maria Paz Vidal',
            'created_date' => '2026-04-10 14:11:59',
            'is_active' => false,
            'commission_appraiser' => false,
        ]);
        CommercialCommissionAppointmentEvidence::query()->create([
            'salesforce_interest_id' => 'a0I-month-separation',
            'appointment_setter_id' => '005Qx00000GTjweIAD',
            'appointment_setter_name' => 'Maria Vidal Perez',
            'appointment_capture_date' => '2026-09-25',
            'synced_at' => now(),
        ]);
        $this->createOpportunity([
            'salesforce_id' => '006-month-reservation',
            'appointment_setter_id' => '005Qx00000GTjweIAD',
            'reservation' => true,
            'reservation_date' => '2026-10-03',
            'cv_signed' => false,
        ]);
        $this->createOpportunity([
            'salesforce_id' => '006-month-delivery',
            'appointment_setter_id' => '005Qx00000GTjweIAD',
            'cv_signed' => true,
            'cv_signed_date' => '2026-11-07',
        ]);

        $september = collect(app(ContactCenterCommissionDashboardService::class)->build('2026-09')['summary_rows'])->firstWhere('salesforce_user_id', '005Qx00000GTjweIAD');
        $october = collect(app(ContactCenterCommissionDashboardService::class)->build('2026-10')['summary_rows'])->firstWhere('salesforce_user_id', '005Qx00000GTjweIAD');
        $november = collect(app(ContactCenterCommissionDashboardService::class)->build('2026-11')['summary_rows'])->firstWhere('salesforce_user_id', '005Qx00000GTjweIAD');

        $this->assertSame(1, $september['appointment_count']);
        $this->assertSame(0, $september['reservation_count']);
        $this->assertSame(0, $september['delivery_count']);
        $this->assertSame(0, $october['appointment_count']);
        $this->assertSame(1, $october['reservation_count']);
        $this->assertSame(0, $october['delivery_count']);
        $this->assertSame(0, $november['appointment_count']);
        $this->assertSame(0, $november['reservation_count']);
        $this->assertSame(1, $november['delivery_count']);
    }

    public function test_missing_salesforce_user_created_date_leaves_the_roster_row_non_certifiable(): void
    {
        CarbonImmutable::setTestNow('2026-10-15 10:00:00');
        app(CommercialCommissionFormulaConfigService::class)->saveForMonth('2026-09', ['company_delivery_target' => 100]);
        $this->completeOpportunityCoverage('2026-09-01', '2026-10-01');
        $this->completeAppointmentEvidenceCoverage('2026-09-01', '2026-10-01');
        SalesforceUser::query()->create([
            'salesforce_id' => '005Qx00000GTjweIAD',
            'name' => 'Maria Paz Vidal',
            'created_date' => null,
            'is_active' => true,
            'commission_appraiser' => false,
        ]);

        $row = collect(app(ContactCenterCommissionDashboardService::class)->build('2026-09')['summary_rows'])
            ->firstWhere('salesforce_user_id', '005Qx00000GTjweIAD');

        $this->assertNull($row['conversion_threshold']);
        $this->assertFalse($row['certifiable']);
        $this->assertNull($row['final_total']);
        $this->assertStringContainsString('Falta Salesforce User.CreatedDate', $row['observations']);
    }

    private function completeOpportunityCoverage(string $from, string $to): void
    {
        ReportSyncRun::query()->create([
            'dataset' => 'salesforce_opportunities', 'source' => 'salesforce', 'status' => 'completed',
            'period_start_at' => $from, 'period_end_at' => $to, 'started_at' => now()->subMinute(),
            'completed_at' => now(), 'timezone' => config('app.timezone'), 'stats' => ['queried' => 0, 'stored' => 0],
        ]);
    }

    private function completeAppointmentEvidenceCoverage(string $from, string $to): void
    {
        ReportSyncRun::query()->create([
            'dataset' => 'commercial_commission_appointment_evidence', 'source' => 'salesforce', 'status' => 'completed',
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
