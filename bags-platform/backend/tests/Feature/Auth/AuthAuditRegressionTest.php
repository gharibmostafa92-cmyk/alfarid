<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Exceptions\BusinessException;
use App\Http\Middleware\EnsureOpenPosShift;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Services\Auth\NetworkIdentity;
use App\Services\Auth\UnifiedAuthService;
use App\Services\CartService;
use App\Services\CustomerAuthService;
use App\Services\CustomerMessageBudget;
use App\Services\CustomerPhoneVerificationService;
use App\Services\WholesaleRegistrationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthAuditRegressionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_identifier_hour_quota_allows_correct_credentials(): void
    {
        $customer = Customer::factory()->create(['password' => 'correct-password']);
        $key = 'unified-login:failures:'.hash_hmac('sha256', $customer->phone, (string) config('app.key')).':hour';
        RateLimiter::hit($key, 3600);
        config(['auth.unified_login_identifier_failures_hour' => 1]);

        $this->assertSame($customer->id, app(UnifiedAuthService::class)->attempt($customer->phone, 'correct-password')->id);
    }

    public function test_verification_cannot_consume_reserved_recovery_budget(): void
    {
        config(['auth.customer_sms_hourly_limit' => 5, 'auth.customer_sms_daily_limit' => 5]);
        $budget = app(CustomerMessageBudget::class);
        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($budget->claim('verify:'.$i));
        }
        $this->assertFalse($budget->claim('verify:fourth'));
        $this->assertTrue($budget->claim('reset:customer:1'));
        $this->assertTrue($budget->claim('reset:customer:2'));
        $this->assertFalse($budget->claim('reset:customer:3'));
    }

    public function test_registration_succeeds_when_guest_cart_merge_fails(): void
    {
        $this->mock(CartService::class)
            ->shouldReceive('mergeGuestCart')->once()->andThrow(new \RuntimeException('Cart unavailable'));
        $this->postJson('/api/v1/store/auth/register', [
            'name' => 'Customer', 'phone' => '01012345678', 'password' => 'correct-password',
        ])->assertCreated()->assertJsonPath('cart_merged', false)->assertJsonStructure(['token']);
        $this->assertDatabaseHas('customers', ['phone' => '01012345678']);
    }

    public function test_country_code_without_plus_is_normalized(): void
    {
        $this->assertSame('01012345678', Customer::normalizePhone('201012345678'));
    }

    public function test_ipv6_rotation_shares_the_network_budget(): void
    {
        $first = Request::create('/', server: ['REMOTE_ADDR' => '2001:db8:abcd:1234::1']);
        $second = Request::create('/', server: ['REMOTE_ADDR' => '2001:db8:abcd:1234::ffff']);
        $other = Request::create('/', server: ['REMOTE_ADDR' => '2001:db8:abcd:5678::1']);
        $this->assertSame(NetworkIdentity::key($first), NetworkIdentity::key($second));
        $this->assertNotSame(NetworkIdentity::key($first), NetworkIdentity::key($other));
        $mapped = Request::create('/', server: ['REMOTE_ADDR' => '::ffff:192.0.2.1']);
        $ipv4 = Request::create('/', server: ['REMOTE_ADDR' => '192.0.2.1']);
        $this->assertSame(NetworkIdentity::key($mapped), NetworkIdentity::key($ipv4));
    }

    public function test_verification_network_cap_does_not_block_other_networks_or_recovery(): void
    {
        config(['auth.customer_sms_verification_network_daily_limit' => 1]);
        $budget = app(CustomerMessageBudget::class);
        $this->assertTrue($budget->claim('verify:first', networkKey: 'network-a'));
        $this->assertFalse($budget->claim('verify:second', networkKey: 'network-a'));
        $this->assertTrue($budget->claim('verify:third', networkKey: 'network-b'));
        $this->assertTrue($budget->claim('reset:customer:1'));
    }

    public function test_otp_supports_new_hmac_and_existing_bcrypt_challenges(): void
    {
        Notification::fake();
        $service = app(CustomerPhoneVerificationService::class);
        $phone = '01012345678';
        $key = hash('sha256', $phone);
        $service->send($phone, 'network-a');
        $row = DB::table('customer_phone_verifications')->where('phone_key', $key)->first();
        $this->assertStringStartsWith('hmac:', $row->code_hash);
        DB::table('customer_phone_verifications')->where('phone_key', $key)->update([
            'code_hash' => 'hmac:'.hash_hmac('sha256', $key.':123456', (string) config('app.key')),
        ]);
        $this->assertSame(64, strlen($service->verify($phone, '123456')));
        DB::table('customer_phone_verifications')->where('phone_key', $key)->update([
            'code_hash' => Hash::make('654321'), 'verified_at' => null,
        ]);
        $this->assertSame(64, strlen($service->verify($phone, '654321')));
    }

    public function test_staff_without_pos_permission_cannot_open_shifts(): void
    {
        $user = User::factory()->create();
        $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson('/api/v1/pos/shifts/open', [])->assertForbidden();
    }

    public function test_pos_only_token_cannot_access_staff_administration(): void
    {
        $user = User::factory()->create();
        $this->withToken($user->createToken('pos', ['pos'])->plainTextToken)
            ->postJson('/api/v1/admin/auth/logout')->assertForbidden()
            ->assertJsonPath('message', 'التوكن غير مصرح له بهذه الواجهة.');
    }

    public function test_legacy_pos_login_enforces_token_lifetime_count_and_active_branch(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['branch_id' => $branch->id, 'password' => 'staff-password']);
        $user->assignRole(Role::findOrCreate('cashier', 'staff'));
        $user->givePermissionTo(Permission::findOrCreate('pos.access', 'staff'));
        config(['auth.staff_max_tokens' => 2, 'auth.staff_token_minutes' => 120]);
        $payload = ['email' => $user->email, 'password' => 'staff-password', 'branch_id' => $branch->id];
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/pos/auth/login', $payload)->assertOk()->assertJsonStructure(['token']);
        }
        $this->assertSame(2, $user->tokens()->count());
        foreach ($user->tokens()->get() as $token) {
            $this->assertNotNull($token->expires_at);
            $this->assertTrue($token->expires_at->between(now()->addMinutes(119), now()->addMinutes(121)));
        }
        $branch->update(['is_active' => false]);
        $this->postJson('/api/v1/pos/auth/login', $payload)->assertNotFound();
    }

    public function test_staff_login_success_and_failure_are_persisted_without_credentials(): void
    {
        $user = User::factory()->create(['password' => 'staff-password']);
        app(UnifiedAuthService::class)->attempt($user->email, 'staff-password');
        try {
            app(UnifiedAuthService::class)->attempt($user->email, 'wrong-password');
            $this->fail('Wrong password accepted.');
        } catch (BusinessException $exception) {
            $this->assertSame(401, $exception->status);
        }
        $this->assertDatabaseHas('staff_audit_logs', ['action' => 'authentication.success', 'actor_id' => $user->id, 'target_id' => $user->id]);
        $this->assertDatabaseHas('staff_audit_logs', ['action' => 'authentication.wrong_password', 'actor_id' => null, 'target_id' => $user->id]);
        foreach (DB::table('staff_audit_logs')->pluck('metadata') as $metadata) {
            $this->assertStringNotContainsString('staff-password', $metadata);
            $this->assertStringNotContainsString($user->email, $metadata);
        }
    }

    public function test_refunds_require_the_additional_refund_permission(): void
    {
        $this->withoutMiddleware(EnsureOpenPosShift::class);
        $sale = Order::factory()->create();
        $user = User::factory()->create();
        $permission = Permission::findOrCreate('pos.access', 'staff');
        $user->givePermissionTo($permission);
        $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson('/api/v1/pos/sales/'.$sale->id.'/return', [])->assertForbidden();
    }

    public function test_registration_rejects_multibyte_passwords_exceeding_bcrypt_limit(): void
    {
        $this->postJson('/api/v1/store/auth/register', [
            'name' => 'Customer', 'phone' => '01012345678', 'password' => str_repeat('ع', 37),
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_budget_counter_accepts_values_above_smallint(): void
    {
        $date = now(config('app.business_timezone', 'Africa/Cairo'))->toDateString();
        DB::table('customer_message_budgets')->insert([
            'key' => hash('sha256', 'reset:customer:1'), 'date' => $date, 'sends' => 32767,
        ]);
        config(['auth.customer_daily_messages' => 40000]);
        $this->assertTrue(app(CustomerMessageBudget::class)->claim('reset:customer:1', sms: false));
        $this->assertDatabaseHas('customer_message_budgets', ['key' => hash('sha256', 'reset:customer:1'), 'sends' => 32768]);
    }

    public function test_wholesale_moves_documents_before_opening_registration_transaction(): void
    {
        Customer::factory()->create();
        $level = DB::transactionLevel();
        $disk = Storage::fake('r2');
        $proxy = \Mockery::mock($disk);
        $proxy->shouldReceive('move')->once()->andReturnUsing(function ($from, $to) use ($disk, $level): bool {
            $this->assertSame($level, DB::transactionLevel());

            return $disk->move($from, $to);
        });
        Storage::shouldReceive('disk')->with('r2')->andReturn($proxy);
        $result = app(WholesaleRegistrationService::class)->register([
            'name' => 'Trader', 'phone' => '01012345678', 'password' => 'secure-trader-password',
            'company_name' => 'Company', 'tax_number' => '12345', 'commercial_register' => '56789',
        ], [UploadedFile::fake()->create('tax.pdf', 10, 'application/pdf')]);
        $document = $result['customer']->documents->sole();
        $this->assertStringStartsWith('customer_documents/'.$result['customer']->id.'/', $document->file_path);
        $disk->assertExists($document->file_path);
        $this->assertNotEmpty($result['token']);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_rehash_never_overwrites_a_concurrent_customer_password_reset(bool $unified): void
    {
        $customer = Customer::factory()->wholesale()->create(['password' => 'old-password']);
        $original = $customer->password;
        $resetHash = Hash::make('reset-password');
        $replacement = Hash::make('old-password');
        Hash::shouldReceive('check')->with('old-password', $original)->andReturnTrue();
        Hash::shouldReceive('needsRehash')->with($original)->andReturnTrue();
        Hash::shouldReceive('make')->with('old-password')->andReturnUsing(function () use ($customer, $resetHash, $replacement): string {
            DB::table('customers')->where('id', $customer->id)->update(['password' => $resetHash]);

            return $replacement;
        });
        try {
            if ($unified) {
                app(UnifiedAuthService::class)->attempt($customer->email, 'old-password');
            } else {
                app(CustomerAuthService::class)->login($customer->phone, 'old-password');
            }
            $this->fail('A stale password snapshot must be rejected.');
        } catch (BusinessException $exception) {
            $this->assertSame(401, $exception->status);
        }
        $this->assertSame($resetHash, $customer->fresh()->password);
    }
}
