<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser hardening for every web response.
 *
 * CSP: scripts only from this origin plus the nonce'd inline theme script; no framing (the signing
 * page must not be embeddable for clickjacking); pdf.js runs its worker from this origin.
 * Styles allow 'unsafe-inline' because Vue style bindings and the UI kit set inline styles.
 * Skipped while the Vite dev server is running (it serves scripts from another port).
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $hot = Vite::isRunningHot();
        if (! $hot) {
            Vite::useCspNonce();
        }

        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        if ($request->isSecure()) {
            // Browsers ignore (and warn about) COOP on plain-http origins.
            $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        }

        // Keep a stricter policy a controller already set (e.g. the sandboxed logo endpoint).
        if (! $hot && ! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'nonce-".Vite::cspNonce()."'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: blob:",
                "font-src 'self' data:",
                "connect-src 'self'",
                "worker-src 'self' blob:",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
                "frame-ancestors 'none'",
            ]));
        }

        if (app()->isProduction() && $request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
