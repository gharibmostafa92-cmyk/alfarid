<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Catalog\ProductStatus;
use App\Enums\Inventory\StockBucket;
use App\Enums\Inventory\StockMovementType;
use App\Enums\Finance\TreasuryType;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Shared fixture for task.txt POS scenario tests (sections 2 + 3).
 *
 * Each concrete file covers a scenario group and inherits the same
 * branch/cashier/drawer/variant setup to keep tests comparable.
 */
abstract class PosScenarioFixture extends TestCase
{
    protected Branch $branch;

    protected Branch $branchB;

    protected User $cashier;

    protected User $cashierB;

    protected Treasury $drawer;

    protected Treasury $safe;

    protected Treasury $drawerB;

    protected ProductVariant $variant;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingSeeder::class);

        $this->branch = Branch::create([
            'name' => 'Cairo Branch', 'code' => 'BR-CAIRO-'.Str::random(4),
            'type' => 'branch', 'is_active' => true,
        ]);
        $this->branchB = Branch::create([
            'name' => 'Alex Branch', 'code' => 'BR-ALEX-'.Str::random(4),
            'type' => 'branch', 'is_active' => true,
        ]);

        $this->cashier = $this->makeCashier('cashier@example.com', $this->branch->id, ['pos.access']);
        $this->cashierB = $this->makeCashier('cashier-b@example.com', $this->branchB->id, ['pos.access']);

        $this->drawer = Treasury::create([
            'name' => 'Ahmed Drawer', 'code' => 'DRW-01-'.Str::random(4),
            'branch_id' => $this->branch->id, 'type' => TreasuryType::PosDrawer,
            'current_balance' => 0, 'is_active' => true,
        ]);
        $this->safe = Treasury::create([
            'name' => 'Cairo Main Safe', 'code' => 'SAFE-CAIRO-'.Str::random(4),
            'branch_id' => $this->branch->id, 'type' => TreasuryType::MainSafe,
            'current_balance' => 1000, 'is_active' => true,
        ]);
        $this->drawerB = Treasury::create([
            'name' => 'Alex Drawer', 'code' => 'DRW-ALEX-'.Str::random(4),
            'branch_id' => $this->branchB->id, 'type' => TreasuryType::PosDrawer,
            'current_balance' => 0, 'is_active' => true,
        ]);

        $this->variant = $this->makeVariant(100.00);
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $this->variant->id,
            'branch_id' => $this->branch->id,
            'type' => StockMovementType::AdjustmentIn,
            'affected_bucket' => StockBucket::OnHand,
            'quantity' => 50,
            'created_by' => $this->cashier->id,
        ]);

        $this->token = $this->openShift($this->cashier, $this->drawer, 0.0);
    }

    protected function makeCashier(string $email, ?int $branchId, array $perms = []): User
    {
        $user = User::create([
            'name' => 'Cashier '.Str::random(4),
            'email' => $email,
            'phone' => '+2010'.random_int(10000000, 19999999),
            'password' => Hash::make('secret123'),
            'branch_id' => $branchId,
            'is_active' => true,
        ]);
        $user->assignRole(Role::findOrCreate('cashier', 'staff'));
        foreach ($perms as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'staff'));
        }

        return $user;
    }

    protected function makeVariant(float $retail = 100.00, ?float $wholesale = null): ProductVariant
    {
        $brand = Brand::create([
            'name_ar' => 'براند', 'name_en' => 'Brand '.Str::random(4),
            'slug' => 'brand-'.Str::random(6), 'is_active' => true,
        ]);
        $cat = Category::create([
            'name_ar' => 'شنط', 'name_en' => 'Bags '.Str::random(4),
            'slug' => 'bags-'.Str::random(6), 'is_active' => true,
        ]);
        $prod = Product::create([
            'code' => 'PRD-'.Str::random(6),
            'name_ar' => 'شنطة جلد', 'name_en' => 'Leather Bag',
            'slug' => 'leather-bag-'.Str::random(6),
            'status' => ProductStatus::Published,
            'brand_id' => $brand->id, 'category_id' => $cat->id,
            'is_active' => true, 'published_at' => now(),
        ]);

        return ProductVariant::create([
            'product_id' => $prod->id,
            'sku' => 'BAG-'.Str::random(6),
            'barcode' => '622'.random_int(1000000000, 1999999999),
            'color_name_ar' => 'أحمر',
            'retail_price' => $retail,
            'wholesale_price' => $wholesale,
            'cost_price' => 60.00,
            'is_active' => true,
        ]);
    }

    protected function stockIn(int $branchId, ProductVariant $variant, float $qty, ?int $by = null): void
    {
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id,
            'branch_id' => $branchId,
            'type' => StockMovementType::AdjustmentIn,
            'affected_bucket' => StockBucket::OnHand,
            'quantity' => $qty,
            'created_by' => $by ?? $this->cashier->id,
        ]);
    }

    /** @return string fresh bearer token with a cleared auth guard */
    protected function tokenFor(User $user, array $perms = []): string
    {
        foreach ($perms as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'staff'));
        }
        // Simulate a fresh request scope: clears guard user AND scoped
        // singletons (BranchAccess user cache, SettingService request cache).
        Auth::forgetGuards();
        app()->forgetScopedInstances();

        return $user->createToken('pos', ['pos'])->plainTextToken;
    }

    protected function openShift(User $user, Treasury $drawer, float $openingCash): string
    {
        $token = $this->tokenFor($user);
        $this->withToken($token)->postJson('/api/v1/pos/shifts/open', [
            'treasury_id' => $drawer->id,
            'opening_cash' => $openingCash,
        ])->assertStatus(201);

        return $token;
    }

    /** @return array<string,string> */
    protected function idem(?string $key = null): array
    {
        return ['Idempotency-Key' => $key ?? 'k-'.Str::random(16)];
    }

    /** @return array<string,mixed> */
    protected function salePayload(float $cash, float $card, int $qty = 1, float $discount = 0.0, ?int $variantId = null): array
    {
        return [
            'items' => [
                ['product_variant_id' => $variantId ?? $this->variant->id, 'qty' => $qty, 'discount_amount' => $discount],
            ],
            'payment_method' => 'mixed',
            'cash_amount' => $cash,
            'card_amount' => $card,
        ];
    }
}
