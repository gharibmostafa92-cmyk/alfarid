<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\ProductStatus;
use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ProductMediaService
{
    public function index(Product $product): Collection
    {
        $variantIds = $product->variants()->withTrashed()->pluck('id');
        $media = Media::query()
            ->where(fn ($q) => $q->where(['model_type' => Product::class, 'model_id' => $product->id])
                ->orWhere(fn ($w) => $w->where('model_type', ProductVariant::class)->whereIn('model_id', $variantIds)))
            ->orderBy('order_column')->orderBy('id')->get();

        return $media;
    }

    public function upload(Product $product, array $data, array $files = [], ?UploadedFile $file = null): Collection
    {
        // H2: no product row lock here — uploads only append images, so they must
        // not hold the product lock (and DB connection) while files stream to R2
        // and conversions render. Delete/reorder keep their own locks.
        $product = Product::whereKey($product->id)->firstOrFail();
        $owner = ! empty($data['variant_id']) ? $product->variants()->findOrFail($data['variant_id']) : $product;
        if ($data['collection'] === 'video') {
            if ($owner instanceof ProductVariant) {
                throw new BusinessException('الفيديو للمنتج فقط');
            }

            return DB::transaction(function () use ($product, $file) {
                $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();

                return collect([$product->addMedia($file)->toMediaCollection('video')]);
            });
        }

        // H2 + M9: one short transaction per file; a retried client_upload_id
        // replays the stored row instead of uploading a duplicate.
        $clientIds = array_values((array) ($data['client_upload_ids'] ?? []));

        return collect(array_values($files))->map(fn ($image, $index) => $this->storeOne($owner, $image, $clientIds[$index] ?? null));
    }

    private function storeOne(Product|ProductVariant $owner, UploadedFile $image, ?string $clientUploadId): Media
    {
        if (is_string($clientUploadId) && $clientUploadId !== '') {
            $existing = Media::query()
                ->where('model_type', $owner->getMorphClass())
                ->where('model_id', $owner->getKey())
                ->where('custom_properties->client_upload_id', $clientUploadId)
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        try {
            return DB::transaction(function () use ($owner, $image, $clientUploadId) {
                if (is_string($clientUploadId) && $clientUploadId !== '') {
                    $existing = Media::query()
                        ->where('model_type', $owner->getMorphClass())
                        ->where('model_id', $owner->getKey())
                        ->where('custom_properties->client_upload_id', $clientUploadId)
                        ->lockForUpdate()
                        ->first();
                    if ($existing) {
                        return $existing;
                    }

                    return $owner->addMedia($image)->withCustomProperties(['client_upload_id' => $clientUploadId])->toMediaCollection('images');
                }

                return $owner->addMedia($image)->toMediaCollection('images');
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Concurrent retry with the same client_upload_id: replay the winner.
            $existing = Media::query()
                ->where('model_type', $owner->getMorphClass())
                ->where('model_id', $owner->getKey())
                ->where('custom_properties->client_upload_id', $clientUploadId)
                ->first();
            if ($clientUploadId && $existing) {
                return $existing;
            }
            throw $exception;
        }
    }

    public function reorder(Product $product, array $ids): void
    {
        DB::transaction(function () use ($product, $ids): void {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $this->assertMediaBelongs($product, $ids);
            Media::setNewOrder($ids);
        });
    }

    public function delete(Product $product, Media $media): void
    {
        DB::transaction(function () use ($product, $media): void {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $media = Media::whereKey($media->id)->firstOrFail();
            $this->assertMediaBelongs($product, [$media->id]);
            $media->delete();
            if ($product->status === ProductStatus::Published && $media->collection_name === 'images'
                && ! app(ProductPublishabilityChecker::class)->hasImages($product)) {
                throw new BusinessException('لا يمكن حذف آخر صورة لمنتج منشور');
            }
        });
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
