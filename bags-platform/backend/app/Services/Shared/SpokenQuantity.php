<?php

declare(strict_types=1);

namespace App\Services\Shared;

use App\Services\Ai\EntityResolver;
use App\Support\ArabicDigits;

/**
 * Parses spoken Arabic quantities ("اتنين وعشرين") into numbers for the
 * POS voice-count mode. Fractions are not supported — counts are integers.
 */
final class SpokenQuantity
{
    /** @var array<string, float> */
    private const WORDS = [
        'صفر' => 0, 'واحد' => 1, 'واحده' => 1, 'اتنين' => 2, 'اثنين' => 2,
        'اثنان' => 2, 'تنين' => 2, 'تلاته' => 3, 'ثلاثه' => 3, 'اربعه' => 4,
        'خمسه' => 5, 'سته' => 6, 'سبعه' => 7, 'تمانيه' => 8, 'ثمانيه' => 8,
        'تسعه' => 9, 'عشره' => 10, 'عشر' => 10, 'حداشر' => 11, 'احد عشر' => 11,
        'اتناشر' => 12, 'اطناشر' => 12, 'اثنا عشر' => 12, 'تلتاشر' => 13,
        'اربعتاشر' => 14, 'خمستاشر' => 15, 'ستاشر' => 16, 'سبعتاشر' => 17,
        'تمنتاشر' => 18, 'تسعتاشر' => 19, 'عشرين' => 20, 'تلاتين' => 30,
        'اربعين' => 40, 'خمسين' => 50, 'ستين' => 60, 'سبعين' => 70,
        'تمانين' => 80, 'تسعين' => 90, 'ميه' => 100, 'ميت' => 100,
        'مايه' => 100, 'مائه' => 100, 'ميتين' => 200, 'الف' => 1000, 'دسته' => 12,
    ];

    /** @var list<string> */
    private const FILLER = ['قطعه', 'قطع', 'حبه', 'حبات', 'علبه', 'علب', 'كرتونه'];

    /**
     * Build regex alternation pattern for all spoken quantity and filler tokens.
     */
    public static function wordsPattern(): string
    {
        static $pattern = null;
        if ($pattern === null) {
            $tokens = array_merge(array_keys(self::WORDS), self::FILLER, ['دستة', 'دسته', 'دست']);
            usort($tokens, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));
            $escaped = array_map(fn (string $t) => preg_quote($t, '/'), array_unique($tokens));
            $pattern = implode('|', $escaped);
        }

        return $pattern;
    }

    /**
     * Strip digits, spoken quantity words, and unit fillers from the given text.
     */
    public static function stripQuantityTokens(string $text): string
    {
        $normalized = ArabicDigits::normalize($text);
        $words = self::wordsPattern();
        $cleaned = (string) preg_replace('/[\d.,]+|'.$words.'/u', '', $normalized);

        return trim((string) preg_replace('/\s+/u', ' ', $cleaned));
    }

    public static function parse(?string $input): ?float
    {
        if ($input === null) {
            return null;
        }
        $clean = trim(ArabicDigits::normalize($input));
        if ($clean === '') {
            return null;
        }
        if (is_numeric($clean)) {
            $v = (float) $clean;
            if ($v >= 0 && $v <= 999999 && floor($v) == $v) {
                return $v;
            }

            return null;
        }

        $norm = EntityResolver::normalize($clean);
        if (isset(self::WORDS[$norm])) {
            return self::WORDS[$norm];
        }

        $spaced = str_replace(' و ', ' ', ' '.$norm.' ');
        $tokens = preg_split('/\s+/u', trim($spaced)) ?: [];
        $total = 0.0;
        $group = 0.0;
        $matched = 0;
        foreach ($tokens as $i => $token) {
            if ($token === '' || $token === 'و') {
                continue;
            }
            // Strip prefix conjunction 'waw' from subsequent tokens when word is not recognized directly.
            if ($i > 0 && mb_substr($token, 0, 1) === 'و' && mb_strlen($token) > 1 && ! isset(self::WORDS[$token])) {
                $token = mb_substr($token, 1);
                if ($token === '' || $token === 'و') {
                    continue;
                }
            }
            if (in_array($token, self::FILLER, true)) {
                continue;
            }
            if (! isset(self::WORDS[$token])) {
                return null;
            }
            $matched++;
            // WORDS holds ints; strict float comparisons below need a float.
            $v = (float) self::WORDS[$token];
            if ($v === 1000.0 || $v === 100.0) {
                $group = ($group > 0 ? $group : 1) * $v;
                $total += $group;
                $group = 0.0;
            } elseif ($v === 200.0) {
                $total += $v;
            } else {
                $group += $v;
            }
        }
        if ($matched === 0) {
            return null;
        }

        return $total + $group;
    }
}
