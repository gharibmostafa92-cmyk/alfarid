<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Internal EAN-13 barcode generator.
 *
 * Uses the GS1 prefix '2' reserved for internal in-store item numbering
 * and computes the standard modulo 10 checksum digit.
 */
class BarcodeGenerator
{
    /**
     * Generate a valid 13-digit EAN-13 barcode string from an entity ID.
     *
     * @param  int  $id
     * @return string
     */
    public static function fromId(int $id): string
    {
        $base = '2'.str_pad((string) $id, 11, '0', STR_PAD_LEFT);
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $d = (int) $base[$i];
            $sum += ($i % 2 === 0) ? $d : $d * 3;
        }

        return $base.((10 - ($sum % 10)) % 10);
    }
}
