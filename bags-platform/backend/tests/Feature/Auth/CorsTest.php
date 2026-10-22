<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Tests\TestCase;

class CorsTest extends TestCase
{
    public function test_configured_lan_origins_can_preflight_login_with_json_headers(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:3000', 'http://192.168.1.3:3000', 'http://192.168.1.3:5173']]);
        foreach (['http://localhost:3000', 'http://192.168.1.3:3000', 'http://192.168.1.3:5173'] as $origin) {
            $this->withHeaders([
                'Origin' => $origin, 'Access-Control-Request-Method' => 'POST',
                'Access-Control-Request-Headers' => 'content-type,x-requested-with,authorization',
            ])->options('/api/v1/auth/login')->assertNoContent()->assertHeader('Access-Control-Allow-Origin', $origin);
        }
    }

    public function test_unlisted_origins_do_not_receive_cors_access(): void
    {
        config(['cors.allowed_origins' => ['http://192.168.1.3:3000']]);
        foreach (['https://untrusted.example.test', 'http://192.168.1.99:3000'] as $origin) {
            $response = $this->withHeaders(['Origin' => $origin, 'Access-Control-Request-Method' => 'POST'])
                ->options('/api/v1/auth/login');
            $this->assertNotSame($origin, $response->headers->get('Access-Control-Allow-Origin'));
            $this->assertNotSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        }
    }
}
