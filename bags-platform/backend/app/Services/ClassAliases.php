<?php

declare(strict_types=1);

// Backwards-compatibility aliases for services organized into domain subfolders.
$aliases = [
    // Wholesale
    \App\Services\Wholesale\QuotationService::class => 'App\Services\QuotationService',
    \App\Services\Wholesale\WholesalePaymentNoticeService::class => 'App\Services\WholesalePaymentNoticeService',
    \App\Services\Wholesale\WholesalePriceListService::class => 'App\Services\WholesalePriceListService',
    \App\Services\Wholesale\WholesaleQuickOrderService::class => 'App\Services\WholesaleQuickOrderService',
    \App\Services\Wholesale\WholesaleRegistrationService::class => 'App\Services\WholesaleRegistrationService',

    // Orders
    \App\Services\Orders\OrderService::class => 'App\Services\OrderService',
    \App\Services\Orders\OrderAllocationService::class => 'App\Services\OrderAllocationService',
    \App\Services\Orders\ReturnService::class => 'App\Services\ReturnService',
    \App\Services\Orders\CartService::class => 'App\Services\CartService',
    \App\Services\Orders\ShipmentService::class => 'App\Services\ShipmentService',
    \App\Services\Orders\ShippingQuoteService::class => 'App\Services\ShippingQuoteService',
    \App\Services\Orders\ShippingWebhookService::class => 'App\Services\ShippingWebhookService',

    // Inventory
    \App\Services\Inventory\InventoryService::class => 'App\Services\InventoryService',
    \App\Services\Inventory\TransferService::class => 'App\Services\TransferService',
    \App\Services\Inventory\StocktakeService::class => 'App\Services\StocktakeService',
    \App\Services\Inventory\BranchAccess::class => 'App\Services\BranchAccess',
    \App\Services\Inventory\ProductionReceiptService::class => 'App\Services\ProductionReceiptService',

    // Catalog
    \App\Services\Catalog\StoreCatalogService::class => 'App\Services\StoreCatalogService',
    \App\Services\Catalog\BannerService::class => 'App\Services\BannerService',
    \App\Services\Catalog\BannerLinkResolver::class => 'App\Services\BannerLinkResolver',
    \App\Services\Catalog\SlugService::class => 'App\Services\SlugService',
    \App\Services\Catalog\BarcodeGenerator::class => 'App\Services\BarcodeGenerator',

    // Pricing
    \App\Services\Pricing\PricingService::class => 'App\Services\PricingService',
    \App\Services\Pricing\PriceListService::class => 'App\Services\PriceListService',
    \App\Services\Pricing\CouponService::class => 'App\Services\CouponService',

    // Pos
    \App\Services\Pos\PosService::class => 'App\Services\PosService',
    \App\Services\Pos\TreasuryService::class => 'App\Services\TreasuryService',
    \App\Services\Pos\ExpenseService::class => 'App\Services\ExpenseService',
    \App\Services\Pos\StorePaymentPolicy::class => 'App\Services\StorePaymentPolicy',

    // Finance
    \App\Services\Finance\WalletService::class => 'App\Services\WalletService',
    \App\Services\Finance\WalletAdjustmentService::class => 'App\Services\WalletAdjustmentService',
    \App\Services\Finance\StatementBudget::class => 'App\Services\StatementBudget',
    \App\Services\Finance\ReportService::class => 'App\Services\ReportService',

    // Procurement
    \App\Services\Procurement\ProcurementService::class => 'App\Services\ProcurementService',
    \App\Services\Procurement\SupplierService::class => 'App\Services\SupplierService',
    \App\Services\Procurement\SupplierFinanceService::class => 'App\Services\SupplierFinanceService',

    // Customer
    \App\Services\Customer\CustomerGroupService::class => 'App\Services\CustomerGroupService',
    \App\Services\Customer\CustomerManagementService::class => 'App\Services\CustomerManagementService',
    \App\Services\Customer\CustomerPhoneVerificationService::class => 'App\Services\CustomerPhoneVerificationService',
    \App\Services\Customer\CustomerMessageBudget::class => 'App\Services\CustomerMessageBudget',

    // Auth
    \App\Services\Auth\CustomerAuthService::class => 'App\Services\CustomerAuthService',
    \App\Services\Auth\StaffAdministration::class => 'App\Services\StaffAdministration',

    // Shared
    \App\Services\Shared\OperationIdempotencyService::class => 'App\Services\OperationIdempotencyService',
    \App\Services\Shared\IdempotencyFingerprint::class => 'App\Services\IdempotencyFingerprint',
    \App\Services\Shared\PaymentWebhookService::class => 'App\Services\PaymentWebhookService',
    \App\Services\Shared\WebhookSignatureVerifier::class => 'App\Services\WebhookSignatureVerifier',
    \App\Services\Shared\SettingService::class => 'App\Services\SettingService',
    \App\Services\Shared\PrivateAttachments::class => 'App\Services\PrivateAttachments',
    \App\Services\Shared\DiscreteQuantity::class => 'App\Services\DiscreteQuantity',
    \App\Services\Shared\CostAccess::class => 'App\Services\CostAccess',
    \App\Services\Shared\SearchTerm::class => 'App\Services\SearchTerm',
    \App\Services\Shared\ProportionalDistribution::class => 'App\Services\ProportionalDistribution',
    \App\Services\Shared\SpokenQuantity::class => 'App\Services\SpokenQuantity',
];

foreach ($aliases as $original => $alias) {
    if (! class_exists($alias, false)) {
        class_alias($original, $alias);
    }
}
