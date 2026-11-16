<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Orders;

use App\Enums\Wholesale\QuotationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Orders\PriceQuotationRequest;
use App\Http\Resources\Wholesale\QuotationResource;
use App\Models\Quotation;
use App\Services\Inventory\BranchAccess;
use App\Services\Wholesale\QuotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * Backoffice wholesale price quotations administration: reviewing customer RFQs, pricing, and expiration.
 */
class QuotationController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly QuotationService $service,
        private readonly BranchAccess $branchAccess,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:quotations.view', only: ['index', 'show']),
            new Middleware('can:quotations.price', only: ['price', 'expire']),
        ];
    }

    /**
     * List paginated wholesale quotations with customer and status filters.
     */
    public function index(Request $request): JsonResponse
    {
        $this->branchAccess->assertCentralAccess($request->user());
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(array_column(QuotationStatus::cases(), 'value'))],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $quotations = Quotation::query()
            ->with(['customer', 'items.variant.product', 'convertedOrder:id,order_number'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))
            ->latest('id')
            ->paginate($filters['per_page'] ?? 15);

        return QuotationResource::collection($quotations)->response();
    }

    /**
     * Retrieve quotation details with items and variants.
     */
    public function show(Request $request, int $quotation): JsonResponse
    {
        $this->branchAccess->assertCentralAccess($request->user());
        $model = Quotation::query()->with(['customer', 'items.variant.product', 'convertedOrder:id,order_number'])->findOrFail($quotation);

        return QuotationResource::make($model)->response();
    }

    /**
     * Price a quotation with line-item discounts, custom prices, and expiration deadline.
     */
    public function price(PriceQuotationRequest $request, int $quotation): JsonResponse
    {
        $this->branchAccess->assertCentralAccess($request->user());
        $model = Quotation::query()->findOrFail($quotation);
        $priced = $this->service->price($model, [
            ...$request->validated(),
            'priced_by' => $request->user()?->id,
        ]);

        return QuotationResource::make($priced)
            ->additional(['acceptance_warnings' => $this->service->acceptanceWarnings($priced)])
            ->response();
    }

    /**
     * Expire a quotation that passed its validity window.
     */
    public function expire(Request $request, int $quotation): JsonResponse
    {
        $this->branchAccess->assertCentralAccess($request->user());
        $model = Quotation::query()->findOrFail($quotation);

        return QuotationResource::make($this->service->expire($model))->response();
    }
}
