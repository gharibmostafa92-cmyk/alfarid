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

### Storefront audit pass (2026-11-17 evening)

Not pending deployment. The storefront fixes reported in that pass are not in the working tree, so there is nothing from that pass to build or ship. Live server access was not used.

### Storefront defect roadmap (task4.txt F01–F20, 2026-11-17)

Local only. Live server access was not used. Do not migrate production or restart services until a deploy is explicitly authorized.

Pending actions:

- Deploy the backend storefront changes: cache `Vary` and lock TTLs, checkout shopper resolution, cart create/merge/coupon locking, order-track rate limits, commercial password reset, cart image loading, and account page caps.
- Apply migration `2026_11_17_000002_index_guest_carts_updated_at` (partial guest-cart index; created concurrently on PostgreSQL).
- Restart PHP workers so `carts:prune-guests` uses the 30-day default. The daily 04:15 schedule in `routes/console.php` is unchanged.
- Rebuild and deploy the storefront (and any app that bundles `@alfarid/ui`). This includes the phone normalizer, checkout pending-attempt rules, Cloud Run proxy body limits and `Vary` merging, CSP, cart busy state, and branding poll behavior.
- No new environment variables.
- Selected delivery option is stored only in the pending-checkout fingerprint. `CheckoutPayload` still has no shipping field, so the server continues to choose its own shipping option.

### Storefront audit roadmap (tasks_execution/task.txt, 2026-11-17)

Local only. Live server access was not used. Do not migrate production or restart services until a deploy is explicitly authorized.

Pending actions:

- Deploy the backend: storefront cache privacy (session cookie forces `private, no-store`), `Accept-Language` normalized to `ar` or `en`, guest-cart merge stock cap and single upsert, `cart_merged` return value, per-IP order tracking limit, InstaPay on checkout creation, guest recipient validation, cart coupon locking, wallet reads from the write connection, guest-cart prune re-check, and register phone-proof after validation.
- Rebuild and deploy the storefront. This includes the CSP nonce provider, default API proxy namespaces, forwarded client IP when the proxy chain is short, normalized `Accept-Language`, branding refetch after an SSR fallback, object-storage image paths, queued add-to-cart, and checkout success InstaPay retry.
- No new database migration.
- Optional environment variable `API_PROXY_ALLOWED_NAMESPACES`. When unset, the storefront proxy forwards only `auth`, `store`, `wholesale`, and `supplier`. Set it explicitly if this proxy must also forward `admin`, `pos`, or `webhooks`.
- `TRUSTED_PROXY_HOPS` must match the proxies that always append `X-Forwarded-For`. Restrict Cloud Run ingress to the load balancer so clients cannot skip a hop.
- Restart PHP workers and the storefront service. No queue or scheduler name change. `carts:prune-guests` keeps its existing schedule and now deletes in batches of 500 with the cutoff repeated on the delete.
- SMS subnet budgets were not raised. The evening follow-up below stops the registered-phone 422 on verification send.

### Storefront audit follow-up (tasks_execution/task.txt, 2026-11-17 evening)

Local only. Live server access was not used. Do not migrate production or restart services until a deploy is explicitly authorized.

Pending actions from this slice:

- Deploy the backend. Public catalog responses now include `Cookie` in `Vary`. Branding no longer sets its own cache headers. Homepage banner cache stores ids, not Eloquent models.
- Checkout no longer inserts an empty cart when no cart session exists. Idempotent replays still resolve before that check.
- Invalid recipient phones are dropped instead of being stored as `-`.
- Suspended customer login returns `هذا الحساب موقوف. يرجى التواصل مع خدمة العملاء.` and does not include `suspension_reason`.
- Phone verification send returns the same success message for registered numbers and does not queue an SMS for them.
- Product updates strip `cost_price` when the staff user cannot view cost. Catalog and coupon form requests authorize only staff `User` models.
- A superseded waybill ignores later progress updates. A late delivered event sets `needs_attention` and does not mark the old shipment delivered.
- Variant delete is blocked by active orders and open returns. Wholesale purchasable variants use `activeHierarchy`. Cart writes take a shared lock on the variant and drop lines whose variant was deleted. Guest-cart merge keeps lines that do not fit the account line cap.
- Shipping quotes choose the cheapest active carrier rate. Shipment `shipping_cost` is the carrier rate, not zeroed by a free-shipping coupon. `orders.grand_total` remains the COD amount due.
- Webhook dedupe keys that exceed 191 characters keep a prefix plus a SHA-256 of the full key.
- No new environment variables. No new migration in this slice. Restart PHP workers after deploy. No scheduler change.
- The rest of the roadmap (decimal migrations, facet cache, promotion cache invalidation, coupon phone width, staging media, observer coalescing, order-track IP limiter, wallet read path) is still pending in code.
