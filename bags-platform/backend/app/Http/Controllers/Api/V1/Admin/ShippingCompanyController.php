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
        $data = $request->validated();
        // الأسرار لا تُرجع في الردود (has_api_credentials فقط)، فشاشة التعديل
        // ترسل null — تُتجاهل ولا تمحو الأسرار المحفوظة (M10)
        if (array_key_exists('api_credentials', $data)) {
            if ($data['api_credentials'] === null) {
                unset($data['api_credentials']);
            } else {
                // دمج لا استبدال (F21): تحديث مفتاح واحد لا يمحو بقية أسرار
                // الشركة (مفتاح API، سر الوب هوك...) المعروضة كـ null في الشاشة
                $data['api_credentials'] = array_merge(
                    $shippingCompany->api_credentials ?? [],
                    $data['api_credentials'],
                );
            }
        }
        $shippingCompany->update($data);

        return ShippingCompanyResource::make($shippingCompany);
    }

    public function destroy(ShippingCompany $shippingCompany): JsonResponse
    {
        // شركة لها بوليصات: الحذف يكسر FK — يُمنع ويُعطَّل بدلًا منه (M9)
        if ($shippingCompany->shipments()->exists()) {
            throw new BusinessException('لا يمكن حذف شركة شحن لها بوليصات مسجلة؛ عطّلها بدلًا من حذفها.');
        }
        $shippingCompany->delete();

        return response()->json(['message' => 'تم حذف شركة الشحن']);
    }
}
