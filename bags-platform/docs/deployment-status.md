# Deployed vs. Pending Deployment

Live server access was not available in this session. Nothing below is claimed to be running in production.

## A. Already Deployed on Server

Unverified. No production host, release id, applied migration list, queue worker, or scheduler state was inspected.

## B. Ready / Pending Server Deployment

Local code only. Deploy the backend and the wholesale portal frontend together. Do not run production migrations or restart services until that deploy is explicitly authorized.

Pending actions:

- Deploy the current backend, including wholesale settlement, pricing-catalog version bumps, receipt and bank-statement parsing, PDF name-token scanning, and registration rollback that deletes only the uploaded object keys.
- Deploy the wholesale portal frontend: Cairo calendar dates, bounded pager, cart request ordering, quotation acceptance copy, and barcode add-to-cart.
- No new environment variables were added.
- No new database migration was added in this slice. Apply any migrations already present in the tree and not yet applied on the server before starting the new code. Confirm that list on the server; it was not verified here.
- Rebuild frontend assets for the storefront/backoffice apps that bundle `@alfarid/ui`.
- Restart PHP workers (FPM or Octane) and queue workers so they pick up the pricing catalog version key and the payment-notice lock order. The shared cache key is `pricing:catalog_version`.
- No scheduler or queue-name change was introduced.

### Storefront defect roadmap (task4.txt F01–F20, 2026-11-17)

Local only. Live server access was not used. Do not migrate production or restart services until a deploy is explicitly authorized.

Pending actions:

- Deploy the backend storefront changes: cache `Vary` and lock TTLs, checkout shopper resolution, cart create/merge/coupon locking, order-track rate limits, commercial password reset, cart image loading, and account page caps.
- Apply migration `2026_11_17_000002_index_guest_carts_updated_at` (partial guest-cart index; created concurrently on PostgreSQL).
- Restart PHP workers so `carts:prune-guests` uses the 30-day default. The daily 04:15 schedule in `routes/console.php` is unchanged.
- Rebuild and deploy the storefront (and any app that bundles `@alfarid/ui`). This includes the phone normalizer, checkout pending-attempt rules, Cloud Run proxy body limits and `Vary` merging, CSP, cart busy state, and branding poll behavior.
- No new environment variables.
- Selected delivery option is stored only in the pending-checkout fingerprint. `CheckoutPayload` still has no shipping field, so the server continues to choose its own shipping option.
