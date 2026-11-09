<?php

declare(strict_types=1);

// Backwards-compatibility aliases for listeners organized into domain subfolders.
$aliases = [
    // Orders
    \App\Listeners\Orders\SendOrderConfirmation::class => 'App\Listeners\SendOrderConfirmation',
    \App\Listeners\Orders\SendStaffOrderNotification::class => 'App\Listeners\SendStaffOrderNotification',

    // Inventory
    \App\Listeners\Inventory\SendLowStockAlert::class => 'App\Listeners\SendLowStockAlert',
    \App\Listeners\Inventory\SendTransferPendingApproval::class => 'App\Listeners\SendTransferPendingApproval',
    \App\Listeners\Inventory\SuggestTransferRedistribution::class => 'App\Listeners\SuggestTransferRedistribution',

    // Procurement
    \App\Listeners\Procurement\SendPurchaseOrderSent::class => 'App\Listeners\SendPurchaseOrderSent',

    // Wholesale
    \App\Listeners\Wholesale\SendWholesaleApplicationReviewed::class => 'App\Listeners\SendWholesaleApplicationReviewed',
    \App\Listeners\Wholesale\SendWholesaleApplicationSubmitted::class => 'App\Listeners\SendWholesaleApplicationSubmitted',
    \App\Listeners\Wholesale\WholesaleLifecycleSubscriber::class => 'App\Listeners\WholesaleLifecycleSubscriber',
];

foreach ($aliases as $original => $alias) {
    if (! class_exists($alias, false)) {
        class_alias($original, $alias);
    }
}
