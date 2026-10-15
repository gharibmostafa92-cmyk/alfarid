<?php

declare(strict_types=1);

namespace App\Enums;

enum CustomerDocumentType: string
{
    case CommercialRegister = 'commercial_register';
    case TaxCard = 'tax_card';
    case NationalId = 'national_id';
    case Other = 'other';
}
