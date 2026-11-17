<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Models\Customer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class StorePageMaxValidationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_wallet_page_above_1000_is_rejected_and_page_one_still_works(): void
    {
        $customer = Customer::factory()->create();
        $token = $customer->createToken('store-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/store/account/wallet?page=1001')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('page');

        $this->withToken($token)
            ->getJson('/api/v1/store/account/wallet?page=1')
            ->assertOk()
            ->assertJsonPath('transactions.current_page', 1);
    }
}
