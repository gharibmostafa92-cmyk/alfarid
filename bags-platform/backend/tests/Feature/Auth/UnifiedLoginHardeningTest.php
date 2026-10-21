<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\CustomerStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\SupplierUser;
use App\Models\User;
use App\Rules\EmailNotUsedByOtherAccountType;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UnifiedLoginHardeningTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_supplier_login_rejected_when_company_inactive(): void
    {
        $supplier = Supplier::factory()->create(['is_active' => false]);
        $user = SupplierUser::factory()->create(['supplier_id' => $supplier->id, 'password' => 'sup-pass']);

        $this->login(['login' => $user->email, 'password' => 'sup-pass'])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'بيانات الدخول غير صحيحة.');

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_me_returns_destination_subtype_with_separate_customer_type(): void
    {
        $customer = Customer::factory()->wholesale()->create(['password' => 'cust-pass']);
        $token = (string) $this->login(['login' => $customer->phone, 'password' => 'cust-pass'])->json('token');

        $this->authed('get', '/api/v1/auth/me', $token)
            ->assertOk()
            ->assertJsonPath('actor.type', 'customer')
            ->assertJsonPath('actor.subtype', 'wholesale_pending')
            ->assertJsonPath('actor.customer_type', 'wholesale');
    }

    public function test_array_login_returns_validation_error_not_server_error(): void
    {
        $this->login(['login' => ['01012345678'], 'password' => 'x'])
            ->assertStatus(422);
    }

    public function test_phone_format_variants_share_one_rate_limit_bucket(): void
    {
        foreach (['01012345678', '010 1234 5678', '+201012345678', '00201012345678'] as $variant) {
            $this->login(['login' => $variant, 'password' => 'wrong']);
        }
        // الخامسة مسموحة (5/دقيقة)، والسادسة بصيغة مختلفة كانت قبل الإصلاح
        // بعدّاد مستقل فتفلت — الآن تُحجب.
        $this->login(['login' => '01012345678', 'password' => 'wrong'])->assertUnauthorized();
        $this->login(['login' => '+201012345678', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_cashier_with_inactive_branch_is_forbidden(): void
    {
        $branch = Branch::factory()->create(['is_active' => false]);
        $cashier = User::factory()->create(['is_active' => true, 'branch_id' => $branch->id]);
        $cashier->assignRole(Role::firstOrCreate(['name' => 'cashier', 'guard_name' => 'staff']));

        $this->login(['login' => $cashier->email, 'password' => 'password'])
            ->assertForbidden();
    }

    public function test_customer_login_reports_cart_merge_result(): void
    {
        $customer = Customer::factory()->create(['password' => 'cust-pass']);

        $this->login(['login' => $customer->phone, 'password' => 'cust-pass'])
            ->assertOk()
            ->assertJsonPath('cart_merged', true);
    }

    public function test_supplier_tokens_are_capped(): void
    {
        config()->set('auth.supplier_max_tokens', 2);
        $user = SupplierUser::factory()->create(['password' => 'sup-pass']);

        for ($i = 0; $i < 3; $i++) {
            $this->login(['login' => $user->email, 'password' => 'sup-pass'])->assertOk();
        }

        $this->assertSame(2, $user->tokens()->count());
    }

    public function test_rejected_retail_customer_goes_to_application_status(): void
    {
        $customer = Customer::factory()->create([
            'password' => 'cust-pass', 'status' => CustomerStatus::Rejected,
            'rejection_reason' => 'بيانات ناقصة',
        ]);

        $response = $this->login(['login' => $customer->phone, 'password' => 'cust-pass']);
        $response->assertOk()
            ->assertJsonPath('redirect.path', '/wholesale/application-status')
            ->assertJsonPath('actor.subtype', 'wholesale_pending');

        $this->authed('get', '/api/v1/wholesale/auth/application-status', (string) $response->json('token'))
            ->assertOk()
            ->assertJsonPath('data.rejection_reason', 'بيانات ناقصة');
    }

    public function test_unverified_customer_email_does_not_block_other_account_types(): void
    {
        Customer::factory()->create(['email' => 'pending-cust@example.test', 'email_verified_at' => null]);

        $failed = false;
        (new EmailNotUsedByOtherAccountType('users'))->validate(
            'email', 'pending-cust@example.test', function () use (&$failed): void { $failed = true; }
        );
        $this->assertFalse($failed);
    }

    private function login(array $body)
    {
        Auth::forgetGuards();

        return $this->postJson('/api/v1/auth/login', $body);
    }

    private function authed(string $method, string $uri, string $token, array $data = [])
    {
        Auth::forgetGuards();

        return $method === 'get'
            ? $this->getJson($uri, ['Authorization' => 'Bearer '.$token])
            : $this->postJson($uri, $data, ['Authorization' => 'Bearer '.$token]);
    }
}
