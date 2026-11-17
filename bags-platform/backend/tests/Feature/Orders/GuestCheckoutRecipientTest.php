<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\City;
use App\Models\Customer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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

    public function test_authenticated_checkout_does_not_require_recipient_fields(): void
    {
        $customer = Customer::factory()->create();
        $city = City::factory()->create();

        $this->withToken($customer->createToken('store-token')->plainTextToken)
            ->postJson('/api/v1/store/checkout', [
                'city_id' => $city->id,
                'shipping_address_line' => 'عنوان العميل',
                'payment_method' => 'cod',
            ])->assertJsonMissingValidationErrors(['recipient_name', 'recipient_phone']);
    }
}
