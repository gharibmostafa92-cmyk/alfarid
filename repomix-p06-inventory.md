This file is a merged representation of a subset of the codebase, containing specifically included files and files not matching ignore patterns, combined into a single document by Repomix.
The content has been processed where content has been formatted for parsing in markdown style.

# File Summary

## Purpose
This file contains a packed representation of a subset of the repository's contents that is considered the most important context.
It is designed to be easily consumable by AI systems for analysis, code review,
or other automated processes.

## File Format
The content is organized as follows:
1. This summary section
2. Repository information
3. Directory structure
4. Repository files (if enabled)
5. Multiple file entries, each consisting of:
  a. A header with the file path (## File: path/to/file)
  b. The full contents of the file in a code block

## Usage Guidelines
- This file should be treated as read-only. Any changes should be made to the
  original repository files, not this packed version.
- When processing this file, use the file path to distinguish
  between different files in the repository.
- Be aware that this file may contain sensitive information. Handle it with
  the same level of security as you would the original repository.
- Pay special attention to the Repository Description. These contain important context and guidelines specific to this project.

## Notes
- Some files may have been excluded based on .gitignore rules and Repomix's configuration
- Binary files are not included in this packed representation. Please refer to the Repository Structure section for a complete list of file paths, including binary files
- Only files matching these patterns are included: bags-platform/backend/app/Services/InventoryService.php, bags-platform/backend/app/Services/ProductionReceiptService.php, bags-platform/backend/app/Services/TransferService.php, bags-platform/backend/app/Services/StocktakeService.php, bags-platform/backend/app/Services/Concerns/HandlesIdempotentCreation.php, bags-platform/backend/app/Services/IdempotencyFingerprint.php, bags-platform/backend/app/Services/SpokenQuantity.php, bags-platform/backend/app/Services/BranchAccess.php, bags-platform/backend/app/Services/CostAccess.php, bags-platform/backend/app/Services/SearchTerm.php, bags-platform/backend/app/Models/StockLevel.php, bags-platform/backend/app/Models/StockMovement.php, bags-platform/backend/app/Models/ProductionReceipt.php, bags-platform/backend/app/Models/StockTransfer.php, bags-platform/backend/app/Models/StockTransferItem.php, bags-platform/backend/app/Models/Stocktake.php, bags-platform/backend/app/Models/StocktakeItem.php, bags-platform/backend/app/Models/StocktakeScan.php, bags-platform/backend/app/Models/InventoryIdempotencyKey.php, bags-platform/backend/app/Models/InventoryReconcileRun.php, bags-platform/backend/app/Models/ProductVariant.php, bags-platform/backend/app/Models/Branch.php, bags-platform/backend/app/Models/Location.php, bags-platform/backend/app/Enums/StockBucket.php, bags-platform/backend/app/Enums/StockMovementType.php, bags-platform/backend/app/Enums/TransferStatus.php, bags-platform/backend/app/Enums/StocktakeStatus.php, bags-platform/backend/app/Events/StockLowDetected.php, bags-platform/backend/app/Events/TransferPendingApproval.php, bags-platform/backend/app/Listeners/SendLowStockAlert.php, bags-platform/backend/app/Listeners/SendTransferPendingApproval.php, bags-platform/backend/app/Listeners/SuggestTransferRedistribution.php, bags-platform/backend/app/Notifications/LowStockAlertNotification.php, bags-platform/backend/app/Notifications/TransferPendingApprovalNotification.php, bags-platform/backend/app/Notifications/TransferSuggestionNotification.php, bags-platform/backend/app/Notifications/InventoryAnomalyDigestNotification.php, bags-platform/backend/app/Console/Commands/ReconcileInventory.php, bags-platform/backend/app/Console/Commands/PruneIdempotencyKeys.php, bags-platform/backend/app/Console/Commands/DetectInventoryAnomalies.php, bags-platform/backend/app/Http/Controllers/Api/V1/Admin/InventoryController.php, bags-platform/backend/app/Http/Controllers/Api/V1/Admin/ProductionReceiptController.php, bags-platform/backend/app/Http/Controllers/Api/V1/Admin/TransferController.php, bags-platform/backend/app/Http/Controllers/Api/V1/Admin/StocktakeController.php, bags-platform/backend/app/Http/Controllers/Api/V1/Pos/PosStockController.php, bags-platform/backend/app/Http/Requests/Admin/Inventory/StoreAdjustmentRequest.php, bags-platform/backend/app/Http/Requests/Admin/Inventory/StoreProductionReceiptRequest.php, bags-platform/backend/app/Http/Requests/Admin/Stocktake/StoreStocktakeRequest.php, bags-platform/backend/app/Http/Requests/Admin/Stocktake/UpdateStocktakeRequest.php, bags-platform/backend/app/Http/Requests/Admin/Stocktake/UpdateStocktakeItemsRequest.php, bags-platform/backend/app/Http/Requests/Admin/Transfer/StoreTransferRequest.php, bags-platform/backend/app/Http/Requests/Admin/Transfer/UpdateTransferRequest.php, bags-platform/backend/app/Http/Requests/Admin/Transfer/ApproveTransferRequest.php, bags-platform/backend/app/Http/Requests/Admin/Transfer/ShipTransferRequest.php, bags-platform/backend/app/Http/Requests/Admin/Transfer/ReceiveTransferRequest.php, bags-platform/backend/app/Http/Requests/Admin/Transfer/TransferItemsQtyRequest.php, bags-platform/backend/app/Http/Resources/Admin/StockLevelResource.php, bags-platform/backend/app/Http/Resources/Admin/StockMovementResource.php, bags-platform/backend/app/Http/Resources/Admin/StockTransferResource.php, bags-platform/backend/app/Http/Resources/Admin/StocktakeResource.php, bags-platform/backend/app/Exceptions/BusinessException.php, bags-platform/backend/app/Exceptions/RetryableConflict.php, bags-platform/backend/app/Observers/CatalogRetailPriceObserver.php, bags-platform/backend/database/migrations/2026_10_15_160001_create_stock_levels_table.php, bags-platform/backend/database/migrations/2026_10_15_160002_create_stock_movements_table.php, bags-platform/backend/database/migrations/2026_10_15_160003_create_stock_transfers_table.php, bags-platform/backend/database/migrations/2026_10_15_160004_create_stock_transfer_items_table.php, bags-platform/backend/database/migrations/2026_10_15_160005_create_stocktakes_table.php, bags-platform/backend/database/migrations/2026_10_15_160006_create_stocktake_items_table.php, bags-platform/backend/database/migrations/2026_10_15_190001_create_inventory_idempotency_keys_table.php, bags-platform/backend/database/migrations/2026_10_23_000001_create_production_receipts_table.php, bags-platform/backend/database/migrations/2026_11_03_000002_create_stocktake_scans_table.php, bags-platform/backend/database/migrations/2026_11_08_000001_create_inventory_reconcile_runs.php
- Files matching these patterns are excluded: **/tests/**, **/*Test.php, **/test/**, **/*test*.*
- Files matching patterns in .gitignore are excluded
- Files matching default ignore patterns are excluded
- Content has been formatted for parsing in markdown style
- Files are sorted by Git change count (files with more changes are at the bottom)

# User Provided Header
Phase 06: Inventory & Production Receipts [P06] — Core Source Files for Unit Testing (Excluding Existing Tests)

# Directory Structure
```
bags-platform/
  backend/
    app/
      Console/
        Commands/
          DetectInventoryAnomalies.php
          PruneIdempotencyKeys.php
          ReconcileInventory.php
      Enums/
        StockBucket.php
        StockMovementType.php
        StocktakeStatus.php
        TransferStatus.php
      Events/
        StockLowDetected.php
        TransferPendingApproval.php
      Exceptions/
        BusinessException.php
        RetryableConflict.php
      Http/
        Controllers/
          Api/
            V1/
              Admin/
                InventoryController.php
                ProductionReceiptController.php
                StocktakeController.php
                TransferController.php
              Pos/
                PosStockController.php
        Requests/
          Admin/
            Inventory/
              StoreAdjustmentRequest.php
              StoreProductionReceiptRequest.php
            Stocktake/
              StoreStocktakeRequest.php
              UpdateStocktakeItemsRequest.php
              UpdateStocktakeRequest.php
            Transfer/
              ApproveTransferRequest.php
              ReceiveTransferRequest.php
              ShipTransferRequest.php
              StoreTransferRequest.php
              TransferItemsQtyRequest.php
              UpdateTransferRequest.php
        Resources/
          Admin/
            StockLevelResource.php
            StockMovementResource.php
            StocktakeResource.php
            StockTransferResource.php
      Listeners/
        SendLowStockAlert.php
        SendTransferPendingApproval.php
        SuggestTransferRedistribution.php
      Models/
        Branch.php
        InventoryIdempotencyKey.php
        InventoryReconcileRun.php
        Location.php
        ProductionReceipt.php
        ProductVariant.php
        StockLevel.php
        StockMovement.php
        Stocktake.php
        StocktakeItem.php
        StocktakeScan.php
        StockTransfer.php
        StockTransferItem.php
      Notifications/
        InventoryAnomalyDigestNotification.php
        LowStockAlertNotification.php
        TransferPendingApprovalNotification.php
        TransferSuggestionNotification.php
      Observers/
        CatalogRetailPriceObserver.php
      Services/
        Concerns/
          HandlesIdempotentCreation.php
        BranchAccess.php
        CostAccess.php
        IdempotencyFingerprint.php
        InventoryService.php
        ProductionReceiptService.php
        SearchTerm.php
        SpokenQuantity.php
        StocktakeService.php
        TransferService.php
    database/
      migrations/
        2026_10_15_160001_create_stock_levels_table.php
        2026_10_15_160002_create_stock_movements_table.php
        2026_10_15_160003_create_stock_transfers_table.php
        2026_10_15_160004_create_stock_transfer_items_table.php
        2026_10_15_160005_create_stocktakes_table.php
        2026_10_15_160006_create_stocktake_items_table.php
        2026_10_15_190001_create_inventory_idempotency_keys_table.php
        2026_10_23_000001_create_production_receipts_table.php
        2026_11_03_000002_create_stocktake_scans_table.php
        2026_11_08_000001_create_inventory_reconcile_runs.php
```

# Files

## File: bags-platform/backend/app/Console/Commands/PruneIdempotencyKeys.php
```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\InventoryIdempotencyKey;
use Illuminate\Console\Command;

/**
 * تنظيف مفاتيح عدم التكرار القديمة (L-2): المفتاح وظيفته تغطية نافذة إعادة
 * المحاولة، فالاحتفاظ به أكثر من 7 أيام تراكم بلا فائدة. يُجدول يوميًا.
 */
class PruneIdempotencyKeys extends Command
{
    protected $signature = 'inventory:prune-idempotency-keys';

    protected $description = 'حذف مفاتيح عدم تكرار المخزون الأقدم من 7 أيام';

    public function handle(): int
    {
        $deleted = 0;
        $cutoff = now()->subDays(7);
        do {
            $ids = InventoryIdempotencyKey::query()->where('created_at', '<', $cutoff)
                ->orderBy('id')->limit(1000)->pluck('id');
            $deleted += InventoryIdempotencyKey::query()->whereIn('id', $ids)->delete();
        } while ($ids->count() === 1000);

        $this->info("تم حذف {$deleted} مفتاح عدم تكرار قديم.");

        return self::SUCCESS;
    }
}
```

## File: bags-platform/backend/app/Enums/StockBucket.php
```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum StockBucket: string
{
    case OnHand = 'on_hand';
    case Reserved = 'reserved';
    case Damaged = 'damaged';
    case InTransit = 'in_transit';
}
```

## File: bags-platform/backend/app/Enums/StocktakeStatus.php
```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum StocktakeStatus: string
{
    case Draft = 'draft';
    case InProgress = 'in_progress';
    case Approved = 'approved';
    case Cancelled = 'cancelled';
}
```

## File: bags-platform/backend/app/Enums/TransferStatus.php
```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum TransferStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Shipped = 'shipped';
    case Received = 'received';
    case Cancelled = 'cancelled';
}
```

## File: bags-platform/backend/app/Http/Requests/Admin/Inventory/StoreAdjustmentRequest.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Inventory;

use App\Http\Requests\Admin\Concerns\BulkVariantExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAdjustmentRequest extends FormRequest
{
    use BulkVariantExists;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'bail', 'integer', 'min:1', Rule::exists('branches', 'id')->where('is_active', true)],
            'location_id' => [
                'nullable', 'bail', 'integer', 'min:1',
                Rule::exists('locations', 'id')->where('branch_id', filter_var($this->input('branch_id'), FILTER_VALIDATE_INT) ?: 0),
            ],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_variant_id' => ['required', 'bail', 'integer', 'min:1'],
            'items.*.adjustment_type' => ['required', Rule::in(['increase', 'decrease', 'mark_damaged', 'write_off_damaged', 'restore_damaged'])],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:99999999.9999'],
            'items.*.reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->addBulkVariantExistsCheck($validator);
    }
}
```

## File: bags-platform/backend/app/Http/Requests/Admin/Stocktake/StoreStocktakeRequest.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Stocktake;

use App\Http\Requests\Admin\Concerns\BulkVariantExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreStocktakeRequest extends FormRequest
{
    use BulkVariantExists;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'bail', 'integer', 'min:1', Rule::exists('branches', 'id')->where('is_active', true)],
            'location_id' => [
                'nullable', 'bail', 'integer', 'min:1',
                Rule::exists('locations', 'id')->where('branch_id', filter_var($this->input('branch_id'), FILTER_VALIDATE_INT) ?: 0),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['nullable', 'array', 'max:500'],
            'items.*.product_variant_id' => [
                'required_with:items', 'bail', 'integer', 'min:1', 'distinct',
            ],
            'items.*.counted_qty' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:99999999.9999'],
            'items.*.recount' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->addBulkVariantExistsCheck($validator);
    }
}
```

## File: bags-platform/backend/app/Http/Requests/Admin/Stocktake/UpdateStocktakeItemsRequest.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Stocktake;

use App\Http\Requests\Admin\Concerns\BulkVariantExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * تحديث جزئي لعناصر جرد (M-4): العناصر المرسلة تُحدَّث/تُنشأ فقط، والغائبة
 * تبقى كما هي — بعكس PUT الكامل الذي يستبدل القائمة كلها.
 */
class UpdateStocktakeItemsRequest extends FormRequest
{
    use BulkVariantExists;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.product_variant_id' => ['required', 'bail', 'integer', 'min:1', 'distinct'],
            'items.*.counted_qty' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:99999999.9999'],
            'items.*.recount' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->addBulkVariantExistsCheck($validator);
    }
}
```

## File: bags-platform/backend/app/Http/Requests/Admin/Stocktake/UpdateStocktakeRequest.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Stocktake;

use App\Enums\StocktakeStatus;
use App\Http\Requests\Admin\Concerns\BulkVariantExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateStocktakeRequest extends FormRequest
{
    use BulkVariantExists;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $branchId = filter_var($this->input('branch_id', $this->route('stocktake')?->branch_id), FILTER_VALIDATE_INT) ?: 0;

        return [
            'branch_id' => ['sometimes', 'bail', 'integer', 'min:1', Rule::exists('branches', 'id')->where('is_active', true)],
            'location_id' => [
                'sometimes', 'nullable', 'bail', 'integer', 'min:1',
                Rule::exists('locations', 'id')->where('branch_id', $branchId),
            ],
            'status' => ['sometimes', Rule::in([StocktakeStatus::InProgress->value, StocktakeStatus::Cancelled->value])],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'items' => ['sometimes', 'array', 'max:500'],
            'items.*.product_variant_id' => [
                'required_with:items', 'bail', 'integer', 'min:1', 'distinct',
            ],
            'items.*.counted_qty' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:99999999.9999'],
            'items.*.recount' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->addBulkVariantExistsCheck($validator);
    }
}
```

## File: bags-platform/backend/app/Http/Requests/Admin/Transfer/ApproveTransferRequest.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Transfer;

class ApproveTransferRequest extends TransferItemsQtyRequest
{
    protected string $qtyField = 'approved_qty';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // M3: approving unseen edits is a real hazard — the approver must
        // name the version they reviewed.
        return array_merge(parent::rules(), [
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);
    }
}
```

## File: bags-platform/backend/app/Http/Requests/Admin/Transfer/ReceiveTransferRequest.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Transfer;

class ReceiveTransferRequest extends TransferItemsQtyRequest
{
    protected string $qtyField = 'received_qty';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // M3: see ApproveTransferRequest.
        return array_merge(parent::rules(), [
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);
    }
}
```

## File: bags-platform/backend/app/Http/Requests/Admin/Transfer/ShipTransferRequest.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Transfer;

class ShipTransferRequest extends TransferItemsQtyRequest
{
    protected string $qtyField = 'shipped_qty';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // M3: see ApproveTransferRequest.
        return array_merge(parent::rules(), [
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);
    }
}
```

## File: bags-platform/backend/app/Http/Requests/Admin/Transfer/StoreTransferRequest.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Transfer;

use App\Http\Requests\Admin\Concerns\BulkVariantExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTransferRequest extends FormRequest
{
    use BulkVariantExists;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from_branch_id' => ['required', 'bail', 'integer', 'min:1', Rule::exists('branches', 'id')->where('is_active', true)],
            'to_branch_id' => ['required', 'bail', 'integer', 'min:1', Rule::exists('branches', 'id')->where('is_active', true), 'different:from_branch_id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_variant_id' => ['required', 'bail', 'integer', 'min:1'],
            'items.*.from_location_id' => [
                'nullable', 'bail', 'integer', 'min:1',
                Rule::exists('locations', 'id')->where('branch_id', filter_var($this->input('from_branch_id'), FILTER_VALIDATE_INT) ?: 0),
            ],
            'items.*.to_location_id' => [
                'nullable', 'bail', 'integer', 'min:1',
                Rule::exists('locations', 'id')->where('branch_id', filter_var($this->input('to_branch_id'), FILTER_VALIDATE_INT) ?: 0),
            ],
            'items.*.requested_qty' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:99999999.9999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->addBulkVariantExistsCheck($validator);
    }
}
```

## File: bags-platform/backend/app/Http/Requests/Admin/Transfer/UpdateTransferRequest.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Transfer;

use App\Http\Requests\Admin\Concerns\BulkVariantExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateTransferRequest extends FormRequest
{
    use BulkVariantExists;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $fromBranchId = filter_var($this->input('from_branch_id', $this->route('transfer')?->from_branch_id), FILTER_VALIDATE_INT) ?: 0;
        $toBranchId = filter_var($this->input('to_branch_id', $this->route('transfer')?->to_branch_id), FILTER_VALIDATE_INT) ?: 0;

        return [
            'from_branch_id' => ['sometimes', 'bail', 'integer', 'min:1', Rule::exists('branches', 'id')->where('is_active', true)],
            'to_branch_id' => ['sometimes', 'bail', 'integer', 'min:1', Rule::exists('branches', 'id')->where('is_active', true)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'items' => ['sometimes', 'array', 'min:1', 'max:100'],
            'items.*.product_variant_id' => ['required_with:items', 'bail', 'integer', 'min:1'],
            'items.*.from_location_id' => [
                'nullable', 'bail', 'integer', 'min:1',
                Rule::exists('locations', 'id')->where('branch_id', $fromBranchId),
            ],
            'items.*.to_location_id' => [
                'nullable', 'bail', 'integer', 'min:1',
                Rule::exists('locations', 'id')->where('branch_id', $toBranchId),
            ],
            'items.*.requested_qty' => ['required_with:items', 'numeric', 'decimal:0,4', 'gt:0', 'max:99999999.9999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->addBulkVariantExistsCheck($validator);
    }
}
```

## File: bags-platform/backend/app/Http/Resources/Admin/StockLevelResource.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockLevelResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_variant_id' => $this->product_variant_id,
            'variant' => $this->whenLoaded('variant', fn () => [
                'id' => $this->variant->id,
                'sku' => $this->variant->sku,
                'barcode' => $this->variant->barcode,
                'product_id' => $this->variant->product_id,
                'product_name_ar' => $this->variant->relationLoaded('product') ? $this->variant->product?->name_ar : null,
                'product_name_en' => $this->variant->relationLoaded('product') ? $this->variant->product?->name_en : null,
            ]),
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ]),
            'location' => $this->whenLoaded('location', fn () => $this->location ? [
                'id' => $this->location->id,
                'name' => $this->location->name,
                'code' => $this->location->code,
            ] : null),
            'on_hand' => $this->on_hand,
            'reserved' => $this->reserved,
            'damaged' => $this->damaged,
            'in_transit' => $this->in_transit,
            'available' => number_format($this->available, 4, '.', ''),
            // حد إعادة الطلب الفعّال: مستوى الصف إن ضُبط (> 0)، وإلا مستوى المتغير
            'reorder_level' => $this->effectiveReorderLevel(),
            // إشارة تشغيلية على مستوى الصف (L-4: صف بلا حد فعّال ليس "منخفضًا").
            // فلتر low_stock في القوائم أوسع: يطابق حالة التنبيه
            // (حد الصنف يُقارن بإجمالي الفرع) — راجع StockLevel::whereLowStock.
            'is_low_stock' => $this->effectiveReorderLevel() > 0 && $this->available <= $this->effectiveReorderLevel(),
            'updated_at' => $this->updated_at,
        ];
    }

    /** حد إعادة الطلب الفعّال (نفس صيغة فلتر low_stock في الكنترولر). */
    private function effectiveReorderLevel(): float
    {
        if ((float) $this->reorder_level > 0) {
            return (float) $this->reorder_level;
        }

        return $this->relationLoaded('variant') ? (float) ($this->variant?->reorder_level ?? 0) : 0.0;
    }
}
```

## File: bags-platform/backend/app/Http/Resources/Admin/StockTransferResource.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockTransferResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'transfer_number' => $this->transfer_number,
            'from_branch' => $this->whenLoaded('fromBranch', fn () => [
                'id' => $this->fromBranch->id,
                'name' => $this->fromBranch->name,
            ]),
            'to_branch' => $this->whenLoaded('toBranch', fn () => [
                'id' => $this->toBranch->id,
                'name' => $this->toBranch->name,
            ]),
            'status' => $this->status,
            'created_by' => $this->created_by,
            'creator_name' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'approved_by' => $this->approved_by,
            'shipped_at' => $this->shipped_at,
            'received_at' => $this->received_at,
            'notes' => $this->notes,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'product_variant_id' => $item->product_variant_id,
                'sku' => $item->relationLoaded('variant') ? $item->variant?->sku : null,
                'from_location' => $item->relationLoaded('fromLocation') && $item->fromLocation ? [
                    'id' => $item->fromLocation->id, 'name' => $item->fromLocation->name,
                ] : null,
                'to_location' => $item->relationLoaded('toLocation') && $item->toLocation ? [
                    'id' => $item->toLocation->id, 'name' => $item->toLocation->name,
                ] : null,
                'requested_qty' => $item->requested_qty,
                'approved_qty' => $item->approved_qty,
                'shipped_qty' => $item->shipped_qty,
                'received_qty' => $item->received_qty,
            ])->all()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
```

## File: bags-platform/backend/app/Models/InventoryIdempotencyKey.php
```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** مفتاح عدم تكرار لطلب تسوية يدوية — يُنشأ ويُستهلك داخل InventoryService فقط. */
class InventoryIdempotencyKey extends Model
{
    protected $fillable = ['user_id', 'key', 'request_hash', 'response', 'status_code'];

    protected function casts(): array
    {
        return [
            'response' => 'array',
            'status_code' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

## File: bags-platform/backend/app/Models/Location.php
```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LocationType;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['branch_id', 'name', 'code', 'type', 'is_active'])]
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use HasFactory;

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LocationType::class,
            'is_active' => 'boolean',
        ];
    }
}
```

## File: bags-platform/backend/app/Models/StockMovement.php
```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * سجل دفتر أستاذ المخزون: تاريخي وغير قابل للتعديل أو الحذف نهائيًا (Immutable Ledger).
 */
class StockMovement extends Model
{
    /** لا يوجد updated_at: السجل لا يتغير بعد كتابته. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'reference_number', 'product_variant_id', 'branch_id', 'location_id',
        'type', 'affected_bucket', 'quantity', 'balance_before', 'balance_after',
        'unit_cost', 'reference_type', 'reference_id', 'created_by', 'notes',
    ];

    protected $casts = [
        'type' => StockMovementType::class,
        'affected_bucket' => StockBucket::class,
        'quantity' => 'decimal:4',
        'balance_before' => 'decimal:4',
        'balance_after' => 'decimal:4',
        'unit_cost' => 'decimal:4',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('سجلات دفتر المخزون لا تُعدَّل'));
        static::deleting(fn () => throw new LogicException('سجلات دفتر المخزون لا تُحذف'));
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
```

## File: bags-platform/backend/app/Models/Stocktake.php
```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StocktakeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Stocktake extends Model
{
    public function scans(): HasMany
    {
        return $this->hasMany(StocktakeScan::class);
    }

    protected $fillable = [
        'stocktake_number', 'branch_id', 'location_id', 'status',
        'created_by', 'approved_by', 'approved_at', 'notes',
    ];

    protected $casts = [
        'status' => StocktakeStatus::class,
        'approved_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(StocktakeItem::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
```

## File: bags-platform/backend/app/Models/StocktakeItem.php
```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StocktakeItem extends Model
{
    protected $fillable = [
        'stocktake_id', 'product_variant_id', 'system_qty', 'counted_qty', 'snapshot_movement_id',
    ];

    protected $casts = [
        'snapshot_movement_id' => 'integer',
        'system_qty' => 'decimal:4',
        'counted_qty' => 'decimal:4',
        'difference' => 'decimal:4',
    ];

    public function stocktake(): BelongsTo
    {
        return $this->belongsTo(Stocktake::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }
}
```

## File: bags-platform/backend/app/Models/StocktakeScan.php
```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StocktakeScan extends Model
{
    protected $fillable = [
        'stocktake_id', 'scan_id', 'product_variant_id', 'qty', 'created_by',
    ];

    protected $casts = [
        'qty' => 'float',
    ];

    public function stocktake(): BelongsTo
    {
        return $this->belongsTo(Stocktake::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
```

## File: bags-platform/backend/app/Models/StockTransferItem.php
```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferItem extends Model
{
    protected $fillable = [
        'stock_transfer_id', 'product_variant_id', 'from_location_id', 'to_location_id',
        'requested_qty', 'approved_qty', 'shipped_qty', 'received_qty',
    ];

    protected $casts = [
        'requested_qty' => 'decimal:4',
        'approved_qty' => 'decimal:4',
        'shipped_qty' => 'decimal:4',
        'received_qty' => 'decimal:4',
    ];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }
}
```

## File: bags-platform/backend/database/migrations/2026_10_15_160001_create_stock_levels_table.php
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_levels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('on_hand', 12, 4)->default(0);
            $table->decimal('reserved', 12, 4)->default(0);
            $table->decimal('damaged', 12, 4)->default(0);
            $table->decimal('in_transit', 12, 4)->default(0);
            $table->decimal('reorder_level', 12, 4)->default(0);
            $table->decimal('available', 12, 4)->storedAs('on_hand - reserved - damaged');
            $table->timestamps();

            $table->index('product_variant_id');
            $table->index('branch_id');
            $table->index('location_id');
        });

        // قيد فريد مركب: ‏NULLS NOT DISTINCT عشان صفوف "من غير موقع" متتكررش (PostgreSQL 15+)
        DB::statement('CREATE UNIQUE INDEX stock_levels_variant_branch_location_unique
            ON stock_levels (product_variant_id, branch_id, location_id) NULLS NOT DISTINCT');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_levels');
    }
};
```

## File: bags-platform/backend/database/migrations/2026_10_15_160002_create_stock_movements_table.php
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            // الأعمدة التالية nullable/defaulted عشان عقد اختبارات الفروع/المواقع من المرحلة 1
            // (إدخال صف بحقول branch_id/location_id فقط). الاكتمال مضمون تطبيقيًا لأن
            // الكتابة الوحيدة للدفتر بتتم عبر InventoryService اللي بيملأ كل الحقول دائمًا.
            $table->string('reference_number')->nullable()->unique();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 32)->nullable();
            $table->string('affected_bucket', 16)->nullable();
            $table->decimal('quantity', 12, 4)->default(0);
            $table->decimal('balance_before', 12, 4)->default(0);
            $table->decimal('balance_after', 12, 4)->default(0);
            $table->decimal('unit_cost', 12, 4)->nullable();
            $table->nullableMorphs('reference');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('product_variant_id');
            $table->index('branch_id');
            $table->index('type');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
```

## File: bags-platform/backend/database/migrations/2026_10_15_160003_create_stock_transfers_table.php
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table): void {
            $table->id();
            $table->string('transfer_number')->unique();
            $table->foreignId('from_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('to_branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('status', 16)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('from_branch_id');
            $table->index('to_branch_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfers');
    }
};
```

## File: bags-platform/backend/database/migrations/2026_10_15_160004_create_stock_transfer_items_table.php
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfer_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('from_location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->foreignId('to_location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->decimal('requested_qty', 12, 4);
            $table->decimal('approved_qty', 12, 4)->default(0);
            $table->decimal('shipped_qty', 12, 4)->default(0);
            $table->decimal('received_qty', 12, 4)->default(0);
            $table->timestamps();

            $table->index('stock_transfer_id');
            $table->index('product_variant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
    }
};
```

## File: bags-platform/backend/database/migrations/2026_10_15_160005_create_stocktakes_table.php
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocktakes', function (Blueprint $table): void {
            $table->id();
            $table->string('stocktake_number')->unique();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('branch_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocktakes');
    }
};
```

## File: bags-platform/backend/database/migrations/2026_10_15_160006_create_stocktake_items_table.php
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocktake_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stocktake_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('system_qty', 12, 4)->default(0);
            $table->decimal('counted_qty', 12, 4)->nullable();
            $table->decimal('difference', 12, 4)->nullable()->storedAs('counted_qty - system_qty');
            $table->timestamps();

            $table->index('stocktake_id');
            $table->index('product_variant_id');
            $table->unique(['stocktake_id', 'product_variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocktake_items');
    }
};
```

## File: bags-platform/backend/database/migrations/2026_10_15_190001_create_inventory_idempotency_keys_table.php
```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مفاتيح عدم التكرار (Idempotency) لطلبات التسويات اليدوية: إعادة إرسال نفس
 * الطلب (retry شبكة/ضغط مزدوج) تعيد الرد المحفوظ بدل تنفيذ الحركات مرتين،
 * ونفس المفتاح بحمولة مختلفة يُرفض 409. الإدراج داخل transaction التسوية نفسها،
 * ففشل الطلب يلغي المفتاح معه وتظل إعادة المحاولة ممكنة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 128);
            $table->char('request_hash', 64);
            $table->json('response')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_idempotency_keys');
    }
};
```

## File: bags-platform/backend/database/migrations/2026_11_03_000002_create_stocktake_scans_table.php
```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H2: exactly-once POS stocktake scans. (stocktake_id, scan_id) is unique so
 * a retried HTTP request after a timeout cannot double-count. Rows are
 * deleted when the session is approved or cancelled (FK cascade on delete).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocktake_scans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stocktake_id')->constrained('stocktakes')->cascadeOnDelete();
            $table->uuid('scan_id');
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('qty', 12, 4);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['stocktake_id', 'scan_id'], 'stocktake_scans_unique_scan');
            $table->index(['stocktake_id', 'product_variant_id'], 'stocktake_scans_session_variant_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocktake_scans');
    }
};
```

## File: bags-platform/backend/app/Console/Commands/DetectInventoryAnomalies.php
```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\StockMovementType;
use App\Models\Stocktake;
use App\Notifications\InventoryAnomalyDigestNotification;
use App\Notifications\StaffRecipients;
use App\Services\BranchAccess;
use App\Services\SettingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * 5.3: كشف الأنماط الغريبة أسبوعيًا — ملاحظات للمراجعة تظهر للي معاه
 * ai_agent.logs.view بس، مش اتهامات.
 */
class DetectInventoryAnomalies extends Command
{
    protected $signature = 'inventory:detect-anomalies';

    protected $description = 'Flag unusual inventory patterns for review (adjustment rates, repeat shortages).';

    public function handle(SettingService $settings): int
    {
        // entry point للتشخيص: المجدول يعمل بلا tty، والتشغيل اليدوي بـ tty.
        $source = function_exists('posix_isatty') && @posix_isatty(STDOUT) ? 'cli-manual' : 'scheduler';
        logger()->info('inventory.anomalies.run', ['entry_point' => $source]);
        if (! $settings->getBool('ai_inventory_proactive_enabled', false)) {
            $this->info('Proactive inventory engine is disabled.');

            return self::SUCCESS;
        }

        $notes = [];
        $since = now()->subDays(30);

        // مستخدم نسبة تسويات الخصم بتاعته أعلى من غيره بكتير.
        $rates = DB::table('stock_movements')
            ->selectRaw('created_by, COUNT(*) AS c, COALESCE(json_agg(DISTINCT branch_id), \'[]\') AS branches')
            ->where('created_at', '>=', $since)
            ->whereIn('type', [StockMovementType::AdjustmentOut->value, StockMovementType::DamageWriteOff->value])
            ->whereNotNull('created_by')
            ->groupBy('created_by')
            ->havingRaw('COUNT(*) >= 5')
            ->orderByDesc('c')->get();
        if ($rates->count() >= 3) {
            $sorted = $rates->pluck('c')->sort()->values();
            $median = (float) $sorted[(int) floor($sorted->count() / 2)];
            foreach ($rates as $row) {
                if ($median > 0 && (float) $row->c > $median * 3) {
                    $notes[] = [
                        'title' => 'معدل تسويات خصم مرتفع',
                        'detail' => "المستخدم #{$row->created_by} سجّل {$row->c} تسوية خصم آخر 30 يوم (الوسيط {$median}). راجع الأسباب قبل أي استنتاج.",
                        'branch_ids' => array_values(array_filter(array_map(
                            'intval',
                            json_decode((string) $row->branches, true) ?? []
                        ))),
                    ];
                }
            }
        }

        // موقع العجز بيتكرر فيه (جرود معتمدة آخر 90 يوم).
        $locs = DB::table('stocktake_items as si')
            ->join('stocktakes as s', 's.id', '=', 'si.stocktake_id')
            ->join('locations as loc', 'loc.id', '=', 's.location_id')
            ->selectRaw('s.location_id, loc.branch_id, COUNT(*) AS c')
            ->where('s.status', 'approved')
            ->where('s.created_at', '>=', now()->subDays(90))
            ->whereNotNull('s.location_id')
            ->whereRaw('(si.counted_qty - si.system_qty) < 0')
            ->groupBy('s.location_id', 'loc.branch_id')
            ->havingRaw('COUNT(*) >= 3')
            ->orderByDesc('c')->limit(5)->get();
        foreach ($locs as $row) {
            $notes[] = [
                'title' => 'عجز متكرر في موقع',
                'detail' => "الموقع #{$row->location_id} ظهر فيه عجز {$row->c} مرات آخر 90 يوم. احتمال خطأ ترتيب أو تسجيل.",
                'branch_ids' => [(int) $row->branch_id],
            ];
        }

        // استلامات تحويل بعجز متكرر من نفس المصدر.
        $srcs = DB::table('stock_movements')
            ->selectRaw('branch_id, COUNT(*) AS c')
            ->where('type', StockMovementType::TransferShortage->value)
            ->where('created_at', '>=', now()->subDays(90))
            ->groupBy('branch_id')
            ->havingRaw('COUNT(*) >= 3')
            ->orderByDesc('c')->limit(5)->get();
        foreach ($srcs as $row) {
            $notes[] = [
                'title' => 'عجز استلام متكرر',
                'detail' => "الفرع #{$row->branch_id} سجّل عجز استلام {$row->c} مرات آخر 90 يوم. راجع التغليف والعد عند الشحن.",
                'branch_ids' => [(int) $row->branch_id],
            ];
        }

        if ($notes === []) {
            $this->info('No unusual patterns detected.');

            return self::SUCCESS;
        }

        // L8: anomaly notes name specific branches — route each note to its
        // branch's holders only. Central holders (no branch restriction) get
        // the full digest; branch holders never see other branches' notes.
        $recipients = StaffRecipients::withAnyPermission(['ai_agent.logs.view']);
        if ($recipients->isEmpty()) {
            $this->warn('Anomalies found but nobody holds ai_agent.logs.view.');

            return self::SUCCESS;
        }

        $central = collect();
        $perBranch = [];
        foreach ($recipients as $recipient) {
            try {
                $scope = app(BranchAccess::class)->restrictedBranch($recipient);
            } catch (AuthorizationException) {
                // Unscoped holder (no branch, no all-branches grant): keep the
                // previous global visibility instead of dropping them.
                $scope = null;
            }
            if ($scope === null) {
                $central->push($recipient);
            } else {
                $perBranch[$scope] ??= collect();
                $perBranch[$scope]->push($recipient);
            }
        }

        $visible = static fn (array $note): array => ['title' => $note['title'], 'detail' => $note['detail']];
        $sent = 0;
        if ($central->isNotEmpty()) {
            Notification::sendNow($central, new InventoryAnomalyDigestNotification(array_map($visible, $notes)), ['database']);
            $sent += $central->count();
        }
        foreach ($perBranch as $branchId => $holders) {
            $scoped = array_values(array_filter(
                $notes,
                static fn (array $note): bool => in_array($branchId, $note['branch_ids'] ?? [], true)
            ));
            if ($scoped === []) {
                continue;
            }
            Notification::sendNow($holders, new InventoryAnomalyDigestNotification(array_map($visible, $scoped)), ['database']);
            $sent += $holders->count();
        }
        $this->info('Flagged '.count($notes).' notes for review ('.$sent.' deliveries).');

        return self::SUCCESS;
    }
}
```

## File: bags-platform/backend/app/Enums/StockMovementType.php
```php
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
```

## File: bags-platform/backend/app/Exceptions/BusinessException.php
```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

// الاستثناء اللي بنرميه لما قاعدة من قواعد البيزنس تتكسر، وبيرجّع 422.
class BusinessException extends Exception
{
    public function __construct(
        string $message,
        public array $errors = [],
        public int $status = 422,
        public ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        $payload = ['message' => $this->getMessage(), 'errors' => $this->errors];
        if ($this->errorCode !== null) {
            // رمز خطأ مميز تتصرف الواجهة على أساسه (مثل coupon_invalid)
            $payload['code'] = $this->errorCode;
        }

        $response = response()->json($payload, $this->status);
        if ($this->status === 503) {
            $response->headers->set('Retry-After', '1');
        }

        return $response;
    }
}
```

## File: bags-platform/backend/app/Exceptions/RetryableConflict.php
```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A 409 that is safe to retry: the row was busy with another transaction
 * (lock timeout / deadlock / serialization failure), nothing was written.
 * Unlike other 409s it must never flip an action to failed.
 */
final class RetryableConflict extends ConflictHttpException {}
```

## File: bags-platform/backend/app/Http/Requests/Admin/Transfer/TransferItemsQtyRequest.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Transfer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * طلبات مراحل التحويل (اعتماد/شحن/استلام): كميات اختيارية لكل عنصر،
 * وغياب عنصر يعني استخدام الكمية الافتراضية للمرحلة السابقة.
 */
class TransferItemsQtyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** حقل الكمية الخاص بالمرحلة الحالية — بيتحدد في الكلاس الفرعي. */
    protected string $qtyField = 'approved_qty';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $transferId = $this->route('transfer')?->id;

        return [
            'expected_version' => ['nullable', 'integer', 'min:1'],
            'expected_updated_at' => ['nullable', 'date'],
            'items' => ['nullable', 'array', 'max:100'],
            'items.*.id' => [
                'required', 'bail', 'integer', 'min:1', 'distinct',
                Rule::exists('stock_transfer_items', 'id')->where('stock_transfer_id', $transferId),
            ],
            "items.*.{$this->qtyField}" => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:99999999.9999'],
        ];
    }

    /**
     * خريطة [معرّف العنصر => الكمية] جاهزة للخدمة.
     *
     * @return array<int, float>
     */
    public function quantitiesByItemId(): array
    {
        $map = [];

        foreach ($this->validated('items', []) ?? [] as $item) {
            $map[(int) $item['id']] = (float) $item[$this->qtyField];
        }

        return $map;
    }

    public function expectedVersion(): ?int
    {
        return $this->filled('expected_version') ? (int) $this->validated('expected_version') : null;
    }

    public function expectedUpdatedAt(): ?string
    {
        return $this->validated('expected_updated_at');
    }
}
```

## File: bags-platform/backend/app/Http/Resources/Admin/StocktakeResource.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Enums\StocktakeStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StocktakeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'stocktake_number' => $this->stocktake_number,
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ]),
            'location' => $this->whenLoaded('location', fn () => $this->location ? [
                'id' => $this->location->id,
                'name' => $this->location->name,
            ] : null),
            'status' => $this->status,
            'items_count' => $this->whenCounted('items'),
            'created_by' => $this->created_by,
            'creator_name' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at,
            'notes' => $this->notes,
            // M6: الجرد "الأعمى" — الرصيد الدفتري والفرق للمعتمِد فقط (أو بعد
            // الاعتماد)، فمن يعدّ لا يرى ما يُخفي به العجز.
            'items' => $this->whenLoaded('items', function () use ($request) {
                $reveal = $this->status === StocktakeStatus::Approved
                    || $request->user()?->can('stocktakes.approve');

                return $this->items->map(function ($item) use ($reveal) {
                    $row = [
                        'id' => $item->id,
                        'product_variant_id' => $item->product_variant_id,
                        'sku' => $item->relationLoaded('variant') ? $item->variant?->sku : null,
                    ];
                    if ($reveal) {
                        $row['system_qty'] = $item->system_qty;
                    }
                    $row['counted_qty'] = $item->counted_qty;
                    if ($reveal) {
                        $row['difference'] = $item->difference;
                    }

                    return $row;
                })->all();
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
```

## File: bags-platform/backend/app/Listeners/SuggestTransferRedistribution.php
```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\StockLowDetected;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Notifications\StaffRecipients;
use App\Notifications\TransferSuggestionNotification;
use App\Services\Ai\Inventory\InventoryCoverage;
use App\Services\SettingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * 5.1: اقتراح إعادة التوزيع الاستباقي — من غير توكنز (محسوب في PHP بالكامل).
 * يعمل بشرط ai_inventory_proactive_enabled.
 */
class SuggestTransferRedistribution implements ShouldQueue
{
    public function handle(StockLowDetected $event): void
    {
        if (! app(SettingService::class)->getBool('ai_inventory_proactive_enabled', false)) {
            return;
        }

        $lowCoverage = InventoryCoverage::coverageDays($event->productVariantId, $event->branchId);
        // مهدد بالنفاد فعلًا: تغطية 7 أيام أو أقل (أو صفر متاح).
        if ($lowCoverage !== null && $lowCoverage > 7) {
            return;
        }
        $daily = InventoryCoverage::avgDailySale($event->productVariantId, $event->branchId);
        if ($daily <= 0) {
            return;
        }

        // متبرع: نفس الصنف في فرع آخر بتغطية أكبر من 60 يوم.
        $donor = StockLevel::query()
            ->where('product_variant_id', $event->productVariantId)
            ->where('branch_id', '!=', $event->branchId)
            ->whereRaw('(on_hand - reserved - damaged) > 0')
            ->orderByDesc(DB::raw('on_hand - reserved - damaged'))
            ->limit(10)->get()
            ->map(fn ($l) => ['branch_id' => (int) $l->branch_id, 'available' => (float) $l->on_hand - (float) $l->reserved - (float) $l->damaged])
            ->first(function ($c) use ($event): bool {
                $cov = InventoryCoverage::coverageDays($event->productVariantId, $c['branch_id']);

                return $cov === null || $cov > 60;
            });
        if ($donor === null) {
            return;
        }

        $need = max(1.0, round($daily * 30 - InventoryCoverage::available($event->productVariantId, $event->branchId), 0));
        $donorDaily = InventoryCoverage::avgDailySale($event->productVariantId, $donor['branch_id']);
        $surplus = $donorDaily > 0
            ? max(0.0, $donor['available'] - $donorDaily * 60)
            : $donor['available'];
        $qty = (int) min($need, $surplus);
        if ($qty < 1) {
            return;
        }

        $variant = ProductVariant::query()->with('product:id,name_ar')->find($event->productVariantId);
        $sku = $variant?->sku;
        $label = $variant?->product?->name_ar ?? $sku ?? ('#'.$event->productVariantId);
        $prompt = "جهز تحويل {$qty} قطعة من {$label} (SKU {$sku}) من الفرع #{$donor['branch_id']} للفرع #{$event->branchId}";

        $recipients = StaffRecipients::withAnyPermission(['transfers.create'], [$event->branchId, $donor['branch_id']])
            ->merge(StaffRecipients::branchManagers($event->branchId))
            ->unique('id');
        if ($recipients->isEmpty()) {
            return;
        }

        DB::transaction(fn () => Notification::sendNow($recipients, new TransferSuggestionNotification(
            $event->productVariantId, $sku, $donor['branch_id'], $event->branchId,
            $qty, $lowCoverage, InventoryCoverage::coverageDays($event->productVariantId, $donor['branch_id']), $prompt,
        ), ['database']));
    }
}
```

## File: bags-platform/backend/app/Models/InventoryReconcileRun.php
```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryReconcileRun extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['branch_id', 'checked', 'problem_count', 'samples'];

    protected $casts = [
        'branch_id' => 'integer',
        'checked' => 'integer',
        'problem_count' => 'integer',
        'samples' => 'array',
    ];
}
```

## File: bags-platform/backend/app/Models/StockTransfer.php
```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransferStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransfer extends Model
{
    protected $attributes = ['version' => 1];

    protected static function booted(): void
    {
        static::saving(function (self $transfer): void {
            if (! $transfer->exists) {
                return;
            }
            $transfer->version = (int) $transfer->getRawOriginal('version') + 1;
            $transfer->updated_at = now();
        });
    }

    protected $fillable = [
        'transfer_number', 'from_branch_id', 'to_branch_id', 'status',
        'created_by', 'approved_by', 'shipped_at', 'received_at', 'notes',
    ];

    protected $casts = [
        'version' => 'integer',
        'status' => TransferStatus::class,
        'shipped_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
```

## File: bags-platform/backend/app/Notifications/InventoryAnomalyDigestNotification.php
```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** ملاحظات مراجعة أسبوعية لأنماط المخزون الغريبة — مش اتهامات. */
class InventoryAnomalyDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param list<array{title:string,detail:string}> $notes */
    public function __construct(private readonly array $notes) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'inventory_anomalies',
            'notes' => $this->notes,
            'message' => 'ملاحظات للمراجعة: '.count($this->notes).' ملاحظة على أنماط المخزون هذا الأسبوع.',
        ];
    }
}
```

## File: bags-platform/backend/app/Notifications/LowStockAlertNotification.php
```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** تنبيه انخفاض مخزون متغير في فرع إلى حد إعادة الطلب. */
class LowStockAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $productVariantId,
        private readonly ?string $sku,
        private readonly int $branchId,
        private readonly float $available,
        private readonly float $reorderLevel,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'low_stock',
            'product_variant_id' => $this->productVariantId,
            'sku' => $this->sku,
            'branch_id' => $this->branchId,
            'available' => $this->available,
            'reorder_level' => $this->reorderLevel,
            'message' => "انخفض مخزون الصنف {$this->sku} في الفرع #{$this->branchId} إلى {$this->available} (حد إعادة الطلب {$this->reorderLevel}).",
        ];
    }
}
```

## File: bags-platform/backend/app/Notifications/TransferPendingApprovalNotification.php
```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\StockTransfer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** تحويلة مخزون جديدة بانتظار الاعتماد. */
class TransferPendingApprovalNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly StockTransfer $transfer) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'transfer_pending_approval',
            'transfer_id' => $this->transfer->id,
            'transfer_number' => $this->transfer->transfer_number,
            'from_branch_id' => $this->transfer->from_branch_id,
            'to_branch_id' => $this->transfer->to_branch_id,
            'message' => "تحويلة جديدة رقم {$this->transfer->transfer_number} بانتظار الاعتماد.",
        ];
    }
}
```

## File: bags-platform/backend/app/Notifications/TransferSuggestionNotification.php
```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** اقتراح استباقي: إعادة توزيع صنف من فرع بتغطية عالية لفرع مهدد بالنفاد. */
class TransferSuggestionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $productVariantId,
        private readonly ?string $sku,
        private readonly int $fromBranchId,
        private readonly int $toBranchId,
        private readonly float $quantity,
        private readonly ?float $lowCoverageDays,
        private readonly ?float $donorCoverageDays,
        private readonly string $aiPrompt,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $low = $this->lowCoverageDays !== null ? "فاضل له {$this->lowCoverageDays} يوم" : 'رصيده منخفض';
        $donor = $this->donorCoverageDays !== null ? "تغطية {$this->donorCoverageDays} يوم" : 'بلا مبيعات';

        return [
            'kind' => 'transfer_suggestion',
            'product_variant_id' => $this->productVariantId,
            'sku' => $this->sku,
            'from_branch_id' => $this->fromBranchId,
            'to_branch_id' => $this->toBranchId,
            'quantity' => $this->quantity,
            'low_coverage_days' => $this->lowCoverageDays,
            'donor_coverage_days' => $this->donorCoverageDays,
            'ai_prompt' => $this->aiPrompt,
            'message' => "فرع #{$this->toBranchId} {$low} من الصنف {$this->sku}، وفرع #{$this->fromBranchId} عنده ({$donor}). مقترح: تحويل {$this->quantity} قطعة.",
        ];
    }
}
```

## File: bags-platform/backend/app/Services/Concerns/HandlesIdempotentCreation.php
```php
<?php

declare(strict_types=1);

namespace App\Services\Concerns;

use App\Exceptions\BusinessException;
use App\Models\InventoryIdempotencyKey;
use App\Services\BranchAccess;
use App\Services\IdempotencyFingerprint;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * إنشاء بمفتاح عدم تكرار لنهايات الإنشاء (تحويل/جرد): إعادة المحاولة بعد
 * timeout بنفس المفتاح تعيد النموذج المنشأ بدل نسخة مكررة، ونفس المفتاح
 * بحمولة مختلفة يُرفض 409. المفتاح يُدرج في نفس معاملة الإنشاء، ففشل
 * الإنشاء يلغيه معه (L-7 — نفس نمط مفتاح التسويات).
 */
trait HandlesIdempotentCreation
{
    /**
     * @param  list<string>  $relations
     * @param  callable(): Model  $creator
     */
    private function createIdempotently(string $context, array $payload, int $userId, ?string $idempotencyKey, string $modelClass, array $relations, callable $creator): Model
    {
        if ($idempotencyKey === null) {
            return $creator();
        }

        $requestHash = IdempotencyFingerprint::hash(['__context' => $context, 'payload' => $payload]);

        try {
            return DB::transaction(function () use ($userId, $idempotencyKey, $requestHash, $creator): Model {
                $record = InventoryIdempotencyKey::query()->create([
                    'user_id' => $userId,
                    'key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                ]);

                $model = $creator();

                $record->update(['response' => [$model->id], 'status_code' => 201]);

                return $model;
            });
        } catch (UniqueConstraintViolationException $e) {
            // L4: اسم القيد من رسالة السائق (errorInfo) لا من نص الاستثناء العام.
            $driverMessage = (string) ($e->errorInfo[2] ?? $e->getMessage());
            if (! str_contains($driverMessage, 'inventory_idempotency_keys_user_id_key_unique')
                && ! str_contains($e->getMessage(), 'inventory_idempotency_keys_user_id_key_unique')) {
                throw $e;
            }

            $record = InventoryIdempotencyKey::query()
                ->where('user_id', $userId)->where('key', $idempotencyKey)->first();

            if ($record === null || ! hash_equals($record->request_hash, $requestHash)) {
                throw new BusinessException('مفتاح عدم التكرار هذا مستخدم مسبقًا مع بيانات مختلفة.', [], 409);
            }

            $model = $modelClass::query()->with($relations)->findOrFail((int) ($record->response[0] ?? 0));
            // H4: إعادة التشغيل يقبل أي نطاق للنموذج (تحويل: مرسل أو وجهة).
            $branches = [];
            foreach (['branch_id', 'from_branch_id', 'to_branch_id'] as $attribute) {
                $value = $model->getAttribute($attribute);
                if ($value !== null) {
                    $branches[] = (int) $value;
                }
            }
            app(BranchAccess::class)->assertBranch($userId, ...$branches);

            return $model;
        }
    }
}
```

## File: bags-platform/backend/app/Services/SearchTerm.php
```php
<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Escapes user input to treat SQL LIKE wildcard characters as literal strings.
 */
class SearchTerm
{
    public static function escape(string $term): string
    {
        return strtr($term, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }

    public static function pattern(string $term): string
    {
        return '%'.self::escape($term).'%';
    }

    public static function prefix(string $term): string
    {
        return self::escape($term).'%';
    }
}
```

## File: bags-platform/backend/app/Services/SpokenQuantity.php
```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Ai\EntityResolver;

/**
 * Parses spoken Arabic quantities ("اتنين وعشرين") into numbers for the
 * POS voice-count mode. Fractions are not supported — counts are integers.
 */
final class SpokenQuantity
{
    /** @var array<string, float> */
    private const WORDS = [
        'صفر' => 0, 'واحد' => 1, 'واحده' => 1, 'اتنين' => 2, 'اثنين' => 2,
        'اثنان' => 2, 'تنين' => 2, 'تلاته' => 3, 'ثلاثه' => 3, 'اربعه' => 4,
        'خمسه' => 5, 'سته' => 6, 'سبعه' => 7, 'تمانيه' => 8, 'ثمانيه' => 8,
        'تسعه' => 9, 'عشره' => 10, 'عشر' => 10, 'حداشر' => 11, 'احد عشر' => 11,
        'اتناشر' => 12, 'اطناشر' => 12, 'اثنا عشر' => 12, 'تلتاشر' => 13,
        'اربعتاشر' => 14, 'خمستاشر' => 15, 'ستاشر' => 16, 'سبعتاشر' => 17,
        'تمنتاشر' => 18, 'تسعتاشر' => 19, 'عشرين' => 20, 'تلاتين' => 30,
        'اربعين' => 40, 'خمسين' => 50, 'ستين' => 60, 'سبعين' => 70,
        'تمانين' => 80, 'تسعين' => 90, 'ميه' => 100, 'ميت' => 100,
        'مايه' => 100, 'مائه' => 100, 'ميتين' => 200, 'الف' => 1000, 'دسته' => 12,
    ];

    /** @var list<string> */
    private const FILLER = ['قطعه', 'قطع', 'حبه', 'حبات', 'علبه', 'علب', 'كرتونه'];

    public static function parse(?string $input): ?float
    {
        if ($input === null) {
            return null;
        }
        $clean = trim(EntityResolver::normalizeDigits($input));
        if ($clean === '') {
            return null;
        }
        if (is_numeric($clean)) {
            $v = (float) $clean;
            if ($v >= 0 && $v <= 999999 && floor($v) == $v) {
                return $v;
            }

            return null;
        }

        $norm = EntityResolver::normalize($clean);
        if (isset(self::WORDS[$norm])) {
            return self::WORDS[$norm];
        }

        $spaced = str_replace(' و ', ' ', ' '.$norm.' ');
        $tokens = preg_split('/\s+/u', trim($spaced)) ?: [];
        $total = 0.0;
        $group = 0.0;
        $matched = 0;
        foreach ($tokens as $i => $token) {
            if ($token === '' || $token === 'و') {
                continue;
            }
            // عطف متصل: "وعشرين" — الواو الأولى تُحذف لغير أول توكن.
            if ($i > 0 && mb_substr($token, 0, 1) === 'و' && mb_strlen($token) > 1 && ! isset(self::WORDS[$token])) {
                $token = mb_substr($token, 1);
                if ($token === '' || $token === 'و') {
                    continue;
                }
            }
            if (in_array($token, self::FILLER, true)) {
                continue;
            }
            if (! isset(self::WORDS[$token])) {
                return null;
            }
            $matched++;
            $v = self::WORDS[$token];
            if ($v === 1000.0 || $v === 100.0) {
                $group = ($group > 0 ? $group : 1) * $v;
                $total += $group;
                $group = 0.0;
            } elseif ($v === 200.0) {
                $total += $v;
            } else {
                $group += $v;
            }
        }
        if ($matched === 0) {
            return null;
        }

        return $total + $group;
    }
}
```

## File: bags-platform/backend/database/migrations/2026_11_08_000001_create_inventory_reconcile_runs.php
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('inventory_reconcile_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->integer('checked')->default(0);
            $table->integer('problem_count')->default(0);
            $table->json('samples')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_reconcile_runs');
    }
};
```

## File: bags-platform/backend/app/Events/StockLowDetected.php
```php
<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * انخفض المتاح لمتغير في فرع إلى حد إعادة الطلب أو دونه — يُطلق من
 * InventoryService::recordMovement عند عبور الحد نزولًا فقط (لا تكرار
 * مع كل حركة لاحقة والرصيد تحت الحد أصلًا).
 *
 * M1: بعد الـ commit فقط — إطلاقه داخل المعاملة كان يدخل مهامًا للطابور
 * عن حركات قد تتراجع، ويفشل التسلسل لو التقطها العامل قبل الـ commit.
 */
class StockLowDetected implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $productVariantId,
        public int $branchId,
        public float $available,
        public float $reorderLevel,
    ) {}
}
```

## File: bags-platform/backend/app/Events/TransferPendingApproval.php
```php
<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\StockTransfer;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * أُنشئت تحويلة مخزون جديدة بانتظار الاعتماد.
 *
 * M1: بعد الـ commit فقط — داخل معاملة الإنشاء كان العامل قد يلتقط
 * المهمة قبل الحفظ فيفشل بـ ModelNotFoundException.
 */
class TransferPendingApproval implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public StockTransfer $transfer) {}
}
```

## File: bags-platform/backend/app/Http/Controllers/Api/V1/Admin/InventoryController.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\StockMovementType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Inventory\StoreAdjustmentRequest;
use App\Http\Resources\Admin\StockLevelResource;
use App\Http\Resources\Admin\StockMovementResource;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Services\BranchAccess;
use App\Services\InventoryService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

class InventoryController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly BranchAccess $branchAccess,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:inventory.view', only: ['index', 'movements']),
            new Middleware('can:inventory.adjust', only: ['adjustments']),
        ];
    }

    /** قائمة أرصدة المخزون الحالية مع الفلاتر والمتاح المحسوب. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'location_id' => ['nullable', 'integer', 'min:1'],
            'product_id' => ['nullable', 'integer', 'min:1'],
            'product_variant_id' => ['nullable', 'integer', 'min:1'],
            'low_stock' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $levels = StockLevel::query()
            ->when($this->branchAccess->restrictedBranch($request->user()), fn ($q, $branch) => $q->where('stock_levels.branch_id', $branch))
            ->with(['variant.product', 'branch', 'location'])
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('stock_levels.branch_id', $v))
            ->when($filters['location_id'] ?? null, fn ($q, $v) => $q->where('stock_levels.location_id', $v))
            ->when($filters['product_variant_id'] ?? null, fn ($q, $v) => $q->where('stock_levels.product_variant_id', $v))
            ->when($filters['product_id'] ?? null, fn ($q, $v) => $q->whereHas('variant', fn ($vq) => $vq->where('product_id', $v)))
            // L6: نفس شرط التنبيهات — حد الصف على الصف، وحد الصنف على إجمالي الفرع.
            ->when($filters['low_stock'] ?? null, fn ($q) => $q->whereLowStock())
            ->orderBy('stock_levels.branch_id')
            ->orderBy('stock_levels.product_variant_id')
            ->orderBy('stock_levels.id')
            ->paginate($filters['per_page'] ?? 15);

        return StockLevelResource::collection($levels);
    }

    /** سجل حركات دفتر الأستاذ من الأحدث للأقدم مع الفلاتر. */
    public function movements(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'location_id' => ['nullable', 'integer', 'min:1'],
            'product_id' => ['nullable', 'integer', 'min:1'],
            'product_variant_id' => ['nullable', 'integer', 'min:1'],
            'type' => ['nullable', Rule::in(array_column(StockMovementType::cases(), 'value'))],
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('from_date'), 'after_or_equal:from_date')],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $movements = StockMovement::query()
            ->when($this->branchAccess->restrictedBranch($request->user()), fn ($q, $branch) => $q->where('branch_id', $branch))
            ->with(['variant', 'branch', 'location', 'creator'])
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['location_id'] ?? null, fn ($q, $v) => $q->where('location_id', $v))
            ->when($filters['product_variant_id'] ?? null, fn ($q, $v) => $q->where('product_variant_id', $v))
            ->when($filters['product_id'] ?? null, fn ($q, $v) => $q->whereHas('variant', fn ($vq) => $vq->where('product_id', $v)))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            // حدود الأيام بأيام العمل (business_timezone) لا UTC، والتخزين يظل UTC
            ->when($filters['from_date'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', Carbon::parse($v, config('app.business_timezone'))->startOfDay()->utc()))
            ->when($filters['to_date'] ?? null, fn ($q, $v) => $q->where('created_at', '<', Carbon::parse($v, config('app.business_timezone'))->addDay()->startOfDay()->utc()))
            ->orderByDesc('id')
            // الدفتر append-only وسيكبر لملايين الصفوف: بلا COUNT(*) وبلا OFFSET —
            // ترقيم بالمؤشر على (branch_id, id DESC) يجعل الصفحات العميقة بنفس
            // تكلفة الأولى (M-7). المقايضة: لا قفز لرقم صفحة محدد.
            ->cursorPaginate($filters['per_page'] ?? 15);

        return StockMovementResource::collection($movements);
    }

    /** تسوية يدوية: كل العناصر في transaction واحدة عبر InventoryService فقط. */
    public function adjustments(StoreAdjustmentRequest $request): JsonResponse
    {
        $movements = $this->inventory->applyAdjustments($request->validated(), $request->user()->id, $this->requiredIdempotencyKey($request));

        return response()->json([
            'message' => 'تم تنفيذ التسوية بنجاح',
            'data' => StockMovementResource::collection((new EloquentCollection($movements))->load(['variant', 'branch', 'location'])),
        ], 201);
    }
}
```

## File: bags-platform/backend/app/Http/Controllers/Api/V1/Admin/StocktakeController.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\StocktakeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Stocktake\StoreStocktakeRequest;
use App\Http\Requests\Admin\Stocktake\UpdateStocktakeItemsRequest;
use App\Http\Requests\Admin\Stocktake\UpdateStocktakeRequest;
use App\Http\Resources\Admin\StocktakeResource;
use App\Models\Stocktake;
use App\Services\BranchAccess;
use App\Services\StocktakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

class StocktakeController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly StocktakeService $stocktakes,
        private readonly BranchAccess $branchAccess,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:stocktakes.view', only: ['index', 'show']),
            new Middleware('can:stocktakes.create', only: ['store']),
            new Middleware('can:stocktakes.update', only: ['update', 'updateItems']),
            new Middleware('can:stocktakes.delete', only: ['destroy']),
            new Middleware('can:stocktakes.approve', only: ['approve']),
        ];
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(array_column(StocktakeStatus::cases(), 'value'))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $filters['staff_branch_id'] = $this->branchAccess->restrictedBranch($request->user());

        return StocktakeResource::collection($this->stocktakes->paginate($filters));
    }

    public function store(StoreStocktakeRequest $request): JsonResponse
    {
        $stocktake = $this->stocktakes->create($request->validated(), $request->user()->id, $this->requiredIdempotencyKey($request));

        return StocktakeResource::make($stocktake)
            ->additional(['message' => 'تم إنشاء جلسة الجرد بنجاح'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Stocktake $stocktake): StocktakeResource
    {
        $this->branchAccess->assertBranch($request->user()->id, $stocktake->branch_id);

        return StocktakeResource::make($this->stocktakes->find($stocktake->id));
    }

    public function update(UpdateStocktakeRequest $request, Stocktake $stocktake): StocktakeResource
    {
        return StocktakeResource::make($this->stocktakes->update($stocktake, $request->validated(), $request->user()->id));
    }

    /** تحديث جزئي للعناصر: upsert للمرسلة فقط دون حذف الغائبة (M-4). */
    public function updateItems(UpdateStocktakeItemsRequest $request, Stocktake $stocktake): StocktakeResource
    {
        return StocktakeResource::make($this->stocktakes->upsertSomeItems($stocktake, $request->validated()['items'], $request->user()->id));
    }

    public function destroy(Request $request, Stocktake $stocktake): JsonResponse
    {
        $this->stocktakes->delete($stocktake, $request->user()->id);

        return response()->json(['message' => 'تم حذف جلسة الجرد بنجاح']);
    }

    public function approve(Request $request, Stocktake $stocktake): StocktakeResource
    {
        $stocktake = $this->stocktakes->approve($stocktake, $request->user()->id);

        return StocktakeResource::make($stocktake)->additional(['message' => 'تم اعتماد الجرد وتسوية الفروق']);
    }
}
```

## File: bags-platform/backend/app/Http/Resources/Admin/StockMovementResource.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Services\CostAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockMovementResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference_number' => $this->reference_number,
            'product_variant_id' => $this->product_variant_id,
            'variant' => $this->whenLoaded('variant', fn () => [
                'id' => $this->variant->id,
                'sku' => $this->variant->sku,
                'product_id' => $this->variant->product_id,
            ]),
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ]),
            'location' => $this->whenLoaded('location', fn () => $this->location ? [
                'id' => $this->location->id,
                'name' => $this->location->name,
            ] : null),
            'type' => $this->type,
            'affected_bucket' => $this->affected_bucket,
            'quantity' => $this->quantity,
            'balance_before' => $this->balance_before,
            'balance_after' => $this->balance_after,
            // تكلفة الشراء معلومة تجارية حساسة: لا تظهر إلا بصلاحية صريحة
            'unit_cost' => $this->when(CostAccess::canView($request->user()), $this->unit_cost),
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'created_by' => $this->created_by,
            'creator_name' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'notes' => $this->notes,
            'created_at' => $this->created_at,
        ];
    }
}
```

## File: bags-platform/backend/app/Listeners/SendLowStockAlert.php
```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\StockLowDetected;
use App\Models\ProductVariant;
use App\Notifications\LowStockAlertNotification;
use App\Notifications\StaffRecipients;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/** تنبيه مديري المشتريات (purchases.view) ومديري الفرع بانخفاض المخزون. */
class SendLowStockAlert implements ShouldQueue
{
    public function handle(StockLowDetected $event): void
    {
        // L4: balance oscillation around the threshold (sale → return → sale)
        // re-crosses on every pass, and row-level + variant-level checks can
        // fire two events for one movement — one alert per variant/branch
        // every 6 hours is enough for a restock signal.
        if (! Cache::add("low-stock:{$event->productVariantId}:{$event->branchId}", 1, now()->addHours(6))) {
            return;
        }

        $recipients = StaffRecipients::withAnyPermission(['purchases.view'], [$event->branchId])
            ->merge(StaffRecipients::branchManagers($event->branchId))
            ->unique('id');

        if ($recipients->isEmpty()) {
            return;
        }

        $sku = ProductVariant::query()->whereKey($event->productVariantId)->value('sku');

        DB::transaction(fn () => Notification::sendNow($recipients, new LowStockAlertNotification(
            $event->productVariantId,
            $sku !== null ? (string) $sku : null,
            $event->branchId,
            $event->available,
            $event->reorderLevel,
        ), ['database']));
    }
}
```

## File: bags-platform/backend/app/Listeners/SendTransferPendingApproval.php
```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TransferPendingApproval;
use App\Notifications\StaffRecipients;
use App\Notifications\TransferPendingApprovalNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/** تنبيه من يملكون اعتماد التحويلات (transfers.approve) بتحويلة جديدة. */
class SendTransferPendingApproval implements ShouldQueue
{
    public function handle(TransferPendingApproval $event): void
    {
        $recipients = StaffRecipients::withAnyPermission(['transfers.approve'], [
            (int) $event->transfer->from_branch_id, (int) $event->transfer->to_branch_id,
        ]);
        if ($recipients->isNotEmpty()) {
            DB::transaction(fn () => Notification::sendNow($recipients, new TransferPendingApprovalNotification($event->transfer), ['database']));
        }
    }
}
```

## File: bags-platform/backend/app/Models/Branch.php
```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BranchType;
use App\Models\Concerns\TracksEditVersion;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'code', 'type', 'phone', 'email', 'address', 'city', 'city_id', 'is_active'])]
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    use TracksEditVersion;

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => BranchType::class,
            'is_active' => 'boolean',
        ];
    }
}
```

## File: bags-platform/backend/app/Models/ProductionReceipt.php
```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionReceipt extends Model
{
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    protected $fillable = ['batch_number', 'branch_id', 'location_id', 'created_by', 'idempotency_key', 'request_hash', 'items', 'notes'];

    protected $hidden = ['idempotency_key', 'request_hash'];

    protected function casts(): array
    {
        return ['items' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('استلام الإنتاج مستند نهائي لا يُعدَّل.'));
        static::deleting(fn () => throw new \LogicException('استلام الإنتاج مستند نهائي لا يُحذف.'));
    }
}
```

## File: bags-platform/backend/app/Models/StockLevel.php
```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * رصيد مخزون حالي لمتغير في فرع/موقع. ممنوع التعديل المباشر:
 * أي تغيير بيتم حصرًا عن طريق InventoryService::recordMovement (قاعدة المرحلة 3 الصارمة).
 */
class StockLevel extends Model
{
    protected $fillable = [
        'product_variant_id', 'branch_id', 'location_id',
        'reorder_level',
    ];

    protected $casts = [
        'on_hand' => 'decimal:4',
        'reserved' => 'decimal:4',
        'damaged' => 'decimal:4',
        'in_transit' => 'decimal:4',
        'reorder_level' => 'decimal:4',
        'available' => 'decimal:4',
    ];

    /** المتاح للبيع = الفعلي − المحجوز − التالف (نفس معادلة العمود المولّد في القاعدة). */
    public function getAvailableAttribute(): float
    {
        return round((float) $this->on_hand - (float) $this->reserved - (float) $this->damaged, 4);
    }

    /**
     * نطاق للأرصدة غير الصفرية في أي وعاء مخزني (فعلي، محجوز، تالف، قيد النقل).
     *
     * @param  Builder<StockLevel>  $query
     * @return Builder<StockLevel>
     */
    public function scopeHasNonZeroBalance(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('on_hand', '<>', 0)
            ->orWhere('reserved', '<>', 0)
            ->orWhere('damaged', '<>', 0)
            ->orWhere('in_transit', '<>', 0));
    }

    /**
     * L6: rows currently in an alert state — mirrors the two
     * StockLowDetected triggers in InventoryService::recordMovement:
     * a set row threshold compares against the row, while an unset row
     * threshold falls back to the variant threshold compared against the
     * branch-wide total (not the single row).
     *
     * @param  Builder<StockLevel>  $query
     * @return Builder<StockLevel>
     */
    public function scopeWhereLowStock(Builder $query): Builder
    {
        return $query
            ->join('product_variants as pv_low', 'pv_low.id', '=', 'stock_levels.product_variant_id')
            ->leftJoinSub(
                self::query()->selectRaw('branch_id, product_variant_id, COALESCE(SUM(on_hand - reserved - damaged), 0) AS branch_available')
                    ->groupBy('branch_id', 'product_variant_id'),
                'branch_totals',
                fn ($join) => $join
                    ->on('branch_totals.branch_id', '=', 'stock_levels.branch_id')
                    ->on('branch_totals.product_variant_id', '=', 'stock_levels.product_variant_id')
            )
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $row) => $row
                    ->where('stock_levels.reorder_level', '>', 0)
                    ->whereRaw('stock_levels.available <= stock_levels.reorder_level'))
                ->orWhere(fn (Builder $variant) => $variant
                    ->whereRaw('COALESCE(stock_levels.reorder_level, 0) = 0')
                    ->where('pv_low.reorder_level', '>', 0)
                    ->whereRaw('COALESCE(branch_totals.branch_available, 0) <= pv_low.reorder_level')))
            ->select('stock_levels.*');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
```

## File: bags-platform/backend/app/Services/CostAccess.php
```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

/**
 * Authorizes access to confidential product and inventory unit cost values.
 * Both legacy permissions represent the same commercial data boundary.
 */
final class CostAccess
{
    /**
     * Check if the authenticated staff user has permission to view unit costs.
     */
    public static function canView(?User $user): bool
    {
        return $user !== null && ($user->can('products.view_cost') || $user->can('inventory.view_cost'));
    }
}
```

## File: bags-platform/backend/app/Services/StocktakeService.php
```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Enums\StocktakeStatus;
use App\Exceptions\BusinessException;
use App\Models\Location;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Observers\CatalogRetailPriceObserver;
use App\Services\Concerns\HandlesIdempotentCreation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Manages physical inventory stocktaking sessions, count reconciliation, and ledger adjustments.
 */
class StocktakeService
{
    use HandlesIdempotentCreation;

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly SettingService $settings,
    ) {}

    /**
     * Paginates stocktake sessions with applied branch and status filters.
     *
     * @param array<string, mixed> $filters Query filters
     * @return LengthAwarePaginator Paginated stocktakes
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return Stocktake::query()
            ->when($filters['staff_branch_id'] ?? null, fn ($q, $branch) => $q->where('branch_id', $branch))
            ->with(['branch', 'location', 'creator'])
            ->withCount('items')
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Retrieves a single stocktake session with loaded associations.
     *
     * @param int $id Stocktake ID
     * @return Stocktake Retrieved stocktake model
     */
    public function find(int $id): Stocktake
    {
        return Stocktake::query()
            ->with(['items.variant', 'branch', 'location', 'creator', 'approver'])
            ->findOrFail($id);
    }

    /**
     * Creates a new draft stocktake session idempotently.
     *
     * @param array<string, mixed> $data Stocktake attributes and items
     * @param int $userId Creator ID
     * @param string|null $idempotencyKey Optional client request idempotency key
     * @return Stocktake Created stocktake
     * @throws BusinessException If location or branch scope is invalid
     */
    public function create(array $data, int $userId, ?string $idempotencyKey = null): Stocktake
    {
        app(BranchAccess::class)->assertBranch($userId, (int) $data['branch_id']);

        /** @var Stocktake */
        return $this->createIdempotently(
            'stocktake_store',
            $data,
            $userId,
            $idempotencyKey,
            Stocktake::class,
            ['items.variant', 'branch', 'location', 'creator'],
            fn (): Stocktake => $this->doCreate($data, $userId),
        );
    }

    private function doCreate(array $data, int $userId): Stocktake
    {
        return DB::transaction(function () use ($data, $userId): Stocktake {
            app(BranchAccess::class)->assertBranch($userId, (int) $data['branch_id']);
            $this->assertScopedLocation((int) $data['branch_id'], $data['location_id'] ?? null);
            $stocktake = Stocktake::query()->create([
                'stocktake_number' => $this->settings->generateNextNumber('stocktake'),
                'branch_id' => $data['branch_id'],
                'location_id' => $data['location_id'] ?? null,
                'status' => StocktakeStatus::Draft,
                'created_by' => $userId,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncItems($stocktake, $data['items'] ?? []);

            return $stocktake->load(['items.variant', 'branch', 'location', 'creator']);
        });
    }

    /**
     * Updates an editable stocktake session attributes, status, and items.
     *
     * @param Stocktake $stocktake Target stocktake
     * @param array<string, mixed> $data Updated attributes
     * @param int $userId User performing update
     * @return Stocktake Updated stocktake instance
     * @throws BusinessException If stocktake is locked or transition is invalid
     */
    public function update(Stocktake $stocktake, array $data, int $userId): Stocktake
    {
        return DB::transaction(function () use ($stocktake, $data, $userId): Stocktake {
            $stocktake = Stocktake::query()->lockForUpdate()->findOrFail($stocktake->id);
            $this->assertEditable($stocktake);
            app(BranchAccess::class)->assertBranch($userId, $stocktake->branch_id);
            app(BranchAccess::class)->assertBranch($userId, (int) ($data['branch_id'] ?? $stocktake->branch_id));

            $branchId = $data['branch_id'] ?? $stocktake->branch_id;
            $locationId = array_key_exists('location_id', $data) ? $data['location_id'] : $stocktake->location_id;
            if ($locationId !== null && ! Location::query()->whereKey($locationId)->where('branch_id', $branchId)->exists()) {
                throw new BusinessException('موقع الجرد لا يتبع الفرع المحدد.');
            }
            if ($locationId === null && ($data['status'] ?? null) !== StocktakeStatus::Cancelled->value) {
                $this->assertScopedLocation((int) $branchId, null);
            }
            $scopeChanged = (int) $branchId !== $stocktake->branch_id || ($locationId === null ? null : (int) $locationId) !== $stocktake->location_id;
            if ($scopeChanged
                && ! array_key_exists('items', $data) && $stocktake->items()->exists()) {
                throw new BusinessException('تغيير نطاق الجرد يتطلب إعادة إرسال العناصر والعد الفعلي.');
            }

            if (isset($data['status'])) {
                $target = StocktakeStatus::from($data['status']);
                $allowed = match ($stocktake->status) {
                    StocktakeStatus::Draft => [StocktakeStatus::InProgress, StocktakeStatus::Cancelled],
                    StocktakeStatus::InProgress => [StocktakeStatus::Cancelled],
                    default => [],
                };

                if (! in_array($target, $allowed, true)) {
                    throw new BusinessException("لا يمكن نقل الجرد من حالة {$stocktake->status->value} إلى {$target->value}.");
                }

                $stocktake->status = $target;
            }

            $stocktake->fill([
                'branch_id' => $data['branch_id'] ?? $stocktake->branch_id,
                'location_id' => array_key_exists('location_id', $data) ? $data['location_id'] : $stocktake->location_id,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $stocktake->notes,
            ])->save();

            if (($stocktake->status === StocktakeStatus::Cancelled)
                && DB::table('stocktake_scans')->where('stocktake_id', $stocktake->id)->exists()) {
                // H2: cancelled sessions release their per-scan idempotency rows.
                DB::table('stocktake_scans')->where('stocktake_id', $stocktake->id)->delete();
            }

            if (isset($data['items'])) {
                if ($scopeChanged) {
                    $data['items'] = array_map(fn ($item) => [...$item, 'recount' => true], $data['items']);
                }
                $this->upsertItems($stocktake, $data['items']);
            }

            return $stocktake->load(['items.variant', 'branch', 'location', 'creator']);
        });
    }

    /**
     * Approves a stocktake session, recording inventory adjustments for non-zero count differences.
     *
     * @param Stocktake $stocktake Target stocktake
     * @param int $userId Approving user ID
     * @return Stocktake Approved and locked stocktake
     * @throws BusinessException If items are uncounted, stale, or stocktake cannot be approved
     */
    public function approve(Stocktake $stocktake, int $userId): Stocktake
    {
        return app(CatalogRetailPriceObserver::class)->batch(fn () => DB::transaction(function () use ($stocktake, $userId): Stocktake {
            $stocktake = Stocktake::query()->lockForUpdate()->findOrFail($stocktake->id);
            app(BranchAccess::class)->assertBranch($userId, $stocktake->branch_id);
            $stocktake->load('items.variant');

            if (! in_array($stocktake->status, [StocktakeStatus::Draft, StocktakeStatus::InProgress], true)) {
                throw new BusinessException('لا يمكن اعتماد جرد بحالة '.$stocktake->status->value.'.');
            }

            $this->assertScopedLocation((int) $stocktake->branch_id, $stocktake->location_id);
            $this->assertItemLimit($stocktake->items->count());

            $uncounted = $stocktake->items->filter(fn ($item) => $item->counted_qty === null);

            if ($stocktake->items->isEmpty()) {
                throw new BusinessException('لا يمكن اعتماد جرد بلا عناصر.');
            }

            if ($uncounted->isNotEmpty()) {
                throw new BusinessException('لا يمكن الاعتماد: توجد عناصر لم تُعدّ بعد.');
            }

            $levels = $this->inventory->lockLevels($stocktake->items->map(fn ($item) => [
                'product_variant_id' => $item->product_variant_id,
                'branch_id' => $stocktake->branch_id, 'location_id' => $stocktake->location_id,
            ])->all());

            // Reconcile according to configured stale policy: reject on intermediate movement or apply net difference.
            $rejectStale = config('inventory.stocktake_stale_policy', 'reject') !== 'apply_difference';
            $staleItemIds = [];
            $staleVariantIds = [];
            $latestMovements = StockMovement::query()->where('branch_id', $stocktake->branch_id)
                ->where('location_id', $stocktake->location_id)->where('affected_bucket', StockBucket::OnHand->value)
                ->whereIn('product_variant_id', $stocktake->items->pluck('product_variant_id'))
                ->when(! $rejectStale, fn ($q) => $q->whereIn('type', [StockMovementType::StocktakeGain->value, StockMovementType::StocktakeLoss->value]))
                ->selectRaw('product_variant_id, MAX(id) AS last_id')->groupBy('product_variant_id')
                ->pluck('last_id', 'product_variant_id');
            foreach ($stocktake->items as $item) {
                $key = $this->inventory->levelKey([
                    'product_variant_id' => $item->product_variant_id,
                    'branch_id' => $stocktake->branch_id, 'location_id' => $stocktake->location_id,
                ]);
                if ((int) ($latestMovements[$item->product_variant_id] ?? 0) > (int) $item->snapshot_movement_id
                    || ($rejectStale && (int) round((float) $levels[$key]->on_hand * 10000) !== (int) round((float) $item->system_qty * 10000))) {
                    $staleItemIds[] = $item->id;
                    $staleVariantIds[] = $item->product_variant_id;
                }
                $finalQty = (float) $levels[$key]->on_hand + (float) $item->difference;
                if ((int) round($finalQty * 10000) < (int) round((float) $levels[$key]->reserved * 10000) + (int) round((float) $levels[$key]->damaged * 10000)) {
                    throw new BusinessException('العد الفعلي أقل من المحجوز والتالف؛ راجع الحجوزات وسوِّ التالف قبل إعادة العد والاعتماد.');
                }
            }

            if ($staleItemIds !== []) {
                throw new BusinessException(
                    'توجد أصناف تغيّر رصيدها بعد عدّها وتحتاج إعادة عد قبل الاعتماد.',
                    ['recount_item_ids' => $staleItemIds, 'recount_variant_ids' => $staleVariantIds],
                );
            }

            foreach ($stocktake->items as $item) {
                $difference = (float) $item->difference;

                if ($difference === 0.0) {
                    continue;
                }

                $key = $this->inventory->levelKey([
                    'product_variant_id' => $item->product_variant_id,
                    'branch_id' => $stocktake->branch_id, 'location_id' => $stocktake->location_id,
                ]);

                $this->inventory->recordMovement([
                    'product_variant_id' => $item->product_variant_id,
                    'branch_id' => $stocktake->branch_id,
                    'location_id' => $stocktake->location_id,
                    'type' => $difference > 0 ? StockMovementType::StocktakeGain : StockMovementType::StocktakeLoss,
                    'affected_bucket' => StockBucket::OnHand,
                    'quantity' => $difference,
                    'unit_cost' => $item->variant?->cost_price,
                    'reference_type' => Stocktake::class,
                    'reference_id' => $stocktake->id,
                    'created_by' => $userId,
                    'notes' => 'تسوية جرد '.$stocktake->stocktake_number,
                ], $levels[$key] ?? null);
            }

            $stocktake->update([
                'status' => StocktakeStatus::Approved,
                'approved_by' => $userId,
                'approved_at' => now(),
            ]);

            // H2: the session is closed; drop per-scan idempotency rows (audit served its purpose).
            DB::table('stocktake_scans')->where('stocktake_id', $stocktake->id)->delete();

            return $stocktake->load(['items.variant', 'branch', 'location']);
        }));
    }

    /**
     * Deletes a draft or cancelled stocktake session.
     *
     * @param Stocktake $stocktake Stocktake to delete
     * @param int $userId Requesting user ID
     * @throws BusinessException If session is in an undeletable status
     */
    public function delete(Stocktake $stocktake, int $userId): void
    {
        DB::transaction(function () use ($stocktake, $userId): void {
            $stocktake = Stocktake::query()->lockForUpdate()->findOrFail($stocktake->id);
            app(BranchAccess::class)->assertBranch($userId, $stocktake->branch_id);

            if (! in_array($stocktake->status, [StocktakeStatus::Draft, StocktakeStatus::Cancelled], true)) {
                throw new BusinessException('لا يمكن حذف جرد إلا في حالة المسودة أو الملغي.');
            }

            $stocktake->delete();
        });
    }

    private function assertScopedLocation(int $branchId, mixed $locationId): void
    {
        if ($locationId !== null) {
            return;
        }

        // Prevent branch-wide unlocated stocktaking when location-specific stock exists to avoid artificial discrepancies.
        $hasLocatedStock = StockLevel::query()->where('branch_id', $branchId)
            ->whereNotNull('location_id')->hasNonZeroBalance()->exists();

        if ($hasLocatedStock) {
            throw new BusinessException('هذا الفرع يستخدم المواقع وله أرصدة عليها؛ حدد موقع الجرد بدل العدّ الإجمالي للفرع.');
        }
    }

    private function assertEditable(Stocktake $stocktake): void
    {
        if (in_array($stocktake->status, [StocktakeStatus::Approved, StocktakeStatus::Cancelled], true)) {
            throw new BusinessException('هذا الجرد مقفل ولا يقبل أي تعديل.');
        }
    }

    /**
     * @param list<array{product_variant_id: int, counted_qty?: float|int|string|null}> $items
     */
    private function syncItems(Stocktake $stocktake, array $items): void
    {
        $this->assertItemLimit(count($items));
        $levels = $this->snapshotLevels($stocktake, $items);
        $snapshotMovementId = (int) StockMovement::query()->max('id');
        foreach ($items as $item) {
            $level = $levels->get($item['product_variant_id']);

            $stocktake->items()->create([
                'product_variant_id' => $item['product_variant_id'],
                'system_qty' => $level ? (float) $level->on_hand : 0,
                'snapshot_movement_id' => $snapshotMovementId,
                'counted_qty' => $item['counted_qty'] ?? null,
            ]);
        }
    }

    /**
     * Incrementally updates a subset of stocktake items without deleting unmentioned ones.
     *
     * @param Stocktake $stocktake Target stocktake
     * @param list<array{product_variant_id: int, counted_qty?: float|int|string|null, recount?: bool|null}> $items Items payload
     * @param int $userId User ID
     * @return Stocktake Updated stocktake
     */
    public function upsertSomeItems(Stocktake $stocktake, array $items, int $userId): Stocktake
    {
        return DB::transaction(function () use ($stocktake, $items, $userId): Stocktake {
            $stocktake = Stocktake::query()->lockForUpdate()->findOrFail($stocktake->id);
            $this->assertEditable($stocktake);
            app(BranchAccess::class)->assertBranch($userId, $stocktake->branch_id);

            $this->upsertItems($stocktake, $items, deleteAbsent: false);
            $stocktake->touch();

            return $stocktake->load(['items.variant', 'branch', 'location', 'creator']);
        });
    }

    /**
     * Adds counted quantity to a stocktake item under row lock (e.g. for barcode scanning).
     *
     * H2: when the client supplies a scan UUID, the scan is recorded in
     * stocktake_scans with a unique (stocktake_id, scan_id) key inside the
     * same transaction. A retried request replays the stored state instead of
     * double-counting (exactly-once per scan at the database level).
     *
     * @param Stocktake $stocktake Target stocktake
     * @param int $variantId Scanned variant ID
     * @param float $quantity Scanned quantity increment
     * @param int $userId Scanning user ID
     * @param string|null $scanId Client-generated scan UUID for idempotency
     * @return Stocktake Updated stocktake
     * @throws BusinessException If session is locked or quantity is invalid
     */
    public function incrementItemCount(Stocktake $stocktake, int $variantId, float $quantity, int $userId, ?string $scanId = null): Stocktake
    {
        return DB::transaction(function () use ($stocktake, $variantId, $quantity, $userId, $scanId): Stocktake {
            $stocktake = Stocktake::query()->lockForUpdate()->findOrFail($stocktake->id);
            $this->assertEditable($stocktake);
            app(BranchAccess::class)->assertBranch($userId, $stocktake->branch_id);

            if ($scanId !== null) {
                $recorded = DB::table('stocktake_scans')->insertOrIgnore([
                    'stocktake_id' => $stocktake->id,
                    'scan_id' => $scanId,
                    'product_variant_id' => $variantId,
                    'qty' => $quantity,
                    'created_by' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($recorded === 0) {
                    // Duplicate delivery of an already-applied scan: replay state, do not count again.
                    return $stocktake->load(['items.variant', 'branch', 'location', 'creator']);
                }
            }

            $existing = $stocktake->items()->where('product_variant_id', $variantId)->first();
            if ($existing !== null && $existing->counted_qty !== null && (int) $existing->snapshot_movement_id > 0) {
                // M2: a previously-counted variant rescanned after an on_hand
                // movement on the same key would silently double-apply the
                // difference at approve time. Reject the scan and ask for a
                // recount, which refreshes system_qty and the snapshot cursor.
                // Uses stock_movements_stale_check_index
                // (branch_id, location_id, affected_bucket, product_variant_id, id).
                $moved = StockMovement::query()
                    ->where('branch_id', $stocktake->branch_id)
                    ->where('location_id', $stocktake->location_id)
                    ->where('affected_bucket', StockBucket::OnHand->value)
                    ->where('product_variant_id', $variantId)
                    ->where('id', '>', (int) $existing->snapshot_movement_id)
                    ->exists();
                if ($moved) {
                    throw new BusinessException('تغيّر رصيد هذا الصنف أثناء العد؛ أعد عدّه من جديد قبل المتابعة.');
                }
            }
            $counted = (float) ($existing?->counted_qty ?? 0);
            $total = round($counted + $quantity, 4);
            if (! is_finite($quantity) || $quantity <= 0 || $total > 99999999.9999) {
                throw new BusinessException('كمية العد تتجاوز النطاق المسموح.');
            }
            if ($existing !== null && $existing->counted_qty !== null) {
                $existing->update(['counted_qty' => $total]);
            } else {
                $this->upsertItems($stocktake, [['product_variant_id' => $variantId, 'counted_qty' => $total]], deleteAbsent: false);
            }
            $stocktake->touch();

            return $stocktake->load(['items.variant', 'branch', 'location', 'creator']);
        });
    }

    /**
     * @param list<array{product_variant_id: int, counted_qty?: float|int|string|null, recount?: bool|null}> $items
     */
    private function upsertItems(Stocktake $stocktake, array $items, bool $deleteAbsent = true): void
    {
        $existing = $stocktake->items()->get()->keyBy('product_variant_id');

        $payloadIds = collect($items)->map(fn ($item) => (int) $item['product_variant_id'])->unique()->values()->all();
        $totalItems = $deleteAbsent
            ? count($payloadIds)
            : $existing->count() + count(array_diff($payloadIds, $existing->keys()->map(fn ($id) => (int) $id)->all()));
        $this->assertItemLimit($totalItems);
        $levels = $this->snapshotLevels($stocktake, $items);
        $snapshotMovementId = (int) StockMovement::query()->max('id');

        $keepIds = [];
        foreach ($items as $item) {
            $variantId = (int) $item['product_variant_id'];
            $currentOnHand = $levels->get($variantId) ? (float) $levels->get($variantId)->on_hand : 0.0;
            $row = $existing->get($variantId);

            if ($row === null) {
                $created = $stocktake->items()->create([
                    'product_variant_id' => $variantId,
                    'system_qty' => $currentOnHand,
                    'snapshot_movement_id' => $snapshotMovementId,
                    'counted_qty' => $item['counted_qty'] ?? null,
                ]);
                $keepIds[] = $created->id;

                continue;
            }

            $keepIds[] = $row->id;
            $updates = [];

            if (array_key_exists('counted_qty', $item)) {
                $updates['counted_qty'] = $item['counted_qty'];

                $countChanged = $item['counted_qty'] !== null
                    && ($row->counted_qty === null || (float) $item['counted_qty'] !== (float) $row->counted_qty);

                if ($countChanged || ! empty($item['recount'])) {
                    $updates['system_qty'] = $currentOnHand;
                    $updates['snapshot_movement_id'] = $snapshotMovementId;
                }
            } elseif (! empty($item['recount'])) {
                $updates['system_qty'] = $currentOnHand;
                $updates['snapshot_movement_id'] = $snapshotMovementId;
            }

            if ($updates !== []) {
                $row->update($updates);
            }
        }

        if ($deleteAbsent) {
            $stocktake->items()->whereNotIn('id', $keepIds)->delete();
        }
    }

    private function snapshotLevels(Stocktake $stocktake, array $items): Collection
    {
        return $this->inventory->lockLevels(array_map(fn ($item) => [
            'branch_id' => $stocktake->branch_id, 'location_id' => $stocktake->location_id,
            'product_variant_id' => $item['product_variant_id'],
        ], $items))->keyBy('product_variant_id');
    }

    private function assertItemLimit(int $count): void
    {
        $maxItems = max(1, (int) config('inventory.stocktake_max_items', 500));
        if ($count > $maxItems) {
            throw new BusinessException("تجاوزت الجلسة الحد الأقصى للبنود ({$maxItems})؛ قسّم الجرد إلى جلسات حسب الموقع.");
        }
    }
}
```

## File: bags-platform/backend/database/migrations/2026_10_23_000001_create_production_receipts_table.php
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    private const LEGACY_TYPES = "'purchase_receipt', 'sale', 'sale_return', 'adjustment_in', 'adjustment_out', 'damage', 'transfer_out', 'transfer_in', 'stocktake_gain', 'stocktake_loss', 'reserve', 'release_reserve', 'damage_release', 'damage_write_off', 'transfer_in_transit', 'transfer_transit_release', 'transfer_shortage'";

    public function up(): void
    {
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_valid_type');
        // M7: NOT VALID + VALIDATE avoids a full-table ACCESS EXCLUSIVE scan stall on a hot ledger.
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_valid_type CHECK (type IN ('.self::LEGACY_TYPES.", 'production_receipt')) NOT VALID");
        DB::statement('ALTER TABLE stock_movements VALIDATE CONSTRAINT stock_movements_valid_type');
        Schema::create('production_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('batch_number', 100)->unique();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 128);
            $table->string('request_hash', 64);
            $table->json('items');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['created_by', 'idempotency_key']);
            $table->index(['branch_id', 'id']);
        });
    }

    public function down(): void
    {
        if (DB::table('production_receipts')->exists() || DB::table('stock_movements')->where('type', 'production_receipt')->exists()) {
            throw new RuntimeException('Cannot roll back recorded production receipts; preserve the inventory audit trail.');
        }
        Schema::dropIfExists('production_receipts');
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_valid_type');
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_valid_type CHECK (type IN ('.self::LEGACY_TYPES.'))');
    }
};
```

## File: bags-platform/backend/app/Http/Controllers/Api/V1/Admin/TransferController.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\TransferStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Transfer\ApproveTransferRequest;
use App\Http\Requests\Admin\Transfer\ReceiveTransferRequest;
use App\Http\Requests\Admin\Transfer\ShipTransferRequest;
use App\Http\Requests\Admin\Transfer\StoreTransferRequest;
use App\Http\Requests\Admin\Transfer\UpdateTransferRequest;
use App\Http\Resources\Admin\StockTransferResource;
use App\Models\StockTransfer;
use App\Services\BranchAccess;
use App\Services\TransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

class TransferController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly TransferService $transfers,
        private readonly BranchAccess $branchAccess,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:transfers.view', only: ['index', 'show']),
            new Middleware('can:transfers.create', only: ['store']),
            new Middleware('can:transfers.update', only: ['update']),
            new Middleware('can:transfers.delete', only: ['destroy']),
            new Middleware('can:transfers.approve', only: ['approve']),
            new Middleware('can:transfers.ship', only: ['ship']),
            new Middleware('can:transfers.receive', only: ['receive']),
            new Middleware('can:transfers.update', only: ['cancel']),
        ];
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'from_branch_id' => ['nullable', 'integer', 'min:1'],
            'to_branch_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(array_column(TransferStatus::cases(), 'value'))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $filters['staff_branch_id'] = $this->branchAccess->restrictedBranch($request->user());

        return StockTransferResource::collection($this->transfers->paginate($filters));
    }

    public function store(StoreTransferRequest $request): JsonResponse
    {
        $transfer = $this->transfers->create($request->validated(), $request->user()->id, $this->requiredIdempotencyKey($request));

        return StockTransferResource::make($transfer)
            ->additional(['message' => 'تم إنشاء التحويل بنجاح'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, StockTransfer $transfer): StockTransferResource
    {
        $this->branchAccess->assertBranch($request->user()->id, $transfer->from_branch_id, $transfer->to_branch_id);

        return StockTransferResource::make($this->transfers->find($transfer->id));
    }

    public function update(UpdateTransferRequest $request, StockTransfer $transfer): StockTransferResource
    {
        return StockTransferResource::make($this->transfers->update($transfer, $request->validated(), $request->user()->id));
    }

    public function destroy(Request $request, StockTransfer $transfer): JsonResponse
    {
        $this->transfers->delete($transfer, $request->user()->id);

        return response()->json(['message' => 'تم حذف التحويل بنجاح']);
    }

    public function approve(ApproveTransferRequest $request, StockTransfer $transfer): StockTransferResource
    {
        $transfer = $this->transfers->approve(
            $transfer,
            $request->quantitiesByItemId(),
            (int) $request->user()->id,
            $request->expectedUpdatedAt(),
            $request->expectedVersion(),
        );

        return StockTransferResource::make($transfer)->additional(['message' => 'تم اعتماد التحويل وحجز الكميات']);
    }

    public function ship(ShipTransferRequest $request, StockTransfer $transfer): StockTransferResource
    {
        $transfer = $this->transfers->ship(
            $transfer,
            $request->quantitiesByItemId(),
            (int) $request->user()->id,
            $request->expectedUpdatedAt(),
            $request->expectedVersion(),
        );

        return StockTransferResource::make($transfer)->additional(['message' => 'تم شحن التحويل بنجاح']);
    }

    public function receive(ReceiveTransferRequest $request, StockTransfer $transfer): StockTransferResource
    {
        $transfer = $this->transfers->receive(
            $transfer,
            $request->quantitiesByItemId(),
            (int) $request->user()->id,
            $request->expectedUpdatedAt(),
            $request->expectedVersion(),
        );

        return StockTransferResource::make($transfer)->additional(['message' => 'تم استلام التحويل بنجاح']);
    }

    public function cancel(Request $request, StockTransfer $transfer): StockTransferResource
    {
        $transfer = $this->transfers->cancel($transfer, $request->user()->id);

        return StockTransferResource::make($transfer)->additional(['message' => 'تم إلغاء التحويل']);
    }
}
```

## File: bags-platform/backend/app/Observers/CatalogRetailPriceObserver.php
```php
<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\RefreshCatalogRetailPrices;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Monitors price list, variant, and stock mutations to batch-refresh catalog price snapshots across nested transactions.
 */
class CatalogRetailPriceObserver
{
    private int $batchDepth = 0;

    private array $pendingProductIds = [];

    private array $transactionSnapshots = [];

    /**
     * Records pre-transaction state snapshot on connection begin.
     *
     * @param  Connection  $connection  Database connection instance
     */
    public function transactionBegan(Connection $connection): void
    {
        if ($this->batchDepth > 0 && $connection->getName() === config('database.default')) {
            $this->transactionSnapshots[$connection->transactionLevel()] = $this->pendingProductIds;
        }
    }

    /**
     * Reverts pending IDs if transaction rolled back, or cleans up state on commit.
     *
     * @param  Connection  $connection  Database connection
     * @param  bool  $rolledBack  Whether transaction rolled back
     */
    public function transactionFinished(Connection $connection, bool $rolledBack = false): void
    {
        if ($this->batchDepth === 0 || $connection->getName() !== config('database.default')) {
            return;
        }
        $level = $connection->transactionLevel();
        if ($rolledBack && isset($this->transactionSnapshots[$level + 1])) {
            $this->pendingProductIds = $this->transactionSnapshots[$level + 1];
        }
        foreach (array_keys($this->transactionSnapshots) as $snapshotLevel) {
            if ($snapshotLevel > $level) {
                unset($this->transactionSnapshots[$snapshotLevel]);
            }
        }
    }

    /**
     * Groups mutations in a closure to defer dispatching refresh job until outermost batch completes.
     *
     * @param  callable  $callback  Executable closure
     * @return mixed Closure return value
     */
    public function batch(callable $callback): mixed
    {
        $previous = $this->pendingProductIds;
        $this->batchDepth++;
        try {
            $result = $callback();
        } catch (\Throwable $exception) {
            $this->pendingProductIds = $previous;
            throw $exception;
        } finally {
            $this->batchDepth--;
            if ($this->batchDepth === 0) {
                $this->transactionSnapshots = [];
            }
        }

        if ($this->batchDepth === 0 && $this->pendingProductIds !== []) {
            $ids = $this->pendingProductIds;
            $this->pendingProductIds = [];
            $this->dispatchRefresh($ids);
        }

        return $result;
    }

    public function saved(Model $model): void
    {
        $this->refresh($model);
    }

    public function deleted(Model $model): void
    {
        $this->refresh($model);
    }

    public function restored(Model $model): void
    {
        $this->refresh($model);
    }

    private function refresh(Model $model): void
    {
        $productIds = [];
        if ($model instanceof ProductVariant) {
            $productIds = [$model->product_id, $model->getRawOriginal('product_id')];
        } elseif ($model instanceof PriceListItem || $model instanceof StockLevel) {
            $variantIds = [$model->product_variant_id, $model->getRawOriginal('product_variant_id')];
            $loadedVariant = $model->relationLoaded('variant') ? $model->getRelation('variant') : null;
            if (! $model->wasChanged('product_variant_id') && $loadedVariant?->id === $model->product_variant_id && $loadedVariant->product_id !== null) {
                $productIds = [$loadedVariant->product_id];
            } else {
                $productIds = ProductVariant::withTrashed()->whereIn('id', array_filter($variantIds))->pluck('product_id')->all();
            }
        } else {
            $this->dispatchRefresh();

            return;
        }

        $clean = array_values(array_filter(array_unique(array_map('intval', $productIds))));
        if ($clean === []) {
            return;
        }

        if ($this->batchDepth > 0) {
            $this->pendingProductIds = array_values(array_unique(array_merge($this->pendingProductIds, $clean)));
        } else {
            $this->dispatchRefresh($clean);
        }
    }

    private function dispatchRefresh(int|array|null $productIds = null): void
    {
        // PendingDispatch acquires the unique lock before the job's afterCommit
        // setting takes effect. Defer dispatch itself so a database cache lock
        // collision cannot abort the transaction saving the model.
        DB::afterCommit(static function () use ($productIds): void {
            RefreshCatalogRetailPrices::dispatch($productIds);
        });
    }
}
```

## File: bags-platform/backend/app/Console/Commands/ReconcileInventory.php
```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\InventoryReconcileRun;
use App\Services\StatementBudget;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * مطابقة دورية بين الدفتر والأرصدة (M-8): القاعدة تضمن صحة كل حركة على حدة،
 * والكتابة المباشرة التي تتجاوز حماية الموديل تحتاج مطابقة مستقلة.
 * الفحص يتحقق لكل وعاء من أمرين:
 * 1. رصيد stock_levels يساوي آخر balance_after في الدفتر لنفس الوعاء.
 * 2. تسلسل الدفتر متصل: balance_before لكل حركة يساوي balance_after لسابقتها.
 * يُشغَّل دوريًا (جدولة ليلية) ويُرجع كود فشل عند وجود أي فرق للتنبيه.
 */
class ReconcileInventory extends Command
{
    protected $signature = 'inventory:reconcile {--branch_id= : قصر الفحص على فرع واحد} {--all-branches : الفحص الكامل فرعًا فرعًا بنفس الاستعلام (وضع الجدولة الأسبوعية)} {--incremental : فحص المفاتيح المتغيرة فقط} {--limit=1000 : أقصى عدد مفاتيح في الدفعة التراكمية}';

    protected $description = 'مطابقة أرصدة المخزون مع دفتر الحركات وكشف أي كتابة مباشرة';

    public function handle(): int
    {
        $branch = $this->option('branch_id');
        if ($branch !== null && filter_var($branch, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $this->error('branch_id يجب أن يكون عددًا صحيحًا موجبًا.');

            return self::INVALID;
        }

        // M6: فحص شامل واحد يمسح الدفتر كاملًا ويحمل أقفالًا واسعة وقت الذروة —
        // وضع الجدولة يمر على الفروع النشطة واحدًا واحدًا بنفس الاستعلام.
        if ((bool) $this->option('all-branches')) {
            if ($branch !== null) {
                $this->error('مرر branch_id أو all-branches وليس الاثنين معًا.');

                return self::INVALID;
            }
            $failures = 0;
            foreach (Branch::query()->where('is_active', true)->orderBy('id')->pluck('id') as $branchId) {
                $this->info("فحص الفرع {$branchId}...");
                if (StatementBudget::run(300000, fn (): int => $this->reconcile((int) $branchId)) !== self::SUCCESS) {
                    $failures++;
                }
            }
            if ($failures > 0) {
                $this->error("فشلت المطابقة في {$failures} من الفروع.");

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        return StatementBudget::run(300000, fn (): int => $this->reconcile($branch === null ? null : (int) $branch));
    }

    private function reconcile(?int $branch): int
    {

        $incremental = (bool) $this->option('incremental');
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        if ($limit === false) {
            $this->error('limit يجب أن يكون بين 1 و10000.');

            return self::INVALID;
        }
        // One SQL statement sees one committed snapshot, even while sales continue.
        // Window functions also catch broken chains and movements without a stock row.
        $sql = <<<'SQL'
WITH levels AS (
    SELECT * FROM stock_levels WHERE (?::bigint IS NULL OR branch_id = ?::bigint)
), ledger AS (
    SELECT *,
        LAG(balance_after, 1, 0::numeric) OVER (
            PARTITION BY branch_id, location_id, product_variant_id, affected_bucket ORDER BY id
        ) AS previous_balance,
        ROW_NUMBER() OVER (
            PARTITION BY branch_id, location_id, product_variant_id, affected_bucket ORDER BY id DESC
        ) AS recency
    FROM stock_movements WHERE (?::bigint IS NULL OR branch_id = ?::bigint)
), buckets AS (
    SELECT l.id, l.branch_id, l.location_id, l.product_variant_id, b.bucket, b.balance
    FROM levels l CROSS JOIN LATERAL (VALUES
        ('on_hand', l.on_hand), ('reserved', l.reserved),
        ('damaged', l.damaged), ('in_transit', l.in_transit)
    ) b(bucket, balance)
), problems AS (
    SELECT 'chain' AS issue, id, branch_id, location_id, product_variant_id, affected_bucket AS bucket,
        previous_balance AS expected, balance_before AS actual
    FROM ledger WHERE balance_before <> previous_balance
    UNION ALL
    SELECT 'balance', b.id, b.branch_id, b.location_id, b.product_variant_id, b.bucket,
        COALESCE(m.balance_after, 0), b.balance
    FROM buckets b LEFT JOIN ledger m ON m.recency = 1
        AND m.branch_id = b.branch_id AND m.product_variant_id = b.product_variant_id
        AND COALESCE(m.location_id, 0) = COALESCE(b.location_id, 0) AND m.affected_bucket = b.bucket
    WHERE b.balance <> COALESCE(m.balance_after, 0)
    UNION ALL
    SELECT 'missing_level', m.id, m.branch_id, m.location_id, m.product_variant_id,
        m.affected_bucket, m.balance_after, NULL::numeric
    FROM ledger m LEFT JOIN levels l ON l.branch_id = m.branch_id
        AND l.product_variant_id = m.product_variant_id AND COALESCE(l.location_id, 0) = COALESCE(m.location_id, 0)
    WHERE m.recency = 1 AND l.id IS NULL
)
SELECT (SELECT COUNT(*) FROM levels) AS checked, (SELECT COUNT(*) FROM problems) AS problem_count,
    COALESCE((SELECT json_agg(p) FROM (SELECT * FROM problems ORDER BY branch_id, product_variant_id, id LIMIT 100) p), '[]'::json) AS samples
SQL;
        if ($incremental) {
            // Revision comparison survives concurrent commits. A simple MAX(id) cursor
            // would skip a lower sequence ID committed after the reconciliation snapshot.
            $matches = fn (string $alias): string => "EXISTS (SELECT 1 FROM chosen c WHERE c.branch_id = {$alias}.branch_id AND c.product_variant_id = {$alias}.product_variant_id AND COALESCE(c.location_id, 0) = COALESCE({$alias}.location_id, 0))";
            // L13: prefer least-recently-checked keys so a permanently-broken
            // key cannot occupy the LIMIT window forever and starve the rest.
            $sql = str_replace('WITH levels AS (', "WITH chosen AS MATERIALIZED (SELECT * FROM inventory_reconcile_work WHERE (?::bigint IS NULL OR branch_id = ?::bigint) ORDER BY checked_at NULLS FIRST, key LIMIT {$limit}), levels AS (", $sql);
            $sql = str_replace('SELECT * FROM stock_levels WHERE', 'SELECT * FROM stock_levels WHERE '.$matches('stock_levels').' AND', $sql);
            $sql = str_replace('FROM stock_movements WHERE', 'FROM stock_movements WHERE '.$matches('stock_movements').' AND', $sql);
            $sql = str_replace("\nSELECT (SELECT COUNT(*) FROM levels)", <<<'SQL'
, cleaned AS (
    DELETE FROM inventory_reconcile_work w USING chosen c
    WHERE w.key = c.key AND w.revision = c.revision
        AND NOT EXISTS (SELECT 1 FROM problems p WHERE p.branch_id = c.branch_id
            AND p.product_variant_id = c.product_variant_id AND COALESCE(p.location_id, 0) = COALESCE(c.location_id, 0))
    RETURNING w.key
)
SELECT (SELECT COUNT(*) FROM levels)
SQL, $sql);
            $sql = str_replace(
                "COALESCE((SELECT json_agg(p) FROM (SELECT * FROM problems ORDER BY branch_id, product_variant_id, id LIMIT 100) p), '[]'::json) AS samples",
                "COALESCE((SELECT json_agg(p) FROM (SELECT * FROM problems ORDER BY branch_id, product_variant_id, id LIMIT 100) p), '[]'::json) AS samples, (SELECT COALESCE(json_agg(key), '[]'::json) FROM chosen) AS examined",
                $sql
            );
        }
        $bindings = array_fill(0, $incremental ? 6 : 4, $branch);
        $result = DB::selectOne($sql, $bindings);

        if ($incremental) {
            // L13: mark everything just examined — permanent problems sink
            // below unchecked keys on the next run instead of starving them.
            $examined = json_decode((string) ($result->examined ?? '[]'), true) ?? [];
            if ($examined !== []) {
                DB::table('inventory_reconcile_work')->whereIn('key', $examined)->update(['checked_at' => now()]);
            }
        }

        // آخر نتيجة محفوظة لأداة inventory_health (المطابقة الكاملة أثقل من
        // حد زمن الأدوات، فالأداة تقرأ آخر تشغيل مجدول بدل تشغيل الفحص).
        InventoryReconcileRun::query()->create([
            'branch_id' => $branch !== null ? (int) $branch : null,
            'checked' => (int) $result->checked,
            'problem_count' => (int) $result->problem_count,
            'samples' => json_decode((string) $result->samples, true) ?? [],
        ]);

        // L11: التشغيل التراكمي كل 15 دقيقة يخلف ~96 صفًا يوميًا — إبقاء
        // أسبوعين فقط من سجل التشغيلات.
        InventoryReconcileRun::query()->where('created_at', '<', now()->subDays(14))->delete();

        if ((int) $result->problem_count > 0) {
            $samples = json_decode($result->samples, true, flags: JSON_THROW_ON_ERROR);
            foreach ($samples as $problem) {
                $this->error(json_encode($problem, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            }
            Log::error('Inventory reconciliation failed', ['problem_count' => (int) $result->problem_count, 'samples' => $samples]);
            $this->error("المطابقة فشلت: {$result->problem_count} فرق في {$result->checked} صف رصيد.");

            return self::FAILURE;
        }

        $this->info("المطابقة سليمة: {$result->checked} صف رصيد مطابق للدفتر.");

        return self::SUCCESS;
    }
}
```

## File: bags-platform/backend/app/Http/Controllers/Api/V1/Pos/PosStockController.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Pos;

use App\Enums\StocktakeStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\StockLevelResource;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\Stocktake;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\SearchTerm;
use App\Services\StocktakeService;
use App\Services\TransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Handles POS terminal inventory queries, branch transfer requests, incoming receipts, and barcode stocktake scans.
 */
class PosStockController extends Controller
{
    public function __construct(
        private readonly TransferService $transfers,
        private readonly StocktakeService $stocktakes,
    ) {}

    /**
     * Lists current branch inventory stock levels with search and low-stock filters.
     *
     * @param Request $request Filter query request
     * @return AnonymousResourceCollection Paginated stock level resources
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'product_variant_id' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:100'],
            'low_stock' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        /** @var User $user */
        $user = $request->user('staff');

        // L7 (documented non-control, not a blind count): on_hand stays
        // visible to counters because the same handset sells from it — the
        // session relies on the snapshot/stale-movement guards at approve
        // time (StocktakeService) instead of balance secrecy.
        $levels = StockLevel::query()
            ->where('branch_id', $user->branch_id)
            ->with(['variant.product', 'location'])
            ->when($filters['product_variant_id'] ?? null, fn ($q, $v) => $q->where('product_variant_id', $v))
            ->when($filters['q'] ?? null, function ($q, $v): void {
                // Escape LIKE wildcard characters to avoid malformed patterns and unintentional wildcards.
                $like = SearchTerm::pattern((string) $v);
                $q->whereHas('variant', fn ($vq) => $vq->where('sku', $v)
                    ->orWhere('barcode', $v)
                    ->orWhereHas('product', fn ($pq) => $pq
                        ->where('name_ar', 'LIKE', $like)
                        ->orWhere('name_en', 'LIKE', $like)));
            })
            // L6: نفس شرط التنبيهات — حد الصف على الصف، وحد الصنف على إجمالي الفرع.
            ->when($filters['low_stock'] ?? null, fn ($q) => $q->whereLowStock())
            ->orderBy('product_variant_id')
            ->paginate($filters['per_page'] ?? 15);

        return StockLevelResource::collection($levels);
    }

    /**
     * Requests stock transfer from another active branch into the cashier's branch.
     *
     * @param Request $request Transfer request payload
     * @return JsonResponse Created transfer response
     */
    public function requestTransfer(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('staff');
        abort_unless($user->can('transfers.create'), 403);

        $data = $request->validate([
            'from_branch_id' => ['required', 'integer', 'min:1',
                Rule::exists('branches', 'id')->where('is_active', true),
                Rule::notIn([$user->branch_id]),
            ],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            // L9: reject inactive variants here instead of failing later at approve time.
            'items.*.product_variant_id' => ['required', 'integer', 'min:1', 'distinct',
                Rule::exists('product_variants', 'id')->where('is_active', true)],
            'items.*.from_location_id' => ['nullable', 'integer', 'min:1',
                Rule::exists('locations', 'id')->where('branch_id', (int) $request->input('from_branch_id')),
            ],
            'items.*.to_location_id' => ['nullable', 'integer', 'min:1',
                Rule::exists('locations', 'id')->where('branch_id', (int) $user->branch_id),
            ],
            'items.*.requested_qty' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:99999999.9999'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $data['to_branch_id'] = $user->branch_id;

        $transfer = $this->transfers->create($data, $user->id, $this->requiredIdempotencyKey($request));

        return response()->json([
            'message' => 'تم إرسال طلب التحويل بنجاح وهو في انتظار الاعتماد.',
            'transfer_number' => $transfer->transfer_number,
            'id' => $transfer->id,
        ], 201);
    }

    /**
     * Confirms receipt of an incoming transfer at the cashier's branch.
     *
     * @param Request $request Receipt request payload
     * @param StockTransfer $transfer Target transfer model
     * @return JsonResponse Receipt confirmation response
     */
    public function receiveTransfer(Request $request, StockTransfer $transfer): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('staff');
        abort_unless($user->can('transfers.receive'), 403);

        if ((int) $transfer->to_branch_id !== (int) $user->branch_id) {
            return response()->json(['message' => 'هذه التحويلة ليست موجهة لفرعك.'], 403);
        }

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.id' => ['required', 'integer', 'min:1', 'distinct',
                Rule::exists('stock_transfer_items', 'id')->where('stock_transfer_id', $transfer->id),
            ],
            'items.*.received_qty' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:99999999.9999'],
        ]);

        $quantities = [];
        foreach ($data['items'] as $item) {
            $quantities[(int) $item['id']] = (float) $item['received_qty'];
        }

        $transfer = $this->transfers->receive($transfer, $quantities, $user->id);

        return response()->json([
            'message' => 'تم تأكيد استلام التحويلة بنجاح وتم رفع المخزون.',
            'id' => $transfer->id,
            'status' => $transfer->status->value,
        ]);
    }

    /**
     * Records barcode scan count against an open stocktake session.
     *
     * @param Request $request Scan request containing barcode and optional quantity
     * @param Stocktake $stocktake Target stocktake session
     * @return JsonResponse Scan registration response
     */
    public function scan(Request $request, Stocktake $stocktake): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('staff');
        abort_unless($user->can('stocktakes.update'), 403);

        if ((int) $stocktake->branch_id !== (int) $user->branch_id) {
            return response()->json(['message' => 'جلسة الجرد لا تتبع فرعك.'], 403);
        }
        app(BranchAccess::class)->assertBranch($user->id, $stocktake->branch_id);

        if (! in_array($stocktake->status, [StocktakeStatus::Draft, StocktakeStatus::InProgress], true)) {
            return response()->json(['message' => 'جلسة الجرد ليست مفتوحة.'], 422);
        }

        $data = $request->validate([
            'barcode' => ['required', 'string', 'max:100'],
            'qty' => ['nullable', 'numeric', 'decimal:0,4', 'min:0.0001', 'max:99999'],
            'qty_words' => ['nullable', 'string', 'max:50'],
            'scan_id' => ['nullable', 'uuid'],
        ]);

        // وضع العد الصوتي: الموظف يمسح الباركود ويقول الرقم ("اتنين وعشرين").
        // system_qty يفضل محجوبًا عن غير المصرح لهم بالاعتماد (نفس القاعدة أدناه).
        $qty = isset($data['qty']) ? (float) $data['qty'] : 1;
        if (! isset($data['qty']) && isset($data['qty_words'])) {
            $spoken = \App\Services\SpokenQuantity::parse($data['qty_words']);
            if ($spoken === null || $spoken < 1) {
                return response()->json(['message' => 'العدد المنطوق مش واضح؛ قوله تاني أو اكتبه رقمًا.'], 422);
            }
            $qty = $spoken;
        }

        $variant = ProductVariant::query()
            ->where('barcode', $data['barcode'])
            ->where('is_active', true)
            ->first();

        if ($variant === null) {
            return response()->json([
                'message' => 'لم يُعثر على صنف بهذا الباركود.',
                'barcode' => $data['barcode'],
            ], 404);
        }

        $before = $stocktake->items->firstWhere('product_variant_id', $variant->id)?->counted_qty;

        $stocktake = $this->stocktakes->incrementItemCount($stocktake, $variant->id, $qty, $user->id, $data['scan_id'] ?? null);

        $item = $stocktake->items->firstWhere('product_variant_id', $variant->id);

        $response = [
            'message' => 'تم تسجيل المسح بنجاح.',
            'product_variant_id' => $variant->id,
            'sku' => $variant->sku,
            'counted_qty' => $item?->counted_qty,
        ];
        if (($data['scan_id'] ?? null) !== null && (float) ($item?->counted_qty ?? 0) === (float) ($before ?? 0)) {
            $response['duplicate'] = true;
        }
        if ($user->can('stocktakes.approve')) {
            $response['system_qty'] = $item?->system_qty;
        }

        return response()->json($response);
    }
}
```

## File: bags-platform/backend/app/Services/TransferService.php
```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Enums\TransferStatus;
use App\Events\TransferPendingApproval;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Services\Concerns\HandlesIdempotentCreation;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Inter-branch inventory transfer service managing draft, approval, dispatch, and receiving stages.
 *
 * All inventory ledger side effects are coordinated exclusively via InventoryService:
 * - draft: No inventory side effects.
 * - approve: Reserves approved quantities at source branch to prevent concurrent overselling.
 * - ship: Releases source reserve, issues TransferOut from on_hand, and records TransferInTransit at destination.
 * - receive: Clears TransferTransitRelease from in_transit, adds TransferIn to on_hand, and writes off shortages.
 */
class TransferService
{
    use HandlesIdempotentCreation;

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly SettingService $settings,
    ) {}

    /**
     * Paginates inter-branch transfers filtered by branch and status.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return StockTransfer::query()
            ->when($filters['staff_branch_id'] ?? null, fn ($q, $branch) => $q->where(fn ($scope) => $scope->where('from_branch_id', $branch)->orWhere('to_branch_id', $branch)))
            ->with(['fromBranch', 'toBranch', 'creator'])
            ->when($filters['from_branch_id'] ?? null, fn ($q, $v) => $q->where('from_branch_id', $v))
            ->when($filters['to_branch_id'] ?? null, fn ($q, $v) => $q->where('to_branch_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Retrieves a single stock transfer with items, branches, and audit relations.
     */
    public function find(int $id): StockTransfer
    {
        return StockTransfer::query()
            ->with(['items.variant', 'items.fromLocation', 'items.toLocation', 'fromBranch', 'toBranch', 'creator', 'approver'])
            ->findOrFail($id);
    }

    /**
     * Creates a new stock transfer draft with optional idempotency protection.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, int $userId, ?string $idempotencyKey = null): StockTransfer
    {
        // Transfers may be initiated from source (warehouse dispatch) or destination branch (POS replenishment request).
        $this->assertCreateAccess($userId, (int) $data['from_branch_id'], (int) $data['to_branch_id']);

        /** @var StockTransfer */
        return $this->createIdempotently(
            'transfer_store',
            $data,
            $userId,
            $idempotencyKey,
            StockTransfer::class,
            ['items.variant', 'fromBranch', 'toBranch', 'creator'],
            fn (): StockTransfer => $this->doCreate($data, $userId),
        );
    }

    /**
     * Persists new draft transfer and dispatches approval notification event.
     *
     * @param  array<string, mixed>  $data
     */
    private function doCreate(array $data, int $userId): StockTransfer
    {
        return DB::transaction(function () use ($data, $userId): StockTransfer {
            $this->assertCreateAccess($userId, (int) $data['from_branch_id'], (int) $data['to_branch_id']);
            $transfer = StockTransfer::query()->create([
                'transfer_number' => $this->settings->generateNextNumber('transfer'),
                'from_branch_id' => $data['from_branch_id'],
                'to_branch_id' => $data['to_branch_id'],
                'status' => TransferStatus::Draft,
                'created_by' => $userId,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncItems($transfer, $data['items']);

            $created = $transfer->load(['items.variant', 'fromBranch', 'toBranch', 'creator']);
            TransferPendingApproval::dispatch($created);

            return $created;
        });
    }

    /**
     * Updates transfer header and item lines while in draft status under pessimistic row lock.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(StockTransfer $transfer, array $data, int $userId): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $data, $userId): StockTransfer {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $this->assertStatus($transfer, TransferStatus::Draft, 'التعديل');
            app(BranchAccess::class)->assertBranch($userId, $transfer->from_branch_id);
            app(BranchAccess::class)->assertBranch($userId, (int) ($data['from_branch_id'] ?? $transfer->from_branch_id));

            $fromBranch = $data['from_branch_id'] ?? $transfer->from_branch_id;
            $toBranch = $data['to_branch_id'] ?? $transfer->to_branch_id;
            if ((int) $fromBranch === (int) $toBranch) {
                throw new BusinessException('يجب اختلاف الفرع المرسل عن الفرع المستلم.');
            }
            $items = $data['items'] ?? $transfer->items->toArray();
            foreach ($items as $item) {
                foreach (['from_location_id' => $fromBranch, 'to_location_id' => $toBranch] as $field => $branchId) {
                    if (isset($item[$field]) && ! Location::query()->whereKey($item[$field])->where('branch_id', $branchId)->exists()) {
                        throw new BusinessException('موقع التحويل لا يتبع الفرع المحدد.');
                    }
                }
            }

            $transfer->update([
                'from_branch_id' => $data['from_branch_id'] ?? $transfer->from_branch_id,
                'to_branch_id' => $data['to_branch_id'] ?? $transfer->to_branch_id,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $transfer->notes,
            ]);

            if (isset($data['items'])) {
                $transfer->items()->delete();
                $this->syncItems($transfer, $data['items']);
            }

            return $transfer->load(['items.variant', 'fromBranch', 'toBranch', 'creator']);
        });
    }

    /**
     * Approves transfer lines and reserves inventory at source branch to guarantee shipment stock.
     *
     * @param  array<int, float|int|string>  $itemsPayload  Map of transfer item ID to approved quantity.
     */
    public function approve(StockTransfer $transfer, array $itemsPayload, int $userId, ?string $expectedUpdatedAt = null, ?int $expectedVersion = null): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $itemsPayload, $userId, $expectedUpdatedAt, $expectedVersion): StockTransfer {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $transfer->load('items.variant');
            app(BranchAccess::class)->assertBranch($userId, $transfer->from_branch_id);
            $this->assertPayloadMatchesLockedItems($transfer, $itemsPayload, $expectedUpdatedAt, $expectedVersion);
            $this->assertStatus($transfer, TransferStatus::Draft, 'الاعتماد');
            // Early validation prevents approving transfers targeted at inactive or unreceivable branches.
            $this->assertReceivableDestination($transfer);
            if ($transfer->items->sum(fn ($item) => (float) ($itemsPayload[$item->id] ?? $item->requested_qty)) <= 0) {
                throw new BusinessException('يجب اعتماد كمية موجبة واحدة على الأقل؛ يمكن إلغاء التحويل بدلًا من اعتماده بصفر.');
            }
            $levels = $this->lockTransferLevels($transfer, true, false);
            $qtyUpdates = [];

            foreach ($transfer->items as $item) {
                $approvedQty = (float) ($itemsPayload[$item->id] ?? $item->requested_qty);

                if ($approvedQty < 0 || $approvedQty > (float) $item->requested_qty) {
                    throw new BusinessException('الكمية المعتمدة يجب أن تكون بين صفر والكمية المطلوبة.');
                }

                // Collect quantity updates into a single batch upsert to minimize lock holding duration.
                $qtyUpdates[] = [...$item->only(['id', 'stock_transfer_id', 'product_variant_id', 'from_location_id', 'to_location_id', 'requested_qty', 'approved_qty', 'shipped_qty', 'received_qty', 'created_at']), 'approved_qty' => $approvedQty, 'updated_at' => now()];

                if ($approvedQty > 0) {
                    $this->move([
                        'product_variant_id' => $item->product_variant_id,
                        'branch_id' => $transfer->from_branch_id,
                        'location_id' => $item->from_location_id,
                        'type' => StockMovementType::Reserve,
                        'affected_bucket' => StockBucket::Reserved,
                        'quantity' => $approvedQty,
                        'unit_cost' => $item->variant?->cost_price,
                        'reference_type' => StockTransfer::class,
                        'reference_id' => $transfer->id,
                        'created_by' => $userId,
                        'notes' => 'حجز لصالح تحويل '.$transfer->transfer_number,
                    ], $levels);
                }
            }

            if ($qtyUpdates !== []) {
                StockTransferItem::query()->upsert($qtyUpdates, ['id'], ['approved_qty', 'updated_at']);
            }

            $transfer->update(['status' => TransferStatus::Approved, 'approved_by' => $userId]);

            return $transfer->load(['items.variant', 'fromBranch', 'toBranch']);
        });
    }

    /**
     * Dispatch approved stock from source branch and move into destination in-transit bucket.
     *
     * Releases source reservations, deducts physical on-hand inventory, returns any unshipped
     * approved quantity back to available stock, and registers items as in-transit at destination.
     *
     * @param  StockTransfer  $transfer
     * @param  array<int, float|int|string>  $itemsPayload  Map of transfer item ID to shipped quantity
     * @param  int  $userId
     * @param  string|null  $expectedUpdatedAt
     * @param  int|null  $expectedVersion
     * @return StockTransfer
     *
     * @throws BusinessException
     */
    public function ship(StockTransfer $transfer, array $itemsPayload, int $userId, ?string $expectedUpdatedAt = null, ?int $expectedVersion = null): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $itemsPayload, $userId, $expectedUpdatedAt, $expectedVersion): StockTransfer {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $transfer->load('items.variant');
            app(BranchAccess::class)->assertBranch($userId, $transfer->from_branch_id);
            if (in_array($transfer->status, [TransferStatus::Shipped, TransferStatus::Received], true)) {
                return $this->replayCompletedStage($transfer, $itemsPayload, 'shipped_qty');
            }
            $this->assertPayloadMatchesLockedItems($transfer, $itemsPayload, $expectedUpdatedAt, $expectedVersion);
            $this->assertStatus($transfer, TransferStatus::Approved, 'الشحن');
            // Fail early if destination branch or locations were deactivated between approval and shipping
            $this->assertReceivableDestination($transfer);
            if ($transfer->items->sum(fn ($item) => (float) ($itemsPayload[$item->id] ?? $item->approved_qty)) <= 0) {
                throw new BusinessException('يجب شحن كمية موجبة واحدة على الأقل؛ يمكن إلغاء التحويل لفك الحجز.');
            }
            $levels = $this->lockTransferLevels($transfer, true, true);
            $qtyUpdates = [];

            foreach ($transfer->items as $item) {
                $shippedQty = (float) ($itemsPayload[$item->id] ?? $item->approved_qty);

                if ($shippedQty < 0 || $shippedQty > (float) $item->approved_qty) {
                    throw new BusinessException('الكمية المشحونة يجب أن تكون بين صفر والكمية المعتمدة.');
                }

                $qtyUpdates[] = [...$item->only(['id', 'stock_transfer_id', 'product_variant_id', 'from_location_id', 'to_location_id', 'requested_qty', 'approved_qty', 'shipped_qty', 'received_qty', 'created_at']), 'shipped_qty' => $shippedQty, 'updated_at' => now()];

                if ($shippedQty <= 0) {
                    if ((float) $item->approved_qty > 0) {
                        $this->move([
                            'product_variant_id' => $item->product_variant_id,
                            'branch_id' => $transfer->from_branch_id,
                            'location_id' => $item->from_location_id,
                            'type' => StockMovementType::ReleaseReserve,
                            'affected_bucket' => StockBucket::Reserved,
                            'quantity' => -(float) $item->approved_qty,
                            'reference_type' => StockTransfer::class,
                            'reference_id' => $transfer->id,
                            'created_by' => $userId,
                            'notes' => 'فك حجز صنف غير مشحون من تحويل '.$transfer->transfer_number,
                        ], $levels);
                    }

                    continue;
                }

                $base = [
                    'product_variant_id' => $item->product_variant_id,
                    'unit_cost' => $item->variant?->cost_price,
                    'reference_type' => StockTransfer::class,
                    'reference_id' => $transfer->id,
                    'created_by' => $userId,
                ];

                // Release source reservation before recording physical deduction from on-hand
                $this->move([...$base,
                    'branch_id' => $transfer->from_branch_id,
                    'location_id' => $item->from_location_id,
                    'type' => StockMovementType::ReleaseReserve,
                    'affected_bucket' => StockBucket::Reserved,
                    'quantity' => -$shippedQty,
                    'notes' => 'فك حجز عند شحن تحويل '.$transfer->transfer_number,
                ], $levels);
                $this->move([...$base,
                    'branch_id' => $transfer->from_branch_id,
                    'location_id' => $item->from_location_id,
                    'type' => StockMovementType::TransferOut,
                    'affected_bucket' => StockBucket::OnHand,
                    'quantity' => -$shippedQty,
                    'notes' => 'شحن تحويل '.$transfer->transfer_number,
                ], $levels);

                // Release approved but unshipped quantity back to available inventory
                $unshipped = round((float) $item->approved_qty - $shippedQty, 4);
                if ($unshipped > 0) {
                    $this->move([...$base,
                        'branch_id' => $transfer->from_branch_id,
                        'location_id' => $item->from_location_id,
                        'type' => StockMovementType::ReleaseReserve,
                        'affected_bucket' => StockBucket::Reserved,
                        'quantity' => -$unshipped,
                        'notes' => 'فك حجز الكمية المعتمدة غير المشحونة من تحويل '.$transfer->transfer_number,
                    ], $levels);
                }

                // Increment in-transit stock at the destination branch/location
                $this->move([...$base,
                    'branch_id' => $transfer->to_branch_id,
                    'location_id' => $item->to_location_id,
                    'type' => StockMovementType::TransferInTransit,
                    'affected_bucket' => StockBucket::InTransit,
                    'quantity' => $shippedQty,
                    'notes' => 'بضاعة في الطريق من تحويل '.$transfer->transfer_number,
                ], $levels);
            }

            if ($qtyUpdates !== []) {
                StockTransferItem::query()->upsert($qtyUpdates, ['id'], ['shipped_qty', 'updated_at']);
            }

            $transfer->update(['status' => TransferStatus::Shipped, 'shipped_at' => now()]);

            return $transfer->load(['items.variant', 'fromBranch', 'toBranch']);
        });
    }

    /**
     * Intake shipped inventory at the destination branch and settle in-transit stock.
     *
     * Moves verified goods from in-transit to on-hand. Any difference between shipped
     * and received quantity is deducted as an explicit shortage ledger movement.
     *
     * @param  StockTransfer  $transfer
     * @param  array<int, float|int|string>  $itemsPayload  Map of transfer item ID to received quantity
     * @param  int  $userId
     * @param  string|null  $expectedUpdatedAt
     * @param  int|null  $expectedVersion
     * @return StockTransfer
     *
     * @throws BusinessException
     */
    public function receive(StockTransfer $transfer, array $itemsPayload, int $userId, ?string $expectedUpdatedAt = null, ?int $expectedVersion = null): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $itemsPayload, $userId, $expectedUpdatedAt, $expectedVersion): StockTransfer {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $transfer->load('items.variant');
            app(BranchAccess::class)->assertBranch($userId, $transfer->to_branch_id);
            // Verify destination branch remains active before accepting inbound goods
            $toBranch = Branch::query()->find($transfer->to_branch_id);
            if ($toBranch === null || ! $toBranch->is_active) {
                throw new BusinessException('الفرع المستلم غير نشط؛ لا يمكن الاستلام إليه.');
            }
            if ($transfer->status === TransferStatus::Received) {
                return $this->replayCompletedStage($transfer, $itemsPayload, 'received_qty');
            }
            $this->assertPayloadMatchesLockedItems($transfer, $itemsPayload, $expectedUpdatedAt, $expectedVersion);
            $this->assertStatus($transfer, TransferStatus::Shipped, 'الاستلام');
            $levels = $this->lockTransferLevels($transfer, false, true);

            $shortageNotes = [];
            $qtyUpdates = [];

            foreach ($transfer->items as $item) {
                $receivedQty = (float) ($itemsPayload[$item->id] ?? $item->shipped_qty);
                $shippedQty = (float) $item->shipped_qty;

                if ($receivedQty < 0 || $receivedQty > $shippedQty) {
                    throw new BusinessException('الكمية المستلمة يجب أن تكون بين صفر والكمية المشحونة.');
                }

                $qtyUpdates[] = [...$item->only(['id', 'stock_transfer_id', 'product_variant_id', 'from_location_id', 'to_location_id', 'requested_qty', 'approved_qty', 'shipped_qty', 'received_qty', 'created_at']), 'received_qty' => $receivedQty, 'updated_at' => now()];

                if ($shippedQty <= 0) {
                    continue;
                }

                $base = [
                    'product_variant_id' => $item->product_variant_id,
                    'branch_id' => $transfer->to_branch_id,
                    'location_id' => $item->to_location_id,
                    'unit_cost' => $item->variant?->cost_price,
                    'reference_type' => StockTransfer::class,
                    'reference_id' => $transfer->id,
                    'created_by' => $userId,
                ];

                // Deduct accepted quantity from in-transit and post directly to destination on-hand
                if ($receivedQty > 0) {
                    $this->move([...$base,
                        'type' => StockMovementType::TransferTransitRelease,
                        'affected_bucket' => StockBucket::InTransit,
                        'quantity' => -$receivedQty,
                        'notes' => 'استلام تحويل '.$transfer->transfer_number,
                    ], $levels);
                    $this->move([...$base,
                        'type' => StockMovementType::TransferIn,
                        'affected_bucket' => StockBucket::OnHand,
                        'quantity' => $receivedQty,
                        'notes' => 'استلام تحويل '.$transfer->transfer_number,
                    ], $levels);
                }

                // Any shortage is deducted from in-transit as an explicit shortage movement for loss tracking
                $shortage = round($shippedQty - $receivedQty, 4);
                if ($shortage > 0) {
                    $this->move([...$base,
                        'type' => StockMovementType::TransferShortage,
                        'affected_bucket' => StockBucket::InTransit,
                        'quantity' => -$shortage,
                        'notes' => 'عجز عند استلام تحويل '.$transfer->transfer_number,
                    ], $levels);
                    $shortageNotes[] = "عجز {$shortage} وحدة في المتغير #{$item->product_variant_id}";
                }
            }

            if ($qtyUpdates !== []) {
                StockTransferItem::query()->upsert($qtyUpdates, ['id'], ['received_qty', 'updated_at']);
            }

            $notes = $transfer->notes;
            if ($shortageNotes !== []) {
                $notes = trim(($notes ? $notes.' | ' : '').implode('؛ ', $shortageNotes));
            }

            $transfer->update(['status' => TransferStatus::Received, 'received_at' => now(), 'notes' => $notes]);

            return $transfer->load(['items.variant', 'fromBranch', 'toBranch']);
        });
    }

    /**
     * Cancel an active transfer in draft or approved status.
     *
     * Once approved, cancelling releases reserved inventory back to available stock.
     * Shipped transfers cannot be cancelled and must proceed to intake/settlement.
     *
     * @param  StockTransfer  $transfer
     * @param  int  $userId
     * @return StockTransfer
     *
     * @throws BusinessException
     */
    public function cancel(StockTransfer $transfer, int $userId): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $userId): StockTransfer {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $transfer->load('items.variant');
            app(BranchAccess::class)->assertBranch($userId, $transfer->from_branch_id);

            if ($transfer->status === TransferStatus::Approved) {
                $levels = $this->lockTransferLevels($transfer, true, false);
                foreach ($transfer->items as $item) {
                    if ((float) $item->approved_qty <= 0) {
                        continue;
                    }

                    $this->move([
                        'product_variant_id' => $item->product_variant_id,
                        'branch_id' => $transfer->from_branch_id,
                        'location_id' => $item->from_location_id,
                        'type' => StockMovementType::ReleaseReserve,
                        'affected_bucket' => StockBucket::Reserved,
                        'quantity' => -(float) $item->approved_qty,
                        'reference_type' => StockTransfer::class,
                        'reference_id' => $transfer->id,
                        'created_by' => $userId,
                        'notes' => 'فك حجز عند إلغاء تحويل '.$transfer->transfer_number,
                    ], $levels);
                }
            } elseif ($transfer->status !== TransferStatus::Draft) {
                throw new BusinessException('لا يمكن إلغاء تحويل بحالة '.$transfer->status->value.'.');
            }

            $transfer->update(['status' => TransferStatus::Cancelled]);

            return $transfer->load(['items.variant', 'fromBranch', 'toBranch']);
        });
    }

    /**
     * Delete a draft or cancelled transfer.
     *
     * Transfers that have associated inventory ledger movements cannot be deleted
     * to preserve audit trails and prevent orphaned reference records.
     *
     * @param  StockTransfer  $transfer
     * @param  int  $userId
     * @return void
     *
     * @throws BusinessException
     */
    public function delete(StockTransfer $transfer, int $userId): void
    {
        DB::transaction(function () use ($transfer, $userId): void {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            app(BranchAccess::class)->assertBranch($userId, $transfer->from_branch_id);

            if (! in_array($transfer->status, [TransferStatus::Draft, TransferStatus::Cancelled], true)) {
                throw new BusinessException('لا يمكن حذف تحويل إلا في حالة المسودة أو الملغي.');
            }

            // Transfers with posted ledger movements must be preserved for financial and audit trails
            if (StockMovement::query()->whereIn('reference_type', [StockTransfer::class, 'stock_transfer'])->where('reference_id', $transfer->id)->exists()) {
                throw new BusinessException('لا يمكن حذف تحويل له حركات مخزون مسجلة في الدفتر؛ إلغاؤه يحفظ أثر التدقيق.');
            }

            $transfer->delete();
        });
    }

    /**
     * Prevent stale submission payloads and concurrent overwrites.
     *
     * Validates item IDs against locked items to catch deletions/swaps between request parsing
     * and lock acquisition, and verifies optimistic concurrency controls (version and updated_at).
     */
    private function assertPayloadMatchesLockedItems(StockTransfer $transfer, array $itemsPayload, ?string $expectedUpdatedAt, ?int $expectedVersion = null): void
    {
        if ($expectedUpdatedAt !== null && $expectedVersion === null) {
            throw new BusinessException('أعد تحميل التحويل لإرسال رقم الإصدار قبل التنفيذ.', [], 409);
        }
        if ($expectedVersion !== null && $expectedVersion !== $transfer->version) {
            throw new BusinessException('تم تعديل التحويل؛ أعد تحميله وراجع الكميات.', ['current_version' => $transfer->version], 409);
        }

        $unknownIds = array_diff(array_map('intval', array_keys($itemsPayload)), $transfer->items->pluck('id')->map(fn ($id) => (int) $id)->all());

        if ($unknownIds !== []) {
            throw new BusinessException(
                'توجد عناصر في الطلب لا تتبع هذا التحويل (المعرّفات: '.implode(', ', $unknownIds).'). أعد تحميل التحويل وراجع الكميات.',
                ['unknown_item_ids' => array_values($unknownIds)],
                409,
            );
        }

        if ($expectedUpdatedAt !== null
            && ! Carbon::parse($expectedUpdatedAt)->equalTo($transfer->updated_at)) {
            throw new BusinessException(
                'تم تعديل هذا التحويل بعد تحميله. أعد تحميله وراجع الكميات قبل التنفيذ.',
                ['current_updated_at' => $transfer->updated_at->toIso8601String()],
                409,
            );
        }
    }

    private function replayCompletedStage(StockTransfer $transfer, array $itemsPayload, string $quantityField): StockTransfer
    {
        $this->assertPayloadMatchesLockedItems($transfer, $itemsPayload, null);
        foreach ($transfer->items as $item) {
            if (array_key_exists($item->id, $itemsPayload)
                && (int) round((float) $itemsPayload[$item->id] * 10000) !== (int) round((float) $item->{$quantityField} * 10000)) {
                throw new BusinessException('تم تنفيذ المرحلة بكميات مختلفة؛ أعد تحميل التحويل.', [], 409);
            }
        }

        return $transfer->load(['fromBranch', 'toBranch']);
    }

    /**
     * Authorize transfer initiation from either source or destination branch.
     *
     * Transfers can be initiated by source warehouse dispatchers or destination store managers.
     */
    private function assertCreateAccess(int $userId, int $fromBranchId, int $toBranchId): void
    {
        try {
            app(BranchAccess::class)->assertBranch($userId, $fromBranchId);
        } catch (AuthorizationException) {
            app(BranchAccess::class)->assertBranch($userId, $toBranchId);
        }
    }

    private function assertStatus(StockTransfer $transfer, TransferStatus $expected, string $action): void
    {
        if ($transfer->status !== $expected) {
            throw new BusinessException("لا يمكن تنفيذ {$action} على تحويل بحالة {$transfer->status->value}.");
        }
    }

    /**
     * Validate destination branch, locations, and variants before dispatch.
     *
     * Prevents inventory from getting trapped in transit if the destination branch
     * or receiving location was archived or deactivated after transfer creation.
     */
    private function assertReceivableDestination(StockTransfer $transfer): void
    {
        $toBranch = Branch::query()->sharedLock()->find($transfer->to_branch_id);
        if ($toBranch === null || ! $toBranch->is_active) {
            throw new BusinessException('الفرع الوجهة غير نشط؛ لا يمكن اعتماد أو شحن التحويل إليه.');
        }

        $variantIds = $transfer->items->map(fn ($item) => (int) $item->product_variant_id)->unique()->values()->all();
        $existingVariantIds = ProductVariant::withTrashed()->whereIn('id', $variantIds)
            ->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $missing = array_diff($variantIds, $existingVariantIds);
        if ($missing !== []) {
            throw new BusinessException('أحد أصناف التحويل غير نشط أو لم يعد موجودًا؛ راجع البنود قبل المتابعة.');
        }

        foreach ($transfer->items as $item) {
            if ($item->to_location_id !== null && ! Location::query()->whereKey($item->to_location_id)
                ->where('branch_id', $transfer->to_branch_id)->where('is_active', true)->exists()) {
                throw new BusinessException('موقع الاستلام غير نشط أو لا يتبع الفرع الوجهة.');
            }
        }
    }

    /**
     * @param  list<array{product_variant_id: int, from_location_id?: int|null, to_location_id?: int|null, requested_qty: float|int|string}>  $items
     */
    private function syncItems(StockTransfer $transfer, array $items): void
    {
        $this->assertDistinctItems($items);
        foreach ($items as $item) {
            $transfer->items()->create([
                'product_variant_id' => $item['product_variant_id'],
                'from_location_id' => $item['from_location_id'] ?? null,
                'to_location_id' => $item['to_location_id'] ?? null,
                'requested_qty' => $item['requested_qty'],
            ]);
        }
    }

    private function assertDistinctItems(array $items): void
    {
        $keys = array_map(fn ($item) => $item['product_variant_id'].':'.($item['from_location_id'] ?? 0).':'.($item['to_location_id'] ?? 0), $items);
        if (count(array_unique($keys)) !== count($keys)) {
            throw new BusinessException('لا يجوز تكرار نفس الصنف وموقعي الإرسال والاستلام داخل التحويل.');
        }
    }

    /**
     * Post movement using pre-locked stock level instances to bypass re-locking overhead.
     */
    private function move(array $data, Collection $levels): void
    {
        $level = $levels->get($this->inventory->levelKey([
            'product_variant_id' => $data['product_variant_id'],
            'branch_id' => $data['branch_id'],
            'location_id' => $data['location_id'] ?? null,
        ]));

        $this->inventory->recordMovement($data, $level);
    }

    private function lockTransferLevels(StockTransfer $transfer, bool $source, bool $destination): Collection
    {
        $keys = [];
        foreach ($transfer->items as $item) {
            if ($source) {
                $keys[] = ['branch_id' => $transfer->from_branch_id, 'location_id' => $item->from_location_id, 'product_variant_id' => $item->product_variant_id];
            }
            if ($destination) {
                $keys[] = ['branch_id' => $transfer->to_branch_id, 'location_id' => $item->to_location_id, 'product_variant_id' => $item->product_variant_id];
            }
        }

        return $this->inventory->lockLevels($keys);
    }
}
```

## File: bags-platform/backend/app/Http/Controllers/Api/V1/Admin/ProductionReceiptController.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Inventory\StoreProductionReceiptRequest;
use App\Models\Branch;
use App\Models\ProductionReceipt;
use App\Models\ProductVariant;
use App\Services\BranchAccess;
use App\Services\CostAccess;
use App\Services\ProductionReceiptService;
use App\Services\SearchTerm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ProductionReceiptController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:inventory.view', only: ['options', 'index']),
        ];
    }

    public function __construct(
        private readonly BranchAccess $branchAccess,
        private readonly ProductionReceiptService $productionReceipts,
    ) {}

    public function options(Request $request): JsonResponse
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $branch = $this->branchAccess->restrictedBranch($request->user());
        $branches = Branch::where('is_active', true)->when($branch, fn ($q) => $q->whereKey($branch))
            ->with(['locations' => fn ($q) => $q->where('is_active', true)])->orderBy('id')->get();
        $variants = ProductVariant::where('is_active', true)->whereHas('product')
            ->with('product:id,name_ar')->when($filters['search'] ?? null, function ($q, $search): void {
                $pattern = SearchTerm::pattern($search);
                $q->where(fn ($q) => $q->whereLike('sku', $pattern)->orWhereLike('barcode', $pattern)
                    ->orWhereHas('product', fn ($p) => $p->whereLike('name_ar', $pattern)->orWhereLike('name_en', $pattern)));
            })->orderBy('id')->limit(50)->get();

        return response()->json(['data' => [
            'branches' => $branches->map(fn ($b) => ['id' => $b->id, 'name' => $b->name,
                'locations' => $b->locations->map(fn ($l) => ['id' => $l->id, 'name' => $l->name])]),
            'variants' => $variants->map(fn ($v) => ['id' => $v->id, 'label' => $v->product->name_ar.' / '.$v->sku]),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $branch = $this->branchAccess->restrictedBranch($request->user());
        $receipts = ProductionReceipt::query()->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->orderByDesc('id')->paginate($filters['per_page'] ?? 15);
        if (! CostAccess::canView($request->user())) {
            $receipts->getCollection()->each(function ($receipt): void {
                $receipt->items = array_map(function ($item) {
                    unset($item['unit_cost']);

                    return $item;
                }, $receipt->items);
            });
        }

        return response()->json($receipts);
    }

    public function store(StoreProductionReceiptRequest $request): JsonResponse
    {
        $key = $this->idempotencyKey($request);
        abort_if($key === null, 422, 'مفتاح Idempotency-Key مطلوب لاستلام الإنتاج.');
        $receipt = $this->productionReceipts->receive($request->validated(), $request->user()->id, $key);

        return response()->json(['message' => 'تم استلام الإنتاج وإضافة الكميات للمخزون.', 'data' => $receipt], 201);
    }
}
```

## File: bags-platform/backend/app/Http/Requests/Admin/Inventory/StoreProductionReceiptRequest.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Inventory;

use App\Http\Requests\Admin\Concerns\BulkVariantExists;
use App\Services\CostAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreProductionReceiptRequest extends FormRequest
{
    use BulkVariantExists;

    public function withValidator(Validator $validator): void
    {
        $this->addBulkVariantExistsCheck($validator, activeOnly: true);
    }

    public function authorize(): bool
    {
        return $this->user()->can('inventory.adjust') && CostAccess::canView($this->user());
    }

    protected function prepareForValidation(): void
    {
        // L3: batch numbers are compared/unique-matched — trim whitespace.
        if (is_string($batch = $this->input('batch_number'))) {
            $this->merge(['batch_number' => mb_strtoupper(trim($batch))]);
        }
    }

    public function rules(): array
    {
        return [
            'batch_number' => ['required', 'string', 'max:100'],
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('is_active', true)],
            'location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('branch_id', (int) $this->input('branch_id'))->where('is_active', true)],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*' => ['array:product_variant_id,quantity,unit_cost'],
            'items.*.product_variant_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99999999'],
            'items.*.unit_cost' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:99999999.9999'],
        ];
    }
}
```

## File: bags-platform/backend/app/Services/BranchAccess.php
```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StaffRole;
use App\Models\Branch;
use App\Models\Treasury;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * Enforces staff branch scoping using a fail-closed access policy:
 * Multi-branch access is an explicit privilege (Super Admin role or inventory.all_branches permission).
 * A branch-assigned staff member is strictly confined to their branch.
 * Users with no branch assignment and no all-branch privilege are denied access by default.
 */
class BranchAccess
{
    /** @var array<int, User> Per-request memory cache to avoid reloading user instances on repeated checks. */
    private array $usersById = [];

    /** @var array<int, bool> M4: حالة تفعيل الفرع للمستخدم المرتبط — cache بمستوى الطلب. */
    private array $branchActive = [];

    /**
     * Determine branch scope restriction for the given user.
     *
     * Returns null if user possesses global multi-branch access, returns branch ID if confined,
     * or throws an authorization exception if unassigned without global privileges.
     *
     * @param  User|null  $user
     * @return int|null
     *
     * @throws AuthorizationException
     */
    public function restrictedBranch(?User $user): ?int
    {
        if ($user === null) {
            throw new AuthorizationException;
        }

        if ($user->hasRole(StaffRole::SuperAdmin->value, 'staff') || $user->can('inventory.all_branches')) {
            return null;
        }

        if ($user->branch_id !== null) {
            // M4: الفرع المعطّل مقفول — مقيّد بفرع معطّل مالوش أي وصول
            // (fail-closed) بدل ما يكمل POS/مخزون عادي على فرع مزعّم إنه مقفول.
            $branchId = (int) $user->branch_id;
            $this->branchActive[$branchId] ??= (bool) (Branch::query()->find($branchId)?->is_active);
            if (! $this->branchActive[$branchId]) {
                throw new AuthorizationException('الفرع المرتبط بحسابك متوقف حالياً.');
            }

            return $branchId;
        }

        throw new AuthorizationException('حسابك غير مرتبط بأي فرع ولا تملك صلاحية الوصول لكل الفروع (inventory.all_branches).');
    }

    /**
     * Assert that the user is authorized to access at least one of the specified branches.
     *
     * @param  int  $userId
     * @param  int  ...$branchIds
     * @return void
     *
     * @throws AuthorizationException
     */
    public function assertBranch(int $userId, int ...$branchIds): void
    {
        $branchId = $this->restrictedBranch($this->usersById[$userId] ??= User::findOrFail($userId));
        if ($branchId !== null && ! in_array($branchId, $branchIds, true)) {
            throw new AuthorizationException('لا تملك صلاحية الوصول إلى هذا الفرع.');
        }
    }

    /**
     * Assert that the user possesses global multi-branch access required for central headquarters operations.
     *
     * @param  User|null  $user
     * @return void
     *
     * @throws AuthorizationException
     */
    public function assertCentralAccess(?User $user): void
    {
        if ($this->restrictedBranch($user) !== null) {
            throw new AuthorizationException('هذه العملية المركزية تتطلب صلاحية الوصول لكل الفروع.');
        }
    }

    public function lockActiveBranch(int $branchId): Branch
    {
        return $this->lockActiveBranches([$branchId])->get($branchId)
            ?? throw (new ModelNotFoundException)->setModel(Branch::class, [$branchId]);
    }

    /**
     * @param  array<int, int>|Collection<int, int>  $branchIds
     * @return Collection<int, Branch>
     */
    public function lockActiveBranches(array|Collection $branchIds): Collection
    {
        $ids = collect($branchIds)->map(fn ($id) => (int) $id)->unique()->sort()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        $branches = Branch::query()->whereIn('id', $ids->all())->orderBy('id')->sharedLock()->get()->keyBy('id');
        foreach ($ids as $id) {
            $branch = $branches->get($id);
            if ($branch === null) {
                throw (new ModelNotFoundException)->setModel(Branch::class, [$id]);
            }
            if (! $branch->is_active) {
                throw new AuthorizationException('الفرع متوقف حالياً.');
            }
        }

        return $branches;
    }

    /**
     * Assert that the user is authorized to perform operations on the specified treasury.
     *
     * Central treasuries (branch_id = null) require global access; branch treasuries require branch access.
     *
     * @param  int  $userId
     * @param  Treasury  $treasury
     * @return void
     *
     * @throws AuthorizationException
     */
    public function assertTreasury(int $userId, Treasury $treasury): void
    {
        if ($treasury->branch_id === null) {
            $this->assertCentralAccess(User::findOrFail($userId));
        } else {
            $this->assertBranch($userId, (int) $treasury->branch_id);
        }
    }
}
```

## File: bags-platform/backend/app/Services/IdempotencyFingerprint.php
```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessException;
use JsonException;

/**
 * Computes deterministic request payload fingerprints for idempotency evaluation.
 *
 * Sorts array keys deterministically and normalizes scalar numeric representations
 * so cosmetic serialization variations (e.g. "5" vs 5 vs 5.0) yield identical SHA-256 digests.
 */
final class IdempotencyFingerprint
{
    /**
     * Compute SHA-256 fingerprint for a canonicalized payload array.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function hash(array $payload): string
    {
        try {
            return hash('sha256', json_encode(self::canonicalize($payload), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            throw new BusinessException('بيانات الطلب تحتوي قيمة غير صالحة.');
        }
    }

    private static function canonicalize(mixed $value, ?string $field = null): mixed
    {
        if (is_float($value) && ! is_finite($value)) {
            throw new BusinessException('يجب أن تكون القيم الرقمية محدودة.');
        }
        // M2: textual decimal normalization without precision loss — strip
        // redundant zeros instead of rounding, so 10.1234 and 10.1299 never
        // share a fingerprint (no silent replay of a corrected price), and no
        // float rounding error is introduced by (float) casts.
        if (is_numeric($value) && in_array($field, [
            'amount', 'subtotal', 'tax_amount', 'unit_price', 'line_total',
            'grand_total', 'discount_amount', 'total', 'total_amount', 'paid_amount',
            'expected_total', 'unit_cost',
        ], true)) {
            return self::normalizeDecimal((string) $value);
        }

        if (is_string($value) && is_numeric($value)
            && ($field === 'id' || str_ends_with($field ?? '', '_id')
                || in_array($field, ['qty', 'amount', 'received_good_qty', 'received_damaged_qty', 'quantity', 'requested_qty', 'counted_qty', 'approved_qty', 'shipped_qty', 'received_qty'], true))) {
            $value = $value + 0;
        }

        if (is_float($value) && floor($value) === $value && abs($value) <= 9007199254740991) {
            return (int) $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $mapped = [];
        foreach ($value as $key => $item) {
            $mapped[$key] = self::canonicalize($item, is_string($key) ? $key : null);
        }

        if ($isList && $mapped !== [] && self::isItemList($mapped)) {
            usort($mapped, self::itemSort(...));
        }

        if (! $isList) {
            ksort($mapped);
        }

        return $mapped;
    }

    /**
     * Normalize a decimal string without rounding: trim sign/zeros, drop a
     * trailing decimal point, keep every significant digit intact.
     */
    private static function normalizeDecimal(string $raw): string
    {
        $raw = trim($raw);
        $sign = '';
        if ($raw !== '' && ($raw[0] === '+' || $raw[0] === '-')) {
            $sign = $raw[0] === '-' ? '-' : '';
            $raw = substr($raw, 1);
        }

        // Keep the coefficient compact: expanding user-supplied exponents
        // could allocate gigabytes. No float conversion or rounding is needed.
        if (preg_match('/\A(\d*(?:\.\d*)?)(?:[eE]([+-]?\d+))?\z/', $raw, $parts) !== 1) {
            throw new BusinessException('قيمة رقمية غير صالحة.');
        }
        $coefficient = $parts[1];
        $point = strpos($coefficient, '.');
        $fractionLength = $point === false ? 0 : strlen($coefficient) - $point - 1;
        $digits = ltrim(str_replace('.', '', $coefficient), '0');
        if ($digits === '') {
            return '0';
        }
        $significant = rtrim($digits, '0');
        $exponent = bcadd($parts[2] ?? '0', (string) (strlen($digits) - strlen($significant) - $fractionLength), 0);

        return $sign.$significant.'e'.$exponent;
    }

    /** @param list<mixed> $items */
    private static function isItemList(array $items): bool
    {
        foreach ($items as $item) {
            if (! is_array($item) || (! isset($item['id']) && ! isset($item['order_item_id']) && ! isset($item['purchase_order_item_id']) && ! isset($item['product_variant_id']))) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $left */
    private static function itemSort(array $left, array $right): int
    {
        $sortKey = static function (array $item): array {
            foreach (['order_item_id', 'purchase_order_item_id', 'id', 'product_variant_id'] as $rank => $key) {
                if (isset($item[$key])) {
                    return [$rank, $item[$key], json_encode($item, JSON_THROW_ON_ERROR)];
                }
            }

            return [4, '', json_encode($item, JSON_THROW_ON_ERROR)];
        };

        return $sortKey($left) <=> $sortKey($right);
    }
}
```

## File: bags-platform/backend/app/Services/ProductionReceiptService.php
```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessException;
use App\Exceptions\RetryableConflict;
use App\Models\Branch;
use App\Models\ProductionReceipt;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Handles finished goods manufacturing intake and inventory ledger posting.
 *
 * Recalculates weighted average cost (WAC) for manufactured variants across all branches
 * and records production receipt movements into branch on-hand inventory.
 */
class ProductionReceiptService
{
    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * Intake a manufactured batch into branch inventory and update variant landed cost.
     *
     * @param  array{branch_id: int, batch_number: string, items: list<array{product_variant_id: int, quantity: int|float, unit_cost: string|float|int}>, location_id?: int|null, notes?: string|null}  $data
     * @param  int  $userId
     * @param  string  $key  Idempotency key for request deduplication
     * @return ProductionReceipt
     *
     * @throws BusinessException
     * @throws UniqueConstraintViolationException
     */
    public function receive(array $data, int $userId, string $key): ProductionReceipt
    {
        app(BranchAccess::class)->assertBranch($userId, (int) $data['branch_id']);
        $hash = IdempotencyFingerprint::hash($data);
        $existing = ProductionReceipt::where('created_by', $userId)->where('idempotency_key', $key)->first();
        if ($existing) {
            return $this->replay($existing, $hash);
        }

        try {
            return DB::transaction(function () use ($data, $userId, $key, $hash): ProductionReceipt {
                DB::statement("SET LOCAL lock_timeout = '5s'");
                $branch = Branch::whereKey($data['branch_id'])->sharedLock()->firstOrFail();
                if (! $branch->is_active) {
                    throw new BusinessException('الفرع غير نشط.');
                }
                $variants = ProductVariant::whereIn('id', array_column($data['items'], 'product_variant_id'))
                    ->with('product:id,name_ar')->orderBy('id')->lockForUpdate()->get()->keyBy('id');

                $sortedItems = collect($data['items'])->sortBy('product_variant_id')->values()->all();
                $itemsWithLabels = [];

                foreach ($sortedItems as $item) {
                    $variant = $variants->get($item['product_variant_id']);
                    if (! $variant || ! $variant->is_active || ! $variant->product) {
                        throw new BusinessException('أحد أصناف الإنتاج غير متاح.');
                    }
                    $itemsWithLabels[] = [
                        ...$item,
                        'label' => $variant->product->name_ar.' / '.$variant->sku,
                    ];
                }

                $receipt = ProductionReceipt::create([
                    ...$data, 'items' => $itemsWithLabels, 'created_by' => $userId, 'idempotency_key' => $key, 'request_hash' => $hash,
                ]);

                foreach ($sortedItems as $item) {
                    $variant = $variants->get($item['product_variant_id']);
                    $quantity = (int) $item['quantity'];
                    $unitCost = bcadd((string) $item['unit_cost'], '0', 4);
                    // H4: bcmath weighted average — float loses precision once
                    // on_hand * cost crosses 2^53, silently biasing landed cost.
                    // F12: lock this variant's stock_levels rows before summing so
                    // the average is computed on a frozen balance even if a
                    // concurrent movement path ever skips the variant lock.
                    $lockedLevelIds = StockLevel::where('product_variant_id', $variant->id)->orderBy('id')->lockForUpdate()->pluck('id');
                    // In-transit goods are still owned inventory. Excluding them
                    // would change the average merely because a transfer shipped.
                    $onHandStr = (string) StockLevel::whereIn('id', $lockedLevelIds)
                        ->sum(DB::raw('on_hand + in_transit'));
                    $onHandStr = bccomp($onHandStr, '0', 4) < 0 ? '0' : $onHandStr;
                    $costStr = (string) $variant->cost_price;
                    $unitStr = $unitCost;
                    $total = bcadd(bcmul($onHandStr, $costStr, 8), bcmul((string) $quantity, $unitStr, 8), 8);
                    $average = bcadd(bcdiv($total, bcadd($onHandStr, (string) $quantity, 4), 5), '0.00005', 4);
                    $variant->update(['cost_price' => $average, 'last_cost_price' => $unitCost]);
                    $this->inventory->recordMovement([
                        'product_variant_id' => $variant->id,
                        'branch_id' => (int) $data['branch_id'],
                        'location_id' => $data['location_id'] ?? null,
                        'type' => StockMovementType::ProductionReceipt,
                        'affected_bucket' => StockBucket::OnHand,
                        'quantity' => $quantity, 'unit_cost' => $unitCost,
                        'reference_type' => ProductionReceipt::class, 'reference_id' => $receipt->id,
                        'created_by' => $userId, 'notes' => $data['notes'] ?? null,
                    ]);
                }

                return $receipt;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = ProductionReceipt::where('created_by', $userId)->where('idempotency_key', $key)->first();
            if ($existing) {
                return $this->replay($existing, $hash);
            }
            if (ProductionReceipt::whereRaw('LOWER(batch_number) = ?', [mb_strtolower($data['batch_number'])])->exists()) {
                throw new BusinessException('رقم دفعة الإنتاج مسجل بالفعل؛ راجع سجل الاستلام.', [], 409);
            }
            throw $exception;
        } catch (QueryException $exception) {
            // L3: SET LOCAL lock_timeout makes sharedLock/lockForUpdate throw
            // raw 55P03 (Laravel does not treat it as a deadlock) — surface a
            // retryable 409 instead of a 500.
            if (($exception->errorInfo[0] ?? null) === '55P03') {
                throw new RetryableConflict('المخزون مشغول بعملية أخرى الآن؛ أعد المحاولة بعد لحظات.', $exception);
            }
            throw $exception;
        }
    }

    /**
     * Return previously created receipt if request payload hash matches idempotency key.
     */
    private function replay(ProductionReceipt $receipt, string $hash): ProductionReceipt
    {
        if (! hash_equals($receipt->request_hash, $hash)) {
            throw new BusinessException('مفتاح الطلب مستخدم مع بيانات مختلفة.', [], 409);
        }

        return $receipt;
    }
}
```

## File: bags-platform/backend/app/Models/ProductVariant.php
```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TracksEditVersion;
use App\Support\CatalogMedia;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ProductVariant extends Model implements HasMedia
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory, InteractsWithMedia, SoftDeletes, TracksEditVersion;

    protected $fillable = [
        'product_id', 'sku', 'barcode', 'color_name_ar', 'color_name_en', 'color_hex', 'size', 'capacity',
        'cost_price', 'last_cost_price', 'retail_price', 'wholesale_price', 'compare_at_price', 'reorder_level', 'weight_kg', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'cost_price' => 'decimal:4', 'last_cost_price' => 'decimal:4', 'retail_price' => 'decimal:2', 'wholesale_price' => 'decimal:2', 'compare_at_price' => 'decimal:2',
        'weight_kg' => 'decimal:3', 'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::deleted(function (self $variant): void {
            CartItem::query()->where('product_variant_id', $variant->id)->delete();
        });
    }

    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Compatibility with the catalog's existing editable retail price. */
    public function getBasePriceAttribute(): string
    {
        return (string) ($this->retail_price ?? 0);
    }

    /**
     * اسم العرض للكتالوج: اللون والمقاس معًا (مثال: "أسود / L")،
     * ويرجع SKU عند غيابهما.
     */
    public function displayLabel(): string
    {
        $parts = array_filter([
            $this->color_name_ar ?? $this->color_name_en,
            $this->size ?? $this->capacity,
        ], fn ($v) => $v !== null && trim((string) $v) !== '');

        return $parts !== [] ? implode(' / ', $parts) : (string) $this->sku;
    }

    // صور خاصة باللون ده
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')->useDisk(CatalogMedia::disk())->storeConversionsOnDisk(CatalogMedia::disk())->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
        $this->addMediaCollection('images_staging')->useDisk(CatalogMedia::disk())->storeConversionsOnDisk(CatalogMedia::disk())->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        // F3: queued like Product — no synchronous conversion inside locks.
        $this->addMediaConversion('thumb')->fit(Fit::Crop, 300, 300)->format('webp')->performOnCollections('images')->queued();
        $this->addMediaConversion('medium')->fit(Fit::Contain, 800, 800)->format('webp')->performOnCollections('images')->queued();
    }
}
```

## File: bags-platform/backend/app/Services/InventoryService.php
```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Events\StockLowDetected;
use App\Exceptions\BusinessException;
use App\Exceptions\RetryableConflict;
use App\Models\Branch;
use App\Models\InventoryIdempotencyKey;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single authority for modifying stock balances across the platform.
 *
 * Enforces ledger-first accounting: records a stock movement in the ledger
 * before updating row balances under row-level database locks.
 */
class InventoryService
{
    public function __construct(private readonly SettingService $settings) {}

    /**
     * Records an immutable stock movement and updates bucket balances.
     *
     * @param  array{product_variant_id: int, branch_id: int, location_id?: int|null, type: StockMovementType|string, affected_bucket: StockBucket|string, quantity: float|int|string, unit_cost?: float|null, reference_type?: string|null, reference_id?: int|null, created_by?: int|null, notes?: string|null}  $data
     * @throws BusinessException If quantity is invalid, bucket balance goes negative, or available stock is insufficient.
     */
    public function recordMovement(array $data, ?StockLevel $prelockedLevel = null): StockMovement
    {
        $apply = function () use ($data, $prelockedLevel): StockMovement {
            $rawQty = $data['quantity'];
            if (! is_numeric($rawQty) || ! is_finite((float) $rawQty) || abs((float) $rawQty) > 99999999.9999) {
                throw new BusinessException('كمية الحركة غير صالحة.');
            }
            // Work in ten-thousandths to avoid floating point residues at zero.
            $units = (int) round((float) $rawQty * 10000);
            $qty = $units / 10000;

            if ($units === 0) {
                throw new BusinessException('كمية الحركة يجب ألا تساوي صفرًا.');
            }

            $type = $data['type'] instanceof StockMovementType ? $data['type'] : StockMovementType::from($data['type']);
            $bucket = $data['affected_bucket'] instanceof StockBucket ? $data['affected_bucket'] : StockBucket::from($data['affected_bucket']);
            $this->assertValidMovementSemantics($type, $bucket, $units);
            $locationId = $data['location_id'] ?? null;

            // Skip re-locking and location checks if caller already locked this level row.
            if ($prelockedLevel !== null) {
                if ((int) $prelockedLevel->product_variant_id !== (int) $data['product_variant_id']
                    || (int) $prelockedLevel->branch_id !== (int) $data['branch_id']
                    || ($prelockedLevel->location_id === null ? null : (int) $prelockedLevel->location_id) !== ($locationId === null ? null : (int) $locationId)) {
                    throw new \LogicException('الصف المقفول مسبقًا لا يطابق مفتاح الحركة.');
                }
                $stock = $prelockedLevel;
            } else {
                if ($locationId !== null && ! Location::query()->whereKey($locationId)->where('branch_id', $data['branch_id'])->exists()) {
                    throw new BusinessException('الموقع لا يتبع الفرع المحدد.');
                }

                $stock = $this->lockLevels([[
                    'product_variant_id' => $data['product_variant_id'],
                    'branch_id' => $data['branch_id'],
                    'location_id' => $locationId,
                ]])->first();
            }

            // Only new inbound inventory requires active variant and location.
            // Stock transfers, returns, and write-offs remain allowed on inactive items to liquidate stock.
            $isNewInbound = in_array($type, [
                StockMovementType::AdjustmentIn,
                StockMovementType::ProductionReceipt,
                StockMovementType::PurchaseReceipt,
            ], true);
            if ($isNewInbound && $bucket === StockBucket::OnHand && $units > 0) {
                $variant = $stock->variant;
                if ($variant === null || $variant->trashed() || ! $variant->is_active
                    || ($stock->location_id !== null && ! $stock->location?->is_active)) {
                    throw new BusinessException('الإدخال يتطلب متغيرًا وموقعًا نشطين.');
                }
            }

            $bucketKey = $bucket->value;
            $before = (float) $stock->{$bucketKey};
            $afterUnits = (int) round($before * 10000) + $units;
            $after = $afterUnits / 10000;
            if ($afterUnits > 999999999999) {
                throw new BusinessException('الرصيد يتجاوز الحد المسموح.');
            }

            if ($after < 0) {
                throw new BusinessException("الرصيد غير كافٍ في ({$bucketKey}) لإتمام العملية.");
            }

            // Ensure available-for-sale quantity (on_hand - reserved - damaged) does not drop below zero.
            $onHand = $bucket === StockBucket::OnHand ? $after : (float) $stock->on_hand;
            $reserved = $bucket === StockBucket::Reserved ? $after : (float) $stock->reserved;
            $damaged = $bucket === StockBucket::Damaged ? $after : (float) $stock->damaged;
            $availableAfter = (int) round($onHand * 10000) - (int) round($reserved * 10000) - (int) round($damaged * 10000);

            if ($availableAfter < 0) {
                $availableNow = (float) $stock->on_hand - (float) $stock->reserved - (float) $stock->damaged;

                throw new BusinessException("الكمية المتاحة للبيع ({$availableNow}) غير كافية لإتمام العملية.");
            }

            // Created-by is mandatory for audit trails; fails explicitly in queue/CLI contexts without auth user.
            $createdBy = $data['created_by'] ?? auth()->id();
            if ($createdBy === null) {
                throw new \LogicException('recordMovement يتطلب created_by صريحًا عند غياب مستخدم مصادق.');
            }

            // Store morph alias instead of FQCN so future class renames preserve audit trail queries.
            $referenceType = $data['reference_type'] ?? null;
            if (is_string($referenceType) && class_exists($referenceType)) {
                $referenceType = (new $referenceType)->getMorphClass();
            }

            // 4. تسجيل الحركة في دفتر الأستاذ (Ledger)
            $movement = StockMovement::query()->create([
                'reference_number' => $this->settings->generateNextNumber('movement'),
                'product_variant_id' => $data['product_variant_id'],
                'branch_id' => $data['branch_id'],
                'location_id' => $locationId,
                'type' => $type,
                'affected_bucket' => $bucket,
                'quantity' => $qty,
                'balance_before' => $before,
                'balance_after' => $after,
                'unit_cost' => $data['unit_cost'] ?? null,
                'reference_type' => $referenceType,
                'reference_id' => $data['reference_id'] ?? null,
                'created_by' => $createdBy,
                'notes' => $data['notes'] ?? null,
            ]);

            $stock->{$bucketKey} = $after;
            $stock->save();

            // Evaluate low stock threshold: location-specific reorder level triggers on row availability,
            // while variant reorder level triggers on branch-wide aggregate availability.
            $rowLevel = (float) $stock->reorder_level;
            if ($rowLevel > 0) {
                $effectOnAvailable = match ($bucket) {
                    StockBucket::OnHand => $units,
                    StockBucket::Reserved, StockBucket::Damaged => -$units,
                    default => 0,
                };
                $availableBeforeUnits = $availableAfter - $effectOnAvailable;
                $availableAfterQty = $availableAfter / 10000;
                if ($availableBeforeUnits / 10000 > $rowLevel && $availableAfterQty <= $rowLevel) {
                    StockLowDetected::dispatch(
                        (int) $stock->product_variant_id,
                        (int) $stock->branch_id,
                        $availableAfterQty,
                        $rowLevel,
                    );
                }
            }
            $variantLevel = (float) ($stock->variant?->reorder_level ?? 0);
            if ($variantLevel > 0) {
                $effectOnAvailable = match ($bucket) {
                    StockBucket::OnHand => $units,
                    StockBucket::Reserved, StockBucket::Damaged => -$units,
                    default => 0,
                };
                // Check aggregate branch availability within the transaction to see the committed change.
                $branchTotal = (float) (StockLevel::query()
                    ->where('product_variant_id', $stock->product_variant_id)
                    ->where('branch_id', $stock->branch_id)
                    ->selectRaw('COALESCE(SUM(on_hand - reserved - damaged), 0) AS total')
                    ->value('total') ?? 0);
                $branchBefore = $branchTotal - ($effectOnAvailable / 10000);
                if ($branchBefore > $variantLevel && $branchTotal <= $variantLevel) {
                    StockLowDetected::dispatch(
                        (int) $stock->product_variant_id,
                        (int) $stock->branch_id,
                        $branchTotal,
                        $variantLevel,
                    );
                }
            }

            return $movement;
        };

        if ($prelockedLevel !== null) {
            if (DB::transactionLevel() === 0) {
                throw new \LogicException('Prelocked stock requires an active transaction.');
            }

            return $apply();
        }

        return DB::transaction($apply);
    }

    /**
     * Executes multiple stock adjustments atomically with optional idempotency protection.
     *
     * @param  array{branch_id: int, location_id?: int|null, items: list<array{product_variant_id: int, adjustment_type: string, quantity: float|int|string, reason?: string|null}>}  $payload
     * @return list<StockMovement>
     */
    public function applyAdjustments(array $payload, int $userId, ?string $idempotencyKey = null): array
    {
        app(BranchAccess::class)->assertBranch($userId, (int) $payload['branch_id']);
        if ($idempotencyKey === null) {
            return $this->executeAdjustments($payload, $userId);
        }

        $requestHash = IdempotencyFingerprint::hash($payload);

        try {
            return DB::transaction(function () use ($payload, $userId, $idempotencyKey, $requestHash): array {
                $record = InventoryIdempotencyKey::query()->create([
                    'user_id' => $userId,
                    'key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                ]);

                $movements = $this->executeAdjustments($payload, $userId);

                $record->update([
                    'response' => array_map(fn (StockMovement $movement) => $movement->id, $movements),
                    'status_code' => 201,
                ]);

                return $movements;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Isolate idempotency key collisions from unrelated unique constraint violations.
            $driverMessage = (string) ($e->errorInfo[2] ?? $e->getMessage());
            if (! str_contains($driverMessage, 'inventory_idempotency_keys_user_id_key_unique')
                && ! str_contains($e->getMessage(), 'inventory_idempotency_keys_user_id_key_unique')) {
                throw $e;
            }

            $record = InventoryIdempotencyKey::query()
                ->where('user_id', $userId)->where('key', $idempotencyKey)->first();

            if ($record === null) {
                throw new BusinessException('تعارض في مفتاح عدم التكرار؛ أعد المحاولة.', [], 409);
            }

            if (! hash_equals($record->request_hash, $requestHash)) {
                throw new BusinessException('مفتاح عدم التكرار هذا مستخدم مسبقًا مع بيانات مختلفة.', [], 409);
            }

            $ids = $record->response ?? [];
            $byId = StockMovement::query()->whereIn('id', $ids)->get()->keyBy('id');

            return array_values(array_filter(array_map(fn ($id) => $byId->get($id), $ids)));
        }
    }

    /**
     * @param  array{branch_id: int, location_id?: int|null, items: list<array{product_variant_id: int, adjustment_type: string, quantity: float|int|string, reason?: string|null}>}  $payload
     * @return list<StockMovement>
     */
    private function executeAdjustments(array $payload, int $userId): array
    {
        return DB::transaction(function () use ($payload, $userId): array {
            app(BranchAccess::class)->assertBranch($userId, (int) $payload['branch_id']);

            // Deactivation allows stock write-offs/decreases, but increases require active variants and locations.
            if (collect($payload['items'])->contains('adjustment_type', 'increase')) {
                $variants = ProductVariant::query()->withTrashed()
                    ->whereIn('id', array_column($payload['items'], 'product_variant_id'))
                    ->get()->keyBy('id');
                $location = ($payload['location_id'] ?? null) !== null ? Location::query()->find($payload['location_id']) : null;

                foreach ($payload['items'] as $item) {
                    if ($item['adjustment_type'] !== 'increase') {
                        continue;
                    }
                    // Soft-deleted variants cannot receive new stock, but write-offs remain allowed.
                    $variant = $variants->get($item['product_variant_id']);
                    if ($variant === null || $variant->trashed() || ! $variant->is_active) {
                        throw new BusinessException('لا يمكن الإدخال على متغير معطّل؛ فعّله أولًا أو استخدم عمليات الإخراج لتصفية رصيده.');
                    }
                    if ($location !== null && ! $location->is_active) {
                        throw new BusinessException('لا يمكن الإدخال إلى موقع معطّل؛ فعّله أولًا.');
                    }
                }
            }
            $levels = $this->lockLevels(array_map(fn ($item) => [
                'product_variant_id' => $item['product_variant_id'],
                'branch_id' => $payload['branch_id'],
                'location_id' => $payload['location_id'] ?? null,
            ], $payload['items']));
            $movements = [];

            foreach ($payload['items'] as $item) {
                // Apply adjustment directly to prelocked level instance.
                $level = $levels->get($this->levelKey([
                    'product_variant_id' => $item['product_variant_id'],
                    'branch_id' => $payload['branch_id'],
                    'location_id' => $payload['location_id'] ?? null,
                ]));
                if (in_array($item['adjustment_type'], ['write_off_damaged', 'restore_damaged'], true)) {
                    $base = [
                        'product_variant_id' => $item['product_variant_id'],
                        'branch_id' => $payload['branch_id'], 'location_id' => $payload['location_id'] ?? null,
                        'created_by' => $userId, 'notes' => $item['reason'] ?? null,
                        'quantity' => -abs((float) $item['quantity']),
                    ];
                    $movements[] = $this->recordMovement([...$base, 'type' => StockMovementType::DamageRelease, 'affected_bucket' => StockBucket::Damaged], $level);
                    if ($item['adjustment_type'] === 'write_off_damaged') {
                        $movements[] = $this->recordMovement([...$base, 'type' => StockMovementType::DamageWriteOff, 'affected_bucket' => StockBucket::OnHand], $level);
                    }

                    continue;
                }
                [$type, $bucket, $sign] = match ($item['adjustment_type']) {
                    'increase' => [StockMovementType::AdjustmentIn, StockBucket::OnHand, 1],
                    'decrease' => [StockMovementType::AdjustmentOut, StockBucket::OnHand, -1],
                    'mark_damaged' => [StockMovementType::Damage, StockBucket::Damaged, 1],
                    default => throw new BusinessException('نوع تسوية غير معروف: '.$item['adjustment_type']),
                };

                $movements[] = $this->recordMovement([
                    'product_variant_id' => $item['product_variant_id'],
                    'branch_id' => $payload['branch_id'],
                    'location_id' => $payload['location_id'] ?? null,
                    'type' => $type,
                    'affected_bucket' => $bucket,
                    'quantity' => $sign * abs((float) $item['quantity']),
                    'created_by' => $userId,
                    'notes' => $item['reason'] ?? null,
                ], $level);
            }

            return $movements;
        });
    }

    /**
     * Current stock level for a variant at a branch and location (read-only snapshot).
     */
    public function levelFor(int $variantId, int $branchId, ?int $locationId): ?StockLevel
    {
        return StockLevel::query()
            ->where('product_variant_id', $variantId)
            ->where('branch_id', $branchId)
            ->where('location_id', $locationId)
            ->first();
    }

    /**
     * Acquire every stock key before a multi-item operation, in one global order.
     *
     * @param  list<array{product_variant_id: int, branch_id: int, location_id?: int|null}>  $keys
     * @return Collection<string, StockLevel>
     */
    public function lockLevels(array $keys): Collection
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Stock locks require an active transaction.');
        }
        // Enforce 5s lock timeout to fail fast with 409 Conflict instead of holding PHP worker threads.
        $keys = collect($keys)->unique(fn ($key) => $this->levelKey($key))
            ->sortBy(fn ($key) => [(int) $key['branch_id'], (int) ($key['location_id'] ?? 0), (int) $key['product_variant_id']]);
        if ($keys->isEmpty()) {
            return collect();
        }
        $now = now();
        $previousTimeout = DB::selectOne("SELECT current_setting('lock_timeout') AS value")->value;
        DB::statement("SET LOCAL lock_timeout = '5s'");

        try {
            // Lock variants before stock rows in every path, including callers
            // that prelock a batch of balances before recording movements.
            // H1: shared (FOR SHARE) lock — movements only need to block
            // concurrent variant edits (which take FOR UPDATE), while shared
            // locks don't block each other, so branches run in parallel.
            // The real serialization stays on stock_levels (FOR UPDATE below).
            $variantIds = $keys->pluck('product_variant_id')->unique()->sort()->values();
            $variants = ProductVariant::withTrashed()->whereIn('id', $variantIds)->orderBy('id')->sharedLock()->get();
            if ($variants->count() !== $variantIds->count()) {
                throw new BusinessException('أحد المتغيرات لم يعد متاحًا.');
            }

            // Aggregate threshold checks must see earlier movements at other
            // locations in this branch. Lock only pairs with a branch threshold.
            $thresholdIds = $variants->filter(fn ($variant) => (float) $variant->reorder_level > 0)->modelKeys();
            $pairs = $keys->filter(fn ($key) => in_array((int) $key['product_variant_id'], $thresholdIds, true))
                ->unique(fn ($key) => $key['branch_id'].':'.$key['product_variant_id'])
                ->sortBy(fn ($key) => [(int) $key['branch_id'], (int) $key['product_variant_id']]);
            foreach ($pairs as $key) {
                DB::statement('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [
                    'stock-threshold:'.$key['branch_id'].':'.$key['product_variant_id'],
                ]);
            }

            // M2: batch location→branch ownership check inside the lock path,
            // so executeAdjustments cannot plant (branch A, location of branch
            // B) rows through the prelocked path. The composite FK migration
            // enforces the same invariant at the database level.
            $locationIds = $keys->pluck('location_id')->filter(fn ($id) => $id !== null)->unique()->values();
            if ($locationIds->isNotEmpty()) {
                $branchByLocation = Location::query()->whereIn('id', $locationIds->all())->pluck('branch_id', 'id');
                foreach ($keys as $key) {
                    if (($key['location_id'] ?? null) === null) {
                        continue;
                    }
                    $actual = $branchByLocation->get($key['location_id']);
                    if ($actual === null || (int) $actual !== (int) $key['branch_id']) {
                        throw new BusinessException('الموقع لا يتبع الفرع المحدد.');
                    }
                }
            }

            // Seed missing stock level rows with insertOrIgnore before acquiring exclusive row locks.
            $rows = $keys->map(fn ($key) => [
                'product_variant_id' => $key['product_variant_id'], 'branch_id' => $key['branch_id'],
                'location_id' => $key['location_id'] ?? null, 'created_at' => $now, 'updated_at' => $now,
            ])->values();
            foreach ($rows->chunk(1000) as $chunk) {
                DB::table('stock_levels')->insertOrIgnore($chunk->all());
            }

            $levels = StockLevel::query()->with(['variant:id,product_id,is_active,deleted_at,reorder_level', 'location:id,is_active'])->where(function ($query) use ($keys): void {
                foreach ($keys->groupBy(fn ($key) => $key['branch_id'].':'.($key['location_id'] ?? 0)) as $group) {
                    $identity = $group->first();
                    $query->orWhere(fn ($scope) => $scope->where('branch_id', $identity['branch_id'])
                        ->where('location_id', $identity['location_id'] ?? null)
                        ->whereIn('product_variant_id', $group->pluck('product_variant_id')->all()));
                }
            })->orderBy('branch_id')->orderByRaw('location_id ASC NULLS FIRST')->orderBy('product_variant_id')
                ->lockForUpdate()->get()->keyBy(fn ($stock) => $this->levelKey($stock->getAttributes()));

            // M-11: بعد قفل صفوف الأرصدة يُفحص is_active للموقع والفرع تحت
            // قفل FOR SHARE — يقفل التعطيل (FOR UPDATE على نفس الصفوف)
            // فيتسلسل المساران: إما يرى المخزونُ التعطيلَ فيرفض، أو يرى
            // التعطيلُ الرصيدَ الجديد فيرفض. بلا هذا يدخل رصيد بين الفحص والتعطيل.
            $branchIds = $keys->pluck('branch_id')->map(fn ($id) => (int) $id)->unique()->values();
            if ($branchIds->isNotEmpty()) {
                $inactiveBranches = Branch::query()->whereIn('id', $branchIds->all())->sharedLock()->pluck('is_active', 'id');
                foreach ($branchIds as $branchId) {
                    if ($inactiveBranches->has($branchId) && ! (bool) $inactiveBranches->get($branchId)) {
                        throw new BusinessException('الفرع متوقف حالياً ولا يقبل حركات مخزنية.');
                    }
                }
            }
            if ($locationIds->isNotEmpty()) {
                $inactiveLocations = Location::query()->whereIn('id', $locationIds->all())->sharedLock()->pluck('is_active', 'id');
                foreach ($locationIds as $locationId) {
                    if ($inactiveLocations->has($locationId) && ! (bool) $inactiveLocations->get($locationId)) {
                        throw new BusinessException('الموقع متوقف حالياً ولا يقبل حركات مخزنية.');
                    }
                }
            }
        } catch (QueryException $e) {
            // M1: lock timeouts are retryable conflicts, not terminal failures.
            // RetryableConflict extends ConflictHttpException, so REST callers
            // still see 409, while PendingActionService::confirmLocked retries
            // instead of marking the action failed.
            if (($e->errorInfo[0] ?? $e->getCode()) === '55P03') {
                throw new RetryableConflict('المخزون مشغول بعملية أخرى الآن؛ أعد المحاولة بعد لحظات.', $e);
            }

            throw $e;
        } catch (\Throwable $e) {
            DB::selectOne("SELECT set_config('lock_timeout', ?, true)", [$previousTimeout]);
            throw $e;
        }

        // Restore the caller's transaction-local setting, not the session default.
        // Failed PostgreSQL transactions must roll back before further SQL.
        DB::selectOne("SELECT set_config('lock_timeout', ?, true)", [$previousTimeout]);

        return $levels;
    }

    /**
     * Generates a composite key uniquely identifying a branch-location-variant level row.
     *
     * @param  array{branch_id: int|string, location_id?: int|string|null, product_variant_id: int|string}  $key
     */
    public function levelKey(array $key): string
    {
        return $key['branch_id'].':'.($key['location_id'] ?? 0).':'.$key['product_variant_id'];
    }

    /**
     * Resolves the primary receiving location for a branch, defaulting to designated receiving type.
     */
    public function receivingLocation(int $branchId, ?int $locationId = null): ?int
    {
        $query = Location::query()->where('branch_id', $branchId)->where('is_active', true);
        if ($locationId !== null) {
            $location = (clone $query)->whereKey($locationId)->first();
            if ($location === null || (string) $location->type->value === 'damaged') {
                throw new BusinessException('موقع الاستلام غير نشط أو لا يتبع الفرع.');
            }

            return $locationId;
        }
        // Damaged locations are never eligible as receiving locations.
        $id = (clone $query)->where('type', '!=', 'damaged')
            ->orderByRaw("CASE WHEN type = 'receiving' THEN 0 ELSE 1 END")->orderBy('id')->value('id');
        if ($id === null) {
            throw new BusinessException('لا يوجد موقع استلام صالح للفرع.');
        }

        return (int) $id;
    }

    /**
     * Validates movement type compatibility with affected stock bucket and sign of quantity.
     */
    private function assertValidMovementSemantics(StockMovementType $type, StockBucket $bucket, int $units): void
    {
        // Default branch prevents unhandled match errors if new enum cases are introduced.
        [$allowed, $positive] = match ($type) {
            StockMovementType::ProductionReceipt, StockMovementType::PurchaseReceipt, StockMovementType::SaleReturn,
            StockMovementType::AdjustmentIn, StockMovementType::StocktakeGain, StockMovementType::TransferIn => [[StockBucket::OnHand], true],
            StockMovementType::Sale, StockMovementType::AdjustmentOut,
            StockMovementType::StocktakeLoss, StockMovementType::TransferOut,
            StockMovementType::DamageWriteOff => [[StockBucket::OnHand], false],
            StockMovementType::Damage => [[StockBucket::Damaged], true],
            StockMovementType::DamageRelease => [[StockBucket::Damaged], false],
            StockMovementType::Reserve => [[StockBucket::Reserved], true],
            StockMovementType::ReleaseReserve => [[StockBucket::Reserved], false],
            StockMovementType::TransferInTransit => [[StockBucket::InTransit], true],
            StockMovementType::TransferTransitRelease, StockMovementType::TransferShortage => [[StockBucket::InTransit], false],
            default => throw new BusinessException('نوع حركة مخزنية غير مدعوم: '.$type->value),
        };
        if (! in_array($bucket, $allowed, true) || ($units > 0) !== $positive) {
            throw new BusinessException('نوع حركة المخزون لا يطابق وعاء المخزون أو اتجاه الكمية.');
        }
    }
}
```
