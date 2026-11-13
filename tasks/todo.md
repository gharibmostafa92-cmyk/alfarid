# task7.txt execution — todo list

## Phase A — backend correctness
- [ ] T1 (F01): failing coupon test for untrusted cart token + independent IP/customer cap; implement; run coupon suites
- [ ] T2 (F02): observer test for Product/PriceList mutations; implement; run catalog snapshot tests
- [ ] T3 (F06): trim tests; implement; run product/variant suites
- [ ] T4 (F07): afterCommit placement + always-dispatch tests; implement; run auth/customer suites
- [ ] T5 (F08): normalisation tests; implement; run store auth/checkout suites
- [ ] T6 (F17): route constraint tests; implement; run store route suites
- [ ] T7 (F18): query-count/track tests; implement; run catalog/order suites

## Phase B — frontend correctness
- [ ] T8 (F03): token equality guard; verify logout route
- [ ] T9 (F04): matcher exclusions
- [ ] T10 (F05/F21): media rewrite path + import placement
- [ ] T11 (F12): streaming byte limit
- [ ] T12 (F13): persist expected ids
- [ ] T13 (F14): checkout effect deps
- [ ] T14 (F09): Web Locks fallback
- [ ] T15 (F15): POS undo dedupe
- [ ] T16 (F16): categories/design/popup cleanup
- [ ] T17 (F19): safeStoreHref helper + call sites

## Phase C — media/docs
- [ ] T18 (F11): owner-wide stale staging cleanup + test
- [ ] T19 (F10/F20/F21): residual documentation

## Phase D — tracking
- [ ] T20: task7.txt markers + deployment doc
- [ ] T21: repomix sync once
- [ ] T22: full tests + typecheck + build + final report
