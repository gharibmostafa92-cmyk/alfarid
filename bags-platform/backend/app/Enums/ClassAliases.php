<?php

declare(strict_types=1);

// Backwards-compatibility aliases for enums organized into domain subfolders.
$aliases = [
    // Wholesale
    \App\Enums\Wholesale\QuotationSource::class => 'App\Enums\QuotationSource',
    \App\Enums\Wholesale\QuotationStatus::class => 'App\Enums\QuotationStatus',
    \App\Enums\Wholesale\WholesalePaymentMethod::class => 'App\Enums\WholesalePaymentMethod',
    \App\Enums\Wholesale\WholesalePaymentNoticeStatus::class => 'App\Enums\WholesalePaymentNoticeStatus',

    // Orders
    \App\Enums\Orders\OrderStatus::class => 'App\Enums\OrderStatus',
    \App\Enums\Orders\RefundMethod::class => 'App\Enums\RefundMethod',
    \App\Enums\Orders\ReturnStatus::class => 'App\Enums\ReturnStatus',
    \App\Enums\Orders\ShipmentStatus::class => 'App\Enums\ShipmentStatus',
    \App\Enums\Orders\SalesChannel::class => 'App\Enums\SalesChannel',

    // Inventory
    \App\Enums\Inventory\BranchType::class => 'App\Enums\BranchType',
    \App\Enums\Inventory\LocationType::class => 'App\Enums\LocationType',
    \App\Enums\Inventory\StockBucket::class => 'App\Enums\StockBucket',
    \App\Enums\Inventory\StockMovementType::class => 'App\Enums\StockMovementType',
    \App\Enums\Inventory\StocktakeStatus::class => 'App\Enums\StocktakeStatus',
    \App\Enums\Inventory\TransferStatus::class => 'App\Enums\TransferStatus',

    // Procurement
    \App\Enums\Procurement\PurchaseOrderStatus::class => 'App\Enums\PurchaseOrderStatus',
    \App\Enums\Procurement\PurchaseRequestStatus::class => 'App\Enums\PurchaseRequestStatus',
    \App\Enums\Procurement\SupplierDeliveryStatus::class => 'App\Enums\SupplierDeliveryStatus',
    \App\Enums\Procurement\SupplierInvoiceStatus::class => 'App\Enums\SupplierInvoiceStatus',
    \App\Enums\Procurement\SupplierPaymentMethod::class => 'App\Enums\SupplierPaymentMethod',

    // Finance
    \App\Enums\Finance\PosShiftStatus::class => 'App\Enums\PosShiftStatus',
    \App\Enums\Finance\TreasuryTransactionType::class => 'App\Enums\TreasuryTransactionType',
    \App\Enums\Finance\TreasuryTransferStatus::class => 'App\Enums\TreasuryTransferStatus',
    \App\Enums\Finance\TreasuryType::class => 'App\Enums\TreasuryType',

    // Customer
    \App\Enums\Customer\CustomerDocumentStatus::class => 'App\Enums\CustomerDocumentStatus',
    \App\Enums\Customer\CustomerDocumentType::class => 'App\Enums\CustomerDocumentType',
    \App\Enums\Customer\CustomerStatus::class => 'App\Enums\CustomerStatus',
    \App\Enums\Customer\CustomerType::class => 'App\Enums\CustomerType',

    // Catalog
    \App\Enums\Catalog\BannerLinkType::class => 'App\Enums\BannerLinkType',
    \App\Enums\Catalog\BannerPosition::class => 'App\Enums\BannerPosition',
    \App\Enums\Catalog\Occasion::class => 'App\Enums\Occasion',
    \App\Enums\Catalog\PriceListType::class => 'App\Enums\PriceListType',
    \App\Enums\Catalog\ProductStatus::class => 'App\Enums\ProductStatus',

];

foreach ($aliases as $original => $alias) {
    if (! class_exists($alias, false) && ! enum_exists($alias, false)) {
        class_alias($original, $alias);
    }
}
