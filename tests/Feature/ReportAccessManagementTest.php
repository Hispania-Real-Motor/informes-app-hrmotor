<?php

namespace Tests\Feature;

use App\Models\MasterDelegation;
use App\Models\ReportUser;
use App\Models\SalesforceLead;
use App\Models\SalesforceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\MirrorsLeadFixturesToInterestReporting;
use Tests\TestCase;

class ReportAccessManagementTest extends TestCase
{
    use MirrorsLeadFixturesToInterestReporting;
    use RefreshDatabase;

    public function test_admin_puede_actualizar_el_rol_minimo_por_informe(): void
    {
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
            ->get('/informes/permisos-informes')
            ->assertOk()
            ->assertSee('Permisos por informe');

        $this->withSession($session)
            ->put('/informes/permisos-informes', [
                'minimum_roles' => [
                    'leads' => ReportUser::ROLE_DIRECTOR,
                    'reservations-sales' => ReportUser::ROLE_VIEWER,
                    'calls' => ReportUser::ROLE_VIEWER,
                    'campaigns' => ReportUser::ROLE_DIRECTOR,
                    'commercial-commissions' => ReportUser::ROLE_ADMIN,
                    'stock' => ReportUser::ROLE_ADMIN,
                ],
            ])
            ->assertRedirect('/informes/permisos-informes');

        $this->assertDatabaseHas('report_access_settings', [
            'report_key' => 'leads',
            'minimum_role' => ReportUser::ROLE_DIRECTOR,
        ]);
        $this->assertDatabaseHas('report_access_settings', [
            'report_key' => 'commercial-commissions',
            'minimum_role' => ReportUser::ROLE_ADMIN,
        ]);
    }

    public function test_usuario_sin_permiso_en_un_informe_es_redirigido_al_siguiente_disponible(): void
    {
        config()->set('services.informes_auth.enabled', true);

        $admin = ReportUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin@hrmotor.com',
            'password' => Hash::make('secret'),
            'role' => ReportUser::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $viewer = ReportUser::query()->create([
            'name' => 'Viewer',
            'email' => 'viewer@hrmotor.com',
            'password' => Hash::make('secret'),
            'role' => ReportUser::ROLE_VIEWER,
            'is_active' => true,
        ]);

        $adminSession = [
            'informes_authenticated' => true,
            'report_user_id' => $admin->id,
            'report_user_role' => ReportUser::ROLE_ADMIN,
            'report_user_email' => $admin->email,
        ];

        $this->withSession($adminSession)
            ->put('/informes/permisos-informes', [
                'minimum_roles' => [
                    'leads' => ReportUser::ROLE_DIRECTOR,
                    'reservations-sales' => ReportUser::ROLE_VIEWER,
                    'calls' => ReportUser::ROLE_VIEWER,
                    'campaigns' => ReportUser::ROLE_DIRECTOR,
                    'commercial-commissions' => ReportUser::ROLE_DIRECTOR,
                    'stock' => ReportUser::ROLE_ADMIN,
                ],
            ])
            ->assertRedirect('/informes/permisos-informes');

        $viewerSession = [
            'informes_authenticated' => true,
            'report_user_id' => $viewer->id,
            'report_user_role' => ReportUser::ROLE_VIEWER,
            'report_user_email' => $viewer->email,
        ];

        $this->withSession($viewerSession)
            ->get('/informes/leads')
            ->assertRedirect('/informes/reservas-ventas');
    }

    public function test_auditor_de_comisiones_solo_puede_abrir_el_informe_de_comisiones(): void
    {
        config()->set('services.informes_auth.enabled', true);

        $auditor = ReportUser::query()->create([
            'name' => 'Auditor de Comisiones',
            'email' => 'auditor.comisiones@hrmotor.com',
            'password' => Hash::make('secret'),
            'role' => ReportUser::ROLE_COMMISSION_AUDITOR,
            'is_active' => true,
        ]);

        $session = [
            'informes_authenticated' => true,
            'report_user_id' => $auditor->id,
            'report_user_role' => ReportUser::ROLE_COMMISSION_AUDITOR,
            'report_user_email' => $auditor->email,
        ];

        $this->withSession($session)
            ->get('/informes/comisiones-comerciales')
            ->assertOk()
            ->assertSee('Comisiones finales')
            ->assertDontSee('Penalizaciones financieras');

        $this->withSession($session)
            ->get('/informes/leads')
            ->assertRedirect('/informes/comisiones-comerciales');

        $this->withSession($session)
            ->get('/informes/permisos-informes')
            ->assertForbidden();

        $this->withSession($session)
            ->get('/informes/penalizaciones-financiacion')
            ->assertForbidden();
    }

    public function test_conciliaciones_internas_solo_se_exponen_a_administrador_it(): void
    {
        config()->set('services.informes_auth.enabled', true);

        $director = ReportUser::query()->create([
            'name' => 'Dirección',
            'email' => 'direccion-calidad@hrmotor.com',
            'password' => Hash::make('secret'),
            'role' => ReportUser::ROLE_DIRECTOR,
            'is_active' => true,
        ]);
        $session = [
            'informes_authenticated' => true,
            'report_user_id' => $director->id,
            'report_user_role' => ReportUser::ROLE_DIRECTOR,
            'report_user_email' => $director->email,
        ];

        $this->withSession($session)
            ->get('/informes/comisiones-comerciales?month=2026-06')
            ->assertOk()
            ->assertDontSee('Conciliacion del universo');

        $this->withSession($session)
            ->get('/informes/campanas')
            ->assertOk()
            ->assertSee('window.reportUserCanSeeSourceReconciliation = false', false);
    }

    public function test_area_manager_accede_a_informes_operativos_y_solo_ve_su_area_en_comisiones(): void
    {
        config()->set('services.informes_auth.enabled', true);

        $manager = ReportUser::query()->create([
            'name' => 'Manager Norte',
            'email' => 'manager.area@hrmotor.com',
            'password' => Hash::make('secret'),
            'role' => ReportUser::ROLE_AREA_MANAGER,
            'area_zone' => 'north',
            'is_active' => true,
        ]);
        $session = [
            'informes_authenticated' => true,
            'report_user_id' => $manager->id,
            'report_user_role' => ReportUser::ROLE_AREA_MANAGER,
            'report_user_email' => $manager->email,
        ];

        $this->withSession($session)
            ->get('/informes/comisiones-comerciales?tab=financials')
            ->assertOk()
            ->assertDontSee('>Financieros<', false)
            ->assertDontSee('Vista restringida')
            ->assertDontSee('Buscar manager')
            ->assertDontSee('>Estado<', false)
            ->assertDontSee('Comisión Oscar');

        $this->withSession($session)
            ->get('/informes/leads')
            ->assertOk();

        $this->withSession($session)
            ->get('/informes/reservas-ventas')
            ->assertOk();

        $this->withSession($session)
            ->get('/informes/llamadas')
            ->assertOk();
    }

    public function test_roles_funcionales_respetan_la_matriz_de_informes(): void
    {
        config()->set('services.informes_auth.enabled', true);
        $delegation = MasterDelegation::query()->create([
            'delegation_name' => 'Alcobendas',
            'commercial_group' => 'Madrid',
            'is_active' => true,
        ]);

        foreach ([
            ReportUser::ROLE_MARKETING => ['ok' => ['/informes/leads', '/informes/campanas'], 'denied' => ['/informes/comisiones-comerciales', '/informes/stock']],
            ReportUser::ROLE_FINANCIAL => ['ok' => ['/informes/comisiones-comerciales'], 'denied' => ['/informes/leads', '/informes/campanas']],
            ReportUser::ROLE_DELEGATION_MANAGER => ['ok' => ['/informes/leads', '/informes/llamadas', '/informes/comisiones-comerciales'], 'denied' => ['/informes/campanas', '/informes/stock']],
            ReportUser::ROLE_COMMERCIAL => ['ok' => ['/informes/leads', '/informes/llamadas', '/informes/comisiones-comerciales'], 'denied' => ['/informes/campanas', '/informes/stock']],
        ] as $role => $matrix) {
            $user = ReportUser::query()->create([
                'email' => $role.'@hrmotor.com', 'password' => 'secret12', 'role' => $role, 'is_active' => true,
                'master_delegation_id' => $role === ReportUser::ROLE_DELEGATION_MANAGER ? $delegation->id : null,
                'salesforce_user_id' => $role === ReportUser::ROLE_COMMERCIAL ? '005-COMMERCIAL-SCOPE' : null,
            ]);
            $session = [
                'informes_authenticated' => true, 'report_user_id' => $user->id,
                'report_user_role' => $role, 'report_user_email' => $user->email,
            ];
            foreach ($matrix['ok'] as $url) {
                $this->withSession($session)->get($url)->assertOk();
            }
            foreach ($matrix['denied'] as $url) {
                $this->withSession($session)->get($url)->assertRedirect();
            }
        }
    }

    public function test_exports_y_auditorias_siguen_el_informe_y_no_el_rol_global(): void
    {
        config()->set('services.informes_auth.enabled', true);
        $marketing = ReportUser::query()->create([
            'email' => 'marketing.audit@hrmotor.com', 'password' => 'secret12',
            'role' => ReportUser::ROLE_MARKETING, 'is_active' => true,
        ]);
        $session = [
            'informes_authenticated' => true,
            'report_user_id' => $marketing->id,
            'report_user_role' => $marketing->role,
            'report_user_email' => $marketing->email,
        ];

        $this->withSession($session)
            ->getJson('/informes/leads/data/kpi-audit?period=last_30_days')
            ->assertOk();
        $this->withSession($session)
            ->get('/informes/campanas/export/campaigns.csv?start_date=2026-06-01&end_date=2026-06-30')
            ->assertOk();
        $this->withSession($session)
            ->getJson('/informes/llamadas/data/audit?period=last_30_days')
            ->assertForbidden();
        $this->withSession($session)
            ->get('/informes/comisiones-comerciales/export/comisiones.xlsx?month=2026-06')
            ->assertForbidden();
    }

    public function test_comercial_no_puede_auditar_un_lead_de_otro_salesforce_user(): void
    {
        config()->set('services.informes_auth.enabled', true);
        foreach (['005-OWN', '005-OTHER'] as $id) {
            SalesforceUser::query()->create([
                'salesforce_id' => $id,
                'name' => $id,
                'profile_name' => 'Compra/Venta',
                'user_delegation' => 'Alcobendas',
                'is_active' => true,
            ]);
        }
        foreach (['00Q-OWN' => '005-OWN', '00Q-OTHER' => '005-OTHER'] as $leadId => $ownerId) {
            SalesforceLead::query()->create([
                'salesforce_id' => $leadId,
                'created_date' => '2026-06-15 10:00:00',
                'status' => 'Potencial',
                'owner_id' => $ownerId,
                'record_type_name' => 'Venta',
                'is_deleted' => false,
            ]);
        }
        $commercial = ReportUser::query()->create([
            'email' => 'commercial.scope@hrmotor.com', 'password' => 'secret12',
            'role' => ReportUser::ROLE_COMMERCIAL, 'salesforce_user_id' => '005-OWN', 'is_active' => true,
        ]);
        $session = [
            'informes_authenticated' => true,
            'report_user_id' => $commercial->id,
            'report_user_role' => $commercial->role,
            'report_user_email' => $commercial->email,
        ];

        $items = $this->withSession($session)
            ->getJson('/informes/leads/data/lead-audit?ids[]=00Q-OWN&ids[]=00Q-OTHER')
            ->assertOk()
            ->json('items');

        $this->assertSame(['00Q-OWN'], collect($items)->pluck('salesforce_id')->all());
    }

    public function test_stock_only_no_es_un_rol_minimo_configurable(): void
    {
        config()->set('services.informes_auth.enabled', true);
        $admin = ReportUser::query()->create([
            'email' => 'admin-minimum-roles@hrmotor.com',
            'password' => 'synthetic-password-12',
            'role' => ReportUser::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $session = [
            'informes_authenticated' => true,
            'report_user_id' => $admin->id,
            'report_user_role' => $admin->role,
            'report_user_email' => $admin->email,
        ];

        $this->withSession($session)
            ->get('/informes/permisos-informes')
            ->assertOk()
            ->assertDontSee('value="'.ReportUser::ROLE_STOCK_ONLY.'"', false);

        $this->withSession($session)
            ->put('/informes/permisos-informes', [
                'minimum_roles' => [
                    'leads' => ReportUser::ROLE_STOCK_ONLY,
                    'reservations-sales' => ReportUser::ROLE_VIEWER,
                    'calls' => ReportUser::ROLE_VIEWER,
                    'campaigns' => ReportUser::ROLE_DIRECTOR,
                    'commercial-commissions' => ReportUser::ROLE_DIRECTOR,
                    'stock' => ReportUser::ROLE_ADMIN,
                ],
            ])
            ->assertSessionHasErrors('minimum_roles.leads');

        $this->assertDatabaseMissing('report_access_settings', [
            'minimum_role' => ReportUser::ROLE_STOCK_ONLY,
        ]);
    }
}
