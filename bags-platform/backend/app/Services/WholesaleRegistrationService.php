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
 * Wholesale customer self-registration service.
 * Registers customer in Pending status and stages uploaded verification documents into private storage.
 * Final approval is performed by administrators via CustomerManagementService.
 */
class WholesaleRegistrationService
{
    public function __construct(private readonly CustomerAuthService $auth) {}

    /**
     * Register a new wholesale customer and store supporting verification documents.
     * Staged files are moved into customer directory upon successful transaction.
     *
     * @param  list<UploadedFile>  $files
     * @param  list<string|null>  $documentTypes  Parallel document type tags (defaults to "other")
     * @return array{customer: Customer, token: string}
     */
    public function register(array $data, array $files, array $documentTypes = []): array
    {
        $files = array_values($files);
        $documentTypes = array_values($documentTypes);
        $storedPaths = [];
        $staged = [];
        $stagingDirectory = 'customer_documents/pending/'.Str::uuid();

        try {
            foreach ($files as $i => $file) {
                $filename = Str::uuid().'.'.($file->extension() ?: 'bin');
                $path = Storage::disk('r2')->putFileAs($stagingDirectory, $file, $filename);
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

            // Reserve the sequence value before storage I/O, without holding row locks.
            $customerId = (int) DB::selectOne("SELECT nextval(pg_get_serial_sequence('customers', 'id')) AS id")->id;
            foreach ($staged as &$document) {
                $path = "customer_documents/{$customerId}/".$document['filename'];
                $storedPaths[] = $path;
                if (! Storage::disk('r2')->move($document['path'], $path)) {
                    throw new \RuntimeException('تعذر حفظ مستند العميل.');
                }
                $document['path'] = $path;
            }
            unset($document);

            $registration = DB::transaction(function () use ($data, $staged, $customerId): array {
                $this->auth->lockRegistrationEmail($data['email'] ?? null);
                $verified = app(CustomerPhoneVerificationService::class)->consume($data);
                $customer = Customer::query()->make([
                    'name' => $data['name'],
                    'email' => $data['email'] ?? null,
                    'phone' => $data['phone'],
                    'password' => $data['password'],
                    'type' => CustomerType::Wholesale,
                    'status' => CustomerStatus::Pending,
                    'phone_verified_at' => $verified ? now() : null,
                    'company_name' => $data['company_name'],
                    'tax_number' => $data['tax_number'],
                    'commercial_register' => $data['commercial_register'],
                ]);
                $customer->forceFill(['id' => $customerId])->save();

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
                        // The account is committed; a queue outage must not turn it into a failed registration.
                        report($exception);
                    }
                });

                return [
                    'customer' => $customer->load('documents'),
                    'token' => $this->auth->issueToken($customer, 'wholesale-token'),
                ];
            });
        } catch (\Throwable $e) {
            // Transaction failure: clean up uploaded files on cloud storage to prevent orphaned assets
            foreach ($storedPaths as $path) {
                try {
                    Storage::disk('r2')->delete($path);
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }
            if ($storedPaths !== []) {
                try {
                    Storage::disk('r2')->deleteDirectory($stagingDirectory);
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }

            throw $e;
        }

        try {
            Storage::disk('r2')->deleteDirectory($stagingDirectory);
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $registration;
    }
}
