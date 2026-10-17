<?php

declare(strict_types=1);

namespace App\Enums;

/** حالات بوليصة الشحن. */
enum ShipmentStatus: string
{
    case Created = 'created';
    case PickedUp = 'picked_up';
    case InTransit = 'in_transit';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Returned = 'returned';
}
