<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Customer;
use App\Models\SupplierUser;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MultiTableAuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_customer_and_supplier_auth_tables_have_remember_token_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('customers', 'remember_token'));
        $this->assertTrue(Schema::hasColumn('supplier_users', 'remember_token'));
    }

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_test-auth/staff', fn (Request $request) => [
            'id' => $request->user()->getKey(),
            'model' => $request->user()::class,
        ])->middleware('auth.staff');

        Route::get('/_test-auth/customer', fn (Request $request) => [
            'id' => $request->user()->getKey(),
            'model' => $request->user()::class,
        ])->middleware('auth.customer');

        Route::get('/_test-auth/wholesale', fn (Request $request) => [
            'id' => $request->user()->getKey(),
            'model' => $request->user()::class,
        ])->middleware(['auth.customer', 'wholesale.approved']);

        Route::get('/_test-auth/supplier', fn (Request $request) => [
            'id' => $request->user()->getKey(),
            'model' => $request->user()::class,
        ])->middleware('auth.supplier');

        Route::get('/_test-auth/staff-role', fn () => ['ok' => true])
            ->middleware(['auth.staff', 'role:manager']);

        Route::get('/_test-auth/staff-permission', fn () => ['ok' => true])
            ->middleware(['auth.staff', 'permission:inventory.read']);
    }

    public function test_returns_403_for_inactive_staff_and_authenticates_active_staff(): void
    {
        $activeStaff = User::factory()->create(['is_active' => true]);
        $activeToken = $activeStaff->createToken('test')->plainTextToken;

        $this->requestWithToken($activeToken, '/_test-auth/staff')
            ->assertOk()
            ->assertJsonPath('model', User::class);

        $inactiveStaff = User::factory()->create(['is_active' => false]);
        $inactiveToken = $inactiveStaff->createToken('test')->plainTextToken;

        $this->requestWithToken($inactiveToken, '/_test-auth/staff')->assertForbidden();
    }

    public function test_returns_403_for_suspended_customers_but_allows_pending_customers(): void
    {
        $pendingCustomer = $this->createCustomer(status: CustomerStatus::Pending);
        $pendingToken = $pendingCustomer->createToken('test')->plainTextToken;

        $this->requestWithToken($pendingToken, '/_test-auth/customer')
            ->assertOk()
            ->assertJsonPath('model', Customer::class);

        $suspendedCustomer = $this->createCustomer(status: CustomerStatus::Suspended);
        $suspendedToken = $suspendedCustomer->createToken('test')->plainTextToken;

        $this->requestWithToken($suspendedToken, '/_test-auth/customer')->assertForbidden();

        $rejectedCustomer = $this->createCustomer(status: CustomerStatus::Rejected);
        $rejectedToken = $rejectedCustomer->createToken('test')->plainTextToken;

        $this->requestWithToken($rejectedToken, '/_test-auth/customer')->assertForbidden();
    }

    #[DataProvider('wholesaleAccessCases')]
    public function test_wholesale_gate_checks_customer_type_and_approval_status(
        CustomerType $type,
        CustomerStatus $status,
        bool $isAllowed,
    ): void {
        $customer = $this->createCustomer(type: $type, status: $status);
        $token = $customer->createToken('test')->plainTextToken;

        $response = $this->requestWithToken($token, '/_test-auth/wholesale');

        if ($isAllowed) {
            $response->assertOk();

            return;
        }

        $response->assertForbidden();
    }

    public static function wholesaleAccessCases(): array
    {
        return [
            'approved wholesale' => [CustomerType::Wholesale, CustomerStatus::Approved, true],
            'approved distributor' => [CustomerType::Distributor, CustomerStatus::Approved, true],
            'pending wholesale' => [CustomerType::Wholesale, CustomerStatus::Pending, false],
            'rejected distributor' => [CustomerType::Distributor, CustomerStatus::Rejected, false],
            'retail customer' => [CustomerType::Retail, CustomerStatus::Approved, false],
        ];
    }

    public function test_returns_403_for_inactive_supplier_users(): void
    {
        $supplier = SupplierUser::create([
            'supplier_id' => 1,
            'name' => 'Active supplier user',
            'email' => 'active-supplier@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $token = $supplier->createToken('test')->plainTextToken;

        $this->requestWithToken($token, '/_test-auth/supplier')
            ->assertOk()
            ->assertJsonPath('model', SupplierUser::class);

        $unassignedSupplier = SupplierUser::create([
            'name' => 'Unassigned supplier user',
            'email' => 'unassigned-supplier@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $unassignedToken = $unassignedSupplier->createToken('test')->plainTextToken;

        $this->requestWithToken($unassignedToken, '/_test-auth/supplier')->assertForbidden();

        $inactiveSupplier = SupplierUser::create([
            'supplier_id' => 1,
            'name' => 'Inactive supplier user',
            'email' => 'inactive-supplier@example.test',
            'password' => 'password',
            'is_active' => false,
        ]);
        $inactiveToken = $inactiveSupplier->createToken('test')->plainTextToken;

        $this->requestWithToken($inactiveToken, '/_test-auth/supplier')->assertForbidden();
    }

    public function test_authentication_middlewares_return_401_when_no_token_is_present(): void
    {
        foreach (['staff', 'customer', 'supplier', 'wholesale'] as $route) {
            Auth::forgetGuards();

            $this->getJson("/_test-auth/{$route}")->assertUnauthorized();
        }
    }

    public function test_returns_403_when_customer_token_is_used_as_staff(): void
    {
        $customer = $this->createCustomer();
        $token = $customer->createToken('test')->plainTextToken;

        $this->requestWithToken($token, '/_test-auth/staff')->assertForbidden();
    }

    public function test_role_middleware_allows_staff_with_the_required_role_and_forbids_others(): void
    {
        $role = Role::create(['name' => 'manager', 'guard_name' => 'staff']);
        $authorizedStaff = User::factory()->create();
        $authorizedStaff->assignRole($role);
        $authorizedToken = $authorizedStaff->createToken('test')->plainTextToken;

        $this->requestWithToken($authorizedToken, '/_test-auth/staff-role')->assertOk();

        $otherStaff = User::factory()->create();
        $otherToken = $otherStaff->createToken('test')->plainTextToken;

        $this->requestWithToken($otherToken, '/_test-auth/staff-role')->assertForbidden();
    }

    public function test_permission_middleware_allows_staff_with_the_required_permission(): void
    {
        $permission = Permission::create(['name' => 'inventory.read', 'guard_name' => 'staff']);
        $staff = User::factory()->create();
        $staff->givePermissionTo($permission);
        $token = $staff->createToken('test')->plainTextToken;

        $this->requestWithToken($token, '/_test-auth/staff-permission')->assertOk();
    }

    private function createCustomer(
        CustomerType $type = CustomerType::Retail,
        CustomerStatus $status = CustomerStatus::Pending,
    ): Customer {
        return Customer::create([
            'name' => 'Test customer',
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->unique()->numerify('010########'),
            'password' => 'password',
            'type' => $type,
            'status' => $status,
        ]);
    }

    private function requestWithToken(string $token, string $uri): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->getJson($uri);
    }
}
