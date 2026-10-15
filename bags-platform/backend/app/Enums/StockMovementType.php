<?php

declare(strict_types=1);

namespace App\Enums;

enum StockMovementType: string
{
    case PurchaseReceipt = 'purchase_receipt';
    case Sale = 'sale';
    case SaleReturn = 'sale_return';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case Damage = 'damage';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case StocktakeGain = 'stocktake_gain';
    case StocktakeLoss = 'stocktake_loss';
    case Reserve = 'reserve';
    case ReleaseReserve = 'release_reserve';
}
