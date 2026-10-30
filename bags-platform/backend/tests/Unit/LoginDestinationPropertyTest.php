<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Customer;
use App\Services\Auth\LoginDestinationResolver;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\TestCase;

class LoginDestinationPropertyTest extends TestCase
{
    public function test_generated_redirects_stay_inside_the_account_destination(): void
    {
        config(['store.retail_home' => '/shop']);
        $resolver = new LoginDestinationResolver;
        $retail = new Customer(['type' => CustomerType::Retail, 'status' => CustomerStatus::Approved]);
        $wholesale = new Customer(['type' => CustomerType::Wholesale, 'status' => CustomerStatus::Approved]);
        $random = new Randomizer(new Mt19937(20261030));
        $fragments = ['shop', 'checkout', 'auth', 'supplier', 'wholesale', 'admin', '.', '..', '%2e%2e',
            '%2F', '%5C', '\\', 'https:', 'evil.com', '%00', '%20', "\x7f", 'منتجات', 'a', ''];
        $paths = ['//evil.com', '/%2F%2Fevil.com', '/\\evil.com', 'https://evil.com', '/shop/../auth',
            '/%2e%2e/admin', '/wholesale', "/shop/\x01", '/wholesale/../supplier', '/%61uth',
            '/%73upplier', '/%77holesale', '/checkout?next=ok', '/wholesale/orders#details'];
        for ($i = 0; $i < 5000; $i++) {
            $parts = [];
            for ($j = 0, $length = $random->getInt(1, 6); $j < $length; $j++) {
                $parts[] = $fragments[$random->getInt(0, count($fragments) - 1)];
            }
            $paths[] = ($i % 2 === 0 ? '/' : '/wholesale/').implode('/', $parts);
        }
        foreach ($paths as $path) {
            foreach ([$retail, $wholesale] as $actor) {
                $result = $resolver->resolve($actor, $path)['path'];
                $decoded = rawurldecode($result);
                $pathname = preg_split('/[?#]/', $decoded, 2)[0];
                $safe = str_starts_with($decoded, '/') && ! str_starts_with($decoded, '//')
                    && ! str_contains($decoded, '\\') && ! str_contains($decoded, '://')
                    && ! preg_match('/[\x00-\x20\x7f]/', $decoded)
                    && array_intersect(explode('/', $pathname), ['.', '..']) === [];
                if ($actor === $retail) {
                    $safe = $safe && ! preg_match('~^/(auth|supplier|wholesale)(/|$)~', $pathname);
                } else {
                    $safe = $safe && ($pathname === '/wholesale' || str_starts_with($pathname, '/wholesale/'));
                }
                $this->assertTrue($safe, 'Unsafe generated redirect: '.json_encode([$path, $result]));
            }
        }
        $this->assertSame('/checkout?next=ok', $resolver->resolve($retail, '/checkout?next=ok')['path']);
        $this->assertSame('/wholesale/orders#details', $resolver->resolve($wholesale, '/wholesale/orders#details')['path']);
    }
}
