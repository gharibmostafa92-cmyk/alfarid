<?php

declare(strict_types=1);

namespace App\Enums\Catalog;

enum PriceListType: string
{
    case Retail = 'retail';
    case Wholesale = 'wholesale';
    case Distributor = 'distributor';
    case CustomerGroup = 'customer_group';
    case CustomerSpecific = 'customer_specific';
}
