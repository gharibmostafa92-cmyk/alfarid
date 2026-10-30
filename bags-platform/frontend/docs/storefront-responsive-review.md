# Storefront responsive UI review

The retail store keeps its existing Oswald/Poppins typography, white surfaces, dark footer and muted teal accents. Changes target usability from 320px upward rather than a replacement design.

## Implementation

- Removed the header backdrop filter that made fixed mobile navigation and drawers position against the header instead of the viewport. Removed conflicting shared header/search rules from the product stylesheet.
- Kept the mobile navigation at the bottom, reserved content space for it, handled safe areas, and kept fixed payment controls below drawers. Form focus temporarily hides bottom controls to leave room for the software keyboard.
- Added focus containment, Escape dismissal, focus restoration and resize cleanup to the mobile menu. Drawer content scrolls independently and cart controls have larger touch targets.
- Fixed the catalog's viewport-dependent initial render so server and client agree. Closed filters use `hidden`, removing their controls from keyboard navigation as well as the layout.
- Made product/filter controls semantic links and buttons, exposed actions on touch devices and keyboard focus, improved wrapping, sorting controls and phone product grids.
- Applied the store header to retail account, receipt and tracking routes. Added account section navigation, readable account layouts, loading announcements and retry actions after account requests fail.
- Improved footer contrast, form typography, product options, quantity controls, anchor offsets and reduced-motion handling. Desktop page structure and business/API contracts remain intact.
- Synchronized the lockfile with the already-declared `@alfarid/ui` → `@alfarid/api-client` workspace dependency and restored its missing local link. No package or version was added to the manifests.

## Verification

From `bags-platform/frontend`:

```powershell
pnpm --filter @alfarid/storefront typecheck
pnpm --filter @alfarid/storefront lint
node --test tests/storefront-checkout.test.cjs tests/storefront-catalog.test.cjs tests/store-cart.test.cjs tests/storefront-banners.test.cjs tests/auth-tabs.test.cjs
$env:PLAYWRIGHT_CHANNEL = 'msedge'
node --test tests/storefront-responsive.e2e.cjs
```

The browser tests require a running storefront at `http://localhost:3001` and the existing Playwright test installation. `STOREFRONT_URL` overrides the address; omit `PLAYWRIGHT_CHANNEL` when using Playwright's installed Chromium. `RESPONSIVE_SCREENSHOTS` optionally saves full-page captures.

The responsive matrix checks 17 retail routes at 320, 768, 1024 and 1440 pixels: home, shop, categories, product, design, showcase, checkout, receipt, tracking, four account pages and four authentication pages. It checks document width and JavaScript errors with populated browser-only fixtures, including long product names. No fixture data is saved to the backend. Separate tests cover navigation placement, drawer coverage, filter hydration, focus restoration, resize cleanup, narrow search and account error recovery.

Visual confirmation also includes 390px phones. Browser emulation does not establish physical iPhone/Android keyboard behavior, synthesized swipe support, low-end device performance or formal WCAG conformance. Real payment/order submission and authenticated wholesale/supplier workflows are outside this UI verification.

ESLint retains the existing manual stylesheet warning in the root layout. The design detector also reports existing animation/easing and decorative-border warnings; these are separate from the layout regressions fixed here.

Results: TypeScript passed; standalone storefront ESLint passed with that one existing warning; 34 focused regression tests passed; the 68 route/width combinations and five interaction tests passed. A separate confirmation captured the key shopping screens at 320, 390, 768, 1024 and 1440px.

A production build was compiled in an isolated copy on the same drive to avoid disturbing the running development server. Compilation and generation of all 24 pages completed. Next's embedded ESLint step reported a plugin-resolution failure in that isolated copy (`eslint-plugin-react-hooks`); standalone ESLint in the actual project passed. The isolated build is therefore not a clean production-release gate.
