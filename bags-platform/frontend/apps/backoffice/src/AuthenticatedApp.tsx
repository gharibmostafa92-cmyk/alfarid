import { resolveServiceUrl } from '@alfarid/api-client';
import { AdminLoading } from './components/admin/AdminLoading';
import { hasPermission, canAny } from '@alfarid/utils';
import { StaffPasswordPage } from "./components/auth/StaffPasswordPage";
import { FinancialDashboard } from '@alfarid/ui';
import { lazy, Suspense, useCallback, useEffect, useRef, useState } from 'react';
import { useAuthStore } from './stores/auth.store';
import CategoriesMockPage from './pages/admin/categories-mock/CategoriesMockPage';
import PosPage from './pages/pos/PosPage';
import CatalogMockPage from './pages/admin/catalog-mock/CatalogMockPage';
import AdminMockPage from './pages/admin/admin-mock/AdminMockPage';
import AdminOverview from './pages/admin/overview/AdminOverview';
const AssistantPage = lazy(() => import('./pages/admin/assistant/AssistantPage'));
import OperationsMockPage, { canViewOperation, NAV_GROUPS } from './pages/admin/operations-mock/OperationsMockPage';
const InventoryPage = lazy(() => import('./pages/admin/inventory-mock/InventoryPage'));

export default function AuthenticatedApp() {
  const user = useAuthStore((state) => state.user);
  const logoutRemote = useAuthStore((state) => state.logoutRemote);
  const readRoute = () => window.location.hash.replace(/^#/, '')
    || window.location.pathname + window.location.search;
  const [routeWithQuery, setRouteWithQuery] = useState(readRoute);
  const route = routeWithQuery.split('?')[0];
  const inventorySelection = useRef<{ entity: string; id: number } | undefined>(undefined);
  useEffect(() => {
    if (route === '/assistant') return;
    const params = new URLSearchParams(routeWithQuery.split('?')[1] ?? '');
    const entity = route === '/inventory/transfer' ? 'transfer' : route === '/inventory/count' ? 'stocktake' : route === '/production' ? 'production_receipt' : undefined;
    const id = Number(params.get('id') ?? params.get('receipt_id'));
    inventorySelection.current = entity && Number.isSafeInteger(id) && id > 0 ? { entity, id } : undefined;
  }, [routeWithQuery, route]);
  const can = useCallback((permission: string) =>
    hasPermission(user?.permissions, permission), [user]);
  const cashierOnly = useAuthStore((state) => state.destination === '/pos');

  useEffect(() => {
    // Stocktake navigation guards may synchronously restore the accepted hash.
    const changed = () => queueMicrotask(() => setRouteWithQuery(readRoute()));
    window.addEventListener('hashchange', changed);
    window.addEventListener('popstate', changed);
    return () => {
      window.removeEventListener('hashchange', changed);
      window.removeEventListener('popstate', changed);
    };
  }, []);

  const opMatch = route.match(/^\/(orders|returns|wholesale|shipping|pricing|promos|brands|suppliers|purchasing|finance|shifts|reports|notifications)(?:\/([^/?#]+))?$/);
  const isOperation = Boolean(opMatch);
  const operationsActive = opMatch ? opMatch[1] : null;
  // AI deep links use ?tab= + ?id= (e.g. #/finance?tab=trf&id=12); the legacy ?sub= form keeps working.
  const operationsQuery = new URLSearchParams(routeWithQuery.split('?')[1] ?? '');
  const operationsId = opMatch?.[2] ?? (opMatch?.[1] === 'finance' ? operationsQuery.get('id') || undefined : undefined);
  const operationsSub = (() => {
    const raw = routeWithQuery.split('?')[1] ?? '';
    return new URLSearchParams(raw).get('sub') || new URLSearchParams(raw).get('tab') || undefined;
  })();

  useEffect(() => {
    const isStore = ['/store', '/banners', '/settings/store', '/settings/banners'].includes(route);
    if (cashierOnly && !['/pos', '/account/password', ...(can('ai_agent.use') ? ['/assistant'] : [])].includes(route)) {
      window.location.replace('/pos');
    } else if (
      !['/', '/account/password', '/dashboard', '/categories', '/dealers', '/production', '/pos', '/users', '/roles', '/settings', '/assistant'].includes(route) &&
      !isStore &&
      !isOperation &&
      !/^\/products(?:\/(?:new|[0-9]+))?$/.test(route) &&
      !/^\/inventory(?:\/[a-z-]+)?$/.test(route)
    ) {
      window.location.replace('/dashboard');
    }
  }, [cashierOnly, route, isOperation, can]);

  if (route === '/account/password') {
    return <StaffPasswordPage channel={cashierOnly ? 'pos' : 'admin'} />;
  }

  if (cashierOnly && route === '/assistant' && can('ai_agent.use')) {
    // Allow POS assistant route if explicitly permitted
  } else if (cashierOnly || route === '/pos') {
    if (!cashierOnly && !can('pos.access')) {
      return <div dir="rtl" role="alert" className="p-6">ليس لديك صلاحية عرض نقطة البيع. <a href="/dashboard">العودة للوحة التحكم</a></div>;
    }
    return <PosPage />;
  }

  const categoriesActive = route === '/categories';
  const inventoryActive = route === '/inventory' || route.startsWith('/inventory/');
  const isStoreRoute = route === '/store' || route === '/banners' || route === '/settings/store' || route === '/settings/banners';
  if (
    (route === '/users' && !canAny(can, 'users.view', 'users.manage')) ||
    (route === '/roles' && !canAny(can, 'roles.view', 'roles.manage')) ||
    (route === '/settings' && !canAny(can, 'settings.view', 'settings.manage')) ||
    (isStoreRoute && !can('store.manage')) ||
    (route === '/assistant' && !can('ai_agent.use'))
  ) return <div dir="rtl" role="alert" className="p-6">ليس لديك صلاحية عرض هذه الصفحة. <a href="/dashboard">العودة للوحة التحكم</a></div>;
  const adminActive = route === '/users' ? 'users' : route === '/roles' ? 'roles' : (route === '/settings' || isStoreRoute) ? 'settings' : null;
  const catalogActive = route === '/assistant' ? 'assistant' : isOperation
    ? operationsActive
    : inventoryActive
    ? 'inventory'
    : route.startsWith('/products')
    ? 'products'
    : route === '/dealers'
    ? 'dealers'
    : route === '/production'
    ? 'production'
    : adminActive;

  // Pre-populates assistant prompt and entity mentions when deep-linked from dealer views (#/assistant?seed=...)
  const assistantPrompt = (() => {
    if (route !== '/assistant') return undefined;
    const params = new URLSearchParams(routeWithQuery.split('?')[1] ?? '');
    const text = params.get('seed');
    if (!text) return undefined;
    const id = Number(params.get('mention_id'));
    const entity = params.get('mention_entity');
    const label = params.get('mention_label');
    return { text, mentions: (entity === 'customer' || entity === 'transfer' || entity === 'stocktake') && Number.isInteger(id) && id > 0 ? [{ entity, id, label: label || text }] : undefined };
  })();
  const assistantNav = can('ai_agent.use') ? [{ id: 'assistant', label: 'المساعد الذكي', onOpen: () => { window.location.hash = '/assistant'; } }] : [];
  return <FinancialDashboard
    onLogout={logoutRemote}
    storefrontUrl={new URL('/', resolveServiceUrl(import.meta.env.VITE_WEB_URL ?? 'http://localhost:3000')).href}
    account={user}
    overviewPage={<AdminOverview key={user?.id} can={can} name={user?.name} />}
    additionalNavigation={cashierOnly ? [] : NAV_GROUPS.map(group => ({
      label: group.label,
      items: group.items.filter(item => canViewOperation(item.m, can))
        .map(item => ({ id: item.m, label: item.label, onOpen: () => { window.location.hash = '/' + item.m; } })),
    })).filter(group => group.items.length > 0)}
    catalogActive={catalogActive}
    catalogPage={
      route === '/assistant' ? <Suspense fallback={<AdminLoading label="جارٍ تحميل المساعد" />}><AssistantPage can={can} initialPrompt={assistantPrompt} initialSelection={inventorySelection.current} /></Suspense> : isOperation && operationsActive ? (
        !canViewOperation(operationsActive, can) ? (
          <div dir="rtl" className="p-6">ليس لديك صلاحية عرض هذه الصفحة.</div>
        ) : (
        <OperationsMockPage
          key={`${operationsActive}-${operationsId || ''}-${operationsSub || ''}`}
          page={operationsActive}
          id={operationsId}
          sub={operationsSub}
          can={can}
        />
        )
      ) : inventoryActive ? (
        can('inventory.view') ? (
          <Suspense fallback={<AdminLoading label="جارٍ تحميل المخزون" />}>
            <InventoryPage can={can}/>
          </Suspense>
        ) : (
          <div dir="rtl" className="p-6">ليس لديك صلاحية عرض المخزون.</div>
        )
      ) : adminActive ? (
        <AdminMockPage
          key={adminActive}
          page={adminActive}
          can={can}
          initialTab={isStoreRoute ? 'store' : undefined}
          queryString={routeWithQuery.split('?')[1] ?? ''}
        />
      ) : (
        catalogActive && <CatalogMockPage key={catalogActive} can={can} page={catalogActive} />
      )
    }
    catalogNav={!cashierOnly ? [
      ...(canViewOperation('orders', can) ? [{ id: 'orders', label: 'الطلبات', onOpen: () => { window.location.hash = '/orders'; } }] : []),
      ...(can('inventory.view') ? [{ id: 'inventory', label: 'المخزون', onOpen: () => { window.location.hash = '/inventory/stock'; } }] : []),
      ...(can('products.view') ? [{ id: 'products', label: 'المنتجات', onOpen: () => { window.location.hash = '/products'; } }] : []),
      ...(can('customers.view') ? [{ id: 'dealers', label: 'التجار والموزعون', onOpen: () => { window.location.hash = '/dealers'; } }] : []),
      ...(can('inventory.view') ? [{ id: 'production', label: 'استلام الإنتاج', onOpen: () => { window.location.hash = '/production'; } }] : []),
      ...(canViewOperation('finance', can) ? [{ id: 'finance', label: 'المالية', onOpen: () => { window.location.hash = '/finance'; } }] : []),
      ...(can('reports.view') ? [{ id: 'reports', label: 'التقارير', onOpen: () => { window.location.hash = '/reports'; } }] : []),
      ...(canAny(can, 'users.view', 'users.manage') ? [{ id: 'users', label: 'المستخدمون', onOpen: () => { window.location.hash = '/users'; } }] : []),
      ...(canAny(can, 'roles.view', 'roles.manage') ? [{ id: 'roles', label: 'الأدوار', onOpen: () => { window.location.hash = '/roles'; } }] : []),
      ...(canAny(can, 'settings.view', 'settings.manage', 'store.manage') ? [{ id: 'settings', label: canAny(can, 'settings.view', 'settings.manage') ? 'الإعدادات' : 'إدارة المتجر', onOpen: () => { window.location.hash = canAny(can, 'settings.view', 'settings.manage') ? '/settings' : '/store'; } }] : []),
      ...assistantNav,
    ] : assistantNav}
    onCloseCatalog={() => { window.location.hash = '/dashboard'; }}
    onOpenNotifications={() => { window.location.hash = '/notifications'; }}
    categoriesActive={categoriesActive}
    categoriesPage={<CategoriesMockPage can={can} embedded />}
    onOpenCategories={!cashierOnly && can('categories.view')
      ? () => { window.location.hash = '/categories'; }
      : undefined}
    onCloseCategories={() => { window.location.hash = '/dashboard'; }}
  />;
}

