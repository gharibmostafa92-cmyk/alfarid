<?php

declare(strict_types=1);

namespace App\Services;

// تجهيز نص البحث لاستعلام LIKE: الـ wildcards اللي جاية من المستخدم بتتعامل كنص حرفي مش نمط
class SearchTerm
{
    public static function escape(string $term): string
    {
        return strtr($term, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }

    public static function pattern(string $term): string
    {
        return '%'.self::escape($term).'%';
    }
}
