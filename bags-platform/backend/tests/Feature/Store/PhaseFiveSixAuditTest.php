<?php

namespace Tests\Feature\Store;

use App\Enums\CustomerStatus;
use App\Enums\ProductStatus;
use App\Exceptions\BusinessException;
use App\Models\Banner;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Location;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\User;
use App\Notifications\CustomerPasswordReset;
use App\Services\CustomerAuthService;
use App\Services\StoreCatalogService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PhaseFiveSixAuditTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function product(array $attributes = [], array $variant = []): Product
    {
        $product = Product::factory()->create(['status' => ProductStatus::Published, ...$attributes]);
        ProductVariant::factory()->create(['product_id' => $product->id, 'retail_price' => 100, ...$variant]);

        return $product;
    }

    private function staff(string|array $permission): string
    {
        foreach ((array) $permission as $ability) {
            Permission::findOrCreate($ability, 'staff');
        }
        $user = User::factory()->create();
        $user->givePermissionTo($permission);

        return $user->createToken('test')->plainTextToken;
    }

    public function test_price_range_matches_one_variant_and_maximum_works_alone(): void
    {
        $outside = $this->product([], ['retail_price' => 50]);
        ProductVariant::factory()->create(['product_id' => $outside->id, 'retail_price' => 500]);
        $inside = $this->product([], ['retail_price' => 150]);

        $this->getJson('/api/v1/store/products?price_min=100&price_max=200')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $inside->id);
        $this->getJson('/api/v1/store/products?price_max=75')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $outside->id);
        $this->getJson('/api/v1/store/products?price_min=200&price_max=100')->assertUnprocessable();
    }

    public function test_retired_brand_filter_rejects_scalar_and_nested_values(): void
    {
        $product = $this->product();

        $this->getJson('/api/v1/store/products?brand='.$product->brand_id)
            ->assertUnprocessable()->assertJsonValidationErrors('brand');
        $this->getJson('/api/v1/store/products?brand[0][unexpected]=1')
            ->assertUnprocessable()->assertJsonValidationErrors('brand');
        $this->getJson('/api/v1/store/products')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $product->id);
    }

    public function test_store_and_wholesale_registration_have_distinct_named_routes(): void
    {
        $this->assertSame('/api/v1/store/auth/register', route('store.auth.register', [], false));
        $this->assertSame('/api/v1/wholesale/auth/register', route('wholesale.auth.register', [], false));
        $routes = collect(app('router')->getRoutes())->filter(fn ($route) => $route->getName() !== null);
        $names = $routes->map(fn ($route) => $route->getName());
        $this->assertCount($names->count(), $names->unique());
    }

    public function test_stock_excludes_inactive_and_deleted_variants(): void
    {
        $product = $this->product();
        $inactive = ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => false]);
        $deleted = ProductVariant::factory()->create(['product_id' => $product->id]);
        $branch = Branch::factory()->create();
        $location = Location::factory()->create(['branch_id' => $branch->id]);
        foreach ([$inactive, $deleted] as $variant) {
            StockLevel::forceCreate(['product_variant_id' => $variant->id, 'branch_id' => $branch->id,
                'location_id' => $location->id, 'on_hand' => 9]);
        }
        $deleted->delete();

        $this->getJson('/api/v1/store/products?in_stock=1')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/store/products/'.$product->slug)
            ->assertOk()->assertJsonPath('data.in_stock', false)->assertJsonMissingPath('data.store_available');
    }

    public function test_reserved_and_damaged_are_subtracted_across_active_branches(): void
    {
        $product = $this->product();
        foreach ([true, true, false] as $active) {
            $branch = Branch::factory()->create(['is_active' => $active]);
            $location = Location::factory()->create(['branch_id' => $branch->id]);
            StockLevel::forceCreate(['product_variant_id' => $product->variants->first()->id,
                'branch_id' => $branch->id, 'location_id' => $location->id,
                'on_hand' => 10, 'reserved' => 3, 'damaged' => 2]);
        }

        $this->getJson('/api/v1/store/products/'.$product->slug)->assertOk()
            ->assertJsonPath('data.in_stock', true)->assertJsonPath('data.variants.0.in_stock', true)
            ->assertJsonMissingPath('data.store_available')->assertJsonMissingPath('data.variants.0.available');
        $this->assertSame(10.0, app(StoreCatalogService::class)->availabilityMap([$product->variants->first()->id])[$product->variants->first()->id]);
    }

    public function test_card_uses_cheapest_resolved_price(): void
    {
        $product = $this->product();
        $second = ProductVariant::factory()->create(['product_id' => $product->id, 'retail_price' => 200]);
        $list = PriceList::create(['name' => 'Sale', 'type' => 'retail']);
        $list->items()->create(['product_variant_id' => $second->id, 'price' => 40, 'min_qty' => 1]);

        $this->getJson('/api/v1/store/products')->assertOk()->assertJsonPath('data.0.price', 40);
    }

    public function test_discounted_home_excludes_private_expired_future_and_bulk_prices(): void
    {
        $this->freezeTime();
        $eligible = $this->product();
        $retail = PriceList::create(['name' => 'Public', 'type' => 'retail']);
        $retail->items()->create(['product_variant_id' => $eligible->variants->first()->id, 'price' => 80, 'min_qty' => 1]);
        foreach ([['ends_at' => now()->subSecond()], ['starts_at' => now()->addDay()], ['min_qty' => 10]] as $overrides) {
            $product = $this->product();
            $retail->items()->create(['product_variant_id' => $product->variants->first()->id,
                'price' => 80, 'min_qty' => 1, ...$overrides]);
        }
        $privateProduct = $this->product();
        $private = PriceList::create(['name' => 'Private', 'type' => 'customer_specific', 'customer_id' => Customer::factory()->create()->id]);
        $private->items()->create(['product_variant_id' => $privateProduct->variants->first()->id, 'price' => 70, 'min_qty' => 1]);

        $this->getJson('/api/v1/store/home')->assertOk()->assertJsonCount(1, 'data.discounted_products')
            ->assertJsonPath('data.discounted_products.0.id', $eligible->id);
    }

    public function test_cached_home_does_not_keep_expired_or_disabled_banners_visible(): void
    {
        $this->freezeTime();
        Banner::factory()->create(['ends_at' => now()->addMinute()]);
        $disabled = Banner::factory()->create();
        $this->getJson('/api/v1/store/home')->assertOk()->assertJsonCount(2, 'data.banners');
        $disabled->update(['is_active' => false]);
        $this->travel(2)->minutes();

        $this->getJson('/api/v1/store/home')->assertOk()->assertJsonCount(0, 'data.banners');
    }

    public function test_similar_does_not_treat_null_brands_as_a_match(): void
    {
        $product = $this->product(['brand_id' => null]);
        $same = $this->product(['brand_id' => null, 'category_id' => $product->category_id]);
        $this->product(['brand_id' => null]);

        $this->getJson('/api/v1/store/products/'.$product->slug.'/similar')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $same->id);
    }

    public function test_suspended_status_tokens_are_rejected_and_rejected_customers_can_logout(): void
    {
        $customer = Customer::factory()->wholesale()->create(['status' => CustomerStatus::Suspended]);
        $this->withToken($customer->createToken('test')->plainTextToken)
            ->getJson('/api/v1/wholesale/auth/application-status')->assertForbidden();
        $customer->update(['status' => CustomerStatus::Rejected]);
        Auth::forgetGuards();
        $this->withToken($customer->createToken('test')->plainTextToken)->postJson('/api/v1/store/auth/logout')->assertOk();
        $this->assertSame(1, $customer->tokens()->count());
    }

    public function test_customer_editor_cannot_bypass_approval_by_crud(): void
    {
        $token = $this->staff('customers.update');
        $customer = Customer::factory()->wholesale()->create();
        $customer->createToken('wholesale-token');

        $this->withToken($token)->putJson('/api/v1/admin/customers/'.$customer->id, ['status' => 'approved'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame(CustomerStatus::Pending, $customer->refresh()->status);
        $this->assertSame(1, $customer->tokens()->count());
    }

    public function test_document_review_is_scoped_and_requires_approval_permission(): void
    {
        $token = $this->staff(['customers.approve', 'customers.documents.view']);
        $customer = Customer::factory()->wholesale()->create();
        $document = $customer->documents()->create(['document_type' => 'tax_card', 'file_path' => 'private.pdf',
            'original_name' => 'tax.pdf', 'mime_type' => 'application/pdf']);
        $other = Customer::factory()->wholesale()->create();
        $this->withToken($token)->patchJson("/api/v1/admin/customers/{$other->id}/documents/{$document->id}", ['status' => 'rejected'])->assertNotFound();
        Auth::forgetGuards();
        $this->withToken($token)->patchJson("/api/v1/admin/customers/{$customer->id}/documents/{$document->id}", ['status' => 'rejected'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertDatabaseHas('customer_documents', ['id' => $document->id, 'status' => 'rejected']);
        $customer->update(['status' => CustomerStatus::Rejected]);
        Auth::forgetGuards();
        $this->withToken($customer->createToken('test')->plainTextToken)->getJson('/api/v1/wholesale/auth/application-status')
            ->assertOk()->assertJsonPath('data.rejected_documents.0.id', $document->id);
        Auth::forgetGuards();
        $this->withToken($this->staff('customers.view'))->patchJson("/api/v1/admin/customers/{$customer->id}/documents/{$document->id}", ['status' => 'verified'])->assertForbidden();
    }

    public function test_phone_only_reset_delivers_the_hashed_token_using_sms(): void
    {
        config(['services.customer_sms.url' => 'https://sms.example.test/send', 'services.customer_sms.token' => 'secret']);
        Http::preventStrayRequests();
        Http::fake(['https://sms.example.test/send' => Http::response(['success' => true], 200)]);
        $customer = Customer::factory()->create(['email' => null]);

        $this->postJson('/api/v1/store/auth/forgot-password', ['phone' => $customer->phone])->assertOk();

        $row = DB::table('password_reset_tokens')->where('email', 'customer:'.$customer->id)->first();
        Http::assertSent(fn (Request $request) => $request['to'] === $customer->phone
            && hash_equals($row->token, hash('sha256', str_replace('رمز استعادة كلمة المرور: ', '', $request['message']))));
    }

    public function test_customer_migration_handles_legacy_blocked_accounts_and_nullable_email_rollback(): void
    {
        $identityMigration = require database_path('migrations/2026_10_16_115322_normalize_customer_identity.php');
        $identityMigration->down();
        $migration = require database_path('migrations/2026_10_16_000001_complete_customers_structure.php');
        $migration->down();
        $id = DB::table('customers')->insertGetId(['name' => 'Legacy', 'email' => 'legacy@example.test',
            'phone' => '01000001234', 'password' => Hash::make('password'), 'type' => 'retail', 'status' => 'blocked']);

        $migration->up();

        $this->assertDatabaseHas('customers', ['id' => $id, 'status' => 'suspended']);
        DB::table('customers')->where('id', $id)->update(['email' => null]);
        $migration->down();
        $this->assertDatabaseHas('customers', ['id' => $id, 'status' => 'blocked']);
        $migration->up();
        $identityMigration->up();
    }

    public function test_active_flag_tracks_publication_and_wholesale_defaults_to_pending(): void
    {
        $product = Product::factory()->create(['status' => ProductStatus::Draft]);
        $this->assertFalse($product->refresh()->is_active);
        $product->update(['status' => ProductStatus::Published]);
        $this->assertTrue($product->refresh()->is_active);
        $customer = Customer::create(['name' => 'Wholesale', 'phone' => '01000123456', 'password' => 'password', 'type' => 'wholesale']);
        $this->assertSame(CustomerStatus::Pending, $customer->refresh()->status);
    }

    public function test_token_failure_rolls_back_wholesale_registration_and_uploaded_files(): void
    {
        $this->fakeUploads();
        $this->partialMock(CustomerAuthService::class, function ($mock): void {
            $mock->shouldReceive('issueToken')->once()->andThrow(new \RuntimeException('Token failure'));
        });

        $this->postJson('/api/v1/wholesale/auth/register', [
            'name' => 'Wholesale', 'phone' => '01000987654', 'password' => 'secure-password123',
            'company_name' => 'Company', 'tax_number' => '123', 'commercial_register' => '456',
            'documents' => [UploadedFile::fake()->create('register.pdf', 20, 'application/pdf')],
        ])->assertServerError();

        $this->assertDatabaseMissing('customers', ['phone' => '01000987654']);
        $this->assertDatabaseCount('customer_documents', 0);
        $this->assertSame([], Storage::disk('r2')->allFiles());
    }

    public function test_failed_file_write_does_not_create_customer_or_document_records(): void
    {
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFileAs')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('r2')->andReturn($disk);

        $this->postJson('/api/v1/wholesale/auth/register', [
            'name' => 'Wholesale', 'phone' => '01000987654', 'password' => 'secure-password123',
            'company_name' => 'Company', 'tax_number' => '123', 'commercial_register' => '456',
            'documents' => [UploadedFile::fake()->create('register.pdf', 20, 'application/pdf')],
        ])->assertServerError();

        $this->assertDatabaseMissing('customers', ['phone' => '01000987654']);
        $this->assertDatabaseCount('customer_documents', 0);
    }

    public function test_home_cache_keeps_customer_specific_prices_separate_from_guest_prices(): void
    {
        $product = $this->product();
        $customer = Customer::factory()->create();
        $list = PriceList::create(['name' => 'Private', 'type' => 'customer_specific', 'customer_id' => $customer->id]);
        $list->items()->create(['product_variant_id' => $product->variants->first()->id, 'price' => 60, 'min_qty' => 1]);
        $token = $customer->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/store/home')->assertOk()->assertJsonPath('data.latest_products.0.price', 60);
        Auth::forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/store/home')
            ->assertOk()->assertJsonPath('data.latest_products.0.price', 100);
    }

    public function test_admin_can_edit_compare_at_price_for_store_discounts(): void
    {
        $token = $this->staff('products.update');
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $this->withToken($token)->putJson("/api/v1/admin/products/{$product->id}/variants/{$variant->id}", ['compare_at_price' => 300])
            ->assertOk()->assertJsonPath('data.compare_at_price', '300.00');

        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'compare_at_price' => 300]);
    }

    public function test_password_reset_is_rate_limited_even_for_unknown_accounts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/store/auth/forgot-password', ['email' => 'unknown@example.test'])->assertOk();
        }

        $this->postJson('/api/v1/store/auth/forgot-password', ['email' => 'unknown@example.test'])->assertTooManyRequests();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_customer_type_cannot_be_promoted_without_the_approval_action(): void
    {
        $editor = $this->staff('customers.update');
        $customer = Customer::factory()->create();

        $this->withToken($editor)->putJson('/api/v1/admin/customers/'.$customer->id, ['type' => 'distributor'])
            ->assertUnprocessable();
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'type' => 'retail']);
        Auth::forgetGuards();
        $approver = $this->staff('customers.approve');
        $customer->documents()->create(['document_type' => 'commercial_register', 'file_path' => 'verified.pdf', 'original_name' => 'verified.pdf', 'mime_type' => 'application/pdf', 'status' => 'verified']);
        $this->withToken($approver)->postJson('/api/v1/admin/customers/'.$customer->id.'/approve', [
            'type' => 'distributor', 'company_name' => 'Company', 'tax_number' => '123', 'commercial_register' => '456',
        ])
            ->assertOk()->assertJsonPath('data.type', 'distributor');
        $this->assertNotNull($customer->refresh()->approved_by);
        $this->assertNotNull($customer->approved_at);
    }

    public function test_reset_tokens_do_not_collide_with_staff_using_the_same_email(): void
    {
        Notification::fake();
        $customer = Customer::factory()->create(['email' => 'shared@example.test']);
        DB::table('password_reset_tokens')->insert(['email' => $customer->email, 'token' => 'existing-staff-token', 'created_at' => now()]);

        $this->postJson('/api/v1/store/auth/forgot-password', ['email' => $customer->email])->assertOk();

        $this->assertDatabaseHas('password_reset_tokens', ['email' => $customer->email, 'token' => 'existing-staff-token']);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'customer:'.$customer->id]);
        Notification::assertSentTo($customer, CustomerPasswordReset::class);
    }

    public function test_missing_sms_configuration_does_not_expose_whether_a_phone_is_registered(): void
    {
        config(['services.customer_sms.url' => null, 'services.customer_sms.token' => null]);
        Exceptions::fake();
        $customer = Customer::factory()->create(['email' => null]);

        $known = $this->postJson('/api/v1/store/auth/forgot-password', ['phone' => $customer->phone])->assertOk();
        $unknown = $this->postJson('/api/v1/store/auth/forgot-password', ['phone' => '01099999999'])->assertOk();

        $this->assertSame($known->json(), $unknown->json());
        Exceptions::assertReported(BusinessException::class);
    }
}
