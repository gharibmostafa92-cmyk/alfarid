<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Promotion\PromotionRequest;
use App\Http\Resources\Admin\PromotionResource;
use App\Models\Promotion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/** إدارة العروض التلقائية. */
class PromotionController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:promotions.view', only: ['index', 'show']),
            new Middleware('can:promotions.create', only: ['store']),
            new Middleware('can:promotions.update', only: ['update']),
            new Middleware('can:promotions.delete', only: ['destroy']),
        ];
    }

    public function index(): AnonymousResourceCollection
    {
        return PromotionResource::collection(Promotion::query()->latest('id')->paginate(20));
    }

    public function store(PromotionRequest $request): JsonResponse
    {
        $promotion = Promotion::create($this->withoutNulls($request->validated()));

        return PromotionResource::make($promotion)->response()->setStatusCode(201);
    }

    public function show(Promotion $promotion): PromotionResource
    {
        return PromotionResource::make($promotion);
    }

    public function update(PromotionRequest $request, Promotion $promotion): PromotionResource
    {
        $promotion->update($this->withoutNulls($request->validated()));

        return PromotionResource::make($promotion);
    }

    /**
     * ‏null صريح لحقول NOT NULL يُحذف من الحمولة بدل ما يكسر الحفظ بـ 500 (M8).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withoutNulls(array $data): array
    {
        foreach (['value', 'applies_to', 'is_active'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] === null) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    public function destroy(Promotion $promotion): JsonResponse
    {
        $promotion->delete();

        return response()->json(['message' => 'تم حذف العرض']);
    }
}
