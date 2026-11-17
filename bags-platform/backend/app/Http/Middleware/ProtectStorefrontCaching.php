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
        $locale = $this->storefrontLocale($request);
        $request->headers->set('Accept-Language', $locale);
        app()->setLocale($locale);

        $response = $next($request);
        $this->mergeVary($response, ['Origin', 'Accept-Language', 'Authorization']);
        if ($this->requestIsPersonalized($request)) {
            $response->headers->set('Cache-Control', 'private, no-store');
        } elseif ($request->isMethod('GET') && $response->isSuccessful()) {
            if (! $response->headers->has('Cache-Control')) {
                $response->headers->set('Cache-Control', 'public, max-age=0, s-maxage=60');
            }
        }

        return $response;
    }

    private function requestIsPersonalized(Request $request): bool
    {
        $sessionCookie = (string) config('session.cookie');

        return $request->headers->has('Authorization')
            || ($sessionCookie !== '' && $request->cookies->has($sessionCookie))
            || auth('sanctum')->check();
    }

    private function storefrontLocale(Request $request): string
    {
        $header = (string) $request->headers->get('Accept-Language', '');
        $range = trim(explode(',', $header, 2)[0]);
        $tag = strtolower(trim(explode(';', $range, 2)[0]));

        return str_starts_with($tag, 'ar') ? 'ar' : 'en';
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
