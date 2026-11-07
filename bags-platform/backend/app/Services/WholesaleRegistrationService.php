<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CustomerDocumentStatus;
use App\Enums\CustomerDocumentType;
use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Events\WholesaleApplicationSubmitted;
use App\Models\Customer;
use App\Models\CustomerAuditLog;
use App\Models\CustomerDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Handles wholesale merchant self-registration and KYC document staging.
 *
 * Persists customer accounts in pending verification status and isolates document uploads
 * in private cloud storage prior to database transaction commitment.
 */
class WholesaleRegistrationService
{
    public function __construct(
        private readonly CustomerAuthService $auth,
        private readonly CustomerPhoneVerificationService $phoneVerification,
    ) {}

    /**
     * Registers a wholesale applicant and links uploaded verification files.
     *
     * @param  array<string, mixed>  $data  Registration attributes including credentials and trade metadata.
     * @param  list<UploadedFile>  $files  Uploaded identity and commercial verification files.
     * @param  list<string|null>  $documentTypes  Corresponding document categorization tags.
     * @return array{customer: Customer, token: string} Created customer instance and initial session token.
     */
    public function register(array $data, array $files, array $documentTypes = []): array
    {
        $files = array_values($files);
        $documentTypes = array_values($documentTypes);
        $storedPaths = [];
        $staged = [];
        $stagingDirectory = 'customer_documents/pending/'.Str::uuid();
        $disk = Storage::disk('r2');

        // Assert proof existence upfront before expensive cloud file I/O; actual token consumption happens in DB transaction.
        $this->phoneVerification->assertProofPresent($data);

        try {
            foreach ($files as $i => $file) {
                $filename = Str::uuid().'.'.($file->extension() ?: 'bin');
                $path = $disk->putFileAs($stagingDirectory, $file, $filename);
                if ($path === false) {
                    throw new \RuntimeException('تعذر حفظ مستند العميل.');
                }
                $storedPaths[] = $path;
                $staged[] = [
                    'filename' => $filename, 'path' => $path,
                    'document_type' => $documentTypes[$i] ?? CustomerDocumentType::Other->value,
                    'original_name' => CustomerDocument::safeName($file->getClientOriginalName()),
                    'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                ];
            }

            // Pre-allocate Postgres sequence ID to establish customer document directory path without holding row locks during S3/R2 copy operations.
            $customerId = (int) DB::selectOne("SELECT nextval(pg_get_serial_sequence('customers', 'id')) AS id")->id;
            foreach ($staged as &$document) {
                $path = "customer_documents/{$customerId}/".$document['filename'];
                $storedPaths[] = $path;
                if (! $disk->move($document['path'], $path)) {
                    throw new \RuntimeException('تعذر حفظ مستند العميل.');
                }
                $document['path'] = $path;
            }
            unset($document);

            $registration = DB::transaction(function () use ($data, $staged, $customerId): array {
                $this->auth->lockRegistrationEmail($data['email'] ?? null);
                $verified = $this->phoneVerification->consume($data);
                $customer = Customer::query()->make([
                    'name' => $data['name'],
                    'email' => $data['email'] ?? null,
                    'phone' => $data['phone'],
                    'password' => $data['password'],
                    'type' => CustomerType::Wholesale,
                    'phone_verified_at' => $verified ? now() : null,
                    'company_name' => $data['company_name'],
                    'tax_number' => $data['tax_number'],
                    'commercial_register' => $data['commercial_register'],
                ]);
                $customer->forceFill(['id' => $customerId, 'status' => CustomerStatus::Pending])->save();

                foreach ($staged as $document) {
                    $customer->documents()->create([
                        'document_type' => $document['document_type'],
                        'file_path' => $document['path'],
                        'original_name' => $document['original_name'],
                        'mime_type' => $document['mime_type'],
                        'status' => CustomerDocumentStatus::Pending,
                    ]);
                }

                CustomerAuditLog::record($customer, 'self_registered', null, ['channel' => 'wholesale']);
                DB::afterCommit(static function () use ($customer): void {
                    try {
                        WholesaleApplicationSubmitted::dispatch($customer);
                    } catch (\Throwable $exception) {
                        // Commit the account first; prevent queue driver outages or notification failures from aborting a valid registration.
                        report($exception);
                    }
                });

                return [
                    'customer' => $customer->load('documents'),
                    'token' => $this->auth->issueToken($customer, 'wholesale-token'),
                ];
            });
        } catch (\Throwable $e) {
            // Purge staged and committed cloud assets on rollback to prevent orphaned KYC files in storage bucket.
            $this->purgePathsAndDirectory($stagingDirectory, $storedPaths);

            // Map PostgreSQL unique constraint violations (e.g. duplicate tax/phone/email) to friendly HTTP 422 validation errors.
            throw Customer::translateUniqueViolation($e);
        }

        $this->deleteDirectorySilently($stagingDirectory);

        return $registration;
    }

    /**
     * @param  list<string>  $storedPaths
     */
    private function purgePathsAndDirectory(string $stagingDirectory, array $storedPaths): void
    {
        $disk = Storage::disk('r2');
        foreach ($storedPaths as $path) {
            try {
                $disk->delete($path);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
        if ($storedPaths !== []) {
            $this->deleteDirectorySilently($stagingDirectory);
        }
    }

    private function deleteDirectorySilently(string $directory): void
    {
        try {
            Storage::disk('r2')->deleteDirectory($directory);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
