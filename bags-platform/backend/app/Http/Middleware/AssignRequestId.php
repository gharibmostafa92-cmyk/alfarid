<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generates or propagates request correlation IDs across HTTP responses, log context, and queued jobs.
 */
final class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $clientId = $request->headers->get('X-Request-ID');
        if (! is_string($clientId) || ! preg_match('/\A[A-Za-z0-9._:-]{8,128}\z/', $clientId)) {
            $clientId = null;
        }
        // L8: a client-supplied id is only adopted behind a trusted proxy that
        // strips spoofed forwarding headers; otherwise the server mints the
        // correlation id and the client value is kept aside for debugging.
        $id = ($clientId !== null && $request->isFromTrustedProxy()) ? $clientId : (string) Str::uuid();

        // Laravel 11 Context propagates request_id into queued jobs dispatched within this lifecycle,
        // while Log::withContext ensures consistent stdout/stderr log formatting in the current process.
        Context::add([
            'request_id' => $id,
            'client_request_id' => $clientId,
            'node' => gethostname(),
            'client_ip' => $request->ip(),
        ]);
        Log::withContext([
            'request_id' => $id,
            'node' => gethostname(),
            'client_ip' => $request->ip(),
        ]);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $id);

        if (config('app.expose_node_header')) {
            $response->headers->set('X-Served-By', (string) gethostname());
        }

        return $response;
    }
}
