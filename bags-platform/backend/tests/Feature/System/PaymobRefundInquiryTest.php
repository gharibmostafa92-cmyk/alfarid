<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Services\Shared\PaymobRefundInquiry;
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
}
