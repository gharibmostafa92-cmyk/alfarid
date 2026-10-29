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
     * @param  list<string|null>  $documentTypes Parallel document type tags (defaults to "other")
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

            $registration = DB::transaction(function () use ($data, $staged, &$storedPaths): array {
                $verified = app(CustomerPhoneVerificationService::class)->consume($data);
                $customer = Customer::query()->create([
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

                foreach ($staged as $document) {
                    // Private storage: sensitive corporate documents are never publicly exposed;
                    // downloads are strictly routed through authenticated admin endpoints.
                    $path = "customer_documents/{$customer->id}/".$document['filename'];
                    $storedPaths[] = $path;
                    if (! Storage::disk('r2')->move($document['path'], $path)) {
                        throw new \RuntimeException('تعذر حفظ مستند العميل.');
                    }

                    $customer->documents()->create([
                        'document_type' => $document['document_type'],
                        'file_path' => $path,
                        'original_name' => $document['original_name'],
                        'mime_type' => $document['mime_type'],
                        'status' => CustomerDocumentStatus::Pending,
                    ]);
                }

                CustomerAuditLog::record($customer, 'self_registered', null, ['channel' => 'wholesale']);
                WholesaleApplicationSubmitted::dispatch($customer);

                return [
                    'customer' => $customer->load('documents'),
                    'token' => $this->auth->issueToken($customer, 'wholesale-token'),
                ];
            });
        } catch (\Throwable $e) {
            // Transaction failure: clean up uploaded files on cloud storage to prevent orphaned assets
            foreach ($storedPaths as $path) {
                Storage::disk('r2')->delete($path);
            }
            if ($storedPaths !== []) {
                Storage::disk('r2')->deleteDirectory($stagingDirectory);
            }

            throw $e;
        }

        Storage::disk('r2')->deleteDirectory($stagingDirectory);

        return $registration;
    }
}
