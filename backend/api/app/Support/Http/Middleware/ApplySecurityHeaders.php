<?php

declare(strict_types=1);

namespace App\Support\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies hardening headers to every API response.
 *
 * The API returns JSON only, so the content security policy can be maximally
 * restrictive — nothing it serves should ever be rendered as a document or
 * embedded in a frame.
 */
final class ApplySecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Cross-Origin-Resource-Policy' => 'same-site',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; sandbox",
            // Financial data must not sit in a shared or browser cache.
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
        ];

        if ($request->secure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains; preload';
        }

        foreach ($headers as $header => $value) {
            $response->headers->set($header, $value);
        }

        // Leaks the framework and version to anyone probing the service.
        $response->headers->remove('X-Powered-By');
        $response->headers->remove('Server');

        // PHP emits X-Powered-By from the SAPI, below the framework's response
        // object, so removing it above is not enough. Production should also
        // set `expose_php = Off`; this covers environments where it is not.
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        return $response;
    }
}
