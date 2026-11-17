<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Customer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CartMergedContractTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_store_login_and_register_report_cart_merged_without_a_guest_header(): void
    {
        $customer = Customer::factory()->create(['password' => 'customer-password']);

        $this->postJson('/api/v1/store/auth/login', [
            'login' => $customer->phone,
            'password' => 'customer-password',
        ])->assertOk()->assertJsonPath('cart_merged', true);

        $this->postJson('/api/v1/store/auth/register', [
            'name' => 'New Customer',
            'phone' => '01012345001',
            'password' => 'correct-password',
        ])->assertCreated()->assertJsonPath('cart_merged', true);
    }

    public function test_store_login_and_register_report_cart_merged_for_a_non_uuid_guest_header(): void
    {
        $customer = Customer::factory()->create(['password' => 'customer-password']);

        $this->withHeader('X-Cart-Session', 'not-a-uuid')
            ->postJson('/api/v1/store/auth/login', [
                'login' => $customer->phone,
                'password' => 'customer-password',
            ])->assertOk()->assertJsonPath('cart_merged', true);

        $this->withHeader('X-Cart-Session', 'not-a-uuid')
            ->postJson('/api/v1/store/auth/register', [
                'name' => 'New Customer',
                'phone' => '01012345002',
                'password' => 'correct-password',
            ])->assertCreated()->assertJsonPath('cart_merged', true);
    }

    public function test_store_login_and_register_report_cart_not_merged_when_guest_cart_is_missing(): void
    {
        $customer = Customer::factory()->create(['password' => 'customer-password']);
        $missing = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';

        $this->withHeader('X-Cart-Session', $missing)
            ->postJson('/api/v1/store/auth/login', [
                'login' => $customer->phone,
                'password' => 'customer-password',
            ])->assertOk()->assertJsonPath('cart_merged', false);

        $this->withHeader('X-Cart-Session', $missing)
            ->postJson('/api/v1/store/auth/register', [
                'name' => 'New Customer',
                'phone' => '01012345003',
                'password' => 'correct-password',
            ])->assertCreated()->assertJsonPath('cart_merged', false);
    }
}
