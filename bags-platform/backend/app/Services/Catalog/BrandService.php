<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Exceptions\BusinessException;
use App\Models\Brand;
use App\Services\SlugService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class BrandService
{
    public function create(array $data, ?UploadedFile $logo): Brand
    {
        // M1: retry once on auto-slug races; explicit conflicts surface as 409.
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

                // F2: the R2 upload runs outside the row lock, in its own
                // short transaction — network I/O must not hold the lock.
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

    public function update(Brand $brand, array $data, ?UploadedFile $logo): Brand
    {
        // F8: a concurrent update can win the slug between validation and the
        // write — surface it as 409, not 500.
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

        // F2: logo changes run outside the row lock (see create()).
        $removeLogo = (bool) ($data['remove_logo'] ?? false);
        if ($logo) {
            $this->attachLogo($updated, $logo);
        } elseif ($removeLogo) {
            DB::transaction(fn () => $updated->clearMediaCollection('logo'));
        }

        return $updated;
    }

    /**
     * F2: attach the logo in its own short transaction with no row lock.
     * The logo is optional, so a failed upload keeps the saved row and
     * reports a clear message instead of rolling back the data.
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

    public function delete(Brand $brand): void
    {
        DB::transaction(function () use ($brand): void {
            // قفل الصف بيمنع حذف البراند في نفس لحظة إنشاء منتج عليه
            $locked = Brand::whereKey($brand->id)->lockForUpdate()->firstOrFail();

            if ($locked->products()->exists()) {
                throw new BusinessException('لا يمكن حذف براند مرتبط بمنتجات');
            }

            $locked->delete();
        });
    }
}
