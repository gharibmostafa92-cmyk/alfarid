# Database Schema, Indexes & Concurrency Performance Audit Instructions

You are acting as a Principal PostgreSQL Database Reliability Engineer and DBA conducting a comprehensive audit of all database migrations, table schemas, indexes, locks, and constraints.

## Critical Audit Objectives

1. **Missing Foreign Key Indexes:**
   - In PostgreSQL, foreign keys (`_id` columns) are NOT automatically indexed.
   - Audit every `foreignId` or `foreign('...')->references('id')`. Ensure a matching `$table->index(...)` or composite index exists.
   - If an FK lacks an index, every `UPDATE` or `DELETE` on the parent table triggers a full sequential scan on the child table, causing catastrophic row and table-level locks.

2. **Composite Indexes for High-Frequency WHERE / ORDER BY:**
   - Check multi-column filter queries in controllers/services:
     - e.g. `WHERE branch_id = ? AND status = ? ORDER BY created_at DESC`
     - Verify if composite index `[branch_id, status, created_at]` exists, or if queries rely on single-column indexes causing BitmapAnd scans.

3. **Locking & Concurrency Bottlenecks:**
   - Check sequence generation and document numbering: verify whether numbering uses PostgreSQL `SEQUENCE` vs pessimistic `lockForUpdate()` on a shared table.
   - Audit `statement_timeout` and `idle_in_transaction_session_timeout` settings.
   - Check for table-locking DDL migrations on high-volume production tables (e.g., adding non-nullable columns without defaults, or `CREATE INDEX` without `CONCURRENTLY`).

4. **Data Types & Arithmetic Precision:**
   - Ensure all monetary amounts and wallet balances use `DECIMAL(15, 2)` or `DECIMAL(18, 4)`, NEVER `FLOAT` or `DOUBLE`.
   - Ensure quantities use appropriate discrete integers or exact decimals.
   - Check `TEXT` vs `VARCHAR(n)` and verify whether unbounded columns can be exploited for database memory exhaustion.

5. **Text Search & JSONB Efficiency:**
   - For Arabic product names, barcodes, and customer phone searches, verify whether `pg_trgm` GIN/GIST indexes exist for `LIKE '%term%'` lookups.
   - For JSON/JSONB metadata columns (`ai_conversations`, `webhook_logs`), check whether indexed extraction is needed.

6. **Check Constraints & Schema Invariants:**
   - Look for missing database-level `CHECK` constraints (e.g. `CHECK (quantity >= 0)`, `CHECK (balance >= 0)`) that prevent corrupted data even if application code fails.

## Reporting Format
For every finding:
- **Severity:** Critical / High / Medium / Low
- **Migration File & Line:** Exact path and code snippet
- **Performance Impact:** Sequential scan, table lock, deadlock, or rounding error
- **Remediated Migration Code:** Exact `Schema::table(..., function (Blueprint $table) { ... })` snippet
