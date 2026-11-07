<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Exceptions\BusinessException;
use App\Models\Brand;
use App\Services\SlugService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Manages brand catalog entities, slug generation, logo media attachments, and deletion constraints.
 */
class BrandService
{
    /**
     * Creates a new brand record with optional logo media.
     *
     * @param  array<string, mixed>  $data  Brand attributes including localized names.
     * @param  UploadedFile|null  $logo  Optional image file for brand logo.
     * @return Brand Created brand instance.
     *
     * @throws BusinessException If unique slug collision occurs or logo upload fails.
     */
    public function create(array $data, ?UploadedFile $logo): Brand
    {
        // Retry once on generated slug races before returning a 409 conflict error.
        $autoSlug = empty($data['slug'] ?? null);
        for ($attempt = 0; ; $attempt++) {
            try {
                $brand = DB::transaction(function () use ($data) {
                    unset($data['logo']);
                    $data = array_filter($data, fn ($value) => $value !== null);

                    if (empty($data['slug'])) {
                        $data['slug'] = SlugService::make(Brand::class, $data['name_en']);
                    }

                    return Brand::create($data);
                });

                // Perform media uploads in a separate transaction to avoid holding database row locks during remote network I/O.
                if ($logo) {
                    $this->attachLogo($brand, $logo);
                }

                return $brand;
            } catch (UniqueConstraintViolationException $e) {
                if ($autoSlug && $attempt === 0) {
                    $data['slug'] = SlugService::make(Brand::class, $data['name_en']);

                    continue;
                }
                throw new BusinessException('المعرف (slug) مستخدم بالفعل؛ اختر قيمة أخرى.', [], 409);
            }
        }
    }

    /**
     * Updates an existing brand record and syncs logo media.
     *
     * @param  Brand  $brand  Target brand model.
     * @param  array<string, mixed>  $data  Updated attributes.
     * @param  UploadedFile|null  $logo  Replacement logo file if provided.
     * @return Brand Refreshed brand instance.
     *
     * @throws BusinessException If unique slug collision occurs or logo upload fails.
     */
    public function update(Brand $brand, array $data, ?UploadedFile $logo): Brand
    {
        // Catch concurrent unique slug collisions and convert to 409 conflict responses.
        try {
            $updated = DB::transaction(function () use ($brand, $data) {
                $brand = Brand::whereKey($brand->id)->lockForUpdate()->firstOrFail();
                unset($data['logo'], $data['remove_logo']);

                if (array_key_exists('slug', $data) && empty($data['slug'])) {
                    $data['slug'] = SlugService::make(Brand::class, $data['name_en'] ?? $brand->name_en, $brand->id);
                }

                foreach (['sort_order', 'is_active'] as $field) {
                    if (array_key_exists($field, $data) && $data[$field] === null) {
                        unset($data[$field]);
                    }
                }

                $brand->update($data);

                return $brand;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new BusinessException('المعرف (slug) مستخدم بالفعل؛ اختر قيمة أخرى.', [], 409);
        }

        // Execute logo updates outside main entity transaction to prevent long-running table locks.
        $removeLogo = (bool) ($data['remove_logo'] ?? false);
        if ($logo) {
            $this->attachLogo($updated, $logo);
        } elseif ($removeLogo) {
            DB::transaction(fn () => $updated->clearMediaCollection('logo'));
        }

        return $updated;
    }

    /**
     * Attaches a brand logo in an isolated transaction without holding table locks.
     */
    private function attachLogo(Brand $brand, UploadedFile $logo): void
    {
        try {
            DB::transaction(fn () => $brand->addMedia($logo)->toMediaCollection('logo'));
        } catch (\Throwable $e) {
            report($e);
            throw new BusinessException('تم حفظ العلامة لكن فشل رفع الشعار؛ أعد رفعه من صفحة التعديل.', ['brand_id' => [$brand->id]], 422, 'brand_logo_failed');
        }
    }

    /**
     * Deletes a brand if not referenced by existing catalog products.
     *
     * @param  Brand  $brand  Brand instance to delete.
     * @return void
     *
     * @throws BusinessException If brand has associated products.
     */
    public function delete(Brand $brand): void
    {
        DB::transaction(function () use ($brand): void {
            // Lock row to prevent deleting a brand concurrently while a product is being assigned to it.
            $locked = Brand::whereKey($brand->id)->lockForUpdate()->firstOrFail();

            if ($locked->products()->exists()) {
                throw new BusinessException('لا يمكن حذف براند مرتبط بمنتجات');
            }

            $locked->delete();
        });
    }
}
