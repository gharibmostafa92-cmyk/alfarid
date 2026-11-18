<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Http\Requests\Store\Orders\CheckoutRequest;
use App\Models\Cart;
use App\Models\City;
use App\Models\Customer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class GuestCheckoutRecipientTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_checkout_requires_recipient_name_and_phone(): void
    {
        $city = City::factory()->create();

        $this->postJson('/api/v1/store/checkout', [
            'city_id' => $city->id,
            'shipping_address_line' => 'عنوان الضيف',
            'payment_method' => 'cod',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['recipient_name', 'recipient_phone']);
    }

    public function test_authenticated_checkout_requires_recipient_fields_for_delivery(): void
    {
        $customer = Customer::factory()->create();
        $city = City::factory()->create();

        $this->withToken($customer->createToken('store-token')->plainTextToken)
            ->postJson('/api/v1/store/checkout', [
                'city_id' => $city->id,
                'shipping_address_line' => 'عنوان العميل',
                'payment_method' => 'cod',
            ])->assertUnprocessable()
            ->assertJsonValidationErrors(['recipient_name', 'recipient_phone']);
    }

    public function test_phone_input_that_normalizes_to_empty_is_removed_before_validation(): void
    {
        $request = CheckoutRequest::create('/api/v1/store/checkout', 'POST', ['recipient_phone' => '---']);
        $prepare = new \ReflectionMethod(CheckoutRequest::class, 'prepareForValidation');
        $prepare->invoke($request);

        $this->assertNull($request->input('recipient_phone'));
    }

    public function test_checkout_without_a_saved_cart_does_not_insert_one(): void
    {
        $city = City::factory()->create();
        $cartToken = (string) Str::uuid();

        $this->withHeader('X-Cart-Session', $cartToken)
            ->postJson('/api/v1/store/checkout', [
                'recipient_name' => 'عميل جديد',
                'recipient_phone' => '01012345678',
                'city_id' => $city->id,
                'shipping_address_line' => 'عنوان التوصيل',
                'payment_method' => 'cod',
            ])->assertUnprocessable();

        $this->assertDatabaseMissing('carts', ['cart_token' => $cartToken]);
    }

    public function test_authenticated_checkout_without_a_saved_cart_does_not_insert_one(): void
    {
        $customer = Customer::factory()->create();
        $city = City::factory()->create();

        $this->withToken($customer->createToken('store-token')->plainTextToken)
            ->postJson('/api/v1/store/checkout', [
                'city_id' => $city->id,
                'shipping_address_line' => 'عنوان العميل',
                'payment_method' => 'cod',
            ]);

        $this->assertSame(0, Cart::query()->count());
    }
}
