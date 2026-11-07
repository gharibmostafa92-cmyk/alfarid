<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Origin channel for wholesale quotation requests.
 */
enum QuotationSource: string
{
    case Portal = 'portal';
    case Whatsapp = 'whatsapp';
    case Admin = 'admin';
}
