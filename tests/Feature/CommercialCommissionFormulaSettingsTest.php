<?php

namespace Tests\Feature;

use App\Models\CommercialCommissionMonthSetting;
use App\Models\ReportUser;
use App\Models\SalesforceOpportunity;
use App\Models\SalesforceUser;
use App\Services\Reports\CommercialCommissions\CommercialCommissionDashboardService;
use App\Services\Reports\CommercialCommissions\CommercialCommissionFormulaConfigService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CommercialCommissionFormulaSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'https://app.hrmotor.com/api/internal/google-reviews/count*' => Http::response([
                'month' => '06-26',
                'location' => 'HR Motor || Test',
                'reviews_count' => 0,
                'average_rating' => null,
            ], 200),
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_admin_puede_guardar_coeficientes_del_mes_abierto_y_el_calculo_aplica_stock_sin_alterar_operacion_fija(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-26 12:00:00'));
        config()->set('services.informes_auth.enabled', true);
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');

        $admin = ReportUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin@hrmotor.com',
            'password' => Hash::make('secret'),
            'role' => ReportUser::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $session = [
            'informes_authenticated' => true,
            'report_user_id' => $admin->id,
            'report_user_role' => ReportUser::ROLE_ADMIN,
            'report_user_email' => $admin->email,
        ];

        $this->withSession($session)
            ->put('/informes/configuracion-comisiones', $this->validPayload('2026-06', [
                'sales' => ['solo_delivery_amount' => 100.0],
                'stock' => ['amount' => 25.0],
            ]))
            ->assertRedirect('/informes/configuracion-comisiones?month=2026-06');

        SalesforceUser::query()->create([
            'salesforce_id' => '005-CFG',
            'name' => 'Comercial Config',
            'profile_name' => 'Compra/Venta',
            'is_active' => true,
        ]);

        SalesforceOpportunity::query()->create([
            'salesforce_id' => 'SALE-CFG-1',
            'name' => 'Venta configurada',
            'owner_id' => '005-CFG',
            'owner_name' => 'Comercial Config',
            'owner_is_active' => true,
            'stage_name' => 'Contrato',
            'record_type_name' => 'Venta',
            'cv_signed' => true,
            'cv_signed_date' => '2026-06-10',
            'opo_for_importe_total' => 12000,
            'importe_financiado' => 12000,
            'garantia_total' => 5000,
            'gestion_de_venta' => false,
            'vehicle_entry_date' => '2025-12-01',
        ]);

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-06');
        $row = collect($payload['summary_rows'])->firstWhere('commercial_id', '005-CFG');

        $this->assertNotNull($row);
        $this->assertEquals(60.0, $row['sales_amount']);
        $this->assertEquals(25.0, $row['stock_150_amount']);
    }

    public function test_admin_puede_guardar_meta_por_delegacion_del_mes_abierto(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-26 12:00:00'));
        config()->set('services.informes_auth.enabled', true);
        config()->set('commercial_commissions.sale_management_field', 'gestion_de_venta');

        $admin = ReportUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin@hrmotor.com',
            'password' => Hash::make('secret'),
            'role' => ReportUser::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $session = [
            'informes_authenticated' => true,
            'report_user_id' => $admin->id,
            'report_user_role' => ReportUser::ROLE_ADMIN,
            'report_user_email' => $admin->email,
        ];

        $this->withSession($session)
            ->put('/informes/configuracion-comisiones', $this->validPayload('2026-06', [
                'delegations' => [
                    'goals' => [
                        'hr-motor-alicante' => [
                            'label' => 'HR MOTOR ALICANTE',
                            'target_deliveries' => 35,
                        ],
                    ],
                ],
            ]))
            ->assertRedirect('/informes/configuracion-comisiones?month=2026-06');

        SalesforceUser::query()->create([
            'salesforce_id' => '005-DEL',
            'name' => 'Comercial Delegacion',
            'profile_name' => 'Compra/Venta',
            'is_active' => true,
        ]);

        foreach (range(1, 2) as $index) {
            SalesforceOpportunity::query()->create([
                'salesforce_id' => 'SALE-DEL-'.$index,
                'name' => 'Venta delegacion '.$index,
                'owner_id' => '005-DEL',
                'owner_name' => 'Comercial Delegacion',
                'owner_is_active' => true,
                'owner_delegation' => 'HR MOTOR ALICANTE',
                'delivery_store' => 'HR MOTOR ALICANTE',
                'stage_name' => 'Contrato',
                'record_type_name' => 'Venta',
                'cv_signed' => true,
                'cv_signed_date' => '2026-06-0'.($index + 1),
                'opo_for_importe_total' => 10000,
                'vehicle_sale_price' => 13000,
                'vehicle_purchase_price' => 10000,
                'importe_financiado' => 5000,
                'beneficio_financiacion_comercial' => 800,
                'garantia_total' => 200,
                'gestion_de_venta' => false,
            ]);
        }

        $payload = app(CommercialCommissionDashboardService::class)->build('2026-06');
        $row = collect($payload['delegation_rows'])->firstWhere('delegation_name', 'Alicante');

        $this->assertNotNull($row);
        $this->assertSame(35, $row['target_deliveries']);
        $this->assertSame(2, $row['deliveries_count']);
    }

    public function test_normaliza_llica_de_vall_como_llica_de_valls_para_area_manager(): void
    {
        $service = app(CommercialCommissionFormulaConfigService::class);

        $this->assertSame('Llica de Valls', $service->normalizeDelegationLabel('Lliça de Vall'));
        $this->assertSame('Llica de Valls', $service->normalizeDelegationLabel('HR MOTOR LLIÇA DE VALL'));
        $this->assertSame('Llica de Valls', $service->normalizeDelegationLabel('llica'));
    }

    public function test_admin_puede_guardar_objetivo_general_de_entregas_del_mes_abierto(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-26 12:00:00'));
        config()->set('services.informes_auth.enabled', true);

        $this->withSession($this->administratorSession())
            ->put('/informes/configuracion-comisiones', $this->validPayload('2026-06', [
                'company_delivery_target' => 1000,
            ]))
            ->assertRedirect('/informes/configuracion-comisiones?month=2026-06');

        $settings = app(CommercialCommissionFormulaConfigService::class)->forMonth('2026-06');

        $this->assertSame(1000, $settings['company_delivery_target']);
        $this->assertEquals(60.0, $settings['sales']['solo_delivery_amount']);
    }

    public function test_admin_puede_dejar_vacio_el_objetivo_general_de_entregas(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-26 12:00:00'));
        config()->set('services.informes_auth.enabled', true);

        $this->withSession($this->administratorSession())
            ->put('/informes/configuracion-comisiones', $this->validPayload('2026-06', [
                'company_delivery_target' => '',
            ]))
            ->assertRedirect('/informes/configuracion-comisiones?month=2026-06');

        $stored = CommercialCommissionMonthSetting::query()
            ->where('month', '2026-06')
            ->sole();

        $this->assertNull($stored->settings['company_delivery_target']);
    }

    public function test_objetivo_general_de_entregas_rechaza_valores_no_positivos_o_no_enteros(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-26 12:00:00'));
        config()->set('services.informes_auth.enabled', true);

        $session = $this->administratorSession();

        foreach ([-1, 0, 1.5] as $target) {
            $this->withSession($session)
                ->from('/informes/configuracion-comisiones?month=2026-06')
                ->put('/informes/configuracion-comisiones', $this->validPayload('2026-06', [
                    'company_delivery_target' => $target,
                ]))
                ->assertRedirect('/informes/configuracion-comisiones?month=2026-06')
                ->assertSessionHasErrors('company_delivery_target');
        }
    }

    public function test_unifica_a_coruna_por_clave_estable_en_configuracion_area_manager(): void
    {
        $service = app(CommercialCommissionFormulaConfigService::class);

        $service->saveForMonth('2026-06', [
            'area_manager' => [
                'assignments' => [
                    'a-coruna-sin-tilde' => [
                        'label' => 'A Coruna',
                        'manager_key' => 'kosta-plamenov',
                        'active' => true,
                        'objectives' => ['deliveries' => 0, 'benefit' => 0, 'guarantee' => 0, 'purchases' => 0],
                    ],
                    'a-coruna-con-tilde' => [
                        'label' => 'A Coruña',
                        'manager_key' => 'kosta-plamenov',
                        'active' => true,
                        'objectives' => ['deliveries' => 30, 'benefit' => 31416, 'guarantee' => 12750, 'purchases' => 18],
                    ],
                ],
            ],
        ]);

        $assignments = collect($service->forMonth('2026-06')['area_manager']['assignments'] ?? []);
        $aCoruna = $assignments->filter(
            fn (array $assignment): bool => $service->delegationKey((string) $assignment['label']) === 'a-coruna'
        );

        $this->assertCount(1, $aCoruna);
        $this->assertSame(30.0, (float) data_get($aCoruna->first(), 'objectives.deliveries'));
    }

    public function test_mes_cerrado_no_permita_guardar_coeficientes(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-26 12:00:00'));
        config()->set('services.informes_auth.enabled', true);

        $admin = ReportUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin@hrmotor.com',
            'password' => Hash::make('secret'),
            'role' => ReportUser::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $session = [
            'informes_authenticated' => true,
            'report_user_id' => $admin->id,
            'report_user_role' => ReportUser::ROLE_ADMIN,
            'report_user_email' => $admin->email,
        ];

        $this->withSession($session)
            ->from('/informes/configuracion-comisiones?month=2026-05')
            ->put('/informes/configuracion-comisiones', $this->validPayload('2026-05'))
            ->assertRedirect('/informes/configuracion-comisiones?month=2026-05')
            ->assertSessionHasErrors('month');
    }

    public function test_admin_puede_abrir_temporalmente_mayo_guardar_y_el_mes_vuelve_a_cerrarse(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-07 12:00:00'));
        config()->set('services.informes_auth.enabled', true);

        $admin = ReportUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin@hrmotor.com',
            'password' => Hash::make('secret'),
            'role' => ReportUser::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $session = [
            'informes_authenticated' => true,
            'report_user_id' => $admin->id,
            'report_user_role' => ReportUser::ROLE_ADMIN,
            'report_user_email' => $admin->email,
        ];

        $this->withSession($session)
            ->post('/informes/configuracion-comisiones/unlock', ['month' => '2026-06'])
            ->assertRedirect('/informes/configuracion-comisiones?month=2026-06');

        $this->withSession(array_merge($session, [
            'commercial_commission_temporarily_unlocked_months' => ['2026-06'],
        ]))
            ->put('/informes/configuracion-comisiones', $this->validPayload('2026-06', [
                'sales' => ['solo_delivery_amount' => 95.0],
            ]))
            ->assertRedirect('/informes/configuracion-comisiones?month=2026-06')
            ->assertSessionHas('status', 'Coeficientes de comisiones actualizados correctamente. El mes se ha vuelto a cerrar.');

        $this->withSession($session)
            ->from('/informes/configuracion-comisiones?month=2026-06')
            ->put('/informes/configuracion-comisiones', $this->validPayload('2026-06'))
            ->assertRedirect('/informes/configuracion-comisiones?month=2026-06')
            ->assertSessionHasErrors('month');
    }

    public function test_cualquier_mes_cerrado_se_puede_abrir_temporalmente_y_mes_actual_o_futuro_no(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-07 12:00:00'));
        config()->set('services.informes_auth.enabled', true);

        $admin = ReportUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin@hrmotor.com',
            'password' => Hash::make('secret'),
            'role' => ReportUser::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $session = [
            'informes_authenticated' => true,
            'report_user_id' => $admin->id,
            'report_user_role' => ReportUser::ROLE_ADMIN,
            'report_user_email' => $admin->email,
        ];

        $this->withSession($session)
            ->post('/informes/configuracion-comisiones/unlock', ['month' => '2026-06'])
            ->assertRedirect('/informes/configuracion-comisiones?month=2026-06');

        $this->withSession($session)
            ->post('/informes/configuracion-comisiones/unlock', ['month' => '2026-07'])
            ->assertRedirect('/informes/configuracion-comisiones?month=2026-07')
            ->assertSessionHasErrors('month');

        $this->withSession($session)
            ->post('/informes/configuracion-comisiones/unlock', ['month' => '2026-08'])
            ->assertRedirect('/informes/configuracion-comisiones?month=2026-08')
            ->assertSessionHasErrors('month');
    }

    public function test_junio_cerrado_muestra_boton_de_apertura_temporal(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-07 12:00:00'));
        config()->set('services.informes_auth.enabled', true);

        $admin = ReportUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin@hrmotor.com',
            'password' => Hash::make('secret'),
            'role' => ReportUser::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $session = [
            'informes_authenticated' => true,
            'report_user_id' => $admin->id,
            'report_user_role' => ReportUser::ROLE_ADMIN,
            'report_user_email' => $admin->email,
        ];

        $this->withSession($session)
            ->get('/informes/configuracion-comisiones?month=2026-06')
            ->assertOk()
            ->assertSee('Abrir mes temporalmente');
    }

    public function test_mes_sin_configuracion_hereda_metas_delegacion_del_mes_anterior(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-07 12:00:00'));
        config()->set('services.informes_auth.enabled', true);

        $admin = ReportUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin@hrmotor.com',
            'password' => Hash::make('secret'),
            'role' => ReportUser::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $session = [
            'informes_authenticated' => true,
            'report_user_id' => $admin->id,
            'report_user_role' => ReportUser::ROLE_ADMIN,
            'report_user_email' => $admin->email,
        ];

        $this->withSession($session)
            ->put('/informes/configuracion-comisiones', $this->validPayload('2026-07', [
                'delegations' => [
                    'goals' => [
                        'palma' => [
                            'label' => 'Palma',
                            'target_deliveries' => 22,
                        ],
                        'alicante' => [
                            'label' => 'Alicante',
                            'target_deliveries' => 35,
                        ],
                    ],
                ],
            ]))
            ->assertRedirect('/informes/configuracion-comisiones?month=2026-07');

        $this->withSession($session)
            ->get('/informes/configuracion-comisiones?month=2026-08')
            ->assertOk()
            ->assertSee('value="22"', false)
            ->assertSee('value="35"', false);
    }

    public function test_mes_sin_configuracion_hereda_objetivos_area_manager_del_mes_anterior_o_base(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-07 12:00:00'));

        $settings = app(CommercialCommissionFormulaConfigService::class)->forMonth('2026-07');

        $palma = $settings['area_manager']['assignments']['palma'] ?? null;
        $badajoz = $settings['area_manager']['assignments']['badajoz'] ?? null;

        $this->assertNotNull($palma);
        $this->assertSame('david-baeza', $palma['manager_key']);
        $this->assertSame(40.0, $palma['objectives']['deliveries']);
        $this->assertSame(38080.0, $palma['objectives']['benefit']);

        $this->assertNotNull($badajoz);
        $this->assertSame(15.0, $badajoz['objectives']['deliveries']);
    }

    private function validPayload(string $month, array $overrides = []): array
    {
        $payload = [
            'month' => $month,
            'company_delivery_target' => null,
            'sales' => [
                'solo_delivery_amount' => 60.0,
                'shared_owner_delivery_amount' => 30.0,
                'shared_secondary_delivery_amount' => 30.0,
            ],
            'purchases' => [
                'commission_percent' => 0.018,
            ],
            'stock' => [
                'days_threshold' => 150,
                'amount' => 10.0,
            ],
            'bonus' => [
                'start_after_delivery' => 15,
                'amount_per_delivery' => 30.0,
            ],
            'delivery_brackets' => [
                ['max_deliveries' => 6, 'percent' => 0.0],
                ['max_deliveries' => 11, 'percent' => 0.8],
                ['percent' => 1.0],
            ],
            'penalties' => [
                'guarantee_total_threshold' => 3500.0,
                'guarantee_percent' => 0.10,
                'reviews_low_threshold' => 30.0,
                'reviews_mid_threshold' => 50.0,
                'reviews_low_percent' => 0.50,
                'reviews_mid_percent' => 0.10,
                'financing_percentage_threshold' => 40.0,
                'financing_percent' => 0.10,
            ],
            'financing_product_brackets' => [
                ['min_amount' => 50001.0, 'percent' => 0.09],
                ['min_amount' => 30001.0, 'percent' => 0.08],
                ['min_amount' => 25001.0, 'percent' => 0.07],
                ['min_amount' => 17001.0, 'percent' => 0.06],
                ['min_amount' => 12001.0, 'percent' => 0.05],
                ['min_amount' => 8001.0, 'percent' => 0.04],
                ['min_amount' => 5001.0, 'percent' => 0.03],
                ['min_amount' => 1.0, 'percent' => 0.02],
            ],
            'guarantee_product_brackets' => [
                ['min_amount' => 20401.0, 'percent' => 0.11],
                ['min_amount' => 14401.0, 'percent' => 0.09],
                ['min_amount' => 9601.0, 'percent' => 0.07],
                ['min_amount' => 5401.0, 'percent' => 0.06],
                ['min_amount' => 3501.0, 'percent' => 0.04],
                ['min_amount' => 1.0, 'percent' => 0.03],
            ],
            'delegations' => [
                'goals' => [],
            ],
            'area_manager' => [
                'kpi_bases' => [
                    'deliveries' => 150.0,
                    'benefit' => 150.0,
                    'guarantee' => 100.0,
                    'purchases' => 100.0,
                ],
                'zone_keys' => [
                    ['min_percent' => 100.0, 'multiplier' => 1.10],
                    ['min_percent' => 91.0, 'multiplier' => 1.00],
                    ['min_percent' => 85.0, 'multiplier' => 0.90],
                    ['min_percent' => 0.0, 'multiplier' => 0.80],
                ],
                'assignments' => [],
            ],
        ];

        return array_replace_recursive($payload, $overrides);
    }

    private function administratorSession(): array
    {
        $administrator = ReportUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin@hrmotor.com',
            'password' => Hash::make('secret'),
            'role' => ReportUser::ROLE_ADMIN,
            'is_active' => true,
        ]);

        return [
            'informes_authenticated' => true,
            'report_user_id' => $administrator->id,
            'report_user_role' => ReportUser::ROLE_ADMIN,
            'report_user_email' => $administrator->email,
        ];
    }
}
