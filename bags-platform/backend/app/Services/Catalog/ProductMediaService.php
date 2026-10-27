<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\ProductStatus;
use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ProductMediaService
{
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

    public function upload(Product $product, array $data, array $files = [], ?UploadedFile $file = null): Collection
    {
        // Images serialize with reorder through a shared cache lock. Network
        // uploads happen before the short database transaction that links them.
        $product = Product::whereKey($product->id)->firstOrFail();
        $owner = ! empty($data['variant_id']) ? $product->variants()->findOrFail($data['variant_id']) : $product;
        if ($data['collection'] === 'video') {
            if ($owner instanceof ProductVariant) {
                throw new BusinessException('الفيديو للمنتج فقط');
            }

            // Upload first; serialize only the short replacement so concurrent
            // uploads cannot delete each other's new single-file video.
            $media = $product->addMedia($file)->toMediaCollection('video_staging', 'r2_public');
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

        // M4: one transaction per image; a retried client_upload_id
        // replays the stored row instead of uploading a duplicate.
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

        // F3: بلا معاملة وبلا قفل مالك — الرفع (I/O شبكي على R2) والتحويلات
        // المُدرجة على الطابور كانت يحبسان صف المنتج/المتغير ثوانٍ لكل ملف.
        // الفهرس الفريد الجزئي على client_upload_id هو الحارس من التكرار،
        // وإعادة المحاولة بنفس المعرّف تعيد صف الفائز عبر الالتقاط أدناه.
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

    public function reorder(Product $product, array $ids): void
    {
        $this->withMediaLock($product->id, fn () => DB::transaction(function () use ($product, $ids): void {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $this->assertMediaBelongs($product, $ids);
            // L6: a partial id list duplicates order_column across the
            // product/variant scope — require the complete owner-collection
            // list before renumbering.
            $this->assertCompleteOrderList($ids);
            Media::setNewOrder($ids);
        }));
    }

    public function delete(Product $product, Media $media): void
    {
        // F9 مغطى في TransactionalMediaObserver: حذف الملفات مؤجل لـ
        // afterCommit (فشل المعاملة يُبقي الملفات)، وملفات الرفع المُجهضة
        // تُنظَّف عند الـ rollback، مع أمر media:prune-orphans الدوري.
        DB::transaction(function () use ($product, $media): void {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $media = Media::whereKey($media->id)->firstOrFail();
            $this->assertMediaBelongs($product, [$media->id]);
            // L6: validate before deleting, not after with a rollback — the
            // file-removal observer must never run for a rejected delete.
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

    /** L6: another usable image must remain besides the one being deleted. */
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
