<?php

declare(strict_types=1);

namespace App\Enums;

enum SalesChannel: string
{
    case Retail = 'retail';
    case Wholesale = 'wholesale';
    case Both = 'both';

    public function allowsRetail(): bool
    {
        return $this !== self::Wholesale;
    }

    public function allowsWholesale(): bool
    {
        return $this !== self::Retail;
    }
}
