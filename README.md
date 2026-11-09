# Alfarid Enterprise Commerce Platform

[![Architecture: Monorepo](https://img.shields.io/badge/Architecture-Monorepo-blue.svg)](#system-architecture)
[![Backend: Laravel 13](https://img.shields.io/badge/Backend-Laravel%2013%20%7C%20PHP%208.3-red.svg)](bags-platform/backend)
[![Frontend: Turborepo + pnpm](https://img.shields.io/badge/Frontend-Turborepo%20%7C%20pnpm-black.svg)](bags-platform/frontend)
[![Database: PostgreSQL 16](https://img.shields.io/badge/Database-PostgreSQL%2016-336791.svg)](bags-platform/infrastructure)
[![Code Style: PSR--12 / ESLint](https://img.shields.io/badge/Code%20Style-PSR--12%20%7C%20Prettier-success.svg)](#code-hygiene--standards)

---

## Executive Overview

**Alfarid** is a commerce platform for retail, wholesale bag distribution, and physical branch operations.

The platform bridges real-time physical store terminals (**POS**), centralized business administration (**Backoffice**), luxury consumer e-commerce (**Storefront**), and an autonomous **WhatsApp Wholesale Trader AI Agent** backed by a high-concurrency **Laravel 13 REST API**.

The architecture enforces strict double-entry inventory ledger accounting, pessimistic concurrency control, multi-guard role-based access control (RBAC), and automated financial treasury settlement.

---

## System Architecture

```
                                  ┌──────────────────────────────────────────────┐
                                  │           Clients & Channels                 │
                                  └──────┬──────────────┬──────────────┬─────────┘
                                         │              │              │
                    ┌────────────────────┼──────────────┼──────────────┼────────────────────┐
                    ▼                    ▼              ▼              ▼                    ▼
          ┌──────────────────┐  ┌─────────────────┐  ┌───────┐  ┌─────────────┐  ┌────────────────────┐
          │  @alfarid/pos    │  │ @alfarid/       │  │ Next  │  │ WhatsApp    │  │ Mobile Responsive  │
          │  Live API / Mock   │  │ backoffice      │  │ Store │  │ Business    │  │ Operations Shell   │
          │  Cashier Desk    │  │ Admin Operations│  │ front │  │ Gateway     │  │ (Android / iOS)    │
          └─────────┬────────┘  └────────┬────────┘  └───┬───┘  └──────┬──────┘  └─────────┬──────────┘
                    │                    │               │             │                   │
                    └────────────────────┼───────────────┼─────────────┴───────────────────┘
                                         ▼               ▼
                              ┌─────────────────────────────────────┐
                              │     HAProxy SSL / Load Balancer     │
                              └──────────────────┬──────────────────┘
                                                 ▼
                              ┌─────────────────────────────────────┐
                              │       Laravel 13 REST API Core      │
                              │    (Sanctum Multi-Guard & Spatie)   │
                              ├─────────────────────────────────────┤
                              │ • Inventory Ledger (Double-Entry)   │
                              │ • Wholesale Engine & FIFO Debt Settle│
                              │ • Point of Sale Shift Reconciliation │
                              │ • Procurement, Returns & Treasury   │
                              │ • Egyptian Dialect Trader AI Agent  │
                              └──────────┬────────────────┬─────────┘
                                         │                │
                        ┌────────────────┴────┐     ┌─────┴───────────────┐
                        ▼                     ▼     ▼                     ▼
             ┌─────────────────────┐   ┌───────────────┐   ┌─────────────────────┐
             │ PgBouncer Pooler    │   │ Redis 7 Cache │   │ Cloudflare R2 / S3  │
             │ + PostgreSQL 16 DB  │   │ & Queue Sync  │   │ Secure Media Disks  │
             └─────────────────────┘   └───────────────┘   └─────────────────────┘
```

---

## Technology Stack

| Layer | Technology | Key Capabilities & Highlights |
| :--- | :--- | :--- |
| **Backend API** | PHP 8.3 / Laravel 13 | Sanctum multi-guard auth, Spatie RBAC, pessimistic row locking, idempotency guards, FormRequests, API resources |
| **Database** | PostgreSQL 16 + PgBouncer | Composite index optimization, trigram search (`pg_trgm`), transactional safety, statement timeouts |
| **Caching & Queues** | Redis 7 + Laravel Queues | Distributed locks, cache invalidation tags, asynchronous receipt OCR/voice processing |
| **Frontend Monorepo** | Turborepo + pnpm Workspace | Shared workspace package pipeline, isolated build caches, unified TypeScript configuration |
| **Backoffice App** | React 19 / Vite / TypeScript | High-density admin console, dynamic data tables, inventory adjustments, multi-branch treasury controls |
| **POS Terminal** | React 19 / Vite / TypeScript | Dual-mode data switch (Live API / Local Mock), HID barcode scanner listener, split tenders, sound UX |
| **Storefront App** | Next.js 15 / React / Tailwind | Server-side rendering, responsive catalog showcase, dynamic cart, guest & customer checkout |
| **Shared Design System** | `@alfarid/ui` + `@alfarid/utils`| Accessible UI tokens, atomic primitives, localized number/currency mappers, central API client |
| **Wholesale Automation** | WhatsApp Baileys + Gemini AI | Natural Language trader ordering, voice transcription, bank receipt OCR, FIFO payment notices |
| **Infrastructure** | Docker Compose / HAProxy | Microservices orchestration, SSL termination, zero-downtime rolling reload configurations |

---

## Repository Structure

```tree
alfarid/
├── .github/
│   └── workflows/
│       └── ai-agent-evaluation.yml     # Automated evaluation suite for Trader Bot
├── bags-platform/
│   ├── backend/                        # Laravel 13 REST API Application
│   │   ├── app/
│   │   │   ├── Enums/                  # Strongly typed domain enums (Order, Return, Shift, Stock)
│   │   │   ├── Http/Controllers/       # Scoped API controllers (Admin, POS, Store, Wholesale, AI)
│   │   │   ├── Models/                 # Eloquent entities with strict casts & scopes
│   │   │   └── Services/               # Domain engines (InventoryService, PosService, ReturnService)
│   │   ├── database/
│   │   │   ├── migrations/             # Timestamped relational database schema & index definitions
│   │   │   └── seeders/                # Production & demo seeders (Roles, Permissions, Settings)
│   │   └── tests/
│   │       ├── Feature/                # Concurrency, POS lifecycle, and wholesale feature suites
│   │       └── Unit/                   # Core math, amount distribution, and invariant tests
│   ├── frontend/                       # Turborepo Monorepo Root
│   │   ├── apps/
│   │   │   ├── backoffice/             # Admin operations dashboard (@alfarid/backoffice)
│   │   │   ├── pos/                    # Standalone POS terminal desk (@alfarid/pos)
│   │   │   └── storefront/             # Next.js luxury bag storefront (@alfarid/storefront)
│   │   └── packages/
│   │       ├── api-client/             # Typed API contracts & Axios transport (@alfarid/api-client)
│   │       ├── config/                 # Shared Tailwind, PostCSS, and TSConfig presets
│   │       ├── ui/                     # Design system primitives, modals, buttons (@alfarid/ui)
│   │       └── utils/                  # Currency, date, and domain formatters (@alfarid/utils)
│   ├── infrastructure/                 # Containerized orchestration & production deployments
│   │   ├── docker/                     # Optimized Alpine Dockerfiles for PHP-FPM, Node, Nginx
│   │   ├── haproxy/                    # High-availability reverse proxy configs
│   │   ├── k6/                         # High-concurrency performance load-testing scripts
│   │   └── monitoring/                 # Prometheus metrics scraping & Grafana dashboards
│   └── wa-gateway/                     # Node.js Baileys WhatsApp automation service
├── .gitignore                          # Ignore rules for local artifacts
└── README.md                           # Platform engineering documentation
```

---

## Core Domain Capabilities

### 1. Robust Double-Entry Inventory Ledger
- All inventory mutations (`Sale`, `Return`, `Transfer`, `Adjustment`, `Stocktake`) are immutable rows in `stock_movements`.
- Concurrency control utilizes `lockForUpdate()` and database transaction boundaries to eliminate race hazards and negative stock anomalies under heavy load.

### 2. POS Terminal with Shift Reconciliation
- Cashier shift lifecycle (`Open -> Cash Transactions -> Split Tender Payments -> Mid-Shift Drop -> Blind Count Reconciliation -> Close`).
- Live hardware keyboard listener for USB/HID barcode scanners.
- Atomic sale quotes with automatic promotions, coupons, and customer group tier discounts.
- Shift treasury scoping guaranteeing that cashier refunds only deduct from their assigned drawer balance.

### 3. Wholesale B2B Engine & Trader AI
- Tiered price lists (`Retail`, `Wholesale`, `Distributor`, `Customer-Specific`) with branch scoping.
- Quotation workflow auto-converting accepted trader quotes to confirmed orders.
- Autonomous Egyptian Dialect WhatsApp assistant understanding messy Arabic audio, images, and text orders with strict safety guardrails.
- Bank transfer notice OCR and FIFO debt settlement against overdue invoices.

---

## Getting Started

### Prerequisites
- **PHP** >= 8.3 (with `pdo_pgsql`, `redis`, `gd`, `intl`, `bcmath`)
- **Composer** >= 2.7
- **Node.js** >= 20.x
- **pnpm** 10.34.4
- **PostgreSQL** >= 16.x

---

### Backend Setup (Laravel API)

```bash
# 1. Navigate to backend directory
cd bags-platform/backend

# 2. Install PHP dependencies
composer install

# 3. Environment configuration
cp .env.example .env
php artisan key:generate

# 4. Run database migrations & seed reference data
php artisan migrate --seed

# 5. Run test suite to verify system integrity
php artisan test
```

---

### Frontend Monorepo Setup (Turborepo)

```bash
# 1. Navigate to frontend root
cd bags-platform/frontend

# 2. Install monorepo dependencies via pnpm
pnpm install

# 3. Development servers
# Run all applications concurrently
pnpm dev

# Or run specific applications:
pnpm --filter @alfarid/backoffice dev    # Admin Backoffice (http://localhost:5173)
pnpm --filter @alfarid/pos dev           # POS Terminal (http://localhost:3002)
pnpm --filter @alfarid/storefront dev    # Luxury Storefront (http://localhost:3000)

# 4. Production build
pnpm build
```

---

## Quality Assurance & Verification

Run the relevant checks from the repository root:

```bash
# Backend unit & feature testing
cd bags-platform/backend
php artisan test --filter=PosEndpointsTest
php artisan test --filter=ReturnTaskTxtCoverageTest

# Frontend typechecking & production builds
cd ../frontend
pnpm --filter @alfarid/backoffice build
pnpm --filter @alfarid/pos build
pnpm --filter @alfarid/storefront build
```

---

## Git Hygiene & Commit Standards

New commits can use this format:

```
<type>(<scope>): <imperative summary>

[optional bulleted architectural body]
```

- **Scopes**: `workspace`, `backend`, `wholesale-bot`, `backoffice`, `pos`, `storefront`, `ui`, `infra`
- **Types**: `feat`, `fix`, `test`, `chore`, `docs`, `refactor`, `perf`

---

## License

Proprietary & Confidential. All rights reserved © AKGROUP Platform Engineering.
