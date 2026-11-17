<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\Catalog\ProductStatus;
use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\Orders\OrderStatus;
use App\Enums\Orders\SalesChannel;
use App\Enums\Wholesale\WholesalePaymentMethod;
use App\Enums\Wholesale\WholesalePaymentNoticeStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WalletAdjustment;
use App\Models\WholesalePaymentNotice;
use App\Services\Ai\Wholesale\Tools\PreviewNoticeAllocationTool;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\FinancialPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class Task2Slice1SecurityTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(AiAgentPermissionSeeder::class);
        $this->seed(FinancialPermissionSeeder::class);
    }

    private function staffToken(User $user): string
    {
        Auth::forgetGuards();

        return $user->createToken('test')->plainTextToken;
    }

    private function customerToken(Customer $customer): string
    {
        Auth::forgetGuards();

        return $customer->createToken('wholesale')->plainTextToken;
    }

    public function test_preview_notice_allocation_tool_enforces_branch_isolation(): void
    {
        $branchA = Branch::factory()->create(['is_active' => true]);
        $branchB = Branch::factory()->create(['is_active' => true]);

        $branchAUser = User::factory()->create(['branch_id' => $branchA->id]);
        $branchAUser->givePermissionTo(Permission::findOrCreate('payment_notices.view', 'staff'));

        $customer = Customer::factory()->create([
            'type' => CustomerType::Wholesale,
            'status' => CustomerStatus::Approved,
        ]);

        $orderB = Order::factory()->create([
            'customer_id' => $customer->id,
            'channel' => SalesChannel::Wholesale->value,
            'payment_method' => 'credit',
            'status' => OrderStatus::Confirmed,
            'allocated_branch_id' => $branchB->id,
            'grand_total' => 500,
        ]);

        $noticeB = WholesalePaymentNotice::create([
            'customer_id' => $customer->id,
            'order_id' => $orderB->id,
            'notice_number' => 'NOT-'.Str::upper(Str::random(6)),
            'amount' => 500,
            'payment_method' => 'bank_transfer',
            'transfer_date' => now()->toDateString(),
            'bank_name' => 'CIB',
            'reference_number' => 'REF-'.Str::upper(Str::random(8)),
            'receipt_file_path' => 'receipts/test-b.pdf',
            'status' => WholesalePaymentNoticeStatus::PendingReview,
        ]);

        $freeNotice = WholesalePaymentNotice::create([
            'customer_id' => $customer->id,
            'order_id' => null,
            'notice_number' => 'NOT-'.Str::upper(Str::random(6)),
            'amount' => 200,
            'payment_method' => 'bank_transfer',
            'transfer_date' => now()->toDateString(),
            'bank_name' => 'CIB',
            'reference_number' => 'REF-'.Str::upper(Str::random(8)),
            'receipt_file_path' => 'receipts/test-b.pdf',
            'status' => WholesalePaymentNoticeStatus::PendingReview,
        ]);

        $tool = app(PreviewNoticeAllocationTool::class);

        $outcomeOtherBranch = $tool->handle($branchAUser, ['notice_id' => $noticeB->id]);
        $this->assertSame('denied', $outcomeOtherBranch->type, 'Branch A staff must be denied previewing a Branch B notice.');

        $outcomeFree = $tool->handle($branchAUser, ['notice_id' => $freeNotice->id]);
        $this->assertSame('denied', $outcomeFree->type, 'Branch-restricted staff must be denied previewing a central free notice.');
    }

    public function test_customer_wallet_controller_enforces_central_access_and_requires_idempotency_key(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $branchStaff = User::factory()->create(['branch_id' => $branch->id]);
        $branchStaff->givePermissionTo([
            Permission::findOrCreate('customers.wallet.view', 'staff'),
            Permission::findOrCreate('customers.wallet.adjust', 'staff'),
        ]);

        $centralStaff = User::factory()->create(['branch_id' => null]);
        $centralStaff->givePermissionTo([
            Permission::findOrCreate('customers.wallet.view', 'staff'),
            Permission::findOrCreate('customers.wallet.adjust', 'staff'),
            Permission::findOrCreate('inventory.all_branches', 'staff'),
        ]);

        $customer = Customer::factory()->create();
        $adjustment = WalletAdjustment::create([
            'customer_id' => $customer->id,
            'kind' => 'adjustment',
            'direction' => 'credit',
            'amount' => 50,
            'reason' => 'seed',
            'source' => 'admin',
            'created_by' => $centralStaff->id,
        ]);

        $branchToken = $this->staffToken($branchStaff);

        $this->withToken($branchToken)->getJson("/api/v1/admin/customers/{$customer->id}/wallet")->assertForbidden();
        $this->withToken($branchToken)->getJson("/api/v1/admin/customers/{$customer->id}/wallet/summary")->assertForbidden();
        $this->withToken($branchToken)->getJson("/api/v1/admin/customers/{$customer->id}/wallet/adjustments")->assertForbidden();
        $this->withToken($branchToken)
            ->postJson("/api/v1/admin/customers/{$customer->id}/wallet/adjustments", [
                'kind' => 'adjustment',
                'direction' => 'credit',
                'amount' => 25,
                'reason' => 'unauthorized branch',
            ], ['Idempotency-Key' => 'branch-key-1'])->assertForbidden();
        $this->withToken($branchToken)
            ->deleteJson("/api/v1/admin/wallet-adjustments/{$adjustment->id}", ['reason' => 'test'], ['Idempotency-Key' => 'branch-key-2'])
            ->assertForbidden();

        // Central staff must still provide a non-empty Idempotency-Key on storeAdjustment.
        $centralToken = $this->staffToken($centralStaff);
        $this->withToken($centralToken)
            ->postJson("/api/v1/admin/customers/{$customer->id}/wallet/adjustments", [
                'kind' => 'adjustment',
                'direction' => 'credit',
                'amount' => 25,
                'reason' => 'missing key',
            ])->assertStatus(422);
    }

    public function test_wholesale_catalog_excludes_products_under_inactive_ancestors_at_any_depth(): void
    {
        $customer = Customer::factory()->create([
            'type' => CustomerType::Wholesale,
            'status' => CustomerStatus::Approved,
        ]);

        // 4-level tree: root (inactive) -> lvl2 (active) -> lvl3 (active) -> leaf (active)
        $inactiveRoot = Category::factory()->create(['is_active' => false, 'parent_id' => null]);
        $lvl2 = Category::factory()->create(['is_active' => true, 'parent_id' => $inactiveRoot->id]);
        $lvl3 = Category::factory()->create(['is_active' => true, 'parent_id' => $lvl2->id]);
        $leafUnderInactiveRoot = Category::factory()->create(['is_active' => true, 'parent_id' => $lvl3->id]);

        // Soft-deleted parent -> active child
        $softDeletedParent = Category::factory()->create(['is_active' => true, 'parent_id' => null]);
        $leafUnderDeletedParent = Category::factory()->create(['is_active' => true, 'parent_id' => $softDeletedParent->id]);
        $softDeletedParent->delete();

        // Active tree
        $activeRoot = Category::factory()->create(['is_active' => true, 'parent_id' => null]);
        $activeLeaf = Category::factory()->create(['is_active' => true, 'parent_id' => $activeRoot->id]);

        $hidden1 = Product::factory()->create([
            'category_id' => $leafUnderInactiveRoot->id,
            'status' => ProductStatus::Published,
            'sales_channel' => SalesChannel::Wholesale,
        ]);
        ProductVariant::factory()->create(['product_id' => $hidden1->id, 'is_active' => true]);

        $hidden2 = Product::factory()->create([
            'category_id' => $leafUnderDeletedParent->id,
            'status' => ProductStatus::Published,
            'sales_channel' => SalesChannel::Wholesale,
        ]);
        ProductVariant::factory()->create(['product_id' => $hidden2->id, 'is_active' => true]);

        $visible = Product::factory()->create([
            'category_id' => $activeLeaf->id,
            'status' => ProductStatus::Published,
            'sales_channel' => SalesChannel::Both,
        ]);
        ProductVariant::factory()->create(['product_id' => $visible->id, 'is_active' => true]);

        $res = $this->withToken($this->customerToken($customer))
            ->getJson('/api/v1/wholesale/products')
            ->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($visible->id, $ids);
        $this->assertNotContains($hidden1->id, $ids, 'Product under inactive great-grandparent must be excluded.');
        $this->assertNotContains($hidden2->id, $ids, 'Product under soft-deleted parent must be excluded.');
    }
}
