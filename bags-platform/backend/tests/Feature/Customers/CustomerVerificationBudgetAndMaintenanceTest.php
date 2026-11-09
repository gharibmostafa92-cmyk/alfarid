<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Enums\CustomerDocumentStatus;
use App\Enums\CustomerDocumentType;
use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\CustomerAuditLog;
use App\Models\CustomerGroup;
use App\Models\User;
use App\Services\Auth\CustomerAuthService;
use App\Services\Customer\CustomerMessageBudget;
use App\Services\Customer\CustomerPhoneVerificationService;
use Database\Seeders\CustomerPermissionSeeder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CustomerVerificationBudgetAndMaintenanceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeUploads();
        $this->seed(CustomerPermissionSeeder::class);
    }

    /**
     * 19 — كود التحقق من الموبايل (OTP): دورة الحياة الكاملة
     */
    public function test_19_phone_otp_full_lifecycle(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-01 12:00:00', 'Africa/Cairo'));
        config([
            'services.customer_sms.url' => 'https://sms.example.test/send',
            'services.customer_sms.token' => 'secret-sms-token',
            'auth.customer_sms_daily_limit' => 100,
            'auth.customer_sms_hourly_limit' => 50,
            'auth.customer_sms_verification_network_hourly_limit' => 20,
            'auth.customer_daily_messages' => 20,
        ]);
        Http::preventStrayRequests();
        $sentMessages = [];
        Http::fake([
            'https://sms.example.test/send' => function ($request) use (&$sentMessages) {
                $sentMessages[] = $request->data();

                return Http::response(['success' => true], 200);
            },
        ]);

        $service = app(CustomerPhoneVerificationService::class);
        $netKey = CustomerMessageBudget::subnetKey('198.51.100.10');
        $phone = '01012345678';

        // 1. First send -> 1 HTTP message
        $service->send($phone, $netKey);
        $this->assertCount(1, $sentMessages);

        // 2. Second send after 30 seconds -> 0 new messages (cooldown)
        Carbon::setTestNow(now()->addSeconds(30));
        $service->send($phone, $netKey);
        $this->assertCount(1, $sentMessages);

        // 3. Third send after 61 seconds from initial -> 1 new message, attempts=0
        Carbon::setTestNow(now()->addSeconds(31));
        $service->send($phone, $netKey);
        $this->assertCount(2, $sentMessages);
        preg_match('/([0-9]{6})/', (string) ($sentMessages[1]['message'] ?? ''), $matches);
        $code = $matches[1];
        $this->assertSame(0, (int) DB::table('customer_phone_verifications')->value('attempts'));

        // 4. Verify with wrong code 5 times, then with right code -> rejected (422)
        $wrongCode = $code === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 5; $i++) {
            try {
                $service->verify($phone, $wrongCode);
                $this->fail('Wrong OTP code must fail.');
            } catch (BusinessException $e) {
                $this->assertSame(422, $e->status);
            }
        }
        try {
            $service->verify($phone, $code);
            $this->fail('6th attempt even with right code must fail.');
        } catch (BusinessException $e) {
            $this->assertSame(422, $e->status);
        }

        // Issue fresh OTP after cooldown and get valid 64-char proof
        Carbon::setTestNow(now()->addSeconds(61));
        $service->send($phone, $netKey);
        $this->assertCount(3, $sentMessages);
        preg_match('/([0-9]{6})/', (string) ($sentMessages[2]['message'] ?? ''), $matches);
        $freshCode = $matches[1];
        $proof = $service->verify($phone, $freshCode);
        $this->assertSame(64, strlen($proof));

        // 5. Verify after 11 minutes -> rejected
        $phoneExpired = '01012345679';
        $service->send($phoneExpired, $netKey);
        $this->assertCount(4, $sentMessages);
        preg_match('/([0-9]{6})/', (string) ($sentMessages[3]['message'] ?? ''), $matches);
        $expCode = $matches[1];
        Carbon::setTestNow(now()->addMinutes(11));
        try {
            $service->verify($phoneExpired, $expCode);
            $this->fail('Expired OTP must fail.');
        } catch (BusinessException $e) {
            $this->assertSame(422, $e->status);
        }

        // 6. Send to phone registered to an existing or soft-deleted customer -> silent no-op
        $existing = Customer::factory()->create(['phone' => '01022223333']);
        $service->send($existing->phone, $netKey);
        $existing->delete();
        $service->send($existing->phone, $netKey);
        $this->assertCount(4, $sentMessages);

        // 7. Send without networkKey -> no HTTP message, logs warning
        Log::spy();
        $service->send('01099998888', null);
        $this->assertCount(4, $sentMessages);
        Log::shouldHaveReceived('warning')->with('Verification SMS claimed without network key.')->once();
    }

    /**
     * 20 — الـ proof بيتستخدم مرة واحدة بس (تتابعيًا، وفحص الموبايل المختلف قبل رفع أي ملف على r2)
     */
    public function test_20_phone_verification_proof_is_single_use_and_checked_before_r2_upload(): void
    {
        config(['auth.require_phone_verification' => true]);
        Notification::fake();

        $service = app(CustomerPhoneVerificationService::class);
        $netKey = CustomerMessageBudget::subnetKey('198.51.100.20');
        $phone = '01055556666';

        $service->send($phone, $netKey);
        $code = '';
        Notification::assertSentOnDemand(\App\Notifications\CustomerPhoneVerificationCode::class, function ($n, $ch, $notifiable) use (&$code) {
            preg_match('/([0-9]{6})/', $n->toSms($notifiable), $m);
            $code = $m[1];

            return true;
        });

        $proof = $service->verify($phone, $code);

        // First registration with matching phone succeeds, sets phone_verified_at, and deletes verification row
        $this->postJson('/api/v1/wholesale/auth/register', [
            'name' => 'تاجر أول',
            'phone' => $phone,
            'password' => 'strong-pass-12',
            'phone_verification_token' => $proof,
            'company_name' => 'شركة',
            'tax_number' => '123',
            'commercial_register' => '456',
            'documents' => [UploadedFile::fake()->create('cr.pdf', 30, 'application/pdf')],
        ])->assertCreated();

        $customer = Customer::where('phone', $phone)->firstOrFail();
        $this->assertNotNull($customer->phone_verified_at);
        $this->assertDatabaseCount('customer_phone_verifications', 0);

        // Second sequential registration with same proof fails with 422
        $this->postJson('/api/v1/wholesale/auth/register', [
            'name' => 'تاجر ثاني',
            'phone' => $phone,
            'password' => 'strong-pass-12',
            'phone_verification_token' => $proof,
            'company_name' => 'شركة',
            'tax_number' => '123',
            'commercial_register' => '456',
            'documents' => [UploadedFile::fake()->create('cr.pdf', 30, 'application/pdf')],
        ])->assertStatus(422);

        // Using proof with a different phone number is rejected BEFORE any file is uploaded to r2
        $phone2 = '01055556667';
        Carbon::setTestNow(now()->addMinutes(2));
        $service->send($phone2, $netKey);
        Notification::assertSentOnDemand(\App\Notifications\CustomerPhoneVerificationCode::class, function ($n, $ch, $notifiable) use (&$code) {
            preg_match('/([0-9]{6})/', $n->toSms($notifiable), $m);
            $code = $m[1];

            return true;
        });
        $proof2 = $service->verify($phone2, $code);

        $disk = $this->fakeUploads();
        $proxy = \Mockery::mock($disk)->makePartial();
        $proxy->shouldNotReceive('putFileAs');
        Storage::shouldReceive('disk')->with('r2')->andReturn($proxy);

        $this->postJson('/api/v1/wholesale/auth/register', [
            'name' => 'تاجر بموبايل مختلف',
            'phone' => '01099990000',
            'password' => 'strong-pass-12',
            'phone_verification_token' => $proof2,
            'company_name' => 'شركة',
            'tax_number' => '123',
            'commercial_register' => '456',
            'documents' => [UploadedFile::fake()->create('cr.pdf', 30, 'application/pdf')],
        ])->assertStatus(422)->assertJsonValidationErrors('phone_verification_token');
    }

    /**
     * 21 — ميزانية الرسائل: الحدود العامة وحدود التحقق وحدود الشبكة وتوقيت القاهرة
     */
    public function test_21_customer_message_budget_limits_atomicity_and_cairo_timezone(): void
    {
        // 23:30 UTC on 2026-11-01 is 01:30 Cairo on 2026-11-02
        Carbon::setTestNow(Carbon::parse('2026-11-01 23:30:00', 'UTC'));
        config([
            'app.business_timezone' => 'Africa/Cairo',
            'auth.customer_sms_daily_limit' => 10,
            'auth.customer_sms_hourly_limit' => 4,
            'auth.customer_sms_verification_fraction' => 0.6,
            'auth.customer_sms_verification_network_hourly_limit' => 3,
            'auth.customer_sms_verification_network_daily_limit' => 10,
            'auth.customer_daily_messages' => 5,
        ]);

        $budget = app(CustomerMessageBudget::class);
        $netKey = CustomerMessageBudget::subnetKey('203.0.113.5');

        // Verification hourly limit = floor(4 * 0.6) = 2
        $this->assertTrue($budget->claim('verify:phone1', sms: true, networkKey: $netKey));
        $this->assertTrue($budget->claim('verify:phone2', sms: true, networkKey: $netKey));

        // 3rd verification claim in the same hour exceeds sms:verify:hour (limit 2) -> false
        // And NO other counters (like sms:global:day, network, or verify:phone3) are incremented!
        $globalDayKey = hash('sha256', 'sms:global:day');
        $this->assertSame(2, (int) DB::table('customer_message_budgets')->where('key', $globalDayKey)->value('sends'));

        $this->assertFalse($budget->claim('verify:phone3', sms: true, networkKey: $netKey));
        $this->assertSame(2, (int) DB::table('customer_message_budgets')->where('key', $globalDayKey)->value('sends'));
        $this->assertSame(0, (int) DB::table('customer_message_budgets')->where('key', hash('sha256', 'verify:phone3'))->value('sends'));

        // Date stored is Cairo date (2026-11-02), not UTC date (2026-11-01)
        $this->assertDatabaseHas('customer_message_budgets', [
            'key' => $globalDayKey,
            'date' => '2026-11-02',
            'sends' => 2,
        ]);

        // Zero limit in config always returns false
        config(['auth.customer_daily_messages' => 0]);
        $this->assertFalse($budget->claim('reset:customer:99', sms: false));

        // Section 3: subnetKey IPv4 /24 and IPv6 /48 grouping + invalid IP -> null
        $this->assertSame(
            CustomerMessageBudget::subnetKey('192.0.2.10'),
            CustomerMessageBudget::subnetKey('192.0.2.250'),
        );
        $this->assertNotSame(
            CustomerMessageBudget::subnetKey('192.0.2.10'),
            CustomerMessageBudget::subnetKey('192.0.3.10'),
        );
        $this->assertSame(
            CustomerMessageBudget::subnetKey('2001:db8:abcd:0001::1'),
            CustomerMessageBudget::subnetKey('2001:db8:abcd:ffff::9999'),
        );
        $this->assertNotSame(
            CustomerMessageBudget::subnetKey('2001:db8:abcd:0001::1'),
            CustomerMessageBudget::subnetKey('2001:db8:abce:0001::1'),
        );
        $this->assertNull(CustomerMessageBudget::subnetKey('invalid-ip'));
        $this->assertNull(CustomerMessageBudget::subnetKey(null));

        // Section 3: 50%, 80%, and 100% warning logs on global SMS budget
        DB::table('customer_message_budgets')->delete();
        config([
            'auth.customer_sms_daily_limit' => 5,
            'auth.customer_sms_hourly_limit' => 100,
            'auth.customer_daily_messages' => 10,
        ]);
        Log::spy();
        for ($i = 1; $i <= 5; $i++) {
            $this->assertTrue($budget->claim("order:sms:{$i}", sms: true));
        }
        // ceil(5 * 0.5) = 3 (50%), ceil(5 * 0.8) = 4 (80%), and 5 === 5 (exhausted)
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m) => $m === 'Customer SMS budget reached 50%.')->once();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m) => $m === 'Customer SMS budget reached 80%.')->once();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m) => $m === 'Customer SMS budget exhausted.')->once();
    }

    /**
     * 22 — فشل مزود الـ SMS (الحالات أ، ب، ج، د) وعدم تسرب التوكن أو الكود في اللوج
     */
    public function test_22_sms_provider_failures_return_503_retry_after_and_do_not_leak_secrets(): void
    {
        $channel = new \App\Notifications\Channels\CustomerSmsChannel;
        $customer = Customer::factory()->make(['phone' => '01012345678']);
        $otpCode = '654321';
        $notification = new \App\Notifications\CustomerPhoneVerificationCode($otpCode);
        $secretToken = 'super-secret-gateway-token-xyz';

        // (د) URL with http:// -> BusinessException 503 + Retry-After: 1
        Http::preventStrayRequests();
        config(['services.customer_sms.url' => 'http://insecure.example.test/sms', 'services.customer_sms.token' => $secretToken]);
        try {
            $channel->send($customer, $notification);
            $this->fail('HTTP URL must be rejected with 503.');
        } catch (BusinessException $e) {
            $this->assertSame(503, $e->status);
            $this->assertSame('1', $e->render()->headers->get('Retry-After'));
        }

        config(['services.customer_sms.url' => 'https://sms.example.test/send', 'services.customer_sms.token' => $secretToken]);

        // (أ) Connection timeout, (ب) HTTP 500, (ج) HTTP 200 with success:false
        Log::spy();
        $step = 0;
        Http::fake([
            'https://sms.example.test/send' => function () use (&$step) {
                $step++;
                if ($step === 1) {
                    throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
                }
                if ($step === 2) {
                    return Http::response(['error' => 'Internal Error'], 500);
                }

                return Http::response(['success' => false], 200);
            },
        ]);

        foreach (['timeout', 'http_500', 'success_false'] as $name) {
            Carbon::setTestNow(now()->addMinutes(5));

            try {
                app(CustomerPhoneVerificationService::class)->send('01012345678', CustomerMessageBudget::subnetKey('192.0.2.1'));
                $this->fail("Case {$name} must throw BusinessException 503.");
            } catch (BusinessException $e) {
                $this->assertSame(503, $e->status);
                $this->assertSame('1', $e->render()->headers->get('Retry-After'));
            }
        }

        // Ensure neither gateway token nor OTP code leaked into logs across all 3 failure cases
        Log::shouldHaveReceived('error')->withArgs(function (string $msg, array $context) use ($secretToken, $otpCode): bool {
            $serialized = json_encode([$msg, $context]);

            return $msg === 'Customer OTP delivery failed.'
                && ! str_contains($serialized, $secretToken)
                && ! str_contains($serialized, $otpCode);
        })->times(3);
    }

    /**
     * 26 & Section 3 — أمر توحيد أرقام الموبايل + اختبار خصائص (Property Test) ومطابقة PHP مع SQL
     */
    public function test_26_normalize_customer_phones_command_and_php_sql_equivalence(): void
    {
        // Drop phone CHECK constraint temporarily to seed legacy unnormalized phones
        DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customers_valid_phone');

        $cPlus = Customer::factory()->create(['phone' => '01011111111']);
        $cZero = Customer::factory()->create(['phone' => '01022222222']);
        $cLegacy = Customer::factory()->create(['phone' => '01033333333']);
        $cValid = Customer::factory()->create(['phone' => '01044444444']);

        DB::table('customers')->where('id', $cPlus->id)->update(['phone' => '+201012345678']);
        DB::table('customers')->where('id', $cZero->id)->update(['phone' => '00201098765432']);
        DB::table('customers')->where('id', $cLegacy->id)->update(['phone' => 'legacy-5']);

        // (أ) Dry-run without --apply -> SUCCESS, 0 modifications
        $this->artisan('customers:normalize-phones')->assertSuccessful();
        $this->assertSame('+201012345678', DB::table('customers')->where('id', $cPlus->id)->value('phone'));

        // With --apply -> normalizes +20 and 0020, keeps legacy-5 and valid phone untouched, writes phone_normalized audit
        $this->artisan('customers:normalize-phones', ['--apply' => true])->assertSuccessful();
        $this->assertSame('01012345678', DB::table('customers')->where('id', $cPlus->id)->value('phone'));
        $this->assertSame('01098765432', DB::table('customers')->where('id', $cZero->id)->value('phone'));
        $this->assertSame('legacy-5', DB::table('customers')->where('id', $cLegacy->id)->value('phone'));
        $this->assertSame('01044444444', DB::table('customers')->where('id', $cValid->id)->value('phone'));
        $this->assertDatabaseHas('customer_audit_logs', ['customer_id' => $cPlus->id, 'action' => 'phone_normalized']);
        $this->assertDatabaseHas('customer_audit_logs', ['customer_id' => $cZero->id, 'action' => 'phone_normalized']);
        $this->assertDatabaseMissing('customer_audit_logs', ['customer_id' => $cLegacy->id, 'action' => 'phone_normalized']);

        // (ب) Two numbers normalizing to the same value -> FAILURE & 0 modifications
        DB::table('customers')->where('id', $cZero->id)->update(['phone' => '+201012345678']);
        $this->artisan('customers:normalize-phones', ['--apply' => true])->assertFailed();
        $this->assertSame('+201012345678', DB::table('customers')->where('id', $cZero->id)->value('phone'));

        // (ج) Invalid number '123' -> FAILURE & 0 modifications
        DB::table('customers')->where('id', $cZero->id)->update(['phone' => '123']);
        $this->artisan('customers:normalize-phones', ['--apply' => true])->assertFailed();
        $this->assertSame('123', DB::table('customers')->where('id', $cZero->id)->value('phone'));

        // Fix cZero and restore constraint
        DB::table('customers')->where('id', $cZero->id)->update(['phone' => '01022222222']);
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_valid_phone CHECK (phone ~ '^01[0125][0-9]{8}$' OR phone ~ '^legacy-[0-9]+$') NOT VALID");

        // Section 3: Property test + PHP vs SQL equivalence (including NBSP \u{00A0} and spaces around legacy-)
        $rng = new Randomizer(new Mt19937(20261101));
        $prefixes = ['010', '011', '012', '015'];
        $arabicMap = ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩'];
        $persianMap = ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹'];

        $samples = [
            "\u{00A0}+20\u{00A0}101 234 5678\u{00A0}",
            "  \u{00A0}legacy-42\u{00A0}  ",
            '0020 (101) 234-5678',
            '20101.234.5678',
            '٠١٠١٢٣٤٥٦٧٨',
            '۰۱۰۱۲۳۴۵۶۷۸',
        ];

        for ($i = 0; $i < 100; $i++) {
            $canonical = $prefixes[$rng->getInt(0, 3)].sprintf('%08d', $rng->getInt(0, 99999999));
            $sub = substr($canonical, 1); // 10xxxxxxxx
            $variants = [
                $canonical,
                '20'.$sub,
                '+20'.$sub,
                '0020'.$sub,
                '+20 ('.substr($sub, 0, 2).') '.substr($sub, 2, 4).'-'.substr($sub, 6),
                "\u{00A0}".strtr($canonical, $arabicMap)."\u{00A0}",
                strtr('+20 '.$sub, $persianMap),
            ];
            foreach ($variants as $v) {
                $once = Customer::normalizePhone($v);
                $twice = Customer::normalizePhone($once);
                $this->assertSame($canonical, $once, "Failed normalizing variant: {$v}");
                $this->assertSame($once, $twice, "Idempotence failed for: {$v}");
            }
            $samples[] = $variants[$rng->getInt(0, count($variants) - 1)];
        }

        // Verify SQL expression in NormalizeCustomerPhones produces exact same result as Customer::normalizePhone for all samples
        foreach (array_slice($samples, 0, 25) as $sample) {
            $sqlResult = DB::selectOne("
                WITH cleaned AS (
                    SELECT ?::text AS phone,
                        regexp_replace(TRIM(?::text), '^[[:space:]\u{00A0}\u{1680}\u{2000}-\u{200A}\u{2028}\u{2029}\u{202F}\u{205F}\u{3000}]+|[[:space:]\u{00A0}\u{1680}\u{2000}-\u{200A}\u{2028}\u{2029}\u{202F}\u{205F}\u{3000}]+$', '', 'g') AS trimmed,
                        regexp_replace(translate(TRIM(?::text),
                            '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹', '01234567890123456789'), '[[:space:]\u{00A0}\u{1680}\u{2000}-\u{200A}\u{2028}\u{2029}\u{202F}\u{205F}\u{3000}().-]+', '', 'g') AS value
                )
                SELECT CASE
                    WHEN trimmed ~ '^legacy-[0-9]+$' THEN trimmed
                    WHEN value ~ '^201[0125][0-9]{8}$' THEN '0' || SUBSTRING(value FROM 3)
                    WHEN value LIKE '+20%' THEN '0' || SUBSTRING(value FROM 4)
                    WHEN value LIKE '0020%' THEN '0' || SUBSTRING(value FROM 5)
                    ELSE value END AS after FROM cleaned
            ", [$sample, $sample, $sample])->after;

            $this->assertSame(Customer::normalizePhone($sample), $sqlResult, "PHP vs SQL mismatch on sample: {$sample}");
        }
    }

    /**
     * 27 — أمر تنظيف الملفات والبيانات القديمة (customers:prune-artifacts)
     */
    public function test_27_prune_customer_artifacts_selective_deletion_and_storage_failure_isolation(): void
    {
        $disk = $this->fakeUploads();

        // 1. Pending folder 2 hours old vs 10 minutes old
        $oldPending = 'customer_documents/pending/'.Str::uuid();
        $recentPending = 'customer_documents/pending/'.Str::uuid();
        $disk->put($oldPending.'/doc.pdf', 'old');
        $disk->put($recentPending.'/doc.pdf', 'recent');
        touch($disk->path($oldPending.'/doc.pdf'), now()->subHours(2)->timestamp);
        touch($disk->path($recentPending.'/doc.pdf'), now()->subMinutes(10)->timestamp);

        // 2. Orphan customer_documents/999999 vs soft-deleted customer folder
        $orphanDir = 'customer_documents/999999';
        $disk->put($orphanDir.'/orphan.pdf', 'orphan');
        touch($disk->path($orphanDir.'/orphan.pdf'), now()->subHours(2)->timestamp);

        $trashedCustomer = Customer::factory()->create();
        $trashedCustomer->delete();
        $trashedDir = 'customer_documents/'.$trashedCustomer->id;
        $disk->put($trashedDir.'/kept.pdf', 'kept');
        touch($disk->path($trashedDir.'/kept.pdf'), now()->subHours(2)->timestamp);

        // 3. Phone verification expired 2 hours ago
        DB::table('customer_phone_verifications')->insert([
            'phone_key' => hash('sha256', 'expired-phone'),
            'code_hash' => 'hash',
            'created_at' => now()->subHours(3),
            'expires_at' => now()->subHours(2),
        ]);

        // 4. Message budget row 31 days old
        DB::table('customer_message_budgets')->insert([
            'key' => hash('sha256', 'old-budget'),
            'date' => now()->subDays(31)->toDateString(),
            'sends' => 5,
        ]);

        // 5. Audit login_failed 91 days old vs audit approved 200 days old
        $activeCustomer = Customer::factory()->create();
        $loginFailedLog = CustomerAuditLog::create([
            'customer_id' => $activeCustomer->id,
            'action' => 'login_failed',
            'metadata' => [],
        ]);
        DB::table('customer_audit_logs')->where('id', $loginFailedLog->id)->update(['created_at' => now()->subDays(91)]);

        $approvedLog = CustomerAuditLog::create([
            'customer_id' => $activeCustomer->id,
            'action' => 'approved',
            'metadata' => [],
        ]);
        DB::table('customer_audit_logs')->where('id', $approvedLog->id)->update(['created_at' => now()->subDays(200)]);

        $this->artisan('customers:prune-artifacts')->assertSuccessful();

        // Deleted:
        $disk->assertMissing($oldPending.'/doc.pdf');
        $disk->assertMissing($orphanDir.'/orphan.pdf');
        $this->assertDatabaseCount('customer_phone_verifications', 0);
        $this->assertDatabaseCount('customer_message_budgets', 0);
        $this->assertDatabaseMissing('customer_audit_logs', ['id' => $loginFailedLog->id]);

        // Kept:
        $disk->assertExists($recentPending.'/doc.pdf');
        $disk->assertExists($trashedDir.'/kept.pdf');
        $this->assertDatabaseHas('customer_audit_logs', ['id' => $approvedLog->id]);

        // 6. When directories() throws an exception -> DB cleanup still completes, command returns FAILURE
        DB::table('customer_phone_verifications')->insert([
            'phone_key' => hash('sha256', 'expired-phone-2'),
            'code_hash' => 'hash',
            'created_at' => now()->subHours(3),
            'expires_at' => now()->subHours(2),
        ]);

        $failingDisk = \Mockery::mock(FilesystemAdapter::class);
        $failingDisk->shouldReceive('directories')->andThrow(new \RuntimeException('R2 outage'));
        Storage::shouldReceive('disk')->with('r2')->andReturn($failingDisk);

        $this->artisan('customers:prune-artifacts')->assertFailed();
        $this->assertDatabaseCount('customer_phone_verifications', 0);
    }

    /**
     * Section 3 — استعادة كلمة المرور على مستوى الخدمة (بما فيها توحيد الموبايل +2010...)
     */
    public function test_section3_password_reset_service_level_edge_cases(): void
    {
        $this->freezeTime();
        $auth = app(CustomerAuthService::class);

        $trader = Customer::factory()->wholesale()->create([
            'phone' => '01012345678',
            'email' => 'trader@example.com',
            'email_verified_at' => now(),
            'password' => 'old-password-123',
        ]);
        $trader->createToken('active-tok');

        // Future created_at token is rejected
        $plain = Str::random(64);
        DB::table('password_reset_tokens')->insert([
            'email' => $trader->getEmailForPasswordReset(),
            'token' => hash('sha256', $plain),
            'created_at' => now()->addMinute(),
        ]);
        try {
            $auth->resetPassword(['phone' => '+20 101 234 5678', 'token' => $plain, 'password' => 'new-password-123']);
            $this->fail('Future reset token must be rejected.');
        } catch (BusinessException $e) {
            $this->assertSame(422, $e->status);
        }

        // Valid created_at, but wholesale password < 12 chars -> ValidationException
        DB::table('password_reset_tokens')->where('email', $trader->getEmailForPasswordReset())->update([
            'created_at' => now(),
        ]);
        try {
            $auth->resetPassword(['phone' => '+20 101 234 5678', 'token' => $plain, 'password' => 'short-8c']);
            $this->fail('Wholesale password < 12 chars must fail.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('password', $e->errors());
        }

        // Valid reset with unnormalized phone '+20 101 234 5678' -> normalizes phone, updates password, revokes tokens, deletes reset row
        $auth->resetPassword(['phone' => '+20 101 234 5678', 'token' => $plain, 'password' => 'new-strong-password-123']);
        $this->assertTrue(Hash::check('new-strong-password-123', $trader->fresh()->password));
        $this->assertSame(0, $trader->tokens()->count());
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    /**
     * 32 — أداء قائمة العملاء والسجل (ثبات عدد الاستعلامات بدون N+1 واستخدام فهرس customers_search_trgm_index)
     */
    public function test_32_customer_list_show_and_history_query_performance_and_trgm_index(): void
    {
        Permission::findOrCreate('customers.view', 'staff');
        $staff = User::factory()->create(['branch_id' => null]);
        $staff->givePermissionTo('customers.view');
        $token = $staff->createToken('staff')->plainTextToken;

        $group = CustomerGroup::create(['name' => 'G-Perf', 'code' => 'g-perf']);
        $customers = Customer::factory()->count(5)->wholesale()->create(['customer_group_id' => $group->id]);
        foreach ($customers as $c) {
            CustomerAuditLog::record($c, 'created', null);
        }

        // Baseline queries with 5 customers
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->withToken($token)->getJson('/api/v1/admin/customers?search=010')->assertOk();
        $indexBaseline = count(DB::getQueryLog());

        Auth::forgetGuards();
        DB::flushQueryLog();
        $this->withToken($token)->getJson('/api/v1/admin/customers/'.$customers[0]->id)->assertOk();
        $showBaseline = count(DB::getQueryLog());

        Auth::forgetGuards();
        DB::flushQueryLog();
        $this->withToken($token)->getJson('/api/v1/admin/customers/'.$customers[0]->id.'/history')->assertOk();
        $historyBaseline = count(DB::getQueryLog());

        // Add 20 more customers with documents, addresses, and audit logs
        $more = Customer::factory()->count(20)->wholesale()->create(['customer_group_id' => $group->id]);
        foreach ($more as $c) {
            $c->documents()->create([
                'document_type' => CustomerDocumentType::CommercialRegister,
                'file_path' => 'doc.pdf', 'original_name' => 'doc.pdf', 'mime_type' => 'application/pdf',
            ]);
            for ($i = 0; $i < 5; $i++) {
                CustomerAuditLog::record($customers[0], 'updated', null);
            }
        }

        Auth::forgetGuards();
        DB::flushQueryLog();
        $this->withToken($token)->getJson('/api/v1/admin/customers?search=010')->assertOk();
        $this->assertLessThanOrEqual($indexBaseline, count(DB::getQueryLog()));

        Auth::forgetGuards();
        DB::flushQueryLog();
        $this->withToken($token)->getJson('/api/v1/admin/customers/'.$customers[0]->id)->assertOk();
        $this->assertLessThanOrEqual($showBaseline, count(DB::getQueryLog()));

        Auth::forgetGuards();
        DB::flushQueryLog();
        $this->withToken($token)->getJson('/api/v1/admin/customers/'.$customers[0]->id.'/history')->assertOk();
        $this->assertLessThanOrEqual($historyBaseline, count(DB::getQueryLog()));
        DB::disableQueryLog();

        // Verify customers_search_trgm_index exists and is used when seqscan is disabled
        DB::transaction(function (): void {
            DB::statement('SET LOCAL enable_seqscan = off');
            $plan = json_encode(DB::select("EXPLAIN (FORMAT JSON) SELECT id FROM customers WHERE search_text ILIKE '%010%'"));
            $this->assertStringContainsString('customers_search_trgm_index', $plan);
        });
    }
}
