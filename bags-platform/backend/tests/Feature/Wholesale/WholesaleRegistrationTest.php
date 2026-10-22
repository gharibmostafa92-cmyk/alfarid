<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\CustomerDocumentStatus;
use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** مهمة 5.3: تسجيل الجملة بالمستندات ومتابعة حالة الطلب. */
class WholesaleRegistrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeUploads();
    }

    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'تاجر جملة',
            'email' => 'ws@example.com',
            'phone' => '01022223333',
            'password' => 'password123',
            'company_name' => 'شركة الأفق للتجارة',
            'tax_number' => '123456789',
            'commercial_register' => '987654',
            'documents' => [
                UploadedFile::fake()->create('register.pdf', 200, 'application/pdf'),
                UploadedFile::fake()->image('tax-card.jpg'),
            ],
            'document_types' => ['commercial_register', 'tax_card'],
            ...$overrides,
        ];
    }

    public function test_register_creates_pending_wholesale_with_private_documents_and_token(): void
    {
        $response = $this->postJson('/api/v1/wholesale/auth/register', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.type', 'wholesale')
            ->assertJsonPath('data.status', 'pending');
        $this->assertNotEmpty($response->json('token'));

        $customer = Customer::where('phone', '01022223333')->firstOrFail();
        $this->assertSame(CustomerType::Wholesale, $customer->type);
        $this->assertSame(CustomerStatus::Pending, $customer->status);
        $this->assertSame('شركة الأفق للتجارة', $customer->company_name);

        $documents = $customer->documents()->orderBy('id')->get();
        $this->assertCount(2, $documents);
        $this->assertSame('commercial_register', $documents[0]->document_type->value);
        $this->assertSame('tax_card', $documents[1]->document_type->value);
        $this->assertSame(CustomerDocumentStatus::Pending, $documents[0]->status);
        foreach ($documents as $document) {
            $this->assertStringStartsWith("customer_documents/{$customer->id}/", $document->file_path);
            Storage::disk('r2')->assertExists($document->file_path);
        }
    }

    public function test_register_validates_company_fields_and_document_rules(): void
    {
        $this->postJson('/api/v1/wholesale/auth/register', $this->payload(['company_name' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors('company_name');

        // ملف أكبر من 5MB
        $this->postJson('/api/v1/wholesale/auth/register', $this->payload([
            'phone' => '01022224444',
            'documents' => [UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf')],
        ]))->assertUnprocessable()->assertJsonValidationErrors('documents.0');

        // صيغة غير مسموحة
        $this->postJson('/api/v1/wholesale/auth/register', $this->payload([
            'phone' => '01022224444',
            'documents' => [UploadedFile::fake()->create('notes.txt', 10, 'text/plain')],
        ]))->assertUnprocessable()->assertJsonValidationErrors('documents.0');

        // بلا مستندات أصلًا
        $this->postJson('/api/v1/wholesale/auth/register', $this->payload(['phone' => '01022224444', 'documents' => []]))
            ->assertUnprocessable()->assertJsonValidationErrors('documents');

        $this->assertSame(0, Customer::count());
    }

    public function test_application_status_for_pending_and_rejected_and_approved(): void
    {
        $token = $this->postJson('/api/v1/wholesale/auth/register', $this->payload())->assertCreated()->json('token');

        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/wholesale/auth/application-status')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonCount(2, 'data.documents');

        // الإدارة ترفض الطلب وترفض مستندًا بعينه
        $customer = Customer::where('phone', '01022223333')->firstOrFail();
        $customer->documents()->firstOrFail()->update(['status' => CustomerDocumentStatus::Rejected]);
        $customer->update(['status' => CustomerStatus::Rejected, 'rejection_reason' => 'السجل التجاري منتهي']);

        Auth::forgetGuards();
        $response = $this->withToken($token)->getJson('/api/v1/wholesale/auth/application-status')
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'السجل التجاري منتهي');
        $statuses = collect($response->json('data.documents'))->pluck('status')->all();
        $this->assertContains('rejected', $statuses);

        // ثم يُعتمد
        $customer->update(['status' => CustomerStatus::Approved, 'rejection_reason' => null, 'approved_at' => now()]);
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/wholesale/auth/application-status')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.rejection_reason', null);
    }

    public function test_application_status_requires_a_customer_token(): void
    {
        $this->getJson('/api/v1/wholesale/auth/application-status')->assertUnauthorized();

        $staff = User::factory()->create();
        $staffToken = $staff->createToken('staff-token')->plainTextToken;
        Auth::forgetGuards();
        $this->withToken($staffToken)->getJson('/api/v1/wholesale/auth/application-status')->assertForbidden();
    }
}
