<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Str;

class SlugService
{
    public static function make(string $modelClass, string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: strtolower(Str::random(8));
        // قص القاعدة لـ 240 حرف عشان اللاحقة الرقمية متعديش عمود varchar(255) وترجع 500
        $base = rtrim(Str::substr($base, 0, 240), '-');

        // استعلام واحد لكل اللاحقات، ثم اختيار أول لاحقة متاحة في الذاكرة.
        $like = SearchTerm::escape($base).'-%';
        $query = $modelClass::withTrashed();
        $existing = $query
            ->when($query->getModel()->getConnection()->getDriverName() === 'pgsql',
                fn ($q) => $q->whereRaw('slug ~* ?', ['^'.preg_quote($base).'(-[0-9]+)?$']),
                fn ($q) => $q->where(fn ($where) => $where->whereLike('slug', $base)->orWhereLike('slug', $like)))
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->pluck('slug')->map(fn ($slug) => strtolower($slug));

        if (! $existing->contains($base)) {
            return $base;
        }

        $taken = array_fill_keys($existing->all(), true);
        $suffix = 2;
        while (isset($taken[$base.'-'.$suffix])) {
            $suffix++;
        }

        return $base.'-'.$suffix;
    }
}
