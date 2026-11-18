<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Customer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class RegisterPhoneShapeTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_registration_rejects_invalid_credentials_and_duplicate_customer_phone(): void
    {
        $this->postJson('/api/v1/store/auth/register', [
            'name' => 'Customer', 'phone' => '01912345678', 'password' => '1234567',
        ])->assertUnprocessable()->assertJsonValidationErrors(['phone', 'password']);
        $this->assertDatabaseCount('customers', 0);

        $existing = Customer::factory()->create(['phone' => '01012345678']);
        $this->postJson('/api/v1/store/auth/register', [
            'name' => 'Other customer', 'phone' => $existing->phone, 'password' => 'correct-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_register_rejects_an_array_phone_with_validation_error(): void
    {
        config(['auth.require_phone_verification' => true]);

        $this->postJson('/api/v1/store/auth/register', [
            'name' => 'Customer',
            'phone' => ['01012345678'],
            'password' => 'correct-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }
}
