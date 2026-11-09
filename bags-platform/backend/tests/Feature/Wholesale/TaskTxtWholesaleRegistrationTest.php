<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\CustomerDocumentStatus;
use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Events\WholesaleApplicationSubmitted;
use App\Models\Customer;
use App\Services\Wholesale\WholesaleRegistrationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * task.txt item 28: التسجيل ورفع المستندات
 */
class TaskTxtWholesaleRegistrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeUploads();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'تاجر جملة جديد',
            'email' => 'trader-'.uniqid().'@example.com',
            'phone' => '01099887766',
            'password' => 'valid-password-123',
            'company_name' => 'مؤسسة الفريد للتجارة',
            'tax_number' => '555666777',
            'commercial_register' => '112233',
            'documents' => [
                UploadedFile::fake()->create('commercial_register.pdf', 150, 'application/pdf'),
                UploadedFile::fake()->image('tax_card.jpg'),
            ],
            'document_types' => ['commercial_register', 'tax_card'],
            ...$overrides,
        ];
    }

    public function test_successful_registration_creates_pending_customer_documents_token_and_dispatches_event(): void
    {
        Event::fake([WholesaleApplicationSubmitted::class]);

        $response = $this->postJson('/api/v1/wholesale/auth/register', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.type', 'wholesale')
            ->assertJsonPath('data.status', 'pending');

        $this->assertNotEmpty($response->json('token'));

        $customer = Customer::query()->where('phone', '01099887766')->firstOrFail();
        $this->assertSame(CustomerType::Wholesale, $customer->type);
        $this->assertSame(CustomerStatus::Pending, $customer->status);
        $this->assertCount(2, $customer->documents);

        foreach ($customer->documents as $doc) {
            $this->assertSame(CustomerDocumentStatus::Pending, $doc->status);
            Storage::disk('r2')->assertExists($doc->file_path);
        }

        Event::assertDispatched(WholesaleApplicationSubmitted::class, fn ($e) => $e->customer->id === $customer->id);
    }

    public function test_validation_rejects_bad_phone_passwords_document_limits_spoofed_exe_and_mismatched_types(): void
    {
        Customer::factory()->create(['phone' => '01011112222']);

        // 1. Invalid phone format & already registered phone
        $this->postJson('/api/v1/wholesale/auth/register', $this->payload(['phone' => '01912345678']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');

        $this->postJson('/api/v1/wholesale/auth/register', $this->payload(['phone' => '01011112222']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');

        // 2. Password 11 chars (< 12) and 73 bytes (> 72 bcrypt limit)
        $this->postJson('/api/v1/wholesale/auth/register', $this->payload(['password' => '12345678901']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->postJson('/api/v1/wholesale/auth/register', $this->payload(['password' => str_repeat('a', 73)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        // 3. 6 documents (max is 5)
        $sixDocs = array_map(
            fn ($i) => UploadedFile::fake()->create("doc{$i}.pdf", 50, 'application/pdf'),
            range(1, 6),
        );
        $this->postJson('/api/v1/wholesale/auth/register', $this->payload([
            'documents' => $sixDocs,
            'document_types' => array_fill(0, 6, 'commercial_register'),
        ]))->assertStatus(422)->assertJsonValidationErrors('documents');

        // 4. Executable disguised as PDF (MZ header with .pdf extension)
        $disguisedExe = UploadedFile::fake()->createWithContent('malware.pdf', "MZ\x90\x00\x03\x00\x00\x00");
        $this->postJson('/api/v1/wholesale/auth/register', $this->payload([
            'documents' => [$disguisedExe],
            'document_types' => ['commercial_register'],
        ]))->assertStatus(422)->assertJsonValidationErrors('documents.0');

        // 5. document_types count does not match documents count
        $this->postJson('/api/v1/wholesale/auth/register', $this->payload([
            'documents' => [
                UploadedFile::fake()->create('a.pdf', 50, 'application/pdf'),
                UploadedFile::fake()->create('b.pdf', 50, 'application/pdf'),
            ],
            'document_types' => ['commercial_register'],
        ]))->assertStatus(422)->assertJsonValidationErrors('document_types');

        // Only the pre-existing customer exists; zero orphaned files in R2
        $this->assertSame(1, Customer::query()->count());
        $this->assertSame([], Storage::disk('r2')->allFiles());
    }

    public function test_r2_move_failure_rolls_back_customer_and_cleans_up_staged_files(): void
    {
        $fakeDisk = Storage::disk('r2');
        $proxy = new class($fakeDisk)
        {
            public function __construct(private readonly object $inner) {}

            public function putFileAs(string $path, mixed $file, string $name): string|false
            {
                return $this->inner->putFileAs($path, $file, $name);
            }

            public function move(string $from, string $to): bool
            {
                return false;
            }

            public function delete(string|array $paths): bool
            {
                return $this->inner->delete($paths);
            }

            public function deleteDirectory(string $directory): bool
            {
                return $this->inner->deleteDirectory($directory);
            }

            public function __call(string $name, array $arguments): mixed
            {
                return $this->inner->{$name}(...$arguments);
            }
        };

        Storage::set('r2', $proxy);

        try {
            app(WholesaleRegistrationService::class)->register(
                [
                    'name' => 'تاجر فشل التخزين',
                    'email' => 'r2fail@example.com',
                    'phone' => '01077665544',
                    'password' => 'valid-password-123',
                    'company_name' => 'شركة',
                    'tax_number' => '111',
                    'commercial_register' => '222',
                ],
                [UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')],
                ['commercial_register'],
            );
            $this->fail('Expected RuntimeException when R2 move fails.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('تعذر حفظ مستند العميل', $e->getMessage());
        }

        $this->assertDatabaseMissing('customers', ['phone' => '01077665544']);
        $this->assertSame([], $fakeDisk->allFiles());
    }

    public function test_concurrent_duplicate_phone_at_service_layer_creates_one_customer_and_cleans_r2(): void
    {
        $service = app(WholesaleRegistrationService::class);
        $data = [
            'name' => 'تاجر أول',
            'email' => 'first@example.com',
            'phone' => '01066554433',
            'password' => 'valid-password-123',
            'company_name' => 'شركة 1',
            'tax_number' => '111',
            'commercial_register' => '222',
        ];

        $first = $service->register(
            $data,
            [UploadedFile::fake()->create('first.pdf', 100, 'application/pdf')],
            ['commercial_register'],
        );
        $this->assertSame(1, Customer::query()->where('phone', '01066554433')->count());

        // Second concurrent call bypassing form-request pre-check hits unique constraint inside DB transaction
        try {
            $service->register(
                [...$data, 'email' => 'second@example.com'],
                [UploadedFile::fake()->create('second.pdf', 100, 'application/pdf')],
                ['commercial_register'],
            );
            $this->fail('Duplicate phone registration must throw ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('phone', $e->errors());
        }

        $this->assertSame(1, Customer::query()->where('phone', '01066554433')->count());
        // Only the first customer's single document remains on R2
        $files = Storage::disk('r2')->allFiles();
        $this->assertCount(1, $files);
        $this->assertStringStartsWith('customer_documents/'.$first['customer']->id.'/', $files[0]);
    }
}
