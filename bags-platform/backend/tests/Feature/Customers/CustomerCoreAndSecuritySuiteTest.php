<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Enums\CustomerDocumentStatus;
use App\Enums\CustomerDocumentType;
use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Events\WholesaleApplicationReviewed;
use App\Events\WholesaleApplicationSubmitted;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerAuditLog;
use App\Models\CustomerDocument;
use App\Models\CustomerGroup;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Notifications\Wholesale\WholesaleApplicationReviewedNotification;
use App\Notifications\Wholesale\WholesaleApplicationSubmittedNotification;
use App\Services\Auth\CustomerAuthService;
use App\Services\Customer\CustomerManagementService;
use App\Services\Auth\StaffAdministration;
use Database\Seeders\CustomerPermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CustomerCoreAndSecuritySuiteTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ALL_PERMS = [
        'customers.view',
        'customers.create',
        'customers.update',
        'customers.delete',
        'customers.approve',
        'customers.documents.view',
        'customer_groups.view',
        'customer_groups.create',
        'customer_groups.update',
        'customer_groups.delete',
        'inventory.all_branches',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeUploads();
        $this->seed(CustomerPermissionSeeder::class);
    }

    private function createStaff(array $permissions = [], array $attributes = []): array
    {
        foreach ($permissions as $perm) {
            Permission::findOrCreate($perm, 'staff');
        }
        $user = User::factory()->create(['branch_id' => null, ...$attributes]);
        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return [$user, $user->createToken('staff-token')->plainTextToken];
    }

    private function apiAs(string $token, string $method, string $uri, array $data = []): TestResponse
    {
        Auth::forgetGuards();
        app('auth')->shouldUse('web');

        return $this->withToken($token)->json($method, $uri, $data);
    }

    /**
     * 1 ش
     */
    public function test_01_suspend_approve_and_reactivate_persist_pre_suspension_status_on_fresh_schema(): void
    {
        [, $token] = $this->createStaff(['customers.approve', 'customers.view']);
        $customer = Customer::factory()->wholesale()->create([
            'status' => CustomerStatus::Pending,
            'company_name' => 'شركة الاختبار للتجارة',
            'tax_number' => '123456789',
            'commercial_register' => '987654',
        ]);
        $customer->documents()->create([
            'document_type' => CustomerDocumentType::CommercialRegister,
            'file_path' => 'customer_documents/'.$customer->id.'/cr.pdf',
            'original_name' => 'cr.pdf',
            'mime_type' => 'application/pdf',
            'status' => CustomerDocumentStatus::Verified,
        ]);

        // 1. Approve
        $this->apiAs($token, 'POST', "/api/v1/admin/customers/{$customer->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
        $this->assertNull(DB::table('customers')->where('id', $customer->id)->value('pre_suspension_status'));

        // 2. Suspend with reason
        $this->apiAs($token, 'POST', "/api/v1/admin/customers/{$customer->id}/suspend", ['reason' => 'مراجعة أمنية'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');
        $this->assertSame('approved', DB::table('customers')->where('id', $customer->id)->value('pre_suspension_status'));

        // 3. Reactivate
        $this->apiAs($token, 'POST', "/api/v1/admin/customers/{$customer->id}/reactivate")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
        $row = DB::table('customers')->where('id', $customer->id)->first();
        $this->assertSame('approved', $row->status);
        $this->assertNull($row->pre_suspension_status);
        $this->assertNull($row->suspension_reason);
    }

    /**
     * 2 — مصفوفة الصلاحيات لكل endpoint في العملاء (admin.php 236–248)
     */
    public function test_02_permission_matrix_for_every_customer_endpoint(): void
    {
        [, $none] = $this->createStaff([]);
        [, $view] = $this->createStaff(['customers.view']);
        [, $create] = $this->createStaff(['customers.create']);
        [, $update] = $this->createStaff(['customers.update']);
        [, $approve] = $this->createStaff(['customers.approve']);
        [, $docsView] = $this->createStaff(['customers.documents.view']);
        [, $delete] = $this->createStaff(['customers.delete']);
        [, $docsApprove] = $this->createStaff(['customers.documents.view', 'customers.approve']);
        [, $docsUpdate] = $this->createStaff(['customers.documents.view', 'customers.update']);

        $customer = Customer::factory()->wholesale()->create(['status' => CustomerStatus::Pending]);
        $doc = $customer->documents()->create([
            'document_type' => CustomerDocumentType::TaxCard,
            'file_path' => 'customer_documents/'.$customer->id.'/tax.pdf',
            'original_name' => 'tax.pdf',
            'mime_type' => 'application/pdf',
            'status' => CustomerDocumentStatus::Verified,
        ]);
        Storage::disk('r2')->put($doc->file_path, 'pdf-content');

        // index, show, history -> require customers.view
        foreach ([$none, $create, $update, $approve, $docsView, $delete] as $tok) {
            $this->apiAs($tok, 'GET', '/api/v1/admin/customers')->assertForbidden();
            $this->apiAs($tok, 'GET', "/api/v1/admin/customers/{$customer->id}")->assertForbidden();
            $this->apiAs($tok, 'GET', "/api/v1/admin/customers/{$customer->id}/history")->assertForbidden();
        }
        $this->apiAs($view, 'GET', '/api/v1/admin/customers')->assertOk();
        $this->apiAs($view, 'GET', "/api/v1/admin/customers/{$customer->id}")->assertOk();
        $this->apiAs($view, 'GET', "/api/v1/admin/customers/{$customer->id}/history")->assertOk();

        // store -> requires customers.create
        $storePayload = ['name' => 'عميل جديد', 'phone' => '01098765432', 'password' => 'secure-pass-1234'];
        foreach ([$none, $view, $update, $approve, $docsView, $delete] as $tok) {
            $this->apiAs($tok, 'POST', '/api/v1/admin/customers', $storePayload)->assertForbidden();
        }
        $this->assertDatabaseMissing('customers', ['phone' => '01098765432']);
        $this->apiAs($create, 'POST', '/api/v1/admin/customers', $storePayload)->assertCreated();

        // update -> requires customers.update
        foreach ([$none, $view, $create, $approve, $docsView, $delete] as $tok) {
            $this->apiAs($tok, 'PUT', "/api/v1/admin/customers/{$customer->id}", ['name' => 'محاولة غير مصرحة'])->assertForbidden();
        }
        $this->assertNotSame('محاولة غير مصرحة', $customer->fresh()->name);
        $this->apiAs($update, 'PUT', "/api/v1/admin/customers/{$customer->id}", ['name' => 'اسم معدل'])->assertOk();

        // approve, reject, suspend, reactivate -> require customers.approve
        foreach ([$none, $view, $create, $update, $docsView, $delete] as $tok) {
            $this->apiAs($tok, 'POST', "/api/v1/admin/customers/{$customer->id}/approve")->assertForbidden();
            $this->apiAs($tok, 'POST', "/api/v1/admin/customers/{$customer->id}/reject", ['reason' => 'سبب'])->assertForbidden();
            $this->apiAs($tok, 'POST', "/api/v1/admin/customers/{$customer->id}/suspend", ['reason' => 'سبب'])->assertForbidden();
            $this->apiAs($tok, 'POST', "/api/v1/admin/customers/{$customer->id}/reactivate")->assertForbidden();
        }
        $this->assertSame(CustomerStatus::Pending, $customer->fresh()->status);

        // downloadDocument -> requires customers.documents.view
        foreach ([$none, $view, $create, $update, $approve, $delete] as $tok) {
            $this->apiAs($tok, 'GET', "/api/v1/admin/customers/{$customer->id}/documents/{$doc->id}/download")->assertForbidden();
        }
        $this->apiAs($docsView, 'GET', "/api/v1/admin/customers/{$customer->id}/documents/{$doc->id}/download")->assertOk();

        // reviewDocument -> requires customers.documents.view AND customers.approve
        foreach ([$none, $view, $create, $update, $approve, $docsView, $delete, $docsUpdate] as $tok) {
            $this->apiAs($tok, 'PATCH', "/api/v1/admin/customers/{$customer->id}/documents/{$doc->id}", ['status' => 'verified'])->assertForbidden();
        }
        $this->apiAs($docsApprove, 'PATCH', "/api/v1/admin/customers/{$customer->id}/documents/{$doc->id}", ['status' => 'verified'])->assertOk();

        // storeDocument -> requires customers.documents.view AND customers.update
        $filePayload = ['document' => UploadedFile::fake()->create('extra.pdf', 50, 'application/pdf')];
        foreach ([$none, $view, $create, $update, $approve, $docsView, $delete, $docsApprove] as $tok) {
            $this->apiAs($tok, 'POST', "/api/v1/admin/customers/{$customer->id}/documents", $filePayload)->assertForbidden();
        }
        $this->assertSame(1, $customer->documents()->count());
        $this->apiAs($docsUpdate, 'POST', "/api/v1/admin/customers/{$customer->id}/documents", $filePayload)->assertCreated();
        $this->assertSame(2, $customer->documents()->count());
        // Verify newly uploaded doc so approve test can proceed
        $customer->documents()->update(['status' => CustomerDocumentStatus::Verified]);

        // Test approve/suspend/reactivate/reject with $approve token
        $this->apiAs($approve, 'POST', "/api/v1/admin/customers/{$customer->id}/reject", ['reason' => 'مرفوض مؤقتًا'])->assertOk();
        $this->apiAs($approve, 'POST', "/api/v1/admin/customers/{$customer->id}/approve")->assertOk();
        $this->apiAs($approve, 'POST', "/api/v1/admin/customers/{$customer->id}/suspend", ['reason' => 'إيقاف'])->assertOk();
        $this->apiAs($approve, 'POST', "/api/v1/admin/customers/{$customer->id}/reactivate")->assertOk();

        // destroy & restore -> require customers.delete
        foreach ([$none, $view, $create, $update, $approve, $docsView] as $tok) {
            $this->apiAs($tok, 'DELETE', "/api/v1/admin/customers/{$customer->id}")->assertForbidden();
        }
        $this->assertNotSoftDeleted('customers', ['id' => $customer->id]);
        $this->apiAs($delete, 'DELETE', "/api/v1/admin/customers/{$customer->id}")->assertOk();
        $this->assertSoftDeleted('customers', ['id' => $customer->id]);

        foreach ([$none, $view, $create, $update, $approve, $docsView] as $tok) {
            $this->apiAs($tok, 'POST', "/api/v1/admin/customers/{$customer->id}/restore")->assertForbidden();
        }
        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
        $this->apiAs($delete, 'POST', "/api/v1/admin/customers/{$customer->id}/restore")->assertOk();
        $this->assertNotSoftDeleted('customers', ['id' => $customer->id]);
    }

    /**
     * 3 — الحقول الحساسة في الإنشاء والتعديل محتاجة صلاحية customers.approve
     */
    public function test_03_sensitive_fields_in_store_and_update_require_approve_permission(): void
    {
        [, $token] = $this->createStaff(['customers.create', 'customers.update']);
        $group = CustomerGroup::create(['name' => 'G1', 'code' => 'g1', 'is_active' => true]);

        // Store sensitive fields -> 403
        $baseStore = ['name' => 'عميل', 'phone' => '01011223344', 'password' => 'secure-pass-1234'];
        foreach ([
            ['credit_limit' => 1000],
            ['customer_group_id' => $group->id],
            ['type' => 'distributor'],
        ] as $extra) {
            $this->apiAs($token, 'POST', '/api/v1/admin/customers', [...$baseStore, ...$extra])->assertForbidden();
        }
        $this->assertDatabaseMissing('customers', ['phone' => '01011223344']);

        // Update sensitive fields -> 403
        $customer = Customer::factory()->wholesale()->create([
            'name' => 'الاسم الأصلي',
            'email' => 'orig@example.com',
            'phone' => '01055667788',
            'tax_number' => '111222333',
            'credit_limit' => 0,
        ]);
        $origPasswordHash = $customer->password;

        foreach ([
            ['password' => 'new-password-1234'],
            ['email' => 'changed@example.com'],
            ['phone' => '01099887766'],
            ['tax_number' => '999888777'],
            ['company_name' => 'شركة مختلفة'],
            ['commercial_register' => '999999'],
            ['credit_limit' => 5000],
            ['customer_group_id' => $group->id],
        ] as $payload) {
            $this->apiAs($token, 'PUT', "/api/v1/admin/customers/{$customer->id}", $payload)->assertForbidden();
        }

        $fresh = $customer->fresh();
        $this->assertSame('orig@example.com', $fresh->email);
        $this->assertSame('01055667788', $fresh->phone);
        $this->assertSame('111222333', $fresh->tax_number);
        $this->assertSame('0.00', $fresh->credit_limit);
        $this->assertSame($origPasswordHash, $fresh->password);

        // Update name only -> 200
        $this->apiAs($token, 'PUT', "/api/v1/admin/customers/{$customer->id}", ['name' => 'الاسم الجديد'])
            ->assertOk()
            ->assertJsonPath('data.name', 'الاسم الجديد');
        $this->assertSame('الاسم الجديد', $customer->fresh()->name);
    }

    /**
     * 4 — العزل بين أنواع الحسابات (موظف / عميل / تاجر غير معتمد / موزع معتمد)
     */
    public function test_04_account_type_isolation_across_admin_and_wholesale_routes(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        $product = Product::factory()->create(['status' => 'published']);
        ProductVariant::factory()->create(['product_id' => $product->id, 'retail_price' => 100, 'is_active' => true]);

        [, $staffToken] = $this->createStaff(['customers.view']);
        $retailApproved = Customer::factory()->create(['type' => CustomerType::Retail, 'status' => CustomerStatus::Approved]);
        $wholesalePending = Customer::factory()->wholesale()->create(['status' => CustomerStatus::Pending]);
        $distributorApproved = Customer::factory()->wholesale()->create([
            'type' => CustomerType::Distributor,
            'status' => CustomerStatus::Approved,
        ]);

        $retailToken = $retailApproved->createToken('store')->plainTextToken;
        $pendingToken = $wholesalePending->createToken('wholesale')->plainTextToken;
        $distributorToken = $distributorApproved->createToken('wholesale')->plainTextToken;

        // Without token -> 401
        Auth::forgetGuards();
        $this->getJson('/api/v1/wholesale/auth/application-status')->assertUnauthorized();
        $this->getJson('/api/v1/admin/customers')->assertUnauthorized();
        $this->getJson('/api/v1/wholesale/products')->assertUnauthorized();

        // 1. Staff token on GET /wholesale/auth/application-status -> 403
        $this->apiAs($staffToken, 'GET', '/api/v1/wholesale/auth/application-status')->assertForbidden();

        // 2. Customer token on GET /admin/customers -> 403
        $this->apiAs($retailToken, 'GET', '/api/v1/admin/customers')->assertForbidden();
        $this->apiAs($distributorToken, 'GET', '/api/v1/admin/customers')->assertForbidden();

        // 3. Pending wholesale token on GET /wholesale/products -> 403
        $this->apiAs($pendingToken, 'GET', '/api/v1/wholesale/products')->assertForbidden();

        // 4. Approved retail token on GET /wholesale/products -> 403
        $this->apiAs($retailToken, 'GET', '/api/v1/wholesale/products')->assertForbidden();

        // 5. Approved distributor token on GET /wholesale/products -> 200
        $this->apiAs($distributorToken, 'GET', '/api/v1/wholesale/products')->assertOk();
    }

    /**
     * 5 — قواعد الاعتماد (المستندات والبيانات القانونية) + جدول انتقالات الحالات
     */
    public function test_05_approval_rules_documents_legal_fields_and_state_machine(): void
    {
        Event::fake([WholesaleApplicationReviewed::class]);
        [$admin, $token] = $this->createStaff(['customers.approve']);

        // (أ) تاجر عنده مستند pending -> 422
        $cPendingDoc = Customer::factory()->wholesale()->create(['status' => CustomerStatus::Pending]);
        $cPendingDoc->documents()->create([
            'document_type' => CustomerDocumentType::CommercialRegister,
            'file_path' => 'doc.pdf', 'original_name' => 'doc.pdf', 'mime_type' => 'application/pdf',
            'status' => CustomerDocumentStatus::Pending,
        ]);
        $this->apiAs($token, 'POST', "/api/v1/admin/customers/{$cPendingDoc->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', 'راجع المستندات المعلقة (اعتمد السليم وارفض غير السليم) قبل اعتماد الحساب.');
        $this->assertSame(CustomerStatus::Pending, $cPendingDoc->fresh()->status);
        $this->assertDatabaseMissing('customer_audit_logs', ['customer_id' => $cPendingDoc->id, 'action' => 'approved']);

        // (ب) كل مستنداته rejected -> 422
        $cRejectedDocs = Customer::factory()->wholesale()->create(['status' => CustomerStatus::Pending]);
        $cRejectedDocs->documents()->create([
            'document_type' => CustomerDocumentType::CommercialRegister,
            'file_path' => 'doc.pdf', 'original_name' => 'doc.pdf', 'mime_type' => 'application/pdf',
            'status' => CustomerDocumentStatus::Rejected,
        ]);
        $this->apiAs($token, 'POST', "/api/v1/admin/customers/{$cRejectedDocs->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', 'يلزم مستند موثّق واحد على الأقل لاعتماد حساب الجملة أو الموزع.');

        // (ج) مافيش أي مستند -> 422
        $cNoDocs = Customer::factory()->wholesale()->create(['status' => CustomerStatus::Pending]);
        $this->apiAs($token, 'POST', "/api/v1/admin/customers/{$cNoDocs->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', 'يلزم مستند موثّق واحد على الأقل لاعتماد حساب الجملة أو الموزع.');

        // (د) tax_number فاضي -> 422
        $cMissingTax = Customer::factory()->wholesale()->create(['status' => CustomerStatus::Pending, 'tax_number' => null]);
        $cMissingTax->documents()->create([
            'document_type' => CustomerDocumentType::CommercialRegister,
            'file_path' => 'doc.pdf', 'original_name' => 'doc.pdf', 'mime_type' => 'application/pdf',
            'status' => CustomerDocumentStatus::Verified,
        ]);
        $this->apiAs($token, 'POST', "/api/v1/admin/customers/{$cMissingTax->id}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors('tax_number');

        // (هـ) موقوف -> 422
        $cSuspended = Customer::factory()->wholesale()->create(['status' => CustomerStatus::Suspended]);
        $this->apiAs($token, 'POST', "/api/v1/admin/customers/{$cSuspended->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', 'الحساب موقوف؛ استخدم إعادة التفعيل بدل الاعتماد.');

        // (و) معتمد أصلاً وبنفس النوع -> 422
        $cApproved = Customer::factory()->wholesale()->create(['status' => CustomerStatus::Approved]);
        $cApproved->documents()->create([
            'document_type' => CustomerDocumentType::CommercialRegister,
            'file_path' => 'doc.pdf', 'original_name' => 'doc.pdf', 'mime_type' => 'application/pdf',
            'status' => CustomerDocumentStatus::Verified,
        ]);
        $this->apiAs($token, 'POST', "/api/v1/admin/customers/{$cApproved->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', 'هذا الحساب معتمد بالفعل.');

        Event::assertNothingDispatched();

        // (ز) مستند verified ومستند rejected والبيانات كاملة -> 200
        $cValid = Customer::factory()->wholesale()->create([
            'status' => CustomerStatus::Pending,
            'rejection_reason' => 'سبب قديم',
            'suspension_reason' => 'سبب إيقاف قديم',
        ]);
        $cValid->documents()->create([
            'document_type' => CustomerDocumentType::CommercialRegister,
            'file_path' => 'v.pdf', 'original_name' => 'v.pdf', 'mime_type' => 'application/pdf',
            'status' => CustomerDocumentStatus::Verified,
        ]);
        $cValid->documents()->create([
            'document_type' => CustomerDocumentType::TaxCard,
            'file_path' => 'r.pdf', 'original_name' => 'r.pdf', 'mime_type' => 'application/pdf',
            'status' => CustomerDocumentStatus::Rejected,
        ]);

        $this->apiAs($token, 'POST', "/api/v1/admin/customers/{$cValid->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $fresh = $cValid->fresh();
        $this->assertSame(CustomerStatus::Approved, $fresh->status);
        $this->assertSame($admin->id, $fresh->approved_by);
        $this->assertNotNull($fresh->approved_at);
        $this->assertNull($fresh->rejection_reason);
        $this->assertNull($fresh->suspension_reason);
        $this->assertDatabaseHas('customer_audit_logs', ['customer_id' => $cValid->id, 'action' => 'approved', 'actor_id' => $admin->id]);
        Event::assertDispatched(WholesaleApplicationReviewed::class, fn ($e) => $e->customer->id === $cValid->id && $e->decision === 'approved');

        // Idempotent second approve -> 422
        $this->apiAs($token, 'POST', "/api/v1/admin/customers/{$cValid->id}/approve")->assertStatus(422);

        // Promoting approved wholesale to distributor -> 200
        $this->apiAs($token, 'POST', "/api/v1/admin/customers/{$cValid->id}/approve", ['type' => 'distributor'])
            ->assertOk()
            ->assertJsonPath('data.type', 'distributor');

        // Pending retail approval requires no documents
        $retailPending = Customer::factory()->create(['type' => CustomerType::Retail, 'status' => CustomerStatus::Pending]);
        $this->apiAs($token, 'POST', "/api/v1/admin/customers/{$retailPending->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }

    /**
     * 7 — الإيقاف يقطع الوصول فورًا وإعادة التفعيل ترجّع الحالة الصح
     */
    public function test_07_suspend_revokes_access_immediately_and_reactivate_restores_previous_status(): void
    {
        [, $adminToken] = $this->createStaff(['customers.approve']);

        foreach ([CustomerStatus::Approved, CustomerStatus::Pending, CustomerStatus::Rejected] as $initialStatus) {
            $customer = Customer::factory()->wholesale()->create([
                'status' => $initialStatus,
                'rejection_reason' => $initialStatus === CustomerStatus::Rejected ? 'سبب الرفض' : null,
            ]);
            $customerToken = $customer->createToken('session')->plainTextToken;
            DB::table('password_reset_tokens')->insert([
                'email' => $customer->getEmailForPasswordReset(),
                'token' => hash('sha256', 'reset-token'),
                'created_at' => now(),
            ]);

            // Suspend
            $this->apiAs($adminToken, 'POST', "/api/v1/admin/customers/{$customer->id}/suspend", ['reason' => 'مخالفة شروط'])
                ->assertOk()
                ->assertJsonPath('data.status', 'suspended');

            // 0 tokens & reset token deleted
            $this->assertSame(0, $customer->tokens()->count());
            $this->assertDatabaseMissing('password_reset_tokens', ['email' => $customer->getEmailForPasswordReset()]);

            // Old token gets 401
            $this->apiAs($customerToken, 'GET', '/api/v1/wholesale/auth/application-status')->assertUnauthorized();

            // Audit log has previous_status
            $log = CustomerAuditLog::where('customer_id', $customer->id)->where('action', 'suspended')->latest('id')->firstOrFail();
            $this->assertSame($initialStatus->value, $log->metadata['previous_status'] ?? null);

            // Second suspend on already suspended -> 422
            $this->apiAs($adminToken, 'POST', "/api/v1/admin/customers/{$customer->id}/suspend", ['reason' => 'تكرار'])
                ->assertStatus(422);

            // Reactivate -> restores exact initial status
            $this->apiAs($adminToken, 'POST', "/api/v1/admin/customers/{$customer->id}/reactivate")
                ->assertOk()
                ->assertJsonPath('data.status', $initialStatus->value);
            $this->assertSame($initialStatus, $customer->fresh()->status);

            // Reactivate on non-suspended -> 422
            $this->apiAs($adminToken, 'POST', "/api/v1/admin/customers/{$customer->id}/reactivate")
                ->assertStatus(422);
        }

        // Suspended wholesale whose pre_suspension_status is 'approved' but legal details are missing -> 422 (not 500)
        $incomplete = Customer::factory()->wholesale()->create(['status' => CustomerStatus::Suspended]);
        DB::table('customers')->where('id', $incomplete->id)->update([
            'pre_suspension_status' => 'approved',
            'company_name' => null,
            'tax_number' => '',
        ]);

        $this->apiAs($adminToken, 'POST', "/api/v1/admin/customers/{$incomplete->id}/reactivate")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['company_name', 'tax_number']);
    }

    /**
     * 8 — التسجيل الذاتي للتاجر: الحالة الطبيعية
     */
    public function test_08_wholesale_self_registration_happy_path(): void
    {
        Event::fake([WholesaleApplicationSubmitted::class]);

        $response = $this->postJson('/api/v1/wholesale/auth/register', [
            'name' => 'أحمد التاجر',
            'phone' => '+20 101 234 5678',
            'password' => 'strong-pass-12',
            'company_name' => 'شركة النور',
            'tax_number' => '555-666-777',
            'commercial_register' => 'CR-9988',
            'documents' => [
                UploadedFile::fake()->create('cr.pdf', 150, 'application/pdf'),
                UploadedFile::fake()->image('tax.png'),
            ],
            'document_types' => ['commercial_register', 'tax_card'],
        ])->assertCreated()
            ->assertJsonPath('data.type', 'wholesale')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.phone', '01012345678');

        $this->assertNotEmpty($response->json('token'));

        $customer = Customer::where('phone', '01012345678')->firstOrFail();
        $docs = $customer->documents()->orderBy('id')->get();
        $this->assertCount(2, $docs);
        foreach ($docs as $doc) {
            $this->assertSame(CustomerDocumentStatus::Pending, $doc->status);
            $this->assertStringStartsWith("customer_documents/{$customer->id}/", $doc->file_path);
            Storage::disk('r2')->assertExists($doc->file_path);
        }

        // Pending staging directory removed
        $this->assertSame([], Storage::disk('r2')->directories('customer_documents/pending'));

        // Audit log self_registered
        $this->assertDatabaseHas('customer_audit_logs', [
            'customer_id' => $customer->id,
            'action' => 'self_registered',
        ]);

        // Event dispatched once after commit
        Event::assertDispatchedTimes(WholesaleApplicationSubmitted::class, 1);
    }

    /**
     * 9 — فشل التسجيل في النص مايسيبش ملفات يتيمة ولا حساب ناقص (الحالات أ، ب، ج)
     */
    public function test_09_registration_midway_failure_cleans_up_files_and_leaves_no_orphan_records(): void
    {
        $basePayload = [
            'name' => 'تاجر تجربة',
            'phone' => '01019998887',
            'password' => 'strong-pass-12',
            'company_name' => 'شركة',
            'tax_number' => '12345',
            'commercial_register' => '67890',
        ];

        // (ب) Email taken in users table when lockRegistrationEmail runs inside WholesaleRegistrationService::register -> 422
        $disk = $this->fakeUploads();
        User::factory()->create(['email' => 'taken.staff@example.com']);
        try {
            app(\App\Services\Wholesale\WholesaleRegistrationService::class)->register(
                [...$basePayload, 'email' => 'TAKEN.STAFF@example.com'],
                [UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf')],
                ['commercial_register'],
            );
            $this->fail('Expected ValidationException for email taken in users table.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
        }
        $this->assertDatabaseMissing('customers', ['phone' => '01019998887']);
        $this->assertDatabaseCount('customer_documents', 0);
        $this->assertSame([], Storage::disk('r2')->allFiles());

        // (ج) Mock issueToken throws exception -> 500, no customer/docs, r2 cleaned
        $this->partialMock(CustomerAuthService::class, function ($mock): void {
            $mock->shouldReceive('issueToken')->once()->andThrow(new \RuntimeException('Token failure'));
        });

        $this->postJson('/api/v1/wholesale/auth/register', [
            ...$basePayload,
            'documents' => [UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf')],
        ])->assertStatus(500);

        $this->assertDatabaseMissing('customers', ['phone' => '01019998887']);
        $this->assertDatabaseCount('customer_documents', 0);
        $this->assertSame([], Storage::disk('r2')->allFiles());

        // (أ) Mock move returns false -> 500, no customer/docs, r2 cleaned
        $proxy = \Mockery::mock($disk)->makePartial();
        $proxy->shouldReceive('move')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('r2')->andReturn($proxy);

        $this->postJson('/api/v1/wholesale/auth/register', [
            ...$basePayload,
            'documents' => [UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf')],
        ])->assertStatus(500);

        $this->assertDatabaseMissing('customers', ['phone' => '01019998887']);
        $this->assertDatabaseCount('customer_documents', 0);
        $this->assertSame([], $disk->allFiles());
    }

    /**
     * 11 (ب، ج، د) و 12 — حدود رفع المستندات ورفع المستندات ذاتيًا من التاجر حسب حالته
     */
    public function test_11_and_12_document_upload_limits_and_self_service_by_status(): void
    {
        $service = app(CustomerManagementService::class);

        // (د) عميل قطاعي -> 422 "رفع المستندات متاح للتجار والموزعين فقط."
        $retail = Customer::factory()->create(['type' => CustomerType::Retail]);
        [, $staffToken] = $this->createStaff(['customers.documents.view', 'customers.update']);
        $this->apiAs($staffToken, 'POST', "/api/v1/admin/customers/{$retail->id}/documents", [
            'document' => UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf'),
        ])->assertStatus(422)->assertJsonPath('message', 'رفع المستندات متاح للتجار والموزعين فقط.');

        // (ب) تاجر عنده 5 فعّالة -> 422
        $trader5 = Customer::factory()->wholesale()->create(['status' => CustomerStatus::Pending]);
        for ($i = 1; $i <= 5; $i++) {
            $service->uploadDocument($trader5, UploadedFile::fake()->create("d{$i}.pdf", 20, 'application/pdf'), null);
        }
        $this->apiAs($staffToken, 'POST', "/api/v1/admin/customers/{$trader5->id}/documents", [
            'document' => UploadedFile::fake()->create('d6.pdf', 20, 'application/pdf'),
        ])->assertStatus(422);

        // (ج) تاجر عنده 4 فعّالة و 6 rejected (إجمالي 10) -> 422
        $trader10 = Customer::factory()->wholesale()->create(['status' => CustomerStatus::Pending]);
        for ($i = 1; $i <= 6; $i++) {
            $doc = $service->uploadDocument($trader10, UploadedFile::fake()->create("r{$i}.pdf", 20, 'application/pdf'), null);
            $doc->update(['status' => CustomerDocumentStatus::Rejected]);
        }
        for ($i = 1; $i <= 4; $i++) {
            $service->uploadDocument($trader10, UploadedFile::fake()->create("a{$i}.pdf", 20, 'application/pdf'), null);
        }
        $this->assertSame(10, $trader10->documents()->count());
        $this->apiAs($staffToken, 'POST', "/api/v1/admin/customers/{$trader10->id}/documents", [
            'document' => UploadedFile::fake()->create('extra.pdf', 20, 'application/pdf'),
        ])->assertStatus(422);

        // 12 — Self-service POST /wholesale/auth/documents by trader status:
        // pending -> 201 + audit channel=wholesale_self, default document_type=other
        $pending = Customer::factory()->wholesale()->create(['status' => CustomerStatus::Pending]);
        $pendingTok = $pending->createToken('ws')->plainTextToken;
        $res = $this->apiAs($pendingTok, 'POST', '/api/v1/wholesale/auth/documents', [
            'document' => UploadedFile::fake()->create('self.pdf', 30, 'application/pdf'),
        ])->assertCreated();
        $docId = $res->json('data.id');
        $this->assertSame(CustomerDocumentType::Other, CustomerDocument::findOrFail($docId)->document_type);
        $log = CustomerAuditLog::where('customer_id', $pending->id)->where('action', 'document_uploaded')->firstOrFail();
        $this->assertSame('wholesale_self', $log->metadata['channel'] ?? null);

        // approved -> 403 from service
        $approved = Customer::factory()->wholesale()->create(['status' => CustomerStatus::Approved]);
        $approvedTok = $approved->createToken('ws')->plainTextToken;
        $this->apiAs($approvedTok, 'POST', '/api/v1/wholesale/auth/documents', [
            'document' => UploadedFile::fake()->create('self.pdf', 30, 'application/pdf'),
        ])->assertForbidden()->assertJsonPath('message', 'رفع المستندات متاح للطلبات قيد المراجعة فقط.');

        // rejected -> 403 from middleware
        $rejected = Customer::factory()->wholesale()->create(['status' => CustomerStatus::Rejected]);
        $rejectedTok = $rejected->createToken('ws')->plainTextToken;
        $this->apiAs($rejectedTok, 'POST', '/api/v1/wholesale/auth/documents', [
            'document' => UploadedFile::fake()->create('self.pdf', 30, 'application/pdf'),
        ])->assertForbidden();

        // suspended -> 403 from middleware
        $suspended = Customer::factory()->wholesale()->create(['status' => CustomerStatus::Suspended]);
        $suspendedTok = $suspended->createToken('ws')->plainTextToken;
        $this->apiAs($suspendedTok, 'POST', '/api/v1/wholesale/auth/documents', [
            'document' => UploadedFile::fake()->create('self.pdf', 30, 'application/pdf'),
        ])->assertForbidden();

        // Section 3: Spoofed PHP file renamed to .pdf or file > 5MB -> 422
        $spoofedPhp = UploadedFile::fake()->create('spoofed.pdf', 10, 'text/x-php');
        $this->apiAs($pendingTok, 'POST', '/api/v1/wholesale/auth/documents', [
            'document' => $spoofedPhp,
        ])->assertStatus(422)->assertJsonValidationErrors('document');

        $tooLarge = UploadedFile::fake()->create('huge.pdf', 5121, 'application/pdf');
        $this->apiAs($pendingTok, 'POST', '/api/v1/wholesale/auth/documents', [
            'document' => $tooLarge,
        ])->assertStatus(422)->assertJsonValidationErrors('document');
    }

    /**
     * 13 & 14 & 15 — IDOR تنزيل/مراجعة مستند عميل تاني، واسم الملف وقت التنزيل آمن، ومراجعة المستند بعد الاعتماد
     */
    public function test_13_14_15_document_idor_safe_filename_and_review_validation(): void
    {
        [, $token] = $this->createStaff(['customers.documents.view', 'customers.approve']);

        $customerA = Customer::factory()->wholesale()->create(['status' => CustomerStatus::Approved]);
        $customerB = Customer::factory()->wholesale()->create(['status' => CustomerStatus::Pending]);

        $evilName = "a\"\r\nX-Evil: 1/../../".str_repeat('b', 260).'.pdf';
        $docOfB = $customerB->documents()->create([
            'document_type' => CustomerDocumentType::NationalId,
            'file_path' => "customer_documents/{$customerB->id}/id.pdf",
            'original_name' => CustomerDocument::safeName($evilName),
            'mime_type' => 'application/pdf',
            'status' => CustomerDocumentStatus::Pending,
        ]);
        Storage::disk('r2')->put($docOfB->file_path, 'secret-id-content');

        // 13 — IDOR: Customer A route with Customer B's document -> 404, no document_downloaded audit
        $this->apiAs($token, 'GET', "/api/v1/admin/customers/{$customerA->id}/documents/{$docOfB->id}/download")
            ->assertNotFound();
        $this->apiAs($token, 'PATCH', "/api/v1/admin/customers/{$customerA->id}/documents/{$docOfB->id}", [
            'status' => 'verified',
        ])->assertNotFound();
        $this->assertDatabaseMissing('customer_audit_logs', ['action' => 'document_downloaded']);

        // 14 — Safe filename on download
        $safe = CustomerDocument::safeName($evilName);
        $this->assertLessThanOrEqual(250, mb_strlen($safe));
        $this->assertStringNotContainsString("\r", $safe);
        $this->assertStringNotContainsString("\n", $safe);
        $this->assertStringNotContainsString('"', $safe);
        $this->assertStringNotContainsString('/', $safe);
        $this->assertStringNotContainsString('\\', $safe);

        $downloadRes = $this->apiAs($token, 'GET', "/api/v1/admin/customers/{$customerB->id}/documents/{$docOfB->id}/download")
            ->assertOk();
        $disposition = (string) $downloadRes->headers->get('Content-Disposition');
        $this->assertStringNotContainsString("\r", $disposition);
        $this->assertStringNotContainsString("\n", $disposition);
        $this->assertStringNotContainsString('X-Evil', $disposition);

        // Missing file on r2 -> 404 and no audit
        CustomerAuditLog::query()->delete();
        Storage::disk('r2')->delete($docOfB->file_path);
        $this->apiAs($token, 'GET', "/api/v1/admin/customers/{$customerB->id}/documents/{$docOfB->id}/download")
            ->assertNotFound();
        $this->assertDatabaseMissing('customer_audit_logs', ['action' => 'document_downloaded']);

        // 15 — Review document on approved customer + note validation
        $docOfA = $customerA->documents()->create([
            'document_type' => CustomerDocumentType::CommercialRegister,
            'file_path' => "customer_documents/{$customerA->id}/cr.pdf",
            'original_name' => 'cr.pdf',
            'mime_type' => 'application/pdf',
            'status' => CustomerDocumentStatus::Verified,
        ]);

        // rejected without note -> 422
        $this->apiAs($token, 'PATCH', "/api/v1/admin/customers/{$customerA->id}/documents/{$docOfA->id}", [
            'status' => 'rejected',
        ])->assertStatus(422)->assertJsonValidationErrors('note');

        // note > 500 chars -> 422
        $this->apiAs($token, 'PATCH', "/api/v1/admin/customers/{$customerA->id}/documents/{$docOfA->id}", [
            'status' => 'rejected',
            'note' => str_repeat('أ', 501),
        ])->assertStatus(422)->assertJsonValidationErrors('note');

        // rejected with valid note -> 200
        $this->apiAs($token, 'PATCH', "/api/v1/admin/customers/{$customerA->id}/documents/{$docOfA->id}", [
            'status' => 'rejected',
            'note' => 'انتهت صلاحية السجل',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

        // back to pending -> 200
        $this->apiAs($token, 'PATCH', "/api/v1/admin/customers/{$customerA->id}/documents/{$docOfA->id}", [
            'status' => 'pending',
        ])->assertOk()->assertJsonPath('data.status', 'pending');
    }

    /**
     * 16 — الحذف الناعم والاستعادة
     */
    public function test_16_soft_delete_and_restore_lifecycle(): void
    {
        [, $token] = $this->createStaff(['customers.view', 'customers.delete']);

        $customer = Customer::factory()->wholesale()->create(['email' => 'reclaim@example.com', 'email_verified_at' => null]);
        $customer->createToken('tok');
        DB::table('password_reset_tokens')->insert([
            'email' => $customer->getEmailForPasswordReset(),
            'token' => 'tok',
            'created_at' => now(),
        ]);

        // (د) restore on non-deleted customer -> 422
        $this->apiAs($token, 'POST', "/api/v1/admin/customers/{$customer->id}/restore")
            ->assertStatus(422)
            ->assertJsonPath('message', 'الحساب غير محذوف.');

        // (أ) delete customer with tokens
        $this->apiAs($token, 'DELETE', "/api/v1/admin/customers/{$customer->id}")->assertOk();
        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
        $this->assertSame(0, $customer->tokens()->count());
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $customer->getEmailForPasswordReset()]);
        $this->assertDatabaseHas('customer_audit_logs', ['customer_id' => $customer->id, 'action' => 'deleted']);

        // (هـ) delete, show, history on soft-deleted customer -> 404
        $this->apiAs($token, 'DELETE', "/api/v1/admin/customers/{$customer->id}")->assertNotFound();
        $this->apiAs($token, 'GET', "/api/v1/admin/customers/{$customer->id}")->assertNotFound();
        $this->apiAs($token, 'GET', "/api/v1/admin/customers/{$customer->id}/history")->assertNotFound();

        // index?trashed=only returns only soft-deleted customers
        $activeOther = Customer::factory()->create();
        $trashedRes = $this->apiAs($token, 'GET', '/api/v1/admin/customers?trashed=only')->assertOk();
        $this->assertSame([$customer->id], collect($trashedRes->json('data'))->pluck('id')->all());

        // (ب) restore customer -> 200 & audit restored
        $this->apiAs($token, 'POST', "/api/v1/admin/customers/{$customer->id}/restore")->assertOk();
        $this->assertNotSoftDeleted('customers', ['id' => $customer->id]);
        $this->assertDatabaseHas('customer_audit_logs', ['customer_id' => $customer->id, 'action' => 'restored']);

        // (ج) delete again, create new customer with same email, try to restore first -> 422
        $this->apiAs($token, 'DELETE', "/api/v1/admin/customers/{$customer->id}")->assertOk();
        Customer::factory()->wholesale()->create(['email' => 'reclaim@example.com', 'email_verified_at' => null]);
        $this->apiAs($token, 'POST', "/api/v1/admin/customers/{$customer->id}/restore")
            ->assertStatus(422);
    }

    /**
     * 17 — التعديل: تغيير بيانات الدخول بيسحب الجلسات ويسجّل الفرق المالي
     */
    public function test_17_update_side_effects_credentials_revocation_and_financial_diff(): void
    {
        [, $token] = $this->createStaff(['customers.update', 'customers.approve']);

        // 1. Change phone -> revokes tokens, resets phone_verified_at, changes remember_token, deletes reset row, audits credentials_changed
        $customer = Customer::factory()->wholesale()->create([
            'status' => CustomerStatus::Approved,
            'phone' => '01011112222',
            'phone_verified_at' => now(),
            'email' => 'cred@example.com',
            'credit_limit' => 0,
        ]);
        $prepareSession = function (Customer $c): string {
            $c->forceFill(['remember_token' => 'rem-'.uniqid()])->save();
            $c->createToken('sess');
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $c->getEmailForPasswordReset()],
                ['token' => 'tok', 'created_at' => now()]
            );

            return (string) $c->remember_token;
        };

        $oldRem = $prepareSession($customer);
        $this->apiAs($token, 'PUT', "/api/v1/admin/customers/{$customer->id}", ['phone' => '01033334444'])->assertOk();
        $fresh = $customer->fresh();
        $this->assertNull($fresh->phone_verified_at);
        $this->assertNotSame($oldRem, $fresh->remember_token);
        $this->assertSame(0, $fresh->tokens()->count());
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $customer->getEmailForPasswordReset()]);
        $this->assertDatabaseHas('customer_audit_logs', ['customer_id' => $customer->id, 'action' => 'credentials_changed']);

        // 2. Clear email (null) -> revokes credentials
        CustomerAuditLog::query()->delete();
        $oldRem = $prepareSession($customer);
        $this->apiAs($token, 'PUT', "/api/v1/admin/customers/{$customer->id}", ['email' => null])->assertOk();
        $fresh = $customer->fresh();
        $this->assertNull($fresh->email);
        $this->assertNotSame($oldRem, $fresh->remember_token);
        $this->assertSame(0, $fresh->tokens()->count());
        $this->assertDatabaseHas('customer_audit_logs', ['customer_id' => $customer->id, 'action' => 'credentials_changed']);

        // 3. Change password -> revokes credentials, password never in audit log
        CustomerAuditLog::query()->delete();
        $oldRem = $prepareSession($customer);
        $this->apiAs($token, 'PUT', "/api/v1/admin/customers/{$customer->id}", ['password' => 'new-secret-pass-123'])->assertOk();
        $this->assertSame(0, $customer->tokens()->count());
        $log = CustomerAuditLog::where('customer_id', $customer->id)->where('action', 'credentials_changed')->firstOrFail();
        $this->assertStringNotContainsString('new-secret-pass-123', json_encode($log->metadata));

        // 4. Change name only -> tokens stay, audit is 'updated'
        CustomerAuditLog::query()->delete();
        $customer->createToken('keep-me');
        $this->apiAs($token, 'PUT', "/api/v1/admin/customers/{$customer->id}", ['name' => 'اسم فقط'])->assertOk();
        $this->assertSame(1, $customer->tokens()->count());
        $this->assertDatabaseHas('customer_audit_logs', ['customer_id' => $customer->id, 'action' => 'updated']);

        // Deactivated customer group: updating name while omitting customer_group_id succeeds (200),
        // but re-sending the deactivated customer_group_id in the PUT payload is rejected (422) by UpdateCustomerRequest
        $deactivatedGrp = CustomerGroup::create(['name' => 'مجموعة ستعطل', 'code' => 'grp-deact', 'is_active' => true]);
        $customer->forceFill(['customer_group_id' => $deactivatedGrp->id])->save();
        $deactivatedGrp->update(['is_active' => false]);

        $this->apiAs($token, 'PUT', "/api/v1/admin/customers/{$customer->id}", ['name' => 'اسم بدون إرسال المجموعة'])
            ->assertOk();
        $this->apiAs($token, 'PUT', "/api/v1/admin/customers/{$customer->id}", [
            'name' => 'اسم مع إرسال المجموعة المعطلة',
            'customer_group_id' => $deactivatedGrp->id,
        ])->assertStatus(422)->assertJsonValidationErrors('customer_group_id');
        $customer->forceFill(['customer_group_id' => null])->save();

        // 5. Change credit_limit from 0 to 5000 -> audit changes.credit_limit has before & after
        CustomerAuditLog::query()->delete();
        $this->apiAs($token, 'PUT', "/api/v1/admin/customers/{$customer->id}", ['credit_limit' => 5000])->assertOk();
        $finLog = CustomerAuditLog::where('customer_id', $customer->id)->where('action', 'updated')->firstOrFail();
        $this->assertEquals(['before' => '0.00', 'after' => '5000'], $finLog->metadata['changes']['credit_limit'] ?? null);

        // 6. Approved trader with empty company_name -> 422 and no change
        $origCompany = $customer->fresh()->company_name;
        $this->apiAs($token, 'PUT', "/api/v1/admin/customers/{$customer->id}", ['company_name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('company_name');
        $this->assertSame($origCompany, $customer->fresh()->company_name);

        // 7. Sending type or status in update -> 422 (missing rule)
        $this->apiAs($token, 'PUT', "/api/v1/admin/customers/{$customer->id}", ['type' => 'retail'])
            ->assertStatus(422)->assertJsonValidationErrors('type');
        $this->apiAs($token, 'PUT', "/api/v1/admin/customers/{$customer->id}", ['status' => 'pending'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }

    /**
     * 18 — الإنشاء من لوحة الإدارة + حدود المدخلات
     */
    public function test_18_admin_customer_creation_defaults_and_validation_boundaries(): void
    {
        [$staff, $token] = $this->createStaff(['customers.create', 'customers.approve']);

        // 1. Without type -> retail & approved, approved_by = staff, email_verified_at = null, audit created
        $res1 = $this->apiAs($token, 'POST', '/api/v1/admin/customers', [
            'name' => 'قطاعي افتراضي',
            'email' => 'ret1@example.com',
            'phone' => '01010000001',
            'password' => 'valid-password-12',
        ])->assertCreated()
            ->assertJsonPath('data.type', 'retail')
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approved_by', $staff->id)
            ->assertJsonPath('data.email_verified_at', null);
        $this->assertDatabaseHas('customer_audit_logs', ['customer_id' => $res1->json('data.id'), 'action' => 'created']);

        // 2. type=wholesale without status -> pending
        $res2 = $this->apiAs($token, 'POST', '/api/v1/admin/customers', [
            'name' => 'تاجر افتراضي',
            'phone' => '01010000002',
            'password' => 'valid-password-12',
            'type' => 'wholesale',
        ])->assertCreated()
            ->assertJsonPath('data.type', 'wholesale')
            ->assertJsonPath('data.status', 'pending');
        $this->assertNull(Customer::findOrFail($res2->json('data.id'))->approved_by);

        // 3. type=wholesale & status=approved -> 422
        $this->apiAs($token, 'POST', '/api/v1/admin/customers', [
            'name' => 'تاجر معتمد مباشرة',
            'phone' => '01010000003',
            'password' => 'valid-password-12',
            'type' => 'wholesale',
            'status' => 'approved',
        ])->assertStatus(422);

        // 4. Inactive customer group -> 422
        $inactiveGroup = CustomerGroup::create(['name' => 'معطلة', 'code' => 'inact', 'is_active' => false]);
        $this->apiAs($token, 'POST', '/api/v1/admin/customers', [
            'name' => 'عميل مجموعة معطلة',
            'phone' => '01010000004',
            'password' => 'valid-password-12',
            'customer_group_id' => $inactiveGroup->id,
        ])->assertStatus(422)->assertJsonValidationErrors('customer_group_id');

        // 5. Arabic digits phone ٠١٠١٢٣٤٥٦٧٨ -> saved as 01012345678
        $this->apiAs($token, 'POST', '/api/v1/admin/customers', [
            'name' => 'عميل أرقام عربية',
            'phone' => '٠١٠١٢٣٤٥٦٧٨',
            'password' => 'valid-password-12',
        ])->assertCreated()->assertJsonPath('data.phone', '01012345678');

        // Section 3 validation edge cases:
        // Password 11 chars -> 422
        $this->apiAs($token, 'POST', '/api/v1/admin/customers', [
            'name' => 'قصير', 'phone' => '01010000005', 'password' => '12345678901',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        // Password 73 bytes (36 Arabic chars = 72 bytes + 'a' = 73 bytes) -> 422 PasswordByteLimit
        $this->apiAs($token, 'POST', '/api/v1/admin/customers', [
            'name' => 'طويل بالبايت', 'phone' => '01010000005', 'password' => str_repeat('أ', 36).'a',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        // Password exact 72 bytes (36 Arabic chars = 72 bytes, 36 chars >= 12) -> 201
        $this->apiAs($token, 'POST', '/api/v1/admin/customers', [
            'name' => '72 بايت بالظبط', 'phone' => '01010000006', 'password' => str_repeat('أ', 36),
        ])->assertCreated();

        // credit_limit -1 or 10000000000 -> 422
        foreach ([-1, 10000000000] as $badLimit) {
            $this->apiAs($token, 'POST', '/api/v1/admin/customers', [
                'name' => 'ائتمان خاطئ', 'phone' => '01010000007', 'password' => 'valid-password-12', 'credit_limit' => $badLimit,
            ])->assertStatus(422)->assertJsonValidationErrors('credit_limit');
        }

        // Invalid phone prefix 01312345678 -> 422
        $this->apiAs($token, 'POST', '/api/v1/admin/customers', [
            'name' => 'موبايل غلط', 'phone' => '01312345678', 'password' => 'valid-password-12',
        ])->assertStatus(422)->assertJsonValidationErrors('phone');

        // Duplicate phone of soft-deleted customer -> 422
        $deleted = Customer::factory()->create(['phone' => '01010000008']);
        $deleted->delete();
        $this->apiAs($token, 'POST', '/api/v1/admin/customers', [
            'name' => 'مكرر مع محذوف', 'phone' => '01010000008', 'password' => 'valid-password-12',
        ])->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    /**
     * 23 & Section 3 — مجموعات العملاء: حذف آمن ووصول مركزي وفلاتر وتدقيق
     */
    public function test_23_customer_groups_safe_deletion_central_access_and_audit(): void
    {
        [, $centralToken] = $this->createStaff(self::ALL_PERMS);
        $branch = Branch::factory()->create();
        [, $branchScopedToken] = $this->createStaff(
            ['customer_groups.view', 'customer_groups.create', 'customer_groups.update', 'customer_groups.delete'],
            ['branch_id' => $branch->id]
        );

        // (د) Branch-scoped staff with customer_groups.create tries store -> 403
        $this->apiAs($branchScopedToken, 'POST', '/api/v1/admin/customer-groups', [
            'name' => 'فرعية', 'code' => 'BR-1',
        ])->assertForbidden();

        // (أ) Delete group with soft-deleted customer -> 422
        $grpTrashed = CustomerGroup::create(['name' => 'بها محذوف', 'code' => 'grp-trashed']);
        $cust = Customer::factory()->create(['customer_group_id' => $grpTrashed->id]);
        $cust->delete();
        $this->apiAs($centralToken, 'DELETE', "/api/v1/admin/customer-groups/{$grpTrashed->id}")
            ->assertStatus(422);
        $this->assertDatabaseHas('customer_groups', ['id' => $grpTrashed->id]);

        // (ب) Delete group linked to price list -> 422
        $grpPriceList = CustomerGroup::create(['name' => 'بها قائمة أسعار', 'code' => 'grp-pl']);
        PriceList::create(['name' => 'PL', 'type' => 'customer_group', 'customer_group_id' => $grpPriceList->id]);
        $this->apiAs($centralToken, 'DELETE', "/api/v1/admin/customer-groups/{$grpPriceList->id}")
            ->assertStatus(422);
        $this->assertDatabaseHas('customer_groups', ['id' => $grpPriceList->id]);

        // (ج) Delete empty group -> 200 & staff_audit_logs has customer_group.deleted
        $grpEmpty = CustomerGroup::create(['name' => 'فاضية', 'code' => 'grp-empty']);
        $this->apiAs($centralToken, 'DELETE', "/api/v1/admin/customer-groups/{$grpEmpty->id}")
            ->assertOk();
        $this->assertDatabaseMissing('customer_groups', ['id' => $grpEmpty->id]);
        $this->assertDatabaseHas('staff_audit_logs', ['action' => 'customer_group.deleted', 'target_id' => $grpEmpty->id]);

        // (هـ) Duplicate code -> 422 on code
        $this->apiAs($centralToken, 'POST', '/api/v1/admin/customer-groups', [
            'name' => 'مكرر', 'code' => 'grp-pl',
        ])->assertStatus(422)->assertJsonValidationErrors('code');

        // Section 3 CustomerGroup rules:
        // discount_percentage 100.01 -> 422, 100 -> 201, null -> defaults to 0.00
        $this->apiAs($centralToken, 'POST', '/api/v1/admin/customer-groups', [
            'name' => 'خصم زائد', 'code' => 'disc-over', 'discount_percentage' => 100.01,
        ])->assertStatus(422)->assertJsonValidationErrors('discount_percentage');

        $this->apiAs($centralToken, 'POST', '/api/v1/admin/customer-groups', [
            'name' => 'خصم كامل', 'code' => 'disc-100', 'discount_percentage' => 100,
        ])->assertCreated()->assertJsonPath('data.discount_percentage', '100.00');

        $nullDisc = $this->apiAs($centralToken, 'POST', '/api/v1/admin/customer-groups', [
            'name' => 'خصم افتراضي', 'code' => 'disc-null', 'discount_percentage' => null, 'is_active' => false,
        ])->assertCreated()->assertJsonPath('data.discount_percentage', '0.00');

        // code with space -> 422
        $this->apiAs($centralToken, 'POST', '/api/v1/admin/customer-groups', [
            'name' => 'مسافة', 'code' => 'bad code',
        ])->assertStatus(422)->assertJsonValidationErrors('code');

        // Case-sensitive code: 'VIP' and 'vip' both accepted currently
        $this->apiAs($centralToken, 'POST', '/api/v1/admin/customer-groups', [
            'name' => 'كبار كابيتال', 'code' => 'VIP', 'is_active' => false,
        ])->assertCreated();
        $this->apiAs($centralToken, 'POST', '/api/v1/admin/customer-groups', [
            'name' => 'كبار سمول', 'code' => 'vip', 'is_active' => false,
        ])->assertCreated();

        // Filter is_active=0 returns only inactive, and customers_count is present
        $listRes = $this->apiAs($centralToken, 'GET', '/api/v1/admin/customer-groups?is_active=0')->assertOk();
        $this->assertContains($nullDisc->json('data.id'), collect($listRes->json('data'))->pluck('id')->all());
        $this->assertArrayHasKey('customers_count', $listRes->json('data.0'));

        // Update group records before and after in staff_audit_logs
        $grpId = $nullDisc->json('data.id');
        $this->apiAs($centralToken, 'PUT', "/api/v1/admin/customer-groups/{$grpId}", [
            'discount_percentage' => 25,
        ])->assertOk();
        $auditRow = DB::table('staff_audit_logs')->where('action', 'customer_group.updated')->where('target_id', $grpId)->first();
        $this->assertNotNull($auditRow);
        $meta = json_decode($auditRow->metadata, true);
        $this->assertEquals(0, $meta['before']['discount_percentage']);
        $this->assertEquals(25, $meta['after']['discount_percentage']);
    }

    /**
     * 24 — حالة الطلب للتاجر والبيانات اللي بتظهر له
     */
    public function test_24_wholesale_application_status_visibility_and_data_leak_prevention(): void
    {
        [$admin] = $this->createStaff(['customers.approve']);

        // 1. Rejected trader with rejection_reason
        $rejected = Customer::factory()->wholesale()->create([
            'status' => CustomerStatus::Rejected,
            'rejection_reason' => 'البطاقة الضريبية غير سارية',
        ]);
        $rejectedToken = $rejected->createToken('ws')->plainTextToken;

        $resRejected = $this->apiAs($rejectedToken, 'GET', '/api/v1/wholesale/auth/application-status')
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'البطاقة الضريبية غير سارية');
        $this->assertStringNotContainsString('password', $resRejected->getContent());
        $this->assertStringNotContainsString('remember_token', $resRejected->getContent());

        // Rejected trader cannot access any other wholesale/store customer route -> 403
        $this->apiAs($rejectedToken, 'GET', '/api/v1/wholesale/dashboard')->assertForbidden();
        $this->apiAs($rejectedToken, 'POST', '/api/v1/wholesale/auth/documents')->assertForbidden();
        $this->apiAs($rejectedToken, 'GET', '/api/v1/store/account/orders')->assertForbidden();

        // 2. Pending trader with a rejected document that has review_note
        $pending = Customer::factory()->wholesale()->create([
            'status' => CustomerStatus::Pending,
            'rejection_reason' => 'سبب داخلي لا يجب أن يظهر للـ pending',
        ]);
        $pending->documents()->create([
            'document_type' => CustomerDocumentType::TaxCard,
            'file_path' => 'customer_documents/secret_internal_path.pdf',
            'original_name' => 'tax.pdf',
            'mime_type' => 'application/pdf',
            'status' => CustomerDocumentStatus::Rejected,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'review_note' => 'الصورة مقصوصة',
        ]);
        $pendingToken = $pending->createToken('ws')->plainTextToken;

        $resPending = $this->apiAs($pendingToken, 'GET', '/api/v1/wholesale/auth/application-status')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.rejection_reason', null)
            ->assertJsonPath('data.rejected_documents.0.review_note', 'الصورة مقصوصة');

        // Internal storage path and reviewer identity must never leak to the customer
        $raw = $resPending->getContent();
        $this->assertStringNotContainsString('secret_internal_path.pdf', $raw);
        $this->assertStringNotContainsString('file_path', $raw);
        $this->assertStringNotContainsString('reviewed_by', $raw);
    }

    /**
     * 25 — الإشعارات بعد الـ commit بس
     */
    public function test_25_notifications_dispatch_only_after_transaction_commits(): void
    {
        Notification::fake();
        [$approver] = $this->createStaff(['customers.approve']);
        [$otherStaff] = $this->createStaff(['customers.view']);

        $trader = Customer::factory()->wholesale()->create(['status' => CustomerStatus::Pending]);
        $trader->documents()->create([
            'document_type' => CustomerDocumentType::CommercialRegister,
            'file_path' => 'cr.pdf', 'original_name' => 'cr.pdf', 'mime_type' => 'application/pdf',
            'status' => CustomerDocumentStatus::Verified,
        ]);

        $service = app(CustomerManagementService::class);

        // 1. Approve inside a transaction that rolls back -> 0 notifications
        try {
            DB::transaction(function () use ($service, $trader, $approver): void {
                $service->approve($trader, [], $approver->id);
                throw new \RuntimeException('Simulated rollback after approve');
            });
        } catch (\RuntimeException) {
        }

        Notification::assertNothingSent();
        $this->assertSame(CustomerStatus::Pending, $trader->fresh()->status);

        // 2. Normal approve -> 1 notification to trader with decision=approved
        $service->approve($trader, [], $approver->id);
        Notification::assertSentToTimes($trader, WholesaleApplicationReviewedNotification::class, 1);
        Notification::assertSentTo(
            $trader,
            WholesaleApplicationReviewedNotification::class,
            fn (WholesaleApplicationReviewedNotification $n) => ($n->toArray($trader)['decision'] ?? null) === 'approved'
        );

        // 3. New wholesale registration sends notification to staff with customers.approve only
        Notification::fake();
        $this->postJson('/api/v1/wholesale/auth/register', [
            'name' => 'تاجر إشعارات',
            'phone' => '01077889900',
            'password' => 'secure-pass-1234',
            'company_name' => 'شركة',
            'tax_number' => '111',
            'commercial_register' => '222',
            'documents' => [UploadedFile::fake()->create('cr.pdf', 30, 'application/pdf')],
        ])->assertCreated();

        Notification::assertSentTo($approver, WholesaleApplicationSubmittedNotification::class);
        Notification::assertNotSentTo($otherStaff, WholesaleApplicationSubmittedNotification::class);
    }

    /**
     * 28 — البحث والفلترة في قائمة العملاء
     */
    public function test_28_customer_index_search_wildcards_filters_and_sorting(): void
    {
        [, $token] = $this->createStaff(['customers.view']);

        $c1 = Customer::factory()->wholesale()->create([
            'name' => 'عميل أول',
            'company_name' => 'شركة 100% للتجارة',
            'tax_number' => 'TAX-998877-X',
        ]);
        $c2 = Customer::factory()->wholesale()->create([
            'name' => 'عميل a_b خاص',
            'company_name' => 'شركة 1000 للتجارة',
            'tax_number' => 'TAX-112233-Y',
        ]);
        $c3 = Customer::factory()->create([
            'name' => 'عميل axb عادي',
            'type' => CustomerType::Retail,
        ]);

        // 1. search=ab (2 chars) -> 422
        $this->apiAs($token, 'GET', '/api/v1/admin/customers?search=ab')
            ->assertStatus(422)
            ->assertJsonValidationErrors('search');

        // 2. search=100% -> matches literal '100%' only (not '1000')
        $resPct = $this->apiAs($token, 'GET', '/api/v1/admin/customers?search='.urlencode('100%'))->assertOk();
        $this->assertSame([$c1->id], collect($resPct->json('data'))->pluck('id')->all());

        // 3. search=a_b -> matches literal 'a_b' only (not 'axb')
        $resUnd = $this->apiAs($token, 'GET', '/api/v1/admin/customers?search=a_b')->assertOk();
        $this->assertSame([$c2->id], collect($resUnd->json('data'))->pluck('id')->all());

        // 4. Search by part of tax_number -> finds customer via search_text
        $resTax = $this->apiAs($token, 'GET', '/api/v1/admin/customers?search=998877')->assertOk();
        $this->assertSame([$c1->id], collect($resTax->json('data'))->pluck('id')->all());

        // 5. business_only=1&type=retail -> empty result
        $this->apiAs($token, 'GET', '/api/v1/admin/customers?business_only=1&type=retail')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // 6. per_page=101 -> 422
        $this->apiAs($token, 'GET', '/api/v1/admin/customers?per_page=101')
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');

        // 7. Ordered descending by id
        $resAll = $this->apiAs($token, 'GET', '/api/v1/admin/customers')->assertOk();
        $this->assertSame([$c3->id, $c2->id, $c1->id], collect($resAll->json('data'))->pluck('id')->all());
    }

    /**
     * 29 & Section 3 — منع استخدام نفس الإيميل في أنواع حسابات مختلفة + خصائص الإيميل في Customer
     */
    public function test_29_cross_account_email_uniqueness_and_customer_email_mutator(): void
    {
        [, $token] = $this->createStaff(['customers.create']);

        // 1. Staff has x@a.com -> creating customer with X@A.com fails with 422
        User::factory()->create(['email' => 'x@a.com']);
        $this->apiAs($token, 'POST', '/api/v1/admin/customers', [
            'name' => 'عميل',
            'email' => 'X@A.com',
            'phone' => '01012340001',
            'password' => 'valid-password-12',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        // 2. Retail customer with unverified email -> creating staff with same email is allowed
        Customer::factory()->create([
            'type' => CustomerType::Retail,
            'email' => 'unverified.retail@a.com',
            'email_verified_at' => null,
        ]);
        $staffWithRetailEmail = User::factory()->create(['email' => 'unverified.retail@a.com']);
        $this->assertDatabaseHas('users', ['id' => $staffWithRetailEmail->id, 'email' => 'unverified.retail@a.com']);

        // 3. Wholesale trader with unverified email -> creating staff with same email is rejected
        Customer::factory()->wholesale()->create([
            'email' => 'unverified.trader@a.com',
            'email_verified_at' => null,
        ]);
        try {
            User::factory()->create(['email' => 'UNVERIFIED.TRADER@a.com']);
            $this->fail('Creating staff with wholesale trader email must fail.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
        }

        // Section 3: email attribute order in mass assignment & case sensitivity
        // When email_verified_at is placed BEFORE email in new Customer([...]), email_verified_at is cleared if email_verified_at were fillable;
        // verify both orderings and setEmailAttribute behavior:
        $c1 = new Customer(['email_verified_at' => now(), 'email' => 'A@B.C']);
        $this->assertSame('a@b.c', $c1->email);
        $this->assertNull($c1->email_verified_at);

        $ts = now()->startOfSecond();
        $c = Customer::factory()->create(['email' => 'a@b.c', 'email_verified_at' => $ts]);
        // Setting same email with different case does NOT clear email_verified_at
        $c->email = '  A@B.C  ';
        $this->assertNotNull($c->email_verified_at);

        // Setting different email clears email_verified_at
        $c->email = 'other@b.c';
        $this->assertNull($c->email_verified_at);
    }

    /**
     * 30 & 31 — إصدار التوكنات وحدها الأقصى (10) وتسجيل دخول العميل على مستوى الخدمة
     */
    public function test_30_and_31_token_issuance_cap_and_customer_auth_service_login(): void
    {
        $this->freezeTime();
        config(['auth.customer_max_tokens' => 10, 'auth.customer_token_minutes' => 1440]);
        $auth = app(CustomerAuthService::class);

        $customer = Customer::factory()->create([
            'phone' => '01011223344',
            'email' => 'verified@example.com',
            'email_verified_at' => now(),
            'password' => 'secret-pass-123',
        ]);

        // 30 — Call issueToken 12 times -> only 10 newest remain, expires_at = +1440 mins
        for ($i = 1; $i <= 12; $i++) {
            $auth->issueToken($customer, 'tok-'.$i);
        }
        $tokens = $customer->tokens()->orderBy('id')->get();
        $this->assertCount(10, $tokens);
        $this->assertSame('tok-3', $tokens->first()->name);
        $this->assertSame('tok-12', $tokens->last()->name);
        $this->assertSame(now()->addMinutes(1440)->toDateTimeString(), $tokens->last()->expires_at->toDateTimeString());

        // 31 — CustomerAuthService::login cases:
        // 1. Phone with +20... -> succeeds
        $logged = $auth->login('+20 101 122 3344', 'secret-pass-123');
        $this->assertSame($customer->id, $logged->id);

        // 2. Verified email -> succeeds
        $logged = $auth->login('VERIFIED@example.com', 'secret-pass-123');
        $this->assertSame($customer->id, $logged->id);

        // 3. Unverified email: retail rejected (401), trader succeeds
        $retailUnv = Customer::factory()->create([
            'type' => CustomerType::Retail,
            'email' => 'ret.unv@example.com',
            'email_verified_at' => null,
            'password' => 'secret-pass-123',
        ]);
        try {
            $auth->login('ret.unv@example.com', 'secret-pass-123');
            $this->fail('Retail unverified email login must fail.');
        } catch (\App\Exceptions\BusinessException $e) {
            $this->assertSame(401, $e->status);
        }

        $traderUnv = Customer::factory()->wholesale()->create([
            'email' => 'ws.unv@example.com',
            'email_verified_at' => null,
            'password' => 'secret-pass-123',
        ]);
        $this->assertSame($traderUnv->id, $auth->login('ws.unv@example.com', 'secret-pass-123')->id);

        // 4. Wrong password -> 401, audit login_failed with HMAC (no raw phone)
        try {
            $auth->login($customer->phone, 'wrong-password');
            $this->fail('Wrong password must fail.');
        } catch (\App\Exceptions\BusinessException $e) {
            $this->assertSame(401, $e->status);
        }
        defer()->invoke();
        $failedLog = CustomerAuditLog::where('customer_id', $customer->id)->where('action', 'login_failed')->latest('id')->firstOrFail();
        $this->assertSame(hash_hmac('sha256', $customer->phone, (string) config('app.key')), $failedLog->metadata['identifier_key'] ?? null);
        $this->assertStringNotContainsString($customer->phone, json_encode($failedLog->metadata));

        // 5. Old hash needing rehash -> updates hash in DB
        $weakHash = password_hash('secret-pass-123', PASSWORD_BCRYPT, ['cost' => 4]);
        DB::table('customers')->where('id', $customer->id)->update(['password' => $weakHash]);
        Hash::driver('bcrypt')->setRounds(10);
        try {
            $auth->login($customer->phone, 'secret-pass-123');
            $this->assertNotSame($weakHash, DB::table('customers')->where('id', $customer->id)->value('password'));
        } finally {
            Hash::driver('bcrypt')->setRounds(4);
        }

        // 6. Suspended account -> 401 if wrong password, 403 if right password, and issueToken throws 403
        $customer->forceFill(['status' => CustomerStatus::Suspended, 'suspension_reason' => 'إيقاف إداري'])->save();
        try {
            $auth->login($customer->phone, 'wrong-password');
            $this->fail('Suspended with wrong password must return 401.');
        } catch (\App\Exceptions\BusinessException $e) {
            $this->assertSame(401, $e->status);
        }
        try {
            $auth->login($customer->phone, 'secret-pass-123');
            $this->fail('Suspended with right password must return 403.');
        } catch (\App\Exceptions\BusinessException $e) {
            $this->assertSame(403, $e->status);
        }
        try {
            $auth->issueToken($customer, 'blocked');
            $this->fail('issueToken on suspended customer must return 403.');
        } catch (\App\Exceptions\BusinessException $e) {
            $this->assertSame(403, $e->status);
        }
    }

    /**
     * Section 3 — الـ Middleware الخاص بالموظفين، معالجة الأخطاء العامة، وحماية البيانات
     */
    public function test_section3_staff_middleware_error_handling_and_data_protection(): void
    {
        // 1. is_system staff -> 403, inactive staff -> 403
        [, $sysToken] = $this->createStaff(['customers.view'], ['is_system' => true]);
        [, $inactToken] = $this->createStaff(['customers.view'], ['is_active' => false]);
        $this->apiAs($sysToken, 'GET', '/api/v1/admin/customers')->assertForbidden();
        $this->apiAs($inactToken, 'GET', '/api/v1/admin/customers')->assertForbidden();

        // 2. must_change_password -> 403 with code=password_change_required except GET me, PUT me/password, logout
        [, $mustRotateToken] = $this->createStaff(['customers.view'], ['must_change_password' => true]);
        $this->apiAs($mustRotateToken, 'GET', '/api/v1/admin/customers')
            ->assertForbidden()
            ->assertJsonPath('code', 'password_change_required');
        $this->apiAs($mustRotateToken, 'GET', '/api/v1/admin/me')->assertOk();

        // 3. Staff with deactivated branch -> 403 (using array cache cleared between checks)
        Cache::flush();
        $inactiveBranch = Branch::factory()->create(['is_active' => false]);
        [, $branchStaffToken] = $this->createStaff(['customers.view'], ['branch_id' => $inactiveBranch->id]);
        $this->apiAs($branchStaffToken, 'GET', '/api/v1/admin/customers')
            ->assertForbidden()
            ->assertJsonPath('message', 'الفرع المرتبط بحسابك متوقف حالياً.');

        // 4. Token with 'pos' ability only on admin routes -> 403
        [$staffUser] = $this->createStaff(['customers.view']);
        $posOnlyToken = $staffUser->createToken('pos-tok', ['pos'])->plainTextToken;
        $this->apiAs($posOnlyToken, 'GET', '/api/v1/admin/customers')
            ->assertForbidden()
            ->assertJsonPath('message', 'التوكن غير مصرح له بهذه الواجهة.');

        // 5. Route::pattern on customers/abc -> 404 (not 500)
        [, $validStaffToken] = $this->createStaff(['customers.view']);
        $this->apiAs($validStaffToken, 'GET', '/api/v1/admin/customers/abc')->assertNotFound();

        // 6. BusinessException with 503 includes Retry-After: 1 and errorCode in response
        $ex = new \App\Exceptions\BusinessException('مشغول', [], 503, 'service_busy');
        $rendered = $ex->render();
        $this->assertSame(503, $rendered->getStatusCode());
        $this->assertSame('1', $rendered->headers->get('Retry-After'));
        $this->assertSame('service_busy', $rendered->getData(true)['code'] ?? null);

        // 7. StaffAdministration::audit masks email and strips password
        StaffAdministration::audit(
            $staffUser,
            'staff.test_audit',
            'user',
            $staffUser->id,
            ['name', 'email', 'password', 'current_password'],
            ['email' => 'secret.admin@example.com', 'password' => 'plain-old'],
            ['email' => 'new.admin@example.com', 'password' => 'plain-new'],
        );
        $auditRow = DB::table('staff_audit_logs')->where('action', 'staff.test_audit')->first();
        $meta = json_decode($auditRow->metadata, true);
        $this->assertSame(['name', 'email'], $meta['fields']);
        $this->assertSame('se***@example.com', $meta['before']['email']);
        $this->assertSame('ne***@example.com', $meta['after']['email']);
        $this->assertArrayNotHasKey('password', $meta['before']);
        $this->assertArrayNotHasKey('password', $meta['after']);
    }
}
