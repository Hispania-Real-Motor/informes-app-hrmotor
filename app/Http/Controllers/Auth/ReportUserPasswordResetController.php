<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\ReportUserPasswordResetMail;
use App\Models\ReportUser;
use App\Services\Auth\ReportUserPasswordResetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ReportUserPasswordResetController extends Controller
{
    private const PUBLIC_STATUS = 'Si existe una cuenta activa asociada a ese correo, recibirás un enlace para restablecer tu contraseña.';

    public function showRequestForm(): View
    {
        return view('auth.informes-password-forgot');
    }

    public function sendResetLink(Request $request, ReportUserPasswordResetService $tokens): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $throttleKey = $this->requestThrottleKey($request, $data['email']);
        $maxAttempts = max(1, (int) config('auth.report_password_reset.request_max_attempts', 3));

        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            return back()
                ->withInput($request->only('email'))
                ->with('status', self::PUBLIC_STATUS)
                ->setStatusCode(429);
        }

        RateLimiter::hit(
            $throttleKey,
            max(1, (int) config('auth.report_password_reset.request_decay_seconds', 3600)),
        );

        $user = ReportUser::query()
            ->where('email', $data['email'])
            ->where('is_active', true)
            ->first();

        if ($user) {
            $plainToken = $tokens->createTokenFor($user);

            Mail::to($user->email)->send(new ReportUserPasswordResetMail(
                resetUrl: route('password.reset', ['token' => $plainToken], true),
                expireMinutes: $tokens->expireMinutes(),
            ));
        }

        return back()
            ->withInput($request->only('email'))
            ->with('status', self::PUBLIC_STATUS);
    }

    public function showResetForm(string $token, ReportUserPasswordResetService $tokens): View
    {
        return view('auth.informes-password-reset', [
            'token' => $token,
            'tokenIsValid' => $tokens->tokenRecord($token) !== null,
        ]);
    }

    public function reset(Request $request, ReportUserPasswordResetService $tokens): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'min:12', 'max:255', 'confirmed'],
        ]);

        $throttleKey = $this->resetThrottleKey($request, $data['token']);
        $maxAttempts = max(1, (int) config('auth.report_password_reset.reset_max_attempts', 5));

        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            return back()
                ->withErrors(['token' => 'El enlace no es válido o ha caducado.'])
                ->setStatusCode(429);
        }

        RateLimiter::hit(
            $throttleKey,
            max(1, (int) config('auth.report_password_reset.reset_decay_seconds', 900)),
        );

        if (! $tokens->resetPassword($data['token'], $data['password'])) {
            return back()->withErrors(['token' => 'El enlace no es válido o ha caducado.']);
        }

        RateLimiter::clear($throttleKey);

        return redirect()
            ->route('login')
            ->with('status', 'La contraseña se ha cambiado correctamente. Ya puedes iniciar sesión.');
    }

    private function requestThrottleKey(Request $request, string $email): string
    {
        return 'report-password-reset-request:'.hash('sha256', implode('|', [
            Str::lower(trim($email)),
            (string) $request->ip(),
        ]));
    }

    private function resetThrottleKey(Request $request, string $token): string
    {
        return 'report-password-reset-consume:'.hash('sha256', implode('|', [
            hash('sha256', $token),
            (string) $request->ip(),
        ]));
    }
}
