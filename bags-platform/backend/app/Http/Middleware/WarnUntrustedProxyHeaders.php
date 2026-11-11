<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * H6: TRUSTED_PROXIES=none behind a real proxy turns every IP-based limiter
 * into one global bucket. Warn once per worker without leaking client data.
 */
class WarnUntrustedProxyHeaders
{
    private static bool $warned = false;

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::$warned
            && trim((string) config('app.trusted_proxies')) === 'none'
            && $request->headers->has('X-Forwarded-For')) {
            self::$warned = true;
            logger()->warning(
                'TRUSTED_PROXIES is none but a request carried X-Forwarded-For; IP-based limits and audit IPs are unreliable behind a proxy.',
            );
        }

        return $next($request);
    }
}
