<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Customer;
use App\Notifications\CustomerPasswordReset;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** مهمة 5.2: مصادقة المتجر القطاعي. */
class StoreAuthTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_register_forces_retail_approved_and_returns_working_token(): void
    {
        $response = $this->postJson('/api/v1/store/auth/register', [
            'name' => 'عميل قطاعي',
            'email' => 'retail@example.com',
            'phone' => '01012345678',
            'password' => 'password123',
            // قيم يحاول العميل فرضها — التسجيل يتجاهلها ويفرض retail/approved
            'type' => 'wholesale',
            'status' => 'pending',
        ])->assertCreated()
            ->assertJsonPath('data.type', 'retail')
            ->assertJsonPath('data.status', 'approved');

        $token = $response->json('token');
        $this->assertNotEmpty($token);

        $customer = Customer::where('phone', '01012345678')->firstOrFail();
        $this->assertSame(CustomerType::Retail, $customer->type);
        $this->assertSame(CustomerStatus::Approved, $customer->status);

        // التوكن يعمل فورًا (تسجيل دخول تلقائي)
        $this->withToken($token)->postJson('/api/v1/store/auth/logout')->assertOk();
        // وبعد الخروج يموت التوكن نفسه
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->withToken($token)->postJson('/api/v1/store/auth/logout')->assertUnauthorized();
    }

    public function test_register_validates_unique_phone_and_email_and_password_length(): void
    {
        Customer::factory()->create(['phone' => '01012345678', 'email' => 'taken@example.com']);

        $this->postJson('/api/v1/store/auth/register', [
            'name' => 'X', 'phone' => '01012345678', 'password' => 'password123',
        ])->assertUnprocessable()->assertJsonValidationErrors('phone');

        $this->postJson('/api/v1/store/auth/register', [
            'name' => 'X', 'phone' => '01087654321', 'email' => 'taken@example.com', 'password' => 'password123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->postJson('/api/v1/store/auth/register', [
            'name' => 'X', 'phone' => '01087654321', 'password' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        // البريد اختياري
        $this->postJson('/api/v1/store/auth/register', [
            'name' => 'X', 'phone' => '01087654321', 'password' => 'password123',
        ])->assertCreated();
    }

    public function test_login_by_phone_or_email_and_rejects_bad_credentials(): void
    {
        $customer = Customer::factory()->create([
            'email' => 'login@example.com', 'phone' => '01055554444', 'password' => 'password123',
        ]);

        $this->postJson('/api/v1/store/auth/login', ['login' => '01055554444', 'password' => 'password123'])
            ->assertOk()->assertJsonPath('data.id', $customer->id);
        $this->postJson('/api/v1/store/auth/login', ['login' => 'login@example.com', 'password' => 'password123'])
            ->assertOk();

        $this->postJson('/api/v1/store/auth/login', ['login' => '01055554444', 'password' => 'wrong-password'])
            ->assertUnauthorized();
        $this->postJson('/api/v1/store/auth/login', ['login' => 'nobody@example.com', 'password' => 'password123'])
            ->assertUnauthorized();
    }

    public function test_suspended_customer_cannot_login_but_pending_wholesale_can(): void
    {
        Customer::factory()->create([
            'phone' => '01011112222', 'password' => 'password123',
            'status' => CustomerStatus::Suspended, 'suspension_reason' => 'تأخر سداد',
        ]);
        $this->postJson('/api/v1/store/auth/login', ['login' => '01011112222', 'password' => 'password123'])
            ->assertForbidden();

        Customer::factory()->wholesale()->create(['phone' => '01033334444', 'password' => 'password123']);
        $this->postJson('/api/v1/store/auth/login', ['login' => '01033334444', 'password' => 'password123'])
            ->assertOk();
    }

    public function test_logout_deletes_only_the_current_token(): void
    {
        $customer = Customer::factory()->create();
        $tokenA = $customer->createToken('store-token')->plainTextToken;
        $tokenB = $customer->createToken('store-token')->plainTextToken;

        $this->withToken($tokenA)->postJson('/api/v1/store/auth/logout')->assertOk();
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->withToken($tokenA)->postJson('/api/v1/store/auth/logout')->assertUnauthorized();
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->withToken($tokenB)->postJson('/api/v1/store/auth/logout')->assertOk();
    }

    public function test_forgot_password_creates_reset_token_and_notifies_without_leaking_existence(): void
    {
        Notification::fake();
        $customer = Customer::factory()->create(['email' => 'reset@example.com', 'phone' => '01077776666']);

        $this->postJson('/api/v1/store/auth/forgot-password', ['email' => 'reset@example.com'])->assertOk();
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'reset@example.com']);
        $row = DB::table('password_reset_tokens')->where('email', 'reset@example.com')->first();
        $this->assertNotSame('plain', $row->token); // مخزن مُجزّأ
        Notification::assertSentTo($customer, CustomerPasswordReset::class);

        // بريد غير موجود: نفس الرد، ولا صف جديد
        DB::table('password_reset_tokens')->delete();
        $this->postJson('/api/v1/store/auth/forgot-password', ['email' => 'ghost@example.com'])->assertOk();
        $this->assertDatabaseCount('password_reset_tokens', 0);

        // بالهاتف لمن لا بريد له: يُخزن بالمفتاح المتاح
        $phoneless = Customer::factory()->create(['email' => null, 'phone' => '01066665555']);
        $this->postJson('/api/v1/store/auth/forgot-password', ['phone' => '01066665555'])->assertOk();
        $this->assertDatabaseHas('password_reset_tokens', ['email' => '01066665555']);
    }
}
