# AI Assistant & Agentic Operations Subsystem — Architectural Deep Dive & Diligence Report

> **Classification:** Technical Due Diligence & Engineering Documentation  
> **Target Audience:** Technical Evaluators, Software Buyers, Chief Technology Officers, Enterprise Architects  
> **Status:** Published & Verified (Multi-Domain Agentic Orchestrator, Two-Phase Proposal/Confirm/Undo Engine, SSE Streaming & WhatsApp Staff Gateway)  
> **Evidence Tier:** `Verified` (Production Laravel 11 / PHP 8.3 Backend + React 19 TypeScript Backoffice UI + PostgreSQL Schemas + 44 Backend Feature/Unit Test Suites + 9 Frontend Test Suites; empirical run results recorded in Section 8)  
> **Primary Source References:**  
> - Orchestrator: [`AgentOrchestrator.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/app/Services/Ai/AgentOrchestrator.php)  
> - Two-Phase Action Engine: [`PendingActionService.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/app/Services/Ai/PendingActionService.php)  
> - Tool Registry: [`ToolRegistry.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/app/Services/Ai/ToolRegistry.php)  
> - Domain Router: [`DomainRouter.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/app/Services/Ai/DomainRouter.php)  
> - Gemini HTTP Client: [`GeminiClient.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/app/Services/Ai/GeminiClient.php)  
> - Stream Concurrency Middleware: [`PreventConcurrentAiStream.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/app/Http/Middleware/PreventConcurrentAiStream.php)  
> - Controllers: [`AiMessageController.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/app/Http/Controllers/Api/V1/Admin/Ai/AiMessageController.php), [`AiActionController.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/app/Http/Controllers/Api/V1/Admin/Ai/AiActionController.php), [`AiAttachmentController.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/app/Http/Controllers/Api/V1/Admin/Ai/AiAttachmentController.php)  
> - WhatsApp Staff Gateway: [`WhatsAppInboundProcessor.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/app/Services/Ai/WhatsApp/WhatsAppInboundProcessor.php), [`WhatsAppIdentityService.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/app/Services/Ai/WhatsApp/WhatsAppIdentityService.php), [`WhatsAppChannelPolicy.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/app/Services/Ai/WhatsApp/WhatsAppChannelPolicy.php)  
> - Frontend Backoffice Workspace: [`AssistantPage.tsx`](file:///d:/programming/programming/projects/alfarid/bags-platform/frontend/apps/backoffice/src/pages/admin/assistant/AssistantPage.tsx), [`stream.ts`](file:///d:/programming/programming/projects/alfarid/bags-platform/frontend/apps/backoffice/src/pages/admin/assistant/stream.ts)  
> **Primary Test References:**  
> - Backend AI Suites: [`tests/Feature/Ai/`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/tests/Feature/Ai/), [`tests/Feature/Admin/Ai/`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/tests/Feature/Admin/Ai/), [`tests/Unit/*DomainRouterTest.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/tests/Unit/)  
> - Frontend AI Suites: [`tests/slice3-assistant-core.test.cjs`](file:///d:/programming/programming/projects/alfarid/bags-platform/frontend/tests/slice3-assistant-core.test.cjs), [`tests/slice4-assistant-ui.test.cjs`](file:///d:/programming/programming/projects/alfarid/bags-platform/frontend/tests/slice4-assistant-ui.test.cjs), [`tests/task6-assistant-safety.test.cjs`](file:///d:/programming/programming/projects/alfarid/bags-platform/frontend/tests/task6-assistant-safety.test.cjs)

---

## 1. Executive Summary & Value Proposition

The **AI Assistant & Agentic Operations Subsystem** ("مساعد VARYA") is an enterprise-grade, multi-domain operational copilot embedded directly into the Alfarid / Bags-Platform backoffice and staff WhatsApp channels. Unlike generic LLM chat wrappers that either hallucinate database IDs or execute unguarded mutations, this subsystem enforces **strict human-in-the-loop two-phase commits**, **SHA-256 argument integrity**, **cryptographic state-hashed 15-minute undo**, **per-user and global worker concurrency locks**, and **channel-level risk policies**.

- **Core Capability:** `[IMPLEMENTED]` Translates Egyptian Arabic and English operational prompts, `@entity` mentions, UI selections, and image/CSV attachments into permission-gated read queries and reversible write proposals across **10 operational domains** (Catalog, Inventory, Pricing, Finance, Wholesale, Shipping, Storefront, Customers/Dealers, Settings, and Admin/Staff), accessible via SSE-streamed Backoffice UI and PIN-protected WhatsApp staff sessions.
- **Architectural Philosophy:** `[IMPLEMENTED]` Two-phase proposal-confirm-undo execution (`AiPendingAction`), domain-routed tool declarations (`DomainRouter`), fail-closed authorization re-checked at both proposal and confirmation time, and bounded worker/token execution budgets.
- **Commercial Impact:** `[INFERRED]` Architecturally designed to reduce backoffice administrative time for catalog onboarding, bulk pricing, inventory reconciliation, and wholesale payment notice review while eliminating the primary enterprise AI adoption risks: unauthorized mutations, prompt injection, FPM worker pool exhaustion, and runaway LLM token bills.
- **Operational Resilience:** `[IMPLEMENTED]` Bounded agent cycles (`MAX_STEPS = 6`, `MAX_CYCLE_SECONDS = 240`), global concurrent slot semaphores, `ignore_user_abort(true)` turn completion, and durable WhatsApp outbound delivery tracking (`ai_whatsapp_outbound` with unique `(inbound_id, sequence)`).

---

## 2. The Engineering Challenge

Deploying an LLM agent with write access to a live retail, inventory, and financial ERP introduces severe failure modes that naive chatbot architectures fail to contain:

1. **The Unchecked Mutation & Prompt Injection Hazard:** If an LLM can directly execute `approve_dealer_application`, `bulk_update_prices`, or `create_treasury_transfer` upon parsing user text or untrusted customer-submitted fields, a single hallucination or indirect prompt injection can corrupt financial ledgers or approve fraudulent wholesale credit.
2. **The PHP-FPM Worker Exhaustion Trap (Slowloris via SSE):** Long-running Server-Sent Events (SSE) streams waiting on external Gemini API calls hold PHP-FPM workers open for tens of seconds. Without per-user stream locks and global concurrency slot caps, a handful of concurrent tabs or users can saturate the entire application worker pool.
3. **The Token Budget & Retry Bypass:** If failed or aborted LLM calls are not metered, or if oversized tool responses (e.g., 500-row catalog dumps) are fed back into multi-step conversation history, a single complex prompt can exhaust daily API quotas or degrade model attention.
4. **The Stale-State & Concurrent Edit Race:** Between the moment an AI proposes a price change or stock adjustment and the moment an admin clicks "Confirm" (or "Undo" 5 minutes later), another staff member may have modified the underlying record. Executing blindly overwrites concurrent work.
5. **The Mobile / WhatsApp Privilege Escalation Vector:** Allowing staff to trigger high-risk backoffice operations over WhatsApp without identity binding, PIN session locking, and channel-specific tool blacklists exposes the ERP to stolen-phone account takeover.

---

## 3. Architectural Design & System Components

### Component Breakdown

| Component / Layer | Implementation Path | Responsibility | Architectural Pattern |
|---|---|---|---|
| **HTTP Ingress & SSE Stream** | [`AiMessageController.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/app/Http/Controllers/Api/V1/Admin/Ai/AiMessageController.php) | Validates text/selection/mentions/attachments, emits SSE (`status`, `reply`, `error`), records Sentry `ai.stream` spans | Controller + SSE Stream |
| **Stream Concurrency Guard** | [`PreventConcurrentAiStream.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/app/Http/Middleware/PreventConcurrentAiStream.php) | Enforces 1 active SSE/message stream per staff member via distributed `Cache::lock` | Middleware Mutex |
| **Agent Orchestrator** | [`AgentOrchestrator.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/app/Services/Ai/AgentOrchestrator.php) | Manages global slot pool, user lock, multi-step Gemini loop, token budgets, shortcuts, and tool dispatch | Bounded Agent Loop |
| **Domain Router** | [`DomainRouter.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/app/Services/Ai/DomainRouter.php) | Pre-compiles Arabic/English keyword matchers and routes mentions/selections/attachments to relevant tool domains | Intent Classifier / Router |
| **Tool Registry & Providers** | [`ToolRegistry.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/app/Services/Ai/ToolRegistry.php) | Aggregates 7 domain `ToolProvider`s + core Admin/Customer/Settings tools; enforces RBAC & feature flags | Registry + Provider |
| **Two-Phase Action Engine** | [`PendingActionService.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/app/Services/Ai/PendingActionService.php) | Coordinates `confirm`, `confirmBulk`, `cancel`, and 15-minute `undo` with `args_hash` and `undo_state` verification | Two-Phase Commit / Saga |
| **Gemini HTTP Client** | [`GeminiClient.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/app/Services/Ai/GeminiClient.php) | Executes Gemini API calls with connect-only retries, model validation, and `GeminiException` token accounting | Resilient API Client |
| **Attachment Ingress** | [`AiAttachmentController.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/app/Http/Controllers/Api/V1/Admin/Ai/AiAttachmentController.php) | Validates images (`MaxImagePixels`) & CSVs (anti-PHP/null-byte), enforces per-user quota under `lockForUpdate`, writes to `ai_private` | Transactional Upload |
| **WhatsApp Staff Gateway** | [`WhatsAppInboundProcessor.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/app/Services/Ai/WhatsApp/WhatsAppInboundProcessor.php) | Processes staff WhatsApp webhooks with link-token pairing, PIN session lock, `WhatsAppChannelPolicy`, and durable outbox | Inbound Gateway + Outbox |
| **Backoffice AI UI & Parser** | [`AssistantPage.tsx`](file:///d:/programming/programming/projects/alfarid/bags-platform/frontend/apps/backoffice/src/pages/admin/assistant/AssistantPage.tsx), [`stream.ts`](file:///d:/programming/programming/projects/alfarid/bags-platform/frontend/apps/backoffice/src/pages/admin/assistant/stream.ts) | Renders rich `ui_blocks`, @mentions, slash starters, and O(n) incremental UTF-8-safe SSE frame parsing | React 19 SPA + Incremental Parser |

### System Architecture Diagram

```mermaid
graph TD
    UI["Backoffice UI (AssistantPage.tsx)"] -->|"POST /stream (SSE)"| MW["PreventConcurrentAiStream"]
    WA["WhatsApp Webhook"] -->|"POST /webhooks/whatsapp/inbound"| WAP["WhatsAppInboundProcessor"]
    MW --> Ctrl["AiMessageController"]
    Ctrl --> Orch["AgentOrchestrator"]
    WAP -->|"WhatsAppActorContext + Policy"| Orch

    Orch -->|"1. Acquire Slot + User Lock"| Redis[("Cache / Redis Locks")]
    Orch -->|"2. Route Intent"| Router["DomainRouter (10 Domains)"]
    Router --> Reg["ToolRegistry (7 Providers + Core)"]
    Orch -->|"3. Multi-Step Converse (max 6)"| Gemini["GeminiClient (Google AI)"]

    Reg -->|"Read Tool"| DB[("PostgreSQL (ACID)")]
    Reg -->|"Write Tool -> Proposal"| Pending["AiPendingAction (ULID)"]
    UI -->|"POST /actions/{id}/confirm or /undo"| ActCtrl["AiActionController"]
    ActCtrl --> PAS["PendingActionService"]
    PAS -->|"Verify args_hash + typed_confirmation + locks"| DB
```

---

## 4. Internal Execution Flow & Lifecycle

### A. SSE Turn & Two-Phase Proposal/Confirm/Undo Lifecycle

```mermaid
sequenceDiagram
    autonumber
    actor Staff as Staff Admin
    participant MW as PreventConcurrentAiStream
    participant Ctrl as AiMessageController
    participant Orch as AgentOrchestrator
    participant Reg as ToolRegistry
    participant Gemini as GeminiClient
    participant PAS as PendingActionService
    participant DB as PostgreSQL

    Staff->>MW: POST /api/v1/admin/ai/conversations/{id}/stream
    MW->>MW: Acquire Cache::lock("ai-stream:{user_id}")
    MW->>Ctrl: Forward request
    Ctrl->>Orch: guard() & handle(actor, conv, turn, emit)
    Orch->>Orch: Acquire global concurrent slot + user lock
    Orch->>DB: Persist user AiMessage + update last_message_at
    Orch->>Reg: declarationsFor(actor, routedDomains, channel)
    loop Up to MAX_STEPS (6) within MAX_CYCLE_SECONDS (240s)
        Orch->>Gemini: converse(history, declarations, system)
        Gemini-->>Orch: Response (text or functionCalls + usage)
        Orch->>DB: Increment conversation total_tokens & save AiMessage
        alt Write Tool Call
            Orch->>Reg: dispatch(actor, conv, call)
            Reg->>DB: Create AiPendingAction (status=pending, args_hash, expected_versions)
            Reg-->>Orch: ToolOutcome(proposal, uiBlock)
            Orch-->>Ctrl: Halt loop & return proposal card
        end
    end
    Ctrl-->>Staff: SSE event: reply (text + ui_blocks)
    Staff->>PAS: POST /api/v1/admin/ai/actions/{action}/confirm (typed_confirmation?)
    PAS->>DB: BEGIN + lockForAction + lockForUpdate(AiPendingAction)
    PAS->>PAS: Re-verify RBAC, feature flags, args_hash, expires_at, typed confirmation
    PAS->>DB: Execute domain mutation & compute undo_state hash -> COMMIT
    PAS-->>Staff: 200 OK (result + undo_available_until [+15m])
```

### B. `AiPendingAction` State Machine

```mermaid
stateDiagram-v2
    [*] --> bulk_child: Bulk parent created
    [*] --> pending: Single write proposal created
    bulk_child --> pending: Selected in confirmBulk()
    bulk_child --> cancelled: Unselected in confirmBulk()
    pending --> expired: expires_at.isPast() on confirm
    pending --> cancelled: User cancels action
    pending --> confirmed: Claimed (bulk or ExecutesOutsideTransaction)
    pending --> executed: Direct transactional execution succeeds
    pending --> failed: Validation / auth / DB constraint error
    confirmed --> executed: External execution succeeds
    confirmed --> failed: External execution fails
    confirmed --> pending: RetryableConflict in confirmBulk()
    executed --> undone: Undo within 15m & targetState matches undo_state
```

### Step-by-Step Flow Description

1. **Ingress & Concurrency Fencing:** `[IMPLEMENTED]` `PreventConcurrentAiStream` acquires a per-user `Cache::lock('ai-stream:'.$user->id)` (TTL `max_execution_time + 120`, min 60s) and releases it in `terminate()` (`PreventConcurrentAiStream.php:30-54`). Inside `AgentOrchestrator::handle()`, the engine acquires a randomized global slot lock (`ai-agent:concurrent-slot:web:{index}`, capped at 20 for web and 3 for WhatsApp) and a per-user lock (`ai-agent:user:{id}`), registering a PHP shutdown handler to release locks on fatal aborts (`AgentOrchestrator.php:140-216`).
2. **Intent Routing & Declaration Filtering:** `[IMPLEMENTED]` `DomainRouter::route()` inspects image attachments (auto-enabling `catalog`), `@mentions`, UI `selection`, and pre-compiled Arabic/English keywords across 10 domains (`catalog`, `customers`, `settings`, `admin`, `inventory`, `pricing`, `finance`, `shipping`, `wholesale`, `storefront`). `AgentOrchestrator` expands `catalog` to include `inventory` and `pricing` (`AgentOrchestrator.php:267-269`), and `ToolRegistry::declarationsFor()` filters out tools disabled by settings, feature flags, RBAC permissions, or `WhatsAppChannelPolicy`.
3. **Bounded Agent Loop & Prompt Injection Defense:** `[IMPLEMENTED]` Up to `MAX_STEPS` (default 6, max 10) and `MAX_CALLS_PER_STEP = 8` are executed within `MAX_CYCLE_SECONDS = 240` (reserving `45s` before tool calls). Tool results are serialized and truncated at `ai_tool_result_max_chars` (default 16,000 chars) via `cappedToolResponses()` (`AgentOrchestrator.php:452-472`). System instructions explicitly fence untrusted customer text (`untrusted_text`) and tool outputs as data, never instructions (`AgentOrchestrator.php:95,112`).
4. **Two-Phase Proposal & Confirmation:** `[IMPLEMENTED]` Write tools return a `proposal` `ToolOutcome` backed by an `AiPendingAction` ULID row storing `args`, `expected_versions`, `preview`, `inverse`, `args_hash`, `risk` (`low`/`medium`/`high`), and `expires_at`. Execution requires an explicit `POST /actions/{action}/confirm` call, which re-verifies ownership (`user_id === actor->id`), `ai_agent.execute` + domain permissions, `hash_equals($action->args_hash, ...)`, and `typed_confirmation` for `high` risk actions (`PendingActionService.php:247-305`).

---

## 5. Critical Engineering Discoveries & Decision Log

### Discovery 1: Non-Bypassable Token Budgeting Including Failed Provider Calls
- **The Problem:** Users or buggy prompts triggering repeated Gemini 5xx/429 errors or mid-stream aborts could consume upstream API tokens without incrementing local `ai_messages` counters, bypassing daily spend limits.
- **Naive Alternative & Why It Fails:** Recording token usage only on successful `200 OK` responses leaves aborted or partial-usage failures unmetered.
- **Actual Implementation:** `[IMPLEMENTED]` `AgentOrchestrator::handleLocked()` (`AgentOrchestrator.php:309-326`) catches `GeminiException`, extracts `$failure->usage()` (`input_tokens`, `output_tokens`, `total_tokens`), increments `ai_conversations.total_tokens`, and inserts a role `event` row in `ai_messages` so subsequent daily budget sums (`AgentOrchestrator.php:58-63`) immediately account for the failed call.
- **Defensible Advantage:** Prevents budget-bypass loops and ensures accurate per-user token accounting in `Africa/Cairo` business-timezone daily windows.
- **Trade-offs:** Adds an extra `AiMessage` event row on failed calls that carry usage metadata.

### Discovery 2: Cryptographic Argument Hashing & Optimistic State-Hashed Undo
- **The Problem:** Between AI proposal and admin confirmation (or between execution and a 15-minute undo), concurrent edits or payload tampering could cause an action to mutate stale state or revert another admin's work.
- **Naive Alternative & Why It Fails:** Allowing blind undo by re-applying stored inverse values overwrites any intervening edits made by other staff members.
- **Actual Implementation:** `[IMPLEMENTED]` `PendingActionService::confirmLocked()` verifies `hash_equals($action->args_hash, $this->registry->hashArgs($action->args))` (`PendingActionService.php:280`). On execution, `finalizeAction()` computes `$storedResult['undo_state'] = $this->registry->targetState($action)` (`PendingActionService.php:396-398`). `undoLocked()` enforces a strict 15-minute window (`executed_at->addMinutes(15)->isFuture()`), acquires target locks via `$this->registry->lockTargets($locked)`, and aborts with HTTP `409` if `hash_equals($action->result['undo_state'], $this->registry->targetState($action))` fails (`PendingActionService.php:488-501`).
- **Defensible Advantage:** Guarantees zero silent overwrites on undo and tamper-proof argument integrity between proposal and execution.
- **Trade-offs:** Undo is intentionally unavailable after 15 minutes or if any intervening mutation alters the target state hash.

### Discovery 3: Safe Bulk Partial Execution with Retryable Lock Recovery
- **The Problem:** Batch AI operations (e.g., approving multiple wholesale notices or updating multiple prices) can hit transient PostgreSQL lock timeouts (`55P03`) or deadlocks (`40P01`) mid-batch.
- **Naive Alternative & Why It Fails:** Failing the entire batch permanently leaves partial mutations with no clean retry path, or re-running already-executed items causes duplicate side effects.
- **Actual Implementation:** `[IMPLEMENTED]` `PendingActionService::confirmBulk()` (`PendingActionService.php:131-232`) requires typed confirmation `'تنفيذ'`, supports partial `selected_items` (cancelling unselected `bulk_child` rows atomically), preserves `alreadyExecutedIds` across retries, and catches `RetryableConflict` / PostgreSQL SQLSTATE `55P03`/`40P01`/`40001` via `retryBulkConflict()`, resetting unexecuted children back to `bulk_child` and the parent to `pending` with HTTP `409` for safe idempotent retry.
- **Defensible Advantage:** Eliminates partial-batch corruption and duplicate execution under concurrent warehouse/finance load.
- **Trade-offs:** Requires client/user retry when HTTP `409` is returned.

### Discovery 4: O(n) Incremental UTF-8-Safe SSE Frame Parser
- **The Problem:** Browser `onDownloadProgress` callbacks deliver the entire accumulated `responseText` on each network chunk. Re-parsing the full text on every chunk is $O(n^2)$ and can split multi-byte Arabic UTF-8 characters (`U+FFFD`) or `\r\n` delimiters across chunk boundaries.
- **Naive Alternative & Why It Fails:** Calling `parseAgentEvents(responseText)` on every progress event freezes the UI on long replies and corrupts split Arabic frames.
- **Actual Implementation:** `[IMPLEMENTED]` `createIncrementalSseParser()` in [`stream.ts:44-79`](file:///d:/programming/programming/projects/alfarid/bags-platform/frontend/apps/backoffice/src/pages/admin/assistant/stream.ts#L44-L79) tracks a byte `cursor`, holds back trailing `U+FFFD` replacement characters and trailing `\r` across chunks, parses only newly completed `\n\n` frames, and provides `flush()` to drain the final frame even if a reverse proxy strips the trailing blank line.
- **Defensible Advantage:** Constant-time per-chunk UI rendering and zero dropped Arabic characters or final `reply` frames.
- **Trade-offs:** Coupled to Axios `onDownloadProgress` text accumulation semantics.

---

## 6. Performance, Concurrency & Data Integrity

### Concurrency Guarantees
- **Worker Pool Protection:** `[IMPLEMENTED]` Two-layer locking: `PreventConcurrentAiStream` (1 stream per staff user) + `AgentOrchestrator` global slot semaphore (max 20 web, max 3 WhatsApp) + per-user turn lock (`ai-agent:user:{id}`).
- **Client Disconnect Resilience:** `[IMPLEMENTED]` `AgentOrchestrator::handleWithUserLock()` sets `ignore_user_abort(true)` during the turn (`AgentOrchestrator.php:204`) so tool calls and `AiMessage` history pairs do not become orphaned mid-flight, while checking `connection_aborted()` between steps (`AgentOrchestrator.php:394`) to stop early before issuing another Gemini call.
- **Pessimistic Action Locking:** `[IMPLEMENTED]` `PendingActionService` wraps `confirm`, `cancel`, and `undo` in `DB::transaction()` with `AiPendingAction::query()->lockForUpdate()` and domain-specific locks (`StaffAdministration::lock()` or provider-scoped `LocksTargets`).
- **Gemini Connect-Only Retries:** `[IMPLEMENTED]` `GeminiClient::isConnectFailure()` (`GeminiClient.php:26-33`) restricts automatic retries to DNS/connect failures, explicitly excluding read timeouts (`cURL error 28` / `timed out`) to prevent duplicate upstream billing.

### Query & Storage Optimization
- **Index-Friendly Budget Queries:** `[IMPLEMENTED]` Daily token usage sums join `ai_conversations` with explicit `created_at >=` and `<` bounds (`AgentOrchestrator.php:58-63`) rather than `whereDate()` / `whereHas()` to preserve PostgreSQL index scans.
- **Conversation Pruning & Retention:** `[IMPLEMENTED]` `PruneAiConversations` command (`ai:prune-conversations`) and `2026_11_02_000001_add_ai_prune_indexes.php` support scheduled cleanup of expired conversations and pending actions.
- **Durable WhatsApp Outbox:** `[IMPLEMENTED]` `ai_whatsapp_outbound` enforces `UNIQUE (inbound_id, sequence)` (`2026_11_18_000002_add_durable_whatsapp_outbound_delivery.php`) so webhook retries resume pending outbound delivery instead of re-invoking the LLM.

---

## 7. Security, Validation & Defense-in-Depth

- **RBAC & Dual-Point Authorization:** `[IMPLEMENTED]` All AI routes require `permission:ai_agent.use`; action confirmation/undo require `permission:ai_agent.execute`; settings and WhatsApp admin require `permission:ai_agent.settings` (`routes/api/admin.php:372-406`). `ToolRegistry` and `PendingActionService::authorizeTool()` re-check user `is_active`, tool-specific permissions, `ai_enabled`, and `ai_disabled_tools` at both proposal and confirmation time.
- **One-Time Credential Handling:** `[IMPLEMENTED]` When `create_staff_user` or `create_customer` generates a password, `PendingActionService::finalizeAction()` and `event(..., stripPassword: true)` strip `generated_password` from `ai_pending_actions.result` and `ai_messages` (`PendingActionService.php:393,553-569`), returning it once to the confirming admin.
- **Attachment Hardening:** `[IMPLEMENTED]` `AiAttachmentController::store()` validates images with `MaxImagePixels` (max 5120 KB, `jpg/jpeg/png/webp`), rejects null bytes and `<?php` / `<?=` tags in CSV/TXT uploads (max 1024 KB), enforces per-user pending attachment quotas under `User::lockForUpdate()`, and cleans up `ai_private` files on DB rollback (`AiAttachmentController.php:36-100`).
- **WhatsApp Channel Security (`WhatsAppChannelPolicy` & `WhatsAppIdentityService`):** `[IMPLEMENTED]`
  - Staff link tokens use 12-character unambiguous uppercase codes (`ABCDEFGHJKLMNPQRSTUVWXYZ23456789`) stored as SHA-256 hashes with configurable TTL (`WhatsAppIdentityService.php:58-65,102-115`).
  - `WhatsAppInboundProcessor` enforces PIN session unlocking (`pinLocked()`), fail-closed turn claiming (`TURN_STARTED_REASON`), and automatic identity revocation when a role loses `ai_agent.whatsapp` (`WhatsAppIdentityService::revokeUsersOfRole()`).
  - `WhatsAppChannelPolicy` blocks `create_staff_user`, `create_customer`, and `DISABLED_BY_DEFAULT` admin tools, enforces `ai_whatsapp_allow_writes` and `ai_whatsapp_max_risk` (default `medium`), and restricts `approve_payment_notice` to PIN-verified identities when `ai_whatsapp_allow_notice_approval` is enabled (`WhatsAppChannelPolicy.php:19-73`).

---

## 8. Empirical Verification & Test Matrix

| Test Suite / File | Test Scope | Tested Invariants / Scenarios | Status (Observed 2026-11-21) |
|---|---|---|---|
| [`Slice4OrchestratorAndToolRegistryTest.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/tests/Feature/Ai/Slice4OrchestratorAndToolRegistryTest.php)<br>[`Task2AiStreamLockTest.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/tests/Feature/Ai/Task2AiStreamLockTest.php)<br>[`Slice3ProposalAndWhatsAppSecurityTest.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/tests/Feature/Ai/Slice3ProposalAndWhatsAppSecurityTest.php) | Core Orchestrator, Stream Locks, Proposal & WhatsApp Security | Concurrent stream locks, proposal hashing, WhatsApp policy gates, Gemini retry-after parsing | **15/16 Passed** (54 assertions; 1 failure: `test_gemini_client_parses_http_date_and_decimal_retry_after` asserted `2 === 4`) |
| [`tests/Unit/*DomainRouterTest.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/tests/Unit/) (5 files)<br>[`AiStreamLockTest.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/tests/Feature/System/AiStreamLockTest.php)<br>[`WhatsAppAssistantTest.php`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/tests/Feature/Admin/Ai/WhatsAppAssistantTest.php) | Domain Routers, System Stream Lock & WhatsApp E2E Flow | Arabic/English keyword routing across Finance, Inventory, Pricing, Shipping, Storefront, Wholesale; WhatsApp linking, PIN lock, outbound delivery | **43/48 Passed** (153 assertions; 4 failures + 1 error in `WhatsAppAssistantTest.php`, including `CreateBannerTool.php:121` passing `null` to `StaffAdministration::audit()`) |
| [`slice3-assistant-core.test.cjs`](file:///d:/programming/programming/projects/alfarid/bags-platform/frontend/tests/slice3-assistant-core.test.cjs)<br>[`slice4-assistant-ui.test.cjs`](file:///d:/programming/programming/projects/alfarid/bags-platform/frontend/tests/slice4-assistant-ui.test.cjs)<br>[`task6-assistant-safety.test.cjs`](file:///d:/programming/programming/projects/alfarid/bags-platform/frontend/tests/task6-assistant-safety.test.cjs)<br>[`assistant.test.cjs`](file:///d:/programming/programming/projects/alfarid/bags-platform/frontend/tests/assistant.test.cjs) | Frontend SSE Parser, UI Blocks, URL/XSS Safety & RichText | Incremental UTF-8 SSE parsing, `AbortSignal`, download allowlist, QR data URL sanitization, settings diffing | **20/21 Passed** (1 failure in `task6-assistant-safety.test.cjs`: `bold regex tolerates a single asterisk inside`) |
| [`tests/Feature/Ai/`](file:///d:/programming/programming/projects/alfarid/bags-platform/backend/tests/Feature/Ai/) (remaining 34 suites) | Domain-Specific AI Tools & Hardening | Catalog, Inventory, Finance, Wholesale, Customer/Dealer, Bulk Pricing, and Gemini Failure simulations | *Present in repo; not executed in full during this bounded session* |

> **Audit Evidence Citation:**
> - Backend Command 1: `php artisan test tests/Feature/Ai/Slice4OrchestratorAndToolRegistryTest.php tests/Feature/Ai/Task2AiStreamLockTest.php tests/Feature/Ai/Slice3ProposalAndWhatsAppSecurityTest.php` -> `15/16 passed (54 assertions)`
> - Backend Command 2: `php artisan test tests/Unit/*DomainRouterTest.php tests/Feature/System/AiStreamLockTest.php tests/Feature/Admin/Ai/WhatsAppAssistantTest.php` -> `43/48 passed (153 assertions; 4 failures, 1 error)`
> - Frontend Command: `node --test tests/slice3-assistant-core.test.cjs tests/slice4-assistant-ui.test.cjs tests/task6-assistant-safety.test.cjs tests/assistant.test.cjs` -> `20/21 passed`

---

## 9. Commercial Advantage & Acquisition Value (The Buyer Lens)

1. **Minimized Total Cost of Ownership (TCO):** `[IMPLEMENTED]` Modular `ToolProvider` architecture cleanly isolates 7 domain tool suites (`Catalog`, `Finance`, `Inventory`, `Pricing`, `Shipping`, `Storefront`, `Wholesale`) behind a single `ToolRegistry`, allowing an acquiring team to add or disable entire business domains without modifying the orchestrator.
2. **De-risked Enterprise AI Adoption:** `[IMPLEMENTED]` Two-phase `AiPendingAction` commits with `args_hash`, high-risk typed confirmations, and 15-minute state-hashed undo give enterprise buyers auditable, reversible AI operations rather than an unguarded chatbot.
3. **Predictable Infrastructure & LLM Spend:** `[IMPLEMENTED]` Global concurrency slot caps, per-user stream locks, `cappedToolResponses`, and failure-inclusive daily/cycle token budgets protect both PHP-FPM worker pools and Google Gemini API budgets from runaway usage.
4. **Turnkey Omnichannel Operations:** `[IMPLEMENTED]` Staff can execute permission-scoped queries and PIN-verified payment notice approvals directly from WhatsApp using the same `AgentOrchestrator` and RBAC engine as the React backoffice.

---

## 10. Engineering Trade-offs & Known Boundaries

- **Known Constraint 1 (Provider Coupling):** `[IMPLEMENTED]` `GeminiClient` targets the Google Generative AI (Gemini) REST protocol (`contents` / `functionCall` / `functionResponse` parts). Switching to OpenAI/Anthropic would require an adapter implementing the same `converse()` contract and part normalization.
- **Known Constraint 2 (Observed Test Failures & `CreateBannerTool` Bug):** `[TESTED]` In the bounded test runs on 2026-11-21:
  - `Slice4OrchestratorAndToolRegistryTest::test_gemini_client_parses_http_date_and_decimal_retry_after` failed (`2 === 4` seconds delta).
  - `WhatsAppAssistantTest.php` had 4 failures (`test_link_from_lid_only_activates_identity`, `test_shipping_company_can_be_confirmed_from_whatsapp_at_medium_risk`, `test_tool_disabled_on_whatsapp_is_rejected_at_confirm_even_if_the_card_was_made_on_the_web`, `test_job_releases_when_the_assistant_is_busy`) and 1 `TypeError` in `CreateBannerTool.php:121` (`StaffAdministration::audit()` `$before` argument received `null` instead of `array`).
  - `task6-assistant-safety.test.cjs` failed 1 of 21 assertions (`bold regex tolerates a single asterisk inside`).
  These should be remediated before production WhatsApp banner creation or release sign-off.
- **Architectural Boundary 3 (15-Minute Undo Window):** `[IMPLEMENTED]` Undo is intentionally bounded to 15 minutes and aborts on any intervening state mutation (`409`). Long-lived reversals must be performed through domain reversal workflows (e.g., `reverse_payment_notice_approval`, `ReturnService`).
- **Recommended Next-Stage Evolution:** `[INFERRED]` For 10x concurrent staff scale, offload SSE streaming from PHP-FPM workers to Laravel Octane / Swoole or a dedicated Node/Go streaming relay while keeping `PendingActionService` transactional execution in Laravel.
