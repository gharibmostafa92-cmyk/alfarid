<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\CorsOrigins;
use PHPUnit\Framework\TestCase;

/** L8: localhost development origins are defaults only outside production. */
class CorsOriginsTest extends TestCase
{
    public function test_production_has_no_localhost_defaults(): void
    {
        $this->assertSame([], CorsOrigins::defaults('production'));
    }

    public function test_non_production_keeps_localhost_defaults(): void
    {
        foreach (['local', 'testing', 'staging'] as $environment) {
            $defaults = CorsOrigins::defaults($environment);
            $this->assertContains('http://localhost:3000', $defaults, $environment);
            $this->assertContains('http://localhost:5173', $defaults, $environment);
            $this->assertContains('http://localhost:3002', $defaults, $environment);
        }
    }
}
