<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Services\StorePaymentPolicy;
use Illuminate\Support\Carbon;
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

    public function test_instapay_instructions_stop_at_the_exact_expiry_and_invalid_addresses_are_hidden(): void
    {
        config([
            'store.payment_methods' => ['cod', 'bank_transfer'],
            'store.instapay_address' => 'shop@instapay',
            'orders.pending_online_expire_minutes' => 90,
        ]);
        Carbon::setTestNow('2026-11-07 12:00:00');
        $order = new Order([
            'channel' => 'store',
            'payment_method' => 'bank_transfer',
            'payment_status' => 'unpaid',
            'status' => OrderStatus::Pending,
            'grand_total' => '150.00',
        ]);
        $order->created_at = Carbon::parse('2026-11-07 11:50:00');

        $fresh = $this->policy->instapayInstructions($order);
        $this->assertSame('shop@instapay', $fresh['address']);
        $this->assertSame('150.00', $fresh['amount']);
        $this->assertSame(Carbon::parse('2026-11-07 13:20:00')->toIso8601String(), $fresh['expires_at']);

        $order->created_at = Carbon::parse('2026-11-07 10:30:00');
        $this->assertNull($this->policy->instapayInstructions($order));

        $order->payment_status = 'paid';
        $order->created_at = Carbon::parse('2026-11-07 11:50:00');
        $this->assertNull($this->policy->instapayInstructions($order));

        config(['store.instapay_address' => 'abc@gmail.com']);
        $this->assertNull($this->policy->instapayAddress());
        $this->assertNotContains('bank_transfer', $this->policy->allowedFor(null));
        Carbon::setTestNow();
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
