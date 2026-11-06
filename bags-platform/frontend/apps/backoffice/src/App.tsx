import { AdminLoading } from './components/admin/AdminLoading';
import { hasPermission } from '@alfarid/utils';
import { defaultApiClient, resolveServiceUrl } from '@alfarid/api-client';
import { ProtectedRoute } from './components/auth/ProtectedRoute';
import AuthCallbackPage from './pages/auth/AuthCallbackPage';
import LoginPage from './pages/auth/LoginPage';
import SessionProbePage from './pages/auth/SessionProbePage';
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

defaultApiClient.defaults.baseURL = resolveServiceUrl(import.meta.env.VITE_API_URL ?? 'http://localhost:8000');

export function App() {
  if (window.location.pathname === '/auth/session') return <SessionProbePage />;
  if (window.location.pathname === '/auth/callback') return <AuthCallbackPage />;
  if (window.location.pathname === '/login') return <LoginPage />;
  return <ProtectedRoute><AuthenticatedApp /></ProtectedRoute>;
}

function AuthenticatedApp() {
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
  const cashierOnly = (user?.roles ?? []).includes('cashier') && ![
    'orders.view', 'treasuries.view', 'expenses.view', 'treasury_transfers.view', 'reports.view',
    'products.view', 'inventory.view', 'users.view', 'customers.view', 'roles.view', 'settings.view',
    'returns.view', 'shipping.view', 'price_lists.view', 'promotions.view', 'pos_shifts.view',
    'quotations.view', 'payment_notices.view',
  ].some(can);

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
    if (cashierOnly && !['/', '/dashboard', '/pos', ...(can('ai_agent.use') ? ['/assistant'] : [])].includes(route)) {
      window.location.replace('/dashboard');
    } else if (
      !['/', '/dashboard', '/categories', '/dealers', '/production', '/pos', '/users', '/roles', '/settings', '/assistant'].includes(route) &&
      !isStore &&
      !isOperation &&
      !/^\/products(?:\/(?:new|[0-9]+))?$/.test(route) &&
      !/^\/inventory(?:\/[a-z-]+)?$/.test(route)
    ) {
      window.location.replace('/dashboard');
    }
  }, [cashierOnly, route, isOperation, can]);

  if (cashierOnly && !['/', '/dashboard', '/pos', ...(can('ai_agent.use') ? ['/assistant'] : [])].includes(route)) return null;
  if (route === '/pos') return <PosPage />;

  const categoriesActive = route === '/categories';
  const inventoryActive = route === '/inventory' || route.startsWith('/inventory/');
  const isStoreRoute = route === '/store' || route === '/banners' || route === '/settings/store' || route === '/settings/banners';
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

  // 4.4: seed جاهز من شاشة التجار — #/assistant?seed=…&mention_entity=customer&mention_id=…&mention_label=…
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
      ...((can('users.view') || can('users.manage')) ? [{ id: 'users', label: 'المستخدمون', onOpen: () => { window.location.hash = '/users'; } }] : []),
      ...((can('roles.view') || can('roles.manage')) ? [{ id: 'roles', label: 'الأدوار', onOpen: () => { window.location.hash = '/roles'; } }] : []),
      ...((can('settings.view') || can('settings.manage')) ? [{ id: 'settings', label: 'الإعدادات', onOpen: () => { window.location.hash = '/settings'; } }] : []),
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

export default App;
