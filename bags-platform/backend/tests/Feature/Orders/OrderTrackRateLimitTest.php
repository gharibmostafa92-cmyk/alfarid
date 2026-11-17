<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\Order;
use App\Services\Auth\NetworkIdentity;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class OrderTrackRateLimitTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['TRACK-F11-IP', 'TRACK-F11-GLOBAL', 'TRACK-F11-CLEAR'] as $number) {
            RateLimiter::clear('order-track-order:'.sha1($number));
            foreach (['203.0.113.10', '203.0.113.20', '203.0.113.50', '198.51.100.8'] as $ip) {
                RateLimiter::clear('order-track-ip:'.sha1($number.'|'.$ip));
            }
            foreach (range(1, 8) as $octet) {
                RateLimiter::clear('order-track-ip:'.sha1($number.'|203.0.113.'.$octet));
            }
        }
    }

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

    public function test_failures_from_other_ips_do_not_lock_the_order(): void
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
            ->assertNotFound();
    }

    public function test_successful_track_clears_the_ip_limiter(): void
    {
        $order = Order::factory()->create([
            'order_number' => 'TRACK-F11-CLEAR',
            'guest_phone' => '01012345678',
            'guest_phone_normalized' => '01012345678',
        ]);
        $wrong = "/api/v1/store/orders/{$order->order_number}/track?phone=01000000000";
        $right = "/api/v1/store/orders/{$order->order_number}/track?phone=01012345678";

        foreach (range(1, 4) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
                ->getJson($wrong)
                ->assertNotFound();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
            ->getJson($right)
            ->assertOk();

        foreach (range(1, 5) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
                ->getJson($wrong)
                ->assertNotFound();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
            ->getJson($wrong)
            ->assertTooManyRequests();
    }

    public function test_tracking_caps_distinct_order_numbers_from_one_ip(): void
    {
        $ip = '198.51.100.99';
        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => $ip]);
        RateLimiter::clear('order-tracking-ip:'.NetworkIdentity::forRequest($request));

        foreach (range(1, 10) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->getJson('/api/v1/store/orders/UNKNOWN-'.uniqid((string) $attempt, true).'/track?phone=01012345678')
                ->assertNotFound();
        }

        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->getJson('/api/v1/store/orders/ANOTHER-UNKNOWN/track?phone=01012345678')
            ->assertTooManyRequests();
    }
}
