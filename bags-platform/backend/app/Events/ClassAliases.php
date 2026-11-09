<?php

declare(strict_types=1);

// Backwards-compatibility aliases for events organized into domain subfolders.
$aliases = [
    \App\Events\Wholesale\PaymentNoticeReviewed::class => 'App\Events\PaymentNoticeReviewed',
    \App\Events\Wholesale\PaymentNoticeSubmitted::class => 'App\Events\PaymentNoticeSubmitted',
    \App\Events\Wholesale\QuotationAccepted::class => 'App\Events\QuotationAccepted',
    \App\Events\Wholesale\QuotationExpired::class => 'App\Events\QuotationExpired',
    \App\Events\Wholesale\QuotationPriced::class => 'App\Events\QuotationPriced',
    \App\Events\Wholesale\QuotationSubmitted::class => 'App\Events\QuotationSubmitted',
    \App\Events\Wholesale\WholesaleApplicationReviewed::class => 'App\Events\WholesaleApplicationReviewed',
    \App\Events\Wholesale\WholesaleApplicationSubmitted::class => 'App\Events\WholesaleApplicationSubmitted',
];

foreach ($aliases as $original => $alias) {
    if (! class_exists($alias, false)) {
        class_alias($original, $alias);
    }
}
