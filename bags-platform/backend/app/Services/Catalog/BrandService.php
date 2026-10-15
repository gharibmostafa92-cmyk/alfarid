<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Exceptions\BusinessException;
use App\Models\Brand;
use App\Services\SlugService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class BrandService
{
    public function create(array $data, ?UploadedFile $logo): Brand
    {
        unset($data['logo']);
        $data = array_filter($data, fn ($value) => $value !== null);

        if (empty($data['slug'])) {
            $data['slug'] = SlugService::make(Brand::class, $data['name_en']);
        }

        $brand = DB::transaction(fn () => Brand::create($data));

        // عمليات الملفات بره الـ transaction: ملف اتمسح مبيترجعش مع الـ rollback
        if ($logo) {
            $brand->addMedia($logo)->toMediaCollection('logo');
        }

        return $brand;
    }

    public function update(Brand $brand, array $data, ?UploadedFile $logo): Brand
    {
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

        DB::transaction(fn () => $brand->update($data));

        // استبدال/مسح الشعار آخر خطوة وبعد نجاح حفظ البيانات، عشان فشل لاحق ميسيبش رابط مكسور
        if ($removeLogo) {
            $brand->clearMediaCollection('logo');
        }

        if ($logo) {
            $brand->addMedia($logo)->toMediaCollection('logo');
        }

        return $brand;
    }

    public function delete(Brand $brand): void
    {
        DB::transaction(function () use ($brand): void {
            // قفل الصف بيمنع حذف البراند في نفس لحظة إنشاء منتج عليه
            $locked = Brand::whereKey($brand->id)->lockForUpdate()->first() ?? $brand;

            if ($locked->products()->exists()) {
                throw new BusinessException('لا يمكن حذف براند مرتبط بمنتجات');
            }

            $locked->delete();
        });
    }
}
