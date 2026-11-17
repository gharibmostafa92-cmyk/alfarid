<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class RegisterPhoneShapeTest extends TestCase
{
    use LazilyRefreshDatabase;

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
