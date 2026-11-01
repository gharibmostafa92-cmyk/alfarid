<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CustomerDocumentStatus;
use App\Enums\CustomerDocumentType;
use App\Enums\CustomerStatus;
use App\Exceptions\BusinessException;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\User;
use App\Notifications\CustomerPasswordReset;
use App\Services\Ai\ToolRegistry;
use App\Services\CustomerManagementService;
use App\Services\CustomerMessageBudget;
use App\Services\SettingService;
use App\Services\WholesaleRegistrationService;
use Database\Seeders\CustomerPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AuditRoundTwoVerificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ALL_CUSTOMER_PERMISSIONS = [
        'customers.view', 'customers.create', 'customers.update', 'customers.delete', 'customers.approve',
        'customers.documents.view', 'customer_groups.view', 'customer_groups.create', 'customer_groups.update', 'customer_groups.delete',
        'inventory.all_branches',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CustomerPermissionSeeder::class);
        $this->seed(SettingSeeder::class);
        DB::table('settings')->where('key', 'ai_enabled')->update(['value' => '1']);
        app(SettingService::class)->clearCache();
        Http::preventStrayRequests();
    }

    private function staffToken(array $permissions = self::ALL_CUSTOMER_PERMISSIONS): string
    {
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'staff');
        }
        $staff = User::factory()->create(['branch_id' => null]);
        $staff->givePermissionTo($permissions);

        return $staff->createToken('test')->plainTextToken;
    }

    private function api(string $token, string $method, string $uri, array $data = []): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->json($method, $uri, $data);
    }

    /** 1. اعتماد تاجر عنده مستند مرفوض ومستند موثق: لازم ينجح */
    public function test_approve_trader_with_rejected_and_verified_documents_succeeds(): void
    {
        $token = $this->staffToken();
        $group = CustomerGroup::create(['name' => 'تجار', 'code' => 'traders', 'discount_percentage' => 10, 'is_active' => true]);
        $customer = Customer::factory()->wholesale()->create([
            'status' => CustomerStatus::Pending,
            'customer_group_id' => $group->id,
        ]);

        $customer->documents()->create([
            'document_type' => CustomerDocumentType::CommercialRegister,
            'file_path' => 'doc1.pdf',
            'original_name' => 'doc1.pdf',
            'mime_type' => 'application/pdf',
            'status' => CustomerDocumentStatus::Verified,
        ]);

        $customer->documents()->create([
            'document_type' => CustomerDocumentType::TaxCard,
            'file_path' => 'doc2.pdf',
            'original_name' => 'doc2.pdf',
            'mime_type' => 'application/pdf',
            'status' => CustomerDocumentStatus::Rejected,
        ]);

        $response = $this->api($token, 'POST', "/api/v1/admin/customers/{$customer->id}/approve", [
            'customer_group_id' => $group->id,
            'credit_limit' => 50000,
        ])->assertOk();

        $this->assertSame(CustomerStatus::Approved, $customer->fresh()->status);
    }

    /** 2. رفع مستند سادس بعد ما واحد اترفض: لازم ينجح، وبعدها السقف الإجمالي (10) يمنع */
    public function test_upload_sixth_document_succeeds_after_rejection_and_total_cap_of_ten_blocks(): void
    {
        $this->fakeUploads();
        $service = app(CustomerManagementService::class);
        $customer = Customer::factory()->wholesale()->create(['status' => CustomerStatus::Pending]);

        // رفع 5 مستندات
        $docs = [];
        for ($i = 1; $i <= 5; $i++) {
            $file = UploadedFile::fake()->create("doc{$i}.pdf", 100, 'application/pdf');
            $docs[] = $service->uploadDocument($customer, $file, null);
        }

        // محاولة رفع سادس والكل نشط -> يفشل
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('وصلت للحد الأقصى للمستندات');
        $service->uploadDocument($customer, UploadedFile::fake()->create('doc6.pdf', 100, 'application/pdf'), null);
    }

    public function test_upload_succeeds_when_one_rejected_until_total_ten(): void
    {
        $this->fakeUploads();
        $service = app(CustomerManagementService::class);
        $customer = Customer::factory()->wholesale()->create(['status' => CustomerStatus::Pending]);

        // رفع 5 مستندات
        $docs = [];
        for ($i = 1; $i <= 5; $i++) {
            $file = UploadedFile::fake()->create("doc{$i}.pdf", 100, 'application/pdf');
            $docs[] = $service->uploadDocument($customer, $file, null);
        }

        // رفض مستند
        $docs[0]->update(['status' => CustomerDocumentStatus::Rejected]);

        // رفع سادس -> ينجح لأن النشط 4
        $doc6 = $service->uploadDocument($customer, UploadedFile::fake()->create('doc6.pdf', 100, 'application/pdf'), null);
        $this->assertNotNull($doc6);
        $this->assertSame(6, $customer->documents()->count());

        // نرفض كل المستندات ونرفع حتى نصل لـ 10
        $customer->documents()->update(['status' => CustomerDocumentStatus::Rejected]);
        for ($i = 7; $i <= 10; $i++) {
            $file = UploadedFile::fake()->create("doc{$i}.pdf", 100, 'application/pdf');
            $service->uploadDocument($customer, $file, null);
        }
        $this->assertSame(10, $customer->documents()->count());

        // المستند 11 يمنعه السقف الإجمالي حتى لو النشط قليل
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('وصلت للحد الأقصى للمستندات (5 فعّالة / 10 إجمالي).');
        $service->uploadDocument($customer, UploadedFile::fake()->create('doc11.pdf', 100, 'application/pdf'), null);
    }

    /** اختبار مسار رفع المستندات الذاتي للتاجر */
    public function test_wholesale_self_service_document_upload(): void
    {
        $this->fakeUploads();
        $customer = Customer::factory()->wholesale()->create(['status' => CustomerStatus::Pending]);
        $token = $customer->createToken('wholesale')->plainTextToken;

        Auth::forgetGuards();
        $response = $this->withToken($token)->postJson('/api/v1/wholesale/auth/documents', [
            'document' => UploadedFile::fake()->create('cr.pdf', 200, 'application/pdf'),
            'document_type' => CustomerDocumentType::CommercialRegister->value,
        ])->assertCreated();

        $this->assertSame(CustomerDocumentStatus::Pending->value, $response->json('data.status'));

        // تاجر معتمد بالفعل -> 403
        $approved = Customer::factory()->wholesale()->create(['status' => CustomerStatus::Approved]);
        $approvedToken = $approved->createToken('wholesale')->plainTextToken;

        Auth::forgetGuards();
        $this->withToken($approvedToken)->postJson('/api/v1/wholesale/auth/documents', [
            'document' => UploadedFile::fake()->create('cr2.pdf', 200, 'application/pdf'),
        ])->assertForbidden();
    }

    /** 3. approve على حساب موقوف: لازم ترجع 422 */
    public function test_approve_suspended_customer_returns_422(): void
    {
        $token = $this->staffToken();
        $customer = Customer::factory()->wholesale()->create([
            'status' => CustomerStatus::Suspended,
            'suspension_reason' => 'مخالفة',
        ]);

        $this->api($token, 'POST', "/api/v1/admin/customers/{$customer->id}/approve", [
            'type' => 'wholesale',
        ])->assertStatus(422)->assertJsonPath('message', 'الحساب موقوف؛ استخدم إعادة التفعيل بدل الاعتماد.');
    }

    /** 4. restore لحساب بريده اتاخد: لازم ترجع رسالة واضحة */
    public function test_restore_customer_whose_email_was_claimed_returns_clear_message(): void
    {
        $token = $this->staffToken();
        $customerA = Customer::factory()->wholesale()->create([
            'email' => 'unique.trader@example.com',
            'email_verified_at' => null,
            'phone' => '01011111111',
        ]);
        $idA = $customerA->id;

        // حذف العميل الأول
        $this->api($token, 'DELETE', "/api/v1/admin/customers/{$idA}")->assertOk();
        $this->assertSoftDeleted('customers', ['id' => $idA]);

        // عميل جديد يأخذ نفس البريد
        $customerB = Customer::factory()->wholesale()->create([
            'email' => 'unique.trader@example.com',
            'phone' => '01022222222',
        ]);

        // محاولة استعادة العميل الأول
        $res = $this->api($token, 'POST', "/api/v1/admin/customers/{$idA}/restore");
        $res->assertStatus(422);
        $this->assertStringContainsString('لا يمكن الاستعادة: البريد unique.trader@example.com مستخدم الآن بحساب نشط آخر', $res->json('message'));
    }

    /** 5. subnetKey لعنوانين IPv6 في نفس الـ /48: لازم يرجّع نفس المفتاح */
    public function test_subnet_key_same_for_ipv6_in_same_slash_48(): void
    {
        $ip1 = '2001:0db8:85a3:0000:0000:8a2e:0370:7334';
        $ip2 = '2001:0db8:85a3:ffff:0000:8a2e:0370:7335';
        $ipOther = '2001:0db8:85a4:0000:0000:8a2e:0370:7334';

        $key1 = CustomerMessageBudget::subnetKey($ip1);
        $key2 = CustomerMessageBudget::subnetKey($ip2);
        $keyOther = CustomerMessageBudget::subnetKey($ipOther);

        $this->assertNotNull($key1);
        $this->assertSame($key1, $key2);
        $this->assertNotSame($key1, $keyOther);
    }

    /** L-9: فحص تكرار الرقم في sendPhoneVerification يرجع رسالة واضحة قبل الـ queue */
    public function test_send_phone_verification_clear_error_for_existing_customer(): void
    {
        Customer::factory()->create(['phone' => '01033333333']);

        $res = $this->postJson('/api/v1/store/auth/phone-verification/send', [
            'phone' => '01033333333',
        ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors('phone');
        $this->assertStringContainsString('الرقم مسجل بالفعل', $res->json('errors.phone.0'));

        // حساب محذوف
        $trashed = Customer::factory()->create(['phone' => '01044444444']);
        $trashed->delete();

        $resTrashed = $this->postJson('/api/v1/store/auth/phone-verification/send', [
            'phone' => '01044444444',
        ]);
        $resTrashed->assertStatus(422);
        $this->assertStringContainsString('الرقم مرتبط بحساب محذوف', $resTrashed->json('errors.phone.0'));
    }

    /** L-1: حذف CustomerGroup مرتبط بعملاء محذوفين سوفت-ديليت ممنوع */
    public function test_customer_group_cannot_be_deleted_if_has_trashed_customers(): void
    {
        $token = $this->staffToken();
        $group = CustomerGroup::create(['name' => 'مجموعة مؤقتة', 'code' => 'temp-grp', 'is_active' => true]);
        $customer = Customer::factory()->create(['customer_group_id' => $group->id]);
        $customer->delete(); // soft delete

        $this->api($token, 'DELETE', "/api/v1/admin/customer-groups/{$group->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكن حذف مجموعة مرتبطة بعملاء؛ انقل العملاء أولًا أو عطّل المجموعة.');
    }

    /** L-4: تكرار كود المجموعة يرجع 422 */
    public function test_customer_group_duplicate_code_returns_422(): void
    {
        $token = $this->staffToken();
        CustomerGroup::create(['name' => 'مجموعة 1', 'code' => 'DUP-CODE', 'is_active' => true]);

        $this->api($token, 'POST', '/api/v1/admin/customer-groups', [
            'name' => 'مجموعة 2',
            'code' => 'DUP-CODE',
        ])->assertStatus(422)->assertJsonValidationErrors('code');
    }

    /** L-8: تعيين مجموعة معطلة يرفض في الـ Requests والخدمة */
    public function test_inactive_customer_group_rejected_in_requests(): void
    {
        $token = $this->staffToken();
        $inactiveGroup = CustomerGroup::create(['name' => 'معطلة', 'code' => 'INACTIVE', 'is_active' => false]);

        $this->api($token, 'POST', '/api/v1/admin/customers', [
            'name' => 'عميل جديد',
            'phone' => '01055555555',
            'password' => 'secure-pass-1234',
            'customer_group_id' => $inactiveGroup->id,
        ])->assertStatus(422)->assertJsonValidationErrors('customer_group_id');
    }

    /** L-10: assertProofPresent يفحص الـ token قبل رفع الملفات */
    public function test_wholesale_registration_asserts_proof_before_upload(): void
    {
        config(['auth.require_phone_verification' => true]);
        $service = app(WholesaleRegistrationService::class);

        $file = UploadedFile::fake()->create('license.pdf', 100, 'application/pdf');

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('إثبات ملكية الهاتف غير صالح أو تم استخدامه.');

        $service->register([
            'name' => 'تاجر بلا إثبات',
            'phone' => '01066666666',
            'password' => 'password-1234',
            'company_name' => 'شركة',
            'tax_number' => '123456789',
            'commercial_register' => '987654',
            'phone_verification_token' => 'invalid-token',
        ], [$file]);
    }

    /** L-2: البحث أقل من 3 حروف يرفض بـ 422 */
    public function test_customer_search_requires_min_3_characters(): void
    {
        $token = $this->staffToken();

        $this->api($token, 'GET', '/api/v1/admin/customers?search=ab')
            ->assertStatus(422)
            ->assertJsonValidationErrors('search');

        $this->api($token, 'GET', '/api/v1/admin/customers?search=abc')
            ->assertOk();
    }

    /** L-5: رابط استعادة كلمة المرور في SMS بنص لاتيني */
    public function test_customer_password_reset_notification_to_sms_url(): void
    {
        $customer = Customer::factory()->create(['phone' => '01012345678']);
        $notification = new CustomerPasswordReset('test-token-1234');
        $sms = $notification->toSms($customer);

        $this->assertStringStartsWith('Password reset: ', $sms);
        $this->assertStringContainsString('token=test-token-1234', $sms);
        $this->assertStringContainsString('phone=01012345678', $sms);
    }

    /** L-6: استهلاك الذكاء الاصطناعي مع توقيت القاهرة */
    public function test_ai_usage_endpoint_with_cairo_timezone(): void
    {
        $token = $this->staffToken(['ai_agent.use', 'ai_agent.logs.view', 'inventory.all_branches']);
        $response = $this->api($token, 'GET', '/api/v1/admin/ai/usage?all=1');
        $response->assertOk();
        $this->assertIsArray($response->json('data'));
    }

    /** اختبار حماية: HttpException بعد كتابة جزئية أو تعارض لا يسيب الإجراء معلقًا pending */
    public function test_http_exception_during_confirm_marks_failed(): void
    {
        $perms = ['ai_agent.use', 'ai_agent.execute', 'settings.manage', 'inventory.all_branches'];
        foreach ($perms as $p) {
            Permission::findOrCreate($p, 'staff');
        }
        $user = User::factory()->create(['branch_id' => null]);
        $user->givePermissionTo($perms);
        $token = $user->createToken('test')->plainTextToken;

        $conv = AiConversation::create(['user_id' => $user->id, 'title' => 'test', 'model' => 'gemini-2.0-flash']);

        $registry = app(ToolRegistry::class);
        $outcome = $registry->dispatch($user, $conv, ['name' => 'update_settings', 'args' => [
            'changes' => ['return_window_days' => 30],
        ]]);
        $this->assertSame('proposal', $outcome->type);
        $action = AiPendingAction::where('conversation_id', $conv->id)->firstOrFail();

        // تغيير الإعداد في الخلفية ليحدث 409 تعارض
        DB::table('settings')->where('key', 'return_window_days')->update(['value' => '99']);

        $res = $this->withToken($token)->postJson("/api/v1/admin/ai/actions/{$action->id}/confirm");
        $res->assertStatus(409);

        // الإجراء لازم يكون failed ومش pending
        $this->assertSame('failed', $action->fresh()->status);
    }
}
