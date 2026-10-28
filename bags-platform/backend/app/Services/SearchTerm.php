<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Escapes user input to treat SQL LIKE wildcard characters as literal strings.
 */
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

    public static function prefix(string $term): string
    {
        return self::escape($term).'%';
    }
}
