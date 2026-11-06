<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Customer;
use App\Services\StorePaymentPolicy;
use Tests\TestCase;

class StorePaymentPolicyTest extends TestCase
{
    private StorePaymentPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        config(['store.payment_methods' => ['cod', 'wallet']]);
        $this->policy = new StorePaymentPolicy;
    }

    public function test_wallet_and_credit_follow_account_standing(): void
    {
        $this->assertSame(['cod'], $this->policy->allowedFor(null));
        $this->assertSame(['cod', 'wallet'], $this->policy->allowedFor($this->customer(CustomerType::Retail, CustomerStatus::Approved)));
        $this->assertSame(
            ['cod', 'wallet', 'credit'],
            $this->policy->allowedFor($this->customer(CustomerType::Wholesale, CustomerStatus::Approved)),
        );
        $this->assertSame(['cod'], $this->policy->allowedFor($this->customer(CustomerType::Retail, CustomerStatus::Pending)));
        $this->assertSame(['cod'], $this->policy->allowedFor($this->customer(CustomerType::Distributor, CustomerStatus::Suspended)));
    }

    public function test_wholesale_credit_is_offered_even_when_it_is_absent_from_config(): void
    {
        $this->assertNotContains('credit', config('store.payment_methods'));
        $this->assertContains(
            'credit',
            $this->policy->allowedFor($this->customer(CustomerType::Wholesale, CustomerStatus::Approved)),
        );
    }

    private function customer(CustomerType $type, CustomerStatus $status): Customer
    {
        return new Customer(['type' => $type, 'status' => $status]);
    }
}
