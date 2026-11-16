<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Enums\Wholesale\WholesalePaymentNoticeStatus;
use App\Events\Orders\OrderCreated;
use App\Http\Requests\Wholesale\StorePaymentNoticeRequest;
use App\Jobs\ScoreWholesaleReceipt;
use App\Models\Branch;
use App\Models\City;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Rules\RejectExecutableSignature;
use App\Services\Wholesale\BankStatementMatcher;
use App\Services\Wholesale\QuotationService;
use Carbon\Carbon;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;
use ZipArchive;

class Task6Slice1RegressionTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('r2');
    }

    public function test_f02_registration_rollback_purges_uploaded_documents_without_undefined_variable_error(): void
    {
        Customer::factory()->create([
            'phone' => '01022223333',
            'email' => 'existing@example.com',
        ]);

        // Duplicate tax_number or phone inside transaction after uploading documents should return 422, not 500 TypeError.
        $response = $this->postJson('/api/v1/wholesale/auth/register', [
            'name' => 'تاجر جملة',
            'email' => 'new-ws@example.com',
            'phone' => '01022223333',
            'password' => 'secure-password123',
            'company_name' => 'شركة الأفق للتجارة',
            'tax_number' => '123456789',
            'commercial_register' => '987654',
            'documents' => [
                UploadedFile::fake()->create('register.pdf', 200, 'application/pdf'),
            ],
            'document_types' => ['commercial_register'],
        ]);

        $response->assertStatus(422);
        $this->assertSame([], Storage::disk('r2')->allFiles('customer_documents'));
    }

    public function test_f03_reject_executable_signature_blocks_zip_by_default_but_allows_valid_xlsx_when_enabled(): void
    {
        $tmpXlsx = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmpXlsx, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook/>');
        $zip->close();

        $file = new UploadedFile($tmpXlsx, 'quick.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $defaultValidator = Validator::make(['file' => $file], ['file' => [new RejectExecutableSignature]]);
        $this->assertTrue($defaultValidator->fails(), 'Default RejectExecutableSignature must still reject PK\\x03\\x04.');

        $ooxmlValidator = Validator::make(['file' => $file], ['file' => [new RejectExecutableSignature(allowOfficeOpenXml: true)]]);
        $this->assertFalse($ooxmlValidator->fails(), 'OOXML mode must allow valid .xlsx archives.');

        // Macro-enabled archive with vbaProject.bin must still be rejected even in OOXML mode.
        $tmpMacro = tempnam(sys_get_temp_dir(), 'xlsm').'.xlsx';
        $zip2 = new ZipArchive;
        $zip2->open($tmpMacro, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip2->addFromString('[Content_Types].xml', '<Types/>');
        $zip2->addFromString('xl/workbook.xml', '<workbook/>');
        $zip2->addFromString('xl/vbaProject.bin', 'macro-payload');
        $zip2->close();

        $macroFile = new UploadedFile($tmpMacro, 'macro.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $macroValidator = Validator::make(['file' => $macroFile], ['file' => [new RejectExecutableSignature(allowOfficeOpenXml: true)]]);
        $this->assertTrue($macroValidator->fails(), 'OOXML mode must reject archives containing vbaProject.bin.');

        @unlink($tmpXlsx);
        @unlink($tmpMacro);
    }

    public function test_f10_score_wholesale_receipt_and_quotation_accept_defer_dispatch_until_after_commit(): void
    {
        $job = new ScoreWholesaleReceipt(123);
        $this->assertTrue($job->afterCommit, 'ScoreWholesaleReceipt must set afterCommit = true.');

        $this->seed(SettingSeeder::class);
        $admin = User::factory()->create();
        $branch = Branch::factory()->create(['is_active' => true]);
        $customer = Customer::factory()->create([
            'type' => CustomerType::Wholesale,
            'status' => CustomerStatus::Approved,
            'credit_limit' => 50000,
            'payment_terms_days' => 30,
        ]);
        $city = City::factory()->create();
        CustomerAddress::query()->create([
            'customer_id' => $customer->id,
            'city_id' => $city->id,
            'address_line' => '123 شارع النيل',
            'is_default' => true,
        ]);
        $product = Product::factory()->create([
            'status' => 'published',
            'sales_channel' => 'both',
        ]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'is_active' => true,
            'wholesale_price' => 100,
        ]);
        StockLevel::forceCreate([
            'branch_id' => $branch->id,
            'product_variant_id' => $variant->id,
            'on_hand' => 100,
            'reserved' => 0,
            'damaged' => 0,
            'in_transit' => 0,
        ]);

        $service = app(QuotationService::class);
        $quotation = $service->create($customer, [
            'items' => [['product_variant_id' => $variant->id, 'qty' => 2]],
        ]);
        $quotation = $service->submit($quotation);
        $quotation = $service->price($quotation, [
            'valid_until' => now('Africa/Cairo')->addDays(7)->toDateString(),
            'items' => [['id' => $quotation->items->first()->id, 'offered_unit_price' => 100]],
        ], $admin->id);

        Event::fake([OrderCreated::class]);
        $dispatchedDuringTx = false;
        DB::transaction(function () use ($service, $quotation, &$dispatchedDuringTx): void {
            $service->accept($quotation);
            $dispatchedDuringTx = Event::dispatched(OrderCreated::class)->isNotEmpty();
        });

        $this->assertFalse($dispatchedDuringTx, 'OrderCreated must NOT dispatch before the outer DB transaction commits.');
        Event::assertDispatched(OrderCreated::class);
    }

    public function test_f15_store_payment_notice_uses_cairo_business_timezone_for_transfer_date(): void
    {
        // 2026-11-16 22:30:00 UTC is 2026-11-17 00:30:00 in Africa/Cairo
        Carbon::setTestNow(Carbon::create(2026, 11, 16, 22, 30, 0, 'UTC'));
        try {
            $request = new StorePaymentNoticeRequest;
            $validator = Validator::make([
                'amount' => '500.00',
                'payment_method' => 'bank_transfer',
                'bank_name' => 'CIB',
                'reference_number' => 'REF-TZ-1',
                'transfer_date' => '2026-11-17',
                'receipt' => UploadedFile::fake()->image('slip.jpg'),
            ], $request->rules());

            $this->assertFalse($validator->fails(), 'Cairo date 2026-11-17 must be accepted at 22:30 UTC.');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_f20_bank_statement_matcher_rejects_negative_and_parenthesized_amounts(): void
    {
        $this->assertSame('0.00', BankStatementMatcher::cleanAmount('-1500.00'));
        $this->assertSame('0.00', BankStatementMatcher::cleanAmount('(1,500.00)'));
        $this->assertSame('1500.50', BankStatementMatcher::cleanAmount('1.500,50'));

        $customer = Customer::factory()->create([
            'type' => CustomerType::Wholesale,
            'status' => CustomerStatus::Approved,
        ]);
        WholesalePaymentNotice::query()->create([
            'notice_number' => 'WPN-NEG-1',
            'customer_id' => $customer->id,
            'amount' => '1500.00',
            'payment_method' => 'bank_transfer',
            'bank_name' => 'CIB',
            'reference_number' => 'ANY-REF',
            'transfer_date' => '2026-11-10',
            'receipt_file_path' => 'receipts/test.jpg',
            'status' => WholesalePaymentNoticeStatus::PendingReview,
        ]);

        $result = app(BankStatementMatcher::class)->match([
            ['amount' => '-1500.00', 'date' => '2026-11-10', 'reference' => ''],
            ['amount' => '(1,500.00)', 'date' => '2026-11-10', 'reference' => 'ANY-REF'],
        ]);

        $this->assertSame([], $result['exact']);
        $this->assertSame([], $result['probable']);
        $this->assertCount(2, $result['unmatched']);
    }
}
