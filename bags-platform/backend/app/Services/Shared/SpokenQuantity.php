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
        'دستة' => 12, 'دست' => 12,
        'تلتميه' => 300, 'ثلاثميه' => 300, 'ثلاثمائه' => 300,
        'ربعميه' => 400, 'اربعمائه' => 400, 'خمسميه' => 500, 'خمسمائه' => 500,
        'ستميه' => 600, 'ستمائه' => 600, 'سبعميه' => 700, 'سبعمائه' => 700,
        'تمنميه' => 800, 'تمانميه' => 800, 'ثمانمائه' => 800,
        'تسعميه' => 900, 'تسعمائه' => 900, 'الفين' => 2000, 'ألفين' => 2000,
        'نص' => 0.5,
    ];

    /** @var list<string> Dozen spellings act as a ×12 multiplier, not an addition. */
    private const DOZENS = ['دسته', 'دستة', 'دست'];

    /** @var list<string> */
    private const FILLER = ['قطعه', 'قطعة', 'قطع', 'حبه', 'حبة', 'حبات', 'علبه', 'علبة', 'علب', 'كرتونه'];

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
        $cleaned = (string) preg_replace('/(?<![A-Za-z0-9_-])[\d.,]+(?![A-Za-z0-9_-])|(?<!\p{L})(?:'.$words.')(?!\p{L})/u', '', $normalized);

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

        $norm = str_replace(['احد عشر', 'اثنا عشر'], ['حداشر', 'اتناشر'], $norm);

        $spaced = str_replace(' و ', ' ', ' '.$norm.' ');
        $tokens = preg_split('/\s+/u', trim($spaced)) ?: [];
        $total = 0.0;
        $group = 0.0;
        $matched = 0;
        $lastSmall = null;
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
            if ($v === 100.0) {
                // M11: hundreds accumulate in the open group so a following
                // thousand multiplies them ("مية ألف" = 100000), instead of
                // flushing 100 into the total first (which yielded 1100).
                $group = ($group > 0 ? $group : 1) * $v;
                $lastSmall = null;
            } elseif ($v === 1000.0) {
                $group = ($group > 0 ? $group : 1) * $v;
                $total += $group;
                $group = 0.0;
                $lastSmall = null;
            } elseif (in_array($token, self::DOZENS, true)) {
                // M2: "دسته" (dozen) multiplies a preceding count ("تلاته
                // دسته" = 36), it is never added to it.
                $group = ($group > 0 ? $group : 1) * $v;
                $lastSmall = null;
            } elseif ($v === 200.0) {
                // M10: "ميتين ألف" = 200 * 1000, not 200 + 1000. Keep the
                // hundred in the open group so a following thousand multiplies it.
                $group += $v;
                $lastSmall = null;
            } else {
                // M2: a repeated ones/tens value ("خمسه خمسه") is a
                // speech-recognition stutter, not an addition — reject it
                // instead of silently doubling the count.
                if ($lastSmall !== null && $v === $lastSmall) {
                    return null;
                }
                $lastSmall = $v;
                $group += $v;
            }
        }
        if ($matched === 0) {
            return null;
        }

        $result = $total + $group;
        // Match the numeric path's upper bound (999999).
        if ($result > 999999) {
            return null;
        }

        return $result;
    }
}
