<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Pricing;

use App\Enums\Catalog\PriceListType;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Enums\Orders\SalesChannel;
use App\Jobs\RefreshCatalogRetailPrices;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Pricing\PriceListService;
use App\Services\Pricing\PricingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class Task2RoadmapSlice1Test extends TestCase
{
    use LazilyRefreshDatabase;

    private function staff(array $perms, ?int $branchId = null): User
    {
        foreach ($perms as $p) {
            Permission::findOrCreate($p, 'staff');
        }
        $u = User::factory()->create(['branch_id' => $branchId]);
        $u->givePermissionTo($perms);

        return $u;
    }

    private function token(User $u): string
    {
        Auth::forgetGuards();

        return $u->createToken('test')->plainTextToken;
    }

    // CRITICAL-01: Cross-branch / cross-list IDOR on PriceListItemController
    public function test_price_list_item_endpoints_enforce_parent_scope_bindings(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $staffA = $this->staff([
            'price_lists.view', 'price_lists.update', 'price_lists.delete',
        ], $branchA->id);

        $listA = PriceList::query()->create([
            'name' => 'Branch A List',
            'type' => PriceListType::Retail,
            'branch_id' => $branchA->id,
        ]);
        $listB = PriceList::query()->create([
            'name' => 'Branch B Secret List',
            'type' => PriceListType::Retail,
            'branch_id' => $branchB->id,
        ]);
        $variant = ProductVariant::factory()->create(['retail_price' => 100]);
        $foreignItem = PriceListItem::query()->create([
            'price_list_id' => $listB->id,
            'product_variant_id' => $variant->id,
            'min_qty' => 1,
            'price' => 55,
        ]);

        $token = $this->token($staffA);

        $this->withToken($token)
            ->getJson("/api/v1/admin/price-lists/{$listA->id}/items/{$foreignItem->id}")
            ->assertNotFound();

        $this->withToken($token)
            ->patchJson("/api/v1/admin/price-lists/{$listA->id}/items/{$foreignItem->id}", ['price' => 10])
            ->assertNotFound();

        $this->withToken($token)
            ->deleteJson("/api/v1/admin/price-lists/{$listA->id}/items/{$foreignItem->id}")
            ->assertNotFound();
    }

    // CRITICAL-04: Wholesale catalog excludes products in inactive category/parent/grandparent
    public function test_wholesale_catalog_excludes_products_under_inactive_category_hierarchy(): void
    {
        $grandparent = Category::factory()->create(['is_active' => false]);
        $parent = Category::factory()->create(['parent_id' => $grandparent->id, 'is_active' => false]);
        $leafWithInactiveAncestors = Category::factory()->create(['parent_id' => $parent->id, 'is_active' => false]);
        $activeParent = Category::factory()->create(['is_active' => true]);
        $activeLeaf = Category::factory()->create(['parent_id' => $activeParent->id, 'is_active' => true]);

        $hiddenProduct = Product::factory()->create([
            'category_id' => $leafWithInactiveAncestors->id,
            'status' => ProductStatus::Published,
            'sales_channel' => SalesChannel::Wholesale,
        ]);
        ProductVariant::factory()->create(['product_id' => $hiddenProduct->id, 'is_active' => true]);

        $visibleProduct = Product::factory()->create([
            'category_id' => $activeLeaf->id,
            'status' => ProductStatus::Published,
            'sales_channel' => SalesChannel::Wholesale,
        ]);
        ProductVariant::factory()->create(['product_id' => $visibleProduct->id, 'is_active' => true]);

        $customer = Customer::factory()->create([
            'type' => CustomerType::Wholesale,
            'status' => CustomerStatus::Approved,
        ]);
        Auth::forgetGuards();
        $token = $customer->createToken('wholesale')->plainTextToken;

        $res = $this->withToken($token)->getJson('/api/v1/wholesale/products')->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertContains($visibleProduct->id, $ids);
        $this->assertNotContains($hiddenProduct->id, $ids);
    }

    // HIGH-04: PriceListService::delete dispatches RefreshCatalogRetailPrices despite ON DELETE CASCADE
    public function test_deleting_price_list_dispatches_catalog_retail_price_refresh_for_its_products(): void
    {
        Queue::fake();

        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $list = PriceList::query()->create([
            'name' => 'Retail Promo',
            'type' => PriceListType::Retail,
            'is_active' => true,
        ]);
        PriceListItem::query()->create([
            'price_list_id' => $list->id,
            'product_variant_id' => $variant->id,
            'min_qty' => 1,
            'price' => 45,
        ]);

        // Clear any jobs queued during setup
        Queue::fake();

        app(PriceListService::class)->delete($list);

        Queue::assertPushed(RefreshCatalogRetailPrices::class, function (RefreshCatalogRetailPrices $job) use ($product): bool {
            return in_array($product->id, $job->productId ?? [], true);
        });
    }

    // HIGH-04: PricingService::catalogContext reflects updated customer group
    public function test_pricing_service_catalog_context_invalidates_when_customer_group_changes(): void
    {
        $groupA = CustomerGroup::query()->create(['name' => 'Group A', 'code' => 'GRP_A', 'is_active' => true, 'discount_percentage' => 5]);
        $groupB = CustomerGroup::query()->create(['name' => 'Group B', 'code' => 'GRP_B', 'is_active' => true, 'discount_percentage' => 10]);
        $customer = Customer::factory()->create([
            'type' => CustomerType::Wholesale,
            'status' => CustomerStatus::Approved,
            'customer_group_id' => $groupA->id,
        ]);

        $pricing = app(PricingService::class);
        $first = $pricing->catalogContext($customer);
        $this->assertStringContainsString("group:{$groupA->id}", $first['key']);

        $customer->update(['customer_group_id' => $groupB->id]);
        $customer->unsetRelation('customerGroup');

        $second = $pricing->catalogContext($customer);
        $this->assertStringContainsString("group:{$groupB->id}", $second['key']);
    }

    // HIGH-08: CustomerGroupController::index & show require central access
    public function test_branch_restricted_staff_cannot_view_customer_groups(): void
    {
        $branch = Branch::factory()->create();
        $staff = $this->staff(['customer_groups.view'], $branch->id);
        $group = CustomerGroup::query()->create(['name' => 'Group C', 'code' => 'GRP_C', 'is_active' => true, 'discount_percentage' => 0]);
        $token = $this->token($staff);

        $this->withToken($token)
            ->getJson('/api/v1/admin/customer-groups')
            ->assertForbidden();

        $this->withToken($token)
            ->getJson("/api/v1/admin/customer-groups/{$group->id}")
            ->assertForbidden();
    }

    // MEDIUM-01: PricingService::withStorePrice loads customerGroup without lazy loading violation
    public function test_with_store_price_does_not_trigger_lazy_loading_violation(): void
    {
        $group = CustomerGroup::query()->create(['name' => 'Group D', 'code' => 'GRP_D', 'is_active' => true, 'discount_percentage' => 10]);
        $customer = Customer::factory()->create([
            'type' => CustomerType::Wholesale,
            'status' => CustomerStatus::Approved,
            'customer_group_id' => $group->id,
        ]);
        // Fresh customer without customerGroup loaded
        $freshCustomer = Customer::query()->findOrFail($customer->id);

        $wasPreventing = Model::preventsLazyLoading();
        Model::preventLazyLoading(true);
        try {
            $query = app(PricingService::class)->withStorePrice(ProductVariant::query(), $freshCustomer);
            $this->assertNotNull($query);
        } finally {
            Model::preventLazyLoading($wasPreventing);
        }
    }
}
