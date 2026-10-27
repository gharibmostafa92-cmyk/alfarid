<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Customer;
use App\Services\StorePaymentPolicy;
use Tests\TestCase;

class StorePaymentPolicyTest extends TestCase
{
    public function test_configured_credit_and_wallet_cannot_bypass_customer_eligibility(): void
    {
        config(['store.payment_methods' => ['cod', 'wallet', 'credit']]);
        $policy = app(StorePaymentPolicy::class);
        $this->assertSame(['cod'], $policy->allowedFor(null));
        $this->assertSame(['cod'], $policy->allowedFor(new Customer(['type' => 'wholesale', 'status' => 'pending'])));
        $this->assertSame(['cod', 'wallet'], $policy->allowedFor(new Customer(['type' => 'retail', 'status' => 'approved'])));
        $this->assertSame(['cod', 'wallet', 'credit'], $policy->allowedFor(new Customer(['type' => 'wholesale', 'status' => 'approved'])));
    }
}
