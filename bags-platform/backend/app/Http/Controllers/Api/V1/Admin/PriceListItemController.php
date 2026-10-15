<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Pricing\StorePriceListItemRequest;
use App\Http\Requests\Admin\Pricing\UpdatePriceListItemRequest;
use App\Http\Resources\Admin\PriceListItemResource;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Services\PriceListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class PriceListItemController extends Controller implements HasMiddleware
{
    public function __construct(private readonly PriceListService $priceLists) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:price_lists.view', only: ['index', 'show']),
            new Middleware('can:price_lists.create', only: ['store']),
            new Middleware('can:price_lists.update', only: ['update']),
            new Middleware('can:price_lists.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request, PriceList $priceList): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'product_variant_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return PriceListItemResource::collection($this->priceLists->paginateItems($priceList, $filters, $request->user()));
    }

    public function store(StorePriceListItemRequest $request, PriceList $priceList): JsonResponse
    {
        $item = $this->priceLists->createItem($priceList, $request->validated(), $request->user());

        return (new PriceListItemResource($item))
            ->additional(['message' => 'تمت إضافة سعر الصنف بنجاح'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, PriceList $priceList, PriceListItem $item): PriceListItemResource
    {
        $this->priceLists->assertListAccess($request->user(), $priceList);

        return new PriceListItemResource($item->load('variant.product'));
    }

    public function update(UpdatePriceListItemRequest $request, PriceList $priceList, PriceListItem $item): PriceListItemResource
    {
        return new PriceListItemResource($this->priceLists->updateItem($item, $request->validated(), $request->user()));
    }

    public function destroy(Request $request, PriceList $priceList, PriceListItem $item): JsonResponse
    {
        $this->priceLists->deleteItem($item, $request->user());

        return response()->json(['message' => 'تم حذف سعر الصنف بنجاح']);
    }
}
