<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Catalog;

use App\Http\Requests\Admin\Brand\StoreBrandRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;
use PDOException;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CatalogHardeningTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ALL_PERMISSIONS = [
        'categories.view', 'categories.create', 'categories.update', 'categories.delete',
        'brands.view', 'brands.create', 'brands.update', 'brands.delete',
        'products.view', 'products.create', 'products.update', 'products.delete',
        'products.publish', 'products.view_cost',
    ];

    // ---------- H1: قواعد النشر بتتفحص بعد أي تعديل على منتج منشور ----------

    public function test_clearing_price_of_a_published_product_variant_is_rejected_and_rolled_back(): void
    {
        $token = $this->adminToken();
        [$productId, $variantId] = $this->publishedProduct($token);

        $this->requestWithToken($token, 'PUT', "/api/v1/admin/products/{$productId}/variants/{$variantId}", [
            'retail_price' => null,
        ])->assertUnprocessable();

        $this->assertSame('100.00', (string) ProductVariant::find($variantId)->retail_price);
    }

    public function test_valid_price_update_on_published_product_still_works(): void
    {
        $token = $this->adminToken();
        [$productId, $variantId] = $this->publishedProduct($token);

        $this->requestWithToken($token, 'PUT', "/api/v1/admin/products/{$productId}/variants/{$variantId}", [
            'retail_price' => 150,
        ])->assertOk()
            ->assertJsonPath('data.retail_price', '150.00');
    }

    public function test_switching_published_product_to_both_without_wholesale_prices_is_rejected(): void
    {
        $token = $this->adminToken();
        [$productId] = $this->publishedProduct($token);

        $this->requestWithToken($token, 'PUT', "/api/v1/admin/products/{$productId}", [
            'sales_channel' => 'both',
        ])->assertUnprocessable();

        $this->assertSame('retail', Product::find($productId)->sales_channel->value);
    }

    public function test_moving_published_product_to_inactive_category_is_rejected(): void
    {
        $token = $this->adminToken();
        [$productId] = $this->publishedProduct($token);
        $inactive = Category::factory()->create(['is_active' => false]);

        $this->requestWithToken($token, 'PUT', "/api/v1/admin/products/{$productId}", [
            'category_id' => $inactive->id,
        ])->assertUnprocessable();
    }

    public function test_adding_unpriced_active_variant_to_published_product_is_rejected(): void
    {
        $token = $this->adminToken();
        [$productId] = $this->publishedProduct($token);

        $this->requestWithToken($token, 'POST', "/api/v1/admin/products/{$productId}/variants", [
            'color_name_en' => 'Green',
        ])->assertUnprocessable();

        $this->assertSame(1, Product::find($productId)->variants()->count());
    }

    public function test_deactivating_a_category_with_published_products_is_rejected(): void
    {
        $token = $this->adminToken();
        [, , $categoryId] = $this->publishedProduct($token);

        $this->requestWithToken($token, 'PUT', "/api/v1/admin/categories/{$categoryId}", [
            'is_active' => false,
        ])->assertUnprocessable();

        $this->assertTrue(Category::find($categoryId)->is_active);
    }

    // ---------- H2: فهارس العلاقات موجودة ----------

    public function test_catalog_lookup_indexes_exist(): void
    {
        $productIndexes = collect(Schema::getIndexes('products'))->pluck('name');
        $variantIndexes = collect(Schema::getIndexes('product_variants'))->pluck('name');

        $this->assertTrue($productIndexes->contains('products_category_id_index'));
        $this->assertTrue($productIndexes->contains('products_brand_id_index'));
        $this->assertTrue($productIndexes->contains('products_created_by_index'));
        $this->assertTrue($productIndexes->contains('products_created_at_index'));
        $this->assertTrue($variantIndexes->contains('product_variants_product_active_index'));
        $this->assertTrue($variantIndexes->contains('product_variants_product_retail_index'));
    }

    // ---------- H3: SVG مرفوض كشعار ----------

    public function test_svg_brand_logo_is_rejected(): void
    {
        $this->fakeUploads();
        $token = $this->adminToken();

        $rules = (new StoreBrandRequest)->rules();
        $validator = Validator::make([
            'logo' => UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml'),
        ], ['logo' => $rules['logo']]);
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('logo', $validator->errors()->toArray());
    }

    // ---------- H4: حماية من الدورات في شجرة الأقسام ----------

    public function test_descendant_ids_terminates_on_a_cycle(): void
    {
        $a = Category::factory()->create();
        $b = Category::factory()->create(['parent_id' => $a->id]);
        DB::table('categories')->where('id', $a->id)->update(['parent_id' => $b->id]); // دورة A<->B

        $this->assertSame([$b->id], $a->fresh()->descendantIds());
    }

    public function test_depth_throws_on_a_cycle_instead_of_looping(): void
    {
        $a = Category::factory()->create();
        $b = Category::factory()->create(['parent_id' => $a->id]);
        DB::table('categories')->where('id', $a->id)->update(['parent_id' => $b->id]);

        $this->expectException(RuntimeException::class);
        $a->fresh()->depth();
    }

    // ---------- M1: حدود مطابقة للأعمدة ----------

    public function test_product_slug_longer_than_the_column_is_a_validation_error(): void
    {
        $token = $this->adminToken();
        $category = Category::factory()->create();

        $this->requestWithToken($token, 'POST', '/api/v1/admin/products', [
            'category_id' => $category->id,
            'name_ar' => 'منتج',
            'name_en' => 'Slugged',
            'slug' => str_repeat('a', 256),
            'sales_channel' => 'retail',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);
    }

    public function test_oversized_weight_is_a_validation_error(): void
    {
        $token = $this->adminToken();
        $category = Category::factory()->create();

        $this->requestWithToken($token, 'POST', '/api/v1/admin/products', [
            'category_id' => $category->id,
            'name_ar' => 'منتج',
            'name_en' => 'Heavy',
            'sales_channel' => 'retail',
            'weight_kg' => 100000,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['weight_kg']);
    }

    public function test_generated_slugs_stay_within_the_column_even_with_suffixes(): void
    {
        $token = $this->adminToken();
        $category = Category::factory()->create();
        $longName = str_repeat('a', 250);

        foreach ([1, 2] as $i) {
            $response = $this->requestWithToken($token, 'POST', '/api/v1/admin/products', [
                'category_id' => $category->id,
                'name_ar' => 'منتج '.$i,
                'name_en' => $longName,
                'sales_channel' => 'retail',
            ])->assertCreated();
            $this->assertLessThanOrEqual(255, strlen((string) $response->json('data.slug')));
        }
    }

    // ---------- M2: سباق الـ unique يرجع 409 ----------

    public function test_unique_constraint_violation_renders_as_409(): void
    {
        $request = Request::create('/api/v1/admin/products', 'POST');
        $request->headers->set('Accept', 'application/json');

        $exception = new UniqueConstraintViolationException(
            'pgsql', 'insert into products ...', [], new PDOException('duplicate key'),
        );
        $response = app(ExceptionHandler::class)->render($request, $exception);

        $this->assertSame(409, $response->getStatusCode());
    }

    // ---------- M3: صيغة الباركود الداخلي محجوزة ----------

    public function test_manual_barcode_in_the_internal_format_is_rejected(): void
    {
        $token = $this->adminToken();
        $product = Product::factory()->create();

        $this->requestWithToken($token, 'POST', "/api/v1/admin/products/{$product->id}/variants", [
            'color_name_en' => 'Red',
            'barcode' => '2000000000015',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['barcode']);
    }

    public function test_external_barcode_and_resending_own_generated_barcode_are_allowed(): void
    {
        $token = $this->adminToken();
        $product = Product::factory()->create();

        $this->requestWithToken($token, 'POST', "/api/v1/admin/products/{$product->id}/variants", [
            'color_name_en' => 'Red',
            'barcode' => 'EXT-987',
        ])->assertCreated();

        $created = $this->requestWithToken($token, 'POST', "/api/v1/admin/products/{$product->id}/variants", [
            'color_name_en' => 'Blue',
        ])->assertCreated();
        $variantId = $created->json('data.id');
        $generated = (string) $created->json('data.barcode');
        $this->assertMatchesRegularExpression('/^2\d{12}$/', $generated);

        // إعادة إرسال نفس الباركود المولّد للمتغير نفسه (فورم كامل) مسموحة
        $this->requestWithToken($token, 'PUT', "/api/v1/admin/products/{$product->id}/variants/{$variantId}", [
            'barcode' => $generated,
        ])->assertOk();

        // لكن باركود داخلي الصيغة بتاع متغير تاني مرفوض
        $this->requestWithToken($token, 'PUT', "/api/v1/admin/products/{$product->id}/variants/{$variantId}", [
            'barcode' => '2000000000099',
        ])->assertUnprocessable();
    }

    // ---------- helpers ----------

    /** @return array{0: int, 1: int, 2: int} product id, variant id, category id */
    private function publishedProduct(string $token): array
    {
        $this->fakeUploads();
        $category = Category::factory()->create();

        $created = $this->requestWithToken($token, 'POST', '/api/v1/admin/products', [
            'category_id' => $category->id,
            'name_ar' => 'منتج منشور',
            'name_en' => 'Published Product '.uniqid(),
            'sales_channel' => 'retail',
            'variants' => [['color_name_en' => 'Red', 'retail_price' => 100]],
        ])->assertCreated();
        $productId = $created->json('data.id');
        $variantId = $created->json('data.variants.0.id');

        Auth::forgetGuards();
        $this->withToken($token)->post("/api/v1/admin/products/{$productId}/media", [
            'collection' => 'images',
            'files' => [UploadedFile::fake()->image('p.jpg')],
        ])->assertCreated();

        $this->requestWithToken($token, 'PUT', "/api/v1/admin/products/{$productId}", [
            'status' => 'published',
        ])->assertOk();

        return [$productId, $variantId, $category->id];
    }

    private function adminToken(): string
    {
        foreach (self::ALL_PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'staff');
        }
        $staff = User::factory()->create();
        $staff->givePermissionTo(self::ALL_PERMISSIONS);

        return $staff->createToken('test')->plainTextToken;
    }

    private function requestWithToken(string $token, string $method, string $uri, array $payload = []): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->json($method, $uri, $payload);
    }
}
