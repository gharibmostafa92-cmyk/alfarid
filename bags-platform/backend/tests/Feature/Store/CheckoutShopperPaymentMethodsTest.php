<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Enums\Customer\CustomerStatus;
use App\Http\Requests\Store\Orders\CheckoutRequest;
use App\Models\Customer;
use App\Services\Pos\StorePaymentPolicy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class CheckoutShopperPaymentMethodsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_non_shopper_customer_is_not_offered_account_payment_methods(): void
    {
        config([
            'store.payment_methods' => ['cod', 'wallet', 'credit'],
            'store.instapay_address' => '',
        ]);

        $customer = Customer::factory()->create(['status' => CustomerStatus::Suspended]);
        $this->actingAs($customer, 'sanctum');
        $this->assertNull(Customer::resolveShopper(auth('sanctum')->user()));

        $request = CheckoutRequest::create('/api/v1/store/checkout', 'POST');
        $request->setContainer($this->app)->setRedirector($this->app->make('redirect'));
        $request->setUserResolver(fn () => $customer);

        $methods = (new ReflectionMethod(CheckoutRequest::class, 'allowedPaymentMethods'))->invoke($request);

        $this->assertSame(app(StorePaymentPolicy::class)->allowedFor(null), $methods);
        $this->assertNotContains('wallet', $methods);
        $this->assertNotContains('credit', $methods);
    }
}
