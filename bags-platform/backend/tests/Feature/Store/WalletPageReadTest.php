<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Models\Customer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class WalletPageReadTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_wallet_page_two_returns_the_balance(): void
    {
        $customer = Customer::factory()->create(['wallet_balance' => 25]);

        $this->withToken($customer->createToken('store-token')->plainTextToken)
            ->getJson('/api/v1/store/account/wallet?page=2')
            ->assertOk()
            ->assertJsonPath('balance', '25.00');
    }
}
