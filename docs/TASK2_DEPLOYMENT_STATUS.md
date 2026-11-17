# task2 — Deployment Status

Source roadmaps:
- `_archive_unused/tasks_execution/task2.txt` (Procurement, Supplier Portal, Goods Receipt & Inward Audit, 23 findings — Complete & Verified)
- `task2.txt` (Finance, Concurrency, Report & Treasury Audit, 18 findings — Historical Reference)

Live Cloud Run revisions and current audit logs: [Task execution audit](TASKS_EXECUTION_AUDIT.md).
Last deployed commit: `25ee590` (see `docs/.last-deployed`)
Date: 2026-11-16
Status: **Ready for Server Deployment**

---

# Deployed vs. Pending Deployment

### A. Already Deployed on Server

- Commit `25ee590` (`2026-11-10`, per `docs/.last-deployed`).
- Live server deployment state unverified from local workspace (no direct production SSH/DB access).
- None of the newly implemented local fixes and migrations from `_archive_unused/tasks_execution/task2.txt` or `task2.txt` are active in production yet.

---

### B. Ready / Pending Server Deployment

All 23 roadmap items from `_archive_unused/tasks_execution/task2.txt` are fully implemented, verified via automated test suites (Backend PHPUnit & Frontend TypeScript / Node.js test runners), and ready for deployment:

#### 1. Database Migrations & Index Hardening
- **`2026_11_16_000003_add_supplier_users_and_procurement_indexes.php`**:
  - `MEDIUM-06`: Adds concurrent unique index on `supplier_users(lower(btrim(email)))` for case-insensitive `O(log N)` authentication and login lookup.
  - `MEDIUM-08`: Adds composite indexes on `purchase_orders(supplier_id, id DESC)` and `supplier_deliveries(supplier_id, id DESC)` for zero-overhead supplier portal dashboard and authorization queries.
  - `MEDIUM-09`: Adds clear descriptive Arabic error messages on `lock_timeout = '3s'` acquisition failures in database migrations.

#### 2. Backend Concurrency, Locking Hierarchy & Business Invariants
- `HIGH-01`: Enforced canonical lock hierarchy `Supplier -> PurchaseOrder -> Deliveries/Items` via `lockSupplierThenOrder` across `cancelPurchaseOrder`, `deletePurchaseOrder`, `closeShort`, `closeDeliveryShort`, `supplierAccept`, and `supplierRequestChange` in `ProcurementService.php`, eliminating PostgreSQL deadlocks (SQLSTATE 40P01).
- `CRITICAL-03`: Implemented canonical items fingerprinting in `ProcurementService::updatePurchaseOrder` (comparing variant IDs, ordered quantities, unit costs, tax rates, and notes) to reject identical payloads as no-ops, preventing deletion/recreation sequence burn and closing the separation-of-duties self-approval laundering vulnerability.
- `HIGH-02`: Realigned `invoiceCeiling` in `SupplierFinanceService.php` to calculate exact 12-decimal `netFactor` without premature intermediate 2-decimal rounding of `discountShare`, maintaining strict 3-way matching precision.
- `MEDIUM-01`: Enforced explicit `is_active` validation on treasuries in `SupplierFinanceService::executePayment` (`if (! $treasury->is_active) throw new BusinessException(...)`).
- `MEDIUM-04`: Enforced `assertBranchAccess($creator, (int) $pr->branch_id)` and verified `$data['branch_id'] === (int) $pr->branch_id` in `ProcurementService::createPurchaseOrder`.
- `MEDIUM-05`: Enforced variant subset matching in `createPurchaseOrder` when explicit items are passed alongside a purchase request.
- `LOW-02`: Added `lastModifier(): BelongsTo` relationship in `PurchaseOrder.php` and exposed `last_modified_by` in `PurchaseOrderResource.php`.

#### 3. Backend Statement Integrity, Performance & Security Boundaries
- `HIGH-03`: `SupplierFinanceService::buildStatementSnapshot` reloads `$supplier = Supplier::query()->findOrFail($supplier->id)` within the transactional snapshot to guarantee mathematical parity between `current_balance` and `closing_balance`.
- `HIGH-04`: Replaced heavy Eloquent model hydration with `toBase()` query builder joins in `buildStatementSnapshot` and enforced a strict 5,000-entry ceiling throwing a clear `BusinessException` on unbounded queries.
- `HIGH-05`: Enforced `assertCentralAccess` on `SupplierController` management actions (`store`, `update`, `destroy`, `users`, `storeUser`, `updateUser`, `destroyUser`), and added row-locking and `is_active` validation during user creation and update.
- `MEDIUM-02`: Prefixed advisory locks in `SerializesAccountEmail` with `'account_email:'` to prevent lock collision with other system advisory locks.
- `MEDIUM-03`: Fixed `SendPurchaseOrderSent` listener to verify `PurchaseOrderStatus::SentToSupplier`, load fresh `$po->supplier_id`, pass the updated `$po` instance, and isolate recipient notification dispatching.

#### 4. Frontend Storefront (Supplier Portal)
- `CRITICAL-01`: Created dynamic detail pages:
  - `apps/storefront/src/app/supplier/purchase-orders/[id]/page.tsx`: Full purchase order details, item breakdown, accept, request change, and ASN delivery notice submission.
  - `apps/storefront/src/app/supplier/deliveries/[id]/page.tsx`: Shipment notice tracking, delivery line items, status details, and protected attachment downloads.
- `CRITICAL-02`: Restored all Arabic text labels, titles, loading indicators, and logout buttons across `layout.tsx`, `page.tsx`, and `profile/page.tsx`, and added `dir="rtl"` to main authenticated containers.
- `MEDIUM-07`: Fixed statement filter in `storefront/src/app/supplier/statement/page.tsx` to clear previous errors upon form submission or reset.

#### 5. Frontend Backoffice (Operations Procurement)
- `HIGH-06`: In `suppliers.tsx`:
  - `POModule::openForm`: Eager-loads full purchase order details via `purchaseOrdersApi.show(po.id)` when editing from list view if items are missing.
  - `ReceivePOModal`: Automatically loads deliveries if not populated on the input purchase order object.
  - `PurchasingPage`: Clears `paymentInvoice` upon form open via `onClearInitialInvoice` callback, allowing re-payments without UI lockup.
- `HIGH-07`: In `ReceivePOModal`: Added `useEffect` on `[open, po?.id]` to reset `qtys`, `deliveryId`, `locationId`, and `lastPayload.current`, and generate fresh `newIdempotencyKey()`, preventing idempotency collision and silent data loss across consecutive receipts.
- `LOW-01`: Replaced `setForm(false)` with `setForm(null)` across `POModule`, and scoped `approvedPRs` to `branch_id: form?.branch ? Number(form.branch) : undefined`.
- `LOW-02`: Added user separation check in `POModule::actionsFor` to prevent the creator or last modifier from self-approving purchase orders.
- `MEDIUM-07`: Added `from_date` and `to_date` date pickers in `SupplierDetail` statement tab, passing date bounds to `suppliersApi.statement(id, { from_date, to_date })`.
- `MEDIUM-10`: In `PayModule::save`: Added client-side validation ensuring `form.sup`, `form.treasury`, and positive `form.amount` are present, requiring reference number for non-cash payment methods, and auto-filling remaining invoice balance when selecting an invoice.

#### 6. Repomix Synchronization
- Updated `repomix/configs/repomix.subsystem-08-procurement-suppliers.json` to include newly added storefront dynamic routes and backend resources.
- Synchronized bundles in `repomix/outputs/started/repomix-subsystem-08-procurement-suppliers.xml` and `repomix/outputs/repomix-subsystem-08-procurement-suppliers.xml`.

---

## Required Deployment Commands & Operational Steps

When authorized for staging/production deployment:

1. **Database Migration**:
   ```bash
   php artisan migrate --force
   ```
2. **Schema & Index Verification**:
   ```bash
   php artisan db:verify-schema
   ```
3. **Cache Invalidation & Warmup**:
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```
4. **Queue Worker Restart**:
   ```bash
   php artisan queue:restart
   ```
5. **Frontend Asset Build**:
   ```bash
   pnpm -r build
   ```

---

## Automated Verification Evidence

- **Backend Feature Test Suites**:
  - `Task2Slice1ProcurementIntegrityTest.php`: 6/6 tests passed (14 assertions).
  - `Task2Slice2ProcurementSecurityAndStatementTest.php`: 4/4 tests passed (9 assertions).
  - `ProcurementTest.php`: 12/12 tests passed (96 assertions).
  - `TaskTxtProcurementCloseoutTest.php`: 11/11 tests passed (58 assertions).
  - Total: **33/33 tests passed, 177 assertions, 0 failures, 0 errors**.
- **Frontend Test Suite & Static Analysis**:
  - `node bags-platform/frontend/tests/operations-suppliers.test.cjs`: 5/5 tests passed.
  - Storefront TypeScript Typecheck (`apps/storefront/src`): 0 errors (`tsc --noEmit`).
  - Backoffice TypeScript Typecheck (`apps/backoffice/src`): 0 errors (`tsc --noEmit`).
