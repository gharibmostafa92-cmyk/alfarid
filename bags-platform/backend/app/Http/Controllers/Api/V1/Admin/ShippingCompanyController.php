<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Shipping\ShippingCompanyRequest;
use App\Http\Resources\Admin\ShippingCompanyResource;
use App\Models\ShippingCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/** إدارة شركات الشحن. */
class ShippingCompanyController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:shipping.view', only: ['index', 'show']),
            new Middleware('can:shipping.create', only: ['store']),
            new Middleware('can:shipping.update', only: ['update']),
            new Middleware('can:shipping.delete', only: ['destroy']),
        ];
    }

    public function index(): AnonymousResourceCollection
    {
        return ShippingCompanyResource::collection(ShippingCompany::query()->latest('id')->paginate(20));
    }

    public function store(ShippingCompanyRequest $request): JsonResponse
    {
        $company = ShippingCompany::create($request->validated());

        return ShippingCompanyResource::make($company)->response()->setStatusCode(201);
    }

    public function show(ShippingCompany $shippingCompany): ShippingCompanyResource
    {
        return ShippingCompanyResource::make($shippingCompany);
    }

    public function update(ShippingCompanyRequest $request, ShippingCompany $shippingCompany): ShippingCompanyResource
    {
        $shippingCompany->update($request->validated());

        return ShippingCompanyResource::make($shippingCompany);
    }

    public function destroy(ShippingCompany $shippingCompany): JsonResponse
    {
        $shippingCompany->delete();

        return response()->json(['message' => 'تم حذف شركة الشحن']);
    }
}
