<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-0003: every account must be active and have login 2FA confirmed before using the application.
 * The active check runs first so a deactivated account is logged out even without 2FA.
 */
final class EnsureTwoFactorEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $next($request);
        }
        if ($user->is_active === false) {
            auth()->guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'Akun Anda dinonaktifkan.']);
        }
        if ($user->two_factor_confirmed_at === null) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => 'Aktifkan autentikasi dua faktor terlebih dahulu untuk melanjutkan.']);

            return redirect()->route('security.edit');
        }

        return $next($request);
    }
}
