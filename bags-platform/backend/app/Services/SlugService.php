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

        // استعلام واحد بيجيب الـ slug نفسه وأي لاحقات موجودة، وينبني منه أول لاحقة متاحة فوق أعلى لاحقة
        $like = strtr($base, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']).'-%';
        $existing = $modelClass::withTrashed()
            ->where(fn ($q) => $q->where('slug', $base)->orWhere('slug', 'like', $like))
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->pluck('slug');

        if (! $existing->contains($base)) {
            return $base;
        }

        $max = 1;
        foreach ($existing as $slug) {
            if (preg_match('/^'.preg_quote($base, '/').'-(\d+)$/', (string) $slug, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return $base.'-'.($max + 1);
    }
}
