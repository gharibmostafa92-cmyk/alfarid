<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CustomerDocumentStatus;
use App\Enums\CustomerDocumentType;
use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Customer;
use App\Models\CustomerAuditLog;
use App\Models\CustomerDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * تسجيل تجار الجملة: حساب pending + مستندات تحقق في تخزين خاص.
 * الاعتماد نفسه قرار إدارة (مهمة 5.4) — هنا يُنشأ الطلب فقط.
 */
class WholesaleRegistrationService
{
    public function __construct(private readonly CustomerAuthService $auth) {}

    /**
     * @param  list<UploadedFile>  $files
     * @param  list<string|null>  $documentTypes  أنواع موازية للملفات (افتراضي other)
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
                $path = Storage::disk('local')->putFileAs($stagingDirectory, $file, $filename);
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
                    // تخزين خاص: وثائق الشركات لا تخرج بروابط عامة أبدًا،
                    // والتنزيل لاحقًا عبر نهاية الأدمن المحمية فقط.
                    $path = "customer_documents/{$customer->id}/".$document['filename'];
                    if (! Storage::disk('local')->move($document['path'], $path)) {
                        throw new \RuntimeException('تعذر حفظ مستند العميل.');
                    }
                    $storedPaths[] = $path;

                    $customer->documents()->create([
                        'document_type' => $document['document_type'],
                        'file_path' => $path,
                        'original_name' => $document['original_name'],
                        'mime_type' => $document['mime_type'],
                        'status' => CustomerDocumentStatus::Pending,
                    ]);
                }

                CustomerAuditLog::record($customer, 'self_registered', null, ['channel' => 'wholesale']);

                return [
                    'customer' => $customer->load('documents'),
                    'token' => $this->auth->issueToken($customer, 'wholesale-token'),
                ];
            });
        } catch (\Throwable $e) {
            // فشل قاعدة البيانات بعد رفع ملفات: نظّفها حتى لا تبقى يتيمة
            foreach ($storedPaths as $path) {
                Storage::disk('local')->delete($path);
            }
            if ($storedPaths !== []) {
                Storage::disk('local')->deleteDirectory($stagingDirectory);
            }

            throw $e;
        }

        Storage::disk('local')->deleteDirectory($stagingDirectory);

        return $registration;
    }
}
