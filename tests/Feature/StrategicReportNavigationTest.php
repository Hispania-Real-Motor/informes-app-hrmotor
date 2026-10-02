<?php

namespace Tests\Feature;

use App\Models\ReportAccessSetting;
use App\Models\ReportUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StrategicReportNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_director_can_open_the_structural_strategic_modules(): void
    {
        foreach ([ReportUser::ROLE_ADMIN, ReportUser::ROLE_DIRECTOR] as $role) {
            $user = $this->createUser($role);

            $this->withSession($this->sessionFor($user))
                ->get('/informes')
                ->assertOk()
                ->assertSee('Resumen Ejecutivo')
                ->assertSee('Leads')
                ->assertSee('Reservas')
                ->assertSee('Ventas')
                ->assertSee('class="app-nav-link is-active"', false)
                ->assertSee('aria-current="page"', false);

            $this->withSession($this->sessionFor($user))
                ->get('/informes/seo-analytics')
                ->assertOk()
                ->assertSee('SEO y Analytics')
                ->assertSee('Search Console')
                ->assertSee('aria-current="page"', false);
        }
    }

    public function test_operational_roles_land_on_their_first_authorized_report(): void
    {
        foreach ([
            ReportUser::ROLE_MARKETING => '/informes/leads',
            ReportUser::ROLE_AREA_MANAGER => '/informes/leads',
            ReportUser::ROLE_DELEGATION_MANAGER => '/informes/leads',
            ReportUser::ROLE_COMMERCIAL => '/informes/leads',
            ReportUser::ROLE_FINANCIAL => '/informes/comisiones-comerciales',
            ReportUser::ROLE_COMMISSION_AUDITOR => '/informes/comisiones-comerciales',
            ReportUser::ROLE_VIEWER => '/informes/leads',
            ReportUser::ROLE_STOCK_ONLY => '/informes/stock',
        ] as $role => $destination) {
            $user = $this->createUser($role);

            $this->withSession($this->sessionFor($user))
                ->get('/informes')
                ->assertRedirect($destination);
        }
    }

    public function test_stored_minimum_roles_cannot_grant_strategic_access(): void
    {
        ReportAccessSetting::query()->create([
            'report_key' => 'summary',
            'minimum_role' => ReportUser::ROLE_VIEWER,
        ]);
        ReportAccessSetting::query()->create([
            'report_key' => 'seo-analytics',
            'minimum_role' => ReportUser::ROLE_VIEWER,
        ]);

        foreach ([ReportUser::ROLE_VIEWER, ReportUser::ROLE_MARKETING, ReportUser::ROLE_AREA_MANAGER] as $role) {
            $user = $this->createUser($role);

            if ($role === ReportUser::ROLE_VIEWER) {
                $this->withSession($this->sessionFor($user))
                    ->get('/informes')
                    ->assertRedirect('/informes/leads');
            }

            $this->withSession($this->sessionFor($user))
                ->get('/informes/seo-analytics')
                ->assertRedirect('/informes/leads');
        }

        $admin = $this->createUser(ReportUser::ROLE_ADMIN);
        $this->withSession($this->sessionFor($admin))
            ->get('/informes/permisos-informes')
            ->assertOk()
            ->assertDontSee('name="minimum_roles[summary]"', false)
            ->assertDontSee('name="minimum_roles[seo-analytics]"', false);
    }

    public function test_sidebar_only_exposes_authorized_links_and_admin_section(): void
    {
        $marketing = $this->createUser(ReportUser::ROLE_MARKETING);
        $marketingResponse = $this->withSession($this->sessionFor($marketing))->get('/informes/leads');

        $marketingResponse
            ->assertOk()
            ->assertSee('/informes/leads', false)
            ->assertSee('/informes/campanas', false)
            ->assertDontSee('/informes/seo-analytics', false)
            ->assertDontSee('/informes/usuarios', false)
            ->assertDontSee('nav-administration', false);

        $director = $this->createUser(ReportUser::ROLE_DIRECTOR);
        $this->withSession($this->sessionFor($director))
            ->get('/informes')
            ->assertOk()
            ->assertSee('/informes/seo-analytics', false)
            ->assertDontSee('/informes/usuarios', false)
            ->assertDontSee('nav-administration', false);

        $admin = $this->createUser(ReportUser::ROLE_ADMIN);
        $this->withSession($this->sessionFor($admin))
            ->get('/informes')
            ->assertOk()
            ->assertSee('nav-administration', false)
            ->assertSee('/informes/alertas-operativas', false)
            ->assertSee('/informes/usuarios', false)
            ->assertSee('/informes/permisos-informes', false)
            ->assertSee('/informes/configuracion-comisiones', false)
            ->assertSee('/informes/penalizaciones-financiacion', false);
    }

    public function test_login_uses_summary_for_strategic_roles_and_operational_landing_for_others(): void
    {
        $admin = $this->createUser(ReportUser::ROLE_ADMIN, 'admin-login@example.test');
        $this->post('/login', ['email' => $admin->email, 'password' => 'secret12'])
            ->assertRedirect('/informes');

        $this->post('/logout');

        $marketing = $this->createUser(ReportUser::ROLE_MARKETING, 'marketing-login@example.test');
        $this->post('/login', ['email' => $marketing->email, 'password' => 'secret12'])
            ->assertRedirect('/informes/leads');

        $this->post('/logout');

        $stockOnly = $this->createUser(ReportUser::ROLE_STOCK_ONLY, 'stock-only-login@example.test');
        $this->post('/login', ['email' => $stockOnly->email, 'password' => 'secret12'])
            ->assertRedirect('/informes/stock');
    }

    public function test_stock_only_solo_navega_y_accede_al_informe_stock(): void
    {
        $stockOnly = $this->createUser(ReportUser::ROLE_STOCK_ONLY);
        $session = $this->sessionFor($stockOnly);

        $this->withSession($session)
            ->get('/informes')
            ->assertRedirect('/informes/stock');

        $stockResponse = $this->withSession($session)
            ->get('/informes/stock')
            ->assertOk()
            ->assertSee('href="'.route('reports.stock.index').'"', false)
            ->assertDontSee('href="'.route('reports.index').'"', false)
            ->assertDontSee('href="'.route('reports.leads.index').'"', false)
            ->assertDontSee('href="'.route('reports.reservations-sales.index').'"', false)
            ->assertDontSee('href="'.route('reports.calls.index').'"', false)
            ->assertDontSee('href="'.route('reports.campaigns.index').'"', false)
            ->assertDontSee('href="'.route('reports.seo-analytics.index').'"', false)
            ->assertDontSee('href="'.route('reports.commercial-commissions.index').'"', false)
            ->assertDontSee('nav-administration', false);

        $this->assertSame(1, substr_count($stockResponse->getContent(), '<span>Stock</span>'));

        foreach ([
            '/informes/seo-analytics',
            '/informes/leads',
            '/informes/reservas-ventas',
            '/informes/llamadas',
            '/informes/campanas',
            '/informes/comisiones-comerciales',
        ] as $url) {
            $this->withSession($session)->get($url)->assertRedirect('/informes/stock');
        }

        $this->withSession($session)
            ->getJson('/informes/leads/data/resumen')
            ->assertForbidden();

        foreach ([
            '/informes/usuarios',
            '/informes/permisos-informes',
            '/informes/configuracion-comisiones',
            '/informes/penalizaciones-financiacion',
            '/informes/alertas-operativas',
        ] as $url) {
            $this->withSession($session)->get($url)->assertForbidden();
        }
    }

    public function test_shell_assets_keep_sidebar_state_local_and_mobile_drawer_accessible(): void
    {
        $javascript = file_get_contents(resource_path('js/reports/app-shell.js'));
        $css = file_get_contents(resource_path('css/reports/app-shell.css'));
        $designCss = file_get_contents(resource_path('css/reports/design-system.css'));

        $this->assertStringContainsString("const storageKey = 'hrmotor-report-sidebar'", $javascript);
        $this->assertStringContainsString('localStorage.getItem(storageKey)', $javascript);
        $this->assertStringContainsString('try {', $javascript);
        $this->assertStringContainsString("matchMedia('(max-width: 900px)')", $javascript);
        $this->assertStringContainsString("event.key === 'Escape'", $javascript);
        $this->assertStringContainsString("setAttribute('aria-expanded'", $javascript);
        $this->assertStringContainsString("workspace?.setAttribute('inert', '')", $javascript);
        $this->assertStringContainsString("workspace?.removeAttribute('inert')", $javascript);
        $this->assertStringContainsString('sidebarClose?.focus()', $javascript);
        $this->assertStringContainsString("overlay?.addEventListener('click', () => closeMobile(true))", $javascript);
        $this->assertStringContainsString('.app-sidebar-mobile-open .app-sidebar', $css);
        $this->assertStringContainsString('.app-sidebar-collapsed .app-workspace', $css);
        $this->assertStringContainsString('body.app-shell-page.campaigns-report', $css);
        $this->assertStringContainsString('top: calc(var(--app-topbar-height) + 18px)', $css);
        $this->assertStringContainsString('--brand-accent-accessible: var(--report-ui-accent-strong)', $css);
        $this->assertStringContainsString('--report-ui-accent-strong: #a50f23', $designCss);
        $this->assertStringContainsString('outline: 3px solid var(--focus-ring)', $css);
        $this->assertStringContainsString('--surface-muted:', $css);
        $this->assertStringNotContainsString('prefers-color-scheme', $css);

        $icons = file_get_contents(resource_path('views/components/reports/nav-icon.blade.php'));
        $this->assertStringContainsString("@case('penalties')", $icons);
    }

    private function createUser(string $role, ?string $email = null): ReportUser
    {
        return ReportUser::query()->create([
            'name' => ucfirst(str_replace('_', ' ', $role)),
            'email' => $email ?? $role.'-'.str()->random(8).'@example.test',
            'password' => Hash::make('secret12'),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function sessionFor(ReportUser $user): array
    {
        return [
            'informes_authenticated' => true,
            'report_user_id' => $user->id,
            'report_user_role' => $user->role,
            'report_user_email' => $user->email,
            'report_user_name' => $user->name,
        ];
    }
}
