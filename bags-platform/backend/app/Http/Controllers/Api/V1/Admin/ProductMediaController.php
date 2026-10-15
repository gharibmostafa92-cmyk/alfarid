<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ProductStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Product\ReorderProductMediaRequest;
use App\Http\Requests\Admin\Product\UploadProductMediaRequest;
use App\Http\Resources\MediaResource;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

class ProductMediaController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:products.view', only: ['index']),
            new Middleware('can:products.update', only: ['store', 'reorder', 'destroy']),
        ];
    }

    // GET /products/{product}/media
    public function index(Product $product): AnonymousResourceCollection
    {
        $variantIds = $product->variants()->pluck('id');
        $media = Media::query()
            ->where(fn ($q) => $q->where(['model_type' => Product::class, 'model_id' => $product->id])
                ->orWhere(fn ($w) => $w->where('model_type', ProductVariant::class)->whereIn('model_id', $variantIds)))
            ->orderBy('order_column')->orderBy('id')->get();

        return MediaResource::collection($media);
    }

    // POST /products/{product}/media
    public function store(UploadProductMediaRequest $request, Product $product): JsonResponse
    {
        $owner = $request->variant_id ? $product->variants()->findOrFail($request->variant_id) : $product;

        if ($request->collection === 'video') {
            if ($owner instanceof ProductVariant) {
                throw new BusinessException('الفيديو للمنتج فقط');
            }
            $media = collect([$product->addMediaFromRequest('file')->toMediaCollection('video')]);
        } else {
            // الملفات بتتكتب على الـ disk فورًا ومفيش rollback لها: لو صورة فشلت في النص
            // نمسح اللي اتضاف قبلها بدل ما تفضل ملفات يتيمة على الـ disk
            $added = [];
            try {
                foreach ($request->file('files') as $file) {
                    $added[] = $owner->addMedia($file)->toMediaCollection('images');
                }
            } catch (Throwable $e) {
                foreach ($added as $item) {
                    $item->delete();
                }
                throw $e;
            }
            $media = collect($added);
        }

        return MediaResource::collection($media)->response()->setStatusCode(201);
    }

    // PUT /products/{product}/media/reorder
    public function reorder(ReorderProductMediaRequest $request, Product $product): JsonResponse
    {
        $this->assertMediaBelongs($product, $request->ids);
        Media::setNewOrder($request->ids);

        return response()->json(['message' => 'تم الترتيب']);
    }

    // DELETE /products/{product}/media/{media}
    public function destroy(Product $product, Media $media): JsonResponse
    {
        DB::transaction(function () use ($product, $media): void {
            // قفل صف المنتج بيمنع سباق حذف آخر صورتين (أو الحذف مع النشر) في نفس اللحظة
            $locked = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();

            $this->assertMediaBelongs($locked, [$media->id]);
            if ($locked->status === ProductStatus::Published && $media->collection_name === 'images'
                && $media->model_type === Product::class && $locked->getMedia('images')->count() <= 1) {
                throw new BusinessException('لا يمكن حذف آخر صورة لمنتج منشور');
            }
            $media->delete();
        });

        return response()->json(['message' => 'تم الحذف']);
    }

    private function assertMediaBelongs(Product $product, array $ids): void
    {
        $variantIds = $product->variants()->withTrashed()->pluck('id')->all();
        $count = Media::whereIn('id', $ids)->where(fn ($q) => $q
            ->where(fn ($w) => $w->where('model_type', Product::class)->where('model_id', $product->id))
            ->orWhere(fn ($w) => $w->where('model_type', ProductVariant::class)->whereIn('model_id', $variantIds))
        )->count();
        if ($count !== count(array_unique($ids))) {
            abort(404, 'ملف غير تابع لهذا المنتج');
        }
    }
}
