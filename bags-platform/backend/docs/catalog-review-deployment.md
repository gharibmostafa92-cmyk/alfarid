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
   files during the move after SHA-256 verification. An earlier copy-only run has
   already updated its media rows, so remove retained old R2 copies separately
   using a verified inventory; they are deliberately not deleted on a later run.
6. Run `php artisan media-library:regenerate --only=thumb --only=medium` for existing
   category and brand assets. Run a queue worker for conversions queued by the
   configured media library. Newly uploaded product images render synchronously
   per file, without holding a product row lock.
7. Run `php artisan config:cache` and `php artisan integrations:check`.
   `integrations:check --live` probes the private R2 disk and Gemini; separately
   confirm an actual catalog image and its thumbnail load through the public CDN,
   and private documents cannot be fetched anonymously.

The image upload API accepts at most four images and 25 million pixels per image.
The backoffice sends a persistent UUID per pending file and allows 180 seconds for
the request. Retrying the same owner/UUID returns the existing media row.

Existing migrations already provide `products(category_id)`, `products(brand_id)`
and `product_variants.compare_at_price`; no duplicate schema changes are needed.
The shared controller already rejects idempotency keys longer than 128 bytes and
maps PostgreSQL lock conflicts to HTTP 409.
`stock_levels_valid_balances` already prevents negative availability, so the
existing positive-row stock filter agrees with the aggregate on product cards.
