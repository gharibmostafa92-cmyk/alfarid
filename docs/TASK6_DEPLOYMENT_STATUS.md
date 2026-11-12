# task6 — Deployment Status

Source roadmap: `_archive_unused/tasks_execution/task6.txt` (POS terminal + backoffice POS, 19 findings: F1–F19).
Last deployed commit: `25ee590` (see `docs/.last-deployed`)
Date: 2026-11-12
Branch state: uncommitted working tree (also contains unrelated pre-existing changes from other lanes — see §5).

## 1. Already Deployed on Server / Cloud

- Commit `25ee590` — `fix(tests): avoid duplicate generated brand slugs`
- Prior history in `docs/CHANGELOG.md`.
- **No task6 fix is deployed yet.** Everything below is local-only and NOT live.

## 2. Ready / Pending Server Deployment (NOT yet deployed)

Do not treat a green local suite as a live revision. Deploy only after review.

### Backend (code-only unless noted)

- ✅ F2 — damaged returns no longer double-add stock (`PosService::processReturn`: SaleReturn stays on OnHand; damaged leg offsets via contract-legal movement; `previousQuantities` still type-filtered).
  - File: `bags-platform/backend/app/Services/Pos/PosService.php`
  - Deploy notes: code-only. No migration.
- ✅ F14 — shift-totals/close math is BigDecimal-only (no float path into `minor()`/`RoundingMode::Unnecessary`).
  - File: `bags-platform/backend/app/Services/Pos/PosService.php`
  - Deploy notes: code-only.
- ✅ F7 — `recordExpense` validates amount (BigDecimal, positive, 2dp), requires category, authorizes `$userId` vs shift/branch.
  - Files: `PosService.php`, `ExpenseService.php`
  - Deploy notes: code-only.
- ✅ F3-netting — exchange settles return credit against the replacement total; only the NET cash/card difference moves through treasury (`exchange_credit` on orders; tender invariant updated).
  - Files: `PosSaleController.php`, `PosService.php`, `Order.php` (+fillable/cast)
  - Deploy notes: **migrations required** (`000082_pos_task6_backend_fixes` adds `orders.exchange_credit`; `000083` rewrites the tender CHECK to include it via NOT VALID + VALIDATE).
- ✅ F4 — `PosSaleController::store` requires `pos.discount` before idempotency (mirrors `quote()`).
  - Deploy notes: code-only. Cashiers without the permission now get 403 on discounted sales (was: late/actor-mismatched check).
- ✅ F5 — `resumeHeld` locks + deletes atomically in `DB::transaction` (matches its docblock); `createSale` tolerates an already-consumed `held_sale_id` (proceeds, keeps branch/owner checks when the row exists).
  - Deploy notes: code-only.
- ✅ F11 — sale-time customer guards (reject soft-deleted/suspended/rejected customers; reject zero-grand-total sales).
  - Deploy notes: code-only. Pending-wholesale accounts were already priced at retail server-side (unchanged).
- ✅ F13 — null-branch fail-closed (`AuthorizationException`, no `(int) null → 0`) in `PosProductController::lookup`, `PosShiftController::open`, `PosSaleController::held`; `PosShiftAdminController` uses `user('staff')` explicitly and returns 403 (not empty list) on cross-branch filter.
  - Deploy notes: code-only.
- ✅ F9 — `forceCloseShift` requires caller-counted `actual_closing_cash` + records `force_closed_by`; same-supervisor approve rejected (SoD); `settleShiftToMainSafe` idempotent (settled marker) and runs BEFORE status → Approved inside the lock.
  - Files: `PosService.php`, `TreasuryService.php`, `PosShift.php`
  - Deploy notes: **migrations required** (`000082_add_settled_at_to_pos_shifts` backfills `settled_at` for approved shifts; `000082_pos_task6_backend_fixes` adds `force_closed_by` FK + `settled_at` guard). Supervisors must count the drawer on force-close (new required field).
- ✅ F6 — catalog version is O(1) (`COUNT/MAX/SUM(hashtextextended)`, no `string_agg`); skipped on `?cursor=` pages.
  - Deploy notes: code-only.
- ✅ F16 — return lookup tries exact `whereIn` first; LIKE fallback only on miss, branch- + window-scoped.
  - Deploy notes: code-only.
- ✅ F17 — precision migration `000070` is idempotent (`information_schema` guard) with a non-destructive down.
  - Deploy notes: rerun-safe under load.
- ✅ F18 — `pos_held_sales(created_at)` index + ordered prune batches.
  - Deploy notes: **migration required** (index in `000082_pos_task6_backend_fixes`).
- Tests: new `tests/Feature/Pos/Task6BackendFixesTest.php` (21 passed, 138 assertions); neighbors `TaskTxtPosScenariosBTest` (12), `PosReturnTaskTxtBatchDTest` (4), `PosPhase1EndpointsTest` (26), `TaskTxtPosConcurrencyTest` (4) green.

### Frontend (rebuild required)

- ✅ F1 — `apps/pos` ships a PROD fail-fast gate (`services/pos-data-source.ts`: throws at module load in PROD unless `VITE_POS_SOURCE=mock`); empty initial cart; `currentShift: null, isOpen: false`; no fabricated barcode items (lookup + alert + beep instead).
  - Deploy notes: **build + env**: production builds must NOT set `VITE_POS_SOURCE=mock`/`VITE_POS_MOCK=true`. The gate fronts the mock until the real POS HTTP service is wired (one-export swap).
- ✅ F12 — `apps/pos` reprices non-manual lines on customer change (`manualPrice` flag); ScanBar unified with backoffice (`normAr`, `^\d{8,}$` barcode guard, unknown-barcode alert).
- ✅ F15 — `toAsciiNum` hardened (single dot, max 2 decimals) in both apps + `Number.isFinite` guards before sends.
- ✅ F19 — `catch` + server-message surfacing in `ReturnModal`, `OpenShiftGate`, `CheckoutModal`, `CloseShiftModal` (new `lib/pos-error.ts`); no silent `setStage('pay')`.
- ✅ F10 — backoffice `getShiftStats` `?? 0` guards; closing notes stay optional for blind cashiers (422 surfaced); post-close receipt renders only defined fields; `apps/pos` CloseShiftModal hides expected/diff (blind panel).
- ✅ F5-wiring — backoffice `CreateSalePayload.held_sale_id` sent at sale; resume merges with quantity dedupe; `holdSale` persists `unit_price` + spread `discount_amount`.
- ✅ F8 — POS cart-session idempotency key (`crypto.randomUUID`, stable across modal reopen, renewed on clear/hold/success); timeout path warns to verify the last invoice before editing. Backoffice pending-sale manager untouched (already correct).
- ✅ F11-badge — wholesale+Pending shows "جملة (قيد المراجعة — تطبق أسعار التجزئة)" in both CustomerBars.
- ✅ F4-discount — POS discount button gated on `pos.discount` + `max_discount_percent`; cart discount spread pro-rata per line (Largest Remainder, new `lib/money.ts` mirroring backoffice).
- Checks: `tsc --noEmit` clean for `pos` + `backoffice`; `pnpm --filter @alfarid/pos build` OK; `pnpm --filter @alfarid/backoffice build` OK; frontend POS suites 76/76 (pos-phase2 18, pos-phase5 17, pos-phase6-11 11, pos-tasktxt-gaps 12, pos-e2e-lifecycle 3, safe-return-path 15).

### Migrations to run, in order

1. `2026_11_12_000082_add_settled_at_to_pos_shifts` (adds + backfills `settled_at`; `lock_timeout = 3s`)
2. `2026_11_12_000082_pos_task6_backend_fixes` (adds `orders.exchange_credit`, `pos_shifts.force_closed_by` FK, `pos_shifts.settled_at` if missing, `pos_held_sales(created_at)` index)
3. `2026_11_12_000083_pos_tender_includes_exchange_credit` (rewrites tender CHECK via NOT VALID + VALIDATE)
4. `2026_11_12_000070_align_treasury_shift_expense_precision` (re-run safe; no-op when already aligned)

Rollback: reverse order; `000083` down restores the prior CHECK; `000082_pos_task6_backend_fixes` down drops the index/columns (settled_at kept when the sibling migration owns it); `000070` down is guarded. Exchange sales created while `000083` is live carry `exchange_credit > 0` — rolling back past them without settling breaks the old CHECK on re-validate; do not roll back after live exchange traffic.

### Environment / build / ops

- Environment: no new server secrets. `VITE_POS_SOURCE=mock` (or `VITE_POS_MOCK=true`) is DEV-ONLY; ensure production POS builds do not set it (the gate throws otherwise — fail-closed by design).
- Build: rebuild `apps/pos` + backoffice after deploy.
- Queue/scheduler: unchanged (`PruneHeldSales` cadence untouched; only batch ordering + index).
- Behavior changes to announce: discounted POS sales now 403 without `pos.discount`; force-close requires counted cash; zero-total sales rejected; suspended/deleted customers rejected at sale time; blind cashiers are no longer asked for closing notes (supervisor documents variances at review).

## 3. Verification evidence (local, 2026-11-12)

- `php artisan test tests/Feature/Pos/Task6BackendFixesTest.php` → 21 passed (138 assertions) on isolated PG (main PG 5432 down; verified on lane-owned `localhost:5433`).
- Neighbors: `TaskTxtPosScenariosBTest` 12/12, `PosReturnTaskTxtBatchDTest` 4/4, `PosPhase1EndpointsTest` 26/26, `TaskTxtPosConcurrencyTest` 4/4.
- Two test-authoring defects found and fixed during verification (stale Sanctum guard memo on cross-user `withToken`; duplicate `order_number` fixture) — implementation was already correct.
- Frontend: typecheck + builds + 76 tests green (see above; full `pnpm -r` gates in §4).

## 4. Outstanding / next

- Full `php artisan test`, `pnpm -r typecheck`, `pnpm -r build` results: see final report (run at completion).
- `repomix/outputs/started/` bundles regenerated for the task6 file set (see final report).
- Pre-existing unrelated working-tree changes (storefront/catalog/finance/task5 lanes) are NOT task6 scope and NOT covered here.

## 5. Scope boundary (do not deploy-blindly)

The working tree contains extensive unrelated uncommitted changes (storefront, catalog services, finance/AI tools, task5 tests, coupon rate-limiting) from other lanes/sessions. The task6 set is EXACTLY the files listed in §2 plus `tests/Feature/Pos/Task6BackendFixesTest.php` and the three `000082/000083` migrations (+ `000070` edit). Review/deploy only that set for task6.
