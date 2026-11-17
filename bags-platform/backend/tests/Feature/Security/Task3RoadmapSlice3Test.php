<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Exceptions\BusinessException;
use App\Http\Requests\Admin\Customer\UpdateCustomerRequest;
use App\Models\Customer;
use App\Models\User;
use App\Rules\MaxImagePixels;
use App\Rules\RejectExecutableSignature;
use App\Services\Customer\CustomerManagementService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class Task3RoadmapSlice3Test extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_medium_01_customer_financial_update_requires_concurrency_token_and_rejects_same_second_stale_version(): void
    {
        $customer = Customer::factory()->create([
            'type' => CustomerType::Wholesale,
            'status' => CustomerStatus::Approved,
            'credit_limit' => 10000,
        ]);

        $request = UpdateCustomerRequest::create('/api/v1/admin/customers/'.$customer->id, 'PATCH', ['credit_limit' => 20000]);
        $validator = Validator::make(['credit_limit' => 20000], $request->rules());
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('expected_updated_at', $validator->errors()->toArray());

        $service = app(CustomerManagementService::class);
        $initialVersion = (int) $customer->lock_version;

        $service->update($customer, [
            'credit_limit' => 15000,
            'expected_version' => $initialVersion,
        ]);

        $this->expectException(BusinessException::class);
        $service->update($customer->fresh(), [
            'credit_limit' => 25000,
            'expected_version' => $initialVersion,
        ]);
    }

    public function test_medium_02_user_phone_normalizes_arabic_digits_and_cf_marks(): void
    {
        $user = User::factory()->create([
            'phone' => "\u{200E}٠١٠١٢٣٤٥٦٧٨\u{200F}",
        ]);

        $this->assertSame('01012345678', $user->phone);
    }

    public function test_medium_03_reject_executable_signature_catches_bom_php_and_pdf_xfa(): void
    {
        $bomPhp = UploadedFile::fake()->createWithContent('evil.txt', "\xEF\xBB\xBF<?php echo 1;");
        $failedBom = false;
        (new RejectExecutableSignature)->validate('file', $bomPhp, function () use (&$failedBom): void {
            $failedBom = true;
        });
        $this->assertTrue($failedBom);

        $pdfXfa = UploadedFile::fake()->createWithContent('form.pdf', "%PDF-1.7\n1 0 obj\n<< /XFA 2 0 R >>\nendobj");
        $failedXfa = false;
        (new RejectExecutableSignature)->validate('file', $pdfXfa, function () use (&$failedXfa): void {
            $failedXfa = true;
        });
        $this->assertTrue($failedXfa);
    }

    public function test_medium_03_max_image_pixels_rejects_extreme_single_dimension(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'wide_png_');
        // Minimal valid PNG IHDR with width=15000, height=10 (total 150,000 pixels < 16M)
        $ihdrData = pack('N2C5', 15000, 10, 8, 2, 0, 0, 0);
        $ihdrCrc = pack('N', crc32('IHDR'.$ihdrData));
        $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdrData.$ihdrCrc.pack('N', 0).'IEND'.pack('N', crc32('IEND'));
        file_put_contents($tmp, $png);

        try {
            $file = new UploadedFile($tmp, 'wide.png', 'image/png', null, true);
            $failed = false;
            (new MaxImagePixels)->validate('file', $file, function () use (&$failed): void {
                $failed = true;
            });
            $this->assertTrue($failed);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_medium_05_prune_customer_artifacts_deletes_expired_tokens(): void
    {
        $customer = Customer::factory()->create();
        $expired = $customer->createToken('old', ['store-token']);
        DB::table('personal_access_tokens')
            ->where('id', $expired->accessToken->id)
            ->update(['expires_at' => now()->subDays(2)]);

        $active = $customer->createToken('fresh', ['store-token']);
        DB::table('personal_access_tokens')
            ->where('id', $active->accessToken->id)
            ->update(['expires_at' => now()->addDays(5)]);

        $this->artisan('customers:prune-artifacts')->assertExitCode(0);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $expired->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $active->accessToken->id]);
    }
}
