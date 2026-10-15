<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CustomerDocumentStatus;
use App\Enums\CustomerDocumentType;
use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Customer;
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
    /**
     * @param  list<UploadedFile>  $files
     * @param  list<string|null>  $documentTypes  أنواع موازية للملفات (افتراضي other)
     */
    public function register(array $data, array $files, array $documentTypes = []): Customer
    {
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($data, $files, $documentTypes, &$storedPaths): Customer {
                $customer = Customer::query()->create([
                    'name' => $data['name'],
                    'email' => $data['email'] ?? null,
                    'phone' => $data['phone'],
                    'password' => $data['password'],
                    'type' => CustomerType::Wholesale,
                    'status' => CustomerStatus::Pending,
                    'company_name' => $data['company_name'],
                    'tax_number' => $data['tax_number'],
                    'commercial_register' => $data['commercial_register'],
                ]);

                foreach ($files as $i => $file) {
                    // تخزين خاص: وثائق الشركات لا تخرج بروابط عامة أبدًا،
                    // والتنزيل لاحقًا عبر نهاية الأدمن المحمية فقط.
                    $filename = Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: 'bin');
                    $path = Storage::disk('local')->putFileAs("customer_documents/{$customer->id}", $file, $filename);
                    $storedPaths[] = $path;

                    $customer->documents()->create([
                        'document_type' => $documentTypes[$i] ?? CustomerDocumentType::Other->value,
                        'file_path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                        'status' => CustomerDocumentStatus::Pending,
                    ]);
                }

                return $customer->load('documents');
            });
        } catch (\Throwable $e) {
            // فشل قاعدة البيانات بعد رفع ملفات: نظّفها حتى لا تبقى يتيمة
            foreach ($storedPaths as $path) {
                Storage::disk('local')->delete($path);
            }

            throw $e;
        }
    }
}
