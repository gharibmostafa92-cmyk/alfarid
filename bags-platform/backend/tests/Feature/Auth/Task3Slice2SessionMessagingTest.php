<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\Customer\CustomerStatus;
use App\Exceptions\BusinessException;
use App\Jobs\SendCustomerPasswordReset;
use App\Jobs\SendCustomerPhoneVerification;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\SupplierUser;
use App\Models\User;
use App\Services\Auth\CustomerAuthService;
use App\Services\Auth\LoginDestinationResolver;
use App\Services\Customer\CustomerMessageBudget;
use App\Services\Customer\CustomerPhoneVerificationService;
use Database\Seeders\CustomerPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Task3Slice2SessionMessagingTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CustomerPermissionSeeder::class);
    }

    private function apiAs(string $token, string $method, string $uri, array $data = []): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->json($method, $uri, $data);
    }

    private function subnetKey(): string
    {
        return CustomerMessageBudget::subnetKey('198.51.100.77');
    }

    public function test_stale_otp_delivery_failure_keeps_newer_code(): void
    {
        config([
            'services.customer_sms.url' => 'https://sms.example.test/send',
            'services.customer_sms.token' => 'secret-sms-token',
        ]);
        Http::fake(['https://sms.example.test/send' => Http::response(['success' => true], 200)]);

        $service = app(CustomerPhoneVerificationService::class);
        $phone = '01010010010';
        $service->send($phone, $this->subnetKey());

        $key = hash_hmac('sha256', $phone, (string) config('app.key'));
        $row = DB::table('customer_phone_verifications')->where('phone_key', $key)->firstOrFail();
        $staleHash = $row->code_hash;
        $staleCreated = $row->created_at;

        // A concurrent newer send overwrote the challenge before the stale
        // delivery attempt reported its failure.
        DB::table('customer_phone_verifications')->where('phone_key', $key)->update([
            'code_hash' => 'hmac:'.hash_hmac('sha256', $key.':999999', (string) config('app.key')),
            'created_at' => now()->addMinute(),
        ]);

        $service->handleDeliveryFailure(
            $key,
            $staleHash,
            $this->subnetKey(),
            Carbon::now()->toImmutable(),
            new \RuntimeException('stale provider timeout')
        );

        $fresh = DB::table('customer_phone_verifications')->where('phone_key', $key)->firstOrFail();
        $this->assertStringStartsWith('hmac:', $fresh->code_hash);
        $this->assertNotSame($staleHash, $fresh->code_hash);
        $this->assertSame(
            Carbon::parse($fresh->created_at)->toDateTimeString(),
            Carbon::parse(now()->addMinute())->toDateTimeString()
        );
        $this->assertNotSame((string) $staleCreated, (string) $fresh->created_at);
    }

    public function test_customer_messaging_jobs_have_retry_backoff(): void
    {
        $this->assertSame(
            [15, 45],
            (new SendCustomerPhoneVerification('01010010011'))->backoff()
        );
        $this->assertSame(
            [15, 45],
            (new SendCustomerPasswordReset(null, '01010010011'))->backoff()
        );
    }

    public function test_password_reset_delivery_failure_releases_budget_and_rethrows(): void
    {
        config([
            'services.customer_sms.url' => 'https://sms.example.test/send',
            'services.customer_sms.token' => 'secret-sms-token',
        ]);
        Http::fake(['https://sms.example.test/send' => Http::response(['error' => true], 500)]);

        $customer = Customer::factory()->wholesale()->create([
            'status' => CustomerStatus::Approved,
            'phone' => '01010010012',
        ]);

        try {
            app(CustomerAuthService::class)->sendPasswordReset(null, '01010010012');
            $this->fail('Delivery failure must propagate so the queue can retry with backoff.');
        } catch (BusinessException $exception) {
            $this->assertSame(503, $exception->status);
        }

        // The undelivered token must not linger: the retry regenerates it.
        $this->assertSame(0, DB::table('password_reset_tokens')->count());

        $date = Carbon::now((string) config('app.business_timezone', 'Africa/Cairo'))->toDateString();
        $sends = (int) DB::table('customer_message_budgets')
            ->where('key', hash('sha256', 'reset:customer:'.$customer->id))
            ->where('date', $date)
            ->value('sends');
        $this->assertSame(0, $sends);
    }

    public function test_login_flood_rejected_before_users_query(): void
    {
        config([
            'auth.unified_login_failures_hour' => 3,
            'services.turnstile.secret_key' => 'test-secret',
        ]);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false], 200)]);

        $staff = User::factory()->create(['password' => 'CorrectStaff123']);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'login' => $staff->email,
                'password' => 'WrongPass123',
            ])->assertUnauthorized();
        }

        $usersQueries = [];
        DB::listen(static function ($query) use (&$usersQueries): void {
            if (str_contains(strtolower($query->sql), 'from "users"')) {
                $usersQueries[] = $query->sql;
            }
        });
        $this->postJson('/api/v1/auth/login', [
            'login' => $staff->email,
            'password' => 'WrongPass123',
        ])->assertStatus(429);

        $this->assertSame([], $usersQueries);
    }

    public function test_pending_customer_cannot_use_store_routes(): void
    {
        $customer = Customer::factory()->wholesale()->create(['status' => CustomerStatus::Pending]);
        $token = $customer->createToken('store', ['store-token'])->plainTextToken;

        $this->apiAs($token, 'GET', '/api/v1/store/account/addresses')->assertForbidden();
    }

    public function test_suspended_customer_can_logout_via_unified_route(): void
    {
        $customer = Customer::factory()->create(['status' => CustomerStatus::Suspended]);
        $token = $customer->createToken('store', ['store-token'])->plainTextToken;

        $this->apiAs($token, 'POST', '/api/v1/auth/logout')->assertNoContent();

        $this->assertSame(0, $customer->tokens()->count());
    }

    public function test_supplier_me_issues_single_supplier_query(): void
    {
        $supplierUser = SupplierUser::factory()->create(['is_active' => true]);
        $token = $supplierUser->createToken('supplier', ['supplier'])->plainTextToken;

        $supplierQueries = [];
        DB::listen(static function ($query) use (&$supplierQueries): void {
            if (str_contains(strtolower($query->sql), '"suppliers"')) {
                $supplierQueries[] = $query->sql;
            }
        });
        $this->apiAs($token, 'GET', '/api/v1/auth/me')->assertOk();

        $this->assertCount(1, $supplierQueries);
    }

    public function test_cashier_branch_gates_pos_destination(): void
    {
        Permission::findOrCreate('pos.access', 'staff');
        $role = Role::findOrCreate('cashier', 'staff');
        $role->syncPermissions(['pos.access']);

        $branch = Branch::factory()->create(['is_active' => true]);
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $cashier->assignRole($role);

        // Works whether or not the caller preloaded the branch relation.
        $this->assertSame('pos', app(LoginDestinationResolver::class)->resolve($cashier)['destination']);

        $branch->update(['is_active' => false]);
        try {
            app(LoginDestinationResolver::class)->resolve($cashier->fresh());
            $this->fail('Cashier on a deactivated branch must be blocked.');
        } catch (BusinessException $exception) {
            $this->assertSame(403, $exception->status);
        }
    }
}
