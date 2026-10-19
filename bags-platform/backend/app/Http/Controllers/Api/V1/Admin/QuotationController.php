<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\QuotationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PriceQuotationRequest;
use App\Http\Resources\Wholesale\QuotationResource;
use App\Models\Quotation;
use App\Services\QuotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

class QuotationController extends Controller implements HasMiddleware
{
    public function __construct(private readonly QuotationService $service) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:quotations.view', only: ['index', 'show']),
            new Middleware('can:quotations.price', only: ['price', 'expire']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(array_column(QuotationStatus::cases(), 'value'))],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $quotations = Quotation::query()
            ->with(['customer', 'items.variant.product'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))
            ->latest('id')
            ->paginate($filters['per_page'] ?? 15);

        return QuotationResource::collection($quotations)->response();
    }

    public function show(int $quotation): JsonResponse
    {
        $model = Quotation::query()->with(['customer', 'items.variant.product'])->findOrFail($quotation);

        return (new QuotationResource($model))->response();
    }

    public function price(PriceQuotationRequest $request, int $quotation): JsonResponse
    {
        $model = Quotation::query()->findOrFail($quotation);

        return (new QuotationResource($this->service->price($model, $request->validated())))->response();
    }

    public function expire(int $quotation): JsonResponse
    {
        $model = Quotation::query()->findOrFail($quotation);

        return (new QuotationResource($this->service->expire($model)))->response();
    }
}
