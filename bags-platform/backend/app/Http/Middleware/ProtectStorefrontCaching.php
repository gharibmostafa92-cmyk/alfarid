<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProtectStorefrontCaching
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->setVary('Authorization', false);
        if ($request->bearerToken() !== null || auth('sanctum')->check()) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }
}
