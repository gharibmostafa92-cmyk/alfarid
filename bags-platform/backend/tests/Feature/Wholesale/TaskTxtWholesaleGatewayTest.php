<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Models\Customer;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class TaskTxtWholesaleGatewayTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);
    }

    private function customerToken(string $type, string $status): string
    {
        $customer = Customer::factory()->create(['type' => $type, 'status' => $status]);

        return $customer->createToken('portal')->plainTextToken;
    }

    public function test_unauthenticated_dashboard_returns_401(): void
    {
        $this->getJson('/api/v1/wholesale/dashboard')->assertUnauthorized();
    }

    public function test_staff_token_is_forbidden_on_wholesale_gateway(): void
    {
        $staff = User::factory()->create();
        Auth::forgetGuards();

        $this->withToken($staff->createToken('staff')->plainTextToken)
            ->getJson('/api/v1/wholesale/dashboard')->assertForbidden();
    }

    #[TestWith(['retail', 'approved'])]
    #[TestWith(['wholesale', 'pending'])]
    #[TestWith(['wholesale', 'rejected'])]
    #[TestWith(['wholesale', 'suspended'])]
    public function test_unapproved_tiers_are_forbidden(string $type, string $status): void
    {
        Auth::forgetGuards();

        $this->withToken($this->customerToken($type, $status))
            ->getJson('/api/v1/wholesale/dashboard')->assertForbidden();
    }

    #[TestWith(['wholesale'])]
    #[TestWith(['distributor'])]
    public function test_approved_commercial_tiers_reach_dashboard(string $type): void
    {
        Auth::forgetGuards();

        $this->withToken($this->customerToken($type, 'approved'))
            ->getJson('/api/v1/wholesale/dashboard')->assertOk();
    }
}
