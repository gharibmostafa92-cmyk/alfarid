<?php

declare(strict_types=1);

namespace App\Enums;

enum TreasuryType: string
{
    case MainSafe    = 'main_safe';
    case PosDrawer   = 'pos_drawer';
    case BankAccount = 'bank_account';
}
