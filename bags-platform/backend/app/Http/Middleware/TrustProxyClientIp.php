<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * C1: the storefront proxy is the only component that sees the real client
 * address (Cloud Run reports the proxy's egress IP). It forwards the address
 * with an HMAC because Terraform/IAM invokers could otherwise forge it.
 *
 * Only a valid signature makes the header authoritative. The value is applied
 * to REMOTE_ADDR before any rate limiter runs, so every IP-keyed limiter and
 * audit entry sees the client instead of the proxy.
 */
class TrustProxyClientIp
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('app.proxy_client_ip_secret');
        $address = $request->headers->get('X-Alfarid-Client-IP');
        $signature = $request->headers->get('X-Alfarid-Client-IP-Signature');

        if (is_string($secret) && $secret !== ''
            && is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false
            && is_string($signature) && $signature !== ''
            && hash_equals(hash_hmac('sha256', $address, $secret), strtolower($signature))) {
            $request->server->set('REMOTE_ADDR', $address);
            // Some deployments terminate at a trusted load balancer that reads
            // X-Forwarded-For; keep both views consistent without trusting a
            // client-supplied chain.
            $request->headers->set('X-Forwarded-For', $address);
        }

        return $next($request);
    }
}
