<?php

declare(strict_types=1);

// Backwards-compatibility aliases for notifications organized into domain subfolders.
$aliases = [
    // Wholesale
    \App\Notifications\Wholesale\WholesaleApplicationReviewedNotification::class => 'App\Notifications\WholesaleApplicationReviewedNotification',
    \App\Notifications\Wholesale\WholesaleApplicationSubmittedNotification::class => 'App\Notifications\WholesaleApplicationSubmittedNotification',
    \App\Notifications\Wholesale\WholesaleDeskNotification::class => 'App\Notifications\WholesaleDeskNotification',

    // Inventory
    \App\Notifications\Inventory\InventoryAnomalyDigestNotification::class => 'App\Notifications\InventoryAnomalyDigestNotification',
    \App\Notifications\Inventory\LowStockAlertNotification::class => 'App\Notifications\LowStockAlertNotification',
    \App\Notifications\Inventory\TransferPendingApprovalNotification::class => 'App\Notifications\TransferPendingApprovalNotification',
    \App\Notifications\Inventory\TransferSuggestionNotification::class => 'App\Notifications\TransferSuggestionNotification',

    // Auth
    \App\Notifications\Auth\CustomerPasswordReset::class => 'App\Notifications\CustomerPasswordReset',
    \App\Notifications\Auth\CustomerPhoneVerificationCode::class => 'App\Notifications\CustomerPhoneVerificationCode',
    \App\Notifications\Auth\StaffAuthenticationAlert::class => 'App\Notifications\StaffAuthenticationAlert',
];

foreach ($aliases as $original => $alias) {
    if (! class_exists($alias, false)) {
        class_alias($original, $alias);
    }
}
