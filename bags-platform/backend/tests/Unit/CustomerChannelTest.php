<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\CustomerChannel;
use PHPUnit\Framework\TestCase;

/** M6: a short numeric OTP hash must never leak through serialization or mass assignment. */
class CustomerChannelTest extends TestCase
{
    public function test_verification_secret_is_hidden_from_serialization(): void
    {
        $channel = new CustomerChannel;
        $channel->forceFill([
            'customer_id' => 1,
            'phone' => '+201012345678',
            'provider' => 'whatsapp_cloud',
            'verification_code_hash' => '$2y$12$fakehashfortestingpurposes000',
            'verification_attempts' => 0,
        ]);

        $array = $channel->toArray();

        $this->assertArrayNotHasKey('verification_code_hash', $array);
        $this->assertSame('+201012345678', $array['phone']);
    }

    public function test_verification_secret_is_not_mass_assignable(): void
    {
        $channel = new CustomerChannel;

        $this->assertFalse($channel->isFillable('verification_code_hash'));
        $this->assertFalse($channel->isFillable('verification_attempts'));
        $this->assertTrue($channel->isFillable('phone'));
    }
}
