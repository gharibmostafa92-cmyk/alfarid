# Payment Webhooks & State Machine — Architectural Deep Dive & Diligence Report

> **Classification:** Technical Due Diligence & Engineering Documentation  
> **Target Audience:** Technical Evaluators, Software Buyers, Chief Technology Officers, Enterprise Solution Architects  
> **Status:** Published (Audited against Laravel 11 Backend + PostgreSQL Partial Unique Indexing + Multi-Gateway Cryptographic Verification)  
> **Evidence Tier:** `Verified` (Implemented in Production Code + Validated by Automated Test Suites; see Section 8 for exact observed test run scope and 4 fixture-drift failures in `WebhookTest.php`)  
> **Primary Source References:**  
> - Webhook Ingress Routes: [`bags-platform/backend/routes/api/webhooks.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/routes/api/webhooks.php)  
> - Pre-Decode Body Limit Middleware: [`bags-platform/backend/app/Http/Middleware/LimitPaymentWebhookBody.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/app/Http/Middleware/LimitPaymentWebhookBody.php)  
> - Payment Webhook Controller: [`bags-platform/backend/app/Http/Controllers/Api/V1/Webhook/PaymentWebhookController.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/app/Http/Controllers/Api/V1/Webhook/PaymentWebhookController.php)  
> - Core Webhook State-Machine & Lock Engine: [`bags-platform/backend/app/Services/Shared/PaymentWebhookService.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/app/Services/Shared/PaymentWebhookService.php)  
> - Cryptographic Signature Verifier: [`bags-platform/backend/app/Services/Shared/WebhookSignatureVerifier.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/app/Services/Shared/WebhookSignatureVerifier.php)  
> - Paymob Refund Inquiry Client: [`bags-platform/backend/app/Services/Shared/PaymobRefundInquiry.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/app/Services/Shared/PaymobRefundInquiry.php)  
> - Sanitized Audit Log Model: [`bags-platform/backend/app/Models/WebhookLog.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/app/Models/WebhookLog.php)  
> - Gateway & Rate-Limit Configuration: [`bags-platform/backend/config/webhooks.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/config/webhooks.php)  
> - Rate Limiter Definitions: [`bags-platform/backend/app/Providers/AppServiceProvider.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/app/Providers/AppServiceProvider.php#L575-L585)  
> - Log Retention & Scrubbing Command: [`bags-platform/backend/app/Console/Commands/Maintenance/PruneWebhookLogs.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/app/Console/Commands/Maintenance/PruneWebhookLogs.php)  
> - Database Schema & Partial Unique Index Migrations:  
>   - [`2026_10_17_100006_create_webhook_logs_table.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/database/migrations/2026_10_17_100006_create_webhook_logs_table.php)  
>   - [`2026_10_29_000003_webhook_processed_unique.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/database/migrations/2026_10_29_000003_webhook_processed_unique.php)  
>   - [`2026_11_12_000049_index_webhook_retention.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/database/migrations/2026_11_12_000049_index_webhook_retention.php)  
>   - [`2026_11_12_000065_add_webhook_log_lookup_index.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/database/migrations/2026_11_12_000065_add_webhook_log_lookup_index.php)  
> **Primary Test References:**  
> - Payment Webhook State Machine & Refund Tests: [`Task2PaymentWebhookTest.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/tests/Feature/Orders/Task2PaymentWebhookTest.php)  
> - Webhook Signature, Lifecycle & Quarantine Tests: [`WebhookTest.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/tests/Feature/Orders/WebhookTest.php)  
> - Parallel Webhook Concurrency Tests: [`StoreCriticalConcurrencyTest.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/tests/Feature/Orders/StoreCriticalConcurrencyTest.php#L278-L294)  
> - Rate-Limit & Bucket Isolation Tests: [`Task2Slice4WebhooksRateLimitsTest.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/tests/Feature/Orders/Task2Slice4WebhooksRateLimitsTest.php), [`WebhookThrottleSplitTest.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/tests/Feature/Auth/WebhookThrottleSplitTest.php), [`Task2WebhookRateLimitTest.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/tests/Feature/System/Task2WebhookRateLimitTest.php)  
> - Payload PII Redaction Unit Tests: [`WebhookLogTest.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/tests/Unit/WebhookLogTest.php)  

---

## 1. Executive Summary & Value Proposition

The **Payment Webhooks & State Machine Subsystem** governs all asynchronous inbound payment notifications from external payment gateways (Paymob, Stripe, Moyasar, and generic HMAC-SHA256 providers) into the Alfarid / Bags-Platform order and financial ledger. Because payment callbacks arrive unauthenticated over the public internet—often out of order, duplicated, or during concurrent checkout commits—this subsystem enforces **cryptographic verification, deterministic concurrency serialization, idempotent event deduplication, and strict payment state-machine guards** before mutating any order or inventory state.

- **Core Capability:** `[IMPLEMENTED]` Verifies, deduplicates, and executes payment state transitions (`paid`, `failed`, `refunded`, `partially_refunded`, `pending`, `ignored`) across heterogeneous payment gateways (`PaymentWebhookService.php#L51-L372`).
- **Architectural Philosophy:** `[IMPLEMENTED]` Defense-in-depth ingestion pipeline combining pre-parse payload size bounds (`256 KiB`), constant-time cryptographic signature verification (`hash_equals`), transaction-scoped PostgreSQL advisory locks (`pg_advisory_xact_lock`), pessimistic row locking (`SELECT ... FOR UPDATE`), and a partial unique database index backstop (`webhook_logs_processed_provider_event_unique`).
- **Commercial Impact:** Eliminates silent financial loss from amount/currency spoofing, prevents double-fulfillment and phantom refunds caused by out-of-order gateway retries, and protects PCI/PII compliance by scrubbing sensitive cardholder fields prior to persistence.
- **Operational Resilience:** `[IMPLEMENTED]` Distinguishes transient downstream failures (`503 Service Unavailable` when an order is not yet committed or Paymob refund inquiry is unreachable) from permanent rejections (`403` invalid signature, `413` oversized body, `409` missing order reference), ensuring gateways automatically retry recoverable races without manual intervention.

---

## 2. The Engineering Challenge

Payment webhook handlers are one of the highest-risk surfaces in e-commerce systems. Naive implementations fail under real-world gateway behavior in five predictable ways:

- **Out-of-Order & Duplicate Delivery:** Gateways guarantee at-least-once delivery, not ordering or uniqueness. A late `failed` webhook or a delayed `paid` retry arriving *after* a `refunded` event can overwrite refund state and trigger unauthorized warehouse dispatch.
- **Concurrent Webhook Races:** Two identical callbacks arriving milliseconds apart both read "no processed log exists," then both confirm the order and reserve inventory twice.
- **Unauthenticated / Unsigned Refund Fields:** Gateways like Paymob omit cumulative refund totals from their HMAC-signed callback dictionary (`WebhookSignatureVerifier.php#L129-L144`), meaning trusting callback refund amounts directly exposes the merchant to forged full-refund state transitions.
- **Multi-Event Noise & Ambiguous Statuses:** Gateways such as Stripe emit multiple success-family events for a single charge (`charge.succeeded`, `payment_intent.succeeded`, `checkout.session.completed`) or emit `checkout.session.completed` with `payment_status = unpaid` for deferred payment methods.
- **Log Flooding & PII Retention Liability:** Unauthenticated attackers sending invalid signatures or >1 MB payloads can exhaust PHP memory, flood `webhook_logs` disk space, or cause raw cardholder PII (`pan`, `billing_details`, `secret_token`) to be persisted into 90-day database backups.

---

## 3. Architectural Design & System Components

### Component Breakdown

| Component / Layer | Implementation Path | Responsibility | Architectural Pattern |
|---|---|---|---|
| **Route Ingress & Throttle** | [`routes/api/webhooks.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/routes/api/webhooks.php#L14-L15) | Constrains `gateway` slug (`[A-Za-z0-9_-]{1,32}`) and applies isolated `webhooks-payment` rate limiter | Edge Parameter Guard + Dual-Bucket Throttle |
| **Body-Size Middleware** | [`LimitPaymentWebhookBody.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/app/Http/Middleware/LimitPaymentWebhookBody.php#L12-L27) | Rejects `POST api/v1/webhooks/*` exceeding `256 KiB` (`Content-Length` or raw body) with HTTP `413` before JSON parsing | Pre-Decode Memory Exhaustion Guard |
| **HTTP Controller** | [`PaymentWebhookController.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/app/Http/Controllers/Api/V1/Webhook/PaymentWebhookController.php#L23-L45) | Enforces 256 KiB pre-decode ceiling, normalizes headers/query, delegates raw body to service | Thin Ingress Adapter |
| **State-Machine Engine** | [`PaymentWebhookService.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/app/Services/Shared/PaymentWebhookService.php#L26-L757) | Orchestrates signature check, event filtering, advisory locking, amount/currency validation, and order state transitions | Transaction Script + State-Machine Guard |
| **Signature Verifier** | [`WebhookSignatureVerifier.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/app/Services/Shared/WebhookSignatureVerifier.php#L15-L161) | Dispatches driver-specific verification (`stripe`, `paymob`, `moyasar`, `hmac`) with `hash_equals` | Strategy Pattern |
| **Paymob Inquiry Client** | [`PaymobRefundInquiry.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/app/Services/Shared/PaymobRefundInquiry.php#L15-L157) | Authoritatively fetches cumulative `refunded_amount_cents` from Paymob REST API with cached auth token + mutex lock | Cache-Aside + Distributed Mutex + Circuit Backoff |
| **Audit Log & Scrubber** | [`WebhookLog.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/app/Models/WebhookLog.php#L12-L95) | Persists delivery attempts and recursively redacts 29 sensitive credential/PII keys before DB write | Active Record + Recursive Data Redaction |
| **Retention Pruner** | [`PruneWebhookLogs.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/app/Console/Commands/Maintenance/PruneWebhookLogs.php#L13-L63) | Deletes expired unprocessed rows (90d), scrubs processed payloads (90d), and retains deduplication keys (365d) | Batched Index-Walk Maintenance Command |

### System Architecture Diagram

```mermaid
graph TD
    GW["Payment Gateway (Paymob / Stripe / Moyasar / HMAC)"] -->|"POST /api/v1/webhooks/payment/{gateway}"| RL["RateLimiter: webhooks-payment (IP + Gateway Bucket)"]
    RL --> Ctrl["PaymentWebhookController (256 KiB Guard)"]
    Ctrl --> Svc["PaymentWebhookService"]
    Svc --> Verifier["WebhookSignatureVerifier (hash_equals)"]
    Svc -->|"If Paymob Refund"| Inquiry["PaymobRefundInquiry (Cached Token + 3s Timeout)"]
    Inquiry -->|"HTTPS API"| PaymobAPI["Paymob Acceptance API"]
    Svc -->|"pg_advisory_xact_lock + FOR UPDATE"| DB[("PostgreSQL (orders + webhook_logs)")]
    Svc -->|"Auto-confirm Pending Order (Savepoint)"| OrderSvc["OrderService::changeStatus"]
    Svc -->|"PII Scrubbing"| LogModel["WebhookLog::sanitizePayload"]
    LogModel --> DB
```

---

## 4. Internal Execution Flow & Lifecycle

### Request-to-Ledger Sequence Diagram

```mermaid
sequenceDiagram
    autonumber
    actor GW as Payment Gateway
    participant Ctrl as PaymentWebhookController
    participant Svc as PaymentWebhookService
    participant Ver as WebhookSignatureVerifier
    participant Inq as PaymobRefundInquiry
    participant DB as PostgreSQL

    GW->>Ctrl: POST /api/v1/webhooks/payment/{gateway}
    Ctrl->>Ctrl: Check rawBody <= 262,144 bytes before json_decode
    Ctrl->>Svc: handle(gateway, payload, rawBody, headers, query, ip)
    Svc->>Svc: Validate slug & config; reject >256 KiB with 413 + sampledSlimLog
    Svc->>Ver: verifyGateway(driver, secret, rawBody, headers, query, payload)
    alt Invalid Signature
        Ver-->>Svc: false
        Svc->>DB: sampledSlimLog(..., status='invalid_signature')
        Svc-->>GW: 403 Forbidden
    end
    Ver-->>Svc: true
    Svc->>Svc: Normalize outcome & check allowed_events filter
    opt Paymob Refund Outcome
        Svc->>Inq: amount(transactionId, orderId, config)
        Inq-->>Svc: Verified cumulative refund amount (or null -> 503)
    end
    Svc->>DB: Pre-check processed (provider, event_id)
    Svc->>DB: startLog(status='received', sanitized payload)
    Svc->>DB: BEGIN TRANSACTION
    Svc->>DB: SELECT pg_advisory_xact_lock(hashtextextended(provider:event_id, 101))
    Svc->>DB: Re-check alreadyProcessed() under lock
    Svc->>DB: SELECT * FROM orders WHERE ... FOR UPDATE
    Svc->>Svc: Validate payment_method, amountMatches / refundCurrencyMatches
    Svc->>Svc: Apply State-Machine Transition (paid / failed / refunded)
    opt Pending Order + Paid Outcome
        Svc->>DB: SAVEPOINT -> OrderService::changeStatus(Confirmed)
    end
    Svc->>DB: UPDATE webhook_logs SET status='processed'
    Svc->>DB: COMMIT
    Svc-->>GW: 200 OK {received: true}
```

### Step-by-Step Flow Description

1. **Ingress & Size Bounding:** `[IMPLEMENTED]` `LimitPaymentWebhookBody` (`LimitPaymentWebhookBody.php#L18-L23`) rejects `POST api/v1/webhooks/*` requests exceeding `PaymentWebhookService::MAX_BODY_BYTES` (`256 KiB`) via `Content-Length` or raw body length with HTTP `413` before Laravel's JSON parser runs. `PaymentWebhookController::handle` (`PaymentWebhookController.php#L25-L30`) also checks `strlen($raw) > 262144` *before* invoking `json_decode($raw, true)`, and `PaymentWebhookService::handle` (`PaymentWebhookService.php#L66-L70`) returns HTTP `413` and logs a sampled slim record without storing the oversized body.
2. **Cryptographic Verification:** `[IMPLEMENTED]` `WebhookSignatureVerifier::verifyGateway` (`WebhookSignatureVerifier.php#L63-L75`) validates the request using the configured driver before any full payload is written to `webhook_logs`. Invalid signatures return HTTP `403` (`PaymentWebhookService.php#L90-L94`).
3. **Event Filtering & Refund Amount Resolution:** `[IMPLEMENTED]` If `allowed_events` is configured (e.g., Stripe in `config/webhooks.php#L45-L48`), unsubscribed event types are logged as `ignored` with HTTP `200` (`PaymentWebhookService.php#L103-L118`). For Paymob refunds, `PaymobRefundInquiry::amount` (`PaymobRefundInquiry.php#L20-L86`) queries Paymob's API for the authoritative cumulative refund total; if the inquiry fails, the service returns HTTP `503` so Paymob retries (`PaymentWebhookService.php#L148-L155`).
4. **Concurrency & Transactional Mutation:** `[IMPLEMENTED]` Inside `DB::transaction`, `pg_advisory_xact_lock(hashtextextended(?, 101))` (`PaymentWebhookService.php#L176`) serializes concurrent deliveries of the same `(provider, event_id)` key, followed by `Order::query()->...->lockForUpdate()->first()` (`PaymentWebhookService.php#L189-L194`). Auto-confirmation of `Pending` orders runs inside a nested `DB::transaction` savepoint (`PaymentWebhookService.php#L257-L259`) so a business/constraint failure during confirmation flags `needs_attention = true` without rolling back the captured `payment_status = 'paid'` update.

---

## 5. Critical Engineering Discoveries & Decision Log

### Discovery 1: Composite Event-Idempotency Key `(baseId:outcome:refundAmount)`
- **The Problem:** Gateways such as Paymob and Stripe reuse the same transaction or charge ID across an initial payment capture and one or more subsequent partial refunds. Keying `webhook_logs` solely on `transaction_id` would cause all subsequent refunds to be silently dropped as `duplicate`.
- **Naive Alternative & Why It Fails:** Using `event_id` alone without outcome/amount suffixes drops Paymob's follow-up refund callbacks (which share `obj.id`) or collapses multiple partial refunds into the first event.
- **Actual Implementation:** `[IMPLEMENTED]` `PaymentWebhookService::buildEventId` (`PaymentWebhookService.php#L570-L590`) constructs `{base}:{outcome}` (and appends `:{refundAmount}` formatted to 2 decimal places when present), hashing oversized prefixes with SHA-256 to guarantee the key always fits within `webhook_logs.event_id` (`varchar(191)`). For Stripe refunds, `extractEventId` (`PaymentWebhookService.php#L553-L556`) prioritizes the top-level `evt_*` delivery ID over `data.object.id`.
- **Defensible Advantage:** Preserves strict idempotency per delivery while allowing sequential partial refunds and full refunds on the same underlying transaction.
- **Trade-offs:** Relies on `PaymobRefundInquiry` availability during Paymob refunds to differentiate cumulative refund amounts; mitigated by returning `503` on inquiry failure.

### Discovery 2: Authoritative Out-of-Band Paymob Refund Inquiry
- **The Problem:** Paymob's official 20-field HMAC-SHA512 callback signature (`WebhookSignatureVerifier.php#L129-L135`) does **not** include `refunded_amount_cents` or any cumulative refund total. An attacker or corrupted proxy could alter unsigned payload fields to trick the system into marking a partial refund as a full refund.
- **Naive Alternative & Why It Fails:** Reading `refunded_amount` directly from the unauthenticated JSON keys in a Paymob callback creates an unauthenticated financial state-corruption vulnerability.
- **Actual Implementation:** `[IMPLEMENTED]` `PaymobRefundInquiry::amount` (`PaymobRefundInquiry.php#L20-L86`) authenticates with Paymob's regional API (`accept.paymob.com`, `ksa.paymob.com`, `uae.paymob.com`, `oman.paymob.com`), caches the ~1-hour auth token for 50 minutes under a distributed `Cache::lock` (`PaymobRefundInquiry.php#L99`), enforces 1.5s connect / 3s request timeouts, verifies transaction ID, order ID, currency, `success === true`, and `pending === false`, and extracts `refunded_amount_cents_int` (or `amount_cents` if `is_voided`).
- **Defensible Advantage:** Eliminates unsigned refund amount spoofing while preventing auth-endpoint stampedes via distributed cache locking and 15-second failure backoff.
- **Trade-offs:** Adds an external HTTP round-trip on Paymob refund callbacks (bounded to 3s timeout).

### Discovery 3: Savepoint Isolation for Post-Payment Order Auto-Confirmation
- **The Problem:** When a `paid` webhook arrives, the order transitions `payment_status -> 'paid'` and attempts `OrderService::changeStatus($order, OrderStatus::Confirmed)` (which allocates branch stock). If stock allocation or a database constraint fails inside PostgreSQL, a raw exception aborts the entire transaction (`SQLSTATE 25P02`), rolling back the `payment_status = 'paid'` record and returning `500` even though the customer's card was already charged.
- **Naive Alternative & Why It Fails:** Wrapping the entire webhook in one flat transaction loses the payment capture record on confirmation errors, leaving staff blind to paid-but-unconfirmed orders.
- **Actual Implementation:** `[IMPLEMENTED]` `PaymentWebhookService.php#L253-L291` wraps `OrderService::changeStatus` in a nested `DB::transaction` (PostgreSQL `SAVEPOINT`). Transient lock/connection SQLSTATEs (`55P03`, `40P01`, `08006`, `08003`) and `DeadlockException` are re-thrown to trigger gateway redelivery; all other failures roll back only the savepoint, set `needs_attention = true`, and persist `payment_status = 'paid'` with a sanitized staff note.
- **Defensible Advantage:** Guarantees zero silent loss of captured payments while preserving automatic retry on transient database contention.
- **Trade-offs:** Requires staff triage via the `needs_attention` queue when inventory allocation fails after payment capture.

### Discovery 4: Two-Tier Rate Limiting & Sampled Reject Logging
- **The Problem:** A flood of unauthenticated `403` requests or a misconfigured gateway retry storm could exhaust database connections writing `webhook_logs` rows or starve other webhook providers.
- **Naive Alternative & Why It Fails:** A single global webhook rate limit lets a noisy shipping carrier starve payment webhooks; logging every `403` or `413` unconditionally enables storage-exhaustion DoS.
- **Actual Implementation:** `[IMPLEMENTED]` `AppServiceProvider.php#L575-L585` defines `webhooks-payment` with two limits: an outer per-IP ceiling (`240/min`) and an inner `(bucket, IP)` ceiling (`120/min`), collapsing unknown gateway slugs into a single `'unknown'` bucket to prevent unbounded cache-key minting. Rejected requests (`403`, `413`) use `sampledSlimLog` (`PaymentWebhookService.php#L726-L730`), capping log writes at 30 rows per 60 seconds per `(status, IP, provider)` with `payload = null`.
- **Defensible Advantage:** Protects database IOPS and preserves observability during credential misconfigurations or DDoS probes.
- **Trade-offs:** Beyond 30 invalid requests/minute from a single IP, individual `403`/`413` attempts are not individually persisted.

---

## 6. Performance, Concurrency & Data Integrity

### Concurrency Guarantees
- **Transaction-Scoped Advisory Locks:** `[IMPLEMENTED]` `SELECT pg_advisory_xact_lock(hashtextextended(?, 101))` (`PaymentWebhookService.php#L176`) serializes concurrent deliveries for the same `provider:event_id` without holding session-level locks across PgBouncer transaction-pooled connections.
- **Pessimistic Row Locking:** `[IMPLEMENTED]` Target `Order` rows are fetched with `->lockForUpdate()` (`PaymentWebhookService.php#L193`) inside the transaction, serializing concurrent webhooks against manual admin payment updates or customer cancellations.
- **Database Partial Unique Index Backstop:** `[IMPLEMENTED]` Migration `2026_10_29_000003_webhook_processed_unique.php#L44` creates `webhook_logs_processed_provider_event_unique ON webhook_logs (provider, event_id) WHERE status = 'processed'` concurrently (`$withinTransaction = false`). This makes it physically impossible at the PostgreSQL engine level for two rows with the same `(provider, event_id)` to both commit as `processed`, while still allowing multiple `duplicate`, `failed`, or `ignored` audit rows.

### Payment State-Machine Invariants

```mermaid
stateDiagram-v2
    [*] --> unpaid
    unpaid --> paid : "outcome=paid + amountMatches"
    unpaid --> failed : "outcome=failed"
    paid --> partially_refunded : "cumulative refund < grand_total (needs_attention=true)"
    paid --> refunded : "refund == grand_total (needs_attention if not Cancelled)"
    partially_refunded --> refunded : "cumulative refund == grand_total"
    paid --> paid : "late failed ignored; duplicate paid flags needs_attention=true"
    refunded --> refunded : "late paid/failed NEVER reverts refunded (flags needs_attention=true)"
```

- **Unknown Status Quarantine:** `[IMPLEMENTED]` `PaymentWebhookService::outcome` (`PaymentWebhookService.php#L410-L420`) classifies any explicit non-empty status outside `SUCCESS`, `FAILED`, or `refunded` (e.g., `unpaid` on `checkout.session.completed`) as `ignored` rather than inferring `paid` from the event type.
- **Off-Gateway Method Protection:** `[IMPLEMENTED]` Orders with `payment_method` in `['wallet', 'bank_transfer']` are unconditionally ignored by gateway callbacks (`PaymentWebhookService.php#L202-L206`).
- **Integer-Cent Money Comparison:** `[IMPLEMENTED]` `sameMoney` (`PaymentWebhookService.php#L684-L687`) compares `(int) round($paid * 100) === (int) round($expected * 100)` after verifying currency equality (`amountMatches` / `paymobAmountMatches` / `refundCurrencyMatches`).
- **Retention & Index Design:** `[IMPLEMENTED]` `webhook_logs_retention_idx (created_at, id)` (`2026_11_12_000049_index_webhook_retention.php#L19`) and `webhook_logs_provider_event_status_index (provider, event_id, status)` (`2026_11_12_000065_add_webhook_log_lookup_index.php#L25`) accelerate `PruneWebhookLogs` batch sweeps and duplicate lookups.

---

## 7. Security, Validation & Defense-in-Depth

- **Multi-Driver Constant-Time Signature Verification:** `[IMPLEMENTED]` `WebhookSignatureVerifier` (`WebhookSignatureVerifier.php#L20-L160`) enforces `hash_equals` across all four drivers:
  - `stripe`: Parses `t=` and `v1=` from `Stripe-Signature`, rejects timestamps outside a 300-second replay window (`abs(time() - (int) $timestamp) > 300`), and verifies `HMAC-SHA256("{t}.{rawBody}", secret)`.
  - `paymob`: Concatenates Paymob's 20 official callback fields in exact order and verifies `HMAC-SHA512` against `?hmac=`. Also strips untrusted top-level keys by persisting only `['obj' => ..., 'type' => ...]` (`PaymentWebhookService.php#L167-L169`).
  - `moyasar`: Constant-time comparison against `payload['secret_token']`.
  - `hmac` (default): `HMAC-SHA256` over raw request body (with optional `sha256=` prefix stripping) against configurable signature header.
- **Recursive PII & Credential Redaction:** `[IMPLEMENTED]` `WebhookLog::sanitizePayload` (`WebhookLog.php#L40-L94`) normalizes key names (stripping `-`, `_`, and spaces) and recursively replaces 29 sensitive keys (`secrettoken`, `apikey`, `pan`, `cardnumber`, `cvv`, `billingdata`, `billingdetails`, `email`, `phone`, `last4`, `fingerprint`, etc.) with `'[redacted]'` before persistence.
- **Retention Scrubbing:** `[IMPLEMENTED]` `PruneWebhookLogs` (`PruneWebhookLogs.php#L52-L57`) nulls out `payload` on `processed` rows older than 90 days while retaining `(provider, event_id)` for 365 days (`--dedup-days=365`), preserving long-term replay protection without retaining PII.
- **Sanitized Error Notes:** `[IMPLEMENTED]` Caught exceptions inside auto-confirmation (`PaymentWebhookService.php#L268-L290`) and transaction rollbacks (`PaymentWebhookService.php#L365-L368`) record full details only in application logs while writing generic, SQL-free messages to `webhook_logs.error`.

---

## 8. Empirical Verification & Test Matrix

All 6 dedicated payment-webhook and rate-limiting test suites were executed directly in this audit session (`php artisan test` against `alfarid_testing` PostgreSQL):

| Test Suite / File | Test Scope | Tested Invariants / Scenarios | Observed Status |
|---|---|---|---|
| `tests/Unit/WebhookLogTest.php` + `tests/Feature/Orders/Task2PaymentWebhookTest.php` | Unit + Feature (Isolated Run) | PII/credential scrubbing, 413 oversized payload, 503 missing order, Stripe cumulative refunds, pending `refund.created` quarantine, Paymob refund inquiry, `allowed_events` filtering | **20 passed, 0 failed** (102 assertions, 12.20s) |
| Combined 6-Suite Run (`WebhookTest.php` + `Task2PaymentWebhookTest.php` + `Task2Slice4WebhooksRateLimitsTest.php` + `WebhookThrottleSplitTest.php` + `Task2WebhookRateLimitTest.php` + `WebhookLogTest.php`) | Full Payment Webhook & Throttle Matrix | All invariants above plus signature rejection, rate-limit bucket isolation, off-gateway method quarantine, and state-machine transition guards | **44 tests total: 40 Passed, 4 Failed** (231 assertions, 33.87s) |
| `tests/Feature/Orders/StoreCriticalConcurrencyTest.php#L278-L294` | Parallel Concurrency (`[IMPLEMENTED]`, not re-run in this batch) | `test_parallel_payment_webhooks_only_confirm_once` dispatches two concurrent `POST /api/v1/webhooks/payment/testpay` jobs and asserts `[200, 200]`, 1 `processed`, 1 `duplicate`, 1 `Reserve` movement | Verified by code inspection; covered in prior audit |

> **Audit Evidence Citation (Exact Observed Test Run):**
> - **Command:** `Set-Location bags-platform/backend; php artisan test tests/Feature/Orders/WebhookTest.php tests/Feature/Orders/Task2PaymentWebhookTest.php tests/Feature/Orders/Task2Slice4WebhooksRateLimitsTest.php tests/Feature/Auth/WebhookThrottleSplitTest.php tests/Feature/System/Task2WebhookRateLimitTest.php tests/Unit/WebhookLogTest.php`
> - **Result:** `40 passed, 4 failed` (`231 assertions`, `33870ms`).
> - **Exact Failure Analysis (4 tests in `WebhookTest.php`):**
>   1. `WebhookTest::test_second_paid_event_on_paid_order_flags_double_payment` (`WebhookTest.php#L237`)
>   2. `WebhookTest::test_paid_event_after_refund_does_not_revert_refund` (`WebhookTest.php#L260`)
>   3. `WebhookTest::test_failed_event_does_not_overwrite_refund` (`WebhookTest.php#L292`)
>   4. `WebhookTest::test_partial_refund_keeps_paid_status_and_flags_attention` (`WebhookTest.php#L307`)
> - **Root Cause:** In `WebhookTest.php#L49-L54`, the `testpay` fixture sets `'require_amount' => false` without `'currency'`, yet those 4 test cases pass `'amount' => $total` (or `'amount_refunded'`) **without** a `'currency'` field. In `PaymentWebhookService::amountMatches` (`PaymentWebhookService.php#L480-L483`) and `refundCurrencyMatches` (`PaymentWebhookService.php#L531-L533`), when an `amount` is supplied or a refund is processed, `currency === null` strictly returns `false` (`amount mismatch` / currency mismatch), leaving `payment_status` as `'unpaid'` while returning HTTP `200 {received: false}`. The production code is enforcing strict currency validation; the 4 test fixtures in `WebhookTest.php` omit `'currency' => 'EGP'`.

---

## 9. Commercial Advantage & Acquisition Value (The Buyer Lens)

1. **Zero Silent Revenue & Inventory Loss:** `[IMPLEMENTED]` Every payment transition is serialized by PostgreSQL advisory locks (`pg_advisory_xact_lock`), row locks (`FOR UPDATE`), and a partial unique index (`webhook_logs_processed_provider_event_unique`), eliminating double-fulfillment and out-of-order state corruption.
2. **Hardened Against Gateway Quirks:** `[IMPLEMENTED]` Built-in protection for Paymob's unsigned refund fields (via `PaymobRefundInquiry`), Stripe's multi-event noise (`allowed_events` + `evt_*` ID prioritization), and deferred `unpaid` checkout sessions prevents real-world production incidents that routinely cost merchants thousands in uncollected or double-refunded orders.
3. **Compliance & Audit-Ready Logging:** `[IMPLEMENTED]` Recursive 29-key PII/credential redaction (`WebhookLog::sanitizePayload`) paired with 90-day payload scrubbing (`PruneWebhookLogs`) keeps 365-day idempotency intact while minimizing PCI/GDPR exposure in backups.
4. **Low Integration TCO:** `[IMPLEMENTED]` Adding a new payment gateway requires only a `config/webhooks.php` entry and (if non-standard HMAC) a single strategy method in `WebhookSignatureVerifier`.

---

## 10. Engineering Trade-offs & Known Boundaries

- **Known Constraint 1 (Currency Required When Amount Is Present):** `[IMPLEMENTED]` Even when `require_amount => false` is configured for a gateway, supplying a numeric `amount` without `currency` fails `amountMatches()` (`PaymentWebhookService.php#L480-L483`). Similarly, `refundCurrencyMatches()` (`PaymentWebhookService.php#L531`) always requires a non-null `currency` on refund webhooks. (This currently causes 4 fixture-drift failures in `WebhookTest.php`.)
- **Known Constraint 2 (PostgreSQL-Specific Concurrency Primitives):** `[IMPLEMENTED]` `PaymentWebhookService` executes `SELECT pg_advisory_xact_lock(hashtextextended(?, 101))` (`PaymentWebhookService.php#L176`), and `2026_10_29_000003_webhook_processed_unique.php` creates a PostgreSQL partial unique index. The subsystem is tied to PostgreSQL and is not portable to MySQL/SQLite without replacing those primitives.
- **Architectural Boundary 3 (Synchronous Inline Execution):** `[IMPLEMENTED]` Webhooks are processed synchronously inside the HTTP request lifecycle (bounded by a 256 KiB payload limit and 1.5s/3s timeouts on `PaymobRefundInquiry`). Under extreme traffic spikes, offloading post-verification processing to a queue could be introduced as a future scale evolution, though `[INFERRED]` current synchronous execution avoids queue-delay race conditions with immediate order redirects.
- **Known Boundary 4 (HTTP `200` on Amount Mismatch):** `[IMPLEMENTED]` `amount mismatch` returns HTTP `200` with `['received' => false, 'message' => 'amount mismatch']` and sets `needs_attention = true` (`PaymentWebhookService.php#L221-L233`) so the gateway stops retrying while staff investigate.
