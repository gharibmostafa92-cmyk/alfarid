<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PriceListType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Pricing\ImportPriceListItemsRequest;
use App\Http\Requests\Admin\Pricing\StorePriceListRequest;
use App\Http\Requests\Admin\Pricing\UpdatePriceListRequest;
use App\Http\Resources\Admin\PriceListResource;
use App\Models\PriceList;
use App\Services\PriceListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Backoffice administration of customer-, branch-, and group-specific price lists, CSV import/export, and percentage adjustments.
 */
class PriceListController extends Controller implements HasMiddleware
{
    public function __construct(private readonly PriceListService $priceLists) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:price_lists.view', only: ['index', 'show']),
            new Middleware('can:price_lists.create', only: ['store']),
            new Middleware('can:price_lists.update', only: ['update', 'applyPercentage']),
            new Middleware('can:price_lists.delete', only: ['destroy']),
            new Middleware('can:price_lists.import', only: ['import']),
            new Middleware('can:price_lists.export', only: ['export']),
        ];
    }

    /**
     * List paginated price lists with filters for type, customer, branch, and status.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::enum(PriceListType::class)],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'customer_group_id' => ['nullable', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return PriceListResource::collection($this->priceLists->paginate($filters, $request->user()));
    }

    /**
     * Create a new custom or branch price list.
     */
    public function store(StorePriceListRequest $request): JsonResponse
    {
        $priceList = $this->priceLists->create($request->validated(), $request->user());

        return PriceListResource::make($priceList)
            ->additional(['message' => 'تم إنشاء قائمة الأسعار بنجاح'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Retrieve a price list along with item pricing.
     */
    public function show(Request $request, PriceList $priceList): PriceListResource
    {
        return PriceListResource::make($this->priceLists->find($priceList->id, $request->user()));
    }

    /**
     * Update price list headers and rules.
     */
    public function update(UpdatePriceListRequest $request, PriceList $priceList): PriceListResource
    {
        return PriceListResource::make($this->priceLists->update($priceList, $request->validated(), $request->user()));
    }

    /**
     * Delete a price list and clear its item entries.
     */
    public function destroy(Request $request, PriceList $priceList): JsonResponse
    {
        $this->priceLists->delete($priceList, $request->user());

        return response()->json(['message' => 'تم حذف قائمة الأسعار بنجاح']);
    }

    /**
     * Import price list items from CSV, supporting dry-run previews.
     */
    public function import(ImportPriceListItemsRequest $request, PriceList $priceList): JsonResponse
    {
        $dryRun = $request->boolean('dry_run');
        $report = $this->priceLists->importCsv($priceList, $request->file('file'), $request->user(), $dryRun);

        return response()->json([
            'message' => $dryRun ? 'معاينة الاستيراد' : 'تم تنفيذ الاستيراد',
            'data' => $report,
        ]);
    }

    /**
     * Bulk adjust item prices in the price list by a specified percentage.
     */
    public function applyPercentage(Request $request, PriceList $priceList): JsonResponse
    {
        $data = $request->validate(['percent' => ['required', 'numeric', 'min:-100', 'max:1000000']]);
        $updated = $this->priceLists->applyPercentage($priceList, (float) $data['percent'], $request->user());

        return response()->json(['data' => ['updated' => $updated]]);
    }

    /**
     * Export price list items as a streaming CSV download.
     */
    public function export(Request $request, PriceList $priceList): StreamedResponse
    {
        return $this->priceLists->exportCsv($priceList, $request->user());
    }
}
