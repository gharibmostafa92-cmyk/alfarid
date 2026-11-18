<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Jobs\SendCustomerPasswordReset;
use App\Models\Customer;
use App\Notifications\Auth\CustomerPasswordReset;
use App\Services\Auth\CustomerAuthService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class Task4AuthPasswordResetTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
    }

    public function test_recovery_notification_uses_the_storefront_route_and_fragment(): void
    {
        config(['app.frontend_url' => 'https://store.example.test']);
        $token = str_repeat('a', 64);
        $notification = new CustomerPasswordReset($token);
        $url = 'https://store.example.test/auth/reset-password#token='.$token;

        $this->assertSame($url, $notification->toMail(new Customer)->actionUrl);
        $this->assertStringContainsString($url, $notification->toSms(new Customer));
    }

    public function test_recovery_request_response_is_the_same_for_known_and_unknown_addresses(): void
    {
        Queue::fake([SendCustomerPasswordReset::class]);
        $customer = Customer::factory()->create(['email_verified_at' => now()]);
        $known = $this->postJson('/api/v1/store/auth/forgot-password', ['email' => $customer->email])
            ->assertOk();
        $unknown = $this->postJson('/api/v1/store/auth/forgot-password', ['email' => 'unknown@example.test'])
            ->assertOk();

        $unknown->assertExactJson($known->json());
    }

    public function test_forgot_password_accepts_email_with_null_phone(): void
    {
        $customer = Customer::factory()->create([
            'email' => 'user.test@example.com',
            'phone' => '01012345678',
        ]);
        $customer->forceFill(['email_verified_at' => now()])->save();

        $response = $this->postJson('/api/v1/store/auth/forgot-password', [
            'email' => 'user.test@example.com',
            'phone' => null,
        ]);

        $response->assertOk();
    }

    public function test_forgot_password_accepts_phone_with_null_email(): void
    {
        Queue::fake([SendCustomerPasswordReset::class]);
        $customer = Customer::factory()->create([
            'phone' => '01012345678',
        ]);

        $response = $this->postJson('/api/v1/store/auth/forgot-password', [
            'email' => null,
            'phone' => '01012345678',
        ]);

        $response->assertOk();
    }

    public function test_forgot_password_accepts_international_egyptian_phone_format(): void
    {
        Queue::fake([SendCustomerPasswordReset::class]);
        Customer::factory()->create(['phone' => '01012345678']);

        $this->postJson('/api/v1/store/auth/forgot-password', [
            'email' => null,
            'phone' => '+20 101 234 5678',
        ])->assertOk();

        Queue::assertPushed(SendCustomerPasswordReset::class, fn ($job) => $job->phone === '01012345678');
    }

    public function test_forgot_password_accepts_email_with_empty_string_phone(): void
    {
        $customer = Customer::factory()->create([
            'email' => 'user.empty@example.com',
            'phone' => '01012345679',
        ]);
        $customer->forceFill(['email_verified_at' => now()])->save();

        $response = $this->postJson('/api/v1/store/auth/forgot-password', [
            'email' => 'user.empty@example.com',
            'phone' => '',
        ]);

        $response->assertOk();
    }

    public function test_forgot_password_rejects_when_both_email_and_phone_provided(): void
    {
        $response = $this->postJson('/api/v1/store/auth/forgot-password', [
            'email' => 'user@example.com',
            'phone' => '01012345678',
        ]);

        $response->assertStatus(422);
    }

    public function test_forgot_password_rejects_when_neither_email_nor_phone_provided(): void
    {
        $response = $this->postJson('/api/v1/store/auth/forgot-password', [
            'email' => null,
            'phone' => null,
        ]);

        $response->assertStatus(422);
    }

    public function test_reset_password_accepts_email_with_empty_string_phone(): void
    {
        $customer = Customer::factory()->create([
            'email' => 'reset.empty@example.com',
        ]);
        $customer->forceFill(['email_verified_at' => now()])->save();

        $token = str_repeat('b', 64);
        DB::table('password_reset_tokens')->insert([
            'email' => 'customer:'.$customer->id,
            'token' => hash('sha256', $token),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/store/auth/reset-password', [
            'email' => 'reset.empty@example.com',
            'phone' => '',
            'token' => $token,
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $response->assertOk();
    }

    public function test_reset_password_accepts_email_with_null_phone(): void
    {
        $customer = Customer::factory()->create([
            'email' => 'reset.test@example.com',
        ]);
        $customer->forceFill(['email_verified_at' => now()])->save();

        $token = str_repeat('a', 64);
        DB::table('password_reset_tokens')->insert([
            'email' => 'customer:'.$customer->id,
            'token' => hash('sha256', $token),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/store/auth/reset-password', [
            'email' => 'reset.test@example.com',
            'phone' => null,
            'token' => $token,
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $response->assertOk();
    }

    public function test_send_password_reset_cooldown_prevents_duplicate_tokens_within_sixty_seconds(): void
    {
        $customer = Customer::factory()->create([
            'email' => 'cooldown@example.com',
        ]);
        $customer->forceFill(['email_verified_at' => now()])->save();

        $service = app(CustomerAuthService::class);
        $service->sendPasswordReset('cooldown@example.com', null);

        $firstTokenRow = DB::table('password_reset_tokens')->where('email', 'customer:'.$customer->id)->first();
        $this->assertNotNull($firstTokenRow);

        // Immediate subsequent call should be ignored by cooldown and retain original token
        $service->sendPasswordReset('cooldown@example.com', null);
        $secondTokenRow = DB::table('password_reset_tokens')->where('email', 'customer:'.$customer->id)->first();

        $this->assertSame($firstTokenRow->token, $secondTokenRow->token);
    }
}
