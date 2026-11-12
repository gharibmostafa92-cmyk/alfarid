# task3.txt execution — todo list

## Phase A — stocktake correctness
- [ ] T0: probe test env (`php artisan --version`, one quick inventory test file)
- [ ] T1 (L-2): failing test for mismatched scan_id replay → 409; implement compare; run `StocktakeScanIdempotencyTest`
- [ ] T2 (C-1/M-1): failing tests for recount-zero + correction-demands-recount; implement in `upsertItems`; update sanctioned tests; run stocktake suites

## Phase B — transfers
- [ ] T3 (L-3): failing tests for approve-retry + cancel-retry idempotency; implement; run transfer suites
- [ ] T4 (M-2): failing tests for shortage permission; seeder + service gate; run transfer + seeder-related suites
- [ ] T5 (L-6): failing tests for destination/creator draft access; implement; run transfer suites
- [ ] T6 (L-8): whereIn refactor; run transfer suites

## Phase C — receipt hot path
- [ ] T7 (L-4): prelocked batch in receipt; run receipt + concurrency suites
- [ ] T8 (M-5): for-no-key-update; run concurrency suites
- [ ] T9 (M-7): drop duplicate check; run inventory suites

## Phase D — cross-cutting
- [ ] T10 (L-7): canonicalize imports; run affected suites
- [ ] T11 (L-1 + key-length tests): add tests; run them

## Checkpoint
- [ ] Inventory + AI-inventory suites green

## Phase E/F/G
- [ ] T12 docs + deployment tracking
- [ ] T13 repomix sync (once)
- [ ] T14 final verification + report
