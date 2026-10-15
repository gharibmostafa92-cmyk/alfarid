<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Product\ReorderProductMediaRequest;
use App\Http\Requests\Admin\Product\UploadProductMediaRequest;
use App\Http\Resources\MediaResource;
use App\Models\Product;
use App\Services\Catalog\ProductMediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ProductMediaController extends Controller implements HasMiddleware
{
    public function __construct(private ProductMediaService $service) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:products.view', only: ['index']),
            new Middleware('can:products.update', only: ['store', 'reorder', 'destroy']),
        ];
    }

    public function index(Product $product): AnonymousResourceCollection
    {
        return MediaResource::collection($this->service->index($product));
    }

    public function store(UploadProductMediaRequest $request, Product $product): JsonResponse
    {
        $media = $this->service->upload($product, $request->validated(), $request->file('files', []), $request->file('file'));

        return MediaResource::collection($media)->response()->setStatusCode(201);
    }

    public function reorder(ReorderProductMediaRequest $request, Product $product): JsonResponse
    {
        $this->service->reorder($product, $request->validated('ids'));

        return response()->json(['message' => 'تم الترتيب']);
    }

    public function destroy(Product $product, Media $media): JsonResponse
    {
        $this->service->delete($product, $media);

        return response()->json(['message' => 'تم الحذف']);
    }
}
