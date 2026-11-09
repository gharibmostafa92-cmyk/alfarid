<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\Catalog\ProductStatus;
use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\CatalogMedia;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Handles product and variant media uploads, R2 storage staging, ordering, and deletion safety.
 */
class ProductMediaService
{
    /**
     * Lists all active media items for a product and its associated variants.
     *
     * @param  Product  $product  Product model instance.
     * @return Collection<int, Media>
     */
    public function index(Product $product): Collection
    {
        $variantIds = $product->variants()->withTrashed()->pluck('id');
        $media = Media::query()
            ->whereIn('collection_name', ['images', 'video'])
            ->where(fn ($q) => $q->where(['model_type' => Product::class, 'model_id' => $product->id])
                ->orWhere(fn ($w) => $w->where('model_type', ProductVariant::class)->whereIn('model_id', $variantIds)))
            ->orderBy('order_column')->orderBy('id')->get();

        return $media;
    }

    /**
     * Uploads media items to cloud storage and associates them with product or variant collections.
     *
     * @param  Product  $product  Target parent product.
     * @param  array<string, mixed>  $data  Upload parameters including collection and optional variant_id.
     * @param  list<UploadedFile>  $files  Batch of image files for images collection.
     * @param  UploadedFile|null  $file  Single video file for video collection.
     * @return Collection<int, Media> Newly attached media models.
     *
     * @throws BusinessException If variant is assigned a video or upload lock times out.
     */
    public function upload(Product $product, array $data, array $files = [], ?UploadedFile $file = null): Collection
    {
        // Serialize uploads and reordering through cache locks to keep network I/O outside database locks.
        $product = Product::whereKey($product->id)->firstOrFail();
        $owner = ! empty($data['variant_id']) ? $product->variants()->findOrFail($data['variant_id']) : $product;
        if ($data['collection'] === 'video') {
            if ($owner instanceof ProductVariant) {
                throw new BusinessException('الفيديو للمنتج فقط');
            }

            // Upload video to staging first before atomic replacement to prevent race conditions from dropping active files.
            $media = $product->addMedia($file)->toMediaCollection('video_staging', CatalogMedia::disk());
            try {
                DB::transaction(function () use ($product, $media): void {
                    $fresh = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
                    $fresh->media()->where('collection_name', 'video')->get()->each->delete();
                    $media->update(['collection_name' => 'video']);
                });
            } catch (\Throwable $exception) {
                $media->delete();
                throw $exception;
            }

            return collect([$media]);
        }

        // Process images individually so client_upload_id idempotency keys can replay without duplicating files.
        $clientIds = array_values((array) ($data['client_upload_ids'] ?? []));

        return collect(array_values($files))->map(fn ($image, $index) => $this->storeOne($owner, $image, $clientIds[$index] ?? null));
    }

    private function storeOne(Product|ProductVariant $owner, UploadedFile $image, ?string $clientUploadId): Media
    {
        $productId = $owner instanceof Product ? $owner->id : $owner->product_id;

        return $this->withMediaLock($productId, fn () => $this->storeOneUnlocked($owner, $image, $clientUploadId));
    }

    private function withMediaLock(int $productId, \Closure $operation): mixed
    {
        try {
            return Cache::lock('catalog-media:'.$productId, 300)->block(5, $operation);
        } catch (LockTimeoutException) {
            throw new BusinessException('جاري رفع أو ترتيب وسائط المنتج؛ أعد المحاولة بعد لحظات.', [], 409);
        }
    }

    private function storeOneUnlocked(Product|ProductVariant $owner, UploadedFile $image, ?string $clientUploadId): Media
    {
        if (is_string($clientUploadId) && $clientUploadId !== '') {
            $existing = Media::query()
                ->where('model_type', $owner->getMorphClass())
                ->where('model_id', $owner->getKey())
                ->where('custom_properties->client_upload_id', $clientUploadId)
                ->first();
            if ($existing) {
                if ($existing->collection_name === 'images_staging' && $existing->created_at->lt(now()->subMinutes(15))) {
                    $existing->delete();
                    $existing = null;
                }
            }
            if ($existing) {
                if ($existing->collection_name !== 'images') {
                    throw new BusinessException('لم يكتمل رفع الصورة بعد؛ أعد المحاولة بعد لحظات.', [], 409);
                }

                return $existing;
            }
        }

        // Upload to staging collection outside database locks to prevent slow cloud network I/O from blocking concurrent requests.
        $stagingId = (string) Str::uuid();
        try {
            $properties = ['staging_upload_id' => $stagingId];
            if (is_string($clientUploadId) && $clientUploadId !== '') {
                $properties['client_upload_id'] = $clientUploadId;
            }
            $adder = $owner->addMedia($image)->withCustomProperties($properties);
            $media = $adder->toMediaCollection('images_staging');
            try {
                DB::transaction(function () use ($owner, $media): void {
                    $productId = $owner instanceof Product ? $owner->id : $owner->product_id;
                    Product::whereKey($productId)->lockForUpdate()->firstOrFail();
                    if ($owner instanceof ProductVariant) {
                        ProductVariant::whereKey($owner->id)->where('product_id', $productId)->lockForUpdate()->firstOrFail();
                    }
                    $media->update(['collection_name' => 'images']);
                    DB::afterCommit(function () use ($media): void {
                        try {
                            app(FileManipulator::class)->createDerivedFiles($media);
                        } catch (\Throwable $exception) {
                            report($exception);
                        }
                    });
                });
            } catch (\Throwable $exception) {
                $media->delete();
                throw $exception;
            }

            return $media->refresh();
        } catch (UniqueConstraintViolationException $exception) {
            // Concurrent retry with the same client_upload_id: replay the winner.
            $existing = Media::query()
                ->where('model_type', $owner->getMorphClass())
                ->where('model_id', $owner->getKey())
                ->where('custom_properties->client_upload_id', $clientUploadId)
                ->first();
            if ($clientUploadId && $existing && $existing->collection_name === 'images') {
                return $existing;
            }
            if ($clientUploadId && $existing) {
                throw new BusinessException('لم يكتمل رفع الصورة بعد؛ أعد المحاولة بعد لحظات.', [], 409);
            }
            throw $exception;
        } catch (\Throwable $exception) {
            Media::query()->where('model_type', $owner->getMorphClass())->where('model_id', $owner->id)
                ->where('collection_name', 'images_staging')->where('custom_properties->staging_upload_id', $stagingId)
                ->get()->each->delete();
            throw $exception;
        }
    }

    /**
     * Reorders media items within an owner collection, validating expected sequence.
     *
     * @param  Product  $product  Parent product model.
     * @param  list<int>  $ids  Ordered list of media identifiers.
     * @param  list<int>|null  $expectedIds  Optional optimistic concurrency expectation.
     * @return void
     *
     * @throws BusinessException If order changed concurrently or list is incomplete.
     */
    public function reorder(Product $product, array $ids, ?array $expectedIds = null): void
    {
        $this->withMediaLock($product->id, fn () => DB::transaction(function () use ($product, $ids, $expectedIds): void {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            if ($expectedIds !== null) {
                $first = Media::query()->whereIn('id', $ids)->first();
                $currentIds = $first ? Media::query()->where('model_type', $first->model_type)
                    ->where('model_id', $first->model_id)->where('collection_name', $first->collection_name)
                    ->orderBy('order_column')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all() : [];
                if ($currentIds !== array_map('intval', $expectedIds)) {
                    throw new BusinessException('ترتيب الصور اتغير أثناء المراجعة؛ اطلب اقتراحًا جديدًا.', [], 409);
                }
            }
            $this->assertMediaBelongs($product, $ids);
            // Require complete collection ID list to prevent partial ordering from causing duplicate sequence indexes.
            $this->assertCompleteOrderList($ids);
            Media::setNewOrder($ids);
        }));
    }

    /**
     * Deletes a media item, enforcing publishability invariants that require at least one photo.
     *
     * @param  Product  $product  Parent product model.
     * @param  Media  $media  Target media model to delete.
     * @return void
     *
     * @throws BusinessException If deleting the last photo of a published product.
     */
    public function delete(Product $product, Media $media): void
    {
        // Defer remote file deletions until afterCommit via TransactionalMediaObserver to preserve files if transaction rolls back.
        DB::transaction(function () use ($product, $media): void {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $media = Media::whereKey($media->id)->firstOrFail();
            $this->assertMediaBelongs($product, [$media->id]);
            // Validate last-image restriction before invoking delete to ensure file cleanup observer is never triggered on rejected deletes.
            if ($product->status === ProductStatus::Published && $media->collection_name === 'images'
                && ! $this->hasOtherImages($product, $media->id)) {
                throw new BusinessException('لا يمكن حذف آخر صورة لمنتج منشور');
            }
            $media->delete();
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

    /** Asserts that at least one active image remains on the published product after deletion. */
    private function hasOtherImages(Product $product, int $exceptMediaId): bool
    {
        $variantIds = $product->variants()->where('is_active', true)->pluck('id')->all();

        return Media::query()
            ->where('collection_name', 'images')
            ->whereKeyNot($exceptMediaId)
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('model_type', Product::class)->where('model_id', $product->id))
                ->orWhere(fn ($w) => $w->where('model_type', ProductVariant::class)->whereIn('model_id', $variantIds)))
            ->exists();
    }

    /** Reorder exactly one owner's complete media collection. */
    private function assertCompleteOrderList(array $ids): void
    {
        $first = Media::query()->whereIn('id', $ids)->first();
        if ($first === null || count($ids) !== count(array_unique($ids))) {
            throw new BusinessException('قائمة الترتيب يجب أن تشمل مجموعة وسائط كاملة لمالك واحد.');
        }
        $total = Media::query()
            ->where('model_type', $first->model_type)
            ->where('model_id', $first->model_id)
            ->where('collection_name', $first->collection_name)
            ->count();
        $selected = Media::query()->whereIn('id', $ids)
            ->where('model_type', $first->model_type)->where('model_id', $first->model_id)
            ->where('collection_name', $first->collection_name)->count();
        if (count($ids) !== $total || $selected !== $total) {
            throw new BusinessException('قائمة الترتيب يجب أن تشمل مجموعة وسائط كاملة لمالك واحد.');
        }
    }
}
