<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerEmail;

use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Services\Auth\CustomerAuthService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationEmailTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.resend.capture_enabled' => true,
            'services.resend.from' => 'Alfarid <orders@alfarid.example>',
            'services.resend.environment' => 'testing',
        ]);
        Http::preventStrayRequests();
        Notification::fake();
    }

    public function test_retail_registration_creates_a_welcome_intent_without_sending_email_inline(): void
    {
        $response = $this->postJson('/api/v1/store/auth/register', [
            'name' => 'عميل جديد',
            'email' => 'retail@example.test',
            'phone' => '01012345901',
            'password' => 'correct-password-123',
        ]);

        $response->assertCreated();
        $customer = Customer::query()->where('phone', '01012345901')->firstOrFail();
        $row = DB::table('customer_email_outbox')->first();
        $payload = json_decode(Crypt::decryptString($row->payload), true, 512, JSON_THROW_ON_ERROR);

        $this->assertDatabaseCount('customer_email_outbox', 1);
        $this->assertSame('retail@example.test', $payload['to'][0]);
        $this->assertStringContainsString('تم إنشاء حسابك بنجاح.', $payload['text']);
        $this->assertNull($customer->email_verified_at);
        Http::assertNothingSent();
    }

    public function test_wholesale_registration_creates_a_pending_account_welcome_intent(): void
    {
        $response = $this->postJson('/api/v1/wholesale/auth/register', [
            'name' => 'تاجر جملة',
            'email' => 'wholesale@example.test',
            'phone' => '01012345902',
            'password' => 'correct-wholesale-password-123',
            'company_name' => 'شركة جديدة',
            'tax_number' => '123456789',
            'commercial_register' => '987654',
            'documents' => [UploadedFile::fake()->createWithContent('register.pdf', "%PDF-1.4\nvalid")],
            'document_types' => ['commercial_register'],
        ]);

        $response->assertCreated()->assertJsonPath('data.status', 'pending');
        $customer = Customer::query()->where('phone', '01012345902')->firstOrFail();
        $row = DB::table('customer_email_outbox')->first();
        $payload = json_decode(Crypt::decryptString($row->payload), true, 512, JSON_THROW_ON_ERROR);

        $this->assertDatabaseCount('customer_email_outbox', 1);
        $this->assertSame('wholesale@example.test', $payload['to'][0]);
        $this->assertStringContainsString('حساب الجملة وهو قيد المراجعة', $payload['text']);
        $this->assertNull($customer->email_verified_at);
        Http::assertNothingSent();
    }

    public function test_retail_registration_without_email_still_succeeds_without_an_intent(): void
    {
        $this->postJson('/api/v1/store/auth/register', [
            'name' => 'عميل بالهاتف',
            'phone' => '01012345903',
            'password' => 'correct-password-123',
        ])->assertCreated();

        $this->assertDatabaseCount('customer_email_outbox', 0);
        Http::assertNothingSent();
    }

    public function test_duplicate_registration_does_not_create_a_second_welcome_intent(): void
    {
        $registration = [
            'name' => 'عميل',
            'email' => 'duplicate@example.test',
            'phone' => '01012345905',
            'password' => 'correct-password-123',
        ];

        $this->postJson('/api/v1/store/auth/register', $registration)->assertCreated();
        $this->postJson('/api/v1/store/auth/register', $registration)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);

        $this->assertDatabaseCount('customer_email_outbox', 1);
        Http::assertNothingSent();
    }

    public function test_rolling_back_registration_rolls_back_its_welcome_intent(): void
    {
        try {
            DB::transaction(function (): void {
                app(CustomerAuthService::class)->registerRetail([
                    'name' => 'عميل لم يكتمل',
                    'email' => 'rolled-back@example.test',
                    'phone' => '01012345904',
                    'password' => 'correct-password-123',
                ]);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }

        $this->assertDatabaseMissing('customers', ['phone' => '01012345904']);
        $this->assertDatabaseCount('customer_email_outbox', 0);
    }

    public function test_rejected_phone_proof_creates_neither_account_nor_welcome_intent(): void
    {
        config(['auth.require_phone_verification' => true]);

        try {
            app(CustomerAuthService::class)->registerRetail([
                'name' => 'عميل غير موثق',
                'email' => 'unverified@example.test',
                'phone' => '01012345906',
                'password' => 'correct-password-123',
            ]);
            $this->fail('A missing phone proof must be rejected.');
        } catch (BusinessException) {
            $this->assertDatabaseMissing('customers', ['phone' => '01012345906']);
            $this->assertDatabaseCount('customer_email_outbox', 0);
        }
    }
}
