<?php

use App\Exceptions\DocumentStateException;
use App\Http\Middleware\EnforceHttpsBehindCloudflare;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Services\Signer\SignerException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Behind Coolify's proxy (TLS ends there): trust it so https URLs, secure cookies, HSTS and
        // client IPs (rate limits, audit log) are correct. Unset in development. Only safe when the
        // app port is reachable through that proxy alone (compose.prod.yml publishes no ports).
        if (is_string($proxies = env('TRUSTED_PROXIES')) && $proxies !== '') {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        // Global, so the redirect also covers public pages and the PKI endpoints.
        $middleware->append(EnforceHttpsBehindCloudflare::class);

        $middleware->web(append: [
            SecurityHeaders::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Workflow and Signing Service errors go back to the form as Indonesian messages.
        // TOTP problems attach to the code field; everything else to a general "action" key.
        $exceptions->render(function (SignerException $e, Request $request) {
            if ($request->expectsJson()) {
                return null;
            }
            if (! $e->isUserFixable()) {
                report($e);
            }

            return back()->withErrors([$e->isUserFixable() ? 'totp_code' : 'action' => $e->userMessage()]);
        });
        $exceptions->render(function (DocumentStateException $e, Request $request) {
            return $request->expectsJson() ? null : back()->withErrors(['action' => $e->getMessage()]);
        });
        $exceptions->dontReport([DocumentStateException::class]);
    })->create();
