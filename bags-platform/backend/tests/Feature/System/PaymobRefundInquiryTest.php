<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Services\Shared\PaymobRefundInquiry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** L2: one auth token per TTL, not one token request per refund webhook. */
class PaymobRefundInquiryTest extends TestCase
{
    public function test_auth_token_is_cached_across_inquiries(): void
    {
        Http::fake([
            'https://accept.paymob.com/api/auth/tokens' => Http::response(['token' => 'cached-token']),
            'https://accept.paymob.com/api/acceptance/transactions/123456' => Http::response([
                'id' => 123456, 'order' => ['id' => 7654321], 'currency' => 'EGP',
                'success' => true, 'pending' => false, 'refunded_amount_cents' => 5000,
            ]),
        ]);

        $config = ['api_key' => 'l2-cache-probe-key', 'currency' => 'EGP'];
        $inquiry = new PaymobRefundInquiry;

        $this->assertSame(50.0, $inquiry->amount('123456', '7654321', $config));
        $this->assertSame(50.0, $inquiry->amount('123456', '7654321', $config));

        // One auth request for two inquiries; each inquiry still hits its own URL.
        Http::assertSentCount(3);
        $authHits = collect(Http::recorded())->filter(
            fn ($pair) => str_ends_with($pair[0]->url(), '/api/auth/tokens')
        );
        $this->assertCount(1, $authHits);
    }

    public function test_provider_auth_rejection_refreshes_and_retries_in_the_same_call(): void
    {
        Http::fake([
            'https://accept.paymob.com/api/auth/tokens' => Http::sequence()
                ->push(['token' => 'expired-token'])
                ->push(['token' => 'replacement-token']),
            'https://accept.paymob.com/api/acceptance/transactions/123456' => Http::sequence()
                ->pushStatus(401)
                ->push([
                    'id' => 123456, 'order' => ['id' => 7654321], 'currency' => 'EGP',
                    'success' => true, 'pending' => false, 'refunded_amount_cents' => 5000,
                ]),
        ]);

        $config = ['api_key' => 'expired-cache-probe-key', 'currency' => 'EGP'];
        $inquiry = new PaymobRefundInquiry;

        // M2: the 401 only costs one extra round-trip; the webhook still gets
        // its verified amount instead of a 503 + provider redelivery.
        $this->assertSame(50.0, $inquiry->amount('123456', '7654321', $config));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/transactions/')
            && $request->hasHeader('Authorization', 'Bearer replacement-token'));
    }

    public function test_cached_token_ttl_stays_below_the_provider_lifetime(): void
    {
        Http::fake([
            'https://accept.paymob.com/api/auth/tokens' => Http::response(['token' => 'ttl-token']),
            'https://accept.paymob.com/api/acceptance/transactions/123456' => Http::response([
                'id' => 123456, 'order' => ['id' => 7654321], 'currency' => 'EGP',
                'success' => true, 'pending' => false, 'refunded_amount_cents' => 5000,
            ]),
        ]);

        $this->assertSame(50.0, (new PaymobRefundInquiry)->amount('123456', '7654321', [
            'api_key' => 'ttl-probe-key', 'currency' => 'EGP',
        ]));

        $cacheKey = 'paymob:refund-auth:'.sha1('https://accept.paymob.com|ttl-probe-key');
        $this->assertTrue(Cache::has($cacheKey));
        $ttl = (new \ReflectionClass(PaymobRefundInquiry::class))->getConstant('TOKEN_TTL_MINUTES');
        $this->assertIsInt($ttl);
        $this->assertLessThanOrEqual(55, $ttl);
    }
}
