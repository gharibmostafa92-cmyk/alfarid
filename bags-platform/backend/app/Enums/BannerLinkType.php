<?php

declare(strict_types=1);

namespace App\Enums;

enum BannerLinkType: string
{
    case Product = 'product';
    case Category = 'category';
    case Brand = 'brand';
    case External = 'external';
    case None = 'none';
}
