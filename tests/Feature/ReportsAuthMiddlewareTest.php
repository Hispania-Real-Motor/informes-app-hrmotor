<?php

namespace Tests\Feature;

use App\Models\ReportUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ReportsAuthMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected bool $authenticateReportsByDefault = false;

    public function test_login_keeps_keyboard_navigation_without_automatic_focus_or_missing_background(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('autofocus', false)
            ->assertSee('name="email"', false)->assertSee('name="password"', false);
        $this->assertStringNotContainsString('/images/login-bg.jpg', file_get_contents(resource_path('css/app.css')));
        foreach (['informes-login', 'informes-password-forgot', 'informes-password-reset'] as $view) {
            $this->assertStringNotContainsString('autofocus', file_get_contents(resource_path('views/auth/'.$view.'.blade.php')));
        }
    }

    public function test_usuario_no_autenticado_es_redirigido_al_login_sin_basic_auth(): void
    {
        $response = $this->get('/informes/leads');

        $response
            ->assertRedirect('/login')
            ->assertSessionHas('url.intended', url('/informes/leads'));

        $this->assertFalse($response->headers->has('WWW-Authenticate'));
    }

    public function test_credenciales_globales_legacy_no_permiten_iniciar_sesion(): void
    {
        config()->set('services.informes_auth', [
            'enabled' => false,
            'email' => 'legacy@example.test',
            'user' => 'legacy-user',
            'password' => 'legacy-test-password',
        ]);

        $this->from('/login')->post('/login', [
            'email' => 'legacy@example.test',
            'password' => 'legacy-test-password',
        ])
            ->assertSessionHasErrors('email')
            ->assertRedirect('/login')
            ->assertSessionMissing('informes_authenticated');

        $this->get('/informes/leads')->assertRedirect('/login');
    }

    public function test_login_de_report_user_conserva_rol_y_logout_limpia_sesion(): void
    {
        $user = ReportUser::query()->create([
            'name' => 'Viewer',
            'email' => 'viewer@example.test',
            'password' => Hash::make('viewer-test-password'),
            'role' => ReportUser::ROLE_VIEWER,
            'is_active' => true,
        ]);

        $this->withSession(['url.intended' => url('/informes/leads')])
            ->post('/login', [
                'email' => $user->email,
                'password' => 'viewer-test-password',
            ])
            ->assertRedirect('/informes/leads')
            ->assertSessionHas('informes_authenticated', true)
            ->assertSessionHas('report_user_id', $user->id)
            ->assertSessionHas('report_user_role', ReportUser::ROLE_VIEWER);

        $this->get('/login')->assertRedirect('/informes');

        $this->post('/logout')
            ->assertRedirect('/login')
            ->assertSessionMissing('informes_authenticated');
    }

    public function test_sesion_sin_usuario_real_no_autentica_aunque_indique_rol_admin(): void
    {
        $this->withSession([
            'informes_authenticated' => true,
            'report_user_id' => null,
            'report_user_role' => ReportUser::ROLE_ADMIN,
        ])->get('/informes/leads')->assertRedirect('/login');
    }

    public function test_rol_de_sesion_manipulado_no_eleva_privilegios_del_usuario(): void
    {
        $viewer = ReportUser::query()->create([
            'name' => 'Viewer',
            'email' => 'viewer-privileges@example.test',
            'password' => Hash::make('viewer-test-password'),
            'role' => ReportUser::ROLE_VIEWER,
            'is_active' => true,
        ]);

        $session = [
            'informes_authenticated' => true,
            'report_user_id' => $viewer->id,
            'report_user_role' => ReportUser::ROLE_ADMIN,
            'report_user_email' => $viewer->email,
        ];

        $this->withSession($session)
            ->get('/informes/usuarios')
            ->assertForbidden();

        $this->withSession($session)
            ->get('/informes/leads')
            ->assertOk()
            ->assertSessionHas('report_user_role', ReportUser::ROLE_VIEWER);
    }

    public function test_usuario_inactivo_no_puede_reutilizar_una_sesion(): void
    {
        $inactive = ReportUser::query()->create([
            'email' => 'inactive@example.test',
            'password' => Hash::make('inactive-test-password'),
            'role' => ReportUser::ROLE_ADMIN,
            'is_active' => false,
        ]);

        $this->withSession([
            'informes_authenticated' => true,
            'report_user_id' => $inactive->id,
            'report_user_role' => ReportUser::ROLE_ADMIN,
        ])->get('/informes/leads')->assertRedirect('/login');
    }

    public function test_login_incorrecto_repetido_se_bloquea_sin_revelar_el_usuario(): void
    {
        config()->set('auth.report_login.max_attempts', 2);
        config()->set('auth.report_login.decay_seconds', 60);
        RateLimiter::clear('report-login:test');

        foreach (range(1, 2) as $attempt) {
            $this->from('/login')->post('/login', [
                'email' => 'unknown@example.test',
                'password' => 'incorrect-password-'.$attempt,
            ])->assertRedirect('/login')->assertSessionHasErrors('email');
        }

        $this->from('/login')->post('/login', [
            'email' => 'unknown@example.test',
            'password' => 'incorrect-password-final',
        ])->assertStatus(429)->assertSessionHasErrors('email');
    }

    public function test_login_rechaza_passwords_sobredimensionadas_antes_del_hash(): void
    {
        $this->from('/login')->post('/login', [
            'email' => 'unknown@example.test',
            'password' => str_repeat('x', 256),
        ])->assertRedirect('/login')->assertSessionHasErrors('password');
    }
}
