# Admin API integration verification — 2026-10-24

The existing users, roles, and settings screens now use the shared authenticated API client. The reference CSS and `new_design/` were left untouched. Mock persistence and seed data were removed; the `admin-mock` directory name was retained to preserve existing imports.

## Passing checks

- Backoffice TypeScript check and production build.
- Repository frontend lint (`pnpm run lint`, invoked through `rtk proxy` because the command wrapper initially substituted a missing ESLint executable).
- Laravel Pint on changed backend files.
- 27 backend tests / 141 assertions: `AdminUserEndpointsTest`, `RolePermissionEndpointsTest`, and `AdminApiContractsTest`.
- 9 backend tests / 50 assertions: `AdminSettingsEndpointsTest`.
- `node tests/admin-mappers.test.cjs`: settings grouping, exactly 12 numbering types, minimal patches, boolean conversion, role mapping, reserved system role, unique permission grouping, and Arabic labels for all fixture permissions.
- `node tests/admin-endpoints.test.cjs`: empty branch query, numeric active status, trimmed search, names for role filters, pagination across all role/branch pages, and AbortSignal propagation.
- `node tests/admin-screens.e2e.cjs`: real screen navigation, server fixtures, minimal settings PUT, persistence after reload, padding validation, duplicate email validation, user creation/update/deactivation/reactivation, debounce/filter requests, role creation/rename/delete, delete conflict, self-account protection, Super Admin protection, branch permission fallback, read-only roles/settings, and access denial.

## Fixture provenance

All six JSON fixtures in `apps/backoffice/src/pages/admin/admin-mock/api/__fixtures__/` were exported from actual authenticated Laravel endpoint responses by `AdminApiContractsTest`, using `LocalAdminSeeder` and `SettingSeeder` inside the isolated `alfarid_testing` database. They contain generated test accounts and no tokens or passwords. Export explicitly with `EXPORT_ADMIN_FIXTURES=1` when running that test; ordinary runs do not modify fixtures.

## Full backend suite limitation

`php artisan test` executed 829 tests; 736 passed. The complete suite failed, including failures in `UnifiedLoginHardeningTest` (supplier login returned 401), inventory/concurrency tests, and repeated migration attempts encountering the existing PostgreSQL function `mark_inventory_reconciliation_work`. These modules were not changed as part of this task. The migration's `CREATE FUNCTION` is not idempotent across the suite's database resets; its scope is outside the authorized backend changes.

The stale function was cleared only in `alfarid_testing` to obtain independent, passing results for the 36 relevant backend tests. No `migrate:fresh` was run against the developer's application database. A live end-to-end manual run against a freshly seeded local backend remains separate from the automated fixture-based browser verification.
