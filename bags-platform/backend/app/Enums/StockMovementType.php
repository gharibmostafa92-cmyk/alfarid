<?php

declare(strict_types=1);

namespace App\Enums;

enum StockMovementType: string
{
    case ProductionReceipt = 'production_receipt';
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
    case DamageRelease = 'damage_release';
    case DamageWriteOff = 'damage_write_off';
    case TransferInTransit = 'transfer_in_transit';
    case TransferTransitRelease = 'transfer_transit_release';
    case TransferShortage = 'transfer_shortage';
}
