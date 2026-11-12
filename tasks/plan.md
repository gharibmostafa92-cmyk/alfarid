# Implementation Plan: task3.txt inventory roadmap

Source: `_archive_unused/tasks_execution/task3.txt` (inventory stocktake/transfers/locks).
No `task32.txt` exists anywhere — no scope expansion.

## Audit verdict (evidence-backed)

| Item | Status | Evidence |
|---|---|---|
| C-1 recount phantom gain | OPEN — implement | `StocktakeService::upsertItems:480-483` refreshes baseline but keeps old `counted_qty` |
| M-1 manual count edit after movements | OPEN — implement | `upsertItems:476-483` refreshes snapshot on `countChanged` with no intervening-movement check |
| H-1 location NULL in location branches | IMPLEMENTED | `receivingLocation:490-513`, `lockLevels:407-419/450-457`, unique index `NULLS NOT DISTINCT`, matrix test |
| H-2 composite indexes | IMPLEMENTED | `2026_10_24_000007`, `2026_10_28_*`, `2026_11_03_000003` migrations |
| H-3 transfersApi broken | DISPROVEN — no change | `BASE` self-consistent, PUT accepted, types mirror resource, no `API_BASE` symbol |
| H-4 stuck in-transit/reserved | IMPLEMENTED | `UpdateBranch:70-76` blocks deactivation with approved/shipped transfers |
| M-2 shortage write-off single-handed | OPEN — implement | `receive:419-428` writes `TransferShortage` with only `transfers.receive`; no separate permission exists |
| M-3 version optional | RULED — HTTP boundary enforces | `Approve/Ship/ReceiveTransferRequest:19` require `expected_version`; unknown-id 409 + updated_at-without-version 409 in service; service null-path kept for internal callers (10+ existing tests call without version) |
| M-4 events in transaction | IMPLEMENTED | Both events implement `ShouldDispatchAfterCommit`; `TaskTxtInventoryEventsTest:191-200` |
| M-5 FOR SHARE/FOR UPDATE hot rows | OPEN (one line) | `ProductionReceiptService:60` uses `lockForUpdate`; roadmap prescribes `for no key update`. Branch-advisory part is observational ("راقب... واعتبر") — skipped by ruling |
| M-6 qty truncation | SAFE — no change | `StoreProductionReceiptRequest:50` enforces `integer`; `TaskTxtInventoryReceiptsTest:184-187` asserts 1.5 → 422 |
| M-7 extra round trips | OPEN (one line) | `recordMovement:72` duplicates `lockLevels:407-419` ownership check; other sub-items skipped by ruling (reordering lock acquisition risks deadlocks; `with()` is the load mechanism) |
| M-8 reconcile trigger 3x writes | IMPLEMENTED | `2026_11_12_000025` splits UPDATE path; `000051` resets `checked_at`; ledger test covers |
| L-1 deadlock→409 mapping | IMPLEMENTED, untested — add test | `bootstrap/app.php:138-146` maps 55P03/40P01/40001; zero tests cover it |
| L-2 scan_id replay mismatch | OPEN — implement | `incrementItemCount:399-413` replays without comparing stored variant/qty |
| L-3 approve/cancel retry errors | PARTIAL — implement missing | ship/receive replay done (`:243`,`:370`); approve replay + cancelled-cancel missing |
| L-4 savepoints per receipt item | OPEN — implement | `ProductionReceiptService:92-101` calls `recordMovement` without prelocked level (`lockLevels` is public, prelocked path skips nested transaction `:201-207`) |
| L-5 movement numbering lock | IMPLEMENTED | `2026_11_12_000060` shared-xact allocation + exclusive-only sync |
| L-6 destination cannot edit/cancel draft | OPEN — implement | `update:131`, `cancel:464`, `delete:512` assert source branch only |
| L-7 test namespace alias | OPEN (mechanical) | `TaskTxtInventoryCase:89` + 2 more test files use legacy alias (runtime works via `class_alias`) |
| L-8 per-item exists | OPEN (mechanical) | `assertReceivableDestination:617-622` loops `exists()`; single `whereIn` keeps semantics |
| L-9 BranchAccess singleton risk | IMPLEMENTED | `AppServiceProvider:82` registers `scoped()` |
| L-10 idempotency_key exposure | IMPLEMENTED | `ProductionReceipt:22` `$hidden` includes both keys |
| INFO LocationController no permission gate | VERIFIED, no prescribed fix — document | No `middleware()`; only `BranchAccess`. Roadmap prescribes no fix → residual risk note |
| INFO idempotency-key length | IMPLEMENTED, untested — add test | `Controller:21-33` + `OperationIdempotencyService:53-55` enforce 1..128; no boundary test |

## Task list (vertical slices, sequential — shared files)

### Phase A — stocktake correctness (C-1/M-1/L-2)
- [ ] T1: L-2 — replay compares stored variant/qty, 409 on mismatch + failing-first test
- [ ] T2: C-1/M-1 — recount zeroes `counted_qty` unless explicit count; count-correction demands explicit recount on intervening movement + update sanctioned tests (`ScanIdempotencyTest:149` 11.0→1.0; check `StocktakeTest:284`, `PricingAuditTest:156`, `SecurityRound3:98`)

### Phase B — transfers (M-2/L-3/L-6/L-8)
- [ ] T3: L-3 — approve replay via `replayCompletedStage('approved_qty')`; cancelled-cancel returns entity + tests
- [ ] T4: M-2 — new `transfers.approve_shortage` permission (seeder → SuperAdmin), service gate on shortage>0, tests (manager 403, superadmin OK, zero-shortage unaffected). Deployment note: assign to roles after deploy
- [ ] T5: L-6 — draft update/delete/cancel allow creator or destination branch + tests
- [ ] T6: L-8 — single `whereIn` in `assertReceivableDestination`; run transfer suites (no behavior change)

### Phase C — receipt hot path (L-4/M-5/M-7)
- [ ] T7: L-4 — batch `lockLevels` once in `ProductionReceiptService::receive`, pass prelocked levels; run receipt + concurrency suites
- [ ] T8: M-5 — variants `lock('for no key update')` in receipt; run concurrency suites
- [ ] T9: M-7 — drop duplicate `Location::exists()` in `recordMovement`; run inventory suites

### Phase D — cross-cutting tests + mechanical
- [ ] T10: L-7 — canonicalize `TreasuryType` import in 3 test files; run affected suites
- [ ] T11: L-1 mapping test (handler render: POST 409 + GET 503 + Retry-After) + Idempotency-Key >128 rejection test

### Checkpoint
- [ ]inventory suites green (`tests/Feature/Admin/Inventory`, Ai inventory tools tests)

### Phase E — docs & deployment tracking
- [ ] T12: deployment doc section (Deployed vs Pending) + audit follow-up note + INFO residuals (LocationController, SoD, AdjustmentIn unit_cost, apply_difference policy)

### Phase F — repomix sync (once)
- [ ] T13: inspect `repomix/` configs vs final tree (note: `repomix.inventory-ledger.json` deleted in working tree — restore or re-point), regenerate `repomix/outputs/started` copies

### Phase G — final verification
- [ ] T14: `php artisan test` full + `pnpm -r typecheck` + `pnpm -r build`; final report

## Rulings
- M-3 service-level mandatory version NOT added: HTTP layer already requires it (3 FormRequests), service 409s cover unknown ids and updated_at-without-version; 10+ committed tests use the service null-path. Cost of change (rewriting committed contract) exceeds residual risk (all in-repo callers pass versions). — recheck if a versionless external caller appears.
- M-7 limited to duplicate-check removal: lock-ordering and timeout-handling rewrites risk deadlocks; per-item insertOrIgnore cost is bounded (≤500 rows, one statement).
- M-5 limited to receipt variant lock: branch advisory-lock swap needs UpdateBranch+lockLevels discipline change; observational per roadmap wording.
- LocationController permission gate NOT added: no prescribed fix, changes API contract; recorded as residual risk for product decision.
- H-3: no code change (claim disproven by file bodies); documented.

## Risks
| Risk | Impact | Mitigation |
|---|---|---|
| C-1/M-1 changes approved variance math | High | Roadmap explicitly orders test updates; run full stocktake suites |
| M-2 new permission blocks legit shortage receives | Med | Grant path documented; zero-shortage path untouched and tested |
| L-4/L-6 touch locked-transaction code | Med | Concurrency suites per slice; one slice at a time |
| Full php artisan test needs pgsql `alfarid_testing` | Med | Probe env first (T0); targeted suites per slice regardless |
