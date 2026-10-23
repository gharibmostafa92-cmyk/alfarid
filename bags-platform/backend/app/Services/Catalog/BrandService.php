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
                return DB::transaction(function () use ($data, $logo) {
                    unset($data['logo']);
                    $data = array_filter($data, fn ($value) => $value !== null);

                    if (empty($data['slug'])) {
                        $data['slug'] = SlugService::make(Brand::class, $data['name_en']);
                    }

                    $brand = Brand::create($data);

                    if ($logo) {
                        $brand->addMedia($logo)->toMediaCollection('logo');
                    }

                    return $brand;
                });
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
        return DB::transaction(function () use ($brand, $data, $logo) {
            $brand = Brand::whereKey($brand->id)->lockForUpdate()->firstOrFail();
            $removeLogo = (bool) ($data['remove_logo'] ?? false);
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

            if ($removeLogo) {
                $brand->clearMediaCollection('logo');
            }

            if ($logo) {
                $brand->addMedia($logo)->toMediaCollection('logo');
            }

            return $brand;
        });
    }

    public function delete(Brand $brand): void
    {
        DB::transaction(function () use ($brand): void {
            // قفل الصف بيمنع حذف البراند في نفس لحظة إنشاء منتج عليه
            $locked = Brand::whereKey($brand->id)->lockForUpdate()->firstOrFail();

            if ($locked->products()->withTrashed()->exists()) {
                throw new BusinessException('لا يمكن حذف براند مرتبط بمنتجات');
            }

            $locked->delete();
        });
    }
}
