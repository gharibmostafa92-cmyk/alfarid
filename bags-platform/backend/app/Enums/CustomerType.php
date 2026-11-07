<?php

declare(strict_types=1);

namespace App\Enums;

enum CustomerType: string
{
    case Retail = 'retail';
    case Wholesale = 'wholesale';
    case Distributor = 'distributor';

    /** @return list<string> */
    public static function commercialValues(): array
    {
        return [self::Wholesale->value, self::Distributor->value];
    }
}
