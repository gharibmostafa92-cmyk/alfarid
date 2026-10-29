<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Unique URL slug generator for Eloquent models.
 *
 * Generates SEO-friendly slugs with automatic numeric deduplication (-2, -3, ...)
 * and guarantees length boundaries to fit database varchar(255) constraints.
 */
class SlugService
{
    /**
     * Generate a unique slug for an Eloquent model instance.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @param  string  $source
     * @param  int|null  $ignoreId
     * @return string
     */
    public static function make(string $modelClass, string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: strtolower(Str::random(8));
        // Cap base slug at 240 characters so appended numeric suffixes stay within varchar(255) column limits
        $base = rtrim(Str::substr($base, 0, 240), '-');

        // Fetch all existing candidate slugs in a single query and find the next available integer suffix in memory
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
