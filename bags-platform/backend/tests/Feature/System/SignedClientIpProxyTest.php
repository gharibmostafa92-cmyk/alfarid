<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** C1: only a correctly signed client-address header may override REMOTE_ADDR. */
class SignedClientIpProxyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.proxy_client_ip_secret' => 'test-proxy-secret']);
        Route::get('/signed-ip-probe', fn () => response()->json(['ip' => request()->ip()]));
    }

    public function test_a_signed_client_address_replaces_the_proxy_address(): void
    {
        $address = '198.51.100.42';

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
            ->withHeaders([
                'X-Alfarid-Client-IP' => $address,
                'X-Alfarid-Client-IP-Signature' => hash_hmac('sha256', $address, 'test-proxy-secret'),
            ])
            ->get('/signed-ip-probe')
            ->assertOk()
            ->assertJsonPath('ip', $address);
    }

    public function test_a_forged_or_unsigned_header_is_ignored(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
            ->withHeaders([
                'X-Alfarid-Client-IP' => '203.0.113.50',
                'X-Alfarid-Client-IP-Signature' => hash_hmac('sha256', '203.0.113.51', 'test-proxy-secret'),
            ])
            ->get('/signed-ip-probe')
            ->assertOk()
            ->assertJsonPath('ip', '10.0.0.9');

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
            ->withHeaders(['X-Alfarid-Client-IP' => '203.0.113.50'])
            ->get('/signed-ip-probe')
            ->assertOk()
            ->assertJsonPath('ip', '10.0.0.9');
    }

    public function test_the_header_is_ignored_when_no_secret_is_configured(): void
    {
        config(['app.proxy_client_ip_secret' => null]);
        $address = '198.51.100.42';

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
            ->withHeaders([
                'X-Alfarid-Client-IP' => $address,
                'X-Alfarid-Client-IP-Signature' => hash_hmac('sha256', $address, 'test-proxy-secret'),
            ])
            ->get('/signed-ip-probe')
            ->assertOk()
            ->assertJsonPath('ip', '10.0.0.9');
    }
}
