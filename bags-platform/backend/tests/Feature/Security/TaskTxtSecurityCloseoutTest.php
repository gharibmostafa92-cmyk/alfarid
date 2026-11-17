<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\Auth\CustomerPhoneVerificationCode;
use App\Notifications\Channels\CustomerSmsChannel;
use App\Rules\TurnstileToken;
use App\Services\Auth\LoginDestinationResolver;
use App\Services\Customer\CustomerMessageBudget;
use App\Services\Customer\CustomerPhoneVerificationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TaskTxtSecurityCloseoutTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_successful_turnstile_token_cannot_be_replayed(): void
    {
        config([
            'cache.default' => 'array',
            'services.turnstile.secret_key' => 'test-secret',
            'services.turnstile.expected_hostname' => 'store.example.test',
        ]);
        Cache::store('array')->flush();
        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response([
                'success' => true,
                'hostname' => 'store.example.test',
            ]),
        ]);

        $validate = static fn () => Validator::make(
            ['turnstile_token' => 'one-time-token'],
            ['turnstile_token' => [new TurnstileToken('192.0.2.10')]],
        )->validate();

        $validate();
        $replay = Validator::make(
            ['turnstile_token' => 'one-time-token'],
            ['turnstile_token' => [new TurnstileToken('192.0.2.10')]],
        );

        $this->assertTrue($replay->fails());
        Http::assertSentCount(1);
    }

    public function test_soft_deleted_customer_phone_can_receive_a_new_verification_code(): void
    {
        Notification::fake();
        $phone = '01001234567';
        Customer::factory()->create(['phone' => $phone])->delete();

        app(CustomerPhoneVerificationService::class)->send(
            $phone,
            CustomerMessageBudget::subnetKey('192.0.2.10'),
        );

        Notification::assertSentOnDemand(CustomerPhoneVerificationCode::class);
    }

    public function test_phone_verification_uses_the_canonical_phone_for_delivery(): void
    {
        Notification::fake();

        app(CustomerPhoneVerificationService::class)->send(
            '+20 100 123 4567',
            CustomerMessageBudget::subnetKey('192.0.2.11'),
        );

        Notification::assertSentOnDemand(
            CustomerPhoneVerificationCode::class,
            static fn ($notification, array $channels, $notifiable): bool => ($notifiable->routes['customer_sms'] ?? null) === '01001234567',
        );
    }

    public function test_failed_sms_delivery_releases_the_message_budget_claim(): void
    {
        $phone = '01001234568';
        $networkKey = CustomerMessageBudget::subnetKey('192.0.2.12');
        $channel = \Mockery::mock(CustomerSmsChannel::class);
        $channel->shouldReceive('send')->once()->andThrow(new \RuntimeException('SMS provider unavailable.'));
        $this->app->instance(CustomerSmsChannel::class, $channel);

        try {
            app(CustomerPhoneVerificationService::class)->send($phone, $networkKey);
        } catch (\RuntimeException) {
            // The failed transport remains visible to the caller, while its quota reservation is refunded.
        }

        $this->assertSame(0, (int) DB::table('customer_message_budgets')->sum('sends'));
    }

    public function test_phone_proof_is_issued_and_checked_for_normalized_phone_numbers(): void
    {
        Notification::fake();
        config(['auth.require_phone_verification' => true]);
        $service = app(CustomerPhoneVerificationService::class);
        $service->send('+20 100 123 4567', CustomerMessageBudget::subnetKey('192.0.2.13'));

        $code = null;
        Notification::assertSentOnDemand(
            CustomerPhoneVerificationCode::class,
            static function ($notification, array $channels, $notifiable) use (&$code): bool {
                preg_match('/(\d{6})$/u', $notification->toSms($notifiable), $matches);
                $code = $matches[1] ?? null;

                return ($notifiable->routes['customer_sms'] ?? null) === '01001234567';
            },
        );
        $this->assertIsString($code);

        $proof = $service->verify('+20 100 123 4567', $code);
        $service->requireProof(Request::create('/register', 'POST', [
            'phone' => '+20 100 123 4567',
            'phone_verification_token' => $proof,
        ]));

        $this->assertSame(64, strlen($proof));
    }

    public function test_unverified_commercial_customer_email_cannot_collide_with_staff_email(): void
    {
        $email = 'commercial-collision@example.test';
        User::factory()->create(['email' => $email]);
        $customer = Customer::factory()->wholesale()->make([
            'email' => $email,
            'email_verified_at' => null,
        ]);

        $this->expectException(ValidationException::class);
        $customer->save();
    }

    public function test_changing_customer_type_to_commercial_rechecks_email_uniqueness(): void
    {
        $email = 'retail-to-commercial@example.test';
        User::factory()->create(['email' => $email]);
        $customer = Customer::factory()->create(['email' => 'legacy-retail@example.test']);
        DB::table('customers')->where('id', $customer->id)->update([
            'email' => $email,
            'email_verified_at' => null,
        ]);
        $customer->refresh();
        $customer->type = CustomerType::Wholesale;
        $customer->status = CustomerStatus::Pending;

        $this->expectException(ValidationException::class);
        $customer->save();
    }

    public function test_pending_customer_cannot_shop(): void
    {
        $customer = Customer::factory()->create(['status' => CustomerStatus::Pending]);

        $this->assertFalse($customer->canShop());
    }

    public function test_admin_cannot_create_a_pending_retail_account(): void
    {
        Permission::findOrCreate('customers.create', 'staff');
        $staff = User::factory()->create(['is_active' => true]);
        $staff->givePermissionTo('customers.create');
        $token = $staff->createToken('test', ['*'])->plainTextToken;

        Auth::forgetGuards();
        $this->withToken($token)->postJson('/api/v1/admin/customers', [
            'name' => 'Pending Retail',
            'phone' => '01001234569',
            'password' => 'secure-password123',
            'type' => 'retail',
            'status' => 'pending',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_rejected_retail_customer_does_not_get_the_wholesale_application_route(): void
    {
        $customer = Customer::factory()->create([
            'type' => CustomerType::Retail,
            'status' => CustomerStatus::Rejected,
        ]);

        $destination = app(LoginDestinationResolver::class)->resolve($customer);

        $this->assertSame('retail', $destination['destination']);
        $this->assertSame('/shop', $destination['path']);
    }

    public function test_retail_redirect_normalizes_its_path_and_preserves_nested_url_query_values(): void
    {
        $customer = Customer::factory()->create([
            'type' => CustomerType::Retail,
            'status' => CustomerStatus::Approved,
        ]);
        $resolver = app(LoginDestinationResolver::class);

        $this->assertSame(
            '/shop?next=https%3A%2F%2Fexample.test',
            $resolver->resolve($customer, '/%73hop?next=https%3A%2F%2Fexample.test')['path'],
        );
        $this->assertSame(
            '/shop?next=https://example.test#section',
            $resolver->resolve($customer, '/shop?next=https://example.test#section')['path'],
        );
    }

    public function test_login_reports_cart_merged_when_no_guest_session_header_is_sent(): void
    {
        Customer::factory()->create([
            'phone' => '01001234570',
            'password' => 'customer-password',
            'type' => CustomerType::Retail,
            'status' => CustomerStatus::Approved,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'login' => '01001234570',
            'password' => 'customer-password',
        ])->assertOk()->assertJsonPath('cart_merged', true);
    }

    public function test_login_does_not_claim_cart_merge_when_the_guest_cart_is_missing(): void
    {
        Customer::factory()->create([
            'phone' => '01001234571',
            'password' => 'customer-password',
            'type' => CustomerType::Retail,
            'status' => CustomerStatus::Approved,
        ]);

        $this->withHeader('X-Cart-Session', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11')
            ->postJson('/api/v1/auth/login', [
                'login' => '01001234571',
                'password' => 'customer-password',
            ])->assertOk()->assertJsonPath('cart_merged', false);
    }

    public function test_login_reports_cart_merge_when_an_existing_guest_cart_is_consumed(): void
    {
        $customer = Customer::factory()->create([
            'phone' => '01001234572',
            'password' => 'customer-password',
            'type' => CustomerType::Retail,
            'status' => CustomerStatus::Approved,
        ]);
        $guestCart = Cart::query()->create(['cart_token' => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12']);

        $this->withHeader('X-Cart-Session', $guestCart->cart_token)
            ->postJson('/api/v1/auth/login', [
                'login' => '01001234572',
                'password' => 'customer-password',
            ])->assertOk()->assertJsonPath('cart_merged', true);

        $this->assertDatabaseMissing('carts', ['id' => $guestCart->id]);
        $this->assertDatabaseHas('carts', ['customer_id' => $customer->id]);
    }
}
