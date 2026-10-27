<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\ProductVariant;
use App\Models\SupplierUser;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UnifiedLoginTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_handoff_rechecks_roles_before_issuing_a_token(): void
    {
        $staff = $this->makeStaff('revoked@example.test', 'secret123');
        $staff->assignRole($this->staffRole('manager'));
        $code = $this->login(['login' => $staff->email, 'password' => 'secret123'])->json('handoff_code');
        $staff->syncRoles([]);

        $this->postJson('/api/v1/auth/handoff/exchange', ['code' => $code])->assertForbidden();

        $this->assertSame(0, $staff->tokens()->count());
    }

    public function test_handoff_uses_current_cashier_destination_when_roles_change(): void
    {
        $staff = $this->makeStaff('changed@example.test', 'secret123', true, Branch::factory()->create()->id);
        $staff->assignRole($this->staffRole('manager'));
        $code = $this->login(['login' => $staff->email, 'password' => 'secret123'])->json('handoff_code');
        $staff->syncRoles([$this->staffRole('cashier')]);

        $this->postJson('/api/v1/auth/handoff/exchange', ['code' => $code])
            ->assertOk()->assertJsonPath('redirect.path', '/dashboard');

        $this->assertSame(1, $staff->tokens()->count());
    }

    #[TestWith(['//evil.example'])]
    #[TestWith(['/%2fevil.example'])]
    #[TestWith(['/checkout%5cevil'])]
    #[TestWith(['/checkout/../../supplier'])]
    #[TestWith(['/%61uth/login'])]
    #[TestWith(['/checkout%0d%0aLocation:evil'])]
    public function test_retail_unsafe_redirect_falls_back_to_store_home(string $redirect): void
    {
        $customer = Customer::factory()->create(['password' => 'cust-pass']);

        $this->login(['login' => $customer->phone, 'password' => 'cust-pass', 'redirect' => $redirect])
            // Retail home is config('store.retail_home', '/shop'): unsafe redirects fall back there.
            ->assertOk()->assertJsonPath('redirect.path', '/shop');
    }

    #[TestWith(['/supplier-other'])]
    #[TestWith(['/supplier/../auth/login'])]
    #[TestWith(['/checkout'])]
    public function test_supplier_redirect_stays_inside_supplier_portal(string $redirect): void
    {
        $supplier = SupplierUser::factory()->create(['password' => 'sup-pass']);

        $this->login(['login' => $supplier->email, 'password' => 'sup-pass', 'redirect' => $redirect])
            ->assertOk()->assertJsonPath('redirect.path', '/supplier');
    }

    public function test_customer_email_must_be_verified_but_normalized_phone_can_log_in(): void
    {
        $customer = Customer::factory()->create([
            'email' => 'unverified@example.test', 'email_verified_at' => null,
            'phone' => '01022334455', 'password' => 'cust-pass',
        ]);

        $this->login(['login' => $customer->email, 'password' => 'cust-pass'])->assertUnauthorized();
        $this->login(['login' => '+201022334455', 'password' => 'cust-pass'])
            ->assertOk()->assertJsonPath('actor.type', 'customer');
    }

    public function test_rejected_distributor_is_sent_to_application_status_with_rejection_reason(): void
    {
        $customer = Customer::factory()->wholesale()->create([
            'type' => CustomerType::Distributor, 'status' => CustomerStatus::Rejected,
            'rejection_reason' => 'مستندات غير مكتملة', 'password' => 'cust-pass',
        ]);

        $login = $this->login(['login' => $customer->phone, 'password' => 'cust-pass']);
        $login->assertOk()->assertJsonPath('redirect.path', '/wholesale/application-status');
        $this->authed('get', '/api/v1/wholesale/auth/application-status', $login->json('token'))
            ->assertOk()->assertJsonPath('data.rejection_reason', 'مستندات غير مكتملة');
    }

    private function staffRole(string $name): Role
    {
        return Role::firstOrCreate(['name' => $name, 'guard_name' => 'staff']);
    }

    private function makeStaff(string $email, string $password, bool $active = true, ?int $branchId = null): User
    {
        return User::factory()->create([
            'email' => $email,
            'password' => $password,
            'is_active' => $active,
            'branch_id' => $branchId,
        ]);
    }

    private function login(array $body, ?string $cartSession = null)
    {
        // حراس Sanctum يخزّنون المستخدم المحلول داخل نفس التطبيق بين الطلبات،
        // لازم ننساهم قبل كل طلب وإلا تسرّب هوية طلب سابق (MultiTableAuthenticationTest يعمل نفس الشيء).
        Auth::forgetGuards();
        $headers = $cartSession !== null ? ['X-Cart-Session' => $cartSession] : [];

        return $this->postJson('/api/v1/auth/login', $body, $headers);
    }

    /** @return array{Authorization: string} */
    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    private function authed(string $method, string $uri, string $token, array $data = [])
    {
        Auth::forgetGuards();

        return $method === 'get'
            ? $this->getJson($uri, $this->bearer($token))
            : $this->postJson($uri, $data, $this->bearer($token));
    }

    public function test_admin_staff_login_with_case_insensitive_email_and_spaces(): void
    {
        $staff = $this->makeStaff('admin@example.test', 'secret123');
        $staff->assignRole($this->staffRole('manager'));

        $response = $this->login(['login' => '  ADMIN@Example.Test  ', 'password' => 'secret123']);

        $response->assertOk()
            ->assertJsonPath('actor.type', 'staff')
            ->assertJsonPath('actor.subtype', 'admin')
            ->assertJsonPath('redirect.app', 'backoffice')
            ->assertJsonPath('redirect.path', '/dashboard')
            ->assertJsonPath('token', null);
        $this->assertSame(64, mb_strlen((string) $response->json('handoff_code')));
    }

    public function test_cashier_with_branch_goes_to_dashboard_and_branchless_cashier_is_forbidden(): void
    {
        $branch = Branch::factory()->create();
        $cashier = $this->makeStaff('cashier@example.test', 'secret123', true, $branch->id);
        $cashier->assignRole($this->staffRole('cashier'));

        $this->login(['login' => 'cashier@example.test', 'password' => 'secret123'])
            ->assertOk()
            ->assertJsonPath('redirect.app', 'backoffice')
            ->assertJsonPath('redirect.path', '/dashboard')
            ->assertJsonPath('actor.subtype', 'pos');

        $branchless = $this->makeStaff('cashier2@example.test', 'secret123');
        $branchless->assignRole($this->staffRole('cashier'));

        $this->login(['login' => 'cashier2@example.test', 'password' => 'secret123'])
            ->assertForbidden();
    }

    public function test_staff_without_roles_is_forbidden_and_inactive_staff_is_unauthorized(): void
    {
        $this->makeStaff('norole@example.test', 'secret123');

        $this->login(['login' => 'norole@example.test', 'password' => 'secret123'])
            ->assertForbidden();

        $this->makeStaff('off@example.test', 'secret123', false);

        $this->login(['login' => 'off@example.test', 'password' => 'secret123'])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'بيانات الدخول غير صحيحة.');
    }

    public function test_handoff_exchange_issues_staff_token_single_use_and_expirable(): void
    {
        $staff = $this->makeStaff('exchange@example.test', 'secret123');
        $staff->assignRole($this->staffRole('manager'));

        $code = (string) $this->login(['login' => 'exchange@example.test', 'password' => 'secret123'])->json('handoff_code');

        Auth::forgetGuards();
        $exchange = $this->postJson('/api/v1/auth/handoff/exchange', ['code' => $code]);
        $exchange->assertOk();
        $token = (string) $exchange->json('token');
        $this->assertNotEmpty($token);

        $this->authed('get', '/api/v1/admin/me', $token)->assertOk();

        // الكود يُستخدم مرة واحدة بس
        Auth::forgetGuards();
        $this->postJson('/api/v1/auth/handoff/exchange', ['code' => $code])->assertUnauthorized();

        $fresh = (string) $this->login(['login' => 'exchange@example.test', 'password' => 'secret123'])->json('handoff_code');
        $this->travel(61)->seconds();
        Auth::forgetGuards();
        $this->postJson('/api/v1/auth/handoff/exchange', ['code' => $fresh])->assertUnauthorized();
        $this->travelBack();
    }

    public function test_active_supplier_login_and_token_access_with_inactive_supplier_rejected(): void
    {
        $supplier = SupplierUser::factory()->create(['email' => 'supplier@example.test', 'password' => 'sup-pass']);

        $response = $this->login(['login' => 'supplier@example.test', 'password' => 'sup-pass']);
        $response->assertOk()
            ->assertJsonPath('actor.type', 'supplier')
            ->assertJsonPath('redirect.app', 'web')
            ->assertJsonPath('redirect.path', '/supplier');
        $this->assertNotEmpty($response->json('token'));

        $this->authed('get', '/api/v1/supplier/dashboard', (string) $response->json('token'))
            ->assertOk();

        SupplierUser::factory()->create(['email' => 'off-supplier@example.test', 'password' => 'sup-pass', 'is_active' => false]);

        $this->login(['login' => 'off-supplier@example.test', 'password' => 'sup-pass'])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'بيانات الدخول غير صحيحة.');
    }

    public function test_retail_customer_login_by_phone_and_verified_email_with_redirect_rules(): void
    {
        $customer = Customer::factory()->create([
            'phone' => '01012345678',
            'email' => 'retail@example.test',
            'password' => 'cust-pass',
        ]);

        $this->login(['login' => $customer->phone, 'password' => 'cust-pass'])
            ->assertOk()
            ->assertJsonPath('actor.type', 'customer')
            ->assertJsonPath('actor.subtype', 'retail')
            ->assertJsonPath('redirect.path', '/shop');

        $this->login(['login' => 'RETAIL@example.test', 'password' => 'cust-pass'])
            ->assertOk()
            ->assertJsonPath('redirect.path', '/shop');

        $this->login(['login' => $customer->phone, 'password' => 'cust-pass', 'redirect' => '/checkout'])
            ->assertOk()
            ->assertJsonPath('redirect.path', '/checkout');

        $this->login(['login' => $customer->phone, 'password' => 'cust-pass', 'redirect' => '//evil.com'])
            ->assertOk()
            ->assertJsonPath('redirect.path', '/shop');
    }

    public function test_wholesale_routing_by_approval_status_and_suspended_is_forbidden(): void
    {
        $pending = Customer::factory()->wholesale()->create(['password' => 'ws-pass']);
        $approved = Customer::factory()->wholesale()->create([
            'status' => CustomerStatus::Approved,
            'password' => 'ws-pass',
        ]);
        $suspended = Customer::factory()->create([
            'type' => CustomerType::Retail,
            'status' => CustomerStatus::Suspended,
            'password' => 'ws-pass',
        ]);

        $this->login(['login' => $pending->phone, 'password' => 'ws-pass'])
            ->assertOk()
            ->assertJsonPath('redirect.path', '/wholesale/application-status')
            ->assertJsonPath('actor.subtype', 'wholesale_pending');

        $this->login(['login' => $approved->phone, 'password' => 'ws-pass'])
            ->assertOk()
            ->assertJsonPath('redirect.path', '/wholesale')
            ->assertJsonPath('actor.subtype', 'wholesale');

        $this->login(['login' => $suspended->phone, 'password' => 'ws-pass'])
            ->assertForbidden();
    }

    public function test_wrong_password_returns_same_message_for_every_account_type(): void
    {
        $this->makeStaff('wrong@example.test', 'right-pass');
        SupplierUser::factory()->create(['email' => 'wrong-supplier@example.test', 'password' => 'right-pass']);
        $customer = Customer::factory()->create(['password' => 'right-pass']);

        foreach (['wrong@example.test', 'wrong-supplier@example.test', $customer->phone] as $login) {
            $this->login(['login' => $login, 'password' => 'nope-nope'])
                ->assertUnauthorized()
                ->assertJsonPath('message', 'بيانات الدخول غير صحيحة.');
        }
    }

    public function test_shared_email_resolves_by_matching_password_with_priority(): void
    {
        $staff = $this->makeStaff('shared@example.test', 'staff-pass');
        $staff->assignRole($this->staffRole('manager'));
        // Existing shared identities remain usable; new writes prevent this legacy state.
        Customer::withoutEvents(fn () => Customer::factory()->create(['email' => 'shared@example.test', 'password' => 'customer-pass']));

        $this->login(['login' => 'shared@example.test', 'password' => 'customer-pass'])
            ->assertOk()
            ->assertJsonPath('actor.type', 'customer');

        $this->login(['login' => 'shared@example.test', 'password' => 'staff-pass'])
            ->assertOk()
            ->assertJsonPath('actor.type', 'staff');
    }

    public function test_sixth_attempt_within_a_minute_is_rate_limited(): void
    {
        $this->makeStaff('limited@example.test', 'right-pass');

        for ($i = 0; $i < 5; $i++) {
            $this->login(['login' => 'limited@example.test', 'password' => 'wrong'])->assertUnauthorized();
        }

        $this->login(['login' => 'limited@example.test', 'password' => 'wrong'])
            ->assertStatus(429);
    }

    public function test_customer_token_cannot_access_admin_and_me_requires_token(): void
    {
        $customer = Customer::factory()->create(['password' => 'cust-pass']);
        $token = (string) $this->login(['login' => $customer->phone, 'password' => 'cust-pass'])->json('token');

        $this->authed('get', '/api/v1/admin/me', $token)->assertForbidden();
        Auth::forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_logout_revokes_token(): void
    {
        $customer = Customer::factory()->create(['password' => 'cust-pass']);
        $token = (string) $this->login(['login' => $customer->phone, 'password' => 'cust-pass'])->json('token');

        $this->authed('post', '/api/v1/auth/logout', $token)->assertNoContent();
        $this->authed('get', '/api/v1/auth/me', $token)->assertUnauthorized();
    }

    public function test_guest_cart_merges_into_customer_account_on_login(): void
    {
        $variant = ProductVariant::factory()->create();
        $guest = Cart::factory()->create();
        CartItem::factory()->create(['cart_id' => $guest->id, 'product_variant_id' => $variant->id, 'qty' => 2]);

        $customer = Customer::factory()->create(['phone' => '01099998888', 'password' => 'cust-pass']);

        $this->login(
            ['login' => $customer->phone, 'password' => 'cust-pass'],
            $guest->cart_token,
        )->assertOk();

        $this->assertDatabaseHas('carts', ['customer_id' => $customer->id]);
        $account = Cart::query()->where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame(2.0, (float) $account->items()->where('product_variant_id', $variant->id)->firstOrFail()->qty);
        $this->assertDatabaseMissing('carts', ['id' => $guest->id]);
    }
}
