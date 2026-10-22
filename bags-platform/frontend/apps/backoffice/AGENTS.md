# Backoffice interface

- Vortix (`packages/ui/src/financial-dashboard/FinancialDashboard.jsx`) is the primary admin interface and navigation shell.
- The previous ALFARID admin shell and its mock pages have been removed. Do not recreate a second admin shell or route fallback.
- Add admin screens inside Vortix. The categories screen preserves the isolated RTL design from `task.txt`, uses the authenticated catalog API, and renders within Vortix through `App.tsx`.
- Keep the Vortix navigation shell RTL: sidebar on the right, header brand on the right, account and tools on the left. Individual content views retain their own text direction.
- Preserve the unified login, session verification, role restrictions, logout and POS access when changing navigation.
