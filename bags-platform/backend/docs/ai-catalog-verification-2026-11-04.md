# AI catalog verification — 4–5 November 2026

Scope: the supplied catalog implementation plan (phases 0–6), H1–H3,
M1–M10, L1–L6, and the accompanying behavioral and concurrency checklist.
Existing unrelated working-tree changes were preserved.

## Corrections from this verification

- Normalize Arabic/Persian digits in nested matrix values and ID lists while
  preserving free-text names and descriptions.
- Apply status, category and sales-channel filters to exact product-code/SKU
  matches as well as name searches.
- Preserve SSE CRLF delimiters when a network chunk splits the CR/LF pair.
  Complete final frames and parser reset behavior remain covered.
- Freeze the test clock before the 14:59/15:01 undo boundary fixtures so
  test execution time cannot accidentally cross the asserted boundary.
- Restore PHP's execution limit and `ignore_user_abort` after an agent turn.
  The agent's per-turn limit must not leak into later CLI/worker work.
- Close confirm/undo races with service-side version checks for status, deletion,
  variant creation and category reordering, rechecked snapshots plus expected versions for undo,
  and expected media order under the service lock. Undo runs in a savepoint
  so a failing later item cannot commit earlier restores. Category moves take
  the exclusive tree lock directly, without upgrading a held shared lock.
- Require a conversation before copywriting calls Gemini, always record its
  usage, and wrap variant color/size/capacity text in bounded `untrusted_text`.
- Restore catalog switches, bulk price limits and step limits in administration;
  display tool usage, confirmation/cancellation rates, 409 rates and high
  cancellation warnings using the existing usage API.
- Restore the authorized employee conversation log as a read-only view.
- Match typed confirmations in the UI using the server's trim/case rules.
- Fit the assistant and composer above the shell's mobile navigation and within
  the available viewport, including visual viewport changes.
- Align product cards with `thumb_url`, `min_retail`/`max_retail`,
  `color_name_ar`, `is_active`, and `readiness.checks` from the backend.
- Display bulk price `old_price`/`new_price`, actual summary totals, and correct
  one-based media order. Category expand/collapse controls now have names.
- Correct obsolete catalog evaluation argument names and status values. A publish
  request expects `check_publishability` first. The fixture now contains 291
  cases, including 62 catalog/safety cases and at least 30 catalog read cases.
- Reject unknown catalog argument names and invalid enums in `ai:evaluate
  --check`. `--toolsets` supports comparison against the full declaration list.
  Valid code-based product references and equivalent relative category orders
  compare semantically; media ID ordering remains significant.

## Evidence and coverage

| Requirement group | Main evidence |
| --- | --- |
| Providers, flags, shared rules, locking and proposals | `AiAgentTest`, `AiCatalogToolsTest`, `AiAuditTaskFixesTest` |
| Card ownership, expiry, tampering, permissions and no unconfirmed writes | `TaskTxtCriticalConfirmTest`, `AiAgentHardeningTest` |
| Atomic bulk price edits, protected cost and undo | `TaskTxtBulkPricingTest`, `TaskTxtCostAndDeleteTest`, `TaskTxtUndoAndCategoriesTest` |
| Idempotency, publishability, hierarchy and concurrent changes | `TaskTxtIdempotencyPublishLockTest`, `Admin/Catalog/CatalogConcurrencyTest`, `TaskTxtSection3GapsTest` |
| Attachments, shared private storage, replay and pruning | `AiCatalogToolsTest`, `AiAuditTaskFixesTest`, `TaskTxtAttachmentsTest`, `TaskTxtVisibilityPruneTest` |
| Token budgets, model errors, steps and runtime limits | `TaskTxtTokenIsolationTest`, `TaskTxtLimitsGeminiAccessTest`, `TaskTxtGeminiFailuresTest`, `AiCatalogVerificationTest` |
| Search, mentions, health and price refresh | `TaskTxtMentionsPerfTest`, `TaskTxtRetailPriceTest`, `TaskTxtComponentsTest` |
| Evaluation fixture contracts and nested digits | `Unit/CatalogEvaluationDatasetTest`, `Unit/CatalogArgsTest` |
| SSE, adapters, errors and frontend logic | `frontend/tests/*.test.cjs` |
| Administration, confirmations, log isolation, responsive composer | `frontend/tests/assistant.e2e.cjs` |
| Catalog API block contracts, price preview, guided actions and uploads | `frontend/tests/assistant-catalog.e2e.cjs` |

Verification logs are under `backend/storage/logs/catalog-verification-*` and
the repository's `test-results/catalog-verification-*`. Browser screenshots are
under `test-results/assistant/`. Tests use PostgreSQL, not SQLite.

`php artisan schedule:list` includes `ai:prune-conversations` at 05:10 in the
business timezone (`Africa/Cairo`). Full-suite and live model results are
recorded in the corresponding logs; a fixture syntax check alone is not a model
accuracy measurement.

The initial full regression run passed **1,955 tests / 26,354 assertions**.
All **133 frontend unit tests** and both assistant browser flows passed.
The final focused regressions passed **43 tests / 258 assertions**, including
the concurrency guards, private catalog storage and legacy reorder query count;
the final broader affected suites passed **865 tests / 7,343 assertions**,
recorded in `catalog-verification-final-related.log`. The undo-window boundary
tests freeze the clock before fixture creation so execution time cannot move
the asserted 14:59 boundary past the 15-minute limit.

The live Gemini benchmark on the isolated local evaluation database measured
**96.8% (60/62)** with the full declaration list and **91.9% (57/62)** with
domain routing. Both clear the 85% minimum, but routing loses more than the
allowed two percentage points. Catalog turns therefore use the full tool list;
`ai:evaluate --toolsets` remains available to test a future optimization. The
temporary evaluation database was removed after the run. One negative barcode
fixture was corrected to expect refusal rather than an invalid write call.

## Deployment requirements

The initial environment used local `public` catalog storage, while its existing
R2 bucket was configured for private uploads. The catalog can explicitly use
that private R2 disk with signed URLs via `MEDIA_CATALOG_DISK=r2`; integration
checks and catalog-only migration now respect that supported mode. Stable public
catalog URLs remain an alternative requiring a separate `R2_PUBLIC_BUCKET_NAME`
and `R2_PUBLIC_URL`. Never make the private document bucket public. AI uploads
use the shared private `ai_private` disk, optionally set with
`AI_PRIVATE_BUCKET_NAME` and `AI_PRIVATE_KEY_PREFIX`.

The existing private bucket `fixitpro` was verified against R2: bucket access
returned HTTP 200, existing objects were found, uploading/downloading a unique
temporary object succeeded, and its signed URL returned the exact contents.
The temporary object was deleted after verification. The missing public catalog
configuration does not mean the existing private bucket is absent or broken.
The seven existing catalog media records were copied to R2 with SHA-256
verification; local originals were retained. `--catalog-only` excludes other
business uploads and orphan files from this migration.
The local environment now selects `MEDIA_CATALOG_DISK=r2`. Missing `thumb` and
`medium` conversions were regenerated on R2. All **7 original URLs and 7
thumbnail URLs** returned successfully. The final `integrations:check --live`
passed both R2 round-trip/signed-URL checks and Gemini text generation.
Gemini vision also correctly identified a synthetic blue image sent through
the real image-enabled client; no customer image was used for that probe.

A separate real-storage workflow also passed on an isolated local database:
attachment proposal/confirmation, duplicate confirmation without duplicate media,
generated thumbnail retrieval, publishability, and typed product publication.
Its synthetic files lived under a unique `_verification/catalog-smoke-*`
prefix, and both that prefix and the temporary database were removed afterward.

After configuring the deployment environment, run `php artisan
integrations:check --live`, verify an actual public catalog image/thumbnail and
private attachment URL, and confirm conversion workers and the scheduler run.
See `catalog-review-deployment.md` for media migration and storage prerequisites.

Use the requested staged release: validate all catalog flags in staging and
require at least 85% model accuracy; enable basic catalog tools in production
first while keeping high-risk tools in `ai_disabled_tools`; release bulk pricing
and product status changes in week two, then vision/copywriting in week three
after reviewing token usage. This review does not change production flags or
deploy the application.

Rotating `APP_KEY` invalidates existing pending-card HMACs: confirmation returns
409 and the user must request a fresh proposal. Do not rewrite existing hashes.
Provider-side work that completes after a client timeout may incur usage the
client cannot record; keep the provider account's budget limits enabled.
