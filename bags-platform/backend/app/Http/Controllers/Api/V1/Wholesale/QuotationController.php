<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wholesale;

use App\Enums\QuotationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wholesale\StoreQuotationRequest;
use App\Http\Requests\Wholesale\UpdateQuotationRequest;
use App\Http\Resources\Wholesale\QuotationResource;
use App\Models\Customer;
use App\Models\Quotation;
use App\Services\QuotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class QuotationController extends Controller
{
    public function __construct(private readonly QuotationService $service) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(array_column(QuotationStatus::cases(), 'value'))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $quotations = Quotation::query()
            ->where('customer_id', $request->user()->id)
            ->with('items.variant.product')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->latest('id')
            ->paginate($filters['per_page'] ?? 15);

        return QuotationResource::collection($quotations)->response();
    }

    public function store(StoreQuotationRequest $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $quotation = $this->service->create($customer, $request->validated());

        return (new QuotationResource($quotation))->response()->setStatusCode(201);
    }

    public function show(Request $request, int $quotation): JsonResponse
    {
        $model = $this->ownQuotation($request, $quotation);

        return (new QuotationResource($model->load('items.variant.product')))->response();
    }

    public function update(UpdateQuotationRequest $request, int $quotation): JsonResponse
    {
        $model = $this->ownQuotation($request, $quotation);

        return (new QuotationResource($this->service->update($model, $request->validated())))->response();
    }

    public function destroy(Request $request, int $quotation): JsonResponse
    {
        $this->service->deleteDraft($this->ownQuotation($request, $quotation));

        return response()->json(['message' => 'تم حذف مسودة عرض السعر.']);
    }

    public function submit(Request $request, int $quotation): JsonResponse
    {
        return (new QuotationResource($this->service->submit($this->ownQuotation($request, $quotation))))->response();
    }

    public function accept(Request $request, int $quotation): JsonResponse
    {
        return (new QuotationResource($this->service->accept($this->ownQuotation($request, $quotation))))->response();
    }

    public function reject(Request $request, int $quotation): JsonResponse
    {
        return (new QuotationResource($this->service->reject($this->ownQuotation($request, $quotation))))->response();
    }

    private function ownQuotation(Request $request, int $id): Quotation
    {
        return Quotation::query()
            ->where('customer_id', $request->user()->id)
            ->findOrFail($id);
    }
}
