<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Inventory;

use App\Models\Branch;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\User;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Shared helpers for the task.txt inventory suites.
 */
abstract class TaskTxtInventoryCase extends TestCase
{
    use LazilyRefreshDatabase;

    protected const ALL_PERMISSIONS = [
        'inventory.view', 'inventory.adjust', 'inventory.all_branches',
        'transfers.view', 'transfers.create', 'transfers.update', 'transfers.delete',
        'transfers.approve', 'transfers.ship', 'transfers.receive',
        'stocktakes.view', 'stocktakes.create', 'stocktakes.update', 'stocktakes.delete', 'stocktakes.approve',
        'products.view_cost', 'inventory.view_cost',
        'pos.access',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
    }

    protected function staffWithPermissions(array $permissions, ?int $branchId = null): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'staff');
        }
        $staff = User::factory()->create(['branch_id' => $branchId]);
        if ($permissions !== []) {
            $staff->givePermissionTo($permissions);
        }

        return $staff;
    }

    protected function tokenFor(User $user, string $name = 'test'): string
    {
        return $user->createToken($name)->plainTextToken;
    }

    protected function requestWithToken(string $token, string $method, string $uri, array $payload = [], array $headers = []): TestResponse
    {
        // Guards memoize their user per app instance; forget them so each
        // request authenticates with its own Bearer token.
        Auth::forgetGuards();

        if (! isset($headers['Idempotency-Key'])) {
            $headers['Idempotency-Key'] = (string) Str::uuid();
        }

        return $this->withToken($token)->json($method, $uri, $payload, $headers);
    }

    protected function posWithToken(string $token): static
    {
        Auth::forgetGuards();

        return $this->withToken($token);
    }

    protected function posCashierWithShift(Branch $branch, array $permissions): array
    {
        foreach (array_merge(['pos.access'], $permissions) as $permission) {
            Permission::findOrCreate($permission, 'staff');
        }
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $cashier->givePermissionTo(array_merge(['pos.access'], $permissions));

        $drawer = \App\Models\Treasury::create([
            'name' => 'Drawer '.uniqid(), 'code' => 'DRW-'.uniqid(),
            'branch_id' => $branch->id, 'type' => \App\Enums\TreasuryType::PosDrawer,
            'current_balance' => 0, 'is_active' => true,
        ]);

        $token = $cashier->createToken('pos', ['pos'])->plainTextToken;
        $this->posWithToken($token)->postJson('/api/v1/pos/shifts/open', [
            'treasury_id' => $drawer->id, 'opening_cash' => 0,
        ])->assertCreated();

        return [$cashier, $token];
    }

    protected function seedStock(string $token, Branch $branch, ProductVariant $variant, float $qty): void
    {
        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => 'increase', 'quantity' => $qty]],
        ])->assertCreated();
    }

    protected function level(int $variantId, int $branchId): StockLevel
    {
        return StockLevel::query()
            ->where('product_variant_id', $variantId)
            ->where('branch_id', $branchId)
            ->whereNull('location_id')
            ->firstOrFail();
    }
}
