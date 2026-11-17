<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\Customer\CustomerType;
use App\Http\Requests\Admin\Auth\AdminLoginRequest;
use App\Http\Requests\Admin\Customer\StoreCustomerRequest;
use App\Http\Requests\Api\V1\Auth\UnifiedLoginRequest;
use App\Jobs\SendCustomerPasswordReset;
use App\Jobs\SendCustomerPhoneVerification;
use App\Models\Customer;
use App\Models\CustomerAuditLog;
use App\Models\SupplierUser;
use App\Models\User;
use App\Rules\EmailNotUsedByOtherAccountType;
use App\Services\Auth\CustomerAuthService;
use App\Services\Auth\UnifiedAuthService;
use App\Services\Customer\CustomerMessageBudget;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class Task3RoadmapSlice1Test extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_critical_01_unverified_retail_email_does_not_block_staff_or_supplier_and_is_released(): void
    {
        $retail = Customer::factory()->create([
            'email' => 'claimed@example.test',
            'email_verified_at' => null,
            'type' => CustomerType::Retail,
        ]);

        $staffRuleFailed = false;
        (new EmailNotUsedByOtherAccountType('users'))->validate('email', 'claimed@example.test', function () use (&$staffRuleFailed): void {
            $staffRuleFailed = true;
        });
        $this->assertFalse($staffRuleFailed);

        $supplierRuleFailed = false;
        (new EmailNotUsedByOtherAccountType('supplier_users'))->validate('email', 'claimed@example.test', function () use (&$supplierRuleFailed): void {
            $supplierRuleFailed = true;
        });
        $this->assertFalse($supplierRuleFailed);

        $staff = User::factory()->create(['email' => 'claimed@example.test']);
        $this->assertSame('claimed@example.test', $staff->email);
        $this->assertNull($retail->fresh()->email);
        $this->assertTrue(
            CustomerAuditLog::query()
                ->where('customer_id', $retail->id)
                ->where('action', 'unverified_email_released')
                ->exists()
        );
    }

    public function test_critical_01_find_for_login_ignores_unverified_retail_shadow_for_commercial_customer(): void
    {
        Customer::factory()->create([
            'email' => 'trader@example.test',
            'email_verified_at' => null,
            'type' => CustomerType::Retail,
        ]);
        $wholesale = Customer::factory()->create([
            'email' => 'trader@example.test',
            'email_verified_at' => null,
            'type' => CustomerType::Wholesale,
        ]);

        $resolved = app(CustomerAuthService::class)->findForLogin('trader@example.test');
        $this->assertNotNull($resolved);
        $this->assertSame($wholesale->id, $resolved->id);
    }

    public function test_critical_01_store_customer_request_denies_wholesale_without_approve_permission(): void
    {
        Permission::findOrCreate('customers.create', 'staff');
        $staff = User::factory()->create();
        $staff->givePermissionTo('customers.create');

        $request = StoreCustomerRequest::create('/api/v1/admin/customers', 'POST', [
            'type' => CustomerType::Wholesale->value,
        ]);
        $request->setUserResolver(fn () => $staff);

        $this->assertFalse($request->authorize());
    }

    public function test_high_01_customer_message_budget_sorts_keys_deterministically_across_claim_and_release(): void
    {
        $budget = app(CustomerMessageBudget::class);
        $at = \Carbon\CarbonImmutable::now('Africa/Cairo');

        $this->assertTrue($budget->claim('reset:customer:123', sms: true, networkKey: 'net-a', at: $at));
        $budget->releaseResetClaim(123, true, $at, 'net-a');

        $dayCount = (int) DB::table('customer_message_budgets')
            ->where('key', hash('sha256', 'sms:global:day'))
            ->where('date', $at->toDateString())
            ->value('sends');
        $this->assertSame(0, $dayCount);
    }

    public function test_high_02_otp_and_reset_jobs_do_not_retry_and_timeout_preserves_reset_token(): void
    {
        $this->assertSame(1, (new SendCustomerPhoneVerification('01012345678'))->tries);
        $this->assertSame(1, (new SendCustomerPasswordReset('a@example.test', null))->tries);

        $customer = Customer::factory()->create(['email_verified_at' => now()]);
        $key = $customer->getEmailForPasswordReset();
        $plain = str_repeat('x', 64);
        DB::table('password_reset_tokens')->insert([
            'email' => $key,
            'token' => hash('sha256', $plain),
            'created_at' => now(),
        ]);

        try {
            app(CustomerAuthService::class)->handleResetDeliveryFailure(
                $customer,
                $key,
                $plain,
                true,
                'net-1',
                \Carbon\CarbonImmutable::now('Africa/Cairo'),
                new \RuntimeException('cURL error 28: Operation timed out after 5000 milliseconds')
            );
            $this->fail('Expected exception to be rethrown');
        } catch (\RuntimeException) {
        }

        $this->assertDatabaseHas('password_reset_tokens', ['email' => $key, 'token' => hash('sha256', $plain)]);
    }

    public function test_high_03_valid_password_with_403_business_exception_clears_pair_rate_limit(): void
    {
        $customer = Customer::factory()->create([
            'phone' => '01012345678',
            'email' => 'suspended@example.test',
            'email_verified_at' => now(),
            'status' => \App\Enums\Customer\CustomerStatus::Suspended,
            'password' => Hash::make('Secret123!'),
            'type' => CustomerType::Retail,
        ]);

        $request = Request::create('/api/v1/auth/login', 'POST', [
            'login' => 'suspended@example.test',
            'password' => 'Secret123!',
        ], [], [], ['REMOTE_ADDR' => '198.51.100.10']);
        $this->app->instance('request', $request);

        try {
            app(UnifiedAuthService::class)->attempt('suspended@example.test', 'Secret123!');
            $this->fail('Expected 403 suspended account');
        } catch (\App\Exceptions\BusinessException $e) {
            $this->assertSame(403, $e->status);
        }

        $networkKey = \App\Services\Auth\NetworkIdentity::key($request);
        $key = 'unified-login:'.hash_hmac('sha256', 'suspended@example.test', (string) config('app.key'));
        $pairKey = $key.':pair:'.$networkKey;
        $this->assertSame(0, RateLimiter::attempts($pairKey));
    }

    public function test_medium_08_login_requests_enforce_72_byte_password_limit(): void
    {
        $longArabic = str_repeat('ع', 40); // 80 UTF-8 bytes

        $adminRules = (new AdminLoginRequest)->rules();
        $adminValidator = Validator::make(['email' => 'a@example.com', 'password' => $longArabic], $adminRules);
        $this->assertTrue($adminValidator->fails());

        $unifiedRules = (new UnifiedLoginRequest)->rules();
        $unifiedValidator = Validator::make(['login' => 'a@example.com', 'password' => $longArabic], $unifiedRules);
        $this->assertTrue($unifiedValidator->fails());
    }
}
