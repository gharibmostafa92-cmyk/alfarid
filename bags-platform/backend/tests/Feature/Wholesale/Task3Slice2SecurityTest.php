<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\Customers\CustomerDocumentStatus;
use App\Enums\Customers\CustomerDocumentType;
use App\Enums\Customers\CustomerStatus;
use App\Enums\Customers\CustomerTier;
use App\Enums\Customers\CustomerType;
use App\Enums\Orders\OrderStatus;
use App\Enums\Finance\TreasuryType;
use App\Enums\Finance\TreasuryTransactionType;
use App\Enums\Orders\SalesChannel;
use App\Enums\Wholesale\WholesalePaymentNoticeStatus;
use App\Exceptions\BusinessException;
use App\Models\Address;
use App\Models\Branch;
use App\Models\Category;
use App\Models\City;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use Spatie\Permission\Models\Role;
use App\Models\Treasury;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Rules\RejectExecutableSignature;
use App\Services\Wholesale\QuotationService;
use App\Services\Wholesale\WholesaleRegistrationService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class Task3Slice2SecurityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;
    private Customer $wholesaleCustomer;
    private Treasury $treasury;
    private Branch $branchA;
    private Branch $branchB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');

        $this->branchA = Branch::factory()->create(['is_active' => true]);
        $this->branchB = Branch::factory()->create(['is_active' => true]);

        $this->treasury = Treasury::create([
            'name' => 'خزينة رئيسية',
            'code' => 'MAIN-'.uniqid(),
            'type' => TreasuryType::MainSafe,
            'current_balance' => 0,
            'branch_id' => $this->branchA->id,
            'is_active' => true,
        ]);

        $this->wholesaleCustomer = Customer::factory()->create([
            'type' => 'wholesale',
            'status' => 'approved',
            'credit_limit' => 100000,
        ]);
    }

    public function test_category_hierarchy_filtering_excludes_products_with_inactive_ancestors(): void
    {
        // 1. Root G inactive -> Parent P active -> Child C active
        $gInactive = Category::factory()->create(['name_ar' => 'جد معطل', 'is_active' => false]);
        $pActive1 = Category::factory()->create(['name_ar' => 'أب نشط 1', 'is_active' => true, 'parent_id' => $gInactive->id]);
        $cActive1 = Category::factory()->create(['name_ar' => 'ابن نشط 1', 'is_active' => true, 'parent_id' => $pActive1->id]);

        $prod1 = Product::factory()->create([
            'name_ar' => 'منتج بجد معطل',
            'category_id' => $cActive1->id,
            'status' => 'published',
            'sales_channel' => SalesChannel::Wholesale,
        ]);
        ProductVariant::factory()->create(['product_id' => $prod1->id, 'is_active' => true, 'sku' => 'SKU-INACTIVE-G']);

        // 2. Root G active -> Parent P inactive -> Child C active
        $gActive = Category::factory()->create(['name_ar' => 'جد نشط', 'is_active' => true]);
        $pInactive = Category::factory()->create(['name_ar' => 'أب معطل', 'is_active' => false, 'parent_id' => $gActive->id]);
        $cActive2 = Category::factory()->create(['name_ar' => 'ابن نشط 2', 'is_active' => true, 'parent_id' => $pInactive->id]);

        $prod2 = Product::factory()->create([
            'name_ar' => 'منتج بأب معطل',
            'category_id' => $cActive2->id,
            'status' => 'published',
            'sales_channel' => SalesChannel::Wholesale,
        ]);
        ProductVariant::factory()->create(['product_id' => $prod2->id, 'is_active' => true, 'sku' => 'SKU-INACTIVE-P']);

        // 3. Root G active -> Parent P active -> Child C active (all active)
        $pActive2 = Category::factory()->create(['name_ar' => 'أب نشط 2', 'is_active' => true, 'parent_id' => $gActive->id]);
        $cActive3 = Category::factory()->create(['name_ar' => 'ابن نشط 3', 'is_active' => true, 'parent_id' => $pActive2->id]);

        $prod3 = Product::factory()->create([
            'name_ar' => 'منتج نشط بالكامل',
            'category_id' => $cActive3->id,
            'status' => 'published',
            'sales_channel' => SalesChannel::Wholesale,
        ]);
        ProductVariant::factory()->create(['product_id' => $prod3->id, 'is_active' => true, 'sku' => 'SKU-ACTIVE-ALL']);

        $token = $this->wholesaleCustomer->createToken('wholesale')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/v1/wholesale/products');
        $response->assertOk();

        $returnedIds = collect($response->json('data'))->pluck('id')->all();

        $this->assertNotContains($prod1->id, $returnedIds, 'Product with inactive grandparent must be excluded');
        $this->assertNotContains($prod2->id, $returnedIds, 'Product with inactive parent must be excluded');
        $this->assertContains($prod3->id, $returnedIds, 'Product with active ancestors must be included');
    }

    public function test_approve_and_reject_prevent_lazy_loading_violations(): void
    {
        Model::preventLazyLoading(true);

        $order = Order::factory()->create([
            'customer_id' => $this->wholesaleCustomer->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'grand_total' => '1000.00',
            'allocated_branch_id' => $this->branchA->id,
        ]);

        $notice1 = WholesalePaymentNotice::factory()->create([
            'customer_id' => $this->wholesaleCustomer->id,
            'order_id' => $order->id,
            'amount' => '400.00',
            'status' => WholesalePaymentNoticeStatus::PendingReview,
        ]);

        $token = $this->admin->createToken('admin')->plainTextToken;

        // Approve test
        $resApprove = $this->withToken($token)->postJson("/api/v1/admin/payment-notices/{$notice1->id}/approve", [
            'treasury_id' => $this->treasury->id,
            'admin_notes' => 'Approved cleanly',
        ]);
        $resApprove->assertOk();

        $notice2 = WholesalePaymentNotice::factory()->create([
            'customer_id' => $this->wholesaleCustomer->id,
            'order_id' => $order->id,
            'amount' => '300.00',
            'status' => WholesalePaymentNoticeStatus::PendingReview,
        ]);

        // Reject test
        $resReject = $this->withToken($token)->postJson("/api/v1/admin/payment-notices/{$notice2->id}/reject", [
            'admin_notes' => 'Rejected cleanly',
        ]);
        $resReject->assertOk();

        Model::preventLazyLoading(false);
    }

    public function test_reverse_payment_notice_endpoint_reverses_approval(): void
    {
        $order = Order::factory()->create([
            'customer_id' => $this->wholesaleCustomer->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'grand_total' => '1000.00',
            'allocated_branch_id' => $this->branchA->id,
        ]);

        $notice = WholesalePaymentNotice::factory()->create([
            'customer_id' => $this->wholesaleCustomer->id,
            'order_id' => $order->id,
            'amount' => '1000.00',
            'status' => WholesalePaymentNoticeStatus::PendingReview,
        ]);

        $token = $this->admin->createToken('admin')->plainTextToken;

        // First approve
        $this->withToken($token)->postJson("/api/v1/admin/payment-notices/{$notice->id}/approve", [
            'treasury_id' => $this->treasury->id,
        ])->assertOk();

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);

        // Now reverse
        $resReverse = $this->withToken($token)->postJson("/api/v1/admin/payment-notices/{$notice->id}/reverse", [
            'reason' => 'Bounced bank cheque',
        ]);
        $resReverse->assertOk();

        $notice->refresh();
        $this->assertSame(WholesalePaymentNoticeStatus::Reversed, $notice->status);
        $this->assertNotNull($notice->reversed_at);
        $this->assertNotNull($notice->reversal_treasury_transaction_id);

        $order->refresh();
        $this->assertSame('unpaid', $order->payment_status, 'Order should be reopened to unpaid after reverse');
    }

    public function test_restricted_branch_approver_does_not_settle_orders_of_other_branches(): void
    {
        // Branch staff restricted to Branch A
        $branchStaff = User::factory()->create(['branch_id' => $this->branchA->id]);
        $role = Role::firstOrCreate(['name' => 'branch_officer', 'guard_name' => 'staff']);
        $role->givePermissionTo('payment_notices.view', 'payment_notices.approve');
        $branchStaff->assignRole($role);

        // Order 1 at Branch A (500)
        $orderA = Order::factory()->create([
            'customer_id' => $this->wholesaleCustomer->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'grand_total' => '500.00',
            'allocated_branch_id' => $this->branchA->id,
        ]);

        // Order 2 at Branch B (500)
        $orderB = Order::factory()->create([
            'customer_id' => $this->wholesaleCustomer->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'grand_total' => '500.00',
            'allocated_branch_id' => $this->branchB->id,
        ]);

        // Notice linked to Order A for 1000 EGP (has 500 EGP excess)
        $notice = WholesalePaymentNotice::factory()->create([
            'customer_id' => $this->wholesaleCustomer->id,
            'order_id' => $orderA->id,
            'amount' => '500.00',
            'status' => WholesalePaymentNoticeStatus::PendingReview,
        ]);

        $token = $branchStaff->createToken('branch-staff')->plainTextToken;

        $res = $this->withToken($token)->postJson("/api/v1/admin/payment-notices/{$notice->id}/approve", [
            'treasury_id' => $this->treasury->id,
        ]);
        $res->assertOk();

        $orderA->refresh();
        $orderB->refresh();

        $this->assertSame('paid', $orderA->payment_status);
        $this->assertSame('unpaid', $orderB->payment_status, 'Branch B order must NOT be settled by Branch A staff');
    }

    public function test_reject_executable_signature_blocks_elf_macho_and_scripts(): void
    {
        $rule = new RejectExecutableSignature;

        $check = function (string $content) use ($rule): bool {
            $file = UploadedFile::fake()->createWithContent('test.bin', $content);
            $validator = Validator::make(['doc' => $file], ['doc' => [$rule]]);
            return $validator->passes();
        };

        // Block PE
        $this->assertFalse($check("MZ\x90\x00\x03\x00\x00\x00"));

        // Block ELF
        $this->assertFalse($check("\x7FELF\x01\x01\x01\x00"));

        // Block Mach-O 32-bit & 64-bit
        $this->assertFalse($check("\xFE\xED\xFA\xCE\x00\x00\x00\x00"));
        $this->assertFalse($check("\xFE\xED\xFA\xCF\x00\x00\x00\x00"));
        $this->assertFalse($check("\xCE\xFA\xED\xFE\x00\x00\x00\x00"));
        $this->assertFalse($check("\xCF\xFA\xED\xFE\x00\x00\x00\x00"));

        // Block Mach-O Fat Binary
        $this->assertFalse($check("\xCA\xFE\xBA\xBE\x00\x00\x00\x00"));

        // Block scripts
        $this->assertFalse($check("<?php echo 'malicious'; ?>"));
        $this->assertFalse($check("#!/bin/bash\nrm -rf /"));

        // Allow legitimate files
        $this->assertTrue($check("%PDF-1.4 sample content"));
        $this->assertTrue($check("\xFF\xD8\xFF\xE0\x00\x10JFIF")); // JPEG
        $this->assertTrue($check("\x89PNG\r\n\x1a\n\x00\x00")); // PNG
        $this->assertTrue($check("sku,qty\nSKU-1,10\n")); // CSV
    }

    public function test_wholesale_registration_uploads_directly_without_staging_move(): void
    {
        Storage::fake('r2');

        $service = app(WholesaleRegistrationService::class);

        $doc1 = UploadedFile::fake()->create('cr.pdf', 100, 'application/pdf');
        $doc2 = UploadedFile::fake()->create('tax.png', 100, 'image/png');

        $data = [
            'name' => 'تاجر تجربة مباشر',
            'email' => 'direct.upload@trader.test',
            'phone' => '01099887766',
            'password' => 'SecurePass123!',
            'company_name' => 'شركة الاستيراد المباشر',
            'tax_number' => '123456789',
            'commercial_register' => '987654321',
        ];

        // Seed mock phone verification proof if required
        session()->put('phone_verified_'.$data['phone'], true);

        $result = $service->register($data, [$doc1, $doc2], ['commercial_register', 'tax_card']);

        $customer = $result['customer'];
        $this->assertNotNull($customer->id);

        $docs = CustomerDocument::where('customer_id', $customer->id)->get();
        $this->assertCount(2, $docs);

        foreach ($docs as $doc) {
            $this->assertStringStartsWith("customer_documents/{$customer->id}/", $doc->file_path);
            $this->assertStringNotContainsString('pending', $doc->file_path);
            Storage::disk('r2')->assertExists($doc->file_path);
        }

        // Verify no pending directories exist
        $allFiles = Storage::disk('r2')->allFiles();
        foreach ($allFiles as $filePath) {
            $this->assertStringNotContainsString('pending', $filePath);
        }
    }

    public function test_ensure_wholesale_approved_returns_distinct_error_code_on_403(): void
    {
        $pendingCustomer = Customer::factory()->create([
            'type' => \App\Enums\Customer\CustomerType::Wholesale,
            'status' => \App\Enums\Customer\CustomerStatus::Pending,
        ]);

        $token = $pendingCustomer->createToken('wholesale-test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/wholesale/dashboard')
            ->assertForbidden()
            ->assertJsonPath('code', 'wholesale_not_approved');
    }

    public function test_quotation_create_allows_unpriced_custom_variants(): void
    {
        $customer = Customer::factory()->create([
            'type' => \App\Enums\Customer\CustomerType::Wholesale,
            'status' => \App\Enums\Customer\CustomerStatus::Approved,
            'credit_limit' => '100000.00',
        ]);

        $category = Category::factory()->create(['is_active' => true]);
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'status' => 'published',
            'sales_channel' => 'wholesale',
        ]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'is_active' => true,
            'retail_price' => null,
            'wholesale_price' => null,
        ]);

        $quotation = app(QuotationService::class)->create($customer, [
            'items' => [
                ['product_variant_id' => $variant->id, 'qty' => 10, 'requested_target_price' => 150.0],
            ],
        ]);

        $this->assertCount(1, $quotation->items);
        $this->assertSame(0.0, (float) $quotation->items->first()->offered_unit_price);
        $this->assertSame(150.0, (float) $quotation->items->first()->requested_target_price);
    }
}
