<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\ProductStatus;
use App\Exceptions\BusinessException;
use App\Models\Category;
use App\Models\Product;

class CategoryHierarchy
{
    public static function snapshot(): array
    {
        return Category::query()->get(['id', 'parent_id', 'is_active'])->keyBy('id')->toArray();
    }

    public static function assertValid(array $nodes): void
    {
        foreach (array_keys($nodes) as $id) {
            $seen = [];
            while ($id !== null) {
                if (isset($seen[$id])) {
                    throw new BusinessException('توجد دورة في شجرة الأقسام');
                }
                if (! isset($nodes[$id])) {
                    throw new BusinessException('القسم الأب غير موجود');
                }
                $seen[$id] = true;
                if (count($seen) > 3) {
                    throw new BusinessException('أقصى عمق للأقسام 3 مستويات');
                }
                $id = $nodes[$id]['parent_id'];
            }
        }
    }

    public static function assertPublishedPathsActive(array $nodes): void
    {
        $categoryIds = Product::query()->where('status', ProductStatus::Published)->distinct()->pluck('category_id');
        foreach ($categoryIds as $id) {
            while ($id !== null) {
                if (! isset($nodes[$id]) || ! $nodes[$id]['is_active']) {
                    throw new BusinessException('لا يمكن تعطيل أو نقل قسم يحتوي على منتجات منشورة إلى سلسلة أقسام غير نشطة');
                }
                $id = $nodes[$id]['parent_id'];
            }
        }
    }

    public static function activePath(?Category $category): bool
    {
        $seen = [];
        while ($category) {
            if (! $category->is_active || isset($seen[$category->id]) || count($seen) >= 3) {
                return false;
            }
            $seen[$category->id] = true;
            if ($category->parent_id === null) {
                return true;
            }
            $category = Category::find($category->parent_id);
        }

        return false;
    }
}
