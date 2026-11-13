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
        $existingVary = $response->headers->get('Vary');
        if ($existingVary) {
            $varyTokens = array_filter(array_map('trim', explode(',', $existingVary)));
            if (! in_array('Authorization', $varyTokens, true)) {
                $varyTokens[] = 'Authorization';
                $response->headers->set('Vary', implode(', ', $varyTokens));
            }
        } else {
            $response->setVary('Authorization', false);
        }
        if ($request->bearerToken() !== null || auth('sanctum')->check()) {
            $response->headers->set('Cache-Control', 'private, no-store');
        } elseif ($request->isMethod('GET') && $response->isSuccessful()) {
            if (! $response->headers->has('Cache-Control')) {
                $response->headers->set('Cache-Control', 'public, max-age=0, s-maxage=60');
            }
        }

        return $response;
    }
}
