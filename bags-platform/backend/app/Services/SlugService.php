<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Str;

class SlugService
{
    public static function make(string $modelClass, string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: Str::random(8);
        // قص القاعدة لـ 240 حرف عشان اللاحقة الرقمية متعديش عمود varchar(255) وترجع 500
        $base = rtrim(Str::substr($base, 0, 240), '-');
        $slug = $base;
        $i = 2;
        while ($modelClass::withTrashed()->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
