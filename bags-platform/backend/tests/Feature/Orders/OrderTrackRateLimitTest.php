<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class OrderTrackRateLimitTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_five_track_failures_block_only_that_ip_until_the_global_cap(): void
    {
        $number = 'TRACK-F11-IP';
        $path = "/api/v1/store/orders/{$number}/track?phone=01012345678";

        foreach (range(1, 5) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
                ->getJson($path)
                ->assertNotFound();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->getJson($path)
            ->assertTooManyRequests();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
            ->getJson($path)
            ->assertNotFound();
    }

    public function test_global_track_cap_blocks_every_ip(): void
    {
        $number = 'TRACK-F11-GLOBAL';
        $path = "/api/v1/store/orders/{$number}/track?phone=01012345678";

        foreach (range(1, 8) as $octet) {
            foreach (range(1, 5) as $attempt) {
                $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$octet}"])
                    ->getJson($path)
                    ->assertNotFound();
            }
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.8'])
            ->getJson($path)
            ->assertTooManyRequests();
    }
}
