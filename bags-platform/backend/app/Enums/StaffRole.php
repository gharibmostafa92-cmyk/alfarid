<?php

declare(strict_types=1);

namespace App\Enums;

enum StaffRole: string
{
    case SuperAdmin = 'Super Admin';
    case Cashier = 'cashier';

    public static function normalizeName(string $name): string
    {
        return trim(\Normalizer::normalize($name, \Normalizer::FORM_KC) ?: $name);
    }

    public static function isReserved(string $name): bool
    {
        $name = mb_strtolower(self::normalizeName($name));
        foreach (self::cases() as $role) {
            if ($name === mb_strtolower($role->value)) {
                return true;
            }
        }

        return false;
    }
}
