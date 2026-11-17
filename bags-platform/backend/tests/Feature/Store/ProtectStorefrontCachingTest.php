<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Http\Middleware\ProtectStorefrontCaching;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ProtectStorefrontCachingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_vary_includes_origin_language_and_authorization_and_non_bearer_auth_is_private(): void
    {
        $middleware = new ProtectStorefrontCaching;
        $request = Request::create('/api/v1/store/branding', 'GET');
        $request->headers->set('Authorization', 'Basic abc123');
        $request->headers->set('Origin', 'https://shop.example');
        $request->headers->set('Accept-Language', 'ar');

        $response = $middleware->handle($request, function () {
            return response()->json(['ok' => true])
                ->header('Vary', 'Origin, Accept-Encoding')
                ->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=300');
        });

        $vary = array_map('trim', explode(',', (string) $response->headers->get('Vary')));
        $this->assertContains('Origin', $vary);
        $this->assertContains('Accept-Language', $vary);
        $this->assertContains('Authorization', $vary);
        $this->assertContains('Accept-Encoding', $vary);
        $this->assertCacheControl($response, ['private', 'no-store']);
    }

    public function test_authorization_header_without_bearer_token_is_not_publicly_cacheable(): void
    {
        $middleware = new ProtectStorefrontCaching;
        $request = Request::create('/api/v1/store/branding', 'GET');
        $request->headers->set('Authorization', 'Bearer');

        $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertCacheControl($response, ['private', 'no-store']);
    }

    public function test_public_get_without_authorization_keeps_public_caching_and_still_varies(): void
    {
        $middleware = new ProtectStorefrontCaching;
        $request = Request::create('/api/v1/store/cities', 'GET');

        $response = $middleware->handle($request, function () {
            $response = response()->json(['data' => []]);
            $response->headers->remove('Cache-Control');

            return $response;
        });

        $vary = array_map('trim', explode(',', (string) $response->headers->get('Vary')));
        $this->assertContains('Origin', $vary);
        $this->assertContains('Accept-Language', $vary);
        $this->assertContains('Authorization', $vary);
        $this->assertCacheControl($response, ['public', 'max-age=0', 's-maxage=60']);
    }

    /**
     * @param  list<string>  $expected
     */
    private function assertCacheControl(\Symfony\Component\HttpFoundation\Response $response, array $expected): void
    {
        $actual = array_map('trim', explode(',', strtolower((string) $response->headers->get('Cache-Control'))));
        sort($actual);
        $expected = array_map('strtolower', $expected);
        sort($expected);
        $this->assertSame($expected, $actual);
    }
}
