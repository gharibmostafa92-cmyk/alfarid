<?php

declare(strict_types=1);

// Backwards-compatibility aliases for HTTP requests organized into domain subfolders.
$aliases = [
    // Catalog
    \App\Http\Requests\Admin\Catalog\StoreBrandRequest::class => 'App\Http\Requests\Admin\Brand\StoreBrandRequest',
    \App\Http\Requests\Admin\Catalog\UpdateBrandRequest::class => 'App\Http\Requests\Admin\Brand\UpdateBrandRequest',
    \App\Http\Requests\Admin\Catalog\ReorderCategoriesRequest::class => 'App\Http\Requests\Admin\Category\ReorderCategoriesRequest',
    \App\Http\Requests\Admin\Catalog\StoreCategoryRequest::class => 'App\Http\Requests\Admin\Category\StoreCategoryRequest',
    \App\Http\Requests\Admin\Catalog\UpdateCategoryRequest::class => 'App\Http\Requests\Admin\Category\UpdateCategoryRequest',
    \App\Http\Requests\Admin\Catalog\ReorderProductMediaRequest::class => 'App\Http\Requests\Admin\Product\ReorderProductMediaRequest',
    \App\Http\Requests\Admin\Catalog\StoreProductRequest::class => 'App\Http\Requests\Admin\Product\StoreProductRequest',
    \App\Http\Requests\Admin\Catalog\UpdateProductRequest::class => 'App\Http\Requests\Admin\Product\UpdateProductRequest',
    \App\Http\Requests\Admin\Catalog\UploadProductMediaRequest::class => 'App\Http\Requests\Admin\Product\UploadProductMediaRequest',
    \App\Http\Requests\Admin\Catalog\StoreVariantRequest::class => 'App\Http\Requests\Admin\Variant\StoreVariantRequest',
    \App\Http\Requests\Admin\Catalog\UpdateVariantRequest::class => 'App\Http\Requests\Admin\Variant\UpdateVariantRequest',
    \App\Http\Requests\Admin\Catalog\VariantRules::class => 'App\Http\Requests\Admin\Variant\VariantRules',
    \App\Http\Requests\Admin\Catalog\StoreBannerRequest::class => 'App\Http\Requests\Api\V1\Admin\StoreBannerRequest',
    \App\Http\Requests\Admin\Catalog\UpdateBannerRequest::class => 'App\Http\Requests\Api\V1\Admin\UpdateBannerRequest',

    // Inventory
    \App\Http\Requests\Admin\Inventory\StoreStocktakeRequest::class => 'App\Http\Requests\Admin\Stocktake\StoreStocktakeRequest',
    \App\Http\Requests\Admin\Inventory\UpdateStocktakeItemsRequest::class => 'App\Http\Requests\Admin\Stocktake\UpdateStocktakeItemsRequest',
    \App\Http\Requests\Admin\Inventory\UpdateStocktakeRequest::class => 'App\Http\Requests\Admin\Stocktake\UpdateStocktakeRequest',
    \App\Http\Requests\Admin\Inventory\ApproveTransferRequest::class => 'App\Http\Requests\Admin\Transfer\ApproveTransferRequest',
    \App\Http\Requests\Admin\Inventory\ReceiveTransferRequest::class => 'App\Http\Requests\Admin\Transfer\ReceiveTransferRequest',
    \App\Http\Requests\Admin\Inventory\ShipTransferRequest::class => 'App\Http\Requests\Admin\Transfer\ShipTransferRequest',
    \App\Http\Requests\Admin\Inventory\StoreTransferRequest::class => 'App\Http\Requests\Admin\Transfer\StoreTransferRequest',
    \App\Http\Requests\Admin\Inventory\TransferItemsQtyRequest::class => 'App\Http\Requests\Admin\Transfer\TransferItemsQtyRequest',
    \App\Http\Requests\Admin\Inventory\UpdateTransferRequest::class => 'App\Http\Requests\Admin\Transfer\UpdateTransferRequest',
    \App\Http\Requests\Admin\Inventory\StoreLocationRequest::class => 'App\Http\Requests\Api\V1\Admin\StoreLocationRequest',
    \App\Http\Requests\Admin\Inventory\UpdateLocationRequest::class => 'App\Http\Requests\Api\V1\Admin\UpdateLocationRequest',
    \App\Http\Requests\Admin\Inventory\StoreBranchRequest::class => 'App\Http\Requests\Api\V1\Admin\StoreBranchRequest',
    \App\Http\Requests\Admin\Inventory\UpdateBranchRequest::class => 'App\Http\Requests\Api\V1\Admin\UpdateBranchRequest',

    // Orders
    \App\Http\Requests\Admin\Orders\AllocateOrderRequest::class => 'App\Http\Requests\Admin\Order\AllocateOrderRequest',
    \App\Http\Requests\Admin\Orders\CreateShipmentRequest::class => 'App\Http\Requests\Admin\Order\CreateShipmentRequest',
    \App\Http\Requests\Admin\Orders\UpdateOrderPaymentRequest::class => 'App\Http\Requests\Admin\Order\UpdateOrderPaymentRequest',
    \App\Http\Requests\Admin\Orders\UpdateOrderStatusRequest::class => 'App\Http\Requests\Admin\Order\UpdateOrderStatusRequest',
    \App\Http\Requests\Admin\Orders\ApproveReturnRequest::class => 'App\Http\Requests\Admin\Return\ApproveReturnRequest',
    \App\Http\Requests\Admin\Orders\ReceiveReturnRequest::class => 'App\Http\Requests\Admin\Return\ReceiveReturnRequest',
    \App\Http\Requests\Admin\Orders\RefundReturnRequest::class => 'App\Http\Requests\Admin\Return\RefundReturnRequest',
    \App\Http\Requests\Admin\Orders\StoreReturnRequest::class => 'App\Http\Requests\Admin\Return\StoreReturnRequest',
    \App\Http\Requests\Admin\Orders\UpdateReturnRequest::class => 'App\Http\Requests\Admin\Return\UpdateReturnRequest',
    \App\Http\Requests\Admin\Orders\ShippingCompanyRequest::class => 'App\Http\Requests\Admin\Shipping\ShippingCompanyRequest',
    \App\Http\Requests\Admin\Orders\ShippingRateRequest::class => 'App\Http\Requests\Admin\Shipping\ShippingRateRequest',
    \App\Http\Requests\Admin\Orders\ShippingZoneRequest::class => 'App\Http\Requests\Admin\Shipping\ShippingZoneRequest',
    \App\Http\Requests\Admin\Orders\PriceQuotationRequest::class => 'App\Http\Requests\Admin\PriceQuotationRequest',
    \App\Http\Requests\Admin\Orders\ReviewPaymentNoticeRequest::class => 'App\Http\Requests\Admin\ReviewPaymentNoticeRequest',

    // Pricing
    \App\Http\Requests\Admin\Pricing\CouponRequest::class => 'App\Http\Requests\Admin\Promotion\CouponRequest',
    \App\Http\Requests\Admin\Pricing\PromotionRequest::class => 'App\Http\Requests\Admin\Promotion\PromotionRequest',

    // Procurement
    \App\Http\Requests\Admin\Procurement\StoreSupplierUserRequest::class => 'App\Http\Requests\Admin\Supplier\StoreSupplierUserRequest',
    \App\Http\Requests\Admin\Procurement\SupplierRequest::class => 'App\Http\Requests\Admin\Supplier\SupplierRequest',
    \App\Http\Requests\Admin\Procurement\UpdateSupplierUserRequest::class => 'App\Http\Requests\Admin\Supplier\UpdateSupplierUserRequest',

    // Auth / Staff
    \App\Http\Requests\Admin\Auth\AdminLoginRequest::class => 'App\Http\Requests\Api\V1\Admin\AdminLoginRequest',
    \App\Http\Requests\Admin\Auth\StoreRoleRequest::class => 'App\Http\Requests\Api\V1\Admin\StoreRoleRequest',
    \App\Http\Requests\Admin\Auth\StoreUserRequest::class => 'App\Http\Requests\Api\V1\Admin\StoreUserRequest',
    \App\Http\Requests\Admin\Auth\UpdateRoleRequest::class => 'App\Http\Requests\Api\V1\Admin\UpdateRoleRequest',
    \App\Http\Requests\Admin\Auth\UpdateUserRequest::class => 'App\Http\Requests\Api\V1\Admin\UpdateUserRequest',
    \App\Http\Requests\Admin\Auth\UpdateSettingsRequest::class => 'App\Http\Requests\Api\V1\Admin\UpdateSettingsRequest',

    // Supplier portal requests
    \App\Http\Requests\Supplier\StatementRequest::class => 'App\Http\Requests\Supplier\Account\StatementRequest',
    \App\Http\Requests\Supplier\LoginRequest::class => 'App\Http\Requests\Supplier\Auth\LoginRequest',
    \App\Http\Requests\Supplier\StoreDeliveryRequest::class => 'App\Http\Requests\Supplier\Delivery\StoreDeliveryRequest',
    \App\Http\Requests\Supplier\RequestChangeRequest::class => 'App\Http\Requests\Supplier\PurchaseOrder\RequestChangeRequest',
];

foreach ($aliases as $original => $alias) {
    if (! class_exists($alias, false)) {
        class_alias($original, $alias);
    }
}
