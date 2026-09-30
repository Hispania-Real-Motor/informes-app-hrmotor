<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ReportUser;
use App\Support\ReportUserAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

class InformesLoginController extends Controller
{
    private const REMEMBER_DAYS = 30;

    private const DUMMY_PASSWORD_HASH = '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';

    public function show(Request $request): View|RedirectResponse
    {
        if ((bool) $request->session()->get('informes_authenticated', false)
            && $request->session()->get('report_user_id') !== null) {
            return redirect()->route('reports.index');
        }

        return view('auth.informes-login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $throttleKey = $this->loginThrottleKey($request, $credentials['email']);
        $maxAttempts = max(1, (int) config('auth.report_login.max_attempts', 5));

        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Las credenciales no son correctas. Inténtalo de nuevo más tarde.'])
                ->setStatusCode(429);
        }

        $reportUser = $this->reportUserForCredentials($credentials['email'], $credentials['password']);

        if ($reportUser === null) {
            RateLimiter::hit(
                $throttleKey,
                max(1, (int) config('auth.report_login.decay_seconds', 60)),
            );

            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Las credenciales no son correctas.']);
        }

        RateLimiter::clear($throttleKey);

        $request->session()->regenerate();
        $this->storeAuthenticatedSession($request, $reportUser);

        if ($request->boolean('remember')) {
            $this->queueRememberCookie($request, $reportUser);
        } else {
            Cookie::queue(Cookie::forget('informes_remember'));
            Cookie::queue(Cookie::forget('report_user_remember'));
        }

        $fallbackRoute = ReportUserAccess::defaultAccessibleRouteName($request) ?? 'reports.index';

        return redirect()->intended(route($fallbackRoute));
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget([
            'informes_authenticated',
            'informes_user',
            'report_user_id',
            'report_user_email',
            'report_user_role',
            'report_user_name',
        ]);
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Cookie::queue(Cookie::forget('informes_remember'));
        Cookie::queue(Cookie::forget('report_user_remember'));

        return redirect()->route('login');
    }

    private function reportUserForCredentials(string $login, string $password): ?ReportUser
    {
        $user = ReportUser::query()
            ->where('email', $login)
            ->first();

        $passwordMatches = Hash::check($password, $user?->password ?? self::DUMMY_PASSWORD_HASH);

        if (! $user || ! $user->is_active || ! $passwordMatches) {
            return null;
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return $user;
    }

    private function storeAuthenticatedSession(Request $request, ReportUser $user): void
    {
        $request->session()->put('informes_authenticated', true);
        $request->session()->put('informes_user', $user->email);
        $request->session()->put('report_user_id', $user->id);
        $request->session()->put('report_user_email', $user->email);
        $request->session()->put('report_user_role', $user->role);
        $request->session()->put('report_user_name', $user->name);
        $request->session()->put('report_user_password_changed_at', $user->password_changed_at?->getTimestamp());
    }

    private function queueRememberCookie(Request $request, ReportUser $user): void
    {
        Cookie::queue(Cookie::make(
            'report_user_remember',
            $this->reportUserRememberToken($user),
            self::REMEMBER_DAYS * 24 * 60,
            null,
            null,
            (bool) config('session.secure', $request->isSecure()),
            true,
            false,
            'lax'
        ));
        Cookie::queue(Cookie::forget('informes_remember'));
    }

    private function reportUserRememberToken(ReportUser $user): string
    {
        return $user->id.'|'.hash_hmac(
            'sha256',
            implode('|', [$user->id, $user->email, $user->password]),
            (string) config('app.key')
        );
    }

    private function loginThrottleKey(Request $request, string $login): string
    {
        return 'report-login:'.hash('sha256', implode('|', [
            Str::lower(trim($login)),
            (string) $request->ip(),
        ]));
    }
}
