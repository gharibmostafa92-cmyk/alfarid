# Catalog review deployment

1. Install Composer dependencies with the `bcmath` PHP extension enabled. Run the
   catalog, inventory and manufacturing tests against PostgreSQL; SQLite cannot
   verify advisory locks or row lock races.
2. Keep `R2_BUCKET_NAME` private. Configure a different bucket in
   `R2_PUBLIC_BUCKET_NAME`, and set `R2_PUBLIC_URL` to its CDN URL including
   `R2_PUBLIC_KEY_PREFIX`. Never enable public access on the document bucket.
   Catalog originals, including draft images, are public assets; UUID paths are
   not an access-control mechanism.
3. In a maintenance window, run `php artisan migrate --force`. The cost column
   precision migration rewrites `product_variants` under an exclusive lock.
   New lookup/unique indexes use `CONCURRENTLY` outside a migration transaction.
   Resolve existing case-insensitive slug/SKU/barcode duplicates before deploying
   these indexes. If an interrupted concurrent build left an invalid index,
   inspect `pg_index.indisvalid`, drop the invalid index, then rerun the migration.
4. **Required before serving traffic:** run
   `php artisan media:backfill-path-layouts`, then
   `php artisan uploads:migrate-to-r2`. The latter also moves catalog media
   already on the private R2 disk to the public disk. Missing/corrupt files are
   reported while other records continue; a nonzero exit status requires fixing
   the reported files and rerunning. Source files remain by default.
5. After verification, `uploads:migrate-to-r2 --delete-source` can remove source
   files after SHA-256 verification, including on a later run after copying.
   Each migrated row retains a source manifest until cleanup succeeds; retrying
   cleanup never deletes an active destination. Rows migrated by older releases
   without this manifest still need a separately verified source inventory.
   Legacy numeric catalog folders are copied into UUID folders before updating
   their URLs. Use `--delete-source` to remove the old guessable URLs as well.
6. Run `php artisan media-library:regenerate --only=thumb --only=medium` for existing
   category and brand assets. Run a queue worker for conversions queued by the
   configured media library. Product images upload to staging outside a database
   transaction; a short transaction links each completed original. Conversions
   run on the queue after commit. Configure `QUEUE_CONNECTION=database` (or Redis)
   and `MEDIA_QUEUE_CONNECTION` and run workers before accepting uploads.
7. Run `php artisan config:cache` and `php artisan integrations:check`.
   `integrations:check --live` probes the private R2 disk and Gemini; separately
   confirm an actual catalog image and its thumbnail load through the public CDN,
   and private documents cannot be fetched anonymously.
8. Before directing shoppers to the release, warm the homepage with a successful
   `GET /api/v1/store/home`. Cold requests wait at most one second for the builder.
   Facets stay fresh for 60 seconds and serve stale data during background
   refresh for up to 600 seconds. Structural facets are shared; price ranges
   are separated by pricing context and isolate customer-specific lists.
   Apply the location/branch foreign keys, movement sequence lock update and
   catalog trigram migrations before serving traffic. Resolve any historical
   location/branch mismatches before validating the new foreign keys.
9. Apply the `2026_10_28` migrations before serving the updated price endpoints.
   Run `php artisan store:refresh-retail-prices` before opening traffic, then
   restart queue workers. The scheduler refreshes public retail sorting prices
   every five minutes, including time-limited lists. Per-product jobs refresh
   changes after commit. Checkout always uses live pricing; a snapshot older
   than ten minutes falls back to live pricing too.
10. The new occasions constraint rejects objects and unknown array values.
    Correct any reported historical invalid rows and retry migration. The
    independent index repair detects invalid concurrent builds and rebuilds them.

Image retries and image reordering share a product-scoped cache lock. Use the
same shared cache across application nodes; no database row stays locked while
an image streams or is converted. A failed conversion keeps the usable original;
retry the failed conversion job or run `media-library:regenerate` after repair.

Video uploads stream into a hidden `video_staging` collection before a short
transaction swaps the live video. The scheduled `media:prune-orphans` command
also removes expired `images_staging` and `video_staging` media after interrupted uploads.
The scheduler also runs `--staging-only` hourly without listing every R2 folder.
An image retry can recover an abandoned staging row after fifteen minutes.

The image upload API accepts at most four images and 16 million pixels per image.
Configure conversion workers with at least `memory_limit=512M` and size the
worker pool against that memory budget. This is a
deployment setting; the application does not raise server memory limits.
The backoffice sends a persistent UUID per pending file and allows 180 seconds for
the request. Retrying the same owner/UUID returns the existing media row.

Existing migrations already provide `products(category_id)`, `products(brand_id)`
and `product_variants.compare_at_price`; no duplicate schema changes are needed.
The shared controller already rejects idempotency keys longer than 128 bytes;
the global exception handler maps PostgreSQL lock conflicts (including receipt
lock timeouts, SQLSTATE 55P03) to HTTP 409 for writes and HTTP 503 for safe reads,
with Retry-After exposed through CORS.
`stock_levels_valid_balances` already prevents negative availability, so the
existing positive-row stock filter agrees with the aggregate on product cards.

## Follow-up review (F1–F20)

- F1/F3/F4/F5: lookup indexes, media upload uniqueness, four-decimal costs,
  `production_receipts.request_hash`, per-user receipt keys and unique batch
  numbers are already covered by the earlier migrations. Deploy those migrations;
  changing casts alone does not change the database schema.
- Deploy the new `000004`–`000006` migrations before the updated application.
  Product creation uses a per-user UUID plus a request fingerprint; a replay with
  changed input returns 409 and identifies the saved product. Historical rows
  without fingerprints keep their existing replay behavior.
- Product and variant resources expose `lock_version`. The backoffice retains
  it, sends `expected_version`, and saves the returned version immediately after
  each successful write so a later upload failure can be retried. A stale edit
  returns 409 and invokes the existing product reload flow. Timestamp-only edits
  are rejected: clients sending `expected_updated_at` must also send the loaded
  `expected_version`, which is the authoritative concurrency check.
- Category/brand image uploads now commit independently of their metadata and
  run without a tree/metadata row lock. Upload failure leaves the saved metadata
  intact and reports the saved `category_id`/`brand_id` with a specific error
  code. The category form switches to editing that ID before retrying. Archived
  brand clients must use `brand_id` the same way. All catalog image inputs use the shared 16MP
  pixel limit. Missing queued conversions fall back to the original image.
- Wholesale lists, exports and quick-order lookups preload eligible prices in
  one query per batch, then resolve customer/group/channel priorities and tiers
  in memory using the same rules as single-item pricing.
- The existing Laravel scheduler runs `media:prune-orphans` daily at 04:30 in the
  configured business timezone. Ensure the server already runs Laravel's scheduler.
  Preview with `php artisan media:prune-orphans --dry-run`; only unreferenced UUID
  folders on an R2 disk whose newest file is over an hour old are eligible.
  Referenced media (including soft-deleted products), numeric legacy paths and
  recent uploads are retained. Storage errors produce a nonzero exit status.
- Soft deletion intentionally retains catalog files; permanent deletion removes
  media after the database commit. Existing inventory/audit foreign keys can still
  prohibit permanent removal. No public force-delete endpoint has been added.
- Brand operating endpoints remain retired (HTTP 410) by `ManufacturingBusiness`.
  Product input prohibits `brand_id`; archived brand services/data remain for
  historical records. Tests exercise that contract and validate retained services
  directly instead of expecting retired endpoints to become active again.

## Pasted review verification (H1–L10)

- H1/H2/M1 were already covered: FK lookup indexes, the partial unique media
  upload index and four-decimal costs exist in migrations. `last_cost_price`
  already uses four decimals. M4 is handled globally as described above.
- M2: 16MP validation is shared by catalog image inputs; configure worker memory
  as above. L6 also validates filename extensions against the accepted types.
- M3: upload errors identify saved metadata so clients retry updates.
- M5: SKU/barcode search uses an uncorrelated variant-ID subquery. Name substring
  matching is unchanged; the planner still chooses scans according to data size.
- M6: active children/products still prevent soft deletion. Historical deleted
  children/products no longer block it; database restrictions on hard deletion
  remain in force. Restoring products would need to validate their category path.
- M7: store cards and the gallery include active variants' images, the variant
  payload exposes its images, and publication/last-image protection uses the same
  visible-image rule. Inactive/deleted variants' images do not qualify.
- M8: URL generation no longer logs on each missing legacy layout. The required
  layout backfill remains a deployment step.
- L1/L2: creation keys are UUIDs; version numbers replace timestamp-only locking.
- L3/L4: video replacement takes a product row lock. Admin media listing,
  reordering and deletion include media belonging to deleted variants.
- L5/L7: numeric catalog paths are rekeyed during R2 migration; catalog source
  folders are excluded from the private-file pass. Source cleanup is retryable.
- L8/L9: `FILESYSTEM_DISK` is respected, and receipt variant validation uses one
  bulk query while retaining active/non-deleted product checks.
- L10: the demo seeder refuses production and requires an existing staff user;
  it never creates an account with a known password.
