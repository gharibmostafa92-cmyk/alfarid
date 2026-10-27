<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\StorefrontDemoSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class StorefrontDemoSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_demo_populates_home_and_rerunning_does_not_duplicate_products_or_stock(): void
    {
        Storage::fake('public');
        config(['filesystems.banners_disk' => 'public']);
        User::factory()->create();

        $this->seed(StorefrontDemoSeeder::class);
        $mediaCount = Media::query()->count();
        Product::query()->where('slug', 'demo-everyday-tote')->update([
            'published_at' => now(), 'material_en' => 'Outdated demo material',
        ]);
        $this->seed(StorefrontDemoSeeder::class);
        $this->assertSame($mediaCount, Media::query()->count());

        $this->assertSame(12, Product::query()->where('slug', 'like', 'demo-%')->where('status', 'published')->count());
        $this->assertSame(6, Banner::query()->count());
        $this->assertSame(21, StockMovement::query()->count());
        $response = $this->getJson('/api/v1/store/home')->assertOk()
            ->assertJsonCount(6, 'data.banners')->assertJsonCount(12, 'data.best_sellers')
            ->assertJsonCount(12, 'data.latest_products')->assertJsonCount(3, 'data.discounted_products');
        $this->assertSame(1, collect($response->json('data.best_sellers'))->where('in_stock', false)->count());
        $this->assertSame(3, collect($response->json('data.banners'))->where('position', 'main_slider')->count());
        $product = Product::query()->where('slug', 'demo-everyday-tote')->firstOrFail();
        $media = $product->getFirstMedia('images');
        Storage::disk('public')->assertExists($media->getPathRelativeToRoot());
        $this->assertNotEmpty($response->json('data.best_sellers.0.image'));
        $this->assertSame($product->description_en, $product->material_en);
        $this->assertTrue($product->published_at->lt(now()->subDays(30)));

        $this->getJson('/api/v1/store/products?category=demo-backpacks')->assertOk()
            ->assertJsonPath('meta.total', 5);
        $under800 = $this->getJson('/api/v1/store/products?price_max=799.99')->assertOk();
        $this->assertEqualsCanonicalizing(
            ['demo-soft-rose-daypack', 'demo-mini-pastel-crossbody'],
            array_column($under800->json('data'), 'slug')
        );
        $evening = $this->getJson('/api/v1/store/products?occasions[]=evening')->assertOk();
        $this->assertEqualsCanonicalizing(
            ['demo-parisian-crossbody', 'demo-saffiano-handbag', 'demo-quilted-clutch'],
            array_column($evening->json('data'), 'slug')
        );
        $this->getJson('/api/v1/store/products?search=tote')->assertOk()->assertJsonPath('meta.total', 2);
        $facets = $this->getJson('/api/v1/store/catalog/filters')->assertOk()
            ->assertJsonPath('data.total', 12)->assertJsonCount(5, 'data.colors')
            ->assertJsonCount(3, 'data.price_ranges');
        $this->assertSame([2, 7, 3], array_column($facets->json('data.price_ranges'), 'count'));
        $this->getJson('/api/v1/store/products/demo-soft-rose-daypack')->assertOk()
            ->assertJsonPath('data.in_stock', false)->assertJsonPath('data.variants.0.in_stock', false);
        $detail = $this->getJson('/api/v1/store/products/demo-vogue-elegance-backpack')->assertOk();
        $variants = collect($detail->json('data.variants'));
        $this->assertCount(3, $variants);
        $this->assertNotEmpty($variants->firstWhere('color_name_en', 'Rose')['images']);
        $this->assertNotSame(
            $variants->firstWhere('color_name_en', 'Rose')['images'][0]['url'],
            $variants->firstWhere('color_name_en', 'Camel')['images'][0]['url']
        );
    }

    public function test_regular_product_uploads_still_cannot_use_the_public_disk(): void
    {
        Storage::fake('public');
        $product = Product::factory()->create();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Uploaded media must be stored on R2.');

        $product->addMedia(base_path('../frontend/apps/storefront/public/images/demo/city-backpack.jpg'))
            ->preservingOriginal()->storingConversionsOnDisk('public')->toMediaCollection('images', 'public');
    }

    public function test_demo_marker_cannot_enable_public_disk_uploads_in_production(): void
    {
        Storage::fake('public');
        $product = Product::factory()->create();
        $this->app->instance('env', 'production');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Uploaded media must be stored on R2.');

        $product->addMedia(base_path('../frontend/apps/storefront/public/images/demo/city-backpack.jpg'))
            ->preservingOriginal()->withCustomProperties(['storefront_demo' => true])
            ->storingConversionsOnDisk('public')->toMediaCollection('images', 'public');
    }

    public function test_demo_marker_cannot_enable_public_disk_uploads_for_regular_variants(): void
    {
        Storage::fake('public');
        $product = Product::factory()->create(['slug' => 'regular-product']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Uploaded media must be stored on R2.');
        $variant->addMedia(base_path('../frontend/apps/storefront/public/images/demo/city-backpack.jpg'))
            ->preservingOriginal()->withCustomProperties(['storefront_demo' => true])
            ->storingConversionsOnDisk('public')->toMediaCollection('images', 'public');
    }
}
