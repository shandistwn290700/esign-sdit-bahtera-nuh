<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Production sits behind Cloudflare -> tunnel -> Traefik, which reaches the app over plain http, so
 * X-Forwarded-Proto says "http" even for https visitors. Cloudflare's CF-Visitor header carries the
 * visitor's real scheme:
 *  - "http"  -> redirect to the https URL (passwords and session cookies never travel unencrypted);
 *  - "https" -> mark the request secure, so HSTS and other https-only behaviour apply.
 * Without CF-Visitor (health checks, direct internal calls) nothing changes, so no redirect loop.
 * Active only when APP_URL is https and secure session cookies are on (i.e. production).
 */
final class EnforceHttpsBehindCloudflare
{
    public function handle(Request $request, Closure $next): Response
    {
        $appUrl = (string) config('app.url');
        if (! str_starts_with($appUrl, 'https://') || config('session.secure') !== true) {
            return $next($request);
        }

        $visitor = json_decode((string) $request->headers->get('CF-Visitor', ''), true);
        $scheme = is_array($visitor) && is_string($visitor['scheme'] ?? null) ? $visitor['scheme'] : null;

        if ($scheme === 'http') {
            // Build the target from APP_URL, never from the Host header.
            $target = rtrim($appUrl, '/').$request->getRequestUri();
            $status = in_array($request->getMethod(), ['GET', 'HEAD'], true) ? 301 : 308;

            return redirect()->away($target, $status);
        }
        if ($scheme === 'https') {
            $request->headers->set('X-Forwarded-Proto', 'https');
            $request->headers->set('X-Forwarded-Port', '443');
            $request->server->set('HTTPS', 'on');
        }

        // Client IP: Cloudflare APPENDS to a visitor-supplied X-Forwarded-For, and all proxies are
        // trusted, so the left-most (spoofable) entry would become request()->ip(). That would let
        // an attacker rotate fake IPs past the login throttle (keyed by email + IP) and forge IPs
        // in the audit log. CF-Connecting-IP is set by Cloudflare itself and cannot be supplied.
        if ($scheme !== null) {
            $cfIp = (string) $request->headers->get('CF-Connecting-IP', '');
            if (filter_var($cfIp, FILTER_VALIDATE_IP) !== false) {
                $request->headers->set('X-Forwarded-For', $cfIp);
            }
        }

        return $next($request);
    }
}
