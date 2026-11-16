<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\Customer\CustomerDocumentStatus;
use App\Enums\Customer\CustomerDocumentType;
use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Exceptions\BusinessException;
use App\Jobs\SendCustomerPasswordReset;
use App\Jobs\SendCustomerPhoneVerification;
use App\Models\Customer;
use App\Models\CustomerChannel;
use App\Models\CustomerDocument;
use App\Models\User;
use App\Rules\MaxImagePixels;
use App\Rules\RejectExecutableSignature;
use App\Rules\UniqueCustomerEmail;
use App\Services\Auth\CustomerAuthService;
use App\Services\Customer\CustomerManagementService;
use App\Services\Customer\CustomerPhoneVerificationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Task5CustomerSlice4Test extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_kyc_upload_and_download_hardening(): void
    {
        $pdfPath = tempnam(sys_get_temp_dir(), 'pdf_');
        file_put_contents($pdfPath, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<<>>\n%%EOF");
        $cleanPdf = new UploadedFile($pdfPath, 'doc.pdf', 'application/pdf', null, true);

        $vPdf = Validator::make(['doc' => $cleanPdf], ['doc' => [new MaxImagePixels(100), new RejectExecutableSignature]]);
        $this->assertFalse($vPdf->fails(), 'Clean PDF must pass MaxImagePixels and RejectExecutableSignature');

        $jsPdfPath = tempnam(sys_get_temp_dir(), 'jspdf_');
        file_put_contents($jsPdfPath, "%PDF-1.4\n1 0 obj\n<< /OpenAction << /S /JavaScript /JS (app.alert(1)) >> >>\nendobj");
        $jsPdf = new UploadedFile($jsPdfPath, 'evil.pdf', 'application/pdf', null, true);

        $vJsPdf = Validator::make(['doc' => $jsPdf], ['doc' => [new RejectExecutableSignature]]);
        $this->assertTrue($vJsPdf->fails(), 'PDF with /JavaScript must be rejected');

        $zipPath = tempnam(sys_get_temp_dir(), 'zip_');
        file_put_contents($zipPath, "PK\x03\x04dummy-zip-polyglot");
        $zipFile = new UploadedFile($zipPath, 'poly.pdf', 'application/pdf', null, true);
        $vZip = Validator::make(['doc' => $zipFile], ['doc' => [new RejectExecutableSignature]]);
        $this->assertTrue($vZip->fails(), 'ZIP/JAR polyglot signature must be rejected');

        // Test downloadDocument headers & extension enforcement.
        Storage::fake('r2');
        $staff = User::factory()->create();
        $role = Role::findOrCreate('Super Admin', 'staff');
        $role->givePermissionTo(Permission::findOrCreate('customers.documents.view', 'staff'));
        $staff->assignRole($role);

        $customer = Customer::factory()->wholesale()->create();
        Storage::disk('r2')->put("customer_documents/{$customer->id}/file.bin", "%PDF-1.4\nclean");
        $doc = CustomerDocument::create([
            'customer_id' => $customer->id,
            'document_type' => CustomerDocumentType::CommercialRegister,
            'file_path' => "customer_documents/{$customer->id}/file.bin",
            'original_name' => 'malicious.html',
            'mime_type' => 'application/pdf',
            'status' => CustomerDocumentStatus::Pending,
        ]);

        $res = $this->withToken($staff->createToken('staff')->plainTextToken)
            ->get("/api/v1/admin/customers/{$customer->id}/documents/{$doc->id}/download")
            ->assertOk();

        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('.pdf', (string) $res->headers->get('Content-Disposition'));

        @unlink($pdfPath);
        @unlink($jsPdfPath);
        @unlink($zipPath);
    }

    public function test_unverified_retail_email_does_not_squat_wholesale_or_other_accounts(): void
    {
        $squatter = Customer::factory()->create([
            'type' => CustomerType::Retail,
            'email' => 'ceo@company.test',
            'email_verified_at' => null,
        ]);

        $v = Validator::make(['email' => 'ceo@company.test'], ['email' => [new UniqueCustomerEmail]]);
        $this->assertFalse($v->fails(), 'Unverified retail email must not block new registrations');
    }

    public function test_restore_and_store_update_handle_soft_deleted_phone_collisions(): void
    {
        $staff = User::factory()->create();
        $role = Role::findOrCreate('Super Admin', 'staff');
        $role->givePermissionTo(Permission::findOrCreate('customers.create', 'staff'));
        $role->givePermissionTo(Permission::findOrCreate('customers.update', 'staff'));
        $role->givePermissionTo(Permission::findOrCreate('customers.approve', 'staff'));
        $staff->assignRole($role);
        $token = $staff->createToken('staff')->plainTextToken;

        $deleted = Customer::factory()->create(['phone' => '01099887766']);
        $deleted->delete();

        // StoreCustomerRequest must allow reusing a soft-deleted customer's phone.
        $this->withToken($token)->postJson('/api/v1/admin/customers', [
            'name' => 'New Owner',
            'phone' => '01099887766',
            'password' => 'StrongPass1234',
        ])->assertCreated();

        // Restoring the old deleted customer must throw a controlled 422 BusinessException, not 500.
        try {
            app(CustomerManagementService::class)->restore($deleted, $staff->id);
            $this->fail('Expected BusinessException on phone collision during restore');
        } catch (BusinessException $e) {
            $this->assertSame(422, $e->status);
        }
    }

    public function test_customer_channel_revoked_on_suspend_delete_and_phone_change_and_login_checks_phone_verification(): void
    {
        $customer = Customer::factory()->create(['phone' => '01011223344', 'phone_verified_at' => now()]);
        CustomerChannel::create([
            'customer_id' => $customer->id,
            'provider' => 'whatsapp',
            'phone' => '01011223344',
            'verification_status' => 'verified',
            'verified_at' => now(),
        ]);

        $service = app(CustomerManagementService::class);
        $service->update($customer, ['phone' => '01055667788'], null);
        $this->assertDatabaseMissing('customer_channels', ['customer_id' => $customer->id, 'verification_status' => 'verified']);

        CustomerChannel::create([
            'customer_id' => $customer->id,
            'provider' => 'whatsapp',
            'phone' => '01055667788',
            'verification_status' => 'verified',
            'verified_at' => now(),
        ]);
        $service->suspend($customer->fresh(), 'abuse', null);
        $this->assertDatabaseMissing('customer_channels', ['customer_id' => $customer->id, 'verification_status' => 'verified']);

        // Unverified phone blocked at login when CUSTOMER_REQUIRE_PHONE_VERIFICATION=true.
        config(['auth.require_phone_verification' => true]);
        $unverified = Customer::factory()->create([
            'phone' => '01077889900',
            'password' => 'SecretPass1234',
            'phone_verified_at' => null,
        ]);

        try {
            app(CustomerAuthService::class)->login('01077889900', 'SecretPass1234');
            $this->fail('Expected BusinessException for unverified phone when required');
        } catch (BusinessException $e) {
            $this->assertSame(403, $e->status);
        }
    }

    public function test_phone_normalization_handles_double_zero_country_prefix_and_arabic_digits(): void
    {
        $this->assertSame('01012345678', Customer::normalizePhone('+2001012345678'));
        $this->assertSame('01012345678', Customer::normalizePhone('002001012345678'));
        $this->assertSame('01012345678', Customer::normalizePhone('٠١٠١٢٣٤٥٦٧٨'));

        // Verify NormalizeCustomerPhones dry-run executes cleanly without SQL/translate errors.
        $this->artisan('customers:normalize-phones')->assertSuccessful();
    }

    public function test_phone_verification_allows_resend_after_verified_if_cooldown_elapsed_and_jobs_have_retries(): void
    {
        Notification::fake();

        $svc = app(CustomerPhoneVerificationService::class);
        $svc->send('01012349876', 'test-subnet-key');

        // Simulate user verified the OTP, then refreshed the page losing React proof state, and 61s elapsed.
        DB::table('customer_phone_verifications')->update([
            'verified_at' => now()->subSeconds(61),
            'created_at' => now()->subSeconds(61),
            'expires_at' => now()->addMinutes(9),
            'proof_hash' => 'old-proof',
        ]);

        $svc->send('01012349876', 'test-subnet-key');

        $row = DB::table('customer_phone_verifications')->first();
        $this->assertNull($row->verified_at);
        $this->assertNull($row->proof_hash);

        $this->assertGreaterThanOrEqual(3, (new SendCustomerPhoneVerification('01012349876'))->tries);
        $this->assertGreaterThanOrEqual(3, (new SendCustomerPasswordReset(null, '01012349876'))->tries);
    }
}
