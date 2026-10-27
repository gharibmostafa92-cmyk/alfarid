<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Catalog;

use App\Exceptions\BusinessException;
use App\Http\Requests\Admin\Brand\StoreBrandRequest;
use App\Http\Requests\Admin\Brand\UpdateBrandRequest;
use App\Http\Requests\Admin\Category\StoreCategoryRequest;
use App\Http\Requests\Admin\Category\UpdateCategoryRequest;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Rules\MaxImagePixels;
use App\Services\Catalog\CategoryService;
use App\Services\Catalog\ProductService;
use App\Services\Catalog\VariantService;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CatalogCompletionReviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_same_second_product_and_variant_edits_reject_stale_versions(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $this->freezeTime();
        $first = app(ProductService::class)->update($product, ['name_en' => 'First', 'expected_version' => 0], $user);
        $this->assertSame(1, (int) $first->lock_version);
        try {
            app(ProductService::class)->update($product, ['name_en' => 'Stale', 'expected_version' => 0], $user);
            $this->fail('Stale product edit accepted.');
        } catch (BusinessException $exception) {
            $this->assertSame(409, $exception->status);
        }
        $firstVariant = app(VariantService::class)->update($variant, ['retail_price' => 20, 'expected_version' => 0]);
        $this->assertSame(1, (int) $firstVariant->lock_version);
        try {
            app(VariantService::class)->update($variant, ['retail_price' => 30, 'expected_version' => 0]);
            $this->fail('Stale variant edit accepted.');
        } catch (BusinessException $exception) {
            $this->assertSame(409, $exception->status);
        }
        $this->assertSame('First', $product->fresh()->name_en);
        $this->assertSame('20.00', $variant->fresh()->retail_price);
    }

    public function test_variant_api_returns_edit_version_and_four_decimal_cost(): void
    {
        foreach (['products.view', 'products.update', 'products.view_cost'] as $permission) {
            Permission::findOrCreate($permission, 'staff');
        }
        $user = User::factory()->create();
        $user->givePermissionTo(['products.view', 'products.update', 'products.view_cost']);
        $this->withToken($user->createToken('versions')->plainTextToken);
        $variant = ProductVariant::factory()->create(['cost_price' => '1.2345']);
        $this->getJson('/api/v1/admin/products/'.$variant->product_id)->assertOk()
            ->assertJsonPath('data.variants.0.lock_version', 0)->assertJsonPath('data.variants.0.cost_price', '1.2345');
        $url = '/api/v1/admin/products/'.$variant->product_id.'/variants/'.$variant->id;
        $this->putJson($url, ['retail_price' => 25, 'expected_version' => 0])->assertOk()->assertJsonPath('data.lock_version', 1);
        $this->putJson($url, ['retail_price' => 30, 'expected_version' => 0])->assertConflict();
    }

    public function test_create_retry_with_changed_data_is_rejected_without_a_duplicate(): void
    {
        $user = User::factory()->create();
        $payload = ['category_id' => Category::factory()->create()->id, 'name_ar' => 'حقيبة', 'name_en' => 'Original',
            'sales_channel' => 'retail', 'create_key' => '11111111-2222-4333-8444-555555555555'];
        $product = app(ProductService::class)->create($payload, $user);
        try {
            app(ProductService::class)->create([...$payload, 'name_en' => 'Changed'], $user);
            $this->fail('Changed create payload accepted.');
        } catch (BusinessException $exception) {
            $this->assertSame(409, $exception->status);
            $this->assertSame([$product->id], $exception->errors['product_id']);
        }
        $this->assertDatabaseCount('products', 1);
    }

    public function test_http_create_retry_can_replay_explicit_slug_sku_and_barcode(): void
    {
        Permission::findOrCreate('products.create', 'staff');
        $user = User::factory()->create();
        $user->givePermissionTo('products.create');
        $this->withToken($user->createToken('replay')->plainTextToken);
        $payload = ['category_id' => Category::factory()->create()->id, 'name_ar' => 'حقيبة', 'name_en' => 'Explicit',
            'sales_channel' => 'retail', 'slug' => 'explicit', 'create_key' => '11111111-2222-4333-8444-555555555555',
            'variants' => [['sku' => 'EXPLICIT-1', 'barcode' => 'EXT-1', 'retail_price' => 10]]];
        $first = $this->postJson('/api/v1/admin/products', $payload)->assertCreated();
        $second = $this->postJson('/api/v1/admin/products', $payload)->assertCreated();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_variants', 1);
    }

    public function test_category_network_io_runs_after_tree_transaction_has_ended(): void
    {
        Category::query()->count();
        $baseline = DB::transactionLevel();
        Media::creating(function () use ($baseline): void {
            // Only the media transaction (and this test's outer transaction)
            // may remain while files are written, never the tree transaction.
            $this->assertSame($baseline + 1, DB::transactionLevel());
        });
        $service = app(CategoryService::class);
        $category = $service->create(['name_ar' => 'قسم', 'name_en' => 'Separate upload'], UploadedFile::fake()->image('first.jpg'));
        $service->update($category, ['description_en' => 'Updated'], UploadedFile::fake()->image('second.jpg'));
    }

    public function test_batched_pricing_matches_single_resolver_and_does_not_query_per_variant_or_tier(): void
    {
        $group = CustomerGroup::create(['name' => 'Trade', 'code' => 'TRADE', 'discount_percentage' => 10, 'is_active' => true]);
        $customer = Customer::factory()->wholesale()->create(['status' => 'approved', 'customer_group_id' => $group->id]);
        $other = Customer::factory()->create();
        $variants = ProductVariant::factory()->count(8)->create(['retail_price' => 100]);
        foreach ([
            ['type' => 'retail', 'price' => 80, 'min' => 1],
            ['type' => 'wholesale', 'price' => 60, 'min' => 5],
            ['type' => 'customer_group', 'price' => 50, 'min' => 10, 'customer_group_id' => $group->id],
            ['type' => 'customer_specific', 'price' => 40, 'min' => 20, 'customer_id' => $customer->id],
            ['type' => 'customer_specific', 'price' => 1, 'min' => 1, 'customer_id' => $other->id],
        ] as $fixture) {
            $list = PriceList::create([
                'name' => $fixture['type'], 'type' => $fixture['type'], 'is_active' => true,
                'customer_id' => $fixture['customer_id'] ?? null, 'customer_group_id' => $fixture['customer_group_id'] ?? null,
            ]);
            foreach ($variants as $variant) {
                $list->items()->create(['product_variant_id' => $variant->id, 'price' => $fixture['price'], 'min_qty' => $fixture['min']]);
            }
        }
        $pricing = app(PricingService::class);
        $expected = $variants->mapWithKeys(fn ($variant) => [$variant->id => [
            'tiers' => $pricing->tiersFor($variant, $customer),
            'prices' => array_map(fn ($qty) => $pricing->resolve($variant, $customer, $qty), [1, 5, 10, 20]),
        ]]);
        DB::enableQueryLog();
        try {
            $context = $pricing->contextFor($customer, $variants->pluck('id')->all());
            foreach ($variants as $variant) {
                $this->assertSame($expected[$variant->id]['tiers'], $pricing->tiersFor($variant, $customer, $context));
                foreach ([1, 5, 10, 20] as $index => $qty) {
                    $this->assertSame($expected[$variant->id]['prices'][$index], $pricing->resolve($variant, $customer, $qty, null, $context));
                }
            }
            $this->assertCount(1, DB::getQueryLog(), 'The loaded group is reused; only the batched price query is needed.');
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_pruner_keeps_live_recent_and_non_uuid_files_and_supports_dry_run(): void
    {
        $disk = Storage::disk('r2_public');
        $live = Product::factory()->create()->addMedia(UploadedFile::fake()->image('live.jpg'))->toMediaCollection('images');
        $old = '11111111-2222-4333-8444-555555555555/orphan.jpg';
        $recent = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee/recent.jpg';
        foreach ([$old, $recent, 'customer_documents/id.pdf'] as $path) {
            $disk->put($path, 'content');
        }
        touch($disk->path($old), now()->subHours(2)->timestamp);
        touch($disk->path($live->getPathRelativeToRoot()), now()->subHours(2)->timestamp);
        $this->artisan('media:prune-orphans', ['--dry-run' => true])->assertSuccessful();
        $disk->assertExists($old);
        $this->artisan('media:prune-orphans')->assertSuccessful();
        $disk->assertMissing($old);
        $disk->assertExists($recent);
        $disk->assertExists('customer_documents/id.pdf');
        $disk->assertExists($live->getPathRelativeToRoot());
        $this->artisan('media:prune-orphans', ['--disk' => 'local'])->assertFailed();
        $this->artisan('media:prune-orphans', ['--older-than' => 1])->assertFailed();
    }

    public function test_shared_image_pixel_rule_rejects_oversized_headers_without_decoding_them(): void
    {
        $image = UploadedFile::fake()->image('dimensions.png', 1, 1);
        $path = $image->getPathname();
        $bytes = file_get_contents($path);
        // Change only the PNG dimension header; the test never allocates a
        // 36-million-pixel bitmap to exercise its validation boundary.
        file_put_contents($path, substr_replace($bytes, pack('NN', 6000, 6000), 16, 8));
        $validator = Validator::make(['image' => $image], ['image' => [new MaxImagePixels]]);
        $this->assertTrue($validator->fails());
        foreach ([
            StoreCategoryRequest::class => 'image',
            UpdateCategoryRequest::class => 'image',
            StoreBrandRequest::class => 'logo',
            UpdateBrandRequest::class => 'logo',
        ] as $request => $field) {
            $route = new Route('PUT', 'catalog/{category}/{brand}', []);
            $route->bind(Request::create('/catalog/1/1', 'PUT'));
            $route->setParameter('category', (object) ['id' => 1]);
            $route->setParameter('brand', (object) ['id' => 1]);
            $form = new $request;
            $form->setRouteResolver(fn () => $route);
            $this->assertNotEmpty(array_filter($form->rules()[$field], fn ($rule) => $rule instanceof MaxImagePixels));
        }
    }
}
