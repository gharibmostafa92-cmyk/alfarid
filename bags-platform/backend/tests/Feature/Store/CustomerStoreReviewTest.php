<?php

namespace Tests\Feature\Store;

use App\Enums\CustomerType;
use App\Enums\ProductStatus;
use App\Enums\SalesChannel;
use App\Jobs\SendCustomerPasswordReset;
use App\Models\Banner;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Notifications\Channels\CustomerSmsChannel;
use App\Notifications\CustomerPasswordReset;
use App\Services\CustomerAuthService;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CustomerStoreReviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function product(array $attributes = [], array $variant = []): Product
    {
        $product = Product::factory()->create(['status' => ProductStatus::Published, ...$attributes]);
        ProductVariant::factory()->create(['product_id' => $product->id, 'retail_price' => 100, ...$variant]);

        return $product;
    }

    private function staff(array $permissions): string
    {
        $user = User::factory()->create();
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'staff'));
        }

        return $user->createToken('test')->plainTextToken;
    }

    private function sale(Product $product, string $type = 'retail', float $price = 40, array $owner = [], array $item = []): PriceList
    {
        $list = PriceList::create(['name' => 'Test', 'type' => $type, ...$owner]);
        $list->items()->create(['product_variant_id' => $product->variants->first()->id, 'price' => $price, 'min_qty' => 1, ...$item]);

        return $list;
    }

    public static function unapprovedStatuses(): array
    {
        return [['pending'], ['rejected'], ['suspended']];
    }

    #[DataProvider('unapprovedStatuses')]
    public function test_unapproved_tokens_only_receive_retail_prices(string $status): void
    {
        $product = $this->product();
        $customer = Customer::factory()->wholesale()->create(['status' => $status]);
        $this->sale($product, 'wholesale', 20);
        $this->sale($product, 'customer_specific', 10, ['customer_id' => $customer->id]);

        $this->withToken($customer->createToken('test')->plainTextToken)
            ->getJson('/api/v1/store/products/'.$product->slug)->assertOk()
            ->assertJsonPath('data.price', 100)->assertJsonPath('data.variants.0.price', 100);
    }

    public function test_every_store_product_endpoint_hides_wholesale_and_inactive_catalogs(): void
    {
        $valid = $this->product();
        $wholesale = $this->product(['sales_channel' => SalesChannel::Wholesale]);
        $inactiveCategory = Category::factory()->create(['is_active' => false]);
        $child = Category::factory()->create(['parent_id' => $inactiveCategory->id]);
        $hidden = $this->product(['category_id' => $child->id]);
        $brand = Brand::factory()->create(['is_active' => false]);
        $historicalBrandProduct = $this->product(['brand_id' => $brand->id]);

        $response = $this->getJson('/api/v1/store/products')->assertOk()->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing([$valid->id, $historicalBrandProduct->id], $response->json('data.*.id'));
        foreach ([$wholesale, $hidden] as $product) {
            $this->getJson('/api/v1/store/products/'.$product->slug)->assertNotFound();
            $this->getJson('/api/v1/store/products/'.$product->slug.'/similar')->assertNotFound();
        }
        $this->getJson('/api/v1/store/products/'.$historicalBrandProduct->slug)->assertOk()->assertJsonPath('data.id', $historicalBrandProduct->id);
        $this->getJson('/api/v1/store/products/'.$historicalBrandProduct->slug.'/similar')->assertOk();
        $home = $this->getJson('/api/v1/store/home')->assertOk()->assertJsonCount(2, 'data.latest_products');
        $this->assertEqualsCanonicalizing([$valid->id, $historicalBrandProduct->id], $home->json('data.latest_products.*.id'));
        $this->getJson('/api/v1/store/products?category='.$inactiveCategory->slug)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/store/products?brand='.$brand->slug)->assertUnprocessable()->assertJsonValidationErrors('brand');
    }

    public function test_price_filters_and_sort_use_actual_customer_prices_and_put_nulls_last(): void
    {
        $sale = $this->product([], ['retail_price' => 200]);
        $this->sale($sale);
        $regular = $this->product([], ['retail_price' => 80]);
        $unpriced = $this->product([], ['retail_price' => null]);

        $this->getJson('/api/v1/store/products?price_max=50')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sale->id);
        $this->getJson('/api/v1/store/products?sort=price_asc')->assertOk()
            ->assertJsonPath('data.0.id', $sale->id)->assertJsonPath('data.1.id', $regular->id)->assertJsonPath('data.2.id', $unpriced->id);
        $this->getJson('/api/v1/store/products?sort=price_desc')->assertOk()
            ->assertJsonPath('data.0.id', $regular->id)->assertJsonPath('data.2.id', $unpriced->id);
        $customer = Customer::factory()->create();
        $this->sale($regular, 'customer_specific', 15, ['customer_id' => $customer->id]);
        Auth::forgetGuards();
        $this->withToken($customer->createToken('test')->plainTextToken)
            ->getJson('/api/v1/store/products?sort=price_asc&price_max=20')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $regular->id)->assertJsonPath('data.0.price', 15);
    }

    public function test_sql_prices_match_pricing_service_owner_tier_fallback_and_group_rules(): void
    {
        $product = $this->product();
        $variant = $product->variants->first();
        $group = CustomerGroup::create(['name' => 'VIP', 'code' => 'review-vip', 'discount_percentage' => 15]);
        $customer = Customer::factory()->wholesale()->create(['status' => 'approved', 'customer_group_id' => $group->id]);
        $this->sale($product, 'retail', 80);
        $pricing = app(PricingService::class);
        $assertPrice = function () use ($pricing, $variant, $customer): void {
            $expected = $pricing->resolve($variant, $customer, 1)['unit_price'];
            $actual = $pricing->withStorePrice(ProductVariant::query(), $customer)->whereKey($variant->id)->firstOrFail()->store_price;
            $this->assertSame($expected, (float) $actual);
        };
        $assertPrice();
        $this->sale($product, 'wholesale', 60);
        $assertPrice();
        $this->sale($product, 'customer_group', 50, ['customer_group_id' => $group->id]);
        $assertPrice();
        $this->sale($product, 'customer_specific', 30, ['customer_id' => $customer->id]);
        $this->sale($product, 'customer_specific', 1, ['customer_id' => $customer->id], ['min_qty' => 10]);
        $assertPrice();
    }

    public function test_old_price_is_returned_for_list_and_group_discounts(): void
    {
        $product = $this->product();
        $this->sale($product, 'retail', 80);

        $this->getJson('/api/v1/store/products/'.$product->slug)->assertOk()
            ->assertJsonPath('data.price', 80)->assertJsonPath('data.old_price', 100)
            ->assertJsonPath('data.discount_percentage', 20)->assertJsonPath('data.variants.0.old_price', 100);
    }

    public function test_expired_unpriced_and_wrong_variant_discounts_are_hidden_from_cached_home(): void
    {
        $this->freezeTime();
        $expiring = $this->product();
        $this->sale($expiring, 'retail', 40, ['ends_at' => now()->addMinute()]);
        $this->product([], ['retail_price' => null, 'compare_at_price' => 200]);
        $wrongVariant = $this->product();
        ProductVariant::factory()->create(['product_id' => $wrongVariant->id, 'retail_price' => 200, 'compare_at_price' => 300]);
        $this->getJson('/api/v1/store/home')->assertOk()->assertJsonCount(1, 'data.discounted_products');
        $this->travel(2)->minutes();

        $this->getJson('/api/v1/store/home')->assertOk()->assertJsonCount(0, 'data.discounted_products');
    }

    public function test_read_query_count_does_not_grow_per_product_variant_and_retired_brand_filters_do_not_query_brands(): void
    {
        $this->product();
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        $this->getJson('/api/v1/store/products')->assertOk();
        $baseline = count($queries);
        for ($i = 0; $i < 7; $i++) {
            $product = $this->product();
            ProductVariant::factory()->count(3)->create(['product_id' => $product->id]);
        }
        $queries = [];

        $this->getJson('/api/v1/store/products')->assertOk()->assertJsonCount(8, 'data');
        $this->assertLessThanOrEqual($baseline, count($queries));
        $brands = Brand::factory()->count(25)->create()->pluck('slug')->all();
        $queries = [];
        $this->getJson('/api/v1/store/products?'.http_build_query(['brand' => $brands]))->assertUnprocessable()->assertJsonValidationErrors('brand');
        $brandQueries = array_filter($queries, fn ($sql) => str_starts_with($sql, 'select "id" from "brands"'));
        $this->assertCount(0, $brandQueries);
    }

    public function test_retired_brands_do_not_load_media_and_category_media_queries_do_not_grow_per_record(): void
    {
        Brand::factory()->create();
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        $this->getJson('/api/v1/store/brands')->assertStatus(410);
        $this->assertCount(0, $queries);
        Brand::factory()->count(15)->create();
        $queries = [];
        $this->getJson('/api/v1/store/brands')->assertStatus(410);
        $this->assertCount(0, $queries);
        Category::factory()->create();
        $queries = [];
        $this->getJson('/api/v1/store/categories')->assertOk();
        $baseline = count($queries);
        Category::factory()->count(15)->create();
        $queries = [];
        $this->getJson('/api/v1/store/categories')->assertOk();
        $this->assertSame($baseline, count($queries));
        $mediaQueries = array_filter($queries, fn ($sql) => str_contains($sql, 'from "media"'));
        $this->assertCount(1, $mediaQueries);
    }

    public static function nonPendingStatuses(): array
    {
        return [['approved'], ['suspended'], ['rejected']];
    }

    #[DataProvider('nonPendingStatuses')]
    public function test_rejection_cannot_unblock_or_revoke_an_existing_approved_account(string $status): void
    {
        $customer = Customer::factory()->wholesale()->create(['status' => $status]);
        $token = $this->staff(['customers.approve']);

        $this->withToken($token)->postJson('/api/v1/admin/customers/'.$customer->id.'/reject', ['reason' => 'Invalid transition'])
            ->assertUnprocessable();
        $this->assertSame($status, $customer->refresh()->status->value);
    }

    public function test_wholesale_promotion_requires_legal_details(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->staff(['customers.approve']);

        $this->withToken($token)->postJson('/api/v1/admin/customers/'.$customer->id.'/approve', ['type' => 'distributor'])->assertUnprocessable();
        $this->assertSame(CustomerType::Retail, $customer->refresh()->type);
    }

    public function test_customer_editors_cannot_assign_financial_terms_or_change_pending_type(): void
    {
        $customer = Customer::factory()->wholesale()->create();
        $token = $this->staff(['customers.create', 'customers.update']);
        foreach ([['credit_limit' => 1000000], ['customer_group_id' => null]] as $changes) {
            Auth::forgetGuards();
            $this->withToken($token)->putJson('/api/v1/admin/customers/'.$customer->id, $changes)->assertForbidden();
            Auth::forgetGuards();
            $this->withToken($token)->postJson('/api/v1/admin/customers', ['name' => 'X', 'phone' => '01000000000', 'password' => 'password', ...$changes])->assertForbidden();
        }
        Auth::forgetGuards();
        $this->withToken($token)->putJson('/api/v1/admin/customers/'.$customer->id, ['type' => 'retail'])->assertUnprocessable();
        $this->assertSame(CustomerType::Wholesale, $customer->refresh()->type);
    }

    public function test_email_is_normalized_and_duplicate_contact_addresses_are_rejected(): void
    {
        $this->postJson('/api/v1/store/auth/register', ['name' => 'X', 'email' => ' User@Example.Test ', 'phone' => ' 01000000000 ', 'password' => 'password'])
            ->assertCreated()->assertJsonPath('data.email', 'user@example.test')->assertJsonPath('data.phone', '01000000000');
        $this->postJson('/api/v1/store/auth/login', ['login' => 'USER@EXAMPLE.TEST', 'password' => 'password'])->assertUnauthorized();
        $this->postJson('/api/v1/store/auth/register', ['name' => 'Y', 'email' => 'USER@example.test', 'phone' => '01000000001', 'password' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_deleted_customers_can_be_found_and_restored_without_losing_status_or_history(): void
    {
        $customer = Customer::factory()->wholesale()->create(['status' => 'suspended']);
        $customer->delete();
        $token = $this->staff(['customers.view', 'customers.delete']);

        $this->withToken($token)->getJson('/api/v1/admin/customers?trashed=only')->assertOk()->assertJsonPath('data.0.id', $customer->id);
        Auth::forgetGuards();
        $this->withToken($token)->postJson('/api/v1/admin/customers/'.$customer->id.'/restore')->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->assertNotSoftDeleted($customer);
        Auth::forgetGuards();
        $this->withToken($this->staff(['customers.view']))->postJson('/api/v1/admin/customers/'.$customer->id.'/restore')->assertForbidden();
    }

    public function test_forgot_password_queues_equal_work_for_known_and_unknown_accounts(): void
    {
        Queue::fake();
        $customer = Customer::factory()->create();

        $this->postJson('/api/v1/store/auth/forgot-password', ['email' => $customer->email])->assertOk();
        $this->postJson('/api/v1/store/auth/forgot-password', ['email' => 'unknown@example.test'])->assertOk();

        Queue::assertPushed(SendCustomerPasswordReset::class, 2);
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_password_reset_respects_requested_channel_and_blocks_ambiguous_identities(): void
    {
        Notification::fake();
        $customer = Customer::factory()->create();
        $this->postJson('/api/v1/store/auth/forgot-password', ['phone' => $customer->phone])->assertOk();
        Notification::assertSentTo($customer, CustomerPasswordReset::class,
            fn ($notification, $channels) => $channels === [CustomerSmsChannel::class]);
        $this->postJson('/api/v1/store/auth/forgot-password', ['phone' => $customer->phone, 'email' => $customer->email])->assertUnprocessable();
    }

    public function test_suspended_customers_cannot_generate_reset_notifications(): void
    {
        Notification::fake();
        $customer = Customer::factory()->create(['status' => 'suspended']);

        $this->postJson('/api/v1/store/auth/forgot-password', ['phone' => $customer->phone])->assertOk();

        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_reset_token_is_consumed_once_and_all_existing_sessions_are_revoked(): void
    {
        $customer = Customer::factory()->create();
        $customer->createToken('old-session');
        $token = str_repeat('x', 64);
        DB::table('password_reset_tokens')->insert(['email' => $customer->getEmailForPasswordReset(), 'token' => Hash::make($token), 'created_at' => now()]);
        $payload = ['email' => $customer->email, 'token' => $token, 'password' => 'new-password', 'password_confirmation' => 'new-password'];

        $this->postJson('/api/v1/store/auth/reset-password', $payload)->assertOk();

        $this->assertTrue(Hash::check('new-password', $customer->refresh()->password));
        $this->assertSame(0, $customer->tokens()->count());
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->postJson('/api/v1/store/auth/reset-password', $payload)->assertUnprocessable();
    }

    public static function invalidResetCases(): array
    {
        return [['expired'], ['wrong'], ['other-account'], ['suspended']];
    }

    #[DataProvider('invalidResetCases')]
    public function test_invalid_or_expired_reset_tokens_do_not_change_passwords(string $case): void
    {
        $this->freezeTime();
        $customer = Customer::factory()->create(['status' => $case === 'suspended' ? 'suspended' : 'approved']);
        $other = Customer::factory()->create();
        $token = str_repeat('x', 64);
        DB::table('password_reset_tokens')->insert(['email' => $customer->getEmailForPasswordReset(),
            'token' => Hash::make($token), 'created_at' => $case === 'expired' ? now()->subMinutes(61) : now()]);

        $this->postJson('/api/v1/store/auth/reset-password', ['email' => $case === 'other-account' ? $other->email : $customer->email,
            'token' => $case === 'wrong' ? str_repeat('y', 64) : $token,
            'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertUnprocessable();

        $this->assertTrue(Hash::check('password', $customer->refresh()->password));
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    public function test_failed_retail_token_issuance_rolls_back_account_creation(): void
    {
        $this->partialMock(CustomerAuthService::class, fn ($mock) => $mock->shouldReceive('issueToken')->once()->andThrow(new \RuntimeException('Failure')));

        $this->postJson('/api/v1/store/auth/register', ['name' => 'X', 'phone' => '01000000000', 'password' => 'password'])->assertServerError();

        $this->assertDatabaseMissing('customers', ['phone' => '01000000000']);
    }

    public function test_missing_documents_return_404_and_download_names_are_safe(): void
    {
        $this->fakeUploads();
        $customer = Customer::factory()->create();
        $document = $customer->documents()->create(['document_type' => 'other', 'file_path' => 'missing.pdf',
            'original_name' => "directory\\bad\"name\r\n.pdf", 'mime_type' => 'application/pdf']);
        $token = $this->staff(['customers.view', 'customers.documents.view']);
        $uri = "/api/v1/admin/customers/{$customer->id}/documents/{$document->id}/download";

        $this->withToken($token)->getJson($uri)->assertNotFound();
        Storage::disk('r2')->put('missing.pdf', 'document');
        Auth::forgetGuards();
        $response = $this->withToken($token)->get($uri)->assertOk();
        $this->assertStringNotContainsString("\r", $response->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString('directory', $response->headers->get('Content-Disposition'));
    }

    public function test_customer_search_includes_company_tax_number_and_literal_wildcards(): void
    {
        $customer = Customer::factory()->wholesale()->create(['company_name' => 'Unique-company', 'tax_number' => 'TAX-123%']);
        Customer::factory()->wholesale()->create(['tax_number' => 'TAX-123456']);
        $token = $this->staff(['customers.view']);

        $this->withToken($token)->getJson('/api/v1/admin/customers?search=Unique-company')->assertOk()->assertJsonPath('data.0.id', $customer->id);
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/admin/customers?search=TAX-123%25')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $customer->id);
    }

    public function test_banner_keys_starting_with_http_receive_r2_signed_urls(): void
    {
        config(['filesystems.banners_disk' => 'r2']);
        Banner::factory()->create(['image_path' => 'http_banners/hero.jpg']);

        $response = $this->getJson('/api/v1/store/home')->assertOk();

        $this->assertSame('https://storage.example.test/http_banners%2Fhero.jpg?signed=1', $response->json('data.banners.0.image'));
    }

    public function test_wholesale_uploads_are_staged_before_the_registration_transaction_and_use_safe_mime_names(): void
    {
        $disk = $this->fakeUploads();
        $proxy = \Mockery::mock($disk);
        Customer::query()->count();
        $baselineLevel = DB::transactionLevel();
        $proxy->shouldReceive('putFileAs')->once()->andReturnUsing(function (...$arguments) use ($disk, $baselineLevel) {
            $this->assertSame($baselineLevel, DB::transactionLevel());

            return $disk->putFileAs(...$arguments);
        });
        Storage::shouldReceive('disk')->with('r2')->andReturn($proxy);

        $response = $this->postJson('/api/v1/wholesale/auth/register', [
            'name' => 'Wholesale', 'phone' => '01000987654', 'password' => 'secure-password123',
            'company_name' => 'Company', 'tax_number' => '123', 'commercial_register' => '456',
            'documents' => [UploadedFile::fake()->create(str_repeat('a', 300).'.jpg', 20, 'application/pdf')],
        ])->assertCreated();

        $document = Customer::findOrFail($response->json('data.id'))->documents()->firstOrFail();
        $this->assertLessThanOrEqual(250, mb_strlen($document->original_name));
        $this->assertStringEndsWith('.pdf', $document->file_path);
        $disk->assertExists($document->file_path);
    }

    public function test_reset_cooldown_keeps_existing_token_and_does_not_send_duplicate_notifications(): void
    {
        $this->freezeTime();
        Notification::fake();
        $customer = Customer::factory()->create();
        $this->postJson('/api/v1/store/auth/forgot-password', ['email' => $customer->email])->assertOk();
        $token = DB::table('password_reset_tokens')->where('email', $customer->getEmailForPasswordReset())->value('token');

        $this->postJson('/api/v1/store/auth/forgot-password', ['email' => $customer->email])->assertOk();

        Notification::assertSentToTimes($customer, CustomerPasswordReset::class, 1);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $customer->getEmailForPasswordReset(), 'token' => $token]);
    }

    public function test_verified_wholesale_details_cannot_be_erased_by_basic_update(): void
    {
        $customer = Customer::factory()->wholesale()->create(['status' => 'approved']);
        $token = $this->staff(['customers.update', 'customers.approve']);

        $this->withToken($token)->putJson('/api/v1/admin/customers/'.$customer->id, ['tax_number' => null])->assertUnprocessable();
        $this->assertNotNull($customer->refresh()->tax_number);
    }

    public function test_variant_list_inherits_weight_without_per_variant_parent_queries_and_update_keeps_images(): void
    {
        $product = Product::factory()->create(['weight_kg' => 2]);
        $variants = ProductVariant::factory()->count(8)->sequence(fn ($sequence) => ['size' => 'size-'.$sequence->index])
            ->create(['product_id' => $product->id, 'weight_kg' => null]);
        $token = $this->staff(['products.view', 'products.update']);
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });

        $this->withToken($token)->getJson('/api/v1/admin/products/'.$product->id.'/variants')
            ->assertOk()->assertJsonPath('data.0.weight_kg', '2.000');
        $parentQueries = array_filter($queries, fn ($sql) => str_contains($sql, 'from "products"'));
        $this->assertCount(1, $parentQueries);
        Auth::forgetGuards();
        $this->withToken($token)->putJson('/api/v1/admin/products/'.$product->id.'/variants/'.$variants->first()->id, ['weight_kg' => 3])
            ->assertOk()->assertJsonPath('data.images', []);
    }
}
