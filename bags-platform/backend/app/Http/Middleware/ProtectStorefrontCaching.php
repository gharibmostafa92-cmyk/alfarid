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
        $this->mergeVary($response, ['Origin', 'Accept-Language', 'Authorization']);
        if ($request->headers->has('Authorization') || auth('sanctum')->check()) {
            $response->headers->set('Cache-Control', 'private, no-store');
        } elseif ($request->isMethod('GET') && $response->isSuccessful()) {
            if (! $response->headers->has('Cache-Control')) {
                $response->headers->set('Cache-Control', 'public, max-age=0, s-maxage=60');
            }
        }

        return $response;
    }

    /**
     * @param  list<string>  $required
     */
    private function mergeVary(Response $response, array $required): void
    {
        $existing = $response->headers->get('Vary');
        $tokens = [];
        if (is_string($existing) && $existing !== '') {
            foreach (explode(',', $existing) as $token) {
                $token = trim($token);
                if ($token !== '' && ! $this->varyContains($tokens, $token)) {
                    $tokens[] = $token;
                }
            }
        }
        foreach ($required as $token) {
            if (! $this->varyContains($tokens, $token)) {
                $tokens[] = $token;
            }
        }
        $response->headers->set('Vary', implode(', ', $tokens));
    }

    /**
     * @param  list<string>  $tokens
     */
    private function varyContains(array $tokens, string $needle): bool
    {
        foreach ($tokens as $token) {
            if (strcasecmp($token, $needle) === 0) {
                return true;
            }
        }

        return false;
    }
}
