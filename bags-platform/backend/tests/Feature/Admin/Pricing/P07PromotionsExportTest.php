<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Pricing;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\PriceListType;
use App\Enums\ProductStatus;
use App\Enums\SalesChannel;
use App\Exports\WholesalePriceListExport;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\WholesalePriceListService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * task.txt items 31 (wholesale export safety/values) and 37 (promotions).
 */
class P07PromotionsExportTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function staff(array $perms): User
    {
        foreach ($perms as $p) {
            Permission::findOrCreate($p, 'staff');
        }
        $u = User::factory()->create();
        $u->givePermissionTo($perms);

        return $u;
    }

    private function promoAdmin(): User
    {
        return $this->staff(['promotions.view', 'promotions.create', 'promotions.update', 'promotions.delete', 'coupons.view', 'coupons.create']);
    }

    private function token(User $u): string
    {
        Auth::forgetGuards();

        return $u->createToken('test')->plainTextToken;
    }

    private function customer(CustomerType $type, CustomerStatus $status, ?int $groupId = null): Customer
    {
        return Customer::query()->create(['name' => 'c', 'email' => uniqid().'@example.com',
            'phone' => '010'.random_int(10000000, 99999999), 'password' => Hash::make('password'),
            'company_name' => 'Company', 'tax_number' => '123', 'commercial_register' => '456',
            'type' => $type, 'status' => $status, 'customer_group_id' => $groupId]);
    }

    private function publishedProduct(SalesChannel $channel = SalesChannel::Both, ?float $retail = 500, ?float $wholesale = 400): array
    {
        $product = Product::factory()->create(['category_id' => Category::factory()->create(['is_active' => true])->id,
            'code' => 'W'.uniqid(), 'status' => ProductStatus::Published, 'sales_channel' => $channel]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id,
            'retail_price' => $retail, 'wholesale_price' => $wholesale, 'cost_price' => 50, 'is_active' => true]);

        return [$product, $variant];
    }

    private function promotion(array $overrides = []): array
    {
        return $this->withToken($this->token($this->promoAdmin()))->postJson('/api/v1/admin/promotions', array_merge([
            'name' => 'Promo '.uniqid(), 'type' => 'percentage_discount', 'value' => 10,
        ], $overrides))->assertCreated()->json('data');
    }

    // ── item 37: promotion validation matrix ──
    public function test_promotion_validation_matrix(): void
    {
        $token = $this->token($this->promoAdmin());

        // Unknown types are rejected.
        $this->withToken($token)->postJson('/api/v1/admin/promotions',
            ['name' => 'B', 'type' => 'buy_x_get_y', 'value' => 10])->assertStatus(422);

        // Scoped promotions need existing targets.
        $this->withToken($token)->postJson('/api/v1/admin/promotions',
            ['name' => 'C', 'type' => 'percentage_discount', 'value' => 10, 'applies_to' => 'categories'])->assertStatus(422);
        $cat = Category::factory()->create(['is_active' => true]);
        $scoped = $this->withToken($token)->postJson('/api/v1/admin/promotions',
            ['name' => 'C', 'type' => 'percentage_discount', 'value' => 10, 'applies_to' => 'categories', 'target_ids' => [$cat->id]])
            ->assertCreated()->json('data');
        $this->assertSame('categories', $scoped['applies_to']);

        // Over-100% fails on create and on type switch.
        $this->withToken($token)->postJson('/api/v1/admin/promotions',
            ['name' => 'H', 'type' => 'percentage_discount', 'value' => 101])->assertStatus(422);
        $fixed = $this->promotion(['type' => 'fixed_discount', 'value' => 150]);
        $this->withToken($token)->putJson("/api/v1/admin/promotions/{$fixed['id']}",
            ['name' => $fixed['name'], 'type' => 'percentage_discount', 'value' => 150])->assertStatus(422);
    }

    // ── item 37: auto_applied is always forced off (owner decision) ──
    public function test_auto_applied_is_always_forced_off(): void
    {
        // There is no auto_applied column: the input key is ignored and the
        // resource always reports false. Promotions never auto-apply at checkout.
        $created = $this->promotion(['auto_applied' => true]);
        $this->assertFalse((bool) $created['auto_applied']);
        $this->assertDatabaseHas('promotions', ['id' => $created['id']]);
    }

    // ── item 31: Excel cells are neutralized; nulls and decisions documented ──
    public function test_wholesale_export_neutralizes_formula_cells(): void
    {
        $rows = [[
            'sku' => '=HYPERLINK("http://x","c")', 'barcode' => '+123', 'product_name' => '@SUM(A1)',
            'base_price' => 100.0, 'wholesale_price' => 90.0, 'tier_prices' => [], 'available_qty' => 3.0,
        ], [
            'sku' => 'NORMAL-1', 'barcode' => null, 'product_name' => 'حقيبة',
            'base_price' => null, 'wholesale_price' => null, 'tier_prices' => [], 'available_qty' => 0.0,
        ]];

        $array = (new WholesalePriceListExport($rows))->array();
        $this->assertSame("'=HYPERLINK(\"http://x\",\"c\")", $array[0][0]);
        $this->assertSame("'+123", $array[0][1]);
        $this->assertSame("'@SUM(A1)", $array[0][2]);
        $this->assertSame('NORMAL-1', $array[1][0]);
        $this->assertNull($array[1][3]);
        $this->assertNull($array[1][4]);
        $this->assertSame("'=HYPERLINK", WholesalePriceListExport::cellText('=HYPERLINK'));
        $this->assertSame('abc', WholesalePriceListExport::cellText('abc'));
        $this->assertNull(WholesalePriceListExport::cellText(null));
    }

    // ── item 31: export rows cover retail-only products and unapproved dealers ──
    public function test_wholesale_rows_cover_retail_only_and_unapproved(): void
    {
        [$retailOnlyProduct, $retailOnlyVariant] = $this->publishedProduct(SalesChannel::Retail, 500, null);
        [$bothProduct, $bothVariant] = $this->publishedProduct(SalesChannel::Both, 500, 400);

        $list = PriceList::query()->create(['name' => 'W', 'type' => PriceListType::Wholesale]);
        $list->items()->create(['product_variant_id' => $bothVariant->id, 'min_qty' => 1, 'price' => 350]);

        $group = CustomerGroup::query()->create(['name' => 'G', 'code' => 'G'.uniqid(), 'discount_percentage' => 10]);
        $approved = $this->customer(CustomerType::Wholesale, CustomerStatus::Approved, $group->id);
        $rows = app(WholesalePriceListService::class)->rows($approved);
        $bySku = collect($rows)->keyBy('sku');

        // Group percentage applies on top of the channel list price (item 11):
        // 350 × 0.9 = 315. Customer-specific and customer-group lists return
        // early with final prices (no compounding); see P07PricingEngine2Test.
        $this->assertSame(315.0, $bySku[$bothVariant->sku]['wholesale_price']);
        // Retail-only products still appear (owner decision: no channel filter).
        $this->assertArrayHasKey($retailOnlyVariant->sku, $bySku->toArray());

        // Unapproved dealers see retail pricing under the wholesale header (owner decision).
        $pending = $this->customer(CustomerType::Wholesale, CustomerStatus::Pending);
        $pendingRows = collect(app(WholesalePriceListService::class)->rows($pending))->keyBy('sku');
        $this->assertSame(500.0, $pendingRows[$bothVariant->sku]['wholesale_price']);

        // Variants without any wholesale price resolve through the retail base,
        // still carrying the group percentage: 500 × 0.9 = 450.
        $this->assertSame(450.0, $bySku[$retailOnlyVariant->sku]['wholesale_price']);
    }
}
