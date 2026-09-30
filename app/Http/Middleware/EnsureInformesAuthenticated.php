<?php

namespace App\Http\Middleware;

use App\Models\ReportUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

class EnsureInformesAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $this->authenticatedReportUser($request)) {
            $this->storeAuthenticatedSession($request, $user);

            return $next($request);
        }

        if ($rememberedUser = $this->rememberedReportUser($request)) {
            $request->session()->regenerate();
            $this->storeAuthenticatedSession($request, $rememberedUser);
            $rememberedUser->forceFill(['last_login_at' => now()])->save();

            return $next($request);
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put('url.intended', $request->fullUrl());
        Cookie::queue(Cookie::forget('report_user_remember'));

        return redirect()->route('login');
    }

    private function authenticatedReportUser(Request $request): ?ReportUser
    {
        if (! (bool) $request->session()->get('informes_authenticated', false)) {
            return null;
        }

        $id = $request->session()->get('report_user_id');

        if ($id === null) {
            return null;
        }

        $user = ReportUser::query()->whereKey($id)->where('is_active', true)->first();

        if (! $user) {
            return null;
        }

        $sessionPasswordChangedAt = $request->session()->get('report_user_password_changed_at');
        $currentPasswordChangedAt = $user->password_changed_at?->getTimestamp();

        return $sessionPasswordChangedAt === $currentPasswordChangedAt ? $user : null;
    }

    private function rememberedReportUser(Request $request): ?ReportUser
    {
        $cookie = (string) $request->cookie('report_user_remember');

        if ($cookie === '' || ! str_contains($cookie, '|')) {
            return null;
        }

        [$id, $token] = explode('|', $cookie, 2);
        $user = ReportUser::query()
            ->whereKey($id)
            ->where('is_active', true)
            ->first();

        if (! $user) {
            return null;
        }

        $expected = hash_hmac(
            'sha256',
            implode('|', [$user->id, $user->email, $user->password]),
            (string) config('app.key')
        );

        return hash_equals($expected, $token) ? $user : null;
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
}
