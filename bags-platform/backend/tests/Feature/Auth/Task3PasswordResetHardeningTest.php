<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Customer;
use App\Services\Auth\CustomerAuthService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

// task3 HIGH-01 (compare-and-delete) + LOW-01 (72-byte password ceiling).
class Task3PasswordResetHardeningTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_stale_delivery_failure_does_not_delete_a_newer_reset_token(): void
    {
        $customer = Customer::factory()->create(['email_verified_at' => now()]);
        $key = $customer->getEmailForPasswordReset();
        $newPlain = str_repeat('b', 64);
        DB::table('password_reset_tokens')->insert([
            'email' => $key, 'token' => hash('sha256', $newPlain), 'created_at' => now(),
        ]);

        app(CustomerAuthService::class)->deleteStaleResetToken($key, str_repeat('a', 64));

        $this->assertSame(hash('sha256', $newPlain), (string) DB::table('password_reset_tokens')->where('email', $key)->value('token'));
    }

    public function test_matching_delivery_failure_clears_its_own_reset_token(): void
    {
        $customer = Customer::factory()->create(['email_verified_at' => now()]);
        $key = $customer->getEmailForPasswordReset();
        $plain = str_repeat('c', 64);
        DB::table('password_reset_tokens')->insert([
            'email' => $key, 'token' => hash('sha256', $plain), 'created_at' => now(),
        ]);

        app(CustomerAuthService::class)->deleteStaleResetToken($key, $plain);

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $key]);
    }

    public function test_reset_password_rejects_passwords_above_72_bytes(): void
    {
        $customer = Customer::factory()->create(['email_verified_at' => now()]);
        $token = str_repeat('d', 64);
        DB::table('password_reset_tokens')->insert([
            'email' => $customer->getEmailForPasswordReset(),
            'token' => hash('sha256', $token),
            'created_at' => now(),
        ]);
        $hash = $customer->password;

        try {
            app(CustomerAuthService::class)->resetPassword([
                'email' => $customer->email,
                'token' => $token,
                'password' => str_repeat('ع', 40),
            ]);
            $this->fail('Expected a 72-byte password ceiling.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('password', $e->errors());
        }

        $this->assertSame($hash, $customer->refresh()->password);
    }

    public function test_retail_registration_rejects_passwords_above_72_bytes(): void
    {
        try {
            app(CustomerAuthService::class)->registerRetail([
                'name' => 'Byte limit',
                'email' => 'byte-limit@example.test',
                'phone' => '01012345678',
                'password' => str_repeat('e', 73),
            ]);
            $this->fail('Expected a 72-byte password ceiling.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('password', $e->errors());
        }

        $this->assertDatabaseMissing('customers', ['email' => 'byte-limit@example.test']);
    }
}
