<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Logistics parcel delivery lifecycle statuses.
 */
enum ShipmentStatus: string
{
    case Created = 'created';
    case PickedUp = 'picked_up';
    case InTransit = 'in_transit';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Returned = 'returned';

    /**
     * Determine whether the status represents a final terminal delivery lifecycle state.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::Returned], true);
    }
}
