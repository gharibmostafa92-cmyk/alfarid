<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Pricing;

use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Enums\Catalog\PriceListType;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Orders\SalesChannel;
use App\Jobs\RefreshCatalogRetailPrices;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Catalog\RetailPriceSnapshot;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * task.txt items 13, 14, 15, 17, 19 plus section-3 price-list edges.
 * Item 16 (CSV matrix) lives in P07CsvImportTest.
 */
class P07PriceListsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ALL = [
        'customer_groups.view', 'customer_groups.create', 'customer_groups.update', 'customer_groups.delete',
        'price_lists.view', 'price_lists.create', 'price_lists.update', 'price_lists.delete',
        'price_lists.import', 'price_lists.export',
        'inventory.all_branches',
    ];

    private function branchPerms(): array
    {
        return array_values(array_diff(self::ALL, ['inventory.all_branches']));
    }

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

    private function priceList(PriceListType $type, ?int $branchId = null, int $priority = 0): PriceList
    {
        return PriceList::query()->create([
            'name' => 'List '.uniqid(), 'type' => $type, 'branch_id' => $branchId, 'priority' => $priority,
        ]);
    }

    private function variant(float $retail = 100, float $wholesale = 80): ProductVariant
    {
        return ProductVariant::factory()->create(['retail_price' => $retail, 'wholesale_price' => $wholesale]);
    }

    // ── item 13: branch isolation across list + item + import/export endpoints ──
    public function test_branch_restricted_staff_cannot_touch_other_branch_lists(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $a = $this->staff($this->branchPerms(), $branchA->id);
        $token = $this->token($a);

        $foreign = $this->priceList(PriceListType::Retail, $branchB->id);
        $variant = $this->variant();

        // Read/update/delete/import/export on the foreign list → 404 (not 403).
        $this->withToken($token)->getJson("/api/v1/admin/price-lists/{$foreign->id}")->assertNotFound();
        $this->withToken($token)->putJson("/api/v1/admin/price-lists/{$foreign->id}", ['name' => 'x'])->assertNotFound();
        $this->withToken($token)->deleteJson("/api/v1/admin/price-lists/{$foreign->id}")->assertNotFound();
        $this->withToken($token)->get("/api/v1/admin/price-lists/{$foreign->id}/export")->assertNotFound();
        $this->withToken($token)->post("/api/v1/admin/price-lists/{$foreign->id}/import",
            ['file' => UploadedFile::fake()->createWithContent('p.csv', "sku,price\n{$variant->sku},10\n")])->assertNotFound();

        // Item endpoints on the foreign list → 404 as well.
        $this->withToken($token)->getJson("/api/v1/admin/price-lists/{$foreign->id}/items")->assertNotFound();
        $this->withToken($token)->postJson("/api/v1/admin/price-lists/{$foreign->id}/items",
            ['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 10])->assertNotFound();

        // Creating for another branch → 403; creating bare binds to the own branch.
        $this->withToken($token)->postJson('/api/v1/admin/price-lists',
            ['name' => 'F', 'type' => 'retail', 'branch_id' => $branchB->id])->assertForbidden();
        $own = $this->withToken($token)->postJson('/api/v1/admin/price-lists', ['name' => 'O', 'type' => 'retail'])
            ->assertCreated()->json('data');
        $this->assertSame($branchA->id, $own['branch']['id']);

        // Moving the own list to null/another branch → 403.
        $this->withToken($token)->putJson("/api/v1/admin/price-lists/{$own['id']}", ['branch_id' => null])->assertForbidden();
        $this->withToken($token)->putJson("/api/v1/admin/price-lists/{$own['id']}", ['branch_id' => $branchB->id])->assertForbidden();

        // Own list + items work fine.
        $this->withToken($token)->getJson("/api/v1/admin/price-lists/{$own['id']}")->assertOk();
        $this->withToken($token)->postJson("/api/v1/admin/price-lists/{$own['id']}/items",
            ['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 10])->assertCreated();
    }

    // ── item 14: permission matrix for pricing endpoints ──
    public function test_pricing_permission_matrix(): void
    {
        $list = $this->priceList(PriceListType::Retail);
        $variant = $this->variant();

        // No permissions at all → 403 everywhere.
        $bare = $this->staff([]);
        $bareToken = $this->token($bare);
        $this->withToken($bareToken)->getJson('/api/v1/admin/price-lists')->assertForbidden();
        $this->withToken($bareToken)->postJson('/api/v1/admin/price-lists', ['name' => 'x', 'type' => 'retail'])->assertForbidden();
        $this->withToken($bareToken)->getJson("/api/v1/admin/price-lists/{$list->id}")->assertForbidden();
        $this->withToken($bareToken)->getJson('/api/v1/admin/customer-groups')->assertForbidden();
        $this->withToken($bareToken)->getJson('/api/v1/admin/coupons')->assertForbidden();
        $this->withToken($bareToken)->getJson('/api/v1/admin/promotions')->assertForbidden();

        // View-only staff: reads pass, every write (incl. import/export) is 403.
        $viewer = $this->staff(['price_lists.view', 'inventory.all_branches']);
        $vt = $this->token($viewer);
        $this->withToken($vt)->getJson('/api/v1/admin/price-lists')->assertOk();
        $this->withToken($vt)->postJson('/api/v1/admin/price-lists', ['name' => 'x', 'type' => 'retail'])->assertForbidden();
        $this->withToken($vt)->putJson("/api/v1/admin/price-lists/{$list->id}", ['name' => 'x'])->assertForbidden();
        $this->withToken($vt)->deleteJson("/api/v1/admin/price-lists/{$list->id}")->assertForbidden();
        $this->withToken($vt)->post("/api/v1/admin/price-lists/{$list->id}/import",
            ['file' => UploadedFile::fake()->createWithContent('p.csv', "sku,price\n{$variant->sku},10\n")])->assertForbidden();
        $this->withToken($vt)->get("/api/v1/admin/price-lists/{$list->id}/export")->assertForbidden();

        // Update permission is not a substitute for import/export.
        $updater = $this->staff(['price_lists.view', 'price_lists.update', 'inventory.all_branches']);
        $ut = $this->token($updater);
        $this->withToken($ut)->post("/api/v1/admin/price-lists/{$list->id}/import",
            ['file' => UploadedFile::fake()->createWithContent('p.csv', "sku,price\n{$variant->sku},10\n")])->assertForbidden();
        $this->withToken($ut)->get("/api/v1/admin/price-lists/{$list->id}/export")->assertForbidden();
        $this->withToken($ut)->putJson("/api/v1/admin/price-lists/{$list->id}", ['name' => 'renamed'])->assertOk();

        // Full permissions: everything passes.
        $admin = $this->staff(self::ALL);
        $at = $this->token($admin);
        $created = $this->withToken($at)->postJson('/api/v1/admin/price-lists', ['name' => 'ok', 'type' => 'retail'])->assertCreated()->json('data');
        $this->withToken($at)->get("/api/v1/admin/price-lists/{$created['id']}/export")->assertOk();
        $this->withToken($at)->deleteJson("/api/v1/admin/price-lists/{$created['id']}")->assertOk();
    }

    // ── item 14: inactive super-admin and system users get no bypass ──
    public function test_privileged_bypass_requires_active_non_system_super_admin(): void
    {
        Role::findOrCreate('Super Admin', 'staff');
        $inactive = User::factory()->create(['is_active' => false]);
        $inactive->assignRole('Super Admin');
        $this->withToken($this->token($inactive))->getJson('/api/v1/admin/price-lists')->assertForbidden();

        $system = User::factory()->create(['is_active' => true, 'is_system' => true]);
        $system->assignRole('Super Admin');
        $this->withToken($this->token($system))->getJson('/api/v1/admin/price-lists')->assertForbidden();

        $active = User::factory()->create(['is_active' => true]);
        $active->assignRole('Super Admin');
        $this->withToken($this->token($active))->getJson('/api/v1/admin/price-lists')->assertOk();
    }

    // ── item 14: branch-restricted staff cannot manage customer groups ──
    public function test_branch_restricted_staff_cannot_manage_customer_groups(): void
    {
        $branch = Branch::factory()->create();
        $a = $this->staff($this->branchPerms(), $branch->id);
        $token = $this->token($a);

        $this->withToken($token)->postJson('/api/v1/admin/customer-groups', ['name' => 'G', 'code' => 'GX'])->assertForbidden();
        $central = $this->staff(array_merge(self::ALL, ['inventory.all_branches']));
        $this->withToken($this->token($central))->postJson('/api/v1/admin/customer-groups', ['name' => 'G', 'code' => 'GX'])->assertCreated();
    }

    private function publishedProductWithVariant(float $retail = 500): array
    {
        $product = Product::factory()->create([
            'category_id' => Category::factory()->create(['is_active' => true])->id,
            'code' => 'PUB'.uniqid(), 'status' => ProductStatus::Published, 'sales_channel' => SalesChannel::Both,
        ]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'retail_price' => $retail,
            'wholesale_price' => 400, 'cost_price' => 50, 'is_active' => true]);

        return [$product, $variant];
    }

    // ── item 15: CSV import refreshes the stored catalog minimum ──
    public function test_csv_import_dispatches_snapshot_refresh_for_affected_products(): void
    {
        [$product, $variant] = $this->publishedProductWithVariant();
        $admin = $this->staff(self::ALL);
        $token = $this->token($admin);
        $list = $this->priceList(PriceListType::Retail);

        Queue::fake();
        $csv = "sku,variant_id,product_name,min_qty,price,starts_at,ends_at\n{$variant->sku},{$variant->id},,1,300,,\n";
        $response = $this->withToken($token)->post("/api/v1/admin/price-lists/{$list->id}/import",
            ['file' => UploadedFile::fake()->createWithContent('prices.csv', $csv)]);
        $response->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.skipped', 0);

        Queue::assertPushed(RefreshCatalogRetailPrices::class, 1);
        Queue::assertPushed(RefreshCatalogRetailPrices::class, fn ($job) => $job->productId === [$product->id]);
    }

    // ── item 17: owner linkage is enforced on merged create/update state ──
    public function test_owner_linkage_enforced_without_500s(): void
    {
        $admin = $this->staff(self::ALL);
        $token = $this->token($admin);
        $customer = Customer::query()->create(['name' => 'c', 'email' => 'c'.uniqid().'@example.com',
            'phone' => '010'.random_int(10000000, 99999999), 'password' => Hash::make('password'),
            'type' => CustomerType::Retail, 'status' => CustomerStatus::Approved]);
        $group = CustomerGroup::query()->create(['name' => 'G', 'code' => 'G'.uniqid()]);

        // customer_specific without a customer → 422, not 500.
        $this->withToken($token)->postJson('/api/v1/admin/price-lists',
            ['name' => 'S', 'type' => 'customer_specific'])->assertStatus(422);
        // retail carrying a customer → 422 via prohibited_unless.
        $this->withToken($token)->postJson('/api/v1/admin/price-lists',
            ['name' => 'R', 'type' => 'retail', 'customer_id' => $customer->id])->assertStatus(422);
        // retail → customer_group without the group → 422 (merged owner check).
        $retail = $this->withToken($token)->postJson('/api/v1/admin/price-lists',
            ['name' => 'R2', 'type' => 'retail'])->assertCreated()->json('data');
        $this->withToken($token)->putJson("/api/v1/admin/price-lists/{$retail['id']}", ['type' => 'customer_group'])->assertStatus(422);

        // Name-only update on a group list keeps the group.
        $groupList = $this->withToken($token)->postJson('/api/v1/admin/price-lists',
            ['name' => 'GL', 'type' => 'customer_group', 'customer_group_id' => $group->id])->assertCreated()->json('data');
        $renamed = $this->withToken($token)->putJson("/api/v1/admin/price-lists/{$groupList['id']}", ['name' => 'GL2'])->assertOk()->json('data');
        $this->assertSame($group->id, $renamed['customer_group']['id']);

        // Switching a customer list back to retail clears the customer.
        $specific = $this->withToken($token)->postJson('/api/v1/admin/price-lists',
            ['name' => 'CS', 'type' => 'customer_specific', 'customer_id' => $customer->id])->assertCreated()->json('data');
        $cleared = $this->withToken($token)->putJson("/api/v1/admin/price-lists/{$specific['id']}", ['type' => 'retail'])->assertOk()->json('data');
        $this->assertTrue(! array_key_exists('customer', $cleared) || $cleared['customer'] === null);
    }

    // ── item 19: timed lists activate through the scheduled refresh ──
    public function test_future_list_activates_after_scheduled_refresh_passes_start(): void
    {
        [$product, $variant] = $this->publishedProductWithVariant(500);
        $snapshot = app(RetailPriceSnapshot::class);

        $future = $this->priceList(PriceListType::Retail);
        $future->update(['starts_at' => now()->addDay()]);
        $future->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 300]);

        $snapshot->refresh();
        // The future list is excluded: the snapshot falls back to the base price.
        $this->assertSame(500.0, (float) $product->fresh()->catalog_min_retail_price);

        $this->travel(1)->days();
        $this->artisan('store:refresh-retail-prices')->assertSuccessful();
        $this->assertSame(300.0, (float) $product->fresh()->catalog_min_retail_price);
    }

    // ── section 3: duplicate tiers are rejected on create and on concurrent insert ──
    public function test_duplicate_tier_rejected_sequentially(): void
    {
        $admin = $this->staff(self::ALL);
        $token = $this->token($admin);
        $variant = $this->variant();
        $list = $this->priceList(PriceListType::Retail);

        $url = "/api/v1/admin/price-lists/{$list->id}/items";
        $this->withToken($token)->postJson($url, ['product_variant_id' => $variant->id, 'min_qty' => 5, 'price' => 77])->assertCreated();
        $this->withToken($token)->postJson($url, ['product_variant_id' => $variant->id, 'min_qty' => 5, 'price' => 70])->assertStatus(422);
        $this->assertSame(1, $list->items()->count());
    }

    // ── section 3: item date validation merges new values with stored ones ──
    public function test_item_update_validates_dates_against_stored_values(): void
    {
        $admin = $this->staff(self::ALL);
        $token = $this->token($admin);
        $variant = $this->variant();
        $list = $this->priceList(PriceListType::Retail);
        $item = $list->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 90,
            'starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(10)]);

        // Setting only ends_at before the stored starts_at must fail.
        $this->withToken($token)->putJson("/api/v1/admin/price-lists/{$list->id}/items/{$item->id}",
            ['ends_at' => now()->addDays(3)->toDateTimeString()])->assertStatus(422);
        $this->withToken($token)->putJson("/api/v1/admin/price-lists/{$list->id}/items/{$item->id}",
            ['price' => 95])->assertOk();
    }

    // ── section 3: items for inactive or deleted variants are rejected ──
    public function test_item_for_inactive_or_deleted_variant_is_rejected(): void
    {
        $admin = $this->staff(self::ALL);
        $token = $this->token($admin);
        $list = $this->priceList(PriceListType::Retail);

        $inactive = ProductVariant::factory()->create(['is_active' => false]);
        $url = "/api/v1/admin/price-lists/{$list->id}/items";
        $this->withToken($token)->postJson($url, ['product_variant_id' => $inactive->id, 'min_qty' => 1, 'price' => 10])->assertStatus(422);

        $deleted = $this->variant();
        $deleted->delete();
        $this->withToken($token)->postJson($url, ['product_variant_id' => $deleted->id, 'min_qty' => 1, 'price' => 10])->assertStatus(422);
    }

    // ── section 3: export survives deleted variants, carries row count, neutralizes formulas ──
    public function test_export_with_deleted_variant_row_count_and_formula_cells(): void
    {
        Auth::forgetGuards();
        $admin = $this->staff(self::ALL);
        $token = $this->token($admin);
        $product = Product::factory()->create(['category_id' => Category::factory()->create(['is_active' => true])->id,
            'code' => 'FRM'.uniqid(), 'name_ar' => '=HYPERLINK("http://x","click")',
            'status' => ProductStatus::Published, 'sales_channel' => SalesChannel::Both]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'retail_price' => 100,
            'wholesale_price' => 80, 'cost_price' => 10, 'is_active' => true]);
        $list = $this->priceList(PriceListType::Retail);
        $list->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 90]);
        $gone = $this->variant();
        $list->items()->create(['product_variant_id' => $gone->id, 'min_qty' => 1, 'price' => 50]);
        $gone->delete();

        $export = $this->withToken($token)->get("/api/v1/admin/price-lists/{$list->id}/export");
        $export->assertOk();
        $this->assertSame('2', $export->headers->get('X-Row-Count'));
        $csv = $export->streamedContent();
        // Formula-leading product name is neutralized with a leading quote.
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    // ── section 3: restricted staff do not see global lists (owner decision) ──
    public function test_restricted_staff_cannot_see_global_lists(): void
    {
        $branchA = Branch::factory()->create();
        $a = $this->staff(['price_lists.view'], $branchA->id);
        $token = $this->token($a);
        $global = $this->priceList(PriceListType::Retail, null);

        // Current behavior: global lists are invisible (404) to restricted staff.
        $this->withToken($token)->getJson("/api/v1/admin/price-lists/{$global->id}")->assertNotFound();
        $index = $this->withToken($token)->getJson('/api/v1/admin/price-lists')->assertOk()->json('data');
        $this->assertNotContains($global->id, collect($index)->pluck('id')->all());
    }

    // ── item 30: editing a list header dispatches a full refresh ──
    public function test_list_header_edit_dispatches_full_refresh(): void
    {
        $admin = $this->staff(self::ALL);
        $token = $this->token($admin);
        $list = $this->priceList(PriceListType::Retail);

        Queue::fake();
        $this->withToken($token)->putJson("/api/v1/admin/price-lists/{$list->id}", ['priority' => 7])->assertOk();
        Queue::assertPushed(RefreshCatalogRetailPrices::class, 1);
        Queue::assertPushed(RefreshCatalogRetailPrices::class, fn ($job) => $job->productId === null);
    }
}
