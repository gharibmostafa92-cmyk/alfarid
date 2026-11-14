# Task 3 Inventory Roadmap — Deployment Status

Scope: `_archive_unused/tasks_execution/task3.txt` (findings F01–F15). This is separate from the existing `TASK3_DEPLOYMENT_STATUS.md`, which documents the older POS/finance Task 3.

## Deployed vs. Pending Deployment

**Production status: unverified and not deployed.** Work is local and uncommitted. No production database or service was changed.

| Finding | Status | Resolution / evidence |
| --- | --- | --- |
| F01 | ✅ Done | Resolve receiving location before approval; detect collisions before reservation; verify destination while stock rows are locked; persist actual receipt location separately for legacy/mixed lines in `received_location_id`. Added migration `2026_11_14_000002_add_transfer_received_location`. |
| F02 | ✅ Done | Preserve SQL `IS NULL` semantics; capture movement cursors per locked stock key; lock the row before repeated-scan freshness checks; reject write-off drift in `apply_difference`; cancellation cannot mutate scope. |
| F03 | ✅ Done | External branch filters return 403 while valid one-sided transfer filters remain usable. Existing resources hide cost fields without cost permission; focused security coverage passes. |
| F04 | ✅ Done | Remove the all-branches stock-row lock from production receipt WAC calculation; variant lock fences ledger writers and WAC includes in-transit stock. Receipt document creation follows stock locking. |
| F05 | ✅ Done | Recheck branch/location activity under inventory locks; reject inactive destinations; avoid stale eager-loaded location state; unchanged-scope cancellation remains available for legacy inactive stocktakes. |
| F06 | ✅ Done | Remove stale per-scope `BranchAccess` caches and re-read user/branch assignment on each authorization check. |
| F07 | ✅ Verified safe | Keep the generic exception handler: `QueryException` is caught first, so PostgreSQL’s aborted transaction does not reach the `set_config()` recovery statement. Application exceptions still restore the caller’s transaction-local timeout. |
| F08 | ✅ Done | Low-stock event/notification location fields have backward-compatible defaults; location cooldown keys are separate and the legacy branch key is preserved. Branchless users without global access are excluded from global anomaly digests. |
| F09 | ✅ Done | Draft cancel requires update access; approved cancel requires approval access and source-branch access. Replay/delete checks account for legacy morph types; AI cancellation tool advertises both permissions and gates by status. |
| F10 | ✅ Done | Use `BigDecimal` for inventory/transfer quantity comparisons and exact whole-number checks. Preserve the pre-existing idempotency fingerprint encoding for retries created before deployment. |
| F11 | ✅ Verified | Existing daily pruning uses bounded batches and regression coverage; no new storage growth path found. |
| F12 | ✅ Done | Batch donor/target coverage queries, avoid repeat anomaly work, skip unassigned non-central anomaly recipients, and retain cost redaction on production-receipt responses. |
| F13 | ✅ Done | Fix request/list loading, duplicate submissions, quantity retention, scan retry identity, speech-listener cleanup, and production-only diagnostics in POS inventory UI. Add browser-source regression coverage. |
| F14 | ✅ Done | Validate a changed source/destination branch against the other persisted branch and return field-level validation errors. |
| F15 | ✅ Done | `whereNull`/nullable predicates compile to `IS NULL`; the migration validates the composite location/branch foreign keys, so migrated databases cannot retain those orphan references. Reconciliation CTE insertion uses an explicit marker, and `inventory:reconcile --incremental --limit=10` completed successfully against the isolated test database. |

## Verification

- Inventory-focused backend suite: **62/62 tests, 468 assertions passed** after the final authorization changes.
- Additional affected backend regressions: **36/36 tests, 229 assertions passed**; inventory execution and scan regressions: **29/29 passed**; scan idempotency: **6/6 passed**.
- Frontend unit suite: **311/311 passed**.
- `pnpm -r typecheck`: passed across all workspace apps and packages.
- `pnpm -r build`: passed. Existing warnings remain for large backoffice chunks, storefront hook dependencies/manual stylesheet guidance, and unavailable local branding service.
- PHP syntax checks and `git diff --check`: passed for changed inventory paths.
- Repository-wide backend suite completed: **3,163/3,195 passed; 28 failures and 4 errors**. The run surfaced five failures tied to this roadmap (inactive-location error text, cancellation route authorization, cancellation fixture permissions, scan fixture permission, and unpublished product fixture); each was corrected and the affected focused suites passed afterward. The full suite was not rerun after those corrections, so repository-wide green status is not claimed. Other failures/errors were in out-of-scope AI WhatsApp, catalog/media, pricing, shipping, wholesale, POS, and legacy inventory sequence/idempotency areas.

Backend verification ran against the isolated local database `alfarid_codex_testing` so it would not interfere with another active test process using `alfarid_testing`.

## Deployment Notes

1. Back up the production database and rehearse the additive `received_location_id` migration against a production-like copy.
2. Deploy the code and apply migration `2026_11_14_000002_add_transfer_received_location` before enabling the new transfer receipt response field.
3. Restart queue workers so older queued `StockLowDetected` payloads are handled by the backward-compatible event property default.
4. Smoke-test mixed-location and legacy branch-level transfer receipt, stocktake retry/recount, scoped anomaly alerts, and staff permission matrices.

Nothing in this task has been deployed or pushed.
