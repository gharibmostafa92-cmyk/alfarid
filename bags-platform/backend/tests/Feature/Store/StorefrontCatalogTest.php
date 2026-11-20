<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Enums\Catalog\ProductStatus;
use App\Models\Banner;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\City;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** المرحلة 6: واجهة المتجر للقراءة (عام + تسعير شخصي عند وجود توكن). */
class StorefrontCatalogTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function product(array $attrs = [], array $variantAttrs = []): Product
    {
        $product = Product::factory()->create(['status' => ProductStatus::Published, ...$attrs]);
        ProductVariant::factory()->create(['product_id' => $product->id, 'retail_price' => 1000, ...$variantAttrs]);

        return $product->refresh();
    }

    private function stock(ProductVariant $variant, float $qty, bool $branchActive = true): void
    {
        $branch = Branch::factory()->create(['is_active' => $branchActive]);
        $location = Location::factory()->create(['branch_id' => $branch->id]);
        StockLevel::forceCreate([
            'product_variant_id' => $variant->id, 'branch_id' => $branch->id, 'location_id' => $location->id,
            'on_hand' => $qty, 'reserved' => 0, 'damaged' => 0,
        ]);
    }

    public function test_home_aggregates_sections_and_caches_ids(): void
    {
        Banner::factory()->create(['title' => 'ساري', 'sort_order' => 1]);
        Banner::factory()->create(['title' => 'مستقبلي', 'starts_at' => now()->addDay()]);
        Banner::factory()->create(['title' => 'منتهي', 'ends_at' => now()->subDay()]);
        Banner::factory()->create(['title' => 'معطل', 'is_active' => false]);
        Banner::factory()->create(['title' => 'بلا صورة', 'image_path' => '']);
        $old = $this->product(['sales_count' => 50]);
        $new = $this->product();
        $discounted = $this->product();
        $discounted->variants()->first()->update(['compare_at_price' => 1500]);
        Product::factory()->create(['status' => ProductStatus::Draft]);

        $response = $this->getJson('/api/v1/store/home')->assertOk();
        $this->assertSame(['ساري'], collect($response->json('data.banners'))->pluck('title')->all());
        $this->assertSame($discounted->id, $response->json('data.latest_products.0.id')); // الأحدث إنشاءً
        $this->assertSame($old->id, $response->json('data.best_sellers.0.id'));
        $this->assertContains($discounted->id, collect($response->json('data.discounted_products'))->pluck('id')->all());
        $this->assertSame(3, collect($response->json('data.latest_products'))->count()); // المسودة مستبعدة

        // A newly published product is visible after the catalog changes.
        $this->product();
        $again = $this->getJson('/api/v1/store/home')->assertOk();
        $this->assertSame(4, collect($again->json('data.latest_products'))->count());
        Cache::forget('store_home_data:v2');
        $fresh = $this->getJson('/api/v1/store/home')->assertOk();
        $this->assertSame(4, collect($fresh->json('data.latest_products'))->count());
    }

    public function test_categories_tree_counts_only_published_and_brands_cities_active_only(): void
    {
        $root = Category::factory()->create(['parent_id' => null]);
        $child = Category::factory()->create(['parent_id' => $root->id]);
        Category::factory()->create(['is_active' => false]);
        $this->product(['category_id' => $child->id]);
        Product::factory()->create(['status' => ProductStatus::Draft, 'category_id' => $child->id]);
        $activeBrand = Brand::factory()->create(['is_active' => true]);
        $inactiveBrand = Brand::factory()->create(['is_active' => false]);
        City::factory()->create(['is_active' => true, 'shipping_cost' => 60]);
        City::factory()->create(['is_active' => false]);

        $categories = $this->getJson('/api/v1/store/categories')->assertOk();
        $this->assertCount(1, $categories->json('data'));
        $this->assertSame(1, $categories->json('data.0.products_count'));
        $this->assertSame(0, $categories->json('data.0.direct_products_count'));
        $this->assertSame(1, $categories->json('data.0.children.0.products_count'));

        // مصانع المنتجات تنشئ علامات إضافية — نتحقق من الحضور/الغياب لا العدد الكلي
        $brands = $this->getJson('/api/v1/store/brands')->assertOk();
        $brandIds = collect($brands->json('data'))->pluck('id')->all();
        $this->assertContains($activeBrand->id, $brandIds);
        $this->assertNotContains($inactiveBrand->id, $brandIds);
        $cities = $this->getJson('/api/v1/store/cities')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('60.00', $cities->json('data.0.shipping_cost'));
    }

    public function test_products_filters_and_sorts(): void
    {
        $catA = Category::factory()->create();
        $subA = Category::factory()->create(['parent_id' => $catA->id]);
        $brandX = Brand::factory()->create();
        $brandY = Brand::factory()->create();

        $p1 = $this->product(['category_id' => $subA->id, 'brand_id' => $brandX->id], ['color_name_en' => 'Black', 'retail_price' => 500]);
        $p2 = $this->product(['category_id' => $catA->id, 'brand_id' => $brandY->id], ['color_name_en' => 'Red', 'retail_price' => 1500]);
        $p3 = $this->product(['brand_id' => $brandY->id], ['retail_price' => 900, 'color_name_en' => 'Navy', 'color_name_ar' => 'أزرق']);
        $this->stock($p1->variants->first(), 3);
        Product::factory()->create(['status' => ProductStatus::Draft, 'category_id' => $subA->id]);

        // القسم بالـ slug يشمل الفرعي
        $byCat = $this->getJson("/api/v1/store/products?category={$catA->slug}")->assertOk();
        $this->assertEqualsCanonicalizing([$p1->id, $p2->id], collect($byCat->json('data'))->pluck('id')->all());

        // علامات متعددة + لون + مدى سعر
        $this->getJson('/api/v1/store/products?brand[]='.$brandX->slug)->assertUnprocessable()->assertJsonValidationErrors('brand');
        $filtered = $this->getJson('/api/v1/store/products?color=black&price_min=100&price_max=1000')->assertOk();
        $this->assertSame([$p1->id], collect($filtered->json('data'))->pluck('id')->all());

        // المتوفر فقط
        $inStock = $this->getJson('/api/v1/store/products?in_stock=1')->assertOk();
        $this->assertSame([$p1->id], collect($inStock->json('data'))->pluck('id')->all());
        $card = collect($inStock->json('data'))->first();
        $this->assertTrue($card['in_stock']);
        $this->assertArrayNotHasKey('store_available', $card);

        // الفرز بالسعر
        $asc = $this->getJson('/api/v1/store/products?sort=price_asc')->assertOk();
        $this->assertSame([$p1->id, $p3->id, $p2->id], collect($asc->json('data'))->pluck('id')->all());

        // مخزون في فرع معطّل لا يُحتسب
        $p4 = $this->product();
        $this->stock($p4->variants->first(), 9, branchActive: false);
        $inStock2 = $this->getJson('/api/v1/store/products?in_stock=1')->assertOk();
        $this->assertSame([$p1->id], collect($inStock2->json('data'))->pluck('id')->all());
    }

    public function test_card_price_is_personalized_when_a_customer_token_is_sent(): void
    {
        $group = CustomerGroup::create(['name' => 'VIP', 'code' => 'vip', 'discount_percentage' => 10]);
        $product = $this->product([], ['retail_price' => 1000]);

        // زائر: السعر الأساسي للقناة
        $guest = $this->getJson('/api/v1/store/products')->assertOk();
        $this->assertSame(1000.0, (float) collect($guest->json('data'))->firstWhere('id', $product->id)['price']);

        // عميل بمجموعة خصم 10%: خصم المجموعة فوق سعر القناة
        $customer = Customer::factory()->create(['customer_group_id' => $group->id]);
        $token = $customer->createToken('store-token')->plainTextToken;
        Auth::forgetGuards();
        $mine = $this->withToken($token)->getJson('/api/v1/store/products')->assertOk();
        $card = collect($mine->json('data'))->firstWhere('id', $product->id);
        $this->assertSame(900.0, (float) $card['price']);
        $this->assertSame(10, $card['discount_percentage']);
    }

    public function test_show_by_slug_with_variant_prices_and_availability_and_draft_404(): void
    {
        $product = $this->product();
        $variant = $product->variants->first();
        $second = ProductVariant::factory()->create(['product_id' => $product->id, 'retail_price' => 2000, 'compare_at_price' => 2500]);
        $inactive = ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => false]);
        $this->stock($variant, 5);
        $this->stock($second, 0);

        $response = $this->getJson("/api/v1/store/products/{$product->slug}")->assertOk();
        $variants = collect($response->json('data.variants'));
        $this->assertCount(2, $variants); // المعطّل مستبعد
        $first = $variants->firstWhere('id', $variant->id);
        $this->assertSame(1000.0, (float) $first['price']);
        $this->assertArrayNotHasKey('available', $first);
        $this->assertTrue($first['in_stock']);
        $secondData = $variants->firstWhere('id', $second->id);
        $this->assertSame(2500.0, (float) $secondData['old_price']);
        $this->assertFalse($secondData['in_stock']);

        $draft = Product::factory()->create(['status' => ProductStatus::Draft]);
        $this->getJson("/api/v1/store/products/{$draft->slug}")->assertNotFound();
        $this->getJson('/api/v1/store/products/no-such-slug')->assertNotFound();
    }

    public function test_similar_uses_same_category_without_retired_brand_fallback_and_excludes_self(): void
    {
        $catA = Category::factory()->create();
        $catB = Category::factory()->create();
        $brandX = Brand::factory()->create();
        $brandY = Brand::factory()->create();

        $base = $this->product(['category_id' => $catA->id, 'brand_id' => $brandX->id]);
        $sameCat = $this->product(['category_id' => $catA->id, 'brand_id' => $brandY->id]);
        $sameBrand = $this->product(['category_id' => $catB->id, 'brand_id' => $brandX->id]);
        $unrelated = $this->product(['category_id' => $catB->id, 'brand_id' => $brandY->id]);

        $response = $this->getJson("/api/v1/store/products/{$base->slug}/similar")->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$sameCat->id], $ids);
        $this->assertNotContains($sameBrand->id, $ids);
        $this->assertNotContains($unrelated->id, $ids);
        $this->assertNotContains($base->id, $ids);
    }

    public function test_unpriced_variant_does_not_break_listing(): void
    {
        $product = Product::factory()->create(['status' => ProductStatus::Published]);
        ProductVariant::factory()->create(['product_id' => $product->id, 'retail_price' => null, 'wholesale_price' => null]);

        $response = $this->getJson('/api/v1/store/products')->assertOk();
        $card = collect($response->json('data'))->firstWhere('id', $product->id);
        $this->assertNull($card['price']);
    }

    public function test_catalog_query_validation_and_missing_category(): void
    {
        $this->getJson('/api/v1/store/products?category=nonexistent-slug')->assertOk()->assertJsonCount(0, 'data');
        foreach (['/api/v1/store/products', '/api/v1/store/catalog/filters'] as $url) {
            $this->getJson($url.'?brand=1')->assertUnprocessable()->assertJsonValidationErrors('brand');
            $this->getJson($url.'?search=a')->assertUnprocessable()->assertJsonValidationErrors('search');
            $this->getJson($url.'?price_min=100&price_max=50')->assertUnprocessable()->assertJsonValidationErrors('price_max');
            $this->getJson($url.'?occasions[]=bogus')->assertUnprocessable()->assertJsonValidationErrors('occasions.0');
            $this->getJson($url.'?occasions[]=work&occasions[]=casual&occasions[]=evening&occasions[]=travel&occasions[]=work')
                ->assertUnprocessable()->assertJsonValidationErrors('occasions');
        }
    }

    public function test_home_personalized_prices_are_private_and_discounted_cards_are_bounded(): void
    {
        $group = CustomerGroup::create(['name' => 'VIP', 'code' => 'vip', 'discount_percentage' => 10]);
        for ($i = 0; $i < 12; $i++) {
            $this->product([], ['retail_price' => 1000]);
        }
        $product = $this->product([], ['retail_price' => 1000]);
        $guest = $this->getJson('/api/v1/store/home')->assertOk();
        $this->assertSame(1000.0, (float) collect($guest->json('data.latest_products'))->firstWhere('id', $product->id)['price']);
        $customer = Customer::factory()->create(['customer_group_id' => $group->id]);
        $token = $customer->createToken('store-token')->plainTextToken;
        Auth::forgetGuards();
        $response = $this->withToken($token)->getJson('/api/v1/store/home')->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertSame(900.0, (float) collect($response->json('data.latest_products'))->firstWhere('id', $product->id)['price']);
        $discounted = $response->json('data.discounted_products');
        $this->assertCount(12, $discounted);
        foreach ($discounted as $card) {
            $this->assertNotNull($card['price']);
            $this->assertNotNull($card['discount_percentage']);
        }
    }

    public function test_categories_and_brands_exclude_inactive_entries_and_are_ordered(): void
    {
        $root = Category::factory()->create(['parent_id' => null, 'sort_order' => 2]);
        $first = Category::factory()->create(['parent_id' => null, 'sort_order' => 1]);
        $child = Category::factory()->create(['parent_id' => $root->id]);
        $inactive = Category::factory()->create(['parent_id' => $root->id, 'is_active' => false]);
        $hidden = Category::factory()->create(['parent_id' => $inactive->id]);
        $visibleProduct = $this->product(['category_id' => $child->id]);
        $hiddenProduct = $this->product(['category_id' => $hidden->id]);
        $tree = $this->getJson('/api/v1/store/categories')->assertOk()->json('data');
        $this->assertSame([$first->id, $root->id], collect($tree)->pluck('id')->all());
        $this->assertSame([$child->id], collect($tree[1]['children'])->pluck('id')->all());
        $this->assertNull($tree[1]['parent_id']);
        $this->assertSame($root->id, $tree[1]['children'][0]['parent_id']);
        $this->assertSame(1, $tree[1]['active_products_count']);
        $this->assertSame(1, $tree[1]['products_count']);
        $products = $this->getJson('/api/v1/store/products?category='.$root->slug)->assertOk()->json('data');
        $ids = collect($products)->pluck('id')->all();
        $this->assertContains($visibleProduct->id, $ids);
        $this->assertNotContains($hiddenProduct->id, $ids);
        Brand::query()->update(['is_active' => false]);
        $later = Brand::factory()->create(['is_active' => true, 'sort_order' => 2, 'name_ar' => 'أ']);
        $second = Brand::factory()->create(['is_active' => true, 'sort_order' => 1, 'name_ar' => 'ب']);
        $earlier = Brand::factory()->create(['is_active' => true, 'sort_order' => 1, 'name_ar' => 'أ']);
        $brands = $this->getJson('/api/v1/store/brands')->assertOk()->json('data');
        $this->assertSame([$earlier->id, $second->id, $later->id], collect($brands)->pluck('id')->all());
    }

    public function test_stock_filter_excludes_receiving_and_damaged_locations_and_sale_filter_uses_discount(): void
    {
        $hidden = $this->product();
        foreach (['receiving', 'damaged'] as $type) {
            $location = Location::factory()->create(['type' => $type]);
            StockLevel::forceCreate(['product_variant_id' => $hidden->variants->first()->id, 'branch_id' => $location->branch_id,
                'location_id' => $location->id, 'on_hand' => 5, 'reserved' => 0, 'damaged' => 0]);
        }
        $sale = $this->product([], ['compare_at_price' => 1500]);
        $this->stock($sale->variants->first(), 3);
        $stock = $this->getJson('/api/v1/store/products?in_stock=1')->assertOk()->json('data');
        $this->assertSame([$sale->id], collect($stock)->pluck('id')->all());
        $sales = $this->getJson('/api/v1/store/products?on_sale=1')->assertOk()->json('data');
        $this->assertSame([$sale->id], collect($sales)->pluck('id')->all());
    }

    public function test_search_matches_arabic_names_and_ignores_inactive_variant_skus(): void
    {
        $product = $this->product(['name_ar' => 'حقيبة مميزة']);
        ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'HIDDEN-ABC-987', 'is_active' => false]);
        $result = $this->getJson('/api/v1/store/products?search='.urlencode('حقيبة'))->assertOk()->json('data');
        $this->assertContains($product->id, collect($result)->pluck('id')->all());
        $this->getJson('/api/v1/store/products?search=HIDDEN-ABC-987')->assertOk()->assertJsonCount(0, 'data');
    }
}
