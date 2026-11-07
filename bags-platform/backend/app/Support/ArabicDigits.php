<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Normalizes Eastern Arabic (Arabic-Indic) and Persian numerals to Western digits.
 */
final class ArabicDigits
{
    /** @var array<string, string> Contiguous transliteration map for Arabic-Indic (٠-٩) and Persian (۰-۹) digits. */
    public const MAP = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    /**
     * Transliterates Arabic-Indic and Persian numerals into standard ASCII digits.
     *
     * @param  string  $value  Input text potentially containing Eastern Arabic or Persian numerals.
     * @return string Normalized text with Western ASCII digits (0-9).
     */
    public static function normalize(string $value): string
    {
        return strtr($value, self::MAP);
    }
}
