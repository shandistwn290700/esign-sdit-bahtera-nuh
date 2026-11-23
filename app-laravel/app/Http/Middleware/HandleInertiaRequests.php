<?php

namespace App\Http\Middleware;

use App\Services\Branding;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => fn () => app(Branding::class)->name(),
            'branding' => fn () => ['logo_url' => app(Branding::class)->logoUrl()],
            // Only what the UI needs; never signer key ids or 2FA material.
            'auth' => [
                'user' => fn () => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'position' => $request->user()->position,
                    'is_admin' => $request->user()->isAdmin(),
                    'can_sign' => $request->user()->canSign(),
                    'two_factor_enabled' => $request->user()->two_factor_confirmed_at !== null,
                    'email_verified_at' => $request->user()->email_verified_at,
                    'avatar' => null,
                ] : null,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
