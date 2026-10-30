<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->headers->get('X-Request-ID');
        if (! is_string($id) || ! preg_match('/\A[A-Za-z0-9._:-]{8,128}\z/', $id)) {
            $id = (string) Str::uuid();
        }

        // L2: Context (not Log::withContext) propagates request_id to queued
        // jobs and their log records (Laravel 11+); Log context stayed in
        // the HTTP process. Keep Log::withContext too for log formatting.
        Context::add([
            'request_id' => $id,
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
