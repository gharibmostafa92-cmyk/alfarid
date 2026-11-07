<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Shipping\ShippingCompanyRequest;
use App\Http\Resources\Admin\ShippingCompanyResource;
use App\Models\ShippingCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Shipping carrier configurations, integration credentials, and status management.
 */
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

    /**
     * List paginated shipping carriers in reverse chronological order.
     */
    public function index(): AnonymousResourceCollection
    {
        return ShippingCompanyResource::collection(ShippingCompany::query()->latest('id')->paginate(20));
    }

    /**
     * Register a new shipping company.
     */
    public function store(ShippingCompanyRequest $request): JsonResponse
    {
        $company = ShippingCompany::create($request->validated());

        return ShippingCompanyResource::make($company)->response()->setStatusCode(201);
    }

    /**
     * Retrieve shipping company configuration details.
     */
    public function show(ShippingCompany $shippingCompany): ShippingCompanyResource
    {
        return ShippingCompanyResource::make($shippingCompany);
    }

    /**
     * Update shipping company settings with credential merging.
     */
    public function update(ShippingCompanyRequest $request, ShippingCompany $shippingCompany): ShippingCompanyResource
    {
        $data = $request->validated();
        // Form payloads send null for masked secrets; ignore nulls to avoid wiping saved credentials.
        if (array_key_exists('api_credentials', $data)) {
            if ($data['api_credentials'] === null) {
                unset($data['api_credentials']);
            } else {
                // Merge credentials rather than replacing to preserve unedited keys (e.g. webhook secrets).
                $data['api_credentials'] = array_merge(
                    $shippingCompany->api_credentials ?? [],
                    $data['api_credentials'],
                );
            }
        }
        $shippingCompany->update($data);

        return ShippingCompanyResource::make($shippingCompany);
    }

    /**
     * Delete a shipping company if no shipments have been assigned.
     */
    public function destroy(ShippingCompany $shippingCompany): JsonResponse
    {
        // Guard against foreign key violations when historic shipments reference this carrier.
        if ($shippingCompany->shipments()->exists()) {
            throw new BusinessException('لا يمكن حذف شركة شحن لها بوليصات مسجلة؛ عطّلها بدلًا من حذفها.');
        }
        $shippingCompany->delete();

        return response()->json(['message' => 'تم حذف شركة الشحن']);
    }
}
