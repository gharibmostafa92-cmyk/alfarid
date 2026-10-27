<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Exceptions\BusinessException;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Notifications\CustomerPasswordReset;
use App\Notifications\CustomerPhoneVerificationCode;
use App\Services\CustomerAuthService;
use App\Services\CustomerPhoneVerificationService;
use App\Services\PricingService;
use App\Services\StoreCatalogService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CustomerAbuseProtectionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function staff(array $permissions): array
    {
        $staff = User::factory()->create();
        foreach ($permissions as $permission) {
            $staff->givePermissionTo(Permission::findOrCreate($permission, 'staff'));
        }

        return [$staff, $staff->createToken('staff')->plainTextToken];
    }

    private function product(array $attributes = []): Product
    {
        $product = Product::factory()->published()->create($attributes);
        ProductVariant::factory()->create(['product_id' => $product->id, 'retail_price' => 100]);

        return $product;
    }

    private function otp(string $phone): string
    {
        $this->postJson('/api/v1/store/auth/phone-verification/send', ['phone' => $phone])->assertOk();
        $code = '';
        Notification::assertSentOnDemand(CustomerPhoneVerificationCode::class, function ($notification, $channels, $notifiable) use (&$code): bool {
            preg_match('/[0-9]{6}$/', $notification->toSms($notifiable), $matches);
            $code = $matches[0];

            return true;
        });

        return $code;
    }

    public function test_registration_limits_are_shared_between_retail_and_wholesale(): void
    {
        config(['auth.customer_limits.registration_ip_minute' => 5]);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/store/auth/register', [])->assertUnprocessable();
        }
        $this->postJson('/api/v1/wholesale/auth/register', [])->assertTooManyRequests();
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_registration_daily_limit_survives_minute_windows(): void
    {
        config(['auth.customer_limits.registration_ip_day' => 20]);
        $this->freezeTime();
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/v1/store/auth/register', [])->assertUnprocessable();
            $this->travel(2)->minutes();
        }
        $this->postJson('/api/v1/store/auth/register', [])->assertTooManyRequests();
    }

    public function test_reset_identifier_limit_survives_rotating_source_ips(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($i + 1)])
                ->postJson('/api/v1/store/auth/forgot-password', ['phone' => '٠١٠٠١٢٣٤٥٦٧'])->assertOk();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->postJson('/api/v1/store/auth/forgot-password', ['phone' => '+201001234567'])->assertTooManyRequests();
    }

    public function test_reset_confirmation_has_an_independent_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/store/auth/forgot-password', ['email' => 'unknown@example.test'])->assertOk();
        }
        $this->postJson('/api/v1/store/auth/reset-password', [])->assertUnprocessable();
    }

    public function test_worker_enforces_daily_customer_budget_across_channels(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(12));
        Notification::fake();
        $customer = Customer::factory()->create();
        for ($i = 0; $i < 6; $i++) {
            app(CustomerAuthService::class)->sendPasswordReset($i % 2 ? null : $customer->email, $i % 2 ? $customer->phone : null);
            $this->travel(61)->seconds();
        }
        Notification::assertSentToTimes($customer, CustomerPasswordReset::class, 5);
        $this->assertSame(5, (int) DB::table('customer_message_budgets')->where('key', hash('sha256', 'reset:customer:'.$customer->id))->sum('sends'));
    }

    public function test_catalog_bounds_and_sort_share_one_lateral_calculation(): void
    {
        $this->product();
        $catalog = app(StoreCatalogService::class);
        $query = $catalog->applyFilters($catalog->publishedQuery(), ['price_min' => 1, 'price_max' => 9999, 'sort' => 'price_asc']);
        $sql = $query->toSql();
        $this->assertSame(1, substr_count($sql, 'join lateral'));
        $this->assertSame(2, substr_count($sql, 'MIN(priced_variants.store_price)')); // stocked minimum, then fallback minimum
        $this->assertCount(1, $query->get());
        $this->assertNotEmpty(DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$sql, $query->getBindings()));
        $this->getJson('/api/v1/store/products?page=501')->assertUnprocessable()->assertJsonValidationErrors('page');
    }

    public function test_expensive_catalog_queries_are_throttled(): void
    {
        config(['auth.customer_limits.price_ip_minute' => 2]);
        for ($i = 0; $i < 2; $i++) {
            $this->getJson('/api/v1/store/products?sort=price_asc')->assertOk();
        }
        $this->getJson('/api/v1/store/products?sort=price_asc')->assertTooManyRequests();
    }

    public function test_basic_editor_cannot_change_password_or_recovery_contacts(): void
    {
        $customer = Customer::factory()->wholesale()->create(['status' => 'approved']);
        [, $token] = $this->staff(['customers.update']);
        foreach ([['password' => 'new-password'], ['email' => 'attacker@example.test'], ['phone' => '01000000000']] as $changes) {
            Auth::forgetGuards();
            $this->withToken($token)->putJson('/api/v1/admin/customers/'.$customer->id, $changes)->assertForbidden();
        }
        $this->assertTrue(Hash::check('password', $customer->refresh()->password));
    }

    public function test_authorized_password_change_revokes_credentials_and_records_actor(): void
    {
        $customer = Customer::factory()->wholesale()->create(['status' => 'approved']);
        $customer->forceFill(['remember_token' => 'old-remember'])->save();
        $customer->createToken('old');
        DB::table('password_reset_tokens')->insert(['email' => $customer->getEmailForPasswordReset(), 'token' => Hash::make('old'), 'created_at' => now()]);
        [$staff, $token] = $this->staff(['customers.update', 'customers.approve']);
        $this->withToken($token)->putJson('/api/v1/admin/customers/'.$customer->id, ['password' => 'new-password'])->assertOk();
        $this->assertTrue(Hash::check('new-password', $customer->refresh()->password));
        $this->assertNotSame('old-remember', $customer->remember_token);
        $this->assertSame(0, $customer->tokens()->count());
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertDatabaseHas('customer_audit_logs', ['customer_id' => $customer->id, 'actor_id' => $staff->id, 'action' => 'credentials_changed']);
        $this->assertStringNotContainsString('new-password', $customer->auditLogs()->firstOrFail()->getRawOriginal('metadata'));
    }

    public static function phoneForms(): array
    {
        return [['01001234567'], ['+201001234567'], ['00201001234567'], ['010 0123 4567'], ['٠١٠٠١٢٣٤٥٦٧'], ['۰۱۰۰۱۲۳۴۵۶۷']];
    }

    #[DataProvider('phoneForms')]
    public function test_phone_aliases_cannot_create_duplicate_accounts(string $phone): void
    {
        Customer::factory()->create(['phone' => '01001234567']);
        $this->postJson('/api/v1/store/auth/register', ['name' => 'X', 'phone' => $phone, 'password' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_phone_rejects_email_identifiers_and_login_normalizes_phone(): void
    {
        $customer = Customer::factory()->create(['phone' => '01001234567']);
        $this->postJson('/api/v1/store/auth/register', ['name' => 'X', 'phone' => 'ali@gmail.com', 'password' => 'password'])->assertUnprocessable();
        $this->postJson('/api/v1/store/auth/login', ['login' => '+201001234567', 'password' => 'password'])->assertOk()->assertJsonPath('data.id', $customer->id);
    }

    public function test_pagination_retains_catalog_and_customer_filters(): void
    {
        $category = Category::factory()->create();
        for ($i = 0; $i < 21; $i++) {
            $this->product(['category_id' => $category->id]);
        }
        $response = $this->getJson('/api/v1/store/products?category='.$category->slug.'&sort=best_selling')->assertOk();
        $this->assertStringContainsString('category='.$category->slug, $response->json('links.next'));
        $this->assertStringContainsString('sort=best_selling', $response->json('links.next'));
        Customer::factory()->count(2)->wholesale()->create();
        [, $token] = $this->staff(['customers.view']);
        $response = $this->withToken($token)->getJson('/api/v1/admin/customers?type=wholesale&status=pending&per_page=1')->assertOk();
        $this->assertStringContainsString('status=pending', $response->json('links.next'));
    }

    public function test_unknown_login_still_checks_a_password_hash(): void
    {
        CustomerAuthService::dummyHash();
        Hash::partialMock()->shouldReceive('check')->once()->with('bad-password', \Mockery::type('string'))->andReturnFalse();
        $this->postJson('/api/v1/store/auth/login', ['login' => 'unknown@example.test', 'password' => 'bad-password'])->assertUnauthorized();
    }

    public function test_session_count_is_bounded(): void
    {
        config(['auth.customer_max_tokens' => 3]);
        $customer = Customer::factory()->create();
        $first = app(CustomerAuthService::class)->issueToken($customer, 'first');
        for ($i = 0; $i < 3; $i++) {
            app(CustomerAuthService::class)->issueToken($customer, 'new');
        }
        $this->assertSame(3, $customer->tokens()->count());
        Auth::forgetGuards();
        $this->withToken($first)->postJson('/api/v1/store/auth/logout')->assertUnauthorized();
    }

    public function test_authenticated_catalog_is_private_and_does_not_write_token_usage(): void
    {
        $customer = Customer::factory()->create();
        $token = $customer->createToken('test');
        $this->withToken($token->plainTextToken)->getJson('/api/v1/store/products')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertNull($token->accessToken->refresh()->last_used_at);
    }

    public function test_audit_records_decisions_and_suspend_delete_clear_reset_tokens(): void
    {
        $customer = Customer::factory()->wholesale()->create();
        [$staff, $token] = $this->staff(['customers.approve', 'customers.delete', 'customers.documents.view']);
        $document = $customer->documents()->create(['document_type' => 'other', 'file_path' => 'private.pdf', 'original_name' => 'private.pdf', 'mime_type' => 'application/pdf']);
        $base = '/api/v1/admin/customers/'.$customer->id;
        $this->withToken($token)->postJson($base.'/approve')->assertUnprocessable();
        Auth::forgetGuards();
        $this->withToken($token)->patchJson($base.'/documents/'.$document->id, ['status' => 'verified'])->assertOk();
        $this->assertSame($staff->id, $document->refresh()->reviewed_by);
        $this->assertNotNull($document->reviewed_at);
        Auth::forgetGuards();
        $this->withToken($token)->postJson($base.'/approve')->assertOk();
        DB::table('password_reset_tokens')->insert(['email' => $customer->getEmailForPasswordReset(), 'token' => Hash::make('old'), 'created_at' => now()]);
        Auth::forgetGuards();
        $this->withToken($token)->postJson($base.'/suspend', ['reason' => 'Security'])->assertOk();
        $this->assertDatabaseCount('password_reset_tokens', 0);
        DB::table('password_reset_tokens')->insert(['email' => $customer->getEmailForPasswordReset(), 'token' => Hash::make('old'), 'created_at' => now()]);
        Auth::forgetGuards();
        $this->withToken($token)->deleteJson($base)->assertOk();
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Auth::forgetGuards();
        $this->withToken($token)->postJson($base.'/restore')->assertOk();
        foreach (['document_reviewed', 'approved', 'suspended', 'deleted', 'restored'] as $action) {
            $this->assertDatabaseHas('customer_audit_logs', ['customer_id' => $customer->id, 'actor_id' => $staff->id, 'action' => $action]);
        }
    }

    public static function invalidCustomers(): array
    {
        return [[['credit_limit' => -1]], [['type' => 'wholesale', 'status' => 'approved']], [['phone' => 'ali@gmail.com']]];
    }

    #[DataProvider('invalidCustomers')]
    public function test_database_enforces_customer_domains(array $changes): void
    {
        try {
            DB::transaction(fn () => DB::table('customers')->insert(['name' => 'Invalid', 'phone' => '01000000000', 'password' => 'hash', ...$changes]));
            $this->fail('Invalid data must be rejected by PostgreSQL.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }
    }

    public function test_raw_wholesale_defaults_to_pending_and_domains_can_be_validated(): void
    {
        DB::table('customers')->insert(['name' => 'Raw', 'phone' => '01000000000', 'password' => 'hash', 'type' => 'wholesale']);
        $this->assertDatabaseHas('customers', ['phone' => '01000000000', 'status' => 'pending']);
        $this->artisan('customers:validate-domains')->assertSuccessful();
    }

    public function test_micro_discount_does_not_show_zero_percent_badge(): void
    {
        $product = $this->product();
        $product->variants()->firstOrFail()->update(['compare_at_price' => '100.10']);
        $this->getJson('/api/v1/store/products/'.$product->slug)->assertOk()->assertJsonPath('data.discount_percentage', null)
            ->assertJsonPath('data.old_price', null)->assertJsonPath('data.variants.0.old_price', null);
        $this->getJson('/api/v1/store/home')->assertOk()->assertJsonCount(0, 'data.discounted_products');
    }

    public function test_numeric_category_slug_takes_precedence_over_id(): void
    {
        $idCategory = Category::factory()->create(['id' => 2026]);
        $slugCategory = Category::factory()->create(['slug' => '2026']);
        $this->product(['category_id' => $idCategory->id]);
        $expected = $this->product(['category_id' => $slugCategory->id]);
        $this->getJson('/api/v1/store/products?category=2026')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $expected->id);
    }

    public function test_decimal_rounding_matches_sql(): void
    {
        $group = CustomerGroup::create(['name' => 'Half-cent', 'code' => 'half-cent', 'discount_percentage' => '12.50']);
        $customer = Customer::factory()->create(['customer_group_id' => $group->id]);
        $variant = $this->product()->variants()->firstOrFail();
        $variant->update(['retail_price' => '1.08']);
        $pricing = app(PricingService::class);
        $this->assertSame(0.95, $pricing->resolve($variant, $customer)['unit_price']);
        $this->assertSame(0.95, (float) $pricing->withStorePrice(ProductVariant::query(), $customer)->whereKey($variant->id)->firstOrFail()->store_price);
    }

    public function test_registration_requires_phone_proof_bound_to_number_and_single_use(): void
    {
        config(['auth.require_phone_verification' => true]);
        Notification::fake();
        $phone = '01001234567';
        $payload = ['name' => 'Verified', 'phone' => $phone, 'password' => 'password'];
        $this->postJson('/api/v1/store/auth/register', $payload)->assertUnprocessable()->assertJsonValidationErrors('phone_verification_token');
        $code = $this->otp($phone);
        $proof = $this->postJson('/api/v1/store/auth/phone-verification/verify', ['phone' => $phone, 'code' => $code])->assertOk()->json('phone_verification_token');
        $this->postJson('/api/v1/store/auth/register', [...$payload, 'phone' => '01099999999', 'phone_verification_token' => $proof])->assertUnprocessable();
        $this->postJson('/api/v1/store/auth/register', [...$payload, 'phone_verification_token' => $proof])->assertCreated();
        $this->assertNotNull(Customer::where('phone', $phone)->firstOrFail()->phone_verified_at);
        $this->postJson('/api/v1/store/auth/register', [...$payload, 'phone_verification_token' => $proof])->assertUnprocessable();
    }

    public function test_phone_otp_limits_attempts_and_expiry(): void
    {
        $this->freezeTime();
        Notification::fake();
        $phone = '01001234567';
        $code = $this->otp($phone);
        $wrong = $code === '111111' ? '222222' : '111111';
        for ($i = 0; $i < 5; $i++) {
            try {
                app(CustomerPhoneVerificationService::class)->verify($phone, $wrong);
            } catch (BusinessException) {
            }
        }
        try {
            app(CustomerPhoneVerificationService::class)->verify($phone, $code);
            $this->fail('Exhausted OTP must fail.');
        } catch (BusinessException) {
            $this->assertSame(5, DB::table('customer_phone_verifications')->value('attempts'));
        }
        $this->travel(11)->minutes();
        $this->postJson('/api/v1/store/auth/phone-verification/verify', ['phone' => $phone, 'code' => $code])->assertUnprocessable();
    }

    public function test_queue_payload_encrypts_recipient_identifiers(): void
    {
        config(['auth.customer_password_reset.connection' => 'database']);
        $email = 'private-recipient@example.test';
        $this->postJson('/api/v1/store/auth/forgot-password', ['email' => $email])->assertOk();
        $payload = DB::table('jobs')->value('payload');
        $this->assertStringNotContainsString($email, $payload);
        $this->assertStringContainsString('SendCustomerPasswordReset', $payload);
    }

    public function test_cleanup_only_removes_abandoned_staging(): void
    {
        $disk = $this->fakeUploads();
        $old = 'customer_documents/pending/'.Str::uuid();
        $fresh = 'customer_documents/pending/'.Str::uuid();
        $disk->put($old.'/old.pdf', 'old');
        $disk->put($fresh.'/new.pdf', 'new');
        $disk->put('customer_documents/123/final.pdf', 'final');
        touch($disk->path($old.'/old.pdf'), now()->subHours(2)->timestamp);
        touch($disk->path($old), now()->subHours(2)->timestamp);
        $this->artisan('customers:prune-artifacts')->assertSuccessful();
        $disk->assertMissing($old.'/old.pdf');
        $disk->assertExists([$fresh.'/new.pdf', 'customer_documents/123/final.pdf']);
    }

    public function test_document_download_requires_its_own_permission(): void
    {
        $customer = Customer::factory()->create();
        $document = $customer->documents()->create(['document_type' => 'other', 'file_path' => 'private.pdf', 'original_name' => 'private.pdf', 'mime_type' => 'application/pdf']);
        [, $token] = $this->staff(['customers.view']);
        $this->withToken($token)->getJson("/api/v1/admin/customers/{$customer->id}/documents/{$document->id}/download")->assertForbidden();
    }

    public function test_expired_phone_otp_cannot_be_verified_even_with_the_correct_code(): void
    {
        Notification::fake();
        $phone = '01001234567';
        $code = $this->otp($phone);
        $this->travel(11)->minutes();
        $this->postJson('/api/v1/store/auth/phone-verification/verify', ['phone' => $phone, 'code' => $code])->assertUnprocessable();
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_phone_verification_has_a_durable_daily_budget(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(12));
        Notification::fake();
        for ($i = 0; $i < 6; $i++) {
            app(CustomerPhoneVerificationService::class)->send('01001234567');
            $this->travel(61)->seconds();
        }
        Notification::assertSentOnDemandTimes(CustomerPhoneVerificationCode::class, 5);
    }

    public function test_unknown_reset_confirmation_still_checks_the_token_hash(): void
    {
        CustomerAuthService::dummyHash();
        Hash::partialMock()->shouldReceive('check')->once()->with(str_repeat('x', 64), \Mockery::type('string'))->andReturnFalse();
        $this->postJson('/api/v1/store/auth/reset-password', [
            'email' => 'unknown@example.test', 'token' => str_repeat('x', 64), 'password' => 'new-password', 'password_confirmation' => 'new-password',
        ])->assertUnprocessable();
    }

    public function test_expired_sessions_are_pruned_before_issuing_another_token(): void
    {
        $customer = Customer::factory()->create();
        $old = $customer->createToken('expired')->accessToken;
        $old->forceFill(['created_at' => now()->subDays(2)])->save();
        app(CustomerAuthService::class)->issueToken($customer, 'new');
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $old->id]);
        $this->assertSame(1, $customer->tokens()->count());
    }

    public function test_legacy_phone_normalization_is_a_dry_run_until_explicit_apply(): void
    {
        $customer = Customer::factory()->create(['phone' => '01001234567']);
        DB::statement('ALTER TABLE customers DROP CONSTRAINT customers_valid_phone');
        DB::table('customers')->where('id', $customer->id)->update(['phone' => '+201001234567']);
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_valid_phone CHECK (phone ~ '^01[0125][0-9]{8}$' OR phone ~ '^legacy-[0-9]+$') NOT VALID");
        $this->artisan('customers:normalize-phones')->assertSuccessful();
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'phone' => '+201001234567']);
        $this->artisan('customers:normalize-phones', ['--apply' => true])->assertSuccessful();
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'phone' => '01001234567']);
        $this->assertDatabaseHas('customer_audit_logs', ['customer_id' => $customer->id, 'action' => 'phone_normalized']);
    }

    public function test_legacy_phone_collisions_require_manual_repair_without_modifying_accounts(): void
    {
        $first = Customer::factory()->create(['phone' => '01001234567']);
        $second = Customer::factory()->create();
        DB::statement('ALTER TABLE customers DROP CONSTRAINT customers_valid_phone');
        DB::table('customers')->where('id', $second->id)->update(['phone' => '+201001234567']);
        $this->artisan('customers:normalize-phones', ['--apply' => true])->assertFailed();
        $this->assertDatabaseHas('customers', ['id' => $first->id, 'phone' => '01001234567']);
        $this->assertDatabaseHas('customers', ['id' => $second->id, 'phone' => '+201001234567']);
    }

    public function test_home_keeps_banners_available_during_cold_cache_contention(): void
    {
        $this->getJson('/api/v1/store/home')->assertOk();
        $lock = Cache::lock('store_home_data:v2:build', 15);
        $this->assertTrue($lock->get());
        try {
            $this->getJson('/api/v1/store/home')->assertOk();
            Cache::forget('store_home_data:v2');
            $this->getJson('/api/v1/store/home')->assertOk()->assertJsonPath('data.latest_products', []);
        } finally {
            $lock->release();
        }
    }

    public function test_queue_backlog_events_record_operational_alerts(): void
    {
        Log::spy();
        Event::dispatch(new QueueBusy('database', 'default', 101));
        Log::shouldHaveReceived('error')->once()->with(
            'Queue backlog exceeded its configured threshold.', ['connection' => 'database', 'queue' => 'default', 'size' => 101],
        );
    }
}
