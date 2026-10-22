<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Customers;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\User;
use Database\Seeders\CustomerPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** مهمة 5.4: إدارة العملاء واعتماد الجملة في لوحة الإدارة. */
class AdminCustomerManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ALL_CUSTOMER_PERMISSIONS = [
        'customers.view', 'customers.create', 'customers.update', 'customers.delete', 'customers.approve',
        'customers.documents.view',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CustomerPermissionSeeder::class);
    }

    private function staffToken(array $permissions = self::ALL_CUSTOMER_PERMISSIONS): string
    {
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'staff');
        }
        $staff = User::factory()->create();
        $staff->givePermissionTo($permissions);

        return $staff->createToken('test')->plainTextToken;
    }

    private function api(string $token, string $method, string $uri, array $data = []): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->json($method, $uri, $data);
    }

    public function test_index_filters_by_type_status_and_search(): void
    {
        $token = $this->staffToken();
        Customer::factory()->create(['name' => 'قطاعي أحمد', 'type' => CustomerType::Retail, 'status' => CustomerStatus::Approved]);
        Customer::factory()->wholesale()->create(['company_name' => 'شركة النيل', 'commercial_register' => 'CR-555']);
        Customer::factory()->create(['type' => CustomerType::Distributor, 'status' => CustomerStatus::Suspended]);

        $this->api($token, 'GET', '/api/v1/admin/customers')->assertOk()->assertJsonCount(3, 'data');
        $this->api($token, 'GET', '/api/v1/admin/customers?type=wholesale')->assertOk()->assertJsonCount(1, 'data');
        $this->api($token, 'GET', '/api/v1/admin/customers?status=suspended')->assertOk()->assertJsonCount(1, 'data');
        $this->api($token, 'GET', '/api/v1/admin/customers?search=CR-555')->assertOk()->assertJsonCount(1, 'data');
        $this->api($token, 'GET', '/api/v1/admin/customers?search=أحمد')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_admin_crud_and_show_with_documents_and_group(): void
    {
        $this->fakeUploads();
        $token = $this->staffToken();
        $group = CustomerGroup::create(['name' => 'ذهبي', 'code' => 'gold', 'discount_percentage' => 10]);

        $created = $this->api($token, 'POST', '/api/v1/admin/customers', [
            'name' => 'جملة جديد', 'phone' => '01099990000', 'password' => 'password123',
            'type' => 'wholesale', 'company_name' => 'شركة س', 'customer_group_id' => $group->id,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending') // جملة من الأدمن: قيد المراجعة افتراضيًا
            ->assertJsonPath('data.customer_group.name', 'ذهبي');
        $id = $created->json('data.id');

        $customer = Customer::findOrFail($id);
        $path = Storage::disk('r2')->putFileAs("customer_documents/{$id}", UploadedFile::fake()->create('reg.pdf', 50, 'application/pdf'), 'reg.pdf');
        $customer->documents()->create([
            'document_type' => 'commercial_register', 'file_path' => $path,
            'original_name' => 'reg.pdf', 'mime_type' => 'application/pdf', 'status' => 'pending',
        ]);

        $show = $this->api($token, 'GET', "/api/v1/admin/customers/{$id}")->assertOk();
        $this->assertSame('commercial_register', $show->json('data.documents.0.document_type'));
        $this->assertStringContainsString('/documents/', $show->json('data.documents.0.download_url'));

        $this->api($token, 'PUT', "/api/v1/admin/customers/{$id}", ['credit_limit' => 25000])
            ->assertOk()->assertJsonPath('data.credit_limit', '25000.00');

        $this->api($token, 'DELETE', "/api/v1/admin/customers/{$id}")->assertOk();
        $this->assertSoftDeleted('customers', ['id' => $id]);
        $this->api($token, 'GET', "/api/v1/admin/customers/{$id}")->assertNotFound();
    }

    public function test_document_download_is_scoped_and_protected(): void
    {
        $this->fakeUploads();
        $token = $this->staffToken();
        $customer = Customer::factory()->wholesale()->create();
        $other = Customer::factory()->wholesale()->create();
        $path = Storage::disk('r2')->putFileAs("customer_documents/{$customer->id}", UploadedFile::fake()->create('secret.pdf', 50, 'application/pdf'), 'secret.pdf');
        $document = $customer->documents()->create([
            'document_type' => 'tax_card', 'file_path' => $path,
            'original_name' => 'secret.pdf', 'mime_type' => 'application/pdf', 'status' => 'pending',
        ]);

        // نفس العميل: تنزيل ناجح
        Auth::forgetGuards();
        $this->withToken($token)->get("/api/v1/admin/customers/{$customer->id}/documents/{$document->id}/download")
            ->assertOk();

        // مستند تحت عميل آخر: 404 بالربط المقيّد
        $this->api($token, 'GET', "/api/v1/admin/customers/{$other->id}/documents/{$document->id}/download")
            ->assertNotFound();

        // بلا صلاحية العرض: 403
        $noView = $this->staffToken(['customers.approve']);
        $this->api($noView, 'GET', "/api/v1/admin/customers/{$customer->id}/documents/{$document->id}/download")
            ->assertForbidden();
    }

    public function test_approve_sets_group_credit_promotion_and_audit_fields(): void
    {
        $token = $this->staffToken();
        $admin = User::where('id', '!=', null)->latest('id')->first(); // آخر موظف أنشأ التوكنات
        $group = CustomerGroup::create(['name' => 'موزعون', 'code' => 'distributors', 'discount_percentage' => 15]);
        $customer = Customer::factory()->wholesale()->create();

        $customer->documents()->create(['document_type' => 'commercial_register', 'file_path' => 'verified.pdf', 'original_name' => 'verified.pdf', 'mime_type' => 'application/pdf', 'status' => 'verified']);
        $response = $this->api($token, 'POST', "/api/v1/admin/customers/{$customer->id}/approve", [
            'customer_group_id' => $group->id, 'credit_limit' => 50000, 'type' => 'distributor',
        ])->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.type', 'distributor')
            ->assertJsonPath('data.credit_limit', '50000.00');

        $customer->refresh();
        $this->assertSame(CustomerStatus::Approved, $customer->status);
        $this->assertNotNull($customer->approved_at);
        $this->assertNotNull($customer->approved_by);
        $this->assertSame($group->id, $customer->customer_group_id);

        // اعتماد مكرر مرفوض
        $this->api($token, 'POST', "/api/v1/admin/customers/{$customer->id}/approve")->assertStatus(422);
    }

    public function test_reject_requires_reason_and_keeps_token_alive_for_status_check(): void
    {
        $token = $this->staffToken();
        $customer = Customer::factory()->wholesale()->create();
        $customerToken = $customer->createToken('wholesale-token')->plainTextToken;

        $this->api($token, 'POST', "/api/v1/admin/customers/{$customer->id}/reject", [])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->api($token, 'POST', "/api/v1/admin/customers/{$customer->id}/reject", ['reason' => 'مستندات ناقصة'])
            ->assertOk()->assertJsonPath('data.rejection_reason', 'مستندات ناقصة');
        $this->assertSame(CustomerStatus::Rejected, $customer->refresh()->status);

        // توكن التاجر ما زال حيًا ليرى سبب الرفض
        Auth::forgetGuards();
        $this->withToken($customerToken)->getJson('/api/v1/wholesale/auth/application-status')
            ->assertOk()->assertJsonPath('data.rejection_reason', 'مستندات ناقصة');
    }

    public function test_suspend_sets_reason_and_kills_all_customer_tokens_immediately(): void
    {
        $token = $this->staffToken();
        $customer = Customer::factory()->create(['status' => CustomerStatus::Approved]);
        $sessionA = $customer->createToken('store-token')->plainTextToken;
        $sessionB = $customer->createToken('store-token')->plainTextToken;

        $this->api($token, 'POST', "/api/v1/admin/customers/{$customer->id}/suspend", [])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->api($token, 'POST', "/api/v1/admin/customers/{$customer->id}/suspend", ['reason' => 'احتيال'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.suspension_reason', 'احتيال');

        $this->assertSame(0, $customer->tokens()->count());

        // الجلستان ماتتا فورًا: الخروج بهما يرجع 401، والدخول الجديد مرفوض 403
        Auth::forgetGuards();
        $this->withToken($sessionA)->postJson('/api/v1/store/auth/logout')->assertUnauthorized();
        $this->postJson('/api/v1/store/auth/login', ['login' => $customer->phone, 'password' => 'password'])
            ->assertForbidden();
    }

    public function test_customers_endpoints_require_permissions(): void
    {
        $customer = Customer::factory()->create();
        $viewer = $this->staffToken(['customers.view']);
        $this->api($viewer, 'GET', '/api/v1/admin/customers')->assertOk();
        $this->api($viewer, 'POST', '/api/v1/admin/customers', [
            'name' => 'X', 'phone' => '01000000001', 'password' => 'password123',
        ])->assertForbidden();
        $this->api($viewer, 'POST', "/api/v1/admin/customers/{$customer->id}/approve")->assertForbidden();

        $noPerms = $this->staffToken([]);
        $this->api($noPerms, 'GET', '/api/v1/admin/customers')->assertForbidden();
    }
}
