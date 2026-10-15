<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\ProductStatus;
use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ProductMediaService
{
    public function index(Product $product): Collection
    {
        $variantIds = $product->variants()->pluck('id');
        $media = Media::query()
            ->where(fn ($q) => $q->where(['model_type' => Product::class, 'model_id' => $product->id])
                ->orWhere(fn ($w) => $w->where('model_type', ProductVariant::class)->whereIn('model_id', $variantIds)))
            ->orderBy('order_column')->get();

        return $media;
    }

    public function upload(Product $product, array $data, array $files = [], ?UploadedFile $file = null): Collection
    {
        return DB::transaction(function () use ($product, $data, $files, $file) {
            $owner = ! empty($data['variant_id']) ? $product->variants()->findOrFail($data['variant_id']) : $product;
            if ($data['collection'] === 'video') {
                if ($owner instanceof ProductVariant) {
                    throw new BusinessException('الفيديو للمنتج فقط');
                }

                return collect([$product->addMedia($file)->toMediaCollection('video')]);
            }

            return collect($files)->map(fn ($image) => $owner->addMedia($image)->toMediaCollection('images'));
        });
    }

    public function reorder(Product $product, array $ids): void
    {
        $this->assertMediaBelongs($product, $ids);
        DB::transaction(fn () => Media::setNewOrder($ids));
    }

    public function delete(Product $product, Media $media): void
    {
        $this->assertMediaBelongs($product, [$media->id]);
        if ($product->status === ProductStatus::Published && $media->collection_name === 'images'
            && $media->model_type === Product::class && $product->getMedia('images')->count() <= 1) {
            throw new BusinessException('لا يمكن حذف آخر صورة لمنتج منشور');
        }
        $media->delete();
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
