<?php

declare(strict_types=1);

namespace App\Services;

// بيولّد باركود EAN-13 داخلي. الباركود بيبدأ بـ 2، ودي البادئة المخصصة للاستخدام الداخلي.
class BarcodeGenerator
{
    public static function fromId(int $id): string
    {
        $base = '2'.str_pad((string) $id, 11, '0', STR_PAD_LEFT); // 12 رقم
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $d = (int) $base[$i];
            $sum += ($i % 2 === 0) ? $d : $d * 3;
        }

        return $base.((10 - ($sum % 10)) % 10);
    }
}
