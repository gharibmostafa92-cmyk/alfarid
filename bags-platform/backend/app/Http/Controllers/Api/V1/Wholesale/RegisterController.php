<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wholesale;

use App\Enums\CustomerDocumentStatus;
use App\Enums\CustomerStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wholesale\WholesaleRegisterRequest;
use App\Http\Resources\Store\CustomerResource;
use App\Models\Customer;
use App\Services\WholesaleRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** تسجيل تجار الجملة ومتابعة حالة طلب الاعتماد. */
class RegisterController extends Controller
{
    public function __construct(
        private readonly WholesaleRegistrationService $registrations,
    ) {}

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
     * حالة الطلب لصاحبه — بما فيه المرفوض (يحتاج سبب الرفض ومستنداته).
     * محمي بمصادقة العملاء فقط؛ لا يحتاج اعتماد الجملة.
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
                    ])->values()->all(),
            ],
        ]);
    }
}
