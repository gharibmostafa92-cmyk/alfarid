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

## Notes
- Some files may have been excluded based on .gitignore rules and Repomix's configuration
- Binary files are not included in this packed representation. Please refer to the Repository Structure section for a complete list of file paths, including binary files
- Only files matching these patterns are included: bags-platform/backend/app/Services/ReturnService.php, bags-platform/backend/app/Services/PosService.php, bags-platform/backend/app/Services/WholesalePaymentNoticeService.php, bags-platform/backend/app/Services/WalletService.php, bags-platform/backend/app/Services/PrivateAttachments.php, bags-platform/backend/app/Services/DiscreteQuantity.php, bags-platform/backend/app/Models/ReturnRequest.php, bags-platform/backend/app/Models/ReturnRequestItem.php, bags-platform/backend/app/Models/Order.php, bags-platform/backend/app/Models/OrderItem.php, bags-platform/backend/app/Models/PosShift.php, bags-platform/backend/app/Models/CustomerWalletTransaction.php, bags-platform/backend/app/Enums/ReturnStatus.php, bags-platform/backend/app/Enums/RefundMethod.php, bags-platform/backend/app/Http/Controllers/Api/V1/Admin/ReturnController.php, bags-platform/backend/app/Http/Controllers/Api/V1/Store/ReturnController.php, bags-platform/backend/app/Http/Controllers/Api/V1/Pos/PosSaleController.php, bags-platform/backend/app/Http/Controllers/Api/V1/Wholesale/InvoiceController.php, bags-platform/backend/app/Http/Controllers/Api/V1/AttachmentController.php, bags-platform/backend/app/Http/Requests/Admin/Return/ApproveReturnRequest.php, bags-platform/backend/app/Http/Requests/Admin/Return/ReceiveReturnRequest.php, bags-platform/backend/app/Http/Requests/Admin/Return/RefundReturnRequest.php, bags-platform/backend/app/Http/Requests/Admin/Return/StoreReturnRequest.php, bags-platform/backend/app/Http/Requests/Admin/Return/UpdateReturnRequest.php, bags-platform/backend/app/Http/Requests/Store/Return/StoreReturnRequest.php, bags-platform/backend/app/Http/Resources/Admin/ReturnRequestResource.php, bags-platform/backend/app/Http/Resources/Admin/ReturnRequestItemResource.php, bags-platform/backend/app/Http/Resources/Store/ReturnRequestResource.php, bags-platform/backend/app/Http/Resources/Store/ReturnRequestItemResource.php, bags-platform/backend/app/Console/Commands/PrivatizeReturnAttachments.php, bags-platform/backend/app/Console/Commands/PrunePrivateAttachments.php, bags-platform/backend/app/Modules/Returns/ReturnsServiceProvider.php, bags-platform/backend/app/Modules/Returns/Routes/api.php, bags-platform/backend/app/Modules/Returns/Routes/web.php, bags-platform/backend/routes/api/admin.php, bags-platform/backend/routes/api/store.php, bags-platform/backend/routes/api/pos.php, bags-platform/backend/database/migrations/2026_10_17_100007_add_requires_refund_to_orders.php, bags-platform/backend/database/migrations/2026_10_18_100011_create_return_requests_tables.php, bags-platform/backend/database/migrations/2026_10_18_100013_seed_return_settings.php, bags-platform/backend/database/migrations/2026_10_19_073459_harden_returns_and_procurement_operations.php, bags-platform/backend/database/migrations/2026_10_24_000008_add_pos_return_guards.php, bags-platform/backend/database/migrations/2026_10_29_000013_index_private_return_attachments.php, bags-platform/backend/database/seeders/ReturnPermissionSeeder.php, bags-platform/backend/database/seeders/RolePermissionSeeder.php, bags-platform/frontend/packages/api-client/src/endpoints/operations/orders.ts, bags-platform/frontend/packages/api-client/src/endpoints/operations/finance.ts, bags-platform/frontend/packages/api-client/src/endpoints/operations/reports.ts, bags-platform/frontend/packages/api-client/src/endpoints/index.ts, bags-platform/frontend/packages/ui/src/wholesale-portal/orders-screens.jsx, bags-platform/frontend/packages/ui/src/wholesale-portal/finance-screens.jsx, bags-platform/frontend/packages/ui/src/wholesale-portal/WholesalePortal.jsx, bags-platform/frontend/apps/backoffice/src/pages/admin/operations-mock/orders.tsx, bags-platform/frontend/apps/backoffice/src/pages/admin/operations-mock/data.ts, bags-platform/frontend/apps/backoffice/src/pages/admin/operations-mock/api/mappers.ts, bags-platform/frontend/apps/backoffice/src/pages/admin/operations-mock/api/mockData.ts, bags-platform/frontend/apps/backoffice/src/pages/pos/components/returns/ReturnModal.tsx, bags-platform/frontend/apps/pos/src/components/returns/ReturnModal.tsx, bags-platform/frontend/apps/pos/src/services/pos-mock.service.ts, bags-platform/frontend/apps/pos/src/types/pos.types.ts, bags-platform/frontend/apps/storefront/src/app/account/returns/page.tsx, bags-platform/frontend/apps/storefront/src/lib/safe-return-path.ts, new_design/returns.jsx
- Files matching these patterns are excluded: **/node_modules/**, **/.git/**, **/dist/**, **/.next/**, **/vendor/**, **/storage/**, **/tests/**, **/test/**, **/*.test.*, **/*Test.php, **/*.spec.*, **/factories/**
- Files matching default ignore patterns are excluded
- Content has been formatted for parsing in markdown style
- Files are sorted by Git change count (files with more changes are at the bottom)

# Directory Structure
```
bags-platform/
  backend/
    app/
      Console/
        Commands/
          PrivatizeReturnAttachments.php
          PrunePrivateAttachments.php
      Enums/
        RefundMethod.php
        ReturnStatus.php
      Http/
        Controllers/
          Api/
            V1/
              Admin/
                ReturnController.php
              Pos/
                PosSaleController.php
              Store/
                ReturnController.php
              Wholesale/
                InvoiceController.php
              AttachmentController.php
        Requests/
          Admin/
            Return/
              ApproveReturnRequest.php
              ReceiveReturnRequest.php
              RefundReturnRequest.php
              StoreReturnRequest.php
              UpdateReturnRequest.php
          Store/
            Return/
              StoreReturnRequest.php
        Resources/
          Admin/
            ReturnRequestItemResource.php
            ReturnRequestResource.php
          Store/
            ReturnRequestItemResource.php
            ReturnRequestResource.php
      Models/
        CustomerWalletTransaction.php
        Order.php
        OrderItem.php
        PosShift.php
        ReturnRequest.php
        ReturnRequestItem.php
      Modules/
        Returns/
          Routes/
            api.php
            web.php
          ReturnsServiceProvider.php
      Services/
        DiscreteQuantity.php
        PosService.php
        PrivateAttachments.php
        ReturnService.php
        WalletService.php
        WholesalePaymentNoticeService.php
    database/
      migrations/
        2026_10_17_100007_add_requires_refund_to_orders.php
        2026_10_18_100011_create_return_requests_tables.php
        2026_10_18_100013_seed_return_settings.php
        2026_10_19_073459_harden_returns_and_procurement_operations.php
        2026_10_24_000008_add_pos_return_guards.php
        2026_10_29_000013_index_private_return_attachments.php
      seeders/
        ReturnPermissionSeeder.php
        RolePermissionSeeder.php
    routes/
      api/
        admin.php
        pos.php
        store.php
  frontend/
    apps/
      backoffice/
        src/
          pages/
            admin/
              operations-mock/
                api/
                  mappers.ts
                  mockData.ts
                data.ts
                orders.tsx
            pos/
              components/
                returns/
                  ReturnModal.tsx
      pos/
        src/
          components/
            returns/
              ReturnModal.tsx
          services/
            pos-mock.service.ts
          types/
            pos.types.ts
      storefront/
        src/
          app/
            account/
              returns/
                page.tsx
          lib/
            safe-return-path.ts
    packages/
      api-client/
        src/
          endpoints/
            operations/
              finance.ts
              orders.ts
              reports.ts
            index.ts
      ui/
        src/
          wholesale-portal/
            finance-screens.jsx
            orders-screens.jsx
            WholesalePortal.jsx
new_design/
  returns.jsx
```

# Files

## File: bags-platform/backend/app/Modules/Returns/Routes/api.php
```php
<?php

declare(strict_types=1);
```

## File: bags-platform/backend/app/Modules/Returns/Routes/web.php
```php
<?php

declare(strict_types=1);
```

## File: bags-platform/backend/app/Modules/Returns/ReturnsServiceProvider.php
```php
<?php

declare(strict_types=1);

namespace App\Modules\Returns;

use Illuminate\Support\ServiceProvider;

class ReturnsServiceProvider extends ServiceProvider {}
```

## File: bags-platform/backend/app/Console/Commands/PrunePrivateAttachments.php
```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PrunePrivateAttachments extends Command
{
    protected $signature = 'attachments:prune-orphans {--hours=24} {--dry-run}';

    protected $description = 'Remove old private attachments with no database reference';

    public function handle(): int
    {
        $hours = filter_var($this->option('hours'), FILTER_VALIDATE_INT);
        if ($hours === false || $hours < 24) {
            $this->error('Use a grace period of at least 24 hours.');

            return self::FAILURE;
        }
        $disk = Storage::disk('r2');
        $cutoff = now()->subHours($hours)->timestamp;
        $failures = 0;
        foreach ([
            'return_requests' => ['return_request_items', 'attachments'],
            'supplier_invoices' => ['supplier_invoices', 'attachment_path'],
            'supplier_deliveries' => ['supplier_deliveries', 'invoice_file_path'],
            'wholesale_payment_notices' => ['wholesale_payment_notices', 'receipt_file_path'],
        ] as $prefix => [$table, $column]) {
            try {
                $batch = [];
                foreach ($disk->getDriver()->listContents($prefix, true) as $entry) {
                    if ($entry->isFile() && ($entry->lastModified() ?? PHP_INT_MAX) < $cutoff) {
                        $batch[] = $entry->path();
                        if (count($batch) === 500) {
                            $this->prune($batch, $table, $column);
                            $batch = [];
                        }
                    }
                }
                if ($batch !== []) {
                    $this->prune($batch, $table, $column);
                }
            } catch (Throwable $exception) {
                report($exception);
                $this->error('Private attachment cleanup failed for '.$prefix);
                $failures++;
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function prune(array $paths, string $table, string $column): void
    {
        $query = DB::table($table);
        if ($column === 'attachments') {
            $query->where(function ($query) use ($paths): void {
                foreach ($paths as $path) {
                    $query->orWhereJsonContains('attachments', $path);
                }
            });
            $referenced = $query->pluck($column)->flatMap(fn ($value) => json_decode($value, true, 512, JSON_THROW_ON_ERROR))->all();
        } else {
            $referenced = $query->whereIn($column, $paths)->pluck($column)->all();
        }
        foreach (array_diff($paths, $referenced) as $path) {
            // A reference may have committed since the batch lookup.
            $query = DB::table($table);
            $exists = $column === 'attachments'
                ? $query->whereJsonContains($column, $path)->exists()
                : $query->where($column, $path)->exists();
            if ($exists) {
                continue;
            }
            if ($this->option('dry-run')) {
                $this->line('[dry-run] '.$path);
            } elseif (! Storage::disk('r2')->delete($path)) {
                throw new \RuntimeException('Storage did not confirm attachment deletion.');
            }
        }
    }
}
```

## File: bags-platform/backend/app/Http/Resources/Admin/ReturnRequestResource.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\ReturnRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReturnRequest
 */
class ReturnRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'return_number' => $this->return_number,
            'order_id' => $this->order_id,
            'order_number' => $this->whenLoaded('order', fn () => $this->order?->order_number),
            'customer_id' => $this->customer_id,
            'customer_name' => $this->whenLoaded('customer', fn () => $this->customer?->name),
            'branch_id' => $this->branch_id,
            'status' => $this->status->value,
            'refund_method' => $this->refund_method?->value,
            'total_refund_amount' => $this->total_refund_amount,
            'reason' => $this->reason,
            'admin_notes' => $this->admin_notes,
            'refund_reference' => $this->refund_reference,
            'approved_by' => $this->approved_by,
            'received_by' => $this->received_by,
            'refunded_by' => $this->refunded_by,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(),
            'refunded_at' => $this->refunded_at?->toIso8601String(),
            'items' => ReturnRequestItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
```

## File: bags-platform/backend/app/Http/Resources/Store/ReturnRequestResource.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Store;

use App\Models\ReturnRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReturnRequest
 */
class ReturnRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'return_number' => $this->return_number,
            'order_id' => $this->order_id,
            'order_number' => $this->whenLoaded('order', fn () => $this->order?->order_number),
            'branch_id' => $this->branch_id,
            'status' => $this->status->value,
            'refund_method' => $this->refund_method?->value,
            'total_refund_amount' => $this->total_refund_amount,
            'reason' => $this->reason,
            'refund_reference' => $this->refund_reference,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(),
            'refunded_at' => $this->refunded_at?->toIso8601String(),
            'items' => ReturnRequestItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
```

## File: bags-platform/backend/database/migrations/2026_10_17_100007_add_requires_refund_to_orders.php
```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flags orders where payment succeeded after cancellation for manual finance team review and refund.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->boolean('requires_refund')->default(false)->after('payment_status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('requires_refund');
        });
    }
};
```

## File: bags-platform/backend/database/migrations/2026_10_29_000013_index_private_return_attachments.php
```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $name = 'return_request_items_attachments_gin_idx';
        $index = DB::selectOne('SELECT i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ? AND c.relnamespace = current_schema()::regnamespace', [$name]);
        if ($index !== null && ! $index->indisvalid) {
            DB::statement("DROP INDEX CONCURRENTLY {$name}");
        }
        if ($index === null || ! $index->indisvalid) {
            // Matches Laravel's whereJsonContains cast for the existing JSON column.
            DB::statement("CREATE INDEX CONCURRENTLY {$name} ON return_request_items USING gin ((attachments::jsonb))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS return_request_items_attachments_gin_idx');
        }
    }
};
```

## File: bags-platform/frontend/apps/backoffice/src/pages/admin/operations-mock/api/mockData.ts
```typescript
import type { AdminOrder, ReturnRequest, SalesReportData, InventoryReportData, ProfitReportData, SupplierReportRow, ShiftReportData } from '@alfarid/api-client';
import { ORDERS, RETURNS, VARIANTS, BRANCHES, SUPPLIERS, SHIFTS } from '../data';

/** Only passed to useRemote.mock; never substitutes for an empty live response. */
export function mockOrder(id: number): AdminOrder | null {
  const row = ORDERS.find(o => o.id === id);
  if (!row) return null;
  return { id: row.id, order_number: row.no, status: row.status as AdminOrder['status'], payment_method: (row.pay === 'bank' ? 'bank_transfer' : row.pay) as AdminOrder['payment_method'], payment_status: row.payStatus as AdminOrder['payment_status'], guest_name: row.customer, guest_phone: row.phone, grand_total: String(row.total), needs_attention: row.followUp, requires_refund: row.needRefund, channel: row.channel, created_at: '2026-10-27T08:00:00Z', allocated_branch: row.branch ? { id: 1, name: row.branch } : null, items: VARIANTS.slice(0, 2).map((v, i) => ({ id: i + 1, product_variant_id: v.id, product_name: v.name, sku: v.sku, qty: 1, unit_price: String(v.price), line_total: String(v.price) })), status_history: [] };
}
export function mockReturn(id: number): ReturnRequest | null {
  const r = RETURNS.find(r => r.id === id);
  if (!r) return null;
  return { id: r.id, return_number: r.no, order_id: 1, order_number: r.order, customer_id: null, customer_name: r.customer, branch_id: 1, status: r.status as ReturnRequest['status'], refund_method: r.method === 'original' ? 'original_payment' : r.method === 'bank' ? 'bank_transfer' : r.method as ReturnRequest['refund_method'], total_refund_amount: String(r.total), reason: '', admin_notes: null, refund_reference: null, approved_by: null, received_by: null, refunded_by: null, approved_at: null, received_at: null, refunded_at: null, created_at: '2026-10-27T08:00:00Z', updated_at: null, items: [] };
}
export const mockSales = (): SalesReportData => {
  const rows = ORDERS.map(o => ({ period: o.date, group_key: null, group_label: null, orders_count: 1, gross_sales: o.total, net_sales: o.total, discounts: 0, shipping: 0, tax: 0 }));
  const amount = ORDERS.reduce((sum, o) => sum + o.total, 0);
  return { rows, totals: { orders_count: rows.length, gross_sales: amount, net_sales: amount, discounts: 0, shipping: 0, tax: 0 } };
};
export const mockInventory = (): InventoryReportData => ({ valuation: BRANCHES.map((name, i) => ({ branch_id: i + 1, branch_name: name, variants_count: VARIANTS.length, total_qty: 0, total_value: 0 })), total_value: 0, dead_stock_days: 90, dead_stock: [] });
export const mockProfit = (): ProfitReportData => ({ net_sales_revenue: mockSales().totals.net_sales, cogs: 0, gross_profit: mockSales().totals.net_sales, total_expenses: 0, net_profit: mockSales().totals.net_sales });
export const mockSuppliers = (): SupplierReportRow[] => SUPPLIERS.map(s => ({ supplier_id: s.id, supplier_name: s.company || s.name, current_balance: s.balance, total_purchases: 0, aging: { not_due: Math.max(0, s.balance), overdue_1_30: 0, overdue_31_60: 0, overdue_61_90: 0, overdue_90_plus: 0 } }));
export const mockShifts = (): ShiftReportData => ({ rows: SHIFTS.map(s => ({ shift_id: s.id, shift_number: s.no, cashier_name: s.cashier, branch_name: s.branch, status: s.status, opened_at: s.open, closed_at: s.close, cash_sales_total: 0, card_sales_total: 0, cash_returns_total: 0, cash_expenses_total: 0, expected_closing_cash: s.exp ?? 0, actual_closing_cash: s.act, cash_difference: s.act != null && s.exp != null ? s.act - s.exp : null, has_difference: s.act !== s.exp })), totals: { shifts_count: SHIFTS.length, cash_sales: 0, card_sales: 0, shifts_with_difference: SHIFTS.filter(s => s.act !== s.exp).length } });
```

## File: bags-platform/frontend/apps/pos/src/components/returns/ReturnModal.tsx
```typescript
import React, { useState, useEffect } from 'react';
import ReactDOM from 'react-dom';
import { Icon } from '../ui/Icons';
import { egp, fmt, PImg, Api, Empty, Seg, Check, Search, useEsc } from '../ui/SharedUI';
import { posMockService } from '../../services/pos-mock.service';
import type { PastSale, PosProduct } from '../../types/pos.types';

interface ReturnModalProps {
  open: boolean;
  onClose: () => void;
  toast: (msg: string, tone?: 'ok' | 'bad') => void;
}

export function ReturnModal({ open, onClose, toast }: ReturnModalProps) {
  const [num, setNum] = useState<string>('');
  const [sale, setSale] = useState<PastSale | null>(null);
  const [err, setErr] = useState<string>('');
  const [mode, setMode] = useState<'return' | 'exchange'>('return');
  const [sel, setSel] = useState<Record<number, { qty: number; to: 'on_hand' | 'damaged' }>>({});
  const [repl, setRepl] = useState<{ id: number; qty: number }[]>([]);
  const [rq, setRq] = useState<string>('');
  const [busy, setBusy] = useState<boolean>(false);
  const [allProducts, setAllProducts] = useState<PosProduct[]>([]);

  useEffect(() => {
    if (open) {
      setNum('');
      setSale(null);
      setErr('');
      setMode('return');
      setSel({});
      setRepl([]);
      setRq('');
      setBusy(false);
      posMockService.getProducts().then(setAllProducts);
    }
  }, [open]);

  useEsc(onClose, open);
  if (!open) return null;

  const find = async () => {
    if (!num.trim()) return;
    const s = await posMockService.getPastSale(num);
    if (s) {
      setSale(s);
      setErr('');
    } else {
      setSale(null);
      setErr('لم يتم العثور على فاتورة بهذا الرقم في الفرع');
    }
  };

  const loadExample = async () => {
    const s = await posMockService.getPastSale('POS-NSR-10233');
    if (s) {
      setNum('POS-NSR-10233');
      setSale(s);
      setErr('');
    }
  };

  const retTotal = sale
    ? sale.items.reduce((s, i) => s + (sel[i.id]?.qty || 0) * i.price, 0)
    : 0;

  const replTotal = repl.reduce((s, r) => {
    const p = posMockService.getProductById(r.id);
    return s + (p?.price || 0) * r.qty;
  }, 0);

  const net = replTotal - retTotal;
  const anySel = Object.values(sel).some((v) => v.qty > 0);
  const canSubmit = anySel && (mode === 'return' || repl.length > 0) && !busy;

  const results = rq
    ? allProducts
        .filter(
          (p) =>
            p.name.includes(rq) ||
            p.sku.toLowerCase().includes(rq.toLowerCase())
        )
        .slice(0, 4)
    : [];

  const handleSubmit = async () => {
    if (!canSubmit || !sale) return;
    setBusy(true);
    try {
      const returnPayload = Object.entries(sel)
        .filter(([, v]) => v.qty > 0)
        .map(([id, v]) => ({
          id: Number(id),
          qty: v.qty,
          return_to: v.to,
        }));

      if (mode === 'return') {
        await posMockService.processReturn(sale.id, returnPayload);
        toast('تم تسجيل المرتجع وتحديث المخزون');
      } else {
        await posMockService.processExchange(sale.id, returnPayload, repl, net);
        toast('تم الاستبدال وإصدار فاتورة جديدة');
      }
      onClose();
    } finally {
      setBusy(false);
    }
  };

  return ReactDOM.createPortal(
    <div
      dir="rtl"
      className={document.querySelector('.app')?.classList.contains('theme-dark') ? 'theme-dark' : ''}
    >
      <div className="overlay" onClick={onClose} />
      <div
        className="modal"
        style={{ width: 'min(920px, calc(100vw - 32px))' }}
        data-screen-label="07 Return & Exchange"
        role="dialog"
        aria-modal="true"
      >
        <div className="pop">
          <div
            className="row gap-3"
            style={{ padding: '18px 22px', borderBottom: '1px solid var(--line)' }}
          >
            <div className="grow col">
              <h3 className="fs-16">مرتجع / استبدال</h3>
              <span className="perm">
                <Icon n="shield" s={12} />
                pos.sales.refund
              </span>
            </div>
            <Seg
              value={mode}
              onChange={setMode}
              options={[
                { value: 'return', label: 'إرجاع فقط' },
                { value: 'exchange', label: 'استبدال بصنف آخر' },
              ]}
            />
            <button className="icon-btn sm" onClick={onClose} aria-label="إغلاق">
              <Icon n="x" s={16} />
            </button>
          </div>

          <div
            style={{
              padding: '18px 22px',
              display: 'flex',
              flexDirection: 'column',
              gap: 16,
            }}
          >
            <div className="ret-head">
              <div className="scanbar grow" style={{ height: 48 }}>
                <span className="scan-ic" style={{ width: 30, height: 30 }}>
                  <Icon n="barcode" s={16} w={2} />
                </span>
                <input
                  autoFocus
                  value={num}
                  onChange={(e) => setNum(e.target.value)}
                  onKeyDown={(e) => e.key === 'Enter' && find()}
                  placeholder="امسح باركود الإيصال أو اكتب رقم الفاتورة (order_number)"
                  className="ltr"
                  style={{ textAlign: 'right' }}
                />
              </div>
              <button className="btn btn-primary" style={{ height: 48 }} onClick={find}>
                بحث
              </button>
              {!sale && (
                <button className="btn btn-ghost" style={{ height: 48 }} onClick={loadExample}>
                  مثال
                </button>
              )}
            </div>
            {err && (
              <div className="banner b-bad">
                <Icon n="alert" s={16} />
                {err}
              </div>
            )}

            {!sale ? (
              <Empty
                icon="ret"
                title="ابدأ بمسح إيصال البيع الأصلي"
                body="سيتم عرض بنود الفاتورة لتختار الأصناف والكميات المرتجعة وحالتها."
              />
            ) : (
              <>
                <div className="order-meta">
                  <div>
                    <span>رقم الفاتورة</span>
                    <b className="mono">{sale.number}</b>
                  </div>
                  <div>
                    <span>التاريخ</span>
                    <b>{sale.date}</b>
                  </div>
                  <div>
                    <span>العميل</span>
                    <b>{sale.customer}</b>
                  </div>
                  <div>
                    <span>الدفع / الكاشير</span>
                    <b>
                      {sale.method} · {sale.cashier}
                    </b>
                  </div>
                </div>
                <div className="card" style={{ overflow: 'hidden' }}>
                  <table className="tbl">
                    <thead>
                      <tr>
                        <th style={{ width: 40 }}></th>
                        <th>الصنف</th>
                        <th className="n">السعر</th>
                        <th className="n">المباع</th>
                        <th style={{ textAlign: 'center' }}>كمية الإرجاع</th>
                        <th>حالة الصنف الراجع</th>
                        <th className="n">المسترد</th>
                      </tr>
                    </thead>
                    <tbody>
                      {sale.items.map((i) => {
                        const p = posMockService.getProductById(i.id);
                        const s = sel[i.id] || { qty: 0, to: 'on_hand' };
                        const on = s.qty > 0;
                        const upd = (v: Partial<{ qty: number; to: 'on_hand' | 'damaged' }>) =>
                          setSel((x) => ({ ...x, [i.id]: { ...s, ...v } }));

                        return (
                          <tr key={i.id} className={on ? 'selected' : ''}>
                            <td>
                              <Check
                                on={on}
                                onChange={(v) => upd({ qty: v ? 1 : 0 })}
                              />
                            </td>
                            <td>
                              <div className="row gap-2">
                                <PImg p={p} size="xs" />
                                <div>
                                  <div className="fs-13 fw-5">{p?.name}</div>
                                  <div className="mono t-3" style={{ fontSize: 11 }}>
                                    {p?.sku}
                                  </div>
                                </div>
                              </div>
                            </td>
                            <td className="n">{egp(i.price)}</td>
                            <td className="n">{fmt(i.qty)}</td>
                            <td style={{ textAlign: 'center' }}>
                              <div className="stepper-q" style={{ opacity: on ? 1 : 0.5 }}>
                                <button
                                  onClick={() => upd({ qty: Math.min(i.qty, s.qty + 1) })}
                                >
                                  <Icon n="plus" s={13} />
                                </button>
                                <span>{fmt(s.qty)}</span>
                                <button
                                  onClick={() => upd({ qty: Math.max(0, s.qty - 1) })}
                                >
                                  <Icon n="minus" s={13} />
                                </button>
                              </div>
                            </td>
                            <td>
                              <div
                                className="seg"
                                style={{
                                  opacity: on ? 1 : 0.45,
                                  pointerEvents: on ? 'auto' : 'none',
                                }}
                              >
                                <button
                                  aria-pressed={s.to === 'on_hand'}
                                  onClick={() => upd({ to: 'on_hand' })}
                                >
                                  <span className="row gap-1">
                                    <i
                                      style={{
                                        width: 7,
                                        height: 7,
                                        borderRadius: 9,
                                        background: 'var(--ok)',
                                      }}
                                    />
                                    سليم
                                  </span>
                                </button>
                                <button
                                  aria-pressed={s.to === 'damaged'}
                                  onClick={() => upd({ to: 'damaged' })}
                                >
                                  <span className="row gap-1">
                                    <i
                                      style={{
                                        width: 7,
                                        height: 7,
                                        borderRadius: 9,
                                        background: 'var(--bad)',
                                      }}
                                    />
                                    تالف
                                  </span>
                                </button>
                              </div>
                            </td>
                            <td className="n fw-6">{on ? egp(s.qty * i.price) : '—'}</td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>

                {mode === 'exchange' && (
                  <div
                    className="card card-pad col gap-3"
                    style={{ background: 'var(--bg-sunken)' }}
                  >
                    <div className="row between">
                      <span className="section-label">الصنف البديل</span>
                      <span className="fs-12 t-3">
                        ينفَّذ الإرجاع والفاتورة الجديدة في عملية واحدة
                      </span>
                    </div>
                    <div style={{ position: 'relative' }}>
                      <Search
                        value={rq}
                        onChange={setRq}
                        placeholder="ابحث عن الصنف البديل بالاسم أو الكود…"
                      />
                      {results.length > 0 && (
                        <div className="scan-results" style={{ maxHeight: 240 }}>
                          {results.map((p) => (
                            <button
                              key={p.id}
                              className="scan-row"
                              onClick={() => {
                                setRepl((r) =>
                                  r.find((x) => x.id === p.id)
                                    ? r.map((x) =>
                                        x.id === p.id ? { ...x, qty: x.qty + 1 } : x
                                      )
                                    : [...r, { id: p.id, qty: 1 }]
                                );
                                setRq('');
                              }}
                            >
                              <PImg p={p} size="xs" />
                              <span className="grow fs-13">{p.name}</span>
                              <span className="badge b-neutral">رصيد {fmt(p.stock)}</span>
                              <span className="price">{egp(p.price)}</span>
                            </button>
                          ))}
                        </div>
                      )}
                    </div>
                    {repl.map((r) => {
                      const p = posMockService.getProductById(r.id);
                      if (!p) return null;
                      return (
                        <div
                          key={r.id}
                          className="row gap-3"
                          style={{
                            background: 'var(--bg-card)',
                            padding: 10,
                            borderRadius: 12,
                            border: '1px solid var(--line)',
                          }}
                        >
                          <PImg p={p} size="xs" />
                          <span className="grow fs-13 fw-5">{p.name}</span>
                          <div className="stepper-q">
                            <button
                              onClick={() =>
                                setRepl((x) =>
                                  x.map((y) => (y.id === r.id ? { ...y, qty: y.qty + 1 } : y))
                                )
                              }
                            >
                              <Icon n="plus" s={13} />
                            </button>
                            <span>{fmt(r.qty)}</span>
                            <button
                              onClick={() =>
                                setRepl((x) =>
                                  r.qty > 1
                                    ? x.map((y) =>
                                        y.id === r.id ? { ...y, qty: y.qty - 1 } : y
                                      )
                                    : x.filter((y) => y.id !== r.id)
                                )
                              }
                            >
                              <Icon n="minus" s={13} />
                            </button>
                          </div>
                          <span className="price" style={{ width: 80, textAlign: 'end' }}>
                            {egp(p.price * r.qty)}
                          </span>
                        </div>
                      );
                    })}
                    {repl.length === 0 && (
                      <div className="fs-13 t-3">لم يتم اختيار صنف بديل بعد.</div>
                    )}
                  </div>
                )}
              </>
            )}
          </div>

          {sale && (
            <div
              className="row gap-3"
              style={{
                padding: '14px 22px',
                borderTop: '1px solid var(--line)',
                background: 'var(--bg-card)',
              }}
            >
              {mode === 'return' ? (
                <div
                  className="net grow"
                  style={{
                    background: 'var(--bad-bg)',
                    color: 'var(--bad)',
                    padding: '10px 14px',
                  }}
                >
                  <Icon n="cash" s={18} />
                  <span className="fs-13 fw-6">رد نقدية للعميل</span>
                  <span className="ms-auto display fs-20 fw-6 num">{egp(retTotal)} ج</span>
                </div>
              ) : (
                <div
                  className="net grow"
                  style={{
                    background:
                      !anySel || !repl.length
                        ? 'var(--bg-sunken)'
                        : net > 0
                        ? 'var(--ok-bg)'
                        : net < 0
                        ? 'var(--bad-bg)'
                        : 'var(--info-bg)',
                    color:
                      !anySel || !repl.length
                        ? 'var(--text-2)'
                        : net > 0
                        ? 'var(--ok)'
                        : net < 0
                        ? 'var(--bad)'
                        : 'var(--info)',
                    padding: '10px 14px',
                  }}
                >
                  <span className="fs-12 t-2" style={{ color: 'inherit', opacity: 0.8 }}>
                    مرتجع {egp(retTotal)} ← بديل {egp(replTotal)}
                  </span>
                  <span className="fs-13 fw-6">
                    {net > 0
                      ? 'تحصيل فرق من العميل'
                      : net < 0
                      ? 'رد فرق للعميل'
                      : 'بدون فروق'}
                  </span>
                  <span className="ms-auto display fs-20 fw-6 num">{egp(Math.abs(net))} ج</span>
                </div>
              )}
              <Api m="POST" path={`/pos/sales/${sale.id}/${mode}`} />
              <button
                className="btn btn-primary btn-lg"
                disabled={!canSubmit}
                onClick={handleSubmit}
              >
                <Icon n="check" s={16} w={2.4} />
                {busy
                  ? 'جارِ التنفيذ…'
                  : mode === 'return'
                  ? 'تأكيد المرتجع'
                  : 'تأكيد الاستبدال'}
              </button>
            </div>
          )}
        </div>
      </div>
    </div>,
    document.body
  );
}
```

## File: bags-platform/frontend/apps/pos/src/services/pos-mock.service.ts
```typescript
import type {
  Branch,
  CashierUser,
  Treasury,
  ProductCategory,
  PosProduct,
  Customer,
  PosCartLine,
  ShiftStats,
  PosShift,
  PosHeldSale,
  PastSale,
  StockTransfer,
  TransferSource,
  StocktakeSession,
  CreateSalePayload,
} from '../types/pos.types';

const INITIAL_BRANCH: Branch = { id: 3, name: 'فرع مدينة نصر', code: 'NSR', is_active: true };

const INITIAL_CASHIER: CashierUser = {
  id: 21,
  name: 'أحمد سمير',
  initials: 'أس',
  role: 'كاشير أول',
  perms: ['pos.override_price', 'pos.sales.refund', 'transfers.create', 'transfers.receive', 'stocktakes.update'],
};

const INITIAL_TREASURIES: Treasury[] = [
  { id: 1, name: 'درج كاشير ١', code: 'DRW-01', state: 'free', last: 'آخر إغلاق أمس ١١:٤٠ م' },
  { id: 2, name: 'درج كاشير ٢', code: 'DRW-02', state: 'free', last: 'آخر إغلاق أمس ١٠:١٥ م' },
  { id: 4, name: 'درج الصالة', code: 'DRW-04', state: 'busy', last: 'مفتوح مع منى عادل' },
];

const INITIAL_CATS: ProductCategory[] = [
  { id: 'all', name: 'الكل' },
  { id: 'hand', name: 'شنط يد' },
  { id: 'cross', name: 'كروس' },
  { id: 'back', name: 'باك باك' },
  { id: 'wallet', name: 'محافظ' },
  { id: 'travel', name: 'سفر' },
  { id: 'acc', name: 'إكسسوارات' },
];

const INITIAL_PRODUCTS: PosProduct[] = [
  { id: 101, sku: 'HB-2041-BLK', barcode: '6221031204115', name: 'شنطة يد جلد كلاسيك — أسود', cat: 'hand', price: 450, wholesale: 390, stock: 14, reorder: 6, h: 30 },
  { id: 102, sku: 'HB-2041-CML', barcode: '6221031204122', name: 'شنطة يد جلد كلاسيك — جملي', cat: 'hand', price: 450, wholesale: 390, stock: 4, reorder: 6, h: 60 },
  { id: 103, sku: 'CB-1180-RED', barcode: '6221031118009', name: 'كروس صغيرة بسلسلة — أحمر', cat: 'cross', price: 285, wholesale: 240, stock: 22, reorder: 8, h: 18 },
  { id: 104, sku: 'CB-1180-BEG', barcode: '6221031118016', name: 'كروس صغيرة بسلسلة — بيج', cat: 'cross', price: 285, wholesale: 240, stock: 9, reorder: 8, h: 75 },
  { id: 105, sku: 'BP-3302-NVY', barcode: '6221031330241', name: 'باك باك لابتوب ١٥ بوصة — كحلي', cat: 'back', price: 620, wholesale: 540, stock: 7, reorder: 4, h: 255 },
  { id: 106, sku: 'BP-3305-GRY', barcode: '6221031330555', name: 'باك باك مدرسي مقاوم للمياه', cat: 'back', price: 380, wholesale: 320, stock: 0, reorder: 5, h: 220 },
  { id: 107, sku: 'WL-0712-BLK', barcode: '6221031071205', name: 'محفظة رجالي جلد طبيعي', cat: 'wallet', price: 195, wholesale: 160, stock: 41, reorder: 12, h: 40 },
  { id: 108, sku: 'WL-0820-PNK', barcode: '6221031082003', name: 'محفظة حريمي بسوستة — وردي', cat: 'wallet', price: 230, wholesale: 190, stock: 3, reorder: 10, h: 350 },
  { id: 109, sku: 'TR-5510-BLK', barcode: '6221031551004', name: 'شنطة سفر كابينة ٢٠ بوصة', cat: 'travel', price: 1450, wholesale: 1280, stock: 5, reorder: 3, h: 280 },
  { id: 110, sku: 'TR-5520-OLV', barcode: '6221031552001', name: 'حقيبة ويك إند قماش — زيتي', cat: 'travel', price: 760, wholesale: 660, stock: 11, reorder: 4, h: 110 },
  { id: 111, sku: 'AC-0091-GLD', barcode: '6221031009109', name: 'ميدالية مفاتيح معدن ذهبي', cat: 'acc', price: 65, wholesale: 50, stock: 88, reorder: 20, h: 85 },
  { id: 112, sku: 'AC-0105-STR', barcode: '6221031010501', name: 'حزام كتف بديل مطرز', cat: 'acc', price: 120, wholesale: 95, stock: 2, reorder: 10, h: 160 },
  { id: 113, sku: 'HB-2077-WHT', barcode: '6221031207703', name: 'توت باج كبيرة — أوف وايت', cat: 'hand', price: 540, wholesale: 470, stock: 16, reorder: 6, h: 95 },
  { id: 114, sku: 'CB-1215-BRN', barcode: '6221031121504', name: 'كروس رجالي جلد — بني', cat: 'cross', price: 340, wholesale: 290, stock: 6, reorder: 6, h: 45 },
  { id: 115, sku: 'WL-0901-BLU', barcode: '6221031090107', name: 'حافظة كروت RFID — أزرق', cat: 'wallet', price: 145, wholesale: 120, stock: 27, reorder: 10, h: 230 },
];

const INITIAL_CUSTOMERS: Customer[] = [
  { id: 15, name: 'مها فتحي', phone: '01012345678', tier: 'wholesale', points: 1240 },
  { id: 22, name: 'كريم عبد الله', phone: '01155550912', tier: 'retail', points: 320 },
  { id: 31, name: 'محلات النور للشنط', phone: '01228877410', tier: 'wholesale', points: 0 },
];

const INITIAL_HELD_SEED: PosHeldSale[] = [
  { ref: 'HLD-NSR-0042', at: '٠٢:١٤ م', mins: 18, customer: 'كريم عبد الله', note: 'زبون بيجيب محفظة إضافية', items: [{ id: 105, qty: 1 }, { id: 107, qty: 2 }] },
  { ref: 'HLD-NSR-0041', at: '١٢:٥٠ م', mins: 102, customer: null, note: 'بانتظار العميل', items: [{ id: 109, qty: 1 }] },
];

const INITIAL_PAST_SALES: Record<string, PastSale> = {
  'POS-NSR-10233': {
    id: 10233,
    number: 'POS-NSR-10233',
    date: '٣ نوفمبر ٢٠٢٦ — ٠٤:٢٢ م',
    cashier: 'منى عادل',
    customer: 'مها فتحي',
    method: 'نقدي',
    items: [
      { id: 101, qty: 2, price: 450 },
      { id: 107, qty: 1, price: 195 },
      { id: 111, qty: 3, price: 65 },
    ],
  },
};

const INITIAL_TRANSFERS_IN: StockTransfer[] = [
  {
    id: 881,
    number: 'TRF-2026-0881',
    from: 'المخزن الرئيسي — العبور',
    status: 'shipped',
    shipped: 'اليوم ٠٩:٣٠ ص',
    driver: 'محمود — ٠١٠٩٩٨٨٧٧٦',
    items: [{ id: 102, qty: 10 }, { id: 108, qty: 12 }, { id: 112, qty: 15 }, { id: 106, qty: 8 }],
  },
  {
    id: 874,
    number: 'TRF-2026-0874',
    from: 'فرع المعادي',
    status: 'shipped',
    shipped: 'أمس ٠٦:١٠ م',
    driver: 'عربية الفرع',
    items: [{ id: 104, qty: 5 }, { id: 114, qty: 4 }],
  },
];

const INITIAL_SOURCES: TransferSource[] = [
  { id: 1, name: 'المخزن الرئيسي — العبور', kind: 'warehouse' },
  { id: 2, name: 'فرع المعادي', kind: 'store' },
  { id: 5, name: 'فرع مصر الجديدة', kind: 'store' },
  { id: 6, name: 'فرع ٦ أكتوبر', kind: 'store' },
];

function delay(ms = 180): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

export class PosMockService {
  private branch: Branch = { ...INITIAL_BRANCH };
  private cashier: CashierUser = { ...INITIAL_CASHIER };
  private treasuries: Treasury[] = [...INITIAL_TREASURIES];
  private categories: ProductCategory[] = [...INITIAL_CATS];
  private products: PosProduct[] = [...INITIAL_PRODUCTS];
  private customers: Customer[] = [...INITIAL_CUSTOMERS];
  private heldSales: PosHeldSale[] = [...INITIAL_HELD_SEED];
  private pastSales: Record<string, PastSale> = { ...INITIAL_PAST_SALES };
  private transfersIn: StockTransfer[] = [...INITIAL_TRANSFERS_IN];
  private sources: TransferSource[] = [...INITIAL_SOURCES];

  private currentShift: PosShift | null = {
    id: 512,
    shift_number: 'SH-NSR-0512',
    status: 'open',
    treasury_id: 1,
    treasury: INITIAL_TREASURIES[0],
    branch_id: INITIAL_BRANCH.id,
    branch: INITIAL_BRANCH,
    cashier: INITIAL_CASHIER,
    opening_cash: 500,
    cash_sales_total: 7360,
    card_sales_total: 2840,
    cash_returns_total: 450,
    cash_expenses_total: 180,
    expected_closing_cash: 500 + 7360 - 450 - 180,
    opened_at: new Date(Date.now() - 4 * 60 * 60 * 1000),
    invoices_count: 23,
  };

  private stocktakeSession: StocktakeSession = {
    id: 19,
    code: 'STK-NSR-0019',
    status: 'in_progress',
    counts: { 101: 14, 103: 21, 107: 41, 111: 85 },
    lastScan: { id: 111, qty: 85, via: 'voice', words: 'خمسة وتمانين' },
    log: [
      { id: 111, add: 85, via: 'voice', words: 'خمسة وتمانين', t: '٠٣:٤٢' },
      { id: 107, add: 41, via: 'barcode', t: '٠٣:٣٨' },
      { id: 103, add: 21, via: 'barcode', t: '٠٣:٣١' },
      { id: 101, add: 14, via: 'barcode', t: '٠٣:٢٥' },
    ],
  };

  private listeners: Set<() => void> = new Set();

  public subscribe(cb: () => void): () => void {
    this.listeners.add(cb);
    return () => this.listeners.delete(cb);
  }

  private notify(): void {
    this.listeners.forEach((cb) => cb());
  }

  // Branch & Cashier
  public async getBranch(): Promise<Branch> {
    await delay(50);
    return { ...this.branch };
  }

  public async getCashier(): Promise<CashierUser> {
    await delay(50);
    return { ...this.cashier };
  }

  // Shift API
  public async getTreasuries(): Promise<Treasury[]> {
    await delay(120);
    return this.treasuries.map((t) => ({ ...t }));
  }

  public async getCurrentShift(): Promise<PosShift | null> {
    await delay(100);
    return this.currentShift ? { ...this.currentShift } : null;
  }

  public async openShift(treasuryId: number, openingCash: number): Promise<PosShift> {
    await delay(300);
    const treasury = this.treasuries.find((t) => t.id === treasuryId) || this.treasuries[0];
    treasury.state = 'busy';

    const newShift: PosShift = {
      id: Math.floor(Math.random() * 900) + 100,
      shift_number: `SH-NSR-${String(Math.floor(Math.random() * 900) + 100).padStart(4, '0')}`,
      status: 'open',
      treasury_id: treasury.id,
      treasury: { ...treasury },
      branch_id: this.branch.id,
      branch: { ...this.branch },
      cashier: { ...this.cashier },
      opening_cash: openingCash,
      cash_sales_total: 0,
      card_sales_total: 0,
      cash_returns_total: 0,
      cash_expenses_total: 0,
      expected_closing_cash: openingCash,
      opened_at: new Date(),
      invoices_count: 0,
    };

    this.currentShift = newShift;
    this.notify();
    return { ...newShift };
  }

  public async closeShift(actualClosingCash: number, notes?: string): Promise<PosShift> {
    await delay(350);
    if (!this.currentShift) throw new Error('لا توجد وردية مفتوحة للإغلاق');

    const expected =
      this.currentShift.opening_cash +
      this.currentShift.cash_sales_total -
      this.currentShift.cash_returns_total -
      this.currentShift.cash_expenses_total;

    this.currentShift.status = 'pending_approval';
    this.currentShift.closed_at = new Date();
    this.currentShift.actual_closing_cash = actualClosingCash;
    this.currentShift.expected_closing_cash = expected;
    this.currentShift.cash_difference = actualClosingCash - expected;
    this.currentShift.notes = notes || null;

    const treasury = this.treasuries.find((t) => t.id === this.currentShift?.treasury_id);
    if (treasury) {
      treasury.state = 'free';
      treasury.last = `آخر إغلاق اليوم ${new Date().toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' })}`;
    }

    const closed = { ...this.currentShift };
    this.currentShift = null;
    this.notify();
    return closed;
  }

  public async getShiftStats(): Promise<ShiftStats> {
    await delay(80);
    if (!this.currentShift) {
      return { cashSales: 0, cardSales: 0, returns: 0, expenses: 0, invoices: 0 };
    }
    return {
      cashSales: this.currentShift.cash_sales_total,
      cardSales: this.currentShift.card_sales_total,
      returns: this.currentShift.cash_returns_total,
      expenses: this.currentShift.cash_expenses_total,
      invoices: this.currentShift.invoices_count ?? 0,
    };
  }

  // Catalog & Products
  public async getCategories(): Promise<ProductCategory[]> {
    await delay(60);
    return [...this.categories];
  }

  public async getProducts(cat = 'all', query = '', lowStockOnly = false): Promise<PosProduct[]> {
    await delay(120);
    const q = query.trim().toLowerCase();
    return this.products.filter((p) => {
      const matchCat = cat === 'all' || p.cat === cat;
      const matchQ = !q || p.name.includes(q) || p.sku.toLowerCase().includes(q) || p.barcode.includes(q);
      const matchLow = !lowStockOnly || p.stock <= p.reorder;
      return matchCat && matchQ && matchLow;
    });
  }

  public getProductById(id: number): PosProduct | undefined {
    return this.products.find((p) => p.id === id);
  }

  // Customers
  public async getCustomers(query = ''): Promise<Customer[]> {
    await delay(80);
    const q = query.trim();
    if (!q) return [...this.customers];
    return this.customers.filter((c) => c.phone.includes(q) || c.name.includes(q));
  }

  public async createQuickCustomer(data: { name: string; phone: string; tier: 'retail' | 'wholesale' }): Promise<Customer> {
    await delay(200);
    const newCust: Customer = {
      id: Math.floor(Math.random() * 900) + 100,
      name: data.name,
      phone: data.phone,
      tier: data.tier,
      points: 0,
    };
    this.customers.push(newCust);
    this.notify();
    return { ...newCust };
  }

  // Sales & Checkout
  public async createSale(payload: CreateSalePayload): Promise<{ order: PastSale; invoiceNumber: string }> {
    await delay(350);

    const invoiceNum = `POS-NSR-${Math.floor(Math.random() * 9000) + 10000}`;

    // Deduct stock
    for (const item of payload.items) {
      const product = this.products.find((p) => p.id === item.product_variant_id);
      if (product) {
        product.stock = Math.max(0, product.stock - item.qty);
      }
    }

    // Update active shift stats
    if (this.currentShift) {
      this.currentShift.cash_sales_total += payload.cash_amount || 0;
      this.currentShift.card_sales_total += payload.card_amount || 0;
      this.currentShift.invoices_count = (this.currentShift.invoices_count || 0) + 1;
      this.currentShift.expected_closing_cash =
        this.currentShift.opening_cash +
        this.currentShift.cash_sales_total -
        this.currentShift.cash_returns_total -
        this.currentShift.cash_expenses_total;
    }

    // Record past sale
    const customer = payload.customer_id ? this.customers.find((c) => c.id === payload.customer_id) : null;
    const saleOrder: PastSale = {
      id: Math.floor(Math.random() * 90000) + 10000,
      number: invoiceNum,
      date: new Date().toLocaleDateString('ar-EG', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
      }) + ` — ${new Date().toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' })}`,
      cashier: this.cashier.name,
      customer: customer ? customer.name : 'عميل نقدي',
      method: payload.payment_method === 'cash' ? 'نقدي' : payload.payment_method === 'card' ? 'بطاقة' : 'مختلط',
      items: payload.items.map((i) => ({
        id: i.product_variant_id,
        qty: i.qty,
        price: i.unit_price,
      })),
    };

    this.pastSales[invoiceNum] = saleOrder;
    this.notify();
    return { order: saleOrder, invoiceNumber: invoiceNum };
  }

  // Held Sales
  public async getHeldSales(): Promise<PosHeldSale[]> {
    await delay(80);
    return [...this.heldSales];
  }

  public async holdSale(cart: PosCartLine[], customer: Customer | null, note: string): Promise<PosHeldSale> {
    await delay(250);
    const newHeld: PosHeldSale = {
      ref: `HLD-NSR-${String(Math.floor(Math.random() * 9000) + 1000).padStart(4, '0')}`,
      at: new Date().toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' }),
      mins: 1,
      customer: customer ? customer.name : null,
      note,
      items: cart.map((c) => ({ id: c.id, qty: c.qty })),
    };
    this.heldSales.unshift(newHeld);
    this.notify();
    return { ...newHeld };
  }

  public async deleteHeldSale(ref: string): Promise<void> {
    await delay(120);
    this.heldSales = this.heldSales.filter((h) => h.ref !== ref);
    this.notify();
  }

  // Past sales & Returns
  public async getPastSale(num: string): Promise<PastSale | null> {
    await delay(150);
    const key = num.trim().toUpperCase();
    return this.pastSales[key] || this.pastSales[`POS-NSR-${key}`] || null;
  }

  public async processReturn(
    saleId: number,
    items: { id: number; qty: number; return_to: 'on_hand' | 'damaged' }[]
  ): Promise<void> {
    await delay(350);
    let totalRefund = 0;
    for (const item of items) {
      const prod = this.products.find((p) => p.id === item.id);
      if (prod) {
        if (item.return_to === 'on_hand') {
          prod.stock += item.qty;
        }
        totalRefund += prod.price * item.qty;
      }
    }

    if (this.currentShift) {
      this.currentShift.cash_returns_total += totalRefund;
      this.currentShift.expected_closing_cash =
        this.currentShift.opening_cash +
        this.currentShift.cash_sales_total -
        this.currentShift.cash_returns_total -
        this.currentShift.cash_expenses_total;
    }
    this.notify();
  }

  public async processExchange(
    saleId: number,
    returnItems: { id: number; qty: number; return_to: 'on_hand' | 'damaged' }[],
    exchangeItems: { id: number; qty: number }[],
    netDiff: number
  ): Promise<void> {
    await delay(400);
    // Returns
    for (const item of returnItems) {
      const prod = this.products.find((p) => p.id === item.id);
      if (prod && item.return_to === 'on_hand') {
        prod.stock += item.qty;
      }
    }
    // Replacements
    for (const item of exchangeItems) {
      const prod = this.products.find((p) => p.id === item.id);
      if (prod) {
        prod.stock = Math.max(0, prod.stock - item.qty);
      }
    }
    // Financial diff
    if (this.currentShift) {
      if (netDiff > 0) {
        this.currentShift.cash_sales_total += netDiff;
      } else if (netDiff < 0) {
        this.currentShift.cash_returns_total += Math.abs(netDiff);
      }
      this.currentShift.expected_closing_cash =
        this.currentShift.opening_cash +
        this.currentShift.cash_sales_total -
        this.currentShift.cash_returns_total -
        this.currentShift.cash_expenses_total;
    }
    this.notify();
  }

  // Transfers
  public async getTransfersIn(): Promise<StockTransfer[]> {
    await delay(120);
    return [...this.transfersIn];
  }

  public async receiveTransfer(transferId: number, quantities: Record<number, number>): Promise<void> {
    await delay(350);
    const transfer = this.transfersIn.find((t) => t.id === transferId);
    if (!transfer) return;

    for (const item of transfer.items) {
      const receivedQty = quantities[item.id] ?? item.qty;
      const product = this.products.find((p) => p.id === item.id);
      if (product) {
        product.stock += receivedQty;
      }
    }

    transfer.status = 'received';
    this.transfersIn = this.transfersIn.filter((t) => t.id !== transferId);
    this.notify();
  }

  public async getTransferSources(): Promise<TransferSource[]> {
    await delay(60);
    return [...this.sources];
  }

  public async requestTransfer(
    fromBranchId: number,
    items: { id: number; qty: number }[],
    notes?: string
  ): Promise<string> {
    await delay(300);
    const transferNum = `TRF-REQ-${Math.floor(Math.random() * 9000) + 1000}`;
    this.notify();
    return transferNum;
  }

  // Stocktake
  public async getStocktakeSession(): Promise<{ session: StocktakeSession; scope: PosProduct[] }> {
    await delay(100);
    const scope = this.products.slice(0, 12);
    return {
      session: {
        ...this.stocktakeSession,
        counts: { ...this.stocktakeSession.counts },
        log: [...this.stocktakeSession.log],
      },
      scope,
    };
  }

  public async recordStocktakeScan(
    variantId: number,
    qty: number,
    via: 'voice' | 'barcode',
    words?: string
  ): Promise<void> {
    await delay(100);
    const prevCount = this.stocktakeSession.counts[variantId] || 0;
    const newCount = prevCount + qty;
    this.stocktakeSession.counts[variantId] = newCount;
    this.stocktakeSession.lastScan = {
      id: variantId,
      qty: newCount,
      via,
      words,
    };
    this.stocktakeSession.log.unshift({
      id: variantId,
      add: qty,
      via,
      words,
      t: new Date()
        .toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' })
        .replace(/\s?[صم]$/, ''),
    });
    if (this.stocktakeSession.log.length > 10) {
      this.stocktakeSession.log = this.stocktakeSession.log.slice(0, 10);
    }
    this.notify();
  }
}

export const posMockService = new PosMockService();
```

## File: bags-platform/frontend/apps/pos/src/types/pos.types.ts
```typescript
export type AppScreen = 'terminal' | 'stock' | 'transfers' | 'stocktake';

export interface Branch {
  id: number;
  name: string;
  code: string;
  is_active?: boolean;
}

export interface CashierUser {
  id: number;
  name: string;
  initials: string;
  role: string;
  perms: string[];
}

export interface Treasury {
  id: number;
  name: string;
  code: string;
  state: 'free' | 'busy';
  last: string;
}

export interface ProductCategory {
  id: string;
  name: string;
}

export interface PosProduct {
  id: number;
  sku: string;
  barcode: string;
  name: string;
  cat: 'hand' | 'cross' | 'back' | 'wallet' | 'travel' | 'acc' | string;
  price: number;
  wholesale: number;
  stock: number;
  reorder: number;
  h: number; // Hue for placeholder art
}

export interface Customer {
  id: number;
  name: string;
  phone: string;
  tier: 'retail' | 'wholesale';
  points: number;
}

export interface PosCartLine {
  id: number;
  qty: number;
  price: number;
  listPrice: number;
}

export interface ShiftStats {
  cashSales: number;
  cardSales: number;
  returns: number;
  expenses: number;
  invoices: number;
}

export interface PosShift {
  id: number;
  shift_number: string;
  status: 'open' | 'pending_approval' | 'closed';
  treasury_id: number;
  treasury: Treasury;
  branch_id?: number;
  branch?: Branch;
  cashier?: CashierUser;
  opening_cash: number;
  cash_sales_total: number;
  card_sales_total: number;
  cash_returns_total: number;
  cash_expenses_total: number;
  expected_closing_cash: number;
  actual_closing_cash?: number | null;
  cash_difference?: number | null;
  notes?: string | null;
  opened_at: Date;
  closed_at?: Date | null;
  invoices_count?: number;
}

export interface PosHeldSale {
  ref: string;
  at: string;
  mins: number;
  customer: string | null;
  note: string;
  items: { id: number; qty: number }[];
}

export interface PastSaleItem {
  id: number;
  qty: number;
  price: number;
}

export interface PastSale {
  id: number;
  number: string;
  date: string;
  cashier: string;
  customer: string;
  method: string;
  items: PastSaleItem[];
}

export interface StockTransferItem {
  id: number;
  qty: number;
}

export interface StockTransfer {
  id: number;
  number: string;
  from: string;
  status: 'shipped' | 'received' | 'pending' | 'draft';
  shipped: string;
  driver: string;
  items: StockTransferItem[];
}

export interface TransferSource {
  id: number;
  name: string;
  kind: 'warehouse' | 'store';
}

export interface StocktakeScanLog {
  id: number;
  add: number;
  via: 'voice' | 'barcode';
  words?: string;
  t: string;
}

export interface StocktakeSession {
  id: number;
  code: string;
  status: 'draft' | 'in_progress' | 'completed';
  counts: Record<number, number>;
  lastScan?: {
    id: number;
    qty: number;
    via: 'voice' | 'barcode';
    words?: string;
  };
  log: StocktakeScanLog[];
}

export interface CreateSalePayload {
  items: {
    product_variant_id: number;
    qty: number;
    unit_price: number;
    discount_amount?: number;
  }[];
  customer_id: number | null;
  payment_method: 'cash' | 'card' | 'mixed';
  cash_amount: number;
  card_amount: number;
  notes?: string;
}
```

## File: bags-platform/frontend/apps/storefront/src/lib/safe-return-path.ts
```typescript
/** Only local application paths can be used after authentication. */
export function safeReturnPath(value: string | null): string | null {
  if (!value || !value.startsWith('/') || value.startsWith('//') || /[\\\u0000-\u0020]/.test(value)) return null;
  try {
    const decoded = decodeURIComponent(value);
    if (decoded.startsWith('//') || /[\\\u0000-\u0020]/.test(decoded)) return null;
    const url = new URL(value, 'https://store.invalid');
    if (url.origin !== 'https://store.invalid' || /^\/(auth|supplier|wholesale)(?:\/|$)/i.test(decodeURIComponent(url.pathname))) return null;
    return url.pathname + url.search + url.hash;
  } catch { return null; }
}
```

## File: new_design/returns.jsx
```javascript
// Screen 7 — Return & Exchange modal
function ReturnModal({ open, onClose, toast }) {
  const [num, setNum] = useState('');
  const [sale, setSale] = useState(null);
  const [err, setErr] = useState('');
  const [mode, setMode] = useState('return');
  const [sel, setSel] = useState({}); // id -> {qty, to}
  const [repl, setRepl] = useState([]); // {id, qty}
  const [rq, setRq] = useState('');
  const [busy, setBusy] = useState(false);
  useEffect(() => { if (open) { setNum(''); setSale(null); setErr(''); setMode('return'); setSel({}); setRepl([]); setRq(''); setBusy(false); } }, [open]);
  useEsc(onClose, open);
  if (!open) return null;

  const find = () => {
    const key = num.trim().toUpperCase();
    const s = PAST_SALES[key] || PAST_SALES['POS-NSR-' + key];
    if (s) { setSale({ ...s, number: PAST_SALES[key] ? key : 'POS-NSR-' + key }); setErr(''); } else { setSale(null); setErr('لم يتم العثور على فاتورة بهذا الرقم في الفرع'); }
  };
  const retTotal = sale ? sale.items.reduce((s, i) => s + (sel[i.id]?.qty || 0) * i.price, 0) : 0;
  const replTotal = repl.reduce((s, r) => s + pById(r.id).price * r.qty, 0);
  const net = replTotal - retTotal;
  const anySel = Object.values(sel).some(v => v.qty > 0);
  const canSubmit = anySel && (mode === 'return' || repl.length > 0) && !busy;
  const results = rq ? PRODUCTS.filter(p => p.name.includes(rq) || p.sku.toLowerCase().includes(rq.toLowerCase())).slice(0, 4) : [];

  return ReactDOM.createPortal(<div dir="rtl" className={document.querySelector('.app')?.classList.contains('theme-dark') ? 'theme-dark' : ''}>
    <div className="overlay" onClick={onClose} />
    <div className="modal" style={{ width: 'min(920px, calc(100vw - 32px))' }} data-screen-label="07 Return & Exchange" role="dialog" aria-modal="true">
      <div className="pop">
        <div className="row gap-3" style={{ padding: '18px 22px', borderBottom: '1px solid var(--line)' }}>
          <div className="grow col"><h3 className="fs-16">مرتجع / استبدال</h3><span className="perm"><Icon n="shield" s={12} />pos.sales.refund</span></div>
          <Seg value={mode} onChange={setMode} options={[{ value: 'return', label: 'إرجاع فقط' }, { value: 'exchange', label: 'استبدال بصنف آخر' }]} />
          <button className="icon-btn sm" onClick={onClose} aria-label="إغلاق"><Icon n="x" s={16} /></button>
        </div>

        <div style={{ padding: '18px 22px', display: 'flex', flexDirection: 'column', gap: 16 }}>
          <div className="ret-head">
            <div className="scanbar grow" style={{ height: 48 }}>
              <span className="scan-ic" style={{ width: 30, height: 30 }}><Icon n="barcode" s={16} w={2} /></span>
              <input autoFocus value={num} onChange={e => setNum(e.target.value)} onKeyDown={e => e.key === 'Enter' && find()} placeholder="امسح باركود الإيصال أو اكتب رقم الفاتورة (order_number)" className="ltr" style={{ textAlign: 'right' }} />
            </div>
            <button className="btn btn-primary" style={{ height: 48 }} onClick={find}>بحث</button>
            {!sale && <button className="btn btn-ghost" style={{ height: 48 }} onClick={() => { setNum('POS-NSR-10233'); setSale({ ...PAST_SALES['POS-NSR-10233'], number: 'POS-NSR-10233' }); setErr(''); }}>مثال</button>}
          </div>
          {err && <div className="banner b-bad"><Icon n="alert" s={16} />{err}</div>}

          {!sale ? <Empty icon="ret" title="ابدأ بمسح إيصال البيع الأصلي" body="سيتم عرض بنود الفاتورة لتختار الأصناف والكميات المرتجعة وحالتها." /> : <>
            <div className="order-meta">
              <div><span>رقم الفاتورة</span><b className="mono">{sale.number}</b></div>
              <div><span>التاريخ</span><b>{sale.date}</b></div>
              <div><span>العميل</span><b>{sale.customer}</b></div>
              <div><span>الدفع / الكاشير</span><b>{sale.method} · {sale.cashier}</b></div>
            </div>
            <div className="card" style={{ overflow: 'hidden' }}>
              <table className="tbl">
                <thead><tr><th style={{ width: 40 }}></th><th>الصنف</th><th className="n">السعر</th><th className="n">المباع</th><th style={{ textAlign: 'center' }}>كمية الإرجاع</th><th>حالة الصنف الراجع</th><th className="n">المسترد</th></tr></thead>
                <tbody>{sale.items.map(i => {
                  const p = pById(i.id); const s = sel[i.id] || { qty: 0, to: 'on_hand' }; const on = s.qty > 0;
                  const upd = v => setSel(x => ({ ...x, [i.id]: { ...s, ...v } }));
                  return <tr key={i.id} className={on ? 'selected' : ''}>
                    <td><Check on={on} onChange={v => upd({ qty: v ? 1 : 0 })} /></td>
                    <td><div className="row gap-2"><PImg p={p} size="xs" /><div><div className="fs-13 fw-5">{p.name}</div><div className="mono t-3" style={{ fontSize: 11 }}>{p.sku}</div></div></div></td>
                    <td className="n">{egp(i.price)}</td>
                    <td className="n">{fmt(i.qty)}</td>
                    <td style={{ textAlign: 'center' }}><div className="stepper-q" style={{ opacity: on ? 1 : .5 }}>
                      <button onClick={() => upd({ qty: Math.min(i.qty, s.qty + 1) })}><Icon n="plus" s={13} /></button><span>{fmt(s.qty)}</span><button onClick={() => upd({ qty: Math.max(0, s.qty - 1) })}><Icon n="minus" s={13} /></button>
                    </div></td>
                    <td><div className="seg" style={{ opacity: on ? 1 : .45, pointerEvents: on ? 'auto' : 'none' }}>
                      <button aria-pressed={s.to === 'on_hand'} onClick={() => upd({ to: 'on_hand' })}><span className="row gap-1"><i style={{ width: 7, height: 7, borderRadius: 9, background: 'var(--ok)' }} />سليم</span></button>
                      <button aria-pressed={s.to === 'damaged'} onClick={() => upd({ to: 'damaged' })}><span className="row gap-1"><i style={{ width: 7, height: 7, borderRadius: 9, background: 'var(--bad)' }} />تالف</span></button>
                    </div></td>
                    <td className="n fw-6">{on ? egp(s.qty * i.price) : '—'}</td>
                  </tr>;
                })}</tbody>
              </table>
            </div>

            {mode === 'exchange' && <div className="card card-pad col gap-3" style={{ background: 'var(--bg-sunken)' }}>
              <div className="row between"><span className="section-label">الصنف البديل</span><span className="fs-12 t-3">ينفَّذ الإرجاع والفاتورة الجديدة في عملية واحدة</span></div>
              <div style={{ position: 'relative' }}>
                <Search value={rq} onChange={setRq} placeholder="ابحث عن الصنف البديل بالاسم أو الكود…" />
                {results.length > 0 && <div className="scan-results" style={{ maxHeight: 240 }}>{results.map(p => <button key={p.id} className="scan-row" onClick={() => { setRepl(r => r.find(x => x.id === p.id) ? r.map(x => x.id === p.id ? { ...x, qty: x.qty + 1 } : x) : [...r, { id: p.id, qty: 1 }]); setRq(''); }}>
                  <PImg p={p} size="xs" /><span className="grow fs-13">{p.name}</span><span className="badge b-neutral">رصيد {fmt(p.stock)}</span><span className="price">{egp(p.price)}</span></button>)}</div>}
              </div>
              {repl.map(r => { const p = pById(r.id); return <div key={r.id} className="row gap-3" style={{ background: 'var(--bg-card)', padding: 10, borderRadius: 12, border: '1px solid var(--line)' }}>
                <PImg p={p} size="xs" /><span className="grow fs-13 fw-5">{p.name}</span>
                <div className="stepper-q"><button onClick={() => setRepl(x => x.map(y => y.id === r.id ? { ...y, qty: y.qty + 1 } : y))}><Icon n="plus" s={13} /></button><span>{fmt(r.qty)}</span><button onClick={() => setRepl(x => r.qty > 1 ? x.map(y => y.id === r.id ? { ...y, qty: y.qty - 1 } : y) : x.filter(y => y.id !== r.id))}><Icon n="minus" s={13} /></button></div>
                <span className="price" style={{ width: 80, textAlign: 'end' }}>{egp(p.price * r.qty)}</span>
              </div>; })}
              {repl.length === 0 && <div className="fs-13 t-3">لم يتم اختيار صنف بديل بعد.</div>}
            </div>}
          </>}
        </div>

        {sale && <div className="row gap-3" style={{ padding: '14px 22px', borderTop: '1px solid var(--line)', background: 'var(--bg-card)' }}>
          {mode === 'return' ? <div className="net grow" style={{ background: 'var(--bad-bg)', color: 'var(--bad)', padding: '10px 14px' }}>
            <Icon n="cash" s={18} /><span className="fs-13 fw-6">رد نقدية للعميل</span><span className="ms-auto display fs-20 fw-6 num">{egp(retTotal)} ج</span>
          </div> : <div className="net grow" style={{ background: !anySel || !repl.length ? 'var(--bg-sunken)' : net > 0 ? 'var(--ok-bg)' : net < 0 ? 'var(--bad-bg)' : 'var(--info-bg)', color: !anySel || !repl.length ? 'var(--text-2)' : net > 0 ? 'var(--ok)' : net < 0 ? 'var(--bad)' : 'var(--info)', padding: '10px 14px' }}>
            <span className="fs-12 t-2" style={{ color: 'inherit', opacity: .8 }}>مرتجع {egp(retTotal)} ← بديل {egp(replTotal)}</span>
            <span className="fs-13 fw-6">{net > 0 ? 'تحصيل فرق من العميل' : net < 0 ? 'رد فرق للعميل' : 'بدون فروق'}</span>
            <span className="ms-auto display fs-20 fw-6 num">{egp(Math.abs(net))} ج</span>
          </div>}
          <Api m="POST" path={`/pos/sales/${sale.id}/${mode}`} />
          <button className="btn btn-primary btn-lg" disabled={!canSubmit} onClick={() => { setBusy(true); setTimeout(() => { toast(mode === 'return' ? 'تم تسجيل المرتجع وتحديث المخزون' : 'تم الاستبدال وإصدار فاتورة جديدة'); onClose(); }, 700); }}>
            <Icon n="check" s={16} w={2.4} />{busy ? 'جارِ التنفيذ…' : mode === 'return' ? 'تأكيد المرتجع' : 'تأكيد الاستبدال'}
          </button>
        </div>}
      </div>
    </div>
  </div>, document.body);
}

Object.assign(window, { ReturnModal });
```

## File: bags-platform/backend/app/Enums/RefundMethod.php
```php
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Supported payment channels for reimbursing approved customer return requests.
 */
enum RefundMethod: string
{
    case OriginalPayment = 'original_payment';
    case Wallet = 'wallet';
    case BankTransfer = 'bank_transfer';
    case Cash = 'cash';
}
```

## File: bags-platform/backend/app/Enums/ReturnStatus.php
```php
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Return Merchandise Authorization (RMA) lifecycle statuses. Inventory movements occur only on Received.
 */
enum ReturnStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Received = 'received';
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';
}
```

## File: bags-platform/backend/app/Http/Resources/Admin/ReturnRequestItemResource.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\ReturnRequestItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReturnRequestItem
 */
class ReturnRequestItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_item_id' => $this->order_item_id,
            'product_variant_id' => $this->product_variant_id,
            'sku' => $this->whenLoaded('orderItem', fn () => $this->orderItem?->sku_snapshot),
            'product_name' => $this->whenLoaded('orderItem', fn () => $this->orderItem?->product_name_snapshot),
            'requested_qty' => $this->requested_qty,
            'approved_qty' => $this->approved_qty,
            'received_good_qty' => $this->received_good_qty,
            'received_damaged_qty' => $this->received_damaged_qty,
            'unit_refund_price' => $this->unit_refund_price,
            'item_reason' => $this->item_reason,
            'attachments' => array_map(
                fn (int $index): string => url('/api/v1/admin/return-items/'.$this->id.'/attachments/'.$index),
                array_keys($this->attachments ?? []),
            ),
        ];
    }
}
```

## File: bags-platform/backend/app/Http/Resources/Store/ReturnRequestItemResource.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Store;

use App\Models\ReturnRequestItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReturnRequestItem
 */
class ReturnRequestItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_item_id' => $this->order_item_id,
            'product_variant_id' => $this->product_variant_id,
            'sku' => $this->whenLoaded('orderItem', fn () => $this->orderItem?->sku_snapshot),
            'product_name' => $this->whenLoaded('orderItem', fn () => $this->orderItem?->product_name_snapshot),
            'requested_qty' => $this->requested_qty,
            'approved_qty' => $this->approved_qty,
            'received_good_qty' => $this->received_good_qty,
            'received_damaged_qty' => $this->received_damaged_qty,
            'unit_refund_price' => $this->unit_refund_price,
            'item_reason' => $this->item_reason,
            'attachments' => array_map(
                fn (int $index): string => url('/api/v1/store/account/return-items/'.$this->id.'/attachments/'.$index),
                array_keys($this->attachments ?? []),
            ),
        ];
    }
}
```

## File: bags-platform/backend/app/Models/CustomerWalletTransaction.php
```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ledger entry for customer store credit balances, deposits, and order settlements.
 */
class CustomerWalletTransaction extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'balance_after' => 'decimal:2', 'created_at' => 'datetime'];
    }
}
```

## File: bags-platform/backend/app/Models/OrderItem.php
```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Individual product variant line item on a retail or wholesale order.
 */
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_variant_id',
        'product_name_snapshot',
        'sku_snapshot',
        'allocated_branch_id',
        'qty',
        'returned_qty',
        'unit_price',
        'discount_amount',
        'tax_amount',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'returned_qty' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function allocatedBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'allocated_branch_id');
    }
}
```

## File: bags-platform/backend/app/Models/PosShift.php
```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PosShiftStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cashier register shift session tracking opening cash, transactions, and drawer reconciliations.
 */
class PosShift extends Model
{
    protected $fillable = [
        'shift_number',
        'user_id',
        'branch_id',
        'treasury_id',
        'status',
        'opened_at',
        'closed_at',
        'approved_at',
        'approved_by',
        'opening_cash',
        'cash_sales_total',
        'card_sales_total',
        'cash_returns_total',
        'cash_expenses_total',
        'expected_closing_cash',
        'actual_closing_cash',
        'cash_difference',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status'                => PosShiftStatus::class,
            'opened_at'             => 'datetime',
            'closed_at'             => 'datetime',
            'approved_at'           => 'datetime',
            'opening_cash'          => 'decimal:2',
            'cash_sales_total'      => 'decimal:2',
            'card_sales_total'      => 'decimal:2',
            'cash_returns_total'    => 'decimal:2',
            'cash_expenses_total'   => 'decimal:2',
            'expected_closing_cash' => 'decimal:2',
            'actual_closing_cash'   => 'decimal:2',
            'cash_difference'       => 'decimal:2',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Treasury, $this> */
    public function treasury(): BelongsTo
    {
        return $this->belongsTo(Treasury::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'pos_shift_id');
    }

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'shift_id');
    }

    /** @return HasMany<TreasuryTransaction, $this> */
    public function treasuryTransactions(): HasMany
    {
        return $this->hasMany(TreasuryTransaction::class, 'shift_id');
    }
}
```

## File: bags-platform/backend/app/Models/ReturnRequest.php
```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RefundMethod;
use App\Enums\ReturnStatus;
use Database\Factories\ReturnRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Return Merchandise Authorization (RMA) model.
 * Creation and initial approval do not alter stock balances; inventory increments
 * occur strictly upon physical receipt and inspection in ReturnService.
 */
class ReturnRequest extends Model
{
    /** @use HasFactory<ReturnRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'return_number',
        'order_id',
        'customer_id',
        'branch_id',
        'status',
        'refund_method',
        'total_refund_amount',
        'reason',
        'admin_notes',
        'refund_reference',
        'approved_by',
        'received_by',
        'refunded_by',
        'approved_at',
        'received_at',
        'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReturnStatus::class,
            'refund_method' => RefundMethod::class,
            'total_refund_amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'received_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<ReturnRequestItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ReturnRequestItem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** @return BelongsTo<User, $this> */
    public function refunder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refunded_by');
    }
}
```

## File: bags-platform/backend/database/migrations/2026_10_18_100011_create_return_requests_tables.php
```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Return merchandise authorization (RMA) requests and item breakdowns.
 * Note: Request submission and initial approval do not adjust stock levels; inventory
 * movements occur strictly upon physical receipt and inspection via ReturnService::receive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('return_number')->unique();
            $table->foreignId('order_id')->constrained('orders');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            // Designated receiving warehouse or branch assigned during initial approval
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('refund_method', 30)->nullable();
            $table->decimal('total_refund_amount', 12, 2)->default(0);
            $table->text('reason');
            $table->text('admin_notes')->nullable();
            // External payment gateway refund ID or bank transfer receipt reference
            $table->string('refund_reference')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index(['customer_id', 'status']);
        });

        DB::statement("ALTER TABLE return_requests ADD CONSTRAINT return_requests_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'received', 'refunded', 'cancelled'))");
        DB::statement("ALTER TABLE return_requests ADD CONSTRAINT return_requests_refund_method_check CHECK (refund_method IS NULL OR refund_method IN ('original_payment', 'wallet', 'bank_transfer', 'cash'))");

        Schema::create('return_request_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('return_request_id')->constrained('return_requests')->cascadeOnDelete();
            // Source order line used to validate returnable quantity limits against prior returns
            $table->foreignId('order_item_id')->constrained('order_items');
            $table->foreignId('product_variant_id')->constrained('product_variants');
            $table->decimal('requested_qty', 12, 4);
            $table->decimal('approved_qty', 12, 4)->default(0);
            $table->decimal('received_good_qty', 12, 4)->default(0);
            $table->decimal('received_damaged_qty', 12, 4)->default(0);
            // Effective purchase price after apportioning item-level and order-level coupon discounts
            $table->decimal('unit_refund_price', 12, 4);
            $table->string('item_reason')->nullable();
            $table->json('attachments')->nullable();
            $table->timestamps();

            $table->index('order_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_request_items');
        Schema::dropIfExists('return_requests');
    }
};
```

## File: bags-platform/backend/database/migrations/2026_10_18_100013_seed_return_settings.php
```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds default return numbering sequences (RET-) and customer return eligibility window settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('settings')->insertOrIgnore([
            ['group' => 'numbering', 'key' => 'return_prefix', 'value' => 'RET-', 'type' => 'string', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'numbering', 'key' => 'return_next_number', 'value' => '1001', 'type' => 'integer', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'numbering', 'key' => 'return_padding', 'value' => '6', 'type' => 'integer', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'orders', 'key' => 'return_window_days', 'value' => '14', 'type' => 'integer', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'return_prefix', 'return_next_number', 'return_padding', 'return_window_days',
        ])->delete();
    }
};
```

## File: bags-platform/backend/database/migrations/2026_10_24_000008_add_pos_return_guards.php
```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links POS return orders to parent sales and tracks returned item quantities to prevent over-returns:
     * - orders.return_of_order_id: references original sale order (null for non-return orders).
     * - order_items.returned_qty: tracks cumulative returned quantity per order line.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'return_of_order_id')) {
                $table->foreignId('return_of_order_id')
                    ->nullable()
                    ->after('pos_shift_id')
                    ->constrained('orders')
                    ->nullOnDelete();
            }
        });

        Schema::table('order_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('order_items', 'returned_qty')) {
                $table->decimal('returned_qty', 10, 4)->default(0)->after('qty');
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE order_items DROP CONSTRAINT IF EXISTS order_items_returned_qty_check');
            DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_returned_qty_check CHECK (returned_qty <= qty AND returned_qty >= 0)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE order_items DROP CONSTRAINT IF EXISTS order_items_returned_qty_check');
        }

        Schema::table('order_items', function (Blueprint $table): void {
            if (Schema::hasColumn('order_items', 'returned_qty')) {
                $table->dropColumn('returned_qty');
            }
        });

        Schema::table('orders', function (Blueprint $table): void {
            if (Schema::hasColumn('orders', 'return_of_order_id')) {
                $table->dropConstrainedForeignId('return_of_order_id');
            }
        });
    }
};
```

## File: bags-platform/frontend/apps/backoffice/src/pages/admin/operations-mock/api/mappers.ts
```typescript
import type { AdminOrder, ReturnRequest, ShippingCompany, ShippingZone, ShippingRate, CustomerGroupApi, PriceListApi, PromotionApi, CouponApi } from '@alfarid/api-client';
import type { OrderRow, ReturnRow, ShipCompany, ShipZone, ShipRate, CustomerGroup, PriceListRow, PromoRow, CouponRow } from '../data';

const TZ = 'Africa/Cairo';
export const toDate = (iso?: string | null): string => {
  if (!iso) return '';
  const date = new Date(iso);
  if (!Number.isFinite(date.getTime())) return '';
  const parts = new Intl.DateTimeFormat('en', { timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(date);
  const value = (key: string) => parts.find(part => part.type === key)?.value ?? '';
  return value('year') + '-' + value('month') + '-' + value('day');
};
export const toTime = (iso?: string | null): string => {
  if (!iso) return '';
  const date = new Date(iso);
  if (!Number.isFinite(date.getTime())) return '';
  return date.toLocaleTimeString('en-GB', { timeZone: TZ, hour: '2-digit', minute: '2-digit' });
};
export const num = (v: string | number | null | undefined) => (v === null || v === undefined || v === '' ? 0 : Number(v));

export const mapErrors = (e: Record<string, string>, m: Record<string, string>) =>
  Object.fromEntries(Object.entries(e).map(([k, v]) => [m[k] ?? k, v])) as Record<string, string>;

const CTYPE_GUEST = 'زائر';
const CTYPE_WHOLESALE = 'جملة';
const CTYPE_RETAIL = 'قطاعي';

/** normalize backend values to the keys the current UI maps expect */
const normalizePay = (p?: string | null): string => {
  if (!p) return '';
  return p === 'bank_transfer' ? 'bank' : p;
};
const normalizeRefundMethod = (m?: string | null): string | null => {
  if (!m) return null;
  if (m === 'original_payment') return 'original';
  if (m === 'bank_transfer') return 'bank';
  return m;
};

export function mapOrder(o: AdminOrder): OrderRow {
  return {
    id: o.id,
    no: o.order_number,
    customer: o.customer?.name ?? o.guest_name ?? '—',
    guest: !o.customer,
    ctype: !o.customer ? CTYPE_GUEST : o.customer.type === 'wholesale' ? CTYPE_WHOLESALE : CTYPE_RETAIL,
    phone: o.customer?.phone ?? o.guest_phone ?? '',
    channel: o.channel ?? 'web',
    city: (o.city as any)?.name_ar ?? (o.city as any)?.name ?? '',
    status: o.status,
    pay: normalizePay(o.payment_method),
    payStatus: o.payment_status,
    branch: o.allocated_branch?.name ?? null,
    total: num(o.grand_total),
    date: toDate(o.created_at),
    time: toTime(o.created_at),
    followUp: !!o.needs_attention,
    needRefund: !!o.requires_refund,
  };
}

export function mapReturn(r: ReturnRequest, branchName?: (id: number | null) => string | null): ReturnRow {
  return {
    id: r.id,
    no: r.return_number,
    order: r.order_number ?? `#${r.order_id}`,
    customer: r.customer_name ?? '—',
    branch: branchName ? branchName(r.branch_id) : r.branch_id ? `#${r.branch_id}` : null,
    status: r.status,
    method: normalizeRefundMethod(r.refund_method),
    total: num(r.total_refund_amount),
    date: toDate(r.created_at),
  };
}

export const branchOptions = (list: { id: number; name: string }[]) => list.map((b) => ({ value: String(b.id), label: b.name }));

export const mapShipCompany = (c: ShippingCompany): ShipCompany => ({
  id: c.id, name: c.name, code: c.code, url: c.tracking_url_template ?? '', active: c.is_active, api: !!c.has_api_credentials,
});
export const mapShipZone = (z: ShippingZone): ShipZone & { cityIds: number[] } => ({
  id: z.id, name: z.name, active: z.is_active,
  cities: (z.cities ?? []).map((c) => c.name_ar ?? ''), cityIds: (z.cities ?? []).map((c) => c.id),
});
export const mapShipRate = (r: ShippingRate): ShipRate & { companyId: number } => ({
  id: r.id, co: r.company_name ?? String(r.shipping_company_id), companyId: r.shipping_company_id,
  zone: r.shipping_zone_id, base: num(r.base_cost),
  free: r.free_shipping_threshold === null ? null : num(r.free_shipping_threshold),
  cod: num(r.cod_fee ?? 0), min: r.estimated_days_min, max: r.estimated_days_max,
});
export const mapCustomerGroup = (g: CustomerGroupApi): CustomerGroup => ({
  id: g.id, name: g.name, code: g.code, desc: g.description, disc: num(g.discount_percentage), active: g.is_active,
});
export const mapPriceList = (p: PriceListApi): PriceListRow & { raw: PriceListApi } => ({
  id: p.id, name: p.name, type: p.type === 'customer_group' ? 'group' : p.type === 'customer_specific' ? 'customer' : p.type,
  target: p.customer?.name ?? p.customer_group?.name ?? null,
  branch: p.branch?.name ?? null,
  from: p.starts_at?.slice(0, 10) ?? '', to: p.ends_at?.slice(0, 10) ?? null,
  prio: p.priority, active: p.is_active, items: p.items_count ?? 0, raw: p,
});
const promoToUI = (t: string): string => t === 'percentage_discount' ? 'percent' : t === 'fixed_discount' ? 'amount' : t === 'buy_x_get_y' ? 'bxgy' : 'free_ship';
export const promoToBackend = (t: string): any => t === 'percent' ? 'percentage_discount' : t === 'amount' ? 'fixed_discount' : t === 'bxgy' ? 'buy_x_get_y' : 'free_shipping';
export const mapPromo = (p: PromotionApi): PromoRow => ({
  id: p.id, name: p.name, type: promoToUI(p.type) as PromoRow['type'], value: p.value === null ? null : num(p.value),
  min: p.min_order_amount === null ? null : num(p.min_order_amount),
  scope: p.applies_to as PromoRow['scope'], targets: [],
  from: p.starts_at?.slice(0, 10) ?? '', to: p.ends_at?.slice(0, 10) ?? null, active: p.is_active,
});
const couponToUI = (t: string): string => t === 'percentage' ? 'percent' : t === 'fixed' ? 'amount' : 'free_ship';
export const couponToBackend = (t: string): any => t === 'percent' ? 'percentage' : t === 'amount' ? 'fixed' : 'free_shipping';
export const mapCoupon = (c: CouponApi): CouponRow => ({
  id: c.id, code: c.code, type: couponToUI(c.type) as CouponRow['type'], value: num(c.value) || null,
  maxDisc: c.max_discount_amount === null ? null : num(c.max_discount_amount), min: num(c.min_order_amount),
  total: c.usage_limit, perCust: c.usage_limit_per_customer, used: c.used_count,
  from: c.starts_at?.slice(0, 10) ?? '', to: c.ends_at?.slice(0, 10) ?? null, active: c.is_active,
});

export async function optimisticToggle<T extends { id: number }>(
  list: T[], setList: (l: T[]) => void, id: number, patch: Partial<T>, call: () => Promise<unknown>,
  toast: (t: string, tone?: 'accent' | 'green' | 'red') => void,
) {
  const prev = list;
  setList(list.map((x) => (x.id === id ? { ...x, ...patch } : x)));
  try { await call(); } catch (e) {
    const { parseApiError } = await import('@alfarid/api-client');
    setList(prev); toast(parseApiError(e).message, 'red');
  }
}
```

## File: bags-platform/frontend/apps/storefront/src/app/account/returns/page.tsx
```typescript
'use client';

import React, { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { accountEndpoints } from '@alfarid/api-client';

export default function AccountReturnsPage() {
  const [items, setItems] = useState<Array<{ id: number; status: string; created_at: string }> | null>(null);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    setError('');
    try { setItems(await accountEndpoints.returns().then(r => r.data as Array<{ id: number; status: string; created_at: string }>)); }
    catch { setError('تعذر تحميل المرتجعات.'); }
  }, []);

  useEffect(() => { void load(); }, [load]);
  return (
    <main id="main-content" tabIndex={-1} className="min-h-screen bg-[#f3f5f7]">
      <section className="checkout-wrapper" aria-label="Returns">
        <div className="container" style={{ maxWidth: '720px', margin: '0 auto', padding: '40px 20px' }}>
          <h1 className="checkout-giant-title">RETURNS</h1>
          {error && <div className="checkout-banner is-error" role="alert"><span>{error}</span><button type="button" className="btn-apply-promo" onClick={() => { void load(); }}>إعادة المحاولة</button></div>}
          {items === null ? (error ? null : (
            <div role="status" aria-label="Loading returns"><div className="mega-skel-link" /></div>
          )) : items.length === 0 ? (
            <div className="checkout-block"><p>لا توجد طلبات إرجاع. <Link href="/account/orders">طلباتي</Link></p></div>
          ) : (
            items.map((r) => (
              <div key={r.id} className="checkout-block">
                <div className="checkout-block-header">
                  <h2 className="checkout-section-heading">طلب إرجاع #{r.id}</h2>
                  <span>{r.status}</span>
                </div>
              </div>
            ))
          )}
        </div>
      </section>
    </main>
  );
}
```

## File: bags-platform/frontend/packages/ui/src/wholesale-portal/orders-screens.jsx
```javascript
import React, { useState, useEffect } from 'react';
import { useApp } from './context';
import { wholesaleEndpoints, accountEndpoints, defaultApiClient } from '@alfarid/api-client';
import { useApi } from './data/useApi';
import { DataBoundary } from './data/DataBoundary';
import { apiError, pageMeta, saveBlob, allPages } from './data/helpers';
import { mapOrder, mapQuote, mapReturn } from './data/mappers';
import { validateReturnFiles } from './data/validation';
import { ORDER_STATUS, PAY_METHOD, PAY_STATUS, QUOTE_STATUS, RETURN_STATUS, REFUND_METHOD } from './data/constants';
import { fmt, Money } from './data/format';
import {
  Badge, PageHead, Crumb, Panel, SkeletonRows, Empty,
  Async, Pager, Field, Modal, Banner, Thumb, Qty
} from './ui-primitives';

/* Screens 13–17 + 22: orders, order detail, quotations, returns. */

const REORDER_SKIP_LABEL = { not_found_or_inactive: 'الصنف غير موجود أو غير متاح', insufficient_stock: 'الكمية غير متاحة' };

export const ReorderModal = ({ orderId, onClose }) => {
  const { go, applyCartResponse } = useApp();
  const [busy, setBusy] = useState(true);
  const [report, setReport] = useState(null);
  const [err, setErr] = useState('');
  const request = React.useRef(null);
  useEffect(() => {
    let active = true;
    setBusy(true);
    setErr('');
    setReport(null);
    // StrictMode replays effects; re-use the same mutation rather than adding twice.
    if (request.current?.id !== orderId) request.current = {
      id: orderId,
      promise: wholesaleEndpoints.reorder(orderId).then((r) => { applyCartResponse(r.cart); return r; }),
    };
    request.current.promise
      .then((r) => { if (active) { setReport(r); setBusy(false); } })
      .catch((e) => { if (active) { setErr(apiError(e).message); setBusy(false); } });
    return () => { active = false; };
  }, [orderId, applyCartResponse]);
  return (
    <Modal title="إعادة الطلب" onClose={onClose} icon={<div className="modal-icon"><i className="fa-solid fa-rotate" /></div>}
      foot={<><button className="btn-pill" onClick={onClose}>إغلاق</button><button className="btn-pill is-dark" disabled={busy || !report} onClick={() => go('cart')}><i className="fa-solid fa-bag-shopping" /> فتح السلة</button></>}>
      <Banner kind="info">تمت إضافة البنود المتاحة للسلة <b>بأسعار اليوم</b>، مش بأسعار الطلب القديم.</Banner>
      <div className="mt-16">
        {busy && <SkeletonRows rows={3} cols={3} />}
        {err && <p className="f-error">{err}</p>}
        {report && (
          <>
            {(report.added || []).map((a, i) => (
              <div key={`a-${i}`} className="sum-row"><span><b style={{ color: 'var(--ink)' }}>{a.sku}</b></span>
                <span className="badge success">أضيف <span className="num">×{a.qty}</span></span></div>))}
            {(report.skipped || []).map((s, i) => (
              <div key={`s-${i}`} className="sum-row"><span><b style={{ color: 'var(--ink)' }}>{s.sku}</b></span>
                <span className="badge danger">تم تخطيه · {REORDER_SKIP_LABEL[s.reason] || s.reason}</span></div>))}
            {!report.added?.length && !report.skipped?.length && <p className="muted">لا توجد بنود قابلة للإضافة.</p>}
          </>
        )}
      </div>
    </Modal>
  );
};

export const CancelModal = ({ order, onClose, onDone }) => {
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');
  const confirm = async () => {
    setBusy(true);
    setErr('');
    try {
      await accountEndpoints.cancelOrder(order.id);
      onDone();
    } catch (e) {
      setErr(apiError(e).message);
      setBusy(false);
    }
  };
  return (
    <Modal title={`إلغاء الطلب ${order.number}؟`} onClose={onClose} icon={<div className="modal-icon is-err"><i className="fa-solid fa-ban" /></div>}
      foot={<><button className="btn-pill" onClick={onClose}>تراجع</button><button className="btn-pill is-danger" disabled={busy} onClick={confirm}>{busy ? <span className="spin" /> : null} تأكيد الإلغاء</button></>}>
      {order.method === 'credit' && <p className="muted" style={{ lineHeight: 1.9 }}>هيتم إلغاء الطلب وتحرير مبلغ <b className="num" style={{ color: 'var(--ink)' }}>{fmt(order.total)} EGP</b> من الائتمان المستخدم. الإلغاء متاح طالما الطلب لم يُشحن.</p>}
      {order.method !== 'credit' && <p className="muted" style={{ lineHeight: 1.9 }}>هيتم إلغاء الطلب. الإلغاء متاح طالما الطلب لم يُشحن.</p>}
      {err && <p className="f-error" style={{ marginTop: 8 }}>{err}</p>}
    </Modal>
  );
};

/* ---------- 13. Orders ---------- */
const GROUPS = { all: null, active: 'active', delivered: 'delivered', cancelled: 'cancelled' };

export const Orders = () => {
  const { go, toast, refreshAccount } = useApp();
  const [f, setF] = useState('all');
  const [page, setPage] = useState(1);
  const [re, setRe] = useState(null);
  const [cancel, setCancel] = useState(null);

  const api = useApi((signal) => wholesaleEndpoints
    .orders({ group: GROUPS[f] || undefined, page }, signal)
    .then((r) => ({ items: (r.data || []).map(mapOrder), meta: pageMeta(r.meta) })), [f, page]);

  const list = api.data?.items || [];
  const meta = api.data?.meta || { page: 1, pages: 1, total: 0, per: 15 };

  const onCancelled = () => {
    setCancel(null);
    refreshAccount();
    api.reload();
    toast('تم إلغاء الطلب');
  };

  return (
    <>
      <PageHead en="My Orders" ar="كل طلبات حساب الجملة" />
      <section className="panel">
        <div className="toolbar">
          <div className="chips">{[['all', 'الكل'], ['active', 'جاري التنفيذ'], ['delivered', 'تم التسليم'], ['cancelled', 'ملغي']].map(([k, l]) => <button key={k} className={`chip${f === k ? ' is-on' : ''}`} onClick={() => { setF(k); setPage(1); }}>{l}</button>)}</div>
        </div>
        <DataBoundary api={api}>
          <Async rows={6} cols={6} isEmpty={!list.length} empty={<Empty icon="fa-box-open" title="مفيش طلبات" desc="لما تعمل أول طلب هيظهر هنا." action={<button className="btn-pill is-dark" onClick={() => go('catalog')}>تصفح الكتالوج</button>} />}>
            <div className="tbl-wrap"><table className="tbl">
              <thead><tr><th>رقم الطلب</th><th>التاريخ</th><th>حالة التنفيذ</th><th>طريقة الدفع</th><th>السداد</th><th className="t-end">الإجمالي</th><th></th></tr></thead>
              <tbody>{list.map((o) => (
                <tr key={o.id} className="is-click" onClick={() => go('order', o.id)}>
                  <td className="strong num">{o.number}</td>
                  <td className="num">{o.date}</td>
                  <td><Badge map={ORDER_STATUS} k={o.status} /></td>
                  <td>{PAY_METHOD[o.method]}</td>
                  <td><Badge map={PAY_STATUS} k={o.pay} /></td>
                  <td className="t-end strong num">{fmt(o.total)}</td>
                  <td onClick={(e) => e.stopPropagation()}><div className="row-actions">
                    <button className="btn-link" onClick={() => setRe(o.id)}>إعادة الطلب</button>
                    {o.cancellable && <button className="btn-link danger" onClick={() => setCancel(o)}>إلغاء</button>}
                    <button className="btn-link muted" onClick={() => go('order', o.id)}>التفاصيل</button>
                  </div></td>
                </tr>))}</tbody>
            </table></div>
            <Pager page={meta.page} pages={meta.pages} total={meta.total} per={meta.per} onPage={setPage} />
          </Async>
        </DataBoundary>
      </section>
      {re && <ReorderModal orderId={re} onClose={() => setRe(null)} />}
      {cancel && <CancelModal order={cancel} onClose={() => setCancel(null)} onDone={onCancelled} />}
    </>
  );
};

/* ---------- 14. Order detail + tracking ---------- */
export const OrderDetail = ({ id }) => {
  const { go, refreshAccount, route } = useApp();
  const [re, setRe] = useState(false);
  const [cancel, setCancel] = useState(false);

  const api = useApi((signal) => wholesaleEndpoints.order(id, signal).then(mapOrder), [id]);
  const o = api.data;

  const onCancelled = () => {
    setCancel(false);
    refreshAccount();
    api.reload();
  };

  return (
    <>
      <PageHead en={o?.number || ''} crumb={<Crumb items={[{ label: 'طلباتي', go: () => go('orders') }, { label: o?.number || '', mono: true }]} />} ar={o ? <>تاريخ الطلب <span className="num">{o.date}</span></> : null}>
        {o && <Badge map={ORDER_STATUS} k={o.status} lg />}
        {o && <button className="btn-pill" onClick={() => setRe(true)}><i className="fa-solid fa-rotate" /> إعادة الطلب</button>}
        {o?.returnable && <button className="btn-pill" onClick={() => go('returnNew', o.id)}><i className="fa-solid fa-rotate-left" /> طلب إرجاع</button>}
        {o?.cancellable && <button className="btn-pill is-danger" onClick={() => setCancel(true)}>إلغاء الطلب</button>}
      </PageHead>
      <DataBoundary api={api}>
        <Async rows={5} cols={4} empty={<Empty icon="fa-box-open" title="الطلب غير موجود" />}>
          {o && (
          <div className="grid-2">
            <div className="stack">
              <Panel title="البنود" en="ITEMS" body={false}>
                <div className="tbl-wrap"><table className="tbl">
                  <thead><tr><th>المنتج</th><th>الكمية</th><th>سعر الوحدة</th><th>الخصم</th><th className="t-end">الإجمالي</th></tr></thead>
                  <tbody>{o.items.map((it) => (
                    <tr key={it.id || it.sku}><td><div className="prod-cell"><Thumb sm /><div><div className="prod-name">{it.name}</div><div className="sku">{it.sku}</div></div></div></td>
                      <td className="num">{it.qty}</td><td className="num">{fmt(it.unit)}</td><td className="num" style={{ color: it.discount ? 'var(--teal)' : null }}>{it.discount ? '− ' + fmt(it.discount) : '—'}</td>
                      <td className="t-end strong num">{fmt(it.line_total)}</td></tr>))}</tbody>
                </table></div>
              </Panel>
              <div className="grid-2e" style={{ gap: 24 }}>
                <Panel title="المستلم والعنوان" en="DELIVERY">
                  <dl className="kv"><dt>المستلم</dt><dd>{o.recipient}</dd><dt>التليفون</dt><dd className="num">{o.phone}</dd><dt>المدينة</dt><dd>{o.city}</dd><dt>العنوان</dt><dd>{o.address}</dd></dl>
                </Panel>
                <Panel title="الدفع" en="PAYMENT">
                  <dl className="kv"><dt>الطريقة</dt><dd>{PAY_METHOD[o.method]}</dd><dt>حالة السداد</dt><dd><Badge map={PAY_STATUS} k={o.pay} /></dd></dl>
                  {o.pay === 'unpaid' && o.method === 'credit' && <button className="btn-link mt-16" onClick={() => go('noticeNew', o.id)}>تسجيل إشعار سداد لهذا الطلب</button>}
                </Panel>
              </div>
            </div>
            <div className="stack">
              <Panel title="تفصيل المبالغ" en="TOTALS">
                <div className="sum-row"><span>المنتجات</span><strong className="num">{fmt(o.subtotal)}</strong></div>
                <div className="sum-row is-neg"><span>الخصم</span><strong className="num">{o.discount ? '− ' + fmt(o.discount) : '—'}</strong></div>
                <div className="sum-row"><span>الشحن</span><strong className="num">{fmt(o.shipping)}</strong></div>
                <div className="sum-row"><span>رسوم التحصيل</span><strong className="num">{fmt(o.cod_fee)}</strong></div>
                <div className="sum-row"><span>الضريبة</span><strong className="num">{fmt(o.tax)}</strong></div>
                <div className="sum-total"><span>الإجمالي</span><span className="num">{fmt(o.total)}</span></div>
              </Panel>
              <Panel title="سجل الحالة" en="TRACKING">
                <ul className="tl">{(o.track || []).map(([s, t, st], i) => (
                  <li key={i} className={st === 'now' ? 'is-now' : st === 'done' ? 'is-done' : 'is-future'}>
                    <div className="tl-title">{ORDER_STATUS[s] ? ORDER_STATUS[s][0] : s}</div>{t && <div className="tl-meta num">{t}</div>}</li>))}</ul>
                <p className="note"><i className="fa-solid fa-circle-info" />التتبع من خدمة الشحن برقم الطلب ورقم الهاتف.</p>
              </Panel>
            </div>
          </div>
          )}
        </Async>
      </DataBoundary>
      {re && o && <ReorderModal orderId={o.id} onClose={() => setRe(false)} />}
      {cancel && o && <CancelModal order={o} onClose={() => setCancel(false)} onDone={onCancelled} />}
    </>
  );
};

/* ---------- 15. Quotations list ---------- */
const QUOTE_FILTER_REV = { priced: 'priced_by_admin', converted: 'converted_to_order' };

export const Quotes = () => {
  const { go } = useApp();
  const [f, setF] = useState('all');
  const [page, setPage] = useState(1);

  const api = useApi((signal) => wholesaleEndpoints
    .quotes({ status: QUOTE_FILTER_REV[f] || (f === 'all' ? undefined : f), page }, signal)
    .then((r) => ({ items: (r.data || []).map(mapQuote), meta: pageMeta(r.meta) })), [f, page]);

  const list = api.data?.items || [];
  const meta = api.data?.meta || { page: 1, pages: 1, total: 0, per: 15 };

  return (
    <>
      <PageHead en="Quotations" ar="اطلب تسعير خاص للكميات الكبيرة وتابع رد الإدارة">
        <button className="btn-pill is-dark" onClick={() => go('quoteNew')}><i className="fa-solid fa-plus" /> طلب عرض سعر</button>
      </PageHead>
      <section className="panel">
        <div className="toolbar"><div className="chips">
          <button className={`chip${f === 'all' ? ' is-on' : ''}`} onClick={() => { setF('all'); setPage(1); }}>الكل</button>
          {Object.entries(QUOTE_STATUS).map(([k, [l]]) => <button key={k} className={`chip${f === k ? ' is-on' : ''}`} onClick={() => { setF(k); setPage(1); }}>{l}</button>)}
        </div></div>
        <DataBoundary api={api}>
          <Async rows={6} cols={5} isEmpty={!list.length} empty={<Empty icon="fa-file-signature" title="لا توجد عروض أسعار بهذه الحالة" action={<button className="btn-pill is-dark" onClick={() => go('quoteNew')}>طلب عرض سعر</button>} />}>
            <div className="tbl-wrap"><table className="tbl">
              <thead><tr><th>رقم العرض</th><th>تاريخ الإنشاء</th><th>الحالة</th><th>صالح حتى</th><th className="t-end">الإجمالي</th><th></th></tr></thead>
              <tbody>{list.map((q) => {
                const daysLeft = q.valid_until ? Math.ceil((new Date(q.valid_until).getTime() - Date.now()) / 864e5) : null;
                return (
                  <tr key={q.id} className="is-click" onClick={() => go(q.status === 'draft' ? 'quoteEdit' : 'quote', q.id)}>
                    <td className="strong num">{q.number}</td><td className="num">{q.created}</td><td><Badge map={QUOTE_STATUS} k={q.status} /></td>
                    <td className="num">{q.valid_until || '—'}{q.status === 'priced' && daysLeft != null && daysLeft >= 0 && <span className="badge warning" style={{ marginInlineStart: 8 }}>باقي <span className="num">{daysLeft}</span> يوم</span>}</td>
                    <td className="t-end strong num">{q.total != null ? fmt(q.total) : '—'}</td>
                    <td className="t-end"><button className="btn-link muted">{q.status === 'draft' ? 'تعديل' : q.status === 'priced' ? 'مراجعة والرد' : 'فتح'}</button></td>
                  </tr>);
              })}</tbody>
            </table></div>
            <Pager page={meta.page} pages={meta.pages} total={meta.total} per={meta.per} onPage={setPage} />
          </Async>
        </DataBoundary>
      </section>
    </>
  );
};

/* ---------- 16. Create / edit quote ---------- */
export const QuoteForm = ({ id }) => {
  const { go, toast, route } = useApp();
  const editingId = id ?? route.id;
  const [items, setItems] = useState([]);
  const [note, setNote] = useState('');
  const [search, setSearch] = useState('');
  const [debounced, setDebounced] = useState('');
  const [matches, setMatches] = useState([]);
  const [errs, setErrs] = useState({});
  const [confirm, setConfirm] = useState(null);
  const [busy, setBusy] = useState(false);
  const savedId = React.useRef(editingId);
  const quoteApi = useApi((signal) => wholesaleEndpoints.quote(editingId, signal).then(mapQuote), [editingId], !!editingId);

  useEffect(() => {
    if (quoteApi.state === 'ready' && quoteApi.data) {
        const m = quoteApi.data;
        setItems((m.items || []).map((it) => ({ variant_id: it.variant_id, name: it.name, sku: it.sku, qty: it.qty, target: it.target != null ? String(it.target) : '' })));
        setNote(m.trader_note || '');
    }
    savedId.current = editingId;
  }, [editingId, quoteApi.data]);

  useEffect(() => {
    const t = setTimeout(() => setDebounced(search), 400);
    return () => clearTimeout(t);
  }, [search]);

  useEffect(() => {
    if (!debounced.trim()) { setMatches([]); return; }
    const ctrl = new AbortController();
    wholesaleEndpoints.products({ search: debounced.trim(), per_page: 5 }, ctrl.signal)
      .then((r) => {
        const vars = (r.data || []).flatMap((p) => (p.variants || []).map((v) => ({
          variant_id: v.id, name: p.name, sku: v.sku, price: v.your_price != null ? Number(v.your_price) : null,
        })));
        if (!ctrl.signal.aborted) setMatches(vars);
      })
      .catch(() => {});
    return () => ctrl.abort();
  }, [debounced]);

  const upd = (i, k, val) => setItems(items.map((it, j) => j === i ? { ...it, [k]: val } : it));

  const save = async () => {
    const e = {};
    if (!items.length) e.items = 'أضف بند واحد على الأقل.';
    items.forEach((it, i) => { if (!it.qty || Number(it.qty) <= 0) e[`items.${i}.qty`] = 'الكمية مطلوبة.'; });
    if (note.length > 2000) e.note = 'الحد الأقصى 2000 حرف.';
    setErrs(e);
    if (Object.keys(e).length) return null;
    const body = {
      customer_notes: note || null,
      items: items.map((it) => ({ product_variant_id: it.variant_id, qty: Number(it.qty), requested_target_price: it.target ? Number(it.target) : null })),
    };
    setBusy(true);
    try {
      const saved = savedId.current ? await wholesaleEndpoints.updateQuote(savedId.current, body) : await wholesaleEndpoints.createQuote(body);
      savedId.current = saved.id;
      return saved;
    } catch (err) {
      const info = apiError(err);
      const mapped = {};
      Object.entries(info.fields || {}).forEach(([k, msgs]) => { mapped[k === 'customer_notes' ? 'note' : k] = Array.isArray(msgs) ? msgs[0] : String(msgs); });
      setErrs(mapped);
      if (!Object.keys(mapped).length) toast(info.message);
      return null;
    } finally {
      setBusy(false);
    }
  };

  const saveDraft = async () => {
    const saved = await save();
    if (saved) { toast('تم حفظ المسودة'); go('quotes'); }
  };

  const sendForPricing = async () => {
    const saved = await save();
    if (!saved) return;
    setBusy(true);
    try {
      const submitted = await wholesaleEndpoints.submitQuote(saved.id);
      go('quote', submitted.id);
    } catch (err) {
      toast(apiError(err).message);
    } finally {
      setBusy(false);
      setConfirm(null);
    }
  };

  const removeDraft = async () => {
    setBusy(true);
    try {
      await wholesaleEndpoints.deleteQuote(editingId);
      toast('تم حذف المسودة');
      go('quotes');
    } catch (err) {
      toast(apiError(err).message);
    } finally {
      setBusy(false);
      setConfirm(null);
    }
  };

  if (editingId && quoteApi.state !== 'ready') return (<><PageHead en="Quote" /><section className="panel"><DataBoundary api={quoteApi}><Async rows={5} cols={5} /></DataBoundary></section></>);

  return (
    <>
      <PageHead en={editingId ? 'تعديل مسودة' : 'New Quote'} crumb={<Crumb items={[{ label: 'عروض الأسعار', go: () => go('quotes') }, { label: editingId ? 'تعديل مسودة' : 'طلب جديد' }]} />} ar={editingId ? 'مسودة — يمكنك التعديل قبل الإرسال' : 'اختر المنتجات والكميات، واقترح السعر المستهدف لو حابب'}>
        {editingId && <button className="btn-pill is-danger" onClick={() => setConfirm('delete')}><i className="fa-regular fa-trash-can" /> حذف المسودة</button>}
      </PageHead>
      <div className="grid-2" style={{ gridTemplateColumns: '1fr 340px' }}>
        <section className="panel">
          <div className="toolbar" style={{ position: 'relative' }}>
            <div className="search" style={{ flex: 1 }}><i className="fa-solid fa-plus" /><input className="line-input" placeholder="أضف منتج — ابحث بالاسم أو SKU" value={search} onChange={(e) => setSearch(e.target.value)} /></div>
            <span className="soft small"><span className="num">{items.length}</span> / <span className="num">100</span> بند</span>
            {matches.length > 0 && (
              <div className="qf-suggest">{matches.map((v) => (
                <button key={v.variant_id} onClick={() => { setItems([...items, { variant_id: v.variant_id, name: v.name, sku: v.sku, qty: 1, target: '' }]); setSearch(''); setMatches([]); }}>
                  <Thumb sm /><span style={{ flex: 1 }}><b>{v.name}</b><span className="sku">{v.sku}</span></span><span className="num small">{v.price != null ? fmt(v.price) : '—'}</span></button>))}</div>
            )}
          </div>
          {items.length === 0 ? <Empty icon="fa-list" title="مفيش بنود" desc="ابحث وأضف المنتجات اللي عايز تسعيرها." /> : (
            <div className="tbl-wrap"><table className="tbl">
              <thead><tr><th>المتغير</th><th>الكمية</th><th>السعر المستهدف <span className="f-opt">(اختياري)</span></th><th></th></tr></thead>
              <tbody>{items.map((it, i) => (
                <tr key={i} className={errs[`items.${i}.qty`] ? 'is-error' : ''}>
                  <td><div className="prod-cell"><Thumb sm /><div><div className="prod-name">{it.name}</div><div className="soft small"><span className="sku">{it.sku}</span></div></div></div></td>
                  <td><Qty value={it.qty} onChange={(n) => upd(i, 'qty', n)} />{errs[`items.${i}.qty`] && <p className="f-error">{errs[`items.${i}.qty`]}</p>}</td>
                  <td><input className="box-input ltr" style={{ width: 120 }} placeholder="0.00" value={it.target} onChange={(e) => upd(i, 'target', e.target.value.replace(/[^\d.]/g, ''))} />{errs[`items.${i}.requested_target_price`] && <p className="f-error">{errs[`items.${i}.requested_target_price`]}</p>}{errs[`items.${i}.product_variant_id`] && <p className="f-error">{errs[`items.${i}.product_variant_id`]}</p>}</td>
                  <td><button className="btn-link muted" onClick={() => setItems(items.filter((_, j) => j !== i))} aria-label="حذف"><i className="fa-solid fa-xmark" /></button></td>
                </tr>))}</tbody>
            </table></div>
          )}
          {errs.items && <p className="f-error" style={{ padding: '0 24px 16px' }}>{errs.items}</p>}
        </section>
        <aside className="stack" style={{ position: 'sticky', top: 100 }}>
          <Panel title="ملاحظات للإدارة">
            <Field label="ملاحظات التاجر" opt counter={<span className="f-counter" style={{ color: note.length > 2000 ? '#b91c1c' : null }}>{note.length}/2000</span>} error={errs.note}>
              <textarea className="line-input" rows="5" value={note} placeholder="مواعيد التسليم، تغليف خاص، شروط الدفع…" onChange={(e) => setNote(e.target.value)} />
            </Field>
            <div className="divider" />
            <div className="sum-row"><span>عدد البنود</span><strong className="num">{items.length}</strong></div>
            <div className="sum-row"><span>إجمالي الكميات</span><strong className="num">{items.reduce((a, it) => a + Number(it.qty || 0), 0)}</strong></div>
            <button className="btn-block mt-24" disabled={busy} onClick={() => setConfirm('submit')}><i className="fa-solid fa-paper-plane" /> إرسال للإدارة للتسعير</button>
            <button className="btn-pill mt-8" style={{ width: '100%', justifyContent: 'center' }} disabled={busy} onClick={saveDraft}><i className="fa-regular fa-floppy-disk" /> حفظ كمسودة</button>
          </Panel>
          <p className="note"><i className="fa-solid fa-circle-info" />بعد الإرسال مش هتقدر تعدّل الطلب. الإدارة هترد بالأسعار وتاريخ صلاحية العرض.</p>
        </aside>
      </div>
      {confirm === 'submit' && <Modal title="إرسال طلب التسعير؟" onClose={() => setConfirm(null)} icon={<div className="modal-icon"><i className="fa-solid fa-paper-plane" /></div>}
        foot={<><button className="btn-pill" onClick={() => setConfirm(null)}>رجوع</button><button className="btn-pill is-dark" disabled={busy} onClick={sendForPricing}>{busy ? <span className="spin" /> : 'إرسال'}</button></>}>
        <p className="muted">هيتم إرسال <span className="num">{items.length}</span> بند للإدارة. الحالة هتتغير إلى «مطلوب تسعيره».</p></Modal>}
      {confirm === 'delete' && <Modal title="حذف المسودة؟" onClose={() => setConfirm(null)} icon={<div className="modal-icon is-err"><i className="fa-solid fa-trash-can" /></div>}
        foot={<><button className="btn-pill" onClick={() => setConfirm(null)}>رجوع</button><button className="btn-pill is-danger" disabled={busy} onClick={removeDraft}>{busy ? <span className="spin" /> : 'حذف'}</button></>}>
        <p className="muted">الإجراء ده نهائي.</p></Modal>}
    </>
  );
};

/* ---------- 17. Quote detail ---------- */
export const QuoteDetail = ({ id }) => {
  const { go, toast, refreshAccount } = useApp();
  const [confirm, setConfirm] = useState(null);
  const [busy, setBusy] = useState(false);

  const api = useApi((signal) => wholesaleEndpoints.quote(id, signal).then(mapQuote), [id]);
  const q = api.data;

  const act = async (kind) => {
    setBusy(true);
    try {
      if (kind === 'accept') {
        await wholesaleEndpoints.acceptQuote(id);
        toast('تم قبول العرض');
      } else {
        await wholesaleEndpoints.rejectQuote(id);
        toast('تم رفض العرض');
      }
      await api.reload();
      refreshAccount();
    } catch (e) {
      toast(apiError(e).message);
      await api.reload();
    } finally {
      setBusy(false);
      setConfirm(null);
    }
  };

  return (
    <>
      <PageHead en={q?.number || ''} crumb={<Crumb items={[{ label: 'عروض الأسعار', go: () => go('quotes') }, { label: q?.number || '', mono: true }]} />} ar={q ? <>أُنشئ في <span className="num">{q.created}</span>{q.valid_until ? <> · صالح حتى <span className="num">{q.valid_until}</span></> : null}</> : null}>
        {q && <Badge map={QUOTE_STATUS} k={q.status} lg />}
      </PageHead>
      <DataBoundary api={api}>
        <Async rows={5} cols={4} empty={<Empty icon="fa-file-signature" title="العرض غير موجود" />}>
          {q && (
          <>
            {q.status === 'priced' && <div className="mb-24"><Banner kind="info" icon="fa-clock" action={<div className="row"><button className="btn-pill is-danger sm" onClick={() => setConfirm('reject')}>رفض</button><button className="btn-pill is-dark sm" onClick={() => setConfirm('accept')}>قبول العرض</button></div>}><b>الإدارة سعّرت العرض.</b> راجع الأسعار وقرر قبل <span className="num">{q.valid_until}</span> — بعدها العرض بينتهي تلقائيًا.</Banner></div>}
            {q.status === 'requested' && <div className="mb-24"><Banner kind="warn" icon="fa-hourglass-half">الطلب عند الإدارة للتسعير. هيوصلك إشعار لما يتم الرد.</Banner></div>}
            {q.status === 'expired' && <div className="mb-24"><Banner kind="error" icon="fa-calendar-xmark">انتهت صلاحية العرض ولم يعد قابلًا للقبول. تقدر تطلب عرض جديد.</Banner></div>}
            {q.status === 'converted' && <div className="mb-24"><Banner kind="ok" action={q.converted_order_id && <button className="btn-pill sm" onClick={() => go('order', q.converted_order_id)}>فتح الطلب <span className="num">{q.converted_order_id}</span></button>}>تم تحويل العرض لطلب.</Banner></div>}
            {q.status === 'accepted' && <div className="mb-24"><Banner kind="ok">تم قبول العرض. الإدارة هتحوله لطلب وهيظهر في طلباتك.</Banner></div>}
            <div className="grid-2">
              <Panel title="البنود" en="LINE ITEMS" body={false}>
                <div className="tbl-wrap"><table className="tbl">
                  <thead><tr><th>المنتج</th><th>الكمية</th><th>السعر المطلوب</th><th>السعر المعروض</th><th className="t-end">إجمالي البند</th></tr></thead>
                  <tbody>{q.items.map((it) => (
                    <tr key={it.id || it.sku}><td><div className="prod-cell"><Thumb sm /><div><div className="prod-name">{it.name}</div><div className="sku">{it.sku}</div></div></div></td>
                      <td className="num">{it.qty}</td><td className="num muted">{it.target != null ? fmt(it.target) : '—'}</td>
                      <td className="num strong">{it.offered != null ? fmt(it.offered) : <span className="soft">في الانتظار</span>}</td>
                      <td className="t-end strong num">{it.offered != null ? fmt(it.line_total) : '—'}</td></tr>))}</tbody>
                </table></div>
              </Panel>
              <div className="stack">
                <Panel title="الإجماليات" en="TOTALS">
                  <div className="sum-row"><span>الإجمالي قبل الخصم</span><strong className="num">{q.subtotal != null ? fmt(q.subtotal) : '—'}</strong></div>
                  <div className="sum-row is-neg"><span>الخصم</span><strong className="num">{q.discount ? '− ' + fmt(q.discount) : '—'}</strong></div>
                  <div className="sum-row"><span>الضريبة</span><strong className="num">{q.tax != null ? fmt(q.tax) : '—'}</strong></div>
                  <div className="sum-total"><span>الإجمالي النهائي</span><strong className="num">{q.total != null ? fmt(q.total) : '—'}</strong></div>
                </Panel>
                <Panel title="الملاحظات" en="NOTES">
                  <div className="note-bubble"><span>ملاحظات التاجر</span><p>{q.trader_note || <span className="soft">—</span>}</p></div>
                  <div className="note-bubble is-admin"><span>رد الإدارة</span><p>{q.admin_note || <span className="soft">لم يتم الرد بعد</span>}</p></div>
                </Panel>
              </div>
            </div>
          </>
          )}
        </Async>
      </DataBoundary>
      {confirm && <Modal title={confirm === 'accept' ? 'قبول عرض السعر؟' : 'رفض عرض السعر؟'} onClose={() => setConfirm(null)} icon={<div className={`modal-icon${confirm === 'reject' ? ' is-err' : ''}`}><i className={`fa-solid ${confirm === 'accept' ? 'fa-check' : 'fa-xmark'}`} /></div>}
        foot={<><button className="btn-pill" onClick={() => setConfirm(null)}>رجوع</button><button className={`btn-pill ${confirm === 'accept' ? 'is-dark' : 'is-danger'}`} disabled={busy} onClick={() => act(confirm)}>{busy ? <span className="spin" /> : confirm === 'accept' ? 'قبول' : 'رفض'}</button></>}>
        <p className="muted" style={{ lineHeight: 1.9 }}>{confirm === 'accept' && q ? <>بقبولك العرض بإجمالي <b className="num" style={{ color: 'var(--ink)' }}>{fmt(q.total)} EGP</b> الإدارة هتحوله لطلب.</> : 'الإجراء نهائي، ولن يكون العرض قابلًا للقبول بعد الرفض.'}</p></Modal>}
    </>
  );
};

/* ---------- 22. Returns ---------- */
export const Returns = () => {
  const { go } = useApp();
  const [page, setPage] = useState(1);

  const api = useApi(() => accountEndpoints.returns(page)
    .then((r) => ({ items: (r.data || []).map(mapReturn), meta: pageMeta(r.meta) })), [page]);
  const list = api.data?.items || [];
  const meta = api.data?.meta || { page: 1, pages: 1, total: 0, per: 20 };

  return (
    <>
      <PageHead en="Returns" ar="طلبات الإرجاع والاسترداد"><button className="btn-pill is-dark" onClick={() => go('returnNew')}><i className="fa-solid fa-plus" /> طلب إرجاع</button></PageHead>
      <section className="panel">
        <DataBoundary api={api}>
          <Async rows={4} cols={5} isEmpty={!list.length} empty={<Empty icon="fa-rotate-left" title="مفيش مرتجعات" desc="تقدر تطلب إرجاع لأي طلب مؤهل بعد التسليم." />}>
            <div className="tbl-wrap"><table className="tbl">
              <thead><tr><th>رقم المرتجع</th><th>الطلب</th><th>الحالة</th><th>مبلغ الاسترداد</th><th>التاريخ</th><th></th></tr></thead>
              <tbody>{list.map((r) => (
                <tr key={r.id} className="is-click" onClick={() => go('return', r.id)}>
                  <td className="strong num">{r.number}</td><td className="num">{r.order}</td><td><Badge map={RETURN_STATUS} k={r.status} /></td>
                  <td className="strong num">{fmt(r.refund)}</td><td className="num">{r.date}</td><td className="t-end"><button className="btn-link muted">التفاصيل</button></td></tr>))}</tbody>
            </table></div>
            <Pager page={meta.page} pages={meta.pages} total={meta.total} per={meta.per} onPage={setPage} />
          </Async>
        </DataBoundary>
      </section>
    </>
  );
};

export const ReturnForm = ({ orderId }) => {
  const { go, toast, route } = useApp();
  const preselect = orderId ?? route.id;
  const [oid, setOid] = useState(preselect || null);
  const [sel, setSel] = useState({});
  const [reason, setReason] = useState('');
  const [method, setMethod] = useState('');
  const [errs, setErrs] = useState({});
  const [busy, setBusy] = useState(false);

  const eligibleApi = useApi((signal) => allPages((page, signal) => wholesaleEndpoints.orders({ group: 'delivered', page }, signal), signal)
    .then((rows) => rows.map(mapOrder).filter((o) => o.returnable)), []);
  const eligible = eligibleApi.data || [];
  useEffect(() => { if (!oid && eligible.length) setOid(eligible[0].id); }, [oid, eligible]);

  const orderApi = useApi((signal) => wholesaleEndpoints.order(oid, signal).then(mapOrder), [oid], !!oid);
  const order = orderApi.state === 'ready' && orderApi.data?.id === oid ? orderApi.data : null;
  useEffect(() => { setSel({}); }, [oid]);

  const toggle = (id) => setSel((s) => ({ ...s, [id]: s[id] ? undefined : { qty: 1, reason: '', files: [] } }));
  const set = (id, k, v) => setSel((s) => ({ ...s, [id]: { ...s[id], [k]: v } }));
  const chosen = Object.entries(sel).filter(([, v]) => v);
  const totalFiles = chosen.reduce((a, [, v]) => a + v.files.length, 0);

  const addFiles = (id, list) => {
    const cur = sel[id];
    if (!cur) return;
    const next = [...cur.files, ...list];
    const error = validateReturnFiles(chosen.map(([itemId, value]) => String(itemId) === String(id) ? next : value.files));
    setErrs((previous) => ({ ...previous, files: error }));
    if (error) { toast(error); return; }
    set(id, 'files', next);
  };

  const submit = async () => {
    const e = {};
    if (!oid || !order || !order.returnable) e.order = 'اختر طلبًا مؤهلًا للإرجاع.';
    if (!chosen.length) e.items = 'اختر بند واحد على الأقل.';
    if (!reason.trim()) e.reason = 'السبب العام مطلوب.';
    chosen.forEach(([itemId, v]) => {
      if (v.reason.length > 255) e[itemId] = 'الحد الأقصى 255 حرف.';
      if (!Number.isInteger(Number(v.qty)) || Number(v.qty) < 1) e[`${itemId}-qty`] = 'كمية صحيحة مطلوبة.';
    });
    const fileError = validateReturnFiles(chosen.map(([, v]) => v.files));
    if (fileError) e.files = fileError;
    if (method && !REFUND_METHOD[method]) e.method = 'طريقة استرداد غير صالحة.';
    setErrs(e);
    if (Object.keys(e).length) return;
    const fd = new FormData();
    fd.append('order_id', String(oid));
    fd.append('reason', reason);
    if (method) fd.append('refund_method', method);
    chosen.forEach(([itemId, v], i) => {
      fd.append(`items[${i}][order_item_id]`, String(itemId));
      fd.append(`items[${i}][requested_qty]`, String(Math.trunc(Number(v.qty))));
      if (v.reason) fd.append(`items[${i}][item_reason]`, v.reason);
      v.files.forEach((fl) => fd.append(`items[${i}][attachments][]`, fl));
    });
    setBusy(true);
    try {
      const r = await accountEndpoints.createReturn(fd);
      go('return', r.id);
    } catch (err) {
      const info = apiError(err);
      const mapped = {};
      Object.entries(info.fields || {}).forEach(([k, msgs]) => {
        const parts = k.split('.');
        const itemId = parts[0] === 'items' ? chosen[Number(parts[1])]?.[0] : null;
        const key = k === 'order_id' ? 'order' : k === 'refund_method' ? 'method'
          : itemId ? (parts[2] === 'requested_qty' ? `${itemId}-qty` : parts[2] === 'item_reason' ? itemId : parts[2] === 'attachments' ? 'files' : 'items') : k;
        mapped[key] = Array.isArray(msgs) ? msgs[0] : String(msgs);
      });
      setErrs(mapped);
      if (!Object.keys(mapped).length) toast(info.message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <PageHead en="New Return" crumb={<Crumb items={[{ label: 'المرتجعات', go: () => go('returns') }, { label: 'طلب جديد' }]} />} ar="اختر الطلب والبنود، ووضح السبب" />
      <div className="grid-2" style={{ gridTemplateColumns: '1fr 340px' }}>
        <div className="stack">
          <Panel title="الطلب المؤهل" en="ORDER">
            {errs.order && <p className="f-error">{errs.order}</p>}
            <DataBoundary api={eligibleApi}><Async rows={2} cols={2} isEmpty={!eligible.length} empty={<Empty title="لا توجد طلبات مؤهلة للإرجاع حاليًا." />}>
            <div className="addr-picks">{eligible.map((x) => (
              <button key={x.id} className={`addr-pick${oid === x.id ? ' is-on' : ''}`} onClick={() => { setOid(x.id); }}><b className="num">{x.number}</b><span>تم التسليم · <span className="num">{x.date}</span> · <span className="num">{fmt(x.total)}</span> EGP</span></button>))}
              {!eligible.length && <p className="muted">لا توجد طلبات مؤهلة للإرجاع حاليًا.</p>}</div>
            </Async></DataBoundary>
            <p className="note mt-16"><i className="fa-solid fa-circle-info" />الطلبات المؤهلة بس هي اللي بتظهر هنا (تم تسليمها وضمن مدة الإرجاع).</p>
          </Panel>
          <Panel title="البنود المرتجعة" en="ITEMS">
            {errs.items && <p className="f-error" style={{ marginTop: 0 }}>{errs.items}</p>}
            {oid && <DataBoundary api={orderApi}><Async rows={3} cols={3}>
            {(order?.items || []).map((it) => {
              const s = sel[it.id];
              return (
                <div key={it.id} className={`ret-item${s ? ' is-on' : ''}`}>
                  <label className="row" style={{ cursor: 'pointer' }}>
                    <input type="checkbox" checked={!!s} onChange={() => toggle(it.id)} style={{ accentColor: '#111', width: 16, height: 16 }} />
                    <Thumb sm /><div style={{ flex: 1 }}><div className="prod-name">{it.name}</div><div className="soft small"><span className="sku">{it.sku}</span> · تم شراء <span className="num">{it.qty}</span> · <span className="num">{fmt(it.unit)}</span></div></div>
                  </label>
                  {s && (
                    <div className="fields mt-16" style={{ paddingInlineStart: 42 }}>
                      <Field label="الكمية المرتجعة" req hint={`حتى ${it.qty}`} error={errs[`${it.id}-qty`]}><Qty value={s.qty} min={1} onChange={(n) => set(it.id, 'qty', Math.min(n, it.qty))} /></Field>
                      <Field label="سبب البند" opt error={errs[it.id]}><input className={`line-input${errs[it.id] ? ' is-invalid' : ''}`} value={s.reason} placeholder="مثال: عيب في السحّاب" onChange={(e) => set(it.id, 'reason', e.target.value)} /></Field>
                      <Field label="صور" opt span hint="حتى 5 صور للبند · 5 ميجابايت للصورة · JPG / PNG / WebP" error={errs.files}>
                        <div className="row wrap">
                          {s.files.map((fl, k) => <button key={k} type="button" className="photo-chip" title={`حذف ${fl.name}`} onClick={() => { set(it.id, 'files', s.files.filter((_, index) => index !== k)); setErrs((previous) => ({ ...previous, files: null })); }}><i className="fa-regular fa-image" /></button>)}
                          {s.files.length < 5 && totalFiles < 10 && <label className="drop sm" style={{ minHeight: 52, width: 52, padding: 0, justifyContent: 'center', cursor: 'pointer' }}><input type="file" accept=".jpg,.jpeg,.png,.webp" multiple style={{ display: 'none' }} onChange={(e) => { addFiles(it.id, Array.from(e.target.files || [])); e.target.value = ''; }} /><i className="fa-solid fa-plus" /></label>}
                        </div>
                      </Field>
                    </div>
                  )}
                </div>);
            })}
            </Async></DataBoundary>}
            {errs.files && <p className="f-error" role="alert">{errs.files}</p>}
          </Panel>
        </div>
        <aside className="stack" style={{ position: 'sticky', top: 100 }}>
          <Panel title="السبب والاسترداد">
            <Field label="السبب العام" req error={errs.reason}><textarea className={`line-input${errs.reason ? ' is-invalid' : ''}`} rows="3" value={reason} onChange={(e) => setReason(e.target.value)} /></Field>
            <div className="f-label mt-24">طريقة الاسترداد</div>
            <div className="radio-list">{Object.entries(REFUND_METHOD).map(([k, l]) => <label key={k} className="radio-row"><span className="radio-left"><input type="radio" checked={method === k} onChange={() => setMethod(k)} /><span className="radio-title">{l}</span></span></label>)}</div>
            {errs.method && <p className="f-error">{errs.method}</p>}
            <div className="sum-row mt-16"><span>صور مرفقة</span><strong className="num" style={{ color: totalFiles > 10 ? '#b91c1c' : null }}>{totalFiles} / 10</strong></div>
            <button className="btn-block mt-24" disabled={busy || !order || !order.returnable} onClick={submit}>{busy ? <><span className="spin" /> جارٍ التقديم…</> : 'تقديم طلب الإرجاع'}</button>
          </Panel>
          <p className="note"><i className="fa-solid fa-circle-info" />إجمالي حجم الصور حتى 25 ميجابايت. مبلغ الاسترداد النهائي بيتحدد بعد فحص المرتجع.</p>
        </aside>
      </div>
    </>
  );
};

export const ReturnDetail = ({ id }) => {
  const { go } = useApp();
  const api = useApi(() => accountEndpoints.returnDetail(id).then(mapReturn), [id]);
  const r = api.data;
  const steps = r ? [['requested', r.date], ['approved', r.approved_at], ['received', r.received_at], ['refunded', r.refunded_at]] : [];

  const { toast } = useApp();
  const dlPhoto = async (url) => {
    try {
      await saveBlob(defaultApiClient.get(url, { responseType: 'blob', params: { signed: 1 } }), 'photo.jpg');
    } catch (e) {
      toast(apiError(e).message);
    }
  };

  return (
    <>
      <PageHead en={r?.number || ''} crumb={<Crumb items={[{ label: 'المرتجعات', go: () => go('returns') }, { label: r?.number || '', mono: true }]} />} ar={r ? <>على الطلب <button className="btn-link" onClick={() => go('order', r.order_id)}><span className="num">{r.order}</span></button></> : null}>
        {r && <Badge map={RETURN_STATUS} k={r.status} lg />}
      </PageHead>
      <DataBoundary api={api}>
        <Async rows={4} cols={4} empty={<Empty icon="fa-rotate-left" title="المرتجع غير موجود" />}>
          {r && (
          <div className="grid-2">
            <div className="stack">
              <Panel title="البنود" en="ITEMS" body={false}>
                <table className="tbl"><thead><tr><th>المنتج</th><th>الكمية</th><th>السبب</th><th>صور</th></tr></thead>
                  <tbody>{r.items.map((it, i) => <tr key={i}><td><div className="prod-cell"><Thumb sm /><div><div className="prod-name">{it.name}</div><div className="sku">{it.sku}</div></div></div></td><td className="num">{it.qty}</td><td>{it.reason}</td><td><div className="row" style={{ gap: 6 }}>{(it.attachments || []).map((u, k) => <button key={k} className="photo-chip" style={{ width: 36, height: 36 }} title="تنزيل الصورة" onClick={() => dlPhoto(u)}><i className="fa-regular fa-image" /></button>)}{it.photos > 0 && !it.attachments?.length && <span className="soft small num">{it.photos}</span>}</div></td></tr>)}</tbody></table>
              </Panel>
              <Panel title="السبب العام"><p style={{ margin: 0 }}>{r.reason}</p></Panel>
            </div>
            <div className="stack">
              <Panel title="الاسترداد" en="REFUND">
                <dl className="kv"><dt>المبلغ</dt><dd><Money v={r.refund} strong /></dd><dt>الطريقة</dt><dd>{REFUND_METHOD[r.method] || r.method || '—'}</dd><dt>المرجع</dt><dd className="sku">{r.ref || '—'}</dd></dl>
              </Panel>
              <Panel title="المراحل" en="TIMELINE">
                <ul className="tl">{steps.map(([s, d], i) => <li key={s} className={d ? (steps[i + 1] && steps[i + 1][1] ? 'is-done' : 'is-now') : 'is-future'}><div className="tl-title">{RETURN_STATUS[s] ? RETURN_STATUS[s][0] : s}</div>{d && <div className="tl-meta num">{d}</div>}</li>)}</ul>
              </Panel>
            </div>
          </div>
          )}
        </Async>
      </DataBoundary>
    </>
  );
};
```

## File: bags-platform/frontend/packages/ui/src/wholesale-portal/WholesalePortal.jsx
```javascript
'use client';

import React, { useState, useEffect, useCallback, useRef } from 'react';
import './portal.css';
import './auth.css';
import { AppCtx } from './context';
import { authEndpoints, cartEndpoints, wholesaleEndpoints, defaultApiClient } from '@alfarid/api-client';
import { useApi } from './data/useApi';
import { DataBoundary } from './data/DataBoundary';
import { Async, ErrorState } from './ui-primitives';
import { apiError } from './data/helpers';
import { mapAccount, mapCart } from './data/mappers';
import { fmt } from './data/format';
import {
  Dashboard,
  Catalog,
  QuickOrder,
  Cart,
  Checkout,
  OrderSuccess,
} from './buy-screens';
import {
  Orders,
  OrderDetail,
  Quotes,
  QuoteForm,
  QuoteDetail,
  Returns,
  ReturnForm,
  ReturnDetail,
} from './orders-screens';
import {
  Invoices,
  Statement,
  NoticeForm,
  Notices,
  NoticeDetail,
  Wallet,
  Notifications,
  Account,
} from './finance-screens';
import {
  ApplicationStatus,
  ForgotPassword,
  ResetPassword,
} from './auth-screens';

const NAV_GROUPS = [
  {
    title: 'نظرة عامة',
    items: [
      { id: 'dashboard', label: 'لوحة التحكم', icon: 'fa-gauge-high' },
    ],
  },
  {
    title: 'الطلبات والمشتريات',
    items: [
      { id: 'catalog', label: 'الكتالوج', icon: 'fa-table-cells-large' },
      { id: 'quick', label: 'طلب سريع', icon: 'fa-bolt' },
      { id: 'cart', label: 'سلة المشتريات', icon: 'fa-bag-shopping', badge: 'cart' },
      { id: 'orders', label: 'الطلبات', icon: 'fa-box' },
      { id: 'quotes', label: 'عروض الأسعار', icon: 'fa-file-signature' },
      { id: 'returns', label: 'المرتجعات', icon: 'fa-rotate-left' },
    ],
  },
  {
    title: 'المالية والحساب',
    items: [
      { id: 'invoices', label: 'الفواتير', icon: 'fa-file-lines' },
      { id: 'statement', label: 'كشف الحساب', icon: 'fa-file-invoice-dollar' },
      { id: 'notices', label: 'إشعارات السداد', icon: 'fa-money-check-dollar' },
      { id: 'wallet', label: 'المحفظة', icon: 'fa-wallet' },
    ],
  },
  {
    title: 'الإعدادات',
    items: [
      { id: 'account', label: 'بيانات الحساب', icon: 'fa-building' },
      { id: 'notifications', label: 'الإشعارات', icon: 'fa-bell', badge: 'notifs' },
      { id: 'status', label: 'حالة الحساب', icon: 'fa-shield-halved' },
    ],
  },
];

const readRoute = (initialScreen) => {
  if (typeof window === 'undefined') return { screen: initialScreen, id: null };
  const p = new URLSearchParams(window.location.search);
  return { screen: p.get('screen') || initialScreen, id: p.get('id') ? Number(p.get('id')) : null };
};

/**
 * @param {{ initialScreen?: string, initialId?: number|null, onLogout?: (() => unknown)|null, onEnter?: (() => unknown)|null, onForbidden?: (() => unknown)|null, onForgotSubmit?: ((login: string) => Promise<unknown>)|null, onResetSubmit?: ((login: string, token: string, password: string, confirmation: string) => Promise<unknown>)|null, brandName?: string }} props
 */
export function WholesalePortal({
  initialScreen = 'dashboard',
  initialId = null,
  onLogout = null,
  onEnter = null,
  onForbidden = null,
  onForgotSubmit = null,
  onResetSubmit = null,
  brandName = 'SOPHIE',
}) {
  const [route, setRoute] = useState(() => {
    const r = readRoute(initialScreen);
    return initialId != null && r.id == null ? { screen: r.screen, id: initialId } : r;
  });
  const routeRef = useRef(route);
  routeRef.current = route;
  const isAuthScreen = ['status', 'forgot', 'reset'].includes(route.screen);
  const [lastOrder, setLastOrder] = useState(null);
  const [unread, setUnread] = useState(0);
  const [toasts, setToasts] = useState([]);

  const toast = useCallback((msg) => {
    const id = Date.now() + Math.random();
    setToasts((prev) => [...prev, { id, msg }]);
    setTimeout(() => {
      setToasts((prev) => prev.filter((t) => t.id !== id));
    }, 3500);
  }, []);

  const forbidden = useCallback(() => {
    if (routeRef.current.screen !== 'status' && typeof onForbidden === 'function') onForbidden();
  }, [onForbidden]);

  useEffect(() => {
    const interceptor = defaultApiClient.interceptors.response.use((r) => r, (e) => {
      if (apiError(e).status === 403 && e.config?.url?.startsWith('/api/v1/wholesale/')) forbidden();
      return Promise.reject(e);
    });
    return () => defaultApiClient.interceptors.response.eject(interceptor);
  }, [forbidden]);

  const accountApi = useApi(async (signal) => {
    const [me, dash] = await Promise.all([authEndpoints.me(), wholesaleEndpoints.dashboard(signal)]);
    return { account: mapAccount(me.data, dash), dash };
  }, [], !isAuthScreen);
  const account = accountApi.data?.account;
  const dash = accountApi.data?.dash;
  const refreshAccount = accountApi.reload;
  const cartApi = useApi(() => cartEndpoints.get(null).then(mapCart), [], !isAuthScreen);
  const cart = cartApi.data;
  const setCartState = cartApi.setData;

  const loadCart = useCallback(async () => {
    try {
      const c = await cartEndpoints.get(null);
      setCartState(mapCart(c));
    } catch (e) {
      if (apiError(e).status === 403) forbidden();
    }
  }, [forbidden, setCartState]);

  const addToCart = useCallback(async (lines) => {
    try {
      let last = null;
      for (const l of lines) last = await cartEndpoints.add(l.variant_id, l.qty, null);
      if (last) setCartState(mapCart(last));
      toast('تمت الإضافة إلى سلة المشتريات بنجاح');
    } catch (e) { toast(apiError(e).message); }
  }, [toast, setCartState]);

  const applyCartResponse = useCallback((c) => {
    if (c) setCartState(mapCart(c));
  }, [setCartState]);

  useEffect(() => {
    if (isAuthScreen) return;
    const tick = () => wholesaleEndpoints.notifications(1).then((r) => setUnread(r.meta.unread_count)).catch(() => {});
    tick();
    const t = setInterval(tick, 60000);
    return () => clearInterval(t);
  }, [isAuthScreen]);

  const go = useCallback((screen, id = null) => {
    const p = new URLSearchParams();
    p.set('screen', screen);
    if (id != null) p.set('id', id);
    if (typeof window !== 'undefined') {
      window.history.pushState(null, '', `${window.location.pathname}?${p}`);
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    setRoute({ screen, id });
  }, []);

  useEffect(() => {
    const onPop = () => setRoute(readRoute(initialScreen));
    window.addEventListener('popstate', onPop);
    return () => window.removeEventListener('popstate', onPop);
  }, [initialScreen]);

  const logout = useCallback(() => {
    if (onLogout) onLogout();
    else toast('تم تسجيل الخروج');
  }, [onLogout, toast]);

  const cartTotalQty = cart?.lines.reduce((s, l) => s + l.qty, 0) ?? 0;

  const contextValue = {
    go,
    route,
    cart,
    cartApi,
    accountApi,
    loadCart,
    addToCart,
    applyCartResponse,
    cartTotalQty,
    lastOrder,
    setLastOrder,
    unread,
    setUnread,
    account,
    dash,
    refreshAccount,
    toast,
    logout,
  };

  // Screen router
  const renderScreen = () => {
    const { screen, id } = route;
    switch (screen) {
      case 'dashboard':
        return <Dashboard />;
      case 'catalog':
        return <Catalog />;
      case 'quick':
        return <QuickOrder />;
      case 'cart':
        return <Cart />;
      case 'checkout':
        return <Checkout />;
      case 'success':
      case 'orderSuccess':
        return <OrderSuccess />;
      case 'orders':
        return <Orders />;
      case 'order':
        return <OrderDetail id={id} />;
      case 'quotes':
        return <Quotes />;
      case 'quoteNew':
        return <QuoteForm />;
      case 'quoteEdit':
        return <QuoteForm id={id} />;
      case 'quote':
        return <QuoteDetail id={id} />;
      case 'returns':
        return <Returns />;
      case 'returnNew':
        return <ReturnForm orderId={id} />;
      case 'return':
        return <ReturnDetail id={id} />;
      case 'invoices':
        return <Invoices />;
      case 'statement':
        return <Statement />;
      case 'notices':
        return <Notices />;
      case 'noticeNew':
        return <NoticeForm />;
      case 'notice':
        return <NoticeDetail id={id} />;
      case 'wallet':
        return <Wallet />;
      case 'notifications':
        return <Notifications />;
      case 'account':
        return <Account />;
      case 'status':
        return <ApplicationStatus onLogout={logout} onEnter={onEnter || (() => go('dashboard'))} />;
      case 'forgot':
        return <ForgotPassword onBack={() => go('dashboard')} onGoReset={() => go('reset')} onForgotSubmit={onForgotSubmit} />;
      case 'reset':
        return <ResetPassword onBack={() => go('dashboard')} onForgot={() => go('forgot')} onResetSubmit={onResetSubmit} />;
      default:
        return <Dashboard />;
    }
  };

  if (isAuthScreen) return (
    <AppCtx.Provider value={contextValue}>
      <div className="wholesale-portal-app" dir="rtl">{renderScreen()}</div>
    </AppCtx.Provider>
  );

  if (!account) {
    if (accountApi.state === 'error') return (
      <div className="wholesale-portal-app" dir="rtl"><ErrorState onRetry={refreshAccount} /></div>
    );
    return (
      <div className="wholesale-portal-app" dir="rtl">
        <div className="wp-layout">
          <aside className="wp-side"><div className="sk" style={{ height: 18, width: '60%', margin: 16 }} /><div className="sk" style={{ height: 12, width: '80%', margin: 16 }} /><div className="sk" style={{ height: 12, width: '70%', margin: 16 }} /></aside>
          <main className="wp-main"><div className="wp-wrap"><div className="sk" style={{ height: 28, width: '40%', marginBottom: 16 }} /><div className="sk" style={{ height: 120, width: '100%' }} /></div></main>
        </div>
      </div>
    );
  }

  return (
    <AppCtx.Provider value={contextValue}>
      <div className="wholesale-portal-app" dir="rtl">
        {/* Sticky Header */}
        <header className="wp-header">
          <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
            <button className="wp-logo" onClick={() => go('dashboard')}>
              <span>{brandName}</span>
              <span className="wp-logo-tag">WHOLESALE</span>
            </button>
          </div>

          <div className="wp-head-right">
            <div className="wp-credit-chip" title="الحد الائتماني والمتاح">
              <span>الرصيد المتاح:</span>
              <strong className="num">{fmt(account.available_credit ?? 0, 0)} EGP</strong>
            </div>

            <button
              className="wp-icon-btn"
              onClick={() => go('notifications')}
              title="الإشعارات"
            >
              <i className="fa-regular fa-bell" />
              {unread > 0 && <span className="wp-count">{unread}</span>}
            </button>

            <button
              className="wp-icon-btn"
              onClick={() => go('cart')}
              title="سلة المشتريات"
            >
              <i className="fa-solid fa-bag-shopping" />
              {cartTotalQty > 0 && <span className="wp-count">{cartTotalQty}</span>}
            </button>

            <button
              className="wp-avatar"
              onClick={() => go('account')}
              title="الملف التجاري"
            >
              {String(account.name || '').split(' ').map((s) => s[0]).slice(0, 2).join('')}
            </button>
          </div>
        </header>

        {/* Standard portal layout */}
          <div className="wp-layout">
            {/* Sidebar */}
            <aside className="wp-side">
              <div className="wp-side-tag">B2B PORTAL</div>

              {NAV_GROUPS.map((group) => (
                <div key={group.title} className="wp-side-group">
                  <div className="wp-side-group-title">{group.title}</div>
                  {group.items.map((item) => {
                    const isActive =
                      route.screen === item.id ||
                      (item.id === 'orders' && route.screen === 'order') ||
                      (item.id === 'quotes' && ['quote', 'quoteNew', 'quoteEdit'].includes(route.screen)) ||
                      (item.id === 'returns' && ['return', 'returnNew'].includes(route.screen)) ||
                      (item.id === 'notices' && ['notice', 'noticeNew'].includes(route.screen));
                    const badgeVal =
                      item.badge === 'cart' && cartTotalQty > 0
                        ? cartTotalQty
                        : item.badge === 'notifs' && unread > 0
                        ? unread
                        : null;

                    return (
                      <button
                        key={item.id}
                        className={`wp-side-link ${isActive ? 'is-active' : ''}`}
                        onClick={() => go(item.id)}
                      >
                        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                          <span className="wp-side-dot" />
                          <i
                            className={`fa-solid ${item.icon}`}
                            style={{ width: 18, textAlign: 'center', fontSize: 13 }}
                          />
                          <span>{item.label}</span>
                        </div>
                        {badgeVal && <span className="wp-side-badge num">{badgeVal}</span>}
                      </button>
                    );
                  })}
                </div>
              ))}

              <div className="wp-side-foot">
                <button
                  className="wp-side-link"
                  onClick={logout}
                  style={{ color: '#be123c' }}
                >
                  <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                    <i
                      className="fa-solid fa-arrow-right-from-bracket"
                      style={{ width: 18, textAlign: 'center' }}
                    />
                    <span>تسجيل الخروج</span>
                  </div>
                </button>
              </div>
            </aside>

            {/* Main Content Area */}
            <main className="wp-main">
              <div className="wp-wrap">
                {route.screen === 'dashboard' ? (
                  <DataBoundary api={accountApi}><Async rows={3} cols={4}>{renderScreen()}</Async></DataBoundary>
                ) : renderScreen()}
              </div>
            </main>
          </div>
        {/* Floating Toasts */}
        {toasts.length > 0 && (
          <div
            style={{
              position: 'fixed',
              bottom: 24,
              left: '50%',
              transform: 'translateX(-50%)',
              zIndex: 9999,
              display: 'flex',
              flexDirection: 'column',
              gap: 8,
            }}
          >
            {toasts.map((t) => (
              <div
                key={t.id}
                className="toast"
                style={{ position: 'relative', bottom: 'auto', left: 'auto', transform: 'none' }}
              >
                <i className="fa-solid fa-circle-check" />
                <span>{t.msg}</span>
              </div>
            ))}
          </div>
        )}
      </div>
    </AppCtx.Provider>
  );
}

export default WholesalePortal;
```

## File: bags-platform/backend/app/Http/Controllers/Api/V1/Wholesale/InvoiceController.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wholesale;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wholesale\StorePaymentNoticeRequest;
use App\Http\Resources\Wholesale\WholesalePaymentNoticeResource;
use App\Models\Customer;
use App\Models\Order;
use App\Services\SettingService;
use App\Services\WholesalePaymentNoticeService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly WholesalePaymentNoticeService $notices,
        private readonly SettingService $settings,
    ) {}

    /**
     * List merchant invoices (both paid orders and orders on credit terms).
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'payment_status' => ['nullable', 'string', 'in:paid,unpaid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $invoices = Order::query()
            ->where('customer_id', $request->user()->id)
            ->where(fn ($q) => $q->where('payment_method', 'credit')->orWhere('payment_status', 'paid'))
            ->when($filters['payment_status'] ?? null, fn ($q, $v) => $q->where('payment_status', $v))
            ->latest('id')
            ->paginate($filters['per_page'] ?? 15);

        $data = $invoices->getCollection()->map(fn (Order $order) => [
            'id' => $order->id,
            'invoice_number' => $order->order_number,
            'order_number' => $order->order_number,
            'status' => $order->status->value,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'subtotal' => $order->subtotal,
            'discount_amount' => $order->discount_amount,
            'shipping_cost' => $order->shipping_cost,
            'tax_amount' => $order->tax_amount,
            'grand_total' => $order->grand_total,
            'created_at' => $order->created_at?->toIso8601String(),
            'pdf_url' => url("/api/v1/wholesale/invoices/{$order->id}/pdf"),
        ])->values()->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $invoices->currentPage(),
                'last_page' => $invoices->lastPage(),
                'per_page' => $invoices->perPage(),
                'total' => $invoices->total(),
            ],
        ]);
    }

    /**
     * Download the merchant's order invoice as PDF (scoped to the authenticated merchant).
     */
    public function pdf(Request $request, int $order): Response
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $model = Order::query()
            ->where('customer_id', $customer->id)
            ->with('items')
            ->findOrFail($order);

        return Pdf::loadView('pdfs.wholesale_invoice', [
            'company_name' => (string) $this->settings->get('company_name', 'Alfarid'),
            'customer' => $customer,
            'order' => $model,
        ])->download("invoice-{$model->order_number}.pdf");
    }

    /**
     * Upload a bank payment proof for outstanding credit balances.
     */
    public function storePaymentNotice(StorePaymentNoticeRequest $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $notice = $this->notices->create($customer, $request->validated(), $request->file('receipt'));

        return (new WholesalePaymentNoticeResource($notice->load('order')))->response()->setStatusCode(201);
    }

    /**
     * Retrieve detailed account statement including debits, credits, and cumulative balance.
     */
    public function statement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        /** @var Customer $customer */
        $customer = $request->user();
        $statement = $this->notices->statement($customer, $validated['from'] ?? null, $validated['to'] ?? null);

        return response()->json([
            'data' => [
                'opening_balance' => number_format($statement['opening_balance'], 2, '.', ''),
                'closing_balance' => number_format($statement['closing_balance'], 2, '.', ''),
                'entries' => $statement['entries'],
            ],
        ]);
    }
}
```

## File: bags-platform/backend/app/Http/Requests/Admin/Return/ApproveReturnRequest.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Return;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Initial return approval: receiving branch and optional approved quantities (defaults to full requested).
 */
class ApproveReturnRequest extends FormRequest
{
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
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['nullable', 'array', 'max:50'],
            'items.*.id' => ['required', 'integer'],
            'items.*.approved_qty' => ['required', 'integer', 'min:0', 'max:99999999'],
        ];
    }
}
```

## File: bags-platform/backend/app/Http/Requests/Admin/Return/ReceiveReturnRequest.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Return;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Physical inspection of return items: good and damaged quantities (total <= approved).
 */
class ReceiveReturnRequest extends FormRequest
{
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
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.id' => ['required', 'integer'],
            'items.*.received_good_qty' => ['required', 'integer', 'min:0', 'max:99999999'],
            'items.*.received_damaged_qty' => ['required', 'integer', 'min:0', 'max:99999999'],
        ];
    }
}
```

## File: bags-platform/backend/app/Http/Requests/Admin/Return/RefundReturnRequest.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Return;

use App\Enums\RefundMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Complete financial refund for inspected return items: refund method and external reference.
 */
class RefundReturnRequest extends FormRequest
{
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
            'refund_method' => ['nullable', Rule::enum(RefundMethod::class)],
            'refund_reference' => ['nullable', 'string', 'max:191'],
        ];
    }
}
```

## File: bags-platform/backend/app/Http/Requests/Admin/Return/UpdateReturnRequest.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Return;

use App\Enums\RefundMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Administrative updates to a return request (notes, refund method, or rejection/cancellation).
 */
class UpdateReturnRequest extends FormRequest
{
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
            'admin_notes' => ['nullable', 'string', 'max:2000'],
            'refund_method' => ['nullable', Rule::enum(RefundMethod::class)],
            'status' => ['nullable', Rule::in(['rejected', 'cancelled'])],
        ];
    }
}
```

## File: bags-platform/backend/app/Models/ReturnRequestItem.php
```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ReturnRequestItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Return request line item tracking requested, approved, and received quantities with snapshot refund pricing.
 */
class ReturnRequestItem extends Model
{
    /** @use HasFactory<ReturnRequestItemFactory> */
    use HasFactory;

    protected $fillable = [
        'return_request_id',
        'order_item_id',
        'product_variant_id',
        'requested_qty',
        'approved_qty',
        'received_good_qty',
        'received_damaged_qty',
        'unit_refund_price',
        'refund_amount',
        'item_reason',
        'attachments',
    ];

    protected function casts(): array
    {
        return [
            'requested_qty' => 'decimal:4',
            'approved_qty' => 'decimal:4',
            'received_good_qty' => 'decimal:4',
            'received_damaged_qty' => 'decimal:4',
            'unit_refund_price' => 'decimal:4',
            'refund_amount' => 'decimal:2',
            'attachments' => 'array',
        ];
    }

    /** @return BelongsTo<ReturnRequest, $this> */
    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class);
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
```

## File: bags-platform/backend/app/Services/WalletService.php
```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\CustomerWalletTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Manages customer wallet balance and immutable ledger transactions.
 *
 * All balance adjustments are computed using integer cents to prevent floating-point
 * rounding errors, guarded by pessimistic locking and idempotency uniqueness checks.
 */
class WalletService
{
    /**
     * Record a debit or credit transaction on a customer's wallet ledger.
     *
     * @param  int  $customerId
     * @param  float  $amount  Positive for credit/top-up, negative for debit/payment
     * @param  string  $type  Transaction type (e.g. checkout_payment, refund, adjustment)
     * @param  string  $referenceType  Morph class or table name of the triggering source
     * @param  int  $referenceId  ID of the triggering source record
     * @param  int|null  $createdBy  User ID if triggered by an administrator
     * @return CustomerWalletTransaction
     *
     * @throws BusinessException
     */
    public function record(int $customerId, float $amount, string $type, string $referenceType, int $referenceId, ?int $createdBy = null): CustomerWalletTransaction
    {
        return DB::transaction(function () use ($customerId, $amount, $type, $referenceType, $referenceId, $createdBy): CustomerWalletTransaction {
            // Lock customer row to serialize concurrent debits/credits on the same balance
            $customer = Customer::query()->lockForUpdate()->findOrFail($customerId);
            if ($type === 'checkout_payment' && $customer->status !== CustomerStatus::Approved) {
                throw new BusinessException('الدفع بالمحفظة يتطلب حساب عميل نشطًا.');
            }
            // Enforce idempotency: prevent duplicate ledger entries for the same reference event
            if (CustomerWalletTransaction::query()->where('reference_type', $referenceType)->where('reference_id', $referenceId)->where('type', $type)->exists()) {
                throw new BusinessException('حركة المحفظة مسجلة سابقًا.', [], 409);
            }
            // Calculate balances in integer cents to eliminate floating-point drift
            $amountCents = (int) round($amount * 100);
            $balanceCents = (int) round((float) $customer->wallet_balance * 100) + $amountCents;
            if ($balanceCents < 0) {
                throw new BusinessException('رصيد المحفظة لا يكفي قيمة الطلب كاملة.');
            }
            if ($balanceCents > 999999999999) {
                throw new BusinessException('رصيد المحفظة يتجاوز الحد المسموح.');
            }
            $entry = CustomerWalletTransaction::create([
                'customer_id' => $customerId, 'type' => $type, 'amount' => $amountCents / 100,
                'balance_after' => $balanceCents / 100, 'reference_type' => $referenceType,
                'reference_id' => $referenceId, 'created_by' => $createdBy,
            ]);
            $customer->forceFill(['wallet_balance' => $balanceCents / 100])->save();

            return $entry;
        });
    }
}
```

## File: bags-platform/backend/database/migrations/2026_10_19_073459_harden_returns_and_procurement_operations.php
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Moving return attachments to private storage is deferred to an artisan command to minimize DDL lock time.
        DB::statement('ALTER TABLE product_variants ALTER COLUMN cost_price TYPE numeric(14, 4)');
        Schema::create('operation_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('operation', 100);
            $table->string('actor_type', 100);
            $table->unsignedBigInteger('actor_id');
            $table->string('key', 128);
            $table->string('request_hash', 64);
            $table->string('result_type')->nullable();
            $table->unsignedBigInteger('result_id')->nullable();
            $table->timestamp('created_at');
            $table->unique(['operation', 'actor_type', 'actor_id', 'key'], 'operation_idempotency_scope_unique');
        });
        Schema::table('supplier_payments', function (Blueprint $table): void {
            $table->string('idempotency_key', 128)->nullable()->unique();
        });
        Schema::table('return_request_items', function (Blueprint $table): void {
            $table->decimal('refund_amount', 12, 2)->default(0);
        });
        DB::statement('UPDATE return_request_items SET refund_amount = round((received_good_qty + received_damaged_qty) * unit_refund_price, 2)');
        Schema::create('customer_wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->decimal('amount', 14, 2);
            $table->decimal('balance_after', 14, 2);
            $table->string('reference_type');
            $table->unsignedBigInteger('reference_id');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['reference_type', 'reference_id', 'type'], 'wallet_reference_unique');
            $table->index(['customer_id', 'created_at']);
        });
        DB::statement("INSERT INTO customer_wallet_transactions (customer_id, type, amount, balance_after, reference_type, reference_id) SELECT id, 'opening', wallet_balance, wallet_balance, 'customer', id FROM customers WHERE wallet_balance <> 0");
        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_wallet_nonnegative CHECK (wallet_balance >= 0)');
        DB::statement('ALTER TABLE customer_wallet_transactions ADD CONSTRAINT wallet_balance_nonnegative CHECK (balance_after >= 0)');
        DB::unprepared("CREATE OR REPLACE FUNCTION prevent_wallet_mutation() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Wallet ledger is append-only'; END; $$; CREATE TRIGGER wallet_immutable BEFORE UPDATE OR DELETE ON customer_wallet_transactions FOR EACH ROW EXECUTE FUNCTION prevent_wallet_mutation()");
        DB::statement("CREATE UNIQUE INDEX supplier_payments_reference_unique ON supplier_payments (supplier_id, lower(btrim(reference_no))) WHERE reference_no IS NOT NULL AND payment_method <> 'cash'");
        DB::statement("CREATE UNIQUE INDEX supplier_invoices_supplier_no_unique ON supplier_invoices (supplier_id, lower(btrim(supplier_invoice_no))) WHERE status <> 'cancelled'");
        DB::statement('CREATE INDEX supplier_invoices_supplier_date_index ON supplier_invoices (supplier_id, issue_date)');
        DB::statement('CREATE INDEX return_requests_number_pattern_index ON return_requests USING gin (return_number gin_trgm_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS return_requests_number_pattern_index');
        DB::statement('DROP INDEX IF EXISTS supplier_invoices_supplier_date_index');
        DB::statement('DROP INDEX IF EXISTS supplier_invoices_supplier_no_unique');
        DB::statement('DROP INDEX IF EXISTS supplier_payments_reference_unique');
        Schema::dropIfExists('customer_wallet_transactions');
        DB::statement('DROP FUNCTION IF EXISTS prevent_wallet_mutation()');
        DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customers_wallet_nonnegative');
        Schema::table('supplier_payments', function (Blueprint $table): void {
            $table->dropColumn('idempotency_key');
        });
        Schema::table('return_request_items', function (Blueprint $table): void {
            $table->dropColumn('refund_amount');
        });
        Schema::dropIfExists('operation_idempotency_keys');
        // Preserving cost_price precision: do not revert to numeric(12,2) to avoid data truncation.
    }
};
```

## File: bags-platform/backend/database/seeders/ReturnPermissionSeeder.php
```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\StaffRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Return request permissions (approval, inspection receipt, and financial refund).
 */
class ReturnPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'returns.view', 'returns.create', 'returns.update', 'returns.delete', 'returns.approve', 'returns.receive', 'returns.refund',
        ];

        $created = [];
        foreach ($permissions as $permission) {
            $created[] = Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'staff']);
        }

        $superAdmin = Role::firstOrCreate(['name' => StaffRole::SuperAdmin->value, 'guard_name' => 'staff']);
        $superAdmin->givePermissionTo($created);
    }
}
```

## File: bags-platform/frontend/apps/backoffice/src/pages/admin/operations-mock/data.ts
```typescript
/* ============ Status maps + mock data (Vortix Admin) ============ */

export const BRANCHES = [
  'فرع مدينة نصر',
  'فرع المعادي',
  'فرع الإسكندرية - سموحة',
  'فرع المنصورة',
  'المخزن الرئيسي',
];

export const CITIES = [
  'القاهرة',
  'الجيزة',
  'الإسكندرية',
  'المنصورة',
  'طنطا',
  'الزقازيق',
  'أسيوط',
  'سوهاج',
  'الأقصر',
  'أسوان',
  'بورسعيد',
  'الإسماعيلية',
  'السويس',
  'دمياط',
  'المنيا',
  'بني سويف',
  'الفيوم',
  'الغردقة',
];

export const CHANNELS = ['المتجر الإلكتروني', 'تطبيق الجوال', 'نقطة البيع', 'بوابة الجملة'];
export const CASHIERS = ['محمود سعيد', 'منة الله عادل', 'كريم فتحي', 'سارة حمدي', 'يوسف عاطف'];

export interface StatusMeta {
  label: string;
  tone: 'gray' | 'amber' | 'blue' | 'violet' | 'orange' | 'green' | 'red';
}

export const ORDER_STATUS: Record<string, StatusMeta> = {
  pending: { label: 'قيد المراجعة', tone: 'amber' },
  confirmed: { label: 'مؤكد', tone: 'blue' },
  processing: { label: 'قيد التجهيز', tone: 'violet' },
  shipped: { label: 'تم الشحن', tone: 'orange' },
  delivered: { label: 'تم التسليم', tone: 'green' },
  cancelled: { label: 'ملغي', tone: 'red' },
  returned: { label: 'مرتجع', tone: 'gray' },
};

export const ORDER_NEXT: Record<string, string[]> = {
  pending: ['confirmed', 'cancelled'],
  confirmed: ['processing', 'shipped', 'cancelled'],
  processing: ['shipped', 'cancelled'],
  shipped: ['delivered', 'returned'],
  delivered: ['returned'],
  cancelled: [],
  returned: [],
};

export const PAY_STATUS: Record<string, StatusMeta> = {
  unpaid: { label: 'غير مدفوع', tone: 'amber' },
  paid: { label: 'مدفوع', tone: 'green' },
  failed: { label: 'فشل الدفع', tone: 'red' },
  refunded: { label: 'مسترد', tone: 'gray' },
};

export const PAY_METHODS: Record<string, string> = {
  cod: 'عند الاستلام',
  card: 'بطاقة',
  wallet: 'محفظة',
  bank: 'تحويل بنكي',
  credit: 'آجل',
};

export const RETURN_STATUS: Record<string, StatusMeta> = {
  pending: { label: 'قيد المراجعة', tone: 'amber' },
  approved: { label: 'موافق عليه', tone: 'blue' },
  rejected: { label: 'مرفوض', tone: 'red' },
  received: { label: 'مستلم', tone: 'violet' },
  refunded: { label: 'تم رد المبلغ', tone: 'green' },
  cancelled: { label: 'ملغي', tone: 'gray' },
};

export const REFUND_METHODS: Record<string, string> = {
  original: 'وسيلة الدفع الأصلية',
  wallet: 'المحفظة',
  bank: 'تحويل بنكي',
  cash: 'نقدي',
};

export const PR_STATUS: Record<string, StatusMeta> = {
  draft: { label: 'مسودة', tone: 'gray' },
  pending: { label: 'بانتظار الاعتماد', tone: 'amber' },
  approved: { label: 'معتمد', tone: 'green' },
  rejected: { label: 'مرفوض', tone: 'red' },
  converted_to_po: { label: 'تحول لأمر شراء', tone: 'violet' },
};

export const PO_STATUS: Record<string, StatusMeta> = {
  draft: { label: 'مسودة', tone: 'gray' },
  sent: { label: 'مرسل للمورد', tone: 'blue' },
  accepted: { label: 'مقبول من المورد', tone: 'green' },
  revision: { label: 'المورد طلب تعديلًا', tone: 'amber' },
  partial: { label: 'مستلم جزئيًا', tone: 'violet' },
  completed: { label: 'مكتمل', tone: 'green' },
  cancelled: { label: 'ملغي', tone: 'red' },
};

export const INV_STATUS: Record<string, StatusMeta> = {
  draft: { label: 'مسودة', tone: 'gray' },
  posted: { label: 'مُرحّلة', tone: 'blue' },
  partial: { label: 'مدفوعة جزئيًا', tone: 'amber' },
  paid: { label: 'مدفوعة', tone: 'green' },
  cancelled: { label: 'ملغاة', tone: 'red' },
};

export const TRANSFER_STATUS: Record<string, StatusMeta> = {
  pending: { label: 'بانتظار الاعتماد', tone: 'amber' },
  approved: { label: 'معتمد', tone: 'green' },
  rejected: { label: 'مرفوض', tone: 'red' },
};

export const SHIFT_STATUS: Record<string, StatusMeta> = {
  open: { label: 'مفتوحة', tone: 'blue' },
  closed: { label: 'مغلقة بانتظار الاعتماد', tone: 'amber' },
  approved: { label: 'معتمدة', tone: 'green' },
};

export const QUOTE_STATUS: Record<string, StatusMeta> = {
  draft: { label: 'مسودة', tone: 'gray' },
  requested: { label: 'مطلوب تسعيره', tone: 'amber' },
  priced: { label: 'مسعّر من الإدارة', tone: 'blue' },
  accepted: { label: 'مقبول', tone: 'green' },
  rejected: { label: 'مرفوض', tone: 'red' },
  expired: { label: 'منتهي', tone: 'gray' },
  converted: { label: 'تحول لطلب', tone: 'violet' },
};

export const NOTICE_STATUS: Record<string, StatusMeta> = {
  pending: { label: 'بانتظار المراجعة', tone: 'amber' },
  approved: { label: 'موافق عليه', tone: 'green' },
  rejected: { label: 'مرفوض', tone: 'red' },
};

/* ---------- products / variants ---------- */
export interface ProductVariant {
  id: number;
  name: string;
  sku: string;
  price: number;
  cost: number;
}

export const VARIANTS: ProductVariant[] = [
  { id: 1, name: 'زيت زيتون بكر ممتاز 1 لتر', sku: 'OIL-EV-1000', price: 385, cost: 290 },
  { id: 2, name: 'أرز مصري فاخر 5 كجم', sku: 'RCE-EG-5000', price: 210, cost: 168 },
  { id: 3, name: 'سكر أبيض ناعم 1 كجم', sku: 'SGR-WH-1000', price: 38.5, cost: 31 },
  { id: 4, name: 'شاي أسود ناعم 250 جم', sku: 'TEA-BL-250', price: 96, cost: 72 },
  { id: 5, name: 'مكرونة بيني 400 جم', sku: 'PST-PN-400', price: 22, cost: 15.5 },
  { id: 6, name: 'سمن نباتي 2 كجم', sku: 'GHE-VG-2000', price: 268, cost: 214 },
  { id: 7, name: 'عسل نحل طبيعي 500 جم', sku: 'HNY-NT-500', price: 240, cost: 175 },
  { id: 8, name: 'تونة قطع فاتح 185 جم', sku: 'TNA-LT-185', price: 64, cost: 49 },
  { id: 9, name: 'بن محوّج 200 جم', sku: 'COF-SP-200', price: 145, cost: 104 },
  { id: 10, name: 'دقيق فاخر 1 كجم', sku: 'FLR-PR-1000', price: 32, cost: 25 },
];

/* ---------- orders ---------- */
export const NAMES = [
  'أحمد عبد الرحمن',
  'مريم خالد',
  'شركة النور للتجارة',
  'محمد إبراهيم',
  'زائر',
  'نورهان سامي',
  'عمر مصطفى',
  'هبة الله فوزي',
  'سوبر ماركت الأمل',
  'إسلام حسن',
  'زائر',
  'دينا وليد',
];

export interface OrderRow {
  id: number;
  no: string;
  customer: string;
  guest: boolean;
  ctype: string;
  phone: string;
  channel: string;
  status: string;
  pay: string;
  payStatus: string;
  branch: string | null;
  total: number;
  date: string;
  time: string;
  followUp: boolean;
  needRefund: boolean;
  city: string;
}

export const ORDERS: OrderRow[] = (() => {
  const sts = ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'delivered', 'cancelled', 'returned', 'pending', 'processing', 'shipped', 'confirmed'];
  const pays = ['cod', 'card', 'wallet', 'bank', 'credit', 'cod', 'card', 'card', 'cod', 'wallet', 'cod', 'credit'];
  const pst = ['unpaid', 'paid', 'paid', 'unpaid', 'paid', 'paid', 'refunded', 'paid', 'failed', 'paid', 'unpaid', 'unpaid'];
  return NAMES.map((n, i) => {
    const guest = n === 'زائر';
    return {
      id: 10482 - i * 7,
      no: 'ORD-' + (10482 - i * 7),
      customer: guest ? (i === 4 ? 'رانيا محمود' : 'طارق سليم') : n,
      guest,
      ctype: guest ? 'زائر' : (n.includes('شركة') || n.includes('سوبر')) ? 'جملة' : 'قطاعي',
      phone: '01' + ['0', '1', '2', '5'][i % 4] + String(23456781 + i * 1093217).slice(0, 8),
      channel: CHANNELS[i % 4],
      status: sts[i],
      pay: pays[i],
      payStatus: pst[i],
      branch: i === 8 ? null : BRANCHES[i % 5],
      total: [1284.5, 3920, 642, 18750, 455.75, 2310, 980, 1675, 799, 5420, 312.5, 24800][i],
      date: `2026-10-${String(27 - Math.floor(i / 2)).padStart(2, '0')}`,
      time: ['09:14', '10:42', '11:05', '12:30', '13:18', '14:02', '15:47', '16:20', '17:11', '18:35', '19:08', '20:44'][i],
      followUp: [true, false, false, true, false, false, false, false, true, false, false, true][i],
      needRefund: [false, false, false, false, false, false, true, true, false, false, false, false][i],
      city: CITIES[i % 8],
    };
  });
})();

/* ---------- returns ---------- */
export interface ReturnRow {
  id: number;
  no: string;
  order: string;
  customer: string;
  branch: string | null;
  status: string;
  method: string | null;
  total: number;
  date: string;
}

export const RETURNS: ReturnRow[] = [
  { id: 1, no: 'RET-2041', order: 'ORD-10433', customer: 'نورهان سامي', branch: 'فرع المعادي', status: 'pending', method: 'original', total: 770, date: '2026-10-27' },
  { id: 2, no: 'RET-2040', order: 'ORD-10426', customer: 'عمر مصطفى', branch: 'فرع مدينة نصر', status: 'approved', method: 'wallet', total: 1240, date: '2026-10-26' },
  { id: 3, no: 'RET-2039', order: 'ORD-10398', customer: 'سوبر ماركت الأمل', branch: 'المخزن الرئيسي', status: 'received', method: 'bank', total: 6480, date: '2026-10-25' },
  { id: 4, no: 'RET-2038', order: 'ORD-10377', customer: 'هبة الله فوزي', branch: 'فرع الإسكندرية - سموحة', status: 'refunded', method: 'cash', total: 385, date: '2026-10-23' },
  { id: 5, no: 'RET-2037', order: 'ORD-10361', customer: 'إسلام حسن', branch: 'فرع المنصورة', status: 'rejected', method: null, total: 0, date: '2026-10-22' },
  { id: 6, no: 'RET-2036', order: 'ORD-10340', customer: 'مريم خالد', branch: null, status: 'cancelled', method: 'original', total: 210, date: '2026-10-20' },
];

/* ---------- suppliers ---------- */
export interface SupplierRow {
  id: number;
  code: string;
  name: string;
  company: string | null;
  phone: string;
  email: string | null;
  terms: number;
  balance: number;
  active: boolean;
}

export const SUPPLIERS: SupplierRow[] = [
  { id: 1, code: 'SUP-001', name: 'مصطفى الشريف', company: 'الشريف للزيوت والسمن', phone: '01001234567', email: 'orders@sherif-oils.com', terms: 30, balance: 184250, active: true },
  { id: 2, code: 'SUP-002', name: 'هاني عبد الله', company: 'مطاحن الدلتا', phone: '01112233445', email: 'sales@deltamills.eg', terms: 45, balance: 92600.5, active: true },
  { id: 3, code: 'SUP-003', name: 'إيمان رشاد', company: 'النيل للتعبئة', phone: '01223344556', email: null, terms: 15, balance: -4200, active: true },
  { id: 4, code: 'SUP-004', name: 'شريف منير', company: 'بن الحرمين', phone: '01098877665', email: 'info@haramain-coffee.com', terms: 60, balance: 38900, active: true },
  { id: 5, code: 'SUP-005', name: 'وليد قاسم', company: null, phone: '01556677889', email: null, terms: 0, balance: 0, active: false },
  { id: 6, code: 'SUP-006', name: 'رامي جمال', company: 'جمال للأغذية المحفوظة', phone: '01067788990', email: 'rami@gamal-foods.com', terms: 30, balance: 61340, active: true },
];

export interface TreasuryRow {
  id: number;
  name: string;
  code: string;
  type: 'main' | 'drawer' | 'bank';
  branch: string | null;
  balance: number;
  active: boolean;
}

export const TREASURIES: TreasuryRow[] = [
  { id: 1, name: 'الخزنة الرئيسية', code: 'TR-MAIN', type: 'main', branch: null, balance: 1284600, active: true },
  { id: 2, name: 'درج كاشير 1 — مدينة نصر', code: 'CD-NC-01', type: 'drawer', branch: 'فرع مدينة نصر', balance: 18420.5, active: true },
  { id: 3, name: 'درج كاشير 2 — مدينة نصر', code: 'CD-NC-02', type: 'drawer', branch: 'فرع مدينة نصر', balance: 9310, active: true },
  { id: 4, name: 'درج كاشير — المعادي', code: 'CD-MA-01', type: 'drawer', branch: 'فرع المعادي', balance: 12780, active: true },
  { id: 5, name: 'البنك الأهلي — جاري', code: 'BK-NBE-01', type: 'bank', branch: null, balance: 3420150.75, active: true },
  { id: 6, name: 'بنك مصر — جاري', code: 'BK-MSR-01', type: 'bank', branch: null, balance: 865300, active: true },
  { id: 7, name: 'درج كاشير — سموحة', code: 'CD-AX-01', type: 'drawer', branch: 'فرع الإسكندرية - سموحة', balance: 0, active: false },
];

export const TREASURY_TYPES: Record<string, string> = {
  main: 'خزنة رئيسية',
  drawer: 'درج كاشير',
  bank: 'حساب بنكي',
};

export const WHOLESALE = [
  'شركة النور للتجارة',
  'سوبر ماركت الأمل',
  'هايبر الفتح',
  'مؤسسة الرحمة للتوزيع',
  'ماركت الصفا',
];

/* ---------- Shipping, Pricing, Promos & Brands ---------- */
export interface ShipCompany {
  id: number;
  name: string;
  code: string;
  url: string;
  active: boolean;
  api: boolean;
}

export const SHIP_COMPANIES: ShipCompany[] = [
  { id: 1, name: 'بوسطة', code: 'BOSTA', url: 'https://bosta.co/tracking-shipments?shipment-number={awb}', active: true, api: true },
  { id: 2, name: 'أرامكس', code: 'ARAMEX', url: 'https://www.aramex.com/track/results?ShipmentNumber={awb}', active: true, api: true },
  { id: 3, name: 'مرسول إكسبريس', code: 'MRSOOL', url: 'https://track.mrsool.eg/{awb}', active: true, api: false },
  { id: 4, name: 'مندوب داخلي', code: 'INHOUSE', url: '', active: true, api: false },
  { id: 5, name: 'J&T Express', code: 'JNT', url: 'https://www.jtexpress.eg/track?awb={awb}', active: false, api: false },
];

export interface ShipZone {
  id: number;
  name: string;
  cities: string[];
  active: boolean;
}

export const SHIP_ZONES: ShipZone[] = [
  { id: 1, name: 'القاهرة الكبرى', cities: ['القاهرة', 'الجيزة'], active: true },
  { id: 2, name: 'الإسكندرية والساحل', cities: ['الإسكندرية'], active: true },
  { id: 3, name: 'الدلتا', cities: ['المنصورة', 'طنطا', 'الزقازيق', 'دمياط'], active: true },
  { id: 4, name: 'القناة', cities: ['بورسعيد', 'الإسماعيلية', 'السويس'], active: true },
  { id: 5, name: 'الصعيد', cities: ['أسيوط', 'سوهاج', 'الأقصر', 'أسوان', 'المنيا', 'بني سويف', 'الفيوم'], active: true },
  { id: 6, name: 'البحر الأحمر', cities: ['الغردقة'], active: false },
];

export interface ShipRate {
  id: number;
  co: string;
  zone: number;
  base: number;
  free: number | null;
  cod: number;
  min: number;
  max: number;
}

export const SHIP_RATES: ShipRate[] = [
  { id: 1, co: 'BOSTA', zone: 1, base: 45, free: 1500, cod: 10, min: 1, max: 2 },
  { id: 2, co: 'BOSTA', zone: 3, base: 60, free: 2000, cod: 12, min: 2, max: 3 },
  { id: 3, co: 'ARAMEX', zone: 2, base: 65, free: null, cod: 15, min: 2, max: 4 },
  { id: 4, co: 'ARAMEX', zone: 5, base: 85, free: 0, cod: 15, min: 3, max: 6 },
  { id: 5, co: 'INHOUSE', zone: 1, base: 30, free: 800, cod: 0, min: 0, max: 1 },
  { id: 6, co: 'MRSOOL', zone: 4, base: 70, free: null, cod: 10, min: 2, max: 4 },
];

export interface CustomerGroup {
  id: number;
  name: string;
  code: string;
  desc: string | null;
  disc: number;
  active: boolean;
}

export const CUSTOMER_GROUPS: CustomerGroup[] = [
  { id: 1, name: 'عملاء VIP', code: 'VIP', desc: 'عملاء التجزئة الأعلى إنفاقًا', disc: 7.5, active: true },
  { id: 2, name: 'تجار الجملة — فئة أ', code: 'WHL-A', desc: 'حجم شراء شهري يتجاوز 250 ألف', disc: 12, active: true },
  { id: 3, name: 'تجار الجملة — فئة ب', code: 'WHL-B', desc: null, disc: 8, active: true },
  { id: 4, name: 'الموظفون', code: 'STAFF', desc: 'خصم موظفي الشركة', disc: 15, active: true },
  { id: 5, name: 'المطاعم والكافيهات', code: 'HORECA', desc: 'قطاع الضيافة', disc: 10, active: false },
];

export interface PriceListRow {
  id: number;
  name: string;
  type: string;
  target: string | null;
  branch: string | null;
  from: string;
  to: string | null;
  prio: number;
  active: boolean;
  items: number;
}

export const PRICE_LISTS: PriceListRow[] = [
  { id: 1, name: 'قائمة القطاعي الأساسية', type: 'retail', target: null, branch: null, from: '2026-01-01', to: null, prio: 0, active: true, items: 1240 },
  { id: 2, name: 'أسعار الجملة 2026', type: 'wholesale', target: null, branch: null, from: '2026-01-01', to: '2026-12-31', prio: 100, active: true, items: 860 },
  { id: 3, name: 'موزعي الدلتا', type: 'distributor', target: null, branch: 'فرع المنصورة', from: '2026-07-01', to: null, prio: 150, active: true, items: 412 },
  { id: 4, name: 'خصومات VIP', type: 'group', target: 'عملاء VIP', branch: null, from: '2026-10-01', to: '2026-12-31', prio: 200, active: true, items: 95 },
  { id: 5, name: 'تعاقد سوبر ماركت الأمل', type: 'customer', target: 'سوبر ماركت الأمل', branch: null, from: '2026-09-15', to: '2027-03-15', prio: 500, active: true, items: 64 },
  { id: 6, name: 'عروض فرع سموحة', type: 'retail', target: null, branch: 'فرع الإسكندرية - سموحة', from: '2026-06-01', to: '2026-08-31', prio: 50, active: false, items: 38 },
];

export interface PromoRow {
  id: number;
  name: string;
  type: 'percent' | 'amount' | 'bxgy' | 'free_ship';
  value: number | null;
  min: number | null;
  scope: 'all' | 'categories' | 'brands' | 'variants';
  targets: string[];
  from: string;
  to: string | null;
  active: boolean;
}

export const PROMOS: PromoRow[] = [
  { id: 1, name: 'تخفيضات نوفمبر', type: 'percent', value: 15, min: 500, scope: 'categories', targets: ['البقالة', 'المعلبات'], from: '2026-11-01', to: '2026-11-30', active: true },
  { id: 2, name: 'خصم 50 جنيه على الزيوت', type: 'amount', value: 50, min: 400, scope: 'brands', targets: ['الشريف'], from: '2026-10-15', to: '2026-11-15', active: true },
  { id: 3, name: 'شحن مجاني للطلبات الكبيرة', type: 'free_ship', value: null, min: 1200, scope: 'all', targets: [], from: '2026-10-01', to: null, active: true },
  { id: 4, name: 'اشترِ 2 شاي واحصل على 1', type: 'bxgy', value: null, min: null, scope: 'variants', targets: ['TEA-BL-250'], from: '2026-09-01', to: '2026-09-30', active: false },
];

export interface CouponRow {
  id: number;
  code: string;
  type: 'percent' | 'amount' | 'free_ship';
  value: number | null;
  maxDisc: number | null;
  min: number;
  total: number | null;
  perCust: number | null;
  used: number;
  from: string;
  to: string | null;
  active: boolean;
}

export const COUPONS: CouponRow[] = [
  { id: 1, code: 'WELCOME50', type: 'amount', value: 50, maxDisc: null, min: 300, total: 5000, perCust: 1, used: 1842, from: '2026-01-01', to: null, active: true },
  { id: 2, code: 'RAMADAN20', type: 'percent', value: 20, maxDisc: 150, min: 500, total: 2000, perCust: 2, used: 2000, from: '2026-02-18', to: '2026-03-20', active: false },
  { id: 3, code: 'FREESHIP', type: 'free_ship', value: null, maxDisc: null, min: 600, total: null, perCust: 3, used: 412, from: '2026-10-01', to: '2026-12-31', active: true },
  { id: 4, code: 'VIP10', type: 'percent', value: 10, maxDisc: 300, min: 0, total: null, perCust: null, used: 96, from: '2026-10-10', to: null, active: true },
];

export interface BrandRow {
  id: number;
  ar: string;
  en: string;
  slug: string | null;
  sort: number;
  active: boolean;
  logo: string | null;
  products: number;
}

export const BRANDS: BrandRow[] = [
  { id: 1, ar: 'الشريف', en: 'El Sherif', slug: 'el-sherif', sort: 1, active: true, logo: '#e0491f', products: 48 },
  { id: 2, ar: 'الضحى', en: 'Al Doha', slug: 'al-doha', sort: 2, active: true, logo: '#18151c', products: 31 },
  { id: 3, ar: 'العروسة', en: 'El Arousa', slug: 'el-arousa', sort: 3, active: true, logo: '#0ea5a4', products: 22 },
  { id: 4, ar: 'بن الحرمين', en: 'Haramain Coffee', slug: 'haramain-coffee', sort: 4, active: true, logo: '#92400e', products: 14 },
  { id: 5, ar: 'جهينة', en: 'Juhayna', slug: 'juhayna', sort: 5, active: true, logo: '#2f5bd3', products: 57 },
  { id: 6, ar: 'ريف مصر', en: 'Reef Masr', slug: null, sort: 6, active: false, logo: null, products: 0 },
];

/* ---------- Purchasing Data ---------- */
export interface PurchaseRequest {
  id: number;
  no: string;
  branch: string;
  status: string;
  by: string;
  date: string;
  items: number;
}

export const PRS: PurchaseRequest[] = [
  { id: 1, no: 'PR-0318', branch: 'فرع مدينة نصر', status: 'pending', by: 'كريم فتحي', date: '2026-10-27', items: 6 },
  { id: 2, no: 'PR-0317', branch: 'المخزن الرئيسي', status: 'draft', by: 'أحمد ناصر', date: '2026-10-26', items: 14 },
  { id: 3, no: 'PR-0316', branch: 'فرع المعادي', status: 'approved', by: 'سارة حمدي', date: '2026-10-25', items: 4 },
  { id: 4, no: 'PR-0315', branch: 'فرع المنصورة', status: 'converted_to_po', by: 'يوسف عاطف', date: '2026-10-22', items: 9 },
  { id: 5, no: 'PR-0314', branch: 'فرع الإسكندرية - سموحة', status: 'rejected', by: 'منة الله عادل', date: '2026-10-20', items: 3 },
];

export interface PurchaseOrder {
  id: number;
  no: string;
  sup: number;
  branch: string;
  status: string;
  eta: string | null;
  total: number;
}

export const POS: PurchaseOrder[] = [
  { id: 1, no: 'PO-1207', sup: 1, branch: 'المخزن الرئيسي', status: 'sent', eta: '2026-11-02', total: 148500 },
  { id: 2, no: 'PO-1206', sup: 2, branch: 'فرع مدينة نصر', status: 'revision', eta: '2026-10-30', total: 62400 },
  { id: 3, no: 'PO-1205', sup: 4, branch: 'المخزن الرئيسي', status: 'partial', eta: '2026-10-28', total: 38900 },
  { id: 4, no: 'PO-1204', sup: 6, branch: 'فرع المعادي', status: 'accepted', eta: '2026-10-29', total: 27350 },
  { id: 5, no: 'PO-1203', sup: 1, branch: 'فرع المنصورة', status: 'completed', eta: '2026-10-20', total: 91200 },
  { id: 6, no: 'PO-1202', sup: 3, branch: 'المخزن الرئيسي', status: 'draft', eta: null, total: 14800 },
  { id: 7, no: 'PO-1201', sup: 2, branch: 'فرع المعادي', status: 'cancelled', eta: '2026-10-15', total: 22000 },
];

export interface SupplierInvoice {
  id: number;
  sys: string;
  supNo: string;
  sup: number;
  po: string | null;
  issue: string;
  due: string | null;
  subtotal: number;
  tax: number;
  paid: number;
  status: string;
  file: boolean;
}

export const INVOICES: SupplierInvoice[] = [
  { id: 1, sys: 'SINV-0892', supNo: 'SH-2026-4471', sup: 1, po: 'PO-1203', issue: '2026-10-21', due: '2026-11-20', subtotal: 80000, tax: 11200, paid: 50000, status: 'partial', file: true },
  { id: 2, sys: 'SINV-0891', supNo: 'DM/10/882', sup: 2, po: null, issue: '2026-10-18', due: '2026-12-02', subtotal: 54000, tax: 7560, paid: 0, status: 'posted', file: true },
  { id: 3, sys: 'SINV-0890', supNo: 'HC-3310', sup: 4, po: 'PO-1205', issue: '2026-10-28', due: null, subtotal: 18500, tax: 2590, paid: 0, status: 'draft', file: false },
  { id: 4, sys: 'SINV-0889', supNo: 'GF-77120', sup: 6, po: null, issue: '2026-09-30', due: '2026-10-30', subtotal: 24000, tax: 3360, paid: 27360, status: 'paid', file: true },
  { id: 5, sys: 'SINV-0888', supNo: 'NP-0091', sup: 3, po: null, issue: '2026-09-12', due: '2026-09-27', subtotal: 6200, tax: 0, paid: 0, status: 'cancelled', file: false },
];

export interface SupplierPayment {
  id: number;
  no: string;
  sup: number;
  inv: string | null;
  amount: number;
  method: string;
  ref: string | null;
  date: string;
  notes: string | null;
  void: { reason: string; by: string; at: string } | null;
}

export const SPAYMENTS: SupplierPayment[] = [
  { id: 1, no: 'SPAY-0441', sup: 1, inv: 'SINV-0892', amount: 50000, method: 'bank', ref: 'NBE-TRX-882140', date: '2026-10-24', notes: 'دفعة أولى', void: null },
  { id: 2, no: 'SPAY-0440', sup: 6, inv: 'SINV-0889', amount: 27360, method: 'cheque', ref: 'CHQ-004127', date: '2026-10-22', notes: null, void: null },
  { id: 3, no: 'SPAY-0439', sup: 2, inv: null, amount: 15000, method: 'cash', ref: null, date: '2026-10-19', notes: 'دفعة تحت الحساب', void: null },
  { id: 4, no: 'SPAY-0438', sup: 4, inv: null, amount: 8000, method: 'bank', ref: 'MSR-55120', date: '2026-10-11', notes: null, void: { reason: 'تحويل مكرر', by: 'أحمد ناصر', at: '2026-10-12 10:14' } },
];

export const SP_METHODS: Record<string, string> = { cash: 'نقدي', bank: 'تحويل بنكي', cheque: 'شيك' };
export const supName = (id: number): string => SUPPLIERS.find(s => s.id === id)?.company || SUPPLIERS.find(s => s.id === id)?.name || '';

/* ---------- Finance, Shifts, Wholesale ---------- */
export interface ExpenseRow {
  hasAttachment?: boolean;
  voidedAt?: string | null;
  voidReason?: string | null;
  id: number;
  no: string;
  branch: string;
  tr: number;
  cat: string;
  amount: number;
  date: string;
  by: string;
  shift: string | null;
  notes: string | null;
}

export const EXPENSES: ExpenseRow[] = [
  { id: 1, no: 'EXP-2214', branch: 'فرع مدينة نصر', tr: 2, cat: 'نظافة', amount: 350, date: '2026-10-27', by: 'محمود سعيد', shift: 'SH-5521', notes: 'أدوات تنظيف' },
  { id: 2, no: 'EXP-2213', branch: 'المخزن الرئيسي', tr: 1, cat: 'صيانة', amount: 4800, date: '2026-10-26', by: 'أحمد ناصر', shift: null, notes: 'صيانة ثلاجة العرض' },
  { id: 3, no: 'EXP-2212', branch: 'فرع المعادي', tr: 4, cat: 'نقل وتحميل', amount: 600, date: '2026-10-26', by: 'كريم فتحي', shift: 'SH-5518', notes: null },
  { id: 4, no: 'EXP-2211', branch: 'المخزن الرئيسي', tr: 5, cat: 'إيجار', amount: 45000, date: '2026-10-25', by: 'أحمد ناصر', shift: null, notes: 'إيجار نوفمبر' },
  { id: 5, no: 'EXP-2210', branch: 'فرع مدينة نصر', tr: 3, cat: 'ضيافة', amount: 180, date: '2026-10-24', by: 'سارة حمدي', shift: 'SH-5512', notes: null },
];

export interface TreasuryTransfer {
  id: number;
  from: number;
  to: number;
  amount: number;
  status: string;
  by: string;
  appr: string | null;
  date: string;
  notes: string | null;
}

export const TRANSFERS: TreasuryTransfer[] = [
  { id: 1, from: 2, to: 1, amount: 15000, status: 'pending', by: 'محمود سعيد', appr: null, date: '2026-10-27', notes: 'توريد نقدية نهاية اليوم' },
  { id: 2, from: 1, to: 5, amount: 250000, status: 'pending', by: 'أحمد ناصر', appr: null, date: '2026-10-27', notes: 'إيداع بنكي' },
  { id: 3, from: 4, to: 1, amount: 10500, status: 'approved', by: 'كريم فتحي', appr: 'أحمد ناصر', date: '2026-10-26', notes: null },
  { id: 4, from: 5, to: 6, amount: 120000, status: 'approved', by: 'أحمد ناصر', appr: 'هشام رضا', date: '2026-10-24', notes: null },
  { id: 5, from: 3, to: 1, amount: 8000, status: 'rejected', by: 'سارة حمدي', appr: 'أحمد ناصر', date: '2026-10-23', notes: 'المبلغ غير مطابق للعد' },
];

export interface ShiftRow {
  id: number;
  no: string;
  cashier: string;
  branch: string;
  tr: number;
  open: string;
  close: string | null;
  status: string;
  exp: number | null;
  act: number | null;
}

export const SHIFTS: ShiftRow[] = [
  { id: 1, no: 'SH-5524', cashier: 'محمود سعيد', branch: 'فرع مدينة نصر', tr: 2, open: '2026-10-27 08:00', close: null, status: 'open', exp: null, act: null },
  { id: 2, no: 'SH-5521', cashier: 'منة الله عادل', branch: 'فرع مدينة نصر', tr: 3, open: '2026-10-26 16:00', close: '2026-10-27 00:10', status: 'closed', exp: 21840, act: 21640 },
  { id: 3, no: 'SH-5518', cashier: 'كريم فتحي', branch: 'فرع المعادي', tr: 4, open: '2026-10-26 08:00', close: '2026-10-26 16:05', status: 'closed', exp: 14260.5, act: 14310.5 },
  { id: 4, no: 'SH-5515', cashier: 'سارة حمدي', branch: 'فرع مدينة نصر', tr: 2, open: '2026-10-25 16:00', close: '2026-10-26 00:02', status: 'approved', exp: 18900, act: 18900 },
  { id: 5, no: 'SH-5512', cashier: 'يوسف عاطف', branch: 'فرع المنصورة', tr: 2, open: '2026-10-25 08:00', close: '2026-10-25 16:00', status: 'approved', exp: 9420, act: 9300 },
];

export const trName = (id: number): string => TREASURIES.find(t => t.id === id)?.name || '';

export interface QuoteRow {
  id: number;
  no: string;
  cust: string;
  company: string;
  status: string;
  total: number | null;
  valid: string | null;
  date: string;
  order?: string;
}

export const QUOTES: QuoteRow[] = [
  { id: 1, no: 'QT-0612', cust: 'أحمد حمدي', company: 'شركة النور للتجارة', status: 'requested', total: null, valid: null, date: '2026-10-27' },
  { id: 2, no: 'QT-0611', cust: 'سامح نبيل', company: 'ماركت الصفا', status: 'priced', total: 64200, valid: '2026-11-05', date: '2026-10-26' },
  { id: 3, no: 'QT-0610', cust: 'عادل رمضان', company: 'هايبر الفتح', status: 'accepted', total: 118400, valid: '2026-10-31', date: '2026-10-22', order: 'ORD-10452' },
  { id: 4, no: 'QT-0609', cust: 'ياسر فهمي', company: 'مؤسسة الرحمة للتوزيع', status: 'converted', total: 24800, valid: '2026-10-28', date: '2026-10-18', order: 'ORD-10475' },
  { id: 5, no: 'QT-0608', cust: 'محمد الصاوي', company: 'سوبر ماركت الأمل', status: 'expired', total: 42000, valid: '2026-10-20', date: '2026-10-10' },
];

export interface PaymentNoticeRow {
  id: number;
  no: string;
  cust: string;
  company: string;
  order: string | null;
  amount: number;
  method: string;
  bank: string;
  ref: string;
  date: string;
  file: boolean;
  status: string;
  tr?: number;
  review?: string;
}

export const NOTICES: PaymentNoticeRow[] = [
  { id: 1, no: 'PN-0284', cust: 'محمد الصاوي', company: 'شركة النور للتجارة', order: 'ORD-10475', amount: 24800, method: 'bank', bank: 'البنك الأهلي المصري', ref: 'FT26300118842', date: '2026-10-27', file: true, status: 'pending' },
  { id: 2, no: 'PN-0283', cust: 'ياسر فهمي', company: 'مؤسسة الرحمة للتوزيع', order: null, amount: 100000, method: 'cheque', bank: 'بنك مصر', ref: 'CHQ-883104', date: '2026-10-26', file: true, status: 'pending' },
  { id: 3, no: 'PN-0282', cust: 'عادل رمضان', company: 'هايبر الفتح', order: 'ORD-10452', amount: 71400, method: 'deposit', bank: 'CIB', ref: 'DEP-55021', date: '2026-10-24', file: false, status: 'approved', tr: 5, review: 'تمت المطابقة مع كشف الحساب' },
  { id: 4, no: 'PN-0281', cust: 'سامح نبيل', company: 'ماركت الصفا', order: null, amount: 9000, method: 'bank', bank: 'QNB', ref: 'QNB-1192', date: '2026-10-21', file: true, status: 'rejected', review: 'المبلغ غير ظاهر في كشف الحساب' },
];

export const NOTICE_METHODS: Record<string, string> = { bank: 'تحويل بنكي', cheque: 'شيك', deposit: 'إيداع' };

/* ---------- Notifications ---------- */
export interface NotificationItem {
  id: string;
  type: string;
  data: any;
  read_at: string | null;
  created_at: string;
}

export const NOTIFS: NotificationItem[] = [
  { id: 'n1', type: 'order.created', data: { order_no: 'ORD-10482', total: 1284.5, channel: 'المتجر الإلكتروني', target: { m: 'orders', id: 10482 } }, read_at: null, created_at: 'منذ 4 دقائق' },
  { id: 'n2', type: 'return.requested', data: { return_no: 'RET-2041', order_no: 'ORD-10433', target: { m: 'returns', id: 1 } }, read_at: null, created_at: 'منذ 22 دقيقة' },
  { id: 'n3', type: 'payment_notice.submitted', data: { notice_no: 'PN-0284', company: 'شركة النور للتجارة', amount: 24800, target: { m: 'wholesale', sub: 'notices' } }, read_at: null, created_at: 'منذ ساعة' },
  { id: 'n4', type: 'shift.closed', data: { shift_no: 'SH-5521', cashier: 'منة الله عادل', difference: -200, target: { m: 'shifts' } }, read_at: null, created_at: 'منذ 9 ساعات' },
  { id: 'n5', type: 'purchase_order.revision_requested', data: { po_no: 'PO-1206', supplier: 'مطاحن الدلتا', target: { m: 'purchasing', sub: 'po' } }, read_at: '2026-10-26', created_at: 'أمس 18:40' },
  { id: 'n6', type: 'quotation.requested', data: { quote_no: 'QT-0611', company: 'شركة النور للتجارة', target: { m: 'wholesale', sub: 'quotes' } }, read_at: '2026-10-26', created_at: 'أمس 14:12' },
  { id: 'n7', type: 'stock.low', data: { sku: 'OIL-EV-1000', branch: 'فرع المعادي', qty: 4 }, read_at: '2026-10-25', created_at: '25 أكتوبر' },
  { id: 'n8', type: 'system.message', data: { message: 'تمت جدولة صيانة الخادم يوم الجمعة 2:00 ص' }, read_at: '2026-10-24', created_at: '24 أكتوبر' },
];
```

## File: bags-platform/frontend/apps/backoffice/src/pages/pos/components/returns/ReturnModal.tsx
```typescript
import React, { useState, useEffect, useRef } from 'react';
import ReactDOM from 'react-dom';
import { Icon } from '../ui/Icons';
import { egp, fmt, PImg, Api, Empty, Seg, Check, Search, useEsc } from '../ui/SharedUI';
import { pos as posMockService } from '../../services/pos-data-source';
import type { PastSale, PosProduct } from '../../types/pos.types';

interface ReturnModalProps {
  open: boolean;
  onClose: () => void;
  toast: (msg: string, tone?: 'ok' | 'bad') => void;
}

export function ReturnModal({ open, onClose, toast }: ReturnModalProps) {
  const [num, setNum] = useState<string>('');
  const [sale, setSale] = useState<PastSale | null>(null);
  const [err, setErr] = useState<string>('');
  const [mode, setMode] = useState<'return' | 'exchange'>('return');
  const [sel, setSel] = useState<Record<number, { qty: number; to: 'on_hand' | 'damaged' }>>({});
  const [repl, setRepl] = useState<{ id: number; qty: number }[]>([]);
  const [rq, setRq] = useState<string>('');
  const [busy, setBusy] = useState<boolean>(false);
  const submitting = useRef(false);
  const [allProducts, setAllProducts] = useState<PosProduct[]>([]);
  const [replQuoteTotal, setReplQuoteTotal] = useState<number | null>(null);

  useEffect(() => {
    if (open) {
      setNum('');
      setSale(null);
      setErr('');
      setMode('return');
      setSel({});
      setRepl([]);
      setRq('');
      setBusy(false);
      setReplQuoteTotal(null);
      posMockService.getProducts().then(setAllProducts);
    }
  }, [open]);

  useEffect(() => {
    setReplQuoteTotal(null);
    if (!open || mode !== 'exchange' || !sale || !repl.length) return;
    const controller = new AbortController();
    const items = repl.map((r) => ({ product_variant_id: r.id, qty: r.qty }));
    void posMockService.quoteSale({ items, customer_id: sale.customer_id ?? null }, controller.signal)
      .then((q) => { if (!controller.signal.aborted) setReplQuoteTotal(q.grand_total); })
      .catch((error: Error) => { if (!controller.signal.aborted) toast(error.message, 'bad'); });
    return () => controller.abort();
  }, [open, mode, repl, sale, toast]);

  useEsc(onClose, open);
  if (!open) return null;

  const find = async () => {
    if (!num.trim()) return;
    const s = await posMockService.getPastSale(num);
    if (s) {
      setSale(s);
      setErr('');
    } else {
      setSale(null);
      setErr('لم يتم العثور على فاتورة بهذا الرقم في الفرع');
    }
  };

  const loadExample = async () => {
    const s = await posMockService.getPastSale('POS-NSR-10233');
    if (s) {
      setNum('POS-NSR-10233');
      setSale(s);
      setErr('');
    }
  };

  const retTotal = sale
    ? sale.items.reduce((s, i) => s + (sel[i.id]?.qty || 0) * i.price, 0)
    : 0;

  const fallbackReplTotal = repl.reduce((s, r) => {
    const p = posMockService.getProductById(r.id);
    return s + (p?.price || 0) * r.qty;
  }, 0);
  const replTotal = replQuoteTotal != null ? replQuoteTotal : fallbackReplTotal;

  const net = replTotal - retTotal;
  const anySel = Object.values(sel).some((v) => v.qty > 0);
  const canSubmit = anySel && (mode === 'return' || replQuoteTotal != null) && !busy;

  const results = rq
    ? allProducts
        .filter(
          (p) =>
            p.name.includes(rq) ||
            p.sku.toLowerCase().includes(rq.toLowerCase())
        )
        .slice(0, 4)
    : [];

  const handleSubmit = async () => {
    if (!canSubmit || !sale || submitting.current) return;
    submitting.current = true;
    setBusy(true);
    try {
      const returnPayload = Object.entries(sel)
        .filter(([, v]) => v.qty > 0)
        .map(([id, v]) => ({
          id: Number(id),
          qty: v.qty,
          return_to: v.to,
        }));

      if (mode === 'return') {
        const res = await posMockService.processReturn(sale.id, returnPayload);
        const cardAmt = sale.card_amount ?? 0;
        const cardRefund = (res as any)?.card_refund_amount ?? (cardAmt > 0 ? Math.min(cardAmt, retTotal) : 0);
        if (cardRefund > 0) {
          toast(`تم تسجيل المرتجع. يجب رد ${cardRefund} جنيه على الكارت يدويًا`, 'ok');
        } else {
          toast('تم تسجيل المرتجع وتحديث المخزون', 'ok');
        }
      } else {
        const res = await posMockService.processExchange(sale.id, returnPayload, repl, net, sale.customer_id);
        const cardRefund = (res as any)?.card_refund_amount ?? 0;
        if (cardRefund > 0) {
          toast(`تم الاستبدال. يجب رد ${cardRefund} جنيه على الكارت يدويًا`, 'ok');
        } else {
          toast('تم الاستبدال وإصدار فاتورة جديدة', 'ok');
        }
      }
      onClose();
    } catch (error) {
      toast(error instanceof Error ? error.message : 'تعذر تسجيل العملية', 'bad');
    } finally {
      submitting.current = false;
      setBusy(false);
    }
  };

  return ReactDOM.createPortal(
    <div
      dir="rtl"
      className={document.querySelector('.app')?.classList.contains('theme-dark') ? 'theme-dark' : ''}
    >
      <div className="overlay" onClick={onClose} />
      <div
        className="modal"
        style={{ width: 'min(920px, calc(100vw - 32px))' }}
        data-screen-label="07 Return & Exchange"
        role="dialog"
        aria-modal="true"
      >
        <div className="pop">
          <div
            className="row gap-3"
            style={{ padding: '18px 22px', borderBottom: '1px solid var(--line)' }}
          >
            <div className="grow col">
              <h3 className="fs-16">مرتجع / استبدال</h3>
              <span className="perm">
                <Icon n="shield" s={12} />
                pos.sales.refund
              </span>
            </div>
            <Seg
              value={mode}
              onChange={setMode}
              options={[
                { value: 'return', label: 'إرجاع فقط' },
                { value: 'exchange', label: 'استبدال بصنف آخر' },
              ]}
            />
            <button className="icon-btn sm" onClick={onClose} aria-label="إغلاق">
              <Icon n="x" s={16} />
            </button>
          </div>

          <div
            style={{
              padding: '18px 22px',
              display: 'flex',
              flexDirection: 'column',
              gap: 16,
            }}
          >
            <div className="ret-head">
              <div className="scanbar grow" style={{ height: 48 }}>
                <span className="scan-ic" style={{ width: 30, height: 30 }}>
                  <Icon n="barcode" s={16} w={2} />
                </span>
                <input
                  autoFocus
                  value={num}
                  onChange={(e) => setNum(e.target.value)}
                  onKeyDown={(e) => e.key === 'Enter' && find()}
                  placeholder="امسح باركود الإيصال أو اكتب رقم الفاتورة (order_number)"
                  className="ltr"
                  style={{ textAlign: 'right' }}
                />
              </div>
              <button className="btn btn-primary" style={{ height: 48 }} onClick={find}>
                بحث
              </button>
              {!sale && (
                <button className="btn btn-ghost" style={{ height: 48 }} onClick={loadExample}>
                  مثال
                </button>
              )}
            </div>
            {err && (
              <div className="banner b-bad">
                <Icon n="alert" s={16} />
                {err}
              </div>
            )}

            {!sale ? (
              <Empty
                icon="ret"
                title="ابدأ بمسح إيصال البيع الأصلي"
                body="سيتم عرض بنود الفاتورة لتختار الأصناف والكميات المرتجعة وحالتها."
              />
            ) : (
              <>
                <div className="order-meta">
                  <div>
                    <span>رقم الفاتورة</span>
                    <b className="mono">{sale.number}</b>
                  </div>
                  <div>
                    <span>التاريخ</span>
                    <b>{sale.date}</b>
                  </div>
                  <div>
                    <span>العميل</span>
                    <b>{sale.customer}</b>
                  </div>
                  <div>
                    <span>الدفع / الكاشير</span>
                    <b>
                      {sale.method} · {sale.cashier}
                    </b>
                  </div>
                </div>
                <div className="card" style={{ overflow: 'hidden' }}>
                  <table className="tbl">
                    <thead>
                      <tr>
                        <th style={{ width: 40 }}></th>
                        <th>الصنف</th>
                        <th className="n">السعر</th>
                        <th className="n">المباع</th>
                        <th style={{ textAlign: 'center' }}>كمية الإرجاع</th>
                        <th>حالة الصنف الراجع</th>
                        <th className="n">المسترد</th>
                      </tr>
                    </thead>
                    <tbody>
                      {sale.items.map((i) => {
                        const p = posMockService.getProductById(i.id);
                        const s = sel[i.id] || { qty: 0, to: 'on_hand' };
                        const retMax = i.returnable_qty != null ? i.returnable_qty : i.qty;
                        const fullyReturned = retMax <= 0;
                        const on = !fullyReturned && s.qty > 0;
                        const upd = (v: Partial<{ qty: number; to: 'on_hand' | 'damaged' }>) =>
                          setSel((x) => ({ ...x, [i.id]: { ...s, ...v } }));

                        return (
                          <tr key={i.id} className={on ? 'selected' : ''}>
                            <td>
                              {fullyReturned ? (
                                <span className="badge b-neutral" style={{ fontSize: 10, padding: '2px 4px' }}>
                                  —
                                </span>
                              ) : (
                                <Check
                                  on={on}
                                  onChange={(v) => upd({ qty: v ? 1 : 0 })}
                                />
                              )}
                            </td>
                            <td>
                              <div className="row gap-2">
                                <PImg p={p} size="xs" />
                                <div>
                                  <div className="fs-13 fw-5">{p?.name}</div>
                                  <div className="mono t-3" style={{ fontSize: 11 }}>
                                    {p?.sku}
                                  </div>
                                </div>
                              </div>
                            </td>
                            <td className="n">{egp(i.price)}</td>
                            <td className="n">
                              {fmt(i.qty)}
                              {i.returnable_qty != null && i.returnable_qty < i.qty && (
                                <div className="fs-11 t-3">متبقي {fmt(i.returnable_qty)}</div>
                              )}
                            </td>
                            <td style={{ textAlign: 'center' }}>
                              {fullyReturned ? (
                                <span className="badge b-neutral" style={{ fontSize: 11 }}>
                                  مرتجع بالكامل
                                </span>
                              ) : (
                                <div className="stepper-q" style={{ opacity: on ? 1 : 0.5 }}>
                                  <button
                                    disabled={s.qty >= retMax}
                                    onClick={() => upd({ qty: Math.min(retMax, s.qty + 1) })}
                                  >
                                    <Icon n="plus" s={13} />
                                  </button>
                                  <span>{fmt(s.qty)}</span>
                                  <button
                                    onClick={() => upd({ qty: Math.max(0, s.qty - 1) })}
                                  >
                                    <Icon n="minus" s={13} />
                                  </button>
                                </div>
                              )}
                            </td>
                            <td>
                              <div
                                className="seg"
                                style={{
                                  opacity: on ? 1 : 0.45,
                                  pointerEvents: on ? 'auto' : 'none',
                                }}
                              >
                                <button
                                  aria-pressed={s.to === 'on_hand'}
                                  onClick={() => upd({ to: 'on_hand' })}
                                >
                                  <span className="row gap-1">
                                    <i
                                      style={{
                                        width: 7,
                                        height: 7,
                                        borderRadius: 9,
                                        background: 'var(--ok)',
                                      }}
                                    />
                                    سليم
                                  </span>
                                </button>
                                <button
                                  aria-pressed={s.to === 'damaged'}
                                  onClick={() => upd({ to: 'damaged' })}
                                >
                                  <span className="row gap-1">
                                    <i
                                      style={{
                                        width: 7,
                                        height: 7,
                                        borderRadius: 9,
                                        background: 'var(--bad)',
                                      }}
                                    />
                                    تالف
                                  </span>
                                </button>
                              </div>
                            </td>
                            <td className="n fw-6">{on ? egp(s.qty * i.price) : '—'}</td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>

                {mode === 'exchange' && (
                  <div
                    className="card card-pad col gap-3"
                    style={{ background: 'var(--bg-sunken)' }}
                  >
                    <div className="row between">
                      <span className="section-label">الصنف البديل</span>
                      <span className="fs-12 t-3">
                        ينفَّذ الإرجاع والفاتورة الجديدة في عملية واحدة
                      </span>
                    </div>
                    <div style={{ position: 'relative' }}>
                      <Search
                        value={rq}
                        onChange={setRq}
                        placeholder="ابحث عن الصنف البديل بالاسم أو الكود…"
                      />
                      {results.length > 0 && (
                        <div className="scan-results" style={{ maxHeight: 240 }}>
                          {results.map((p) => (
                            <button
                              key={p.id}
                              className="scan-row"
                              onClick={() => {
                                setRepl((r) =>
                                  r.find((x) => x.id === p.id)
                                    ? r.map((x) =>
                                        x.id === p.id ? { ...x, qty: x.qty + 1 } : x
                                      )
                                    : [...r, { id: p.id, qty: 1 }]
                                );
                                setRq('');
                              }}
                            >
                              <PImg p={p} size="xs" />
                              <span className="grow fs-13">{p.name}</span>
                              <span className="badge b-neutral">رصيد {fmt(p.stock)}</span>
                              <span className="price">{egp(p.price)}</span>
                            </button>
                          ))}
                        </div>
                      )}
                    </div>
                    {repl.map((r) => {
                      const p = posMockService.getProductById(r.id);
                      if (!p) return null;
                      return (
                        <div
                          key={r.id}
                          className="row gap-3"
                          style={{
                            background: 'var(--bg-card)',
                            padding: 10,
                            borderRadius: 12,
                            border: '1px solid var(--line)',
                          }}
                        >
                          <PImg p={p} size="xs" />
                          <span className="grow fs-13 fw-5">{p.name}</span>
                          <div className="stepper-q">
                            <button
                              onClick={() =>
                                setRepl((x) =>
                                  x.map((y) => (y.id === r.id ? { ...y, qty: y.qty + 1 } : y))
                                )
                              }
                            >
                              <Icon n="plus" s={13} />
                            </button>
                            <span>{fmt(r.qty)}</span>
                            <button
                              onClick={() =>
                                setRepl((x) =>
                                  r.qty > 1
                                    ? x.map((y) =>
                                        y.id === r.id ? { ...y, qty: y.qty - 1 } : y
                                      )
                                    : x.filter((y) => y.id !== r.id)
                                )
                              }
                            >
                              <Icon n="minus" s={13} />
                            </button>
                          </div>
                          <span className="price" style={{ width: 80, textAlign: 'end' }}>
                            {egp(p.price * r.qty)}
                          </span>
                        </div>
                      );
                    })}
                    {repl.length === 0 && (
                      <div className="fs-13 t-3">لم يتم اختيار صنف بديل بعد.</div>
                    )}
                  </div>
                )}
              </>
            )}
          </div>

          {sale && (
            <div
              className="row gap-3"
              style={{
                padding: '14px 22px',
                borderTop: '1px solid var(--line)',
                background: 'var(--bg-card)',
              }}
            >
              {mode === 'return' ? (
                <div
                  className="net grow"
                  style={{
                    background: 'var(--bad-bg)',
                    color: 'var(--bad)',
                    padding: '10px 14px',
                  }}
                >
                  <Icon n="cash" s={18} />
                  <span className="fs-13 fw-6">رد نقدية للعميل</span>
                  <span className="ms-auto display fs-20 fw-6 num">{egp(retTotal)} ج</span>
                </div>
              ) : (
                <div
                  className="net grow"
                  style={{
                    background:
                      !anySel || !repl.length
                        ? 'var(--bg-sunken)'
                        : net > 0
                        ? 'var(--ok-bg)'
                        : net < 0
                        ? 'var(--bad-bg)'
                        : 'var(--info-bg)',
                    color:
                      !anySel || !repl.length
                        ? 'var(--text-2)'
                        : net > 0
                        ? 'var(--ok)'
                        : net < 0
                        ? 'var(--bad)'
                        : 'var(--info)',
                    padding: '10px 14px',
                  }}
                >
                  <span className="fs-12 t-2" style={{ color: 'inherit', opacity: 0.8 }}>
                    مرتجع {egp(retTotal)} ← بديل {egp(replTotal)}
                  </span>
                  <span className="fs-13 fw-6">
                    {net > 0
                      ? 'تحصيل فرق من العميل'
                      : net < 0
                      ? 'رد فرق للعميل'
                      : 'بدون فروق'}
                  </span>
                  <span className="ms-auto display fs-20 fw-6 num">{egp(Math.abs(net))} ج</span>
                </div>
              )}
              <Api m="POST" path={`/pos/sales/${sale.id}/${mode}`} />
              <button
                className="btn btn-primary btn-lg"
                disabled={!canSubmit}
                onClick={handleSubmit}
              >
                <Icon n="check" s={16} w={2.4} />
                {busy
                  ? 'جارِ التنفيذ…'
                  : mode === 'return'
                  ? 'تأكيد المرتجع'
                  : 'تأكيد الاستبدال'}
              </button>
            </div>
          )}
        </div>
      </div>
    </div>,
    document.body
  );
}
```

## File: bags-platform/frontend/packages/api-client/src/endpoints/operations/finance.ts
```typescript
import { ADMIN, asPage, crud, one, api, cleanParams, openProtectedFile, downloadFile, type Id } from './http';

export type TreasuryType = 'main_safe' | 'pos_drawer' | 'bank_account';
type Named = { id: number; name: string };
export interface Treasury {
  id: number; code: string; name: string; type: TreasuryType; branch_id: number | null;
  branch?: Named | null; current_balance: string; is_active: boolean;
}
export interface TreasuryPayload { name: string; code: string; type: TreasuryType; branch_id?: number | null; is_active: boolean }
export const treasuriesApi = crud<Treasury, TreasuryPayload>(`${ADMIN}/treasuries`);

export interface Expense {
  id: number; expense_number: string; branch_id: number; branch?: Named; treasury?: Named; creator?: Named;
  shift_id: number | null; amount: string; category: string; notes: string | null;
  treasury_id: number; expense_date: string; attachment_path?: string | null;
  voided_at?: string | null; voided_by?: number | null; void_reason?: string | null;
}
export interface ExpensePayload { branch_id: number; amount: number; category: string; notes?: string | null; treasury_id: number; expense_date: string }
export const expensesApi = {
  ...crud<Expense, ExpensePayload>(`${ADMIN}/expenses`),
  openAttachment: (id: Id) => openProtectedFile(`${ADMIN}/expenses/${id}/attachment`),
  create: (body: ExpensePayload, idempotencyKey: string) =>
    api.post(`${ADMIN}/expenses`, body, { headers: { 'Idempotency-Key': idempotencyKey } }).then((r) => one<Expense>(r)),
  void: (id: Id, void_reason: string) => api.post(`${ADMIN}/expenses/${id}/void`, { void_reason }).then((r) => one<Expense>(r)),
};

export interface TreasuryTransfer {
  id: number; from_treasury_id: number; to_treasury_id: number; amount: string; status: string; notes: string | null;
  from_treasury?: Named; to_treasury?: Named; creator?: Named; approver?: Named; created_at?: string;
}
const TT = `${ADMIN}/treasury-transfers`;
export const treasuryTransfersApi = {
  list: (params?: object, signal?: AbortSignal) => api.get(TT, { params: cleanParams(params), signal }).then((r) => asPage<TreasuryTransfer>(r)),
  show: (id: Id, signal?: AbortSignal) => api.get(`${TT}/${id}`, { signal }).then((r) => one<TreasuryTransfer>(r)),
  create: (body: { from_treasury_id: number; to_treasury_id: number; amount: number; notes?: string | null }, idempotencyKey: string) =>
    api.post(TT, body, { headers: { 'Idempotency-Key': idempotencyKey } }).then((r) => one<TreasuryTransfer>(r)),
  approve: (id: Id, idempotencyKey?: string) =>
    api.post(`${TT}/${id}/approve`, {}, { headers: idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : undefined }).then((r) => one<TreasuryTransfer>(r)),
  reject: (id: Id) => api.post(`${TT}/${id}/reject`).then((r) => one<TreasuryTransfer>(r)),
};

export interface PosShift {
  id: number; shift_number: string; branch_id: number; user_id: number; treasury_id: number;
  cashier?: Named; branch?: Named; treasury?: Named; approver?: Named;
  status: 'open' | 'closed_pending_approval' | 'approved'; opened_at: string; closed_at: string | null;
  opening_cash: string; cash_sales_total: string; card_sales_total: string; cash_returns_total: string; cash_expenses_total: string;
  expected_closing_cash: string | null; actual_closing_cash: string | null; cash_difference: string | null;
  expenses?: Expense[]; notes?: string | null;
}
const SH = `${ADMIN}/shifts`;
export const shiftsApi = {
  list: (params?: object, signal?: AbortSignal) => api.get(SH, { params: cleanParams(params), signal }).then((r) => asPage<PosShift>(r)),
  show: (id: Id, signal?: AbortSignal) => api.get(`${SH}/${id}`, { signal }).then((r) => one<PosShift>(r)),
  approve: (id: Id) => api.post(`${SH}/${id}/approve`).then((r) => one<PosShift>(r)),
};

export interface QuotationItem {
  id: number; product_variant_id: number; qty: number; requested_target_price: string | null;
  offered_unit_price: string | null; line_total: string | null;
  variant?: { id: number; sku: string; product_name: string | null };
}
export interface Quotation {
  id: number; quotation_number: string; customer_id: number; customer?: Named & { company_name: string | null };
  status: 'draft' | 'requested' | 'priced_by_admin' | 'accepted' | 'rejected' | 'expired' | 'converted_to_order';
  valid_until: string | null; subtotal: string; discount_amount: string; tax_amount: string; grand_total: string;
  customer_notes: string | null; admin_notes: string | null; converted_order_id: number | null;
  items?: QuotationItem[]; created_at: string;
}
export interface PriceQuotationPayload {
  items: { id: number; offered_unit_price: number }[]; valid_until?: string | null; admin_notes?: string | null;
  discount_amount?: number; tax_amount?: number;
}
const Q = `${ADMIN}/quotations`;
export const quotationsApi = {
  list: (params?: object, signal?: AbortSignal) => api.get(Q, { params: cleanParams(params), signal }).then((r) => asPage<Quotation>(r)),
  show: (id: Id, signal?: AbortSignal) => api.get(`${Q}/${id}`, { signal }).then((r) => one<Quotation>(r)),
  price: (id: Id, body: PriceQuotationPayload) => api.post(`${Q}/${id}/price`, body).then((r) => one<Quotation>(r)),
  expire: (id: Id) => api.post(`${Q}/${id}/expire`).then((r) => one<Quotation>(r)),
};

export interface PaymentNotice {
  id: number; notice_number: string; customer_id: number; customer?: Named & { company_name: string | null };
  order_id: number | null; order_number?: string | null; amount: string; payment_method: string;
  bank_name: string | null; reference_number: string | null; transfer_date: string | null; has_receipt: boolean;
  status: 'pending_review' | 'approved' | 'rejected'; admin_notes: string | null; treasury_id: number | null; created_at: string;
}
const PN = `${ADMIN}/payment-notices`;
export const paymentNoticesApi = {
  list: (params?: object, signal?: AbortSignal) => api.get(PN, { params: cleanParams(params), signal }).then((r) => asPage<PaymentNotice>(r)),
  show: (id: Id, signal?: AbortSignal) => api.get(`${PN}/${id}`, { signal }).then((r) => one<PaymentNotice>(r)),
  approve: (id: Id, body: { treasury_id?: number | null; admin_notes?: string | null }, idempotencyKey?: string) =>
    api.post(`${PN}/${id}/approve`, body, { headers: idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : undefined }).then((r) => one<PaymentNotice>(r)),
  reject: (id: Id, admin_notes: string) => api.post(`${PN}/${id}/reject`, { admin_notes }).then((r) => one<PaymentNotice>(r)),
  openReceipt: (id: Id) => openProtectedFile(`${PN}/${id}/receipt`),
  downloadReceipt: (id: Id) => downloadFile(`${PN}/${id}/receipt`, `payment-notice-${id}-receipt`),
};
export type { Id };
```

## File: bags-platform/frontend/packages/ui/src/wholesale-portal/finance-screens.jsx
```javascript
import React, { useState, useEffect, useRef } from 'react';
import { useApp } from './context';
import { wholesaleEndpoints, accountEndpoints } from '@alfarid/api-client';
import { useApi } from './data/useApi';
import { DataBoundary } from './data/DataBoundary';
import { apiError, saveBlob, pageMeta, num, calendarDay, today, allPages } from './data/helpers';
import { mapOrder, mapInvoice, mapStatement, mapNotice, mapWalletTx, mapNotif } from './data/mappers';
import { ORDER_STATUS, PAY_METHOD, PAY_STATUS, NOTICE_STATUS, NOTICE_METHOD } from './data/constants';
import { fmt, Money } from './data/format';
import {
  Badge, PageHead, Crumb, Panel, Empty,
  Async, Pager, Field, Modal, Banner, Seg
} from './ui-primitives';

/* Screens 18–21, 23–25: invoices, statement, payment notices, wallet, notifications, account. */

/* ---------- 18. Invoices ---------- */
export const Invoices = () => {
  const { go, toast, account } = useApp();
  const [f, setF] = useState('all');
  const [page, setPage] = useState(1);
  const [open, setOpen] = useState(null);
  const [dl, setDl] = useState(null);
  const [unpaidTotal, setUnpaidTotal] = useState(0);

  const api = useApi((signal) => wholesaleEndpoints
    .invoices({ payment_status: f === 'all' ? undefined : f, page }, signal)
    .then((r) => ({ items: (r.data || []).map(mapInvoice), meta: pageMeta(r.meta) })), [f, page]);

  const list = api.data?.items || [];
  const meta = api.data?.meta || { page: 1, pages: 1, total: 0, per: 15 };
  const detailApi = useApi((signal) => wholesaleEndpoints.order(open.id, signal).then(mapOrder), [open?.id], !!open);
  const openOrder = detailApi.state === 'ready' ? detailApi.data : null;

  useEffect(() => {
    wholesaleEndpoints.outstanding().then((d) => setUnpaidTotal(num(d.outstanding_balance) ?? 0)).catch(() => {});
  }, []);

  const openInvoice = (o) => setOpen(o);

  const pdf = async (o) => {
    setDl(o.id);
    try {
      await saveBlob(wholesaleEndpoints.invoicePdf(o.id), `invoice-${o.number}.pdf`);
    } catch (e) {
      toast(apiError(e).message);
    } finally {
      setDl(null);
    }
  };

  return (
    <>
      <PageHead en="Invoices" ar="رقم الفاتورة هو نفس رقم الطلب" />
      <section className="panel">
        <div className="toolbar"><Seg value={f} onChange={(v) => { setF(v); setPage(1); }} options={[{ value: 'all', label: 'الكل' }, { value: 'unpaid', label: 'غير مدفوع' }, { value: 'paid', label: 'مدفوع' }]} />
          <span className="soft small">غير مدفوع: <Money v={unpaidTotal} strong /></span></div>
        <DataBoundary api={api}>
          <Async rows={5} cols={7} isEmpty={!list.length} empty={<Empty icon="fa-file-invoice" title="لا توجد فواتير" />}>
            <div className="tbl-wrap"><table className="tbl">
              <thead><tr><th>رقم الفاتورة</th><th>التاريخ</th><th>حالة الطلب</th><th>الدفع</th><th>السداد</th><th>قبل الخصم</th><th>الخصم</th><th>الشحن</th><th>الضريبة</th><th className="t-end">الإجمالي</th><th></th></tr></thead>
              <tbody>{list.map((o) => (
                <tr key={o.id} className="is-click" onClick={() => openInvoice(o)}>
                  <td className="strong num">{o.number}</td><td className="num">{o.date}</td><td><Badge map={ORDER_STATUS} k={o.status} /></td><td>{PAY_METHOD[o.method]}</td><td><Badge map={PAY_STATUS} k={o.pay} /></td>
                  <td className="num">{fmt(o.subtotal)}</td><td className="num" style={{ color: o.discount ? 'var(--teal)' : null }}>{o.discount ? fmt(o.discount) : '—'}</td><td className="num">{fmt(o.shipping)}</td><td className="num">{fmt(o.tax)}</td>
                  <td className="t-end strong num">{fmt(o.total)}</td>
                  <td onClick={(e) => e.stopPropagation()}><div className="row-actions"><button className="btn-pill sm" onClick={() => pdf(o)} disabled={dl === o.id}>{dl === o.id ? <span className="spin" /> : <i className="fa-regular fa-file-pdf" />} PDF</button></div></td>
                </tr>))}</tbody>
            </table></div>
            <Pager page={meta.page} pages={meta.pages} total={meta.total} per={meta.per} onPage={setPage} />
          </Async>
        </DataBoundary>
      </section>
      {open && (
        <Modal wide title={<>فاتورة <span className="num">{open.number}</span></>} onClose={() => setOpen(null)}
          foot={<><button className="btn-pill" onClick={() => go('order', open.id)}>فتح الطلب المرتبط</button><button className="btn-pill is-dark" disabled={dl === open.id} onClick={() => pdf(open)}>{dl === open.id ? <span className="spin" /> : <i className="fa-regular fa-file-pdf" />} تنزيل PDF</button></>}>
          <div className="inv-head">
            <div><div className="wp-logo" style={{ fontSize: 20 }}>SOPHIE</div><div className="soft small">فاتورة ضريبية مبسطة</div></div>
            <dl className="kv" style={{ gridTemplateColumns: '90px auto' }}><dt>التاريخ</dt><dd className="num">{open.date}</dd><dt>العميل</dt><dd>{account?.company}</dd><dt>السداد</dt><dd><Badge map={PAY_STATUS} k={open.pay} /></dd></dl>
          </div>
          <DataBoundary api={detailApi}><Async rows={3} cols={4}>
          <table className="tbl mt-16"><thead><tr><th>المنتج</th><th>الكمية</th><th>السعر</th><th className="t-end">الإجمالي</th></tr></thead>
            <tbody>{(openOrder?.items || []).map((it) => <tr key={it.id || it.sku}><td><div className="strong">{it.name}</div><div className="sku">{it.sku}</div></td><td className="num">{it.qty}</td><td className="num">{fmt(it.unit)}</td><td className="t-end num strong">{fmt(it.line_total)}</td></tr>)}</tbody></table>
          </Async></DataBoundary>
          <div style={{ maxWidth: 320, marginInlineStart: 'auto' }} className="mt-16">
            <div className="sum-row"><span>المنتجات قبل الخصم</span><strong className="num">{fmt(openOrder?.subtotal ?? open.subtotal)}</strong></div>
            <div className="sum-row is-neg"><span>الخصم</span><strong className="num">{(openOrder?.discount ?? open.discount) ? '− ' + fmt(openOrder?.discount ?? open.discount) : '—'}</strong></div>
            <div className="sum-row"><span>الشحن</span><strong className="num">{fmt(openOrder?.shipping ?? open.shipping)}</strong></div>
            <div className="sum-row"><span>الضريبة</span><strong className="num">{fmt(openOrder?.tax ?? open.tax)}</strong></div>
            <div className="sum-total"><span>الإجمالي</span><strong className="num">{fmt(openOrder?.total ?? open.total)}</strong></div>
          </div>
        </Modal>
      )}
    </>
  );
};

/* ---------- 19. Statement ---------- */
const firstOfMonth = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`; };

export const Statement = () => {
  const { go, account } = useApp();
  const [from, setFrom] = useState(firstOfMonth());
  const [to, setTo] = useState(today());
  const [chip, setChip] = useState(0);
  const bad = from > to;

  const api = useApi((signal) => bad
    ? Promise.resolve(null)
    : wholesaleEndpoints.statement({ from, to }, signal).then(mapStatement), [from, to, bad]);

  const entries = api.data?.entries || [];

  const outApi = useApi((signal) => wholesaleEndpoints
    .invoices({ payment_status: 'unpaid', page: 1 }, signal)
    .then((r) => (r.data || []).map(mapInvoice)), []);
  const out = outApi.data || [];
  const debtApi = useApi(() => wholesaleEndpoints.outstanding().then((d) => num(d.outstanding_balance) ?? 0), []);
  const debt = debtApi.data ?? 0;

  const setRange = (i) => {
    setChip(i);
    const now = new Date();
    if (i === 0) { setFrom(firstOfMonth()); setTo(today()); }
    else if (i === 1) { const d = new Date(now.getFullYear(), now.getMonth() - 2, 1); setFrom(calendarDay(d)); setTo(today()); }
    else { setFrom(`${now.getFullYear()}-01-01`); setTo(today()); }
  };

  return (
    <>
      <PageHead en="Statement" ar="كشف الحساب والمديونية">
        <button className="btn-pill is-dark" onClick={() => go('noticeNew')}><i className="fa-solid fa-money-check-dollar" /> تسجيل إشعار سداد</button>
      </PageHead>
      <div className="kpis" style={{ gridTemplateColumns: 'repeat(4, minmax(0,1fr))' }}>
        <div className="kpi is-ink"><span className="kpi-en">CURRENT DEBT</span><span className="kpi-label">المديونية الحالية</span><span className="kpi-value">{fmt(debt)}<small>EGP</small></span></div>
        <div className="kpi"><span className="kpi-en">CREDIT LIMIT</span><span className="kpi-label">حد الائتمان</span><span className="kpi-value">{fmt(account?.credit_limit ?? 0)}<small>EGP</small></span></div>
        <div className="kpi"><span className="kpi-en">OPENING</span><span className="kpi-label">رصيد أول المدة</span><span className="kpi-value">{fmt(api.data?.opening_balance)}<small>EGP</small></span></div>
        <div className="kpi"><span className="kpi-en">CLOSING</span><span className="kpi-label">رصيد آخر المدة</span><span className="kpi-value">{fmt(api.data?.closing_balance)}<small>EGP</small></span></div>
      </div>
      <div className="grid-2" style={{ gridTemplateColumns: '1fr 320px' }}>
        <section className="panel">
          <div className="toolbar">
            <div className="row" style={{ gap: 20 }}>
              <Field label="من تاريخ"><input type="date" className={`line-input ltr${bad ? ' is-invalid' : ''}`} value={from} onChange={(e) => setFrom(e.target.value)} /></Field>
              <Field label="إلى تاريخ"><input type="date" className={`line-input ltr${bad ? ' is-invalid' : ''}`} value={to} onChange={(e) => setTo(e.target.value)} /></Field>
            </div>
            <div className="chips">{['هذا الشهر', 'آخر 3 شهور', 'هذه السنة'].map((l, i) => <button key={l} className={`chip${chip === i ? ' is-on' : ''}`} onClick={() => setRange(i)}>{l}</button>)}</div>
          </div>
          {bad ? <div style={{ padding: 24 }}><Banner kind="error">تاريخ البداية لازم يكون قبل تاريخ النهاية.</Banner></div> : (
            <DataBoundary api={api}>
              <Async rows={8} cols={6} isEmpty={!entries.length} empty={<Empty icon="fa-receipt" title="لا توجد حركات في هذه الفترة" desc="جرب فترة زمنية أخرى." />}>
                <div className="tbl-wrap"><table className="tbl">
                  <thead><tr><th>التاريخ</th><th>المرجع</th><th>البيان</th><th className="t-end">مدين</th><th className="t-end">دائن</th><th className="t-end">الرصيد</th></tr></thead>
                  <tbody>{entries.map((r, i) => (
                    <tr key={i}>
                      <td className="num">{r.date}</td><td className="sku">{r.ref}</td><td>{r.desc}</td>
                      <td className="t-end num">{r.debit ? fmt(r.debit) : ''}</td><td className="t-end num" style={{ color: 'var(--teal)' }}>{r.credit ? fmt(r.credit) : ''}</td>
                      <td className="t-end num strong">{fmt(r.bal)}</td></tr>))}</tbody>
                  <tfoot><tr><td colSpan="3">رصيد آخر المدة</td><td className="t-end num">{fmt(entries.reduce((a, r) => a + (r.debit || 0), 0))}</td><td className="t-end num">{fmt(entries.reduce((a, r) => a + (r.credit || 0), 0))}</td><td className="t-end num">{fmt(api.data?.closing_balance)}</td></tr></tfoot>
                </table></div>
              </Async>
            </DataBoundary>
          )}
        </section>
        <aside className="stack" style={{ position: 'sticky', top: 100 }}>
          <Panel title="مستحقات مفتوحة" en="OUTSTANDING" body={false}>
            <DataBoundary api={outApi}><Async rows={3} cols={2} isEmpty={!out.length} empty={<Empty title="لا توجد مستحقات مفتوحة" />}>
            {out.map((o) => <div key={o.id} className="out-row" onClick={() => go('order', o.id)}><div><b className="num">{o.number}</b><span className="num">{o.date}</span></div><span className="num strong">{fmt(o.total)}</span></div>)}
            <div className="panel-body" style={{ paddingTop: 12 }}><div className="sum-total" style={{ paddingTop: 0 }}><span>الإجمالي</span><span className="num" style={{ fontSize: 18 }}>{fmt(out.reduce((a, o) => a + (o.total || 0), 0))}</span></div></div>
            </Async></DataBoundary>
          </Panel>
          <p className="note"><i className="fa-solid fa-circle-info" />المديونية والائتمان منفصلين تمامًا عن رصيد المحفظة.</p>
        </aside>
      </div>
    </>
  );
};

/* ---------- 20. New payment notice ---------- */
const NOTICE_FIELD_MAP = { bank_name: 'bank', reference_number: 'ref', transfer_date: 'date', receipt: 'file', order_id: 'order', payment_method: 'method' };

export const NoticeForm = () => {
  const { go, toast, route } = useApp();
  const preselectId = route.id;
  const [f, setF] = useState({ order: '', amount: '', method: 'bank_transfer', bank: '', ref: '', date: today() });
  const [file, setFile] = useState(null);
  const [errs, setErrs] = useState({});
  const [stage, setStage] = useState('form'); // form | confirm | sending | done
  const [done, setDone] = useState(null);
  const inputRef = useRef(null);

  const ordersApi = useApi((signal) => allPages((page, signal) => wholesaleEndpoints.invoices({ payment_status: 'unpaid', page }, signal), signal)
    .then((rows) => rows.map(mapInvoice)), []);
  const orders = ordersApi.data || [];
  useEffect(() => {
    const pre = preselectId ? orders.find((x) => x.id === preselectId) : null;
    if (pre) setF((s) => ({ ...s, order: String(pre.id), amount: String(pre.total ?? '') }));
  }, [preselectId, ordersApi.data]);

  const set = (k, v) => { setF({ ...f, [k]: v }); setErrs({ ...errs, [k]: null }); };

  const pickOrder = (orderId) => {
    const o = orders.find((x) => String(x.id) === orderId);
    setF({ ...f, order: orderId, amount: o ? String(o.total ?? '') : f.amount });
  };

  const validate = () => {
    const e = {};
    if (!f.amount || Number(f.amount) <= 0) e.amount = 'أدخل مبلغ صحيح أكبر من صفر.';
    if (!f.bank.trim()) e.bank = 'اسم البنك مطلوب.';
    if (!f.ref.trim()) e.ref = 'رقم المرجع مطلوب.';
    if (!f.date) e.date = 'تاريخ التحويل مطلوب.';
    else if (f.date > today()) e.date = 'تاريخ التحويل لا يمكن أن يكون في المستقبل.';
    if (!file) e.file = 'إيصال التحويل مطلوب.';
    else if (file.size > 10 * 1024 * 1024) e.file = 'حجم الملف أكبر من 10 ميجابايت.';
    else if (!['application/pdf', 'image/jpeg', 'image/png', 'image/webp'].includes(file.type)) e.file = 'النوع غير مدعوم (pdf/jpg/png/webp).';
    setErrs(e);
    return !Object.keys(e).length;
  };

  const send = async () => {
    setStage('sending');
    const fd = new FormData();
    if (f.order) fd.append('order_id', f.order);
    fd.append('amount', f.amount);
    fd.append('payment_method', f.method);
    fd.append('bank_name', f.bank);
    fd.append('reference_number', f.ref);
    fd.append('transfer_date', f.date);
    fd.append('receipt', file);
    try {
      const n = await wholesaleEndpoints.createNotice(fd);
      setDone(n);
      setStage('done');
    } catch (e) {
      const info = apiError(e);
      const mapped = {};
      Object.entries(info.fields || {}).forEach(([k, msgs]) => {
        mapped[NOTICE_FIELD_MAP[k] || k] = Array.isArray(msgs) ? msgs[0] : String(msgs);
      });
      setErrs(mapped);
      if (!Object.keys(mapped).length) toast(info.message);
      setStage('form');
    }
  };

  if (stage === 'done' && done) return (
    <div className="success-wrap"><div className="order-card">
      <div className="modal-icon" style={{ width: 64, height: 64, fontSize: 26 }}><i className="fa-solid fa-paper-plane" /></div>
      <h1 className="success-title">NOTICE SENT</h1>
      <p className="muted">تم إرسال إشعار السداد للمراجعة</p>
      <p className="success-num num">{done.notice_number}</p>
      <Banner kind="warn" icon="fa-hourglass-half">رفع الإيصال <b>لا يعني اعتماد السداد</b>. الإدارة هتراجع التحويل وتعتمده، وهيوصلك إشعار.</Banner>
      <div className="row mt-24" style={{ justifyContent: 'center' }}><button className="btn-pill is-dark" onClick={() => go('notice', done.id)}>عرض الإشعار</button><button className="btn-pill" onClick={() => go('notices')}>كل الإشعارات</button></div>
    </div></div>
  );

  return (
    <>
      <PageHead en="Payment Notice" crumb={<Crumb items={[{ label: 'إشعارات السداد', go: () => go('notices') }, { label: 'إشعار جديد' }]} />} ar="سجّل تحويل أو شيك أو إيداع تم لحساب الشركة" />
      <div className="grid-2" style={{ gridTemplateColumns: '1fr 340px' }}>
        <section className="panel"><div className="panel-body">
          <div className="fields">
            <Field label="طلب مرتبط" opt span error={errs.order}>
              <DataBoundary api={ordersApi}><Async rows={1} cols={1}>
              <select className="line-input" value={f.order} onChange={(e) => pickOrder(e.target.value)}>
                <option value="">بدون — سداد على الحساب</option>{orders.map((o) => <option key={o.id} value={o.id}>{o.number} · {fmt(o.total)} EGP</option>)}
              </select></Async></DataBoundary></Field>
            <Field label="المبلغ" req error={errs.amount}><div style={{ position: 'relative' }}><input className={`line-input ltr${errs.amount ? ' is-invalid' : ''}`} style={{ textAlign: 'right', paddingLeft: 40 }} inputMode="decimal" placeholder="0.00" value={f.amount} onChange={(e) => set('amount', e.target.value.replace(/[^\d.]/g, ''))} /><span className="soft small" style={{ position: 'absolute', left: 0, top: 12 }}>EGP</span></div></Field>
            <Field label="تاريخ التحويل" req error={errs.date}><input type="date" max={today()} className={`line-input ltr${errs.date ? ' is-invalid' : ''}`} value={f.date} onChange={(e) => set('date', e.target.value)} /></Field>
            <Field label="طريقة السداد" req span error={errs.method}>
              <div className="chips">{Object.entries(NOTICE_METHOD).map(([k, l]) => <button key={k} type="button" className={`chip${f.method === k ? ' is-on' : ''}`} onClick={() => set('method', k)}><i className={`fa-solid ${{ bank_transfer: 'fa-building-columns', check: 'fa-money-check', deposit: 'fa-piggy-bank' }[k]}`} />{l}</button>)}</div></Field>
            <Field label="اسم البنك" req error={errs.bank}><input className={`line-input${errs.bank ? ' is-invalid' : ''}`} placeholder="مثال: البنك الأهلي المصري" value={f.bank} onChange={(e) => set('bank', e.target.value)} /></Field>
            <Field label={f.method === 'check' ? 'رقم الشيك' : 'رقم المرجع'} req error={errs.ref}><input className={`line-input ltr${errs.ref ? ' is-invalid' : ''}`} style={{ textAlign: 'right' }} value={f.ref} onChange={(e) => set('ref', e.target.value)} /></Field>
            <Field label="الإيصال" req span error={errs.file}>
              <input ref={inputRef} type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" style={{ display: 'none' }} onChange={(e) => { setFile(e.target.files?.[0] || null); setErrs({ ...errs, file: null }); }} />
              {file ? (
                <div className="file-row"><i className="fa-regular fa-file-pdf" /><div className="grow"><b className="num">{file.name}</b></div><button className="btn-link muted" onClick={() => setFile(null)}><i className="fa-solid fa-xmark" /></button></div>
              ) : (
                <div className={`drop${errs.file ? ' is-invalid' : ''}`} style={{ minHeight: 120 }} onClick={() => inputRef.current?.click()}>
                  <i className="fa-solid fa-receipt" /><b>ارفع صورة أو PDF للإيصال</b><small>PDF · JPG · JPEG · PNG · WebP — حتى <span className="num">10</span> ميجابايت</small></div>
              )}
            </Field>
          </div>
        </div></section>
        <aside className="stack" style={{ position: 'sticky', top: 100 }}>
          <Panel title="مراجعة">
            <div className="sum-row"><span>الطلب</span><strong className="num">{f.order ? orders.find((o) => String(o.id) === f.order)?.number : 'على الحساب'}</strong></div>
            <div className="sum-row"><span>الطريقة</span><strong>{NOTICE_METHOD[f.method]}</strong></div>
            <div className="sum-total"><span>المبلغ</span><span className="num">{f.amount ? fmt(f.amount) : '—'}</span></div>
            <button className="btn-block mt-24" onClick={() => validate() && setStage('confirm')}>إرسال للمراجعة</button>
          </Panel>
          <p className="note"><i className="fa-solid fa-circle-info" />السداد مش بيتعتمد تلقائيًا؛ الإدارة بتراجع الإيصال مع كشف البنك.</p>
        </aside>
      </div>
      {(stage === 'confirm' || stage === 'sending') && <Modal title="تأكيد إرسال إشعار السداد" onClose={() => setStage('form')} icon={<div className="modal-icon"><i className="fa-solid fa-money-check-dollar" /></div>}
        foot={<><button className="btn-pill" onClick={() => setStage('form')}>تعديل</button><button className="btn-pill is-dark" disabled={stage === 'sending'} onClick={send}>{stage === 'sending' ? <span className="spin" /> : null} تأكيد الإرسال</button></>}>
        <dl className="kv"><dt>المبلغ</dt><dd><Money v={f.amount} strong /></dd><dt>الطريقة</dt><dd>{NOTICE_METHOD[f.method]}</dd><dt>البنك</dt><dd>{f.bank}</dd><dt>المرجع</dt><dd className="sku">{f.ref}</dd><dt>التاريخ</dt><dd className="num">{f.date}</dd><dt>الإيصال</dt><dd className="num">{file?.name}</dd></dl>
      </Modal>}
    </>
  );
};

/* ---------- 21. Notices list + detail ---------- */
export const Notices = () => {
  const { go } = useApp();
  const [f, setF] = useState('all');
  const [page, setPage] = useState(1);

  const api = useApi((signal) => wholesaleEndpoints
    .notices({ status: f === 'all' ? undefined : 'pending_review', page }, signal)
    .then((r) => ({ items: (r.data || []).map(mapNotice), meta: pageMeta(r.meta) })), [f, page]);

  const list = api.data?.items || [];
  const meta = api.data?.meta || { page: 1, pages: 1, total: 0, per: 15 };

  return (
    <>
      <PageHead en="Payment Notices" ar="إشعارات السداد المرسلة وحالة مراجعتها"><button className="btn-pill is-dark" onClick={() => go('noticeNew')}><i className="fa-solid fa-plus" /> إشعار جديد</button></PageHead>
      <section className="panel">
        <div className="toolbar"><Seg value={f} onChange={(v) => { setF(v); setPage(1); }} options={[{ value: 'all', label: 'الكل' }, { value: 'pending', label: 'قيد المراجعة' }]} /></div>
        <DataBoundary api={api}>
          <Async rows={5} cols={6} isEmpty={!list.length} empty={<Empty icon="fa-money-check" title="لا توجد إشعارات سداد" action={<button className="btn-pill is-dark" onClick={() => go('noticeNew')}>تسجيل إشعار</button>} />}>
            <div className="tbl-wrap"><table className="tbl">
              <thead><tr><th>رقم الإشعار</th><th>الطلب</th><th>الطريقة</th><th>تاريخ التحويل</th><th>الحالة</th><th>تاريخ الإنشاء</th><th className="t-end">المبلغ</th></tr></thead>
              <tbody>{list.map((n) => (
                <tr key={n.id} className="is-click" onClick={() => go('notice', n.id)}>
                  <td className="strong num">{n.number}</td><td className="num">{n.order || <span className="soft">على الحساب</span>}</td><td>{NOTICE_METHOD[n.method]}</td><td className="num">{n.date}</td>
                  <td><Badge map={NOTICE_STATUS} k={n.status} /></td><td className="num">{n.created}</td><td className="t-end strong num">{fmt(n.amount)}</td></tr>))}</tbody>
            </table></div>
            <Pager page={meta.page} pages={meta.pages} total={meta.total} per={meta.per} onPage={setPage} />
          </Async>
        </DataBoundary>
      </section>
    </>
  );
};

export const NoticeDetail = ({ id }) => {
  const { go, toast } = useApp();
  const [dl, setDl] = useState(false);
  const api = useApi((signal) => wholesaleEndpoints.notice(id, signal).then(mapNotice), [id]);
  const n = api.data;

  const receipt = async () => {
    setDl(true);
    try {
      await saveBlob(wholesaleEndpoints.noticeReceipt(id), 'receipt');
    } catch (e) {
      toast(apiError(e).message);
    } finally {
      setDl(false);
    }
  };

  return (
    <>
      <PageHead en={n?.number || ''} crumb={<Crumb items={[{ label: 'إشعارات السداد', go: () => go('notices') }, { label: n?.number || '', mono: true }]} />} ar={n ? <>أُرسل في <span className="num">{n.created}</span></> : null}>
        {n && <Badge map={NOTICE_STATUS} k={n.status} lg />}
      </PageHead>
      <DataBoundary api={api}>
        <Async rows={4} cols={4} empty={<Empty icon="fa-money-check" title="الإشعار غير موجود" />}>
          {n && (
          <>
            {n.status === 'rejected' && <div className="mb-24"><Banner kind="error" action={<button className="btn-pill sm" onClick={() => go('noticeNew')}>إرسال إشعار جديد</button>}>تم رفض الإشعار. راجع ملاحظة الإدارة وأرسل إشعار جديد بالبيانات الصحيحة.</Banner></div>}
            {n.status === 'pending' && <div className="mb-24"><Banner kind="warn" icon="fa-hourglass-half">الإشعار قيد المراجعة من الإدارة. المبلغ لن يُخصم من المديونية قبل الاعتماد.</Banner></div>}
            <div className="grid-2e">
              <Panel title="بيانات السداد" en="DETAILS">
                <dl className="kv"><dt>المبلغ</dt><dd><Money v={n.amount} strong /></dd><dt>الطريقة</dt><dd>{NOTICE_METHOD[n.method]}</dd><dt>اسم البنك</dt><dd>{n.bank}</dd><dt>رقم المرجع</dt><dd className="sku">{n.ref}</dd><dt>تاريخ التحويل</dt><dd className="num">{n.date}</dd>
                  <dt>الطلب المرتبط</dt><dd>{n.order_id ? <button className="btn-link num" onClick={() => go('order', n.order_id)}>{n.order}</button> : <span className="soft">على الحساب</span>}</dd></dl>
              </Panel>
              <div className="stack">
                <Panel title="الإيصال" en="RECEIPT">
                  {n.receipt ? <div className="file-row"><i className="fa-regular fa-file-image" /><div className="grow num">الإيصال المرفق</div><button className="btn-pill sm" disabled={dl} onClick={receipt}>{dl ? <span className="spin" /> : <i className="fa-solid fa-download" />} تنزيل</button></div> : <p className="muted">لا يوجد إيصال مرفق.</p>}
                </Panel>
                <Panel title="ملاحظات الإدارة" en="ADMIN NOTE">
                  <div className="note-bubble is-admin" style={{ margin: 0 }}><p>{n.admin_note || <span className="soft">لا توجد ملاحظات</span>}</p></div>
                </Panel>
              </div>
            </div>
          </>
          )}
        </Async>
      </DataBoundary>
    </>
  );
};

/* ---------- 23. Wallet ---------- */
export const Wallet = () => {
  const [page, setPage] = useState(1);
  const { go } = useApp();
  const [direction, setDirection] = useState('all');
  const [days, setDays] = useState('all');
  const fromDate = days === 'all' ? undefined : (() => { const d = new Date(); d.setDate(d.getDate() - Number(days)); return d.toISOString().slice(0, 10); })();
  const api = useApi(() => accountEndpoints.wallet(page, {
    ...(direction === 'all' ? {} : { direction }),
    ...(fromDate ? { from: fromDate } : {}),
  }), [page, direction, days]);
  const balance = num(api.data?.balance) ?? 0;
  const txs = (api.data?.transactions?.data || []).map(mapWalletTx);
  const tMeta = api.data?.transactions ? pageMeta(api.data.transactions) : { page: 1, pages: 1, total: txs.length, per: 20 };

  return (
    <>
      <PageHead en="Wallet" ar="رصيد المحفظة وحركاتها — عرض فقط" />
      <div className="wallet-hero">
        <div><span className="kpi-en" style={{ color: '#a3a3a3' }}>CURRENT BALANCE</span><div className="wallet-amt num">{fmt(balance)} <small>EGP</small></div><span style={{ color: '#a3a3a3', fontSize: 13 }}>الرصيد الحالي</span></div>
        <p className="note" style={{ color: '#a3a3a3', maxWidth: 300 }}><i className="fa-solid fa-circle-info" />الرصيد بيتضاف من المرتجعات والتسويات، ويُستخدم كطريقة دفع عند إتمام الطلب. منفصل عن الائتمان والمديونية.</p>
      </div>
      <section className="panel mt-24">
        <div className="panel-head" style={{ paddingBottom: 6 }}><h2 className="panel-title">الحركات</h2><span className="panel-en">TRANSACTIONS</span></div>
        <div className="toolbar">
          <Seg value={direction} onChange={(v) => { setDirection(v); setPage(1); }} options={[{ value: 'all', label: 'الكل' }, { value: 'credit', label: 'إضافة' }, { value: 'debit', label: 'خصم' }]} />
          <Seg value={days} onChange={(v) => { setDays(v); setPage(1); }} options={[{ value: 'all', label: 'كل الفترات' }, { value: '7', label: 'آخر 7 أيام' }, { value: '30', label: 'آخر 30 يوم' }]} />
        </div>
        <DataBoundary api={api}>
          <Async rows={4} cols={5} isEmpty={!txs.length} empty={<Empty icon="fa-wallet" title="مفيش حركات على المحفظة" />}>
            <div className="tbl-wrap"><table className="tbl">
              <thead><tr><th>النوع</th><th>المرجع</th><th>التاريخ</th><th className="t-end">المبلغ</th><th className="t-end">الرصيد بعد الحركة</th></tr></thead>
              <tbody>{txs.map((t) => (
                <tr key={t.id}><td><div className="row" style={{ gap: 10 }}><span className={`wtx-ic${t.amount < 0 ? ' neg' : ''}`}><i className={`fa-solid ${t.amount < 0 ? 'fa-arrow-up' : 'fa-arrow-down'}`} /></span><span className="strong">{t.label}</span></div></td>
                  <td className="sku">{t.refLink ? <button type="button" className="btn-link num" onClick={() => go(t.refLink[0], t.refLink[1])}>{t.ref}</button> : t.ref}</td><td className="num">{t.date}</td>
                  <td className="t-end num strong" style={{ color: t.amount < 0 ? '#b91c1c' : 'var(--teal)' }}>{t.amount > 0 ? '+' : '−'}{fmt(Math.abs(t.amount ?? 0))}</td>
                  <td className="t-end num">{fmt(t.after)}</td></tr>))}</tbody>
            </table></div>
            <Pager page={tMeta.page} pages={tMeta.pages} total={tMeta.total} per={tMeta.per} onPage={setPage} />
          </Async>
        </DataBoundary>
      </section>
    </>
  );
};

/* ---------- 24. Notifications ---------- */
export const Notifications = () => {
  const { go, unread, setUnread, toast } = useApp();
  const [f, setF] = useState('all');
  const [page, setPage] = useState(1);

  const api = useApi((signal) => wholesaleEndpoints.notifications(page, signal)
    .then((r) => ({ items: (r.data || []).map(mapNotif), meta: r.meta })), [page]);

  const all = api.data?.items || [];
  const unreadCount = api.data?.meta?.unread_count ?? unread;
  const list = f === 'all' ? all : all.filter((n) => n.unread);

  const read = async (n) => {
    try {
      const r = await wholesaleEndpoints.readNotification(n.id);
      setUnread(r.meta.unread_count);
      api.setData((d) => d && ({ ...d, meta: { ...d.meta, unread_count: r.meta.unread_count }, items: d.items.map((x) => (x.id === n.id ? { ...x, unread: false } : x)) }));
      return true;
    } catch (e) {
      toast(apiError(e).message);
    }
  };

  const open = async (n) => {
    if (await read(n) && n.link) go(n.link[0], n.link[1]);
  };

  const readAll = async () => {
    try {
      const r = await wholesaleEndpoints.readAllNotifications();
      setUnread(r.meta.unread_count);
      api.setData((d) => d && ({ ...d, meta: { ...d.meta, unread_count: r.meta.unread_count }, items: d.items.map((x) => ({ ...x, unread: false })) }));
    } catch (e) {
      toast(apiError(e).message);
    }
  };

  return (
    <>
      <PageHead en="Notifications" ar={<><span className="num">{unreadCount}</span> غير مقروء</>}>
        <button className="btn-pill" onClick={readAll}>تحديد الكل كمقروء</button>
      </PageHead>
      <section className="panel">
        <div className="toolbar"><Seg value={f} onChange={setF} options={[{ value: 'all', label: 'الكل', n: all.length }, { value: 'unread', label: 'غير مقروء', n: all.filter((n) => n.unread).length }]} /></div>
        <DataBoundary api={api}>
          <Async rows={5} cols={3} isEmpty={!list.length} empty={<Empty icon="fa-bell-slash" title="مفيش إشعارات غير مقروءة" desc="كل الإشعارات اتقرت." />}>
            {list.map((n) => (
              <div key={n.id} className={`notif${n.unread ? ' is-unread' : ''}`}>
                <span className="notif-ic"><i className={`fa-solid ${n.icon}`} /></span>
                <div>
                  <p className="notif-title">{n.title}</p><p className="notif-body">{n.body}</p>
                  <div className="row mt-8" style={{ gap: 16 }}>
                    {n.link && <button className="btn-link" onClick={() => open(n)}>فتح</button>}
                    {n.unread && <button className="btn-link muted" onClick={() => read(n)}>تحديد كمقروء</button>}
                  </div>
                </div>
                <span className="notif-time">{n.time}</span>
              </div>))}
            <Pager page={page} pages={api.data?.meta?.last_page ?? 1} total={api.data?.meta?.total ?? list.length} per={api.data?.meta?.per_page ?? 20} onPage={setPage} />
          </Async>
        </DataBoundary>
      </section>
    </>
  );
};

/* ---------- 25. Account ---------- */
export const Account = () => {
  const { logout, account } = useApp();
  const [addrs, setAddrs] = useState([]);
  const addrApi = useApi(() => accountEndpoints.addresses().then((a) => { setAddrs(a); return a; }), []);

  return (
    <>
      <PageHead en="My Account" ar="بيانات الحساب والشركة والعناوين المحفوظة">
        <button className="btn-pill" onClick={logout}><i className="fa-solid fa-arrow-right-from-bracket" /> تسجيل الخروج</button>
      </PageHead>
      <div className="acct-hero">
        <div className="wp-avatar" style={{ width: 64, height: 64, fontSize: 20 }}>{String(account?.name || '').split(' ').map((s) => s[0]).slice(0, 2).join('')}</div>
        <div style={{ flex: 1 }}><div className="acct-name">{account?.name}</div><div className="muted small">{account?.company}</div></div>
        <div style={{ textAlign: 'left' }}><Badge map={{ approved: ['حساب معتمد', 'success'] }} k="approved" lg /><div className="soft small mt-8">منذ <span className="num">{account?.approved_at}</span></div></div>
      </div>
      <div className="grid-2e mt-24">
        <Panel title="بيانات التاجر" en="TRADER">
          <dl className="kv"><dt>الاسم</dt><dd>{account?.name}</dd><dt>الموبايل</dt><dd className="num">{account?.phone}</dd><dt>البريد</dt><dd className="num">{account?.email}</dd></dl>
        </Panel>
        <Panel title="البيانات التجارية" en="COMPANY">
          <dl className="kv"><dt>الشركة</dt><dd>{account?.company}</dd><dt>الرقم الضريبي</dt><dd className="num">{account?.tax_number}</dd><dt>السجل التجاري</dt><dd>{account?.commercial_register}</dd><dt>حد الائتمان</dt><dd><Money v={account?.credit_limit} /></dd></dl>
        </Panel>
      </div>
      <Panel title="العناوين المحفوظة" en="ADDRESSES" style={{ marginTop: 24 }}>
        <DataBoundary api={addrApi}>
          <Async rows={3} cols={3} isEmpty={!addrs.length} empty={<Empty icon="fa-location-dot" title="لا توجد عناوين محفوظة" desc="العناوين بتتحفظ تلقائيًا من طلباتك." />}>
            <div className="addr-grid">{addrs.map((a) => (
              <div key={a.id} className={`addr-card${a.is_default ? ' is-def' : ''}`}><div className="row between"><b>{a.city_name_ar}</b>{a.is_default && <span className="badge accent">الافتراضي</span>}</div><div className="small">{a.address_line}</div></div>))}</div>
          </Async>
        </DataBoundary>
      </Panel>
      <p className="note mt-16"><i className="fa-solid fa-circle-info" />لتعديل البيانات أو العناوين أو تغيير كلمة المرور، تواصل مع مدير حسابك.</p>
    </>
  );
};
```

## File: bags-platform/backend/app/Console/Commands/PrivatizeReturnAttachments.php
```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Legacy command alias for migrating uploaded attachments to private R2 storage.
 */
class PrivatizeReturnAttachments extends Command
{
    protected $signature = 'returns:privatize-attachments';

    // Kept as a backwards-compatible alias for existing runbooks and cron entries.
    // Migrates all legacy uploads (not returns only) via uploads:migrate-to-r2.
    protected $description = 'Migrate all legacy uploads to private R2 storage (alias of uploads:migrate-to-r2, not returns-only)';

    public function handle(): int
    {
        return $this->call('uploads:migrate-to-r2', ['--delete-source' => true]);
    }
}
```

## File: bags-platform/backend/app/Http/Controllers/Api/V1/Store/ReturnController.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\Return\StoreReturnRequest;
use App\Http\Resources\Store\ReturnRequestResource;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Services\ReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Customer portal return requests for delivered orders, including defect photo attachments.
 */
class ReturnController extends Controller
{
    private const RELATIONS = ['items.orderItem', 'order'];

    public function __construct(protected ReturnService $returns) {}

    /**
     * Paginate return requests for the authenticated customer.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return ReturnRequestResource::collection(ReturnRequest::query()
            ->where('customer_id', $request->user()->id)->with(self::RELATIONS)->latest('id')->paginate(20));
    }

    /**
     * Display specific customer return request details.
     */
    public function show(Request $request, int $returnRequest): ReturnRequestResource
    {
        $return = ReturnRequest::query()->where('customer_id', $request->user()->id)
            ->with(self::RELATIONS)->findOrFail($returnRequest);

        return $this->returnResource($return);
    }

    /**
     * Submit a new return request against a delivered customer order.
     */
    public function store(StoreReturnRequest $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();

        // Scope order lookup to current customer; throw 404 rather than 403 to prevent order ID enumeration.
        $order = Order::query()
            ->where('customer_id', $customer->id)
            ->findOrFail((int) $request->validated('order_id'));

        $return = $this->returns->createReturnRequest($order, $request->payload(), $customer);

        return $this->returnResource($return)->response()->setStatusCode(201);
    }

    /**
     * Wrap return request model with eager-loaded relations in an API resource.
     *
     * @param  ReturnRequest  $return  Target return request model
     * @return ReturnRequestResource Formatted resource
     */
    private function returnResource(ReturnRequest $return): ReturnRequestResource
    {
        return ReturnRequestResource::make($return->loadMissing(self::RELATIONS));
    }
}
```

## File: bags-platform/backend/app/Http/Requests/Admin/Return/StoreReturnRequest.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Return;

use App\Enums\RefundMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Staff-initiated return request creation for delivered retail or guest orders.
 */
class StoreReturnRequest extends FormRequest
{
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
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'reason' => ['required', 'string', 'max:2000'],
            'refund_method' => ['nullable', Rule::enum(RefundMethod::class)],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.order_item_id' => ['required', 'integer', 'distinct'],
            'items.*.requested_qty' => ['required', 'integer', 'gt:0', 'max:99999999'],
            'items.*.item_reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
```

## File: bags-platform/backend/database/seeders/RolePermissionSeeder.php
```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\StaffRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    /**
     * @var array<string, list<string>>
     */
    private const PERMISSIONS_BY_MODULE = [
        'branches' => ['view', 'create', 'update', 'delete', 'manage'],
        'locations' => ['view', 'create', 'update', 'delete', 'manage'],
        'users' => ['view', 'create', 'update', 'delete', 'manage'],
        'roles' => ['view', 'create', 'update', 'delete', 'manage'],
        'permissions' => ['view'],
        'customers' => ['view', 'create', 'update', 'delete', 'manage'],
        'suppliers' => ['view', 'create', 'update', 'delete', 'manage'],
        'products' => ['view', 'create', 'update', 'delete', 'manage'],
        'categories' => ['view', 'create', 'update', 'delete', 'manage'],
        'inventory' => ['view', 'adjust', 'all_branches', 'view_cost'],
        'orders' => ['view', 'create', 'update', 'delete', 'manage'],
        'purchases' => ['view', 'create', 'update', 'delete', 'manage'],
        'returns' => ['view', 'create', 'update', 'delete', 'manage'],
        'payments' => ['view', 'create', 'update', 'delete', 'manage'],
        'shipping' => ['view', 'create', 'update', 'delete', 'manage'],
        'transfers' => ['view', 'create', 'update', 'delete', 'manage'],
        'reports' => ['view', 'export'],
        'notifications' => ['view', 'create', 'update', 'delete', 'manage'],
        'pricing' => ['view', 'create', 'update', 'delete', 'manage'],
        'settings' => ['view', 'update', 'manage'],
        'store' => ['manage'],
        'pos' => ['access', 'sales.view', 'sales.create', 'sales.refund'],
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS_BY_MODULE as $module => $actions) {
            foreach ($actions as $action) {
                Permission::findOrCreate("{$module}.{$action}", 'staff');
            }
        }

        $superAdmin = Role::findOrCreate(StaffRole::SuperAdmin->value, 'staff');
        $this->call(AiAgentPermissionSeeder::class);
        $this->call(FinancialPermissionSeeder::class);
        $superAdmin->syncPermissions(
            Permission::query()->where('guard_name', 'staff')->get(),
        );
    }
}
```

## File: bags-platform/frontend/packages/api-client/src/endpoints/operations/orders.ts
```typescript
import { api, ADMIN, asPage, one, crud, cleanParams, reader, type Id, type Paginated } from './http';

export type OrderStatus = 'pending' | 'confirmed' | 'processing' | 'shipped' | 'delivered' | 'cancelled' | 'returned';
export type PaymentStatus = 'unpaid' | 'paid' | 'failed' | 'refunded';
export type PaymentMethod = 'cod' | 'card' | 'wallet' | 'bank_transfer' | 'credit';

export const PAYMENT_TRANSITIONS: Record<PaymentStatus, PaymentStatus[]> = {
  unpaid: ['paid', 'failed'],
  failed: ['paid', 'unpaid'],
  paid: ['refunded'],
  refunded: [],
};

export interface AdminOrderListParams {
  page?: number;
  per_page?: number;
  status?: OrderStatus;
  allocated_branch_id?: number;
  payment_method?: PaymentMethod;
  date_from?: string; // Y-m-d
  date_to?: string;   // Y-m-d
  search?: string;
  needs_attention?: boolean;
  requires_refund?: boolean;
}

export interface AdminOrderStats { pending: number; processing: number; needs_attention: number; requires_refund: number }

export interface AdminOrderItem {
  id: number;
  product_variant_id: number;
  product_name: string;
  sku: string;
  allocated_branch_id?: number | null;
  qty: number;
  unit_price: string;
  discount_amount?: string | null;
  tax_amount?: string | null;
  line_total: string;
}

export interface AdminOrderStatusHistory {
  from_status: OrderStatus | null;
  to_status: OrderStatus;
  changed_by_type: string | null;
  changed_by_id: number | null;
  notes: string | null;
  at: string | null;
}

export interface AdminOrder {
  id: number;
  order_number: string;
  channel?: string | null;
  status: OrderStatus;
  payment_status: PaymentStatus;
  payment_method: PaymentMethod;
  requires_refund: boolean;
  needs_attention: boolean;
  cod_fee?: string | null;
  guest_name: string | null;
  guest_phone: string | null;
  guest_email?: string | null;
  subtotal?: string | null;
  discount_amount?: string | null;
  coupon_code?: string | null;
  shipping_cost?: string | null;
  tax_amount?: string | null;
  grand_total: string;
  shipping_address_line?: string | null;
  shipping_notes?: string | null;
  items_count?: number;
  customer?: { id: number; name: string; phone?: string | null; type?: string } | null;
  city?: { id: number; name_ar: string } | null;
  allocated_branch?: { id: number; name: string } | null;
  items?: AdminOrderItem[];
  status_history?: AdminOrderStatusHistory[];
  created_at: string;
  updated_at?: string | null;
}

export interface ShipmentResponse {
  id: number;
  order_id?: number;
  shipping_company_id: number;
  company_name?: string | null;
  awb_number: string | null;
  status?: string | null;
  tracking_url?: string | null;
  label_url?: string | null;
  shipping_cost?: string | null;
  cod_amount?: string | null;
}

const O = `${ADMIN}/orders`;
export const adminOrdersApi = {
  ...reader<AdminOrder, AdminOrderListParams>(O),
  updateStatus: (id: Id, body: { status: Exclude<OrderStatus, 'shipped'>; notes?: string }) =>
    api.patch(`${O}/${id}/status`, body).then((r) => one<AdminOrder>(r)),
  updatePayment: (id: Id, body: { payment_status: PaymentStatus; notes: string; refund_reference?: string }) =>
    api.post(`${O}/${id}/payment`, body).then((r) => one<AdminOrder>(r)),
  allocate: (id: Id, branch_id: number | null) =>
    api.post(`${O}/${id}/allocate`, { branch_id }).then((r) => one<AdminOrder>(r)),
  deallocate: (id: Id) => api.post(`${O}/${id}/deallocate`).then((r) => one<AdminOrder>(r)),
  createShipment: (id: Id, body: { shipping_company_id: number; awb_number?: string | null }) =>
    api.post(`${O}/${id}/shipment`, body).then((r) => one<ShipmentResponse>(r)),
  stats: (signal?: AbortSignal): Promise<AdminOrderStats> =>
    api.get<{ data: AdminOrderStats }>(`${O}/stats`, { signal }).then((r) => r.data.data),
};

/* ===== Returns ===== */
export type ReturnStatus = 'pending' | 'approved' | 'rejected' | 'received' | 'refunded' | 'cancelled';
export type RefundMethod = 'original_payment' | 'wallet' | 'bank_transfer' | 'cash';

export interface ReturnRequestItem {
  id: number;
  order_item_id: number;
  product_variant_id?: number | null;
  product_name?: string | null;
  sku?: string | null;
  requested_qty: number;
  approved_qty: number | null;
  received_good_qty: number | null;
  received_damaged_qty: number | null;
  unit_refund_price?: string | null;
  item_reason?: string | null;
}

/** Maps directly to backend ReturnRequestResource payload. */
export interface ReturnRequest {
  id: number;
  return_number: string;
  order_id: number;
  order_number?: string | null;
  customer_id: number | null;
  customer_name?: string | null;
  branch_id: number | null;
  status: ReturnStatus;
  refund_method: RefundMethod | null;
  total_refund_amount: string | null;
  reason: string | null;
  admin_notes: string | null;
  refund_reference: string | null;
  approved_by: number | null;
  received_by: number | null;
  refunded_by: number | null;
  approved_at: string | null;
  received_at: string | null;
  refunded_at: string | null;
  items?: ReturnRequestItem[];
  created_at: string | null;
  updated_at: string | null;
}

export interface ReturnListParams { page?: number; search?: string; status?: ReturnStatus; order_id?: number; customer_id?: number }
export interface StoreReturnPayload {
  order_id: number;
  reason: string;
  refund_method?: RefundMethod | null;
  items: { order_item_id: number; requested_qty: number; item_reason?: string }[];
}
export interface ApproveReturnPayload { branch_id: number; items?: { id: number; approved_qty: number }[]; admin_notes?: string }
export interface ReceiveReturnPayload { items: { id: number; received_good_qty: number; received_damaged_qty: number }[] }
export interface RefundReturnPayload { refund_method?: RefundMethod | null; refund_reference?: string }

const R = `${ADMIN}/returns`;
export const adminReturnsApi = {
  ...crud<ReturnRequest, StoreReturnPayload, ReturnListParams>(R),
  update: (id: Id, b: Partial<StoreReturnPayload> & { status?: 'rejected' | 'cancelled'; admin_notes?: string; refund_method?: RefundMethod | null }) =>
    api.put(`${R}/${id}`, b).then((r) => one<ReturnRequest>(r)),
  approve: (id: Id, b: ApproveReturnPayload) => api.post(`${R}/${id}/approve`, b).then((r) => one<ReturnRequest>(r)),
  receive: (id: Id, b: ReceiveReturnPayload) => api.post(`${R}/${id}/receive`, b).then((r) => one<ReturnRequest>(r)),
  refund: (id: Id, b: RefundReturnPayload) => api.post(`${R}/${id}/refund`, b).then((r) => one<ReturnRequest>(r)),
};
```

## File: bags-platform/frontend/packages/api-client/src/endpoints/operations/reports.ts
```typescript
import { api, ADMIN, cleanParams, SLOW_REQUEST_TIMEOUT, type PageMeta } from './http';

type Money = number | string;
export interface DashboardSummary {
  today: DashboardSales & { new_orders_count: number };
  month: DashboardSales;
  low_stock_count: number;
  pending_wholesale_applications: number;
  top_products: { product_variant_id: number; product_name: string | null; sku: string | null; qty_sold: number; revenue: Money }[];
  top_branches: { branch_id: number; branch_name: string | null; orders_count: number; revenue: Money }[];
}
interface DashboardSales {
  sales_total: Money;
  sales_by_channel: Record<'store' | 'wholesale' | 'pos', Money>;
}
export interface ReportPeriod { from?: string; to?: string }
export interface SalesReportParams extends ReportPeriod { granularity?: 'daily' | 'monthly'; group_by?: 'branch' | 'channel' | 'cashier' | 'payment_method' }
export interface InventoryReportParams { dead_days?: number }
export interface SalesReportRow { period: string; group_key: string | number | null; group_label: string | null; orders_count: number; gross_sales: Money; discounts: Money; tax: Money; shipping: Money; net_sales: Money }
export interface SalesReportData { rows: SalesReportRow[]; totals: Omit<SalesReportRow, 'period' | 'group_key' | 'group_label'> }
export interface InventoryReportData {
  valuation: { branch_id: number; branch_name: string; variants_count: number; total_qty: Money; total_value: Money }[];
  total_value: Money; dead_stock_days: number;
  dead_stock: { product_variant_id: number; sku: string; product_name: string; on_hand: Money; tied_value: Money; last_movement_at: string | null }[];
}
export interface ProfitReportData { net_sales_revenue: Money; missing_cost_movements?: number; cogs: Money; gross_profit: Money; total_expenses: Money; net_profit: Money }
export interface SupplierReportRow { supplier_id: number; supplier_name: string; current_balance: Money; total_purchases: Money; aging: Record<'not_due' | 'overdue_1_30' | 'overdue_31_60' | 'overdue_61_90' | 'overdue_90_plus', Money> }
export interface ShiftReportRow { shift_id: number; shift_number: string; cashier_name: string; branch_name: string; status: string; opened_at: string; closed_at: string | null; cash_sales_total: Money; card_sales_total: Money; cash_returns_total: Money; cash_expenses_total: Money; expected_closing_cash: Money; actual_closing_cash: Money | null; cash_difference: Money | null; has_difference: boolean }
export interface ShiftReportData { rows: ShiftReportRow[]; totals: { shifts_count: number; cash_sales: Money; card_sales: Money; shifts_with_difference: number } }
const report = <T>(name: string, p: object, signal?: AbortSignal): Promise<T> =>
  api.get<{ data: T }>(`${ADMIN}/reports/${name}`, { params: cleanParams(p), signal, timeout: SLOW_REQUEST_TIMEOUT }).then((r) => r.data.data);
export const reportsApi = {
  dashboard: (signal?: AbortSignal) =>
    api.get<{ data: DashboardSummary }>(`${ADMIN}/dashboard`, { signal, timeout: SLOW_REQUEST_TIMEOUT }).then((r) => r.data.data),
  sales: (p: SalesReportParams = {}, signal?: AbortSignal) => report<SalesReportData>('sales', p, signal),
  profit: (p: ReportPeriod = {}, signal?: AbortSignal) => report<ProfitReportData>('profit', p, signal),
  inventory: (p: InventoryReportParams = {}, signal?: AbortSignal) => report<InventoryReportData>('inventory', p, signal),
  shifts: (p: ReportPeriod & { only_differences?: boolean } = {}, signal?: AbortSignal) => report<ShiftReportData>('shifts', p, signal),
  suppliers: (signal?: AbortSignal) => report<SupplierReportRow[]>('suppliers', {}, signal),
};

export const MAX_REPORT_DAYS = 366;
export function validatePeriod(from?: string, to?: string): string | null {
  if (!from && !to) return null;
  if (!from || !to) return 'اختر تاريخ البداية والنهاية';
  const date = (value: string) => {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return NaN;
    const stamp = Date.parse(value + 'T00:00:00Z');
    return Number.isFinite(stamp) && new Date(stamp).toISOString().slice(0, 10) === value ? stamp : NaN;
  };
  const a = date(from), b = date(to);
  if (!Number.isFinite(a) || !Number.isFinite(b)) return 'صيغة التاريخ غير صحيحة';
  if (b < a) return 'تاريخ النهاية يجب أن يكون بعد تاريخ البداية';
  // ReportService uses an inclusive period (difference <= 365 days).
  if ((b - a) / 86_400_000 + 1 > MAX_REPORT_DAYS) return `أقصى مدى للتقرير ${MAX_REPORT_DAYS} يوماً`;
  return null;
}

export interface NotificationApi { id: string; type: string; data: Record<string, unknown>; read_at: string | null; created_at: string | null }
export type AdminNotification = NotificationApi;
export interface NotificationsPage { data: NotificationApi[]; meta: Omit<PageMeta, 'from' | 'to'> & { unread_count: number } }
export const notificationsApi = {
  list: (page = 1, signal?: AbortSignal) => api.get<NotificationsPage>(`${ADMIN}/notifications`, { params: { page }, signal }).then((r) => r.data),
  markRead: (id: string) => api.post<{ data: { id: string; read_at: string }; meta: { unread_count: number } }>(`${ADMIN}/notifications/${encodeURIComponent(id)}/read`).then((r) => r.data),
  readAll: () => api.post<{ data: { read_all: boolean }; meta: { unread_count: number } }>(`${ADMIN}/notifications/read-all`).then((r) => r.data),
};
```

## File: bags-platform/backend/app/Http/Controllers/Api/V1/Admin/ReturnController.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ReturnStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Return\ApproveReturnRequest;
use App\Http\Requests\Admin\Return\ReceiveReturnRequest;
use App\Http\Requests\Admin\Return\RefundReturnRequest;
use App\Http\Requests\Admin\Return\StoreReturnRequest;
use App\Http\Requests\Admin\Return\UpdateReturnRequest;
use App\Http\Resources\Admin\ReturnRequestResource;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\ReturnService;
use App\Services\SearchTerm;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * Manages customer return request lifecycle including approvals, receiving, and refunds.
 */
class ReturnController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:returns.view', only: ['index', 'show']),
            new Middleware('can:returns.create', only: ['store']),
            new Middleware('can:returns.update', only: ['update']),
            new Middleware('can:returns.approve', only: ['approve']),
            new Middleware('can:returns.receive', only: ['receive']),
            new Middleware('can:returns.refund', only: ['refund']),
            new Middleware('can:returns.delete', only: ['destroy']),
        ];
    }

    public function __construct(protected ReturnService $returns) {}

    /**
     * Lists paginated return requests scoped by branch access and status filters.
     *
     * @param Request $request Filter query request
     * @return AnonymousResourceCollection Paginated return requests
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(array_column(ReturnStatus::cases(), 'value'))],
            'order_id' => ['nullable', 'integer', 'min:1'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $search = trim((string) $request->query('search', ''));
        $branch = app(BranchAccess::class)->restrictedBranch($request->user());
        $returns = ReturnRequest::query()->when($branch !== null, fn ($q) => $q->where(fn ($q) => $q->where('branch_id', $branch)->orWhere(fn ($q) => $q->whereNull('branch_id')->whereHas('order', fn ($q) => $q->where('allocated_branch_id', $branch)))))
            ->with(['order:id,order_number', 'customer:id,name', 'items.orderItem'])
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('order_id'), fn ($q, $orderId) => $q->where('order_id', (int) $orderId))
            ->when($request->query('customer_id'), fn ($q, $customerId) => $q->where('customer_id', (int) $customerId))
            ->when($search !== '', fn ($q) => $q->where(function ($w) use ($search): void {
                $pattern = SearchTerm::pattern($search);
                $w->where('return_number', 'ilike', $pattern)
                    ->orWhereHas('order', fn ($o) => $o->where('order_number', 'ilike', $pattern));
            }))
            ->latest('id')
            ->paginate(20);

        return ReturnRequestResource::collection($returns);
    }

    /**
     * Submits a manual return request for an order on behalf of a customer.
     *
     * @param StoreReturnRequest $request Creation request
     * @return JsonResponse Created return request response
     */
    public function store(StoreReturnRequest $request): JsonResponse
    {
        $order = Order::query()->findOrFail((int) $request->validated('order_id'));
        if ($order->allocated_branch_id !== null) {
            app(BranchAccess::class)->assertBranch($request->user()->id, (int) $order->allocated_branch_id);
        } else {
            app(BranchAccess::class)->assertCentralAccess($request->user());
        }
        $return = $this->returns->createReturnRequest($order, $request->validated());
        $return->load(['items.orderItem', 'order', 'customer']);

        return ReturnRequestResource::make($return)->response()->setStatusCode(201);
    }

    /**
     * Retrieves details of a specific return request.
     *
     * @param Request $request Incoming request
     * @param ReturnRequest $returnRequest Target return request
     * @return ReturnRequestResource Return request resource
     */
    public function show(Request $request, ReturnRequest $returnRequest): ReturnRequestResource
    {
        $this->authorizeReturn($request, $returnRequest);
        $returnRequest->load(['items.orderItem', 'order', 'customer', 'branch']);

        return ReturnRequestResource::make($returnRequest);
    }

    /**
     * Updates return request attributes and status.
     *
     * @param UpdateReturnRequest $request Update request
     * @param ReturnRequest $returnRequest Target return request
     * @return ReturnRequestResource Updated return request resource
     */
    public function update(UpdateReturnRequest $request, ReturnRequest $returnRequest): ReturnRequestResource
    {
        $this->authorizeReturn($request, $returnRequest);
        // Rejecting a return is an approval-tier decision requiring 'returns.approve' capability.
        if (($request->validated()['status'] ?? null) === 'rejected') {
            abort_unless($request->user()->can('returns.approve'), 403);
        }
        /** @var User $admin */
        $admin = $request->user();
        $return = $this->returns->updateDetails($returnRequest, $admin, $request->validated());
        $return->load(['items.orderItem', 'order', 'customer']);

        return ReturnRequestResource::make($return);
    }

    /**
     * Deletes a pending return request.
     *
     * @param Request $request Deletion request
     * @param ReturnRequest $returnRequest Target return request
     * @return JsonResponse Deletion confirmation
     */
    public function destroy(Request $request, ReturnRequest $returnRequest): JsonResponse
    {
        $this->authorizeReturn($request, $returnRequest);
        $this->returns->delete($returnRequest);

        return response()->json(['message' => 'تم حذف طلب المرتجع']);
    }

    /**
     * Approves a return request and assigns the receiving branch.
     *
     * @param ApproveReturnRequest $request Approval request
     * @param ReturnRequest $returnRequest Target return request
     * @return ReturnRequestResource Approved return resource
     */
    public function approve(ApproveReturnRequest $request, ReturnRequest $returnRequest): ReturnRequestResource
    {
        $this->authorizeReturn($request, $returnRequest);
        app(BranchAccess::class)->assertBranch($request->user()->id, (int) $request->validated()['branch_id']);
        /** @var User $admin */
        $admin = $request->user();
        $return = $this->returns->approve($returnRequest, $admin, $request->validated());
        $return->load(['items.orderItem', 'order', 'customer']);

        return ReturnRequestResource::make($return);
    }

    /**
     * Confirms receipt and inspection of returned merchandise at the branch.
     *
     * @param ReceiveReturnRequest $request Receiving request
     * @param ReturnRequest $returnRequest Target return request
     * @return ReturnRequestResource Received return resource
     */
    public function receive(ReceiveReturnRequest $request, ReturnRequest $returnRequest): ReturnRequestResource
    {
        $this->authorizeReturn($request, $returnRequest);
        /** @var User $admin */
        $admin = $request->user();
        $return = $this->returns->receive($returnRequest, $admin, $request->validated());
        $return->load(['items.orderItem', 'order', 'customer']);

        return ReturnRequestResource::make($return);
    }

    /**
     * Issues financial refund for received return items.
     *
     * @param RefundReturnRequest $request Refund request
     * @param ReturnRequest $returnRequest Target return request
     * @return ReturnRequestResource Refunded return resource
     */
    public function refund(RefundReturnRequest $request, ReturnRequest $returnRequest): ReturnRequestResource
    {
        $this->authorizeReturn($request, $returnRequest);
        /** @var User $admin */
        $admin = $request->user();
        $return = $this->returns->refund($returnRequest, $admin, $request->validated());
        $return->load(['items.orderItem', 'order', 'customer']);

        return ReturnRequestResource::make($return);
    }

    private function authorizeReturn(Request $request, ReturnRequest $returnRequest): void
    {
        $branch = app(BranchAccess::class)->restrictedBranch($request->user());
        if ($branch === null) {
            return;
        }
        $returnRequest->loadMissing('order:id,allocated_branch_id');
        $returnBranch = $returnRequest->branch_id !== null ? (int) $returnRequest->branch_id : null;
        $orderBranch = $returnRequest->order?->allocated_branch_id !== null ? (int) $returnRequest->order->allocated_branch_id : null;
        if (($returnBranch ?? $orderBranch) !== $branch) {
            throw new AuthorizationException('لا تملك صلاحية الوصول إلى هذا المرتجع.');
        }
    }
}
```

## File: bags-platform/backend/app/Http/Controllers/Api/V1/Pos/PosSaleController.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Pos;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosHeldSale;
use App\Models\PosShift;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\OperationIdempotencyService;
use App\Services\PosService;
use App\Services\SettingService;
use App\Support\ArabicDigits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * POS terminal sales operations: direct checkout, cart hold/resume, returns, and item exchanges.
 */
class PosSaleController extends Controller
{
    public function __construct(private readonly PosService $pos) {}

    /**
     * Create and record a direct POS retail sale within the active shift.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_variant_id' => ['required', 'integer', 'min:1'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:9999'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'payment_method' => ['nullable', 'string', 'in:cash,card,mixed'],
            'cash_amount' => ['nullable', 'numeric', 'min:0'],
            'card_amount' => ['nullable', 'numeric', 'min:0'],
            'override_price' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var PosShift $shift */
        $shift = $request->attributes->get('pos_shift');

        // Authorize manual unit price overrides via pos.override_price permission.
        if (! empty($data['override_price'])) {
            $user = $request->user('staff');
            if (! $user->can('pos.override_price')) {
                return response()->json(['message' => 'لا تملك صلاحية تجاوز سعر البيع.'], 403);
            }
        }

        $order = app(OperationIdempotencyService::class)->run(
            'pos_sale', $request->user('staff'), $this->requiredIdempotencyKey($request),
            ['shift_id' => $shift->id, ...$data], fn () => $this->pos->createSale($shift, $data),
        );
        // Idempotent replays resolve via find() without relations; reload for a complete response.
        $order->loadMissing(['items.variant', 'customer']);

        return response()->json([
            'message' => 'تم تسجيل البيع بنجاح.',
            'data' => $this->formatOrder($order),
        ], 201);
    }

    /**
     * Temporarily hold an uncompleted sale for later retrieval.
     */
    public function hold(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'integer', 'min:1'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var PosShift $shift */
        $shift = $request->attributes->get('pos_shift');

        $held = $this->pos->holdSale($shift, $data);

        return response()->json([
            'message' => 'تم تعليق الفاتورة بنجاح.',
            'hold_reference' => $held->hold_reference,
            'data' => $held->only(['id', 'hold_reference', 'items_payload', 'notes', 'created_at']),
        ], 201);
    }

    /**
     * List all held sales currently pending in the cashier's branch.
     */
    public function held(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('staff');
        $held = PosHeldSale::query()
            ->where('branch_id', $user->branch_id)
            ->with(['customer'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $held]);
    }

    /**
     * Calculate live cart pricing, discounts, available stock, and totals without locks or DB writes.
     */
    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'override_price' => ['nullable', 'boolean'],
        ]);

        /** @var PosShift $shift */
        $shift = $request->attributes->get('pos_shift');
        /** @var User $user */
        $user = $request->user('staff');

        $override = ! empty($data['override_price']);
        if ($override && ! $user->can('pos.override_price')) {
            return response()->json(['message' => 'لا تملك صلاحية تجاوز سعر البيع.'], 403);
        }

        if (collect($data['items'])->contains(fn ($item) => (float) ($item['discount_amount'] ?? 0) > 0) && ! $user->can('pos.discount')) {
            return response()->json(['message' => 'لا تملك صلاحية تطبيق خصم يدوي.'], 403);
        }

        $customer = isset($data['customer_id']) ? Customer::find($data['customer_id']) : null;

        return response()->json([
            'data' => $this->pos->quote(
                branchId: (int) $shift->branch_id,
                items: $data['items'],
                c: $customer,
                override: $override,
            ),
        ]);
    }

    /**
     * Look up a delivered POS sale order by number for return or exchange processing.
     */
    public function lookupForReturn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'number' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user('staff');

        $normalized = strtoupper(trim(ArabicDigits::normalize($data['number'])));
        if ($normalized === '') {
            abort(404, 'الفاتورة غير موجودة.');
        }

        $candidates = [$normalized];
        if (! str_starts_with($normalized, 'POS-')) {
            $candidates[] = 'POS-'.$normalized;
        }
        if (! str_starts_with($normalized, 'ORD-')) {
            $candidates[] = 'ORD-'.$normalized;
        }

        $stripped = preg_replace('/^(?:POS|ORD)-/', '', $normalized) ?? $normalized;
        if (ctype_digit($stripped)) {
            $intVal = (string) ((int) $stripped);
            $pad6 = str_pad($intVal, 6, '0', STR_PAD_LEFT);
            $pad4 = str_pad($intVal, 4, '0', STR_PAD_LEFT);
            foreach (['POS-', 'ORD-'] as $p) {
                $candidates[] = $p.$stripped;
                $candidates[] = $p.$intVal;
                $candidates[] = $p.$pad6;
                $candidates[] = $p.$pad4;
            }
            $orderPrefix = (string) app(SettingService::class)->get('order_prefix', 'ORD-');
            $orderPadding = (int) app(SettingService::class)->get('order_padding', 6);
            if ($orderPrefix !== '') {
                $candidates[] = strtoupper($orderPrefix).str_pad($intVal, max(0, $orderPadding), '0', STR_PAD_LEFT);
                $candidates[] = strtoupper($orderPrefix).$stripped;
            }
        }
        $candidates = array_values(array_unique($candidates));
        $literalSuffix = strtr($stripped, ['!' => '!!', '%' => '!%', '_' => '!_']);

        $order = Order::query()
            ->where('channel', 'pos')
            ->where('status', OrderStatus::Delivered)
            ->whereNull('return_of_order_id')
            ->where(function ($q) use ($candidates, $stripped, $literalSuffix): void {
                $q->whereIn('order_number', $candidates);
                if ($stripped !== '') {
                    $q->orWhereRaw("order_number LIKE ? ESCAPE '!'", ['%-'.$literalSuffix]);
                    if (ctype_digit($stripped)) {
                        $intVal = (string) ((int) $stripped);
                        $q->orWhere('order_number', 'like', '%-'.str_pad($intVal, 6, '0', STR_PAD_LEFT));
                    }
                }
            })
            ->with(['items.variant.product', 'customer', 'posShift.cashier'])
            ->latest('id')
            ->first();

        if ($order === null) {
            abort(404, 'الفاتورة غير موجودة.');
        }

        $branchId = (int) ($order->allocated_branch_id ?? $order->posShift?->branch_id ?? 0);
        app(BranchAccess::class)->assertBranch($user->id, $branchId);

        return response()->json([
            'data' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'created_at' => $order->created_at,
                'payment_method' => $order->payment_method,
                'cash_amount' => number_format((float) $order->cash_amount, 2, '.', ''),
                'card_amount' => number_format((float) $order->card_amount, 2, '.', ''),
                'grand_total' => number_format((float) $order->grand_total, 2, '.', ''),
                'customer' => $order->customer !== null ? [
                    'id' => $order->customer->id,
                    'name' => $order->customer->name,
                    'phone' => $order->customer->phone,
                ] : null,
                'cashier' => $order->posShift?->cashier !== null ? [
                    'id' => $order->posShift->cashier->id,
                    'name' => $order->posShift->cashier->name,
                ] : null,
                'items' => $order->items->map(function (OrderItem $item): array {
                    $qty = (float) $item->qty;
                    $returnedQty = (float) ($item->returned_qty ?? 0);
                    $returnableQty = max(0.0, $qty - $returnedQty);
                    $unitPrice = (float) $item->unit_price;
                    $discountAmount = (float) $item->discount_amount;
                    $netUnitPrice = $qty > 0 ? ($qty * $unitPrice - $discountAmount) / $qty : 0.0;

                    return [
                        'id' => $item->id,
                        'product_variant_id' => $item->product_variant_id,
                        'product_name' => $item->product_name_snapshot
                            ?? $item->variant?->product?->name_ar
                            ?? $item->variant?->product?->name_en,
                        'variant_name' => $item->variant?->color_name_ar ?? $item->variant?->color_name_en,
                        'sku' => $item->sku_snapshot ?? $item->variant?->sku,
                        'qty' => floor($qty) === $qty ? (int) $qty : $qty,
                        'returned_qty' => floor($returnedQty) === $returnedQty ? (int) $returnedQty : $returnedQty,
                        'returnable_qty' => floor($returnableQty) === $returnableQty ? (int) $returnableQty : $returnableQty,
                        'unit_price' => number_format($unitPrice, 2, '.', ''),
                        'discount_amount' => number_format($discountAmount, 2, '.', ''),
                        'net_unit_price' => number_format($netUnitPrice, 2, '.', ''),
                    ];
                })->values()->all(),
            ],
        ]);
    }

    /**
     * Atomically lock, retrieve, and delete a held sale so two cashiers cannot resume it twice.
     */
    public function resumeHeld(Request $request, int $held_sale): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('staff');

        $data = DB::transaction(function () use ($held_sale, $user): array {
            $heldSale = PosHeldSale::query()
                ->whereKey($held_sale)
                ->lockForUpdate()
                ->with('customer')
                ->first();

            if ($heldSale === null) {
                abort(404, 'الفاتورة المعلقة غير موجودة.');
            }

            app(BranchAccess::class)->assertBranch($user->id, (int) $heldSale->branch_id);

            $payload = [
                'id' => $heldSale->id,
                'hold_reference' => $heldSale->hold_reference,
                'customer_id' => $heldSale->customer_id,
                'customer' => $heldSale->customer !== null ? [
                    'id' => $heldSale->customer->id,
                    'name' => $heldSale->customer->name,
                    'phone' => $heldSale->customer->phone,
                ] : null,
                'notes' => $heldSale->notes,
                'items_payload' => $heldSale->items_payload,
            ];

            $heldSale->delete();

            return $payload;
        });

        return response()->json([
            'message' => 'تم استعادة الفاتورة المعلقة بنجاح.',
            'data' => $data,
        ]);
    }

    /**
     * Delete a parked held sale belonging to the cashier's branch.
     */
    public function deleteHeld(Request $request, int $held_sale): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('staff');

        $heldSale = PosHeldSale::query()->find($held_sale);
        if ($heldSale === null) {
            abort(404, 'الفاتورة المعلقة غير موجودة.');
        }

        app(BranchAccess::class)->assertBranch($user->id, (int) $heldSale->branch_id);

        $heldSale->delete();

        return response()->json([
            'message' => 'تم حذف الفاتورة المعلقة بنجاح.',
        ]);
    }

    /**
     * Process item return against an existing POS sale order.
     */
    public function processReturn(Request $request, Order $sale): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'integer', 'min:1'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.return_to' => ['nullable', 'string', 'in:on_hand,damaged'],
        ]);

        /** @var PosShift $shift */
        $shift = $request->attributes->get('pos_shift');

        $sale->load('items');

        $result = $this->pos->processReturn($shift, $sale, $data['items']);

        return response()->json([
            'message' => 'تم تسجيل المرتجع بنجاح.',
            'data' => $result,
        ]);
    }

    /**
     * Process exchange: atomically return items and create new replacement sale order.
     */
    public function processExchange(Request $request, Order $sale): JsonResponse
    {
        $data = $request->validate([
            'returned_items' => ['required', 'array', 'min:1'],
            'returned_items.*.product_variant_id' => ['required', 'integer', 'min:1'],
            'returned_items.*.qty' => ['required', 'integer', 'min:1'],
            'returned_items.*.return_to' => ['nullable', 'string', 'in:on_hand,damaged'],
            'exchange_items' => ['required', 'array', 'min:1'],
            'exchange_items.*.product_variant_id' => ['required', 'integer', 'min:1'],
            'exchange_items.*.qty' => ['required', 'integer', 'min:1'],
            'exchange_items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'exchange_items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'cash_amount' => ['nullable', 'numeric', 'min:0'],
            'card_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        /** @var PosShift $shift */
        $shift = $request->attributes->get('pos_shift');

        $sale->load('items');

        // Atomically process return and replacement sale order in a single transaction.
        $exchangePayload = [
            'items' => $data['exchange_items'],
            'customer_id' => $sale->customer_id,
        ];
        if (array_key_exists('cash_amount', $data)) {
            $exchangePayload['cash_amount'] = $data['cash_amount'];
        }
        if (array_key_exists('card_amount', $data)) {
            $exchangePayload['card_amount'] = $data['card_amount'];
        }
        $result = DB::transaction(function () use ($shift, $sale, $data, $exchangePayload): array {
            $result = $this->pos->processReturn($shift, $sale, $data['returned_items']);
            $exchangeOrder = $this->pos->createSale($shift, $exchangePayload);
            $result['exchange_order_id'] = $exchangeOrder->id;

            return $result;
        }, 3);

        return response()->json([
            'message' => 'تم تسجيل الاستبدال بنجاح.',
            'data' => $result,
        ]);
    }

    // ===================================================================
    //  Helper
    // ===================================================================

    /**
     * Formats order and line items for POS receipt printer and client payload.
     *
     * @param  Order  $order  Target order model
     * @return array<string, mixed> Formatted POS order data
     */
    private function formatOrder(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'channel' => $order->channel,
            'status' => $order->status->value,
            'grand_total' => $order->grand_total,
            'cash_amount' => $order->cash_amount,
            'card_amount' => $order->card_amount,
            'customer' => $order->relationLoaded('customer') ? [
                'id' => $order->customer?->id,
                'name' => $order->customer?->name,
            ] : null,
            'items' => $order->relationLoaded('items') ? $order->items->map(fn ($i) => [
                'product_variant_id' => $i->product_variant_id,
                'sku' => $i->relationLoaded('variant') ? $i->variant?->sku : null,
                'qty' => $i->qty,
                'unit_price' => $i->unit_price,
                'discount_amount' => $i->discount_amount,
                'line_total' => $i->line_total,
            ])->all() : [],
        ];
    }
}
```

## File: bags-platform/backend/app/Http/Requests/Store/Return/StoreReturnRequest.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Store\Return;

use App\Enums\RefundMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Customer return request submission with optional defect photos per item.
 */
class StoreReturnRequest extends FormRequest
{
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
            'order_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:2000'],
            'refund_method' => ['nullable', Rule::enum(RefundMethod::class)],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.order_item_id' => ['required', 'integer', 'distinct'],
            'items.*.requested_qty' => ['required', 'integer', 'gt:0', 'max:99999999'],
            'items.*.item_reason' => ['nullable', 'string', 'max:255'],
            'items.*.attachments' => ['nullable', 'array', 'max:5'],
            'items.*.attachments.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $files = 0;
            $kilobytes = 0;
            foreach (array_keys($this->input('items', []) ?? []) as $index) {
                $itemFiles = $this->file("items.{$index}.attachments");
                $itemFiles = is_array($itemFiles) ? $itemFiles : ($itemFiles !== null ? [$itemFiles] : []);
                foreach ($itemFiles as $file) {
                    if (! is_object($file) || ! method_exists($file, 'getSize')) {
                        continue;
                    }
                    $files++;
                    $kilobytes += (int) ceil($file->getSize() / 1024);
                }
            }
            if ($files > 10) {
                $validator->errors()->add('items', 'الحد الأقصى لمرفقات طلب الإرجاع 10 ملفات.');
            }
            if ($kilobytes > 25600) {
                $validator->errors()->add('items', 'الحجم الإجمالي لمرفقات طلب الإرجاع يجب ألا يتجاوز 25 ميغابايت.');
            }
        });
    }

    /**
     * Return request payload formatted for ReturnService, pairing validated inputs with uploaded item files.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();
        foreach (array_keys($data['items'] ?? []) as $i) {
            $files = $this->file("items.{$i}.attachments");
            $data['items'][$i]['attachments'] = is_array($files) ? $files : ($files !== null ? [$files] : []);
        }

        return $data;
    }
}
```

## File: bags-platform/backend/app/Services/DiscreteQuantity.php
```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessException;

/**
 * Validates inventory and transaction quantities against domain boundary limits.
 *
 * Supports continuous units up to 4 decimal places matching database precision (decimal:12,4),
 * as well as strictly indivisible whole discrete units.
 */
final class DiscreteQuantity
{
    /** Maximum allowed decimal quantity matching database decimal(12,4). */
    public const MAX_DECIMAL = 99999999.9999;

    /**
     * Checks whether a quantity is finite, has at most 4 decimal places, and stays within limits.
     *
     * @param  mixed  $qty  Input quantity or cost value.
     * @param  bool  $allowZero  Whether zero quantity is permitted.
     */
    public static function isValid(mixed $qty, bool $allowZero = false): bool
    {
        if (! is_numeric($qty) || ! is_finite((float) $qty)) {
            return false;
        }
        $value = (float) $qty;
        // Epsilon comparison guards against IEEE-754 floating point inaccuracies during decimal checks.
        if ($value < ($allowZero ? 0 : 1) || $value > self::MAX_DECIMAL || abs($value - round($value, 4)) > 1e-9) {
            return false;
        }

        return true;
    }

    /**
     * Assert that a quantity is finite, has at most 4 decimal places, and stays within bounds.
     *
     * @param  mixed  $qty  Input quantity value.
     * @param  bool  $allowZero  Whether zero quantity is permitted.
     *
     * @throws BusinessException If quantity is non-numeric, out of bounds, or exceeds 4 decimals.
     */
    public static function assert(mixed $qty, bool $allowZero = false): void
    {
        if (! self::isValid($qty, $allowZero)) {
            throw new BusinessException('الكمية يجب أن تكون رقمًا حتى 4 خانات عشرية ضمن الحد المسموح.');
        }
    }

    /**
     * Assert that a quantity is an indivisible, positive whole integer.
     *
     * @param  mixed  $qty  Input quantity value.
     * @param  bool  $allowZero  Whether zero quantity is permitted.
     * @param  string|null  $customMessage  Optional exception message override.
     *
     * @throws BusinessException If value is fractional, negative, or not a finite number.
     */
    public static function assertWhole(mixed $qty, bool $allowZero = false, ?string $customMessage = null): void
    {
        if (! is_numeric($qty) || ! is_finite((float) $qty)) {
            throw new BusinessException($customMessage ?? 'الكمية يجب أن تكون عددًا صحيحًا من القطع.');
        }
        $value = (float) $qty;
        // Discrete piece counts cannot be subdivided into fractional merchandise units.
        if (floor($value) !== $value || $value < ($allowZero ? 0 : 1)) {
            throw new BusinessException($customMessage ?? 'الكمية يجب أن تكون عددًا صحيحًا من القطع.');
        }
    }
}
```

## File: bags-platform/backend/app/Services/PrivateAttachments.php
```php
<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Transactional file upload manager for private cloud storage (R2).
 *
 * Automatically tracks and deletes uploaded files if the database transaction fails.
 */
class PrivateAttachments
{
    private array $stored = [];

    private array $prepared = [];

    private array $claimed = [];

    /**
     * Executes a callback within a transactional scope, purging stored files on rollback.
     *
     * @param  callable(self): mixed  $callback  Business transaction callback.
     * @param  list<array{0: UploadedFile, 1: string}>  $uploads  Optional files pre-uploaded outside transaction locks.
     * @return mixed Value returned by the business callback.
     *
     * @throws Throwable Re-throws the original exception if transaction fails.
     */
    public static function transaction(callable $callback, array $uploads = []): mixed
    {
        $files = new self;
        try {
            foreach ($uploads as [$file, $directory]) {
                if ($file instanceof UploadedFile) {
                    if (isset($files->prepared[spl_object_id($file)])) {
                        continue;
                    }
                    $files->prepared[spl_object_id($file)] = $files->store($file, $directory.'/'.Str::uuid());
                }
            }
        } catch (Throwable $exception) {
            $files->deleteStoredQuietly();
            throw $exception;
        }

        // Attach rollback cleanup to outer transaction to ensure savepoint rollbacks still purge remote storage.
        if (DB::transactionLevel() > 0) {
            $pending = $files;
            DB::afterRollBack(static function () use ($pending): void {
                $pending->deleteStoredQuietly();
            });
        }

        try {
            $result = DB::transaction(fn () => $callback($files));
            $unused = array_diff(array_values($files->prepared), $files->claimed);
            $files->deletePathsQuietly($unused);
            $files->stored = array_values(array_diff($files->stored, $unused));

            return $result;
        } catch (Throwable $exception) {
            // Suppress cleanup failures so the original business or database exception surfaces to the caller.
            $files->deleteStoredQuietly();

            throw $exception;
        }
    }

    /**
     * Stores an uploaded file on private R2 storage and tracks it for transaction rollback cleanup.
     *
     * @param  UploadedFile  $file  Uploaded file instance.
     * @param  string  $directory  Target storage path.
     * @return string Stored file path on disk.
     *
     * @throws RuntimeException If the cloud storage upload fails.
     */
    public function store(UploadedFile $file, string $directory): string
    {
        if (isset($this->prepared[spl_object_id($file)])) {
            $path = $this->prepared[spl_object_id($file)];
            $this->claimed[] = $path;

            return $path;
        }
        $path = $file->store($directory, 'r2');
        if ($path === false) {
            throw new RuntimeException('تعذر حفظ المرفق؛ لم يتم تسجيل العملية.');
        }
        $this->stored[] = $path;

        return $path;
    }

    /**
     * Best-effort R2 cleanup that never throws: a failing delete must not
     * replace the original business exception (disk has throw => true).
     */
    public function deleteStoredQuietly(): void
    {
        if ($this->stored === []) {
            return;
        }

        $paths = $this->stored;
        $this->stored = [];

        $this->deletePathsQuietly($paths);
    }

    private function deletePathsQuietly(array $paths): void
    {
        if ($paths === []) {
            return;
        }
        try {
            Storage::disk('r2')->delete($paths);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
```

## File: bags-platform/frontend/apps/backoffice/src/pages/admin/operations-mock/orders.tsx
```typescript
import { AdminLoading } from '../../../components/admin/AdminLoading';
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  ORDER_STATUS,
  ORDER_NEXT,
  PAY_STATUS,
  PAY_METHODS,
  RETURN_STATUS,
  REFUND_METHODS,
  BRANCHES,
  ORDERS,
  RETURNS,
  VARIANTS,
  SHIP_COMPANIES,
  type OrderRow,
  type ReturnRow,
} from './data';
import { adminOrdersApi, adminReturnsApi, branchesApi, shippingCompaniesApi, PAYMENT_TRANSITIONS, type AdminOrderListParams, type OrderStatus, type PaymentStatus, type ReturnStatus, type RefundMethod } from '@alfarid/api-client';
import { useRemote, useDebounced, useAction } from './api/useRemote';
import { useFormState } from './api/useFormState';
import { RemoteFeedback } from './api/RemoteFeedback';
import { mockOrder, mockReturn } from './api/mockData';
import { useCan } from '../../../hooks/useCan';
import { mapOrder, mapReturn, branchOptions, toDate, toTime, num } from './api/mappers';
import {
  fmtNum,
  fmtMoney,
  Num,
  Icon,
  Avatar,
  Badge,
  StatusBadge,
  PageHead,
  Card,
  CardHead,
  KPI,
  SearchPill,
  FSelect,
  FChip,
  FDate,
  DataTable,
  Pager,
  RowActions,
  Modal,
  Field,
  TextArea,
  Note,
  KV,
  Timeline,
  Seg,
  Empty,
  useToast,
  type ColumnDef,
} from './ui';

export function PayCell({ o }: { o: OrderRow }) {
  return (
    <div className="leading-tight">
      <div className="text-[12.5px] tx1 font-medium">{PAY_METHODS[o.pay]}</div>
      <div className={'text-[11px] mt-0.5 font-semibold c-' + (PAY_STATUS[o.payStatus]?.tone || 'gray')}>
        {PAY_STATUS[o.payStatus]?.label || o.payStatus}
      </div>
    </div>
  );
}

export function OrderFlags({ o }: { o: OrderRow }) {
  return (
    <div className="flex flex-col gap-1 items-start">
      {o.followUp && (
        <span className="badge t-amber !py-[2px] !text-[10.5px]">
          <Icon name="flag" size={10} sw={2.4} />
          يحتاج متابعة
        </span>
      )}
      {o.needRefund && (
        <span className="badge t-red !py-[2px] !text-[10.5px]">
          <Icon name="returns" size={10} sw={2.4} />
          يتطلب استرداد مبلغ
        </span>
      )}
    </div>
  );
}

/* ---------- Orders list ---------- */
export function OrdersList({ go }: { go: (route: { m: string; id?: number | string }) => void }) {
  const [q, setQ] = useState('');
  const [st, setSt] = useState('');
  const [br, setBr] = useState('');
  const [pm, setPm] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [fu, setFu] = useState(false);
  const [rf, setRf] = useState(false);
  const toast = useToast();
  const [page, setPage] = useState(1);
  const dq = useDebounced(q);

  // Reset to page 1 upon filter mutation
  useEffect(() => { setPage(1); }, [dq, st, br, pm, from, to, fu, rf]);

  const params: AdminOrderListParams = {
    page,
    status: (st || undefined) as OrderStatus | undefined,
    search: dq || undefined,
    allocated_branch_id: br ? Number(br) : undefined,
    payment_method: (pm === 'bank' ? 'bank_transfer' : pm || undefined) as AdminOrderListParams['payment_method'],
    date_from: from || undefined,
    date_to: to || undefined,
    needs_attention: fu ? true : undefined,
    requires_refund: rf ? true : undefined,
  };

  const { data, loading, error, reload, usingMock } = useRemote(
    (signal) => adminOrdersApi.list(params, signal),
    [page, st, dq, br, pm, from, to, fu, rf],
    {
      mock: () => ({ data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: ORDERS.length, from: 1, to: ORDERS.length } } as any),
      onError: (e) => toast(e.message, 'red'),
    },
  );
  const rows = useMemo(
    () => (usingMock ? ORDERS : (data?.data ?? []).map(mapOrder)),
    [data, usingMock],
  );
  const branches = useRemote((s) => branchesApi.list({}, s).then((r) => r.data), []);
  const branchOpts = useMemo(() => branchOptions(branches.data ?? []), [branches.data]);
  const kpi = useRemote(async (signal) => {
    const s = await adminOrdersApi.stats(signal);
    return { pending: s.pending, processing: s.processing, follow: s.needs_attention, refund: s.requires_refund };
  }, []);

  const anyFilter = q || st || br || pm || from || to || fu || rf;
  const reset = () => {
    setQ('');
    setSt('');
    setBr('');
    setPm('');
    setFrom('');
    setTo('');
    setFu(false);
    setRf(false);
  };

  const cols: ColumnDef<OrderRow>[] = [
    { h: 'رقم الطلب', r: (o) => <span className="num font-semibold tx2">{o.no}</span> },
    {
      h: 'العميل',
      r: (o) => (
        <div className="flex items-center gap-2.5 min-w-0 max-w-[260px]">
          <Avatar name={o.customer} tone={o.id} size={28} />
          <div className="leading-tight min-w-0 flex-1">
            <div className="font-semibold tx1 flex items-center gap-1.5">
              {o.customer}
              {o.guest && <span className="badge t-gray !py-0 !px-1.5 !text-[10px]">زائر</span>}
            </div>
            <div className="text-[11px] tx3 num ltr mt-0.5">{o.phone}</div>
          </div>
        </div>
      ),
    },
    {
      h: 'القناة',
      r: (o) => (
        <span className="v-sub px-2.5 py-1 rounded-full text-[11px] tx2 whitespace-nowrap">
          {o.channel}
        </span>
      ),
    },
    { h: 'الحالة', r: (o) => <StatusBadge map={ORDER_STATUS} value={o.status} /> },
    { h: 'الدفع', r: (o) => <PayCell o={o} /> },
    {
      h: 'الفرع المخصص',
      r: (o) =>
        o.branch ? (
          <span className="tx2 whitespace-nowrap">{o.branch}</span>
        ) : (
          <span className="tx3 text-[11.5px]">غير مخصص</span>
        ),
    },
    {
      h: 'الإجمالي',
      r: (o) => <span className="font-semibold tx1 whitespace-nowrap">{fmtMoney(o.total)}</span>,
    },
    {
      h: 'تاريخ الإنشاء',
      r: (o) => (
        <div className="leading-tight tx2">
          <Num>{o.date}</Num>
          <div className="text-[11px] tx3 num">{o.time}</div>
        </div>
      ),
    },
    { h: 'تنبيهات', r: (o) => <OrderFlags o={o} /> },
    {
      h: '',
      r: (o) => (
        <RowActions>
          <button className="btn btn-soft btn-xs" onClick={() => go({ m: 'orders', id: o.id })}>
            عرض التفاصيل
          </button>
        </RowActions>
      ),
    },
  ];

  return (
    <div className="space-y-5">
      <RemoteFeedback state={{ loading: false, error: null, usingMock, reload }} />
      <PageHead title="الطلبات" sub="متابعة طلبات كل القنوات، وتحديث حالتها ودفعها وتخصيص مخزونها." />
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 stg stg-2">
        <KPI
          hero
          label="طلبات قيد المراجعة"
          icon="clock"
          value={kpi.data?.pending ?? '—'}
          foot="تحتاج تأكيدًا أو إلغاء"
        />
        <KPI
          label="يحتاج متابعة"
          icon="flag"
          value={kpi.data?.follow ?? '—'}
          foot="معلَّمة للمتابعة"
        />
        <KPI
          label="يتطلب استرداد مبلغ"
          icon="returns"
          value={kpi.data?.refund ?? '—'}
          foot="ملغاة أو مرتجعة ومدفوعة"
        />
        <KPI
          label="قيد التجهيز"
          icon="store"
          value={kpi.data?.processing ?? '—'}
          foot="بانتظار تخصيص المخزون"
        />
      </div>
      <Card stg={3}>
        <div className="flex flex-wrap items-center gap-2 mb-4">
          <SearchPill value={q} onChange={setQ} placeholder="رقم الطلب، العميل، الهاتف" w="w-48" />
          <FSelect
            value={st}
            onChange={setSt}
            placeholder="كل الحالات"
            options={Object.entries(ORDER_STATUS).map(([v, s]) => ({ value: v, label: s.label }))}
          />
          <FSelect value={br} onChange={setBr} placeholder="كل الفروع" options={branchOpts.length ? branchOpts : []} />
          <FSelect
            value={pm}
            onChange={setPm}
            placeholder="طريقة الدفع"
            options={Object.entries(PAY_METHODS).map(([v, l]) => ({ value: v, label: l }))}
          />
          <FDate label="من" value={from} onChange={setFrom} />
          <FDate label="إلى" value={to} onChange={setTo} />
          <FChip on={fu} onClick={() => setFu(!fu)} icon="flag">
            يحتاج متابعة
          </FChip>
          <FChip on={rf} onClick={() => setRf(!rf)} icon="returns">
            يتطلب استرداد
          </FChip>
          {anyFilter && (
            <button className="btn btn-ghost btn-xs" onClick={reset}>
              <Icon name="x" size={12} />
              مسح الفلاتر
            </button>
          )}
        </div>
        <DataTable
          rows={loading ? [] : rows}
          empty={loading ? <AdminLoading compact /> : error && !usingMock
            ? <Empty icon="alert" title="تعذّر تحميل الطلبات" text={error.message}
                action={<button className="btn btn-soft btn-sm" onClick={reload}>إعادة المحاولة</button>} />
            : undefined}
          onRow={(o: OrderRow) => go({ m: 'orders', id: o.id })}
          cols={cols}
        />
        <Pager total={data?.meta.total ?? rows.length} per={data?.meta.per_page ?? 20} page={page} onPage={setPage} />
      </Card>
    </div>
  );
}

/* ---------- Order detail ---------- */
export function OrderDetail({
  id,
  go,
}: {
  id: number | string;
  go: (route: { m: string; id?: number | string }) => void;
}) {
  const toast = useToast();
  const mayUpdate = useCan('orders.update');
  const mayPay = useCan('orders.payment');
  const mayAllocate = useCan('orders.allocate');
  const { busy, run } = useAction();
  const numId = Number(id);
  const remote = useRemote(
    (s) => adminOrdersApi.show(numId, s), [numId], { mock: () => mockOrder(numId), onError: (e) => toast(e.message, 'red') },
  );
  const { data: order, loading, setData: setOrder } = remote;
  const shipCos = useRemote((s) => shippingCompaniesApi.list({ is_active: true }, s).then((p) => p.data), []);
  const branchesList = useRemote((s) => branchesApi.list({}, s).then((r) => r.data), []);
  const [statusNotes, setStatusNotes] = useState('');
  const [payNotes, setPayNotes] = useState('');
  const [refundRef, setRefundRef] = useState('');
  const [modal, setModal] = useState<string | null>(null);
  const [newSt, setNewSt] = useState('');
  const [newPay, setNewPay] = useState('');
  const [allocBranch, setAllocBranch] = useState('');
  const [carrier, setCarrier] = useState('');
  const [awb, setAwb] = useState('');
  useEffect(() => { if (order) setNewPay(mapOrder(order).payStatus); }, [order]);

  if (loading || !order) return <><RemoteFeedback state={remote} />{!loading && !remote.error && <Empty title="الطلب غير موجود" />}</>;
  const o = mapOrder(order);

  const saveStatus = async (status: OrderStatus, notes: string) => {
    if (remote.usingMock) return toast('البيانات المعروضة تجريبية — لم يتم الحفظ', 'red');
    const r = await run(() => adminOrdersApi.updateStatus(order.id, { status: status as any, notes: notes || undefined }),
      { toast, success: 'تم تحديث حالة الطلب' });
    if (r) { setOrder(r); setModal(null); }
  };

  const savePayment = async (payment_status: PaymentStatus, notes: string) => {
    if (remote.usingMock) return toast('البيانات المعروضة تجريبية — لم يتم الحفظ', 'red');
    if (!notes.trim()) return toast('الملاحظات إلزامية للتعديل اليدوي على الدفع', 'red');
    if (payment_status === 'refunded' && !refundRef.trim()) return toast('مرجع الاسترداد إلزامي عند التحويل إلى مسترد', 'red');
    const r = await run(() => adminOrdersApi.updatePayment(order.id, {
      payment_status,
      notes,
      ...(payment_status === 'refunded' ? { refund_reference: refundRef.trim() } : {}),
    }),
      { toast, success: 'تم تحديث حالة الدفع' });
    if (r) { setOrder(r); setModal(null); setRefundRef(''); }
  };

  const saveAlloc = async (branchId: string) => {
    if (remote.usingMock) return toast('البيانات المعروضة تجريبية — لم يتم الحفظ', 'red');
    const r = await run(() => adminOrdersApi.allocate(order.id, branchId ? Number(branchId) : null),
      { toast, success: 'تم تخصيص الطلب' });
    if (r) { setOrder(r); setModal(null); }
  };
  const deAlloc = async () => {
    if (remote.usingMock) return toast('البيانات المعروضة تجريبية — لم يتم الحفظ', 'red');
    const r = await run(() => adminOrdersApi.deallocate(order.id), { toast, success: 'تم إلغاء التخصيص' });
    if (r) setOrder(r);
  };
  const saveShipment = async (companyId: string, awbNum: string) => {
    if (remote.usingMock) return toast('البيانات المعروضة تجريبية — لم يتم الحفظ', 'red');
    const r = await run(() => adminOrdersApi.createShipment(order.id, { shipping_company_id: Number(companyId), awb_number: awbNum || null }),
      { toast, success: 'تم إنشاء الشحنة' });
    if (r) { setModal(null); remote.reload(); }
  };

  const items = (order.items ?? []).map((it) => ({
    v: { name: it.product_name, sku: it.sku, price: num(it.unit_price) },
    qty: it.qty,
    disc: num(it.discount_amount),
    tax: num(it.tax_amount),
    branch: o.branch,
    line: num(it.line_total),
  }));
  const displayItems = items;
  const lineTotal = (it: any) => it.line;
  const subtotal = order.subtotal != null ? num(order.subtotal) : displayItems.reduce((s: number, it: any) => s + (it.v.price ?? 0) * it.qty, 0);
  const disc = order.discount_amount != null ? num(order.discount_amount) : displayItems.reduce((s: number, it: any) => s + (it.disc ?? 0), 0);
  const ship = order.shipping_cost != null ? num(order.shipping_cost) : 0;
  const codFee = num(order.cod_fee);
  const tax = order.tax_amount != null ? num(order.tax_amount) : 0;
  const total = order.grand_total != null ? num(order.grand_total) : subtotal - disc + ship + codFee + tax;
  const statusOpts = (ORDER_NEXT[o.status] || []).filter((s) => s !== 'shipped');
  const next = ORDER_NEXT[o.status] || [];
  const canShip = next.includes('shipped');
  const terminal = next.length === 0;
  const canAllocate = ['confirmed', 'processing'].includes(order.status);

  const timeline = (order.status_history ?? []).map((h) => ({
    title: (ORDER_STATUS as any)[h.to_status]?.label ?? h.to_status,
    date: `${toDate(h.at)} ${toTime(h.at)}`,
    note: h.notes ?? undefined,
    from: h.from_status,
    to: h.to_status,
  }));
  const history = timeline.map(t => ({ from: t.from, to: t.to, note: t.note, by: '', at: t.date }));

  return (
    <div className="space-y-5">
      <RemoteFeedback state={remote} />
      <PageHead
        back={{ label: 'كل الطلبات', onClick: () => go({ m: 'orders' }) }}
        title={
          <span className="flex items-center gap-3 flex-wrap">
            <span className="num">{o.no}</span>
            <StatusBadge map={ORDER_STATUS} value={o.status} />
          </span>
        }
        sub={
          <span>
            أُنشئ <Num>{o.date} · {o.time}</Num> عبر {o.channel}
          </span>
        }
        actions={
          <>
            <button
              className="btn btn-soft"
              disabled={!mayUpdate || remote.usingMock || terminal}
              onClick={() => {
                setNewSt('');
                setModal('status');
              }}
            >
              <Icon name="refresh" size={14} />
              تغيير الحالة
            </button>
            <button className="btn btn-soft" disabled={!mayPay || remote.usingMock} onClick={() => setModal('pay')}>
              <Icon name="card" size={14} />
              تحديث الدفع
            </button>
            {o.branch ? (
              <button
                className="btn btn-soft"
                disabled={!mayAllocate || remote.usingMock || busy || !canAllocate}
                onClick={deAlloc}
              >
                <Icon name="layers" size={14} />
                فك التخصيص
              </button>
            ) : (
              <button className="btn btn-soft" disabled={!mayAllocate || remote.usingMock || busy || !canAllocate} onClick={() => setModal('alloc')}>
                <Icon name="layers" size={14} />
                تخصيص المخزون
              </button>
            )}
            <button
              className="btn btn-dark"
              disabled={!mayUpdate || remote.usingMock || busy || !canShip}
              onClick={() => setModal('ship')}
            >
              <Icon name="truck" size={14} />
              إنشاء شحنة
            </button>
          </>
        }
      />

      {(o.followUp || o.needRefund) && (
        <div className="flex flex-wrap gap-2 stg stg-2">
          {o.followUp && (
            <Note tone="amber" icon="flag">
              هذا الطلب معلَّم <b>«يحتاج متابعة»</b>.
            </Note>
          )}
          {o.needRefund && (
            <Note tone="red" icon="returns">
              هذا الطلب <b>«يتطلب استرداد مبلغ»</b> — أنشئ مرتجعًا أو حدّث حالة الدفع إلى مسترد.
            </Note>
          )}
        </div>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-12 gap-5">
        <div className="lg:col-span-8 space-y-5">
          <Card stg={2}>
            <CardHead
              title="المنتجات"
              sub="بيانات المنتج كما كانت وقت الطلب"
              right={
                <span className="text-[11.5px] tx3">
                  <Num>{items.length}</Num> بنود
                </span>
              }
            />
            <DataTable
              compact
              cols={[
                {
                  h: 'المنتج',
                  r: (it) => (
                    <div className="flex items-center gap-2.5 min-w-0 max-w-[260px]">
                      <span className="w-9 h-9 rounded-[10px] v-sub flex items-center justify-center tx3">
                        <Icon name="box" size={15} />
                      </span>
                      <div className="leading-tight min-w-0 flex-1">
                        <div className="font-semibold tx1">{it.v.name}</div>
                        <div className="text-[11px] tx3 num ltr mt-0.5">{it.v.sku}</div>
                      </div>
                    </div>
                  ),
                },
                { h: 'الكمية', align: 'center', r: (it) => <Num className="font-semibold">{it.qty}</Num> },
                { h: 'سعر الوحدة', r: (it) => fmtMoney(it.v.price) },
                {
                  h: 'الخصم',
                  r: (it) =>
                    it.disc ? <span className="c-red">{fmtMoney(-it.disc)}</span> : <span className="tx3">—</span>,
                },
                { h: 'الضريبة', r: (it) => <span className="tx2">{fmtMoney(it.tax)}</span> },
                { h: 'إجمالي البند', r: (it) => <span className="font-semibold">{fmtMoney(lineTotal(it))}</span> },
                {
                  h: 'فرع البند',
                  r: (it) =>
                    it.branch ? (
                      <span className="text-[11.5px] tx2">{it.branch}</span>
                    ) : (
                      <span className="text-[11.5px] tx3">غير مخصص</span>
                    ),
                },
              ]}
              rows={displayItems}
            />
          </Card>

          <Card stg={3}>
            <CardHead title="سجل التغييرات" sub="كل تغيير في حالة الطلب ومن قام به" />
            <Timeline
              items={history.map((h) => ({
                icon: h.to === 'pending' ? 'plus' : 'refresh',
                body: (
                  <div>
                    <div className="flex items-center gap-2 flex-wrap">
                      {h.from && (
                        <>
                          <StatusBadge map={ORDER_STATUS} value={h.from} />
                          <Icon name="arrowL" size={13} className="tx3" />
                        </>
                      )}
                      <StatusBadge map={ORDER_STATUS} value={h.to} />
                    </div>
                    {h.note && <div className="text-[12.5px] tx1 mt-2">{h.note}</div>}
                    <div className="text-[11px] tx3 mt-1 flex items-center gap-2">
                      <span>{h.by}</span>
                      <span>·</span>
                      <Num>{h.at}</Num>
                    </div>
                  </div>
                ),
              }))}
            />
          </Card>
        </div>

        <div className="lg:col-span-4 space-y-5">
          <div className="v-card p-4 stg stg-2">
            <div className="v-orange rounded-[18px] p-4">
              <div className="flex items-center justify-between text-[12.5px] text-white/90">
                <span>إجمالي الطلب</span>
                <span className="px-2 py-0.5 rounded-full text-[10.5px] font-semibold bg-white/20">
                  {PAY_METHODS[o.pay]}
                </span>
              </div>
              <div className="font-display text-[30px] font-bold num mt-2 mb-1 ltr">
                {fmtNum(total)} <span className="text-[14px] opacity-80">EGP</span>
              </div>
              <div className="text-[11.5px] text-white/85">
                حالة الدفع: <b>{PAY_STATUS[o.payStatus]?.label || o.payStatus}</b>
              </div>
            </div>
            <div className="px-1 pt-4">
              <KV
                items={[
                  ['المجموع الفرعي', fmtMoney(subtotal)],
                  ['الخصم', <span className="c-red">{fmtMoney(-disc)}</span>],
                  [
                    'كود الكوبون',
                    <span className="badge t-orange num !text-[11px]" style={{ letterSpacing: '.04em' }}>
                      <Icon name="ticket" size={11} />
                      {order.coupon_code ?? '—'}
                    </span>,
                  ],
                  ['الشحن', fmtMoney(ship)],
                  ['رسوم الدفع عند الاستلام', fmtMoney(codFee)],
                  ['الضريبة', fmtMoney(tax)],
                ]}
              />
              <div className="flex items-center justify-between v-bt mt-3 pt-3">
                <span className="font-semibold tx1">الإجمالي</span>
                <span className="font-display font-bold text-[16px]">{fmtMoney(total)}</span>
              </div>
            </div>
          </div>

          <Card stg={3}>
            <CardHead title="العميل" className="mb-3" />
            <div className="flex items-center gap-3 mb-4">
              <Avatar name={o.customer} tone={o.id} size={40} />
              <div>
                <div className="font-semibold tx1">{o.customer}</div>
                <div className="text-[11.5px] tx3 num ltr">{o.phone}</div>
              </div>
            </div>
            <KV
              items={[
                [
                  'نوع العميل',
                  <Badge tone={o.guest ? 'gray' : o.ctype === 'جملة' ? 'violet' : 'blue'} dot={false}>
                    {o.ctype}
                  </Badge>,
                ],
                o.guest && [
                  'بيانات الزائر',
                  <span className="text-[12px]">
                    بدون حساب · <span className="num">{order.guest_email ?? '—'}</span>
                  </span>,
                ],
              ]}
            />
          </Card>

          <Card stg={4}>
            <CardHead title="التوصيل" className="mb-3" />
            <KV
              items={[
                ['المدينة', o.city],
                [
                  'العنوان',
                  <span className="text-[12px] leading-relaxed">
                    {order.shipping_address_line ?? '—'}
                  </span>,
                ],
              ]}
            />
            <div className="v-sub px-3.5 py-2.5 mt-3 text-[12px] tx2 flex gap-2">
              <Icon name="info" size={14} style={{ marginTop: 2 }} />
              {order.shipping_notes ?? '—'}
            </div>
          </Card>

          <Card stg={5}>
            <CardHead title="التنفيذ" className="mb-3" />
            <KV
              items={[
                ['حالة الطلب', <StatusBadge map={ORDER_STATUS} value={o.status} />],
                ['الفرع المخصص', o.branch || <span className="tx3">غير مخصص</span>],
              ]}
            />
            {!terminal && (
              <div className="mt-4">
                <div className="lbl mb-2">الانتقالات المتاحة</div>
                <div className="flex flex-wrap gap-1.5">
                  {next.map((n) => (
                    <StatusBadge key={n} map={ORDER_STATUS} value={n} />
                  ))}
                </div>
              </div>
            )}
          </Card>
        </div>
      </div>

      {/* status modal */}
      <Modal
        open={modal === 'status'}
        onClose={() => setModal(null)}
        title="تغيير حالة الطلب"
        icon="refresh"
        sub="تظهر فقط الحالات المسموح الانتقال إليها من الحالة الحالية."
        footer={
          <>
            <button className="btn btn-soft" onClick={() => setModal(null)}>
              إلغاء
            </button>
            <button
              className="btn btn-dark"
              disabled={!newSt || busy}
              onClick={() => saveStatus(newSt as OrderStatus, statusNotes)}
            >
              حفظ الحالة
            </button>
          </>
        }
      >
        <div className="space-y-4">
          <div className="v-sub p-3 flex items-center gap-2 text-[12px]">
            <span className="tx2">الحالة الحالية</span>
            <StatusBadge map={ORDER_STATUS} value={o.status} />
          </div>
          <Field label="الحالة الجديدة" req>
            <div className="grid grid-cols-2 gap-2">
              {statusOpts.map((n) => (
                <button
                  key={n}
                  type="button"
                  onClick={() => setNewSt(n)}
                  className="v-sub p-3 flex items-center justify-between text-[12.5px] font-medium transition"
                  style={
                    newSt === n ? { outline: '2px solid var(--accent)', background: 'var(--bg-card)' } : {}
                  }
                >
                  <StatusBadge map={ORDER_STATUS} value={n} />
                  {newSt === n && <Icon name="check" size={15} className="accent" />}
                </button>
              ))}
            </div>
          </Field>
          {newSt === 'shipped' && (
            <Note tone="amber" icon="alert">
              قد ترفض العملية إن لم توجد شحنة أو لم يُخصص المخزون — يُفضّل استخدام «إنشاء شحنة».
            </Note>
          )}
          <TextArea label="ملاحظات" max={500} placeholder="اختياري" value={statusNotes} onChange={setStatusNotes} />
        </div>
      </Modal>

      {/* payment modal */}
      <Modal
        open={modal === 'pay'}
        onClose={() => setModal(null)}
        title="تحديث حالة الدفع"
        icon="card"
        footer={
          <>
            <button className="btn btn-soft" onClick={() => setModal(null)}>
              إلغاء
            </button>
            <button
              className="btn btn-dark"
              disabled={busy}
              onClick={() => savePayment(newPay as PaymentStatus, payNotes)}
            >
              حفظ
            </button>
          </>
        }
      >
        <div className="space-y-4">
          <Field label="حالة الدفع" req>
            <div className="grid grid-cols-2 gap-2">
              {(PAYMENT_TRANSITIONS[order.payment_status as PaymentStatus] ?? Object.keys(PAY_STATUS)).map((k) => {
                const s = (PAY_STATUS as any)[k] ?? { label: k, tone: 'gray' };
                return (
                <button
                  key={k}
                  type="button"
                  onClick={() => setNewPay(k)}
                  className="v-sub p-3 flex items-center justify-between transition"
                  style={
                    newPay === k ? { outline: '2px solid var(--accent)', background: 'var(--bg-card)' } : {}
                  }
                >
                  <span className="flex items-center gap-2 text-[12.5px] font-medium tx1">
                    <span className={'w-2 h-2 rounded-full t-' + s.tone} style={{ background: 'currentColor' }} />
                    {s.label}
                    <span className="text-[10.5px] tx3 num">{k}</span>
                  </span>
                  {newPay === k && <Icon name="check" size={15} className="accent" />}
                </button>
                );
              })}
            </div>
          </Field>
          <Note icon="info">يُسجَّل تغيير الحالة فقط — لا يدعم النظام إدخال مبلغ دفعة جزئية.</Note>
          <TextArea label="ملاحظات" max={500} placeholder="إلزامية للتعديل اليدوي" value={payNotes} onChange={setPayNotes} />
          {newPay === 'refunded' && (
            <Field label="مرجع الاسترداد" req>
              <input className="inp" value={refundRef} onChange={(e) => setRefundRef(e.target.value)} placeholder="رقم مرجع الاسترداد الفعلي" maxLength={191} />
            </Field>
          )}
        </div>
      </Modal>

      {/* allocate modal */}
      <Modal
        open={modal === 'alloc'}
        onClose={() => setModal(null)}
        title="تخصيص المخزون"
        icon="layers"
        width={460}
        footer={
          <>
            <button className="btn btn-soft" onClick={() => setModal(null)}>
              إلغاء
            </button>
            <button
              className="btn btn-dark"
              disabled={busy}
              onClick={() => saveAlloc(allocBranch)}
            >
              {allocBranch ? 'تخصيص' : 'تخصيص تلقائي'}
            </button>
          </>
        }
      >
        <div className="space-y-4 pb-2">
          <Field label="الفرع" hint="اترك الحقل فارغًا ليختار النظام الفرع المناسب تلقائيًا.">
            <select className="inp" value={allocBranch} onChange={(e) => setAllocBranch(e.target.value)}>
              <option value="">— تخصيص تلقائي —</option>
              {(branchesList.data ?? []).map((b: { id: number; name: string }) => (
                <option key={b.id} value={String(b.id)}>{b.name}</option>
              ))}
            </select>
          </Field>
        </div>
      </Modal>

      {/* shipping modal */}
      <Modal
        open={modal === 'ship'}
        onClose={() => setModal(null)}
        title="إنشاء شحنة"
        icon="truck"
        width={520}
        footer={
          <>
            <button className="btn btn-soft" onClick={() => setModal(null)}>
              إلغاء
            </button>
            <button
              className="btn btn-dark"
              disabled={!carrier || busy}
              onClick={() => saveShipment(carrier, awb)}
            >
              إنشاء الشحنة
            </button>
          </>
        }
      >
        <div className="space-y-4">
          <Field label="شركة الشحن" req>
            <select className="inp" value={carrier} onChange={(e) => setCarrier(e.target.value)}>
              <option value="">اختر الشركة</option>
              {(shipCos.data ?? []).map((c: { id: number; name: string }) => (
                <option key={c.id} value={String(c.id)}>
                  {c.name}
                </option>
              ))}
            </select>
          </Field>
          <Field
            label="رقم البوليصة (AWB)"
            count={`${awb.length} / 100`}
            hint="اختياري — يمكن تركه إن كانت الشركة تُصدره تلقائيًا."
          >
            <input
              className="inp num"
              dir="ltr"
              maxLength={100}
              value={awb}
              onChange={(e) => setAwb(e.target.value)}
              placeholder="e.g. 7731 4402 9918"
            />
          </Field>
          {!o.branch && (
            <Note tone="amber" icon="alert">
              لم يتم تخصيص مخزون لهذا الطلب بعد — قد يرفض النظام إنشاء الشحنة.
            </Note>
          )}
        </div>
      </Modal>
    </div>
  );
}

/* ================= RETURNS ================= */
export function ReturnsList({ go }: { go: (route: { m: string; id?: number | string }) => void }) {
  const [st, setSt] = useState('');
  const [q, setQ] = useState('');
  const [create, setCreate] = useState(false);
  const toast = useToast();
  const [page, setPage] = useState(1);
  const dq = useDebounced(q);
  useEffect(() => { setPage(1); }, [dq, st]);
  const { data, loading, error, reload, usingMock } = useRemote(
    (signal) => adminReturnsApi.list({ page, search: dq || undefined, status: (st === '' ? undefined : st) as ReturnStatus | undefined }, signal),
    [page, dq, st],
    {
      mock: () => ({ data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: RETURNS.length, from: 1, to: RETURNS.length } } as any),
      onError: (e) => toast(e.message, 'red'),
    },
  );
  const branches = useRemote((s) => branchesApi.list({}, s).then((r) => r.data), []);
  const branchMap = useMemo(() => new Map((branches.data ?? []).map((b: { id: number; name: string }) => [b.id, b.name] as const)), [branches.data]);
  const rows = useMemo(
    () => (usingMock ? RETURNS.filter((r) => (!st || r.status === st) && (!dq || (r.no + r.order + r.customer).includes(dq))) : (data?.data ?? []).map((r: any) => mapReturn(r, (id: number | null) => (id == null ? null : branchMap.get(id) ?? `#${id}`)))),
    [data, usingMock, st, dq, branchMap],
  );
  const counts = useRemote(async (signal) => {
    const qq = (status?: ReturnStatus) => adminReturnsApi.list({ status, page: 1 }, signal).then((r) => r.meta.total);
    const entries = await Promise.all(Object.keys(RETURN_STATUS).map(async (k) => [k, await qq(k as ReturnStatus)] as const));
    const total = await qq(undefined);
    return { total, byStatus: Object.fromEntries(entries) as Record<string, number> };
  }, []);

  return (
    <div className="space-y-5">
      <RemoteFeedback state={{ loading: false, error: null, usingMock, reload }} />
      <PageHead
        title="المرتجعات"
        sub="الموافقة والاستلام ورد المبلغ تتم كخطوات منفصلة لكل مرتجع."
        actions={
          <button className="btn btn-dark" onClick={() => setCreate(true)}>
            <Icon name="plus" size={14} />
            إنشاء مرتجع
          </button>
        }
      />
      <Card stg={2} pad="p-4">
        <div className="flex gap-2 overflow-x-auto">
          <button
            onClick={() => setSt('')}
            className={'v-sub px-4 py-3 rounded-[14px] text-start min-w-[120px] transition ' + (!st ? 'ring-2' : '')}
            style={!st ? { '--tw-ring-color': 'var(--accent)' } as any : {}}
          >
            <div className="text-[11.5px] tx2">الكل</div>
            <div className="font-display text-[22px] font-bold num tx1">{counts.data?.total ?? '—'}</div>
          </button>
          {Object.entries(RETURN_STATUS).map(([k, s]) => (
            <button
              key={k}
              onClick={() => setSt(k)}
              className={'v-sub px-4 py-3 rounded-[14px] text-start min-w-[120px] transition ' + (st === k ? 'ring-2' : '')}
              style={st === k ? { '--tw-ring-color': 'var(--accent)' } as any : {}}
            >
              <div className={'text-[11.5px] font-medium c-' + s.tone}>{s.label}</div>
              <div className="font-display text-[22px] font-bold num tx1">
                {counts.data?.byStatus[k] ?? '—'}
              </div>
            </button>
          ))}
        </div>
      </Card>
      <Card stg={3}>
        <div className="flex flex-wrap items-center gap-2 mb-4">
          <SearchPill value={q} onChange={setQ} placeholder="رقم المرتجع أو الطلب" />
        </div>
        <DataTable
          onRow={(r: ReturnRow) => go({ m: 'returns', id: r.id })}
          rows={loading ? [] : rows}
          empty={loading ? <AdminLoading compact /> : error && !usingMock
            ? <Empty icon="alert" title="تعذّر تحميل المرتجعات" text={error.message}
                action={<button className="btn btn-soft btn-sm" onClick={reload}>إعادة المحاولة</button>} />
            : undefined}
          cols={[
            { h: 'رقم المرتجع', r: (r) => <span className="num font-semibold tx1">{r.no}</span> },
            {
              h: 'الطلب الأصلي',
              r: (r) => (
                <span className="num tx2 underline decoration-dotted underline-offset-4">{r.order}</span>
              ),
            },
            {
              h: 'العميل',
              r: (r) => (
                <div className="flex items-center gap-2">
                  <Avatar name={r.customer} tone={r.id} size={26} />
                  <span className="font-medium tx1">{r.customer}</span>
                </div>
              ),
            },
            {
              h: 'الفرع',
              r: (r) =>
                r.branch ? <span className="tx2">{r.branch}</span> : <span className="tx3 text-[11.5px]">يحدد عند الموافقة</span>,
            },
            { h: 'الحالة', r: (r) => <StatusBadge map={RETURN_STATUS} value={r.status} /> },
            {
              h: 'طريقة الرد',
              r: (r) =>
                r.method ? (
                  <span className="tx2">{REFUND_METHODS[r.method] || r.method}</span>
                ) : (
                  <span className="tx3">—</span>
                ),
            },
            { h: 'إجمالي الاسترداد', r: (r) => <span className="font-semibold">{fmtMoney(r.total)}</span> },
            { h: 'تاريخ الإنشاء', r: (r) => <Num className="tx2">{r.date}</Num> },
          ]}
        />
        <Pager total={data?.meta.total ?? rows.length} per={data?.meta.per_page ?? 20} page={page} onPage={setPage} />
      </Card>
      <CreateReturnModal open={create} onClose={() => setCreate(false)} onSaved={() => { reload(); counts.reload(); }} />
    </div>
  );
}

export function CreateReturnModal({ open, onClose, onSaved }: { open: boolean; onClose: () => void; onSaved?: () => void }) {
  const toast = useToast();
  const { busy, run } = useAction();
  const f = useFormState({ reason: '', refund_method: '' });
  const [orderId, setOrderId] = useState('');
  const [orderQuery, setOrderQuery] = useState('');
  const dqOrder = useDebounced(orderQuery);
  const [lines, setLines] = useState([{ item: '', qty: 1, reason: '' }]);
  const set = (i: number, k: string, v: any) =>
    setLines(lines.map((l, j) => (j === i ? { ...l, [k]: v } : l)));

  const deliveredOrders = useRemote(
    (signal) => adminOrdersApi.list({ search: dqOrder || undefined, status: 'delivered' as OrderStatus }, signal).then((r) => r.data),
    [dqOrder],
    { enabled: open, mock: () => ORDERS.filter((o) => o.status === 'delivered').map((o) => ({ id: o.id, order_number: o.no } as any)) },
  );
  const selectedOrder = useRemote(
    (signal) => (orderId ? adminOrdersApi.show(Number(orderId), signal) : Promise.resolve(null as any)),
    [orderId],
    { enabled: open && !!orderId, mock: () => mockOrder(Number(orderId)) },
  );
  const orderItems = (selectedOrder.data as any)?.items ?? [];
  useEffect(() => { setLines([{ item: '', qty: 1, reason: '' }]); }, [orderId]);

  const submit = async () => {
    if (selectedOrder.usingMock || deliveredOrders.usingMock) return toast('البيانات المعروضة تجريبية — لم يتم الحفظ', 'red');
    if (!orderId) return toast('اختر الطلب الأصلي', 'red');
    if (!f.v.reason.trim()) { f.setErrors({ reason: 'سبب الإرجاع مطلوب' } as any); return toast('سبب الإرجاع مطلوب', 'red'); }
    const items = lines
      .filter((l) => l.item && Number(l.qty) > 0)
      .map((l) => ({ order_item_id: Number(l.item), requested_qty: Number(l.qty), item_reason: l.reason || undefined }));
    if (!items.length) return toast('أضف بندًا واحدًا على الأقل بكمية صحيحة', 'red');
    const r = await run(() => adminReturnsApi.create({
      order_id: Number(orderId),
      reason: f.v.reason,
      refund_method: (f.v.refund_method === 'bank' ? 'bank_transfer' : f.v.refund_method === 'original' ? 'original_payment' : f.v.refund_method || undefined) as RefundMethod | undefined,
      items,
    }), { toast, success: 'تم إنشاء طلب المرتجع', onFieldErrors: (e) => f.setErrors(e as any) });
    if (r) { onSaved?.(); onClose(); toast(`تم إنشاء المرتجع ${(r as any).return_number ?? ''}`, 'green'); }
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="إنشاء مرتجع"
      icon="returns"
      width={680}
      sub="يُنشأ بحالة «قيد المراجعة»؛ يُحسب مبلغ الاسترداد من النظام."
      footer={
        <>
          <button className="btn btn-soft" onClick={onClose}>
            إلغاء
          </button>
          <button
            className="btn btn-dark"
            disabled={!orderId || busy}
            onClick={submit}
          >
            إنشاء المرتجع
          </button>
        </>
      }
    >
      <div className="space-y-4">
        <div className="grid grid-cols-2 gap-3">
          <Field label="الطلب الأصلي" req err={(f.errors as any).order_id}>
            <input className="inp" value={orderQuery} onChange={(e) => setOrderQuery(e.target.value)} placeholder="ابحث برقم الطلب" />
            <select className="inp mt-2" value={orderId} onChange={(e) => setOrderId(e.target.value)}>
              <option value="">اختر من النتائج</option>
              {(deliveredOrders.data ?? []).map((o: any) => (
                <option key={o.id} value={String(o.id)}>
                  {o.order_number ?? o.no} — {(o.customer as any)?.name ?? o.customer ?? ''}
                </option>
              ))}
            </select>
          </Field>
          <Field label="طريقة الاسترداد" hint="اختيارية — يمكن تحديدها عند رد المبلغ." err={(f.errors as any).refund_method}>
            <select className="inp" value={f.v.refund_method} onChange={f.on('refund_method' as any)}>
              <option value="">لم تُحدد</option>
              {Object.entries(REFUND_METHODS).map(([k, l]) => (
                <option key={k} value={k}>
                  {l}
                </option>
              ))}
            </select>
          </Field>
        </div>
        <Field label="سبب الإرجاع" req err={(f.errors as any).reason}>
          <TextArea label="" req max={2000} placeholder="اشرح سبب الإرجاع" value={f.v.reason} onChange={(v) => f.set('reason' as any, v)} />
        </Field>
        <div>
          <div className="flex items-center justify-between mb-2">
            <span className="lbl">
              البنود <span className="accent">*</span>
            </span>
            <span className="text-[11px] tx3">
              من بند واحد حتى <Num>50</Num> بندًا · <Num>{lines.length}</Num>/50
            </span>
          </div>
          <div className="v-sub p-2 space-y-2">
            <div className="grid grid-cols-12 gap-2 px-2 pt-1 text-[11px] tx3">
              <span className="col-span-5">بند الطلب</span>
              <span className="col-span-2">الكمية</span>
              <span className="col-span-4">سبب البند</span>
            </div>
            {lines.map((l, i) => (
              <div key={i} className="grid grid-cols-12 gap-2 items-center">
                <select
                  className="inp inp-sm col-span-5 !bg-[var(--bg-card)]"
                  disabled={!orderId}
                  value={l.item}
                  onChange={(e) => set(i, 'item', e.target.value)}
                >
                  <option value="">{orderId ? 'اختر بندًا' : 'اختر الطلب أولًا'}</option>
                  {orderItems.map((it: any) => (
                    <option key={String(it.product_variant_id ?? it.id)} value={String(it.id ?? it.product_variant_id)}>
                      {it.product_name ?? it.name} · {it.sku}
                    </option>
                  ))}
                </select>
                <input
                  type="number"
                  min="1"
                  className="inp inp-sm col-span-2 num !bg-[var(--bg-card)]"
                  value={l.qty}
                  onChange={(e) => set(i, 'qty', e.target.value)}
                />
                <input
                  className="inp inp-sm col-span-4 !bg-[var(--bg-card)]"
                  placeholder="اختياري"
                  value={l.reason}
                  onChange={(e) => set(i, 'reason', e.target.value)}
                />
                <button
                  className="icon-btn !w-7 !h-7 col-span-1 justify-self-center"
                  disabled={lines.length === 1}
                  onClick={() => setLines(lines.filter((_, j) => j !== i))}
                >
                  <Icon name="trash" size={13} />
                </button>
                {(f.errors as any)[`items.${i}.requested_qty`] && (
                  <span className="col-span-12 text-[11px] c-red">{(f.errors as any)[`items.${i}.requested_qty`]}</span>
                )}
              </div>
            ))}
            <button
              className="btn btn-ghost btn-sm w-full"
              disabled={lines.length >= 50}
              onClick={() => setLines([...lines, { item: '', qty: 1, reason: '' }])}
            >
              <Icon name="plus" size={13} />
              إضافة بند
            </button>
          </div>
        </div>
      </div>
    </Modal>
  );
}

export function ReturnDetail({
  id,
  go,
}: {
  id: number | string;
  go: (route: { m: string; id?: number | string }) => void;
}) {
  const toast = useToast();
  const mayApprove = useCan('returns.approve');
  const mayReceive = useCan('returns.receive');
  const mayRefund = useCan('returns.refund');
  const mayUpdate = useCan('returns.update');
  const { busy, run } = useAction();
  const numId = Number(id);
  const remote = useRemote((s) => adminReturnsApi.show(numId, s), [numId], { mock: () => mockReturn(numId), onError: (e) => toast(e.message, 'red') });
  const { data: ret, setData: setRet, loading } = remote;
  const branches = useRemote((s) => branchesApi.list({}, s).then((r) => r.data), []);
  const branchMap = useMemo(() => new Map((branches.data ?? []).map((b: { id: number; name: string }) => [b.id, b.name] as const)), [branches.data]);
  const fb = useMemo(() => (ret ? mapReturn(ret, (bid) => (bid == null ? null : branchMap.get(bid) ?? `#${bid}`)) : null), [ret, branchMap]);
  const base = RETURNS.find((r) => r.id === numId) || RETURNS[0];
  const r = useMemo(() => fb ?? base, [fb, base]);
  const [modal, setModal] = useState<string | null>(null);
  const [approveBranch, setApproveBranch] = useState('');
  const [rejectMode, setRejectMode] = useState('rejected');
  const [approveQtys, setApproveQtys] = useState<Record<number, number>>({});
  const [goodQtys, setGoodQtys] = useState<Record<number, number | undefined>>({});
  const [damagedQtys, setDamagedQtys] = useState<Record<number, number | undefined>>({});
  const [refundMethod, setRefundMethod] = useState('');
  const [refundRef, setRefundRef] = useState('');
  const [rejectNotes, setRejectNotes] = useState('');
  const [approveNotes, setApproveNotes] = useState('');
  useEffect(() => { if (ret) setApproveBranch(ret.branch_id ? String(ret.branch_id) : ''); }, [ret]);

  const approve = async () => {
    if (!ret || remote.usingMock) return;
    if (!approveBranch) return toast('اختر الفرع المستلم', 'red');
    const items = (ret.items ?? []).map((it) => ({ id: it.id, approved_qty: approveQtys[it.id] ?? it.requested_qty }));
    const res = await run(() => adminReturnsApi.approve(ret.id, {
      branch_id: Number(approveBranch),
      items,
      admin_notes: approveNotes || undefined,
    }), { toast, success: 'تمت الموافقة على المرتجع' });
    if (res) { setRet(res); setModal(null); }
  };

  const receive = async () => {
    if (!ret || remote.usingMock) return;
    const missing = (ret.items ?? []).filter((it) => goodQtys[it.id] === undefined || damagedQtys[it.id] === undefined);
    if (missing.length > 0) return toast('أدخل الكمية السليمة والتالفة لكل بند بعد الفحص الفعلي', 'red');
    const items = (ret.items ?? []).map((it) => ({ id: it.id, received_good_qty: goodQtys[it.id] ?? 0, received_damaged_qty: damagedQtys[it.id] ?? 0 }));
    const res = await run(() => adminReturnsApi.receive(ret.id, { items }), { toast, success: 'تم استلام المرتجع وفحصه' });
    if (res) { setRet(res); setModal(null); }
  };

  const refund = async () => {
    if (!ret || remote.usingMock) return;
    const res = await run(() => adminReturnsApi.refund(ret.id, { refund_method: (refundMethod === 'bank' ? 'bank_transfer' : refundMethod === 'original' ? 'original_payment' : refundMethod || ret.refund_method || undefined) as RefundMethod | undefined, refund_reference: refundRef || undefined }), { toast, success: 'تم تنفيذ الاسترداد' });
    if (res) { setRet(res); setModal(null); }
  };

  const rejectOrCancel = async () => {
    if (!ret || remote.usingMock) return;
    const res = await run(() => adminReturnsApi.update(ret.id, { status: rejectMode as 'rejected' | 'cancelled', admin_notes: rejectNotes || undefined }), { toast, success: 'تم التحديث' });
    if (res) { setRet(res); setModal(null); }

  };

  if (loading || !ret) return <><RemoteFeedback state={remote} />{!loading && !remote.error && <Empty title="المرتجع غير موجود" />}</>;

  const lines = (ret?.items ?? []).map((it) => ({
    v: { name: it.product_name ?? `#${it.order_item_id}`, sku: it.sku ?? '' },
    req: it.requested_qty,
    appr: it.approved_qty,
    good: it.received_good_qty,
    dmg: it.received_damaged_qty,
    price: num(it.unit_refund_price),
    reason: it.item_reason ?? '',
    files: 0,
    id: it.id,
  }));
  const displayLines = lines;
  const steps = [
    { k: 'pending', l: 'طلب الإرجاع' },
    { k: 'approved', l: 'الموافقة' },
    { k: 'received', l: 'الاستلام والفحص' },
    { k: 'refunded', l: 'رد المبلغ' },
  ];
  const order = ['pending', 'approved', 'received', 'refunded'];
  const idx = order.indexOf(r.status);
  const closed = ['rejected', 'cancelled'].includes(r.status);

  return (
    <div className="space-y-5">
      <RemoteFeedback state={remote} />
      <PageHead
        back={{ label: 'كل المرتجعات', onClick: () => go({ m: 'returns' }) }}
        title={
          <span className="flex items-center gap-3">
            <span className="num">{r.no}</span>
            <StatusBadge map={RETURN_STATUS} value={r.status} />
          </span>
        }
        sub={
          <span>
            عن الطلب <span className="num font-semibold tx1">{r.order}</span> · {r.customer}
          </span>
        }
        actions={
          <>
            {r.status === 'pending' && (
              <>
                <button className="btn btn-danger" disabled={!mayUpdate || remote.usingMock || busy} onClick={() => setModal('reject')}>
                  رفض / إلغاء
                </button>
                <button className="btn btn-dark" disabled={!mayApprove || remote.usingMock || busy} onClick={() => setModal('approve')}>
                  <Icon name="check" size={14} />
                  الموافقة
                </button>
              </>
            )}
            {r.status === 'approved' && (
              <>
                <button
                  className="btn btn-soft"
                  onClick={() => {
                    setRejectMode('cancelled');
                    setModal('reject');
                  }}
                >
                  إلغاء
                </button>
                <button className="btn btn-dark" disabled={!mayReceive || remote.usingMock || busy} onClick={() => setModal('receive')}>
                  <Icon name="box" size={14} />
                  الاستلام والفحص
                </button>
              </>
            )}
            {r.status === 'received' && (
              <button className="btn btn-accent" disabled={!mayRefund || remote.usingMock || busy} onClick={() => setModal('refund')}>
                <Icon name="wallet" size={14} />
                رد المبلغ
              </button>
            )}
          </>
        }
      />

      <Card stg={2} pad="p-5">
        {closed ? (
          <Note tone={r.status === 'rejected' ? 'red' : 'gray'} icon="ban">
            هذا المرتجع {RETURN_STATUS[r.status]?.label || r.status} — لا توجد خطوات إضافية.
          </Note>
        ) : (
          <div className="grid grid-cols-4 gap-2">
            {steps.map((s, i) => {
              const done = i <= idx,
                cur = i === idx + 1;
              return (
                <div key={s.k} className="flex flex-col gap-2">
                  <div
                    className="h-2 rounded-full overflow-hidden"
                    style={{ background: done ? 'var(--accent)' : 'transparent' }}
                  >
                    {!done && <div className="h-full hatch-track" />}
                  </div>
                  <div className="flex items-center gap-2">
                    <span
                      className={
                        'w-5 h-5 rounded-full flex items-center justify-center text-[10px] font-bold num ' +
                        (done ? 'btn-accent' : cur ? 'btn-dark' : 'v-well')
                      }
                    >
                      {done ? <Icon name="check" size={10} sw={3} /> : i + 1}
                    </span>
                    <span className={'text-[12.5px] font-medium ' + (done || cur ? 'tx1' : 'tx3')}>
                      {s.l}
                    </span>
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </Card>

      <div className="grid grid-cols-1 lg:grid-cols-12 gap-5">
        <Card stg={3} className="lg:col-span-8">
          <CardHead title="بنود المرتجع" />
          <DataTable
            compact
            rows={displayLines}
            cols={[
              {
                h: 'المنتج',
                r: (l) => (
                  <div className="leading-tight min-w-0 flex-1">
                    <div className="font-semibold tx1">{l.v.name}</div>
                    <div className="text-[11px] tx3 num ltr">{l.v.sku}</div>
                  </div>
                ),
              },
              { h: 'مطلوب', align: 'center', r: (l) => <Num>{l.req}</Num> },
              {
                h: 'موافق عليه',
                align: 'center',
                r: (l) => (l.appr == null ? <span className="tx3">—</span> : <Num className="font-semibold">{l.appr}</Num>),
              },
              {
                h: 'سليم',
                align: 'center',
                r: (l) => (l.good == null ? <span className="tx3">—</span> : <Num className="c-green font-semibold">{l.good}</Num>),
              },
              {
                h: 'تالف',
                align: 'center',
                r: (l) => (l.dmg == null ? <span className="tx3">—</span> : <Num className="c-red font-semibold">{l.dmg}</Num>),
              },
              { h: 'سعر الاسترداد', r: (l) => fmtMoney(l.price) },
              {
                h: 'السبب والمرفقات',
                r: (l) => (
                  <div className="leading-tight min-w-0 flex-1">
                    <div className="text-[12px] tx1">{l.reason}</div>
                    {l.files > 0 && (
                      <div className="flex gap-1 mt-1.5">
                        {Array.from({ length: l.files }).map((_, i) => (
                          <span
                            key={i}
                            className="w-7 h-7 rounded-lg v-sub flex items-center justify-center tx3"
                          >
                            <Icon name="image" size={12} />
                          </span>
                        ))}
                      </div>
                    )}
                  </div>
                ),
              },
            ]}
          />
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-5">
            <div className="v-sub p-4">
              <div className="lbl mb-1.5">سبب الإرجاع</div>
              <div className="text-[12.5px] tx1 leading-relaxed">
                {ret.reason ?? '—'}
              </div>
            </div>
            <div className="v-sub p-4">
              <div className="lbl mb-1.5">ملاحظات الإدارة</div>
              <div className="text-[12.5px] tx1 leading-relaxed">
                {ret.admin_notes ?? '—'}
              </div>
            </div>
          </div>
        </Card>
        <div className="lg:col-span-4 space-y-5">
          <div className="v-card p-4 stg stg-3">
            <div className="v-orange rounded-[18px] p-4">
              <div className="text-[12.5px] text-white/90">إجمالي الاسترداد</div>
              <div className="font-display text-[28px] font-bold num my-1.5 ltr">
                {fmtNum(r.total)} <span className="text-[13px] opacity-80">EGP</span>
              </div>
              <div className="text-[11px] text-white/80">محسوب من النظام حسب الكميات المعتمدة</div>
            </div>
            <div className="p-2 pt-4">
              <KV
                items={[
                  ['الفرع', r.branch],
                  ['طريقة الاسترداد', r.method && (REFUND_METHODS[r.method] || r.method)],
                  ['مرجع الاسترداد', r.status === 'refunded' ? <span className="num">{ret.refund_reference ?? '—'}</span> : null],
                ]}
              />
            </div>
          </div>
          <Card stg={4}>
            <CardHead title="السجل" className="mb-4" />
            <Timeline
              items={[
                r.status === 'refunded' && {
                  icon: 'wallet',
                  body: (
                    <div>
                      <div className="text-[12.5px] font-medium tx1">تم رد المبلغ</div>
                      <div className="text-[11px] tx3">
                        <Num>{toDate(ret.refunded_at)} {toTime(ret.refunded_at)}</Num>
                      </div>
                    </div>
                  ),
                },
                idx >= 2 && {
                  icon: 'box',
                  body: (
                    <div>
                      <div className="text-[12.5px] font-medium tx1">تم الاستلام والفحص</div>
                      <div className="text-[11px] tx3">
                        <Num>{toDate(ret.received_at)} {toTime(ret.received_at)}</Num>
                      </div>
                    </div>
                  ),
                },
                idx >= 1 && {
                  icon: 'check',
                  body: (
                    <div>
                      <div className="text-[12.5px] font-medium tx1">تمت الموافقة</div>
                      <div className="text-[11px] tx3">
                        <Num>{toDate(ret.approved_at)} {toTime(ret.approved_at)}</Num>
                      </div>
                    </div>
                  ),
                },
                {
                  icon: 'plus',
                  body: (
                    <div>
                      <div className="text-[12.5px] font-medium tx1">تم إنشاء المرتجع</div>
                      <div className="text-[11px] tx3">
                        {r.customer} · <Num>{r.date} {toTime(ret.created_at)}</Num>
                      </div>
                    </div>
                  ),
                },
              ].filter(Boolean) as any}
            />
          </Card>
        </div>
      </div>

      {/* approve modal */}
      <Modal
        open={modal === 'approve'}
        onClose={() => setModal(null)}
        title="الموافقة على المرتجع"
        icon="check"
        width={620}
        footer={
          <>
            <button className="btn btn-soft" onClick={() => setModal(null)}>
              إلغاء
            </button>
            <button
              className="btn btn-dark"
              disabled={remote.usingMock || !approveBranch || busy}
              onClick={approve}
            >
              موافقة
            </button>
          </>
        }
      >
        <div className="space-y-4">
          <Field label="الفرع المستلم" req>
            <select
              className="inp"
              value={approveBranch}
              onChange={(e) => setApproveBranch(e.target.value)}
            >
              <option value="">اختر الفرع</option>
              {(branches.data ?? []).map((b: { id: number; name: string }) => (
                <option key={b.id} value={String(b.id)}>{b.name}</option>
              ))}
            </select>
          </Field>
          <div>
            <div className="lbl mb-2">الكمية الموافق عليها لكل بند</div>
            <div className="v-sub p-2 space-y-1.5">
              {displayLines.map((l, i) => (
                <div key={i} className="flex items-center gap-3 px-2 py-1.5">
                  <div className="flex-1 min-w-0">
                    <div className="text-[12.5px] font-medium tx1 clamp1">{l.v.name}</div>
                    <div className="text-[11px] tx3">
                      مطلوب <Num>{l.req}</Num>
                    </div>
                  </div>
                  <input
                    type="number"
                    min="0"
                    max={l.req}
                    value={approveQtys[(l as any).id] ?? l.req}
                    onChange={(e) => setApproveQtys({ ...approveQtys, [(l as any).id]: Number(e.target.value) })}
                    className="inp inp-sm w-20 num text-center !bg-[var(--bg-card)]"
                  />
                </div>
              ))}
            </div>
          </div>
          <TextArea label="ملاحظات الإدارة" placeholder="اختياري" value={approveNotes} onChange={setApproveNotes} />
        </div>
      </Modal>

      {/* receive modal */}
      <Modal
        open={modal === 'receive'}
        onClose={() => setModal(null)}
        title="الاستلام والفحص"
        icon="box"
        width={620}
        sub="أدخل الكمية السليمة والتالفة المستلمة لكل بند."
        footer={
          <>
            <button className="btn btn-soft" onClick={() => setModal(null)}>
              إلغاء
            </button>
            <button
              className="btn btn-dark"
              disabled={remote.usingMock || busy}
              onClick={receive}
            >
              تأكيد الاستلام
            </button>
          </>
        }
      >
        <div className="v-sub p-2">
          <div className="grid grid-cols-12 gap-2 px-2 py-1.5 text-[11px] tx3">
            <span className="col-span-6">البند</span>
            <span className="col-span-2 text-center">معتمد</span>
            <span className="col-span-2 text-center">سليم</span>
            <span className="col-span-2 text-center">تالف</span>
          </div>
          {displayLines.map((l, i) => (
            <div key={i} className="grid grid-cols-12 gap-2 items-center px-2 py-1.5">
              <span className="col-span-6 text-[12.5px] font-medium tx1 clamp1">{l.v.name}</span>
              <Num className="col-span-2 text-center font-semibold">{l.appr ?? l.req}</Num>
              <input
                type="number"
                min="0"
                value={goodQtys[(l as any).id] ?? ''}
                onChange={(e) => setGoodQtys({ ...goodQtys, [(l as any).id]: e.target.value === '' ? undefined : Number(e.target.value) })}
                className="inp inp-sm col-span-2 num text-center !bg-[var(--bg-card)]"
              />
              <input
                type="number"
                min="0"
                value={damagedQtys[(l as any).id] ?? ''}
                onChange={(e) => setDamagedQtys({ ...damagedQtys, [(l as any).id]: e.target.value === '' ? undefined : Number(e.target.value) })}
                className="inp inp-sm col-span-2 num text-center !bg-[var(--bg-card)]"
              />
            </div>
          ))}
        </div>
      </Modal>

      {/* refund modal */}
      <Modal
        open={modal === 'refund'}
        onClose={() => setModal(null)}
        title="رد المبلغ"
        icon="wallet"
        width={460}
        footer={
          <>
            <button className="btn btn-soft" onClick={() => setModal(null)}>
              إلغاء
            </button>
            <button
              className="btn btn-accent"
              disabled={busy}
              onClick={refund}
            >
              تأكيد الرد
            </button>
          </>
        }
      >
        <div className="space-y-4 pb-2">
          <div className="v-sub p-4 flex items-center justify-between">
            <span className="tx2 text-[12.5px]">المبلغ المحسوب</span>
            <span className="font-display font-bold text-[18px]">{fmtMoney(ret ? num(ret.total_refund_amount) : r.total)}</span>
          </div>
          <Field label="طريقة الاسترداد" req>
            <select className="inp" value={refundMethod || (ret?.refund_method === 'original_payment' ? 'original' : ret?.refund_method === 'bank_transfer' ? 'bank' : ret?.refund_method ?? r.method ?? '')} onChange={(e) => setRefundMethod(e.target.value)}>
              {Object.entries(REFUND_METHODS).map(([k, l]) => (
                <option key={k} value={k}>
                  {l}
                </option>
              ))}
            </select>
          </Field>
          <Field label="المرجع" hint="اختياري — رقم العملية أو التحويل.">
            <input className="inp num" dir="ltr" value={refundRef} onChange={(e) => setRefundRef(e.target.value)} />
          </Field>
        </div>
      </Modal>

      {/* reject modal */}
      <Modal
        open={modal === 'reject'}
        onClose={() => setModal(null)}
        title="رفض أو إلغاء المرتجع"
        icon="ban"
        tone="red"
        width={460}
        footer={
          <>
            <button className="btn btn-soft" onClick={() => setModal(null)}>
              تراجع
            </button>
            <button
              className="btn btn-danger"
              disabled={busy}
              onClick={rejectOrCancel}
            >
              تأكيد
            </button>
          </>
        }
      >
        <div className="space-y-4 pb-2">
          <Field label="الحالة">
            <Seg
              value={rejectMode}
              onChange={setRejectMode}
              options={[
                { value: 'rejected', label: 'مرفوض' },
                { value: 'cancelled', label: 'ملغي' },
              ]}
            />
          </Field>
          <TextArea label="ملاحظات الإدارة" placeholder="سبب الرفض أو الإلغاء" value={rejectNotes} onChange={setRejectNotes} />
        </div>
      </Modal>
    </div>
  );
}
```

## File: bags-platform/backend/app/Http/Controllers/Api/V1/AttachmentController.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\ReturnRequestItem;
use App\Models\SupplierDelivery;
use App\Models\SupplierInvoice;
use App\Models\SupplierUser;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Services\BranchAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Securely streams private file attachments with strict role-based access control and nosniff headers.
 */
class AttachmentController extends Controller
{
    public function __construct(private readonly BranchAccess $branchAccess) {}

    /**
     * Streams inspection photos attached to a return request item.
     *
     * @param  Request  $request  Authorized request
     * @param  int  $returnItem  Return item identifier
     * @param  int  $attachment  Attachment array index
     * @return RedirectResponse Short-lived signed R2 URL
     */
    public function returnFile(Request $request, int $returnItem, int $attachment): RedirectResponse|JsonResponse
    {
        $actor = $request->user();
        $query = ReturnRequestItem::query();
        if ($actor instanceof Customer) {
            $query->whereHas('returnRequest', fn ($q) => $q->where('customer_id', $actor->id));
        } else {
            abort_unless($actor instanceof User && $actor->can('returns.view'), 403);
        }
        $item = $query->findOrFail($returnItem);
        if ($actor instanceof User) {
            $return = $item->returnRequest;
            $branchId = $return->branch_id ?? $return->order?->allocated_branch_id;
            if ($branchId === null) {
                $this->branchAccess->assertCentralAccess($actor);
            } else {
                $this->branchAccess->assertBranch($actor->id, (int) $branchId);
            }
        }

        return $this->download($request, ($item->attachments ?? [])[$attachment] ?? null);
    }

    /**
     * Streams supplier invoice PDF attachment.
     *
     * @param  Request  $request  Authorized request
     * @param  int  $invoice  Supplier invoice identifier
     * @return RedirectResponse Short-lived signed R2 URL
     */
    public function invoice(Request $request, int $invoice): RedirectResponse|JsonResponse
    {
        $actor = $request->user();
        $query = SupplierInvoice::query()->with('purchaseOrder');
        if ($actor instanceof SupplierUser) {
            $query->where('supplier_id', $actor->supplier_id)->where('status', '!=', 'draft');
        } else {
            abort_unless($actor instanceof User && $actor->can('purchases.view'), 403);
        }

        $model = $query->findOrFail($invoice);
        if ($actor instanceof User) {
            // Prevent branch-confined staff from accessing procurement documents of other branches.
            $branchId = $model->purchaseOrder?->branch_id;
            if ($branchId === null) {
                $this->branchAccess->assertCentralAccess($actor);
            } else {
                $this->branchAccess->assertBranch($actor->id, (int) $branchId);
            }
        }

        return $this->download($request, $model->attachment_path);
    }

    /**
     * Streams delivery receipt document attached to a procurement delivery.
     *
     * @param  Request  $request  Authorized request
     * @param  int  $delivery  Delivery identifier
     * @return RedirectResponse Short-lived signed R2 URL
     */
    public function delivery(Request $request, int $delivery): RedirectResponse|JsonResponse
    {
        $actor = $request->user();
        $query = SupplierDelivery::query()->with('purchaseOrder');
        if ($actor instanceof SupplierUser) {
            $query->where('supplier_id', $actor->supplier_id);
        } else {
            abort_unless($actor instanceof User && $actor->can('purchases.view'), 403);
        }

        $model = $query->findOrFail($delivery);
        if ($actor instanceof User) {
            // Prevent cross-branch procurement document retrieval.
            $branchId = $model->purchaseOrder?->branch_id;
            if ($branchId === null) {
                $this->branchAccess->assertCentralAccess($actor);
            } else {
                $this->branchAccess->assertBranch($actor->id, (int) $branchId);
            }
        }

        return $this->download($request, $model->invoice_file_path);
    }

    /**
     * Streams bank transfer receipt attached to a wholesale payment notice.
     *
     * @param  Request  $request  Authorized request
     * @param  int  $notice  Payment notice identifier
     * @return RedirectResponse Short-lived signed R2 URL (H3)
     */
    public function paymentNotice(Request $request, int $notice): RedirectResponse|JsonResponse
    {
        $actor = $request->user();
        $query = WholesalePaymentNotice::query();
        if ($actor instanceof Customer) {
            $query->where('customer_id', $actor->id);
        } else {
            abort_unless($actor instanceof User && $actor->can('payment_notices.view'), 403);
        }

        $model = $query->with('order')->findOrFail($notice);
        if ($actor instanceof User) {
            $branchId = $model->order?->allocated_branch_id;
            if ($branchId === null) {
                $this->branchAccess->assertCentralAccess($actor);
            } else {
                $this->branchAccess->assertBranch($actor->id, (int) $branchId);
            }
        }

        return $this->download($request, $model->receipt_file_path);
    }

    private function download(Request $request, ?string $path): RedirectResponse|JsonResponse
    {
        // Avoid proxying storage payload through PHP-FPM workers to prevent connection exhaustion.
        // Instead, authorize locally and redirect to a short-lived (2-minute) signed R2 URL.
        abort_if($path === null, 404);

        try {
            if (! $request->boolean('signed') && ! Storage::disk('r2')->exists($path)) {
                abort(404);
            }

            $url = Storage::disk('r2')->temporaryUrl(
                $path,
                now()->addMinutes(2),
                [
                    'ResponseContentDisposition' => HeaderUtils::makeDisposition('attachment', basename($path), 'attachment'),
                    // Prevent intermediate proxies or shared caches from storing private documents.
                    'ResponseCacheControl' => 'private, max-age=120',
                ]
            );
        } catch (Throwable $exception) {
            if ((int) ($exception->getCode() ?: 0) === 404 || $exception instanceof HttpException) {
                throw $exception;
            }

            // R2 outage (throw => true) is a dependency failure, not a bug.
            abort(503, 'Document storage is temporarily unavailable.');
        }

        if ($request->boolean('signed')) {
            return response()->json(['url' => $url])->header('Cache-Control', 'private, no-store');
        }

        return redirect()->to($url)->header('Cache-Control', 'private, no-store');
    }
}
```

## File: bags-platform/backend/app/Models/Order.php
```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Order entity representing customer orders across Storefront, POS, and Wholesale channels.
 * Status transitions MUST occur via OrderService::changeStatus() to ensure inventory reservations,
 * status history audit entries, and shipment consignments are atomically handled.
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'order_number',
        'channel',
        'customer_id',
        'guest_name',
        'guest_phone',
        'guest_phone_normalized',
        'guest_email',
        'city_id',
        'shipping_address_line',
        'shipping_notes',
        'allocated_branch_id',
        'pos_shift_id',
        'return_of_order_id',
        'status',
        'payment_method',
        'payment_status',
        'due_at',
        'requires_refund',
        'needs_attention',
        'cod_fee',
        'idempotency_key',
        'idempotency_scope',
        'gateway_order_id',
        'subtotal',
        'discount_amount',
        'coupon_id',
        'coupon_code',
        'shipping_cost',
        'tax_amount',
        'grand_total',
        'cash_amount',
        'card_amount',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'shipping_cost' => 'decimal:2',
            'cod_fee' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'cash_amount' => 'decimal:2',
            'card_amount' => 'decimal:2',
            'requires_refund' => 'boolean',
            'needs_attention' => 'boolean',
            'due_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PosShift, $this> */
    public function posShift(): BelongsTo
    {
        return $this->belongsTo(PosShift::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<OrderStatusHistory, $this> */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->latest('id');
    }

    /** @return HasMany<Shipment, $this> */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    /** @return HasMany<ReturnRequest, $this> */
    public function returnRequests(): HasMany
    {
        return $this->hasMany(ReturnRequest::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function allocatedBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'allocated_branch_id');
    }

    /**
     * Determine whether the given phone number matches this order (guest or registered customer).
     * Compares canonical E.164 formats to prevent false mismatches from dialing codes.
     *
     * @param  string  $phone  Input phone string to compare
     * @return bool True if phone matches guest or customer phone
     */
    public function matchesPhone(string $phone): bool
    {
        $normalized = Customer::normalizePhone($phone);

        if ($this->guest_phone_normalized !== null && $this->guest_phone_normalized === $normalized) {
            return true;
        }

        if ($this->guest_phone !== null && Customer::normalizePhone($this->guest_phone) === $normalized) {
            return true;
        }

        if ($this->customer?->phone !== null && Customer::normalizePhone((string) $this->customer->phone) === $normalized) {
            return true;
        }

        return false;
    }
}
```

## File: bags-platform/backend/app/Services/WholesalePaymentNoticeService.php
```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\TreasuryTransactionType;
use App\Enums\TreasuryType;
use App\Enums\WholesalePaymentNoticeStatus;
use App\Events\PaymentNoticeReviewed;
use App\Events\PaymentNoticeSubmitted;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Models\Treasury;
use App\Models\User;
use App\Models\WholesalePaymentAllocation;
use App\Models\WholesalePaymentNotice;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Manages wholesale customer payment notices, credit balance calculation, and order allocations.
 */
class WholesalePaymentNoticeService
{
    public function __construct(
        private readonly SettingService $settings,
        private readonly TreasuryService $treasury,
    ) {}

    /**
     * Submits a new payment notice with an uploaded proof receipt.
     *
     * @param  Customer  $customer  Submitting wholesale customer
     * @param  array<string, mixed>  $data  Notice metadata
     * @param  UploadedFile  $receipt  Proof of payment file
     * @return WholesalePaymentNotice Created pending notice
     *
     * @throws BusinessException If linked order is invalid or capacity exceeded
     */
    public function create(Customer $customer, array $data, UploadedFile $receipt): WholesalePaymentNotice
    {
        return PrivateAttachments::transaction(function (PrivateAttachments $files) use ($customer, $data, $receipt): WholesalePaymentNotice {
            // Serialize free and linked submissions for the same customer before
            // checking the bank reference, including requests with different files.
            Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $reference = trim($data['reference_number']);
            if (WholesalePaymentNotice::query()->where('customer_id', $customer->id)
                ->whereReferenceNumber($reference)->exists()) {
                throw new BusinessException('رقم مرجع السداد مسجل بالفعل لهذا التاجر.', [], 409);
            }

            $amount = BigDecimal::of((string) $data['amount'])->toScale(2, RoundingMode::HalfUp);
            $transferDate = Carbon::parse((string) $data['transfer_date'])->toDateString();
            $crossCustomer = WholesalePaymentNotice::query()
                ->where('customer_id', '!=', $customer->id)
                ->where('status', '!=', WholesalePaymentNoticeStatus::Rejected->value)
                ->whereReferenceNumber($reference)
                ->where('amount', (string) $amount)
                ->whereDate('transfer_date', $transferDate)
                ->first();
            if ($crossCustomer !== null) {
                throw new BusinessException("نفس المرجع والمبلغ والتاريخ مسجّلين لإشعار {$crossCustomer->notice_number} من حساب تاجر آخر.", [], 409);
            }

            $realPath = $receipt->getRealPath();
            if ($realPath === false) {
                throw new BusinessException('تعذر قراءة إيصال السداد.');
            }
            $hash = hash_file('sha256', $realPath);
            $sameReceipt = WholesalePaymentNotice::query()
                ->where('receipt_sha256', $hash)
                ->where('status', '!=', WholesalePaymentNoticeStatus::Rejected->value)
                ->first();
            if ($sameReceipt !== null) {
                throw new BusinessException("نفس الإيصال اترفع قبل كده ({$sameReceipt->notice_number}).", [], 409);
            }

            $order = null;

            if (! empty($data['order_id'])) {
                // Pessimistic lock inside transaction serializes concurrent submissions against remaining balance.
                $order = $this->assertCreditableOrder($customer, (int) $data['order_id']);
                $this->assertOrderCapacity($order, (string) $data['amount']);
            }

            $path = $files->store($receipt, "wholesale_payment_notices/{$customer->id}");

            try {
                $notice = WholesalePaymentNotice::create([
                    'notice_number' => $this->settings->generateNextNumber('payment_notice'),
                    'customer_id' => $customer->id,
                    'order_id' => $order?->id,
                    'amount' => (string) $amount,
                    'payment_method' => $data['payment_method'],
                    'bank_name' => $data['bank_name'],
                    'reference_number' => $reference,
                    'transfer_date' => $transferDate,
                    'receipt_file_path' => $path,
                    'receipt_sha256' => $hash,
                    'status' => WholesalePaymentNoticeStatus::PendingReview,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new BusinessException('نفس الإيصال اترفع قبل كده.', [], 409);
            }

            PaymentNoticeSubmitted::dispatch($notice);

            return $notice;
        }, [[$receipt, 'wholesale_payment_notices/'.$customer->id]]);
    }

    /**
     * Computes the current net debt for a wholesale customer (negative implies credit balance).
     *
     * @param  Customer  $customer  Wholesale customer
     * @return float Current outstanding debt or negative advance credit
     */
    public function outstandingBalance(Customer $customer): float
    {
        return (float) (string) $this->outstandingBalanceDecimal($customer);
    }

    /**
     * @return array{debt: string, linked_open_notices: string, free_pool: string, allocated: string, allocated_open: string, floating_credit: string, outstanding: string}
     */
    public function balanceBreakdown(Customer $customer): array
    {
        $debt = BigDecimal::of((string) Order::query()
            ->where('customer_id', $customer->id)
            ->where('payment_method', 'credit')
            ->where('payment_status', 'unpaid')
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->sum('grand_total'))->toScale(2, RoundingMode::HalfUp);

        // Fully paid orders and their linked notices are excluded to avoid double deduction.
        $linkedOpen = BigDecimal::of((string) WholesalePaymentNotice::query()
            ->where('customer_id', $customer->id)
            ->where('status', WholesalePaymentNoticeStatus::Approved->value)
            ->whereNotNull('order_id')
            ->whereExists(function ($exists): void {
                $exists->selectRaw('1')->from('orders as o')
                    ->whereColumn('o.id', 'wholesale_payment_notices.order_id')
                    ->where('o.payment_status', 'unpaid')
                    ->where('o.status', '!=', OrderStatus::Cancelled->value);
            })
            ->sum('amount'))->toScale(2, RoundingMode::HalfUp);

        $free = $this->freePoolTotal($customer);
        $allocated = $this->allocatedTotal($customer);
        // Partial FIFO allocations consume free money against orders that stay
        // unpaid, so debt still counts their full totals: subtract the applied
        // amount explicitly or the payment would vanish from the balance.
        $allocatedOpen = $this->allocatedOpenTotal($customer);
        $floating = $free->minus($allocated)->toScale(2, RoundingMode::HalfUp);
        $outstanding = $debt->minus($linkedOpen)->minus($floating)->minus($allocatedOpen)->toScale(2, RoundingMode::HalfUp);

        return [
            'debt' => (string) $debt,
            'linked_open_notices' => (string) $linkedOpen,
            'free_pool' => (string) $free,
            'allocated' => (string) $allocated,
            'allocated_open' => (string) $allocatedOpen,
            'floating_credit' => (string) $floating,
            'outstanding' => (string) $outstanding,
        ];
    }

    private function outstandingBalanceDecimal(Customer $customer): BigDecimal
    {
        return BigDecimal::of($this->balanceBreakdown($customer)['outstanding']);
    }

    private function freeNoticesQuery(Customer $customer)
    {
        return WholesalePaymentNotice::query()
            ->where('customer_id', $customer->id)
            ->where('status', WholesalePaymentNoticeStatus::Approved->value)
            ->where(function ($query): void {
                $query->whereNull('order_id')
                    ->orWhereExists(function ($exists): void {
                        $exists->selectRaw('1')->from('orders as o')
                            ->whereColumn('o.id', 'wholesale_payment_notices.order_id')
                            ->where('o.status', OrderStatus::Cancelled->value);
                    });
            });
    }

    private function freePoolTotal(Customer $customer): BigDecimal
    {
        return BigDecimal::of((string) $this->freeNoticesQuery($customer)->sum('amount'))
            ->toScale(2, RoundingMode::HalfUp);
    }

    private function allocatedTotal(Customer $customer): BigDecimal
    {
        return BigDecimal::of((string) WholesalePaymentAllocation::query()
            ->whereHas('notice', fn ($q) => $q->where('customer_id', $customer->id))
            ->whereHas('order', fn ($q) => $q->where('status', '!=', OrderStatus::Cancelled->value))
            ->sum('amount'))->toScale(2, RoundingMode::HalfUp);
    }

    private function allocatedOpenTotal(Customer $customer): BigDecimal
    {
        return BigDecimal::of((string) WholesalePaymentAllocation::query()
            ->whereHas('notice', fn ($q) => $q->where('customer_id', $customer->id))
            ->whereHas('order', fn ($q) => $q
                ->where('status', '!=', OrderStatus::Cancelled->value)
                ->where('payment_status', 'unpaid'))
            ->sum('amount'))->toScale(2, RoundingMode::HalfUp);
    }

    /**
     * Generates a chronological statement of account including debits, credits, and opening balance.
     *
     * @param  Customer  $customer  Wholesale customer
     * @param  string|null  $from  Start date (inclusive, YYYY-MM-DD)
     * @param  string|null  $to  End date (inclusive, YYYY-MM-DD)
     * @return array{opening_balance: float, closing_balance: float, entries: list<array<string, mixed>>}
     */
    public function statement(Customer $customer, ?string $from = null, ?string $to = null): array
    {
        $entries = collect();

        Order::query()
            ->where('customer_id', $customer->id)
            ->where('payment_method', 'credit')
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->get(['id', 'order_number', 'grand_total', 'created_at'])
            ->each(fn (Order $order) => $entries->push([
                'date' => $order->created_at->copy(),
                'type' => 'debit',
                'description' => "طلب آجل {$order->order_number}",
                'reference' => $order->order_number,
                'amount' => (float) $order->grand_total,
            ]));

        WholesalePaymentNotice::query()
            ->where('customer_id', $customer->id)
            ->where('status', WholesalePaymentNoticeStatus::Approved->value)
            ->get(['id', 'notice_number', 'payment_method', 'amount', 'approved_at', 'transfer_date', 'updated_at'])
            ->each(fn (WholesalePaymentNotice $notice) => $entries->push([
                'date' => ($notice->approved_at ?? $notice->transfer_date ?? $notice->updated_at)->copy(),
                'type' => 'credit',
                'description' => "سداد {$notice->notice_number} ({$notice->payment_method->value})",
                'reference' => $notice->notice_number,
                'amount' => (float) $notice->amount,
            ]));

        ReturnRequest::query()
            ->where('customer_id', $customer->id)
            ->where('status', 'refunded')
            ->whereNotNull('refunded_at')
            ->get(['id', 'return_number', 'total_refund_amount', 'refunded_at'])
            ->each(fn (ReturnRequest $return) => $entries->push([
                'date' => $return->refunded_at->copy(),
                'type' => 'credit',
                'description' => "مرتجع مسترد {$return->return_number}",
                'reference' => $return->return_number,
                'amount' => (float) $return->total_refund_amount,
            ]));

        // Standard accounting convention: debits precede credits on identical dates.
        $sorted = $entries->sortBy([
            fn (array $a, array $b): int => $a['date']->startOfDay() <=> $b['date']->startOfDay(),
            fn (array $a, array $b): int => ($a['type'] === 'debit' ? 0 : 1) <=> ($b['type'] === 'debit' ? 0 : 1),
        ])->values();

        $fromDate = $from !== null ? Carbon::parse($from)->startOfDay() : null;
        $toDate = $to !== null ? Carbon::parse($to)->endOfDay() : null;

        $opening = 0.0;
        $running = 0.0;
        $result = [];
        foreach ($sorted as $entry) {
            $delta = $entry['type'] === 'debit' ? $entry['amount'] : -$entry['amount'];

            if ($fromDate !== null && $entry['date']->lt($fromDate)) {
                $opening += $delta;
                $running = $opening;

                continue;
            }
            if ($toDate !== null && $entry['date']->gt($toDate)) {
                continue;
            }

            $running += $delta;
            $result[] = [
                'date' => $entry['date']->toDateString(),
                'reference' => $entry['reference'],
                'description' => $entry['description'],
                'debit' => $entry['type'] === 'debit' ? number_format($entry['amount'], 2, '.', '') : '0.00',
                'credit' => $entry['type'] === 'credit' ? number_format($entry['amount'], 2, '.', '') : '0.00',
                'balance' => number_format($running, 2, '.', ''),
            ];
        }

        return [
            'opening_balance' => round($opening, 2),
            'closing_balance' => round($running, 2),
            'entries' => $result,
        ];
    }

    /**
     * Approves a payment notice, recording treasury receipt and allocating against open debt.
     *
     * @param  WholesalePaymentNotice  $notice  Target notice
     * @param  int  $adminId  Approving staff member ID
     * @param  int|null  $treasuryId  Destination treasury ID
     * @param  string|null  $adminNotes  Optional administrative review notes
     * @return WholesalePaymentNotice Updated notice instance
     *
     * @throws BusinessException If notice was reviewed, exceeds debt, or treasury is invalid
     */
    public function approve(WholesalePaymentNotice $notice, int $adminId, ?int $treasuryId = null, ?string $adminNotes = null): WholesalePaymentNotice
    {
        return DB::transaction(function () use ($notice, $adminId, $treasuryId, $adminNotes): WholesalePaymentNotice {
            $notice = WholesalePaymentNotice::query()->lockForUpdate()->findOrFail($notice->id);
            $customer = Customer::query()->lockForUpdate()->findOrFail($notice->customer_id);

            if ($notice->status !== WholesalePaymentNoticeStatus::PendingReview) {
                throw new BusinessException('إشعار السداد هذا تمت مراجعته من قبل.');
            }

            if ($notice->order_id !== null) {
                $order = Order::query()->lockForUpdate()->findOrFail($notice->order_id);
                if ($order->payment_status === 'paid') {
                    throw new BusinessException('الطلب المرتبط تم سداده بالفعل؛ لا يمكن اعتماد إشعار السداد.');
                }
            }

            // Decimal comparison avoids floating-point precision boundary errors.
            if (BigDecimal::of((string) $notice->amount)->compareTo($this->outstandingBalanceDecimal($customer)) > 0) {
                throw new BusinessException('مبلغ الإشعار أكبر من المديونية الآجلة الحالية للتاجر.');
            }

            $treasury = $this->resolveTreasury($treasuryId);
            app(BranchAccess::class)->assertTreasury($adminId, $treasury);

            $transaction = $this->treasury->record([
                'treasury_id' => $treasury->id,
                'type' => TreasuryTransactionType::CustomerReceipt,
                'amount' => (string) $notice->amount,
                'reference_type' => 'wholesale_payment_notice',
                'reference_id' => $notice->id,
                'created_by' => $adminId,
                'notes' => "اعتماد إشعار سداد جملة {$notice->notice_number}",
            ]);

            $notice->update([
                'status' => WholesalePaymentNoticeStatus::Approved,
                'reviewed_by' => $adminId,
                'approved_at' => now(),
                'admin_notes' => $adminNotes ?? $notice->admin_notes,
                'treasury_id' => $treasury->id,
                'treasury_transaction_id' => $transaction->id,
            ]);

            if ($notice->order_id !== null) {
                $this->settleOrderIfCovered((int) $notice->order_id, $adminId, $notice->notice_number);
            }
            $this->settleFreeNoticeFifo($customer, $adminId, $notice->notice_number);

            $notice = $notice->load(['customer', 'order', 'treasury', 'treasuryTransaction']);
            PaymentNoticeReviewed::dispatch($notice);

            return $notice;
        });
    }

    /**
     * Rejects a pending wholesale payment notice.
     *
     * @param  WholesalePaymentNotice  $notice  Target notice
     * @param  int  $adminId  Reviewing staff member ID
     * @param  string|null  $adminNotes  Reason for rejection
     * @return WholesalePaymentNotice Updated notice instance
     *
     * @throws BusinessException If notice was already reviewed
     */
    public function reject(WholesalePaymentNotice $notice, int $adminId, ?string $adminNotes = null): WholesalePaymentNotice
    {
        return DB::transaction(function () use ($notice, $adminId, $adminNotes): WholesalePaymentNotice {
            $notice = WholesalePaymentNotice::query()->lockForUpdate()->findOrFail($notice->id);

            if ($notice->status !== WholesalePaymentNoticeStatus::PendingReview) {
                throw new BusinessException('إشعار السداد هذا تمت مراجعته من قبل.');
            }

            $notice->update([
                'status' => WholesalePaymentNoticeStatus::Rejected,
                'reviewed_by' => $adminId,
                'admin_notes' => $adminNotes ?? $notice->admin_notes,
            ]);

            $notice = $notice->load(['customer', 'order']);
            PaymentNoticeReviewed::dispatch($notice);

            return $notice;
        });
    }

    /**
     * Reverses an approved notice: treasury reversal, allocation removal, and unpaid orders.
     * This is an audited financial action, not the 15-minute AI undo window.
     *
     * @throws BusinessException If the notice is not an approved, unreversed receipt.
     */
    public function reverse(WholesalePaymentNotice $notice, int $adminId, string $reason): WholesalePaymentNotice
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new BusinessException('سبب عكس الاعتماد مطلوب.');
        }

        return DB::transaction(function () use ($notice, $adminId, $reason): WholesalePaymentNotice {
            $notice = WholesalePaymentNotice::query()->lockForUpdate()->findOrFail($notice->id);
            Customer::query()->lockForUpdate()->findOrFail($notice->customer_id);

            if ($notice->status !== WholesalePaymentNoticeStatus::Approved || $notice->reversed_at !== null) {
                throw new BusinessException('لا يمكن عكس إشعار غير معتمد أو سبق عكسه.');
            }
            if ($notice->treasury_id === null) {
                throw new BusinessException('الإشعار من غير حركة خزينة، لا يمكن عكسه.');
            }

            $orderIds = WholesalePaymentAllocation::query()
                ->where('notice_id', $notice->id)
                ->pluck('order_id')
                ->all();
            if ($notice->order_id !== null) {
                $orderIds[] = (int) $notice->order_id;
            }
            WholesalePaymentAllocation::query()->where('notice_id', $notice->id)->delete();

            $reversal = $this->treasury->record([
                'treasury_id' => $notice->treasury_id,
                'type' => TreasuryTransactionType::CustomerReceiptReversal,
                'amount' => (string) BigDecimal::of((string) $notice->amount)->negated()->toScale(2, RoundingMode::HalfUp),
                'reference_type' => 'wholesale_payment_notice',
                'reference_id' => $notice->id,
                'created_by' => $adminId,
                'notes' => "عكس اعتماد إشعار سداد {$notice->notice_number}: {$reason}",
            ]);

            $notice->update([
                'status' => WholesalePaymentNoticeStatus::Reversed,
                'reversed_at' => now(),
                'reversal_treasury_transaction_id' => $reversal->id,
                'admin_notes' => $reason,
                'reviewed_by' => $adminId,
            ]);

            foreach (array_unique(array_map('intval', $orderIds)) as $orderId) {
                $this->reopenOrderIfUncovered($orderId, $adminId, $notice->notice_number);
            }

            return $notice->load(['customer', 'order', 'treasury']);
        });
    }

    private function assertCreditableOrder(Customer $customer, int $orderId): Order
    {
        // Lock order row to prevent concurrent notices from exceeding total order value.
        $order = Order::query()->where('customer_id', $customer->id)->lockForUpdate()->find($orderId);

        if ($order === null) {
            throw new BusinessException('الطلب المرتبط غير موجود أو لا يخص هذا التاجر.', [], 404);
        }

        if ($order->payment_method !== 'credit' || $order->payment_status !== 'unpaid'
            || $order->status === OrderStatus::Cancelled) {
            throw new BusinessException('الطلب المرتبط ليس طلب آجل غير مسدد.');
        }

        return $order;
    }

    private function assertOrderCapacity(Order $order, string $newAmount): void
    {
        // Include pending reviews so concurrent submissions cannot collectively overcommit the order.
        $committed = (string) WholesalePaymentNotice::query()
            ->where('order_id', $order->id)
            ->whereIn('status', [
                WholesalePaymentNoticeStatus::PendingReview->value,
                WholesalePaymentNoticeStatus::Approved->value,
            ])
            ->sum('amount');

        $total = BigDecimal::of($committed)->plus(BigDecimal::of($newAmount))->toScale(2, RoundingMode::HalfUp);
        if ($total->compareTo(BigDecimal::of((string) $order->grand_total)) > 0) {
            throw new BusinessException('مبلغ الإشعار يتجاوز المتبقي غير المسدد من هذا الطلب.');
        }
    }

    private function settleOrderIfCovered(int $orderId, int $adminId, string $noticeNumber): void
    {
        $order = Order::query()->lockForUpdate()->find($orderId);

        if ($order === null || $order->payment_status !== 'unpaid') {
            return;
        }

        $approved = (string) WholesalePaymentNotice::query()
            ->where('order_id', $order->id)
            ->where('status', WholesalePaymentNoticeStatus::Approved->value)
            ->sum('amount');

        if (BigDecimal::of($approved)->compareTo(BigDecimal::of((string) $order->grand_total)) >= 0) {
            $this->markOrderPaid($order, $adminId, "تسوية آجل بالإشعار {$noticeNumber}");
        }
    }

    /**
     * Read-only allocation preview. Uses the same planner as settlement and writes nothing.
     *
     * @return array{notice_id: int, notice_number: string, amount: string, linked_order: array<string, mixed>|null, fifo: list<array<string, mixed>>}
     */
    public function previewNoticeAllocation(WholesalePaymentNotice $notice, ?int $branchId = null): array
    {
        return DB::transaction(function () use ($notice, $branchId): array {
            $customer = Customer::query()->lockForUpdate()->findOrFail($notice->customer_id);
            $extra = '0.00';
            $linked = null;

            if ($notice->order_id !== null) {
                $order = Order::query()->lockForUpdate()->find($notice->order_id);
                if ($order !== null) {
                    $approved = BigDecimal::of((string) WholesalePaymentNotice::query()
                        ->where('order_id', $order->id)
                        ->where('status', WholesalePaymentNoticeStatus::Approved->value)
                        ->when($notice->status !== WholesalePaymentNoticeStatus::Approved, fn ($q) => $q->whereKeyNot($notice->id))
                        ->sum('amount'));
                    $incoming = $notice->status === WholesalePaymentNoticeStatus::Approved
                        ? BigDecimal::zero()
                        : BigDecimal::of((string) $notice->amount);
                    $already = BigDecimal::of((string) WholesalePaymentAllocation::query()->where('order_id', $order->id)->sum('amount'));
                    $remaining = BigDecimal::of((string) $order->grand_total)->minus($approved)->minus($incoming)->minus($already)
                        ->toScale(2, RoundingMode::HalfUp);
                    $linked = [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'remaining_after' => (string) ($remaining->isNegative() ? BigDecimal::zero()->toScale(2, RoundingMode::HalfUp) : $remaining),
                        'closes' => $remaining->isLessThanOrEqualTo('0'),
                    ];
                }
            } elseif ($notice->status !== WholesalePaymentNoticeStatus::Approved) {
                $extra = (string) BigDecimal::of((string) $notice->amount)->toScale(2, RoundingMode::HalfUp);
            }

            return [
                'notice_id' => $notice->id,
                'notice_number' => $notice->notice_number,
                'amount' => (string) $notice->amount,
                'linked_order' => $linked,
                'fifo' => $this->planFreeAllocation($customer, $extra, $branchId),
            ];
        });
    }

    /** @return list<array{notice_id: int, notice_number: string, customer_id: int, reason: string}> */
    public function receiptCollisions(WholesalePaymentNotice $notice): array
    {
        $collisions = [];
        $mapCollision = static fn (WholesalePaymentNotice $other, string $reason): array => [
            'notice_id' => $other->id,
            'notice_number' => $other->notice_number,
            'customer_id' => $other->customer_id,
            'reason' => $reason,
        ];

        if ($notice->receipt_sha256 !== null) {
            $sameFile = WholesalePaymentNotice::query()
                ->where('receipt_sha256', $notice->receipt_sha256)
                ->whereKeyNot($notice->id)
                ->where('status', '!=', WholesalePaymentNoticeStatus::Rejected->value)
                ->get(['id', 'notice_number', 'customer_id']);
            foreach ($sameFile as $other) {
                $collisions[] = $mapCollision($other, 'same_receipt');
            }
        }

        $sameTransfer = WholesalePaymentNotice::query()
            ->whereKeyNot($notice->id)
            ->where('customer_id', '!=', $notice->customer_id)
            ->where('status', '!=', WholesalePaymentNoticeStatus::Rejected->value)
            ->whereReferenceNumber((string) $notice->reference_number)
            ->where('amount', (string) $notice->amount)
            ->whereDate('transfer_date', $notice->transfer_date?->toDateString())
            ->get(['id', 'notice_number', 'customer_id']);
        foreach ($sameTransfer as $other) {
            $collisions[] = $mapCollision($other, 'same_reference_amount_date');
        }

        return $collisions;
    }

    // FIFO allocation settles oldest unpaid orders first using available unallocated notices.
    private function settleFreeNoticeFifo(Customer $customer, int $adminId, string $noticeNumber): void
    {
        $branchId = app(BranchAccess::class)->restrictedBranch(User::findOrFail($adminId));
        $this->applyFreePlan($customer, $adminId, $noticeNumber, $this->planFreeAllocation($customer, '0', $branchId));
    }

    /**
     * @return list<array{order_id: int, order_number: string, allocated: string, remaining: string, closes: bool, skipped: bool}>
     */
    private function planFreeAllocation(Customer $customer, string $extraFree, ?int $branchId): array
    {
        $available = $this->freePoolTotal($customer)->minus($this->allocatedTotal($customer))
            ->plus(BigDecimal::of($extraFree))
            ->toScale(2, RoundingMode::HalfUp);
        $mode = (string) $this->settings->get('wholesale_fifo_mode', 'strict');
        if (! in_array($mode, ['strict', 'skip_to_fit', 'partial'], true)) {
            $mode = 'strict';
        }

        $orders = Order::query()
            ->where('customer_id', $customer->id)
            ->where('payment_method', 'credit')
            ->where('payment_status', 'unpaid')
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->when($branchId !== null, fn ($q) => $q->where('allocated_branch_id', $branchId))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $lines = [];
        foreach ($orders as $order) {
            if ($available->isLessThanOrEqualTo('0')) {
                break;
            }
            $covered = $this->orderCoveredAmount((int) $order->id);
            $need = BigDecimal::of((string) $order->grand_total)->minus($covered)
                ->toScale(2, RoundingMode::HalfUp);
            if ($need->isLessThanOrEqualTo('0')) {
                $lines[] = $this->allocationLine($order, '0.00', '0.00', true, false);

                continue;
            }
            if ($available->compareTo($need) >= 0) {
                $lines[] = $this->allocationLine($order, (string) $need, '0.00', true, false);
                $available = $available->minus($need)->toScale(2, RoundingMode::HalfUp);

                continue;
            }
            if ($mode === 'partial') {
                $remaining = $need->minus($available)->toScale(2, RoundingMode::HalfUp);
                $lines[] = $this->allocationLine($order, (string) $available, (string) $remaining, false, false);

                break;
            }
            if ($mode === 'skip_to_fit') {
                $lines[] = $this->allocationLine($order, '0.00', (string) $need, false, true);

                continue;
            }

            $lines[] = $this->allocationLine($order, '0.00', (string) $need, false, false);

            break;
        }

        return $lines;
    }

    /** @param  list<array{order_id: int, order_number: string, allocated: string, remaining: string, closes: bool, skipped: bool}>  $lines */
    private function applyFreePlan(Customer $customer, int $adminId, string $noticeNumber, array $lines): void
    {
        foreach ($lines as $line) {
            $allocated = BigDecimal::of($line['allocated'])->toScale(2, RoundingMode::HalfUp);
            if ($allocated->isGreaterThan('0')) {
                $this->consumeFreePool($customer, $allocated, $line['order_id'], $adminId);
            }
            if ($line['closes']) {
                $order = Order::query()->lockForUpdate()->find($line['order_id']);
                if ($order !== null && $order->payment_status === 'unpaid') {
                    $this->markOrderPaid($order, $adminId, "تسوية آجل بالإشعار الحر {$noticeNumber}");
                }
            }
        }
    }

    /** @return array{order_id: int, order_number: string, allocated: string, remaining: string, closes: bool, skipped: bool} */
    private function allocationLine(Order $order, string $allocated, string $remaining, bool $closes, bool $skipped): array
    {
        return [
            'order_id' => (int) $order->id,
            'order_number' => (string) $order->order_number,
            'allocated' => $allocated,
            'remaining' => $remaining,
            'closes' => $closes,
            'skipped' => $skipped,
        ];
    }

    private function consumeFreePool(Customer $customer, BigDecimal $need, int $orderId, int $adminId): void
    {
        $notices = $this->freeNoticesQuery($customer)->orderBy('id')->lockForUpdate()->get();
        $remaining = $need->toScale(2, RoundingMode::HalfUp);

        foreach ($notices as $notice) {
            if ($remaining->isLessThanOrEqualTo('0')) {
                break;
            }
            $used = BigDecimal::of((string) WholesalePaymentAllocation::query()
                ->where('notice_id', $notice->id)
                ->whereHas('order', fn ($q) => $q->where('status', '!=', OrderStatus::Cancelled->value))
                ->sum('amount'))->toScale(2, RoundingMode::HalfUp);
            $free = BigDecimal::of((string) $notice->amount)->minus($used)->toScale(2, RoundingMode::HalfUp);
            if ($free->isLessThanOrEqualTo('0')) {
                continue;
            }
            $take = $free->compareTo($remaining) >= 0 ? $remaining : $free;
            WholesalePaymentAllocation::create([
                'notice_id' => $notice->id,
                'order_id' => $orderId,
                'amount' => (string) $take,
                'created_by' => $adminId,
            ]);
            $remaining = $remaining->minus($take)->toScale(2, RoundingMode::HalfUp);
        }
    }

    private function reopenOrderIfUncovered(int $orderId, int $adminId, string $noticeNumber): void
    {
        $order = Order::query()->lockForUpdate()->find($orderId);
        if ($order === null || $order->payment_status !== 'paid') {
            return;
        }

        $covered = $this->orderCoveredAmount((int) $order->id);
        if ($covered->compareTo(BigDecimal::of((string) $order->grand_total)) >= 0) {
            return;
        }

        $this->transitionOrderPaymentStatus($order, 'unpaid', $adminId, "إلغاء تسوية بعد عكس الإشعار {$noticeNumber}");
    }

    // Record status transition in audit history rather than performing a silent update.
    private function markOrderPaid(Order $order, int $adminId, string $notes): void
    {
        $this->transitionOrderPaymentStatus($order, 'paid', $adminId, $notes);
    }

    private function transitionOrderPaymentStatus(Order $order, string $paymentStatus, int $adminId, string $notes): void
    {
        $order->update(['payment_status' => $paymentStatus]);
        $order->statusHistories()->create([
            'from_status' => $order->status,
            'to_status' => $order->status,
            'changed_by_type' => (new User)->getMorphClass(),
            'changed_by_id' => $adminId,
            'notes' => mb_substr($notes, 0, 500),
        ]);
    }

    private function orderCoveredAmount(int $orderId): BigDecimal
    {
        $linked = BigDecimal::of((string) WholesalePaymentNotice::query()
            ->where('order_id', $orderId)
            ->where('status', WholesalePaymentNoticeStatus::Approved->value)
            ->sum('amount'));
        $allocated = BigDecimal::of((string) WholesalePaymentAllocation::query()
            ->where('order_id', $orderId)
            ->sum('amount'));

        return $linked->plus($allocated)->toScale(2, RoundingMode::HalfUp);
    }

    private function resolveTreasury(?int $treasuryId): Treasury
    {
        if ($treasuryId !== null) {
            // Receipts must go to bank accounts or main safes, not active POS drawers during open shifts.
            $treasury = Treasury::query()->findOrFail($treasuryId);
            if (! $treasury->is_active) {
                throw new BusinessException('الخزينة المحددة غير نشطة.');
            }
            if (! in_array($treasury->type, [TreasuryType::BankAccount, TreasuryType::MainSafe], true)) {
                throw new BusinessException('اعتماد إشعار السداد مسموح للخزينة البنكية أو الرئيسية فقط.');
            }

            return $treasury;
        }

        $treasury = Treasury::query()->where('is_active', true)
            ->where('type', TreasuryType::BankAccount->value)
            ->orderBy('id')
            ->first()
            ?? Treasury::query()->where('is_active', true)
                ->where('type', TreasuryType::MainSafe->value)
                ->orderBy('id')
                ->first();

        if ($treasury === null) {
            throw new BusinessException('لا توجد خزينة بنكية أو رئيسية نشطة لتسجيل القبض.');
        }

        return $treasury;
    }
}
```

## File: bags-platform/backend/app/Services/ReturnService.php
```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\RefundMethod;
use App\Enums\ReturnStatus;
use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ReturnRequest;
use App\Models\ReturnRequestItem;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Return Merchandise Authorization (RMA) service managing the four-stage return lifecycle.
 *
 * Lifecycle stages:
 * 1. create: Submission (validates delivery status, return window, and item quantity ceilings).
 * 2. approve: Administrative pre-approval (assigns receiving branch and approved item quantities).
 * 3. receive: Physical warehouse inspection and stock ledger intake via InventoryService.
 * 4. refund: Financial reimbursement (customer wallet credit or payment gateway reference).
 *
 * Golden Rule: Requests and approvals never alter stock balances. Only the physical intake
 * stage (receive) records ledger movements: sale_return for intact goods into on_hand,
 * followed by damage bucket allocation for defective units.
 */
class ReturnService
{
    public function __construct(
        protected SettingService $settings,
        protected InventoryService $inventory,
        protected OrderService $orders,
    ) {}

    /**
     * Submits a new return request for a delivered order within the allowed return window.
     *
     * @param  array{reason: string, refund_method?: string|null, items: list<array{order_item_id: int, requested_qty: float|int|string, item_reason?: string|null, attachments?: list<UploadedFile>}>}  $data
     */
    public function createReturnRequest(Order $order, array $data, ?Customer $customer = null): ReturnRequest
    {
        $uploads = [];
        foreach ($data['items'] as $item) {
            foreach ($item['attachments'] ?? [] as $file) {
                $uploads[] = [$file, 'return_requests/pending'];
            }
        }
        $return = PrivateAttachments::transaction(function (PrivateAttachments $files) use ($order, $data, $customer): ReturnRequest {
            // Lock order row to serialize concurrent return requests against line item quantities.
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($customer !== null && (int) $order->customer_id !== (int) $customer->id) {
                throw new BusinessException('هذا الطلب لا يخص حسابك.', [], 403);
            }

            if ($order->status !== OrderStatus::Delivered) {
                throw new BusinessException('لا يمكن إنشاء طلب إرجاع إلا لطلب تم تسليمه فعليًا.');
            }
            if ($order->channel === 'pos') {
                throw new BusinessException('مرتجعات نقطة البيع تُنفَّذ من مسار POS لمنع تكرار الرد بين مسارين.');
            }

            $this->assertWithinReturnWindow($order);

            // Aggregate duplicate return lines for the same order item before validating ceilings.
            $lines = [];
            foreach ($data['items'] as $line) {
                DiscreteQuantity::assert($line['requested_qty']);
                $orderItemId = (int) $line['order_item_id'];
                $lines[$orderItemId] ??= ['qty' => 0.0, 'reason' => null, 'files' => []];
                $lines[$orderItemId]['qty'] += (float) $line['requested_qty'];
                $lines[$orderItemId]['reason'] = $line['item_reason'] ?? $lines[$orderItemId]['reason'];
                $lines[$orderItemId]['files'] = array_merge($lines[$orderItemId]['files'], $line['attachments'] ?? []);
            }

            $orderItems = $order->items()->whereIn('id', array_keys($lines))->get()->keyBy('id');
            if ($orderItems->count() !== count($lines)) {
                throw new BusinessException('أحد البنود المطلوب إرجاعها لا يتبع هذا الطلب.');
            }

            foreach ($lines as $orderItemId => $line) {
                $orderItem = $orderItems->get($orderItemId);
                // Active return requests (excluding rejected/cancelled) count against remaining returnable quantity.
                $previouslyRequested = (float) ReturnRequestItem::query()
                    ->where('order_item_id', $orderItemId)
                    ->join('return_requests', 'return_requests.id', '=', 'return_request_items.return_request_id')
                    ->whereNotIn('return_requests.status', [ReturnStatus::Rejected->value, ReturnStatus::Cancelled->value])
                    ->selectRaw("COALESCE(SUM(CASE WHEN return_requests.status IN ('received', 'refunded') THEN received_good_qty + received_damaged_qty WHEN return_requests.status = 'approved' THEN approved_qty ELSE requested_qty END), 0) as total")
                    ->value('total');
                if (round($previouslyRequested + $line['qty'], 4) > round((float) $orderItem->qty, 4)) {
                    throw new BusinessException("كمية الإرجاع للصنف ({$orderItem->sku_snapshot}) تتجاوز الكمية المشتراة بعد احتساب مرتجعات سابقة.");
                }
            }

            $return = ReturnRequest::create([
                'return_number' => $this->settings->generateNextNumber('return'),
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'status' => ReturnStatus::Pending,
                'refund_method' => $data['refund_method'] ?? null,
                'total_refund_amount' => 0,
                'reason' => $data['reason'],
            ]);

            foreach ($lines as $orderItemId => $line) {
                $orderItem = $orderItems->get($orderItemId);
                $return->items()->create([
                    'order_item_id' => $orderItem->id,
                    'product_variant_id' => $orderItem->product_variant_id,
                    'requested_qty' => $line['qty'],
                    'unit_refund_price' => $this->netUnitRefundPrice($orderItem),
                    'item_reason' => $line['reason'],
                ]);
            }

            $this->storeAttachments($return, $data['items'], $files);

            return $return;
        }, $uploads);

        return $return->fresh(['items']);
    }

    /**
     * Grants administrative pre-approval, designating receiving branch and approved item quantities.
     *
     * Does not alter inventory balances until physical intake.
     *
     * @param  array{branch_id: int, admin_notes?: string|null, items?: list<array{id: int, approved_qty: float|int|string}>}  $data
     */
    public function approve(ReturnRequest $return, User $admin, array $data): ReturnRequest
    {
        return DB::transaction(function () use ($return, $admin, $data): ReturnRequest {
            $order = Order::query()->lockForUpdate()->findOrFail($return->order_id);
            $return = ReturnRequest::query()->lockForUpdate()->findOrFail($return->id);
            if ($return->status !== ReturnStatus::Pending) {
                throw new BusinessException('لا يمكن اعتماد طلب مرتجع إلا وهو قيد المراجعة.');
            }

            if (! Branch::query()->whereKey($data['branch_id'])->where('is_active', true)->exists()) {
                throw new BusinessException('فرع الاستلام غير نشط.');
            }
            $return->load(['items.orderItem', 'items.variant.product']);
            $approved = collect($data['items'] ?? [])->keyBy(fn (array $line): int => (int) $line['id']);
            foreach ($approved->keys() as $itemId) {
                if (! $return->items->contains('id', $itemId)) {
                    throw new BusinessException('أحد البنود المعتمدة لا يتبع طلب المرتجع هذا.');
                }
            }

            $totalApproved = 0.0;
            foreach ($return->items as $item) {
                // Items omitted from approval payload default to their full requested quantity.
                $qty = $approved->has($item->id)
                    ? (float) $approved->get($item->id)['approved_qty']
                    : (float) $item->requested_qty;
                DiscreteQuantity::assert($qty, true);
                if ($qty < 0 || round($qty, 4) > round((float) $item->requested_qty, 4)) {
                    throw new BusinessException("الكمية المعتمدة للصنف ({$item->orderItem?->sku_snapshot}) يجب أن تكون بين صفر والمطلوب.");
                }
                $item->update(['approved_qty' => $qty]);
                $totalApproved += $qty;
            }
            if ($totalApproved <= 0) {
                throw new BusinessException('لا توجد كميات معتمدة؛ ارفض الطلب بدلًا من اعتماده إن لم يُقبل أي بند.');
            }

            $return->update([
                'branch_id' => (int) $data['branch_id'],
                'status' => ReturnStatus::Approved,
                'approved_by' => $admin->id,
                'approved_at' => now(),
                'admin_notes' => $data['admin_notes'] ?? $return->admin_notes,
            ]);

            return $return->fresh(['items']);
        });
    }

    /**
     * Performs physical inspection and records inventory intake movements into warehouse stock.
     *
     * @param  array{items: list<array{id: int, received_good_qty: float|int|string, received_damaged_qty: float|int|string}>}  $data
     */
    public function receive(ReturnRequest $return, User $admin, array $data): ReturnRequest
    {
        return DB::transaction(function () use ($return, $admin, $data): ReturnRequest {
            $order = Order::query()->lockForUpdate()->findOrFail($return->order_id);
            $return = ReturnRequest::query()->lockForUpdate()->findOrFail($return->id);
            if ($return->status !== ReturnStatus::Approved) {
                throw new BusinessException('لا يمكن استلام مرتجع إلا بعد الموافقة المبدئية عليه.');
            }
            if ($return->branch_id === null) {
                throw new BusinessException('لم يُحدد فرع الاستلام عند اعتماد هذا المرتجع.');
            }
            if (! Branch::query()->whereKey($return->branch_id)->where('is_active', true)->exists()) {
                throw new BusinessException('فرع الاستلام غير نشط.');
            }

            $return->load(['items.orderItem', 'items.variant.product']);
            $lines = collect($data['items'])->keyBy(fn (array $line): int => (int) $line['id']);
            foreach ($lines->keys() as $itemId) {
                if (! $return->items->contains('id', $itemId)) {
                    throw new BusinessException('أحد البنود المستلمة لا يتبع طلب المرتجع هذا.');
                }
            }
            foreach ($return->items as $item) {
                if (! $lines->has($item->id)) {
                    throw new BusinessException('بيانات الاستلام يجب أن تغطي كل بنود المرتجع (بند بلا استلام فعلي يُرسل بصفر).');
                }
            }

            // Aggregate prior returned quantities across order items in a single query to avoid N+1 queries.
            $previousByOrderItem = ReturnRequestItem::query()
                ->whereIn('order_item_id', $return->items->pluck('order_item_id')->all())
                ->whereHas('returnRequest', fn ($q) => $q->whereIn('status', [ReturnStatus::Received, ReturnStatus::Refunded]))
                ->selectRaw('order_item_id, COALESCE(SUM(received_good_qty + received_damaged_qty), 0) as qty, COALESCE(SUM(refund_amount), 0) as refunded')
                ->groupBy('order_item_id')
                ->get()
                ->keyBy('order_item_id');

            $locationId = $this->inventory->receivingLocation((int) $return->branch_id);
            $levels = $this->inventory->lockLevels($return->items->map(fn ($item) => [
                'product_variant_id' => $item->product_variant_id,
                'branch_id' => (int) $return->branch_id, 'location_id' => $locationId,
            ])->all());
            $costs = StockMovement::query()->whereIn('reference_type', array_unique([$order->getMorphClass(), Order::class]))
                ->where('reference_id', $order->id)->where('type', StockMovementType::Sale)
                ->whereNotNull('unit_cost')->pluck('unit_cost', 'product_variant_id');

            $refundTotal = 0.0;
            foreach ($return->items as $item) {
                $line = $lines->get($item->id);
                DiscreteQuantity::assert($line['received_good_qty'], true);
                DiscreteQuantity::assert($line['received_damaged_qty'], true);
                $good = (float) $line['received_good_qty'];
                $damaged = (float) $line['received_damaged_qty'];
                if (round($good + $damaged, 4) > round((float) $item->approved_qty, 4)) {
                    throw new BusinessException("المستلم للصنف ({$item->orderItem?->sku_snapshot}) يتجاوز الكمية المعتمدة.");
                }

                $base = [
                    'product_variant_id' => $item->product_variant_id,
                    'branch_id' => (int) $return->branch_id,
                    'location_id' => $locationId,
                    'unit_cost' => $costs->get($item->product_variant_id),
                    'reference_type' => ReturnRequest::class,
                    'reference_id' => $return->id,
                    'created_by' => $admin->id,
                ];
                $level = $levels->get($this->inventory->levelKey($base));
                if ($good > 0) {
                    $this->inventory->recordMovement([...$base,
                        'type' => StockMovementType::SaleReturn,
                        'affected_bucket' => StockBucket::OnHand,
                        'quantity' => $good,
                        'notes' => "مرتجع سليم — طلب {$return->return_number}",
                    ], $level);
                }
                if ($damaged > 0) {
                    // Damaged units return physically to on_hand before being segregated into the damaged bucket.
                    $this->inventory->recordMovement([...$base,
                        'type' => StockMovementType::SaleReturn,
                        'affected_bucket' => StockBucket::OnHand,
                        'quantity' => $damaged,
                        'notes' => "مرتجع تالف (عودة فيزيائية) — طلب {$return->return_number}",
                    ], $level);
                    $this->inventory->recordMovement([...$base,
                        'type' => StockMovementType::Damage,
                        'affected_bucket' => StockBucket::Damaged,
                        'quantity' => $damaged,
                        'notes' => "مرتجع تالف بعد الفحص — طلب {$return->return_number}",
                    ], $level);
                }

                $receivedTotal = $good + $damaged;
                if ($receivedTotal > 0 && $item->variant?->product_id) {
                    Product::query()->whereKey($item->variant->product_id)
                        ->update(['sales_count' => DB::raw('GREATEST(sales_count - '.(int) round($receivedTotal).', 0)')]);
                }

                $previous = $previousByOrderItem->get($item->order_item_id);
                $previousQty = (float) ($previous->qty ?? 0);
                $previousRefunded = (float) ($previous->refunded ?? 0);
                $orderItem = $item->orderItem;
                $netCents = $this->netLineRefundCents($orderItem);
                $cumulativeQty = $previousQty + $receivedTotal;
                $targetCents = $cumulativeQty >= (float) $orderItem->qty
                    ? $netCents
                    : min($netCents, (int) round($cumulativeQty * (float) $item->unit_refund_price * 100));
                $refundAmount = max(0, $targetCents - (int) round($previousRefunded * 100)) / 100;
                $item->update([
                    'refund_amount' => $refundAmount,
                    'received_good_qty' => $good,
                    'received_damaged_qty' => $damaged,
                ]);
                $refundTotal += $refundAmount;
            }

            $return->update([
                'total_refund_amount' => round($refundTotal, 2),
                'status' => ReturnStatus::Received,
                'received_by' => $admin->id,
                'received_at' => now(),
            ]);

            return $return->fresh(['items']);
        });
    }

    /**
     * Issues financial reimbursement to the customer via wallet credit or recorded payment gateway reference.
     *
     * @param  array{refund_method?: string|null, refund_reference?: string|null}  $data
     */
    public function refund(ReturnRequest $return, User $admin, array $data): ReturnRequest
    {
        return DB::transaction(function () use ($return, $admin, $data): ReturnRequest {
            $order = Order::query()->lockForUpdate()->findOrFail($return->order_id);
            $return = ReturnRequest::query()->lockForUpdate()->findOrFail($return->id);
            if ($return->status !== ReturnStatus::Received) {
                throw new BusinessException('لا يمكن رد مبلغ مرتجع إلا بعد استلامه وفحصه.');
            }

            $method = $data['refund_method'] ?? $return->refund_method?->value;
            if ($method === null) {
                throw new BusinessException('حدد طريقة رد المبلغ قبل إتمام الاسترداد.');
            }
            $reference = $data['refund_reference'] ?? $return->refund_reference;
            if (in_array($method, [RefundMethod::OriginalPayment->value, RefundMethod::BankTransfer->value], true)
                && trim((string) $reference) === '') {
                throw new BusinessException('مرجع التحويل أو بوابة الدفع مطلوب لإتمام الاسترداد.');
            }

            if (! in_array($order->payment_status, ['paid', 'partially_refunded', 'refunded'], true)) {
                throw new BusinessException('لا يمكن رد مبلغ نقدي أو إلى المحفظة لطلب غير مدفوع. يُقيَّد المرتجع كتخفيض للمستحق حتى يُبنى مسار إشعار دائن.');
            }
            if ($order->payment_method === 'wallet' && $method !== RefundMethod::Wallet->value) {
                throw new BusinessException('طلب مدفوع بالمحفظة يُرد إلى المحفظة فقط.');
            }
            $reference = $reference !== null ? trim((string) $reference) : $reference;
            if ($reference !== null && $reference !== '' && ReturnRequest::query()
                ->where('id', '!=', $return->id)
                ->whereNotIn('status', [ReturnStatus::Rejected, ReturnStatus::Cancelled])
                ->whereRaw('lower(btrim(refund_reference)) = ?', [mb_strtolower($reference)])
                ->exists()) {
                throw new BusinessException('مرجع الرد مسجّل سابقًا.', [], 409);
            }

            if ($method === RefundMethod::Wallet->value) {
                if ($return->customer_id === null) {
                    throw new BusinessException('الاسترداد إلى المحفظة يتطلب حساب عميل مسجل؛ اختر طريقة أخرى لطلبات الزوار.');
                }
                app(WalletService::class)->record($return->customer_id, (float) $return->total_refund_amount,
                    'return_refund', 'return_request', $return->id, $admin->id);
            }

            $return->update([
                'refund_method' => $method,
                'refund_reference' => $reference,
                'status' => ReturnStatus::Refunded,
                'refunded_by' => $admin->id,
                'refunded_at' => now(),
            ]);

            $this->syncOrderPaymentStatusAfterRefund($order);
            if ($this->isOrderFullyReturned($order)) {
                $this->orders->changeStatus(
                    $order,
                    OrderStatus::Returned,
                    $admin,
                    "إرجاع كامل لبنود الطلب عبر طلب المرتجع {$return->return_number}",
                    null,
                    false,
                );
            }

            return $return->fresh(['items']);
        });
    }

    /**
     * Permanently deletes a pending return request and removes associated attachment files from private storage.
     */
    public function delete(ReturnRequest $return): void
    {
        DB::transaction(function () use ($return): void {
            $return = ReturnRequest::query()->lockForUpdate()->findOrFail($return->id);
            if ($return->status !== ReturnStatus::Pending) {
                throw new BusinessException('يمكن حذف المرتجعات قيد المراجعة فقط؛ استخدم الإلغاء للحالات الأخرى.');
            }
            $paths = $return->items()->get()->flatMap(fn ($item) => $item->attachments ?? [])->all();
            $return->delete();
            DB::afterCommit(function () use ($paths): void {
                Storage::disk('r2')->delete($paths);
            });
        });
    }

    /**
     * Rejects a pending return request without modifying stock or treasury balances.
     */
    public function reject(ReturnRequest $return, User $admin, ?string $notes = null): ReturnRequest
    {
        return DB::transaction(function () use ($return, $notes): ReturnRequest {
            $return = ReturnRequest::query()->lockForUpdate()->findOrFail($return->id);
            if ($return->status !== ReturnStatus::Pending) {
                throw new BusinessException('لا يمكن رفض طلب مرتجع إلا وهو قيد المراجعة.');
            }
            $return->update([
                'status' => ReturnStatus::Rejected,
                'admin_notes' => $notes ?? $return->admin_notes,
            ]);

            return $return->fresh(['items']);
        });
    }

    /**
     * Cancels an unreceived return request (pending or approved).
     */
    public function cancel(ReturnRequest $return, ?string $notes = null): ReturnRequest
    {
        return DB::transaction(function () use ($return, $notes): ReturnRequest {
            $return = ReturnRequest::query()->lockForUpdate()->findOrFail($return->id);
            if (! in_array($return->status, [ReturnStatus::Pending, ReturnStatus::Approved], true)) {
                throw new BusinessException('لا يمكن إلغاء مرتجع بعد استلامه.');
            }
            $return->update([
                'status' => ReturnStatus::Cancelled,
                'admin_notes' => $notes ?? $return->admin_notes,
            ]);

            return $return->fresh(['items']);
        });
    }

    /**
     * Updates non-financial return metadata, routing rejected and cancelled transitions to dedicated handlers.
     *
     * @param  array{admin_notes?: string|null, refund_method?: string|null, status?: string|null}  $data
     */
    public function updateDetails(ReturnRequest $return, User $admin, array $data): ReturnRequest
    {
        if (($data['status'] ?? null) === ReturnStatus::Rejected->value) {
            return $this->reject($return, $admin, $data['admin_notes'] ?? null);
        }
        if (($data['status'] ?? null) === ReturnStatus::Cancelled->value) {
            return $this->cancel($return, $data['admin_notes'] ?? null);
        }

        return DB::transaction(function () use ($return, $data): ReturnRequest {
            $return = ReturnRequest::query()->lockForUpdate()->findOrFail($return->id);
            if (! in_array($return->status, [ReturnStatus::Pending, ReturnStatus::Approved, ReturnStatus::Received], true)
                && array_key_exists('refund_method', $data)) {
                throw new BusinessException('لا يمكن تغيير طريقة الرد بعد اكتمال الاسترداد.');
            }

            $updates = [];
            if (array_key_exists('admin_notes', $data)) {
                $updates['admin_notes'] = $data['admin_notes'];
            }
            if (array_key_exists('refund_method', $data)) {
                $updates['refund_method'] = $data['refund_method'];
            }
            if ($updates !== []) {
                $return->update($updates);
            }

            return $return->fresh(['items']);
        });
    }

    /**
     * Validates that the return request is submitted within the configured days since delivery.
     */
    private function assertWithinReturnWindow(Order $order): void
    {
        $windowDays = (int) $this->settings->get('return_window_days', 14);
        if ($windowDays === 0) {
            throw new BusinessException('الإرجاع متوقف من إعدادات المتجر.');
        }
        if ($windowDays < 0) {
            return;
        }

        $deliveredAt = $order->statusHistories()
            ->where('to_status', OrderStatus::Delivered->value)
            ->latest('id')
            ->value('created_at');

        if ($deliveredAt === null) {
            throw new BusinessException('لا يوجد سجل تسليم موثوق لحساب فترة الاسترجاع.');
        }

        if ($deliveredAt->copy()->addDays($windowDays)->isPast()) {
            throw new BusinessException("انتهت فترة الاسترجاع المسموحة ({$windowDays} يومًا من التسليم) لهذا الطلب.");
        }
    }

    /**
     * Computes effective net unit refund price after proportional discounts and non-inclusive taxes.
     */
    private function netUnitRefundPrice(OrderItem $orderItem): float
    {
        $qty = (float) $orderItem->qty;
        if ($qty <= 0) {
            return (float) $orderItem->unit_price;
        }

        return round($this->netLineRefundCents($orderItem) / 100 / $qty, 4);
    }

    /**
     * Computes net payable amount in integer cents for an order item (unit_price * qty - discounts + item taxes).
     */
    private function netLineRefundCents(OrderItem $orderItem): int
    {
        $net = (float) $orderItem->unit_price * (float) $orderItem->qty
            - (float) $orderItem->discount_amount
            + (float) $orderItem->tax_amount;

        return (int) round(max(0.0, $net) * 100);
    }

    /**
     * Updates order payment status to refunded or partially_refunded based on cumulative return reimbursements.
     */
    private function syncOrderPaymentStatusAfterRefund(Order $order): void
    {
        if (! in_array($order->payment_status, ['paid', 'partially_refunded', 'refunded'], true)) {
            return;
        }
        $refundedCents = (int) round((float) $order->returnRequests()->where('status', ReturnStatus::Refunded)->sum('total_refund_amount') * 100);
        $refundableCents = (int) $order->items()->get()->sum(fn (OrderItem $item): int => $this->netLineRefundCents($item));
        if ($refundableCents > 0 && $refundedCents >= $refundableCents) {
            $order->update(['payment_status' => 'refunded', 'requires_refund' => false]);
        } elseif ($refundedCents > 0) {
            $order->update(['payment_status' => 'partially_refunded']);
        }
    }

    /**
     * Stores uploaded defect photos on private disk and updates item attachment paths.
     *
     * @param  list<array{order_item_id: int, attachments?: list<UploadedFile>}>  $rawItems
     */
    private function storeAttachments(ReturnRequest $return, array $rawItems, PrivateAttachments $files): void
    {
        $filesByOrderItem = [];
        foreach ($rawItems as $line) {
            foreach ($line['attachments'] ?? [] as $file) {
                if ($file instanceof UploadedFile) {
                    $filesByOrderItem[(int) $line['order_item_id']][] = $file;
                }
            }
        }
        if ($filesByOrderItem === []) {
            return;
        }

        $return->load('items');
        foreach ($return->items as $item) {
            $itemFiles = $filesByOrderItem[(int) $item->order_item_id] ?? [];
            if ($itemFiles === []) {
                continue;
            }
            $paths = [];
            foreach ($itemFiles as $file) {
                $paths[] = $files->store($file, "return_requests/{$return->id}");
            }
            $item->update(['attachments' => array_merge($item->attachments ?? [], $paths)]);
        }
    }

    /**
     * Checks whether all ordered items have been fully received and refunded.
     */
    private function isOrderFullyReturned(Order $order): bool
    {
        $items = $order->items()->get();
        if ($items->isEmpty()) {
            return false;
        }

        $receivedByItem = ReturnRequestItem::query()
            ->whereIn('order_item_id', $items->pluck('id'))
            ->whereHas('returnRequest', fn ($q) => $q->where('status', ReturnStatus::Refunded->value))
            ->selectRaw('order_item_id, COALESCE(SUM(received_good_qty + received_damaged_qty), 0) as total')
            ->groupBy('order_item_id')
            ->pluck('total', 'order_item_id');
        foreach ($items as $orderItem) {
            $received = (float) ($receivedByItem[$orderItem->id] ?? 0);
            if (round($received, 4) < round((float) $orderItem->qty, 4)) {
                return false;
            }
        }

        return true;
    }
}
```

## File: bags-platform/backend/routes/api/pos.php
```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Pos\PosAuthController;
use App\Http\Controllers\Api\V1\Pos\PosBootstrapController;
use App\Http\Controllers\Api\V1\Pos\PosCustomerController;
use App\Http\Controllers\Api\V1\Pos\PosProductController;
use App\Http\Controllers\Api\V1\Pos\PosSaleController;
use App\Http\Controllers\Api\V1\Pos\PosShiftController;
use App\Http\Controllers\Api\V1\Pos\PosStockController;
use App\Http\Controllers\Api\V1\Shared\NotificationController;
use Illuminate\Support\Facades\Route;

// Numeric route identifier patterns
Route::pattern('sale', '[0-9]{1,18}');
Route::pattern('transfer', '[0-9]{1,18}');
Route::pattern('stocktake', '[0-9]{1,18}');
Route::pattern('held_sale', '[0-9]{1,18}');

// Legacy POS cashier login; disabled when LEGACY_LOGIN_ENABLED=false in favor of /api/v1/auth/login.
if (config('auth.legacy_login_enabled', false)) {
    Route::post('auth/login', [PosAuthController::class, 'login'])
        ->middleware('throttle:pos-login')
        ->name('auth.login');
}

Route::middleware(['auth:sanctum', 'auth.staff', 'permission:pos.access'])->group(function (): void {
    Route::post('auth/logout', [PosAuthController::class, 'logout'])->name('auth.logout');
    Route::get('bootstrap', [PosBootstrapController::class, 'show'])->name('bootstrap');
    Route::get('treasuries/available', [PosShiftController::class, 'availableDrawers'])->name('treasuries.available');

    // Shift management: opening and inspecting shifts do not require an active shift.
    Route::post('shifts/open', [PosShiftController::class, 'open'])->name('shifts.open');
    Route::get('shifts/current', [PosShiftController::class, 'current'])->name('shifts.current');

    // Product catalog, lookup, customers, and held carts accessible prior to shift opening.
    Route::get('catalog', [PosProductController::class, 'catalog'])->name('catalog');
    Route::get('customers', [PosCustomerController::class, 'index'])->name('customers.index');
    Route::get('products/lookup', [PosProductController::class, 'lookup'])->name('products.lookup');
    Route::get('sales/held', [PosSaleController::class, 'held'])->name('sales.held');

    // Protected endpoints requiring an active shift (pos.shift middleware injects current shift).
    Route::middleware('pos.shift')->group(function (): void {
        Route::get('shifts/current/summary', [PosShiftController::class, 'summary'])->name('shifts.current.summary');
        Route::post('shifts/close', [PosShiftController::class, 'close'])->name('shifts.close');

        // POS sales, held carts, refunds, and exchanges.
        Route::post('sales', [PosSaleController::class, 'store'])->name('sales.store');
        Route::post('sales/hold', [PosSaleController::class, 'hold'])->name('sales.hold');
        Route::post('sales/quote', [PosSaleController::class, 'quote'])->name('sales.quote');
        Route::get('sales/lookup', [PosSaleController::class, 'lookupForReturn'])->middleware('permission:pos.sales.refund')->name('sales.lookup');
        Route::post('sales/held/{held_sale}/resume', [PosSaleController::class, 'resumeHeld'])->name('sales.held.resume');
        Route::delete('sales/held/{held_sale}', [PosSaleController::class, 'deleteHeld'])->name('sales.held.delete');
        Route::post('sales/{sale}/return', [PosSaleController::class, 'processReturn'])->middleware('permission:pos.sales.refund')->name('sales.return');
        Route::post('sales/{sale}/exchange', [PosSaleController::class, 'processExchange'])->middleware('permission:pos.sales.refund')->name('sales.exchange');

        // Customers quick creation during active shift.
        Route::post('customers', [PosCustomerController::class, 'store'])->name('customers.store');

        // Branch inventory, transfers, and barcode scanning.
        Route::get('stock', [PosStockController::class, 'index'])->name('stock.index');
        Route::get('transfers/incoming', [PosStockController::class, 'incoming'])->name('transfers.incoming');
        Route::get('transfer-sources', [PosStockController::class, 'sources'])->name('transfer-sources.index');
        Route::post('transfer-requests', [PosStockController::class, 'requestTransfer'])->name('transfer-requests.store');
        Route::post('transfers/{transfer}/receive', [PosStockController::class, 'receiveTransfer'])->name('transfers.receive');
        Route::get('stocktakes/current', [PosStockController::class, 'currentStocktake'])->name('stocktakes.current');
        Route::post('stocktakes/{stocktake}/scan', [PosStockController::class, 'scan'])->name('stocktakes.scan');
    });

    // Staff notifications
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/{id}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');
});
```

## File: bags-platform/backend/app/Services/PosService.php
```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PosShiftStatus;
use App\Enums\PriceListType;
use App\Enums\StockBucket;
use App\Enums\StockMovementType;
use App\Enums\TreasuryTransactionType;
use App\Enums\TreasuryType;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\PosHeldSale;
use App\Models\PosShift;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Treasury;
use App\Models\User;
use App\Support\ArabicDigits;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Core engine for POS shift lifecycle, immediate retail sales, returns, and cash drawer management.
 */
class PosService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly PricingService $pricing,
        private readonly TreasuryService $treasury,
        private readonly SettingService $settings,
    ) {}

    /**
     * Opens a new POS shift and drawer for a cashier, transferring opening float from the branch main safe.
     *
     * @param  User  $user  Operating cashier
     * @param  int  $branchId  Operating branch ID
     * @param  int  $treasuryId  POS drawer treasury ID
     * @param  float  $openingCash  Declared opening float amount
     * @return PosShift Created open shift
     *
     * @throws BusinessException If cashier or drawer already has an active shift, or float mismatch
     */
    public function openShift(User $user, int $branchId, int $treasuryId, float $openingCash): PosShift
    {
        return DB::transaction(function () use ($user, $branchId, $treasuryId, $openingCash): PosShift {
            app(BranchAccess::class)->assertBranch($user->id, $branchId);
            app(BranchAccess::class)->lockActiveBranch($branchId);
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! is_finite($openingCash) || $openingCash < 0) {
                throw new BusinessException('العهدة النقدية غير صالحة.');
            }

            $existingUserShift = PosShift::query()
                ->where('user_id', $user->id)
                ->where('status', PosShiftStatus::Open)
                ->lockForUpdate()
                ->exists();

            if ($existingUserShift) {
                throw new BusinessException('لديك وردية مفتوحة بالفعل. أغلقها أولًا قبل فتح وردية جديدة.');
            }

            // Branch-scoped drawer lookup runs before the busy check so cashiers
            // from other branches get a neutral 404 instead of cross-branch state.
            $treasury = Treasury::query()
                ->where('id', $treasuryId)
                ->where('branch_id', $branchId)
                ->where('type', 'pos_drawer')
                ->where('is_active', true)
                ->firstOrFail();

            $existingDrawerShift = PosShift::query()
                ->where('treasury_id', $treasuryId)
                ->where('status', PosShiftStatus::Open)
                ->lockForUpdate()
                ->exists();

            if ($existingDrawerShift) {
                throw new BusinessException('درج النقدية المحدد مفتوح على وردية أخرى.');
            }

            $mainSafe = Treasury::query()->where('branch_id', $branchId)->where('type', 'main_safe')->where('is_active', true)->orderBy('id')->first();
            $treasuries = $this->treasury->lockTreasuries(array_filter([$treasury->id, $mainSafe?->id]));
            $treasury = $treasuries->get($treasury->id);
            if (PosShift::query()->where('treasury_id', $treasuryId)->whereIn('status', [PosShiftStatus::Open, PosShiftStatus::ClosedPendingApproval])->exists()) {
                throw new BusinessException('الدرج مرتبط بوردية مفتوحة أو تنتظر الاعتماد.');
            }
            $existingCash = $this->minor($treasury->current_balance);
            $openingCents = $this->minor($openingCash);
            if ($existingCash !== 0 && $existingCash !== $openingCents) {
                throw new BusinessException('العهدة لا تطابق رصيد الدرج القائم؛ سوِّ الرصيد قبل فتح الوردية.');
            }
            if ($existingCash === 0 && $openingCents > 0 && $mainSafe === null) {
                throw new BusinessException('يلزم وجود خزنة رئيسية لتمويل عهدة الوردية.');
            }

            $shiftNumber = $this->settings->generateNextNumber('shift');

            $shift = PosShift::create([
                'shift_number' => $shiftNumber,
                'user_id' => $user->id,
                'branch_id' => $branchId,
                'treasury_id' => $treasuryId,
                'status' => PosShiftStatus::Open,
                'opened_at' => now(),
                'opening_cash' => $openingCash,
                'cash_sales_total' => 0,
                'card_sales_total' => 0,
                'cash_returns_total' => 0,
                'cash_expenses_total' => 0,
                'expected_closing_cash' => $openingCash,
            ]);

            // Fund opening float directly from the branch safe without minting untracked cash.
            if ($existingCash === 0 && $openingCents > 0) {
                $this->treasury->record([
                    'treasury_id' => $mainSafe->id, 'shift_id' => $shift->id,
                    'type' => TreasuryTransactionType::TransferOut, 'amount' => -$openingCash,
                    'reference_type' => PosShift::class, 'reference_id' => $shift->id,
                    'created_by' => $user->id, 'notes' => 'تمويل عهدة الوردية '.$shiftNumber,
                ]);
                $this->treasury->record([
                    'treasury_id' => $treasury->id,
                    'shift_id' => $shift->id,
                    'type' => TreasuryTransactionType::OpeningBalance,
                    'amount' => $openingCash,
                    'reference_type' => PosShift::class,
                    'reference_id' => $shift->id,
                    'created_by' => $user->id,
                    'notes' => "رصيد فتح وردية #{$shiftNumber}",
                ]);
            }

            return $shift;
        });
    }

    /**
     * Computes live financial totals and delivered invoice count for a shift.
     *
     * @param  PosShift  $s  Target shift
     * @return array{
     *     opening_cash: float,
     *     cash_sales_total: float,
     *     card_sales_total: float,
     *     cash_returns_total: float,
     *     cash_expenses_total: float,
     *     expected_closing_cash: float,
     *     invoices_count: int
     * }
     */
    public function computeShiftTotals(PosShift $s): array
    {
        $salesStats = Order::query()
            ->where('pos_shift_id', $s->id)
            ->where('status', OrderStatus::Delivered)
            ->selectRaw('
                COALESCE(SUM(cash_amount), 0) AS cash_sales,
                COALESCE(SUM(card_amount), 0) AS card_sales
            ')
            ->first();

        $cashSales = (float) ($salesStats?->cash_sales ?? 0);
        $cardSales = (float) ($salesStats?->card_sales ?? 0);

        $cashReturns = (float) Order::query()
            ->where('pos_shift_id', $s->id)
            ->where('status', OrderStatus::Returned)
            ->sum('cash_amount');

        $cashExpenses = (float) Expense::query()
            ->where('shift_id', $s->id)
            ->whereNull('voided_at')
            ->sum('amount');

        $openingCash = (float) $s->opening_cash;
        $expectedClosingCash = $openingCash
            + $cashSales
            - $cashReturns
            - $cashExpenses;

        return [
            'opening_cash' => $openingCash,
            'cash_sales_total' => $cashSales,
            'card_sales_total' => $cardSales,
            'cash_returns_total' => $cashReturns,
            'cash_expenses_total' => $cashExpenses,
            'expected_closing_cash' => $expectedClosingCash,
            'invoices_count' => Order::where('pos_shift_id', $s->id)->where('status', OrderStatus::Delivered)->count(),
        ];
    }

    /**
     * Lists active POS drawers for a branch with busy status and current balance.
     *
     * @param  int  $branchId  Target branch ID
     * @return array<int, array{id: int, name: string, code: string, busy: bool, balance: string}>
     */
    public function getAvailableDrawers(int $branchId): array
    {
        $drawers = Treasury::query()
            ->where('branch_id', $branchId)
            ->where('type', TreasuryType::PosDrawer)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        if ($drawers->isEmpty()) {
            return [];
        }

        $busyIds = PosShift::query()
            ->whereIn('treasury_id', $drawers->pluck('id')->all())
            ->whereIn('status', [PosShiftStatus::Open, PosShiftStatus::ClosedPendingApproval])
            ->pluck('treasury_id')
            ->flip();

        return $drawers->map(fn (Treasury $drawer): array => [
            'id' => $drawer->id,
            'name' => $drawer->name,
            'code' => $drawer->code,
            'busy' => $busyIds->has($drawer->id),
            'balance' => number_format((float) $drawer->current_balance, 2, '.', ''),
        ])->values()->all();
    }

    /**
     * Computes an MD5 version hash of the branch product catalog and stock levels.
     *
     * @param  int  $branchId  Target branch ID
     * @return string 32-character MD5 hex string
     */
    public function computeCatalogVersion(int $branchId): string
    {
        $maxVariantUpdated = (string) (ProductVariant::query()->max('updated_at') ?? '');
        // Catalog contents can change more than once in the same second.
        $stockVersion = $this->catalogStockLevels($branchId)->toBase()->selectRaw(
            "md5(string_agg(json_build_array(
                id, product_variant_id, on_hand, available, reorder_level
            )::text, ',' ORDER BY id)) as version"
        )->value('version');
        $productVersion = Product::query()
            ->where('is_active', true)
            ->whereNotNull('published_at')
            ->toBase()
            ->selectRaw("md5(string_agg(concat(id, ':', updated_at), ',' ORDER BY id)) as version")
            ->value('version');
        // Use the eligible prices so schedule boundaries also change the tag.
        $pricingVersion = $this->catalogPriceItems($branchId)->toBase()->selectRaw(
            "md5(string_agg(json_build_array(
                price_list_items.id, price_list_items.product_variant_id,
                price_list_items.min_qty, price_list_items.price,
                price_lists.type, price_lists.branch_id, price_lists.priority
            )::text, ',' ORDER BY price_list_items.id)) as version"
        )->value('version');

        return md5($maxVariantUpdated.'|'.$stockVersion.'|'.$productVersion.'|'.$pricingVersion);
    }

    /**
     * Closes an active POS shift, recalculates expected cash, and records cash difference.
     *
     * @param  PosShift  $shift  Active shift to close
     * @param  float  $actualCash  Physical cash count declared by cashier
     * @param  string|null  $notes  Optional closing remarks
     * @return PosShift Shift moved to pending approval
     *
     * @throws BusinessException If shift is not open or amount is invalid
     */
    public function closeShift(PosShift $shift, float $actualCash, ?string $notes): PosShift
    {
        if ($shift->status !== PosShiftStatus::Open) {
            throw new BusinessException('يمكن إغلاق الورديات المفتوحة فقط.');
        }

        return DB::transaction(function () use ($shift, $actualCash, $notes): PosShift {
            $shift = $this->lockOpenShift($shift);
            app(BranchAccess::class)->lockActiveBranch((int) $shift->branch_id);
            if (! is_finite($actualCash) || $actualCash < 0) {
                throw new BusinessException('النقدية الفعلية غير صالحة.');
            }

            $totals = $this->computeShiftTotals($shift);
            $expectedClosingCash = $totals['expected_closing_cash'];
            $cashDifference = $actualCash - $expectedClosingCash;

            $shift->update([
                'status' => PosShiftStatus::ClosedPendingApproval,
                'closed_at' => now(),
                'cash_sales_total' => $totals['cash_sales_total'],
                'card_sales_total' => $totals['card_sales_total'],
                'cash_returns_total' => $totals['cash_returns_total'],
                'cash_expenses_total' => $totals['cash_expenses_total'],
                'expected_closing_cash' => $expectedClosingCash,
                'actual_closing_cash' => $actualCash,
                'cash_difference' => $cashDifference,
                'notes' => $notes,
            ]);

            return $shift->fresh();
        });
    }

    /**
     * Administratively approves a closed shift and sweeps drawer cash to the branch main safe.
     *
     * @param  PosShift  $shift  Closed shift awaiting approval
     * @param  User  $approver  Authorizing supervisor/admin
     * @return PosShift Approved shift instance
     *
     * @throws BusinessException If shift is not in pending approval state
     */
    public function approveShift(PosShift $shift, User $approver): PosShift
    {
        if ($shift->status !== PosShiftStatus::ClosedPendingApproval) {
            throw new BusinessException('يمكن اعتماد الورديات المغلقة المنتظرة للاعتماد فقط.');
        }

        return DB::transaction(function () use ($shift, $approver): PosShift {
            $shift = PosShift::query()->lockForUpdate()->findOrFail($shift->id);
            app(BranchAccess::class)->assertBranch($approver->id, $shift->branch_id);
            app(BranchAccess::class)->lockActiveBranch((int) $shift->branch_id);
            if ($shift->status !== PosShiftStatus::ClosedPendingApproval) {
                throw new BusinessException('الوردية ليست في انتظار الاعتماد.');
            }
            $shift->update([
                'status' => PosShiftStatus::Approved,
                'approved_at' => now(),
                'approved_by' => $approver->id,
            ]);

            $this->treasury->settleShiftToMainSafe($shift, $approver->id);

            return $shift->fresh();
        });
    }

    /**
     * Creates an immediate delivered retail POS sale, deducting on-hand stock and registering payment.
     *
     * @param  PosShift  $shift  Active shift
     * @param array{
     *     items: array<array{product_variant_id: int, qty: int, unit_price?: float|null, discount_amount?: float}>,
     *     customer_id?: int|null,
     *     payment_method?: string,
     *     cash_amount?: float,
     *     card_amount?: float,
     *     notes?: string|null,
     *     override_price?: bool,
     * } $payload Sale parameters
     * @return Order Created delivered order
     *
     * @throws BusinessException If shift is inactive, insufficient stock, or split payments mismatch
     */
    public function createSale(PosShift $shift, array $payload): Order
    {
        if ($shift->status !== PosShiftStatus::Open) {
            throw new BusinessException('الوردية غير مفتوحة — لا يمكن إجراء أي بيع.');
        }

        return DB::transaction(function () use ($shift, $payload): Order {
            $shift = $this->lockOpenShift($shift);
            $actor = User::findOrFail($shift->user_id);
            if (! empty($payload['override_price']) && ! $actor->can('pos.override_price')) {
                throw new AuthorizationException('لا تملك صلاحية تجاوز سعر البيع.');
            }
            if (collect($payload['items'])->contains(fn ($item) => (float) ($item['discount_amount'] ?? 0) > 0) && ! $actor->can('pos.discount')) {
                throw new AuthorizationException('لا تملك صلاحية تطبيق خصم يدوي.');
            }
            $stockRows = StockLevel::query()->where('branch_id', $shift->branch_id)
                ->whereIn('product_variant_id', array_column($payload['items'], 'product_variant_id'))
                ->where(fn ($q) => $q->whereNull('location_id')->orWhereHas('location', fn ($q) => $q->where('is_active', true)))
                ->get(['branch_id', 'location_id', 'product_variant_id']);
            $levels = $this->inventory->lockLevels($stockRows->map->getAttributes()->all());
            $customer = isset($payload['customer_id'])
                ? Customer::find($payload['customer_id'])
                : null;

            $overridePrice = (bool) ($payload['override_price'] ?? false);
            $items = $this->resolveItems((int) $shift->branch_id, $payload['items'], $customer, $overridePrice, true);
            $totals = $this->computeCartTotals($items);

            $subtotal = $totals['subtotal'];
            $discountTotal = $totals['discount_total'];
            $grandTotal = $totals['grand_total'];

            if ($grandTotal < 0) {
                throw new BusinessException('إجمالي الفاتورة لا يمكن أن يكون سالبًا.');
            }

            $cashAmount = (float) ($payload['cash_amount'] ?? $grandTotal);
            $cardAmount = (float) ($payload['card_amount'] ?? 0);

            if ($cashAmount < 0 || $cardAmount < 0) {
                throw new BusinessException('مبالغ الدفع يجب ألا تكون سالبة.');
            }

            // Split payments must reconcile down to the cent against the calculated grand total.
            $grandCents = $this->minor($grandTotal);
            $paidCents = $this->minor($cashAmount) + $this->minor($cardAmount);

            if ($paidCents !== $grandCents) {
                throw new BusinessException('مجموع المبالغ (نقدي + بطاقة) لا يساوي إجمالي الفاتورة.');
            }

            $orderNumber = $this->settings->generateNextNumber('order');

            // POS orders deliver immediately on checkout, bypassing online reservation workflows.
            $order = Order::create([
                'order_number' => $orderNumber,
                'channel' => 'pos',
                'customer_id' => $customer?->id,
                'allocated_branch_id' => $shift->branch_id,
                'pos_shift_id' => $shift->id,
                'status' => OrderStatus::Delivered,
                'payment_method' => $payload['payment_method'] ?? 'mixed',
                'payment_status' => 'paid',
                'subtotal' => $subtotal,
                'discount_amount' => $discountTotal,
                'shipping_cost' => 0,
                'tax_amount' => 0,
                'grand_total' => $grandTotal,
                'cash_amount' => $cashAmount,
                'card_amount' => $cardAmount,
            ]);

            foreach ($items as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_variant_id' => $item['product_variant_id'],
                    'product_name_snapshot' => $item['product_name_snapshot'],
                    'sku_snapshot' => $item['sku_snapshot'],
                    'allocated_branch_id' => $shift->branch_id,
                    'qty' => $item['qty'],
                    'unit_price' => $item['unit_price'],
                    'discount_amount' => $item['discount_amount'],
                    'line_total' => $item['line_total'],
                ]);

                $remaining = (int) round((float) $item['qty'] * 10000);
                foreach ($levels->where('product_variant_id', $item['product_variant_id']) as $level) {
                    $take = min($remaining, (int) round($level->available * 10000));
                    if ($take <= 0) {
                        continue;
                    }
                    $this->inventory->recordMovement([
                        'product_variant_id' => $item['product_variant_id'],
                        'branch_id' => $shift->branch_id,
                        'location_id' => $level->location_id,
                        'type' => StockMovementType::Sale,
                        'affected_bucket' => StockBucket::OnHand,
                        'quantity' => -$take / 10000,
                        'unit_cost' => $item['unit_cost'],
                        'reference_type' => Order::class,
                        'reference_id' => $order->id,
                        'created_by' => $shift->user_id,
                        'notes' => "بيع POS #{$orderNumber}",
                    ], $level);
                    $remaining -= $take;
                }
                if ($remaining > 0) {
                    throw new BusinessException('الرصيد المتاح تغير؛ أعد مراجعة الكميات.');
                }
            }

            OrderStatusHistory::create([
                'order_id' => $order->id,
                'from_status' => null,
                'to_status' => OrderStatus::Delivered,
                'changed_by_type' => User::class,
                'changed_by_id' => $shift->user_id,
                'notes' => 'بيع POS مباشر',
            ]);

            if ($cashAmount > 0) {
                $this->treasury->record([
                    'treasury_id' => $shift->treasury_id,
                    'shift_id' => $shift->id,
                    'type' => TreasuryTransactionType::PosSale,
                    'amount' => $cashAmount,
                    'reference_type' => Order::class,
                    'reference_id' => $order->id,
                    'created_by' => $shift->user_id,
                    'notes' => "مبيعات نقدية POS #{$orderNumber}",
                ]);
            }

            return $order->load(['items.variant', 'customer']);
        });
    }

    /**
     * Processes full or partial POS returns, restocking items and issuing cash/card refunds.
     *
     * @param  PosShift  $shift  Active shift
     * @param  Order  $originalOrder  Original POS delivered order
     * @param  array<array{product_variant_id: int, qty: int, return_to?: string}>  $returnedItems  Items to return
     * @param  array<array{product_variant_id: int, qty: int}>  $exchangeItems  Exchange items (must be empty; exchanges are separate sales)
     * @return array{return_order_id: int|null, exchange_order_id: null, refund_amount: float}
     *
     * @throws BusinessException If order is unreturnable, quantities exceed sold units, or invalid shift
     */
    public function processReturn(
        PosShift $shift,
        Order $originalOrder,
        array $returnedItems,
        array $exchangeItems = []
    ): array {
        if ($shift->status !== PosShiftStatus::Open) {
            throw new BusinessException('الوردية غير مفتوحة — لا يمكن إجراء أي مرتجع.');
        }

        if ($originalOrder->channel !== 'pos') {
            throw new BusinessException('يمكن مرتجع طلبات POS فقط من شاشة الـ POS.');
        }

        return DB::transaction(function () use ($shift, $originalOrder, $returnedItems, $exchangeItems): array {
            $shift = $this->lockOpenShift($shift);
            // Lock original order row to serialize concurrent refund requests against the same receipt.
            /** @var Order $originalOrder */
            $originalOrder = Order::query()->lockForUpdate()->findOrFail($originalOrder->id);
            $originalOrder->load('items');
            app(BranchAccess::class)->assertBranch($shift->user_id, (int) $originalOrder->allocated_branch_id);
            if ($originalOrder->channel !== 'pos') {
                throw new BusinessException('يمكن مرتجع طلبات POS فقط من شاشة الـ POS.');
            }

            if ($originalOrder->status !== OrderStatus::Delivered) {
                throw new BusinessException('المرتجع متاح لطلبات POS المسلمة فقط.');
            }

            if ($originalOrder->getAttribute('return_of_order_id') !== null) {
                throw new BusinessException('لا يمكن إرجاع طلب مرتجع.');
            }

            if (! empty($exchangeItems)) {
                throw new BusinessException('الاستبدال يتم كعملية بيع مستقلة بعد المرتجع، وليس ضمن نفس الطلب.');
            }

            $returnValue = 0.0;
            $returnOrderId = null;

            if (! empty($returnedItems)) {
                $grouped = [];
                foreach ($returnedItems as $ret) {
                    $vid = (int) $ret['product_variant_id'];
                    $qty = (float) $ret['qty'];
                    DiscreteQuantity::assertWhole($qty, false, 'كمية المرتجع يجب أن تكون موجبة.');
                    $destination = $ret['return_to'] ?? 'on_hand';
                    if (! in_array($destination, ['on_hand', 'damaged'], true)
                        || (isset($grouped[$vid]) && $grouped[$vid]['return_to'] !== $destination)) {
                        throw new BusinessException('حدد حالة واحدة صحيحة لكل صنف مرتجع.');
                    }
                    if (! isset($grouped[$vid])) {
                        $grouped[$vid] = ['qty' => 0.0, 'return_to' => $ret['return_to'] ?? 'on_hand'];
                    }
                    $grouped[$vid]['qty'] += $qty;
                }

                $previousQuantities = StockMovement::query()
                    ->whereIn('reference_type', array_unique([$originalOrder->getMorphClass(), Order::class]))
                    ->where('reference_id', $originalOrder->id)->where('type', StockMovementType::SaleReturn)
                    ->selectRaw('product_variant_id, SUM(quantity) AS qty')->groupBy('product_variant_id')->pluck('qty', 'product_variant_id');
                $previousRefunds = DB::table('order_items as ri')->join('orders as ro', 'ro.id', '=', 'ri.order_id')
                    ->where('ro.return_of_order_id', $originalOrder->id)
                    ->selectRaw('ri.product_variant_id, SUM(ri.qty) AS qty, SUM(ri.line_total) AS amount')
                    ->groupBy('ri.product_variant_id')->get()->keyBy('product_variant_id');
                foreach ($previousQuantities as $variantId => $quantity) {
                    if ((float) $quantity > (float) ($previousRefunds->get($variantId)?->qty ?? 0) + 0.00001) {
                        // Unlinked historical returns cannot be reliably split between cash and card; abort to avoid duplicate refunds.
                        throw new BusinessException('للفاتورة مرتجعات تاريخية غير مرتبطة؛ راجع ربط مستندات الرد بالأصل قبل رد مبلغ جديد.', [], 409);
                    }
                }

                $lines = [];
                foreach ($grouped as $variantId => $g) {
                    $matching = $originalOrder->items->where('product_variant_id', $variantId);
                    if ($matching->isEmpty()) {
                        throw new BusinessException("الصنف {$variantId} غير موجود في الطلب الأصلي.");
                    }

                    $soldQty = (float) $matching->sum('qty');
                    $soldNet = round((float) $matching->sum('line_total'), 2);
                    $netUnit = $soldQty > 0 ? $soldNet / $soldQty : 0.0;

                    $alreadyReturned = (float) $previousQuantities->get($variantId, 0);
                    $remaining = $soldQty - $alreadyReturned;
                    if ($g['qty'] > $remaining + 1e-9) {
                        throw new BusinessException("كمية المرتجع ({$g['qty']}) تتجاوز المتبقي القابل للإرجاع ({$remaining}).");
                    }

                    $previousRefund = $previousRefunds->get($variantId)?->amount ?? 0;
                    $targetCents = (int) round(($alreadyReturned + $g['qty']) * $this->minor($soldNet) / $soldQty);
                    $lineRefund = max(0, min($this->minor($soldNet), $targetCents) - $this->minor($previousRefund)) / 100;
                    $returnValue = round($returnValue + $lineRefund, 2);

                    $first = $matching->first();
                    $lines[$variantId] = [
                        'qty' => $g['qty'],
                        'return_to' => $g['return_to'],
                        'net_unit' => $netUnit,
                        'line_refund' => $lineRefund,
                        'product_name_snapshot' => $first->product_name_snapshot,
                        'sku_snapshot' => $first->sku_snapshot,
                    ];
                }

                if ($returnValue < 0) {
                    throw new BusinessException('قيمة المرتجع لا يمكن أن تكون سالبة.');
                }

                // Cash refund cannot exceed the net physical cash paid on the original order.
                $previousCashRefunded = (float) Order::withTrashed()
                    ->where('return_of_order_id', $originalOrder->id)
                    ->sum('cash_amount');
                $maxCashRefund = round((float) $originalOrder->cash_amount - $previousCashRefunded, 2);
                $cashRefund = min($returnValue, max(0.0, $maxCashRefund));

                $locationId = $this->inventory->receivingLocation((int) $shift->branch_id);
                $levels = $this->inventory->lockLevels(array_map(fn ($variantId) => [
                    'product_variant_id' => $variantId, 'branch_id' => $shift->branch_id, 'location_id' => $locationId,
                ], array_keys($lines)));

                foreach ($lines as $variantId => $line) {
                    $base = [
                        'product_variant_id' => $variantId,
                        'branch_id' => $shift->branch_id,
                        'location_id' => $locationId,
                        'type' => StockMovementType::SaleReturn,
                        'affected_bucket' => StockBucket::OnHand,
                        'quantity' => $line['qty'],
                        'reference_type' => Order::class,
                        'reference_id' => $originalOrder->id,
                        'created_by' => $shift->user_id,
                        'notes' => "مرتجع POS من #{$originalOrder->order_number}",
                    ];
                    $level = $levels->get($this->inventory->levelKey($base));
                    $saleCost = StockMovement::query()->whereIn('reference_type', array_unique([$originalOrder->getMorphClass(), Order::class]))
                        ->where('reference_id', $originalOrder->id)->where('product_variant_id', $variantId)->where('type', 'sale')->value('unit_cost');
                    $this->inventory->recordMovement([...$base, 'unit_cost' => $saleCost], $level);
                    if ($line['return_to'] === 'damaged') {
                        $this->inventory->recordMovement([...$base, 'type' => StockMovementType::Damage, 'affected_bucket' => StockBucket::Damaged], $level);
                    }
                }

                $returnOrderNumber = $this->settings->generateNextNumber('order');
                $returnAttrs = [
                    'order_number' => $returnOrderNumber,
                    'channel' => 'pos',
                    'customer_id' => $originalOrder->customer_id,
                    'allocated_branch_id' => $shift->branch_id,
                    'pos_shift_id' => $shift->id,
                    'status' => OrderStatus::Returned,
                    'payment_method' => $originalOrder->payment_method,
                    'payment_status' => 'refunded',
                    'subtotal' => $returnValue,
                    'discount_amount' => 0,
                    'shipping_cost' => 0,
                    'tax_amount' => 0,
                    'grand_total' => $returnValue,
                    'cash_amount' => $cashRefund,
                    'card_amount' => round($returnValue - $cashRefund, 2),
                ];
                $returnAttrs['return_of_order_id'] = $originalOrder->id;
                $returnAttrs['requires_refund'] = $returnAttrs['card_amount'] > 0;
                $returnAttrs['payment_status'] = $returnAttrs['card_amount'] > 0 ? 'refund_pending' : 'refunded';
                $returnOrder = Order::create($returnAttrs);

                foreach ($lines as $variantId => $line) {
                    OrderItem::create([
                        'order_id' => $returnOrder->id,
                        'product_variant_id' => $variantId,
                        'product_name_snapshot' => $line['product_name_snapshot'],
                        'sku_snapshot' => $line['sku_snapshot'],
                        'allocated_branch_id' => $shift->branch_id,
                        'qty' => $line['qty'],
                        'unit_price' => round($line['net_unit'], 2),
                        'discount_amount' => 0,
                        'line_total' => $line['line_refund'],
                    ]);
                }

                foreach ($grouped as $variantId => $g) {
                    $remaining = (float) StockMovement::query()
                        ->whereIn('reference_type', array_unique([$originalOrder->getMorphClass(), Order::class]))
                        ->where('reference_id', $originalOrder->id)->where('type', StockMovementType::SaleReturn)
                        ->where('product_variant_id', $variantId)->sum('quantity');
                    foreach ($originalOrder->items->where('product_variant_id', $variantId)->sortBy('id') as $item) {
                        $take = min($remaining, (float) $item->qty);
                        $item->update(['returned_qty' => $take]);
                        $remaining -= $take;
                    }
                }

                OrderStatusHistory::create([
                    'order_id' => $returnOrder->id,
                    'from_status' => null,
                    'to_status' => OrderStatus::Returned,
                    'changed_by_type' => User::class,
                    'changed_by_id' => $shift->user_id,
                    'notes' => "مرتجع من #{$originalOrder->order_number}",
                ]);

                if ($cashRefund > 0) {
                    $this->treasury->record([
                        'treasury_id' => $shift->treasury_id,
                        'shift_id' => $shift->id,
                        'type' => TreasuryTransactionType::PosReturn,
                        'amount' => -$cashRefund,
                        'reference_type' => Order::class,
                        'reference_id' => $returnOrder->id,
                        'created_by' => $shift->user_id,
                        'notes' => "رد مرتجع #{$returnOrderNumber}",
                    ]);
                }

                $returnOrderId = $returnOrder->id;
            }

            return [
                'return_order_id' => $returnOrderId,
                'exchange_order_id' => null,
                'refund_amount' => $returnValue,
            ];
        });
    }

    /**
     * Parks an in-progress sale payload to free the POS terminal.
     *
     * @param  PosShift  $shift  Active shift
     * @param  array<string, mixed>  $payload  Uncompleted cart payload
     * @return PosHeldSale Created held sale record
     */
    public function holdSale(PosShift $shift, array $payload): PosHeldSale
    {
        return PosHeldSale::create([
            'hold_reference' => 'HOLD-'.strtoupper(Str::random(8)),
            'branch_id' => $shift->branch_id,
            'user_id' => $shift->user_id,
            'customer_id' => $payload['customer_id'] ?? null,
            'items_payload' => $payload['items'],
            'notes' => $payload['notes'] ?? null,
            'created_at' => now(),
        ]);
    }

    /**
     * High-speed product variant lookup matching barcode, SKU, or name with branch inventory availability.
     *
     * @param  string  $query  Search query term
     * @param  int  $branchId  Branch ID
     * @param  Customer|null  $customer  Optional customer for targeted pricing
     * @return array<int, array<string, mixed>> Matching variants with live price and stock
     */
    public function lookupProducts(string $query, int $branchId, ?Customer $customer = null): array
    {
        $normalized = ArabicDigits::normalize(trim($query));

        $variants = ProductVariant::query()
            ->with(['product', 'media'])
            ->whereHas('product', fn ($q) => $q->where('is_active', true)->whereNotNull('published_at'))
            ->where('is_active', true)
            ->where(function ($q) use ($query, $normalized): void {
                $like = SearchTerm::pattern(mb_strtolower($query));
                $q->where('barcode', $normalized)
                    ->orWhere('barcode', $query)
                    ->orWhere('sku', $normalized)
                    ->orWhere('sku', $query)
                    ->orWhereHas('product', fn ($pq) => $pq->whereRaw('LOWER(name_ar) LIKE ? OR LOWER(name_en) LIKE ?', [$like, $like]));
            })
            ->limit(20)
            ->get();

        $variantIds = $variants->pluck('id')->all();
        $stockByVariant = ! empty($variantIds)
            ? StockLevel::query()
                ->where('branch_id', $branchId)
                ->whereIn('product_variant_id', $variantIds)
                ->where(fn ($q) => $q->whereNull('location_id')->orWhereHas('location', fn ($q) => $q->where('is_active', true)))
                ->get()
                ->groupBy('product_variant_id')
            : collect();

        $result = [];
        foreach ($variants as $variant) {
            $stock = $stockByVariant->get($variant->id, collect());

            try {
                $priceData = $this->pricing->resolve($variant, $customer, 1, $branchId);
            } catch (\Throwable) {
                $priceData = ['unit_price' => null, 'source' => 'unpriced', 'price_list_id' => null];
            }

            $result[] = [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
                'product_name' => $variant->product->name_ar ?? $variant->product->name_en,
                'variant_name' => $variant->color_name_ar ?? $variant->color_name_en,
                'on_hand' => (float) $stock->sum('on_hand'),
                'available' => (float) $stock->sum('available'),
                'unit_price' => $priceData['unit_price'],
                'price_source' => $priceData['source'],
                'price_list_id' => $priceData['price_list_id'],
            ];
        }

        return $result;
    }

    /**
     * Retrieves the active branch product catalog with aggregated stock and batch-resolved retail/wholesale prices.
     *
     * Supports cursor pagination when total active variants exceed 3000 or when a cursor is explicitly provided.
     *
     * @param  int  $branchId  Target branch ID
     * @param  string  $catalogVersion  Pre-computed catalog version hash
     * @param  string|null  $cursor  Optional pagination cursor
     * @return array{data: list<array<string, mixed>>, meta: array{catalog_version: string, next_cursor: string|null}}
     */
    public function getBranchCatalog(int $branchId, string $catalogVersion, ?string $cursor = null): array
    {
        $baseQuery = ProductVariant::query()
            ->where('is_active', true)
            ->whereHas('product', fn ($q) => $q->where('is_active', true)->whereNotNull('published_at'))
            ->with(['product.media', 'media'])
            ->orderBy('id');

        $nextCursor = null;
        if ($cursor !== null && $cursor !== '') {
            $paginator = $baseQuery->cursorPaginate(500, ['*'], 'cursor', $cursor);
            $variants = collect($paginator->items());
            $nextCursor = $paginator->nextCursor()?->encode();
        } else {
            $totalCount = (clone $baseQuery)->count();
            if ($totalCount > 3000) {
                $paginator = $baseQuery->cursorPaginate(500);
                $variants = collect($paginator->items());
                $nextCursor = $paginator->nextCursor()?->encode();
            } else {
                $variants = $baseQuery->get();
            }
        }

        if ($variants->isEmpty()) {
            return [
                'data' => [],
                'meta' => [
                    'catalog_version' => $catalogVersion,
                    'next_cursor' => null,
                ],
            ];
        }

        $variantIds = $variants->pluck('id')->all();

        $stockByVariant = $this->catalogStockLevels($branchId)
            ->whereIn('product_variant_id', $variantIds)
            ->get()
            ->groupBy('product_variant_id');

        $priceListItemsByVariant = $this->catalogPriceItems($branchId)
            ->select('price_list_items.*', 'price_lists.type as pricing_type', 'price_lists.branch_id as pricing_branch_id', 'price_lists.priority as pricing_priority')
            ->whereIn('price_list_items.product_variant_id', $variantIds)
            ->orderByRaw('price_lists.branch_id IS NULL ASC')
            ->orderByDesc('price_list_items.min_qty')
            ->orderByDesc('price_lists.priority')
            ->orderBy('price_list_items.price')
            ->orderBy('price_list_items.id')
            ->get()
            ->groupBy('product_variant_id');

        $data = [];
        foreach ($variants as $variant) {
            /** @var ProductVariant $variant */
            $stock = $stockByVariant->get($variant->id, collect());
            $onHand = (float) $stock->sum('on_hand');
            $available = (float) $stock->sum('available');

            $rowReorderMax = (float) $stock->max('reorder_level');
            $reorderPoint = $rowReorderMax > 0 ? $rowReorderMax : (float) ($variant->reorder_level ?? 0);

            $candidates = $priceListItemsByVariant->get($variant->id, collect());
            $retailMatch = $candidates->firstWhere('pricing_type', PriceListType::Retail->value);
            $wholesaleMatch = $candidates->firstWhere('pricing_type', PriceListType::Wholesale->value);

            $retailPriceRaw = $retailMatch !== null
                ? (float) $retailMatch->price
                : (float) ($variant->retail_price ?? 0);

            if ($wholesaleMatch !== null) {
                $wholesalePriceRaw = (float) $wholesaleMatch->price;
            } elseif ($retailMatch !== null && $variant->wholesale_price === null) {
                $wholesalePriceRaw = (float) $retailMatch->price;
            } elseif ($variant->wholesale_price !== null) {
                $wholesalePriceRaw = (float) $variant->wholesale_price;
            } else {
                $wholesalePriceRaw = $retailPriceRaw;
            }

            $productName = $variant->product->name_ar ?? $variant->product->name_en ?? '';
            $variantName = $variant->color_name_ar ?? $variant->color_name_en ?? $variant->size ?? $variant->capacity;
            $composedName = implode(' - ', array_filter([$productName, $variantName], fn ($v) => $v !== null && trim((string) $v) !== ''));

            $media = $variant->getMedia('images')->first() ?? $variant->product->getMedia('images')->first();
            $imageUrl = $media !== null
                ? ($media->hasGeneratedConversion('thumb') ? $media->getUrl('thumb') : $media->getUrl())
                : null;

            $data[] = [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
                'name' => $composedName !== '' ? $composedName : (string) $variant->sku,
                'product_name' => $productName,
                'variant_name' => $variantName,
                'category_id' => $variant->product->category_id !== null ? (string) $variant->product->category_id : null,
                'unit_price' => round($retailPriceRaw, 2),
                'wholesale_price' => round($wholesalePriceRaw, 2),
                'available' => floor($available) === $available ? (int) $available : $available,
                'on_hand' => floor($onHand) === $onHand ? (int) $onHand : $onHand,
                'reorder_point' => floor($reorderPoint) === $reorderPoint ? (int) $reorderPoint : $reorderPoint,
                'image_url' => $imageUrl,
            ];
        }

        return [
            'data' => $data,
            'meta' => [
                'catalog_version' => $catalogVersion,
                'next_cursor' => $nextCursor,
            ],
        ];
    }

    /** @return Builder<StockLevel> */
    private function catalogStockLevels(int $branchId): Builder
    {
        return StockLevel::query()
            ->where('branch_id', $branchId)
            ->where(fn ($q) => $q->whereNull('location_id')->orWhereHas('location', fn ($q) => $q->where('is_active', true)));
    }

    /** @return Builder<PriceListItem> */
    private function catalogPriceItems(int $branchId): Builder
    {
        $now = now();

        return PriceListItem::query()
            ->join('price_lists', 'price_lists.id', '=', 'price_list_items.price_list_id')
            ->where('price_lists.is_active', true)
            ->whereIn('price_lists.type', [PriceListType::Retail->value, PriceListType::Wholesale->value])
            ->where('price_list_items.min_qty', '<=', 1)
            ->where(fn ($q) => $q->whereNull('price_lists.starts_at')->orWhere('price_lists.starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('price_lists.ends_at')->orWhere('price_lists.ends_at', '>=', $now))
            ->where(fn ($q) => $q->whereNull('price_list_items.starts_at')->orWhere('price_list_items.starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('price_list_items.ends_at')->orWhere('price_list_items.ends_at', '>=', $now))
            ->where(fn ($q) => $q->whereNull('price_lists.branch_id')->orWhere('price_lists.branch_id', $branchId));
    }

    /**
     * Quotes a POS cart without DB locks or persistence, returning line pricing, availability, and totals.
     * Never throws when stock is insufficient; populates line `error` with an Arabic message instead.
     *
     * @param  int  $branchId  Target branch ID
     * @param  array<array{product_variant_id: int, qty: int|float, unit_price?: float|null, discount_amount?: float|null}>  $items  Raw cart items
     * @param  Customer|null  $c  Optional customer for targeted pricing
     * @param  bool  $override  Whether manual price override is enabled
     * @return array{
     *     lines: array<int, array{
     *         product_variant_id: int,
     *         qty: int|float,
     *         unit_price: string,
     *         discount_amount: string,
     *         line_total: string,
     *         available: int|float,
     *         error: string|null
     *     }>,
     *     subtotal: string,
     *     discount_total: string,
     *     grand_total: string
     * }
     */
    public function quote(int $branchId, array $items, ?Customer $c = null, bool $override = false): array
    {
        $resolved = $this->resolveItems($branchId, $items, $c, $override, false);
        $totals = $this->computeCartTotals($resolved);

        $lines = $resolved->map(fn (array $item): array => [
            'product_variant_id' => $item['product_variant_id'],
            'qty' => is_float($item['qty']) && floor($item['qty']) === $item['qty'] ? (int) $item['qty'] : $item['qty'],
            'unit_price' => number_format((float) $item['unit_price'], 2, '.', ''),
            'discount_amount' => number_format((float) $item['discount_amount'], 2, '.', ''),
            'line_total' => number_format((float) $item['line_total'], 2, '.', ''),
            'available' => is_float($item['available']) && floor($item['available']) === $item['available']
                ? (int) $item['available']
                : $item['available'],
            'error' => $item['error'],
        ])->values()->all();

        return [
            'lines' => $lines,
            'subtotal' => number_format($totals['subtotal'], 2, '.', ''),
            'discount_total' => number_format($totals['discount_total'], 2, '.', ''),
            'grand_total' => number_format($totals['grand_total'], 2, '.', ''),
        ];
    }

    /**
     * Computes subtotal, discount_total, and grand_total from resolved items in minor units.
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array{subtotal: float, discount_total: float, grand_total: float}
     */
    private function computeCartTotals(Collection $items): array
    {
        return [
            'subtotal' => $items->sum(fn ($item) => $this->minor($item['unit_price']) * (int) $item['qty']) / 100,
            'discount_total' => $items->sum(fn ($item) => $this->minor($item['discount_amount'])) / 100,
            'grand_total' => $items->sum(fn ($item) => $this->minor($item['line_total'])) / 100,
        ];
    }

    /**
     * Prepares sale item line totals and resolves pricing tiers.
     *
     * @param  int  $branchId  Target branch ID
     * @param  array<array{product_variant_id: int, qty: int|float, unit_price?: float|null, discount_amount?: float|null}>  $rawItems  Raw cart items
     * @param  Customer|null  $customer  Target customer
     * @param  bool  $overridePrice  Whether manual price override is enabled
     * @param  bool  $enforceStock  When true, throws BusinessException if available < qty; when false, sets line `error`
     * @return Collection<int, array<string, mixed>> Resolved item rows
     */
    private function resolveItems(
        int $branchId,
        array $rawItems,
        ?Customer $customer,
        bool $overridePrice,
        bool $enforceStock = true
    ): Collection {
        return collect($rawItems)->map(function (array $item) use ($branchId, $customer, $overridePrice, $enforceStock): array {
            $qty = (float) $item['qty'];
            DiscreteQuantity::assertWhole($qty, false, 'كمية البيع يجب أن تكون عددًا صحيحًا موجبًا.');
            /** @var ProductVariant $variant */
            $variant = ProductVariant::query()
                ->where('id', $item['product_variant_id'])
                ->where('is_active', true)
                ->firstOrFail();

            $stock = StockLevel::query()
                ->where('product_variant_id', $variant->id)
                ->where('branch_id', $branchId)
                ->where(fn ($q) => $q->whereNull('location_id')->orWhereHas('location', fn ($q) => $q->where('is_active', true)))
                ->get();

            $available = (float) $stock->sum('available');
            $availableFormatted = floor($available) === $available ? (int) $available : $available;

            $error = null;
            if ($available < $qty) {
                if ($enforceStock) {
                    throw new BusinessException(
                        "الكمية المتاحة للصنف [{$variant->sku}] في الفرع هي {$available} فقط.",
                        status: 422,
                    );
                }
                $error = "الكمية المطلوبة غير متوفرة في المخزون (المتاح: {$availableFormatted})";
            }

            if ($overridePrice && isset($item['unit_price'])) {
                $unitPrice = (float) $item['unit_price'];
            } else {
                $priceData = $this->pricing->resolve($variant, $customer, $qty, $branchId);
                $unitPrice = (float) $priceData['unit_price'];
            }

            if (! is_finite($unitPrice) || $unitPrice < 0) {
                throw new BusinessException("سعر الصنف [{$variant->sku}] لا يمكن أن يكون سالبًا.");
            }

            $discount = (float) ($item['discount_amount'] ?? 0);
            $maxDiscount = (float) ($this->settings->get('pos.max_discount_percent')
                ?? $this->settings->get('pos_max_discount_percent')
                ?? config('inventory.pos_max_discount_percent', 100));
            if ($discount > round($unitPrice * $qty * $maxDiscount / 100, 2)) {
                throw new BusinessException('الخصم يتجاوز النسبة القصوى المسموحة.');
            }
            if (! is_finite($discount) || $discount < 0) {
                throw new BusinessException("خصم الصنف [{$variant->sku}] لا يمكن أن يكون سالبًا.");
            }
            $lineTotal = round($unitPrice * $qty - $discount, 2);

            if ($lineTotal < 0) {
                throw new BusinessException("إجمالي السطر للصنف [{$variant->sku}] لا يمكن أن يكون سالبًا.");
            }

            return [
                'product_variant_id' => $variant->id,
                'product_name_snapshot' => $variant->product->name_ar ?? $variant->product->name_en ?? 'POS Item',
                'sku_snapshot' => $variant->sku,
                'qty' => (int) $qty,
                'unit_price' => $unitPrice,
                'discount_amount' => $discount,
                'line_total' => $lineTotal,
                'unit_cost' => $variant->cost_price,
                'available' => $availableFormatted,
                'error' => $error,
            ];
        });
    }

    /**
     * Records a petty cash expense paid directly from the active shift cash drawer.
     *
     * @param  PosShift  $shift  Active shift
     * @param  array<string, mixed>  $data  Expense attributes
     * @param  int  $userId  Cashier/User recording the expense
     * @return Expense Created expense record
     *
     * @throws BusinessException If shift is not open
     */
    public function recordExpense(PosShift $shift, array $data, int $userId): Expense
    {
        if ($shift->status !== PosShiftStatus::Open) {
            throw new BusinessException('الوردية غير مفتوحة — لا يمكن تسجيل مصاريف.');
        }

        return DB::transaction(function () use ($shift, $data, $userId): Expense {
            $shift = $this->lockOpenShift($shift);
            app(BranchAccess::class)->lockActiveBranch((int) $shift->branch_id);
            $expenseNumber = $this->settings->generateNextNumber('expense');

            $expense = Expense::create([
                'expense_number' => $expenseNumber,
                'branch_id' => $shift->branch_id,
                'treasury_id' => $shift->treasury_id,
                'shift_id' => $shift->id,
                'category' => $data['category'],
                'amount' => $data['amount'],
                'expense_date' => $data['expense_date'] ?? today(),
                'attachment_path' => $data['attachment_path'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            $this->treasury->record([
                'treasury_id' => $shift->treasury_id,
                'shift_id' => $shift->id,
                'type' => TreasuryTransactionType::Expense,
                'amount' => -(float) $data['amount'],
                'reference_type' => Expense::class,
                'reference_id' => $expense->id,
                'created_by' => $userId,
                'notes' => "مصروف: {$data['category']} #{$expenseNumber}",
            ]);

            return $expense;
        });
    }

    private function lockOpenShift(PosShift $shift): PosShift
    {
        $shift = PosShift::query()->lockForUpdate()->findOrFail($shift->id);
        if ($shift->status !== PosShiftStatus::Open) {
            throw new BusinessException('الوردية غير مفتوحة.');
        }

        return $shift;
    }

    /**
     * Convert decimal amount to integer cents, forbidding unrepresented fractional cents.
     */
    private function minor(mixed $value): int
    {
        try {
            // RoundingMode::Unnecessary rejects sub-penny fractions to enforce strict 2-decimal financial bounds.
            return BigDecimal::of((string) $value)->toScale(2, RoundingMode::Unnecessary)->getUnscaledValue()->toInt();
        } catch (\Throwable) {
            throw new BusinessException('المبلغ المالي غير صالح؛ يُسمح بخانتين عشريتين فقط.');
        }
    }
}
```

## File: bags-platform/backend/routes/api/store.php
```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\Shared\NotificationController;
use App\Http\Controllers\Api\V1\Store\AccountController;
use App\Http\Controllers\Api\V1\Store\AuthController;
use App\Http\Controllers\Api\V1\Store\BrandingController;
use App\Http\Controllers\Api\V1\Store\CartController;
use App\Http\Controllers\Api\V1\Store\CatalogController;
use App\Http\Controllers\Api\V1\Store\CheckoutController;
use App\Http\Controllers\Api\V1\Store\HomeController;
use App\Http\Controllers\Api\V1\Store\OrderController as StoreOrderController;
use App\Http\Controllers\Api\V1\Store\ProductController;
use App\Http\Controllers\Api\V1\Store\ReturnController;
use App\Http\Controllers\Api\V1\Store\WalletController;
use App\Http\Middleware\ProtectStorefrontCaching;
use Illuminate\Support\Facades\Route;

// Retail customer authentication routes
Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::get('registration-options', [AuthController::class, 'registrationOptions'])->middleware('throttle:store-branding')->name('registration-options');
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:customer-registration')->name('register');
    Route::post('phone-verification/send', [AuthController::class, 'sendPhoneVerification'])->middleware('throttle:customer-phone-send')->name('phone-verification.send');
    Route::post('phone-verification/verify', [AuthController::class, 'verifyPhone'])->middleware('throttle:customer-phone-verify')->name('phone-verification.verify');
    // Legacy storefront authentication route; disabled when LEGACY_LOGIN_ENABLED=false in favor of /auth/login.
    if (config('auth.legacy_login_enabled', false)) {
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:customer-login')->name('login');
    }
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:customer-password-reset')->name('forgot-password');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:customer-reset-confirm')->name('reset-password');

    Route::middleware(['auth:sanctum', 'auth.customer'])->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        // Constrain notification IDs to UUIDs so malformed identifiers return 404 rather than casting exceptions.
        Route::post('notifications/{id}/read', [NotificationController::class, 'markAsRead'])->whereUuid('id')->name('notifications.read');
    });
});

Route::get('branding', [BrandingController::class, 'show'])->middleware('throttle:store-branding')->name('branding');

// Storefront catalog browsing (accessible by guests and authenticated customers)
Route::middleware(['throttle:store-catalog', ProtectStorefrontCaching::class])->group(function (): void {
    Route::get('home', [HomeController::class, 'index'])->name('home');
    Route::get('categories', [CatalogController::class, 'categories'])->name('categories');
    Route::get('brands', [CatalogController::class, 'brands'])->name('brands');
    Route::get('cities', [CatalogController::class, 'cities'])->name('cities');

    Route::get('catalog/filters', [CatalogController::class, 'filters'])->name('catalog.filters');
    Route::get('showcase', [ProductController::class, 'showcase'])->name('showcase');
    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::get('products/{slug}', [ProductController::class, 'show'])->name('products.show');
    Route::get('products/{slug}/similar', [ProductController::class, 'similar'])->name('products.similar');
});

// Numeric route identifier patterns: non-numeric inputs 404 rather than triggering database errors
Route::pattern('item', '[0-9]{1,18}');
Route::pattern('order', '[0-9]{1,18}');

// Cart and checkout endpoints (supports guest X-Cart-Session and authenticated customer tokens)
Route::middleware('throttle:cart')->prefix('cart')->name('cart.')->group(function (): void {
    Route::get('items', [CartController::class, 'index'])->name('items.index');
    Route::post('items', [CartController::class, 'store'])->name('items.store');
    Route::delete('items', [CartController::class, 'clear'])->name('items.clear');
    Route::put('items/{item}', [CartController::class, 'update'])->name('items.update');
    Route::delete('items/{item}', [CartController::class, 'destroy'])->name('items.destroy');
    Route::post('coupon', [CartController::class, 'applyCoupon'])->middleware('throttle:coupon')->name('coupon.apply');
    Route::delete('coupon', [CartController::class, 'removeCoupon'])->name('coupon.remove');
});

Route::middleware('throttle:checkout')->prefix('checkout')->name('checkout.')->group(function (): void {
    Route::post('shipping-options', [CheckoutController::class, 'shippingOptions'])->name('shipping-options');
    Route::post('/', [CheckoutController::class, 'store'])->name('store');
});

// Public order tracking with strict rate limiting to prevent enumeration attacks
Route::get('orders/{number}/track', [StoreOrderController::class, 'track'])
    ->middleware('throttle:order-tracking')->name('orders.track');

// Authenticated customer account routes
Route::middleware(['auth:sanctum', 'auth.customer'])->prefix('account')->name('account.')->group(function (): void {
    Route::get('addresses', [AccountController::class, 'addresses'])->name('addresses.index');
    Route::get('wallet', [WalletController::class, 'index'])->name('wallet.index');
    Route::get('orders', [StoreOrderController::class, 'index'])->name('orders.index');
    Route::post('orders/{order}/cancel', [StoreOrderController::class, 'cancel'])->name('orders.cancel');
    // Return requests (RMA) for delivered orders
    Route::get('return-items/{return_item}/attachments/{attachment}', [AttachmentController::class, 'returnFile'])->whereNumber(['return_item', 'attachment'])->name('return-items.attachments.show');
    Route::get('returns', [ReturnController::class, 'index'])->name('returns.index');
    Route::get('returns/{return_request}', [ReturnController::class, 'show'])->whereNumber('return_request')->name('returns.show');
    Route::post('returns', [ReturnController::class, 'store'])->middleware('throttle:10,60')->name('returns.store');
});
```

## File: bags-platform/frontend/packages/api-client/src/endpoints/index.ts
```typescript
export * from './inventory';
export * from './operations';

import { createApiClient, defaultApiClient as apiClient } from '../axios-instance';
import { SLOW_REQUEST_TIMEOUT } from './operations/http';
import type {
  AdminBrand, AdminCategory, AdminMedia, AdminProduct, AdminVariant,
  BrandPayload, CartCouponResponse, CategoryPayload, CategoryReorderItem,
  CheckoutPayload, CheckoutResponse, ProductPayload, ProductStatus,
  PublicBranding, ResourceCollection, SalesChannel, ShippingOptionsResponse,
  StoreCart, StoreCatalogFilters, StoreCategory, StoreCity, StoreHomeData,
  StoreOrder, StoreProduct, StoreProductCard, StoreProductListParams,
  TrackOrderResponse, VariantPayload,
} from '../types';

const publicApiClient = createApiClient({ getToken: () => null, onUnauthorized: () => {} });

export const brandingEndpoints = {
  get: (signal?: AbortSignal) => publicApiClient.get<{ data: PublicBranding }>('/api/v1/store/branding', {
    baseURL: apiClient.defaults.baseURL,
    signal,
    timeout: 5000,
  }).then((response) => response.data.data),
};

const cartHeaders = (session: string | null) => session ? { 'X-Cart-Session': session } : {};
export const cartEndpoints = {
  clear: (session: string | null) => apiClient.delete<{ data: StoreCart }>('/api/v1/store/cart/items', { headers: cartHeaders(session) }).then(response => response.status === 204 ? cartEndpoints.get(session) : response.data.data),
  get: (session: string | null) => apiClient.get<{ data: StoreCart }>('/api/v1/store/cart/items', { headers: cartHeaders(session) }).then(response => response.data.data),
  add: (variantId: number, qty: number, session: string | null) => apiClient.post<{ data: StoreCart }>('/api/v1/store/cart/items', { product_variant_id: variantId, qty }, { headers: cartHeaders(session) }).then(response => response.data.data),
  update: (id: number, qty: number, session: string | null) => apiClient.put<{ data: StoreCart }>(`/api/v1/store/cart/items/${id}`, { qty }, { headers: cartHeaders(session) }).then(response => response.data.data),
  remove: (id: number, session: string | null) => apiClient.delete<{ data: StoreCart }>(`/api/v1/store/cart/items/${id}`, { headers: cartHeaders(session) }).then(response => response.status === 204 ? cartEndpoints.get(session) : response.data.data),
  applyCoupon: (code: string, session: string | null) => apiClient.post<{ data: CartCouponResponse }>('/api/v1/store/cart/coupon', { code }, { headers: cartHeaders(session) }).then(response => response.data.data),
  removeCoupon: (session: string | null) => apiClient.delete<{ data: StoreCart }>('/api/v1/store/cart/coupon', { headers: cartHeaders(session) }).then(response => response.data.data),
};


export type ActorType = 'staff' | 'supplier' | 'customer';
export type Destination = 'admin' | 'pos' | 'supplier' | 'wholesale' | 'wholesale_pending' | 'retail';

export interface LoginRedirect {
  app: 'web' | 'backoffice';
  path: string;
  destination?: Destination;
}
export interface UnifiedLoginResponse {
  message: string;
  actor: { type: ActorType; subtype: Destination; status: string | null; customer_type?: string };
  redirect: LoginRedirect;
  token: string | null;
  handoff_code: string | null;
  data: Record<string, unknown>;
  cart_merged?: boolean | null;
}
export interface HandoffExchangeResponse {
  data: StaffUserResource;
  token: string;
  redirect: LoginRedirect;
}
export interface StaffUserResource {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  branch_id: number | null;
  branch: { id: number; name: string } | null;
  is_active: boolean;
  roles: { id: number; name: string }[];
  permissions: string[];
}

export const authEndpoints = {
  login: (body: { login: string; password: string; redirect?: string }, cartSession?: string | null) =>
    apiClient
      .post<UnifiedLoginResponse>('/api/v1/auth/login', body, {
        headers: cartSession ? { 'X-Cart-Session': cartSession } : {},
      })
      .then((r) => r.data),
  me: () => apiClient.get('/api/v1/auth/me').then((r) => r.data),
  logout: () => apiClient.post('/api/v1/auth/logout'),
  exchangeHandoff: (code: string) =>
    apiClient.post<HandoffExchangeResponse>('/api/v1/auth/handoff/exchange', { code }).then((r) => r.data),
  staffMe: () => apiClient.get<{ data: StaffUserResource }>('/api/v1/admin/me').then((r) => r.data.data),
  staffLogout: () => apiClient.post('/api/v1/admin/auth/logout'),
};

export const API_ENDPOINTS = {
  AUTH: {
    LOGIN: '/api/v1/auth/login',
    LOGOUT: '/api/v1/auth/logout',
    ME: '/api/v1/auth/me',
  },
  ADMIN: {
    DASHBOARD: '/api/v1/admin/dashboard',
    PRODUCTS: '/api/v1/admin/products',
    CATEGORIES: '/api/v1/admin/categories',
    ORDERS: '/api/v1/admin/orders',
    CUSTOMERS: '/api/v1/admin/customers',
    INVENTORY: '/api/v1/admin/inventory',
    SUPPLIERS: '/api/v1/admin/suppliers',
    REPORTS: '/api/v1/admin/reports',
    TREASURY: '/api/v1/admin/treasuries',
    EXPENSES: '/api/v1/admin/expenses',
  },
  POS: {
    AUTH_LOGIN: '/api/v1/pos/auth/login',
    AUTH_LOGOUT: '/api/v1/pos/auth/logout',
    BOOTSTRAP: '/api/v1/pos/bootstrap',
    SHIFTS_CURRENT: '/api/v1/pos/shifts/current',
    SHIFT_SUMMARY: '/api/v1/pos/shifts/current/summary',
    SHIFTS_OPEN: '/api/v1/pos/shifts/open',
    SHIFTS_CLOSE: '/api/v1/pos/shifts/close',
    TREASURIES_AVAILABLE: '/api/v1/pos/treasuries/available',
    CATALOG: '/api/v1/pos/catalog',
    PRODUCTS_LOOKUP: '/api/v1/pos/products/lookup',
    CUSTOMERS: '/api/v1/pos/customers',
    SALES: '/api/v1/pos/sales',
    SALES_QUOTE: '/api/v1/pos/sales/quote',
    SALES_LOOKUP: '/api/v1/pos/sales/lookup',
    SALES_HOLD: '/api/v1/pos/sales/hold',
    SALES_HELD: '/api/v1/pos/sales/held',
    SALE_HELD_RESUME: (id: number | string) => `/api/v1/pos/sales/held/${id}/resume`,
    SALE_HELD_DELETE: (id: number | string) => `/api/v1/pos/sales/held/${id}`,
    SALE_RETURN: (id: number | string) => `/api/v1/pos/sales/${id}/return`,
    SALE_EXCHANGE: (id: number | string) => `/api/v1/pos/sales/${id}/exchange`,
    STOCK: '/api/v1/pos/stock',
    TRANSFERS_INCOMING: '/api/v1/pos/transfers/incoming',
    TRANSFER_SOURCES: '/api/v1/pos/transfer-sources',
    TRANSFER_REQUESTS: '/api/v1/pos/transfer-requests',
    TRANSFER_RECEIVE: (id: number | string) => `/api/v1/pos/transfers/${id}/receive`,
    STOCKTAKES_CURRENT: '/api/v1/pos/stocktakes/current',
    STOCKTAKE_SCAN: (id: number | string) => `/api/v1/pos/stocktakes/${id}/scan`,
  },
  STORE: {
    HOME: '/api/v1/store/home',
    PRODUCTS: '/api/v1/store/products',
    CATEGORIES: '/api/v1/store/categories',
    CATALOG_FILTERS: '/api/v1/store/catalog/filters',
    SHOWCASE: '/api/v1/store/showcase',
    CART: '/api/v1/store/cart',
    CHECKOUT: '/api/v1/store/checkout',
    ORDERS: '/api/v1/store/orders',
  },
  WHOLESALE: {
    PRODUCTS: '/api/v1/wholesale/products',
    QUICK_ORDER: '/api/v1/wholesale/quick-order',
    QUOTATIONS: '/api/v1/wholesale/quotations',
    INVOICES: '/api/v1/wholesale/invoices',
    STATEMENT: '/api/v1/wholesale/statement',
  },
  SUPPLIER: {
    PURCHASE_ORDERS: '/api/v1/supplier/purchase-orders',
    DELIVERIES: '/api/v1/supplier/deliveries',
    INVOICES: '/api/v1/supplier/invoices',
  },
} as const;

export const storeEndpoints = {
  home: () => apiClient.get<{ data: StoreHomeData }>(API_ENDPOINTS.STORE.HOME).then((r) => r.data.data),
  categories: (signal?: AbortSignal) =>
    apiClient.get<{ data: StoreCategory[] }>(API_ENDPOINTS.STORE.CATEGORIES, { signal }).then((r) => r.data.data),
  products: (params: StoreProductListParams, signal?: AbortSignal) =>
    apiClient.get<ResourceCollection<StoreProductCard>>(API_ENDPOINTS.STORE.PRODUCTS, { params, signal }).then((r) => r.data),
  product: (slug: string, signal?: AbortSignal) =>
    apiClient.get<{ data: StoreProduct }>(`${API_ENDPOINTS.STORE.PRODUCTS}/${encodeURIComponent(slug)}`, { signal }).then((r) => r.data.data),
  similar: (slug: string, signal?: AbortSignal) =>
    apiClient.get<{ data: StoreProductCard[] }>(`${API_ENDPOINTS.STORE.PRODUCTS}/${encodeURIComponent(slug)}/similar`, { signal }).then((r) => r.data.data),
  filters: (params: StoreProductListParams, signal?: AbortSignal) =>
    apiClient.get<{ data: StoreCatalogFilters }>(API_ENDPOINTS.STORE.CATALOG_FILTERS, { params, signal }).then((r) => r.data.data),
  showcase: (signal?: AbortSignal) =>
    apiClient.get<{ data: StoreProduct[] }>(API_ENDPOINTS.STORE.SHOWCASE, { signal }).then((r) => r.data.data),
};

export const checkoutEndpoints = {
  cities: () => apiClient.get<{ data: StoreCity[] }>('/api/v1/store/cities').then((r) => r.data.data),
  shippingOptions: (body: { city_id: number; payment_method?: string }, session: string | null, signal?: AbortSignal) =>
    apiClient.post<{ data: ShippingOptionsResponse }>('/api/v1/store/checkout/shipping-options', body, { headers: cartHeaders(session), signal }).then((r) => r.data.data),
  place: (body: CheckoutPayload, session: string | null, idempotencyKey: string) =>
    apiClient.post<CheckoutResponse>('/api/v1/store/checkout', body, {
      headers: { ...cartHeaders(session), 'Idempotency-Key': idempotencyKey },
    }).then((r) => r.data),
  track: (number: string, phone?: string) => apiClient.get<{ data: TrackOrderResponse }>(`/api/v1/store/orders/${encodeURIComponent(number)}/track`, { params: phone ? { phone } : {} }).then((r) => r.data.data),
};

export const accountEndpoints = {
  orders: (page = 1) => apiClient.get<{ data: StoreOrder[]; meta?: { current_page: number; last_page: number } }>('/api/v1/store/account/orders', { params: { page } }).then((r) => ({
    data: r.data.data,
    currentPage: r.data.meta?.current_page ?? page,
    lastPage: r.data.meta?.last_page ?? 1,
  })),
  cancelOrder: (id: number) => apiClient.post<{ data: StoreOrder }>(`/api/v1/store/account/orders/${id}/cancel`).then((r) => r.data.data),
  wallet: (page = 1, filters: { direction?: 'credit' | 'debit'; type?: string; from?: string; to?: string } = {}) => apiClient.get<{ balance: number | string; transactions: { data: Array<{ id: number; type: string; type_label?: string | null; amount: number | string; balance_after: number | string; reference_type: string | null; reference_id: number | null; reference_label?: string | null; created_at: string }>; current_page: number; last_page: number; total: number } }>('/api/v1/store/account/wallet', { params: { page, ...filters } }).then((r) => r.data),
  returns: (page = 1) => apiClient.get<{ data: unknown[]; meta: { current_page: number; last_page: number; total: number } }>(`/api/v1/store/account/returns?page=${page}`).then((r) => r.data),
  returnDetail: (id: number) => apiClient.get<{ data: unknown }>(`/api/v1/store/account/returns/${id}`).then((r) => r.data.data),
  createReturn: (fd: FormData) => apiClient.post<{ data: { id: number } }>('/api/v1/store/account/returns', fd, { headers: { 'Content-Type': 'multipart/form-data' } }).then((r) => r.data.data),
  addresses: () => apiClient.get<{ data: Array<{ id: number; city_id: number; city_name_ar: string | null; city_name_en: string | null; address_line: string; is_default: boolean }> }>('/api/v1/store/account/addresses').then((r) => r.data.data),
};

const W = '/api/v1/wholesale';
export const wholesaleEndpoints = {
  applicationStatus: (signal?: AbortSignal) => apiClient.get(`${W}/auth/application-status`, { signal }).then((r) => r.data.data),
  dashboard: (signal?: AbortSignal) => apiClient.get(`${W}/dashboard`, { signal }).then((r) => r.data.data),
  products: (p: { search?: string; page?: number; per_page?: number }, signal?: AbortSignal) => apiClient.get(`${W}/products`, { params: p, signal }).then((r) => r.data),
  exportPriceList: (format: 'pdf' | 'xlsx') => apiClient.get(`${W}/price-list/export`, { params: { format }, responseType: 'blob', timeout: SLOW_REQUEST_TIMEOUT }),
  quickLookup: (items: Array<{ sku?: string; barcode?: string; qty: number }>) => apiClient.post(`${W}/quick-order/lookup`, { items }).then((r) => r.data),
  quickImport: (file: File) => { const f = new FormData(); f.append('file', file); return apiClient.post(`${W}/quick-order/import`, f, { headers: { 'Content-Type': 'multipart/form-data' } }).then((r) => r.data.data); },
  quickAddToCart: (items: Array<{ sku: string; qty: number }>) => apiClient.post(`${W}/quick-order/add-to-cart`, { items }).then((r) => r.data.data),
  orders: (p: { group?: string; page?: number }, signal?: AbortSignal) => apiClient.get(`${W}/orders`, { params: p, signal }).then((r) => r.data),
  order: (id: number, signal?: AbortSignal) => apiClient.get(`${W}/orders/${id}`, { signal }).then((r) => r.data.data),
  reorder: (id: number) => apiClient.post(`${W}/orders/${id}/reorder`).then((r) => r.data.data),
  invoices: (p: { payment_status?: 'paid' | 'unpaid'; page?: number }, signal?: AbortSignal) => apiClient.get(`${W}/invoices`, { params: p, signal }).then((r) => r.data),
  invoicePdf: (id: number) => apiClient.get(`${W}/invoices/${id}/pdf`, { responseType: 'blob' }),
  statement: (p: { from?: string; to?: string }, signal?: AbortSignal) => apiClient.get(`${W}/statement`, { params: p, signal }).then((r) => r.data.data),
  notices: (p: { status?: string; page?: number }, signal?: AbortSignal) => apiClient.get(`${W}/payment-notices`, { params: p, signal }).then((r) => r.data),
  notice: (id: number, signal?: AbortSignal) => apiClient.get(`${W}/payment-notices/${id}`, { signal }).then((r) => r.data.data),
  outstanding: (signal?: AbortSignal) => apiClient.get(`${W}/payment-notices/outstanding`, { signal }).then((r) => r.data.data),
  createNotice: (fd: FormData) => apiClient.post(`${W}/payment-notices`, fd, { headers: { 'Content-Type': 'multipart/form-data' } }).then((r) => r.data.data),
  noticeReceipt: (id: number) => apiClient.get(`${W}/payment-notices/${id}/receipt`, { responseType: 'blob', params: { signed: 1 } }),
  quotes: (p: { status?: string; page?: number }, signal?: AbortSignal) => apiClient.get(`${W}/quotations`, { params: p, signal }).then((r) => r.data),
  quote: (id: number, signal?: AbortSignal) => apiClient.get(`${W}/quotations/${id}`, { signal }).then((r) => r.data.data),
  createQuote: (body: unknown) => apiClient.post(`${W}/quotations`, body).then((r) => r.data.data),
  updateQuote: (id: number, body: unknown) => apiClient.put(`${W}/quotations/${id}`, body).then((r) => r.data.data),
  deleteQuote: (id: number) => apiClient.delete(`${W}/quotations/${id}`),
  submitQuote: (id: number) => apiClient.post(`${W}/quotations/${id}/submit`).then((r) => r.data.data),
  acceptQuote: (id: number) => apiClient.post(`${W}/quotations/${id}/accept`).then((r) => r.data.data),
  rejectQuote: (id: number) => apiClient.post(`${W}/quotations/${id}/reject`).then((r) => r.data.data),
  notifications: (page = 1, signal?: AbortSignal) => apiClient.get(`${W}/notifications`, { params: { page }, signal }).then((r) => r.data),
  readNotification: (id: string) => apiClient.post(`${W}/notifications/${id}/read`).then((r) => r.data),
  readAllNotifications: () => apiClient.post(`${W}/notifications/read-all`).then((r) => r.data),
};

const MULTIPART = { headers: { 'Content-Type': 'multipart/form-data' } };

function appendFormField(fd: FormData, key: string, v: unknown): void {
  if (v === undefined) return;
  if (v === null) return fd.append(key, '');
  if (typeof v === 'boolean') return fd.append(key, v ? '1' : '0');
  if (v instanceof Blob) return fd.append(key, v);
  fd.append(key, String(v));
}

function categoryFormData(p: CategoryPayload, spoofPut = false): FormData {
  const fd = new FormData();
  if (spoofPut) fd.append('_method', 'PUT');
  const put = (key: string, v: unknown) => appendFormField(fd, key, v);
  put('parent_id', p.parent_id);
  put('name_ar', p.name_ar);
  put('name_en', p.name_en);
  put('slug', p.slug);
  put('description_ar', p.description_ar);
  put('description_en', p.description_en);
  put('sort_order', p.sort_order);
  put('is_active', p.is_active);
  if (p.image) put('image', p.image);
  if (p.remove_image) put('remove_image', true);
  return fd;
}

function progress(cb?: (n: number) => void) {
  return (e: { loaded: number; total?: number }) => cb?.(Math.round((e.loaded / (e.total || 1)) * 100));
}

const CAT = API_ENDPOINTS.ADMIN.CATEGORIES; // '/api/v1/admin/categories'

export const categoriesApi = {
  tree: (signal?: AbortSignal) =>
    apiClient.get<{ data: AdminCategory[] }>(CAT, { params: { tree: 1 }, signal }).then((r) => r.data.data),
  show: (id: number) =>
    apiClient.get<{ data: AdminCategory }>(`${CAT}/${id}`).then((r) => r.data.data),
  create: (p: CategoryPayload, onProgress?: (pct: number) => void) =>
    apiClient.post<{ data: AdminCategory }>(CAT, categoryFormData(p), {
      ...MULTIPART,
      onUploadProgress: progress(onProgress),
    }).then((r) => r.data.data),
  update: (id: number, p: CategoryPayload, onProgress?: (pct: number) => void) =>
    apiClient.post<{ data: AdminCategory }>(`${CAT}/${id}`, categoryFormData(p, true), {
      ...MULTIPART,
      onUploadProgress: progress(onProgress),
    }).then((r) => r.data.data),
  remove: (id: number) =>
    apiClient.delete<{ message: string }>(`${CAT}/${id}`).then((r) => r.data),
  reorder: (items: CategoryReorderItem[]) =>
    apiClient.put<{ message: string }>(`${CAT}/reorder`, { items }).then((r) => r.data),
};

const BR = '/api/v1/admin/brands';
const PR = '/api/v1/admin/products';

export interface BrandListParams { search?: string; is_active?: 0 | 1; page?: number; per_page?: number }
export interface ProductListParams {
  search?: string; category_id?: number; include_children?: 0 | 1; brand_id?: number;
  status?: ProductStatus; sales_channel?: SalesChannel; is_featured?: 0 | 1;
  sort?: 'latest' | 'oldest' | 'name'; page?: number; per_page?: number;
}

function brandFormData(p: BrandPayload, spoofPut = false): FormData {
  const fd = new FormData();
  if (spoofPut) fd.append('_method', 'PUT');
  const put = (key: string, v: unknown) => appendFormField(fd, key, v);
  put('name_ar', p.name_ar);
  put('name_en', p.name_en);
  put('slug', p.slug);
  put('description_ar', p.description_ar);
  put('description_en', p.description_en);
  put('sort_order', p.sort_order);
  put('is_active', p.is_active);
  if (p.logo) put('logo', p.logo);
  if (p.remove_logo) put('remove_logo', true);
  return fd;
}

export const brandsApi = {
  list: (params: BrandListParams, signal?: AbortSignal) =>
    apiClient.get<ResourceCollection<AdminBrand>>(BR, { params, signal }).then(r => r.data),
  show: (id: number) => apiClient.get<{ data: AdminBrand }>(`${BR}/${id}`).then(r => r.data.data),
  create: (p: BrandPayload, onProgress?: (n: number) => void) =>
    apiClient.post<{ data: AdminBrand }>(BR, brandFormData(p), { ...MULTIPART, onUploadProgress: progress(onProgress) }).then(r => r.data.data),
  update: (id: number, p: BrandPayload, onProgress?: (n: number) => void) =>
    apiClient.post<{ data: AdminBrand }>(`${BR}/${id}`, brandFormData(p, true), { ...MULTIPART, onUploadProgress: progress(onProgress) }).then(r => r.data.data),
  remove: (id: number) => apiClient.delete<{ message: string }>(`${BR}/${id}`).then(r => r.data),
};

export const productsApi = {
  list: (params: ProductListParams, signal?: AbortSignal) =>
    apiClient.get<ResourceCollection<AdminProduct>>(PR, { params, signal }).then(r => r.data),
  show: (id: number, signal?: AbortSignal) => apiClient.get<{ data: AdminProduct }>(`${PR}/${id}`, { signal }).then(r => r.data.data),
  create: (p: ProductPayload & { variants?: VariantPayload[] }) => apiClient.post<{ data: AdminProduct }>(PR, p).then(r => r.data.data),
  update: (id: number, p: Partial<ProductPayload> & { status?: ProductStatus; expected_updated_at?: string }) =>
    apiClient.put<{ data: AdminProduct }>(`${PR}/${id}`, p).then(r => r.data.data),
  remove: (id: number) => apiClient.delete<{ message: string }>(`${PR}/${id}`).then(r => r.data),
};

export const variantsApi = {
  list: (productId: number) => apiClient.get<{ data: AdminVariant[] }>(`${PR}/${productId}/variants`).then(r => r.data.data),
  create: (productId: number, p: VariantPayload) => apiClient.post<{ data: AdminVariant }>(`${PR}/${productId}/variants`, p).then(r => r.data.data),
  update: (productId: number, id: number, p: Partial<VariantPayload>) => apiClient.put<{ data: AdminVariant }>(`${PR}/${productId}/variants/${id}`, p).then(r => r.data.data),
  remove: (productId: number, id: number) => apiClient.delete(`${PR}/${productId}/variants/${id}`).then(r => r.data),
};

export const productMediaApi = {
  list: (productId: number) => apiClient.get<{ data: AdminMedia[] }>(`${PR}/${productId}/media`).then(r => r.data.data),
  uploadImages: (productId: number, files: File[], variantId?: number | null, onProgress?: (n: number) => void, clientUploadIds?: string[]) => {
    const fd = new FormData();
    fd.append('collection', 'images');
    if (variantId) fd.append('variant_id', String(variantId));
    files.forEach((f, index) => {
      fd.append('files[]', f); // max 4 per request
      fd.append('client_upload_ids[]', clientUploadIds?.[index] ?? crypto.randomUUID());
    });
    return apiClient.post<{ data: AdminMedia[] }>(`${PR}/${productId}/media`, fd, { ...MULTIPART, timeout: 180000, onUploadProgress: progress(onProgress) }).then(r => r.data.data);
  },
  uploadVideo: (productId: number, file: File, onProgress?: (n: number) => void) => {
    const fd = new FormData();
    fd.append('collection', 'video');
    fd.append('file', file);
    return apiClient.post<{ data: AdminMedia[] }>(`${PR}/${productId}/media`, fd, { ...MULTIPART, onUploadProgress: progress(onProgress), timeout: 0 }).then(r => r.data.data);
  },
  reorder: (productId: number, ids: number[]) => apiClient.put(`${PR}/${productId}/media/reorder`, { ids }).then(r => r.data),
  remove: (productId: number, mediaId: number) => apiClient.delete(`${PR}/${productId}/media/${mediaId}`).then(r => r.data),
};
```

## File: bags-platform/backend/routes/api/admin.php
```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\Ai\AiActionController;
use App\Http\Controllers\Api\V1\Admin\Ai\AiAdministrationController;
use App\Http\Controllers\Api\V1\Admin\Ai\AiAttachmentController;
use App\Http\Controllers\Api\V1\Admin\Ai\AiConversationController;
use App\Http\Controllers\Api\V1\Admin\Ai\AiMentionController;
use App\Http\Controllers\Api\V1\Admin\Ai\AiMessageController;
use App\Http\Controllers\Api\V1\Admin\Ai\AiWelcomeController;
use App\Http\Controllers\Api\V1\Admin\Ai\WhatsAppAdminController;
use App\Http\Controllers\Api\V1\Admin\AuthController;
use App\Http\Controllers\Api\V1\Admin\BannerController;
use App\Http\Controllers\Api\V1\Admin\BranchController;
use App\Http\Controllers\Api\V1\Admin\BrandController;
use App\Http\Controllers\Api\V1\Admin\CategoryController;
use App\Http\Controllers\Api\V1\Admin\CouponController;
use App\Http\Controllers\Api\V1\Admin\CustomerController;
use App\Http\Controllers\Api\V1\Admin\CustomerGroupController;
use App\Http\Controllers\Api\V1\Admin\CustomerWalletController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\ExpenseController;
use App\Http\Controllers\Api\V1\Admin\InventoryController;
use App\Http\Controllers\Api\V1\Admin\LocationController;
use App\Http\Controllers\Api\V1\Admin\OrderController;
use App\Http\Controllers\Api\V1\Admin\PermissionController;
use App\Http\Controllers\Api\V1\Admin\PosShiftAdminController;
use App\Http\Controllers\Api\V1\Admin\PriceListController;
use App\Http\Controllers\Api\V1\Admin\PriceListItemController;
use App\Http\Controllers\Api\V1\Admin\ProductController;
use App\Http\Controllers\Api\V1\Admin\ProductionReceiptController;
use App\Http\Controllers\Api\V1\Admin\ProductMediaController;
use App\Http\Controllers\Api\V1\Admin\ProductVariantController;
use App\Http\Controllers\Api\V1\Admin\PromotionController;
use App\Http\Controllers\Api\V1\Admin\PurchaseOrderController;
use App\Http\Controllers\Api\V1\Admin\PurchaseRequestController;
use App\Http\Controllers\Api\V1\Admin\QuotationController;
use App\Http\Controllers\Api\V1\Admin\ReportController;
use App\Http\Controllers\Api\V1\Admin\ReturnController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\SettingController;
use App\Http\Controllers\Api\V1\Admin\ShippingCompanyController;
use App\Http\Controllers\Api\V1\Admin\ShippingRateController;
use App\Http\Controllers\Api\V1\Admin\ShippingZoneController;
use App\Http\Controllers\Api\V1\Admin\StocktakeController;
use App\Http\Controllers\Api\V1\Admin\SupplierController;
use App\Http\Controllers\Api\V1\Admin\SupplierInvoiceController;
use App\Http\Controllers\Api\V1\Admin\SupplierPaymentController;
use App\Http\Controllers\Api\V1\Admin\TransferController;
use App\Http\Controllers\Api\V1\Admin\TreasuryController;
use App\Http\Controllers\Api\V1\Admin\TreasuryTransferController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Admin\WholesalePaymentNoticeController;
use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\Shared\NotificationController;
use App\Http\Middleware\AuditStaffAdministration;
use Illuminate\Support\Facades\Route;

// Enforce numeric route patterns: non-numeric segments yield 404 instead of Postgres type errors (500).
Route::pattern('category', '[0-9]{1,18}');
Route::pattern('brand', '[0-9]{1,18}');
Route::pattern('product', '[0-9]{1,18}');
Route::pattern('variant', '[0-9]{1,18}');
Route::pattern('media', '[0-9]{1,18}');
Route::pattern('transfer', '[0-9]{1,18}');
Route::pattern('stocktake', '[0-9]{1,18}');
Route::pattern('customer_group', '[0-9]{1,18}');
Route::pattern('customer', '[0-9]{1,18}');
Route::pattern('document', '[0-9]{1,18}');
Route::pattern('price_list', '[0-9]{1,18}');
Route::pattern('item', '[0-9]{1,18}');
Route::pattern('order', '[0-9]{1,18}');
Route::pattern('coupon', '[0-9]{1,18}');
Route::pattern('promotion', '[0-9]{1,18}');
Route::pattern('return_request', '[0-9]{1,18}');
Route::pattern('shipping_company', '[0-9]{1,18}');
Route::pattern('shipping_zone', '[0-9]{1,18}');
Route::pattern('shipping_rate', '[0-9]{1,18}');
Route::pattern('supplier', '[0-9]{1,18}');
Route::pattern('role', '[0-9]{1,18}');
Route::pattern('branch', '[0-9]{1,18}');
Route::pattern('location', '[0-9]{1,18}');
Route::pattern('user', '[0-9]{1,18}');
Route::pattern('banner', '[0-9]{1,18}');
Route::pattern('purchase_request', '[0-9]{1,18}');
Route::pattern('purchase_order', '[0-9]{1,18}');
Route::pattern('supplier_invoice', '[0-9]{1,18}');
Route::pattern('supplier_payment', '[0-9]{1,18}');
// Constrain AI route parameters: conversation IDs are numeric and action IDs are 26-char ULIDs,
// failing cleanly with 404 rather than triggering PostgreSQL syntax casting errors (22P02).
Route::pattern('conversation', '[0-9]{1,18}');
Route::pattern('action', '[0-9A-Za-z]{26}');

// Public admin authentication endpoint (legacy — prefer /api/v1/auth/login).
// Disabled when LEGACY_LOGIN_ENABLED=false to avoid splitting brute-force rate limits across endpoints.
if (config('auth.legacy_login_enabled', false)) {
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:admin-login')
        ->name('admin.auth.login');
}

// Staff-only API endpoints: require a Sanctum token and an active staff account.
Route::middleware(['auth:sanctum', 'auth.staff', AuditStaffAdministration::class])
    ->name('admin.')
    ->group(function (): void {
        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('dashboard', [DashboardController::class, 'index'])->middleware('permission:reports.view')->name('dashboard');
        Route::prefix('reports')->name('reports.')->middleware(['permission:reports.view', 'throttle:30,1'])->group(function (): void {
            Route::get('sales', [ReportController::class, 'sales'])->name('sales');
            Route::get('inventory', [ReportController::class, 'inventory'])->name('inventory');
            Route::get('profit', [ReportController::class, 'profit'])->name('profit');
            Route::get('suppliers', [ReportController::class, 'suppliers'])->name('suppliers');
            Route::get('shifts', [ReportController::class, 'shifts'])->name('shifts');
        });
        // Constrain notification IDs to UUIDs so malformed identifiers return 404 rather than casting exceptions.
        Route::post('notifications/{id}/read', [NotificationController::class, 'markAsRead'])->whereUuid('id')->name('notifications.read');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');

        Route::get('me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        Route::apiResource('branches', BranchController::class)
            ->only(['index', 'show'])
            ->middleware('permission:branches.view|branches.manage');
        Route::apiResource('branches', BranchController::class)
            ->only(['store'])
            ->middleware('permission:branches.create|branches.manage');
        Route::apiResource('branches', BranchController::class)
            ->only(['update'])
            ->middleware('permission:branches.update|branches.manage');
        Route::apiResource('branches', BranchController::class)
            ->only(['destroy'])
            ->middleware('permission:branches.delete|branches.manage');

        Route::scopeBindings()->group(function (): void {
            Route::apiResource('branches.locations', LocationController::class)
                ->only(['index', 'show'])
                ->middleware('permission:locations.view|locations.manage');
            Route::apiResource('branches.locations', LocationController::class)
                ->only(['store'])
                ->middleware('permission:locations.create|locations.manage');
            Route::apiResource('branches.locations', LocationController::class)
                ->only(['update'])
                ->middleware('permission:locations.update|locations.manage');
            Route::apiResource('branches.locations', LocationController::class)
                ->only(['destroy'])
                ->middleware('permission:locations.delete|locations.manage');
        });

        // Allow self-service rotation for flagged temporary passwords without requiring users.update permission.
        Route::put('me/password', [UserController::class, 'updateOwnPassword'])->name('me.password');
        Route::apiResource('users', UserController::class)
            ->only(['index', 'show'])
            ->middleware('permission:users.view|users.manage');
        Route::apiResource('users', UserController::class)
            ->only(['store'])
            ->middleware('permission:users.create|users.manage');
        Route::apiResource('users', UserController::class)
            ->only(['update'])
            ->middleware('permission:users.update|users.manage');
        Route::apiResource('users', UserController::class)
            ->only(['destroy'])
            ->middleware('permission:users.delete|users.manage');

        Route::apiResource('roles', RoleController::class)
            ->only(['index', 'show'])
            ->middleware('permission:roles.view|roles.manage');
        Route::apiResource('roles', RoleController::class)
            ->only(['store'])
            ->middleware('permission:roles.create|roles.manage');
        Route::apiResource('roles', RoleController::class)
            ->only(['update'])
            ->middleware('permission:roles.update|roles.manage');
        Route::apiResource('roles', RoleController::class)
            ->only(['destroy'])
            ->middleware('permission:roles.delete|roles.manage');

        Route::get('permissions', [PermissionController::class, 'index'])
            ->middleware('permission:permissions.view|roles.manage')
            ->name('permissions.index');

        // Catalog: authorization is handled in the controllers via can:* middleware.
        // Place custom categories/reorder before apiResource to prevent matching {category} parameter.
        Route::put('categories/reorder', [CategoryController::class, 'reorder'])->name('categories.reorder');
        Route::apiResource('categories', CategoryController::class);

        Route::apiResource('brands', BrandController::class);

        Route::apiResource('products', ProductController::class);
        Route::apiResource('products.variants', ProductVariantController::class)->scoped();

        Route::put('products/{product}/media/reorder', [ProductMediaController::class, 'reorder'])->name('products.media.reorder');
        Route::get('products/{product}/media', [ProductMediaController::class, 'index'])->name('products.media.index');
        // Throttle product media uploads to prevent CPU saturation from synchronous image processing.
        Route::post('products/{product}/media', [ProductMediaController::class, 'store'])->middleware('throttle:30,1')->name('products.media.store');
        Route::delete('products/{product}/media/{media}', [ProductMediaController::class, 'destroy'])->name('products.media.destroy');

        // Inventory management
        Route::get('production-receipts/options', [ProductionReceiptController::class, 'options'])->name('production-receipts.options');
        Route::get('production-receipts', [ProductionReceiptController::class, 'index'])->name('production-receipts.index');
        Route::post('production-receipts', [ProductionReceiptController::class, 'store'])->name('production-receipts.store');
        Route::prefix('inventory')->name('inventory.')->group(function () {
            Route::get('/', [InventoryController::class, 'index'])->name('index');
            Route::get('/movements', [InventoryController::class, 'movements'])->name('movements');
            Route::post('/adjustments', [InventoryController::class, 'adjustments'])->name('adjustments');
        });

        // Stock transfers
        Route::apiResource('transfers', TransferController::class);
        Route::post('transfers/{transfer}/approve', [TransferController::class, 'approve'])->name('transfers.approve');
        Route::post('transfers/{transfer}/ship', [TransferController::class, 'ship'])->name('transfers.ship');
        Route::post('transfers/{transfer}/receive', [TransferController::class, 'receive'])->name('transfers.receive');
        Route::post('transfers/{transfer}/cancel', [TransferController::class, 'cancel'])->name('transfers.cancel');

        // Stocktake sessions
        Route::apiResource('stocktakes', StocktakeController::class);
        Route::patch('stocktakes/{stocktake}/items', [StocktakeController::class, 'updateItems'])->name('stocktakes.items.update');
        Route::post('stocktakes/{stocktake}/approve', [StocktakeController::class, 'approve'])->name('stocktakes.approve');

        // Pricing and customer groups
        Route::apiResource('customer-groups', CustomerGroupController::class);

        Route::apiResource('price-lists', PriceListController::class);
        Route::post('price-lists/{price_list}/import', [PriceListController::class, 'import'])->name('price-lists.import');
        Route::get('price-lists/{price_list}/export', [PriceListController::class, 'export'])->name('price-lists.export');
        Route::post('price-lists/{price_list}/apply-percentage', [PriceListController::class, 'applyPercentage'])->name('price-lists.apply-percentage');

        Route::scopeBindings()->group(function () {
            Route::apiResource('price-lists.items', PriceListItemController::class);
        });

        // Customer management
        Route::apiResource('customers', CustomerController::class);
        Route::post('customers/{customer}/documents', [CustomerController::class, 'storeDocument'])->name('customers.documents.store');
        Route::post('customers/{customer}/restore', [CustomerController::class, 'restore'])->withTrashed()->name('customers.restore');
        Route::post('customers/{customer}/approve', [CustomerController::class, 'approve'])->name('customers.approve');
        Route::post('customers/{customer}/reject', [CustomerController::class, 'reject'])->name('customers.reject');
        Route::post('customers/{customer}/suspend', [CustomerController::class, 'suspend'])->name('customers.suspend');
        Route::post('customers/{customer}/reactivate', [CustomerController::class, 'reactivate'])->name('customers.reactivate');
        Route::get('customers/{customer}/history', [CustomerController::class, 'history'])->name('customers.history');
        Route::get('customers/{customer}/wallet', [CustomerWalletController::class, 'show'])->name('customers.wallet.show');
        Route::get('customers/{customer}/wallet/summary', [CustomerWalletController::class, 'summary'])->name('customers.wallet.summary');
        Route::get('customers/{customer}/wallet/adjustments', [CustomerWalletController::class, 'adjustments'])->name('customers.wallet.adjustments.index');
        Route::post('customers/{customer}/wallet/adjustments', [CustomerWalletController::class, 'storeAdjustment'])->name('customers.wallet.adjustments.store');
        Route::delete('wallet-adjustments/{adjustment}', [CustomerWalletController::class, 'reverseAdjustment'])->name('wallet.adjustments.reverse');
        Route::scopeBindings()->group(function (): void {
            Route::patch('customers/{customer}/documents/{document}', [CustomerController::class, 'reviewDocument'])
                ->name('customers.documents.update');
            Route::get('customers/{customer}/documents/{document}/download', [CustomerController::class, 'downloadDocument'])
                ->name('customers.documents.download');
        });

        // Order management
        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/stats', [OrderController::class, 'stats'])->name('orders.stats');
        Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.update-status');
        Route::post('orders/{order}/payment', [OrderController::class, 'updatePayment'])->name('orders.update-payment');
        Route::post('orders/{order}/allocate', [OrderController::class, 'allocate'])->name('orders.allocate');
        // Releases allocated branch stock for an order back into available inventory.
        Route::post('orders/{order}/deallocate', [OrderController::class, 'deallocate'])->name('orders.deallocate');
        Route::post('orders/{order}/shipment', [OrderController::class, 'createShipment'])->name('orders.shipment');

        // Shipping carriers, zones, and rates
        Route::apiResource('shipping-companies', ShippingCompanyController::class);
        Route::apiResource('shipping-zones', ShippingZoneController::class);
        Route::apiResource('shipping-rates', ShippingRateController::class);

        // Promotions and coupons
        Route::apiResource('promotions', PromotionController::class);
        Route::apiResource('coupons', CouponController::class);

        Route::get('return-items/{return_item}/attachments/{attachment}', [AttachmentController::class, 'returnFile'])->whereNumber(['return_item', 'attachment'])->name('return-items.attachments.show');
        Route::get('supplier-invoices/{invoice}/attachment', [AttachmentController::class, 'invoice'])->whereNumber('invoice')->name('supplier-invoices.attachment');
        Route::get('supplier-deliveries/{delivery}/invoice', [AttachmentController::class, 'delivery'])->whereNumber('delivery')->name('deliveries.invoice');
        // Wholesale quotations and payment notices
        Route::get('quotations', [QuotationController::class, 'index'])->name('quotations.index');
        Route::get('quotations/{quotation}', [QuotationController::class, 'show'])->whereNumber('quotation')->name('quotations.show');
        Route::post('quotations/{quotation}/price', [QuotationController::class, 'price'])->whereNumber('quotation')->name('quotations.price');
        Route::post('quotations/{quotation}/expire', [QuotationController::class, 'expire'])->whereNumber('quotation')->name('quotations.expire');
        Route::get('payment-notices', [WholesalePaymentNoticeController::class, 'index'])->name('payment-notices.index');
        Route::get('payment-notices/{payment_notice}', [WholesalePaymentNoticeController::class, 'show'])->whereNumber('payment_notice')->name('payment-notices.show');
        Route::post('payment-notices/{payment_notice}/approve', [WholesalePaymentNoticeController::class, 'approve'])->whereNumber('payment_notice')->name('payment-notices.approve');
        Route::post('payment-notices/{payment_notice}/reject', [WholesalePaymentNoticeController::class, 'reject'])->whereNumber('payment_notice')->name('payment-notices.reject');
        Route::get('payment-notices/{notice}/receipt', [AttachmentController::class, 'paymentNotice'])->whereNumber('notice')->name('payment-notices.receipt');

        // Return requests (RMA)
        Route::apiResource('returns', ReturnController::class)->parameters(['returns' => 'return_request']);
        Route::post('returns/{return_request}/approve', [ReturnController::class, 'approve'])->name('returns.approve');
        Route::post('returns/{return_request}/receive', [ReturnController::class, 'receive'])->name('returns.receive');
        Route::post('returns/{return_request}/refund', [ReturnController::class, 'refund'])->name('returns.refund');

        // Procurement, suppliers, and purchase orders
        Route::apiResource('suppliers', SupplierController::class);
        Route::get('suppliers/{supplier}/users', [SupplierController::class, 'users'])->name('suppliers.users.index');
        Route::post('suppliers/{supplier}/users', [SupplierController::class, 'storeUser'])->name('suppliers.users.store');
        Route::put('suppliers/{supplier}/users/{user}', [SupplierController::class, 'updateUser'])->name('suppliers.users.update');
        Route::delete('suppliers/{supplier}/users/{user}', [SupplierController::class, 'destroyUser'])->name('suppliers.users.destroy');

        Route::apiResource('purchase-requests', PurchaseRequestController::class)
            ->parameters(['purchase-requests' => 'purchase_request']);
        Route::post('purchase-requests/{purchase_request}/submit', [PurchaseRequestController::class, 'submit'])->name('purchase-requests.submit');
        Route::post('purchase-requests/{purchase_request}/approve', [PurchaseRequestController::class, 'approve'])->name('purchase-requests.approve');
        Route::post('purchase-requests/{purchase_request}/reject', [PurchaseRequestController::class, 'reject'])->name('purchase-requests.reject');

        Route::apiResource('purchase-orders', PurchaseOrderController::class)
            ->parameters(['purchase-orders' => 'purchase_order']);
        Route::post('purchase-orders/{purchase_order}/approve', [PurchaseOrderController::class, 'approve'])->name('purchase-orders.approve');
        Route::post('purchase-orders/{purchase_order}/send', [PurchaseOrderController::class, 'send'])->name('purchase-orders.send');
        Route::post('purchase-orders/{purchase_order}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel');
        Route::post('purchase-orders/{purchase_order}/close-short', [PurchaseOrderController::class, 'closeShort'])->name('purchase-orders.close-short');
        Route::post('purchase-orders/{purchase_order}/receive', [PurchaseOrderController::class, 'receive'])->name('purchase-orders.receive');

        Route::apiResource('supplier-invoices', SupplierInvoiceController::class)
            ->parameters(['supplier-invoices' => 'supplier_invoice']);
        Route::post('supplier-invoices/{supplier_invoice}/post', [SupplierInvoiceController::class, 'post'])->name('supplier-invoices.post');
        Route::post('supplier-invoices/{supplier_invoice}/cancel', [SupplierInvoiceController::class, 'cancel'])->name('supplier-invoices.cancel');

        Route::apiResource('supplier-payments', SupplierPaymentController::class)
            ->parameters(['supplier-payments' => 'supplier_payment']);
        Route::post('supplier-payments/{supplier_payment}/allocate', [SupplierPaymentController::class, 'allocate'])->name('supplier-payments.allocate');
        Route::post('supplier-payments/{supplier_payment}/void', [SupplierPaymentController::class, 'void'])->name('supplier-payments.void');

        Route::middleware('permission:store.manage|settings.view')->group(function (): void {
            Route::get('banners', [BannerController::class, 'index'])->name('banners.index');
            Route::get('banners/{banner}', [BannerController::class, 'show'])->name('banners.show');
        });
        Route::middleware('permission:store.manage')->group(function (): void {
            Route::put('banners/reorder', [BannerController::class, 'reorder'])->name('banners.reorder');
            Route::post('banners', [BannerController::class, 'store'])->middleware('throttle:30,1')->name('banners.store');
            Route::post('banners/{banner}', [BannerController::class, 'update'])->middleware('throttle:30,1')->name('banners.update');
            Route::patch('banners/{banner}/toggle', [BannerController::class, 'toggle'])->name('banners.toggle');
            Route::delete('banners/{banner}', [BannerController::class, 'destroy'])->name('banners.destroy');
        });

        Route::get('settings', [SettingController::class, 'index'])
            ->middleware('permission:settings.view|settings.manage')
            ->name('settings.index');
        Route::put('settings', [SettingController::class, 'update'])
            ->middleware('permission:settings.update|settings.manage')
            ->name('settings.update');

        // Treasuries, expenses, transfers, and shifts
        Route::pattern('treasury', '[0-9]{1,18}');
        Route::pattern('expense', '[0-9]{1,18}');
        Route::pattern('treasury_transfer', '[0-9]{1,18}');
        Route::pattern('shift', '[0-9]{1,18}');

        Route::apiResource('treasuries', TreasuryController::class);

        Route::apiResource('expenses', ExpenseController::class);
        Route::get('expenses/{expense}/attachment', [ExpenseController::class, 'attachment'])->name('expenses.attachment');
        Route::post('expenses/{expense}/void', [ExpenseController::class, 'void'])->name('expenses.void');

        Route::apiResource('treasury-transfers', TreasuryTransferController::class)
            ->parameters(['treasury-transfers' => 'treasuryTransfer'])
            ->only(['index', 'store', 'show']);
        Route::post('treasury-transfers/{treasuryTransfer}/approve', [TreasuryTransferController::class, 'approve'])
            ->name('treasury-transfers.approve');
        Route::post('treasury-transfers/{treasuryTransfer}/reject', [TreasuryTransferController::class, 'reject'])
            ->name('treasury-transfers.reject');

        Route::get('shifts', [PosShiftAdminController::class, 'index'])
            ->name('shifts.index');
        Route::get('shifts/{shift}', [PosShiftAdminController::class, 'show'])
            ->name('shifts.show');
        Route::post('shifts/{shift}/approve', [PosShiftAdminController::class, 'approve'])
            ->name('shifts.approve');

        Route::prefix('ai')->name('ai.')->middleware('permission:ai_agent.use')->group(function (): void {
            Route::get('settings', [AiAdministrationController::class, 'settings'])->middleware('permission:ai_agent.settings')->name('settings');
            Route::put('settings', [AiAdministrationController::class, 'updateSettings'])->middleware('permission:ai_agent.settings')->name('settings.update');
            Route::get('usage', [AiAdministrationController::class, 'usage'])->name('usage');
            Route::middleware('permission:ai_agent.settings')->group(function (): void {
                Route::get('whatsapp/status', [WhatsAppAdminController::class, 'status'])->name('whatsapp.status');
                Route::get('whatsapp/qr', [WhatsAppAdminController::class, 'qr'])->name('whatsapp.qr');
                Route::post('whatsapp/logout', [WhatsAppAdminController::class, 'logout'])->name('whatsapp.logout');
                Route::get('whatsapp/identities', [WhatsAppAdminController::class, 'identities'])->name('whatsapp.identities');
                Route::post('whatsapp/identities', [WhatsAppAdminController::class, 'storeIdentity'])->name('whatsapp.identities.store');
                Route::post('whatsapp/identities/{identity}/link', [WhatsAppAdminController::class, 'link'])->name('whatsapp.identities.link');
                Route::post('whatsapp/identities/{identity}/revoke', [WhatsAppAdminController::class, 'revoke'])->name('whatsapp.identities.revoke');
                Route::get('whatsapp/staff', [WhatsAppAdminController::class, 'staff'])->name('whatsapp.staff');
                Route::get('whatsapp/monitor', [WhatsAppAdminController::class, 'monitor'])->name('whatsapp.monitor');
            });
            Route::patch('conversations/{conversation}/pin', [AiConversationController::class, 'pin'])->name('conversations.pin');
            Route::post('conversations/{conversation}/stream', [AiMessageController::class, 'stream'])->middleware('throttle:ai-chat')->name('conversations.stream');
            Route::get('conversations', [AiConversationController::class, 'index'])->name('conversations.index');
            // Throttle conversation creation to rate-limit conversation record and initial title setup.
            Route::post('conversations', [AiConversationController::class, 'store'])->middleware('throttle:30,1')->name('conversations.store');
            Route::get('conversations/{conversation}', [AiConversationController::class, 'show'])->name('conversations.show');
            Route::delete('conversations/{conversation}', [AiConversationController::class, 'destroy'])->name('conversations.destroy');
            Route::post('conversations/{conversation}/messages', [AiMessageController::class, 'store'])->middleware('throttle:ai-chat')->name('conversations.messages.store');
            Route::post('conversations/{conversation}/attachments', [AiAttachmentController::class, 'store'])->middleware('throttle:30,1')->name('conversations.attachments.store');
            Route::post('actions/{action}/confirm', [AiActionController::class, 'confirm'])->middleware('permission:ai_agent.execute')->name('actions.confirm');
            Route::post('actions/{action}/cancel', [AiActionController::class, 'cancel'])->name('actions.cancel');
            Route::post('actions/{action}/undo', [AiActionController::class, 'undo'])->middleware('permission:ai_agent.execute')->name('actions.undo');
            // Throttle debounced autocomplete queries on staff and product mentions.
            Route::get('mentions', [AiMentionController::class, 'search'])->middleware('throttle:60,1')->name('mentions');
            Route::get('welcome', [AiWelcomeController::class, 'show'])->name('welcome');
        });
    });
```
