<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\WebhookLog;
use PHPUnit\Framework\TestCase;

/** L3: Stripe PII and card fields must not survive into stored webhook payloads. */
class WebhookLogTest extends TestCase
{
    public function test_sensitive_keys_cover_stripe_pii_and_card_fields(): void
    {
        $clean = WebhookLog::sanitizePayload([
            'billing_details' => [
                'name' => 'Jane',
                'phone' => '+201000000000',
                'email' => 'jane@example.com',
                'address' => ['line1' => '1 Main St', 'line2' => 'Apt 2'],
            ],
            'phone' => '+201000000000',
            'card' => ['last4' => '4242', 'fingerprint' => 'fp_123'],
            'order_number' => 'ORD-1',
        ]);

        $this->assertNotNull($clean);
        // The billing_details branch matches as a whole and is redacted in full.
        $this->assertSame('[redacted]', $clean['billing_details']);
        $this->assertSame('[redacted]', $clean['phone']);
        $this->assertSame('[redacted]', $clean['card']['last4']);
        $this->assertSame('[redacted]', $clean['card']['fingerprint']);
        $this->assertSame('ORD-1', $clean['order_number']);
    }
}
