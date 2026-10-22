<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Notifications\CustomerPasswordReset;
use App\Notifications\CustomerPhoneVerificationCode;
use App\Providers\AppServiceProvider;
use App\Services\CustomerAuthService;
use App\Services\CustomerMessageBudget;
use App\Services\CustomerPhoneVerificationService;
use App\Services\PricingService;
use App\Services\StoreCatalogService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CustomerAdditionalReviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function staff(array $permissions): string
    {
        $staff = User::factory()->create();
        foreach ($permissions as $permission) {
            $staff->givePermissionTo(Permission::findOrCreate($permission, 'staff'));
        }

        return $staff->createToken('staff')->plainTextToken;
    }

    public static function pricingCases(): array
    {
        return [
            'retail' => ['retail', 80.0],
            'wholesale with group discount' => ['wholesale', 70.0],
            'customer group' => ['customer_group', 80.0],
            'customer specific' => ['customer_specific', 80.0],
        ];
    }

    #[DataProvider('pricingCases')]
    public function test_sql_and_php_agree_for_list_only_variants(string $type, float $expected): void
    {
        $group = CustomerGroup::create(['name' => 'Review', 'code' => 'review', 'discount_percentage' => '12.50']);
        $customer = Customer::factory()->wholesale()->create(['status' => 'approved', 'customer_group_id' => $group->id]);
        $variant = ProductVariant::factory()->create(['retail_price' => null]);
        $list = PriceList::create(['name' => 'Review', 'type' => $type,
            'customer_id' => $type === 'customer_specific' ? $customer->id : null,
            'customer_group_id' => $type === 'customer_group' ? $group->id : null]);
        $list->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 80]);
        $buyer = $type === 'retail' ? null : $customer;
        $pricing = app(PricingService::class);

        $php = $pricing->resolve($variant, $buyer);
        $loaded = $pricing->withStorePrice(ProductVariant::query(), $buyer)->findOrFail($variant->id);
        $sql = app(StoreCatalogService::class)->variantPrice($loaded, $buyer);

        $this->assertSame($expected, $php['unit_price']);
        $this->assertSame($expected, $php['base_price']);
        $this->assertSame($expected, $sql['unit_price']);
        $this->assertSame($expected, $sql['base_price']);
    }

    public function test_loaded_catalog_prices_execute_no_queries_during_display(): void
    {
        $product = Product::factory()->published()->create();
        ProductVariant::factory()->count(10)->create(['product_id' => $product->id]);
        $catalog = app(StoreCatalogService::class);
        $loaded = $catalog->publishedQuery()->findOrFail($product->id);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $catalog->displayPrice($loaded, null);
        foreach ($loaded->variants as $variant) {
            $catalog->variantPrice($variant, null);
        }

        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_sms_hourly_budget_is_global_and_declined_claim_does_not_charge_recipient(): void
    {
        $this->travelTo(now(config('app.business_timezone', 'Africa/Cairo'))->startOfDay()->addHours(12));
        config(['auth.customer_sms_hourly_limit' => 2, 'auth.customer_sms_daily_limit' => 3]);
        $budget = app(CustomerMessageBudget::class);
        $this->assertTrue($budget->claim('a'));
        $this->assertTrue($budget->claim('b'));

        $this->assertFalse($budget->claim('c'));
        $this->assertDatabaseMissing('customer_message_budgets', ['key' => hash('sha256', 'c')]);
        $this->travel(1)->hours();
        $this->assertTrue($budget->claim('c'));
        $this->assertFalse($budget->claim('d'));
        $this->assertTrue($budget->claim('mail', sms: false));
    }

    public function test_sms_budget_warns_once_at_eighty_percent(): void
    {
        $this->freezeTime();
        config(['auth.customer_sms_hourly_limit' => 5, 'auth.customer_sms_daily_limit' => 100]);
        Log::spy();
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue(app(CustomerMessageBudget::class)->claim('recipient-'.$i));
        }
        Log::shouldHaveReceived('warning')->once()->with('Customer SMS budget reached 80%.', [
            'scope' => 'sms:global:hour:'.now(config('app.business_timezone', 'Africa/Cairo'))->format('H'),
            'date' => now(config('app.business_timezone', 'Africa/Cairo'))->toDateString(), 'limit' => 5,
        ]);
    }

    public function test_resending_cannot_invalidate_valid_phone_proof(): void
    {
        $this->freezeTime();
        Notification::fake();
        config(['auth.require_phone_verification' => true]);
        $phone = '01001234567';
        $service = app(CustomerPhoneVerificationService::class);
        $service->send($phone);
        $code = '';
        Notification::assertSentOnDemand(CustomerPhoneVerificationCode::class, function ($notification, $channels, $notifiable) use (&$code): bool {
            preg_match('/[0-9]{6}$/', $notification->toSms($notifiable), $matches);
            $code = $matches[0];

            return true;
        });
        $proof = $service->verify($phone, $code);
        $this->travel(61)->seconds();

        $service->send($phone);

        Notification::assertSentOnDemandTimes(CustomerPhoneVerificationCode::class, 1);
        $this->postJson('/api/v1/store/auth/register', ['name' => 'Verified', 'phone' => $phone, 'password' => 'password', 'phone_verification_token' => $proof])->assertCreated();
    }

    public function test_random_email_does_not_bypass_phone_rate_limit(): void
    {
        Notification::fake();
        $this->postJson('/api/v1/store/auth/phone-verification/send', ['phone' => '01001234567', 'email' => 'first@example.test'])->assertOk();

        $this->postJson('/api/v1/store/auth/phone-verification/send', ['phone' => '01001234567', 'email' => 'second@example.test'])->assertTooManyRequests();
        Notification::assertSentOnDemandTimes(CustomerPhoneVerificationCode::class, 1);
    }

    public function test_unverified_email_does_not_reserve_identity_or_allow_login_and_recovery(): void
    {
        Notification::fake();
        $customer = Customer::factory()->create(['email' => 'victim@example.test', 'email_verified_at' => null]);
        $payload = ['name' => 'Owner', 'email' => ' VICTIM@EXAMPLE.TEST ', 'phone' => '01001234567', 'password' => 'password'];

        $this->postJson('/api/v1/store/auth/register', $payload)->assertCreated()->assertJsonPath('data.email', 'victim@example.test');
        Auth::forgetGuards();
        $this->postJson('/api/v1/store/auth/login', ['login' => 'victim@example.test', 'password' => 'password'])->assertUnauthorized();
        $this->postJson('/api/v1/store/auth/forgot-password', ['email' => 'victim@example.test'])->assertOk();
        Notification::assertNothingSent();
        $this->postJson('/api/v1/store/auth/login', ['login' => $customer->phone, 'password' => 'password'])->assertOk();
    }

    public function test_verified_email_reset_accepts_mixed_case_and_hashes_random_token_with_sha256(): void
    {
        Notification::fake();
        $customer = Customer::factory()->create(['email' => 'owner@example.test']);
        app(CustomerAuthService::class)->sendPasswordReset('OWNER@EXAMPLE.TEST', null);
        $token = '';
        Notification::assertSentTo($customer, CustomerPasswordReset::class, function ($notification) use (&$token): bool {
            $token = str_replace('رمز استعادة كلمة المرور: ', '', $notification->toSms(Customer::factory()->make()));

            return true;
        });
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $customer->getEmailForPasswordReset(), 'token' => hash('sha256', $token)]);

        $this->postJson('/api/v1/store/auth/reset-password', ['email' => 'OWNER@EXAMPLE.TEST', 'token' => $token, 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertOk();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_editor_cannot_change_business_identity(): void
    {
        $customer = Customer::factory()->wholesale()->create(['status' => 'approved']);
        $token = $this->staff(['customers.update']);

        $this->withToken($token)->putJson('/api/v1/admin/customers/'.$customer->id, ['commercial_register' => 'different'])->assertForbidden();
        $this->assertNotSame('different', $customer->refresh()->commercial_register);
    }

    public function test_authorized_business_identity_change_records_specific_audit_action(): void
    {
        $customer = Customer::factory()->wholesale()->create(['status' => 'approved']);
        $token = $this->staff(['customers.update', 'customers.approve']);

        $this->withToken($token)->putJson('/api/v1/admin/customers/'.$customer->id, ['commercial_register' => 'different'])->assertOk();
        $this->assertDatabaseHas('customer_audit_logs', ['customer_id' => $customer->id, 'action' => 'business_identity_changed']);
    }

    public function test_document_review_requires_both_permissions_and_download_is_audited(): void
    {
        $disk = $this->fakeUploads();
        $customer = Customer::factory()->wholesale()->create();
        $document = $customer->documents()->create(['document_type' => 'national_id', 'file_path' => 'private.pdf', 'original_name' => 'private.pdf', 'mime_type' => 'application/pdf']);
        $disk->put('private.pdf', 'private');
        $token = $this->staff(['customers.approve']);
        $url = '/api/v1/admin/customers/'.$customer->id.'/documents/'.$document->id;
        $this->withToken($token)->patchJson($url, ['status' => 'verified'])->assertForbidden();
        Auth::forgetGuards();
        $token = $this->staff(['customers.approve', 'customers.documents.view']);

        $this->withToken($token)->patchJson($url, ['status' => 'verified'])->assertOk();
        $this->withToken($token)->getJson($url.'/download')->assertOk();
        $this->assertDatabaseHas('customer_audit_logs', ['customer_id' => $customer->id, 'action' => 'document_downloaded']);
    }

    public function test_wholesale_approval_without_documents_returns_422(): void
    {
        $customer = Customer::factory()->wholesale()->create();
        $token = $this->staff(['customers.approve']);

        $this->withToken($token)->postJson('/api/v1/admin/customers/'.$customer->id.'/approve')->assertUnprocessable();
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'status' => 'pending']);
    }

    public function test_stale_home_data_is_returned_while_refresh_lock_is_held(): void
    {
        $this->freezeTime();
        $this->getJson('/api/v1/store/home')->assertOk();
        $this->travel(301)->seconds();
        $lock = Cache::lock('illuminate:cache:flexible:lock:store_home_data', 120);
        $this->assertTrue($lock->get());
        try {
            $this->getJson('/api/v1/store/home')->assertOk();
            $this->assertNotNull(Cache::get('store_home_data'));
        } finally {
            $lock->release();
        }
    }

    public function test_price_queries_have_global_limit_across_ips(): void
    {
        config(['auth.customer_limits.price_global_minute' => 2]);
        for ($i = 1; $i <= 2; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$i])->getJson('/api/v1/store/products?sort=price_asc')->assertOk();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.3'])->getJson('/api/v1/store/products?sort=price_asc')->assertTooManyRequests();
    }

    public function test_price_sort_count_omits_lateral_query_and_keeps_page_totals(): void
    {
        Product::factory()->published()->count(2)->create();
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->getJson('/api/v1/store/products?sort=price_asc')->assertOk()->assertJsonPath('meta.total', 2);

        $counts = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'count('));
        $this->assertCount(1, $counts);
        $this->assertStringNotContainsString('lateral', $counts->first()['query']);
        DB::disableQueryLog();
    }

    public function test_sms_gateway_rejection_is_logged_even_on_http_200(): void
    {
        config(['services.customer_sms.url' => 'https://sms.example.test/send', 'services.customer_sms.token' => 'secret']);
        Http::preventStrayRequests();
        Http::fake(['https://sms.example.test/send' => Http::response(['success' => false], 200)]);
        Log::spy();
        try {
            app(CustomerPhoneVerificationService::class)->send('01001234567');
            $this->fail('Gateway refusal must fail delivery.');
        } catch (BusinessException) {
            Log::shouldHaveReceived('error')->once()->with('Customer OTP delivery failed.', ['exception' => BusinessException::class]);
        }
        Http::assertSentCount(1);
    }

    public function test_cleanup_prunes_old_orphans_and_keeps_soft_deleted_customer_documents(): void
    {
        $disk = $this->fakeUploads();
        $customer = Customer::factory()->create();
        $customer->delete();
        $kept = 'customer_documents/'.$customer->id.'/kept.pdf';
        $orphan = 'customer_documents/99999999/orphan.pdf';
        foreach ([$kept, $orphan] as $file) {
            $disk->put($file, 'private');
            touch($disk->path($file), now()->subHours(2)->timestamp);
            touch(dirname($disk->path($file)), now()->subHours(2)->timestamp);
        }

        $this->artisan('customers:prune-artifacts')->assertSuccessful();

        $disk->assertExists($kept);
        $disk->assertMissing($orphan);
    }

    public function test_document_type_count_must_match_uploaded_files(): void
    {
        $this->fakeUploads();
        $this->postJson('/api/v1/wholesale/auth/register', ['name' => 'Company', 'phone' => '01001234567', 'password' => 'password',
            'company_name' => 'Company', 'tax_number' => '123', 'commercial_register' => '456',
            'documents' => [UploadedFile::fake()->create('id.pdf', 10, 'application/pdf')],
            'document_types' => ['national_id', 'tax_card']])->assertUnprocessable()->assertJsonValidationErrors('document_types');
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_login_hourly_limit_survives_minute_windows_and_rotating_ips(): void
    {
        $this->freezeTime();
        config(['auth.customer_limits.login_id_hour' => 2]);
        for ($i = 1; $i <= 2; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$i])->postJson('/api/v1/store/auth/login', ['login' => '01001234567', 'password' => 'wrong'])->assertUnauthorized();
            $this->travel(2)->minutes();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.3'])->postJson('/api/v1/store/auth/login', ['login' => '01001234567', 'password' => 'wrong'])->assertTooManyRequests();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public static function unsafeProxyConfigurations(): array
    {
        return [[''], [null], ['*'], ['10.0.0.1,*']];
    }

    #[DataProvider('unsafeProxyConfigurations')]
    public function test_production_rejects_missing_or_wildcard_proxy_configuration(?string $proxies): void
    {
        $this->app['env'] = 'production';
        config(['app.trusted_proxies' => $proxies]);
        $this->expectException(\LogicException::class);

        (new AppServiceProvider($this->app))->boot();
    }

    public function test_only_configured_proxy_can_supply_client_ip(): void
    {
        config(['app.trusted_proxies' => '192.0.2.10']);
        (new AppServiceProvider($this->app))->boot();
        Route::get('/review-client-ip', fn (Request $request) => response()->json(['ip' => $request->ip()]));

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])->withHeader('X-Forwarded-For', '198.51.100.20')
            ->getJson('/review-client-ip')->assertOk()->assertJsonPath('ip', '198.51.100.20');
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.11'])->withHeader('X-Forwarded-For', '198.51.100.20')
            ->getJson('/review-client-ip')->assertOk()->assertJsonPath('ip', '192.0.2.11');
    }

    public function test_email_change_revokes_email_verification(): void
    {
        $customer = Customer::factory()->create();
        $this->assertNotNull($customer->email_verified_at);

        $customer->update(['email' => 'replacement@example.test']);

        $this->assertNull($customer->refresh()->email_verified_at);
    }

    public function test_authentication_and_token_issuance_are_audited_without_plaintext_credentials(): void
    {
        $customer = Customer::factory()->create();
        $payload = ['login' => $customer->phone, 'password' => 'password'];
        $this->postJson('/api/v1/store/auth/login', [...$payload, 'password' => 'wrong'])->assertUnauthorized();
        $this->postJson('/api/v1/store/auth/login', $payload)->assertOk();

        foreach (['login_failed', 'login_succeeded', 'token_issued'] as $action) {
            $this->assertDatabaseHas('customer_audit_logs', ['customer_id' => $customer->id, 'action' => $action]);
        }
        $this->assertStringNotContainsString('password', $customer->auditLogs()->get()->toJson());
    }

    public function test_admin_creation_rejects_terminal_status_and_stamps_retail_approval(): void
    {
        $token = $this->staff(['customers.create']);
        $payload = ['name' => 'New', 'phone' => '01001234567', 'password' => 'password'];
        $this->withToken($token)->postJson('/api/v1/admin/customers', [...$payload, 'status' => 'suspended'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->withToken($token)->postJson('/api/v1/admin/customers', $payload)->assertCreated();
        $this->assertNotNull(Customer::where('phone', $payload['phone'])->firstOrFail()->approved_at);
    }

    public function test_document_upload_with_scalar_file_returns_422(): void
    {
        $this->fakeUploads();
        $this->postJson('/api/v1/wholesale/auth/register', ['name' => 'Company', 'phone' => '01001234567', 'password' => 'password',
            'company_name' => 'Company', 'tax_number' => '123', 'commercial_register' => '456',
            'documents' => UploadedFile::fake()->create('id.pdf', 10, 'application/pdf'),
            'document_types' => ['national_id']])->assertUnprocessable()->assertJsonValidationErrors('documents');
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_login_daily_limit_survives_hour_windows(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(12));
        config(['auth.customer_limits.login_id_hour' => 100, 'auth.customer_limits.login_id_day' => 2]);
        for ($i = 1; $i <= 2; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$i])->postJson('/api/v1/store/auth/login', ['login' => '01001234567', 'password' => 'wrong'])->assertUnauthorized();
            $this->travel(61)->minutes();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.3'])->postJson('/api/v1/store/auth/login', ['login' => '01001234567', 'password' => 'wrong'])->assertTooManyRequests();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
