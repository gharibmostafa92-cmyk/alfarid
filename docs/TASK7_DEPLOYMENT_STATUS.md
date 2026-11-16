# Task 7 — Supplier & Procurement Findings Deployment Status

Source roadmap: `_archive_unused/tasks_execution/task7.txt` (18 findings: `F01`–`F18`).
Date: 2026-11-16
Branch: `fixes/task-audit-roadmap` (local working tree; **not deployed to live production**)

# Deployed vs. Pending Deployment

### A. Already Deployed on Server

- **Live server status for Task 7 changes:** None of the Task 7 (`F01`–`F18`) fixes or migrations are deployed to production. Live server runtime verification was not executed in this task; treat live state as unverified.
- Pre-existing Task 7 area behavior on the server is unchanged by this branch until the items below are released.

### B. Ready / Pending Server Deployment

All items below are implemented locally in the repository and require release approval, migration execution by the migration-owner role, backend container deploy, and frontend backoffice/storefront rebuild. **Do not run migrations or restarts on production without explicit authorization.**

#### 1. Database Migrations (Must Run with Migration-Owner Role Before App Traffic)

- **`2026_11_16_000001_add_supplier_payments_treasury_id_index.php`** (`F15`, new, `withinTransaction = false`):
  - Creates concurrent index `supplier_payments_treasury_id_index` on `supplier_payments (treasury_id)` for branch-scoped `whereHas('treasury')` lookups and `restrictOnDelete` FK checks.
  - Rehearsal: run `php artisan migrate --force` under migration-owner credentials, then `php artisan db:verify-schema` if available.

#### 2. Backend Code Changes (API Container Rebuild & Deploy)

- **Payment update contract (`F02`):** `UpdateSupplierPaymentRequest` no longer `prohibited`; accepts `sometimes` + `SupplierPaymentMethod` enum. `SupplierFinanceService::updatePayment` keeps rejecting an actually-changed method (422). Frontend update payload now sends only `{ reference_no, notes }`.
- **Statement branch isolation (`F04`):** `SupplierController::statement` now requires central access (`assertCentralAccess`); branch-restricted staff receive 403 instead of the company-wide ledger.
- **Invoice ceiling split (`F05`):** draft create/update compare against `po.grand_total`; posting compares posted-only invoices against the received-goods ceiling (`assertPostedInvoiceFitsReceived`, 422 message now names received value). Drafts no longer block each other at posting.
- **Decimal precision (`F06` partial):** `SupplierService::update` opening/current balance math via `BigDecimal`; `ProcurementService` PR→PO conversion and ASN quantity caps via `BigDecimal` (no `(float)` drift).
- **Delivery race (`F08`):** `executeSupplierDelivery` verification moved inside `DB::transaction` after `lockSupplierThenOrder`; PO items and pending-shipment rows locked (`lockForUpdate`, aggregate replaced by locked-row sum in PHP).
- **Lock ordering (`F09`):** new `lockSupplierThenOrder()` (Supplier → PurchaseOrder) used by update/approve/send/receipt/delivery paths, matching finance service order.
- **Idempotency normalization (`F12`):** blank/whitespace `Idempotency-Key` normalized to `null` in `receivePurchaseOrder` and `createSupplierDelivery`; `PurchaseOrderController::receive` now asserts branch access before service entry (404/403 consistency).
- **Separation of duties (`F14`):** `approvePurchaseRequest` rejects self-approval unless `allow_self_approve_purchases` is enabled; `updatePurchaseOrder` rejects no-op updates instead of rewriting `last_modified_by`.
- **Statement range (`F03` backend):** `from_date`-only statements clamp `to` to `min(today, from + 365d)` instead of failing with an incomprehensible 366-day error; actual period returned in response. `statement()` additionally requests `REPEATABLE READ READ ONLY` only when it owns the outermost transaction (`DB::transactionLevel() === 0`), fixing `SET TRANSACTION` failures inside wrapping transactions; production behavior unchanged.
- **Delivery date window (`F16` backend):** `StoreDeliveryRequest.delivery_date` bounded to `2000-01-01`–`2100-12-31`.
- **Query waste (`F17`, `F18`):** `executePurchaseReceipt` reuses the loaded `User` actor (one lookup per receipt); `updateInvoice` reuses locked `$supplier`; `SendPurchaseOrderSent` skips cancelled orders.
- **Invoice search:** `SupplierInvoiceController::index` accepts `search` (matches `supplier_invoice_no`/`invoice_number`).

#### 3. Frontend Package & Application Changes (Rebuild Required)

- **Supplier dashboard (`F01`):** `SupplierDashboard.last_payments` type now uses flat `invoice_number`; `supplier/page.tsx` renders it (previously always blank).
- **Statement UX (`F03`):** `statement/page.tsx` validates the 366-day cap client-side with a clear message.
- **Timezone (`F03`):** backoffice invoice/payment forms use local-day `localToday()` instead of UTC `toISOString`.
- **Receive modal (`F11`):** optional branch `location_id` selector (via `locationsApi`) included in receive payload and idempotency key.
- **Invoice lookup (`F13`):** PayModule fetches one page with `search` + invoice-number search box instead of 20 sequential `allPages`; InvModule PO dropdown hides `draft`/`cancelled` orders.
- **Payment update (`F02`):** update call sends `{ reference_no, notes }` only.

#### 4. Environment, Queue, Scheduler & Operational Checklist

- **Environment variables:** No new variables or secrets required.
- **Queues/workers:** No new queues. `SendPurchaseOrderSent` (already `afterCommit`) now also skips cancelled orders — safe for delayed workers.
- **Scheduler:** No changes.
- **Build:** Rebuild `@alfarid/api-client`, `@alfarid/backoffice`, `@alfarid/storefront` (`tsc --noEmit` green, suppliers frontend tests 5/5).
- **Deployment commands (staging/production, when authorized):**
  1. `php artisan migrate --force` (migration-owner).
  2. Deploy backend container.
  3. Rebuild/redeploy backoffice + storefront.
  4. Smoke: supplier portal dashboard/statement, backoffice payment edit (reference-only), PO approve-by-other, invoice draft→post after receipt.

#### 5. Known Pre-Existing Issues (Not Introduced Here, Left Untouched)

- ~~`statement()` `SET TRANSACTION ISOLATION LEVEL` fails inside test transactions~~ — **fixed in this branch** (isolation requested only at the outermost transaction level); previously failed identically on the clean tree.
- `TaskFinanceChecklistTest::test_payment_before_statement_start...` asserted strict floats (`-300.0`) against the string-decimal statement contract (`'-300.00'`, matching the `SupplierStatement` TS type and sibling assertions) — assertion corrected to the contract in this branch.
- `PUT /api/v1/admin/supplier-payments/{id}` for legacy treasury-less fixtures returns 410 via `ManufacturingBusiness` middleware in tests — pre-existing on clean tree.
- `tests/Feature/Security/Task8Slice1AuthAndSettingsTest.php` has a duplicated `<?php` tag (parse error when the file is loaded) — pre-existing.
- Full-suite parallel/overlapping runs against the single `alfarid_testing` database cause drop-table deadlocks — environmental; run suites serially.
