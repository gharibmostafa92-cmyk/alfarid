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
use Symfony\Component\HttpFoundation\Response;

class PriceListController extends Controller implements HasMiddleware
{
    public function __construct(private readonly PriceListService $priceLists) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:price_lists.view', only: ['index', 'show']),
            new Middleware('can:price_lists.create', only: ['store']),
            new Middleware('can:price_lists.update', only: ['update']),
            new Middleware('can:price_lists.delete', only: ['destroy']),
            new Middleware('can:price_lists.import', only: ['import']),
            new Middleware('can:price_lists.export', only: ['export']),
        ];
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::in(array_column(PriceListType::cases(), 'value'))],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'customer_group_id' => ['nullable', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return PriceListResource::collection($this->priceLists->paginate($filters));
    }

    public function store(StorePriceListRequest $request): JsonResponse
    {
        $priceList = $this->priceLists->create($request->validated());

        return (new PriceListResource($priceList))
            ->additional(['message' => 'تم إنشاء قائمة الأسعار بنجاح'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(PriceList $priceList): PriceListResource
    {
        return new PriceListResource($this->priceLists->find($priceList->id));
    }

    public function update(UpdatePriceListRequest $request, PriceList $priceList): PriceListResource
    {
        return new PriceListResource($this->priceLists->update($priceList, $request->validated()));
    }

    public function destroy(PriceList $priceList): JsonResponse
    {
        $this->priceLists->delete($priceList);

        return response()->json(['message' => 'تم حذف قائمة الأسعار بنجاح']);
    }

    /** استيراد أسعار من CSV (updateOrCreate على القائمة/المتغير/حد الكمية). */
    public function import(ImportPriceListItemsRequest $request, PriceList $priceList): JsonResponse
    {
        $report = $this->priceLists->importCsv($priceList, $request->file('file'));

        return response()->json([
            'message' => 'تم تنفيذ الاستيراد',
            'data' => $report,
        ]);
    }

    /** تصدير عناصر القائمة كملف CSV قابل لإعادة الاستيراد. */
    public function export(PriceList $priceList): Response
    {
        $csv = $this->priceLists->exportCsv($priceList);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="price-list-'.$priceList->id.'.csv"',
        ]);
    }
}
