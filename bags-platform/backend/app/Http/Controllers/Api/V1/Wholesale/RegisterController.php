<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wholesale;

use App\Enums\Customer\CustomerDocumentStatus;
use App\Enums\Customer\CustomerDocumentType;
use App\Enums\Customer\CustomerStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wholesale\WholesaleRegisterRequest;
use App\Http\Resources\Store\CustomerResource;
use App\Models\Customer;
use App\Services\Customer\CustomerManagementService;
use App\Services\Wholesale\WholesaleRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Handles wholesale merchant registration and onboarding application status tracking.
 */
class RegisterController extends Controller
{
    public function __construct(
        private readonly WholesaleRegistrationService $registrations,
    ) {}

    /**
     * Submit wholesale registration application with accompanying trade documents.
     */
    public function register(WholesaleRegisterRequest $request): JsonResponse
    {
        $data = $request->validated();
        $registration = $this->registrations->register(
            $data,
            $request->file('documents', []),
            $data['document_types'] ?? [],
        );

        return CustomerResource::make($registration['customer'])
            ->additional(['message' => 'تم استلام طلب التسجيل؛ حسابك قيد المراجعة', 'token' => $registration['token']])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Retrieve the current merchant onboarding application status and rejected document notes.
     * Accessible by authenticated customer accounts prior to full wholesale approval.
     */
    public function applicationStatus(Request $request): JsonResponse
    {
        $customer = $request->user();
        if (! $customer instanceof Customer) {
            throw new BusinessException('هذه النهاية مخصصة لحسابات العملاء.', [], 403);
        }

        $customer->load('documents');

        return response()->json([
            'data' => [
                ...CustomerResource::make($customer)->resolve($request),
                'rejection_reason' => $customer->status === CustomerStatus::Rejected ? $customer->rejection_reason : null,
                'suspension_reason' => $customer->status === CustomerStatus::Suspended ? $customer->suspension_reason : null,
                'approved_at' => $customer->approved_at,
                'documents' => $customer->documents->map(fn ($document) => [
                    'id' => $document->id,
                    'document_type' => $document->document_type,
                    'status' => $document->status,
                    'original_name' => $document->original_name,
                ])->all(),
                'rejected_documents' => $customer->documents
                    ->where('status', CustomerDocumentStatus::Rejected)
                    ->map(fn ($document) => [
                        'id' => $document->id,
                        'document_type' => $document->document_type,
                        'original_name' => $document->original_name,
                        'review_note' => $document->review_note,
                    ])->values()->all(),
            ],
        ]);
    }

    /**
     * Upload additional onboarding or replacement documents requested during verification.
     */
    public function uploadDocument(Request $request, CustomerManagementService $customers): JsonResponse
    {
        $customer = $request->user();
        if (! $customer instanceof Customer) {
            throw new BusinessException('هذا المسار متاح للعملاء فقط.', [], 403);
        }
        $data = $request->validate([
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:5120'],
            'document_type' => ['nullable', Rule::enum(CustomerDocumentType::class)],
        ]);
        $document = $customers->uploadDocument(
            $customer,
            $request->file('document'),
            null,
            isset($data['document_type']) ? CustomerDocumentType::from($data['document_type']) : null,
            selfService: true,
        );

        return response()->json(['data' => ['id' => $document->id, 'status' => $document->status]], 201);
    }
}
