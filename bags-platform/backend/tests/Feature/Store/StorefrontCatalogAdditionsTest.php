<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Enums\ProductStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Storefront catalog additions: sorts, occasions/search filters, facets, showcase, card/category fields, admin fields. */
class StorefrontCatalogAdditionsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function product(array $attrs = [], array $variantAttrs = []): Product
    {
        $product = Product::factory()->create([
            'status' => ProductStatus::Published, 'published_at' => now(), ...$attrs,
        ]);
        ProductVariant::factory()->create(['product_id' => $product->id, ...$variantAttrs]);

        return $product->refresh();
    }

    private function stock(ProductVariant $variant, float $qty): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $location = Location::factory()->create(['branch_id' => $branch->id]);
        StockLevel::forceCreate([
            'product_variant_id' => $variant->id, 'branch_id' => $branch->id, 'location_id' => $location->id,
            'on_hand' => $qty, 'reserved' => 0, 'damaged' => 0,
        ]);
    }

    private function signIn(array $permissions): void
    {
        Auth::forgetGuards();
        $staff = User::factory()->create();
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'staff');
        }
        $staff->givePermissionTo($permissions);
        $this->withToken($staff->createToken('test')->plainTextToken);
    }

    public function test_featured_sort_puts_featured_first_and_name_sorts_alphabetically(): void
    {
        $plain = $this->product(['name_en' => 'Zulu Bag', 'sales_count' => 500]);
        $featured = $this->product(['name_en' => 'Alpha Bag', 'is_featured' => true, 'sales_count' => 1]);
        $other = $this->product(['name_en' => 'Mango Bag']);

        $res = $this->getJson('/api/v1/store/products?sort=featured')->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertSame($featured->id, $ids[0]);

        $res = $this->getJson('/api/v1/store/products?sort=name')->assertOk();
        $this->assertSame(
            [$featured->id, $other->id, $plain->id],
            collect($res->json('data'))->pluck('id')->all()
        );

        $this->getJson('/api/v1/store/products?sort=bad')->assertUnprocessable();
    }

    public function test_occasions_filter_matches_any_selected_occasion(): void
    {
        $p1 = $this->product(['occasions' => ['casual', 'work']]);
        $p2 = $this->product(['occasions' => ['work', 'evening']]);
        $p3 = $this->product(['occasions' => ['evening']]);
        $p4 = $this->product(['occasions' => null]);

        $res = $this->getJson('/api/v1/store/products?occasions[]=evening')->assertOk();
        $this->assertEqualsCanonicalizing(
            [$p2->id, $p3->id],
            collect($res->json('data'))->pluck('id')->all()
        );
        $this->assertNotContains($p1->id, collect($res->json('data'))->pluck('id')->all());
        $this->assertNotContains($p4->id, collect($res->json('data'))->pluck('id')->all());
    }

    public function test_name_sort_has_stable_pagination_for_equal_names(): void
    {
        $first = $this->product(['name_en' => 'Same Bag']);
        $second = $this->product(['name_en' => 'Same Bag']);

        $this->getJson('/api/v1/store/products?sort=name&per_page=1')
            ->assertOk()->assertJsonPath('data.0.id', $second->id);
        $this->getJson('/api/v1/store/products?sort=name&per_page=1&page=2')
            ->assertOk()->assertJsonPath('data.0.id', $first->id);
    }

    public function test_search_matches_names_and_skus(): void
    {
        $tote = $this->product(['name_en' => 'The Everyday Tote']);
        $duffel = $this->product(['name_en' => 'Executive Weekender Duffel Tote']);
        $other = $this->product(['name_en' => 'City Top Handle Bag']);
        $bySku = $this->product([], ['sku' => 'SP-TEST-XYZ-01']);

        $res = $this->getJson('/api/v1/store/products?search=tote')->assertOk();
        $this->assertEqualsCanonicalizing([$tote->id, $duffel->id], collect($res->json('data'))->pluck('id')->all());
        $this->assertNotContains($other->id, collect($res->json('data'))->pluck('id')->all());

        $res = $this->getJson('/api/v1/store/products?search=test-xyz')->assertOk();
        $this->assertSame([$bySku->id], collect($res->json('data'))->pluck('id')->all());
    }

    public function test_per_page_is_honored_and_capped(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->product();
        }

        $res = $this->getJson('/api/v1/store/products?per_page=5')->assertOk();
        $this->assertSame(5, $res->json('meta.per_page'));
        $this->assertSame(7, $res->json('meta.total'));
        $this->assertCount(5, $res->json('data'));

        $this->getJson('/api/v1/store/products?per_page=100')->assertUnprocessable();
    }

    public function test_catalog_filters_returns_colors_ranges_occasions_and_total(): void
    {
        $this->product([], ['color_name_en' => 'Rose', 'color_name_ar' => 'وردي', 'color_hex' => '#e89aa8', 'retail_price' => 1250]);
        $this->product([], ['color_name_en' => 'Noir', 'color_name_ar' => 'أسود', 'color_hex' => '#1a1a1a', 'retail_price' => 750]);
        $this->product(['occasions' => ['evening']], ['color_name_en' => 'Rose', 'color_name_ar' => 'وردي', 'color_hex' => '#e89aa8']);

        $res = $this->getJson('/api/v1/store/catalog/filters')->assertOk();
        $data = $res->json('data');
        $this->assertSame(3, $data['total']);
        $this->assertSame(['noir', 'rose'], collect($data['colors'])->pluck('id')->sort()->values()->all());
        $this->assertSame([2, 1], [collect($data['colors'])->firstWhere('id', 'rose')['count'], collect($data['colors'])->firstWhere('id', 'noir')['count']]);
        $this->assertCount(3, $data['price_ranges']);
        $this->assertGreaterThan(0, collect($data['price_ranges'])->sum('count'));
        $this->assertCount(4, $data['occasions']);
        $this->assertSame(1, collect($data['occasions'])->firstWhere('id', 'evening')['count']);
    }

    public function test_catalog_filters_scoped_to_category_and_ignores_color_param(): void
    {
        $clutch = Category::factory()->create(['slug' => 'demo-clutches']);
        $this->product(['category_id' => $clutch->id], ['color_name_en' => 'Noir', 'color_hex' => '#1a1a1a']);
        $this->product([], ['color_name_en' => 'Rose', 'color_hex' => '#e89aa8']);

        $res = $this->getJson('/api/v1/store/catalog/filters?category=demo-clutches')->assertOk();
        $this->assertSame(['noir'], collect($res->json('data.colors'))->pluck('id')->all());

        $plain = $this->getJson('/api/v1/store/catalog/filters')->assertOk()->json('data');
        $withColor = $this->getJson('/api/v1/store/catalog/filters?color=noir')->assertOk()->json('data');
        $this->assertEquals($plain, $withColor);
    }

    public function test_facets_and_showcase_use_the_current_customer_price(): void
    {
        $this->product(['is_featured' => true], ['retail_price' => 1000]);
        $guest = $this->getJson('/api/v1/store/catalog/filters')->assertOk();
        $this->assertSame(0, $guest->json('data.price_ranges.0.count'));
        $group = CustomerGroup::create(['name' => 'VIP', 'code' => 'vip', 'discount_percentage' => 30]);
        $customer = Customer::factory()->create(['customer_group_id' => $group->id]);
        Auth::forgetGuards();
        $this->withToken($customer->createToken('store-token')->plainTextToken);

        $this->getJson('/api/v1/store/catalog/filters')->assertOk()
            ->assertJsonPath('data.price_ranges.0.count', 1)->assertJsonPath('data.price_ranges.1.count', 0);
        $this->getJson('/api/v1/store/showcase')->assertOk()
            ->assertJsonPath('data.0.price', 700)->assertJsonPath('data.0.variants.0.price', 700);
    }

    public function test_storefront_field_migration_rolls_back_and_can_be_applied_again(): void
    {
        $migration = require database_path('migrations/2026_10_25_100000_add_storefront_fields_to_products_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('products', 'occasions'));
        $this->assertFalse(Schema::hasColumn('products', 'badge_en'));
        $migration->up();
        $this->assertTrue(Schema::hasColumns('products', ['occasions', 'badge_en', 'badge_ar']));
        $this->product(['occasions' => ['work'], 'badge_en' => 'LUXE']);
        $this->getJson('/api/v1/store/products?occasions[]=work')->assertOk()->assertJsonPath('data.0.badge_en', 'LUXE');
    }

    public function test_showcase_returns_featured_first_then_best_sellers_with_stock_flags(): void
    {
        $f1 = $this->product(['is_featured' => true, 'sales_count' => 50]);
        $f2 = $this->product(['is_featured' => true, 'sales_count' => 200]);
        $best = $this->product(['sales_count' => 999]);
        $this->product(['sales_count' => 5]);
        $this->stock($f1->variants->first(), 4);

        $res = $this->getJson('/api/v1/store/showcase')->assertOk();
        $data = $res->json('data');
        $this->assertCount(4, $data);
        $this->assertSame([$f2->id, $f1->id, $best->id], array_column(array_slice($data, 0, 3), 'id'));
        foreach ($data as $item) {
            $this->assertNotEmpty($item['variants']);
            foreach ($item['variants'] as $variant) {
                $this->assertArrayHasKey('in_stock', $variant);
            }
        }
        $this->assertTrue(collect($data)->firstWhere('id', $f1->id)['variants'][0]['in_stock']);
    }

    public function test_card_has_new_badge_and_colors_and_show_has_extra_fields(): void
    {
        $product = $this->product(
            ['published_at' => now(), 'badge_en' => 'LUXE', 'occasions' => ['work']],
            ['color_name_en' => 'Rose', 'color_name_ar' => 'وردي', 'color_hex' => '#e89aa8']
        );
        $old = $this->product(['published_at' => now()->subDays(60)]);

        $res = $this->getJson('/api/v1/store/products')->assertOk();
        $card = collect($res->json('data'))->firstWhere('id', $product->id);
        $this->assertTrue($card['is_new']);
        $this->assertSame('LUXE', $card['badge_en']);
        $this->assertSame('Rose', $card['colors'][0]['name_en']);
        $oldCard = collect($res->json('data'))->firstWhere('id', $old->id);
        $this->assertFalse($oldCard['is_new']);

        $show = $this->getJson("/api/v1/store/products/{$product->slug}")->assertOk()->json('data');
        $this->assertSame(['work'], $show['occasions']);
        $this->assertArrayHasKey('warranty_details', $show);
        $this->assertArrayHasKey('dimensions', $show);
    }

    public function test_category_resource_includes_descriptions(): void
    {
        Category::factory()->create(['description_en' => 'Backpacks for commuters', 'description_ar' => 'شنط ظهر']);

        $res = $this->getJson('/api/v1/store/categories')->assertOk();
        $found = collect($res->json('data'))->firstWhere('description_en', 'Backpacks for commuters');
        $this->assertNotNull($found);
        $this->assertSame('شنط ظهر', $found['description_ar']);
    }

    public function test_archived_and_draft_products_are_excluded_from_filters_and_showcase(): void
    {
        $draft = Product::factory()->create(['status' => ProductStatus::Draft, 'occasions' => ['evening']]);
        ProductVariant::factory()->create(['product_id' => $draft->id, 'color_name_en' => 'Mintglow', 'color_hex' => '#aaffcc']);
        $archived = Product::factory()->create(['status' => ProductStatus::Archived, 'is_featured' => true, 'sales_count' => 9999]);
        ProductVariant::factory()->create(['product_id' => $archived->id, 'color_name_en' => 'Mintglow', 'color_hex' => '#aaffcc']);
        $visible = $this->product([], ['color_name_en' => 'Noir', 'color_hex' => '#1a1a1a']);

        $filters = $this->getJson('/api/v1/store/catalog/filters')->assertOk()->json('data');
        $this->assertSame(1, $filters['total']);
        $this->assertSame(['noir'], collect($filters['colors'])->pluck('id')->all());
        $this->assertSame(0, collect($filters['occasions'])->firstWhere('id', 'evening')['count']);

        $showcase = $this->getJson('/api/v1/store/showcase')->assertOk()->json('data');
        $this->assertSame([$visible->id], collect($showcase)->pluck('id')->all());
    }

    public function test_admin_product_occasions_validation_and_persistence(): void
    {
        $this->signIn(['products.create']);
        $categoryId = Category::factory()->create()->id;

        $this->postJson('/api/v1/admin/products', [
            'category_id' => $categoryId, 'name_ar' => 'حقيبة', 'name_en' => 'Bag',
            'sales_channel' => 'retail', 'occasions' => ['bad'],
        ])->assertUnprocessable()->assertJsonValidationErrors('occasions.0');

        $res = $this->postJson('/api/v1/admin/products', [
            'category_id' => $categoryId, 'name_ar' => 'حقيبة', 'name_en' => 'Work Bag',
            'sales_channel' => 'retail', 'occasions' => ['work'], 'badge_en' => 'LUXE',
        ])->assertCreated();

        $saved = Product::query()->findOrFail($res->json('data.id'));
        $this->assertSame(['work'], $saved->occasions);
        $this->assertSame('LUXE', $saved->badge_en);
    }
}
