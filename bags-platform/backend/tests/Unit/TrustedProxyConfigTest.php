<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Providers\AppServiceProvider;
use PHPUnit\Framework\TestCase;

class TrustedProxyConfigTest extends TestCase
{
    public function test_explicit_proxies_and_none_are_safe(): void
    {
        $this->assertTrue(AppServiceProvider::trustedProxyConfigIsSafe('10.0.0.10,192.0.2.0/24'));
        $this->assertTrue(AppServiceProvider::trustedProxyConfigIsSafe('10.0.0.0/8'));
        $this->assertTrue(AppServiceProvider::trustedProxyConfigIsSafe('2001:db8::/32'));
        $this->assertTrue(AppServiceProvider::trustedProxyConfigIsSafe('none'));
    }

    public function test_wildcards_empty_and_slash_zero_are_unsafe(): void
    {
        $this->assertFalse(AppServiceProvider::trustedProxyConfigIsSafe(''));
        $this->assertFalse(AppServiceProvider::trustedProxyConfigIsSafe(null));
        $this->assertFalse(AppServiceProvider::trustedProxyConfigIsSafe('*'));
        $this->assertFalse(AppServiceProvider::trustedProxyConfigIsSafe('**'));
        $this->assertFalse(AppServiceProvider::trustedProxyConfigIsSafe('10.0.0.1,*'));
        $this->assertFalse(AppServiceProvider::trustedProxyConfigIsSafe('0.0.0.0/0'));
        $this->assertFalse(AppServiceProvider::trustedProxyConfigIsSafe('::/0'));
        $this->assertFalse(AppServiceProvider::trustedProxyConfigIsSafe('10.0.0.0/8, 0.0.0.0/0'));
    }
}
