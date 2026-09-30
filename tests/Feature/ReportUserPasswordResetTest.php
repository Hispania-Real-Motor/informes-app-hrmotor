<?php

namespace Tests\Feature;

use App\Jobs\Auth\SendReportUserPasswordResetLink;
use App\Mail\ReportUserPasswordResetMail;
use App\Models\ReportUser;
use App\Models\ReportUserPasswordResetToken;
use App\Models\User;
use App\Services\Auth\ReportUserPasswordResetService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ReportUserPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected bool $authenticateReportsByDefault = false;

    public function test_get_formulario_de_recuperacion_y_enlace_desde_login(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee(route('password.request'), false);

        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('Recuperar contraseña')
            ->assertSee('Enviar enlace');
    }

    public function test_solicitud_encola_job_para_cualquier_email_valido_con_respuesta_publica_generica(): void
    {
        Queue::fake();
        Mail::fake();

        $active = $this->reportUser('active@example.test', true);
        $inactive = $this->reportUser('inactive@example.test', false);
        $expectedStatus = 'Si existe una cuenta activa asociada a ese correo, recibirás un enlace para restablecer tu contraseña.';

        $this->from(route('password.request'))->post(route('password.email'), [
            'email' => $active->email,
        ])->assertRedirect(route('password.request'))->assertSessionHas('status', $expectedStatus);

        $this->from(route('password.request'))->post(route('password.email'), [
            'email' => 'missing@example.test',
        ])->assertRedirect(route('password.request'))->assertSessionHas('status', $expectedStatus);

        $this->from(route('password.request'))->post(route('password.email'), [
            'email' => $inactive->email,
        ])->assertRedirect(route('password.request'))->assertSessionHas('status', $expectedStatus);

        Queue::assertPushed(SendReportUserPasswordResetLink::class, 3);
        Queue::assertPushed(SendReportUserPasswordResetLink::class, fn (SendReportUserPasswordResetLink $job): bool => $job instanceof ShouldBeEncrypted);
        Mail::assertNothingSent();
        $this->assertDatabaseCount('report_user_password_reset_tokens', 0);
    }

    public function test_solicitud_respeta_case_sensitivity_real_del_email(): void
    {
        Queue::fake();
        Mail::fake();
        $this->reportUser('MixedCase@example.test', true);

        $this->post(route('password.email'), ['email' => 'mixedcase@example.test'])
            ->assertSessionHas('status');

        Queue::assertPushed(SendReportUserPasswordResetLink::class, 1);
        Mail::assertNothingSent();
    }

    public function test_rate_limit_de_solicitud_no_revela_existencia(): void
    {
        Queue::fake();
        Mail::fake();
        config()->set('auth.report_password_reset.request_max_attempts', 1);
        config()->set('auth.report_password_reset.request_decay_seconds', 60);

        $email = 'limited@example.test';
        $this->reportUser($email, true);

        $this->post(route('password.email'), ['email' => $email])
            ->assertSessionHas('status');

        $this->post(route('password.email'), ['email' => $email])
            ->assertStatus(429)
            ->assertSessionHas('status');

        Queue::assertPushed(SendReportUserPasswordResetLink::class, 1);
        Mail::assertNothingSent();
    }

    public function test_job_de_usuario_activo_genera_token_y_envia_correo(): void
    {
        Mail::fake();
        $user = $this->reportUser('job-active@example.test', true);

        $this->runResetJob($user->email);

        $resetUrl = null;
        Mail::assertSent(ReportUserPasswordResetMail::class, function (ReportUserPasswordResetMail $mail) use ($user, &$resetUrl): bool {
            $resetUrl = $mail->resetUrl;

            return $mail->hasTo($user->email)
                && str_starts_with($mail->resetUrl, config('app.url'))
                && str_contains($mail->resetUrl, config('app.url').'/password/reset/');
        });

        $record = ReportUserPasswordResetToken::query()->firstOrFail();
        $this->assertSame($user->id, $record->report_user_id);
        $this->assertFalse(str_contains((string) $resetUrl, $record->token_hash));
    }

    public function test_job_de_usuario_inexistente_o_inactivo_no_envia_correo_ni_genera_token(): void
    {
        Mail::fake();
        $inactive = $this->reportUser('job-inactive@example.test', false);

        $this->runResetJob('missing@example.test');
        $this->runResetJob($inactive->email);

        Mail::assertNothingSent();
        $this->assertDatabaseCount('report_user_password_reset_tokens', 0);
    }

    public function test_job_no_envia_si_usuario_cambia_email_o_se_desactiva_antes_de_ejecutar(): void
    {
        Mail::fake();
        $changed = $this->reportUser('queued-before-change@example.test', true);
        $deactivated = $this->reportUser('queued-before-disable@example.test', true);

        $changed->forceFill(['email' => 'queued-after-change@example.test'])->save();
        $deactivated->forceFill(['is_active' => false])->save();

        $this->runResetJob('queued-before-change@example.test');
        $this->runResetJob('queued-before-disable@example.test');

        Mail::assertNothingSent();
        $this->assertDatabaseCount('report_user_password_reset_tokens', 0);
    }

    public function test_fallo_smtp_no_afecta_respuesta_http_original(): void
    {
        Queue::fake();
        $user = $this->reportUser('smtp-failure@example.test', true);

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHas('status');

        Queue::assertPushed(SendReportUserPasswordResetLink::class);
    }

    public function test_token_valido_actualiza_password_hasheado_y_permita_login_solo_con_password_nuevo(): void
    {
        Mail::fake();
        $user = $this->reportUser('reset@example.test', true, 'old-password-123');

        $token = $this->requestToken($user->email);

        $this->get(route('password.reset', ['token' => $token]))
            ->assertOk()
            ->assertSee('Restablecer contraseña');

        $this->post(route('password.update'), [
            'token' => $token,
            'password' => 'new-password-12345',
            'password_confirmation' => 'new-password-12345',
        ])->assertRedirect(route('login'))->assertSessionHas('status');

        $user->refresh();

        $this->assertTrue(Hash::check('new-password-12345', $user->password));
        $this->assertFalse(Hash::check('old-password-123', $user->password));
        $this->assertNotNull($user->password_changed_at);

        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'old-password-123',
        ])->assertSessionHasErrors('email');

        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'new-password-12345',
        ])->assertRedirect('/informes/leads');
    }

    public function test_token_invalido_expirado_usado_y_reemplazado_no_permite_reset(): void
    {
        Mail::fake();
        $user = $this->reportUser('tokens@example.test', true);

        $firstToken = $this->requestToken($user->email);
        $secondToken = $this->requestToken($user->email);

        $this->post(route('password.update'), [
            'token' => $firstToken,
            'password' => 'new-password-12345',
            'password_confirmation' => 'new-password-12345',
        ])->assertSessionHasErrors('token');

        $this->post(route('password.update'), [
            'token' => str_repeat('a', 64),
            'password' => 'new-password-12345',
            'password_confirmation' => 'new-password-12345',
        ])->assertSessionHasErrors('token');

        ReportUserPasswordResetToken::query()->whereNull('consumed_at')->update(['expires_at' => now()->subMinute()]);

        $this->post(route('password.update'), [
            'token' => $secondToken,
            'password' => 'new-password-12345',
            'password_confirmation' => 'new-password-12345',
        ])->assertSessionHasErrors('token');

        $validToken = $this->requestToken($user->email);

        $this->post(route('password.update'), [
            'token' => $validToken,
            'password' => 'new-password-12345',
            'password_confirmation' => 'new-password-12345',
        ])->assertRedirect(route('login'));

        $this->post(route('password.update'), [
            'token' => $validToken,
            'password' => 'another-password-12345',
            'password_confirmation' => 'another-password-12345',
        ])->assertSessionHasErrors('token');
    }

    public function test_generar_token_serializa_por_usuario_y_no_bloquea_usuarios_distintos(): void
    {
        $first = $this->reportUser('lock-one@example.test', true);
        $second = $this->reportUser('lock-two@example.test', true);
        $service = app(ReportUserPasswordResetService::class);

        $oldToken = $service->createTokenFor($first);
        $newToken = $service->createTokenFor($first->fresh());
        $secondToken = $service->createTokenFor($second);

        $this->assertNull($service->tokenRecord($oldToken));
        $this->assertNotNull($service->tokenRecord($newToken));
        $this->assertNotNull($service->tokenRecord($secondToken));
        $this->assertSame(2, ReportUserPasswordResetToken::query()->whereNull('consumed_at')->count());
        $this->assertSame(1, ReportUserPasswordResetToken::query()->where('report_user_id', $first->id)->whereNull('consumed_at')->count());
        $this->assertSame(1, ReportUserPasswordResetToken::query()->where('report_user_id', $second->id)->whereNull('consumed_at')->count());
    }

    public function test_validacion_de_password_y_confirmacion(): void
    {
        Mail::fake();
        $user = $this->reportUser('validation@example.test', true);
        $token = $this->requestToken($user->email);

        $this->post(route('password.update'), [
            'token' => $token,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->post(route('password.update'), [
            'token' => $token,
            'password' => 'valid-password-123',
            'password_confirmation' => 'different-password-123',
        ])->assertSessionHasErrors('password');
    }

    public function test_usuario_inactivo_o_con_email_cambiado_no_puede_consumir_token(): void
    {
        Mail::fake();
        $inactiveAfterRequest = $this->reportUser('inactive-after@example.test', true);
        $inactiveToken = $this->requestToken($inactiveAfterRequest->email);
        $inactiveAfterRequest->forceFill(['is_active' => false])->save();

        $this->post(route('password.update'), [
            'token' => $inactiveToken,
            'password' => 'new-password-12345',
            'password_confirmation' => 'new-password-12345',
        ])->assertSessionHasErrors('token');

        $changedEmail = $this->reportUser('before-change@example.test', true);
        $changedEmailToken = $this->requestToken($changedEmail->email);
        $changedEmail->forceFill(['email' => 'after-change@example.test'])->save();

        $this->post(route('password.update'), [
            'token' => $changedEmailToken,
            'password' => 'new-password-12345',
            'password_confirmation' => 'new-password-12345',
        ])->assertSessionHasErrors('token');
    }

    public function test_no_modifica_app_user_y_sigue_usando_report_user(): void
    {
        Mail::fake();
        $appUser = User::factory()->create(['email' => 'same@example.test', 'password' => Hash::make('app-old-password')]);
        $reportUser = $this->reportUser('same@example.test', true, 'report-old-password');
        $token = $this->requestToken($reportUser->email);

        $this->post(route('password.update'), [
            'token' => $token,
            'password' => 'report-new-password',
            'password_confirmation' => 'report-new-password',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('app-old-password', $appUser->fresh()->password));
        $this->assertTrue(Hash::check('report-new-password', $reportUser->fresh()->password));
    }

    public function test_remember_cookie_y_sesion_previos_quedan_invalidos_tras_reset(): void
    {
        Mail::fake();
        $user = $this->reportUser('remember@example.test', true, 'old-password-123');
        $rememberToken = $this->rememberTokenFor($user);
        $token = $this->requestToken($user->email);

        $this->post(route('password.update'), [
            'token' => $token,
            'password' => 'new-password-12345',
            'password_confirmation' => 'new-password-12345',
        ])->assertRedirect(route('login'));

        $this->withCookie('report_user_remember', $rememberToken)
            ->get('/informes/leads')
            ->assertRedirect('/login');

        $this->withSession([
            'informes_authenticated' => true,
            'report_user_id' => $user->id,
            'report_user_role' => $user->role,
            'report_user_email' => $user->email,
            'report_user_password_changed_at' => null,
        ])->get('/informes/leads')->assertRedirect('/login');
    }

    public function test_rate_limit_de_consumo_de_token(): void
    {
        Mail::fake();
        config()->set('auth.report_password_reset.reset_max_attempts', 1);
        config()->set('auth.report_password_reset.reset_decay_seconds', 60);
        $user = $this->reportUser('consume-limit@example.test', true);
        $token = $this->requestToken($user->email);

        $this->post(route('password.update'), [
            'token' => str_repeat('b', 64),
            'password' => 'new-password-12345',
            'password_confirmation' => 'new-password-12345',
        ])->assertSessionHasErrors('token');

        $this->post(route('password.update'), [
            'token' => str_repeat('b', 64),
            'password' => 'new-password-12345',
            'password_confirmation' => 'new-password-12345',
        ])->assertStatus(429)->assertSessionHasErrors('token');

        $this->post(route('password.update'), [
            'token' => $token,
            'password' => 'new-password-12345',
            'password_confirmation' => 'new-password-12345',
        ])->assertRedirect(route('login'));
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('report-password-reset-request:test');
        parent::tearDown();
    }

    private function reportUser(string $email, bool $active, string $password = 'old-password-123'): ReportUser
    {
        return ReportUser::query()->create([
            'name' => 'Report User',
            'email' => $email,
            'password' => $password,
            'role' => ReportUser::ROLE_VIEWER,
            'is_active' => $active,
        ]);
    }

    private function requestToken(string $email): string
    {
        $user = ReportUser::query()->where('email', $email)->firstOrFail();

        return app(ReportUserPasswordResetService::class)->createTokenFor($user);
    }

    private function runResetJob(string $email): void
    {
        (new SendReportUserPasswordResetLink($email))->handle(app(ReportUserPasswordResetService::class));
    }

    private function rememberTokenFor(ReportUser $user): string
    {
        return $user->id.'|'.hash_hmac(
            'sha256',
            implode('|', [$user->id, $user->email, $user->password]),
            (string) config('app.key')
        );
    }
}
